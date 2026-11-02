<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Round 2a R2A-030: the phone code check's limit (R1-164) was an inline "throttle:10,1,phone-verify": the 11th check within
 * a minute answered Laravel's bare "Too Many Attempts." (no translation anywhere), and the Account settings form got the
 * bare 429 page instead of the error under the code field. The two other inline limits ("Download my data": throttle:3,10;
 * the administrator's "Show the secret link": throttle:10,1) answered the same way and, without a prefix, even shared one
 * counter per member.
 *
 * Now every limit is a named one with the site's answer: "Too many tries. Wait 42 seconds, then try again." with
 * Retry-After, in the language the page would have had. Laravel runs its limiter before the web group's Language
 * middleware, so a member who reads the site in another language and sends a form to an address without the language
 * (Account settings, My videos) got that sentence in the site's default language from every web limit: the answer now
 * takes the address's language, else the one this browser chose (the session), as that middleware decides it.
 */
class MemberLimitsAnswerInTheirLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.default_locale' => 'en',
            'cache.limiter' => 'array',
            'services.brevo.api_key' => 'lane-cx-test-key-never-used',
        ]);
        Cache::flush();
        unset($_SERVER['DETECTED_LOCALE']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);

        parent::tearDown();
    }

    public function test_every_limit_is_a_named_one(): void
    {
        $inline = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && preg_match('/^throttle:\d/', $middleware) === 1) {
                    $inline[] = $route->getName().' '.$middleware;
                }
            }
        }

        $this->assertSame([], $inline, 'an inline limit answers Laravel\'s bare "Too Many Attempts." in English');
        $this->assertContains('throttle:phone-verify', Route::getRoutes()->getByName('phone.verify')->gatherMiddleware());
        $this->assertContains('throttle:member-export', Route::getRoutes()->getByName('user.export')->gatherMiddleware());
        $this->assertContains('throttle:admin-secret-reveal', Route::getRoutes()->getByName('admin.settings.maintenance.reveal')->gatherMiddleware());
    }

    public function test_the_phone_code_check_limit_answers_a_script_in_the_members_language(): void
    {
        $member = $this->memberWaitingForACode();

        for ($n = 1; $n <= 10; $n++) {
            $this->actingAs($member)->withSession(['app_locale' => 'ar'])->postJson('/phone/verify', ['code' => '00000'])->assertStatus(422);
        }

        $this->aNewRequest();
        $refused = $this->actingAs($member)->withSession(['app_locale' => 'ar'])->postJson('/phone/verify', ['code' => '00000'])->assertStatus(429);
        $seconds = (int) $refused->json('retry_after');

        $this->assertGreaterThan(0, $seconds);
        $this->assertSame((string) $seconds, (string) $refused->headers->get('Retry-After'));
        $this->assertSame($this->inLanguage('ar', fn () => RouteServiceProvider::waitMessage($seconds)), $refused->json('message'));
        $this->assertNotSame('Too Many Attempts.', $refused->json('message'));

        // an English member: the English sentence with the wait, never Laravel's bare one
        $english = $this->memberWaitingForACode();
        for ($n = 1; $n <= 10; $n++) {
            $this->actingAs($english)->postJson('/phone/verify', ['code' => '00000']);
        }
        $this->aNewRequest();
        $refused = $this->actingAs($english)->postJson('/phone/verify', ['code' => '00000'])->assertStatus(429);
        $this->assertSame(RouteServiceProvider::waitMessage((int) $refused->json('retry_after')), $refused->json('message'));
    }

    public function test_the_phone_code_form_goes_back_to_the_code_field(): void
    {
        $member = $this->memberWaitingForACode();
        $page = route('user.edit');

        for ($n = 1; $n <= 10; $n++) {
            $this->actingAs($member)->withSession(['app_locale' => 'ar'])->from($page)->post('/phone/verify', ['code' => '00000']);
        }

        $this->aNewRequest();
        $refused = $this->actingAs($member)->withSession(['app_locale' => 'ar'])->from($page)->post('/phone/verify', ['code' => '00000']);
        $refused->assertRedirect($page);
        $refused->assertSessionHasErrors('code');
        $message = (string) session('errors')->first('code');
        $this->assertMatchesRegularExpression('/\d+/', $message);
        app()->setLocale('ar');
        $this->assertSame(RouteServiceProvider::waitMessage((int) preg_replace('/\D+/', '', $message)), $message, 'the Arabic sentence with the wait');
        app()->setLocale('en');

        // the page then shows it under the code field, which is marked invalid (the browser lands on /ar/profile/edit:
        // public/index.php hands the address over without its language and sets DETECTED_LOCALE)
        $_SERVER['DETECTED_LOCALE'] = 'ar';
        $html = (string) $this->actingAs($member)->withSession(['app_locale' => 'ar'])->get($page)->assertOk()->getContent();
        $this->assertStringContainsString('id="phone-otp"', $html);
        $this->assertStringContainsString('aria-invalid="true" aria-describedby="code-error"', $html);
        $this->assertStringContainsString(e($message), $html);
    }

    public function test_download_my_data_counts_downloads_and_answers_in_the_members_language(): void
    {
        $member = User::factory()->create();

        // The menu's link has no language in it: for a member who reads the site in French the Language middleware first
        // sends the browser to /fr/profile/export. That hop is not a download and is not counted.
        for ($n = 1; $n <= 5; $n++) {
            $this->actingAs($member)->withSession(['app_locale' => 'fr'])->get('/profile/export')->assertRedirect('/fr/profile/export');
        }

        $_SERVER['DETECTED_LOCALE'] = 'fr';
        app()->setLocale('fr');
        for ($n = 1; $n <= 3; $n++) {
            $this->actingAs($member)->withSession(['app_locale' => 'fr'])->get('/profile/export')->assertOk();
        }

        $this->aNewRequest();
        $refused = $this->actingAs($member)->withSession(['app_locale' => 'fr'])->from('/profile/edit')->get('/profile/export');
        $refused->assertRedirect('/profile/edit');
        // a window of 10 minutes: "Wait 10 minutes" (whole minutes over a minute), in French
        $this->assertSame(RouteServiceProvider::waitMessage(self::secondsIn((string) session('error')) * 60), session('error'));
        $this->assertNotSame('Too Many Attempts.', session('error'));

        $this->aNewRequest();
        $json = $this->actingAs($member)->withSession(['app_locale' => 'fr'])->getJson('/profile/export')->assertStatus(429);
        $this->assertSame(RouteServiceProvider::waitMessage((int) $json->json('retry_after')), $json->json('message'));
        app()->setLocale('en');
    }

    public function test_a_refused_download_never_goes_back_to_the_download(): void
    {
        $member = User::factory()->create();

        // Laravel keeps every page opened as the page to go back to, a download too: without a Referer (a privacy
        // extension, a strict Referrer-Policy) back() went to the download again, which was refused again: a loop.
        for ($n = 1; $n <= 3; $n++) {
            $this->actingAs($member)->get('/profile/export')->assertOk();
        }
        $this->assertStringEndsWith('/profile/export', (string) session()->previousUrl());

        // then Account settings, in the section with the button: "Delete account" while members may delete their account
        $this->aNewRequest();
        $refused = $this->actingAs($member)->get('/profile/export');
        $refused->assertRedirect(url('/profile/edit').'?section=delete');
        $this->assertSame(RouteServiceProvider::waitMessage(self::secondsIn((string) session('error')) * 60), session('error'));

        // with the page it came from, back there as before
        $this->aNewRequest();
        $this->actingAs($member)->from('/profile/edit?section=security')->get('/profile/export')->assertRedirect('/profile/edit?section=security');

        // the download as the Referer is not a page to go back to either
        $this->aNewRequest();
        $this->actingAs($member)->from(url('/profile/export'))->get('/profile/export')->assertRedirect(url('/profile/edit').'?section=delete');

        // a site where members cannot delete their account has the button in "Sign-in and security"
        config(['site.allow_account_deletion' => false]);
        $this->aNewRequest();
        $this->actingAs($member)->withSession(['_previous' => ['url' => url('/profile/export'), 'route' => 'user.export']])
            ->get('/profile/export')->assertRedirect(url('/profile/edit').'?section=security');
    }

    public function test_a_refused_download_never_goes_back_to_the_download_in_a_sub_folder(): void
    {
        // A site in a sub folder (APP_URL .../site, the front controller /site/index.php: Laravel's base URL "/site"):
        // the remembered download keeps the folder in its address (round 2 review R2CX-REV-01: the browser was sent
        // back to the download, which was refused again, until it gave up).
        config(['app.url' => 'http://localhost/site']);
        $inFolder = ['SCRIPT_NAME' => '/site/index.php', 'PHP_SELF' => '/site/index.php', 'SCRIPT_FILENAME' => public_path('index.php')];
        $download = 'http://localhost/site/profile/export';
        $settings = 'http://localhost/site/profile/edit?section=delete';
        $member = User::factory()->create();

        for ($n = 1; $n <= 3; $n++) {
            $this->actingAs($member)->withServerVariables($inFolder)->get($download)->assertOk();
        }
        $this->assertSame($download, (string) session()->previousUrl());

        $this->aNewRequest();
        $refused = $this->actingAs($member)->withServerVariables($inFolder)->get($download);
        $refused->assertRedirect($settings);
        $this->assertSame(RouteServiceProvider::waitMessage(self::secondsIn((string) session('error')) * 60), session('error'));

        // a session from before Laravel stored the page's route name: the address alone tells it is the download
        $this->aNewRequest();
        $this->actingAs($member)->withServerVariables($inFolder)->withSession(['_previous' => ['url' => $download]])
            ->get($download)->assertRedirect($settings);

        // the download as the Referer, with and without /index.php after the folder
        foreach ([$download, 'http://localhost/site/index.php/profile/export'] as $referer) {
            $this->aNewRequest();
            $this->actingAs($member)->withServerVariables($inFolder)->from($referer)->get($download)->assertRedirect($settings);
        }

        // another page of the site in the folder: back there
        $this->aNewRequest();
        $this->actingAs($member)->withServerVariables($inFolder)->from('http://localhost/site/profile/edit?section=security')
            ->get($download)->assertRedirect('http://localhost/site/profile/edit?section=security');
    }

    public function test_the_secret_link_limit_answers_in_the_administrators_language(): void
    {
        $admin = User::factory()->admin()->create();
        $last = null;

        for ($n = 1; $n <= 11; $n++) {
            $this->aNewRequest();
            $last = $this->actingAs($admin)->withSession(['app_locale' => 'de'])
                ->postJson(route('admin.settings.maintenance.reveal'), ['password' => 'not-the-password']);
        }

        $last->assertStatus(429);
        $this->assertSame($this->inLanguage('de', fn () => RouteServiceProvider::waitMessage((int) $last->json('retry_after'))), $last->json('message'));
        $this->assertNotSame('Too Many Attempts.', $last->json('message'));
    }

    public function test_the_other_member_limits_answer_in_the_session_language_too(): void
    {
        // My videos' bulk visibility (round 1b R1B-026): posted to /profile/videos/mass-visibility, without the language.
        $member = User::factory()->create();

        for ($n = 1; $n <= 10; $n++) {
            $this->actingAs($member)->withSession(['app_locale' => 'ar'])->postJson(route('user.videos.mass-visibility'), []);
        }

        $this->aNewRequest();
        $refused = $this->actingAs($member)->withSession(['app_locale' => 'ar'])->postJson(route('user.videos.mass-visibility'), [])->assertStatus(429);
        $this->assertSame($this->inLanguage('ar', fn () => RouteServiceProvider::waitMessage((int) $refused->json('retry_after'))), $refused->json('message'));

        // the sign-in form's limit (a guest whose browser chose Vietnamese; the form posts to /login)
        $this->app['auth']->forgetGuards();
        for ($n = 1; $n <= 5; $n++) {
            $this->withSession(['app_locale' => 'vi'])->from('/login')->post('/login', ['username' => 'nobody', 'password' => 'wrong-password']);
        }
        $this->aNewRequest();
        $this->withSession(['app_locale' => 'vi'])->from('/login')->post('/login', ['username' => 'nobody', 'password' => 'wrong-password'])->assertRedirect('/login');
        $this->assertSame($this->inLanguage('vi', fn () => RouteServiceProvider::waitMessage(self::secondsIn((string) session('error')))), session('error'));
    }

    /**
     * The application of a test lives on between its requests, the language a request left included; a real request
     * starts in the site's default language (or the address's, which public/index.php applies before the kernel).
     */
    private function aNewRequest(): void
    {
        app()->setLocale($_SERVER['DETECTED_LOCALE'] ?? 'en');
    }

    private function memberWaitingForACode(): User
    {
        $member = User::factory()->create();
        $member->forceFill(['phone' => '+3361234'.random_int(1000, 9999), 'phone_verified_at' => null, 'phone_confirmation_code' => '54321'])->save();

        return $member->fresh();
    }

    private function inLanguage(string $locale, \Closure $make): string
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $make();
        } finally {
            app()->setLocale($previous);
        }
    }

    private static function secondsIn(string $message): int
    {
        return (int) preg_replace('/\D+/', '', $message) ?: 1;
    }
}
