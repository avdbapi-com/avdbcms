<?php

namespace App\Support;

/**
 * Detects the writing system of a video or hero title so the views can add a
 * title-script-<script> class: the theme CSS then swaps the display font for one
 * that covers the script and drops uppercase / letter-spacing for scripts that
 * have no case (Arabic, Devanagari, Thai, CJK), whatever the interface language.
 *
 * The public card renderer (resources/js/components/Videos/Video.tsx) mirrors these
 * rules; keep the two in step.
 */
class TitleScript
{
    public const SCRIPTS = ['latin', 'vietnamese', 'arabic', 'devanagari', 'thai', 'cjk', 'cyrillic', 'other'];

    /**
     * Letters per script, checked in this order (non-Latin scripts before Latin). Letters only: shared punctuation
     * (the Arabic comma, the danda, CJK brackets) never counts, while shared letters such as the long vowel mark do
     * (PCRE2 10.40+ matches \p{Arabic} by Script_Extensions; Video.tsx uses \p{Script_Extensions=...}).
     */
    private const PATTERNS = [
        'arabic' => '/(?=\p{L})\p{Arabic}/u',
        'devanagari' => '/(?=\p{L})\p{Devanagari}/u',
        'thai' => '/(?=\p{L})\p{Thai}/u',
        'cjk' => '/(?=\p{L})[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u',
        'cyrillic' => '/(?=\p{L})\p{Cyrillic}/u',
    ];

    /**
     * Vietnamese letters: the precomposed tone-mark block (U+1EA0-U+1EF9) and the horned
     * o/u, which no other Latin orthography uses.
     */
    private const VIETNAMESE = '/[\x{1EA0}-\x{1EF9}\x{01A0}\x{01A1}\x{01AF}\x{01B0}]/u';

    /** d-stroke with a circumflex or breve vowel: Vietnamese without a tone mark ("Đâu"). */
    private const VIETNAMESE_WEAK = '/[\x{0110}\x{0111}].*[\x{0102}\x{0103}\x{00C2}\x{00E2}\x{00CA}\x{00EA}\x{00D4}\x{00F4}]|[\x{0102}\x{0103}\x{00C2}\x{00E2}\x{00CA}\x{00EA}\x{00D4}\x{00F4}].*[\x{0110}\x{0111}]/u';

    /**
     * latin | vietnamese | arabic | devanagari | thai | cjk | cyrillic | other.
     *
     * A non-Latin script wins when it has at least a quarter of the letters, so
     * "BTS 방탄소년단 MV" is cjk while "Tokyo 東京 night walk" stays latin. Titles without
     * letters (numbers, emoji) are latin.
     */
    public static function detect(?string $text): string
    {
        $text = trim((string) $text);

        if ($text === '' || ! preg_match('/\p{L}/u', $text)) {
            return 'latin';
        }

        $letters = (int) preg_match_all('/\p{L}/u', $text);
        $latin = (int) preg_match_all('/(?=\p{L})\p{Latin}/u', $text);

        $best = null;
        $bestCount = 0;
        foreach (self::PATTERNS as $script => $pattern) {
            $count = (int) preg_match_all($pattern, $text);
            if ($count > $bestCount) {
                $best = $script;
                $bestCount = $count;
            }
        }

        if ($best !== null && $bestCount * 4 >= $letters) {
            return $best;
        }

        // Letters that are neither Latin nor one of the scripts above (Greek, Hebrew,
        // Georgian, Bengali, Tamil...) dominate the title.
        if (($letters - $latin - $bestCount) * 2 > $letters) {
            return 'other';
        }

        if (preg_match(self::VIETNAMESE, $text) || preg_match(self::VIETNAMESE_WEAK, $text)) {
            return 'vietnamese';
        }

        return 'latin';
    }

    /** The class the views put on a title element: title-script-<script>. */
    public static function cssClass(?string $text): string
    {
        return 'title-script-'.self::detect($text);
    }
}
