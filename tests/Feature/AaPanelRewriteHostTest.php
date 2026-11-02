<?php

namespace Tests\Feature;

use App\Support\AaPanelRewrite;
use App\Support\EnvironmentRepairer;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * R1-091 (round 1 judge): the root index.php chose the aaPanel rewrite file from the request's Host header and, since
 * 1.2.3, also overwrote a file holding aaPanel's laravel5 preset, then ran nginx -t and reload. A request routed to this
 * site with another site's Host (an absolute-form request line, a default or catch-all server block) could replace that
 * other Laravel site's rules (and empty files in 1.2.2). The installer's repair (EnvironmentRepairer) did the same.
 *
 * Now the site is the one whose aaPanel vhost file has this folder (or its public/) as its root, read from the panel's
 * own files; the Host header is never read. These tests build a copy of the panel's folders in a temporary folder.
 */
class AaPanelRewriteHostTest extends TestCase
{
    private const PRESET = "location / {\n\ttry_files \$uri \$uri/ /index.php\$is_args\$query_string;\n}\n";

    private string $tmp;

    private string $panel;

    private array $server = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = str_replace('\\', '/', storage_path('framework/testing/aapanel-'.bin2hex(random_bytes(5))));
        $this->panel = $this->tmp.'/panel';
        File::makeDirectory($this->panel.'/vhost/nginx/extension', 0777, true);
        File::makeDirectory($this->panel.'/vhost/rewrite', 0777, true);
        File::makeDirectory($this->tmp.'/wwwroot/our-folder/public', 0777, true);
        File::makeDirectory($this->tmp.'/wwwroot/victim.test', 0777, true);

