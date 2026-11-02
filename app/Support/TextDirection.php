<?php

namespace App\Support;

/**
 * 1.2.3 round 3 (R3-015, R3-060; replaces R2A-064's unicode-bidi: plaintext): a creator's or member's own words (a
 * video or playlist title, a tagline, a description, the band's button, a name) are laid out in THEIR direction, not the
 * page's. A Latin title on an Arabic page keeps its start and ends in its own full stop or "…"; an Arabic title on an
 * English page the same way round.
 *
 * The direction is the one dir="auto" would give (the text's first strong letter) and it is printed as a real dir
 * attribute, because only the element's own direction moves a clamp's or a text-overflow's ellipsis to the text's end
 * in Chromium, WebKit and Firefox alike (with unicode-bidi: plaintext the element stayed right to left: WebKit and
 * Firefox drew the "…" over the first letters, Chromium pushed it out of the box). It is printed only where it differs
 * from the page's own direction: a page whose texts read its own way gets no extra bytes (the page budgets of member
 * design 4.3).
 */
final class TextDirection
{
    /**
     * Letters written right to left (bidi classes R and AL): Hebrew, Arabic, Syriac, Thaana, NKo, Samaritan, Mandaic and
     * the Arabic supplements (U+0590-U+08FF), the Hebrew and Arabic presentation forms, and the right-to-left blocks of
     * the supplementary planes (U+10800-U+10FFF, U+1E800-U+1EFFF).
     */
    private const RTL_LETTERS = '/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}\x{10800}-\x{10FFF}\x{1E800}-\x{1EFFF}]/u';

    /**
     * 'ltr' or 'rtl': the direction of the text's first strong character, as dir="auto" decides it (a letter, or a
     * left-to-right, right-to-left or Arabic letter mark; digits, punctuation, spaces and emoji do not count). Null when
     * the text has none.
     */
    public static function of(?string $text): ?string
    {
        if ($text === null || $text === '' || preg_match('/[\p{L}\x{200E}\x{200F}\x{061C}]/u', $text, $first) !== 1) {
            return null;
        }

        return match ($first[0]) {
            "\u{200E}" => 'ltr',
            "\u{200F}", "\u{061C}" => 'rtl',
            default => preg_match(self::RTL_LETTERS, $first[0]) === 1 ? 'rtl' : 'ltr',
        };
    }

    /**
     * Whether the page is laid out right to left: the same rule as components/layout.blade.php's <html dir> (the
     * language's 'rtl' flag in config/languages.php, else Arabic, Persian, Hebrew and Urdu; the admin's site-wide
     * right-to-left switch for every language). Keep the two the same (tests/Feature/CreatorTextDirectionTest.php
     * compares them). A few array lookups: not cached, so a changed language or setting is always seen.
     */
    public static function pageIsRtl(): bool
    {
        if (filter_var(config('site.rtl_support', false), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }
        $locale = (string) app()->getLocale();
        $dashed = str_replace('_', '-', $locale);
        $base = strtolower(explode('-', $dashed)[0]);
        $catalog = (array) config('languages', []);
        $info = (array) ($catalog[$locale] ?? $catalog[$dashed] ?? $catalog[$base] ?? []);

        return array_key_exists('rtl', $info)
            ? filter_var($info['rtl'], FILTER_VALIDATE_BOOLEAN)
            : in_array($base, ['ar', 'fa', 'he', 'ur'], true);
    }

    /**
     * The attribute to print on the element that holds the text (and cuts it): ' dir=ltr' or ' dir=rtl' when the text
     * reads the other way than the page, else nothing. Usage: <a class="mk-lrow__title"{!! TextDirection::attr($title) !!}>
     */
    public static function attr(?string $text): string
    {
        $direction = self::of($text);
        if ($direction === null || ($direction === 'rtl') === self::pageIsRtl()) {
            return '';
        }

        return ' dir=' . $direction;
    }
}
