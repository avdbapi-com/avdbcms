<?php

namespace Tests\Feature;

use App\Http\Middleware\ThrottleExceptViewOnlyRefusals;
use App\Http\Middleware\ViewOnlyDemo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.3: the route limits ("throttle:...") leave a view-only demo account's refused requests to its guard
 * (App\Http\Middleware\ThrottleExceptViewOnlyRefusals, ViewOnlyDemo::answersItself()): such an account keeps getting
 * the refusal, with its "This is a demo" window, and never the site's "Too many tries" ("Download your data" allows
 * 3 in 10 minutes, a report 5 a minute). Everything that runs is limited exactly as before, for every account.
 *
 * Each test names the class for the "throttle" alias itself, as app/Http/Kernel.php does with the 1.2.3 change of its
 * alias (the same class: nothing changes once that is in).
 */
class ViewOnlyDemoRouteLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        // (without the class, before 1.2.3, Laravel's own limits answer: what these tests show is then missing)
        if (class_exists(ThrottleExceptViewOnlyRefusals::class)) {
            app('router')->aliasMiddleware('throttle', ThrottleExceptViewOnlyRefusals::class);
        }
        // Small limits, so a few requests reach them (each limit reads its setting on every request).
        config(['site.report_rate_limit' => 2, 'site.search_rate_limit' => 2]);
    }

    public function test_a_view_only_account_keeps_getting_its_refusal_past_a_route_limit(): void
    {
        $demo = $this->viewOnly(User::factory()->create());

        // "Download your data" (throttle:3,10), a closed page: every click goes back with the refusal.
        for ($click = 1; $click <= 5; $click++) {
            $this->asAccount($demo, ['Referer' => url('/profile/edit'), 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-User' => '?1'])
                ->get('/profile/export')
                ->assertStatus(303)
                ->assertRedirect('/profile/edit')
                ->assertHeader('X-View-Only-Demo-Refusal', 'closed')
                ->assertSessionHas('view_only_demo.window', 'closed');
        }

        // A report (throttle:reports, a named limit, 2 a minute here), a change: every one is refused with the window's
        // answer, none is told to wait.
        for ($report = 1; $report <= 4; $report++) {
            $this->asAccount($demo)->postJson('/api/report', ['id' => 1, 'type' => 'video', 'reason' => 'x'])
                ->assertForbidden()
                ->assertHeader('X-View-Only-Demo-Refusal', 'change')
                ->assertJsonPath('demo_window.kind', 'change');
        }
    }

    public function test_what_runs_is_limited_as_before_for_every_account(): void
    {
        $member = User::factory()->create();
        $demo = $this->viewOnly(User::factory()->create());

        // A member's export: 3 in 10 minutes, then "Too many tries" with the wait (the named limit "member-export" since
        // round 2a R2A-030: a page goes back with the message, a script gets 429).
        foreach ([200, 200, 200] as $status) {
            $this->asAccount($member)->get('/profile/export')->assertStatus($status);
        }
        $this->asAccount($member)->get('/profile/export')->assertRedirect()
            ->assertSessionHas('error', fn ($message): bool => is_string($message) && str_starts_with($message, 'Too many tries.'));
        $this->asAccount($member)->getJson('/profile/export')->assertStatus(429);

        // A search (throttle:search, a named limit): limited for both, the view-only account too (it runs).
        foreach ([$member, $demo] as $account) {
            foreach ([200, 200, 429] as $status) {
                $this->asAccount($account)->getJson('/api/search?q=night')->assertStatus($status);
            }
        }
    }

    public function test_limits_switched_off_by_laravels_own_class_stay_off(): void
    {
        $member = User::factory()->create();
        $this->withoutMiddleware(ThrottleRequests::class);

        for ($click = 1; $click <= 5; $click++) {
            $this->asAccount($member)->get('/profile/export')->assertOk();
        }
    }

    public function test_the_guard_answers_itself_exactly_the_requests_it_refuses(): void
    {
        $demo = $this->viewOnly(User::factory()->create());
        $member = User::factory()->create();

        $cases = [
            // [method, path, the guard answers it itself for a view-only account]
            ['GET', '/profile/export', true],          // closed (*.export)
            ['POST', '/api/report', true],             // a change
            ['GET', '/api/videos/upload18/config', true], // a GET that starts a change
            ['GET', '/api/search', false],             // a page or data the account may see
            ['GET', '/profile/edit', false],
            ['POST', '/logout', false],                // signing out passes (and is kept)
            ['POST', '/update-locale', false],         // the language switch passes
        ];

        foreach ($cases as [$method, $path, $refused]) {
            foreach ([[$demo, $refused], [$member, false]] as [$account, $expected]) {
                $request = Request::create($path, $method);
                $route = app('router')->getRoutes()->match($request);
                $this->assertInstanceOf(Route::class, $route);
                $request->setRouteResolver(fn () => $route);
                $request->setUserResolver(fn () => $account);

                $this->assertSame($expected, ViewOnlyDemo::answersItself($request), "{$method} {$path} ({$route->getName()}) for ".($account->is($demo) ? 'the view-only account' : 'a member'));
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    private function viewOnly(User $user): User
    {
        $user->forceFill([ViewOnlyDemo::COLUMN => true])->save();

        return $user->fresh();
    }

    /** The account, with only these headers on the next requests (withHeaders() alone keeps the earlier ones). */
    private function asAccount(User $user, array $headers = []): static
    {
        $this->flushHeaders();

        return $this->actingAs($user)->withHeaders($headers);
    }
}
