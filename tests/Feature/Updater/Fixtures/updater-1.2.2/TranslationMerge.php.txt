<?php

namespace App\Support\Updater;

/**
 * A language file the owner changed (lang/<locale>.json whose live SHA-256 is not the installed manifest's): the
 * update merges it instead of overwriting it. The owner's text wins for every key both files have, the release adds
 * its new keys, and keys only the owner's file has stay (an unchanged file is replaced as a whole instead, which is
 * how keys the release removed go away: from an edited file nobody can tell them from the owner's own keys).
 * Settings > Languages saves the same layout (keys sorted, 4-space indent, unescaped UTF-8 and slashes).
 */
final class TranslationMerge
{
    /** lang/<locale>.json at the top of lang/ (lang/<locale>/*.php are kept with a copy of the new file instead). */
    public static function isLanguageFile(string $path): bool
    {
        return preg_match('#^lang/[A-Za-z0-9_-]+\.json$#', $path) === 1;
    }

    /** lang/<locale>/<group>.php */
    public static function isLanguageArray(string $path): bool
    {
        return preg_match('#^lang/[^/]+/.+\.php$#', $path) === 1;
    }

    /**
     * @param  array<string, string>  $live  the site's file (the owner's texts)
     * @param  array<string, string>  $new  the release's file
     * @return array{data: array<string, string>, added: int, kept: int}
     */
    public static function merge(array $live, array $new): array
    {
        $merged = $live;
        $added = 0;
        $kept = 0;

        foreach ($new as $key => $value) {
            if (array_key_exists($key, $live)) {
                if ($live[$key] !== $value) {
                    $kept++;
                }

                continue;
            }

            $merged[$key] = $value;
            $added++;
        }

        return ['data' => $merged, 'added' => $added, 'kept' => $kept];
    }

    /**
     * Decode a language file: a flat object of strings, or null when it is not one (a file the owner broke is kept as
     * it is, with the release's copy next to it).
     *
     * @return array<string, string>|null
     */
    public static function decode(string $json): ?array
    {
        $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json, true);

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            return null;
        }

        foreach ($data as $value) {
            if (! is_string($value)) {
                return null;
            }
        }

        return array_map('strval', $data);
    }

    /** Settings > Languages' layout: keys in byte order, 4-space indent, the file's own line endings, final newline. */
    public static function encode(array $data, string $eol = "\n"): string
    {
        if ($data === []) {
            return '{}'.$eol;
        }

        ksort($data, SORT_STRING);
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR
        );

        return str_replace("\n", $eol, $json).$eol;
    }

    /** The line ending a file mostly uses. */
    public static function lineEnding(string $text): string
    {
        $crlf = substr_count($text, "\r\n");

        return $crlf > 0 && $crlf * 2 >= substr_count($text, "\n") ? "\r\n" : "\n";
    }
}
