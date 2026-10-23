<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsPageController;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The redesigned Settings section: old /admin/settings?tab=X links answer 302 (never 301, so switching back to
 * the old admin keeps working in the same browser), the hub and the nine settings + money pages render from
 * config/admin_settings.php, and an admin who uses the old admin still gets the old settings page.
 */
class AdminSettingsRedirectsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2']);
    }

    public static function tabs(): array
    {
        $tabs = [];
        foreach ((require __DIR__.'/../../config/admin_settings.php')['tab_redirects'] as $tab => $target) {
            $tabs[$tab] = [$tab, $target];
        }

        return $tabs;
    }

    #[DataProvider('tabs')]
    public function test_old_settings_tabs_answer_a_temporary_redirect_to_their_new_page(string $tab, string $target): void
    {
        $this->standInForTheAppearancePages();

        $response = $this->actingAs($this->admin())->get('/admin/settings?tab='.$tab);

        $response->assertStatus(302);
        $this->assertSame(url($target), $response->headers->get('Location'));
    }

    public function test_the_appearance_tab_opens_the_appearance_section(): void
    {
        $this->standInForTheAppearancePages();

        $this->actingAs($this->admin())
            ->get('/admin/settings?tab=appearance')
            ->assertStatus(302)
            ->assertRedirect('/admin/appearance');
    }

    public function test_an_old_tab_whose_new_page_cannot_open_on_this_site_keeps_the_old_tab(): void
    {
        $admin = $this->admin();

        // System information moved to a tool page, which answers 404 while the operational tools are switched off.
        config(['admin.operational_tools_enabled' => false]);
        $this->actingAs($admin)->get('/admin/settings?tab=system')->assertOk()->assertSee('Site Identity & Branding');

        config(['admin.operational_tools_enabled' => true]);
        $this->actingAs($admin)->get('/admin/settings?tab=system')->assertRedirect('/admin/preflight?tab=server');

        // A page of a package that is not installed (yet).
        config(['admin_settings.tab_redirects.appearance' => '/admin/not-installed-yet']);
        $this->actingAs($admin)->get('/admin/settings?tab=appearance')->assertOk()->assertSee('Site Identity & Branding');
    }

    public function test_the_settings_link_without_a_tab_opens_the_hub(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('What do you want to change?')
            ->assertSee('Site basics')
            ->assertSee('/admin/settings/email', false)
            ->assertDontSee('Site Identity & Branding');

        $this->actingAs($this->admin())->get('/admin/settings?tab=no-such-tab')->assertOk()->assertSee('What do you want to change?');
    }

    public function test_the_hub_search_finds_settings_by_label_old_name_and_synonym(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/settings?q=sender')
            ->assertOk()
            ->assertSee('Sender name')
            ->assertSee('/admin/settings/email#mail_from_name', false);

        // "Mailer Engine" is the old label of "How emails are sent"; "smtp" is a search synonym.
        $this->actingAs($admin)->getJson('/admin/settings?q=mailer%20engine')
            ->assertOk()
            ->assertJsonPath('results.0.url', '/admin/settings/email#mail_mailer');
        $this->actingAs($admin)->getJson('/admin/settings?q=smtp')
            ->assertOk()
            ->assertJsonFragment(['url' => '/admin/settings/email#smtp_host']);

        $this->actingAs($admin)->get('/admin/settings?q=sender&partial=1')
            ->assertOk()
            ->assertSee('Sender name')
            ->assertDontSee('<html', false);
    }

    public static function pages(): array
    {
        return array_map(fn (string $id): array => [$id], array_combine(array_keys(SettingsPageController::PAGES), array_keys(SettingsPageController::PAGES)));
    }

    #[DataProvider('pages')]
    public function test_every_settings_page_renders_its_basic_settings_with_plain_labels(string $slug): void
    {
        $pageId = SettingsPageController::PAGES[$slug];
        $page = config('admin_settings.pages')[$pageId];

        $response = $this->actingAs($this->admin())->get('/admin/settings/'.$slug)->assertOk();
        $response->assertSee('<h1>'.e($page['label']).'</h1>', false)
            ->assertSee('action="'.route('admin.settings.save').'"', false)
            ->assertSee('data-savebar', false);

        foreach ($page['cards'] as $card) {
            foreach ($card['fields'] as $key) {
                $setting = config("admin_settings.settings.{$key}");
                if (in_array($setting['level'], ['basic', 'advanced'], true)) {
                    $response->assertSee('id="'.$key.'-row"', false);
                    $response->assertSee(e($setting['label']), false);
                } else {
                    // Expert settings appear only in Developer mode; hidden ones never.
                    $response->assertDontSee('id="'.$key.'-row"', false);
                }
            }
        }
    }

    public function test_unknown_settings_pages_are_not_found(): void
    {
        $this->actingAs($this->admin())->get('/admin/settings/appearance')->assertNotFound();
        $this->actingAs($this->admin())->get('/admin/settings/nope')->assertNotFound();
    }

    public function test_the_money_pages_render(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/money')->assertOk()->assertSee('Earnings')->assertSee('What needs setting up');
        $this->actingAs($admin)->get('/admin/money/ads')->assertOk()->assertSee('id="ad_code_header-row"', false)->assertSee('id="ads_enabled"', false);
        $this->actingAs($admin)->get('/admin/money/setup')->assertOk()->assertSee('id="premium_enabled"', false)->assertSee('Card payments (Stripe)');
        $this->actingAs($admin)->get('/admin/money/premium-page')->assertOk()->assertSee('id="premium_page_title-row"', false);
    }

    public function test_risky_switches_carry_their_consequence(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/settings/maintenance')
            ->assertOk()
            ->assertSee('data-confirm="Turn on maintenance mode? Visitors will see the &quot;back soon&quot; page until you turn it off."', false)
            ->assertSee('data-confirm-when="on"', false)
            ->assertSee('data-confirm-action="Turn on maintenance mode"', false);

        $this->actingAs($this->admin())
            ->get('/admin/settings/features')
            ->assertSee('data-confirm-when="off"', false);
    }

    public function test_settings_pages_show_saved_values_and_defaults(): void
    {
        Setting::set('mail_from_name', 'Saved Sender', 'email');

        $this->actingAs($this->admin())
            ->get('/admin/settings/email')
            ->assertOk()
            ->assertSee('value="Saved Sender"', false)
            ->assertSee('Send a test email');
    }

    public function test_an_admin_who_uses_the_old_admin_keeps_the_old_settings_page(): void
    {
        config(['admin.ui' => 'v1']);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/settings?tab=general')->assertOk()->assertSee('Site Identity & Branding');
        $this->actingAs($admin)->get('/admin/settings/email')->assertRedirect(route('admin.settings.index', ['tab' => 'email']));
        $this->actingAs($admin)->get('/admin/money/ads')->assertRedirect(route('admin.settings.index', ['tab' => 'monetization']));
    }

    public function test_odd_query_strings_do_not_break_the_pages(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/settings?tab[]=email')->assertOk()->assertSee('What do you want to change?');
        $this->actingAs($admin)->get('/admin/settings?tab=pages.premium')->assertOk();
        $this->actingAs($admin)->get('/admin/settings?q[]=smtp')->assertOk();
        $this->actingAs($admin)->getJson('/admin/settings/values?keys[]=ad_code_header')->assertOk()->assertExactJson(['values' => []]);
        $this->actingAs($admin)->getJson('/admin/settings/values?keys=smtp_password,site_name,ad_code_header')
            ->assertOk()
            ->assertJsonMissingPath('values.smtp_password')
            ->assertJsonMissingPath('values.site_name')
            ->assertJsonStructure(['values' => ['ad_code_header']]);
    }

    public function test_the_page_script_is_cached_by_the_browser(): void
    {
        $this->assertStringNotContainsString('.js', route('admin.settings.script', ['v' => 1]));
        $response = $this->actingAs($this->admin())->get(route('admin.settings.script', ['v' => 1]))->assertOk();

        $this->assertStringContainsString('max-age=31536000', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('javascript', $response->headers->get('Content-Type'));
    }

    public function test_guests_and_members_never_see_the_new_pages(): void
    {
        $this->get('/admin/settings/email')->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/admin/money/setup')->assertNotFound();
    }

    public function test_the_route_files_of_the_redesign_never_replace_or_rename_an_admin_route(): void
    {
        // routes/admin.php loads routes/admin/*.php after its own routes. A later route with the same method and
        // address silently replaces the earlier one (whose name then stops resolving), so register the file with
        // everything it loads on a router of its own and note every replacement.
        $routes = new class extends RouteCollection
        {
            /** @var list<string> */
            public array $replaced = [];

            public function add(Route $route)
            {
                foreach ($route->methods() as $method) {
                    if (isset($this->routes[$method][$route->getDomain().$route->uri()])) {
                        $this->replaced[] = $method.' /'.$route->uri();
                    }
                }

                return parent::add($route);
            }
        };
        $router = new Router($this->app['events'], $this->app);
        $router->setRoutes($routes);
        $appRouter = RouteFacade::getFacadeRoot();

        RouteFacade::swap($router);
        try {
            require base_path('routes/admin.php');
        } finally {
            RouteFacade::swap($appRouter);
        }

        $this->assertSame([], $routes->replaced, 'A route of routes/admin/*.php replaces an admin route registered before it.');

        $names = array_values(array_filter(array_map(fn (Route $route): ?string => $route->getName(), $routes->getRoutes())));
        $this->assertSame([], array_values(array_unique(array_diff_assoc($names, array_unique($names)))), 'An admin route name is registered twice.');

        // The application registers the same routes: every one keeps its name, address and action.
        foreach ($routes->getRoutes() as $route) {
            $live = $route->getName() !== null ? app('router')->getRoutes()->getByName($route->getName()) : null;
            if ($live !== null) {
                $this->assertSame([$route->uri(), $route->methods(), $route->getActionName()], [$live->uri(), $live->methods(), $live->getActionName()], $route->getName());
            }
        }
        $this->assertGreaterThan(250, count($names));
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** The Appearance pages belong to WP8; until they are installed, stand-in routes answer their addresses. */
    private function standInForTheAppearancePages(): void
    {
        if (! RouteFacade::has('admin.appearance.index')) {
            RouteFacade::middleware('admin')->get('/admin/appearance', fn () => 'Themes')->name('admin.appearance.index');
        }

        if (! RouteFacade::has('admin.appearance.page')) {
            RouteFacade::middleware('admin')->get('/admin/appearance/{page}', fn () => 'Appearance page')->name('admin.appearance.page');
        }
    }
}
