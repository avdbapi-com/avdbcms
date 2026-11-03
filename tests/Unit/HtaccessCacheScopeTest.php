<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 1.2.3 refresh (N1-ROBOTS-01, CLF-CACHE-01): public/.htaccess had `ExpiresDefault "access plus 2 days"`, so Apache and
 * LiteSpeed gave every answer without an Expires header of its own - the pages made for one visitor (no-store, private),
 * robots.txt and the sitemaps - a second Cache-Control line (max-age=172800) and a two-day life in a CDN: a robots.txt
 * the site had just written reached crawlers behind Cloudflare up to two days later. mod_expires now names the static
 * kinds only (styles, scripts, fonts, pictures); HTML, robots.txt and the sitemaps carry the application's own
 * Cache-Control, and robots.txt as a file gets the five minutes its route gives it (mod_headers, <Files>).
 */
class HtaccessCacheScopeTest extends TestCase
{
    private const STATIC_TYPES = [
        'text/css', 'application/javascript', 'application/x-javascript', 'text/javascript',
        'font/woff2', 'font/woff', 'font/ttf', 'font/otf',
        'image/webp', 'image/avif', 'image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/x-icon',
    ];

    private static function read(string $relative): string
    {
        $text = file_get_contents(dirname(__DIR__, 2).'/'.$relative);
        self::assertIsString($text, $relative);

        return str_replace("\r\n", "\n", $text);
    }

    /** The directives without the comments. */
    private static function code(string $htaccess): string
    {
        return (string) preg_replace('/^\s*#[^\n]*\n?/m', '', $htaccess);
    }

    public function test_mod_expires_names_the_static_kinds_only_and_has_no_default(): void
    {
        $code = self::code(self::read('public/.htaccess'));

        $this->assertDoesNotMatchRegularExpression('/^\s*ExpiresDefault\b/m', $code, 'ExpiresDefault gave HTML, robots.txt and the sitemaps a two-day life');
        $this->assertMatchesRegularExpression('/^\s*ExpiresActive On\s*$/m', $code);

        preg_match_all('/^\s*ExpiresByType\s+(\S+)\s+"([^"]+)"/m', $code, $rules, PREG_SET_ORDER);
        $this->assertNotEmpty($rules);

        foreach ($rules as [, $type, $life]) {
            $this->assertContains($type, self::STATIC_TYPES, $type.' is no static kind of this site (its answer sets its own Cache-Control)');
            $this->assertMatchesRegularExpression('/^access plus (1 year|1 month)$/', $life, $type);
        }

        foreach (self::STATIC_TYPES as $type) {
            $this->assertStringContainsString('ExpiresByType '.$type.' ', $code, $type.' keeps its long life');
        }

        foreach (['text/html', 'text/plain', 'application/xml', 'text/xml', 'application/json'] as $type) {
            $this->assertStringNotContainsString('ExpiresByType '.$type, $code, $type.': the application decides');
        }
    }

    public function test_robots_txt_as_a_file_lives_five_minutes_like_its_route(): void
    {
        $code = self::code(self::read('public/.htaccess'));

        $this->assertMatchesRegularExpression(
            '/<Files "robots\.txt">\s*Header set Cache-Control "public, max-age=300"\s*<\/Files>/',
            $code,
            'the file the site writes is served by the web server before the route: the same five minutes'
        );

        // the route's answer (App\Http\Controllers\SeoController::robots) says the same
        $this->assertStringContainsString("'Cache-Control' => 'public, max-age=300'", self::read('app/Http/Controllers/SeoController.php'));
    }

    public function test_the_two_htaccess_files_stay_valid_for_apache_and_litespeed(): void
    {
        foreach (['public/.htaccess', '.htaccess'] as $file) {
            $code = self::code(self::read($file));

            // every module block is guarded, and every section closes
            foreach (['IfModule', 'FilesMatch', 'Files'] as $section) {
                $this->assertSame(
                    preg_match_all('/^\s*<'.$section.'[ >]/m', $code),
                    preg_match_all('/^\s*<\/'.$section.'>/m', $code),
                    $file.': every <'.$section.'> closes'
                );
            }

            foreach (['ExpiresActive', 'ExpiresByType', 'ExpiresDefault'] as $directive) {
                if (str_contains($code, $directive)) {
                    $this->assertMatchesRegularExpression('/<IfModule mod_expires\.c>.*?'.$directive.'.*?<\/IfModule>/s', $code, $file.': '.$directive.' inside the mod_expires guard');
                }
            }

            if (str_contains($code, 'Header ')) {
                $this->assertMatchesRegularExpression('/<IfModule mod_headers\.c>.*?Header .*?<\/IfModule>/s', $code, $file.': Header inside the mod_headers guard');
            }
        }

        // the root file (the shared-host fallback that forwards to public/) sets no life at all: public/.htaccess does
        $this->assertStringNotContainsString('Expires', self::code(self::read('.htaccess')));
    }
}
