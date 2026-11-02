<?php

namespace Tests\Unit;

use App\Support\ListAdContexts;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The list ad of every video list (App\Support\ListAdContexts): every ad context the views and scripts use is a list
 * with its own settings, the main list of the five homepage layouts with a top banner is the homepage feed (up to 1.2.2
 * those layouts gave it five contexts no map knew, so the main list of the default look never showed a list ad), each
 * list is in window.AD_CONFIG once, and every list of Money > Ads has its two admin fields.
 */
class ListAdContextsTest extends TestCase
{
    /** The homepage layouts with a top banner and one list (pages/home.blade.php $homeFeedLayouts). */
    private const HOMEPAGE_FEED_LAYOUTS = ['youtube_spotlight', 'cinema_carousel', 'bento_mosaic', 'tiktok_shorts', 'cyberpunk_hud'];

    /** The contexts those layouts gave their main list up to 1.2.2: no list has them. */
    private const OLD_HOMEPAGE_FEED_CONTEXTS = ['yt_spotlight_feed', 'cinema_feed', 'bento_feed', 'shorts_feed', 'cyber_feed'];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> the files under a folder with one of the endings */
    private static function files(string $folder, array $endings): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root().'/'.$folder, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            foreach ($endings as $ending) {
                if (str_ends_with($file->getFilename(), $ending)) {
                    $files[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }

        sort($files);

        return $files;
    }

    private static function relative(string $path): string
    {
        return substr($path, strlen(str_replace('\\', '/', self::root())) + 1);
    }

    /**
     * The attributes of every <x-videos-area> and <x-feed-ad-slot> tag of a Blade source: tags start a line and are
     * written self-closing (attribute values may hold "=>" or "->", so a tag ends at its "/>"); mentions in comments
     * ("<x-videos-area>: ...") are not tags.
     *
     * @return list<string>
     */
    private static function listTags(string $source): array
    {
        preg_match_all('/^[ \t]*<x-(?:videos-area|feed-ad-slot)(?=[\s\/])(.*?)\/>/ms', $source, $tags);

        return $tags[1];
    }

    /**
     * Layout => [ad context, heading icon] of pages/home.blade.php $homeFeedLayouts.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function homeFeedLayouts(): array
    {
        $home = (string) file_get_contents(self::root().'/resources/views/pages/home.blade.php');
        preg_match('/\$homeFeedLayouts = \[(.*?)\];/s', $home, $block);
        preg_match_all("/'([a-z_]+)' => \\['([a-z0-9_]+)', '([a-z0-9-]+)'\\]/", $block[1] ?? '', $rows, PREG_SET_ORDER);
        $layouts = [];

        foreach ($rows as $row) {
            $layouts[$row[1]] = [$row[2], $row[3]];
        }

        return $layouts;
    }

    /**
     * Every list context the site uses, with the files that use it: <x-videos-area ad-context>, <x-feed-ad-slot
     * context>, the homepage layouts with a top banner and one feed (pages/home.blade.php $homeFeedLayouts), direct
     * reads of a list's settings (config('site.ad_code_grid_<list>')) and Preact lists (adContext="...").
     *
     * @return array<string, list<string>>
     */
    private static function usedContexts(): array
    {
        $used = [];

        foreach (self::files('resources/views', ['.blade.php']) as $path) {
            $source = (string) file_get_contents($path);
            $contexts = [];

            foreach (self::listTags($source) as $attributes) {
                if (preg_match('/(?<![\w:-])(?:ad-)?context="([a-z0-9_]+)"/', $attributes, $match) === 1) {
                    $contexts[] = $match[1];
                }
            }

            preg_match_all("/site\\.ad_(?:code_grid|grid_layout)_([a-z0-9_]+)'/", $source, $reads);

            foreach (array_merge($contexts, $reads[1]) as $context) {
                $used[$context][] = self::relative($path);
            }
        }

        foreach (self::homeFeedLayouts() as [$context]) {
            $used[$context][] = 'resources/views/pages/home.blade.php ($homeFeedLayouts)';
        }

        foreach (self::files('resources/js', ['.tsx', '.ts']) as $path) {
            preg_match_all('/\badContext="([a-z0-9_]+)"/', (string) file_get_contents($path), $props);

            foreach ($props[1] as $context) {
                $used[$context][] = self::relative($path);
            }
        }

        ksort($used);

        return $used;
    }

    public function test_every_ad_context_the_site_uses_is_a_list(): void
    {
        $used = self::usedContexts();

        $this->assertSame([], array_values(array_diff(array_keys($used), ListAdContexts::SETTINGS)), 'contexts without a list ad: '.json_encode($used));

        // The lists that carry a list ad: the homepage of every layout, search, categories, channels, playlists, Trending,
        // Subscriptions and Up next.
        foreach (['smart_discovery', 'creator_spotlight', 'trending_actors', 'new_videos', 'home_feed', 'trend_page', 'category_page', 'subscriptions', 'channel_videos', 'search_results', 'playlist_videos', 'watch_related', 'channel_home_featured', 'channel_home_latest'] as $context) {
            $this->assertArrayHasKey($context, $used, "{$context} is used by a list");
        }
    }

    public function test_every_list_is_used_by_a_list_and_named_once(): void
    {
        $used = array_keys(self::usedContexts());

        foreach (ListAdContexts::SETTINGS as $list) {
            $this->assertContains($list, $used, "{$list} has settings but no list uses them");
        }

        $this->assertSame(count(ListAdContexts::SETTINGS), count(array_unique(ListAdContexts::SETTINGS)));
        $this->assertSame(ListAdContexts::SETTINGS, ListAdContexts::all());
    }

    public function test_lists_never_pass_an_ad_context_the_scan_cannot_see(): void
    {
        // A context computed at render time would escape the scan above: only the homepage feed partial takes one,
        // from $homeFeedLayouts, which the scan reads.
        $dynamic = [];

        $tags = 0;

        foreach (self::files('resources/views', ['.blade.php']) as $path) {
            foreach (self::listTags((string) file_get_contents($path)) as $attributes) {
                $tags++;

                if (preg_match('/(?<![\w-]):(?:ad-)?context=/', $attributes) === 1) {
                    $dynamic[] = self::relative($path);
                }
            }
        }

        $this->assertSame(['resources/views/pages/partials/home-feed.blade.php'], $dynamic);
        $this->assertGreaterThanOrEqual(13, $tags, 'the scan finds the list tags');
    }

    public function test_the_five_homepage_layouts_with_a_top_banner_use_the_homepage_feed(): void
    {
        $layouts = self::homeFeedLayouts();

        $this->assertSame(self::HOMEPAGE_FEED_LAYOUTS, array_keys($layouts));

        foreach ($layouts as $layout => [$context]) {
            $this->assertSame('home_feed', $context, "the main list of {$layout}");
        }

        $this->assertSame('home_feed', ListAdContexts::settingsOf('home_feed'));
        $this->assertSame('new_videos', ListAdContexts::settingsOf('new_videos'), 'the Classic grid list keeps its own');

        // The five contexts of 1.2.2 are gone: no list and no script names them any more.
        foreach (self::OLD_HOMEPAGE_FEED_CONTEXTS as $old) {
            $this->assertNull(ListAdContexts::settingsOf($old), $old);
        }

        foreach (array_merge(self::files('resources/views', ['.blade.php']), self::files('resources/js', ['.tsx', '.ts'])) as $path) {
            $source = (string) file_get_contents($path);

            foreach (self::OLD_HOMEPAGE_FEED_CONTEXTS as $old) {
                $this->assertStringNotContainsString($old, $source, self::relative($path));
            }
        }
    }

    public function test_codes_and_shapes_resolve_through_the_list_settings(): void
    {
        config([
            'site.ad_code_grid_home_feed' => '<div class="home-feed-ad">AD</div>',
            'site.ad_grid_layout_home_feed' => 'wide_row',
            'site.ad_code_grid_new_videos' => '<div class="new-videos-ad">AD</div>',
            'site.ad_grid_layout_new_videos' => 'inherit',
            'site.ad_grid_layout' => 'grid_card',
        ]);

        $this->assertSame('<div class="home-feed-ad">AD</div>', ListAdContexts::code('home_feed'));
        $this->assertSame('wide_row', ListAdContexts::ownShape('home_feed'));
        $this->assertSame('wide_row', ListAdContexts::shape('home_feed'));

        $this->assertSame('<div class="new-videos-ad">AD</div>', ListAdContexts::code('new_videos'));
        $this->assertSame('inherit', ListAdContexts::ownShape('new_videos'));
        $this->assertSame('grid_card', ListAdContexts::shape('new_videos'), 'inherit = the main shape');

        config(['site.ad_grid_layout' => 'wide_row', 'site.ad_grid_layout_home_feed' => 'junk']);
        $this->assertSame('inherit', ListAdContexts::ownShape('home_feed'), 'a stored value no shape has counts as inherit');
        $this->assertSame('wide_row', ListAdContexts::shape('home_feed'));

        config(['site.ad_code_grid_home_feed' => ['not', 'a', 'string']]);
        $this->assertSame('', ListAdContexts::code('home_feed'), 'a code that is not text is no code');
    }

    public function test_a_context_no_list_has_shows_no_ad(): void
    {
        config([
            'site.ad_grid_layout' => 'wide_row',
            'site.ad_code_grid_default' => '<div>AD</div>',
            'site.ad_code_grid_cinema_feed' => '<div>AD</div>',
            'site.ad_code_grid_home_feed' => '<div>AD</div>',
        ]);

        foreach ([null, '', 'default', 'home', 'Home_Feed', ' home_feed', 'cinema_feed', 123, ['home_feed']] as $context) {
            $this->assertNull(ListAdContexts::settingsOf($context));
            $this->assertSame('', ListAdContexts::code($context));
            $this->assertSame('inherit', ListAdContexts::ownShape($context));
        }

        $this->assertSame('wide_row', ListAdContexts::shape('default'), 'the main shape, but without a code no ad shows');
    }

    public function test_the_maps_hold_every_list_once(): void
    {
        $tag = '<script async src="https://ads.example.com/t.js" data-zone="LANE-F-ONCE"></script>';
        config(['site.ad_code_grid_home_feed' => $tag, 'site.ad_grid_layout_home_feed' => 'grid_card', 'site.ad_code_grid_new_videos' => '']);

        $codes = ListAdContexts::codes();
        $shapes = ListAdContexts::shapes();

        $this->assertSame(ListAdContexts::SETTINGS, array_keys($codes));
        $this->assertSame(ListAdContexts::SETTINGS, array_keys($shapes));
        $this->assertSame($tag, $codes['home_feed']);
        $this->assertSame('grid_card', $shapes['home_feed']);
        $this->assertSame(1, substr_count((string) json_encode($codes), 'LANE-F-ONCE'), 'the homepage feed code is printed once');

        // The same code in two lists (the update copies the Classic grid's code into the homepage feed): once per list.
        config(['site.ad_code_grid_new_videos' => $tag]);
        $this->assertSame(2, substr_count((string) json_encode(ListAdContexts::codes()), 'LANE-F-ONCE'));
    }

    public function test_money_ads_has_both_fields_of_every_list(): void
    {
        $settings = (array) config('admin_settings.settings');
        $card = (array) config('admin_settings.pages')['money.ads']['cards']['per_section'];
        $index = json_decode((string) file_get_contents(self::root().'/resources/admin/lib/settings-index.json'), true);
        $indexed = array_column($index, null, 'key');
        $expected = [];

        foreach (ListAdContexts::SETTINGS as $list) {
            $expected[] = 'ad_grid_layout_'.$list;
            $expected[] = 'ad_code_grid_'.$list;

            foreach (['ad_grid_layout_'.$list => 'segmented', 'ad_code_grid_'.$list => 'code'] as $key => $control) {
                $this->assertArrayHasKey($key, $settings);
                $this->assertSame(['monetization', 'money.ads', 'per_section', 'advanced', $control], [
                    $settings[$key]['group'], $settings[$key]['page'], $settings[$key]['card'], $settings[$key]['level'], $settings[$key]['control'],
                ], $key);
                $this->assertArrayHasKey($key, $indexed, "{$key} is in the Ctrl+K search");
            }
        }

        $this->assertSame($expected, $card['fields'], 'Different ads per list: shape then code of each list, in Money > Ads order');

        // The old settings page (admin UI v1, Settings > Monetization) lists the same lists.
        $old = (string) file_get_contents(self::root().'/resources/views/admin/settings/index.blade.php');
        preg_match_all("/'code' => 'ad_code_grid_([a-z0-9_]+)'/", $old, $oldCodes);
        preg_match_all("/'layout' => 'ad_grid_layout_([a-z0-9_]+)'/", $old, $oldShapes);
        $this->assertSame(ListAdContexts::SETTINGS, $oldCodes[1]);
        $this->assertSame(ListAdContexts::SETTINGS, $oldShapes[1]);
        // The admin's word for the list is "main homepage list"; the Classic grid's is named after its layout.
        $this->assertSame('Ad code for the main homepage list', $settings['ad_code_grid_home_feed']['label']);
        $this->assertSame('Ad shape in the main homepage list', $settings['ad_grid_layout_home_feed']['label']);
        $this->assertSame('Ad shape in the Classic grid homepage list', $settings['ad_grid_layout_new_videos']['label']);
        $this->assertStringContainsString('Spotlight, Cinema rows, Mosaic, Shorts and Control room', $settings['ad_code_grid_home_feed']['help']);
        $this->assertSame('Ad code for the Classic grid homepage list', $settings['ad_code_grid_new_videos']['label']);
        $this->assertStringNotContainsString('Empty = use the main setting', $card['description'], 'an empty list code shows no ad');
    }
}
