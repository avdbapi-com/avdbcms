<?php

namespace App\Support\Studio;

/**
 * Lean studio HTML (member design 4.3 page-content budgets, studio-20).
 *
 * - squeeze(): the template indentation between tags goes. A run of white space that holds a line break and touches a
 *   tag becomes one line break, a run of two or more spaces or tabs that touches a tag one space: a browser renders
 *   both exactly like the run they replace (white-space: normal and pre-line collapse them the same way). Text between
 *   words is never touched, and <script>, <style>, <textarea> and <pre> blocks are copied as they are (a description's
 *   own line breaks and indentation, inline JSON and scripts). The same pass makes the site's own links, pictures and
 *   form addresses root-relative (relativeAttributes). layouts/user.blade.php squeezes the studio pages' content
 *   section with it.
 * - path(): a named route as a root-relative address that keeps the front controller of the request ("/index.php" on
 *   hosts that need it) and a proxy's forced root: the rows of a list carry it instead of the absolute URL.
 * - countWords(): the counted words of a translation for every count from 1 to $max, as runs [from, text] where the
 *   text holds MARKER for the number: the exact form trans_choice() picks for each count (explicit ranges and the
 *   language's plural rules), without one string per count (avdMkCount() in users/partials/studio-scripts reads it).
 *   The caller passes a closure around its own trans_choice() call written with the literal key, so the key stays
 *   visible to the translation tools (scripts/i18n finds literal keys only).
 */
final class StudioHtml
{
    /** Stands for the number in countWords() texts (a control character no translation contains). */
    public const MARKER = "\u{1}";

    public static function squeeze(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $parts = preg_split('~(<(script|style|textarea|pre)\b[^>]*>.*?</\2\s*>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($parts)) {
            return $html;
        }

        $out = '';
        $count = count($parts);
        $origins = self::origins();

        // [text, block, tag name, text, block, tag name, ..., text]
        for ($i = 0; $i < $count; $i += 3) {
            // A kept block that ends before this text or starts after it is a tag too: the text gets a stand-in ">" /
            // "<" at that end for the rules below, taken off again after them.
            $before = $i > 0;
            $after = $i + 1 < $count;
            $text = ($before ? '>' : '').$parts[$i].($after ? '<' : '');

            // a run with a line break -> one line break; a run of 2+ spaces or tabs -> one space (both touch a tag)
            $squeezed = preg_replace(
                ['~>[ \t]*\r?\n\s*~', '~[ \t]*\r?\n\s*<~', '~>[ \t]{2,}~', '~[ \t]{2,}<~'],
                [">\n", "\n<", '> ', ' <'],
                $text,
            );
            $squeezed = is_string($squeezed) ? $squeezed : $text;
            $squeezed = substr($squeezed, $before ? 1 : 0, $after ? -1 : null);

            $out .= self::relativeAttributes($squeezed, $origins);

            if ($after) {
                $out .= $parts[$i + 1];
            }
        }

        return $out;
    }

    /**
     * The links, pictures and forms of this site in a piece of markup, root-relative: href, src, action, formaction and
     * poster values that start with the site's own origin lose it ("/profile/videos/5/edit"; the front controller in
     * the path stays). Other attributes (a data-copy address, JSON) keep their absolute address.
     *
     * @param  list<string>  $origins
     */
    public static function relativeAttributes(string $html, array $origins): string
    {
        foreach ($origins as $origin) {
            if (! str_contains($html, $origin.'/')) {
                continue;
            }

            $from = [];
            $to = [];

            foreach (['href', 'src', 'action', 'formaction', 'poster'] as $attribute) {
                $from[] = ' '.$attribute.'="'.$origin.'/';
                $to[] = ' '.$attribute.'="/';
            }

            $html = str_replace($from, $to, $html);
        }

        return $html;
    }

    /**
     * The scheme and host of the request and of the URL generator's root (a forced root behind a proxy), each once.
     *
     * @return list<string>
     */
    public static function origins(): array
    {
        $origins = [request()->getSchemeAndHttpHost()];
        $root = parse_url(url('/'));

        if (is_array($root) && isset($root['scheme'], $root['host'])) {
            $origins[] = $root['scheme'].'://'.$root['host'].(isset($root['port']) ? ':'.$root['port'] : '');
        }

        return array_values(array_unique(array_filter($origins, fn ($origin) => is_string($origin) && str_contains($origin, '://'))));
    }

    /**
     * A page's own inline script as it is sent (1.2.3 round 1, R1-069: the 4.3 HTML budgets): its comment lines, its
     * indentation and its empty lines stay in the template, never in the page. Only whole lines that start with "//"
     * after their indentation are left out, so a "//" inside a line (an address in a string) is never touched; the
     * scripts this is used on hold no multi-line string or template literal, where indentation would matter.
     */
    public static function js(string $html): string
    {
        $out = [];

        foreach (preg_split('/\R/', $html) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /** Whether the current page is a studio page whose content section is squeezed. */
    public static function squeezesThisPage(): bool
    {
        return request()->routeIs(
            'user.index',
            'user.videos.*',
            'user.playlists.*',
            'user.comments.*',
            'user.subscribers',
            'user.activity.*',
            'user.reports.*',
            'user.invoices.*',
        );
    }

    /**
     * "/profile/videos/5/edit" ("/index.php/profile/videos/5/edit" behind a front controller in the address, with the
     * folder of a site installed in one: route(..., false) is the path from the domain's root, App\Support\SiteUrlGenerator).
     */
    public static function path(string $name, mixed $parameters = []): string
    {
        return route($name, $parameters, false);
    }

    /**
     * An address of this site without its scheme and host ("/video/sunset-drive-…"); any other address as it is.
     */
    public static function relative(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $origin = request()->getSchemeAndHttpHost();

        return $origin !== '' && str_starts_with($url, $origin.'/') ? substr($url, strlen($origin)) : $url;
    }

    /**
     * A route address with $placeholder where its one parameter goes: "/profile/videos/__ID__/edit".
     */
    public static function pathTemplate(string $name, string $parameter, string $placeholder = '__ID__'): string
    {
        return self::path($name, [$parameter => $placeholder]);
    }

    /**
     * $choice(int $n, string $count) returns the line for $n with $count in place of the number; the caller writes the
     * trans_choice() call with its literal key inside the closure, so the translation tools find the key in the source
     * (users/videos/index.blade.php: $mkPlural).
     *
     * @param  \Closure(int, string): string  $choice
     * @return list<array{0: int, 1: string}>
     */
    public static function countWords(\Closure $choice, int $max): array
    {
        $out = [];
        $last = null;

        for ($n = 1; $n <= max(1, $max); $n++) {
            $text = (string) $choice($n, self::MARKER);

            if ($text !== $last) {
                $out[] = [$n, $text];
                $last = $text;
            }
        }

        return $out;
    }
}
