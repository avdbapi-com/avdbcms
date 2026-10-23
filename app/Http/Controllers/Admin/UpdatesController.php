<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Support\AppVersion;
use App\Support\DatabaseBackup;
use App\Support\Environment;
use App\Support\Updater\PlanStore;
use App\Support\Updater\ReleaseCheck;
use App\Support\Updater\Updater;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Tools > Updates (F8): is a newer AVDBCMS out, and its one-click install. The page renders from what is kept (the
 * cached GitHub check, plan.json, state.json, the file backups); when nothing is kept yet it asks GitHub after it
 * appears (status?check=1, a deferred section). Its script (resources/admin/updates/updates.ts) walks the steps of
 * App\Support\Updater: start -> step ... -> swap (the maintenance hook) -> finish.
 *
 * Every action answers JSON to the script and a redirect with the message to a plain form, and never a 500: anything
 * unexpected is reported (without addresses, tokens or cookies) and answered with a message. Start and Undo ask for
 * the administrator's password again (Hash::check, not the redirect-only password.confirm middleware: F8 §15); five
 * wrong passwords in ten minutes block both for ten minutes.
 */
class UpdatesController
{
    /** Wrong passwords allowed within PASSWORD_WINDOW seconds; the fifth blocks Update and Undo for PASSWORD_WINDOW s. */
    public const PASSWORD_TRIES = 5;

    public const PASSWORD_WINDOW = 600;

    /** The result of the last update or undo stays on the page this long. */
    private const RESULT_SECONDS = 14 * 86400;

    public function index(Request $request): View
    {
        $admin = $request->user();
        $ops = (bool) config('admin.operational_tools_enabled', false);
        $data = [
            'current' => AppVersion::CURRENT,
            'ops' => $ops,
            'state' => null,
            'check' => null,
            'checkMessage' => null,
            'release' => null,
            'plan' => null,
            'result' => null,
            'blockers' => [],
            'recentBackup' => null,
            'backups' => [],
            'lockedFor' => 0,
            'log' => [],
            'problem' => null,
        ];

        try {
            $updater = $this->updater();
            $plan = $updater->plan();
            $check = $this->releaseCheck()->cached();
            $view = $plan !== null ? $this->planView($plan, $admin) : null;
            $state = $updater->state();

            $data = array_merge($data, [
                // "Last updated" only while it speaks of the running version (an upload by hand may have come since).
                'state' => is_array($state) && ($state['installed_version'] ?? null) === AppVersion::CURRENT ? $state : null,
                'check' => $check,
                'checkMessage' => ReleaseCheck::message($check),
                'release' => self::offered($check),
                'plan' => $view !== null && $view['active'] ? $view : null,
                'result' => $view !== null && ! $view['active'] && $view['step_at'] >= time() - self::RESULT_SECONDS ? $view : null,
                'blockers' => $updater->blockers(),
                'recentBackup' => $ops ? $this->recentBackup() : null,
                'backups' => $this->backupList($updater),
                'lockedFor' => $this->lockedFor($admin),
                'log' => $plan !== null ? $updater->logLines($plan) : [],
            ]);
        } catch (Throwable $e) {
            self::reportSafely($e);
            $data['problem'] = self::stopped($e);
        }

        return view('admin.updates.index', $data);
    }

    /**
     * The page's JSON: the plan (what it may show of it), the check (check=1: ask GitHub when nothing is kept, 10 s),
     * the newest lines of the step log (log=1).
     */
    public function status(Request $request): JsonResponse
    {
        try {
            $updater = $this->updater();
            $plan = $updater->plan();
            $check = $request->boolean('check') ? $this->releaseCheck()->get(10) : $this->releaseCheck()->cached();

            $answer = [
                'ok' => true,
                'plan' => $plan !== null ? $this->planView($plan, $request->user()) : null,
                'check' => $check === null ? null : [
                    'state' => $check['state'] ?? null,
                    'version' => $check['release']['version'] ?? null,
                    'checked_at' => $check['checked_at'] ?? null,
                    'message' => ReleaseCheck::message($check),
                ],
                'log' => $request->boolean('log') ? $updater->logLines($plan) : null,
            ];
        } catch (Throwable $e) {
            self::reportSafely($e);
            $answer = ['ok' => false, 'plan' => null, 'check' => null, 'log' => null, 'message' => self::stopped($e)];
        }

        return response()->json($answer)->header('Cache-Control', 'no-store, private');
    }

