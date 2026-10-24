<?php

namespace App\Http\Middleware;

use App\Models\User;
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
 *    commands, queue, cron and failed jobs, the maintenance secret, the Upload18 upload settings, payments and linking
 *    a social account;
 *  - everything else runs inside a database transaction that is rolled back at the end, so a page that writes
 *    something (a "seen" mark, a counter) keeps nothing;
 *  - what it is shown is cleaned: the values of keys, passwords, tokens and account names from the settings, the
 *    config and the .env, e-mail addresses, IP addresses (not the site's own address), server paths, session ids and
 *    credential fields are masked; and since a search that still matched the masked addresses would tell "this address
 *    exists" one guess at a time, the account searches it runs leave the e-mail column out (withoutEmailSearch());
 *  - each page carries a small "View-only demo" note (class avd-view-only-note), each answer the header
 *    X-View-Only-Demo: 1.
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

    /**
     * GET addresses that only start a change: the upload's settings (they carry the Upload18 key) and progress, a
     * payment, linking a social account.
     */
    private const STARTS_CHANGE = [
        'videos.upload18.config', 'videos.upload18.status',
        'premium.subscribe', 'premium.subscribe.paypal', 'premium.paypal.return',
        'oauth.connect', 'oauth.callback', 'oauth.unlink',
    ];

    /** Closed in every method (route name patterns): files, private data, the server, another account. */
    private const CLOSED = [
        'admin.impersonate',
        'admin.backups.*', 'admin.logs', 'admin.logs.*', 'log-viewer.*', 'admin.exports.*',
        '*.export', '*.download', '*.request-download',
        'admin.env-editor.*', 'admin.settings.update.env', 'admin.database.*', 'admin.system.*', 'admin.phpinfo.*',
        'admin.preflight.*', 'admin.artisan.*', 'admin.queue.*', 'horizon.*', 'admin.horizon', 'admin.cron.*',
        'admin.failed-jobs.*', 'admin.audit.*',
        'admin.settings.maintenance.reveal',
    ];

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

        $route = $request->route();
        $name = $route instanceof Route ? (string) $route->getName() : '';
        $path = trim($request->path(), '/');

        if ($name !== '' && Str::is(self::CLOSED, $name)) {
            return $this->refuse($request, __('This page is not part of the view-only demo: it would show private files or server details.'));
        }

        if (in_array($name, self::STARTS_CHANGE, true)) {
            return $this->refuse($request, __('This is a view-only demo account: you can look at everything, but changes are switched off.'));
        }

        $signInOut = in_array($name, self::SIGN_IN_OUT_NAMES, true) || in_array($path, self::SIGN_IN_OUT_PATHS, true);

        if (! $signInOut && ! in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            if (isset(self::QUIET[$name])) {
                [$status, $body] = self::QUIET[$name];

                return $this->tag($body === null ? response()->noContent($status) : new JsonResponse($body, $status));
            }

            if (! in_array($name, self::ALLOWED_NAMES, true) && ! in_array($path, self::ALLOWED_PATHS, true)) {
                return $this->refuse($request, __('This is a view-only demo account: you can look at everything, but changes are switched off.'));
            }
        }

        $response = self::withoutEmailSearch(fn (): Response => $signInOut ? $next($request) : $this->rolledBack(static fn () => $next($request)));

        return $this->clean($request, $response);
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

    private function refuse(Request $request, string $message): Response
    {
        if ($request->expectsJson() || $request->isJson() || $request->is('api/*') || ! $request->hasSession()) {
            return $this->tag(new JsonResponse([
                'ok' => false,
                'success' => false,
                'message' => $message,
                'error' => $message,
                'errors' => ['view_only_demo' => [$message]],
            ], 403));
        }

        return $this->tag(redirect()->to($this->back($request), 303)->withErrors(['view_only_demo' => $message]));
    }

    /** The page the visitor came from on this site (never this address, never another site), else Home. */
    private function back(Request $request): string
    {
        $here = $request->fullUrl();
        $candidates = [(string) $request->headers->get('referer', '')];

        try {
            $candidates[] = (string) $request->session()->previousUrl();
        } catch (Throwable) {
            // no session
        }

        foreach ($candidates as $url) {
            // an address on this site: the same host, or a path of it ("/admin/users", never "//other.host/...")
            $local = str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_starts_with($url, '/\\');

            if ($url !== '' && $url !== $here && $url !== $request->getRequestUri()
                && ($local || strcasecmp((string) parse_url($url, PHP_URL_HOST), $request->getHost()) === 0)) {
                return $url;
            }
        }

        return url($request->is('admin', 'admin/*') ? '/admin' : '/');
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

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return $response;
        }

        $type = strtolower((string) $response->headers->get('Content-Type', ''));
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

            return $this->tag($html
                ? response('<!doctype html><meta charset="utf-8"><title>'.e(__('View-only demo account')).'</title><p style="font:16px system-ui;margin:2rem">'.e($message).' <a href="'.e(url('/')).'">'.e(__('Home')).'</a></p>', 403, ['Content-Type' => 'text/html; charset=UTF-8'])
                : new JsonResponse(['ok' => false, 'success' => false, 'message' => $message], 403));
        }

        if ($html && $this->wantsNote($request)) {
            $cleaned = self::withNote($cleaned);
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

    /** The note goes on pages, not inside the player frame other sites embed or a page shown in a frame. */
    private function wantsNote(Request $request): bool
    {
        if ($request->is('embed/*', 'video/*/embed')) {
            return false;
        }

        return ! in_array(strtolower((string) $request->headers->get('Sec-Fetch-Dest', '')), ['iframe', 'frame', 'embed', 'object'], true);
    }

    /** The page with the "View-only demo" note as the first thing in its body (unchanged when it has no body). */
    public static function withNote(string $html): string
    {
        // The body tag after the head: a "<body>" written inside a script of the head is not the page's body.
        $headEnd = stripos($html, '</head>');
        $offset = $headEnd === false ? 0 : $headEnd;

        if (! preg_match('/<body\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE, $offset)) {
            return $html;
        }

        $at = $match[0][1] + strlen($match[0][0]);

        return substr($html, 0, $at).self::note().substr($html, $at);
    }

    /** The note: bottom centre (the admin's toasts sit bottom right, above it), clicks go through it. */
    public static function note(): string
    {
        $class = self::NOTE_CLASS;

        return '<style>.'.$class.'{position:fixed;z-index:65;left:50%;bottom:16px;transform:translateX(-50%);'
            .'width:max-content;max-width:calc(100vw - 32px);box-sizing:border-box;padding:7px 14px;border-radius:999px;'
            .'background:#1f5bc7;color:#fff;font:600 13px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;'
            .'text-align:center;box-shadow:0 4px 18px rgba(0,0,0,.25);pointer-events:none;opacity:.95}'
            .'@media (max-width:640px){.'.$class.'{bottom:calc(76px + env(safe-area-inset-bottom))}}'
            // above the settings pages' save bar while it shows
            .'body:has(.ad-savebar:not([hidden])) .'.$class.'{bottom:calc(96px + env(safe-area-inset-bottom))}'
            .'@media print{.'.$class.'{display:none}}</style>'
            .'<div class="'.$class.'" role="note">'.e(__('View-only demo: look around freely, changes are switched off.')).'</div>';
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
