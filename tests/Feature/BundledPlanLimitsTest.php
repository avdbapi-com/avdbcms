<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Setting;
use App\Providers\AppServiceProvider;
use App\Support\NumberFormat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Plan upload limits (1.2.2). Up to 1.2.1 the bundled example.sql stored the test site's limits (free members:
 * 1,000,000 videos, 9.77 GB per file, 10 TB in all; premium members: 97.7 GB per file). Those rows override
 * config/plans.php, so its defaults never applied and the Premium page of a new site showed those numbers.
 * 1.2.2 ships the defaults of config/plans.php in example.sql, and migration 2026_10_24_120000 gives an existing
 * site the same values only where a row still holds exactly the bundled value.
 */
class BundledPlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_24_120000_correct_bundled_plan_upload_limits';

    /** The planlimits rows of example.sql up to 1.2.1. */
    private const BUNDLED_121 = [
        'free_max_uploads' => '1000000',
        'free_max_file_size' => '10485760000',
        'free_max_storage' => '10995116277760',
        'premium_max_uploads' => '',
        'premium_max_file_size' => '104857600000',
        'premium_max_storage' => '',
        'trial_period_days' => '30',
        'trial_email_reminder' => '7',
    ];

    /** The planlimits rows of example.sql from 1.2.2 on: the defaults of config/plans.php. */
    private const SHIPPED_122 = [
        'free_max_uploads' => '10',
        'free_max_file_size' => '524288000',
        'free_max_storage' => '5368709120',
        'premium_max_uploads' => '',
        'premium_max_file_size' => '2147483648',
        'premium_max_storage' => '',
        'trial_period_days' => '30',
        'trial_email_reminder' => '7',
    ];

    private const SEEDED_AT = '2026-05-02 11:18:31';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_example_sql_ships_the_defaults_of_config_plans(): void
    {
        $this->assertSame(self::SHIPPED_122, $this->exampleSqlRows('planlimits'), 'the planlimits rows of database/install/example.sql');

        $plans = require base_path('config/plans.php');
        $this->assertSame([
            'free_max_uploads' => (string) $plans['free']['max_uploads'],
            'free_max_file_size' => (string) $plans['free']['max_file_size'],
            'free_max_storage' => (string) $plans['free']['max_videos_storage'],
            'premium_max_uploads' => (string) $plans['premium']['max_uploads'],
            'premium_max_file_size' => (string) $plans['premium']['max_file_size'],
            'premium_max_storage' => (string) $plans['premium']['max_videos_storage'],
            'trial_period_days' => (string) $plans['trial_period']['period'],
            'trial_email_reminder' => (string) $plans['trial_period']['email_reminder'],
        ], self::SHIPPED_122, 'example.sql and config/plans.php agree');

        $this->assertSame('500 MB', NumberFormat::fileSize((int) self::SHIPPED_122['free_max_file_size']));
        $this->assertSame('5 GB', NumberFormat::fileSize((int) self::SHIPPED_122['free_max_storage']));
        $this->assertSame('2 GB', NumberFormat::fileSize((int) self::SHIPPED_122['premium_max_file_size']));
    }

    public function test_example_sql_ships_no_demo_ad_code_under_the_old_or_the_new_class_name(): void
    {
        // 1.2.1 renamed the demo ad class avd-test-ad to avd-demo-ad (seed migrations + stylesheet). example.sql
        // stores every ad slot empty, so neither name is in it and a new site shows no demo ads from it.
        $sql = (string) file_get_contents(database_path('install/example.sql'));
        $this->assertStringNotContainsString('avd-test-ad', $sql);
        $this->assertStringNotContainsString('avd-demo-ad', $sql);

        $codes = array_filter($this->exampleSqlRows('monetization'), fn (string $key): bool => str_starts_with($key, 'ad_code_'), ARRAY_FILTER_USE_KEY);
        $this->assertCount(19, $codes);
        $this->assertSame([], array_filter($codes, fn (string $code): bool => $code !== ''), 'every ad slot of example.sql is empty');
    }

    public function test_the_migration_corrects_exactly_the_bundled_values_to_the_shipped_defaults(): void
    {
        $corrections = $this->migration()::CORRECTIONS;

        $this->assertSame(['free_max_uploads', 'free_max_file_size', 'free_max_storage', 'premium_max_file_size'], array_keys($corrections));

        foreach ($corrections as $key => [$bundled, $default]) {
            $this->assertSame(self::BUNDLED_121[$key], $bundled, "{$key}: the value example.sql shipped up to 1.2.1");
            $this->assertSame(self::SHIPPED_122[$key], $default, "{$key}: the value example.sql ships now");
        }

        // The rows the migration does not name were already the defaults.
        foreach (array_diff_key(self::BUNDLED_121, $corrections) as $key => $value) {
            $this->assertSame(self::SHIPPED_122[$key], $value, $key);
        }
    }

    public function test_a_121_site_with_the_bundled_values_gets_the_defaults_once(): void
    {
        $this->seedPlanLimits(self::BUNDLED_121);
        Setting::set('site_name', 'My Video Site', 'general');
        $other = DB::table('settings')->where('group', '!=', 'planlimits')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        Carbon::setTestNow('2026-11-01 10:00:00');
        $this->migration()->up();

        $this->assertSame(self::SHIPPED_122, $this->storedPlanLimits());
        foreach (array_keys($this->migration()::CORRECTIONS) as $key) {
            $this->assertSame('2026-11-01 10:00:00', (string) DB::table('settings')->where('key', $key)->value('updated_at'), "{$key} records when it changed");
        }
        foreach (['premium_max_uploads', 'premium_max_storage', 'trial_period_days', 'trial_email_reminder'] as $key) {
            $this->assertSame(self::SEEDED_AT, (string) DB::table('settings')->where('key', $key)->value('updated_at'), "{$key} is not touched");
        }
        $this->assertSame($other, DB::table('settings')->where('group', '!=', 'planlimits')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), 'no other setting changes');

        // Tools › Logs › Admin actions says what changed, in full in both admins' lists.
        $audit = DB::table('admin_audit_logs')->where('action', 'update.plan_limits_defaults')->get();
        $this->assertCount(1, $audit);
        $this->assertNull($audit[0]->user_id);
        $this->assertSame('1.2.2: package upload limits set to the defaults: free 10 videos, 500 MB per file, 5 GB in all; premium 2 GB per file', $audit[0]->details);
        $this->assertFitsTheAdminLists($audit[0]->details);

        // Safe to run again: nothing matches any more, nothing is written.
        Carbon::setTestNow('2026-11-02 10:00:00');
        $this->migration()->up();
        $this->assertSame(self::SHIPPED_122, $this->storedPlanLimits());
        $this->assertSame(0, DB::table('settings')->where('updated_at', '2026-11-02 10:00:00')->count());
        $this->assertSame(1, DB::table('admin_audit_logs')->count());

        // A rollback keeps the corrected values: it cannot tell them from the same values chosen by the owner.
        $this->migration()->down();
        $this->assertSame(self::SHIPPED_122, $this->storedPlanLimits());
    }

    public function test_a_new_122_site_is_left_alone(): void
    {
        $this->seedPlanLimits(self::SHIPPED_122);
        $before = DB::table('settings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        Carbon::setTestNow('2026-11-01 10:00:00');
        $this->migration()->up();

        $this->assertSame($before, DB::table('settings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function test_values_the_owner_chose_are_never_changed(): void
    {
        $owner = [
            'free_max_uploads' => '1000001',
            'free_max_file_size' => '10485760000 ',
            'free_max_storage' => '10995116277760.0',
            'premium_max_uploads' => '1000000',
            'premium_max_file_size' => '',
            'premium_max_storage' => '10995116277760',
            'trial_period_days' => '0',
            'trial_email_reminder' => '3',
        ];
        $this->seedPlanLimits($owner);

        $this->migration()->up();

        $this->assertSame($owner, $this->storedPlanLimits());
        $this->assertSame(0, DB::table('admin_audit_logs')->count(), 'nothing changed, nothing logged');
    }

    public function test_only_the_rows_still_holding_the_bundled_value_change(): void
    {
        $mixed = array_replace(self::BUNDLED_121, ['free_max_uploads' => '25', 'premium_max_file_size' => '5368709120']);
        $this->seedPlanLimits($mixed);

        $this->migration()->up();

        $this->assertSame(array_replace(self::SHIPPED_122, ['free_max_uploads' => '25', 'premium_max_file_size' => '5368709120']), $this->storedPlanLimits());

        // Admin actions names only the limits that changed, never the owner's own values as new ones.
        $this->assertSame(
            ['1.2.2: package upload limits set to the defaults: free 500 MB per file, 5 GB in all'],
            DB::table('admin_audit_logs')->where('action', 'update.plan_limits_defaults')->pluck('details')->all(),
        );
    }

    public function test_the_admin_actions_text_names_only_what_changed_and_fits_the_lists(): void
    {
        $migration = $this->migration();
        $this->assertSame(array_keys($migration::CORRECTIONS), array_keys($migration::AUDIT_PARTS));
        $this->assertSame(120, $migration::AUDIT_MAX_LENGTH);

        // The words agree with the new values.
        $words = array_map(fn (array $part): string => $part[1], $migration::AUDIT_PARTS);
        $this->assertSame(self::SHIPPED_122['free_max_uploads'].' videos', $words['free_max_uploads']);
        $this->assertSame(NumberFormat::fileSize((int) self::SHIPPED_122['free_max_file_size']).' per file', $words['free_max_file_size']);
        $this->assertSame(NumberFormat::fileSize((int) self::SHIPPED_122['free_max_storage']).' in all', $words['free_max_storage']);
        $this->assertSame(NumberFormat::fileSize((int) self::SHIPPED_122['premium_max_file_size']).' per file', $words['premium_max_file_size']);

        $this->assertSame('1.2.2: package upload limits set to the defaults: premium 2 GB per file', $migration::auditDetails(['premium_max_file_size']));
        $this->assertSame('1.2.2: package upload limits set to the defaults: free 10 videos; premium 2 GB per file', $migration::auditDetails(['premium_max_file_size', 'free_max_uploads']));

        // Every combination of changed limits: only those, in a fixed order, short enough for both lists.
        $keys = array_keys($migration::CORRECTIONS);
        for ($mask = 1; $mask < 2 ** count($keys); $mask++) {
            $changed = array_values(array_filter($keys, fn (string $key, int $i): bool => (bool) ($mask & (1 << $i)), ARRAY_FILTER_USE_BOTH));
            $details = $migration::auditDetails($changed);

            $this->assertFitsTheAdminLists($details);
            foreach ($migration::AUDIT_PARTS as $key => [$plan, $text]) {
                $this->assertSame(in_array($key, $changed, true), str_contains($details, $text), "{$details}: {$key}");
            }
            $this->assertSame(in_array('premium_max_file_size', $changed, true), str_contains($details, 'premium '), $details);
            $this->assertSame(array_diff($changed, ['premium_max_file_size']) !== [], str_contains($details, 'free '), $details);
        }
    }

    /** The old admin's list shows Str::limit(details, 120), the new admin's 160. */
    private function assertFitsTheAdminLists(string $details): void
    {
        $this->assertLessThanOrEqual(120, mb_strwidth($details, 'UTF-8'), $details);
        $this->assertSame($details, Str::limit($details, 120), 'the old admin list shows all of it');
        $this->assertSame($details, Str::limit($details, 160), 'the new admin list shows all of it');
    }

    public function test_a_bundled_value_outside_the_plan_limits_group_and_missing_rows_stay_as_they_are(): void
    {
        DB::table('settings')->insert(['key' => 'free_max_uploads', 'value' => '1000000', 'group' => 'uploads', 'created_at' => self::SEEDED_AT, 'updated_at' => self::SEEDED_AT]);

        $this->migration()->up();

        $this->assertSame('1000000', DB::table('settings')->where('key', 'free_max_uploads')->value('value'));
        $this->assertSame(0, DB::table('settings')->where('group', 'planlimits')->count(), 'no row is added');
    }

    public function test_a_database_without_the_settings_table_is_skipped(): void
    {
        Schema::drop('settings');

        $this->migration()->up();
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('settings'));
    }

    public function test_the_settings_cache_shows_the_new_values_at_once(): void
    {
        $this->seedPlanLimits(self::BUNDLED_121);
        $this->assertSame('1000000', Setting::getAllGrouped()['planlimits']['free_max_uploads']);
        $this->assertSame('10485760000', Setting::getGroup('planlimits')['free_max_file_size']);
        $this->assertSame('104857600000', Setting::get('premium_max_file_size'));

        $this->migration()->up();

        $this->assertSame('10', Setting::getAllGrouped()['planlimits']['free_max_uploads']);
        $this->assertSame('524288000', Setting::getGroup('planlimits')['free_max_file_size']);
        $this->assertSame('2147483648', Setting::get('premium_max_file_size'));
    }

    public function test_the_premium_page_shows_the_new_limits(): void
    {
        $this->withoutVite();
        Plan::factory()->create();
        $this->seedPlanLimits(self::BUNDLED_121);
        Setting::clearCache();

        // A 1.2.1 site: the bundled rows decide what the page shows.
        $this->applySettingsLikeAWebRequest();
        $this->assertSame([
            'Max Video Uploads' => ['1000000', 'Unlimited'],
            'Max File Size Per Upload' => [NumberFormat::fileSize(10485760000), NumberFormat::fileSize(104857600000)],
            'Video Storage Space' => [NumberFormat::fileSize(10995116277760), 'Unlimited'],
        ], $this->comparisonRows());

        $this->migration()->up();

        $this->applySettingsLikeAWebRequest();
        $this->assertSame([
            'Max Video Uploads' => ['10', 'Unlimited'],
            'Max File Size Per Upload' => ['500 MB', '2 GB'],
            'Video Storage Space' => ['5 GB', 'Unlimited'],
        ], $this->comparisonRows());
    }

    /**
     * @return array<string, array{0: string, 1: string}> label => [free, premium] of the Premium page's comparison table
     */
    private function comparisonRows(): array
    {
        $html = $this->get(route('pages.premium'))->assertOk()->getContent();

        preg_match_all('#<td class="fw-bold">\s*(.*?)\s*</td>\s*<td>\s*(.*?)\s*</td>\s*<td>\s*(.*?)\s*</td>#s', $html, $rows, PREG_SET_ORDER);
        $table = [];

        foreach ($rows as [, $label, $free, $premium]) {
            if (in_array($label, ['Max Video Uploads', 'Max File Size Per Upload', 'Video Storage Space'], true)) {
                $table[$label] = [html_entity_decode(strip_tags($free)), html_entity_decode(strip_tags($premium))];
            }
        }

        return $table;
    }

    /** What AppServiceProvider::applyDbSettings() does at the start of every web request (it skips the console). */
    private function applySettingsLikeAWebRequest(): void
    {
        $provider = $this->app->getProvider(AppServiceProvider::class);
        $console = new ReflectionProperty($this->app, 'isRunningInConsole');
        $before = $console->getValue($this->app);
        $console->setValue($this->app, false);

        try {
            (new ReflectionMethod($provider, 'applyDbSettings'))->invoke($provider);
        } finally {
            $console->setValue($this->app, $before);
        }
    }

    /** @param array<string, string> $rows */
    private function seedPlanLimits(array $rows): void
    {
        foreach ($rows as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'group' => 'planlimits', 'created_at' => '2026-04-25 20:05:28', 'updated_at' => self::SEEDED_AT]);
        }
    }

    /** @return array<string, string> */
    private function storedPlanLimits(): array
    {
        $stored = DB::table('settings')->where('group', 'planlimits')->pluck('value', 'key')->map(fn ($value) => (string) $value)->all();

        return array_replace(array_intersect_key(self::BUNDLED_121, $stored), $stored);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }

    /**
     * The rows of one settings group in the shipped example.sql (key => value, in file order).
     *
     * @return array<string, string>
     */
    private function exampleSqlRows(string $group): array
    {
        $sql = file_get_contents(database_path('install/example.sql'));
        $this->assertIsString($sql);
        $this->assertSame(1, preg_match('/^INSERT INTO `settings` VALUES\s*(.*?);\s*$/ms', $sql, $insert), 'the settings INSERT of example.sql');

        preg_match_all("/\\((\\d+),'((?:[^'\\\\]|\\\\.)*)','((?:[^'\\\\]|\\\\.)*)','((?:[^'\\\\]|\\\\.)*)',/", $insert[1], $rows, PREG_SET_ORDER);
        $found = [];

        foreach ($rows as [, , $key, $value, $rowGroup]) {
            if ($rowGroup === $group) {
                $found[stripcslashes($key)] = stripcslashes($value);
            }
        }

        return $found;
    }
}
