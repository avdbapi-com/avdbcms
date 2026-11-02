<?php

namespace Tests\Feature;

use App\Support\EnvironmentRepairer;
use App\Support\StorageHider;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Round 1b judge R1B-016: .env got 0660 from the front controllers, the permission repair and the update's Finish.
 * Where the web server reads the site through the site's group (www-data in the site user's group: the hosting model of
 * many panels) the web server could rewrite .env (APP_KEY, the database, the mail settings). Every code path that sets
 * the mode now gives 0640: written by its owner (the site's PHP user) only, read by the group, nothing for the others;
 * the update's Finish takes the group's write bit and the others' bits away from an older .env (0664 of 1.2.2, 0660 of
 * RC1) and leaves a stricter mode the owner chose (0600, 0400) as it is. Only from a web request, as before: from the
 * console (another user, root) the mode stays.
 *
 * The POSIX cases need a Linux / macOS PHP (the round 1b box runs them; Windows has no such modes).
 */
class EnvModeTest extends TestCase
{
    private string $directory = '';

    protected function tearDown(): void
    {
        if ($this->directory !== '') {
            File::deleteDirectory($this->directory);
        }

        $this->setRunningInConsole(null);

        parent::tearDown();
    }

    public function test_every_code_path_that_sets_the_mode_gives_0640(): void
    {
        foreach (['index.php', 'public/index.php'] as $front) {
            $source = (string) file_get_contents(base_path($front));
            $this->assertStringContainsString('@chmod($envPath, 0640);', $source, $front);
            $this->assertDoesNotMatchRegularExpression('/@chmod\(\$envPath,\s*0(?!640)\d{3}\)/', $source, $front);
        }

        $repairer = (string) file_get_contents(app_path('Support/EnvironmentRepairer.php'));
        $this->assertStringContainsString("@exec('chmod 640 ' . escapeshellarg(\$envPath) . ' 2>&1');", $repairer);
        $this->assertStringContainsString('@chmod($envPath, 0640);', $repairer, 'a .env the repair makes from .env.example');
        $this->assertSame(0640, (new EnvironmentRepairer())->filePermissionPlan(true, true)['modes'][base_path('.env')]);
        $this->assertSame(0640, (new EnvironmentRepairer())->filePermissionPlan(false, true)['modes'][base_path('.env')]);

        $hider = (string) file_get_contents(app_path('Support/StorageHider.php'));
        $this->assertStringContainsString('@chmod($env, $mode & 0640)', $hider);
    }

    public function test_finish_takes_the_group_write_bit_and_the_others_bits_away_and_keeps_a_stricter_mode(): void
    {
        $this->requirePosix();
        $env = $this->ownEnv();

        foreach ([0664 => 0640, 0660 => 0640, 0644 => 0640, 0666 => 0640, 0662 => 0640, 0640 => 0640, 0600 => 0600, 0400 => 0400, 0620 => 0600] as $from => $to) {
            chmod($env, $from);
            clearstatcache(true, $env);
            $this->setRunningInConsole(false);
            $changed = $this->tightenEnvMode();
            clearstatcache(true, $env);

            $this->assertSame($to, fileperms($env) & 0777, sprintf('%04o -> %04o', $from, $to));
            $this->assertSame($from === $to ? 0 : 1, $changed, sprintf('%04o changed', $from));
        }
    }

    public function test_from_the_console_the_mode_stays(): void
    {
        $this->requirePosix();
        $env = $this->ownEnv();
        chmod($env, 0664);
        clearstatcache(true, $env);

        $this->setRunningInConsole(true);
        $this->assertSame(0, $this->tightenEnvMode());
        clearstatcache(true, $env);
        $this->assertSame(0664, fileperms($env) & 0777);

        $this->assertArrayNotHasKey(base_path('.env'), (new EnvironmentRepairer())->filePermissionPlan(true, false)['modes']);
    }

    private function requirePosix(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_geteuid')) {
            $this->markTestSkipped('POSIX file modes (Linux / macOS): run on the round 1b box.');
        }
    }

    /** A .env of the test's own (the tree's real one stays untouched), owned by this PHP user. */
    private function ownEnv(): string
    {
        $this->directory = str_replace('\\', '/', storage_path('framework/testing/env-mode-'.uniqid()));
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->directory.'/.env', "APP_NAME=Test\n");
        $this->app->useEnvironmentPath($this->directory);

        return $this->directory.'/.env';
    }

    private function tightenEnvMode(): int
    {
        $method = new ReflectionMethod(StorageHider::class, 'tightenEnvMode');

        return (int) $method->invoke(new StorageHider());
    }

    /** Whether the application counts as a console process (null: as PHP says). */
    private function setRunningInConsole(?bool $value): void
    {
        $property = new ReflectionProperty($this->app, 'isRunningInConsole');
        $property->setValue($this->app, $value);
    }
}
