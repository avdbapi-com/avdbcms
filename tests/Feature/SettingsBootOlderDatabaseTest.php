<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Support\SiteSettingsSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The admin's settings on the database of an older release (judge R3-001). Between the files of a one-click update and
 * its Finish (and inside the Finish request itself) the 1.2.3 code boots on a 1.2.0 to 1.2.2 database that has no row
 * ad_grid_layout_home_feed yet (2026_10_25_120000_add_homepage_feed_list_ad adds it). Reading that missing row threw,
 * and one catch around the whole of AppServiceProvider::applySettings() skipped every setting after the ads: the admin's
 * time zone (Finish wrote its Admin actions rows and the bans' dates in config/app.php's Europe/Paris), the language,
 * the look, SEO and the security settings (the admin IP allow-list, the robot check, two-factor sign-in, force HTTPS).
 *
 * Now the missing row is its default, and each group of settings is applied on its own: a group that fails is written
 * to the log (once in ten minutes) and every group after it is still applied.
 */
class SettingsBootOlderDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private string $zoneBefore = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();

        $this->zoneBefore = date_default_timezone_get();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zoneBefore);

        parent::tearDown();
    }

    public function test_the_admins_zone_language_and_security_settings_apply_on_a_database_without_the_home_feed_row(): void
    {
        $this->storeOwnerSettings();
        // A 1.2.0 to 1.2.2 database: the row the 1.2.3 migration adds is not there yet.
        DB::table('settings')->where('key', 'ad_grid_layout_home_feed')->delete();
        $this->assertFalse(DB::table('settings')->where('key', 'ad_grid_layout_home_feed')->exists());
        $this->startAsAFreshRequest();

        $this->applySettings();

        $this->assertOwnerSettingsApplied();
        $this->assertSame('inherit', config('site.ad_grid_layout_home_feed'), 'the missing row is its default');
        $this->assertSame('wide_row', config('site.ad_grid_layout'));
        $this->assertSame('grid_card', config('site.ad_grid_layout_new_videos'), 'the rows the database has are read as before');
    }

    public function test_a_group_that_fails_is_logged_once_and_every_group_after_it_is_still_applied(): void
    {
        $this->storeOwnerSettings();
        // A value that is not a text (a row edited by hand, a cache entry another release wrote): the uploads group
        // cannot apply it.
        $all = \App\Models\Setting::getAllGrouped();
        $all['uploads']['upload_allowed_formats'] = ['mp4', 'webm'];
        Cache::forever('settings.__all_grouped', $all);
        $this->startAsAFreshRequest();
        Log::spy();

        $this->applySettings();
        // The next request on the same site: the line is not written again for ten minutes.
        $this->startAsAFreshRequest();
        $this->applySettings();

        $this->assertOwnerSettingsApplied();
        $this->assertSame('as the configuration files have it', config('site.upload_allowed_formats'), 'the setting of the failing group keeps its value');
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message): bool => str_starts_with($message, 'AVDBCMS settings: the uploads settings could not be applied'));
    }

    /**
     * A console process (the scheduler, the queue worker) applies the settings inside SafeCache::readOnly(): a cron job may
     * run as another system user (root), and a cache file it wrote the web could not replace. A failing group is logged
     * there without writing the cache: once per process, and not at all while the web logged it in the last ten minutes.
     */
    public function test_a_console_process_logs_a_failing_group_without_writing_the_cache(): void
    {
        $this->storeOwnerSettings();
        $all = \App\Models\Setting::getAllGrouped();
        $all['uploads']['upload_allowed_formats'] = ['mp4', 'webm'];
        Cache::forever('settings.__all_grouped', $all);
        $this->forgetWhatConsoleProcessesLogged();
        $this->startAsAFreshRequest();
        Log::spy();

        \App\Support\SafeCache::readOnly(fn () => $this->applySettings());
        $this->startAsAFreshRequest();
        \App\Support\SafeCache::readOnly(fn () => $this->applySettings());

        $this->assertOwnerSettingsApplied();
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message): bool => str_starts_with($message, 'AVDBCMS settings: the uploads settings could not be applied'));
        $written = array_values(array_filter(array_keys($this->cacheEntries()), fn (string $key): bool => str_starts_with($key, 'avdbcms:settings-group-failed:')));
        $this->assertSame([], $written, 'the console wrote nothing into the cache');
    }

    public function test_a_console_process_does_not_log_a_failing_group_the_web_logged_a_moment_ago(): void
    {
        $this->storeOwnerSettings();
        $all = \App\Models\Setting::getAllGrouped();
        $all['uploads']['upload_allowed_formats'] = ['mp4', 'webm'];
        Cache::forever('settings.__all_grouped', $all);
        $this->forgetWhatConsoleProcessesLogged();
        $this->startAsAFreshRequest();
        Log::spy();

        // The web logs it (its ten-minute mark goes into the cache), then a console process applies the settings.
        $this->applySettings();
        $this->startAsAFreshRequest();
        \App\Support\SafeCache::readOnly(fn () => $this->applySettings());

        $this->assertOwnerSettingsApplied();
        Log::shouldHaveReceived('error')->once();
    }

    /**
     * The line of a group that fails carries the admin's time zone, and so does every later line of the request (review
     * UP-RV-01). Monolog gives a logger the zone PHP has when the logger is made: written from inside the group, before
     * the localization group, the line made the request's logger in config/app.php's Europe/Paris. The failures are now
     * written after the last group.
     */
    public function test_the_line_of_a_failing_group_and_the_later_lines_carry_the_admins_time_zone(): void
    {
        $this->storeOwnerSettings();
        $all = \App\Models\Setting::getAllGrouped();
        $all['uploads']['upload_allowed_formats'] = ['mp4', 'webm'];
        Cache::forever('settings.__all_grouped', $all);
        $this->logIntoATestHandler();
        $this->startAsAFreshRequest();

        $this->applySettings();
        Log::info('a later line of the same request');

        $records = $this->testHandler()->getRecords();
        $this->assertCount(2, $records);
        $this->assertStringStartsWith('AVDBCMS settings: the uploads settings could not be applied', $records[0]->message);
        foreach ($records as $record) {
            $this->assertSame('Asia/Bangkok', $record->datetime->getTimezone()->getName(), $record->message);
        }
        $this->assertOwnerSettingsApplied();
    }

    /**
     * An installed site logs errors only (the installer writes LOG_LEVEL=error): a group that could not be applied left
     * settings at their defaults (the security ones among them), so its line is an error and reaches that log; written
     * as a warning it never did (review UP-RV-01, seen on the e2e site of the real update).
     */
    public function test_the_line_of_a_failing_group_reaches_the_log_of_an_installed_site(): void
    {
        $this->storeOwnerSettings();
        $all = \App\Models\Setting::getAllGrouped();
        $all['uploads']['upload_allowed_formats'] = ['mp4', 'webm'];
        Cache::forever('settings.__all_grouped', $all);
        $this->logIntoATestHandler('error');
        $this->startAsAFreshRequest();

        $this->applySettings();

        $records = $this->testHandler()->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('ERROR', $records[0]->level->getName());
        $this->assertStringStartsWith('AVDBCMS settings: the uploads settings could not be applied', $records[0]->message);
        $this->assertSame('Asia/Bangkok', $records[0]->datetime->getTimezone()->getName());
    }

    /**
     * A logger made before the settings are applied (a long-running worker applies the admin's changed settings before
     * its next job, ConsoleSiteSettings::beforeJob()) writes in the zone they apply from then on.
     */
    public function test_a_logger_made_before_the_settings_writes_in_the_zone_they_apply(): void
    {
        $this->storeOwnerSettings();
        $this->logIntoATestHandler();
        $this->startAsAFreshRequest();
        Log::info('before the settings');

        $this->applySettings();
        Log::info('after the settings');

        $records = $this->testHandler()->getRecords();
        $this->assertCount(2, $records);
        $this->assertSame('Europe/Paris', $records[0]->datetime->getTimezone()->getName(), 'the line written before');
        $this->assertSame('Asia/Bangkok', $records[1]->datetime->getTimezone()->getName(), 'the line written after');
        $this->assertOwnerSettingsApplied();
    }

    public function test_a_site_without_settings_gets_every_default_and_no_log_line(): void
    {
        // A fresh install whose settings table is not there yet (or empty): every group applies its defaults.
        $this->startAsAFreshRequest();
        Log::spy();
        DB::statement('DROP TABLE settings');
        Cache::flush();

        $this->applySettings();

        $this->assertSame('Europe/Paris', config('app.timezone'), 'no time zone saved: the one of config/app.php');
        $this->assertSame('', config('site.admin_ip_whitelist'), 'the security group applied its defaults');
        $this->assertFalse(config('site.captcha_on_login'));
        $this->assertSame('inherit', config('site.ad_grid_layout_home_feed'));
        $this->assertSame('grid_card', config('site.ad_grid_layout'), 'no row: the default, never a missing key');
        Log::shouldNotHaveReceived('error');
    }

    /* ----------------------------------------------------------------- helpers */

    /** What an owner saved on 1.2.2: a time zone that is not Paris, a language, the security group, SEO and the ads. */
    private function storeOwnerSettings(): void
    {
        foreach ([
            ['localization', 'timezone', 'Asia/Bangkok'],
            ['localization', 'date_format', 'd/m/Y'],
            ['security', 'admin_ip_whitelist', '203.0.113.7'],
            ['security', 'captcha_provider', 'built_in'],
            ['security', 'captcha_on_login', '1'],
            ['security', 'captcha_on_register', '1'],
            ['security', 'two_factor_enabled', '1'],
            ['security', 'force_https', '1'],
            ['security', 'block_tor_exit_nodes', '1'],
            ['seo', 'meta_title_template', '{title} - {site_name} (owner)'],
            ['monetization', 'ad_grid_layout', 'wide_row'],
            ['monetization', 'ad_grid_layout_new_videos', 'grid_card'],
            ['monetization', 'ad_preroll_enabled', '1'],
            ['maintenance', 'maintenance_allowed_ips', '198.51.100.9'],
        ] as [$group, $key, $value]) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['group' => $group, 'value' => $value]);
        }

        Cache::flush();
    }

    /** config/app.php's values, as a request starts before the settings are applied. */
    private function startAsAFreshRequest(): void
    {
        config([
            'app.timezone' => 'Europe/Paris',
            'site.admin_ip_whitelist' => null,
            'site.captcha_on_login' => null,
            'site.captcha_on_register' => null,
            'site.two_factor_enabled' => null,
            'site.force_https' => null,
            'site.block_tor_exit_nodes' => null,
            'site.meta_title_template' => null,
            'site.date_format' => null,
            'site.maintenance_allowed_ips' => null,
            'site.ad_preroll_enabled' => null,
            'site.upload_allowed_formats' => 'as the configuration files have it',
        ]);
        date_default_timezone_set('Europe/Paris');
    }

    private function applySettings(): void
    {
        (new AppServiceProvider($this->app))->applySettings(SiteSettingsSource::web());
    }

    /**
     * The default log channel of this test: a Monolog logger, made at its first line, that keeps its records ($level: the
     * lowest level it keeps, as LOG_LEVEL sets it on a site).
     */
    private function logIntoATestHandler(string $level = 'debug'): void
    {
        config([
            'logging.default' => 'settings_boot_test',
            'logging.channels.settings_boot_test' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\TestHandler::class, 'level' => $level],
        ]);
        Log::forgetChannel('settings_boot_test');
    }

    private function testHandler(): \Monolog\Handler\TestHandler
    {
        $handlers = array_values(array_filter(Log::channel('settings_boot_test')->getLogger()->getHandlers(),
            fn ($handler): bool => $handler instanceof \Monolog\Handler\TestHandler));
        $this->assertCount(1, $handlers);

        return $handlers[0];
    }

    /** A new console process: nothing logged by this one yet. */
    private function forgetWhatConsoleProcessesLogged(): void
    {
        $logged = new \ReflectionProperty(AppServiceProvider::class, 'settingsGroupsLogged');
        $logged->setValue(null, []);
    }

    /** @return array<string, mixed> the entries of the test's (array) cache store */
    private function cacheEntries(): array
    {
        $store = Cache::store()->getStore();
        $storage = new \ReflectionProperty($store, 'storage');

        return (array) $storage->getValue($store);
    }

    private function assertOwnerSettingsApplied(): void
    {
        $this->assertSame('Asia/Bangkok', config('app.timezone'), 'the admin\'s time zone');
        $this->assertSame('Asia/Bangkok', date_default_timezone_get());
        $this->assertSame('Asia/Bangkok', Carbon::now()->getTimezone()->getName(), 'what Finish writes its rows in');
        $this->assertSame('d/m/Y', config('site.date_format'));
        $this->assertSame('203.0.113.7', config('site.admin_ip_whitelist'), 'the admin IP allow-list');
        $this->assertSame([true, true], [config('site.captcha_on_login'), config('site.captcha_on_register')], 'the robot check');
        $this->assertSame('built_in', config('site.captcha_provider'));
        $this->assertTrue(config('site.two_factor_enabled'), 'two-factor sign-in');
        $this->assertTrue(config('site.force_https'), 'force HTTPS');
        $this->assertTrue(config('site.block_tor_exit_nodes'));
        $this->assertSame('{title} - {site_name} (owner)', config('site.meta_title_template'), 'SEO');
        $this->assertSame('198.51.100.9', config('site.maintenance_allowed_ips'), 'the owner\'s maintenance settings');
        $this->assertTrue(config('site.ad_preroll_enabled'));
    }
}
