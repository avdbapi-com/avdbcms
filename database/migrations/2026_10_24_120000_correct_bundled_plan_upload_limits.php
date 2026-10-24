<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Up to 1.2.1 the bundled database/install/example.sql stored the upload limits of the developers' test site: free
 * members 1,000,000 videos, 9.77 GB per file and 10 TB in all, premium members 97.7 GB per file. Those rows override
 * config/plans.php, so its defaults never applied and the Premium page of a new site showed those numbers.
 *
 * A row that still holds exactly the bundled value gets the default of config/plans.php (the value example.sql ships
 * from 1.2.2 on). Any other value is the owner's choice and stays as it is: a changed number, an empty value, a row
 * in another group or a missing row. Safe to run again (nothing matches then) and on 1.2.0 and 1.2.1 databases.
 */
return new class extends Migration
{
    /** setting key => [the value example.sql shipped up to 1.2.1, the default of config/plans.php] */
    public const CORRECTIONS = [
        'free_max_uploads' => ['1000000', '10'],
        'free_max_file_size' => ['10485760000', '524288000'],
        'free_max_storage' => ['10995116277760', '5368709120'],
        'premium_max_file_size' => ['104857600000', '2147483648'],
    ];

    /** setting key => [plan, the new value in words] for the Admin actions row, which names only what changed. */
    public const AUDIT_PARTS = [
        'free_max_uploads' => ['free', '10 videos'],
        'free_max_file_size' => ['free', '500 MB per file'],
        'free_max_storage' => ['free', '5 GB in all'],
        'premium_max_file_size' => ['premium', '2 GB per file'],
    ];

    /** The admin lists show the details up to 120 characters (old admin) or 160 (new admin). */
    public const AUDIT_MAX_LENGTH = 120;

    private const GROUP = 'planlimits';

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $changed = [];

        foreach (self::CORRECTIONS as $key => [$bundled, $default]) {
            $rows = DB::table('settings')
                ->where('group', self::GROUP)
                ->where('key', $key)
                ->where('value', $bundled)
                ->get(['id', 'key', 'value']);

            foreach ($rows as $row) {
                // Some collations ignore trailing spaces or case in "=": only the exact bundled text is changed.
                if ((string) $row->key !== $key || ! is_string($row->value) || $row->value !== $bundled) {
                    continue;
                }

                $updated = DB::table('settings')
                    ->where('id', $row->id)
                    ->where('value', $bundled)
                    ->update(['value' => $default, 'updated_at' => now()]);

                if ($updated > 0) {
                    $changed[] = $key;
                }
            }
        }

        $changed = array_values(array_unique($changed));

        if ($changed === []) {
            return;
        }

        // The site reads these rows from the settings cache on every request.
        Setting::clearCache($changed, [self::GROUP]);

        // Tools › Logs › Admin actions is the owner's record of what changed: sites log only errors to laravel.log.
        try {
            if (Schema::hasTable('admin_audit_logs')) {
                DB::table('admin_audit_logs')->insert([
                    'user_id' => null,
                    'action' => 'update.plan_limits_defaults',
                    'details' => self::auditDetails($changed),
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => now(),
                ]);
            }

            Log::info('AVDBCMS 1.2.2: the bundled upload limits of example.sql were set to the defaults of config/plans.php.', ['settings' => $changed]);
        } catch (Throwable) {
            // The log entries are a courtesy; the change itself is done.
        }
    }

    /**
     * The Admin actions text: only the limits that changed, at most AUDIT_MAX_LENGTH characters, for example
     * "1.2.2: package upload limits set to the defaults: free 500 MB per file, 5 GB in all; premium 2 GB per file".
     *
     * @param  list<string>  $changed  setting keys
     */
    public static function auditDetails(array $changed): string
    {
        $plans = [];

        foreach (self::AUDIT_PARTS as $key => [$plan, $words]) {
            if (in_array($key, $changed, true)) {
                $plans[$plan][] = $words;
            }
        }

        $parts = [];

        foreach ($plans as $plan => $words) {
            $parts[] = $plan.' '.implode(', ', $words);
        }

        return '1.2.2: package upload limits set to the defaults: '.implode('; ', $parts);
    }

    public function down(): void
    {
        // Nothing to undo: putting the test-site values back would bring the wrong limits back, and a rollback cannot
        // tell a corrected row from the same value chosen by the owner later.
    }
};