    /** "Check now": a fresh answer from GitHub, at most once a minute per site (the server's 60 an hour are shared). */
    public function check(Request $request): JsonResponse|RedirectResponse
    {
        return $this->guarded($request, function () use ($request): JsonResponse|RedirectResponse {
            $result = $this->releaseCheck()->refresh();

            if ($result['wait'] > 0) {
                return $this->respond($request, ['ok' => false, 'wait' => $result['wait'], 'message' => trans_choice(
                    'You checked a moment ago. Try again in 1 second.|You checked a moment ago. Try again in :count seconds.',
                    $result['wait'],
                    ['count' => $result['wait']]
                )]);
            }

            $answer = (array) $result['answer'];
            $state = (string) ($answer['state'] ?? 'error');
            $version = $answer['release']['version'] ?? null;

            // Home's callout comes from the same answer from now on. (ReleaseCheck::refresh() wrote the admin-log row.)
            StatusProbeController::forget('updates');

            return $this->respond($request, [
                'ok' => $state !== 'error',
                'state' => $state,
                'version' => $version,
                'message' => $state === 'available' && is_string($version)
                    ? __('AVDBCMS :version is available', ['version' => $version])
                    : (ReleaseCheck::message($answer) ?? __('Your site is up to date.')),
            ]);
        });
    }

    /** "Update to X.Y.Z" in the confirm dialog: the password again, then the plan (Updater::start). */
    public function start(Request $request): JsonResponse|RedirectResponse
    {
        return $this->guarded($request, function () use ($request): JsonResponse|RedirectResponse {
            $admin = $request->user();

            if (($refusal = $this->passwordRefusal($request, $admin)) !== null) {
                return $this->respond($request, $refusal[0], $refusal[1]);
            }

            // "Skip the database backup" counts only while Tools > Backups has one from the last 24 hours.
            $skip = $request->boolean('skip_backup') && $this->recentBackup() !== null;

            return $this->respond($request, $this->updater()->start($admin, $skip));
        });
    }

    /**
     * The next step of the running update (the page asks again while the answer says next: step). The updater learns
     * whether this browser holds the update cookie: one that does not gets it first, in an answer of its own.
     */
    public function step(Request $request): JsonResponse|RedirectResponse
    {
        return $this->guarded($request, fn (): JsonResponse|RedirectResponse => $this->respond($request,
            $this->updater()->withBrowserCookie(self::browserCookie($request))->step($request->user())));
    }

    /**
     * The swap address when the maintenance hook is not there (any more): the swap is over, the hook was deleted in
     * the middle (the updater writes it again with a new token), or its token ran out before the swap began (the hook
     * stood down and the update prepares the swap again).
     */
    public function swap(Request $request): JsonResponse|RedirectResponse
    {
        return $this->guarded($request, fn (): JsonResponse|RedirectResponse => $this->respond($request,
            $this->updater()->withBrowserCookie(self::browserCookie($request))->swap($request->user())));
    }

    /** After the swap (already the new code): Finish update (the database changes), state.json, clean-up. */
    public function finish(Request $request): JsonResponse|RedirectResponse
    {
        return $this->guarded($request, fn (): JsonResponse|RedirectResponse => $this->respond($request, $this->updater()->finish($request->user())));
    }

    /** Cancel an update before it replaces files (a stalled one too, by any administrator). */
    public function cancel(Request $request): JsonResponse|RedirectResponse
    {
        return $this->guarded($request, fn (): JsonResponse|RedirectResponse => $this->respond($request, $this->updater()->cancel($request->user())));
    }

    /** "Undo this update" (files only): the password again, then the same swap the other way. */
    public function undo(Request $request): JsonResponse|RedirectResponse
    {
        return $this->guarded($request, function () use ($request): JsonResponse|RedirectResponse {
            $admin = $request->user();

            if (($refusal = $this->passwordRefusal($request, $admin)) !== null) {
                return $this->respond($request, $refusal[0], $refusal[1]);
            }

            return $this->respond($request, $this->updater()->undo($admin));
        });
    }

