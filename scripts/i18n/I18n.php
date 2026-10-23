<?php

/*
|--------------------------------------------------------------------------
| Translation tooling shared by scripts/i18n/*.php and scripts/i18n_check.php
|--------------------------------------------------------------------------
|
| Plain PHP (no Laravel boot) so the release build can run it from any shell.
| Keys are the English text: Laravel JSON translations (lang/<locale>.json) and the
| Preact bundles (resources/js/lang/<locale>.ts).
|
| Required keys = the inventory (scripts/i18n/inventory.json: the new admin and
| theme strings) + every literal __() / trans() / @lang() key found in the
| source paths of scripts/i18n/sources.json; for the .ts files every literal t() key.
|
| Fallback rule for a required key that has no translation of its own:
|   exact key -> the translation of its OLD label (inventory "old_label") -> English.
|
*/

namespace Avd\I18n;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class Paths
{
    public static function root(?string $root): string
    {
        $root = $root !== null && $root !== '' ? $root : dirname(__DIR__, 2);
        $real = realpath($root);
        if ($real === false || !is_dir($real . '/lang')) {
            throw new RuntimeException("Not a project root (no lang/ folder): {$root}");
        }

        return str_replace('\\', '/', $real);
    }

    /** @return array<int, string> locale codes of lang/*.json, sorted */
    public static function locales(string $root): array
    {
        $codes = array_map(static fn (string $f) => basename($f, '.json'), glob($root . '/lang/*.json') ?: []);
        sort($codes);

        return $codes;
    }

    public static function lang(string $root, string $locale): string
    {
        return $root . '/lang/' . $locale . '.json';
    }

    public static function data(string $root, string $file): string
    {
        return $root . '/scripts/i18n/' . $file;
    }
}

/**
 * lang/<locale>.json reader/writer that keeps the files' format: 4-space pretty print, unescaped
 * UTF-8 and slashes, the file's own line endings, keys sorted by byte order (what the existing
 * translate scripts produce).
 */
final class LangFile
{
    /** @return array{0: array<string, string>, 1: string} [translations, line ending] */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            return [[], "\n"];
        }
        $raw = (string) file_get_contents($path);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid JSON in {$path}: " . json_last_error_msg());
        }
        $out = [];
        foreach ($data as $key => $value) {
            if (!is_string($value)) {
                throw new RuntimeException("Non-string value for key '{$key}' in {$path}");
            }
            $out[(string) $key] = $value;
        }

        return [$out, substr_count($raw, "\r\n") * 2 >= substr_count($raw, "\n") && str_contains($raw, "\r\n") ? "\r\n" : "\n"];
    }

    /** @param array<string, string> $data */
    public static function encode(array $data, string $eol = "\n"): string
    {
        ksort($data, SORT_STRING);
        $json = $data === [] ? '{}' : json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR
        );

        return str_replace("\n", $eol, $json) . $eol;
    }

    /** Writes only when the content changed; returns true when it did. */
    public static function write(string $path, array $data, string $eol): bool
    {
        $encoded = self::encode($data, $eol);
        if (is_file($path) && file_get_contents($path) === $encoded) {
            return false;
        }
        $tmp = $path . '.tmp' . getmypid();
        file_put_contents($tmp, $encoded, LOCK_EX);
        if (!@rename($tmp, $path)) {
            copy($tmp, $path);
            @unlink($tmp);
        }

        return true;
    }
}

/**
 * resources/js/lang/<locale>.ts - the Preact strings (i18next, ICU syntax): `export default { "English": "...", }`.
 * Keeps the files' style (4-space indent, a comma after every entry) and order; new keys are appended.
 */
final class JsLangFile
{
    /** @return array<int, string> locale codes with a resources/js/lang/<code>.ts file */
    public static function locales(string $root): array
    {
        $codes = array_map(static fn (string $f) => basename($f, '.ts'), glob($root . '/resources/js/lang/*.ts') ?: []);
        $codes = array_values(array_filter($codes, static fn (string $c) => preg_match('/^[a-z]{2,3}$/', $c) === 1));
        sort($codes);

        return $codes;
    }

    public static function path(string $root, string $locale): string
    {
        return $root . '/resources/js/lang/' . $locale . '.ts';
    }

    /** @return array{0: array<string, string>, 1: string} [translations, line ending] */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            return [[], "\n"];
        }
        $raw = (string) file_get_contents($path);
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false) {
            throw new RuntimeException("No object literal in {$path}");
        }
        $body = preg_replace('/,(\s*)$/', '$1', substr($raw, $start + 1, $end - $start - 1));
        $data = json_decode('{' . $body . '}', true);
        if (!is_array($data)) {
            throw new RuntimeException("Unreadable translations in {$path}: " . json_last_error_msg());
        }

        return [array_map('strval', $data), str_contains($raw, "\r\n") ? "\r\n" : "\n"];
    }

    /** @param array<string, string> $data */
    public static function encode(array $data, string $eol = "\n"): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;
        $lines = ['export default {'];
        foreach ($data as $key => $value) {
            $lines[] = '    ' . json_encode((string) $key, $flags) . ': ' . json_encode($value, $flags) . ',';
        }
        $lines[] = '}';

        return implode($eol, $lines) . $eol;
    }

    public static function write(string $path, array $data, string $eol): bool
    {
        $encoded = self::encode($data, $eol);
        if (is_file($path) && file_get_contents($path) === $encoded) {
            return false;
        }
        file_put_contents($path, $encoded, LOCK_EX);

        return true;
    }
}

/**
 * scripts/i18n/inventory.json: rows {en, where, old_label, source}.
 */
