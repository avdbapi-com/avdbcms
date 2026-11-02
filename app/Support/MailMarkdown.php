<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Member text (a user name, a video title, a contact message) inside a Markdown mail. Laravel's mail templates escape
 * {{ }} for HTML only and then read the whole mail as Markdown, so a name like [x](https://evil.example) became a live
 * link (or ![x](...) a remote image) in a mail sent from the site's domain. Here the text is HTML-escaped and [ ] \
 * become numeric references, which Markdown prints as they are but never reads as a link, an image or an escape.
 * The visible text stays the same. Security plan S26 / S29.
 */
final class MailMarkdown
{
    public static function text(mixed $value, bool $lineBreaks = false): HtmlString
    {
        $html = strtr(e((string) $value), ['[' => '&#91;', ']' => '&#93;', '\\' => '&#92;']);

        return new HtmlString($lineBreaks ? nl2br($html) : $html);
    }
}
