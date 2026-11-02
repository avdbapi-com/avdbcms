<!DOCTYPE html>
@php
    // A demo site's visitor choices (App\Support\VisitorChoices: the look this browser tries, ads turned on or off):
    // every page this layout draws for the public site gets them, whatever its address or method (the ApplyVisitorChoices
    // middleware applied them already to a page a visitor reads; here the 404 page of an /admin address, the answer to a
    // form, a page rendered outside the web middleware). Nothing while both settings are off, or in the admin's layout.
    \App\Support\VisitorChoices::applyForLayout(request(), (string) $attributes->get('type', ''));
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
    // The "Today's design" pill and its HUD (member design 4.1a, 4.4, owner decision OD-M17): never on the member
    // back office (studio and account: that corner is the studio menu's account card and the save bar) nor on the
    // sign-in pages; the particle canvas never on a member page (a requestAnimationFrame loop at rest). Evaluated
    // below, once the route is known.
    $showDailyPill = false;
    $showDailyParticles = false;
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
    // The password-reset page carries the reset token (in its address) and the e-mail (in its query): the owner's own
    // scripts and head code (an analytics or chat snippet reads location.href) do not run there. Custom CSS stays.
    $isPasswordResetPage = request()->routeIs('password.reset');
    $customJs = $customCodeEnabled && ! $isPasswordResetPage ? ($appearance['custom_js'] ?? '') : '';
    $customHead = $customCodeEnabled && ! $isPasswordResetPage ? ($appearance['custom_head_html'] ?? '') : '';
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
    // Member pages (member design 5.13, the member scaffold): the route names of config/member_pages.php. The member
    // CSS entry loads for a signed-in visitor on every page and for guests on the member routes, never on the admin's
    // pages; html[data-avd-member] is printed on the member routes once config('member_pages.marker') is on.
    // Error and status pages declare themselves member pages (resources/views/errors/minimal.blade.php sets the request
    // attribute avd.member_page before the layout renders: member design 6.15, the error-* mockups).
    $memberPage = (is_string($routeName) && \Illuminate\Support\Str::is((array) config('member_pages.routes', []), $routeName))
        || request()->attributes->get('avd.member_page') === true;
    $memberMarker = $memberPage && (bool) config('member_pages.marker', false);
    // An error page a guest sees links the entry itself (errors/minimal.blade.php, request attribute avd.member_css_pushed,
    // set before this layout renders): linked once, not twice.
    $memberCss = ($memberPage || auth()->check()) && $layoutType !== 'admin' && ! request()->routeIs('admin.*')
        && request()->attributes->get('avd.member_css_pushed') !== true;
    // R1-070: the sign-in pages' part of the member kit (resources/sass/auth-pages.scss, member/_auth.scss) is its own
    // entry, printed after the member entry where its rules are used: the sign-in pages, the contact form, the account
    // page (its "Always on" divider) and the error pages (errors/minimal.blade.php marks itself with avd.member_page).
    $memberAuthCss = ($memberCss || request()->attributes->get('avd.member_css_pushed') === true)
        && (request()->routeIs('login', 'logout', 'registration', 'password.*', 'verification.*', 'phone.*', 'contact.show', 'user.edit')
            || request()->attributes->get('avd.member_page') === true);
    // The member back office (studio and account: layouts/user, type="user"). The admin's back office (type="admin")
    // shares html[data-avd-back-office] but never the studio's tab bar, slim footer or member rules.
    $isMemberBackOffice = $layoutType === 'user';
    // A one-time toast that comes with the page (member design 5.10: the toast system is the layout's). A controller
    // flashes `toast` as a string or as ['message' => ..., 'type' => success|error|info|warning]; the layout prints it
    // in the kit toast stack and marks <html data-mk-toast> while it shows, so "Back to top" and the daily-design pill
    // step aside (4.4). The text is always escaped.
    $flashToast = (function (): ?array {
        $raw = session('toast');
        $toast = is_array($raw) ? $raw : (is_string($raw) ? ['message' => $raw] : null);
        $message = is_array($toast) && is_scalar($toast['message'] ?? null) ? trim((string) $toast['message']) : '';
        if ($message === '') {
            return null;
        }
        $type = in_array($toast['type'] ?? 'success', ['success', 'error', 'info', 'warning'], true) ? ($toast['type'] ?? 'success') : 'success';

        return ['message' => $message, 'type' => $type];
    })();
    // A page that can show one visitor's flashed message (this toast, a status line, form errors) is that visitor's:
    // the edge cache leaves it private (App\Support\OneTimePage; the flash data is gone from the session by the time the
    // outer middleware sees the response). The toast marks it even when it is not a flash.
    if (! \App\Support\OneTimePage::markIfFlashed() && $flashToast !== null) {
        \App\Support\OneTimePage::mark();
    }
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
        in_array($routeName, ['login', 'registration', 'logout'], true) ||
        \Illuminate\Support\Str::startsWith($routeName, ['password.', 'verification.', 'phone.', 'oauth.'])
    )) {
        $pageRobots = 'noindex, nofollow';
    } elseif (in_array($routeName, ['pages.history', 'subscription.manage', 'playlist.manage', 'custom-playlist.show'], true)) {
        $pageRobots = 'noindex, nofollow';
    } elseif ($routeName && \Illuminate\Support\Str::startsWith($routeName, 'user.') && $routeName !== 'user.show') {
        $pageRobots = 'noindex, nofollow';
    }
    // 1.2.3: a watch page that opens in a language where the video is not indexable (App\Support\VideoLanguages::robots(),
    // also its X-Robots-Tag header) says so here too
    $pageRobots = request()->attributes->get(\App\Support\VideoLanguages::ROBOTS) ?? $pageRobots;

    $robotsMeta = $robotsOverride !== '' ? $robotsOverride : $pageRobots;
    $adCodeHeader = config('site.ad_code_header', '');
    $adCodeFooter = config('site.ad_code_footer', '');
    $adCodeMobile = config('site.ad_code_mobile', '');
    $adCodeOnclick = config('site.ad_code_onclick', '');
    $adPrerollAds = \App\Support\PrerollAds::parse(config('site.ad_preroll_media_url', ''), config('site.ad_preroll_click_url', ''));
    $adPrerollMediaUrls = array_column($adPrerollAds, 'src');
    // 'logout': the "Sign out of <site>?" page an old GET link opens (auth-22) is drawn in the sign-in layout: no ads (6.20)
    $isAuthRoute = $routeName && (
        in_array($routeName, ['login', 'registration', 'logout'], true) ||
        \Illuminate\Support\Str::startsWith($routeName, ['password.', 'verification.', 'phone.', 'oauth.'])
    );
    // /premium, the checkout returns and the welcome sheet sell an ad-free plan: no ad slot, phone strip or popunder
    // there (member design 6.20, owner decision OD-M10; revertible in this one line).
    $isCheckoutPage = $routeName && (
        $routeName === 'pages.premium' || \Illuminate\Support\Str::startsWith($routeName, ['premium.', 'paypal.', 'stripe.'])
    );
    $adsEnabled = config('site.ads_enabled', false);
    $hideAds = $adsEnabled && config('site.ads_hide_for_premium', true) && auth()->user()?->is_premium;
    $showAds = $adsEnabled && ! $hideAds && ! $isBackOfficeLayout && ! $isAuthRoute && ! $isCheckoutPage;
    $showMobileAd = $showAds && trim((string) $adCodeMobile) !== '';
    $showOnclickAd = $showAds && config('site.ad_onclick_enabled', false) && trim((string) $adCodeOnclick) !== '';
    // The demo site's "Demo · Ads off" pill and palette button (layouts/partials/visitor-choices): null, so nothing at
    // all is printed, while both settings are off, on the admin's pages and on pages the feature leaves alone.
    $avdVisitorDock = \App\Support\VisitorChoices::dock(request(), $layoutType, $backToTopEnabled);
    $showDailyPill = $dailyBadgeEnabled && $activeDailyDesign && ! $isMemberBackOffice && ! $isAuthRoute;
    $showDailyParticles = $showDailyPill && $dailyAtmosphereParticles && ! $memberPage;
    // The studio's phone tab bar replaces the site's bottom bar on member back-office pages while "Mobile
    // Bottom Nav Bar" is on: body.has-studio-tabbar marks it, so the 62px band,
    // the save bar's lift and the hidden head/header Upload follow the real tab bar, and with the setting off studio
    // phones keep an Upload button and no empty band.
    $studioTabbar = $mobileBottomNavEnabled && $isMemberBackOffice;
    // auth-8 / auth-18: the layout's sign-in entries (phone bottom bar, the guest prompt) bring the visitor back to the
    // page they came from and keep their language: the header's own link (ReturnPath::loginUrl()), whose ?redirect= is
    // a path of this site (ReturnPath::safePath(); round 3 R3-079: it was the address as received, "//host/x" too). Not
    // from the sign-in pages themselves. Create account carries no return address (the sign-up page reads none), as
    // in the header and the guest prompt.
    $signInUrl = $isAuthRoute || ! request()->isMethod('GET') ? locale_url(route('login')) : \App\Http\Controllers\Auth\ReturnPath::loginUrl();
    $signUpUrl = locale_url(route('registration'));
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
    // A site installed in a folder (APP_URL https://example.com/site): locale_url() gives the whole address, and the
    // alternates below put the page after APP_URL, which holds the folder (R2R-05).
    $canonicalDefault = site_folder() === '' ? $appUrl . locale_url($currentPath) : locale_url($currentPath);
    $canonicalUrl = trim($__env->yieldContent('canonical', $canonicalDefault)) ?: $canonicalDefault;
    $canonicalPath = parse_url($canonicalUrl, PHP_URL_PATH) ?: $currentPath;
    $canonicalPath = site_path('/' . ltrim($canonicalPath, '/'));
    // A site whose links keep /index.php/...: the language goes after it (/index.php/fr/trend), never before it
    // (/fr/index.php answers 404 on nginx).
    $alternatePrefix = '';
    if ($canonicalPath === '/index.php' || str_starts_with($canonicalPath, '/index.php/')) {
        $alternatePrefix = '/index.php';
        $canonicalPath = substr($canonicalPath, strlen('/index.php')) ?: '/';
    }
    // The home page of a site installed in a folder: the folder with its slash, as its canonical (R2R-05).
    $alternateHome = $alternatePrefix === '' && site_folder() !== '' ? '/' : '';
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
    // Theme-owned structure (theme_spec theme_owned_recipe) and the side menu. On public pages ThemeStructure
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
    // Back-office pages print the plain bar (layouts/menus/header.blade.php): the masthead and crest decorations and
    // their header rules stay on the public pages (studio-37, studio-40).
    if ($isBackOfficeLayout && in_array($themeAttributes['data-avd-header-deco'] ?? '', ['masthead', 'crest'], true)) {
        $themeAttributes['data-avd-header-deco'] = 'none';
    }
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
    @if($isMemberBackOffice) data-avd-member-back-office @endif
    @if($memberMarker) data-avd-member @endif
    @if($flashToast) data-mk-toast @endif
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
    {{-- Early hints from the page content (the first hero image, the VideosList
         chunk and its imports). Pages render before this layout, so their pushes are already here. --}}
    @stack('preload')
    @php
        // The page's early feeds (<x-videos-area early>, see resources/views/components/videos-area.blade.php): one
        // for every width, or one below 768px and one from 768px (`media`); on a home feed its first page (the API's
        // JSON, for guests and signed-in members alike since R1-072), else (a cold API cache) its first row's pictures.
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
        {{-- The early feed's first-page API request starts here, in parallel with the CSS
           and JS, instead of after the app boots. It is exactly the request VideosList makes (getVideos ->
           jsonFetch: same URL, headers and credentials, the same /index.php rule). VideosList takes the
           promise from window.__avdFeedEarly[url] once, for the first page only; a missing, failed or
           unexpected response falls back to its own request. When the page carries that first page
           (#avdFeedEarlyPage: a home feed, for guests and members) no request is made: VideosList takes it as its
           initial data (window.__avdFeedShipped[url]) and the promise resolves to it. Without it (a cold API cache) the
           page carries the first row's pictures: VideosList's skeleton shows them (window.__avdFeedThumbs[url],
           with `warm`: eager only where the server skeleton loads them eagerly, i.e. no hero photo and early at
           every width). A feed that is early at one width only (its media query = VideosList's early-media) makes
           no request at the other widths, where it loads like any other feed; a page it carries still answers
           that later first-page request (window.__avdFeedEarly[url]) instead of the API, without rendering it at
           boot. Arguments: [[url, media, page id, thumbs], ...], locale, CSRF token, warm. ES5. --}}
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
                    {{-- X-Background-Request (App\Http\Middleware\BackgroundRequest): the session is read, never changed. --}}
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Locale': locale, 'X-Background-Request': '1'},
                    credentials: 'include'
                }).then(function (response) {
                    if (!response.ok) throw response.status;
                    return response.json();
                });
                request['catch'](function () {});
                registry[url] = request;
                {{-- Under a hero without a photo of its own (the ranked lists) the feed's first row is the largest
                   picture of the first screen: its thumbnails start loading as soon as the list arrives, while the
                   app still boots; the cards' <img>s then find them in the image cache. --}}
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
        {{-- 1.2.3 round 3: an imported video's page is indexable only in English, the default language and the languages of its translations (App\Support\VideoLanguages) --}}
        @php $hreflangOnly = request()->attributes->get(\App\Support\VideoLanguages::HREFLANG); @endphp
        @foreach(config('languages', []) as $langCode => $langInfo)
            @continue(is_array($hreflangOnly) && ! in_array($langCode, $hreflangOnly, true))
            <link rel="alternate" hreflang="{{ $langCode }}" href="{{ $appUrl . $alternatePrefix . ($langCode === $defaultLocale ? ($canonicalPath === '/' ? $alternateHome : $canonicalPath) : '/' . $langCode . ($canonicalPath === '/' ? '' : $canonicalPath)) }}">
        @endforeach
        <link rel="alternate" hreflang="x-default" href="{{ $appUrl . $alternatePrefix . (is_array($hreflangOnly) && $hreflangOnly !== [] && ! in_array($defaultLocale, $hreflangOnly, true) ? '/' . $hreflangOnly[0] : '') . ($canonicalPath === '/' ? $alternateHome : $canonicalPath) }}">
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
    <link rel="manifest" href="{{ site_browser_path('/manifest.json') }}">
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
        // adds one. The admin's pages keep the full v1 stylesheet (the Theme Studio previews every pairing) plus
        // the theme's families. The member studio and account pages (type="user") load the look's families only,
        // like every other member page (studio-20, account-36: they requested all 15 families, 63 KB of CSS gzip,
        // for the 2 the look uses).
        $avdAllFontFamilies = $layoutType === 'admin';
        if ($themeFonts === null) {
            $avdGoogleFontsUrl = \App\Support\AppearanceSettings::googleFontsUrl(
                $avdAllFontFamilies
                    ? array_keys(\App\Support\AppearanceSettings::googleFontFamilySpecs())
                    : \App\Support\AppearanceSettings::googleFontFamiliesFor(
                        $typographyPairing,
                        $fontFamilyKey,
                        $typographyEnabled,
                        implode("\n", [(string) $customCss, (string) $customHead, (string) $customJs, (string) $announcementBanner])
                    )
            );
        } else {
            $avdGoogleFontsUrl = $avdAllFontFamilies
                ? \App\Support\ThemeTokens::backOfficeFontsUrl(\App\Support\AppearanceSettings::googleFontFamilySpecs(), $themeFonts['families'])
                : $themeFonts['url'];
        }
    @endphp
    @if($avdGoogleFontsUrl)
    <link rel="preload" as="style" href="{{ $avdGoogleFontsUrl }}">
    <link href="{{ $avdGoogleFontsUrl }}" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="{{ $avdGoogleFontsUrl }}"></noscript>
    @endif
    @php
        // R1-068 (perf-12): a metric-matched fallback face for each web font of the look (ThemeTokens::fallbackFaces(): the
        // local Arial, Times New Roman or Courier New with the family's size-adjust and ascent / descent overrides), named
        // right after the family in the look's font stacks, so the swap to the web font re-wraps and moves nothing.
        $avdFontFallbacks = $themeFonts !== null ? \App\Support\ThemeTokens::fallbackFaces(array_keys((array) ($themeFonts['families'] ?? []))) : '';
        $avdFontFallbackFamilies = $avdFontFallbacks !== '' ? \App\Support\ThemeTokens::fallbackFamilies(array_keys((array) ($themeFonts['families'] ?? []))) : [];
    @endphp
    @if($avdFontFallbacks !== '')
    <style id="avd-font-fallbacks">{!! $avdFontFallbacks !!}</style>
    {{-- R3-023: the faces are asked for now, while the page waits for its stylesheets: the browser's one-time set-up of
         local font matching then happens here instead of inside the page's first full layout (ThemeTokens::fallbackFamilies). ES5. --}}
    <script>(function(f,n){if(f&&f.load)for(var i=0;i<n.length;i++){f.load('1em "'+n[i]+' Fallback"')['catch'](function(){});f.load('700 1em "'+n[i]+' Fallback"')['catch'](function(){})}})(document.fonts,@json($avdFontFallbackFamilies))</script>
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
            {{-- Clean up any stale legacy localStorage theme key to prevent desyncing from server presets --}}
            try {
                if (localStorage.getItem('theme')) {
                    localStorage.removeItem('theme');
                }
            } catch(e) {}
            {{-- The visitor's Live Custom Accent Hue and (with the Daily Aesthetic badge)
                 personal glow are restored here, before the first paint, instead of at the end of the body.
                 Theme previews included, as before. resources/js/layout/core.ts syncs the HUD's picker. --}}
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
                {{-- When previewing via ?theme= or ?theme_preset=, strictly honor the server-rendered theme --}}
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
                    // "Device" in the account panel (layouts/menus/account.blade.php): follow the device in every look,
                    // also when the look's default is light or dark.
                    if (localStorage.getItem('avd_manual_theme_context') === themeContext && manualTheme === 'device' && window.matchMedia) {
                        var deviceScheme = window.matchMedia('(prefers-color-scheme: dark)');
                        var followDevice = function() {
                            try {
                                if (localStorage.getItem('avd_manual_theme') !== 'device') return;
                            } catch(e) {}
                            root.setAttribute('data-bs-theme', deviceScheme.matches ? 'dark' : 'light');
                            window.dispatchEvent(new CustomEvent('theme-changed'));
                        };
                        root.setAttribute('data-bs-theme', deviceScheme.matches ? 'dark' : 'light');
                        if (deviceScheme.addEventListener) deviceScheme.addEventListener('change', followDevice);
                        else if (deviceScheme.addListener) deviceScheme.addListener(followDevice);
                        hasManualTheme = true;
                    }
                } catch(e) {}
            }
            if (root.dataset.avdThemeDefault === 'auto' && !hasManualTheme && window.matchMedia) {
                var scheme = window.matchMedia('(prefers-color-scheme: dark)');
                var syncScheme = function() {
                    {{-- A later manual switch retains priority for this preset. --}}
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
        {{-- The layout's page features run from the module bundle
             (resources/js/layout/core.ts). Until it has run, the names that onclick attributes, page
             scripts and admin pages call queue their calls here; core.ts replays them. ES5. --}}
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
            {{-- The watch page's theater buttons get their click listener from core.ts; a click before then
                 is queued here. --}}
            document.addEventListener('click', function (e) {
                var target = e.target;
                if (!w.__avdCore && target && target.closest && target.closest('[data-theater-toggle]')) w.avdToggleTheater();
            });
            {{-- The bundle has run by the load event. If it has not (public/build missing or older than these
                 views, or its scripts blocked), the calls above stay queued: say so instead of failing silently. --}}
            w.addEventListener('load', function () {
                if (!w.__avdCore && w.console) w.console.error('AVD: the layout script bundle did not run, so theater mode, lights out, search (Ctrl+K), watch later and the other layout features are off. Upload public/build together with resources/views, then check that /build/assets/*.js loads.');
            });
        })(window);
        {{-- A select with data-avd-apply (the footer's and the account panel's language) applies an explicit choice only
             (R2A-003, WCAG 3.2.2): a pick in its open list (a click, a tap, the list's Enter) or Enter on the select.
             Windows and Linux browsers change a closed select with the arrow and letter keys and send "change" at once:
             those keys now only show the choices one by one; leaving the select (or the page), Escape, a press that opens
             the list and a page shown again from the history put the page's own choice back. In a form the select sends
             the form, elsewhere it opens the address of its choice. On the document, so the selects work from the first
             paint. ES5. --}}
        (function(w,d){var keyed=null,
        pick=function(e){var s=e.target;return s&&s.tagName==='SELECT'&&s.hasAttribute('data-avd-apply')?s:null},
        own=function(s){for(var i=0;i<s.options.length;i++)if(s.options[i].defaultSelected)return i;return 0},
        back=function(s){if(s.selectedIndex!==own(s))s.selectedIndex=own(s)},
        backAll=function(){var a=d.querySelectorAll('select[data-avd-apply]');for(var i=0;i<a.length;i++)back(a[i])},
        apply=function(s){if(s.selectedIndex===own(s))return;if(s.form){if(s.form.requestSubmit)s.form.requestSubmit();else s.form.submit()}else if(s.value)w.location.href=s.value},
        on=function(t,f){d.addEventListener(t,function(e){var s=pick(e);if(s)f(s,e)},true)};
        on('keydown',function(s,e){var k=e.key||'';if(k==='Enter'){if(s.selectedIndex!==own(s)){e.preventDefault();apply(s)}}
        else if(k==='Escape'||k==='Esc')back(s);
        else if(!e.altKey&&!e.ctrlKey&&!e.metaKey&&(k.length===1||/^(Arrow|Page|Home|End)/.test(k))){keyed=s;w.setTimeout(function(){keyed=null},0)}});
        on('change',function(s){if(s!==keyed)apply(s)});
        on('mousedown',back);on('focusout',back);
        w.addEventListener('pageshow',backAll);w.addEventListener('blur',backAll)})(window,document);
    </script>
    <script>
        {{-- Capability tier (perf v2): html[data-avd-tier] = rich | balanced | lite, decided here
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
           single-signal hint. ES5 only (no ?. or ??); every storage access is guarded. --}}
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
            {{-- Hint: {t, ts, v: build, why, n: 1 pending (one signal) | 2 confirmed, sid}. Only a
               confirmed hint caps the static result; a stale or other-build hint is dropped. --}}
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
            {{-- The two independent signals: the other detector in this session (LoAF + rAF probe),
               or a downgrade in an earlier session. The hint keeps only the level both agree on. --}}
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
                {{-- Never raises the tier and never overrides an admin, visitor or QA pin. --}}
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
                {{-- A clean probe: a single-signal hint left by an earlier session is dropped. --}}
                ok: function () {
                    var h = readHint();
                    if (h && h.n < 2 && h.sid !== sid) store('localStorage', 'avd_tier_hint', null);
                }
            };
            {{-- Balanced runs its bounded title/chip loops only once the page is idle after load
               (motion.scss); this marks that moment. --}}
            function bootIdle() {
                var mark = function () { root.setAttribute('data-avd-boot', 'idle'); };
                if (window.requestIdleCallback) window.requestIdleCallback(mark, {timeout: 3000}); else setTimeout(mark, 300);
            }
            if (document.readyState === 'complete') bootIdle(); else window.addEventListener('load', bootIdle);

            function staticTier() {
                var nav = window.navigator || {};
                var mem = +nav.deviceMemory || 0;
                {{-- Core counts are trusted only where deviceMemory exists (Chromium). --}}
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
                return cpuFast() ? 2 : 1;
            }

            {{-- perf-6: threads and memory say nothing about how fast a CPU is (a weak or busy laptop with
               8 threads and 8 GB got the rich tier, then ran home at 31 fps until the probe caught up).
               Rich also needs the CPU to pass a 2 ms work loop, measured once per tab session (kept in
               sessionStorage avd_tier_cpu as steps per ms). Measured on page loads of the reference desktop:
               130-180 steps/ms, 65-75 with the CPU slowed 2x (still rich), 14-20 slowed 4x (balanced). --}}
            function cpuFast() {
                var BAR = 36;
                var kept = +store('sessionStorage', 'avd_tier_cpu');
                if (kept > 0) return kept >= BAR;
                var perf = window.performance;
                if (!perf || !perf.now) return true;
                var n = 0, x = 0, j, start = perf.now(), end = start + 2;
                while (perf.now() < end) {
                    for (j = 0; j < 1000; j++) { x = (x * 31 + j) % 1000003; }
                    n++;
                }
                var rate = Math.round(n / Math.max(perf.now() - start, 0.1) * 10) / 10 + (x < 0 ? 1 : 0);
                store('sessionStorage', 'avd_tier_cpu', String(rate));
                return rate >= BAR;
            }

            {{-- LoAF governor (Chromium 123+), only for an auto-detected tier above lite. Frames of
               200 ms or more count while the page is visible (not in the second after it becomes
               visible), inside two windows: DOMContentLoaded+1 s to +21 s, and the first scroll to
               +10 s. Three within 10 s drop one tier; five within 10 s, or more than 2.5 s of
               blocking time in one window, drop to lite. --}}
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
                {{-- The old perf key pinned lite forever. --}}
                store('localStorage', 'avd_perf', null);
                {{-- 1. Admin: the server already rendered the pinned tier (admin pages are always rich). --}}
                if (src === 'admin') { apply(cur, 'admin'); return; }
                var qa = /[?&]avd_tier=(lite|balanced|rich|auto)(?:&|$)/.exec(window.location.search || '');
                if (qa) store('sessionStorage', 'avd_tier_qa', qa[1] === 'auto' ? null : qa[1]);
                {{-- 2. Visitor pin, 3. QA pin. --}}
                var pin = idx(store('localStorage', 'avd_tier_user'));
                if (pin >= 0) { apply(pin, 'user'); return; }
                pin = idx(store('sessionStorage', 'avd_tier_qa'));
                if (pin >= 0) { apply(pin, 'qa'); return; }
                {{-- 5. Static signals, capped by a confirmed hint; 4. the session lock only lowers them. --}}
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
        // The Font Awesome solid and regular fonts (header and card icons) are requested with the CSS instead of
        // after it has been parsed and laid out. R1-071: the fonts cut to the icons the site's code names
        // (resources/fonts/fontawesome, a fraction of the full fonts' 158 KB and 25 KB, which competed with the
        // render-blocking CSS on slow phones); the full solid font as well on a page that shows an icon an owner chose
        // outside the cut (App\Support\IconSubset), and instead of the cut fonts on the old admin layout's pages, whose
        // full icon set (fa-full.scss) draws every icon from the full fonts. The font preloads ask for a low priority:
        // the browser fetches them after the render-blocking CSS, still well before the first paint on a slow phone
        // (Fast 3G, CPU x4: the icons' font 3.5 s, the first paint 4.6-5.8 s), instead of holding the CSS back (high
        // priority: the first paint 0.7 s later) or arriving 4 s after it (no preload). A non-English page also
        // preloads its locale chunk, which resources/js/lang/config.ts imports before the components render. Exact
        // build URLs; skipped in hot mode or when the manifest lacks an entry.
        $avdBootPreloads = [];
        $avdBootNonce = null;
        $avdIconExtraCss = \App\Support\IconSubset::extraCss();

        if (! \Illuminate\Support\Facades\Vite::isRunningHot()) {
            $avdIconFonts = ($type ?? null) === 'admin'
                ? ['node_modules/@fortawesome/fontawesome-free/webfonts/fa-solid-900.woff2', 'node_modules/@fortawesome/fontawesome-free/webfonts/fa-regular-400.woff2']
                : ['resources/fonts/fontawesome/fa-solid-900.woff2', 'resources/fonts/fontawesome/fa-regular-400.woff2'];

            if (($type ?? null) !== 'admin' && \App\Support\IconSubset::needsFullFont($avdIconExtraCss)) {
                $avdIconFonts[] = 'node_modules/@fortawesome/fontawesome-free/webfonts/fa-solid-900.woff2';
            }

            foreach ($avdIconFonts as $avdIconFont) {
                try {
                    // The built CSS names its fonts relative to itself (vite.config.ts renderBuiltUrl), so the
                    // URL it requests is the asset() URL on every install type: /build/assets/<file>,
                    // /public/build/assets/<file> (root install) or the CDN ASSET_URL. A preload of any other
                    // URL would be a second, unused download.
                    $avdIconFontUrl = \Illuminate\Support\Facades\Vite::asset($avdIconFont);

                    if ($avdIconFontUrl !== '') {
                        $avdBootPreloads[] = ['font', $avdIconFontUrl];
                    }
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
    <link rel="preload" as="font" type="font/woff2" href="{{ $avdBootPreloadUrl }}" fetchpriority="low" crossorigin>
        @else
    <link rel="modulepreload" as="script" href="{{ $avdBootPreloadUrl }}"@if($avdBootNonce) nonce="{{ $avdBootNonce }}"@endif>
        @endif
    @endforeach
    @php
        // perf-7: the main CSS carries only the Font Awesome icons the site's code names. The icons owners choose
        // (categories, side-menu links, premium feature boxes) that it lacks get their rules here, usually none
        // (App\Support\IconSubset, $avdIconExtraCss above); the old admin layout's pages name icons of their own and
        // link the full set.
        $avdIconsAll = null;

        if (($type ?? null) === 'admin') {
            try {
                $avdIconsAll = \Illuminate\Support\Facades\Vite::asset('resources/sass/fa-full.scss') ?: null;
            } catch (\Throwable) {
                $avdIconsAll = null;
            }
        }
    @endphp
    @if($avdIconExtraCss !== '')
    <style id="avd-icon-extra">{!! $avdIconExtraCss !!}</style>
    @endif
    @if($avdIconsAll)
    <link rel="stylesheet" href="{{ $avdIconsAll }}" data-avd-icons="all">
    @endif
    <style id="dynamic-theme">
        :root {
            --avd-grid-columns: {{ $gridColumns }};
            --avd-font-size-base: {{ $baseFontSize }}px;
            --avd-font-scale-ui: {{ $uiFontRatio }};
            --avd-font-scale-heading: {{ $headingFontRatio }};
            --avd-font-scale-card-title: {{ $cardTitleFontRatio }};
            @if($themeRecord !== null)
            {{-- Compatibility aliases: names older rules and partials read, resolved from the theme tokens (the
                 token file below defines them per mode on html[data-bs-theme]). The compatibility names (--avd-primary(-rgb),
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
            {{-- Server-only copies of the theme colours for the rules that now live in
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
         hidden (0px) side menu width. The back office prints the one-row bar (no second row of sections, no masthead
         or crest: layouts/menus/header.blade.php), so a look whose header token counts two rows or the crest (104,
         112, 76px) gets the bar's real height there: the header has no empty band and every sticky part sits right
         under it (--avd-header-h). The phone bar (60px, layout-theme-b.css) comes
         later and keeps its value. --}}
    <style id="avd-back-office-menu">html[data-bs-theme][data-avd-theme]{--avd-sidebar-w:260px}</style>
    @if(in_array((string) ($themeRecord['layout']['header'] ?? ''), ['megabar', 'masthead', 'masthead_brutal', 'crest'], true))
    <style id="avd-back-office-header">html[data-bs-theme][data-avd-theme]{--avd-header-h:64px}</style>
    @endif
    @endif
    @endif
    {{-- The static theme CSS that used to fill the <style> above is in
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
    {{-- The member CSS entry (member design 5.13): after the theme CSS, before the owner's custom head code (which
         keeps the last word). A build without the entry (an older public/build) renders the page without it, as the
         font preloads above do, instead of failing every page of a signed-in visitor. --}}
    @if($memberCss)
        @php
            try {
                $avdMemberCss = app(\Illuminate\Foundation\Vite::class)('resources/sass/member.scss');
            } catch (\Illuminate\Foundation\ViteException) {
                $avdMemberCss = null;
            }
        @endphp
        {{ $avdMemberCss }}
    @endif
    {{-- R1-070: the member danger tokens of this look (App\Support\Member\DangerMap: at most four short rules, both modes),
         wherever the member entry is printed (by an error page for a guest too), instead of all 348 blocks in it. --}}
    @if(($memberCss || request()->attributes->get('avd.member_css_pushed') === true) && $themeRecord !== null)
        @php
            $avdMemberDanger = \App\Support\Member\DangerMap::css($themeSelection['theme'] ?? null, $themeSelection['flavor'] ?? null);
        @endphp
        @if($avdMemberDanger !== '')
    <style id="avd-member-danger">{!! $avdMemberDanger !!}</style>
        @endif
    @endif
    @if(config('site.website_schema_enabled', true))
        <script type="application/ld+json">{!! json_encode($seoService->websiteSchema(), $jsonScriptFlags) !!}</script>
    @endif
    @if(config('site.search_action_schema_enabled', true))
        <script type="application/ld+json">{!! json_encode($seoService->searchActionSchema(), $jsonScriptFlags) !!}</script>
    @endif
    @stack('structured-data')
    @stack('seo-head')
    {{-- R1-070: the sign-in pages' part of the member kit, after the member entry (and after an error page's own link
         of it, above), on the pages that use it ($memberAuthCss). --}}
    @if($memberAuthCss)
        @php
            try {
                $avdAuthCss = app(\Illuminate\Foundation\Vite::class)('resources/sass/auth-pages.scss');
            } catch (\Illuminate\Foundation\ViteException) {
                $avdAuthCss = null;
            }
        @endphp
        {{ $avdAuthCss }}
    @endif
    {{-- A page's own cached stylesheets (the watch page's rules, watch-7): after every stylesheet above, before the
         body's style blocks and the owner's custom head code. --}}
    @stack('head-styles')
    @if($avdVisitorDock !== null)
    @include('layouts.partials.visitor-choices', ['part' => 'head', 'dock' => $avdVisitorDock])
    @endif
    @if($customHead){!! $customHead !!}@endif
</head>
<body @class(['avd-app', 'has-bottom-nav' => $mobileBottomNavEnabled && !$isBackOfficeLayout, 'has-studio-tabbar' => $studioTabbar, 'avd-admin' => $attributes->get('type') === 'admin', 'has-sidebar' => $sidebarPinnedLayout, 'has-mobile-ad' => $showMobileAd, 'has-hero-zone' => $pageHasHeroZone])>
    {{-- R3-050: the keyboard's first stop on every page: past the header and the side or studio menu to the page's own
         content (shown only while it has the focus: header.scss; the focus move: resources/js/layout/core.ts).
         tabindex="0": Safari's Tab (without "Press Tab to highlight each item") skips links but stops here too. --}}
    <a class="avd-skip-link" href="#main-container" tabindex="0">{{ __('Skip to content') }}</a>
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

        {{-- R1-069: the chrome's partials (header, side menu, account panel, footer) reach the browser without their
             source indentation (App\Support\CompactHtml: a run of white space alone between two tags becomes one
             space, which renders the same): about 20 KB of a signed-in visitor's page. --}}
        @php ob_start(); @endphp
        @include('layouts.menus.header')
        {!! \App\Support\CompactHtml::compact((string) ob_get_clean()) !!}
        {{-- Header fit: the site's name is never cut, and the bar never runs off the screen, while the bar's own links can
             make room. Links the row cannot hold step out from the end (the current page's last); every one of them stays
             in the side menu. The search box gives way before them (header.scss). Imperial Salon's centred crest at
             1200-1440px in French, Spanish, German or Tagalog cut the name to one to six letters. Runs before the first
             paint, again when the bar's width changes and when a web font arrives. Only where the bar carries links (a
             public page of a look that shows them; the back-office bar has none). ES5. --}}
        @if(! $isBackOfficeLayout && \App\Support\ThemeStructure::headerLinksVisible())
        <script>(function(){
        var row=document.querySelector('.avd-header-inner'),nav=row&&row.querySelector('.avd-hnav'),
        brand=row&&row.querySelector('.avd-brand');
        if(!nav||!brand)return;
        var text=brand.querySelector('.avd-brand-text'),links=[],current=[],queued=0,i;
        for(i=nav.children.length-1;i>=0;i--)(nav.children[i].classList.contains('active')?current:links).push(nav.children[i]);
        links=links.concat(current);
        var tight=function(){return(text&&text.scrollWidth>text.clientWidth+1)||brand.scrollWidth>brand.clientWidth+1
        ||row.scrollWidth>row.clientWidth+1};
        var fit=function(){
        for(i=0;i < links.length;i++)links[i].hidden=false;
        if(!nav.getClientRects().length)return;
        for(i=0;i < links.length&&tight();i++)links[i].hidden=true};
        var later=function(){if(queued)return;queued=1;requestAnimationFrame(function(){queued=0;fit()})};
        fit();
        if(window.ResizeObserver)new ResizeObserver(later).observe(row);else addEventListener('resize',later);
        if(document.fonts&&document.fonts.addEventListener)document.fonts.addEventListener('loadingdone',later);
        addEventListener('load',later)})();</script>
        @endif

        {{-- The --sidebar-top updater runs from resources/js/layout/core.ts. --}}

        <main class="avd-main position-relative">
            <div class="avd-shell d-flex">
                @php ob_start(); @endphp
                @include('layouts.menus.sidebars.'.$sidebar, ['type' => $type ?? null])
                {!! \App\Support\CompactHtml::compact((string) ob_get_clean()) !!}
                <div id="main-container" class="container-fluid my-3 @yield('class')" style="@yield('style')" data-avd-main>
                    {{$slot}}
                    @yield('content')
                </div>
            </div>

            @if (Auth::check())
                @include('users.videos.modals.upload')
                @php ob_start(); @endphp
                @include('layouts.menus.account')
                {!! \App\Support\CompactHtml::compact((string) ob_get_clean()) !!}
            @endif
        </main>

        @php
            // The slim footer (studio and account pages) lines up with the page's frame: the page root's width
            // (member/_kit.scss: .mk-page 1280px, .mk-page--form 1180px, .mk-page--narrow 880px), known here because the
            // content has rendered. At 1920px the footer spanned the whole main column: 151px before the page, its
            // language select 43px past it.
            $footerFrame = null;
            if (($footer ?? null) === 'slim') {
                $footerFrame = preg_match('/class="[^"]*\bmk-page\b[^"]*"/', (string) $slot.$__env->yieldContent('content'), $footerFrameClass) === 1
                    ? (str_contains($footerFrameClass[0], 'mk-page--narrow') ? 'narrow' : (str_contains($footerFrameClass[0], 'mk-page--form') ? 'form' : 'page'))
                    : null;
            }
        @endphp
        @php ob_start(); @endphp
        @include('layouts.menus.footer', ['footerVariant' => ($footer ?? null) === 'slim' ? 'slim' : 'full', 'footerFrame' => $footerFrame])
        {!! \App\Support\CompactHtml::compact((string) ob_get_clean()) !!}

        @if($showDailyPill)
        @php
            // The day's design in the reader's language: its look and colour style by name (the catalogue's English
            // "<theme> · <colour style>" before), the day of the year and the date in the admin's "Date format" (it
            // was always English: "October 26, 2026").
            $dailyName = filled($activeDailyDesign['theme_name'] ?? null)
                ? __((string) $activeDailyDesign['theme_name']).(filled($activeDailyDesign['flavor_name'] ?? null) ? ' · '.__((string) $activeDailyDesign['flavor_name']) : '')
                : (string) ($activeDailyDesign['name'] ?? '');
            $dailyDayText = __('Day :day of :days', ['day' => $activeDailyDesign['day'], 'days' => $dailyDaysInYear]);
            $dailyDateText = \App\Support\SiteDate::date(filled($activeDailyDesign['date'] ?? null) ? \Carbon\CarbonImmutable::parse((string) $activeDailyDesign['date']) : now());
            $dailyHoliday = filled($activeDailyDesign['holiday'] ?? null) ? __((string) $activeDailyDesign['holiday']) : '';
            $dailyColours = ['primary' => __('Primary colour'), 'secondary' => __('Secondary colour'), 'accent' => __('Accent colour')];
            try {
                $avdDailySheetCss = \Illuminate\Support\Facades\Vite::asset('resources/sass/daily-sheet.scss');
            } catch (\Illuminate\Foundation\ViteException) {
                $avdDailySheetCss = null;
            }
        @endphp
        {{-- The sheet's own rules (resources/sass/daily-sheet.scss) load without holding the page: the sheet shows only
             after a click, so the main CSS every visitor parses before the first paint does not carry them. --}}
        @if($avdDailySheetCss)
            <link rel="stylesheet" id="avdDailySheetCss" href="{{ $avdDailySheetCss }}" media="print" onload="this.media='all'">
        @endif
        {{-- "Today's design" opens from ONE pill at the bottom-start corner (.avd-daily-pill, below). The sheet is the
             kit's dialog (a centred, side or bottom sheet as the look's pop-up style says) in the look's own colours, in
             both modes: it was white text on a fixed dark panel, unreadable in the light looks. Plain words (not
             "365-DAY AESTHETIC IDENTITY" or "SOUNDSCAPE SYNTHESIS"), buttons that keep their words on one line, and the
             sound switch only where the site plays sounds (Appearance > Effects & speed > Click sounds), saying
             whether they are on. --}}
        <div class="modal fade" id="dailyAestheticModal" tabindex="-1" aria-labelledby="dailyAestheticModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable mk-dialog avd-mk avd-daily-sheet">
                <div class="modal-content">
                    <div class="mk-sheet__head">
                        <span class="mk-sheet__grip" aria-hidden="true"></span>
                        <div class="avd-daily-sheet__head">
                            <span class="avd-daily-sheet__kicker"><i class="fa-solid fa-calendar-day" aria-hidden="true"></i> {{ __("Today's design") }}</span>
                            <h2 class="mk-sheet__title" id="dailyAestheticModalLabel">{{ $dailyName }}</h2>
                            <p class="avd-daily-sheet__meta">{{ $dailyDayText }}&nbsp;· {{ $dailyDateText }}@if($dailyHoliday !== '')&nbsp;· <span class="avd-daily-sheet__holiday"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> {{ $dailyHoliday }}</span>@endif</p>
                        </div>
                        <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </div>

                    <div class="mk-sheet__body">
                        <p class="avd-daily-sheet__mood">{{ $activeDailyDesign['mood'] ?? __('A distinct and inspiring visual experience crafted uniquely for today.') }}</p>

                        <section class="avd-daily-sheet__part avd-daily-sheet__colours" aria-labelledby="avdDailyColoursTitle">
                            <h3 class="avd-daily-sheet__label" id="avdDailyColoursTitle">{{ __("Today's colours") }}</h3>
                            <p class="avd-daily-sheet__hint">{{ __('Click a colour to copy its code.') }}</p>
                            <div class="avd-daily-sheet__swatches">
                                @foreach($dailyColours as $dailyColourKey => $dailyColourName)
                                    <button type="button" class="avd-daily-swatch" aria-label="{{ __(':label: :value', ['label' => $dailyColourName, 'value' => __('Copy colour code :code', ['code' => $activeDailyDesign[$dailyColourKey]])]) }}" onclick="window.copyColorHex(@js((string) $activeDailyDesign[$dailyColourKey]), @js((string) $dailyColourName))">
                                        <span class="avd-daily-swatch__chip" style="background: {{ $activeDailyDesign[$dailyColourKey] }}" aria-hidden="true"></span>
                                        <span class="avd-daily-swatch__name">{{ $dailyColourName }}</span>
                                        <span class="avd-daily-swatch__code" dir="ltr">{{ $activeDailyDesign[$dailyColourKey] }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </section>

                        <section class="avd-daily-sheet__part" aria-labelledby="avdDailyGlowTitle">
                            <h3 class="avd-daily-sheet__label" id="avdDailyGlowTitle">{{ __('Glow strength') }}</h3>
                            <div class="avd-daily-sheet__choices" role="group" aria-labelledby="avdDailyGlowTitle">
                                @foreach(['subtle' => __('Subtle'), 'balanced' => __('Balanced'), 'vivid' => __('Vivid')] as $dailyGlow => $dailyGlowLabel)
                                    <button type="button" @class(['avd-daily-choice glow-btn', 'active' => $dailyGlow === 'balanced']) data-glow="{{ $dailyGlow }}" aria-pressed="{{ $dailyGlow === 'balanced' ? 'true' : 'false' }}" onclick="window.setPersonalGlow('{{ $dailyGlow }}')">{{ $dailyGlowLabel }}</button>
                                @endforeach
                            </div>
                        </section>

                        @if($uiAudioFeedbackEnabled)
                            <section class="avd-daily-sheet__part" aria-labelledby="avdDailySoundTitle">
                                <h3 class="avd-daily-sheet__label" id="avdDailySoundTitle">{{ __('Sounds') }}</h3>
                                <button type="button" class="avd-daily-choice avd-daily-sheet__sound" id="avdSoundToggleBtn" aria-pressed="true" onclick="window.AvdAudio && window.AvdAudio.toggleSound()"><i class="fa-solid fa-volume-high" aria-hidden="true"></i> <span id="avdSoundToggleText">{{ __('Sound effects: on') }}</span></button>
                            </section>
                        @endif

                        <section class="avd-daily-sheet__part" aria-labelledby="avdDailyAccentTitle">
                            <div class="avd-daily-sheet__row">
                                <h3 class="avd-daily-sheet__label" id="avdDailyAccentTitle">{{ __('Your accent colour') }}</h3>
                                <button type="button" class="mk-btn mk-btn--quiet mk-btn--sm" onclick="window.resetCustomAccent && window.resetCustomAccent()"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i><span>{{ __("Use today's colour") }}</span></button>
                            </div>
                            <div class="avd-daily-sheet__accent">
                                <input type="color" id="avdCustomColorPicker" class="avd-daily-sheet__picker" value="{{ $activeDailyDesign['primary'] }}" aria-labelledby="avdDailyAccentTitle" onchange="window.setCustomAccent && window.setCustomAccent(this.value)" oninput="window.setCustomAccent && window.setCustomAccent(this.value)">
                                <div class="avd-daily-sheet__accent-text">
                                    <div class="avd-daily-sheet__accent-name" id="avdCustomColorName">{{ $dailyName }}</div>
                                    <p class="avd-daily-sheet__hint">{{ __('Changes the colour of buttons and highlights on this device.') }}</p>
                                </div>
                            </div>
                        </section>

                        @if($tomorrowDailyDesign)
                            @php
                                $dailyTomorrowName = filled($tomorrowDailyDesign['theme_name'] ?? null)
                                    ? __((string) $tomorrowDailyDesign['theme_name']).(filled($tomorrowDailyDesign['flavor_name'] ?? null) ? ' · '.__((string) $tomorrowDailyDesign['flavor_name']) : '')
                                    : (string) ($tomorrowDailyDesign['name'] ?? '');
                            @endphp
                            <section class="avd-daily-sheet__part avd-daily-sheet__row avd-daily-sheet__tomorrow" aria-labelledby="avdDailyTomorrowTitle">
                                <div class="avd-daily-sheet__accent-text">
                                    <h3 class="avd-daily-sheet__label" id="avdDailyTomorrowTitle">{{ __('Tomorrow') }}</h3>
                                    <div class="avd-daily-sheet__accent-name">{{ $dailyTomorrowName }}</div>
                                </div>
                                <a href="?preview_day={{ $tomorrowDailyDesign['day'] }}" class="mk-btn mk-btn--secondary mk-btn--sm"><i class="fa-solid fa-eye" aria-hidden="true"></i><span>{{ __('Preview') }}</span></a>
                            </section>
                        @endif
                    </div>

                    <div class="mk-sheet__foot">
                        <button type="button" class="mk-btn mk-btn--quiet" onclick="window.avdSurpriseMe()"><i class="fa-solid fa-dice" aria-hidden="true"></i><span>{{ __('Surprise me') }}</span></button>
                        <button type="button" class="mk-btn mk-btn--secondary" onclick="window.generateAestheticCard()"><i class="fa-solid fa-image" aria-hidden="true"></i><span>{{ __('Download wallpaper') }}</span></button>
                    </div>
                </div>
            </div>
        </div>
        {{-- Each time the sheet opens: its rules apply (also if it opens before they finished loading), and a saved accent
             colour reads "Your colour: #FF0066" in the reader's language (the page-load restore in core.ts wrote the
             English "Custom Tone: ..."). ES5. --}}
        <script>(function(){
        var sheet=document.getElementById('dailyAestheticModal');
        if(sheet)sheet.addEventListener('show.bs.modal',function(){
        var css=document.getElementById('avdDailySheetCss'),name=document.getElementById('avdCustomColorName'),hex=null;
        if(css&&css.media!=='all')css.media='all';
        try{hex=localStorage.getItem('avd_custom_accent')}catch(e){}
        if(name&&hex)name.textContent=@json((string) __('Your colour: :code')).replace(':code',function(){
        return'\u2066'+String(hex).toUpperCase()+'\u2069'})})})();</script>
        @endif

        {{-- Spotlight search (Ctrl+K or /; watch-36): the look's own colours (token classes in enhancements.scss, both
             modes), plain words, and no product name. No backdrop blur. --}}
        <div class="modal fade avd-spotlight" id="avdSpotlightModal" tabindex="-1" aria-labelledby="avdSpotlightModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered avd-spotlight__dialog">
                <div class="modal-content avd-spotlight__content">
                    <h2 class="visually-hidden" id="avdSpotlightModalLabel">{{ __('Search') }}</h2>
                    <div class="avd-spotlight__head">
                        <i class="fa-solid fa-magnifying-glass avd-spotlight__icon" aria-hidden="true"></i>
                        <form action="{{ (function_exists('locale_url') ? locale_url('/search') : url('/search')) }}" method="GET" class="flex-grow-1 mb-0" id="avdSpotlightForm" role="search">
                            <label class="visually-hidden" for="avdSpotlightInput">{{ __('Search videos, tags and channels') }}</label>
                            <input type="search" name="q" id="avdSpotlightInput" class="avd-spotlight__input" placeholder="{{ __('Search videos, tags and channels') }}" autocomplete="off" maxlength="255" enterkeyhint="search">
                        </form>
                        <kbd class="avd-spotlight__kbd">Esc</kbd>
                    </div>
                    <div class="avd-spotlight__body">
                        <p class="avd-spotlight__group">{{ __('Popular searches') }}</p>
                        <div class="avd-spotlight__chips">
                            {{-- only suggestions that find videos on this site (catalogs differ per install) --}}
                            @foreach (\App\Support\SpotlightChips::all() as $spotlightChip)
                                <a href="{{ (function_exists('locale_url') ? locale_url('/search?q=' . urlencode($spotlightChip['q'])) : url('/search?q=' . urlencode($spotlightChip['q']))) }}" class="avd-spotlight__chip">
                                    <i class="fa-solid fa-hashtag" aria-hidden="true"></i> {{ $spotlightChip['label'] }}
                                </a>
                            @endforeach
                            <a href="{{ (function_exists('locale_url') ? locale_url('/categories') : url('/categories')) }}" class="avd-spotlight__chip">
                                <i class="fa-solid fa-shapes" aria-hidden="true"></i> {{ __('All categories') }}
                            </a>
                        </div>

                        <p class="avd-spotlight__group">{{ __('Go to') }}</p>
                        <div class="avd-spotlight__links">
                            <a href="{{ (function_exists('locale_url') ? locale_url('/') : url('/')) }}" class="avd-spotlight__link">
                                <span><i class="fa-solid fa-house" aria-hidden="true"></i>{{ __('Home') }}</span>
                            </a>
                            <a href="{{ (function_exists('locale_url') ? locale_url('/categories') : url('/categories')) }}" class="avd-spotlight__link">
                                <span><i class="fa-solid fa-shapes" aria-hidden="true"></i>{{ __('Categories') }}</span>
                            </a>
                            @if($showDailyPill)
                            <button type="button" class="avd-spotlight__link" onclick="var m=window.bootstrap&&bootstrap.Modal.getInstance(document.getElementById('avdSpotlightModal')); if(m){m.hide();} window.openDailyAestheticModal && window.openDailyAestheticModal();">
                                <span><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>{{ __("Today's design") }}</span>
                                <kbd class="avd-spotlight__kbd">D</kbd>
                            </button>
                            @endif
                            @if($watchKeyboardShortcutsEnabled)
                            <button type="button" class="avd-spotlight__link" onclick="var m=window.bootstrap&&bootstrap.Modal.getInstance(document.getElementById('avdSpotlightModal')); if(m){m.hide();} var o=document.getElementById('shortcuts-overlay'); if(o){o.classList.add('active'); window.AvdAudio&&window.AvdAudio.playClick();}">
                                <span><i class="fa-solid fa-keyboard" aria-hidden="true"></i>{{ __('Keyboard shortcuts') }}</span>
                                <kbd class="avd-spotlight__kbd">?</kbd>
                            </button>
                            @endif
                        </div>
                    </div>
                    <div class="avd-spotlight__foot">
                        {{ __('Press / or Ctrl+K anywhere to search.') }}
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
        @php
            // The phone bottom bar (auth-3, channel-1, watch-1, channel-29, watch-10). "Your channel" opens the member's
            // own channel by its route key (slug or profile id: a username is not a channel address, /@<username> was a
            // 404 for most members) and is marked on that channel; "Library" opens the library page (history, watch
            // later, liked videos, playlists) and is marked on every page of the library.
            $bottomNavUser = auth()->user();
            $bottomNavRouteUser = request()->route('user');
            $bottomNavOwnChannel = $bottomNavUser !== null && request()->routeIs('user.show')
                && $bottomNavRouteUser instanceof \App\Models\User && $bottomNavRouteUser->is($bottomNavUser);
            $bottomNavItems = [
                'home' => request()->routeIs('pages.home'),
                'categories' => request()->routeIs('pages.categories', 'pages.category'),
                'explore' => request()->routeIs('search.index'),
                'library' => request()->routeIs('pages.library', 'pages.history', 'custom-playlist.show', 'playlist.manage', 'subscription.manage'),
                'channel' => $bottomNavOwnChannel,
                'signin' => request()->routeIs('login'),
                'signup' => request()->routeIs('registration'),
            ];
        @endphp
        <nav class="avd-mobile-bottom-nav d-md-none fixed-bottom" id="avdMobileBottomNav" aria-label="{{ __('Mobile navigation') }}" data-avd-bottom-nav>
            <div class="d-flex align-items-center justify-content-around py-2 px-1">
                <a href="{{ (function_exists('locale_url') ? locale_url('/') : url('/')) }}" @class(['avd-mob-nav-item', 'active' => $bottomNavItems['home']]) @if($bottomNavItems['home']) aria-current="page" @endif>
                    <i class="fa-solid fa-house" aria-hidden="true"></i>
                    <span>{{ __('Home') }}</span>
                </a>
                <a href="{{ (function_exists('locale_url') ? locale_url('/categories') : url('/categories')) }}" @class(['avd-mob-nav-item', 'active' => $bottomNavItems['categories']]) @if($bottomNavItems['categories']) aria-current="page" @endif>
                    <i class="fa-solid fa-shapes" aria-hidden="true"></i>
                    <span>{{ __('Categories') }}</span>
                </a>
                @if($configBool('header_random_button_enabled', true))
                <a href="{{ (function_exists('locale_url') ? locale_url(route('video.random')) : route('video.random')) }}" class="avd-mob-nav-item avd-mob-special">
                    <span class="avd-mob-fab">
                        <i class="fa-solid fa-shuffle" aria-hidden="true"></i>
                    </span>
                    <span>{{ __('Random') }}</span>
                </a>
                @else
                <a href="{{ (function_exists('locale_url') ? locale_url('/search') : url('/search')) }}" @class(['avd-mob-nav-item', 'active' => $bottomNavItems['explore']]) @if($bottomNavItems['explore']) aria-current="page" @endif>
                    <i class="fa-solid fa-compass" aria-hidden="true"></i>
                    <span>{{ __('Explore') }}</span>
                </a>
                @endif
                @auth
                <a href="{{ locale_url(route('pages.library')) }}" @class(['avd-mob-nav-item', 'active' => $bottomNavItems['library']]) @if($bottomNavItems['library']) aria-current="page" @endif>
                    <i class="fa-solid fa-folder-open" aria-hidden="true"></i>
                    <span>{{ __('Library') }}</span>
                </a>
                <a href="{{ locale_url(route('user.show', $bottomNavUser)) }}" @class(['avd-mob-nav-item', 'active' => $bottomNavItems['channel']]) @if($bottomNavItems['channel']) aria-current="page" @endif>
                    <i class="fa-solid fa-circle-user" aria-hidden="true"></i>
                    <span>{{ __('Your channel') }}</span>
                </a>
                @else
                <a href="{{ $signInUrl }}" @class(['avd-mob-nav-item', 'active' => $bottomNavItems['signin']]) @if($bottomNavItems['signin']) aria-current="page" @endif>
                    <i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i>
                    <span>{{ __('Sign in') }}</span>
                </a>
                @if(config('site.registration_enabled', true))
                <a href="{{ $signUpUrl }}" @class(['avd-mob-nav-item', 'active' => $bottomNavItems['signup']]) @if($bottomNavItems['signup']) aria-current="page" @endif>
                    <i class="fa-solid fa-user-plus" aria-hidden="true"></i>
                    <span>{{ __('Create account') }}</span>
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
        {{-- The start of every address the scripts build (resources/js/functions/sitePath.ts): the site's folder, then
             /index.php where its links carry it. --}}
        window.SITE_BASE = {!! json_encode(site_folder() . (index_php_links() ? '/index.php' : ''), $jsonScriptFlags) !!}
        window.API_BASE = '{{ str_contains(request()->getRequestUri(), "/index.php") ? url("/index.php/api") : url("/api") }}'
        window.AD_CONFIG = {
            enabled: {{ $showAds ? 'true' : 'false' }},
            prerollEnabled: {{ ($showAds && config('site.ad_preroll_enabled', false)) ? 'true' : 'false' }},
            prerollDuration: {{ config('site.ad_preroll_duration', 5) }},
            prerollMediaUrl: {!! json_encode($showAds ? config('site.ad_preroll_media_url', '') : '', $jsonScriptFlags) !!},
            prerollMediaUrls: {!! json_encode($showAds ? $adPrerollMediaUrls : [], $jsonScriptFlags) !!},
            prerollAds: {!! json_encode($showAds ? $adPrerollAds : [], $jsonScriptFlags) !!},
            prerollCode: {!! json_encode($showAds ? config('site.ad_code_video', '') : '', $jsonScriptFlags) !!},
            {{-- The list ad code and shape of each list of Money > Ads by its ad context (App\Support\ListAdContexts):
                 one entry per list, so each code is printed once. --}}
            gridAdCodeByContext: {!! json_encode($showAds ? \App\Support\ListAdContexts::codes() : [], $jsonScriptFlags) !!},
            gridPosition: {{ config('site.ad_grid_position', 6) }},
            gridMaxPerFeed: {{ config('site.ad_grid_max_per_feed', 1) }},
            gridLayout: {!! json_encode(config('site.ad_grid_layout', 'grid_card'), $jsonScriptFlags) !!},
            gridLayoutByContext: {!! json_encode($showAds ? \App\Support\ListAdContexts::shapes() : [], $jsonScriptFlags) !!},
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
                    <h3 class="mb-0"><i class="fa-solid fa-keyboard text-info" aria-hidden="true"></i> {{__('Keyboard shortcuts')}}</h3>
                    <button type="button" class="btn-close btn-close-white" onclick="document.getElementById('shortcuts-overlay').classList.remove('active')"></button>
                </div>
                <div class="shortcuts-grid">
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Full screen')}}</span><kbd>F</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Theater mode')}}</span><kbd>T</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Lights out')}}</span><kbd>L</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Color filter')}}</span><kbd>C</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Mirror the video')}}</span><kbd>M</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Aspect ratio')}}</span><kbd>W</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Rotate the video')}}</span><kbd>O</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Focus mode')}}</span><kbd>Shift+F</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Video details')}}</span><kbd>I</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Glow around the player')}}</span><kbd>A</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{ __(':label: :value', ['label' => __('Color filter'), 'value' => __('Night')]) }}</span><kbd>Shift+N</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Sleep timer')}}</span><kbd>Z</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Notes')}}</span><kbd>N</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Mini player')}}</span><kbd>P</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{ __("Today's design") }}</span><kbd>D</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Search')}}</span><kbd>Ctrl+K / /</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Keyboard shortcuts')}}</span><kbd>?</kbd></div>
                    <div class="shortcut-item"><span class="shortcut-label">{{__('Close / Exit')}}</span><kbd>Esc</kbd></div>
                </div>
            </div>
        </div>
    @endif

    @if($backToTopEnabled)
        {{-- data-label: the floating pill's visible label (layout-theme-a.css, content: attr(data-label)), translated
             for every language. It is the button's own name, "Back to top": the short "Top" is the
             ranking word of other screens and read "summit" or "leading" in many languages. Phones show the icon only. --}}
        <button type="button" class="back-to-top" id="back-to-top" tabindex="-1" title="{{ __('Back to top') }}" aria-label="{{ __('Back to top') }}" data-label="{{ __('Back to top') }}" onclick="window.scrollTo({top:0,behavior:'{{ $scrollBehavior }}'})">
            <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>
        </button>
    @endif

    {{-- A demo site's visitor buttons, only while their settings are on ($avdVisitorDock, above). --}}
    @if($avdVisitorDock !== null)
        @include('layouts.partials.visitor-choices', ['part' => 'body', 'dock' => $avdVisitorDock])
    @endif

    {{-- One toast system on every page (watch-47; member design 4.4, 5.10): the member kit's toast (.mk-toast, its
         CSS is in the main CSS), drawn by resources/js/layout/core.ts (window.avdToast, showCopyToast and the error
         toast of resources/js/functions/toast.ts). Above the bottom bar, the ad strip and the member bars; at the top
         while a dialog or a side panel is open. A toast that comes with the page is printed here. --}}
    <div class="mk-toasts avd-mk" id="avd-toasts" role="region" aria-label="{{ __('Messages') }}" data-avd-toasts>
        @if($flashToast)
            <div @class(['mk-toast', 'mk-toast--'.$flashToast['type']]) role="{{ $flashToast['type'] === 'error' ? 'alert' : 'status' }}" data-avd-toast data-avd-toast-flash>
                <i @class(['fa-solid', 'fa-circle-check' => $flashToast['type'] === 'success', 'fa-triangle-exclamation' => $flashToast['type'] === 'error' || $flashToast['type'] === 'warning', 'fa-circle-info' => $flashToast['type'] === 'info']) aria-hidden="true"></i>
                <span class="mk-toast__text">{{ $flashToast['message'] }}</span>
                <button type="button" class="mk-icon-btn mk-icon-btn--sm mk-toast__close" aria-label="{{ __('Close') }}" data-avd-toast-close><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
        @endif
    </div>

    {{-- Back-to-top, theater mode and lights out, the keyboard shortcuts, the toasts, the UI
         sounds, the Spotlight palette, the Daily HUD and the accent restore run from resources/js/layout/core.ts
         (and its daily-hud / decor chunks); window.* names called before it runs are queued by the <head> stubs. --}}
    @if($jsTheaterMemoryEnabled)
    <script>
        {{-- Theater mode memory: restored at this point of the parse, as before (the toggle is in
           resources/js/layout/core.ts). No scroll reset here any more: only a real
           change of mode resets the horizontal scroll. ES5. --}}
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
    {{-- The click ripple engine runs from the module bundle (resources/js/layout/ripple.ts, installed by core.ts before
         DOMContentLoaded): 9 KB less inline script on every page (member design 4.3 layout budget). --}}

    {{-- Cinema Lights Out Backdrop --}}
    <div id="avdLightsOutBackdrop" role="button" tabindex="0" aria-label="{{ __('Lights Out') }}" onclick="window.avdToggleLightsOut()" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.avdToggleLightsOut();}"></div>

    {{-- Seasonal Ambient Particles Canvas: never on a member page (a requestAnimationFrame loop at rest, 4.1a). --}}
    @if($showDailyParticles)
        <canvas id="avdSeasonalAtmosphere"></canvas>
    @endif

    {{-- "Today's design" (daily_design_show_badge while the design changes every day): ONE pill at the bottom-start
         corner (member design 4.4, OD-M17). Its place is logical (.avd-daily-pill: inset-inline-start, so it
         mirrors in Arabic and never meets the toasts and "Back to top" at the bottom-end corner) and it floats above
         the bottom layers (--avd-bottom-chrome, --mk-bars-h). Not on the member back office or the sign-in pages
         ($showDailyPill). Two real buttons: open the day's design, hide the pill. --}}
    @if($showDailyPill)
        <div class="avd-daily-pill d-none d-md-flex" id="avdDailyPill" style="--avd-daily-primary: {{ $activeDailyDesign['primary'] }}; --avd-daily-accent: {{ $activeDailyDesign['accent'] }};">
            <button type="button" class="avd-daily-pill__open" id="dailyThemeBadge" aria-haspopup="dialog" aria-controls="dailyAestheticModal" title="{{ __("Open today's design") }}" onclick="window.openDailyAestheticModal && window.openDailyAestheticModal()">
                <span class="avd-daily-pill__mark" aria-hidden="true"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
                <span class="avd-daily-pill__label">{{ __('Today:') }}</span>
                <strong class="avd-daily-pill__name">{{ $dailyName }}</strong>
                <span class="avd-daily-pill__day">{{ $dailyDayText }}</span>
                @if($dailyHoliday !== '')
                    <span class="avd-daily-pill__holiday">{{ $dailyHoliday }}</span>
                @endif
            </button>
            {{-- Hides the pill for this page. From the keyboard (a click with detail 0) focus moves on to the page's
                 content, so a keyboard user is not sent back to the top of the page. --}}
            <button type="button" class="avd-daily-pill__close" aria-label="{{ __('Close') }}" title="{{ __('Close') }}" onclick="var pill = this.closest('.avd-daily-pill'); if (!event.detail) { var next = document.getElementById('main-container'); if (next) { if (!next.hasAttribute('tabindex')) next.setAttribute('tabindex', '-1'); next.focus({preventScroll: true}); } } if (pill) pill.style.setProperty('display', 'none', 'important');">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </div>
    @endif

    @php
        $authWatchLaterIds = Auth::check()
            ? (Auth::user()->playlists()->where('title', \App\Playlists\Types\WatchLaterPlaylist::getName())->first()?->videos()->pluck('videos.id')->map(fn($id) => (int)$id)->toArray() ?? [])
            : [];
        // The translations and settings of resources/js/layout/*.ts, once, instead of
        // Blade tokens inside inline scripts. Plain text, not HTML: the scripts set it as
        // textContent, title or canvas text, and escape it where it goes into markup (avdToast). The JSON
        // flags (JSON_HEX_TAG, _AMP, _APOS, _QUOT) write < > & ' " as JSON unicode escapes, so no value can
        // end the <script> element.
        $avdLayoutJs = [
            't' => [
                // a name and its value in the reader's punctuation (R3-048: French "Éteindre les lumières : Activé")
                'lightsOut' => __(':label: :value', ['label' => __('Lights out'), 'value' => __('On').' (L)']),
                'lightsRestored' => __(':label: :value', ['label' => __('Lights out'), 'value' => __('Off')]),
                'audioOn' => (string) __('Sound effects: on'),
                'audioOff' => (string) __('Sound effects: off'),
                'audioEnabled' => (string) __('Sound effects on'),
                'audioMuted' => (string) __('Sound effects off'),
                'copied' => (string) __("Copied"),
                'copiedColon' => (string) __("Copied:"),
                'wallpaperDownloaded' => (string) __('Wallpaper downloaded'),
                'cardDownloaded' => (string) __('Wallpaper downloaded'),
                // "Today's design" (resources/js/layout/daily-hud.ts): the wallpaper's words and the sheet's messages.
                'dailyTitle' => (string) __("Today's design"),
                'dayOf' => (string) __('Day :day of :days'),
                'colourPrimary' => (string) __('Primary colour'),
                'colourSecondary' => (string) __('Secondary colour'),
                'colourAccent' => (string) __('Accent colour'),
                'customColour' => (string) __('Your colour: :code'),
                'loadingDay' => (string) __('Loading day :day'),
                'wlPromptTitle' => (string) __('Sign in to save videos'),
                'wlPromptMessage' => (string) __('Watch later keeps videos for you on every device. It takes a minute to create an account.'),
                'wlQuickSaveMessage' => (string) __('Watch later keeps videos for you on every device. It takes a minute to create an account.'),
                'wlSavedTitle' => (string) __('Remove from Watch later'),
                'wlTitle' => (string) __('Save to Watch later'),
                'wlSaved' => (string) __('Saved to Watch later'),
                'wlRemoved' => (string) __('Removed from Watch later'),
                'wlFailed' => (string) __('That did not work. Check your connection and try again.'),
                // The toast system (core.ts) and the searchable lists of app.ts (TomSelect, studio-27).
                'close' => (string) __('Close'),
                'error' => (string) __('Something went wrong. Please try again.'),
                'linkCopied' => (string) __('Link copied'),
                'shareUrl' => (string) __('Share this link:'),
                'noResults' => (string) __('No results for ":query"'),
                'removeItem' => (string) __('Remove'),
            ],
            'theaterMemory' => (bool) $jsTheaterMemoryEnabled,
            'copyToast' => (bool) $jsCopyToastEnabled,
            'primaryColor' => (string) $themeBrandColor,
            'themePreset' => (string) $themePreset,
            'siteName' => (string) config('app.name'),
            'user' => [
                'isAuthenticated' => Auth::check(),
                'id' => Auth::id(),
                'loginUrl' => $signInUrl,
                'registerUrl' => $signUpUrl,
                'watchLaterIds' => $authWatchLaterIds,
            ],
            'dailyCard' => [
                'name' => (string) ($dailyName ?? __((string) ($themeRecord['name'] ?? config('app.name')))),
                'day' => (string) ($activeDailyDesign['day'] ?? now()->dayOfYear),
                'date' => \App\Support\SiteDate::date(now()),
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
                'dateLabel' => (string) ($dailyDateText ?? $activeDailyDesign['date_label']),
                'name' => (string) ($dailyName ?? $activeDailyDesign['name']),
                'seasonMood' => (string) __((string) $activeDailyDesign['season_mood']),
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

    @include('layouts.partials.auth-prompt')
    {{-- Appearance draft preview (admin only, the ApplyAppearanceDraft middleware marks the request): the "you are previewing a draft" banner. --}}
    @includeWhen(request()->attributes->get('avd.appearance_draft'), 'admin.appearance.partials.draft-banner')
</body>
</html>
