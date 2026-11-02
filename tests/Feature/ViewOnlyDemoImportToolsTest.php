<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AvdbImportController;
use App\Http\Controllers\Admin\StatusProbeController;
use App\Http\Controllers\Admin\XvidImportController;
use App\Http\Middleware\ViewOnlyDemo;
use App\Models\AvdbImportRun;
use App\Models\AvdbImportSchedule;
use App\Models\User;
use App\Models\XvidImportRun;
use App\Models\XvidImportSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.3: the AVDBAPI and XVIDAPI import tools are closed to a view-only demo account (App\Http\Middleware
 * ViewOnlyDemo::CLOSED): every route of both (pages, settings, runs, schedules, progress, logs, exports, data answers),
 * with the same answer as the other closed pages (a page goes back with the refusal, a script gets the 403 JSON), and
 * the account is not shown the owner's import settings anywhere else (Home, the status checks). A normal administrator
 * keeps every import route.
 */
class ViewOnlyDemoImportToolsTest extends TestCase
{
    use RefreshDatabase;

    private const CLOSED = 'This page is not part of the view-only demo: it would show private files or server details.';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['admin.ui' => 'v2', 'admin.operational_tools_enabled' => true]);
    }

    /** No request ever leaves the test (the import pages and the provider check ask the providers). */
    private function providers(bool $answer): void
    {
        Http::fake(['*' => $answer
            ? Http::response(['code' => 1, 'total' => 0, 'pagecount' => 0, 'limit' => 20, 'list' => []], 200)
            : Http::response('down', 503)]);
    }

    /** @return list<Route> every route of the two import tools, as the router knows them (a route added later counts too) */
    private function importRoutes(): array
    {
        $routes = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = (string) $route->getName();
            $action = (string) $route->getActionName();

            if (Str::startsWith($name, ['admin.avdb-import.', 'admin.xvid-import.'])
                || Str::startsWith($action, [AvdbImportController::class.'@', XvidImportController::class.'@'])) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /** @return array<string, int|string> route parameters for every import route */
    private function importRecords(): array
    {
        $avdbRun = AvdbImportRun::query()->create(['status' => 'completed', 'settings' => [], 'message' => 'Done.']);
        $xvidRun = XvidImportRun::query()->create(['status' => 'completed', 'settings' => [], 'message' => 'Done.']);
        $avdbSchedule = AvdbImportController::ensureDefaultScheduleExists() ?? AvdbImportSchedule::query()->firstOrFail();
        $xvidSchedule = XvidImportSchedule::query()->create([
            'name' => 'Daily new videos', 'is_active' => false, 'categories' => [65], 'pages' => 1, 'start_page' => 1, 'pagesize' => 50,
            'delay' => 0, 'settings' => ['frequency' => 'daily', 'run_time' => '03:00', 'categories' => [65]], 'next_run_at' => now()->addHour(),
        ]);

        return ['avdb' => ['run' => $avdbRun->id, 'schedule' => $avdbSchedule->id, 'type' => 'failed'],
            'xvid' => ['run' => $xvidRun->id, 'schedule' => $xvidSchedule->id, 'type' => 'failed']];
    }

    private function urlOf(Route $route, array $records): string
    {
        $parameters = Str::contains((string) $route->getName(), 'xvid') ? $records['xvid'] : $records['avdb'];
        $wanted = array_intersect_key($parameters, array_flip($route->parameterNames()));

        return '/'.ltrim(route((string) $route->getName(), $wanted, false), '/');
    }

    public function test_there_are_import_routes_and_every_one_of_them_is_a_closed_route(): void
    {
        $routes = $this->importRoutes();

        // Both tools, with their pages, data answers and actions (the test would pass on nothing otherwise).
        $this->assertGreaterThanOrEqual(30, count($routes));
        $names = array_map(fn (Route $route): string => (string) $route->getName(), $routes);
        foreach (['admin.avdb-import.index', 'admin.avdb-import.start', 'admin.avdb-import.progress', 'admin.avdb-import.stats',
            'admin.avdb-import.issues.export', 'admin.avdb-import.schedules.store', 'admin.avdb-import.schedules.run-due',
            'admin.xvid-import.index', 'admin.xvid-import.start', 'admin.xvid-import.progress', 'admin.xvid-import.schedules.run-now'] as $expected) {
            $this->assertContains($expected, $names);
        }

        foreach ($routes as $route) {
            $this->assertTrue(ViewOnlyDemo::closedRoute((string) $route->getName()), $route->getName().' is a closed route');
        }

        // The scheduler buttons of the AVDBAPI durations (Tools > Background jobs) were closed already.
        $this->assertTrue(ViewOnlyDemo::closedRoute('admin.cron.duration.start'));
    }

    public function test_every_import_route_is_closed_for_a_view_only_admin_page_and_script(): void
    {
        $this->providers(true);
        $demo = $this->viewOnlyAdmin();
        $records = $this->importRecords();
        $checked = 0;

        foreach ($this->importRoutes() as $route) {
            $url = $this->urlOf($route, $records);

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                // a page (a person's click on a link or a form): back to the page it came from, with the refusal and the
                // window's kind (a script asking the same closed path again at once is sent Home: ViewOnlyDemo::back())
                $page = $this->actingAs($demo)->from('/admin/users')->withHeaders(['Sec-Fetch-User' => '?1'])->{strtolower($method)}($url);
                $this->assertSame(303, $page->getStatusCode(), "{$method} {$url}");
                $this->assertSame(url('/admin/users'), $page->headers->get('Location'), "{$method} {$url}");
                $page->assertHeader(ViewOnlyDemo::HEADER, '1')
                    ->assertHeader('X-View-Only-Demo-Refusal', 'closed')
                    ->assertSessionHasErrors(['view_only_demo' => self::CLOSED])
                    ->assertSessionHas('view_only_demo.window', 'closed');

                // a script: 403 JSON with the message and what the "This is a demo" window shows
                $this->actingAs($demo)->json($method, $url)
                    ->assertForbidden()
                    ->assertHeader(ViewOnlyDemo::HEADER, '1')
                    ->assertHeader('X-View-Only-Demo-Refusal', 'closed')
                    ->assertJson([
                        'ok' => false,
                        'message' => self::CLOSED,
                        'errors' => ['view_only_demo' => [self::CLOSED]],
                        'demo_window' => ['kind' => 'closed', 'title' => 'This is a demo', 'body' => 'This part is not open in the demo.', 'button' => 'Got it'],
                    ]);
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(30, $checked);

        // Nothing ran: no import was started, no schedule changed.
        $this->assertSame(1, AvdbImportRun::query()->count());
        $this->assertSame(1, XvidImportRun::query()->count());
        $this->assertFalse((bool) AvdbImportSchedule::query()->value('enabled'));
    }

    public function test_the_import_pages_never_reach_a_view_only_account_even_without_a_page_to_go_back_to(): void
    {
        $this->providers(true);
        $demo = $this->viewOnlyAdmin();

        // No Referer and no previous page: Home of the admin, with the refusal, never the import page itself.
        foreach (['/admin/avdb-import', '/admin/xvid-import'] as $page) {
            $response = $this->actingAs($demo)->get($page);
            $response->assertStatus(303)->assertSessionHasErrors(['view_only_demo' => self::CLOSED]);
            $this->assertStringEndsWith('/admin', (string) $response->headers->get('Location'));
            $this->assertStringNotContainsString('avdbapi.com', (string) $response->getContent());
        }
    }

    public function test_a_normal_admin_keeps_every_import_route(): void
    {
        $this->providers(true);
        $owner = User::factory()->admin()->create();
        $records = $this->importRecords();

        $this->actingAs($owner)->get('/admin/avdb-import')->assertOk()->assertHeaderMissing(ViewOnlyDemo::HEADER)->assertSee('avdbapi.com', false);
        $this->actingAs($owner)->get('/admin/xvid-import')->assertOk()->assertHeaderMissing(ViewOnlyDemo::HEADER);
        $this->actingAs($owner)->getJson('/admin/avdb-import/stats')->assertOk()->assertHeaderMissing(ViewOnlyDemo::HEADER);
        $this->actingAs($owner)->getJson(route('admin.avdb-import.progress', $records['avdb']['run'], false))->assertOk();
        $this->actingAs($owner)->getJson(route('admin.xvid-import.progress', $records['xvid']['run'], false))->assertOk();

        // An action runs (here: a schedule paused and resumed), never refused as a demo.
        $this->actingAs($owner)->postJson(route('admin.avdb-import.schedules.toggle', $records['avdb']['schedule'], false))
            ->assertHeaderMissing(ViewOnlyDemo::HEADER)->assertHeaderMissing('X-View-Only-Demo-Refusal');
        $this->assertTrue((bool) AvdbImportSchedule::query()->value('enabled'));
    }

    public function test_home_shows_a_view_only_admin_no_import_tool_link_card_or_failed_import(): void
    {
        $this->providers(true);
        $demo = $this->viewOnlyAdmin();
        $owner = User::factory()->admin()->create();
        // A failed import of today whose message names the provider's address (an owner's import detail).
        AvdbImportRun::query()->create(['status' => 'failed', 'settings' => [], 'message' => 'https://avdbapi.com/api.php/provide/vod did not answer', 'created_at' => now(), 'updated_at' => now()]);

        $html = (string) $this->actingAs($demo)->get('/admin')->assertOk()->getContent();
        $this->assertStringNotContainsString('/admin/avdb-import', $html);
        $this->assertStringNotContainsString('/admin/xvid-import', $html);
        $this->assertStringNotContainsString('id="ad-home-imports"', $html);
        $this->assertStringNotContainsString('avdbapi.com', $html);
        $this->assertStringNotContainsString('1 import failed', $html);

        // The owner: all of it, as before.
        $owner->refresh();
        $html = (string) $this->actingAs($owner)->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('href="/admin/avdb-import"', $html);
        $this->assertStringContainsString('id="ad-home-imports"', $html);
        $this->assertStringContainsString('1 import failed', $html);
        $this->assertStringContainsString('class="ad-grid2 ad-home-bottom"', $html);
    }

    public function test_the_status_checks_never_offer_a_view_only_admin_the_import_tools(): void
    {
        $demo = $this->viewOnlyAdmin();
        $owner = User::factory()->admin()->create();
        // The providers do not answer: the check says so and links to the imports (for the owner).
        $this->providers(false);

        // Asked first by the view-only account: the kept answer still carries the link for every other administrator.
        $answer = $this->actingAs($demo)->getJson('/admin/status/provider')->assertOk()->json();
        $this->assertSame('danger', $answer['status']);
        $this->assertNull($answer['action']);

        $kept = StatusProbeController::peek('provider');
        $this->assertSame('/admin/avdb-import', parse_url((string) $kept['action']['url'], PHP_URL_PATH));

        $this->actingAs($owner)->getJson('/admin/status/provider')->assertOk()
            ->assertJsonPath('action.label', 'See imports');

        // Home: the owner is told about it with the link; the view-only account is not.
        AvdbImportSchedule::query()->create([
            'name' => 'Hourly', 'enabled' => true, 'frequency' => 'hourly', 'interval_minutes' => 60, 'run_time' => '00:15', 'weekday' => 1,
            'month_day' => 1, 'skip_if_active' => true, 'settings' => ['categories' => [1]], 'next_run_at' => now()->addHour(),
        ]);
        $ownerHome = (string) $this->actingAs($owner)->get('/admin')->getContent();
        $this->assertStringContainsString('See imports', $ownerHome);
        $demoHome = (string) $this->actingAs($demo)->get('/admin')->getContent();
        $this->assertStringNotContainsString('See imports', $demoHome);
        $this->assertStringNotContainsString('/admin/avdb-import', $demoHome);
    }

    private function viewOnlyAdmin(): User
    {
        $user = User::factory()->admin()->create();
        $user->forceFill([ViewOnlyDemo::COLUMN => true])->save();

        return $user->fresh();
    }
}
