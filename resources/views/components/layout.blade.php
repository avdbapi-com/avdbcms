<!DOCTYPE html>
@php
    // The resolved appearance of this request: AppearanceSettings::current() (theme catalogue v2: what the provider
    // applied, drafts included) where it exists, else the stored group with this request's previews.
    $appearance = method_exists(\App\Support\AppearanceSettings::class, 'current')
        ? \App\Support\AppearanceSettings::current()
        : \App\Support\AppearanceSettings::resolveActiveDesign(\App\Models\Setting::getGroup('appearance'));
    // The daily design badge and its HUD render only while the daily design is on with its badge; otherwise the
    // daily catalogue is not consulted at all.
    $dailyBadgeEnabled = \App\Support\AppearanceSettings::isDailyDesignActive($appearance) && \App\Support\AppearanceSettings::bool($appearance, 'daily_design_show_badge');
    $activeDailyDesign = $dailyBadgeEnabled ? ($appearance['_active_daily_design'] ?? \App\Support\DailyDesignCatalog::today()) : null;
    // The daily design's year has 365 or 366 days; the day after its last one is 1 January of the next year.
    $dailyDaysInYear = \App\Support\DailyDesignCatalog::daysInYear((int) ($activeDailyDesign['year'] ?? now()->year));
    $tomorrowDailyDesign = $activeDailyDesign === null ? null : ($appearance['_tomorrow_daily_design'] ?? \App\Support\DailyDesignCatalog::tomorrow($activeDailyDesign));
    $dailyAtmosphereParticles = false;
    $appearanceBool = fn (string $key): bool => \App\Support\AppearanceSettings::bool($appearance, $key);
    $theme = in_array($appearance['theme'] ?? 'dark', ['light', 'dark', 'auto'], true) ? $appearance['theme'] ?? 'dark' : 'dark';
    $resolvedTheme = $theme === 'auto' ? 'dark' : $theme;
    $colorValue = fn (string $key, string $fallback): string => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) config('site.' . $key, $appearance[$key] ?? ''))
        ? (string) config('site.' . $key, $appearance[$key] ?? '')
        : $fallback;
    $primaryColor = $colorValue('primary_color', '#ff3d61');
    $secondaryColor = $colorValue('brand_secondary_color', '#ff8b5c');
    $accentColor = $colorValue('accent_color', '#18c8d8');
    $darkBgColor = $colorValue('dark_bg_color', '#090a0f');
    $darkSurfaceColor = $colorValue('dark_surface_color', '#141724');
    $lightBgColor = $colorValue('light_bg_color', '#f8fafc');
    $lightSurfaceColor = $colorValue('light_surface_color', '#ffffff');
    $themePreset = (string) config('site.theme_preset', $appearance['theme_preset'] ?? 'cyberpunk_neon');
    // A preview query parameter, or null when it is absent or not a single value (?key[]=x counts as absent).
    $queryString = fn (string $key): ?string => is_scalar($queryValue = request()->query($key)) ? (string) $queryValue : null;
    $surfaceStyle = (string) ($queryString('surface_style') ?? ($appearance['surface_style'] ?? 'glass'));
    $surfaceStyle = in_array($surfaceStyle, ['glass', 'solid', 'contrast'], true) ? $surfaceStyle : 'glass';
    $radiusScale = (string) ($queryString('radius_scale') ?? ($appearance['radius_scale'] ?? 'balanced'));
    $radiusScale = in_array($radiusScale, ['compact', 'balanced', 'rounded'], true) ? $radiusScale : 'balanced';
    $motionLevel = (string) ($queryString('motion_level') ?? ($appearance['motion_level'] ?? 'full'));
    $motionLevel = in_array($motionLevel, ['full', 'reduced', 'off'], true) ? $motionLevel : 'full';
    $contentDensity = (string) ($queryString('content_density') ?? ($appearance['content_density'] ?? 'comfortable'));
    $contentDensity = in_array($contentDensity, ['compact', 'comfortable', 'relaxed'], true) ? $contentDensity : 'comfortable';
    $gridColumns = max(2, min(6, (int)($appearance['grid_columns'] ?? 4)));
    $logoUrl = (string) config('site.logo_url', $appearance['logo_url'] ?? '');
    $faviconUrl = (string) config('site.favicon_url', $appearance['favicon_url'] ?? '');
    $configBool = function (string $key, bool $fallback) use ($queryString): bool {
        $value = $queryString($key) ?? config('site.' . $key, $fallback);

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    };
    $typographyFonts = \App\Support\AppearanceSettings::fontFamilies();
    $typographyEnabled = $configBool('typography_controls_enabled', $appearanceBool('typography_controls_enabled'));
    $fontFamilyKey = (string) config('site.font_family', $appearance['font_family'] ?? 'system');
    $fontFamilyKey = array_key_exists($fontFamilyKey, $typographyFonts) ? $fontFamilyKey : 'system';
    $fontFamilyStack = $typographyFonts[$fontFamilyKey]['stack'];
    $textContrastLevels = \App\Support\AppearanceSettings::textContrastLevels();
    $textContrast = (string) config('site.text_contrast', $appearance['text_contrast'] ?? 'normal');
    $textContrast = array_key_exists($textContrast, $textContrastLevels) ? $textContrast : 'normal';
    $intSetting = function (string $key, int $fallback, int $min, int $max) use ($appearance): int {
        $value = (int) config('site.' . $key, $appearance[$key] ?? $fallback);

        return max($min, min($max, $value));
    };
    $baseFontSize = $intSetting('base_font_size', 16, \App\Support\AppearanceSettings::BASE_FONT_MIN_PX, \App\Support\AppearanceSettings::BASE_FONT_MAX_PX);
    $uiFontScale = $intSetting('ui_font_scale', 100, \App\Support\AppearanceSettings::UI_FONT_SCALE_MIN, \App\Support\AppearanceSettings::UI_FONT_SCALE_MAX);
    $headingFontScale = $intSetting('heading_font_scale', 100, \App\Support\AppearanceSettings::HEADING_FONT_SCALE_MIN, \App\Support\AppearanceSettings::HEADING_FONT_SCALE_MAX);
    $cardTitleFontScale = $intSetting('card_title_font_scale', 100, \App\Support\AppearanceSettings::CARD_TITLE_FONT_SCALE_MIN, \App\Support\AppearanceSettings::CARD_TITLE_FONT_SCALE_MAX);
    $uiFontRatio = number_format($uiFontScale / 100, 2, '.', '');
    $headingFontRatio = number_format($headingFontScale / 100, 2, '.', '');
    $cardTitleFontRatio = number_format($cardTitleFontScale / 100, 2, '.', '');
    $effectsEnabled = $configBool('effects_enabled', $appearanceBool('effects_enabled'));
    $effectEnabled = fn (string $key): bool => $effectsEnabled && $configBool($key, $appearanceBool($key));
    // Ambient particles are a decorative effect too. Keep the admin setting
    // authoritative, but make the global effects and motion controls able to
    // turn off its render loop before the canvas is emitted.
    $dailyAtmosphereParticles = $effectEnabled('daily_design_atmosphere_particles') && $motionLevel !== 'off';
    $cssAnimationsEnabled = $effectEnabled('css_animations_enabled');
    $cssTransitionsEnabled = $effectEnabled('css_transitions_enabled');
    $cssHoverEffectsEnabled = $effectEnabled('css_hover_effects_enabled');
    $cssGradientEffectsEnabled = $effectEnabled('css_gradient_effects_enabled');
    $cssBlurEffectsEnabled = $effectEnabled('css_blur_effects_enabled');
    $cssShadowEffectsEnabled = $effectEnabled('css_shadow_effects_enabled');
    $cssStickyHeaderEnabled = $effectEnabled('css_sticky_header_enabled');
    $cssShimmerEffectEnabled = $effectEnabled('css_shimmer_effect_enabled');
    $headlineAccentEnabled = $cssGradientEffectsEnabled && $configBool('headline_accent_enabled', $appearanceBool('headline_accent_enabled'));
    $headlineAccentAnimationEnabled = $headlineAccentEnabled && $cssAnimationsEnabled && $configBool('headline_accent_animation_enabled', $appearanceBool('headline_accent_animation_enabled'));
    $jsAnimeEffectsEnabled = $effectEnabled('js_anime_effects_enabled');
    $jsThemeMemoryEnabled = $effectEnabled('js_theme_memory_enabled');
    $jsTheaterMemoryEnabled = $effectEnabled('js_theater_memory_enabled');
    $jsSmoothScrollEnabled = $effectEnabled('js_smooth_scroll_enabled');
    $jsCopyToastEnabled = $effectEnabled('js_copy_toast_enabled');
    $jsLazyCommentsEnabled = $effectEnabled('js_lazy_comments_enabled');
    $customCodeEnabled = $configBool('custom_code_enabled', $appearanceBool('custom_code_enabled'));
    $customCss = $customCodeEnabled ? ($appearance['custom_css'] ?? '') : '';
    $customJs = $customCodeEnabled ? ($appearance['custom_js'] ?? '') : '';
    $customHead = $customCodeEnabled ? ($appearance['custom_head_html'] ?? '') : '';
    $announcementBanner = $configBool('announcement_banner_enabled', $appearanceBool('announcement_banner_enabled')) ? ($appearance['announcement_banner'] ?? '') : '';
    $frontSidebarPinned = $configBool('front_sidebar_pinned_enabled', $appearanceBool('front_sidebar_pinned_enabled'));
    $cardHoverEnabled = $cssHoverEffectsEnabled && $configBool('video_card_hover_enabled', $appearanceBool('video_card_hover_enabled'));
    $watchSidebarStickyEnabled = $configBool('watch_sidebar_sticky_enabled', $appearanceBool('watch_sidebar_sticky_enabled'));
    $watchKeyboardShortcutsEnabled = $configBool('watch_keyboard_shortcuts_enabled', $appearanceBool('watch_keyboard_shortcuts_enabled'));
    $backToTopEnabled = $configBool('back_to_top_enabled', $appearanceBool('back_to_top_enabled'));
    $secondaryPageDesignEnabled = $configBool('secondary_page_design_enabled', $appearanceBool('secondary_page_design_enabled'));
    $secondaryPageHeroEnabled = $configBool('secondary_page_hero_enabled', $appearanceBool('secondary_page_hero_enabled'));
    $secondaryPageSurfacesEnabled = $configBool('secondary_page_surfaces_enabled', $appearanceBool('secondary_page_surfaces_enabled'));
    $secondaryPageFormPolishEnabled = $configBool('secondary_page_form_polish_enabled', $appearanceBool('secondary_page_form_polish_enabled'));
    $secondaryPageTablePolishEnabled = $configBool('secondary_page_table_polish_enabled', $appearanceBool('secondary_page_table_polish_enabled'));
    $clampInt = function (string $key, int $fallback, int $min, int $max) use ($appearance, $queryString): int {
        $value = (int) ($queryString($key) ?? config('site.' . $key, $appearance[$key] ?? $fallback));

        return max($min, min($max, $value));
    };
    $optionValue = function (string $key, array $allowed, string $fallback) use ($appearance, $queryString): string {
        $value = (string) ($queryString($key) ?? config('site.' . $key, $appearance[$key] ?? $fallback));

        return in_array($value, $allowed, true) ? $value : $fallback;
    };
    $homeWidgetMotionEnabled = $jsAnimeEffectsEnabled && $configBool('home_widget_motion_enabled', $appearanceBool('home_widget_motion_enabled'));
    $homeWidgetMotionStyle = $optionValue('home_widget_motion_style', ['slide', 'lift', 'zoom', 'fade'], 'slide');
    $homeWidgetMotionDuration = $clampInt('home_widget_motion_duration_ms', 720, 120, 2000);
    $homeWidgetMotionDistance = $clampInt('home_widget_motion_distance_px', 34, 0, 160);
    $homeWidgetMotionStagger = $clampInt('home_widget_motion_stagger_ms', 70, 0, 240);
    $homeWidgetMotionCascade = $homeWidgetMotionEnabled && $configBool('home_widget_motion_card_cascade_enabled', $appearanceBool('home_widget_motion_card_cascade_enabled'));
    $homeWidgetMotionAutoRun = $homeWidgetMotionEnabled && $configBool('home_widget_motion_autorun_enabled', $appearanceBool('home_widget_motion_autorun_enabled'));
    $homeWidgetMotionPauseOnHover = $configBool('home_widget_motion_pause_on_hover_enabled', $appearanceBool('home_widget_motion_pause_on_hover_enabled'));
    $homeWidgetMotionSpeed = $clampInt('home_widget_motion_speed_px_per_second', 48, 4, 160);
    $secondaryPageDensity = $optionValue('secondary_page_density', ['compact', 'comfortable', 'relaxed'], 'comfortable');
    $secondaryPageMaxWidth = $optionValue('secondary_page_max_width', ['standard', 'wide', 'full'], 'wide');
    $scrollbarStyle = $optionValue('scrollbar_style', array_keys(\App\Support\AppearanceSettings::scrollbarStyles()), 'subtle');
    $uploadModalStyle = $optionValue('upload_modal_style', array_keys(\App\Support\AppearanceSettings::uploadModalStyles()), 'glass');
    $mobileVideoColumns = $clampInt('mobile_video_columns', 2, 1, 2);
    $mobileVideoCardDensity = $optionValue('mobile_video_card_density', array_keys(\App\Support\AppearanceSettings::mobileVideoCardDensities()), 'compact');
    $studioVideoPreviewMode = $optionValue('studio_video_preview_mode', array_keys(\App\Support\AppearanceSettings::studioVideoPreviewModes()), 'button');
    $enhancedSelectsEnabled = $configBool('enhanced_selects_enabled', $appearanceBool('enhanced_selects_enabled'));
    $horizontalRailsHideScrollbarsEnabled = $configBool('horizontal_rails_hide_scrollbars_enabled', $appearanceBool('horizontal_rails_hide_scrollbars_enabled'));
    $scrollPanelHoverGuardEnabled = $configBool('scroll_panel_hover_guard_enabled', $appearanceBool('scroll_panel_hover_guard_enabled'));
    $scrollBehavior = $jsSmoothScrollEnabled ? 'smooth' : 'auto';
    $themeOledBlackEnabled = $configBool('theme_oled_black_enabled', $appearanceBool('theme_oled_black_enabled'));
    $headerStyle = $optionValue('header_style', array_keys(\App\Support\AppearanceSettings::headerStyles()), 'glass_blur');
    $videoCardHoverGlowEnabled = $configBool('video_card_hover_glow_enabled', $appearanceBool('video_card_hover_glow_enabled'));
    $videoCardDurationStyle = $optionValue('video_card_duration_style', array_keys(\App\Support\AppearanceSettings::videoCardDurationStyles()), 'pill');
    $videoCardTitleLines = $optionValue('video_card_title_lines', ['1', '2', '3', '4'], '2');
    $watchAmbientGlowIntensity = $optionValue('watch_ambient_glow_intensity', array_keys(\App\Support\AppearanceSettings::ambientGlowIntensities()), 'ambient');
    $searchFocusGlowEnabled = $configBool('search_focus_glow_enabled', $appearanceBool('search_focus_glow_enabled'));
    $creatorAvatarRingStyle = $optionValue('creator_avatar_ring_style', array_keys(\App\Support\AppearanceSettings::creatorAvatarRingStyles()), 'neon_pulse');
    $creatorVerifiedBadgeEnabled = $configBool('creator_verified_badge_enabled', $appearanceBool('creator_verified_badge_enabled'));
    $categoryCardStyle = $optionValue('category_card_style', array_keys(\App\Support\AppearanceSettings::categoryCardStyles()), 'gradient_mesh');
    $mobileBottomNavEnabled = $configBool('mobile_bottom_nav_enabled', $appearanceBool('mobile_bottom_nav_enabled'));
    $mobileTapPreviewEnabled = $configBool('mobile_tap_preview_enabled', $appearanceBool('mobile_tap_preview_enabled'));
    $backToTopStyle = $optionValue('back_to_top_style', array_keys(\App\Support\AppearanceSettings::backToTopStyles()), 'floating_pill');
    $uiClickRippleEnabled = $configBool('ui_click_ripple_enabled', $appearanceBool('ui_click_ripple_enabled'));
    $homeLayoutMode = $optionValue('home_layout_mode', array_keys(\App\Support\AppearanceSettings::homeLayoutModes()), 'grid_modern');
    $watchLayoutMode = $optionValue('watch_layout_mode', array_keys(\App\Support\AppearanceSettings::watchLayoutModes()), 'classic_two_column');
    $videoCardLayoutStyle = $optionValue('video_card_layout_style', array_keys(\App\Support\AppearanceSettings::videoCardLayoutStyles()), 'card_standard');
    $modalPresentationStyle = $optionValue('modal_presentation_style', array_keys(\App\Support\AppearanceSettings::modalPresentationStyles()), 'modal_glassmorphic_center');
    $motionEngineLevel = $optionValue('motion_engine_level', array_keys(\App\Support\AppearanceSettings::motionEngineLevels()), 'smooth_fluid');
    $interactive3dCardTiltEnabled = $effectEnabled('interactive_3d_card_tilt_enabled');
    $cinemaAmbientLightDiffusionEnabled = $effectEnabled('cinema_ambient_light_diffusion_enabled');
    $backgroundAtmosphereEffect = $optionValue('background_atmosphere_effect', array_keys(\App\Support\AppearanceSettings::backgroundAtmosphereEffects()), 'clean_none');
    $typographyPairing = $optionValue('typography_pairing', array_keys(\App\Support\AppearanceSettings::typographyPairings()), 'modern_tech');
    $videoPlayerCinemaFilter = $optionValue('video_player_cinema_filter', array_keys(\App\Support\AppearanceSettings::videoPlayerCinemaFilters()), 'none');
    $cardSpotlightGlowEnabled = $effectEnabled('card_spotlight_glow_enabled');
    $borderBeamGlowEnabled = $effectEnabled('border_beam_glow_enabled');
    $borderBeamSpeed = $optionValue('border_beam_speed', array_keys(\App\Support\AppearanceSettings::borderBeamSpeeds()), 'normal');
    $surfaceNoiseGrainEnabled = $effectEnabled('surface_noise_grain_enabled');
    $uiAudioFeedbackEnabled = $effectEnabled('ui_audio_feedback_enabled');
    $videoPlayerAmbilightGlowEnabled = $effectEnabled('video_player_ambilight_glow_enabled');
    $surfaceGlassmorphismEnabled = $effectEnabled('surface_glassmorphism_enabled');
    $buttonLiquidShimmerEnabled = $effectEnabled('button_liquid_shimmer_enabled');
    $cursorGlowTrailEnabled = $effectEnabled('cursor_glow_trail_enabled');
    $typoPairings = \App\Support\AppearanceSettings::typographyPairings();
    $activeTypoPairing = $typoPairings[$typographyPairing] ?? $typoPairings['modern_tech'];

    // AppearanceSettings resolves explicit previews before controllers and views.
    // Persisted custom values remain authoritative when no preview is requested.
    $seoService = app(\App\Services\SeoService::class);
    $defaultBrandImage = $logoUrl !== '' ? site_asset_url($logoUrl) : asset('images/logo.png');
    $ogImage = config('site.og_image') ?: $defaultBrandImage;
    $metaDesc = config('site.default_meta_description') ?: '';
    $pageTitle = decode_html_entities(trim($__env->yieldContent('title')));
    $pageDescription = trim($__env->yieldContent('description', $metaDesc));
    $pageImage = trim($__env->yieldContent('image', $ogImage)) ?: $ogImage;
    $pageImage = site_asset_url($pageImage) ?: asset('images/logo.png');
    $titleTemplate = config('site.meta_title_template', '{title} | {site_name}');
    $fullTitle = $pageTitle !== ''
        ? trim(strtr($titleTemplate, [
            '{title}' => $pageTitle,
            '{site_name}' => config('app.name'),
        ]))
        : config('app.name');
    $twitterCard = config('site.twitter_card_type', 'summary_large_image');
    $twitterHandle = config('site.twitter_handle', '');
    $fbAppId = config('site.facebook_app_id', '');
    $googleVerif = config('site.google_verification', '');
    $activeLocale = str_replace('_', '-', (string) app()->getLocale());
    $activeLocaleBase = strtolower((string) explode('-', $activeLocale)[0]);
    $languageCatalog = (array) config('languages', []);
    $activeLocaleInfo = (array) (
        $languageCatalog[(string) app()->getLocale()]
        ?? $languageCatalog[$activeLocale]
        ?? $languageCatalog[$activeLocaleBase]
        ?? []
    );
    $localeRtl = array_key_exists('rtl', $activeLocaleInfo)
        ? filter_var($activeLocaleInfo['rtl'], FILTER_VALIDATE_BOOLEAN)
        : in_array($activeLocaleBase, ['ar', 'fa', 'he', 'ur'], true);
    // Keep the existing site-wide switch as an explicit force-RTL override,
    // while allowing locale metadata to set direction for RTL languages.
    $isRtl = filter_var(config('site.rtl_support', false), FILTER_VALIDATE_BOOLEAN) || $localeRtl;
    $ogLocale = str_replace('-', '_', $activeLocale);
    $routeName = request()->route()?->getName();
    $layoutType = (string) $attributes->get('type', '');
    $isBackOfficeLayout = in_array($layoutType, ['admin', 'user'], true);
    // What the theme CSS needs to know about this page, printed as markers instead of tested with a page-wide :has()
    // (which re-checked the whole page each time a feed page arrived): the back-office menu
    // (layouts/menus/sidebars/back: html[data-avd-back-office]) and a home hero zone (body.has-hero-zone; the
    // hero partial declares it, and the page's sections are known before the layout renders).
    $backOfficeMenu = ($sidebar ?? null) === 'back';
    $pageHasHeroZone = class_exists(\App\Support\ThemeStructure::class) && \App\Support\ThemeStructure::pageHasHeroZone();
    // Capability tier (perf v2). Admin pages always render rich, so the Theme Studio previews
    // show every effect; a non-auto performance_tier_mode pins every visitor to that tier.
    // Otherwise the <head> tier script decides before the first paint, and 'balanced' is the
    // no-JS default. The build hash scopes the visitor's 7-day downgrade hint to this build.
    $perfTierMode = $optionValue('performance_tier_mode', array_keys(\App\Support\AppearanceSettings::performanceTierModes()), 'auto');
    $perfTierPinned = $layoutType === 'admin' ? 'rich' : ($perfTierMode !== 'auto' ? $perfTierMode : null);
    $perfTierServer = $perfTierPinned ?? 'balanced';
    $viteManifestHash = (string) (\Illuminate\Support\Facades\Vite::manifestHash() ?? '');
    $rating = trim($__env->yieldContent('rating'));
    if ($rating === '' && !$isBackOfficeLayout && config('site.adult_rating_enabled', true)) {
        $rating = 'adult';
    }
    $robotsOverride = trim($__env->yieldContent('robots'));
    $pageRobots = $seoService->robotsContent();

    if ($layoutType === 'admin' || ($routeName && \Illuminate\Support\Str::startsWith($routeName, 'admin.'))) {
        $pageRobots = 'noindex, nofollow';
    } elseif ($routeName === 'search.index' && !config('site.search_pages_indexable', false)) {
        $pageRobots = 'noindex, follow';
    } elseif (in_array($routeName, ['video.show', 'video.seo-show'], true) && !config('site.video_pages_indexable', true)) {
        $pageRobots = 'noindex, follow';
    } elseif ($routeName === 'pages.category' && !config('site.category_pages_indexable', true)) {
        $pageRobots = 'noindex, follow';
    } elseif ($routeName === 'user.show' && !config('site.profile_pages_indexable', true)) {
        $pageRobots = 'noindex, follow';
    } elseif ($routeName === 'playlist.show' && !config('site.playlist_pages_indexable', true)) {
        $pageRobots = 'noindex, follow';
    } elseif ($routeName && (
        in_array($routeName, ['login', 'registration'], true) ||
        \Illuminate\Support\Str::startsWith($routeName, ['password.', 'verification.', 'phone.', 'oauth.'])
    )) {
        $pageRobots = 'noindex, nofollow';
    } elseif (in_array($routeName, ['pages.history', 'subscription.manage', 'playlist.manage', 'custom-playlist.show'], true)) {
        $pageRobots = 'noindex, nofollow';
    } elseif ($routeName && \Illuminate\Support\Str::startsWith($routeName, 'user.') && $routeName !== 'user.show') {
        $pageRobots = 'noindex, nofollow';
    }

    $robotsMeta = $robotsOverride !== '' ? $robotsOverride : $pageRobots;
    $adCodeHeader = config('site.ad_code_header', '');
    $adCodeFooter = config('site.ad_code_footer', '');
    $adCodeMobile = config('site.ad_code_mobile', '');
    $adCodeOnclick = config('site.ad_code_onclick', '');
    $adPrerollAds = \App\Support\PrerollAds::parse(config('site.ad_preroll_media_url', ''), config('site.ad_preroll_click_url', ''));
    $adPrerollMediaUrls = array_column($adPrerollAds, 'src');
    $isAuthRoute = $routeName && (
        in_array($routeName, ['login', 'registration'], true) ||
        \Illuminate\Support\Str::startsWith($routeName, ['password.', 'verification.', 'phone.', 'oauth.'])
    );
    $adsEnabled = config('site.ads_enabled', false);
    $hideAds = $adsEnabled && config('site.ads_hide_for_premium', true) && auth()->user()?->is_premium;
    $showAds = $adsEnabled && ! $hideAds && ! $isBackOfficeLayout && ! $isAuthRoute;
    $showMobileAd = $showAds && trim((string) $adCodeMobile) !== '';
    $showOnclickAd = $showAds && config('site.ad_onclick_enabled', false) && trim((string) $adCodeOnclick) !== '';
    $jsonScriptFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
    $primaryValues = sscanf($primaryColor, '#%02x%02x%02x') ?: [255, 61, 97];
    $secondaryValues = sscanf($secondaryColor, '#%02x%02x%02x') ?: [255, 139, 92];
    $accentValues = sscanf($accentColor, '#%02x%02x%02x') ?: [24, 200, 216];
    $primaryRgb = implode(',', $primaryValues);
    $secondaryRgb = implode(',', $secondaryValues);
    $accentRgb = implode(',', $accentValues);
    $mixColor = function (array $base, array $target, float $targetWeight): string {
        $mixed = array_map(
            fn ($baseValue, $targetValue) => max(0, min(255, (int) round($baseValue * (1 - $targetWeight) + $targetValue * $targetWeight))),
            $base,
            $target
        );

        return sprintf('#%02x%02x%02x', $mixed[0], $mixed[1], $mixed[2]);
    };
    $primaryHot = $mixColor($primaryValues, [7, 10, 16], 0.18);
    $primarySoft = implode(',', $primaryValues);
    $currentPath = request()->getPathInfo();
    $appUrl = rtrim(config('app.url'), '/');
    $defaultLocale = config('site.default_locale', config('app.locale', 'en'));
    $canonicalDefault = $appUrl . locale_url($currentPath);
    $canonicalUrl = trim($__env->yieldContent('canonical', $canonicalDefault)) ?: $canonicalDefault;
    $canonicalPath = parse_url($canonicalUrl, PHP_URL_PATH) ?: $currentPath;
    $canonicalPath = '/' . ltrim($canonicalPath, '/');
    $languagesForSeo = config('languages', []);
    $pathSegments = explode('/', trim($canonicalPath, '/'));
    if (!empty($pathSegments[0]) && isset($languagesForSeo[$pathSegments[0]])) {
        array_shift($pathSegments);
        $canonicalPath = '/' . implode('/', $pathSegments);
        $canonicalPath = $canonicalPath === '/' ? '/' : rtrim($canonicalPath, '/');
    }

    // Theme collection v2 (App\Support\ThemeTokens, App\Support\ThemeCss): the theme and colour style (flavor)
    // this page renders. theme_preset is a v2 id or one of the 120 old ids (an old id brings its own flavor);
    // ?theme= / ?theme_preset= (v2 or old id) and ?theme_flavor= preview them for one request.
    $themeQuery = request()->query('theme', request()->query('theme_preset'));
    $themePreviewing = is_string($themeQuery) && \App\Support\ThemeTokens::isKnown($themeQuery);
    $themeFlavorQuery = request()->query('theme_flavor');
    $themeSelection = isset($appearance['_theme_id']) && \App\Support\ThemeTokens::theme((string) $appearance['_theme_id']) !== null
        ? \App\Support\ThemeTokens::resolve((string) $appearance['_theme_id'], isset($appearance['_theme_flavor']) ? (string) $appearance['_theme_flavor'] : null)
        : \App\Support\ThemeTokens::resolve(
            $themePreviewing ? $themeQuery : (string) config('site.theme_preset', $appearance['theme_preset'] ?? ''),
            is_string($themeFlavorQuery) ? $themeFlavorQuery : ($themePreviewing ? null : (string) config('site.theme_flavor', $appearance['theme_flavor'] ?? ''))
        );
    $themeRecord = \App\Support\ThemeTokens::theme($themeSelection['theme']);
    $themeFlavorRecord = $themeRecord !== null ? \App\Support\ThemeTokens::flavor($themeRecord, $themeSelection['flavor']) : null;
    $themeRecipe = (array) ($themeRecord['theme_owned_recipe'] ?? []);
    // The visitor switch and an 'auto' default flip html[data-bs-theme] in the browser: the page then needs
    // the tokens of both modes (inline token CSS and owner colours).
    $themeModes = $theme === 'auto' || $jsThemeMemoryEnabled || $configBool('sidebar_theme_switcher_enabled', true) || (bool) config('site.footer_theme_switcher_enabled', true)
        ? [$resolvedTheme, $resolvedTheme === 'dark' ? 'light' : 'dark']
        : [$resolvedTheme];
    // Theme-owned structure (theme_spec theme_owned_recipe) and the side menu. On public pages WP5's ThemeStructure
    // is the one resolver once installed. Until then body.has-sidebar keeps the v1 rule: the v1 menu
    // (layouts/menus/sidebars/front) is pinned whenever front_sidebar_pinned_enabled is on, and the theme CSS turns
    // a 'hidden' style into a drawer (the theme's --avd-sidebar-w is 0, so nothing is offset); the CSS before the
    // theme collection v2 ignores the style. Back-office pages (v1 admin, account) keep their own full pinned menu
    // and the stored header style, whatever the public theme says.
    $themeStructure = !$isBackOfficeLayout && class_exists(\App\Support\ThemeStructure::class) ? (array) \App\Support\ThemeStructure::htmlAttributes() : [];
    $sidebarPinnedLayout = $themeStructure !== []
        ? \App\Support\ThemeStructure::sidebarPinned()
        : $frontSidebarPinned && !in_array($routeName, ['video.show', 'video.seo-show', 'pages.premium'], true);
    $sidebarStyles = ['full', 'rail', 'floating', 'hidden'];
    $sidebarStyle = $optionValue('sidebar_style', $sidebarStyles, in_array($themeRecord['layout']['sidebar'] ?? '', $sidebarStyles, true) ? $themeRecord['layout']['sidebar'] : 'full');
    $sidebarStyle = match (true) {
        $isBackOfficeLayout => 'full',
        $headerStyle === 'sidebar_rail' => 'rail',
        !$frontSidebarPinned => 'hidden',
        default => $sidebarStyle,
    };
    $themeAttributes = $themeRecord === null ? [] : [
        'data-avd-theme' => $themeSelection['theme'],
        'data-avd-flavor' => $themeSelection['flavor'],
        'data-avd-theme-family' => (string) ($themeRecord['family'] ?? ''),
        'data-avd-header-deco' => (string) ($themeRecipe['header_deco'] ?? 'none'),
        'data-avd-hero-deco' => (string) ($themeRecipe['hero_deco'] ?? 'none'),
        'data-avd-card-frame' => (string) ($themeRecipe['card_frame'] ?? 'flat'),
        'data-avd-chip-style' => (string) ($themeRecipe['chip_style'] ?? 'pill'),
        'data-avd-section-style' => (string) ($themeRecipe['section_style'] ?? 'plain'),
        'data-avd-avatar-shape' => (string) ($themeRecipe['avatar_shape'] ?? 'circle'),
        'data-avd-backdrop' => (string) ($themeRecipe['backdrop'] ?? 'none'),
        'data-avd-sidebar-style' => $sidebarStyle,
    ];
    $themeAttributes = array_replace($themeAttributes, array_intersect_key($themeStructure, $themeAttributes));
    $headerStyleAttribute = (string) ($themeStructure['data-avd-header-style'] ?? $headerStyle);
    // The owner's custom colours: a colour setting of this request that differs from the colour style's own
    // value (AppearanceSettings marks them as _custom_colours where it can). Fitted by the colour engine
    // unless brand_contrast_autofix is off, then printed after the token file with the fonts of another
    // typography pairing and the Vietnamese / Cyrillic display swap. A ?theme= / ?theme_flavor= preview shows
    // the colour style itself, as applying it would (applying a theme replaces the colour settings).
    $themeColourSettings = $themePreviewing || is_string($themeFlavorQuery)
        ? []
        : (isset($appearance['_custom_colours']) && is_array($appearance['_custom_colours'])
            // AppearanceSettings gives [key => colour]; a plain list of keys is accepted too.
            ? array_intersect_key($appearance, array_is_list($appearance['_custom_colours']) ? array_flip($appearance['_custom_colours']) : $appearance['_custom_colours'])
            : array_intersect_key($appearance, \App\Support\ThemeTokens::OWNER_COLOURS));
    foreach (array_keys($themeColourSettings) as $themeColourKey) {
        $themeColourSettings[$themeColourKey] = (string) config('site.' . $themeColourKey, $themeColourSettings[$themeColourKey]);
    }
    $themeColourSettings['brand_contrast_autofix'] = (string) config('site.brand_contrast_autofix', $appearance['brand_contrast_autofix'] ?? '1');
    // "Pure black in dark mode" re-derives the dark tokens on black (the theme's tokens own the background).
    $themeColourSettings['theme_oled_black_enabled'] = $themeOledBlackEnabled ? '1' : '0';
    $themeCustomColours = $themeRecord !== null && $themeFlavorRecord !== null ? \App\Support\ThemeTokens::customColours($themeColourSettings, $themeRecord, $themeFlavorRecord) : [];
    $themeFontPairing = $queryString('typography_pairing')
        ?? ($themePreviewing ? null : (string) config('site.typography_pairing', $appearance['typography_pairing'] ?? ''));
    $themeFonts = $themeRecord !== null
        ? \App\Support\ThemeTokens::fonts($themeRecord, $themeFontPairing, $activeLocale, implode("\n", [(string) $customCss, (string) $customHead, (string) $customJs, (string) $announcementBanner]))
        : null;
    $themeOwnerCss = $themeRecord !== null ? \App\Support\ThemeTokens::ownerOverrides($themeColourSettings, $themeSelection['theme'], $themeSelection['flavor'], $themeModes) : '';
    if (!empty($themeFonts['tokens'])) {
        $themeOwnerCss .= 'html[data-bs-theme]{' . implode(';', array_map(fn ($name, $value) => $name . ':' . $value, array_keys($themeFonts['tokens']), $themeFonts['tokens'])) . '}';
    }
    // The brand colour of this page (browser UI colour, layout scripts): the owner's primary colour or the colour style's.
    $themeBrandColor = $themeRecord !== null
        ? ($themeCustomColours['primary_color']['value'] ?? (string) \App\Support\Color\Oklch::hex((string) \App\Support\ThemeTokens::palette($themeRecord, $resolvedTheme, $themeFlavorRecord)[0]['brand']))
        : $primaryColor;
    $themeBrandRgb = $themeRecord !== null ? \App\Support\Color\Oklch::rgbTriplet($themeBrandColor) : $primaryRgb;
