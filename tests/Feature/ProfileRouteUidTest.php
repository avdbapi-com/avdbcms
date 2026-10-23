<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileRouteUidTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_route_uses_slug_plus_short_uid(): void
    {
        $user = User::factory()->create([
            'slug' => 'small-breasts',
            'profile_uid' => 'a1b2c3d4',
        ]);

        $this->assertSame(url('/@small-breasts-a1b2c3d4'), route('user.show', $user));
        $this->assertSame('small-breasts-a1b2c3d4', $user->profile_route_key);
    }

    public function test_legacy_profile_slug_redirects_to_canonical_uid_url(): void
    {
        $user = User::factory()->create([
            'slug' => 'small-breasts',
            'profile_uid' => 'a1b2c3d4',
        ]);

        $this->get('/@small-breasts')
            ->assertRedirect($user->route)
            ->assertStatus(301);
    }

    public function test_profile_uid_urls_can_be_disabled_from_seo_settings(): void
    {
        config(['site.profile_uid_urls_enabled' => false]);

        $user = User::factory()->create([
            'slug' => 'small-breasts',
            'profile_uid' => 'a1b2c3d4',
        ]);

        $this->assertSame(url('/@small-breasts'), route('user.show', $user));
        $this->assertSame('small-breasts', $user->profile_route_key);

        $this->get('/@small-breasts-a1b2c3d4')
            ->assertRedirect($user->route)
            ->assertStatus(301);
    }

    public function test_profile_uid_resolves_when_slug_is_not_canonical(): void
    {
        $user = User::factory()->create([
            'slug' => 'admin',
            'profile_uid' => 'z9y8x7w6',
        ]);

        $this->get('/@old-admin-z9y8x7w6')
            ->assertRedirect($user->route)
            ->assertStatus(301);
    }

    public function test_canonical_profile_page_has_canonical_link(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'slug' => 'admin',
            'profile_uid' => 'z9y8x7w6',
        ]);

        $this->get('/@admin-z9y8x7w6')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="' . $user->route . '">', false)
            ->assertSee('"@type":"ProfilePage"', false)
            ->assertSee('class="avd-channel-avatar-wrap avd-avatar-frame"', false)
            ->assertSee('class="avd-channel-avatar-img avd-avatar-media"', false)
            ->assertSee('width="120" height="120"', false)
            ->assertSee('onerror="this.onerror=null;this.src=', false)
            ->assertSee('default_men.png', false)
            ->assertSee('class="avd-subscribe-button btn btn-dark', false)
            ->assertSee('fa-user-plus', false);
    }

    public function test_profile_subscribe_control_maps_guest_and_authenticated_state(): void
    {
        $this->withoutVite();

        $creator = User::factory()->create([
            'slug' => 'profile-creator',
            'profile_uid' => 'c3d4e5f6',
        ]);

        $guestHtml = $this->get($creator->route)
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<subscribe-button', $guestHtml);
        $this->assertStringContainsString('class="avd-subscribe-button btn btn-dark', $guestHtml);

        $viewer = User::factory()->create();

        $unsubscribedHtml = $this->actingAs($viewer)
            ->get($creator->route)
            ->assertOk()
            ->getContent();

        $this->assertSubscribeElementState($unsubscribedHtml, $creator, true);

        $viewer->subscriptions()->attach($creator->id, [
            'subscribe_at' => now(),
            'read_at' => now(),
        ]);

        $subscribedHtml = $this->actingAs($viewer)
            ->get($creator->route)
            ->assertOk()
            ->getContent();

        $this->assertSubscribeElementState($subscribedHtml, $creator, false);
    }

    private function assertSubscribeElementState(string $html, User $creator, bool $expectsSubscribeAttribute): void
    {
        $this->assertSame(1, preg_match('/<subscribe-button\b(?<attributes>[^>]*)>/s', $html, $matches));
        $attributes = $matches['attributes'];

        $this->assertStringContainsString('user="' . $creator->id . '"', $attributes);

        if ($expectsSubscribeAttribute) {
            $this->assertStringContainsString('is-subscribe', $attributes);
        } else {
            $this->assertStringNotContainsString('is-subscribe', $attributes);
        }
    }

    public function test_profile_sitemap_includes_canonical_uid_url(): void
    {
        $user = User::factory()->create([
            'slug' => 'small-breasts',
            'profile_uid' => 'a1b2c3d4',
        ]);

        Video::factory()
            ->for($user)
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'uploaded_at' => now()->subDay(),
                'published_at' => now()->subDay(),
            ]);

        $this->get('/sitemap-profiles-en-1.xml')
            ->assertOk()
            ->assertSee($user->route, false);
    }

    public function test_profile_sitemap_uses_requested_locale_url_and_hreflang_links(): void
    {
        $user = User::factory()->create([
            'slug' => 'localized-profile',
            'profile_uid' => 'b2c3d4e5',
        ]);

        Video::factory()
            ->for($user)
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'uploaded_at' => now()->subDay(),
                'published_at' => now()->subDay(),
                'avdb_id' => 987654,
            ]);

        $this->get('/sitemap-profiles-tr-1.xml')
            ->assertOk()
            ->assertSee('<loc>' . url('/tr/@localized-profile-b2c3d4e5') . '</loc>', false)
            ->assertSee('hreflang="tr"', false)
            ->assertSee('href="' . url('/tr/@localized-profile-b2c3d4e5') . '"', false)
            ->assertSee('hreflang="x-default"', false)
            ->assertDontSee('<loc>' . $user->route . '</loc>', false);
    }
}
