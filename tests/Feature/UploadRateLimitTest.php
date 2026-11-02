<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use App\Services\Upload18Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Security plan S28: the upload limiter was keyed on the member plus a resumableIdentifier the client picks, so every
 * request with a new ?resumableIdentifier= opened a fresh 10-a-minute bucket (and generate-server-thumbnails runs
 * ffmpeg on this server on demand). Now each member has one bucket; only the pieces of a file stored on this server keep
 * one bucket per file, as before; server thumbnails run at most twice a minute. The app's own pages never reach it.
 */
class UploadRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Video $video;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.upload_rate_limit' => 10]);
        $this->member = User::factory()->create();
        $this->video = Video::factory()->for($this->member, 'user')->create(['embed_code' => null]);
        $this->actingAs($this->member);
    }

    public function test_a_made_up_identifier_no_longer_opens_a_fresh_bucket(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->getJson(route('videos.create-status', ['video' => $this->video->id, 'resumableIdentifier' => 'made-up-'.$i]))->assertOk();
        }

        // The same trick on another route of the group counts against the same bucket.
        for ($i = 7; $i <= 10; $i++) {
            $this->assertNotLimited($this->postJson(route('videos.generate-thumbnails', ['video' => $this->video->id]), ['resumableIdentifier' => 'made-up-'.$i]));
        }

        $this->getJson(route('videos.create-status', ['video' => $this->video->id, 'resumableIdentifier' => 'made-up-11']))->assertStatus(429);
    }

    public function test_server_thumbnails_run_at_most_twice_a_minute(): void
    {
        $url = route('videos.generate-server-thumbnails', ['video' => $this->video->id]);

        $this->assertNotLimited($this->postJson($url, ['count' => 1]));
        $this->assertNotLimited($this->postJson($url, ['count' => 1]));
        $this->postJson($url, ['count' => 1])->assertStatus(429);

        // The member's other upload requests still have room (3 of 10 used).
        $this->getJson(route('videos.create-status', ['video' => $this->video->id]))->assertOk();

        $this->travel(61)->seconds();
        $this->assertNotLimited($this->postJson($url, ['count' => 1]));
    }

    public function test_pieces_of_a_file_stored_here_keep_one_bucket_per_file(): void
    {
        config(['services.upload18.allow_server_fallback' => true]);
        Storage::fake('local');

        for ($i = 1; $i <= 10; $i++) {
            $this->assertNotLimited($this->postJson(route('videos.upload18.chunk'), ['resumableIdentifier' => 'file-one', 'resumableChunkNumber' => $i]));
        }

        $this->postJson(route('videos.upload18.chunk'), ['resumableIdentifier' => 'file-one', 'resumableChunkNumber' => 11])->assertStatus(429);
        $this->assertNotLimited($this->postJson(route('videos.upload18.chunk'), ['resumableIdentifier' => 'file-two', 'resumableChunkNumber' => 1]), 'another file has its own bucket');
        $this->getJson(route('videos.create-status', ['video' => $this->video->id]))->assertOk();
    }

    public function test_the_upload_page_and_the_create_page_never_reach_the_limit(): void
    {
        config(['services.upload18.api_key' => 'test-key-not-real']);
        // 1.2.3: the status check looks the did up in the account's video list (findVideoByDid) and reads its
        // state with the static Upload18Service::statusFromVideo(); statusForDid stays for older callers.
        $this->mock(Upload18Service::class, function ($mock): void {
            $mock->shouldReceive('findVideoByDid')->andReturn(null);
            $mock->shouldReceive('statusForDid')->andReturn(['found' => false, 'ready' => false, 'vid' => '']);
        });
        $start = Carbon::parse('2026-10-25 12:00:00');

        // VideoUpload.tsx: the Upload18 status every 10 s, for up to 10 minutes.
        for ($second = 0; $second < 600; $second += 10) {
            $this->travelTo($start->copy()->addSeconds($second));
            $this->getJson(route('videos.upload18.status', ['did' => 'd1', 'vid' => 'v1']))->assertOk();
        }

        // The create page: create-status after 3 s and then every 10 s, and the server thumbnails once.
        $start = $start->copy()->addMinutes(11);
        $this->travelTo($start);
        $this->assertNotLimited($this->postJson(route('videos.generate-server-thumbnails', ['video' => $this->video->id]), ['count' => 3]));

        foreach (array_merge([3], range(10, 600, 10)) as $second) {
            $this->travelTo($start->copy()->addSeconds($second));
            $this->getJson(route('videos.create-status', ['video' => $this->video->id]))->assertOk();
        }
    }

    private function assertNotLimited(TestResponse $response, string $message = ''): void
    {
        $this->assertNotSame(429, $response->status(), $message !== '' ? $message : 'answered 429');
    }
}
