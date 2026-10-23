@extends('layouts.admin')
@section('title', __('Settings'))

@php
    $smartDiscoveryDefaults = $smartDiscoveryDefaults ?? \App\Services\SmartDiscoveryRailService::settingsDefaults();
    $creatorSpotlightDefaults = $creatorSpotlightDefaults ?? \App\Services\AdaptiveCreatorSpotlightService::settingsDefaults();
    $trendingCollectionsDefaults = $trendingCollectionsDefaults ?? \App\Services\AdaptiveTrendingCollectionsService::settingsDefaults();
    $homeFeedSources = $homeFeedSources ?? \App\Support\AppearanceSettings::homeFeedSources();
    $homeFeedLoadModes = $homeFeedLoadModes ?? \App\Support\AppearanceSettings::homeFeedLoadModes();
    $homeFeedCategories = $homeFeedCategories ?? collect();
    $homeWidgetVideoDefaults = $homeWidgetVideoDefaults ?? \App\Support\HomeWidgetVideoSettings::defaults();
    $premiumPageDefaults = $premiumPageDefaults ?? \App\Support\PremiumPageSettings::defaults();
    $premiumPageIconOptions = $premiumPageIconOptions ?? \App\Support\PremiumPageSettings::iconOptions();
    $playbackProgressDefaults = $playbackProgressDefaults ?? \App\Services\PlaybackProgressSignalService::settingsDefaults();
    $creatorQualityPulseDefaults = $creatorQualityPulseDefaults ?? \App\Services\CreatorQualityPulseService::settingsDefaults();
    $studioHealthAlertsDefaults = $studioHealthAlertsDefaults ?? \App\Services\StudioHealthAlertsService::settingsDefaults();
    $creatorRecoveryChecklistDefaults = $creatorRecoveryChecklistDefaults ?? \App\Services\CreatorRecoveryChecklistService::settingsDefaults();
    $channelCustomizationDefaults = $channelCustomizationDefaults ?? \App\Support\ChannelCustomizationSettings::defaults();
    $channelCustomizationOptions = $channelCustomizationOptions ?? \App\Support\ChannelCustomizationSettings::options();
    $searchHistoryRetentionDefaults = $searchHistoryRetentionDefaults ?? \App\Services\SearchHistoryRetentionService::settingsDefaults();
    $themePresets = $themePresets ?? \App\Support\AppearanceSettings::themePresets();
    $videoCardDurationStyles = $videoCardDurationStyles ?? \App\Support\AppearanceSettings::videoCardDurationStyles();
    $categoryCardStyles = $categoryCardStyles ?? \App\Support\AppearanceSettings::categoryCardStyles();
    $creatorAvatarRingStyles = $creatorAvatarRingStyles ?? \App\Support\AppearanceSettings::creatorAvatarRingStyles();
    $headerStyles = $headerStyles ?? \App\Support\AppearanceSettings::headerStyles();
    $backToTopStyles = $backToTopStyles ?? \App\Support\AppearanceSettings::backToTopStyles();
    $ambientGlowIntensities = $ambientGlowIntensities ?? \App\Support\AppearanceSettings::ambientGlowIntensities();
    $heroLayoutModes = $heroLayoutModes ?? \App\Support\AppearanceSettings::heroLayoutModes();
    $watchLayoutModes = $watchLayoutModes ?? \App\Support\AppearanceSettings::watchLayoutModes();
    $videoCardLayoutStyles = $videoCardLayoutStyles ?? \App\Support\AppearanceSettings::videoCardLayoutStyles();
    $homeLayoutModes = $homeLayoutModes ?? \App\Support\AppearanceSettings::homeLayoutModes();
    $motionEngineLevels = $motionEngineLevels ?? \App\Support\AppearanceSettings::motionEngineLevels();
    $performanceTierModes = $performanceTierModes ?? \App\Support\AppearanceSettings::performanceTierModes();
    $masterExperiences = $masterExperiences ?? \App\Support\MasterExperienceCatalog::all();
    $modalPresentationStyles = $modalPresentationStyles ?? \App\Support\AppearanceSettings::modalPresentationStyles();
    $backgroundAtmosphereEffects = $backgroundAtmosphereEffects ?? \App\Support\AppearanceSettings::backgroundAtmosphereEffects();
    $typographyPairings = $typographyPairings ?? \App\Support\AppearanceSettings::typographyPairings();
    $appearanceDefaults = \App\Support\AppearanceSettings::defaults();
    $footerDefaults = \App\Support\FooterSettings::defaults();
    $navigationDefaults = \App\Support\NavigationSettings::defaults();
    $typographyFonts = \App\Support\AppearanceSettings::fontFamilies();
    $textContrastLevels = \App\Support\AppearanceSettings::textContrastLevels();
    $mobileVideoCardDensities = \App\Support\AppearanceSettings::mobileVideoCardDensities();
    $studioVideoPreviewModes = \App\Support\AppearanceSettings::studioVideoPreviewModes();
    $mailMailerOptions = array_keys(config('mail.mailers', ['smtp' => []]));
    $uploadDefaults = $uploadDefaults ?? \App\Data\UploadSettings::settingsDefaults();
    $uploadVideoStatusOptions = $uploadVideoStatusOptions ?? \App\Enums\VideoStatus::getActive();
    $uploadVideoLanguages = $uploadVideoLanguages ?? \App\Models\Video::languages();
    $uploadAllowedFormatsDefault = 'mp4,webm,avi,mov,mkv';
    $normalizeVideoStatusSetting = function (mixed $status): string {
        return match (strtolower((string) $status)) {
            'public' => (string) \App\Enums\VideoStatus::PUBLIC->value,
            'private' => (string) \App\Enums\VideoStatus::PRIVATE->value,
            'planned' => (string) \App\Enums\VideoStatus::PLANNED->value,
            'unlisted' => (string) \App\Enums\VideoStatus::UNLISTED->value,
            default => (string) $status,
        };
    };
    $notificationBroadcastDefaults = $notificationBroadcastDefaults ?? \App\Data\NotificationBroadcastSettings::settingsDefaults();
    $value = fn (string $group, string $key, mixed $default = '') => old($key, $settings[$group][$key] ?? $default);
    $isChecked = fn (string $group, string $key, string $default = '0') => (string)($settings[$group][$key] ?? $default) === '1';
    $display = fn (mixed $label, string $fallback = '') => is_array($label) ? $fallback : (string) $label;
    $stripeChecks = [
        ['label' => __('Stripe Publishable Key'), 'ready' => filled($env['stripe_key'] ?? null)],
        ['label' => __('Stripe Secret Key'), 'ready' => filled($env['stripe_secret'] ?? null)],
        ['label' => __('Stripe Webhook Secret'), 'ready' => filled($env['stripe_webhook_secret'] ?? null)],
        ['label' => __('Stripe Plan IDs'), 'ready' => (int)($systemInfo['total_plans'] ?? 0) > 0 && (int)($systemInfo['plans_missing_stripe'] ?? 0) === 0],
    ];
    $paypalChecks = [
        ['label' => __('PayPal Client ID'), 'ready' => filled($env['paypal_client_id'] ?? null)],
        ['label' => __('PayPal Secret'), 'ready' => filled($env['paypal_client_secret'] ?? null)],
        ['label' => __('PayPal Webhook ID'), 'ready' => filled($env['paypal_webhook_id'] ?? null)],
        ['label' => __('PayPal Plan IDs'), 'ready' => (int)($systemInfo['total_plans'] ?? 0) > 0 && (int)($systemInfo['plans_missing_paypal'] ?? 0) === 0],
    ];
    $stripeConfigured = collect($stripeChecks)->every(fn ($item) => $item['ready']);
    $paypalConfigured = collect($paypalChecks)->every(fn ($item) => $item['ready']);
    $billingConfigured = $stripeConfigured || $paypalConfigured;
    $activeVideos = (int)($systemInfo['active_videos'] ?? 0);
    $activeVideoThumbnails = (int)($systemInfo['active_videos_with_thumbnails'] ?? 0);
    $activeVideoDescriptions = (int)($systemInfo['active_videos_with_descriptions'] ?? 0);
    $descriptionCoveragePrefix = ($systemInfo['description_coverage_estimated'] ?? false) ? '~' : '';
    $thumbnailCoverage = $activeVideos > 0 ? round(($activeVideoThumbnails / $activeVideos) * 100) : 100;
    $descriptionCoverage = $activeVideos > 0 ? round(($activeVideoDescriptions / $activeVideos) * 100) : 100;
    $systemChecks = [
        ['label' => __('Production Environment'), 'ready' => ($env['app_env'] ?? '') === 'production', 'value' => $env['app_env'] ?: __('Not set')],
        ['label' => __('Debug Disabled'), 'ready' => !($env['app_debug'] ?? false), 'value' => ($env['app_debug'] ?? false) ? __('ON') : __('OFF')],
        ['label' => __('Public App URL'), 'ready' => str_starts_with((string)($env['app_url'] ?? ''), 'https://') && !str_contains((string)($env['app_url'] ?? ''), 'localhost'), 'value' => $env['app_url'] ?: __('Not set')],
        ['label' => __('Site Indexable'), 'ready' => !config('site.robots_noindex'), 'value' => config('site.robots_noindex') ? __('Noindex enabled') : __('Index allowed')],
        ['label' => __('Sitemaps Enabled'), 'ready' => config('site.sitemap_enabled', true) && config('site.video_sitemap_enabled', true), 'value' => (config('site.sitemap_enabled', true) ? 'sitemap' : 'no sitemap') . ' / ' . (config('site.video_sitemap_enabled', true) ? 'video sitemap' : 'no video sitemap')],
        ['label' => __('Canonical and Schema'), 'ready' => config('site.canonical_enabled', true) && config('site.website_schema_enabled', true) && config('site.video_schema_enabled', true), 'value' => __('Canonical, Website schema, Video schema')],
        ['label' => __('Video Thumbnail Coverage'), 'ready' => $activeVideos === 0 || $thumbnailCoverage >= 80, 'value' => $activeVideoThumbnails . ' / ' . $activeVideos . ' (' . $thumbnailCoverage . '%)'],
        ['label' => __('Video Description Coverage'), 'ready' => $activeVideos === 0 || $descriptionCoverage >= 80, 'value' => $descriptionCoveragePrefix . $activeVideoDescriptions . ' / ' . $activeVideos . ' (' . $descriptionCoveragePrefix . $descriptionCoverage . '%)'],
        ['label' => __('Payment Gateway Ready'), 'ready' => $billingConfigured, 'value' => ($stripeConfigured ? 'Stripe' : __('Stripe incomplete')) . ' / ' . ($paypalConfigured ? 'PayPal' : __('PayPal incomplete'))],
        ['label' => __('Settings Coverage'), 'ready' => true, 'value' => ($systemInfo['settings_groups_count'] ?? 0) . ' ' . __('groups') . ' / ' . ($systemInfo['settings_fields_count'] ?? 0) . ' ' . __('fields')],
        ['label' => __('Storage Link'), 'ready' => (bool)($systemInfo['storage_public_link'] ?? false), 'value' => ($systemInfo['storage_public_link'] ?? false) ? __('Present') : __('Missing')],
        ['label' => __('Storage Writable'), 'ready' => (bool)($systemInfo['storage_writable'] ?? false), 'value' => ($systemInfo['storage_writable'] ?? false) ? __('Writable') : __('Not writable')],
        ['label' => __('Framework Cache'), 'ready' => (bool)($systemInfo['framework_cache_writable'] ?? false), 'value' => ($systemInfo['framework_cache_writable'] ?? false) ? __('Writable') : __('Not writable')],
        ['label' => __('Bootstrap Cache'), 'ready' => (bool)($systemInfo['bootstrap_cache_writable'] ?? false), 'value' => ($systemInfo['bootstrap_cache_writable'] ?? false) ? __('Writable') : __('Not writable')],
        ['label' => __('Config Cached'), 'ready' => true, 'value' => ($systemInfo['config_cached'] ?? false) ? __('Yes') : __('No')],
        ['label' => __('Routes Cached'), 'ready' => true, 'value' => ($systemInfo['routes_cached'] ?? false) ? __('Yes') : __('No')],
        ['label' => __('Compiled Views'), 'ready' => true, 'value' => number_format((int)($systemInfo['compiled_views'] ?? 0))],
        ['label' => __('Latest Log Error'), 'ready' => str_starts_with((string)($systemInfo['latest_log_error'] ?? ''), 'No '), 'value' => $systemInfo['latest_log_error'] ?? __('Unavailable')],
    ];
    $activeTab = request('tab', 'general') === 'advanced' ? 'system' : request('tab', 'general');
    $tabs = [
        ['id' => 'general', 'icon' => 'sliders', 'label' => __('General'), 'group' => 'core'],
        ['id' => 'appearance', 'icon' => 'palette', 'label' => __('Appearance'), 'group' => 'design'],
        ['id' => 'discovery', 'icon' => 'compass', 'label' => __('Discovery'), 'group' => 'design'],
        ['id' => 'uploads', 'icon' => 'cloud-arrow-up', 'label' => __('Uploads'), 'group' => 'media'],
        ['id' => 'notifications', 'icon' => 'bell', 'label' => __('Notifications'), 'group' => 'media'],
        ['id' => 'email', 'icon' => 'envelope', 'label' => __('Email'), 'group' => 'core'],
        ['id' => 'comments', 'icon' => 'comments', 'label' => __('Comments'), 'group' => 'media'],
        ['id' => 'users', 'icon' => 'users-gear', 'label' => __('Users'), 'group' => 'media'],
        ['id' => 'monetization', 'icon' => 'sack-dollar', 'label' => __('Monetization'), 'group' => 'commerce'],
        ['id' => 'seo', 'icon' => 'magnifying-glass-chart', 'label' => __('SEO'), 'group' => 'commerce'],
        ['id' => 'pages', 'icon' => 'file-lines', 'label' => __('Pages'), 'group' => 'design'],
        ['id' => 'security', 'icon' => 'shield-halved', 'label' => __('Security'), 'group' => 'commerce'],
        ['id' => 'localization', 'icon' => 'globe', 'label' => __('Localization'), 'group' => 'core'],
        ['id' => 'validation', 'icon' => 'check-double', 'label' => __('Validation'), 'group' => 'core'],
        ['id' => 'planlimits', 'icon' => 'gauge-high', 'label' => __('Plans'), 'group' => 'commerce'],
        ['id' => 'api', 'icon' => 'code', 'label' => __('API'), 'group' => 'commerce'],
        ['id' => 'sociallinks', 'icon' => 'hashtag', 'label' => __('Social'), 'group' => 'commerce'],
        ['id' => 'maintenance', 'icon' => 'screwdriver-wrench', 'label' => __('Maintenance'), 'group' => 'core'],
        ['id' => 'system', 'icon' => 'server', 'label' => __('System'), 'group' => 'core'],
    ];
    $tabIds = collect($tabs)->pluck('id')->all();
    $activeTab = in_array($activeTab, $tabIds, true) ? $activeTab : 'general';
@endphp

@section('content')
<style>
    .avd-settings-page {
        --settings-card: rgba(255, 255, 255, 0.08);
        --settings-input-bg: #111827;
        --settings-line: var(--avd-border);
        --settings-muted: var(--avd-muted);
        max-width: 100%;
        overflow-x: clip;
    }

    [data-bs-theme="light"] .avd-settings-page {
        --settings-card: #fff;
        --settings-input-bg: #fff;
        --settings-line: rgba(15, 23, 42, 0.14);
        --settings-muted: #445066;
    }

    .avd-settings-page * {
        box-sizing: border-box;
    }

    .avd-settings-page .avd-settings-panel *,
    .avd-settings-page .avd-settings-hero * {
        min-width: 0;
    }

    /* Crucial: Exempt buttons, badges, nav pills and horizontal filter strips from min-width: 0 */
    .avd-settings-page .btn,
    .avd-settings-page .badge,
    .avd-settings-page .nav-link,
    .avd-settings-page .cat-quick-filter-btn,
    .avd-settings-page .mood-filter-btn,
    .avd-settings-page .studio-atmo-pill {
        min-width: max-content !important;
        flex-shrink: 0;
    }

    /* Universal spacing between icons and text in all buttons */
    .avd-settings-page .btn,
    .btn {
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 0.5rem !important;
    }

    .avd-settings-page .btn > i,
    .avd-settings-page .btn > svg,
    .btn > i,
    .btn > svg {
        display: inline-block !important;
        vertical-align: middle !important;
        flex-shrink: 0 !important;
    }

    .avd-settings-page .btn > i:first-child:not(:only-child),
    .avd-settings-page .btn > svg:first-child:not(:only-child),
    .btn > i:first-child:not(:only-child),
    .btn > svg:first-child:not(:only-child) {
        margin-right: 0 !important;
    }

    .avd-settings-page .btn > i:last-child:not(:only-child),
    .avd-settings-page .btn > svg:last-child:not(:only-child),
    .btn > i:last-child:not(:only-child),
    .btn > svg:last-child:not(:only-child) {
        margin-left: 0 !important;
    }

    .badge > i:first-child:not(:only-child),
    .badge > svg:first-child:not(:only-child) {
        margin-right: 0.4rem !important;
    }

    .nav-link > i:first-child:not(:only-child),
    .nav-link > svg:first-child:not(:only-child) {
        margin-right: 0.4rem !important;
    }

    .me-1\.5 { margin-right: 0.45rem !important; }
    .ms-1\.5 { margin-left: 0.45rem !important; }
    .gap-1\.5 { gap: 0.45rem !important; }

    /* Category & Mood Quick Filter Strips - never wrap, never clip, smooth scroll */
    #categoryQuickFilterStrip {
        display: flex !important;
        flex-wrap: nowrap !important;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
        gap: 0.5rem !important;
        padding-bottom: 6px;
        max-width: 100% !important;
    }

    #categoryQuickFilterStrip::-webkit-scrollbar {
        height: 5px;
    }

    #categoryQuickFilterStrip::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 4px;
    }

    #categoryQuickFilterStrip::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 4px;
    }

    #categoryQuickFilterStrip .cat-quick-filter-btn,
    #categoryQuickFilterStrip .btn {
        flex: 0 0 auto !important;
        flex-shrink: 0 !important;
        min-width: max-content !important;
        max-width: none !important;
        white-space: nowrap !important;
        overflow: visible !important;
        text-overflow: clip !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 0.45rem !important;
        padding: 0.38rem 0.85rem !important;
        font-size: 0.78rem !important;
        line-height: 1.2 !important;
    }

    #moodQuickFilterStrip {
        display: flex !important;
        flex-wrap: nowrap !important;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
        gap: 0.45rem !important;
        padding-bottom: 6px;
        max-width: 100% !important;
    }

    #moodQuickFilterStrip .mood-filter-btn,
    #moodQuickFilterStrip .btn {
        flex: 0 0 auto !important;
        flex-shrink: 0 !important;
        min-width: max-content !important;
        max-width: none !important;
        white-space: nowrap !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 0.45rem !important;
        padding: 0.32rem 0.75rem !important;
        font-size: 0.76rem !important;
    }

    /* Real-Time Auto-Save HUD & Toast Styles */
    .realtime-autosave-hud {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.42rem 0.95rem;
        border-radius: 999px;
        border: 1px solid var(--settings-line);
        background: var(--avd-surface-soft, rgba(255,255,255,0.06));
        font-size: 0.76rem;
        transition: background-color 0.25s, border-color 0.25s, box-shadow 0.25s, color 0.25s;
        cursor: default;
        user-select: none;
    }

    .realtime-autosave-hud.saving {
        border-color: rgba(var(--avd-brand-rgb, 255,61,97), 0.6) !important;
        background: rgba(var(--avd-brand-rgb, 255,61,97), 0.12) !important;
        box-shadow: 0 0 16px rgba(var(--avd-brand-rgb, 255,61,97), 0.25);
    }

    .realtime-autosave-hud.saved {
        border-color: rgba(34, 197, 94, 0.5) !important;
        background: rgba(34, 197, 94, 0.12) !important;
        box-shadow: 0 0 16px rgba(34, 197, 94, 0.2);
    }

    .realtime-hud-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #22c55e;
        box-shadow: 0 0 8px #22c55e;
        display: inline-block;
        transition: background-color 0.25s, box-shadow 0.25s, opacity 0.25s, transform 0.25s;
    }

    .realtime-hud-dot.saving {
        background: #f59e0b;
        box-shadow: 0 0 10px #f59e0b;
        animation: avdHudPulse 0.9s infinite alternate;
    }

    .realtime-hud-dot.error {
        background: #ef4444;
        box-shadow: 0 0 10px #ef4444;
    }

    @keyframes avdHudPulse {
        from { transform: scale(0.85); opacity: 0.65; }
        to { transform: scale(1.25); opacity: 1; }
    }

    .realtime-save-toast {
        position: fixed;
        bottom: 24px;
        right: 24px;
        z-index: 99999;
        padding: 0.85rem 1.25rem;
        border-radius: 16px;
        border: 1px solid rgba(34, 197, 94, 0.4);
        background: rgba(14, 19, 34, 0.95);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        box-shadow: 0 16px 40px rgba(0, 0, 0, 0.55), 0 0 20px rgba(34, 197, 94, 0.2);
        color: #fff;
        display: flex;
        align-items: center;
        gap: 0.85rem;
        transform: translateY(100px);
        opacity: 0;
        transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.35s ease;
        pointer-events: none;
    }

    .realtime-save-toast.show {
        transform: translateY(0);
        opacity: 1;
        pointer-events: auto;
    }

    .avd-settings-hero {
        position: relative;
        overflow: hidden;
        border: 1px solid var(--settings-line);
        border-radius: 18px;
        background:
            radial-gradient(circle at 4% 0%, rgba(var(--avd-brand-rgb), 0.14), transparent 20rem),
            radial-gradient(circle at 92% 10%, rgba(var(--avd-cyan-rgb), 0.12), transparent 22rem),
            linear-gradient(135deg, var(--avd-surface), var(--avd-surface-soft));
        box-shadow: var(--avd-shadow-soft);
    }

    .avd-settings-hero h3 {
        font-size: 1.55rem;
        font-weight: 950;
        letter-spacing: 0;
    }

    .avd-settings-hero p {
        color: var(--settings-muted) !important;
        overflow-wrap: anywhere;
    }

    .avd-settings-card {
        height: 100%;
        border: 1px solid var(--settings-line);
        border-radius: 14px;
        background: var(--settings-card);
        box-shadow: var(--avd-shadow-soft);
    }

    .avd-settings-summary-grid {
        --bs-gutter-x: 0.85rem;
        --bs-gutter-y: 0.85rem;
    }

    .avd-settings-summary-grid .avd-settings-card {
        min-height: 92px;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .avd-settings-tabs {
        position: sticky;
        top: 64px;
        z-index: 10;
        border: 1px solid var(--settings-line);
        border-radius: 16px;
        background: var(--avd-surface);
        box-shadow: var(--avd-shadow-soft);
    }

    .avd-settings-tabs .nav {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(7.6rem, 1fr));
        align-items: stretch;
        flex-wrap: initial !important;
        gap: 0.25rem;
        overflow: visible !important;
        padding: 0.42rem;
    }

    .avd-settings-tabs .nav-item {
        min-width: 0;
    }

    .avd-settings-tabs .nav-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.34rem;
        width: 100%;
        min-height: 32px;
        padding: 0.34rem 0.58rem;
        border: 0;
        border-radius: 999px;
        color: var(--avd-muted);
        font-size: 0.82rem;
        font-weight: 820;
        line-height: 1.1;
        white-space: nowrap;
    }

    .avd-settings-tabs .avd-tab-label {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    [data-bs-theme="light"] .avd-settings-tabs .nav-link {
        color: #4b5568;
    }

    .avd-settings-tabs .nav-link i {
        font-size: 0.82rem;
    }

    .avd-settings-tabs .nav-link.active {
        background: linear-gradient(135deg, var(--avd-brand), var(--avd-brand-2));
        color: #fff;
        box-shadow: 0 10px 22px rgba(var(--avd-brand-rgb), 0.22);
    }

    .avd-settings-panel {
        border: 1px solid var(--settings-line);
        border-radius: 18px;
        background: var(--settings-card);
        box-shadow: var(--avd-shadow-soft);
    }

    .avd-field-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.85rem;
    }

    .avd-field-span {
        grid-column: 1 / -1;
    }

    .avd-ad-slot-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.85rem;
    }

    .avd-ad-slot-editor {
        min-width: 0;
        display: grid;
        gap: 0.65rem;
        padding: 0.85rem;
        border: 1px solid var(--settings-line);
        border-radius: 16px;
        background: var(--avd-surface-soft);
    }

    .avd-ad-slot-heading {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
    }

    .avd-ad-slot-heading .form-select {
        width: min(100%, 190px);
        min-height: 38px;
    }

    .avd-switch-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.75rem;
    }

    .avd-switch-card {
        min-height: 52px;
        display: flex;
        align-items: center;
        gap: 0.7rem;
        padding: 0.62rem 0.75rem;
        border: 1px solid var(--settings-line);
        border-radius: 14px;
        background: var(--avd-surface-soft);
    }

    .avd-switch-card .form-check-input {
        margin: 0;
    }

    .avd-section-title {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        margin-bottom: 1rem;
        font-size: 1rem;
        font-weight: 950;
    }

    .avd-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.3rem 0.58rem;
        border-radius: 999px;
        border: 1px solid var(--settings-line);
        background: var(--avd-surface-soft);
        color: var(--settings-muted);
        font-size: 0.74rem;
        font-weight: 850;
    }

    .avd-appearance-preview {
        border: 1px solid var(--settings-line);
        border-radius: 18px;
        overflow: hidden;
        background:
            radial-gradient(circle at 12% 0%, rgba(var(--avd-brand-rgb), 0.18), transparent 16rem),
            var(--avd-surface-soft);
    }

    .avd-appearance-preview-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.72rem 0.85rem;
        border-bottom: 1px solid var(--settings-line);
        background: var(--avd-surface);
    }

    .avd-preview-brand {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        font-weight: 950;
    }

    .avd-preview-play {
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--avd-brand), var(--avd-brand-2));
        color: #fff;
        box-shadow: var(--avd-glow-brand);
    }

    .avd-preview-card {
        margin: 0.85rem;
        border-radius: 14px;
        background: var(--avd-surface);
        box-shadow: var(--avd-shadow-soft);
        overflow: hidden;
    }

    .avd-preview-thumb {
        aspect-ratio: 16 / 9;
        background:
            linear-gradient(135deg, rgba(var(--avd-brand-rgb), 0.32), rgba(var(--avd-cyan-rgb), 0.20)),
            var(--avd-surface-hover);
    }

    .avd-env-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.75rem;
    }

    .avd-env-item {
        border: 1px solid var(--settings-line);
        border-radius: 14px;
        background: var(--avd-surface-soft);
        padding: 0.72rem;
        min-width: 0;
    }

    .avd-settings-page .text-muted {
        color: var(--settings-muted) !important;
    }

    .avd-settings-page .form-label,
    .avd-settings-page .fw-bold,
    .avd-settings-page .fw-black {
        color: var(--avd-text);
    }

    .avd-settings-page .form-control,
    .avd-settings-page .form-select,
    .avd-settings-page textarea.form-control {
        min-height: 42px;
        border-color: var(--settings-line);
        background-color: var(--settings-input-bg);
        color: var(--avd-text);
    }

    .avd-settings-page .form-control:focus,
    .avd-settings-page .form-select:focus,
    .avd-settings-page textarea.form-control:focus {
        border-color: var(--avd-brand);
        background-color: var(--settings-input-bg);
        color: var(--avd-text);
        box-shadow: 0 0 0 4px var(--avd-brand-soft);
    }

    [data-bs-theme="light"] .avd-settings-page .form-control,
    [data-bs-theme="light"] .avd-settings-page .form-select,
    [data-bs-theme="light"] .avd-settings-page textarea.form-control {
        background-color: #fff;
        color: #111827;
    }

    .avd-settings-page .avd-settings-card.p-3,
    .avd-settings-page .avd-settings-hero.p-3,
    .avd-settings-page .avd-settings-panel.p-3 {
        padding: 0.9rem !important;
    }

    .avd-settings-page .avd-settings-summary-grid .avd-settings-card.p-3 {
        padding: 0.75rem !important;
    }

    @media (min-width: 768px) {
        .avd-settings-page .avd-settings-hero.p-md-4,
        .avd-settings-page .avd-settings-panel.p-md-4 {
            padding: 1.1rem !important;
        }
    }

    @media (max-width: 1199.98px) {
        .avd-switch-grid,
        .avd-env-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 767.98px) {
        .avd-field-grid,
        .avd-ad-slot-grid,
        .avd-switch-grid,
        .avd-env-grid {
            grid-template-columns: 1fr;
        }

        .avd-ad-slot-heading {
            display: grid;
        }

        .avd-ad-slot-heading .form-select {
            width: 100%;
        }

        .avd-settings-tabs {
            position: static;
        }
    }

    @media (max-width: 575.98px) {
        .avd-settings-page {
            width: 100%;
        }

        .avd-settings-summary-grid {
            --bs-gutter-x: 0.6rem;
            --bs-gutter-y: 0.6rem;
        }

        .avd-settings-summary-grid .avd-settings-card {
            min-height: 82px;
        }

        .avd-settings-summary-grid .avd-settings-card i {
            margin-bottom: 0.35rem !important;
        }

        .avd-settings-summary-grid .fw-black {
            font-size: 0.92rem;
        }

        .avd-settings-summary-grid .small {
            font-size: 0.76rem;
        }

        .avd-settings-tabs .nav {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .avd-settings-tabs .nav-link {
            min-height: 34px;
            min-width: 0 !important;
            padding-inline: 0.48rem;
            font-size: 0.78rem;
        }

        .avd-settings-page .btn:not(.cat-quick-filter-btn):not(.mood-filter-btn):not(.theme-cat-pill):not(.theme-mood-pill):not(.studio-atmo-pill) {
            min-width: 0 !important;
            max-width: 100%;
            white-space: normal;
        }

        .avd-settings-page .btn {
            gap: 0.35rem !important;
        }

        .realtime-autosave-hud {
            max-width: 100%;
            white-space: nowrap;
        }

        .realtime-save-toast {
            right: 12px;
            left: 12px;
            bottom: calc(12px + env(safe-area-inset-bottom));
            max-width: none;
        }

        #themeGalleryModal .modal-header {
            flex-wrap: wrap;
            gap: 0.75rem;
            padding-inline: 1rem !important;
        }

        #themeGalleryModal .modal-header > .d-flex:last-child {
            width: 100%;
            justify-content: flex-end;
        }

        #themeGalleryModal .modal-title {
            font-size: 1rem;
        }

        #themeGalleryModal .modal-title .badge {
            font-size: 0.65rem !important;
        }

        #themeGalleryModal .theme-card-actions {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        }

        #themeGalleryModal .theme-card-actions .btn {
            min-width: 0 !important;
            white-space: normal;
        }

        #themeGalleryModal .theme-item-card .font-monospace {
            max-width: 100px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .avd-settings-page .row {
            --bs-gutter-x: 0;
            margin-left: 0;
            margin-right: 0;
        }

        .avd-settings-page [class*="col-"] {
            padding-left: 0;
            padding-right: 0;
        }

        .avd-settings-page .avd-settings-summary-grid {
            --bs-gutter-x: 0.6rem;
            --bs-gutter-y: 0.6rem;
        }

        .avd-settings-page .avd-settings-summary-grid > [class*="col-"] {
            padding-left: calc(var(--bs-gutter-x) * 0.5);
            padding-right: calc(var(--bs-gutter-x) * 0.5);
        }

    }

    @media (prefers-reduced-motion: reduce) {
        .avd-settings-page *,
        .avd-settings-page *::before,
        .avd-settings-page *::after {
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
            scroll-behavior: auto !important;
            transition-duration: 0.01ms !important;
        }

        .realtime-hud-dot.saving {
            animation: none !important;
        }
    }
</style>

<div class="avd-settings-page">
    <div class="avd-settings-hero p-3 p-md-4 mb-4">
        <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
            <div>
                <span class="avd-status-pill mb-2"><i class="fa-solid fa-database"></i> {{__('Live database settings')}}</span>
                <h3 class="mb-1"><i class="fa-solid fa-gear me-2"></i> {{__('Site Settings')}}</h3>
                <p class="text-muted mb-0">{{__('Only settings that are wired to the application are editable here. Environment secrets stay in Env Editor.')}}</p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div class="realtime-autosave-hud shadow-sm me-1" id="realtimeAutoSaveHUD" role="status" aria-live="polite" aria-atomic="true" title="{{ __('All settings auto-save instantly when modified') }}">
                    <span class="realtime-hud-dot" id="realtimeHudDot"></span>
                    <span class="fw-bold text-white" id="realtimeHudText">{{ __('Real-Time Auto-Save Active') }}</span>
                </div>
                <a href="{{ route('admin.env-editor.index') }}" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-file-shield me-1"></i> {{__('Env Editor')}}
                </a>
                <form method="POST" action="{{ route('admin.settings.clear.cache') }}">
                    @csrf
                    <button type="submit" class="btn btn-warning">
                        <i class="fa-solid fa-broom me-1"></i> {{__('Clear Caches')}}
                    </button>
                </form>
            </div>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>{{__('Please fix the highlighted settings.')}}</strong>
            <ul class="mb-0 mt-2">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{__('Close')}}"></button>
        </div>
    @endif

    <div class="row g-3 mb-3 mb-md-4 avd-settings-summary-grid">
        @foreach([
            ['label' => 'PHP', 'value' => $systemInfo['php_version'], 'icon' => 'fa-brands fa-php'],
            ['label' => 'Laravel', 'value' => $systemInfo['laravel_version'], 'icon' => 'fa-brands fa-laravel'],
            ['label' => __('Database'), 'value' => $systemInfo['db_size'], 'icon' => 'fa-solid fa-database'],
            ['label' => __('Storage'), 'value' => $systemInfo['storage_used'], 'icon' => 'fa-solid fa-hard-drive'],
            ['label' => __('Disk Free'), 'value' => $systemInfo['disk_free'], 'icon' => 'fa-solid fa-hdd'],
            ['label' => __('Upload Limit'), 'value' => $systemInfo['max_upload'], 'icon' => 'fa-solid fa-upload'],
        ] as $card)
            <div class="col-6 col-lg-4 col-xxl-2">
                <div class="avd-settings-card p-3">
                    <i class="{{ $card['icon'] }} text-primary mb-2"></i>
                    <div class="small text-muted">{{ $display($card['label']) }}</div>
                    <div class="fw-black text-truncate">{{ $card['value'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-3 mb-3 mb-md-4 avd-settings-summary-grid">
        @foreach([
            ['label' => __('Users'), 'value' => $systemInfo['total_users'], 'icon' => 'fa-users', 'route' => 'admin.users.index'],
            ['label' => __('Videos'), 'value' => $systemInfo['total_videos'], 'icon' => 'fa-video', 'route' => 'admin.videos.index'],
            ['label' => __('Comments'), 'value' => $systemInfo['total_comments'], 'icon' => 'fa-comment', 'route' => 'admin.comments.index'],
            ['label' => __('Categories'), 'value' => $systemInfo['total_categories'], 'icon' => 'fa-folder', 'route' => 'admin.categories.index'],
            ['label' => __('Plans'), 'value' => $systemInfo['total_plans'], 'icon' => 'fa-toolbox', 'route' => 'admin.plans.index'],
            ['label' => __('Subscriptions'), 'value' => $systemInfo['total_subs'], 'icon' => 'fa-crown', 'route' => 'admin.subscriptions.index'],
        ] as $stat)
            <div class="col-6 col-md-4 col-xxl-2">
                <a href="{{ route($stat['route']) }}" class="text-decoration-none">
                    <div class="avd-settings-card p-3">
                        <i class="fa-solid {{ $stat['icon'] }} text-primary mb-2"></i>
                        <div class="fw-black">{{ number_format((int)$stat['value']) }}</div>
                        <div class="small text-muted">{{ $display($stat['label']) }}</div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <!-- Settings Category Filter Pills -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2 px-1">
        <div class="d-flex align-items-center gap-1.5 flex-wrap" id="settingsCategoryFilters">
            <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 py-1 settings-cat-btn active" data-group="all">
                <i class="fa-solid fa-layer-group me-1"></i> {{ __('All Settings') }} <span class="badge bg-white text-primary rounded-pill ms-1">19</span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2.5 py-1 settings-cat-btn" data-group="core">
                <i class="fa-solid fa-gear me-1 text-primary"></i> {{ __('Core & Admin') }} <span class="badge bg-secondary-subtle text-body rounded-pill ms-1">6</span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2.5 py-1 settings-cat-btn" data-group="design">
                <i class="fa-solid fa-palette me-1 text-info"></i> {{ __('Design & Feeds') }} <span class="badge bg-secondary-subtle text-body rounded-pill ms-1">3</span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2.5 py-1 settings-cat-btn" data-group="media">
                <i class="fa-solid fa-video me-1 text-danger"></i> {{ __('Media & Users') }} <span class="badge bg-secondary-subtle text-body rounded-pill ms-1">4</span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2.5 py-1 settings-cat-btn" data-group="commerce">
                <i class="fa-solid fa-sack-dollar me-1 text-warning"></i> {{ __('Commerce & Growth') }} <span class="badge bg-secondary-subtle text-body rounded-pill ms-1">6</span>
            </button>
        </div>
        <div class="small text-muted d-none d-md-flex align-items-center gap-1">
            <i class="fa-solid fa-bolt text-warning"></i>
            <span>{{ __('Instant live tools, presets & diagnostic helpers enabled') }}</span>
        </div>
    </div>

    <div class="avd-settings-tabs mb-3">
        <ul class="nav" id="settingsTabs" role="tablist">
            @foreach($tabs as $tab)
                <li class="nav-item settings-tab-item" role="presentation" data-group="{{ $tab['group'] }}">
                    <button class="nav-link {{ $activeTab === $tab['id'] ? 'active' : '' }}"
                        id="tab-button-{{ $tab['id'] }}"
                        data-bs-toggle="tab"
                        data-bs-target="#tab-{{ $tab['id'] }}"
                        data-settings-tab="{{ $tab['id'] }}"
                        type="button"
                        role="tab"
                        aria-controls="tab-{{ $tab['id'] }}"
                        aria-selected="{{ $activeTab === $tab['id'] ? 'true' : 'false' }}">
                        <i class="fa-solid fa-{{ $tab['icon'] }}"></i><span class="avd-tab-label">{{ $tab['label'] }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="tab-content">
        <div class="tab-pane fade {{ $activeTab === 'general' ? 'show active' : '' }}" id="tab-general" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'general') }}" method="POST">
                @csrf @method('PUT')
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="avd-section-title mb-0"><i class="fa-solid fa-signature text-primary"></i> {{__('Site Identity & Branding')}}</div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1">Core Identity</span>
                </div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="generalSiteNameInput">{{__('Site Name')}}</label>
                        <input type="text" name="site_name" id="generalSiteNameInput" class="form-control" value="{{ $value('general', 'site_name', config('app.name')) }}" required>
                        <div class="form-text">{{ __('The public name of your portal, shown in headers, page titles, and system emails.') }}</div>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="contact_email">{{__('Contact Email')}}</label>
                        <input id="contact_email" type="email" name="contact_email" class="form-control" value="{{ $value('general', 'contact_email') }}">
                        <div class="form-text">{{ __('Default email address for user contact requests and admin notices.') }}</div>
                    </div>
                    <div class="avd-field-span">
                        <label class="form-label fw-bold" for="site_description">{{__('Site Description')}}</label>
                        <textarea id="site_description" name="site_description" class="form-control" rows="2">{{ $value('general', 'site_description') }}</textarea>
                        <div class="form-text">{{ __('Primary overview of your video platform for search engines and open graph cards.') }}</div>
                    </div>
                    <div class="avd-field-span">
                        <label class="form-label fw-bold" for="site_keywords">{{__('Site Keywords')}}</label>
                        <input id="site_keywords" type="text" name="site_keywords" class="form-control" value="{{ $value('general', 'site_keywords') }}" placeholder="video, streaming, clips, media">
                        <div class="form-text">{{ __('Comma-separated meta keywords for discovery indexing.') }}</div>
                    </div>
                </div>

                <div class="avd-section-title mt-4"><i class="fa-solid fa-list-ol text-primary"></i> {{__('Catalog Pagination & Widget Limits')}}</div>
                <p class="form-text mt-0 mb-3">{{__('Configure items loaded per page across public feeds, profile sidebars, and discovery grids.')}}</p>

                <div class="row g-3 mb-3">
                    <div class="col-lg-6">
                        <div class="card h-100 border p-3 bg-body-tertiary">
                            <h6 class="fw-bold mb-2 text-primary small text-uppercase"><i class="fa-solid fa-table-cells me-1"></i> {{ __('Feed & Result Pagination') }}</h6>
                            <div class="avd-field-grid">
                                <div>
                                    <label class="form-label fw-bold" for="per_page_videos">{{ __('Videos Per Page') }}</label>
                                    <input id="per_page_videos" type="number" name="per_page_videos" class="form-control" value="{{ $value('general', 'per_page_videos', 24) }}" min="1">
                                    <div class="form-text small">{{ __('Home, category & trend feeds') }}</div>
                                </div>
                                <div>
                                    <label class="form-label fw-bold" for="per_page_comments">{{ __('Comments Per Page') }}</label>
                                    <input id="per_page_comments" type="number" name="per_page_comments" class="form-control" value="{{ $value('general', 'per_page_comments', 20) }}" min="1">
                                    <div class="form-text small">{{ __('Top-level comments per video') }}</div>
                                </div>
                                <div>
                                    <label class="form-label fw-bold" for="per_page_users">{{ __('Users Per Page') }}</label>
                                    <input id="per_page_users" type="number" name="per_page_users" class="form-control" value="{{ $value('general', 'per_page_users', 15) }}" min="1">
                                    <div class="form-text small">{{ __('Creators index directory') }}</div>
                                </div>
                                <div>
                                    <label class="form-label fw-bold" for="per_page_search">{{ __('Search Results') }}</label>
                                    <input id="per_page_search" type="number" name="per_page_search" class="form-control" value="{{ $value('general', 'per_page_search', 20) }}" min="1">
                                    <div class="form-text small">{{ __('Items per search query') }}</div>
                                </div>
                                <div>
                                    <label class="form-label fw-bold" for="per_page_activity">{{ __('Activity Items') }}</label>
                                    <input id="per_page_activity" type="number" name="per_page_activity" class="form-control" value="{{ $value('general', 'per_page_activity', 12) }}" min="1">
                                    <div class="form-text small">{{ __('Profile activity cards') }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card h-100 border p-3 bg-body-tertiary">
                            <h6 class="fw-bold mb-2 text-info small text-uppercase"><i class="fa-solid fa-id-badge me-1"></i> {{ __('Profile & Preview Limits') }}</h6>
                            <div class="avd-field-grid">
                                <div>
                                    <label class="form-label fw-bold" for="profile_videos_limit">{{ __('Profile Video Preview') }}</label>
                                    <input id="profile_videos_limit" type="number" name="profile_videos_limit" class="form-control" value="{{ $value('general', 'profile_videos_limit', 8) }}" min="1">
                                    <div class="form-text small">{{ __('Channel home tab video cards') }}</div>
                                </div>
                                <div>
                                    <label class="form-label fw-bold" for="profile_playlists_limit">{{ __('Profile Playlist Preview') }}</label>
                                    <input id="profile_playlists_limit" type="number" name="profile_playlists_limit" class="form-control" value="{{ $value('general', 'profile_playlists_limit', 6) }}" min="1">
                                    <div class="form-text small">{{ __('Channel home tab playlist cards') }}</div>
                                </div>
                                <div>
                                    <label class="form-label fw-bold" for="dashboard_widget_limit">{{ __('Dashboard Widgets') }}</label>
                                    <input id="dashboard_widget_limit" type="number" name="dashboard_widget_limit" class="form-control" value="{{ $value('general', 'dashboard_widget_limit', 5) }}" min="1">
                                    <div class="form-text small">{{ __('Quick rows on admin overview') }}</div>
                                </div>
                                <div>
                                    <label class="form-label fw-bold" for="discover_users_limit">{{ __('Discover Users') }}</label>
                                    <input id="discover_users_limit" type="number" name="discover_users_limit" class="form-control" value="{{ $value('general', 'discover_users_limit', 100) }}" min="1">
                                    <div class="form-text small">{{ __('Max creators in discovery pool') }}</div>
                                </div>
                                <div>
                                    <label class="form-label fw-bold" for="search_suggestions_limit">{{ __('Search Suggestions') }}</label>
                                    <input id="search_suggestions_limit" type="number" name="search_suggestions_limit" class="form-control" value="{{ $value('general', 'search_suggestions_limit', 10) }}" min="1">
                                    <div class="form-text small">{{ __('Autocomplete dropdown items') }}</div>
                                </div>
                                <div>
                                    <label class="form-label fw-bold" for="suggested_videos_count">{{ __('Watch Sidebar Videos') }}</label>
                                    <input id="suggested_videos_count" type="number" name="suggested_videos_count" class="form-control" value="{{ $value('general', 'suggested_videos_count', 9) }}" min="1">
                                    <div class="form-text small">{{ __('Related videos beside player') }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border mb-3 p-3 bg-body-tertiary">
                    <h6 class="fw-bold mb-2 text-warning small text-uppercase"><i class="fa-solid fa-clock-rotate-left me-1"></i> {{ __('Search History & Auto-Purge Rules') }}</h6>
                    <div class="avd-field-grid">
                        <div>
                            <label class="form-label fw-bold" for="search_history_retention_days">{{__('Retention Days')}}</label>
                            <input id="search_history_retention_days" type="number" name="search_history_retention_days" class="form-control" value="{{ $value('general', 'search_history_retention_days', $searchHistoryRetentionDefaults['retention_days']) }}" min="1" max="365">
                            <div class="form-text small">{{ __('Days to preserve search queries before automatic purge.') }}</div>
                        </div>
                        <div>
                            <label class="form-label fw-bold" for="search_history_max_rows">{{__('Max Search History Rows')}}</label>
                            <input id="search_history_max_rows" type="number" name="search_history_max_rows" class="form-control" value="{{ $value('general', 'search_history_max_rows', $searchHistoryRetentionDefaults['max_rows']) }}" min="100" max="5000000">
                            <div class="form-text small">{{ __('Keeps search log database compact and fast.') }}</div>
                        </div>
                        <div>
                            <label class="form-label fw-bold" for="search_history_cleanup_interval_minutes">{{__('Cleanup Interval (Minutes)')}}</label>
                            <input id="search_history_cleanup_interval_minutes" type="number" name="search_history_cleanup_interval_minutes" class="form-control" value="{{ $value('general', 'search_history_cleanup_interval_minutes', $searchHistoryRetentionDefaults['cleanup_interval_minutes']) }}" min="10" max="10080">
                            <div class="form-text small">{{ __('Frequency of the scheduled search log cleanup task.') }}</div>
                        </div>
                    </div>
                </div>

                <div class="avd-section-title mt-4"><i class="fa-solid fa-toggle-on text-primary"></i> {{__('Feature & Access Switches')}}</div>
                <div class="avd-switch-grid">
                    @foreach([
                        ['registration_enabled', __('User Registration'), '1', __('Allow new visitors to register accounts on the platform.')],
                        ['allow_social_login', __('Social Sign-In'), '1', __('Enable OAuth buttons for Google and Facebook login.')],
                        ['allow_uploads', __('Creator Video Uploads'), '1', __('Permit verified users to upload videos through upload studio.')],
                        ['allow_embedding', __('Video Iframe Embedding'), '1', __('Allow external sites to embed videos via public player iframes.')],
                        ['show_trending', __('Trending Page'), '1', __('Display the dedicated /trend discovery feed in navigation.')],
                        ['show_history', __('Watch History Tracker'), '1', __('Keep track of recently watched videos for signed-in members.')],
                    ] as $toggle)
                        <label class="avd-switch-card">
                            <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('general', $toggle[0], $toggle[2]))>
                            <span>
                                <span class="fw-bold d-block">{{ $display($toggle[1]) }}</span>
                                <span class="text-muted small">{{ $toggle[3] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <button class="btn btn-primary mt-4 px-4 py-2" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save General Settings')}}</button>
            </form>
        </div><div class="tab-pane fade {{ $activeTab === 'appearance' ? 'show active' : '' }}" id="tab-appearance" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'appearance') }}" method="POST">
                @csrf @method('PUT')
                {{-- Legacy alias is retained in the schema/export contract but stays out of form submission. --}}
                <input type="hidden" name="mobile_bottom_dock_enabled" value="{{ $value('appearance', 'mobile_bottom_dock_enabled', $appearanceDefaults['mobile_bottom_dock_enabled'] ?? '1') }}" disabled>
                <div class="row g-4">
                    <div class="col-xl-8">
                        {{-- 1-CLICK MASTER EXPERIENCE PRESETS STUDIO --}}
                        <div class="mb-4 p-3 p-md-4 rounded-4 border position-relative overflow-hidden shadow-sm" style="background: linear-gradient(135deg, rgba(255, 61, 97, 0.05) 0%, rgba(24, 200, 216, 0.05) 100%), #11141d; border-color: rgba(255, 255, 255, 0.12) !important;">
                            @php
                                $activeMasterExpKey = (string) $value('appearance', 'active_master_experience', '');
                                if ($activeMasterExpKey === 'youtube_2026_pro') {
                                    $activeMasterExpKey = 'youtube_spotlight_pro';
                                }
                                if ($activeMasterExpKey === 'cyberpunk_hud_2077') {
                                    $activeMasterExpKey = 'cyberpunk_hud';
                                }
                                $currentThemePreset = (string) $value('appearance', 'theme_preset', $appearanceDefaults['theme_preset'] ?? 'cyberpunk_neon');
                                $currentHomeLayout = (string) $value('appearance', 'home_layout_mode', $appearanceDefaults['home_layout_mode'] ?? 'grid_modern');

                                if (!empty($activeMasterExpKey) && isset($masterExperiences[$activeMasterExpKey])) {
                                    $expectedPreset = $masterExperiences[$activeMasterExpKey]['theme_preset'] ?? '';
                                    if (!empty($expectedPreset) && $expectedPreset !== $currentThemePreset) {
                                        $activeMasterExpKey = '';
                                    }
                                }
                                if (empty($activeMasterExpKey)) {
                                    foreach ($masterExperiences as $mK => $mE) {
                                        if (($mE['theme_preset'] ?? '') === $currentThemePreset) {
                                            $activeMasterExpKey = $mK;
                                            break;
                                        }
                                    }
                                }
                                $isDefaultThemeActive = empty($activeMasterExpKey) || $activeMasterExpKey === 'default_factory';
                            @endphp
                            <input type="hidden" name="active_master_experience" value="{{ $activeMasterExpKey }}">

                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                                <div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-danger text-uppercase px-2.5 py-1 fw-bold rounded-pill" style="letter-spacing: 0.08em;">
                                            <i class="fa-solid fa-wand-magic-sparkles me-1"></i> {{ __('1-Click Master Experiences') }}
                                        </span>
                                        <span class="badge bg-secondary-subtle text-body-secondary rounded-pill small">{{ count($masterExperiences) }} {{ __('Studios') }}</span>
                                    </div>
                                    <h5 class="fw-bold mt-2 mb-1 text-white">{{ __('Complete Ecosystem Transformations') }}</h5>
                                    <p class="small text-muted mb-0">{{ __('Instantly transform your site into an entirely new experience. Combines theme colors, page layouts, card styles, physics engine, and modal archetypes with one click.') }}</p>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    @if($isDefaultThemeActive)
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2.5 py-1.5 small fw-semibold d-inline-flex align-items-center gap-1 shadow-sm">
                                            <i class="fa-solid fa-circle-check"></i> {{ __('Default Theme Active') }}
                                        </span>
                                    @endif
                                    <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-2 shadow-sm reset-default-theme-btn" title="{{ __('Reset theme, layout, and colors to factory defaults') }}">
                                        <i class="fa-solid fa-arrow-rotate-left"></i>
                                        <span>{{ __('Reset to Default Theme') }}</span>
                                    </button>
                                </div>
                            </div>

                            <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3" id="masterExperiencesGrid">
                                @foreach($masterExperiences as $expKey => $exp)
                                    @php
                                        $isActiveExp = ($activeMasterExpKey === $expKey);
                                    @endphp
                                    <div class="col">
                                        <div class="card h-100 border rounded-4 overflow-hidden position-relative shadow-sm transition-all master-experience-card {{ $isActiveExp ? 'active-master-card border-success' : '' }}"
                                             data-experience-id="{{ $expKey }}"
                                             data-theme-preset="{{ $exp['theme_preset'] }}"
                                             style="{{ $isActiveExp ? 'background: linear-gradient(135deg, rgba(34, 197, 94, 0.14), rgba(255,255,255,0.03)) !important; border-color: #22c55e !important; box-shadow: 0 0 25px rgba(34, 197, 94, 0.4) !important;' : 'background: rgba(255,255,255,0.03); border-color: rgba(255,255,255,0.08) !important;' }}">
                                            <div class="master-active-ribbon w-100 py-1 text-center fw-bold text-white shadow-sm" style="background: linear-gradient(90deg, #15803d, #22c55e, #15803d); font-size: 0.65rem; letter-spacing: 0.08em; z-index: 2; {{ $isActiveExp ? '' : 'display: none !important;' }}">
                                                <i class="fa-solid fa-circle-check me-1"></i> {{ __('CURRENTLY ACTIVE ECOSYSTEM') }}
                                            </div>
                                            <div class="card-body p-3 d-flex flex-column {{ $isActiveExp ? 'pt-2' : '' }}">
                                                <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                                    <span class="master-active-badge badge bg-success text-white rounded-pill px-2.5 py-1 small fw-bold d-inline-flex align-items-center gap-1 shadow-sm" style="{{ $isActiveExp ? '' : 'display: none !important;' }}">
                                                        <i class="fa-solid fa-circle-check"></i> {{ __('Active Now') }}
                                                    </span>
                                                    <span class="master-regular-badge badge bg-{{ $exp['badge_color'] ?? 'primary' }} text-white rounded-pill px-2 py-0.5 small fw-bold" style="{{ $isActiveExp ? 'display: none !important;' : '' }}">
                                                        {{ __($exp['badge'] ?? 'Featured') }}
                                                    </span>
                                                    <i class="master-card-icon fa-solid fa-{{ $exp['icon'] ?? 'star' }} {{ $isActiveExp ? 'text-success' : 'text-white-50' }}"></i>
                                                </div>
                                                <h6 class="fw-bold text-white mb-1 text-truncate" title="{{ __($exp['label']) }}">{{ __($exp['label']) }}</h6>
                                                <p class="small text-muted mb-3 flex-grow-1" style="font-size: 0.76rem; min-height: 34px; line-height: 1.35;">
                                                    {{ __($exp['subtitle']) }}
                                                </p>
                                                <div class="d-flex flex-wrap gap-1 mb-3">
                                                    <span class="master-chip-badge badge border {{ $isActiveExp ? 'bg-success-subtle text-success border-success' : 'bg-body-tertiary text-muted' }}" style="font-size: 0.65rem;">
                                                        <i class="fa-solid fa-palette me-1"></i> {{ Str::headline($exp['theme_preset']) }}
                                                    </span>
                                                    <span class="master-chip-badge badge border {{ $isActiveExp ? 'bg-success-subtle text-success border-success' : 'bg-body-tertiary text-muted' }}" style="font-size: 0.65rem;">
                                                        <i class="fa-solid fa-table-cells me-1"></i> {{ Str::headline($exp['home_layout_mode']) }}
                                                    </span>
                                                    <span class="master-chip-badge badge border {{ $isActiveExp ? 'bg-success-subtle text-success border-success' : 'bg-body-tertiary text-muted' }}" style="font-size: 0.65rem;">
                                                        <i class="fa-solid fa-film me-1"></i> {{ Str::headline($exp['watch_layout_mode']) }}
                                                    </span>
                                                </div>
                                                <div class="master-btn-container mt-auto">
                                                    @if($isActiveExp)
                                                        <button type="button" class="btn btn-sm btn-success rounded-pill w-100 fw-bold py-1.5 shadow-sm active-experience-btn" disabled style="opacity: 1; cursor: default; pointer-events: none;">
                                                            <i class="fa-solid fa-check-double me-1"></i> {{ __('Currently Active') }}
                                                        </button>
                                                    @else
                                                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill w-100 fw-semibold apply-experience-btn py-1.5" data-experience-id="{{ $expKey }}">
                                                            <i class="fa-solid fa-bolt me-1"></i> {{ __('Activate') }}
                                                        </button>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <!-- 365-Day Daily Auto-Design Engine Studio Card -->
                        @php
                            $curDailyDesignToday = $dailyDesignToday ?? \App\Support\DailyDesignCatalog::today();
                            $curDailyDesignScopes = $dailyDesignScopes ?? \App\Support\AppearanceSettings::dailyDesignScopes();
                            $curDailyDesignMonths = $dailyDesignMonths ?? \App\Support\DailyDesignCatalog::months();
                            $curDailyDesignCatalog = $dailyDesignCatalog ?? \App\Support\DailyDesignCatalog::all();
                        @endphp
                        <div class="mb-4">
                            <div class="p-3 p-md-4 rounded-4 border position-relative shadow-sm" style="background: linear-gradient(135deg, rgba(20, 184, 166, 0.08), rgba(99, 102, 241, 0.09)); border-color: rgba(99, 102, 241, 0.28) !important;">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1.5">
                                            <span class="badge rounded-pill text-white px-2.5 py-1 fw-bold shadow-sm" style="background: linear-gradient(135deg, #6366f1, #06b6d4); font-size: 0.72rem;">
                                                <i class="fa-solid fa-calendar-days me-1"></i>365 DAYS / 365 DESIGNS
                                            </span>
                                            <span class="badge bg-body-tertiary text-muted border rounded-pill px-2 py-0.5 small">
                                                {{ __('Deterministic Calendar Engine') }}
                                            </span>
                                        </div>
                                        <h5 class="fw-bold mb-1 text-white d-flex align-items-center gap-2">
                                            <i class="fa-solid fa-clock-rotate-left text-info"></i>
                                            {{ __('Daily Auto-Design Engine') }}
                                        </h5>
                                        <div class="small text-muted">
                                            {{ __('Every day of the year automatically adopts a unique, non-repeating handcrafted design with seasonal arcs and holiday specials.') }}
                                        </div>
                                    </div>

                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3 py-1.5 d-inline-flex align-items-center gap-1.5 shadow-sm" data-bs-toggle="modal" data-bs-target="#dailyCalendarModal">
                                            <i class="fa-solid fa-calendar-alt"></i>
                                            <span class="fw-semibold">{{ __('Browse 365-Day Calendar') }}</span>
                                        </button>
                                        <label class="avd-switch-card py-1.5 px-3 rounded-pill mb-0 border" style="background: rgba(255,255,255,0.05); border-color: rgba(99, 102, 241, 0.35) !important;">
                                            <input class="form-check-input me-2" type="checkbox" name="daily_design_auto_enabled" id="dailyDesignAutoEnabledCheck" value="1" @checked($isChecked('appearance', 'daily_design_auto_enabled', $appearanceDefaults['daily_design_auto_enabled']))>
                                            <span class="small fw-bold text-white">{{ __('Enable Auto Daily Rotation') }}</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Today's Active Identity Showcase -->
                                <div class="row g-3 align-items-center p-3 rounded-3 border mb-3" style="background: rgba(15, 23, 42, 0.65); border-color: rgba(255,255,255,0.08) !important;">
                                    <div class="col-lg-5">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="rounded-circle d-flex align-items-center justify-content-center shadow-lg flex-shrink-0" style="width: 48px; height: 48px; background: linear-gradient(135deg, {{ $curDailyDesignToday['primary'] }}, {{ $curDailyDesignToday['accent'] }});">
                                                <i class="fa-solid fa-wand-magic-sparkles text-white fs-5"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="d-flex flex-wrap align-items-center gap-1.5 mb-1">
                                                    <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2 py-0.5 fw-bold" style="font-size: 0.68rem;">
                                                        {{ __('Day') }} {{ $curDailyDesignToday['day'] }}/366 ({{ $curDailyDesignToday['date_label'] }})
                                                    </span>
                                                    <span class="badge rounded-pill bg-dark border text-white-50 px-2 py-0.5" style="font-size: 0.66rem;">
                                                        {{ $curDailyDesignToday['season_mood'] }}
                                                    </span>
                                                    @if(!empty($curDailyDesignToday['holiday']))
                                                        <span class="badge rounded-pill bg-warning text-dark px-2 py-0.5 fw-bold" style="font-size: 0.66rem;">
                                                            <i class="fa-solid fa-star me-1"></i> {{ $curDailyDesignToday['holiday'] }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <h6 class="fw-bold text-white mb-0 text-truncate" id="activeDailyDesignName">{{ $curDailyDesignToday['name'] }}</h6>
                                                <div class="small text-muted" style="font-size: 0.73rem;">
                                                    {{ __('Layout:') }} <strong class="text-info">{{ Str::headline($curDailyDesignToday['home_layout_mode']) }}</strong> • {{ __('Surface:') }} <strong>{{ Str::headline($curDailyDesignToday['surface_style']) }}</strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-lg-3">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="rounded-circle border" style="width: 22px; height: 22px; background: {{ $curDailyDesignToday['primary'] }};" title="Primary: {{ $curDailyDesignToday['primary'] }}"></span>
                                            <span class="rounded-circle border" style="width: 22px; height: 22px; background: {{ $curDailyDesignToday['secondary'] }};" title="Secondary: {{ $curDailyDesignToday['secondary'] }}"></span>
                                            <span class="rounded-circle border" style="width: 22px; height: 22px; background: {{ $curDailyDesignToday['accent'] }};" title="Accent: {{ $curDailyDesignToday['accent'] }}"></span>
                                            <div class="small text-muted ms-1 font-monospace" style="font-size: 0.72rem;">
                                                {{ $curDailyDesignToday['primary'] }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-lg-4">
                                        <div class="d-flex align-items-center justify-content-lg-end gap-2">
                                            <label class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" name="daily_design_show_badge" id="dailyDesignShowBadgeCheck" value="1" @checked($isChecked('appearance', 'daily_design_show_badge', $appearanceDefaults['daily_design_show_badge']))>
                                                <span class="form-check-label small text-muted">{{ __('Show Frontend Badge') }}</span>
                                            </label>
                                            <label class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" name="daily_design_atmosphere_particles" id="dailyDesignAtmosphereParticlesCheck" value="1" @checked($isChecked('appearance', 'daily_design_atmosphere_particles', $appearanceDefaults['daily_design_atmosphere_particles'] ?? '1'))>
                                                <span class="form-check-label small text-muted">{{ __('Atmosphere Particles') }}</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Rotation Scope Settings -->
                                <div class="row g-3">
                                    <div class="col-md-7">
                                        <label class="form-label small fw-bold text-muted text-uppercase mb-1" for="dailyDesignScopeSelect">{{ __('Rotation Scope') }}</label>
                                        <select name="daily_design_scope" class="form-select" id="dailyDesignScopeSelect">
                                            @foreach($curDailyDesignScopes as $scopeKey => $scopeLabel)
                                                <option value="{{ $scopeKey }}" @selected((string)$value('appearance', 'daily_design_scope', $appearanceDefaults['daily_design_scope']) === $scopeKey)>
                                                    {{ $scopeLabel }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label small fw-bold text-muted text-uppercase mb-1" for="dailyDesignOverrideInput">{{ __('Calendar Test / Override Day (0 = Today)') }}</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fa-solid fa-calendar-day"></i></span>
                                            <input type="number" name="daily_design_override_day" id="dailyDesignOverrideInput" class="form-control" min="0" max="366" value="{{ (int)$value('appearance', 'daily_design_override_day', 0) }}" placeholder="0 for auto calendar date">
                                        </div>
                                        <div class="form-text small text-muted" style="font-size: 0.7rem;">{{ __('Set 0 for natural day-of-year sync, or 1-366 to test any specific day.') }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="avd-section-title"><i class="fa-solid fa-wand-magic-sparkles text-primary"></i> {{__('Design system')}}</div>
                        <div class="avd-field-grid">
                            <div class="avd-field-span">
                                <div class="p-3 p-md-4 rounded-3 border position-relative" style="background: rgba(var(--avd-brand-rgb, 255, 61, 97), 0.04); border-color: rgba(var(--avd-brand-rgb, 255, 61, 97), 0.2) !important;">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                                        <div>
                                            <div class="form-label fw-bold mb-0 fs-6">
                                                <i class="fa-solid fa-palette text-primary me-2"></i> {{__('Curated Theme Presets')}}
                                                <span class="badge bg-primary text-white rounded-pill ms-2 px-2.5 py-1">120 {{__('Themes')}}</span>
                                            </div>
                                            <div class="small text-muted mt-1">{{__('Choose from 12 distinct aesthetic worlds or launch the visual gallery to preview and apply any theme with one click.')}}</div>
                                        </div>
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1.5 d-inline-flex align-items-center gap-1.5 reset-default-theme-btn" title="{{ __('Reset Theme to Factory Default') }}">
                                                <i class="fa-solid fa-arrow-rotate-left"></i>
                                                <span class="fw-semibold">{{__('Reset to Default')}}</span>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm d-inline-flex align-items-center gap-1.5" data-bs-toggle="modal" data-bs-target="#themeGalleryModal">
                                                <i class="fa-solid fa-wand-magic-sparkles"></i>
                                                <span class="fw-semibold">{{__('Browse Visual Gallery (120 Themes)')}}</span>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-2.5 d-inline-flex align-items-center gap-1" id="btnThemeJsonSpec" data-bs-toggle="modal" data-bs-target="#themeJsonSpecModal" title="{{__('Theme JSON Spec Hub')}}">
                                                <i class="fa-solid fa-code"></i>
                                                <span class="d-none d-sm-inline">{{__('Theme JSON')}}</span>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2.5 d-inline-flex align-items-center gap-1" id="randomThemeBtn" title="{{__('Surprise Me with a Random Theme')}}">
                                                <i class="fa-solid fa-dice"></i>
                                                <span class="d-none d-sm-inline">{{__('Random')}}</span>
                                            </button>
                                            <label class="form-check form-switch mb-0 ms-2">
                                                <input class="form-check-input" type="checkbox" name="apply_theme_preset" value="1" id="applyThemePresetCheck" checked>
                                                <span class="form-check-label small fw-bold text-primary">{{__('Sync Styles on Save')}}</span>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Category Quick Filter Strip -->
                                    <div class="mb-3">
                                        <div class="d-flex align-items-center gap-1.5 overflow-x-auto pb-1" id="categoryQuickFilterStrip" style="scrollbar-width: thin;">
                                            <button type="button" class="btn btn-xs rounded-pill px-2.5 py-1 fw-bold text-nowrap btn-primary active cat-quick-filter-btn" data-category="all">
                                                <i class="fa-solid fa-layer-group me-1"></i> {{ __('All (120)') }}
                                            </button>
                                            @php
                                                $catIcons = [
                                                    'Cyberpunk & Tokyo Neon' => 'fa-bolt text-warning',
                                                    'Deep Space & Astronomy' => 'fa-satellite text-info',
                                                    'Luxury, Gold & Obsidian' => 'fa-crown text-warning',
                                                    'Oceanic & Deep Sea' => 'fa-water text-primary',
                                                    'Nature & Botanical' => 'fa-leaf text-success',
                                                    'Gaming & Esports' => 'fa-gamepad text-danger',
                                                    'Anime & Pastel Dream' => 'fa-heart text-danger',
                                                    'Minimalist & Swiss Studio' => 'fa-square-minus text-secondary',
                                                    'Dark OLED & Stealth' => 'fa-moon text-dark',
                                                    'Developer & Code Syntax' => 'fa-code text-info',
                                                    'Modern Light & Daybreak' => 'fa-sun text-warning',
                                                    'Sci-Fi & Holographic' => 'fa-atom text-info',
                                                ];
                                            @endphp
                                            @foreach($themePresetsByCategory as $categoryName => $catPresets)
                                                <button type="button" class="btn btn-xs rounded-pill px-2.5 py-1 fw-semibold text-nowrap btn-outline-secondary cat-quick-filter-btn" data-category="{{ $categoryName }}">
                                                    <i class="fa-solid {{ $catIcons[$categoryName] ?? 'fa-palette' }} me-1"></i> {{ $categoryName }} ({{ count($catPresets) }})
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>

                                    <!-- Mood & Color Temperature Quick-Filter Strip -->
                                    <div class="mb-3 d-flex flex-wrap align-items-center gap-1.5" id="moodQuickFilterStrip">
                                        <span class="small text-muted fw-bold me-1"><i class="fa-solid fa-temperature-half me-1 text-primary"></i> {{ __('Mood / Temp:') }}</span>
                                        <button type="button" class="btn btn-xs rounded-pill px-2.5 py-0.5 fw-semibold active btn-primary mood-filter-btn" data-mood="all">
                                            {{ __('All') }}
                                        </button>
                                        <button type="button" class="btn btn-xs rounded-pill px-2.5 py-0.5 fw-semibold btn-outline-secondary mood-filter-btn" data-mood="warm">
                                            <i class="fa-solid fa-fire text-warning me-1"></i> {{ __('Warm / Amber') }}
                                        </button>
                                        <button type="button" class="btn btn-xs rounded-pill px-2.5 py-0.5 fw-semibold btn-outline-secondary mood-filter-btn" data-mood="cool">
                                            <i class="fa-solid fa-snowflake text-info me-1"></i> {{ __('Cool / Frost') }}
                                        </button>
                                        <button type="button" class="btn btn-xs rounded-pill px-2.5 py-0.5 fw-semibold btn-outline-secondary mood-filter-btn" data-mood="cyber">
                                            <i class="fa-solid fa-bolt text-danger me-1"></i> {{ __('Cyber / Neon') }}
                                        </button>
                                        <button type="button" class="btn btn-xs rounded-pill px-2.5 py-0.5 fw-semibold btn-outline-secondary mood-filter-btn" data-mood="pastel">
                                            <i class="fa-solid fa-heart me-1" style="color: #f472b6;"></i> {{ __('Pastel & Pop') }}
                                        </button>
                                        <button type="button" class="btn btn-xs rounded-pill px-2.5 py-0.5 fw-semibold btn-outline-secondary mood-filter-btn" data-mood="mono">
                                            <i class="fa-solid fa-moon text-secondary me-1"></i> {{ __('OLED & Mono') }}
                                        </button>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-md-7">
                                            <label class="form-label small fw-bold text-muted text-uppercase mb-1" for="themePresetSelect">{{__('Selected Theme Preset')}}</label>
                                            <select name="theme_preset" class="form-select" id="themePresetSelect">
                                                @foreach($themePresetsByCategory as $categoryName => $catPresets)
                                                    <optgroup label="{{ $categoryName }} ({{ count($catPresets) }})">
                                                        @foreach($catPresets as $key => $preset)
                                                            <option value="{{ $key }}"
                                                                @selected((string)$value('appearance', 'theme_preset', $appearanceDefaults['theme_preset']) === $key)
                                                                data-primary="{{ $preset['primary'] }}"
                                                                data-secondary="{{ $preset['secondary'] }}"
                                                                data-accent="{{ $preset['accent'] }}"
                                                                data-dark-bg="{{ $preset['dark_bg_color'] ?? '#070913' }}"
                                                                data-dark-surface="{{ $preset['dark_surface_color'] ?? '#0e1322' }}"
                                                                data-light-bg="{{ $preset['light_bg_color'] ?? '#f8fafc' }}"
                                                                data-light-surface="{{ $preset['light_surface_color'] ?? '#ffffff' }}"
                                                                data-theme="{{ $preset['theme'] }}"
                                                                data-oled="{{ $preset['theme_oled_black_enabled'] }}"
                                                                data-surface="{{ $preset['surface_style'] }}"
                                                                data-radius="{{ $preset['radius_scale'] }}"
                                                                data-font="{{ $preset['font_family'] }}"
                                                                data-header="{{ $preset['header_style'] }}"
                                                                data-category-card="{{ $preset['category_card_style'] }}"
                                                                data-avatar-ring="{{ $preset['creator_avatar_ring_style'] }}"
                                                                data-duration="{{ $preset['video_card_duration_style'] }}"
                                                                data-ambient-glow="{{ $preset['watch_ambient_glow_intensity'] }}"
                                                                data-scrollbar="{{ $preset['scrollbar_style'] }}"
                                                                data-card-layout="{{ $preset['video_card_layout_style'] ?? 'card_standard' }}"
                                                                data-hero-layout="{{ $preset['hero_layout_mode'] ?? 'classic_banner' }}"
                                                                data-back-to-top-style="{{ $preset['back_to_top_style'] ?? 'floating_pill' }}"
                                                                data-atmosphere="{{ $preset['background_atmosphere_effect'] ?? 'clean_none' }}"
                                                                data-home-layout="{{ $preset['home_layout_mode'] ?? 'grid_modern' }}"
                                                                data-watch-layout="{{ $preset['watch_layout_mode'] ?? 'classic_two_column' }}"
                                                                data-modal-style="{{ $preset['modal_presentation_style'] ?? 'modal_glassmorphic_center' }}"
                                                                data-motion-engine="{{ $preset['motion_engine_level'] ?? 'smooth_fluid' }}"
                                                                data-typo-pairing="{{ $preset['typography_pairing'] ?? 'modern_tech' }}"
                                                                data-tilt-3d="{{ $preset['interactive_3d_card_tilt_enabled'] ?? '1' }}"
                                                                data-cinema-diffusion="{{ $preset['cinema_ambient_light_diffusion_enabled'] ?? '1' }}"
                                                                data-mobile-columns="{{ $preset['mobile_video_columns'] ?? '2' }}"
                                                                data-mobile-density="{{ $preset['mobile_video_card_density'] ?? 'compact' }}"
                                                                data-mobile-bottom-nav="{{ $preset['mobile_bottom_nav_enabled'] ?? '1' }}"
                                                                data-mobile-tap-preview="{{ $preset['mobile_tap_preview_enabled'] ?? '1' }}"
                                                                data-hero-autoplay="{{ $preset['hero_slider_autoplay_enabled'] ?? '1' }}"
                                                                data-hero-autoplay-seconds="{{ $preset['hero_slider_autoplay_seconds'] ?? '5' }}"
                                                                data-title-lines="{{ $preset['video_card_title_lines'] ?? '2' }}"
                                                                data-category="{{ $preset['category'] }}"
                                                                data-label="{{ $preset['label'] }}"
                                                                data-description="{{ $preset['description'] }}">
                                                                {{ (string)$value('appearance', 'theme_preset', $appearanceDefaults['theme_preset']) === $key ? '✓ [ACTIVE] ' : '' }}{{ $preset['label'] }} — [{{ $preset['primary'] }}, {{ $preset['secondary'] }}, {{ $preset['accent'] }}]
                                                            </option>
                                                        @endforeach
                                                    </optgroup>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-5">
                                            <div class="form-label small fw-bold text-muted text-uppercase mb-1">{{__('Display Contrast')}}</div>
                                            <label class="avd-switch-card h-100 py-2">
                                                <input class="form-check-input" type="checkbox" name="theme_oled_black_enabled" id="themeOledBlackCheck" value="1" @checked($isChecked('appearance', 'theme_oled_black_enabled', $appearanceDefaults['theme_oled_black_enabled']))>
                                                <span class="small">
                                                    <span class="fw-bold d-block"><i class="fa-solid fa-circle-half-stroke me-1 text-primary"></i> {{__('OLED Pure Black')}}</span>
                                                    <span class="text-muted">{{__('True #000000 black canvas')}}</span>
                                                </span>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Active Theme Live Details & Palette Strip -->
                                    <div class="mt-3 p-2.5 rounded-3 border bg-body" id="activeThemeStrip" style="border-left: 4px solid #22c55e !important;">
                                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-success text-white rounded-pill px-2.5 py-1 small fw-bold shadow-sm">
                                                    <i class="fa-solid fa-circle-check me-1"></i> {{ __('CURRENTLY ACTIVE') }}
                                                </span>
                                                <span class="fw-bold small text-truncate" id="activeThemeTitle" style="max-width: 260px;">Cyberpunk Neon</span>
                                                <span class="badge bg-secondary-subtle text-body-secondary rounded-pill small" id="activeThemeCategory">Cyberpunk & Tokyo Neon</span>
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="small text-muted fw-semibold me-1">{{__('Palette (Click to Copy):')}}</span>
                                                <div class="d-inline-flex align-items-center gap-1.5 p-1 rounded-pill bg-body-tertiary border">
                                                    <span class="d-inline-block rounded-circle active-swatch-dot" id="activeDotPrimary" style="width: 16px; height: 16px; background: #ff3d61; cursor: pointer; transition: transform 0.15s ease;" title="{{ __('Click to copy Primary Hex') }}"></span>
                                                    <span class="d-inline-block rounded-circle active-swatch-dot" id="activeDotSecondary" style="width: 16px; height: 16px; background: #ff8b5c; cursor: pointer; transition: transform 0.15s ease;" title="{{ __('Click to copy Secondary Hex') }}"></span>
                                                    <span class="d-inline-block rounded-circle active-swatch-dot" id="activeDotAccent" style="width: 16px; height: 16px; background: #18c8d8; cursor: pointer; transition: transform 0.15s ease;" title="{{ __('Click to copy Accent Hex') }}"></span>
                                                    <span class="d-inline-block rounded-circle active-swatch-dot" id="activeDotDarkBg" style="width: 16px; height: 16px; background: #070913; border: 1px solid rgba(255,255,255,0.25); cursor: pointer; transition: transform 0.15s ease;" title="{{ __('Click to copy Dark BG Hex') }}"></span>
                                                    <span class="d-inline-block rounded-circle active-swatch-dot" id="activeDotDarkSurface" style="width: 16px; height: 16px; background: #0e1322; border: 1px solid rgba(255,255,255,0.25); cursor: pointer; transition: transform 0.15s ease;" title="{{ __('Click to copy Dark Surface Hex') }}"></span>
                                                    <span class="d-inline-block rounded-circle active-swatch-dot" id="activeDotLightBg" style="width: 16px; height: 16px; background: #f8fafc; border: 1px solid rgba(0,0,0,0.15); cursor: pointer; transition: transform 0.15s ease;" title="{{ __('Click to copy Light BG Hex') }}"></span>
                                                    <span class="d-inline-block rounded-circle active-swatch-dot" id="activeDotLightSurface" style="width: 16px; height: 16px; background: #ffffff; border: 1px solid rgba(0,0,0,0.15); cursor: pointer; transition: transform 0.15s ease;" title="{{ __('Click to copy Light Surface Hex') }}"></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mt-2 d-flex flex-wrap align-items-center gap-1.5 small text-muted">
                                            <span class="badge border bg-body-secondary text-body" id="activeBadgeSurface"><i class="fa-solid fa-cube me-1"></i>Surface: Glass</span>
                                            <span class="badge border bg-body-secondary text-body" id="activeBadgeRadius"><i class="fa-solid fa-shapes me-1"></i>Radius: Balanced</span>
                                            <span class="badge border bg-body-secondary text-body" id="activeBadgeFont"><i class="fa-solid fa-font me-1"></i>Font: Modern</span>
                                            <span class="badge border bg-body-secondary text-body" id="activeBadgeHeader"><i class="fa-solid fa-window-maximize me-1"></i>Header: Glass Blur</span>
                                            <span class="text-truncate ms-auto text-muted small fst-italic" id="activeThemeDesc" style="max-width: 320px;">Hot crimson pink with neon amber highlights</span>
                                        </div>
                                    </div>

                                    <!-- Real-Time Interactive Theme Playground & Live Studio Stage -->
                                    <div class="mt-3 p-3 rounded-3 border bg-body-tertiary" id="liveThemeStudioStage">
                                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2.5 pb-2 border-bottom">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2.5 py-1">
                                                    <i class="fa-solid fa-wand-magic-sparkles me-1"></i> {{ __('REAL-TIME DESIGN PREVIEW') }}
                                                </span>
                                                <span class="small text-muted">{{ __('Live interactive preview of video card, player header, and contrast health') }}</span>
                                            </div>
                                            <!-- Studio Controls: Canvas Mode Toggle, Viewport Switcher, Card Style & Harmonizer -->
                                            <div class="d-flex flex-wrap align-items-center gap-2" id="wcagContrastAuditContainer">
                                                <!-- Dark / Light Canvas Mode Switcher -->
                                                <div class="btn-group btn-group-sm rounded-pill p-0.5 border" role="group" id="liveStudioCanvasToggle" style="background: var(--avd-surface-alt, rgba(255,255,255,0.06));">
                                                    <button type="button" class="btn btn-xs rounded-pill px-2.5 py-1 active fw-semibold" id="btnStudioCanvasDark" title="{{ __('Preview in Dark Mode Canvas') }}">
                                                        <i class="fa-solid fa-moon me-1 text-info"></i> {{ __('Dark Canvas') }}
                                                    </button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2.5 py-1 fw-semibold text-muted" id="btnStudioCanvasLight" title="{{ __('Preview in Light Mode Canvas') }}">
                                                        <i class="fa-solid fa-sun me-1 text-warning"></i> {{ __('Light Canvas') }}
                                                    </button>
                                                </div>

                                                <!-- Responsive Viewport Simulator Toggle -->
                                                <div class="btn-group btn-group-sm rounded-pill p-0.5 border d-none d-sm-inline-flex" role="group" id="liveStudioViewportToggle" style="background: var(--avd-surface-alt, rgba(255,255,255,0.06));">
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 active fw-semibold" id="btnStudioViewportDesktop" title="{{ __('Desktop View (100% Width)') }}">
                                                        <i class="fa-solid fa-desktop me-1"></i> {{ __('Desktop') }}
                                                    </button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted" id="btnStudioViewportMobile" title="{{ __('Mobile View (360px Feed)') }}">
                                                        <i class="fa-solid fa-mobile-screen me-1"></i> {{ __('Mobile') }}
                                                    </button>
                                                </div>

                                                <!-- Mini Card Layout Preview Switcher -->
                                                <div class="btn-group btn-group-sm rounded-pill p-0.5 border d-none d-md-inline-flex" role="group" id="liveStudioCardStyleToggle" style="background: var(--avd-surface-alt, rgba(255,255,255,0.06));">
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 active fw-semibold" data-card-style="card_bento_modern" title="Bento Modern">Bento</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted" data-card-style="card_standard" title="Standard YouTube">Standard</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted" data-card-style="card_glass_overlay" title="Glass Overlay">Glass</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted" data-card-style="card_hover_expand" title="Cinema Expand">Cinema</button>
                                                </div>

                                                <!-- Mini Typography Pairing Quick Switcher -->
                                                <div class="btn-group btn-group-sm rounded-pill p-0.5 border d-none d-lg-inline-flex" role="group" id="liveStudioTypoToggle" style="background: var(--avd-surface-alt, rgba(255,255,255,0.06));">
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 active fw-semibold" data-typo="modern_tech" title="Modern Tech (Outfit)">Tech</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted" data-typo="cyberpunk_future" title="Cyberpunk Future (Orbitron)">Cyber</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted" data-typo="hacker_mono" title="Hacker Mono (JetBrains Mono)">Mono</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted" data-typo="editorial_luxury" title="Editorial Luxury (Playfair Display)">Luxury</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted" data-typo="anime_pop" title="Anime Pop (Quicksand)">Anime</button>
                                                </div>

                                                <!-- Palette Harmonizer Button -->
                                                <button type="button" class="btn btn-xs btn-outline-primary rounded-pill px-2.5 py-1 fw-semibold d-inline-flex align-items-center gap-1 shadow-sm" id="btnOpenHarmonizerModal" data-bs-toggle="modal" data-bs-target="#paletteHarmonizerModal" title="{{ __('Harmonize Palette with Color Science') }}">
                                                    <i class="fa-solid fa-palette text-warning"></i>
                                                    <span>{{ __('Harmonizer') }}</span>
                                                </button>

                                                <!-- Dynamic WCAG 2.2 Contrast Ratio Audit Badge (Click to open full audit) -->
                                                <span class="badge rounded-pill px-2.5 py-1 fw-bold shadow-sm" id="wcagContrastBadge" role="button" data-bs-toggle="modal" data-bs-target="#wcagComplianceModal" style="background: rgba(34, 197, 94, 0.15); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.3); cursor: pointer;" title="{{ __('Click to inspect full WCAG 2.2 contrast compliance matrix') }}">
                                                    <i class="fa-solid fa-shield-halved me-1"></i><span id="wcagContrastText">WCAG AAA (12.8:1)</span>
                                                </span>
                                                <span class="badge rounded-pill px-2 py-1 bg-dark text-white border border-secondary small" id="wcagSurfaceBadge">
                                                    <i class="fa-solid fa-cube me-1"></i><span id="livePreviewSurfaceText">Glass Surface</span>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Atmosphere FX Dynamic Preview Strip -->
                                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2.5 pb-2 border-bottom" id="liveStudioAtmoToolbar" style="border-color: var(--avd-border, rgba(255,255,255,0.08)) !important;">
                                            <div class="d-flex align-items-center gap-1.5 flex-wrap">
                                                <span class="small text-muted fw-bold text-uppercase" style="font-size: 0.68rem;">
                                                    <i class="fa-solid fa-wand-magic-sparkles me-1 text-primary"></i> {{ __('Atmosphere FX:') }}
                                                </span>
                                                <div class="btn-group btn-group-sm rounded-pill p-0.5 border" role="group" id="liveStudioAtmoPills" style="background: var(--avd-surface-alt, rgba(255,255,255,0.06));">
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 active fw-semibold studio-atmo-pill" data-atmo="clean_none">Clean None</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted studio-atmo-pill" data-atmo="aurora_glow">Aurora Glow</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted studio-atmo-pill" data-atmo="cyber_grid">Cyber Grid</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted studio-atmo-pill" data-atmo="cosmic_particles">Cosmic Particles</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted studio-atmo-pill" data-atmo="mesh_nebula">Mesh Nebula</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted studio-atmo-pill" data-atmo="solar_flare">Solar Flare</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted studio-atmo-pill" data-atmo="quantum_matrix">Quantum Matrix</button>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge rounded-pill bg-body-secondary text-body small" id="liveActiveTypoBadge" style="font-size: 0.68rem;">
                                                    <i class="fa-solid fa-font me-1 text-info"></i>Typo: Modern Tech
                                                </span>
                                                <span class="badge rounded-pill bg-body-secondary text-body small" id="liveActiveAtmoBadge" style="font-size: 0.68rem;">
                                                    <i class="fa-solid fa-wand-magic-sparkles me-1 text-warning"></i>FX: Clean None
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Glass Frosting & Ambient Rim Glow Strip -->
                                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2.5 pb-2 border-bottom" id="liveStudioGlassToolbar" style="border-color: var(--avd-border, rgba(255,255,255,0.08)) !important;">
                                            <div class="d-flex align-items-center gap-2 flex-grow-1" style="max-width: 320px;">
                                                <span class="small text-muted fw-bold text-uppercase flex-shrink-0" style="font-size: 0.68rem;">
                                                    <i class="fa-solid fa-droplet me-1 text-info"></i> {{ __('Glass Blur:') }}
                                                </span>
                                                <input type="range" class="form-range flex-grow-1" id="liveStudioBlurRange" min="0" max="32" step="2" value="12" style="height: 5px; cursor: pointer;">
                                                <span class="badge bg-body-secondary text-body font-monospace flex-shrink-0" id="liveStudioBlurValue" style="font-size: 0.68rem; min-width: 38px;">12px</span>
                                            </div>
                                            <div class="d-flex align-items-center gap-3 ms-auto">
                                                <div class="form-check form-switch form-check-inline mb-0">
                                                    <input class="form-check-input" type="checkbox" id="liveStudioBorderBeamToggle" checked style="cursor: pointer;">
                                                    <label class="form-check-label small text-muted fw-semibold" for="liveStudioBorderBeamToggle" style="font-size: 0.68rem; cursor: pointer;">
                                                        <i class="fa-solid fa-wand-magic-sparkles me-1 text-warning"></i> {{ __('Ambient Rim Glow') }}
                                                    </label>
                                                </div>
                                                <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle fw-bold" id="liveDesignQualityScore" style="font-size: 0.68rem;">
                                                    <i class="fa-solid fa-certificate me-1"></i>Score: 98/100
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Cinema Player LUT & Tone Grading Strip -->
                                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2.5 pb-2 border-bottom" id="liveStudioCinemaToolbar" style="border-color: var(--avd-border, rgba(255,255,255,0.08)) !important;">
                                            <div class="d-flex align-items-center gap-1.5 flex-wrap">
                                                <span class="small text-muted fw-bold text-uppercase" style="font-size: 0.68rem;">
                                                    <i class="fa-solid fa-film me-1 text-danger"></i> {{ __('Player Cinema Tone:') }}
                                                </span>
                                                <div class="btn-group btn-group-sm rounded-pill p-0.5 border" role="group" id="liveStudioCinemaPills" style="background: var(--avd-surface-alt, rgba(255,255,255,0.06));">
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 active btn-dark fw-semibold studio-cinema-pill" data-cinema="none">None</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted studio-cinema-pill" data-cinema="hdr_vibrant">Vibrant HDR</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted studio-cinema-pill" data-cinema="warm_35mm">Warm 35mm</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted studio-cinema-pill" data-cinema="cyber_glow">Cyber Glow</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted studio-cinema-pill" data-cinema="cool_scifi">Sci-Fi Cool</button>
                                                    <button type="button" class="btn btn-xs rounded-pill px-2 py-0.5 fw-semibold text-muted studio-cinema-pill" data-cinema="monochrome_noir">Noir</button>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge rounded-pill bg-body-secondary text-body small" id="liveActiveCinemaBadge" style="font-size: 0.68rem;">
                                                    <i class="fa-solid fa-sliders me-1 text-danger"></i>LUT: None
                                                </span>
                                                <span class="badge rounded-pill bg-body-secondary text-body small" id="liveSpotlightBadge" style="font-size: 0.68rem;">
                                                    <i class="fa-solid fa-lightbulb me-1 text-warning"></i>Spotlight: On
                                                </span>
                                            </div>
                                        </div>

                                        <!-- The Live Preview Stage (Mini Mockup) -->
                                        <div class="row g-3 align-items-stretch">
                                            <!-- Mock Video Card Preview -->
                                            <div class="col-md-6 col-lg-5" id="liveCardPreviewCol" style="transition: opacity 0.3s ease, transform 0.3s ease, background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;">
                                                <div class="p-2.5 rounded-3 border position-relative overflow-hidden h-100 avd-card-spotlight has-border-beam" id="liveCardPreviewContainer" style="background: var(--avd-surface, #0e1322); border-color: var(--avd-border, rgba(255,255,255,0.1)) !important; transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, transform 0.3s ease;">
                                                    <div class="avd-border-beam" id="liveStudioBorderBeam"></div>
                                                    <!-- Card Thumbnail Mockup -->
                                                    <div class="position-relative rounded-2 overflow-hidden mb-2" id="liveCardThumbContainer" style="aspect-ratio: 16/9; background: linear-gradient(135deg, rgba(var(--avd-brand-rgb, 255,61,97), 0.25), rgba(var(--avd-cyan-rgb, 24,200,216), 0.2)), #131722;">
                                                        <!-- Ambient Glow Halo (Cinema Diffusion) -->
                                                        <div id="liveCardDiffusionGlow" style="position: absolute; inset: -15%; background: radial-gradient(circle, var(--avd-brand, #ff3d61) 0%, transparent 70%); filter: blur(24px); opacity: 0.25; pointer-events: none;"></div>
                                                        
                                                        <!-- Center Play Mockup -->
                                                        <div class="position-absolute top-50 start-50 translate-middle rounded-circle d-flex align-items-center justify-content-center shadow" style="width: 44px; height: 44px; background: rgba(0,0,0,0.6); backdrop-filter: blur(10px); color: #fff; border: 1px solid rgba(255,255,255,0.2);">
                                                            <i class="fa-solid fa-play ms-1" id="livePlayIcon" style="font-size: 1rem; color: var(--avd-brand, #ff3d61);"></i>
                                                        </div>
                                                        
                                                        <!-- Badges -->
                                                        <span class="position-absolute top-0 start-0 m-2 badge rounded-pill px-2 py-0.5 fw-bold" id="liveCardHdBadge" style="background: rgba(0,0,0,0.75); color: #fff; border: 1px solid rgba(255,255,255,0.2); font-size: 0.65rem;">
                                                            4K ULTRA HD
                                                        </span>
                                                        <span class="position-absolute bottom-0 end-0 m-2 badge rounded-pill px-2 py-0.5 fw-bold" id="liveCardDurationPill" style="background: rgba(0,0,0,0.8); backdrop-filter: blur(8px); color: #fff; font-size: 0.7rem;">
                                                            14:20
                                                        </span>
                                                    </div>

                                                    <!-- Video Card Metadata Mockup -->
                                                    <div class="d-flex align-items-start gap-2">
                                                        <!-- Creator Avatar with Animated Ring -->
                                                        <div class="rounded-circle p-0.5 flex-shrink-0" id="liveCardAvatarRing" style="background: linear-gradient(135deg, var(--avd-brand, #ff3d61), var(--avd-cyan, #18c8d8));">
                                                            <div class="rounded-circle bg-dark text-white d-flex align-items-center justify-content-center fw-bold" style="width: 34px; height: 34px; font-size: 0.75rem;">
                                                                AV
                                                            </div>
                                                        </div>
                                                        <div class="flex-grow-1 min-w-0">
                                                            <div class="fw-bold small text-truncate" id="liveCardTitleText" style="color: var(--bs-body-color);">
                                                                Cyberpunk 2077: Phantom Liberty • 60 FPS 4K Showcase
                                                            </div>
                                                            <div class="d-flex align-items-center gap-1.5 small text-muted mt-0.5" style="font-size: 0.72rem;">
                                                                <span>Studio Creator</span>
                                                                <i class="fa-solid fa-circle-check text-primary" style="font-size: 0.65rem;"></i>
                                                                <span>• 124K views • 2 hours ago</span>
                                                            </div>
                                                            <div class="mt-1.5 d-flex align-items-center gap-1">
                                                                <span class="badge rounded-pill px-2 py-0.5" id="liveCardCategoryChip" style="background: var(--avd-surface-alt, rgba(255,255,255,0.08)); color: var(--avd-brand, #ff3d61); border: 1px solid var(--avd-border, rgba(255,255,255,0.12)); font-size: 0.68rem;">
                                                                    Gaming & Sci-Fi
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Mock Player Header & Interactive Controls -->
                                            <div class="col-md-6 col-lg-7">
                                                <div class="p-3 rounded-3 border h-100 d-flex flex-column justify-content-between avd-card-spotlight" id="liveHeaderPreviewContainer" style="background: var(--avd-surface, #0e1322); border-color: var(--avd-border, rgba(255,255,255,0.1)) !important; transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, transform 0.3s ease;">
                                                    <!-- Header Top Bar Mockup -->
                                                    <div>
                                                        <div class="d-flex align-items-center justify-content-between gap-2 pb-2.5 mb-2.5 border-bottom" style="border-color: var(--avd-border, rgba(255,255,255,0.08)) !important;">
                                                            <div class="d-flex align-items-center gap-2">
                                                                <i class="fa-solid fa-play-circle fs-4" id="liveLogoIcon" style="color: var(--avd-brand, #ff3d61);"></i>
                                                                <span class="fw-bold tracking-tight" id="liveLogoText" style="background: linear-gradient(135deg, var(--avd-brand, #ff3d61), var(--avd-brand-2, #ff8b5c)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">AVDBCMS</span>
                                                            </div>
                                                            <!-- Mini Search Bar -->
                                                            <div class="d-none d-sm-flex align-items-center px-2.5 py-1 rounded-pill border" id="liveSearchBar" style="background: var(--avd-bg, #070913); border-color: var(--avd-border, rgba(255,255,255,0.12)) !important; width: 180px;">
                                                                <i class="fa-solid fa-magnifying-glass small text-muted me-2"></i>
                                                                <span class="small text-muted" style="font-size: 0.75rem;">Search videos...</span>
                                                            </div>
                                                            <div class="d-flex align-items-center gap-1.5">
                                                                <span class="btn btn-xs rounded-pill px-2.5 py-1 fw-bold text-white shadow-sm" id="liveSubscribeBtn" style="background: var(--avd-brand, #ff3d61); font-size: 0.72rem;">
                                                                    <i class="fa-solid fa-bell me-1"></i>Subscribe
                                                                </span>
                                                            </div>
                                                        </div>

                                                        <!-- Creator Studio Telemetry Bar Mockup -->
                                                        <div class="p-2 rounded-2 border d-flex flex-wrap align-items-center justify-content-between gap-2" id="liveTelemetryHudMock" style="background: rgba(0,0,0,0.25); border-color: rgba(var(--avd-cyan-rgb, 24,200,216), 0.25) !important;">
                                                            <div class="d-flex align-items-center gap-2">
                                                                <span class="badge px-1.5 py-0.5 rounded text-uppercase fw-bold" id="liveHudBadge" style="background: rgba(var(--avd-cyan-rgb, 24,200,216), 0.15); color: var(--avd-cyan, #18c8d8); font-size: 0.65rem;">
                                                                    <i class="fa-solid fa-tower-broadcast me-1"></i>STUDIO HUD
                                                                </span>
                                                                <span class="small font-monospace" id="liveHudResolutionText" style="font-size: 0.7rem; color: var(--avd-cyan, #18c8d8);">
                                                                    3840x2160 • 60 FPS • H.264 MP4
                                                                </span>
                                                            </div>
                                                            <div class="d-flex align-items-center gap-1.5 font-monospace text-muted" style="font-size: 0.68rem;">
                                                                <span>Bitrate: <strong>14.2 Mbps</strong></span>
                                                                <span>• Audio: <strong>AAC 320k</strong></span>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Theme Token Inspector Chips (Click to copy Hex) -->
                                                    <div class="mt-3 pt-2 border-top d-flex flex-wrap align-items-center justify-content-between gap-2" style="border-color: var(--avd-border, rgba(255,255,255,0.08)) !important;">
                                                        <div class="d-flex flex-wrap align-items-center gap-1.5" id="liveStudioColorTokensList">
                                                            <span class="badge border rounded-pill small studio-token-pill" id="liveTokenPrimary" style="background: rgba(var(--avd-brand-rgb, 255,61,97), 0.1); color: var(--avd-brand, #ff3d61); font-size: 0.68rem; cursor: pointer;" title="{{ __('Click to copy Primary Hex') }}">Primary: #ff3d61</span>
                                                            <span class="badge border rounded-pill small studio-token-pill" id="liveTokenSecondary" style="background: rgba(var(--avd-brand-2-rgb, 255,139,92), 0.1); color: var(--avd-brand-2, #ff8b5c); font-size: 0.68rem; cursor: pointer;" title="{{ __('Click to copy Secondary Hex') }}">Secondary: #ff8b5c</span>
                                                            <span class="badge border rounded-pill small studio-token-pill" id="liveTokenAccent" style="background: rgba(var(--avd-cyan-rgb, 24,200,216), 0.1); color: var(--avd-cyan, #18c8d8); font-size: 0.68rem; cursor: pointer;" title="{{ __('Click to copy Accent Hex') }}">Accent: #18c8d8</span>
                                                            <span class="badge border rounded-pill small studio-token-pill" id="liveTokenDarkBg" style="background: rgba(0,0,0,0.3); color: #94a3b8; font-size: 0.68rem; cursor: pointer;" title="{{ __('Click to copy Dark BG Hex') }}">Dark BG: #070913</span>
                                                            <span class="badge border rounded-pill small studio-token-pill" id="liveTokenDarkSurface" style="background: rgba(0,0,0,0.3); color: #94a3b8; font-size: 0.68rem; cursor: pointer;" title="{{ __('Click to copy Dark Surface Hex') }}">Dark Surface: #0e1322</span>
                                                            <span class="badge border rounded-pill small studio-token-pill" id="liveTokenLightBg" style="background: rgba(255,255,255,0.1); color: #94a3b8; font-size: 0.68rem; cursor: pointer;" title="{{ __('Click to copy Light BG Hex') }}">Light BG: #f8fafc</span>
                                                            <span class="badge border rounded-pill small studio-token-pill" id="liveTokenLightSurface" style="background: rgba(255,255,255,0.1); color: #94a3b8; font-size: 0.68rem; cursor: pointer;" title="{{ __('Click to copy Light Surface Hex') }}">Light Surface: #ffffff</span>
                                                        </div>
                                                        <span class="badge rounded-pill bg-body-secondary text-body small" id="liveLayoutArchetypeBadge" style="font-size: 0.68rem;">
                                                            <i class="fa-solid fa-layer-group me-1"></i>Layout: Grid Modern
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Interactive Live Token Fine-Tuner & Color Science Bar -->
                                        <div class="mt-3 pt-2.5 border-top" id="livePaletteFineTunerBar" style="border-color: var(--avd-border, rgba(255,255,255,0.08)) !important;">
                                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <span class="small text-muted fw-bold text-uppercase" style="font-size: 0.68rem;">
                                                        <i class="fa-solid fa-sliders me-1 text-primary"></i> {{ __('Live Fine-Tuner:') }}
                                                    </span>
                                                    <div class="d-flex align-items-center gap-1.5 flex-wrap">
                                                        <!-- Primary -->
                                                        <div class="d-inline-flex align-items-center gap-1 px-2 py-0.5 rounded-pill border bg-body shadow-sm" title="{{ __('Fine-tune Primary Brand Color') }}">
                                                            <input type="color" id="pickerStudioPrimary" class="form-control form-control-color border-0 p-0 rounded-circle" style="width: 18px; height: 18px; cursor: pointer;" value="#ff3d61">
                                                            <span class="font-monospace small studio-token-pill" id="liveTokenPrimaryFine" style="font-size: 0.68rem; cursor: pointer; color: var(--avd-brand, #ff3d61);" title="{{ __('Click to copy Primary Hex') }}">Pri</span>
                                                        </div>
                                                        <!-- Secondary -->
                                                        <div class="d-inline-flex align-items-center gap-1 px-2 py-0.5 rounded-pill border bg-body shadow-sm" title="{{ __('Fine-tune Secondary Brand Color') }}">
                                                            <input type="color" id="pickerStudioSecondary" class="form-control form-control-color border-0 p-0 rounded-circle" style="width: 18px; height: 18px; cursor: pointer;" value="#ff8b5c">
                                                            <span class="font-monospace small studio-token-pill" id="liveTokenSecondaryFine" style="font-size: 0.68rem; cursor: pointer; color: var(--avd-brand-2, #ff8b5c);" title="{{ __('Click to copy Secondary Hex') }}">Sec</span>
                                                        </div>
                                                        <!-- Accent -->
                                                        <div class="d-inline-flex align-items-center gap-1 px-2 py-0.5 rounded-pill border bg-body shadow-sm" title="{{ __('Fine-tune Accent Glow Color') }}">
                                                            <input type="color" id="pickerStudioAccent" class="form-control form-control-color border-0 p-0 rounded-circle" style="width: 18px; height: 18px; cursor: pointer;" value="#18c8d8">
                                                            <span class="font-monospace small studio-token-pill" id="liveTokenAccentFine" style="font-size: 0.68rem; cursor: pointer; color: var(--avd-cyan, #18c8d8);" title="{{ __('Click to copy Accent Hex') }}">Acc</span>
                                                        </div>
                                                        <!-- Dark BG -->
                                                        <div class="d-inline-flex align-items-center gap-1 px-2 py-0.5 rounded-pill border bg-body shadow-sm" title="{{ __('Fine-tune Dark Canvas Background Color') }}">
                                                            <input type="color" id="pickerStudioDarkBg" class="form-control form-control-color border-0 p-0 rounded-circle" style="width: 18px; height: 18px; cursor: pointer;" value="#070913">
                                                            <span class="font-monospace small text-muted studio-token-pill" id="liveTokenDarkBgFine" style="font-size: 0.68rem; cursor: pointer;" title="{{ __('Click to copy Dark BG Hex') }}">D-BG</span>
                                                        </div>
                                                        <!-- Dark Surface -->
                                                        <div class="d-inline-flex align-items-center gap-1 px-2 py-0.5 rounded-pill border bg-body shadow-sm" title="{{ __('Fine-tune Dark Surface Color') }}">
                                                            <input type="color" id="pickerStudioDarkSurface" class="form-control form-control-color border-0 p-0 rounded-circle" style="width: 18px; height: 18px; cursor: pointer;" value="#0e1322">
                                                            <span class="font-monospace small text-muted studio-token-pill" id="liveTokenDarkSurfaceFine" style="font-size: 0.68rem; cursor: pointer;" title="{{ __('Click to copy Dark Surface Hex') }}">D-Surf</span>
                                                        </div>
                                                        <!-- Light BG -->
                                                        <div class="d-inline-flex align-items-center gap-1 px-2 py-0.5 rounded-pill border bg-body shadow-sm" title="{{ __('Fine-tune Light Canvas Background Color') }}">
                                                            <input type="color" id="pickerStudioLightBg" class="form-control form-control-color border-0 p-0 rounded-circle" style="width: 18px; height: 18px; cursor: pointer;" value="#f8fafc">
                                                            <span class="font-monospace small text-muted studio-token-pill" id="liveTokenLightBgFine" style="font-size: 0.68rem; cursor: pointer;" title="{{ __('Click to copy Light BG Hex') }}">L-BG</span>
                                                        </div>
                                                        <!-- Light Surface -->
                                                        <div class="d-inline-flex align-items-center gap-1 px-2 py-0.5 rounded-pill border bg-body shadow-sm" title="{{ __('Fine-tune Light Surface Color') }}">
                                                            <input type="color" id="pickerStudioLightSurface" class="form-control form-control-color border-0 p-0 rounded-circle" style="width: 18px; height: 18px; cursor: pointer;" value="#ffffff">
                                                            <span class="font-monospace small text-muted studio-token-pill" id="liveTokenLightSurfaceFine" style="font-size: 0.68rem; cursor: pointer;" title="{{ __('Click to copy Light Surface Hex') }}">L-Surf</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <!-- Action Buttons: Magic Palette, Quick Harmony, Export/Import, & Reset -->
                                                <div class="d-flex align-items-center gap-1.5 ms-auto flex-wrap">
                                                    <button type="button" class="btn btn-xs btn-outline-warning rounded-pill px-2.5 py-1 fw-bold d-inline-flex align-items-center gap-1 shadow-sm" id="btnStudioMagicPalette" title="{{ __('Algorithmic Golden-Ratio Palette Generator') }}">
                                                        <i class="fa-solid fa-bolt text-warning"></i>
                                                        <span>{{ __('Magic Palette') }}</span>
                                                    </button>
                                                    <button type="button" class="btn btn-xs btn-outline-info rounded-pill px-2 py-1 d-inline-flex align-items-center gap-1" id="btnStudioCompAccent" title="{{ __('Generate 180° Optical Complementary Accent Color') }}">
                                                        <i class="fa-solid fa-palette text-info"></i>
                                                        <span>{{ __('Comp Accent') }}</span>
                                                    </button>
                                                    <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill px-2 py-1 d-inline-flex align-items-center gap-1" id="btnStudioInvertContrast" title="{{ __('Toggle Canvas Depth (OLED Black vs Charcoal)') }}">
                                                        <i class="fa-solid fa-circle-half-stroke"></i>
                                                        <span>{{ __('Depth') }}</span>
                                                    </button>
                                                    <button type="button" class="btn btn-xs btn-outline-success rounded-pill px-2 py-1 d-inline-flex align-items-center gap-1" id="btnStudioExportTheme" title="{{ __('Export Theme JSON & CSS Tokens') }}">
                                                        <i class="fa-solid fa-download"></i>
                                                        <span>{{ __('Export') }}</span>
                                                    </button>
                                                    <button type="button" class="btn btn-xs btn-outline-primary rounded-pill px-2 py-1 d-inline-flex align-items-center gap-1" id="btnStudioImportTheme" title="{{ __('Import Theme JSON') }}">
                                                        <i class="fa-solid fa-upload"></i>
                                                        <span>{{ __('Import') }}</span>
                                                    </button>
                                                    <button type="button" class="btn btn-xs btn-outline-info rounded-pill px-2 py-1 d-inline-flex align-items-center gap-1" id="btnStudioComparePreset" title="{{ __('Compare current colors against active preset defaults') }}">
                                                        <i class="fa-solid fa-code-compare"></i>
                                                        <span>{{ __('Diff') }}</span>
                                                    </button>
                                                    <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill px-2 py-1 d-inline-flex align-items-center gap-1" id="btnStudioResetCustomColors" title="{{ __('Reset custom colors to current active preset') }}">
                                                        <i class="fa-solid fa-rotate-left"></i>
                                                        <span>{{ __('Reset') }}</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="theme">{{__('Default Theme')}}</label>
                                <select id="theme" name="theme" class="form-select">
                                    @foreach(['dark' => __('Dark'), 'light' => __('Light'), 'auto' => __('Auto')] as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'theme', 'dark') === $key)>{{ $display($label, ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text small">{{ __("Default mode for visitors who have not picked one. Choosing a different preset resets this to that preset's base mode; you can change it again afterwards and Save.") }}</div>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="grid_columns">{{__('Video Grid Columns')}}</label>
                                <select id="grid_columns" name="grid_columns" class="form-select">
                                    @for($i = 2; $i <= 6; $i++)
                                        <option value="{{ $i }}" @selected((int)$value('appearance', 'grid_columns', 4) === $i)>{{ $i }} {{__('columns')}}</option>
                                    @endfor
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="primary_color">{{__('Primary Color')}}</label>
                                <input id="primary_color" type="color" name="primary_color" class="form-control form-control-color w-100" value="{{ $value('appearance', 'primary_color', '#ff3d61') }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="brand_secondary_color">{{__('Secondary Color')}}</label>
                                <input id="brand_secondary_color" type="color" name="brand_secondary_color" class="form-control form-control-color w-100" value="{{ $value('appearance', 'brand_secondary_color', '#ff8b5c') }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="accent_color">{{__('Accent Color')}}</label>
                                <input id="accent_color" type="color" name="accent_color" class="form-control form-control-color w-100" value="{{ $value('appearance', 'accent_color', $appearanceDefaults['accent_color']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="inputDarkBgColor">{{__('Dark Canvas BG')}}</label>
                                <input type="color" name="dark_bg_color" id="inputDarkBgColor" class="form-control form-control-color w-100" value="{{ $value('appearance', 'dark_bg_color', $appearanceDefaults['dark_bg_color'] ?? '#070913') }}" title="{{ __('Dark theme main canvas background') }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="inputDarkSurfaceColor">{{__('Dark Card Surface')}}</label>
                                <input type="color" name="dark_surface_color" id="inputDarkSurfaceColor" class="form-control form-control-color w-100" value="{{ $value('appearance', 'dark_surface_color', $appearanceDefaults['dark_surface_color'] ?? '#0e1322') }}" title="{{ __('Dark theme card, header and container surface') }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="inputLightBgColor">{{__('Light Canvas BG')}}</label>
                                <input type="color" name="light_bg_color" id="inputLightBgColor" class="form-control form-control-color w-100" value="{{ $value('appearance', 'light_bg_color', $appearanceDefaults['light_bg_color'] ?? '#f8fafc') }}" title="{{ __('Light theme main canvas background') }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="inputLightSurfaceColor">{{__('Light Card Surface')}}</label>
                                <input type="color" name="light_surface_color" id="inputLightSurfaceColor" class="form-control form-control-color w-100" value="{{ $value('appearance', 'light_surface_color', $appearanceDefaults['light_surface_color'] ?? '#ffffff') }}" title="{{ __('Light theme card and container surface') }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="surface_style">{{__('Surface Style')}}</label>
                                <select id="surface_style" name="surface_style" class="form-select">
                                    @foreach(['glass' => __('Glass'), 'solid' => __('Solid'), 'contrast' => __('High Contrast')] as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'surface_style', 'glass') === $key)>{{ $display($label, ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="radius_scale">{{__('Corner Radius')}}</label>
                                <select id="radius_scale" name="radius_scale" class="form-select">
                                    @foreach(['compact' => __('Compact'), 'balanced' => __('Balanced'), 'rounded' => __('Rounded')] as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'radius_scale', 'balanced') === $key)>{{ $display($label, ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="motion_level">{{__('Motion Level')}}</label>
                                <select id="motion_level" name="motion_level" class="form-select">
                                    @foreach(['full' => __('Full'), 'reduced' => __('Reduced'), 'off' => __('Off')] as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'motion_level', 'full') === $key)>{{ $display($label, ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="content_density">{{__('Content Density')}}</label>
                                <select id="content_density" name="content_density" class="form-select">
                                    @foreach(['compact' => __('Compact'), 'comfortable' => __('Comfortable'), 'relaxed' => __('Relaxed')] as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'content_density', 'comfortable') === $key)>{{ $display($label, ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="text_contrast">{{__('Text Contrast')}}</label>
                                <select id="text_contrast" name="text_contrast" class="form-select">
                                    @foreach($textContrastLevels as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'text_contrast', $appearanceDefaults['text_contrast']) === $key)>{{ $display(__($label), ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="scrollbar_style">{{__('Scrollbar Style')}}</label>
                                <select id="scrollbar_style" name="scrollbar_style" class="form-select">
                                    @foreach(\App\Support\AppearanceSettings::scrollbarStyles() as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'scrollbar_style', $appearanceDefaults['scrollbar_style']) === $key)>{{ $display(__($label), ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="upload_modal_style">{{__('Upload Modal Style')}}</label>
                                <select id="upload_modal_style" name="upload_modal_style" class="form-select">
                                    @foreach(\App\Support\AppearanceSettings::uploadModalStyles() as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'upload_modal_style', $appearanceDefaults['upload_modal_style']) === $key)>{{ $display(__($label), ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="avd-section-title mt-4"><i class="fa-solid fa-shapes text-primary"></i> {{__('Structural Layout & Interactive Component Studio')}}</div>
                        <div class="p-3 p-md-4 rounded-3 border mb-3" style="background: rgba(var(--avd-cyan-rgb, 24, 200, 216), 0.04); border-color: rgba(var(--avd-cyan-rgb, 24, 200, 216), 0.25) !important;">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div>
                                    <h6 class="fw-bold mb-1 text-white"><i class="fa-solid fa-layer-group text-info me-2"></i> {{ __('Layout Diversity Engine') }}</h6>
                                    <div class="small text-muted">{{ __('Configure structural variations across the application: interactive hero sliders, multi-style video cards, cinema watch modes, and header architectures.') }}</div>
                                </div>
                                <span class="badge bg-info text-dark rounded-pill px-3 py-1 fw-bold">{{ __('Structural Engine') }}</span>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-uppercase text-muted mb-1" for="heroLayoutModeSelect">{{ __('Hero Showcase Layout') }}</label>
                                    <select name="hero_layout_mode" class="form-select" id="heroLayoutModeSelect">
                                        @foreach($heroLayoutModes as $key => $label)
                                            <option value="{{ $key }}" @selected((string)$value('appearance', 'hero_layout_mode', $appearanceDefaults['hero_layout_mode'] ?? 'snap_slider_carousel') === $key)>
                                                {{ __($label) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="form-text small">{{ __('Choose between interactive snap slider, bento spotlight, live ticker feed, classic banner, or turn off entirely for a direct video feed.') }}</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-uppercase text-muted mb-1" for="videoCardLayoutStyleSelect">{{ __('Video Card Layout Style') }}</label>
                                    <select name="video_card_layout_style" class="form-select" id="videoCardLayoutStyleSelect">
                                        @foreach($videoCardLayoutStyles as $key => $label)
                                            <option value="{{ $key }}" @selected((string)$value('appearance', 'video_card_layout_style', $appearanceDefaults['video_card_layout_style'] ?? 'card_standard') === $key)>
                                                {{ __($label) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="form-text small">{{ __('Standard 16:9, Netflix zoom expand, modern glassmorphic overlay, horizontal strip, or bento tile.') }}</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-uppercase text-muted mb-1" for="watchLayoutModeSelect">{{ __('Watch Page Presentation') }}</label>
                                    <select name="watch_layout_mode" class="form-select" id="watchLayoutModeSelect">
                                        @foreach($watchLayoutModes as $key => $label)
                                            <option value="{{ $key }}" @selected((string)$value('appearance', 'watch_layout_mode', $appearanceDefaults['watch_layout_mode'] ?? 'classic_two_column') === $key)>
                                                {{ __($label) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="form-text small">{{ __('YouTube classic 70/30, full-width theater cinema with ambient glow, immersion canvas, or creator studio.') }}</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-uppercase text-muted mb-1" for="headerStyleSelect">{{ __('Header & Navigation Style') }}</label>
                                    <select name="header_style" class="form-select" id="headerStyleSelect">
                                        @foreach($headerStyles as $key => $label)
                                            <option value="{{ $key }}" @selected((string)$value('appearance', 'header_style', $appearanceDefaults['header_style'] ?? 'glass_blur') === $key)>
                                                {{ __($label) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="form-text small">{{ __('Liquid glass blur, floating glass island, minimalist slim topbar, full portal megabar, or app rail.') }}</div>
                                </div>

                                <div class="col-md-6">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <label class="form-label small fw-bold text-uppercase text-muted mb-0" for="heroSliderAutoplaySwitch">{{ __('Hero Slider Autoplay') }}</label>
                                        <div class="form-check form-switch m-0">
                                            <input class="form-check-input" type="checkbox" name="hero_slider_autoplay_enabled" id="heroSliderAutoplaySwitch" value="1" @checked($isChecked('appearance', 'hero_slider_autoplay_enabled', $appearanceDefaults['hero_slider_autoplay_enabled'] ?? '1'))>
                                            <label class="form-check-label small" for="heroSliderAutoplaySwitch">{{ __('Auto-Advance') }}</label>
                                        </div>
                                    </div>
                                    <div class="input-group">
                                        <input type="number" name="hero_slider_autoplay_seconds" class="form-control" min="3" max="15" value="{{ $value('appearance', 'hero_slider_autoplay_seconds', $appearanceDefaults['hero_slider_autoplay_seconds'] ?? '5') }}">
                                        <span class="input-group-text small">{{ __('seconds (3-15s)') }}</span>
                                    </div>
                                    <div class="form-text small">{{ __('Toggle auto-advancing slides on/off and configure pacing interval.') }}</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-uppercase text-muted mb-1" for="motion_engine_level">{{ __('Motion Engine Performance') }}</label>
                                    <select id="motion_engine_level" name="motion_engine_level" class="form-select">
                                        @foreach($motionEngineLevels as $key => $label)
                                            <option value="{{ $key }}" @selected((string)$value('appearance', 'motion_engine_level', $appearanceDefaults['motion_engine_level'] ?? 'smooth_fluid') === $key)>
                                                {{ __($label) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="form-text small">{{ __('Controls animation smoothness, spring physics, and frame pacing across the platform.') }}</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-uppercase text-muted mb-1" for="performance_tier_mode">{{ __('Device Performance Tier') }}</label>
                                    <select id="performance_tier_mode" name="performance_tier_mode" class="form-select">
                                        @foreach($performanceTierModes as $key => $label)
                                            <option value="{{ $key }}" @selected((string)$value('appearance', 'performance_tier_mode', $appearanceDefaults['performance_tier_mode'] ?? 'auto') === $key)>
                                                {{ __($label) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="form-text small">{{ __('Auto checks each device before the page draws: strong desktops get every effect, phones and modest hardware keep the same look with lighter motion and blur, weak devices get a static version. Admin pages always show every effect.') }}</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-uppercase text-muted mb-1" for="homeLayoutModeSelect">{{ __('Home Feed Architecture') }}</label>
                                    <select name="home_layout_mode" class="form-select" id="homeLayoutModeSelect">
                                        @foreach($homeLayoutModes as $key => $label)
                                            <option value="{{ $key }}" @selected((string)$value('appearance', 'home_layout_mode', $appearanceDefaults['home_layout_mode'] ?? 'grid_modern') === $key)>
                                                {{ __($label) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="form-text small">{{ __('Overall homepage layout model: Standard modern grid, Netflix billboard carousel, YouTube spotlight, or Bento mosaic.') }}</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-uppercase text-muted mb-1" for="modalPresentationStyleSelect">{{ __('Modal Presentation Style') }}</label>
                                    <select name="modal_presentation_style" class="form-select" id="modalPresentationStyleSelect">
                                        @foreach($modalPresentationStyles as $key => $label)
                                            <option value="{{ $key }}" @selected((string)$value('appearance', 'modal_presentation_style', $appearanceDefaults['modal_presentation_style'] ?? 'modal_glassmorphic_center') === $key)>
                                                {{ __($label) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="form-text small">{{ __('Frosted glass center modal, slide-in sheet drawer, fullscreen cinema lightbox, or brutalist popover.') }}</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-uppercase text-muted mb-1" for="backgroundAtmosphereEffectSelect">{{ __('Atmosphere & Backdrop FX') }}</label>
                                    <select name="background_atmosphere_effect" class="form-select" id="backgroundAtmosphereEffectSelect">
                                        @foreach($backgroundAtmosphereEffects as $key => $label)
                                            <option value="{{ $key }}" @selected((string)$value('appearance', 'background_atmosphere_effect', $appearanceDefaults['background_atmosphere_effect'] ?? 'clean_none') === $key)>
                                                {{ __($label) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="form-text small">{{ __('Visual background ambient effect: Clean minimalist, cosmic floating stardust, aurora glow mesh, or cyberpunk grid.') }}</div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-uppercase text-muted mb-1" for="typographyPairingSelect">{{ __('Typography Pairing System') }}</label>
                                    <select name="typography_pairing" class="form-select" id="typographyPairingSelect">
                                        @foreach($typographyPairings as $key => $conf)
                                            <option value="{{ $key }}" @selected((string)$value('appearance', 'typography_pairing', $appearanceDefaults['typography_pairing'] ?? 'modern_tech') === $key)>
                                                {{ is_array($conf) ? ($conf['label'] ?? $key) : $conf }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="form-text small">{{ __('Curated font pairing with Google Fonts auto-load.') }}</div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-uppercase text-muted mb-1" for="videoPlayerCinemaFilterSelect">{{ __('Cinema Player Tone Filter (LUT)') }}</label>
                                    <select name="video_player_cinema_filter" class="form-select" id="videoPlayerCinemaFilterSelect">
                                        @foreach($videoPlayerCinemaFilters as $key => $conf)
                                            <option value="{{ $key }}" @selected((string)$value('appearance', 'video_player_cinema_filter', $appearanceDefaults['video_player_cinema_filter'] ?? 'none') === $key)>
                                                {{ is_array($conf) ? ($conf['label'] ?? $key) : $conf }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="form-text small">{{ __('Hardware-accelerated CSS tone grading.') }}</div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-uppercase text-muted mb-1" for="borderBeamSpeedSelect">{{ __('Border Beam Orbit Speed') }}</label>
                                    <select name="border_beam_speed" class="form-select" id="borderBeamSpeedSelect">
                                        @foreach($borderBeamSpeeds as $key => $label)
                                            <option value="{{ $key }}" @selected((string)$value('appearance', 'border_beam_speed', $appearanceDefaults['border_beam_speed'] ?? 'normal') === $key)>
                                                {{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="form-text small">{{ __('Revolution velocity for glowing perimeter beams.') }}</div>
                                </div>

                                <div class="col-12 mt-2">
                                    <div class="d-flex flex-wrap align-items-center gap-3">
                                        <label class="avd-switch-card mb-0 py-2 px-3">
                                            <input class="form-check-input" type="checkbox" name="interactive_3d_card_tilt_enabled" value="1" @checked($isChecked('appearance', 'interactive_3d_card_tilt_enabled', $appearanceDefaults['interactive_3d_card_tilt_enabled'] ?? '1'))>
                                            <span class="small">
                                                <span class="fw-bold d-block"><i class="fa-solid fa-cube me-1 text-info"></i> {{ __('3D Card Hover Tilt') }}</span>
                                                <span class="text-muted">{{ __('Interactive tilt perspective reaction on desktop cursor') }}</span>
                                            </span>
                                        </label>
                                        <label class="avd-switch-card mb-0 py-2 px-3">
                                            <input class="form-check-input" type="checkbox" name="cinema_ambient_light_diffusion_enabled" value="1" @checked($isChecked('appearance', 'cinema_ambient_light_diffusion_enabled', $appearanceDefaults['cinema_ambient_light_diffusion_enabled'] ?? '1'))>
                                            <span class="small">
                                                <span class="fw-bold d-block"><i class="fa-solid fa-lightbulb me-1 text-info"></i> {{ __('Cinema Ambient Diffusion') }}</span>
                                                <span class="text-muted">{{ __('Dynamic video ambient light diffusion around watch player') }}</span>
                                            </span>
                                        </label>
                                        <label class="avd-switch-card mb-0 py-2 px-3">
                                            <input class="form-check-input" type="checkbox" name="card_spotlight_glow_enabled" value="1" @checked($isChecked('appearance', 'card_spotlight_glow_enabled', $appearanceDefaults['card_spotlight_glow_enabled'] ?? '1'))>
                                            <span class="small">
                                                <span class="fw-bold d-block"><i class="fa-solid fa-crosshairs me-1 text-info"></i> {{ __('Card Cursor Spotlight') }}</span>
                                                <span class="text-muted">{{ __('60fps radial cursor spotlight tracking across cards & surfaces') }}</span>
                                            </span>
                                        </label>
                                        <label class="avd-switch-card mb-0 py-2 px-3">
                                            <input class="form-check-input" type="checkbox" name="border_beam_glow_enabled" value="1" @checked($isChecked('appearance', 'border_beam_glow_enabled', $appearanceDefaults['border_beam_glow_enabled'] ?? '1'))>
                                            <span class="small">
                                                <span class="fw-bold d-block"><i class="fa-solid fa-circle-notch fa-spin me-1 text-info" style="--fa-animation-duration: 4s;"></i> {{ __('Animated Border Beam') }}</span>
                                                <span class="text-muted">{{ __('Hardware-accelerated revolving gradient beam along featured cards') }}</span>
                                            </span>
                                        </label>
                                        <label class="avd-switch-card mb-0 py-2 px-3">
                                            <input class="form-check-input" type="checkbox" name="surface_noise_grain_enabled" value="1" @checked($isChecked('appearance', 'surface_noise_grain_enabled', $appearanceDefaults['surface_noise_grain_enabled'] ?? '1'))>
                                            <span class="small">
                                                <span class="fw-bold d-block"><i class="fa-solid fa-braille me-1 text-info"></i> {{ __('Organic Film Grain Noise') }}</span>
                                                <span class="text-muted">{{ __('Anti-banding velvety SVG turbulence texture across dark/mesh backdrops') }}</span>
                                            </span>
                                        </label>
                                        <label class="avd-switch-card mb-0 py-2 px-3">
                                            <input class="form-check-input" type="checkbox" name="video_player_ambilight_glow_enabled" value="1" @checked($isChecked('appearance', 'video_player_ambilight_glow_enabled', $appearanceDefaults['video_player_ambilight_glow_enabled'] ?? '1'))>
                                            <span class="small">
                                                <span class="fw-bold d-block"><i class="fa-solid fa-sun me-1 text-warning"></i> {{ __('Reactive Player Ambilight Halo') }}</span>
                                                <span class="text-muted">{{ __('Dynamic luminous backlight halo projection surrounding active video players') }}</span>
                                            </span>
                                        </label>
                                        <div class="avd-switch-card mb-0 py-2 px-3 d-flex align-items-center justify-content-between">
                                            <label class="d-flex align-items-center gap-2 mb-0 cursor-pointer flex-grow-1">
                                                <input class="form-check-input mt-0" type="checkbox" name="ui_audio_feedback_enabled" value="1" @checked($isChecked('appearance', 'ui_audio_feedback_enabled', $appearanceDefaults['ui_audio_feedback_enabled'] ?? '0'))>
                                                <span class="small">
                                                    <span class="fw-bold d-block"><i class="fa-solid fa-volume-high me-1 text-primary"></i> {{ __('Web Audio Micro-Haptics') }}</span>
                                                    <span class="text-muted">{{ __('Pure synthesized 0ms audio feedback for clicks, toggles, & preset switching') }}</span>
                                                </span>
                                            </label>
                                            <button type="button" class="btn btn-xs btn-outline-info rounded-pill px-2.5 py-1 ms-2 flex-shrink-0" id="btnTestAudioFeedback" title="{{ __('Test audio synthesizer right now') }}">
                                                <i class="fa-solid fa-play me-1"></i> {{ __('Test Sound') }}
                                            </button>
                                        </div>
                                        <label class="avd-switch-card mb-0 py-2 px-3">
                                            <input class="form-check-input" type="checkbox" name="surface_glassmorphism_enabled" value="1" @checked($isChecked('appearance', 'surface_glassmorphism_enabled', $appearanceDefaults['surface_glassmorphism_enabled'] ?? '1'))>
                                            <span class="small">
                                                <span class="fw-bold d-block"><i class="fa-solid fa-cube me-1 text-info"></i> {{ __('Frosted Acrylic Glassmorphism 2.0') }}</span>
                                                <span class="text-muted">{{ __('Ultra-deep 20px blur and 180% saturation on cards, navbars, and modal sheets') }}</span>
                                            </span>
                                        </label>
                                        <label class="avd-switch-card mb-0 py-2 px-3">
                                            <input class="form-check-input" type="checkbox" name="button_liquid_shimmer_enabled" value="1" @checked($isChecked('appearance', 'button_liquid_shimmer_enabled', $appearanceDefaults['button_liquid_shimmer_enabled'] ?? '1'))>
                                            <span class="small">
                                                <span class="fw-bold d-block"><i class="fa-solid fa-wand-magic-sparkles me-1 text-warning"></i> {{ __('Liquid Glow Button Shimmer') }}</span>
                                                <span class="text-muted">{{ __('Luminous angled light sweep across primary CTA buttons with tactile active press') }}</span>
                                            </span>
                                        </label>
                                        <label class="avd-switch-card mb-0 py-2 px-3">
                                            <input class="form-check-input" type="checkbox" name="cursor_glow_trail_enabled" value="1" @checked($isChecked('appearance', 'cursor_glow_trail_enabled', $appearanceDefaults['cursor_glow_trail_enabled'] ?? '0'))>
                                            <span class="small">
                                                <span class="fw-bold d-block"><i class="fa-solid fa-arrow-pointer me-1 text-primary"></i> {{ __('Desktop Ambient Cursor Halo') }}</span>
                                                <span class="text-muted">{{ __('Fluid 320px radial gradient halo following cursor movement across page backdrops') }}</span>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="avd-section-title mt-4"><i class="fa-solid fa-image text-primary"></i> {{__('Brand assets')}}</div>
                        <div class="avd-field-grid">
                            <div>
                                <label class="form-label fw-bold" for="logo_url">{{__('Logo Path or URL')}}</label>
                                <input id="logo_url" type="text" name="logo_url" class="form-control" value="{{ $value('appearance', 'logo_url') }}" placeholder="/images/logo.png">
                                <div class="form-text">{{__('Used in the header and schema logo. Paste a full https URL or a public path like /images/logo.png.')}}</div>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="favicon_url">{{__('Favicon Path or URL')}}</label>
                                <input id="favicon_url" type="text" name="favicon_url" class="form-control" value="{{ $value('appearance', 'favicon_url') }}" placeholder="/icons/favicon.ico">
                                <div class="form-text">{{__('Used for the browser tab icon. Recommended: .ico, .png, or .svg in the public folder.')}}</div>
                            </div>
                        </div>
                        <div class="avd-section-title mt-4"><i class="fa-solid fa-message text-primary"></i> {{__('Footer and announcements')}}</div>
                        <div class="avd-field-grid">
                            <div>
                                <label class="form-label fw-bold" for="footer_brand_title">{{__('Footer Brand Title')}}</label>
                                <input id="footer_brand_title" type="text" name="footer_brand_title" class="form-control" value="{{ $value('appearance', 'footer_brand_title', $footerDefaults['footer_brand_title']) }}" placeholder="{{ config('app.name') }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="footer_categories_limit">{{__('Footer Categories Limit')}}</label>
                                <input id="footer_categories_limit" type="number" name="footer_categories_limit" class="form-control" value="{{ $value('appearance', 'footer_categories_limit', $footerDefaults['footer_categories_limit']) }}" min="0" max="24">
                            </div>
                            <div class="avd-field-span">
                                <label class="form-label fw-bold" for="footer_tagline">{{__('Footer Tagline')}}</label>
                                <input id="footer_tagline" type="text" name="footer_tagline" class="form-control" value="{{ $value('appearance', 'footer_tagline', $footerDefaults['footer_tagline']) }}">
                            </div>
                            <div class="avd-field-span">
                                <label class="form-label fw-bold" for="footer_text">{{__('Footer Text')}}</label>
                                <input id="footer_text" type="text" name="footer_text" class="form-control" value="{{ $value('appearance', 'footer_text') }}" placeholder="(c) {{ now()->format('Y') }} {{ config('app.name') }}. All rights reserved.">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="footer_explore_title">{{__('Explore Column Title')}}</label>
                                <input id="footer_explore_title" type="text" name="footer_explore_title" class="form-control" value="{{ $value('appearance', 'footer_explore_title', $footerDefaults['footer_explore_title']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="footer_categories_title">{{__('Categories Column Title')}}</label>
                                <input id="footer_categories_title" type="text" name="footer_categories_title" class="form-control" value="{{ $value('appearance', 'footer_categories_title', $footerDefaults['footer_categories_title']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="footer_support_title">{{__('Support Column Title')}}</label>
                                <input id="footer_support_title" type="text" name="footer_support_title" class="form-control" value="{{ $value('appearance', 'footer_support_title', $footerDefaults['footer_support_title']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="footer_settings_title">{{__('Settings Column Title')}}</label>
                                <input id="footer_settings_title" type="text" name="footer_settings_title" class="form-control" value="{{ $value('appearance', 'footer_settings_title', $footerDefaults['footer_settings_title']) }}">
                            </div>
                            <div class="avd-field-span">
                                <label class="form-label fw-bold" for="footer_explore_links">{{__('Explore Links')}}</label>
                                <textarea id="footer_explore_links" name="footer_explore_links" class="form-control font-monospace" rows="4" placeholder="Label|/url">{{ $value('appearance', 'footer_explore_links', $footerDefaults['footer_explore_links']) }}</textarea>
                                <div class="form-text">{{__('One link per line. Format: Label|URL. Internal URLs are automatically localized.')}}</div>
                            </div>
                            <div class="avd-field-span">
                                <label class="form-label fw-bold" for="footer_categories_links">{{__('Category Links Override')}}</label>
                                <textarea id="footer_categories_links" name="footer_categories_links" class="form-control font-monospace" rows="4" placeholder="Label|/categories/example">{{ $value('appearance', 'footer_categories_links', $footerDefaults['footer_categories_links']) }}</textarea>
                                <div class="form-text">{{__('Leave empty to use the first menu categories automatically.')}}</div>
                            </div>
                            <div class="avd-field-span">
                                <label class="form-label fw-bold" for="footer_support_links">{{__('Support Links')}}</label>
                                <textarea id="footer_support_links" name="footer_support_links" class="form-control font-monospace" rows="4" placeholder="Label|/url">{{ $value('appearance', 'footer_support_links', $footerDefaults['footer_support_links']) }}</textarea>
                            </div>
                            <div class="avd-field-span">
                                <label class="form-label fw-bold" for="footer_bottom_links">{{__('Bottom Links')}}</label>
                                <textarea id="footer_bottom_links" name="footer_bottom_links" class="form-control font-monospace" rows="3" placeholder="Label|/url">{{ $value('appearance', 'footer_bottom_links', $footerDefaults['footer_bottom_links']) }}</textarea>
                            </div>
                            <div class="avd-field-span">
                                <label class="form-label fw-bold" for="announcement_banner">{{__('Announcement Banner')}}</label>
                                <textarea id="announcement_banner" name="announcement_banner" class="form-control" rows="2" placeholder="{{__('Optional HTML shown above the header')}}">{{ $value('appearance', 'announcement_banner') }}</textarea>
                            </div>
                        </div>
                        <div class="avd-switch-grid mt-3">
                            @foreach([
                                ['footer_brand_enabled', __('Brand Block')],
                                ['footer_social_enabled', __('Social Buttons')],
                                ['footer_explore_enabled', __('Explore Column')],
                                ['footer_categories_enabled', __('Categories Column')],
                                ['footer_support_enabled', __('Support Column')],
                                ['footer_settings_enabled', __('Settings Column')],
                                ['footer_language_switcher_enabled', __('Footer Language Switcher')],
                                ['footer_theme_switcher_enabled', __('Footer Theme Switcher')],
                                ['footer_bottom_links_enabled', __('Bottom Links')],
                            ] as $toggle)
                                <label class="avd-switch-card">
                                    <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('appearance', $toggle[0], $footerDefaults[$toggle[0]] ?? '1'))>
                                    <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="avd-section-title mt-4"><i class="fa-solid fa-mobile-screen-button text-primary"></i> {{__('Responsive & mobile ergonomics')}}</div>
                        <div class="avd-field-grid">
                            <div>
                                <label class="form-label fw-bold" for="mobile_video_columns">{{__('Mobile Video Columns')}}</label>
                                <select id="mobile_video_columns" name="mobile_video_columns" class="form-select">
                                    @foreach([1 => __('1 video per row'), 2 => __('2 videos per row')] as $key => $label)
                                        <option value="{{ $key }}" @selected((int)$value('appearance', 'mobile_video_columns', $appearanceDefaults['mobile_video_columns']) === $key)>{{ $display($label, $key . ' columns') }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="mobile_video_card_density">{{__('Mobile Card Density')}}</label>
                                <select id="mobile_video_card_density" name="mobile_video_card_density" class="form-select">
                                    @foreach($mobileVideoCardDensities as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'mobile_video_card_density', $appearanceDefaults['mobile_video_card_density']) === $key)>{{ $display(__($label), ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="studio_video_preview_mode">{{__('Studio Video Preview')}}</label>
                                <select id="studio_video_preview_mode" name="studio_video_preview_mode" class="form-select">
                                    @foreach($studioVideoPreviewModes as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'studio_video_preview_mode', $appearanceDefaults['studio_video_preview_mode']) === $key)>{{ $display(__($label), ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="back_to_top_style">{{__('Back To Top Style')}}</label>
                                <select id="back_to_top_style" name="back_to_top_style" class="form-select">
                                    @foreach($backToTopStyles as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'back_to_top_style', $appearanceDefaults['back_to_top_style']) === $key)>{{ $display(__($label), ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="avd-switch-grid mt-3 mb-3">
                            @foreach([
                                ['mobile_bottom_nav_enabled', __('Mobile Bottom Nav Bar'), __('Floating thumb-friendly navigation on mobile devices.')],
                                ['mobile_tap_preview_enabled', __('Mobile Tap Preview'), __('Quick-look video preview card on tap before navigation.')],
                                ['ui_click_ripple_enabled', __('Material Click Ripples'), __('Fluid micro-interaction ripples on buttons and cards.')],
                                ['enhanced_selects_enabled', __('Enhanced Dropdowns'), __('Polished searchable selects on forms.')],
                                ['horizontal_rails_hide_scrollbars_enabled', __('Hide Rail Scrollbars'), __('Cleaner category, discovery, and action rails.')],
                                ['scroll_panel_hover_guard_enabled', __('Scroll Panel Guard'), __('Prevents hover effects from creating accidental scrollbars.')],
                            ] as $toggle)
                                <label class="avd-switch-card">
                                    <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('appearance', $toggle[0], $appearanceDefaults[$toggle[0]] ?? '1'))>
                                    <span>
                                        <span class="fw-bold d-block">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                                        <span class="text-muted small">{{ $display($toggle[2], '') }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <div class="avd-section-title mt-4"><i class="fa-solid fa-font text-primary"></i> {{__('Typography controls')}}</div>
                        <div class="avd-switch-grid mb-3">
                            <label class="avd-switch-card">
                                <input class="form-check-input" type="checkbox" name="typography_controls_enabled" value="1" @checked($isChecked('appearance', 'typography_controls_enabled', $appearanceDefaults['typography_controls_enabled']))>
                                <span class="fw-bold">{{__('Apply Typography Controls')}}</span>
                            </label>
                        </div>
                        <div class="avd-field-grid">
                            <div>
                                <label class="form-label fw-bold" for="font_family">{{__('Font Family')}}</label>
                                <select id="font_family" name="font_family" class="form-select">
                                    @foreach($typographyFonts as $key => $font)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'font_family', $appearanceDefaults['font_family']) === $key)>{{ $display(__($font['label']), $font['label']) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="base_font_size">{{__('Base Font Size')}}</label>
                                <input id="base_font_size" type="number" name="base_font_size" class="form-control" value="{{ $value('appearance', 'base_font_size', $appearanceDefaults['base_font_size']) }}" min="{{ \App\Support\AppearanceSettings::BASE_FONT_MIN_PX }}" max="{{ \App\Support\AppearanceSettings::BASE_FONT_MAX_PX }}" required>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="ui_font_scale">{{__('UI Font Scale')}}</label>
                                <input id="ui_font_scale" type="number" name="ui_font_scale" class="form-control" value="{{ $value('appearance', 'ui_font_scale', $appearanceDefaults['ui_font_scale']) }}" min="{{ \App\Support\AppearanceSettings::UI_FONT_SCALE_MIN }}" max="{{ \App\Support\AppearanceSettings::UI_FONT_SCALE_MAX }}" required>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="heading_font_scale">{{__('Heading Font Scale')}}</label>
                                <input id="heading_font_scale" type="number" name="heading_font_scale" class="form-control" value="{{ $value('appearance', 'heading_font_scale', $appearanceDefaults['heading_font_scale']) }}" min="{{ \App\Support\AppearanceSettings::HEADING_FONT_SCALE_MIN }}" max="{{ \App\Support\AppearanceSettings::HEADING_FONT_SCALE_MAX }}" required>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="card_title_font_scale">{{__('Card Title Font Scale')}}</label>
                                <input id="card_title_font_scale" type="number" name="card_title_font_scale" class="form-control" value="{{ $value('appearance', 'card_title_font_scale', $appearanceDefaults['card_title_font_scale']) }}" min="{{ \App\Support\AppearanceSettings::CARD_TITLE_FONT_SCALE_MIN }}" max="{{ \App\Support\AppearanceSettings::CARD_TITLE_FONT_SCALE_MAX }}" required>
                            </div>
                            <div>
                                <div class="form-label fw-bold">{{__('Typography Preview')}}</div>
                                <div class="avd-switch-card">
                                    <span class="fw-bold">{{__('Aa')}}</span>
                                    <span class="text-muted">{{__('Applies to public pages, admin pages, forms, cards, and navigation.')}}</span>
                                </div>
                            </div>
                        </div>
                        <div class="avd-section-title mt-4"><i class="fa-solid fa-compass-drafting text-primary"></i> {{__('Homepage controls')}}</div>
                        <div class="avd-switch-grid mb-3">
                            @foreach([
                                ['home_intro_enabled', __('Intro Header'), '1'],
                                ['home_category_tabs_enabled', __('Category Tabs'), '1'],
                                ['home_rail_links_enabled', __('Rail Links'), '1'],
                                ['home_fallback_rails_enabled', __('Fallback Rails'), '1'],
                            ] as $toggle)
                                <label class="avd-switch-card">
                                    <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('appearance', $toggle[0], $appearanceDefaults[$toggle[0]] ?? $toggle[2]))>
                                    <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="avd-field-grid">
                            <div>
                                <label class="form-label fw-bold" for="home_intro_kicker">{{__('Intro Kicker')}}</label>
                                <input id="home_intro_kicker" type="text" name="home_intro_kicker" class="form-control" value="{{ $value('appearance', 'home_intro_kicker', $appearanceDefaults['home_intro_kicker']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="home_intro_title">{{__('Intro Title')}}</label>
                                <input id="home_intro_title" type="text" name="home_intro_title" class="form-control" value="{{ $value('appearance', 'home_intro_title', $appearanceDefaults['home_intro_title']) }}">
                            </div>
                            <div class="avd-field-span">
                                <label class="form-label fw-bold" for="home_intro_subtitle">{{__('Intro Subtitle')}}</label>
                                <input id="home_intro_subtitle" type="text" name="home_intro_subtitle" class="form-control" value="{{ $value('appearance', 'home_intro_subtitle', $appearanceDefaults['home_intro_subtitle']) }}">
                            </div>
                        </div>
                        <div class="avd-section-title mt-4"><i class="fa-solid fa-table-cells-large text-primary"></i> {{__('Homepage video feed')}}</div>
                        <div class="avd-switch-grid mb-3">
                            <label class="avd-switch-card">
                                <input class="form-check-input" type="checkbox" name="home_main_feed_enabled" value="1" @checked($isChecked('appearance', 'home_main_feed_enabled', $appearanceDefaults['home_main_feed_enabled']))>
                                <span class="fw-bold">{{__('Show Homepage Videos')}}</span>
                            </label>
                        </div>
                        <div class="avd-field-grid">
                            <div>
                                <label class="form-label fw-bold" for="home_feed_source">{{__('Feed Source')}}</label>
                                <select id="home_feed_source" name="home_feed_source" class="form-select">
                                    @foreach($homeFeedSources as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'home_feed_source', $appearanceDefaults['home_feed_source']) === $key)>{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="home_feed_category_id">{{__('Category')}}</label>
                                <select id="home_feed_category_id" name="home_feed_category_id" class="form-select">
                                    <option value="0" @selected((string)$value('appearance', 'home_feed_category_id', $appearanceDefaults['home_feed_category_id']) === '0')>{{__('All Categories')}}</option>
                                    @foreach($homeFeedCategories as $category)
                                        <option value="{{ $category->id }}" @selected((string)$value('appearance', 'home_feed_category_id', $appearanceDefaults['home_feed_category_id']) === (string)$category->id)>{{ __($category->title) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="home_feed_columns">{{__('Items Per Row')}}</label>
                                <input id="home_feed_columns" type="number" name="home_feed_columns" class="form-control" value="{{ $value('appearance', 'home_feed_columns', $appearanceDefaults['home_feed_columns']) }}" min="{{ \App\Support\AppearanceSettings::HOME_FEED_MIN_COLUMNS }}" max="{{ \App\Support\AppearanceSettings::HOME_FEED_MAX_COLUMNS }}" required>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="home_feed_load_mode">{{__('Loading Behavior')}}</label>
                                <select id="home_feed_load_mode" name="home_feed_load_mode" class="form-select">
                                    @foreach($homeFeedLoadModes as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'home_feed_load_mode', $appearanceDefaults['home_feed_load_mode']) === $key)>{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="avd-section-title mt-4"><i class="fa-solid fa-person-running text-primary"></i> {{__('Homepage widget motion')}}</div>
                        <div class="avd-switch-grid mb-3">
                            @foreach([
                                ['home_widget_motion_enabled', __('Widget Motion'), '1'],
                                ['home_widget_motion_autorun_enabled', __('Auto Run Rows'), '1'],
                                ['home_widget_motion_card_cascade_enabled', __('Card Cascade'), '1'],
                                ['home_widget_motion_pause_on_hover_enabled', __('Pause On Hover'), '1'],
                            ] as $toggle)
                                <label class="avd-switch-card">
                                    <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('appearance', $toggle[0], $appearanceDefaults[$toggle[0]] ?? $toggle[2]))>
                                    <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="avd-field-grid">
                            <div>
                                <label class="form-label fw-bold" for="home_widget_motion_style">{{__('Motion Style')}}</label>
                                <select id="home_widget_motion_style" name="home_widget_motion_style" class="form-select">
                                    @foreach(['slide' => __('Slide'), 'lift' => __('Lift'), 'zoom' => __('Zoom'), 'fade' => __('Fade')] as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'home_widget_motion_style', $appearanceDefaults['home_widget_motion_style']) === $key)>{{ $display($label, ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="home_widget_motion_duration_ms">{{__('Duration Ms')}}</label>
                                <input id="home_widget_motion_duration_ms" type="number" name="home_widget_motion_duration_ms" class="form-control" value="{{ $value('appearance', 'home_widget_motion_duration_ms', $appearanceDefaults['home_widget_motion_duration_ms']) }}" min="120" max="2000" required>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="home_widget_motion_distance_px">{{__('Distance Px')}}</label>
                                <input id="home_widget_motion_distance_px" type="number" name="home_widget_motion_distance_px" class="form-control" value="{{ $value('appearance', 'home_widget_motion_distance_px', $appearanceDefaults['home_widget_motion_distance_px']) }}" min="0" max="160" required>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="home_widget_motion_stagger_ms">{{__('Stagger Ms')}}</label>
                                <input id="home_widget_motion_stagger_ms" type="number" name="home_widget_motion_stagger_ms" class="form-control" value="{{ $value('appearance', 'home_widget_motion_stagger_ms', $appearanceDefaults['home_widget_motion_stagger_ms']) }}" min="0" max="240" required>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="home_widget_motion_speed_px_per_second">{{__('Auto Run Speed')}}</label>
                                <input id="home_widget_motion_speed_px_per_second" type="number" name="home_widget_motion_speed_px_per_second" class="form-control" value="{{ $value('appearance', 'home_widget_motion_speed_px_per_second', $appearanceDefaults['home_widget_motion_speed_px_per_second']) }}" min="4" max="160" required>
                            </div>
                            @foreach([
                                ['home_widget_fresh_picks_motion_direction', __('Fresh Picks')],
                                ['home_widget_smart_discovery_motion_direction', __('Smart Discovery Tags')],
                                ['home_widget_creator_spotlight_motion_direction', __('Creator Spotlight')],
                                ['home_widget_trending_collections_motion_direction', __('Trending Actors')],
                            ] as $motionField)
                                <div>
                                    <label class="form-label fw-bold" for="{{ $motionField[0] }}">{{ $motionField[1] }} {{__('Direction')}}</label>
                                    <select id="{{ $motionField[0] }}" name="{{ $motionField[0] }}" class="form-select">
                                        @foreach(['right' => __('Slide Right'), 'left' => __('Slide Left'), 'up' => __('Slide Up'), 'down' => __('Slide Down'), 'none' => __('Fade Only')] as $key => $label)
                                            <option value="{{ $key }}" @selected((string)$value('appearance', $motionField[0], $appearanceDefaults[$motionField[0]]) === $key)>{{ $display($label, ucfirst($key)) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                        <div class="avd-section-title mt-4"><i class="fa-solid fa-bars-staggered text-primary"></i> {{__('Header and sidebar controls')}}</div>
                        <div class="avd-switch-grid">
                            @foreach([
                                ['header_search_enabled', __('Header Search'), '1'],
                                ['search_focus_glow_enabled', __('Search Focus Glow'), '1'],
                                ['header_category_chips_enabled', __('Category Sub-Rail'), '1'],
                                ['header_random_button_enabled', __('Random Video Button'), '1'],
                                ['header_upload_button_enabled', __('Header Upload'), '1'],
                                ['header_notifications_enabled', __('Notifications'), '1'],
                                ['header_login_button_enabled', __('Header Login'), '1'],
                                ['header_register_button_enabled', __('Header Register'), '1'],
                                ['header_premium_chip_enabled', __('Premium Chip'), '1'],
                                ['sidebar_static_menu_enabled', __('Custom Sidebar Menu'), '1'],
                                ['front_sidebar_pinned_enabled', __('Pinned Sidebar'), '1'],
                                ['sidebar_playlists_enabled', __('Sidebar Playlists'), '1'],
                                ['sidebar_subscriptions_enabled', __('Sidebar Subscriptions'), '1'],
                                ['sidebar_explore_enabled', __('Sidebar Explore'), '1'],
                                ['sidebar_premium_link_enabled', __('Premium Link'), '1'],
                                ['sidebar_discover_channels_enabled', __('Discover Link'), '1'],
                                ['sidebar_locale_switcher_enabled', __('Locale Switcher'), '1'],
                                ['sidebar_theme_switcher_enabled', __('Theme Switcher'), '1'],
                            ] as $toggle)
                                <label class="avd-switch-card">
                                    <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('appearance', $toggle[0], $appearanceDefaults[$toggle[0]] ?? $toggle[2]))>
                                    <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="avd-field-grid mt-3">
                            <div>
                                <label class="form-label fw-bold" for="header_style">{{__('Header Layout Style')}}</label>
                                <select id="header_style" name="header_style" class="form-select">
                                    @foreach($headerStyles as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'header_style', $appearanceDefaults['header_style']) === $key)>{{ $display(__($label), ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="header_brand_text">{{__('Header Brand Text')}}</label>
                                <input id="header_brand_text" type="text" name="header_brand_text" class="form-control" value="{{ $value('appearance', 'header_brand_text', $navigationDefaults['header_brand_text']) }}" placeholder="{{ config('app.name') }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="header_premium_label">{{__('Premium Chip Label')}}</label>
                                <input id="header_premium_label" type="text" name="header_premium_label" class="form-control" value="{{ $value('appearance', 'header_premium_label', $navigationDefaults['header_premium_label']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="header_upload_label">{{__('Upload Button Label')}}</label>
                                <input id="header_upload_label" type="text" name="header_upload_label" class="form-control" value="{{ $value('appearance', 'header_upload_label', $navigationDefaults['header_upload_label']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="header_login_label">{{__('Login Button Label')}}</label>
                                <input id="header_login_label" type="text" name="header_login_label" class="form-control" value="{{ $value('appearance', 'header_login_label', $navigationDefaults['header_login_label']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="header_register_label">{{__('Register Button Label')}}</label>
                                <input id="header_register_label" type="text" name="header_register_label" class="form-control" value="{{ $value('appearance', 'header_register_label', $navigationDefaults['header_register_label']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="sidebar_premium_label">{{__('Premium Sidebar Label')}}</label>
                                <input id="sidebar_premium_label" type="text" name="sidebar_premium_label" class="form-control" value="{{ $value('appearance', 'sidebar_premium_label', $navigationDefaults['sidebar_premium_label']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="sidebar_playlists_title">{{__('Playlists Title')}}</label>
                                <input id="sidebar_playlists_title" type="text" name="sidebar_playlists_title" class="form-control" value="{{ $value('appearance', 'sidebar_playlists_title', $navigationDefaults['sidebar_playlists_title']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="sidebar_playlists_limit">{{__('Playlists Limit')}}</label>
                                <input id="sidebar_playlists_limit" type="number" name="sidebar_playlists_limit" class="form-control" value="{{ $value('appearance', 'sidebar_playlists_limit', $navigationDefaults['sidebar_playlists_limit']) }}" min="1" max="50">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="sidebar_subscriptions_title">{{__('Subscriptions Title')}}</label>
                                <input id="sidebar_subscriptions_title" type="text" name="sidebar_subscriptions_title" class="form-control" value="{{ $value('appearance', 'sidebar_subscriptions_title', $navigationDefaults['sidebar_subscriptions_title']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="sidebar_subscriptions_limit">{{__('Subscriptions Limit')}}</label>
                                <input id="sidebar_subscriptions_limit" type="number" name="sidebar_subscriptions_limit" class="form-control" value="{{ $value('appearance', 'sidebar_subscriptions_limit', $navigationDefaults['sidebar_subscriptions_limit']) }}" min="1" max="50">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="sidebar_explore_title">{{__('Explore Title')}}</label>
                                <input id="sidebar_explore_title" type="text" name="sidebar_explore_title" class="form-control" value="{{ $value('appearance', 'sidebar_explore_title', $navigationDefaults['sidebar_explore_title']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="sidebar_explore_limit">{{__('Explore Category Limit')}}</label>
                                <input id="sidebar_explore_limit" type="number" name="sidebar_explore_limit" class="form-control" value="{{ $value('appearance', 'sidebar_explore_limit', $navigationDefaults['sidebar_explore_limit']) }}" min="0" max="100">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="sidebar_discover_channels_label">{{__('Discover Channels Label')}}</label>
                                <input id="sidebar_discover_channels_label" type="text" name="sidebar_discover_channels_label" class="form-control" value="{{ $value('appearance', 'sidebar_discover_channels_label', $navigationDefaults['sidebar_discover_channels_label']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="sidebar_more_label">{{__('Show More Label')}}</label>
                                <input id="sidebar_more_label" type="text" name="sidebar_more_label" class="form-control" value="{{ $value('appearance', 'sidebar_more_label', $navigationDefaults['sidebar_more_label']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="sidebar_less_label">{{__('Show Less Label')}}</label>
                                <input id="sidebar_less_label" type="text" name="sidebar_less_label" class="form-control" value="{{ $value('appearance', 'sidebar_less_label', $navigationDefaults['sidebar_less_label']) }}">
                            </div>
                            <div class="avd-field-span">
                                <label class="form-label fw-bold" for="sidebar_primary_links">{{__('Primary Sidebar Links')}}</label>
                                <textarea id="sidebar_primary_links" name="sidebar_primary_links" class="form-control font-monospace" rows="4" placeholder="Label|icon|/url">{{ $value('appearance', 'sidebar_primary_links', $navigationDefaults['sidebar_primary_links']) }}</textarea>
                                <div class="form-text">{{__('One link per line. Format: Label|FontAwesome icon|URL|new tab optional. Internal URLs are localized automatically.')}}</div>
                            </div>
                            <div class="avd-field-span">
                                <label class="form-label fw-bold" for="sidebar_auth_links">{{__('Authenticated Sidebar Links')}}</label>
                                <textarea id="sidebar_auth_links" name="sidebar_auth_links" class="form-control font-monospace" rows="5" placeholder="Label|icon|/url">{{ $value('appearance', 'sidebar_auth_links', $navigationDefaults['sidebar_auth_links']) }}</textarea>
                            </div>
                        </div>
                        <div class="avd-section-title mt-4"><i class="fa-solid fa-display text-primary"></i> {{__('Watch page controls')}}</div>
                        <div class="avd-switch-grid">
                            @foreach([
                                ['watch_theater_button_enabled', __('Theater Button'), '1'],
                                ['watch_theater_mode_default', __('Default Theater On Load'), '0'],
                                ['watch_floating_mini_player_enabled', __('Floating Mini-Player On Scroll'), '1'],
                                ['watch_speed_controls_enabled', __('Player Speed Chips'), '1'],
                                ['watch_keyboard_shortcuts_enabled', __('Keyboard Shortcuts'), '1'],
                                ['watch_next_video_enabled', __('Next Video'), '1'],
                                ['watch_playlist_panel_enabled', __('Playlist Panel'), '1'],
                                ['watch_related_videos_enabled', __('Related Videos'), '1'],
                                ['watch_sidebar_ads_enabled', __('Sidebar Ads'), '1'],
                                ['watch_reactions_enabled', __('Reactions'), '1'],
                                ['watch_share_enabled', __('Share'), '1'],
                                ['watch_save_enabled', __('Save'), '1'],
                                ['watch_report_enabled', __('Report'), '1'],
                                ['watch_channel_panel_enabled', __('Channel Panel'), '1'],
                                ['watch_description_enabled', __('Description'), '1'],
                                ['watch_metadata_chips_enabled', __('Metadata Chips'), '1'],
                                ['watch_comments_preview_enabled', __('Comments Preview'), '1'],
                                ['watch_sidebar_sticky_enabled', __('Sticky Sidebar'), '1'],
                                ['watch_player_ambient_glow_enabled', __('Player Ambient Glow'), '1'],
                                ['watch_action_panel_enabled', __('Action Panel Surface'), '1'],
                                ['watch_up_next_card_enabled', __('Up Next Card'), '1'],
                                ['watch_description_default_open', __('Description Starts Open'), '0'],
                                ['watch_related_heading_enabled', __('Related Heading'), '0'],
                                ['watch_related_show_channel_enabled', __('Related Channel'), '1'],
                                ['watch_related_show_meta_enabled', __('Related Meta'), '1'],
                            ] as $toggle)
                                <label class="avd-switch-card">
                                    <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('appearance', $toggle[0], $appearanceDefaults[$toggle[0]] ?? $toggle[2]))>
                                    <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="avd-field-grid mt-3">
                            <div>
                                <label class="form-label fw-bold" for="watch_ambient_glow_intensity">{{__('Ambient Glow Intensity')}}</label>
                                <select id="watch_ambient_glow_intensity" name="watch_ambient_glow_intensity" class="form-select">
                                    @foreach($ambientGlowIntensities as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'watch_ambient_glow_intensity', $appearanceDefaults['watch_ambient_glow_intensity']) === $key)>{{ $display(__($label), ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="watch_autoplay_countdown_seconds">{{__('Autoplay Countdown (Seconds)')}}</label>
                                <input id="watch_autoplay_countdown_seconds" type="number" name="watch_autoplay_countdown_seconds" class="form-control" min="0" max="30" value="{{ $value('appearance', 'watch_autoplay_countdown_seconds', $appearanceDefaults['watch_autoplay_countdown_seconds']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="watch_player_frame_style">{{__('Player Frame Style')}}</label>
                                <select id="watch_player_frame_style" name="watch_player_frame_style" class="form-select">
                                    @foreach(['cinematic' => __('Cinematic'), 'soft' => __('Soft'), 'minimal' => __('Minimal')] as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'watch_player_frame_style', $appearanceDefaults['watch_player_frame_style']) === $key)>{{ $display($label, ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="watch_sidebar_density">{{__('Sidebar Density')}}</label>
                                <select id="watch_sidebar_density" name="watch_sidebar_density" class="form-select">
                                    @foreach(['compact' => __('Compact'), 'comfortable' => __('Comfortable'), 'relaxed' => __('Relaxed')] as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'watch_sidebar_density', $appearanceDefaults['watch_sidebar_density']) === $key)>{{ $display($label, ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="watch_related_source">{{__('Related Source')}}</label>
                                <select id="watch_related_source" name="watch_related_source" class="form-select">
                                    @foreach(\App\Support\NavigationSettings::watchRelatedSources() as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'watch_related_source', $navigationDefaults['watch_related_source']) === $key)>{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="watch_related_title">{{__('Related Heading Text')}}</label>
                                <input id="watch_related_title" type="text" name="watch_related_title" class="form-control" value="{{ $value('appearance', 'watch_related_title', $navigationDefaults['watch_related_title']) }}">
                            </div>
                        </div>
                        <div class="avd-section-title mt-4"><i class="fa-solid fa-layer-group text-primary"></i> {{__('Secondary page controls')}}</div>
                        <div class="avd-switch-grid">
                            @foreach([
                                ['secondary_page_design_enabled', __('Secondary Page Design'), '1'],
                                ['secondary_page_hero_enabled', __('Page Hero Band'), '1'],
                                ['secondary_page_surfaces_enabled', __('Polished Surfaces'), '1'],
                                ['secondary_page_form_polish_enabled', __('Form Styling'), '1'],
                                ['secondary_page_table_polish_enabled', __('Table Styling'), '1'],
                                ['contact_illustration_enabled', __('Contact Illustration'), '1'],
                                ['premium_comparison_enabled', __('Premium Comparison'), '1'],
                            ] as $toggle)
                                <label class="avd-switch-card">
                                    <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('appearance', $toggle[0], $appearanceDefaults[$toggle[0]] ?? $toggle[2]))>
                                    <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="avd-field-grid mt-3">
                            <div>
                                <label class="form-label fw-bold" for="secondary_page_density">{{__('Secondary Page Density')}}</label>
                                <select id="secondary_page_density" name="secondary_page_density" class="form-select">
                                    @foreach(['compact' => __('Compact'), 'comfortable' => __('Comfortable'), 'relaxed' => __('Relaxed')] as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'secondary_page_density', $appearanceDefaults['secondary_page_density']) === $key)>{{ $display($label, ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="secondary_page_max_width">{{__('Secondary Page Width')}}</label>
                                <select id="secondary_page_max_width" name="secondary_page_max_width" class="form-select">
                                    @foreach(['standard' => __('Standard'), 'wide' => __('Wide'), 'full' => __('Full Width')] as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'secondary_page_max_width', $appearanceDefaults['secondary_page_max_width']) === $key)>{{ $display($label, ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="avd-section-title mt-4"><i class="fa-solid fa-wand-magic-sparkles text-primary"></i> {{__('CSS and JavaScript effects')}}</div>
                        <div class="avd-switch-grid">
                            @foreach([
                                ['effects_enabled', __('Master Effects'), '1'],
                                ['css_animations_enabled', __('CSS Animations'), '1'],
                                ['css_transitions_enabled', __('CSS Transitions'), '1'],
                                ['css_hover_effects_enabled', __('Hover Movement'), '1'],
                                ['css_gradient_effects_enabled', __('Decorative Gradients'), '1'],
                                ['css_blur_effects_enabled', __('Glass Blur'), '1'],
                                ['css_shadow_effects_enabled', __('Shadows And Glow'), '1'],
                                ['css_sticky_header_enabled', __('Sticky Header'), '1'],
                                ['css_shimmer_effect_enabled', __('Skeleton Shimmer'), '1'],
                                ['headline_accent_enabled', __('Headline Gradient Text'), '1'],
                                ['headline_accent_animation_enabled', __('Headline Sheen Animation'), '1'],
                                ['js_anime_effects_enabled', __('Anime.js Enhancements'), '1'],
                                ['js_theme_memory_enabled', __('Theme Memory'), '1'],
                                ['js_theater_memory_enabled', __('Theater Memory'), '1'],
                                ['js_smooth_scroll_enabled', __('Smooth Scroll'), '1'],
                                ['js_copy_toast_enabled', __('Copy Toast'), '1'],
                                ['js_lazy_comments_enabled', __('Lazy Comments'), '1'],
                            ] as $toggle)
                                <label class="avd-switch-card">
                                    <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('appearance', $toggle[0], $appearanceDefaults[$toggle[0]] ?? $toggle[2]))>
                                    <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="avd-section-title mt-4"><i class="fa-solid fa-sliders text-primary"></i> {{__('Global affordances')}}</div>
                        <div class="avd-switch-grid">
                            @foreach([
                                ['video_card_hover_enabled', __('Card Hover Effects'), '1'],
                                ['video_card_progress_enabled', __('Card Progress Bars'), '1'],
                                ['announcement_banner_enabled', __('Announcement Banner'), '1'],
                                ['custom_code_enabled', __('Custom Code'), '1'],
                                ['back_to_top_enabled', __('Back To Top'), '1'],
                            ] as $toggle)
                                <label class="avd-switch-card">
                                    <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('appearance', $toggle[0], $appearanceDefaults[$toggle[0]] ?? $toggle[2]))>
                                    <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                                </label>
                            @endforeach
                        </div>

                        <div class="avd-section-title mt-4"><i class="fa-solid fa-film text-primary"></i> {{__('Video cards & stream preview')}}</div>
                        <div class="avd-field-grid">
                            <div>
                                <label class="form-label fw-bold" for="video_card_duration_style">{{__('Duration Badge Style')}}</label>
                                <select id="video_card_duration_style" name="video_card_duration_style" class="form-select">
                                    @foreach($videoCardDurationStyles as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'video_card_duration_style', $appearanceDefaults['video_card_duration_style']) === $key)>{{ $display(__($label), ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="video_card_title_lines">{{__('Title Lines Clamp')}}</label>
                                <select id="video_card_title_lines" name="video_card_title_lines" class="form-select">
                                    @foreach(['1' => __('1 Line (Truncated)'), '2' => __('2 Lines (Balanced)'), '3' => __('3 Lines (Expanded)'), '4' => __('4 Lines (Maximum)')] as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'video_card_title_lines', $appearanceDefaults['video_card_title_lines']) === (string)$key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="video_card_new_badge_days">{{__('"NEW" Badge Days Threshold')}}</label>
                                <input id="video_card_new_badge_days" type="number" name="video_card_new_badge_days" class="form-control" min="0" max="30" value="{{ $value('appearance', 'video_card_new_badge_days', $appearanceDefaults['video_card_new_badge_days']) }}">
                                <div class="form-text">{{__('Set to 0 to disable NEW badge on fresh uploads.')}}</div>
                            </div>
                        </div>
                        <div class="avd-switch-grid mt-3">
                            @foreach([
                                ['video_card_scrubbing_enabled', __('Hover Storyboard Scrubbing'), '1'],
                                ['video_card_hover_glow_enabled', __('Card Interactive Hover Glow'), '1'],
                                ['video_card_hd_badge_enabled', __('HD / 4K Stream Quality Badges'), '1'],
                            ] as $toggle)
                                <label class="avd-switch-card">
                                    <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('appearance', $toggle[0], $appearanceDefaults[$toggle[0]] ?? $toggle[2]))>
                                    <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                                </label>
                            @endforeach
                        </div>

                        <div class="avd-section-title mt-4"><i class="fa-solid fa-users-viewfinder text-primary"></i> {{__('Creator & category visuals')}}</div>
                        <div class="avd-field-grid">
                            <div>
                                <label class="form-label fw-bold" for="creator_avatar_ring_style">{{__('Creator Avatar Ring Style')}}</label>
                                <select id="creator_avatar_ring_style" name="creator_avatar_ring_style" class="form-select">
                                    @foreach($creatorAvatarRingStyles as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'creator_avatar_ring_style', $appearanceDefaults['creator_avatar_ring_style']) === $key)>{{ $display(__($label), ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="category_card_style">{{__('Category Card Visual Style')}}</label>
                                <select id="category_card_style" name="category_card_style" class="form-select">
                                    @foreach($categoryCardStyles as $key => $label)
                                        <option value="{{ $key }}" @selected((string)$value('appearance', 'category_card_style', $appearanceDefaults['category_card_style']) === $key)>{{ $display(__($label), ucfirst($key)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="avd-switch-grid mt-3">
                            @foreach([
                                ['creator_verified_badge_enabled', __('Creator Verified Checkmarks'), '1'],
                                ['category_video_count_pill_enabled', __('Category Video Count Pills'), '1'],
                            ] as $toggle)
                                <label class="avd-switch-card">
                                    <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('appearance', $toggle[0], $appearanceDefaults[$toggle[0]] ?? $toggle[2]))>
                                    <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="col-xl-4">
                        <div class="avd-appearance-preview">
                            <div class="avd-appearance-preview-top">
                                <span class="avd-preview-brand">
                                    <span class="avd-preview-play"><i class="fa-solid fa-play"></i></span>
                                    {{ config('app.name') }}
                                </span>
                                <span class="avd-status-pill"><i class="fa-solid fa-bolt"></i> {{__('Preview')}}</span>
                            </div>
                            <div class="avd-preview-card">
                                <div class="avd-preview-thumb"></div>
                                <div class="p-3">
                                    <div class="fw-black">{{__('Modern video card')}}</div>
                                    <div class="text-muted small">{{__('Brand colors, radius, motion and density apply site-wide.')}}</div>
                                    <button type="button" class="btn btn-primary btn-sm mt-3"><i class="fa-solid fa-play me-1"></i> {{__('Action')}}</button>
                                </div>
                            </div>
                            <div class="mt-3 p-3 rounded-4 border bg-body-tertiary" style="border-color: rgba(255,255,255,0.08) !important;">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="fa-solid fa-arrow-rotate-left text-danger"></i>
                                    <span class="fw-bold small text-white">{{ __('Factory Theme Reset') }}</span>
                                </div>
                                <p class="small text-muted mb-3" style="font-size: 0.78rem; line-height: 1.4;">
                                    {{ __('Revert all site colors, hero sliders, layouts, and physics to original factory defaults with 1 click.') }}
                                </p>
                                <button type="button" class="btn btn-sm btn-outline-danger rounded-pill w-100 fw-semibold reset-default-theme-btn py-1.5">
                                    <i class="fa-solid fa-arrow-rotate-left me-1"></i> {{ __('Reset to Default Theme') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <details class="mt-4">
                    <summary class="fw-bold mb-3">{{__('Advanced custom code')}}</summary>
                    <div class="avd-field-grid">
                        <div class="avd-field-span">
                            <label class="form-label fw-bold" for="custom_css">{{__('Custom CSS')}}</label>
                            <textarea id="custom_css" name="custom_css" class="form-control font-monospace" rows="8">{{ $value('appearance', 'custom_css') }}</textarea>
                        </div>
                        <div class="avd-field-span">
                            <label class="form-label fw-bold" for="custom_js">{{__('Custom JavaScript')}}</label>
                            <textarea id="custom_js" name="custom_js" class="form-control font-monospace" rows="8">{{ $value('appearance', 'custom_js') }}</textarea>
                        </div>
                        <div class="avd-field-span">
                            <label class="form-label fw-bold" for="custom_head_html">{{__('Custom Head HTML')}}</label>
                            <textarea id="custom_head_html" name="custom_head_html" class="form-control font-monospace" rows="5">{{ $value('appearance', 'custom_head_html') }}</textarea>
                        </div>
                    </div>
                </details>
                <div class="d-flex flex-wrap align-items-center gap-3 mt-4">
                    <button class="btn btn-primary px-4 py-2 fw-bold rounded-pill" type="submit">
                        <i class="fa-solid fa-save me-2"></i> {{__('Save Appearance')}}
                    </button>
                    <button type="button" class="btn btn-outline-danger px-3 py-2 fw-semibold rounded-pill reset-default-theme-btn">
                        <i class="fa-solid fa-arrow-rotate-left me-2"></i> {{__('Reset to Default Theme')}}
                    </button>
                </div>
            </form>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'discovery' ? 'show active' : '' }}" id="tab-discovery" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'discovery') }}" method="POST">
                @csrf @method('PUT')
                <div class="avd-section-title"><i class="fa-solid fa-hashtag text-primary"></i> {{__('Smart Discovery Tags')}}</div>
                <div class="form-text small mb-3">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    {{ __('The Smart Discovery, Creator Spotlight and Trending Collections rails render in the Grid / Classic home layouts (Appearance → Home layout). The Cinema, YouTube Spotlight, Bento, TikTok Shorts and Cyberpunk HUD layouts use their own sections, so these switches have no effect there. Smart Discovery needs videos published within the lookback window; otherwise the category fallback is shown.') }}
                </div>
                <div class="avd-switch-grid mb-4">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="smart_discovery_rail_enabled" value="1" @checked($isChecked('discovery', 'smart_discovery_rail_enabled', $smartDiscoveryDefaults['enabled']))>
                        <span class="fw-bold">{{__('Tags Enabled')}}</span>
                    </label>
                </div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="smart_discovery_rail_limit">{{__('Tag Item Limit')}}</label>
                        <input id="smart_discovery_rail_limit" type="number" name="smart_discovery_rail_limit" class="form-control" value="{{ $value('discovery', 'smart_discovery_rail_limit', $smartDiscoveryDefaults['limit']) }}" min="{{ $smartDiscoveryDefaults['limit_min'] }}" max="{{ $smartDiscoveryDefaults['limit_max'] }}" required>
                        <div class="form-text">{{__('Tags with the most public videos are shown first.')}}</div>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="smart_discovery_rail_lookback_days">{{__('Discovery Window Days')}}</label>
                        <input id="smart_discovery_rail_lookback_days" type="number" name="smart_discovery_rail_lookback_days" class="form-control" value="{{ $value('discovery', 'smart_discovery_rail_lookback_days', $smartDiscoveryDefaults['lookback_days']) }}" min="{{ $smartDiscoveryDefaults['lookback_days_min'] }}" max="{{ $smartDiscoveryDefaults['lookback_days_max'] }}" required>
                        <div class="form-text">{{__('Only public videos inside this recent window are counted.')}}</div>
                    </div>
                </div>
                <div class="avd-section-title mt-4"><i class="fa-solid fa-pen text-primary"></i> {{__('Smart Discovery Text')}}</div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="smart_discovery_rail_kicker">{{__('Kicker')}}</label>
                        <input id="smart_discovery_rail_kicker" type="text" name="smart_discovery_rail_kicker" class="form-control" value="{{ $value('discovery', 'smart_discovery_rail_kicker', $homeWidgetVideoDefaults['smart_discovery_rail_kicker']) }}" maxlength="160">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="smart_discovery_rail_title">{{__('Title')}}</label>
                        <input id="smart_discovery_rail_title" type="text" name="smart_discovery_rail_title" class="form-control" value="{{ $value('discovery', 'smart_discovery_rail_title', $homeWidgetVideoDefaults['smart_discovery_rail_title']) }}" maxlength="160">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="smart_discovery_rail_fallback_kicker">{{__('Fallback Kicker')}}</label>
                        <input id="smart_discovery_rail_fallback_kicker" type="text" name="smart_discovery_rail_fallback_kicker" class="form-control" value="{{ $value('discovery', 'smart_discovery_rail_fallback_kicker', $homeWidgetVideoDefaults['smart_discovery_rail_fallback_kicker']) }}" maxlength="160">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="smart_discovery_rail_fallback_title">{{__('Fallback Title')}}</label>
                        <input id="smart_discovery_rail_fallback_title" type="text" name="smart_discovery_rail_fallback_title" class="form-control" value="{{ $value('discovery', 'smart_discovery_rail_fallback_title', $homeWidgetVideoDefaults['smart_discovery_rail_fallback_title']) }}" maxlength="160">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="smart_discovery_rail_link_label">{{__('Link Label')}}</label>
                        <input id="smart_discovery_rail_link_label" type="text" name="smart_discovery_rail_link_label" class="form-control" value="{{ $value('discovery', 'smart_discovery_rail_link_label', $homeWidgetVideoDefaults['smart_discovery_rail_link_label']) }}" maxlength="160">
                    </div>
                </div>
                <div class="avd-section-title mt-4"><i class="fa-solid fa-film text-primary"></i> {{__('Smart Discovery Tag Videos')}}</div>
                <div class="avd-switch-grid mb-3">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="smart_discovery_rail_videos_enabled" value="1" @checked($isChecked('discovery', 'smart_discovery_rail_videos_enabled', $homeWidgetVideoDefaults['smart_discovery_rail_videos_enabled']))>
                        <span class="fw-bold">{{__('Show Videos')}}</span>
                    </label>
                </div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="smart_discovery_rail_video_source">{{__('Video Source')}}</label>
                        <select id="smart_discovery_rail_video_source" name="smart_discovery_rail_video_source" class="form-select">
                            @foreach($homeFeedSources as $key => $label)
                                <option value="{{ $key }}" @selected((string)$value('discovery', 'smart_discovery_rail_video_source', $homeWidgetVideoDefaults['smart_discovery_rail_video_source']) === $key)>{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="smart_discovery_rail_video_category_id">{{__('Video Category')}}</label>
                        <select id="smart_discovery_rail_video_category_id" name="smart_discovery_rail_video_category_id" class="form-select">
                            <option value="0" @selected((string)$value('discovery', 'smart_discovery_rail_video_category_id', $homeWidgetVideoDefaults['smart_discovery_rail_video_category_id']) === '0')>{{__('All Categories')}}</option>
                            @foreach($homeFeedCategories as $category)
                                <option value="{{ $category->id }}" @selected((string)$value('discovery', 'smart_discovery_rail_video_category_id', $homeWidgetVideoDefaults['smart_discovery_rail_video_category_id']) === (string)$category->id)>{{ __($category->title) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="smart_discovery_rail_video_columns">{{__('Videos Per Row')}}</label>
                        <input id="smart_discovery_rail_video_columns" type="number" name="smart_discovery_rail_video_columns" class="form-control" value="{{ $value('discovery', 'smart_discovery_rail_video_columns', $homeWidgetVideoDefaults['smart_discovery_rail_video_columns']) }}" min="{{ \App\Support\AppearanceSettings::HOME_FEED_MIN_COLUMNS }}" max="{{ \App\Support\AppearanceSettings::HOME_FEED_MAX_COLUMNS }}" required>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="smart_discovery_rail_video_rows">{{__('Video Rows')}}</label>
                        <input id="smart_discovery_rail_video_rows" type="number" name="smart_discovery_rail_video_rows" class="form-control" value="{{ $value('discovery', 'smart_discovery_rail_video_rows', $homeWidgetVideoDefaults['smart_discovery_rail_video_rows']) }}" min="{{ \App\Support\AppearanceSettings::HOME_FEED_MIN_ROWS }}" max="{{ \App\Support\AppearanceSettings::HOME_FEED_MAX_ROWS }}" required>
                    </div>
                </div>
                <hr class="my-4">
                <div class="avd-section-title"><i class="fa-solid fa-users-viewfinder text-primary"></i> {{__('Creator Spotlight')}}</div>
                <div class="avd-switch-grid mb-4">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="creator_spotlight_enabled" value="1" @checked($isChecked('discovery', 'creator_spotlight_enabled', $creatorSpotlightDefaults['enabled']))>
                        <span class="fw-bold">{{__('Spotlight Enabled')}}</span>
                    </label>
                </div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="creator_spotlight_limit">{{__('Spotlight Item Limit')}}</label>
                        <input id="creator_spotlight_limit" type="number" name="creator_spotlight_limit" class="form-control" value="{{ $value('discovery', 'creator_spotlight_limit', $creatorSpotlightDefaults['limit']) }}" min="{{ $creatorSpotlightDefaults['limit_min'] }}" max="{{ $creatorSpotlightDefaults['limit_max'] }}" required>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="creator_spotlight_lookback_days">{{__('Creator Lookback Days')}}</label>
                        <input id="creator_spotlight_lookback_days" type="number" name="creator_spotlight_lookback_days" class="form-control" value="{{ $value('discovery', 'creator_spotlight_lookback_days', $creatorSpotlightDefaults['lookback_days']) }}" min="{{ $creatorSpotlightDefaults['lookback_days_min'] }}" max="{{ $creatorSpotlightDefaults['lookback_days_max'] }}" required>
                    </div>
                </div>
                <div class="avd-section-title mt-4"><i class="fa-solid fa-pen text-primary"></i> {{__('Creator Spotlight Text')}}</div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="creator_spotlight_kicker">{{__('Kicker')}}</label>
                        <input id="creator_spotlight_kicker" type="text" name="creator_spotlight_kicker" class="form-control" value="{{ $value('discovery', 'creator_spotlight_kicker', $homeWidgetVideoDefaults['creator_spotlight_kicker']) }}" maxlength="160">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="creator_spotlight_title">{{__('Title')}}</label>
                        <input id="creator_spotlight_title" type="text" name="creator_spotlight_title" class="form-control" value="{{ $value('discovery', 'creator_spotlight_title', $homeWidgetVideoDefaults['creator_spotlight_title']) }}" maxlength="160">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="creator_spotlight_fallback_kicker">{{__('Fallback Kicker')}}</label>
                        <input id="creator_spotlight_fallback_kicker" type="text" name="creator_spotlight_fallback_kicker" class="form-control" value="{{ $value('discovery', 'creator_spotlight_fallback_kicker', $homeWidgetVideoDefaults['creator_spotlight_fallback_kicker']) }}" maxlength="160">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="creator_spotlight_fallback_title">{{__('Fallback Title')}}</label>
                        <input id="creator_spotlight_fallback_title" type="text" name="creator_spotlight_fallback_title" class="form-control" value="{{ $value('discovery', 'creator_spotlight_fallback_title', $homeWidgetVideoDefaults['creator_spotlight_fallback_title']) }}" maxlength="160">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="creator_spotlight_link_label">{{__('Link Label')}}</label>
                        <input id="creator_spotlight_link_label" type="text" name="creator_spotlight_link_label" class="form-control" value="{{ $value('discovery', 'creator_spotlight_link_label', $homeWidgetVideoDefaults['creator_spotlight_link_label']) }}" maxlength="160">
                    </div>
                </div>
                <div class="avd-section-title mt-4"><i class="fa-solid fa-film text-primary"></i> {{__('Creator Spotlight Videos')}}</div>
                <div class="avd-switch-grid mb-3">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="creator_spotlight_videos_enabled" value="1" @checked($isChecked('discovery', 'creator_spotlight_videos_enabled', $homeWidgetVideoDefaults['creator_spotlight_videos_enabled']))>
                        <span class="fw-bold">{{__('Show Videos')}}</span>
                    </label>
                </div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="creator_spotlight_video_source">{{__('Video Source')}}</label>
                        <select id="creator_spotlight_video_source" name="creator_spotlight_video_source" class="form-select">
                            @foreach($homeFeedSources as $key => $label)
                                <option value="{{ $key }}" @selected((string)$value('discovery', 'creator_spotlight_video_source', $homeWidgetVideoDefaults['creator_spotlight_video_source']) === $key)>{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="creator_spotlight_video_category_id">{{__('Video Category')}}</label>
                        <select id="creator_spotlight_video_category_id" name="creator_spotlight_video_category_id" class="form-select">
                            <option value="0" @selected((string)$value('discovery', 'creator_spotlight_video_category_id', $homeWidgetVideoDefaults['creator_spotlight_video_category_id']) === '0')>{{__('All Categories')}}</option>
                            @foreach($homeFeedCategories as $category)
                                <option value="{{ $category->id }}" @selected((string)$value('discovery', 'creator_spotlight_video_category_id', $homeWidgetVideoDefaults['creator_spotlight_video_category_id']) === (string)$category->id)>{{ __($category->title) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="creator_spotlight_video_columns">{{__('Videos Per Row')}}</label>
                        <input id="creator_spotlight_video_columns" type="number" name="creator_spotlight_video_columns" class="form-control" value="{{ $value('discovery', 'creator_spotlight_video_columns', $homeWidgetVideoDefaults['creator_spotlight_video_columns']) }}" min="{{ \App\Support\AppearanceSettings::HOME_FEED_MIN_COLUMNS }}" max="{{ \App\Support\AppearanceSettings::HOME_FEED_MAX_COLUMNS }}" required>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="creator_spotlight_video_rows">{{__('Video Rows')}}</label>
                        <input id="creator_spotlight_video_rows" type="number" name="creator_spotlight_video_rows" class="form-control" value="{{ $value('discovery', 'creator_spotlight_video_rows', $homeWidgetVideoDefaults['creator_spotlight_video_rows']) }}" min="{{ \App\Support\AppearanceSettings::HOME_FEED_MIN_ROWS }}" max="{{ \App\Support\AppearanceSettings::HOME_FEED_MAX_ROWS }}" required>
                    </div>
                </div>
                <hr class="my-4">
                <div class="avd-section-title"><i class="fa-solid fa-user-group text-primary"></i> {{__('Trending Actors')}}</div>
                <div class="avd-switch-grid mb-4">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="trending_collections_enabled" value="1" @checked($isChecked('discovery', 'trending_collections_enabled', $trendingCollectionsDefaults['enabled']))>
                        <span class="fw-bold">{{__('Actors Enabled')}}</span>
                    </label>
                </div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="trending_collections_limit">{{__('Actor Item Limit')}}</label>
                        <input id="trending_collections_limit" type="number" name="trending_collections_limit" class="form-control" value="{{ $value('discovery', 'trending_collections_limit', $trendingCollectionsDefaults['limit']) }}" min="{{ $trendingCollectionsDefaults['limit_min'] }}" max="{{ $trendingCollectionsDefaults['limit_max'] }}" required>
                        <div class="form-text">{{__('Actors with the most recent views are shown first.')}}</div>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="trending_collections_lookback_days">{{__('Actor Lookback Days')}}</label>
                        <input id="trending_collections_lookback_days" type="number" name="trending_collections_lookback_days" class="form-control" value="{{ $value('discovery', 'trending_collections_lookback_days', $trendingCollectionsDefaults['lookback_days']) }}" min="{{ $trendingCollectionsDefaults['lookback_days_min'] }}" max="{{ $trendingCollectionsDefaults['lookback_days_max'] }}" required>
                        <div class="form-text">{{__('Counts actor views inside this recent window.')}}</div>
                    </div>
                </div>
                <div class="avd-section-title mt-4"><i class="fa-solid fa-pen text-primary"></i> {{__('Trending Actor Text')}}</div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="trending_collections_kicker">{{__('Kicker')}}</label>
                        <input id="trending_collections_kicker" type="text" name="trending_collections_kicker" class="form-control" value="{{ $value('discovery', 'trending_collections_kicker', $homeWidgetVideoDefaults['trending_collections_kicker']) }}" maxlength="160">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="trending_collections_title">{{__('Title')}}</label>
                        <input id="trending_collections_title" type="text" name="trending_collections_title" class="form-control" value="{{ $value('discovery', 'trending_collections_title', $homeWidgetVideoDefaults['trending_collections_title']) }}" maxlength="160">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="trending_collections_fallback_kicker">{{__('Fallback Kicker')}}</label>
                        <input id="trending_collections_fallback_kicker" type="text" name="trending_collections_fallback_kicker" class="form-control" value="{{ $value('discovery', 'trending_collections_fallback_kicker', $homeWidgetVideoDefaults['trending_collections_fallback_kicker']) }}" maxlength="160">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="trending_collections_fallback_title">{{__('Fallback Title')}}</label>
                        <input id="trending_collections_fallback_title" type="text" name="trending_collections_fallback_title" class="form-control" value="{{ $value('discovery', 'trending_collections_fallback_title', $homeWidgetVideoDefaults['trending_collections_fallback_title']) }}" maxlength="160">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="trending_collections_link_label">{{__('Link Label')}}</label>
                        <input id="trending_collections_link_label" type="text" name="trending_collections_link_label" class="form-control" value="{{ $value('discovery', 'trending_collections_link_label', $homeWidgetVideoDefaults['trending_collections_link_label']) }}" maxlength="160">
                    </div>
                </div>
                <div class="avd-section-title mt-4"><i class="fa-solid fa-film text-primary"></i> {{__('Trending Actor Videos')}}</div>
                <div class="avd-switch-grid mb-3">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="trending_collections_videos_enabled" value="1" @checked($isChecked('discovery', 'trending_collections_videos_enabled', $homeWidgetVideoDefaults['trending_collections_videos_enabled']))>
                        <span class="fw-bold">{{__('Show Videos')}}</span>
                    </label>
                </div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="trending_collections_video_source">{{__('Video Source')}}</label>
                        <select id="trending_collections_video_source" name="trending_collections_video_source" class="form-select">
                            @foreach($homeFeedSources as $key => $label)
                                <option value="{{ $key }}" @selected((string)$value('discovery', 'trending_collections_video_source', $homeWidgetVideoDefaults['trending_collections_video_source']) === $key)>{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="trending_collections_video_category_id">{{__('Video Category')}}</label>
                        <select id="trending_collections_video_category_id" name="trending_collections_video_category_id" class="form-select">
                            <option value="0" @selected((string)$value('discovery', 'trending_collections_video_category_id', $homeWidgetVideoDefaults['trending_collections_video_category_id']) === '0')>{{__('All Categories')}}</option>
                            @foreach($homeFeedCategories as $category)
                                <option value="{{ $category->id }}" @selected((string)$value('discovery', 'trending_collections_video_category_id', $homeWidgetVideoDefaults['trending_collections_video_category_id']) === (string)$category->id)>{{ __($category->title) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="trending_collections_video_columns">{{__('Videos Per Row')}}</label>
                        <input id="trending_collections_video_columns" type="number" name="trending_collections_video_columns" class="form-control" value="{{ $value('discovery', 'trending_collections_video_columns', $homeWidgetVideoDefaults['trending_collections_video_columns']) }}" min="{{ \App\Support\AppearanceSettings::HOME_FEED_MIN_COLUMNS }}" max="{{ \App\Support\AppearanceSettings::HOME_FEED_MAX_COLUMNS }}" required>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="trending_collections_video_rows">{{__('Video Rows')}}</label>
                        <input id="trending_collections_video_rows" type="number" name="trending_collections_video_rows" class="form-control" value="{{ $value('discovery', 'trending_collections_video_rows', $homeWidgetVideoDefaults['trending_collections_video_rows']) }}" min="{{ \App\Support\AppearanceSettings::HOME_FEED_MIN_ROWS }}" max="{{ \App\Support\AppearanceSettings::HOME_FEED_MAX_ROWS }}" required>
                    </div>
                </div>
                <hr class="my-4">
                <div class="avd-section-title"><i class="fa-solid fa-gauge-high text-primary"></i> {{__('Playback Progress Signal')}}</div>
                <div class="avd-switch-grid mb-4">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="playback_progress_signal_enabled" value="1" @checked($isChecked('discovery', 'playback_progress_signal_enabled', $playbackProgressDefaults['enabled']))>
                        <span class="fw-bold">{{__('Progress Signal Enabled')}}</span>
                    </label>
                </div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="playback_progress_signal_min_duration_seconds">{{__('Minimum Video Duration Seconds')}}</label>
                        <input id="playback_progress_signal_min_duration_seconds" type="number" name="playback_progress_signal_min_duration_seconds" class="form-control" value="{{ $value('discovery', 'playback_progress_signal_min_duration_seconds', $playbackProgressDefaults['min_duration_seconds']) }}" min="{{ $playbackProgressDefaults['min_duration_seconds_min'] }}" max="{{ $playbackProgressDefaults['min_duration_seconds_max'] }}" required>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="playback_progress_signal_retention_days">{{__('Retention Days')}}</label>
                        <input id="playback_progress_signal_retention_days" type="number" name="playback_progress_signal_retention_days" class="form-control" value="{{ $value('discovery', 'playback_progress_signal_retention_days', $playbackProgressDefaults['retention_days']) }}" min="{{ $playbackProgressDefaults['retention_days_min'] }}" max="{{ $playbackProgressDefaults['retention_days_max'] }}" required>
                    </div>
                </div>
                <button class="btn btn-primary mt-4" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save Discovery')}}</button>
            </form>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'uploads' ? 'show active' : '' }}" id="tab-uploads" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'uploads') }}" method="POST">
                @csrf @method('PUT')
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="avd-section-title mb-0"><i class="fa-solid fa-sliders text-primary"></i> {{__('Video Upload Rules & Limits')}}</div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1">Direct & Remote Storage</span>
                </div>
                
                <div class="card border p-3 mb-3 bg-body-tertiary">
                    <h6 class="fw-bold mb-2 text-primary small text-uppercase"><i class="fa-solid fa-film me-1"></i> {{ __('Media Specifications') }}</h6>
                    <div class="avd-field-grid">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <label class="form-label fw-bold mb-0" for="uploadMaxDurationInput">{{__('Max Duration (Seconds)')}}</label>
                                <span id="uploadDurationBadge" class="badge bg-info-subtle text-info border border-info-subtle font-monospace">3600s = 1 hr 0 min</span>
                            </div>
                            <input type="number" name="upload_max_duration" id="uploadMaxDurationInput" class="form-control" value="{{ $value('uploads', 'upload_max_duration', 3600) }}" min="1" max="86400" required>
                            <div class="d-flex gap-1 flex-wrap mt-1">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 duration-preset-btn" data-sec="900">15m</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 duration-preset-btn" data-sec="1800">30m</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 duration-preset-btn" data-sec="3600">1h</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 duration-preset-btn" data-sec="7200">2h</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 duration-preset-btn" data-sec="14400">4h</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 duration-preset-btn" data-sec="86400">24h</button>
                            </div>
                        </div>
                        <div>
                            <label class="form-label fw-bold" for="uploadAllowedFormatsInput">{{__('Allowed Formats')}}</label>
                            <input type="text" name="upload_allowed_formats" id="uploadAllowedFormatsInput" class="form-control" value="{{ $value('uploads', 'upload_allowed_formats', $uploadAllowedFormatsDefault) }}" placeholder="mp4,webm,avi,mov,mkv" required>
                            <div class="d-flex gap-1 flex-wrap mt-1">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 formats-preset-btn" data-formats="mp4,webm">Web Standard</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 formats-preset-btn" data-formats="mp4,webm,avi,mov,mkv">Universal</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 formats-preset-btn" data-formats="mp4,webm,mkv,avi,mov,wmv,flv,m4v">All Formats</button>
                            </div>
                        </div>
                        <div>
                            <label class="form-label fw-bold" for="default_video_status">{{__('Default Video Status')}}</label>
                            <select id="default_video_status" name="default_video_status" class="form-select">
                                @foreach($uploadVideoStatusOptions as $id => $name)
                                    <option value="{{ $id }}" @selected($normalizeVideoStatusSetting($value('uploads', 'default_video_status', \App\Enums\VideoStatus::PUBLIC->value)) === (string) $id)>{{ __($name) }}</option>
                                @endforeach
                            </select>
                            <div class="form-text small">{{ __('Initial status after upload before user edits') }}</div>
                        </div>
                        <div>
                            <label class="form-label fw-bold" for="default_video_language">{{__('Default Video Language')}}</label>
                            <select id="default_video_language" name="default_video_language" class="form-select">
                                <option value="">{{ __('No default') }}</option>
                                @foreach($uploadVideoLanguages as $code => $language)
                                    <option value="{{ $code }}" @selected((string)$value('uploads', 'default_video_language', 'en') === (string)$code)>{{ ucfirst($language) }}</option>
                                @endforeach
                            </select>
                            <div class="form-text small">{{ __('Auto-selected language in upload form') }}</div>
                        </div>
                        <div>
                            <label class="form-label fw-bold" for="thumbWidthInput">{{__('Generated Thumbnail Width')}}</label>
                            <input type="number" name="thumbnail_width" id="thumbWidthInput" class="form-control" value="{{ $value('uploads', 'thumbnail_width', 640) }}" min="160" max="3840" required>
                        </div>
                        <div>
                            <label class="form-label fw-bold" for="thumbHeightInput">{{__('Generated Thumbnail Height')}}</label>
                            <input type="number" name="thumbnail_height" id="thumbHeightInput" class="form-control" value="{{ $value('uploads', 'thumbnail_height', 360) }}" min="90" max="2160" required>
                            <div class="d-flex gap-1 flex-wrap mt-1">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 thumb-preset-btn" data-w="640" data-h="360">360p (SD)</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 thumb-preset-btn" data-w="854" data-h="480">480p (FWVGA)</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 thumb-preset-btn" data-w="1280" data-h="720">720p (HD)</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 thumb-preset-btn" data-w="1920" data-h="1080">1080p (FHD)</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="avd-switch-grid my-3">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="allow_custom_thumbnails" value="1" @checked($isChecked('uploads', 'allow_custom_thumbnails', '1'))>
                        <span>
                            <span class="fw-bold d-block">{{__('Allow Custom Thumbnails')}}</span>
                            <span class="text-muted small">{{__('Creators can upload custom poster artwork (JPEG/PNG/WEBP) instead of generated frames.')}}</span>
                        </span>
                    </label>
                </div>

                <div class="card border p-3 mt-4 bg-body-tertiary">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="avd-section-title mb-0"><i class="fa-solid fa-cloud-arrow-up text-primary"></i> {{__('Upload18 Remote Storage Integration')}}</div>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Chunked Video Delivery</span>
                    </div>
                    <div class="avd-field-grid">
                        <div>
                            <label class="form-label fw-bold" for="upload18_api_key">{{__('Upload18 API Key')}}</label>
                            <input id="upload18_api_key" type="text" name="upload18_api_key" class="form-control font-monospace" value="{{ $value('uploads', 'upload18_api_key', config('services.upload18.api_key', '')) }}" placeholder="Enter Upload18 API Key">
                        </div>
                        <div>
                            <label class="form-label fw-bold" for="upload18_api_url">{{__('Upload18 API URL')}}</label>
                            <input id="upload18_api_url" type="url" name="upload18_api_url" class="form-control" value="{{ $value('uploads', 'upload18_api_url', config('services.upload18.api_url', 'https://upload18.org/api')) }}">
                        </div>
                        @foreach([
                            ['upload18_default_cid', __('Upload18 Category ID'), config('services.upload18.default_cid', '15'), 'Remote category index'],
                            ['upload18_default_fid', __('Upload18 Server ID'), config('services.upload18.default_fid', '18'), 'Remote storage server node'],
                            ['upload18_default_mycid', __('Upload18 Account Folder ID'), config('services.upload18.default_mycid', '0'), 'User folder identifier'],
                            ['upload18_chunk_size_mb', __('Chunk Size (MB)'), config('site.upload18_chunk_size_mb', $uploadDefaults['upload18_chunk_size_mb']), 'Chunk slicing for stable uploads', $uploadDefaults['upload18_chunk_size_mb_min'], $uploadDefaults['upload18_chunk_size_mb_max']],
                            ['upload18_thumbnail_count', __('Auto Thumbnail Count'), config('site.upload18_thumbnail_count', $uploadDefaults['upload18_thumbnail_count']), 'Frames extracted per video', $uploadDefaults['upload18_thumbnail_count_min'], $uploadDefaults['upload18_thumbnail_count_max']],
                            ['max_thumbnail_size', __('Thumbnail Max Size (KB)'), config('site.max_thumbnail_size', $uploadDefaults['max_thumbnail_size']), 'Max allowable image size', $uploadDefaults['max_thumbnail_size_min'], $uploadDefaults['max_thumbnail_size_max']],
                        ] as $field)
                            <div>
                                <label class="form-label fw-bold" for="{{ $field[0] }}">{{ $display($field[1]) }}</label>
                                <input id="{{ $field[0] }}" type="number" name="{{ $field[0] }}" class="form-control" value="{{ $value('uploads', $field[0], $field[2]) }}" min="{{ $field[4] ?? 0 }}" @isset($field[5]) max="{{ $field[5] }}" @endisset>
                                <div class="form-text small">{{ $field[3] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <button class="btn btn-primary mt-4 px-4 py-2" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save Upload Settings')}}</button>
            </form>
        </div><div class="tab-pane fade {{ $activeTab === 'notifications' ? 'show active' : '' }}" id="tab-notifications" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'notifications') }}" method="POST">
                @csrf @method('PUT')
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="avd-section-title mb-0"><i class="fa-solid fa-bell text-primary"></i> {{__('Notification Delivery & Broadcast Engine')}}</div>
                    <a href="{{ route('admin.broadcast.index') }}" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-bullhorn me-1"></i> {{ __('Manage Broadcasts') }}</a>
                </div>
                <div class="card border p-3 mb-3 bg-body-tertiary">
                    <div class="row align-items-center g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold" for="broadcast_notification_chunk_size">{{__('Broadcast Dispatch Chunk Size')}}</label>
                            <input id="broadcast_notification_chunk_size"
                                type="number"
                                name="broadcast_notification_chunk_size"
                                class="form-control"
                                value="{{ $value('notifications', 'broadcast_notification_chunk_size', $notificationBroadcastDefaults['broadcast_notification_chunk_size']) }}"
                                min="{{ $notificationBroadcastDefaults['broadcast_notification_chunk_size_min'] }}"
                                max="{{ $notificationBroadcastDefaults['broadcast_notification_chunk_size_max'] }}"
                                required
                            >
                            <div class="form-text">{{ __('Controls how many user notifications are queued per batch job. Default: 50. Lower values reduce memory spikes on large subscriber lists.') }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="alert alert-info mb-0 small">
                                <i class="fa-solid fa-circle-info me-1"></i>
                                <strong>{{ __('Queue Notice') }}:</strong>
                                {{ __('Broadcast dispatches use your background queue worker. Ensure queue worker is running so notifications deliver instantly.') }}
                            </div>
                        </div>
                    </div>
                </div>
                <button class="btn btn-primary mt-3 px-4 py-2" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save Notification Settings')}}</button>
            </form>
        </div><div class="tab-pane fade {{ $activeTab === 'email' ? 'show active' : '' }}" id="tab-email" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'email') }}" method="POST">
                @csrf @method('PUT')
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="avd-section-title mb-0"><i class="fa-solid fa-envelope-open-text text-primary"></i> {{__('Mail Delivery & SMTP Configuration')}}</div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1">SMTP & Live Diagnostic</span>
                </div>

                <!-- 1-Click SMTP Presets Toolbar -->
                <div class="card border p-3 mb-3 bg-body-tertiary">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                        <span class="fw-bold small text-uppercase text-muted"><i class="fa-solid fa-bolt text-warning me-1"></i> {{ __('1-Click SMTP Presets') }}</span>
                        <span class="small text-muted">{{ __('Click to auto-populate Host, Port, and Encryption') }}</span>
                    </div>
                    <div class="d-flex gap-2 flex-wrap" id="smtpPresetsToolbar">
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill smtp-preset-btn"
                                data-mailer="smtp" data-host="smtp.gmail.com" data-port="587" data-enc="tls">
                            <i class="fa-brands fa-google text-danger me-1"></i>Gmail (587 TLS)
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill smtp-preset-btn"
                                data-mailer="smtp" data-host="smtp-relay.brevo.com" data-port="587" data-enc="tls">
                            <i class="fa-solid fa-paper-plane text-info me-1"></i>Brevo / Sendinblue (587)
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill smtp-preset-btn"
                                data-mailer="smtp" data-host="smtp.mailgun.org" data-port="587" data-enc="tls">
                            <i class="fa-solid fa-envelopes-bulk text-warning me-1"></i>Mailgun (587)
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill smtp-preset-btn"
                                data-mailer="smtp" data-host="email-smtp.us-east-1.amazonaws.com" data-port="587" data-enc="tls">
                            <i class="fa-brands fa-aws text-warning me-1"></i>Amazon SES (587)
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill smtp-preset-btn"
                                data-mailer="smtp" data-host="127.0.0.1" data-port="1025" data-enc="">
                            <i class="fa-solid fa-laptop-code text-success me-1"></i>Local Mailpit (1025)
                        </button>
                    </div>
                </div>

                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="mailMailerSelect">{{__('Mailer Engine')}}</label>
                        <select name="mail_mailer" id="mailMailerSelect" class="form-select">
                            @foreach($mailMailerOptions as $mailer)
                                <option value="{{ $mailer }}" @selected((string)$value('email', 'mail_mailer', $env['mail_mailer'] ?? config('mail.default', 'smtp')) === $mailer)>{{ \Illuminate\Support\Str::headline($mailer) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="smtpHostInput">{{__('SMTP Host')}}</label>
                        <input type="text" name="smtp_host" id="smtpHostInput" class="form-control" value="{{ $value('email', 'smtp_host', $env['mail_host'] ?? config('mail.mailers.smtp.host')) }}" placeholder="smtp.example.com">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="smtpPortInput">{{__('SMTP Port')}}</label>
                        <input type="number" name="smtp_port" id="smtpPortInput" class="form-control" value="{{ $value('email', 'smtp_port', $env['mail_port'] ?? config('mail.mailers.smtp.port', 587)) }}" min="1" max="65535" required>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="smtpEncryptionSelect">{{__('Encryption')}}</label>
                        <select name="smtp_encryption" id="smtpEncryptionSelect" class="form-select">
                            @foreach(['' => __('None'), 'tls' => 'TLS', 'ssl' => 'SSL'] as $key => $label)
                                <option value="{{ $key }}" @selected((string)$value('email', 'smtp_encryption', $env['mail_encryption'] ?? config('mail.mailers.smtp.encryption', 'tls')) === (string)$key)>{{ $display($label, strtoupper($key ?: 'none')) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="smtpUsernameInput">{{__('SMTP Username')}}</label>
                        <input type="text" name="smtp_username" id="smtpUsernameInput" class="form-control" value="{{ $value('email', 'smtp_username', $env['mail_username'] ?? config('mail.mailers.smtp.username')) }}" autocomplete="off">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="smtpPasswordInput">{{__('SMTP Password')}}</label>
                        <input type="password" name="smtp_password" id="smtpPasswordInput" class="form-control" value="{{ $value('email', 'smtp_password', config('mail.mailers.smtp.password')) }}" autocomplete="new-password">
                    </div>
                </div>

                <div class="avd-section-title mt-4"><i class="fa-solid fa-at text-primary"></i> {{__('Sender Addresses & Alert Routing')}}</div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="mailFromAddressInput">{{__('From Address')}}</label>
                        <input type="email" name="mail_from_address" id="mailFromAddressInput" class="form-control" value="{{ $value('email', 'mail_from_address', $env['mail_from_address'] ?? config('mail.from.address')) }}" required>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="mail_from_name">{{__('From Name')}}</label>
                        <input id="mail_from_name" type="text" name="mail_from_name" class="form-control" value="{{ $value('email', 'mail_from_name', $env['mail_from_name'] ?? config('mail.from.name')) }}">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="support_email">{{__('Support Email')}}</label>
                        <input id="support_email" type="email" name="support_email" class="form-control" value="{{ $value('email', 'support_email', $env['support_email'] ?? config('mail.support_mail')) }}">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="server_email">{{__('Server Alert Email')}}</label>
                        <input id="server_email" type="email" name="server_email" class="form-control" value="{{ $value('email', 'server_email', $env['server_email'] ?? config('mail.server_mail')) }}">
                    </div>
                </div>

                <!-- Live Email Diagnostic Card -->
                <div class="card border border-info-subtle mt-4 p-3 bg-body-tertiary">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle bg-info text-white d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                                <i class="fa-solid fa-vial"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0">{{ __('Live SMTP Delivery Tester') }}</h6>
                                <span class="small text-muted">{{ __('Send a test email to verify credentials and TLS handshake immediately.') }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="row g-2 align-items-center mt-1">
                        <div class="col-md-7">
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-paper-plane text-muted"></i></span>
                                <input type="email" id="testEmailRecipient" class="form-control"
                                       value="{{ $value('email', 'contact_email', $value('general', 'contact_email', config('mail.from.address'))) }}"
                                       placeholder="admin@example.com">
                            </div>
                        </div>
                        <div class="col-md-5">
                            <button type="button" id="btnSendTestEmail" class="btn btn-info text-white w-100 fw-bold">
                                <i class="fa-solid fa-paper-plane me-1"></i> {{ __('Send Test Email Now') }}
                            </button>
                        </div>
                    </div>
                    <div id="testEmailAlert" class="mt-2.5 d-none"></div>
                </div>

                <button class="btn btn-primary mt-4 px-4 py-2" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save Email Settings')}}</button>
            </form>
        </div><div class="tab-pane fade {{ $activeTab === 'comments' ? 'show active' : '' }}" id="tab-comments" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'comments') }}" method="POST">
                @csrf @method('PUT')
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="avd-section-title mb-0"><i class="fa-solid fa-comments text-primary"></i> {{__('Comment System & Discussion Engine')}}</div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1">Moderation & Feeds</span>
                </div>
                <div class="avd-switch-grid mb-4">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="comments_enabled" value="1" @checked($isChecked('comments', 'comments_enabled', '1'))>
                        <span>
                            <span class="fw-bold d-block">{{__('Enable Video Comments')}}</span>
                            <span class="text-muted small">{{ __('Allows visitors to post comments on public videos.') }}</span>
                        </span>
                    </label>
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="allow_replies" value="1" @checked($isChecked('comments', 'allow_replies', '1'))>
                        <span>
                            <span class="fw-bold d-block">{{__('Allow Threaded Replies')}}</span>
                            <span class="text-muted small">{{ __('Permits nested comment replies under parent messages.') }}</span>
                        </span>
                    </label>
                </div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="per_page_replies">{{ __('Replies Per Page') }}</label>
                        <input id="per_page_replies" type="number" name="per_page_replies" class="form-control" value="{{ $value('comments', 'per_page_replies', 10) }}" min="1">
                        <div class="form-text small">{{ __('Number of replies shown before expanding "Show more".') }}</div>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="comment_preview_length">{{ __('Comment Preview Length (Characters)') }}</label>
                        <input id="comment_preview_length" type="number" name="comment_preview_length" class="form-control" value="{{ $value('comments', 'comment_preview_length', 500) }}" min="80">
                        <div class="form-text small">{{ __('Character cutoff before showing "Read more" toggle.') }}</div>
                    </div>
                </div>

                <div class="card border p-3 mt-4 bg-body-tertiary">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="avd-section-title mb-0"><i class="fa-solid fa-chart-line text-primary"></i> {{__('Video View Tracking & Analytics Retention')}}</div>
                        <span class="badge bg-info-subtle text-info border border-info-subtle">Database Optimization</span>
                    </div>
                    <p class="form-text mt-0 mb-3">{{__('Fine-tune how video views are registered, cooldown timers against view botting, and retention cleanup jobs.')}}</p>
                    <div class="avd-field-grid">
                        <div>
                            <label class="form-label fw-bold" for="view_cooldown_minutes">{{__('View Cooldown (Minutes)')}}</label>
                            <input id="view_cooldown_minutes" type="number" name="view_cooldown_minutes" class="form-control" value="{{ $value('comments', 'view_cooldown_minutes', 5) }}" min="0" max="1440">
                            <div class="form-text small">{{ __('Minutes before repeat views from the same IP/user increment the count.') }}</div>
                        </div>
                        <div>
                            <label class="form-label fw-bold" for="view_log_mode">{{__('Raw View History Mode')}}</label>
                            <select id="view_log_mode" name="view_log_mode" class="form-select">
                                @foreach(($viewLogModes ?? []) as $modeKey => $modeLabel)
                                    <option value="{{ $modeKey }}" @selected((string)$value('comments', 'view_log_mode', $viewHistoryDefaults['log_mode'] ?? 'authenticated') === $modeKey)>{{ $display($modeLabel, \Illuminate\Support\Str::headline($modeKey)) }}</option>
                                @endforeach
                            </select>
                            <div class="form-text small">{{ __('Counter only keeps totals without saving every single raw view row.') }}</div>
                        </div>
                        <div>
                            <label class="form-label fw-bold" for="view_history_retention_days">{{__('Raw View Retention Days')}}</label>
                            <input id="view_history_retention_days" type="number" name="view_history_retention_days" class="form-control" value="{{ $value('comments', 'view_history_retention_days', $viewHistoryDefaults['retention_days'] ?? 90) }}" min="{{ $viewHistoryDefaults['retention_days_min'] ?? 1 }}" max="{{ $viewHistoryDefaults['retention_days_max'] ?? 3650 }}" required>
                            <div class="form-text small">{{ __('Scheduled cleanup deletes older raw view rows while video totals stay intact.') }}</div>
                        </div>
                    </div>
                    <div class="avd-switch-grid mt-3">
                        <label class="avd-switch-card">
                            <input class="form-check-input" type="checkbox" name="view_daily_stats_enabled" value="1" @checked($isChecked('comments', 'view_daily_stats_enabled', $viewHistoryDefaults['daily_stats_enabled'] ?? '1'))>
                            <span>
                                <span class="fw-bold d-block">{{__('Daily View Aggregates')}}</span>
                                <span class="text-muted small">{{__('Keep one compact row per video per day for high-speed charting and trending discovery.')}}</span>
                            </span>
                        </label>
                    </div>
                </div>
                <button class="btn btn-primary mt-4 px-4 py-2" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save Comment & View Settings')}}</button>
            </form>
        </div><div class="tab-pane fade {{ $activeTab === 'users' ? 'show active' : '' }}" id="tab-users" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'users') }}" method="POST">
                @csrf @method('PUT')
                <div class="avd-section-title"><i class="fa-solid fa-user-check text-primary"></i> {{__('Registration and verification')}}</div>
                <div class="avd-switch-grid mb-4">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="email_verification_required" value="1" @checked($isChecked('users', 'email_verification_required', '1'))>
                        <span>
                            <span class="fw-bold d-block">{{__('Require Email Verification')}}</span>
                            <span class="text-muted small">{{__('New users must verify email before verified-only actions.')}}</span>
                        </span>
                    </label>
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="allow_account_deletion" value="1" @checked($isChecked('users', 'allow_account_deletion', '1'))>
                        <span class="fw-bold">{{__('Allow Account Deletion')}}</span>
                    </label>
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="show_subscriber_count" value="1" @checked($isChecked('users', 'show_subscriber_count', '1'))>
                        <span class="fw-bold">{{__('Show Subscriber Counts')}}</span>
                    </label>
                </div>
                <div class="avd-section-title"><i class="fa-solid fa-id-card text-primary"></i> {{__('Account limits')}}</div>
                <div class="avd-field-grid">
                    @foreach([
                        ['max_register_attempts', __('Register Attempts Per IP'), 1],
                        ['max_avatar_size', __('Avatar Max Size KB'), 2048],
                        ['max_banner_size', __('Banner Max Size KB'), 5120],
                    ] as $field)
                        <div>
                            <label class="form-label fw-bold" for="{{ $field[0] }}">{{ $display($field[1], \Illuminate\Support\Str::headline($field[0])) }}</label>
                            <input id="{{ $field[0] }}" type="number" name="{{ $field[0] }}" class="form-control" value="{{ $value('users', $field[0], $field[2]) }}" min="1">
                        </div>
                    @endforeach
                </div>
                <hr class="my-4">
                <div class="avd-section-title"><i class="fa-solid fa-swatchbook text-primary"></i> {{__('Channel customization')}}</div>
                <p class="text-muted">{{__('Control which channel design choices creators can use and the default look for new or uncustomized channels.')}}</p>
                <div class="avd-switch-grid mb-4">
                    @foreach([
                        ['channel_customization_enabled', __('Customization Enabled')],
                        ['channel_customization_layout_enabled', __('Layout Choices')],
                        ['channel_customization_theme_enabled', __('Theme Choices')],
                        ['channel_customization_accent_enabled', __('Accent Choices')],
                        ['channel_customization_custom_accent_enabled', __('Custom Accent Color')],
                        ['channel_customization_surface_enabled', __('Surface Choices')],
                        ['channel_customization_banner_enabled', __('Banner Treatments')],
                        ['channel_customization_avatar_enabled', __('Avatar Frames')],
                        ['channel_customization_motion_enabled', __('Motion Choices')],
                        ['channel_customization_effects_enabled', __('Visual Effects')],
                        ['channel_customization_pattern_enabled', __('Background Patterns')],
                        ['channel_customization_shape_enabled', __('Header Shapes')],
                        ['channel_customization_video_layout_enabled', __('Video Layout Choices')],
                        ['channel_customization_featured_video_enabled', __('Featured Video')],
                        ['channel_customization_featured_layout_enabled', __('Featured Layout Choices')],
                        ['channel_customization_featured_sizing_enabled', __('Featured Rows & Columns')],
                        ['channel_customization_latest_uploads_enabled', __('Latest Uploads Section')],
                        ['channel_customization_latest_layout_enabled', __('Latest Uploads Layout')],
                        ['channel_customization_latest_sizing_enabled', __('Latest Uploads Rows & Columns')],
                        ['channel_customization_playlists_enabled', __('Playlists Section')],
                        ['channel_customization_playlists_layout_enabled', __('Playlists Layout')],
                        ['channel_customization_playlists_sizing_enabled', __('Playlists Rows & Columns')],
                        ['channel_customization_sections_enabled', __('About Sections')],
                        ['channel_customization_cta_enabled', __('Custom CTA')],
                        ['channel_customization_stats_enabled', __('Stats Modules')],
                        ['channel_customization_socials_enabled', __('Website Links')],
                        ['channel_customization_upload_rhythm_enabled', __('Upload Rhythm')],
                    ] as $toggle)
                        <label class="avd-switch-card">
                            <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('users', $toggle[0], $channelCustomizationDefaults[$toggle[0]] ?? '1'))>
                            <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="avd-field-grid">
                    @foreach([
                        'layout' => 'channel_default_layout',
                        'theme' => 'channel_default_theme',
                        'accent' => 'channel_default_accent',
                        'surface' => 'channel_default_surface',
                        'banner_treatment' => 'channel_default_banner_treatment',
                        'avatar_frame' => 'channel_default_avatar_frame',
                        'motion' => 'channel_default_motion',
                        'pattern' => 'channel_default_pattern',
                        'header_shape' => 'channel_default_header_shape',
                        'stats_style' => 'channel_default_stats_style',
                        'video_layout' => 'channel_default_video_layout',
                        'featured_mode' => 'channel_default_featured_mode',
                        'featured_layout' => 'channel_default_featured_layout',
                        'latest_uploads_layout' => 'channel_default_latest_uploads_layout',
                        'playlists_layout' => 'channel_default_playlists_layout',
                        'density' => 'channel_default_density',
                    ] as $optionKey => $settingKey)
                        <div>
                            <label class="form-label fw-bold" for="{{ $settingKey }}">{{ __(str_replace('Default ', '', \Illuminate\Support\Str::headline($settingKey))) }}</label>
                            <select id="{{ $settingKey }}" name="{{ $settingKey }}" class="form-select">
                                @foreach($channelCustomizationOptions[$optionKey] as $key => $label)
                                    <option value="{{ $key }}" @selected((string)$value('users', $settingKey, $channelCustomizationDefaults[$settingKey]) === $key)>{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                    <div>
                        <label class="form-label fw-bold" for="channel_default_custom_accent">{{__('Default Custom Accent')}}</label>
                        <input id="channel_default_custom_accent" type="color" name="channel_default_custom_accent" class="form-control form-control-color w-100" value="{{ $value('users', 'channel_default_custom_accent', $channelCustomizationDefaults['channel_default_custom_accent']) }}">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="channel_default_featured_columns">{{__('Default Featured Items Per Row')}}</label>
                        <input id="channel_default_featured_columns" type="number" name="channel_default_featured_columns" class="form-control" value="{{ $value('users', 'channel_default_featured_columns', $channelCustomizationDefaults['channel_default_featured_columns']) }}" min="1" max="6">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="channel_default_featured_rows">{{__('Default Featured Rows')}}</label>
                        <input id="channel_default_featured_rows" type="number" name="channel_default_featured_rows" class="form-control" value="{{ $value('users', 'channel_default_featured_rows', $channelCustomizationDefaults['channel_default_featured_rows']) }}" min="1" max="4">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="channel_default_latest_uploads_columns">{{__('Default Latest Uploads Items Per Row')}}</label>
                        <input id="channel_default_latest_uploads_columns" type="number" name="channel_default_latest_uploads_columns" class="form-control" value="{{ $value('users', 'channel_default_latest_uploads_columns', $channelCustomizationDefaults['channel_default_latest_uploads_columns']) }}" min="1" max="6">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="channel_default_latest_uploads_rows">{{__('Default Latest Uploads Rows')}}</label>
                        <input id="channel_default_latest_uploads_rows" type="number" name="channel_default_latest_uploads_rows" class="form-control" value="{{ $value('users', 'channel_default_latest_uploads_rows', $channelCustomizationDefaults['channel_default_latest_uploads_rows']) }}" min="1" max="6">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="channel_default_playlists_columns">{{__('Default Playlists Per Row')}}</label>
                        <input id="channel_default_playlists_columns" type="number" name="channel_default_playlists_columns" class="form-control" value="{{ $value('users', 'channel_default_playlists_columns', $channelCustomizationDefaults['channel_default_playlists_columns']) }}" min="1" max="4">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="channel_default_playlists_rows">{{__('Default Playlist Rows')}}</label>
                        <input id="channel_default_playlists_rows" type="number" name="channel_default_playlists_rows" class="form-control" value="{{ $value('users', 'channel_default_playlists_rows', $channelCustomizationDefaults['channel_default_playlists_rows']) }}" min="1" max="6">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="channel_default_playlist_video_columns">{{__('Default Playlist Videos Per Row')}}</label>
                        <input id="channel_default_playlist_video_columns" type="number" name="channel_default_playlist_video_columns" class="form-control" value="{{ $value('users', 'channel_default_playlist_video_columns', $channelCustomizationDefaults['channel_default_playlist_video_columns']) }}" min="1" max="6">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="channel_default_playlist_video_rows">{{__('Default Playlist Video Rows')}}</label>
                        <input id="channel_default_playlist_video_rows" type="number" name="channel_default_playlist_video_rows" class="form-control" value="{{ $value('users', 'channel_default_playlist_video_rows', $channelCustomizationDefaults['channel_default_playlist_video_rows']) }}" min="1" max="4">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="channel_tagline_max_length">{{__('Tagline Max Length')}}</label>
                        <input id="channel_tagline_max_length" type="number" name="channel_tagline_max_length" class="form-control" value="{{ $value('users', 'channel_tagline_max_length', $channelCustomizationDefaults['channel_tagline_max_length']) }}" min="20" max="240">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="channel_cta_label_max_length">{{__('CTA Label Max Length')}}</label>
                        <input id="channel_cta_label_max_length" type="number" name="channel_cta_label_max_length" class="form-control" value="{{ $value('users', 'channel_cta_label_max_length', $channelCustomizationDefaults['channel_cta_label_max_length']) }}" min="8" max="80">
                    </div>
                </div>
                <hr class="my-4">
                <div class="avd-section-title"><i class="fa-solid fa-gauge-high text-primary"></i> {{__('Creator Quality Pulse')}}</div>
                <div class="avd-switch-grid mb-4">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="creator_quality_pulse_enabled" value="1" @checked($isChecked('users', 'creator_quality_pulse_enabled', $creatorQualityPulseDefaults['enabled']))>
                        <span class="fw-bold">{{__('Pulse Enabled')}}</span>
                    </label>
                </div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="creator_quality_pulse_limit">{{__('Pulse Item Limit')}}</label>
                        <input id="creator_quality_pulse_limit" type="number" name="creator_quality_pulse_limit" class="form-control" value="{{ $value('users', 'creator_quality_pulse_limit', $creatorQualityPulseDefaults['limit']) }}" min="{{ $creatorQualityPulseDefaults['limit_min'] }}" max="{{ $creatorQualityPulseDefaults['limit_max'] }}" required>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="creator_quality_pulse_lookback_days">{{__('Pulse Lookback Days')}}</label>
                        <input id="creator_quality_pulse_lookback_days" type="number" name="creator_quality_pulse_lookback_days" class="form-control" value="{{ $value('users', 'creator_quality_pulse_lookback_days', $creatorQualityPulseDefaults['lookback_days']) }}" min="{{ $creatorQualityPulseDefaults['lookback_days_min'] }}" max="{{ $creatorQualityPulseDefaults['lookback_days_max'] }}" required>
                    </div>
                </div>
                <hr class="my-4">
                <div class="avd-section-title"><i class="fa-solid fa-shield-heart text-primary"></i> {{__('Studio Health Alerts')}}</div>
                <div class="avd-switch-grid mb-4">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="studio_health_alerts_enabled" value="1" @checked($isChecked('users', 'studio_health_alerts_enabled', $studioHealthAlertsDefaults['enabled']))>
                        <span class="fw-bold">{{__('Alerts Enabled')}}</span>
                    </label>
                </div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="studio_health_alerts_limit">{{__('Alert Limit')}}</label>
                        <input id="studio_health_alerts_limit" type="number" name="studio_health_alerts_limit" class="form-control" value="{{ $value('users', 'studio_health_alerts_limit', $studioHealthAlertsDefaults['limit']) }}" min="{{ $studioHealthAlertsDefaults['limit_min'] }}" max="{{ $studioHealthAlertsDefaults['limit_max'] }}" required>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="studio_health_alerts_lookback_days">{{__('Alert Lookback Days')}}</label>
                        <input id="studio_health_alerts_lookback_days" type="number" name="studio_health_alerts_lookback_days" class="form-control" value="{{ $value('users', 'studio_health_alerts_lookback_days', $studioHealthAlertsDefaults['lookback_days']) }}" min="{{ $studioHealthAlertsDefaults['lookback_days_min'] }}" max="{{ $studioHealthAlertsDefaults['lookback_days_max'] }}" required>
                    </div>
                </div>
                <hr class="my-4">
                <div class="avd-section-title"><i class="fa-solid fa-list-check text-primary"></i> {{__('Creator Recovery Checklist')}}</div>
                <div class="avd-switch-grid mb-4">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="creator_recovery_checklist_enabled" value="1" @checked($isChecked('users', 'creator_recovery_checklist_enabled', $creatorRecoveryChecklistDefaults['enabled']))>
                        <span class="fw-bold">{{__('Checklist Enabled')}}</span>
                    </label>
                </div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="creator_recovery_checklist_limit">{{__('Checklist Limit')}}</label>
                        <input id="creator_recovery_checklist_limit" type="number" name="creator_recovery_checklist_limit" class="form-control" value="{{ $value('users', 'creator_recovery_checklist_limit', $creatorRecoveryChecklistDefaults['limit']) }}" min="{{ $creatorRecoveryChecklistDefaults['limit_min'] }}" max="{{ $creatorRecoveryChecklistDefaults['limit_max'] }}" required>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="creator_recovery_checklist_inactivity_days">{{__('Inactivity Days')}}</label>
                        <input id="creator_recovery_checklist_inactivity_days" type="number" name="creator_recovery_checklist_inactivity_days" class="form-control" value="{{ $value('users', 'creator_recovery_checklist_inactivity_days', $creatorRecoveryChecklistDefaults['inactivity_days']) }}" min="{{ $creatorRecoveryChecklistDefaults['inactivity_days_min'] }}" max="{{ $creatorRecoveryChecklistDefaults['inactivity_days_max'] }}" required>
                    </div>
                </div>
                <button class="btn btn-primary mt-4" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save Users')}}</button>
            </form>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'monetization' ? 'show active' : '' }}" id="tab-monetization" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'monetization') }}" method="POST">
                @csrf @method('PUT')
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
                    <div>
                        <div class="avd-section-title mb-1"><i class="fa-solid fa-crown text-warning"></i> {{__('Premium billing')}}</div>
                        <p class="text-muted mb-0">{{__('Premium checkout works when at least one gateway has credentials, a webhook, and plan IDs configured.')}}</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-start">
                        <span class="badge bg-{{ $billingConfigured ? 'success' : 'warning' }} fs-6">
                            {{ $billingConfigured ? __('Billing ready') : __('Billing incomplete') }}
                        </span>
                        <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.env-editor.index') }}"><i class="fa-solid fa-file-shield me-1"></i> {{__('Env Editor')}}</a>
                        <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.plans.index') }}"><i class="fa-solid fa-layer-group me-1"></i> {{__('Plans')}}</a>
                    </div>
                </div>
                <div class="avd-switch-grid mb-4">
                    @foreach([
                        ['premium_enabled', __('Premium Enabled'), '1'],
                        ['stripe_enabled', __('Stripe Enabled'), '1'],
                        ['paypal_enabled', __('PayPal Enabled'), '1'],
                        ['show_premium_badge', __('Show Premium Badge'), '1'],
                    ] as $toggle)
                        <label class="avd-switch-card">
                            <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('monetization', $toggle[0], $toggle[2]))>
                            <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="avd-section-title"><i class="fa-brands fa-stripe-s text-primary"></i> {{__('Stripe status')}}</div>
                <div class="avd-env-grid mb-4">
                    @foreach($stripeChecks as $item)
                        <div class="avd-env-item">
                            <div class="small text-muted">{{ $display($item['label']) }}</div>
                            <div class="fw-black text-{{ $item['ready'] ? 'success' : 'warning' }}">{{ $item['ready'] ? __('Ready') : __('Missing') }}</div>
                        </div>
                    @endforeach
                    <div class="avd-env-item">
                        <div class="small text-muted">{{__('Currency')}}</div>
                        <div class="fw-black text-uppercase">{{ $env['cashier_currency'] ?: __('Not set') }}</div>
                    </div>
                    <div class="avd-env-item">
                        <div class="small text-muted">{{__('Plans')}}</div>
                        <div class="fw-black">{{ $systemInfo['total_plans'] ?? 0 }} {{__('total')}} / {{ $systemInfo['plans_missing_stripe'] ?? 0 }} {{__('missing Stripe ID')}}</div>
                    </div>
                </div>

                <div class="avd-section-title"><i class="fa-brands fa-paypal text-primary"></i> {{__('PayPal status')}}</div>
                <div class="avd-env-grid mb-4">
                    @foreach($paypalChecks as $item)
                        <div class="avd-env-item">
                            <div class="small text-muted">{{ $display($item['label']) }}</div>
                            <div class="fw-black text-{{ $item['ready'] ? 'success' : 'warning' }}">{{ $item['ready'] ? __('Ready') : __('Missing') }}</div>
                        </div>
                    @endforeach
                    <div class="avd-env-item">
                        <div class="small text-muted">{{__('Mode')}}</div>
                        <div class="fw-black text-uppercase">{{ $env['paypal_mode'] ?: __('Not set') }}</div>
                    </div>
                    <div class="avd-env-item">
                        <div class="small text-muted">{{__('Plans')}}</div>
                        <div class="fw-black">{{ $systemInfo['total_plans'] ?? 0 }} {{__('total')}} / {{ $systemInfo['plans_missing_paypal'] ?? 0 }} {{__('missing PayPal ID')}}</div>
                    </div>
                </div>

                @php
                    $globalGridLayoutOptions = [
                        'grid_card' => __('Grid card replaces one video slot'),
                        'wide_row' => __('Wide responsive row between video rows'),
                    ];
                    $feedGridLayoutOptions = [
                        'inherit' => __('Use global layout'),
                        'grid_card' => __('Grid card'),
                        'wide_row' => __('List / wide row'),
                    ];
                    $feedAdSlots = [
                        [
                            'icon' => 'compass',
                            'label' => __('Smart Discovery'),
                            'description' => __('Home Smart Discovery widget video feed.'),
                            'layout' => 'ad_grid_layout_smart_discovery',
                            'code' => 'ad_code_grid_smart_discovery',
                        ],
                        [
                            'icon' => 'users-viewfinder',
                            'label' => __('Creator Spotlight'),
                            'description' => __('Home Creator Spotlight widget video feed.'),
                            'layout' => 'ad_grid_layout_creator_spotlight',
                            'code' => 'ad_code_grid_creator_spotlight',
                        ],
                        [
                            'icon' => 'user-group',
                            'label' => __('Trending Actors'),
                            'description' => __('Home Trending Actors widget video feed.'),
                            'layout' => 'ad_grid_layout_trending_actors',
                            'code' => 'ad_code_grid_trending_actors',
                        ],
                        [
                            'icon' => 'clapperboard',
                            'label' => __('New Videos'),
                            'description' => __('Main homepage video feed.'),
                            'layout' => 'ad_grid_layout_new_videos',
                            'code' => 'ad_code_grid_new_videos',
                        ],
                        [
                            'icon' => 'arrow-trend-up',
                            'label' => __('Trending Page'),
                            'description' => __('Dedicated /trend video feed.'),
                            'layout' => 'ad_grid_layout_trend_page',
                            'code' => 'ad_code_grid_trend_page',
                        ],
                        [
                            'icon' => 'folder-open',
                            'label' => __('Category Pages'),
                            'description' => __('Video feed on each category page.'),
                            'layout' => 'ad_grid_layout_category_page',
                            'code' => 'ad_code_grid_category_page',
                        ],
                        [
                            'icon' => 'bell',
                            'label' => __('Subscriptions'),
                            'description' => __('Signed-in subscriptions latest video feed.'),
                            'layout' => 'ad_grid_layout_subscriptions',
                            'code' => 'ad_code_grid_subscriptions',
                        ],
                        [
                            'icon' => 'address-card',
                            'label' => __('Channel Videos'),
                            'description' => __('Public user/channel video tabs.'),
                            'layout' => 'ad_grid_layout_channel_videos',
                            'code' => 'ad_code_grid_channel_videos',
                        ],
                        [
                            'icon' => 'magnifying-glass',
                            'label' => __('Search Results'),
                            'description' => __('Mixed search result list after filters.'),
                            'layout' => 'ad_grid_layout_search_results',
                            'code' => 'ad_code_grid_search_results',
                        ],
                        [
                            'icon' => 'list-ol',
                            'label' => __('Playlist Videos'),
                            'description' => __('Public playlist and saved playlist video lists.'),
                            'layout' => 'ad_grid_layout_playlist_videos',
                            'code' => 'ad_code_grid_playlist_videos',
                        ],
                        [
                            'icon' => 'shuffle',
                            'label' => __('Watch Related'),
                            'description' => __('Related video list in the watch page sidebar.'),
                            'layout' => 'ad_grid_layout_watch_related',
                            'code' => 'ad_code_grid_watch_related',
                        ],
                        [
                            'icon' => 'star',
                            'label' => __('Channel Featured'),
                            'description' => __('Featured video section on public channel home.'),
                            'layout' => 'ad_grid_layout_channel_home_featured',
                            'code' => 'ad_code_grid_channel_home_featured',
                        ],
                        [
                            'icon' => 'clock',
                            'label' => __('Channel Latest'),
                            'description' => __('Latest uploads section on public channel home.'),
                            'layout' => 'ad_grid_layout_channel_home_latest',
                            'code' => 'ad_code_grid_channel_home_latest',
                        ],
                    ];
                    $fixedAdSlots = [
                        ['ad_code_header', __('Header Responsive Banner'), __('Top page banner. Best for 728x90 or fluid responsive tags.')],
                        ['ad_code_sidebar', __('Watch Sidebar Card'), __('Right column slot on the watch page. Keep it calm and contained.')],
                        ['ad_code_video', __('Video Pre-roll HTML Fallback'), __('Used when no video ad URL is set or the media ad fails to load.')],
                        ['ad_code_footer', __('Footer Slim Banner'), __('Bottom page banner for broad but low-pressure inventory.')],
                        ['ad_code_mobile', __('Mobile Sticky Mini Banner'), __('Small dismissible mobile slot. Use compact responsive tags only.')],
                    ];
                    $highCpmAdSlots = [
                        ['ad_code_onclick', __('OnClick / Popunder Script'), __('Paste the network popunder script. It loads as a script-only slot and should trigger only from user interaction.')],
                    ];
                @endphp

                <div class="avd-section-title"><i class="fa-solid fa-rectangle-ad text-primary"></i> {{__('Advertising')}}</div>
                <div class="avd-switch-grid mb-4">
                    @foreach([
                        ['ads_enabled', __('Ads Enabled'), '0'],
                        ['ads_hide_for_premium', __('Hide Ads for Premium'), '1'],
                        ['ad_preroll_enabled', __('Video Pre-roll'), '0'],
                    ] as $toggle)
                        <label class="avd-switch-card">
                            <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('monetization', $toggle[0], $toggle[2]))>
                            <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="avd-section-title mt-4"><i class="fa-solid fa-bolt text-primary"></i> {{__('High CPM formats')}}</div>
                <div class="avd-switch-grid mb-3">
                    @foreach([
                        ['ad_onclick_enabled', __('OnClick Popunder'), '1'],
                    ] as $toggle)
                        <label class="avd-switch-card">
                            <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('monetization', $toggle[0], $toggle[2]))>
                            <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="avd-field-grid mb-4">
                    @foreach($highCpmAdSlots as $field)
                        <div class="avd-field-span">
                            <label class="form-label fw-bold" for="{{ $field[0] }}">{{ $display($field[1], \Illuminate\Support\Str::headline($field[0])) }}</label>
                            <textarea id="{{ $field[0] }}" name="{{ $field[0] }}" class="form-control font-monospace" rows="4">{{ $value('monetization', $field[0]) }}</textarea>
                            <div class="form-text">{{ $display($field[2]) }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="avd-section-title mt-4"><i class="fa-solid fa-film text-primary"></i> {{__('Player video ads')}}</div>
                <div class="avd-field-grid mb-4">
                    <div class="avd-field-span">
                        <label class="form-label fw-bold" for="ad_preroll_media_url">{{__('Pre-roll Video URLs')}}</label>
                        <textarea id="ad_preroll_media_url" name="ad_preroll_media_url" class="form-control font-monospace" rows="5" placeholder="https://test-videos.co.uk/vids/bigbuckbunny/mp4/h264/1080/Big_Buck_Bunny_1080_10s_30MB.mp4 | https://example.com/your-offer&#10;https://content.jwplatform.com/manifests/yp34SRmf.m3u8 | https://example.com/another-offer">{{ $value('monetization', 'ad_preroll_media_url') }}</textarea>
                        <div class="form-text">{{__('One ad per line: VIDEO_URL | CLICK_URL. The video can be MP4 or HLS (.m3u8); the click URL is optional and opens in a new tab when the viewer clicks the ad or its "Visit advertiser" button. The player randomly chooses one line before each video. If empty, the HTML fallback below is used.')}}</div>
                        <div class="form-text font-monospace small">{{__('Example:')}}<br>https://test-videos.co.uk/vids/bigbuckbunny/mp4/h264/1080/Big_Buck_Bunny_1080_10s_30MB.mp4 | https://example.com/your-offer<br>https://content.jwplatform.com/manifests/yp34SRmf.m3u8</div>
                    </div>
                    <div class="avd-field-span">
                        <label class="form-label fw-bold" for="ad_preroll_click_url">{{__('Default Click URL')}}</label>
                        <input id="ad_preroll_click_url" type="url" name="ad_preroll_click_url" class="form-control font-monospace" value="{{ $value('monetization', 'ad_preroll_click_url') }}" placeholder="https://example.com/advertise">
                        <div class="form-text">{{__('Used for every video ad line that has no click URL of its own. Leave empty to keep those ads non-clickable.')}}</div>
                    </div>
                </div>

                <div class="avd-section-title mt-4"><i class="fa-solid fa-sliders text-primary"></i> {{__('Feed ad rules')}}</div>
                <div class="avd-field-grid mb-4">
                    <div>
                        <label class="form-label fw-bold" for="ad_preroll_duration">{{__('Pre-roll Duration')}}</label>
                        <input id="ad_preroll_duration" type="number" name="ad_preroll_duration" class="form-control" value="{{ $value('monetization', 'ad_preroll_duration', 5) }}" min="1" max="60">
                        <div class="form-text">{{__('Controls the maximum ad time for video and HTML player ads. Video ads also close when the media ends.')}}</div>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="ad_grid_position">{{__('Grid Ad Position')}}</label>
                        <input id="ad_grid_position" type="number" name="ad_grid_position" class="form-control" value="{{ $value('monetization', 'ad_grid_position', 6) }}" min="1">
                        <div class="form-text">{{__('Shows each feed ad after this many videos.')}}</div>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="ad_grid_max_per_feed">{{__('Max Grid Ads per Feed')}}</label>
                        <input id="ad_grid_max_per_feed" type="number" name="ad_grid_max_per_feed" class="form-control" value="{{ $value('monetization', 'ad_grid_max_per_feed', 1) }}" min="0" max="20">
                        <div class="form-text">{{__('Limits repeated ads inside each feed. Use 1 for one ad only, or 0 for unlimited.')}}</div>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="ad_grid_layout">{{__('Default Feed Layout')}}</label>
                        <select id="ad_grid_layout" name="ad_grid_layout" class="form-select">
                            @foreach($globalGridLayoutOptions as $layoutKey => $layoutLabel)
                                <option value="{{ $layoutKey }}" @selected((string)$value('monetization', 'ad_grid_layout', 'grid_card') === $layoutKey)>{{ $display($layoutLabel, $layoutKey) }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">{{__('Grid card replaces one video slot; wide row places the ad between completed rows.')}}</div>
                    </div>
                </div>

                <div class="avd-section-title mt-4 mb-2"><i class="fa-solid fa-table-cells text-primary"></i> {{__('Feed ad slots')}}</div>
                <p class="form-text mt-0 mb-3">{{__('Each feed has its own ad code. Empty feed slots do not fall back to a generic grid ad.')}}</p>
                <div class="avd-ad-slot-grid mb-4">
                    @foreach($feedAdSlots as $slot)
                        <div class="avd-ad-slot-editor">
                            <div class="avd-ad-slot-heading">
                                <div>
                                    <label class="form-label fw-bold mb-1" for="{{ $slot['code'] }}">
                                        <i class="fa-solid fa-{{ $slot['icon'] }} text-primary me-1"></i>
                                        {{ $display($slot['label'], \Illuminate\Support\Str::headline($slot['code'])) }}
                                    </label>
                                    <div class="form-text mt-0">{{ $display($slot['description']) }}</div>
                                </div>
                                <select name="{{ $slot['layout'] }}" class="form-select" aria-label="{{ $display($slot['label']) }} {{ __('layout') }}">
                                    @foreach($feedGridLayoutOptions as $layoutKey => $layoutLabel)
                                        <option value="{{ $layoutKey }}" @selected((string)$value('monetization', $slot['layout'], 'inherit') === $layoutKey)>{{ $display($layoutLabel, $layoutKey) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <textarea id="{{ $slot['code'] }}" name="{{ $slot['code'] }}" class="form-control font-monospace" rows="4">{{ $value('monetization', $slot['code']) }}</textarea>
                        </div>
                    @endforeach
                </div>

                <div class="avd-section-title mt-4"><i class="fa-solid fa-window-maximize text-primary"></i> {{__('Page and player ad slots')}}</div>
                <div class="avd-field-grid">
                    @foreach($fixedAdSlots as $field)
                        <div class="avd-field-span">
                            <label class="form-label fw-bold" for="{{ $field[0] }}">{{ $display($field[1], \Illuminate\Support\Str::headline($field[0])) }}</label>
                            <textarea id="{{ $field[0] }}" name="{{ $field[0] }}" class="form-control font-monospace" rows="3">{{ $value('monetization', $field[0]) }}</textarea>
                            <div class="form-text">{{ $display($field[2]) }}</div>
                        </div>
                    @endforeach
                </div>
                <button class="btn btn-primary mt-4" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save Monetization')}}</button>
            </form>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'seo' ? 'show active' : '' }}" id="tab-seo" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'seo') }}" method="POST">
                @csrf @method('PUT')
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="avd-section-title mb-0"><i class="fa-solid fa-magnifying-glass-chart text-primary"></i> {{__('Search Engine Optimization (SEO)')}}</div>
                    <div class="d-flex gap-2">
                        <a href="{{ url('/sitemap.xml') }}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-sitemap me-1"></i> {{ __('XML Sitemap') }}</a>
                        <a href="{{ url('/video-sitemap.xml') }}" target="_blank" class="btn btn-sm btn-outline-info"><i class="fa-solid fa-video me-1"></i> {{ __('Video Sitemap') }}</a>
                    </div>
                </div>

                <!-- Live Google SERP Snippet Preview Card -->
                <div class="card border mb-4 p-3 bg-body-tertiary" style="border-radius: 12px;">
                    <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom">
                        <span class="fw-bold small text-uppercase text-muted"><i class="fa-brands fa-google text-primary me-1"></i> {{ __('Google SERP Snippet Preview (Desktop)') }}</span>
                        <div class="d-flex gap-3 small">
                            <span>{{ __('Title') }}: <strong id="serpTitleLength" class="text-success">0</strong>/60</span>
                            <span>{{ __('Description') }}: <strong id="serpDescLength" class="text-success">0</strong>/160</span>
                        </div>
                    </div>
                    <div class="p-3 bg-body border rounded-3" style="max-width: 650px;">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center" style="width: 22px; height: 22px; font-size: 11px;">
                                <i class="fa-solid fa-globe"></i>
                            </span>
                            <div class="small text-truncate" style="color: #202124; font-size: 0.82rem;">
                                <strong>{{ config('app.name', 'AVDBCMS') }}</strong>
                                <span class="text-muted ms-1">{{ config('app.url') }} › video › watch</span>
                            </div>
                        </div>
                        <h5 class="mb-1" style="font-size: 1.15rem; line-height: 1.3;">
                            <a href="#" class="text-decoration-none text-primary fw-medium" id="serpPreviewTitle" style="color: #1a0dab !important;">
                                {{ config('app.name') }} | Premium Video Community
                            </a>
                        </h5>
                        <p class="mb-0 small text-body-secondary" id="serpPreviewDesc" style="color: #4d5156; font-size: 0.85rem; line-height: 1.4;">
                            Watch and discover trending videos, creator channels, playlists, and HD streaming on {{ config('app.name') }}.
                        </p>
                    </div>
                </div>

                <div class="avd-section-title"><i class="fa-solid fa-heading text-primary"></i> {{__('Search appearance')}}</div>
                
                <!-- Quick Token Inserter -->
                <div class="d-flex align-items-center gap-1.5 flex-wrap mb-2">
                    <span class="small text-muted me-1">{{ __('Insert Token:') }}</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 seo-token-badge" data-token="{title}">{title}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 seo-token-badge" data-token="{site_name}">{site_name}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 seo-token-badge" data-token="{slug}">{slug}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 seo-token-badge" data-token="{uuid}">{uuid}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 seo-token-badge" data-token="{category}">{category}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 seo-token-badge" data-token="{user}">{user}</button>
                </div>

                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="seoTitleTemplateInput">{{__('Title Template')}}</label>
                        <input type="text" name="meta_title_template" id="seoTitleTemplateInput" class="form-control font-monospace" value="{{ $value('seo', 'meta_title_template', '{title} | {site_name}') }}">
                        <div class="form-text">{{__('Template applied to public video titles. Use {title} and {site_name}.')}}</div>
                    </div>
                    <div class="avd-field-span">
                        <label class="form-label fw-bold" for="seoMetaDescriptionInput">{{__('Default Meta Description')}}</label>
                        <textarea name="default_meta_description" id="seoMetaDescriptionInput" class="form-control" rows="3">{{ $value('seo', 'default_meta_description') }}</textarea>
                        <div class="form-text">{{__('Default snippet for home and generic pages without explicit descriptions.')}}</div>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="og_image">{{__('Default Open Graph Image')}}</label>
                        <input id="og_image" type="text" name="og_image" class="form-control" value="{{ $value('seo', 'og_image') }}" placeholder="https://example.com/banner.jpg">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="twitter_card_type">{{__('Twitter Card Type')}}</label>
                        <select id="twitter_card_type" name="twitter_card_type" class="form-select">
                            @foreach(['summary', 'summary_large_image', 'player'] as $type)
                                <option value="{{ $type }}" @selected((string)$value('seo', 'twitter_card_type', 'summary_large_image') === $type)>{{ $type }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="twitter_handle">{{__('Twitter Handle')}}</label>
                        <input id="twitter_handle" type="text" name="twitter_handle" class="form-control" value="{{ $value('seo', 'twitter_handle') }}" placeholder="@channel">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="facebook_app_id">{{__('Facebook App ID')}}</label>
                        <input id="facebook_app_id" type="text" name="facebook_app_id" class="form-control" value="{{ $value('seo', 'facebook_app_id') }}">
                    </div>
                    <div class="avd-field-span">
                        <label class="form-label fw-bold" for="google_verification">{{__('Google Site Verification')}}</label>
                        <input id="google_verification" type="text" name="google_verification" class="form-control" value="{{ $value('seo', 'google_verification') }}" placeholder="google-site-verification token">
                    </div>
                </div>

                <div class="avd-section-title mt-4"><i class="fa-solid fa-tags text-primary"></i> {{__('Page meta templates')}}</div>
                <p class="form-text mt-0 mb-3">{{__('Use these templates to make each domain publish unique SEO text. Unknown tokens are removed when rendered.')}}</p>
                @foreach($seoTemplateGroups as $templateGroup)
                    <div class="avd-secondary-panel p-3 mb-3">
                        <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
                            <div>
                                <h3 class="h6 fw-bold mb-1">{{ __($templateGroup['label']) }}</h3>
                                <div class="form-text mt-0">{{ __($templateGroup['description']) }}</div>
                            </div>
                        </div>
                        <div class="avd-field-grid">
                            @foreach($templateGroup['fields'] as $templateField)
                                @php
                                    $templateKey = $templateField['key'];
                                    $templateRows = $templateField['rows'] ?? 1;
                                    $templateDefault = $seoTemplateDefaults[$templateKey] ?? '';
                                @endphp
                                <div @class(['avd-field-span' => $templateRows > 1])>
                                    <label class="form-label fw-bold" for="{{ $templateKey }}">{{ __($templateField['label']) }}</label>
                                    @if($templateRows > 1)
                                        <textarea id="{{ $templateKey }}" name="{{ $templateKey }}" class="form-control font-monospace" rows="{{ $templateRows }}">{{ $value('seo', $templateKey, $templateDefault) }}</textarea>
                                    @else
                                        <input id="{{ $templateKey }}" type="text" name="{{ $templateKey }}" class="form-control font-monospace" value="{{ $value('seo', $templateKey, $templateDefault) }}">
                                    @endif
                                    <div class="form-text">{{__('Tokens:')}} <code>{{ $templateField['tokens'] }}</code></div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div class="avd-section-title mt-4"><i class="fa-solid fa-link text-primary"></i> {{__('Video URL structure')}}</div>
                <div class="avd-switch-grid mb-4">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="profile_uid_urls_enabled" value="1" @checked($isChecked('seo', 'profile_uid_urls_enabled', '1'))>
                        <span>
                            <span class="fw-bold d-block">{{__('Profile UID URLs')}}</span>
                            <span class="text-muted small">{{__('Adds the short profile UID to public channel URLs to avoid slug conflicts. Disable to use legacy @slug URLs.')}}</span>
                        </span>
                    </label>
                </div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="video_url_prefix">{{__('Video URL Prefix')}}</label>
                        <input id="video_url_prefix" type="text" name="video_url_prefix" class="form-control font-monospace" value="{{ $value('seo', 'video_url_prefix', 'video') }}">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="video_url_pattern">{{__('Video URL Pattern')}}</label>
                        <input id="video_url_pattern" type="text" name="video_url_pattern" class="form-control font-monospace" value="{{ $value('seo', 'video_url_pattern', '{slug}-{uuid}') }}">
                        <div class="form-text">{{__('Tokens: {slug}, {uuid}, {id}, {year}, {month}, {day}, {user}, {category}. Include {uuid} or {id}.')}}</div>
                    </div>
                </div>
                <div class="avd-env-grid mt-3">
                    @foreach([
                        '{slug}-{uuid}',
                        '{slug}-{id}-{uuid}',
                        '{category}/{slug}-{uuid}',
                        '{year}/{month}/{slug}-{id}',
                        '{user}/{slug}-{uuid}',
                    ] as $example)
                        <div class="avd-env-item">
                            <div class="small text-muted">{{__('Pattern example')}}</div>
                            <code>{{ $example }}</code>
                        </div>
                    @endforeach
                </div>

                <div class="avd-section-title mt-4"><i class="fa-solid fa-robot text-primary"></i> {{__('Robots and previews')}}</div>
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="robots_max_snippet">{{__('Max Snippet Characters')}}</label>
                        <input id="robots_max_snippet" type="number" name="robots_max_snippet" class="form-control" value="{{ $value('seo', 'robots_max_snippet', -1) }}" min="-1" max="1000">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="robots_max_video_preview">{{__('Max Video Preview Seconds')}}</label>
                        <input id="robots_max_video_preview" type="number" name="robots_max_video_preview" class="form-control" value="{{ $value('seo', 'robots_max_video_preview', -1) }}" min="-1" max="600">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="robots_max_image_preview">{{__('Max Image Preview')}}</label>
                        <select id="robots_max_image_preview" name="robots_max_image_preview" class="form-select">
                            @foreach(['large', 'standard', 'none'] as $preview)
                                <option value="{{ $preview }}" @selected((string)$value('seo', 'robots_max_image_preview', 'large') === $preview)>{{ $preview }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="schema_organization_type">{{__('Schema Organization Type')}}</label>
                        <select id="schema_organization_type" name="schema_organization_type" class="form-select">
                            @foreach(['Organization', 'WebSite'] as $type)
                                <option value="{{ $type }}" @selected((string)$value('seo', 'schema_organization_type', 'Organization') === $type)>{{ $type }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="avd-switch-grid mt-4">
                    @foreach([
                        ['robots_noindex', __('Noindex Site'), '0'],
                        ['robots_nofollow', __('Nofollow Links'), '0'],
                        ['robots_noarchive', __('No Archive'), '0'],
                        ['robots_notranslate', __('No Translate'), '0'],
                        ['robots_noimageindex', __('No Image Index'), '0'],
                        ['canonical_enabled', __('Canonical Links'), '1'],
                        ['hreflang_enabled', __('Hreflang Alternates'), '1'],
                        ['adult_rating_enabled', __('Adult Rating Meta'), '1'],
                        ['video_pages_indexable', __('Index Video Pages'), '1'],
                        ['category_pages_indexable', __('Index Category Pages'), '1'],
                        ['profile_pages_indexable', __('Index Profile Pages'), '1'],
                        ['playlist_pages_indexable', __('Index Playlist Pages'), '1'],
                        ['search_pages_indexable', __('Index Search/Tag Pages'), '0'],
                    ] as $toggle)
                        <label class="avd-switch-card">
                            <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('seo', $toggle[0], $toggle[2]))>
                            <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="avd-section-title mt-4"><i class="fa-solid fa-code-branch text-primary"></i> {{__('Structured data and discovery')}}</div>
                <div class="avd-switch-grid">
                    @foreach([
                        ['website_schema_enabled', __('Website Schema'), '1'],
                        ['search_action_schema_enabled', __('SearchAction Schema'), '1'],
                        ['video_schema_enabled', __('VideoObject Schema'), '1'],
                        ['video_seek_action_enabled', __('SeekToAction Schema'), '0'],
                        ['sitemap_enabled', __('XML Sitemaps'), '1'],
                        ['video_sitemap_enabled', __('Video Sitemap'), '1'],
                        ['indexnow_enabled', __('IndexNow'), '0'],
                    ] as $toggle)
                        <label class="avd-switch-card">
                            <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('seo', $toggle[0], $toggle[2]))>
                            <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="avd-field-grid mt-4">
                    <div>
                        <label class="form-label fw-bold" for="sitemap_video_limit">{{__('Video Sitemap URLs Per File')}}</label>
                        <input id="sitemap_video_limit" type="number" name="sitemap_video_limit" class="form-control" value="{{ $value('seo', 'sitemap_video_limit', 1000) }}" min="100" max="1000">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="indexnow_key">{{__('IndexNow Key')}}</label>
                        <input id="indexnow_key" type="text" name="indexnow_key" class="form-control font-monospace" value="{{ $value('seo', 'indexnow_key') }}">
                    </div>
                    <div class="avd-field-span">
                        <label class="form-label fw-bold" for="indexnow_endpoint">{{__('IndexNow Endpoint')}}</label>
                        <input id="indexnow_endpoint" type="url" name="indexnow_endpoint" class="form-control" value="{{ $value('seo', 'indexnow_endpoint', 'https://api.indexnow.org/indexnow') }}">
                    </div>
                    <div class="avd-field-span">
                        <label class="form-label fw-bold" for="robots_txt">{{__('Custom robots.txt')}}</label>
                        <textarea id="robots_txt" name="robots_txt" class="form-control font-monospace" rows="5" placeholder="{{__('Leave blank to generate automatically')}}">{{ $value('seo', 'robots_txt') }}</textarea>
                    </div>
                </div>
                <button class="btn btn-primary mt-4" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save SEO')}}</button>
            </form>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'pages' ? 'show active' : '' }}" id="tab-pages" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'pages') }}" method="POST">
                @csrf @method('PUT')
                <div class="avd-section-title"><i class="fa-solid fa-crown text-warning"></i> {{__('Premium page content')}}</div>
                <div class="avd-switch-grid mb-4">
                    @foreach([
                        ['premium_plan_switcher_enabled', __('Plan Switcher'), '1'],
                        ['premium_timeline_enabled', __('Checkout Timeline'), '1'],
                    ] as $toggle)
                        <label class="avd-switch-card">
                            <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('pages', $toggle[0], $premiumPageDefaults[$toggle[0]] ?? $toggle[2]))>
                            <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="avd-field-grid">
                    @foreach([
                        ['premium_page_kicker', __('Hero Kicker')],
                        ['premium_page_title', __('Hero Title')],
                        ['premium_page_subtitle', __('Hero Subtitle')],
                        ['premium_disabled_message', __('Disabled Message')],
                        ['premium_checkout_unavailable_message', __('Checkout Unavailable Message')],
                        ['premium_empty_plans_message', __('Empty Plans Message')],
                        ['premium_trial_badge_template', __('Trial Badge Template')],
                        ['premium_then_label', __('Then Label')],
                        ['premium_cancel_anytime_label', __('Cancel Label')],
                        ['premium_card_or_paypal_label', __('Card And PayPal Label')],
                        ['premium_paypal_label', __('PayPal Label')],
                    ] as $field)
                        <div @class(['avd-field-span' => str_contains($field[0], 'message') || str_contains($field[0], 'subtitle')])>
                            <label class="form-label fw-bold" for="{{ $field[0] }}">{{ $display($field[1], \Illuminate\Support\Str::headline($field[0])) }}</label>
                            <input id="{{ $field[0] }}" type="text" name="{{ $field[0] }}" class="form-control" value="{{ $value('pages', $field[0], $premiumPageDefaults[$field[0]]) }}">
                        </div>
                    @endforeach
                </div>

                <div class="avd-section-title mt-4"><i class="fa-solid fa-receipt text-primary"></i> {{__('Premium billing copy')}}</div>
                <div class="avd-field-grid">
                    @foreach([
                        ['premium_billing_heading', __('Billing Heading')],
                        ['premium_annual_savings_text', __('Annual Savings Text')],
                        ['premium_plan_unavailable_label', __('Plan Unavailable Label')],
                        ['premium_plan_unavailable_note', __('Plan Unavailable Note')],
                        ['premium_pay_card_label', __('Pay Card Label')],
                        ['premium_pay_paypal_label', __('Pay PayPal Label')],
                        ['premium_start_card_label', __('Start Card Label')],
                        ['premium_start_paypal_label', __('Start PayPal Label')],
                        ['premium_billing_trial_summary', __('Trial Billing Summary')],
                        ['premium_billing_without_trial_summary', __('Billing Summary Without Trial')],
                        ['premium_billing_charge_note', __('Billing Charge Note')],
                        ['premium_billing_refund_note', __('Billing Refund Note')],
                    ] as $field)
                        <div @class(['avd-field-span' => str_contains($field[0], 'summary') || str_contains($field[0], 'note')])>
                            <label class="form-label fw-bold" for="{{ $field[0] }}">{{ $display($field[1], \Illuminate\Support\Str::headline($field[0])) }}</label>
                            <input id="{{ $field[0] }}" type="text" name="{{ $field[0] }}" class="form-control" value="{{ $value('pages', $field[0], $premiumPageDefaults[$field[0]]) }}">
                        </div>
                    @endforeach
                </div>

                <div class="avd-section-title mt-4"><i class="fa-solid fa-list-check text-primary"></i> {{__('Premium feature cards')}}</div>
                @foreach([
                    'uploads' => __('Uploads Feature'),
                    'downloads' => __('Downloads Feature'),
                    'ad_free' => __('Ad-free Feature'),
                ] as $featureKey => $featureLabel)
                    <div class="avd-secondary-panel p-3 mb-3">
                        <div class="avd-switch-grid mb-3">
                            <label class="avd-switch-card">
                                <input class="form-check-input" type="checkbox" name="premium_feature_{{ $featureKey }}_enabled" value="1" @checked($isChecked('pages', 'premium_feature_' . $featureKey . '_enabled', $premiumPageDefaults['premium_feature_' . $featureKey . '_enabled']))>
                                <span class="fw-bold">{{ $display($featureLabel, \Illuminate\Support\Str::headline($featureKey)) }}</span>
                            </label>
                        </div>
                        <div class="avd-field-grid">
                            <div>
                                <label class="form-label fw-bold" for="premium_feature_{{ $featureKey }}_title">{{__('Title')}}</label>
                                <input id="premium_feature_{{ $featureKey }}_title" type="text" name="premium_feature_{{ $featureKey }}_title" class="form-control" value="{{ $value('pages', 'premium_feature_' . $featureKey . '_title', $premiumPageDefaults['premium_feature_' . $featureKey . '_title']) }}">
                            </div>
                            <div>
                                <label class="form-label fw-bold" for="premium_feature_{{ $featureKey }}_icon">{{__('Icon')}}</label>
                                <select id="premium_feature_{{ $featureKey }}_icon" name="premium_feature_{{ $featureKey }}_icon" class="form-select">
                                    @foreach($premiumPageIconOptions as $icon => $label)
                                        <option value="{{ $icon }}" @selected((string)$value('pages', 'premium_feature_' . $featureKey . '_icon', $premiumPageDefaults['premium_feature_' . $featureKey . '_icon']) === $icon)>{{ $display($label, $icon) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="avd-field-span">
                                <label class="form-label fw-bold" for="premium_feature_{{ $featureKey }}_description">{{__('Description')}}</label>
                                <input id="premium_feature_{{ $featureKey }}_description" type="text" name="premium_feature_{{ $featureKey }}_description" class="form-control" value="{{ $value('pages', 'premium_feature_' . $featureKey . '_description', $premiumPageDefaults['premium_feature_' . $featureKey . '_description']) }}">
                            </div>
                        </div>
                    </div>
                @endforeach

                <div class="avd-section-title mt-4"><i class="fa-solid fa-timeline text-primary"></i> {{__('Premium checkout timeline')}}</div>
                <div class="avd-field-grid">
                    @foreach([
                        ['premium_timeline_trial_title', __('Trial Step Title')],
                        ['premium_timeline_trial_description', __('Trial Step Description')],
                        ['premium_timeline_reminder_title', __('Reminder Step Title')],
                        ['premium_timeline_reminder_description', __('Reminder Step Description')],
                        ['premium_timeline_start_title', __('Start Step Title')],
                        ['premium_timeline_start_description', __('Start Step Description')],
                    ] as $field)
                        <div @class(['avd-field-span' => str_contains($field[0], 'description')])>
                            <label class="form-label fw-bold" for="{{ $field[0] }}">{{ $display($field[1], \Illuminate\Support\Str::headline($field[0])) }}</label>
                            <input id="{{ $field[0] }}" type="text" name="{{ $field[0] }}" class="form-control" value="{{ $value('pages', $field[0], $premiumPageDefaults[$field[0]]) }}">
                        </div>
                    @endforeach
                </div>

                <div class="avd-section-title mt-4"><i class="fa-solid fa-table-list text-primary"></i> {{__('Premium comparison table')}}</div>
                <div class="avd-switch-grid mb-4">
                    @foreach([
                        ['premium_comparison_uploads_row_enabled', __('Uploads Row'), '1'],
                        ['premium_comparison_file_size_row_enabled', __('File Size Row'), '1'],
                        ['premium_comparison_storage_row_enabled', __('Storage Row'), '1'],
                        ['premium_comparison_downloads_row_enabled', __('Downloads Row'), '1'],
                        ['premium_comparison_ads_row_enabled', __('Ads Row'), '1'],
                        ['premium_comparison_downloads_free_enabled', __('Free Downloads Check'), '0'],
                        ['premium_comparison_downloads_premium_enabled', __('Premium Downloads Check'), '1'],
                        ['premium_comparison_ads_free_enabled', __('Free Ads Check'), '1'],
                        ['premium_comparison_ads_premium_enabled', __('Premium Ads Check'), '0'],
                    ] as $toggle)
                        <label class="avd-switch-card">
                            <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('pages', $toggle[0], $premiumPageDefaults[$toggle[0]] ?? $toggle[2]))>
                            <span class="fw-bold">{{ $display($toggle[1], \Illuminate\Support\Str::headline($toggle[0])) }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="avd-field-grid mb-4">
                    @foreach([
                        ['premium_comparison_title', __('Comparison Title')],
                        ['premium_comparison_free_label', __('Free Column Label')],
                        ['premium_comparison_premium_label', __('Premium Column Label')],
                        ['premium_comparison_uploads_label', __('Uploads Row Label')],
                        ['premium_comparison_file_size_label', __('File Size Row Label')],
                        ['premium_comparison_storage_label', __('Storage Row Label')],
                        ['premium_comparison_downloads_label', __('Downloads Row Label')],
                        ['premium_comparison_ads_label', __('Ads Row Label')],
                        ['premium_unlimited_label', __('Unlimited Label')],
                    ] as $field)
                        <div>
                            <label class="form-label fw-bold" for="{{ $field[0] }}">{{ $display($field[1], \Illuminate\Support\Str::headline($field[0])) }}</label>
                            <input id="{{ $field[0] }}" type="text" name="{{ $field[0] }}" class="form-control" value="{{ $value('pages', $field[0], $premiumPageDefaults[$field[0]]) }}">
                        </div>
                    @endforeach
                </div>

                <div class="avd-section-title"><i class="fa-solid fa-file-contract text-primary"></i> {{__('Terms of Service')}}</div>
                <textarea name="terms_content" class="form-control" rows="14" placeholder="{{__('HTML is supported')}}">{{ $value('pages', 'terms_content') }}</textarea>
                <button class="btn btn-primary mt-4" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save Page Content')}}</button>
            </form>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'security' ? 'show active' : '' }}" id="tab-security" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'security') }}" method="POST">
                @csrf @method('PUT')
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <div class="avd-section-title mb-0"><i class="fa-solid fa-shield-halved text-primary"></i> {{__('Security, Headers & Firewall')}}</div>
                    <!-- 1-Click Security Profile Presets -->
                    <div class="d-flex gap-1.5 flex-wrap" id="securityProfilesToolbar">
                        <button type="button" class="btn btn-sm btn-outline-success rounded-pill sec-profile-btn" data-profile="strict">
                            <i class="fa-solid fa-lock me-1"></i> {{ __('Strict Production') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill sec-profile-btn" data-profile="balanced">
                            <i class="fa-solid fa-scale-balanced me-1"></i> {{ __('Balanced Standard') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill sec-profile-btn" data-profile="dev">
                            <i class="fa-solid fa-code me-1"></i> {{ __('Development') }}
                        </button>
                    </div>
                </div>

                <div class="card border p-3 mb-3 bg-body-tertiary">
                    <h6 class="fw-bold mb-2 text-primary small text-uppercase"><i class="fa-solid fa-robot me-1"></i> {{ __('Spam Challenge & Captcha Engine') }}</h6>
                    <div class="avd-field-grid mb-3">
                        <div>
                            <label class="form-label fw-bold" for="captcha_provider">{{__('Captcha Provider')}}</label>
                            <select id="captcha_provider" name="captcha_provider" class="form-select">
                                @foreach(\App\Services\CaptchaService::providers() as $providerKey => $providerLabel)
                                    <option value="{{ $providerKey }}" @selected((string)$value('security', 'captcha_provider', 'built_in') === $providerKey)>{{ $display($providerLabel, \Illuminate\Support\Str::headline($providerKey)) }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{__('Built-in math challenge works out of the box with zero external keys.')}}</div>
                        </div>
                        <div>
                            <label class="form-label fw-bold" for="captcha_site_key">{{__('Captcha Site Key')}}</label>
                            <input id="captcha_site_key" type="text" name="captcha_site_key" class="form-control font-monospace" value="{{ $value('security', 'captcha_site_key') }}" autocomplete="off">
                        </div>
                        <div class="avd-field-span">
                            <label class="form-label fw-bold" for="captcha_secret_key">{{__('Captcha Secret Key')}}</label>
                            <input id="captcha_secret_key" type="password" name="captcha_secret_key" class="form-control font-monospace" value="{{ $value('security', 'captcha_secret_key') }}" autocomplete="new-password">
                            <div class="form-text">{{__('External providers are safely ignored if secret keys are missing, preventing admin lockout.')}}</div>
                        </div>
                    </div>
                    <div class="avd-switch-grid">
                        @foreach([
                            ['honeypot_enabled', __('Hidden Honeypot Traps'), '1'],
                            ['captcha_on_login', __('Captcha on Login Form'), '1'],
                            ['captcha_on_register', __('Captcha on Registration Form'), '1'],
                            ['captcha_on_contact', __('Captcha on Contact Form'), '1'],
                            ['captcha_on_password_reset', __('Captcha on Password Reset'), '1'],
                        ] as $toggle)
                            <label class="avd-switch-card">
                                <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" value="1" @checked($isChecked('security', $toggle[0], $toggle[2]))>
                                <span class="fw-bold">{{ $display($toggle[1]) }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="card border p-3 mb-3 bg-body-tertiary">
                    <h6 class="fw-bold mb-2 text-info small text-uppercase"><i class="fa-solid fa-shield-virus me-1"></i> {{ __('Browser HTTP Security Headers') }}</h6>
                    <div class="avd-switch-grid mb-3">
                        @foreach([
                            ['force_https', __('Force HTTPS Redirect'), '0'],
                            ['hsts_enabled', __('HSTS Strict Transport'), '0'],
                            ['csp_enabled', __('Content Security Policy'), '0'],
                            ['xss_protection', __('X-XSS-Protection Header'), '1'],
                            ['nosniff_header', __('X-Content-Type-Options: nosniff'), '1'],
                            ['frame_options_header', __('X-Frame-Options: SAMEORIGIN'), '1'],
                        ] as $toggle)
                            <label class="avd-switch-card">
                                <input class="form-check-input" type="checkbox" name="{{ $toggle[0] }}" id="secToggle_{{ $toggle[0] }}" value="1" @checked($isChecked('security', $toggle[0], $toggle[2]))>
                                <span class="fw-bold">{{ $display($toggle[1]) }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="form-text small mb-3">
                        <i class="fa-solid fa-circle-info me-1"></i>
                        {{ __('Many hosting panels (aaPanel/Nginx, HestiaCP, CyberPanel/OpenLiteSpeed, 1Panel, Cloudflare) already send Strict-Transport-Security at the web-server or CDN layer. Enable HSTS here only if your server does not send it yet — otherwise browsers receive the header twice. Force HTTPS Redirect is safe behind reverse proxies.') }}
                    </div>
                    <div class="avd-field-grid">
                        <div>
                            <label class="form-label fw-bold" for="hsts_max_age">{{__('HSTS Max Age (Seconds)')}}</label>
                            <input id="hsts_max_age" type="number" name="hsts_max_age" class="form-control" value="{{ $value('security', 'hsts_max_age', 31536000) }}" min="0">
                            <div class="form-text small">{{ __('31536000 = 1 Year') }}</div>
                        </div>
                        <div class="avd-field-span">
                            <label class="form-label fw-bold" for="csp_directives">{{__('Custom CSP Directives')}}</label>
                            <textarea id="csp_directives" name="csp_directives" class="form-control font-monospace" rows="2">{{ $value('security', 'csp_directives') }}</textarea>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="card h-100 border p-3 bg-body-tertiary">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label class="form-label fw-bold mb-0 text-success" for="adminIpWhitelistArea"><i class="fa-solid fa-list-check me-1"></i> {{__('Admin IP Whitelist')}}</label>
                                <button type="button" class="btn btn-sm btn-outline-success py-0 px-2 add-my-ip-btn" data-target="adminIpWhitelistArea">
                                    <i class="fa-solid fa-plus me-1"></i> {{ __('Add My IP') }} ({{ request()->ip() }})
                                </button>
                            </div>
                            <textarea id="adminIpWhitelistArea" name="admin_ip_whitelist" class="form-control font-monospace" rows="4" placeholder="{{__('One IP per line (e.g. 192.168.1.1)')}}">{{ $value('security', 'admin_ip_whitelist') }}</textarea>
                            <div class="form-text small">{{ __('Only listed IPs can access the admin dashboard. Leave empty to allow all admin logins.') }}</div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card h-100 border p-3 bg-body-tertiary">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label class="form-label fw-bold mb-0 text-danger" for="global_ip_blacklist"><i class="fa-solid fa-ban me-1"></i> {{__('Global IP Blacklist')}}</label>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Blocked Access</span>
                            </div>
                            <textarea id="global_ip_blacklist" name="global_ip_blacklist" class="form-control font-monospace" rows="4" placeholder="{{__('One IP per line (e.g. 45.12.34.56)')}}">{{ $value('security', 'global_ip_blacklist') }}</textarea>
                            <div class="form-text small">{{ __('Completely forbids any HTTP requests from these IP addresses.') }}</div>
                        </div>
                    </div>
                </div>

                <button class="btn btn-primary mt-4 px-4 py-2" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save Security Settings')}}</button>
            </form>
        </div><div class="tab-pane fade {{ $activeTab === 'localization' ? 'show active' : '' }}" id="tab-localization" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'localization') }}" method="POST">
                @csrf @method('PUT')
                <div class="avd-field-grid">
                    <div>
                        <label class="form-label fw-bold" for="default_locale">{{__('Default Locale')}}</label>
                        <select id="default_locale" name="default_locale" class="form-select">
                            @foreach(config('languages', ['en' => ['label' => 'English']]) as $code => $language)
                                <option value="{{ $code }}" @selected((string)$value('localization', 'default_locale', config('site.default_locale', config('app.locale', 'en'))) === (string)$code)>{{ $language['label'] ?? strtoupper($code) }} ({{ $code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="timezone">{{__('Timezone')}}</label>
                        <select id="timezone" name="timezone" class="form-select">
                            @foreach(['UTC','America/New_York','America/Los_Angeles','Europe/London','Europe/Paris','Asia/Tokyo','Asia/Shanghai','Asia/Ho_Chi_Minh','Asia/Bangkok','Australia/Sydney'] as $tz)
                                <option value="{{ $tz }}" @selected((string)$value('localization', 'timezone', config('app.timezone')) === $tz)>{{ $tz }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="date_format">{{__('Date Format')}}</label>
                        <select id="date_format" name="date_format" class="form-select">
                            @foreach(['M d, Y','d/m/Y','Y-m-d','d.m.Y','m/d/Y'] as $format)
                                <option value="{{ $format }}" @selected((string)$value('localization', 'date_format', 'M d, Y') === $format)>{{ now()->format($format) }} ({{ $format }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="avd-switch-grid mt-4">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="allow_language_switch" value="1" @checked($isChecked('localization', 'allow_language_switch', '1'))>
                        <span class="fw-bold">{{__('Allow Language Switch')}}</span>
                    </label>
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="rtl_support" value="1" @checked($isChecked('localization', 'rtl_support', '0'))>
                        <span class="fw-bold">{{__('RTL Support')}}</span>
                    </label>
                </div>
                <button class="btn btn-primary mt-4" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save Localization')}}</button>
            </form>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'validation' ? 'show active' : '' }}" id="tab-validation" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'validation') }}" method="POST">
                @csrf @method('PUT')
                <div class="avd-field-grid">
                    @foreach([
                        ['user_username_min', __('Username Min'), config('validation.user.username.min', 3)],
                        ['user_username_max', __('Username Max'), config('validation.user.username.max', 40)],
                        ['user_slug_min', __('Slug Min'), config('validation.user.slug.min', 3)],
                        ['user_slug_max', __('Slug Max'), config('validation.user.slug.max', 30)],
                        ['user_password_min', __('Password Min'), config('validation.user.password.min', 6)],
                        ['user_description_max', __('User Description Max'), config('validation.user.description.max', 5000)],
                        ['user_website_max', __('Website Max'), config('validation.user.website.max', 255)],
                        ['video_title_max', __('Video Title Max'), config('validation.video.title.max', 100)],
                        ['video_description_max', __('Video Description Max'), config('validation.video.description.max', 5000)],
                        ['comment_content_max', __('Comment Max'), config('validation.comment.content.max', 5000)],
                        ['playlist_title_max', __('Playlist Title Max'), config('validation.playlist.title.max', 150)],
                        ['playlist_description_max', __('Playlist Description Max'), config('validation.playlist.description.max', 5000)],
                        ['report_comment_max', __('Report Comment Max'), config('validation.report.comment.max', 5000)],
                        ['contact_name_min', __('Contact Name Min'), config('validation.contact.name.min', 3)],
                        ['contact_name_max', __('Contact Name Max'), config('validation.contact.name.max', 40)],
                        ['contact_message_min', __('Contact Message Min'), config('validation.contact.message.min', 10)],
                        ['contact_message_max', __('Contact Message Max'), config('validation.contact.message.max', 5000)],
                    ] as $field)
                        <div>
                            <label class="form-label fw-bold" for="{{ $field[0] }}">{{ $display($field[1], \Illuminate\Support\Str::headline($field[0])) }}</label>
                            <input id="{{ $field[0] }}" type="number" name="{{ $field[0] }}" class="form-control" value="{{ $value('validation', $field[0], $field[2]) }}" min="1">
                        </div>
                    @endforeach
                </div>
                <button class="btn btn-primary mt-4" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save Validation')}}</button>
            </form>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'planlimits' ? 'show active' : '' }}" id="tab-planlimits" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'planlimits') }}" method="POST">
                @csrf @method('PUT')
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="avd-section-title mb-0"><i class="fa-solid fa-gauge-high text-primary"></i> {{__('Membership Plan & Storage Quotas')}}</div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1">Tier Storage Limits</span>
                </div>
                
                <div class="row g-3 mb-3">
                    <div class="col-lg-6">
                        <div class="card h-100 border p-3 bg-body-tertiary">
                            <h6 class="fw-bold mb-3 text-primary"><i class="fa-solid fa-user me-1"></i> {{ __('Free Membership Quotas') }}</h6>
                            <div class="mb-3">
                                <label class="form-label fw-bold" for="free_max_uploads">{{__('Free Max Uploads')}}</label>
                                <input id="free_max_uploads" type="number" name="free_max_uploads" class="form-control" value="{{ $value('planlimits', 'free_max_uploads', config('plans.free.max_uploads', 10)) }}" min="0">
                                <div class="form-text small">{{ __('Max videos a free member can upload (0 for unlimited).') }}</div>
                            </div>
                            <div class="mb-3">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <label class="form-label fw-bold mb-0" for="free_max_file_size">{{__('Free Max Video File Size')}}</label>
                                    <span class="badge bg-info-subtle text-info byte-converter-badge" data-for="free_max_file_size">500 MB</span>
                                </div>
                                <input type="number" name="free_max_file_size" id="free_max_file_size" class="form-control byte-input" value="{{ $value('planlimits', 'free_max_file_size', config('plans.free.max_file_size', 524288000)) }}" min="1048576">
                                <div class="d-flex gap-1 flex-wrap mt-1">
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 byte-preset-btn" data-target="free_max_file_size" data-bytes="104857600">100 MB</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 byte-preset-btn" data-target="free_max_file_size" data-bytes="262144000">250 MB</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 byte-preset-btn" data-target="free_max_file_size" data-bytes="524288000">500 MB</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 byte-preset-btn" data-target="free_max_file_size" data-bytes="1073741824">1 GB</button>
                                </div>
                            </div>
                            <div>
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <label class="form-label fw-bold mb-0" for="free_max_storage">{{__('Free Max Total Storage')}}</label>
                                    <span class="badge bg-info-subtle text-info byte-converter-badge" data-for="free_max_storage">5 GB</span>
                                </div>
                                <input type="number" name="free_max_storage" id="free_max_storage" class="form-control byte-input" value="{{ $value('planlimits', 'free_max_storage', config('plans.free.max_videos_storage', 5368709120)) }}" min="1048576">
                                <div class="d-flex gap-1 flex-wrap mt-1">
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 byte-preset-btn" data-target="free_max_storage" data-bytes="1073741824">1 GB</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 byte-preset-btn" data-target="free_max_storage" data-bytes="5368709120">5 GB</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 byte-preset-btn" data-target="free_max_storage" data-bytes="10737418240">10 GB</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card h-100 border p-3 bg-body-tertiary">
                            <h6 class="fw-bold mb-3 text-warning"><i class="fa-solid fa-crown me-1"></i> {{ __('Premium Membership Quotas') }}</h6>
                            <div class="mb-3">
                                <label class="form-label fw-bold" for="premium_max_uploads">{{__('Premium Max Uploads')}}</label>
                                <input id="premium_max_uploads" type="number" name="premium_max_uploads" class="form-control" value="{{ $value('planlimits', 'premium_max_uploads') }}" placeholder="{{ __('Empty for Unlimited') }}">
                                <div class="form-text small">{{ __('Leave blank for unlimited video uploads.') }}</div>
                            </div>
                            <div class="mb-3">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <label class="form-label fw-bold mb-0" for="premium_max_file_size">{{__('Premium Max Video File Size')}}</label>
                                    <span class="badge bg-warning-subtle text-warning byte-converter-badge" data-for="premium_max_file_size">2 GB</span>
                                </div>
                                <input type="number" name="premium_max_file_size" id="premium_max_file_size" class="form-control byte-input" value="{{ $value('planlimits', 'premium_max_file_size', config('plans.premium.max_file_size', 2147483648)) }}" min="1048576">
                                <div class="d-flex gap-1 flex-wrap mt-1">
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 byte-preset-btn" data-target="premium_max_file_size" data-bytes="1073741824">1 GB</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 byte-preset-btn" data-target="premium_max_file_size" data-bytes="2147483648">2 GB</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 byte-preset-btn" data-target="premium_max_file_size" data-bytes="5368709120">5 GB</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 byte-preset-btn" data-target="premium_max_file_size" data-bytes="10737418240">10 GB</button>
                                </div>
                            </div>
                            <div>
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <label class="form-label fw-bold mb-0" for="premium_max_storage">{{__('Premium Max Total Storage')}}</label>
                                    <span class="badge bg-warning-subtle text-warning byte-converter-badge" data-for="premium_max_storage">Unlimited</span>
                                </div>
                                <input type="number" name="premium_max_storage" id="premium_max_storage" class="form-control byte-input" value="{{ $value('planlimits', 'premium_max_storage') }}" placeholder="{{ __('Empty for Unlimited') }}">
                                <div class="d-flex gap-1 flex-wrap mt-1">
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 byte-preset-btn" data-target="premium_max_storage" data-bytes="10737418240">10 GB</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 byte-preset-btn" data-target="premium_max_storage" data-bytes="53687091200">50 GB</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 byte-preset-btn" data-target="premium_max_storage" data-bytes="107374182400">100 GB</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border p-3 bg-body-tertiary">
                    <h6 class="fw-bold mb-2 text-primary small text-uppercase"><i class="fa-solid fa-clock me-1"></i> {{ __('Trial Periods & Reminders') }}</h6>
                    <div class="avd-field-grid">
                        <div>
                            <label class="form-label fw-bold" for="trial_period_days">{{__('Trial Period (Days)')}}</label>
                            <input id="trial_period_days" type="number" name="trial_period_days" class="form-control" value="{{ $value('planlimits', 'trial_period_days', config('plans.trial_period.period', 30)) }}" min="0" max="365">
                            <div class="form-text small">{{ __('Default days granted when a plan includes a free trial.') }}</div>
                        </div>
                        <div>
                            <label class="form-label fw-bold" for="trial_email_reminder">{{__('Trial Expiration Reminder (Days)')}}</label>
                            <input id="trial_email_reminder" type="number" name="trial_email_reminder" class="form-control" value="{{ $value('planlimits', 'trial_email_reminder', config('plans.trial_period.email_reminder', 7)) }}" min="0" max="365">
                            <div class="form-text small">{{ __('Days before trial ends to send automated renewal notice.') }}</div>
                        </div>
                    </div>
                </div>
                <button class="btn btn-primary mt-4 px-4 py-2" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save Plan Quotas')}}</button>
            </form>
        </div><div class="tab-pane fade {{ $activeTab === 'api' ? 'show active' : '' }}" id="tab-api" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'api') }}" method="POST">
                @csrf @method('PUT')
                <div class="avd-field-grid">
                    @foreach([
                        ['api_rate_limit', __('General Rate Limit'), 60],
                        ['login_rate_limit', __('Login Rate Limit'), 5],
                        ['upload_rate_limit', __('Upload Rate Limit'), 10],
                        ['subscribe_rate_limit', __('Subscribe Rate Limit'), 5],
                        ['comment_rate_limit', __('Comment Rate Limit'), 15],
                        ['report_rate_limit', __('Report Rate Limit'), 5],
                        ['search_rate_limit', __('Search Rate Limit'), 30],
                        ['video_view_rate_limit', __('Video View Rate Limit'), 120],
                        ['cors_max_age', __('CORS Max Age'), 0],
                    ] as $field)
                        <div>
                            <label class="form-label fw-bold" for="{{ $field[0] }}">{{ $display($field[1], \Illuminate\Support\Str::headline($field[0])) }}</label>
                            <input id="{{ $field[0] }}" type="number" name="{{ $field[0] }}" class="form-control" value="{{ $value('api', $field[0], $field[2]) }}" min="0">
                        </div>
                    @endforeach
                    <div>
                        <label class="form-label fw-bold" for="cors_allowed_origins">{{__('CORS Allowed Origins')}}</label>
                        <input id="cors_allowed_origins" type="text" name="cors_allowed_origins" class="form-control" value="{{ $value('api', 'cors_allowed_origins', '*') }}">
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="cors_allowed_methods">{{__('CORS Allowed Methods')}}</label>
                        <input id="cors_allowed_methods" type="text" name="cors_allowed_methods" class="form-control" value="{{ $value('api', 'cors_allowed_methods', '*') }}">
                    </div>
                    <div class="avd-field-span">
                        <label class="form-label fw-bold" for="embed_allowed_domains">{{__('Embed Allowed Domains')}}</label>
                        <textarea id="embed_allowed_domains" name="embed_allowed_domains" class="form-control font-monospace" rows="3" placeholder="{{__('Comma or newline separated')}}">{{ $value('api', 'embed_allowed_domains') }}</textarea>
                    </div>
                </div>
                <div class="avd-switch-grid mt-4">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="cors_supports_credentials" value="1" @checked($isChecked('api', 'cors_supports_credentials', '1'))>
                        <span class="fw-bold">{{__('CORS Supports Credentials')}}</span>
                    </label>
                </div>
                <button class="btn btn-primary mt-4" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save API')}}</button>
            </form>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'sociallinks' ? 'show active' : '' }}" id="tab-sociallinks" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'sociallinks') }}" method="POST">
                @csrf @method('PUT')
                <div class="avd-field-grid">
                    @foreach([
                        ['social_facebook', 'Facebook', 'fa-brands fa-facebook-f'],
                        ['social_twitter', 'X / Twitter', 'fa-brands fa-x-twitter'],
                        ['social_instagram', 'Instagram', 'fa-brands fa-instagram'],
                        ['social_youtube', 'YouTube', 'fa-brands fa-youtube'],
                        ['social_tiktok', 'TikTok', 'fa-brands fa-tiktok'],
                        ['social_discord', 'Discord', 'fa-brands fa-discord'],
                        ['social_telegram', 'Telegram', 'fa-brands fa-telegram'],
                    ] as $field)
                        <div>
                            <label class="form-label fw-bold" for="{{ $field[0] }}"><i class="{{ $field[2] }} me-1"></i> {{ $display($field[1], \Illuminate\Support\Str::headline($field[0])) }}</label>
                            <input id="{{ $field[0] }}" type="url" name="{{ $field[0] }}" class="form-control" value="{{ $value('sociallinks', $field[0]) }}">
                        </div>
                    @endforeach
                </div>
                <button class="btn btn-primary mt-4" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save Social Links')}}</button>
            </form>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'maintenance' ? 'show active' : '' }}" id="tab-maintenance" role="tabpanel">
            <form class="avd-settings-panel p-3 p-md-4" action="{{ route('admin.settings.update.group', 'maintenance') }}" method="POST">
                @csrf @method('PUT')
                
                @php
                    $isMaintActive = $isChecked('maintenance', 'maintenance_mode_enabled', '0');
                @endphp

                <!-- Prominent Live Status Banner -->
                <div class="card border p-3 mb-4 {{ $isMaintActive ? 'border-warning bg-warning-subtle' : 'border-success-subtle bg-success-subtle' }}" style="border-radius: 12px;">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle {{ $isMaintActive ? 'bg-warning text-dark' : 'bg-success text-white' }} d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; font-size: 1.25rem;">
                                <i class="fa-solid {{ $isMaintActive ? 'fa-triangle-exclamation' : 'fa-circle-check' }}"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-0 {{ $isMaintActive ? 'text-warning-emphasis' : 'text-success-emphasis' }}">
                                    {{ $isMaintActive ? __('MAINTENANCE MODE IS CURRENTLY ACTIVE') : __('SYSTEM IS LIVE AND ONLINE') }}
                                </h5>
                                <p class="small mb-0 text-body-secondary">
                                    {{ $isMaintActive ? __('Normal visitors are shown a 503 Maintenance page unless their IP is whitelisted or using bypass secret.') : __('Platform is fully open to public traffic and search engine crawlers.') }}
                                </p>
                            </div>
                        </div>
                        <span class="badge {{ $isMaintActive ? 'bg-warning text-dark' : 'bg-success text-white' }} fs-6 px-3 py-1.5 rounded-pill">
                            {{ $isMaintActive ? __('503 Enabled') : __('200 OK Live') }}
                        </span>
                    </div>
                </div>

                <div class="avd-switch-grid mb-4">
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="maintenance_mode_enabled" value="1" @checked($isMaintActive)>
                        <span>
                            <span class="fw-bold d-block">{{__('Enable Maintenance Mode')}}</span>
                            <span class="text-muted small">{{ __('Switches site to maintenance response for non-admins.') }}</span>
                        </span>
                    </label>
                    <label class="avd-switch-card">
                        <input class="form-check-input" type="checkbox" name="maintenance_allow_admins" value="1" @checked($isChecked('maintenance', 'maintenance_allow_admins', '1'))>
                        <span>
                            <span class="fw-bold d-block">{{__('Bypass for Logged-In Admins')}}</span>
                            <span class="text-muted small">{{ __('Allows signed-in administrators to browse the live site.') }}</span>
                        </span>
                    </label>
                </div>

                <div class="avd-field-grid">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <label class="form-label fw-bold mb-0" for="maintenanceSecretInput">{{__('Bypass Secret Key')}}</label>
                            <button type="button" id="btnGenMaintSecret" class="btn btn-sm btn-outline-secondary py-0 px-2">
                                <i class="fa-solid fa-arrows-rotate me-1"></i> {{ __('Generate Random') }}
                            </button>
                        </div>
                        <input type="text" name="maintenance_secret" id="maintenanceSecretInput" class="form-control font-monospace" value="{{ $value('maintenance', 'maintenance_secret') }}" placeholder="e.g. secret-token-123">
                        <div class="d-flex align-items-center gap-1 mt-1">
                            <button type="button" id="btnCopyBypassUrl" class="btn btn-outline-primary btn-sm py-0 px-2" data-base="{{ config('app.url') }}">
                                <i class="fa-solid fa-copy me-1"></i> {{ __('Copy Bypass URL') }}
                            </button>
                            <span id="copyBypassNotice" class="small text-success d-none"><i class="fa-solid fa-check me-1"></i>Copied!</span>
                        </div>
                    </div>
                    <div>
                        <label class="form-label fw-bold" for="maintenance_retry_after">{{__('Retry After (Seconds)')}}</label>
                        <input id="maintenance_retry_after" type="number" name="maintenance_retry_after" class="form-control" value="{{ $value('maintenance', 'maintenance_retry_after', 3600) }}" min="0">
                        <div class="form-text">{{ __('Sent as Retry-After HTTP header to crawlers (3600 = 1 hour).') }}</div>
                    </div>
                    <div class="avd-field-span">
                        <label class="form-label fw-bold" for="maintenance_message">{{__('Maintenance Display Message')}}</label>
                        <textarea id="maintenance_message" name="maintenance_message" class="form-control" rows="3">{{ $value('maintenance', 'maintenance_message', __('We are currently performing maintenance. Please check back soon.')) }}</textarea>
                    </div>
                    <div class="avd-field-span">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <label class="form-label fw-bold mb-0" for="maintenanceAllowedIpsArea">{{__('Allowed Maintenance IP Addresses')}}</label>
                            <button type="button" class="btn btn-sm btn-outline-success py-0 px-2 add-my-ip-btn" data-target="maintenanceAllowedIpsArea">
                                <i class="fa-solid fa-plus me-1"></i> {{ __('Add My Current IP') }} ({{ request()->ip() }})
                            </button>
                        </div>
                        <textarea id="maintenanceAllowedIpsArea" name="maintenance_allowed_ips" class="form-control font-monospace" rows="3" placeholder="{{__('One IP per line (e.g. 192.168.1.1)')}}">{{ $value('maintenance', 'maintenance_allowed_ips') }}</textarea>
                        <div class="form-text">{{ __('Visitors from these IP addresses can view the site normally without seeing the maintenance page.') }}</div>
                    </div>
                </div>
                <button class="btn btn-primary mt-4 px-4 py-2" type="submit"><i class="fa-solid fa-save me-1"></i> {{__('Save Maintenance Settings')}}</button>
            </form>
        </div><div class="tab-pane fade {{ $activeTab === 'system' ? 'show active' : '' }}" id="tab-system" role="tabpanel">
            <div class="avd-settings-panel p-3 p-md-4">
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
                    <div>
                        <div class="avd-section-title mb-1"><i class="fa-solid fa-server text-primary"></i> {{__('System Status & Cache Engine')}}</div>
                        <p class="text-muted mb-0">{{__('Instant granular cache purging, server runtime metrics, and operational health checks.')}}</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-outline-secondary" href="{{ route('admin.env-editor.index') }}"><i class="fa-solid fa-file-shield me-1"></i> {{__('Open Env Editor')}}</a>
                    </div>
                </div>

                <!-- Granular AJAX Cache Purge Suite -->
                <div class="card border p-3 mb-4 bg-body-tertiary" style="border-radius: 14px;">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle bg-warning text-dark d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                <i class="fa-solid fa-broom"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0">{{ __('System Cache Purge Suite') }}</h6>
                                <span class="small text-muted">{{ __('Purge specific cache layers without needing SSH terminal access.') }}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex flex-wrap gap-2" id="cachePurgeButtons">
                        <button type="button" class="btn btn-warning fw-bold px-3 btn-cache-purge" data-type="all">
                            <i class="fa-solid fa-broom me-1"></i> {{ __('Purge All Caches') }}
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-cache-purge" data-type="config">
                            <i class="fa-solid fa-gear me-1"></i> {{ __('Config Cache') }}
                        </button>
                        <button type="button" class="btn btn-outline-info btn-cache-purge" data-type="route">
                            <i class="fa-solid fa-route me-1"></i> {{ __('Route Cache') }}
                        </button>
                        <button type="button" class="btn btn-outline-success btn-cache-purge" data-type="view">
                            <i class="fa-solid fa-eye me-1"></i> {{ __('Blade View Cache') }}
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-cache-purge" data-type="data">
                            <i class="fa-solid fa-database me-1"></i> {{ __('Application Cache') }}
                        </button>
                    </div>
                    <div id="cachePurgeAlert" class="mt-3 d-none"></div>
                </div>

                <div class="avd-section-title"><i class="fa-solid fa-microchip text-primary"></i> {{__('Environment & Services Overview')}}</div>
                <div class="avd-env-grid mb-4">
                    @foreach([
                        ['label' => __('App URL'), 'value' => $env['app_url']],
                        ['label' => __('Environment'), 'value' => $env['app_env']],
                        ['label' => __('Debug Mode'), 'value' => $env['app_debug'] ? 'ON' : 'OFF'],
                        ['label' => __('Cache Driver'), 'value' => $env['cache_driver']],
                        ['label' => __('Queue Driver'), 'value' => $env['queue_connection']],
                        ['label' => __('Session Driver'), 'value' => $env['session_driver']],
                        ['label' => __('Storage Disk'), 'value' => $env['filesystem_disk']],
                        ['label' => __('Mail Driver'), 'value' => $env['mail_mailer']],
                        ['label' => __('Search Driver'), 'value' => $env['scout_driver']],
                        ['label' => __('Broadcasting'), 'value' => $env['broadcast_driver']],
                    ] as $item)
                        <div class="avd-env-item">
                            <div class="small text-muted">{{ $display($item['label']) }}</div>
                            <div class="fw-black text-truncate">{{ $item['value'] ?: __('Not set') }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="avd-section-title"><i class="fa-solid fa-list-check text-primary"></i> {{__('Operational Health Checklist')}}</div>
                <div class="avd-env-grid">
                    @foreach($systemChecks as $check)
                        <div class="avd-env-item">
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <div class="small text-muted text-truncate">{{ $display($check['label']) }}</div>
                                <span class="badge bg-{{ $check['ready'] ? 'success' : 'warning' }}">
                                    {{ $check['ready'] ? __('OK') : __('Check') }}
                                </span>
                            </div>
                            <div class="fw-black text-truncate mt-1" title="{{ $check['value'] }}">{{ $check['value'] ?: __('Not set') }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const tabs = Array.from(document.querySelectorAll('#settingsTabs [data-settings-tab]'));

        if (!tabs.length) {
            return;
        }

        const validTabIds = new Set(tabs.map((tab) => tab.dataset.settingsTab));

        function showSettingsTab(tabId, updateUrl = true) {
            if (!validTabIds.has(tabId)) {
                return;
            }

            const trigger = tabs.find((tab) => tab.dataset.settingsTab === tabId);

            if (!trigger) {
                return;
            }

            if (window.bootstrap?.Tab) {
                window.bootstrap.Tab.getOrCreateInstance(trigger).show();
            } else {
                tabs.forEach((tab) => {
                    const isActive = tab === trigger;
                    const pane = document.querySelector(tab.getAttribute('data-bs-target'));

                    tab.classList.toggle('active', isActive);
                    tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
                    pane?.classList.toggle('show', isActive);
                    pane?.classList.toggle('active', isActive);
                });
            }

            if (updateUrl) {
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tabId);
                window.history.replaceState({ settingsTab: tabId }, '', url);
            }
        }

        tabs.forEach((tab) => {
            tab.addEventListener('shown.bs.tab', function (event) {
                const tabId = event.target?.dataset?.settingsTab;

                if (!tabId) {
                    return;
                }

                const url = new URL(window.location.href);
                url.searchParams.set('tab', tabId);
                window.history.pushState({ settingsTab: tabId }, '', url);
            });
        });

        window.addEventListener('popstate', function () {
            const tabId = new URL(window.location.href).searchParams.get('tab') || 'general';
            showSettingsTab(validTabIds.has(tabId) ? tabId : 'general', false);
        });

        const requestedTab = new URL(window.location.href).searchParams.get('tab');

        if (requestedTab && validTabIds.has(requestedTab)) {
            showSettingsTab(requestedTab, false);
        }
    });

    // Theme Universe Studio & Gallery Controller
    document.addEventListener('DOMContentLoaded', function () {
        const presetSelect = document.getElementById('themePresetSelect');
        if (!presetSelect) return;

        const inputPrimary = document.querySelector('input[name="primary_color"]');
        const inputSecondary = document.querySelector('input[name="brand_secondary_color"]');
        const inputAccent = document.querySelector('input[name="accent_color"]');
        const inputDarkBg = document.querySelector('input[name="dark_bg_color"]');
        const inputDarkSurface = document.querySelector('input[name="dark_surface_color"]');
        const inputLightBg = document.querySelector('input[name="light_bg_color"]');
        const inputLightSurface = document.querySelector('input[name="light_surface_color"]');

        function updateActiveThemeStrip(syncInputs = true) {
            const selected = presetSelect.options[presetSelect.selectedIndex];
            if (!selected) return;

            const label = selected.dataset.label || selected.text;
            const category = selected.dataset.category || '';
            const primary = selected.dataset.primary || '#ff3d61';
            const secondary = selected.dataset.secondary || '#ff8b5c';
            const accent = selected.dataset.accent || '#18c8d8';
            const darkBg = selected.dataset.darkBg || '#070913';
            const darkSurface = selected.dataset.darkSurface || '#0e1322';
            const lightBg = selected.dataset.lightBg || '#f8fafc';
            const lightSurface = selected.dataset.lightSurface || '#ffffff';
            const surface = selected.dataset.surface || 'glass';
            const radius = selected.dataset.radius || 'balanced';
            const font = selected.dataset.font || 'modern';
            const header = selected.dataset.header || 'glass_blur';
            const categoryCard = selected.dataset.categoryCard || 'gradient_mesh';
            const avatarRing = selected.dataset.avatarRing || 'neon_pulse';
            const durationStyle = selected.dataset.duration || 'pill';
            const ambientGlow = selected.dataset.ambientGlow || 'ambient';
            const scrollbar = selected.dataset.scrollbar || 'subtle';
            const desc = selected.dataset.description || '';

            const titleEl = document.getElementById('activeThemeTitle');
            if (titleEl) titleEl.textContent = label;

            const catEl = document.getElementById('activeThemeCategory');
            if (catEl) catEl.textContent = category;

            const dotP = document.getElementById('activeDotPrimary');
            if (dotP) { dotP.style.backgroundColor = primary; dotP.title = 'Primary: ' + primary; }

            const dotS = document.getElementById('activeDotSecondary');
            if (dotS) { dotS.style.backgroundColor = secondary; dotS.title = 'Secondary: ' + secondary; }

            const dotA = document.getElementById('activeDotAccent');
            if (dotA) { dotA.style.backgroundColor = accent; dotA.title = 'Accent: ' + accent; }

            const dotDarkBg = document.getElementById('activeDotDarkBg');
            if (dotDarkBg) { dotDarkBg.style.backgroundColor = darkBg; dotDarkBg.title = 'Dark Canvas BG: ' + darkBg; }

            const dotDarkSurface = document.getElementById('activeDotDarkSurface');
            if (dotDarkSurface) { dotDarkSurface.style.backgroundColor = darkSurface; dotDarkSurface.title = 'Dark Surface: ' + darkSurface; }

            const dotLightBg = document.getElementById('activeDotLightBg');
            if (dotLightBg) { dotLightBg.style.backgroundColor = lightBg; dotLightBg.title = 'Light BG: ' + lightBg; }

            const dotLightSurface = document.getElementById('activeDotLightSurface');
            if (dotLightSurface) { dotLightSurface.style.backgroundColor = lightSurface; dotLightSurface.title = 'Light Surface: ' + lightSurface; }

            const badgeSurf = document.getElementById('activeBadgeSurface');
            if (badgeSurf) badgeSurf.innerHTML = '<i class="fa-solid fa-cube me-1"></i>Surface: ' + surface.charAt(0).toUpperCase() + surface.slice(1);

            const badgeRad = document.getElementById('activeBadgeRadius');
            if (badgeRad) badgeRad.innerHTML = '<i class="fa-solid fa-shapes me-1"></i>Radius: ' + radius.charAt(0).toUpperCase() + radius.slice(1);

            const badgeFnt = document.getElementById('activeBadgeFont');
            if (badgeFnt) badgeFnt.innerHTML = '<i class="fa-solid fa-font me-1"></i>Font: ' + font.charAt(0).toUpperCase() + font.slice(1);

            const badgeHdr = document.getElementById('activeBadgeHeader');
            if (badgeHdr) badgeHdr.innerHTML = '<i class="fa-solid fa-window-maximize me-1"></i>Header: ' + header.replace('_', ' ');

            const descEl = document.getElementById('activeThemeDesc');
            if (descEl) descEl.textContent = desc;

            // Live cascade CSS tokens so admin panel responds immediately
            document.documentElement.style.setProperty('--avd-brand', primary);
            document.documentElement.style.setProperty('--bs-primary', primary);
            document.documentElement.style.setProperty('--avd-brand-2', secondary);
            document.documentElement.style.setProperty('--avd-brand-secondary', secondary);
            document.documentElement.style.setProperty('--avd-cyan', accent);
            document.documentElement.style.setProperty('--avd-accent', accent);
            document.documentElement.style.setProperty('--avd-dark-bg', darkBg);
            document.documentElement.style.setProperty('--avd-dark-surface', darkSurface);
            document.documentElement.style.setProperty('--avd-light-bg', lightBg);
            document.documentElement.style.setProperty('--avd-light-surface', lightSurface);
            document.documentElement.style.setProperty('--avd-bg', darkBg);
            document.documentElement.style.setProperty('--avd-surface', darkSurface);

            if (syncInputs) {
                const applyCheck = document.getElementById('applyThemePresetCheck');
                if (applyCheck) applyCheck.checked = true;

                if (inputPrimary) inputPrimary.value = primary;
                if (inputSecondary) inputSecondary.value = secondary;
                if (inputAccent) inputAccent.value = accent;
                if (inputDarkBg) inputDarkBg.value = darkBg;
                if (inputDarkSurface) inputDarkSurface.value = darkSurface;
                if (inputLightBg) inputLightBg.value = lightBg;
                if (inputLightSurface) inputLightSurface.value = lightSurface;

                const surfSelect = document.querySelector('select[name="surface_style"]');
                if (surfSelect && surface) surfSelect.value = surface;

                const radSelect = document.querySelector('select[name="radius_scale"]');
                if (radSelect && radius) radSelect.value = radius;

                const fontSelect = document.querySelector('select[name="font_family"]');
                if (fontSelect && font) fontSelect.value = font;

                const hdrSelect = document.querySelector('select[name="header_style"]');
                if (hdrSelect && header) hdrSelect.value = header;

                const categoryCardSelect = document.querySelector('select[name="category_card_style"]');
                if (categoryCardSelect && categoryCard) categoryCardSelect.value = categoryCard;

                const avatarRingSelect = document.querySelector('select[name="creator_avatar_ring_style"]');
                if (avatarRingSelect && avatarRing) avatarRingSelect.value = avatarRing;

                const durationSelect = document.querySelector('select[name="video_card_duration_style"]');
                if (durationSelect && durationStyle) durationSelect.value = durationStyle;

                const ambientSelect = document.querySelector('select[name="watch_ambient_glow_intensity"]');
                if (ambientSelect && ambientGlow) ambientSelect.value = ambientGlow;

                const scrollbarSelect = document.querySelector('select[name="scrollbar_style"]');
                if (scrollbarSelect && scrollbar) scrollbarSelect.value = scrollbar;

                const oledCheck = document.getElementById('themeOledBlackCheck');
                if (oledCheck && selected.dataset.oled !== undefined) oledCheck.checked = selected.dataset.oled === '1';

                if (selected.dataset.homeLayout) {
                    const homeLayout = document.querySelector('select[name="home_layout_mode"]');
                    if (homeLayout) homeLayout.value = selected.dataset.homeLayout;
                }
                if (selected.dataset.heroLayout) {
                    const heroLayout = document.querySelector('select[name="hero_layout_mode"]');
                    if (heroLayout) heroLayout.value = selected.dataset.heroLayout;
                }
                if (selected.dataset.backToTopStyle) {
                    const backToTopStyle = document.querySelector('select[name="back_to_top_style"]');
                    if (backToTopStyle) backToTopStyle.value = selected.dataset.backToTopStyle;
                }
                if (selected.dataset.watchLayout) {
                    const watchLayout = document.querySelector('select[name="watch_layout_mode"]');
                    if (watchLayout) watchLayout.value = selected.dataset.watchLayout;
                }
                if (selected.dataset.cardLayout) {
                    const cardLayout = document.querySelector('select[name="video_card_layout_style"]');
                    if (cardLayout) cardLayout.value = selected.dataset.cardLayout;
                }
                if (selected.dataset.atmosphere) {
                    const atmos = document.querySelector('select[name="background_atmosphere_effect"]');
                    if (atmos) atmos.value = selected.dataset.atmosphere;
                }
                if (selected.dataset.modalStyle) {
                    const modalStyle = document.querySelector('select[name="modal_presentation_style"]');
                    if (modalStyle) modalStyle.value = selected.dataset.modalStyle;
                }
                if (selected.dataset.motionEngine) {
                    const motionEngine = document.querySelector('select[name="motion_engine_level"]');
                    if (motionEngine) motionEngine.value = selected.dataset.motionEngine;
                }
                if (selected.dataset.typoPairing) {
                    const typoPairing = document.querySelector('select[name="typography_pairing"]');
                    if (typoPairing) typoPairing.value = selected.dataset.typoPairing;
                }
                if (selected.dataset.tilt3d !== undefined) {
                    const tiltCheck = document.querySelector('input[name="interactive_3d_card_tilt_enabled"]');
                    if (tiltCheck) tiltCheck.checked = selected.dataset.tilt3d === '1';
                }
                if (selected.dataset.cinemaDiffusion !== undefined) {
                    const diffCheck = document.querySelector('input[name="cinema_ambient_light_diffusion_enabled"]');
                    if (diffCheck) diffCheck.checked = selected.dataset.cinemaDiffusion === '1';
                }
                if (selected.dataset.mobileColumns) {
                    const mobileColumns = document.querySelector('select[name="mobile_video_columns"]');
                    if (mobileColumns) mobileColumns.value = selected.dataset.mobileColumns;
                }
                if (selected.dataset.mobileDensity) {
                    const mobileDensity = document.querySelector('select[name="mobile_video_card_density"]');
                    if (mobileDensity) mobileDensity.value = selected.dataset.mobileDensity;
                }
                if (selected.dataset.mobileBottomNav !== undefined) {
                    const mobileNav = document.querySelector('input[name="mobile_bottom_nav_enabled"]');
                    if (mobileNav) mobileNav.checked = selected.dataset.mobileBottomNav === '1';
                }
                if (selected.dataset.mobileTapPreview !== undefined) {
                    const tapPreview = document.querySelector('input[name="mobile_tap_preview_enabled"]');
                    if (tapPreview) tapPreview.checked = selected.dataset.mobileTapPreview === '1';
                }
                if (selected.dataset.heroAutoplay !== undefined) {
                    const heroAutoplay = document.querySelector('input[name="hero_slider_autoplay_enabled"]');
                    if (heroAutoplay) heroAutoplay.checked = selected.dataset.heroAutoplay === '1';
                }
                if (selected.dataset.heroAutoplaySeconds) {
                    const heroAutoplaySeconds = document.querySelector('input[name="hero_slider_autoplay_seconds"]');
                    if (heroAutoplaySeconds) heroAutoplaySeconds.value = selected.dataset.heroAutoplaySeconds;
                }
                if (selected.dataset.titleLines) {
                    const titleLines = document.querySelector('select[name="video_card_title_lines"]');
                    if (titleLines) titleLines.value = selected.dataset.titleLines;
                }
                if (selected.dataset.cinemaFilter !== undefined) {
                    const cinemaSelect = document.querySelector('select[name="video_player_cinema_filter"]');
                    if (cinemaSelect) cinemaSelect.value = selected.dataset.cinemaFilter;
                }
                if (selected.dataset.cardSpotlight !== undefined) {
                    const spotCheck = document.querySelector('input[name="card_spotlight_glow_enabled"]');
                    if (spotCheck) spotCheck.checked = selected.dataset.cardSpotlight === '1';
                }
                if (selected.dataset.theme) {
                    const themeSelect = document.querySelector('select[name="theme"]');
                    if (themeSelect) themeSelect.value = selected.dataset.theme;
                }
            }

            try { updateLiveStudioStage(); } catch (e) {}
        }

        function getLuminance(hex) {
            if (!hex || typeof hex !== 'string') return 0.5;
            hex = hex.replace('#', '').trim();
            if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
            if (hex.length !== 6) return 0.5;
            const r = parseInt(hex.substring(0, 2), 16) / 255;
            const g = parseInt(hex.substring(2, 4), 16) / 255;
            const b = parseInt(hex.substring(4, 6), 16) / 255;
            const toLinear = (c) => (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
            return 0.2126 * toLinear(r) + 0.7152 * toLinear(g) + 0.0722 * toLinear(b);
        }

        function getContrastRatio(hex1, hex2) {
            const l1 = getLuminance(hex1);
            const l2 = getLuminance(hex2);
            const lighter = Math.max(l1, l2);
            const darker = Math.min(l1, l2);
            return ((lighter + 0.05) / (darker + 0.05)).toFixed(1);
        }

        let liveStudioStageCanvasMode = 'dark';

        function updateLiveStudioStage() {
            const p = inputPrimary ? inputPrimary.value : '#ff3d61';
            const s = inputSecondary ? inputSecondary.value : '#ff8b5c';
            const a = inputAccent ? inputAccent.value : '#18c8d8';
            const dbg = inputDarkBg ? inputDarkBg.value : '#070913';
            const dsurf = inputDarkSurface ? inputDarkSurface.value : '#0e1322';
            const lbg = inputLightBg ? inputLightBg.value : '#f8fafc';
            const lsurf = inputLightSurface ? inputLightSurface.value : '#ffffff';

            const isDarkCanvas = liveStudioStageCanvasMode === 'dark';
            const activeBg = isDarkCanvas ? dbg : lbg;
            const activeSurf = isDarkCanvas ? dsurf : lsurf;

            // 1. Dynamic WCAG 2.2 Contrast Ratio Calculation
            const ratio = parseFloat(getContrastRatio(p, activeBg));
            const wcagBadge = document.getElementById('wcagContrastBadge');
            const wcagText = document.getElementById('wcagContrastText');
            if (wcagBadge && wcagText) {
                const canvasSuffix = isDarkCanvas ? ' [Dark]' : ' [Light]';
                if (ratio >= 7.0) {
                    wcagText.textContent = `WCAG AAA (${ratio}:1)${canvasSuffix}`;
                    wcagBadge.style.background = 'rgba(34, 197, 94, 0.15)';
                    wcagBadge.style.color = '#22c55e';
                    wcagBadge.style.borderColor = 'rgba(34, 197, 94, 0.3)';
                } else if (ratio >= 4.5) {
                    wcagText.textContent = `WCAG AA (${ratio}:1)${canvasSuffix}`;
                    wcagBadge.style.background = 'rgba(16, 185, 129, 0.15)';
                    wcagBadge.style.color = '#10b981';
                    wcagBadge.style.borderColor = 'rgba(16, 185, 129, 0.3)';
                } else if (ratio >= 3.0) {
                    wcagText.textContent = `WCAG AA Large (${ratio}:1)${canvasSuffix}`;
                    wcagBadge.style.background = 'rgba(245, 158, 11, 0.15)';
                    wcagBadge.style.color = '#f59e0b';
                    wcagBadge.style.borderColor = 'rgba(245, 158, 11, 0.3)';
                } else {
                    wcagText.textContent = `Contrast Alert (${ratio}:1)${canvasSuffix}`;
                    wcagBadge.style.background = 'rgba(239, 68, 68, 0.15)';
                    wcagBadge.style.color = '#ef4444';
                    wcagBadge.style.borderColor = 'rgba(239, 68, 68, 0.3)';
                }
            }

            // 2. Mock Video Card Preview Container
            const cardBox = document.getElementById('liveCardPreviewContainer');
            if (cardBox) {
                cardBox.style.backgroundColor = activeSurf;
                cardBox.style.borderColor = isDarkCanvas ? 'rgba(255, 255, 255, 0.12)' : 'rgba(0, 0, 0, 0.1)';
            }

            const cardTitleEl = document.getElementById('liveCardTitleText');
            if (cardTitleEl) {
                cardTitleEl.style.color = isDarkCanvas ? '#ffffff' : '#0f172a';
            }

            const glow = document.getElementById('liveCardDiffusionGlow');
            if (glow) {
                glow.style.background = `radial-gradient(circle, ${p} 0%, transparent 70%)`;
            }

            const playIcon = document.getElementById('livePlayIcon');
            if (playIcon) playIcon.style.color = p;

            const avatarRing = document.getElementById('liveCardAvatarRing');
            if (avatarRing) {
                avatarRing.style.background = `linear-gradient(135deg, ${p}, ${a})`;
            }

            const catChip = document.getElementById('liveCardCategoryChip');
            if (catChip) {
                catChip.style.color = p;
                catChip.style.borderColor = p + '40';
            }

            // 3. Mock Player Header & Telemetry
            const headerBox = document.getElementById('liveHeaderPreviewContainer');
            if (headerBox) {
                headerBox.style.backgroundColor = activeSurf;
                headerBox.style.borderColor = isDarkCanvas ? 'rgba(255, 255, 255, 0.12)' : 'rgba(0, 0, 0, 0.1)';
            }

            const searchBar = document.getElementById('liveSearchBar');
            if (searchBar) {
                searchBar.style.backgroundColor = activeBg;
                searchBar.style.borderColor = isDarkCanvas ? 'rgba(255, 255, 255, 0.12)' : 'rgba(0, 0, 0, 0.1)';
            }

            const logoIcon = document.getElementById('liveLogoIcon');
            if (logoIcon) logoIcon.style.color = p;

            const logoText = document.getElementById('liveLogoText');
            if (logoText) {
                logoText.style.background = `linear-gradient(135deg, ${p}, ${s})`;
                logoText.style.webkitBackgroundClip = 'text';
                logoText.style.webkitTextFillColor = 'transparent';
            }

            const subBtn = document.getElementById('liveSubscribeBtn');
            if (subBtn) subBtn.style.backgroundColor = p;

            const hudBox = document.getElementById('liveTelemetryHudMock');
            if (hudBox) {
                hudBox.style.backgroundColor = isDarkCanvas ? 'rgba(0,0,0,0.3)' : 'rgba(0,0,0,0.04)';
                hudBox.style.borderColor = a + '40';
            }

            const hudBadge = document.getElementById('liveHudBadge');
            if (hudBadge) {
                hudBadge.style.color = a;
                hudBadge.style.backgroundColor = a + '20';
            }

            const hudRes = document.getElementById('liveHudResolutionText');
            if (hudRes) hudRes.style.color = a;

            // 4. Token chips (All 7 Swatches)
            const tP = document.getElementById('liveTokenPrimary');
            if (tP) { tP.textContent = `Primary: ${p}`; tP.style.color = p; tP.dataset.hex = p; }
            const tS = document.getElementById('liveTokenSecondary');
            if (tS) { tS.textContent = `Secondary: ${s}`; tS.style.color = s; tS.dataset.hex = s; }
            const tA = document.getElementById('liveTokenAccent');
            if (tA) { tA.textContent = `Accent: ${a}`; tA.style.color = a; tA.dataset.hex = a; }
            const tDbg = document.getElementById('liveTokenDarkBg');
            if (tDbg) { tDbg.textContent = `Dark BG: ${dbg}`; tDbg.dataset.hex = dbg; }
            const tDsurf = document.getElementById('liveTokenDarkSurface');
            if (tDsurf) { tDsurf.textContent = `Dark Surf: ${dsurf}`; tDsurf.dataset.hex = dsurf; }
            const tLbg = document.getElementById('liveTokenLightBg');
            if (tLbg) { tLbg.textContent = `Light BG: ${lbg}`; tLbg.dataset.hex = lbg; }
            const tLsurf = document.getElementById('liveTokenLightSurface');
            if (tLsurf) { tLsurf.textContent = `Light Surf: ${lsurf}`; tLsurf.dataset.hex = lsurf; }

            // Sync Studio Native Color Pickers and labels
            const syncStudioPicker = (id, hex) => {
                const el = document.getElementById(id);
                if (el && document.activeElement !== el && hex && hex.startsWith('#') && hex.length === 7) {
                    el.value = hex;
                }
            };
            syncStudioPicker('pickerStudioPrimary', p);
            syncStudioPicker('pickerStudioSecondary', s);
            syncStudioPicker('pickerStudioAccent', a);
            syncStudioPicker('pickerStudioDarkBg', dbg);
            syncStudioPicker('pickerStudioDarkSurface', dsurf);
            syncStudioPicker('pickerStudioLightBg', lbg);
            syncStudioPicker('pickerStudioLightSurface', lsurf);

            // Studio Fine-Tuner Labels
            const tPFine = document.getElementById('liveTokenPrimaryFine');
            if (tPFine) { tPFine.textContent = `Pri: ${p}`; tPFine.style.color = p; tPFine.dataset.hex = p; }
            const tSFine = document.getElementById('liveTokenSecondaryFine');
            if (tSFine) { tSFine.textContent = `Sec: ${s}`; tSFine.style.color = s; tSFine.dataset.hex = s; }
            const tAFine = document.getElementById('liveTokenAccentFine');
            if (tAFine) { tAFine.textContent = `Acc: ${a}`; tAFine.style.color = a; tAFine.dataset.hex = a; }
            const tDbgFine = document.getElementById('liveTokenDarkBgFine');
            if (tDbgFine) { tDbgFine.textContent = `D-BG: ${dbg}`; tDbgFine.dataset.hex = dbg; }
            const tDsurfFine = document.getElementById('liveTokenDarkSurfaceFine');
            if (tDsurfFine) { tDsurfFine.textContent = `D-Surf: ${dsurf}`; tDsurfFine.dataset.hex = dsurf; }
            const tLbgFine = document.getElementById('liveTokenLightBgFine');
            if (tLbgFine) { tLbgFine.textContent = `L-BG: ${lbg}`; tLbgFine.dataset.hex = lbg; }
            const tLsurfFine = document.getElementById('liveTokenLightSurfaceFine');
            if (tLsurfFine) { tLsurfFine.textContent = `L-Surf: ${lsurf}`; tLsurfFine.dataset.hex = lsurf; }

            // Cinema LUT Filter Preview on Card Thumbnail
            const cinemaSelect = document.querySelector('select[name="video_player_cinema_filter"]');
            const cinemaVal = cinemaSelect ? cinemaSelect.value : 'none';
            const thumbBox = document.getElementById('liveCardThumbContainer');
            if (thumbBox) {
                thumbBox.setAttribute('data-avd-cinema-filter', cinemaVal);
            }
            const cinemaBadge = document.getElementById('liveActiveCinemaBadge');
            if (cinemaBadge && cinemaSelect) {
                const cinemaText = cinemaSelect.options[cinemaSelect.selectedIndex]?.text?.split('(')[0]?.trim() || cinemaVal;
                cinemaBadge.innerHTML = `<i class="fa-solid fa-sliders me-1 text-danger"></i>LUT: ${cinemaText}`;
            }
            document.querySelectorAll('#liveStudioCinemaPills button').forEach(b => {
                if (b.dataset.cinema === cinemaVal) {
                    b.classList.add('active', 'btn-dark');
                    b.classList.remove('text-muted');
                } else {
                    b.classList.remove('active', 'btn-dark');
                    b.classList.add('text-muted');
                }
            });

            // Card Spotlight Status in Studio
            const spotCheck = document.querySelector('input[name="card_spotlight_glow_enabled"]');
            const spotActive = spotCheck ? spotCheck.checked : true;
            const spotBadge = document.getElementById('liveSpotlightBadge');
            if (spotBadge) {
                spotBadge.innerHTML = `<i class="fa-solid fa-lightbulb me-1 ${spotActive ? 'text-warning' : 'text-muted'}"></i>Spotlight: ` + (spotActive ? 'On' : 'Off');
            }

            // Border Beam Status in Studio
            const beamCheck = document.querySelector('input[name="border_beam_glow_enabled"]');
            const beamActive = beamCheck ? beamCheck.checked : true;
            const beamSpeedSelect = document.querySelector('select[name="border_beam_speed"]');
            const beamSpeedVal = beamSpeedSelect ? beamSpeedSelect.value : 'normal';
            const studioBeam = document.getElementById('liveStudioBorderBeam');
            if (studioBeam) {
                studioBeam.style.display = beamActive ? 'block' : 'none';
            }
            const stage = document.getElementById('liveThemeStudioStage');
            if (stage) {
                stage.setAttribute('data-avd-border-beam', beamActive ? 'on' : 'off');
                stage.setAttribute('data-avd-border-beam-speed', beamSpeedVal);
                stage.style.setProperty('--avd-primary', p);
                stage.style.setProperty('--avd-accent', a);
                stage.style.setProperty('--avd-brand', p);
                stage.style.setProperty('--avd-cyan', a);
            }

            // 5. Surface & Radius & Layout inspection
            const surfSelect = document.querySelector('select[name="surface_style"]');
            const surfText = document.getElementById('livePreviewSurfaceText');
            if (surfText && surfSelect) {
                surfText.textContent = surfSelect.options[surfSelect.selectedIndex]?.text || 'Surface';
            }

            const layoutSelect = document.querySelector('select[name="home_layout_mode"]');
            const layoutBadge = document.getElementById('liveLayoutArchetypeBadge');
            if (layoutBadge && layoutSelect) {
                layoutBadge.innerHTML = `<i class="fa-solid fa-layer-group me-1"></i>Layout: ` + (layoutSelect.options[layoutSelect.selectedIndex]?.text?.split('(')[0]?.trim() || 'Modern Grid');
            }

            const radSelect = document.querySelector('select[name="radius_scale"]');
            const radVal = radSelect ? radSelect.value : 'balanced';
            if (cardBox) cardBox.style.borderRadius = (radVal === 'compact' ? '8px' : (radVal === 'rounded' ? '24px' : '16px'));

            // 6. Typography & Dynamic Atmosphere Visualizer
            const studioStage = document.getElementById('liveThemeStudioStage');
            const atmoSelect = document.querySelector('select[name="background_atmosphere_effect"]');
            const atmoVal = atmoSelect ? atmoSelect.value : 'clean_none';
            if (studioStage) {
                studioStage.style.backgroundColor = activeBg;
                studioStage.style.transition = 'background 0.4s ease, border-color 0.4s ease';
                if (atmoVal === 'aurora_glow') {
                    studioStage.style.backgroundImage = `radial-gradient(at 0% 0%, ${p}33 0px, transparent 50%), radial-gradient(at 100% 100%, ${a}25 0px, transparent 50%)`;
                } else if (atmoVal === 'cyber_grid') {
                    studioStage.style.backgroundImage = `linear-gradient(to right, ${p}15 1px, transparent 1px), linear-gradient(to bottom, ${p}15 1px, transparent 1px)`;
                    studioStage.style.backgroundSize = '24px 24px';
                } else if (atmoVal === 'cosmic_particles') {
                    studioStage.style.backgroundImage = `radial-gradient(circle at 50% 50%, ${s}22 0%, transparent 65%), radial-gradient(circle at 80% 20%, ${p}20 0%, transparent 50%)`;
                } else if (atmoVal === 'mesh_nebula') {
                    studioStage.style.backgroundImage = `radial-gradient(circle at 10% 20%, ${p}30 0%, transparent 40%), radial-gradient(circle at 90% 80%, ${s}30 0%, transparent 40%), radial-gradient(circle at 50% 50%, ${a}25 0%, transparent 50%)`;
                } else {
                    studioStage.style.backgroundImage = 'none';
                }
            }

            const typoSelect = document.querySelector('select[name="typography_pairing"]');
            const typoVal = typoSelect ? typoSelect.value : 'modern_tech';
            const typoFonts = {
                'modern_tech': "'Outfit', sans-serif",
                'cyberpunk_future': "'Orbitron', sans-serif",
                'hacker_mono': "'JetBrains Mono', monospace",
                'editorial_luxury': "'Playfair Display', serif",
                'anime_pop': "'Quicksand', sans-serif",
                'regal_gold': "'Cinzel', serif",
                'clean_minimal': "'Inter', sans-serif",
                'nordic_frost': "'Space Grotesk', sans-serif"
            };
            const activeFont = typoFonts[typoVal] || "'Outfit', sans-serif";
            if (cardTitleEl) cardTitleEl.style.fontFamily = activeFont;
            if (logoText) logoText.style.fontFamily = activeFont;

            const typoBadge = document.getElementById('liveActiveTypoBadge');
            if (typoBadge && typoSelect) {
                typoBadge.innerHTML = `<i class="fa-solid fa-font me-1 text-info"></i>Typo: ` + (typoSelect.options[typoSelect.selectedIndex]?.text?.split('(')[0]?.trim() || typoVal);
            }
            const atmoBadge = document.getElementById('liveActiveAtmoBadge');
            if (atmoBadge && atmoSelect) {
                atmoBadge.innerHTML = `<i class="fa-solid fa-wand-magic-sparkles me-1 text-warning"></i>FX: ` + (atmoSelect.options[atmoSelect.selectedIndex]?.text?.split('(')[0]?.trim() || atmoVal);
            }

            // 7. Glass Blur & Ambient Rim Glow
            applyGlassStudioEffects();
        }

        // Glass Blur Range Slider & Ambient Rim Glow inside Studio
        const blurRange = document.getElementById('liveStudioBlurRange');
        const blurValText = document.getElementById('liveStudioBlurValue');
        const borderBeamToggle = document.getElementById('liveStudioBorderBeamToggle');

        function applyGlassStudioEffects() {
            const blurPx = blurRange ? blurRange.value : 12;
            if (blurValText) blurValText.textContent = `${blurPx}px`;

            const cardBox = document.getElementById('liveCardPreviewContainer');
            const headerBox = document.getElementById('liveHeaderPreviewContainer');
            const p = inputPrimary ? inputPrimary.value : '#ff3d61';
            const hasGlow = borderBeamToggle ? borderBeamToggle.checked : true;

            [cardBox, headerBox].forEach(el => {
                if (!el) return;
                el.style.backdropFilter = `blur(${blurPx}px)`;
                el.style.webkitBackdropFilter = `blur(${blurPx}px)`;
                if (hasGlow) {
                    el.style.boxShadow = `0 8px 32px rgba(0,0,0,0.35), 0 0 16px ${p}44, inset 0 0 1px rgba(255,255,255,0.2)`;
                } else {
                    el.style.boxShadow = `0 4px 20px rgba(0,0,0,0.25)`;
                }
            });
        }

        if (blurRange) {
            blurRange.addEventListener('input', applyGlassStudioEffects);
        }
        if (borderBeamToggle) {
            borderBeamToggle.addEventListener('change', applyGlassStudioEffects);
        }

        // Live Studio Canvas Dark / Light Switcher
        const btnCanvasDark = document.getElementById('btnStudioCanvasDark');
        const btnCanvasLight = document.getElementById('btnStudioCanvasLight');
        if (btnCanvasDark && btnCanvasLight) {
            btnCanvasDark.addEventListener('click', () => {
                liveStudioStageCanvasMode = 'dark';
                btnCanvasDark.classList.add('active');
                btnCanvasDark.classList.remove('text-muted');
                btnCanvasLight.classList.remove('active');
                btnCanvasLight.classList.add('text-muted');
                updateLiveStudioStage();
            });
            btnCanvasLight.addEventListener('click', () => {
                liveStudioStageCanvasMode = 'light';
                btnCanvasLight.classList.add('active');
                btnCanvasLight.classList.remove('text-muted');
                btnCanvasDark.classList.remove('active');
                btnCanvasDark.classList.add('text-muted');
                updateLiveStudioStage();
            });
        }

        // Mini Card Layout Preview Switcher inside Studio
        document.querySelectorAll('#liveStudioCardStyleToggle button').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('#liveStudioCardStyleToggle button').forEach(b => {
                    b.classList.remove('active');
                    b.classList.add('text-muted');
                });
                this.classList.add('active');
                this.classList.remove('text-muted');

                const style = this.getAttribute('data-card-style');
                const cardLayoutSelect = document.querySelector('select[name="video_card_layout_style"]');
                if (cardLayoutSelect) {
                    cardLayoutSelect.value = style;
                }
                const applyCheck = document.getElementById('applyThemePresetCheck');
                if (applyCheck) applyCheck.checked = false;
                updateLiveStudioStage();
            });
        });

        // Mini Typography Pairing Quick Switcher inside Studio
        document.querySelectorAll('#liveStudioTypoToggle button').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('#liveStudioTypoToggle button').forEach(b => {
                    b.classList.remove('active');
                    b.classList.add('text-muted');
                });
                this.classList.add('active');
                this.classList.remove('text-muted');

                const typo = this.getAttribute('data-typo');
                const typoSelect = document.querySelector('select[name="typography_pairing"]');
                if (typoSelect) {
                    typoSelect.value = typo;
                }
                const applyCheck = document.getElementById('applyThemePresetCheck');
                if (applyCheck) applyCheck.checked = false;
                updateLiveStudioStage();
            });
        });

        // Atmosphere FX Dynamic Preview Switcher inside Studio
        document.querySelectorAll('#liveStudioAtmoPills button').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('#liveStudioAtmoPills button').forEach(b => {
                    b.classList.remove('active', 'btn-primary');
                    b.classList.add('text-muted', 'btn-outline-secondary');
                });
                this.classList.add('active', 'btn-primary');
                this.classList.remove('text-muted', 'btn-outline-secondary');

                const atmo = this.getAttribute('data-atmo');
                const atmoSelect = document.querySelector('select[name="background_atmosphere_effect"]');
                if (atmoSelect) {
                    atmoSelect.value = atmo;
                }
                const applyCheck = document.getElementById('applyThemePresetCheck');
                if (applyCheck) applyCheck.checked = false;
                updateLiveStudioStage();
            });
        });

        // Category Quick Filter Strip Handlers
        document.querySelectorAll('.cat-quick-filter-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.cat-quick-filter-btn').forEach(b => {
                    b.classList.remove('btn-primary', 'active');
                    b.classList.add('btn-outline-secondary');
                });
                this.classList.remove('btn-outline-secondary');
                this.classList.add('btn-primary', 'active');

                const cat = this.getAttribute('data-category');
                const optgroups = presetSelect.querySelectorAll('optgroup');
                let firstMatchOption = null;

                optgroups.forEach(og => {
                    if (cat === 'all' || og.label.toLowerCase().includes(cat.toLowerCase())) {
                        og.style.display = '';
                        if (!firstMatchOption && og.children.length > 0) {
                            firstMatchOption = og.children[0];
                        }
                    } else {
                        og.style.display = 'none';
                    }
                });

                if (firstMatchOption && cat !== 'all') {
                    presetSelect.value = firstMatchOption.value;
                    updateActiveThemeStrip(true);
                }
            });
        });

        // Mood & Color Temperature Quick-Filter Handlers
        document.querySelectorAll('.mood-filter-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.mood-filter-btn').forEach(b => {
                    b.classList.remove('btn-primary', 'active');
                    b.classList.add('btn-outline-secondary');
                });
                this.classList.remove('btn-outline-secondary');
                this.classList.add('btn-primary', 'active');

                const mood = this.getAttribute('data-mood');
                const optgroups = presetSelect.querySelectorAll('optgroup');
                let firstMatchOption = null;

                const moodKeywords = {
                    warm: ['warm', 'luxury', 'amber', 'gold', 'sunset', 'fire', 'crimson', 'autumn', 'orange', 'bronze'],
                    cool: ['cool', 'nordic', 'frost', 'arctic', 'ice', 'glacier', 'ocean', 'blue', 'sapphire', 'cyan', 'emerald'],
                    cyber: ['cyber', 'neon', 'synth', 'matrix', 'hacker', 'future', 'tokyo', 'quantum', 'acid', 'arcade'],
                    pastel: ['pastel', 'anime', 'sakura', 'kawaii', 'candy', 'pop', 'dream', 'lavender', 'rose', 'blush'],
                    mono: ['mono', 'dark', 'oled', 'black', 'stealth', 'minimal', 'obsidian', 'dracula', 'terminal', 'swiss']
                };

                const targetWords = moodKeywords[mood] || [];

                optgroups.forEach(og => {
                    let hasVisibleChild = false;
                    og.querySelectorAll('option').forEach(opt => {
                        if (mood === 'all') {
                            opt.style.display = '';
                            hasVisibleChild = true;
                        } else {
                            const text = (opt.textContent + ' ' + (opt.getAttribute('data-category') || '') + ' ' + (opt.getAttribute('data-label') || '') + ' ' + (opt.getAttribute('data-description') || '')).toLowerCase();
                            const isMatch = targetWords.some(w => text.includes(w));
                            opt.style.display = isMatch ? '' : 'none';
                            if (isMatch) {
                                hasVisibleChild = true;
                                if (!firstMatchOption) firstMatchOption = opt;
                            }
                        }
                    });
                    og.style.display = hasVisibleChild ? '' : 'none';
                });

                if (firstMatchOption && mood !== 'all') {
                    presetSelect.value = firstMatchOption.value;
                    updateActiveThemeStrip(true);
                }

                if (typeof window.avdPlaySound === 'function') {
                    window.avdPlaySound('click');
                }
            });
        });

        // Click-to-Copy for Swatch Dots & Token Chips
        document.querySelectorAll('.active-swatch-dot, .studio-token-pill').forEach(el => {
            el.addEventListener('click', function () {
                const hex = this.dataset.hex || this.style.backgroundColor;
                if (!hex) return;
                let cleanHex = hex;
                if (hex.startsWith('rgb')) {
                    const rgb = hex.match(/\d+/g);
                    if (rgb && rgb.length >= 3) {
                        cleanHex = '#' + ((1 << 24) + (parseInt(rgb[0]) << 16) + (parseInt(rgb[1]) << 8) + parseInt(rgb[2])).toString(16).slice(1);
                    }
                }
                navigator.clipboard.writeText(cleanHex).then(() => {
                    if (typeof alertToast === 'function') {
                        alertToast(`Copied ${cleanHex} to clipboard!`, 'success');
                    }
                }).catch(() => {});
            });
        });

        // Responsive Viewport Simulator Handlers
        const btnVpDesktop = document.getElementById('btnStudioViewportDesktop');
        const btnVpMobile = document.getElementById('btnStudioViewportMobile');
        const cardCol = document.getElementById('liveCardPreviewCol');
        if (btnVpDesktop && btnVpMobile && cardCol) {
            btnVpDesktop.addEventListener('click', () => {
                btnVpDesktop.classList.add('active');
                btnVpDesktop.classList.remove('text-muted');
                btnVpMobile.classList.remove('active');
                btnVpMobile.classList.add('text-muted');
                cardCol.style.maxWidth = '';
                cardCol.style.margin = '';
                cardCol.style.flex = '';
            });
            btnVpMobile.addEventListener('click', () => {
                btnVpMobile.classList.add('active');
                btnVpMobile.classList.remove('text-muted');
                btnVpDesktop.classList.remove('active');
                btnVpDesktop.classList.add('text-muted');
                cardCol.style.maxWidth = '360px';
                cardCol.style.margin = '0 auto';
                cardCol.style.flex = '0 0 360px';
            });
        }

        // Color Science & Accessibility Utilities
        function hexToRgb(hex) {
            hex = (hex || '').replace('#', '').trim();
            if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
            const num = parseInt(hex || '0', 16);
            return { r: (num >> 16) & 255, g: (num >> 8) & 255, b: num & 255 };
        }

        function rgbToHex(r, g, b) {
            return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
        }

        function rgbToHsl(r, g, b) {
            r /= 255; g /= 255; b /= 255;
            const max = Math.max(r, g, b), min = Math.min(r, g, b);
            let h, s, l = (max + min) / 2;
            if (max === min) {
                h = s = 0;
            } else {
                const d = max - min;
                s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
                switch (max) {
                    case r: h = ((g - b) / d + (g < b ? 6 : 0)) / 6; break;
                    case g: h = ((b - r) / d + 2) / 6; break;
                    case b: h = ((r - g) / d + 4) / 6; break;
                }
            }
            return { h: Math.round(h * 360), s: Math.round(s * 100), l: Math.round(l * 100) };
        }

        function hslToRgb(h, s, l) {
            h /= 360; s /= 100; l /= 100;
            let r, g, b;
            if (s === 0) {
                r = g = b = l;
            } else {
                const hue2rgb = (p, q, t) => {
                    if (t < 0) t += 1;
                    if (t > 1) t -= 1;
                    if (t < 1/6) return p + (q - p) * 6 * t;
                    if (t < 1/2) return q;
                    if (t < 2/3) return p + (q - p) * (2/3 - t) * 6;
                    return p;
                };
                const q = l < 0.5 ? l * (1 + s) : l + s - l * s;
                const p = 2 * l - q;
                r = hue2rgb(p, q, h + 1/3);
                g = hue2rgb(p, q, h);
                b = hue2rgb(p, q, h - 1/3);
            }
            return { r: Math.round(r * 255), g: Math.round(g * 255), b: Math.round(b * 255) };
        }

        function hexToHsl(hex) {
            const rgb = hexToRgb(hex);
            if (!rgb) return { h: 0, s: 0, l: 0 };
            const r = rgb.r / 255;
            const g = rgb.g / 255;
            const b = rgb.b / 255;
            const max = Math.max(r, g, b), min = Math.min(r, g, b);
            let h = 0, s = 0, l = (max + min) / 2;
            if (max !== min) {
                const d = max - min;
                s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
                switch (max) {
                    case r: h = (g - b) / d + (g < b ? 6 : 0); break;
                    case g: h = (b - r) / d + 2; break;
                    case b: h = (r - g) / d + 4; break;
                }
                h /= 6;
            }
            return { h: Math.round(h * 360), s: Math.round(s * 100), l: Math.round(l * 100) };
        }

        function hslToHex(h, s, l) {
            const rgb = hslToRgb(h, s, l);
            return rgbToHex(rgb.r, rgb.g, rgb.b);
        }

        function getLuminance(r, g, b) {
            const a = [r, g, b].map(v => {
                v /= 255;
                return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
            });
            return a[0] * 0.2126 + a[1] * 0.7152 + a[2] * 0.0722;
        }

        function calculateWcagRatio(hex1, hex2) {
            const rgb1 = hexToRgb(hex1);
            const rgb2 = hexToRgb(hex2);
            const l1 = getLuminance(rgb1.r, rgb1.g, rgb1.b);
            const l2 = getLuminance(rgb2.r, rgb2.g, rgb2.b);
            const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
            return Math.round(ratio * 10) / 10;
        }

        // Palette Harmonizer Modal Handlers
        const harmonizerModal = document.getElementById('paletteHarmonizerModal');
        if (harmonizerModal) {
            harmonizerModal.addEventListener('show.bs.modal', function () {
                const container = document.getElementById('harmonizerSchemesList');
                if (!container) return;
                const p = inputPrimary ? inputPrimary.value : '#ff3d61';
                const rgb = hexToRgb(p);
                const hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
                const dbg = inputDarkBg ? inputDarkBg.value : '#070913';

                const seedDot = document.getElementById('harmonizerSeedDot');
                const seedHex = document.getElementById('harmonizerSeedHex');
                if (seedDot) seedDot.style.backgroundColor = p;
                if (seedHex) seedHex.textContent = p;

                const schemes = [
                    {
                        title: 'Triadic Dynamic Energy',
                        desc: '120° / 240° chromatic split providing rich vibrant contrast suitable for modern gaming and creative platforms.',
                        s: hslToHex((hsl.h + 120) % 360, Math.min(100, hsl.s + 5), hsl.l),
                        a: hslToHex((hsl.h + 240) % 360, Math.min(100, hsl.s + 5), hsl.l)
                    },
                    {
                        title: 'Analogous Serene Gradient',
                        desc: '±30° neighboring hues creating smooth, cohesive, organic transitions typical of Apple and Stripe brand styling.',
                        s: hslToHex((hsl.h + 330) % 360, hsl.s, hsl.l),
                        a: hslToHex((hsl.h + 30) % 360, hsl.s, hsl.l)
                    },
                    {
                        title: 'Complementary Neon Punch',
                        desc: '180° opposing hue paired with a 150° split angle for high-visibility interactive CTA buttons and accents.',
                        s: hslToHex((hsl.h + 180) % 360, hsl.s, hsl.l),
                        a: hslToHex((hsl.h + 150) % 360, Math.min(100, hsl.s + 10), Math.min(85, hsl.l + 5))
                    },
                    {
                        title: 'Split-Complementary Balance',
                        desc: '150° and 210° offset hues providing multi-point color depth with minimal visual tension.',
                        s: hslToHex((hsl.h + 150) % 360, hsl.s, hsl.l),
                        a: hslToHex((hsl.h + 210) % 360, hsl.s, hsl.l)
                    },
                    {
                        title: 'Monochromatic Tonal Ramp',
                        desc: 'Precision luminance modulation keeping the exact hue family while establishing clear structural hierarchy.',
                        s: hslToHex(hsl.h, Math.max(20, hsl.s - 15), Math.min(85, hsl.l + 18)),
                        a: hslToHex(hsl.h, Math.min(100, hsl.s + 15), Math.max(25, hsl.l - 18))
                    }
                ];

                container.innerHTML = '';
                schemes.forEach(sc => {
                    const ratioP = calculateWcagRatio(p, dbg);
                    const card = document.createElement('div');
                    card.className = 'p-3 rounded-3 border bg-body d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3 shadow-sm';
                    card.innerHTML = `
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <h6 class="fw-bold mb-0">${sc.title}</h6>
                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-0.5" style="font-size: 0.68rem;">
                                    WCAG ${ratioP}:1
                                </span>
                            </div>
                            <p class="small text-muted mb-2" style="font-size: 0.78rem;">${sc.desc}</p>
                            <div class="d-flex align-items-center gap-2">
                                <div class="d-flex align-items-center gap-1">
                                    <span class="d-inline-block rounded-circle border" style="width: 16px; height: 16px; background: ${p};" title="Primary: ${p}"></span>
                                    <span class="font-monospace small text-muted" style="font-size: 0.72rem;">${p}</span>
                                </div>
                                <span class="text-muted small">&rarr;</span>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="d-inline-block rounded-circle border" style="width: 16px; height: 16px; background: ${sc.s};" title="Harmonized Secondary: ${sc.s}"></span>
                                    <span class="font-monospace small text-muted" style="font-size: 0.72rem;">${sc.s}</span>
                                </div>
                                <span class="text-muted small">&rarr;</span>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="d-inline-block rounded-circle border" style="width: 16px; height: 16px; background: ${sc.a};" title="Harmonized Accent: ${sc.a}"></span>
                                    <span class="font-monospace small text-muted" style="font-size: 0.72rem;">${sc.a}</span>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold flex-shrink-0 apply-harmony-btn" data-s="${sc.s}" data-a="${sc.a}">
                            <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Apply Harmony
                        </button>
                    `;
                    container.appendChild(card);
                });

                container.querySelectorAll('.apply-harmony-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const s = this.dataset.s;
                        const a = this.dataset.a;
                        if (inputSecondary && s) inputSecondary.value = s;
                        if (inputAccent && a) inputAccent.value = a;
                        onManualColorChange();
                        const modalEl = document.getElementById('paletteHarmonizerModal');
                        if (modalEl && window.bootstrap?.Modal) {
                            const instance = window.bootstrap.Modal.getInstance(modalEl);
                            if (instance) instance.hide();
                        }
                        if (typeof alertToast === 'function') alertToast('Harmonized palette applied successfully!', 'success');
                    });
                });
            });
        }

        // WCAG 2.2 Deep Compliance Matrix Modal Handlers
        const wcagModal = document.getElementById('wcagComplianceModal');
        function populateWcagMatrix() {
            const tbody = document.getElementById('wcagMatrixTableBody');
            if (!tbody) return;
            const p = inputPrimary ? inputPrimary.value : '#ff3d61';
            const s = inputSecondary ? inputSecondary.value : '#ff8b5c';
            const a = inputAccent ? inputAccent.value : '#18c8d8';
            const dbg = inputDarkBg ? inputDarkBg.value : '#070913';
            const dsurf = inputDarkSurface ? inputDarkSurface.value : '#0e1322';
            const lbg = inputLightBg ? inputLightBg.value : '#f8fafc';

            const pairings = [
                { label: 'Primary Brand on Dark Canvas', fg: p, bg: dbg, note: 'Nav links, active filters, icons' },
                { label: 'Primary Brand on Light Canvas', fg: p, bg: lbg, note: 'Light mode navigation, active chips' },
                { label: 'Primary Brand on Dark Surface', fg: p, bg: dsurf, note: 'Card badges, player header buttons' },
                { label: 'White Text on Primary CTA Button', fg: '#ffffff', bg: p, note: 'Subscribe, action triggers' },
                { label: 'Secondary Accent on Dark Canvas', fg: s, bg: dbg, note: 'Gradient flows, secondary chips' },
                { label: 'Tertiary Accent Glow on Canvas', fg: a, bg: dbg, note: 'Telemetry HUD, verified badges' }
            ];

            tbody.innerHTML = '';
            pairings.forEach(item => {
                const ratio = calculateWcagRatio(item.fg, item.bg);
                let badgeClass = 'bg-danger';
                let rating = 'Fail (< 3:1)';
                let status = '❌ Needs Adjustment';
                if (ratio >= 7.0) {
                    badgeClass = 'bg-success';
                    rating = 'AAA (≥ 7:1)';
                    status = '✅ Certified AAA';
                } else if (ratio >= 4.5) {
                    badgeClass = 'bg-info text-dark';
                    rating = 'AA (≥ 4.5:1)';
                    status = '✅ Certified AA';
                } else if (ratio >= 3.0) {
                    badgeClass = 'bg-warning text-dark';
                    rating = 'Large Text (≥ 3:1)';
                    status = '⚠️ Headings Only';
                }

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>
                        <div class="fw-semibold">${item.label}</div>
                        <div class="text-muted small" style="font-size: 0.72rem;">${item.note}</div>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-1.5">
                            <span class="d-inline-block rounded-circle border" style="width: 14px; height: 14px; background: ${item.fg};"></span>
                            <span class="font-monospace small">${item.fg}</span>
                        </div>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-1.5">
                            <span class="d-inline-block rounded-circle border" style="width: 14px; height: 14px; background: ${item.bg};"></span>
                            <span class="font-monospace small">${item.bg}</span>
                        </div>
                    </td>
                    <td class="text-center font-monospace fw-bold">${ratio}:1</td>
                    <td class="text-center"><span class="badge ${badgeClass} rounded-pill px-2.5 py-1 small">${rating}</span></td>
                    <td class="text-center small">${status}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        if (wcagModal) {
            wcagModal.addEventListener('show.bs.modal', populateWcagMatrix);
        }

        const btnAutoOptWcag = document.getElementById('btnAutoOptimizeWcag');
        if (btnAutoOptWcag) {
            btnAutoOptWcag.addEventListener('click', function () {
                const isDark = (liveStudioStageCanvasMode === 'dark');
                const bgHex = isDark ? (inputDarkBg ? inputDarkBg.value : '#070913') : (inputLightBg ? inputLightBg.value : '#f8fafc');
                const p = inputPrimary ? inputPrimary.value : '#ff3d61';
                const rgb = hexToRgb(p);
                const hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
                
                let currentRatio = calculateWcagRatio(p, bgHex);
                if (currentRatio >= 7.0) {
                    if (typeof alertToast === 'function') alertToast('Color already meets WCAG AAA standards (' + currentRatio + ':1)!', 'success');
                    return;
                }
                
                const step = isDark ? 2 : -2;
                let newL = hsl.l;
                let newHex = p;
                for (let i = 0; i < 40; i++) {
                    newL += step;
                    if (newL <= 5 || newL >= 95) break;
                    newHex = hslToHex(hsl.h, hsl.s, newL);
                    if (calculateWcagRatio(newHex, bgHex) >= 7.1) {
                        break;
                    }
                }
                
                if (inputPrimary) inputPrimary.value = newHex;
                onManualColorChange();
                populateWcagMatrix();
                if (typeof alertToast === 'function') alertToast('Optimized primary color to ' + newHex + ' (WCAG AAA certified)!', 'success');
            });
        }

        // Multi-Format Theme Spec Hub Handlers
        const themeJsonModal = document.getElementById('themeJsonSpecModal');
        function generateThemeSpecs() {
            const selected = presetSelect.options[presetSelect.selectedIndex];
            const p = inputPrimary ? inputPrimary.value : '#ff3d61';
            const s = inputSecondary ? inputSecondary.value : '#ff8b5c';
            const a = inputAccent ? inputAccent.value : '#18c8d8';
            const dbg = inputDarkBg ? inputDarkBg.value : '#070913';
            const dsurf = inputDarkSurface ? inputDarkSurface.value : '#0e1322';
            const lbg = inputLightBg ? inputLightBg.value : '#f8fafc';
            const lsurf = inputLightSurface ? inputLightSurface.value : '#ffffff';
            const font = document.querySelector('select[name="font_family"]')?.value || 'modern';
            const typo = document.querySelector('select[name="typography_pairing"]')?.value || 'modern_tech';
            const homeLayout = document.querySelector('select[name="home_layout_mode"]')?.value || 'grid_modern';
            const cardLayout = document.querySelector('select[name="video_card_layout_style"]')?.value || 'card_standard';
            const motion = document.querySelector('select[name="motion_engine_level"]')?.value || 'smooth_fluid';

            const spec = {
                preset_id: presetSelect.value,
                label: selected?.dataset.label || selected?.text || 'Custom Theme',
                category: selected?.dataset.category || 'Custom',
                description: selected?.dataset.description || '',
                primary: p,
                secondary: s,
                accent: a,
                dark_bg_color: dbg,
                dark_surface_color: dsurf,
                light_bg_color: lbg,
                light_surface_color: lsurf,
                surface_style: document.querySelector('select[name="surface_style"]')?.value || 'glass',
                radius_scale: document.querySelector('select[name="radius_scale"]')?.value || 'balanced',
                font_family: font,
                header_style: document.querySelector('select[name="header_style"]')?.value || 'glass_blur',
                category_card_style: document.querySelector('select[name="category_card_style"]')?.value || 'gradient_mesh',
                creator_avatar_ring_style: document.querySelector('select[name="creator_avatar_ring_style"]')?.value || 'neon_pulse',
                video_card_duration_style: document.querySelector('select[name="video_card_duration_style"]')?.value || 'pill',
                watch_ambient_glow_intensity: document.querySelector('select[name="watch_ambient_glow_intensity"]')?.value || 'ambient',
                scrollbar_style: document.querySelector('select[name="scrollbar_style"]')?.value || 'subtle',
                home_layout_mode: homeLayout,
                hero_layout_mode: document.querySelector('select[name="hero_layout_mode"]')?.value || 'classic_banner',
                back_to_top_style: document.querySelector('select[name="back_to_top_style"]')?.value || 'floating_pill',
                watch_layout_mode: document.querySelector('select[name="watch_layout_mode"]')?.value || 'classic_two_column',
                video_card_layout_style: cardLayout,
                background_atmosphere_effect: document.querySelector('select[name="background_atmosphere_effect"]')?.value || 'clean_none',
                typography_pairing: typo,
                video_player_cinema_filter: document.querySelector('select[name="video_player_cinema_filter"]')?.value || 'none',
                modal_presentation_style: document.querySelector('select[name="modal_presentation_style"]')?.value || 'modal_glassmorphic_center',
                motion_engine_level: motion,
                interactive_3d_card_tilt_enabled: document.querySelector('input[name="interactive_3d_card_tilt_enabled"]')?.checked ? '1' : '0',
                cinema_ambient_light_diffusion_enabled: document.querySelector('input[name="cinema_ambient_light_diffusion_enabled"]')?.checked ? '1' : '0',
                card_spotlight_glow_enabled: document.querySelector('input[name="card_spotlight_glow_enabled"]')?.checked ? '1' : '0',
                border_beam_glow_enabled: document.querySelector('input[name="border_beam_glow_enabled"]')?.checked ? '1' : '0',
                border_beam_speed: document.querySelector('select[name="border_beam_speed"]')?.value || 'normal',
                surface_noise_grain_enabled: document.querySelector('input[name="surface_noise_grain_enabled"]')?.checked ? '1' : '0',
                mobile_video_columns: document.querySelector('select[name="mobile_video_columns"]')?.value || '2',
                mobile_video_card_density: document.querySelector('select[name="mobile_video_card_density"]')?.value || 'compact',
                mobile_bottom_nav_enabled: document.querySelector('input[name="mobile_bottom_nav_enabled"]')?.checked ? '1' : '0',
                mobile_tap_preview_enabled: document.querySelector('input[name="mobile_tap_preview_enabled"]')?.checked ? '1' : '0',
                hero_slider_autoplay_enabled: document.querySelector('input[name="hero_slider_autoplay_enabled"]')?.checked ? '1' : '0',
                hero_slider_autoplay_seconds: document.querySelector('input[name="hero_slider_autoplay_seconds"]')?.value || '5',
                video_card_title_lines: document.querySelector('select[name="video_card_title_lines"]')?.value || '2'
            };

            // 1. JSON
            const jsonArea = document.getElementById('themeJsonExportTextarea');
            if (jsonArea) jsonArea.value = JSON.stringify(spec, null, 2);

            // 2. CSS Custom Properties
            const cssArea = document.getElementById('themeCssExportTextarea');
            if (cssArea) {
                cssArea.value = `:root {
  /* Brand Tokens */
  --avd-brand: ${p};
  --avd-brand-secondary: ${s};
  --avd-accent: ${a};

  /* Surfaces & Canvases */
  --avd-dark-bg: ${dbg};
  --avd-dark-surface: ${dsurf};
  --avd-light-bg: ${lbg};
  --avd-light-surface: ${lsurf};

  /* Typography & Layout Hierarchy */
  --avd-font-family: '${font}', sans-serif;
  --avd-typography-pairing: '${typo}';
  --avd-layout-home: '${homeLayout}';
  --avd-card-style: '${cardLayout}';
  --avd-motion-engine: '${motion}';
}

[data-bs-theme="dark"] {
  --avd-bg: var(--avd-dark-bg);
  --avd-surface: var(--avd-dark-surface);
  --bs-body-bg: var(--avd-dark-bg);
}

[data-bs-theme="light"] {
  --avd-bg: var(--avd-light-bg);
  --avd-surface: var(--avd-light-surface);
  --bs-body-bg: var(--avd-light-bg);
}`;
            }

            // 3. Tailwind v4 @theme
            const twArea = document.getElementById('themeTailwindExportTextarea');
            if (twArea) {
                twArea.value = `@theme {
  --color-brand: ${p};
  --color-brand-secondary: ${s};
  --color-accent: ${a};
  --color-canvas-dark: ${dbg};
  --color-surface-dark: ${dsurf};
  --color-canvas-light: ${lbg};
  --color-surface-light: ${lsurf};
  --font-display: '${font}', sans-serif;
}`;
            }
        }

        if (themeJsonModal) {
            themeJsonModal.addEventListener('show.bs.modal', generateThemeSpecs);

            const copyJsonBtn = document.getElementById('copyThemeJsonBtn');
            if (copyJsonBtn) {
                copyJsonBtn.addEventListener('click', function () {
                    const textarea = document.getElementById('themeJsonExportTextarea');
                    if (textarea && textarea.value) {
                        navigator.clipboard.writeText(textarea.value).then(() => {
                            if (typeof alertToast === 'function') alertToast('Theme JSON copied to clipboard!', 'success');
                        });
                    }
                });
            }

            const copyCssBtn = document.getElementById('copyThemeCssBtn');
            if (copyCssBtn) {
                copyCssBtn.addEventListener('click', function () {
                    const textarea = document.getElementById('themeCssExportTextarea');
                    if (textarea && textarea.value) {
                        navigator.clipboard.writeText(textarea.value).then(() => {
                            if (typeof alertToast === 'function') alertToast('CSS Custom Properties copied to clipboard!', 'info');
                        });
                    }
                });
            }

            const copyTwBtn = document.getElementById('copyThemeTailwindBtn');
            if (copyTwBtn) {
                copyTwBtn.addEventListener('click', function () {
                    const textarea = document.getElementById('themeTailwindExportTextarea');
                    if (textarea && textarea.value) {
                        navigator.clipboard.writeText(textarea.value).then(() => {
                            if (typeof alertToast === 'function') alertToast('Tailwind v4 tokens copied to clipboard!', 'success');
                        });
                    }
                });
            }

            const applyJsonBtn = document.getElementById('applyImportedThemeJsonBtn');
            if (applyJsonBtn) {
                applyJsonBtn.addEventListener('click', function () {
                    const importArea = document.getElementById('themeJsonImportTextarea');
                    if (!importArea || !importArea.value.trim()) {
                        if (typeof alertToast === 'function') alertToast('Please paste a valid Theme JSON first.', 'warning');
                        return;
                    }
                    try {
                        const parsed = JSON.parse(importArea.value);
                        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
                            throw new Error('Theme JSON must be an object.');
                        }

                        const importedColorKeys = [
                            'primary',
                            'secondary',
                            'accent',
                            'dark_bg_color',
                            'dark_surface_color',
                            'light_bg_color',
                            'light_surface_color',
                        ];
                        importedColorKeys.forEach((key) => {
                            if (parsed[key] !== undefined && !/^#[0-9a-f]{6}$/i.test(String(parsed[key]))) {
                                throw new Error(`${key} must be a six-digit hexadecimal color.`);
                            }
                        });

                        if (parsed.primary && inputPrimary) inputPrimary.value = parsed.primary;
                        if (parsed.secondary && inputSecondary) inputSecondary.value = parsed.secondary;
                        if (parsed.accent && inputAccent) inputAccent.value = parsed.accent;
                        if (parsed.dark_bg_color && inputDarkBg) inputDarkBg.value = parsed.dark_bg_color;
                        if (parsed.dark_surface_color && inputDarkSurface) inputDarkSurface.value = parsed.dark_surface_color;
                        if (parsed.light_bg_color && inputLightBg) inputLightBg.value = parsed.light_bg_color;
                        if (parsed.light_surface_color && inputLightSurface) inputLightSurface.value = parsed.light_surface_color;

                        const setImportedSelect = (name, key) => {
                            if (parsed[key] === undefined || parsed[key] === null) return;
                            const select = document.querySelector(`select[name="${name}"]`);
                            const value = String(parsed[key]);
                            if (select && Array.from(select.options).some(option => option.value === value)) {
                                select.value = value;
                            }
                        };
                        const setImportedCheck = (name, key) => {
                            if (parsed[key] === undefined || parsed[key] === null) return;
                            const checkbox = document.querySelector(`input[name="${name}"]`);
                            if (checkbox) checkbox.checked = parsed[key] === '1' || parsed[key] === 1 || parsed[key] === true;
                        };

                        [
                            ['theme', 'theme'],
                            ['font_family', 'font_family'],
                            ['header_style', 'header_style'],
                            ['category_card_style', 'category_card_style'],
                            ['creator_avatar_ring_style', 'creator_avatar_ring_style'],
                            ['video_card_duration_style', 'video_card_duration_style'],
                            ['watch_ambient_glow_intensity', 'watch_ambient_glow_intensity'],
                            ['scrollbar_style', 'scrollbar_style'],
                            ['hero_layout_mode', 'hero_layout_mode'],
                            ['back_to_top_style', 'back_to_top_style'],
                            ['typography_pairing', 'typography_pairing'],
                            ['modal_presentation_style', 'modal_presentation_style'],
                            ['motion_engine_level', 'motion_engine_level'],
                            ['mobile_video_columns', 'mobile_video_columns'],
                            ['mobile_video_card_density', 'mobile_video_card_density'],
                            ['video_card_title_lines', 'video_card_title_lines'],
                        ].forEach(([name, key]) => setImportedSelect(name, key));

                        [
                            ['theme_oled_black_enabled', 'theme_oled_black_enabled'],
                            ['interactive_3d_card_tilt_enabled', 'interactive_3d_card_tilt_enabled'],
                            ['cinema_ambient_light_diffusion_enabled', 'cinema_ambient_light_diffusion_enabled'],
                            ['mobile_bottom_nav_enabled', 'mobile_bottom_nav_enabled'],
                            ['mobile_tap_preview_enabled', 'mobile_tap_preview_enabled'],
                            ['hero_slider_autoplay_enabled', 'hero_slider_autoplay_enabled'],
                            ['ui_audio_feedback_enabled', 'ui_audio_feedback_enabled'],
                            ['video_player_ambilight_glow_enabled', 'video_player_ambilight_glow_enabled'],
                            ['surface_glassmorphism_enabled', 'surface_glassmorphism_enabled'],
                            ['button_liquid_shimmer_enabled', 'button_liquid_shimmer_enabled'],
                            ['cursor_glow_trail_enabled', 'cursor_glow_trail_enabled'],
                        ].forEach(([name, key]) => setImportedCheck(name, key));

                        if (parsed.hero_slider_autoplay_seconds !== undefined) {
                            const seconds = document.querySelector('input[name="hero_slider_autoplay_seconds"]');
                            if (seconds) seconds.value = String(parsed.hero_slider_autoplay_seconds);
                        }

                        if (parsed.surface_style) {
                            const sel = document.querySelector('select[name="surface_style"]');
                            if (sel) sel.value = parsed.surface_style;
                        }
                        if (parsed.radius_scale) {
                            const sel = document.querySelector('select[name="radius_scale"]');
                            if (sel) sel.value = parsed.radius_scale;
                        }
                        if (parsed.home_layout_mode) {
                            const sel = document.querySelector('select[name="home_layout_mode"]');
                            if (sel) sel.value = parsed.home_layout_mode;
                        }
                        if (parsed.watch_layout_mode) {
                            const sel = document.querySelector('select[name="watch_layout_mode"]');
                            if (sel) sel.value = parsed.watch_layout_mode;
                        }
                        if (parsed.video_card_layout_style) {
                            const sel = document.querySelector('select[name="video_card_layout_style"]');
                            if (sel) sel.value = parsed.video_card_layout_style;
                        }
                        if (parsed.background_atmosphere_effect) {
                            const sel = document.querySelector('select[name="background_atmosphere_effect"]');
                            if (sel) sel.value = parsed.background_atmosphere_effect;
                        }
                        if (parsed.video_player_cinema_filter) {
                            const sel = document.querySelector('select[name="video_player_cinema_filter"]');
                            if (sel) sel.value = parsed.video_player_cinema_filter;
                        }
                        if (parsed.border_beam_speed) {
                            const sel = document.querySelector('select[name="border_beam_speed"]');
                            if (sel) sel.value = parsed.border_beam_speed;
                        }
                        if (parsed.card_spotlight_glow_enabled !== undefined) {
                            const chk = document.querySelector('input[name="card_spotlight_glow_enabled"]');
                            if (chk) chk.checked = parsed.card_spotlight_glow_enabled === '1' || parsed.card_spotlight_glow_enabled === true;
                        }
                        if (parsed.border_beam_glow_enabled !== undefined) {
                            const chk = document.querySelector('input[name="border_beam_glow_enabled"]');
                            if (chk) chk.checked = parsed.border_beam_glow_enabled === '1' || parsed.border_beam_glow_enabled === true;
                        }
                        if (parsed.surface_noise_grain_enabled !== undefined) {
                            const chk = document.querySelector('input[name="surface_noise_grain_enabled"]');
                            if (chk) chk.checked = parsed.surface_noise_grain_enabled === '1' || parsed.surface_noise_grain_enabled === true;
                        }

                        onManualColorChange();
                        // Imported specs are custom tokens, so persist the edited
                        // appearance form without allowing the selected preset to
                        // overwrite them. The existing autosave listener receives
                        // one change event and submits the complete schema payload.
                        const appearanceForm = document.querySelector('form.avd-settings-panel[action*="/settings/update/appearance"]');
                        const applyPresetCheck = document.getElementById('applyThemePresetCheck');
                        if (applyPresetCheck) applyPresetCheck.checked = false;
                        const saveTrigger = inputPrimary || appearanceForm?.querySelector('[name="theme_preset"]');
                        if (saveTrigger) {
                            saveTrigger.dispatchEvent(new Event('change', { bubbles: true }));
                        } else if (appearanceForm) {
                            appearanceForm.requestSubmit();
                        }

                        const titleEl = document.getElementById('activeThemeTitle');
                        if (titleEl) titleEl.textContent = (parsed.label || 'Imported Custom Theme') + ' (Imported)';

                        const modalInstance = bootstrap.Modal.getInstance(themeJsonModal);
                        if (modalInstance) modalInstance.hide();

                        if (typeof alertToast === 'function') {
                            alertToast('Custom Theme JSON applied and queued for save.', 'success');
                        }
                    } catch (e) {
                        console.error('Invalid JSON:', e);
                        if (typeof alertToast === 'function') alertToast('Invalid JSON format: ' + e.message, 'danger');
                    }
                });
            }
        }

        function onManualColorChange() {
            const applyCheck = document.getElementById('applyThemePresetCheck');
            if (applyCheck) applyCheck.checked = false;

            const titleEl = document.getElementById('activeThemeTitle');
            if (titleEl && !titleEl.textContent.includes('(Custom)')) {
                titleEl.textContent += ' (Custom)';
            }

            const p = inputPrimary ? inputPrimary.value : '#ff3d61';
            const s = inputSecondary ? inputSecondary.value : '#ff8b5c';
            const a = inputAccent ? inputAccent.value : '#18c8d8';
            const dbg = inputDarkBg ? inputDarkBg.value : '#070913';
            const dsurf = inputDarkSurface ? inputDarkSurface.value : '#0e1322';
            const lbg = inputLightBg ? inputLightBg.value : '#f8fafc';
            const lsurf = inputLightSurface ? inputLightSurface.value : '#ffffff';

            const dotP = document.getElementById('activeDotPrimary');
            const dotS = document.getElementById('activeDotSecondary');
            const dotA = document.getElementById('activeDotAccent');
            const dotDarkBg = document.getElementById('activeDotDarkBg');
            const dotDarkSurface = document.getElementById('activeDotDarkSurface');
            const dotLightBg = document.getElementById('activeDotLightBg');
            const dotLightSurface = document.getElementById('activeDotLightSurface');

            if (dotP) { dotP.style.backgroundColor = p; dotP.title = 'Custom Primary: ' + p; dotP.dataset.hex = p; }
            if (dotS) { dotS.style.backgroundColor = s; dotS.title = 'Custom Secondary: ' + s; dotS.dataset.hex = s; }
            if (dotA) { dotA.style.backgroundColor = a; dotA.title = 'Custom Accent: ' + a; dotA.dataset.hex = a; }
            if (dotDarkBg) { dotDarkBg.style.backgroundColor = dbg; dotDarkBg.title = 'Custom Dark BG: ' + dbg; dotDarkBg.dataset.hex = dbg; }
            if (dotDarkSurface) { dotDarkSurface.style.backgroundColor = dsurf; dotDarkSurface.title = 'Custom Dark Surface: ' + dsurf; dotDarkSurface.dataset.hex = dsurf; }
            if (dotLightBg) { dotLightBg.style.backgroundColor = lbg; dotLightBg.title = 'Custom Light BG: ' + lbg; dotLightBg.dataset.hex = lbg; }
            if (dotLightSurface) { dotLightSurface.style.backgroundColor = lsurf; dotLightSurface.title = 'Custom Light Surface: ' + lsurf; dotLightSurface.dataset.hex = lsurf; }

            document.documentElement.style.setProperty('--avd-brand', p);
            document.documentElement.style.setProperty('--bs-primary', p);
            document.documentElement.style.setProperty('--avd-brand-2', s);
            document.documentElement.style.setProperty('--avd-brand-secondary', s);
            document.documentElement.style.setProperty('--avd-cyan', a);
            document.documentElement.style.setProperty('--avd-accent', a);
            document.documentElement.style.setProperty('--avd-dark-bg', dbg);
            document.documentElement.style.setProperty('--avd-dark-surface', dsurf);
            document.documentElement.style.setProperty('--avd-light-bg', lbg);
            document.documentElement.style.setProperty('--avd-light-surface', lsurf);
            document.documentElement.style.setProperty('--avd-bg', dbg);
            document.documentElement.style.setProperty('--avd-surface', dsurf);

            try { updateLiveStudioStage(); } catch (e) {}
        }

        if (inputPrimary) inputPrimary.addEventListener('input', onManualColorChange);
        if (inputSecondary) inputSecondary.addEventListener('input', onManualColorChange);
        if (inputAccent) inputAccent.addEventListener('input', onManualColorChange);
        if (inputDarkBg) inputDarkBg.addEventListener('input', onManualColorChange);
        if (inputDarkSurface) inputDarkSurface.addEventListener('input', onManualColorChange);
        if (inputLightBg) inputLightBg.addEventListener('input', onManualColorChange);
        if (inputLightSurface) inputLightSurface.addEventListener('input', onManualColorChange);

        // 7 Studio Interactive Color Pickers Two-Way Binding
        function bindStudioColorPicker(pickerId, targetInput, cssVar, rgbVar) {
            const picker = document.getElementById(pickerId);
            if (!picker || !targetInput) return;
            picker.addEventListener('input', function () {
                const val = this.value;
                targetInput.value = val;
                if (cssVar) {
                    document.documentElement.style.setProperty(cssVar, val);
                    if (rgbVar) {
                        const rgb = hexToRgb(val);
                        if (rgb) document.documentElement.style.setProperty(rgbVar, `${rgb.r}, ${rgb.g}, ${rgb.b}`);
                    }
                }
                onManualColorChange();
            });
        }
        bindStudioColorPicker('pickerStudioPrimary', inputPrimary, '--avd-brand', '--avd-brand-rgb');
        bindStudioColorPicker('pickerStudioSecondary', inputSecondary, '--avd-brand-2', '--avd-brand-2-rgb');
        bindStudioColorPicker('pickerStudioAccent', inputAccent, '--avd-cyan', '--avd-cyan-rgb');
        bindStudioColorPicker('pickerStudioDarkBg', inputDarkBg, '--avd-dark-bg', null);
        bindStudioColorPicker('pickerStudioDarkSurface', inputDarkSurface, '--avd-dark-surface', null);
        bindStudioColorPicker('pickerStudioLightBg', inputLightBg, '--avd-light-bg', null);
        bindStudioColorPicker('pickerStudioLightSurface', inputLightSurface, '--avd-light-surface', null);

        // Algorithmic Intelligent Palette Generator (⚡ Magic Palette)
        const btnMagic = document.getElementById('btnStudioMagicPalette');
        if (btnMagic) {
            btnMagic.addEventListener('click', function () {
                const goldenAngle = 137.508;
                const baseHue = Math.floor(Math.random() * 360);
                const secHue = Math.round((baseHue + 40) % 360);
                const accHue = Math.round((baseHue + goldenAngle) % 360);

                const newPrimary = hslToHex(baseHue, 92, 58);
                const newSecondary = hslToHex(secHue, 86, 54);
                const newAccent = hslToHex(accHue, 95, 52);
                const newDarkBg = hslToHex(baseHue, 22, 5);
                const newDarkSurface = hslToHex(baseHue, 20, 10);
                const newLightBg = hslToHex(baseHue, 15, 97);
                const newLightSurface = '#ffffff';

                if (inputPrimary) inputPrimary.value = newPrimary;
                if (inputSecondary) inputSecondary.value = newSecondary;
                if (inputAccent) inputAccent.value = newAccent;
                if (inputDarkBg) inputDarkBg.value = newDarkBg;
                if (inputDarkSurface) inputDarkSurface.value = newDarkSurface;
                if (inputLightBg) inputLightBg.value = newLightBg;
                if (inputLightSurface) inputLightSurface.value = newLightSurface;

                onManualColorChange();
                if (typeof window.avdPlaySound === 'function') window.avdPlaySound('chime');
                if (typeof alertToast === 'function') {
                    alertToast('⚡ Generated harmonious golden-ratio color palette!', 'success');
                }
            });
        }

        // Reset Custom Colors to Active Preset Defaults
        const btnResetCustom = document.getElementById('btnStudioResetCustomColors');
        if (btnResetCustom) {
            btnResetCustom.addEventListener('click', function () {
                const selected = presetSelect.options[presetSelect.selectedIndex];
                if (!selected) return;
                const primary = selected.dataset.primary || '#ff3d61';
                const secondary = selected.dataset.secondary || '#ff8b5c';
                const accent = selected.dataset.accent || '#18c8d8';
                const darkBg = selected.dataset.darkBg || '#070913';
                const darkSurface = selected.dataset.darkSurface || '#0e1322';
                const lightBg = selected.dataset.lightBg || '#f8fafc';
                const lightSurface = selected.dataset.lightSurface || '#ffffff';

                if (inputPrimary) inputPrimary.value = primary;
                if (inputSecondary) inputSecondary.value = secondary;
                if (inputAccent) inputAccent.value = accent;
                if (inputDarkBg) inputDarkBg.value = darkBg;
                if (inputDarkSurface) inputDarkSurface.value = darkSurface;
                if (inputLightBg) inputLightBg.value = lightBg;
                if (inputLightSurface) inputLightSurface.value = lightSurface;

                const applyCheck = document.getElementById('applyThemePresetCheck');
                if (applyCheck) applyCheck.checked = true;

                const titleEl = document.getElementById('activeThemeTitle');
                if (titleEl) {
                    titleEl.textContent = (selected.dataset.label || selected.text).replace(' (Custom)', '');
                }

                updateActiveThemeStrip(false);
                if (typeof window.avdPlaySound === 'function') window.avdPlaySound('preset_applied');
                if (typeof alertToast === 'function') {
                    alertToast('Reset colors to ' + (selected.dataset.label || 'preset') + ' defaults!', 'success');
                }
            });
        }

        // Quick Harmony: 180° Optical Complementary Accent
        const btnCompAccent = document.getElementById('btnStudioCompAccent');
        if (btnCompAccent) {
            btnCompAccent.addEventListener('click', function () {
                const curPrimary = inputPrimary ? inputPrimary.value : '#ff3d61';
                const hsl = hexToHsl(curPrimary);
                const compHue = (hsl.h + 180) % 360;
                const newAccent = hslToHex(compHue, Math.max(82, hsl.s), 54);
                if (inputAccent) inputAccent.value = newAccent;
                onManualColorChange();
                if (typeof window.avdPlaySound === 'function') window.avdPlaySound('click');
                if (typeof alertToast === 'function') {
                    alertToast('Generated 180° optical complementary accent color (' + newAccent + ')!', 'info');
                }
            });
        }

        // Quick Harmony: Toggle Canvas Depth (OLED Black vs Charcoal)
        const btnDepthShift = document.getElementById('btnStudioInvertContrast');
        if (btnDepthShift) {
            btnDepthShift.addEventListener('click', function () {
                const curDarkBg = inputDarkBg ? inputDarkBg.value : '#070913';
                const curRgb = hexToRgb(curDarkBg);
                const lum = curRgb ? getLuminance(curRgb.r, curRgb.g, curRgb.b) : 0;
                if (lum < 0.015) {
                    if (inputDarkBg) inputDarkBg.value = '#141724';
                    if (inputDarkSurface) inputDarkSurface.value = '#1c2234';
                    if (typeof alertToast === 'function') alertToast('Switched to elevated Charcoal surface depth.', 'info');
                } else {
                    if (inputDarkBg) inputDarkBg.value = '#070913';
                    if (inputDarkSurface) inputDarkSurface.value = '#0e1322';
                    if (typeof alertToast === 'function') alertToast('Switched to deep Obsidian OLED canvas.', 'info');
                }
                onManualColorChange();
                if (typeof window.avdPlaySound === 'function') window.avdPlaySound('thud');
            });
        }

        // Live Preset Diff Comparison in Studio
        const btnComparePreset = document.getElementById('btnStudioComparePreset');
        if (btnComparePreset) {
            btnComparePreset.addEventListener('click', function () {
                const selected = presetSelect.options[presetSelect.selectedIndex];
                if (!selected) return;
                const defPrimary = (selected.dataset.primary || '#ff3d61').toLowerCase();
                const defSecondary = (selected.dataset.secondary || '#ff8b5c').toLowerCase();
                const defAccent = (selected.dataset.accent || '#18c8d8').toLowerCase();
                const defDarkBg = (selected.dataset.darkBg || '#070913').toLowerCase();
                const defDarkSurface = (selected.dataset.darkSurface || '#0e1322').toLowerCase();
                const defLightBg = (selected.dataset.lightBg || '#f8fafc').toLowerCase();
                const defLightSurface = (selected.dataset.lightSurface || '#ffffff').toLowerCase();

                const curPrimary = (inputPrimary ? inputPrimary.value : '').toLowerCase();
                const curSecondary = (inputSecondary ? inputSecondary.value : '').toLowerCase();
                const curAccent = (inputAccent ? inputAccent.value : '').toLowerCase();
                const curDarkBg = (inputDarkBg ? inputDarkBg.value : '').toLowerCase();
                const curDarkSurface = (inputDarkSurface ? inputDarkSurface.value : '').toLowerCase();
                const curLightBg = (inputLightBg ? inputLightBg.value : '').toLowerCase();
                const curLightSurface = (inputLightSurface ? inputLightSurface.value : '').toLowerCase();

                const diffs = [];
                if (curPrimary && curPrimary !== defPrimary) diffs.push(`Pri: ${defPrimary} ➜ ${curPrimary}`);
                if (curSecondary && curSecondary !== defSecondary) diffs.push(`Sec: ${defSecondary} ➜ ${curSecondary}`);
                if (curAccent && curAccent !== defAccent) diffs.push(`Acc: ${defAccent} ➜ ${curAccent}`);
                if (curDarkBg && curDarkBg !== defDarkBg) diffs.push(`D-Bg: ${defDarkBg} ➜ ${curDarkBg}`);
                if (curDarkSurface && curDarkSurface !== defDarkSurface) diffs.push(`D-Surf: ${defDarkSurface} ➜ ${curDarkSurface}`);
                if (curLightBg && curLightBg !== defLightBg) diffs.push(`L-Bg: ${defLightBg} ➜ ${curLightBg}`);
                if (curLightSurface && curLightSurface !== defLightSurface) diffs.push(`L-Surf: ${defLightSurface} ➜ ${curLightSurface}`);

                const presetName = selected.dataset.label || selected.text || 'Active Preset';
                if (typeof window.avdPlaySound === 'function') window.avdPlaySound('click');

                if (diffs.length === 0) {
                    if (typeof alertToast === 'function') {
                        alertToast(`✓ 100% Match: Current colors match "${presetName}" defaults exactly!`, 'success');
                    }
                } else {
                    if (typeof alertToast === 'function') {
                        alertToast(`🔍 Found ${diffs.length} color delta(s) vs "${presetName}":\n` + diffs.slice(0, 3).join(' | ') + (diffs.length > 3 ? '...' : ''), 'info');
                    }
                }
            });
        }

        // Web Audio Micro-Haptics Interactive Test Sound
        const btnTestAudio = document.getElementById('btnTestAudioFeedback');
        if (btnTestAudio) {
            btnTestAudio.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (typeof window.avdPlaySound === 'function') {
                    window.avdPlaySound('preview');
                }
                if (typeof alertToast === 'function') {
                    alertToast('🔊 Synthesized Web Audio Micro-Haptic chime played!', 'info');
                }
            });
        }

        // Direct Export & Import Modals Triggering
        const btnExportTheme = document.getElementById('btnStudioExportTheme');
        if (btnExportTheme) {
            btnExportTheme.addEventListener('click', function () {
                const modalEl = document.getElementById('themeJsonSpecModal');
                if (modalEl) {
                    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    const jsonTabBtn = document.getElementById('tab-json-btn');
                    if (jsonTabBtn) bootstrap.Tab.getOrCreateInstance(jsonTabBtn).show();
                    bsModal.show();
                }
            });
        }
        const btnImportTheme = document.getElementById('btnStudioImportTheme');
        if (btnImportTheme) {
            btnImportTheme.addEventListener('click', function () {
                const modalEl = document.getElementById('themeJsonSpecModal');
                if (modalEl) {
                    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    const jsonTabBtn = document.getElementById('tab-json-btn');
                    if (jsonTabBtn) bootstrap.Tab.getOrCreateInstance(jsonTabBtn).show();
                    bsModal.show();
                    setTimeout(() => {
                        const importArea = document.getElementById('themeJsonImportTextarea');
                        if (importArea) importArea.focus();
                    }, 350);
                }
            });
        }

        // Cinema Player LUT Pills inside Studio
        document.querySelectorAll('#liveStudioCinemaPills button').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('#liveStudioCinemaPills button').forEach(b => {
                    b.classList.remove('active', 'btn-dark');
                    b.classList.add('text-muted');
                });
                this.classList.add('active', 'btn-dark');
                this.classList.remove('text-muted');

                const cinema = this.getAttribute('data-cinema');
                const cinemaSelect = document.querySelector('select[name="video_player_cinema_filter"]');
                if (cinemaSelect) {
                    cinemaSelect.value = cinema;
                }
                const applyCheck = document.getElementById('applyThemePresetCheck');
                if (applyCheck) applyCheck.checked = false;
                updateLiveStudioStage();
            });
        });

        // Spotlight & Border Beam Controls Listeners
        const spotCheck = document.querySelector('input[name="card_spotlight_glow_enabled"]');
        if (spotCheck) {
            spotCheck.addEventListener('change', () => {
                const applyCheck = document.getElementById('applyThemePresetCheck');
                if (applyCheck) applyCheck.checked = false;
                updateLiveStudioStage();
            });
        }
        const beamCheck = document.querySelector('input[name="border_beam_glow_enabled"]');
        if (beamCheck) {
            beamCheck.addEventListener('change', () => {
                const applyCheck = document.getElementById('applyThemePresetCheck');
                if (applyCheck) applyCheck.checked = false;
                updateLiveStudioStage();
            });
        }
        const beamSpeedSelect = document.querySelector('select[name="border_beam_speed"]');
        if (beamSpeedSelect) {
            beamSpeedSelect.addEventListener('change', () => {
                const applyCheck = document.getElementById('applyThemePresetCheck');
                if (applyCheck) applyCheck.checked = false;
                updateLiveStudioStage();
            });
        }

        ['surface_style', 'radius_scale', 'home_layout_mode', 'watch_layout_mode', 'video_card_layout_style', 'font_family', 'video_player_cinema_filter', 'typography_pairing', 'background_atmosphere_effect'].forEach(name => {
            const el = document.querySelector(`select[name="${name}"]`);
            if (el) el.addEventListener('change', () => {
                const applyCheck = document.getElementById('applyThemePresetCheck');
                if (applyCheck) applyCheck.checked = false;
                try { updateLiveStudioStage(); } catch (e) {}
            });
        });

        // 60fps Radial Cursor Spotlight on Studio Preview Containers
        [document.getElementById('liveCardPreviewContainer'), document.getElementById('liveHeaderPreviewContainer')].forEach(el => {
            if (!el) return;
            el.addEventListener('mousemove', (e) => {
                const rect = el.getBoundingClientRect();
                el.style.setProperty('--mouse-x', `${e.clientX - rect.left}px`);
                el.style.setProperty('--mouse-y', `${e.clientY - rect.top}px`);
            });
        });

        // 3D Perspective Tilt on Mock Video Card
        const liveCardEl = document.getElementById('liveCardPreviewContainer');
        if (liveCardEl) {
            liveCardEl.addEventListener('mousemove', (e) => {
                const rect = liveCardEl.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                const centerX = rect.width / 2;
                const centerY = rect.height / 2;
                const rotateX = ((y - centerY) / centerY) * -10;
                const rotateY = ((x - centerX) / centerX) * 10;
                liveCardEl.style.transform = `perspective(800px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) scale3d(1.02, 1.02, 1.02)`;
            });
            liveCardEl.addEventListener('mouseleave', () => {
                liveCardEl.style.transform = 'perspective(800px) rotateX(0deg) rotateY(0deg) scale3d(1, 1, 1)';
            });
        }

        presetSelect.addEventListener('change', () => updateActiveThemeStrip(true));
        updateActiveThemeStrip(false);

        // Search & Category Filter for Modal
        const searchInput = document.getElementById('themeModalSearch');
        const catPills = document.querySelectorAll('.theme-cat-pill');
        const moodPills = document.querySelectorAll('.theme-mood-pill');
        const themeCards = document.querySelectorAll('.theme-card-item');
        const filteredCount = document.getElementById('themeFilteredCount');
        let activeCategory = 'all';
        let activeMood = 'all';

        function filterThemes() {
            const query = (searchInput?.value || '').toLowerCase().trim();
            let visible = 0;

            themeCards.forEach(card => {
                const cardCat = card.dataset.category || '';
                const cardLabel = card.dataset.label || '';
                const cardDesc = card.dataset.desc || '';
                const cardKey = card.dataset.key || '';
                const cardHome = card.dataset.homeLayout || '';
                const cardCard = card.dataset.cardStyle || '';
                const cardTypo = card.dataset.typoPairing || '';
                const cardAtmo = card.dataset.atmosphere || '';
                const cardOled = card.dataset.oled || '0';
                const cardTheme = card.dataset.theme || 'dark';

                const matchesCat = activeCategory === 'all' || cardCat === activeCategory;

                let matchesMood = true;
                if (activeMood === 'oled') {
                    matchesMood = (cardOled === '1') || cardKey.includes('oled') || cardKey.includes('black') || cardKey.includes('midnight') || cardCat.toLowerCase().includes('oled');
                } else if (activeMood === 'light') {
                    matchesMood = (cardTheme === 'light') || cardKey.includes('light') || cardKey.includes('white') || cardKey.includes('paper') || cardCat.toLowerCase().includes('light') || cardCat.toLowerCase().includes('paper');
                } else if (activeMood === 'cyber') {
                    matchesMood = cardCat.toLowerCase().includes('cyber') || cardKey.includes('neon') || cardKey.includes('cyber') || cardTypo.includes('cyber') || cardAtmo.includes('grid');
                } else if (activeMood === 'luxury') {
                    matchesMood = cardCat.toLowerCase().includes('luxury') || cardKey.includes('gold') || cardKey.includes('emerald') || cardKey.includes('sapphire') || cardTypo.includes('luxury') || cardTypo.includes('regal');
                } else if (activeMood === 'anime') {
                    matchesMood = cardCat.toLowerCase().includes('anime') || cardKey.includes('sakura') || cardKey.includes('pastel') || cardKey.includes('kawaii') || cardTypo.includes('pop');
                } else if (activeMood === 'code') {
                    matchesMood = cardCat.toLowerCase().includes('developer') || cardKey.includes('matrix') || cardKey.includes('dracula') || cardKey.includes('monokai') || cardTypo.includes('mono');
                }

                const matchesQuery = query === '' || 
                    cardLabel.includes(query) || 
                    cardDesc.includes(query) || 
                    cardCat.toLowerCase().includes(query) || 
                    cardKey.includes(query) ||
                    cardHome.toLowerCase().includes(query) ||
                    cardCard.toLowerCase().includes(query) ||
                    cardTypo.toLowerCase().includes(query) ||
                    cardAtmo.toLowerCase().includes(query);

                if (matchesCat && matchesMood && matchesQuery) {
                    card.style.display = '';
                    visible++;
                } else {
                    card.style.display = 'none';
                }
            });

            if (filteredCount) filteredCount.textContent = visible;
        }

        const searchClearBtn = document.getElementById('themeModalSearchClear');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                if (searchClearBtn) {
                    searchClearBtn.style.display = this.value.length > 0 ? '' : 'none';
                }
                filterThemes();
            });
        }
        if (searchClearBtn) {
            searchClearBtn.addEventListener('click', function () {
                if (searchInput) {
                    searchInput.value = '';
                    this.style.display = 'none';
                    searchInput.focus();
                }
                filterThemes();
            });
        }

        moodPills.forEach(pill => {
            pill.addEventListener('click', function () {
                moodPills.forEach(p => {
                    p.classList.remove('btn-dark', 'active');
                    p.classList.add('btn-outline-secondary');
                });
                this.classList.remove('btn-outline-secondary');
                this.classList.add('btn-dark', 'active');
                activeMood = this.dataset.mood || 'all';
                filterThemes();
            });
        });

        catPills.forEach(pill => {
            pill.addEventListener('click', function () {
                catPills.forEach(p => {
                    p.classList.remove('btn-primary', 'active');
                    p.classList.add('btn-outline-secondary');
                });
                this.classList.remove('btn-outline-secondary');
                this.classList.add('btn-primary', 'active');
                activeCategory = this.dataset.category;
                filterThemes();
            });
        });

        // Unified Admin Toast Notification
        function alertToast(msg, type = 'success') {
            try {
                let toastContainer = document.getElementById('admin-toast-container');
                if (!toastContainer) {
                    toastContainer = document.createElement('div');
                    toastContainer.id = 'admin-toast-container';
                    toastContainer.className = 'toast-container position-fixed bottom-0 end-0 p-3';
                    toastContainer.style.zIndex = '99999';
                    document.body.appendChild(toastContainer);
                }

                const bgClass = type === 'success' ? 'bg-success text-white' : (type === 'danger' ? 'bg-danger text-white' : 'bg-primary text-white');
                const icon = type === 'success' ? 'fa-circle-check' : (type === 'danger' ? 'fa-circle-xmark' : 'fa-circle-info');

                const toastEl = document.createElement('div');
                toastEl.className = `toast align-items-center ${bgClass} border-0 shadow-lg mb-2`;
                toastEl.setAttribute('role', 'alert');
                toastEl.setAttribute('aria-live', 'assertive');
                toastEl.setAttribute('aria-atomic', 'true');
                toastEl.style.borderRadius = '12px';
                toastEl.innerHTML = `
                    <div class="d-flex p-2">
                        <div class="toast-body d-flex align-items-center gap-2 fw-semibold">
                            <i class="fa-solid ${icon}"></i>
                            <span>${msg}</span>
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close" onclick="this.closest('.toast').remove()"></button>
                    </div>
                `;
                toastContainer.appendChild(toastEl);
                if (window.bootstrap?.Toast) {
                    const bsToast = new bootstrap.Toast(toastEl, { delay: 3500 });
                    bsToast.show();
                } else {
                    toastEl.classList.add('show');
                    setTimeout(() => toastEl.remove(), 3500);
                }
            } catch (e) {
                console.log('Toast:', msg);
            }
        }

        // Instant One-Click Theme Apply Action
        async function applyTheme(presetKey) {
            let structuralAutosavePaused = false;
            const applyBtns = document.querySelectorAll('.apply-theme-btn');
            applyBtns.forEach(b => {
                if (b.dataset.presetId === presetKey) {
                    b.disabled = true;
                    b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Applying...';
                }
            });

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                              document.querySelector('input[name="_token"]')?.value;

            try {
                if (typeof window.avdPrepareStructuralSettingsAction === 'function') {
                    await window.avdPrepareStructuralSettingsAction();
                    structuralAutosavePaused = true;
                }
                const response = await fetch('{{ route('admin.settings.apply-theme') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ theme_preset: presetKey })
                });

                const data = await response.json();

                if (data.ok) {
                    document.querySelectorAll('.theme-card-item').forEach(c => {
                        const isMatch = (c.dataset.key === presetKey);
                        const cardInner = c.querySelector('.theme-item-card');
                        const applyBtn = c.querySelector('.apply-theme-btn, .active-theme-btn');
                        if (isMatch) {
                            if (cardInner) {
                                cardInner.classList.add('border-success');
                                cardInner.style.borderColor = '#22c55e';
                                cardInner.style.boxShadow = '0 0 25px rgba(34, 197, 94, 0.4)';
                            }
                            if (applyBtn) {
                                applyBtn.className = 'btn btn-sm btn-success w-100 rounded-pill fw-bold py-1.5 shadow-sm active-theme-btn';
                                applyBtn.innerHTML = '<i class="fa-solid fa-check-double me-1"></i>Currently Active';
                                applyBtn.disabled = true;
                            }
                        } else {
                            if (cardInner) {
                                cardInner.classList.remove('border-success');
                                cardInner.style.borderColor = '';
                                cardInner.style.boxShadow = '';
                            }
                            if (applyBtn) {
                                applyBtn.className = 'btn btn-sm btn-outline-primary w-100 rounded-pill apply-theme-btn fw-semibold py-1.5';
                                applyBtn.innerHTML = '<i class="fa-solid fa-bolt me-1"></i>Apply Theme Now';
                                applyBtn.disabled = false;
                            }
                        }
                    });

                    if (presetSelect) presetSelect.value = presetKey;
                    try { updateActiveThemeStrip(true); } catch (e) {}

                    const modalEl = document.getElementById('themeGalleryModal');
                    if (modalEl && window.bootstrap?.Modal) {
                        const modalInstance = window.bootstrap.Modal.getInstance(modalEl);
                        if (modalInstance) modalInstance.hide();
                    }

                    alertToast(data.message || 'Theme applied successfully!', 'success');
                    setTimeout(() => {
                        const url = new URL(window.location.href);
                        url.searchParams.set('tab', 'appearance');
                        url.searchParams.set('_t', Date.now().toString());
                        window.location.href = url.toString();
                    }, 500);
                } else {
                    alertToast(data.message || 'Failed to apply theme.', 'danger');
                    applyBtns.forEach(b => {
                        b.disabled = false;
                        b.innerHTML = '<i class="fa-solid fa-bolt me-1"></i>Apply Theme Now';
                    });
                }
            } catch (err) {
                console.error('Theme apply error:', err);
                if (presetSelect) presetSelect.value = presetKey;
                try { updateActiveThemeStrip(true); } catch (e) {}
                const modalEl = document.getElementById('themeGalleryModal');
                if (modalEl && window.bootstrap?.Modal) {
                    const modalInstance = window.bootstrap.Modal.getInstance(modalEl);
                    if (modalInstance) modalInstance.hide();
                }
                alertToast('Theme selected! Reloading...', 'info');
                setTimeout(() => {
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', 'appearance');
                    url.searchParams.set('_t', Date.now().toString());
                    window.location.href = url.toString();
                }, 600);
            } finally {
                if (structuralAutosavePaused && typeof window.avdReleaseStructuralSettingsAction === 'function') {
                    window.avdReleaseStructuralSettingsAction();
                }
            }
        }

        document.querySelectorAll('.apply-theme-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const presetId = this.dataset.presetId;
                if (presetId) applyTheme(presetId);
            });
        });

        // 1-Click Preview Theme in Studio from Visual Gallery Modal
        document.querySelectorAll('.preview-theme-in-studio-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const presetId = this.dataset.presetId;
                if (!presetId) return;

                if (presetSelect) {
                    presetSelect.value = presetId;
                    applyThemePreset(presetId);
                }

                // Close Gallery Modal
                const galleryModalEl = document.getElementById('themeGalleryModal');
                if (galleryModalEl && window.bootstrap?.Modal) {
                    const inst = window.bootstrap.Modal.getInstance(galleryModalEl);
                    if (inst) inst.hide();
                }

                // Smooth scroll to studio stage
                const studioEl = document.getElementById('liveThemeStudioStage');
                if (studioEl) {
                    studioEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    studioEl.classList.add('shadow-lg');
                    setTimeout(() => studioEl.classList.remove('shadow-lg'), 1500);
                }

                const selectedOption = presetSelect?.querySelector(`option[value="${presetId}"]`);
                const label = selectedOption?.dataset?.label || selectedOption?.text || presetId;
                if (typeof alertToast === 'function') {
                    alertToast(`Loaded "${label}" into Live Studio Preview!`, 'info');
                }
            });
        });

        // 1-Click Master Experience Activator
            document.querySelectorAll('.apply-experience-btn').forEach(btn => {
            btn.addEventListener('click', async function () {
                const expKey = this.dataset.experienceId;
                if (!expKey) return;

                let structuralAutosavePaused = false;

                const originalHtml = this.innerHTML;
                this.disabled = true;
                this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Activating...';

                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                                  document.querySelector('input[name="_token"]')?.value;

                try {
                    if (typeof window.avdPrepareStructuralSettingsAction === 'function') {
                        await window.avdPrepareStructuralSettingsAction();
                        structuralAutosavePaused = true;
                    }
                    const response = await fetch('{{ route('admin.settings.apply-experience') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken
                        },
                        body: JSON.stringify({ experience_key: expKey })
                    });

                    const data = await response.json();

                    if (data.ok) {
                        const targetKey = data.experience_key || expKey;
                        document.querySelectorAll('.master-experience-card').forEach(card => {
                            const cardKey = card.dataset.experienceId;
                            const isNowActive = (cardKey === targetKey);
                            const ribbon = card.querySelector('.master-active-ribbon');
                            const activeBadge = card.querySelector('.master-active-badge');
                            const regBadge = card.querySelector('.master-regular-badge');
                            const icon = card.querySelector('.master-card-icon');
                            const btnContainer = card.querySelector('.master-btn-container');
                            const chips = card.querySelectorAll('.master-chip-badge');

                            if (isNowActive) {
                                card.classList.add('active-master-card', 'border-success');
                                card.style.background = 'linear-gradient(135deg, rgba(34, 197, 94, 0.14), rgba(255,255,255,0.03))';
                                card.style.borderColor = '#22c55e';
                                card.style.boxShadow = '0 0 25px rgba(34, 197, 94, 0.4)';
                                if (ribbon) ribbon.style.removeProperty('display');
                                if (activeBadge) activeBadge.style.removeProperty('display');
                                if (regBadge) regBadge.style.setProperty('display', 'none', 'important');
                                if (icon) { icon.classList.add('text-success'); icon.classList.remove('text-white-50'); }
                                chips.forEach(c => {
                                    c.classList.add('bg-success-subtle', 'text-success', 'border-success');
                                    c.classList.remove('bg-body-tertiary', 'text-muted');
                                });
                                if (btnContainer) {
                                    btnContainer.innerHTML = '<button type="button" class="btn btn-sm btn-success rounded-pill w-100 fw-bold py-1.5 shadow-sm active-experience-btn" disabled style="opacity: 1; cursor: default; pointer-events: none;"><i class="fa-solid fa-check-double me-1"></i>Currently Active</button>';
                                }
                            } else {
                                card.classList.remove('active-master-card', 'border-success');
                                card.style.background = 'rgba(255,255,255,0.03)';
                                card.style.borderColor = (cardKey === 'default_factory') ? 'rgba(255,61,97,0.3)' : 'rgba(255,255,255,0.08)';
                                card.style.boxShadow = '';
                                if (ribbon) ribbon.style.setProperty('display', 'none', 'important');
                                if (activeBadge) activeBadge.style.setProperty('display', 'none', 'important');
                                if (regBadge) regBadge.style.removeProperty('display');
                                if (icon) { icon.classList.remove('text-success'); icon.classList.add('text-white-50'); }
                                chips.forEach(c => {
                                    c.classList.remove('bg-success-subtle', 'text-success', 'border-success');
                                    c.classList.add('bg-body-tertiary', 'text-muted');
                                });
                                if (btnContainer) {
                                    const btnColor = (cardKey === 'default_factory') ? 'btn-outline-danger' : 'btn-outline-primary';
                                    const iconClass = (cardKey === 'default_factory') ? 'fa-arrow-rotate-left' : 'fa-bolt';
                                    const label = (cardKey === 'default_factory') ? 'Restore Default' : 'Activate';
                                    btnContainer.innerHTML = `<button type="button" class="btn btn-sm ${btnColor} rounded-pill w-100 fw-semibold apply-experience-btn py-1.5" data-experience-id="${cardKey}"><i class="fa-solid ${iconClass} me-1"></i>${label}</button>`;
                                }
                            }
                        });

                        alertToast(data.message || 'Master Experience activated!', 'success');
                        setTimeout(() => {
                            const url = new URL(window.location.href);
                            url.searchParams.set('tab', 'appearance');
                            url.searchParams.set('_t', Date.now().toString());
                            window.location.href = url.toString();
                        }, 500);
                    } else {
                        alertToast(data.message || 'Failed to activate experience.', 'danger');
                        this.disabled = false;
                        this.innerHTML = originalHtml;
                    }
                } catch (err) {
                    console.error('Experience error:', err);
                    alertToast('Network error while activating experience.', 'danger');
                    this.disabled = false;
                    this.innerHTML = originalHtml;
                } finally {
                    if (structuralAutosavePaused && typeof window.avdReleaseStructuralSettingsAction === 'function') {
                        window.avdReleaseStructuralSettingsAction();
                    }
                }
            });
        });

        // Reset to Default Theme Handler
            document.querySelectorAll('.reset-default-theme-btn').forEach(btn => {
            btn.addEventListener('click', async function () {
                if (!confirm('Are you sure you want to reset the theme and layout to factory defaults? Any custom colors or layout choices will be restored to the standard default.')) {
                    return;
                }

                const originalHtml = this.innerHTML;
                let structuralAutosavePaused = false;
                this.disabled = true;
                this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Resetting...';

                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                                  document.querySelector('input[name="_token"]')?.value;

                try {
                    if (typeof window.avdPrepareStructuralSettingsAction === 'function') {
                        await window.avdPrepareStructuralSettingsAction();
                        structuralAutosavePaused = true;
                    }
                    const response = await fetch('{{ route('admin.settings.reset-theme') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken
                        }
                    });

                    const data = await response.json();

                    if (data.ok) {
                        alertToast(data.message || 'Theme successfully reset to default!', 'success');
                        setTimeout(() => {
                            const url = new URL(window.location.href);
                            url.searchParams.set('tab', 'appearance');
                            url.searchParams.set('_t', Date.now().toString());
                            window.location.href = url.toString();
                        }, 500);
                    } else {
                        alertToast(data.message || 'Failed to reset theme.', 'danger');
                        this.disabled = false;
                        this.innerHTML = originalHtml;
                    }
                } catch (err) {
                    console.error('Reset error:', err);
                    alertToast('Network error while resetting theme.', 'danger');
                    this.disabled = false;
                    this.innerHTML = originalHtml;
                } finally {
                    if (structuralAutosavePaused && typeof window.avdReleaseStructuralSettingsAction === 'function') {
                        window.avdReleaseStructuralSettingsAction();
                    }
                }
            });
        });

        // Random Theme Trigger
        function pickRandomTheme() {
            const options = Array.from(presetSelect.options);
            if (!options.length) return;
            const randomOption = options[Math.floor(Math.random() * options.length)];
            applyTheme(randomOption.value);
        }

        const randomBtn = document.getElementById('randomThemeBtn');
        if (randomBtn) randomBtn.addEventListener('click', pickRandomTheme);

        const modalRandomBtn = document.getElementById('modalRandomThemeBtn');
        if (modalRandomBtn) modalRandomBtn.addEventListener('click', pickRandomTheme);

        // 365-Day Calendar Preview in Studio Handler
        document.querySelectorAll('.preview-daily-day-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const day = this.getAttribute('data-day');
                const name = this.getAttribute('data-name');
                const primary = this.getAttribute('data-primary');
                const secondary = this.getAttribute('data-secondary');
                const accent = this.getAttribute('data-accent');
                const layout = this.getAttribute('data-layout');
                const surface = this.getAttribute('data-surface');
                const radius = this.getAttribute('data-radius');
                const font = this.getAttribute('data-font');

                // Update color pickers
                const pColor = document.querySelector('input[name="primary_color"]');
                const sColor = document.querySelector('input[name="brand_secondary_color"]');
                const aColor = document.querySelector('input[name="accent_color"]');
                if (pColor) { pColor.value = primary; pColor.dispatchEvent(new Event('input')); }
                if (sColor) { sColor.value = secondary; sColor.dispatchEvent(new Event('input')); }
                if (aColor) { aColor.value = accent; aColor.dispatchEvent(new Event('input')); }

                // Update surface, radius, font
                const surfSel = document.querySelector('select[name="surface_style"]');
                const radSel = document.querySelector('select[name="radius_scale"]');
                const fontSel = document.querySelector('select[name="font_family"]');
                const homeSel = document.querySelector('select[name="home_layout_mode"]');
                if (surfSel && surface) surfSel.value = surface;
                if (radSel && radius) radSel.value = radius;
                if (fontSel && font) fontSel.value = font;
                if (homeSel && layout) homeSel.value = layout;

                // Update override input
                const overrideInput = document.getElementById('dailyDesignOverrideInput');
                if (overrideInput) overrideInput.value = day;

                // Enable the auto rotation switch
                const autoSwitch = document.getElementById('dailyDesignAutoEnabledCheck');
                if (autoSwitch) autoSwitch.checked = true;

                // Close calendar modal
                const modalEl = document.getElementById('dailyCalendarModal');
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();

                // Toast notification
                alertToast(`Previewing Day ${day}: ${name} (Loaded into Studio!)`, 'info');
            });
        });

        // 365-Day Calendar Inspector Live Preview & Search Engine
        window.updateCalendarLivePreview = function(data) {
            const b = document.getElementById('livePreviewDayBadge');
            const n = document.getElementById('livePreviewName');
            const l = document.getElementById('livePreviewLayout');
            const m = document.getElementById('livePreviewMood');
            const d1 = document.getElementById('livePreviewDot1');
            const d2 = document.getElementById('livePreviewDot2');
            const d3 = document.getElementById('livePreviewDot3');
            const ring = document.getElementById('livePreviewIconRing');
            const link = document.getElementById('livePreviewLiveLink');

            if (b) b.textContent = `Day ${data.day} (${data.date_label})`;
            if (n) n.textContent = data.name;
            if (l) l.textContent = (data.home_layout_mode || '').replace('_', ' ').toUpperCase();
            if (m) m.textContent = `${data.season_mood} · ${data.primary}`;
            if (d1) d1.style.background = data.primary;
            if (d2) d2.style.background = data.secondary;
            if (d3) d3.style.background = data.accent;
            if (ring) ring.style.background = `linear-gradient(135deg, ${data.primary}, ${data.accent})`;
            if (link) link.href = `/?preview_day=${data.day}`;
        };

        const calSearchInput = document.getElementById('calendarThemeSearch');
        const calLayoutFilter = document.getElementById('calendarLayoutFilter');

        function filterCalendarCards() {
            const q = (calSearchInput ? calSearchInput.value : '').trim().toLowerCase();
            const layout = (calLayoutFilter ? calLayoutFilter.value : '').trim();

            document.querySelectorAll('.day-card-wrapper').forEach(card => {
                const text = card.getAttribute('data-search') || '';
                const cardLayout = card.getAttribute('data-layout') || '';
                const matchText = !q || text.includes(q);
                const matchLayout = !layout || cardLayout === layout;
                card.style.display = (matchText && matchLayout) ? '' : 'none';
            });
        }
        if (calSearchInput) calSearchInput.addEventListener('input', filterCalendarCards);
        if (calLayoutFilter) calLayoutFilter.addEventListener('change', filterCalendarCards);

        window.downloadCalendarJson = function() {
            fetch('/api/daily-design/catalog')
                .then(res => res.json())
                .then(data => {
                    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'avdbcms_365_day_design_catalog.json';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    alertToast('365-Day Design Catalog exported to JSON successfully!', 'success');
                })
                .catch(err => {
                    alertToast('Export failed: ' + err.message, 'danger');
                });
        };
    });

    // ==========================================
    // Comprehensive Admin Settings Enhancements
    // ==========================================

    // 1. Settings Category Filter Controller
    document.addEventListener('DOMContentLoaded', function () {
        const filterBtns = document.querySelectorAll('.settings-cat-btn');
        const tabItems = document.querySelectorAll('.settings-tab-item');
        if (!filterBtns.length || !tabItems.length) return;

        filterBtns.forEach(btn => {
            btn.addEventListener('click', function () {
                filterBtns.forEach(b => {
                    b.classList.remove('btn-primary', 'active');
                    b.classList.add('btn-outline-secondary');
                });
                this.classList.remove('btn-outline-secondary');
                this.classList.add('btn-primary', 'active');

                const group = this.dataset.group;
                let firstVisibleTabBtn = null;
                let activeTabStillVisible = false;

                tabItems.forEach(item => {
                    const matches = (group === 'all' || item.dataset.group === group);
                    item.style.display = matches ? '' : 'none';
                    if (matches) {
                        const tabBtn = item.querySelector('.nav-link');
                        if (!firstVisibleTabBtn && tabBtn) firstVisibleTabBtn = tabBtn;
                        if (tabBtn && tabBtn.classList.contains('active')) activeTabStillVisible = true;
                    }
                });

                if (!activeTabStillVisible && firstVisibleTabBtn) {
                    firstVisibleTabBtn.click();
                }
            });
        });
    });

    // 2. SMTP Presets & Live Email Tester
    document.addEventListener('DOMContentLoaded', function () {
        const presetBtns = document.querySelectorAll('.smtp-preset-btn');
        const mailerSelect = document.getElementById('mailMailerSelect');
        const hostInput = document.getElementById('smtpHostInput');
        const portInput = document.getElementById('smtpPortInput');
        const encSelect = document.getElementById('smtpEncryptionSelect');

        presetBtns.forEach(btn => {
            btn.addEventListener('click', function () {
                if (mailerSelect) mailerSelect.value = this.dataset.mailer;
                if (hostInput) hostInput.value = this.dataset.host;
                if (portInput) portInput.value = this.dataset.port;
                if (encSelect) encSelect.value = this.dataset.enc;

                presetBtns.forEach(b => b.classList.remove('btn-secondary', 'text-white'));
                this.classList.add('btn-secondary', 'text-white');
            });
        });

        const btnSendTest = document.getElementById('btnSendTestEmail');
        const testRecipientInput = document.getElementById('testEmailRecipient');
        const testAlert = document.getElementById('testEmailAlert');

        if (btnSendTest && testRecipientInput && testAlert) {
            btnSendTest.addEventListener('click', function () {
                const recipient = testRecipientInput.value.trim();
                if (!recipient) {
                    alert('Please enter a recipient email address.');
                    return;
                }

                btnSendTest.disabled = true;
                btnSendTest.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending Test Email...';
                testAlert.className = 'alert alert-info small mt-2.5';
                testAlert.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Contacting SMTP server and dispatching test message...';
                testAlert.classList.remove('d-none');

                fetch('{{ route("admin.settings.test-email") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        to: recipient,
                        mailer: mailerSelect ? mailerSelect.value : 'smtp'
                    })
                })
                .then(res => res.json())
                .then(data => {
                    btnSendTest.disabled = false;
                    btnSendTest.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Send Test Email Now';
                    if (data.success) {
                        testAlert.className = 'alert alert-success small mt-2.5';
                        testAlert.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> ' + data.message;
                    } else {
                        testAlert.className = 'alert alert-danger small mt-2.5';
                        testAlert.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> ' + (data.message || 'Email delivery failed.');
                    }
                })
                .catch(err => {
                    btnSendTest.disabled = false;
                    btnSendTest.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Send Test Email Now';
                    testAlert.className = 'alert alert-danger small mt-2.5';
                    testAlert.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> Network request error: ' + err.message;
                });
            });
        }
    });

    // 3. Google SERP Snippet Preview & SEO Token Inserter
    document.addEventListener('DOMContentLoaded', function () {
        const titleInput = document.getElementById('seoTitleTemplateInput');
        const descInput = document.getElementById('seoMetaDescriptionInput');
        const siteNameInput = document.getElementById('generalSiteNameInput');
        const previewTitle = document.getElementById('serpPreviewTitle');
        const previewDesc = document.getElementById('serpPreviewDesc');
        const titleLen = document.getElementById('serpTitleLength');
        const descLen = document.getElementById('serpDescLength');

        function updateSerp() {
            const siteName = siteNameInput ? siteNameInput.value.trim() || 'AVDBCMS' : 'AVDBCMS';
            let t = titleInput ? titleInput.value : '{title} | {site_name}';
            let d = descInput ? descInput.value.trim() : '';

            // Replace tokens for preview
            const renderedTitle = t.replace('{title}', 'Top Trending Clips').replace('{site_name}', siteName);
            const renderedDesc = d || ('Watch and stream high quality videos on ' + siteName + '. Find trending actors, playlists, and discover new clips daily.');

            if (previewTitle) previewTitle.textContent = renderedTitle;
            if (previewDesc) previewDesc.textContent = renderedDesc;

            if (titleLen) {
                const len = renderedTitle.length;
                titleLen.textContent = len;
                titleLen.className = (len >= 40 && len <= 65) ? 'text-success fw-bold' : (len > 65 ? 'text-danger fw-bold' : 'text-warning fw-bold');
            }
            if (descLen) {
                const len = renderedDesc.length;
                descLen.textContent = len;
                descLen.className = (len >= 120 && len <= 165) ? 'text-success fw-bold' : (len > 165 ? 'text-danger fw-bold' : 'text-warning fw-bold');
            }
        }

        if (titleInput) titleInput.addEventListener('input', updateSerp);
        if (descInput) descInput.addEventListener('input', updateSerp);
        if (siteNameInput) siteNameInput.addEventListener('input', updateSerp);
        updateSerp();

        // SEO token badges click-to-insert
        let lastFocusedSeoInput = titleInput || descInput;
        if (titleInput) titleInput.addEventListener('focus', () => lastFocusedSeoInput = titleInput);
        if (descInput) descInput.addEventListener('focus', () => lastFocusedSeoInput = descInput);

        document.querySelectorAll('.seo-token-badge').forEach(badge => {
            badge.addEventListener('click', function () {
                const token = this.dataset.token;
                if (!lastFocusedSeoInput) return;

                const start = lastFocusedSeoInput.selectionStart || lastFocusedSeoInput.value.length;
                const end = lastFocusedSeoInput.selectionEnd || lastFocusedSeoInput.value.length;
                const val = lastFocusedSeoInput.value;
                lastFocusedSeoInput.value = val.substring(0, start) + token + val.substring(end);
                lastFocusedSeoInput.focus();
                lastFocusedSeoInput.selectionStart = lastFocusedSeoInput.selectionEnd = start + token.length;
                updateSerp();
            });
        });
    });

    // 4. Granular AJAX Cache Purge Suite
    document.addEventListener('DOMContentLoaded', function () {
        const purgeButtons = document.querySelectorAll('.btn-cache-purge');
        const alertBox = document.getElementById('cachePurgeAlert');
        if (!purgeButtons.length || !alertBox) return;

        purgeButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                const type = this.dataset.type || 'all';
                const originalHtml = this.innerHTML;

                purgeButtons.forEach(b => b.disabled = true);
                this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Clearing...';

                alertBox.className = 'alert alert-info small mt-3';
                alertBox.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Executing Artisan cache clear for [' + type + ']...';
                alertBox.classList.remove('d-none');

                fetch('{{ route("admin.settings.clear.cache") }}/' + type, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    }
                })
                .then(res => res.json())
                .then(data => {
                    purgeButtons.forEach(b => b.disabled = false);
                    btn.innerHTML = originalHtml;

                    if (data.success) {
                        alertBox.className = 'alert alert-success small mt-3';
                        alertBox.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> <strong>' + data.timestamp + '</strong>: ' + data.message;
                    } else {
                        alertBox.className = 'alert alert-danger small mt-3';
                        alertBox.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> ' + (data.message || 'Cache purge failed.');
                    }
                })
                .catch(err => {
                    purgeButtons.forEach(b => b.disabled = false);
                    btn.innerHTML = originalHtml;
                    alertBox.className = 'alert alert-danger small mt-3';
                    alertBox.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> Network error: ' + err.message;
                });
            });
        });
    });

    // 5. Upload Helpers & Presets
    document.addEventListener('DOMContentLoaded', function () {
        const durationInput = document.getElementById('uploadMaxDurationInput');
        const durationBadge = document.getElementById('uploadDurationBadge');

        function formatDuration(sec) {
            sec = parseInt(sec, 10) || 0;
            const h = Math.floor(sec / 3600);
            const m = Math.floor((sec % 3600) / 60);
            const s = sec % 60;
            let parts = [];
            if (h > 0) parts.push(h + ' hr');
            if (m > 0) parts.push(m + ' min');
            if (s > 0 && h === 0) parts.push(s + ' sec');
            return sec + 's = ' + (parts.join(' ') || '0 sec');
        }

        if (durationInput && durationBadge) {
            durationInput.addEventListener('input', () => {
                durationBadge.textContent = formatDuration(durationInput.value);
            });
            durationBadge.textContent = formatDuration(durationInput.value);
        }

        document.querySelectorAll('.duration-preset-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                if (durationInput) {
                    durationInput.value = this.dataset.sec;
                    if (durationBadge) durationBadge.textContent = formatDuration(this.dataset.sec);
                }
            });
        });

        document.querySelectorAll('.formats-preset-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const inp = document.getElementById('uploadAllowedFormatsInput');
                if (inp) inp.value = this.dataset.formats;
            });
        });

        document.querySelectorAll('.thumb-preset-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const w = document.getElementById('thumbWidthInput');
                const h = document.getElementById('thumbHeightInput');
                if (w) w.value = this.dataset.w;
                if (h) h.value = this.dataset.h;
            });
        });
    });

    // 6. Plan Limits Byte Helpers
    document.addEventListener('DOMContentLoaded', function () {
        function formatBytes(bytes) {
            bytes = parseInt(bytes, 10);
            if (!bytes || isNaN(bytes)) return 'Unlimited';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        }

        document.querySelectorAll('.byte-converter-badge').forEach(badge => {
            const inputId = badge.dataset.for;
            const input = document.getElementById(inputId);
            if (input) {
                badge.textContent = formatBytes(input.value);
                input.addEventListener('input', () => {
                    badge.textContent = formatBytes(input.value);
                });
            }
        });

        document.querySelectorAll('.byte-preset-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const target = document.getElementById(this.dataset.target);
                if (target) {
                    target.value = this.dataset.bytes;
                    const badge = document.querySelector('.byte-converter-badge[data-for="' + this.dataset.target + '"]');
                    if (badge) badge.textContent = formatBytes(this.dataset.bytes);
                }
            });
        });
    });

    // 7. Security Profiles & Add My IP Helpers
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.sec-profile-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const profile = this.dataset.profile;
                const setToggle = (name, val) => {
                    const el = document.getElementById('secToggle_' + name);
                    if (el) el.checked = val;
                };

                if (profile === 'strict') {
                    setToggle('force_https', true);
                    setToggle('hsts_enabled', true);
                    setToggle('csp_enabled', true);
                    setToggle('xss_protection', true);
                    setToggle('nosniff_header', true);
                    setToggle('frame_options_header', true);
                } else if (profile === 'balanced') {
                    setToggle('force_https', true);
                    setToggle('hsts_enabled', false);
                    setToggle('csp_enabled', false);
                    setToggle('xss_protection', true);
                    setToggle('nosniff_header', true);
                    setToggle('frame_options_header', true);
                } else if (profile === 'dev') {
                    setToggle('force_https', false);
                    setToggle('hsts_enabled', false);
                    setToggle('csp_enabled', false);
                    setToggle('xss_protection', false);
                    setToggle('nosniff_header', true);
                    setToggle('frame_options_header', false);
                }
            });
        });

        document.querySelectorAll('.add-my-ip-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const targetId = this.dataset.target;
                const area = document.getElementById(targetId);
                const myIp = '{{ request()->ip() }}';
                if (!area || !myIp) return;

                const lines = area.value.split('\\n').map(l => l.trim()).filter(Boolean);
                if (!lines.includes(myIp)) {
                    lines.push(myIp);
                    area.value = lines.join('\\n');
                    const orig = this.innerHTML;
                    this.innerHTML = '<i class="fa-solid fa-check me-1"></i> Added!';
                    setTimeout(() => this.innerHTML = orig, 2000);
                } else {
                    alert('Your IP (' + myIp + ') is already in this list.');
                }
            });
        });
    });

    // 8. Maintenance Secret Generator & Bypass URL Copy
    document.addEventListener('DOMContentLoaded', function () {
        const genBtn = document.getElementById('btnGenMaintSecret');
        const secretInput = document.getElementById('maintenanceSecretInput');
        const copyBtn = document.getElementById('btnCopyBypassUrl');
        const copyNotice = document.getElementById('copyBypassNotice');

        if (genBtn && secretInput) {
            genBtn.addEventListener('click', function () {
                const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
                let res = '';
                for (let i = 0; i < 24; i++) {
                    res += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                secretInput.value = res;
            });
        }

        if (copyBtn && secretInput && copyNotice) {
            copyBtn.addEventListener('click', function () {
                const sec = secretInput.value.trim();
                if (!sec) {
                    alert('Please enter or generate a bypass secret key first.');
                    return;
                }
                const url = copyBtn.dataset.base + '/?secret=' + encodeURIComponent(sec);
                navigator.clipboard.writeText(url).then(() => {
                    copyNotice.classList.remove('d-none');
                    setTimeout(() => copyNotice.classList.add('d-none'), 3000);
                }).catch(() => {
                    prompt('Copy this bypass URL:', url);
                });
            });
        }

        // ==========================================================
        // REAL-TIME AUTO-SAVE SYSTEM FOR ALL 18 SETTINGS PANELS
        // ==========================================================
        function initRealtimeAutoSave() {
            const hud = document.getElementById('realtimeAutoSaveHUD');
            const hudDot = document.getElementById('realtimeHudDot');
            const hudText = document.getElementById('realtimeHudText');
            const toast = document.getElementById('realtimeSaveToast');
            const toastTitle = document.getElementById('realtimeToastTitle');
            const toastSubtitle = document.getElementById('realtimeToastSubtitle');
            let toastTimer = null;
            let autoSaveTimers = new Map();
            let autoSavePaused = false;
            // Keep one write stream per settings panel. A mobile browser can
            // dispatch several change/input events before the first request
            // returns; sending those requests in parallel lets an older full
            // form snapshot finish last and overwrite the newest value.
            const autoSaveRequests = new Map();

            // Structural theme actions write many appearance keys at once.
            // Pause field autosave while one is running so a delayed full-form
            // snapshot cannot land after the preset and undo part of it.
            window.avdPrepareStructuralSettingsAction = async function () {
                autoSavePaused = true;
                autoSaveTimers.forEach((timer) => clearTimeout(timer));
                autoSaveTimers.clear();
                const inFlight = Array.from(autoSaveRequests.values())
                    .map((state) => state.inFlight)
                    .filter(Boolean);
                await Promise.all(inFlight);
            };
            window.avdReleaseStructuralSettingsAction = function () {
                autoSavePaused = false;
            };

            function updateHud(state, text) {
                if (!hud || !hudDot || !hudText) return;
                hud.classList.remove('saving', 'saved', 'error');
                hudDot.classList.remove('saving', 'saved', 'error');

                if (state === 'saving') {
                    hud.classList.add('saving');
                    hudDot.classList.add('saving');
                    hudText.textContent = text || 'Saving changes...';
                } else if (state === 'saved') {
                    hud.classList.add('saved');
                    hudDot.classList.add('saved');
                    hudText.textContent = text || 'Saved in real-time';
                    setTimeout(() => {
                        if (!hud.classList.contains('saving')) {
                            hud.classList.remove('saved');
                            hudDot.classList.remove('saved');
                            hudText.textContent = 'Real-Time Auto-Save Active';
                        }
                    }, 4000);
                } else if (state === 'error') {
                    hud.classList.add('error');
                    hudDot.classList.add('error');
                    hudText.textContent = text || 'Error saving changes';
                } else {
                    hudText.textContent = 'Real-Time Auto-Save Active';
                }
            }

            function triggerToast(title, subtitle) {
                if (!toast) return;
                if (toastTimer) clearTimeout(toastTimer);
                if (toastTitle) toastTitle.textContent = title;
                if (toastSubtitle) toastSubtitle.textContent = subtitle;
                toast.classList.add('show');
                toastTimer = setTimeout(() => {
                    toast.classList.remove('show');
                }, 3000);
            }

            // Live-apply appearance tokens in 0ms on the client
            function liveApplyAppearanceTokens(form) {
                const primary = form.querySelector('input[name="primary_color"]')?.value;
                const secondary = form.querySelector('input[name="brand_secondary_color"]')?.value;
                const accent = form.querySelector('input[name="accent_color"]')?.value;
                const darkBg = form.querySelector('input[name="dark_bg_color"]')?.value;
                const darkSurface = form.querySelector('input[name="dark_surface_color"]')?.value;

                const hexToRgb = (hex) => {
                    if (!hex || hex.length < 7) return null;
                    const num = parseInt(hex.slice(1), 16);
                    return [(num >> 16) & 255, (num >> 8) & 255, num & 255].join(',');
                };

                if (primary) {
                    document.documentElement.style.setProperty('--avd-brand', primary);
                    document.documentElement.style.setProperty('--avd-primary', primary);
                    const rgb = hexToRgb(primary);
                    if (rgb) document.documentElement.style.setProperty('--avd-brand-rgb', rgb);
                }
                if (secondary) {
                    document.documentElement.style.setProperty('--avd-brand-2', secondary);
                    const rgb = hexToRgb(secondary);
                    if (rgb) document.documentElement.style.setProperty('--avd-brand-2-rgb', rgb);
                }
                if (accent) {
                    document.documentElement.style.setProperty('--avd-cyan', accent);
                    document.documentElement.style.setProperty('--avd-accent', accent);
                    const rgb = hexToRgb(accent);
                    if (rgb) document.documentElement.style.setProperty('--avd-cyan-rgb', rgb);
                }
                if (darkBg) {
                    document.documentElement.style.setProperty('--avd-bg-dark', darkBg);
                }
                if (darkSurface) {
                    document.documentElement.style.setProperty('--avd-surface-dark', darkSurface);
                }
            }

            async function performRealtimeSave(form, triggerInput = null) {
                if (!form) return;
                const action = form.getAttribute('action');
                if (!action) return;

                const formId = form.getAttribute('id') || action;
                updateHud('saving', 'Saving changes in real-time...');

                // Build formData
                const formData = new FormData(form);

                // If user modified a specific field in appearance (other than the theme preset selector),
                // do NOT force-override custom colors with preset defaults
                if (action.includes('/appearance') && triggerInput) {
                    if (triggerInput.name !== 'theme_preset' && triggerInput.name !== 'apply_theme_preset') {
                        formData.delete('apply_theme_preset');
                    }
                }

                // If primary/accent color changed, live apply CSS properties immediately
                if (action.includes('/appearance')) {
                    liveApplyAppearanceTokens(form);
                }

                try {
                    const res = await fetch(action, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        }
                    });

                    if (res.ok) {
                        const data = await res.json().catch(() => ({}));
                        const timeStr = data.formatted_time || new Date().toLocaleTimeString();
                        const msg = data.message || 'Settings saved successfully.';
                        updateHud('saved', `✓ Saved in real-time (${timeStr})`);
                        triggerToast('Auto-Saved', `${msg} (${timeStr})`);
                    } else {
                        const err = await res.json().catch(() => ({}));
                        let errMsg = 'Failed to save settings.';
                        if (err.errors) {
                            errMsg = Object.values(err.errors).flat().join(' ');
                        } else if (err.message) {
                            errMsg = err.message;
                        }
                        updateHud('error', errMsg);
                        triggerToast('Save Error', errMsg);
                    }
                } catch (e) {
                    console.error('Real-time save error:', e);
                    updateHud('error', 'Network error while auto-saving.');
                }
            }

            function saveFormRealtime(form, triggerInput = null) {
                if (!form || autoSavePaused) return Promise.resolve();
                const formId = form.getAttribute('id') || form.getAttribute('action');
                if (!formId) return Promise.resolve();

                let state = autoSaveRequests.get(formId);
                if (!state) {
                    state = { pending: null, inFlight: null };
                    autoSaveRequests.set(formId, state);
                }

                // Replace a queued snapshot with the latest form state. The
                // request currently being written is allowed to finish, then
                // the latest snapshot is sent in order.
                state.pending = { form, triggerInput };
                if (state.inFlight) return state.inFlight;

                state.inFlight = (async () => {
                    try {
                        while (state.pending) {
                            const next = state.pending;
                            state.pending = null;
                            await performRealtimeSave(next.form, next.triggerInput);
                        }
                    } finally {
                        state.inFlight = null;
                        if (!state.pending) autoSaveRequests.delete(formId);
                    }
                })();

                return state.inFlight;
            }

            // Attach listeners to all settings panel forms
            const forms = document.querySelectorAll('form.avd-settings-panel');
            forms.forEach((form) => {
                const formId = form.getAttribute('id') || form.getAttribute('action');

                // Immediate save on dropdown select, checkbox, radio, color
                form.addEventListener('change', function (e) {
                    if (autoSavePaused) return;
                    const target = e.target;
                    if (!target || !target.name) return;

                    // Skip csrf or method
                    if (target.name === '_token' || target.name === '_method') return;

                    // Clear any pending debounced timers
                    if (autoSaveTimers.has(formId)) {
                        clearTimeout(autoSaveTimers.get(formId));
                        autoSaveTimers.delete(formId);
                    }

                    saveFormRealtime(form, target);
                });

                // Debounced save on text inputs, number inputs, textareas, sliders
                form.addEventListener('input', function (e) {
                    if (autoSavePaused) return;
                    const target = e.target;
                    if (!target || !target.name) return;

                    // If it's a color input, live update style immediately on every pixel drag
                    if (target.type === 'color' && form.getAttribute('action')?.includes('/appearance')) {
                        liveApplyAppearanceTokens(form);
                    }

                    // Text, number, textarea, range debounce 650ms
                    const needsDebounce = ['text', 'number', 'password', 'url', 'email', 'search', 'range'].includes(target.type) || target.tagName === 'TEXTAREA';
                    if (!needsDebounce) return;

                    if (autoSaveTimers.has(formId)) {
                        clearTimeout(autoSaveTimers.get(formId));
                    }

                    updateHud('saving', 'Pending changes...');

                    const timer = setTimeout(() => {
                        autoSaveTimers.delete(formId);
                        saveFormRealtime(form, target);
                    }, 650);

                    autoSaveTimers.set(formId, timer);
                });

                // Intercept manual submit button click (no page reload, no scroll loss)
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (autoSaveTimers.has(formId)) {
                        clearTimeout(autoSaveTimers.get(formId));
                        autoSaveTimers.delete(formId);
                    }

                    const submitBtn = form.querySelector('button[type="submit"]');
                    const originalHtml = submitBtn ? submitBtn.innerHTML : '';
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> Saving...';
                    }

                    saveFormRealtime(form).finally(() => {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = '<i class="fa-solid fa-check me-2 text-success"></i> Saved!';
                            setTimeout(() => {
                                submitBtn.innerHTML = originalHtml;
                            }, 2200);
                        }
                    });
                });
            });
        }
        initRealtimeAutoSave();
    });
</script>

<!-- Visual Theme Gallery Modal (120 Themes) -->
<div class="modal fade" id="themeGalleryModal" tabindex="-1" aria-labelledby="themeGalleryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem; overflow: hidden;">
            <div class="modal-header border-bottom py-3 px-4" style="background: rgba(var(--avd-brand-rgb, 255, 61, 97), 0.05);">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary text-white" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="themeGalleryModalLabel">
                            {{ __('Theme Universe') }}
                            <span class="badge bg-primary text-white rounded-pill ms-2 fs-6">120 {{ __('Curated Presets') }}</span>
                        </h5>
                        <p class="small text-muted mb-0">{{ __('Each theme is completely crafted with harmonized colors, surfaces, typography, and card geometry.') }}</p>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" id="modalRandomThemeBtn">
                        <i class="fa-solid fa-dice me-1"></i> {{ __('Surprise Me') }}
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body p-3 p-md-4">
                <!-- Search and Filter Navigation Bar -->
                <div class="mb-4">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text bg-body border-end-0 rounded-start-pill ps-3"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                                <input type="text" id="themeModalSearch" class="form-control border-start-0 border-end-0" placeholder="{{ __('Search 120 themes by name, mood, color (e.g. cyber, gold, oled, dracula)...') }}">
                                <button class="btn btn-outline-secondary border-start-0 rounded-end-pill pe-3" type="button" id="themeModalSearchClear" title="{{ __('Clear search query') }}" style="display: none;">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 text-md-end text-muted small">
                            <span id="themeFilteredCount" class="fw-bold text-primary">120</span> {{ __('themes found') }}
                        </div>
                    </div>

                    <!-- Aesthetic Mood Filter Pills -->
                    <div class="d-flex gap-1.5 flex-nowrap overflow-x-auto py-1 mb-2 scrollbar-none" id="themeMoodFilters">
                        <span class="small text-muted fw-bold d-flex align-items-center me-1 flex-shrink-0" style="font-size: 0.72rem;">
                            <i class="fa-solid fa-wand-magic-sparkles text-warning me-1"></i> {{ __('Aesthetic Mood:') }}
                        </span>
                        <button type="button" class="btn btn-xs rounded-pill px-2.5 py-0.5 flex-shrink-0 theme-mood-pill active btn-dark" data-mood="all">
                            ✨ {{ __('All Styles') }}
                        </button>
                        <button type="button" class="btn btn-xs rounded-pill px-2.5 py-0.5 flex-shrink-0 theme-mood-pill btn-outline-secondary" data-mood="oled">
                            🖤 {{ __('OLED Pure Black') }}
                        </button>
                        <button type="button" class="btn btn-xs rounded-pill px-2.5 py-0.5 flex-shrink-0 theme-mood-pill btn-outline-secondary" data-mood="light">
                            ☀️ {{ __('Daylight & Paper') }}
                        </button>
                        <button type="button" class="btn btn-xs rounded-pill px-2.5 py-0.5 flex-shrink-0 theme-mood-pill btn-outline-secondary" data-mood="cyber">
                            ⚡ {{ __('Cyber & Neon') }}
                        </button>
                        <button type="button" class="btn btn-xs rounded-pill px-2.5 py-0.5 flex-shrink-0 theme-mood-pill btn-outline-secondary" data-mood="luxury">
                            💎 {{ __('Glass & Regal') }}
                        </button>
                        <button type="button" class="btn btn-xs rounded-pill px-2.5 py-0.5 flex-shrink-0 theme-mood-pill btn-outline-secondary" data-mood="anime">
                            🌸 {{ __('Pastel & Anime') }}
                        </button>
                        <button type="button" class="btn btn-xs rounded-pill px-2.5 py-0.5 flex-shrink-0 theme-mood-pill btn-outline-secondary" data-mood="code">
                            💻 {{ __('Code & Hacker') }}
                        </button>
                    </div>

                    <!-- Category Pills -->
                    <div class="d-flex gap-1.5 flex-nowrap overflow-x-auto py-2 mt-1 scrollbar-none" id="themeCategoryFilters">
                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 py-1 flex-shrink-0 theme-cat-pill active" data-category="all">
                            {{ __('All (120)') }}
                        </button>
                        @foreach($themeCategories as $categoryName)
                            @php
                                $catCount = count($themePresetsByCategory[$categoryName] ?? []);
                            @endphp
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1 flex-shrink-0 theme-cat-pill" data-category="{{ $categoryName }}">
                                {{ $categoryName }} ({{ $catCount }})
                            </button>
                        @endforeach
                    </div>
                </div>

                <!-- Theme Cards Grid -->
                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3" id="themeCardsGrid">
                    @foreach($themePresets as $presetKey => $preset)
                        @php
                            $isActivePreset = ($currentThemePreset === $presetKey);
                        @endphp
                        <div class="col theme-card-item"
                             data-key="{{ $presetKey }}"
                             data-category="{{ $preset['category'] }}"
                             data-label="{{ strtolower($preset['label']) }}"
                             data-desc="{{ strtolower($preset['description']) }}"
                             data-primary="{{ $preset['primary'] }}"
                             data-secondary="{{ $preset['secondary'] }}"
                             data-accent="{{ $preset['accent'] }}"
                             data-dark-bg="{{ $preset['dark_bg_color'] ?? '#070913' }}"
                             data-dark-surface="{{ $preset['dark_surface_color'] ?? '#0e1322' }}"
                             data-light-bg="{{ $preset['light_bg_color'] ?? '#f8fafc' }}"
                             data-light-surface="{{ $preset['light_surface_color'] ?? '#ffffff' }}"
                             data-theme="{{ $preset['theme'] }}"
                             data-oled="{{ $preset['theme_oled_black_enabled'] }}"
                             data-surface="{{ $preset['surface_style'] }}"
                             data-radius="{{ $preset['radius_scale'] }}"
                             data-font="{{ $preset['font_family'] }}"
                             data-home-layout="{{ $preset['home_layout_mode'] ?? 'grid_modern' }}"
                             data-hero-layout="{{ $preset['hero_layout_mode'] ?? 'classic_banner' }}"
                             data-back-to-top-style="{{ $preset['back_to_top_style'] ?? 'floating_pill' }}"
                             data-watch-layout="{{ $preset['watch_layout_mode'] ?? 'classic_two_column' }}"
                             data-card-style="{{ $preset['video_card_layout_style'] ?? 'card_standard' }}"
                             data-typo-pairing="{{ $preset['typography_pairing'] ?? 'modern_tech' }}"
                             data-atmosphere="{{ $preset['background_atmosphere_effect'] ?? 'clean_none' }}"
                             data-modal-style="{{ $preset['modal_presentation_style'] ?? 'modal_glassmorphic_center' }}"
                             data-motion-engine="{{ $preset['motion_engine_level'] ?? 'smooth_fluid' }}"
                             data-mobile-columns="{{ $preset['mobile_video_columns'] ?? '2' }}"
                             data-mobile-density="{{ $preset['mobile_video_card_density'] ?? 'compact' }}"
                             data-mobile-bottom-nav="{{ $preset['mobile_bottom_nav_enabled'] ?? '1' }}"
                             data-mobile-tap-preview="{{ $preset['mobile_tap_preview_enabled'] ?? '1' }}"
                             data-hero-autoplay="{{ $preset['hero_slider_autoplay_enabled'] ?? '1' }}"
                             data-hero-autoplay-seconds="{{ $preset['hero_slider_autoplay_seconds'] ?? '5' }}"
                             data-title-lines="{{ $preset['video_card_title_lines'] ?? '2' }}">
                            <div class="card h-100 border shadow-sm rounded-4 overflow-hidden theme-item-card transition-all {{ $isActivePreset ? 'border-success' : '' }}"
                                 style="background: var(--bs-card-bg, #1a1d24); {{ $isActivePreset ? 'border-color: #22c55e !important; box-shadow: 0 0 25px rgba(34, 197, 94, 0.4) !important;' : '' }}">
                                <!-- Gradient Banner Header -->
                                <div class="position-relative" style="height: 48px; background: linear-gradient(135deg, {{ $preset['primary'] }}, {{ $preset['secondary'] }} 50%, {{ $preset['accent'] }});">
                                    <span class="position-absolute top-50 start-0 translate-middle-y ms-3 badge rounded-pill px-2.5 py-1 text-white shadow-sm" style="background: rgba(0, 0, 0, 0.55); backdrop-filter: blur(8px); font-size: 0.72rem;">
                                        {{ $preset['category'] }}
                                    </span>
                                    @if($isActivePreset)
                                        <span class="position-absolute top-50 end-0 translate-middle-y me-3 badge bg-success text-white rounded-pill px-2.5 py-1 shadow-sm fw-bold border border-white border-opacity-25" style="font-size: 0.72rem;">
                                            <i class="fa-solid fa-circle-check me-1"></i> {{ __('ACTIVE') }}
                                        </span>
                                    @elseif(($preset['theme_oled_black_enabled'] ?? '0') === '1')
                                        <span class="position-absolute top-50 end-0 translate-middle-y me-3 badge bg-black text-white rounded-pill px-2 py-1 shadow-sm border border-secondary" style="font-size: 0.68rem;">
                                            <i class="fa-solid fa-moon me-1 text-warning"></i>OLED
                                        </span>
                                    @endif
                                </div>
                                <div class="card-body p-3 d-flex flex-column">
                                    <div class="d-flex align-items-start justify-content-between gap-2 mb-1.5">
                                        <h6 class="card-title fw-bold mb-0 text-truncate" title="{{ $preset['label'] }}">
                                            {{ $preset['label'] }}
                                        </h6>
                                        @if($isActivePreset)
                                            <span class="badge bg-success text-white rounded-pill px-2 py-0.5 small fw-bold flex-shrink-0 shadow-sm">
                                                <i class="fa-solid fa-check me-1"></i> {{ __('ACTIVE') }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="card-text small text-muted mb-3 flex-grow-1" style="font-size: 0.8rem; min-height: 38px; line-height: 1.35;">
                                        {{ $preset['description'] }}
                                    </p>

                                    <!-- Palette preview dots (Primary, Secondary, Accent, Dark Canvas BG, Dark Surface) -->
                                    <div class="d-flex align-items-center justify-content-between gap-2 p-2 rounded-3 bg-body-tertiary mb-3">
                                        <div class="d-flex align-items-center gap-1.5">
                                            <span class="d-inline-block rounded-circle border border-white border-opacity-25" style="width: 16px; height: 16px; background: {{ $preset['primary'] }};" title="Primary: {{ $preset['primary'] }}"></span>
                                            <span class="d-inline-block rounded-circle border border-white border-opacity-25" style="width: 16px; height: 16px; background: {{ $preset['secondary'] }};" title="Secondary: {{ $preset['secondary'] }}"></span>
                                            <span class="d-inline-block rounded-circle border border-white border-opacity-25" style="width: 16px; height: 16px; background: {{ $preset['accent'] }};" title="Accent: {{ $preset['accent'] }}"></span>
                                            <span class="d-inline-block rounded-circle border border-white border-opacity-25" style="width: 16px; height: 16px; background: {{ $preset['dark_bg_color'] ?? '#070913' }};" title="Dark BG: {{ $preset['dark_bg_color'] ?? '#070913' }}"></span>
                                            <span class="d-inline-block rounded-circle border border-white border-opacity-25" style="width: 16px; height: 16px; background: {{ $preset['dark_surface_color'] ?? '#0e1322' }};" title="Dark Surface: {{ $preset['dark_surface_color'] ?? '#0e1322' }}"></span>
                                        </div>
                                        <div class="small font-monospace text-muted" style="font-size: 0.72rem;">
                                            {{ $preset['primary'] }}
                                        </div>
                                    </div>

                                    <!-- Visual Style & Architectural Badges -->
                                    <div class="d-flex flex-wrap gap-1 mb-3">
                                        <span class="badge border bg-body text-body-secondary" style="font-size: 0.65rem;" title="{{ __('Home Layout') }}">
                                            <i class="fa-solid fa-table-cells me-1 text-primary"></i> {{ Str::headline($preset['home_layout_mode'] ?? 'grid_modern') }}
                                        </span>
                                        <span class="badge border bg-body text-body-secondary" style="font-size: 0.65rem;" title="{{ __('Video Card Style') }}">
                                            <i class="fa-solid fa-film me-1 text-info"></i> {{ Str::headline($preset['video_card_layout_style'] ?? 'card_standard') }}
                                        </span>
                                        <span class="badge border bg-body text-body-secondary" style="font-size: 0.65rem;" title="{{ __('Typography Pairing') }}">
                                            <i class="fa-solid fa-font me-1 text-warning"></i> {{ Str::headline($preset['typography_pairing'] ?? 'modern_tech') }}
                                        </span>
                                        <span class="badge border bg-body text-body-secondary" style="font-size: 0.65rem;" title="{{ __('Atmosphere Effect') }}">
                                            <i class="fa-solid fa-wand-magic-sparkles me-1 text-success"></i> {{ Str::headline($preset['background_atmosphere_effect'] ?? 'clean_none') }}
                                        </span>
                                    </div>

                                    <!-- Apply & Studio Preview Buttons -->
                                    @if($isActivePreset)
                                        <button type="button" class="btn btn-sm btn-success w-100 rounded-pill fw-bold py-1.5 shadow-sm active-theme-btn" disabled style="opacity: 1; cursor: default; pointer-events: none;">
                                            <i class="fa-solid fa-check-double me-1"></i> {{ __('Currently Active') }}
                                        </button>
                                    @else
                                        <div class="d-flex gap-1.5 w-100 theme-card-actions">
                                            <button type="button" class="btn btn-sm btn-outline-info rounded-pill flex-grow-1 preview-theme-in-studio-btn fw-semibold py-1.5" data-preset-id="{{ $presetKey }}" title="{{ __('Load theme directly into Studio Stage without applying yet') }}">
                                                <i class="fa-solid fa-eye me-1"></i> {{ __('Studio Preview') }}
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill flex-grow-1 apply-theme-btn fw-semibold py-1.5" data-preset-id="{{ $presetKey }}">
                                                <i class="fa-solid fa-bolt me-1"></i> {{ __('Apply') }}
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="modal-footer py-2 px-4 border-top bg-body-tertiary justify-content-between">
                <span class="small text-muted">
                    <i class="fa-solid fa-circle-info me-1 text-primary"></i> {{ __('Applying a theme instantly updates the front-end and syncs your settings without downtime.') }}
                </span>
                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" data-bs-dismiss="modal">{{ __('Close') }}</button>
            </div>
        </div>
    </div>
</div>

<!-- 365-Day Calendar Inspector Modal -->
<div class="modal fade" id="dailyCalendarModal" tabindex="-1" aria-labelledby="dailyCalendarModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4" style="background: #0f172a; border: 1px solid rgba(255,255,255,0.12) !important;">
            <div class="modal-header border-bottom py-3 px-4" style="border-color: rgba(255,255,255,0.08) !important;">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 38px; height: 38px; background: linear-gradient(135deg, #6366f1, #06b6d4);">
                        <i class="fa-solid fa-calendar-days"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0" id="dailyCalendarModalLabel">
                            {{ __('365-Day Calendar Design Inspector') }}
                            <span class="badge rounded-pill text-white ms-2" style="background: linear-gradient(135deg, #6366f1, #06b6d4); font-size: 0.72rem;">366 Designs</span>
                        </h5>
                        <p class="small text-muted mb-0">{{ __('Browse every single day of the year, inspect seasonal themes & holidays, and preview any day live in the studio.') }}</p>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3 p-md-4">
                <!-- Search & Filters + Export Bar -->
                <div class="row g-2 mb-3 align-items-center">
                    <div class="col-12 col-md-5">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-black border-secondary text-white-50"><i class="fa-solid fa-magnifying-glass"></i></span>
                            <input type="text" id="calendarThemeSearch" class="form-control form-control-sm bg-black border-secondary text-white" placeholder="{{ __('Search 366 themes by name or holiday (e.g. Halloween, Christmas, Cyber)...') }}">
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <select id="calendarLayoutFilter" class="form-select form-select-sm bg-black border-secondary text-white">
                            <option value="">{{ __('All Layout Archetypes') }}</option>
                            <option value="cinema_carousel">Cinema Carousel</option>
                            <option value="bento_mosaic">Bento Mosaic</option>
                            <option value="youtube_spotlight">YouTube Spotlight</option>
                            <option value="cyberpunk_hud">Cyberpunk HUD</option>
                            <option value="tiktok_shorts">TikTok Shorts</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3 text-end">
                        <button type="button" class="btn btn-sm btn-outline-light rounded-pill px-3 w-100" onclick="window.downloadCalendarJson()">
                            <i class="fa-solid fa-file-code me-1"></i> {{ __('Export JSON') }}
                        </button>
                    </div>
                </div>

                <!-- Live Mini Card Mockup Preview Header Bar -->
                <div id="calendarLivePreviewBanner" class="p-3 rounded-3 mb-3 d-flex flex-wrap align-items-center justify-content-between gap-3 shadow-sm border"
                     style="background: rgba(255,255,255,0.03); border-color: rgba(255,255,255,0.08) !important;">
                    <div class="d-flex align-items-center gap-3">
                        <div id="livePreviewIconRing" class="rounded-circle d-flex align-items-center justify-content-center text-white flex-shrink-0 shadow"
                             style="width: 36px; height: 36px; background: linear-gradient(135deg, {{ $curDailyDesignToday['primary'] }}, {{ $curDailyDesignToday['accent'] }});">
                            <i class="fa-solid fa-eye"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge rounded-pill bg-body-tertiary text-white-50 px-2 py-0.5" id="livePreviewDayBadge" style="font-size: 0.68rem;">Day {{ $curDailyDesignToday['day'] }} ({{ $curDailyDesignToday['date_label'] }})</span>
                                <h6 class="fw-bold text-white mb-0" id="livePreviewName">{{ $curDailyDesignToday['name'] }}</h6>
                                <span class="badge bg-black text-info border" id="livePreviewLayout" style="font-size: 0.64rem;">{{ Str::headline($curDailyDesignToday['home_layout_mode']) }}</span>
                            </div>
                            <span class="small text-white-50" id="livePreviewMood">{{ $curDailyDesignToday['season_mood'] }} · {{ $curDailyDesignToday['primary'] }}</span>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="d-flex align-items-center gap-1.5 me-2">
                            <span class="rounded-circle border" id="livePreviewDot1" style="width: 16px; height: 16px; background: {{ $curDailyDesignToday['primary'] }};" title="Primary"></span>
                            <span class="rounded-circle border" id="livePreviewDot2" style="width: 16px; height: 16px; background: {{ $curDailyDesignToday['secondary'] }};" title="Secondary"></span>
                            <span class="rounded-circle border" id="livePreviewDot3" style="width: 16px; height: 16px; background: {{ $curDailyDesignToday['accent'] }};" title="Accent"></span>
                        </div>
                        <a href="/?preview_day={{ $curDailyDesignToday['day'] }}" target="_blank" id="livePreviewLiveLink" class="btn btn-xs btn-outline-info rounded-pill px-2.5 py-1" style="font-size: 0.72rem;">
                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> {{ __('Simulate on Live Site') }}
                        </a>
                    </div>
                </div>

                <!-- Month selector tabs -->
                <ul class="nav nav-pills gap-1.5 mb-4 pb-2 border-bottom flex-nowrap overflow-x-auto scrollbar-none" id="calendarMonthPills" style="border-color: rgba(255,255,255,0.08) !important;">
                    @foreach($curDailyDesignMonths as $mNum => $m)
                        <li class="nav-item">
                            <button class="nav-link py-1.5 px-3 rounded-pill fw-bold text-nowrap {{ (int)date('n') === $mNum ? 'active' : '' }}"
                                    data-bs-toggle="pill"
                                    data-bs-target="#cal-month-{{ $mNum }}"
                                    type="button"
                                    style="font-size: 0.82rem;">
                                {{ $m['name'] }}
                                <span class="badge bg-black-50 rounded-pill ms-1 text-white-50" style="font-size: 0.65rem;">{{ $m['days_count'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>

                <!-- Month panes with days grid -->
                <div class="tab-content" id="calendarMonthTabContent">
                    @foreach($curDailyDesignMonths as $mNum => $m)
                        <div class="tab-pane fade {{ (int)date('n') === $mNum ? 'show active' : '' }}" id="cal-month-{{ $mNum }}" role="tabpanel">
                            <div class="row g-3">
                                @foreach($curDailyDesignCatalog as $dKey => $dayData)
                                    @if($dayData['month'] === $mNum)
                                        <div class="col-12 col-md-6 col-lg-4 col-xl-3 day-card-wrapper"
                                             data-search="{{ strtolower($dayData['name'] . ' ' . ($dayData['holiday'] ?? '') . ' ' . $dayData['season_mood']) }}"
                                             data-layout="{{ $dayData['home_layout_mode'] }}">
                                            <div class="card h-100 border rounded-3 p-2.5 position-relative shadow-sm transition-all"
                                                 onmouseenter="window.updateCalendarLivePreview({{ json_encode($dayData) }})"
                                                 style="background: rgba(255,255,255,0.03); border-color: {{ $dayData['day'] === ((int)date('z')+1) ? 'var(--avd-brand, #ff3d61)' : 'rgba(255,255,255,0.08)' }} !important;">
                                                <div class="d-flex align-items-center justify-content-between mb-1.5">
                                                    <span class="badge rounded-pill bg-body-tertiary text-white-50 px-2 py-0.5" style="font-size: 0.68rem;">
                                                        {{ $dayData['date_label'] }} (Day {{ $dayData['day'] }})
                                                    </span>
                                                    <div class="d-flex align-items-center gap-1">
                                                        <span class="rounded-circle border" style="width: 14px; height: 14px; background: {{ $dayData['primary'] }};" title="{{ $dayData['primary'] }}"></span>
                                                        <span class="rounded-circle border" style="width: 14px; height: 14px; background: {{ $dayData['secondary'] }};" title="{{ $dayData['secondary'] }}"></span>
                                                        <span class="rounded-circle border" style="width: 14px; height: 14px; background: {{ $dayData['accent'] }};" title="{{ $dayData['accent'] }}"></span>
                                                    </div>
                                                </div>
                                                <h6 class="fw-bold text-white mb-1 text-truncate" title="{{ $dayData['name'] }}" style="font-size: 0.85rem;">
                                                    {{ $dayData['name'] }}
                                                </h6>
                                                <div class="d-flex flex-wrap gap-1 mb-2">
                                                    <span class="badge bg-black text-white-50 border" style="font-size: 0.62rem;">
                                                        <i class="fa-solid fa-table-cells me-1"></i> {{ Str::headline($dayData['home_layout_mode']) }}
                                                    </span>
                                                    @if(!empty($dayData['holiday']))
                                                        <span class="badge bg-warning text-dark" style="font-size: 0.62rem;">
                                                            <i class="fa-solid fa-star me-1"></i> {{ $dayData['holiday'] }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="d-flex gap-1.5 mt-auto pt-1 border-top" style="border-color: rgba(255,255,255,0.06) !important;">
                                                    <button type="button"
                                                            class="btn btn-sm btn-outline-info rounded-pill w-100 py-1 preview-daily-day-btn"
                                                            data-day="{{ $dayData['day'] }}"
                                                            data-name="{{ $dayData['name'] }}"
                                                            data-primary="{{ $dayData['primary'] }}"
                                                            data-secondary="{{ $dayData['secondary'] }}"
                                                            data-accent="{{ $dayData['accent'] }}"
                                                            data-layout="{{ $dayData['home_layout_mode'] }}"
                                                            data-surface="{{ $dayData['surface_style'] }}"
                                                            data-radius="{{ $dayData['radius_scale'] }}"
                                                            data-font="{{ $dayData['font_family'] }}"
                                                            style="font-size: 0.72rem;">
                                                        <i class="fa-solid fa-eye me-1"></i> {{ __('Preview in Studio') }}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="modal-footer py-2 px-4 border-top bg-body-tertiary justify-content-between">
                <span class="small text-muted">
                    <i class="fa-solid fa-circle-info me-1 text-info"></i> {{ __('Click "Preview in Studio" on any day to load its colors and styles live into your appearance editor.') }}
                </span>
                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" data-bs-dismiss="modal">{{ __('Close') }}</button>
            </div>
        </div>
    </div>
</div>

<!-- Palette Harmonizer & Color Science Lab Modal -->
<div class="modal fade" id="paletteHarmonizerModal" tabindex="-1" aria-labelledby="paletteHarmonizerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem; overflow: hidden;">
            <div class="modal-header border-bottom py-3 px-4" style="background: rgba(var(--avd-brand-rgb, 255, 61, 97), 0.08);">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary text-white shadow-sm" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-palette"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="paletteHarmonizerModalLabel">
                            {{ __('Palette Harmonizer & Color Science Lab') }}
                        </h5>
                        <p class="small text-muted mb-0">{{ __('Generate mathematically balanced chromatic schemes derived from your active primary brand color.') }}</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3 p-md-4">
                <div class="d-flex align-items-center justify-content-between p-2.5 rounded-3 bg-body-tertiary mb-3 border">
                    <div class="d-flex align-items-center gap-2">
                        <span class="small text-muted fw-bold text-uppercase">{{ __('Seed Brand Color:') }}</span>
                        <span class="d-inline-block rounded-circle border shadow-sm" id="harmonizerSeedDot" style="width: 20px; height: 20px; background: #ff3d61;"></span>
                        <span class="font-monospace fw-bold small" id="harmonizerSeedHex">#ff3d61</span>
                    </div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2.5 py-1 small">
                        <i class="fa-solid fa-atom me-1"></i> {{ __('OKLCH / HSL Polar Angles') }}
                    </span>
                </div>

                <div class="d-flex flex-column gap-3" id="harmonizerSchemesList">
                    <!-- Dynamic Harmonized Palette Cards inserted via JavaScript -->
                </div>
            </div>
            <div class="modal-footer py-2 px-4 border-top bg-body-tertiary justify-content-between">
                <span class="small text-muted">
                    <i class="fa-solid fa-lightbulb me-1 text-warning"></i> {{ __('Harmonies automatically maintain optimal luminosity and contrast ratios.') }}
                </span>
                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" data-bs-dismiss="modal">{{ __('Close') }}</button>
            </div>
        </div>
    </div>
</div>

<!-- WCAG 2.2 Deep Accessibility Audit Modal -->
<div class="modal fade" id="wcagComplianceModal" tabindex="-1" aria-labelledby="wcagComplianceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem; overflow: hidden;">
            <div class="modal-header border-bottom py-3 px-4" style="background: rgba(34, 197, 94, 0.08);">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-success text-white shadow-sm" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="wcagComplianceModalLabel">
                            {{ __('WCAG 2.2 Accessibility Health Audit') }}
                        </h5>
                        <p class="small text-muted mb-0">{{ __('Live verification against W3C Level AA (4.5:1) and AAA (7.0:1) contrast standards.') }}</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3 p-md-4">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle mb-3" style="font-size: 0.85rem;">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('UI Component / Pairing') }}</th>
                                <th>{{ __('Foreground') }}</th>
                                <th>{{ __('Background') }}</th>
                                <th class="text-center">{{ __('Contrast') }}</th>
                                <th class="text-center">{{ __('WCAG Rating') }}</th>
                                <th class="text-center">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody id="wcagMatrixTableBody">
                            <!-- Populated dynamically via JavaScript -->
                        </tbody>
                    </table>
                </div>

                <div class="p-3 rounded-3 border bg-body-tertiary d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <div class="fw-bold small mb-0.5"><i class="fa-solid fa-wand-magic-sparkles text-success me-1"></i> {{ __('Smart Contrast Optimization') }}</div>
                        <div class="small text-muted">{{ __('Automatically adjust brand luminance to guarantee AAA certification while preserving hue.') }}</div>
                    </div>
                    <button type="button" class="btn btn-sm btn-success rounded-pill px-3 fw-semibold shadow-sm" id="btnAutoOptimizeWcag">
                        <i class="fa-solid fa-bolt me-1"></i> {{ __('Auto-Tune to WCAG AAA') }}
                    </button>
                </div>
            </div>
            <div class="modal-footer py-2 px-4 border-top bg-body-tertiary justify-content-between">
                <span class="small text-muted">
                    <i class="fa-solid fa-circle-info me-1 text-info"></i> {{ __('Standard: Minimum 4.5:1 for body text, 3.0:1 for large headings & UI controls, 7.0:1 for AAA.') }}
                </span>
                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" data-bs-dismiss="modal">{{ __('Close') }}</button>
            </div>
        </div>
    </div>
</div>

<!-- Multi-Format Theme Spec Hub Modal (JSON, CSS Variables, Tailwind v4) -->
<div class="modal fade" id="themeJsonSpecModal" tabindex="-1" aria-labelledby="themeJsonSpecModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem; overflow: hidden;">
            <div class="modal-header border-bottom py-3 px-4" style="background: rgba(var(--avd-cyan-rgb, 24, 200, 216), 0.08);">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-info text-white shadow-sm" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-code"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="themeJsonSpecModalLabel">
                            {{ __('Theme Spec & Design Token Hub') }}
                        </h5>
                        <p class="small text-muted mb-0">{{ __('Export active design tokens in JSON, CSS Custom Properties, or Tailwind v4 formats.') }}</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3 p-md-4">
                <!-- Nav Tabs for Formats -->
                <ul class="nav nav-pills mb-3 gap-1" id="themeSpecTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active rounded-pill px-3 py-1.5 fw-semibold small" id="tab-json-btn" data-bs-toggle="pill" data-bs-target="#tab-json-pane" type="button" role="tab">
                            <i class="fa-solid fa-file-code me-1 text-primary"></i>JSON Spec
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-pill px-3 py-1.5 fw-semibold small" id="tab-css-btn" data-bs-toggle="pill" data-bs-target="#tab-css-pane" type="button" role="tab">
                            <i class="fa-brands fa-css3-alt me-1 text-info"></i>CSS Custom Properties
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-pill px-3 py-1.5 fw-semibold small" id="tab-tailwind-btn" data-bs-toggle="pill" data-bs-target="#tab-tailwind-pane" type="button" role="tab">
                            <i class="fa-solid fa-wind me-1 text-cyan"></i>Tailwind CSS v4
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="themeSpecTabsContent">
                    <!-- Tab 1: JSON Spec (Export & Import) -->
                    <div class="tab-pane fade show active" id="tab-json-pane" role="tabpanel">
                        <div class="mb-3">
                            <div class="d-flex align-items-center justify-content-between mb-1.5">
                                <label class="form-label fw-bold small text-uppercase text-muted mb-0" for="themeJsonExportTextarea">
                                    <i class="fa-solid fa-file-code text-info me-1"></i> {{ __('Active Theme JSON Definition') }}
                                </label>
                                <button type="button" class="btn btn-xs btn-outline-primary rounded-pill px-2.5 py-1" id="copyThemeJsonBtn">
                                    <i class="fa-regular fa-copy me-1"></i> {{ __('Copy JSON') }}
                                </button>
                            </div>
                            <textarea class="form-control font-monospace small bg-body-tertiary" id="themeJsonExportTextarea" rows="8" readonly style="font-size: 0.78rem; line-height: 1.45;"></textarea>
                        </div>
                        <div class="p-3 rounded-3 border bg-body-tertiary">
                            <h6 class="fw-bold mb-1 small text-uppercase text-muted">
                                <i class="fa-solid fa-file-import text-primary me-1"></i> {{ __('Import Custom Theme JSON') }}
                            </h6>
                            <p class="small text-muted mb-2">{{ __('Paste any valid AVDBCMS theme JSON below to apply its complete design tokens to your studio instantly.') }}</p>
                            <textarea class="form-control font-monospace small mb-2.5" id="themeJsonImportTextarea" rows="3" placeholder='{"primary":"#ff0055","secondary":"#7928ca","accent":"#00dfd8",...}' style="font-size: 0.78rem;"></textarea>
                            <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 fw-semibold" id="applyImportedThemeJsonBtn">
                                <i class="fa-solid fa-wand-magic-sparkles me-1"></i> {{ __('Apply & Preview Theme') }}
                            </button>
                        </div>
                    </div>

                    <!-- Tab 2: CSS Custom Properties -->
                    <div class="tab-pane fade" id="tab-css-pane" role="tabpanel">
                        <div class="d-flex align-items-center justify-content-between mb-1.5">
                            <label class="form-label fw-bold small text-uppercase text-muted mb-0" for="themeCssExportTextarea">
                                <i class="fa-brands fa-css3-alt text-info me-1"></i> {{ __('CSS Custom Properties (:root stylesheet)') }}
                            </label>
                            <button type="button" class="btn btn-xs btn-outline-info rounded-pill px-2.5 py-1" id="copyThemeCssBtn">
                                <i class="fa-regular fa-copy me-1"></i> {{ __('Copy CSS') }}
                            </button>
                        </div>
                        <textarea class="form-control font-monospace small bg-body-tertiary" id="themeCssExportTextarea" rows="12" readonly style="font-size: 0.78rem; line-height: 1.45;"></textarea>
                    </div>

                    <!-- Tab 3: Tailwind v4 Theme Tokens -->
                    <div class="tab-pane fade" id="tab-tailwind-pane" role="tabpanel">
                        <div class="d-flex align-items-center justify-content-between mb-1.5">
                            <label class="form-label fw-bold small text-uppercase text-muted mb-0" for="themeTailwindExportTextarea">
                                <i class="fa-solid fa-wind text-cyan me-1"></i> {{ __('Tailwind CSS v4 @theme Block') }}
                            </label>
                            <button type="button" class="btn btn-xs btn-outline-success rounded-pill px-2.5 py-1" id="copyThemeTailwindBtn">
                                <i class="fa-regular fa-copy me-1"></i> {{ __('Copy Tailwind') }}
                            </button>
                        </div>
                        <textarea class="form-control font-monospace small bg-body-tertiary" id="themeTailwindExportTextarea" rows="12" readonly style="font-size: 0.78rem; line-height: 1.45;"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2 px-4 border-top bg-body-tertiary justify-content-between">
                <span class="small text-muted">
                    <i class="fa-solid fa-layer-group me-1 text-primary"></i> {{ __('All formats export 31 bespoke design tokens simultaneously.') }}
                </span>
                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" data-bs-dismiss="modal">{{ __('Close') }}</button>
            </div>
        </div>
    </div>
</div>

<!-- Real-Time Auto-Save Floating Notification Toast -->
<div class="realtime-save-toast" id="realtimeSaveToast" role="status" aria-live="polite" aria-atomic="true">
    <div class="rounded-circle d-flex align-items-center justify-content-center bg-success text-white shadow-sm" style="width: 32px; height: 32px; flex-shrink: 0;">
        <i class="fa-solid fa-check"></i>
    </div>
    <div>
        <div class="fw-bold small text-white" id="realtimeToastTitle">{{ __('Settings Saved') }}</div>
        <div class="text-white-50 small" style="font-size: 0.74rem;" id="realtimeToastSubtitle">{{ __('Changes automatically applied in real-time.') }}</div>
    </div>
</div>
@endpush
