<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AppearanceSettings;
use App\Support\OneTimePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.2.3: the site's layout chrome (resources/views/components/layout.blade.php and its menus).
 * Member design 4.2, 4.4, 6.20;
 * QA ids auth-3 / channel-1 / watch-1 (Profile tab), channel-29 / watch-10 (Library tab), watch-30 (pinned menu
 * announced as a dialog), perf-9 (cloaked header), watch-8 / account-20 (empty search and bell), watch-36 (spotlight),
 * channel-36 (unnamed logo link), watch-39 (unlabelled theme switch).
 */
class FrontChromeLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_phone_bottom_bar_opens_the_members_channel_by_its_address_and_the_library(): void
    {
        $this->setAppearance(['mobile_bottom_nav_enabled' => '1']);
        $user = User::factory()->create(['username' => 'qa_member_name']);
        $html = $this->actingAs($user)->get(route('pages.home'))->assertOk()->getContent();
        $nav = $this->between($html, 'id="avdMobileBottomNav"', '</nav>');

        // Channels are found by their slug (or profile id): /@<username> was a 404 whenever the two differ.
        $this->assertStringContainsString('href="'.locale_url(route('user.show', $user)).'"', $nav);
        $this->assertStringNotContainsString('/@qa_member_name"', $nav);
        // The Library tab opens the library, not "Manage subscriptions".
        $this->assertStringContainsString('href="'.locale_url(route('pages.library')).'"', $nav);
        $this->assertStringNotContainsString(route('subscription.manage'), $nav);
    }

    public function test_the_studio_tab_bar_is_marked_on_the_body_of_member_back_office_pages_only(): void
    {
        $user = User::factory()->create();

        $this->setAppearance(['mobile_bottom_nav_enabled' => '1']);
        $this->assertTrue($this->bodyHasClass($this->actingAs($user)->get(route('user.index'))->assertOk()->getContent(), 'has-studio-tabbar'));
        $this->assertFalse($this->bodyHasClass($this->actingAs($user)->get(route('pages.home'))->assertOk()->getContent(), 'has-studio-tabbar'));

        $this->setAppearance(['mobile_bottom_nav_enabled' => '0']);
        $studio = $this->actingAs($user)->get(route('user.index'))->assertOk()->getContent();
        $this->assertFalse($this->bodyHasClass($studio, 'has-studio-tabbar'));

        // The studio pages are marked for their exact header height.
        $this->assertMatchesRegularExpression('/<html[^>]*\sdata-avd-member-back-office[\s>]/', $studio);
        $this->assertDoesNotMatchRegularExpression('/<html[^>]*\sdata-avd-member-back-office[\s>]/', $this->get(route('pages.home'))->assertOk()->getContent());
    }

    public function test_back_to_top_reads_its_label_from_a_translated_attribute_and_the_slim_footer_leaves_it_room(): void
    {
        $this->setAppearance(['back_to_top_enabled' => '1']);
        $user = User::factory()->create();

        $public = $this->get(route('pages.home'))->assertOk()->getContent();
        // The label is the button's own name; "Top" is the ranking word of other screens ("summit",
        // "leading" in many languages).
        $this->assertMatchesRegularExpression('/<[a-z]+[^>]*class="[^"]*\bback-to-top\b[^"]*"[^>]*data-label="Back to top"/', $public);
        $this->assertStringNotContainsString('data-label="Top"', $public);
        foreach (['ar', 'de', 'ru', 'tr', 'pt', 'vi', 'th'] as $locale) {
            $this->assertNotSame('Back to top', __('Back to top', [], $locale), $locale.' has the label translated');
        }
        $this->assertStringNotContainsString('avd-footer--slim', $public);

        $studio = $this->actingAs($user)->get(route('user.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<footer[^>]*class="[^"]*\bavd-footer--slim\b[^"]*\bavd-footer--btt-room\b/', $studio);

        // The pill's word comes from the attribute (17 languages), not from a fixed "Top" in the style sheet.
        $css = (string) file_get_contents(resource_path('css/layout-theme-a.css'));
        $this->assertMatchesRegularExpression('/\.back-to-top::after\s*\{[^}]*content:\s*attr\(data-label\)/', $css);
    }

    public function test_a_flash_toast_comes_in_the_kit_stack_and_marks_the_html_element(): void
    {
        $html = $this->withSession(['toast' => ['message' => 'Saved <b>now</b>', 'type' => 'success']])
            ->get(route('pages.home'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<html[^>]*\sdata-mk-toast[\s>]/', $html);
        $stack = $this->between($html, 'id="avd-toasts"', '</div>');
        $this->assertStringContainsString('Saved &lt;b&gt;now&lt;/b&gt;', $html);
        $this->assertNotSame('', $stack);
        // The old fixed bottom-end toasts are gone.
        $this->assertStringNotContainsString('id="copy-toast"', $html);

        $this->flushSession();
        $plain = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/<html[^>]*\sdata-mk-toast[\s>]/', $plain);
    }

    /**
     * A page that shows a flashed message is marked as that visitor's page (App\Support\OneTimePage), so
     * the edge cache can keep it private: the flash data is gone from the session when the outer middleware looks at
     * the response (EdgeCacheVideoHtml).
     */
    public function test_a_page_that_shows_a_flashed_message_is_marked_as_one_visitors_page(): void
    {
        // As a redirect leaves the session: the message and Laravel's list of what was flashed for this request.
        $html = $this->withSession(['toast' => 'Your account is closed.', '_flash' => ['old' => ['toast'], 'new' => []]])
            ->get(route('pages.home'))->assertOk()->getContent();
        $this->assertStringContainsString('Your account is closed.', $html);
        $this->assertTrue(OneTimePage::isMarked(app('request')));

        // The next view shows nothing and is not marked.
        $html = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Your account is closed.', $html);
        $this->assertFalse(OneTimePage::isMarked(app('request')));

        // Any flashed message counts (a status line, form errors), and a toast put in the session without a flash too.
        $this->withSession(['status' => 'Saved.', '_flash' => ['old' => ['status'], 'new' => []]])->get(route('pages.home'))->assertOk();
        $this->assertTrue(OneTimePage::isMarked(app('request')));
        $this->flushSession();
        $this->withSession(['toast' => 'Saved.'])->get(route('pages.home'))->assertOk();
        $this->assertTrue(OneTimePage::isMarked(app('request')));
    }

    /**
     * Member design 4.3 (layout budget): the layout's inline scripts carry no explanatory comments (they are
     * Blade comments now, kept in the source) and the click ripple engine is part of the cached module bundle
     * (resources/js/layout/ripple.ts) instead of 9 KB of inline script on every page.
     */
    public function test_the_layout_ships_its_inline_scripts_without_comments_and_no_inline_ripple(): void
    {
        $html = $this->get(route('pages.home'))->assertOk()->getContent();

        foreach (['Capability tier (perf v2)', 'Perf plan step 21', 'Perf plan step 25b', 'Theater mode memory', 'Clean up any stale legacy localStorage', 'The one click ripple engine', 'function spawnRipple('] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }

        $layout = (string) file_get_contents(resource_path('views/components/layout.blade.php'));
        $this->assertStringContainsString('{{-- Capability tier (perf v2)', $layout, 'the explanation stays in the source');
        $this->assertStringNotContainsString('function spawnRipple(', $layout);
        $core = (string) file_get_contents(resource_path('js/layout/core.ts'));
        $this->assertStringContainsString("import {installRipple} from './ripple';", $core);
        $this->assertStringContainsString('safely(installRipple);', $core);
        $this->assertStringContainsString('const spawnRipple = (target: HTMLElement', (string) file_get_contents(resource_path('js/layout/ripple.ts')));

        // the guest layout around the home page's content, in this test's rendering (no build tags, factory data)
        $main = strpos($html, 'id="main-container"');
        $this->assertNotFalse($main);
        preg_match_all('#<script(?![^>]*\bsrc=)(?![^>]*json)[^>]*>(.*?)</script>#s', $html, $scripts);
        $this->assertLessThan(36 * 1024, array_sum(array_map('strlen', $scripts[1])), 'inline script bytes of the guest home page');
    }

    /**
     * perf-7: the main CSS carries only the Font Awesome icons the site's code names. The icons owners choose
     * (a category's, a side-menu link's, a premium feature box's) that the subset lacks get their rules in the layout,
     * so they never show blank; icons the subset has get none; nothing typed reaches the stylesheet but a known name.
     */
    public function test_owner_chosen_icons_outside_the_subset_get_their_rules_in_the_layout(): void
    {
        $this->assertTrue(\App\Support\IconSubset::inSubset('house'));
        $this->assertFalse(\App\Support\IconSubset::inSubset('dragon'));

        $html = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="avd-icon-extra"', $html, 'only subset icons: no extra rules');

        \App\Models\Category::query()->create(['title' => 'Dragons', 'slug' => 'dragons', 'icon' => 'dragon', 'in_menu' => true]);
        \App\Models\Category::query()->create(['title' => 'Homes', 'slug' => 'homes', 'icon' => 'house', 'in_menu' => true]);
        \Illuminate\Support\Facades\Cache::forget(\App\Support\IconSubset::CATEGORY_CACHE_KEY);
        config(['site.sidebar_primary_links' => "Fish|fish|/fish\nHome|house|/\nBad|x}body{color:red|/bad"]);

        $html = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<style id="avd-icon-extra">(.*?)</style>#s', $html, $extra));
        $this->assertStringContainsString('.fa-dragon{--fa:"\f6d5"}', $extra[1]);
        $this->assertStringContainsString('.fa-fish{--fa:"\f578"}', $extra[1]);
        $this->assertStringNotContainsString('.fa-house', $extra[1], 'the subset has it');
        $this->assertStringNotContainsString('body', $extra[1]);
        $this->assertMatchesRegularExpression('/^(\.fa-[a-z0-9-]+\{--fa:"\\\\[0-9a-f]{2,6}"\})+$/', $extra[1]);
    }

    /**
     * perf-7 (the full suite): the menu categories' icons are read through the side menu's own cache, so the
     * layout adds no query of its own (HomeTrendingCollectionsTest counts them); a category outside the menu prints its
     * icon on its own page, which registers it for the layout's head.
     */
    public function test_a_category_outside_the_menu_gets_its_icon_rule_on_its_page_without_an_extra_query(): void
    {
        $dragons = \App\Models\Category::query()->create(['title' => 'Dragons', 'slug' => 'dragons', 'icon' => 'dragon', 'in_menu' => false]);
        \App\Models\Category::query()->create(['title' => 'Otters', 'slug' => 'otters', 'icon' => 'otter', 'in_menu' => true]);
        $this->assertFalse(\App\Support\IconSubset::inSubset('dragon'));
        $this->assertFalse(\App\Support\IconSubset::inSubset('otter'));

        $page = $this->get(route('pages.category', $dragons->slug))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<style id="avd-icon-extra">(.*?)</style>#s', $page, $extra));
        $this->assertStringContainsString('.fa-dragon{--fa:"\f6d5"}', $extra[1], 'the page\'s own category icon');
        $this->assertStringContainsString('.fa-otter{', $extra[1], 'the menu categories\' icons');

        // the home page lists the menu categories, not this one
        $home = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<style id="avd-icon-extra">(.*?)</style>#s', $home, $homeExtra));
        $this->assertStringContainsString('.fa-otter{', $homeExtra[1]);
        $this->assertStringNotContainsString('.fa-dragon', $homeExtra[1]);

        // the icons come through the side menu's cache: no category query of their own on a warm cache
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \App\Support\IconSubset::extraCss();
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();
        $this->assertSame([], $queries);
        $this->assertSame('categories', \App\Support\IconSubset::CATEGORY_CACHE_KEY);
    }

    /** perf-7: the old admin layout's pages name icons of their own and link the full Font Awesome set. */
    public function test_the_old_admin_layout_links_the_full_icon_set_and_public_pages_do_not(): void
    {
        $this->withVite();
        $admin = User::factory()->admin()->create();
        config(['admin.ui' => 'v1']);

        $adminHtml = $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-avd-icons="all"', $adminHtml);
        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="[^"]*/fa-full-[^"]+\.css" data-avd-icons="all">#', $adminHtml);

        $this->assertStringNotContainsString('data-avd-icons="all"', $this->actingAs($admin)->get(route('pages.home'))->assertOk()->getContent());
        auth()->logout();
        $this->assertStringNotContainsString('data-avd-icons="all"', $this->get(route('pages.home'))->assertOk()->getContent());
    }

    /**
     * The mark is enforced on the finished response, after every middleware. Before, the
     * layout marked the page but EdgeCacheVideoHtml (and the watch page's own controller) still sent a guest's page
     * with its one-time message as a public, CDN-cacheable copy: "Your account is closed" for every visitor for minutes.
     */
    public function test_a_page_that_shows_a_flashed_message_is_never_sent_as_a_public_page(): void
    {
        $flash = fn (string $key, mixed $value) => [$key => $value, '_flash' => ['old' => [$key], 'new' => []]];
        $assertPrivate = function ($response, string $what): void {
            $cache = (string) $response->headers->get('Cache-Control');
            $this->assertStringContainsString('no-store', $cache, $what);
            $this->assertStringContainsString('private', $cache, $what);
            $this->assertStringNotContainsString('public', $cache, $what);
            $this->assertStringNotContainsString('s-maxage', $cache, $what);
            $this->assertSame('no-store', $response->headers->get('CDN-Cache-Control'), $what);
            $this->assertSame('no-store', $response->headers->get('Cloudflare-CDN-Cache-Control'), $what);
            $this->assertFalse($response->headers->has('Expires'), $what);
        };

        // The home page with the "account closed" toast, as the redirect after the deletion leaves the session.
        $response = $this->withSession($flash('toast', ['message' => 'Your account is closed.', 'type' => 'success']))->get(route('pages.home'));
        $response->assertOk();
        $this->assertStringContainsString('Your account is closed.', (string) $response->getContent());
        $assertPrivate($response, 'home with a toast');

        // The next guest view of the same address is the public page again (the mark belongs to one request).
        $this->flushSession();
        $public = $this->get(route('pages.home'))->assertOk();
        $this->assertStringContainsString('public', (string) $public->headers->get('Cache-Control'));
        $this->assertStringContainsString('s-maxage', (string) $public->headers->get('Cache-Control'));

        // A status line on the watch page, whose controller makes guest pages public itself.
        $video = \App\Models\Video::factory()->withStatus(\App\Enums\VideoStatus::PUBLIC)->forUser()->create();
        $guestWatch = $this->get($video->route)->assertOk();
        $this->assertStringContainsString('public', (string) $guestWatch->headers->get('Cache-Control'), 'the watch page is public for guests');
        $watch = $this->withSession($flash('status', 'Thanks, your report was sent.'))->get($video->route);
        $watch->assertOk();
        $assertPrivate($watch, 'watch page with a status line');

        // Form errors flashed by a failed post (ShareErrorsFromSession prints them on the next page).
        $this->flushSession();
        $search = $this->withSession($flash('errors', new \Illuminate\Support\ViewErrorBag()))->get(route('search.index', ['q' => 'night']));
        $search->assertOk();
        $assertPrivate($search, 'search with flashed errors');
    }

    /**
     * Member design 9.2, 5.13, 4.3: who downloads the member CSS entry. A guest's public page never; a
     * guest on a member route (sign-in) and every page of a signed-in visitor once (one cached, hashed file); no /admin
     * page ever, the signed-in admin's included.
     */
    public function test_the_member_css_loads_for_signed_in_visitors_and_member_routes_only(): void
    {
        // The real build's tags (the test case turns Vite off by default).
        $this->withVite();
        $this->assertFileExists(public_path('build/manifest.json'));
        $this->assertStringContainsString('"resources/sass/member.scss"', (string) file_get_contents(public_path('build/manifest.json')));

        $video = \App\Models\Video::factory()->withStatus(\App\Enums\VideoStatus::PUBLIC)->forUser()->create();
        $guestPages = [route('pages.home'), route('pages.trend'), route('search.index', ['q' => 'night']), $video->route];

        foreach ($guestPages as $url) {
            $this->assertSame(0, $this->memberStylesheets($this->get($url)->assertOk()->getContent()), 'guest '.$url);
        }

        $this->assertSame(1, $this->memberStylesheets($this->get(route('login'))->assertOk()->getContent()), 'guest sign-in page');

        $member = User::factory()->create();
        foreach ([route('pages.home'), $video->route, route('user.index')] as $url) {
            $this->assertSame(1, $this->memberStylesheets($this->actingAs($member)->get($url)->assertOk()->getContent()), 'member '.$url);
        }

        $admin = User::factory()->admin()->create();
        \App\Support\AdminPreferences::set($admin, 'developer_mode', true);
        $this->assertSame(1, $this->memberStylesheets($this->actingAs($admin)->get(route('pages.home'))->assertOk()->getContent()), 'the admin on the site');

        foreach (['v2', 'v1'] as $ui) {
            config(['admin.ui' => $ui]);
            $pages = $ui === 'v2' ? array_column(AdminPageSmokeTest::adminPages(), 0) : ['admin.index', 'admin.settings.index', 'admin.users.index', 'admin.videos.index', 'admin.comments.index'];
            foreach ($pages as $name) {
                if (! \Illuminate\Support\Facades\Route::has($name)) {
                    continue;
                }
                $response = $this->actingAs($admin)->get(route($name));
                if ($response->getStatusCode() !== 200) {
                    continue; // a page that answers otherwise (a redirect) prints no page to check
                }
                $this->assertSame(0, $this->memberStylesheets((string) $response->getContent()), "admin {$ui} {$name}");
            }
        }
    }

    public function test_back_office_pages_of_two_row_and_crest_headers_get_the_one_row_bar_height(): void
    {
        $user = User::factory()->create();

        // Magazine (masthead) and Imperial Salon (crest) count two rows or the crest; Daylight's slim bar does not.
        foreach (['broadsheet' => true, 'obsidian_gold' => true, 'daylight_paper' => false] as $theme => $expected) {
            $this->setAppearance(['theme_preset' => $theme, 'theme_flavor' => 'signature']);
            $html = $this->actingAs($user)->get(route('user.index'))->assertOk()->getContent();
            $printed = str_contains($html, '<style id="avd-back-office-header">html[data-bs-theme][data-avd-theme]{--avd-header-h:64px}</style>');

            $this->assertSame($expected, $printed, $theme);
        }

        // Public pages keep the look's own header height.
        $this->setAppearance(['theme_preset' => 'broadsheet', 'theme_flavor' => 'signature']);
        $this->assertStringNotContainsString('id="avd-back-office-header"', $this->get(route('pages.home'))->assertOk()->getContent());
    }

    public function test_the_header_paints_without_alpine_and_keeps_named_controls(): void
    {
        $html = $this->get(route('pages.home'))->assertOk()->getContent();

        // perf-9: the header row is never cloaked until Alpine starts.
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*\bavd-header-inner\b[^"]*"[^>]*\sx-cloak/', $html);
        // watch-8: the search field is a real form inside <search-bar> before its script loads.
        $this->assertMatchesRegularExpression('/<search-bar[^>]*>\s*(<!--.*?-->\s*)?<form[^>]*method="GET"/s', $html);
        // channel-36: the logo link has a name; watch-39: the theme switch is labelled, and no duplicate ids.
        $this->assertMatchesRegularExpression('/<a[^>]*class="[^"]*avd-brand[^"]*"[^>]*aria-label="[^"]+"|<a[^>]*aria-label="[^"]+"[^>]*class="[^"]*avd-brand/', $html);
        $this->assertMatchesRegularExpression('/<theme-switch[^>]*\blabelled\b/', $html);
        $this->assertSame(0, substr_count($html, 'id="sun"'));
    }

    public function test_the_pinned_front_menu_is_not_announced_as_a_modal_dialog(): void
    {
        $this->setAppearance(['front_sidebar_pinned_enabled' => '1']);
        $html = $this->get(route('pages.home'))->assertOk()->getContent();
        $menu = $this->between($html, 'id="responsive-sidebar"', '>');
        $this->assertStringContainsString('show_sidebar', $this->between($html, '<div class="offcanvas offcanvas-start', '>'));

        $this->assertStringNotContainsString('aria-modal="true"', $menu);
        $this->assertStringNotContainsString('role="dialog"', $menu);
    }

    public function test_the_spotlight_search_speaks_to_visitors_not_about_the_product(): void
    {
        $html = $this->get(route('pages.home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('AVDBCMS Spotlight', $html);
        $this->assertStringNotContainsString('Home Billboard &amp; Trending Shelves', $html);
        $this->assertStringNotContainsString('Open Daily Aesthetic Identity HUD', $html);
    }

    public function test_checkout_pages_print_no_ad_slot(): void
    {
        config([
            'site.ads_enabled' => true,
            'site.ads_hide_for_premium' => false,
            'site.ad_code_header' => '<div>header test ad</div>',
            'site.ad_code_mobile' => '<div>mobile test ad</div>',
        ]);

        $this->get(route('pages.premium'))->assertOk()
            ->assertDontSee('id="ad-slot-header"', false)
            ->assertDontSee('id="ad-slot-mobile"', false);
        $this->get(route('contact.show'))->assertOk()->assertSee('id="ad-slot-header"', false);
    }

    /** studio-20, account-36: the member studio and account pages request the look's font families only (they asked
     * for all 15 families of the admin's typography previews). */
    public function test_member_back_office_pages_load_the_looks_fonts_only(): void
    {
        $user = User::factory()->create();
        $html = $this->actingAs($user)->get(route('user.index'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<link href="(https:\/\/fonts\.googleapis\.com\/[^"]+)" rel="stylesheet" media="print"/', $html, $m), 'fonts link');
        $this->assertLessThanOrEqual(4, substr_count(html_entity_decode($m[1]), 'family='));
    }

    /** The member CSS entry's stylesheet links of a page (resources/sass/member.scss: build/assets/member-<hash>.css). */
    private function memberStylesheets(string $html): int
    {
        preg_match_all('/<link\b[^>]*>/i', $html, $links);

        return count(array_filter($links[0], fn (string $link): bool => preg_match('/\brel="stylesheet"/i', $link) === 1
            && preg_match('#href="[^"]*/build/assets/member-[^"/]+\.css"#i', $link) === 1));
    }

    private function bodyHasClass(string $html, string $class): bool
    {
        $this->assertSame(1, preg_match('/<body\b[^>]*class="([^"]*)"/', $html, $m), 'body class');

        return in_array($class, preg_split('/\s+/', $m[1]), true);
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, 'missing '.$start);
        $to = strpos($html, $end, $from);

        return substr($html, $from, ($to === false ? strlen($html) : $to) - $from);
    }

    private function setAppearance(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::set($key, $value, 'appearance');
        }

        if (method_exists(AppearanceSettings::class, 'applyToConfig')) {
            AppearanceSettings::applyToConfig(Setting::getGroup('appearance'));
        }

        foreach ($values as $key => $value) {
            config(['site.'.$key => $value]);
        }
    }
}
