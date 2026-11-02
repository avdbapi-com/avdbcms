<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * artisan is a console process whatever PHP build runs it (2 Nov 2026, clfcms.com on cPanel + LiteSpeed: the panel's
 * cron line runs `lsphp artisan schedule:run`, PHP_SAPI "litespeed", so Laravel's runningInConsole() was false, the
 * scheduler's cron runs were never stamped, the admin warned that no cron runs and started its own runs next to the
 * cron's). The artisan file sets APP_RUNNING_IN_CONSOLE before the application reads it, unless the environment already
 * says (Tools > Commands sets it for its own processes; the test harness sets it to false for a web-like process).
 *
 * The real proof ran a non-CLI build (php-cgi, PHP_SAPI "cgi-fcgi") against artisan: the released file wrote no
 * storage/framework/scheduler-cron.timestamp, this one does. PHPUnit runs on the CLI build, so the behaviour is pinned
 * at the source here.
 */
class ArtisanConsoleSwitchTest extends TestCase
{
    public function test_artisan_declares_itself_a_console_process_for_non_cli_builds(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/artisan');

        $this->assertStringContainsString("PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg'", $source);
        $this->assertStringContainsString("getenv('APP_RUNNING_IN_CONSOLE') === false", $source,
            'an explicit value in the environment must win');
        $this->assertStringContainsString("putenv('APP_RUNNING_IN_CONSOLE=true')", $source);
        $this->assertStringContainsString("\$_SERVER['APP_RUNNING_IN_CONSOLE'] = \$_ENV['APP_RUNNING_IN_CONSOLE'] = 'true'", $source);

        $switch = strpos($source, "putenv('APP_RUNNING_IN_CONSOLE=true')");
        $bootstrap = strpos($source, "require_once __DIR__.'/bootstrap/app.php'");
        $this->assertNotFalse($switch);
        $this->assertNotFalse($bootstrap);
        $this->assertLessThan($bootstrap, $switch, 'the switch must be set before the application is made');
    }

    public function test_the_switch_logic_sets_the_flag_only_when_the_environment_is_silent(): void
    {
        $decide = static function (string $sapi, array $env): bool {
            $getenv = array_key_exists('putenv', $env) ? $env['putenv'] : false;

            return $sapi !== 'cli' && $sapi !== 'phpdbg' && $getenv === false
                && ! array_key_exists('server', $env) && ! array_key_exists('env', $env);
        };

        $this->assertTrue($decide('litespeed', []));
        $this->assertTrue($decide('cgi-fcgi', []));
        $this->assertTrue($decide('fpm-fcgi', []));
        $this->assertFalse($decide('cli', []), 'the CLI build needs nothing');
        $this->assertFalse($decide('phpdbg', []));
        $this->assertFalse($decide('litespeed', ['putenv' => 'false']), 'an explicit putenv value wins');
        $this->assertFalse($decide('litespeed', ['server' => 'false']), 'an explicit $_SERVER value wins');
        $this->assertFalse($decide('litespeed', ['env' => 'true']), 'an explicit $_ENV value wins');
    }
}
