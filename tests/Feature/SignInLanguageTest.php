<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/**
 * Judge R2A-018: a guest sent to sign in from a page under a language (/fr/history) keeps that language: the sign-in
 * page is /fr/login, the page to come back to is /fr/history, and the member lands there in French. Signing in never
 * replaces the member's saved language (users.language, the language of their e-mails) with the sign-in page's.
 *
 * public/index.php takes /fr off the address and keeps it in $_SERVER['DETECTED_LOCALE'] before Laravel sees the
 * request: the tests hand requests over the same way.
 */
class SignInLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.captcha_provider' => 'none',
            'site.login_rate_limit' => 1000,
            'site.default_locale' => 'en',
            'cache.limiter' => 'array',
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);
        app('url')->formatPathUsing(fn ($path) => $path);
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_a_guest_on_a_language_page_is_sent_to_the_sign_in_page_of_that_language(): void
    {
        foreach (['fr' => '/history', 'ar' => '/profile/notifications', 'de' => '/library'] as $locale => $page) {
            $this->flushSession();
            $_SERVER['DETECTED_LOCALE'] = $locale;

            $this->get($page.'?sort=new')->assertRedirect(url('/'.$locale.'/login'));
            $this->assertSame(url('/'.$locale.$page.'?sort=new'), session('url.intended'), $locale.': the page to come back to keeps its language');
        }
    }

    public function test_the_member_lands_on_the_page_in_its_language_and_keeps_the_saved_language(): void
    {
        $member = User::factory()->create(['password' => 'password', 'language' => 'de']);
        $_SERVER['DETECTED_LOCALE'] = 'fr';

        $this->get('/history')->assertRedirect(url('/fr/login'));
        $this->get('/login')->assertOk(); // /fr/login
        $this->post('/login', ['username' => $member->email, 'password' => 'password'])->assertRedirect(url('/fr/history'));

        $this->assertAuthenticatedAs($member);
        $this->assertSame('fr', session('app_locale'), 'this visit stays in the language of the page');
        $this->assertSame('de', $member->fresh()->language, 'the saved language (e-mails) is not the sign-in page\'s');
    }

    public function test_a_member_sent_to_the_plain_sign_in_page_keeps_their_language(): void
    {
        // The judge's case before the fix: a French member ended on the English /login and was switched to English.
        $member = User::factory()->create(['password' => 'password', 'language' => 'fr']);

        $this->get('/login')->assertOk();
        $this->post('/login', ['username' => $member->email, 'password' => 'password'])->assertRedirect('/fr/profile');

        $this->assertSame('fr', session('app_locale'), 'no language chosen on the sign-in page: the member\'s own');
        $this->assertSame('fr', $member->fresh()->language);
    }

    public function test_a_language_chosen_on_the_sign_in_page_is_used_for_the_visit_only(): void
    {
        $member = User::factory()->create(['password' => 'password', 'language' => 'fr']);

        // the visitor switched the plain site to Arabic (the session), then signs in on the plain address
        $this->withSession(['app_locale' => 'ar'])->post('/login', ['username' => $member->email, 'password' => 'password'])
            ->assertRedirect('/ar/profile');

        $this->assertSame('ar', session('app_locale'));
        $this->assertSame('fr', $member->fresh()->language);
    }

    public function test_a_page_of_the_site_language_is_remembered_as_before(): void
    {
        $member = User::factory()->create(['password' => 'password', 'language' => 'en']);

        $this->get('/history')->assertRedirect(route('login'));
        $this->assertSame(url('/history'), session('url.intended'));
        $this->post('/login', ['username' => $member->email, 'password' => 'password'])->assertRedirect(url('/history'));
        $this->assertSame('en', $member->fresh()->language);
    }

    public function test_a_browser_language_without_an_address_language_sends_to_its_sign_in_page(): void
    {
        // a plain address while this browser chose French: the sign-in page in French; the page is remembered as
        // Laravel remembers it (the Language middleware sends it to /fr after the sign-in)
        $this->withSession(['app_locale' => 'fr'])->get('/history')->assertRedirect(url('/fr/login'));
        $this->assertSame(url('/history'), session('url.intended'));
    }

    public function test_scripts_still_get_the_json_answer(): void
    {
        $_SERVER['DETECTED_LOCALE'] = 'fr';

        $this->getJson('/history')->assertUnauthorized();
        $this->assertNull(session('url.intended'));
    }

    public function test_a_language_picked_on_the_sign_in_page_after_the_bounce_is_the_landing_pages(): void
    {
        // review CA-RV-03: /fr/history -> /fr/login, the visitor picks German there (the language switch), signs in on
        // /de/login: /de/history in German, not the French page they left. The saved language stays as it was.
        $member = User::factory()->create(['password' => 'password', 'language' => 'en']);
        $_SERVER['DETECTED_LOCALE'] = 'fr';

        $this->get('/history?sort=new')->assertRedirect(url('/fr/login'));
        $this->get('/login')->assertOk(); // /fr/login
        $this->assertSame(url('/fr/history?sort=new'), session('url.intended'));

        unset($_SERVER['DETECTED_LOCALE']);
        $this->get('/update-locale?locale=de&redirect=%2Flogin')->assertRedirect();
        $this->assertSame('de', session('app_locale'), 'the language switch chose German for this browser');

        $_SERVER['DETECTED_LOCALE'] = 'de';
        $this->get('/login')->assertOk(); // /de/login
        $this->post('/login', ['username' => $member->email, 'password' => 'password'])->assertRedirect(url('/de/history?sort=new'));

        $this->assertAuthenticatedAs($member);
        $this->assertSame('de', session('app_locale'));
        $this->assertSame('en', $member->fresh()->language, 'the saved language (e-mails) is not the sign-in page\'s');
    }

    public function test_picking_the_sites_own_language_after_the_bounce_lands_on_the_plain_address(): void
    {
        $member = User::factory()->create(['password' => 'password', 'language' => 'fr']);
        $_SERVER['DETECTED_LOCALE'] = 'de';

        $this->get('/library')->assertRedirect(url('/de/login'));
        $this->assertSame(url('/de/library'), session('url.intended'));

        // the visitor switched the sign-in page to English (the site's language): the plain /login, the session's 'en'
        unset($_SERVER['DETECTED_LOCALE']);
        $this->withSession(['app_locale' => 'en'])->post('/login', ['username' => $member->email, 'password' => 'password'])
            ->assertRedirect(url('/library'));

        $this->assertSame('en', session('app_locale'));
        $this->assertSame('fr', $member->fresh()->language);
    }

    public function test_a_remembered_page_without_a_language_is_never_given_one(): void
    {
        // a plain address (here while the browser chose Arabic) stays plain: the Language middleware sends it on, and
        // the admin's pages never get a language
        $member = User::factory()->create(['password' => 'password', 'language' => 'en']);

        $this->withSession(['app_locale' => 'ar'])->get('/history')->assertRedirect(url('/ar/login'));
        $this->assertSame(url('/history'), session('url.intended'));
        $this->post('/login', ['username' => $member->email, 'password' => 'password'])->assertRedirect(url('/history'));
        $this->assertSame('ar', session('app_locale'));
    }

    public function test_a_social_sign_in_after_the_bounce_follows_the_language_picked_too(): void
    {
        config([
            'site.allow_social_login' => true,
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
            'services.google.redirect' => 'http://localhost/oauth/callback/google',
        ]);
        $member = User::factory()->create(['language' => 'en']);
        $_SERVER['DETECTED_LOCALE'] = 'fr';

        $this->get('/history')->assertRedirect(url('/fr/login'));
        $this->get('/login')->assertOk(); // /fr/login
        $this->assertSame(url('/fr/history'), session('url.intended'));

        // German picked on the sign-in page, then "Sign in with Google": the provider sends the browser back to the
        // callback, which the Language middleware moves to /de/oauth/callback/google
        $this->withSession(['app_locale' => 'de']);
        $_SERVER['DETECTED_LOCALE'] = 'de';
        Socialite::shouldReceive('driver->user')->andReturn((new SocialiteUser())->map([
            'id' => 'google-ca-rv-03',
            'email' => $member->email,
            'nickname' => null,
            'name' => 'Member',
            'avatar' => null,
        ]));

        $this->get('/oauth/callback/google')->assertRedirect(url('/de/history'));

        $this->assertAuthenticatedAs($member);
        $this->assertSame('de', session('app_locale'));
        $this->assertSame('en', $member->fresh()->language);
    }

    public function test_a_site_whose_links_keep_index_php_gets_both_addresses_in_its_own_form(): void
    {
        config(['app.clean_url_fallback' => true, 'app.clean_url_reason' => 'not_checked']);
        app('url')->formatPathUsing(function ($path) {
            if ($path === '' || $path === '/') {
                return '/index.php';
            }

            return str_starts_with($path, '/index.php') ? $path : '/index.php'.(str_starts_with($path, '/') ? $path : '/'.$path);
        });
        $_SERVER['DETECTED_LOCALE'] = 'fr';

        // the request as public/index.php hands it over (an absolute address: the test client would put /index.php in)
        $this->get(rtrim((string) config('app.url'), '/').'/history')->assertRedirect(url('/index.php/fr/login'));
        $this->assertSame(url('/index.php/fr/history'), session('url.intended'));

        // German picked on the sign-in page (review CA-RV-03): the page to come back to keeps /index.php
        $member = User::factory()->create(['password' => 'password', 'language' => 'en']);
        $_SERVER['DETECTED_LOCALE'] = 'de';
        $this->withSession(['app_locale' => 'de'])
            ->post(rtrim((string) config('app.url'), '/').'/login', ['username' => $member->email, 'password' => 'password'])
            ->assertRedirect(url('/index.php/de/history'));
    }
}
