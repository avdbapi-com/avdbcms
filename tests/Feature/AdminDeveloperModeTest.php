<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDeveloperMode;
use App\Models\User;
use App\Support\AdminPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The 3 developer pages (Custom code, Database, Developer zone) open only in Developer mode, a per-admin
 * switch of the account menu, and the server enforces it (App\Http\Middleware\EnsureDeveloperMode).
 */
class AdminDeveloperModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests are about the new admin, whatever ADMIN_UI the test environment sets.
        config(['admin.ui' => 'v2']);
    }

    public static function developerPages(): array
    {
        return [
            'database' => ['admin.database.index'],
            'settings file' => ['admin.env-editor.index'],
            'commands' => ['admin.artisan.index'],
            'api keys' => ['admin.api-tokens.index'],
        ];
    }

    #[DataProvider('developerPages')]
    public function test_developer_pages_are_forbidden_without_developer_mode(string $routeName): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route($routeName))
            ->assertForbidden()
            ->assertSee('Developer mode is off')
            ->assertSee('Turn on Developer mode')
            ->assertSee('class="ad-shell"', false);

        $this->actingAs($admin)
            ->getJson(route($routeName))
            ->assertForbidden()
            ->assertJsonPath('message', 'This tool is for developers. Turn on Developer mode in your account menu to use it.');
    }

    #[DataProvider('developerPages')]
    public function test_developer_pages_open_in_developer_mode(string $routeName): void
    {
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);

        $this->actingAs($admin)->get(route($routeName))->assertOk();
    }

    public function test_actions_of_developer_pages_are_forbidden_too(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.env-editor.update'), ['env' => []])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.artisan.run'), ['command' => 'about'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('admin.settings.update.env'), [])
            ->assertForbidden();
    }

    public function test_every_developer_route_carries_the_gate(): void
    {
        $patterns = (array) config('admin_nav.developer.routes');
        $gated = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => Str::is($patterns, (string) $route->getName()));

        $this->assertGreaterThanOrEqual(10, $gated->count(), 'database, settings file, commands and API keys routes');

        foreach ($gated as $route) {
            $this->assertContains(EnsureDeveloperMode::class, $route->gatherMiddleware(), (string) $route->getName());
            // The operational tools switch still applies where it did before.
            if (Str::is(['admin.database.*', 'admin.env-editor.*', 'admin.artisan.*'], (string) $route->getName())) {
                $this->assertContains('admin.ops', $route->gatherMiddleware(), (string) $route->getName());
            }
        }
    }

    public function test_the_menu_shows_developer_pages_only_in_developer_mode(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertDontSee('href="'.route('admin.env-editor.index', [], false).'"', false)
            ->assertDontSee('href="'.route('admin.database.index', [], false).'"', false);

        AdminPreferences::set($admin, 'developer_mode', true);

        $this->actingAs($admin)
            ->get(route('admin.preflight.index'))
            ->assertOk()
            ->assertSee('href="'.route('admin.env-editor.index', [], false).'"', false)
            ->assertSee('href="'.route('admin.database.index', [], false).'"', false);
    }

    public function test_the_403_page_turns_developer_mode_on(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('admin.database.index'))
            ->post(route('admin.shell.preferences'), ['key' => 'developer_mode', 'value' => '1'])
            ->assertRedirect(route('admin.database.index'));

        $this->assertTrue(AdminPreferences::developerMode($admin->fresh()));
        $this->actingAs($admin->fresh())->get(route('admin.database.index'))->assertOk();
    }

    public function test_developer_pages_stay_open_until_the_update_is_finished(): void
    {
        // Uploaded over an older version: without the preferences column Developer mode cannot be switched on,
        // so the tools that finish an update (Database, Developer zone) stay reachable as before.
        $admin = User::factory()->admin()->create();
        Schema::table('users', fn ($table) => $table->dropColumn('admin_preferences'));
        $fresh = fn () => User::query()->find($admin->id);

        $this->actingAs($fresh())->get(route('admin.database.index'))->assertOk();
        $this->actingAs($fresh())->get(route('admin.env-editor.index'))->assertOk();
        $this->actingAs($fresh())->get(route('admin.artisan.index'))->assertOk();
    }

    public function test_the_old_admin_keeps_its_previous_behaviour(): void
    {
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'admin_ui', 'v1');

        $this->actingAs($admin)->get(route('admin.env-editor.index'))->assertOk();

        config(['admin.ui' => 'v1']);
        $other = User::factory()->admin()->create();
        $this->actingAs($other)->get(route('admin.database.index'))->assertOk();
    }

    public function test_the_operational_tools_switch_still_hides_the_tools(): void
    {
        config(['admin.operational_tools_enabled' => false]);
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);

        $this->actingAs($admin)->get(route('admin.database.index'))->assertNotFound();
    }

    public function test_custom_code_is_the_only_developer_page_of_its_route(): void
    {
        // The Appearance pages share one route; the gate checks the {page} parameter (config admin_nav developer.param_routes).
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $status = function (string $page) use ($admin): int {
            $route = new \Illuminate\Routing\Route(['GET'], 'admin/appearance/{page}', fn () => 'page');
            $route->name('admin.appearance.page');
            $request = Request::create('/admin/appearance/'.$page);
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
            $request->setUserResolver(fn () => $admin->fresh());

            return (new EnsureDeveloperMode)->handle($request, fn () => response('page'))->getStatusCode();
        };

        $this->assertSame(200, $status('brand'));
        $this->assertSame(403, $status('code'));

        AdminPreferences::set($admin, 'developer_mode', true);
        $this->assertSame(200, $status('code'));
    }

    public function test_guests_and_members_still_get_404(): void
    {
        $this->get(route('admin.database.index'))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('admin.database.index'))->assertNotFound();
    }
}
