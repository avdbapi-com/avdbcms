<?php

namespace App\Support;

use App\Models\User;

/**
 * The one matching rule of the admin's two settings searches (AVDBCMS 1.2.3): the search of Settings
 * (SettingsPageController::searchSettings(), on the server) and the Ctrl+K search (resources/admin/lib/search.ts, in the
 * browser, on the index of AdminSearchIndex::build()). For the same words both find the same settings, so the Ctrl+K row
 * "See all N settings" opens Settings with exactly N matches (tests/js/admin_search.test.ts compares them).
 *
 * A setting matches when every word of the query starts a word of its texts (its label, old name, help, card title and
 * page name, each in English and in the admin's language, and its key; wordFound()), or when one of the search words
 * made for it or for its page (config/admin_nav.php and config/admin_settings.php "synonyms": "smtp", "api key") is
 * among the words of the query; the last word of a search word may carry a plural "s" or "es" ("api keys"). Texts and
 * queries are compared folded (fold()). Only the order of the results differs between the two searches: each ranks
 * for its own list.
 */
final class AdminSettingsSearch
{
    /**
     * Characters a word starts after (besides the start of the text): space, ASCII punctuation and the quotes, brackets
     * and commas of the admin's languages. A query word must start a word of the texts: "ads" finds "Ads" and
     * "AdSense", not "uploads". search.ts has the same list.
     */
    public const BOUNDARY = " \t!\"#$%&'()*+,./:;<=>?@[\\]^`{|}~«»‘’“”…、。「」（），";

