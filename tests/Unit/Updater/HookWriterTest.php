<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\HookTexts;
use App\Support\Updater\HookWriter;
use App\Support\Updater\PlanStore;
use Symfony\Component\Process\Process;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\TestCase;

/**
 * The maintenance hook file (F8 11): self-contained plain PHP (the embedded SwapEngine and MaintenanceHook, no
 * Laravel, no vendor/), written with Laravel's "down" file, safe to load twice in one process, and it takes itself
 * away when its swap is over. An owner's own `artisan down` is never touched.
 */
class HookWriterTest extends TestCase
{
    private UpdaterSandbox $box;

    protected function setUp(): void
    {
        parent::setUp();
        $this->box = new UpdaterSandbox();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['avdbcms_update_hook_probe']);
        $this->box->delete();
        parent::tearDown();
    }

    public function test_the_hook_is_plain_php_with_its_config_and_both_classes(): void
    {
        $code = (new HookWriter($this->box->paths()))->build($this->config('abc123'));

        token_get_all($code, TOKEN_PARSE);
        $this->assertStringStartsWith("<?php\n\n// ".HookWriter::MARKER.' (1.0.0 -> 1.0.1)', $code);
        $this->assertStringContainsString('namespace AvdbcmsUpdateHook\\Habc123 {', $code);
        $this->assertStringContainsString('final class SwapEngine', $code);
        $this->assertStringContainsString('final class MaintenanceHook', $code);
        $this->assertStringNotContainsString("\nuse ", $code, 'no imports: vendor/ may be half replaced');
        $this->assertStringNotContainsString('App\\Support', $code);
        $this->assertStringContainsString(hash('sha256', str_repeat('a', 64)), $code, 'the hash of the token, not the token');
        $this->assertStringNotContainsString(str_repeat('a', 64)."'", $code);
    }

    public function test_install_writes_the_hook_and_laravels_down_file_and_remove_takes_both_away(): void
    {
        $paths = $this->box->paths();
        $writer = new HookWriter($paths);
        $this->plan('p1');

        $writer->install($this->config('p1'));

        $this->assertTrue($writer->installed());
        $this->assertFalse($writer->foreignDown());
        $down = json_decode((string) file_get_contents($paths->down()), true);
        $this->assertSame(503, $down['status']);
        $this->assertSame(60, $down['retry']);
        $this->assertSame('p1', $down['avdbcms_update']);
        $this->assertSame([], glob(dirname($paths->hook()).'/*.tmp'), 'no temporary file left');

        $writer->remove();

        $this->assertFileDoesNotExist($paths->hook());
        $this->assertFileDoesNotExist($paths->down());
    }

    public function test_an_owners_artisan_down_is_never_touched(): void
    {
        $paths = $this->box->paths();
        $this->box->write([
            'storage/framework/down' => (string) json_encode(['except' => [], 'retry' => null, 'status' => 503, 'secret' => null]),
            'storage/framework/maintenance.php' => "<?php\n// Laravel's own stub\n",
        ]);
        $writer = new HookWriter($paths);

        $this->assertTrue($writer->foreignDown());
        $this->assertFalse($writer->installed());

        $writer->remove();

        $this->assertFileExists($paths->down());
        $this->assertFileExists($paths->hook());
    }

    public function test_the_hook_can_be_loaded_twice_in_one_process(): void
    {
        $paths = $this->box->paths();
        $this->plan('twice1');
        (new HookWriter($paths))->install($this->config('twice1'));

        $GLOBALS['avdbcms_update_hook_probe'] = true;
        $first = include $paths->hook();
        $second = include $paths->hook();

        $this->assertSame('AvdbcmsUpdateHook\\Htwice1\\MaintenanceHook', get_class($first));
        $this->assertInstanceOf(get_class($first), $second);
    }

    public function test_visitors_get_the_503_page_and_a_finished_swap_takes_the_hook_away(): void
    {
        $paths = $this->box->paths();
        $this->plan('v1');
        (new HookWriter($paths))->install($this->config('v1'));
        $GLOBALS['avdbcms_update_hook_probe'] = true;
        $hook = include $paths->hook();
        unset($GLOBALS['avdbcms_update_hook_probe']);

        $page = $hook->handle(['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'localhost'], [], []);

        $this->assertSame(503, $page['status']);
        $this->assertSame('60', $page['headers']['Retry-After']);
        $this->assertStringContainsString('This site is being updated. Please come back in a minute.', $page['body']);
        $this->assertStringNotContainsString('<script', $page['body']);

        $json = $hook->handle(['REQUEST_METHOD' => 'GET', 'HTTP_ACCEPT' => 'application/json'], [], []);
        $this->assertSame(503, $json['status']);
        $this->assertSame(['message' => 'This site is being updated. Please come back in a minute.'], json_decode($json['body'], true));

        // The plan moved on (the swap ended in another request): the next request goes through to the site.
        $this->plan('v1', 'swapped');
        $this->assertNull($hook->handle(['REQUEST_METHOD' => 'GET'], [], []));
        $this->assertFileDoesNotExist($paths->hook());
        $this->assertFileDoesNotExist($paths->down());
    }

    public function test_the_hook_file_runs_without_laravel(): void
    {
        $paths = $this->box->paths();
        $this->plan('cli1');
        (new HookWriter($paths))->install($this->config('cli1'));
        $runner = $this->box->path('runner.php');
        file_put_contents($runner, "<?php\n\$_SERVER['REQUEST_METHOD'] = 'GET';\n\$_SERVER['HTTP_HOST'] = 'localhost';\nrequire \$argv[1];\necho 'PASSED THROUGH';\n");

        $down = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', $runner, $paths->hook()], $this->box->base);
        $down->run();

        $this->assertSame(0, $down->getExitCode(), $down->getErrorOutput());
        $this->assertStringContainsString('This site is being updated. Please come back in a minute.', $down->getOutput());
        $this->assertStringNotContainsString('PASSED THROUGH', $down->getOutput());

        $this->plan('another-plan');
        $through = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', $runner, $paths->hook()], $this->box->base);
        $through->run();

        $this->assertSame('PASSED THROUGH', $through->getOutput(), $through->getErrorOutput());
        $this->assertFileDoesNotExist($paths->hook(), 'a hook whose plan is gone takes itself away');
    }

    private function plan(string $id, string $status = 'swapping'): void
    {
        PlanStore::write($this->box->paths()->plan(), ['id' => $id, 'status' => $status, 'kind' => 'update', 'swap' => ['done' => 0, 'total' => 3]]);
    }

    private function config(string $id): array
    {
        $paths = $this->box->paths();

        return [
            'plan_id' => $id,
            'plan' => $paths->plan(),
            'job' => $paths->job('1.0.1').'/job.json',
            'lock' => $paths->lock(),
            'log' => $paths->job('1.0.1').'/update.log',
            'hook' => $paths->hook(),
            'down' => $paths->down(),
            'token_hash' => hash('sha256', str_repeat('a', 64)),
            'token_expires' => time() + 600,
            'cookie_name' => 'avdbcms_update',
            'cookie_hash' => hash('sha256', str_repeat('b', 64)),
            'cookie_expires' => time() + 600,
            'origins' => ['http://localhost'],
            'kind' => 'update',
            'version' => '1.0.1',
            'from' => '1.0.0',
            'budget' => 20.0,
            'admin_url' => 'http://localhost/admin/updates',
            'help_url' => 'https://help.avdbapi.com/documentation/avdbcms-one-click-update/',
            'site' => 'Test <site>',
            'strings' => HookTexts::for('update', 'en'),
        ];
    }
}
