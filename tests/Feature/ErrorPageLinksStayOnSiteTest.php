<?php

namespace Tests\Feature;

use App\Services\SeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Round 2a R2A-005: the error pages' "Try again" (the 503 page of the owner's maintenance mode, the 500 and 429 pages) is
 * built from the address the visitor opened, and the 419 page's "Go back" from the page the browser came from, both with
 * locale_url(). Round 1 made "Try again" a locale_url() link, and locale_url() dropped a first language segment and kept
 * the empty segment after it: /en/vi//evil.example/x gave href="//evil.example/x", another site (a middle click, "open in
 * a new tab", a long press or a browser without JavaScript followed it): a link to anywhere on the site's own error page.
 * Round 1b (R1B-013) dropped the empty segments after a language segment; a backslash ("/\evil.example", which every
 * browser reads as "//evil.example") and a tab or line break ("/<tab>/evil.example") still led off the site.
 *
 * One rule now for every link the site builds from an address and for the language switch's return page
 * (locale_path_parts()): an absolute address keeps only its path, query and fragment; tabs and line breaks are dropped
 * and a backslash is a slash, as browsers read them; empty segments go; "/index.php" and the language segment in front
 * go in either order. The answer is always a path of this site.
 */
class ErrorPageLinksStayOnSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.default_locale' => 'en',
            'app.debug' => false,
            'cache.limiter' => 'array',
        ]);
        Cache::flush();
        unset($_SERVER['DETECTED_LOCALE']);
    }

    /** @return list<string> the addresses of round 1b's open-redirect batteries and round 2a's, and their relatives */
    public static function addresses(): array
    {
        $bs = chr(92);

        return [
            // the request URI after public/index.php took the first language segment off (round 2a judge, SEC-R1)
            '/vi//evil.example/x', '/vi///evil.example/x', '/fr//evil.example', '/en//evil.example', '/ar//evil.example/%2e%2e',
            '/de/'.$bs.'evil.example', '/vi/%2F%2Fevil.example', '/es//evil.example/post', '//evil.example/x', '///evil.example',
            '////evil.example/x', '/'.$bs.'evil.example', '/'.$bs.$bs.'evil.example', $bs.$bs.'evil.example', $bs.'/evil.example',
            '/'.$bs.'/evil.example', '/vi/'.$bs.'evil.example', '/vi/'.$bs.$bs.'evil.example', '/vi/'.$bs.'/evil.example',
            '/vi//'.$bs.'evil.example', '/fr/'.$bs.'/'.$bs.'/evil.example',
            // tabs, line breaks, spaces, NUL (browsers drop tab, CR and LF anywhere in an address)
            "/\t/evil.example", "/vi/\t/evil.example", "/\n/evil.example", "/vi/\r\n/evil.example", "/vi/\t".$bs.'evil.example',
            ' //evil.example', "\t//evil.example", "/vi/\0/evil.example", '/vi/ //evil.example',
            // absolute addresses (the 419 page's previous page, a Referer)
            'https://evil.example/x', 'https://evil.example/vi//evil.example/from-referer', 'http://localhost//evil.example/x',
            'http://localhost/vi//evil.example/x', 'https://evil.example//evil2.example/x', 'http://localhost/'.$bs.'evil.example',
            'http://localhost/vi/'.$bs.$bs.'evil.example', 'https://user:pass@evil.example/x', 'HTTPS://EVIL.EXAMPLE//x',
            'http:///evil.example', 'http:/'.$bs.'evil.example', 'https:evil.example/x', 'javascript:alert(1)', 'data:text/html,x',
            'evil.example/x',
            // /index.php in front (a site without clean addresses), dots, queries and fragments
            '/index.php//evil.example/x', '/index.php/vi//evil.example/x', '/vi/index.php//evil.example/x', '/index.php'.$bs.'evil.example',
            '/index.php/'.$bs.'evil.example', '/vi/..//evil.example', '/vi/%2e%2e//evil.example', '/vi?x=//evil.example',
            '/vi#//evil.example', '/?//evil.example', '/vi//evil.example/'.str_repeat('a', 2100),
        ];
    }

    public function test_a_link_built_from_any_address_is_a_page_of_this_site(): void
    {
        foreach ($this->contexts() as $context => [$locale, $indexPhp]) {
            config(['app.clean_url_reason' => 'test', 'app.clean_url_fallback' => $indexPhp]);
            app()->setLocale($locale);

            foreach (self::addresses() as $address) {
                $this->assertOnSite(locale_url($address), $context.' locale_url('.json_encode($address).')');
            }
        }

        app()->setLocale('en');
    }

    public function test_the_language_links_carry_a_page_of_this_site(): void
    {
        foreach ($this->contexts() as $context => [$locale, $indexPhp]) {
            config(['app.clean_url_reason' => 'test', 'app.clean_url_fallback' => $indexPhp]);
            app()->setLocale($locale);

            foreach (self::addresses() as $address) {
                $link = locale_switch_url('de', $address);
                parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
                $this->assertOnSite((string) ($query['redirect'] ?? ''), $context.' locale_switch_url('.json_encode($address).')');
            }
        }

        app()->setLocale('en');
    }

    public function test_ordinary_addresses_keep_their_form(): void
    {
        $cases = [
            // [language, address, locale_url()]
            ['en', '/', '/'],
            ['fr', '/', '/fr'],
            ['en', '', '/'],
            ['en', '/history', '/history'],
            ['fr', '/history', '/fr/history'],
            ['en', '/vi/trend', '/trend'],
            ['vi', '/fr/trend?x=1#c', '/vi/trend?x=1#c'],
            ['en', '/search?q=a%20b&page=2', '/search?q=a%20b&page=2'],
            ['fr', '/search?q=a', '/fr/search?q=a'],
            ['fr', '/?page=2', '/fr?page=2'],
            ['en', '/@Channel', '/@Channel'],
            ['ar', '/vi/%D8%A7%D8%AE%D8%AA%D8%A8%D8%A7%D8%B1', '/ar/%D8%A7%D8%AE%D8%AA%D8%A8%D8%A7%D8%B1'],
            ['en', 'http://localhost/login?redirect=%2Fhistory', '/login?redirect=%2Fhistory'],
            ['fr', 'https://example.com/fr/video/a-b?t=5#c1', '/fr/video/a-b?t=5#c1'],
            ['en', '/index.php', '/index.php'],
            ['fr', '/index.php', '/index.php/fr'],
            ['en', '/index.php/fr/history', '/index.php/history'],
            ['vi', '/vi/index.php/trend', '/index.php/vi/trend'],
            // the trailing slash goes, as the language switch and public/index.php's own redirect do
            ['en', '/trend/', '/trend'],
            ['fr', '/vi/trend/', '/fr/trend'],
            // empty segments go
            ['en', '/video/a//b', '/video/a/b'],
        ];

        foreach ($cases as [$locale, $address, $expected]) {
            app()->setLocale($locale);
            $this->assertSame($expected, locale_url($address), $locale.' '.$address);
        }

        app()->setLocale('en');
        $this->assertSame('/update-locale?locale=fr&redirect=%2Fhistory%3Fpage%3D2', locale_switch_url('fr', '/vi/history?page=2#x'));
        $this->assertSame('/update-locale?locale=fr&redirect=%2F', locale_switch_url('fr', '/update-locale?locale=vi&redirect=/x'));
        config(['app.clean_url_reason' => 'test', 'app.clean_url_fallback' => true]);
        $this->assertSame('/index.php/update-locale?locale=fr&redirect=%2Findex.php%2Ftrend', locale_switch_url('fr', '/index.php/vi/trend/'));
        $this->assertSame('/index.php/fr/trend', (function (): string {
            app()->setLocale('fr');

            return locale_url('/trend');
        })());
        app()->setLocale('en');
    }

    public function test_try_again_of_the_maintenance_page_stays_on_the_site(): void
    {
        config(['site.maintenance_mode' => true, 'site.maintenance_allow_admins' => true, 'site.maintenance_retry_after' => 3600]);
        $bs = chr(92);

        foreach (['/vi//evil.example/x', '/vi///evil.example/x', '/en//evil.example', '/vi/'.$bs.'evil.example/x', '/'.$bs.'evil.example/x', '/vi//'.$bs.'evil.example', '//evil.example/x'] as $address) {
            $response = $this->getRaw($address)->assertStatus(503);
            $this->assertOnSite($this->tryAgain($response), 'maintenance '.$address);
        }

        // an ordinary page keeps its address
        $this->assertSame('/trend', $this->tryAgain($this->get('/trend')->assertStatus(503)));
    }

    public function test_try_again_of_the_500_and_429_pages_stays_on_the_site(): void
    {
        $bs = chr(92);

        foreach ([500 => new RuntimeException('test failure'), 429 => new ThrottleRequestsException('Too Many Attempts.')] as $status => $failure) {
            $this->partialMock(SeoService::class, fn ($mock) => $mock->shouldReceive('resolveVideoPath')->andThrow($failure));

            foreach (['/vi//evil.example/x', '/fr//evil.example', '/vi/'.$bs.'evil.example/x', '/'.$bs.$bs.'evil.example', '//evil.example/x'] as $address) {
                $response = $this->getRaw($address)->assertStatus($status);
                $this->assertOnSite($this->tryAgain($response), $status.' '.$address);
            }
        }
    }

    public function test_go_back_of_the_419_page_stays_on_the_site(): void
    {
        $this->partialMock(SeoService::class, fn ($mock) => $mock->shouldReceive('resolveVideoPath')->andThrow(new TokenMismatchException('CSRF token mismatch.')));
        $bs = chr(92);

        foreach ([
            'http://localhost/vi//evil.example/x', 'http://localhost//evil.example/x', 'http://localhost/vi/'.$bs.'evil.example/x',
            'http://localhost/'.$bs.$bs.'evil.example', "http://localhost/vi/\t/evil.example", 'https://evil.example/vi//evil.example/x',
            'https://evil.example//evil.example', '/vi//evil.example/x', '//evil.example/x', 'http://localhost/index.php//evil.example/x',
        ] as $referer) {
            $response = $this->withHeaders(['Referer' => $referer])->get('/some-form-page')->assertStatus(419);
            $html = (string) $response->getContent();
            $this->assertSame(1, preg_match('#<a[^>]*href="([^"]*)"[^>]*onclick="if \(history\.length#', $html, $match), 'the Go back link: '.$referer);
            $this->assertOnSite(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), '419 Referer '.$referer);
        }
    }

    /** @return array<string, array{string, bool}> name => [current language, links with /index.php] */
    private function contexts(): array
    {
        return [
            'default language' => ['en', false],
            'other language' => ['fr', false],
            'index.php links, default language' => ['en', true],
            'index.php links, other language' => ['fr', true],
        ];
    }

    /**
     * A GET of $uri exactly as a web server hands it over (REQUEST_URI as sent; a backslash included, which Symfony's
     * request factory for tests refuses but a server passes on: the live probe of this lane met it on the 503 page).
     */
    private function getRaw(string $uri): TestResponse
    {
        $request = \Illuminate\Http\Request::create('/raw-uri-placeholder', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
        $request->server->set('REQUEST_URI', $uri);
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return TestResponse::fromBaseResponse($response, $request);
    }

    private function tryAgain(TestResponse $response): string
    {
        $html = (string) $response->getContent();
        $this->assertSame(1, preg_match('#<a[^>]*href="([^"]*)"[^>]*onclick="location\.reload#', $html, $match), 'the Try again link');

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }

    /**
     * $href, read the way a browser reads a link (tabs and line breaks dropped, C0 controls and spaces trimmed, a
     * backslash read as a slash), is a path of this site: one slash, then neither a slash nor a backslash.
     */
    private function assertOnSite(string $href, string $context): void
    {
        $read = trim(str_replace(["\t", "\n", "\r"], '', $href), "\x00..\x20");

        $this->assertMatchesRegularExpression('#^/(?![/\\\\])#', $read, $context.' -> '.json_encode($href));
        $this->assertStringNotContainsString(chr(92), $href, $context.' -> '.json_encode($href).': no backslash');
        $this->assertDoesNotMatchRegularExpression('#[\t\r\n]#', $href, $context.': no tab or line break');
        $this->assertStringNotContainsString('//', (string) parse_url('http://localhost'.$read, PHP_URL_PATH), $context.': no empty segment');
    }
}
