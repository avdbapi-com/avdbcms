<?php

namespace App\Support;

use App\Enums\ReportStatus;
use App\Http\Middleware\EnsureAdminOperationalToolsEnabled;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Throwable;

/**
 * The redesigned admin's navigation (config/admin_nav.php): which page a request belongs to, what the
 * sidebar shows to this admin, page tabs and breadcrumbs, and which routes are Developer-mode only.
 *
 * Pages whose route another release step adds (new Settings, Money and Appearance pages) link to their
 * old settings tab until the route exists, so the menu never leads to a 404.
 */
final class AdminNav
{
    /** @var array<string, array>|null page id => page (with 'id' and 'section') */
    private static ?array $pages = null;

    /** @var array<string, string|null> */
    private static array $urls = [];

    /** The application the lookups belong to (tests boot a new one per test). */
    private static ?int $appId = null;

    public static function config(): array
    {
        return (array) config('admin_nav', []);
    }

    /** @return array<string, array> */
    public static function pages(): array
    {
        self::guardApp();

        if (self::$pages !== null) {
            return self::$pages;
        }

        $pages = [];

        foreach ((array) (self::config()['sections'] ?? []) as $sectionId => $section) {
            foreach ((array) ($section['pages'] ?? []) as $pageId => $page) {
                $pages[$pageId] = $page + ['id' => $pageId, 'section' => $sectionId];
            }
        }

        return self::$pages = $pages;
    }

    public static function page(?string $id): ?array
    {
        return $id === null ? null : (self::pages()[$id] ?? null);
    }

    public static function section(string $id): ?array
    {
        $section = self::config()['sections'][$id] ?? null;

        return is_array($section) ? $section + ['id' => $id] : null;
    }

    /** The page of the current request: explicit override, route + parameters, route name, then URL path. */
    public static function current(?Request $request = null): ?array
    {
        $request ??= request();
        $override = $request->attributes->get('admin.page');

        if (is_string($override) && ($page = self::page($override)) !== null) {
            return $page;
        }

        $route = $request->route();
        $name = $route instanceof Route ? (string) $route->getName() : '';

        if ($name !== '') {
            foreach (self::pages() as $page) {
                if (($page['route'] ?? null) === $name && ! empty($page['params']) && self::paramsMatch($route, $page['params'])) {
                    return $page;
                }
            }

            $pageId = self::config()['routes'][$name] ?? null;

            if (is_string($pageId) && ($page = self::page($pageId)) !== null) {
                return $page;
            }

            foreach (self::pages() as $page) {
                if (($page['route'] ?? null) === $name && empty($page['params'])) {
                    return $page;
                }
            }
        }

        $path = '/'.trim($request->path(), '/');

        foreach (self::pages() as $page) {
            if (($page['url'] ?? null) === $path) {
                return $page;
            }
        }

        return null;
    }

    /** Where a page (or tab) link goes, or null when it cannot be opened here (route missing or switched off). */
    public static function url(array $page): ?string
    {
        self::guardApp();
        $key = ($page['id'] ?? '').'|'.($page['route'] ?? '').'|'.json_encode($page['params'] ?? []).'|'.(config('admin.operational_tools_enabled') ? 1 : 0);

        if (array_key_exists($key, self::$urls)) {
            return self::$urls[$key];
        }

        $routeName = (string) ($page['route'] ?? '');
        $route = $routeName !== '' ? RouteFacade::getRoutes()->getByName($routeName) : null;
        $url = null;

        if ($route instanceof Route) {
            if (self::reachable($route)) {
                try {
                    $url = route($routeName, (array) ($page['params'] ?? []));
                } catch (Throwable) {
                    $url = null;
                }
            }
        } elseif (! empty($page['legacy_url'])) {
            $url = url((string) $page['legacy_url']);
        }

        return self::$urls[$key] = $url;
    }

