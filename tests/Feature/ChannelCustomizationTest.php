<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Http\Controllers\Admin\SettingsController;
use App\Models\Playlist;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Services\CreatorQualityPulseService;
use App\Services\CreatorRecoveryChecklistService;
use App\Services\StudioHealthAlertsService;
use App\Support\ChannelCustomizationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

class ChannelCustomizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_settings_form_renders_channel_customization_controls(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.settings.index', ['tab' => 'users']))
            ->assertOk()
            ->assertSee('Channel customization')
            ->assertSee('Layout Choices');

        foreach (array_keys(ChannelCustomizationSettings::defaults()) as $key) {
            $response->assertSee('name="' . $key . '"', false);
        }
    }

    public function test_admin_can_update_channel_customization_settings_and_clear_cache(): void
    {
        $schema = $this->usersSettingsSchema();

        foreach (array_keys($schema) as $key) {
            Cache::forever('setting.' . $key, 'stale');
        }
        Cache::forever('settings.users', ['stale' => true]);
        Cache::forever('settings.__all_grouped', ['users' => ['stale' => true]]);

        $payload = $this->validUsersPayload([
            'channel_default_layout' => 'poster',
            'channel_default_theme' => 'ocean',
            'channel_default_accent' => 'custom',
            'channel_default_custom_accent' => '#12abcd',
            'channel_default_motion' => 'sweep',
            'channel_default_featured_layout' => 'compact',
            'channel_default_featured_columns' => '4',
            'channel_default_featured_rows' => '2',
            'channel_default_latest_uploads_layout' => 'showcase',
            'channel_default_latest_uploads_columns' => '2',
            'channel_default_latest_uploads_rows' => '2',
            'channel_default_playlists_layout' => 'grid',
            'channel_default_playlists_columns' => '2',
            'channel_default_playlists_rows' => '2',
            'channel_default_playlist_video_columns' => '2',
            'channel_default_playlist_video_rows' => '1',
            'channel_tagline_max_length' => '180',
        ]);

        unset($payload['channel_customization_motion_enabled']);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.settings.update.group', 'users'), $payload)
            ->assertRedirect(route('admin.settings.index', ['tab' => 'users']));

        $this->assertDatabaseHas('settings', [
            'key' => 'channel_default_layout',
            'value' => 'poster',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'channel_default_custom_accent',
            'value' => '#12abcd',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'channel_customization_motion_enabled',
            'value' => '0',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'channel_default_featured_layout',
            'value' => 'compact',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'channel_default_featured_columns',
            'value' => '4',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'channel_default_latest_uploads_layout',
            'value' => 'showcase',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'channel_default_playlists_layout',
            'value' => 'grid',
            'group' => 'users',
        ]);

        $this->assertFalse(Cache::has('settings.users'));
        $this->assertFalse(Cache::has('settings.__all_grouped'));
        $this->assertFalse(Cache::has('setting.channel_default_layout'));
    }

    public function test_user_can_save_channel_customization_choices(): void
    {
        config([
            'site.channel_customization_enabled' => true,
            'site.channel_customization_motion_enabled' => true,
            'site.channel_customization_custom_accent_enabled' => true,
            'site.channel_customization_featured_layout_enabled' => true,
            'site.channel_customization_featured_sizing_enabled' => true,
            'site.channel_customization_latest_layout_enabled' => true,
            'site.channel_customization_latest_sizing_enabled' => true,
            'site.channel_customization_playlists_layout_enabled' => true,
            'site.channel_customization_playlists_sizing_enabled' => true,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('user.edit'))
            ->put(route('user.update'), [
                'channel_customization' => [
                    'layout' => 'split',
                    'theme' => 'forest',
                    'accent' => 'custom',
                    'custom_accent' => '#123abc',
                    'surface' => 'outlined',
                    'banner_treatment' => 'mosaic',
                    'avatar_frame' => 'badge',
                    'motion' => 'float',
                    'pattern' => 'waves',
                    'header_shape' => 'angled',
                    'stats_style' => 'bar',
                    'video_layout' => 'list',
                    'featured_mode' => 'popular',
                    'featured_layout' => 'rail',
                    'featured_columns' => '4',
                    'featured_rows' => '2',
                    'latest_uploads_layout' => 'showcase',
                    'latest_uploads_columns' => '2',
                    'latest_uploads_rows' => '3',
                    'playlists_layout' => 'grid',
                    'playlists_columns' => '2',
                    'playlists_rows' => '2',
                    'playlist_video_columns' => '2',
                    'playlist_video_rows' => '1',
                    'density' => 'spacious',
                    'tagline' => 'A channel made for testing customization.',
                    'cta_label' => 'Visit studio',
                    'cta_url' => 'https://creator.example.test',
                    'show_stats' => '1',
                    'show_featured_video' => '1',
                    'show_latest_uploads' => '1',
                    'show_playlists' => '1',
                    'show_about_panel' => '1',
                    'show_social_links' => '1',
                    'show_upload_rhythm' => '1',
                    'show_subscribe_cta' => '1',
                    'dedupe_home_sections' => '0',
                ],
            ])
            ->assertRedirect(route('user.edit'));

        $customization = $user->refresh()->channel_customization;

        $this->assertSame('split', $customization['layout']);
        $this->assertSame('forest', $customization['theme']);
        $this->assertSame('#123abc', $customization['custom_accent']);
        $this->assertSame('rail', $customization['featured_layout']);
        $this->assertSame('4', $customization['featured_columns']);
        $this->assertSame('2', $customization['featured_rows']);
        $this->assertSame('showcase', $customization['latest_uploads_layout']);
        $this->assertSame('2', $customization['latest_uploads_columns']);
        $this->assertSame('3', $customization['latest_uploads_rows']);
        $this->assertSame('grid', $customization['playlists_layout']);
        $this->assertSame('2', $customization['playlists_columns']);
        $this->assertSame('2', $customization['playlist_video_columns']);
        $this->assertSame('Visit studio', $customization['cta_label']);
        $this->assertSame('0', $customization['dedupe_home_sections']);
    }

    public function test_invalid_channel_customization_payload_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('user.edit'))
            ->put(route('user.update'), [
                'channel_customization' => [
                    'layout' => '"><script>alert(1)</script>',
                    'featured_layout' => '"><script>alert(1)</script>',
                    'featured_columns' => '9',
                    'featured_rows' => '0',
                    'latest_uploads_layout' => '"><script>alert(1)</script>',
                    'latest_uploads_columns' => '9',
                    'latest_uploads_rows' => '0',
                    'playlists_layout' => '"><script>alert(1)</script>',
                    'playlists_columns' => '9',
                    'playlists_rows' => '0',
                    'playlist_video_columns' => '9',
                    'playlist_video_rows' => '0',
                    'cta_url' => 'javascript:alert(1)',
                ],
            ])
            ->assertRedirect(route('user.edit'))
            ->assertSessionHasErrors([
                'channel_customization.layout',
                'channel_customization.featured_layout',
                'channel_customization.featured_columns',
                'channel_customization.featured_rows',
                'channel_customization.latest_uploads_layout',
                'channel_customization.latest_uploads_columns',
                'channel_customization.latest_uploads_rows',
                'channel_customization.playlists_layout',
                'channel_customization.playlists_columns',
                'channel_customization.playlists_rows',
                'channel_customization.playlist_video_columns',
                'channel_customization.playlist_video_rows',
                'channel_customization.cta_url',
            ]);
    }

    public function test_public_channel_page_reflects_saved_customization(): void
    {
        $this->withoutVite();

        config([
            'site.channel_customization_enabled' => true,
            'site.channel_customization_effects_enabled' => true,
            'site.channel_customization_motion_enabled' => true,
        ]);

        $user = User::factory()->create([
            'website' => 'creator.example.test',
            'channel_customization' => [
                'layout' => 'poster',
                'theme' => 'ocean',
                'accent' => 'custom',
                'custom_accent' => '#123abc',
                'surface' => 'outlined',
                'banner_treatment' => 'split',
                'avatar_frame' => 'badge',
                'motion' => 'sweep',
                'pattern' => 'grid',
                'header_shape' => 'angled',
                'stats_style' => 'bar',
                'video_layout' => 'list',
                'featured_mode' => 'popular',
                'featured_layout' => 'compact',
                'featured_columns' => '1',
                'featured_rows' => '1',
                'latest_uploads_layout' => 'showcase',
                'latest_uploads_columns' => '1',
                'latest_uploads_rows' => '1',
                'playlists_layout' => 'grid',
                'playlists_columns' => '1',
                'playlists_rows' => '1',
                'playlist_video_columns' => '1',
                'playlist_video_rows' => '1',
                'density' => 'spacious',
                'tagline' => 'Creator channel tagline from settings.',
                'cta_label' => 'Visit studio',
                'cta_url' => 'https://creator.example.test',
                'show_stats' => '1',
                'show_featured_video' => '1',
                'show_latest_uploads' => '1',
                'show_playlists' => '1',
                'show_about_panel' => '1',
                'show_social_links' => '1',
                'show_upload_rhythm' => '1',
                'show_subscribe_cta' => '1',
                'dedupe_home_sections' => '1',
            ],
        ]);

        $popularVideo = Video::factory()->for($user)->withStatus(VideoStatus::PUBLIC)->create([
            'title' => 'Popular customized channel video',
            'views' => 99,
            'published_at' => now()->subDay(),
            'uploaded_at' => now()->subDay(),
        ]);
        $newerVideo = Video::factory()->for($user)->withStatus(VideoStatus::PUBLIC)->create([
            'title' => 'Newer customized channel video',
            'views' => 5,
            'published_at' => now()->subHour(),
            'uploaded_at' => now()->subHour(),
        ]);
        $playlist = Playlist::factory()->for($user)->create([
            'title' => 'Customized playlist rail',
            'status' => PlaylistStatus::PUBLIC,
            'updated_at' => now(),
        ]);
        $playlist->videos()->attach($newerVideo->id, [
            'position' => 1,
            'added_at' => now(),
        ]);
        $playlist->videos()->attach($popularVideo->id, [
            'position' => 2,
            'added_at' => now()->subMinute(),
        ]);

        $response = $this->get(route('user.show', $user))
            ->assertOk()
            ->assertSee('Creator channel tagline from settings.')
            ->assertSee('Visit studio')
            ->assertSee('Popular customized channel video')
            ->assertSee('Upload rhythm')
            ->assertSee('data-channel-layout="poster"', false)
            ->assertSee('data-channel-theme="ocean"', false)
            ->assertSee('data-channel-accent="custom"', false)
            ->assertSee('data-channel-motion="sweep"', false)
            ->assertSee('data-channel-video-layout="list"', false)
            ->assertSee('data-channel-featured-layout="compact"', false)
            ->assertSee('data-channel-featured-columns="1"', false)
            ->assertSee('data-channel-latest-layout="showcase"', false)
            ->assertSee('data-channel-latest-columns="1"', false)
            ->assertSee('data-channel-playlists-layout="grid"', false)
            ->assertSee('data-channel-playlist-video-columns="1"', false)
            ->assertSee('Customized playlist rail')
            ->assertSee('Featured videos');

        $this->assertStringContainsString('--channel-accent:#123abc;', $response->getContent());
        $featuredHtml = $this->extractFeaturedSection($response->getContent());
        $this->assertStringContainsString('Popular customized channel video', $featuredHtml);
        $this->assertStringNotContainsString('Newer customized channel video', $featuredHtml);
        $latestHtml = $this->extractSection($response->getContent(), 'avd-channel-latest');
        $this->assertStringContainsString('Newer customized channel video', $latestHtml);
        $this->assertStringNotContainsString('Popular customized channel video', $latestHtml);
    }

    public function test_channel_home_avoids_repeating_featured_videos_in_latest_uploads(): void
    {
        $this->withoutVite();

        config(['site.channel_customization_enabled' => true]);

        $user = User::factory()->create([
            'channel_customization' => [
                'featured_mode' => 'latest',
                'featured_layout' => 'compact',
                'featured_columns' => '3',
                'featured_rows' => '1',
                'latest_uploads_layout' => 'grid',
                'latest_uploads_columns' => '3',
                'latest_uploads_rows' => '1',
                'show_featured_video' => '1',
                'show_latest_uploads' => '1',
                'dedupe_home_sections' => '1',
            ],
        ]);

        foreach (['First imported creator video', 'Second imported creator video', 'Third imported creator video'] as $index => $title) {
            Video::factory()->for($user)->withStatus(VideoStatus::PUBLIC)->create([
                'title' => $title,
                'published_at' => now()->subMinutes($index + 1),
                'uploaded_at' => now()->subMinutes($index + 1),
            ]);
        }

        $html = $this->get(route('user.show', $user))
            ->assertOk()
            ->assertSee('Featured videos')
            ->getContent();

        $this->assertStringNotContainsString('avd-channel-latest', $html);
    }

    public function test_disabled_channel_customization_uses_defaults_and_ignores_saved_style(): void
    {
        $this->withoutVite();

        config(['site.channel_customization_enabled' => false]);

        $user = User::factory()->create([
            'channel_customization' => [
                'layout' => 'poster',
                'tagline' => 'Hidden custom tagline',
            ],
        ]);

        $this->get(route('user.show', $user))
            ->assertOk()
            ->assertSee('data-channel-layout="spotlight"', false)
            ->assertDontSee('Hidden custom tagline');
    }

    private function usersSettingsSchema(): array
    {
        $method = new ReflectionMethod(SettingsController::class, 'settingsSchema');
        $method->setAccessible(true);

        return $method->invoke(app(SettingsController::class))['users'];
    }

    private function validUsersPayload(array $overrides = []): array
    {
        $quality = CreatorQualityPulseService::settingsDefaults();
        $health = StudioHealthAlertsService::settingsDefaults();
        $recovery = CreatorRecoveryChecklistService::settingsDefaults();

        return array_replace([
            'max_register_attempts' => '3',
            'max_avatar_size' => '4096',
            'max_banner_size' => '8192',
            'allow_account_deletion' => '1',
            'creator_quality_pulse_enabled' => '1',
            'creator_quality_pulse_limit' => (string) $quality['limit'],
            'creator_quality_pulse_lookback_days' => (string) $quality['lookback_days'],
            'studio_health_alerts_enabled' => '1',
            'studio_health_alerts_limit' => (string) $health['limit'],
            'studio_health_alerts_lookback_days' => (string) $health['lookback_days'],
            'creator_recovery_checklist_enabled' => '1',
            'creator_recovery_checklist_limit' => (string) $recovery['limit'],
            'creator_recovery_checklist_inactivity_days' => (string) $recovery['inactivity_days'],
        ] + ChannelCustomizationSettings::defaults(), $overrides);
    }

    private function extractFeaturedSection(string $html): string
    {
        return $this->extractSection($html, 'avd-channel-featured');
    }

    private function extractSection(string $html, string $className): string
    {
        $start = strpos($html, $className);
        $this->assertNotFalse($start);

        $end = strpos($html, '</section>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }
}
