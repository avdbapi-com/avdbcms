<?php

namespace Tests\Feature\Updater\Support;

use App\Models\User;
use App\Support\AppVersion;
use App\Support\Updater\Downloader;
use App\Support\Updater\PlanStore;
use App\Support\Updater\ReleaseCheck;
use App\Support\Updater\TranslationMerge;
use App\Support\Updater\Updater;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * The site and the release of the updater's feature tests, and the moves of the Updates page. makeSite() builds a
 * throwaway site root with AppVersion::CURRENT installed (its avdbcms-manifest.json, a few code files and the site
 * data no release ships), the update zip of the next version (CURRENT + 1 patch, whatever CURRENT is), GitHub as a
 * ReleaseServer and a faked database dump. The driving helpers walk the steps the way the page's script does: start,
 * step until the answer says something else, the swap POSTs through the generated maintenance hook (as
 * public/index.php hands them over), finish on "the new code" (an Updater playing the next version).
 *
 * The test class uses RefreshDatabase (the admin, the audit table, the pending-migration check), calls makeSite() in
 * setUp() and removeSite() in tearDown().
 */
trait UpdaterScenario
{
    protected UpdaterSandbox $box;

    protected FakeDatabaseBackup $database;

    protected ReleaseServer $github;

    protected User $admin;

    /** The release the tests install: CURRENT + 1 patch. */
    protected string $next;

    /** @var array<string, string> the site before the update: path => SHA-256, without storage/ */
    protected array $before = [];

    /**
     * Site data no release ships: never written, deleted or backed up by an update.
     *
     * @return array<string, string>
     */
    protected static function siteData(): array
    {
        return [
            '.env' => "APP_KEY=base64:dGVzdCBrZXkgb2YgdGhlIHVwZGF0ZXIgdGVzdHM=\nDB_PASSWORD=not-a-real-one\n",
            '.user.ini' => "memory_limit=512M\n",
            'storage/app/installed.json' => '{"installed_at":"2026-10-23T12:00:00Z"}',
            'storage/app/public/x.jpg' => 'jpeg bytes',
            'public/uploads/x' => 'an upload',
            'bin/ffmpeg' => 'ffmpeg binary',
        ];
    }

    /** @return array<string, string> the files of the installed version (CURRENT) */
    protected static function shippedFiles(): array
    {
        return [
            '.htaccess' => "Options -Indexes\nRewriteEngine On\n",
            'public/.htaccess' => "RewriteEngine On\nRewriteRule ^ index.php [L]\n",
            'public/index.php' => "<?php // public/index.php 1\n",
            'index.php' => "<?php // index.php 1\n",
            'artisan' => "#!/usr/bin/env php\n<?php // artisan 1\n",
            'app/Models/User.php' => "<?php // User 1\n",
            'app/Support/Old.php' => "<?php // Old 1\n",
            'config/app.php' => "<?php return ['v' => 1];\n",
            'routes/web.php' => "<?php // routes 1\n",
            'vendor/autoload.php' => "<?php // autoload 1\n",
            'public/build/manifest.json' => '{"app.js":"assets/app-111.js"}',
            'public/build/assets/app-111.js' => "js 1\n",
            'lang/en.json' => TranslationMerge::encode(['Hello' => 'Hello']),
            'resources/views/home.blade.php' => "home 1\n",
        ];
    }

    /** @return array<string, string> the files of the release (the next version) */
    protected static function releaseFiles(): array
    {
        $shipped = self::shippedFiles();

        return [
            '.htaccess' => $shipped['.htaccess'],
            'public/.htaccess' => $shipped['public/.htaccess'],
            'public/index.php' => "<?php // public/index.php 2\n",
            'index.php' => $shipped['index.php'],
            'artisan' => $shipped['artisan'],
            'app/Models/User.php' => "<?php // User 2\n",
            'app/Support/New.php' => "<?php // New 2\n",
            'config/app.php' => "<?php return ['v' => 2];\n",
            'routes/web.php' => $shipped['routes/web.php'],
            'vendor/autoload.php' => "<?php // autoload 2\n",
            'public/build/manifest.json' => '{"app.js":"assets/app-222.js"}',
            'public/build/assets/app-222.js' => "js 2\n",
            'lang/en.json' => TranslationMerge::encode(['Hello' => 'Hello', 'New' => 'New']),
            'resources/views/home.blade.php' => "home 2\n",
        ];
    }

