<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsPageController;
use App\Http\Middleware\ViewOnlyDemo;
use App\Models\User;
use App\Support\AdminPreferences;
use App\Support\AdminSearchIndex;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

/**
 * AVDBCMS 1.2.3: a closed page (App\Http\Middleware\ViewOnlyDemo::CLOSED) is not offered to a view-only demo account
 * (ViewOnlyDemo::hides(), one rule): no menu entry of the new or the old admin, no Home card or link, no Ctrl+K or
 * Settings search result, no tab, breadcrumb or button leads there. A normal administrator is offered every page as
 * before.
 */
class ViewOnlyDemoOfferedPagesTest extends TestCase
{
    use RefreshDatabase;

    /** Pages of the admin menu that are closed for a view-only account (their own routes). */
    private const CLOSED_PAGES = ['/admin/avdb-import', '/admin/backups', '/admin/preflight', '/admin/queue', '/admin/logs-viewer',
        '/admin/exports', '/admin/database', '/admin/env-editor'];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['admin.ui' => 'v2', 'admin.operational_tools_enabled' => true]);
        Http::fake(['*' => Http::response(['code' => 1, 'total' => 0, 'pagecount' => 0, 'limit' => 20, 'list' => []], 200)]);
    }

    public function test_the_new_admin_menu_offers_a_view_only_admin_no_closed_page_and_a_normal_admin_every_page(): void
    {
        [$demo, $owner] = $this->admins();

        $demoLinks = $this->navLinks($this->page($demo, '/admin/users'));
        $ownerLinks = $this->navLinks($this->page($owner, '/admin/users'));

        foreach (self::CLOSED_PAGES as $page) {
            $this->assertNotContains($page, $demoLinks, "the view-only admin's menu has no {$page}");
            $this->assertContains($page, $ownerLinks, "the owner's menu has {$page}");
        }

        foreach ($demoLinks as $link) {
            $this->assertFalse($this->closed($link), "the view-only admin's menu link {$link} is not a closed page");
        }

        // Every other page stays: the view-only admin sees the rest of the menu as the owner does.
        $this->assertSame(array_values(array_diff($ownerLinks, $this->closedOf($ownerLinks))), $demoLinks);
        $this->assertContains('/admin/updates', $demoLinks);
        $this->assertContains('/admin/storage', $demoLinks);
        $this->assertContains('/admin/videos', $demoLinks);
    }

    public function test_the_old_admin_menu_offers_a_view_only_admin_no_closed_page_and_a_normal_admin_every_page(): void
    {
        config(['admin.ui' => 'v1']);
        [$demo, $owner] = $this->admins();

        $demoHtml = $this->page($demo, '/admin/users');
        $ownerHtml = $this->page($owner, '/admin/users');
        $demoLinks = $this->links($demoHtml, '//*[@id="responsive-sidebar"]//a[@href]');
        $ownerLinks = $this->links($ownerHtml, '//*[@id="responsive-sidebar"]//a[@href]');

        foreach (['/admin/avdb-import', '/admin/xvid-import', '/admin/backups', '/admin/preflight', '/admin/queue', '/admin/logs-viewer',
            '/admin/exports', '/admin/env-editor', '/admin/database', '/admin/cron', '/admin/failed-jobs', '/admin/audit', '/admin/system',
            '/admin/phpinfo', '/admin/artisan'] as $page) {
            $this->assertNotContains($page, $demoLinks, "the old admin's menu of the view-only admin has no {$page}");
            $this->assertContains($page, $ownerLinks, "the old admin's menu of the owner has {$page}");
        }

        foreach ($demoLinks as $link) {
            $this->assertFalse($this->closed($link), "old admin menu link {$link}");
        }

        // The groups that keep a page stay (System: Cache & storage; Monetization: plans ...), with their titles.
        $this->assertContains('/admin/storage', $demoLinks);
        $this->assertContains('/admin/plans', $demoLinks);
        $this->assertStringNotContainsString('AVDBAPI Import', $demoHtml);
        $this->assertStringContainsString('AVDBAPI Import', $ownerHtml);
    }

    public function test_the_ctrl_k_search_offers_a_view_only_admin_no_closed_page_or_setting(): void
    {
        [$demo, $owner] = $this->admins();

        $demoIndex = $this->searchIndex($demo);
        $ownerIndex = $this->searchIndex($owner);

        // Never one copy for both: the index is kept per version.
        $this->assertNotSame($demoIndex['v'], $ownerIndex['v']);

        $demoPages = array_column($demoIndex['pages'], 'id');
        $ownerPages = array_column($ownerIndex['pages'], 'id');

        foreach (['content.import', 'content.import#admin.xvid-import.index', 'members.exports', 'tools.health', 'tools.backups', 'tools.jobs',
            'tools.logs', 'tools.database', 'tools.developer'] as $page) {
            $this->assertNotContains($page, $demoPages, "Ctrl+K finds no {$page} for the view-only admin");
            $this->assertContains($page, $ownerPages, "Ctrl+K finds {$page} for the owner");
        }

        foreach (array_merge($demoIndex['pages'], $demoIndex['settings']) as $entry) {
            $this->assertFalse($this->closed((string) $entry['u']), 'Ctrl+K result '.($entry['id'] ?? $entry['k']).' opens no closed page');
        }

        foreach ($demoIndex['pageUrls'] as $page => $url) {
            $this->assertFalse($this->closed($url), "page {$page}");
        }

        // The settings of a closed page are not found; the others are (the same rows as the owner's otherwise).
        $demoSettings = array_column($demoIndex['settings'], 'k');
        $ownerSettings = array_column($ownerIndex['settings'], 'k');
        $this->assertContains('cors_allowed_origins', $ownerSettings);
        $this->assertNotContains('cors_allowed_origins', $demoSettings);
        $this->assertContains('site_name', $demoSettings);
        $this->assertSame([], array_values(array_diff($demoSettings, $ownerSettings)));
    }

    public function test_the_settings_search_offers_a_view_only_admin_no_setting_of_a_closed_page(): void
    {
        [$demo, $owner] = $this->admins();

        // The hub's results as the page and as its script asks them (?partial=1).
        foreach (['/admin/settings?q=cors', '/admin/settings?q=cors&partial=1'] as $url) {
            $demoHtml = $this->page($demo, $url);
            $ownerHtml = $this->page($owner, $url);
            $this->assertStringContainsString('/admin/env-editor', $ownerHtml, 'the owner finds the API access settings');
            $this->assertStringNotContainsString('/admin/env-editor', $demoHtml, 'the view-only admin does not');
        }

        // The rule itself: every result of every word opens a page the view-only admin is offered.
        $this->actingAs($demo);
        $request = Request::create('/admin/settings', 'GET');
        $request->setUserResolver(fn () => $demo);
        $this->app->instance('request', $request);

        foreach (['cors', 'env', 'backup', 'import', 'log', 'cron', 'queue', 'health', 'api', 'email', 'site'] as $word) {
            foreach (SettingsPageController::searchSettings($word, $demo, 500) as $result) {
                $this->assertFalse($this->closed((string) $result['url']), "search '{$word}': {$result['key']}");
            }
        }
    }

    public function test_what_moved_where_offers_a_view_only_admin_no_closed_page(): void
    {
        [$demo, $owner] = $this->admins();

        $demoHtml = $this->page($demo, '/admin/shell/moved');
        $ownerHtml = $this->page($owner, '/admin/shell/moved');

        foreach (['AVDBAPI Import', 'XVIDAPI Import', 'System &gt; Backups', 'System &gt; Log Viewer', 'System &gt; Preflight', 'Monetization &gt; Exports'] as $old) {
            $this->assertStringContainsString($old, $ownerHtml);
            $this->assertStringNotContainsString($old, $demoHtml);
        }

        // The old settings tabs whose settings now live only on a closed page (System, Advanced: the health check).
        $this->assertStringContainsString('data-old-tab="system"', $ownerHtml);
        $this->assertStringNotContainsString('data-old-tab="system"', $demoHtml);
        $this->assertStringContainsString('data-old-tab="general"', $demoHtml);

        foreach ($this->links($demoHtml, '//main//a[@href]') as $link) {
            $this->assertFalse($this->closed($link), "moved link {$link}");
        }
    }

    public function test_tabs_breadcrumbs_and_page_buttons_offer_a_view_only_admin_no_closed_page(): void
    {
        [$demo, $owner] = $this->admins();

        // API keys is open, the other tabs of its page (the settings file, commands) are closed.
        $demoHtml = $this->page($demo, '/admin/api-tokens');
        $ownerHtml = $this->page($owner, '/admin/api-tokens');
        $this->assertStringContainsString('href="/admin/env-editor"', $ownerHtml);
        $this->assertStringContainsString('href="/admin/artisan"', $ownerHtml);
        $this->assertStringNotContainsString('/admin/env-editor', $demoHtml);
        $this->assertStringNotContainsString('/admin/artisan', $demoHtml);

        // The breadcrumb of a Tools page links the section's first page this account is offered.
        $crumbs = fn (string $html): array => $this->links($html, '//nav[contains(@class,"ad-crumbs")]//a[@href]');
        $this->assertContains('/admin/preflight', $crumbs($this->page($owner, '/admin/storage')));
        $this->assertNotContains('/admin/preflight', $crumbs($this->page($demo, '/admin/storage')));

        // Buttons: Import videos (Videos), Export (Members, Languages), Log in as (Members).
        foreach (['/admin/videos' => ['/admin/avdb-import'], '/admin/users' => ['/admin/users/export', '/admin/impersonate/take/'],
            '/admin/languages' => ['/export']] as $url => $targets) {
            $demoPage = $this->page($demo, $url);
            $ownerPage = $this->page($owner, $url);

            foreach ($targets as $target) {
                $this->assertStringContainsString($target, $ownerPage, "{$url}: the owner has {$target}");
                $this->assertStringNotContainsString($target, $demoPage, "{$url}: the view-only admin has no {$target}");
            }
        }
    }

    /**
     * Pages whose rows or fields lead to a closed route only with data: Money > Payments & invoices > Invoices offers
     * each member's invoice PDF (billing name, address, VAT id), Settings > Maintenance a "Show and copy the link" for
     * the saved secret preview link. Both are left out for a view-only admin, in the new and the old admin.
     */
    public function test_invoices_and_the_maintenance_secret_offer_a_view_only_admin_no_closed_page(): void
    {
        [$demo, $owner] = $this->admins();
        $invoice = $this->invoice();
        $pdf = '/profile/invoice/'.$invoice->id;

        // The new admin: the row menu's "Download invoice" and the row button's address.
        $demoHtml = $this->page($demo, '/admin/invoice');
        $ownerHtml = $this->page($owner, '/admin/invoice');
        $this->assertStringContainsString('Download invoice', $ownerHtml);
        $this->assertStringContainsString($pdf, $ownerHtml);
        $this->assertStringNotContainsString('Download invoice', $demoHtml);
        $this->assertStringNotContainsString($pdf, $demoHtml);
        // The row keeps its other way out (the member), so its menu button stays.
        $this->assertStringContainsString('Open member', $demoHtml);
        $this->assertStringContainsString('data-user="'.$invoice->user_id.'"', $demoHtml);

        // The old admin: the row's "Download" button.
        config(['admin.ui' => 'v1']);
        $this->assertStringContainsString($pdf, $this->page($owner, '/admin/invoice'));
        $this->assertStringNotContainsString($pdf, $this->page($demo, '/admin/invoice'));
        config(['admin.ui' => 'v2']);

        // Settings > Maintenance with a saved secret link: showing it is a closed route (admin.settings.maintenance.reveal).
        \App\Models\Setting::set('maintenance_secret', 'bypass-word-for-the-owner', 'maintenance');
        Cache::flush();
        $ownerHtml = $this->page($owner, '/admin/settings/maintenance');
        $demoHtml = $this->page($demo, '/admin/settings/maintenance');
        $this->assertStringContainsString('data-reveal-open', $ownerHtml);
        $this->assertStringContainsString('Show and copy the link', $ownerHtml);
        $this->assertStringNotContainsString('data-reveal-open', $demoHtml);
        $this->assertStringNotContainsString('data-reveal-form', $demoHtml);
        $this->assertStringNotContainsString('Show and copy the link', $demoHtml);
        $this->assertStringContainsString('A secret link is saved. It is hidden until you type your password.', $demoHtml, 'the state is still said');
    }

    public function test_no_admin_page_a_view_only_admin_can_reach_offers_a_closed_page(): void
    {
        [$demo] = $this->admins();
        User::factory()->count(2)->create();
        // Rows that lead to a closed route only when they exist: an invoice, a saved secret preview link.
        $this->invoice();
        \App\Models\Setting::set('maintenance_secret', 'bypass-word-for-the-owner', 'maintenance');
        Cache::flush();

        foreach (['v2', 'v1'] as $ui) {
            config(['admin.ui' => $ui]);
            // The old admin's Home draws its revenue chart with a MySQL query the tests' SQLite cannot run once there
            // is a payment: its crawl starts from Members too (every page is in its side menu).
            $queue = $ui === 'v1' ? ['/admin', '/admin/users'] : ['/admin'];
            $seen = [];
            $pages = [];
            $checked = 0;

            while ($queue !== [] && count($seen) < 160) {
                $url = array_shift($queue);

                // One page of each kind (route and its fixed part, e.g. one member's edit page, every settings page).
                $kind = $this->kindOf($url);

                if (isset($seen[$url]) || ($kind !== null && isset($pages[$kind]))) {
                    continue;
                }

                $seen[$url] = true;
                $pages[$kind ?? $url] = true;
                $response = $this->actingAs($demo)->withHeaders(['Sec-Fetch-User' => '?1'])->get($url);

                if ($response->getStatusCode() !== 200 || ! str_contains((string) $response->headers->get('Content-Type'), 'html')) {
                    continue;
                }

                $html = (string) $response->getContent();
                $checked++;

                foreach ($this->targets($html) as [$method, $target]) {
                    $this->assertFalse($this->closed($target, $method), "{$ui} {$url} offers {$method} {$target}, a closed page");

                    $path = (string) parse_url($target, PHP_URL_PATH);

                    if ($method === 'GET' && str_starts_with($path, '/admin') && ! isset($seen[$path]) && ! preg_match('#/(status|shell/search|search-index|logout|__)#', $path)) {
                        $queue[] = $path;
                    }
                }
            }

            $this->assertGreaterThan(40, $checked, "{$ui}: the crawl reached the admin's pages");
        }
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: User, 1: User} a view-only administrator and a normal one, both in Developer mode with every page shown */
    private function admins(): array
    {
        $demo = User::factory()->admin()->create();
        $demo->forceFill([ViewOnlyDemo::COLUMN => true])->save();
        $owner = User::factory()->admin()->create();

        foreach ([$demo, $owner] as $admin) {
            AdminPreferences::set($admin, 'developer_mode', true);
            AdminPreferences::set($admin, 'show_advanced', true);
        }

        return [$demo->fresh(), $owner->fresh()];
    }

    /** A member's payment with its invoice (Money > Payments & invoices lists it). */
    private function invoice(): \App\Models\Transaction
    {
        $member = User::factory()->create(['username' => 'invoice-buyer-'.uniqid()]);
        $plan = \App\Models\Plan::factory()->create(['duration' => 1, 'price' => 500]);
        $subscription = \App\Models\Subscription::factory()->for($member)->for($plan)->create();

        return \App\Models\Transaction::query()->create([
            'stripe_id' => 'in_test_'.$member->id, 'amount' => 500, 'tax' => 0, 'fee' => 0, 'date' => now()->subDay(),
            'user_id' => $member->id, 'subscription_id' => $subscription->id, 'name' => 'Ana Buyer', 'address' => '1 Long Road',
            'city' => 'Lisbon', 'postal_code' => '1000-001', 'country' => 'PT', 'vat_id' => null,
        ]);
    }

    private function page(User $user, string $url): string
    {
        $response = $this->actingAs($user)->get($url);
        $response->assertOk();

        return (string) $response->getContent();
    }

    private function searchIndex(User $user): array
    {
        $this->actingAs($user)->get('/admin')->assertOk();
        $html = $this->page($user, '/admin');
        preg_match('/data-index-url="([^"]+)"/', $html, $match);
        $this->assertNotEmpty($match, 'the palette names its index');

        return $this->actingAs($user)->getJson(html_entity_decode($match[1]))->assertOk()->json();
    }

    /** @return list<string> the paths of the new admin's menu links (sidebar, its "N more" pages too) */
    private function navLinks(string $html): array
    {
        return $this->links($html, '//nav[contains(@class,"ad-nav")]//a[@href]');
    }

    /** @return list<string> */
    private function links(string $html, string $query): array
    {
        $xpath = $this->xpath($html);
        $links = [];

        foreach ($xpath->query($query) as $node) {
            $path = (string) parse_url((string) $node->getAttribute('href'), PHP_URL_PATH);

            if ($path !== '') {
                $links[] = rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
            }
        }

        return array_values(array_unique($links));
    }

    /** @return list<array{0: string, 1: string}> every [method, address] of a page: links, forms and form buttons, data addresses */
    private function targets(string $html): array
    {
        $xpath = $this->xpath($html);
        $out = [];

        foreach ($xpath->query('//a[@href] | //area[@href]') as $node) {
            $out[] = ['GET', (string) $node->getAttribute('href')];
        }

        foreach ($xpath->query('//form[@action]') as $form) {
            $method = strtoupper((string) $form->getAttribute('method')) ?: 'GET';
            $spoofed = $xpath->query('.//input[@name="_method"]', $form)->item(0);
            $method = $spoofed !== null ? strtoupper((string) $spoofed->getAttribute('value')) : $method;
            $out[] = [$method === '' ? 'GET' : $method, (string) $form->getAttribute('action')];
        }

        foreach ($xpath->query('//*[@formaction]') as $node) {
            $out[] = ['POST', (string) $node->getAttribute('formaction')];
        }

        foreach ($xpath->query('//@*[starts-with(name(), "data-") and contains(name(), "url")]') as $attribute) {
            $out[] = ['GET', (string) $attribute->nodeValue];
        }

        return array_values(array_filter($out, fn (array $target): bool => $target[1] !== '' && ! str_starts_with($target[1], '#')
            && ! str_starts_with($target[1], 'javascript:') && ! str_starts_with($target[1], 'mailto:')));
    }

    /** Whether an address of this site (with this method) is a route a view-only account is refused as a closed page. */
    private function closed(string $url, string $method = 'GET'): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ((is_string($host) && $host !== '' && $host !== parse_url(config('app.url'), PHP_URL_HOST) && $host !== 'localhost') || $url === '') {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        try {
            $route = app('router')->getRoutes()->match(Request::create($path === '' ? '/' : $path, $method === 'HEAD' ? 'GET' : $method));
        } catch (Throwable) {
            return false;
        }

        return ViewOnlyDemo::closedRoute((string) $route->getName());
    }

    /** The kind of an admin page: its route name, and which page for the settings and Appearance pages ({page}). */
    private function kindOf(string $path): ?string
    {
        try {
            $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));
        } catch (Throwable) {
            return null;
        }

        return $route->getName().'|'.(string) ($route->parameters()['page'] ?? '');
    }

    /** @param list<string> $links @return list<string> */
    private function closedOf(array $links): array
    {
        return array_values(array_filter($links, fn (string $link): bool => $this->closed($link)));
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
