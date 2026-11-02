<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\AdminArtisan;
use App\Support\AdminPreferences;
use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

/**
 * Tools > Commands > config:cache (and optimize) caches the configuration FILES only (R1BR-01, also 1.2.2).
 *
 * The command builds the configuration in a fresh application. Run from the admin, inside a web request, that
 * application took itself for a web request and applied the admin's settings, so bootstrap/cache/config.php held the
 * database values: the SMTP password, API keys, the site name ... Here the fresh application reads a database file of
 * its own (the test's database lives in memory, which another application cannot see) whose settings table holds
 * values that exist nowhere else, and it is told it runs in a web request (APP_RUNNING_IN_CONSOLE=false), as on a
 * real site. The cached file must not hold any of them, the page keeps the command's output, and the next request and
 * a console process doing the site's work (round 1b R1R-06) still apply the admin's settings on top of the cache.
 */
class AdminCommandsConfigCacheTest extends TestCase
{
    use RefreshDatabase;

    /** Values the settings table of the fresh application holds, and nothing else does. */
    private const STORED = [
        ['general', 'site_name', 'R1BR-01 Settings Site'],
        ['email', 'smtp_host', 'smtp.r1br01-settings.test'],
        ['email', 'smtp_username', 'r1br01-settings-user'],
        ['email', 'smtp_password', 'r1br01-settings-password-Q7'],
        ['security', 'captcha_secret_key', 'r1br01-settings-captcha-secret'],
    ];

    private string $dir = '';

    /** The same folder relative to the site (APP_CONFIG_CACHE and its siblings: an absolute Windows path is not read as one). */
    private string $relative = '';

    /**
     * The fresh applications' database file, outside the site: a fresh application stays referenced by statics of the
     * framework after the test, and Windows keeps a file open by its connection (it is removed where it can be).
     */
    private string $database = '';

    /** @var list<Application> the applications a test made (their database connections are closed at the end) */
    private array $fresh = [];

    /** @var array<string, array{server: mixed, env: mixed, putenv: string|false}> */
    private array $envBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.operational_tools_enabled' => true]);
        $this->relative = 'storage/framework/testing/r1br01-'.Str::lower(Str::random(10));
        $this->dir = base_path($this->relative);
        File::ensureDirectoryExists($this->dir);
        $this->database = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avd-r1br01-'.Str::lower(Str::random(12)).'.sqlite';
        $this->makeSettingsDatabase($this->database);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        $this->backToTheTestApplication();

        foreach ($this->fresh as $app) {
            foreach (array_keys($app['db']->getConnections()) as $name) {
                $app['db']->purge($name);
            }
        }

        $this->fresh = [];
        gc_collect_cycles();
        File::deleteDirectory($this->dir);
        @unlink($this->database);

