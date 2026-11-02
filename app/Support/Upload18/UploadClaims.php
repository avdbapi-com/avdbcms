<?php

namespace App\Support\Upload18;

use App\Enums\VideoStatus;
use App\Events\Video\VideoUploaded;
use App\Helpers\Number;
use App\Models\Upload18Claim;
use App\Models\User;
use App\Models\Video;
use App\Services\Upload18Service;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Never lose a video" (1.2.3): the Upload18 uploads of members, kept for good in upload18_claims.
 *
 *  1. As soon as Upload18 has the whole file (its answer carries did and vid), the member's browser tells the site
 *     (announce()): the site keeps a private pending draft for that member and that did ("Processing on Upload18" in
 *     the studio).
 *  2. The browser's normal finish (/complete, with the frames it captured) finishes that same draft (finish()): no
 *     second video. If the page was closed at 92 %, the scheduler (upload18:finish-pending, one short check per minute
 *     for all pending drafts) or the draft's own page does it (settle()): embed code, length, and thumbnails from the
 *     server path because the browser sent none.
 *  3. A did belongs to the first member who claimed it, permanently (security plan S09; before 1.2.3 the link lived 30
 *     minutes in the cache): another member's announce, status check or finish of it is refused. A claim is "verified"
 *     (kept for good) only when Upload18's item positively shows it is that member's file (confirms(): the same size in
 *     bytes, the vid of Upload18's own upload answer, or the same length). A claim nobody could confirm yet does not
 *     lock anyone out: once Upload18 lists the item, a claim whose file does not match it is dropped, and so is one that
 *     nothing confirms while the newcomer's file is confirmed (releaseIfBogus()). While nothing tells the two apart, the
 *     newcomer's announce is not refused: its browser keeps the normal path until a listing decides.
 *  4. Upload18 de-duplicated uploads by file name until 25 October 2026: an item that says duplicate = 1, or whose size
 *     (or length, when both are known) is not the file the browser sent, is never attached: the member gets a clear
 *     message and no wrong draft (mismatch()).
 */
final class UploadClaims
{
    /** A member may have this many drafts that Upload18 has not listed yet; more just take the normal path. */
    public const MAX_UNVERIFIED_PER_MEMBER = 5;

    /** A draft Upload18 never lists, or never finishes, fails after this many hours. */
    public const GIVE_UP_HOURS = 24;

    public const CLAIMED = 'claimed';

    public const DUPLICATE = 'duplicate';

    public const MISMATCH = 'mismatch';

    public const VID_MISMATCH = 'vid_mismatch';

    public const NOT_READY = 'not_ready';

    public const UNAVAILABLE = 'unavailable';

    public const TOO_LONG = 'too_long';

    public const NOT_FOUND = 'not_found';

    public const TIMEOUT = 'timeout';

    public const DELETED = 'deleted';

    private static bool $tableSeen = false;

    public function __construct(private readonly Upload18Service $upload18) {}

    /**
     * The table exists. Between an update's file swap and its Finish it does not yet: the upload then works as in 1.2.2
     * (no pending draft, the cache claim of 30 minutes).
     */
    public static function available(): bool
    {
        if (self::$tableSeen) {
            return true;
        }

        try {
            return self::$tableSeen = Schema::hasTable('upload18_claims');
        } catch (Throwable) {
            return false;
        }
    }

    public static function forDid(string $did): ?Upload18Claim
    {
        if ($did === '' || strlen($did) > 120 || ! self::available()) {
            return null;
        }

        return Upload18Claim::query()->where('did', $did)->first();
    }

    /** This member announced or finished this did (the upload's limits were checked when it was kept). */
    public static function holds(int $userId, string $did): bool
    {
        if ($userId < 1 || $did === '' || strlen($did) > 120 || ! self::available()) {
            return false;
        }

        return Upload18Claim::query()->where('did', $did)->where('user_id', $userId)->exists();
    }

    /** The pending claim of a draft, if it is one. */
    public static function pendingFor(Video $video): ?Upload18Claim
    {
        if (! self::isProcessing($video) || ! self::available()) {
            return null;
        }

        return Upload18Claim::query()
            ->where('did', (string) $video->upload18_vid)
            ->where('user_id', (int) $video->user_id)
            ->where('state', Upload18Claim::PENDING)
            ->first();
    }

    /** A member's draft still waiting for Upload18 ("Processing on Upload18" in the studio and on the draft's page). */
    public static function isProcessing(Video $video): bool
    {
        return ! $video->is_failed && $video->is_uploading && filled($video->upload18_vid) && blank($video->embed_code);
    }

    /**
     * Why $item is not the file the member's browser sent, or null when it is (or nothing can be told): duplicate (Upload18
     * gave back an item it already had), vid_mismatch, or mismatch (another size, or, once Upload18 knows it, another
     * length beyond 2 seconds or 1 %).
     */
    public static function mismatch(Upload18Item $item, ?string $vid, ?int $size, ?float $duration): ?string
    {
        if ($item->duplicate) {
            return self::DUPLICATE;
        }

        if ($vid !== null && $vid !== '' && $item->vid !== '' && ! hash_equals($item->vid, $vid)) {
            return self::VID_MISMATCH;
        }

        if ($size !== null && $size > 0 && $item->sizeBytes !== null && $item->sizeBytes !== $size) {
            return self::MISMATCH;
        }

        if ($duration !== null && $duration > 0 && $item->ready() && $item->durationSeconds !== null) {
            if (abs($duration - $item->durationSeconds) > self::lengthTolerance($duration, $item->durationSeconds)) {
                return self::MISMATCH;
            }
        }

        return null;
    }

    /**
     * Upload18's item positively shows that it is this file (call it after mismatch() found nothing): the same size in
     * bytes (Upload18 lists the stored file's size as soon as it has the file), the vid that the member's browser got in
     * Upload18's own upload answer, or, once Upload18 is ready, the same length (within 2 seconds or 1 %). Only such a
     * claim is "verified", that is kept for good against another member: no evidence is no proof.
     */
    public static function confirms(Upload18Item $item, ?string $vid, ?int $size, ?float $duration): bool
    {
        if ($size !== null && $size > 0 && $item->sizeBytes !== null && $item->sizeBytes === $size) {
            return true;
        }

        if ($vid !== null && $vid !== '' && $item->vid !== '' && hash_equals($item->vid, $vid)) {
            return true;
        }

        return $duration !== null && $duration > 0 && $item->ready() && $item->durationSeconds !== null
            && abs($duration - $item->durationSeconds) <= self::lengthTolerance($duration, $item->durationSeconds);
    }

    /** Container and stream lengths of one file differ a little: 2 seconds, or 1 % of a long film. */
    private static function lengthTolerance(float $a, float $b): float
    {
        return max(2.0, 0.01 * max($a, $b));
    }

    public static function message(string $code): string
    {
        return match ($code) {
            self::CLAIMED => __('This Upload18 video is already claimed by another upload session.'),
            self::DUPLICATE => __('Upload18 returned an older video instead of your file, so it was not added. Please upload it again.'),
            self::MISMATCH => __('The video Upload18 returned does not match your file, so it was not added. Please upload it again.'),
            self::VID_MISMATCH => __('Upload18 returned a different video ID.'),
            self::NOT_READY => __('Upload18 has not finished processing this video yet. Please wait and retry.'),
            self::UNAVAILABLE => __('Upload18 status is temporarily unavailable. Please retry finalizing in a moment.'),
            self::TOO_LONG => __('The video duration exceeds the allowed limit.'),
            default => __('The processing of your video failed.'),
        };
    }

    /**
     * The browser says Upload18 has the whole file: keep a private pending draft for this member and this did.
     *
     * @param  array{did: string, vid: string, filename: string, size: int, duration: ?float, duplicate: bool}  $file
     * @return array{saved: bool, claim: ?Upload18Claim, video: ?Video}
     *
     * @throws ClaimRefused
     */
    public function announce(User $user, array $file): array
    {
        if ($file['duplicate']) {
            throw new ClaimRefused(self::DUPLICATE, 409);
        }

        if (! self::available()) {
            return ['saved' => false, 'claim' => null, 'video' => null];
        }

        $did = $file['did'];
        $existing = self::forDid($did);

        if ($existing !== null && (int) $existing->user_id === (int) $user->getKey()) {
            return $this->kept($existing);
        }

        $item = $this->lookup($did);

        if ($existing !== null && ! $this->releaseIfBogus($existing, $item, $file)) {
            // Another member's for good: Upload18 confirmed their file, or their upload is finished or failed.
            if ($existing->verified_at !== null || $existing->state !== Upload18Claim::PENDING) {
                throw new ClaimRefused(self::CLAIMED, 403);
            }

            // Nothing tells the two apart yet (Upload18 lists nothing, or nothing that confirms either file): no draft
            // now, and no refusal either. This browser keeps the normal path (status checks, then the finish), and the
            // first listing that shows the size decides.
            return ['saved' => false, 'claim' => null, 'video' => null];
        }

        if ($item !== null && ($why = self::mismatch($item, $file['vid'], $file['size'], $file['duration'])) !== null) {
            self::logRefused($why, $item, $file['filename'], $file['size']);

            throw new ClaimRefused($why, 409);
        }

        // A claim nobody can check yet: a few per member at most (the browser's normal path still finishes the rest).
        if ($item === null && $this->unverifiedCount($user) >= self::MAX_UNVERIFIED_PER_MEMBER) {
            return ['saved' => false, 'claim' => null, 'video' => null];
        }

        return $this->createPending($user, $file, $item);
    }

    /**
     * When another member holds $claim but Upload18 has not confirmed it yet, with what Upload18 lists for the did now:
     *  - the item is not the file that member announced: their claim (and its empty draft) goes, the did is free;
     *  - the item confirms their file: the claim is verified, kept for good;
     *  - nothing confirms their claim, but the item confirms the newcomer's file ($newcomer: the vid, size and length the
     *    newcomer's browser sent): a claim made without the upload answer's vid, with a size Upload18 does not list,
     *    cannot hold off the member whose browser has the upload answer: it goes too;
     *  - otherwise (no item, no evidence either way) nothing changes.
     * True when the did was freed.
     *
     * @param  array{vid?: ?string, size?: ?int, duration?: ?float}|null  $newcomer
     */
    public function releaseIfBogus(Upload18Claim $claim, ?Upload18Item $item, ?array $newcomer = null): bool
    {
        if ($item === null || $claim->verified_at !== null || $claim->state !== Upload18Claim::PENDING) {
            return false;
        }

        $why = self::mismatch($item, $claim->vid, $claim->file_size, $claim->file_duration);

        if ($why === null) {
            if ($this->confirm($claim, $item)) {
                return false;
            }

            $vid = isset($newcomer['vid']) ? (string) $newcomer['vid'] : null;
            $size = isset($newcomer['size']) ? (int) $newcomer['size'] : null;
            $duration = isset($newcomer['duration']) ? (float) $newcomer['duration'] : null;

            if ($newcomer === null || self::mismatch($item, $vid, $size, $duration) !== null || ! self::confirms($item, $vid, $size, $duration)) {
                return false;
            }

            $why = 'unconfirmed';
        }

        $released = DB::transaction(function () use ($claim): bool {
            $deleted = Upload18Claim::query()
                ->whereKey($claim->getKey())
                ->where('state', Upload18Claim::PENDING)
                ->whereNull('verified_at')
                ->delete();

            if ($deleted !== 1) {
                return false;
            }

            $video = $claim->video_id ? Video::withTrashed()->find($claim->video_id) : null;

            if ($video !== null && blank($video->embed_code)) {
                $video->thumbnails()->delete();
                $video->forceDelete();
            }

            return true;
        });

        if ($released) {
            // mismatch, vid_mismatch, duplicate: not the file Upload18 lists; unconfirmed: nothing showed it was that
            // member's file while another member's file was confirmed.
            Log::warning('Upload18: a pending upload claim that Upload18 never confirmed was dropped.', ['claim' => $claim->getKey(), 'reason' => $why]);
        }

        return $released;
    }

    /**
     * Finish a pending draft with Upload18's ready item: embed code, length, "uploaded". Once, whoever comes first (the
     * browser's finish, the scheduler, the draft's page); the others get the finished video. $images are the browser's
     * frames (already JpegImage-clean); without them the scheduler makes server thumbnails later.
     *
     * @param  list<string>  $images
     * @return array{video: ?Video, finished: bool}
     */
    public function finish(Upload18Claim $claim, Upload18Item $item, array $images = []): array
    {
        $video = null;
        $finished = false;

        DB::transaction(function () use ($claim, $item, $images, &$video, &$finished): void {
            $fresh = Upload18Claim::query()->whereKey($claim->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return;
            }

            $video = $fresh->video_id ? Video::withTrashed()->find($fresh->video_id) : null;

            if ($fresh->state !== Upload18Claim::PENDING) {
                return;
            }

            // The member (or an administrator) deleted the draft while Upload18 worked: that stays so.
            if ($video === null || $video->trashed()) {
                $fresh->forceFill(['state' => Upload18Claim::FAILED, 'error' => self::DELETED, 'next_check_at' => null, 'last_checked_at' => now()])->save();
                $video = null;

                return;
            }

            $video->forceFill([
                'upload18_vid' => $fresh->did,
                'embed_code' => $item->vid,
                'duration' => $item->durationForVideo() ?? $video->getRawOriginal('duration'),
                'uploaded_at' => now(),
            ])->save();

            $fresh->forceFill([
                'state' => Upload18Claim::DONE,
                'vid' => $item->vid,
                'verified_at' => $fresh->verified_at ?? now(),
                'finished_at' => now(),
                'last_checked_at' => now(),
                'next_check_at' => null,
                'error' => null,
                'needs_thumbnails' => $images === [],
            ])->save();

            $finished = true;
        });

        if ($finished && $video !== null) {
            if ($images !== []) {
                GeneratedThumbnails::replace($video, $images, true);
            }

            VideoUploaded::dispatch($video->fresh() ?? $video);
        }

        return ['video' => $video, 'finished' => $finished];
    }

    /**
     * One check of a pending draft with what Upload18 lists for it ($item null: not listed). Used by the scheduler and
     * by the draft's page. Returns finished, waiting, failed or skipped (someone else finished it meanwhile); $reason
     * gets the error code when it failed (the draft's page shows UploadClaims::message() of it).
     */
    public function settle(Upload18Claim $claim, ?Upload18Item $item, ?string &$reason = null): string
    {
        $reason = null;

        if ($claim->state !== Upload18Claim::PENDING) {
            return 'skipped';
        }

        $video = $claim->video_id ? Video::withTrashed()->find($claim->video_id) : null;

        if ($video === null || $video->trashed()) {
            $this->fail($claim, $reason = self::DELETED);

            return 'failed';
        }

        $age = $this->ageSeconds($claim);
        $tooOld = $age >= self::GIVE_UP_HOURS * 3600;

        if ($item === null) {
            if ($tooOld) {
                $this->fail($claim, $reason = self::NOT_FOUND);

                return 'failed';
            }

            $this->later($claim, self::NOT_FOUND);

            return 'waiting';
        }

        if (($why = self::mismatch($item, $claim->vid, $claim->file_size, $claim->file_duration)) !== null) {
            // Never attach it. A claim Upload18 had never confirmed was not this member's upload at all: the did is free.
            $this->fail($claim, $reason = $why, $claim->verified_at === null);

            return 'failed';
        }

        // Verified only on evidence (the size, the upload answer's vid, the length). With none (an answer without a
        // size), the draft still finishes: it is the first claim of this did and nobody brought a confirmed file.
        $this->confirm($claim, $item);

        if (! $item->ready()) {
            if ($tooOld) {
                $this->fail($claim, $reason = self::TIMEOUT);

                return 'failed';
            }

            $this->later($claim, null);

            return 'waiting';
        }

        $seconds = $item->durationForVideo();
        $maxDuration = max(1, (int) (config('site.upload_max_duration') ?? 3600));

        if ($seconds !== null && $seconds > $maxDuration) {
            $this->fail($claim, $reason = self::TOO_LONG);

            return 'failed';
        }

        return $this->finish($claim, $item)['finished'] ? 'finished' : 'skipped';
    }

    /**
     * Mark $claim verified (kept for good) when $item confirms its file, and keep the item's vid when the browser had
     * none (the scheduler then finds the upload by it once it leaves the newest listed videos). True when verified.
     */
    public function confirm(Upload18Claim $claim, Upload18Item $item): bool
    {
        if ($claim->verified_at !== null) {
            return true;
        }

        if (! self::confirms($item, $claim->vid, $claim->file_size, $claim->file_duration)) {
            return false;
        }

        $claim->forceFill([
            'verified_at' => now(),
            'vid' => filled($claim->vid) ? $claim->vid : ($item->vid !== '' ? $item->vid : null),
        ])->save();

        return true;
    }

    /** Upload18 did not answer: check again in two minutes (the give-up clock only counts Upload18's own answers). */
    public function waitForUpload18(Upload18Claim $claim): void
    {
        Upload18Claim::query()->whereKey($claim->getKey())->where('state', Upload18Claim::PENDING)->update([
            'error' => self::UNAVAILABLE,
            'last_checked_at' => now(),
            'next_check_at' => now()->addMinutes(2),
            'updated_at' => now(),
        ]);
    }

    /**
     * A pending draft that will never be finished: the video gets "Failed" (it never had an embed code). The did is
     * freed as well ($release, or whenever Upload18 never confirmed the claim): only a claim Upload18 confirmed keeps its
     * did after it failed. A guess for a did Upload18 has not reached yet would otherwise hold it for good, and the real
     * upload that later gets it would be refused as "already claimed".
     */
    public function fail(Upload18Claim $claim, string $code, bool $release = false): void
    {
        $changed = DB::transaction(function () use ($claim, $code, &$release): bool {
            $fresh = Upload18Claim::query()->whereKey($claim->getKey())->lockForUpdate()->first();

            if ($fresh === null || $fresh->state !== Upload18Claim::PENDING) {
                return false;
            }

            $release = $release || $fresh->verified_at === null;
            $video = $fresh->video_id ? Video::withTrashed()->find($fresh->video_id) : null;

            if ($video !== null && blank($video->embed_code)) {
                $video->forceFill(['status' => VideoStatus::FAILED] + ($release ? ['upload18_vid' => null] : []))->save();
            }

            if ($release) {
                $fresh->delete();

                return true;
            }

            $fresh->forceFill([
                'state' => Upload18Claim::FAILED,
                'error' => $code,
                'next_check_at' => null,
                'last_checked_at' => now(),
            ])->save();

            return true;
        });

        if ($changed) {
            Log::warning('Upload18: a pending upload was not finished.', [
                'claim' => $claim->getKey(),
                'reason' => $code,
                'released' => $release,
                'ext' => strtolower((string) pathinfo((string) $claim->file_name, PATHINFO_EXTENSION)),
                'size' => $claim->file_size,
            ]);
        }
    }

    /**
     * A member's upload refused because Upload18's item is not the file the browser sent. Logged with the file type and
     * both sizes (never the name), so an owner can see at once if Upload18 ever lists another size for some file type.
     */
    public static function logRefused(string $why, Upload18Item $item, ?string $fileName, ?int $size): void
    {
        Log::warning('Upload18: an upload was not attached: Upload18\'s video is not the file the member sent.', [
            'reason' => $why,
            'ext' => strtolower((string) pathinfo((string) $fileName, PATHINFO_EXTENSION)),
            'size' => $size,
            'listed_size' => $item->sizeBytes,
            'duplicate' => $item->duplicate,
            'zt' => $item->zt,
        ]);
    }

    /**
     * /complete without a kept draft (a page opened before 1.2.3, or its announce did not get through): the video is
     * created finished, and the did becomes this member's for good. Two finishes of one upload at the same moment make
     * one video. The checks of the request (status probe, limits, readiness, the file match) are the caller's.
     *
     * @param  list<string>  $images
     *
     * @throws ClaimRefused when another member claimed the did meanwhile
     */
    public function createFinished(User $user, string $did, Upload18Item $item, string $fileName, int $size, ?float $fileDuration, ?int $duration, array $images): Video
    {
        try {
            $video = DB::transaction(function () use ($user, $did, $item, $fileName, $size, $fileDuration, $duration): Video {
                $video = self::newVideo((int) $user->getKey(), $did, $fileName, $size);
                $video->forceFill(['embed_code' => $item->vid, 'duration' => $duration, 'uploaded_at' => now()])->save();

                if (self::available()) {
                    $inserted = Upload18Claim::query()->insertOrIgnore([
                        'did' => $did,
                        'user_id' => (int) $user->getKey(),
                        'video_id' => $video->id,
                        'vid' => $item->vid,
                        'state' => Upload18Claim::DONE,
                        'file_name' => mb_substr($fileName, 0, 255),
                        'file_size' => $size,
                        'file_duration' => $fileDuration,
                        'verified_at' => now(),
                        'finished_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($inserted === 0) {
                        $claim = Upload18Claim::query()->where('did', $did)->first();

                        if ($claim !== null && (int) $claim->user_id !== (int) $user->getKey()) {
                            throw new ClaimRefused(self::CLAIMED, 403);
                        }

                        if ($claim !== null && $claim->video_id && Video::query()->whereKey($claim->video_id)->exists()) {
                            throw new ExistingUpload((int) $claim->video_id);
                        }

                        $claim?->forceFill(['video_id' => $video->id, 'vid' => $item->vid, 'state' => Upload18Claim::DONE, 'finished_at' => now()])->save();
                    }
                }

                return $video;
            });
        } catch (ExistingUpload $existing) {
            return Video::query()->findOrFail($existing->videoId);
        }

        if ($images !== []) {
            GeneratedThumbnails::replace($video, $images, true);
        } else {
            GeneratedThumbnails::addPending($video);
        }

        VideoUploaded::dispatch($video);

        return $video;
    }

    /** A video of this member's (made before 1.2.3 or between an update's file swap and its Finish) becomes their claim. */
    public static function recordExisting(Video $video, string $did): void
    {
        if ($did === '' || strlen($did) > 120 || ! self::available()) {
            return;
        }

        try {
            Upload18Claim::query()->insertOrIgnore([
                'did' => $did,
                'user_id' => (int) $video->user_id,
                'video_id' => $video->id,
                'vid' => filled($video->embed_code) && strlen((string) $video->embed_code) <= 160 ? (string) $video->embed_code : null,
                'state' => Upload18Claim::DONE,
                'file_name' => $video->original_file_name !== null ? mb_substr((string) $video->original_file_name, 0, 255) : null,
                'file_size' => is_numeric($video->size) ? (int) $video->size : null,
                'verified_at' => now(),
                'finished_at' => $video->uploaded_at ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Upload18: could not record an upload claim: '.class_basename($e));
        }
    }

    /** What Upload18 lists for a did now, or null (not among the newest, or no answer). */
    public function lookup(string $did): ?Upload18Item
    {
        try {
            return $this->upload18->findItems([$did], 50, 3)[$did] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array{did: string, vid: string, filename: string, size: int, duration: ?float, duplicate: bool}  $file
     * @return array{saved: bool, claim: ?Upload18Claim, video: ?Video}
     */
    private function createPending(User $user, array $file, ?Upload18Item $item): array
    {
        // Kept for good at once only when Upload18's item confirms the file; the vid stays the upload answer's (or the
        // confirmed item's), so a later check can tell the member's own evidence from a guess.
        $confirmed = $item !== null && self::confirms($item, $file['vid'], $file['size'], $file['duration']);

        try {
            [$claim, $video] = DB::transaction(function () use ($user, $file, $item, $confirmed): array {
                $video = self::newVideo((int) $user->getKey(), $file['did'], $file['filename'], $file['size']);
                GeneratedThumbnails::addPending($video);

                $claim = Upload18Claim::query()->create([
                    'did' => $file['did'],
                    'user_id' => (int) $user->getKey(),
                    'video_id' => $video->id,
                    'vid' => $file['vid'] !== '' ? $file['vid'] : ($confirmed && $item->vid !== '' ? $item->vid : null),
                    'state' => Upload18Claim::PENDING,
                    'file_name' => mb_substr($file['filename'], 0, 255),
                    'file_size' => $file['size'],
                    'file_duration' => $file['duration'],
                    'verified_at' => $confirmed ? now() : null,
                    // Ready at once: the next scheduler minute finishes it if the page is gone by then.
                    'next_check_at' => $item !== null && $item->ready() ? now() : now()->addSeconds(30),
                ]);

                return [$claim, $video];
            });
        } catch (UniqueConstraintViolationException) {
            // Another request kept this did a moment ago.
            $existing = self::forDid($file['did']);

            if ($existing !== null && (int) $existing->user_id === (int) $user->getKey()) {
                return $this->kept($existing);
            }

            throw new ClaimRefused(self::CLAIMED, 403);
        }

        return ['saved' => true, 'claim' => $claim, 'video' => $video];
    }

    /** @return array{saved: bool, claim: ?Upload18Claim, video: ?Video} */
    private function kept(Upload18Claim $claim): array
    {
        $video = $claim->video_id ? Video::query()->find($claim->video_id) : null;

        return ['saved' => $video !== null && $claim->state !== Upload18Claim::FAILED, 'claim' => $claim, 'video' => $video];
    }

    /** A new private draft of a member's upload (no embed code, not "uploaded" yet). */
    private static function newVideo(int $userId, string $did, string $fileName, int $size): Video
    {
        $title = pathinfo($fileName, PATHINFO_FILENAME);

        return Video::query()->create([
            'uuid' => Number::unique(),
            'title' => $title,
            'slug' => Str::slug($title),
            'original_file_name' => $fileName,
            'size' => $size,
            'status' => VideoStatus::DRAFT,
            'user_id' => $userId,
            'upload18_vid' => $did,
        ]);
    }

    private function unverifiedCount(User $user): int
    {
        return Upload18Claim::query()
            ->where('user_id', (int) $user->getKey())
            ->where('state', Upload18Claim::PENDING)
            ->whereNull('verified_at')
            ->count();
    }

    /** Not listed or not ready yet: check again soon after the upload, then less often (one Upload18 call a minute at most). */
    private function later(Upload18Claim $claim, ?string $error): void
    {
        $age = $this->ageSeconds($claim);
        $wait = match (true) {
            $age < 900 => 60,
            $age < 7200 => 300,
            default => 1800,
        };

        Upload18Claim::query()->whereKey($claim->getKey())->where('state', Upload18Claim::PENDING)->update([
            'attempts' => (int) $claim->attempts + 1,
            'error' => $error,
            'last_checked_at' => now(),
            'next_check_at' => now()->addSeconds($wait),
            'updated_at' => now(),
        ]);
    }

    private function ageSeconds(Upload18Claim $claim): int
    {
        return $claim->created_at !== null ? max(0, now()->getTimestamp() - $claim->created_at->getTimestamp()) : 0;
    }
}
