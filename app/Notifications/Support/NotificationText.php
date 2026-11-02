<?php

namespace App\Notifications\Support;

use Illuminate\Support\Str;

/**
 * The words of a bell notification, translated when it is shown (in the member's or the admin's language), never when
 * it is sent: a notification stores which text it is ("text") and the values that text needs ("params"), plus the
 * English sentence for older code ("message"). Rows of AVDBCMS 1.2.2 and older stored only that English sentence: the
 * sentences of the site's own notifications are read back into their values and translated too, anything else is shown
 * as it was stored, with the stray spaces before punctuation taken out ("Your video : x !" -> "Your video: x!").
 * A notification an administrator sent ("New notification") carries the owner's own title and message: shown as written.
 */
final class NotificationText
{
    public const BROADCAST_TYPE = 'App\\Notifications\\AdminBroadcast';

    /** The longest value a sentence takes (a title, a name); longer ones are cut with an ellipsis. */
    private const VALUE_MAX = 160;

    /** Every text a notification can store and the values it needs. */
    public const TEXTS = [
        'new_video' => ['name', 'title'],
        'new_video_plain' => ['name'],
        'new_comment' => ['name', 'title'],
        'new_comment_plain' => [],
        'new_reply' => ['name', 'title'],
        'new_reply_plain' => [],
        'new_subscriber' => ['name'],
        'video_ready' => ['title'],
        'video_failed' => ['title'],
        'video_suspended' => ['title'],
        'export_ready' => ['file'],
        'export_failed' => [],
        'invoice_ready' => ['site'],
    ];

    /** The English sentences 1.2.2 and older stored, per notification class: [pattern, text]. */
    private const LEGACY = [
        'App\\Notifications\\Activity\\NewVideo' => ['/^(?<name>.+?)\s+has published (?:a )?new video\s*!?$/su', 'new_video_plain'],
        'App\\Notifications\\Activity\\NewSubscriber' => ['/^(?<name>.+?)\s+has subscribed to your channel\s*!?$/su', 'new_subscriber'],
        'App\\Notifications\\Activity\\NewComment' => ['/^You have (?:a )?new comment on your video\s*!?$/su', 'new_comment_plain'],
        'App\\Notifications\\Video\\VideoUploaded' => ['/^Your video\s*:\s*(?<title>.+?)\s+was successfully processed\s*\.?$/su', 'video_ready'],
        'App\\Notifications\\Video\\VideoError' => ['/^The processing of your video\s*:\s*(?<title>.+?)\s+failed\s*!?$/su', 'video_failed'],
        'App\\Notifications\\Video\\VideoBanned' => ['/^Your? video\s+(?<title>.+?)\s+was banned\s*\.?$/su', 'video_suspended'],
        'App\\Notifications\\Export\\ExportSuccess' => ['/^Your\s+(?<file>.+?)\s+export is ready\s*!?$/su', 'export_ready'],
        'App\\Notifications\\Export\\ExportError' => ['/^Your export (?:was )?failed\s*!?$/su', 'export_failed'],
        'App\\Notifications\\Premium\\Invoice' => ['/^Your\s+(?<site>.+?)\s+invoice is available\s*\.?$/su', 'invoice_ready'],
    ];

    /**
     * The data a notification stores: which text, its values, the English sentence (older code reads "message"),
     * then $extra (url, created_at, is_read).
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function data(string $text, array $params, array $extra = []): array
    {
        [$text, $params] = self::resolve($text, $params);

        return ['text' => $text, 'params' => $params, 'message' => self::sentence($text, $params, 'en')] + $extra;
    }

    /**
     * The sentence of a stored notification (its type and data) in $locale (null: the current language).
     *
     * @param  array<string, mixed>|string|null  $data
     */
    public static function message(?string $type, array|string|null $data, ?string $locale = null): string
    {
        $data = self::decode($data);

        if ($type === self::BROADCAST_TYPE) {
            $message = $data['message'] ?? $data['body'] ?? '';

            return is_string($message) ? trim($message) : '';
        }

        $text = $data['text'] ?? null;

        if (is_string($text) && isset(self::TEXTS[$text])) {
            [$text, $params] = self::resolve($text, is_array($data['params'] ?? null) ? $data['params'] : []);

            return self::sentence($text, $params, $locale);
        }

        $stored = is_string($data['message'] ?? null) ? trim($data['message']) : '';

        if ($type !== null && isset(self::LEGACY[$type]) && preg_match(self::LEGACY[$type][0], $stored, $match) === 1) {
            [$text, $params] = self::resolve(self::LEGACY[$type][1], array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY));

            return self::sentence($text, $params, $locale);
        }

