<?php

namespace Tests\Feature;

use App\Http\Middleware\Language;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Support\BlockedAddresses;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

/**
 * Judge R1-050 (the part round 2a found open): the API's own refusals answer in the caller's language, as its other
 * answers do (Content-Language). The limits ("Too many tries. Wait 33 seconds, then try again."), the page token check
 * of the site's own scripts (419) and Blocked addresses (403) answered before the api group's Language middleware ran,
 * so always in the site's default language: the API's language is now resolved first.
 */
class ApiRefusalLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.limiter' => 'array', 'site.default_locale' => 'en', 'site.global_ip_blacklist' => '']);
        Cache::flush();
        BlockedAddresses::forget();
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_an_api_limit_answers_in_the_callers_language(): void
    {
        config(['site.search_rate_limit' => 1]);

        foreach ([
            'fr' => ['headers' => ['X-Locale' => 'fr'], 'query' => '', 'ip' => '198.51.100.31'],
            'ar' => ['headers' => [], 'query' => '&locale=ar', 'ip' => '198.51.100.32'],
            'de' => ['headers' => ['Referer' => url('/de/trend')], 'query' => '', 'ip' => '198.51.100.33'],
        ] as $locale => $call) {
            $client = $this->withServerVariables(['REMOTE_ADDR' => $call['ip']])->withHeaders($call['headers']);

            $client->getJson('/api/search?q=lang-probe'.$call['query'])->assertOk()->assertHeader('Content-Language', $locale);
            // a new request starts in the site's language (the test application keeps the last request's)
            app()->setLocale('en');
            $refused = $client->getJson('/api/search?q=lang-probe'.$call['query'])->assertStatus(429)->assertHeader('Retry-After');

            app()->setLocale($locale);
            $expected = RouteServiceProvider::waitMessage((int) $refused->headers->get('Retry-After'));
            app()->setLocale('en');

            $this->assertSame($expected, $refused->json('message'), $locale);
            $this->assertNotSame(RouteServiceProvider::waitMessage((int) $refused->headers->get('Retry-After')), $expected, $locale.': a translated sentence, not the English one');
            $refused->assertHeader('Content-Language', $locale);
            $this->flushHeaders();
        }
    }

    public function test_the_page_token_check_of_the_sites_scripts_answers_in_their_language(): void
    {
        Config::set('sanctum.stateful', ['localhost', 'localhost:8080']);
        Config::set('sanctum.middleware.validate_csrf_token', ApiLanguageCsrfCheck::class);
        $member = User::factory()->create();

        $refused = $this->actingAs($member)
            ->withHeaders(['Origin' => 'http://localhost', 'X-Locale' => 'fr'])
            ->postJson(route('me.clear-all'))
            ->assertStatus(419);

        app()->setLocale('fr');
        $expected = __('This page timed out. Reload it, then try again.');
        app()->setLocale('en');

        $this->assertNotSame(__('This page timed out. Reload it, then try again.'), $expected);
        $this->assertSame($expected, $refused->json('message'));
        $refused->assertHeader('Content-Language', 'fr');
    }

    public function test_a_blocked_address_is_refused_in_the_callers_language(): void
    {
        config(['site.global_ip_blacklist' => '203.0.113.0/24', 'site.api_rate_limit' => 1000]);

        $refused = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->withHeaders(['X-Locale' => 'ar'])
            ->getJson('/api/search?q=blocked-probe')->assertForbidden();

        app()->setLocale('ar');
        $expected = __('Access denied.');
        app()->setLocale('en');

        $this->assertSame($expected, $refused->json('message'));
        $this->assertNotSame('Access denied.', $expected);
    }

    public function test_the_api_language_is_resolved_before_every_middleware_that_can_answer(): void
    {
        $router = app('router');

        foreach (['search', 'subscribe', 'comments.store', 'videos.view', 'me.clear-all'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $resolved = array_map(
                fn ($middleware) => is_string($middleware) ? explode(':', $middleware)[0] : get_class($middleware),
                $router->gatherRouteMiddleware($route),
            );
            $language = array_search(Language::class, $resolved, true);
            $this->assertIsInt($language, $name);

            foreach ([EnsureFrontendRequestsAreStateful::class, \App\Http\Middleware\RefuseBlockedAddresses::class, ThrottleRequests::class, \App\Http\Middleware\Authenticate::class] as $answering) {
                foreach (array_keys($resolved, $answering, true) as $at) {
                    $this->assertLessThan($at, $language, $name.': the language before '.$answering);
                }
            }
        }
    }
}

/** Sanctum's page token check as on a real site (the framework skips it while the tests run). */
class ApiLanguageCsrfCheck extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}
