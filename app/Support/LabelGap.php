<?php

namespace App\Support;

/**
 * A label printed as its own element with its colon, its value after it: "<strong>Caution:</strong> text",
 * "<span class="fw-bold">Next:</span> title" (the label bold, the value not). Between the colon and the value each
 * language writes its own gap: a space in most languages (French keeps its no-break space before the colon and a space
 * after it: "Prudence : texte"), nothing after the Chinese full-width colon "：", which holds its own space
 * ("注意：文本"). The value follows this gap, never a space of the markup, which showed "注意： 直接编辑…" and
 * "下一个： 标题" in Chinese (1.2.3 round 3, R3-048 / VF2-WA-01). So the label element and its value read exactly as the
 * language's own "label: value": AdminText::labelled($label, $value) in the admin, __(':label: :value') on the member
 * side; in every other language the page is the same as before.
 */
final class LabelGap
{
    /**
     * In the admin, after <strong>{{ rtrim(AdminText::labelled($label, '')) }}</strong>: the rest of the admin's label
     * separator (AdminText::punctuation(): ": " in English, "\u{00A0}: " in French, "：" in Chinese), so " " or "".
     */
    public static function admin(?string $locale = null): string
    {
        $separator = AdminText::punctuation($locale)['label'];

        return substr($separator, strlen(rtrim($separator)));
    }

    /**
     * On the member side, after {{ __(':label:', ['label' => $label]) }}: what the language's ":label: :value" puts
     * between the label's colon and the value (" ", or "" in Chinese). That pattern starts with the label's own form
     * (":label:") in every language; a translation that ever did not, or put more than a space there, gets a plain
     * space, as the page had before.
     */
    public static function member(?string $locale = null): string
    {
        $head = (string) __(':label:', ['label' => ''], $locale);
        $pair = (string) __(':label: :value', ['label' => '', 'value' => ''], $locale);
        $gap = $head !== '' && str_starts_with($pair, $head) ? substr($pair, strlen($head)) : ' ';

        return preg_match('/^[ \x{00A0}\x{202F}]*$/u', $gap) === 1 ? $gap : ' ';
    }
}
