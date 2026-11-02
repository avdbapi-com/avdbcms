<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsController;
use App\Models\Setting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\ListAdContexts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The homepage feed's list ad: the main feed of the homepage layouts with a top banner and one feed (Spotlight, the
 * layout of a new site, Cinema rows, Mosaic, Shorts and Control room) has the ad context home_feed and shows the ad
 * of Money > Ads > "Ad code for the main homepage list", in window.AD_CONFIG (VideosList) and in the server skeleton's
 * wide ad box; the Classic grid homepage list keeps its own. Up to 1.2.2 those feeds had ad contexts no map knew, so
 * they never showed a list ad. Each list's code is in window.AD_CONFIG once. The admin fields save on Money > Ads and
 * on the old settings page; old clients of the group save never reset them.
 */
class HomepageFeedListAdTest extends TestCase
{
    use RefreshDatabase;

    private const HOME_AD = '<div class="lane-f-home-feed-ad">HOME FEED AD</div>';

    private const NEW_AD = '<div class="lane-f-new-videos-ad">NEW VIDEOS AD</div>';

    /** layout => [home_layout_mode, the main feed's ad context, the ad code it shows] */
    public static function homepageLayouts(): array
    {
        return [
            'Spotlight' => ['youtube_spotlight', 'home_feed', self::HOME_AD],
            'Cinema rows' => ['cinema_carousel', 'home_feed', self::HOME_AD],
            'Mosaic' => ['bento_mosaic', 'home_feed', self::HOME_AD],
            'Shorts' => ['tiktok_shorts', 'home_feed', self::HOME_AD],
            'Control room' => ['cyberpunk_hud', 'home_feed', self::HOME_AD],
            'Classic grid' => ['grid_modern', 'new_videos', self::NEW_AD],
        ];
    }

    private function adsOn(array $extra = []): void
    {
        config(array_merge([
            'site.ads_enabled' => true,
            'site.ads_hide_for_premium' => true,
            'site.ad_grid_position' => 3,
            'site.ad_grid_max_per_feed' => 1,
            'site.ad_grid_layout' => 'grid_card',
            'site.ad_code_grid_home_feed' => self::HOME_AD,
            'site.ad_grid_layout_home_feed' => 'inherit',
            'site.ad_code_grid_new_videos' => self::NEW_AD,
            'site.ad_grid_layout_new_videos' => 'inherit',
            'site.grid_columns' => 4,
            'site.mobile_video_columns' => 2,
        ], $extra));
    }

