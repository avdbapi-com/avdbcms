<?php

namespace Tests\Feature;

use App\Http\Middleware\ViewOnlyDemo;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.3: where a view-only refusal sends the account (ViewOnlyDemo::back()). It took the
 * Referer, else the session's previous page, and skipped only the address itself. Laravel keeps every page opened as
 * the previous page, closed ones too: two closed pages opened with no open page between them (two tabs at the same
 * moment, a script) sent the account back and forth until the browser gave up (found by a
 * check of every closed page on 2026-10-25). Now a candidate the account cannot open is
 * skipped, Home is the fallback, and a page that sends it straight back to the same closed page is not used twice.
 *
 * The chains are followed as a browser does: every Location in turn, with the Referer of the first request (none for
 * a typed address), at most 10 hops.
 */
class ViewOnlyDemoBackTest extends TestCase
{
    use RefreshDatabase;

    private const CLOSED = 'This page is not part of the view-only demo: it would show private files or server details.';

    private const CHANGE = 'This is a view-only demo account: you can look at everything, but changes are switched off.';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['admin.ui' => 'v2', 'admin.operational_tools_enabled' => true]);
    }

    // ------------------------------------------------------------------ the loop

    public function test_two_closed_pages_opened_at_once_never_send_the_account_back_and_forth(): void
    {
        $demo = $this->viewOnlyAdmin();

        // another tab has just opened Backups (refused): the session keeps it as the previous page; this tab opens Logs.
        // 1.2.2: Logs > Backups > Logs > Backups ... until the browser gave up.
        $this->actingAs($demo)->withSession(['_previous' => ['url' => url('/admin/backups')]]);
        $first = $this->get('/admin/logs')->assertStatus(303)->assertSessionHasErrors(['view_only_demo' => self::CLOSED]);
        $chain = $this->follow($first);

        $this->assertSame(url('/admin'), $first->headers->get('Location'), 'never back to the other closed page');
        $this->assertSame(['/admin'], $chain['visited']);
        $this->assertSame(200, $chain['status']);
        $this->assertStringContainsString(self::CLOSED, $chain['content'], 'Home shows the refusal');
    }

    public function test_both_tabs_land_on_open_pages_whatever_order_the_requests_take(): void
    {
        $demo = $this->viewOnlyAdmin();
        $closed = ['/admin/backups', '/admin/logs', '/admin/database', '/admin/env-editor', '/admin/system', '/admin/audit'];

        foreach ($closed as $a) {
            foreach ($closed as $b) {
                if ($a === $b) {
                    continue;
                }

                // tab 1 opens A and tab 2 opens B; both requests reach the site before either redirect is followed
                $this->actingAs($demo)->withSession(['_previous' => ['url' => url('/admin/users')]]);
                $toA = $this->get($a)->assertStatus(303);
                $toB = $this->get($b)->assertStatus(303);

                foreach ([$a => $toA, $b => $toB] as $from => $refusal) {
                    $chain = $this->follow($refusal);
                    $this->assertSame(200, $chain['status'], "{$from}: ".implode(' > ', $chain['visited']));
                    $this->assertLessThanOrEqual(1, count($chain['visited']), "{$from}: one hop to an open page");
                    foreach ($chain['visited'] as $path) {
                        $this->assertNotContains($path, $closed, "{$from} went on to another closed page");
                    }
                }
            }
        }
    }

    public function test_an_open_page_that_sends_the_account_on_to_the_same_closed_page_does_not_loop(): void
    {
        // an open address that redirects to a closed page (none ships with AVDBCMS today; an old link of a plugin could)
        $this->routeFirst('lanea-vo/old-backups-link', fn () => redirect('/admin/backups'));
        $demo = $this->viewOnlyAdmin();

        $this->actingAs($demo);
        $start = $this->get('/lanea-vo/old-backups-link')->assertRedirect('/admin/backups');
        $chain = $this->follow($start);

        // Backups is refused; the old link (the previous page) has just redirected here and would do it again: Home
        $this->assertSame(['/admin/backups', '/admin'], $chain['visited']);
        $this->assertSame(200, $chain['status']);
        $this->assertStringContainsString(self::CLOSED, $chain['content']);

        // slower than any time limit (a slow host): still no loop, the redirect itself is what counts
        $start = $this->get('/lanea-vo/old-backups-link')->assertRedirect('/admin/backups');
        $this->travel(30)->seconds();
        $this->assertSame(['/admin/backups', '/admin'], $this->follow($start)['visited']);
    }

    public function test_a_link_that_redirects_to_a_closed_page_goes_back_to_the_page_the_link_was_on(): void
    {
        // the person clicked the old link on Members: the refusal goes back to Members, not Home
        $this->routeFirst('lanea-vo/old-backups-link', fn () => redirect('/admin/backups'));
        $this->actingAs($this->viewOnlyAdmin());

        $this->withHeaders(['Referer' => url('/admin/users'), 'Sec-Fetch-User' => '?1']);
        $this->get('/lanea-vo/old-backups-link')->assertRedirect('/admin/backups');
        $this->get('/admin/backups')->assertStatus(303)->assertRedirect('/admin/users');
        $this->get('/admin/users')->assertOk()->assertSee(self::CLOSED);

        // and a page view in between forgets the redirect: the closed page itself is just refused back to Members
        $this->get('/admin/backups')->assertRedirect('/admin/users');
    }

    // ------------------------------------------------------------------ the candidates

    public function test_a_refusal_never_sends_the_account_to_a_page_it_cannot_open(): void
    {
        $demo = $this->viewOnlyAdmin();
        $this->actingAs($demo);

        // [the Referer, the previous page, where it goes]
        $cases = [
            'a closed Referer, an open previous page' => [url('/admin/logs'), url('/admin/videos'), url('/admin/videos')],
            'a closed page behind /index.php' => [url('/index.php/admin/env-editor'), '', url('/admin')],
            'a GET that starts a change (payment)' => ['', url('/subscribe/1'), url('/admin')],
            'the same, with a language prefix' => [url('/vi/subscribe/1'), '', url('/admin')],
            'signing out' => ['', url('/logout'), url('/admin')],
            'another site' => ['https://example.org/admin/users', '', url('/admin')],
            'an open page behind /index.php is kept as given' => [url('/index.php/admin/users'), '', url('/index.php/admin/users')],
            'an open page with a language prefix is kept as given' => [url('/vi/trend'), '', url('/vi/trend')],
        ];

        foreach ($cases as $why => [$referer, $previous, $expected]) {
            $this->withSession(['_previous' => ['url' => $previous]]);
            $response = $this->get('/admin/backups', $referer !== '' ? ['Referer' => $referer] : [])->assertStatus(303);
            $this->assertSame($expected, $response->headers->get('Location'), $why);
            $this->assertTrue(auth()->check(), "{$why}: still signed in");
        }
    }

    public function test_the_page_it_came_from_is_still_where_it_goes(): void
    {
        $demo = $this->viewOnlyAdmin();
        $this->actingAs($demo);

        // the side menu (Referer) and a typed address (the previous page, no Referer)
        $this->from('/admin/users')->get('/admin/backups')->assertStatus(303)->assertRedirect('/admin/users');
        $this->flushHeaders();
        $this->withSession(['_previous' => ['url' => url('/admin/comments')]])
            ->get('/admin/database')->assertStatus(303)->assertRedirect('/admin/comments');

        // a change refused on an open page goes back to that page
        $member = User::factory()->create();
        $this->from('/admin/users')->post(route('admin.users.ban', $member))->assertStatus(303)->assertRedirect('/admin/users')
            ->assertSessionHasErrors(['view_only_demo' => self::CHANGE]);
        $this->assertNull($member->fresh()->banned_at);
    }

    public function test_a_person_clicking_the_same_closed_page_again_stays_on_their_page(): void
    {
        // a click: the browser says "Sec-Fetch-User: ?1". The second click right after reading the message still goes back
        // to the page the person was on (1.2.2's first fix sent a click within 15 s Home).
        $this->actingAs($this->viewOnlyAdmin());
        $click = ['Sec-Fetch-User' => '?1'];

        $this->from('/admin/users')->get('/admin/backups', $click)->assertRedirect('/admin/users');
        $this->from('/admin/users')->get('/admin/backups', $click)->assertRedirect('/admin/users');
        $this->travel(1)->seconds();
        $this->from('/admin/users')->get('/admin/backups', $click)->assertRedirect('/admin/users');
    }

    public function test_a_script_that_opens_the_same_closed_page_again_at_once_goes_home(): void
    {
        // no click (no Sec-Fetch-User): a script on the page it went back to opens the closed page again right away
        $this->actingAs($this->viewOnlyAdmin());

        $this->from('/admin/users')->get('/admin/backups')->assertRedirect('/admin/users');
        $this->from('/admin/users')->get('/admin/backups')->assertRedirect('/admin');
        // later it is an ordinary visit again
        $this->travel(ViewOnlyDemoBackTest::bounceSeconds() + 1)->seconds();
        $this->from('/admin/users')->get('/admin/backups')->assertRedirect('/admin/users');
        // another closed page right after is no bounce
        $this->from('/admin/users')->get('/admin/logs')->assertRedirect('/admin/users');
        $this->assertLessThanOrEqual(3, ViewOnlyDemoBackTest::bounceSeconds(), 'only a request that follows almost at once');
    }

    public function test_a_prefetch_is_no_visit(): void
    {
        // the side menu prefetches Backups on hover; the click that follows is refused back to the same page
        $demo = $this->viewOnlyAdmin();
        $this->actingAs($demo);

        $this->from('/admin/users')->get('/admin/backups', ['Sec-Purpose' => 'prefetch'])->assertRedirect('/admin/users');
        $this->from('/admin/users')->get('/admin/backups')->assertRedirect('/admin/users');
    }

    public function test_a_view_only_member_goes_to_the_site_not_to_the_admin(): void
    {
        $member = User::factory()->create();
        $member->forceFill([ViewOnlyDemo::COLUMN => true])->save();
        $this->actingAs($member->fresh());

        // the member's previous page is an admin page it cannot open (a typed address): Home of the site
        $this->withSession(['_previous' => ['url' => url('/admin/users')]]);
        $this->get('/admin/backups')->assertStatus(303)->assertRedirect(url('/'));
        $this->from('/trend')->get('/admin/backups')->assertStatus(303)->assertRedirect('/trend');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Follows the redirects of $response as a browser does (the same Referer as the first request, here none).
     *
     * @return array{visited: list<string>, status: int, content: string}
     */
    private function follow(TestResponse $response, int $limit = 10): array
    {
        $visited = [];

        while ($response->isRedirect()) {
            if (count($visited) >= $limit) {
                $this->fail('the browser gave up: '.implode(' > ', $visited));
            }

            $location = (string) $response->headers->get('Location');
            $visited[] = (string) parse_url($location, PHP_URL_PATH);
            $response = $this->get($location);
        }

        return ['visited' => $visited, 'status' => $response->getStatusCode(), 'content' => (string) $response->getContent()];
    }

    public static function bounceSeconds(): int
    {
        return (int) (new \ReflectionClassConstant(ViewOnlyDemo::class, 'BOUNCE_SECONDS'))->getValue();
    }

    private function routeFirst(string $uri, Closure $action): void
    {
        $router = $this->app['router'];
        $route = (new \Illuminate\Routing\Route(['GET', 'HEAD'], $uri, $action))->middleware('web')->setRouter($router)->setContainer($this->app);
        $routes = new \Illuminate\Routing\RouteCollection();
        $routes->add($route);
        foreach ($router->getRoutes()->getRoutes() as $existing) {
            $routes->add($existing);
        }
        $router->setRoutes($routes);
    }

    private function viewOnlyAdmin(): User
    {
        $user = User::factory()->admin()->create(['password' => 'Demo-pass-1234']);
        $user->forceFill([ViewOnlyDemo::COLUMN => true])->save();

        return $user->fresh();
    }
}