    /**
     * The site, the release, GitHub, the database dump and the administrator.
     *
     * @param  int  $blob  bytes of an extra incompressible file in the release (a zip big enough for several slices)
     * @param  array{requires_php?: string, min_from?: string}  $manifest  the release manifest's requirements
     */
    protected function makeSite(int $blob = 0, array $manifest = []): void
    {
        config(['admin.operational_tools_enabled' => true, 'app.url' => 'http://localhost']);
        $this->box = new UpdaterSandbox();
        $this->next = UpdaterSandbox::nextVersion();

        // Finish runs `view:clear`: keep it away from the copy's own compiled views.
        File::ensureDirectoryExists($this->box->path('views'));
        config(['view.compiled' => $this->box->path('views')]);

        $this->box->install(AppVersion::CURRENT, self::shippedFiles(), self::siteData());
        $this->before = $this->box->snapshot();

        $files = self::releaseFiles();

        if ($blob > 0) {
            $files['resources/blob.bin'] = self::noise($blob);
        }

        $removed = ['app/Support/Old.php'];
        $zip = $this->box->releaseZip($this->next, $files, $removed, UpdaterSandbox::manifest(
            $this->next, $files, $removed, $manifest['requires_php'] ?? '8.3.0', $manifest['min_from'] ?? AppVersion::CURRENT,
        ));

        $this->github = (new ReleaseServer($this->next, $zip))->install();
        $this->database = new FakeDatabaseBackup($this->box->root.'/storage/app/backups');
        $this->admin = User::factory()->admin()->create(['username' => 'owner']);
    }

    protected function removeSite(): void
    {
        unset($GLOBALS['avdbcms_update_hook_probe']);

        if (isset($this->box)) {
            $this->box->delete();
        }
    }

    /** An Updater on the site root; $version is the AVDBCMS version the code plays (CURRENT: the old code). */
    protected function updater(?string $version = null): Updater
    {
        return new Updater($this->box->paths(), new ReleaseCheck(), $this->database, new Downloader(), $version ?? AppVersion::CURRENT);
    }

    /** Another administrator (for the lock tests). */
    protected function otherAdmin(string $name = 'second'): User
    {
        return User::factory()->admin()->create(['username' => $name]);
    }

    /**
     * Steps of the running update until $until says so (each answer has the keys of the contract).
     *
     * @param  callable(array): bool  $until
     */
    protected function stepUntil(Updater $updater, callable $until, ?User $admin = null): array
    {
        for ($i = 0; $i < 40; $i++) {
            $answer = $updater->step($admin ?? $this->admin);

            foreach (['ok', 'status', 'next', 'label', 'progress', 'message'] as $key) {
                $this->assertArrayHasKey($key, $answer);
            }

            if ($until($answer)) {
                return $answer;
            }

            $this->assertSame('step', $answer['next'], 'a step that is not the end goes on: '.json_encode($answer));
        }

        $this->fail('The update did not get there in 40 steps.');
    }

    /**
     * Start + steps until the swap is ready: the answer that carries the swap token, plus the update cookie the start
     * handed out (it never comes with the answer that takes the site down: that one may be lost).
     */
    protected function driveToSwap(?Updater $updater = null): array
    {
        $updater ??= $this->updater();
        $start = $updater->start($this->admin);
        $this->assertTrue($start['ok'], (string) $start['message']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $start['cookie'] ?? '', 'the start hands out the update cookie');

        $ready = $this->stepUntil($updater, fn (array $answer): bool => $answer['next'] !== 'step');

        $this->assertTrue($ready['ok'], (string) $ready['message']);
        $this->assertSame('swap', $ready['next']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ready['token']);
        $this->assertArrayNotHasKey('cookie', $ready, 'the answer that takes the site down carries no cookie');
        $this->assertNotSame($ready['token'], $start['cookie']);

        return $ready + ['cookie' => $start['cookie']];
    }