    /**
     * window.AD_CONFIG of a page as the browser reads it: enabled and the two maps of list ads.
     *
     * @return array{enabled: bool, codes: array<string, string>, shapes: array<string, string>}
     */
    private function adConfig(string $html): array
    {
        $this->assertSame(1, preg_match('/window\.AD_CONFIG = \{\s*enabled: (true|false),/', $html, $enabled), 'window.AD_CONFIG');
        $this->assertSame(1, preg_match('/\bgridAdCodeByContext: ([^\n]*),\n/', $html, $codes), 'gridAdCodeByContext');
        $this->assertSame(1, preg_match('/\bgridLayoutByContext: ([^\n]*),\n/', $html, $shapes), 'gridLayoutByContext');

        return [
            'enabled' => $enabled[1] === 'true',
            'codes' => json_decode($codes[1], true, 512, JSON_THROW_ON_ERROR),
            'shapes' => json_decode($shapes[1], true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * The skeleton cards before each reserved wide ad box of a page part, with the box's classes.
     *
     * @return list<array{after: int, class: string}>
     */
    private function reserveBoxes(string $html): array
    {
        $boxes = [];
        $offset = 0;

        while (preg_match('/<div class="([^"]*)" data-avd-ad-reserve>/', $html, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $position = $match[0][1];
            $boxes[] = [
                'after' => substr_count(substr($html, 0, $position), 'class="avd-video-card"'),
                'class' => $match[1][0],
            ];
            $offset = $position + strlen($match[0][0]);
        }

        return $boxes;
    }

    /** The <videos-area> element of the homepage's main feed (the last list of the page). */
    private function mainFeed(string $html): string
    {
        $this->assertGreaterThan(0, preg_match_all('/<videos-area\b.*?<\/videos-area>/s', $html, $areas));

        return end($areas[0]);
    }

    #[DataProvider('homepageLayouts')]
    public function test_the_main_feed_of_every_homepage_layout_has_its_list_ad(string $layout, string $context, string $code): void
    {
        $this->adsOn();

        $html = $this->get('/?layout='.$layout)->assertOk()->getContent();
        $config = $this->adConfig($html);

        $this->assertStringContainsString('ad-context="'.$context.'"', $this->mainFeed($html));
        $this->assertTrue($config['enabled']);
        $this->assertSame($code, $config['codes'][$context] ?? null, "the {$layout} feed shows its list ad");
        $this->assertSame('inherit', $config['shapes'][$context] ?? null);
        $this->assertSame(ListAdContexts::all(), array_keys($config['codes']), 'every list context is in the map');
        $this->assertSame(ListAdContexts::all(), array_keys($config['shapes']));
    }

    public function test_the_server_skeleton_reserves_the_wide_row_of_the_homepage_feed(): void
    {
        $this->adsOn(['site.ad_grid_position' => 8, 'site.ad_grid_layout_home_feed' => 'wide_row']);

        // 3 rows: 6 skeleton cards on phones and tablets (2 columns), 12 from 1200px (4 columns): the row after video 8
        // exists only where 12 cards show (the rule of tests/Feature/ListAdPlacementTest.php, now for this context).
        $html = (string) $this->blade('<x-videos-area url="/api/videos?source=newest" columns="4" max-rows="3" ad-context="home_feed" early="false" />');

        $this->assertSame([['after' => 8, 'class' => 'col-12 avd-grid-ad-col avd-grid-ad-col--wide d-none d-xl-flex']], $this->reserveBoxes($html));

        // A context no list has (the 1.2.2 name of the Spotlight feed) gets no ad, so no box either.
        $html = (string) $this->blade('<x-videos-area url="/api/videos?source=newest" columns="4" max-rows="3" ad-context="yt_spotlight_feed" early="false" />');
        $this->assertSame([], $this->reserveBoxes($html));
    }

    public function test_the_homepage_reserves_the_box_where_the_wide_row_goes_and_nothing_for_a_card(): void
    {
        // The main feed without max rows: 4 rows of skeleton cards (8 on 2 columns, 16 on 4). N = 3, one ad: the row
        // after the row that holds video 3 ends at video 4 on 2 and on 4 columns.
        $this->adsOn(['site.ad_grid_layout_home_feed' => 'wide_row']);
        $boxes = $this->reserveBoxes($this->mainFeed($this->get('/?layout=youtube_spotlight')->assertOk()->getContent()));
        $this->assertSame([['after' => 4, 'class' => 'col-12 avd-grid-ad-col avd-grid-ad-col--wide']], $boxes);

        // "Same as above" takes the main shape of Money > Ads.
        $this->adsOn(['site.ad_grid_layout_home_feed' => 'inherit', 'site.ad_grid_layout' => 'wide_row']);
        $this->assertCount(1, $this->reserveBoxes($this->mainFeed($this->get('/?layout=cinema_carousel')->assertOk()->getContent())));

        // A card ad takes one card place, which a skeleton card already holds: no box.
        $this->adsOn(['site.ad_grid_layout_home_feed' => 'grid_card', 'site.ad_grid_layout' => 'wide_row']);
        $this->assertSame([], $this->reserveBoxes($this->mainFeed($this->get('/?layout=bento_mosaic')->assertOk()->getContent())));

        // No code: no ad, no box.
        $this->adsOn(['site.ad_code_grid_home_feed' => '  ', 'site.ad_grid_layout_home_feed' => 'wide_row']);
        $html = $this->get('/?layout=tiktok_shorts')->assertOk()->getContent();
        $this->assertSame([], $this->reserveBoxes($this->mainFeed($html)));
        $this->assertSame('  ', $this->adConfig($html)['codes']['home_feed'], 'VideosList trims the code: an empty one shows no ad');
    }

    public function test_each_list_ad_code_is_printed_once_per_list(): void
    {
        // The update copies the Classic grid's code into the homepage feed, so both lists hold the same tag. The page
        // carries it once per list (a 2,000-character tag was printed 7 times by the first version of this change).
        $tag = '<script async src="https://ads.example.com/tag.js" data-zone="LANE-F-ONE-COPY"></script>'.str_repeat('x', 2000);
        $this->adsOn(['site.ad_code_grid_new_videos' => $tag, 'site.ad_code_grid_home_feed' => $tag]);

        foreach (['/?layout=youtube_spotlight', '/?layout=grid_modern', '/trend'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $config = $this->adConfig($html);

            $this->assertSame(ListAdContexts::SETTINGS, array_keys($config['codes']), $url);
            $this->assertSame(['new_videos', 'home_feed'], array_keys(array_filter($config['codes'], fn (string $code): bool => $code === $tag)), $url);
            $this->assertSame(2, substr_count($html, 'LANE-F-ONE-COPY'), $url);
            $this->assertSame(2, substr_count($html, str_repeat('x', 2000)), $url);
        }

        // Only the homepage feed has it: once.
        $this->adsOn(['site.ad_code_grid_home_feed' => $tag]);
        $this->assertSame(1, substr_count($this->get('/?layout=cinema_carousel')->assertOk()->getContent(), 'LANE-F-ONE-COPY'));
    }

    public function test_ads_switched_off_leave_no_code_and_no_box(): void
    {
        $this->adsOn(['site.ads_enabled' => false, 'site.ad_grid_layout_home_feed' => 'wide_row']);

        foreach (['youtube_spotlight', 'cyberpunk_hud', 'grid_modern'] as $layout) {
            $html = $this->get('/?layout='.$layout)->assertOk()->getContent();
            $config = $this->adConfig($html);

            $this->assertFalse($config['enabled']);
            $this->assertSame([], $config['codes']);
            $this->assertSame([], $config['shapes']);
            $this->assertStringNotContainsString('data-avd-ad-reserve', $html);
            $this->assertStringNotContainsString('lane-f-home-feed-ad', $html);
        }
    }

    public function test_the_stored_settings_reach_the_homepage(): void
    {
        foreach (['ads_enabled' => '1', 'ad_grid_position' => '3', 'ad_code_grid_home_feed' => self::HOME_AD, 'ad_grid_layout_home_feed' => 'wide_row', 'ad_code_grid_new_videos' => self::NEW_AD] as $key => $value) {
            Setting::set($key, $value, 'monetization');
        }

        $this->applyDbSettings();

        foreach (ListAdContexts::SETTINGS as $list) {
            $this->assertSame((string) (Setting::get('ad_code_grid_'.$list) ?? ''), config('site.ad_code_grid_'.$list), $list);
        }

        $this->assertSame(self::HOME_AD, config('site.ad_code_grid_home_feed'));
        $this->assertSame('wide_row', config('site.ad_grid_layout_home_feed'));

        $config = $this->adConfig($this->get('/?layout=youtube_spotlight')->assertOk()->getContent());
        $this->assertSame(self::HOME_AD, $config['codes']['home_feed']);
        $this->assertSame('wide_row', $config['shapes']['home_feed']);
    }

    public function test_server_rendered_lists_read_their_ad_through_the_same_map(): void
    {
        $this->adsOn([
            'site.ad_code_grid_search_results' => '<div class="lane-f-search-ad">AD</div>',
            'site.ad_grid_layout_search_results' => 'wide_row',
            'site.ad_code_grid_playlist_videos' => '<div class="lane-f-playlist-ad">AD</div>',
            'site.ad_grid_layout_playlist_videos' => 'inherit',
        ]);

        $search = (string) $this->blade('<x-feed-ad-slot context="search_results" class="mb-3" />');
        $this->assertStringContainsString('lane-f-search-ad', $search);
        $this->assertStringContainsString('ad-slot-grid--wide', $search);

        $playlist = (string) $this->blade('<x-feed-ad-slot as="li" context="playlist_videos" />');
        $this->assertStringContainsString('<li class="ad-slot ad-slot-grid"', $playlist);
        $this->assertStringContainsString('lane-f-playlist-ad', $playlist);

        $this->assertStringContainsString('lane-f-home-feed-ad', (string) $this->blade('<x-feed-ad-slot context="home_feed" />'));
        $this->assertSame('', trim((string) $this->blade('<x-feed-ad-slot context="default" />')));
        $this->assertSame('', trim((string) $this->blade('<x-feed-ad-slot context="cinema_feed" />')), 'a context no list has');

        config(['site.ads_enabled' => false]);
        $this->assertSame('', trim((string) $this->blade('<x-feed-ad-slot context="search_results" />')));
    }

    public function test_money_ads_shows_and_saves_the_homepage_feed_fields(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();

        $page = $this->actingAs($admin)->get(route('admin.money.ads'))->assertOk();
        $page->assertSee('Ad shape in the main homepage list')
            ->assertSee('Ad code for the main homepage list')
            ->assertSee('Used by the Spotlight, Cinema rows, Mosaic, Shorts and Control room homepage layouts. Leave empty to use no ad there.')
            ->assertSee('Ad code for the Classic grid homepage list')
            ->assertSee('name="groups[monetization][ad_code_grid_home_feed]"', false)
            ->assertSee('name="groups[monetization][ad_grid_layout_home_feed]"', false)
            ->assertDontSee('Empty = use the main setting');

        $this->actingAs($admin)
            ->putJson('/admin/settings/save', [
                'groups' => ['monetization' => ['ad_code_grid_home_feed' => self::HOME_AD, 'ad_grid_layout_home_feed' => 'wide_row']],
                'fields' => ['monetization' => ['ad_code_grid_home_feed', 'ad_grid_layout_home_feed']],
            ])
            ->assertOk()
            ->assertJsonPath('values.monetization.ad_code_grid_home_feed', self::HOME_AD)
            ->assertJsonPath('values.monetization.ad_grid_layout_home_feed', 'wide_row');

        $this->assertSame(self::HOME_AD, Setting::getGroup('monetization')['ad_code_grid_home_feed']);
        $this->assertSame('wide_row', Setting::getGroup('monetization')['ad_grid_layout_home_feed']);

        // The code box loads its value after the page, like the other ad codes.
        $this->actingAs($admin)->getJson('/admin/settings/values?keys=ad_code_grid_home_feed')
            ->assertOk()
            ->assertJsonPath('values.ad_code_grid_home_feed', self::HOME_AD);

        // A shape that does not exist is refused and nothing is written.
        $this->actingAs($admin)
            ->putJson('/admin/settings/save', [
                'groups' => ['monetization' => ['ad_code_grid_home_feed' => '', 'ad_grid_layout_home_feed' => 'sideways']],
                'fields' => ['monetization' => ['ad_code_grid_home_feed', 'ad_grid_layout_home_feed']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['monetization.ad_grid_layout_home_feed']);
        $this->assertSame(self::HOME_AD, Setting::getGroup('monetization')['ad_code_grid_home_feed']);

        // Clearing the code is the owner's choice: it stays empty.
        $this->actingAs($admin)
            ->putJson('/admin/settings/save', [
                'groups' => ['monetization' => ['ad_code_grid_home_feed' => '', 'ad_grid_layout_home_feed' => 'inherit']],
                'fields' => ['monetization' => ['ad_code_grid_home_feed', 'ad_grid_layout_home_feed']],
            ])
            ->assertOk();
        $this->assertSame('', Setting::getGroup('monetization')['ad_code_grid_home_feed']);
    }

    public function test_old_clients_of_the_group_save_never_reset_the_homepage_feed(): void
    {
        Setting::set('ad_code_grid_home_feed', self::HOME_AD, 'monetization');
        Setting::set('ad_grid_layout_home_feed', 'wide_row', 'monetization');
        $admin = User::factory()->admin()->create();

        // Fleet scripts and the old settings page up to 1.2.2 send the monetization group without the new keys.
        $payload = ['ads_enabled' => '1', 'ad_preroll_duration' => '5', 'ad_grid_position' => '8', 'ad_grid_max_per_feed' => '1', 'ad_grid_layout' => 'grid_card'];
        foreach (ListAdContexts::SETTINGS as $list) {
            if ($list !== 'home_feed') {
                $payload['ad_grid_layout_'.$list] = 'inherit';
                $payload['ad_code_grid_'.$list] = '';
            }
        }

        $this->actingAs($admin)->putJson('/admin/settings/update/monetization', $payload)->assertOk();
        $this->assertSame(self::HOME_AD, Setting::getGroup('monetization')['ad_code_grid_home_feed']);
        $this->assertSame('wide_row', Setting::getGroup('monetization')['ad_grid_layout_home_feed']);

        // The old settings page now sends them.
        $this->actingAs($admin)->putJson('/admin/settings/update/monetization', $payload + ['ad_code_grid_home_feed' => self::NEW_AD, 'ad_grid_layout_home_feed' => 'grid_card'])->assertOk();
        $this->assertSame(self::NEW_AD, Setting::getGroup('monetization')['ad_code_grid_home_feed']);
        $this->assertSame('grid_card', Setting::getGroup('monetization')['ad_grid_layout_home_feed']);

        $schema = (new ReflectionMethod(SettingsController::class, 'settingsSchema'))->invoke(app(SettingsController::class));
        $this->assertTrue($schema['monetization']['ad_code_grid_home_feed']['only_when_sent']);
        $this->assertTrue($schema['monetization']['ad_grid_layout_home_feed']['only_when_sent']);

        foreach (ListAdContexts::SETTINGS as $list) {
            $this->assertArrayHasKey('ad_code_grid_'.$list, $schema['monetization'], $list);
            $this->assertArrayHasKey('ad_grid_layout_'.$list, $schema['monetization'], $list);
        }
    }

    public function test_the_old_settings_page_shows_the_homepage_feed(): void
    {
        config(['admin.ui' => 'v1']);
        Setting::set('ad_code_grid_home_feed', self::HOME_AD, 'monetization');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.settings.index', ['tab' => 'monetization']))
            ->assertOk()
            ->assertSee('Homepage Feed')
            ->assertSee('name="ad_grid_layout_home_feed"', false)
            ->assertSee('name="ad_code_grid_home_feed"', false)
            ->assertSee(e(self::HOME_AD), false);
    }

    public function test_a_view_only_demo_account_cannot_change_the_homepage_feed_ad(): void
    {
        config(['admin.ui' => 'v2']);
        Setting::set('ad_code_grid_home_feed', self::HOME_AD, 'monetization');
        $demo = User::factory()->admin()->create();
        $demo->forceFill(['is_view_only' => 1])->save();

        $this->actingAs($demo)
            ->putJson('/admin/settings/save', [
                'groups' => ['monetization' => ['ad_code_grid_home_feed' => '<script>alert(1)</script>', 'ad_grid_layout_home_feed' => 'wide_row']],
                'fields' => ['monetization' => ['ad_code_grid_home_feed', 'ad_grid_layout_home_feed']],
            ])
            ->assertForbidden();

        $this->assertSame(self::HOME_AD, Setting::getGroup('monetization')['ad_code_grid_home_feed']);
    }

    /** What AppServiceProvider::applyDbSettings() does at the start of every web request (it skips the console). */
    private function applyDbSettings(): void
    {
        Setting::clearCache();
        $provider = $this->app->getProvider(AppServiceProvider::class);
        $console = new ReflectionProperty($this->app, 'isRunningInConsole');
        $before = $console->getValue($this->app);
        $console->setValue($this->app, false);

        try {
            (new ReflectionMethod($provider, 'applyDbSettings'))->invoke($provider);
        } finally {
            $console->setValue($this->app, $before);
        }
    }
}
