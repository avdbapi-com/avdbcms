<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Plan;
use App\Models\User;
use App\Models\Video;
use App\Support\AdminNav;
use App\Support\AdminPreferences;
use App\Support\AdminSearchIndex;
use App\Support\AdminTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The redesigned admin's own document (<x-admin.shell>): every admin page renders in it with admin.ui=v2 and in
 * the old layout with v1; 8-section navigation from config/admin_nav.php; page header, tabs, search,
 * preferences; no public theme assets or effects in the admin.
 */
class AdminShellTest extends TestCase
{
    use RefreshDatabase;

    /** GET routes that are downloads, JSON, redirects or actions rather than pages. */
    private const NOT_PAGES = [
        'admin.horizon', 'admin.impersonate', 'admin.impersonate.leave', 'admin.logs', 'admin.preflight.json',
        'admin.avdb-import.stats', 'admin.xvid-import.stats', 'admin.cron.duration.status', 'admin.users.export',
        'admin.shell.search', 'admin.shell.search-index', 'admin.search.users', 'admin.search.videos',
        'admin.avdb-import.runs.clean-finished.help', 'admin.avdb-import.progress', 'admin.xvid-import.progress',
        'admin.avdb-import.issues.export', 'admin.backups.download', 'admin.exports.download', 'admin.logs.download',
        'admin.languages.export',
        // WP7's settings page script and the lazy code-box values (R/r2/notes/wp7.md).
        'admin.settings.script', 'admin.settings.values',
        // Tools > Updates' JSON for its script (F8).
        'admin.updates.status',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // These tests are about the new admin, whatever ADMIN_UI the test environment sets.
        config(['admin.ui' => 'v2']);
    }

    public function test_every_admin_page_renders_inside_the_new_shell(): void
    {
        $admin = $this->developer();

        foreach ($this->pageUrls($admin) as $name => $url) {
            $response = $this->actingAs($admin)->get($url);

            $this->assertSame(200, $response->getStatusCode(), "{$name} ({$url})");
            $html = $response->getContent();
            $this->assertStringContainsString('class="ad-shell"', $html, "{$name} renders inside the shell");
            $this->assertStringContainsString('id="ad-main"', $html, $name);
            $this->assertSame(1, substr_count($html, '<html'), "{$name} is one document");
            $this->assertStringNotContainsString('avd-back-sidebar', $html, "{$name} has no old sidebar");
        }
    }

    public function test_every_admin_page_still_renders_in_the_old_layout_with_v1(): void
    {
        config(['admin.ui' => 'v1']);
        $admin = $this->developer();

        foreach ($this->pageUrls($admin) as $name => $url) {
            $response = $this->actingAs($admin)->get($url);

            // Pages that exist only in the new admin (WP7's Money pages) send an admin on the old admin to the old
            // settings tab that holds the same settings.
            if (Str::startsWith($name, 'admin.money.')) {
                $response->assertRedirect();
                $this->assertStringContainsString('/admin/settings?tab=', (string) $response->headers->get('Location'), $name);

                continue;
            }

            // Tools > Updates has no old-admin page (F8 §2): it renders in the new admin's document for everybody.
            if ($name === 'admin.updates.index') {
                $this->assertSame(200, $response->getStatusCode(), $name);
                $this->assertStringContainsString('class="ad-shell"', $response->getContent(), $name);

                continue;
            }

            $this->assertSame(200, $response->getStatusCode(), "{$name} ({$url})");
            $this->assertStringContainsString('avd-back-sidebar', $response->getContent(), "{$name} uses the old layout");
            $this->assertStringNotContainsString('class="ad-shell"', $response->getContent(), $name);
        }
    }

    public function test_an_admin_can_switch_to_the_old_admin_and_back(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('admin.videos.index'))
            ->post(route('admin.shell.preferences'), ['key' => 'admin_ui', 'value' => 'v1'])
            ->assertRedirect(route('admin.videos.index'));

        $this->actingAs($admin->fresh())
            ->get(route('admin.videos.index'))
            ->assertOk()
            ->assertSee('avd-back-sidebar', false)
            ->assertSee('Use the new admin');

        $this->actingAs($admin->fresh())
            ->post(route('admin.shell.preferences'), ['key' => 'admin_ui', 'value' => 'v2']);

