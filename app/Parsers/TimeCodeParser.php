<?php

namespace App\Parsers;

/**
 * Time codes in a comment or a description ("1:23", "12:05", "1:02:03") become seek buttons of the watch page
 * (videos/show.blade.php, time()). Only real time codes do (watch-22): minutes and seconds under 60 when hours are
 * given, two digits of seconds, and never a piece of a longer number or ratio ("10:30:45:12", "1:234"), nor text inside
 * a link or a tag (a link's address can hold "t=1:23"). The watch page turns a code past the video's length, or under a
 * player it cannot seek, back into text: playable() for the description, resources/js/components/Comments/
 * ExpandComment.tsx for the comments.
 *
 * The input is already HTML-escaped (Parser::apply), so the matched text is digits and colons only.
 */
class TimeCodeParser implements Parseable
{
    // [h:]m:ss or [h:]mm:ss, not glued to other digits or colons.
    private const PATTERN = '/(?<![\d:])(?:(\d{1,2}):)?(\d{1,2}):(\d{2})(?![\d:])/';

    // A whole link (its text is the address) or any other tag: left as it is.
    private const MARKUP = '/(<a\b[^>]*>.*?<\/a>|<[^>]*>)/is';

    // A seek button this parser printed.
    private const BUTTON = '/<button\b[^>]*\bdata-timecode=[\'"](\d+)[\'"][^>]*>([^<]*)<\/button>/';

    public function parse(string $string): string
    {
        $parts = preg_split(self::MARKUP, $string, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $string;
        }

        foreach ($parts as $index => $part) {
            // Odd indexes are the captured markup.
            if ($index % 2 === 1 || $part === '') {
                continue;
            }

            $parts[$index] = preg_replace_callback(self::PATTERN, fn (array $matches): string => self::button($matches), $part) ?? $part;
        }

        return implode('', $parts);
    }

    /**
     * The watch page's description: a code at or past the video's length, or under a player the page cannot seek (an
     * Upload18 video plays in the provider's frame), is plain text instead of a button that does nothing.
     *
     * @param  int  $duration  the video's length in seconds (0: unknown, every code stays a button)
     */
    public static function playable(?string $html, int $duration, bool $seekable): string
    {
        $html = (string) $html;

        if ($html === '' || ! str_contains($html, 'data-timecode=')) {
            return $html;
        }

        return preg_replace_callback(self::BUTTON, function (array $matches) use ($duration, $seekable): string {
            $at = (int) $matches[1];

            return $seekable && ($duration <= 0 || $at < $duration)
                ? $matches[0]
                : '<span class="avd-timecode-text">'.$matches[2].'</span>';
        }, $html) ?? $html;
    }

    /**
     * @param  array<int, string>  $matches
     */
    private static function button(array $matches): string
    {
        $hours = $matches[1] !== '' ? (int) $matches[1] : 0;
        $minutes = (int) $matches[2];
        $seconds = (int) $matches[3];

        if ($seconds > 59 || ($matches[1] !== '' && $minutes > 59)) {
            return $matches[0];
        }

        $timeCode = $hours * 3600 + $minutes * 60 + $seconds;
        $label = e(__('Play from :time', ['time' => $matches[0]]));

        return "<button type='button' style='vertical-align: inherit' onclick='time($timeCode)' class='btn btn-link btn-sm p-0 text-decoration-none avd-timecode' data-timecode='$timeCode' aria-label='$label'>{$matches[0]}</button>";
    }
}
