<?php

namespace App\Support\Updater;

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\StatusProbeController;
use App\Models\Setting;
use App\Models\User;
use App\Support\AdminCache;
use App\Support\AppVersion;
use App\Support\DatabaseBackup;
use App\Support\PendingMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The one-click update of Tools > Updates, step by step. Every step is one short request of the Updates page (the page
 * asks for the next one when a step answers), so none comes near a host's PHP time limit or Cloudflare's 125 seconds:
 *
 *   start      admin's password checked by the controller; pre-checks; the plan (plan.json)
 *   downloading  the .sha256 asset, then the zip in slices (Downloader)
 *   verifying  SHA-256 (+ the API digest), the zip's entries (ZipEntries), its manifest: version, PHP, min_from
 *   unpacking  into storage/app/updates/<version>/staging, every file checked against the manifest (Unpacker)
 *   backing-up the database into Tools > Backups (backup_before_update_<to>_...sql.gz), in steps (DatabaseBackup)
 *   preparing  the live files hashed and compared (SwapPlanner), the file backup folder and backup.json, write test,
 *              the new built assets moved in, then the maintenance hook (HookWriter) and the swap token
 *   swapping   answered by the hook, without Laravel (MaintenanceHook + SwapEngine)
 *   swapped -> migrating -> done: the next request runs on the new code: PendingMigrations::run() (Finish update),
 *              state.json, the work files deleted, the two newest file backups kept
 *
 * Anything that fails before the swap leaves the site as it was; a failed swap puts every file back on its own.
 * Undo (files only, never the database) runs the same swap the other way, from the newest file backup.
 */
final class Updater
{
    public const STATUSES = ['checking', 'downloading', 'verifying', 'unpacking', 'backing-up', 'preparing', 'swapping', 'rolling-back', 'swapped', 'migrating', 'done', 'failed', 'undone', 'cancelled'];

    /** A plan in one of these is running (one at a time per site). */
    public const ACTIVE = ['checking', 'downloading', 'verifying', 'unpacking', 'backing-up', 'preparing', 'swapping', 'rolling-back', 'swapped', 'migrating'];

    /** Before the swap nothing live has changed: these can be cancelled. */
    public const BEFORE_SWAP = ['checking', 'downloading', 'verifying', 'unpacking', 'backing-up', 'preparing'];

    public const COOKIE = 'avdbcms_update';

    /** A plan with no step for 30 minutes is stalled: any administrator may continue or cancel it. */
    public const STALL_SECONDS = 1800;

    public const TOKEN_SECONDS = 1800;

    public const COOKIE_SECONDS = 43200;

    /** An update cookie with less time left than this is handed out again before a step may take the site down. */
    public const COOKIE_RENEW = 3600;

    /** Seconds of work per request. */
    public const BUDGET = 20.0;

    /** File backups of finished updates kept on the server. */
    public const KEEP_BACKUPS = 2;

    private const REUSE_SECONDS = 7 * 86400;

    /** Answers in a row that hand out the update cookie to a browser that never sends it back; then the update goes on. */
    private const COOKIE_TRIES = 3;

    private readonly UpdatePaths $paths;

    /** The AVDBCMS version this code is: AppVersion::CURRENT (a test names the version it plays instead). */
    private readonly string $version;

    private PlanStore $store;

    private HookWriter $hook;

    /**
     * Handed to the browser once: the swap token (the step that makes the swap ready) and the update cookie (start,
     * undo and the answers of freshCookie(), each in an answer that does nothing else).
     *
     * @var array{token?: string, cookie?: string}|null
     */
    private ?array $secrets = null;

    /** True while this object holds the update lock (inside locked()). */
    private bool $holding = false;

    /** The update cookie the browser of this request sent, once UpdatesController said so (withBrowserCookie()). */
    private ?string $browserCookie = null;

    private bool $browserKnown = false;

    public function __construct(
        ?UpdatePaths $paths = null,
        private readonly ReleaseCheck $check = new ReleaseCheck(),
        private readonly DatabaseBackup $database = new DatabaseBackup(),
        private readonly Downloader $downloader = new Downloader(),
        ?string $version = null,
    ) {
        $this->paths = $paths ?? UpdatePaths::forApp();
        $this->version = AppVersion::isVersion($version) ? (string) $version : AppVersion::CURRENT;
        $this->store = new PlanStore($this->paths);
        $this->hook = new HookWriter($this->paths);
    }

    public function paths(): UpdatePaths
    {
        return $this->paths;
    }

    /**
     * The update cookie the browser of this request sent (null: none). Steps then hand a browser that lacks it a new
     * one before anything can take the site down (another browser of the same administrator, an answer that was lost).
     */
    public function withBrowserCookie(?string $value): self
    {
        $this->browserCookie = is_string($value) && preg_match('/^[0-9a-f]{64}\z/', $value) === 1 ? $value : null;
        $this->browserKnown = true;

        return $this;
    }

    /**
     * The update or undo running or last run (plan.json). A swap failure the hook reported (it has no database) gets
     * its audit row and its work files cleared here, under the update lock only: a page that merely shows the plan
     * while another request starts a new one must not write the old plan back.
     */
    public function plan(): ?array
    {
        $plan = $this->store->get();

        if ($plan === null || ! self::unsettled($plan)) {
            return $plan;
        }

        if ($this->holding) {
            return $this->settleFailure($plan);
        }

        try {
            $lock = $this->store->lock();
        } catch (Throwable) {
            return $plan;
        }

        if ($lock === null) {
            return $plan;
        }

        try {
            $plan = $this->store->get();

            return $plan !== null && self::unsettled($plan) ? $this->settleFailure($plan) : $plan;
        } finally {
            $this->store->unlock($lock);
        }
    }

    /** state.json: the last update (installed_version, previous_version, updated_at, backup_id, admin_id). */
    public function state(): ?array
    {
        return $this->store->state();
    }

    public static function active(?array $plan): bool
    {
        return $plan !== null && in_array($plan['status'] ?? null, self::ACTIVE, true);
    }

    public static function stalled(?array $plan): bool
    {
        return self::active($plan) && (int) ($plan['step_at'] ?? 0) < time() - self::STALL_SECONDS;
    }

    /**
     * Why a one-click update cannot run on this site at all (shown before anybody clicks).
     *
     * @return list<string>
     */
    public function blockers(): array
    {
        $why = [];

        if (! config('admin.operational_tools_enabled', false)) {
            $why[] = __('Operational tools are turned off on this site, so updates cannot be installed from here. Upload the update zip with your file manager, then click Finish update on Home.');
        }

        if (Manifest::installed($this->paths->manifest()) === null) {
            $why[] = __('This site has no list of its AVDBCMS files (avdbcms-manifest.json), so it cannot be updated from here. Upload the update zip with your file manager, then click Finish update on Home.');
        }

        if (Unpacker::engine() === null) {
            $why[] = Unpacker::noEngineMessage();
        }

        return $why;
    }

    /**
     * Start an update to the newest release (the controller checked the administrator's password).
     *
     * @return array<string, mixed> the answer for the Updates page
     */
    public function start(User $admin, bool $skipBackup = false): array
    {
        return $this->locked(function () use ($admin, $skipBackup): array {
            $plan = $this->plan();

            if (self::active($plan)) {
                if ((int) $plan['admin_id'] === (int) $admin->getKey()) {
                    return $this->answer($plan);
                }

                return $this->refuse(self::stalled($plan)
                    ? __('An update to :version stopped :time. Continue it or cancel it first.', ['version' => $plan['version'], 'time' => self::ago((int) $plan['step_at'])])
                    : self::busyMessage($plan));
            }

            if (($why = $this->blockers()) !== []) {
                return $this->refuse($why[0]);
            }

            $answer = $this->check->get(10);

            if (! is_array($answer['release'] ?? null) || ! AppVersion::isNewer($answer['release']['version'] ?? null, $this->version)) {
                return $this->refuse(ReleaseCheck::message($answer) ?? __('There is no newer version to install.'));
            }

            if (($why = $this->preCheck()) !== null) {
                return $this->refuse($why);
            }

            $release = self::trusted($answer['release']);
            $skipBackup = $skipBackup && $this->database->recent(24) !== null;

            if (($why = $this->spaceProblem($this->spaceNeeded($release['zip']['size'] ?? null, null, $skipBackup, true))) !== null) {
                return $this->refuse($why);
            }

            $job = $this->paths->job($release['version']);
            File::ensureDirectoryExists($job, 0755);
            $zip = $job.'/'.$release['zip']['name'];
            $reuse = is_file($zip) && (int) filemtime($zip) >= time() - self::REUSE_SECONDS
                && (! is_int($release['zip']['size'] ?? null) || (int) filesize($zip) === $release['zip']['size']);

            // What an earlier try of this version left (staging, the hashes of the live files, its journal and log)
            // is not used again: the site may have changed since. A zip younger than a week is (it is checked again).
            // The work folders of other versions (a try that failed long ago) go.
            $this->discardJob($job, $reuse ? basename($zip) : null);
            $this->pruneJobs($release['version']);
            $this->prunePendingBackups();

            $plan = [
                'format' => 1,
                'id' => bin2hex(random_bytes(8)),
                'kind' => 'update',
                'version' => $release['version'],
                'from' => $this->version,
                'status' => $reuse ? 'verifying' : 'downloading',
                'admin_id' => (int) $admin->getKey(),
                'admin_name' => self::adminName($admin),
                'started_at' => time(),
                'step_at' => time(),
                'job' => basename($job),
                'release' => array_intersect_key($release, array_flip(['version', 'tag', 'name', 'published_at', 'zip', 'sha256'])),
                'source' => $answer['source'] ?? 'api',
                'skip_backup' => $skipBackup,
                'engine' => Unpacker::engine(),
                'download' => ['done' => $reuse ? (int) filesize($zip) : 0, 'total' => $reuse ? (int) filesize($zip) : ($release['zip']['size'] ?? null), 'slice' => Downloader::FIRST_SLICE],
            ];

            // The cookie of the maintenance page comes now, long before anything can take the site down: if the answer
            // that does take it down is lost, the administrator still gets the Finish / Undo page (F8 11).
            $this->secrets = ['cookie' => $this->issueCookie($plan)];
            $this->store->put($plan);
            $this->log($plan, 'start', 'AVDBCMS '.$plan['from'].' -> '.$plan['version'].' started by '.$plan['admin_name']
                .' (zip engine '.$plan['engine'].', checked through '.($plan['source'] === 'web' ? 'github.com' : 'the GitHub API').($reuse ? ', zip of an earlier try reused' : '').($skipBackup ? ', database backup skipped' : '').')');
            AuditLogController::log('update.started', $plan['from'].' -> '.$plan['version'], (int) $admin->getKey());

            return $this->answer($plan, $this->secrets);
        });
    }

