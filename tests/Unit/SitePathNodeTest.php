<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Round 3 fix pass R3-018 (the comments' "Sign in" lacked /index.php on servers without rewrite rules: the web server's
 * 404 page) and R2R-05 (a site installed in a folder of its domain): one helper gives the start of every address the
 * site's scripts build (resources/js/functions/sitePath.ts: the folder, then /index.php where the site's links carry it,
 * printed by the page as window.SITE_BASE from the same decision as the server's links). tests/js/site_path.test.ts runs
 * it on its results in Node; this keeps the comments, the search box and the API calls on it.
 */
class SitePathNodeTest extends TestCase
{
    private static function source(string $relative): string
    {
        $text = file_get_contents(dirname(__DIR__, 2).'/'.$relative);
        self::assertIsString($text, $relative);

        return str_replace("\r\n", "\n", $text);
    }

    public function test_the_site_path_helper_passes_its_cases_in_node(): void
    {
        $node = $this->nodeBinary();

        if ($node === null) {
            $this->markTestSkipped('Node.js 22.18 or newer is needed to run tests/js/site_path.test.ts.');
        }

        $output = [];
        exec(escapeshellarg($node).' '.escapeshellarg(dirname(__DIR__, 2).'/tests/js/site_path.test.ts').' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertMatchesRegularExpression('/site path: \d+ passed, 0 failed/', implode("\n", $output));
    }

    public function test_the_comments_sign_in_links_come_from_the_helper(): void
    {
        $signIn = self::source('resources/js/components/Comments/signInUrl.ts');
        $this->assertStringContainsString('import {signInPath} from "@/functions/sitePath";', $signIn);
        // The page's own sign-in address first (the server's, like the header's link), else the same address built here.
        $this->assertStringContainsString('export const signInUrl = (): string => window.AVD_USER?.loginUrl || signInPath();', $signIn);
        $this->assertStringNotContainsString("'/login", $signIn);

        // Report comment used its own copy of the old rule.
        $report = self::source('resources/js/components/Comments/Actions/ReportComment.tsx');
        $this->assertStringContainsString('from "@/components/Comments/signInUrl";', $report);
        $this->assertStringNotContainsString('/login', $report);

        foreach (['resources/js/components/Comments/Comment.tsx', 'resources/js/components/Comments/Actions/AddComment.tsx'] as $file) {
            $source = self::source($file);
            $this->assertStringContainsString('href={signInUrl()}', $source, $file);
            $this->assertStringNotContainsString("'/login", $source, $file);
        }
    }

    public function test_the_search_box_and_the_api_calls_use_the_same_helper(): void
    {
        $search = self::source('resources/js/components/Search/SearchBar.tsx');
        $this->assertStringContainsString("const searchAction = searchurl || localePath('/search');", $search);
        $this->assertStringNotContainsString("startsWith('/index.php')", $search, 'a second copy of the rule');

        $api = self::source('resources/js/functions/api.ts');
        $this->assertStringContainsString('import {requestBase} from "@/functions/sitePath";', $api);
        $this->assertStringContainsString('targetUrl = requestBase() + targetUrl;', $api);
        $this->assertStringNotContainsString("'/index.php' + targetUrl", $api, 'a second copy of the rule');

        $config = self::source('resources/js/api/avdbclip/config.ts');
        $this->assertStringContainsString('requestBase()', $config);
        $this->assertStringContainsString("sitePath('/api')", $config);
    }

    public function test_the_page_prints_the_start_of_the_sites_addresses_before_its_scripts(): void
    {
        $layout = self::source('resources/views/components/layout.blade.php');
        $line = "window.SITE_BASE = {!! json_encode(site_folder() . (index_php_links() ? '/index.php' : ''), \$jsonScriptFlags) !!}";

        $this->assertStringContainsString($line, $layout);
        $this->assertLessThan(strpos($layout, 'window.API_BASE'), strpos($layout, $line));
        $this->assertStringContainsString('SITE_BASE?: string;', self::source('resources/js/global.d.ts'));
    }

    private function nodeBinary(): ?string
    {
        if (! function_exists('exec')) {
            return null;
        }

        $finder = PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null';
        $paths = [];
        @exec($finder, $paths);
        $node = trim((string) ($paths[0] ?? ''));

        if ($node === '' || ! is_file($node)) {
            return null;
        }

        $version = [];
        @exec(escapeshellarg($node).' -p "process.versions.node" 2>&1', $version);
        $parts = array_map('intval', explode('.', trim((string) ($version[0] ?? '0'))));

        return ($parts[0] ?? 0) > 22 || (($parts[0] ?? 0) === 22 && ($parts[1] ?? 0) >= 18) ? $node : null;
    }
}
