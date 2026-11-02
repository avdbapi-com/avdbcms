<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Support\VerifiedCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * watch-24: the check mark is a channel's status (a confirmed phone, or a site administrator), not a site-wide switch
 * that made every brand-new member look verified; with the switch off nobody gets it.
 */
class VerifiedCreatorTickTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_checked_channels_count_as_verified(): void
    {
        config(['site.creator_verified_badge_enabled' => true]);

        $this->assertFalse(VerifiedCreator::is(null));
        $this->assertFalse(VerifiedCreator::is(User::factory()->create(['phone_verified_at' => null, 'is_admin' => false])));
        $this->assertTrue(VerifiedCreator::is(User::factory()->create(['phone_verified_at' => now(), 'is_admin' => false])));
        $this->assertTrue(VerifiedCreator::is(User::factory()->create(['phone_verified_at' => null, 'is_admin' => true])));

        config(['site.creator_verified_badge_enabled' => false]);
        $this->assertFalse(VerifiedCreator::is(User::factory()->create(['phone_verified_at' => now(), 'is_admin' => true])));
    }

    public function test_the_related_cards_print_the_tick_for_verified_channels_only(): void
    {
        config(['site.creator_verified_badge_enabled' => true]);
        $newcomer = User::factory()->create(['phone_verified_at' => null, 'is_admin' => false]);
        $checked = User::factory()->create(['phone_verified_at' => now(), 'is_admin' => false]);

        // Loaded from the database, as a page loads them (fresh()).
        $plain = view('videos.card-secondary', ['video' => Video::factory()->withStatus(VideoStatus::PUBLIC)->for($newcomer)->create(['views' => 12])->fresh()])->render();
        $ticked = view('videos.card-secondary', ['video' => Video::factory()->withStatus(VideoStatus::PUBLIC)->for($checked)->create(['views' => 12])->fresh()])->render();

        $this->assertStringNotContainsString('fa-circle-check', $plain);
        $this->assertStringContainsString('fa-circle-check', $ticked);
        // Server-printed pictures (watch-6 / perf-3), not the <image-loaded> component.
        $this->assertStringContainsString('<img class="avd-image-loaded-img"', $ticked);
        $this->assertStringNotContainsString('<image-loaded', $ticked);
    }
}