        $this->actingAs($admin->fresh())
            ->get(route('admin.videos.index'))
            ->assertOk()
            ->assertSee('class="ad-shell"', false);
    }

    public function test_the_sidebar_has_the_eight_sections_in_order(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())->get(route('admin.index'))->assertOk()->getContent();

        preg_match_all('/<span class="ad-label-t">([^<]+)<\/span>/', $html, $m);
        $this->assertSame(['Home', 'Content', 'Community', 'Members', 'Money', 'Appearance', 'Settings', 'Tools'], $m[1]);
    }

    public function test_the_nav_config_places_44_everyday_10_advanced_and_3_developer_pages(): void
    {
        $levels = collect(AdminNav::pages())->filter(fn (array $page) => $page['sidebar'])->countBy('level');

        // 44: Tools > Updates (F8) joined the 43 of the redesign.
        $this->assertSame(44, $levels['basic']);
        $this->assertSame(10, $levels['advanced']);
        $this->assertSame(3, $levels['expert']);
        $this->assertSame(['tools.database', 'tools.developer', 'appearance.custom_code'], config('admin_nav.developer.pages'));

        // Every admin route of the spec belongs to a page, so every old page gets breadcrumbs and an active menu item.
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();
            if (Str::startsWith($name, 'admin.') && ! Str::startsWith($name, ['admin.shell.', 'admin.search.']) && ! Str::startsWith($route->uri(), 'api/')) {
                $this->assertArrayHasKey($name, config('admin_nav.routes'), "{$name} has a page in config/admin_nav.php");
            }
        }
    }

    public function test_advanced_pages_wait_behind_n_more_and_the_preference_lists_them(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('admin.videos.index'))->assertOk()->getContent();
        // The shell's own links are paths on this site (the sidebar alone has 60+ links).
        $this->assertMatchesRegularExpression('/data-ad-more-item hidden><a href="\/admin\/media">Files &amp; thumbnails/', $html);
        $this->assertStringContainsString('1 more', $html);

        AdminPreferences::set($admin, 'show_advanced', true);
        $html = $this->actingAs($admin->fresh())->get(route('admin.videos.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-ad-more-item', $html);
        $this->assertMatchesRegularExpression('/<li><a href="\/admin\/media">Files &amp; thumbnails/', $html);
    }

    public function test_the_page_header_comes_from_the_navigation(): void
    {
        $html = $this->actingAs($this->developer())->get(route('admin.failed-jobs.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<h1>Background jobs</h1>', $html);
        $this->assertMatchesRegularExpression('/<nav class="ad-crumbs"[^>]*>.*Tools.*Background jobs/s', $html);
        // The old pages that became tabs keep their addresses.
        $this->assertStringContainsString('href="'.route('admin.queue.index', [], false).'"', $html);
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('admin.failed-jobs.index', [], false), '/').'" class="is-active" aria-current="page"[^>]*>Failed</', $html);
        $this->assertStringContainsString('href="'.route('admin.cron.index', [], false).'"', $html);
    }

    public function test_the_admin_loads_no_public_theme_assets_or_effects(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())->get(route('admin.index'))->assertOk()->getContent();

        foreach (['layout-theme-a', 'layout-theme-b', 'fonts.googleapis', 'fonts.gstatic', 'data-avd-tier', 'data-avd-deco', 'resources/js/app.ts', 'avd-theme', 'backdrop-filter', 'shortcuts-overlay', 'id="video_create"'] as $public) {
            $this->assertStringNotContainsString($public, $html, "no {$public} in the admin");
        }

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        $this->assertStringContainsString('class="ad-sprite"', $html);
    }

    public function test_the_admin_stylesheets_have_no_blur_glow_or_endless_animation(): void
    {
        $sources = '';

        foreach (glob(resource_path('admin/components/*.scss')) as $file) {
            $sources .= file_get_contents($file);
        }

        $sources .= file_get_contents(resource_path('admin/admin.scss'));
        // Rules only, not the comments that say what is not allowed.
        $sources = preg_replace(['#/\*.*?\*/#s', '#//[^
]*#'], '', $sources);

        // backdrop-filter only as "none" (switching the old views' blur off), never a blur of the admin's own.
        $this->assertDoesNotMatchRegularExpression('/backdrop-filter:(?!\s*none\b)/', $sources);
        $this->assertStringNotContainsString('blur(', $sources);
        $this->assertStringNotContainsStringIgnoringCase('glow:', $sources);
        $this->assertStringNotContainsString('infinite', $sources);
    }

    public function test_font_awesome_subset_has_every_icon_the_old_views_use(): void
    {
        $subset = file_get_contents(resource_path('admin/lib/_fa.scss'));
        $catalogue = (string) file_get_contents(base_path('app/Support/FontAwesomeIconRegistry.php'));
        $missing = [];

        foreach (glob(resource_path('views/admin/{,*/,*/*/}*.blade.php'), GLOB_BRACE) as $file) {
            preg_match_all('/\bfa-(solid|regular|brands)?\s*fa-([a-z0-9-]+)|\bfa-([a-z0-9][a-z0-9-]*[a-z0-9])\b/', (string) file_get_contents($file), $m, PREG_SET_ORDER);

            foreach ($m as $match) {
                $name = $match[2] ?? '' ?: ($match[3] ?? '');

                // only real solid icon names (skip fa-solid, fa-2x, fa-spin and dynamic fa-{{ ... }} parts)
                if ($name === '' || ! preg_match('/[|\']'.preg_quote($name, '/').'[|\']/', $catalogue)) {
                    continue;
                }

                if (! str_contains($subset, '.fa-'.$name.' ') && ! str_contains($subset, '.fa-'.$name.',')) {
                    $missing[$name] = str_replace(resource_path('views/'), '', $file);
                }
            }
        }

        $this->assertSame([], $missing, 'Run gen_fa_subset.py after adding Font Awesome icons to an admin view.');
    }

    public function test_search_index_lists_pages_old_names_settings_and_synonyms(): void
    {
        $admin = User::factory()->admin()->create();

        $index = $this->actingAs($admin)->getJson(route('admin.shell.search-index'))->assertOk()->json();

        $pages = collect($index['pages'])->keyBy('id');
        $this->assertTrue($pages->has('tools.health'));
        $this->assertContains('Preflight', $pages['tools.health']['k']);
        $this->assertContains('Mass Broadcast', $pages['community.notify']['k']);
        $this->assertFalse($pages->has('tools.developer'), 'developer pages only in Developer mode');
        $this->assertTrue(collect($index['settings'])->contains(fn (array $row) => $row['k'] === 'smtp_host'));
        $this->assertArrayHasKey('smtp', $index['synonyms']);

        AdminPreferences::set($admin, 'developer_mode', true);
        $developer = $this->actingAs($admin->fresh())->getJson(route('admin.shell.search-index'))->json();
        $this->assertTrue(collect($developer['pages'])->contains(fn (array $page) => $page['id'] === 'tools.developer'));
        $this->assertNotSame($index['v'], $developer['v']);
    }

    public function test_search_finds_members_and_videos_as_the_admin_types(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['username' => 'emma_travels']);
        Video::factory()->for($member)->create(['title' => 'Emma in the northern lights']);

        $this->actingAs($admin)
            ->getJson(route('admin.shell.search', ['q' => 'emma']))
            ->assertOk()
            ->assertJsonPath('members.0.t', 'emma_travels')
            ->assertJsonPath('videos.0.t', 'Emma in the northern lights');

        $this->actingAs($admin)->getJson(route('admin.shell.search', ['q' => 'e']))->assertExactJson(['members' => [], 'videos' => []]);

        $this->app['auth']->forgetGuards();
        $this->getJson(route('admin.shell.search', ['q' => 'emma']))->assertNotFound();
    }

    public function test_preferences_are_validated_and_saved_per_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson(route('admin.shell.preferences'), ['key' => 'admin_theme', 'value' => 'dark'])
            ->assertOk()
            ->assertJsonPath('preferences.admin_theme', 'dark');

        $this->actingAs($admin)->postJson(route('admin.shell.preferences'), ['key' => 'admin_theme', 'value' => 'purple'])->assertUnprocessable();
        $this->actingAs($admin)->postJson(route('admin.shell.preferences'), ['key' => 'is_admin', 'value' => '1'])->assertUnprocessable();

        $this->actingAs($admin->fresh())
            ->get(route('admin.index'))
            ->assertSee('data-theme="dark"', false)
            ->assertSee('data-bs-theme="dark"', false);

        $this->assertSame('system', AdminPreferences::get(User::factory()->admin()->create(), 'admin_theme'));

        $this->app['auth']->forgetGuards();
        $this->postJson(route('admin.shell.preferences'), ['key' => 'admin_theme', 'value' => 'dark'])->assertNotFound();
        $this->actingAs(User::factory()->create())->postJson(route('admin.shell.preferences'), ['key' => 'admin_theme', 'value' => 'dark'])->assertNotFound();
    }

    public function test_the_admin_works_before_the_preferences_column_exists(): void
    {
        $admin = User::factory()->admin()->create();
        Schema::table('users', fn ($table) => $table->dropColumn('admin_preferences'));
        $fresh = fn () => User::query()->find($admin->id);

        // The account menu says why its choices are switched off.
        $this->actingAs($fresh())
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('class="ad-shell"', false)
            ->assertSee('class="ad-menu-note"', false)
            ->assertSee('These choices can be saved after the update is finished.');
        $this->assertFalse(AdminPreferences::available());

        // A choice that cannot be kept says so instead of pretending it was saved.
        $this->actingAs($fresh())
            ->postJson(route('admin.shell.preferences'), ['key' => 'admin_theme', 'value' => 'dark'])
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'These choices can be saved after the update is finished. Open Home and click Finish update.');

        $this->actingAs($fresh())
            ->from(route('admin.tags.index'))
            ->post(route('admin.shell.preferences'), ['key' => 'admin_ui', 'value' => 'v1'])
            ->assertRedirect(route('admin.tags.index'))
            ->assertSessionHasErrors('value');
    }

    public function test_the_search_index_is_kept_by_the_browser_in_the_admins_language(): void
    {
        $admin = User::factory()->admin()->create();
        $version = AdminSearchIndex::version($admin);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.shell.search-index', ['v' => $version, 'l' => 'en']))
            ->assertOk()
            ->assertJsonPath('v', $version);
        $this->assertSame('max-age=86400, private', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsStringIgnoringCase('cookie', (string) $response->headers->get('Vary'));

        // An old version is answered too, but not kept.
        $stale = $this->actingAs($admin)->getJson(route('admin.shell.search-index', ['v' => 'old', 'l' => 'en']))->assertOk();
        $this->assertStringContainsString('no-cache', (string) $stale->headers->get('Cache-Control'));

        // The language comes from the URL (the route skips the Language middleware); unknown ones are ignored.
        $this->actingAs($admin)->getJson(route('admin.shell.search-index', ['l' => '../../etc']))->assertOk()->assertJsonPath('v', $version);
        $vi = $this->actingAs($admin)->getJson(route('admin.shell.search-index', ['l' => 'vi']))->assertOk()->json();
        $this->assertNotSame($version, $vi['v']);
    }

    public function test_the_videos_and_comments_lists_no_longer_need_the_public_components(): void
    {
        $admin = User::factory()->admin()->create();
        Video::factory()->for($admin)->create();

        // WP9's lists replaced the two old modals (public <interactions-area> / <user-replies>): no words, no API
        // address and no old-view scripts for them any more.
        foreach (['admin.videos.index', 'admin.comments.index'] as $route) {
            $this->actingAs($admin)
                ->get(route($route))
                ->assertOk()
                ->assertDontSee('data-el-more', false)
                ->assertDontSee('interactions-area', false)
                ->assertSee('data-ad-legacy="off"', false);
        }
    }

    public function test_the_table_bulk_bar_and_the_preview_monitor_carry_their_behaviour_hooks(): void
    {
        $table = Blade::render(<<<'BLADE'
            <x-admin.table>
                <x-slot:bulk><button class="ad-btn sm">Make public</button></x-slot:bulk>
                <x-slot:head><th class="is-check"><input type="checkbox" class="ad-check" data-ad-select-all></th><th>Video</th></x-slot:head>
                <tr><td class="is-check"><input type="checkbox" class="ad-check" data-ad-select value="7"></td><td>A video</td></tr>
            </x-admin.table>
            BLADE);

        $this->assertStringContainsString('class="ad-bulk" data-ad-bulk hidden', $table);
        $this->assertStringContainsString('data-ad-bulk-count', $table);
        $this->assertStringContainsString('data-ad-bulk-clear', $table);
        $this->assertStringContainsString('data-many=":count selected"', $table);

        $monitor = Blade::render(<<<'BLADE'
            <x-admin.monitor :pages="['/?draft=1' => 'Homepage', '/watch/x?draft=1' => 'Video page']" shot="/shot.webp" />
            BLADE);

        $this->assertStringContainsString('data-src="/?draft=1"', $monitor);
        $this->assertStringContainsString('data-reload-label="Reload preview"', $monitor);
        $this->assertMatchesRegularExpression('/name="ad_monitor_device_[a-z0-9]{6}"/', $monitor);
        $this->assertStringContainsString('data-ad-monitor-page', $monitor);
        $this->assertStringContainsString('data-ad-monitor-live', $monitor);
    }

    public function test_theme_mode_shows_one_page_light_or_dark_without_saving_it(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.index', ['theme_mode' => 'dark']))
            ->assertOk()
            ->assertSee('data-theme="dark"', false)
            ->assertSee('data-bs-theme="dark"', false);

        $this->assertSame('system', AdminPreferences::get($admin->fresh(), 'admin_theme'));
    }

    public function test_old_views_get_their_scripts_and_new_pages_do_not(): void
    {
        $admin = $this->developer();

        $this->actingAs($admin)->get(route('admin.queue.index'))->assertSee('data-ad-legacy="on"', false)->assertSee('id="admin_confirm"', false);
        $this->actingAs($admin)->get(route('admin.shell.moved'))->assertSee('data-ad-legacy="off"', false)->assertDontSee('id="admin_confirm"', false);
    }

    public function test_a_success_message_becomes_a_toast_and_errors_a_callout(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->withSession(['success' => 'Tag saved'])
            ->get(route('admin.tags.index'))
            ->assertOk()
            ->assertSeeInOrder(['id="ad-toasts"', 'Tag saved'], false);

        $this->actingAs($admin)
            ->withSession(['errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['name' => 'The name is taken.']))])
            ->get(route('admin.tags.index'))
            ->assertOk()
            ->assertSeeInOrder(['class="ad-callout is-danger ad-flash"', 'The name is taken.'], false);
    }

    public function test_what_moved_where_lists_the_old_menu(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.shell.moved'))
            ->assertOk()
            ->assertSee('What moved where')
            ->assertSeeInOrder(['Preflight', 'Health check'])
            ->assertSee('Mass Broadcast');
    }

    public function test_the_site_status_names_the_first_problem(): void
    {
        $admin = User::factory()->admin()->create();
        config(['site.maintenance_mode' => false, 'site.robots_noindex' => false, 'mail.default' => 'smtp']);
        $this->actingAs($admin)->get(route('admin.index'))->assertSee('Site is live');

        config(['site.maintenance_mode' => true]);
        $this->actingAs($admin)->get(route('admin.index'))->assertSee('class="ad-status is-warn"', false)->assertSee('Maintenance mode is on');
    }

    public function test_the_layout_is_readable_right_to_left_and_by_page_id(): void
    {
        app()->setLocale('ar');
        $this->assertSame('rtl', AdminTheme::direction());
        app()->setLocale('en');
        $this->assertSame('ltr', AdminTheme::direction());

        $this->assertSame('settings.email', AdminNav::page('settings.email')['id']);
        $this->assertSame('/admin/settings/email', AdminNav::page('settings.email')['url']);
        // Until the new Settings pages exist (WP7), the menu opens the old settings tab instead of a missing page.
        $this->assertStringEndsWith(Route::has('admin.settings.page') ? '/admin/settings/email' : '/admin/settings?tab=email', (string) AdminNav::url(AdminNav::page('settings.email')));
    }

    private function developer(): User
    {
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);

        return $admin->fresh();
    }

    /** @return array<string, string> route name => URL of every admin page */
    private function pageUrls(User $admin): array
    {
        $member = User::factory()->create();
        $video = Video::factory()->for($member)->create();
        $category = Category::query()->create(['title' => 'Test category', 'slug' => 'test-category']);
        $plan = Plan::factory()->create();
        $contact = DB::table('contact_messages')->insertGetId(['name' => 'Ann', 'email' => 'ann@example.test', 'subject' => 'Hi', 'message' => 'Hello', 'is_read' => false, 'created_at' => now(), 'updated_at' => now()]);
        $language = Language::query()->where('code', 'en')->firstOrFail();

        $withParams = [
            'admin.users.edit' => ['user' => $member->id],
            'admin.users.show' => ['user' => $member->id],
            'admin.videos.edit' => ['video' => $video->id],
            'admin.categories.edit' => ['category' => $category->id],
            'admin.plans.edit' => ['plan' => $plan->id],
            'admin.contacts.show' => ['id' => $contact],
            'admin.languages.edit' => ['locale' => 'en'],
            'admin.languages.edit-meta' => ['language' => $language->id],
        ];

        $urls = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! Str::startsWith($name, 'admin.') || Str::startsWith($route->uri(), 'api/') || ! in_array('GET', $route->methods(), true) || in_array($name, self::NOT_PAGES, true)) {
                continue;
            }

            if (isset($withParams[$name])) {
                $urls[$name] = route($name, $withParams[$name]);
            } elseif (! str_contains($route->uri(), '{')) {
                $urls[$name] = route($name);
            }
        }

        $this->assertGreaterThanOrEqual(60, count($urls));

        return $urls;
    }
}
