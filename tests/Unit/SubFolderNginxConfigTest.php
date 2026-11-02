<?php

namespace Tests\Unit;

use App\Support\AaPanelRewrite;
use PHPUnit\Framework\TestCase;

/**
 * Round 3 fix pass R2R-05: docs/server-configs/nginx-subfolder.conf, the nginx locations of a site installed in a folder
 * of its domain (https://example.com/site), says what the other nginx files of docs/server-configs/ say (ServerConfigsTest),
 * inside the folder:
 *  - the upload folders never run a script and never hand out a dump; /_internal/ (the video files nginx sends) is
 *    internal and aliased to storage/app/private; the site's own addresses that end like a file reach the application;
 *  - nginx's alias + try_files pitfall: the fallback is a named location (a plain "/site/index.php" fallback after an
 *    alias lost the folder: nginx opened <project>/publicindex.php, lane SB's box run);
 *  - the front controller runs as /site/index.php/... too and before the dump rule (an admin's backup download through
 *    /site/index.php/... is the application's), and no other script runs;
 *  - the header's recipe for a server without rewrite rules leaves no rewrite and keeps the front controller.
 * The requests through a real nginx (clean and without rewrite rules) are in lane SB's box runs.
 */
class SubFolderNginxConfigTest extends TestCase
{
    private const FILE = 'docs/server-configs/nginx-subfolder.conf';

    private static function config(): string
    {
        $text = file_get_contents(dirname(__DIR__, 2).'/'.self::FILE);
        self::assertIsString($text);

        return str_replace("\r\n", "\n", $text);
    }

    private static function code(string $config): string
    {
        return (string) preg_replace('/#[^\n]*/', '', $config);
    }

    public function test_it_holds_locations_of_the_folder_only_and_is_balanced(): void
    {
        $code = self::code(self::config());

        $this->assertSame(substr_count($code, '{'), substr_count($code, '}'));
        $this->assertStringNotContainsString('server {', $code, 'locations to paste into the domain\'s server block');
        $this->assertStringNotContainsString('root ', $code, 'the domain\'s own root stays as it is');
        $this->assertStringContainsString('__PROJECT_ROOT__', $code);

        preg_match_all('/(?:^|\s)location\s+(=|\^~|~\*?)?\s*(\S+)\s*\{/', $code, $matches, PREG_SET_ORDER);
        $this->assertNotEmpty($matches);

        foreach ($matches as [, $modifier, $path]) {
            $inFolder = $path === '/site' || str_starts_with($path, '/site/') || str_starts_with($path, '^/site/')
                || $path === '@avdbcms_site' || $path === '/_internal/' || $modifier === '~' || $modifier === '~*';
            $this->assertTrue($inFolder, 'a location outside the folder: '.$modifier.' '.$path);
        }

        $this->assertStringContainsString('location = /site { return 301 /site/$is_args$args; }', $code);
    }

    public function test_the_upload_folders_never_run_a_script_nor_hand_out_a_dump(): void
    {
        $code = self::code(self::config());

        foreach (['/site/storage/' => 'storage/app/public/', '/site/uploads/' => 'public/uploads/'] as $folder => $alias) {
            $block = (string) $this->block($code, 'location ^~ '.$folder.' {');
            $this->assertNotSame('', $block, $folder);
            $this->assertStringContainsString('alias __PROJECT_ROOT__/'.$alias.';', $block, $folder);
            $this->assertStringContainsString('location ~* '.AaPanelRewrite::SCRIPTS.' { return 404; }', $block, $folder);
            $this->assertStringContainsString('location ~* '.AaPanelRewrite::DUMPS.' { return 404; }', $block, $folder);
            $this->assertStringNotContainsString('fastcgi_pass', $block, $folder);
        }
    }

    public function test_the_video_files_location_is_internal_and_the_sites_own_file_like_addresses_reach_the_application(): void
    {
        $code = self::code(self::config());

        $this->assertMatchesRegularExpression('#location \^~ /_internal/ \{\s*internal;\s*alias __PROJECT_ROOT__/storage/app/private/;\s*\}#', $code);

        foreach (AaPanelRewrite::APPLICATION_PREFIXES as $prefix) {
            $this->assertMatchesRegularExpression('#location \^~ /site'.preg_quote($prefix, '#').' \{\s*rewrite \^ /site/index\.php last;\s*\}#', $code, $prefix);
        }
    }

    public function test_the_fallback_to_the_front_controller_is_a_named_location(): void
    {
        $code = self::code(self::config());
        $site = (string) $this->block($code, 'location ^~ /site/ {');

        $this->assertStringContainsString('alias __PROJECT_ROOT__/public/;', $site);
        $this->assertStringContainsString('try_files $uri $uri/ @avdbcms_site;', $site);
        $this->assertStringContainsString('try_files $uri @avdbcms_site;', (string) $this->block($code, 'location ^~ /site/storage/ {'));
        $this->assertMatchesRegularExpression('#location @avdbcms_site \{\s*rewrite \^ /site/index\.php last;\s*\}#', $code);
        $this->assertDoesNotMatchRegularExpression('#try_files[^;]*\s/site/index\.php#', $code, 'a plain fallback after an alias loses the folder');
    }

