<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AdminMonetizationAdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_responsive_test_ads_are_seeded_and_enabled(): void
    {
        $this->assertSame('1', Setting::get('ads_enabled'));
        $this->assertSame('1', Setting::get('ads_hide_for_premium'));
        $this->assertSame('1', Setting::get('ad_preroll_enabled'));
        $this->assertSame('1', Setting::get('ad_onclick_enabled'));
        $this->assertSame('5', Setting::get('ad_preroll_duration'));
        $this->assertStringContainsString('https://test-videos.co.uk/vids/bigbuckbunny/mp4/h264/1080/Big_Buck_Bunny_1080_10s_30MB.mp4', Setting::get('ad_preroll_media_url'));
        $this->assertStringContainsString('https://content.jwplatform.com/manifests/yp34SRmf.m3u8', Setting::get('ad_preroll_media_url'));
        $this->assertSame('6', Setting::get('ad_grid_position'));
        $this->assertSame('1', Setting::get('ad_grid_max_per_feed'));
        $this->assertSame('grid_card', Setting::get('ad_grid_layout'));
        $this->assertSame('inherit', Setting::get('ad_grid_layout_smart_discovery'));
        $this->assertSame('inherit', Setting::get('ad_grid_layout_creator_spotlight'));
        $this->assertSame('inherit', Setting::get('ad_grid_layout_trending_actors'));
        $this->assertSame('inherit', Setting::get('ad_grid_layout_new_videos'));
        $this->assertSame('inherit', Setting::get('ad_grid_layout_trend_page'));
        $this->assertSame('inherit', Setting::get('ad_grid_layout_category_page'));
        $this->assertSame('inherit', Setting::get('ad_grid_layout_subscriptions'));
        $this->assertSame('inherit', Setting::get('ad_grid_layout_channel_videos'));
        $this->assertSame('wide_row', Setting::get('ad_grid_layout_search_results'));
        $this->assertSame('wide_row', Setting::get('ad_grid_layout_playlist_videos'));
        $this->assertSame('wide_row', Setting::get('ad_grid_layout_watch_related'));
        $this->assertSame('inherit', Setting::get('ad_grid_layout_channel_home_featured'));
        $this->assertSame('inherit', Setting::get('ad_grid_layout_channel_home_latest'));
        $this->assertStringContainsString('avd-test-ad--leaderboard', Setting::get('ad_code_header', ''));
        $this->assertNull(Setting::query()->where('group', 'monetization')->where('key', 'ad_code_grid')->value('id'));
        $this->assertStringContainsString('avd-test-ad--smart', Setting::get('ad_code_grid_smart_discovery', ''));
        $this->assertStringContainsString('avd-test-ad--creator', Setting::get('ad_code_grid_creator_spotlight', ''));
        $this->assertStringContainsString('avd-test-ad--trending', Setting::get('ad_code_grid_trending_actors', ''));
        $this->assertStringContainsString('avd-test-ad--new', Setting::get('ad_code_grid_new_videos', ''));
        $this->assertStringContainsString('avd-test-ad--trend', Setting::get('ad_code_grid_trend_page', ''));
        $this->assertStringContainsString('avd-test-ad--category', Setting::get('ad_code_grid_category_page', ''));
        $this->assertStringContainsString('avd-test-ad--subs', Setting::get('ad_code_grid_subscriptions', ''));
        $this->assertStringContainsString('avd-test-ad--channel', Setting::get('ad_code_grid_channel_videos', ''));
        $this->assertStringContainsString('avd-test-ad--search', Setting::get('ad_code_grid_search_results', ''));
        $this->assertStringContainsString('avd-test-ad--playlist', Setting::get('ad_code_grid_playlist_videos', ''));
        $this->assertStringContainsString('avd-test-ad--related', Setting::get('ad_code_grid_watch_related', ''));
        $this->assertStringContainsString('avd-test-ad--featured', Setting::get('ad_code_grid_channel_home_featured', ''));
        $this->assertStringContainsString('avd-test-ad--latest', Setting::get('ad_code_grid_channel_home_latest', ''));
        $this->assertStringContainsString('avd-test-ad--sidebar', Setting::get('ad_code_sidebar', ''));
        $this->assertStringContainsString('avd-test-ad--video', Setting::get('ad_code_video', ''));
        $this->assertStringContainsString('avd-test-ad--footer', Setting::get('ad_code_footer', ''));
        $this->assertStringContainsString('avd-test-ad--mobile', Setting::get('ad_code_mobile', ''));
        $this->assertStringContainsString('OnClick / Popunder sample', Setting::get('ad_code_onclick', ''));
        $this->assertNull(Setting::query()->where('group', 'monetization')->where('key', 'ad_push_notifications_enabled')->value('id'));
        $this->assertNull(Setting::query()->where('group', 'monetization')->where('key', 'ad_direct_link_enabled')->value('id'));
        $this->assertNull(Setting::query()->where('group', 'monetization')->where('key', 'ad_direct_link_url')->value('id'));
        $this->assertNull(Setting::query()->where('group', 'monetization')->where('key', 'ad_code_push_notifications')->value('id'));
        $this->assertNull(Setting::query()->where('group', 'monetization')->where('key', 'ad_in_page_push_enabled')->value('id'));
        $this->assertNull(Setting::query()->where('group', 'monetization')->where('key', 'ad_vignette_enabled')->value('id'));
        $this->assertNull(Setting::query()->where('group', 'monetization')->where('key', 'ad_code_in_page_push')->value('id'));
        $this->assertNull(Setting::query()->where('group', 'monetization')->where('key', 'ad_code_vignette')->value('id'));
        $this->assertNull(Setting::query()->where('group', 'monetization')->where('key', 'ad_midroll_enabled')->value('id'));
        $this->assertNull(Setting::query()->where('group', 'monetization')->where('key', 'ad_midroll_media_url')->value('id'));
        $this->assertNull(Setting::query()->where('group', 'monetization')->where('key', 'ad_midroll_position_percent')->value('id'));
    }

    public function test_monetization_form_renders_ad_controls(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.settings.index', ['tab' => 'monetization']))
            ->assertOk()
            ->assertSee('name="ads_enabled"', false)
            ->assertSee('name="ads_hide_for_premium"', false)
            ->assertSee('name="ad_preroll_enabled"', false)
            ->assertSee('name="ad_onclick_enabled"', false)
            ->assertSee('name="ad_preroll_duration"', false)
            ->assertSee('name="ad_preroll_media_url"', false)
            ->assertDontSee('name="ad_midroll_enabled"', false)
            ->assertDontSee('name="ad_midroll_media_url"', false)
            ->assertDontSee('name="ad_midroll_position_percent"', false)
            ->assertDontSee('Video Mid-roll')
            ->assertDontSee('Mid-roll Position')
            ->assertSee('name="ad_grid_position"', false)
            ->assertSee('name="ad_grid_max_per_feed"', false)
            ->assertSee('name="ad_grid_layout"', false)
            ->assertSee('name="ad_grid_layout_smart_discovery"', false)
            ->assertSee('name="ad_grid_layout_creator_spotlight"', false)
            ->assertSee('name="ad_grid_layout_trending_actors"', false)
            ->assertSee('name="ad_grid_layout_new_videos"', false)
            ->assertSee('name="ad_grid_layout_trend_page"', false)
            ->assertSee('name="ad_grid_layout_category_page"', false)
            ->assertSee('name="ad_grid_layout_subscriptions"', false)
            ->assertSee('name="ad_grid_layout_channel_videos"', false)
            ->assertSee('name="ad_grid_layout_search_results"', false)
            ->assertSee('name="ad_grid_layout_playlist_videos"', false)
            ->assertSee('name="ad_grid_layout_watch_related"', false)
            ->assertSee('name="ad_grid_layout_channel_home_featured"', false)
            ->assertSee('name="ad_grid_layout_channel_home_latest"', false)
            ->assertSee('name="ad_code_header"', false)
            ->assertDontSee('name="ad_code_grid"', false)
            ->assertDontSee('Native Grid Card')
            ->assertSee('name="ad_code_grid_smart_discovery"', false)
            ->assertSee('name="ad_code_grid_creator_spotlight"', false)
            ->assertSee('name="ad_code_grid_trending_actors"', false)
            ->assertSee('name="ad_code_grid_new_videos"', false)
            ->assertSee('name="ad_code_grid_trend_page"', false)
            ->assertSee('name="ad_code_grid_category_page"', false)
            ->assertSee('name="ad_code_grid_subscriptions"', false)
            ->assertSee('name="ad_code_grid_channel_videos"', false)
            ->assertSee('name="ad_code_grid_search_results"', false)
            ->assertSee('name="ad_code_grid_playlist_videos"', false)
            ->assertSee('name="ad_code_grid_watch_related"', false)
            ->assertSee('name="ad_code_grid_channel_home_featured"', false)
            ->assertSee('name="ad_code_grid_channel_home_latest"', false)
            ->assertSee('name="ad_code_sidebar"', false)
            ->assertSee('name="ad_code_video"', false)
            ->assertSee('name="ad_code_footer"', false)
            ->assertSee('name="ad_code_mobile"', false)
            ->assertSee('name="ad_code_onclick"', false)
            ->assertDontSee('name="ad_push_notifications_enabled"', false)
            ->assertDontSee('name="ad_direct_link_enabled"', false)
            ->assertDontSee('name="ad_direct_link_url"', false)
            ->assertDontSee('name="ad_code_push_notifications"', false)
            ->assertDontSee('Push Notifications Script')
            ->assertDontSee('Direct Link URL')
            ->assertDontSee('Admin ad preview')
            ->assertDontSee('Open Ad Preview')
            ->assertDontSee('ad_preview=1', false)
            ->assertDontSee('name="ad_in_page_push_enabled"', false)
            ->assertDontSee('name="ad_vignette_enabled"', false)
            ->assertDontSee('name="ad_code_in_page_push"', false)
            ->assertDontSee('name="ad_code_vignette"', false)
            ->assertDontSee('In-Page Push Creative')
            ->assertDontSee('Vignette Banner Creative')
            ->assertSee('Mobile Sticky Mini Banner');
    }

    public function test_onclick_ad_format_renders_on_public_layout(): void
    {
        config([
            'site.ads_enabled' => true,
            'site.ads_hide_for_premium' => false,
            'site.ad_onclick_enabled' => true,
            'site.ad_code_onclick' => Setting::get('ad_code_onclick', ''),
        ]);

        $this->get(route('pages.home'))
            ->assertOk()
            ->assertDontSee('id="ad-overlay-stack"', false)
            ->assertDontSee('id="ad-slot-vignette"', false)
            ->assertDontSee('id="ad-slot-in-page-push"', false)
            ->assertSee('id="ad-slot-onclick"', false)
            ->assertDontSee('id="ad-slot-push-notifications"', false)
            ->assertDontSee('directLinkEnabled', false)
            ->assertDontSee('directLinkUrl', false)
            ->assertDontSee('preview: true', false)
            ->assertDontSee('preview: false', false);
    }

    public function test_player_ad_config_exports_random_preroll_media_urls(): void
    {
        config([
            'site.ads_enabled' => true,
            'site.ads_hide_for_premium' => false,
            'site.ad_preroll_enabled' => true,
            'site.ad_preroll_duration' => 10,
            'site.ad_preroll_media_url' => "https://cdn.example.test/preroll-one.mp4\nhttps://cdn.example.test/preroll-two.m3u8",
            'site.ad_code_video' => '<div>fallback</div>',
        ]);

        $this->get(route('pages.home'))
            ->assertOk()
            ->assertSee('prerollEnabled: true', false)
            ->assertSee('prerollDuration: 10', false)
            ->assertSee('prerollMediaUrls: ["https://cdn.example.test/preroll-one.mp4","https://cdn.example.test/preroll-two.m3u8"]', false)
            ->assertDontSee('midrollEnabled', false)
            ->assertDontSee('midrollMediaUrl', false)
            ->assertDontSee('midrollPositionPercent', false);
    }

    public function test_removed_ad_preview_query_does_not_force_ads(): void
    {
        config([
            'site.ads_enabled' => false,
            'site.ads_hide_for_premium' => true,
            'site.ad_onclick_enabled' => false,
            'site.ad_code_onclick' => Setting::get('ad_code_onclick', ''),
        ]);

        $this->get(route('pages.home', ['ad_preview' => 1]))
            ->assertOk()
            ->assertDontSee('id="ad-overlay-stack"', false)
            ->assertDontSee('id="ad-slot-onclick"', false)
            ->assertDontSee('preview: true', false)
            ->assertDontSee('preview: false', false);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('pages.home', ['ad_preview' => 1]))
            ->assertOk()
            ->assertDontSee('class="avd-app ad-preview-mode', false)
            ->assertDontSee('id="ad-overlay-stack"', false)
            ->assertDontSee('id="ad-slot-vignette"', false)
            ->assertDontSee('id="ad-slot-in-page-push"', false)
            ->assertDontSee('id="ad-slot-onclick"', false)
            ->assertDontSee('id="ad-slot-push-notifications"', false)
            ->assertDontSee('preview: true', false)
            ->assertDontSee('preview: false', false)
            ->assertDontSee('directLinkEnabled', false);
    }

    public function test_admin_can_update_all_ad_settings_and_clear_cache(): void
    {
        $admin = User::factory()->admin()->create();

        Cache::forever('settings.monetization', ['stale' => true]);
        Cache::forever('settings.__all_grouped', ['monetization' => ['stale' => true]]);

        $this->actingAs($admin)
            ->put(route('admin.settings.update.group', 'monetization'), [
                'premium_enabled' => '1',
                'stripe_enabled' => '0',
                'paypal_enabled' => '0',
                'show_premium_badge' => '1',
                'ads_enabled' => '1',
                'ads_hide_for_premium' => '1',
                'ad_preroll_enabled' => '1',
                'ad_onclick_enabled' => '1',
                'ad_preroll_duration' => '7',
                'ad_preroll_media_url' => "https://cdn.example.test/ad-one.mp4\nhttps://cdn.example.test/ad-two.m3u8",
                'ad_grid_position' => '8',
                'ad_grid_max_per_feed' => '2',
                'ad_grid_layout' => 'wide_row',
                'ad_grid_layout_smart_discovery' => 'grid_card',
                'ad_grid_layout_creator_spotlight' => 'wide_row',
                'ad_grid_layout_trending_actors' => 'inherit',
                'ad_grid_layout_new_videos' => 'grid_card',
                'ad_grid_layout_trend_page' => 'wide_row',
                'ad_grid_layout_category_page' => 'grid_card',
                'ad_grid_layout_subscriptions' => 'inherit',
                'ad_grid_layout_channel_videos' => 'wide_row',
                'ad_grid_layout_search_results' => 'wide_row',
                'ad_grid_layout_playlist_videos' => 'grid_card',
                'ad_grid_layout_watch_related' => 'wide_row',
                'ad_grid_layout_channel_home_featured' => 'inherit',
                'ad_grid_layout_channel_home_latest' => 'grid_card',
                'ad_code_header' => '<div>header ad</div>',
                'ad_code_grid_smart_discovery' => '<div>smart ad</div>',
                'ad_code_grid_creator_spotlight' => '<div>creator ad</div>',
                'ad_code_grid_trending_actors' => '<div>trending ad</div>',
                'ad_code_grid_new_videos' => '<div>new videos ad</div>',
                'ad_code_grid_trend_page' => '<div>trend page ad</div>',
                'ad_code_grid_category_page' => '<div>category page ad</div>',
                'ad_code_grid_subscriptions' => '<div>subscriptions ad</div>',
                'ad_code_grid_channel_videos' => '<div>channel videos ad</div>',
                'ad_code_grid_search_results' => '<div>search results ad</div>',
                'ad_code_grid_playlist_videos' => '<div>playlist videos ad</div>',
                'ad_code_grid_watch_related' => '<div>watch related ad</div>',
                'ad_code_grid_channel_home_featured' => '<div>channel featured ad</div>',
                'ad_code_grid_channel_home_latest' => '<div>channel latest ad</div>',
                'ad_code_sidebar' => '<div>sidebar ad</div>',
                'ad_code_video' => '<div>video ad</div>',
                'ad_code_footer' => '<div>footer ad</div>',
                'ad_code_mobile' => '<div>mobile ad</div>',
                'ad_code_onclick' => '<script>window.onclickAd = true;</script>',
            ])
            ->assertRedirect(route('admin.settings.index', ['tab' => 'monetization']));

        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ads_hide_for_premium',
            'value' => '1',
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_grid_position',
            'value' => '8',
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_preroll_media_url',
            'value' => "https://cdn.example.test/ad-one.mp4\nhttps://cdn.example.test/ad-two.m3u8",
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_grid_max_per_feed',
            'value' => '2',
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_grid_layout',
            'value' => 'wide_row',
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_grid_layout_creator_spotlight',
            'value' => 'wide_row',
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_grid_layout_new_videos',
            'value' => 'grid_card',
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_grid_layout_trend_page',
            'value' => 'wide_row',
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_grid_layout_category_page',
            'value' => 'grid_card',
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_grid_layout_channel_videos',
            'value' => 'wide_row',
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_grid_layout_search_results',
            'value' => 'wide_row',
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_grid_layout_watch_related',
            'value' => 'wide_row',
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_code_grid_new_videos',
            'value' => '<div>new videos ad</div>',
        ]);
        $this->assertDatabaseMissing('settings', [
            'group' => 'monetization',
            'key' => 'ad_code_grid',
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_code_grid_category_page',
            'value' => '<div>category page ad</div>',
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_code_grid_channel_videos',
            'value' => '<div>channel videos ad</div>',
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_code_grid_search_results',
            'value' => '<div>search results ad</div>',
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_code_grid_watch_related',
            'value' => '<div>watch related ad</div>',
        ]);
        $this->assertDatabaseHas('settings', [
            'group' => 'monetization',
            'key' => 'ad_code_mobile',
            'value' => '<div>mobile ad</div>',
        ]);
        $this->assertDatabaseMissing('settings', [
            'group' => 'monetization',
            'key' => 'ad_push_notifications_enabled',
        ]);
        $this->assertDatabaseMissing('settings', [
            'group' => 'monetization',
            'key' => 'ad_code_push_notifications',
        ]);
        $this->assertDatabaseMissing('settings', [
            'group' => 'monetization',
            'key' => 'ad_direct_link_enabled',
        ]);
        $this->assertDatabaseMissing('settings', [
            'group' => 'monetization',
            'key' => 'ad_direct_link_url',
        ]);
        $this->assertDatabaseMissing('settings', [
            'group' => 'monetization',
            'key' => 'ad_code_in_page_push',
        ]);
        $this->assertDatabaseMissing('settings', [
            'group' => 'monetization',
            'key' => 'ad_code_vignette',
        ]);
        $this->assertDatabaseMissing('settings', [
            'group' => 'monetization',
            'key' => 'ad_in_page_push_enabled',
        ]);
        $this->assertDatabaseMissing('settings', [
            'group' => 'monetization',
            'key' => 'ad_vignette_enabled',
        ]);
        $this->assertDatabaseMissing('settings', [
            'group' => 'monetization',
            'key' => 'ad_midroll_enabled',
        ]);
        $this->assertDatabaseMissing('settings', [
            'group' => 'monetization',
            'key' => 'ad_midroll_media_url',
        ]);
        $this->assertDatabaseMissing('settings', [
            'group' => 'monetization',
            'key' => 'ad_midroll_position_percent',
        ]);
        $this->assertFalse(Cache::has('settings.monetization'));
        $this->assertFalse(Cache::has('settings.__all_grouped'));
    }
}
