<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PreflightController;
use App\Support\AaPanelRewrite;
use App\Support\Environment;
use App\Support\EnvironmentRepairer;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Round 1b R1B-002 (the aaPanel rewrite writer stays consistent with the shipped configs): Tools > Health check's
 * "Auto-fix" wrote a bare try_files rule of its own into aaPanel's rewrite file (no upload-folder guards, no storage
 * location, no internal location for the videos). It now writes the same rules, with the same checks, as the root
 * index.php (App\Support\AaPanelRewrite), and says what it did in a line the Health check translates. The panel's
 * folders are a copy in a temporary folder; nginx itself is not there, so the rules are written and wait for a reload.
 */
class AaPanelRepairRulesTest extends TestCase
{
    private string $panel;

    /** @var array<string, mixed> */
    private array $server = [];

    /** @var list<string> the nginx commands the repair ran (a fake nginx: tests never touch a real one) */
    private array $commands = [];

    /** @var array{0: int, 1: list<string>} what the fake "nginx -t" answers */
    private array $nginxTest = [0, ['nginx: configuration file /www/server/nginx/conf/nginx.conf test is successful']];

    protected function setUp(): void
    {
        parent::setUp();

        $this->panel = str_replace('\\', '/', storage_path('framework/testing/aapanel-repair-'.bin2hex(random_bytes(5))));
        File::makeDirectory($this->panel.'/vhost/nginx', 0777, true);
        File::makeDirectory($this->panel.'/vhost/rewrite', 0777, true);
        file_put_contents($this->panel.'/vhost/nginx/this-site.test.conf', "server\n{\n    listen 80;\n    root ".str_replace('\\', '/', base_path()).";\n}\n");

        foreach (['SERVER_SOFTWARE', 'HOSTING_PANEL'] as $key) {
            $this->server[$key] = $_SERVER[$key] ?? null;
        }
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.24.0';
        $_SERVER['HOSTING_PANEL'] = 'aapanel';
        Environment::reset();
        AaPanelRewrite::$panelRoot = $this->panel;
        EnvironmentRepairer::$nginxRunner = function (string $command): array {
            $this->commands[] = $command;

            return str_starts_with($command, 'nginx -t') ? $this->nginxTest : [0, []];
        };
        $this->clearScanMarkers();
    }

    protected function tearDown(): void
    {
        // First: the copy of the panel's files goes even when a later line fails (the before-fix run on int-C1's code).
        if (isset($this->panel)) {
            File::deleteDirectory($this->panel);
        }

        foreach ($this->server as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        AaPanelRewrite::$panelRoot = '/www/server/panel';
        EnvironmentRepairer::$nginxRunner = null;
        Environment::reset();
        $this->clearScanMarkers();

        parent::tearDown();
    }

    public function test_the_auto_fix_writes_the_same_rules_as_the_root_index_php(): void
    {
        $this->assertSame(Environment::PANEL_AAPANEL, Environment::panel());
        file_put_contents($this->panel.'/vhost/rewrite/this-site.test.conf', '');

        $results = $this->repair();

        $rules = (string) file_get_contents($this->panel.'/vhost/rewrite/this-site.test.conf');
        $this->assertSame(AaPanelRewrite::rules(rtrim(str_replace('\\', '/', base_path()), '/'))."\n", $rules);
        $this->assertStringContainsString('location ^~ /_internal/ {', $rules);
        $this->assertStringContainsString('location ~* '.AaPanelRewrite::SCRIPTS.' { return 404; }', $rules);

        $this->assertSame(['nginx -t 2>&1', 'nginx -s reload 2>&1'], $this->commands, 'tested, then reloaded');
        $this->assertCount(1, $results);
        $this->assertSame('nginx.rewrite', $results[0]['scope']);
        $this->assertSame('success', $results[0]['severity']);
        $this->assertStringEndsWith(' and reloaded nginx.', $results[0]['message']);
        // A line of REPAIR_LINES_WITH_A_VALUE: shown in the admin's language with the file isolated.
        $this->assertStringContainsString('<bdi dir="ltr">'.e($this->panel.'/vhost/rewrite/this-site.test.conf').'</bdi>', (string) PreflightController::lineHtml($results[0]));
    }

    public function test_rules_nginx_refuses_are_taken_back_and_the_line_says_so(): void
    {
        file_put_contents($this->panel.'/vhost/rewrite/this-site.test.conf', '');
        $this->nginxTest = [1, ['nginx: [emerg] duplicate location "/storage/" in '.$this->panel.'/vhost/rewrite/this-site.test.conf:12']];

        $results = $this->repair();

        $this->assertSame('', file_get_contents($this->panel.'/vhost/rewrite/this-site.test.conf'), 'put back as it was');
        $this->assertSame(['nginx -t 2>&1'], $this->commands, 'never reloaded');
        $this->assertSame('warn', $results[0]['severity']);
        $this->assertStringStartsWith('nginx refused the rules, so the rewrite file was put back as it was: ', $results[0]['message']);
    }

    public function test_rules_somebody_wrote_stay_as_they_are(): void
    {
        $own = "location / {\n    try_files \$uri /index.php?\$query_string;\n}\nlocation /api { proxy_pass http://127.0.0.1:9000; }\n";
        file_put_contents($this->panel.'/vhost/rewrite/this-site.test.conf', $own);

        $this->assertSame([], $this->repair());
        $this->assertSame($own, file_get_contents($this->panel.'/vhost/rewrite/this-site.test.conf'));
    }

    /** @return list<array{scope: string, message: string, severity: string}> */
    private function repair(): array
    {
        $method = new \ReflectionMethod(EnvironmentRepairer::class, 'repairNginxRewrite');

        return $method->invoke((new \ReflectionClass(EnvironmentRepairer::class))->newInstanceWithoutConstructor());
    }

    private function clearScanMarkers(): void
    {
        foreach (glob(rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/avdbcms_aapanel_*') ?: [] as $marker) {
            @unlink($marker);
        }
    }
}
