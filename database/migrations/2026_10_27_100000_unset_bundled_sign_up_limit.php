<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Up to 1.2.2 the bundled database/install/example.sql stored "Sign-ups allowed per visitor" (users.max_register_attempts)
 * as 1: one new account per internet address and minute, so a second person at the same home, school or office was
 * refused ("Wait 53 seconds"). 1.2.3 ships no value; while none is stored, 3 sign-ups per address in 10 minutes apply
 * (App\Http\Requests\Auth\RegisterRequest::signUpLimit()), and a number the owner stores keeps its old meaning (that
 * many per minute).
 *
 * A row that is still exactly that seed is treated as unset (removed), so updated sites get the same default as new
 * ones: the text "1" in the users group that was never changed after it was written (created_at = updated_at; the
 * settings pages write a row only when its value changes). Any other value is the owner's choice and stays as it is:
 * another number, an empty value, a 1 the owner set again later (updated_at moved on), a row in another group, a
 * missing row. One row, looked up by its unique key: fast on any database. Safe to run again (nothing matches then)
 * and on 1.2.0, 1.2.1 and 1.2.2 databases; down() changes nothing.
 */
return new class extends Migration
{
    public const KEY = 'max_register_attempts';

    public const GROUP = 'users';

    /** The value database/install/example.sql shipped up to 1.2.2. */
    public const BUNDLED = '1';

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $row = DB::table('settings')
            ->where('key', self::KEY)
            ->where('group', self::GROUP)
            ->first(['id', 'key', 'value', 'group', 'created_at', 'updated_at']);

        // Some collations ignore trailing spaces or case in "=": only the exact bundled text, never changed since.
        if ($row === null
            || (string) $row->key !== self::KEY
            || (string) $row->group !== self::GROUP
            || ! is_string($row->value)
            || $row->value !== self::BUNDLED
            || ! self::neverChanged($row->created_at, $row->updated_at)) {
            return;
        }

        $removed = DB::table('settings')
            ->where('id', $row->id)
            ->where('value', self::BUNDLED)
            ->delete();

        if ($removed < 1) {
            return;
        }

        // The site reads the group from the settings cache on every request.
        Setting::clearCache([self::KEY], [self::GROUP]);

        // Tools > Logs > Admin actions is the owner's record of what changed: sites log only errors to laravel.log.
        try {
            if (Schema::hasTable('admin_audit_logs')) {
                DB::table('admin_audit_logs')->insert([
                    'user_id' => null,
                    'action' => 'update.sign_up_limit_default',
                    'details' => '1.2.3: "Sign-ups allowed per visitor" was the package value (1 a minute): now the default, 3 in 10 minutes',
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => now(),
                ]);
            }

            Log::info('AVDBCMS 1.2.3: the bundled "Sign-ups allowed per visitor" value of example.sql was unset; the default (3 sign-ups in 10 minutes) applies.');
        } catch (Throwable) {
            // The log entries are a courtesy; the change itself is done.
        }
    }

    /**
     * Written once and never changed: both times known and equal, as example.sql inserted the row (the same time zone
     * shift applies to both, so the comparison holds on every server).
     */
    private static function neverChanged(mixed $createdAt, mixed $updatedAt): bool
    {
        if ($createdAt === null || $updatedAt === null) {
            return false;
        }

        return (string) $createdAt === (string) $updatedAt;
    }

    public function down(): void
    {
        // Nothing to undo: putting the package value back would bring the one-a-minute limit back, and a rollback cannot
        // tell a removed seed from a value the owner chose later.
    }
};