    /** The next step of the running update. */
    public function step(User $admin): array
    {
        return $this->locked(function () use ($admin): array {
            $plan = $this->plan();

            if (! self::active($plan)) {
                return $this->answer($plan, ['ok' => false, 'message' => $plan === null ? __('No update is running.') : self::label($plan)]);
            }

            if (($refusal = $this->takeOver($plan, $admin)) !== null) {
                return $refusal;
            }

            // An answer that hands out the update cookie does nothing else, so the browser holds the cookie before any
            // step can take the site down (a new owner of a stalled plan; another browser; one about to expire).
            if (isset($this->secrets['cookie']) || ($fresh = $this->freshCookie($plan)) !== null) {
                $this->secrets = ['cookie' => $this->secrets['cookie'] ?? $fresh];
                $plan['step_at'] = time();
                $this->store->put($plan);

                return $this->answer($plan, $this->secrets);
            }

            $deadline = microtime(true) + $this->budget();

            try {
                $plan = match ($plan['status']) {
                    'checking', 'downloading' => $this->download($plan, $deadline),
                    'verifying' => $this->verify($plan),
                    'unpacking' => $this->unpack($plan, $deadline),
                    'backing-up' => $this->backUp($plan, $deadline, $admin),
                    'preparing' => ($plan['kind'] ?? 'update') === 'undo' ? $this->prepareUndo($plan, $deadline) : $this->prepare($plan, $deadline),
                    'swapping', 'rolling-back' => $this->reopen($plan),
                    default => $plan,
                };
            } catch (Throwable $e) {
                // The plan as the step left it on disk (a download slice saved, a half database dump discarded), not
                // as it was before the step: putting that back would bring work back that the step threw away.
                $plan = $this->store->get() ?? $plan;

                if (! $e instanceof UpdateException) {
                    self::reportSafely($e);
                    $e = new UpdateException(__('The update stopped: :reason', [
                        'reason' => Str::limit(class_basename($e).': '.self::scrub($e->getMessage()), 180),
                    ]), (string) $plan['status'], true, null, $e);
                }

                return $this->failed($plan, $e, $admin);
            }

            unset($plan['last_error']);
            $plan['step_at'] = time();
            $this->store->put($plan);

            return $this->answer($plan, $this->secrets ?? []);
        });
    }

    /**
     * The swap address reached Laravel instead of the hook: the hook is gone (the swap ended, or somebody deleted it).
     * A swap that is not over gets a new hook and token; a finished one goes on to Finish.
     */
    public function swap(User $admin): array
    {
        return $this->step($admin);
    }

    /** After the swap (already the new code): the database step, state.json, clean-up. */
    public function finish(User $admin): array
    {
        return $this->locked(function () use ($admin): array {
            $plan = $this->plan();

            if ($plan === null) {
                return $this->answer(null, ['ok' => false, 'message' => __('No update is running.')]);
            }

            if (in_array($plan['status'], ['done', 'undone'], true)) {
                return $this->answer($plan, ['next' => 'reload', 'message' => self::label($plan)]);
            }

            if (! in_array($plan['status'], ['swapped', 'migrating'], true)) {
                return $this->answer($plan);
            }

            // OPcache rereads changed files only every revalidate_freq seconds where it could not be cleared.
            $ready = (int) ($plan['swapped_at'] ?? 0) + (int) ($plan['settle']['wait'] ?? 0);

            if ($ready > time()) {
                return $this->answer($plan, ['next' => 'finish', 'retry_after' => $ready - time(), 'label' => __('Waiting for PHP to load the new files...')]);
            }

            if ($plan['kind'] === 'undo') {
                return $this->finishUndo($plan, $admin);
            }

            // This request should already run the new version; an old OPcache entry may need a few seconds more.
            if ($this->version !== $plan['version'] && (int) ($plan['finish_tries'] ?? 0) < 5) {
                $plan['finish_tries'] = (int) ($plan['finish_tries'] ?? 0) + 1;
                $this->store->put($plan);

                return $this->answer($plan, ['next' => 'finish', 'retry_after' => 3, 'label' => __('Waiting for PHP to load the new files...')]);
            }

            $plan['status'] = 'migrating';
            $plan['step_at'] = time();
            $this->store->put($plan);
            $result = PendingMigrations::run();

            if ($result['busy']) {
                return $this->answer($plan, ['ok' => false, 'next' => 'finish', 'retry_after' => 60, 'message' => $result['message']]);
            }

            if (! $result['ok']) {
                $plan['status'] = 'swapped';
                $plan['last_error'] = $result['message'];
                $this->store->put($plan);
                $this->log($plan, 'finish', 'the database step failed: '.$result['message']);
                AuditLogController::log('update.failed', 'finish: '.Str::limit($result['message'], 200), (int) $admin->getKey());

                return $this->answer($plan, ['ok' => false, 'next' => null, 'message' => $result['message']]);
            }

            $plan = $this->complete($plan, $admin, count($result['ran']));

            return $this->answer($plan, ['next' => 'reload', 'message' => self::label($plan)]);
        });
    }

    /** Cancel an update before its swap: the work files go (a database backup that finished stays in Tools > Backups). */
    public function cancel(User $admin): array
    {
        return $this->locked(function () use ($admin): array {
            $plan = $this->plan();

            if ($plan === null || ! in_array($plan['status'], [...self::BEFORE_SWAP, 'failed'], true)) {
                return $this->refuse(__('Only an update that has not started to replace files can be cancelled. Finish it or undo it instead.'));
            }

            if (self::active($plan) && ($refusal = $this->takeOver($plan, $admin)) !== null) {
                return $refusal;
            }

            $this->clearWork($plan, keepZip: false);
            $plan['status'] = 'cancelled';
            $plan['step_at'] = time();
            $this->store->put($plan);
            $this->log($plan, 'cancel', 'cancelled by '.self::adminName($admin));

            // "The update was cancelled. ..." or, for an undo still comparing its files, "The undo was cancelled. ...".
            return $this->answer($plan, ['next' => 'reload', 'message' => self::label($plan)]);
        });
    }

    /** Undo the newest update (files only): the same swap, from its file backup back to the files before it. */
    public function undo(User $admin): array
    {
        return $this->locked(function () use ($admin): array {
            $plan = $this->plan();

            if (self::active($plan)) {
                return $this->refuse(self::busyMessage($plan));
            }

            $backup = $this->undoable();

            if ($backup === null) {
                return $this->refuse(__('There is no update to undo: its backup is gone or a newer version is installed.'));
            }

            if (($why = $this->preCheck()) !== null) {
                return $this->refuse($why);
            }

            $job = $this->paths->job('undo-'.$backup['to']);
            File::deleteDirectory($job);
            File::ensureDirectoryExists($job, 0755);

            // The plan only: the files are compared in the steps that follow (prepareUndo(), within the time budget), and
            // the cookie of the maintenance page comes with this answer, before anything can take the site down.
            $plan = [
                'format' => 1,
                'id' => bin2hex(random_bytes(8)),
                'kind' => 'undo',
                'version' => $backup['from'],
                'from' => $backup['to'],
                'status' => 'preparing',
                'admin_id' => (int) $admin->getKey(),
                'admin_name' => self::adminName($admin),
                'started_at' => time(),
                'step_at' => time(),
                'job' => basename($job),
                'backup_id' => $backup['id'],
            ];

            $this->secrets = ['cookie' => $this->issueCookie($plan)];
            $this->store->put($plan);
            $this->log($plan, 'undo', 'undo of '.$backup['from'].' -> '.$backup['to'].' started by '.$plan['admin_name']);

            return $this->answer($plan, $this->secrets);
        });
    }

    /** Delete a file backup of an earlier update (Tools > Updates lists them). */
    public function deleteBackup(string $id, User $admin): array
    {
        return $this->locked(function () use ($id, $admin): array {
            $plan = $this->plan();

            if (preg_match('/^\d+\.\d+\.\d+-to-\d+\.\d+\.\d+-\d{14}\z/', $id) !== 1 || ! is_dir($this->paths->backups($id))) {
                return $this->refuse(__('That backup is not there any more.'));
            }

            if (self::active($plan) && ($plan['backup_id'] ?? null) === $id) {
                return $this->refuse(self::busyMessage($plan));
            }

            File::deleteDirectory($this->paths->backups($id));
            AuditLogController::log('update.backup_deleted', $id, (int) $admin->getKey());

            return $this->answer($plan, ['next' => 'reload', 'message' => __('The backup :name was deleted.', ['name' => $id])]);
        });
    }

