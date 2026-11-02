<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Judge R2A-050: the "This page timed out" page (419). Its "Go back" without JavaScript leads to the page the form was
 * on. A form that sends to its own address (sign in, sign up, contact, "Forgot password") was on that address, which
 * url()->previous() and url()->current() then both name: "Go back" led to the home page and the form was lost.
 */
class TimedOutPageGoBackTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'csrf-test-token-r2a050-0123456789abcdef012';

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.captcha_provider' => 'none', 'site.default_locale' => 'en', 'cache.limiter' => 'array', 'site.login_rate_limit' => 1000]);
        Cache::flush();
        $this->app->instance(VerifyCsrfToken::class, new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);
        app('url')->formatPathUsing(fn ($path) => $path);
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_a_form_that_sends_to_its_own_address_goes_back_to_it(): void
    {
        foreach (['/login', '/register', '/contact', '/forgot-password'] as $form) {
            $this->flushSession();
            $page = $this->withSession(['_token' => self::TOKEN])->from($form)->post($form, ['_token' => 'stale'])->assertStatus(419);

            $this->assertSame($form, $this->goBack($page), $form);
        }
    }

    public function test_without_a_referer_the_page_the_session_remembers_is_used(): void
    {
        $page = $this->withSession(['_token' => self::TOKEN, '_previous' => ['url' => url('/login')]])
            ->post('/login', ['_token' => 'stale'])->assertStatus(419);

        $this->assertSame('/login', $this->goBack($page));
    }

    public function test_a_form_sent_from_another_page_goes_back_to_that_page_as_before(): void
    {
        $page = $this->withSession(['_token' => self::TOKEN])->from('/trend?sort=new')->post('/logout', ['_token' => 'stale'])->assertStatus(419);

        $this->assertSame('/trend?sort=new', $this->goBack($page));
    }

    public function test_an_address_under_a_language_keeps_it(): void
    {
        $_SERVER['DETECTED_LOCALE'] = 'fr';
        app()->setLocale('fr'); // public/index.php, for /fr/login

        $page = $this->withSession(['_token' => self::TOKEN])->from('/fr/login')->post('/login', ['_token' => 'stale'])->assertStatus(419);

        $this->assertSame('/fr/login', $this->goBack($page));
    }

    public function test_a_site_whose_links_keep_index_php_goes_back_in_its_own_form(): void
    {
        config(['app.clean_url_fallback' => true, 'app.clean_url_reason' => 'not_checked']);
        app('url')->formatPathUsing(function ($path) {
            if ($path === '' || $path === '/') {
                return '/index.php';
            }

            return str_starts_with($path, '/index.php') ? $path : '/index.php'.(str_starts_with($path, '/') ? $path : '/'.$path);
        });
        $base = rtrim((string) config('app.url'), '/');

        // the request as public/index.php hands it over (an absolute address: the test client would put /index.php in)
        $page = $this->withSession(['_token' => self::TOKEN])->withHeader('Referer', $base.'/index.php/login')
            ->post($base.'/login', ['_token' => 'stale'])->assertStatus(419);

        $this->assertSame('/index.php/login', $this->goBack($page));
    }

    /** The no-JavaScript address of the page's "Go back" (its first button). */
    private function goBack(TestResponse $page): string
    {
        $this->assertMatchesRegularExpression('#<a class="mk-btn mk-btn--primary" href="([^"]*)" onclick="if \(history\.length#', (string) $page->getContent());
        preg_match('#<a class="mk-btn mk-btn--primary" href="([^"]*)" onclick="if \(history\.length#', (string) $page->getContent(), $match);

        return html_entity_decode($match[1], ENT_QUOTES);
    }
}