        return self::clean($stored);
    }

    /**
     * The sentence of a stored notification in parts, for a list that shows the names and titles in bold (member design
     * 6.14): [['t' => 'trail_runner', 'b' => true], ['t' => ' subscribed to your channel.', 'b' => false]]. The text is
     * plain (the list prints each part as text); joined, the parts are exactly message(). A notification an administrator
     * sent, or a stored sentence of an unknown kind, is one plain part.
     *
     * @param  array<string, mixed>|string|null  $data
     * @return list<array{t: string, b: bool}>
     */
    public static function parts(?string $type, array|string|null $data, ?string $locale = null): array
    {
        $data = self::decode($data);
        $text = $data['text'] ?? null;
        $params = null;

        if ($type !== self::BROADCAST_TYPE && is_string($text) && isset(self::TEXTS[$text])) {
            [$text, $params] = self::resolve($text, is_array($data['params'] ?? null) ? $data['params'] : []);
        } elseif ($type !== null && $type !== self::BROADCAST_TYPE && isset(self::LEGACY[$type])) {
            $stored = is_string($data['message'] ?? null) ? trim($data['message']) : '';

            if (preg_match(self::LEGACY[$type][0], $stored, $match) === 1) {
                [$text, $params] = self::resolve(self::LEGACY[$type][1], array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY));
            }
        }

        if (! is_string($text) || $params === null) {
            $message = self::message($type, $data, $locale);

            return $message === '' ? [] : [['t' => $message, 'b' => false]];
        }

        // The sentence with a marker around each value, cut at the markers: the values are the bold parts. A value can
        // never hold a marker (private-use characters, removed from the values first).
        $marked = [];

        foreach ($params as $name => $value) {
            $value = str_replace(["\u{E000}", "\u{E001}"], '', (string) $value);
            $marked[$name] = "\u{E000}".$value."\u{E001}";
        }

        $sentence = self::sentence($text, $marked, $locale);
        $parts = [];

        foreach (preg_split('/(\x{E000}.*?\x{E001})/u', $sentence, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            $bold = str_starts_with($piece, "\u{E000}");
            $piece = str_replace(["\u{E000}", "\u{E001}"], '', $piece);

            if ($piece !== '') {
                $parts[] = ['t' => $piece, 'b' => $bold];
            }
        }

        return $parts;
    }

    /**
     * The title of a notification an administrator sent (the owner's words), else null.
     *
     * @param  array<string, mixed>|string|null  $data
     */
    public static function title(?string $type, array|string|null $data): ?string
    {
        $data = self::decode($data);
        $title = $data['title'] ?? $data['subject'] ?? null;

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }

    /**
     * What the admin's lists show for a notification, in the admin's language: [title, message]. One an administrator
     * sent: its title (else "Message from you") and its message, as written; the site's own: its sentence (translated,
     * rows of 1.2.2 too) and no message; one without any text: the name of its kind.
     *
     * @param  array<string, mixed>|string|null  $data
     * @return array{0: string, 1: string}
     */
    public static function summary(?string $type, array|string|null $data): array
    {
        $data = self::decode($data);
        $title = self::title($type, $data);
        $message = self::message($type, $data);

        if ($title !== null || $type === self::BROADCAST_TYPE) {
            return [$title ?? (string) __('Message from you'), $message];
        }

        return [$message !== '' ? $message : Str::headline(class_basename((string) $type)), ''];
    }

    /**
     * A sentence in $locale. Every key is written out here, so the translation tools find them.
     *
     * @param  array<string, string>  $params
     */
    public static function sentence(string $text, array $params, ?string $locale = null): string
    {
        return (string) match ($text) {
            'new_video' => __(':name published a new video: :title', $params, $locale),
            'new_video_plain' => __(':name published a new video.', $params, $locale),
            'new_comment' => __(':name commented on your video: :title', $params, $locale),
            'new_comment_plain' => __('You have a new comment on your video.', [], $locale),
            'new_reply' => __(':name replied to your comment on :title', $params, $locale),
            'new_reply_plain' => __('You have a new reply to your comment.', [], $locale),
            'new_subscriber' => __(':name subscribed to your channel.', $params, $locale),
            'video_ready' => __('Your video is ready: :title', $params, $locale),
            'video_failed' => __('Your video could not be processed: :title', $params, $locale),
            'video_suspended' => __('Your video was suspended: :title', $params, $locale),
            'export_ready' => __('Your export is ready: :file', $params, $locale),
            'export_failed' => __('Your export failed.', [], $locale),
            'invoice_ready' => __('Your invoice from :site is ready.', $params, $locale),
            default => '',
        };
    }

    /**
     * The text and its values, made safe: every value one short line of plain text; a text whose value is missing falls
     * back to the plain text that does not need it (a video without a title: "x published a new video.").
     *
     * @param  array<string, mixed>  $params
     * @return array{0: string, 1: array<string, string>}
     */
    private static function resolve(string $text, array $params): array
    {
        $clean = [];

        foreach (self::TEXTS[$text] ?? [] as $name) {
            $value = $params[$name] ?? '';
            $value = is_scalar($value) ? (string) $value : '';
            $value = trim((string) preg_replace('/\s+/u', ' ', $value));
            $clean[$name] = Str::limit($value, self::VALUE_MAX);
        }

        if ($text === 'new_video' && $clean['title'] === '') {
            return ['new_video_plain', ['name' => $clean['name']]];
        }

        if ($text === 'new_comment' && ($clean['name'] === '' || $clean['title'] === '')) {
            return ['new_comment_plain', []];
        }

        if ($text === 'new_reply' && ($clean['name'] === '' || $clean['title'] === '')) {
            return ['new_reply_plain', []];
        }

        return [$text, $clean];
    }

    /** A stored English sentence of an unknown kind, without the spaces 1.2.2 put before ":" "!" "?" "." "," ";". */
    public static function clean(string $sentence): string
    {
        $sentence = trim((string) preg_replace('/\s+/u', ' ', $sentence));

        return (string) preg_replace('/\s+([:!?.,;])/u', '$1', $sentence);
    }

    /**
     * @param  array<string, mixed>|string|null  $data
     * @return array<string, mixed>
     */
    private static function decode(array|string|null $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        $decoded = is_string($data) && $data !== '' ? json_decode($data, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