    /**
     * The file backups of earlier updates, newest first, with the one Undo can use marked.
     *
     * @return list<array{id: string, from: string, to: string, created_at: int, size: int, files: int, undo: bool, database_backup: ?string}>
     */
    public function backups(): array
    {
        $list = [];

        foreach (glob($this->paths->backups('*'), GLOB_ONLYDIR) ?: [] as $folder) {
            $data = PlanStore::read($folder.'/backup.json');

            if ($data === null || ($data['status'] ?? null) !== 'complete') {
                continue;
            }

            $list[] = [
                'id' => basename($folder),
                'from' => (string) ($data['from'] ?? ''),
                'to' => (string) ($data['to'] ?? ''),
                'created_at' => (int) ($data['created_at'] ?? 0),
                'size' => (int) ($data['size'] ?? 0),
                'files' => count((array) ($data['entries'] ?? [])),
                'undo' => false,
                // The database backup made before that update (Tools > Backups), named in the Undo text.
                'database_backup' => is_string($data['database_backup'] ?? null) && $data['database_backup'] !== '' ? $data['database_backup'] : null,
            ];
        }

        usort($list, fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
        $undo = $this->undoable();

        foreach ($list as &$item) {
            $item['undo'] = $undo !== null && $undo['id'] === $item['id'];
        }

        return $list;
    }

    /**
     * The newest lines of the step log of a plan.
     *
     * @return list<string>
     */
    public function logLines(?array $plan = null, int $lines = 80): array
    {
        $plan ??= $this->store->get();

        if ($plan === null) {
            return [];
        }

        $file = $this->paths->job((string) $plan['job']).'/update.log';

        if (! is_file($file) && ! empty($plan['backup_id'])) {
            $file = $this->paths->backups((string) $plan['backup_id']).'/update.log';
        }

        if (! is_file($file)) {
            return [];
        }

        $all = preg_split('/\R/', trim((string) file_get_contents($file))) ?: [];

        return array_slice($all, -$lines);
    }

    /** What the plan is doing now, in the administrator's words. */
    public static function label(array $plan): string
    {
        $mb = fn (?int $bytes): string => number_format(($bytes ?? 0) / 1048576, 1);
        $version = (string) ($plan['version'] ?? '');
        $undo = ($plan['kind'] ?? 'update') === 'undo';

        return match ($plan['status'] ?? '') {
            'checking', 'downloading' => ! empty($plan['download']['total'])
                ? __('Downloading: :done of :total MB', ['done' => $mb($plan['download']['done'] ?? 0), 'total' => $mb($plan['download']['total'])])
                : __('Downloading: :done MB', ['done' => $mb($plan['download']['done'] ?? 0)]),
            'verifying' => __('Checking the download...'),
            'unpacking' => __('Unpacking: :done of :total files', ['done' => number_format((int) ($plan['unpack']['next'] ?? 0)), 'total' => number_format((int) ($plan['unpack']['total'] ?? 0))]),
            'backing-up' => __('Backing up the database: table :done of :total', [
                'done' => min(count((array) ($plan['db']['tables'] ?? [])), (int) ($plan['db']['table'] ?? 0) + 1),
                'total' => count((array) ($plan['db']['tables'] ?? [])),
            ]),
            'preparing' => match (true) {
                // Undo this update compares the files with its backup before the site goes down.
                $undo => __('Putting the files of AVDBCMS :version back...', ['version' => $version]),
                isset($plan['prepare']['total']) => __('Comparing your files with the new version: :done of :total', ['done' => number_format((int) $plan['prepare']['done']), 'total' => number_format((int) $plan['prepare']['total'])]),
                default => __('Getting the new files ready...'),
            },
            'swapping' => $undo ? __('Putting the files of AVDBCMS :version back...', ['version' => $version]) : __('Installing the new files...'),
            'rolling-back' => __('Something went wrong. Putting your files back...'),
            'swapped', 'migrating' => $undo ? __('Finishing the undo...') : __('Finishing the update...'),
            'done' => __('AVDBCMS :version is installed.', ['version' => $version]),
            'undone' => __('Your files are back to AVDBCMS :version. The database stays as it is now.', ['version' => $version]),
            'cancelled' => $undo ? __('The undo was cancelled. Your site keeps the new version.') : __('The update was cancelled. Nothing on your site was changed.'),
            // The swap put every file back: an undo that stopped leaves the new version, an update the old one.
            'failed' => ! empty($plan['rolled_back']) ? match (true) {
                $undo && self::stopped($plan) => __('The undo was cancelled. Your site keeps the new version.'),
                $undo => __('The undo could not finish. Your site keeps the new version. Nothing in the database was changed.'),
                self::stopped($plan) => __('The update was stopped. Your site was put back as it was. Nothing in the database was changed.'),
                default => __('The update could not be installed. Your site was put back as it was. Nothing in the database was changed.'),
            } : (string) ($plan['failure']['reason'] ?? __('The update stopped.')),
            default => '',
        };
    }

    /** The title of a plan that ended without its result (Tools > Updates shows it above the reason). */
    public static function failureTitle(array $plan): string
    {
        $undo = ($plan['kind'] ?? 'update') === 'undo';

        return match (true) {
            $undo && self::stopped($plan) => __('The undo was cancelled'),
            $undo => __('The undo did not finish'),
            self::stopped($plan) => __('The update was stopped'),
            ! empty($plan['rolled_back']) => __('The update was not installed'),
            default => __('The update stopped.'),
        };
    }

    /** The administrator stopped the swap ("Undo the update" / "Cancel the undo" on the maintenance page). */
    private static function stopped(array $plan): bool
    {
        return ($plan['failure']['reason'] ?? null) === MaintenanceHook::STOPPED;
    }

    /**
     * The release a plan installs: its addresses and file names built from the version (ReleaseCheck::fromTag), never
     * taken from the kept answer (a cache store somebody else can write must not send the download elsewhere). From
     * the answer only what is shown (name, date), the zip's size (at most 300 MB) and GitHub's SHA-256 digest come along.
     */
    private static function trusted(array $offered): array
    {
        $release = ReleaseCheck::fromTag(AppVersion::tag((string) $offered['version']));
        $size = $offered['zip']['size'] ?? null;
        $digest = $offered['zip']['digest'] ?? null;

        if (is_string($offered['name'] ?? null) && trim($offered['name']) !== '') {
            $release['name'] = mb_substr(trim($offered['name']), 0, 200);
        }

        $release['published_at'] = is_string($offered['published_at'] ?? null) ? mb_substr($offered['published_at'], 0, 40) : null;
        $release['zip']['size'] = is_int($size) && $size > 0 && $size <= ReleaseCheck::MAX_ZIP_BYTES ? $size : null;
        $release['zip']['digest'] = is_string($digest) && preg_match('/^[0-9a-f]{64}\z/i', $digest) === 1 ? strtolower($digest) : null;

        return $release;
    }

    /** The file name of a plan's zip: the release's fixed asset name, checked again (never a path out of plan.json). */
    private static function zipName(array $plan): string
    {
        $version = (string) ($plan['version'] ?? '');

        if (! AppVersion::isVersion($version) || ($plan['release']['zip']['name'] ?? null) !== AppVersion::updateAsset($version)) {
            throw new UpdateException(__('The update package is not AVDBCMS :version. Nothing on your site was changed.', ['version' => $version]), 'download', false, 'zip name in the plan');
        }

        return AppVersion::updateAsset($version);
    }

    /** "An update to X.Y.Z is being installed by :name since :time." */
    public static function busyMessage(array $plan): string
    {
        return __('An update to :version is being installed by :name since :time.', [
            'version' => $plan['version'] ?? '',
            'name' => $plan['admin_name'] ?? '',
            'time' => Carbon::createFromTimestamp((int) ($plan['started_at'] ?? time()))->setTimezone(config('app.timezone'))->translatedFormat('H:i'),
        ]);
    }

    /** @return array{done: int, total: int}|null */
    public static function progress(array $plan): ?array
    {
        return match ($plan['status'] ?? '') {
            'checking', 'downloading' => ['done' => (int) ($plan['download']['done'] ?? 0), 'total' => (int) ($plan['download']['total'] ?? 0)],
            'unpacking' => ['done' => (int) ($plan['unpack']['next'] ?? 0), 'total' => (int) ($plan['unpack']['total'] ?? 0)],
            'backing-up' => ['done' => (int) ($plan['db']['table'] ?? 0), 'total' => count((array) ($plan['db']['tables'] ?? []))],
            'preparing' => isset($plan['prepare']['total']) ? ['done' => (int) $plan['prepare']['done'], 'total' => (int) $plan['prepare']['total']] : null,
            'swapping', 'rolling-back' => ['done' => (int) ($plan['swap']['done'] ?? 0), 'total' => (int) ($plan['swap']['total'] ?? 0)],
            default => null,
        };
    }

    /* ---------------------------------------------------------------- steps */

    private function download(array $plan, float $deadline): array
    {
        $job = $this->job($plan);
        $release = (array) $plan['release'];
        $name = self::zipName($plan);
        $plan['status'] = 'downloading';

        if (empty($plan['checksum'])) {
            $plan['checksum'] = $this->downloader->checksum($release);
            $this->log($plan, 'download', 'checksum file read');
            $this->store->put($plan);
        }

        $part = $job.'/'.$name.'.part';

        if (! is_file($part)) {
            $this->log($plan, 'download', 'downloading '.$name.' from github.com');
        }

        do {
            try {
                $slice = $this->downloader->slice($release, $part, (int) ($plan['download']['slice'] ?? Downloader::FIRST_SLICE), $plan['download']['total'] ?? null);
            } catch (UpdateException $e) {
                if ($e->retry) {
                    // The slice did not arrive (a link too slow for it, a dropped connection): the next try asks for less.
                    $plan['download']['slice'] = Downloader::smaller((int) ($plan['download']['slice'] ?? Downloader::FIRST_SLICE));
                    $this->store->put($plan);
                }

                throw $e;
            }

            $plan['download'] = ['done' => $slice['done'], 'total' => $slice['total'], 'slice' => $slice['slice']];
            $plan['step_at'] = time();
            $this->store->put($plan);
        } while (! $slice['complete'] && microtime(true) < $deadline - 8);

        if ($slice['complete']) {
            if (! @rename($part, $job.'/'.$name)) {
                throw new UpdateException(__('Could not write the download. The disk may be full. Nothing on your site was changed.'), 'download', true, 'rename of the .part file');
            }

            $plan['status'] = 'verifying';
            $this->log($plan, 'download', 'downloaded '.number_format($slice['total'] / 1048576, 1).' MB');
        }

        return $plan;
    }

    private function verify(array $plan): array
    {
        $job = $this->job($plan);
        $zip = $job.'/'.self::zipName($plan);

        if (! is_file($zip)) {
            $plan['status'] = 'downloading';
            $plan['download'] = ['done' => 0, 'total' => $plan['release']['zip']['size'] ?? null, 'slice' => Downloader::FIRST_SLICE];

            return $plan;
        }

        if (empty($plan['checksum'])) {
            $plan['checksum'] = $this->downloader->checksum((array) $plan['release']);
        }

        $actual = Checksum::file($zip);
        $digest = $plan['release']['zip']['digest'] ?? null;

        if (! Checksum::matches((string) $plan['checksum'], $actual) || ($digest !== null && ! Checksum::matches((string) $digest, $actual))) {
            @unlink($zip);

            throw new UpdateException(__('The download is damaged (its SHA-256 does not match). Nothing on your site was changed. Try again.'), 'verify', false,
                'sha256 mismatch'.($digest !== null && Checksum::matches((string) $plan['checksum'], $actual) ? ' (the API digest)' : ''));
        }

        $this->log($plan, 'verify', 'SHA-256 matches the .sha256 file'.($digest !== null ? ' and the API digest' : ''));
        $guard = ZipEntries::guard(ZipEntries::read($zip));
        $manifest = (new Unpacker($zip, (string) $plan['engine']))->manifest();

        if ($manifest->version !== $plan['version']) {
            throw new UpdateException(__('The update package is not AVDBCMS :version. Nothing on your site was changed.', ['version' => $plan['version']]), 'verify', false, 'manifest version '.$manifest->version);
        }

        $manifest->assertInstallable($this->version);
        $inZip = array_fill_keys($guard['files'], true);

        if (! isset($inZip[Manifest::FILE])) {
            throw new UpdateException(__('The update package has no valid list of its files. Nothing on your site was changed.'), 'verify', false, 'no manifest entry');
        }

        unset($inZip[Manifest::FILE]);
        $missing = array_diff_key($manifest->files, $inZip);
        $extra = array_diff_key($inZip, $manifest->files);

        if ($missing !== [] || $extra !== []) {
            throw new UpdateException(__('The update package is not complete. Nothing on your site was changed.'), 'verify', false,
                count($missing).' files of the manifest missing, '.count($extra).' extra'.($missing !== [] ? ' (first: '.array_key_first($missing).')' : ''));
        }

        if (($why = $this->spaceProblem($this->spaceNeeded(0, $guard['size'], (bool) $plan['skip_backup'], false))) !== null) {
            throw new UpdateException($why, 'verify', true);
        }

        $entries = (string) json_encode(['files' => $guard['files']], JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($job.'/entries.json', $entries) !== strlen($entries)) {
            throw new UpdateException(__('Could not write the download. The disk may be full. Nothing on your site was changed.'), 'verify', true, 'entries.json');
        }

        $plan['unpack'] = ['next' => 0, 'total' => count($guard['files']), 'size' => $guard['size']];
        $plan['status'] = 'unpacking';
        $this->log($plan, 'verify', 'zip checked: '.count($guard['files']).' files ('.number_format($guard['size'] / 1048576, 1).' MB unpacked), '
            .count($guard['skipped']).' placeholders skipped; requires PHP '.$manifest->requiresPhp.', installs over '.$manifest->minFrom.' or newer');

        return $plan;
    }

    private function unpack(array $plan, float $deadline): array
    {
        $job = $this->job($plan);
        $zip = $job.'/'.self::zipName($plan);
        $files = (array) (PlanStore::read($job.'/entries.json')['files'] ?? []);

        if ($files === [] || ! is_file($zip)) {
            $plan['status'] = 'verifying';

            return $plan;
        }

        $unpacker = new Unpacker($zip, (string) $plan['engine']);
        $from = (int) ($plan['unpack']['next'] ?? 0);

        if ($from === 0) {
            File::deleteDirectory($job.'/staging');
            $this->log($plan, 'unpack', 'unpacking with '.$plan['engine']);
        }

        $next = $unpacker->stage($files, $from, $job.'/staging', $unpacker->manifest(), $deadline);
        $plan['unpack']['next'] = $next;

        if ($next >= count($files)) {
            $plan['status'] = $plan['skip_backup'] ? 'preparing' : 'backing-up';
            $this->log($plan, 'unpack', 'unpacked and checked '.count($files).' files');
        }

        return $plan;
    }

    private function backUp(array $plan, float $deadline, User $admin): array
    {
        try {
            if (empty($plan['db'])) {
                $plan['db'] = $this->database->begin('backup_before_update_'.$plan['version'].'_');
                $this->log($plan, 'database', 'database backup started: '.$plan['db']['name']);
                $this->store->put($plan);
            }

            $plan['db'] = $this->database->resume((array) $plan['db'], $deadline);
            // At once: the next step starts where this one ended (DatabaseBackup cuts the file back to that point).
            $this->store->put($plan);
        } catch (Throwable $e) {
            // A dump cut off in the middle is no backup: the next try starts it again.
            if (! empty($plan['db'])) {
                $this->database->discard((array) $plan['db']);
            }

            unset($plan['db']);
            $this->store->put($plan);

            throw new UpdateException(__('The database backup did not work: :reason', ['reason' => Str::limit($e->getMessage(), 160)]), 'database', true, null, $e);
        }

        if (! empty($plan['db']['done'])) {
            $file = (string) $plan['db']['file'];
            $size = is_file($file) ? (int) filesize($file) : 0;
            $plan['database_backup'] = ['name' => $plan['db']['name'], 'size' => $size, 'method' => $plan['db']['method'] ?? 'Pure-PHP'];
            $plan['db'] = ['done' => true, 'name' => $plan['db']['name'], 'file' => $file];
            $plan['status'] = 'preparing';
            $this->log($plan, 'database', 'database backup done: '.$plan['database_backup']['name'].' ('.number_format($size / 1048576, 2).' MB)');
            AuditLogController::log('update.database_backup', $plan['database_backup']['name'].' ('.number_format($size / 1048576, 2).' MB, '.$plan['database_backup']['method'].')', (int) $admin->getKey());
        }

        return $plan;
    }

    /**
     * Hash the live files (in slices), plan every move, write the merged language files, the file backup folder and
     * its backup.json, check write access, move the new built assets in, then put the site into maintenance with the
     * hook. Nothing live changes before the hook is in place except new files that no page uses yet.
     */
    private function prepare(array $plan, float $deadline): array
    {
        $job = $this->job($plan);
        $manifest = Manifest::parse((string) @file_get_contents($job.'/staging/'.Manifest::FILE));
        $installed = Manifest::installed($this->paths->manifest());

        if ($installed === null) {
            throw new UpdateException(__('This site has no list of its AVDBCMS files (avdbcms-manifest.json), so it cannot be updated from here. Upload the update zip with your file manager, then click Finish update on Home.'), 'prepare', false);
        }

        // A plan made before (an earlier try of this step, or a swap whose token ran out before it began): put back what
        // it moved, start the plan again. Its file backup folder goes with it (the way back emptied it).
        if (is_file($job.'/job.json')) {
            (new SwapEngine((array) PlanStore::read($job.'/job.json')))->rollback(INF);
        }

        foreach (['job.json', 'swap.json', 'journal.jsonl'] as $stale) {
            @unlink($job.'/'.$stale);
        }

        if (! empty($plan['backup_id'])) {
            File::deleteDirectory($this->paths->backups((string) $plan['backup_id']));
            unset($plan['backup_id']);
        }

        // Old built assets the last one-click update kept for cached pages: deleted now (when state.json speaks of the
        // version this site runs; after a manual upload it may not).
        $state = $this->store->state() ?? [];
        $keptAssets = ($state['installed_version'] ?? null) === $installed->version
            ? array_values(array_filter((array) ($state['kept_assets'] ?? []), fn (mixed $path): bool => is_string($path) && Manifest::safePath($path)))
            : [];

        // 1. The SHA-256 of every live file the update may touch (kept between requests in live.json).
        $paths = array_keys($manifest->files + array_fill_keys($manifest->removed, true) + $installed->files
            + array_fill_keys($installed->removed, true) + array_fill_keys($keptAssets, true));
        $paths[] = Manifest::FILE;
        $live = (array) (PlanStore::read($job.'/live.json') ?? []);
        $hashed = 0;

        foreach ($paths as $path) {
            if (array_key_exists($path, $live) || Manifest::preserved($path)) {
                continue;
            }

            if (microtime(true) >= $deadline) {
                PlanStore::write($job.'/live.json', $live);
                $plan['prepare'] = ['done' => count($live), 'total' => count($paths)];

                return $plan;
            }

            $live[$path] = SwapEngine::hash($this->paths->root($path));
            $hashed++;
        }

        PlanStore::write($job.'/live.json', $live);

        if ($hashed > 0) {
            // The rest (planning, backup.json, the write test, the new built assets, the hook) starts in a request of
            // its own, with a whole time budget.
            $plan['prepare'] = ['done' => count($paths), 'total' => count($paths)];

            return $plan;
        }

        unset($plan['prepare']);

        try {
            return $this->planAndGoDown($plan, $job, $manifest, $installed, $live, $keptAssets);
        } catch (Throwable $e) {
            // The next try hashes the live files again: they may change before it comes.
            @unlink($job.'/live.json');

            throw $e;
        }
    }

    /**
     * prepare(), once every live file is hashed: plan the moves, write the merged language files, the file backup
     * folder and its backup.json, check write access, move the new built assets in, then the hook.
     *
     * @param  array<string, ?string>  $live
     * @param  list<string>  $keptAssets
     */
    private function planAndGoDown(array $plan, string $job, Manifest $manifest, Manifest $installed, array $live, array $keptAssets): array
    {
        // 2. What happens to each file.
        $result = SwapPlanner::plan(
            $manifest,
            $installed,
            (string) SwapEngine::hash($job.'/staging/'.Manifest::FILE),
            fn (string $path): ?string => array_key_exists($path, $live) ? $live[$path] : SwapEngine::hash($this->paths->root($path)),
            fn (string $path): ?string => is_file($this->paths->root($path)) ? (string) file_get_contents($this->paths->root($path)) : null,
            fn (string $path): ?string => is_file($job.'/staging/'.$path) ? (string) file_get_contents($job.'/staging/'.$path) : null,
            $keptAssets,
        );
        $plan['kept_assets'] = $result['kept_assets'];

        File::deleteDirectory($job.'/merged');

        foreach ($result['merged'] as $path => $text) {
            File::ensureDirectoryExists(dirname($job.'/merged/'.$path), 0755);

            if (@file_put_contents($job.'/merged/'.$path, $text) !== strlen($text)) {
                throw new UpdateException(__('Could not write :file while unpacking. The disk may be full. Nothing on your site was changed.', ['file' => $path]), 'prepare', true);
            }
        }

        // 3. The file backup: backup.json lists every move from now on (the way back by hand works from it).
        $backupId = $plan['from'].'-to-'.$plan['version'].'-'.date('YmdHis');
        $folder = $this->paths->backups($backupId);
        File::ensureDirectoryExists($folder.'/files', 0755);
        PlanStore::write($folder.'/backup.json', [
            'format' => 1,
            'from' => $plan['from'],
            'to' => $plan['version'],
            'created_at' => time(),
            'admin_id' => $plan['admin_id'],
            'database_backup' => $plan['database_backup']['name'] ?? null,
            'status' => 'pending',
            'entries' => $result['entries'],
        ]);
        $plan['backup_id'] = $backupId;
        $plan['report'] = $result['report'];
        $jobFile = $this->writeJob($plan, $result['ops'], $job, $folder);
        $this->log($plan, 'prepare', sprintf('%d files to replace, %d to add, %d to remove, %d unchanged; %d kept with a copy next to them, %d translations merged, %d changed files replaced',
            $result['report']['replaced'], $result['report']['added'], $result['report']['removed'], $result['report']['unchanged'],
            count($result['report']['kept']), count($result['report']['merged']), count($result['report']['overwritten'])));

        // 4. Last checks before anything live changes (the folders the moves need are made here, see writeProblem()).
        if (($why = $this->preCheck()) !== null || ($why = $this->writeProblem($result['ops'], [$job, $folder], $folder)) !== null) {
            File::deleteDirectory($folder);
            unset($plan['backup_id']);

            throw new UpdateException($why, 'prepare', true);
        }

        // 5. New built assets and the copies next to kept files: no page uses them yet.
        $engine = new SwapEngine((array) PlanStore::read($jobFile));
        $takeBack = function () use ($engine, $folder): void {
            try {
                $engine->rollback(INF);
                File::deleteDirectory($folder);
            } catch (Throwable $e) {
                // The next try of this step puts them back from the journal (prepare() starts with that).
                self::reportSafely($e);
            }
        };

        try {
            $engine->forward(INF, ['pre']);
        } catch (Throwable $e) {
            $takeBack();

            throw new UpdateException(__('The update could not move its files: :reason Nothing on your site was changed.', ['reason' => Str::limit($e->getMessage(), 160)]), 'prepare', true, null, $e);
        }

        // 6. The swap: token, hook.
        try {
            return $this->goDown($plan, count($result['ops']), $jobFile);
        } catch (Throwable $e) {
            $takeBack();

            throw $e instanceof UpdateException ? $e : new UpdateException(__('The update could not prepare its maintenance page. Nothing on your site was changed.'), 'prepare', true, $e->getMessage(), $e);
        }
    }

    /**
     * The "preparing" steps of Undo this update: the live file and the backup copy of every entry of the update's
     * backup.json are hashed within the time budget (kept between requests in live.json); once all are, the next
     * request plans the moves back (files changed after the update are left alone and listed), checks write access and
     * takes the site down with the hook. The same swap as an update, from the backup's files/ to the site.
     */
    private function prepareUndo(array $plan, float $deadline): array
    {
        $job = $this->job($plan);
        $backup = $this->undoable();

        if ($backup === null || $backup['id'] !== ($plan['backup_id'] ?? null)) {
            throw new UpdateException(__('There is no update to undo: its backup is gone or a newer version is installed.'), 'prepare', false);
        }

        // A plan made before (an earlier try, or a swap whose token ran out before it began): nothing of an undo moves
        // before the site goes down, but whatever a journal says moved goes back first.
        if (is_file($job.'/job.json')) {
            (new SwapEngine((array) PlanStore::read($job.'/job.json')))->rollback(INF);
        }

        foreach (['job.json', 'swap.json', 'journal.jsonl'] as $stale) {
            @unlink($job.'/'.$stale);
        }

        $folder = $this->paths->backups($backup['id']);
        $entries = array_values(array_filter((array) ($backup['entries'] ?? []), fn (mixed $entry): bool => is_array($entry)
            && is_string($entry['path'] ?? null) && (Manifest::safePath($entry['path']) || $entry['path'] === Manifest::FILE)));
        $live = (array) (PlanStore::read($job.'/live.json') ?? []);
        $hashed = 0;

        // 1. The live file and the backup copy of every entry: [now, saved].
        foreach ($entries as $entry) {
            $path = (string) $entry['path'];

            if (array_key_exists($path, $live)) {
                continue;
            }

            if (microtime(true) >= $deadline) {
                PlanStore::write($job.'/live.json', $live);
                $plan['prepare'] = ['done' => count($live), 'total' => count($entries)];

                return $plan;
            }

            $live[$path] = [SwapEngine::hash($this->paths->root($path)), ($entry['old'] ?? null) !== null ? SwapEngine::hash($folder.'/files/'.$path) : null];
            $hashed++;
        }

        PlanStore::write($job.'/live.json', $live);

        if ($hashed > 0) {
            // The moves start in a request of their own, with a whole time budget.
            $plan['prepare'] = ['done' => count($entries), 'total' => count($entries)];

            return $plan;
        }

        unset($plan['prepare']);

        // 2. What goes back: the old copy of each replaced or removed file, and the files the update added go.
        $groups = ['vendor' => [], 'main' => [], 'delete' => [], 'entry' => [], 'last' => []];
        $kept = [];
        $restored = 0;
        $removed = 0;

        foreach ($entries as $entry) {
            $path = (string) $entry['path'];
            [$now, $saved] = array_values((array) ($live[$path] ?? [null, null])) + [null, null];
            $old = $entry['old'] ?? null;
            $new = $entry['new'] ?? null;
            $group = $path === Manifest::FILE ? 'last' : (in_array($path, SwapPlanner::ENTRY_POINTS, true) ? 'entry' : (str_starts_with($path, 'vendor/') ? 'vendor' : 'main'));

            if ($now === $old) {
                // Back as it was already (an added file that is gone, a file that has its old content).
                continue;
            }

            if ($now !== $new || ($old !== null && $saved !== $old)) {
                // Changed after the update (or its old copy is not in the backup): left alone and listed.
                $kept[] = $path;

                continue;
            }

            if ($old === null) {
                $groups[$group === 'last' ? 'last' : 'delete'][] = ['do' => 'delete', 'path' => $path, 'src' => null, 'old' => $now, 'new' => null];
                $removed++;
            } else {
                $groups[$group][] = ['do' => 'put', 'path' => $path, 'src' => 'files/'.$path, 'old' => $now, 'new' => $old];
                $restored++;
            }
        }

        $ops = [];

        foreach ($groups as $group => $list) {
            foreach ($list as $op) {
                $ops[] = ['i' => count($ops), 'phase' => $group === 'last' ? 'last' : 'main'] + $op;
            }
        }

        $plan['report'] = ['restored' => $restored, 'removed' => $removed, 'kept' => $kept];
        $jobFile = $this->writeJob($plan, $ops, $folder, $job.'/displaced');
        $this->log($plan, 'undo', $restored.' files back, '.$removed.' removed, '.count($kept).' left alone (changed after the update)');

        // 3. Last checks, then the hook. A failure here starts the comparison again at the next try.
        try {
            if (($why = $this->preCheck()) !== null || ($why = $this->writeProblem($ops, [$job, $folder], $job.'/displaced')) !== null) {
                throw new UpdateException($why, 'prepare', true);
            }

            return $this->goDown($plan, count($ops), $jobFile);
        } catch (Throwable $e) {
            @unlink($job.'/live.json');

            throw $e instanceof UpdateException ? $e : new UpdateException(__('The update could not prepare its maintenance page. Nothing on your site was changed.'), 'prepare', true, $e->getMessage(), $e);
        }
    }

    /** status swapping/rolling-back reached Laravel: the hook is gone; a new hook and token continue the swap. */
    private function reopen(array $plan): array
    {
        if ($this->hook->installed()) {
            return $plan;
        }

        $this->log($plan, 'swap', 'the maintenance hook was gone in the middle of the swap: it is written again');

        return $this->goDown($plan, (int) ($plan['swap']['total'] ?? 0), $this->paths->job((string) $plan['job']).'/job.json', true);
    }

    /**
     * A new swap token, plan status swapping, then the hook and the down file. The hook lets the holder of the plan's
     * update cookie in (handed out earlier, in an answer of its own: never in this one, which may be lost).
     */
    private function goDown(array $plan, int $total, string $jobFile, bool $again = false): array
    {
        $token = bin2hex(random_bytes(32));
        $plan['swap'] = array_merge((array) ($plan['swap'] ?? []), [
            'token_hash' => hash('sha256', $token),
            'token_expires' => time() + self::TOKEN_SECONDS,
            'total' => $total,
        ], $again ? [] : ['done' => 0]);

        if (! $again) {
            $plan['status'] = 'swapping';
        }

        $plan['step_at'] = time();
        // The plan says "swapping" before the hook exists: a hook that finds no swapping plan takes itself away.
        $this->store->put($plan);

        try {
            $this->hook->install($this->hookConfig($plan, $jobFile));
        } catch (Throwable $e) {
            if (! $again) {
                $plan['status'] = 'preparing';
                unset($plan['swap']);
                $this->store->put($plan);
            }

            throw $e;
        }

        $this->secrets = ['token' => $token];
        $this->log($plan, 'swap', 'the site is in maintenance while '.$total.' files move');

        return $plan;
    }

    /**
     * A new update cookie for the browser of this request when it needs one before a step may take the site down:
     * the plan has none or one about to expire, or (UpdatesController told) the browser did not send it: another
     * browser of the same administrator, or the answer of start() never arrived. A browser that keeps no cookies gets
     * COOKIE_TRIES answers in a row, then the update goes on without (its token still carries the swap).
     */
    private function freshCookie(array &$plan): ?string
    {
        $cookie = (array) ($plan['cookie'] ?? []);
        $valid = is_string($cookie['hash'] ?? null) && (int) ($cookie['expires'] ?? 0) > time() + self::COOKIE_RENEW;
        $sent = is_string($this->browserCookie) && $valid && hash_equals((string) $cookie['hash'], hash('sha256', $this->browserCookie));

        if ($valid && ($sent || ! $this->browserKnown)) {
            if ($sent) {
                $plan['cookie']['tries'] = 0;
            }

            return null;
        }

        $tries = $valid ? (int) ($cookie['tries'] ?? 0) + 1 : 0;

        if ($valid && $tries > self::COOKIE_TRIES) {
            return null;
        }

        if ($valid && $tries === self::COOKIE_TRIES) {
            $this->log($plan, 'cookie', 'this browser does not send the update cookie back: the update goes on without it');
        }

        return $this->issueCookie($plan, $tries);
    }

    /** The cookie of the maintenance page's Finish / Undo page: only its SHA-256 is kept (plan.json, then the hook). */
    private function issueCookie(array &$plan, int $tries = 0): string
    {
        $value = bin2hex(random_bytes(32));
        $plan['cookie'] = ['hash' => hash('sha256', $value), 'expires' => time() + self::COOKIE_SECONDS, 'tries' => $tries];

        return $value;
    }

    /** The update is in: state.json, the backup marked complete with its journal and log, work files away. */
    private function complete(array $plan, User $admin, int $migrations): array
    {
        $job = $this->paths->job((string) $plan['job']);
        $folder = $this->paths->backups((string) $plan['backup_id']);
        $this->check->forget();
        StatusProbeController::forget('updates');
        $this->store->putState([
            'installed_version' => $plan['version'],
            'previous_version' => $plan['from'],
            'updated_at' => time(),
            'backup_id' => $plan['backup_id'],
            'admin_id' => (int) $admin->getKey(),
            // Built assets of the version before, left for pages still cached in browsers: the next update deletes them.
            'kept_assets' => array_values((array) ($plan['kept_assets'] ?? [])),
        ]);

        $backup = (array) (PlanStore::read($folder.'/backup.json') ?? []);
        $this->log($plan, 'finish', 'database step done ('.$migrations.' migrations); AVDBCMS '.$plan['version'].' is installed');

        foreach (['journal.jsonl', 'update.log'] as $name) {
            if (is_file($job.'/'.$name)) {
                @copy($job.'/'.$name, $folder.'/'.$name);
            }
        }

        if ($backup !== []) {
            $backup['status'] = 'complete';
            $backup['finished_at'] = time();
            $backup['size'] = self::folderSize($folder.'/files');
            PlanStore::write($folder.'/backup.json', $backup);
        }

        $report = (array) ($plan['report'] ?? []);
        $settle = (array) ($plan['settle'] ?? []);
        AuditLogController::log('update.files', sprintf('%d replaced, %d added, %d removed (zip engine %s, OPcache %s)',
            (int) ($report['replaced'] ?? 0) + count((array) ($report['merged'] ?? [])), (int) ($report['added'] ?? 0), (int) ($report['removed'] ?? 0),
            $plan['engine'] ?? '?', $settle['opcache'] ?? '?'), (int) $admin->getKey());
        AuditLogController::log('update.finished', $plan['from'].' -> '.$plan['version'].' ('.$migrations.' database changes)', (int) $admin->getKey());

        File::deleteDirectory($job);
        $this->pruneBackups((string) $plan['backup_id']);
        self::refreshCaches();
        $plan['status'] = 'done';
        $plan['finished_at'] = time();
        $plan['migrations'] = $migrations;
        unset($plan['swap']);
        $this->store->put($plan);

        return $plan;
    }

    private function finishUndo(array $plan, User $admin): array
    {
        $this->check->forget();
        StatusProbeController::forget('updates');
        $this->store->putState([
            'installed_version' => $plan['version'],
            'previous_version' => $plan['from'],
            'updated_at' => time(),
            'backup_id' => null,
            'admin_id' => (int) $admin->getKey(),
        ]);
        $this->log($plan, 'finish', 'the files of '.$plan['version'].' are back');
        AuditLogController::log('update.undone', $plan['from'].' -> '.$plan['version'].' (files only)', (int) $admin->getKey());

        $folder = $this->paths->backups((string) $plan['backup_id']);
        $kept = array_values(array_filter((array) ($plan['report']['kept'] ?? []), 'is_string'));

        if ($kept === []) {
            // The backup's files went back into the site; what the undo took out of it is not needed any more.
            File::deleteDirectory($folder);
        } else {
            // Files changed after the update were left as they are: their copies from before the update are the only
            // ones, so they stay in files/ (the rest goes) until a later update finishes (pruneBackups()).
            $this->keepUndoneCopies($folder, $kept);
            $plan['kept_backup'] = basename($folder);
        }

        File::deleteDirectory($this->paths->job((string) $plan['job']));
        self::refreshCaches();
        $plan['status'] = 'undone';
        $plan['finished_at'] = time();
        unset($plan['swap']);
        $this->store->put($plan);

        return $this->answer($plan, ['next' => 'reload', 'message' => self::label($plan)]);
    }

    /**
     * After an undo that left files alone: the backup keeps only their copies from before the update, and backup.json
     * says so (status undone: no update to undo any more, not listed; removed after the next finished update).
     *
     * @param  list<string>  $kept
     */
    private function keepUndoneCopies(string $folder, array $kept): void
    {
        $backup = (array) (PlanStore::read($folder.'/backup.json') ?? []);
        $keep = array_fill_keys($kept, true);

        foreach ((array) ($backup['entries'] ?? []) as $entry) {
            $path = (string) ($entry['path'] ?? '');

            if ($path !== '' && ! isset($keep[$path]) && (Manifest::safePath($path) || $path === Manifest::FILE)) {
                @unlink($folder.'/files/'.$path);
            }
        }

        try {
            PlanStore::write($folder.'/backup.json', array_merge($backup, ['status' => 'undone', 'undone_at' => time(), 'kept' => $kept]));
        } catch (Throwable $e) {
            self::reportSafely($e);
        }
    }

    /**
     * After the files changed (an update, an undo): the compiled views, the settings cache and the admin cache version
     * (Finish update clears them only when it ran a migration), then queue:restart, so long-running queue workers (a
     * supervisor's queue:work, Horizon's workers) load the new code. Each on its own: one that fails stops nothing.
     */
    private static function refreshCaches(): void
    {
        $clears = [
            fn () => Artisan::call('view:clear'),
            fn () => Setting::clearCache(),
            fn () => AdminCache::bumpVersion(),
            fn () => Artisan::call('queue:restart'),
        ];

        foreach ($clears as $clear) {
            try {
                $clear();
            } catch (Throwable $e) {
                self::reportSafely($e);
            }
        }
    }

    /* ------------------------------------------------------------- helpers */

    /** @param  callable(): array<string, mixed>  $work */
    private function locked(callable $work): array
    {
        // What an earlier call on this object handed out is not this answer's.
        $this->secrets = null;

        try {
            $lock = $this->store->lock();
        } catch (UpdateException $e) {
            // Not "busy": this disk cannot lock files at all, so no update can run safely here.
            return $this->refuse($e->getMessage());
        }

        if ($lock === null) {
            return ['ok' => false, 'busy' => true, 'status' => $this->store->get()['status'] ?? null, 'next' => null, 'label' => null, 'progress' => null,
                'retry_after' => 3, 'message' => __('Another step of the update is running. Wait a moment.')];
        }

        $this->holding = true;

        try {
            return $work();
        } finally {
            $this->holding = false;
            $this->store->unlock($lock);
        }
    }

    /**
     * Another administrator's plan: refused while it moves, taken over once it stalled. $plan is the caller's own copy
     * (by reference): the step goes on with the new owner, and whatever it saves keeps them.
     */
    private function takeOver(array &$plan, User $admin): ?array
    {
        if ((int) $plan['admin_id'] === (int) $admin->getKey()) {
            return null;
        }

        if (! self::stalled($plan)) {
            return $this->refuse(self::busyMessage($plan));
        }

        $this->log($plan, 'continue', 'stalled since '.date('Y-m-d H:i:s', (int) $plan['step_at']).'; continued by '.self::adminName($admin));
        $plan['admin_id'] = (int) $admin->getKey();
        $plan['admin_name'] = self::adminName($admin);
        $plan['step_at'] = time();
        // The Finish / Undo page of the maintenance hook is the new owner's from now on (their browser gets the cookie
        // with this answer, which does nothing else: see step()).
        $this->secrets = ['cookie' => $this->issueCookie($plan)];
        $this->store->put($plan);

        return null;
    }

    /** A step failed: try again later (the plan waits) or stop the plan, with the reason for the page. */
    private function failed(array $plan, UpdateException $e, User $admin): array
    {
        $this->log($plan, $e->step, 'stopped: '.$e->getMessage().($e->detail !== null ? ' ['.$e->detail.']' : ''));

        if ($e->retry && in_array($plan['status'], self::BEFORE_SWAP, true)) {
            $plan['last_error'] = $e->getMessage();
            $plan['step_at'] = time();
            $this->store->put($plan);

            return $this->answer($plan, ['ok' => false, 'next' => null, 'retry' => true, 'message' => $e->getMessage()]);
        }

        // A zip that failed its checks (as a whole or file by file) is not kept for a new try.
        $this->clearWork($plan, keepZip: ! in_array($e->step, ['verify', 'unpack'], true));
        $plan['status'] = 'failed';
        $plan['failure'] = ['step' => $e->step, 'reason' => $e->getMessage(), 'detail' => $e->detail, 'at' => date('c'), 'audited' => true];
        $plan['step_at'] = time();
        $this->store->put($plan);
        AuditLogController::log('update.failed', $e->step.': '.Str::limit($e->getMessage(), 200), (int) $admin->getKey());

        return $this->answer($plan, ['ok' => false, 'next' => null, 'retry' => false, 'message' => $e->getMessage()]);
    }

    /** A swap failure the hook reported (status failed, its audit row not written yet). */
    private static function unsettled(array $plan): bool
    {
        return ($plan['status'] ?? null) === 'failed' && ($plan['failure']['audited'] ?? true) === false;
    }

    /**
     * A swap the hook rolled back: its audit row (the hook has no database), the empty file backup and the staged
     * files go; the zip and the step log stay for a new try. Called under the update lock only (see plan()).
     */
    private function settleFailure(array $plan): array
    {
        if (! self::unsettled($plan)) {
            return $plan;
        }

        $plan['failure']['audited'] = true;

        try {
            $this->store->put($plan);
        } catch (Throwable) {
            return $plan;
        }

        AuditLogController::log('update.failed', 'swap: '.Str::limit((string) ($plan['failure']['reason'] ?? ''), 200).(! empty($plan['rolled_back']) ? ' (files put back)' : ''), (int) ($plan['admin_id'] ?? 0) ?: null);

        if (($plan['kind'] ?? 'update') === 'undo') {
            // The update's own backup stays: the undo can be tried again.
            File::deleteDirectory($this->paths->job((string) $plan['job']));

            return $plan;
        }

        if (! empty($plan['rolled_back']) && ! empty($plan['backup_id'])) {
            File::deleteDirectory($this->paths->backups((string) $plan['backup_id']));
        }

        $this->clearWork($plan);

        return $plan;
    }

    /** Throw away the work files of a plan (before its swap); a finished database backup stays in Tools > Backups. */
    private function clearWork(array $plan, bool $keepZip = true): void
    {
        $job = $this->paths->job((string) ($plan['job'] ?? ''));

        if (($plan['job'] ?? '') === '' || ! is_dir($job)) {
            return;
        }

        // First the files that moved (the backup folder holds the replaced ones), then the folders.
        $this->rollbackJob($job);

        if (! empty($plan['db']) && empty($plan['db']['done'])) {
            $this->database->discard((array) $plan['db']);
        }

        // The file backup an update made for its swap; an undo's backup_id is the update it puts back, which stays.
        if (! empty($plan['backup_id']) && in_array($plan['status'], self::BEFORE_SWAP, true) && ($plan['kind'] ?? 'update') === 'update') {
            File::deleteDirectory($this->paths->backups((string) $plan['backup_id']));
        }

        $this->emptyFolder($job, ['update.log', $keepZip ? (string) ($plan['release']['zip']['name'] ?? '') : '']);
    }

    /**
     * The work folder of an earlier try of a version, before a new plan uses it: a swap it left half done is put back
     * first (its journal says what moved), then everything goes but $keep (a zip young enough to be used again).
     */
    private function discardJob(string $job, ?string $keep): void
    {
        $this->rollbackJob($job);
        $this->emptyFolder($job, [(string) $keep]);
    }

    /**
     * The work folders of other versions (named X.Y.Z): a zip and a log of a try that failed or was left. Undo folders
     * and the file backups are not touched; a folder whose swap a journal says is half done is put back first.
     */
    private function pruneJobs(string $keep): void
    {
        foreach (glob($this->paths->work('*'), GLOB_ONLYDIR) ?: [] as $folder) {
            $name = basename($folder);

            if ($name !== $keep && AppVersion::isVersion($name)) {
                $this->rollbackJob($folder);
                File::deleteDirectory($folder);
            }
        }
    }

    /** Put back what a job's journal says moved (nothing when it is all put back already or never began). */
    private function rollbackJob(string $job): void
    {
        if (! is_file($job.'/job.json') || ! is_file($job.'/journal.jsonl')) {
            return;
        }

        try {
            (new SwapEngine((array) PlanStore::read($job.'/job.json')))->rollback(INF);
        } catch (Throwable $e) {
            self::reportSafely($e);
        }
    }

    /**
     * Delete everything in a folder except the names in $keep (scandir, not glob: GLOB_BRACE does not exist on every
     * PHP build).
     *
     * @param  list<string>  $keep
     */
    private function emptyFolder(string $folder, array $keep): void
    {
        foreach (is_dir($folder) ? (scandir($folder) ?: []) : [] as $name) {
            if ($name === '.' || $name === '..' || in_array($name, $keep, true)) {
                continue;
            }

            is_dir($folder.'/'.$name) && ! is_link($folder.'/'.$name) ? File::deleteDirectory($folder.'/'.$name) : @unlink($folder.'/'.$name);
        }
    }

    /** Laravel maintenance by the owner, a running import, a waiting Finish update, OPcache that cannot be refreshed. */
    private function preCheck(): ?string
    {
        if ($this->hook->foreignDown()) {
            return __('The site is in Laravel maintenance mode (artisan down). Bring it back up, then try again.');
        }

        foreach (['avdb_import_runs', 'xvid_import_runs'] as $table) {
            try {
                if (DB::table($table)->whereIn('status', ['starting', 'running'])->where('updated_at', '>=', now()->subMinutes(10))->exists()) {
                    return __('An import is running. Wait until it ends or stop it, then try again.');
                }
            } catch (Throwable) {
                // No such table on this database: no import.
            }
        }

        if (PendingMigrations::pending() !== []) {
            return __('An earlier update still waits for its database step. Click Finish update on Home first, then try again.');
        }

        $opcache = SwapEngine::opcache();

        if ($opcache['enabled'] && ! $opcache['invalidate'] && ! $opcache['reset'] && ! $opcache['validate_timestamps']) {
            return __('This server keeps old PHP files in memory and does not let AVDBCMS clear them. Ask your host to restart PHP after a manual update.');
        }

        return null;
    }

    /** About how much free space an update needs: zip + unpacked files + the database dump, plus 20 %. */
    private function spaceNeeded(?int $zip, ?int $unpacked, bool $skipBackup, bool $zipToCome): int
    {
        $zip ??= 70 * 1048576;
        $files = $unpacked ?? (int) ($zip * 3.5);
        $dump = $skipBackup ? 0 : (int) (($this->database->size() ?? 0) * (DatabaseBackup::gzipAvailable() ? 0.35 : 1.1));

        return (int) ((($zipToCome ? $zip : 0) + $files + $dump) * 1.2);
    }

    private function spaceProblem(int $need): ?string
    {
        // disk_free_space() may be in disable_functions: then nothing can be measured and the writes check themselves.
        $free = function_exists('disk_free_space') ? @disk_free_space(is_dir($this->paths->work()) ? $this->paths->work() : $this->paths->storage) : false;

        if (! is_numeric($free) || $free >= $need) {
            return null;
        }

        return __('Not enough disk space: the update needs about :size free.', ['size' => number_format($need / 1048576).' MB']);
    }

    /**
     * Can PHP write where the moves go? The folders they need are made first: in the site (for new files) and in
     * $backup (files/<path> for every file that moves out), so the swap itself needs no new folder, and no free inode,
     * at its worst moment (a disk or an account quota that is nearly full). Then one test file per folder.
     *
     * @param  list<array<string, mixed>>  $ops
     * @param  list<string>  $extra
     */
    private function writeProblem(array $ops, array $extra, ?string $backup = null): ?string
    {
        $folders = array_fill_keys($extra, true);

        foreach ($ops as $op) {
            $folders[dirname($this->paths->root((string) $op['path']))] = true;

            if ($backup !== null && ($op['old'] ?? null) !== null) {
                $folders[dirname($backup.'/files/'.$op['path'])] = true;
            }
        }

        $tested = [];

        foreach (array_keys($folders) as $folder) {
            if (! SwapEngine::makeFolder($folder)) {
                return $this->cannotWrite($folder);
            }

            if (isset($tested[$folder])) {
                continue;
            }

            $tested[$folder] = true;
            $probe = $folder.'/.avdbcms-write-test-'.bin2hex(random_bytes(4));

            if (@file_put_contents($probe, 'ok') !== 2) {
                @unlink($probe);

                return $this->cannotWrite($folder);
            }

            @unlink($probe);
        }

        return null;
    }

    private function cannotWrite(string $folder): string
    {
        return __('AVDBCMS cannot write to :folder. Ask your host to give PHP write access to the site files, then try again.', [
            'folder' => ltrim(Str::after(str_replace('\\', '/', $folder), $this->paths->root), '/') ?: '/',
        ]);
    }

    /** The SwapEngine job of a plan (job.json, read by the hook too) and its list of moves (swap.json). */
    private function writeJob(array $plan, array $ops, string $source, string $backup): string
    {
        $job = $this->job($plan);
        PlanStore::write($job.'/swap.json', ['ops' => $ops]);
        $views = config('view.compiled');
        PlanStore::write($job.'/job.json', [
            'root' => $this->paths->root,
            'source' => str_replace('\\', '/', $source),
            'backup' => str_replace('\\', '/', $backup),
            'journal' => $job.'/journal.jsonl',
            'ops' => $job.'/swap.json',
            'log' => $job.'/update.log',
            // The compiled Blade views: the swap deletes them (and so does the way back), see SwapEngine::settle().
            'views' => is_string($views) && $views !== '' ? str_replace('\\', '/', $views) : null,
        ]);

        return $job.'/job.json';
    }

    /** MaintenanceHook's config: paths, hashes of the secrets, this swap's texts in the site's default language. */
    private function hookConfig(array $plan, string $jobFile): array
    {
        $job = $this->job($plan);
        $request = request();
        $origins = array_values(array_unique(array_filter([
            $request->getSchemeAndHttpHost(),
            ($url = parse_url((string) config('app.url'))) && isset($url['scheme'], $url['host']) ? $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '') : null,
        ])));

        return [
            'plan_id' => $plan['id'],
            'plan' => $this->paths->plan(),
            'job' => $jobFile,
            'lock' => $this->paths->lock(),
            'log' => $job.'/update.log',
            'hook' => $this->paths->hook(),
            'down' => $this->paths->down(),
            'token_hash' => $plan['swap']['token_hash'],
            'token_expires' => $plan['swap']['token_expires'],
            'cookie_name' => self::COOKIE,
            'cookie_hash' => (string) ($plan['cookie']['hash'] ?? ''),
            'cookie_expires' => (int) ($plan['cookie']['expires'] ?? 0),
            'origins' => $origins,
            'kind' => $plan['kind'],
            'version' => $plan['version'],
            'from' => $plan['from'],
            'budget' => self::BUDGET,
            // Absolute (a site in a sub-folder keeps its prefix); the hook's page links back to Tools > Updates.
            'admin_url' => Route::has('admin.updates.index') ? route('admin.updates.index') : url('/admin/updates'),
            'help_url' => AppVersion::HELP_URL,
            'site' => (string) config('app.name'),
            'strings' => HookTexts::for($plan['kind'], (string) config('site.default_locale', config('app.locale', 'en'))),
        ];
    }

