<?php

use App\Support\TelegramLinks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * AVDBCMS 1.2.3: the AVDBAPI Telegram group moved. The public group t.me/avdb_api that 1.2.0 - 1.2.2 linked is gone;
 * the community is a private group now (App\Support\TelegramLinks::GROUP_URL) and personal support is @avdbapi_support
 * (TelegramLinks::SUPPORT_URL). The stored settings of an existing site follow (TelegramLinks::fixStoredSettings(), which
 * AppearanceApplier runs again after "Restore my previous look", "Use the new look again" and Undo in the design
 * history, because they can bring appearance rows from before 1.2.3 back):
 * - social_telegram (Settings > Site basics > Social profiles, "Telegram channel") that is still the old group, in any
 *   of its spellings (TelegramLinks::isRetiredGroup()), becomes the new group's link;
 * - footer_support_links (Appearance > Header, menus & footer, "Support" links) that still holds the links 1.2.2
 *   shipped (Contact, Telegram Support, Terms of Service; line endings and spaces around "|" aside) becomes the new list
 *   of FooterSettings::defaults(): Contact, Telegram group, Telegram support, Terms of Service;
 * - any other stored value that holds an address of the old group (an owner's own Support links, a footer or menu line,
 *   an ad code ...) gets the new group's address in its place (TelegramLinks::replaceRetiredGroup()); every other
 *   character stays as it is.
 * Nothing else changes. Tools > Logs > Admin actions says which settings changed; a site that has no account yet is
 * being built right now (the installer's migrations, a test database) and its defaults are no news, so nothing is
 * logged there. Safe to run again (nothing matches then) and on 1.2.0, 1.2.1 and 1.2.2 databases; down() changes nothing.
 */
return new class extends Migration
{
    /** The "Support" links of the footer that AVDBCMS 1.2.0 - 1.2.2 shipped (FooterSettings, config/admin_settings.php). */
    public const OLD_SUPPORT_LINKS = TelegramLinks::OLD_SUPPORT_LINKS;

    public const AUDIT_ACTION = 'update.telegram_links';

    /** The admin lists show the details up to 120 characters (old admin) or 160 (new admin). */
    public const AUDIT_MAX_LENGTH = 120;

    public function up(): void
    {
        $changed = TelegramLinks::fixStoredSettings();

        if ($changed === [] || ! $this->siteHasAccounts()) {
            return;
        }

        // The log names the two settings every site has first, then the others as they are stored.
        $rank = ['social_telegram' => 0, 'footer_support_links' => 1];
        uksort($changed, fn ($a, $b): int => ($rank[(string) $a] ?? 2) <=> ($rank[(string) $b] ?? 2));
        $names = [];

        foreach ($changed as $key => $change) {
            $names[] = $change['shipped_list'] ? 'footer Support links (group + support)' : self::settingName((string) $key);
        }

        // Tools > Logs > Admin actions is the owner's record of what changed: sites log only errors to laravel.log.
        try {
            $details = self::auditDetails($names);

            if (Schema::hasTable('admin_audit_logs')
                && ! DB::table('admin_audit_logs')->where('action', self::AUDIT_ACTION)->where('details', $details)->exists()) {
                DB::table('admin_audit_logs')->insert([
                    'user_id' => null,
                    'action' => self::AUDIT_ACTION,
                    'details' => $details,
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => now(),
                ]);
            }

            Log::info('AVDBCMS 1.2.3: the settings that linked the retired AVDBAPI Telegram group now link the new group.', ['settings' => array_keys($changed)]);
        } catch (Throwable) {
            // The log entries are a courtesy; the links are changed.
        }
    }

    public function down(): void
    {
        // Nothing to undo: the old public group is gone, so its link would help nobody.
    }

    /**
     * The Admin actions text, at most AUDIT_MAX_LENGTH characters: the new group and the settings that changed, for
     * example "1.2.3: new AVDBAPI Telegram group t.me/+jj6McO-iYfA0MTM1 in: Telegram channel, footer Support links
     * (group + support)"; when they do not all fit, the first ones and how many more.
     *
     * @param  list<string>  $names  the changed settings as the admin names them
     */
    public static function auditDetails(array $names): string
    {
        $prefix = '1.2.3: new AVDBAPI Telegram group '.TelegramLinks::GROUP_TEXT;
        $names = array_values($names);

        for ($shown = count($names); $shown > 0; $shown--) {
            $rest = count($names) - $shown;
            $text = $prefix.' in: '.implode(', ', array_slice($names, 0, $shown)).($rest > 0 ? ' and '.$rest.' more' : '');

            if (mb_strlen($text) <= self::AUDIT_MAX_LENGTH) {
                return $text;
            }
        }

        return $prefix.' in '.count($names).' '.(count($names) === 1 ? 'setting' : 'settings');
    }

    /** The name the admin shows for a setting: its label (config/admin_settings.php), else its key. */
    private static function settingName(string $key): string
    {
        if ($key === 'social_telegram') {
            return 'Telegram channel';
        }

        if ($key === 'footer_support_links') {
            return 'footer Support links';
        }

        try {
            $label = config('admin_settings.settings.'.$key.'.label');
        } catch (Throwable) {
            $label = null;
        }

        return is_string($label) && trim($label) !== '' ? trim($label) : $key;
    }

    /** Whether the site has an account: a site without one is being built right now, and its defaults are no news. */
    private function siteHasAccounts(): bool
    {
        try {
            return Schema::hasTable('users') && DB::table('users')->exists();
        } catch (Throwable) {
            return true;
        }
    }
};