    /**
     * Undo this update (on the code of $updater) until its swap is ready: the undo hands out the cookie, its steps
     * compare the files, the last one carries the swap token.
     */
    protected function undoToSwap(Updater $updater): array
    {
        $undo = $updater->undo($this->admin);
        $this->assertTrue($undo['ok'], (string) $undo['message']);
        $this->assertSame(['preparing', 'step'], [$undo['status'], $undo['next']]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $undo['cookie'] ?? '', 'the undo hands out the update cookie');

        $ready = $this->stepUntil($updater, fn (array $answer): bool => $answer['next'] !== 'step');

        $this->assertTrue($ready['ok'], (string) $ready['message']);
        $this->assertSame('swap', $ready['next']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ready['token']);
        $this->assertArrayNotHasKey('cookie', $ready);

        return $ready + ['cookie' => $undo['cookie']];
    }

    /** The generated maintenance hook, loaded the way public/index.php loads it (without answering). */
    protected function loadHook(): object
    {
        $this->assertFileExists($this->box->paths()->hook(), 'the maintenance hook is in place');
        $GLOBALS['avdbcms_update_hook_probe'] = true;

        try {
            return include $this->box->paths()->hook();
        } finally {
            unset($GLOBALS['avdbcms_update_hook_probe']);
        }
    }

    /**
     * One request through the hook ($server like $_SERVER, $post like $_POST); null when the hook let it through.
     *
     * @return array{status: int, headers: array<string, string>, body: string, json: ?array}|null
     */
    protected function hookRequest(array $server, array $post = [], array $cookies = []): ?array
    {
        $answer = $this->loadHook()->handle($server + ['HTTP_HOST' => 'localhost'], $post, $cookies);

        if ($answer === null) {
            return null;
        }

        $json = str_starts_with((string) ($answer['headers']['Content-Type'] ?? ''), 'application/json') ? json_decode($answer['body'], true) : null;

        return $answer + ['json' => is_array($json) ? $json : null];
    }

    /** The Updates page's swap POSTs (same origin, the token) until the swap answers something else than "swap". */
    protected function swapThroughHook(string $token): array
    {
        for ($i = 0; $i < 20; $i++) {
            $answer = $this->hookRequest(
                ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json'],
                ['avdbcms_update' => 'swap', 'token' => $token],
            );
            $this->assertNotNull($answer['json'] ?? null, 'the swap answers JSON');

            if (($answer['json']['next'] ?? null) !== 'swap') {
                return $answer['json'];
            }
        }

        $this->fail('The swap did not end in 20 requests.');
    }

    /** Finish (the database step) until it answers something else than "finish". */
    protected function finishOn(Updater $updater): array
    {
        for ($i = 0; $i < 10; $i++) {
            $answer = $updater->finish($this->admin);

            if ($answer['next'] !== 'finish') {
                return $answer;
            }
        }

        $this->fail('Finish did not end in 10 requests.');
    }

    /** The whole update to the next version, as an administrator clicks it through. */
    protected function updateToNext(): array
    {
        $ready = $this->driveToSwap();
        $this->assertSame('swapped', $this->swapThroughHook($ready['token'])['step']);
        $done = $this->finishOn($this->updater($this->next));
        $this->assertSame('done', $done['status'], (string) $done['message']);

        return $ready;
    }

    protected function storedPlan(): ?array
    {
        return PlanStore::read($this->box->paths()->plan());
    }

    /** Change plan.json the way time or another request would. */
    protected function changePlan(array $changes): void
    {
        PlanStore::write($this->box->paths()->plan(), array_replace((array) $this->storedPlan(), $changes));
    }

