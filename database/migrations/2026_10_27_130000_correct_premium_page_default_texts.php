<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * AVDBCMS 1.2.3 (R1-114): four texts of Money > Premium page text that every site got from database/install/example.sql
 * up to 1.2.2. The plan cards of /premium read "Your billing starts on Nov 25, 2026 for the month plan. Cancel anytime"
 * ("the month plan", "the year plan", no full stop at the end) and the page said "Save 2 months" twice: under the plans
 * ("Save 2 months with the annual subscription", whatever the prices are) and on the yearly card, whose badge is worked
 * out from the real prices.
 *
 * A row that still holds EXACTLY the shipped text gets the 1.2.3 default (App\Support\PremiumPageSettings::defaults(),
 * the same as RETIRED_DEFAULTS there): "Your billing starts on :date.", "Your billing starts today.", "Cancel anytime."
 * and an empty note under the plans (the owner can still write one). Any other value is the owner's choice and stays as
 * it is: a changed text, an empty value, a row in another group or a missing row. Tools > Logs > Admin actions says what
 * changed (only on a site that has accounts: a database that is being built right now gets no news). Safe to run again
 * (nothing matches then) and on 1.2.0, 1.2.1 and 1.2.2 databases; one small query per text, so it is fast on any site.
 */
return new class extends Migration
{
    /** setting key => [the text example.sql shipped up to 1.2.2, the default from 1.2.3 on] */
    public const CORRECTIONS = [
        'premium_billing_trial_summary' => ['Your billing starts on :date for the :period plan.', 'Your billing starts on :date.'],
        'premium_billing_without_trial_summary' => ['Your billing starts today for the :period plan.', 'Your billing starts today.'],
        'premium_cancel_anytime_label' => ['Cancel anytime', 'Cancel anytime.'],
        'premium_annual_savings_text' => ['Save 2 months with the annual subscription', ''],
    ];

    public const AUDIT_ACTION = 'update.premium_page_texts';

    /** The admin lists show the details up to 120 characters (old admin) or 160 (new admin). */
    public const AUDIT_MAX_LENGTH = 120;

    private const GROUP = 'pages';

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $changed = [];

        foreach (self::CORRECTIONS as $key => [$shipped, $default]) {
            $rows = DB::table('settings')
                ->where('group', self::GROUP)
                ->where('key', $key)
                ->where('value', $shipped)
                ->get(['id', 'key', 'value']);

            foreach ($rows as $row) {
                // Some collations ignore trailing spaces or case in "=": only the exact shipped text is changed.
                if ((string) $row->key !== $key || ! is_string($row->value) || $row->value !== $shipped) {
                    continue;
                }

                $updated = DB::table('settings')
                    ->where('id', $row->id)
                    ->where('value', $shipped)
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

        if (! $this->siteHasAccounts()) {
            return;
        }

        // Tools > Logs > Admin actions is the owner's record of what changed: sites log only errors to laravel.log.
        try {
            if (Schema::hasTable('admin_audit_logs')) {
                DB::table('admin_audit_logs')->insert([
                    'user_id' => null,
                    'action' => self::AUDIT_ACTION,
                    'details' => self::auditDetails($changed),
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => now(),
                ]);
            }

            Log::info('AVDBCMS 1.2.3: the Premium page texts that still held the shipped wording got the new defaults.', ['settings' => $changed]);
        } catch (Throwable) {
            // The log entries are a courtesy; the texts are changed.
        }
    }

    /**
     * The Admin actions text, at most AUDIT_MAX_LENGTH characters: "1.2.3: Premium page texts set to the new wording:
     * billing line, cancel line, savings note".
     *
     * @param  list<string>  $changed  setting keys
     */
    public static function auditDetails(array $changed): string
    {
        $names = [
            'premium_billing_trial_summary' => 'billing line',
            'premium_billing_without_trial_summary' => 'billing line',
            'premium_cancel_anytime_label' => 'cancel line',
            'premium_annual_savings_text' => 'savings note',
        ];
        $parts = array_values(array_unique(array_map(fn (string $key): string => $names[$key] ?? $key, $changed)));
        $details = '1.2.3: Premium page texts set to the new wording: '.implode(', ', $parts);

        return mb_strlen($details) > self::AUDIT_MAX_LENGTH ? mb_substr($details, 0, self::AUDIT_MAX_LENGTH - 1).'…' : $details;
    }

    /** A site with at least one account (not a database the installer or a test is building right now). */
    private function siteHasAccounts(): bool
    {
        try {
            return Schema::hasTable('users') && DB::table('users')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function down(): void
    {
        // Nothing to undo: the old wording is what this changes, and a rollback cannot tell a corrected row from the same
        // text an owner typed later.
    }
};
