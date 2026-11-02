<?php

namespace Tests\Feature;

use App\Exports\UsersExport;
use App\Jobs\Export;
use App\Models\Export as ExportModel;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DatabaseBackup;
use App\Support\Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * Security plan S03 part 3: the files under storage/ that carry secrets have names nobody can derive without APP_KEY or
 * a random part, so a server that serves storage/ by mistake still hands out nothing: the mail and error logs, the file
 * cache, invoice PDFs (from APP_KEY), database backups and exports (random), the install lock without the
 * administrator's e-mail. The pages a view-only account opens never print a derived name or a random part, and an
 * update's own files (plan.json, backup.json, its log, the audit line) name its backup only without the random part.
 */
class HiddenStorageNamesTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    public function test_log_and_cache_names_come_from_the_site_key(): void
    {
        $key = (string) env('APP_KEY', '');
        $part = fn (string $what): string => substr(hash('sha256', $what.'|'.$key), 0, 16);

        $this->assertSame(storage_path('logs/mail-'.$part('mail-log').'.log'), config('logging.channels.mail.path'));
        $this->assertSame(storage_path('logs/laravel-'.$part('laravel-log').'.log'), config('logging.channels.single.path'));
        $this->assertSame(config('logging.channels.single.path'), config('logging.channels.daily.path'));
        $this->assertSame(storage_path('logs/stripe-'.$part('stripe-log').'.log'), config('logging.channels.stripe.path'));
        $this->assertSame(storage_path('logs/paypal-'.$part('paypal-log').'.log'), config('logging.channels.paypal.path'));
        $this->assertSame(storage_path('framework/cache/data/'.$part('cache')), config('cache.stores.file.path'));

        foreach (['mail-log', 'laravel-log', 'stripe-log', 'paypal-log', 'cache'] as $what) {
            $this->assertNotSame($part($what), substr(hash('sha256', $what.'|'), 0, 16), 'another key, another name');
        }
    }

    public function test_backup_names_have_a_random_part_and_pages_show_them_without_it(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', DatabaseBackup::randomPart());
        $this->assertNotSame(DatabaseBackup::randomPart(), DatabaseBackup::randomPart());
        $this->assertSame('backup_2026-10-25_143000.sql.gz', DatabaseBackup::shownName('backup_2026-10-25_143000_ab12cd34ef56.sql.gz'));
        $this->assertSame('backup_before_update_1.2.3_2026-10-25_143000.sql', DatabaseBackup::shownName('backup_before_update_1.2.3_2026-10-25_143000_ab12cd34ef56.sql'));
        $this->assertSame('example.sql', DatabaseBackup::shownName('example.sql'));
    }

    public function test_an_update_names_its_backup_only_without_the_random_part(): void
    {
        $this->makeSite();

        try {
            $this->updateToNext();

            $files = array_map('basename', glob($this->box->root.'/storage/app/backups/backup_before_update_*') ?: []);
            $this->assertCount(1, $files);
            $this->assertSame(1, preg_match('/_([0-9a-f]{12})\.sql$/', $files[0], $m), $files[0]);
            $random = $m[1];

            $plan = (string) file_get_contents($this->box->paths()->plan());
            $this->assertStringNotContainsString($random, $plan, 'plan.json');
            $this->assertSame(['done' => true], json_decode($plan, true)['db'] ?? null, 'no name, no path once the backup is done');
            $this->assertSame(DatabaseBackup::shownName($files[0]), json_decode($plan, true)['database_backup']['name']);

            foreach (glob($this->box->root.'/storage/app/updates/backups/*/backup.json') ?: [] as $backupJson) {
                $this->assertStringNotContainsString($random, (string) file_get_contents($backupJson), 'backup.json');
            }

            foreach (glob($this->box->root.'/storage/app/updates/**/*.log') ?: [] as $log) {
                $this->assertStringNotContainsString($random, (string) file_get_contents($log), basename($log));
            }

            $this->assertStringNotContainsString($random, implode("\n", array_column($this->auditRows(), 'details')), 'the admin log');
        } finally {
            $this->removeSite();
        }
    }

    public function test_an_undo_puts_the_invoices_back_where_an_older_release_reads_them(): void
    {
        $this->makeSite();

        try {
            $this->updateToNext();

            // The updater works on this site's own storage/ (the test site's), as on a real site.
            $storage = $this->box->root.'/storage';
            $this->app->useStoragePath($storage);
            config(['filesystems.disks.local.root' => $storage.'/app/private']);
            Storage::forgetDisk('local');
            $hidden = $storage.'/app/private/'.Transaction::invoiceFolder().'/2026-10/Invoice7-2026-10-01.pdf';
            File::ensureDirectoryExists(dirname($hidden));
            file_put_contents($hidden, "%PDF-1.4\n");

            $this->undoToSwap($this->updater($this->next));

            $back = $storage.'/app/private/invoices/2026-10/Invoice7-2026-10-01.pdf';

            if (version_compare(\App\Support\AppVersion::CURRENT, \App\Support\StorageHider::LAST_RELEASE_WITHOUT_HIDDEN_NAMES, '<=')) {
                $this->assertFileExists($back, 'the older release reads invoices there');
                $this->assertFileDoesNotExist($hidden);
            } else {
                $this->assertFileExists($hidden, 'a release that knows the hidden names keeps them');
                $this->assertFileDoesNotExist($back);
            }
        } finally {
            $this->removeSite();
        }
    }

    public function test_an_export_has_a_random_part_and_still_downloads(): void
    {
        $root = storage_path('framework/testing/export-names-'.uniqid());
        File::ensureDirectoryExists($root.'/app/private');
        config(['filesystems.disks.local.root' => $root.'/app/private', 'admin.operational_tools_enabled' => true]);
        Storage::forgetDisk('local');

        try {
            $admin = User::factory()->admin()->create();
            $export = ExportModel::query()->create(['status' => \App\Enums\ExportStatus::PENDING]);

            \Illuminate\Support\Facades\Event::fake([\App\Events\ExportFinished::class]);
            (new Export($admin, UsersExport::class, $export, []))->handle();

            $file = (string) $export->fresh()->file;
            $this->assertMatchesRegularExpression('/^users-\d{9,11}-[0-9a-f]{12}\.csv$/', $file);
            $this->assertFileExists($root.'/app/private/exports/'.$file);
            $this->actingAs($admin)->get(route('admin.exports.download', $export))->assertOk();
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_an_invoice_lives_in_the_folder_named_from_the_site_key(): void
    {
        $member = User::factory()->create();
        $id = DB::table('transactions')->insertGetId([
            'payment_provider' => 'stripe', 'stripe_id' => 'ch_names', 'amount' => 999, 'tax' => 0, 'fee' => 0,
            'date' => '2026-10-01 10:00:00', 'user_id' => $member->id, 'name' => 'Member', 'address' => 'Street 1',
            'city' => 'Town', 'postal_code' => '1000', 'country' => 'de',
        ]);
        $transaction = Transaction::query()->findOrFail($id);

        $this->assertSame('invoices-'.substr(hash('sha256', 'invoices|'.config('app.key')), 0, 16), Transaction::invoiceFolder());
        $this->assertSame(Transaction::invoiceFolder().'/2026-10/'.$transaction->invoice_name, $transaction->invoice_path);
        $this->assertStringStartsWith('Invoice'.$id.'-2026-10-01', $transaction->invoice_name, 'the attachment name is unchanged');
    }

    public function test_the_install_lock_keeps_no_email_address(): void
    {
        $root = storage_path('framework/testing/install-lock-'.uniqid());
        File::ensureDirectoryExists($root.'/app');
        $this->app->useStoragePath($root);

        try {
            (new ReflectionMethod(Installer::class, 'writeLock'))->invoke(app(Installer::class),
                ['username' => 'owner', 'email' => 'owner@example.test', 'password' => 'x'], ['url' => 'https://victim-site.example']);

            $lock = (string) file_get_contents($root.'/app/installed.json');
            $this->assertStringNotContainsString('owner@example.test', $lock);
            $this->assertArrayNotHasKey('admin_email', json_decode($lock, true));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_the_email_test_page_names_no_derived_log_file(): void
    {
        config(['mail.default' => 'log', 'admin.ui' => 'v1']);
        $mailLog = basename((string) config('logging.channels.mail.path'));
        $demo = User::factory()->admin()->create();
        $demo->forceFill([\App\Http\Middleware\ViewOnlyDemo::COLUMN => true])->save();

        foreach ([User::factory()->admin()->create(), $demo->fresh()] as $viewer) {
            $html = (string) $this->actingAs($viewer)->get(route('admin.email-test.index'))->assertOk()->getContent();

            $this->assertStringNotContainsString($mailLog, $html);
            $this->assertStringNotContainsString('storage/logs/mail.log', $html);
            $this->assertStringContainsString('mail-....log', $html);
        }
    }
}
