<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Enums\VideoStatus;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\SettingsPageController;
use App\Http\Controllers\Admin\XvidImportController;
use App\Models\AvdbImportSchedule;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Report;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Models\XvidImportSchedule;
use App\Support\AdminPreferences;
use App\Support\AdminTheme;
use App\Support\Color\Contrast;
use App\Support\Color\Oklch;
use App\Support\DatabaseBackup;
use App\Support\MasterExperienceCatalog;
use App\Support\ThemeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.2, lane A: the "Back up now" size, the plan upload limits on Money > Premium plans (typed in MB or GB,
 * stored in bytes), the ready-made XVIDAPI schedule ("Paused", its cron line, the next run when switched on), help
 * texts that say what the settings do, the old admin's Reports statuses, the red zone of the "Automatic" admin colour,
 * the XVIDAPI provider tab and the old admin's red outline buttons in dark mode, and the corrected translations.
 *
 * The last test needs the 1.2.2 translations applied to lang/*.json (the release build does that).
 */
class AdminLaneA122Test extends TestCase
{
    use RefreshDatabase;

    private ?string $storage = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The import pages ask their provider for its numbers: never over the network in a test.
        Http::fake();
    }

    protected function tearDown(): void
    {
        if ($this->storage !== null) {
            File::deleteDirectory($this->storage);
            $this->storage = null;
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------ Tools > Backups, "Back up now"

    public function test_back_up_now_says_the_size_the_backups_list_shows(): void
    {
        $this->useScratchStorage();
        // A dump of 27,034 bytes: the list says 26.4 KB, and 1.2.1's message said "(0.03 MB)".
        $this->app->instance(DatabaseBackup::class, new class extends DatabaseBackup {
            protected function viaMysqldump(string $binary, string $fullPath, bool $useGz): bool
            {
                return false;
            }

            protected function dumpAll(string $fullPath, bool $useGz): void
            {
                file_put_contents($fullPath, str_repeat('-', 27034));
            }
        });
        config(['admin.operational_tools_enabled' => true]);

        $response = $this->actingAs($this->admin())->post(route('admin.backups.create'))->assertRedirect(route('admin.backups.index'));

        $message = (string) session('success');
        $this->assertMatchesRegularExpression('/^Database backup created via Pure-PHP: backup_[\d_-]+\.sql(\.gz)? \(26\.4 KB\)$/', $message);
        $this->assertStringNotContainsString('MB', $message);

        $html = $this->newAdminPage(route('admin.backups.index'));
        $this->assertStringContainsString('<td class="c-meta is-num text-nowrap">26.4 KB<small', $html);
        unset($response);
    }

    #[DataProvider('sizes')]
    public function test_the_message_and_the_list_use_one_size_text(int $bytes, string $text): void
    {
        $this->assertSame($text, DatabaseBackup::sizeText($bytes));
        $this->assertSame($text, BackupController::sizeText($bytes));
    }

    public static function sizes(): array
    {
        return [
            '300 B' => [300, '300 B'],
            '26.4 KB' => [27034, '26.4 KB'],
            '35.3 KB' => [36149, '35.3 KB'],
            'just under 1 MB' => [1048575, '1 MB'],
            '3.38 MB' => [3544186, '3.38 MB'],
            '1.5 GB' => [1610612736, '1.5 GB'],
        ];
    }

    // ------------------------------------------------------------------ Money > Premium plans: upload limits

    public function test_the_premium_plans_page_shows_the_upload_limits_and_the_trial_in_mb_and_gb(): void
    {
        $this->storeLimits(['free_max_uploads' => '10', 'free_max_file_size' => '524288000', 'free_max_storage' => '5368709120',
            'premium_max_uploads' => '', 'premium_max_file_size' => '2147483648', 'premium_max_storage' => '',
            'trial_period_days' => '30', 'trial_email_reminder' => '7']);

        $html = $this->newAdminPage(route('admin.plans.index'));
        $xpath = $this->xpath($html);

        $this->assertStringContainsString('What each plan may upload', $html);
        $this->assertStringContainsString('Free trial', $html);
        $form = $xpath->query('//form[@data-settings-form]')->item(0);
        $this->assertNotNull($form, 'the page carries the settings form');
        $this->assertSame(route('admin.settings.save'), $form->getAttribute('action'));
        $this->assertSame(1, $xpath->query('//form[@data-settings-form]//*[@data-savebar]')->length, 'one save bar');

        foreach (['free_max_file_size' => ['500', 'MB'], 'free_max_storage' => ['5', 'GB'], 'premium_max_file_size' => ['2', 'GB'], 'premium_max_storage' => ['', 'MB']] as $key => [$number, $unit]) {
            $input = $xpath->query('//input[@name="groups[planlimits]['.$key.']"]')->item(0);
            $this->assertNotNull($input, $key);
            $this->assertSame($number, $input->getAttribute('value'), $key);
            $select = $xpath->query('//select[@name="units[planlimits]['.$key.']"]/option[@selected]')->item(0);
            $this->assertSame($unit, $select?->getAttribute('value'), $key.' unit');
            $this->assertSame(1, $xpath->query('//*[@id="'.$key.'-row" and @data-size]')->length, $key.' is a size row');
        }
        foreach (['free_max_uploads' => '10', 'premium_max_uploads' => '', 'trial_period_days' => '30', 'trial_email_reminder' => '7'] as $key => $value) {
            $this->assertSame($value, $xpath->query('//input[@name="groups[planlimits]['.$key.']"]')->item(0)?->getAttribute('value'), $key);
        }

        // The page posts every field it shows when the script does not run.
        $this->assertSame(
            'free_max_uploads,free_max_file_size,free_max_storage,premium_max_uploads,premium_max_file_size,premium_max_storage,trial_period_days,trial_email_reminder',
            $xpath->query('//input[@name="fields[planlimits]"]')->item(0)?->getAttribute('value')
        );
        $this->assertStringNotContainsString('In MB.', $html);
    }

    public function test_the_old_byte_values_of_a_site_show_without_rounding_errors(): void
    {
        // The values example.sql shipped up to 1.2.1, and one no whole number of MB.
        $this->assertSame('10000 MB', SettingsPageController::sizeFieldValue('10485760000'));
        $this->assertSame('10240 GB', SettingsPageController::sizeFieldValue('10995116277760'));
        $this->assertSame('100000 MB', SettingsPageController::sizeFieldValue('104857600000'));
        $this->assertSame('476.84 MB', SettingsPageController::sizeFieldValue('500000000'));
        $this->assertSame('1.5 GB', SettingsPageController::sizeFieldValue('1610612736'));
        $this->assertSame('0 MB', SettingsPageController::sizeFieldValue('0'));
        $this->assertSame('', SettingsPageController::sizeFieldValue(''));
        $this->assertSame('512 MB', SettingsPageController::sizeFieldValue('536870912'), 'under 1 GB stays MB');

        // A GB size with up to two decimals shows as it was typed (its bytes are rounded to the byte when stored).
        foreach (['1.2 GB', '1.1 GB', '2.3 GB', '1.33 GB', '1.01 GB', '999.99 GB', '1024 GB', '1 GB', '5 GB'] as $typed) {
            $this->assertSame($typed, SettingsPageController::sizeFieldValue((string) SettingsPageController::sizeBytes($typed)), $typed);
        }
        $this->assertSame(1288490189, SettingsPageController::sizeBytes('1.2 GB'));
        $this->assertSame('1.2 GB', SettingsPageController::sizeFieldValue('1288490188'), 'one byte off still shows 1.2 GB');
        $this->assertSame('1228.8 MB', SettingsPageController::sizeFieldValue('1288490000'), 'further off shows MB');
        $this->assertSame('1263.62 MB', SettingsPageController::sizeFieldValue((string) SettingsPageController::sizeBytes('1.234 GB')), 'more than two decimals of a GB show in MB');

        $this->assertSame(786432000, SettingsPageController::sizeBytes('750 MB'));
        $this->assertSame(1610612736, SettingsPageController::sizeBytes('1.5 GB'));
        $this->assertSame(2147483648, SettingsPageController::sizeBytes('2gb'));
        $this->assertSame(2147483648, SettingsPageController::sizeBytes('2', 'GB'));
        $this->assertSame(2097152, SettingsPageController::sizeBytes('2'));
        $this->assertNull(SettingsPageController::sizeBytes('two GB'));
        $this->assertNull(SettingsPageController::sizeBytes('1,5 GB'));
        $this->assertNull(SettingsPageController::sizeBytes('-5 MB'));
    }

    public function test_sizes_typed_in_mb_or_gb_are_stored_in_bytes(): void
    {
        $this->storeLimits(['free_max_file_size' => '524288000', 'free_max_storage' => '5368709120', 'premium_max_file_size' => '2147483648', 'premium_max_storage' => '1099511627776']);

        $this->actingAs($this->admin())
            ->putJson(route('admin.settings.save'), [
                'groups' => ['planlimits' => ['free_max_file_size' => '750 MB', 'free_max_storage' => '10 GB', 'premium_max_file_size' => '1.5 GB', 'premium_max_storage' => '', 'free_max_uploads' => '25']],
                'fields' => ['planlimits' => ['free_max_file_size', 'free_max_storage', 'premium_max_file_size', 'premium_max_storage', 'free_max_uploads']],
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'values' => ['planlimits' => [
                'free_max_file_size' => '750 MB', 'free_max_storage' => '10 GB', 'premium_max_file_size' => '1.5 GB', 'premium_max_storage' => '', 'free_max_uploads' => '25',
            ]]]);

        $stored = Setting::getGroup('planlimits');
        $this->assertSame('786432000', (string) $stored['free_max_file_size']);
        $this->assertSame('10737418240', (string) $stored['free_max_storage']);
        $this->assertSame('1610612736', (string) $stored['premium_max_file_size']);
        $this->assertSame('', (string) $stored['premium_max_storage'], 'empty = no limit');
        $this->assertSame('25', (string) $stored['free_max_uploads']);
    }

    public function test_a_form_posted_without_javascript_sends_the_unit_beside_the_number(): void
    {
        $this->storeLimits(['free_max_file_size' => '524288000', 'free_max_storage' => '5368709120', 'premium_max_file_size' => '2147483648', 'premium_max_storage' => '']);

        $this->actingAs($this->admin())
            ->from(route('admin.plans.index'))
            ->put(route('admin.settings.save'), [
                'groups' => ['planlimits' => ['free_max_file_size' => '2', 'free_max_storage' => '5', 'premium_max_file_size' => '2', 'premium_max_storage' => '']],
                'units' => ['planlimits' => ['free_max_file_size' => 'GB', 'free_max_storage' => 'GB', 'premium_max_file_size' => 'GB', 'premium_max_storage' => 'MB']],
                'fields' => ['planlimits' => 'free_max_file_size,free_max_storage,premium_max_file_size,premium_max_storage'],
            ])
            ->assertRedirect(route('admin.plans.index'))
            ->assertSessionHasNoErrors();

        $stored = Setting::getGroup('planlimits');
        $this->assertSame('2147483648', (string) $stored['free_max_file_size']);
        $this->assertSame('5368709120', (string) $stored['free_max_storage']);
    }

    public function test_a_size_sent_back_as_the_page_showed_it_keeps_its_exact_bytes(): void
    {
        $this->storeLimits(['free_max_file_size' => '500000000']);
        $save = fn (string $value) => $this->actingAs($this->admin())->putJson(route('admin.settings.save'), [
            'groups' => ['planlimits' => ['free_max_file_size' => $value]],
            'fields' => ['planlimits' => ['free_max_file_size']],
        ])->assertOk();

        $save('476.84 MB');
        $this->assertSame('500000000', (string) Setting::getGroup('planlimits')['free_max_file_size']);

        $save('476.85 MB');
        $this->assertSame((string) (int) round(476.85 * 1048576), (string) Setting::getGroup('planlimits')['free_max_file_size']);
    }

    public function test_a_gb_size_with_decimals_still_shows_in_gb_after_saving(): void
    {
        $this->storeLimits(['free_max_file_size' => '524288000']);
        $save = fn (string $value) => $this->actingAs($this->admin())->putJson(route('admin.settings.save'), [
            'groups' => ['planlimits' => ['free_max_file_size' => $value]],
            'fields' => ['planlimits' => ['free_max_file_size']],
        ])->assertOk();

        $save('1.2 GB')->assertJson(['ok' => true, 'values' => ['planlimits' => ['free_max_file_size' => '1.2 GB']]]);
        $this->assertSame('1288490189', (string) Setting::getGroup('planlimits')['free_max_file_size']);

        // An exact byte value from the old admin that shows as 1.2 GB keeps its bytes when the page sends it back.
        $this->storeLimits(['free_max_file_size' => '1288490188']);
        $save('1.2 GB')->assertJson(['values' => ['planlimits' => ['free_max_file_size' => '1.2 GB']]]);
        $this->assertSame('1288490188', (string) Setting::getGroup('planlimits')['free_max_file_size']);

        $xpath = $this->xpath($this->newAdminPage(route('admin.plans.index')));
        $this->assertSame('1.2', $xpath->query('//input[@name="groups[planlimits][free_max_file_size]"]')->item(0)?->getAttribute('value'));
        $this->assertSame('GB', $xpath->query('//select[@name="units[planlimits][free_max_file_size]"]/option[@selected]')->item(0)?->getAttribute('value'));
    }

    public function test_size_limits_are_checked_on_the_bytes_the_size_makes(): void
    {
        $this->storeLimits(['free_max_file_size' => '524288000']);
        $cases = [
            '0.5 MB' => 'Use 1 MB or more.',
            '2000 GB' => 'Use 1 TB or less.',
            'big' => 'Use a size like 500 MB or 2 GB.',
            '' => 'This cannot be empty.',
        ];

        foreach ($cases as $value => $message) {
            $this->actingAs($this->admin())
                ->putJson(route('admin.settings.save'), [
                    'groups' => ['planlimits' => ['free_max_file_size' => $value]],
                    'fields' => ['planlimits' => ['free_max_file_size']],
                ])
                ->assertStatus(422)
                ->assertJsonPath('errors', ['planlimits.free_max_file_size' => [$message]]);
        }

        $this->assertSame('524288000', (string) Setting::getGroup('planlimits')['free_max_file_size'], 'nothing was saved');
    }

    public function test_the_old_settings_page_still_saves_the_limits_in_bytes(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/admin/settings/update/planlimits', [
                'free_max_uploads' => 10, 'free_max_file_size' => 104857600, 'free_max_storage' => 1073741824,
                'premium_max_uploads' => '', 'premium_max_file_size' => 2147483648, 'premium_max_storage' => '',
                'trial_period_days' => 30, 'trial_email_reminder' => 7,
            ])
            ->assertOk();

        $this->assertSame('104857600', (string) Setting::getGroup('planlimits')['free_max_file_size']);
    }

    public function test_the_limit_help_texts_say_what_the_site_does(): void
    {
        $help = fn (string $key): string => (string) config("admin_settings.settings.{$key}.help");

        // 0 stops every upload of a free member (VideoPolicy::upload compares uploaded videos >= the limit).
        $this->assertSame('How many videos one free member may upload. 0 = free members cannot upload.', $help('free_max_uploads'));
        config(['plans.free.max_uploads' => 0, 'site.allow_uploads' => true]);
        $member = User::factory()->create();
        $this->assertFalse(Gate::forUser($member)->allows('upload', Video::class), 'a free member cannot upload with 0');

        foreach (['free_max_file_size', 'free_max_storage', 'premium_max_file_size', 'premium_max_storage'] as $key) {
            $this->assertSame('size', config("admin_settings.settings.{$key}.control"), $key);
            $this->assertStringNotContainsString('In MB', $help($key), $key);
        }
        $this->assertStringStartsWith('Only shown on the Premium page', $help('premium_max_uploads'));
        $this->assertStringStartsWith('Only shown on the Premium page', $help('premium_max_storage'));
        $this->assertSame('Free premium days before the first card payment, for members who never had premium. 0 = no trial.', $help('trial_period_days'));
    }

    // ------------------------------------------------------------------ XVIDAPI ready-made schedule

    public function test_the_ready_made_xvidapi_schedule_is_paused_with_no_next_run_and_an_hourly_cron_line(): void
    {
        $schedule = XvidImportController::ensureDefaultScheduleExists();

        $this->assertNotNull($schedule);
        $schedule = $schedule->fresh();
        $this->assertSame('Hourly XVIDAPI Video Sync', $schedule->name);
        $this->assertFalse($schedule->is_active);
        $this->assertNull($schedule->next_run_at, 'no next run while paused');
        $this->assertSame('Hourly at :30', $schedule->scheduleLabel());
        $this->assertSame('30 * * * *', $schedule->getRawOriginal('expression'), 'the cron line agrees with "Hourly" (the table default is daily 03:00)');

        // The scheduler leaves it alone.
        $this->artisan('xvid:schedules:run')->assertExitCode(0);
        $this->assertSame(0, \App\Models\XvidImportRun::count());
    }

    public function test_a_paused_schedule_of_an_existing_site_shows_paused_not_a_time_in_the_past(): void
    {
        // A row as 1.2.1 created it: next run one minute before it was made, days ago.
        XvidImportSchedule::create([
            'name' => 'Hourly XVIDAPI Video Sync', 'is_active' => false, 'categories' => [65], 'pages' => 1, 'start_page' => 1, 'pagesize' => 50, 'delay' => 0,
            'settings' => ['frequency' => 'hourly', 'interval_minutes' => 60, 'run_time' => '00:30', 'categories' => [65]],
            'next_run_at' => now()->subMinutes(12),
        ]);
        XvidImportSchedule::create([
            'name' => 'Daily new videos', 'is_active' => true, 'categories' => [65], 'pages' => 1, 'start_page' => 1, 'pagesize' => 50, 'delay' => 0,
            'settings' => ['frequency' => 'daily', 'run_time' => '03:00', 'categories' => [65]],
            'next_run_at' => now()->addHours(5),
        ]);

        $xpath = $this->xpath($this->newAdminPage(route('admin.xvid-import.index')));
        $rows = $xpath->query('//div[@id="schedules-pane"]//tbody/tr');
        $this->assertSame(2, $rows->length);
        $cells = [];
        foreach ($rows as $row) {
            $tds = $xpath->query('./td', $row);
            $cells[trim($tds->item(0)->textContent)] = [trim($tds->item(2)->textContent), trim($tds->item(3)->textContent)];
        }
        $this->assertSame(['Paused', 'Paused'], $cells['Hourly XVIDAPI Video Sync']);
        $this->assertMatchesRegularExpression('/^(4|5) hours from now$/', $cells['Daily new videos'][0]);
        $this->assertSame('Active', $cells['Daily new videos'][1]);
    }

    public function test_switching_a_schedule_on_works_out_its_next_run_from_its_timing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-24 11:10:00'));
        $schedule = XvidImportController::ensureDefaultScheduleExists();
        $schedule->forceFill(['next_run_at' => Carbon::parse('2026-10-11 15:54:30')])->save();

        $this->actingAs($this->admin())->post(route('admin.xvid-import.schedules.toggle', $schedule))->assertSessionHas('success', 'Schedule enabled.');
        $schedule->refresh();
        $this->assertTrue($schedule->is_active);
        $this->assertSame('2026-10-24 11:30:00', $schedule->next_run_at->format('Y-m-d H:i:s'), 'the next :30, not 2 weeks ago');

        $this->actingAs($this->admin())->post(route('admin.xvid-import.schedules.toggle', $schedule))->assertSessionHas('success', 'Schedule paused.');
        $schedule->refresh();
        $this->assertFalse($schedule->is_active);
        $this->assertNull($schedule->next_run_at);
        Carbon::setTestNow();
    }

    public function test_a_new_schedule_stores_the_cron_line_of_its_timing(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('admin.xvid-import.schedules.store'), ['name' => 'Morning', 'frequency' => 'daily', 'run_time' => '06:15', 'pages' => 1, 'start_page' => 1, 'pagesize' => 50, 'delay' => 0])
            ->assertOk();

        $this->assertSame('15 6 * * *', XvidImportSchedule::where('name', 'Morning')->value('expression'));

        $this->assertSame('30 * * * *', XvidImportController::cronExpression(['frequency' => 'hourly', 'run_time' => '00:30']));
        $this->assertSame('0 3 * * 1', XvidImportController::cronExpression(['frequency' => 'weekly', 'run_time' => '03:00', 'weekday' => 1]));
        $this->assertSame('0 3 1 * *', XvidImportController::cronExpression(['frequency' => 'monthly', 'run_time' => '03:00', 'month_day' => 1]));
        $this->assertSame('*/15 * * * *', XvidImportController::cronExpression(['frequency' => 'interval', 'interval_minutes' => 15]));
        $this->assertSame('0 */2 * * *', XvidImportController::cronExpression(['frequency' => 'interval', 'interval_minutes' => 120]));
    }

    public function test_the_avdbapi_ready_made_schedule_shows_paused_too(): void
    {
        AvdbImportSchedule::query()->delete();
        $schedule = new AvdbImportSchedule();
        $schedule->forceFill([
            'name' => 'Hourly Automatic Video Sync', 'enabled' => false, 'frequency' => 'hourly', 'run_time' => '00:15', 'interval_minutes' => 60,
            'skip_if_active' => true, 'settings' => ['categories' => [1], 'pages' => 1], 'next_run_at' => now()->subWeeks(2),
        ])->save();

        $html = $this->newAdminPage(route('admin.avdb-import.index'));

        $this->assertStringNotContainsString('2 weeks ago', $html);
        $this->assertMatchesRegularExpression('#<div>Paused</div>#', $html);
    }

    // ------------------------------------------------------------------ Help texts

    public function test_help_texts_say_what_the_settings_really_do(): void
    {
        $setting = fn (string $key): array => config("admin_settings.settings.{$key}");

        $this->assertStringContainsString('nobody can upload, administrators too', $setting('allow_uploads')['help']);
        $this->assertStringContainsString('Appearance > Header, menus & footer', $setting('allow_uploads')['help']);
        $this->assertSame('Shown to every signed-in member, also while uploads are switched off.', $setting('header_upload_button_enabled')['help']);
        $this->assertStringStartsWith("How many requests one member's uploads may send to your site per minute", $setting('upload_rate_limit')['help']);
        $this->assertSame('Ad break length', $setting('ad_preroll_duration')['label']);
        $this->assertSame('seconds', $setting('ad_preroll_duration')['suffix']);
        $this->assertStringContainsString('Viewers can skip it after 3 seconds.', $setting('ad_preroll_duration')['help']);
        // "Ad after every" keeps its words: the list puts that many videos before an ad (lane F).
        $this->assertSame('Number of videos before an ad appears in video lists.', $setting('ad_grid_position')['help']);

        // The Ctrl+K rows are generated from the same texts.
        $rows = collect(json_decode((string) file_get_contents(resource_path('admin/lib/settings-index.json')), true))->keyBy('key');
        foreach (['allow_uploads', 'header_upload_button_enabled', 'upload_rate_limit', 'ad_preroll_duration', 'free_max_uploads', 'free_max_file_size', 'premium_max_storage', 'trial_period_days'] as $key) {
            $this->assertSame($setting($key)['label'], $rows[$key]['label'], $key);
            $this->assertSame($setting($key)['help'], $rows[$key]['help'], $key);
        }
        $this->assertSame('Pre-roll Duration', $rows['ad_preroll_duration']['old'], 'search still finds the old name');
    }

    public function test_the_skip_button_really_works_after_three_seconds_of_the_ad_break(): void
    {
        $player = (string) file_get_contents(resource_path('js/components/Videos/Ad.tsx'));

        $this->assertStringContainsString('const skipAfter = Math.min(3, Math.max(1, duration - 1));', $player);
        $this->assertStringContainsString('Math.max(1, Math.min(60, configuredDuration))', $player);
    }

    public function test_the_avdb_language_help_no_longer_promises_a_background_backfill(): void
    {
        $html = $this->newAdminPage(route('admin.avdb-import.index'));

        $this->assertStringNotContainsString('backfill', $html);
        $this->assertStringContainsString('Each video is imported with its title in all of these languages, fetched from AVDB during the import.', $html);
        $this->assertStringContainsString('A video without a title in one of these languages is skipped. Nothing is fetched later in the background.', $html);
    }

    // ------------------------------------------------------------------ Old admin Reports

    public function test_the_old_reports_list_offers_block_and_resolve_by_the_real_status(): void
    {
        $site = $this->reportedComments();
        config(['admin.ui' => 'v1']);

        $html = (string) $this->actingAs($site['admin'])->get(route('admin.reports.index'))->assertOk()->getContent();
        $block = fn (Comment $c): string => 'action="'.route('admin.reports.block', ['Comment', $c->id]).'"';
        $resolve = fn (Comment $c): string => 'action="'.route('admin.reports.cancel', ['Comment', $c->id]).'"';

        $this->assertStringContainsString($block($site['pending']), $html);
        $this->assertStringContainsString($resolve($site['pending']), $html);
        $this->assertStringNotContainsString($block($site['blocked']), $html, 'a blocked comment is not offered Block again');
        $this->assertStringContainsString($resolve($site['blocked']), $html);
        $this->assertStringContainsString($block($site['cancelled']), $html);
        $this->assertStringNotContainsString($resolve($site['cancelled']), $html, 'a resolved report is not offered Resolve again');
        // The badge counts the reports that wait, not every report ("3 pending").
        $this->assertStringContainsString('1 report waiting', $html);
        $this->assertStringNotContainsString('3 pending', $html);
    }

    public function test_bulk_resolve_writes_the_cancelled_status_number(): void
    {
        $site = $this->reportedComments();
        config(['admin.ui' => 'v1']);
        $ids = Report::query()->pluck('id')->all();

        $this->actingAs($site['admin'])
            ->post(route('admin.bulk.reports'), ['ids' => $ids, 'action' => 'resolve'])
            ->assertRedirect(route('admin.reports.index'))
            ->assertSessionHas('success', 'Resolved 2 reports.');

        foreach (Report::query()->get() as $report) {
            $this->assertSame(ReportStatus::CANCELLED, $report->status);
        }
        $this->assertSame([ReportStatus::CANCELLED->value], \Illuminate\Support\Facades\DB::table('reports')->distinct()->pluck('status')->map(fn ($s) => (int) $s)->all());
    }

    // ------------------------------------------------------------------ Admin colour "Automatic"

    public static function filmReds(): array
    {
        return [
            'Cinema Night #e50914' => ['#e50914'], 'Tube red #e5252a' => ['#e5252a'], 'Broadsheet #b3001b' => ['#b3001b'],
            'coral #ff6f61' => ['#ff6f61'], 'vermilion #c8381f' => ['#c8381f'], 'canyon #e25b3c' => ['#e25b3c'],
            'alert #ff4d4d' => ['#ff4d4d'],
        ];
    }

    #[DataProvider('filmReds')]
    public function test_red_site_colours_take_the_neutral_admin_accent(string $hex): void
    {
        foreach (['light', 'dark'] as $mode) {
            $neutral = AdminTheme::accentTokens(Contrast::NEUTRAL_ACCENT, $mode)['accent'];
            $this->assertSame($neutral, AdminTheme::accentTokens($hex, $mode)['accent'], "{$hex} {$mode}");
            $this->assertNotSame($neutral, AdminTheme::accentTokens($hex, $mode, true)['accent'], "{$hex} {$mode}: the opt-in keeps the site colour");
        }
        $this->assertTrue(Contrast::hueInRedZone($hex));
    }

    public function test_oranges_keep_their_own_admin_accent(): void
    {
        foreach (['#ff5a1f', '#c2410c', '#e8590c', '#ff6600', '#e2774a'] as $hex) {
            $this->assertFalse(Contrast::hueInRedZone($hex), $hex);
            $this->assertNotSame(AdminTheme::accentTokens(Contrast::NEUTRAL_ACCENT, 'light')['accent'], AdminTheme::accentTokens($hex, 'light')['accent'], $hex);
        }
    }

    public function test_pastel_rose_keeps_the_admin_accent_it_had_in_1_2_1(): void
    {
        // Velvet Lounge and the rose flavours (#e8a598: hue 31, chroma 0.08) are no red: their accent stays rose.
        [, $chroma, $hue] = Oklch::toOklch(Oklch::hex('#e8a598'));
        $this->assertTrue($hue > Contrast::RED_ZONE_EDGE && $hue <= Contrast::RED_ZONE[1] && $chroma < Contrast::RED_ZONE_EDGE_MIN_CHROMA);
        $this->assertFalse(Contrast::hueInRedZone('#e8a598'));
        $this->assertSame('#c58478', AdminTheme::accentTokens('#e8a598', 'light')['accent']);
        $this->assertSame('#c58478', Contrast::adminAccent('#e8a598', 'light', '#ffffff', '#f6f7f9')['accent']);
        $this->assertNotSame(AdminTheme::accentTokens(Contrast::NEUTRAL_ACCENT, 'dark')['accent'], AdminTheme::accentTokens('#e8a598', 'dark')['accent']);
        $this->assertSame('#e8a598', MasterExperienceCatalog::find('velvet_lounge')['apply_settings']['primary_color']);

        // A strong red at the same hue still moves, and the old zone (up to 25) still counts every chroma from 0.04.
        $this->assertTrue(Contrast::hueInRedZone('#c8381f'));
        $this->assertTrue(Contrast::hueInRedZone('#d98c8c'), 'a muted red under hue 25 stayed in the zone');
    }

    public function test_among_the_32_themes_and_12_looks_only_reds_move_to_the_neutral_accent(): void
    {
        $this->assertSame([330.0, 35.0], Contrast::RED_ZONE);
        $colours = [];
        foreach (ThemeCatalog::themes() as $theme) {
            foreach ($theme['flavors'] as $flavor) {
                $colours[] = strtolower($flavor['colours']['primary_color']);
            }
        }
        foreach (MasterExperienceCatalog::all() as $look) {
            $colours[] = strtolower($look['apply_settings']['primary_color']);
        }
        $this->assertCount(186, $colours, '174 flavours and 12 looks');

        $moved = [];
        foreach (array_unique($colours) as $hex) {
            [, $chroma, $hue] = Oklch::toOklch(Oklch::hex($hex));
            $wasRed = $chroma >= 0.04 && ($hue >= 330 || $hue <= 25);
            if (Contrast::hueInRedZone($hex) !== $wasRed) {
                $moved[] = $hex;
                $this->assertTrue($hue > 25 && $hue <= 35 && $chroma >= 0.1, "{$hex} hue {$hue} chroma {$chroma}");
            }
        }
        sort($moved);
        $this->assertSame(['#b3001b', '#c8381f', '#e25b3c', '#e50914', '#e5252a', '#ff4d4d', '#ff6f61'], $moved);
        $this->assertNotContains('#e8a598', $moved, 'pastel rose (Velvet Lounge) keeps its accent');
    }

    public function test_after_publishing_cinema_night_the_automatic_admin_colour_is_blue(): void
    {
        $look = MasterExperienceCatalog::find('netflix_cinema');
        $this->assertSame('#e50914', $look['apply_settings']['primary_color']);
        Setting::set('primary_color', '#e50914', 'appearance');
        Cache::flush();

        $accent = AdminTheme::accentFor($this->admin());

        $this->assertSame('#1f5bc7', $accent['light']['accent']);
        $this->assertSame('#e50914', $accent['brand'], 'the logo dot keeps the site colour');
    }

    // ------------------------------------------------------------------ Contrast

    public function test_the_xvidapi_provider_tab_reads_at_least_4_5_to_1(): void
    {
        $view = (string) file_get_contents(resource_path('views/admin/xvid-import/index.blade.php'));
        $this->assertSame(1, preg_match('/\.xvid-provider-tab\.active \{\s*background: linear-gradient\(135deg, (#[0-9a-f]{6}), (#[0-9a-f]{6})\);/', $view, $stops));

        foreach ([$stops[1], $stops[2]] as $stop) {
            $this->assertGreaterThanOrEqual(4.5, Contrast::contrast('#ffffff', $stop), $stop);
        }
    }

    public function test_the_old_admin_red_outline_buttons_read_at_least_4_5_to_1_in_dark_mode(): void
    {
        $scss = (string) file_get_contents(resource_path('sass/dark.scss'));
        $this->assertSame(1, preg_match('/\[data-bs-theme="dark"\] body\.avd-admin \.btn-outline-danger \{\s*--bs-btn-color: (#[0-9a-f]{6});\s*--bs-btn-border-color: \1;/', $scss, $rule));

        // The dark surfaces the old admin's import and backup pages measured with Bootstrap's #dc3545 (3.5-4.3:1).
        foreach (['#0b0e15', '#12151e', '#171b26', '#1f2128', '#1a1a24', '#212529', '#242b3d'] as $surface) {
            $this->assertGreaterThanOrEqual(4.5, Contrast::contrast($rule[1], $surface), $surface);
            $this->assertLessThan(4.5, Contrast::contrast('#dc3545', $surface), "{$surface} was too low before");
        }
        // Hover fills the button: white on Bootstrap's red.
        $this->assertGreaterThanOrEqual(4.5, Contrast::contrast('#ffffff', '#dc3545'));
    }

    // ------------------------------------------------------------------ Translations (after i18n.json is applied)

    public function test_profile_random_director_and_tag_name_use_the_right_words(): void
    {
        $expected = [
            'Profile' => ['ko' => '프로필', 'zh' => '个人资料', 'th' => 'โปรไฟล์', 'ar' => 'الملف الشخصي'],
            'Random' => ['ko' => '무작위', 'zh' => '随机'],
            'Director' => ['ar' => 'المخرج', 'fr' => 'Réalisateur', 'ko' => '감독', 'ru' => 'Режиссёр', 'th' => 'ผู้กำกับ', 'vi' => 'Đạo diễn', 'id' => 'Sutradara', 'tr' => 'Yönetmen', 'hi' => 'निर्देशक', 'ja' => '監督', 'es' => 'Dirección'],
            'Tag name' => ['fr' => "Nom de l'étiquette", 'id' => 'Nama tag', 'ms' => 'Nama teg'],
        ];

        foreach ($expected as $key => $locales) {
            foreach ($locales as $locale => $text) {
                $data = json_decode((string) file_get_contents(lang_path($locale.'.json')), true);
                $this->assertSame($text, $data[$key] ?? null, "{$locale}: {$key}");
            }
        }
    }

    public function test_every_language_has_the_new_texts_and_no_broken_tag_names(): void
    {
        $added = [
            'Use a size like 500 MB or 2 GB.', 'Due now', 'Fast mode', 'Ad break length',
            'How many videos one free member may upload. 0 = free members cannot upload.',
            'Free premium days before the first card payment, for members who never had premium. 0 = no trial.',
        ];
        $removed = ['Skip button after', 'In MB.', 'In MB. Leave empty for no limit.', 'Fast + background exact translations', 'Schedule status updated.'];

        $files = glob(lang_path('*.json'));
        $this->assertCount(18, $files);
        foreach ($files as $file) {
            $locale = basename($file, '.json');
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            foreach ($added as $key) {
                $this->assertArrayHasKey($key, $data, "{$locale} lacks \"{$key}\"");
                if ($locale !== 'en') {
                    $this->assertNotSame($key, $data[$key], "{$locale} still shows \"{$key}\" in English");
                }
            }
            foreach ($removed as $key) {
                $this->assertArrayNotHasKey($key, $data, "{$locale} still has \"{$key}\"");
            }
            // Tag names reach visitors through Tag::localizedName(): no machine translator's glosses or notes
            // ("X - No translation (character set not recognized)", "Keine Übersetzung möglich", "Sq - Não traduzível
            // (...)", "Masumi\"? Keep as name or ...").
            $note = '/no (direct |exact )?translation|not translated|keine (direkte )?übersetzung|nicht übersetzbar|character set not recogni[sz]ed|possibly a (code|proper)|kein direktes deutsches|bleibt gleich, da|não traduz|no tiene traducción|non traduit|لا يوجد ترجمة|لا ترجمة|अनुवाद नहीं|short for "|\bI\'ll\b|"\? |\bassuming\b|let\'s make|keep as name|\(viết tắt|\(abreviação|\(singkatan\)|줄임\)|\(code produit\)/iu';
            foreach ($data as $key => $value) {
                if (! is_string($value) || $locale === 'en') {
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression('/^(.{2,}?) - \1(.*)$/s', $value, "{$locale}: {$key}");
                if ($value !== $key && ! preg_match('/translat|language/i', $key) && ! preg_match($note, $key)) {
                    $this->assertDoesNotMatchRegularExpression($note, $value, "{$locale}: {$key}");
                }
            }
        }

        $de = json_decode((string) file_get_contents(lang_path('de.json')), true);
        $ms = json_decode((string) file_get_contents(lang_path('ms.json')), true);
        $this->assertSame('Japan', $de['Japan'] ?? 'Japan');
        $this->assertStringNotContainsString('Rogol', $ms['Comes With Bonus Footage Only For Mgs'] ?? '');

        // The garbled names and codes the translator could not read keep their own name.
        $es = json_decode((string) file_get_contents(lang_path('es.json')), true);
        $tl = json_decode((string) file_get_contents(lang_path('tl.json')), true);
        $this->assertSame('µ»ÆΘ╛ì', $es['µ»ÆΘ╛ì'] ?? 'µ»ÆΘ╛ì');
        $this->assertSame('N_650SGOR0016', $de['N_650SGOR0016'] ?? 'N_650SGOR0016');
        $this->assertSame('Masumi', $tl['Masumi'] ?? 'Masumi');

        // The Korean "Tag" column header of Content > Tags says 태그 like every other Korean tag text.
        $ko = json_decode((string) file_get_contents(lang_path('ko.json')), true);
        $this->assertSame('태그', $ko['Tag']);
        $this->assertSame([], array_keys(array_filter($ko, fn ($value) => is_string($value) && str_contains($value, '꼬리표'))));
    }

    // ------------------------------------------------------------------ helpers

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** @param array<string, string> $values */
    private function storeLimits(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::set($key, $value, 'planlimits');
        }
        Setting::clearCache();
    }

    private function useScratchStorage(): void
    {
        $this->storage = storage_path('framework/testing/lane-a122-'.Str::uuid());
        File::ensureDirectoryExists($this->storage.'/app/backups');
        $this->app->useStoragePath($this->storage);
    }

    private function newAdminPage(string $url): string
    {
        config(['admin.ui' => 'v2', 'admin.operational_tools_enabled' => true]);
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);

        return (string) $this->actingAs($admin)->get($url)->assertOk()->getContent();
    }

    /**
     * An administrator and three reported comments: one report waits, one comment was blocked, one report resolved.
     *
     * @return array{admin: User, pending: Comment, blocked: Comment, cancelled: Comment}
     */
    private function reportedComments(): array
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $reporter = User::factory()->create();
        $category = Category::query()->firstOrCreate(['slug' => 'lane-a122'], ['title' => 'Lane A 1.2.2']);
        $video = Video::factory()->for($member)->create(['category_id' => $category->id, 'status' => VideoStatus::PUBLIC]);
        $comments = [];
        foreach (['pending' => ReportStatus::PENDING, 'blocked' => ReportStatus::BLOCKED, 'cancelled' => ReportStatus::CANCELLED] as $name => $status) {
            $comments[$name] = Comment::query()->create(['video_id' => $video->id, 'user_id' => $member->id, 'content' => 'A '.$name.' comment', 'ip' => '127.0.0.1']);
            Report::query()->create(['user_id' => $reporter->id, 'reportable_type' => Comment::class, 'reportable_id' => $comments[$name]->id, 'reason' => 1, 'status' => $status]);
        }

        return ['admin' => $admin] + $comments;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new \DOMXPath($document);
    }
}