    /**
     * The sidebar for this admin: sections in order with their listed pages, the "N more" advanced pages,
     * Developer-mode pages and badges.
     *
     * @return list<array{id: string, label: string, icon: string, group: int, url: ?string, active: bool, open: bool, current: bool, pages: list<array>, more: list<array>, badge: ?array}>
     */
    public static function sidebar(?User $user, ?array $current): array
    {
        $developer = AdminPreferences::developerMode($user);
        $showAdvanced = AdminPreferences::showAdvanced($user);
        $badges = self::badges();
        $out = [];

        foreach ((array) (self::config()['sections'] ?? []) as $sectionId => $section) {
            $listed = [];
            $more = [];
            $first = null;

            foreach ((array) ($section['pages'] ?? []) as $pageId => $page) {
                $page += ['id' => $pageId, 'section' => $sectionId];

                if (! self::visibleTo($page, $developer)) {
                    continue;
                }

                $url = self::url($page);

                if ($url === null) {
                    continue;
                }

                $isCurrent = $current !== null && $current['id'] === $pageId;
                $first ??= $url;

                if (! ($page['sidebar'] ?? true)) {
                    continue;
                }

                $item = ['id' => $pageId, 'label' => $page['label'], 'url' => $url, 'current' => $isCurrent, 'level' => $page['level']];

                if (($page['level'] ?? 'basic') === 'advanced' && ! $showAdvanced && ! $isCurrent) {
                    $more[] = $item;
                } else {
                    $listed[] = $item;
                }
            }

            if ($first === null) {
                continue;
            }

            $active = $current !== null && $current['section'] === $sectionId;
            $single = count($listed) + count($more) <= 1;

            $out[] = [
                'id' => $sectionId,
                'label' => $section['label'],
                'icon' => $section['icon'],
                'group' => (int) ($section['group'] ?? 1),
                'url' => $first,
                'active' => $active,
                'open' => $active && ! $single,
                'current' => $active && $single,
                'single' => $single,
                'pages' => $single ? [] : $listed,
                'more' => $single ? [] : $more,
                'badge' => $badges[$sectionId] ?? null,
            ];
        }

        return $out;
    }

    /** Tabs of a merged page (old pages that became tabs keep their URLs). */
    public static function tabs(?array $page, ?Request $request = null): array
    {
        if ($page === null || empty($page['tabs'])) {
            return [];
        }

        $request ??= request();
        $routeName = (string) $request->route()?->getName();
        $tabs = [];

        foreach ($page['tabs'] as $tab) {
            $url = self::url(['route' => $tab['route'], 'params' => []]);

            if ($url === null) {
                continue;
            }

            $prefix = Str::beforeLast($tab['route'], '.').'.';

            $tabs[] = [
                'label' => $tab['label'],
                'url' => $url,
                // Its own route, or another route of the same old page (create/edit forms of that list).
                'active' => $routeName === $tab['route'] || ($prefix !== 'admin.' && $routeName !== '' && Str::startsWith($routeName, $prefix)),
                'new_tab' => (bool) ($tab['new_tab'] ?? false),
            ];
        }

        return count($tabs) > 1 ? $tabs : [];
    }

    /** @return list<array{label: string, url: ?string}> */
    public static function crumbs(?array $page): array
    {
        if ($page === null) {
            return [];
        }

        $section = self::section($page['section']);

        if ($section === null || $page['section'] === 'home') {
            return [];
        }

        return [
            ['label' => $section['label'], 'url' => self::sectionUrl($page['section'], AdminPreferences::developerMode(self::user()))],
            ['label' => $page['label'], 'url' => null],
        ];
    }

    /**
     * The top bar's site status: green only when the site is live, listed by Google and sends email;
     * otherwise amber with the first problem. Each check links to the page that fixes it.
     *
     * @return array{ok: bool, label: string, checks: list<array{label: string, ok: bool, url: ?string}>}
     */
    public static function siteStatus(): array
    {
        $mailer = (string) config('mail.default', 'smtp');
        $checks = [
            ['label' => 'Maintenance mode is on', 'okLabel' => 'Visitors can open the site', 'ok' => ! config('site.maintenance_mode', false), 'page' => 'settings.maintenance'],
            ['label' => 'Hidden from Google', 'okLabel' => 'Google can list the site', 'ok' => ! config('site.robots_noindex', false), 'page' => 'settings.seo'],
            ['label' => 'Emails are not sent', 'okLabel' => 'Emails are sent', 'ok' => ! in_array($mailer, ['log', 'array'], true), 'page' => 'settings.email'],
        ];
        $first = null;
        $out = [];

        foreach ($checks as $check) {
            $page = self::page($check['page']);
            $out[] = [
                'label' => $check['ok'] ? $check['okLabel'] : $check['label'],
                'ok' => $check['ok'],
                'url' => $page !== null ? self::url($page) : null,
            ];
            $first ??= $check['ok'] ? null : $check['label'];
        }

        return ['ok' => $first === null, 'label' => $first ?? 'Site is live', 'checks' => $out];
    }

    /** The first page of a section this admin can open (the section link). */
    public static function sectionUrl(string $sectionId, bool $developer): ?string
    {
        foreach ((array) (self::config()['sections'][$sectionId]['pages'] ?? []) as $pageId => $page) {
            $page += ['id' => $pageId, 'section' => $sectionId];

            if (self::visibleTo($page, $developer) && ($url = self::url($page)) !== null) {
                return $url;
            }
        }

        return null;
    }