    /**
     * Scripts written without spaces between words (Chinese, Japanese, Thai) or with words glued to "al-" (Arabic): a
     * query word in them is found anywhere in the texts. search.ts has the same ranges.
     */
    public const ANYWHERE = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{0E00}-\x{0E7F}\x{3040}-\x{30FF}\x{31F0}-\x{31FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}\x{FF66}-\x{FF9F}]/u';

    /** @var array<string, array{entries: list<array>, urls: array<string, ?string>}> per app, language and catalogue */
    private static array $memo = [];

    /**
     * Whether fold() takes accents off ("Việt" -> "viet"): with PHP's intl extension. The index tells the browser, so
     * the Ctrl+K search folds exactly like this one.
     */
    public static function foldsAccents(): bool
    {
        return class_exists(\Normalizer::class);
    }

    /** Lower case, accents off (see foldsAccents()), "_" and "-" as spaces, one space between words. */
    public static function fold(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');

        if (self::foldsAccents()) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);

            if (is_string($decomposed)) {
                $text = (string) preg_replace('/[\x{0300}-\x{036F}]/u', '', $decomposed);
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', str_replace(['_', '-'], ' ', $text)));
    }

    /** @return list<string> the words of a folded text */
    public static function words(string $folded): array
    {
        return array_values(array_filter(explode(' ', $folded), static fn (string $word): bool => $word !== ''));
    }

    /**
     * Whether a folded search word ("api key") is among the query's words, as whole words in that order; its last word
     * may carry a plural "s" or "es" ("api keys", "ip bans"). "ads" is a word of "ads not showing", not of "uploads".
     *
     * @param  list<string>  $queryWords
     */
    public static function termApplies(string $term, array $queryWords): bool
    {
        $termWords = self::words($term);
        $length = count($termWords);

        if ($length === 0) {
            return false;
        }

        for ($start = 0; $start + $length <= count($queryWords); $start++) {
            for ($i = 0; $i < $length; $i++) {
                $word = $queryWords[$start + $i];
                $wanted = $termWords[$i];

                if ($word !== $wanted && ! ($i === $length - 1 && ($word === $wanted.'s' || $word === $wanted.'es'))) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * The setting keys and page ids the search words among the query's words name.
     *
     * @param  list<string>  $queryWords
     * @return array<string, true>
     */
    public static function targets(array $queryWords): array
    {
        $targets = [];

        foreach (AdminSearchIndex::synonyms() as $term => $items) {
            if (self::termApplies(self::fold((string) $term), $queryWords)) {
                foreach ($items as $item) {
                    $targets[(string) $item] = true;
                }
            }
        }

        return $targets;
    }

    /**
     * The setting keys and page ids of the search words that ARE the whole query ("premium", "smtp", "api keys"): the
     * owner typed exactly the word made for them, so they come first in both searches (lib/search.ts exactTargets()).
     *
     * @return array<string, true>
     */
    public static function exactTargets(string $folded): array
    {
        $targets = [];

        foreach (AdminSearchIndex::synonyms() as $term => $items) {
            $term = self::fold((string) $term);

            if ($term !== '' && ($folded === $term || $folded === $term.'s' || $folded === $term.'es')) {
                foreach ($items as $item) {
                    $targets[(string) $item] = true;
                }
            }
        }

        return $targets;
    }

    /** A translation that is a text: a key that names a whole translation file gives its array, so the text itself. */
    public static function translate(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $translated = __($text);

        return is_string($translated) ? $translated : $text;
    }

    /**
     * Every setting of the catalogue with its folded texts, in catalogue (page and card) order, for the admin's
     * language. Kept for the request (it only depends on the language and the catalogue).
     *
     * @return list<array{key: string, setting: array, page: ?array, labels: list<string>, olds: list<string>,
     *                    helps: list<string>, cards: list<string>, pages: list<string>, keyText: string, text: string}>
     */
    public static function entries(): array
    {
        return self::memo()['entries'];
    }

    /** The address a search opens for a setting (AdminSettingsCatalog::url(), kept for the request), or null. */
    public static function url(string $key): ?string
    {
        $memo = &self::memo();

        if (! array_key_exists($key, $memo['urls'])) {
            $memo['urls'][$key] = AdminSettingsCatalog::url($key);
        }

        return $memo['urls'][$key];
    }

    /**
     * Whether a setting matches: every query word is part of its texts, or a search word names it or its page.
     *
     * @param  array{key: string, setting: array, text: string}  $entry
     * @param  list<string>  $queryWords
     * @param  array<string, true>  $targets
     */
    public static function matches(array $entry, array $queryWords, array $targets): bool
    {
        if ($queryWords === []) {
            return false;
        }

        return isset($targets[$entry['key']]) || isset($targets[(string) $entry['setting']['page']]) || self::wordsMatch($entry, $queryWords);
    }

    /**
     * Whether every query word is part of the setting's texts.
     *
     * @param  array{text: string}  $entry
     * @param  list<string>  $queryWords
     */
    public static function wordsMatch(array $entry, array $queryWords): bool
    {
        foreach ($queryWords as $word) {
            if (! self::wordFound($entry['text'], $word)) {
                return false;
            }
        }

        return $queryWords !== [];
    }

    /**
     * Whether a folded query word starts a word of a folded text ("ads" in "show ads" and "adsense", not in "uploads");
     * a word of a script in ANYWHERE may stand anywhere.
     */
    public static function wordFound(string $text, string $word): bool
    {
        if ($word === '') {
            return true;
        }

        if (preg_match(self::ANYWHERE, $word) === 1) {
            return str_contains($text, $word);
        }

        $offset = 0;

        while (($at = strpos($text, $word, $offset)) !== false) {
            if ($at === 0 || self::isBoundary(self::characterBefore($text, $at))) {
                return true;
            }

            $offset = $at + 1;
        }

        return false;
    }

    private static function isBoundary(string $character): bool
    {
        return $character !== '' && str_contains(self::BOUNDARY, $character);
    }

    /** The whole (UTF-8) character that ends right before byte $at. */
    private static function characterBefore(string $text, int $at): string
    {
        $start = $at - 1;

        // Back over the continuation bytes (10xxxxxx) to the first byte of the character.
        while ($start > 0 && (ord($text[$start]) & 0xC0) === 0x80) {
            $start--;
        }

        return substr($text, $start, $at - $start);
    }

    /**
     * The folded texts of one setting: label, old name, help, card title and page name, in English and in the admin's
     * language (each once), and the key.
     *
     * @return array{labels: list<string>, olds: list<string>, helps: list<string>, cards: list<string>, pages: list<string>, keyText: string, text: string}
     */
    public static function texts(string $key, array $setting, ?array $page): array
    {
        $both = static function (string $text): array {
            if ($text === '') {
                return [];
            }

            return array_values(array_unique([self::fold($text), self::fold(self::translate($text))]));
        };

        $texts = [
            'labels' => $both((string) ($setting['label'] ?? '')),
            'olds' => $both((string) ($setting['old'][1] ?? '')),
            'helps' => $both((string) ($setting['help'] ?? '')),
            'cards' => $both((string) ($page['cards'][$setting['card'] ?? '']['title'] ?? '')),
            'pages' => $both((string) ($page['label'] ?? '')),
            'keyText' => self::fold($key),
        ];

        // One space around every text: a word never runs from one text into the next.
        $texts['text'] = ' '.implode(' ', array_merge($texts['labels'], $texts['olds'], $texts['helps'], $texts['cards'], $texts['pages'], [$texts['keyText']])).' ';

        return $texts;
    }

    /** @return array{entries: list<array>, urls: array<string, ?string>} */
    private static function &memo(): array
    {
        $settings = (array) config('admin_settings.settings', []);
        $pages = (array) config('admin_settings.pages', []);
        // A view-only demo account gets its own addresses (no setting of a closed page: ViewOnlyDemo::hides()).
        $id = spl_object_id(app()).'|'.app()->getLocale().'|'.md5(serialize([array_keys($settings), array_keys($pages), count($settings, COUNT_RECURSIVE)]))
            .(\App\Http\Middleware\ViewOnlyDemo::active() ? '|view-only' : '');

        if (! isset(self::$memo[$id])) {
            // One catalogue per request in practice; tests make a new app each time.
            if (count(self::$memo) > 8) {
                self::$memo = [];
            }

            $entries = [];

            foreach ($settings as $key => $setting) {
                if (! is_array($setting)) {
                    continue;
                }

                $page = $pages[$setting['page'] ?? ''] ?? null;
                $page = is_array($page) ? $page : null;
                $entries[] = ['key' => (string) $key, 'setting' => $setting, 'page' => $page] + self::texts((string) $key, $setting, $page);
            }

            self::$memo[$id] = ['entries' => $entries, 'urls' => []];
        }

        return self::$memo[$id];
    }

    /** Whether this admin works in Developer mode (developer settings shown as fields, not as a note). */
    public static function developer(?User $user): bool
    {
        return class_exists(AdminPreferences::class) && AdminPreferences::developerMode($user);
    }
}
