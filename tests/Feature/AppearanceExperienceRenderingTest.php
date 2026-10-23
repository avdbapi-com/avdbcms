<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Support\AppearanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppearanceExperienceRenderingTest extends TestCase
{
    use RefreshDatabase;

    private array $runtimeFlagEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Public rendering tests need the normal installed-site route. Keep
        // the installer in its default non-reinstall state for this class and
        // restore the process environment after every test.
        foreach (['APP_INSTALLED' => 'true', 'INSTALLER_ALLOW_REINSTALL' => 'false'] as $key => $value) {
            $this->runtimeFlagEnvironment[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->runtimeFlagEnvironment as $key => [$envValue, $serverValue, $processValue]) {
            if ($envValue === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envValue;
            }

            if ($serverValue === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $serverValue;
            }

            putenv($processValue === false ? $key : $key . '=' . $processValue);
        }

        $this->runtimeFlagEnvironment = [];

        parent::tearDown();
    }

    public function test_homepage_obeys_admin_appearance_composition_toggles(): void
    {
        $this->withoutVite();

        Category::query()->create([
            'title' => 'Hidden Category Tab',
            'slug' => 'hidden-category-tab',
            'icon' => 'film',
            'in_menu' => true,
            'position' => 1,
        ]);

        config([
            'site.home_intro_enabled' => false,
            'site.home_category_tabs_enabled' => false,
            'site.home_main_feed_enabled' => false,
            'site.smart_discovery_rail_enabled' => false,
            'site.creator_spotlight_enabled' => false,
            'site.trending_collections_enabled' => false,
        ]);

        $html = $this->get(route('pages.home'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Watch what is moving now', $html);
        $this->assertStringNotContainsString('id="category-tabs"', $html);
        $this->assertStringNotContainsString('aria-label="Smart discovery"', $html);
        $this->assertStringNotContainsString('<videos-area', $html);
    }

    public function test_homepage_uses_admin_configured_intro_copy(): void
    {
        $this->withoutVite();

        config([
            'site.home_intro_enabled' => true,
            'site.home_layout_mode' => 'grid_modern',
            'site.hero_layout_mode' => 'classic_banner',
            'site.home_intro_kicker' => 'Admin Kicker',
            'site.home_intro_title' => 'Admin Homepage Title',
            'site.home_intro_subtitle' => 'Admin homepage subtitle controlled from settings.',
            'site.smart_discovery_rail_enabled' => false,
            'site.creator_spotlight_enabled' => false,
            'site.trending_collections_enabled' => false,
        ]);

        $this->get(route('pages.home'))
            ->assertOk()
            ->assertSee('Admin Kicker')
            ->assertSee('Admin Homepage Title')
            ->assertSee('Admin homepage subtitle controlled from settings.');
    }

    public function test_auth_homepage_keeps_upload_cta_out_of_intro(): void
    {
        $this->withoutVite();

        config([
            'site.home_layout_mode' => 'grid_modern',
            'site.hero_layout_mode' => 'classic_banner',
        ]);

        $html = $this->actingAs(User::factory()->create())
            ->get(route('pages.home'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('avd-header-actions', $html);
        $this->assertStringContainsString('avd-upload-btn', $html);
        $topbarStart = strpos($html, 'class="avd-home-topbar');
        $categoryTabsStart = strpos($html, 'id="category-tabs"');

        $this->assertNotFalse($topbarStart);
        $this->assertNotFalse($categoryTabsStart);
        $this->assertStringNotContainsString(
            'data-bs-target="#video_create"',
            substr($html, $topbarStart, $categoryTabsStart - $topbarStart)
        );

        $sidebarStart = strpos($html, 'id="responsive-sidebar"');
        $sidebarBodyStart = strpos($html, 'class="offcanvas-body', $sidebarStart);

        $this->assertNotFalse($sidebarStart);
        $this->assertNotFalse($sidebarBodyStart);
        $this->assertStringNotContainsString(
            'data-bs-target="#video_create"',
            substr($html, $sidebarStart, $sidebarBodyStart - $sidebarStart)
        );
    }

    public function test_guest_homepage_keeps_auth_actions_out_of_sidebar(): void
    {
        $this->withoutVite();

        $html = $this->get(route('pages.home'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('avd-header-actions', $html);
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        $xpath = new \DOMXPath($document);

        // Auth actions belong to the header. The shared auth prompt modal also
        // contains these URLs, so scope the negative checks to the real sidebar.
        $this->assertGreaterThan(
            0,
            $xpath->query('//ul[contains(concat(" ", normalize-space(@class), " "), " avd-header-actions ")]//a[contains(@href, "/login")]')->length
        );
        $this->assertGreaterThan(
            0,
            $xpath->query('//ul[contains(concat(" ", normalize-space(@class), " "), " avd-header-actions ")]//a[contains(@href, "/register")]')->length
        );
        $this->assertCount(0, $xpath->query('//*[@id="responsive-sidebar"]//a[contains(@href, "/login")]'));
        $this->assertCount(0, $xpath->query('//*[@id="responsive-sidebar"]//a[contains(@href, "/register")]'));
        $this->assertStringNotContainsString('<video-autoplay-switch', $html);
    }

    public function test_homepage_renders_widgets_above_video_feed(): void
    {
        $this->withoutVite();

        $viewer = User::factory()->create();

        foreach (range(1, 10) as $index) {
            Category::query()->create([
                'title' => 'Slot Category ' . $index,
                'slug' => 'slot-category-' . $index,
                'icon' => 'film',
                'in_menu' => true,
                'position' => $index,
            ]);
        }

        config([
            'site.home_layout_mode' => 'grid_modern',
            'site.hero_layout_mode' => 'classic_banner',
            'site.home_main_feed_enabled' => true,
            'site.home_fallback_rails_enabled' => true,
            'site.smart_discovery_rail_enabled' => true,
            'site.creator_spotlight_enabled' => true,
            'site.trending_collections_enabled' => true,
            'site.home_feed_source' => 'most_viewed',
            'site.home_feed_category_id' => 0,
            'site.home_feed_columns' => 5,
            'site.home_feed_load_mode' => 'load_more',
            'site.per_page_videos' => 18,
            'site.home_widget_motion_enabled' => true,
            'site.home_widget_motion_style' => 'zoom',
            'site.home_widget_motion_duration_ms' => 880,
            'site.home_widget_motion_distance_px' => 48,
            'site.home_widget_motion_stagger_ms' => 90,
            'site.home_widget_motion_card_cascade_enabled' => false,
            'site.home_widget_motion_autorun_enabled' => true,
            'site.home_widget_motion_pause_on_hover_enabled' => true,
            'site.home_widget_motion_speed_px_per_second' => 56,
            'site.home_widget_fresh_picks_motion_direction' => 'right',
            'site.home_widget_smart_discovery_motion_direction' => 'left',
            'site.home_widget_creator_spotlight_motion_direction' => 'right',
            'site.home_widget_trending_collections_motion_direction' => 'up',
        ]);

        $html = $this->actingAs($viewer)
            ->get(route('pages.home'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('widget-interval-rows=', $html);
        $this->assertStringNotContainsString('widget-template-ids=', $html);
        $this->assertStringNotContainsString('<template id="home-widget-', $html);
        $this->assertStringContainsString('columns="5"', $html);
        $this->assertStringNotContainsString('max-items="10"', $html);
        $this->assertStringContainsString('load-mode="load_more"', $html);
        $this->assertStringContainsString('source=most_viewed', $html);
        $this->assertStringContainsString('per_page=18', $html);
        $this->assertStringContainsString('data-avd-home-widget-motion="on"', $html);
        $this->assertStringContainsString('"homeWidgetMotion":{"enabled":true,"style":"zoom","durationMs":880,"distancePx":48,"staggerMs":90,"cardCascade":false,"autoRun":true,"pauseOnHover":true,"speedPxPerSecond":56}', $html);

        $feedPosition = strpos($html, '<videos-area');
        $this->assertNotFalse($feedPosition);

        foreach ([
            'home-widget-smart-discovery',
            'home-widget-creator-spotlight',
            'home-widget-trending-collections',
        ] as $templateId) {
            $widgetPosition = strpos($html, 'id="' . $templateId . '"');
            $this->assertNotFalse($widgetPosition);
            $this->assertLessThan($feedPosition, $widgetPosition);
        }

        $smartPosition = strpos($html, 'id="home-widget-smart-discovery"');
        $creatorPosition = strpos($html, 'id="home-widget-creator-spotlight"');
        $trendingPosition = strpos($html, 'id="home-widget-trending-collections"');
        $this->assertLessThan($creatorPosition, $smartPosition);
        $this->assertLessThan($trendingPosition, $creatorPosition);

        $this->assertStringNotContainsString('home-widget-continue-queue', $html);
        $this->assertStringNotContainsString('home-widget-watch-momentum', $html);

        foreach ([
            'fresh_picks' => 'right',
            'smart_discovery' => 'left',
            'creator_spotlight' => 'right',
            'trending_collections' => 'up',
        ] as $widget => $direction) {
            $this->assertMatchesRegularExpression(
                '/data-avd-home-widget="' . preg_quote($widget, '/') . '"[^>]+data-avd-motion-direction="' . preg_quote($direction, '/') . '"/',
                $html
            );
        }
    }

    public function test_homepage_can_render_admin_configured_widget_video_feeds(): void
    {
        $this->withoutVite();

        $selectedCategory = Category::query()->create([
            'title' => 'Widget Video Category',
            'slug' => 'widget-video-category',
            'icon' => 'film',
            'in_menu' => true,
            'position' => 1,
        ]);

        foreach (range(2, 8) as $index) {
            Category::query()->create([
                'title' => 'Widget Fallback Category ' . $index,
                'slug' => 'widget-fallback-category-' . $index,
                'icon' => 'film',
                'in_menu' => true,
                'position' => $index,
            ]);
        }

        config([
            'site.home_main_feed_enabled' => false,
            'site.home_fallback_rails_enabled' => true,
            'site.smart_discovery_rail_enabled' => true,
            'site.creator_spotlight_enabled' => true,
            'site.trending_collections_enabled' => true,
            'site.smart_discovery_rail_videos_enabled' => true,
            'site.smart_discovery_rail_video_source' => 'most_commented',
            'site.smart_discovery_rail_video_category_id' => 0,
            'site.smart_discovery_rail_video_columns' => 3,
            'site.smart_discovery_rail_video_rows' => 1,
            'site.trending_collections_videos_enabled' => true,
            'site.trending_collections_video_source' => 'category',
            'site.trending_collections_video_category_id' => $selectedCategory->id,
            'site.trending_collections_video_columns' => 2,
            'site.trending_collections_video_rows' => 2,
            'site.creator_spotlight_videos_enabled' => true,
            'site.creator_spotlight_video_source' => 'newest',
            'site.creator_spotlight_video_category_id' => 0,
            'site.creator_spotlight_video_columns' => 4,
            'site.creator_spotlight_video_rows' => 1,
        ]);

        $html = $this->get(route('pages.home'))
            ->assertOk()
            ->getContent();

        $this->assertSame(3, substr_count($html, '<videos-area'));
        $this->assertStringContainsString('data-avd-widget-feed="smart-discovery"', $html);
        $this->assertStringContainsString('data-avd-widget-feed="trending-collections"', $html);
        $this->assertStringContainsString('data-avd-widget-feed="creator-spotlight"', $html);
        $this->assertStringContainsString('source=most_commented', $html);
        $this->assertStringContainsString('per_page=3', $html);
        $this->assertStringContainsString('source=category', $html);
        $this->assertStringContainsString('category=' . $selectedCategory->id, $html);
        $this->assertStringContainsString('columns="2"', $html);
        $this->assertStringContainsString('max-items="4"', $html);

        $smartWidget = strpos($html, 'id="home-widget-smart-discovery"');
        $smartFeed = strpos($html, 'data-avd-widget-feed="smart-discovery"');
        $trendingWidget = strpos($html, 'id="home-widget-trending-collections"');
        $trendingFeed = strpos($html, 'data-avd-widget-feed="trending-collections"');
        $creatorWidget = strpos($html, 'id="home-widget-creator-spotlight"');
        $creatorFeed = strpos($html, 'data-avd-widget-feed="creator-spotlight"');

        $this->assertNotFalse($smartWidget);
        $this->assertNotFalse($smartFeed);
        $this->assertNotFalse($trendingWidget);
        $this->assertNotFalse($trendingFeed);
        $this->assertNotFalse($creatorWidget);
        $this->assertNotFalse($creatorFeed);
        $this->assertLessThan($smartFeed, $smartWidget);
        $this->assertLessThan($creatorWidget, $smartFeed);
        $this->assertLessThan($creatorFeed, $creatorWidget);
        $this->assertLessThan($trendingWidget, $creatorFeed);
        $this->assertLessThan($trendingFeed, $trendingWidget);
    }

    public function test_layout_obeys_low_level_effect_switches(): void
    {
        $this->withoutVite();

        config([
            'site.effects_enabled' => false,
            'site.back_to_top_enabled' => true,
            'site.smart_discovery_rail_enabled' => false,
            'site.creator_spotlight_enabled' => false,
            'site.trending_collections_enabled' => false,
        ]);

        $html = $this->get(route('pages.home'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-avd-effects="off"', $html);
        $this->assertStringContainsString('data-avd-css-animations="off"', $html);
        $this->assertStringContainsString('data-avd-css-transitions="off"', $html);
        $this->assertStringContainsString('data-avd-hover-effects="off"', $html);
        $this->assertStringContainsString('data-avd-gradient-effects="off"', $html);
        $this->assertStringContainsString('data-avd-blur-effects="off"', $html);
        $this->assertStringContainsString('data-avd-shadow-effects="off"', $html);
        $this->assertStringContainsString('data-avd-sticky-header="off"', $html);
        $this->assertStringContainsString('data-avd-shimmer="off"', $html);
        $this->assertStringContainsString('data-avd-title-accent="off"', $html);
        $this->assertStringContainsString('data-avd-title-accent-animation="off"', $html);
        $this->assertStringContainsString('data-avd-smooth-scroll="off"', $html);
        $this->assertStringContainsString('data-avd-home-widget-motion="off"', $html);
        $this->assertStringContainsString('data-avd-tilt-3d="off"', $html);
        $this->assertStringContainsString('data-avd-card-spotlight="off"', $html);
        $this->assertStringContainsString('data-avd-border-beam="off"', $html);
        $this->assertStringContainsString('data-avd-glassmorphism="off"', $html);
        $this->assertStringContainsString('data-avd-cursor-trail="off"', $html);
        $this->assertStringContainsString('"animeEffects":false', $html);
        $this->assertStringContainsString('"homeWidgetMotion":{"enabled":false', $html);
        $this->assertStringContainsString('"themeMemory":false', $html);
        $this->assertStringNotContainsString('"playerMemory"', $html);
        $this->assertStringContainsString("behavior:'auto'", $html);
        $this->assertStringNotContainsString('id="copy-toast"', $html);
        $this->assertStringNotContainsString('sticky-top', $html);
    }

    public function test_headline_accent_settings_control_title_color_effects(): void
    {
        $this->withoutVite();

        // A custom accent colour: the theme collection v2 derives it with the colour engine and prints it after the
        // theme's token file (#avd-theme-owner), --avd-cyan(-rgb) included.
        Setting::set('accent_color', '#12abcd', 'appearance');
        $this->refreshAppearance();
        config([
            'site.accent_color' => '#12abcd',
            'site.hero_layout_mode' => 'classic_banner',
            'site.headline_accent_enabled' => true,
            'site.headline_accent_animation_enabled' => false,
            'site.smart_discovery_rail_enabled' => false,
            'site.creator_spotlight_enabled' => false,
            'site.trending_collections_enabled' => false,
        ]);

        $html = $this->get(route('pages.home'))
            ->assertOk()
            ->assertSee('avd-home-title', false)
            ->getContent();

        $this->assertStringContainsString('data-avd-title-accent="on"', $html);
        $this->assertStringContainsString('data-avd-title-accent-animation="off"', $html);
        $this->assertStringContainsString('<style id="avd-theme-owner">', $html);
        $this->assertMatchesRegularExpression('/--avd-cyan:#12abcd[;}]/', $html);
        $this->assertMatchesRegularExpression('/--avd-cyan-rgb:18,171,205[;}]/', $html);

        $video = Video::factory()
            ->for(User::factory(), 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Headline Accent Watch Title',
                'slug' => 'headline-accent-watch-title',
            ]);

        $watchHtml = $this->get($video->route)
            ->assertOk()
            ->assertSee('avd-watch-title', false)
            ->getContent();

        $this->assertStringContainsString('data-avd-title-accent="on"', $watchHtml);
        $this->assertMatchesRegularExpression('/--avd-cyan:#12abcd[;}]/', $watchHtml);

        config(['site.headline_accent_enabled' => false]);

        $html = $this->get(route('pages.home'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-avd-title-accent="off"', $html);
        // The rule that attribute switches lives in the cached theme CSS (perf plan step 24), not inline.
        $this->assertStringContainsString('html[data-avd-title-accent="off"] .avd-home-title', $this->themeCss());
    }

    public function test_layout_uses_admin_configured_typography_controls(): void
    {
        $this->withoutVite();

        config([
            'site.typography_controls_enabled' => true,
            'site.font_family' => 'display',
            'site.base_font_size' => '18',
            'site.ui_font_scale' => '106',
            'site.heading_font_scale' => '114',
            'site.card_title_font_scale' => '108',
            'site.text_contrast' => 'strong',
            'site.smart_discovery_rail_enabled' => false,
            'site.creator_spotlight_enabled' => false,
            'site.trending_collections_enabled' => false,
        ]);

        $html = $this->get(route('pages.home'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-avd-typography="on"', $html);
        $this->assertStringContainsString('data-avd-font="display"', $html);
        $this->assertStringContainsString('data-avd-text-contrast="strong"', $html);
        $this->assertStringContainsString('--avd-font-size-base: 18px;', $html);
        $this->assertStringContainsString('--avd-font-scale-ui: 1.06;', $html);
        $this->assertStringContainsString('--avd-font-scale-heading: 1.14;', $html);
        $this->assertStringContainsString('--avd-font-scale-card-title: 1.08;', $html);

        // Theme collection v2: font_family no longer pretends to work. The typography pairing drives the fonts: the
        // base font is the theme's body font and the page requests only the pairing's families (no Montserrat or
        // Poppins for the 'display' preset).
        $this->assertStringContainsString('--avd-font-family-base: var(--avd-font-body);', $html);
        $this->assertStringContainsString('--avd-font-heading: var(--avd-font-display);', $html);
        $this->assertMatchesRegularExpression('#https://fonts\.googleapis\.com/css2\?family=Outfit:wght@400\.\.800&amp;family=Plus\+Jakarta\+Sans:wght@400\.\.800&amp;display=swap#', $html);
        $this->assertStringNotContainsString('family=Montserrat', $html);
        $this->assertStringNotContainsString('family=Poppins', $html);

        // The text contrast attribute is styled by the cached theme CSS (perf plan step 24), keyed on <html> only.
        $this->assertStringContainsString('html[data-avd-text-contrast="strong"]', $this->themeCss());
        $this->assertStringNotContainsString('html[data-theme="light"][data-avd-glassmorphism="on"]', $html.$this->themeCss());
    }

    public function test_daily_atmosphere_is_optional_and_has_one_render_loop(): void
    {
        $this->withoutVite();

        config([
            'site.smart_discovery_rail_enabled' => false,
            'site.creator_spotlight_enabled' => false,
            'site.trending_collections_enabled' => false,
        ]);
        Setting::set('daily_design_auto_enabled', '1', 'appearance');
        Setting::set('daily_design_show_badge', '1', 'appearance');
        Setting::set('daily_design_atmosphere_particles', '1', 'appearance');
        $this->refreshAppearance();

        $html = $this->get(route('pages.home'))->assertOk()->getContent();

        // One canvas; its single render loop lives in the layout bundle (perf plan step 25), not in an inline
        // script: resources/js/layout/decor.ts draws it, started from one place and only on the rich tier.
        $this->assertSame(1, substr_count($html, 'id="avdSeasonalAtmosphere"'));
        $this->assertStringNotContainsString('var isRunning = false;', $html);
        $layoutScripts = implode("\n", array_map('file_get_contents', glob(resource_path('js/layout/*.ts'))));
        $this->assertSame(1, substr_count($layoutScripts, 'startAtmosphere('));
        $this->assertSame(1, substr_count((string) file_get_contents(resource_path('js/layout/decor.ts')), 'const draw = '));

        config(['site.effects_enabled' => false]);
        $withoutGlobalEffects = $this->get(route('pages.home'))->assertOk()->getContent();

        $this->assertSame(0, substr_count($withoutGlobalEffects, 'id="avdSeasonalAtmosphere"'));

        config(['site.effects_enabled' => true]);
        Setting::set('daily_design_atmosphere_particles', '0', 'appearance');
        $this->refreshAppearance();
        config(['site.effects_enabled' => true]);
        $withoutParticles = $this->get(route('pages.home'))->assertOk()->getContent();

        $this->assertSame(0, substr_count($withoutParticles, 'id="avdSeasonalAtmosphere"'));
    }

    public function test_secondary_pages_use_admin_configured_design_controls(): void
    {
        $this->withoutVite();

        config([
            'site.secondary_page_design_enabled' => true,
            'site.secondary_page_hero_enabled' => true,
            'site.secondary_page_surfaces_enabled' => true,
            'site.secondary_page_form_polish_enabled' => true,
            'site.secondary_page_table_polish_enabled' => true,
            'site.secondary_page_density' => 'relaxed',
            'site.secondary_page_max_width' => 'standard',
            'site.contact_illustration_enabled' => false,
            'site.premium_comparison_enabled' => false,
        ]);

        $contactHtml = $this->get(route('contact.show'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-avd-secondary-pages="on"', $contactHtml);
        $this->assertStringContainsString('data-avd-secondary-density="relaxed"', $contactHtml);
        $this->assertStringContainsString('data-avd-secondary-width="standard"', $contactHtml);
        $this->assertStringContainsString('avd-contact-page', $contactHtml);
        $this->assertStringNotContainsString('images/pages/contact.png', $contactHtml);

        $termsHtml = $this->get(route('pages.terms'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('avd-terms-page', $termsHtml);
        $this->assertStringContainsString('avd-terms-body', $termsHtml);

        $premiumHtml = $this->get(route('pages.premium'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('avd-premium-page', $premiumHtml);
        $this->assertStringNotContainsString('Plans comparison', $premiumHtml);

        $user = User::factory()->create();

        $profileHtml = $this->actingAs($user)
            ->get(route('user.edit'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('avd-profile-settings', $profileHtml);

        $videosHtml = $this->actingAs($user)
            ->get(route('user.videos.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('avd-profile-videos-page', $videosHtml);

        $subscriptionsHtml = $this->actingAs($user)
            ->get(route('subscription.manage'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('avd-subscriptions-page', $subscriptionsHtml);
    }

    public function test_watch_page_obeys_admin_appearance_toggles(): void
    {
        $this->withoutVite();

        $creator = User::factory()->create();
        $video = Video::factory()
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Appearance Controlled Watch Video',
                'slug' => 'appearance-controlled-watch-video',
                'description' => 'This description should be hidden by appearance settings.',
                'allow_comments' => true,
            ]);

        config([
            'site.watch_theater_button_enabled' => false,
            'site.watch_keyboard_shortcuts_enabled' => false,
            'site.watch_share_enabled' => false,
            'site.watch_description_enabled' => false,
            'site.watch_channel_panel_enabled' => false,
            'site.watch_metadata_chips_enabled' => false,
            'site.watch_comments_preview_enabled' => false,
            'site.watch_related_videos_enabled' => false,
            'site.watch_playlist_panel_enabled' => false,
            'site.watch_next_video_enabled' => false,
            'site.watch_sidebar_ads_enabled' => false,
            'site.watch_player_frame_style' => 'minimal',
            'site.watch_player_ambient_glow_enabled' => false,
            'site.watch_action_panel_enabled' => false,
            'site.watch_sidebar_density' => 'compact',
        ]);

        $html = $this->get($video->route)
            ->assertOk()
            ->assertSee('Appearance Controlled Watch Video')
            ->getContent();

        $this->assertStringNotContainsString('<button type="button" class="avd-player-control" data-theater-toggle', $html);
        $this->assertStringContainsString('data-avd-watch-player-style="minimal"', $html);
        $this->assertStringContainsString('data-avd-watch-ambient="off"', $html);
        $this->assertStringContainsString('data-avd-watch-action-panel="off"', $html);
        $this->assertStringContainsString('data-avd-watch-sidebar-density="compact"', $html);
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*avd-watch-action-panel/', $html);
        $this->assertStringNotContainsString('id="shortcuts-overlay"', $html);
        $this->assertStringNotContainsString('data-bs-target="#share"', $html);
        $this->assertRenderedClassAbsent($html, 'avd-description-panel');
        $this->assertStringNotContainsString('id="comments_area"', $html);
        $this->assertRenderedClassAbsent($html, 'avd-related-list');
    }

    public function test_category_and_watch_feature_toggles_are_honored_by_runtime_views(): void
    {
        $this->withoutVite();

        $category = Category::query()->create([
            'title' => 'Toggle Category',
            'slug' => 'toggle-category',
            'icon' => 'video',
        ]);
        $creator = User::factory()->create();
        $video = Video::factory()
            ->for($creator, 'user')
            ->for($category)
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Toggle Watch Video',
                'slug' => 'toggle-watch-video',
            ]);

        config([
            'site.header_category_chips_enabled' => false,
            'site.category_video_count_pill_enabled' => false,
            'site.watch_floating_mini_player_enabled' => false,
            'site.watch_autoplay_countdown_seconds' => 0,
            'site.watch_next_video_enabled' => false,
            'site.watch_related_videos_enabled' => false,
            'site.watch_playlist_panel_enabled' => false,
            'site.watch_sidebar_ads_enabled' => false,
        ]);

        $homeHtml = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="category-tabs"', $homeHtml);

        $categoryHtml = $this->get($category->route)->assertOk()->getContent();
        $this->assertStringNotContainsString('class="avd-status-chip text-white"', $categoryHtml);

        $watchHtml = $this->get($video->route)->assertOk()->getContent();
        $this->assertStringNotContainsString('id="avdPipToggle"', $watchHtml);
        $this->assertStringNotContainsString('avd-up-next-badge-autoplay', $watchHtml);
        $this->assertStringContainsString('var avdPipAllowed = false;', $watchHtml);
    }

    public function test_watch_layout_uses_saved_admin_value_until_a_theme_preview_is_requested(): void
    {
        $this->withoutVite();

        $creator = User::factory()->create();
        $video = Video::factory()
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Watch Layout Precedence Video',
                'slug' => 'watch-layout-precedence-video',
            ]);

        config([
            'site.theme_preset' => 'cyberpunk_neon',
            'site.watch_layout_mode' => 'theater_cinema',
            'site.watch_related_videos_enabled' => false,
            'site.watch_playlist_panel_enabled' => false,
            'site.watch_next_video_enabled' => false,
            'site.watch_sidebar_ads_enabled' => false,
        ]);

        $savedHtml = $this->get($video->route)->assertOk()->getContent();
        $this->assertStringContainsString('data-avd-watch-layout="theater_cinema"', $savedHtml);

        // The preview shows the previewed theme's own watch layout (v1 catalogue: studio_dashboard; theme
        // collection v2: the alias theme's recommended layout), whatever the saved value.
        $previewLayout = (string) \App\Support\ThemeCatalog::get('cyberpunk_neon')['watch_layout_mode'];
        $this->assertNotSame('theater_cinema', $previewLayout);
        $previewHtml = $this->get($video->route . '?theme=cyberpunk_neon')->assertOk()->getContent();
        $this->assertStringContainsString('data-avd-watch-layout="' . $previewLayout . '"', $previewHtml);
    }

    public function test_watch_page_uses_admin_configured_sidebar_and_description_experience(): void
    {
        $this->withoutVite();

        $creator = User::factory()->create();
        $video = Video::factory()
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Watch Page Experience Video',
                'slug' => 'watch-page-experience-video',
                'description' => str_repeat('Long watch page description. ', 14),
            ]);
        Video::factory()
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Rich Up Next Video',
                'slug' => 'rich-up-next-video',
                'views' => 12,
            ]);

        config([
            'site.watch_next_video_enabled' => true,
            'site.watch_up_next_card_enabled' => true,
            'site.watch_description_enabled' => true,
            'site.watch_description_default_open' => true,
            'site.watch_sidebar_density' => 'relaxed',
            'site.watch_playlist_panel_enabled' => false,
            'site.watch_related_videos_enabled' => true,
            'site.watch_sidebar_ads_enabled' => false,
        ]);

        $html = $this->get($video->route)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-avd-watch-sidebar-density="relaxed"', $html);
        $this->assertRenderedClassPresent($html, 'avd-up-next-card');
        $this->assertStringContainsString('Up next', $html);
        $this->assertStringContainsString('Recommended', $html);
        $this->assertStringContainsString('Rich Up Next Video', $html);
        $this->assertStringContainsString('x-data="{ open: true }"', $html);

        config(['site.watch_up_next_card_enabled' => false]);

        $html = $this->get($video->route)
            ->assertOk()
            ->getContent();

        $this->assertRenderedClassAbsent($html, 'avd-up-next-card');
        $this->assertStringContainsString('Next Video', $html);
    }

    public function test_watch_page_keeps_autoplay_control_removed(): void
    {
        $this->withoutVite();

        $creator = User::factory()->create();
        $video = Video::factory()
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Watch Video Without Autoplay Control',
                'slug' => 'watch-video-without-autoplay-control',
            ]);
        Video::factory()
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Manual Next Video Only',
                'slug' => 'manual-next-video-only',
            ]);

        $html = $this->get($video->route)
            ->assertOk()
            ->assertSee('Next Video')
            ->getContent();

        $this->assertStringNotContainsString('<autoplay-switch', $html);
        $this->assertStringNotContainsString('next_video=', $html);
    }

    public function test_watch_page_can_render_comments_without_lazy_javascript(): void
    {
        $this->withoutVite();

        $creator = User::factory()->create();
        $video = Video::factory()
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Immediate Comments Watch Video',
                'slug' => 'immediate-comments-watch-video',
                'allow_comments' => true,
            ]);

        config([
            'site.effects_enabled' => true,
            'site.js_lazy_comments_enabled' => false,
            'site.watch_comments_preview_enabled' => true,
            'site.comments_enabled' => true,
            'site.watch_related_videos_enabled' => false,
            'site.watch_playlist_panel_enabled' => false,
            'site.watch_next_video_enabled' => false,
            'site.watch_sidebar_ads_enabled' => false,
        ]);

        $html = $this->get($video->route)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("<comments-area video='{$video->uuid}'", $html);
        $this->assertStringNotContainsString(
            "const commentsTarget = document.getElementById('comments_area')",
            $html
        );
    }

    private function assertRenderedClassPresent(string $html, string $class): void
    {
        $this->assertTrue($this->htmlHasClassAttribute($html, $class), "Expected a rendered element with class {$class}.");
    }

    private function assertRenderedClassAbsent(string $html, string $class): void
    {
        $this->assertFalse($this->htmlHasClassAttribute($html, $class), "Did not expect a rendered element with class {$class}.");
    }

    private function htmlHasClassAttribute(string $html, string $class): bool
    {
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        $xpath = new \DOMXPath($document);
        $query = sprintf(
            '//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]',
            $class
        );

        return $xpath->query($query)->length > 0;
    }

    /**
     * What the next request's provider boot does: re-resolve the stored appearance. The theme catalogue v2 binds it as
     * AppearanceSettings::current() at boot, and a test's next request does not boot again.
     */
    private function refreshAppearance(): void
    {
        if (method_exists(AppearanceSettings::class, 'applyToConfig')) {
            AppearanceSettings::applyToConfig(Setting::getGroup('appearance'));
        }
    }

    /** The layout's static theme CSS (resources/css/layout-theme-a.css + -b.css), served as cached build files. */
    private function themeCss(): string
    {
        return (string) file_get_contents(resource_path('css/layout-theme-a.css')).(string) file_get_contents(resource_path('css/layout-theme-b.css'));
    }
}
