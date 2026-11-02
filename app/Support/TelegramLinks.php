<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The AVDBAPI Telegram links AVDBCMS offers for help (1.2.3): the community group, a private group reached through its
 * invite link, and personal support from the AVDBAPI owner. The public group t.me/avdb_api that 1.2.2 and earlier linked
 * (the installer, the AVDBAPI import page, the footer's Support links and the Telegram social link) is gone.
 *
 * The code reads the addresses from here (FooterSettings, AppServiceProvider, the installer and the AVDBAPI import
 * page); the documents (README.md, LICENSE, NOTICE, UPGRADE.md) carry them as text. fixStoredSettings() gives the
 * stored settings of a site the new group: migration 2026_10_26_200000_new_telegram_links runs it once on an existing
 * site, and AppearanceApplier runs it again whenever appearance rows from before 1.2.3 can come back ("Restore my
 * previous look" and "Use the new look again" on Home, Undo in the design history). The footer and the Telegram social
 * link also show the new group for the old address wherever it still comes from (an old backup); nothing is written then.
 */
final class TelegramLinks
{
    /** The AVDBAPI community group on Telegram (private: its invite link). */
    public const GROUP_URL = 'https://t.me/+jj6McO-iYfA0MTM1';

    /** GROUP_URL where the address itself is the text. */
    public const GROUP_TEXT = 't.me/+jj6McO-iYfA0MTM1';

    /** Personal support from the AVDBAPI owner on Telegram. */
    public const SUPPORT_URL = 'https://t.me/avdbapi_support';

    /** SUPPORT_URL where the account name is the text. */
    public const SUPPORT_HANDLE = '@avdbapi_support';

    /** The name of the retired public group (Telegram names ignore letter case). */
    public const RETIRED_GROUP_NAME = 'avdb_api';

    /**
     * The "Support" links of the footer that AVDBCMS 1.2.0 - 1.2.2 shipped (FooterSettings, config/admin_settings.php)
     * and that the theme catalogue upgrade's frozen copy of the old defaults still holds.
     */
    public const OLD_SUPPORT_LINKS = "Contact|/contact\nTelegram Support|https://t.me/avdb_api\nTerms of Service|/terms";

    /** Settings rows fixStoredSettings() reads at a time: the table is small, but ad codes and custom code can be long. */
    private const CHUNK = 200;

    /**
     * The retired public group in a text: https://t.me/avdb_api and its other spellings (http, //, www., telegram.me, no
     * scheme, slashes written "\/" as JSON writes them, any letter case, a trailing slash or a message number). The
     * name ends where a Telegram name cannot go on (t.me/avdb_api_bot is another account), and an address inside a
     * longer one (example.com/t.me/avdb_api) is not the group.
     */
    private const RETIRED_GROUP = '~(?<![\w@./\\\\-])(?<scheme>(?:https?:)?\\\\?/\\\\?/)?(?:www\.)?(?:t|telegram)\.me(?<slash>\\\\?/)avdb_api(?!\w)(?:\\\\?/\d*(?![\w+-]))?~i';

    /**
     * Whether a whole value (spaces around it aside) is the retired group: one of its addresses, or "@avdb_api".
     */
    public static function isRetiredGroup(?string $value): bool
    {
        if ($value === null || stripos($value, self::RETIRED_GROUP_NAME) === false) {
            return false;
        }

        $value = trim($value);

        if (strcasecmp($value, '@'.self::RETIRED_GROUP_NAME) === 0) {
            return true;
        }

        return preg_match(self::RETIRED_GROUP, $value, $match) === 1 && $match[0] === $value;
    }

    /**
     * $text with every address of the retired group replaced by the new group's: a full address by GROUP_URL, one
     * written without a scheme by GROUP_TEXT, and in the "\/" form where the text writes its slashes so (a JSON value
     * stays valid). Every other character stays as it is.
     */
    public static function replaceRetiredGroup(string $text): string
    {
        if (stripos($text, self::RETIRED_GROUP_NAME) === false) {
            return $text;
        }

        $replaced = preg_replace_callback(self::RETIRED_GROUP, static function (array $match): string {
            $address = ($match['scheme'] ?? '') !== '' ? self::GROUP_URL : self::GROUP_TEXT;

            return str_contains($match['slash'] ?? '', '\\') ? str_replace('/', '\\/', $address) : $address;
        }, $text);

        return is_string($replaced) ? $replaced : $text;
    }

    /**
     * The address to link for a stored one: the new group for the retired group, any other value as it is.
     */
    public static function current(?string $value): ?string
    {
        return self::isRetiredGroup($value) ? self::GROUP_URL : $value;
    }

    /**
     * Give the stored settings the new group wherever they still hold the retired one:
     * - social_telegram (Settings > Site basics > Social profiles, "Telegram channel") that is the old group in any of
     *   its spellings (isRetiredGroup()) becomes GROUP_URL;
     * - footer_support_links (Appearance > Header, menus & footer, "Support" links) that still holds the links 1.2.0 -
     *   1.2.2 shipped (OLD_SUPPORT_LINKS; line endings, blank lines and the spaces around a line or its "|" aside)
     *   becomes the list of FooterSettings::defaults(): Contact, Telegram group, Telegram support, Terms of Service;
     * - any other value that holds an address of the old group (an owner's own Support links, a footer or menu line, an
     *   ad code ...) gets the new group's address in its place (replaceRetiredGroup()); every other character stays.
     * Nothing else changes. Only rows that hold "avdb_api" are read, a value changed meanwhile (an admin saving a page)
     * is never overwritten, and the settings cache forgets what changed. Safe to run again: nothing matches then.
     *
     * @return array<string, array{group: string, shipped_list: bool}> what changed, in the order the rows are stored:
     *                                                                 setting key => its group, and whether it was the
     *                                                                 shipped Support list (now the new list)
     */
    public static function fixStoredSettings(): array
    {
        if (! Schema::hasTable('settings')) {
            return [];
        }

        $newSupportLinks = FooterSettings::defaults()['footer_support_links'];
        /** @var array<string, array{group: string, shipped_list: bool}> $changed */
        $changed = [];

        // Every address of the old group names it, so only rows holding "avdb_api" are read: LOWER() because a
        // case-sensitive collation would miss "AVDB_API"; "_" matches any character, the checks below are exact.
        $column = DB::table('settings')->getGrammar()->wrap('value');

        DB::table('settings')
            ->select(['id', 'key', 'value', 'group'])
            ->whereRaw('LOWER('.$column.') LIKE ?', ['%'.self::RETIRED_GROUP_NAME.'%'])
            ->chunkById(self::CHUNK, function ($rows) use ($newSupportLinks, &$changed): void {
                foreach ($rows as $row) {
                    $key = (string) $row->key;
                    $value = $row->value;

                    if (! is_string($value) || stripos($value, self::RETIRED_GROUP_NAME) === false) {
                        continue;
                    }

                    $shippedList = $key === 'footer_support_links' && self::sameLinks($value, self::OLD_SUPPORT_LINKS);

                    if ($key === 'social_telegram' && self::isRetiredGroup($value)) {
                        $new = self::GROUP_URL;
                    } elseif ($shippedList) {
                        $new = $newSupportLinks;
                    } else {
                        $new = self::replaceRetiredGroup($value);
                    }

                    if ($new === $value) {
                        continue;
                    }

                    // Only the value read here: a change made meanwhile (an admin saving the page) is never overwritten.
                    $updated = DB::table('settings')
                        ->where('id', $row->id)
                        ->where('value', $value)
                        ->update(['value' => $new, 'updated_at' => now()]);

                    if ($updated > 0) {
                        $changed[$key] = ['group' => (string) $row->group, 'shipped_list' => $shippedList];
                    }
                }
            }, 'id');

        if ($changed !== []) {
            // The site reads its settings from the settings cache on every request.
            Setting::clearCache(array_keys($changed), array_values(array_unique(array_column($changed, 'group'))));
        }

        return $changed;
    }

    /**
     * Whether two link lists ("Name|address" per line) hold the same links: line endings, blank lines and the spaces
     * around a line or its "|" do not count (a page saved without changes may store "\r\n").
     */
    public static function sameLinks(string $a, string $b): bool
    {
        return self::linkLines($a) === self::linkLines($b);
    }

    /** @return list<string> the lines of a link list, each trimmed and with "|" written without spaces around it */
    private static function linkLines(string $value): array
    {
        $lines = [];

        foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                $lines[] = implode('|', array_map('trim', explode('|', $line)));
            }
        }

        return $lines;
    }
}
