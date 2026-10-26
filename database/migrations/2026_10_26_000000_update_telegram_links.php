<?php

use App\Support\SettingsRevision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The AVDB API Telegram group moved to a private invite link, and personal Telegram support has its own account.
 *
 * Older packages stored the old group link t.me/avdb_api as factory values:
 * - Settings › Site basics › Telegram channel (`social_telegram`, the footer's Telegram icon): create_settings_table,
 *   the 2026_05_05 rebrand and align_upload_and_user_settings wrote it.
 * - Appearance › Menus › "Support" links (`footer_support_links`), the factory line "Telegram Support|t.me/avdb_api".
 *
 * Only a value that still holds the old link changes: the Telegram channel becomes the new group link, and in the
 * "Support" links each line with the old link points to the new group (the factory label "Telegram Support" becomes
 * "Telegram Group") with a "Telegram Support" line to the support account right after it, unless the list already
 * links to that account. Every other line, its order, its line breaks and any link the owner chose stay exactly as
 * stored; an empty Telegram channel stays empty. The timestamps of untouched rows stay. Safe to run again.
 */
return new class extends Migration
{
    public const NEW_GROUP = 'https://t.me/+jj6McO-iYfA0MTM1';

    public const NEW_SUPPORT = 'https://t.me/avdbapi_support';

    /** The labels used for the two lines (translation keys of lang/*.json, already translated in every language). */
    public const GROUP_LABEL = 'Telegram Group';

    public const SUPPORT_LABEL = 'Telegram Support';

    /** The old group link in every spelling a site could have stored (scheme, www, telegram.me, trailing slash, tg://). */
    public const OLD_LINK = '~^(?:(?:https?:)?//)?(?:www\.)?(?:t|telegram)\.(?:me|dog)/avdb_api/?$|^tg://resolve\?domain=avdb_api$~i';

    /** Where the support account already is in a list (any spelling of the handle, any scheme). */
    private const SUPPORT_LINK = '~(?:t|telegram)\.(?:me|dog)/avdbapi_support(?![A-Za-z0-9_])|domain=avdbapi_support(?![A-Za-z0-9_])~i';

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $changed = 0;

        foreach (DB::table('settings')->where('key', 'social_telegram')->get(['id', 'value']) as $row) {
            // Compared in PHP: some production collations ignore case, so SQL equality alone could match an owner's own link.
            if (is_string($row->value) && self::isOldLink($row->value)) {
                $changed += DB::table('settings')->where('id', $row->id)->where('value', $row->value)
                    ->update(['value' => self::NEW_GROUP, 'updated_at' => now()]);
            }
        }

        foreach (DB::table('settings')->where('key', 'footer_support_links')->get(['id', 'value']) as $row) {
            if (! is_string($row->value)) {
                continue;
            }

            $updated = self::supportLinks($row->value);

            if ($updated !== $row->value) {
                $changed += DB::table('settings')->where('id', $row->id)->where('value', $row->value)
                    ->update(['value' => $updated, 'updated_at' => now()]);
            }
        }

        if ($changed > 0) {
            SettingsRevision::flushSettingsCache();
        }
    }

    public function down(): void
    {
        // Data correction is intentionally retained: the old group link no longer leads to the group, and a rollback
        // cannot tell the new links this migration wrote from links an administrator entered since.
    }

    public static function isOldLink(string $value): bool
    {
        return preg_match(self::OLD_LINK, trim($value)) === 1;
    }

    /**
     * The "Support" links (one "Label|address" per line) with the old group link replaced; unchanged when the list
     * holds no old link. Line breaks (\n, \r\n or \r) are kept as stored.
     */
    public static function supportLinks(string $value): string
    {
        $parts = preg_split('/(\r\n|\r|\n)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $value;
        }

        preg_match('/\r\n|\r|\n/', $value, $eol);
        $eol = $eol[0] ?? "\n";
        $hasSupport = preg_match(self::SUPPORT_LINK, $value) === 1;
        $out = [];
        $found = false;

        for ($i = 0, $n = count($parts); $i < $n; $i += 2) {
            $line = $parts[$i];
            $break = $parts[$i + 1] ?? '';
            [$label, $url] = array_pad(explode('|', $line, 2), 2, null);

            if ($url === null || ! self::isOldLink($url)) {
                $out[] = $line . $break;

                continue;
            }

            $found = true;
            $label = trim($label);
            $label = ($label === '' || strcasecmp($label, 'Telegram Support') === 0) ? self::GROUP_LABEL : $label;
            $indent = substr($line, 0, strlen($line) - strlen(ltrim($line)));
            $out[] = $indent . $label . '|' . self::NEW_GROUP;

            if (! $hasSupport) {
                $out[] = $eol . $indent . self::SUPPORT_LABEL . '|' . self::NEW_SUPPORT;
                $hasSupport = true;
            }

            $out[] = $break;
        }

        return $found ? implode('', $out) : $value;
    }
};
