<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * The unit of a number box on the old settings page (admin_ui v1, developer mode). The new admin prints a number's
 * unit after its box (config/admin_settings.php 'suffix' => 'days' | 'ms' | 'px', translated through __()), and the
 * old page's labels are translated as the new admin's labels, written to lead into that box and unit ("Ряд
 * «Популярные теги»: учитывать последние [30] дн."). The old page printed no unit, so in every translated language its
 * day, millisecond and pixel boxes said no unit at all (1.2.3 round 4, TL-VERIFY10-4). It now prints the same unit after
 * the box, unless the label already names it, as the English old labels do ("Discovery Window Days", "Duration Ms"),
 * so English reads as before.
 */
final class NumberUnit
{
    /** The translated unit to print after the box, or '' when the label already names it as a word of its own. */
    public static function after(string $label, string $unit, ?string $locale = null): string
    {
        $word = trim((string) __($unit, [], $locale));
        if ($word === '') {
            return '';
        }
        $text = (string) __($label, [], $locale);

        return preg_match('/(?<![\pL\pN])' . preg_quote($word, '/') . '(?![\pL\pN])/iu', $text) === 1 ? '' : $word;
    }

    /** The Bootstrap input-group text after the box: <span class="input-group-text">unit</span>, or nothing. */
    public static function suffix(string $label, string $unit, ?string $locale = null): HtmlString
    {
        $word = self::after($label, $unit, $locale);

        return new HtmlString($word === '' ? '' : '<span class="input-group-text">' . e($word) . '</span>');
    }
}
