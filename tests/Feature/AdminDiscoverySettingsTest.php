<?php

namespace Tests\Feature;

use App\Data\NotificationBroadcastSettings;
use App\Data\UploadSettings;
use App\Http\Controllers\Admin\SettingsController;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdaptiveCreatorSpotlightService;
use App\Services\AdaptiveTrendingCollectionsService;
use App\Services\PlaybackProgressSignalService;
use App\Services\SearchHistoryRetentionService;
use App\Services\SmartDiscoveryRailService;
use App\Support\AppearanceSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\Support\CreatesAdminSettingsSchema;
use Tests\TestCase;

class AdminDiscoverySettingsTest extends TestCase
{
    use CreatesAdminSettingsSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withMiddleware();

        $this->refreshAdminSettingsSchema();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->dropAdminSettingsSchema();
        Cache::flush();

        parent::tearDown();
    }

    public function test_discovery_settings_form_renders_every_discovery_field(): void
    {
        $this->withoutVite();

        $response = $this->actingAs($this->userWithAdminFlag(true))
            ->get(route('admin.settings.index', ['tab' => 'discovery']))
            ->assertOk()
            ->assertSee('Smart Discovery Tags')
            ->assertSee('Trending Actors')
            ->assertDontSee('Watch Momentum Digest')
            ->assertDontSee('Viewer Continue Queue');

        foreach (array_keys($this->discoverySettingsSchema()) as $key) {
            $response->assertSee('name="' . $key . '"', false);
        }
    }

    public function test_discovery_settings_route_and_cache_cover_all_feature_groups(): void
    {
        $this->withoutVite();

        $this->assertTrue(Route::has('admin.settings.index'));
        $this->assertTrue(Route::has('admin.settings.update.group'));

        $schema = $this->discoverySettingsSchema();
        $features = [
            'smart_discovery_rail' => ['label' => 'Smart Discovery Tags', 'window' => 'lookback_days'],
            'creator_spotlight' => ['label' => 'Creator Spotlight', 'window' => 'lookback_days'],
            'trending_collections' => ['label' => 'Trending Actors', 'window' => 'lookback_days'],
        ];

        foreach (array_keys($schema) as $key) {
            Cache::forever('setting.' . $key, 'stale');
        }

        Cache::forever('settings.discovery', ['stale' => true]);
        Cache::forever('settings.__all_grouped', ['discovery' => ['stale' => true]]);

        $admin = $this->userWithAdminFlag(true);

        $response = $this->actingAs($admin)
            ->get(route('admin.settings.index', ['tab' => 'discovery']))
            ->assertOk()
            ->assertSee(route('admin.settings.update.group', 'discovery'), false);

        foreach ($features as $prefix => $feature) {
            $this->assertArrayHasKey($prefix . '_enabled', $schema);
            $this->assertArrayHasKey($prefix . '_limit', $schema);
            $this->assertArrayHasKey($prefix . '_' . $feature['window'], $schema);
            $response->assertSee($feature['label']);
        }

        $this->assertArrayNotHasKey('watch_momentum_digest_enabled', $schema);
        $this->assertArrayNotHasKey('viewer_continue_queue_enabled', $schema);
        $response->assertDontSee('Watch Momentum Digest');
        $response->assertDontSee('Viewer Continue Queue');

        $this->assertArrayHasKey('playback_progress_signal_enabled', $schema);
        $this->assertArrayHasKey('playback_progress_signal_min_duration_seconds', $schema);
        $this->assertArrayHasKey('playback_progress_signal_retention_days', $schema);
        $response->assertSee('Playback Progress Signal');

        $this->actingAs($admin)
            ->put(route('admin.settings.update.group', 'discovery'), $this->validDiscoveryPayload())
            ->assertRedirect(route('admin.settings.index', ['tab' => 'discovery']));

        $this->assertFalse(Cache::has('settings.discovery'));
        $this->assertFalse(Cache::has('settings.__all_grouped'));

        foreach (array_keys($schema) as $key) {
            $this->assertFalse(Cache::has('setting.' . $key), 'Per-key cache was not cleared for ' . $key);
        }
    }

    public function test_admin_can_update_discovery_settings_and_clear_settings_cache(): void
    {
        Setting::set('smart_discovery_rail_limit', '4', 'discovery');
        Setting::set('smart_discovery_rail_lookback_days', '14', 'discovery');
        Setting::set('creator_spotlight_limit', '4', 'discovery');
        Setting::set('trending_collections_limit', '4', 'discovery');
        Setting::set('playback_progress_signal_min_duration_seconds', '30', 'discovery');
        Setting::set('playback_progress_signal_retention_days', '90', 'discovery');
        Cache::forever('settings.__all_grouped', ['discovery' => [
            'smart_discovery_rail_limit' => '4',
            'creator_spotlight_limit' => '4',
            'trending_collections_limit' => '4',
            'playback_progress_signal_min_duration_seconds' => '30',
        ]]);
        Cache::forever('settings.discovery', [
            'smart_discovery_rail_limit' => '4',
            'creator_spotlight_limit' => '4',
            'trending_collections_limit' => '4',
            'playback_progress_signal_min_duration_seconds' => '30',
        ]);
        Cache::forever('setting.smart_discovery_rail_limit', '4');
        Cache::forever('setting.creator_spotlight_limit', '4');
        Cache::forever('setting.trending_collections_limit', '4');
        Cache::forever('setting.playback_progress_signal_min_duration_seconds', '30');
        Cache::forever('setting.playback_progress_signal_retention_days', '90');

        $this->actingAs($this->userWithAdminFlag(true))
            ->put(route('admin.settings.update.group', 'discovery'), $this->validDiscoveryPayload())
            ->assertRedirect(route('admin.settings.index', ['tab' => 'discovery']));

        $this->assertFalse(Cache::has('settings.__all_grouped'));
        $this->assertFalse(Cache::has('settings.discovery'));
        $this->assertFalse(Cache::has('setting.smart_discovery_rail_limit'));
        $this->assertFalse(Cache::has('setting.creator_spotlight_limit'));
        $this->assertFalse(Cache::has('setting.trending_collections_limit'));
        $this->assertFalse(Cache::has('setting.playback_progress_signal_min_duration_seconds'));
        $this->assertFalse(Cache::has('setting.playback_progress_signal_retention_days'));
        $this->assertDatabaseHas('settings', [
            'key' => 'smart_discovery_rail_limit',
            'value' => '7',
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'smart_discovery_rail_lookback_days',
            'value' => '21',
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'smart_discovery_rail_videos_enabled',
            'value' => '1',
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'smart_discovery_rail_video_source',
            'value' => 'trending',
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'smart_discovery_rail_video_columns',
            'value' => '5',
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'smart_discovery_rail_title',
            'value' => 'Top Tags Admin',
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'creator_spotlight_limit',
            'value' => '6',
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'creator_spotlight_video_rows',
            'value' => '2',
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'trending_collections_limit',
            'value' => '5',
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'trending_collections_video_columns',
            'value' => '6',
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'trending_collections_lookback_days',
            'value' => '14',
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'trending_collections_title',
            'value' => 'Hot Actors Admin',
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'playback_progress_signal_min_duration_seconds',
            'value' => '45',
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'playback_progress_signal_retention_days',
            'value' => '120',
            'group' => 'discovery',
        ]);
    }

    public function test_discovery_update_clears_every_per_key_cache_entry(): void
    {
        foreach (array_keys($this->discoverySettingsSchema()) as $key) {
            Cache::forever('setting.' . $key, 'stale');
        }

        $this->actingAs($this->userWithAdminFlag(true))
            ->put(route('admin.settings.update.group', 'discovery'), $this->validDiscoveryPayload())
            ->assertRedirect(route('admin.settings.index', ['tab' => 'discovery']));

        foreach (array_keys($this->discoverySettingsSchema()) as $key) {
            $this->assertFalse(Cache::has('setting.' . $key), 'Per-key cache was not cleared for ' . $key);
        }
    }

    public function test_discovery_settings_reject_out_of_range_values(): void
    {
        $this->actingAs($this->userWithAdminFlag(true))
            ->from(route('admin.settings.index', ['tab' => 'discovery']))
            ->put(route('admin.settings.update.group', 'discovery'), $this->outOfRangeDiscoveryPayload())
            ->assertSessionHasErrors([
                'smart_discovery_rail_limit',
                'smart_discovery_rail_lookback_days',
                'smart_discovery_rail_video_source',
                'smart_discovery_rail_video_columns',
                'smart_discovery_rail_video_rows',
                'creator_spotlight_limit',
                'creator_spotlight_lookback_days',
                'creator_spotlight_video_source',
                'creator_spotlight_video_columns',
                'creator_spotlight_video_rows',
                'trending_collections_limit',
                'trending_collections_lookback_days',
                'trending_collections_video_source',
                'trending_collections_video_columns',
                'trending_collections_video_rows',
                'playback_progress_signal_min_duration_seconds',
                'playback_progress_signal_retention_days',
            ]);

        $this->assertDatabaseMissing('settings', [
            'key' => 'smart_discovery_rail_limit',
            'value' => '13',
        ]);
    }

    public function test_non_admin_user_cannot_update_discovery_settings(): void
    {
        Setting::set('creator_spotlight_limit', '4', 'discovery');
        session()->flush();

        $response = $this->actingAs($this->userWithAdminFlag(false))
            ->putJson(route('admin.settings.update.group', 'discovery'), $this->validDiscoveryPayload([
                'smart_discovery_rail_limit' => '5',
                'playback_progress_signal_retention_days' => '180',
            ]));

        $response->assertNotFound();

        $this->assertDatabaseMissing('settings', [
            'key' => 'smart_discovery_rail_limit',
            'value' => '5',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'creator_spotlight_limit',
            'value' => '6',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'trending_collections_limit',
            'value' => '5',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'playback_progress_signal_retention_days',
            'value' => '180',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'creator_spotlight_limit',
            'value' => '4',
            'group' => 'discovery',
        ]);
    }

    private function userWithAdminFlag(bool $isAdmin): User
    {
        $name = $isAdmin ? 'admin' : 'member';

        return tap(new User(), function (User $user) use ($isAdmin, $name) {
            $user->forceFill([
                'username' => $name,
                'slug' => $name,
                'email' => $name . '@example.test',
                'password' => 'password',
                'is_admin' => $isAdmin,
                'email_verified_at' => now(),
            ]);
            $user->save();
        });
    }

    private function discoverySettingsSchema(): array
    {
        $method = new ReflectionMethod(SettingsController::class, 'settingsSchema');
        $method->setAccessible(true);

        return $method->invoke(app(SettingsController::class))['discovery'];
    }

    private function validDiscoveryPayload(array $overrides = []): array
    {
        return array_replace([
            'smart_discovery_rail_enabled' => '1',
            'smart_discovery_rail_limit' => '7',
            'smart_discovery_rail_lookback_days' => '21',
            'smart_discovery_rail_videos_enabled' => '1',
            'smart_discovery_rail_video_source' => 'trending',
            'smart_discovery_rail_video_category_id' => '0',
            'smart_discovery_rail_video_columns' => '5',
            'smart_discovery_rail_video_rows' => '2',
            'smart_discovery_rail_kicker' => 'Smart Tags Admin',
            'smart_discovery_rail_title' => 'Top Tags Admin',
            'smart_discovery_rail_fallback_kicker' => 'Browse Tags Admin',
            'smart_discovery_rail_fallback_title' => 'Fallback Tags Admin',
            'smart_discovery_rail_link_label' => 'Trend Link Admin',
            'creator_spotlight_enabled' => '1',
            'creator_spotlight_limit' => '6',
            'creator_spotlight_lookback_days' => '21',
            'creator_spotlight_videos_enabled' => '1',
            'creator_spotlight_video_source' => 'newest',
            'creator_spotlight_video_category_id' => '0',
            'creator_spotlight_video_columns' => '4',
            'creator_spotlight_video_rows' => '2',
            'creator_spotlight_kicker' => 'Creator Kicker Admin',
            'creator_spotlight_title' => 'Creator Title Admin',
            'creator_spotlight_fallback_kicker' => 'Creator Fallback Kicker Admin',
            'creator_spotlight_fallback_title' => 'Creator Fallback Title Admin',
            'creator_spotlight_link_label' => 'Creator Link Admin',
            'trending_collections_enabled' => '1',
            'trending_collections_limit' => '5',
            'trending_collections_lookback_days' => '14',
            'trending_collections_videos_enabled' => '1',
            'trending_collections_video_source' => 'most_viewed',
            'trending_collections_video_category_id' => '0',
            'trending_collections_video_columns' => '6',
            'trending_collections_video_rows' => '1',
            'trending_collections_kicker' => 'Actor Kicker Admin',
            'trending_collections_title' => 'Hot Actors Admin',
            'trending_collections_fallback_kicker' => 'Actor Fallback Kicker Admin',
            'trending_collections_fallback_title' => 'Actor Fallback Title Admin',
            'trending_collections_link_label' => 'Actor Link Admin',
            'playback_progress_signal_enabled' => '1',
            'playback_progress_signal_min_duration_seconds' => '45',
            'playback_progress_signal_retention_days' => '120',
        ], $overrides);
    }

    private function outOfRangeDiscoveryPayload(): array
    {
        return $this->validDiscoveryPayload([
            'smart_discovery_rail_limit' => (string) (SmartDiscoveryRailService::MAX_LIMIT + 1),
            'smart_discovery_rail_lookback_days' => (string) (SmartDiscoveryRailService::MAX_LOOKBACK_DAYS + 1),
            'smart_discovery_rail_video_source' => 'invalid-source',
            'smart_discovery_rail_video_columns' => (string) (AppearanceSettings::HOME_FEED_MIN_COLUMNS - 1),
            'smart_discovery_rail_video_rows' => (string) (AppearanceSettings::HOME_FEED_MAX_ROWS + 1),
            'creator_spotlight_limit' => (string) (AdaptiveCreatorSpotlightService::MAX_LIMIT + 1),
            'creator_spotlight_lookback_days' => (string) (AdaptiveCreatorSpotlightService::MAX_LOOKBACK_DAYS + 1),
            'creator_spotlight_video_source' => 'invalid-source',
            'creator_spotlight_video_columns' => (string) (AppearanceSettings::HOME_FEED_MIN_COLUMNS - 1),
            'creator_spotlight_video_rows' => (string) (AppearanceSettings::HOME_FEED_MAX_ROWS + 1),
            'trending_collections_limit' => (string) (AdaptiveTrendingCollectionsService::MAX_LIMIT + 1),
            'trending_collections_lookback_days' => (string) (AdaptiveTrendingCollectionsService::MAX_LOOKBACK_DAYS + 1),
            'trending_collections_video_source' => 'invalid-source',
            'trending_collections_video_columns' => (string) (AppearanceSettings::HOME_FEED_MIN_COLUMNS - 1),
            'trending_collections_video_rows' => (string) (AppearanceSettings::HOME_FEED_MAX_ROWS + 1),
            'playback_progress_signal_min_duration_seconds' => (string) (PlaybackProgressSignalService::MAX_MIN_DURATION_SECONDS + 1),
            'playback_progress_signal_retention_days' => (string) (PlaybackProgressSignalService::MAX_RETENTION_DAYS + 1),
        ]);
    }
}
