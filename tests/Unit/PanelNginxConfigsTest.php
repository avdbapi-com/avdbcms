<?php

namespace Tests\Unit;

use App\Support\AaPanelRewrite;
use PHPUnit\Framework\TestCase;

/**
 * Round 3 fix pass R3X-07 (the setup guide): two control panels put nginx in front of what runs PHP, and the guide's
 * steps for them could not work (lane SB's box tried each panel's vhost as the panel writes it: runs/box/panels/):
 *  - CloudPanel: nginx-project-root.conf in the FIRST server block rewrote /admin/... and /video/file/... to /index.php,
 *    which reaches the second block (PHP) as "/index.php": every admin page and every video stored on the server showed
 *    the home page; with the Document Root /public there were no steps at all (a script in public/storage/ ran, a dump in
 *    public/ was handed out, videos and backup downloads answered 404). docs/server-configs/nginx-cloudpanel-front.conf
 *    is the first block's part: no rewrite, the application's addresses passed on unchanged.
 *  - HestiaCP: nginx-project-root.conf as nginx.conf_avdbcms has a second "location /" (nginx -t refuses it) and the
 *    same rewrites behind Hestia's proxy. docs/server-configs/nginx-hestia-project-root.conf is the project-root file
 *    without them.
 * The other rules are the same as in the other nginx files (ServerConfigsTest).
 */
class PanelNginxConfigsTest extends TestCase
{
    private const FRONT = 'docs/server-configs/nginx-cloudpanel-front.conf';

    private const HESTIA = 'docs/server-configs/nginx-hestia-project-root.conf';

    private static function read(string $relative): string
    {
        $text = file_get_contents(dirname(__DIR__, 2).'/'.$relative);
        self::assertIsString($text, $relative);

        return str_replace("\r\n", "\n", $text);
    }

    private static function code(string $config): string
    {
        return (string) preg_replace('/#[^\n]*/', '', $config);
    }

    public function test_the_hestia_file_is_the_project_root_file_without_what_breaks_behind_hestias_proxy(): void
    {
        $projectRoot = $this->locations(self::read('docs/server-configs/nginx-project-root.conf'));
        $hestia = $this->locations(self::read(self::HESTIA));

        // Left out: the location / and the three rewrites to /index.php (they break behind Hestia's proxy), and the two
        // public/ upload folders (Hestia's server block has no root of its own: Apache answers them through the project
        // folder's .htaccess, which refuses scripts and dumps there).
        $left = array_values(array_filter($projectRoot, static fn (string $location): bool => ! in_array($location, [
            'location / { try_files $uri $uri/ /index.php?$query_string; }',
            'location ^~ /video/file/ { rewrite ^ /index.php last; }',
            'location ^~ /admin/ { rewrite ^ /index.php last; }',
            'location ~* '.AaPanelRewrite::DUMPS.' { rewrite ^ /index.php last; }',
        ], true) && ! str_starts_with($location, 'location ^~ /public/storage/ {') && ! str_starts_with($location, 'location ^~ /public/uploads/ {')));

        $this->assertCount(count($projectRoot) - 6, $left, 'the six left out are in the project-root file');
        $this->assertSame($left, $hestia);

        $code = self::code(self::read(self::HESTIA));
        $this->assertStringNotContainsString('location / {', $code);
        $this->assertDoesNotMatchRegularExpression('/(^|[\s;{])rewrite\s/', $code);
        $this->assertStringNotContainsString('gzip', $code);
        $this->assertSame(substr_count($code, '{'), substr_count($code, '}'));
        $this->assertSame(4, substr_count($code, '__PROJECT_ROOT__'));
    }

    public function test_the_cloudpanel_front_file_passes_the_applications_addresses_on_unchanged(): void
    {
        $code = self::code(self::read(self::FRONT));

        $this->assertDoesNotMatchRegularExpression('/(^|[\s;{])rewrite\s/', $code, 'a rewrite to /index.php loses the address on the way to PHP');
        $this->assertStringNotContainsString('location / {', $code, 'CloudPanel\'s own location / stays');
        $this->assertStringNotContainsString('fastcgi_pass', $code, 'PHP runs in the second block');
        $this->assertSame(substr_count($code, '{'), substr_count($code, '}'));
        $this->assertSame(3, substr_count($code, '__PROJECT_ROOT__'));

        foreach (array_merge(['/index.php/'], AaPanelRewrite::APPLICATION_PREFIXES) as $prefix) {
            $this->assertMatchesRegularExpression('#location \^~ '.preg_quote($prefix, '#').' \{\s*try_files /\.avdbcms-application @avdbcms_app;\s*\}#', $code, $prefix);
        }

        $app = (string) $this->block($code, 'location @avdbcms_app {');
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:8080;', $app);
        $this->assertStringContainsString('proxy_set_header Host $http_host;', $app);
        $this->assertStringContainsString('proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;', $app);
        $this->assertStringContainsString('proxy_max_temp_file_size 0;', $app, 'a big backup download is passed on, not stored');
    }

