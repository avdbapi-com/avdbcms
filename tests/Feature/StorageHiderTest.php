<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Support\EnvBackups;
use App\Support\StorageHider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Security plan S03 part 4 + S24 part 4: what an earlier release left under storage/ under a guessable name is moved
 * once ("Finish update"): the logs, the old file-cache folders, database backups, exports (the list follows), invoice
 * PDFs, the install lock's e-mail, the installer's and the .env editor's copies of .env. A second run changes nothing,
 * and Tools > Backups, the export download and an invoice download keep working. Runs on a storage folder and a .env of
 * the test's own.
 */
class StorageHiderTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = str_replace('\\', '/', storage_path('framework/testing/hider-'.uniqid()));
        $this->storage = $this->root.'/storage';

        foreach (['logs', 'framework/cache/data', 'app/backups', 'app/private/exports', 'app/private/invoices/2026-09', 'app/installer-backups'] as $dir) {
            File::ensureDirectoryExists($this->storage.'/'.$dir);
        }

        file_put_contents($this->root.'/.env', "APP_NAME=Hider\n");
        $real = [
            'mail' => basename((string) config('logging.channels.mail.path')),
            'single' => basename((string) config('logging.channels.single.path')),
            'stripe' => basename((string) config('logging.channels.stripe.path')),
            'paypal' => basename((string) config('logging.channels.paypal.path')),
            'cache' => basename((string) config('cache.stores.file.path')),
        ];
        $this->app->useEnvironmentPath($this->root);
        $this->app->useStoragePath($this->storage);
        config([
            'logging.channels.mail.path' => $this->storage.'/logs/'.$real['mail'],
            'logging.channels.single.path' => $this->storage.'/logs/'.$real['single'],
            'logging.channels.stripe.path' => $this->storage.'/logs/'.$real['stripe'],
            'logging.channels.paypal.path' => $this->storage.'/logs/'.$real['paypal'],
            'cache.stores.file.path' => $this->storage.'/framework/cache/data/'.$real['cache'],
            'filesystems.disks.local.root' => $this->storage.'/app/private',
        ]);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_the_files_an_earlier_release_left_get_names_nobody_can_guess(): void
    {
        $this->earlierReleaseFiles();
        $exportId = DB::table('exports')->insertGetId(['status' => \App\Enums\ExportStatus::COMPLETED->value, 'file' => 'users-1729851234.csv', 'size' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $transaction = $this->transaction();

        $done = StorageHider::run();

        $this->assertNotSame([], $done);

        // Logs: appended to the new files, the old ones gone.
        foreach (['mail.log', 'laravel.log', 'stripe.log', 'paypal.log', 'laravel-2026-10-20.log'] as $old) {
            $this->assertFileDoesNotExist($this->storage.'/logs/'.$old);
        }
        $this->assertStringContainsString("old mail line\n", (string) file_get_contents(config('logging.channels.mail.path')));
        $this->assertStringContainsString("old error line\n", (string) file_get_contents(config('logging.channels.single.path')));
        $this->assertStringContainsString("old stripe line\n", (string) file_get_contents(config('logging.channels.stripe.path')));
        $this->assertStringContainsString("old paypal line\n", (string) file_get_contents(config('logging.channels.paypal.path')));
        $daily = substr((string) config('logging.channels.single.path'), 0, -4).'-2026-10-20.log';
        $this->assertSame("old daily line\n", file_get_contents($daily));

        // The old cache folders left data/ for one folder with a name nobody can guess, which the updater's clean-up
        // deletes later in slices (nothing is deleted here: a busy 1.2.2 site has tens of thousands of entries there);
        // the current one stays.
        $this->assertDirectoryDoesNotExist($this->storage.'/framework/cache/data/ab');
        $this->assertFileExists(config('cache.stores.file.path').'/keep.txt');
        $stale = StorageHider::staleCacheFolders($this->storage);
        $this->assertCount(1, $stale);
        $this->assertMatchesRegularExpression('/^stale-[0-9a-f]{16}$/', basename($stale[0]));
        $this->assertSame('cached settings with a mail password', file_get_contents($stale[0].'/ab/cd/abcd1234'));

        // Backups: a random part; the install SQL file and a file the owner put there keep their names.
        $backups = array_map('basename', glob($this->storage.'/app/backups/*') ?: []);
        $this->assertCount(1, preg_grep('/^backup_2026-10-01_101010_[0-9a-f]{12}\.sql\.gz$/', $backups));
        $this->assertCount(1, preg_grep('/^backup_before_update_1\.2\.3_2026-10-25_143000_[0-9a-f]{12}\.sql$/', $backups));
        $this->assertContains('example.sql', $backups);
        $this->assertContains('my-own-dump.sql', $backups);

        // Exports: a random part, the list follows, the download still works.
        $file = (string) DB::table('exports')->where('id', $exportId)->value('file');
        $this->assertMatchesRegularExpression('/^users-1729851234-[0-9a-f]{12}\.csv$/', $file);
        $this->assertFileExists($this->storage.'/app/private/exports/'.$file);
        $admin = User::factory()->admin()->create();
        config(['admin.operational_tools_enabled' => true]);
        $download = $this->actingAs($admin)->get(route('admin.exports.download', $exportId));
        $download->assertOk();
        $this->assertSame("a,b\n1,2\n", $download->streamedContent());

        // Invoices: in the folder named from the site's key; the download and the mail attachment read it there.
        $this->assertDirectoryDoesNotExist($this->storage.'/app/private/invoices');
        $this->assertSame(Transaction::invoiceFolder().'/2026-09/'.$transaction->invoice_name, $transaction->fresh()->invoice_path);
        $this->assertFileExists($this->storage.'/app/private/'.$transaction->fresh()->invoice_path);
        $this->actingAs($transaction->user)->get(route('user.invoices.show', $transaction))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $attachment = \Illuminate\Mail\Attachment::fromStorage($transaction->fresh()->invoice_url);
        $this->assertNotNull($attachment);

        // The install lock without the e-mail address.
        $lock = json_decode((string) file_get_contents($this->storage.'/app/installed.json'), true);
        $this->assertArrayNotHasKey('admin_email', $lock);
        $this->assertSame('https://victim-site.example', $lock['app_url']);

        // Copies of .env: the installer's with a random part, the editor's moved to env-backups (with their own time).
        $this->assertFileDoesNotExist($this->storage.'/app/installer-backups/.env.backup');
        $this->assertCount(1, glob($this->storage.'/app/installer-backups/.env.backup.*') ?: []);
        $this->assertSame([], glob($this->root.'/.env.backup.*') ?: []);
        $this->assertSame(['20260901101010', '20260801101010'], array_map(fn (string $name): string => substr($name, 5, 14), EnvBackups::names()));

        // A second run finds nothing to move.
        $this->assertSame([], StorageHider::run());
        $this->assertSame($file, DB::table('exports')->where('id', $exportId)->value('file'));
    }

    /**
     * "Undo this update" back to a release up to 1.2.2: that release reads invoice PDFs from invoices/<Y-m>/ (it would
     * hand members an empty file) and keeps its settings cache in the old folders (the settings cached under the
     * hidden name would be out of date after the next update). Both are put right before its files come back.
     */
    public function test_an_undo_to_an_older_release_puts_the_invoices_back_and_forgets_cached_settings(): void
    {
        $transaction = $this->transaction();
        StorageHider::run();
        $hidden = Transaction::invoiceFolder().'/2026-09/'.$transaction->invoice_name;
        $this->assertSame($hidden, $transaction->fresh()->invoice_path);

        \App\Models\Setting::set('smtp_password', 'set-before-the-undo', 'email');
        \App\Models\Setting::getGroup('email');
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has('settings.email'));

        $this->assertSame(1, StorageHider::prepareForOlderRelease());

        $this->assertFileExists($this->storage.'/app/private/invoices/2026-09/'.$transaction->invoice_name, 'where 1.2.2 reads it');
        $this->assertDirectoryDoesNotExist($this->storage.'/app/private/'.Transaction::invoiceFolder());
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('settings.email'), 'no settings from before the undo after the next update');
        $this->assertSame('invoices/2026-09/'.$transaction->invoice_name, $transaction->fresh()->invoice_path, 'this version reads it there too');
        $this->actingAs($transaction->user)->get(route('user.invoices.show', $transaction))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame(0, StorageHider::prepareForOlderRelease(), 'nothing left to move');

        // The next update hides it again.
        StorageHider::run();
        $this->assertSame($hidden, $transaction->fresh()->invoice_path);
        $this->assertFileExists($this->storage.'/app/private/'.$hidden);
    }

    /**
     * The payment-key page kept whole copies of .env in storage/app/env-backups up to 1.2.2, named only by their time
     * (.env.<YmdHis>, guessable to the second): they get the random part of today's names, the newest 5 stay, and copies
     * already named that way are left alone.
     */
    public function test_saved_env_copies_named_only_by_their_time_get_a_random_part(): void
    {
        File::ensureDirectoryExists(EnvBackups::directory());
        foreach (['20260101000000', '20260201000000', '20260301000000', '20260401000000', '20260501000000', '20260601000000'] as $stamp) {
            file_put_contents(EnvBackups::directory().'/.env.'.$stamp, 'APP_KEY='.$stamp."\n");
        }
        // A copy made by this release (EnvBackups' name) stays as it is.
        $kept = '.env.20260701000000.0123456789abcdef';
        file_put_contents(EnvBackups::directory().'/'.$kept, "APP_KEY=already-hidden\n");

        $this->assertContains('saved .env copies: 6', StorageHider::run());

        $names = EnvBackups::names();
        $this->assertCount(EnvBackups::KEEP, $names, 'the newest five stay');
        $this->assertSame($kept, $names[0]);
        $this->assertSame([], preg_grep('/^\.env\.\d{14}$/', $names), 'no copy is named only by its time any more');
        foreach (['20260601000000', '20260501000000', '20260401000000', '20260301000000'] as $i => $stamp) {
            $this->assertMatchesRegularExpression('/^\.env\.'.$stamp.'\.[0-9a-f]{16}$/', $names[$i + 1]);
            $this->assertSame('APP_KEY='.$stamp."\n", file_get_contents(EnvBackups::directory().'/'.$names[$i + 1]));
        }
        $this->assertSame([], glob(EnvBackups::directory().'/.env.2026010*') ?: [], 'the oldest two went');
        $this->assertSame([], glob(EnvBackups::directory().'/.env.2026020*') ?: []);

        $this->assertSame([], StorageHider::run(), 'a second run finds nothing to rename');
    }

    /**
     * After APP_KEY was changed, the invoice PDFs, the logs and the cache folder named from the earlier key follow the
     * new key: an invoice still downloads at once (it is found in the earlier key's folder) and moves on the next run.
     */
    public function test_after_a_key_change_the_files_named_from_the_earlier_key_follow(): void
    {
        $transaction = $this->transaction();
        StorageHider::run();
        $earlierFolder = Transaction::invoiceFolder();
        $earlierLogs = [
            'mail' => config('logging.channels.mail.path'),
            'single' => config('logging.channels.single.path'),
            'stripe' => config('logging.channels.stripe.path'),
            'paypal' => config('logging.channels.paypal.path'),
        ];
        foreach ($earlierLogs as $channel => $path) {
            file_put_contents($path, "line before the key change ({$channel})\n");
        }
        $earlierDaily = substr((string) $earlierLogs['single'], 0, -4).'-2026-10-20.log';
        file_put_contents($earlierDaily, "daily line before the key change\n");
        $earlierCache = (string) config('cache.stores.file.path');
        File::ensureDirectoryExists($earlierCache.'/ab/cd');
        file_put_contents($earlierCache.'/ab/cd/settings', 'cached settings with a mail password');

        // The owner changes the key (php artisan key:generate): every name derived from it changes.
        $key = 'base64:'.base64_encode(random_bytes(32));
        $part = fn (string $what): string => substr(hash('sha256', $what.'|'.$key), 0, 16);
        config([
            'app.key' => $key,
            'logging.channels.mail.path' => $this->storage.'/logs/mail-'.$part('mail-log').'.log',
            'logging.channels.single.path' => $this->storage.'/logs/laravel-'.$part('laravel-log').'.log',
            'logging.channels.stripe.path' => $this->storage.'/logs/stripe-'.$part('stripe-log').'.log',
            'logging.channels.paypal.path' => $this->storage.'/logs/paypal-'.$part('paypal-log').'.log',
            'cache.stores.file.path' => $this->storage.'/framework/cache/data/'.$part('cache'),
        ]);
        $this->assertNotSame($earlierFolder, Transaction::invoiceFolder());
        $this->assertSame([$earlierFolder], Transaction::earlierInvoiceFolders());

        // Before anything moves, the invoice is found where it is: the download and the mail attachment work.
        $this->assertSame($earlierFolder.'/2026-09/'.$transaction->invoice_name, $transaction->fresh()->invoice_path);
        $this->actingAs($transaction->user)->get(route('user.invoices.show', $transaction))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $done = StorageHider::run();
        $this->assertContains('invoices: 1', $done);

        $this->assertSame(Transaction::invoiceFolder().'/2026-09/'.$transaction->invoice_name, $transaction->fresh()->invoice_path);
        $this->assertFileExists($this->storage.'/app/private/'.$transaction->fresh()->invoice_path);
        $this->assertDirectoryDoesNotExist($this->storage.'/app/private/'.$earlierFolder);
        $this->actingAs($transaction->user)->get(route('user.invoices.show', $transaction))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        foreach ($earlierLogs as $channel => $path) {
            $this->assertFileDoesNotExist($path);
            $this->assertStringContainsString("line before the key change ({$channel})\n", (string) file_get_contents(config('logging.channels.'.$channel.'.path')));
        }
        $this->assertFileDoesNotExist($earlierDaily);
        $this->assertSame("daily line before the key change\n", file_get_contents(substr((string) config('logging.channels.single.path'), 0, -4).'-2026-10-20.log'));
        $this->assertDirectoryDoesNotExist($earlierCache, 'the cached settings of the earlier key are gone');

        $this->assertSame([], StorageHider::run(), 'a second run finds nothing to move');

        // An Undo to a release up to 1.2.2 also brings back an invoice still in an earlier key's folder.
        Storage::disk('local')->put($earlierFolder.'/2026-08/Invoice99-2026-08-01.pdf', "%PDF-1.4\n");
        $this->assertSame(2, StorageHider::prepareForOlderRelease());
        $this->assertFileExists($this->storage.'/app/private/invoices/2026-08/Invoice99-2026-08-01.pdf');
        $this->assertFileExists($this->storage.'/app/private/invoices/2026-09/'.$transaction->invoice_name);
        $this->assertSame([], Transaction::earlierInvoiceFolders());
    }

    /**
     * Round 1 of 1.2.3 (R1-002): a busy 1.2.2 site keeps tens of thousands of expired entries in the old cache folders,
     * and deleting them took a strict host's whole time limit inside Finish. They are only renamed now, in one quick run
     * (a rename per folder), and every file is still there for the clean-up slices.
     */
    public function test_a_busy_old_cache_is_only_renamed(): void
    {
        $data = $this->storage.'/framework/cache/data';
        File::ensureDirectoryExists(config('cache.stores.file.path'));
        file_put_contents(config('cache.stores.file.path').'/keep.txt', 'current');
        $files = 0;

        for ($a = 0; $a < 64; $a++) {
            for ($b = 0; $b < 4; $b++) {
                $folder = sprintf('%s/%02x/%02x', $data, $a, $b);
                File::ensureDirectoryExists($folder);

                for ($i = 0; $i < 12; $i++) {
                    file_put_contents($folder.'/'.sha1($a.'-'.$b.'-'.$i), '1761000000s:7:"expired";');
                    $files++;
                }
            }
        }

        // The folder of an earlier key (after an APP_KEY change) goes the same way.
        File::ensureDirectoryExists($data.'/aaaabbbbccccdddd/ef/01');
        file_put_contents($data.'/aaaabbbbccccdddd/ef/01/settings', 'cached settings of the earlier key');
        $files++;

        $at = microtime(true);
        $done = StorageHider::run();
        $took = microtime(true) - $at;

        $this->assertContains('cache: 65', $done);
        $this->assertLessThan(10.0, $took, 'renames only');
        $this->assertSame([basename((string) config('cache.stores.file.path'))], array_map('basename', glob($data.'/*', GLOB_ONLYDIR) ?: []));
        $stale = StorageHider::staleCacheFolders($this->storage);
        $this->assertCount(1, $stale);
        $this->assertSame($files, count(File::allFiles($stale[0])), 'nothing deleted: the clean-up slices do that');
        $this->assertSame([], StorageHider::run(), 'a second run finds nothing to move');
    }

    /** Within a request of Finish whose deadline came (TimeBudget), nothing starts: the next run does it. */
    public function test_with_no_time_left_in_the_request_the_steps_wait_for_the_next_run(): void
    {
        $this->earlierReleaseFiles();

        \App\Support\TimeBudget::until(microtime(true) - 1.0);

        try {
            $this->assertSame([], StorageHider::run());
        } finally {
            \App\Support\TimeBudget::clear();
        }

        $this->assertFileExists($this->storage.'/logs/mail.log');
        $this->assertDirectoryExists($this->storage.'/framework/cache/data/ab');
        $this->assertFileExists($this->storage.'/app/installer-backups/.env.backup');

        $this->assertNotSame([], StorageHider::run());
        $this->assertFileDoesNotExist($this->storage.'/logs/mail.log');
        $this->assertDirectoryDoesNotExist($this->storage.'/framework/cache/data/ab');
    }

    /**
     * A log takes its new name with one rename when nothing is there yet; a small one is appended to the new file the
     * app already writes; a big one (years of logging, too much to copy within a request of Finish) gets a name of the
     * same family beside it, so it is hidden too and Tools > Logs lists it.
     */
    public function test_logs_take_their_new_names_without_copying_a_big_one(): void
    {
        $single = (string) config('logging.channels.single.path');
        $stripe = (string) config('logging.channels.stripe.path');
        file_put_contents($this->storage.'/logs/mail.log', "old mail line\n");
        $big = str_repeat("an old error line of a busy site\n", 280000);
        file_put_contents($this->storage.'/logs/laravel.log', $big);
        file_put_contents($single, "written by the new version\n");
        file_put_contents($this->storage.'/logs/stripe.log', "old stripe line\n");
        file_put_contents($stripe, "new stripe line\n");
        $this->assertGreaterThan(8 * 1024 * 1024, strlen($big));

        StorageHider::run();

        $this->assertSame("old mail line\n", file_get_contents((string) config('logging.channels.mail.path')), 'renamed');
        $this->assertSame("written by the new version\n", file_get_contents($single), 'the big one was not copied into it');
        $beside = glob(substr($single, 0, -4).'-before-*.log') ?: [];
        $this->assertCount(1, $beside);
        $this->assertMatchesRegularExpression('/^laravel-[0-9a-f]{16}-before-\d{14}\.log$/', basename($beside[0]));
        $this->assertSame(strlen($big), filesize($beside[0]));
        $this->assertSame("new stripe line\nold stripe line\n", file_get_contents($stripe), 'a small one is appended');
        foreach (['mail', 'laravel', 'stripe'] as $old) {
            $this->assertFileDoesNotExist($this->storage.'/logs/'.$old.'.log');
        }
        $this->assertSame([], StorageHider::run(), 'a second run finds nothing to move');
    }

    public function test_the_migration_runs_it_on_a_site_and_never_in_the_test_suite(): void
    {
        file_put_contents($this->storage.'/logs/mail.log', "old mail line\n");
        $migration = require database_path('migrations/2026_10_26_000000_hide_storage_files.php');

        $migration->up();
        $this->assertFileExists($this->storage.'/logs/mail.log', 'the test suite migrates before every test: nothing moves');

        $this->app['env'] = 'production';

        try {
            $migration->up();
            $migration->up();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertFileDoesNotExist($this->storage.'/logs/mail.log');
        $this->assertSame("old mail line\n", file_get_contents(config('logging.channels.mail.path')), 'appended once');
        $migration->down();
    }

    private function earlierReleaseFiles(): void
    {
        file_put_contents($this->storage.'/logs/mail.log', "old mail line\n");
        file_put_contents($this->storage.'/logs/laravel.log', "old error line\n");
        file_put_contents($this->storage.'/logs/stripe.log', "old stripe line\n");
        file_put_contents($this->storage.'/logs/paypal.log', "old paypal line\n");
        file_put_contents($this->storage.'/logs/laravel-2026-10-20.log', "old daily line\n");

        File::ensureDirectoryExists($this->storage.'/framework/cache/data/ab/cd');
        file_put_contents($this->storage.'/framework/cache/data/ab/cd/abcd1234', 'cached settings with a mail password');
        File::ensureDirectoryExists(config('cache.stores.file.path'));
        file_put_contents(config('cache.stores.file.path').'/keep.txt', 'current');

        foreach (['backup_2026-10-01_101010.sql.gz', 'backup_before_update_1.2.3_2026-10-25_143000.sql', 'example.sql', 'my-own-dump.sql'] as $name) {
            file_put_contents($this->storage.'/app/backups/'.$name, '-- dump');
        }

        file_put_contents($this->storage.'/app/private/exports/users-1729851234.csv', "a,b\n1,2\n");
        file_put_contents($this->storage.'/app/installed.json', json_encode(['installed_at' => '2026-10-11T20:55:00+00:00', 'app_url' => 'https://victim-site.example', 'admin_email' => 'owner@example.test', 'backup' => 'example.sql'], JSON_PRETTY_PRINT));
        file_put_contents($this->storage.'/app/installer-backups/.env.backup', "APP_KEY=old\n");
        file_put_contents($this->root.'/.env.backup.20260801101010', "APP_KEY=first\n");
        file_put_contents($this->root.'/.env.backup.20260901101010', "APP_KEY=second\n");
    }

    private function transaction(): Transaction
    {
        $member = User::factory()->create();
        $id = DB::table('transactions')->insertGetId([
            'payment_provider' => 'stripe', 'stripe_id' => 'ch_hider', 'amount' => 999, 'tax' => 0, 'fee' => 0,
            'date' => '2026-09-15 10:00:00', 'user_id' => $member->id, 'name' => 'Member', 'address' => 'Street 1',
            'city' => 'Town', 'postal_code' => '1000', 'country' => 'de',
        ]);
        $transaction = Transaction::query()->findOrFail($id);
        file_put_contents($this->storage.'/app/private/invoices/2026-09/'.$transaction->invoice_name, "%PDF-1.4\n");

        // Before the move the PDF is found at its old place.
        $this->assertSame('invoices/2026-09/'.$transaction->invoice_name, $transaction->invoice_path);

        return $transaction;
    }
}
