<?php

namespace App\Support;

use App\Enums\VideoStatus;
use App\Models\Video;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Banning and unbanning videos (Admin > Videos and its bulk bar, Members > Ban with the member's videos). A ban keeps the
 * status the video had in videos.status_before_ban, with the ban's date, and Unban gives it back. 1.2.2 made every
 * unbanned video public: a private video or a draft too, and a video banned before it was ever published became public
 * without a publish date (its "load more" cursor then failed every Most viewed / Trending page, App\Support\SafeCursor).
 */
final class VideoBans
{
    public const COLUMN = 'status_before_ban';

    /**
     * The date of the ban that kept the status (its banned_at, written with it). The status belongs to the video's ban
     * only while the two dates match: after an Undo of the update (files only, the database stays) an older version bans
     * and unbans without these columns, and a status an earlier ban kept must not come back (it would make public a video
     * its creator made private since).
     */
    public const KEPT_AT = 'status_before_ban_at';

    /**
     * What a video banned before 1.2.3 gets back, as no status was kept then: Private. Its publish date cannot tell that
     * it was public when it was banned: the date of the first publication stays when the creator makes a video private
     * or unlisted afterwards, so "it was published once" is not "it was public". Private shows nobody a video its creator
     * may have hidden; the admin makes it public on its page in one step (the answer says so).
     */
    public const LEGACY_STATUS = VideoStatus::PRIVATE;

    /** Whether the columns exist (the update's last step adds them; a ban before that keeps no status, as in 1.2.2). */
    public static function available(): bool
    {
        return Schema::hasColumns('videos', [self::COLUMN, self::KEPT_AT]);
    }

    /** Whether this banned video kept the status it had before its ban. */
    public static function kept(Video $video): bool
    {
        return self::keptStatus($video) !== null;
    }

    /** The status this banned video goes back to when it is unbanned. */
    public static function statusAfterUnban(Video $video): VideoStatus
    {
        return self::keptStatus($video) ?? self::LEGACY_STATUS;
    }

    /**
     * What Unban answers for this banned video: the status it goes back to, or that it is private now because its
     * status from before the ban is not known (VideoController::unban, and the toast of its row in the new admin's list).
     */
    public static function unbanMessage(Video $video): string
    {
        return self::kept($video)
            ? __('Video unbanned. It is back to its status from before the ban: :status.', ['status' => self::label(self::statusAfterUnban($video))])
            : __('Video unbanned. It is private now, because its status from before the ban is not known. You can make it public on its page.');
    }

    /** The admin's word for a status, as the video's page lists them. */
    public static function label(VideoStatus $status): string
    {
        return match ($status) {
            VideoStatus::PUBLIC => __('Public'),
            VideoStatus::UNLISTED => __('Unlisted'),
            VideoStatus::PRIVATE => __('Private'),
            VideoStatus::PLANNED => __('Scheduled'),
            VideoStatus::DRAFT => __('Draft'),
            VideoStatus::BANNED => __('Banned'),
            VideoStatus::FAILED => __('Failed'),
        };
    }

    /**
     * Bans the videos of $query that are not banned yet (one already banned keeps its date and its kept status); each
     * keeps its status for Unban, with the ban's date. A plain update, as before: a member banned with their videos can
     * have thousands.
     *
     * @return int how many were banned
     */
    public static function ban(Builder|Relation $query): int
    {
        $now = now();
        $values = ['status' => VideoStatus::BANNED->value, 'banned_at' => $now];

        if (self::available()) {
            // First in the list: MySQL and MariaDB assign from left to right, so the column takes the status before
            // the ban (SQLite and the standard always read the row as it was). The ban's date goes with it.
            $values = [self::COLUMN => DB::raw('status'), self::KEPT_AT => $now] + $values;
        }

        return (clone $query)->where('status', '!=', VideoStatus::BANNED->value)->update($values);
    }

    /**
     * Unbans one video: it goes back to its status from before the ban. A model update, so VideoObserver gives a video
     * that becomes public its publish date and the search index and IndexNow follow.
     *
     * @return VideoStatus|null the status it has now; null when it was not banned (nothing changed)
     */
    public static function unbanVideo(Video $video): ?VideoStatus
    {
        if ($video->status !== VideoStatus::BANNED) {
            return null;
        }

        $status = self::statusAfterUnban($video);
        $video->update(self::unbannedValues($status, self::available()));

        return $status;
    }

    /**
     * Unbans the banned videos of $query (the bulk bar: the ticked rows of a page): each goes back to the status its ban
     * kept, the others to LEGACY_STATUS. One update per status, then those of them that became public or scheduled
     * without a publish date get one (datePublished()); a chosen video that was not banned is left as it is.
     *
     * @return array{unbanned: int, unknown: int} how many were unbanned, and how many of them had no kept status
     */
    public static function unban(Builder $query): array
    {
        $ids = (clone $query)->where('status', VideoStatus::BANNED->value)->pluck('id')->all();

        if ($ids === []) {
            return ['unbanned' => 0, 'unknown' => 0];
        }

        $banned = Video::query()->whereIn('id', $ids)->where('status', VideoStatus::BANNED->value);
        $available = self::available();
        $unbanned = 0;

        if ($available) {
            foreach (VideoStatus::cases() as $status) {
                if ($status !== VideoStatus::BANNED) {
                    $unbanned += (clone $banned)->where(self::COLUMN, $status->value)->whereColumn(self::KEPT_AT, 'banned_at')
                        ->update(self::unbannedValues($status, $available));
                }
            }
        }

        // the rest kept no status of their ban (banned before 1.2.3, before the update's last step added the columns, or
        // again by an older version after an Undo of the update)
        $unknown = (clone $banned)->update(self::unbannedValues(self::LEGACY_STATUS, $available));
        self::datePublished(Video::query()->whereIn('id', $ids));

        return ['unbanned' => $unbanned + $unknown, 'unknown' => $unknown];
    }

    /**
     * Gives the public and scheduled videos of $query that have no publish date one, as VideoObserver does for a single
     * video that becomes public or scheduled (the bulk bar's plain updates skip it): public ones now, scheduled ones
     * their planned date.
     */
    public static function datePublished(Builder $query): void
    {
        (clone $query)->where('status', VideoStatus::PUBLIC->value)->whereNull('published_at')->update(['published_at' => now()]);
        (clone $query)->where('status', VideoStatus::PLANNED->value)->whereNull('published_at')->whereNotNull('scheduled_at')
            ->update(['published_at' => DB::raw('scheduled_at')]);
    }

    /** Whether two stored dates are the same second (a kept status belongs to the ban of its date); null is no date. */
    public static function sameMoment(mixed $one, mixed $other): bool
    {
        $timestamp = static function (mixed $date): ?int {
            if ($date instanceof DateTimeInterface) {
                return $date->getTimestamp();
            }

            try {
                return is_string($date) && $date !== '' ? Carbon::parse($date)->getTimestamp() : null;
            } catch (Throwable) {
                return null;
            }
        };

        $first = $timestamp($one);

        return $first !== null && $first === $timestamp($other);
    }

    /**
     * @param  bool  $available  whether the columns exist (available(), read once by the caller)
     * @return array<string, mixed>
     */
    private static function unbannedValues(VideoStatus $status, bool $available): array
    {
        return ['status' => $status->value, 'banned_at' => null] + ($available ? [self::COLUMN => null, self::KEPT_AT => null] : []);
    }

    /** The status the video's ban kept: null when none was kept, or it was kept by an earlier ban (KEPT_AT). */
    private static function keptStatus(Video $video): ?VideoStatus
    {
        $kept = $video->getAttribute(self::COLUMN);
        $status = is_numeric($kept) ? VideoStatus::tryFrom((int) $kept) : null;

        if ($status === null || $status === VideoStatus::BANNED) {
            return null;
        }

        return self::sameMoment($video->getAttribute(self::KEPT_AT), $video->getAttribute('banned_at')) ? $status : null;
    }
}
