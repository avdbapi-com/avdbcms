<?php

namespace App\Support;

/**
 * The layout's chrome without its source indentation (1.2.3 round 1, R1-069).
 *
 * The header, the side menus, the account panel and the footer are Blade partials indented for reading: every line of a
 * menu link, a subscription row or a language choice reached the browser with 16-40 spaces before it, about a third of
 * the 40-60 KB the chrome weighs on a signed-in visitor's page (MEMBER_DESIGN 4.3: the layout of a default-layout member
 * page <= 125 KB raw). compact() replaces each run of white space that stands alone between two tags with one space:
 * the browser collapses such a run to at most one space anyway (or drops it, between blocks and in flex and grid
 * boxes), so the page renders the same. Text with words in it is left as it is, so are attribute values and the
 * contents of <script>, <style>, <pre> and <textarea> (white space matters there).
 */
final class CompactHtml
{
    /** A tag, with quoted attribute values that may hold ">"; a comment; or a DOCTYPE-like declaration. */
    private const TOKEN = '/(<!--.*?-->|<[a-zA-Z\/!](?:[^"\'>]|"[^"]*"|\'[^\']*\')*>)/s';

    /** Elements whose contents keep their white space. */
    private const RAW = ['script', 'style', 'pre', 'textarea'];

    public static function compact(string $html): string
    {
        if ($html === '' || ! str_contains($html, '<')) {
            return $html;
        }

        $parts = preg_split(self::TOKEN, $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $html;
        }

        $out = '';
        $raw = null;

        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                // a tag or a comment
                $out .= $part;

                if ($raw === null && preg_match('/^<('.implode('|', self::RAW).')\b/i', $part, $open) === 1 && ! str_ends_with($part, '/>')) {
                    $raw = strtolower($open[1]);
                } elseif ($raw !== null && preg_match('/^<\/'.$raw.'\s*>$/i', $part) === 1) {
                    $raw = null;
                }

                continue;
            }

            // text between two tags: a run of white space alone becomes one space (inside a raw element: unchanged)
            $out .= ($raw === null && $part !== '' && $part !== ' ' && trim($part, " \t\n\r\f") === '') ? ' ' : $part;
        }

        return $out;
    }
}
