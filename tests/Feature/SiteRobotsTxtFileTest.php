<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AdminPreferences;
use App\Support\Installer;
use App\Support\InstallRun;
use App\Support\RobotsTxt;
use App\Support\Updater\Manifest;
use App\Support\Updater\SwapPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Round 4 fix pass, R3M-02 (already in 1.2.2): every web server serves the static public/robots.txt at /robots.txt before
 * the site's own route (SeoController::robots(): Custom robots.txt of Settings > Google & sharing, "Hide the whole site
 * from Google", the sitemap's whole address), and the shipped file said "Sitemap: /sitemap.xml" (a relative address,
 * invalid in the protocol): those SEO settings never reached crawlers. The file cannot simply go (nginx-public-root.conf's
 * "location = /robots.txt" serves the file only: a 404).
 *
 * Now the site writes the file itself (App\Support\RobotsTxt, the one function that makes the text; the route answers the
 * same): when a save changes Custom robots.txt, Sitemap or "Hide the whole site from Google" (the settings page and the
 * old one; second pass: an edit of another SEO setting leaves the file alone, R4SEO-V1-01), when an administrator opens
 * Settings > Google & sharing (a site updated from 1.2.2 has nothing to save, R4SEO-V1-02) and at the end of an install,
 * atomically, with the sitemap's whole address in the site's own form (/index.php where it has no rewrite rules; a site
 * in a folder: its folder, never the domain's root robots.txt); the project folder's copy of it (made by the project
 * folder's index.php for servers whose web root is the project folder) too; the shipped file has no relative Sitemap
 * line; a file that cannot be written never stops the save (the administrator reads what to do); the one-click update
 * keeps the file the site wrote (Updater\SwapPlanner); a robots.txt the owner wrote by hand is never lost (kept next to
 * it as robots.txt.owner-<date>-<time> before a robots setting's change replaces it; left as it is otherwise). Third
 * pass (R4SEO-V2-01): the site remembers the texts it wrote (RobotsTxt::RECORD), so a file holding one of them is its own
 * whatever the settings say now: after a write that failed, opening the page or the next save replaces it.
 */
class SiteRobotsTxtFileTest extends TestCase
{
    use RefreshDatabase;

    /** public/robots.txt as 1.2.0 to 1.2.2 (and 1.2.3 before this fix) shipped it. */
    private const SHIPPED_122 = "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /api/\nDisallow: /login\nDisallow: /register\nDisallow: /update-locale\nDisallow: /search\nDisallow: /*/search\nDisallow: /*/update-locale\nCrawl-delay: 2\nSitemap: /sitemap.xml\n";

    private const RULES = "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /api/\nDisallow: /login\nDisallow: /register\nDisallow: /update-locale\nDisallow: /search\nDisallow: /*/search\nDisallow: /*/update-locale\nCrawl-delay: 2\n";

    /** A robots.txt the owner wrote by hand (1.2.2 kept it through the update: Updater\SwapPlanner::HAND_EDITED). */
    private const OWNERS = "User-agent: *\nDisallow: /private-area/\nSitemap: https://example.test/sitemap.xml\n";

    /** A scratch "site": <dir>/site is the project folder, <dir>/site/public its public folder, <dir> the domain's web root. */
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->dir = storage_path('framework/testing/robots-'.Str::lower(Str::random(10)));
        File::ensureDirectoryExists($this->dir.'/site/public');
        file_put_contents($this->public(), self::SHIPPED_122);
        $this->app->instance(RobotsTxt::FOLDERS, ['public' => $this->dir.'/site/public', 'project' => $this->dir.'/site']);
        config(['app.url' => 'http://localhost', 'app.clean_urls' => true, 'site.robots_txt_custom' => '', 'site.robots_noindex' => false, 'site.sitemap_enabled' => true]);
        URL::forceRootUrl('http://localhost');
        unset($_SERVER['AVDBCLIP_USED_INDEX_PHP']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        URL::forceRootUrl(null);
        unset($_SERVER['AVDBCLIP_USED_INDEX_PHP']);

        parent::tearDown();
    }

    public function test_the_shipped_file_has_no_relative_sitemap_line_and_is_the_default_text(): void
    {
        $shipped = (string) file_get_contents(base_path('public/robots.txt'));

        $this->assertDoesNotMatchRegularExpression('#^\s*Sitemap:\s*(?!https?://)#mi', $shipped, 'no relative Sitemap address');
        // the default rules; the sitemap's address is the site's own, written by the site
        $this->assertSame(self::RULES, $shipped);
        config(['site.sitemap_enabled' => false]);
        $this->assertSame($shipped, RobotsTxt::text(), 'the shipped file is the text the site writes, without the sitemap');
    }

    public function test_saving_the_seo_page_writes_the_file_crawlers_read(): void
    {
        $admin = User::factory()->admin()->create();

        // "Hide the whole site from Google": everything closed, and no address of a sitemap that answers 404 then
        $this->save($admin, ['robots_noindex' => '1'])->assertOk()->assertJsonPath('warnings', []);
        $this->assertSame("User-agent: *\nDisallow: /\n", $this->file());
        $this->assertSame($this->file(), $this->route(), 'the route answers the same text');

        // back on: the rules and the sitemap's whole address
        $this->save($admin, ['robots_noindex' => '0'])->assertOk();
        $this->assertSame(self::RULES."Sitemap: http://localhost/sitemap.xml\n", $this->file());
        $this->assertSame($this->file(), $this->route());

        // the sitemap off: no Sitemap line
        $this->save($admin, ['sitemap_enabled' => '0'])->assertOk();
        $this->assertSame(self::RULES, $this->file());

        // the owner's own robots.txt (an expert setting: Developer mode)
        AdminPreferences::set($admin, 'developer_mode', true);
        $this->save($admin, ['robots_txt' => "User-agent: *\nDisallow: /private/"])->assertOk()->assertJsonPath('warnings', []);
        $this->assertSame("User-agent: *\nDisallow: /private/", $this->file());
        $this->assertSame($this->file(), $this->route());

        // atomically: no temporary file is left next to it
        $this->assertSame(['robots.txt'], $this->names($this->dir.'/site/public'));
    }

    public function test_the_old_settings_page_writes_it_too(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.settings.update.group', 'seo'), $this->oldSeoPayload(['robots_noindex' => '1']))
            ->assertRedirect(route('admin.settings.index', ['tab' => 'seo']));
        $this->assertSame('1', Setting::get('robots_noindex'));
        $this->assertSame("User-agent: *\nDisallow: /\n", $this->file());

        $this->actingAs($admin)
            ->putJson(route('admin.settings.update.group', 'seo'), $this->oldSeoPayload(['robots_txt' => "User-agent: *\nDisallow: /tmp/"]))
            ->assertOk()
            ->assertJsonMissingPath('warnings');
        $this->assertSame("User-agent: *\nDisallow: /tmp/", $this->file());
    }

    public function test_the_sitemap_address_is_the_sites_own_form(): void
    {
        // a domain's root, with and without rewrite rules
        $this->assertSame('http://localhost/sitemap.xml', RobotsTxt::sitemapUrl());
        config(['app.clean_urls' => false]);
        $this->assertSame('http://localhost/index.php/sitemap.xml', RobotsTxt::sitemapUrl());

        // whatever form the administrator's request came in by: Apache with its rules, a page opened through /index.php
        config(['app.clean_urls' => 'auto']);
        $_SERVER['AVDBCLIP_USED_INDEX_PHP'] = true;
        $software = $_SERVER['SERVER_SOFTWARE'] ?? null;
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        try {
            $this->assertSame('http://localhost/sitemap.xml', RobotsTxt::sitemapUrl(), 'the site\'s form, not the request\'s');
        } finally {
            if ($software === null) {
                unset($_SERVER['SERVER_SOFTWARE']);
            } else {
                $_SERVER['SERVER_SOFTWARE'] = $software;
            }
            unset($_SERVER['AVDBCLIP_USED_INDEX_PHP']);
        }

        // a site in a folder of its domain: its folder, with and without rewrite rules; the address the install names
        config(['app.url' => 'https://example.test/site', 'app.clean_urls' => true]);
        URL::forceRootUrl('https://example.test/site');
        URL::forceScheme('https');
        $this->assertSame('https://example.test/site/sitemap.xml', RobotsTxt::sitemapUrl());
        config(['app.clean_urls' => false]);
        $this->assertSame('https://example.test/site/index.php/sitemap.xml', RobotsTxt::sitemapUrl());
        $this->assertSame('https://shop.example/index.php/sitemap.xml', RobotsTxt::sitemapUrl('https://shop.example/'));
    }

    public function test_a_site_in_a_folder_writes_its_own_files_only(): void
    {
        // https://example.test/site: the project folder <dir>/site in the domain's web root <dir> (what a save writes)
        config(['app.url' => 'https://example.test/site']);
        URL::forceRootUrl('https://example.test/site');
        URL::forceScheme('https');
        Setting::set('robots_noindex', '0', 'seo');

        $this->assertSame(['failed' => [], 'saved' => [], 'left' => []], RobotsTxt::refresh());
        $this->assertSame(self::RULES."Sitemap: https://example.test/site/sitemap.xml\n", $this->file());
        $this->assertFileDoesNotExist($this->dir.'/robots.txt', 'never the domain\'s robots.txt');
        $this->assertFileDoesNotExist($this->dir.'/site/robots.txt', 'no copy where the project folder\'s index.php made none');
    }

    public function test_the_copy_of_the_project_folders_index_php_is_written_too(): void
    {
        // a server whose web root is the project folder: the project folder's index.php copied public/robots.txt next to itself
        file_put_contents($this->dir.'/site/robots.txt', self::SHIPPED_122);

        $this->assertSame(['failed' => [], 'saved' => [], 'left' => []], RobotsTxt::refresh());
        $text = self::RULES."Sitemap: http://localhost/sitemap.xml\n";
        $this->assertSame($text, $this->file());
        $this->assertSame($text, (string) file_get_contents($this->dir.'/site/robots.txt'));
        // (storage: the site's storage/app with the record of what it wrote, R4SEO-V2-01)
        $this->assertSame(['public', 'robots.txt'], array_values(array_diff($this->names($this->dir.'/site'), ['storage'])), 'no temporary file left');
        $this->assertFileDoesNotExist($this->dir.'/robots.txt');

        // a file that already says it is left as it is
        touch($this->public(), time() - 3600);
        clearstatcache();
        $before = filemtime($this->public());
        $this->assertSame(['failed' => [], 'saved' => [], 'left' => []], RobotsTxt::refresh());
        clearstatcache();
        $this->assertSame($before, filemtime($this->public()));
    }

    public function test_a_file_that_cannot_be_written_never_stops_the_save(): void
    {
        // the public folder is no folder the site can write in
        file_put_contents($this->dir.'/not-a-folder', 'x');
        $this->app->instance(RobotsTxt::FOLDERS, ['public' => $this->dir.'/not-a-folder', 'project' => $this->dir.'/site']);
        $admin = User::factory()->admin()->create();
        $warning = RobotsTxt::warning(['public/robots.txt']);

        $response = $this->save($admin, ['robots_noindex' => '1'])->assertOk()->assertJsonPath('ok', true);
        $this->assertSame([$warning], $response->json('warnings'), 'the administrator reads what to do');
        $this->assertStringContainsString('public/robots.txt', $warning);
        $this->assertSame('Changes saved.', $response->json('message'));
        $this->assertSame('1', Setting::get('robots_noindex'), 'the setting is saved');
        $this->assertSame("User-agent: *\nDisallow: /\n", $this->route(), 'the route says it already');

        // the old page: saved, with the warning in its message
        $old = $this->actingAs($admin)
            ->putJson(route('admin.settings.update.group', 'seo'), $this->oldSeoPayload(['robots_noindex' => '0']))
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->assertSame([$warning], $old->json('warnings'));
        $this->assertStringContainsString($warning, (string) $old->json('message'));
        $this->assertSame('0', Setting::get('robots_noindex'));

        // without JavaScript: the page comes back with the warning
        $this->actingAs($admin)
            ->from('/admin/settings/seo')
            ->put('/admin/settings/save', ['groups' => ['seo' => ['robots_noindex' => '1']], 'fields' => ['seo' => ['robots_noindex']]])
            ->assertRedirect('/admin/settings/seo')
            ->assertSessionHas('success', fn (string $text): bool => str_contains($text, $warning));
    }

    public function test_the_end_of_an_install_writes_it_with_the_sites_final_address(): void
    {
        Setting::set('robots_noindex', '0', 'seo');
        Setting::set('sitemap_enabled', '1', 'seo');
        $run = InstallRun::memory(['messages' => [], 'notes' => []]);

        $this->filesStep($run, 'https://wizard.example');
        $this->assertSame(self::RULES."Sitemap: https://wizard.example/sitemap.xml\n", $this->file(), 'the address of the wizard (the final .env)');
        $this->assertContains('robots.txt written with the address of the sitemap.', (array) $run->get('messages'));
        $this->assertSame([], (array) $run->get('notes'));

        // an imported backup that hides the site: the file says it, and the message names no sitemap (R4SEO-V1-04)
        Setting::set('robots_noindex', '1', 'seo');
        $run = InstallRun::memory(['messages' => [], 'notes' => []]);
        $this->filesStep($run, 'https://wizard.example');
        $this->assertSame("User-agent: *\nDisallow: /\n", $this->file());
        $this->assertSame(['robots.txt written.'], (array) $run->get('messages'));

        // the sitemap off: the same
        Setting::set('robots_noindex', '0', 'seo');
        Setting::set('sitemap_enabled', '0', 'seo');
        $run = InstallRun::memory(['messages' => [], 'notes' => []]);
        $this->filesStep($run, 'https://wizard.example');
        $this->assertSame(self::RULES, $this->file());
        $this->assertSame(['robots.txt written.'], (array) $run->get('messages'));

        // a robots.txt the owner wrote by hand stays as it is
        file_put_contents($this->public(), self::OWNERS);
        $run = InstallRun::memory(['messages' => [], 'notes' => []]);
        $this->filesStep($run, 'https://wizard.example');
        $this->assertSame(self::OWNERS, $this->file());
        $this->assertSame(['robots.txt left as it is (public/robots.txt: changed by hand).'], (array) $run->get('messages'));
        $this->assertSame(['robots.txt'], $this->names($this->dir.'/site/public'));

        // a file the server may not write: the install goes on, its result page says what to do
        $this->app->instance(RobotsTxt::FOLDERS, ['public' => $this->dir.'/missing', 'project' => $this->dir.'/site']);
        $this->filesStep($run, 'https://wizard.example');
        $this->assertStringContainsString('robots.txt could not be written (public/robots.txt)', implode(' ', (array) $run->get('notes')));
        $this->assertStringContainsString('then open Settings > Google & sharing in the admin', implode(' ', (array) $run->get('notes')));
    }

    public function test_an_update_keeps_the_file_the_site_wrote(): void
    {
        $generated = self::RULES."Sitemap: https://example.test/sitemap.xml\n";
        $shipped123 = (string) file_get_contents(base_path('public/robots.txt'));

        // 1.2.2 -> 1.2.3: a file as 1.2.2 shipped it is replaced by the new one (no relative Sitemap line any more)
        $plan = $this->updatePlan(self::SHIPPED_122, self::SHIPPED_122, $shipped123);
        $this->assertContains(['put', 'public/robots.txt'], $this->moves($plan));
        $this->assertSame([], $plan['report']['kept']);

        // a file the site wrote differs from the shipped one: kept, the release's new copy goes next to it
        $plan = $this->updatePlan(self::SHIPPED_122, $generated, $shipped123);
        $this->assertNotContains(['put', 'public/robots.txt'], $this->moves($plan), 'the site\'s own file stays');
        $this->assertContains(['put', 'public/robots.txt.avdbcms-1.2.3'], $this->moves($plan));
        $this->assertSame([['path' => 'public/robots.txt', 'copy' => 'public/robots.txt.avdbcms-1.2.3']], $plan['report']['kept']);

        // a later release that ships the same file: nothing moves, nothing is copied next to it
        $plan = $this->updatePlan($shipped123, $generated, $shipped123, '1.2.4');
        $this->assertSame([], array_values(array_filter($this->moves($plan), fn (array $move): bool => str_starts_with($move[1], 'public/robots.txt'))));
        $this->assertSame([], $plan['report']['kept']);
    }

    public function test_only_a_change_of_a_robots_setting_writes_the_file_and_the_owners_own_file_is_kept(): void
    {
        // R4SEO-V1-01: the owner's own robots.txt (kept by the update) and saves of other SEO settings
        file_put_contents($this->public(), self::OWNERS);
        $admin = User::factory()->admin()->create();

        $this->save($admin, ['meta_title_template' => '{title} - {site_name}'])->assertOk()->assertJsonPath('warnings', []);
        $this->assertSame('{title} - {site_name}', Setting::get('meta_title_template'));
        $this->assertSame(self::OWNERS, $this->file(), 'an edit of another SEO setting leaves the file alone');

        // the old settings page posts every field: the robots settings as they are stored (here their defaults)
        $this->actingAs($admin)
            ->putJson(route('admin.settings.update.group', 'seo'), $this->oldSeoPayload(['default_meta_description' => 'Other words']))
            ->assertOk()
            ->assertJsonMissingPath('warnings');
        $this->assertSame(self::OWNERS, $this->file());

        // a form posted without JavaScript sends every field of the page: unchanged robots settings write nothing
        $this->actingAs($admin)
            ->from('/admin/settings/seo')
            ->put('/admin/settings/save', ['groups' => ['seo' => ['sitemap_enabled' => '1', 'robots_noindex' => '0', 'google_verification' => 'abc']], 'fields' => ['seo' => ['sitemap_enabled', 'robots_noindex', 'google_verification']]])
            ->assertRedirect('/admin/settings/seo');
        $this->assertSame(self::OWNERS, $this->file());
        $this->assertSame(['robots.txt'], $this->names($this->dir.'/site/public'));

        // the owner changes a robots setting: their file is kept next to it first, then the site writes its own
        $response = $this->save($admin, ['sitemap_enabled' => '0'])->assertOk();
        $this->assertSame(self::RULES, $this->file());
        $names = $this->names($this->dir.'/site/public');
        $this->assertCount(2, $names);
        $this->assertSame('robots.txt', $names[0]);
        $this->assertMatchesRegularExpression('#\Arobots\.txt\.owner-\d{8}-\d{6}\z#', $names[1]);
        $this->assertSame(self::OWNERS, (string) file_get_contents($this->dir.'/site/public/'.$names[1]), 'the owner\'s lines are kept');
        $notice = RobotsTxt::savedNotice(['public/robots.txt' => 'public/'.$names[1]]);
        $this->assertSame([$notice], $response->json('warnings'), 'the administrator reads where and what to do');
        $this->assertStringContainsString('public/'.$names[1], $notice);
        $this->assertStringContainsString('Custom robots.txt', $notice);

        // from now on the file is the site's: a later change replaces it without another copy
        $this->save($admin, ['sitemap_enabled' => '1'])->assertOk()->assertJsonPath('warnings', []);
        $this->assertSame(self::RULES."Sitemap: http://localhost/sitemap.xml\n", $this->file());
        $this->assertCount(2, $this->names($this->dir.'/site/public'));

        // what the site writes or shipped is its own; anything else is the owner's
        foreach ([self::SHIPPED_122, self::RULES, "User-agent: *\nDisallow: /\n", self::RULES."Sitemap: https://shop.example/index.php/sitemap.xml\n"] as $text) {
            $this->assertTrue(RobotsTxt::isSites($text), $text);
        }
        foreach ([self::OWNERS, '', str_replace("\n", "\r\n", self::RULES), self::RULES."Disallow: /mine/\n"] as $text) {
            $this->assertFalse(RobotsTxt::isSites($text), $text);
        }
    }

    public function test_opening_the_seo_page_brings_the_sites_file_up_to_date(): void
    {
        // R4SEO-V1-02: a site updated from 1.2.2 has the shipped 1.2.3 file (no Sitemap line, no settings) and no save to do
        file_put_contents($this->public(), self::RULES);
        Setting::set('sitemap_enabled', '1', 'seo');
        $admin = User::factory()->admin()->create();

        // also for an administrator of the old admin (the page sends them on to its SEO tab)
        config(['admin.ui' => 'v1']);
        $this->actingAs($admin)->get('/admin/settings/seo')->assertRedirect();
        $this->assertSame(self::RULES."Sitemap: http://localhost/sitemap.xml\n", $this->file());
        $this->assertSame(['robots.txt'], $this->names($this->dir.'/site/public'));

        config(['admin.ui' => 'v2']);
        file_put_contents($this->public(), self::RULES);
        $this->actingAs($admin)->get('/admin/settings/seo')->assertOk()->assertDontSee('could not write');
        $this->assertSame(self::RULES."Sitemap: http://localhost/sitemap.xml\n", $this->file());
        $this->assertSame(['robots.txt'], $this->names($this->dir.'/site/public'));

        // a robots.txt the owner wrote by hand stays as it is (no copy: nothing was asked)
        file_put_contents($this->public(), self::OWNERS);
        $this->actingAs($admin)->get('/admin/settings/seo')->assertOk();
        $this->assertSame(self::OWNERS, $this->file());
        $this->assertSame(['robots.txt'], $this->names($this->dir.'/site/public'));

        // the view-only demo account changes nothing, this file neither
        file_put_contents($this->public(), self::RULES);
        $demo = User::factory()->admin()->create();
        $demo->forceFill([\App\Http\Middleware\ViewOnlyDemo::COLUMN => true])->save();
        $this->actingAs($demo->fresh())->get('/admin/settings/seo');
        $this->assertSame(self::RULES, $this->file());

        // a file the site may not write: the page says what to do
        $this->app->instance(RobotsTxt::FOLDERS, ['public' => $this->dir.'/missing', 'project' => $this->dir.'/site']);
        $this->actingAs($admin)->get('/admin/settings/seo')->assertOk()
            ->assertSee(RobotsTxt::warning(['public/robots.txt']));
    }

    public function test_opening_the_page_after_a_write_that_failed_replaces_the_text_the_site_wrote(): void
    {
        // R4SEO-V2-01: Custom robots.txt "A" written by the site, then changed to "B" while the server does not let the
        // site write public/: the setting is saved, the file keeps "A", the warning says to open the page again
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);
        $site = ['public' => $this->dir.'/site/public', 'project' => $this->dir.'/site'];
        $a = "User-agent: *\nDisallow: /private-a/";
        $b = "User-agent: *\nDisallow: /private-b/";
        $this->save($admin, ['robots_txt' => $a])->assertOk()->assertJsonPath('warnings', []);
        $this->assertSame($a, $this->file());
        $this->app->instance(RobotsTxt::FOLDERS, ['public' => $this->dir.'/missing'] + $site);
        $this->save($admin, ['robots_txt' => $b])->assertOk()->assertJsonPath('warnings', [RobotsTxt::warning(['public/robots.txt'])]);
        $this->assertStringContainsString('/private-b/', (string) Setting::get('robots_txt'));
        $this->assertSame($a, $this->file());

        // the warning's remedy: the site may write there again, the administrator opens Settings > Google & sharing
        $this->app->instance(RobotsTxt::FOLDERS, $site);
        $this->actingAs($admin)->get('/admin/settings/seo')->assertOk()->assertDontSee('could not write');
        $this->assertSame($b, $this->file(), 'the text the site wrote is replaced: crawlers read the settings');
        $this->assertSame(['robots.txt'], $this->names($this->dir.'/site/public'), 'not kept as the owner\'s');

        // the same when Custom robots.txt is cleared (the default rules and the sitemap)
        $this->app->instance(RobotsTxt::FOLDERS, ['public' => $this->dir.'/missing'] + $site);
        $this->save($admin, ['robots_txt' => ''])->assertOk()->assertJsonPath('warnings', [RobotsTxt::warning(['public/robots.txt'])]);
        $this->assertSame($b, $this->file());
        $this->app->instance(RobotsTxt::FOLDERS, $site);
        $this->actingAs($admin)->get('/admin/settings/seo')->assertOk()->assertDontSee('could not write');
        $this->assertSame(self::RULES."Sitemap: http://localhost/sitemap.xml\n", $this->file());
        $this->assertSame(['robots.txt'], $this->names($this->dir.'/site/public'));

        // the record is kept in the site's storage/app (here the scratch site's), with its lock; no temporary file left
        $this->assertSame([RobotsTxt::RECORD, 'robots-txt.lock'], $this->names($this->dir.'/site/storage/app'));
    }

    public function test_the_next_save_replaces_the_text_the_site_wrote_without_keeping_it_as_the_owners(): void
    {
        // R4SEO-V2-01: Custom robots.txt "A" written by the site; "B" could not be written; the next change of a robots
        // setting replaces "A" (the site's own text): no robots.txt.owner-<date>-<time> copy, no notice
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);
        $site = ['public' => $this->dir.'/site/public', 'project' => $this->dir.'/site'];
        $this->save($admin, ['robots_txt' => "User-agent: *\nDisallow: /private-a/"])->assertOk()->assertJsonPath('warnings', []);
        $this->app->instance(RobotsTxt::FOLDERS, ['public' => $this->dir.'/missing'] + $site);
        $this->save($admin, ['robots_txt' => "User-agent: *\nDisallow: /private-b/"])->assertOk();
        $this->app->instance(RobotsTxt::FOLDERS, $site);

        $this->save($admin, ['robots_txt' => "User-agent: *\nDisallow: /private-c/"])->assertOk()->assertJsonPath('warnings', []);
        $this->assertSame("User-agent: *\nDisallow: /private-c/", $this->file());
        $this->assertSame(['robots.txt'], $this->names($this->dir.'/site/public'), 'no copy of the site\'s own text');

        // the old settings page: the same
        $this->app->instance(RobotsTxt::FOLDERS, ['public' => $this->dir.'/missing'] + $site);
        $this->actingAs($admin)
            ->putJson(route('admin.settings.update.group', 'seo'), $this->oldSeoPayload(['robots_txt' => "User-agent: *\nDisallow: /private-d/"]))
            ->assertOk();
        $this->app->instance(RobotsTxt::FOLDERS, $site);
        $this->actingAs($admin)
            ->putJson(route('admin.settings.update.group', 'seo'), $this->oldSeoPayload(['robots_noindex' => '1']))
            ->assertOk()
            ->assertJsonMissingPath('warnings');
        $this->assertSame("User-agent: *\nDisallow: /\n", $this->file());
        $this->assertSame(['robots.txt'], $this->names($this->dir.'/site/public'));

        // a text the site never wrote is still the owner's: an edit by hand after the site's writes stays when the page is
        // opened, and is kept next to the file when a robots setting changes
        file_put_contents($this->public(), self::OWNERS);
        $this->actingAs($admin)->get('/admin/settings/seo')->assertOk();
        $this->assertSame(self::OWNERS, $this->file());
        $response = $this->save($admin, ['robots_noindex' => '0'])->assertOk();
        $this->assertSame(self::RULES."Sitemap: http://localhost/sitemap.xml\n", $this->file());
        $names = $this->names($this->dir.'/site/public');
        $this->assertCount(2, $names);
        $this->assertSame(self::OWNERS, (string) file_get_contents($this->dir.'/site/public/'.$names[1]));
        $this->assertSame([RobotsTxt::savedNotice(['public/robots.txt' => 'public/'.$names[1]])], $response->json('warnings'));

        // the record keeps the newest texts; one that cannot be read counts as none: a file is then the owner's unless
        // isSites() knows it (kept, never lost)
        for ($i = 1; $i <= 55; $i++) {
            config(['site.robots_txt_custom' => "User-agent: *\nDisallow: /p{$i}/"]);
            $this->assertSame(['failed' => [], 'saved' => [], 'left' => []], RobotsTxt::write());
        }
        $record = $this->dir.'/site/storage/app/'.RobotsTxt::RECORD;
        $written = (array) (json_decode((string) file_get_contents($record), true)['written']['public/robots.txt'] ?? []);
        $this->assertCount(50, $written);
        $this->assertSame(hash('sha256', "User-agent: *\nDisallow: /p55/"), end($written));
        file_put_contents($record, '{broken');
        config(['site.robots_txt_custom' => '']);
        $this->assertSame(['failed' => [], 'saved' => [], 'left' => ['public/robots.txt']], RobotsTxt::write(null, true));
        $this->assertSame("User-agent: *\nDisallow: /p55/", $this->file());
    }

    /** One save of the settings page (PUT /admin/settings/save), the SEO group's listed fields. */
    private function save(User $admin, array $seo): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($admin)->putJson('/admin/settings/save', ['groups' => ['seo' => $seo], 'fields' => ['seo' => array_keys($seo)]]);
    }

    /** /robots.txt as the site's route answers it (no file in front of it in a test request). */
    private function route(): string
    {
        Cache::flush();

        return (string) $this->get('/robots.txt')->assertOk()->getContent();
    }

    private function public(): string
    {
        return $this->dir.'/site/public/robots.txt';
    }

    private function file(): string
    {
        clearstatcache();

        return (string) file_get_contents($this->public());
    }

    /** @return list<string> */
    private function names(string $folder): array
    {
        $names = array_values(array_diff((array) scandir($folder), ['.', '..']));
        sort($names);

        return $names;
    }

    /** The installer's files step (the storage link, permissions and caches left out: they act on the copy itself). */
    private function filesStep(InstallRun $run, string $siteUrl): void
    {
        $installer = new class extends Installer
        {
            protected function prepareFiles(InstallRun $run): void
            {
            }
        };

        (new \ReflectionMethod(Installer::class, 'jobFiles'))->invoke($installer, $run, ['url' => $siteUrl]);
    }

    /** @return array<string, mixed> SwapPlanner's plan of an update whose only file is public/robots.txt */
    private function updatePlan(string $installedFile, string $liveFile, string $releaseFile, string $version = '1.2.3'): array
    {
        $installed = new Manifest('1.2.2', '8.3.0', '1.2.0', ['public/robots.txt' => hash('sha256', $installedFile)], []);
        $new = new Manifest($version, '8.3.0', '1.2.0', ['public/robots.txt' => hash('sha256', $releaseFile)], []);
        $live = ['public/robots.txt' => $liveFile, Manifest::FILE => 'old manifest'];

        return SwapPlanner::plan(
            $new,
            $installed,
            hash('sha256', 'new manifest'),
            fn (string $path): ?string => isset($live[$path]) ? hash('sha256', $live[$path]) : null,
            fn (string $path): ?string => $live[$path] ?? null,
            fn (string $path): ?string => $path === 'public/robots.txt' ? $releaseFile : null,
        );
    }

    /** @return list<array{0: string, 1: string}> [do, path] of every move */
    private function moves(array $plan): array
    {
        return array_map(fn (array $op): array => [(string) $op['do'], (string) $op['path']], $plan['ops']);
    }

    /** Every field of the old settings page's SEO group (Admin\SettingsController::updateGroup()). */
    private function oldSeoPayload(array $overrides = []): array
    {
        return array_merge([
            'meta_title_template' => '{title} | {site_name}',
            'default_meta_description' => 'Default description',
            ...\App\Services\SeoService::templateDefaults(),
            'og_image' => '',
            'twitter_card_type' => 'summary_large_image',
            'twitter_handle' => '',
            'facebook_app_id' => '',
            'google_verification' => '',
            'adult_rating_enabled' => '1',
            'profile_uid_urls_enabled' => '1',
            'video_pages_indexable' => '1',
            'category_pages_indexable' => '1',
            'profile_pages_indexable' => '1',
            'playlist_pages_indexable' => '1',
            'search_pages_indexable' => '0',
            'video_url_prefix' => 'video',
            'video_url_pattern' => '{slug}-{uuid}',
            'robots_max_snippet' => '-1',
            'robots_max_video_preview' => '-1',
            'robots_max_image_preview' => 'large',
            'schema_organization_type' => 'Organization',
            'canonical_enabled' => '1',
            'hreflang_enabled' => '1',
            'website_schema_enabled' => '1',
            'search_action_schema_enabled' => '1',
            'video_schema_enabled' => '1',
            'video_seek_action_enabled' => '0',
            'sitemap_enabled' => '1',
            'video_sitemap_enabled' => '1',
            'sitemap_video_limit' => '1000',
            'indexnow_enabled' => '0',
            'indexnow_key' => '',
            'indexnow_endpoint' => 'https://api.indexnow.org/indexnow',
            'robots_txt' => '',
        ], $overrides);
    }
}