@endphp
<html
    lang="{{ $activeLocale }}"
    data-bs-theme="{{ $resolvedTheme }}"
    data-avd-theme-default="{{ $theme }}"
    data-avd-surface="{{ $surfaceStyle }}"
    data-avd-radius="{{ $radiusScale }}"
    data-avd-motion="{{ $motionLevel }}"
    data-avd-tier="{{ $perfTierServer }}"
    data-avd-tier-src="{{ $perfTierPinned !== null ? 'admin' : 'auto' }}"
    data-avd-tier-mode="{{ $perfTierMode }}"
    data-avd-perf="{{ $perfTierServer === 'lite' ? 'lite' : 'full' }}"
    data-avd-build="{{ $viteManifestHash }}"
    data-avd-density="{{ $contentDensity }}"
    data-avd-typography="{{ $typographyEnabled ? 'on' : 'off' }}"
    data-avd-font="{{ $fontFamilyKey }}"
    data-avd-text-contrast="{{ $textContrast }}"
    data-avd-effects="{{ $effectsEnabled ? 'on' : 'off' }}"
    data-avd-css-animations="{{ $cssAnimationsEnabled ? 'on' : 'off' }}"
    data-avd-css-transitions="{{ $cssTransitionsEnabled ? 'on' : 'off' }}"
    data-avd-hover-effects="{{ $cssHoverEffectsEnabled ? 'on' : 'off' }}"
    data-avd-gradient-effects="{{ $cssGradientEffectsEnabled ? 'on' : 'off' }}"
    data-avd-blur-effects="{{ $cssBlurEffectsEnabled ? 'on' : 'off' }}"
    data-avd-shadow-effects="{{ $cssShadowEffectsEnabled ? 'on' : 'off' }}"
    data-avd-sticky-header="{{ $cssStickyHeaderEnabled ? 'on' : 'off' }}"
    data-avd-shimmer="{{ $cssShimmerEffectEnabled ? 'on' : 'off' }}"
    data-avd-title-accent="{{ $headlineAccentEnabled ? 'on' : 'off' }}"
    data-avd-title-accent-animation="{{ $headlineAccentAnimationEnabled ? 'on' : 'off' }}"
    data-avd-smooth-scroll="{{ $jsSmoothScrollEnabled ? 'on' : 'off' }}"
    data-avd-card-hover="{{ $cardHoverEnabled ? 'on' : 'off' }}"
    data-avd-sidebar-sticky="{{ $watchSidebarStickyEnabled ? 'on' : 'off' }}"
    data-avd-home-widget-motion="{{ $homeWidgetMotionEnabled ? 'on' : 'off' }}"
    data-avd-secondary-pages="{{ $secondaryPageDesignEnabled ? 'on' : 'off' }}"
    data-avd-secondary-hero="{{ $secondaryPageHeroEnabled ? 'on' : 'off' }}"
    data-avd-secondary-surfaces="{{ $secondaryPageSurfacesEnabled ? 'on' : 'off' }}"
    data-avd-secondary-forms="{{ $secondaryPageFormPolishEnabled ? 'on' : 'off' }}"
    data-avd-secondary-tables="{{ $secondaryPageTablePolishEnabled ? 'on' : 'off' }}"
    data-avd-secondary-density="{{ $secondaryPageDensity }}"
    data-avd-secondary-width="{{ $secondaryPageMaxWidth }}"
    data-avd-scrollbar="{{ $scrollbarStyle }}"
    data-avd-upload-modal-style="{{ $uploadModalStyle }}"
    data-avd-mobile-video-columns="{{ $mobileVideoColumns }}"
    data-avd-mobile-video-card-density="{{ $mobileVideoCardDensity }}"
    data-avd-mobile-tap-preview="{{ $mobileTapPreviewEnabled ? 'on' : 'off' }}"
    data-avd-studio-video-preview="{{ $studioVideoPreviewMode }}"
    data-avd-enhanced-selects="{{ $enhancedSelectsEnabled ? 'on' : 'off' }}"
    data-avd-rail-scrollbars="{{ $horizontalRailsHideScrollbarsEnabled ? 'hidden' : 'visible' }}"
    data-avd-scroll-panel-hover-guard="{{ $scrollPanelHoverGuardEnabled ? 'on' : 'off' }}"
    data-avd-oled="{{ $themeOledBlackEnabled ? 'on' : 'off' }}"
    data-avd-header-style="{{ $headerStyleAttribute }}"
    data-avd-card-hover-glow="{{ $videoCardHoverGlowEnabled ? 'on' : 'off' }}"
    data-avd-duration-style="{{ $videoCardDurationStyle }}"
    data-avd-title-lines="{{ $videoCardTitleLines }}"
    data-avd-ambient-glow="{{ $watchAmbientGlowIntensity }}"
    data-avd-search-glow="{{ $searchFocusGlowEnabled ? 'on' : 'off' }}"
    data-avd-creator-ring="{{ $creatorAvatarRingStyle }}"
    data-avd-category-card-style="{{ $categoryCardStyle }}"
    data-avd-back-to-top-style="{{ $backToTopStyle }}"
    data-avd-ripple="{{ $uiClickRippleEnabled ? 'on' : 'off' }}"
    data-avd-theme-preset="{{ $themePreset }}"
    @foreach($themeAttributes as $themeAttribute => $themeAttributeValue)
    {{ $themeAttribute }}="{{ $themeAttributeValue }}"
    @endforeach
    @if($backOfficeMenu) data-avd-back-office @endif
    data-avd-home-layout="{{ $homeLayoutMode }}"
    data-avd-watch-layout="{{ $watchLayoutMode }}"
    data-avd-card-layout="{{ $videoCardLayoutStyle }}"
    data-avd-modal-style="{{ $modalPresentationStyle }}"
    data-avd-motion-engine="{{ $motionEngineLevel }}"
    data-avd-tilt-3d="{{ $interactive3dCardTiltEnabled ? 'on' : 'off' }}"
    data-avd-cinema-diffusion="{{ $cinemaAmbientLightDiffusionEnabled ? 'on' : 'off' }}"
    data-avd-atmosphere="{{ $backgroundAtmosphereEffect }}"
    data-avd-typo-pairing="{{ $themeFonts['pairing'] ?? $typographyPairing }}"
    data-avd-cinema-filter="{{ $videoPlayerCinemaFilter }}"
    data-avd-card-spotlight="{{ $cardSpotlightGlowEnabled ? 'on' : 'off' }}"
    data-avd-border-beam="{{ $borderBeamGlowEnabled ? 'on' : 'off' }}"
    data-avd-border-beam-speed="{{ $borderBeamSpeed }}"
    data-avd-surface-noise="{{ $surfaceNoiseGrainEnabled ? 'on' : 'off' }}"
    data-avd-audio="{{ $uiAudioFeedbackEnabled ? 'on' : 'off' }}"
    data-avd-ambilight="{{ $videoPlayerAmbilightGlowEnabled ? 'on' : 'off' }}"
    data-avd-glassmorphism="{{ $surfaceGlassmorphismEnabled ? 'on' : 'off' }}"
    data-avd-liquid-shimmer="{{ $buttonLiquidShimmerEnabled ? 'on' : 'off' }}"
    data-avd-cursor-trail="{{ $cursorGlowTrailEnabled ? 'on' : 'off' }}"
    dir="{{ $isRtl ? 'rtl' : 'ltr' }}"