    /** The newest finished update whose backup still holds its files, while the site runs its version. */
    private function undoable(): ?array
    {
        $state = $this->store->state();

        if ($state === null || empty($state['backup_id']) || ($state['installed_version'] ?? null) !== $this->version) {
            return null;
        }

        $data = PlanStore::read($this->paths->backups((string) $state['backup_id']).'/backup.json');

        if ($data === null || ($data['status'] ?? null) !== 'complete' || ($data['to'] ?? null) !== $this->version) {
            return null;
        }

        return ['id' => (string) $state['backup_id']] + $data;
    }

    /**
     * After an update finished: keep the newest KEEP_BACKUPS complete file backups; the copies an undo kept of files it
     * left alone (status undone) go now too.
     */
    private function pruneBackups(string $keep): void
    {
        $complete = [];

        foreach (glob($this->paths->backups('*'), GLOB_ONLYDIR) ?: [] as $folder) {
            $data = PlanStore::read($folder.'/backup.json');

            if ($data !== null && ($data['status'] ?? null) === 'complete') {
                $complete[basename($folder)] = (int) ($data['created_at'] ?? 0);
            } elseif ($data !== null && ($data['status'] ?? null) === 'undone' && basename($folder) !== $keep) {
                File::deleteDirectory($folder);
            }
        }

        arsort($complete);

        foreach (array_slice(array_keys($complete), self::KEEP_BACKUPS) as $id) {
            if ($id !== $keep) {
                File::deleteDirectory($this->paths->backups($id));
            }
        }
    }