        foreach (['HTTP_HOST', 'SERVER_NAME'] as $key) {
            $this->server[$key] = $_SERVER[$key] ?? null;
        }
        $this->clearScanMarkers();
    }

    protected function tearDown(): void
    {
        foreach ($this->server as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        AaPanelRewrite::$panelRoot = '/www/server/panel';
        $this->clearScanMarkers();
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    public function test_a_request_with_another_sites_host_never_touches_that_sites_rules(): void
    {
        $ours = $this->tmp.'/wwwroot/our-folder';
        $this->vhost('ours.test', $ours);
        $this->vhost('victim.test', $this->tmp.'/wwwroot/victim.test');
        $this->rewrite('ours.test', self::PRESET);
        $this->rewrite('victim.test', self::PRESET);
        $victimBefore = (string) file_get_contents($this->panel.'/vhost/rewrite/victim.test.conf');

        // The attack: this site is reached with the other site's Host.
        $_SERVER['HTTP_HOST'] = 'victim.test';
        $_SERVER['SERVER_NAME'] = 'victim.test';

        $commands = [];
        $result = AaPanelRewrite::apply($ours, $this->panel, function (string $command) use (&$commands): array {
            $commands[] = $command;

            return [0, []];
        });

        $this->assertSame('reloaded', $result);
        $this->assertSame($victimBefore, (string) file_get_contents($this->panel.'/vhost/rewrite/victim.test.conf'), 'the other site\'s rules stay byte for byte');
        $written = (string) file_get_contents($this->panel.'/vhost/rewrite/ours.test.conf');
        $this->assertStringStartsWith(AaPanelRewrite::MARK, $written);
        $this->assertStringContainsString('alias '.$ours.'/storage/app/public/;', $written);
        $this->assertSame(['nginx -t 2>&1', 'nginx -s reload 2>&1'], $commands);

        // Run again (every request): nothing more is written.
        $this->assertSame('kept', AaPanelRewrite::apply($ours, $this->panel, fn () => [0, []]));
    }

    public function test_a_folder_that_no_vhost_serves_is_left_alone_whatever_the_host(): void
    {
        $this->vhost('victim.test', $this->tmp.'/wwwroot/victim.test');
        $this->rewrite('victim.test', '');
        $_SERVER['HTTP_HOST'] = 'victim.test';

        $this->assertNull(AaPanelRewrite::siteFor($this->tmp.'/wwwroot/our-folder', $this->panel));
        $this->assertSame('no-site', AaPanelRewrite::apply($this->tmp.'/wwwroot/our-folder', $this->panel, fn () => $this->fail('nginx must not run')));
        $this->assertSame('', (string) file_get_contents($this->panel.'/vhost/rewrite/victim.test.conf'), 'an empty file of another site stays empty');
    }

    public function test_the_vhost_named_after_the_folder_or_serving_its_public_folder_is_found(): void
    {
        $ours = $this->tmp.'/wwwroot/our-folder';
        $this->vhost('our-folder', $ours.'/public');
        $this->assertSame('our-folder', AaPanelRewrite::siteFor($ours, $this->panel));

        // A commented-out root and the root of a server block for another folder do not count.
        File::delete($this->panel.'/vhost/nginx/our-folder.conf');
        $this->clearScanMarkers();
        file_put_contents($this->panel.'/vhost/nginx/decoy.test.conf', "server\n{\n    # root {$ours};\n    root {$this->tmp}/wwwroot/victim.test;\n}\n");
        $this->assertNull(AaPanelRewrite::siteFor($ours, $this->panel));

        // Quoted roots and a trailing slash are the same folder.
        $this->clearScanMarkers();
        file_put_contents($this->panel.'/vhost/nginx/quoted.test.conf', "server {\n    root \"{$ours}/\";\n}\n");
        $this->assertSame('quoted.test', AaPanelRewrite::siteFor($ours, $this->panel));
    }

    public function test_rules_that_nginx_refuses_are_taken_back(): void
    {
        $ours = $this->tmp.'/wwwroot/our-folder';
        $this->vhost('ours.test', $ours);
        $this->rewrite('ours.test', self::PRESET);

        $result = AaPanelRewrite::apply($ours, $this->panel, fn (string $command): array => [1, [
            'nginx: [emerg] duplicate location "/storage/" in '.$this->panel.'/vhost/nginx/extension/ours.test/x.conf:3',
            'nginx: configuration file /www/server/nginx/conf/nginx.conf test failed',
        ]]);

        $this->assertSame('restored', $result);
        $this->assertSame(self::PRESET, (string) file_get_contents($this->panel.'/vhost/rewrite/ours.test.conf'));
    }

    public function test_the_root_index_php_never_reads_the_host_header(): void
    {
        $index = (string) file_get_contents(base_path('index.php'));

        $this->assertStringNotContainsString('HTTP_HOST', $index);
        $this->assertStringNotContainsString('SERVER_NAME', $index);
        $this->assertStringContainsString('\\App\\Support\\AaPanelRewrite::apply($projectRoot, $aaPanelRoot);', $index);
    }

    public function test_the_installers_repair_takes_the_site_from_the_panels_files_only(): void
    {
        AaPanelRewrite::$panelRoot = $this->panel;
        $this->vhost('victim.test', $this->tmp.'/wwwroot/victim.test');
        $_SERVER['HTTP_HOST'] = 'victim.test';
        $_SERVER['SERVER_NAME'] = 'victim.test';

        $detect = new \ReflectionMethod(EnvironmentRepairer::class, 'detectSiteDomain');
        $repairer = (new \ReflectionClass(EnvironmentRepairer::class))->newInstanceWithoutConstructor();
        $this->assertSame('', $detect->invoke($repairer), 'the Host header names no site');

        $this->clearScanMarkers();
        $this->vhost('this-site.test', str_replace('\\', '/', base_path()));
        $this->assertSame('this-site.test', $detect->invoke($repairer));
    }

    private function vhost(string $name, string $root): void
    {
        file_put_contents($this->panel.'/vhost/nginx/'.$name.'.conf', implode("\n", [
            'server',
            '{',
            '    listen 80;',
            '    server_name '.$name.' www.'.$name.';',
            '    index index.php index.html index.htm default.php default.htm default.html;',
            '    root '.$root.';',
            '    include '.$this->panel.'/vhost/rewrite/'.$name.'.conf;',
            '    include '.$this->panel.'/vhost/nginx/extension/'.$name.'/*.conf;',
            '}',
            '',
        ]));
    }

    private function rewrite(string $name, string $content): void
    {
        file_put_contents($this->panel.'/vhost/rewrite/'.$name.'.conf', $content);
    }

    private function clearScanMarkers(): void
    {
        foreach (glob(rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/avdbcms_aapanel_*') ?: [] as $marker) {
            @unlink($marker);
        }
    }
}