final class Inventory
{
    /** @return array<string, array{en: string, where: string, old_label: ?string, source: string}> keyed by the English text */
    public static function load(string $root): array
    {
        $path = Paths::data($root, 'inventory.json');
        if (!is_file($path)) {
            return [];
        }
        $doc = json_decode((string) file_get_contents($path), true);
        $rows = [];
        foreach ($doc['strings'] ?? [] as $row) {
            $en = (string) ($row['en'] ?? '');
            if ($en === '' || isset($rows[$en])) {
                continue;
            }
            $rows[$en] = [
                'en' => $en,
                'where' => (string) ($row['where'] ?? ''),
                'old_label' => isset($row['old_label']) && $row['old_label'] !== '' ? (string) $row['old_label'] : null,
                'source' => (string) ($row['source'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Keys whose current value in this locale is right although it looks like a fallback:
     *   - keep_english.json "strings": proper nouns and brands, English in every locale;
     *   - accepted.json "locales.<code>": values a translator confirmed - the machine translator kept the English
     *     (Filipino "Username", German "Layout") or a translation equals the old label's (both recorded by sync.php).
     *
     * @return array<string, true>
     */
    public static function accepted(string $root, string $locale): array
    {
        $out = [];
        foreach (['keep_english.json' => 'strings', 'accepted.json' => 'locales'] as $file => $field) {
            $path = Paths::data($root, $file);
            $doc = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
            foreach ($field === 'strings' ? ($doc['strings'] ?? []) : ($doc['locales'][$locale] ?? []) as $text) {
                $out[(string) $text] = true;
            }
        }

        return $out;
    }

    /** keep_english.json alone: the strings that are English in every locale by policy (proper nouns, brands). @return array<string, true> */
    public static function keepEnglish(string $root): array
    {
        $path = Paths::data($root, 'keep_english.json');
        $doc = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];

        return array_fill_keys(array_map('strval', $doc['strings'] ?? []), true);
    }

    /**
     * Only a single word ("Username", "Layout", "OK") may be accepted as English automatically: a machine translator
     * that returns a phrase unchanged has usually skipped it, so phrases go back on the todo list (or into
     * keep_english.json when they are names).
     */
    public static function acceptableAsEnglish(string $text): bool
    {
        return !preg_match('/\s/u', trim($text));
    }

    /** Adds keys to accepted.json for each locale ([code => [keys]]). */
    public static function accept(string $root, array $keysByLocale): void
    {
        $path = Paths::data($root, 'accepted.json');
        $doc = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $doc['about'] ??= 'Per-locale keys whose value was confirmed by a translator although it equals the English text '
            . 'or the old label\'s translation. Written by scripts/i18n/sync.php (--accept-english, reviewed and machine '
            . 'translations); read by scripts/i18n_check.php and the todo lists.';
        foreach ($keysByLocale as $code => $keys) {
            $list = array_values(array_unique(array_merge($doc['locales'][$code] ?? [], $keys)));
            sort($list, SORT_STRING);
            $doc['locales'][$code] = $list;
        }
        ksort($doc['locales'], SORT_STRING);
        file_put_contents($path, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
}

/**
 * Tokens a translation must carry over unchanged: Laravel :name replacements, {template} words,
 * HTML tags and entities, printf markers.
 */
final class Placeholders
{
    /** Illustrative wording, not a real token: translators may translate it. */
    private const IGNORED = ['{braces}'];

    // Laravel replacements are case-sensitive and may be camelCase (:repliesCount, :fileSize); a colon right after a
    // letter, digit or another colon ("Note:x", "http://") does not start one. Same rule as scripts/translate_engine.mjs.
    private const PATTERN = '/<\/?[a-zA-Z][^<>]*>|&[a-zA-Z]+;|&#\d+;|\{[A-Za-z_][A-Za-z0-9_.]*\}|\{\d+\}|(?<![\w:\/])[:][A-Za-z_][A-Za-z0-9_]*|%(?:\d+\$)?[sd]/';

    /** @return array<int, string> sorted token list ("|" counts as a token outside plural strings: "Name | address") */
    public static function of(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $m);
        $tokens = array_values(array_filter($m[0], static fn (string $t) => !in_array($t, self::IGNORED, true)));
        if (!self::plural($text)) {
            $tokens = array_merge($tokens, array_fill(0, substr_count($text, '|'), '|'));
        }
        sort($tokens, SORT_STRING);

        return $tokens;
    }

    /**
     * Laravel plural syntax: explicit ("{0} none|{1} one|[2,*] many") or implicit forms around a :count replacement
     * ("1 video|:count videos" - trans_choice() picks the form by the locale's plural rule); or an ICU plural/select
     * ("{count, plural, =1 {...}}"). A literal "|" in help text ("Name | address") has no :count beside it.
     */
    public static function plural(string $text): bool
    {
        return (str_contains($text, '|') && (bool) preg_match('/(?:^|\|)\s*(?:\{\d+\}|\[\d+\s*,\s*(?:\d+|\*)\])|(?<![\w:\/]):count(?![A-Za-z0-9_])/', $text))
            || (bool) preg_match('/\{\s*\w+\s*,\s*(?:plural|select|selectordinal)\s*,/', $text);
    }


    public static function same(string $english, string $translation): bool
    {
        $diff = self::diff($english, $translation);

        return $diff['missing'] === [] && $diff['extra'] === [];
    }

    /**
     * Plural strings ("{0} none|{1} :count video|[2,*] :count videos") may have more or fewer forms per
     * language: there only the set of replacement tokens has to match.
     * A translation may write a :name replacement in Laravel's ucfirst or UPPER form (:Name, :NAME): Laravel
     * replaces those too.
     *
     * @return array{missing: array<int, string>, extra: array<int, string>}
     */
    public static function diff(string $english, string $translation): array
    {
        $want = self::of($english);
        $have = self::of($translation);
        if (self::plural($english)) {
            $set = static fn (array $tokens) => array_values(array_unique(array_filter($tokens, static fn (string $t) => !preg_match('/^\{\d+\}$/', $t))));
            $want = $set($want);
            $have = $set($have);
            $covers = static fn (string $w, array $list) => array_filter($list, static fn (string $h) => self::fits($w, $h)) !== [];

            return [
                'missing' => array_values(array_filter($want, static fn (string $w) => !$covers($w, $have))),
                'extra' => array_values(array_filter($have, static fn (string $h) => array_filter($want, static fn (string $w) => self::fits($w, $h)) === [])),
            ];
        }
        $missing = $want;
        $extra = $have;
        foreach ([true, false] as $exact) { // identical tokens first, then the ucfirst / UPPER forms
            foreach ($extra as $hi => $token) {
                foreach ($missing as $wi => $wanted) {
                    if ($exact ? $wanted === $token : self::fits($wanted, $token)) {
                        unset($missing[$wi], $extra[$hi]);
                        break;
                    }
                }
            }
        }

        return ['missing' => array_values($missing), 'extra' => array_values($extra)];
    }

    /** $have stands for $want: the same token, or the :Name / :NAME form Laravel derives from :name. */
    private static function fits(string $want, string $have): bool
    {
        if ($want === $have) {
            return true;
        }
        if (!str_starts_with($want, ':') || !str_starts_with($have, ':')) {
            return false;
        }
        $name = substr($want, 1);

        return $have === ':' . ucfirst($name) || $have === ':' . strtoupper($name);
    }
}

/**
 * trans_choice() lines as Laravel prints them. Laravel's MessageSelector picks the form of an implicit "a|b|c" by the
 * locale's plural index - always the first form in vi, id, ja, ko, ms, th, tl, tr and zh, one of three in ru, one of
 * six in ar, the first form for 0 and 1 in fr, fil and hi - so a translation shaped like the English
 * "1 video|:count videos" prints "1 video" for 5 videos in Vietnamese. Explicit forms ("{1} ...|[0,*] ...") are picked
 * by the number in every locale.
 */
final class Plurals
{
    /** The numbers a line is printed with when it is checked (every plural index of the 18 locales occurs). */
    private const RANGE = 120;

    private static ?object $selector = null;

    private static bool $loaded = false;

    /** Laravel's MessageSelector from the target root's vendor/ or this project's (null when neither has Laravel). */
    public static function selector(?string $root = null): ?object
    {
        if (!self::$loaded) {
            self::$loaded = true;
            if (!class_exists(\Illuminate\Translation\MessageSelector::class)) {
                foreach (array_unique(array_filter([$root, dirname(__DIR__, 2)])) as $base) {
                    if (is_file($base . '/vendor/autoload.php')) {
                        require_once $base . '/vendor/autoload.php';
                        break;
                    }
                }
            }
            self::$selector = class_exists(\Illuminate\Translation\MessageSelector::class) ? new \Illuminate\Translation\MessageSelector() : null;
        }

        return self::$selector;
    }

    /** A plural line with a :count replacement (the lines trans_choice() fills in). */
    public static function counted(string $english): bool
    {
        return Placeholders::plural($english) && self::hasCount($english);
    }

    /**
     * The numbers (0-120) for which the translation prints a form without the number although the English form shows
     * it, while that same form also stands for other numbers ("1 video" for 5 videos, "une fois" for 0). A form used
     * for exactly one number may leave the number out (Arabic dual, "once"). [] = right; null = not checked.
     *
     * @return array<int, int>|null
     */
    public static function wrongNumbers(string $english, string $translation, string $locale, ?string $root = null): ?array
    {
        if ($translation === '' || !self::counted($english)) {
            return [];
        }
        $selector = self::selector($root);
        if ($selector === null) {
            return null;
        }
        $forms = [];
        for ($n = 0; $n <= self::RANGE; $n++) {
            $forms[$selector->choose($translation, $n, $locale)][] = $n;
        }
        $wrong = [];
        foreach ($forms as $form => $numbers) {
            if (count($numbers) === 1 || self::hasCount((string) $form)) {
                continue;
            }
            foreach ($numbers as $n) {
                if (self::hasCount($selector->choose($english, $n, 'en'))) {
                    $wrong[] = $n;
                }
            }
        }
        sort($wrong);

        return $wrong;
    }

    /**
     * The English line as an explicit choice that prints right in every locale ("{1} 1 video|[0,*] :count videos"):
     * the fallback a locale shows until it has its own forms. Lines that are not "one|other" stay as they are.
     */
    public static function explicit(string $english): string
    {
        $segments = explode('|', $english);
        if (count($segments) !== 2 || !self::counted($english) || preg_match('/^\s*[\{\[]/', $segments[0]) || preg_match('/^\s*[\{\[]/', $segments[1])) {
            return $english;
        }

        return '{1} ' . $segments[0] . '|[0,*] ' . $segments[1];
    }

    /**
     * True when the translation's form for 1 says neither the number nor an approved number word of the language although
     * the English form for 1 shows the digit ("1 found nothing." -> "I found nothing.": the machine translator read the
     * sample "1" as a subject). The digit may be written in the locale's own digits. Number words: numberWords().
     */
    public static function lostOne(string $english, string $translation, string $locale, ?string $root = null): bool
    {
        if ($translation === '' || !self::counted($english)) {
            return false;
        }
        $selector = self::selector($root);
        if ($selector === null) {
            return false;
        }
        $englishOne = (string) $selector->choose($english, 1, 'en');
        if (!preg_match('/(?<![\d.,])1(?![\d.,]?\d)/', $englishOne) && !self::hasCount($englishOne)) {
            return false; // "Searched once": the English form for 1 has no digit either
        }
        $form = (string) $selector->choose($translation, 1, $locale);
        if (self::hasCount($form)) {
            return false;
        }
        $ascii = preg_replace_callback('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}\x{0966}-\x{096F}\x{0E50}-\x{0E59}\x{FF10}-\x{FF19}]/u', static function (array $m): string {
            $c = mb_ord($m[0]);
            foreach ([0xFF10, 0x0E50, 0x0966, 0x06F0, 0x0660] as $zero) {
                if ($c >= $zero) {
                    return (string) ($c - $zero);
                }
            }

            return $m[0];
        }, $form) ?? $form;
        if (preg_match('/(?<![\d.,])1(?![\d.,]?\d)/', $ascii)) {
            return false;
        }
        foreach (self::numberWords($locale, $root) as $word) {
            $latin = preg_match('/^[\p{Latin}]+$/u', $word) === 1;
            if ($latin ? preg_match('/(?<!\p{L})' . preg_quote($word, '/') . '(?!\p{L})/iu', $form) : mb_stripos($form, $word) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Words that say "one" per locale (scripts/i18n/number_words.json, shared with translate_engine.mjs). Latin-script
     * words must stand alone; other scripts may be part of a longer word (ja 一件, zh 一个, ar واحدة).
     *
     * @return array<int, string>
     */
    public static function numberWords(string $locale, ?string $root = null): array
    {
        static $words = [];
        $file = Paths::data($root ?? dirname(__DIR__, 2), 'number_words.json');
        if (!isset($words[$file])) {
            $doc = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $words[$file] = is_array($doc['locales'] ?? null) ? $doc['locales'] : [];
        }

        return array_values(array_map('strval', $words[$file][strtolower($locale)] ?? []));
    }

    private static function hasCount(string $line): bool
    {
        return (bool) preg_match('/(?<![\w:\/]):(?:count|Count|COUNT)(?![A-Za-z0-9_])/', $line);
    }
}

/**
 * Protected brand and product names (scripts/i18n/brands.json): a name found in the English text must reach the
 * translation verbatim (any case) or as one of the spellings approved for that locale.
 */
final class Brands
{
    /** @var array<string, array{brands: array<string, array<string, array<int, string>>>, keys: array<string, array<int, string>>, rx: array<string, string>}> */
    private static array $cache = [];

    /** @return array{brands: array<string, array<string, array<int, string>>>, keys: array<string, array<int, string>>, rx: array<string, string>} */
    public static function load(string $root): array
    {
        if (isset(self::$cache[$root])) {
            return self::$cache[$root];
        }
        $path = Paths::data($root, 'brands.json');
        $doc = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $brands = is_array($doc['brands'] ?? null) ? $doc['brands'] : [];
        $keys = is_array($doc['keys'] ?? null) ? $doc['keys'] : [];
        $rx = [];
        foreach (array_unique(array_merge(array_keys($brands), ...array_values(array_map('array_values', $keys ?: [[]])))) as $name) {
            $rx[(string) $name] = '/(?<![A-Za-z0-9])' . preg_quote((string) $name, '/') . '(?![A-Za-z0-9])/';
        }

        return self::$cache[$root] = ['brands' => $brands, 'keys' => $keys, 'rx' => $rx];
    }

    /** The protected names that occur in this English text (global ones, plus the per-key ones of $key). @return array<int, string> */
    public static function in(string $root, string $key, string $source): array
    {
        $doc = self::load($root);
        $names = array_keys($doc['brands']);
        foreach ($doc['keys'][$key] ?? [] as $name) {
            $names[] = (string) $name;
        }
        $found = [];
        foreach (array_unique($names) as $name) {
            if (preg_match($doc['rx'][$name], $source)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * The brands of the English text that the translation changed (neither verbatim nor an approved spelling).
     *
     * @return array<int, string>
     */
    public static function lost(string $root, string $key, string $source, string $value, ?string $locale = null): array
    {
        if ($value === '' || $value === $source) {
            return [];
        }
        $doc = self::load($root);
        $lost = [];
        foreach (self::in($root, $key, $source) as $name) {
            if (preg_match('/(?<![A-Za-z0-9])' . preg_quote($name, '/') . '(?![A-Za-z0-9])/i', $value)) {
                continue;
            }
            $spellings = $doc['brands'][$name] ?? [];
            $allowed = $locale !== null ? array_merge($spellings[$locale] ?? [], $spellings['*'] ?? []) : array_merge(...array_values($spellings ?: [[]]));
            foreach ($allowed as $spelling) {
                if ($spelling !== '' && mb_stripos($value, (string) $spelling) !== false) {
                    continue 2;
                }
            }
            $lost[] = $name;
        }

        return $lost;
    }
}

/**
 * Invisible characters that machine translation leaves in values (zero-width space U+200B, joiners U+200C/U+200D, word
 * joiner U+2060, BOM U+FEFF, soft hyphen U+00AD, a stray left-to-right mark U+200E). Right-to-left locales may use the
 * marks U+200E/U+200F on purpose (an LTR file name in Arabic text); joiners between two letters of Indic or Arabic script
 * shape the text and are kept.
 */
final class ZeroWidth
{
    public const RTL = ['ar', 'fa', 'he', 'ur'];

    /** @return array<int, string> the invisible characters of $value that $source does not have, as "U+200B" */
    public static function found(string $source, string $value, string $locale = ''): array
    {
        $out = [];
        foreach (self::scan($value, $locale) as $char) {
            if (!str_contains($source, $char)) {
                $out[] = sprintf('U+%04X', mb_ord($char));
            }
        }

        return array_values(array_unique($out));
    }

    /** $value without the invisible characters $source does not have; spaces doubled by the removal are not touched. */
    public static function strip(string $source, string $value, string $locale = ''): string
    {
        foreach (array_unique(self::scan($value, $locale)) as $char) {
            if (!str_contains($source, $char)) {
                $value = self::remove($value, $char, $locale);
            }
        }

        return $value;
    }

    /** @return array<int, string> */
    private static function scan(string $value, string $locale): array
    {
        if (!preg_match_all('/[\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}\x{00AD}\x{200E}\x{200F}]/u', $value, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $out = [];
        foreach ($m[0] as [$char, $offset]) {
            if (self::meaningful($value, $char, (int) $offset, $locale)) {
                continue;
            }
            $out[] = $char;
        }

        return $out;
    }

    private static function meaningful(string $value, string $char, int $offset, string $locale): bool
    {
        if (in_array($char, ["\u{200E}", "\u{200F}"], true)) {
            return in_array($locale, self::RTL, true);
        }
        if (in_array($char, ["\u{200C}", "\u{200D}"], true)) {
            $before = mb_substr(substr($value, 0, $offset), -1);
            $after = mb_substr(substr($value, $offset + strlen($char)), 0, 1);

            return (bool) preg_match('/[\p{Devanagari}\p{Arabic}\p{Bengali}\p{Thai}]/u', $before) && (bool) preg_match('/[\p{Devanagari}\p{Arabic}\p{Bengali}\p{Thai}]/u', $after);
        }

        return false;
    }

    private static function remove(string $value, string $char, string $locale): string
    {
        $out = '';
        $offset = 0;
        while (($pos = strpos($value, $char, $offset)) !== false) {
            $out .= substr($value, $offset, $pos - $offset);
            if (self::meaningful($value, $char, $pos, $locale)) {
                $out .= $char;
            }
            $offset = $pos + strlen($char);
        }

        return $out . substr($value, $offset);
    }
}

/**
 * A translated value that still holds a whole English sentence of its source (machine translation returned part of the
 * text untranslated): a sentence of 4+ words with 3+ English words, placeholders, brands and quoted text excluded.
 * Port of the review probe work/i18n3-review/scripts/partial_english.py.
 */
final class PartialEnglish
{
    /** The first English sentence of $source found verbatim in $value, or null. */
    public static function sentence(string $source, string $value, array $brands = []): ?string
    {
        if ($value === '' || $value === $source) {
            return null;
        }
        if (!preg_match_all('/[^.!?;:]+[.!?]?/u', $source, $m)) {
            return null;
        }
        foreach ($m[0] as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '' || !str_contains($value, $sentence)) {
                continue;
            }
            $plain = preg_replace('/<[^>]+>|\{[^}]*\}|(?<![\w:\/]):[A-Za-z_]\w*|"[^"]*"|\'[^\']*\'|https?:\/\/\S+/u', ' ', $sentence) ?? $sentence;
            foreach ($brands as $brand) {
                $plain = str_replace($brand, ' ', $plain);
            }
            // English prose: 3+ lowercase words (a run of Capitalised Words is a name or a title, "Martin Luther King Jr."),
            // not a comma list of examples ("cyber, gold, oled, dracula")
            preg_match_all("/(?<![:\\w'])[a-z][a-z']+(?![\\w'])/", $plain, $lower);
            preg_match_all("/(?<![:\\w])[A-Za-z][a-z']+/", $plain, $words);
            $list = substr_count($plain, ',') >= 2 && substr_count($plain, ',') + 1 >= count($words[0]) - 1;
            if (count(preg_split('/\s+/', trim($sentence)) ?: []) >= 4 && count($words[0]) >= 3 && count($lower[0]) >= 3 && !$list) {
                return $sentence;
            }
        }

        return null;
    }
}

/**
 * Finds literal translation keys in source files and data-driven labels in config arrays and catalog classes.
 * sources.json: {"paths": [...], "exclude": [...], "config": {"config/x.php": ["label", ...]},
 *                "catalogs": {"App\\Support\\X::method": ["label", ...]}}.
 */
final class Extractor
{
    private const CALL = '/(?<![\w>$\\\\-])(?:__|trans_choice|trans|@lang|@choice|Lang::get|Lang::choice)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")\s*[,)]/s';

    /** @var array<string, string> catalog callables that could not be read in the last scan => why */
    public static array $skipped = [];

    /** @return array{paths: array<int, string>, exclude: array<int, string>, config: array<string, array<int, string>>, catalogs: array<string, array<int, string>>} */
    public static function sources(string $root): array
    {
        $path = Paths::data($root, 'sources.json');
        $doc = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return [
            'paths' => array_values($doc['paths'] ?? ['resources/views', 'app', 'routes']),
            'exclude' => array_values($doc['exclude'] ?? []),
            'config' => (array) ($doc['config'] ?? []),
            'catalogs' => (array) ($doc['catalogs'] ?? []),
        ];
    }

    /**
     * @return array<string, string> key => first location (relative path)
     */
    public static function scan(string $root, ?array $sources = null): array
    {
        $sources ??= self::sources($root);
        $groups = array_map(static fn (string $f) => basename($f, '.php'), glob($root . '/lang/en/*.php') ?: []);
        $found = [];
        foreach ($sources['paths'] as $rel) {
            foreach (self::files($root, $rel, $sources['exclude']) as $file) {
                $relFile = substr($file, strlen($root) + 1);
                foreach (self::fromSource((string) file_get_contents($file), $groups) as $key) {
                    $found[$key] ??= $relFile;
                }
            }
        }
        foreach ($sources['config'] as $rel => $fields) {
            if (!is_file($root . '/' . $rel)) {
                continue;
            }
            foreach (self::fromConfig($root, $rel, (array) $fields) as $key) {
                $found[$key] ??= $rel;
            }
        }
        self::$skipped = [];
        foreach ($sources['catalogs'] as $callable => $fields) {
            foreach (self::fromCatalog($root, (string) $callable, (array) $fields) as $key) {
                $found[$key] ??= $callable;
            }
        }

        return $found;
    }

    /**
     * Literal first arguments of translation calls; skips interpolated strings and PHP group keys (auth.failed).
     *
     * @param array<int, string> $groups PHP translation groups (lang/en/*.php names)
     * @return array<int, string>
     */
    public static function fromSource(string $code, array $groups = []): array
    {
        if (!preg_match_all(self::CALL, $code, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
            return [];
        }
        $keys = [];
        foreach ($matches as $m) {
            $key = $m[1] !== null ? self::unescapeSingle($m[1]) : self::unescapeDouble((string) $m[2]);
            if ($key === null || trim($key) === '') {
                continue;
            }
            if (preg_match('/^([a-z0-9_-]+)\.[A-Za-z0-9_.-]+$/', $key, $g) && in_array($g[1], $groups, true)) {
                continue;
            }
            $keys[] = $key;
        }

        return array_values(array_unique($keys));
    }

    /**
     * Literal t('...') / i18n.t('...') keys of the Preact code (resources/js, the lang folder excepted).
     *
     * @return array<string, string> key => first location
     */
    public static function scanJs(string $root): array
    {
        $base = $root . '/resources/js';
        if (!is_dir($base)) {
            return [];
        }
        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($iterator as $file) {
            $name = str_replace('\\', '/', $file->getPathname());
            if (preg_match('/\.(ts|tsx|js|jsx)$/', $name) && !str_contains($name, '/resources/js/lang/')) {
                $files[] = $name;
            }
        }
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            foreach (self::fromJs((string) file_get_contents($file)) as $key) {
                $found[$key] ??= substr($file, strlen($root) + 1);
            }
        }

        return $found;
    }

    /** @return array<int, string> */
    public static function fromJs(string $code): array
    {
        $pattern = '/(?:(?<![\w.$])t|\bi18n(?:ext)?\.t)\(\s*([\'"`])((?:(?!\1)[^\\\\]|\\\\.)*)\1\s*[,)]/s';
        if (!preg_match_all($pattern, $code, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $keys = [];
        foreach ($matches as $m) {
            if ($m[1] === '`' && str_contains($m[2], '${')) {
                continue;
            }
            $key = preg_replace_callback('/\\\\(u\{[0-9a-fA-F]+\}|u[0-9a-fA-F]{4}|.)/s', static function (array $e): string {
                $c = $e[1];
                if ($c[0] === 'u' && strlen($c) > 1) {
                    return mb_chr(hexdec(trim(substr($c, 1), '{}')), 'UTF-8');
                }

                return ['n' => "\n", 't' => "\t", 'r' => "\r"][$c] ?? $c;
            }, $m[2]);
            if (trim($key) !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * String values of the given field names anywhere in a config array, plus the labels of
     * "options" maps (value => label).
     *
     * @param array<int, string> $fields
     * @return array<int, string>
     */
    public static function fromConfig(string $root, string $rel, array $fields): array
    {
        self::boot($root);
        $config = (static fn (string $file) => require $file)($root . '/' . $rel);

        return self::collect(is_array($config) ? $config : [], $fields);
    }

    /**
     * Labels that a catalog class hands to the views, which render them with __($label) - invisible to the source scan.
     * "Class::method" names a public static method without arguments that returns an array, "Class::CONSTANT" an array
     * constant (also a private one: read by reflection, nothing is called); the listed fields are collected anywhere in
     * it as in fromConfig(), "*" takes the labels of a flat value => label map and "**" every string at any depth, for
     * arrays that hold nothing but labels (["layout" => ["spotlight" => "Spotlight", ...]], ["ticker" => ["Top plays",
     * ...]]). A class, method or constant the target root does not have (yet) is skipped and noted in self::$skipped.
     *
     * @param array<int, string> $fields
     * @return array<int, string>
     */
    public static function fromCatalog(string $root, string $callable, array $fields): array
    {
        [$class, $member] = array_pad(explode('::', $callable, 2), 2, '');
        // "Class::method('key', null)": a static method called with literal arguments (strings, numbers, null, true/false)
        $args = [];
        if (preg_match('/^(\w+)\((.*)\)$/s', $member, $call) === 1) {
            $member = $call[1];
            $args = self::literalArgs($call[2]);
            if ($args === null) {
                self::$skipped[$callable] = 'arguments must be literals';

                return [];
            }
        }
        self::boot($root);
        $constant = preg_match('/^[A-Z][A-Z0-9_]*$/', $member) === 1;
        if ($member === '' || !class_exists($class) || ($constant ? !(new \ReflectionClass($class))->hasConstant($member) : !method_exists($class, $member))) {
            self::$skipped[$callable] = 'not found';

            return [];
        }
        try {
            $data = $constant ? (new \ReflectionClass($class))->getConstant($member) : $class::$member(...$args);
        } catch (\Throwable $e) {
            self::$skipped[$callable] = $e->getMessage();

            return [];
        }
        if (!is_array($data)) {
            self::$skipped[$callable] = 'did not return an array';

            return [];
        }
        $keys = in_array('*', $fields, true)
            ? array_values(array_filter($data, static fn ($v, $k) => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH))
            : [];
        if (in_array('**', $fields, true)) {
            $leaves = static function (array $node) use (&$leaves): array {
                $out = [];
                foreach ($node as $v) {
                    if (is_string($v)) {
                        $out[] = $v;
                    } elseif (is_array($v)) {
                        array_push($out, ...$leaves($v));
                    }
                }

                return $out;
            };
            $keys = array_merge($keys, $leaves($data));
        }
        // identifiers, colours, URLs and CSS values are data, not text
        $text = static fn (string $s) => preg_match('/\p{L}/u', $s) && !preg_match('/^(?:[a-z0-9_.:\/-]+|#[0-9a-fA-F]{3,8})$|:\/\/|sans-serif|monospace/', $s);

        return array_values(array_unique(array_filter(array_merge($keys, self::collect($data, $fields)), $text)));
    }

    /** @return array<int, string|int|bool|null>|null the literal arguments of "'a', null, 3", null when one is not a literal */
    private static function literalArgs(string $list): ?array
    {
        $list = trim($list);
        if ($list === '') {
            return [];
        }
        $token = '/\G\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|(null|true|false)|(-?\d+))\s*(?:,|$)/i';
        $args = [];
        $offset = 0;
        while ($offset < strlen($list)) {
            if (preg_match($token, $list, $m, PREG_UNMATCHED_AS_NULL, $offset) !== 1 || $m[0] === '') {
                return null;
            }
            $offset += strlen($m[0]);
            $args[] = match (true) {
                $m[1] !== null => self::unescapeSingle($m[1]),
                $m[2] !== null => stripcslashes($m[2]),
                $m[3] !== null => ['null' => null, 'true' => true, 'false' => false][strtolower($m[3])],
                default => (int) $m[4],
            };
        }

        return $args;
    }

    /**
     * String values of the given field names anywhere in an array, plus the labels of "options" maps (value => label)
     * and of the other listed fields that hold a value => text map ("option_help", "more_options").
     *
     * @param array<int, string> $fields
     * @return array<int, string>
     */
    private static function collect(array $data, array $fields): array
    {
        $keys = [];
        $wanted = array_flip($fields);
        $walk = static function ($node) use (&$walk, &$keys, $wanted): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $k => $v) {
                if (is_string($k) && isset($wanted[$k])) {
                    if (is_string($v)) {
                        $keys[] = $v;
                    } elseif (in_array($k, ['option_help', 'more_options'], true) && is_array($v)) {
                        foreach ($v as $text) {
                            if (is_string($text)) {
                                $keys[] = $text;
                            }
                        }
                    } elseif ($k === 'options' && is_array($v)) {
                        foreach ($v as $optionValue => $label) {
                            if (is_string($optionValue) && is_string($label)) {
                                $keys[] = $label;
                            } elseif (is_array($label) && isset($label['label']) && is_string($label['label'])) {
                                $keys[] = $label['label'];
                            }
                        }
                    }
                }
                if (is_array($v)) {
                    $walk($v);
                }
            }
        };
        $walk($data);

        return array_values(array_unique(array_filter($keys, static fn (string $s) => trim($s) !== '')));
    }

    /**
     * Config files and catalog classes may call env() / config() / base_path(): load the target root's autoloader and,
     * outside a running app, create (not boot) its application so the helpers resolve.
     */
    private static function boot(string $root): void
    {
        static $ready = false;
        if ($ready || !is_file($root . '/vendor/autoload.php')) {
            return;
        }
        require_once $root . '/vendor/autoload.php';
        if (class_exists(\Illuminate\Foundation\Application::class)
            && !\Illuminate\Container\Container::getInstance() instanceof \Illuminate\Foundation\Application) {
            try {
                require $root . '/bootstrap/app.php';
            } catch (\Throwable) {
                // plain config arrays still load without the container
            }
        }
        $ready = true;
    }

    /** @return iterable<string> */
    private static function files(string $root, string $rel, array $exclude): iterable
    {
        $path = $root . '/' . trim($rel, '/');
        if (is_file($path)) {
            yield $path;

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        $list = [];
        foreach ($iterator as $file) {
            $name = str_replace('\\', '/', $file->getPathname());
            if (!str_ends_with($name, '.php')) {
                continue;
            }
            $relName = substr($name, strlen($root) + 1);
            foreach ($exclude as $skip) {
                if ($relName === $skip || str_starts_with($relName, rtrim($skip, '/') . '/')) {
                    continue 2;
                }
            }
            $list[] = $name;
        }
        sort($list, SORT_STRING);
        yield from $list;
    }

    private static function unescapeSingle(string $s): string
    {
        return strtr($s, ['\\\\' => '\\', "\\'" => "'"]);
    }

    /** PHP double-quoted string rules; null when the string interpolates a variable. */
    private static function unescapeDouble(string $s): ?string
    {
        if (preg_match('/(?<!\\\\)(?:\\\\\\\\)*\$[A-Za-z_{]|\{\$/', $s)) {
            return null;
        }

        return preg_replace_callback('/\\\\(?:([nrtvef\\\\$"])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/', static function (array $m): string {
            if (($m[1] ?? '') !== '') {
                return ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'][$m[1]];
            }
            if (($m[2] ?? '') !== '') {
                return chr(octdec($m[2]) & 255);
            }
            if (($m[3] ?? '') !== '') {
                return chr(hexdec($m[3]));
            }

            return mb_chr(hexdec($m[4]), 'UTF-8');
        }, $s);
    }
}

/**
 * Per-locale state of a required key and the fallback rule.
 */
final class Status
{
    public const MISSING = 'missing';       // absent or empty
    public const ENGLISH = 'english';       // identical to the English key (and not accepted as English)
    public const OLD_LABEL = 'old_label';   // the old label's translation (fallback, may not match the new wording)
    public const TRANSLATED = 'translated'; // a translation of this exact text (or a confirmed old-label one)
    public const KEPT = 'kept';             // English accepted for this key (proper noun, or confirmed by the translator)
    public const CORRUPT = 'corrupt';       // letters lost to a non-UTF-8 write ("Th?m") or a translator's note left in

    /**
     * @param array<string, string> $locale the locale's translations
     * @param array<string, true> $accepted Inventory::accepted(): values confirmed as they are
     * @param string|null $source the English text when lang/en.json maps the key to something else
     *                            (plural forms: "views" => "{0} :count views|...")
     */
    public static function of(string $key, ?string $oldLabel, array $locale, string $code, array $accepted = [], ?string $source = null): string
    {
        $value = $locale[$key] ?? '';
        if ($value === '') {
            return self::MISSING;
        }
        if ($code === 'en') {
            return self::TRANSLATED;
        }
        if (self::corrupt($value, $source ?? $key)) {
            return self::CORRUPT;
        }
        // the English text, also in the explicit plural shape the fallback writes ("{1} 1 video|[0,*] :count videos")
        if ($value === $key || $value === $source || $value === Plurals::explicit($source ?? $key)) {
            return isset($accepted[$key]) || !preg_match('/\p{L}/u', $key) ? self::KEPT : self::ENGLISH;
        }
        $old = self::oldLabelTranslation($oldLabel, $locale);
        if ($old !== null && $value === $old && $oldLabel !== $key && !isset($accepted[$key])) {
            return self::OLD_LABEL;
        }

        return self::TRANSLATED;
    }

    /** The old label's translation in this locale, when it really is one. */
    public static function oldLabelTranslation(?string $oldLabel, array $locale): ?string
    {
        if ($oldLabel === null || $oldLabel === '') {
            return null;
        }
        $value = $locale[$oldLabel] ?? '';

        return $value !== '' && $value !== $oldLabel ? $value : null;
    }

    /**
     * exact key -> old label's translation -> English. Another locale gets an English plural line in its explicit
     * shape (Plurals::explicit), so trans_choice() prints the right English form there until it is translated.
     */
    public static function fallback(string $key, ?string $oldLabel, array $locale, string $code, ?string $source = null): string
    {
        if (($locale[$key] ?? '') !== '') {
            return $locale[$key];
        }
        $source ??= $key;
        if ($code !== 'en') {
            $old = self::oldLabelTranslation($oldLabel, $locale);
            if ($old !== null && Placeholders::same($source, $old)) {
                return $old;
            }

            return Plurals::explicit($source);
        }

        return $source;
    }

    public static function needsTranslation(string $status): bool
    {
        return in_array($status, [self::MISSING, self::ENGLISH, self::OLD_LABEL, self::CORRUPT], true);
    }

    /**
     * "Th?m" for "Thêm", "?nce" for "İnce", "v? d?: Xem sau" for "ví dụ: Xem sau", "Tinh t?" for "Tinh tế", "?: vi" for
     * "例: vi", "??????" for Arabic text, "2?5" for "2–5", or "Anime - Anime (no translation needed)".
     */
    public static function corrupt(string $value, string $source): bool
    {
        if (stripos($value, 'translation needed') !== false && stripos($source, 'translation needed') === false) {
            return true;
        }
        if (substr_count($value, '?') <= substr_count($source, '?')) {
            return false;
        }
        // Every non-ASCII character became "?": runs of them, a "?" glued to the letters of a word, a lone "?" word
        // inside the sentence or as the whole value, or a lost last letter ("v? d?: Xem sau") - a real question mark
        // ends the sentence.
        if (preg_match('/\?\?|\?\p{L}|(?:\p{L}|^|\s)\?(?=[,.:;)\]]|\s+\p{Ll})|^[\s?]+$/u', $value)) {
            return true;
        }
        // ... or the last letter of a one- or two-letter word of a value without any non-ASCII character left ("Tinh t?").
        // A question the translation asks where the English text does not ("Wer zahlt die Prämie?", "¿Quién paga?
        // Cancelar", "Google neler listeleyebilir?") is not corruption.
        if (!preg_match('/[^\x00-\x7F]/', $value) && preg_match('/(?<!\p{L})\p{L}{1,2}\?/u', $value)) {
            return true;
        }
        // typographic punctuation of the English text lost to "?" by the same non-UTF-8 write
        foreach (['—', '–', '…', '’', '‘', '“', '”', '→', '←', '•', '·'] as $char) {
            if (str_contains($source, $char) && !str_contains($value, $char)) {
                return true;
            }
        }

        return false;
    }
}

/**
 * The required key set with each key's old label.
 */
final class Requirements
{
    /**
     * source = the English text to translate: lang/en.json's value when it differs from the key, else the key.
     *
     * @return array<string, array{old_label: ?string, origin: string, source: string}>
     */
    public static function collect(string $root, bool $withSources = true): array
    {
        [$en] = LangFile::read(Paths::lang($root, 'en'));
        $required = [];
        foreach (Inventory::load($root) as $key => $row) {
            $required[$key] = ['old_label' => $row['old_label'], 'origin' => 'inventory:' . $row['where'], 'source' => $en[$key] ?? $key];
        }
        if ($withSources) {
            foreach (Extractor::scan($root) as $key => $where) {
                $required[$key] ??= ['old_label' => null, 'origin' => $where, 'source' => $en[$key] ?? $key];
            }
        }

        return $required;
    }

    /**
     * Preact keys: every literal t() key of resources/js; source = resources/js/lang/en.ts value or the key.
     *
     * @return array<string, array{old_label: null, origin: string, source: string}>
     */
    public static function collectJs(string $root): array
    {
        [$en] = JsLangFile::read(JsLangFile::path($root, 'en'));
        $required = [];
        foreach (Extractor::scanJs($root) as $key => $where) {
            $required[$key] = ['old_label' => null, 'origin' => $where, 'source' => $en[$key] ?? $key];
        }

        return $required;
    }
}

/**
 * Tiny argv parser: --name=value, --name value, --flag.
 */
final class Cli
{
    /** @return array<string, string|bool> */
    public static function options(array $argv): array
    {
        $out = [];
        $args = array_slice($argv, 1);
        for ($i = 0; $i < count($args); $i++) {
            $a = $args[$i];
            if (!str_starts_with($a, '--')) {
                continue;
            }
            $a = substr($a, 2);
            if (str_contains($a, '=')) {
                [$k, $v] = explode('=', $a, 2);
                $out[$k] = $v;
            } elseif (isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '--') && in_array($a, ['root', 'from', 'locale', 'todo', 'strict', 'min-coverage'], true)) {
                $out[$a] = $args[++$i];
            } else {
                $out[$a] = true;
            }
        }

        return $out;
    }
}
