<?php

namespace Tests\Feature;

use App\Data\UploadSettings;
use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApiInvalidPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_upload_completion_rejects_invalid_video_payload(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('videos.upload18.complete'), [
                'filename' => 'clip.mp4',
                'size' => 'not-a-size',
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['did', 'vid', 'size']);
    }

    public function test_upload_chunk_rejects_invalid_identifier_using_fake_storage(): void
    {
        config(['services.upload18.allow_server_fallback' => true]);

        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('videos.upload18.chunk'), [
                'resumableFilename' => 'clip.mp4',
                'resumableTotalSize' => 1024,
                'resumableIdentifier' => '../bad-path',
                'resumableChunkNumber' => 1,
                'resumableTotalChunks' => 1,
                'file' => $this->fakeUpload('clip.mp4', 1, 'video/mp4'),
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['resumableIdentifier']);
    }

    public function test_generated_thumbnail_rejects_payload_over_configured_decoded_size(): void
    {
        Storage::fake('local');
        config(['site.max_thumbnail_size' => UploadSettings::MIN_MAX_THUMBNAIL_SIZE_KB]);

        $user = User::factory()->create();
        $video = Video::factory()->for($user)->create();
        $payload = 'data:image/jpeg;base64,' . base64_encode(str_repeat('x', (UploadSettings::MIN_MAX_THUMBNAIL_SIZE_KB * 1024) + 1));

        $this->actingAs($user)
            ->postJson(route('videos.generate-thumbnails', $video), [
                'thumbnails' => [$payload],
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['thumbnails.0']);
    }

    public function test_comment_store_rejects_missing_content(): void
    {
        $user = User::factory()->create();
        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create(['allow_comments' => true]);

        $this->actingAs($user)
            ->postJson(route('comments.store', $video->uuid), [])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['content']);
    }

    public function test_report_rejects_invalid_payload_shape(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('report'), [
                'reason' => 'not-a-valid-reason',
                'type' => 'not-a-model',
                'id' => 'not-an-id',
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['reason', 'type', 'id']);
    }
}