    public function test_only_the_front_controller_runs_and_it_comes_first(): void
    {
        $code = self::code(self::config());
        $site = (string) $this->block($code, 'location ^~ /site/ {');
        $front = (string) $this->block($site, 'location ~ ^/site/index\.php(/|$) {');

        $this->assertNotSame('', $front);
        $this->assertStringContainsString('fastcgi_split_path_info ^(/site/index\.php)(/.*)$;', $front);
        $this->assertStringContainsString('fastcgi_param SCRIPT_FILENAME __PROJECT_ROOT__/public/index.php;', $front);
        $this->assertStringContainsString('fastcgi_param PATH_INFO $fastcgi_path_info;', $front);
        $this->assertSame(1, substr_count($code, 'fastcgi_pass'), 'one script runs: the front controller');

        $dumps = strpos($site, 'location ~* '.AaPanelRewrite::DUMPS.' {');
        $dotfiles = strpos($site, 'location ~ '.AaPanelRewrite::DOTFILES.' { return 404; }');
        $scripts = strpos($site, 'location ~* '.AaPanelRewrite::SCRIPTS.' { return 404; }');
        $this->assertNotFalse($dumps);
        $this->assertNotFalse($dotfiles);
        $this->assertNotFalse($scripts);
        $this->assertLessThan($dumps, strpos($site, $front), 'an admin\'s backup download through /site/index.php/... is the application\'s');
        $this->assertLessThan($scripts, strpos($site, $front));
        $this->assertMatchesRegularExpression('#location ~\* '.preg_quote(AaPanelRewrite::DUMPS, '#').' \{\s*rewrite \^ /site/index\.php last;\s*\}#', $site);

        // The front controller's expression takes /site/index.php and /site/index.php/..., nothing else.
        foreach (['/site/index.php' => 1, '/site/index.php/fr/trend' => 1, '/site/index.php.bak' => 0, '/site/a/index.php' => 0, '/site/x.php' => 0] as $path => $match) {
            $this->assertSame($match, preg_match('#^/site/index\.php(/|$)#', $path), $path);
        }
    }

    public function test_the_headers_recipe_without_rewrite_rules_leaves_a_working_front_controller(): void
    {
        $config = self::config();
        $this->assertStringContainsString('"try_files $uri $uri/ =404;"', $config);
        $this->assertStringContainsString('"return 404;" instead of the rewrite', $config);
        $this->assertStringContainsString('"try_files $uri =404;"', $config);

        $variant = str_replace(['try_files $uri $uri/ @avdbcms_site;', 'try_files $uri @avdbcms_site;'], ['try_files $uri $uri/ =404;', 'try_files $uri =404;'], self::code($config));
        $variant = (string) preg_replace('#location (\^~ /site/(video/file|admin)/|@avdbcms_site) \{\s*rewrite \^ /site/index\.php last;\s*\}#', '', $variant, -1, $removed);
        $this->assertSame(3, $removed, 'the three locations that only rewrite');
        $variant = str_replace('rewrite ^ /site/index.php last;', 'return 404;', $variant, $dumps);
        $this->assertSame(1, $dumps, 'the dumps location');

        $this->assertStringNotContainsString('rewrite', $variant);
        $this->assertStringNotContainsString('@avdbcms_site', $variant);
        $this->assertStringContainsString('fastcgi_param SCRIPT_FILENAME __PROJECT_ROOT__/public/index.php;', $variant);
        $this->assertSame(substr_count($variant, '{'), substr_count($variant, '}'));
    }

    public function test_the_guides_name_it(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['COMMUNITY_SETUP_GUIDE.md', 'docs/server-configs/README.md'] as $guide) {
            $this->assertStringContainsString('nginx-subfolder.conf', (string) file_get_contents($root.'/'.$guide), $guide);
        }
    }

    public function test_the_sitemap_advice_gives_the_address_of_a_server_without_rewrite_rules_too(): void
    {
        // round 3 check SB-V1-02: on a folder site without rewrite rules /site/sitemap.xml is the web server's 404 page;
        // the site's sitemap is /site/index.php/sitemap.xml there (the installer prints the form it was opened by)
        $root = dirname(__DIR__, 2);

        foreach ([self::FILE, 'docs/server-configs/README.md', 'COMMUNITY_SETUP_GUIDE.md'] as $file) {
            $text = (string) file_get_contents($root.'/'.$file);
            $this->assertStringContainsString('https://example.com/site/sitemap.xml', $text, $file);
            $this->assertStringContainsString('https://example.com/site/index.php/sitemap.xml', $text, $file);
        }
    }

    /** The text of the block that starts with $opening (nested blocks included), null when there is none. */
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