    /** The primary action of a page: [label, url] when its route can be opened with a GET. */
    public static function primaryAction(?array $page): ?array
    {
        $primary = $page['primary'] ?? null;

        if (! is_array($primary) || empty($primary['route'])) {
            return null;
        }

        $route = RouteFacade::getRoutes()->getByName($primary['route']);

        if (! $route instanceof Route || ! in_array('GET', $route->methods(), true) || ! self::reachable($route)) {
            return null;
        }

        $request = request();

        // On the page the action already opens (e.g. "Add a pick" on Homepage picks), there is nothing to add.
        if ($request->route()?->getName() === $primary['route']) {
            return null;
        }

        try {
            return ['label' => $primary['label'], 'url' => route($primary['route'])];
        } catch (Throwable) {
            return null;
        }
    }

    public static function isDeveloperPage(?array $page): bool
    {
        return $page !== null && in_array($page['id'], (array) (self::config()['developer']['pages'] ?? []), true);
    }

    /** True for a route that only admins in Developer mode may open. */
    public static function isDeveloperRoute(Route $route): bool
    {
        $name = (string) $route->getName();

        if ($name === '') {
            return false;
        }

        $developer = (array) (self::config()['developer'] ?? []);

        if (Str::is((array) ($developer['routes'] ?? []), $name)) {
            return true;
        }

        foreach ((array) ($developer['param_routes'] ?? []) as $entry) {
            if (($entry['route'] ?? null) === $name && self::paramsMatch($route, (array) ($entry['params'] ?? []))) {
                return true;
            }
        }

        return false;
    }

    /** A page's level against this admin: expert pages only in Developer mode. */
    public static function visibleTo(array $page, bool $developer): bool
    {
        return ($page['level'] ?? 'basic') !== 'expert' || $developer;
    }

    /** Clears the per-process lookups (tests change config and routes between requests). */
    public static function flush(): void
    {
        self::$pages = null;
        self::$urls = [];
    }

    private static function guardApp(): void
    {
        $id = spl_object_id(app());

        if (self::$appId !== $id) {
            self::flush();
            self::$appId = $id;
        }
    }

    /**
     * Sidebar badges, cached for a minute (the admin cache version also changes after every admin action).
     *
     * @return array<string, array{type: string, count: int, label: string}>
     */
    public static function badges(): array
    {
        try {
            return (array) AdminCache::remember('shell.nav_badges', 60, fn (): array => self::countBadges());
        } catch (Throwable) {
            // The cache store is down (AdminCache reads its version key directly): count without it.
            return self::countBadges();
        }
    }

    /** @return array<string, array{type: string, count: int, label: string}> */
    private static function countBadges(): array
    {
        $badges = [];
        $count = fn (callable $query): int => self::safeCount($query);

        $waiting = $count(fn () => DB::table('reports')->where('status', ReportStatus::PENDING->value)->count())
            + $count(fn () => DB::table('contact_messages')->where('is_read', false)->count());

        if ($waiting > 0) {
            $badges['community'] = ['type' => 'attention', 'count' => $waiting, 'label' => 'waiting'];
        }

        if ($count(fn () => DB::table('failed_jobs')->count()) > 0) {
            $badges['tools'] = ['type' => 'warning', 'count' => 0, 'label' => 'needs attention'];
        }

        $running = $count(fn () => DB::table('avdb_import_runs')->whereIn('status', ['queued', 'starting', 'running'])->count())
            + $count(fn () => DB::table('xvid_import_runs')->whereIn('status', ['queued', 'starting', 'running'])->count());

        if ($running > 0) {
            $badges['content'] = ['type' => 'running', 'count' => 0, 'label' => 'import running'];
        }

        return $badges;
    }

    private static function safeCount(callable $query): int
    {
        try {
            return (int) $query();
        } catch (Throwable) {
            return 0;
        }
    }

    private static function reachable(Route $route): bool
    {
        if (config('admin.operational_tools_enabled', false)) {
            return true;
        }

        $middleware = $route->gatherMiddleware();

        return ! in_array('admin.ops', $middleware, true) && ! in_array(EnsureAdminOperationalToolsEnabled::class, $middleware, true);
    }

    private static function paramsMatch(Route $route, array $params): bool
    {
        foreach ($params as $key => $value) {
            if ((string) $route->parameter($key) !== (string) $value) {
                return false;
            }
        }

        return $params !== [];
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
