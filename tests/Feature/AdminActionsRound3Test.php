<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Http\Controllers\Admin\XvidImportController;
use App\Listeners\AuditLogFileDeleted;
use App\Models\AvdbImportRun;
use App\Models\AvdbImportSchedule;
use App\Models\Category;
use App\Models\Language;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Video;
use App\Models\XvidImportRun;
use App\Models\XvidImportSchedule;
use App\Support\AuditActions;
use App\Support\AuditDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Opcodes\LogViewer\Events\LogFileDeleted;
use Tests\TestCase;

/**
 * R3-019: Tools > Logs > Admin actions records every admin change that changes the site, beyond R2A-020's list: the
 * judge's eleven changes (Clear All Logs, Health check Auto-fix, a Premium plan's edit and delete, categories made and
 * deleted, a tag renamed, redirects and announcements, a language off and on, the system cache clears, imports started
 * from AVDBAPI and XVIDAPI) and every other admin action that changes something (homepage picks, info pages, pictures,
 * files, history, password resets, premium records, subscriptions, payments, the spam filter, member exports, import
 * schedules and clean-ups, log files deleted on the file viewer, Horizon's retries). One row per action, named by its
 * label, with readable details that never carry a secret or a value of the change (a price, a payment id, an email
 * address, a word of the spam list); an action that changes nothing writes nothing. AdminActionsCoverageTest makes
 * every admin route decide.
 */
