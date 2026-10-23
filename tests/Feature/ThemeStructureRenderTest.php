<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Support\ThemeStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Theme structure (WP5): every header, side menu, hero and watch layout the 12 complete looks combine renders,
 * shows real data only (no costume text) and keeps colours out of the markup.
 */
class ThemeStructureRenderTest extends TestCase
{
    use RefreshDatabase;

    /** Strings of the old "costume" heroes (fake terminals, file names, progress) that must never render. */
    private const COSTUME = ['root@', '.exe', '.json', '--info', '% watched', 'formerly'];

    private array $runtimeFlagEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Public rendering needs the normal installed-site routes (see AppearanceExperienceRenderingTest).
        foreach (['APP_INSTALLED' => 'true', 'INSTALLER_ALLOW_REINSTALL' => 'false'] as $key => $value) {
            $this->runtimeFlagEnvironment[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }

        Cache::flush();
        $this->withoutVite();
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

            putenv($processValue === false ? $key : $key.'='.$processValue);
        }

        $this->runtimeFlagEnvironment = [];

        parent::tearDown();
    }

    /**
     * The 12 complete looks: theme, home layout, hero mode, watch layout, card layout, header style, side menu,
     * and the hero kind the page must show.
     */
    public static function looks(): array
    {
        return [
            'youtube_spotlight_pro' => ['tokyo_neon', 'youtube_spotlight', 'live_ticker_stream', 'classic_two_column', 'card_standard', 'glass_blur', 'full', 'ticker'],
            'netflix_cinema' => ['pure_oled_black', 'cinema_carousel', 'snap_slider_carousel', 'theater_cinema', 'card_hover_expand', 'transparent_overlay', 'hidden', 'billboard'],
            'velvet_lounge' => ['rose_gold_velvet', 'cinema_carousel', 'snap_slider_carousel', 'floating_focus', 'card_hover_expand', 'glass_blur', 'full', 'billboard'],
            'tiktok_shorts_hub' => ['fairy_floss', 'tiktok_shorts', 'snap_slider_carousel', 'floating_focus', 'card_bento_modern', 'glass_blur', 'full', 'shorts'],
            'apple_bento_studio' => ['swiss_monolith', 'bento_mosaic', 'bento_spotlight', 'classic_two_column', 'card_standard', 'minimal_slim', 'hidden', 'bento'],
            'cyberpunk_hud' => ['shibuya_rain', 'cyberpunk_hud', 'snap_slider_carousel', 'studio_dashboard', 'card_standard', 'glass_blur', 'rail', 'hud'],
            'twitch_esports_live' => ['cyber_gladiator', 'grid_modern', 'live_ticker_stream', 'studio_dashboard', 'card_hover_expand', 'full_megabar', 'rail', 'ticker'],
            'cosmic_astronomy_theater' => ['interstellar', 'cinema_carousel', 'snap_slider_carousel', 'theater_cinema', 'card_glass_overlay', 'glass_blur', 'full', 'billboard'],
            'luxury_imperial_salon' => ['obsidian_gold', 'bento_mosaic', 'bento_spotlight', 'classic_two_column', 'card_glass_overlay', 'solid_dark', 'hidden', 'bento'],
            'editorial_magazine' => ['broadsheet', 'grid_modern', 'editorial_lead', 'classic_two_column', 'card_standard', 'full_megabar', 'hidden', 'lead'],
            'glass_lounge' => ['lavender_cloud', 'youtube_spotlight', 'snap_slider_carousel', 'classic_two_column', 'card_standard', 'glass_island', 'hidden', 'split'],
            'daylight_creator' => ['daylight_paper', 'grid_modern', 'classic_banner', 'classic_two_column', 'card_standard', 'minimal_slim', 'full', 'banner'],
        ];
    }

    #[DataProvider('looks')]
    public function test_each_look_renders_its_home_structure_with_real_data_only(
        string $theme, string $home, string $hero, string $watch, string $card, string $header, string $sidebar, string $heroKind
    ): void {
        $videos = $this->seedVideos();
        $this->useLook($theme, $home, $hero, $watch, $card, $header, $sidebar);

        $html = $this->get(route('pages.home'))->assertOk()->getContent();
        $main = $this->mainRegion($html);

        $this->assertStringContainsString('avd-hero avd-hero--'.$heroKind, $main);
        $this->assertStringContainsString('<div class="avd-hero-zone" data-avd-hero>', $main);
        // The page with a hero zone says so on its body (the transparent bar's CSS keys on it, not on a :has() test).
        $this->assertContains('has-hero-zone', explode(' ', (string) preg_replace('/^<body class="([^"]*)".*$/s', '$1', $this->bodyTag($html))));
        $this->assertStringContainsString('avd-sidebar--'.$sidebar, $html);
        // A transparent bar only sits over a full-bleed billboard; the billboard declares it.
        $expectedHeader = $header === 'transparent_overlay' && ! str_contains($main, 'avd-hero--billboard') ? 'solid_dark' : $header;
        $this->assertMatchesRegularExpression('/class="navbar avd-header[^"]*avd-header--'.$expectedHeader.'[\s"]/', $html);
        if ($sidebar === 'hidden') {
            // A hidden side menu moves its links into the bar (.avd-hnav), or into the second row of a two-row bar (.avd-hrow2).
            $this->assertMatchesRegularExpression('/<nav class="avd-(hnav|hrow2)[ "]/', $html);
            $this->assertStringNotContainsString('has-sidebar', $this->bodyTag($html));
        }

        // Real titles of the seeded videos, never invented text.
        $this->assertStringContainsString(e($videos[0]->title), $main);
        foreach (self::COSTUME as $costume) {
            $this->assertStringNotContainsString($costume, $main, "'$costume' must not render on the home page of $theme");
        }
        $this->assertDoesNotMatchRegularExpression('/style="[^"]*(#[0-9a-fA-F]{3,8}\b|rgba?\()/', $main, 'Hero and feed markup carries no inline colours.');
        $this->assertStringContainsString('title-script-latin', $main);
    }

    #[DataProvider('looks')]
    public function test_each_look_renders_its_watch_layout_container(
        string $theme, string $home, string $hero, string $watch, string $card, string $header, string $sidebar
    ): void {
        $videos = $this->seedVideos();
        $this->useLook($theme, $home, $hero, $watch, $card, $header, $sidebar);

        $html = $this->get($videos[0]->route)->assertOk()->getContent();

        $this->assertStringContainsString('data-avd-watch-layout="'.$watch.'"', $html);
        $this->assertMatchesRegularExpression('/<h1 class="avd-watch-title title-script-latin"/', $html);
        if (in_array($watch, ['theater_cinema', 'floating_focus'], true)) {
            // One height-capped container for the player column and the side column, and Up next under the title.
            $this->assertMatchesRegularExpression('/class="avd-watch-primary[^"]*avd-watch-contained/', $html);
            $this->assertStringContainsString('class="avd-upnext-strip mt-3"', $html);
            $this->assertGreaterThanOrEqual(2, substr_count($html, 'class="avd-upnext-strip-item"'));
        } else {
            $this->assertStringNotContainsString('class="avd-upnext-strip mt-3"', $html);
        }
        foreach (self::COSTUME as $costume) {
            $this->assertStringNotContainsString($costume, $this->mainRegion($html));
        }
    }

    public function test_transparent_header_falls_back_to_the_solid_bar_without_a_hero(): void
    {
        $this->seedVideos();
        $this->useLook('pure_oled_black', 'grid_modern', 'off', 'theater_cinema', 'card_standard', 'transparent_overlay', 'hidden');

        $html = $this->get(route('pages.home'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="navbar avd-header[^"]*avd-header--solid_dark[\s"]/', $html);
        $this->assertStringNotContainsString('avd-header--transparent_overlay', $html);
        $this->assertStringNotContainsString('avd-hero ', $this->mainRegion($html));
        $this->assertStringNotContainsString('has-hero-zone', $this->bodyTag($html));
    }

    public function test_the_old_sidebar_rail_header_becomes_a_glass_bar_with_a_rail(): void
    {
        $this->seedVideos();
        // Upgraded sites store the default 'full' style next to the retired header value (the v2 seed writes it).
        Setting::set('sidebar_style', 'full', 'appearance');

        foreach (['full', null] as $configured) {
            $this->useLook('tokyo_neon', 'youtube_spotlight', 'live_ticker_stream', 'classic_two_column', 'card_standard', 'sidebar_rail', $configured);

            $html = $this->get(route('pages.home'))->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/class="navbar avd-header[^"]*avd-header--glass_blur[\s"]/', $html);
            $this->assertStringContainsString('avd-sidebar--rail', $html);
            $this->assertStringContainsString('data-avd-sidebar-style="rail"', $html);
        }

        // A side menu style the owner picked wins over the retired header value.
        $this->useLook('tokyo_neon', 'youtube_spotlight', 'live_ticker_stream', 'classic_two_column', 'card_standard', 'sidebar_rail', 'floating');
        $this->assertStringContainsString('avd-sidebar--floating', $this->get(route('pages.home'))->assertOk()->getContent());
    }

    public function test_an_unpinned_side_menu_is_a_drawer_whatever_its_style(): void
    {
        $this->seedVideos();
        $this->useLook('tokyo_neon', 'youtube_spotlight', 'live_ticker_stream', 'classic_two_column', 'card_standard', 'glass_blur', 'full');

        $pinned = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertStringContainsString('has-sidebar', $this->bodyTag($pinned));
        $this->assertMatchesRegularExpression('/id="responsive-sidebar"/', $pinned);
        $this->assertMatchesRegularExpression('/class="offcanvas[^"]*avd-sidebar--full show_sidebar"/', $pinned);

        // "Side menu open on computers" off: the style stays, nothing pins (no gutter, the menu button shows).
        foreach (['full', 'rail', 'floating'] as $style) {
            $this->useLook('tokyo_neon', 'youtube_spotlight', 'live_ticker_stream', 'classic_two_column', 'card_standard', 'glass_blur', $style);
            config(['site.front_sidebar_pinned_enabled' => false]);

            $html = $this->get(route('pages.home'))->assertOk()->getContent();

            $this->assertStringNotContainsString('has-sidebar', $this->bodyTag($html), "unpinned $style menu");
            $this->assertMatchesRegularExpression('/class="offcanvas[^"]*avd-sidebar--'.$style.'"/', $html);
            $this->assertDoesNotMatchRegularExpression('/class="navbar avd-header[^"]*navbar-expand-md/', $html);
            $this->assertStringContainsString('id="responsive-sidebar-toggle"', $html);
        }

        // The admin preview parameter unpins it the same way.
        $this->useLook('tokyo_neon', 'youtube_spotlight', 'live_ticker_stream', 'classic_two_column', 'card_standard', 'glass_blur', 'full');
        $preview = $this->get(route('pages.home', ['front_sidebar_pinned_enabled' => 0]))->assertOk()->getContent();
        $this->assertStringNotContainsString('has-sidebar', $this->bodyTag($preview));
    }

    public function test_menu_links_mark_only_the_current_page(): void
    {
        $this->seedVideos();
        $this->useLook('lavender_cloud', 'youtube_spotlight', 'snap_slider_carousel', 'classic_two_column', 'card_standard', 'glass_island', 'hidden');
        config(['site.sidebar_primary_links' => "Home|house|/\nTrend|fire|/trend\nBlog|pen|https://blog.example.com|1"]);

        $home = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertSame(['Home'], $this->currentLinks($home, 'avd-hnav'));
        $this->assertSame(['Home'], $this->currentLinks($home, 'avd-sidebar'));

        $trend = $this->get(route('pages.trend'))->assertOk()->getContent();
        $this->assertSame(['Trend'], $this->currentLinks($trend, 'avd-hnav'));
        $this->assertSame(['Trend'], $this->currentLinks($trend, 'avd-sidebar'));
    }

    public function test_masthead_and_crest_decorations_render_their_pieces(): void
    {
        $this->seedVideos();

        $this->useLook('broadsheet', 'grid_modern', 'editorial_lead', 'classic_two_column', 'card_standard', 'full_megabar', 'hidden');
        $masthead = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertStringContainsString('avd-dateline', $masthead);
        $this->assertStringContainsString('avd-masthead-title', $masthead);
        $this->assertStringContainsString('avd-hrow2', $masthead);
        $this->assertStringContainsString("Today's edition", html_entity_decode($masthead, ENT_QUOTES));

        $this->useLook('obsidian_gold', 'bento_mosaic', 'bento_spotlight', 'classic_two_column', 'card_glass_overlay', 'solid_dark', 'hidden');
        $crest = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertStringContainsString('avd-brand--crest', $crest);
        $this->assertStringContainsString('class="avd-hnav', $crest);
    }

    public function test_hud_hero_lists_real_facts_of_the_featured_video(): void
    {
        $videos = $this->seedVideos();
        $this->useLook('shibuya_rain', 'cyberpunk_hud', 'snap_slider_carousel', 'studio_dashboard', 'card_standard', 'glass_blur', 'rail');

        $main = $this->mainRegion($this->get(route('pages.home'))->assertOk()->getContent());

        foreach (['Channel', 'Views', 'Length', 'Category', 'Added'] as $label) {
            $this->assertMatchesRegularExpression('/<div class="avd-drow"><span>'.$label.'<\/span><b>[^<]+<\/b><\/div>/', $main);
        }
        $this->assertStringContainsString($videos[0]->user->username, $main);
        foreach (['ACCESS TERMINAL', 'ENGAGE FEED', 'TELEMETRY', 'SATELLITE'] as $costume) {
            $this->assertStringNotContainsString($costume, $main);
        }
    }

    public function test_the_deep_space_billboard_is_interstellars_cinema_rows_hero_and_its_spotlight_stays_elsewhere(): void
    {
        $videos = $this->seedVideos();
        // Deep Space Theater (cinema rows + slider): the full-width starfield billboard under the glass bar (no
        // transparent-overlay declaration), as in the look's mockup (billboard + shelves; no split card, no orbit rings).
        $this->useLook('interstellar', 'cinema_carousel', 'snap_slider_carousel', 'theater_cinema', 'card_glass_overlay', 'glass_blur', 'full');
        $html = $this->get(route('pages.home'))->assertOk()->getContent();
        $main = $this->mainRegion($html);

        $this->assertStringContainsString('avd-hero avd-hero--billboard', $main);
        $this->assertStringNotContainsString('avd-hero--split', $main);
        $this->assertStringNotContainsString('avd-orbit', $main);
        $this->assertStringContainsString(e($videos[0]->title), $main);
        $this->assertMatchesRegularExpression('/class="navbar avd-header[^"]*avd-header--glass_blur[\s"]/', $html);
        $this->assertSame(['kind' => 'billboard', 'deco' => 'split_orbit'], ThemeStructure::hero('snap_slider_carousel', 'cinema_carousel'));

        // Interstellar's own home (spotlight) and its other slider homes keep the split spotlight.
        foreach (['youtube_spotlight', 'bento_mosaic', 'grid_modern'] as $home) {
            $this->assertSame(['kind' => 'split', 'deco' => 'split_orbit'], ThemeStructure::hero('snap_slider_carousel', $home), $home);
        }

        $this->useLook('interstellar', 'youtube_spotlight', 'snap_slider_carousel', 'classic_two_column', 'card_glass_overlay', 'glass_blur', 'full');
        $this->assertStringContainsString('avd-hero avd-hero--split', $this->mainRegion($this->get(route('pages.home'))->assertOk()->getContent()));
    }

    public function test_titles_carry_their_script_class(): void
    {
        $this->seedVideos('جولة ليلية في شوارع المدينة');
        $this->useLook('tokyo_neon', 'youtube_spotlight', 'live_ticker_stream', 'classic_two_column', 'card_standard', 'glass_blur', 'full');

        $main = $this->mainRegion($this->get(route('pages.home'))->assertOk()->getContent());

        $this->assertStringContainsString('title-script-arabic', $main);
    }

    public function test_public_partials_keep_colours_out_of_the_markup(): void
    {
        $files = array_merge(
            glob(resource_path('views/pages/partials/*.blade.php')),
            glob(resource_path('views/pages/partials/hero/*.blade.php')),
            glob(resource_path('views/layouts/menus/headers/*.blade.php')),
            glob(resource_path('views/components/theme/*.blade.php')),
            [
                resource_path('views/pages/home.blade.php'),
                resource_path('views/pages/categories.blade.php'),
                resource_path('views/layouts/menus/header.blade.php'),
                resource_path('views/layouts/menus/footer.blade.php'),
                resource_path('views/layouts/menus/sidebars/front.blade.php'),
                resource_path('views/videos/card.blade.php'),
                resource_path('views/videos/card-secondary.blade.php'),
                resource_path('views/playlists/card.blade.php'),
            ]
        );

        $this->assertGreaterThan(15, count($files));
        foreach ($files as $file) {
            $source = file_get_contents($file);
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            preg_match_all('/style="([^"]*)"/', $source, $attributes);
            foreach ($attributes[1] as $style) {
                $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $style, "$relative: inline style colour '$style'");
            }

            preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $source, $blocks);
            foreach ($blocks[1] as $css) {
                $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $css, "$relative: hex colour in a <style> block");
            }
        }
    }

    /** @return array<int, Video> the public videos, most viewed first */
    private function seedVideos(string $leadTitle = 'Night walk through the old town in the rain'): array
    {
        $category = Category::query()->create([
            'title' => 'Travel',
            'slug' => 'travel',
            'icon' => 'plane',
            'in_menu' => true,
            'position' => 1,
        ]);
        $creator = User::factory()->create(['username' => 'nightdrive']);
        $titles = [$leadTitle, 'Northern lights over the fjord', 'Street food tour in one night', 'Building a tiny cabin',
            'Late-night store run', 'Deep ocean documentary', 'Retro synth mix', 'Desert storm chasing'];

        $videos = [];
        foreach ($titles as $index => $title) {
            $videos[] = Video::factory()
                ->for($category)
                ->for($creator, 'user')
                ->withStatus(VideoStatus::PUBLIC)
                ->create([
                    'title' => $title,
                    'description' => 'A real description of '.$title.'.',
                    'original_file_name' => 'clip.mp4',
                    'views' => 10000 - $index * 1000,
                    'duration' => 600 + $index * 60,
                    'published_at' => now()->subDays($index + 1),
                    'uploaded_at' => now()->subDays($index + 1),
                ]);
        }

        return $videos;
    }

    private function useLook(string $theme, string $home, string $hero, string $watch, string $card, string $header, ?string $sidebar): void
    {
        config([
            // site.theme_id = the resolved v2 theme AppearanceSettings v2 sets at boot (ThemeStructure prefers it).
            'site.theme_preset' => $theme,
            'site.theme_id' => $theme,
            'site.home_layout_mode' => $home,
            'site.hero_layout_mode' => $hero,
            'site.watch_layout_mode' => $watch,
            'site.video_card_layout_style' => $card,
            'site.header_style' => $header,
            'site.sidebar_style' => $sidebar,
            'site.front_sidebar_pinned_enabled' => true,
        ]);
    }

    private function mainRegion(string $html): string
    {
        $start = strpos($html, '<main');
        $end = strrpos($html, '</main>');

        return $start === false || $end === false ? $html : substr($html, $start, $end - $start);
    }

    private function bodyTag(string $html): string
    {
        return preg_match('/<body[^>]*>/', $html, $match) ? $match[0] : '';
    }

    /** Labels of the links marked as the current page (aria-current) inside the header links or the side menu. */
    private function currentLinks(string $html, string $region): array
    {
        $start = $region === 'avd-hnav' ? strpos($html, '<nav class="avd-hnav') : strpos($html, 'id="responsive-sidebar"');
        $this->assertNotFalse($start, "$region renders");
        $end = strpos($html, $region === 'avd-hnav' ? '</nav>' : 'avd-sidebar-section--explore', $start) ?: strlen($html);
        preg_match_all('/<a [^>]*aria-current="page"[^>]*>(?:<i[^>]*><\/i>)?(?:<span[^>]*>)?\s*([^<]+?)\s*</', substr($html, $start, $end - $start), $matches);

        return $matches[1];
    }
}