        parent::tearDown();
    }

    public function test_config_cache_from_the_admin_keeps_no_database_setting_and_shows_its_output(): void
    {
        $cache = $this->dir.'/config.php';
        $response = $this->runFromTheAdmin('config:cache');

        $this->assertFileExists($cache);
        $text = (string) file_get_contents($cache);

        foreach (self::STORED as [$group, $key, $value]) {
            $this->assertStringNotContainsString($value, $text, "the cached configuration holds the {$group}.{$key} of the settings table");
        }

        $config = require $cache;
        $this->assertNotSame('R1BR-01 Settings Site', $config['app']['name']);
        $this->assertNotSame('smtp.r1br01-settings.test', $config['mail']['mailers']['smtp']['host']);
        $this->assertNotSame('r1br01-settings-password-Q7', $config['mail']['mailers']['smtp']['password']);

        $response->assertRedirect(route('admin.artisan.index'));
        // the request's own application answered: the one-time output is in its session (1.2.2 lost it)
        $response->assertSessionHas('artisan_output', fn ($output): bool => str_contains((string) $output, 'Configuration cached successfully'));
        $response->assertSessionHas('artisan_last_command', 'config:cache');

        // still the request's own application, with its own database
        $this->assertSame($this->app, Container::getInstance());
        $this->assertSame($this->app, Facade::getFacadeApplication());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    public function test_optimize_caches_the_configuration_files_only(): void
    {
        $cache = $this->dir.'/config.php';
        $this->setEnv([
            'APP_CONFIG_CACHE' => $this->relative.'/config.php',
            'APP_ROUTES_CACHE' => $this->relative.'/routes.php',
            'APP_EVENTS_CACHE' => $this->relative.'/events.php',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->database,
            'APP_RUNNING_IN_CONSOLE' => 'false',
        ]);

        // what Commands > optimize runs (the views part left out here: it compiles every view of the site)
        $result = AdminArtisan::call('optimize', ['--except' => 'views']);

        $this->assertSame(0, $result['status'], $result['output']);
        $this->assertStringContainsString('config', $result['output']);
        $this->assertSame($this->app, Container::getInstance());
        $this->assertSame('false', $_SERVER['APP_RUNNING_IN_CONSOLE'], 'the switch is put back');
        $this->assertFileExists($cache);
        $text = (string) file_get_contents($cache);

        foreach (self::STORED as [$group, $key, $value]) {
            $this->assertStringNotContainsString($value, $text, "optimize cached the {$group}.{$key} of the settings table");
        }
    }

    public function test_the_next_request_and_a_console_process_still_apply_the_admins_settings_over_the_cache(): void
    {
        $cache = $this->dir.'/config.php';
        $this->runFromTheAdmin('config:cache');
        $this->assertFileExists($cache);

        // the next web request: the cached files plus the admin's settings
        $this->setEnv(['APP_RUNNING_IN_CONSOLE' => 'false']);
        $web = $this->freshApplication(HttpKernel::class);
        $this->assertTrue($web->configurationIsCached());
        $this->assertSame('R1BR-01 Settings Site', $web['config']->get('app.name'));
        $this->assertSame('smtp.r1br01-settings.test', $web['config']->get('mail.mailers.smtp.host'));
        $this->assertSame('r1br01-settings-password-Q7', $web['config']->get('mail.mailers.smtp.password'));
        $this->backToTheTestApplication();

        // a console process that does the site's work (the queue worker): the same settings (AppServiceProvider::applySettings)
        $this->setEnv(['APP_RUNNING_IN_CONSOLE' => 'true']);
        $console = $this->freshApplication(ConsoleKernel::class);
        $this->assertTrue($console->configurationIsCached());
        $this->assertNotSame('smtp.r1br01-settings.test', $console['config']->get('mail.mailers.smtp.host'), 'nothing applied before the command starts');
        $this->assertTrue((new AppServiceProvider($console))->consoleSettings()->commandStarting('queue:work'));
        $this->assertSame('smtp.r1br01-settings.test', $console['config']->get('mail.mailers.smtp.host'));
        $this->assertSame('R1BR-01 Settings Site', $console['config']->get('app.name'));
        $this->backToTheTestApplication();

        // config:cache on the command line applies none of them
        $console = $this->freshApplication(ConsoleKernel::class);
        $this->assertFalse((new AppServiceProvider($console))->consoleSettings()->commandStarting('config:cache'));
        $this->assertNotSame('smtp.r1br01-settings.test', $console['config']->get('mail.mailers.smtp.host'));
    }

    /**
     * The rest of the request after config:cache keeps what the request had (judge R2CD-RV-01): the fresh application
     * of the command set PHP's time zone to the configuration file's (Europe/Paris) instead of the admin's Time zone, so
     * the "Command run" row of Admin actions was stamped hours off; it also left Carbon in the configuration's language,
     * an error handler of its own and itself as the application the error handlers report to.
     */
    public function test_the_rest_of_the_request_keeps_its_time_zone_language_and_error_handlers(): void
    {
        $zone = date_default_timezone_get();
        // what AppServiceProvider applied for this request: the admin's Time zone (the configuration file says Europe/Paris)
        $this->assertSame('Europe/Paris', config('app.timezone'), 'the configuration file of the site');
        date_default_timezone_set('Asia/Bangkok');
        config(['app.timezone' => 'Asia/Bangkok']);
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:39:47', 'UTC'));
        $handler = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        $reportsTo = \Closure::bind(static fn () => self::$app, null, HandleExceptions::class);

        try {
            $response = $this->runFromTheAdmin('config:cache', ['language' => 'ar'], ['app_locale' => 'ar']);

            $response->assertRedirect(route('admin.artisan.index'));
            $response->assertSessionHas('artisan_output', fn ($output): bool => str_contains((string) $output, 'Configuration cached successfully'));

            // the row written right after the command: the site's wall-clock time (16:39:47 in Bangkok), not Paris's 10:39:47
            $row = DB::table('admin_audit_logs')->where('action', 'tools.command.run')->first();
            $current = set_error_handler(static fn (): bool => false);
            restore_error_handler();

            $this->assertSame([
                'time zone' => 'Asia/Bangkok',
                'row' => 'config:cache at 2026-10-28 16:39:47',
                'Carbon language' => 'ar',
                'error handler' => 'as before the command',
                'errors reported to' => 'the request\'s application',
            ], [
                'time zone' => date_default_timezone_get(),
                'row' => $row === null ? 'none' : $row->details.' at '.$row->created_at,
                'Carbon language' => Carbon::getLocale(),
                'error handler' => $current === $handler ? 'as before the command' : 'another one',
                'errors reported to' => $reportsTo() === $this->app ? 'the request\'s application' : 'another application',
            ]);
        } finally {
            date_default_timezone_set($zone);
            Carbon::setTestNow();
            Carbon::setLocale('en');
        }
    }

    /**
     * Commands > config:cache (or another command) as the admin's form posts it, in Developer mode.
     *
     * @param  array<string, mixed>  $attributes  of the administrator
     * @param  array<string, mixed>  $session
     */
    private function runFromTheAdmin(string $command, array $attributes = [], array $session = [])
    {
        $admin = User::factory()->admin()->create($attributes);
        AdminPreferences::set($admin, 'developer_mode', true);

        // what the fresh application reads: its own database file, and a web request's SAPI (a real site's PHP-FPM)
        $this->setEnv([
            'APP_CONFIG_CACHE' => $this->relative.'/config.php',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->database,
            'APP_RUNNING_IN_CONSOLE' => 'false',
        ]);

        return $this->actingAs($admin)->withSession($session)->post(route('admin.artisan.run'), ['command' => $command]);
    }

    private function makeSettingsDatabase(string $path): void
    {
        touch($path);
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "key" VARCHAR(255) NOT NULL, value TEXT NULL, "group" VARCHAR(255) NOT NULL DEFAULT \'general\', created_at DATETIME NULL, updated_at DATETIME NULL)');
        $insert = $pdo->prepare('INSERT INTO settings ("group", "key", value, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');

        foreach (self::STORED as [$group, $key, $value]) {
            $insert->execute([$group, $key, $value, '2026-10-28 00:00:00', '2026-10-28 00:00:00']);
        }
    }

    /** A new application of this site (bootstrap/app.php), bootstrapped by the given kernel, as another process would. */
    private function freshApplication(string $kernel): Application
    {
        $app = require $this->app->bootstrapPath('app.php');
        $app->make($kernel)->bootstrap();
        $this->fresh[] = $app;

        return $app;
    }

    /** The test's application as the global one again (a fresh application makes itself the global one). */
    private function backToTheTestApplication(): void
    {
        Container::setInstance($this->app);
        Facade::setFacadeApplication($this->app);
        Facade::clearResolvedInstances();
        Model::setConnectionResolver($this->app['db']);
        Model::setEventDispatcher($this->app['events']);
    }

    /** @param  array<string, string>  $vars */
    private function setEnv(array $vars): void
    {
        foreach ($vars as $name => $value) {
            $this->envBefore[$name] ??= ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null, 'putenv' => getenv($name)];
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
            putenv($name.'='.$value);
        }
    }

    private function restoreEnv(): void
    {
        foreach ($this->envBefore as $name => $before) {
            if ($before['server'] === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $before['server'];
            }

            if ($before['env'] === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $before['env'];
            }

            putenv($before['putenv'] === false ? $name : $name.'='.$before['putenv']);
        }

        $this->envBefore = [];
    }
}
