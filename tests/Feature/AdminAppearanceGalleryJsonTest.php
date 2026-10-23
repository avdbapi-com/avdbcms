<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AdminPreferences;
use App\Support\AppearancePages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Appearance section's pages and JSON: the gallery (12 looks, 32 themes, 24 a page, family / mode filters, old
 * names found as "formerly ..."), the daily calendar, every page within its HTML budget, the old ?tab= addresses and
 * the old admin.
 */
class AdminAppearanceGalleryJsonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2']);
        Setting::set('appearance_schema_version', '2', 'appearance');
        Setting::set('theme_preset', 'tokyo_neon', 'appearance');
        Setting::set('theme_flavor', 'signature', 'appearance');
    }

    public function test_the_looks_tab_lists_the_12_complete_looks_demo1_first(): void
    {
        $json = $this->actingAs($this->admin())->getJson(route('admin.appearance.gallery', ['tab' => 'looks']))->assertOk()->json();

        $this->assertSame(12, $json['total']);
        $this->assertCount(12, $json['items']);
        $this->assertSame('youtube_spotlight_pro', $json['items'][0]['id']);
        $this->assertSame('YouTube Spotlight Pro', $json['items'][0]['label']);
        $this->assertTrue($json['items'][0]['default']);

        foreach ($json['items'] as $look) {
            $this->assertNotSame('', $look['theme_name'], $look['id']);
            $this->assertCount(3, $look['colours'], $look['id']);
        }
    }

    public function test_the_themes_tab_pages_24_at_a_time(): void
    {
        $admin = $this->admin();
        $first = $this->actingAs($admin)->getJson(route('admin.appearance.gallery', ['tab' => 'themes']))->assertOk()->json();
        $second = $this->actingAs($admin)->getJson(route('admin.appearance.gallery', ['tab' => 'themes', 'page' => 2]))->assertOk()->json();

        $this->assertSame(32, $first['total']);
        $this->assertCount(24, $first['items']);
        $this->assertTrue($first['has_more']);
        $this->assertCount(8, $second['items']);
        $this->assertFalse($second['has_more']);
        $this->assertCount(32, array_unique(array_merge(array_column($first['items'], 'id'), array_column($second['items'], 'id'))));

        $flavors = array_sum(array_map(fn (array $theme): int => count($theme['flavors']), array_merge($first['items'], $second['items'])));
        $this->assertSame(174, $flavors, '174 colour styles');

        $current = collect($first['items'])->firstWhere('id', 'tokyo_neon');
        $this->assertTrue($current['current']);
        $this->assertSame('signature', $current['current_flavor']);
    }

    public function test_show_all_after_the_first_12_themes_gets_every_other_theme(): void
    {
        $admin = $this->admin();
        $html = $this->actingAs($admin)->get(route('admin.appearance.index', ['tab' => 'themes']))->assertOk()->getContent();
        preg_match_all('/data-theme-id="([a-z0-9_]+)"/', $html, $shown);
        $this->assertCount(12, $shown[1], 'the page shows 12 themes first');

        // "Show all" asks for the themes after the cards shown (offset), never for a page of another size.
        $rest = $this->actingAs($admin)->getJson(route('admin.appearance.gallery', ['tab' => 'themes', 'offset' => count($shown[1])]))->assertOk()->json();

        $this->assertSame(12, $rest['offset']);
        $this->assertCount(20, $rest['items']);
        $this->assertFalse($rest['has_more']);
        $all = array_merge($shown[1], array_column($rest['items'], 'id'));
        $this->assertCount(32, array_unique($all));
        $this->assertContains('broadsheet', $all);
        $this->assertContains('lavender_cloud', $all);
    }

    public function test_family_mode_and_search_filters_find_old_names_too(): void
    {
        $admin = $this->admin();

        $studio = $this->actingAs($admin)->getJson(route('admin.appearance.gallery', ['tab' => 'themes', 'family' => 'studio']))->json('items');
        $this->assertSame(['tokyo_neon', 'daylight_paper', 'clean_graphite'], array_column($studio, 'id'));

        $light = $this->actingAs($admin)->getJson(route('admin.appearance.gallery', ['tab' => 'themes', 'mode' => 'light']))->json('items');
        $this->assertNotEmpty($light);
        $this->assertSame(['light'], array_values(array_unique(array_column($light, 'mode'))));

        // An old (v1) theme name finds the theme it became.
        $old = $this->actingAs($admin)->getJson(route('admin.appearance.gallery', ['tab' => 'themes', 'q' => 'Tokyo Neon Horizon']))->json('items');
        $this->assertSame('tokyo_neon', $old[0]['id']);
        $this->assertStringContainsString('Tokyo Neon Horizon', $old[0]['match']);

        $this->actingAs($admin)->getJson(route('admin.appearance.gallery', ['tab' => 'themes', 'q' => 'zzzz-no-theme']))->assertOk()->assertJson(['total' => 0, 'items' => []]);
    }

    public function test_the_calendar_answers_one_month_of_daily_designs(): void
    {
        $admin = $this->admin();
        $october = $this->actingAs($admin)->getJson(route('admin.appearance.calendar', ['month' => 10]))->assertOk()->json();

        $this->assertSame(10, $october['month']);
        $this->assertCount(31, $october['days']);
        $day = $october['days'][0];
        $this->assertSame(1, $day['day_of_month']);
        $this->assertCount(3, $day['colours']);
        $this->assertStringContainsString('daily_design='.$day['day'], $day['preview']);

        $this->assertCount(28 + (int) now()->isLeapYear(), $this->actingAs($admin)->getJson(route('admin.appearance.calendar', ['month' => 2]))->json('days'));
        $this->assertSame(12, $this->actingAs($admin)->getJson(route('admin.appearance.calendar', ['month' => 99]))->json('month'));
    }

    public function test_a_draft_scope_changes_the_calendar_of_its_author(): void
    {
        $admin = $this->admin();
        Setting::set('daily_design_scope', 'palette_only', 'appearance');

        $palette = $this->actingAs($admin)->getJson(route('admin.appearance.calendar', ['month' => 5]))->json();
        $this->actingAs($admin)->postJson(route('admin.appearance.draft'), ['set' => ['daily_design_scope' => 'full']])->assertOk();
        $full = $this->actingAs($admin)->getJson(route('admin.appearance.calendar', ['month' => 5]))->json();

        $this->assertSame('palette_only', $palette['scope']);
        $this->assertSame('full', $full['scope']);
    }

    public function test_every_appearance_page_renders_in_the_shell_within_its_budget(): void
    {
        $admin = $this->admin();
        AdminPreferences::set($admin, 'developer_mode', true);
        $urls = [route('admin.appearance.index')];

        foreach (array_keys(AppearancePages::PAGES) as $page) {
            $urls[] = route('admin.appearance.page', ['page' => $page]);
        }

        foreach ($urls as $url) {
            $html = $this->actingAs($admin->fresh())->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('class="ad-shell"', $html, $url);
            $this->assertStringContainsString('data-ad-appearance', $html, $url);
            $this->assertStringContainsString('id="ad-appearance-state"', $html, $url);
            $this->assertLessThan(120 * 1024, strlen($html), $url.' stays under 120 KB');
            // The admin never loads theme fonts or effects.
            $this->assertStringNotContainsString('fonts.googleapis', $html, $url);
            $this->assertStringNotContainsString('backdrop-filter', $html, $url);
        }
    }

    public function test_the_pages_without_scripts_load_the_appearance_styles(): void
    {
        $this->withVite();
        $admin = $this->admin();
        $style = 'build/'.json_decode((string) file_get_contents(public_path('build/manifest.json')), true)['resources/admin/appearance/appearance.ts']['css'][0];

        foreach ([
            route('admin.appearance.gallery', ['tab' => 'themes']),
            route('admin.appearance.gallery', ['tab' => 'looks']),
            route('admin.appearance.calendar', ['month' => 3]),
            route('admin.appearance.history'),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertSee($style, false);
        }
    }

    public function test_titles_with_an_ampersand_are_escaped_once(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.appearance.page', ['page' => 'brand']))->assertOk()->getContent();

        $this->assertStringContainsString('<title>Logo, colours &amp; fonts · ', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    public function test_the_header_page_edits_the_announcement_bar(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.appearance.page', ['page' => 'menus']))->assertOk()->getContent();

        $this->assertStringContainsString('data-key="announcement_banner_enabled"', $html);
        $this->assertStringContainsString('data-key="announcement_banner"', $html);
        $this->assertGreaterThan(strpos($html, 'id="card-header"'), strpos($html, 'id="card-announcement"'));
        $this->assertLessThan(strpos($html, 'id="card-side"'), strpos($html, 'id="card-announcement"'), 'right after the header card');
        $this->assertStringContainsString('Notices with a title and a colour, at the top or bottom of the page: <a href="'.route('admin.announcements.index'), $html);
        $this->assertStringContainsString('fields[appearance]', $html);
        $this->assertMatchesRegularExpression('/name="fields\[appearance\]" value="[^"]*announcement_banner/', $html, 'published without scripts too');
        $this->assertContains('announcement_banner', AppearancePages::editableKeys(false));
        $this->assertSame('appearance.menus', AppearancePages::pageOf('announcement_banner'));
    }

    public function test_config_ctrl_k_and_the_pages_agree_on_where_the_announcement_bar_lives(): void
    {
        // Owner decision OD3: the generator places both keys on Header, menus & footer (card "announcement").
        $pages = config('admin_settings.pages');
        $this->assertSame(['announcement_banner_enabled', 'announcement_banner'], $pages['appearance.menus']['cards']['announcement']['fields']);
        $this->assertArrayNotHasKey('community.announcements', $pages, 'no settings card left there');

        $rows = collect(\App\Support\AdminSearchIndex::settingRows())->keyBy('key');

        foreach (['announcement_banner_enabled', 'announcement_banner'] as $key) {
            $setting = config("admin_settings.settings.{$key}");
            $this->assertSame(['appearance.menus', 'announcement'], [$setting['page'], $setting['card']], $key);
            $this->assertSame(['appearance.menus', 'Announcement bar'], [$rows[$key]['page'], $rows[$key]['card']], "Ctrl+K row of {$key}");
        }

        // Every Ctrl+K row names the page and card the config places the setting on.
        foreach ($rows as $key => $row) {
            $setting = config("admin_settings.settings.{$key}");
            $this->assertSame($setting['page'], $row['page'], $key);
            $this->assertSame($pages[$setting['page']]['cards'][$setting['card']]['title'] ?? null, $row['card'], $key);
        }

        // The menu and Ctrl+K's pages agree too: Community > Announcements holds the notices (not the bar), and the
        // old name of the bar's fields finds Header, menus & footer.
        $nav = \App\Support\AdminNav::pages()['community.announcements'];
        $this->assertSame(['Announcements', 'Notices with a title and a colour, at the top or bottom of the page.'], [$nav['label'], $nav['description']]);
        $keywords = collect(\App\Support\AdminSearchIndex::build($this->admin())['pages'])->pluck('k', 'id');
        $this->assertSame(['Announcements'], $keywords['community.announcements']);
        $this->assertContains('Appearance: announcement banner fields', $keywords['appearance.menus']);
    }

    public function test_the_daily_calendar_has_weekday_names_and_stays_in_this_year(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.appearance.index', ['tab' => 'daily']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div class="ad-cal-head" aria-hidden="true">(<span>[^<]+<\/span>){7}<\/div>/', $html);
        $this->assertStringContainsString('<span>'.now()->startOfWeek(\Carbon\CarbonInterface::MONDAY)->translatedFormat('D').'</span>', $html);
    }

    public function test_the_device_rule_says_what_each_choice_means(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.appearance.page', ['page' => 'effects']))->assertOk()->getContent();

        $this->assertStringContainsString('Each device gets the effects it can handle. Weak phones get still versions.', $html);
        $this->assertStringContainsString('Still, simple look on every device. Fastest.', $html);
    }

    public function test_the_monitor_shows_its_homepage_picture_only_for_the_homepage(): void
    {
        $admin = $this->admin();
        $emptyShows = function (string $page) use ($admin): bool {
            $html = $this->actingAs($admin)->get(route('admin.appearance.page', ['page' => $page]))->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<span class="ad-monitor-empty"([^>]*)>/', $html, $page);
            preg_match('/<span class="ad-monitor-empty"([^>]*)>/', $html, $match);

            return ! str_contains($match[1], 'hidden');
        };

        $this->assertFalse($emptyShows('homepage'), 'the homepage shows the picture of the homepage');
        $this->assertTrue($emptyShows('video-page'), 'the video page asks for the live preview instead');
    }

    public function test_pickers_are_radio_groups_with_wireframes(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.appearance.page', ['page' => 'homepage']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div class="ad-pickers[^"]*" role="radiogroup" aria-labelledby="home_layout_mode-label"/', $html);
        $this->assertStringContainsString('name="groups[appearance][hero_layout_mode]" value="editorial_lead"', $html);
        $this->assertStringContainsString('<svg class="wf" viewBox="0 0 160 100"', $html);

        $menus = $this->actingAs($this->admin())->get(route('admin.appearance.page', ['page' => 'menus']))->getContent();
        $this->assertStringContainsString('value="transparent_overlay"', $menus);
        $this->assertStringContainsString('name="groups[appearance][sidebar_style]" value="rail"', $menus);
    }

    public function test_the_effects_page_shows_levels_impact_and_the_weak_phone_estimate(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.appearance.page', ['page' => 'effects']))->assertOk()->getContent();

        foreach (['Off', 'Calm', 'Standard', 'Maximum', 'Speed on a weak phone', 'class="ad-impact is-high"', 'name="ad_effects_level"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        $calm = AppearancePages::speed(array_replace(['performance_tier_mode' => 'auto', 'effects_enabled' => '1'], AppearancePages::LEVELS['calm']['writes']));
        $max = AppearancePages::speed(array_replace(['performance_tier_mode' => 'rich', 'background_atmosphere_effect' => 'aurora_glow'], AppearancePages::LEVELS['maximum']['writes']));
        $this->assertLessThan($max['score'], $calm['score']);
        $this->assertSame('slow', $max['verdict']);
        $this->assertSame(0, AppearancePages::speed(AppearancePages::LEVELS['off']['writes'])['score']);
        $this->assertSame('custom', AppearancePages::levelOf(['effects_enabled' => '1', 'motion_level' => 'off']));
        $this->assertSame('off', AppearancePages::levelOf(AppearancePages::LEVELS['off']['writes']));
    }

    public function test_old_tab_addresses_open_the_appearance_pages(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/settings?tab=appearance')->assertRedirect('/admin/appearance');
        $target = config('admin_settings.tab_redirects.discovery');
        $this->assertNotEmpty($target);
        $this->actingAs($admin)->get('/admin/settings?tab=discovery')->assertRedirect($target);
        $this->actingAs($admin)->get($target)->assertOk();
    }

    public function test_the_old_admin_keeps_the_old_appearance_tab(): void
    {
        config(['admin.ui' => 'v1']);
        $admin = $this->admin();

        foreach ([route('admin.appearance.index'), route('admin.appearance.page', ['page' => 'homepage']), route('admin.appearance.history')] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('avd-back-sidebar', $html, $url);
            $this->assertStringNotContainsString('class="ad-shell"', $html, $url);
        }
    }

    public function test_custom_code_page_needs_developer_mode(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.appearance.page', ['page' => 'code']))->assertForbidden();

        AdminPreferences::set($admin, 'developer_mode', true);
        $this->actingAs($admin->fresh())->get(route('admin.appearance.page', ['page' => 'code']))->assertOk()->assertSee('name="groups[appearance][custom_css]"', false);
    }

    public function test_guests_and_members_cannot_reach_the_section(): void
    {
        $this->getJson(route('admin.appearance.gallery'))->assertNotFound();
        $this->postJson(route('admin.appearance.draft'), ['set' => ['home_intro_title' => 'x']])->assertNotFound();
        $this->actingAs(User::factory()->create())->postJson(route('admin.appearance.publish'))->assertNotFound();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }
}
