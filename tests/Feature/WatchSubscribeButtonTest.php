<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The watch page's member Subscribe (Subscribe.tsx; member design 6.10, QA watch-35): the element names
 * the channel, so "Unsubscribe from <name>?" names it (it read "Unsubscribe from this channel?"), and carries the state
 * as subscribed="1|0", the attribute every member page prints.
 */
class WatchSubscribeButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_watch_pages_subscribe_button_names_the_channel_and_its_state(): void
    {
        $this->withoutVite();
        $creator = User::factory()->create(['username' => 'night_drive_tv']);
        $video = Video::factory()->withStatus(VideoStatus::PUBLIC)->for($creator)->create(['title' => 'Watch subscribe video', 'duration' => 125]);
        $member = User::factory()->create();

        $this->assertSubscribeButton($this->actingAs($member)->get($video->route)->assertOk()->getContent(), $creator, '0');

        $member->subscriptions()->attach($creator->id, ['subscribe_at' => now(), 'read_at' => now()]);
        $this->assertSubscribeButton($this->actingAs($member)->get($video->route)->assertOk()->getContent(), $creator, '1');
    }

    private function assertSubscribeButton(string $html, User $creator, string $state): void
    {
        $this->assertSame(1, preg_match('/<subscribe-button\b(?<attributes>[^>]*)>/s', $html, $matches), 'the member Subscribe of the watch page');
        $attributes = $matches['attributes'];

        $this->assertStringContainsString('user="' . $creator->id . '"', $attributes);
        $this->assertStringContainsString('name="night_drive_tv"', $attributes);
        $this->assertStringContainsString('subscribed="' . $state . '"', $attributes);
    }
}