class AdminActionsRound3Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ?string $storage = null;

    private ?string $linesDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.operational_tools_enabled' => true]);
        $this->admin = User::factory()->admin()->create(['username' => 'boss']);
    }

    protected function tearDown(): void
    {
        foreach (['AVDB_IMPORT_MODE', 'SCHEDULER_CRON_AUTO_INSTALL'] as $key) {
            unset($_ENV[$key]);
        }

        if ($this->storage !== null) {
            File::deleteDirectory($this->storage);
        }

        if ($this->linesDir !== null) {
            @unlink($this->linesDir.DIRECTORY_SEPARATOR.'ar.json');
            @rmdir($this->linesDir);
        }

        parent::tearDown();
    }

    /** A storage folder of the test's own (log files, cache files): the copy's own are never touched. */
    private function ownStorage(): string
    {
        $this->storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avd-r3019-'.bin2hex(random_bytes(5));
        foreach (['logs', 'framework/cache/data', 'framework/views', 'framework/sessions', 'app/public'] as $folder) {
            File::ensureDirectoryExists($this->storage.DIRECTORY_SEPARATOR.$folder);
        }
        $this->app->useStoragePath($this->storage);

        return $this->storage;
    }

    /** @return list<object> the rows written so far, oldest first */
    private function rows(?string $action = null): array
    {
        return DB::table('admin_audit_logs')->when($action, fn ($query) => $query->where('action', $action))->orderBy('id')->get()->all();
    }

    /** The one row of $action, by this administrator, with its label: its details. */
    private function onlyRow(string $action): ?string
    {
        $rows = $this->rows($action);
        $this->assertCount(1, $rows, "one {$action} row");
        $this->assertSame((int) $this->admin->id, (int) $rows[0]->user_id, "{$action}: the administrator who did it");
        $this->assertArrayHasKey($action, AuditActions::LABELS, "{$action} has its label");

        if ($rows[0]->details !== null) {
            $this->assertTrue(AuditDetails::known($action, (string) $rows[0]->details), "{$action}: the details are shown in the reader's language: {$rows[0]->details}");
        }

        return $rows[0]->details;
    }

    /** Runs $do and says how many rows it wrote. */
    private function written(callable $do): int
    {
        $before = DB::table('admin_audit_logs')->count();
        $do();

        return DB::table('admin_audit_logs')->count() - $before;
    }

    public function test_clear_all_logs_and_a_deleted_log_file_are_written(): void
    {
        $storage = $this->ownStorage();
        file_put_contents($storage.'/logs/laravel.log', "[2026-10-29] local.ERROR: something\n");
        file_put_contents($storage.'/logs/cron.log', "ran\n");
        file_put_contents($storage.'/logs/notes.txt', 'not a log');

        $this->actingAs($this->admin)->post(route('admin.logs.clear-all'))->assertRedirect();
        $this->assertSame('cron.log, laravel.log', $this->onlyRow('tools.logs.cleared'));
        $this->assertFileExists($storage.'/logs/notes.txt');

        // nothing left to clear: nothing written
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->post(route('admin.logs.clear-all'))->assertRedirect()));

        file_put_contents($storage.'/logs/import.log', 'x');
        $this->actingAs($this->admin)->delete(route('admin.logs.delete', 'import.log'))->assertRedirect();
        $this->assertSame('import.log', $this->onlyRow('tools.log.deleted'));
        // a file that is not there: an error, no row
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->delete(route('admin.logs.delete', 'import.log'))->assertRedirect()));
    }

    public function test_log_files_deleted_on_the_file_viewer_are_written_once_per_action(): void
    {
        $storage = $this->ownStorage();
        $files = [];
        foreach (['a.log', 'b.log', 'c.log'] as $name) {
            file_put_contents($storage.'/logs/'.$name, 'x');
            $files[] = new \Opcodes\LogViewer\LogFile($storage.'/logs/'.$name);
        }

        $this->actingAs($this->admin);
        // the package deletes file by file and says so for each (LogFile::delete()): one row for the action
        foreach ($files as $file) {
            event(new LogFileDeleted($file));
        }
        AuditLogFileDeleted::flush();

        $this->assertSame('a.log, b.log, c.log', $this->onlyRow('tools.logs.deleted'));

        event(new LogFileDeleted($files[0]));
        AuditLogFileDeleted::flush();
        $this->assertSame('a.log', $this->onlyRow('tools.log.deleted'));
    }

    public function test_health_check_auto_fix_writes_what_it_changed(): void
    {
        $this->ownStorage();

        $this->actingAs($this->admin)->post(route('admin.preflight.fix'))->assertRedirect(route('admin.preflight.index'));

        $details = (string) $this->onlyRow('tools.health.fixed');
        $repairs = (array) session('preflight_repairs');
        $fixed = array_values(array_filter($repairs, fn (array $repair): bool => $repair['severity'] === 'success'));
        $this->assertNotSame([], $fixed);
        $this->assertSame(implode("\n", array_column($fixed, 'message')), $details, 'every line Auto-fix changed, as the session keeps them');
        $this->assertStringNotContainsString('base64:', $details, 'never a key');
    }

    public function test_premium_plans_are_written_without_their_price_or_payment_ids(): void
    {
        $this->actingAs($this->admin)->post(route('admin.plans.store'), ['name' => 'Gold', 'price' => 999, 'duration' => 1, 'stripe_id' => 'price_secret123', 'paypal_plan_id' => 'P-ABC123'])->assertRedirect();
        $plan = Plan::query()->where('name', 'Gold')->firstOrFail();
        $this->assertSame('Gold (#'.$plan->id.')', $this->onlyRow('plan.created'));

        $form = ['name' => 'Gold', 'price' => 1999, 'duration' => 1, 'stripe_id' => 'price_secret123', 'paypal_plan_id' => 'P-ABC123'];
        $this->actingAs($this->admin)->put(route('admin.plans.update', $plan), $form)->assertRedirect();
        $this->assertSame('Gold (#'.$plan->id.'): price', $this->onlyRow('plan.updated'));

        // the same values again: nothing changed, nothing written
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->put(route('admin.plans.update', $plan->fresh()), $form)->assertRedirect()));

        $this->actingAs($this->admin)->put(route('admin.plans.update', $plan), ['name' => 'Gold yearly', 'duration' => 12, 'stripe_id' => 'price_other', 'price' => 1999, 'paypal_plan_id' => 'P-ABC123'])->assertRedirect();
        $this->assertSame('Gold yearly (#'.$plan->id.'): name, billing, stripe', $this->rows('plan.updated')[1]->details);

        $this->actingAs($this->admin)->delete(route('admin.plans.destroy', $plan))->assertRedirect();
        $this->assertSame('Gold yearly (#'.$plan->id.')', $this->onlyRow('plan.deleted'));

        $all = json_encode($this->rows());
        foreach (['price_secret123', 'price_other', 'P-ABC123', '1999', '19.99'] as $value) {
            $this->assertStringNotContainsString($value, $all, 'never a value of the plan');
        }
    }

    public function test_categories_and_tags_are_written(): void
    {
        $this->actingAs($this->admin)->post(route('admin.categories.store'), ['title' => 'Music', 'in_menu' => '1'])->assertRedirect();
        $music = Category::query()->where('title', 'Music')->firstOrFail();
        $this->assertSame('Music (#'.$music->id.')', $this->onlyRow('category.created'));

        $this->actingAs($this->admin)->put(route('admin.categories.update', $music), ['title' => 'Music videos', 'slug' => $music->slug, 'in_menu' => '1'])->assertRedirect();
        $this->assertSame('Music videos (#'.$music->id.'): name', $this->onlyRow('category.updated'));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->put(route('admin.categories.update', $music->fresh()), ['title' => 'Music videos', 'slug' => $music->fresh()->slug, 'in_menu' => '1'])->assertRedirect()));

        $games = Category::query()->create(['title' => 'Games', 'slug' => 'games', 'in_menu' => true, 'position' => 5]);
        $this->actingAs($this->admin)->post(route('admin.categories.organize'), ['categories' => [$games->id, $music->id]])->assertRedirect();
        $this->assertStringStartsWith('Games (#'.$games->id.'), Music videos (#'.$music->id.')', (string) $this->onlyRow('categories.reordered'));
        // the same order again: nothing written
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->post(route('admin.categories.organize'), ['categories' => [$games->id, $music->id]])));

        $this->actingAs($this->admin)->delete(route('admin.categories.destroy', $music))->assertRedirect();
        $this->assertSame('Music videos (#'.$music->id.')', $this->onlyRow('category.deleted'));

        // tags
        $this->actingAs($this->admin)->post(route('admin.tags.store'), ['name' => 'sunset'])->assertRedirect();
        $tag = DB::table('tags')->where('name', 'sunset')->first();
        $this->assertSame('sunset (#'.$tag->id.')', $this->onlyRow('tag.created'));

        $this->actingAs($this->admin)->put(route('admin.tags.update', $tag->id), ['name' => 'sunsets'])->assertRedirect();
        $this->assertSame('sunset (#'.$tag->id.') -> sunsets', $this->onlyRow('tag.renamed'));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->put(route('admin.tags.update', $tag->id), ['name' => 'sunsets'])));

        $beach = DB::table('tags')->insertGetId(['name' => 'beach', 'slug' => 'beach', 'usage_count' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->admin)->post(route('admin.tags.merge'), ['source_id' => $tag->id, 'target_id' => $beach])->assertRedirect();
        $this->assertSame('sunsets (#'.$tag->id.') -> beach (#'.$beach.')', $this->onlyRow('tags.merged'));
        // (the merge counts the target's videos again: none in this test; it is used by videos on a real site)
        DB::table('tags')->where('id', $beach)->update(['usage_count' => 3]);

        $unused = DB::table('tags')->insertGetId(['name' => 'unused one', 'slug' => 'unused-one', 'usage_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->admin)->post(route('admin.tags.delete-unused'))->assertRedirect();
        $this->assertSame('unused one (#'.$unused.')', $this->onlyRow('tags.unused_deleted'));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->post(route('admin.tags.delete-unused'))));

        $this->actingAs($this->admin)->delete(route('admin.tags.destroy', $beach))->assertRedirect();
        $this->assertSame('beach (#'.$beach.')', $this->onlyRow('tag.deleted'));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->delete(route('admin.tags.destroy', $beach))));
    }

    public function test_redirects_announcements_and_languages_are_written(): void
    {
        $this->actingAs($this->admin)->post(route('admin.redirects.store'), ['source' => '/old-page', 'destination' => '/new-page', 'status_code' => '301', 'is_active' => '1'])->assertRedirect();
        $this->assertSame('/old-page -> /new-page (301)', $this->onlyRow('redirect.created'));
        $id = (int) DB::table('redirects')->value('id');
        $this->actingAs($this->admin)->post(route('admin.redirects.toggle', $id))->assertRedirect();
        $this->assertSame('/old-page -> /new-page (301)', $this->onlyRow('redirect.off'));
        $this->actingAs($this->admin)->post(route('admin.redirects.toggle', $id))->assertRedirect();
        $this->onlyRow('redirect.on');
        $this->actingAs($this->admin)->delete(route('admin.redirects.delete', $id))->assertRedirect();
        $this->onlyRow('redirect.deleted');
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->delete(route('admin.redirects.delete', $id))));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->post(route('admin.redirects.toggle', $id))));

        $this->actingAs($this->admin)->post(route('admin.announcements.store'), ['title' => 'Maintenance tonight', 'message' => 'Back at 2 AM.', 'type' => 'info', 'position' => 'top', 'is_active' => '1'])->assertRedirect();
        $announcement = (int) DB::table('announcements')->value('id');
        $this->assertSame('Maintenance tonight (#'.$announcement.')', $this->onlyRow('announcement.created'));
        $this->actingAs($this->admin)->post(route('admin.announcements.toggle', $announcement))->assertRedirect();
        $this->onlyRow('announcement.hidden');
        $this->actingAs($this->admin)->post(route('admin.announcements.toggle', $announcement))->assertRedirect();
        $this->onlyRow('announcement.shown');
        $this->actingAs($this->admin)->delete(route('admin.announcements.destroy', $announcement))->assertRedirect();
        $this->onlyRow('announcement.deleted');
        $this->assertStringNotContainsString('Back at 2 AM', json_encode($this->rows()), 'never the message');

        // a language turned off and on, added, changed and removed (the default one cannot be turned off: no row)
        Language::query()->where('is_default', true)->update(['is_default' => false]);
        Language::query()->updateOrCreate(['code' => 'en'], ['label' => 'English', 'emoji' => '', 'direction' => 'ltr', 'is_active' => true, 'is_default' => true, 'sort_order' => 1]);
        $french = Language::query()->updateOrCreate(['code' => 'fr'], ['label' => 'Français', 'emoji' => '', 'direction' => 'ltr', 'is_active' => true, 'is_default' => false, 'sort_order' => 2]);
        $this->actingAs($this->admin)->post(route('admin.languages.toggle-active', $french))->assertRedirect();
        $this->assertSame('Français (fr)', $this->onlyRow('language.off'));
        $this->actingAs($this->admin)->post(route('admin.languages.toggle-active', $french->fresh()))->assertRedirect();
        $this->assertSame('Français (fr)', $this->onlyRow('language.on'));
        $english = Language::query()->where('code', 'en')->firstOrFail();
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->post(route('admin.languages.toggle-active', $english))));

        $this->actingAs($this->admin)->post(route('admin.languages.store'), ['code' => 'xq', 'label' => 'Testish', 'emoji' => 'T', 'direction' => 'rtl', 'is_active' => '1'])->assertRedirect();
        $this->assertSame('Testish (xq)', $this->onlyRow('language.added'));
        $testish = Language::query()->where('code', 'xq')->firstOrFail();
        $this->actingAs($this->admin)->put(route('admin.languages.update-meta', $testish), ['label' => 'Testish', 'emoji' => 'T', 'direction' => 'ltr', 'is_active' => '1', 'sort_order' => 9])->assertRedirect();
        $this->assertSame('Testish (xq): direction, order', $this->onlyRow('language.updated'));
        $this->actingAs($this->admin)->delete(route('admin.languages.destroy', $testish))->assertRedirect();
        $this->assertSame('Testish (xq)', $this->onlyRow('language.deleted'));
    }

    public function test_the_texts_a_translation_save_changed_are_counted(): void
    {
        $differences = new \ReflectionMethod(\App\Http\Controllers\Admin\LanguageController::class, 'differences');

        $this->assertSame(0, $differences->invoke(null, ['a' => 'x', 'b' => 'y'], ['a' => 'x', 'b' => 'y']));
        $this->assertSame(3, $differences->invoke(null, ['a' => 'x', 'b' => 'y', 'c' => 'z'], ['a' => 'x', 'b' => 'Y', 'd' => 'new']), 'one changed, one added, one taken out');
    }

    public function test_the_cache_clears_and_storage_folders_are_written(): void
    {
        Artisan::shouldReceive('call')->andReturn(0);

        foreach (['all' => 'tools.cache.all_cleared', 'config' => 'tools.cache.config_cleared', 'route' => 'tools.cache.routes_cleared', 'view' => 'tools.cache.views_cleared', 'app' => 'tools.cache.data_cleared'] as $type => $action) {
            $this->actingAs($this->admin)->post(route('admin.system.clear', $type))->assertRedirect();
            $this->onlyRow($action);
        }
        // an unknown cache: nothing cleared, nothing written
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->post(route('admin.system.clear', 'nothing'))));

        DB::table('admin_audit_logs')->delete();
        $this->actingAs($this->admin)->postJson(route('admin.settings.clear.cache', 'view'))->assertOk();
        $this->onlyRow('tools.cache.views_cleared');

        $storage = $this->ownStorage();
        file_put_contents($storage.'/framework/cache/data/one', 'x');
        file_put_contents($storage.'/framework/cache/data/two', 'y');
        $this->actingAs($this->admin)->post(route('admin.storage.clear', 'cache'))->assertRedirect();
        $this->assertSame('storage/framework/cache: 2 files', $this->onlyRow('tools.storage.cleared'));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->post(route('admin.storage.clear', 'cache'))));
    }

    public function test_avdbapi_imports_and_their_schedules_are_written(): void
    {
        Queue::fake();
        $_ENV['AVDB_IMPORT_MODE'] = 'queue';
        $_ENV['SCHEDULER_CRON_AUTO_INSTALL'] = '0';
        $form = ['categories' => [1], 'pages' => 1, 'start_page' => 1, 'pagesize' => 10, 'delay' => 0, 'language_mode' => 'auto', 'thumbnail_mode' => 'remote', 'sort_direction' => 'desc', 'translation_chunk' => 50, 'translation_concurrency' => 2];

        $this->actingAs($this->admin)->postJson(route('admin.avdb-import.start'), $form)->assertOk()->assertJsonPath('success', true);
        $run = AvdbImportRun::query()->latest('id')->firstOrFail();
        $this->assertSame('#'.$run->id, $this->onlyRow('import.avdb.started'));
        $this->actingAs($this->admin)->postJson(route('admin.avdb-import.start'), $form + ['dry_run' => '1'])->assertOk();
        $this->assertSame('#'.($run->id + 1).', dry run', $this->rows('import.avdb.started')[1]->details);

        $this->actingAs($this->admin)->postJson(route('admin.avdb-import.stop', $run))->assertOk();
        $this->assertSame('#'.$run->id, $this->onlyRow('import.avdb.stopped'));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->postJson(route('admin.avdb-import.stop', $run->fresh()))), 'a run that is over: nothing');

        $this->actingAs($this->admin)->postJson(route('admin.avdb-import.force-stop'))->assertOk();
        $this->assertSame('#'.($run->id + 1), $this->onlyRow('import.avdb.force_stopped'));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->postJson(route('admin.avdb-import.force-stop'))), 'none running: nothing');

        $this->actingAs($this->admin)->delete(route('admin.avdb-import.runs.destroy', $run))->assertRedirect();
        $this->assertSame('#'.$run->id, $this->onlyRow('import.avdb.run_deleted'));
        $this->actingAs($this->admin)->delete(route('admin.avdb-import.runs.clean-finished'))->assertRedirect();
        $this->assertSame('#'.($run->id + 1), $this->onlyRow('import.avdb.runs_deleted'));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->delete(route('admin.avdb-import.runs.clean-finished'))));

        // a schedule: added (paused, so no cron job is installed), turned on, run now, deleted
        $this->actingAs($this->admin)->postJson(route('admin.avdb-import.schedules.store'), $form + ['schedule_name' => 'Nightly', 'schedule_frequency' => 'daily', 'schedule_time' => '02:00', 'schedule_enabled' => '0'])->assertOk();
        $schedule = AvdbImportSchedule::query()->latest('id')->firstOrFail();
        $this->assertSame('Nightly (#'.$schedule->id.')', $this->onlyRow('import.avdb.schedule.created'));
        $this->actingAs($this->admin)->post(route('admin.avdb-import.schedules.toggle', $schedule))->assertRedirect();
        $this->onlyRow('import.avdb.schedule.on');
        Artisan::shouldReceive('call')->once()->with('avdb:schedules:run', ['--id' => $schedule->id])->andReturn(0);
        $this->actingAs($this->admin)->post(route('admin.avdb-import.schedules.run-now', $schedule->fresh()))->assertRedirect();
        $this->onlyRow('import.avdb.schedule.run');
        $this->actingAs($this->admin)->post(route('admin.avdb-import.schedules.toggle', $schedule->fresh()))->assertRedirect();
        $this->onlyRow('import.avdb.schedule.off');
        // a paused schedule is not run: nothing written
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->post(route('admin.avdb-import.schedules.run-now', $schedule->fresh()))));
        $this->actingAs($this->admin)->delete(route('admin.avdb-import.schedules.destroy', $schedule))->assertRedirect();
        $this->assertSame('Nightly (#'.$schedule->id.')', $this->onlyRow('import.avdb.schedule.deleted'));
    }

    public function test_avdbapi_clean_all_and_due_schedules_are_written(): void
    {
        Artisan::shouldReceive('call')->andReturn(0);

        // nothing due, nothing imported: nothing written
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->post(route('admin.avdb-import.schedules.run-due'))));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->postJson(route('admin.avdb-import.clean'))->assertOk()));

        $due = AvdbImportSchedule::query()->create(['name' => 'Due one', 'enabled' => true, 'frequency' => 'daily', 'run_time' => '02:00', 'weekday' => 1, 'month_day' => 1, 'interval_minutes' => 1440, 'skip_if_active' => true, 'settings' => [], 'next_run_at' => now()->subMinute()]);
        $this->actingAs($this->admin)->post(route('admin.avdb-import.schedules.run-due'))->assertRedirect();
        $this->assertSame('#'.$due->id, $this->onlyRow('import.avdb.schedules.run_due'));

        $owner = User::factory()->create();
        Video::factory()->for($owner)->create(['avdb_id' => 9001, 'status' => VideoStatus::PUBLIC->value]);
        Video::factory()->for($owner)->create(['avdb_id' => 9002, 'status' => VideoStatus::PUBLIC->value]);
        $this->actingAs($this->admin)->postJson(route('admin.avdb-import.clean'))->assertOk()->assertJsonPath('deleted', 2);
        $this->assertStringStartsWith('videos: 2', (string) $this->onlyRow('import.avdb.cleaned'));
    }

    public function test_xvidapi_imports_and_their_schedules_are_written(): void
    {
        // the run is made, never started (no process, no queue worker in a test)
        $this->partialMock(XvidImportController::class, fn (MockInterface $mock) => $mock->shouldReceive('startBackgroundImport')->andReturn(true));

        $this->actingAs($this->admin)->postJson(route('admin.xvid-import.start'), ['categories' => [65], 'dry_run' => '1'])->assertOk();
        $run = XvidImportRun::query()->latest('id')->firstOrFail();
        $this->assertSame('#'.$run->id.', dry run', $this->onlyRow('import.xvid.started'));

        $run->forceFill(['status' => 'running'])->save();
        $this->actingAs($this->admin)->postJson(route('admin.xvid-import.stop', $run))->assertOk();
        $this->onlyRow('import.xvid.stopped');
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->postJson(route('admin.xvid-import.stop', $run->fresh()))), 'asked to stop already: nothing');

        $this->actingAs($this->admin)->postJson(route('admin.xvid-import.force-stop'))->assertOk();
        $this->assertSame('#'.$run->id, $this->onlyRow('import.xvid.force_stopped'));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->postJson(route('admin.xvid-import.force-stop'))));

        $this->actingAs($this->admin)->post(route('admin.xvid-import.runs.clean-finished'))->assertRedirect();
        $this->assertSame('#'.$run->id, $this->onlyRow('import.xvid.runs_deleted'));

        $this->actingAs($this->admin)->postJson(route('admin.xvid-import.schedules.store'), ['name' => 'Hourly XVID', 'frequency' => 'hourly', 'pages' => 1, 'start_page' => 1, 'pagesize' => 20, 'delay' => 0])->assertOk();
        $schedule = XvidImportSchedule::query()->latest('id')->firstOrFail();
        $this->assertSame('Hourly XVID (#'.$schedule->id.')', $this->onlyRow('import.xvid.schedule.created'));
        $this->actingAs($this->admin)->post(route('admin.xvid-import.schedules.toggle', $schedule))->assertRedirect();
        $this->onlyRow('import.xvid.schedule.off');
        Artisan::shouldReceive('call')->once()->andReturn(0);
        $this->actingAs($this->admin)->post(route('admin.xvid-import.schedules.run-now', $schedule))->assertRedirect();
        $this->onlyRow('import.xvid.schedule.run');
        $this->actingAs($this->admin)->delete(route('admin.xvid-import.schedules.destroy', $schedule))->assertRedirect();
        $this->onlyRow('import.xvid.schedule.deleted');

        $owner = User::factory()->create();
        Video::factory()->for($owner)->create(['xvid_id' => 77, 'status' => VideoStatus::PUBLIC->value]);
        $this->actingAs($this->admin)->postJson(route('admin.xvid-import.clean'))->assertOk()->assertJsonPath('deleted', 1);
        $this->assertSame('videos: 1', $this->onlyRow('import.xvid.cleaned'));
    }

    public function test_content_money_and_member_lists_are_written_without_personal_values(): void
    {
        $owner = User::factory()->create(['username' => 'carol', 'email' => 'carol@example.test']);
        $video = Video::factory()->for($owner)->create(['title' => 'Sunset drive', 'status' => VideoStatus::PUBLIC->value]);

        // homepage picks and info pages
        $this->actingAs($this->admin)->post(route('admin.featured.store'), ['type' => 'video', 'video_id' => $video->id, 'position' => 'homepage_hero', 'is_active' => '1'])->assertRedirect();
        $pick = (int) DB::table('featured_content')->value('id');
        $this->assertSame('Sunset drive (#'.$pick.')', $this->onlyRow('featured.created'));
        $this->actingAs($this->admin)->post(route('admin.featured.toggle', $pick))->assertRedirect();
        $this->onlyRow('featured.off');
        $this->actingAs($this->admin)->delete(route('admin.featured.destroy', $pick))->assertRedirect();
        $this->onlyRow('featured.deleted');

        $this->actingAs($this->admin)->post(route('admin.pages.store'), ['title' => 'About us', 'slug' => 'about-us', 'content' => '<p>Hello</p>', 'is_published' => '1'])->assertRedirect();
        $page = (int) DB::table('custom_pages')->value('id');
        $this->assertSame('About us (#'.$page.')', $this->onlyRow('page.created'));
        $this->actingAs($this->admin)->put(route('admin.pages.update', $page), ['title' => 'About us', 'slug' => 'about-us', 'content' => '<p>Hello again</p>', 'is_published' => '1', 'show_in_footer' => '1'])->assertRedirect();
        $this->assertSame('About us (#'.$page.'): text, footer_link', $this->onlyRow('page.updated'));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->put(route('admin.pages.update', $page), ['title' => 'About us', 'slug' => 'about-us', 'content' => '<p>Hello again</p>', 'is_published' => '1', 'show_in_footer' => '1'])));
        $this->actingAs($this->admin)->post(route('admin.pages.toggle', $page))->assertRedirect();
        $this->onlyRow('page.unpublished');
        $this->actingAs($this->admin)->delete(route('admin.pages.destroy', $page))->assertRedirect();
        $this->onlyRow('page.deleted');

        // password resets: the member, never the address
        DB::table('password_reset_tokens')->insert(['email' => 'carol@example.test', 'token' => 'x', 'created_at' => now()]);
        $this->actingAs($this->admin)->delete(route('admin.password-resets.delete', 'carol@example.test'))->assertRedirect();
        $this->assertSame('carol (#'.$owner->id.')', $this->onlyRow('security.password_reset.deleted'));
        DB::table('password_reset_tokens')->insert([['email' => 'a@example.test', 'token' => 'x', 'created_at' => now()], ['email' => 'b@example.test', 'token' => 'y', 'created_at' => now()]]);
        $this->actingAs($this->admin)->delete(route('admin.password-resets.clear'))->assertRedirect();
        $this->assertSame('2', $this->onlyRow('security.password_resets.cleared'));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->delete(route('admin.password-resets.clear'))));

        // premium records, subscriptions and payments: whose, never an amount
        $plan = Plan::factory()->create();
        $subscription = Subscription::factory()->create(['user_id' => $owner->id, 'plan_id' => $plan->id, 'stripe_id' => 'sub_secret', 'stripe_status' => 'active', 'ends_at' => null]);
        $this->actingAs($this->admin)->post(route('admin.premiums.extend', $subscription->id), ['days' => 30])->assertRedirect();
        $this->assertSame('carol (#'.$owner->id.'), +30 days', $this->onlyRow('premium.extended'));
        // Active again: the end date the extension gave it goes; a second click changes nothing
        $this->actingAs($this->admin)->post(route('admin.subscriptions.activate', $subscription))->assertRedirect();
        $this->assertSame('carol (#'.$owner->id.')', $this->onlyRow('subscription.activated'));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->post(route('admin.subscriptions.activate', $subscription->fresh()))));

        DB::table('transactions')->insert(['user_id' => $owner->id, 'stripe_id' => 'pi_secret', 'amount' => 999, 'tax' => 0, 'fee' => 30, 'date' => now(),
            'name' => 'Carol Smith', 'address' => '1 Road', 'city' => 'Town', 'postal_code' => '12345', 'country' => 'FR']);
        $transaction = (int) DB::table('transactions')->value('id');
        $this->actingAs($this->admin)->delete(route('admin.transactions.delete', $transaction))->assertRedirect();
        $this->assertSame('#'.$transaction.', carol (#'.$owner->id.')', $this->onlyRow('payment.deleted'));

        // the view and search history
        $view = DB::table('views')->insertGetId(['video_id' => $video->id, 'ip' => '203.0.113.5', 'view_at' => now()]);
        $this->actingAs($this->admin)->delete(route('admin.views.delete', $view))->assertRedirect();
        $this->assertSame('#'.$view, $this->onlyRow('view.deleted'));
        DB::table('searches')->insert(['query' => 'private words', 'results' => 0, 'perform_at' => now()]);
        $search = (int) DB::table('searches')->value('id');
        $this->actingAs($this->admin)->delete(route('admin.searches.delete', $search))->assertRedirect();
        $this->assertSame('#'.$search, $this->onlyRow('search.deleted'));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->delete(route('admin.searches.clear'))), 'nothing to delete');

        // the "Email not confirmed" tab: the members' own rows
        $unconfirmed = User::factory()->create(['username' => 'dan', 'email_verified_at' => null]);
        $this->actingAs($this->admin)->post(route('admin.verification.verify', $unconfirmed->id))->assertRedirect();
        $this->assertSame('dan (#'.$unconfirmed->id.')', $this->onlyRow('member.email_confirmed'));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->post(route('admin.verification.verify', $unconfirmed->id))), 'confirmed already');

        $all = json_encode($this->rows());
        foreach (['carol@example.test', 'a@example.test', 'sub_secret', 'pi_secret', 'Carol Smith', 'private words', '203.0.113.5', '999'] as $value) {
            $this->assertStringNotContainsString($value, $all, 'never a personal value or an amount');
        }
    }

    public function test_the_spam_filter_names_the_list_never_its_words(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin)->put(route('admin.spams.update'), ['words' => "casino\nfree money", 'emails' => ''])->assertRedirect();
        $this->assertSame('words', $this->onlyRow('spam.updated'));
        $this->assertSame(0, $this->written(fn () => $this->actingAs($this->admin)->put(route('admin.spams.update'), ['words' => "casino\nfree money", 'emails' => ''])));
        $this->assertStringNotContainsString('casino', json_encode($this->rows()));
    }

    public function test_horizon_retries_are_written(): void
    {
        $middleware = new \App\Http\Middleware\AuditHorizonActions();
        $this->actingAs($this->admin);

        foreach (['horizon.retry-jobs.show' => 'queue.failed.retry', 'horizon.jobs-batches.retry' => 'queue.batch.retry', 'horizon.monitoring.store' => null] as $name => $action) {
            $request = \Illuminate\Http\Request::create('/admin/horizon/api/x/9f1c', 'POST');
            $route = (new \Illuminate\Routing\Route('POST', 'admin/horizon/api/x/{id}', fn () => null))->name($name);
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
            $written = $this->written(fn () => $middleware->handle($request, fn () => response('', 200)));

            if ($action === null) {
                $this->assertSame(0, $written, "{$name} writes nothing");
            } else {
                $this->assertSame(1, $written, $name);
                $this->assertTrue(AuditDetails::known($action, (string) $this->rows($action)[0]->details), $action);
            }
        }
    }

    /**
     * The new rows read in the admin's language (Arabic here, with this test's own lines on top of lang/ar.json): the
     * label, the fields a save changed by the names its form shows, counts, the sentences of the import page, the
     * values set apart in their own direction.
     */
    public function test_the_new_rows_are_shown_in_the_readers_language(): void
    {
        // Arabic lines for the new texts (the merge brings the lane's translations; a test must not depend on them). The
        // words the forms already had come from lang/ar.json itself.
        $this->linesDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avd-r3019-ar-'.bin2hex(random_bytes(5));
        mkdir($this->linesDir);
        file_put_contents($this->linesDir.DIRECTORY_SEPARATOR.'ar.json', json_encode([
            'Plan changed' => 'تم تغيير الخطة',
            'Translations saved' => 'تم حفظ الترجمات',
            '1 text changed|:count texts changed' => 'لم يتغير أي نص|تم تغيير نص واحد|تم تغيير نصين|تم تغيير :count نصوص|تم تغيير :count نصًا|تم تغيير :count نص',
            'AVDBAPI import started' => 'بدأ استيراد AVDBAPI',
        ], JSON_UNESCAPED_UNICODE));
        app('translator')->addJsonPath($this->linesDir);

        DB::table('admin_audit_logs')->insert([
            ['user_id' => $this->admin->id, 'action' => 'plan.updated', 'details' => 'Gold (#3): price, billing', 'created_at' => now()],
            ['user_id' => $this->admin->id, 'action' => 'tag.renamed', 'details' => 'sunset (#8) -> sunsets', 'created_at' => now()],
            ['user_id' => $this->admin->id, 'action' => 'tools.storage.cleared', 'details' => 'storage/framework/cache: 12 files', 'created_at' => now()],
            ['user_id' => $this->admin->id, 'action' => 'language.texts.saved', 'details' => 'Français (fr): 4 texts', 'created_at' => now()],
            ['user_id' => $this->admin->id, 'action' => 'import.avdb.started', 'details' => '#12, dry run', 'created_at' => now()],
            ['user_id' => $this->admin->id, 'action' => 'tools.health.fixed', 'details' => "Core directory and file permissions normalized.\nCreated missing directory: storage/app/public/thumbnails", 'created_at' => now()],
        ]);

        config(['admin.ui' => 'v2']);
        $html = (string) $this->actingAs($this->admin)->withSession(['app_locale' => 'ar'])->get(route('admin.audit.index'))->assertOk()->getContent();

        app()->setLocale('ar');
        // the Arabic lines in use: lang/ar.json's once the lane's translations are merged, else this test's
        $this->assertNotSame('Plan changed', __('Plan changed'));
        $this->assertStringContainsString(e(__('Plan changed')), $html, 'the label');
        $this->assertStringContainsString(e(__('AVDBAPI import started')), $html, 'the label');
        // the fields by the names their form shows, joined with the Arabic comma
        $this->assertStringContainsString('<bdi>Gold (#3)</bdi>: '.e(__('Price')).'، '.e(__('Billed')), $html);
        // the old and the new name, each in its own direction, in the language's ":from to :to"
        $this->assertStringContainsString(strtr(e(__(':from to :to', ['from' => 'FROMNAME', 'to' => 'TONAME'])), ['FROMNAME' => '<bdi>sunset (#8)</bdi>', 'TONAME' => '<bdi>sunsets</bdi>']), $html);
        // a folder left to right, a count in words
        $this->assertStringContainsString('<bdi dir="ltr">storage/framework/cache</bdi>: '.e(trans_choice('1 file|:count files', 12, ['count' => '12'])), $html);
        $this->assertStringContainsString('<bdi>Français (fr)</bdi>: '.e(trans_choice('1 text changed|:count texts changed', 4, ['count' => '4'])), $html);
        $this->assertStringContainsString('4', trans_choice('1 text changed|:count texts changed', 4, ['count' => '4']));
        $this->assertStringContainsString('<bdi dir="ltr">#12</bdi>، '.e(__('Dry run only')), $html);
        // Auto-fix's lines in the Health check page's words, as the parts of one line: no ".؛" between two of them
        $this->assertStringContainsString(e(rtrim(__('Core directory and file permissions normalized.'), '.')).'؛ '
            .strtr(e(__('Created missing directory: :folder', ['folder' => 'FOLDERPATH'])), ['FOLDERPATH' => '<bdi dir="ltr">storage/app/public/thumbnails</bdi>']), $html);
        $this->assertStringNotContainsString('.؛', $html);

        // none of the stored English words is left
        foreach (['price, billing', ' -> ', '12 files', '4 texts', 'dry run', 'permissions normalized'] as $english) {
            $this->assertStringNotContainsString($english, $html);
        }
        app()->setLocale('en');
    }
}
