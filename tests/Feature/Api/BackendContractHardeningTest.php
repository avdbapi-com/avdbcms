<?php

namespace Tests\Feature\Api;

use App\Jobs\UploadToUpload18;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackendContractHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The direct upload's config asks Upload18 for an upload token (1.2.3): a test never reaches the real Upload18.
        Http::preventStrayRequests();
    }

    public function test_device_update_and_delete_are_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $token = $owner->createToken('owner-device')->accessToken;

        // Another member's device answers exactly like a device that does not exist (round 3 R3-081, HiddenRouteModels).
        $this->actingAs($other)
            ->postJson(route('devices.update', $token->id), ['name' => 'hacked-device'])
            ->assertNotFound();

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
        // Every member who may upload sends the file straight to Upload18 (owner decision; since 2026-09-28 /complete is
        // no longer admin-only, which is where the old 403 came from). What this test guards is unchanged: /complete
        // registers an Upload18 video only after THIS account checked its status. Without that check the answer is the
        // retryable 409 "status_required" (the upload panel checks the status and finishes again) and nothing is created.
        $user = User::factory()->create();
        $payload = [
            'did' => 'unclaimed-did',
            'vid' => 'unclaimed-vid',
            'filename' => 'unclaimed.mp4',
            'size' => 1024,
            'duration_seconds' => 60,
        ];

        $this->actingAs($user)
            ->postJson(route('videos.upload18.complete'), $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'status_required');

        // Another member's status check of the same Upload18 video does not count for this member.
        config(['services.upload18.api_key' => 'server-only-key']);
        Http::fake([
            '*' => Http::response([
                'data' => [['id' => 'unclaimed-did', 'vid' => 'unclaimed-vid', 'zt' => '2', 'duration' => '1:00']],
                'total_page' => 1,
            ]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('videos.upload18.status', ['did' => 'unclaimed-did', 'vid' => 'unclaimed-vid']))
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('videos.upload18.complete'), $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'status_required');

        $this->assertDatabaseCount('videos', 0);
        $this->assertDatabaseMissing('upload18_claims', ['did' => 'unclaimed-did']);
    }

    public function test_resumable_chunks_with_the_same_identifier_are_isolated_by_owner(): void
    {
        // Member chunks reach this server only while the admin keeps the server fallback switched on: without it every
        // member uploads straight to Upload18 and /chunk answers 410 (owner decision, 2026-09-28; pinned below).
        config(['services.upload18.allow_server_fallback' => true]);
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

    public function test_member_chunks_are_refused_while_the_server_fallback_is_off(): void
    {
        config(['services.upload18.allow_server_fallback' => false]);
        Queue::fake();
        Storage::fake('local');

        // Members upload straight to Upload18: with the fallback off, no member's file is stored on this server.
        $this->actingAs(User::factory()->create())
            ->postJson(route('videos.upload18.chunk'), [
                'resumableFilename' => 'direct-only.mp4',
                'resumableTotalSize' => 1024,
                'resumableIdentifier' => 'direct-only-upload',
                'resumableChunkNumber' => 1,
                'resumableTotalChunks' => 1,
                'file' => $this->fakeUpload('direct-only.mp4', 1, 'video/mp4'),
            ])
            ->assertStatus(410);

        $this->assertSame([], Storage::disk('local')->allFiles(Video::CHUNK_FOLDER));
        $this->assertDatabaseCount('videos', 0);
        Queue::assertNothingPushed();
    }

    public function test_upload18_status_claim_cannot_be_taken_by_another_user(): void
    {
        // The status check needs the site's Upload18 key: set it here instead of relying on a key in the machine's .env.
        config(['services.upload18.api_key' => 'server-only-key']);

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

        // R1B-027 (owner-approved 2026-10-27): a status check alone claims nothing and gives no vid of a did nobody
        // holds; the member whose file it is holds it from the announce with its size (/pending), as the upload panel
        // sends it. After that the did is the owner's for good, and another account is refused.
        $this->actingAs($owner)
            ->getJson(route('videos.upload18.status', ['did' => 'claimed-did']))
            ->assertOk()
            ->assertJsonPath('vid', '');
        $this->actingAs($owner)
            ->postJson(route('videos.upload18.pending'), ['did' => 'claimed-did', 'vid' => 'claimed-vid', 'filename' => 'claimed.mp4', 'size' => 1024])
            ->assertCreated();
        $this->actingAs($owner)
            ->getJson(route('videos.upload18.status', ['did' => 'claimed-did']))
            ->assertOk()
            ->assertJsonPath('vid', 'claimed-vid');

        $this->actingAs($other)
            ->getJson(route('videos.upload18.status', ['did' => 'claimed-did']))
            ->assertForbidden();
    }

    public function test_regular_upload_config_uses_direct_flow_without_exposing_provider_key(): void
    {
        config([
            'services.upload18.api_key' => 'server-only-key',
            'services.upload18.allow_server_fallback' => false,
        ]);

        Http::fake([
            '*/uploadtoken' => Http::response(['status' => 'success', 'upload_token' => 'u18t_member.token']),
            '*/myvideo*' => Http::response(['data' => [], 'total_page' => 1]),
        ]);

        $user = User::factory()->create();

        // Members upload straight to Upload18 (owner decision, 2026-09-28: a member's file never goes through this
        // server). 1.2.3: their browser gets a short-lived upload token in "apikey" when Upload18 mints one (the only
        // fallback, a restricted Upload + Read key, is covered by Upload18BrowserTokenTest).
        $response = $this->actingAs($user)
            ->getJson(route('videos.upload18.config'))
            ->assertOk()
            ->assertJsonPath('upload_mode', 'direct')
            ->assertJsonPath('allow_server_fallback', false)
            ->assertJsonPath('direct_only', true)
            ->assertJsonPath('server_chunk_url', null)
            ->assertJsonPath('apikey', 'u18t_member.token');

        $this->assertStringNotContainsString('server-only-key', $response->getContent());

        // The member's status check is answered by this server, which sends the key to Upload18 only in the
        // Authorization header and never back to the browser.
        $status = $this->actingAs($user)
            ->getJson(route('videos.upload18.status', ['did' => 'provider-id']))
            ->assertOk()
            ->assertJsonPath('found', false);

        $this->assertStringNotContainsString('server-only-key', $status->getContent());
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/myvideo')
            && $request->hasHeader('Authorization', 'Bearer server-only-key')
            && ! str_contains($request->url(), 'server-only-key'));
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'server-only-key')
            || str_contains((string) $request->body(), 'server-only-key'));
    }

    public function test_regular_upload_never_gets_the_provider_key_with_environment_override(): void
    {
        config([
            'services.upload18.api_key' => 'server-only-key',
            'services.upload18.allow_server_fallback' => false,
        ]);

        Http::fake([
            '*/uploadtoken' => Http::response(['status' => 'success', 'upload_token' => 'u18t_member.token']),
        ]);

        // No environment switch puts the site's key into a member's browser.
        putenv('UPLOAD18_DIRECT_ADMIN_ONLY=false');

        try {
            $response = $this->actingAs(User::factory()->create())
                ->getJson(route('videos.upload18.config'))
                ->assertOk()
                ->assertJsonPath('upload_mode', 'direct')
                ->assertJsonPath('apikey', 'u18t_member.token');

            $this->assertStringNotContainsString('server-only-key', $response->getContent());

            // Nor does the one setting that changes a member's config: the admin's server fallback.
            config(['services.upload18.allow_server_fallback' => true]);
            $response = $this->actingAs(User::factory()->create())
                ->getJson(route('videos.upload18.config'))
                ->assertOk()
                ->assertJsonPath('upload_mode', 'direct')
                ->assertJsonPath('direct_only', false)
                ->assertJsonPath('server_chunk_url', route('videos.upload18.chunk', [], false))
                ->assertJsonPath('apikey', 'u18t_member.token');

            $this->assertStringNotContainsString('server-only-key', $response->getContent());
        } finally {
            putenv('UPLOAD18_DIRECT_ADMIN_ONLY');
        }
    }

    public function test_admin_upload_config_keeps_legacy_direct_flow(): void
    {
        config([
            'services.upload18.api_key' => 'server-only-key',
            'services.upload18.allow_server_fallback' => false,
        ]);

        Http::fake([
            '*/uploadtoken' => Http::response(['status' => 'success', 'upload_token' => 'u18t_browser.token']),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        // 1.2.3: the browser gets a short-lived upload token, never the server's key.
        $response = $this->actingAs($admin)
            ->getJson(route('videos.upload18.config'))
            ->assertOk()
            ->assertJsonPath('upload_mode', 'direct')
            ->assertJsonPath('direct_only', true)
            ->assertJsonPath('apikey', 'u18t_browser.token');

        $this->assertStringNotContainsString('server-only-key', $response->getContent());
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
