<?php

namespace Tests\Feature;

use App\Http\Middleware\RateLimit;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Support\EmailChangeBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The refusals the site's machinery answers with, in plain words of the member's language:
 *  - every limit says how long to wait ("Wait 42 seconds" / "Wait 10 minutes"), never "Too many attempts !";
 *  - a timed-out page token (419) of a member or a guest is not called an "admin session";
 *  - the 429 page ("Too many tries"): minutes for a long wait (not "Wait 594 seconds" under "Wait a minute"), and its
 *    "Try again" keeps the page's language.
 */
class SystemRefusalTextsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.limiter' => 'array', 'site.default_locale' => 'en']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_the_wait_is_said_in_seconds_or_in_minutes(): void
    {
        $this->assertSame('Too many tries. Wait 1 second, then try again.', RouteServiceProvider::waitMessage(1));
        $this->assertSame('Too many tries. Wait 42 seconds, then try again.', RouteServiceProvider::waitMessage(42));
        $this->assertSame('Too many tries. Wait 60 seconds, then try again.', RouteServiceProvider::waitMessage(60));
        $this->assertSame('Too many tries. Wait 2 minutes, then try again.', RouteServiceProvider::waitMessage(61));
        $this->assertSame('Too many tries. Wait 10 minutes, then try again.', RouteServiceProvider::waitMessage(594));
    }

    public function test_a_scripts_limit_answer_names_the_wait(): void
    {
        config(['site.search_rate_limit' => 1]);
        $client = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23']);

        $client->getJson('/api/search?q=wait-probe')->assertOk();
        $response = $client->getJson('/api/search?q=wait-probe')->assertStatus(429)->assertHeader('Retry-After');

        $this->assertSame(RouteServiceProvider::waitMessage((int) $response->headers->get('Retry-After')), $response->json('message'));
        $this->assertStringNotContainsString('Too many attempts', (string) $response->json('message'));
        $this->assertSame((int) $response->headers->get('Retry-After'), $response->json('retry_after'));
    }

    public function test_the_one_a_minute_buttons_name_the_wait(): void
    {
        $request = Request::create('/email/verification-notification', 'POST');
        $request->setLaravelSession($this->app['session.store']);
        $request->setRouteResolver(fn () => (new Route('POST', '/email/verification-notification', []))->name('verification.send')->bind($request));
        $middleware = new RateLimit();

        $first = $middleware->handle($request, fn () => response('sent'), 1);
        $this->assertSame('sent', $first->getContent());

        $second = $middleware->handle($request, fn () => response('sent'), 1);
        $this->assertTrue($second->isRedirect());
        $this->assertMatchesRegularExpression('/^Too many tries\. Wait \d+ seconds?, then try again\.$/', (string) session('error'));

        // a script gets JSON with the same sentence
        $json = Request::create('/email/verification-notification', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $json->setLaravelSession($this->app['session.store']);
        $json->setRouteResolver(fn () => (new Route('POST', '/email/verification-notification', []))->name('verification.send')->bind($json));
        $refused = $middleware->handle($json, fn () => response('sent'), 1);
        $this->assertSame(429, $refused->getStatusCode());
        $this->assertMatchesRegularExpression('/^Too many tries\. Wait \d+ seconds?, then try again\.$/', (string) json_decode((string) $refused->getContent(), true)['message']);
    }

    public function test_the_new_address_link_budget_says_when_it_frees_up(): void
    {
        $member = User::factory()->create(['temporary_email' => 'new-address@example.test']);
        for ($i = 0; $i < EmailChangeBudget::MAX; $i++) {
            EmailChangeBudget::take($member->id);
        }

        $this->actingAs($member)->fromRoute('user.edit')->post(route('verification.send.update'))->assertRedirectToRoute('user.edit');
        $message = (string) session('error');
        $this->assertMatchesRegularExpression('/^You asked for too many confirmation links\. Try again in \d+ minutes?\.$/', $message);
        RateLimiter::clear(EmailChangeBudget::key($member->id));
    }

    public function test_a_timed_out_token_is_not_an_admin_session_for_members_and_guests(): void
    {
        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);

        $member = Request::create('/api/like', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $answer = json_decode((string) $handler->render($member, new TokenMismatchException())->getContent(), true);
        $this->assertSame(__('This page timed out. Reload it, then try again.'), $answer['message']);
        $this->assertStringNotContainsString('admin', strtolower($answer['message']));

        $admin = Request::create('/admin/settings/save', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $answer = json_decode((string) $handler->render($admin, new TokenMismatchException())->getContent(), true);
        $this->assertSame(__('Your admin session token expired. Refresh the page and try the action again.'), $answer['message']);
    }

    public function test_the_429_page_says_minutes_for_a_long_wait_and_keeps_the_language(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member);

        // This page is the answer of a limit without an answer of its own (Laravel's). The site's own limits all answer
        // like its forms since round 2a R2A-030 ("Download my data", 3 in 10 minutes, goes back to the page with the wait in
        // the member's language: MemberLimitsAnswerInTheirLanguageTest), so a probe address carries such a limit here,
        // with the same numbers. (A POST: every GET address of the site is taken by the videos' catch-all route.)
        app('router')->middleware(['web', 'auth', 'throttle:3,10'])->post('/profile/limit-probe', fn () => 'ok');

        for ($i = 0; $i < 3; $i++) {
            $this->post('/profile/limit-probe')->assertOk();
        }
        // public/index.php takes /fr off the address and sets the language before the kernel runs (the limit answers
        // before the Language middleware does)
        $_SERVER['DETECTED_LOCALE'] = 'fr';
        app()->setLocale('fr');
        $response = $this->post('/profile/limit-probe')->assertStatus(429);
        $html = (string) $response->getContent();
        app()->setLocale('fr');
        $minutes = (int) ceil(((int) $response->headers->get('Retry-After')) / 60);
        $expected = trans_choice('{1} You did this many times in a short time. Wait :count minute, then try again.|[2,*] You did this many times in a short time. Wait :count minutes, then try again.', $minutes, ['count' => $minutes]);
        $title = __('Too many tries');
        app()->setLocale('en');

        $this->assertGreaterThan(1, $minutes);
        $this->assertStringContainsString(e($expected), $html);
        $this->assertStringContainsString(e($title), $html);
        $this->assertDoesNotMatchRegularExpression('/\b\d{3} (seconds|secondes)\b/', strip_tags($html));
        $this->assertMatchesRegularExpression('#<a class="mk-btn mk-btn--primary" href="/fr/profile/limit-probe"#', $html, 'Try again keeps /fr');
    }
}
