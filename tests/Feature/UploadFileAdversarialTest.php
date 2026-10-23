<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadFileAdversarialTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_upload_completion_rejects_traversal_filename_without_creating_video(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('videos.upload18.complete'), [
                'did' => 'upload_123',
                'vid' => 'embed_123',
                'filename' => '..\\..\\evil.mp4',
                'size' => 1024,
                'duration_seconds' => 60,
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['filename']);

        $this->assertDatabaseCount('videos', 0);
    }

    public function test_first_upload_chunk_rejects_mime_spoof_without_writing_chunk(): void
    {
        config(['services.upload18.allow_server_fallback' => true]);

        Storage::fake('local');

        $user = User::factory()->create();
        $identifier = 'valid-upload-identifier';

        $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('videos.upload18.chunk'), [
                'resumableFilename' => 'spoofed.mp4',
                'resumableTotalSize' => 1024,
                'resumableIdentifier' => $identifier,
                'resumableChunkNumber' => 1,
                'resumableTotalChunks' => 1,
                'file' => $this->fakeUpload('spoofed.mp4', 1, 'text/plain'),
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['file']);

        Storage::disk('local')->assertMissing(Video::CHUNK_FOLDER . '/' . $identifier . '/000001.part');
    }

    public function test_private_and_deleted_video_file_routes_do_not_expose_files_to_guests(): void
    {
        $privateVideo = Video::factory()
            ->for(User::factory(), 'user')
            ->withStatus(VideoStatus::PRIVATE)
            ->create(['file' => 'private-default.mp4']);

        $deletedVideo = Video::factory()
            ->for(User::factory(), 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'file' => 'deleted-default.mp4',
                'deleted_at' => now(),
            ]);

        $this->get(route('video.file', $privateVideo->file))
            ->assertStatus(Response::HTTP_FORBIDDEN);

        $this->get(route('video.file', $deletedVideo->file))
            ->assertNotFound();
    }
}