    /**
     * File backups still "pending" when a new update starts belong to no plan any more (a try whose request was killed
     * after it made the folder): they go. Only start() calls this, while no plan is active.
     */
    private function prunePendingBackups(): void
    {
        foreach (glob($this->paths->backups('*'), GLOB_ONLYDIR) ?: [] as $folder) {
            if ((PlanStore::read($folder.'/backup.json')['status'] ?? null) === 'pending') {
                File::deleteDirectory($folder);
            }
        }
    }

    /**
     * report() a copy without addresses and without the exception chain: an HTTP client error can quote the signed
     * download address, and the site log must never hold it, nor a token (F8 16).
     */
    public static function reportSafely(Throwable $e): void
    {
        report(new RuntimeException('Tools > Updates: '.class_basename($e).': '.self::scrub($e->getMessage())
            .' at '.str_replace('\\', '/', Str::after($e->getFile(), base_path())).':'.$e->getLine()));
    }

    /** Addresses become their host; 64-hex values (tokens, cookies) are masked. */
    public static function scrub(string $text): string
    {
        $text = (string) preg_replace('~\b[a-z][a-z0-9+.-]*://([^/\s?#"\']+)[^\s"\']*~i', '$1', $text);

        return (string) preg_replace('/\b[0-9a-f]{64}\b/i', '[hidden]', $text);
    }