    /** The work folder of the update to the next version. */
    protected function jobFolder(): string
    {
        return $this->box->paths()->job($this->next);
    }

    /** The update zip in the work folder. */
    protected function zipFile(): string
    {
        return $this->jobFolder().'/'.$this->github->zipName();
    }

    /**
     * Take the update lock the way another request's step holds it (a handle of its own).
     *
     * @return resource
     */
    protected function holdUpdateLock()
    {
        $handle = (new PlanStore($this->box->paths()))->lock();
        $this->assertIsResource($handle, 'the test could take the update lock');

        return $handle;
    }

    /** @param  resource  $handle */
    protected function releaseUpdateLock($handle): void
    {
        (new PlanStore($this->box->paths()))->unlock($handle);
    }

    /** @return list<array{action: string, details: ?string, user_id: ?int}> the update.* rows of the admin log, oldest first */
    protected function auditRows(): array
    {
        return DB::table('admin_audit_logs')->where('action', 'like', 'update.%')->orderBy('id')->get(['action', 'details', 'user_id'])
            ->map(fn (object $row): array => ['action' => (string) $row->action, 'details' => $row->details, 'user_id' => $row->user_id === null ? null : (int) $row->user_id])
            ->all();
    }

    /** @return list<string> the files left in the work folder of the update (names) */
    protected function jobFiles(): array
    {
        $folder = $this->jobFolder();

        return is_dir($folder) ? array_values(array_diff(scandir($folder) ?: [], ['.', '..'])) : [];
    }

    /** Nothing live changed: every file outside storage/ as before, no maintenance files, the site data as it was. */
    protected function assertSiteUnchanged(string $why = ''): void
    {
        $this->assertSame($this->before, $this->box->snapshot(), trim('the site tree changed '.$why));
        $this->assertFileDoesNotExist($this->box->paths()->hook(), 'no maintenance hook');
        $this->assertFileDoesNotExist($this->box->paths()->down(), 'no Laravel down file');

        foreach (self::siteData() as $path => $content) {
            $this->assertSame($content, $this->box->read($path), $path);
        }
    }

    /**
     * Make a folder one this PHP may not create files in (nor replace one by a rename): mode 0555 on Linux and macOS,
     * a deny entry for Everyone (SID S-1-1-0, the same in every language of Windows, on the folder only: its subfolders
     * stay writable) on Windows. Skipped where that cannot stop this PHP (running as root).
     */
    protected function denyWrites(string $folder): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $deny = new Process(['icacls', str_replace('/', '\\', $folder), '/deny', '*S-1-1-0:(W)']);
            $deny->run();

            if (! $deny->isSuccessful()) {
                $this->markTestSkipped('icacls could not take the write permission away: '.$deny->getErrorOutput().$deny->getOutput());
            }
        } else {
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                $this->markTestSkipped('Running as root: folder permissions do not stop this PHP from writing.');
            }

            chmod($folder, 0555);
        }

        clearstatcache();

        if (@file_put_contents($folder.'/.probe-of-the-test', 'x') !== false) {
            @unlink($folder.'/.probe-of-the-test');
            $this->allowWrites($folder);
            $this->markTestSkipped('This system lets PHP write into '.basename($folder).' anyway.');
        }
    }

    protected function allowWrites(string $folder): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            (new Process(['icacls', str_replace('/', '\\', $folder), '/remove:d', '*S-1-1-0']))->run();
        } else {
            @chmod($folder, 0755);
        }

        clearstatcache();
    }

    /** Incompressible bytes, the same in every run (a zip that needs several download slices). */
    protected static function noise(int $bytes): string
    {
        $out = '';
        $block = 'avdbcms-updater-tests';

        while (strlen($out) < $bytes) {
            $block = hash('sha256', $block, true);
            $out .= $block;
        }

        return substr($out, 0, $bytes);
    }
}
