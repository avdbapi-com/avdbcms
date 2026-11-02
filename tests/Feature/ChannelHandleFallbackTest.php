<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QA watch-23: the handle shown and shared everywhere (/@lofi_room, the username) answered 404; only the slug (/@LofiRoom)
 * opened the channel. Every address of a channel now lands on its canonical address.
 */
class ChannelHandleFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_username_opens_the_channel_at_its_canonical_address(): void
    {
        config(['site.profile_uid_urls_enabled' => true]);
        $channel = User::factory()->create(['username' => 'lofi_room', 'slug' => 'LofiRoom', 'profile_uid' => 'ab12cd34']);

        $this->get('/@lofi_room')->assertStatus(301)->assertRedirect($channel->route);
        $this->get('/@LofiRoom')->assertStatus(301)->assertRedirect($channel->route);
        $this->get(parse_url($channel->route, PHP_URL_PATH))->assertOk();
    }

    public function test_the_username_works_without_uid_addresses_too_and_unknown_handles_stay_404(): void
    {
        config(['site.profile_uid_urls_enabled' => false]);
        $channel = User::factory()->create(['username' => 'night_drive', 'slug' => 'NightDrive']);

        $this->get('/@night_drive')->assertStatus(301)->assertRedirect($channel->route);
        $this->get('/@NightDrive')->assertOk();
        $this->get('/@nobody_has_this_name')->assertNotFound();
    }

    public function test_a_slug_that_looks_like_name_and_uid_still_opens_its_channel(): void
    {
        config(['site.profile_uid_urls_enabled' => true]);
        $channel = User::factory()->create(['username' => 'synth_wave', 'slug' => 'synth-wave2024', 'profile_uid' => 'zz99yy88']);

        // "-wave2024" has the shape of an 8 character uid: the lookup used to stop there and answer 404
        $this->assertTrue($channel->is((new User())->resolveRouteBinding('synth-wave2024')));
        $this->get('/@synth-wave2024')->assertStatus(301)->assertRedirect($channel->route);
    }
}