    private function job(array $plan): string
    {
        $job = $this->paths->job((string) $plan['job']);
        File::ensureDirectoryExists($job, 0755);

        return $job;
    }

    private function log(array $plan, string $step, string $message): void
    {
        $job = $this->paths->job((string) ($plan['job'] ?? ''));

        if (($plan['job'] ?? '') === '') {
            return;
        }

        File::ensureDirectoryExists($job, 0755);
        @file_put_contents($job.'/update.log', date('Y-m-d H:i:s')."\t".$step."\t".str_replace(["\r", "\n", "\t"], ' ', $message)."\n", FILE_APPEND | LOCK_EX);
    }

    /** Seconds of work in this request: BUDGET, less where PHP stops scripts sooner. */
    private function budget(): float
    {
        $limit = (int) ini_get('max_execution_time');

        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
            $limit = (int) ini_get('max_execution_time');
        }

        return $limit > 0 ? max(3.0, min(self::BUDGET, $limit - 8)) : self::BUDGET;
    }

    /**
     * The answer for the Updates page.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function answer(?array $plan, array $extra = []): array
    {
        $status = $plan['status'] ?? null;

        return array_merge([
            'ok' => true,
            'status' => $status,
            'next' => match ($status) {
                'checking', 'downloading', 'verifying', 'unpacking', 'backing-up', 'preparing' => 'step',
                'swapping', 'rolling-back' => 'swap',
                'swapped', 'migrating' => 'finish',
                default => null,
            },
            'label' => $plan !== null ? self::label($plan) : null,
            'progress' => $plan !== null ? self::progress($plan) : null,
            'message' => null,
        ], $extra);
    }

    private function refuse(string $message): array
    {
        return ['ok' => false, 'status' => $this->store->get()['status'] ?? null, 'next' => null, 'label' => null, 'progress' => null, 'message' => $message];
    }

    private static function adminName(User $admin): string
    {
        return (string) ($admin->username ?? $admin->email ?? ('#'.$admin->getKey()));
    }

    private static function ago(int $time): string
    {
        return Carbon::createFromTimestamp($time)->diffForHumans();
    }

    private static function folderSize(string $folder): int
    {
        $size = 0;

        if (! is_dir($folder)) {
            return 0;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->isFile() ? (int) $file->getSize() : 0;
        }

        return $size;
    }
}
