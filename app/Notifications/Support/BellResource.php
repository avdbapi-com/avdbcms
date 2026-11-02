<?php

namespace App\Notifications\Support;

use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use App\Models\Video;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One row of the bell's list and of the notifications page (member design 6.14): the fields of NotificationResource
 * (id, kind, title, message, url, is_read, created_at) plus what the row draws:
 *  - group, when, iso: the row's group (today / yesterday / week / earlier) and its time in words, in the site's time
 *    zone and date format (time()), the same in the bell and on the page;
 *  - parts: the sentence in parts, the names and titles in bold (NotificationText::parts), plain text;
 *  - avatar: who did it (a subscriber, the member who commented or replied, the channel that published), when the
 *    notification names that member (rows of 1.2.3 on: data.actor_id);
 *  - thumb: the video it is about (data.video_id), only a video the reader may see (their own or a public one).
 * Rows of 1.2.2 and older have neither: the row shows its type icon.
 *
 * The members and videos of a page are read in one query each (preload()), never one per row.
 *
 * @mixin Notification
 */
class BellResource extends NotificationResource
{
    /** Kinds of notification types the model's own map does not know yet (the reply notification). */
    private const EXTRA_KINDS = [
        'App\\Notifications\\Activity\\NewReply' => 'comment',
    ];

    /** @var array<int, array{name: string, avatar: string}> */
    private static array $actors = [];

    /** @var array<int, string> */
    private static array $thumbs = [];

    /**
     * Reads the members and videos the rows of a page name (one query each).
     *
     * @param  iterable<int, Notification>  $notifications
     */
    public static function preload(iterable $notifications, ?User $reader): void
    {
        $actorIds = [];
        $videoIds = [];

        foreach ($notifications as $notification) {
            $data = is_array($notification->data) ? $notification->data : [];

            if (is_numeric($data['actor_id'] ?? null)) {
                $actorIds[(int) $data['actor_id']] = true;
            }

            if (is_numeric($data['video_id'] ?? null)) {
                $videoIds[(int) $data['video_id']] = true;
            }
        }

        self::$actors = [];
        self::$thumbs = [];

        try {
            if ($actorIds !== []) {
                foreach (User::query()->whereIn('id', array_keys($actorIds))->limit(100)->get() as $actor) {
                    if (! $actor->is_banned) {
                        self::$actors[(int) $actor->getKey()] = ['name' => (string) $actor->username, 'avatar' => (string) $actor->avatar_url];
                    }
                }
            }

            if ($videoIds !== []) {
                foreach (Video::query()->whereIn('id', array_keys($videoIds))->limit(100)->get() as $video) {
                    $own = $reader !== null && (int) $video->user_id === (int) $reader->getKey();

                    if ($own || (bool) ($video->is_public ?? false)) {
                        self::$thumbs[(int) $video->getKey()] = (string) $video->thumbnail_url;
                    }
                }
            }
        } catch (Throwable) {
            // the row falls back to its type icon
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = parent::toArray($request);
        $data = is_array($this->data) ? $this->data : [];

        if (($row['kind'] ?? 'other') === 'other' && isset(self::EXTRA_KINDS[(string) $this->type])) {
            $row['kind'] = self::EXTRA_KINDS[(string) $this->type];
        }

        $actorId = is_numeric($data['actor_id'] ?? null) ? (int) $data['actor_id'] : 0;
        $videoId = is_numeric($data['video_id'] ?? null) ? (int) $data['video_id'] : 0;

        $row['parts'] = NotificationText::parts($this->type, $data);
        $row['avatar'] = $actorId > 0 ? (self::$actors[$actorId]['avatar'] ?? null) : null;
        $row['thumb'] = $videoId > 0 ? (self::$thumbs[$videoId] ?? null) : null;

        // The group and the time as the row says them, worked out here for the bell and the notifications page alike.
        $time = self::time($this->created_at);
        $row['group'] = $time['group'];
        $row['when'] = $time['when'];
        $row['iso'] = $time['iso'];

        return $row;
    }

    /**
     * The group of a notification and its time as the rows say them (member design 6.14), in the SITE's time zone and
     * date format (6.0: every member page): today "12 minutes ago", yesterday "Yesterday, 21:06", this week (2 to 6 days
     * ago) "Friday, 23 Oct", earlier the admin's date format ("Oct 14, 2026"). Days are the site's calendar days, so the
     * bell (whatever zone the visitor's device is in) and the notifications page put a row in the same group with the
     * same words. iso: the moment with the site's offset, for the row's <time datetime>.
     *
     * @return array{group: string, when: string, iso: string}
     */
    public static function time(mixed $created, ?CarbonInterface $now = null): array
    {
        $zone = (string) config('app.timezone', 'UTC');
        $now = ($now !== null ? Carbon::instance($now) : Carbon::now())->setTimezone($zone);

        try {
            $at = $created instanceof CarbonInterface
                ? Carbon::instance($created)
                : (is_string($created) && $created !== '' ? Carbon::parse($created) : null);
        } catch (Throwable) {
            $at = null;
        }

        $at = ($at ?? $now->copy())->setTimezone($zone);

        // whole calendar days between the two dates (a day of a daylight-saving change has 23 or 25 hours)
        $days = (int) round((gmmktime(0, 0, 0, $now->month, $now->day, $now->year) - gmmktime(0, 0, 0, $at->month, $at->day, $at->year)) / 86400);

        if ($days <= 0) {
            [$group, $when] = ['today', ($at->greaterThan($now) ? $now : $at)->diffForHumans()];
        } elseif ($days === 1) {
            [$group, $when] = ['yesterday', __('Yesterday, :time', ['time' => $at->format('H:i')])];
        } elseif ($days <= 6) {
            [$group, $when] = ['week', $at->translatedFormat('l, j M')];
        } else {
            $format = trim((string) config('site.date_format', 'M d, Y'));
            [$group, $when] = ['earlier', $at->translatedFormat($format !== '' ? $format : 'M d, Y')];
        }

        return ['group' => $group, 'when' => $when, 'iso' => $at->toIso8601String()];
    }

    /** The kind of a notification type, with the types the model's map does not know yet. */
    public static function kindOf(?string $type): string
    {
        $kind = Notification::kindOf($type);

        return $kind === 'other' ? (self::EXTRA_KINDS[(string) $type] ?? 'other') : $kind;
    }
}
