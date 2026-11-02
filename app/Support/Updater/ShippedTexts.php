<?php

namespace App\Support\Updater;

/**
 * The texts the releases corrected in their language files, and the correction of a language file an owner edited.
 *
 * An update keeps a lang/<locale>.json the owner edited (TranslationMerge: the owner's text wins for every key both files
 * have, the release adds its new keys), so on its own such a file never gets a text a release corrected (178 in German
 * for 1.2.2). The data file app/Support/Updater/shipped-texts.json (made by scripts/shipped-texts.php from the release
 * zips at every release build) lists, for each language and key whose text changed, the text this version ships ("new")
 * and every older text a release shipped for it ("old").
 *
 * Finish runs correct() on every lang/<locale>.json of the site (the one-click update's Finish in the new code, see
 * Updater::complete(); after an upload by hand: Home's Finish update, "Correct the texts" on Tools > Updates and
 * `php artisan updates:texts`, see Updater::correctLanguageFiles()): a value that is still exactly one of the old
 * shipped texts was never touched by the owner and becomes the new text; any other value (the owner's own text) stays
 * and is counted as kept, and keys only the owner's file has stay as they are. Safe to run again: a corrected file has
 * nothing left to correct.
 *
 * The merge cannot tell the keys a release removed from the owner's own keys, so an edited file kept them all (1.2.3:
 * 593 keys, among them the importers' catalogue names that lane KS withdrew; judge R3-026). The data file also lists
 * every key an older release shipped that no language file of this version has ("removed"), each with the texts it
 * shipped for it, by short SHA-256 (never in words: some of them may not be shipped again). correct() takes such a key
 * out of the file when its text is still exactly one an older release shipped; a text the owner wrote stays.
 *
 * Plain PHP (no Laravel), so the release build's script uses the same code to make the data file.
 */
final class ShippedTexts
{
    /** The data file, relative to the site root (never under lang/, storage/ or public/build/). */
    public const PATH = 'app/Support/Updater/shipped-texts.json';

    public const FORMAT = 1;

    /**
     * An old text a release withdrew because no release may carry it again (1.2.3: translations of the importers'
     * catalogue that named a child or a minor) is listed by its SHA-256, "sha256:<hex>", instead of its words. correct()
     * knows such a text by its hash, so a language file the owner edited still gets the new text; build() never writes
     * the words again, although an older release's zip has them, once the tree's data file lists the hash.
     */
    public const HASHED = 'sha256:';

    /** A language file bigger than this is not read (the shipped ones are about 1 MB). */
    public const MAX_BYTES = 16 * 1024 * 1024;

    /** The data file is never near this (about 0.4 MB for 1.2.0 to 1.2.2). */
    private const MAX_DATA_BYTES = 32 * 1024 * 1024;

    /** Hex characters of the short SHA-256 that "removed" lists keys and texts by (64 bits: shortHash()). */
    public const SHORT = 16;

    private const ABOUT = 'The texts older AVDBCMS releases shipped in lang/<locale>.json for keys whose text this release changed. '
        .'When an update finishes, a text of a language file that is still exactly one of these old texts gets the new one; '
        .'texts the owner changed stay. "removed": the keys older releases shipped that this release no longer has, with '
        .'the texts they had (both by the first 16 hex characters of their SHA-256): such a key leaves a language file while '
        .'its text is still one of them. Made by scripts/shipped-texts.php at every release build: do not edit by hand.';

    /** How the data file lists a withdrawn old text (HASHED). */
    public static function hashed(string $text): string
    {
        return self::HASHED.hash('sha256', $text);
    }

    /** How "removed" lists a key or a text: the first SHORT hex characters of its SHA-256. */
    public static function shortHash(string $text): string
    {
        return substr(hash('sha256', $text), 0, self::SHORT);
    }

    /**
     * The keys older releases shipped that this version no longer has, with the texts they shipped: short hash of the
     * key => short hashes of its texts (any language). Empty when the file has none, is not a data file, or (with $for)
     * was made for another version.
     *
     * @return array<string, list<string>>
     */
    public static function loadRemoved(string $file, ?string $for = null): array
    {
        if (! is_file($file) || (int) @filesize($file) > self::MAX_DATA_BYTES) {
            return [];
        }

        $json = @file_get_contents($file);

        if (! is_string($json) || ($for !== null && self::versions($json)['for'] !== $for)) {
            return [];
        }

        return self::decodeRemoved($json);
    }

