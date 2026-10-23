<?php

namespace Tests\Feature\Api;

use App\Jobs\UploadToUpload18;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackendContractHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_update_and_delete_are_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $token = $owner->createToken('owner-device')->accessToken;

        $this->actingAs($other)
            ->postJson(route('devices.update', $token->id), ['name' => 'hacked-device'])
            ->assertForbidden();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->id,
            'name' => 'owner-device',
        ]);

        $this->actingAs($other)
            ->deleteJson(route('devices.delete', $token->id))
            ->assertNotFound();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
    }

    public function test_resumable_upload_rejects_out_of_range_final_chunk(): void
    {
        config(['services.upload18.allow_server_fallback' => true]);
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('videos.upload18.chunk'), [
                'resumableFilename' => 'range-check.mp4',
                'resumableTotalSize' => 1024,
                'resumableIdentifier' => 'range-check-upload',
                'resumableChunkNumber' => 3,
                'resumableTotalChunks' => 2,
                'file' => $this->fakeUpload('range-check.mp4', 1, 'video/mp4'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resumableChunkNumber']);

        $this->assertDatabaseCount('videos', 0);
    }

    public function test_resumable_upload_rejects_declared_size_mismatch(): void
    {
        config(['services.upload18.allow_server_fallback' => true]);
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('videos.upload18.chunk'), [
                'resumableFilename' => 'size-check.mp4',
                'resumableTotalSize' => 1024,
                'resumableIdentifier' => 'size-check-upload',
                'resumableChunkNumber' => 1,
                'resumableTotalChunks' => 1,
                'file' => $this->fakeUpload('size-check.mp4', 2, 'video/mp4'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The uploaded chunk sizes do not match the declared file size.');

        $this->assertDatabaseCount('videos', 0);
        Queue::assertNothingPushed();
    }

    public function test_resumable_upload_rejects_unbounded_chunk_count(): void
    {
        config(['services.upload18.allow_server_fallback' => true]);

        $this->actingAs(User::factory()->create())
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('videos.upload18.chunk'), [
                'resumableFilename' => 'chunk-count.mp4',
                'resumableTotalSize' => 1024,
                'resumableIdentifier' => 'chunk-count-upload',
                'resumableChunkNumber' => 1,
                'resumableTotalChunks' => 10001,
                'file' => $this->fakeUpload('chunk-count.mp4', 1, 'video/mp4'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resumableTotalChunks']);
    }

    public function test_resumable_finalization_is_idempotent_and_dispatches_once(): void
    {
        config(['services.upload18.allow_server_fallback' => true]);
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $payload = [
            'resumableFilename' => 'idempotent.mp4',
            'resumableTotalSize' => 1024,
            'resumableIdentifier' => 'idempotent-upload',
            'resumableChunkNumber' => 1,
            'resumableTotalChunks' => 1,
            'file' => $this->fakeUpload('idempotent.mp4', 1, 'video/mp4'),
        ];

        $first = $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('videos.upload18.chunk'), $payload)
            ->assertOk();

        $second = $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('videos.upload18.chunk'), $payload)
            ->assertOk()
            ->assertJsonPath('idempotent', true);

        $this->assertSame($first->json('video_id'), $second->json('video_id'));
        $this->assertDatabaseCount('videos', 1);
        Queue::assertPushed(UploadToUpload18::class, 1);
    }

    public function test_direct_upload_completion_requires_this_users_status_probe(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('videos.upload18.complete'), [
                'did' => 'unclaimed-did',
                'vid' => 'unclaimed-vid',
                'filename' => 'unclaimed.mp4',
                'size' => 1024,
                'duration_seconds' => 60,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('videos', 0);
    }

    public function test_resumable_chunks_with_the_same_identifier_are_isolated_by_owner(): void
    {
        config(['services.upload18.allow_server_fallback' => false]);
        Queue::fake();
        Storage::fake('local');

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $payload = [
            'resumableFilename' => 'shared.mp4',
            'resumableTotalSize' => 2048,
            'resumableIdentifier' => 'same-client-identifier',
            'resumableTotalChunks' => 2,
            'file' => $this->fakeUpload('shared.mp4', 1, 'video/mp4'),
        ];

        $this->actingAs($owner)
            ->postJson(route('videos.upload18.chunk'), $payload + ['resumableChunkNumber' => 1])
            ->assertOk();

        $this->actingAs($other)
            ->postJson(route('videos.upload18.chunk'), $payload + ['resumableChunkNumber' => 2])
            ->assertStatus(409)
            ->assertJsonPath('missing_chunks', [1]);

        $this->assertDatabaseCount('videos', 0);
        Queue::assertNothingPushed();
    }

    public function test_upload18_status_claim_cannot_be_taken_by_another_user(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [[
                    'id' => 'claimed-did',
                    'vid' => 'claimed-vid',
                    'zt' => '2',
                    'duration' => '1:00',
                ]],
                'total_page' => 1,
            ]),
        ]);

        $owner = User::factory()->create(['is_admin' => true]);
        $other = User::factory()->create(['is_admin' => true]);

        $this->actingAs($owner)
            ->getJson(route('videos.upload18.status', ['did' => 'claimed-did']))
            ->assertOk();

        $this->actingAs($other)
            ->getJson(route('videos.upload18.status', ['did' => 'claimed-did']))
            ->assertForbidden();
    }

    public function test_regular_upload_config_uses_server_flow_without_exposing_provider_key(): void
    {
        config([
            'services.upload18.api_key' => 'server-only-key',
            'services.upload18.allow_server_fallback' => false,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson(route('videos.upload18.config'))
            ->assertOk()
            ->assertJsonPath('upload_mode', 'server')
            ->assertJsonPath('allow_server_fallback', true)
            ->assertJsonPath('direct_only', false)
            ->assertJsonMissingPath('apikey');

        $this->assertSame(route('videos.upload18.chunk', [], false), $response->json('server_chunk_url'));

        $this->actingAs($user)
            ->getJson(route('videos.upload18.status', ['did' => 'provider-id']))
            ->assertForbidden();
    }

    public function test_regular_upload_cannot_enable_direct_flow_with_environment_override(): void
    {
        config([
            'services.upload18.api_key' => 'server-only-key',
            'services.upload18.allow_server_fallback' => false,
        ]);

        putenv('UPLOAD18_DIRECT_ADMIN_ONLY=false');

        $this->actingAs(User::factory()->create())
            ->getJson(route('videos.upload18.config'))
            ->assertOk()
            ->assertJsonPath('upload_mode', 'server')
            ->assertJsonMissingPath('apikey');

        putenv('UPLOAD18_DIRECT_ADMIN_ONLY=');
    }

    public function test_admin_upload_config_keeps_legacy_direct_flow(): void
    {
        config([
            'services.upload18.api_key' => 'server-only-key',
            'services.upload18.allow_server_fallback' => false,
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->getJson(route('videos.upload18.config'))
            ->assertOk()
            ->assertJsonPath('upload_mode', 'direct')
            ->assertJsonPath('direct_only', true)
            ->assertJsonPath('apikey', 'server-only-key');
    }

    public function test_public_search_no_results_has_stable_has_more_field(): void
    {
        $this->getJson(route('search', ['q' => 'no-such-video-result']))
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('categories', [])
            ->assertJsonPath('items', []);
    }
}
