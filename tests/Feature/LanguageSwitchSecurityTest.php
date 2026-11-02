<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The language switch /update-locale (round 1b judge R1B-013 and R1B-025).
 *
 * R1B-013: it built the return address from ?redirect= (or the Referer) by dropping a first segment that is a language
 * and joining the rest, keeping the empty segment of "//": switching to the site's default language with
 * redirect=/vi//evil.example/x answered "Location: //evil.example/x" and the browser left the site (also %2F-encoded,
 * "///", a POST with the token and the Referer form). Now the address is always a page of THIS site: every encoded,
 * doubled-slash, backslash, control-character, foreign-host and scheme variant ends on this site.
 *
 * R1B-025: a GET (a link or an <img> on another site) saved the signed-in member's language in users.language, which
 * is also the language of their e-mails. Now a GET changes this browser's language only; the saved language changes on
 * a POST with the token (the account menu's switch, Account settings), never without the token.
 */
class LanguageSwitchSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'csrf-test-token-r1b025-0123456789abcdef0123';

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.default_locale' => 'en']);
    }

    /** @return array<string, array{string, string}> locale, ?redirect= value (as the server receives it) */
    public static function hostileTargets(): array
    {
        $bs = chr(92);

        return [
            'locale stripped, empty segment kept' => ['en', '/vi//evil.example/x'],
            'without a path' => ['en', '/fr//evil.example'],
            'encoded once' => ['en', '/de//evil.example/path?q=1'],
            'three slashes' => ['en', '/vi///evil.example'],
            'backslash after the language' => ['en', '/vi/'.$bs.'evil.example/x'],
            'two backslashes' => ['en', '/vi/'.$bs.$bs.'evil.example/x'],
            'encoded slashes decoded by the server' => ['en', '/vi///evil.example'],
            'double-encoded slashes' => ['en', '/vi/%2F%2Fevil.example'],
            'double-encoded backslash' => ['en', '/vi/%5Cevil.example'],
            'tab between the slashes' => ['en', "/vi/\t/evil.example"],
            'newline between the slashes' => ['en', "/vi/\n/evil.example"],
            'carriage return and newline' => ['en', "/vi/\r\n/evil.example"],
            'leading tab' => ['en', "\t//evil.example/x"],
            'protocol-relative' => ['en', '//evil.example/x'],
            'three leading slashes' => ['en', '///evil.example/x'],
            'slash backslash' => ['en', '/'.$bs.'evil.example/x'],
            'another site' => ['en', 'https://evil.example/x'],
            'scheme without slashes' => ['en', 'https:evil.example/x'],
            'scheme with one slash' => ['en', 'https:/evil.example/x'],
            'user info' => ['en', 'http://127.0.0.1@evil.example/x'],
            'dot segments' => ['en', '/vi/%2e%2e//evil.example'],
            'arabic then dots' => ['en', '/ar//evil.example/%2e%2e'],
            'index.php then empty segment' => ['en', '/index.php//evil.example/x'],
            'index.php, language, empty segment' => ['en', '/index.php/vi//evil.example/x'],
            'language, index.php, empty segment' => ['en', '/vi/index.php//evil.example/x'],
            'javascript' => ['en', 'javascript:alert(1)'],
            'data' => ['en', 'data:text/html,<script>alert(1)</script>'],
            'to another language' => ['vi', '/en//evil.example/x'],
            'to another language, three slashes' => ['vi', '/fr///evil.example/x'],
            'to another language, protocol-relative' => ['vi', '//evil.example'],
            'nul byte' => ['en', "/vi/\0/evil.example"],
            'space' => ['en', '/vi/ //evil.example'],
            'very long' => ['en', '/vi//evil.example/'.str_repeat('a', 2100)],
        ];
    }

    #[DataProvider('hostileTargets')]
    public function test_the_redirect_parameter_never_leaves_the_site(string $locale, string $target): void
    {
        $this->assertStaysOnSite($this->get('/update-locale?'.http_build_query(['locale' => $locale, 'redirect' => $target])), 'GET '.$target);
        $this->flushSession();
        $this->assertStaysOnSite($this->post('/update-locale', ['locale' => $locale, 'redirect' => $target]), 'POST '.$target);
    }

    #[DataProvider('hostileTargets')]
    public function test_the_referer_never_leaves_the_site(string $locale, string $target): void
    {
        $path = str_starts_with($target, '/') ? $target : '/'.$target;

        foreach ([$target, $this->site().$path, 'https://evil.example'.$path] as $referer) {
            $this->flushSession();
            $response = $this->withHeaders(['Referer' => $referer])->get('/update-locale?locale='.$locale);
            $this->assertStaysOnSite($response, 'Referer '.$referer);
        }
    }

    public function test_the_switch_keeps_the_page_its_query_and_its_fragment(): void
    {
        $site = $this->site();
        $cases = [
            ['fr', '/vi/trend', $site.'/fr/trend'],
            ['en', '/vi/trend', $site.'/trend'],
            ['vi', '/fr/video/a-b?t=3#c', $site.'/vi/video/a-b?t=3#c'],
            ['vi', '/categories/music?page=2', $site.'/vi/categories/music?page=2'],
            ['vi', '/', $site.'/vi'],
            ['en', '/vi', $site],
            ['vi', '/trend/', $site.'/vi/trend'],
            ['vi', '/login', $site.'/vi/login'],
            ['ar', '/vi/%D8%A7%D8%AE%D8%AA%D8%A8%D8%A7%D8%B1', $site.'/ar/%D8%A7%D8%AE%D8%AA%D8%A8%D8%A7%D8%B1'],
            // the /index.php form of an address (a site without clean addresses): the language after /index.php,
            // never "/<locale>/index.php" (R1B-007), the language of the address dropped wherever it stood
            ['vi', '/index.php/trend', $site.'/index.php/vi/trend'],
            ['en', '/index.php/vi/trend', $site.'/index.php/trend'],
            ['fr', '/vi/index.php/trend', $site.'/index.php/fr/trend'],
            ['vi', '/index.php', $site.'/index.php/vi'],
            ['en', '/index.php/vi', $site.'/index.php'],
            // the switch itself is no page to go back to
            ['vi', '/update-locale?locale=fr&redirect=/trend', $site.'/vi'],
            // an address of this site written in full
            ['vi', $site.'/fr/trend?x=1', $site.'/vi/trend?x=1'],
        ];

        foreach ($cases as [$locale, $target, $expected]) {
            $this->flushSession();
            $response = $this->get('/update-locale?'.http_build_query(['locale' => $locale, 'redirect' => $target]))->assertRedirect($expected);
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $target);
        }

        // No target: the page the browser came from (this site), else the language's home page.
        $this->flushSession();
        $this->withHeaders(['Referer' => $site.'/fr/trend?x=1'])->get('/update-locale?locale=vi')->assertRedirect($site.'/vi/trend?x=1');
        $this->flushSession();
        $this->flushHeaders();
        $this->get('/update-locale?locale=vi')->assertRedirect($site.'/vi');

        // A Referer of another site: its address is not used at all, the language's home page instead.
        $this->flushSession();
        $this->withHeaders(['Referer' => 'https://evil.example/fr/trend'])->get('/update-locale?locale=vi')->assertRedirect($site.'/vi');
        $this->flushHeaders();

        // An unknown language changes nothing and goes home.
        $this->flushSession();
        $this->get('/update-locale?locale=zz&redirect=/trend')->assertRedirect($site);
        $this->assertNull(session('app_locale'));
    }

    public function test_a_link_built_from_an_address_never_starts_with_two_slashes(): void
    {
        // The same cause in the site's link helper: locale_url() dropped the language segment and joined the rest with
        // its empty segment, so "/vi//evil.example/x" in the default language became "//evil.example/x" (the "Try again"
        // link of the 429 / 500 / 503 pages is built from the address the visitor opened).
        foreach (['en', 'vi'] as $locale) {
            app()->setLocale($locale);

            foreach (['/vi//evil.example/x', '/fr///evil.example', '/vi//evil.example', '/de//evil.example/x?y=1', '/index.php/vi//evil.example'] as $path) {
                $url = locale_url($path);
                $this->assertDoesNotMatchRegularExpression('#^/{2}#', $url, $locale.' '.$path.' -> '.$url);
                $this->assertStringStartsWith('/', $url, $locale.' '.$path);
            }

            $switch = locale_switch_url($locale === 'en' ? 'fr' : 'en', '/vi//evil.example/x');
            parse_str((string) parse_url($switch, PHP_URL_QUERY), $query);
            $this->assertDoesNotMatchRegularExpression('#^/{2}#', (string) ($query['redirect'] ?? ''), $switch);
        }

        app()->setLocale('en');
        $this->assertSame('/trend', locale_url('/vi/trend'));
        $this->assertSame('/vi/trend?x=1#c', (function (): string {
            app()->setLocale('vi');

            return locale_url('/fr/trend?x=1#c');
        })());
        app()->setLocale('en');
    }

    public function test_a_site_without_clean_addresses_keeps_index_php_first(): void
    {
        config(['app.clean_url_fallback' => true]);
        $site = $this->site();

        $this->get('/update-locale?'.http_build_query(['locale' => 'vi', 'redirect' => '/trend']))->assertRedirect($site.'/index.php/vi/trend');
        $this->flushSession();
        $this->get('/update-locale?'.http_build_query(['locale' => 'en', 'redirect' => '/vi/trend']))->assertRedirect($site.'/index.php/trend');
        $this->flushSession();
        $this->get('/update-locale?'.http_build_query(['locale' => 'en', 'redirect' => '/vi//evil.example/x']))->assertRedirect($site.'/index.php/evil.example/x');
    }

    public function test_a_get_changes_this_browsers_language_but_never_the_members_saved_language(): void
    {
        $member = User::factory()->create(['language' => 'en']);
        $other = User::factory()->create(['language' => 'de']);

        // A link or an <img> on another site: GET, no token.
        $this->actingAs($member)
            ->withHeaders(['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'image'])
            ->get('/update-locale?'.http_build_query(['locale' => 'vi', 'redirect' => '/']))
            ->assertRedirect($this->site().'/vi');

        $this->assertSame('vi', session('app_locale'), 'this browser shows the pages in the chosen language');
        $this->assertSame('en', $member->fresh()->language, 'the saved language (also of the e-mails) stays');
        $this->assertSame('de', $other->fresh()->language);

        // A guest's GET switches the pages as before.
        auth()->logout();
        $this->flushSession();
        $this->flushHeaders();
        $this->get('/update-locale?locale=fr&redirect=/trend')->assertRedirect($this->site().'/fr/trend');
        $this->assertSame('fr', session('app_locale'));
    }

    public function test_a_post_with_the_token_saves_the_members_language_and_one_without_is_refused(): void
    {
        $this->checkCsrfForReal();
        $member = User::factory()->create(['language' => 'en']);
        $other = User::factory()->create(['language' => 'de']);

        $this->actingAs($member)->post('/update-locale', ['locale' => 'vi', 'redirect' => '/trend'])->assertStatus(419);
        $this->assertSame('en', $member->fresh()->language, 'no token: nothing saved');

        $this->actingAs($member)->withSession(['_token' => self::TOKEN])
            ->post('/update-locale', ['_token' => self::TOKEN, 'locale' => 'vi', 'redirect' => '/trend'])
            ->assertRedirect($this->site().'/vi/trend');
        $this->assertSame('vi', $member->fresh()->language, 'the account menu\'s switch saves the language');
        $this->assertSame('vi', session('app_locale'));
        $this->assertSame('de', $other->fresh()->language, 'another member is never touched');

        // A guest's POST with the token switches the pages, nothing else.
        auth()->logout();
        $this->withSession(['_token' => self::TOKEN])
            ->post('/update-locale', ['_token' => self::TOKEN, 'locale' => 'fr', 'redirect' => '/'])
            ->assertRedirect($this->site().'/fr');
    }

    public function test_the_view_only_demo_account_switches_the_pages_and_keeps_nothing(): void
    {
        $this->checkCsrfForReal();
        $demo = User::factory()->create(['language' => 'en']);
        $demo->forceFill(['is_view_only' => true])->save();

        $this->actingAs($demo)->get('/update-locale?locale=vi&redirect=/trend')->assertRedirect($this->site().'/vi/trend');
        $this->assertSame('en', $demo->fresh()->language);

        $this->actingAs($demo)->withSession(['_token' => self::TOKEN])
            ->post('/update-locale', ['_token' => self::TOKEN, 'locale' => 'fr', 'redirect' => '/trend'])
            ->assertRedirect($this->site().'/fr/trend');
        $this->assertSame('fr', session('app_locale'));
        $this->assertSame('en', $demo->fresh()->language, 'what a view-only account writes is rolled back');
    }

    public function test_the_account_menus_language_switch_is_a_post_with_the_token(): void
    {
        config(['site.allow_language_switch' => true, 'site.sidebar_locale_switcher_enabled' => true]);
        $member = User::factory()->create();

        $html = (string) $this->actingAs($member)->get(route('user.edit'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#<form class="mk-account__pref" method="POST" action="'.preg_quote(e(route('lang.update')), '#').'">\s*<input type="hidden" name="_token" value="[^"]+"#',
            $html,
            'the account menu posts the language with the form\'s token'
        );
        $this->assertDoesNotMatchRegularExpression('#<form class="mk-account__pref" method="GET"#', $html);
    }

    private function checkCsrfForReal(): void
    {
        $this->app->instance(VerifyCsrfToken::class, new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    /** The site's own address (APP_URL of the tree the tests run in), without a trailing slash. */
    private function site(): string
    {
        return rtrim(url('/'), '/');
    }

    /** The Location a browser follows stays on this site (tabs and newlines dropped, a backslash read as a slash). */
    private function assertStaysOnSite(TestResponse $response, string $context): void
    {
        $this->assertTrue($response->isRedirection(), $context.': answered '.$response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        $cleaned = str_replace('\\', '/', (string) preg_replace('/[\t\n\r]/', '', $location));
        $site = $this->site();

        $this->assertDoesNotMatchRegularExpression('#^\s*/{2}#', $cleaned, $context.': protocol-relative '.$location);
        $this->assertTrue(
            $cleaned === $site || str_starts_with($cleaned, $site.'/') || str_starts_with($cleaned, $site.'?') || str_starts_with($cleaned, $site.'#'),
            $context.': '.$location.' is not on '.$site
        );
        $this->assertSame(parse_url($site, PHP_URL_HOST), parse_url($cleaned, PHP_URL_HOST), $context.': '.$location);
        $this->assertSame(parse_url($site, PHP_URL_PORT), parse_url($cleaned, PHP_URL_PORT), $context.': '.$location);
        $this->assertStringNotContainsString('evil.example', (string) parse_url($cleaned, PHP_URL_HOST), $context);
    }
}