    public function test_the_cloudpanel_front_file_keeps_the_rules_of_the_other_nginx_files(): void
    {
        $code = self::code(self::read(self::FRONT));

        foreach (['/storage/' => 'storage/app/public/', '/uploads/' => 'public/uploads/'] as $folder => $alias) {
            $block = (string) $this->block($code, 'location ^~ '.$folder.' {');
            $this->assertStringContainsString('alias __PROJECT_ROOT__/'.$alias.';', $block, $folder);
            $this->assertStringContainsString('location ~* '.AaPanelRewrite::SCRIPTS.' { return 404; }', $block, $folder);
            $this->assertStringContainsString('location ~* '.AaPanelRewrite::DUMPS.' { return 404; }', $block, $folder);
        }

        $this->assertStringContainsString('try_files $uri @avdbcms_app;', (string) $this->block($code, 'location ^~ /storage/ {'), 'a missing upload: storage.fallback');
        $this->assertStringContainsString('alias __PROJECT_ROOT__/public/vendor/;', (string) $this->block($code, 'location ^~ /vendor/ {'));
        $this->assertStringContainsString('location ~* '.AaPanelRewrite::DUMPS.' { return 404; }', $code);
        $this->assertStringContainsString('location ~ '.AaPanelRewrite::DOTFILES.' { return 404; }', $code);

        foreach (AaPanelRewrite::CLOSED_FOLDERS as $folder) {
            $this->assertStringContainsString('location ^~ /'.$folder.'/ { return 404; }', $code, $folder);
        }

        foreach (AaPanelRewrite::CLOSED_FILES as $file) {
            $this->assertStringContainsString('location = /'.$file.' { return 404; }', $code, $file);
        }
    }

    public function test_the_guides_name_the_panel_files_and_no_longer_give_the_steps_that_broke(): void
    {
        $guide = self::read('COMMUNITY_SETUP_GUIDE.md');
        $readme = self::read('docs/server-configs/README.md');
        $projectRoot = self::read('docs/server-configs/nginx-project-root.conf');

        foreach ([$guide, $readme] as $text) {
            $this->assertStringContainsString('nginx-cloudpanel-front.conf', $text);
            $this->assertStringContainsString('nginx-hestia-project-root.conf', $text);
            $this->assertStringContainsString('Proxy Extensions', $text);
        }

        $this->assertStringNotContainsString('to **both** `server { }` blocks', $guide);
        $this->assertStringNotContainsString('add it to **both** server blocks', $readme);
        $this->assertStringNotContainsString('BOTH the frontend', $projectRoot);
        $this->assertStringContainsString('nginx-hestia-project-root.conf', $projectRoot);

        // Every nginx file with its "replace before use" step, in the guide and in the README.
        foreach (['nginx-public-root.conf', 'nginx-aapanel-rewrite.conf', 'nginx-project-root.conf', 'nginx-cloudpanel-front.conf',
            'nginx-hestia-project-root.conf', 'nginx-subfolder.conf'] as $file) {
            $this->assertMatchesRegularExpression('#\| [^\n]*'.preg_quote($file, '#').'[^\n]*\|[^\n]*\|[^\n]*(__PROJECT_ROOT__|/var/www/example\.com)#', $guide, $file);
            $this->assertMatchesRegularExpression('#\| [^\n]*'.preg_quote($file, '#').'[^\n]*\|[^\n]*\|[^\n]*(__PROJECT_ROOT__|/var/www/example\.com)#', $readme, $file);
        }
    }

    /**
     * The location blocks of a piece of nginx configuration, comments, blank lines and gzip lines aside, each block one
     * normalised string, in their order (as ServerConfigsTest reads them).
     *
     * @return list<string>
     */
    private function locations(string $config): array
    {
        $lines = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $config)) as $line) {
            $line = trim((string) preg_replace('/(^|\s)#.*$/', '', $line));

            if ($line !== '' && ! str_starts_with($line, 'gzip')) {
                $lines[] = $line;
            }
        }

        $text = implode(' ', $lines);
        $blocks = [];
        $offset = 0;

        while (($start = strpos($text, 'location ', $offset)) !== false) {
            $depth = 0;

            for ($i = $start, $length = strlen($text); $i < $length; $i++) {
                if ($text[$i] === '{') {
                    $depth++;
                } elseif ($text[$i] === '}' && --$depth === 0) {
                    break;
                }
            }

            $blocks[] = (string) preg_replace('/\s+/', ' ', substr($text, $start, $i - $start + 1));
            $offset = $i + 1;
        }

        return $blocks;
    }

    private function block(string $config, string $opening): ?string
    {
        $start = strpos($config, $opening);

        if ($start === false) {
            return null;
        }

        $depth = 0;

        for ($i = $start, $length = strlen($config); $i < $length; $i++) {
            if ($config[$i] === '{') {
                $depth++;
            } elseif ($config[$i] === '}' && --$depth === 0) {
                return substr($config, $start, $i - $start + 1);
            }
        }

        return null;
    }
}
