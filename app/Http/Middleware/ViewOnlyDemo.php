<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ViewOnlyDemoWindow;
use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * View-only demo accounts (users.is_view_only, switched by an administrator in Members > one member). Such an account
 * may open the whole site and, when it is an administrator too, the admin, but nothing it does is kept:
 *
 *  - a request that would change something (POST, PUT, PATCH, DELETE) is refused before the site's code runs: a page
 *    goes back with the message, a script gets a 403 JSON answer with it. Only signing in and out (what they write is
 *    kept, so signing out really ends the session and the app's token), the language switch and the live-notification
 *    handshake pass; the player's view counter and resume point get the answer the site gives when it stores nothing;
 *  - pages that hand out files, private data, the server or another account are closed: backups, logs, exports and
 *    other downloads, taking over another account, the .env editor, database, system and PHP info, the health check,
 *    commands, queue, cron and failed jobs, the maintenance secret, the Upload18 upload settings, payments, linking
 *    a social account and (1.2.3) the AVDBAPI and XVIDAPI import tools; a closed page is not offered to such an
 *    account either (hides(): no menu entry, Home card, search result, tab or button leads there);
 *  - everything else runs inside a database transaction that is rolled back at the end, so a page that writes
 *    something (a "seen" mark, a counter) keeps nothing;
 *  - what it is shown is cleaned: the values of keys, passwords, tokens and account names from the settings, the
 *    config and the .env, e-mail addresses, IP addresses (not the site's own address), server paths, session ids and
 *    credential fields are masked; and since a search that still matched the masked addresses would tell "this address
 *    exists" one guess at a time, the account searches it runs leave the e-mail column out (withoutEmailSearch());
 *  - each page carries a small "View-only demo" note (class avd-view-only-note), each answer the header
 *    X-View-Only-Demo: 1; every refusal (a page that went back, a script's 403, a closed page opened by a link) opens
 *    one "This is a demo" window on the page (App\Support\ViewOnlyDemoWindow).
 * Every other account and every visitor is not touched. Appended to the web and api middleware groups, so it runs after
 * the session and the API's optional sign-in. Until "Finish update" has added the column, no account is view-only.
 * Older versions do not know the flag: "Undo this update" back to one waits while view-only accounts exist
 * (undoRefusal(), asked by Updater::undo()).
 */
final class ViewOnlyDemo
{
    public const COLUMN = 'is_view_only';

    public const HEADER = 'X-View-Only-Demo';

    public const NOTE_CLASS = 'avd-view-only-note';

    /** The first AVDBCMS version that knows view-only accounts: older code would let them change things again. */
    public const SINCE_VERSION = '1.2.2';

    /** The global scope of App\Models\User that leaves the e-mail column out of a view-only request's searches. */
    private const EMAIL_SEARCH_SCOPE = 'view-only-demo-email-search';

    /** True while a view-only request runs (the scope above does nothing otherwise). */
    private static bool $hidingEmails = false;

    /** Set on the request once the guard has taken it (a request never runs through it twice). */
    private const ATTRIBUTE = 'view_only_demo';

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** Requests that pass in any method (route names, then paths); what they write is rolled back like a page's. */
    private const ALLOWED_NAMES = ['lang.update'];

    private const ALLOWED_PATHS = ['broadcasting/auth'];

    /**
     * Signing in and out pass too, and what they write is kept: signing out must end the session's remember token and
     * delete the app's token (a rolled-back sign-out would leave them valid).
     */
    private const SIGN_IN_OUT_NAMES = ['login.perform', 'logout', 'me.logout'];

    private const SIGN_IN_OUT_PATHS = ['api/login'];

    /** The player's background calls: the answer the site gives when it stores nothing, without running them. */
    private const QUIET = [
        'videos.view' => [204, null],
        'videos.progress' => [202, ['stored' => false]],
    ];

    /** verdict(): one of the QUIET calls, answered without running it. */
    private const ANSWERED_QUIETLY = 'quiet';

    /**
     * GET addresses that only start a change: the upload's settings (they carry the Upload18 key) and progress, a
     * payment, linking a social account.
     */
    private const STARTS_CHANGE = [
        'videos.upload18.config', 'videos.upload18.status',
        'premium.subscribe', 'premium.subscribe.paypal', 'premium.paypal.return',
        'oauth.connect', 'oauth.callback', 'oauth.unlink',
    ];

    /**
     * Closed in every method (route name patterns): files, private data, the server, another account. Any other answer
     * that is a download is refused too (isDownload()), so a download added later is closed by default.
     */
    private const CLOSED = [
        'admin.impersonate',
        'admin.backups.*', 'admin.logs', 'admin.logs.*', 'log-viewer.*', 'admin.exports.*',
        '*.export', '*.download', '*.request-download',
        // A member's invoice PDF: billing name, address and VAT id (an administrator can open every member's).
        'user.invoices.show',
        'admin.env-editor.*', 'admin.settings.update.env', 'admin.database.*', 'admin.system.*', 'admin.phpinfo.*',
        'admin.preflight.*', 'admin.artisan.*', 'admin.queue.*', 'horizon.*', 'admin.horizon', 'admin.cron.*',
        'admin.failed-jobs.*', 'admin.audit.*',
        'admin.settings.maintenance.reveal',
        // 1.2.3: the AVDBAPI and XVIDAPI import tools (their pages, settings, runs, schedules, progress, logs, exports
        // and data answers): they show the owner's import settings (API addresses, categories, schedules) and start
        // imports. Their scheduler buttons (admin.cron.*) are closed above.
        'admin.avdb-import.*', 'admin.xvid-import.*',
    ];

    /** The header a refusal carries besides HEADER: what was refused (REFUSED_CHANGE, REFUSED_CLOSED). */
    public const REFUSAL_HEADER = 'X-View-Only-Demo-Refusal';

    /** A change was refused (any account action). */
    public const REFUSED_CHANGE = 'change';

    /** A closed page (CLOSED) was opened. */
    public const REFUSED_CLOSED = 'closed';

    /** Request attribute: whether this request's account is view-only (active(), worked out once per request). */
    private const ACTIVE_ATTRIBUTE = 'view_only_demo.active';

    /**
     * The last refusal that sent the account back (session): the refused path, where it went, when. A script (no click:
     * the browser sends no "Sec-Fetch-User: ?1") that opens the same closed page again within BOUNCE_SECONDS is sent Home
     * instead of to the same page again; a person clicking again is always sent back to the page they were on.
     */
    private const BOUNCE_KEY = 'view_only_demo.back';

    private const BOUNCE_SECONDS = 3;

    /**
     * The last page view of the account that answered with a redirect (session): from which path, to which path, when.
     * A refusal reached that way never sends the account back to the page that redirected it (it would redirect again).
     * Cleared by the next page view that is no redirect; older than REDIRECT_SECONDS it no longer counts.
     */
    private const REDIRECT_KEY = 'view_only_demo.redirect';

    private const REDIRECT_SECONDS = 60;

    /** Setting, config and .env names whose values are masked (the last part of a config path counts). */
    private const SECRET_NAME = '/(secret|passw|^pass$|_pass$|token|private|dsn|webhook|signature|salt|api_?key|access_?key|^key$|_key$|username|^user$|_user$|client_id|account_id|merchant|^db_database$|^database$|^db_host$|^mail_host$|^smtp_host$)/i';

    /** Of those, the ones a public page may carry (a site key, a publishable key, a client id): masked in the admin only. */
    private const PUBLIC_NAME = '/(site_key|public|publishable|indexnow|app_id|client_id|vite_|pusher_app_key|reverb_app_key|stripe\.key|stripe_key)/i';

    /** Values never treated as secrets (switches, drivers, words a page shows anyway). */
    private const PLAIN_VALUE = '/^(true|false|null|none|tls|ssl|smtp|log|array|file|redis|mysql|mariadb|sqlite|pgsql|database|local|public|sync|default|bcrypt|argon2?id?|localhost|127\.0\.0\.1|sendmail|mailgun|ses|postmark|resend|cookie|session)$/i';

    private const MASK = '••••••';

    private const PATH_MASK = '[server path]';

    /** @var array{always: array<string, string>, admin: array<string, string>}|null */
    private ?array $secrets = null;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get(self::ATTRIBUTE) === true || ! self::appliesTo($request)) {
            return $next($request);
        }

        $request->attributes->set(self::ATTRIBUTE, true);

        [$name, $path] = self::routeOf($request);

        switch (self::verdict($request, $name, $path)) {
            case self::REFUSED_CLOSED:
                return $this->refuse($request, __('This page is not part of the view-only demo: it would show private files or server details.'), self::REFUSED_CLOSED);

            case self::REFUSED_CHANGE:
                return $this->refuse($request, __('This is a view-only demo account: you can look at everything, but changes are switched off.'));

            case self::ANSWERED_QUIETLY:
                [$status, $body] = self::QUIET[$name];

                return $this->tag($body === null ? response()->noContent($status) : new JsonResponse($body, $status));
        }

        $signInOut = self::signsInOrOut($name, $path);
        $response = self::withoutEmailSearch(fn (): Response => $signInOut ? $next($request) : $this->rolledBack(static fn () => $next($request)));
        self::rememberRedirect($request, $response);

        return $this->clean($request, $response);
    }

    /**
     * The route name and the path of a request ('' when no named route took it).
     *
     * @return array{0: string, 1: string}
     */
    private static function routeOf(Request $request): array
    {
        $route = $request->route();

        return [$route instanceof Route ? (string) $route->getName() : '', trim($request->path(), '/')];
    }

    private static function signsInOrOut(string $name, string $path): bool
    {
        return in_array($name, self::SIGN_IN_OUT_NAMES, true) || in_array($path, self::SIGN_IN_OUT_PATHS, true);
    }

    /**
     * What the guard does with a request of a view-only account before the site's code runs: REFUSED_CLOSED (a closed
     * page, in any method), REFUSED_CHANGE (a change, or a GET that only starts one), ANSWERED_QUIETLY (the player's
     * background calls), or null: it runs (rolled back, unless it signs in or out). The one rule of handle() and
     * answersItself().
     */
    private static function verdict(Request $request, string $name, string $path): ?string
    {
        if (self::closedRoute($name)) {
            return self::REFUSED_CLOSED;
        }

        if (in_array($name, self::STARTS_CHANGE, true)) {
            return self::REFUSED_CHANGE;
        }

        if (self::signsInOrOut($name, $path) || in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return null;
        }

        if (isset(self::QUIET[$name])) {
            return self::ANSWERED_QUIETLY;
        }

        return in_array($name, self::ALLOWED_NAMES, true) || in_array($path, self::ALLOWED_PATHS, true) ? null : self::REFUSED_CHANGE;
    }

    /**
     * Whether this request is one the guard answers itself, without running the site's code: a view-only account's
     * request that is refused (a closed page, a change) or answered quietly. A route limit ("throttle:...") must leave
     * such a request to the guard (App\Http\Middleware\ThrottleExceptViewOnlyRefusals): nothing runs, so there is
     * nothing to limit, and the account gets its "This is a demo" window instead of "Too many tries" from the fourth
     * click on. Asked before the guard runs (the limits come first), it works the account out itself.
     */
    public static function answersItself(Request $request): bool
    {
        if ($request->attributes->get(self::ATTRIBUTE) !== true && ! self::appliesTo($request)) {
            return false;
        }

        [$name, $path] = self::routeOf($request);

        return self::verdict($request, $name, $path) !== null;
    }

    /**
     * For back(): whether this page view answered with a redirect, and where to. A prefetch, a background request (it
     * keeps nothing in the session) and a request that is not a page view (another method) leave the mark as it is.
     */
    private static function rememberRedirect(Request $request, Response $response): void
    {
        if (strtoupper($request->getRealMethod()) !== 'GET' || $request->prefetch() || BackgroundRequest::is($request) || ! $request->hasSession()) {
            return;
        }

        try {
            $session = $request->session();
            $location = $response->isRedirection() ? (string) $response->headers->get('Location', '') : '';

            if ($location !== '') {
                $session->put(self::REDIRECT_KEY, [
                    'from' => self::pathOf($request, $request->getRequestUri()),
                    'to' => self::pathOf($request, $location),
                    'at' => now()->getTimestamp(),
                ]);
            } elseif ($session->has(self::REDIRECT_KEY)) {
                $session->forget(self::REDIRECT_KEY);
            }
        } catch (Throwable) {
            // no session: nothing to remember
        }
    }

    // ------------------------------------------------------------------ who

    /** Whether the signed-in account of this request (or the account that took it over) is a view-only demo account. */
    public static function appliesTo(Request $request): bool
    {
        try {
            $user = $request->user();

            if ($user === null && $request->bearerToken() !== null && array_key_exists('sanctum', (array) config('auth.guards'))) {
                $user = auth('sanctum')->user();
            }

            if (self::isViewOnlyUser($user)) {
                return true;
            }
        } catch (Throwable) {
            // no account could be read: the check below still runs
        }

        // An account taken over (impersonated) by a view-only account counts as that account.
        try {
            if ($request->hasSession()) {
                $by = $request->session()->get((string) config('laravel-impersonate.session_key', 'impersonated_by'));

                if (is_numeric($by) && (int) $by > 0) {
                    return self::isViewOnlyUser(User::query()->find((int) $by));
                }
            }
        } catch (Throwable) {
            // no session, or no users table yet
        }

        return false;
    }

    /** Whether the route (by its name) is closed for a view-only account in every method. */
    public static function closedRoute(string $name): bool
    {
        return $name !== '' && Str::is(self::CLOSED, $name);
    }

    /**
     * Whether the account of this request is a view-only demo account: the guard has taken the request, or
     * appliesTo() says so (worked out once per request: menus ask it for every link).
     */
    public static function active(?Request $request = null): bool
    {
        try {
            $request ??= request();
        } catch (Throwable) {
            return false;
        }

        if ($request->attributes->get(self::ATTRIBUTE) === true) {
            return true;
        }

        $known = $request->attributes->get(self::ACTIVE_ATTRIBUTE);

        if (is_bool($known)) {
            return $known;
        }

        $active = self::appliesTo($request);
        $request->attributes->set(self::ACTIVE_ATTRIBUTE, $active);

        return $active;
    }

    /**
     * The one rule of what a view-only account is offered (1.2.3): a menu entry, Home card or link, Ctrl+K or Settings
     * search result, breadcrumb, tab or button that leads to a closed route (CLOSED) is left out for such an account,
     * in the new admin and the old one. Every other account and every visitor is offered everything as before.
     */
    public static function hides(?string $routeName, ?Request $request = null): bool
    {
        return $routeName !== null && self::closedRoute($routeName) && self::active($request);
    }

    /** hides() for an address of this site: the route a GET of it opens (false for another site or no route). */
    public static function hidesUrl(?string $url, ?Request $request = null): bool
    {
        if ($url === null || $url === '' || ! self::active($request)) {
            return false;
        }

        $request ??= request();
        $host = parse_url($url, PHP_URL_HOST);

        if ((is_string($host) && $host !== '' && strcasecmp($host, $request->getHost()) !== 0) || str_starts_with($url, '//')) {
            return false;
        }

        $path = self::routePath($request, $url);

        if ($path === null) {
            return false;
        }

        try {
            $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));
        } catch (Throwable) {
            return false; // no page answers there
        }

        return self::closedRoute((string) $route->getName());
    }

    /** Whether a view-only account is refused when it opens the route (GET): a closed page, or a GET that starts a change. */
    public static function refusesGet(string $name): bool
    {
        return self::closedRoute($name) || in_array($name, self::STARTS_CHANGE, true);
    }

    /** The flag of a loaded account (false before the column exists: "Finish update" has not run yet). */
    public static function isViewOnlyUser(mixed $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $value = $user->getAttributes()[self::COLUMN] ?? null;

        return in_array($value, [true, 1, '1'], true);
    }

    /** Whether users.is_view_only exists (a loaded account answers without a query). */
    public static function available(?User $user = null): bool
    {
        if ($user !== null && array_key_exists(self::COLUMN, $user->getAttributes())) {
            return true;
        }

        try {
            return Schema::hasTable('users') && Schema::hasColumn('users', self::COLUMN);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Why "Undo this update" back to $version must wait, or null. A version older than SINCE_VERSION does not know
     * view-only accounts: after the undo they could change things again, and a view-only administrator would be a full
     * administrator (the database stays, the guard goes with the files). Updater::undo() asks before it plans.
     */
    public static function undoRefusal(string $version): ?string
    {
        if (preg_match('/^\d+\.\d+\.\d+/', $version) === 1 && version_compare($version, self::SINCE_VERSION, '>=')) {
            return null;
        }

        if (! self::available() || ! User::query()->where(self::COLUMN, true)->exists()) {
            return null;
        }

        return __('Undo has to wait: this site has view-only demo accounts, and the older version does not know them. After the undo they could change things, and a view-only administrator would be a full administrator. In Members > All members, delete those accounts, or turn off View-only on them and give them a new password. Then undo.');
    }

    // ------------------------------------------------------------------ searches

    /**
     * Runs $run while the account searches leave the e-mail column out. The addresses are masked in what a view-only
     * account sees, but a search that still matched them (Members, the admin's search box, invoices, e-mail
     * confirmation) would answer "this address exists", one guess at a time. Names and the other columns are still
     * searched. Only a view-only request runs through here, so every other account searches as always.
     *
     * @template T
     *
     * @param  Closure(): T  $run
     * @return T
     */
    public static function withoutEmailSearch(Closure $run): mixed
    {
        if (! User::hasGlobalScope(self::EMAIL_SEARCH_SCOPE)) {
            User::addGlobalScope(self::EMAIL_SEARCH_SCOPE, static function (EloquentBuilder $builder): void {
                if (self::$hidingEmails) {
                    self::withoutEmailMatches($builder->getQuery());
                }
            });
        }

        $was = self::$hidingEmails;
        self::$hidingEmails = true;

        try {
            return $run();
        } finally {
            self::$hidingEmails = $was;
        }
    }

    /**
     * Whether the searches of this request leave the e-mail column out: then a list cached by its search must neither
     * be read (another account's result would answer for the address) nor be written (it would miss for the others).
     */
    public static function hidesEmails(): bool
    {
        return self::$hidingEmails;
    }

    /**
     * Every e-mail match in the conditions of a query (LIKE, REGEXP and their NOT forms; in groups and sub-queries too)
     * becomes a condition that matches nothing (a NOT form: that leaves nothing out). It keeps its place and its value,
     * so the other conditions and their values stay exactly as they were.
     */
    public static function withoutEmailMatches(QueryBuilder $query): void
    {
        foreach ($query->wheres as $index => $where) {
            if (($where['query'] ?? null) instanceof QueryBuilder) {
                self::withoutEmailMatches($where['query']);

                continue;
            }

            $type = (string) ($where['type'] ?? '');

            if (! in_array($type, ['Basic', 'Like'], true) || ! is_string($where['column'] ?? null)) {
                continue;
            }

            $column = strtolower(trim(Str::afterLast(str_replace(['`', '"', '[', ']'], '', $where['column']), '.')));
            $operator = $type === 'Like'
                ? (empty($where['not']) ? 'like' : 'not like')
                : strtolower(trim((string) ($where['operator'] ?? '')));

            if ($column !== 'email' || preg_match('/\b(like|ilike|regexp|rlike|similar to)\b/', $operator) !== 1) {
                continue;
            }

            $matchesNothing = ! str_starts_with($operator, 'not ');
            $bound = ! (($where['value'] ?? null) instanceof Expression);

            $query->wheres[$index] = [
                'type' => 'raw',
                'sql' => $matchesNothing
                    ? ($bound ? '(1 = 0 and ? is not null)' : '(1 = 0)')
                    : ($bound ? '(1 = 1 or ? is null)' : '(1 = 1)'),
                'boolean' => (string) ($where['boolean'] ?? 'and'),
            ];
        }
    }

    // ------------------------------------------------------------------ refusing

    /**
     * $kind (REFUSED_CHANGE, REFUSED_CLOSED) goes with the refusal for the page's "This is a demo" window
     * (App\Support\ViewOnlyDemoWindow): a script gets it in REFUSAL_HEADER and the answer's demo_window, a page that
     * goes back gets it as a one-time value of the session and opens the window.
     */
    private function refuse(Request $request, string $message, string $kind = self::REFUSED_CHANGE): Response
    {
        if (self::fromScript($request)) {
            $response = $this->tag(new JsonResponse([
                'ok' => false,
                'success' => false,
                'message' => $message,
                'error' => $message,
                'errors' => ['view_only_demo' => [$message]],
                'demo_window' => ViewOnlyDemoWindow::payload($kind),
            ], 403));
            $response->headers->set(self::REFUSAL_HEADER, $kind);

            return $response;
        }

        $to = $this->back($request);

        // Remembered for the bounce check of back(); the redirect mark has done its work. A prefetch (the admin's side
        // menu prefetches its links on hover) is no visit: the click that follows gets the same answer.
        try {
            if (! $request->prefetch()) {
                $request->session()->put(self::BOUNCE_KEY, ['from' => $request->getPathInfo(), 'to' => $to, 'at' => now()->getTimestamp()]);
                $request->session()->forget(self::REDIRECT_KEY);
            }
        } catch (Throwable) {
            // no session: nothing to remember
        }

        $response = $this->tag(redirect()->to($to, 303)->withErrors(['view_only_demo' => $message])->with(ViewOnlyDemoWindow::FLASH, $kind));
        $response->headers->set(self::REFUSAL_HEADER, $kind);

        return $response;
    }

    /**
     * Whether a refused request was sent by a script of the page, not by the page itself (a link, a form, the address
     * bar): it asks for JSON, it says it is a script (X-Requested-With: the admin's list row actions ask for the page's
     * HTML that way, as jQuery and axios do), or the browser says it is no page view (Sec-Fetch-Mode other than
     * navigate: fetch() and XMLHttpRequest). A script gets the 403 JSON, which opens the page's "This is a demo" window;
     * a redirect would be followed out of sight, use up the window's one-time value and leave the script to show its
     * own error. A prefetch (the admin's menu prefetches its links on hover) is answered like the page view it prepares.
     */
    private static function fromScript(Request $request): bool
    {
        if ($request->expectsJson() || $request->isJson() || $request->is('api/*') || ! $request->hasSession()) {
            return true;
        }

        if ($request->prefetch()) {
            return false;
        }

        $mode = strtolower(trim((string) $request->headers->get('Sec-Fetch-Mode', '')));

        return $request->ajax() || ($mode !== '' && $mode !== 'navigate' && $mode !== 'nested-navigate');
    }

    /**
     * Where a refused page sends the account: the page it came from on this site (the Referer, else the session's
     * previous page), but never this address, never another site and never a page this account cannot open either (a
     * closed page, a GET that starts a change, signing out, an admin page for a member, an address only another method
     * answers), else Home (the admin's, for an administrator in the admin). Laravel keeps every page opened as the previous page,
     * refused ones too: two closed pages opened at the same moment (two tabs, a script) used to send the account back
     * and forth between them until the browser gave up. Two more loops are cut short:
     *  - the page that has just sent the account here with a redirect (an old link to a closed page) is not used: it
     *    would send it here again (REDIRECT_KEY);
     *  - a script, not a person, that opens the same closed page again within BOUNCE_SECONDS of its refusal goes Home. A
     *    person's click (the browser sends "Sec-Fetch-User: ?1") always goes back to the page they were on.
     */
    private function back(Request $request): string
    {
        $here = $request->fullUrl();
        $candidates = [(string) $request->headers->get('referer', '')];
        $last = null;
        $redirect = null;

        try {
            $candidates[] = (string) $request->session()->previousUrl();
            $last = $request->session()->get(self::BOUNCE_KEY);
            $redirect = $request->session()->get(self::REDIRECT_KEY);
        } catch (Throwable) {
            // no session
        }

        $user = $request->user();
        $admin = $user instanceof User && (bool) ($user->getAttributes()['is_admin'] ?? false);
        $now = now()->getTimestamp();
        $herePath = self::pathOf($request, $request->getRequestUri());
        $byPerson = $request->headers->get('Sec-Fetch-User') === '?1';

        foreach ($candidates as $url) {
            // an address on this site: the same host, or a path of it ("/admin/users", never "//other.host/...")
            $local = str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_starts_with($url, '/\\');

            if ($url === '' || $url === $here || $url === $request->getRequestUri()
                || ! ($local || strcasecmp((string) parse_url($url, PHP_URL_HOST), $request->getHost()) === 0)) {
                continue;
            }

            if (! $this->opens($request, $url, $admin)) {
                continue;
            }

            $redirectedHere = is_array($redirect) && ($redirect['to'] ?? null) === $herePath
                && ($redirect['from'] ?? null) === self::pathOf($request, $url)
                && is_int($redirect['at'] ?? null) && $now - $redirect['at'] <= self::REDIRECT_SECONDS;

            $bounced = ! $byPerson && is_array($last) && ($last['from'] ?? null) === $request->getPathInfo() && ($last['to'] ?? null) === $url
                && is_int($last['at'] ?? null) && $now - $last['at'] <= self::BOUNCE_SECONDS;

            if (! $redirectedHere && ! $bounced) {
                return $url;
            }
        }

        return url($admin && $request->is('admin', 'admin/*') ? '/admin' : '/');
    }

    /**
     * Whether $url (an address of this site) is a page this account may open: it must name a page (GET) that a
     * view-only account is not refused and that does not sign it out; admin pages only for an administrator.
     */
    private function opens(Request $request, string $url, bool $admin): bool
    {
        $path = self::routePath($request, $url);

        if ($path === null) {
            return false;
        }

        try {
            $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));
        } catch (Throwable) {
            return false; // no page answers there, or only to another method
        }

        $name = (string) $route->getName();

        if (self::refusesGet($name) || in_array($name, self::SIGN_IN_OUT_NAMES, true)
            || in_array(trim($route->uri(), '/'), self::SIGN_IN_OUT_PATHS, true)) {
            return false;
        }

        return $admin || ! ($name === 'admin' || str_starts_with($name, 'admin.') || trim($route->uri(), '/') === 'admin' || str_starts_with(trim($route->uri(), '/'), 'admin/'));
    }

    /**
     * The path the router sees for an address of this site, as public/index.php hands it over: without the folder the
     * site is installed in, a leading /index.php or /public, and a /{language} prefix. Null when it is not an address.
     */
    private static function routePath(Request $request, string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');
        $base = rtrim($request->getBaseUrl(), '/');

        foreach ([$base, '/public/index.php', '/index.php', '/public'] as $prefix) {
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
                $path = (string) substr($path, strlen($prefix));
                $path = $path === '' ? '/' : $path;
            }
        }

        $first = explode('/', ltrim($path, '/'))[0];

        if ($first !== '' && array_key_exists($first, (array) config('languages', []))) {
            $path = (string) substr($path, strlen('/'.$first));
            $path = $path === '' ? '/' : $path;
        }

        return $path.(isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '');
    }

    /** routePath() without the query, for comparing two addresses of the same page ('' when it is no address). */
    private static function pathOf(Request $request, string $url): string
    {
        $path = self::routePath($request, $url);

        if ($path === null) {
            return '';
        }

        $path = rtrim(explode('?', $path, 2)[0], '/');

        return $path === '' ? '/' : $path;
    }

    private function tag(Response $response): Response
    {
        $response->headers->set(self::HEADER, '1');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    // ------------------------------------------------------------------ keeping nothing

    private function rolledBack(Closure $run): Response
    {
        try {
            $connection = DB::connection();
            $level = $connection->transactionLevel();
            $connection->beginTransaction();
        } catch (Throwable) {
            return $run(); // no database: nothing could be kept there either
        }

        try {
            return $run();
        } finally {
            try {
                $connection->rollBack($level);
            } catch (Throwable) {
                // the connection is gone: its transaction went with it
            }
        }
    }

    // ------------------------------------------------------------------ cleaning what is shown

    private function clean(Request $request, Response $response): Response
    {
        $response->headers->set(self::HEADER, '1');

        // A download cannot be masked (a PDF, an archive, a database file): refused, whatever page answered it.
        if (self::isDownload($response)) {
            return $this->refuse($request, __('This page is not part of the view-only demo: it would show private files or server details.'), self::REFUSED_CLOSED);
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return $response;
        }

        $type = strtolower((string) $response->headers->get('Content-Type', ''));

        // A page a middleware made (response()->view() there: "Developer mode is off", the admin's address lock) has no
        // type yet: Symfony gives it one when the answer is sent (Response::prepare()), text/html unless the request
        // named another format. The same guess, made now: such a page is masked and carries the note and the window too.
        if ($type === '') {
            $format = $request->getRequestFormat(null);
            $type = strtolower((string) (($format !== null ? $request->getMimeType($format) : null) ?: 'text/html'));
        }

        $html = str_contains($type, 'text/html');
        $json = str_contains($type, 'json');

        if (! $html && ! $json && ! str_contains($type, 'text/plain') && ! str_contains($type, 'xml') && ! str_contains($type, 'csv')) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, private');
        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return $response;
        }

        try {
            // The visitor's own address of the site (an IP on a test server) stays: it is in every asset link.
            $hosts = array_values(array_filter([$request->getHost(), (string) parse_url((string) config('app.url'), PHP_URL_HOST)]));
            $secrets = $this->secrets($request->is('admin', 'admin/*'));
            $paths = self::sitePaths();

            $cleaned = $json
                ? self::maskJson($content, $secrets, $hosts, $paths)
                : self::mask($content, $secrets, $html, $hosts, $paths);
        } catch (Throwable) {
            // Whatever could not be cleaned is not shown.
            $message = __('This page is not part of the view-only demo: it would show private files or server details.');

            $refused = $this->tag($html
                ? response('<!doctype html><meta charset="utf-8"><title>'.e(__('View-only demo account')).'</title><p style="font:16px system-ui;margin:2rem">'.e($message).' <a href="'.e(url('/')).'">'.e(__('Home')).'</a></p>', 403, ['Content-Type' => 'text/html; charset=UTF-8'])
                : new JsonResponse(['ok' => false, 'success' => false, 'message' => $message, 'demo_window' => ViewOnlyDemoWindow::payload(self::REFUSED_CLOSED)], 403));
            $refused->headers->set(self::REFUSAL_HEADER, self::REFUSED_CLOSED);

            return $refused;
        }

        // The note and the "This is a demo" window (only on a view-only account's pages: nobody else loads its code; a
        // redirect's body is never shown).
        if ($html && ! ($response->isRedirection() && $response->headers->has('Location')) && $this->wantsNote($request)) {
            $cleaned = self::withNote($cleaned, ViewOnlyDemoWindow::markup($request, $cleaned));
        }

        if ($cleaned !== $content) {
            if ($response instanceof JsonResponse) {
                $response->setJson($cleaned);
            } else {
                $response->setContent($cleaned);
            }

            $response->headers->remove('Content-Length');
            $response->headers->remove('ETag');
        }

        return $response;
    }

    /**
     * Content types a view-only account may receive: pages and data the guard masks, and media the site shows (videos,
     * thumbnails, avatars and banners, generated SVG avatars, theme CSS, fonts, the sitemap, robots.txt, HLS playlists).
     */
    private const SHOWN_TYPES = '#^(text/(html|plain|css|csv|xml|javascript)|application/(json|[a-z0-9.+-]+\+json|xml|[a-z0-9.+-]+\+xml|javascript|x-javascript|vnd\.apple\.mpegurl|x-mpegurl)|(image|video|audio|font)/[a-z0-9.+-]+)$#';

    /**
     * Whether an answer is a download: "Content-Disposition: attachment", or a content type that is none of SHOWN_TYPES
     * (a PDF, a ZIP, application/octet-stream, a database file ...). An answer without a type passes: the web server
     * sets it for the video files SendFile hands over with X-Accel-Redirect / X-Sendfile / X-LiteSpeed-Location.
     */
    public static function isDownload(Response $response): bool
    {
        $disposition = strtolower(trim((string) $response->headers->get('Content-Disposition', '')));

        if (str_starts_with($disposition, 'attachment')) {
            return true;
        }

        $type = strtolower(trim(explode(';', (string) $response->headers->get('Content-Type', ''))[0]));

        if ($type === '' && $response instanceof BinaryFileResponse) {
            // A file answer gets its type only when it is sent (prepare()): the same guess, made now.
            try {
                $type = strtolower((string) ($response->getFile()->getMimeType() ?: 'application/octet-stream'));
            } catch (Throwable) {
                $type = 'application/octet-stream';
            }
        }

        return $type !== '' && preg_match(self::SHOWN_TYPES, $type) !== 1;
    }

    /** The note goes on pages, not inside the player frame other sites embed or a page shown in a frame. */
    private function wantsNote(Request $request): bool
    {
        if ($request->is('embed/*', 'video/*/embed')) {
            return false;
        }

        return ! in_array(strtolower((string) $request->headers->get('Sec-Fetch-Dest', '')), ['iframe', 'frame', 'embed', 'object'], true);
    }

    /**
     * The page with the "View-only demo" note as the first thing in its body (unchanged when it has no body), and
     * $extra right after it (the "This is a demo" window, App\Support\ViewOnlyDemoWindow::markup()).
     */
    public static function withNote(string $html, string $extra = ''): string
    {
        // The body tag after the head: a "<body>" written inside a script of the head is not the page's body.
        $headEnd = stripos($html, '</head>');
        $offset = $headEnd === false ? 0 : $headEnd;

        if (! preg_match('/<body\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE, $offset)) {
            return $html;
        }

        $at = $match[0][1] + strlen($match[0][0]);

        return substr($html, 0, $at).self::note().$extra.substr($html, $at);
    }

    /**
     * The note: bottom centre (the admin's toasts sit bottom right, above it), clicks go through it. On phones
     * (portrait, and landscape up to 480px tall and 991px wide) the site's own pages show it as a slim banner at the
     * top of the page, in its flow, the way the member pages do (member/_chrome.scss): at the bottom it met "Back to
     * top", the demo site's buttons, the phone bottom bar and the watch page's player. Where the header lies over the
     * top banner (a look's see-through header, fixed there), the note starts below that header: at its real height,
     * measured by the script after the note (its rows and padding: 68px on 768-991px wide landscape phones where
     * --avd-header-h says 64px; --avd-header-h until the script has measured it). While the phone search is open the
     * note steps aside (there and on the member pages), so the header and its search row are at the top of the screen,
     * where the search places its suggestions. On wider screens, where it stays fixed at the bottom centre, it never
     * meets "Back to top" (the bottom-end corner): where a long language's note would come within 8px of the button
     * (German and Russian on tablets), the script sits it 12px above the button; everywhere else it keeps its place.
     */
    public static function note(): string
    {
        $class = self::NOTE_CLASS;
        $sitePhone = 'html:not([data-avd-member])>body.avd-app>.'.$class;

        return '<style>.'.$class.'{position:fixed;z-index:65;left:50%;bottom:16px;transform:translateX(-50%);'
            .'width:max-content;max-width:calc(100vw - 32px);box-sizing:border-box;padding:7px 14px;border-radius:999px;'
            .'background:#1f5bc7;color:#fff;font:600 13px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;'
            .'text-align:center;box-shadow:0 4px 18px rgba(0,0,0,.25);pointer-events:none;opacity:.95}'
            .'@media (max-width:640px){.'.$class.'{bottom:calc(76px + env(safe-area-inset-bottom))}}'
            .'@media (max-width:767.98px),(max-height:480px) and (max-width:991.98px){'.$sitePhone
            .'{position:static;transform:none;width:auto;max-width:none;border-radius:0;box-shadow:none;opacity:1}'
            .'body:has(#mobile-search-panel:not([x-cloak]):not([style*="display: none"]))>.'.$class.'{display:none}'
            .'@supports selector(:has(a)){html[data-avd-header-style="transparent_overlay"]:not([data-avd-member])>body.avd-app.has-hero-zone>.'.$class
            .'{margin-top:var(--avd-vo-head,var(--avd-header-h,56px))}}}'
            // above the settings pages' save bar while it shows
            .'body:has(.ad-savebar:not([hidden])) .'.$class.'{bottom:calc(96px + env(safe-area-inset-bottom))}'
            .'@media print{.'.$class.'{display:none}}</style>'
            .'<div class="'.$class.'" role="note">'.e(__('View-only demo: look around freely, changes are switched off.')).'</div>'
            // "Back to top" and the note on wider screens (the note fixed at the bottom centre): where the note would come
            // within 8px of the button, beside it or over it, the note sits 12px above the button. The button's place is
            // its own layout box: its used left and top (relative to the box both are fixed in, which can be narrower
            // than the page's clientWidth: a scrollbar gutter) and its offset size, not its show/hide animation.
            // Measured as soon as the button is in the page (a child of the body: no deeper watch), again when the
            // window or the button changes size (its label's font, a look); a change tells the demo site's buttons
            // (avd-vo-moved), which rise above the note where they meet. Phones (the note a banner at the top), the
            // member pages and the admin's pages (no "Back to top") are never moved.
            .'<script>(function(d,w){var n=d.currentScript&&d.currentScript.previousElementSibling,b=d.body,e=d.documentElement,t,mt,mo,go,lift,find;'
            .'if(!n||!b)return;'
            .'lift=function(){var s=w.getComputedStyle(n),k=t.offsetHeight,v="",r,c,x,y;'
            .'if(s.position==="fixed"&&s.display!=="none"&&k){r=n.getBoundingClientRect();c=w.getComputedStyle(t);x=parseFloat(c.left);y=parseFloat(c.top);'
            .'if(r.right>x-8&&r.left<x+t.offsetWidth+8&&(n.style.bottom||r.bottom>y-8&&r.top<y+k+8))v=Math.ceil(parseFloat(c.bottom)+k+12)+"px"}'
            .'if(n.style.bottom!==v){n.style.bottom=v;if(w.CustomEvent)n.dispatchEvent(new w.CustomEvent("avd-vo-moved"))}};'
            .'find=function(){t=d.getElementById("back-to-top");if(!t)return false;lift();w.addEventListener("resize",lift);if(w.ResizeObserver)new w.ResizeObserver(lift).observe(t);return true};'
            .'if(!find()&&w.MutationObserver){mt=new w.MutationObserver(function(){if(find())mt.disconnect()});mt.observe(b,{childList:true});'
            .'d.addEventListener("DOMContentLoaded",function(){mt.disconnect();if(!t)find()})}'
            // The see-through header's real height for the margin above (only where that header is fixed over the page's
            // top banner): read as soon as the header is in the page (before it is drawn) and again whenever its size
            // changes (rotation, its font).
            .'if(!w.ResizeObserver||e.getAttribute("data-avd-header-style")!=="transparent_overlay"||!b.classList.contains("has-hero-zone"))return;'
            .'go=function(){var h=d.querySelector("[data-avd-header]");if(!h)return false;new w.ResizeObserver(function(){'
            .'n.style.setProperty("--avd-vo-head",w.getComputedStyle(h).position==="fixed"?h.getBoundingClientRect().height+"px":"")}).observe(h);return true};'
            .'if(!go()&&w.MutationObserver){mo=new w.MutationObserver(function(){if(go())mo.disconnect()});mo.observe(b,{childList:true,subtree:true});'
            .'d.addEventListener("DOMContentLoaded",function(){mo.disconnect()})}})(document,window)</script>';
    }

    /** @return array<string, string> every text form of every secret value => the mask */
    private function secrets(bool $admin): array
    {
        if ($this->secrets === null) {
            $always = [];
            $adminOnly = [];

            $add = static function (string $name, mixed $value) use (&$always, &$adminOnly): void {
                if (! is_string($value)) {
                    return;
                }

                $value = trim($value);
                $leaf = strtolower((string) Str::afterLast($name, '.'));

                // A plain word (a driver, "Administrator") would mask that word in every page; credential fields are
                // emptied by their names anyway.
                if (strlen($value) < 6 || ctype_digit($value) || preg_match(self::PLAIN_VALUE, $value)
                    || (preg_match('/^[A-Za-z]+$/', $value) && strlen($value) < 12)
                    || ! preg_match(self::SECRET_NAME, $leaf)) {
                    return;
                }

                $forms = [$value, htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), rawurlencode($value), urlencode($value)];

                foreach ([0, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
                    $encoded = json_encode($value, $flags);

                    if (is_string($encoded)) {
                        $forms[] = substr($encoded, 1, -1);
                    }
                }

                $public = (bool) preg_match(self::PUBLIC_NAME, strtolower($name));

                foreach ($forms as $form) {
                    if (strlen($form) >= 6) {
                        if ($public) {
                            $adminOnly[$form] = self::MASK;
                        } else {
                            $always[$form] = self::MASK;
                        }
                    }
                }
            };

            try {
                foreach (DB::table('settings')->select(['key', 'value'])->get() as $row) {
                    $add((string) $row->key, $row->value);
                }
            } catch (Throwable) {
                // no settings table: the config and the .env still count
            }

            $walk = static function (mixed $data, string $path) use (&$walk, $add): void {
                if (is_array($data)) {
                    foreach ($data as $key => $value) {
                        $walk($value, $path === '' ? (string) $key : $path.'.'.$key);
                    }
                } else {
                    $add($path, $data);
                }
            };
            $walk(config()->all(), '');

            // The site's .env: the values Laravel read and, when the config is cached (the .env is not read then),
            // the values written in the file.
            foreach (self::envFile(base_path('.env')) as $key => $value) {
                $loaded = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
                $add($key, is_string($loaded) ? $loaded : null);
                $add($key, $value);
            }

            $this->secrets = ['always' => $always, 'admin' => $adminOnly + $always];
        }

        return $admin ? $this->secrets['admin'] : $this->secrets['always'];
    }

    /** @return array<string, string> the names and values written in a .env file (nothing when it cannot be read) */
    private static function envFile(string $file): array
    {
        try {
            $text = is_file($file) ? (string) file_get_contents($file) : '';
        } catch (Throwable) {
            return [];
        }

        $values = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                continue;
            }

            $raw = trim($m[2]);

            if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
                $quote = $raw[0];
                $end = strpos($raw, $quote, 1);
                $value = $end === false ? substr($raw, 1) : substr($raw, 1, $end - 1);
            } else {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $raw));
            }

            $values[$m[1]] = $value;
        }

        return $values;
    }

    /** @return list<string> the site's own folder (plain, JSON-escaped), masked like the well-known server folders */
    private static function sitePaths(): array
    {
        $paths = [];

        foreach (array_unique(array_filter([base_path(), (string) realpath(base_path())])) as $path) {
            $path = rtrim($path, '/\\');

            // A short folder ("/app" in a container) would also match words of the page: those stay.
            if (strlen($path) < 8 || substr_count(str_replace('\\', '/', $path), '/') < 2) {
                continue;
            }

            $paths[] = $path;
            $paths[] = str_replace('/', '\\/', $path);
            $paths[] = str_replace('\\', '\\\\', $path);
        }

        return array_values(array_unique($paths));
    }

    /**
     * A JSON answer, masked as text; when that would break the JSON, every string of it is masked instead, so the
     * answer the page reads always stays valid JSON.
     *
     * @param  array<string, string>  $secrets
     * @param  list<string>  $keepHosts
     * @param  list<string>  $paths
     */
    public static function maskJson(string $json, array $secrets = [], array $keepHosts = [], array $paths = []): string
    {
        $masked = self::mask($json, $secrets, false, $keepHosts, $paths);

        if (self::isJson($masked) || ! self::isJson($json)) {
            return $masked;
        }

        $walk = static function (mixed $value) use (&$walk, $secrets, $keepHosts, $paths): mixed {
            if (is_string($value)) {
                return self::mask($value, $secrets, str_contains($value, '<'), $keepHosts, $paths);
            }

            if (is_array($value)) {
                return array_map($walk, $value);
            }

            if (is_object($value)) {
                // built as an array and cast, so an empty or numeric name stays a name ({} stays {}, not [])
                $properties = [];

                foreach (get_object_vars($value) as $key => $item) {
                    $properties[self::mask((string) $key, $secrets, false, $keepHosts, $paths)] = $walk($item);
                }

                return (object) $properties;
            }

            return $value;
        };

        return json_encode(
            $walk(json_decode($json, false, 512, JSON_THROW_ON_ERROR)),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private static function isJson(string $text): bool
    {
        json_decode($text);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * The text with secret values, e-mail addresses, IP addresses, server paths, session ids and credential fields
     * masked. Throws when a pattern cannot be applied (the caller then shows nothing).
     *
     * @param  array<string, string>  $secrets  every text form of a secret => the mask
     * @param  list<string>  $keepHosts  addresses that stay (the site's own host)
     * @param  list<string>  $paths  the site's own folder in its text forms
     */
    public static function mask(string $text, array $secrets = [], bool $html = true, array $keepHosts = [], array $paths = []): string
    {
        $limit = ini_get('pcre.backtrack_limit');
        @ini_set('pcre.backtrack_limit', '10000000');

        try {
            if ($secrets !== []) {
                $text = strtr($text, $secrets);
            }

            if ($paths !== []) {
                $text = strtr($text, array_fill_keys($paths, self::PATH_MASK));
            }

            // A backslash is only taken where it escapes a slash (JSON): one that escapes a quote must stay, or the
            // JSON around the mask would break.
            $text = self::rx('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', '[private key]', $text);
            $text = self::rx('#\b([a-z][a-z0-9+.\-]*://)[^\s:@/"\'<>\\\\]+:[^\s@/"\'<>\\\\]+@#i', '$1'.self::MASK.'@', $text);
            $text = self::rx('#https?:(?://|\\\\/\\\\/)(?:hooks\.slack\.com(?:/|\\\\/)services|(?:discord|discordapp)\.com(?:/|\\\\/)api(?:/|\\\\/)webhooks|api\.telegram\.org(?:/|\\\\/)bot)(?:\\\\/|[^\s"\'<>\\\\])*#i', '[webhook]', $text);
            $text = self::rx('/\b(sk|rk)_(live|test)_[A-Za-z0-9]{8,}/', '$1_$2_'.self::MASK, $text);
            $text = self::rx('/\bwhsec_[A-Za-z0-9]{8,}/', 'whsec_'.self::MASK, $text);
            $text = self::rx('#\bbase64:[A-Za-z0-9+/\\\\]{40,}={0,2}#', 'base64:'.self::MASK, $text);

            $assets = ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif', 'avif', 'ico', 'css', 'js', 'mjs', 'json', 'woff', 'woff2',
                'ttf', 'otf', 'eot', 'mp4', 'webm', 'm3u8', 'txt', 'map', 'php', 'html', 'xml'];
            $text = self::rxCallback(
                '/(?<![\w.%+-])([A-Za-z0-9])[A-Za-z0-9._%+-]*@([A-Za-z0-9])[A-Za-z0-9-]*(?:\.[A-Za-z0-9-]+)*\.([A-Za-z]{2,24})(?![\w-])/',
                static fn (array $m): string => in_array(strtolower($m[3]), $assets, true) ? $m[0] : $m[1].'•••@'.$m[2].'•••.'.$m[3],
                $text
            );

            $octet = '(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)';
            $text = self::rxCallback(
                '/(?<![\w.])('.$octet.'\.'.$octet.')\.'.$octet.'\.'.$octet.'(?![\w.])/',
                static fn (array $m): string => in_array($m[0], $keepHosts, true) ? $m[0] : $m[1].'.x.x',
                $text
            );
            $text = self::rxCallback(
                '/(?<![\w:.])((?:[0-9a-f]{1,4}:){1,7}:?(?:[0-9a-f]{1,4}:?){1,7})(?![\w:.])/i',
                static function (array $m) use ($keepHosts): string {
                    $ip = $m[1];

                    if (in_array(strtolower($ip), array_map('strtolower', $keepHosts), true)) {
                        return $ip;
                    }

                    // a time (10:44:12) or a plain number list is not an address
                    if ((substr_count($ip, ':') < 3 || ! preg_match('/[a-f]/i', $ip)) && ! str_contains($ip, '::')) {
                        return $ip;
                    }

                    return implode(':', array_slice(explode(':', $ip), 0, 2)).':x:x';
                },
                $text
            );

            // Server folders, plain and JSON-escaped (and drive paths where the site runs on Windows). A path ends
            // before "&" so a path inside an HTML-escaped JSON attribute (&quot;) leaves the attribute whole.
            $text = self::rx('#(?<![\w.:/\\\\-])(?:/home\d*|/var/www|/usr/local|/opt|/srv|/www/wwwroot|/www/sites)/[^\s"\'<>()\\\\&]+#', self::PATH_MASK, $text);
            $text = self::rx('#(?<![\w.:\\\\-])(?:\\\\/home\d*|\\\\/var\\\\/www|\\\\/usr\\\\/local|\\\\/opt|\\\\/srv|\\\\/www\\\\/wwwroot|\\\\/www\\\\/sites)\\\\/(?:\\\\/|[^\s"\'<>()\\\\&])+#', self::PATH_MASK, $text);

            if (PHP_OS_FAMILY === 'Windows') {
                $text = self::rx('#(?<![\w])[A-Z]:\\\\{1,2}[^\s"\'<>\\\\&]+(?:\\\\{1,2}(?=[^\s"\'<>\\\\&])[^\s"\'<>\\\\&]+)+#', self::PATH_MASK, $text);
            }

            // Session ids in the admin's "Signed in now" list (links, plain or JSON-escaped).
            $text = self::rx('~((?:/|\\\\/)admin(?:/|\\\\/)sessions(?:/|\\\\/))[A-Za-z0-9]{40}(?=["\'?/#&\\\\])~', '${1}'.str_repeat('x', 40), $text);

            if ($html) {
                // Credential fields keep their place but show no value.
                $text = self::rxCallback(
                    '/<input\b[^>]*>/i',
                    static function (array $m): string {
                        if (! preg_match('/\bname\s*=\s*["\']([^"\']*)["\']/i', $m[0], $n) || ! self::sensitiveField($n[1])) {
                            return $m[0];
                        }

                        return preg_replace('/\bvalue\s*=\s*("[^"]*"|\'[^\']*\')/i', 'value=""', $m[0]) ?? $m[0];
                    },
                    $text
                );
                $text = self::rxCallback(
                    '#(<textarea\b[^>]*\bname\s*=\s*["\']([^"\']*)["\'][^>]*>)(.*?)(</textarea>)#is',
                    static fn (array $m): string => self::sensitiveField($m[2]) ? $m[1].$m[4] : $m[0],
                    $text
                );
            }

            return $text;
        } finally {
            if (is_string($limit)) {
                @ini_set('pcre.backtrack_limit', $limit);
            }
        }
    }

    private static function sensitiveField(string $name): bool
    {
        $name = strtolower($name);

        if (in_array($name, ['_token', '_method', 'captcha_id', 'captcha_answer', 'experience_key'], true)) {
            return false;
        }

        return (bool) preg_match('/(passw|secret|token|api_?key|access_?key|site_key|indexnow_key|app_key|private_key|client_id|webhook|dsn|account_id|merchant|username|(smtp|mail|db|ftp|redis)_?(user|host))/', $name);
    }

    private static function rx(string $pattern, string $replacement, string $text): string
    {
        $out = preg_replace($pattern, $replacement, $text);

        if ($out === null) {
            throw new RuntimeException('view-only mask failed: '.preg_last_error_msg());
        }

        return $out;
    }

    private static function rxCallback(string $pattern, callable $callback, string $text): string
    {
        $out = preg_replace_callback($pattern, $callback, $text);

        if ($out === null) {
            throw new RuntimeException('view-only mask failed: '.preg_last_error_msg());
        }

        return $out;
    }
}
