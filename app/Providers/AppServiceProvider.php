<?php

namespace App\Providers;

use App\Data\NotificationBroadcastSettings;
use App\Data\UploadSettings;
use App\Enums\VideoStatus;
use App\Models\Setting;
use App\Queue\HostSafeWorker;
use App\Services\AdaptiveCreatorSpotlightService;
use App\Services\AdaptiveTrendingCollectionsService;
use App\Services\BrevoService;
use App\Services\CreatorQualityPulseService;
use App\Services\CreatorRecoveryChecklistService;
use App\Services\PlaybackProgressSignalService;
use App\Services\SearchHistoryRetentionService;
use App\Services\SeoService;
use App\Services\SmartDiscoveryRailService;
use App\Services\StudioHealthAlertsService;
use App\Services\ViewHistoryRetentionService;
use App\Services\YoutubeService;
use App\Support\AppearanceSettings;
use App\Support\ChannelCustomizationSettings;
use App\Support\CorsConfig;
use App\Support\HomeWidgetVideoSettings;
use App\Support\PremiumPageSettings;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Load locale helper functions
        require_once app_path('Helpers/locale.php');

        $this->app->singleton(YoutubeService::class, fn() => new YoutubeService(config('services.youtube.api_key')));
        $this->app->singleton(BrevoService::class, fn() => new BrevoService(config('services.brevo.api_key')));

        $this->registerHostSafeQueueWorker();
    }

    /**
     * Replace the framework queue worker with one that tolerates pcntl_* being listed in
     * php.ini disable_functions (default on aaPanel / HestiaCP / CyberPanel). Mirrors
     * Illuminate\Queue\QueueServiceProvider::registerWorker() apart from the class.
     */
    protected function registerHostSafeQueueWorker(): void
    {
        // extend() rather than singleton(): QueueServiceProvider is DEFERRED, so its own
        // singleton('queue.worker') is registered later (when queue:work resolves it) and
        // would win. An extender is applied on top of whatever binding resolves, so the
        // host-safe worker is what the queue commands actually receive.
        $this->app->extend('queue.worker', function ($worker, $app) {
            if ($worker instanceof HostSafeWorker) {
                return $worker;
            }

            $isDownForMaintenance = function () use ($app) {
                return $app->isDownForMaintenance();
            };

            $resetScope = function () use ($app) {
                if (method_exists($app['log'], 'flushSharedContext')) {
                    $app['log']->flushSharedContext();
                }

                if (method_exists($app['log'], 'withoutContext')) {
                    $app['log']->withoutContext();
                }

                if (method_exists($app['db'], 'getConnections')) {
                    foreach ($app['db']->getConnections() as $connection) {
                        $connection->resetTotalQueryDuration();
                        $connection->allowQueryDurationHandlersToRunAgain();
                    }
                }

                $app->forgetScopedInstances();

                \Illuminate\Support\Facades\Facade::clearResolvedInstances();

                memory_reset_peak_usage();
            };

            return new HostSafeWorker(
                $app['queue'],
                $app['events'],
                $app[ExceptionHandler::class],
                $isDownForMaintenance,
                $resetScope
            );
        });
    }


    public function boot(): void
    {
        Paginator::useBootstrapFive();
        Cashier::calculateTaxes();

        if ($this->app->isLocal() && class_exists(\Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class)) {
            $this->app->register(\Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class);
        }

        $this->applyDbSettings();
        $this->resolveAppearanceFormValues();
    }

    /**
     * The old settings page edits what the site renders: absent appearance keys show their fallback default (v1
     * until "Finish update") and colours the owner never set show the colour style's, so a plain Save keeps the look.
     */
    protected function resolveAppearanceFormValues(): void
    {
        View::composer('admin.settings.index', static function (\Illuminate\View\View $view): void {
            $settings = $view->getData()['settings'] ?? null;

            if (is_array($settings) && is_array($settings['appearance'] ?? null)) {
                $settings['appearance'] = AppearanceSettings::formValues($settings['appearance']);
                $view->with('settings', $settings);
            }
        });
    }

    /**
     * Read ALL admin settings from the database and wire them into the
     * running application so every saved setting actually has a real effect.
     */
    protected function applyDbSettings(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        try {
            // Single query/cache-read loads ALL settings at once
            $all = Setting::getAllGrouped();

            $general      = $all['general']      ?? [];
            $uploads      = $all['uploads']       ?? [];
            $comments     = $all['comments']      ?? [];
            $appearance   = $all['appearance']    ?? [];
            $seo          = $all['seo']           ?? [];
            $email        = $all['email']         ?? [];
            $users        = $all['users']         ?? [];
            $monetization = $all['monetization']  ?? [];
            $localization = $all['localization']   ?? [];
            $notifications= $all['notifications'] ?? [];
            $validation   = $all['validation']    ?? [];
            $planlimits   = $all['planlimits']    ?? [];
            $api          = $all['api']           ?? [];
            $cdn          = $all['cdn']           ?? [];
            $sociallinks  = $all['sociallinks']   ?? [];
            $security     = $all['security']      ?? [];
            $pages        = $all['pages']         ?? [];
            $discovery    = $all['discovery']     ?? [];
            $hasValue = static fn (array $settings, string $key): bool => array_key_exists($key, $settings)
                && $settings[$key] !== null
                && $settings[$key] !== '';

            // ── GENERAL ───────────────────────────────────────────────────────
            if (!empty($general['site_name'])) {
                Config::set('app.name', $general['site_name']);
            }
            Config::set('site.contact_email',    $general['contact_email']    ?? '');
            Config::set('site.admin_email',      $general['contact_email']    ?? config('mail.from.address', ''));
            Config::set('site.site_description', $general['site_description'] ?? '');
            Config::set('site.site_keywords',    $general['site_keywords']    ?? '');
            Config::set('site.per_page_videos',  (int)($general['per_page_videos']  ?? 24));
            Config::set('site.per_page_comments',(int)($general['per_page_comments'] ?? 20));
            Config::set('site.per_page_users',   (int)($general['per_page_users']   ?? 15));
            Config::set('site.per_page_search',  (int)($general['per_page_search']  ?? 20));
            Config::set('site.per_page_activity',        (int)($general['per_page_activity']        ?? 12));
            Config::set('site.profile_videos_limit',     (int)($general['profile_videos_limit']     ?? 8));
            Config::set('site.profile_playlists_limit',  (int)($general['profile_playlists_limit']  ?? 6));
            Config::set('site.dashboard_widget_limit',   (int)($general['dashboard_widget_limit']   ?? 5));
            Config::set('site.discover_users_limit',     (int)($general['discover_users_limit']     ?? 100));
            Config::set('site.search_suggestions_limit', (int)($general['search_suggestions_limit'] ?? 10));
            Config::set('site.suggested_videos_count',   (int)($general['suggested_videos_count']   ?? 9));
            $searchHistoryDefaults = SearchHistoryRetentionService::settingsDefaults();
            Config::set('site.search_history_retention_days', (int)($general['search_history_retention_days'] ?? $searchHistoryDefaults['retention_days']));
            Config::set('site.search_history_max_rows', (int)($general['search_history_max_rows'] ?? $searchHistoryDefaults['max_rows']));
            Config::set('site.search_history_cleanup_interval_minutes', (int)($general['search_history_cleanup_interval_minutes'] ?? $searchHistoryDefaults['cleanup_interval_minutes']));
            Config::set('site.registration_enabled',        ($general['registration_enabled']        ?? '1') === '1');
            Config::set('site.allow_social_login',          ($general['allow_social_login']          ?? '1') === '1');
            Config::set('site.allow_uploads',               ($general['allow_uploads']               ?? '1') === '1');
            Config::set('site.allow_embedding',             ($general['allow_embedding']             ?? '1') === '1');
            Config::set('site.show_trending',               ($general['show_trending']               ?? '1') === '1');
            Config::set('site.show_history',                ($general['show_history']                ?? '1') === '1');
            Config::set('site.allow_shorts',                ($general['allow_shorts']                ?? '1') === '1');
            Config::set('site.email_verification_required', ($users['email_verification_required'] ?? $general['email_verification_required'] ?? '0') === '1');

            // â”€â”€ EMAIL â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            $mailMailer = (string) ($email['mail_mailer'] ?? config('mail.default', 'smtp'));
            if (array_key_exists($mailMailer, config('mail.mailers', []))) {
                Config::set('mail.default', $mailMailer);
            }
            Config::set('mail.mailers.smtp.host', $email['smtp_host'] ?? config('mail.mailers.smtp.host'));
            Config::set('mail.mailers.smtp.port', (int)($email['smtp_port'] ?? config('mail.mailers.smtp.port', 587)));
            Config::set('mail.mailers.smtp.username', $email['smtp_username'] ?? config('mail.mailers.smtp.username'));
            Config::set('mail.mailers.smtp.password', $email['smtp_password'] ?? config('mail.mailers.smtp.password'));
            Config::set('mail.mailers.smtp.encryption', ($email['smtp_encryption'] ?? config('mail.mailers.smtp.encryption')) ?: null);
            Config::set('mail.from.address', $email['mail_from_address'] ?? config('mail.from.address'));
            Config::set('mail.from.name', $email['mail_from_name'] ?? config('mail.from.name'));
            Config::set('mail.support_mail', $email['support_email'] ?? config('mail.support_mail'));
            Config::set('mail.server_mail', $email['server_email'] ?? config('mail.server_mail'));

            $this->applyDiscoveryFeatureSettings($discovery, 'smart_discovery_rail', SmartDiscoveryRailService::settingsDefaults());
            $this->applyDiscoveryFeatureSettings($discovery, 'creator_spotlight', AdaptiveCreatorSpotlightService::settingsDefaults());
            $this->applyDiscoveryFeatureSettings($discovery, 'trending_collections', AdaptiveTrendingCollectionsService::settingsDefaults());
            foreach (HomeWidgetVideoSettings::booleanKeys() as $key) {
                Config::set('site.' . $key, HomeWidgetVideoSettings::bool($discovery, $key));
            }
            foreach (HomeWidgetVideoSettings::textKeys() as $key) {
                Config::set('site.' . $key, HomeWidgetVideoSettings::text($discovery, $key));
            }
            $playbackProgressDefaults = PlaybackProgressSignalService::settingsDefaults();
            Config::set('site.playback_progress_signal_enabled', ($discovery['playback_progress_signal_enabled'] ?? $playbackProgressDefaults['enabled']) === '1');
            Config::set('site.playback_progress_signal_min_duration_seconds', (int) ($discovery['playback_progress_signal_min_duration_seconds'] ?? $playbackProgressDefaults['min_duration_seconds']));
            Config::set('site.playback_progress_signal_retention_days', (int) ($discovery['playback_progress_signal_retention_days'] ?? $playbackProgressDefaults['retention_days']));

            // ── UPLOADS ───────────────────────────────────────────────────────
            $uploadSettings = UploadSettings::fromSettingsArray($uploads);
            Config::set('site.upload_max_duration',      (int)($uploads['upload_max_duration']     ?? 3600));
            Config::set('site.upload_allowed_formats',   strtolower((string) ($uploads['upload_allowed_formats'] ?? 'mp4,webm,avi,mov,mkv')));
            $defaultVideoStatus = $this->normalizeVideoStatusSetting($uploads['default_video_status'] ?? VideoStatus::PUBLIC->value);
            Config::set('site.default_video_status',     $defaultVideoStatus);
            Config::set('site.default_video_language',   $uploads['default_video_language']        ?? 'en');
            Config::set('site.allow_custom_thumbnails',  ($uploads['allow_custom_thumbnails']      ?? '1') === '1');
            Config::set('site.max_thumbnail_size',        $uploadSettings->maxThumbnailSizeKb);
            Config::set('site.upload18_chunk_size_mb',    $uploadSettings->chunkSizeMb);
            Config::set('site.upload18_thumbnail_count',  $uploadSettings->thumbnailCount);
            Config::set('site.upload_chunk_size',         $uploadSettings->chunkSizeMb);
            Config::set('site.thumbnails_count',          $uploadSettings->thumbnailCount);
            Config::set('site.thumbnail_width',           (int) ($uploads['thumbnail_width']  ?? 640));
            Config::set('site.thumbnail_height',          (int) ($uploads['thumbnail_height'] ?? 360));
            // Upload18 API (read by VideoController::getUploadConfig)
            Config::set('services.upload18.api_key',       $uploads['upload18_api_key']       ?? config('services.upload18.api_key', ''));
            Config::set('services.upload18.api_url',       $uploads['upload18_api_url']       ?? config('services.upload18.api_url', 'https://upload18.org/api'));
            Config::set('services.upload18.default_cid',   $uploads['upload18_default_cid']   ?? config('services.upload18.default_cid', '15'));
            Config::set('services.upload18.default_fid',   $uploads['upload18_default_fid']   ?? config('services.upload18.default_fid', '18'));
            Config::set('services.upload18.default_mycid', $uploads['upload18_default_mycid'] ?? config('services.upload18.default_mycid', '0'));

            // ── COMMENTS ─────────────────────────────────────────────────────
            Config::set('site.comments_enabled',           ($comments['comments_enabled']           ?? '1') === '1');
            Config::set('site.allow_replies',              ($comments['allow_replies']              ?? '1') === '1');
            Config::set('site.view_cooldown_minutes',      (int)($comments['view_cooldown_minutes'] ?? 5));
            $viewHistoryDefaults = ViewHistoryRetentionService::settingsDefaults();
            $viewLogMode = (string) ($comments['view_log_mode'] ?? $viewHistoryDefaults['log_mode']);
            if (! array_key_exists($viewLogMode, ViewHistoryRetentionService::logModes())) {
                $viewLogMode = (string) $viewHistoryDefaults['log_mode'];
            }
            Config::set('site.view_log_mode',               $viewLogMode);
            Config::set('site.view_daily_stats_enabled',    ($comments['view_daily_stats_enabled'] ?? $viewHistoryDefaults['daily_stats_enabled']) === '1');
            Config::set('site.view_history_retention_days', (int) ($comments['view_history_retention_days'] ?? $viewHistoryDefaults['retention_days']));
            Config::set('site.per_page_replies',           (int)($comments['per_page_replies']      ?? 10));
            Config::set('site.comment_preview_length',     (int)($comments['comment_preview_length']?? 500));

            // ── USERS ────────────────────────────────────────────────────────
            Config::set('site.max_videos_per_user',    (int)($users['max_videos_per_user']    ?? 0));
            Config::set('site.max_playlists_per_user', (int)($users['max_playlists_per_user'] ?? 50));
            Config::set('site.max_storage_per_user',   (int)($users['max_storage_per_user']   ?? 0));
            Config::set('site.username_min_length',    (int)($users['username_min_length']    ?? 3));
            Config::set('site.username_max_length',    (int)($users['username_max_length']    ?? 50));
            Config::set('site.max_login_attempts',     (int)($users['max_login_attempts']     ?? 5));
            Config::set('site.lockout_minutes',        (int)($users['lockout_minutes']        ?? 15));
            Config::set('site.allow_avatar_upload',    ($users['allow_avatar_upload']         ?? '1') === '1');
            Config::set('site.allow_banner_upload',    ($users['allow_banner_upload']         ?? '1') === '1');
            Config::set('site.allow_account_deletion', ($users['allow_account_deletion']      ?? '1') === '1');
            Config::set('site.allow_bio_edit',         ($users['allow_bio_edit']              ?? '1') === '1');
            Config::set('site.allow_username_change',  ($users['allow_username_change']       ?? '1') === '1');
            Config::set('site.show_subscriber_count',  ($users['show_subscriber_count']       ?? '1') === '1');
            Config::set('site.max_avatar_size',        (int)($users['max_avatar_size']        ?? 2048));
            Config::set('site.avatar_width',           (int)($users['avatar_width']           ?? 200));
            Config::set('site.max_banner_size',        (int)($users['max_banner_size']        ?? 5120));
            Config::set('site.banner_width',           (int)($users['banner_width']           ?? 1280));
            Config::set('site.reserved_usernames',     $users['reserved_usernames']           ?? 'admin,moderator,system,support');
            Config::set('site.max_register_attempts',  (int)($users['max_register_attempts']  ?? 1));
            $creatorQualityPulseDefaults = CreatorQualityPulseService::settingsDefaults();
            Config::set('site.creator_quality_pulse_enabled', ($users['creator_quality_pulse_enabled'] ?? $creatorQualityPulseDefaults['enabled']) === '1');
            Config::set('site.creator_quality_pulse_limit', (int)($users['creator_quality_pulse_limit'] ?? $creatorQualityPulseDefaults['limit']));
            Config::set('site.creator_quality_pulse_lookback_days', (int)($users['creator_quality_pulse_lookback_days'] ?? $creatorQualityPulseDefaults['lookback_days']));
            $studioHealthAlertsDefaults = StudioHealthAlertsService::settingsDefaults();
            Config::set('site.studio_health_alerts_enabled', ($users['studio_health_alerts_enabled'] ?? $studioHealthAlertsDefaults['enabled']) === '1');
            Config::set('site.studio_health_alerts_limit', (int)($users['studio_health_alerts_limit'] ?? $studioHealthAlertsDefaults['limit']));
            Config::set('site.studio_health_alerts_lookback_days', (int)($users['studio_health_alerts_lookback_days'] ?? $studioHealthAlertsDefaults['lookback_days']));
            $creatorRecoveryChecklistDefaults = CreatorRecoveryChecklistService::settingsDefaults();
            Config::set('site.creator_recovery_checklist_enabled', ($users['creator_recovery_checklist_enabled'] ?? $creatorRecoveryChecklistDefaults['enabled']) === '1');
            Config::set('site.creator_recovery_checklist_limit', (int)($users['creator_recovery_checklist_limit'] ?? $creatorRecoveryChecklistDefaults['limit']));
            Config::set('site.creator_recovery_checklist_inactivity_days', (int)($users['creator_recovery_checklist_inactivity_days'] ?? $creatorRecoveryChecklistDefaults['inactivity_days']));
            foreach (ChannelCustomizationSettings::booleanKeys() as $key) {
                Config::set('site.' . $key, ChannelCustomizationSettings::bool($users, $key));
            }
            foreach (ChannelCustomizationSettings::textKeys() as $key) {
                Config::set('site.' . $key, ChannelCustomizationSettings::text($users, $key));
            }

            // ── MONETIZATION ──────────────────────────────────────────────────
            Config::set('site.premium_enabled',      ($monetization['premium_enabled']      ?? '1') === '1');
            Config::set('site.stripe_enabled',       ($monetization['stripe_enabled']       ?? '1') === '1');
            Config::set('site.paypal_enabled',       ($monetization['paypal_enabled']       ?? '1') === '1');
            Config::set('site.ads_enabled',          ($monetization['ads_enabled']          ?? '0') === '1');
            Config::set('site.ads_hide_for_premium', ($monetization['ads_hide_for_premium'] ?? '1') === '1');
            Config::set('site.show_premium_badge',   ($monetization['show_premium_badge']   ?? '1') === '1');
            Config::set('site.ad_onclick_enabled',   ($monetization['ad_onclick_enabled']   ?? '0') === '1');
            Config::set('site.ad_code_header',       $monetization['ad_code_header']   ?? '');
            $gridAdContexts = [
                'smart_discovery',
                'creator_spotlight',
                'trending_actors',
                'new_videos',
                'trend_page',
                'category_page',
                'subscriptions',
                'channel_videos',
                'search_results',
                'playlist_videos',
                'watch_related',
                'channel_home_featured',
                'channel_home_latest',
            ];
            foreach ($gridAdContexts as $adContext) {
                $settingKey = 'ad_code_grid_' . $adContext;
                Config::set('site.' . $settingKey, $monetization[$settingKey] ?? '');
            }
            Config::set('site.ad_code_sidebar',      $monetization['ad_code_sidebar']  ?? '');
            Config::set('site.ad_code_video',        $monetization['ad_code_video']    ?? '');
            Config::set('site.ad_code_footer',       $monetization['ad_code_footer']   ?? '');
            Config::set('site.ad_code_mobile',       $monetization['ad_code_mobile']   ?? '');
            Config::set('site.ad_code_onclick',      $monetization['ad_code_onclick']  ?? '');
            Config::set('site.ad_preroll_duration',  (int)($monetization['ad_preroll_duration'] ?? 5));
            Config::set('site.ad_preroll_media_url', $monetization['ad_preroll_media_url'] ?? '');
            Config::set('site.ad_preroll_click_url', $monetization['ad_preroll_click_url'] ?? '');
            Config::set('site.ad_grid_position',     (int)($monetization['ad_grid_position']   ?? 6));
            Config::set('site.ad_grid_max_per_feed', max(0, min(20, (int)($monetization['ad_grid_max_per_feed'] ?? 1))));
            Config::set('site.ad_grid_layout',        in_array($monetization['ad_grid_layout'] ?? 'grid_card', ['grid_card', 'wide_row'], true) ? $monetization['ad_grid_layout'] : 'grid_card');
            foreach ($gridAdContexts as $adContext) {
                $settingKey = 'ad_grid_layout_' . $adContext;
                Config::set('site.' . $settingKey, in_array($monetization[$settingKey] ?? 'inherit', ['inherit', 'grid_card', 'wide_row'], true) ? $monetization[$settingKey] : 'inherit');
            }
            Config::set('site.ad_preroll_enabled',   ($monetization['ad_preroll_enabled'] ?? '0') === '1');

            // ── APPEARANCE ────────────────────────────────────────────────────
            AppearanceSettings::applyToConfig($appearance);

            // ── SEO ───────────────────────────────────────────────────────────
            Config::set('site.meta_title_template',     $seo['meta_title_template']     ?? '{title} | {site_name}');
            Config::set('site.default_meta_description',$seo['default_meta_description'] ?? '');
            Config::set('site.og_image',                $seo['og_image']                ?? '');
            Config::set('site.twitter_card_type',       $seo['twitter_card_type']       ?? 'summary_large_image');
            Config::set('site.twitter_handle',          $seo['twitter_handle']          ?? '');
            Config::set('site.facebook_app_id',         $seo['facebook_app_id']         ?? '');
            Config::set('site.google_verification',     $seo['google_verification']     ?? '');
            Config::set('site.adult_rating_enabled',    ($seo['adult_rating_enabled']   ?? '1') === '1');
            Config::set('site.profile_uid_urls_enabled',($seo['profile_uid_urls_enabled'] ?? '1') === '1');
            Config::set('site.video_pages_indexable',   ($seo['video_pages_indexable']   ?? '1') === '1');
            Config::set('site.category_pages_indexable',($seo['category_pages_indexable'] ?? '1') === '1');
            Config::set('site.profile_pages_indexable', ($seo['profile_pages_indexable']  ?? '1') === '1');
            Config::set('site.playlist_pages_indexable',($seo['playlist_pages_indexable'] ?? '1') === '1');
            Config::set('site.search_pages_indexable',  ($seo['search_pages_indexable']   ?? '0') === '1');
            foreach (SeoService::templateDefaults() as $key => $default) {
                Config::set('site.' . $key, $seo[$key] ?? $default);
            }
            Config::set('site.video_url_prefix',         $seo['video_url_prefix']         ?? 'video');
            Config::set('site.video_url_pattern',        $seo['video_url_pattern']        ?? '{slug}-{uuid}');
            Config::set('site.robots_max_snippet',       (int)($seo['robots_max_snippet']       ?? -1));
            Config::set('site.robots_max_image_preview', $seo['robots_max_image_preview'] ?? 'large');
            Config::set('site.robots_max_video_preview', (int)($seo['robots_max_video_preview'] ?? -1));
            Config::set('site.robots_nofollow',          ($seo['robots_nofollow']         ?? '0') === '1');
            Config::set('site.robots_noarchive',         ($seo['robots_noarchive']        ?? '0') === '1');
            Config::set('site.robots_notranslate',       ($seo['robots_notranslate']      ?? '0') === '1');
            Config::set('site.robots_noimageindex',      ($seo['robots_noimageindex']     ?? '0') === '1');
            Config::set('site.canonical_enabled',        ($seo['canonical_enabled']       ?? '1') === '1');
            Config::set('site.hreflang_enabled',         ($seo['hreflang_enabled']        ?? '1') === '1');
            Config::set('site.website_schema_enabled',   ($seo['website_schema_enabled']  ?? '1') === '1');
            Config::set('site.search_action_schema_enabled', ($seo['search_action_schema_enabled'] ?? '1') === '1');
            Config::set('site.video_schema_enabled',     ($seo['video_schema_enabled']    ?? '1') === '1');
            Config::set('site.video_seek_action_enabled',($seo['video_seek_action_enabled'] ?? '0') === '1');
            Config::set('site.video_sitemap_enabled',    ($seo['video_sitemap_enabled']   ?? '1') === '1');
            Config::set('site.sitemap_video_limit',      (int)($seo['sitemap_video_limit'] ?? 1000));
            Config::set('site.schema_organization_type', $seo['schema_organization_type'] ?? 'Organization');
            Config::set('site.indexnow_enabled',         ($seo['indexnow_enabled']        ?? '0') === '1');
            Config::set('site.indexnow_key',             $seo['indexnow_key']             ?? '');
            Config::set('site.indexnow_endpoint',        $seo['indexnow_endpoint']        ?? 'https://api.indexnow.org/indexnow');
            Config::set('site.sitemap_enabled',         ($seo['sitemap_enabled']        ?? '1') === '1');
            Config::set('site.robots_noindex',          ($seo['robots_noindex']         ?? '0') === '1');
            Config::set('site.robots_txt_custom',       $seo['robots_txt']              ?? '');

            // ── PAGES CONTENT ─────────────────────────────────────────────────
            Config::set('site.terms_content',   $pages['terms_content']   ?? '');
            Config::set('site.privacy_content', $pages['privacy_content'] ?? '');
            Config::set('site.dmca_content',    $pages['dmca_content']    ?? '');
            Config::set('site.about_content',   $pages['about_content']   ?? '');
            Config::set('site.contact_content', $pages['contact_content'] ?? '');
            Config::set('site.faq_content',     $pages['faq_content']     ?? '');
            foreach (PremiumPageSettings::booleanKeys() as $key) {
                Config::set('site.' . $key, PremiumPageSettings::bool($pages, $key));
            }
            foreach (PremiumPageSettings::textKeys() as $key) {
                Config::set('site.' . $key, PremiumPageSettings::text($pages, $key));
            }

            // ── LOCALIZATION ──────────────────────────────────────────────────
            if (!empty($localization['default_locale'])) {
                Config::set('app.locale',           $localization['default_locale']);
                Config::set('app.fallback_locale',  $localization['default_locale']);
                // Stable key for locale_url() — never overwritten by Language middleware
                Config::set('site.default_locale',  $localization['default_locale']);
            } else {
                // Ensure site.default_locale is always set even if admin hasn't configured it
                Config::set('site.default_locale', config('app.locale', 'en'));
            }
            if (!empty($localization['timezone'])) {
                Config::set('app.timezone', $localization['timezone']);
                date_default_timezone_set($localization['timezone']);
            }
            Config::set('site.date_format',         $localization['date_format']         ?? 'M d, Y');
            Config::set('site.allow_language_switch',($localization['allow_language_switch'] ?? '1') === '1');
            Config::set('site.rtl_support',         ($localization['rtl_support']         ?? '0') === '1');
            Config::set('site.show_relative_dates', ($localization['show_relative_dates']  ?? '1') === '1');

            // ── LANGUAGES FROM DATABASE ────────────────────────────────────────
            // Load active languages from the `languages` table (if it exists) and
            // overwrite config('languages') so all existing code gets live DB data.
            // Falls back to config/languages.php if the table doesn't exist yet.
            // Schema::hasTable is cached to avoid a DB introspection query on every request.
            try {
                $hasLangTable = Cache::rememberForever('schema.has_languages_table', function () {
                    return \Illuminate\Support\Facades\Schema::hasTable('languages');
                });

                if ($hasLangTable) {
                    $dbLanguages = \App\Models\Language::getAllCached();

                    if ($dbLanguages->isNotEmpty()) {
                        $langArray = [];
                        foreach ($dbLanguages as $lang) {
                            $langArray[$lang->code] = [
                                'label' => $lang->label,
                                'emoji' => $lang->emoji,
                                'rtl'   => $lang->direction === 'rtl',
                            ];
                        }
                        Config::set('languages', $langArray);

                        // Set default locale from DB (the language marked is_default)
                        $dbDefault = $dbLanguages->firstWhere('is_default', true);
                        if ($dbDefault) {
                            Config::set('app.locale',          $dbDefault->code);
                            Config::set('app.fallback_locale', $dbDefault->code);
                            Config::set('site.default_locale', $dbDefault->code);
                        }
                    }
                }
            } catch (\Throwable) {
                // Table may not exist (fresh install) — silently fall back to config file
            }

            // ── NOTIFICATIONS ─────────────────────────────────────────────────
            $notificationBroadcastSettings = NotificationBroadcastSettings::fromSettingsArray($notifications);
            Config::set('site.notify_new_user',         ($notifications['notify_new_user']     ?? '1') === '1');
            Config::set('site.notify_new_video',        ($notifications['notify_new_video']    ?? '0') === '1');
            Config::set('site.notify_new_report',       ($notifications['notify_new_report']   ?? '1') === '1');
            Config::set('site.notify_new_comment',      ($notifications['notify_new_comment']  ?? '0') === '1');
            Config::set('site.notify_subscription',     ($notifications['notify_subscription'] ?? '1') === '1');
            Config::set('site.notify_contact_form',     ($notifications['notify_contact_form'] ?? '1') === '1');
            Config::set('site.admin_notification_email',$notifications['admin_notification_email'] ?? '');
            Config::set('site.push_new_subscriber',     ($notifications['push_new_subscriber'] ?? '1') === '1');
            Config::set('site.push_video_liked',        ($notifications['push_video_liked']    ?? '1') === '1');
            Config::set('site.push_comment_reply',      ($notifications['push_comment_reply']  ?? '1') === '1');
            Config::set('site.push_video_processed',    ($notifications['push_video_processed'] ?? '1') === '1');
            Config::set('site.broadcast_notification_chunk_size', $notificationBroadcastSettings->chunkSize);

            // ── PLAN LIMITS (override config/plans.php) ───────────────────────
            if ($hasValue($planlimits, 'free_max_uploads')) {
                Config::set('plans.free.max_uploads',        (int)$planlimits['free_max_uploads']);
            }
            if ($hasValue($planlimits, 'free_max_file_size')) {
                Config::set('plans.free.max_file_size',      (int)$planlimits['free_max_file_size']);
            }
            if ($hasValue($planlimits, 'free_max_storage')) {
                Config::set('plans.free.max_videos_storage', (int)$planlimits['free_max_storage']);
            }
            if ($hasValue($planlimits, 'premium_max_uploads')) {
                Config::set('plans.premium.max_uploads',     (int)$planlimits['premium_max_uploads']);
            }
            if ($hasValue($planlimits, 'premium_max_file_size')) {
                Config::set('plans.premium.max_file_size',   (int)$planlimits['premium_max_file_size']);
            }
            if ($hasValue($planlimits, 'premium_max_storage')) {
                Config::set('plans.premium.max_videos_storage', (int)$planlimits['premium_max_storage']);
            }
            if ($hasValue($planlimits, 'trial_period_days')) {
                Config::set('plans.trial_period.period',     (int)$planlimits['trial_period_days']);
            }
            if ($hasValue($planlimits, 'trial_email_reminder')) {
                Config::set('plans.trial_period.email_reminder', (int)$planlimits['trial_email_reminder']);
            }

            // ── CORS / API ────────────────────────────────────────────────────
            Config::set('site.api_enabled',          ($api['api_enabled']          ?? '1') === '1');
            Config::set('site.api_registration',     ($api['api_registration']     ?? '0') === '1');
            Config::set('site.api_rate_limit',       (int)($api['api_rate_limit']       ?? 60));
            Config::set('site.login_rate_limit',     (int)($api['login_rate_limit']     ?? 5));
            Config::set('site.upload_rate_limit',    (int)($api['upload_rate_limit']    ?? 10));
            Config::set('site.subscribe_rate_limit', (int)($api['subscribe_rate_limit'] ?? 5));
            Config::set('site.comment_rate_limit',   (int)($api['comment_rate_limit']   ?? 15));
            Config::set('site.report_rate_limit',    (int)($api['report_rate_limit']    ?? 5));
            Config::set('site.search_rate_limit',    (int)($api['search_rate_limit']    ?? 30));
            Config::set('site.video_view_rate_limit',(int)($api['video_view_rate_limit'] ?? 120));
            Config::set('site.api_token_expiration', (int)($api['api_token_expiration'] ?? 0));
            Config::set('site.embed_enabled',        ($api['embed_enabled']        ?? '1') === '1');
            Config::set('site.embed_allowed_domains',$api['embed_allowed_domains'] ?? '');
            CorsConfig::applyFromSettings($api);

            // ── CDN ───────────────────────────────────────────────────────────
            Config::set('site.cdn_enabled',      ($cdn['cdn_enabled']      ?? '0') === '1');
            Config::set('site.cdn_force_https',  ($cdn['cdn_force_https']  ?? '1') === '1');
            Config::set('site.cdn_url',          $cdn['cdn_url']           ?? '');
            Config::set('site.video_cdn_url',    $cdn['video_cdn_url']     ?? '');
            Config::set('site.thumbnail_cdn_url',$cdn['thumbnail_cdn_url'] ?? '');
            Config::set('site.cdn_provider',     $cdn['cdn_provider']      ?? 'none');
            Config::set('site.lazy_loading',     ($cdn['lazy_loading']     ?? '1') === '1');
            Config::set('site.webp_conversion',  ($cdn['webp_conversion']  ?? '0') === '1');
            Config::set('site.responsive_images',($cdn['responsive_images']?? '0') === '1');
            Config::set('site.image_quality',    (int)($cdn['image_quality']    ?? 85));
            Config::set('site.max_image_width',  (int)($cdn['max_image_width']  ?? 1920));
            // Cloudflare Integration
            Config::set('site.cf_zone_id',       $cdn['cf_zone_id']       ?? '');
            Config::set('site.cf_api_token',     $cdn['cf_api_token']     ?? '');
            Config::set('site.cf_email',         $cdn['cf_email']         ?? '');
            Config::set('site.cf_auto_purge',    ($cdn['cf_auto_purge']   ?? '0') === '1');
            Config::set('site.cf_dev_mode',      ($cdn['cf_dev_mode']     ?? '0') === '1');
            // Trusted proxy
            Config::set('site.trusted_proxies',  $cdn['trusted_proxies']  ?? '*');
            Config::set('site.trusted_headers',  $cdn['trusted_headers']  ?? 'all');

            // ── SOCIAL LINKS ──────────────────────────────────────────────────
            // Form saves as: social_facebook, social_twitter, etc.
            Config::set('site.social_facebook',  $sociallinks['social_facebook']  ?? '');
            Config::set('site.social_twitter',   $sociallinks['social_twitter']   ?? '');
            Config::set('site.social_instagram', $sociallinks['social_instagram'] ?? '');
            Config::set('site.social_youtube',   $sociallinks['social_youtube']   ?? '');
            Config::set('site.social_tiktok',    $sociallinks['social_tiktok']    ?? '');
            Config::set('site.social_discord',   $sociallinks['social_discord']   ?? '');
            Config::set('site.social_telegram',  array_key_exists('social_telegram', $sociallinks) ? $sociallinks['social_telegram'] : 'https://t.me/+jj6McO-iYfA0MTM1');
            Config::set('site.social_reddit',    $sociallinks['social_reddit']    ?? '');
            Config::set('site.social_github',    $sociallinks['social_github']    ?? '');
            Config::set('site.social_linkedin',  $sociallinks['social_linkedin']  ?? '');
            Config::set('site.social_twitch',    $sociallinks['social_twitch']    ?? '');
            Config::set('site.social_pinterest',  $sociallinks['social_pinterest']  ?? '');
            Config::set('site.social_mastodon',   $sociallinks['social_mastodon']   ?? '');
            Config::set('site.social_threads',    $sociallinks['social_threads']    ?? '');
            Config::set('site.social_bluesky',    $sociallinks['social_bluesky']    ?? '');
            Config::set('site.social_blog',       $sociallinks['social_blog']      ?? '');
            Config::set('site.social_docs',       $sociallinks['social_docs']      ?? '');
            Config::set('site.social_status',     $sociallinks['social_status']    ?? '');
            Config::set('site.social_appstore',   $sociallinks['social_appstore']  ?? '');
            Config::set('site.social_playstore',  $sociallinks['social_playstore'] ?? '');
            Config::set('site.social_rss',        $sociallinks['social_rss']       ?? '');

            // ── WEBHOOKS ──────────────────────────────────────────────────────
            $webhooks = Setting::getGroup('webhooks');
            Config::set('site.webhooks_enabled',       ($webhooks['webhooks_enabled']       ?? '0') === '1');
            Config::set('site.webhook_retry_failed',   ($webhooks['webhook_retry_failed']   ?? '1') === '1');
            Config::set('site.webhook_log_payloads',   ($webhooks['webhook_log_payloads']   ?? '0') === '1');
            Config::set('site.webhook_secret',         $webhooks['webhook_secret']          ?? '');
            Config::set('site.webhook_timeout',        (int)($webhooks['webhook_timeout']   ?? 30));
            Config::set('site.webhook_max_retries',    (int)($webhooks['webhook_max_retries'] ?? 3));
            // Webhook event endpoint URLs
            Config::set('site.webhook_url_new_user',        $webhooks['webhook_url_new_user']        ?? '');
            Config::set('site.webhook_url_new_video',       $webhooks['webhook_url_new_video']       ?? '');
            Config::set('site.webhook_url_new_comment',     $webhooks['webhook_url_new_comment']     ?? '');
            Config::set('site.webhook_url_new_report',      $webhooks['webhook_url_new_report']      ?? '');
            Config::set('site.webhook_url_new_subscription',$webhooks['webhook_url_new_subscription']?? '');
            Config::set('site.webhook_url_payment',         $webhooks['webhook_url_payment']         ?? '');
            Config::set('site.webhook_url_video_processed', $webhooks['webhook_url_video_processed'] ?? '');
            Config::set('site.webhook_url_user_banned',     $webhooks['webhook_url_user_banned']     ?? '');
            Config::set('site.webhook_url_contact',         $webhooks['webhook_url_contact']         ?? '');

            // ── SECURITY ──────────────────────────────────────────────────────
            Config::set('site.force_https',          ($security['force_https']           ?? '0') === '1');
            Config::set('site.two_factor_enabled',   ($security['two_factor_enabled']    ?? '0') === '1');
            Config::set('site.honeypot_enabled',     ($security['honeypot_enabled']      ?? '1') === '1');
            $captchaProvider = $security['captcha_provider'] ?? 'none';
            $captchaProvider = in_array($captchaProvider, ['none', 'built_in', 'turnstile', 'recaptcha_v2'], true) ? $captchaProvider : 'none';
            Config::set('site.captcha_provider',     $captchaProvider);
            Config::set('site.captcha_site_key',     $security['captcha_site_key']       ?? '');
            Config::set('site.captcha_on_login',     ($security['captcha_on_login']      ?? '0') === '1');
            Config::set('site.captcha_on_register',  ($security['captcha_on_register']   ?? '0') === '1');
            Config::set('site.captcha_on_contact',   ($security['captcha_on_contact']    ?? '0') === '1');
            Config::set('site.captcha_on_comment',   ($security['captcha_on_comment']    ?? '0') === '1');
            Config::set('site.captcha_on_upload',    ($security['captcha_on_upload']     ?? '0') === '1');
            Config::set('site.captcha_on_password_reset', ($security['captcha_on_password_reset'] ?? '0') === '1');
            Config::set('site.captcha_secret_key',   $security['captcha_secret_key']     ?? '');
            Config::set('site.block_tor_exit_nodes',  ($security['block_tor_exit_nodes']  ?? '0') === '1');
            Config::set('site.block_vpn_proxies',     ($security['block_vpn_proxies']     ?? '0') === '1');
            Config::set('site.block_disposable_emails',($security['block_disposable_emails'] ?? '0') === '1');
            Config::set('site.require_strong_password',($security['require_strong_password'] ?? '0') === '1');
            Config::set('site.login_notification',    ($security['login_notification']    ?? '0') === '1');
            Config::set('site.auto_logout_idle',      ($security['auto_logout_idle']      ?? '0') === '1');
            Config::set('site.idle_timeout_minutes',  (int)($security['idle_timeout_minutes'] ?? 30));
            Config::set('site.max_devices_per_user',  (int)($security['max_devices_per_user'] ?? 0));
            Config::set('site.admin_ip_whitelist',    trim($security['admin_ip_whitelist'] ?? ''));
            Config::set('site.global_ip_blacklist',   trim($security['global_ip_blacklist'] ?? ''));
            Config::set('site.hsts_enabled',          ($security['hsts_enabled']          ?? '0') === '1');
            Config::set('site.hsts_max_age',          (int)($security['hsts_max_age']     ?? 31536000));
            Config::set('site.csp_enabled',           ($security['csp_enabled']           ?? '0') === '1');
            Config::set('site.xss_protection',        ($security['xss_protection']        ?? '1') === '1');
            Config::set('site.nosniff_header',        ($security['nosniff_header']        ?? '1') === '1');
            Config::set('site.frame_options_header',  ($security['frame_options_header']  ?? '1') === '1');
            Config::set('site.failed_login_lockout',  (int)($security['failed_login_lockout'] ?? 15));
            Config::set('site.max_failed_logins',     (int)($security['max_failed_logins']    ?? 5));
            Config::set('site.csp_directives',        $security['csp_directives']         ?? '');

            // ── MAINTENANCE (dedicated group) ─────────────────────────────
            $maintenance = Setting::getGroup('maintenance');
            $maintenanceOn = array_key_exists('maintenance_mode_enabled', $maintenance)
                ? ($maintenance['maintenance_mode_enabled'] ?? '0') === '1'
                : false;
            Config::set('site.maintenance_mode', $maintenanceOn);
            Config::set('site.maintenance_allow_admins',   ($maintenance['maintenance_allow_admins']   ?? '1') === '1');
            Config::set('site.maintenance_secret',         $maintenance['maintenance_secret']          ?? '');
            Config::set('site.maintenance_message',        $maintenance['maintenance_message']         ?? 'We are currently performing maintenance. Please check back soon.');
            Config::set('site.maintenance_start',          $maintenance['maintenance_start']           ?? '');
            Config::set('site.maintenance_end',            $maintenance['maintenance_end']             ?? '');
            Config::set('site.maintenance_retry_after',    (int)($maintenance['maintenance_retry_after'] ?? 3600));
            Config::set('site.maintenance_allowed_ips',    $maintenance['maintenance_allowed_ips']     ?? '');
            Config::set('site.auto_cleanup_enabled',       ($maintenance['auto_cleanup_enabled']       ?? '0') === '1');
            Config::set('site.cleanup_schedule',           $maintenance['cleanup_schedule']            ?? 'daily');
            Config::set('site.cleanup_logs_days',          (int)($maintenance['cleanup_logs_days']     ?? 30));
            Config::set('site.auto_backup_enabled',        ($maintenance['auto_backup_enabled']        ?? '0') === '1');
            Config::set('site.backup_schedule',            $maintenance['backup_schedule']             ?? 'daily');
            Config::set('site.backup_keep_count',          (int)($maintenance['backup_keep_count']     ?? 7));

            // ── VALIDATION (override config/validation.php) ───────────────────
            if (!empty($validation['user_username_min'])) {
                Config::set('validation.user.username.min', (int)$validation['user_username_min']);
                Config::set('validation.user.username.max', (int)($validation['user_username_max'] ?? 40));
            }
            if (!empty($validation['user_slug_min'])) {
                Config::set('validation.user.slug.min', (int)$validation['user_slug_min']);
                Config::set('validation.user.slug.max', (int)($validation['user_slug_max'] ?? 30));
            }
            if (!empty($validation['user_password_min'])) {
                Config::set('validation.user.password.min', (int)$validation['user_password_min']);
            }
            if (!empty($validation['user_description_max'])) {
                Config::set('validation.user.description.max', (int)$validation['user_description_max']);
            }
            if (!empty($validation['user_website_max'])) {
                Config::set('validation.user.website.max', (int)$validation['user_website_max']);
            }
            if (!empty($validation['video_title_max'])) {
                Config::set('validation.video.title.max', (int)$validation['video_title_max']);
            }
            if (!empty($validation['video_description_max'])) {
                Config::set('validation.video.description.max', (int)$validation['video_description_max']);
            }
            if (!empty($validation['comment_content_max'])) {
                Config::set('validation.comment.content.max', (int)$validation['comment_content_max']);
            }
            if (!empty($validation['playlist_title_max'])) {
                Config::set('validation.playlist.title.max', (int)$validation['playlist_title_max']);
            }
            if (!empty($validation['playlist_description_max'])) {
                Config::set('validation.playlist.description.max', (int)$validation['playlist_description_max']);
            }
            if (!empty($validation['report_comment_max'])) {
                Config::set('validation.report.comment.max', (int)$validation['report_comment_max']);
            }
            if (!empty($validation['contact_name_min'])) {
                Config::set('validation.contact.name.min', (int)$validation['contact_name_min']);
                Config::set('validation.contact.name.max', (int)($validation['contact_name_max'] ?? 40));
            }
            if (!empty($validation['contact_message_min'])) {
                Config::set('validation.contact.message.min', (int)$validation['contact_message_min']);
                Config::set('validation.contact.message.max', (int)($validation['contact_message_max'] ?? 5000));
            }

        } catch (\Throwable $e) {
            // Silently fail if DB not ready (fresh install, migrations not run)
        }
    }

    private function applyDiscoveryFeatureSettings(array $discovery, string $prefix, array $defaults, string $windowKey = 'lookback_days'): void
    {
        Config::set("site.{$prefix}_enabled", ($discovery["{$prefix}_enabled"] ?? $defaults['enabled']) === '1');
        Config::set("site.{$prefix}_limit", (int) ($discovery["{$prefix}_limit"] ?? $defaults['limit']));
        Config::set("site.{$prefix}_{$windowKey}", (int) ($discovery["{$prefix}_{$windowKey}"] ?? $defaults[$windowKey]));
    }

    private function normalizeVideoStatusSetting(mixed $value): int
    {
        $aliases = [
            'public' => VideoStatus::PUBLIC->value,
            'private' => VideoStatus::PRIVATE->value,
            'planned' => VideoStatus::PLANNED->value,
            'unlisted' => VideoStatus::UNLISTED->value,
        ];

        $normalized = $aliases[strtolower((string) $value)] ?? (int) $value;

        return in_array($normalized, VideoStatus::validStatus(), true)
            ? $normalized
            : VideoStatus::PUBLIC->value;
    }
}
