<?php

namespace Tests\Feature;

use App\Enums\ThumbnailStatus;
use App\Enums\VideoStatus;
use App\Events\Video\VideoUploaded;
use App\Jobs\UploadToUpload18;
use App\Models\Category;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use App\Notifications\Activity\NewComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QueueMediaEmailSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_chunk_completion_dispatches_upload18_queue_job_and_pending_thumbnails(): void
    {
        config(['services.upload18.allow_server_fallback' => true]);

        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $identifier = 'avdbcms-upload-' . str()->random(8);

        $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('videos.upload18.chunk'), [
                'resumableFilename' => 'avdbcms-smoke.mp4',
                'resumableTotalSize' => 1024,
                'resumableIdentifier' => $identifier,
                'resumableChunkNumber' => 1,
                'resumableTotalChunks' => 1,
                'file' => $this->fakeUpload('avdbcms-smoke.mp4', 1, 'video/mp4'),
            ])
            ->assertOk()
            ->assertJsonPath('complete', true);

        $video = Video::query()->where('original_file_name', 'avdbcms-smoke.mp4')->firstOrFail();

        $this->assertSame($user->id, $video->user_id);
        $this->assertSame(ThumbnailStatus::PENDING, $video->thumbnails()->firstOrFail()->status);

        Queue::assertPushed(UploadToUpload18::class, function (UploadToUpload18 $job) use ($identifier, $video, $user): bool {
            return $job->folder === ((string) $user->id).'/'.hash('sha256', $identifier)
                && $job->video->is($video)
                && $job->extension === 'mp4';
        });
    }

    public function test_direct_upload_completion_dispatches_video_uploaded_event_without_queueing_file_bytes(): void
    {
        Event::fake([VideoUploaded::class]);
        Queue::fake();

        $user = User::factory()->create(['is_admin' => true]);
        Http::fake([
            '*' => Http::response([
                'data' => [[
                    'id' => 'upload18-did-123',
                    'vid' => 'embed-code-123',
                    'zt' => '2',
                    'duration' => '1:30',
                ]],
                'total_page' => 1,
            ]),
        ]);

        $this->actingAs($user)
            ->getJson(route('videos.upload18.status', [
                'did' => 'upload18-did-123',
            ]))
            ->assertOk()
            ->assertJsonPath('ready', true);

        $this->actingAs($user)
            ->postJson(route('videos.upload18.complete'), [
                'did' => 'upload18-did-123',
                'vid' => 'embed-code-123',
                'filename' => 'direct-upload-smoke.mp4',
                'size' => 2048,
                'duration_seconds' => 90,
            ])
            ->assertOk()
            ->assertJsonStructure(['route']);

        $video = Video::query()->where('embed_code', 'embed-code-123')->firstOrFail();

        $this->assertSame($user->id, $video->user_id);
        $this->assertSame(90, (int) $video->getRawOriginal('duration'));

        Event::assertDispatched(VideoUploaded::class, fn (VideoUploaded $event) => $event->video->is($video));
        Queue::assertNotPushed(UploadToUpload18::class);
    }

    public function test_generated_thumbnail_submission_writes_media_files_and_marks_first_active(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $video = $this->publicVideo($owner, 'Thumbnail Smoke Target');

        $image = 'data:image/jpeg;base64,' . base64_encode("\xff\xd8\xff\xd9");

        $response = $this->actingAs($owner)
            ->postJson(route('videos.generate-thumbnails', $video->id), [
                'thumbnails' => [$image, $image],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 2)
            ->assertJsonPath('data.0.status', ThumbnailStatus::GENERATED->value)
            ->assertJsonPath('data.0.is_active', true);

        $this->assertStringContainsString("/video/{$video->uuid}/thumbnails/", $response->json('data.0.url'));

        $thumbnails = $video->thumbnails()->where('status', ThumbnailStatus::GENERATED)->orderBy('id')->get();

        $this->assertCount(2, $thumbnails);
        $this->assertTrue((bool) $thumbnails->first()->is_active);
        $this->assertFalse((bool) $thumbnails->last()->is_active);

        $this->actingAs($owner)
            ->getJson(route('thumbnails.list', $video->id))
            ->assertOk()
            ->assertJsonPath('data.0.status', ThumbnailStatus::GENERATED->value)
            ->assertJsonPath('data.0.is_active', true);

        foreach ($thumbnails as $thumbnail) {
            Storage::disk('local')->assertExists(Video::THUMBNAIL_FOLDER . '/' . $thumbnail->file);
        }
    }

    public function test_comment_creation_sends_email_notification_to_video_owner(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $commenter = User::factory()->create();
        $video = $this->publicVideo($owner, 'Comment Notification Target');

        $this->actingAs($commenter)
            ->postJson(route('comments.store', $video->uuid), [
                'content' => 'Queue and email smoke comment body.',
            ])
            ->assertSuccessful();

        $comment = Comment::query()->where('video_id', $video->id)->firstOrFail();

        Notification::assertSentTo(
            $owner,
            NewComment::class,
            function (NewComment $notification) use ($owner, $comment): bool {
                $payload = $notification->toArray($owner);

                return $payload['url'] === $comment->video->route
                    && $payload['message'] === 'You have new comment on your video !';
            }
        );
        Notification::assertNotSentTo($commenter, NewComment::class);
    }

    private function publicVideo(User $owner, string $title): Video
    {
        $category = Category::query()->create([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'icon' => 'play',
            'in_menu' => true,
            'position' => 1,
        ]);

        return Video::factory()
            ->for($owner, 'user')
            ->for($category)
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => $title,
                'slug' => str($title)->slug()->toString(),
                'published_at' => now()->subMinute(),
                'uploaded_at' => now()->subMinute(),
                'language' => null,
                'allow_comments' => true,
            ]);
    }
}
