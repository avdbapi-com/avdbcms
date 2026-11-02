<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * R1-090 (round 1 judge): GET /api/videos/{id}/interactions?filter=up|down answered any verified member for any active
 * video, also with the creator's "show likes" off: anybody could list who liked or disliked a video. The site offers
 * that list only to the creator (Studio > Analytics, "See who rated it"). Now: the creator, and administrators with
 * admin powers here; everybody else gets exactly the answer of a video id that does not exist.
 */
class VideoRatersPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_creator_and_an_administrator_list_who_rated_a_video(): void
    {
        [$creator, $video, $fan, $critic] = $this->ratedVideo(['show_likes' => false]);

        // The creator: everybody who rated it, and each filter.
        $this->as($creator)->getJson($this->url($video))->assertOk()->assertJsonCount(2, 'data');
        $this->as($creator)->getJson($this->url($video, 'up'))->assertOk()->assertJsonCount(1, 'data');
        $this->as($creator)->getJson($this->url($video, 'down'))->assertOk()->assertJsonCount(1, 'data');

        // An administrator (no admin IP lock set).
        $this->as(User::factory()->admin()->create())->getJson($this->url($video, 'down'))->assertOk()->assertJsonCount(1, 'data');

        // Another member, one who rated it themselves, and the view-only demo account: the answer of a missing video.
        $demo = User::factory()->create();
        $demo->forceFill(['is_view_only' => true])->save();
        foreach (['another member' => User::factory()->create(), 'a member who rated it' => $fan, 'the view-only demo account' => $demo] as $who => $member) {
            foreach (['all', 'up', 'down'] as $filter) {
                $this->assertLikeAMissingVideo($this->as($member)->getJson($this->url($video, $filter)), $video, $who.' '.$filter);
            }
        }

        // A guest is asked to sign in, as before.
        $this->fresh();
        $this->getJson($this->url($video, 'up'))->assertUnauthorized();
    }

    public function test_the_answer_is_the_same_with_show_likes_on_for_a_deleted_video_and_for_a_private_one(): void
    {
        [$creator, $public] = $this->ratedVideo(['show_likes' => true]);
        $private = Video::factory()->for($creator)->withStatus(VideoStatus::PRIVATE)->create();
        $deleted = Video::factory()->for($creator)->withStatus(VideoStatus::PUBLIC)->create();
        $deleted->delete();
        $stranger = User::factory()->create();

        foreach ([$public, $private, $deleted] as $video) {
            $this->assertLikeAMissingVideo($this->as($stranger)->getJson($this->url($video, 'up')), $video, 'video '.$video->id);
        }

        // The creator still learns that a deleted video is deleted.
        $this->as($creator)->getJson($this->url($deleted))->assertNotFound()->assertJsonPath('message', 'This video is deleted');
    }

    public function test_an_administrator_outside_the_admin_ip_lock_is_an_ordinary_member_here(): void
    {
        [$creator, $video] = $this->ratedVideo(['show_likes' => true]);
        $admin = User::factory()->admin()->create();
        config(['site.admin_ip_whitelist' => '203.0.113.7']);

        $this->assertLikeAMissingVideo(
            $this->as($admin)->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->getJson($this->url($video, 'up')),
            $video,
            'an administrator outside the lock'
        );
        $this->as($admin)->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->getJson($this->url($video, 'up'))->assertOk();
    }

    private function assertLikeAMissingVideo(TestResponse $response, Video $video, string $who): void
    {
        $missing = (int) Video::withTrashed()->max('id') + 1000;
        $this->fresh();
        $reference = $this->actingAs(User::factory()->create())->getJson('/api/videos/'.$missing.'/interactions');

        $this->assertSame(404, $reference->getStatusCode(), 'a missing video id');
        $this->assertSame(404, $response->getStatusCode(), $who);
        $this->assertSame(
            str_replace((string) $missing, '{id}', (string) $reference->json('message')),
            str_replace((string) $video->id, '{id}', (string) $response->json('message')),
            $who.': the answer must read like the one for a missing video'
        );
        $this->assertSame(array_keys((array) $reference->json()), array_keys((array) $response->json()), $who);
    }

    /** @return array{0: User, 1: Video, 2: User, 3: User} the creator, an active video, a member who liked it, one who disliked it */
    private function ratedVideo(array $attributes): array
    {
        $creator = User::factory()->create();
        $video = Video::factory()->for($creator)->withStatus(VideoStatus::PUBLIC)->create($attributes + ['published_at' => now()->subDay()]);
        $fan = User::factory()->create();
        $critic = User::factory()->create();
        $video->interactions()->create(['user_id' => $fan->id, 'status' => true, 'perform_at' => now()]);
        $video->interactions()->create(['user_id' => $critic->id, 'status' => false, 'perform_at' => now()]);

        return [$creator, $video, $fan, $critic];
    }

    private function url(Video $video, string $filter = 'all'): string
    {
        return '/api/videos/'.$video->id.'/interactions?filter='.$filter;
    }

    private function as(User $user): static
    {
        $this->fresh();

        return $this->actingAs($user);
    }

    private function fresh(): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->app['auth']->guard('web')->logout();
        Auth::forgetGuards();
    }
}
