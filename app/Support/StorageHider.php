<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The files under storage/ that carry secrets get names nobody can guess, in case a server ever serves storage/ (nginx
 * with the web root at the project folder and only a try_files rule; security plan S03 + S24). From the release after
 * 1.2.2 the app writes them that way itself; this moves what an earlier release left (also files an older release wrote
 * again after an Undo; before such an Undo, prepareForOlderRelease() puts the invoices back where that release reads them):
 *
 *  - logs: mail.log, laravel.log (and its daily files), stripe.log and paypal.log take the names of their new files (a
 *    small one is appended to a new file that is there already, a big one gets a name of the same family beside it);
 *  - the file cache's old folders (the settings cache, with the mail password and API tokens, at a fixed place) move
 *    into a folder named stale-<16 random hex>, which the updater's clean-up deletes later in short slices
 *    (staleCacheFolders(), App\Support\Updater\Updater::tidy());
 *  - database backups named by their time to the second get a random part (also the one the 1.2.2 updater made
 *    during an update; never the install SQL file);
 *  - members exports get a random part (the exports list follows);
 *  - invoice PDFs move into the folder named from the site's key;
 *  - installed.json no longer keeps the administrator's e-mail address;
 *  - the installer's copy of .env (installer-backups/.env.backup) gets a random part;
 *  - copies of .env the .env editor left next to .env (.env.backup.<YmdHis>) move to storage/app/env-backups, the
 *    newest 5 kept; the copies the payment-key page kept there named only by their time (.env.<YmdHis>) get a random
 *    part; .env itself loses its "everybody may read" bit when this PHP user owns it (only from a web request: the
 *    site's own PHP user);
 *  - after the site's key (APP_KEY) was changed: the invoice PDFs and the logs named from the earlier key move to the
 *    names of the current key (members' invoices keep downloading), and the cache folder of the earlier key goes.
 *
 * Run by the migration 2026_10_26_000000_hide_storage_files ("Finish update"), the one-click update's Finish and the
 * daily storage check (never for a view-only account: its database writes are rolled back). Every step is best effort
 * and safe to repeat: one that fails is logged and the others go on, and with nothing left to move a run only looks.
 *
 * Nothing here takes long: it renames (a few hundred cache folders at most), and it deletes nothing. Within a request of
 * Finish (App\Support\TimeBudget) a step starts only while there is time for it, and the folders and files of a step
 * that ran out of time stay where they are for the next run (Finish's own run after the migrations, the daily check):
 * a strict host (30 seconds, no set_time_limit) never ends Finish in the middle of this.
 */
final class StorageHider
{
    /** The newest release that reads invoice PDFs from invoices/<Y-m>/ and caches in the old folders. */
    public const LAST_RELEASE_WITHOUT_HIDDEN_NAMES = '1.2.2';

    /** The folders (in storage/framework/cache) the old cache folders move into: stale-<16 random hex>. */
    public const STALE_PREFIX = 'stale-';

    /** With a deadline, a step starts only while at least this many seconds are left. */
    private const STEP_SECONDS = 1.0;

    /** A log up to this size is appended to the new file that is there already; a bigger one is renamed beside it. */
    private const APPEND_BYTES = 8 * 1024 * 1024;

    /** @return list<string> what was moved, per step ("logs: 2") */
    public static function run(): array
    {
        return (new self())->steps();
    }

    /**
     * "Undo this update" back to a release up to LAST_RELEASE_WITHOUT_HIDDEN_NAMES, before its files return: the
     * invoice PDFs go back to invoices/<Y-m>/, where that release reads them (it would hand members an empty file),
     * and the settings cached under the hidden cache name are forgotten (that release caches in the old folders, so
     * after the next update they would be out of date). Backups, exports and logs work with it as they are. Best
     * effort; the next update hides them again.
     *
     * @return int how many invoice PDFs went back
     */
    public static function prepareForOlderRelease(): int
    {
        $hider = new self();
        $moved = 0;

        try {
            $moved = $hider->moveInvoicesBack();
        } catch (Throwable $e) {
            Log::warning('Putting the invoices back for the older version did not finish.', ['error' => $e->getMessage()]);
        }

        try {
            if (Schema::hasTable('settings')) {
                Setting::clearCache(
                    DB::table('settings')->pluck('key')->map(static fn (mixed $key): string => (string) $key)->all(),
                    DB::table('settings')->distinct()->pluck('group')->map(static fn (mixed $group): string => (string) $group)->all(),
                );
            }
        } catch (Throwable $e) {
            Log::warning('Forgetting the cached settings for the older version did not finish.', ['error' => $e->getMessage()]);
        }

        return $moved;
    }

    /**
     * The stale-<16 hex> folders the old cache folders were moved into (in $storage, default the site's storage/), for
     * the updater's clean-up (App\Support\Updater\Updater::sweepable()), which moves them into its trash and deletes
     * them in short slices. Links are never listed.
     *
     * @return list<string>
     */
    public static function staleCacheFolders(?string $storage = null): array
    {
        $cache = rtrim(str_replace('\\', '/', $storage ?? storage_path()), '/').'/framework/cache';

        return array_values(array_filter(
            glob($cache.'/'.self::STALE_PREFIX.'*', GLOB_ONLYDIR) ?: [],
            static fn (string $dir): bool => preg_match('/^'.self::STALE_PREFIX.'[0-9a-f]{16}$/', basename($dir)) === 1 && ! is_link($dir)
        ));
    }

    /** @return list<string> */
    private function steps(): array
    {
        $done = [];
        $later = [];

        foreach ([
            'logs' => fn (): int => $this->moveLogs(),
            'cache' => fn (): int => $this->retireOldCache(),
            'backups' => fn (): int => $this->renameBackups(),
            'exports' => fn (): int => $this->renameExports(),
            'invoices' => fn (): int => $this->moveInvoices(),
            'install lock' => fn (): int => $this->cleanInstallLock(),
            'installer .env copy' => fn (): int => $this->renameInstallerEnvCopy(),
            'saved .env copies' => fn (): int => $this->renameSavedEnvCopies(),
            '.env copies' => fn (): int => $this->moveRootEnvCopies(),
            '.env mode' => fn (): int => $this->tightenEnvMode(),
        ] as $step => $run) {
            // Finish's deadline: a step that would start too late waits for the next run (nothing of it is half done).
            if (TimeBudget::left() < self::STEP_SECONDS) {
                $later[] = $step;

                continue;
            }

            try {
                $count = $run();

                if ($count > 0) {
                    $done[] = $step.': '.$count;
                }
            } catch (Throwable $e) {
                Log::warning('Hiding the files under storage/ did not finish one step.', ['step' => $step, 'error' => $e->getMessage()]);
            }
        }

        if ($done !== []) {
            Log::info('Files under storage/ now have names nobody can guess.', ['moved' => $done]);
        }

        if ($later !== []) {
            Log::info('Hiding the files under storage/ goes on in a later run (the request had no time left).', ['steps' => $later]);
        }

        return $done;
    }

    /**
     * mail.log, laravel.log (+ daily files), stripe.log and paypal.log into the files the app writes now; after an
     * APP_KEY change also the files named from the earlier key (mail-<16 hex>.log ...).
     */
    private function moveLogs(): int
    {
        $moved = 0;
        $logs = storage_path('logs');

        foreach (['mail' => 'mail', 'single' => 'laravel', 'stripe' => 'stripe', 'paypal' => 'paypal'] as $channel => $prefix) {
            $target = (string) config('logging.channels.'.$channel.'.path');
            $moved += $this->appendAndRemove($logs.DIRECTORY_SEPARATOR.$prefix.'.log', $target) ? 1 : 0;

            foreach (glob($logs.DIRECTORY_SEPARATOR.$prefix.'-*.log') ?: [] as $file) {
                // An earlier key's file (the current one is skipped: the same path).
                if (preg_match('/^'.$prefix.'-[0-9a-f]{16}\.log$/', basename($file)) === 1) {
                    $moved += $this->appendAndRemove($file, $target) ? 1 : 0;
                }
            }
        }

        // The daily channel's files (laravel-2026-10-25.log, or laravel-<an earlier key's part>-2026-10-25.log) get the
        // current base name with their date.
        $single = (string) config('logging.channels.single.path');
        $base = substr(basename($single), 0, -4);

        foreach (glob($logs.DIRECTORY_SEPARATOR.'laravel-*.log') ?: [] as $file) {
            if (preg_match('/^laravel-(?:[0-9a-f]{16}-)?(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $m) === 1) {
                $moved += $this->appendAndRemove($file, dirname($single).DIRECTORY_SEPARATOR.$base.'-'.$m[1].'.log') ? 1 : 0;
            }
        }

        return $moved;
    }

    /**
     * $from into $to: renamed when $to is not there yet (instant, whatever the size); appended when it is and $from is
     * small; else (a log of years, which a request with a deadline could not copy in time) renamed beside $to under a
     * name of the same family (<$to without .log>-before-<YmdHis>.log), so it is hidden as well and Tools > Logs lists it.
     */
    private function appendAndRemove(string $from, string $to): bool
    {
        if ($to === '' || ! is_file($from) || is_link($from) || $this->samePath($from, $to)) {
            return false;
        }

        clearstatcache(true, $to);

        if (! file_exists($to) && ! is_link($to) && @rename($from, $to)) {
            return true;
        }

        if ((int) @filesize($from) > self::APPEND_BYTES) {
            return $this->renameBeside($from, $to);
        }

        $in = @fopen($from, 'rb');
        $out = @fopen($to, 'ab');

        if ($in === false || $out === false) {
            is_resource($in) && fclose($in);
            is_resource($out) && fclose($out);

            return false;
        }

        $copied = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        return $copied !== false && @unlink($from);
    }

    /** $from renamed to <$to without .log>-before-<YmdHis of its last write>[-n].log (a name nobody can guess either). */
    private function renameBeside(string $from, string $to): bool
    {
        $base = substr($to, 0, -strlen('.log'));
        $stamp = date('YmdHis', ((int) @filemtime($from)) ?: time());

        for ($n = 0; $n < 10; $n++) {
            $target = $base.'-before-'.$stamp.($n > 0 ? '-'.$n : '').'.log';
            clearstatcache(true, $target);

            if (! file_exists($target) && ! is_link($target) && @rename($from, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The file cache wrote to storage/framework/cache/data/<2 hex>/<2 hex>/<sha1> up to 1.2.2; it now writes into a
     * folder named from APP_KEY below it. The old two-level folders, and the folder of an earlier key (after an APP_KEY
     * change), move into a new storage/framework/cache/stale-<16 random hex> folder, one rename each (a few hundred at
     * most): their names are hidden at once. Nothing is deleted here: a busy 1.2.2 site keeps tens of thousands of
     * expired entries there, more than a strict host lets a request of Finish delete (a 30-second limit ended it half
     * way). The updater's clean-up deletes the stale folders later in short slices, as it does with the work files of an
     * update (Tools > Updates while it is open, the scheduler's updates:tidy every five minutes: see staleCacheFolders()).
     * With Finish's deadline near, the folders not moved yet wait for the next run (a later run makes a new stale folder).
     */
    private function retireOldCache(): int
    {
        $data = storage_path('framework/cache/data');

        if (! is_dir($data)) {
            return 0;
        }

        $current = realpath((string) config('cache.stores.file.path')) ?: '';
        $old = array_values(array_filter(
            glob($data.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [],
            static fn (string $dir): bool => preg_match('/^(?:[0-9a-f]{2}|[0-9a-f]{16})$/', basename($dir)) === 1 && ! is_link($dir) && realpath($dir) !== $current
        ));

        if ($old === []) {
            return 0;
        }

        $stale = storage_path('framework/cache/'.self::STALE_PREFIX.bin2hex(random_bytes(8)));

        if (! @mkdir($stale, 0755, true) && ! is_dir($stale)) {
            return 0;
        }

        $moved = 0;

        foreach ($old as $dir) {
            if ($moved > 0 && TimeBudget::left() < 0.5) {
                break;
            }

            if (@rename($dir, $stale.DIRECTORY_SEPARATOR.basename($dir))) {
                $moved++;
            }
        }

        if ($moved === 0) {
            @rmdir($stale);
        }

        return $moved;
    }

    /** backup_[before_update_<version>_]<Y-m-d_His>.sql[.gz] gets a random part (DatabaseBackup's names since 1.2.3). */
    private function renameBackups(): int
    {
        $renamed = 0;

        foreach (glob(storage_path('app/backups').DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            $name = basename($file);

            if (! is_file($file) || is_link($file) || preg_match('/^(backup_(?:before_update_[^_]+_)?\d{4}-\d{2}-\d{2}_\d{6})(\.sql(?:\.gz)?)$/', $name, $m) !== 1) {
                continue;
            }

            if (@rename($file, dirname($file).DIRECTORY_SEPARATOR.$m[1].'_'.DatabaseBackup::randomPart().$m[2])) {
                $renamed++;
            }
        }

        return $renamed;
    }

    /** <name>-<unix time>.csv gets a random part; the exports list follows (exports.file). */
    private function renameExports(): int
    {
        $disk = Storage::disk(config('filesystems.default'));
        $renamed = 0;
        $hasTable = Schema::hasTable('exports');

        foreach ($disk->files('exports') as $path) {
            // Finish's deadline: the rest is renamed in the next run (the exports list names each file as it is).
            if ($renamed > 0 && TimeBudget::left() < 0.5) {
                break;
            }

            $name = basename($path);

            if (preg_match('/^(.+-\d{9,11})\.csv$/', $name, $m) !== 1 || preg_match('/-[0-9a-f]{12}\.csv$/', $name) === 1) {
                continue;
            }

            $new = $m[1].'-'.bin2hex(random_bytes(6)).'.csv';

            if ($disk->exists('exports/'.$new) || ! $disk->move($path, 'exports/'.$new)) {
                continue;
            }

            if ($hasTable) {
                DB::table('exports')->where('file', $name)->update(['file' => $new]);
            }

            $renamed++;
        }

        return $renamed;
    }

    /**
     * invoices/<Y-m>/<file> (up to 1.2.2), and the folder of an earlier key (after an APP_KEY change), into
     * Transaction::invoiceFolder()/<Y-m>/<file> on the default disk.
     */
    private function moveInvoices(): int
    {
        $disk = Storage::disk(config('filesystems.default'));
        $moved = 0;

        foreach ([Transaction::INVOICE_FOLDER, ...Transaction::earlierInvoiceFolders()] as $from) {
            $moved += $this->moveFolder($disk, $from, Transaction::invoiceFolder());
        }

        return $moved;
    }

    /** The invoice PDFs from the folders named from the site's key (now and before) back to invoices/<Y-m>/ (an Undo). */
    private function moveInvoicesBack(): int
    {
        $disk = Storage::disk(config('filesystems.default'));
        $moved = 0;

        foreach ([Transaction::invoiceFolder(), ...Transaction::earlierInvoiceFolders()] as $from) {
            $moved += $this->moveFolder($disk, $from, Transaction::INVOICE_FOLDER);
        }

        return $moved;
    }

    /**
     * Every file of $from to the same place below $to (a file already there wins); $from goes once it is empty. With
     * Finish's deadline near, the rest moves in the next run: Transaction::invoicePath() finds a PDF in either folder.
     */
    private function moveFolder(FilesystemAdapter $disk, string $from, string $to): int
    {
        if ($from === $to || ! $disk->directoryExists($from)) {
            return 0;
        }

        $moved = 0;

        foreach ($disk->allFiles($from) as $path) {
            if ($moved > 0 && TimeBudget::left() < 0.5) {
                return $moved;
            }

            $target = $to.substr($path, strlen($from));

            if (! $disk->exists($target) && $disk->move($path, $target)) {
                $moved++;
            }
        }

        if ($disk->allFiles($from) === []) {
            $disk->deleteDirectory($from);
        }

        return $moved;
    }

    /** installed.json (the install lock) without the administrator's e-mail address. */
    private function cleanInstallLock(): int
    {
        $lock = storage_path('app/installed.json');
        $data = is_file($lock) ? json_decode((string) @file_get_contents($lock), true) : null;

        if (! is_array($data) || ! array_key_exists('admin_email', $data)) {
            return 0;
        }

        unset($data['admin_email']);

        return @file_put_contents($lock, json_encode($data, JSON_PRETTY_PRINT)) !== false ? 1 : 0;
    }

    /** storage/app/installer-backups/.env.backup (1.2.2's fixed name) gets a random part. */
    private function renameInstallerEnvCopy(): int
    {
        $copy = storage_path('app/installer-backups/.env.backup');

        if (! is_file($copy) || is_link($copy)) {
            return 0;
        }

        $target = $copy.'.'.bin2hex(random_bytes(8));

        if (! @rename($copy, $target)) {
            return 0;
        }

        @chmod($target, 0600);

        return 1;
    }

    /**
     * storage/app/env-backups/.env.<YmdHis>: the payment-key page kept whole copies of .env there up to 1.2.2, named only
     * by their time (guessable to the second). They get the random part of EnvBackups' names (the time still sorts
     * first); the newest 5 stay.
     */
    private function renameSavedEnvCopies(): int
    {
        $renamed = 0;

        foreach (glob(EnvBackups::directory().DIRECTORY_SEPARATOR.'.env.*') ?: [] as $file) {
            if (! is_file($file) || is_link($file) || preg_match('/^\.env\.\d{14}$/', basename($file)) !== 1) {
                continue;
            }

            $target = $file.'.'.bin2hex(random_bytes(8));

            if (@rename($file, $target)) {
                @chmod($target, 0600);
                $renamed++;
            }
        }

        if ($renamed > 0) {
            @chmod(EnvBackups::directory(), 0700);
            EnvBackups::prune();
        }

        return $renamed;
    }

    /** .env.backup.<YmdHis> next to .env (the .env editor up to 1.2.2) into storage/app/env-backups, newest 5 kept. */
    private function moveRootEnvCopies(): int
    {
        $folder = dirname(app()->environmentFilePath());
        $moved = 0;

        foreach (glob($folder.DIRECTORY_SEPARATOR.'.env.backup.*') ?: [] as $file) {
            if (! is_file($file) || is_link($file) || preg_match('/^\.env\.backup\.(\d{14})$/', basename($file), $m) !== 1) {
                continue;
            }

            $content = @file_get_contents($file);

            if (is_string($content) && EnvBackups::store($content, $m[1]) !== null && @unlink($file)) {
                $moved++;
            }
        }

        if ($moved > 0) {
            EnvBackups::prune();
        }

        return $moved;
    }

    /**
     * .env at most 0640, only from a web request ("Finish update"): written by its owner only, read by the group, nothing
     * for everybody else. 1.2.2 left 0664 (every account could read it) and RC1 0660, where the web server reads the
     * site through its group and could rewrite it (R1B-016): the group's write bit and every bit of the others go, a
     * stricter mode the owner chose (0600, 0400) stays as it is. Then this PHP user is the site's own, and when it owns
     * the file it keeps reading it. From the console (php artisan migrate as another user) the mode stays: the site's
     * PHP user might read it as "others".
     */
    private function tightenEnvMode(): int
    {
        $env = app()->environmentFilePath();

        if (app()->runningInConsole() || ! is_file($env) || is_link($env) || PHP_OS_FAMILY === 'Windows') {
            return 0;
        }

        $mode = @fileperms($env);

        if (! is_int($mode) || ($mode & 0137) === 0) {
            return 0;
        }

        if (function_exists('posix_geteuid') && @fileowner($env) !== posix_geteuid()) {
            return 0;
        }

        return @chmod($env, $mode & 0640) ? 1 : 0;
    }

    private function samePath(string $a, string $b): bool
    {
        $a = realpath($a) ?: $a;
        $b = realpath($b) ?: $b;

        return strtolower(str_replace('\\', '/', $a)) === strtolower(str_replace('\\', '/', $b));
    }
}