>
<head>
    <meta charset="utf-8">
    <title>{{ $fullTitle }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    {{-- Perf plan steps 21-22: early hints from the page content (the first hero image, the VideosList
         chunk and its imports). Pages render before this layout, so their pushes are already here. --}}
    @stack('preload')
    @php
        // The page's early feeds (<x-videos-area early>, see resources/views/components/videos-area.blade.php): one
        // for every width, or one below 768px and one from 768px (`media`); for guests on a home feed its first page
        // (the API's JSON), else (a cold API cache) its first row's pictures.
        $avdEarlyFeeds = $layoutType !== 'admin'
            ? array_values(array_filter((array) request()->attributes->get('avd.early_feeds', []), fn ($feed): bool => is_array($feed) && is_string($feed['url'] ?? null) && $feed['url'] !== ''))
            : [];
        // A feed's first row of thumbnails (4 from 768px, 2 below) is the largest picture of the first screen under a
        // hero without a photo of its own (the ranked lists): it starts loading with the page, at the widths where the
        // feed is early. A hero photo (pushed to 'preload' by its partial) keeps the early bandwidth.
        $avdEarlyFeedThumbs = $avdEarlyFeeds !== [] && ! str_contains($__env->yieldPushContent('preload'), 'as="image"');
        $avdEarlyFeedPreloads = [];
        $avdEarlyFeedPages = [];
        $avdEarlyFeedScript = [];

        foreach ($avdEarlyFeeds as $avdEarlyIndex => $avdEarlyFeed) {
            $avdEarlyFeedPage = is_array($avdEarlyFeed['page'] ?? null) ? $avdEarlyFeed['page'] : null;
            $avdEarlyFeedMedia = is_string($avdEarlyFeed['media'] ?? null) ? $avdEarlyFeed['media'] : null;
            $avdEarlyFeedPageId = $avdEarlyFeedPage !== null ? 'avdFeedEarlyPage'.($avdEarlyIndex > 0 ? $avdEarlyIndex + 1 : '') : null;
            // Those pictures: from the first page, else from the feed's first-row query (videos-area).
            $avdEarlyFeedFirstRow = $avdEarlyFeedPage !== null
                ? array_values(array_filter(array_column($avdEarlyFeedPage['data'], 'thumbnail'), 'is_string'))
                : array_values(array_filter((array) ($avdEarlyFeed['thumbs'] ?? []), 'is_string'));

            foreach (array_slice($avdEarlyFeedFirstRow, 0, $avdEarlyFeedMedia === '(max-width: 767.98px)' ? 2 : 4) as $avdThumbIndex => $avdThumb) {
                $avdEarlyFeedPreloads[] = [$avdThumb, $avdEarlyFeedMedia ?? ($avdThumbIndex >= 2 ? '(min-width: 768px)' : null)];
            }

            if ($avdEarlyFeedPageId !== null) {
                $avdEarlyFeedPages[$avdEarlyFeedPageId] = $avdEarlyFeedPage;
            }

            $avdEarlyFeedScript[] = [(string) $avdEarlyFeed['url'], $avdEarlyFeedMedia, $avdEarlyFeedPageId, $avdEarlyFeedPage === null ? array_slice($avdEarlyFeedFirstRow, 0, 4) : []];
        }

        $avdEarlyFeedArguments = [$avdEarlyFeedScript, (string) app()->getLocale() ?: 'en', (string) csrf_token(), $avdEarlyFeedThumbs];
    @endphp
    @if($avdEarlyFeedThumbs)
        @foreach($avdEarlyFeedPreloads as [$avdThumb, $avdThumbMedia])
    <link rel="preload" as="image" href="{{ $avdThumb }}" fetchpriority="high"@if($avdThumbMedia !== null) media="{{ $avdThumbMedia }}"@endif>
        @endforeach
    @endif
    @foreach($avdEarlyFeedPages as $avdEarlyFeedPageId => $avdEarlyFeedPage)
    <script type="application/json" id="{{ $avdEarlyFeedPageId }}">@json($avdEarlyFeedPage)</script>
    @endforeach
    @if($avdEarlyFeeds !== [])
    <script>
        /* Perf plan step 21: the early feed's first-page API request starts here, in parallel with the CSS
           and JS, instead of after the app boots. It is exactly the request VideosList makes (getVideos ->
           jsonFetch: same URL, headers and credentials, the same /index.php rule). VideosList takes the
           promise from window.__avdFeedEarly[url] once, for the first page only; a missing, failed or
           unexpected response falls back to its own request. When the page carries that first page
           (#avdFeedEarlyPage, guests on a home feed) no request is made: VideosList takes it as its initial
           data (window.__avdFeedShipped[url]) and the promise resolves to it. Without it (a cold API cache) the
           page carries the first row's pictures: VideosList's skeleton shows them (window.__avdFeedThumbs[url],
           with `warm`: eager only where the server skeleton loads them eagerly, i.e. no hero photo and early at
           every width). A feed that is early at one width only (its media query = VideosList's early-media) makes
           no request at the other widths, where it loads like any other feed; a page it carries still answers
           that later first-page request (window.__avdFeedEarly[url]) instead of the API, without rendering it at
           boot. Arguments: [[url, media, page id, thumbs], ...], locale, CSRF token, warm. ES5. */
        (function (w, feeds, locale, token, warm) {
            var early = function (url, media, pageId, thumbs) {
                if (!w.Promise) return;
                var here = !media || !!(w.matchMedia && w.matchMedia(media).matches);
                var registry = w.__avdFeedEarly = w.__avdFeedEarly || {};
                if (registry[url]) return;
                var shipped = pageId && w.document.getElementById(pageId);
                if (shipped) {
                    var page = JSON.parse(shipped.textContent);
                    if (here) (w.__avdFeedShipped = w.__avdFeedShipped || {})[url] = page;
                    registry[url] = w.Promise.resolve(page);
                    return;
                }
                if (!here) return;
                if (thumbs && thumbs.length) (w.__avdFeedThumbs = w.__avdFeedThumbs || {})[url] = {thumbs: thumbs, eager: !!warm && !media};
                if (!w.fetch) return;
                var target = url + (url.indexOf('?') < 0 ? '?' : '&');
                if (target.indexOf('/api/') === 0 && w.location.pathname.indexOf('/index.php') !== -1) target = '/index.php' + target;
                var request = w.fetch(target, {
                    method: 'GET',
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Locale': locale},
                    credentials: 'include'
                }).then(function (response) {
                    if (!response.ok) throw response.status;
                    return response.json();
                });
                request['catch'](function () {});
                registry[url] = request;
                /* Under a hero without a photo of its own (the ranked lists) the feed's first row is the largest
                   picture of the first screen: its thumbnails start loading as soon as the list arrives, while the
                   app still boots; the cards' <img>s then find them in the image cache. */
                if (warm) request.then(function (page) {
                    var items = page && page.data, n = Math.min(items && items.length || 0, w.innerWidth < 768 ? 2 : 4);
                    for (var i = 0; i < n; i++) {
                        if (items[i] && items[i].thumbnail) (new w.Image()).src = items[i].thumbnail;
                    }
                })['catch'](function () {});
            };
            for (var f = 0; f < feeds.length; f++) {
                try {
                    early(feeds[f][0], feeds[f][1], feeds[f][2], feeds[f][3]);
                } catch (e) {}
            }
        }).apply(null, [window].concat(@json($avdEarlyFeedArguments)));
    </script>
    @endif
    <meta name="description" content="{{ $pageDescription }}">
    <meta name="keywords" content="{{ config('site.site_keywords') }}">
    <meta name="language" content="{{ str_replace('_', '-', app()->getLocale()) }}">
    <meta name="copyright" content="{{config('app.url')}}">
    <meta name="robots" content="{{ $robotsMeta }}">
    @if($rating)<meta name="rating" content="{{ $rating }}">@endif
    <meta name="theme-color" content="{{ $themeBrandColor }}">
    <meta name="msapplication-navbutton-color" content="{{ $themeBrandColor }}">
    <meta name="apple-mobile-web-app-status-bar-style" content="{{ $themeBrandColor }}">
    <meta property="og:site_name" content="{{config('app.name')}}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:title" content="{{ $fullTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:image" content="{{ $pageImage }}">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:locale" content="{{ $ogLocale }}">
    @foreach(config('languages', []) as $langCode => $langInfo)
        @if($langCode !== app()->getLocale())
            <meta property="og:locale:alternate" content="{{ str_replace('-', '_', str_replace('_', '-', $langCode)) }}">
        @endif
    @endforeach
    @if(config('site.canonical_enabled', true))
        <link rel="canonical" href="{{ $canonicalUrl }}">
    @endif
    @if(config('site.hreflang_enabled', true))
        @foreach(config('languages', []) as $langCode => $langInfo)
            <link rel="alternate" hreflang="{{ $langCode }}" href="{{ $appUrl . ($langCode === $defaultLocale ? ($canonicalPath === '/' ? '' : $canonicalPath) : '/' . $langCode . ($canonicalPath === '/' ? '' : $canonicalPath)) }}">
        @endforeach
        <link rel="alternate" hreflang="x-default" href="{{ $appUrl . ($canonicalPath === '/' ? '' : $canonicalPath) }}">
    @endif
    @if($fbAppId)<meta property="fb:app_id" content="{{ $fbAppId }}">@endif
    <meta name="twitter:card" content="{{ $twitterCard }}">
    @if($twitterHandle)<meta name="twitter:site" content="{{ $twitterHandle }}">@endif
    <meta name="twitter:title" content="{{ $fullTitle }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">
    <meta name="twitter:image" content="{{ $pageImage }}">
    @if($googleVerif)<meta name="google-site-verification" content="{{ $googleVerif }}">@endif
    <link rel="icon" href="{{ $faviconUrl ? site_asset_url($faviconUrl) : asset('/icons/favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{asset('/icons/apple-touch-icon.png')}}">
    <link rel="manifest" href="/manifest.json">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://upload18.org">
    <link rel="dns-prefetch" href="https://upload18.org">
    <link rel="preconnect" href="https://helvid.com" crossorigin>
    <link rel="dns-prefetch" href="https://helvid.com">
    @php
        // Font budget (theme collection v2, App\Support\ThemeTokens::fonts): public pages request the typography
        // pairing's display and body families with only the styles the theme uses (Vietnamese / Cyrillic pages
        // swap a display face without their glyphs) and families the custom code names; font_family no longer
        // adds one. Admin and account pages keep the full v1 stylesheet (the Theme Studio previews every
        // pairing; some of their views name fonts directly) plus the theme's families.
        if ($themeFonts === null) {
            $avdGoogleFontsUrl = \App\Support\AppearanceSettings::googleFontsUrl(
                $isBackOfficeLayout
                    ? array_keys(\App\Support\AppearanceSettings::googleFontFamilySpecs())
                    : \App\Support\AppearanceSettings::googleFontFamiliesFor(
                        $typographyPairing,
                        $fontFamilyKey,
                        $typographyEnabled,
                        implode("\n", [(string) $customCss, (string) $customHead, (string) $customJs, (string) $announcementBanner])
                    )
            );
        } else {
            $avdGoogleFontsUrl = $isBackOfficeLayout
                ? \App\Support\ThemeTokens::backOfficeFontsUrl(\App\Support\AppearanceSettings::googleFontFamilySpecs(), $themeFonts['families'])
                : $themeFonts['url'];
        }
    @endphp
    @if($avdGoogleFontsUrl)
    <link rel="preload" as="style" href="{{ $avdGoogleFontsUrl }}">
    <link href="{{ $avdGoogleFontsUrl }}" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="{{ $avdGoogleFontsUrl }}"></noscript>
    @endif
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        [x-cloak],
        .modal:not(.show),
        body:not(.modal-open) .cropper-container {
            display: none !important;
        }
    </style>
    <script>
        (function(){
            // Clean up any stale legacy localStorage theme key to prevent desyncing from server presets
            try {
                if (localStorage.getItem('theme')) {
                    localStorage.removeItem('theme');
                }
            } catch(e) {}
            // Perf plan step 25e: the visitor's Live Custom Accent Hue and (with the Daily Aesthetic badge)
            // personal glow are restored here, before the first paint, instead of at the end of the body.
            // Theme previews included, as before. resources/js/layout/core.ts syncs the HUD's picker.
            try {
                var savedAccent = localStorage.getItem('avd_custom_accent');
                if (savedAccent) {
                    var accentHex = savedAccent.replace('#', '');
                    if (accentHex.length === 3) accentHex = accentHex[0] + accentHex[0] + accentHex[1] + accentHex[1] + accentHex[2] + accentHex[2];
                    var accentNum = parseInt(accentHex, 16);
                    document.documentElement.style.setProperty('--avd-brand', savedAccent);
                    document.documentElement.style.setProperty('--avd-brand-rgb', [(accentNum >> 16) & 255, (accentNum >> 8) & 255, accentNum & 255].join(', '));
                }
                @if($dailyBadgeEnabled && $activeDailyDesign)
                var savedGlow = localStorage.getItem('avd_personal_glow');
                if (savedGlow) {
                    document.documentElement.style.setProperty('--avd-brand-soft', 'rgba(' + @json((string) $themeBrandRgb) + ', ' + (savedGlow === 'vivid' ? '0.5' : (savedGlow === 'subtle' ? '0.12' : '0.28')) + ')');
                }
                @endif
            } catch(e) {}
            var urlParams = new URLSearchParams(window.location.search);
            var isUrlTheme = urlParams.has('theme') || urlParams.has('theme_preset') || urlParams.has('theme_mode') || ['light', 'dark'].includes(urlParams.get('mode'));
            if (isUrlTheme) {
                // When previewing via ?theme= or ?theme_preset=, strictly honor the server-rendered theme
                return;
            }
            var memory = {{ $jsThemeMemoryEnabled ? 'true' : 'false' }};
            var root = document.documentElement;
            var themeContext = root.dataset.avdThemePreset + ':' + root.dataset.avdThemeDefault;
            var hasManualTheme = false;
            if (memory) {
                try {
                    var manualTheme = localStorage.getItem('avd_manual_theme');
                    if (localStorage.getItem('avd_manual_theme_context') === themeContext && (manualTheme === 'light' || manualTheme === 'dark')) {
                        root.setAttribute('data-bs-theme', manualTheme);
                        hasManualTheme = true;
                    }
                } catch(e) {}
            }
            if (root.dataset.avdThemeDefault === 'auto' && !hasManualTheme && window.matchMedia) {
                var scheme = window.matchMedia('(prefers-color-scheme: dark)');
                var syncScheme = function() {
                    // A later manual switch retains priority for this preset.
                    try {
                        if (memory && localStorage.getItem('avd_manual_theme_context') === themeContext &&
                            ['light', 'dark'].includes(localStorage.getItem('avd_manual_theme'))) return;
                    } catch(e) {}
                    root.setAttribute('data-bs-theme', scheme.matches ? 'dark' : 'light');
                    window.dispatchEvent(new CustomEvent('theme-changed'));
                };
                syncScheme();
                if (scheme.addEventListener) scheme.addEventListener('change', syncScheme);
                else if (scheme.addListener) scheme.addListener(syncScheme);
            }
        })();
    </script>
    <script>
        window.AVD_SETTINGS = {!! json_encode([
            'effects' => $effectsEnabled,
            'animeEffects' => $jsAnimeEffectsEnabled,
            'themeMemory' => $jsThemeMemoryEnabled,
            'theaterMemory' => $jsTheaterMemoryEnabled,
            'smoothScroll' => $jsSmoothScrollEnabled,
            'copyToast' => $jsCopyToastEnabled,
            'lazyComments' => $jsLazyCommentsEnabled,
            'enhancedSelects' => $enhancedSelectsEnabled,
            'mobileTapPreview' => $mobileTapPreviewEnabled,
            'commentsAllowReplies' => config('site.allow_replies', true),
            'homeWidgetMotion' => [
                'enabled' => $homeWidgetMotionEnabled,
                'style' => $homeWidgetMotionStyle,
                'durationMs' => $homeWidgetMotionDuration,
                'distancePx' => $homeWidgetMotionDistance,
                'staggerMs' => $homeWidgetMotionStagger,
                'cardCascade' => $homeWidgetMotionCascade,
                'autoRun' => $homeWidgetMotionAutoRun,
                'pauseOnHover' => $homeWidgetMotionPauseOnHover,
                'speedPxPerSecond' => $homeWidgetMotionSpeed,
            ],
        ], $jsonScriptFlags) !!};
        // Perf plan step 25b: the layout's page features run from the module bundle
        // (resources/js/layout/core.ts). Until it has run, the names that onclick attributes, page
        // scripts and admin pages call queue their calls here; core.ts replays them. ES5.
        (function (w) {
            var names = ['avdToggleLightsOut', 'avdToggleTheater', 'openDailyAestheticModal', 'openSpotlightSearch', 'closeSpotlightSearch', 'showCopyToast', 'avdToast', 'alertToast', 'avdQuickShare', 'avdQuickSave', 'avdShowAuthPrompt', 'updateWatchLaterCardBadges'];
            for (var i = 0; i < names.length; i++) {
                (function (name) {
                    var stub = function () {
                        (w.__avdQ = w.__avdQ || []).push([name, Array.prototype.slice.call(arguments)]);
                    };
                    stub.avdQueued = true;
                    w[name] = stub;
                })(names[i]);
            }
            // The watch page's theater buttons get their click listener from core.ts; a click before then
            // is queued here.
            document.addEventListener('click', function (e) {
                var target = e.target;
                if (!w.__avdCore && target && target.closest && target.closest('[data-theater-toggle]')) w.avdToggleTheater();
            });
            // The bundle has run by the load event. If it has not (public/build missing or older than these
            // views, or its scripts blocked), the calls above stay queued: say so instead of failing silently.
            w.addEventListener('load', function () {
                if (!w.__avdCore && w.console) w.console.error('AVD: the layout script bundle did not run, so theater mode, lights out, search (Ctrl+K), watch later and the other layout features are off. Upload public/build together with resources/views, then check that /build/assets/*.js loads.');
            });
        })(window);
    </script>
    <script>
        /* Capability tier (perf v2): html[data-avd-tier] = rich | balanced | lite, decided here
           before the first paint and only ever LOWERED afterwards (never raised within a tab
           session). The tier decides HOW effects render; the admin data-avd-* switches still
           decide WHETHER they exist. data-avd-perf stays the legacy alias: 'lite' exactly when
           the tier is lite, else 'full'.
           Precedence: admin (the server pin: admin pages, or performance_tier_mode) > visitor pin
           (localStorage avd_tier_user) > QA pin (?avd_tier=lite|balanced|rich, kept for the tab
           session; ?avd_tier=auto clears it) > session lock (sessionStorage avd_tier, never above
           the static result) > static signals, capped by a confirmed 7-day hint.
           Runtime downgrades: the LoAF governor below (Chromium) and the rAF probe in
           resources/js/motion/tier.ts. Each dispatches a document 'avd:tier' event {tier, why}.
           One signal only pins this tab; the 7-day hint (scoped to this build) is written once
           two independent signals agree, and a clean probe in a later session clears a
           single-signal hint. ES5 only (no ?. or ??); every storage access is guarded. */
        (function () {
            var root = document.documentElement;
            var T = ['lite', 'balanced', 'rich'];
            var WEEK = 6048e5;
            var build = root.getAttribute('data-avd-build') || '';
            function idx(t) { for (var i = 0; i < 3; i++) { if (T[i] === t) return i; } return -1; }
            var cur = idx(root.getAttribute('data-avd-tier'));
            if (cur < 0) cur = 1;
            var src = root.getAttribute('data-avd-tier-src') || 'auto';
            var sid = '';
            function store(area, key, value) {
                try {
                    var s = window[area];
                    if (value === undefined) return s.getItem(key);
                    if (value === null) s.removeItem(key); else s.setItem(key, value);
                } catch (e) {}
                return null;
            }
            function mq(q) { try { return !!(window.matchMedia && window.matchMedia(q).matches); } catch (e) { return false; } }
            function apply(i, s) {
                cur = i;
                src = s;
                root.setAttribute('data-avd-tier', T[i]);
                root.setAttribute('data-avd-tier-src', s);
                root.setAttribute('data-avd-perf', i ? 'full' : 'lite');
            }
            function emit(why) {
                var detail = {tier: T[cur], why: why, src: src}, ev = null;
                try { ev = new CustomEvent('avd:tier', {detail: detail}); } catch (e) {
                    try { ev = document.createEvent('CustomEvent'); ev.initCustomEvent('avd:tier', false, false, detail); } catch (e2) {}
                }
                if (ev) document.dispatchEvent(ev);
            }
            /* Hint: {t, ts, v: build, why, n: 1 pending (one signal) | 2 confirmed, sid}. Only a
               confirmed hint caps the static result; a stale or other-build hint is dropped. */
            function readHint() {
                var h = null;
                try { h = JSON.parse(store('localStorage', 'avd_tier_hint') || 'null'); } catch (e) {}
                if (!h) return null;
                if (h.v !== build || !(Date.now() - h.ts < WEEK) || idx(h.t) < 0) {
                    store('localStorage', 'avd_tier_hint', null);
                    return null;
                }
                return h;
            }
            function writeHint(t, n, why) {
                store('localStorage', 'avd_tier_hint', JSON.stringify({t: t, ts: Date.now(), v: build, why: why, n: n, sid: sid}));
            }
            /* The two independent signals: the other detector in this session (LoAF + rAF probe),
               or a downgrade in an earlier session. The hint keeps only the level both agree on. */
            function remember(why) {
                var sig = store('sessionStorage', 'avd_tier_sig') || '';
                if ((',' + sig + ',').indexOf(',' + why + ',') < 0) {
                    sig = sig ? sig + ',' + why : why;
                    store('sessionStorage', 'avd_tier_sig', sig);
                }
                var first = store('sessionStorage', 'avd_tier_first');
                if (idx(first) < 0) {
                    first = T[cur];
                    store('sessionStorage', 'avd_tier_first', first);
                }
                var h = readHint();
                if (h && h.n > 1) return;
                if (sig.indexOf('loaf') >= 0 && sig.indexOf('probe') >= 0) writeHint(first, 2, why);
                else if (h && h.sid !== sid) writeHint(T[Math.max(idx(h.t), cur)], 2, why);
                else writeHint(T[cur], 1, why);
            }
            window.AVD_TIER = {
                get: function () { return T[cur]; },
                src: function () { return src; },
                at: function (min) { return cur >= idx(min); },
                /* Never raises the tier and never overrides an admin, visitor or QA pin. */
                down: function (to, why) {
                    var i = idx(to);
                    if (i < 0 || i >= cur || src === 'admin' || src === 'user' || src === 'qa') return false;
                    why = String(why || 'probe');
                    apply(i, 'probe');
                    store('sessionStorage', 'avd_tier', T[i]);
                    if (why === 'loaf' || why === 'probe') remember(why);
                    emit(why);
                    return true;
                },
                /* A clean probe: a single-signal hint left by an earlier session is dropped. */
                ok: function () {
                    var h = readHint();
                    if (h && h.n < 2 && h.sid !== sid) store('localStorage', 'avd_tier_hint', null);
                }
            };
            /* Balanced runs its bounded title/chip loops only once the page is idle after load
               (motion.scss); this marks that moment. */
            function bootIdle() {
                var mark = function () { root.setAttribute('data-avd-boot', 'idle'); };
                if (window.requestIdleCallback) window.requestIdleCallback(mark, {timeout: 3000}); else setTimeout(mark, 300);
            }
            if (document.readyState === 'complete') bootIdle(); else window.addEventListener('load', bootIdle);

            function staticTier() {
                var nav = window.navigator || {};
                var mem = +nav.deviceMemory || 0;
                /* Core counts are trusted only where deviceMemory exists (Chromium). */
                var cpu = mem ? (+nav.hardwareConcurrency || 0) : 0;
                var conn = nav.connection || {};
                var et = String(conn.effectiveType || '');
                var uad = nav.userAgentData;
                var coarse = mq('(pointer: coarse)') || mq('(hover: none)') || !!(uad && uad.mobile);
                if (conn.saveData || /2g/.test(et) || mq('(update: slow)') || (mem && mem <= 2) || (cpu && cpu <= 2) || (coarse && cpu && cpu <= 4)) return 0;
                var scr = window.screen || {};
                var dpr = window.devicePixelRatio || 1;
                var bigScreen = (scr.width || 0) * (scr.height || 0) * dpr * dpr >= 8e6;
                if (coarse || (mem && mem <= 4) || (cpu && cpu <= 4) || et === '3g'
                    || mq('(prefers-reduced-motion: reduce)') || root.getAttribute('data-avd-motion') === 'off'
                    || (bigScreen && mem && (mem < 8 || cpu < 8))) return 1;
                return 2;
            }

            /* LoAF governor (Chromium 123+), only for an auto-detected tier above lite. Frames of
               200 ms or more count while the page is visible (not in the second after it becomes
               visible), inside two windows: DOMContentLoaded+1 s to +21 s, and the first scroll to
               +10 s. Three within 10 s drop one tier; five within 10 s, or more than 2.5 s of
               blocking time in one window, drop to lite. */
            function governor() {
                var PO = window.PerformanceObserver;
                var types = PO && PO.supportedEntryTypes;
                if (!types || types.indexOf('long-animation-frame') < 0) return;
                try { if (window.self !== window.top) return; } catch (e) { return; }
                var perf = window.performance;
                var hidden = function () { return document.visibilityState !== 'visible' || document.prerendering === true; };
                var shownAt = hidden() ? Infinity : 0;
                var wins = [];
                var times = [];
                var sinceDrop = 0;
                var scrolled = false;
                var po = null;
                var stop = function () { try { if (po) po.disconnect(); } catch (e) {} };
                function windowOf(t) {
                    for (var i = 0; i < wins.length; i++) { if (t >= wins[i].from && t <= wins[i].to) return wins[i]; }
                    return null;
                }
                function onList(list) {
                    if (cur === 0 || src === 'admin' || src === 'user' || src === 'qa') { stop(); return; }
                    if (hidden()) return;
                    var entries = list.getEntries();
                    for (var i = 0; i < entries.length; i++) {
                        var e = entries[i], t = e.startTime, w = windowOf(t);
                        if (!w || e.duration < 200 || t < shownAt + 1000) continue;
                        w.blocked += e.blockingDuration || 0;
                        times.push(t);
                        sinceDrop++;
                        while (times.length && t - times[0] > 10000) times.shift();
                        if (times.length >= 5 || w.blocked > 2500) {
                            window.AVD_TIER.down('lite', 'loaf');
                            stop();
                            return;
                        }
                        if (Math.min(sinceDrop, times.length) >= 3) {
                            sinceDrop = 0;
                            window.AVD_TIER.down(cur === 2 ? 'balanced' : 'lite', 'loaf');
                            if (cur === 0) { stop(); return; }
                        }
                    }
                }
                try {
                    po = new PO(onList);
                    po.observe({type: 'long-animation-frame', buffered: true});
                } catch (e) { return; }
                function shown() { if (!hidden()) shownAt = perf.now(); }
                document.addEventListener('visibilitychange', shown);
                document.addEventListener('prerenderingchange', shown);
                document.addEventListener('DOMContentLoaded', function () {
                    var t = perf.now();
                    wins.push({from: t + 1000, to: t + 21000, blocked: 0});
                });
                window.addEventListener('scroll', function onScroll() {
                    if (scrolled) return;
                    scrolled = true;
                    window.removeEventListener('scroll', onScroll);
                    var t = perf.now();
                    wins.push({from: t, to: t + 10000, blocked: 0});
                }, {passive: true});
                setTimeout(stop, 300000);
            }

            try {
                sid = store('sessionStorage', 'avd_tier_sid') || '';
                if (!sid) {
                    sid = String(Math.random()).slice(2, 10);
                    store('sessionStorage', 'avd_tier_sid', sid);
                }
                /* The old perf key pinned lite forever. */
                store('localStorage', 'avd_perf', null);
                /* 1. Admin: the server already rendered the pinned tier (admin pages are always rich). */
                if (src === 'admin') { apply(cur, 'admin'); return; }
                var qa = /[?&]avd_tier=(lite|balanced|rich|auto)(?:&|$)/.exec(window.location.search || '');
                if (qa) store('sessionStorage', 'avd_tier_qa', qa[1] === 'auto' ? null : qa[1]);
                /* 2. Visitor pin, 3. QA pin. */
                var pin = idx(store('localStorage', 'avd_tier_user'));
                if (pin >= 0) { apply(pin, 'user'); return; }
                pin = idx(store('sessionStorage', 'avd_tier_qa'));
                if (pin >= 0) { apply(pin, 'qa'); return; }
                /* 5. Static signals, capped by a confirmed hint; 4. the session lock only lowers them. */
                var level = staticTier(), source = 'auto';
                var hint = readHint();
                if (hint && hint.n > 1 && idx(hint.t) < level) { level = idx(hint.t); source = 'hint'; }
                var lock = idx(store('sessionStorage', 'avd_tier'));
                if (lock >= 0 && lock < level) { level = lock; source = 'session'; }
                apply(level, source);
                store('sessionStorage', 'avd_tier', T[level]);
                if (level > 0) governor();
            } catch (e) {}
        })();
    </script>
    @if ($attributes->get('statistics', false) && config('app.statistics_enabled') && !auth()->user()?->is_admin && !in_array(request()->ip(), config('app.ignore_ips')))
        <script defer src="{{ asset('vendor/umami/script.js') }}" data-website-id="ac30e175-0d0d-40bb-b6bb-cb20c56b856a" data-host-url="{{ url('/') }}"></script>
    @endif
    @vite(['resources/js/app.ts'])
    @php
        // Perf plan step 22.4: the Font Awesome solid and regular fonts (header and card icons; font-display
        // block, so icons stay blank until they arrive) are requested with the CSS instead of after it has been
        // parsed and laid out. Step 22.7: a non-English page also preloads its locale chunk, which
        // resources/js/lang/config.ts imports before the components render. Exact build URLs; skipped in hot
        // mode or when the manifest lacks an entry.
        $avdBootPreloads = [];
        $avdBootNonce = null;

        if (! \Illuminate\Support\Facades\Vite::isRunningHot()) {
            foreach (['fa-solid-900', 'fa-regular-400'] as $avdIconFont) {
                try {
                    // The built CSS names its fonts relative to itself (vite.config.ts renderBuiltUrl), so the
                    // URL it requests is the asset() URL on every install type: /build/assets/<file>,
                    // /public/build/assets/<file> (root install) or the CDN ASSET_URL. A preload of any other
                    // URL would be a second, unused download.
                    $avdBootPreloads[] = ['font', \Illuminate\Support\Facades\Vite::asset('node_modules/@fortawesome/fontawesome-free/webfonts/' . $avdIconFont . '.woff2')];
                } catch (\Throwable) {
                }
            }

            $avdPageLocale = (string) app()->getLocale();

            if ($avdPageLocale !== 'en' && preg_match('/^[a-z]{2}$/', $avdPageLocale)) {
                try {
                    $avdBootPreloads[] = ['module', \Illuminate\Support\Facades\Vite::asset('resources/js/lang/' . $avdPageLocale . '.ts')];
                } catch (\Throwable) {
                }
            }

            $avdBootNonce = \Illuminate\Support\Facades\Vite::cspNonce();
        }
    @endphp
    @foreach($avdBootPreloads as [$avdBootPreloadType, $avdBootPreloadUrl])
        @if($avdBootPreloadType === 'font')
    <link rel="preload" as="font" type="font/woff2" href="{{ $avdBootPreloadUrl }}" crossorigin>
        @else
    <link rel="modulepreload" as="script" href="{{ $avdBootPreloadUrl }}"@if($avdBootNonce) nonce="{{ $avdBootNonce }}"@endif>
        @endif
    @endforeach
    <style id="dynamic-theme">
        :root {
            --avd-grid-columns: {{ $gridColumns }};
            --avd-font-size-base: {{ $baseFontSize }}px;
            --avd-font-scale-ui: {{ $uiFontRatio }};
            --avd-font-scale-heading: {{ $headingFontRatio }};
            --avd-font-scale-card-title: {{ $cardTitleFontRatio }};
            @if($themeRecord !== null)
            {{-- Compatibility aliases: names older rules and partials read, resolved from the theme tokens (the
                 token file below defines them per mode on html[data-bs-theme]). The audit G3 names (--avd-primary(-rgb),
                 --avd-surface-rgb, --avd-dark-surface-rgb, --avd-surface-card, --avd-cyan(-rgb)) are tokens themselves,
                 so the player colours follow the theme without touching its CSS. --avd-theme-primary(-rgb) stay
                 server-only copies (--avd-primary, not --avd-brand: the visitor accent picker rewrites that one). --}}
            --avd-font-family-base: var(--avd-font-body);
            --avd-font-heading: var(--avd-font-display);
            --avd-brand-secondary: var(--avd-brand-2);
            --avd-brand-secondary-rgb: var(--avd-brand-2-rgb);
            --avd-brand-2-soft: rgba(var(--avd-brand-2-rgb), 0.15);
            --avd-cyan-soft: rgba(var(--avd-cyan-rgb), 0.15);
            --avd-accent-soft: rgba(var(--avd-accent-rgb), 0.15);
            --avd-dark-bg: var(--avd-bg);
            --avd-dark-surface: var(--avd-surface-card);
            --avd-light-bg: var(--avd-bg);
            --avd-light-surface: var(--avd-surface-card);
            --avd-theme-primary: var(--avd-primary);
            --avd-theme-primary-rgb: var(--avd-primary-rgb);
            --avd-theme-accent-rgb: var(--avd-accent-rgb);
            {{-- Bootstrap 5.3 colours plain links through these triplets (a { color: rgba(var(--bs-link-color-rgb)) }).
                 The token files set --bs-link-color(-hover) = --bs-primary, the brand ink fitted to 4.5:1. --}}
            --bs-link-color-rgb: var(--bs-primary-rgb);
            --bs-link-hover-color-rgb: var(--bs-primary-rgb);
            @else
            {{-- No theme catalogue: the stored colour settings, as before the theme collection v2. --}}
            --bs-primary: {{ $primaryColor }};
            --bs-primary-rgb: {{ $primaryRgb }};
            --bs-link-color: {{ $primaryColor }};
            --bs-link-hover-color: {{ $primaryColor }};
            --avd-brand: {{ $primaryColor }};
            --avd-brand-rgb: {{ $primaryRgb }};
            --avd-brand-hot: {{ $primaryHot }};
            --avd-brand-2: {{ $secondaryColor }};
            --avd-brand-2-rgb: {{ $secondaryRgb }};
            --avd-brand-secondary: {{ $secondaryColor }};
            --avd-brand-secondary-rgb: {{ $secondaryRgb }};
            --avd-cyan: {{ $accentColor }};
            --avd-cyan-rgb: {{ $accentRgb }};
            --avd-accent: {{ $accentColor }};
            --avd-accent-rgb: {{ $accentRgb }};
            --avd-brand-soft: rgba({{ $primaryRgb }}, 0.15);
            --avd-brand-2-soft: rgba({{ $secondaryRgb }}, 0.15);
            --avd-cyan-soft: rgba({{ $accentRgb }}, 0.15);
            --avd-accent-soft: rgba({{ $accentRgb }}, 0.15);
            --avd-font-family-base: {!! $fontFamilyStack !!};
            --avd-font-heading: {!! $activeTypoPairing['heading_font'] ?? "'Outfit', sans-serif" !!};
            --avd-font-body: {!! $activeTypoPairing['body_font'] ?? "'Plus Jakarta Sans', sans-serif" !!};
            --avd-dark-bg: {{ $darkBgColor }};
            --avd-dark-surface: {{ $darkSurfaceColor }};
            --avd-light-bg: {{ $lightBgColor }};
            --avd-light-surface: {{ $lightSurfaceColor }};
            {{-- Perf plan step 24: server-only copies of the theme colours for the rules that now live in
                 resources/css/layout-theme-*.css (they used to print these Blade values). Not --avd-brand-rgb:
                 the visitor accent picker rewrites that one, and these rules never followed it. --}}
            --avd-theme-primary: {{ $primaryColor }};
            --avd-theme-primary-rgb: {{ $primaryRgb }};
            --avd-theme-accent-rgb: {{ $accentRgb }};
            @endif
        }
    </style>
    @if($themeRecord !== null)
    {{-- Theme collection v2: the active theme's token file (resources/css/themes/<id>.css: both modes keyed on
         html[data-bs-theme], + [dir=rtl]) and its colour style's file (<id>/<flavor>.css, keyed on [data-avd-flavor]),
         before the theme CSS that reads them. AVD_THEME_CSS_INLINE prints only this page's blocks inline instead
         (App\Support\ThemeCss::themeTags). --}}
    {{ \App\Support\ThemeCss::themeTags($themeSelection['theme'], $resolvedTheme, $themeSelection['flavor'], count($themeModes) > 1, $isRtl) }}
    @if($themeOwnerCss !== '')
    {{-- The owner's custom colours (engine-fitted) and fonts of another typography pairing / the display swap. --}}
    <style id="avd-theme-owner">{!! str_ireplace('</style', '<\/style', $themeOwnerCss) !!}</style>
    @endif
    @if($isBackOfficeLayout)
    {{-- The v1 admin and account menu is a full pinned column with text labels: not the public theme's rail or
         hidden (0px) side menu width. --}}
    <style id="avd-back-office-menu">html[data-bs-theme][data-avd-theme]{--avd-sidebar-w:260px}</style>
    @endif
    @endif
    {{-- Perf plan step 24: the static theme CSS that used to fill the <style> above is in
         resources/css/layout-theme-a.css and -b.css, served as cached build files (Vite input,
         whitespace-only minify). The cascade order is the old one: main.css, the :root tokens,
         theme-a, #avd-custom-css, theme-b, #avd-theme-tail. Blade-conditional rules stay inline;
         three of them now sit next to a neighbouring static run instead of before or after it,
         which is order-neutral (see the notes at each one). AVD_THEME_CSS_INLINE=true
         (config app.theme_css_inline) prints the same CSS inline at the same two places, and a
         build without these entries falls back to that too (App\Support\ThemeCss). --}}
    {{ \App\Support\ThemeCss::tags('resources/css/layout-theme-a.css', 'avd-theme-a') }}
    <style id="avd-custom-css">
        {{-- The admin custom CSS used to sit deep inside one stylesheet, where a leading @import,
             @namespace or @charset is ignored. This empty rule keeps it that way. --}}
        :root{}
        {{-- Motion Engine Physics. "off" snaps every animation to its end state in one
           iteration (before, infinite loops strobed at a 1ms period forever). Functional
           spinners and progress indicators keep running. The universal rule is emitted
           only when the mode is selected, so default pages never test it. --}}
        {{-- It used to come before the last static run of theme-a (motion-engine presets, cinema
             diffusion, the click ripple...); none of those sets an animation or transition longhand
             with !important at its specificity (0,5,1)/(0,5,2), so following them changes nothing. --}}
        @if($motionEngineLevel === 'off')
        html[data-avd-motion-engine="off"] *:not(.spinner-border):not(.spinner-grow):not(.loader):not([role="progressbar"]),
        html[data-avd-motion-engine="off"] *:not(.spinner-border):not(.spinner-grow):not(.loader):not([role="progressbar"])::before,
        html[data-avd-motion-engine="off"] *:not(.spinner-border):not(.spinner-grow):not(.loader):not([role="progressbar"])::after {
            animation-duration: 1ms !important;
            animation-delay: 0s !important;
            animation-iteration-count: 1 !important;
            transition-duration: 1ms !important;
        }
        @endif
        {{ $customCss }}
        {{-- Master switches. Each universal (*) rule is emitted only when its switch is
             off: <html> always carries the attribute (value "on"), so an always-present
             '[data-avd-...="off"] *' selector was still tested against every element and
             pseudo-element on every style recalc. Settings render per request, so admin
             previews still get the right rules. --}}
        @if(!$jsSmoothScrollEnabled)
        html[data-avd-smooth-scroll="off"],
        html[data-avd-smooth-scroll="off"] * {
            scroll-behavior: auto !important;
        }
        @endif
        @if($motionLevel === 'off')
        html[data-avd-motion="off"],
        html[data-avd-motion="off"] * {
            scroll-behavior: auto !important;
        }
        html[data-avd-motion="off"] *,
        html[data-avd-motion="off"] *::before,
        html[data-avd-motion="off"] *::after {
            animation-duration: 1ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 1ms !important;
        }
        @endif
        @if(!$cssAnimationsEnabled)
        html[data-avd-css-animations="off"] *,
        html[data-avd-css-animations="off"] *::before,
        html[data-avd-css-animations="off"] *::after {
            animation: none !important;
        }
        @endif
        @if(!$cssTransitionsEnabled)
        html[data-avd-css-transitions="off"] *,
        html[data-avd-css-transitions="off"] *::before,
        html[data-avd-css-transitions="off"] *::after {
            transition: none !important;
        }
        @endif
        {{-- The blur and shadow switches used to follow the hover/gradient/title-accent switches (now
             the start of theme-b), which set none of backdrop-filter, box-shadow or text-shadow. --}}
        @if(!$cssBlurEffectsEnabled)
        html[data-avd-blur-effects="off"] *,
        html[data-avd-blur-effects="off"] *::before,
        html[data-avd-blur-effects="off"] *::after {
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }
        @endif
        @if(!$cssShadowEffectsEnabled)
        html[data-avd-shadow-effects="off"] *,
        html[data-avd-shadow-effects="off"] *::before,
        html[data-avd-shadow-effects="off"] *::after {
            box-shadow: none !important;
            text-shadow: none !important;
        }
        @endif
    </style>
    {{ \App\Support\ThemeCss::tags('resources/css/layout-theme-b.css', 'avd-theme-b') }}
    <style id="avd-theme-tail">
        {{-- Users who ask for reduced transparency get no remaining blur at all. Each selector matches or
           beats the specificity of the rule it cancels and comes later in the source (layout-theme-a/b
           also drop their own allowed blurs under this query). --}}
        @media (prefers-reduced-transparency: reduce) {
            html[data-bs-theme][data-avd-header-style][data-avd-blur-effects] header.avd-header,
            html[data-bs-theme][data-avd-header-style][data-avd-blur-effects] nav.avd-header,
            html[data-avd-glassmorphism][data-bs-theme][data-avd-surface][data-avd-blur-effects] .dropdown-menu,
            html[data-avd-glassmorphism][data-bs-theme][data-avd-blur-effects] .offcanvas,
            html[data-avd-glassmorphism][data-bs-theme] .avd-sidebar,
            html[data-avd-blur-effects][data-bs-theme] #responsive-sidebar.avd-sidebar,
            html[data-avd-card-layout="card_glass_overlay"] .avd-video-card .avd-card-body,
            html .shortcuts-overlay,
            html .modal-backdrop,
            html .modal[style*="backdrop-filter"],
            html[data-bs-theme][data-avd-surface][data-avd-modal-style] .modal .modal-content,
            html .avd-shelf-track-container:hover .avd-shelf-paddle,
            html .avd-shelf-row-wrapper:hover .avd-shelf-paddle,
            html[data-avd-tier="rich"][data-avd-duration-style] .avd-video-card:hover .avd-duration,
            html[data-avd-tier="rich"][data-avd-duration-style] .avd-video-card:hover .duration,
            html[data-avd-tier="rich"] .avd-video-card:hover .avd-card-menu .btn {
                -webkit-backdrop-filter: none !important;
                backdrop-filter: none !important;
            }
        }
    </style>
    @if(config('site.website_schema_enabled', true))
        <script type="application/ld+json">{!! json_encode($seoService->websiteSchema(), $jsonScriptFlags) !!}</script>
    @endif
    @if(config('site.search_action_schema_enabled', true))
        <script type="application/ld+json">{!! json_encode($seoService->searchActionSchema(), $jsonScriptFlags) !!}</script>
    @endif
    @stack('structured-data')
    @stack('seo-head')
    @if($customHead){!! $customHead !!}@endif
</head>
<body @class(['avd-app', 'has-bottom-nav' => $mobileBottomNavEnabled && !$isBackOfficeLayout, 'avd-admin' => $attributes->get('type') === 'admin', 'has-sidebar' => $sidebarPinnedLayout, 'has-mobile-ad' => $showMobileAd, 'has-hero-zone' => $pageHasHeroZone])>
    <div class="page-wrapper">
        @if($showAds && $adCodeHeader)
            <div id="ad-slot-header" class="ad-slot ad-slot-header" role="complementary" aria-label="{{__('Advertisement')}}">{!! $adCodeHeader !!}</div>
        @endif

        @if($announcementBanner)
            <div class="alert alert-warning alert-dismissible rounded-0 mb-0 py-2 text-center" style="font-size:0.9em;">
                {!! $announcementBanner !!}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @include('layouts.menus.header')

        {{-- The --sidebar-top updater runs from resources/js/layout/core.ts (perf plan step 25b). --}}

        <main class="avd-main position-relative">
            <div class="avd-shell d-flex">
                @include('layouts.menus.sidebars.'.$sidebar, ['type' => $type ?? null])
                <div id="main-container" class="container-fluid my-3 @yield('class')" style="@yield('style')" data-avd-main>
                    {{$slot}}
                    @yield('content')
                </div>
            </div>

            <div class="toast-container pe-4 end-0 position-fixed" style="top:68px;">
                <div id="toast" class="toast align-items-center bg-danger text-white" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="10000">
                    <div class="d-flex align-items-center justify-content-between mr-2">
                        <div class="toast-body d-flex align-items-center gap-3">
                            <div class="rounded-4 px-2 py-1 bg-danger text-white">
                                <i class="fa-2x fa-solid fa-triangle-exclamation"></i>
                            </div>
                            <strong id="toast-message" class="text-break"></strong>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="{{__('Close')}}"></button>
                    </div>
                </div>
            </div>

            @if (Auth::check())
                @include('users.videos.modals.upload')
                @include('layouts.menus.account')
            @endif
        </main>

        @include('layouts.menus.footer')

        @if($dailyBadgeEnabled && $activeDailyDesign)
        {{-- The optional atmosphere canvas is emitted once below when its setting is enabled. --}}

        <div class="avd-daily-design-pill-dock position-fixed bottom-0 end-0 m-3 z-3 d-none d-md-block">
            <button type="button" class="avd-daily-badge d-flex align-items-center gap-2 px-3 py-2 rounded-pill shadow-lg"
                 id="dailyThemeBadge"
                 onclick="window.openDailyAestheticModal && window.openDailyAestheticModal()"
                 aria-label="{{ __('Open Daily Aesthetic Identity HUD') }}"
                 style="background: rgba(15, 23, 42, 0.88); border: 1px solid rgba(255, 255, 255, 0.15); font-size: 0.78rem; transition: transform 0.2s ease, box-shadow 0.2s ease; cursor: pointer;"
                 title="{{ __('Click to view Daily Aesthetic Identity HUD (Press D)') }}">
                <span class="rounded-circle d-inline-block" style="width: 10px; height: 10px; background: var(--avd-brand); box-shadow: 0 0 10px var(--avd-brand);"></span>
                <span class="text-white-50">{{ __('Today:') }}</span>
                <strong class="text-white fw-bold">{{ $activeDailyDesign['name'] }}</strong>
                <span class="badge rounded-pill px-2 py-0.5" style="background: rgba(var(--avd-brand-rgb, 255, 61, 97), 0.22); color: var(--avd-brand); font-size: 0.7rem; font-weight: 700;">
                    {{ __('Day') }} {{ $activeDailyDesign['day'] }}/{{ $dailyDaysInYear }}
                </span>
                @if(!empty($activeDailyDesign['holiday']))
                    <span class="badge rounded-pill bg-warning text-dark px-2 py-0.5" style="font-size: 0.68rem; font-weight: 700;">
                        <i class="fa-solid fa-star me-1"></i>{{ $activeDailyDesign['holiday'] }}
                    </span>
                @endif
            </button>
        </div>

        <!-- Daily Aesthetic Identity Master HUD Modal -->
        <div class="modal fade" id="dailyAestheticModal" tabindex="-1" aria-labelledby="dailyAestheticModalLabel" aria-hidden="true" style="backdrop-filter: blur(8px);">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content text-white border-0 shadow-2xl" style="background: rgba(15, 20, 32, 0.95); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8), 0 0 30px rgba(var(--avd-brand-rgb, 255, 61, 97), 0.2);">
                    <div class="modal-header border-0 pb-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge rounded-pill px-3 py-1 mb-2" style="background: rgba(var(--avd-brand-rgb, 255, 61, 97), 0.2); color: var(--avd-brand); border: 1px solid rgba(var(--avd-brand-rgb, 255, 61, 97), 0.4); font-size: 0.75rem; font-weight: 700;">
                                <i class="fa-solid fa-calendar-day me-1"></i> {{ __('365-DAY AESTHETIC IDENTITY') }}
                            </span>
                            <h4 class="modal-title fw-bold text-white mb-0" id="dailyAestheticModalLabel">
                                {{ $activeDailyDesign['name'] }}
                            </h4>
                            <div class="text-white-50 small mt-1">
                                {{ __('Day') }} {{ $activeDailyDesign['day'] }}/{{ $dailyDaysInYear }} &bull; {{ date('F j, Y') }}
                                @if(!empty($activeDailyDesign['holiday']))
                                    &bull; <span class="text-warning fw-semibold"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>{{ $activeDailyDesign['holiday'] }}</span>
                                @endif
                            </div>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>

                    <div class="modal-body px-4 py-3">
                        <!-- Mood Quote Banner -->
                        <div class="p-3 rounded-3 mb-4" style="background: rgba(255, 255, 255, 0.04); border-left: 3px solid var(--avd-brand);">
                            <p class="fst-italic text-white-75 mb-0 small" style="line-height: 1.5;">
                                &ldquo;{{ $activeDailyDesign['mood'] ?? __('A distinct and inspiring visual experience crafted uniquely for today.') }}&rdquo;
                            </p>
                        </div>

                        <!-- Active Color Palette Capsules -->
                        <div class="mb-4">
                            <label class="form-label text-white-50 text-uppercase fw-bold small" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                                {{ __('Active Daily Color Palette (Click to copy hex)') }}
                            </label>
                            <div class="row g-2">
                                <div class="col-4">
                                    <button type="button" class="p-2 rounded-3 text-center cursor-pointer transition-all hover-scale w-100"
                                         aria-label="{{ __('Copy Primary Hex') }}"
                                         onclick="window.copyColorHex('{{ $activeDailyDesign['primary'] }}', 'Primary')"
                                         style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer;">
                                        <div class="rounded-2 mb-1 mx-auto" style="width: 100%; height: 28px; background: {{ $activeDailyDesign['primary'] }}; box-shadow: 0 0 10px {{ $activeDailyDesign['primary'] }}44;"></div>
                                        <div class="fw-bold small">{{ __('Primary') }}</div>
                                        <div class="text-white-50 font-monospace" style="font-size: 0.72rem;">{{ $activeDailyDesign['primary'] }}</div>
                                    </button>
                                </div>
                                <div class="col-4">
                                    <button type="button" class="p-2 rounded-3 text-center cursor-pointer transition-all hover-scale w-100"
                                         aria-label="{{ __('Copy Secondary Hex') }}"
                                         onclick="window.copyColorHex('{{ $activeDailyDesign['secondary'] }}', 'Secondary')"
                                         style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer;">
                                        <div class="rounded-2 mb-1 mx-auto" style="width: 100%; height: 28px; background: {{ $activeDailyDesign['secondary'] }}; box-shadow: 0 0 10px {{ $activeDailyDesign['secondary'] }}44;"></div>
                                        <div class="fw-bold small">{{ __('Secondary') }}</div>
                                        <div class="text-white-50 font-monospace" style="font-size: 0.72rem;">{{ $activeDailyDesign['secondary'] }}</div>
                                    </button>
                                </div>
                                <div class="col-4">
                                    <button type="button" class="p-2 rounded-3 text-center cursor-pointer transition-all hover-scale w-100"
                                         aria-label="{{ __('Copy Accent Hex') }}"
                                         onclick="window.copyColorHex('{{ $activeDailyDesign['accent'] }}', 'Accent')"
                                         style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer;">
                                        <div class="rounded-2 mb-1 mx-auto" style="width: 100%; height: 28px; background: {{ $activeDailyDesign['accent'] }}; box-shadow: 0 0 10px {{ $activeDailyDesign['accent'] }}44;"></div>
                                        <div class="fw-bold small">{{ __('Accent') }}</div>
                                        <div class="text-white-50 font-monospace" style="font-size: 0.72rem;">{{ $activeDailyDesign['accent'] }}</div>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Personal Glow & Soundscape Controls -->
                        <!-- Personal Glow & Soundscape Controls -->
                        <div class="row g-3 mb-4">
                            <div class="col-12 col-md-6">
                                <label class="form-label text-white-50 text-uppercase fw-bold small" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                                    {{ __('Personal Glow Intensity') }}
                                </label>
                                <div class="btn-group w-100" role="group">
                                    <button type="button" class="btn btn-sm btn-outline-secondary text-white py-1.5 glow-btn" data-glow="subtle" onclick="window.setPersonalGlow('subtle')">{{ __('Subtle') }}</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary text-white py-1.5 glow-btn active" data-glow="balanced" onclick="window.setPersonalGlow('balanced')">{{ __('Balanced') }}</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary text-white py-1.5 glow-btn" data-glow="vivid" onclick="window.setPersonalGlow('vivid')">{{ __('Vivid') }}</button>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label text-white-50 text-uppercase fw-bold small" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                                    {{ __('Soundscape Synthesis') }}
                                </label>
                                <button type="button" class="btn btn-sm btn-outline-info text-white w-100 py-1.5" id="avdSoundToggleBtn" onclick="window.AvdAudio && window.AvdAudio.toggleSound()">
                                    <i class="fa-solid fa-volume-high me-1"></i> <span id="avdSoundToggleText">{{ __('Audio FX: ON') }}</span>
                                </button>
                            </div>
                        </div>

                        <!-- Live Custom Accent Hue Tinting -->
                        <div class="p-3 rounded-3 mb-4" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label class="form-label text-white-50 text-uppercase fw-bold small mb-0" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                                    <i class="fa-solid fa-palette text-warning me-1"></i> {{ __('Live Custom Accent Hue') }}
                                </label>
                                <button type="button" class="btn btn-xs btn-link text-white-50 text-decoration-none p-0" onclick="window.resetCustomAccent && window.resetCustomAccent()" style="font-size: 0.72rem;">
                                    <i class="fa-solid fa-rotate-left me-1"></i> {{ __('Reset to Today') }}
                                </button>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <input type="color" id="avdCustomColorPicker" class="form-control form-control-color bg-transparent border-0 p-0" value="{{ $activeDailyDesign['primary'] }}" style="width: 36px; height: 36px; cursor: pointer; border-radius: 8px;" onchange="window.setCustomAccent && window.setCustomAccent(this.value)" oninput="window.setCustomAccent && window.setCustomAccent(this.value)">
                                <div class="flex-grow-1">
                                    <div class="small text-white fw-semibold" id="avdCustomColorName">{{ $activeDailyDesign['name'] }}</div>
                                    <div class="small text-white-50" style="font-size: 0.75rem;">{{ __('Instantly recolor buttons, glows, and badges across the entire site') }}</div>
                                </div>
                            </div>
                        </div>

                        @if($tomorrowDailyDesign)
                        <!-- Tomorrow Sneak Peek -->
                        <div class="p-3 rounded-3 d-flex align-items-center justify-content-between" style="background: rgba(255,255,255,0.03); border: 1px dashed rgba(255,255,255,0.15);">
                            <div>
                                <span class="text-white-50 small">{{ __('Tomorrow Sneak Peek:') }}</span>
                                <strong class="text-white d-block">{{ $tomorrowDailyDesign['name'] }}</strong>
                            </div>
                            <a href="?preview_day={{ $tomorrowDailyDesign['day'] }}" class="btn btn-xs btn-outline-warning text-nowrap">
                                <i class="fa-solid fa-eye me-1"></i> {{ __('Preview Tomorrow') }}
                            </a>
                        </div>
                        @endif
                    </div>

                    <div class="modal-footer border-0 pt-0 pb-4 px-4 d-flex justify-content-between">
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-primary text-white px-3" onclick="window.generateAestheticCard()">
                                <i class="fa-solid fa-image me-1"></i> {{ __('Export Wallpaper Card') }}
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-warning px-3" onclick="window.avdSurpriseMe()">
                                <i class="fa-solid fa-dice me-1"></i> {{ __('Surprise Me') }}
                            </button>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary text-white" data-bs-dismiss="modal">
                            {{ __('Close') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <!-- Global Spotlight Command Palette Search Modal (Ctrl+K / /) -->
        <div class="modal fade" id="avdSpotlightModal" tabindex="-1" aria-labelledby="avdSpotlightModalLabel" aria-hidden="true" style="backdrop-filter: blur(14px);">
            <div class="modal-dialog modal-dialog-centered" style="max-width: 620px;">
                <div class="modal-content text-white border-0 shadow-2xl" style="background: rgba(12, 16, 26, 0.96); border: 1px solid rgba(255, 255, 255, 0.18); border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px rgba(0, 0, 0, 0.9), 0 0 35px rgba(var(--avd-brand-rgb, 255, 61, 97), 0.25);">
                    <div class="p-3 border-bottom d-flex align-items-center gap-3" style="border-color: rgba(255, 255, 255, 0.1) !important;">
                        <i class="fa-solid fa-magnifying-glass text-info fs-5 ps-2"></i>
                        <form action="{{ (function_exists('locale_url') ? locale_url('/search') : url('/search')) }}" method="GET" class="flex-grow-1 mb-0" id="avdSpotlightForm">
                            <input type="text" name="q" id="avdSpotlightInput" class="form-control bg-transparent border-0 text-white shadow-none ps-0" placeholder="{{ __('Type to search videos, tags, channels...') }}" autocomplete="off" style="font-size: 1.1rem;">
                        </form>
                        <kbd class="text-white-50 border border-secondary px-2 py-1 rounded small" style="background: rgba(255,255,255,0.06); font-size: 0.72rem;">ESC</kbd>
                    </div>
                    <div class="p-3" style="max-height: 420px; overflow-y: auto;">
                        <div class="text-white-50 text-uppercase fw-bold mb-2" style="font-size: 0.7rem; letter-spacing: 0.5px;">
                            {{ __('Trending Searches & Channels') }}
                        </div>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            {{-- only suggestions that find videos on this site (catalogs differ per install) --}}
                            @foreach (\App\Support\SpotlightChips::all() as $spotlightChip)
                                <a href="{{ (function_exists('locale_url') ? locale_url('/search?q=' . urlencode($spotlightChip['q'])) : url('/search?q=' . urlencode($spotlightChip['q']))) }}" class="btn btn-sm btn-outline-secondary text-white rounded-pill px-3 py-1" style="font-size: 0.8rem;">
                                    <i class="fa-solid fa-hashtag {{ $spotlightChip['icon'] }} me-1"></i> {{ $spotlightChip['label'] }}
                                </a>
                            @endforeach
                            <a href="{{ (function_exists('locale_url') ? locale_url('/categories') : url('/categories')) }}" class="btn btn-sm btn-outline-secondary text-white rounded-pill px-3 py-1" style="font-size: 0.8rem;">
                                <i class="fa-solid fa-shapes text-success me-1"></i> {{ __('All Categories') }}
                            </a>
                        </div>

                        <div class="text-white-50 text-uppercase fw-bold mb-2" style="font-size: 0.7rem; letter-spacing: 0.5px;">
                            {{ __('Quick Navigation & Tools') }}
                        </div>
                        <div class="d-flex flex-column gap-1">
                            <a href="{{ (function_exists('locale_url') ? locale_url('/') : url('/')) }}" class="p-2 rounded-3 text-white text-decoration-none d-flex align-items-center justify-content-between" style="background: rgba(255,255,255,0.03); transition: background 0.15s ease;">
                                <span class="d-flex align-items-center gap-2.5">
                                    <i class="fa-solid fa-house text-muted"></i>
                                    <span>{{ __('Home Billboard & Trending Shelves') }}</span>
                                </span>
                                <kbd class="text-white-50 small">↵</kbd>
                            </a>
                            <a href="{{ (function_exists('locale_url') ? locale_url('/categories') : url('/categories')) }}" class="p-2 rounded-3 text-white text-decoration-none d-flex align-items-center justify-content-between" style="background: rgba(255,255,255,0.03); transition: background 0.15s ease;">
                                <span class="d-flex align-items-center gap-2.5">
                                    <i class="fa-solid fa-shapes text-muted"></i>
                                    <span>{{ __('Browse Categories Catalog') }}</span>
                                </span>
                                <kbd class="text-white-50 small">↵</kbd>
                            </a>
                            <button type="button" onclick="bootstrap.Modal.getInstance(document.getElementById('avdSpotlightModal'))?.hide(); window.openDailyAestheticModal && window.openDailyAestheticModal();" class="p-2 rounded-3 text-white text-decoration-none d-flex align-items-center justify-content-between w-100 border-0" style="background: rgba(255,255,255,0.03); transition: background 0.15s ease;">
                                <span class="d-flex align-items-center gap-2.5">
                                    <i class="fa-solid fa-wand-magic-sparkles text-warning"></i>
                                    <span>{{ __('Open Daily Aesthetic Identity HUD') }}</span>
                                </span>
                                <kbd class="text-white-50 small">D</kbd>
                            </button>
                            <button type="button" onclick="bootstrap.Modal.getInstance(document.getElementById('avdSpotlightModal'))?.hide(); var o=document.getElementById('shortcuts-overlay'); if(o){o.classList.add('active'); window.AvdAudio&&window.AvdAudio.playClick();}" class="p-2 rounded-3 text-white text-decoration-none d-flex align-items-center justify-content-between w-100 border-0" style="background: rgba(255,255,255,0.03); transition: background 0.15s ease;">
                                <span class="d-flex align-items-center gap-2.5">
                                    <i class="fa-solid fa-keyboard text-info"></i>
                                    <span>{{ __('Show Keyboard Shortcuts Cheat Sheet') }}</span>
                                </span>
                                <kbd class="text-white-50 small">?</kbd>
                            </button>
                        </div>
                    </div>
                    <div class="p-2.5 px-3 border-top d-flex align-items-center justify-content-between text-white-50 small" style="border-color: rgba(255, 255, 255, 0.08) !important; font-size: 0.72rem;">
                        <span><i class="fa-solid fa-lightbulb text-warning me-1"></i> {{ __('Pro tip: Press / or Ctrl+K anywhere to search instantly') }}</span>
                        <span>AVDBCMS Spotlight</span>
                    </div>
                </div>
            </div>
        </div>

        @if($showAds && $adCodeFooter)
            <div id="ad-slot-footer" class="ad-slot ad-slot-footer" role="complementary" aria-label="{{__('Advertisement')}}">{!! $adCodeFooter !!}</div>
        @endif

        @if($showMobileAd)
            <div id="ad-slot-mobile" class="ad-slot ad-slot-mobile d-block d-md-none" role="complementary" aria-label="{{__('Advertisement')}}" data-avd-ad-strip>
                <button type="button" class="ad-slot-close" aria-label="{{__('Close ad')}}" onclick="var slot=this.closest('.ad-slot-mobile'); if(slot){slot.remove();} document.body.classList.remove('has-mobile-ad');">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                {!! $adCodeMobile !!}
            </div>
        @endif

        @if($showOnclickAd)
            <div id="ad-slot-onclick" class="ad-slot-script" aria-hidden="true">{!! $adCodeOnclick !!}</div>
        @endif

    </div>

    @if($mobileBottomNavEnabled && !$isBackOfficeLayout)
        <nav class="avd-mobile-bottom-nav d-md-none fixed-bottom" id="avdMobileBottomNav" aria-label="{{ __('Mobile navigation') }}" data-avd-bottom-nav>
            <div class="d-flex align-items-center justify-content-around py-2 px-1">
                <a href="{{ (function_exists('locale_url') ? locale_url('/') : url('/')) }}" class="avd-mob-nav-item {{ request()->routeIs('pages.home') ? 'active' : '' }}">
                    <i class="fa-solid fa-house"></i>
                    <span>{{ __('Home') }}</span>
                </a>
                <a href="{{ (function_exists('locale_url') ? locale_url('/categories') : url('/categories')) }}" class="avd-mob-nav-item {{ request()->is('*categories*') ? 'active' : '' }}">
                    <i class="fa-solid fa-shapes"></i>
                    <span>{{ __('Categories') }}</span>
                </a>
                @if($configBool('header_random_button_enabled', true))
                <a href="{{ (function_exists('locale_url') ? locale_url(route('video.random')) : route('video.random')) }}" class="avd-mob-nav-item avd-mob-special">
                    <div class="avd-mob-fab">
                        <i class="fa-solid fa-shuffle"></i>
                    </div>
                    <span>{{ __('Random') }}</span>
                </a>
                @else
                <a href="{{ (function_exists('locale_url') ? locale_url('/search') : url('/search')) }}" class="avd-mob-nav-item {{ request()->is('*search*') ? 'active' : '' }}">
                    <i class="fa-solid fa-compass"></i>
                    <span>{{ __('Explore') }}</span>
                </a>
                @endif
                @auth
                <a href="{{ locale_url(route('subscription.manage')) }}" class="avd-mob-nav-item {{ request()->is('*subscription*') ? 'active' : '' }}">
                    <i class="fa-solid fa-folder-open"></i>
                    <span>{{ __('Library') }}</span>
                </a>
                <a href="{{ locale_url(route('user.show', auth()->user()->username)) }}" class="avd-mob-nav-item">
                    <i class="fa-solid fa-circle-user"></i>
                    <span>{{ __('Profile') }}</span>
                </a>
                @else
                <a href="{{ locale_url(route('login')) }}" class="avd-mob-nav-item {{ request()->routeIs('login') ? 'active' : '' }}">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i>
                    <span>{{ __('Login') }}</span>
                </a>
                @if(config('site.registration_enabled', true))
                <a href="{{ locale_url(route('registration')) }}" class="avd-mob-nav-item {{ request()->routeIs('registration') ? 'active' : '' }}">
                    <i class="fa-solid fa-user-plus"></i>
                    <span>{{ __('Register') }}</span>
                </a>
                @endif
                @endauth
            </div>
        </nav>
    @endif

    <script>
        window.USER = {!! Auth::check() ? Auth::user()->json  : 'null' !!}
        window.LANG = '{{app()->getLocale()}}'
        window.LOCALE_PREFIX = '{{ app()->getLocale() !== config('site.default_locale', config('app.locale', 'en')) ? '/' . app()->getLocale() : '' }}'
        window.API_BASE = '{{ str_contains(request()->getRequestUri(), "/index.php") ? url("/index.php/api") : url("/api") }}'
        window.AD_CONFIG = {
            enabled: {{ $showAds ? 'true' : 'false' }},
            prerollEnabled: {{ ($showAds && config('site.ad_preroll_enabled', false)) ? 'true' : 'false' }},
            prerollDuration: {{ config('site.ad_preroll_duration', 5) }},
            prerollMediaUrl: {!! json_encode($showAds ? config('site.ad_preroll_media_url', '') : '', $jsonScriptFlags) !!},
            prerollMediaUrls: {!! json_encode($showAds ? $adPrerollMediaUrls : [], $jsonScriptFlags) !!},
            prerollAds: {!! json_encode($showAds ? $adPrerollAds : [], $jsonScriptFlags) !!},
            prerollCode: {!! json_encode($showAds ? config('site.ad_code_video', '') : '', $jsonScriptFlags) !!},
            gridAdCodeByContext: {!! json_encode($showAds ? [
                'smart_discovery' => config('site.ad_code_grid_smart_discovery', ''),
                'creator_spotlight' => config('site.ad_code_grid_creator_spotlight', ''),
                'trending_actors' => config('site.ad_code_grid_trending_actors', ''),
                'new_videos' => config('site.ad_code_grid_new_videos', ''),
                'trend_page' => config('site.ad_code_grid_trend_page', ''),
                'category_page' => config('site.ad_code_grid_category_page', ''),
                'subscriptions' => config('site.ad_code_grid_subscriptions', ''),
                'channel_videos' => config('site.ad_code_grid_channel_videos', ''),
                'search_results' => config('site.ad_code_grid_search_results', ''),
                'playlist_videos' => config('site.ad_code_grid_playlist_videos', ''),
                'watch_related' => config('site.ad_code_grid_watch_related', ''),
                'channel_home_featured' => config('site.ad_code_grid_channel_home_featured', ''),
                'channel_home_latest' => config('site.ad_code_grid_channel_home_latest', ''),
            ] : [], $jsonScriptFlags) !!},
            gridPosition: {{ config('site.ad_grid_position', 6) }},
            gridMaxPerFeed: {{ config('site.ad_grid_max_per_feed', 1) }},
            gridLayout: {!! json_encode(config('site.ad_grid_layout', 'grid_card'), $jsonScriptFlags) !!},
            gridLayoutByContext: {!! json_encode($showAds ? [
                'smart_discovery' => config('site.ad_grid_layout_smart_discovery', 'inherit'),
                'creator_spotlight' => config('site.ad_grid_layout_creator_spotlight', 'inherit'),
                'trending_actors' => config('site.ad_grid_layout_trending_actors', 'inherit'),
                'new_videos' => config('site.ad_grid_layout_new_videos', 'inherit'),
                'trend_page' => config('site.ad_grid_layout_trend_page', 'inherit'),
                'category_page' => config('site.ad_grid_layout_category_page', 'inherit'),
                'subscriptions' => config('site.ad_grid_layout_subscriptions', 'inherit'),
                'channel_videos' => config('site.ad_grid_layout_channel_videos', 'inherit'),
                'search_results' => config('site.ad_grid_layout_search_results', 'inherit'),
                'playlist_videos' => config('site.ad_grid_layout_playlist_videos', 'inherit'),
                'watch_related' => config('site.ad_grid_layout_watch_related', 'inherit'),
                'channel_home_featured' => config('site.ad_grid_layout_channel_home_featured', 'inherit'),
                'channel_home_latest' => config('site.ad_grid_layout_channel_home_latest', 'inherit'),
            ] : [], $jsonScriptFlags) !!},
            sidebarAdCode: {!! json_encode($showAds ? config('site.ad_code_sidebar', '') : '', $jsonScriptFlags) !!},
        };

        @if (Auth::check())
            document.addEventListener("DOMContentLoaded", function() {
                window.PRIVATE_CHANNEL = window.Echo?.private(`App.Models.User.${window.USER.id}`);
            });
        @endif
    </script>

    @if($customJs)
        <script id="custom-js">{!! $customJs !!}</script>
    @endif

    @stack('scripts')

    @if($watchKeyboardShortcutsEnabled)
        <div class="shortcuts-overlay" id="shortcuts-overlay" role="button" tabindex="0" aria-label="{{ __('Close') }}" onclick="if(event.target===this)this.classList.remove('active')" onkeydown="if((event.key==='Enter'||event.key===' ')&&event.target===this){event.preventDefault();this.classList.remove('active');}">
            <div class="shortcuts-panel">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h3 class="mb-0"><i class="fa-solid fa-keyboard text-info"></i> {{__('Keyboard Shortcuts')}}</h3>
                    <button type="button" class="btn-close btn-close-white" onclick="document.getElementById('shortcuts-overlay').classList.remove('active')"></button>
                </div>
                <div class="shortcuts-grid">
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Fullscreen')}}</span><kbd>F</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Theater Mode')}}</span><kbd>T</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Lights Out')}}</span><kbd>L</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Color Grade Filter')}}</span><kbd>C</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Mirror Flip View')}}</span><kbd>M</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Aspect Ratio')}}</span><kbd>W</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Rotate 90° Frame')}}</span><kbd>O</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Zen Immersion')}}</span><kbd>Shift+F</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Specs & Telemetry')}}</span><kbd>I</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Ambilight Halo Glow')}}</span><kbd>A</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Night Shift (Warm)')}}</span><kbd>Shift+N</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Sleep Timer')}}</span><kbd>Z</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Quick Notes')}}</span><kbd>N</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Mini Player (PiP)')}}</span><kbd>P</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Daily Aesthetic HUD')}}</span><kbd>D</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Quick Search')}}</span><kbd>Ctrl+K / /</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Show Shortcuts')}}</span><kbd>?</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Close / Exit')}}</span><kbd>Esc</kbd></div>
                </div>
            </div>
        </div>
    @endif

    @if($backToTopEnabled)
        <button type="button" class="back-to-top" id="back-to-top" tabindex="-1" title="{{__('Back to top')}}" onclick="window.scrollTo({top:0,behavior:'{{ $scrollBehavior }}'})">
            <i class="fa-solid fa-chevron-up"></i>
        </button>
    @endif

    @if($jsCopyToastEnabled)
        <div class="copy-toast" id="copy-toast"><i class="fa-solid fa-check-circle"></i> <span>{{__('Copied!')}}</span></div>
    @endif

    {{-- Perf plan step 25: back-to-top, theater mode and lights out, the keyboard shortcuts, the toasts, the UI
         sounds, the Spotlight palette, the Daily HUD and the accent restore run from resources/js/layout/core.ts
         (and its daily-hud / decor chunks); window.* names called before it runs are queued by the <head> stubs. --}}
    @if($jsTheaterMemoryEnabled)
    <script>
        /* Theater mode memory: restored at this point of the parse, as before (the toggle is in
           resources/js/layout/core.ts). No scroll reset here any more (perf plan step 25f): only a real
           change of mode resets the horizontal scroll. ES5. */
        (function () {
            var buttons = document.querySelectorAll('[data-theater-toggle]');
            if (!buttons.length) return;
            var on = false;
            try {
                on = localStorage.getItem('theater') === '1';
                localStorage.setItem('theater', on ? '1' : '0');
            } catch (e) {}
            if (!on) return;
            document.body.classList.add('theater-mode');
            for (var i = 0; i < buttons.length; i++) {
                buttons[i].setAttribute('aria-pressed', 'true');
                var icon = buttons[i].querySelector('i');
                if (icon) {
                    icon.classList.remove('fa-expand');
                    icon.classList.add('fa-compress');
                }
            }
        })();
    </script>
    @endif
    <script>
        (function(){
            // The one click ripple engine, on every page that renders this layout (the Upload18 embed
            // shell has its own layout). It replaces the second inline engine and the motion chunk's
            // anime.js ripple, which stacked up to three ripples per click. The card tilt, spotlight,
            // glare, magnet, click burst and cursor halo live in resources/js/motion/pointerFx.ts.
            // Each target gets one span, reused on every click and animated with WAAPI (compositor
            // transform and opacity), and taken out again when its ripple ends, so the target's markup
            // is unchanged at rest. Engines without WAAPI (Safari < 13.1, older WebViews) run the same
            // ripple as a CSS animation (.avd-click-ripple-css), as the old CSS ripple did there.
            // It is rendered whatever the admin click ripple switch says (see motionChunkTargets).
            if (!document.body || typeof WeakMap !== 'function') return;
            var root = document.documentElement;
            var waapi = typeof document.body.animate === 'function';
            var rippleTargets = '.btn, .avd-chip, .avd-tag, .avd-category-pill, .nav-link, .avd-mob-nav-item, .avd-sidebar-link, .avd-shelf-paddle, .avd-player-control, .avd-video-card';
            // Mouse clicks keep a ripple on the controls only the motion chunk's ripple covered (public
            // pages, rich tier, full motion, anime effects on).
            var richMouseTargets = '.avd-icon-button, .avd-sidebar-toggle, .avd-social-btn, .avd-action-pill';
            // Where the layout's ripples never showed, the motion chunk's script-driven mouse ripple still did,
            // on its own controls: the click ripple switch off (the server left the layout engines out, the
            // chunk ignored the switch), the motion engine 'off' setting (it cuts every CSS animation to 1 ms)
            // and 'CSS animations off' (it removes them).
            var motionChunkTargets = '.btn:not(.btn-close), .avd-icon-button, .avd-sidebar-toggle, .avd-tag, .avd-social-btn, .avd-player-control, .avd-action-pill';
            // Framework-managed media subtrees (player, preroll ad, upload) never get injected nodes.
            var excludedSubtrees = 'video-player, video-upload, .avd-player-stage, .avd-preroll-ad, .avd-embed-shell';
            var reducedMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
            var ripples = new WeakMap();
            var pendingTap = null;

            // The balanced tier or above (lite has none), motion on and no reduced-motion preference.
            function rippleOn() {
                if (root.getAttribute('data-avd-motion') === 'off') return false;
                if (reducedMotion && reducedMotion.matches) return false;
                return window.AVD_TIER ? window.AVD_TIER.at('balanced') : root.getAttribute('data-avd-perf') !== 'lite';
            }

            // pointerFx (rich tier, fine pointer) sets data-avd-pointer-budget="ok" when the page is within the
            // old motion chunk's budget (128 cards, comments, list and dropdown items), as its ripple required.
            function richMouseRipple() {
                return !document.body.classList.contains('avd-admin')
                    && (root.getAttribute('data-avd-motion') || 'full') === 'full'
                    && !!(window.AVD_TIER && window.AVD_TIER.at('rich'))
                    && !(window.AVD_SETTINGS && window.AVD_SETTINGS.animeEffects === false)
                    && root.getAttribute('data-avd-pointer-budget') === 'ok';
            }

            function rippleTargetFor(node, mouse) {
                if (!node || !node.closest || !rippleOn()) return null;
                var target;
                if (root.getAttribute('data-avd-ripple') !== 'on' || root.getAttribute('data-avd-motion-engine') === 'off' || root.getAttribute('data-avd-css-animations') === 'off') {
                    target = mouse && richMouseRipple() ? node.closest(motionChunkTargets) : null;
                } else {
                    target = node.closest(rippleTargets) || (mouse && richMouseRipple() ? node.closest(richMouseTargets) : null);
                }
                if (!target || target.getAttribute('data-avd-no-ripple') === 'true' || target.closest(excludedSubtrees)) return null;
                return target;
            }

            function spawnRipple(target, clientX, clientY) {
                var entry = ripples.get(target);
                if (!entry) {
                    var span = document.createElement('span');
                    span.className = 'avd-click-ripple';
                    span.setAttribute('aria-hidden', 'true');
                    entry = { span: span, animation: null, timer: 0 };
                    ripples.set(target, entry);
                }
                var rect = target.getBoundingClientRect();
                var size = Math.max(rect.width, rect.height) * 2;
                var style = window.getComputedStyle(target);
                // The target clips its ripple. The stylesheet makes most targets relative and clipped;
                // the others get the inline pair the old engine wrote on every click.
                if (style.position === 'static') target.style.position = 'relative';
                if (style.overflow !== 'hidden') target.style.overflow = 'hidden';
                if (entry.animation) entry.animation.cancel();
                entry.span.style.width = entry.span.style.height = size + 'px';
                entry.span.style.left = (clientX - rect.left - size / 2) + 'px';
                entry.span.style.top = (clientY - rect.top - size / 2) + 'px';
                if (entry.span.parentNode !== target) target.appendChild(entry.span);
                if (!waapi) {
                    // Restart the CSS ripple (a reflow between dropping and adding the class), and take the
                    // span out once it is over.
                    entry.span.classList.remove('avd-click-ripple-css');
                    void entry.span.offsetWidth;
                    entry.span.classList.add('avd-click-ripple-css');
                    window.clearTimeout(entry.timer);
                    entry.timer = window.setTimeout(function() {
                        entry.timer = 0;
                        entry.span.classList.remove('avd-click-ripple-css');
                        if (entry.span.parentNode) entry.span.parentNode.removeChild(entry.span);
                    }, 700);
                    return;
                }
                var animation = entry.span.animate([
                    { transform: 'scale(0)', opacity: 1 },
                    { transform: 'scale(1)', opacity: 0 }
                ], { duration: 650, easing: 'ease-out' });
                entry.animation = animation;
                animation.onfinish = function() {
                    if (entry.animation !== animation) return;
                    entry.animation = null;
                    if (entry.span.parentNode) entry.span.parentNode.removeChild(entry.span);
                };
            }

            // Mouse ripples on press, as before. Touch and pen: pointerdown also starts every
            // scroll gesture, so remember the target and ripple on 'click', which only taps fire.
            // Cards get no touch ripple; their coarse :active press scale is the tap feedback.
            document.addEventListener('pointerdown', function(e) {
                var mouse = e.pointerType === 'mouse';
                var target = rippleTargetFor(e.target, mouse);
                pendingTap = null;
                if (!target) return;
                if (mouse) {
                    spawnRipple(target, e.clientX, e.clientY);
                } else if (!target.classList.contains('avd-video-card')) {
                    pendingTap = target;
                }
            }, { passive: true });

            document.addEventListener('pointercancel', function() {
                pendingTap = null;
            }, { passive: true });

            // Capture phase: buttons whose own handlers stop the click (card actions, carousel
            // controls) still get their tap ripple, as they did on pointerdown. Engines without
            // pointer events (Safari < 13) ripple every input on click, cards excepted, as the old
            // click-driven CSS ripple did there.
            var pointerEvents = !!window.PointerEvent;
            document.addEventListener('click', function(e) {
                var target = pendingTap;
                pendingTap = null;
                if (!pointerEvents) {
                    target = e.detail ? rippleTargetFor(e.target, false) : null;
                    if (target && !target.classList.contains('avd-video-card')) spawnRipple(target, e.clientX, e.clientY);
                    return;
                }
                if (!target || !e.detail || !target.isConnected) return;
                if (rippleTargetFor(e.target, false) !== target) return;
                spawnRipple(target, e.clientX, e.clientY);
            }, { capture: true, passive: true });
        })();
    </script>

    {{-- Cinema Lights Out Backdrop --}}
    <div id="avdLightsOutBackdrop" role="button" tabindex="0" aria-label="{{ __('Lights Out') }}" onclick="window.avdToggleLightsOut()" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.avdToggleLightsOut();}"></div>

    {{-- Seasonal Ambient Particles Canvas --}}
    @if($dailyBadgeEnabled && $activeDailyDesign && $dailyAtmosphereParticles)
        <canvas id="avdSeasonalAtmosphere"></canvas>
    @endif

    {{-- 365-Day Daily Design Floating Dock Badge & Interactive HUD Modal --}}
    @if($dailyBadgeEnabled && $activeDailyDesign)
        <div class="position-fixed d-none d-md-flex align-items-center gap-2 py-1.5 px-3 rounded-pill shadow-lg transition-all"
             role="button" tabindex="0" aria-label="{{ __('Open Daily Aesthetic Identity HUD') }}"
             style="bottom: 24px; left: 24px; z-index: 1040; cursor: pointer; background: rgba(15, 23, 42, 0.88); border: 1px solid rgba(255, 255, 255, 0.16); box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5), 0 0 18px -2px {{ $activeDailyDesign['primary'] }}55 !important; font-size: 0.78rem;"
             onclick="window.openDailyAestheticModal()"
             onkeydown="if(event.target===this&&(event.key==='Enter'||event.key===' ')){event.preventDefault();window.openDailyAestheticModal();}"
             title="{{ __('Click to explore today\'s aesthetic identity & seasonal atmosphere') }}">
            <span class="rounded-circle d-inline-flex align-items-center justify-content-center text-white flex-shrink-0"
                  style="width: 22px; height: 22px; background: linear-gradient(135deg, {{ $activeDailyDesign['primary'] }}, {{ $activeDailyDesign['accent'] }}); font-size: 0.7rem;">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
            </span>
            <span class="text-white-50">{{ __('Today:') }}</span>
            <strong class="text-white text-truncate" style="max-width: 140px;">{{ $activeDailyDesign['name'] }}</strong>
            <span class="badge rounded-pill bg-black text-white-50 border" style="font-size: 0.64rem; border-color: rgba(255,255,255,0.1) !important;">
                {{ __('Day') }} {{ $activeDailyDesign['day'] }}/{{ $dailyDaysInYear }}
            </span>
            @if(!empty($activeDailyDesign['holiday']))
                <span class="badge rounded-pill bg-warning text-dark fw-bold" style="font-size: 0.64rem;">
                    {{ $activeDailyDesign['holiday'] }}
                </span>
            @endif
            {{-- Closes this pill (its d-md-flex display is !important, hence the important inline value). It used to
                 target #dailyThemeBadge, the other badge, an id this pill shared until 4b, and changed nothing.
                 Activated from the keyboard (a click with detail 0 while this x has focus), it first moves focus to
                 #dailyThemeBadge, the badge that opens the same HUD and is shown at the same breakpoint, so a keyboard
                 user is not sent back to the top of the page. A mouse click or tap moves no focus, so no focus ring
                 appears for pointer users, also in browsers without :focus-visible. --}}
            <button type="button" class="btn-close btn-close-white ms-1" style="font-size: 0.55rem;" onclick="event.stopPropagation(); var badge = document.getElementById('dailyThemeBadge'); if (badge && !event.detail && document.activeElement === this) badge.focus({ preventScroll: true }); this.parentElement.style.setProperty('display', 'none', 'important');" aria-label="Close"></button>
        </div>

        {{-- The HUD modal is emitted once (above, with the first badge): this second copy was never shown and
             duplicated its ids. Its sounds, glow, card and particles run from resources/js/layout (perf plan
             step 25c/d). --}}
    @endif

    @php
        $authWatchLaterIds = Auth::check()
            ? (Auth::user()->playlists()->where('title', \App\Playlists\Types\WatchLaterPlaylist::getName())->first()?->videos()->pluck('videos.id')->map(fn($id) => (int)$id)->toArray() ?? [])
            : [];
        // Perf plan step 25e: the translations and settings of resources/js/layout/*.ts, once, instead of
        // Blade tokens inside inline scripts. Plain text, not HTML (perf plan 4c): the scripts set it as
        // textContent, title or canvas text, and escape it where it goes into markup (avdToast). The JSON
        // flags (JSON_HEX_TAG, _AMP, _APOS, _QUOT) write < > & ' " as JSON unicode escapes, so no value can
        // end the <script> element.
        $avdLayoutJs = [
            't' => [
                'lightsOut' => (string) __("Lights Out Mode (Press L to Restore)"),
                'lightsRestored' => (string) __("Lights Restored"),
                'audioOn' => (string) __("Audio FX: ON"),
                'audioOff' => (string) __("Audio FX: OFF"),
                'audioEnabled' => (string) __("Audio FX: Enabled"),
                'audioMuted' => (string) __("Audio FX: Muted"),
                'copied' => (string) __("Copied"),
                'copiedColon' => (string) __("Copied:"),
                'wallpaperDownloaded' => (string) __("🎨 Aesthetic Wallpaper Card Downloaded!"),
                'cardDownloaded' => (string) __("Aesthetic Card Downloaded!"),
                'wlPromptTitle' => (string) __('Want to watch this later?'),
                'wlPromptMessage' => (string) __('Sign in to add this video to your Watch Later playlist.'),
                'wlQuickSaveMessage' => (string) __('Sign in to add this video to your Watch Later playlist and keep track of videos you want to watch across all your devices.'),
                'wlSavedTitle' => (string) __('Saved in Watch Later (Click to remove)'),
                'wlTitle' => (string) __('Watch Later'),
                'wlSaved' => (string) __('Saved to Watch Later!'),
                'wlRemoved' => (string) __('Removed from Watch Later'),
                'wlFailed' => (string) __('Failed to update Watch Later'),
            ],
            'theaterMemory' => (bool) $jsTheaterMemoryEnabled,
            'copyToast' => (bool) $jsCopyToastEnabled,
            'primaryColor' => (string) $themeBrandColor,
            'themePreset' => (string) $themePreset,
            'user' => [
                'isAuthenticated' => Auth::check(),
                'id' => Auth::id(),
                'loginUrl' => locale_url(route('login')),
                'registerUrl' => locale_url(route('registration')),
                'watchLaterIds' => $authWatchLaterIds,
            ],
            'dailyCard' => [
                'name' => (string) ($activeDailyDesign['name'] ?? __((string) ($themeRecord['name'] ?? config('app.name')))),
                'day' => (string) ($activeDailyDesign['day'] ?? now()->dayOfYear),
                'date' => (string) date('F j, Y'),
                'mood' => (string) ($activeDailyDesign['mood'] ?? 'A distinctive visual atmosphere crafted uniquely for today.'),
                'daysInYear' => $dailyDaysInYear,
            ],
            'daily' => $dailyBadgeEnabled && $activeDailyDesign ? [
                'primaryRgb' => (string) $themeBrandRgb,
                // Theme collection v2: the day's theme and colour style by name (read by resources/js/layout/daily-hud.ts when present).
                'theme' => ($activeDailyDesign['theme_name'] ?? '') !== '' ? (string) __((string) $activeDailyDesign['theme_name']) : '',
                'flavor' => ($activeDailyDesign['flavor_name'] ?? '') !== '' ? (string) __((string) $activeDailyDesign['flavor_name']) : '',
                'daysInYear' => $dailyDaysInYear,
                'primary' => (string) $activeDailyDesign['primary'],
                'secondary' => (string) $activeDailyDesign['secondary'],
                'accent' => (string) $activeDailyDesign['accent'],
                'day' => (string) $activeDailyDesign['day'],
                'dateLabel' => (string) $activeDailyDesign['date_label'],
                'name' => (string) $activeDailyDesign['name'],
                'seasonMood' => (string) $activeDailyDesign['season_mood'],
                'layout' => (string) Str::headline($activeDailyDesign['home_layout_mode']),
                'surface' => (string) Str::headline($activeDailyDesign['surface_style']),
                'typography' => (string) Str::headline($activeDailyDesign['font_family']),
                'slug' => (string) Str::slug($activeDailyDesign['name']),
            ] : null,
        ];
    @endphp
    <script type="application/json" id="avd-i18n">{!! json_encode($avdLayoutJs, $jsonScriptFlags | JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>
    {{-- The first watch-later badge pass stays here, at the end of the parse, as before: the quick-save buttons
         of server-rendered cards (channel, playlists, related videos, billboard) are never painted unsynced (saved
         state, icon) while the module bundle loads. resources/js/layout/core.ts runs the same pass again (it
         changes nothing then) and keeps the buttons in sync: change both together. ES5. --}}
    <script>
        (function () {
            var cfg;
            try {
                cfg = JSON.parse(document.getElementById('avd-i18n').textContent);
            } catch (e) {
                return;
            }
            var user = cfg.user || {};
            var t = cfg.t || {};
            var isAuth = !!user.isAuthenticated;
            var saved = isAuth ? (user.watchLaterIds || []) : [];
            var savedShadow = '0 0 10px rgba(34, 197, 94, 0.4)';
            var probe = document.createElement('span');
            probe.style.boxShadow = savedShadow;
            var savedShadowValue = probe.style.boxShadow;
            var btns = document.querySelectorAll('button[data-watch-later-id], button[onclick*="avdQuickSave"]');
            for (var i = 0; i < btns.length; i++) {
                var btn = btns[i];
                var id = btn.getAttribute('data-watch-later-id');
                if (!id) {
                    var match = (btn.getAttribute('onclick') || '').match(/avdQuickSave\(['"]([^'"]+)['"]\)/);
                    id = match && match[1] ? match[1] : '';
                }
                if (!id) continue;
                var isSaved = isAuth && saved.indexOf(Number(id)) !== -1;
                if (btn.classList.contains('active') !== isSaved || btn.classList.contains('border-success') !== isSaved) {
                    btn.classList.toggle('active', isSaved);
                    btn.classList.toggle('border-success', isSaved);
                }
                if (btn.style.boxShadow !== (isSaved ? savedShadowValue : '')) {
                    btn.style.boxShadow = isSaved ? savedShadow : '';
                }
                var title = isSaved ? t.wlSavedTitle : t.wlTitle;
                if (btn.title !== title) {
                    btn.title = title;
                }
                var icon = btn.querySelector('i');
                var iconClass = isSaved ? 'fa-solid fa-check text-success' : 'fa-regular fa-clock text-white';
                if (icon && icon.className !== iconClass) {
                    icon.className = iconClass;
                }
            }
        })();
    </script>

    <!-- Auth Prompt Modal for Watch Later and Personalized Actions -->
    <div class="modal fade" id="avdAuthPromptModal" tabindex="-1" aria-labelledby="avdAuthPromptTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 430px;">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" style="background: rgba(18, 24, 38, 0.98); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.15) !important; color: #fff;">
                <div class="modal-header border-0 pb-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px; background: rgba(var(--avd-primary-rgb, 255, 61, 97), 0.15); color: var(--avd-primary, #ff3d61); border: 1px solid rgba(var(--avd-primary-rgb, 255, 61, 97), 0.25);">
                            <i class="fa-regular fa-clock fs-5" id="avdAuthPromptIcon"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold fs-5 mb-0" id="avdAuthPromptTitle">{{ __('Want to watch this later?') }}</h5>
                            <span class="text-body-secondary small">{{ __('Personalized Playlist') }}</span>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body px-4 py-3">
                    <p class="text-body-secondary mb-0" id="avdAuthPromptMessage" style="font-size: 0.92rem; line-height: 1.55;">
                        {{ __('Sign in to add this video to your Watch Later playlist and keep track of videos you want to watch across all your devices.') }}
                    </p>
                </div>
                <div class="modal-footer border-0 pt-0 pb-4 px-4 d-flex flex-column gap-2">
                    <a href="{{ locale_url(route('login')) }}" class="btn btn-primary w-100 py-2.5 rounded-3 fw-bold d-flex align-items-center justify-content-center gap-2 shadow-sm" id="avdAuthPromptLoginBtn">
                        <i class="fa-solid fa-arrow-right-to-bracket"></i>
                        <span>{{ __('Sign In') }}</span>
                    </a>
                    @if(config('site.registration_enabled', true))
                    <a href="{{ locale_url(route('registration')) }}" class="btn btn-outline-secondary w-100 py-2.5 rounded-3 fw-semibold text-white d-flex align-items-center justify-content-center gap-2" style="border-color: rgba(255,255,255,0.22); background: rgba(255,255,255,0.04);">
                        <i class="fa-solid fa-user-plus"></i>
                        <span>{{ __('Create an Account') }}</span>
                    </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
    {{-- Appearance draft preview (admin only, WP8's ApplyAppearanceDraft marks the request): the "you are previewing a draft" banner. --}}
    @includeWhen(request()->attributes->get('avd.appearance_draft'), 'admin.appearance.partials.draft-banner')
</body>
</html>
