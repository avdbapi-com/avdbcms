<?php

namespace Tests\Feature;

use App\Enums\CustomPlaylistType;
use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Playlists\PlaylistManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * R1-168 (round 1 judge, from SEC-VERIFY's S07 rows): POST /user/playlists/watch-later/{id} with state=off answered 200
 * "removed" for any existing video (another member's private one included) and 404 for an id that does not exist, and
 * adding answered "This video is private" (or 410 for a banned one) against the missing id's text: the answers told
 * which video ids exist. Now a video that is not in the member's Watch later and that the member may not watch gets
 * exactly the answer of a missing id, removing or adding; a video in the list can always be taken out.
 */
class WatchLaterOracleTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_members_hidden_videos_are_answered_like_a_missing_id(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $hidden = [
            'private' => Video::factory()->for($owner)->withStatus(VideoStatus::PRIVATE)->create(),
            'draft' => Video::factory()->for($owner)->withStatus(VideoStatus::DRAFT)->create(),
            'banned' => Video::factory()->for($owner)->withStatus(VideoStatus::BANNED)->banned()->create(),
        ];

        foreach ($hidden as $kind => $video) {
            foreach ([['state' => 'off'], ['state' => 'on'], []] as $input) {
                $label = $kind.' '.json_encode($input);
                $this->assertLikeAMissingId($this->toggle($member, $video->id, $input), $video->id, $member, $input, $label);
            }
        }

        $this->assertSame(0, PlaylistManager::get(CustomPlaylistType::WATCH_LATER)->getPlaylist()->videos()->count(), 'nothing was added');
    }

    public function test_what_the_member_may_watch_or_already_saved_works_as_before(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $public = Video::factory()->for($other)->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->subDay()]);
        $own = Video::factory()->for($member)->withStatus(VideoStatus::PRIVATE)->create();

        $this->toggle($member, $public->id, ['state' => 'on'])->assertOk()->assertJsonPath('status', 'added');
        $this->toggle($member, $own->id, ['state' => 'on'])->assertOk()->assertJsonPath('status', 'added');
        $this->toggle($member, $own->id, ['state' => 'off'])->assertOk()->assertJsonPath('status', 'removed');

        // A public video not in the list: removing it again answers "removed", as before (a row left in another tab).
        $this->toggle($member, $public->id, ['state' => 'off'])->assertOk()->assertJsonPath('status', 'removed');
        $this->toggle($member, $public->id, ['state' => 'off'])->assertOk()->assertJsonPath('status', 'removed');

        // A video saved while it was public and made private later can still be taken out.
        $this->toggle($member, $public->id, ['state' => 'on'])->assertOk();
        $public->forceFill(['status' => VideoStatus::PRIVATE->value])->save();
        $this->toggle($member, $public->id, ['state' => 'off'])->assertOk()->assertJsonPath('status', 'removed');
    }

    public function test_a_guest_and_the_view_only_demo_account_learn_nothing_either(): void
    {
        $owner = User::factory()->create();
        $private = Video::factory()->for($owner)->withStatus(VideoStatus::PRIVATE)->create();

        $this->fresh();
        $this->postJson('/user/playlists/watch-later/'.$private->id, ['state' => 'off'])->assertUnauthorized();

        $demo = User::factory()->create();
        $demo->forceFill(['is_view_only' => true])->save();
        $this->assertLikeAMissingId($this->toggle($demo, $private->id, ['state' => 'off']), $private->id, $demo, ['state' => 'off'], 'view-only demo');
    }

    private function assertLikeAMissingId(TestResponse $response, int $id, User $member, array $input, string $label): void
    {
        $missing = (int) Video::withTrashed()->max('id') + 1000;
        $reference = $this->toggle($member, $missing, $input);

        $this->assertSame($reference->getStatusCode(), $response->getStatusCode(), $label);
        $this->assertSame(
            str_replace((string) $missing, '{id}', (string) $reference->getContent()),
            str_replace((string) $id, '{id}', (string) $response->getContent()),
            $label.': the answer must be the one of a missing id'
        );
    }

    private function toggle(User $member, int $videoId, array $input): TestResponse
    {
        $this->fresh();

        return $this->actingAs($member)->postJson('/user/playlists/watch-later/'.$videoId, $input);
    }

    private function fresh(): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->app['auth']->guard('web')->logout();
        Auth::forgetGuards();
    }
}