    /** @return array<string, list<string>> see loadRemoved() */
    public static function decodeRemoved(string $json): array
    {
        $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json, true);

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! is_array($data['removed'] ?? null)) {
            return [];
        }

        $pattern = '/^[0-9a-f]{'.self::SHORT.'}\z/';
        $removed = [];

        foreach ($data['removed'] as $key => $texts) {
            // (A hash of digits only is an integer key in PHP: compared as text.)
            $key = (string) $key;

            if (preg_match($pattern, $key) !== 1 || ! is_array($texts)) {
                continue;
            }

            $texts = array_values(array_unique(array_filter($texts, fn (mixed $text): bool => is_string($text) && preg_match($pattern, $text) === 1)));

            if ($texts !== []) {
                $removed[$key] = $texts;
            }
        }

        return $removed;
    }

    /** A language code as lang/<locale>.json names it. */
    public static function isLocale(mixed $locale): bool
    {
        return is_string($locale) && preg_match('/^[A-Za-z0-9_-]{1,32}\z/', $locale) === 1;
    }

    /**
     * The texts of a data file: locale => key => ['new' => text, 'old' => [older shipped texts]]. Null when the file is
     * missing, too big, not a data file, or (with $for) made for another version than $for: its "new" texts are then
     * not the ones this version's language files have (an upload by hand that mixed two versions, a working copy whose
     * texts changed since). An entry that is not well-formed is left out (the rest still counts).
     *
     * @return array<string, array<string, array{new: string, old: list<string>}>>|null
     */
    public static function load(string $file, ?string $for = null): ?array
    {
        if (! is_file($file) || (int) @filesize($file) > self::MAX_DATA_BYTES) {
            return null;
        }

        $json = @file_get_contents($file);

        if (! is_string($json) || ($for !== null && self::versions($json)['for'] !== $for)) {
            return null;
        }

        return self::decode($json);
    }

    /** @return array<string, array<string, array{new: string, old: list<string>}>>|null see load() */
    public static function decode(string $json): ?array
    {
        $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json, true);

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! is_array($data['texts'] ?? null)) {
            return null;
        }

        $texts = [];

        foreach ($data['texts'] as $locale => $entries) {
            if (! self::isLocale($locale) || ! is_array($entries)) {
                continue;
            }

            foreach ($entries as $key => $entry) {
                $new = is_array($entry) ? ($entry['new'] ?? null) : null;

                if (! is_string($new) || ! is_array($entry['old'] ?? null)) {
                    continue;
                }

                $old = array_values(array_unique(array_filter($entry['old'], fn (mixed $value): bool => is_string($value) && $value !== $new)));

                if ($old !== []) {
                    $texts[$locale][(string) $key] = ['new' => $new, 'old' => $old];
                }
            }
        }

        return $texts;
    }

    /**
     * The versions a data file speaks of: 'for' (the version whose texts "new" holds) and 'versions' (the releases whose
     * shipped texts "old" holds). Empty when the file is not a data file.
     *
     * @return array{for: ?string, versions: list<string>}
     */
    public static function versions(string $json): array
    {
        $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json, true);

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            return ['for' => null, 'versions' => []];
        }

        $versions = array_values(array_filter((array) ($data['versions'] ?? []), fn (mixed $version): bool => is_string($version) && self::isVersion($version)));

        return ['for' => is_string($data['for'] ?? null) && self::isVersion($data['for']) ? $data['for'] : null, 'versions' => $versions];
    }

    /**
     * Correct one language file's texts: a value that is still exactly an old shipped text becomes the new one
     * (updated); a value that is neither the new nor an old text is the owner's own and stays (kept); a key the file
     * does not have is not added, and keys only the file has stay, except a key this version no longer has whose text is
     * still exactly one an older release shipped for it ($removed, see loadRemoved()): it leaves the file (removed).
     *
     * @param  array<string, string>  $live  the site's language file
     * @param  array<string, array{new: string, old: list<string>}>  $texts  this language's entries of the data file
     * @param  array<string, list<string>>  $removed  the data file's removed keys
     * @return array{data: array<string, string>, updated: int, kept: int, removed: int}
     */
    public static function correct(array $live, array $texts, array $removed = []): array
    {
        $data = $live;
        $updated = 0;
        $kept = 0;
        $gone = 0;

        if ($removed !== []) {
            foreach ($live as $key => $value) {
                $key = (string) $key;
                $shipped = $removed[self::shortHash($key)] ?? null;

                if ($shipped !== null && ! isset($texts[$key]) && in_array(self::shortHash((string) $value), $shipped, true)) {
                    unset($data[$key]);
                    $gone++;
                }
            }
        }

        foreach ($texts as $key => $text) {
            if (! array_key_exists($key, $live)) {
                continue;
            }

            $value = (string) $live[$key];

            if ($value === $text['new']) {
                continue;
            }

            if (in_array($value, $text['old'], true) || in_array(self::hashed($value), $text['old'], true)) {
                $data[$key] = $text['new'];
                $updated++;
            } else {
                $kept++;
            }
        }

        return ['data' => $data, 'updated' => $updated, 'kept' => $kept, 'removed' => $gone];
    }

    /**
     * How many texts of one language file are still exactly an old shipped text (what correct() would give the new
     * text, or take out as a key this version no longer has), or null when the file cannot be read or is not a language
     * file. Reads only.
     *
     * @param  array<string, array{new: string, old: list<string>}>  $texts
     * @param  array<string, list<string>>  $removed  the data file's removed keys (see correct())
     */
    public static function pending(string $file, array $texts, array $removed = []): ?int
    {
        if (! is_file($file) || is_link($file) || (int) @filesize($file) > self::MAX_BYTES) {
            return null;
        }

        $json = @file_get_contents($file);
        $live = is_string($json) ? TranslationMerge::decode($json) : null;

        if ($live === null) {
            return null;
        }

        $result = self::correct($live, $texts, $removed);

        return $result['updated'] + $result['removed'];
    }

    /**
     * One language file of the site, read and corrected (not written). Null when it cannot be read or is not a language
     * file (a file the owner broke stays as it is). text: the corrected file in the layout of Settings > Languages and
     * the file's own line endings, null when nothing changes.
     *
     * @param  array<string, array{new: string, old: list<string>}>  $texts
     * @param  array<string, list<string>>  $removed  the data file's removed keys (see correct())
     * @return array{before: string, text: ?string, updated: int, kept: int, removed: int}|null
     */
    public static function correctFile(string $file, array $texts, array $removed = []): ?array
    {
        if (! is_file($file) || is_link($file) || (int) @filesize($file) > self::MAX_BYTES) {
            return null;
        }

        $before = @file_get_contents($file);
        $live = is_string($before) ? TranslationMerge::decode($before) : null;

        if ($live === null) {
            return null;
        }

        $result = self::correct($live, $texts, $removed);

        return [
            'before' => $before,
            'text' => $result['updated'] > 0 || $result['removed'] > 0 ? TranslationMerge::encode($result['data'], TranslationMerge::lineEnding($before)) : null,
            'updated' => $result['updated'],
            'kept' => $result['kept'],
            'removed' => $result['removed'],
        ];
    }

    /**
     * The language files of a site: lang/<locale>.json at the top of lang/ (locale => path, sorted). Links are left out:
     * a correction replaces the file, and must never replace a link by a file.
     *
     * @return array<string, string>
     */
    public static function files(string $root): array
    {
        $files = [];

        foreach (glob(rtrim(str_replace('\\', '/', $root), '/').'/lang/*.json') ?: [] as $file) {
            $file = str_replace('\\', '/', $file);

            if (preg_match('/^([A-Za-z0-9_-]{1,32})\.json\z/', basename($file), $match) === 1 && is_file($file) && ! is_link($file)) {
                $files[$match[1]] = $file;
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Replace a file through a temporary file in the same folder and a rename (a request never reads half a language
     * file: Laravel stops at a broken one), with the permissions of the file it replaces. Run as root (a command typed
     * over SSH), the new file also keeps the owner and group of the old one, so the site can still save it on
     * Settings > Languages.
     */
    public static function write(string $file, string $text): bool
    {
        $temporary = dirname($file).'/.'.basename($file).'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $text, LOCK_EX) !== strlen($text)) {
            @unlink($temporary);

            return false;
        }

        $mode = @fileperms($file);

        if (is_int($mode)) {
            @chmod($temporary, $mode & 0777);
        }

        if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
            $owner = @fileowner($file);
            $group = @filegroup($file);

            if (is_int($owner)) {
                @chown($temporary, $owner);
            }

            if (is_int($group)) {
                @chgrp($temporary, $group);
            }
        }

        if (! PlanStore::replace($temporary, $file)) {
            // It may carry the read-only mode of the file it was to replace (Windows then refuses to delete it).
            @chmod($temporary, 0644);
            @unlink($temporary);

            return false;
        }

        clearstatcache(true, $file);

        return true;
    }

    /**
     * Does a language file of the site need a look at all? Not when it is exactly as this version shipped it ($shipped:
     * path => SHA-256 of the installed avdbcms-manifest.json): a file the owner never changed has every text right.
     *
     * @param  array<string, string>  $shipped
     */
    public static function edited(string $root, string $locale, array $shipped): bool
    {
        $path = 'lang/'.$locale.'.json';
        $hash = @hash_file('sha256', rtrim(str_replace('\\', '/', $root), '/').'/'.$path);

        return ! is_string($hash) || ! isset($shipped[$path]) || ! hash_equals((string) $shipped[$path], $hash);
    }

    /**
     * Correct every language file of a site that the data file knows and the owner changed, without a backup (after an
     * upload by hand: the owner's own backup of the site is step 1 of that way). A file that cannot be written stays as
     * it was (written: false).
     *
     * @param  array<string, array<string, array{new: string, old: list<string>}>>  $texts  the site's data file
     * @param  array<string, string>  $shipped  path => SHA-256 of the installed manifest (see edited())
     * @param  array<string, list<string>>  $removed  the data file's removed keys (see correct())
     * @return array<string, array{updated: int, kept: int, removed: int, written: bool}> the languages with something to say
     */
    public static function refresh(string $root, array $texts, array $shipped, array $removed = []): array
    {
        $report = [];

        foreach (self::files($root) as $locale => $file) {
            if ((! isset($texts[$locale]) && $removed === []) || ! self::edited($root, $locale, $shipped)
                || ($result = self::correctFile($file, $texts[$locale] ?? [], $removed)) === null) {
                continue;
            }

            $written = $result['text'] === null || self::write($file, $result['text']);

            if ($result['updated'] > 0 || $result['kept'] > 0 || $result['removed'] > 0) {
                $report[$locale] = ['updated' => $written ? $result['updated'] : 0, 'kept' => $result['kept'], 'removed' => $written ? $result['removed'] : 0, 'written' => $written];
            }
        }

        return $report;
    }

    /**
     * The data file for a release: for each language of $current (this release's lang/<locale>.json files) and each of
     * its keys, every text an older release shipped for that key that differs from this release's text.
     *
     * @param  array<string, array<string, string>>  $current  locale => key => text of this release
     * @param  list<array<string, array<string, string|list<string>>>>  $shipped  per older source: locale => key => the
     *                                                                            text (or texts) it shipped
     * @param  list<string>  $versions  the releases whose shipped texts $shipped holds
     * @param  string  $for  the version of this release
     * @param  list<array<string, array<string, string>>>  $releases  older releases' own language files (locale => key =>
     *                                                                text): their keys that no language file of $current
     *                                                                has are listed under "removed"
     * @param  array<string, list<string>>  $carried  the removed keys an earlier data file listed (decodeRemoved()):
     *                                               they stay listed while $current does not have them again
     * @return array{about: string, format: int, for: string, versions: list<string>, texts: array<string, array<string, array{new: string, old: list<string>}>>, removed?: array<string, list<string>>}
     */
    public static function build(array $current, array $shipped, array $versions, string $for, array $releases = [], array $carried = []): array
    {
        $texts = [];
        ksort($current, SORT_STRING);

        foreach ($current as $locale => $values) {
            if (! self::isLocale($locale)) {
                continue;
            }

            $olds = [];

            foreach ($shipped as $source) {
                foreach ((array) ($source[$locale] ?? []) as $key => $old) {
                    if (! array_key_exists($key, $values)) {
                        continue;
                    }

                    foreach ((array) $old as $value) {
                        if (is_string($value) && $value !== (string) $values[$key]) {
                            $olds[(string) $key][$value] = true;
                        }
                    }
                }
            }

            ksort($olds, SORT_STRING);

            foreach ($olds as $key => $old) {
                // a withdrawn text (listed by its hash, HASHED) is never written in words again
                $old = array_filter($old, static fn (mixed $value, int|string $text): bool => str_starts_with((string) $text, self::HASHED)
                    || ! isset($old[self::hashed((string) $text)]), ARRAY_FILTER_USE_BOTH);
                $old = array_map('strval', array_keys($old));
                sort($old, SORT_STRING);
                $texts[$locale][(string) $key] = ['new' => (string) $values[$key], 'old' => $old];
            }
        }

        $versions = array_values(array_unique(array_filter($versions, fn (mixed $version): bool => is_string($version) && self::isVersion($version))));
        usort($versions, 'version_compare');
        $data = ['about' => self::ABOUT, 'format' => self::FORMAT, 'for' => $for, 'versions' => $versions, 'texts' => $texts];
        $removed = self::removedKeys($current, $releases, $carried);

        if ($removed !== []) {
            $data['removed'] = $removed;
        }

        return $data;
    }

    /**
     * The keys older releases shipped that no language file of this release has, with every text they shipped for them
     * (any language), by short hash; and the ones an earlier data file listed, while this release does not have them again.
     *
     * @param  array<string, array<string, string>>  $current
     * @param  list<array<string, array<string, string>>>  $releases
     * @param  array<string, list<string>>  $carried
     * @return array<string, list<string>>
     */
    private static function removedKeys(array $current, array $releases, array $carried): array
    {
        $now = [];

        foreach ($current as $values) {
            foreach (array_keys((array) $values) as $key) {
                $now[self::shortHash((string) $key)] = true;
            }
        }

        $removed = [];

        foreach ($carried as $key => $texts) {
            $key = (string) $key;

            if (! isset($now[$key]) && preg_match('/^[0-9a-f]{'.self::SHORT.'}\z/', $key) === 1) {
                foreach ((array) $texts as $text) {
                    if (is_string($text) && preg_match('/^[0-9a-f]{'.self::SHORT.'}\z/', $text) === 1) {
                        $removed[$key][$text] = true;
                    }
                }
            }
        }

        foreach ($releases as $release) {
            foreach ($release as $locale => $values) {
                if (! self::isLocale($locale)) {
                    continue;
                }

                foreach ((array) $values as $key => $text) {
                    $hash = self::shortHash((string) $key);

                    if (! isset($now[$hash]) && is_string($text)) {
                        $removed[$hash][self::shortHash($text)] = true;
                    }
                }
            }
        }

        ksort($removed, SORT_STRING);

        return array_map(static function (array $texts): array {
            $texts = array_map('strval', array_keys($texts));
            sort($texts, SORT_STRING);

            return $texts;
        }, $removed);
    }

    /** The data file's text: keys in byte order (build() sorts them), 4-space indent, unescaped UTF-8, a final line feed. */
    public static function encodeData(array $data): string
    {
        // An empty language list stays an object ({}), never [] (decode() takes both).
        if (($data['texts'] ?? null) === []) {
            $data['texts'] = new \stdClass();
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * Per language: how many entries the data file has and how many older texts they hold (for the build's log).
     *
     * @param  array<string, array<string, array{new: string, old: list<string>}>>  $texts
     * @return array<string, array{keys: int, old: int}>
     */
    public static function counts(array $texts): array
    {
        $counts = [];

        foreach ($texts as $locale => $entries) {
            $counts[$locale] = ['keys' => count($entries), 'old' => array_sum(array_map(fn (array $entry): int => count($entry['old']), $entries))];
        }

        return $counts;
    }

    private static function isVersion(string $version): bool
    {
        return preg_match('/^(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\z/', $version) === 1;
    }
}
