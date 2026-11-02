<?php

namespace App\Support\Upload18;

use App\Data\UploadSettings;
use App\Models\Upload18Claim;
use App\Models\Video;
use App\Services\Upload18Service;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The scheduler's upload18:finish-pending, two tasks of its own (App\Console\Kernel), both started only while there is
 * work:
 *
 *  - run(), every minute: every pending draft that is due is checked with ONE look at the account's newest videos (one
 *    Upload18 list call per page, at most 3 pages, stopping once all were seen), a few detail calls for drafts that are
 *    no longer among the newest, and the drafts Upload18 finished get their embed code (UploadClaims::settle()). Short
 *    and bounded: no sleeping, no queue (the scheduler's queue worker does not serve the uploads queue), at most
 *    MAX_PER_PASS drafts and $seconds of work.
 *  - thumbnails() (--thumbnails), every minute: one finished draft whose member's browser sent no frames gets server
 *    thumbnails (ffmpeg on the Upload18 stream, else poster cards). That can take minutes (fetching the stream, ffmpeg),
 *    so it never runs inside the minute check: the check of every other draft never waits for it.
 *
 * Safe while an update is between its file swap and its Finish (no table: no work) and without a saved key (nothing to
 * ask: not due).
 */
final class PendingUploadFinisher
{
    public const MAX_PER_PASS = 100;

    /** Detail calls (GET /getvideodetail/{vid}) per pass, for drafts that fell out of the newest 150 videos. */
    public const MAX_DETAIL_CALLS = 3;

    public function __construct(
        private readonly Upload18Service $upload18,
        private readonly UploadClaims $claims,
    ) {}

    /** True when a pending draft is due for its check (the minute task's "when"). */
    public static function due(): bool
    {
        if (! UploadClaims::available() || ! app(Upload18Service::class)->hasKey()) {
            return false;
        }

        return Upload18Claim::query()
            ->where('state', Upload18Claim::PENDING)
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->exists();
    }

    /** True when a finished draft waits for server thumbnails (the thumbnail task's "when"). */
    public static function thumbnailsDue(): bool
    {
        return UploadClaims::available() && Upload18Claim::query()->where('needs_thumbnails', true)->exists();
    }

    /**
     * @return array{due: int, finished: int, waiting: int, failed: int, unavailable: bool}
     */
    public function run(int $seconds = 50): array
    {
        $deadline = microtime(true) + max(5, $seconds);
        $stats = ['due' => 0, 'finished' => 0, 'waiting' => 0, 'failed' => 0, 'unavailable' => false];

        if (! UploadClaims::available() || ! $this->upload18->hasKey()) {
            return $stats;
        }

        $due = Upload18Claim::query()
            ->where('state', Upload18Claim::PENDING)
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->orderBy('next_check_at')
            ->orderBy('id')
            ->limit(self::MAX_PER_PASS)
            ->get();

        $stats['due'] = $due->count();

        if ($due->isEmpty()) {
            return $stats;
        }

        try {
            $items = $this->upload18->findItems($due->pluck('did')->all(), 50, 3);
        } catch (Upload18Unavailable $e) {
            $stats['unavailable'] = true;
            Log::warning('Upload18 pending uploads: '.$e->getMessage().' Checking again in 2 minutes.');

            foreach ($due as $claim) {
                $this->claims->waitForUpload18($claim);
            }

            return $stats;
        }

        $details = 0;

        foreach ($due as $claim) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $item = $items[$claim->did] ?? null;

            // Not among the newest 150 any more (a busy account, or the scheduler was off for a while): ask Upload18
            // for that one video by its vid, a few per pass. The answer counts only when it names this did.
            if ($item === null && filled($claim->vid) && $details < self::MAX_DETAIL_CALLS) {
                $details++;

                try {
                    $item = $this->upload18->itemByVid((string) $claim->vid, (string) $claim->did);
                } catch (Upload18Unavailable) {
                    $this->claims->waitForUpload18($claim);
                    $stats['unavailable'] = true;

                    continue;
                }
            }

            try {
                $result = $this->claims->settle($claim, $item);
            } catch (Throwable $e) {
                Log::error('Upload18 pending upload '.$claim->getKey().' could not be checked: '.class_basename($e).': '.$e->getMessage());

                continue;
            }

            if (isset($stats[$result])) {
                $stats[$result]++;
            }
        }

        return $stats;
    }

    /** Server thumbnails for one finished draft whose member's browser sent none. Returns how many were stored. */
    public function thumbnails(): int
    {
        if (! UploadClaims::available()) {
            return 0;
        }

        $claim = Upload18Claim::query()->where('needs_thumbnails', true)->orderBy('id')->first();

        if ($claim === null) {
            return 0;
        }

        // Taken off the list first: a capture that fails (or a host without ffmpeg and GD) is not tried every minute,
        // and a second run started meanwhile takes the next draft.
        $taken = Upload18Claim::query()
            ->whereKey($claim->getKey())
            ->where('needs_thumbnails', true)
            ->update(['needs_thumbnails' => false, 'updated_at' => now()]);

        if ($taken !== 1) {
            return 0;
        }

        $video = $claim->video_id ? Video::query()->find($claim->video_id) : null;

        if ($video === null || blank($video->embed_code) || GeneratedThumbnails::readyCount($video) > 0) {
            return 0;
        }

        $settings = UploadSettings::fromConfig();

        try {
            $images = app(ServerThumbnails::class)->generate($video, $settings->thumbnailCount, $settings->maxThumbnailBytes());
        } catch (Throwable $e) {
            Log::warning('Upload18 pending upload '.$claim->getKey().': server thumbnails failed: '.class_basename($e));

            return 0;
        }

        // The member may have picked a picture while the server worked: theirs stays.
        if ($images === [] || GeneratedThumbnails::readyCount($video) > 0) {
            return 0;
        }

        return count(GeneratedThumbnails::replace($video, $images));
    }
}