    public function deleteBackup(Request $request, string $id): JsonResponse|RedirectResponse
    {
        return $this->guarded($request, fn (): JsonResponse|RedirectResponse => $this->respond($request, $this->updater()->deleteBackup($id, $request->user())));
    }

    /* ------------------------------------------------------------- helpers */

    /** The updater of this site (a test binds one with a site root of its own). */
    private function updater(): Updater
    {
        return app()->bound(Updater::class) ? app(Updater::class) : new Updater();
    }

    /** The update cookie as this browser sent it (not encrypted: EncryptCookies::$except), or null. */
    private static function browserCookie(Request $request): ?string
    {
        $value = $request->cookies->get(Updater::COOKIE);

        return is_string($value) ? $value : null;
    }

    private function releaseCheck(): ReleaseCheck
    {
        return app()->bound(ReleaseCheck::class) ? app(ReleaseCheck::class) : new ReleaseCheck();
    }

    /** The newest backup in Tools > Backups from the last 24 hours (the dialog then offers to skip the update's own). */
    private function recentBackup(): ?array
    {
        try {
            return app(DatabaseBackup::class)->recent(24);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The release the page shows: the one the check offers, or, while GitHub cannot be asked, the last one it offered
     * (shown without the install button: Updater::start asks for a working check).
     */
    private static function offered(?array $check): ?array
    {
        if ($check === null) {
            return null;
        }

        $release = ($check['state'] ?? null) === 'available' ? ($check['release'] ?? null) : null;

        if ($release === null && ($check['state'] ?? null) === 'error' && is_array($check['last_good'] ?? null)
            && AppVersion::isNewer($check['last_good']['version'] ?? null)) {
            $release = $check['last_good'];
        }

        return is_array($release) && AppVersion::isVersion($release['version'] ?? null) ? $release : null;
    }

    /**
     * What the page may know of a plan: never its token or cookie hashes, nor paths of the server.
     *
     * @return array<string, mixed>
     */
    private function planView(array $plan, User $admin): array
    {
        $status = (string) ($plan['status'] ?? '');
        $active = Updater::active($plan);
        $release = (array) ($plan['release'] ?? []);

        return [
            'status' => $status,
            'kind' => ($plan['kind'] ?? 'update') === 'undo' ? 'undo' : 'update',
            'version' => (string) ($plan['version'] ?? ''),
            'from' => (string) ($plan['from'] ?? ''),
            'admin_name' => (string) ($plan['admin_name'] ?? ''),
            'started_at' => (int) ($plan['started_at'] ?? 0),
            'step_at' => (int) ($plan['step_at'] ?? 0),
            'finished_at' => isset($plan['finished_at']) ? (int) $plan['finished_at'] : null,
            'active' => $active,
            'stalled' => Updater::stalled($plan),
            'mine' => (int) ($plan['admin_id'] ?? 0) === (int) $admin->getKey(),
            // Before the swap nothing live has changed: the update can still be called off.
            'cancellable' => $active && in_array($status, Updater::BEFORE_SWAP, true),
            'next' => self::next($status),
            'label' => Updater::label($plan),
            // A plan that ended without its result: "The update was not installed", "The undo did not finish", ...
            'title' => $status === 'failed' ? Updater::failureTitle($plan) : null,
            'progress' => Updater::progress($plan),
            'busy' => $active ? Updater::busyMessage($plan) : null,
            'last_error' => is_string($plan['last_error'] ?? null) ? $plan['last_error'] : null,
            'failure' => isset($plan['failure']['reason']) ? ['step' => (string) ($plan['failure']['step'] ?? ''), 'reason' => (string) $plan['failure']['reason']] : null,
            'rolled_back' => ! empty($plan['rolled_back']),
            'kept' => array_values(array_filter((array) ($plan['kept'] ?? []), 'is_string')),
            'report' => self::report($plan),
            'release' => [
                'version' => (string) ($release['version'] ?? ($plan['version'] ?? '')),
                'name' => (string) ($release['name'] ?? ''),
                'published_at' => is_string($release['published_at'] ?? null) ? $release['published_at'] : null,
                'size' => isset($release['zip']['size']) ? (int) $release['zip']['size'] : null,
            ],
            'database_backup' => is_array($plan['database_backup'] ?? null) ? [
                'name' => (string) ($plan['database_backup']['name'] ?? ''),
                'size' => (int) ($plan['database_backup']['size'] ?? 0),
            ] : null,
            'backup_id' => is_string($plan['backup_id'] ?? null) ? $plan['backup_id'] : null,
            // An undo that left files alone kept their copies from before the update in this backup folder.
            'kept_backup' => is_string($plan['kept_backup'] ?? null) ? $plan['kept_backup'] : null,
            'migrations' => isset($plan['migrations']) ? (int) $plan['migrations'] : null,
        ];
    }

    /** What the page does next for a status (the same order as Updater's answers). */
    private static function next(string $status): ?string
    {
        return match (true) {
            in_array($status, Updater::BEFORE_SWAP, true) => 'step',
            in_array($status, ['swapping', 'rolling-back'], true) => 'swap',
            in_array($status, ['swapped', 'migrating'], true) => 'finish',
            default => null,
        };
    }

    /**
     * The lists of a finished update or undo: files kept with the new copy next to them, translations merged, files the
     * owner had changed that were replaced (their old copies are in the update backup). An undo lists only "kept".
     *
     * @return array{replaced: int, added: int, removed: int, unchanged: int, kept: list<array{path: string, copy: ?string}>, merged: list<array{path: string, added: int, kept: int}>, overwritten: list<string>}
     */
    private static function report(array $plan): array
    {
        $report = (array) ($plan['report'] ?? []);
        $kept = [];
        $merged = [];
        $overwritten = [];

        foreach ((array) ($report['kept'] ?? []) as $item) {
            if (is_string($item)) {
                $kept[] = ['path' => $item, 'copy' => null];
            } elseif (is_array($item) && is_string($item['path'] ?? null)) {
                $kept[] = ['path' => $item['path'], 'copy' => is_string($item['copy'] ?? null) ? $item['copy'] : null];
            }
        }

        foreach ((array) ($report['merged'] ?? []) as $item) {
            if (is_array($item) && is_string($item['path'] ?? null)) {
                $merged[] = ['path' => $item['path'], 'added' => (int) ($item['added'] ?? 0), 'kept' => (int) ($item['kept'] ?? 0)];
            }
        }

        foreach ((array) ($report['overwritten'] ?? []) as $item) {
            if (is_string($item)) {
                $overwritten[] = $item;
            }
        }

        return [
            'replaced' => (int) ($report['replaced'] ?? ($report['restored'] ?? 0)),
            'added' => (int) ($report['added'] ?? 0),
            'removed' => (int) ($report['removed'] ?? 0),
            'unchanged' => (int) ($report['unchanged'] ?? 0),
            'kept' => $kept,
            'merged' => $merged,
            'overwritten' => $overwritten,
        ];
    }

    /**
     * The file backups of earlier updates, with the database backup each update made (for the Undo text).
     *
     * @return list<array<string, mixed>>
     */
    private function backupList(Updater $updater): array
    {
        return array_map(function (array $backup) use ($updater): array {
            if (! array_key_exists('database_backup', $backup)) {
                // Updater::backups() does not list it (yet): the backup's own backup.json names it.
                $data = PlanStore::read($updater->paths()->backups((string) $backup['id']).'/backup.json');
                $backup['database_backup'] = is_string($data['database_backup'] ?? null) ? $data['database_backup'] : null;
            }

            return $backup;
        }, $updater->backups());
    }

    /**
     * Start and Undo re-check the administrator's password. Null when it is right, else [answer, HTTP status].
     *
     * @return array{0: array<string, mixed>, 1: int}|null
     */
    private function passwordRefusal(Request $request, User $admin): ?array
    {
        if (($wait = $this->lockedFor($admin)) > 0) {
            return [self::lockedAnswer($wait), 429];
        }

        $password = $request->input('password');

        if (is_string($password) && $password !== '' && Hash::check($password, (string) $admin->getAuthPassword())) {
            RateLimiter::clear(self::attemptsKey($admin));

            return null;
        }

        RateLimiter::hit(self::attemptsKey($admin), self::PASSWORD_WINDOW);

        if (RateLimiter::attempts(self::attemptsKey($admin)) >= self::PASSWORD_TRIES) {
            // The block lasts PASSWORD_WINDOW seconds from the fifth wrong try (not from the first).
            RateLimiter::clear(self::attemptsKey($admin));
            RateLimiter::hit(self::lockKey($admin), self::PASSWORD_WINDOW);

            return [self::lockedAnswer(self::PASSWORD_WINDOW), 429];
        }

        return [['ok' => false, 'status' => null, 'next' => null, 'field' => 'password', 'message' => __('That password is not right.')], 422];
    }

    /** Seconds until this administrator may enter the password again (0: now). */
    private function lockedFor(User $admin): int
    {
        return RateLimiter::tooManyAttempts(self::lockKey($admin), 1) ? max(1, RateLimiter::availableIn(self::lockKey($admin))) : 0;
    }

    private static function lockedAnswer(int $seconds): array
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return ['ok' => false, 'status' => null, 'next' => null, 'field' => 'password', 'locked' => true, 'retry_after' => $seconds,
            'message' => trans_choice('Too many wrong passwords. Try again in 1 minute.|Too many wrong passwords. Try again in :count minutes.', $minutes, ['count' => $minutes])];
    }

    private static function attemptsKey(User $admin): string
    {
        return 'updates-password:'.$admin->getKey();
    }

    private static function lockKey(User $admin): string
    {
        return 'updates-password-lock:'.$admin->getKey();
    }

    /**
     * Run an action; anything unexpected is reported and answered with its reason (never a 500). A busy answer keeps
     * its 409 for the page's script, which then waits and asks again.
     *
     * @param  callable(): (JsonResponse|RedirectResponse)  $action
     */
    private function guarded(Request $request, callable $action): JsonResponse|RedirectResponse
    {
        try {
            return $action();
        } catch (HttpExceptionInterface|HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            self::reportSafely($e);

            return $this->respond($request, ['ok' => false, 'status' => null, 'next' => null, 'retry' => true, 'message' => self::stopped($e)]);
        }
    }

    /**
     * The answer to the page: JSON for its script (409 while another step holds the lock), otherwise back to Tools >
     * Updates with the message. An answer that carries the update cookie sets it (HttpOnly; never in the JSON).
     *
     * @param  array<string, mixed>  $answer
     */
    private function respond(Request $request, array $answer, int $status = 200): JsonResponse|RedirectResponse
    {
        $cookie = is_string($answer['cookie'] ?? null) && preg_match('/^[0-9a-f]{64}\z/', $answer['cookie']) === 1 ? $answer['cookie'] : null;
        unset($answer['cookie']);

        if ($request->expectsJson()) {
            $response = response()->json($answer, $status === 200 && ! empty($answer['busy']) ? 409 : $status)
                ->header('Cache-Control', 'no-store, private');
        } else {
            $message = (string) ($answer['message'] ?? '');
            $response = redirect()->route('admin.updates.index');

            if ($message !== '') {
                $response = ($answer['ok'] ?? false) ? $response->with('success', $message) : $response->withErrors(['update' => $message]);
            }
        }

        if ($cookie !== null) {
            $response->withCookie($this->updateCookie($request, $cookie));
        }

        return $response;
    }

    /**
     * The update cookie: the administrator who started a swap gets the Finish / Undo page of the maintenance hook if
     * the swap is cut off. 12 hours, the whole site (the hook answers every address), HttpOnly, SameSite=Lax, Secure
     * whenever the session cookie is (https here, or behind a TLS proxy / an https APP_URL: EnvironmentServiceProvider
     * then sets session.secure); not encrypted (EncryptCookies::$except), because the hook reads it without Laravel.
     */
    private function updateCookie(Request $request, string $value): Cookie
    {
        $secure = $request->isSecure() || config('session.secure') === true || Environment::isSecureRequest();

        return cookie(Updater::COOKIE, $value, intdiv(Updater::COOKIE_SECONDS, 60), '/', null, $secure, true, false, 'lax');
    }

    /** The reason for the page, without addresses (a download address carries a signature). */
    private static function stopped(Throwable $e): string
    {
        return __('The update stopped: :reason', ['reason' => Updater::scrub(Str::limit(class_basename($e).': '.$e->getMessage(), 180))]);
    }

    /**
     * report() a copy without addresses and without the exception chain: a client error can quote the signed download
     * address, and the site log must never hold it (F8 §16).
     */
    private static function reportSafely(Throwable $e): void
    {
        Updater::reportSafely($e);
    }
}
