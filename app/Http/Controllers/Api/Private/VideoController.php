<?php

namespace App\Http\Controllers\Api\Private;

use App\Data\UploadSettings;
use App\Enums\ThumbnailStatus;
use App\Enums\VideoStatus;
use App\Helpers\Number;
use App\Http\Requests\Video\CompleteUploadRequest;
use App\Http\Requests\Video\FileRequest;
use App\Http\Requests\Video\PendingUploadRequest;
use App\Jobs\UploadToUpload18;
use App\Models\Setting;
use App\Models\Upload18Claim;
use App\Models\Video;
use App\Services\Upload18Service;
use App\Support\Upload18\BrowserUploadKey;
use App\Support\Upload18\ClaimRefused;
use App\Support\Upload18\GeneratedThumbnails;
use App\Support\Upload18\ServerThumbnails;
use App\Support\Upload18\Upload18Item;
use App\Support\Upload18\Upload18Unavailable;
use App\Support\Upload18\UploadClaims;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VideoController
{
    /**
     * Return Upload18 API config for direct browser upload. The browser gets a short-lived upload token in "apikey",
     * never the site's key (BrowserUploadKey).
     */
    public function getUploadConfig(): JsonResponse
    {
        if (!config('site.allow_uploads', true)) {
            return response()->json(['error' => __('Uploads are currently disabled.')], 403);
        }

        // Read from DB settings (admin panel) first, fall back to .env config
        $uploads = $this->uploadSettings();

        $apiKey = $uploads['upload18_api_key'] ?? config('services.upload18.api_key', '');
        $apiUrl = $uploads['upload18_api_url'] ?? config('services.upload18.api_url', 'https://upload18.org/api');
        $cid = $uploads['upload18_default_cid'] ?? config('services.upload18.default_cid', '15');
        $mycid = $uploads['upload18_default_mycid'] ?? config('services.upload18.default_mycid', '0');
        $fid = $uploads['upload18_default_fid'] ?? config('services.upload18.default_fid', '18');
        $uploadSettings = UploadSettings::fromSettingsArray($uploads, UploadSettings::fromConfig());
        $directAllowed = $this->directUploadAllowed();
        $allowServerFallback = (bool) config('services.upload18.allow_server_fallback', false) || ! $directAllowed;

        if (empty($apiKey)) {
            return response()->json(['error' => __('Upload18 API key not configured.')], 500);
        }

        $payload = [
            'upload_mode' => $directAllowed ? 'direct' : 'server',
            'upload_url' => $directAllowed ? rtrim($apiUrl, '/') . '/upload' : null,
            'server_chunk_url' => $allowServerFallback ? route('videos.upload18.chunk', [], false) : null,
            'allow_server_fallback' => $allowServerFallback,
            'direct_only' => $directAllowed && ! $allowServerFallback,
            'status_url' => route('videos.upload18.status', [], false),
            // "Never lose a video": where the browser says Upload18 has the whole file (a private pending draft).
            'pending_url' => route('videos.upload18.pending', [], false),
            'cid' => $cid,
            'mycid' => $mycid,
            'fid' => $fid,
            'chunk_size' => $uploadSettings->chunkSizeMb * 1000 * 1000,
            'server_chunk_size' => min(5 * 1000 * 1000, $uploadSettings->chunkSizeMb * 1000 * 1000),
            'chunk_size_mb' => $uploadSettings->chunkSizeMb,
            'thumbnail_count' => $uploadSettings->thumbnailCount,
        ];

        if ($directAllowed) {
            $credential = app(BrowserUploadKey::class)->issue((string) $payload['upload_url'], [
                'cid' => $cid,
                'mycid' => $mycid,
                'fid' => $fid,
            ]);

            if ($credential === null) {
                // Neither an upload token nor a key that is safe in a browser (a master key, or one that can edit or
                // delete, never reaches a browser): the upload panel shows this.
                return response()->json(['error' => __('Upload18 could not start the upload. Try again in a minute.')], 503);
            }

            $payload['apikey'] = $credential['apikey'];
            $payload['upload_url'] = $credential['upload_url'];
        }

        return response()->json($payload);
    }

    /**
     * Fallback path for large uploads: receive browser chunks locally, then
     * let the queue merge the file, upload it to Upload18, and generate thumbnails.
     */
    public function uploadChunk(FileRequest $request): JsonResponse
    {
        if (!config('services.upload18.allow_server_fallback', false) && $this->directUploadAllowed()) {
            return response()->json([
                'message' => __('Server-side video upload is disabled. Please upload directly to Upload18.'),
            ], 410);
        }

        $chunkNumber = $request->integer('resumableChunkNumber');
        $totalChunks = $request->integer('resumableTotalChunks');
        $identifier = $request->string('resumableIdentifier')->value();
        $filename = $request->string('resumableFilename')->value();
        $totalSize = $request->integer('resumableTotalSize');
        $userId = (int) Auth::id();
        $folder = $userId.'/'.hash('sha256', $identifier);
        $chunkPath = Video::CHUNK_FOLDER.'/'.$folder.'/'.str_pad((string) $chunkNumber, 6, '0', STR_PAD_LEFT).'.part';

        $stream = fopen($request->file('file')->getRealPath(), 'rb');

        try {
            Storage::disk('local')->put($chunkPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($chunkNumber < $totalChunks) {
            return response()->json([
                'complete' => false,
                'chunk' => $chunkNumber,
                'total' => $totalChunks,
            ]);
        }

        $completionKey = $this->chunkCompletionKey($userId, $identifier);
        $lock = Cache::lock('upload18:chunk-complete:'.$userId.':'.hash('sha256', $identifier), 300);

        if (! $lock->get()) {
            return response()->json([
                'message' => __('This upload is already being finalized. Please retry shortly.'),
            ], 409);
        }

        try {
            $completed = $this->completedChunkUpload($completionKey, $userId);
            if ($completed) {
                return response()->json([
                    'complete' => true,
                    'route' => route('user.videos.create', $completed),
                    'video_id' => $completed->id,
                    'idempotent' => true,
                ]);
            }

            $missingChunks = collect(range(1, $totalChunks))
                ->reject(fn (int $part): bool => Storage::disk('local')->exists(
                    Video::CHUNK_FOLDER.'/'.$folder.'/'.str_pad((string) $part, 6, '0', STR_PAD_LEFT).'.part'
                ));

            if ($missingChunks->isNotEmpty()) {
                return response()->json([
                    'message' => __('Some upload chunks are missing. Please retry the missing chunks.'),
                    'missing_chunks' => $missingChunks->values()->all(),
                ], 409);
            }

            $actualSize = collect(range(1, $totalChunks))
                ->sum(fn (int $part): int => (int) Storage::disk('local')->size(
                    Video::CHUNK_FOLDER.'/'.$folder.'/'.str_pad((string) $part, 6, '0', STR_PAD_LEFT).'.part'
                ));

            if ($actualSize !== $totalSize) {
                return response()->json([
                    'message' => __('The uploaded chunk sizes do not match the declared file size.'),
                ], 422);
            }

            $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'mp4';
            $title = Str::replaceLast('.'.$extension, '', $filename);

            $video = Video::create([
                'uuid' => Number::unique(),
                'title' => $title,
                'slug' => Str::slug($title),
                'original_file_name' => $filename,
                'size' => $totalSize,
                'status' => VideoStatus::DRAFT,
                'user_id' => $userId,
            ]);

            for ($i = 0; $i < Video::generatedThumbnailCount(); $i++) {
                $video->thumbnails()->create([
                    'status' => ThumbnailStatus::PENDING,
                    'is_active' => $i === 0,
                ]);
            }

            try {
                $markerWritten = Storage::disk('local')->put($completionKey, json_encode([
                    'video_id' => $video->id,
                    'completed_at' => now()->toIso8601String(),
                ], JSON_THROW_ON_ERROR));

                if (! $markerWritten) {
                    throw new \RuntimeException('Unable to persist upload completion marker.');
                }

                UploadToUpload18::dispatch($folder, $video, $extension);
            } catch (\Throwable $exception) {
                Storage::disk('local')->delete($completionKey);
                $video->thumbnails()->delete();
                $video->delete();
                throw $exception;
            }

            return response()->json([
                'complete' => true,
                'route' => route('user.videos.create', $video),
                'video_id' => $video->id,
            ]);
        } finally {
            $lock->release();
        }
    }

    public function getUploadStatus(Request $request): JsonResponse
    {
        if (! $this->directUploadAllowed()) {
            return response()->json([
                'message' => __('Direct Upload18 uploads are restricted. Please log in to upload.'),
            ], 403);
        }

        $request->validate([
            'did' => 'required|string|max:120',
            'vid' => 'nullable|string|max:120',
        ]);

        $uploads = $this->uploadSettings();
        if (empty($uploads['upload18_api_key'] ?? config('services.upload18.api_key', ''))) {
            return response()->json(['error' => __('Upload18 API key not configured.')], 500);
        }

        $did = (string) $request->input('did');

        try {
            $listed = $this->upload18()->findVideoByDid($did, 50, 3);
        } catch (\Throwable) {
            return response()->json(['error' => __('Video processing status is not available.')], 502);
        }

        $status = Upload18Service::statusFromVideo($listed);

        $expectedVid = (string) $request->input('vid', '');
        if ($expectedVid !== '' && $status['vid'] !== '' && $status['vid'] !== $expectedVid) {
            return response()->json(['error' => __('Upload18 returned a different video ID.')], 409);
        }

        // A did belongs to the first member who claimed it, for good (security plan S09): not only for the 30 minutes
        // of the cache claim below. The vid of this browser's upload answer counts as its evidence.
        $item = $listed !== null ? Upload18Item::fromArray($listed) : null;

        if ($this->ownedByAnother($did, $item, ['vid' => $expectedVid])) {
            return response()->json([
                'message' => __('This Upload18 video is already claimed by another upload session.'),
            ], 403);
        }

        // The vid plays the video: only for the member who holds this did, or for a request that brings it already
        // (R1B-027, owner-approved 2026-10-27). Anybody else learns no vid and no length, and claims nothing.
        if (! $this->mayKnowVid($did, $item, $status, $expectedVid)) {
            return response()->json(array_merge($status, [
                'ready' => false,
                'vid' => '',
                'duration' => null,
                'duration_seconds' => null,
            ]));
        }

        if ($status['found']) {
            $claimKey = $this->upload18ClaimKey($did);
            $claimedBy = Cache::get($claimKey);
            if ($claimedBy !== null && (int) $claimedBy !== (int) Auth::id()) {
                return response()->json([
                    'message' => __('This Upload18 video is already claimed by another upload session.'),
                ], 403);
            }

            Cache::add($claimKey, (int) Auth::id(), now()->addMinutes(30));
            Cache::put(
                $this->upload18PendingKey((int) Auth::id(), $did),
                ['vid' => (string) ($status['vid'] ?? '')],
                now()->addMinutes(30)
            );
        }

        return response()->json($status);
    }

    /**
     * "Never lose a video": the browser says Upload18 has the whole file (the single-POST answer or the last piece's
     * answer carried did and vid). The site keeps a private pending draft for this member and this did, which it
     * finishes by itself if the page is closed (App\Support\Upload18\UploadClaims). 201 with the draft, 200 when it was
     * kept already, 202 when it could not be kept now (the normal finish still makes the video), 403/409 when this
     * upload is refused (another member's video, a video Upload18 handed back instead of the file, another file).
     */
    public function announceUpload(PendingUploadRequest $request): JsonResponse
    {
        if (! $this->directUploadAllowed()) {
            return response()->json([
                'message' => __('Direct Upload18 uploads are restricted. Please log in to upload.'),
            ], 403);
        }

        $data = $request->validated();

        try {
            $kept = app(UploadClaims::class)->announce(Auth::user(), [
                'did' => (string) $data['did'],
                'vid' => (string) ($data['vid'] ?? ''),
                'filename' => (string) $data['filename'],
                'size' => (int) $data['size'],
                'duration' => isset($data['file_duration']) && (float) $data['file_duration'] > 0 ? (float) $data['file_duration'] : null,
                'duplicate' => filter_var($data['duplicate'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]);
        } catch (ClaimRefused $refused) {
            return response()->json($refused->payload(), $refused->status);
        }

        if (! $kept['saved'] || $kept['video'] === null || $kept['claim'] === null) {
            return response()->json(['saved' => false], 202);
        }

        /** @var Upload18Claim $claim */
        $claim = $kept['claim'];

        return response()->json([
            'saved' => true,
            'video_id' => $kept['video']->id,
            'state' => $claim->state,
            'route' => route('user.videos.create', $kept['video']),
        ], $claim->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Called by the browser after upload + polling completes.
     * The browser sends did (Upload18 ID) and vid (embed hash).
     * It finishes the pending draft the browser announced (no second video), or, for a page without one, creates the
     * video as before. Safe to repeat ("Retry finishing").
     */
    public function completeUpload(CompleteUploadRequest $request): JsonResponse
    {
        if (! $this->directUploadAllowed()) {
            return response()->json([
                'message' => __('Direct Upload18 uploads are restricted. Please log in to upload.'),
            ], 403);
        }

        $data = $request->validated();
        $did = (string) $data['did'];
        // "Retry finishing" after the 10-minute wait may carry no vid (the upload answer had none and no status check
        // said "ready"): the member's own kept draft is finished with the vid Upload18 lists; any other finish needs it.
        $vid = (string) ($data['vid'] ?? '');
        $originalName = $data['filename'];
        $size = (int) $data['size'];
        $fileDuration = isset($data['file_duration']) && (float) $data['file_duration'] > 0 ? (float) $data['file_duration'] : null;
        $user = Auth::user();
        $claims = app(UploadClaims::class);

        $claim = UploadClaims::forDid($did);

        if ($claim !== null && (int) $claim->user_id !== (int) $user->id
            && ! $claims->releaseIfBogus($claim, $claim->verified_at === null ? $claims->lookup($did) : null, ['vid' => $vid, 'size' => $size, 'duration' => $fileDuration])) {
            if ($claim->verified_at !== null || $claim->state !== Upload18Claim::PENDING) {
                return $this->refused(new ClaimRefused(UploadClaims::CLAIMED, 403));
            }

            // Another member's claim that nothing confirms yet, nor this file: decided by the next listing with a size.
            return response()->json([
                'message' => UploadClaims::message(UploadClaims::NOT_READY),
                'code' => UploadClaims::NOT_READY,
            ], 409);
        }

        if ($claim !== null && (int) $claim->user_id === (int) $user->id) {
            return $this->completeClaim($claim->refresh(), $data, $vid, $size, $fileDuration);
        }

        $existing = Video::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($did, $vid) {
                $query->where('upload18_vid', $did);

                if ($vid !== '') {
                    $query->orWhere('embed_code', $vid);
                }
            })
            ->first();

        if ($existing) {
            Cache::forget($this->upload18PendingKey((int) $user->id, $did));

            // Only a video that IS this upload (the same did) becomes the member's claim and gets the frames. A video
            // matched by its embed code alone says nothing about the did of this request: it is answered as before
            // 1.2.3 and nothing is recorded, so a member can never take another upload's did this way (S09).
            if ((string) $existing->upload18_vid === $did) {
                UploadClaims::recordExisting($existing, $did);
                $this->attachFramesIfNone($existing, $data['thumbnails'] ?? []);
            }

            return response()->json([
                'route' => route('user.videos.create', $existing),
            ]);
        }

        $pending = Cache::get($this->upload18PendingKey((int) $user->id, $did));
        $claimedBy = Cache::get($this->upload18ClaimKey($did));
        if ($vid === ''
            || ($claimedBy !== null && (int) $claimedBy !== (int) $user->id)
            || ! is_array($pending)
            || ($pending['vid'] ?? '') !== $vid) {
            return response()->json([
                'message' => __('Upload18 status must be checked from this account before finalizing the upload.'),
                'code' => 'status_required',
            ], 409);
        }

        if ($this->uploadWouldExceedStorageLimit($size)) {
            return response()->json([
                'message' => __('Sorry ! Your available video storage is not enough for this file. Upgrade to Premium plan to increase your storage space.'),
                'code' => 'storage',
            ], 403);
        }

        try {
            $listed = $this->upload18()->findVideoByDid($did, 50, 3);
        } catch (\Throwable) {
            return $this->refused(new ClaimRefused(UploadClaims::UNAVAILABLE, 502));
        }

        $remoteStatus = Upload18Service::statusFromVideo($listed);
        $item = $listed !== null ? Upload18Item::fromArray($listed) : null;

        if ($item === null || !$remoteStatus['found'] || !$remoteStatus['ready'] || $remoteStatus['vid'] !== $vid) {
            return response()->json([
                'message' => __('Upload18 has not finished processing this video yet. Please wait and retry.'),
                'code' => UploadClaims::NOT_READY,
                'status' => $remoteStatus,
            ], 409);
        }

        // Never attach an item Upload18 handed back instead of this file, or one of another size or length.
        if (($why = UploadClaims::mismatch($item, $vid, $size, $fileDuration)) !== null) {
            UploadClaims::logRefused($why, $item, $originalName, $size);

            return $this->refused(new ClaimRefused($why, 409));
        }

        $duration = $remoteStatus['duration_seconds']
            ?: (($data['duration_seconds'] ?? null) ?: Upload18Service::durationToSeconds($data['duration'] ?? null));

        $maxDuration = max(1, (int) (config('site.upload_max_duration') ?? 3600));
        if ($duration && $duration > $maxDuration) {
            return response()->json([
                'message' => __('The video duration exceeds the allowed limit.'),
                'code' => UploadClaims::TOO_LONG,
            ], 422);
        }

        try {
            $video = $claims->createFinished(
                $user,
                $did,
                $item,
                $originalName,
                $size,
                $fileDuration,
                $duration ?: null,
                GeneratedThumbnails::fromPayload($data['thumbnails'] ?? [])
            );
        } catch (ClaimRefused $refused) {
            return $this->refused($refused);
        }

        Cache::forget($this->upload18PendingKey((int) $user->id, $did));

        return response()->json([
            'route' => route('user.videos.create', $video),
        ]);
    }

    /**
     * The finish of an upload this member announced: the pending draft gets its embed code now (once; the scheduler may
     * have done it already), with the frames the browser captured.
     */
    private function completeClaim(Upload18Claim $claim, array $data, string $vid, int $size, ?float $fileDuration): JsonResponse
    {
        $user = Auth::user();
        $did = $claim->did;
        $video = $claim->video_id ? Video::query()->find($claim->video_id) : null;

        if ($claim->state === Upload18Claim::DONE && $video !== null) {
            Cache::forget($this->upload18PendingKey((int) $user->id, $did));
            $this->attachFramesIfNone($video, $data['thumbnails'] ?? []);

            return response()->json(['route' => route('user.videos.create', $video)]);
        }

        if ($claim->state !== Upload18Claim::PENDING) {
            $code = (string) ($claim->error ?: 'failed');

            return response()->json([
                'message' => UploadClaims::message($code),
                'code' => in_array($code, [UploadClaims::DUPLICATE, UploadClaims::MISMATCH, UploadClaims::VID_MISMATCH, UploadClaims::TOO_LONG], true) ? $code : 'failed',
            ], 409);
        }

        try {
            $listed = $this->upload18()->findVideoByDid($did, 50, 3);
            $item = $listed !== null ? Upload18Item::fromArray($listed) : null;

            // No longer among the newest 150 videos (a busy shared account): ask Upload18 for this one video by the vid
            // of its upload answer, as the scheduler does. The answer counts only when it names this did.
            $lookupVid = filled($claim->vid) ? (string) $claim->vid : $vid;

            if ($item === null && $lookupVid !== '') {
                $item = $this->upload18()->itemByVid($lookupVid, $did);
            }
        } catch (\Throwable) {
            return $this->refused(new ClaimRefused(UploadClaims::UNAVAILABLE, 502));
        }

        $remoteStatus = $item?->status() ?? Upload18Service::statusFromVideo(null);

        if ($item === null) {
            return response()->json([
                'message' => __('Upload18 has not finished processing this video yet. Please wait and retry.'),
                'code' => UploadClaims::NOT_READY,
                'status' => $remoteStatus,
            ], 409);
        }

        $declaredVid = filled($claim->vid) ? (string) $claim->vid : $vid;
        $claimSize = $claim->file_size ?: $size;
        $claimDuration = $fileDuration ?? $claim->file_duration;
        $why = UploadClaims::mismatch($item, $declaredVid, $claimSize, $claimDuration);

        if ($why === null && $item->vid !== '' && $vid !== '' && ! hash_equals($item->vid, $vid)) {
            $why = UploadClaims::VID_MISMATCH;
        }

        if ($why !== null) {
            UploadClaims::logRefused($why, $item, (string) ($claim->file_name ?? ''), $claimSize);
            app(UploadClaims::class)->fail($claim, $why, $claim->verified_at === null);

            return $this->refused(new ClaimRefused($why, 409));
        }

        // Upload18 confirms the member's file (size, upload answer's vid, length): kept for good from now on.
        app(UploadClaims::class)->confirm($claim, $item);

        if (! $item->ready()) {
            return response()->json([
                'message' => __('Upload18 has not finished processing this video yet. Please wait and retry.'),
                'code' => UploadClaims::NOT_READY,
                'status' => $remoteStatus,
            ], 409);
        }

        $duration = $item->durationForVideo();
        $maxDuration = max(1, (int) (config('site.upload_max_duration') ?? 3600));

        if ($duration !== null && $duration > $maxDuration) {
            app(UploadClaims::class)->fail($claim, UploadClaims::TOO_LONG);

            return response()->json([
                'message' => __('The video duration exceeds the allowed limit.'),
                'code' => UploadClaims::TOO_LONG,
            ], 422);
        }

        $result = app(UploadClaims::class)->finish($claim, $item, GeneratedThumbnails::fromPayload($data['thumbnails'] ?? []));
        $video = $result['video'];

        if ($video === null) {
            return response()->json([
                'message' => UploadClaims::message('failed'),
                'code' => 'failed',
            ], 409);
        }

        if (! $result['finished']) {
            // The scheduler or the draft's page finished it a moment ago: the browser's frames still count.
            $this->attachFramesIfNone($video, $data['thumbnails'] ?? []);
        }

        Cache::forget($this->upload18PendingKey((int) $user->id, $did));

        return response()->json(['route' => route('user.videos.create', $video)]);
    }

    /** The browser's frames on a video that has no ready thumbnail yet (a repeated finish never replaces chosen ones). */
    private function attachFramesIfNone(Video $video, mixed $rawThumbnails): void
    {
        $images = GeneratedThumbnails::fromPayload($rawThumbnails);

        if ($images !== [] && GeneratedThumbnails::readyCount($video) === 0) {
            GeneratedThumbnails::replace($video, $images, true);
            Upload18Claim::query()->where('video_id', $video->id)->update(['needs_thumbnails' => false]);
        }
    }

    /**
     * Another member holds this did (their claim, or an older video of theirs), unless Upload18's item shows that their
     * unconfirmed claim is not their file ($newcomer: what this member's browser knows of the file).
     *
     * @param  array{vid?: ?string, size?: ?int, duration?: ?float}  $newcomer
     */
    private function ownedByAnother(string $did, ?Upload18Item $item, array $newcomer = []): bool
    {
        $claim = UploadClaims::forDid($did);

        if ($claim === null || (int) $claim->user_id === (int) Auth::id()) {
            return false;
        }

        return ! app(UploadClaims::class)->releaseIfBogus($claim, $item, $newcomer);
    }

    /**
     * Whether the status check may give this member the vid of $did (and its length), R1B-027 (S09 residual; owner-
     * approved 2026-10-27, server side only). Before, it answered the vid of any did among Upload18's newest items that
     * had no claim: an upload made before 1.2.3 that never reached /complete, an announce that never finished, a
     * released claim, a video the owner put on Upload18 directly; with the vid a member could play a video that never
     * became a site video, and the length could stand in for the size check of /pending. Now:
     *  - the request already brings the matching vid (the member's browser got it in Upload18's own upload answer, the
     *    normal case): nothing new is learnt, answered as before;
     *  - the member holds the did: a claim Upload18 confirmed, a finished upload, or a pending claim whose file the item
     *    listed now confirms (the size of /pending, the vid of the upload answer, the length).
     * Anybody else gets no vid until they have gone through /pending with its size check; between an update's file swap
     * and its Finish (no claims table yet) nobody holds a did, so only the first case gets it.
     *
     * @param  array{found: bool, ready: bool, vid: string}  $status
     */
    private function mayKnowVid(string $did, ?Upload18Item $item, array $status, string $expectedVid): bool
    {
        if ($expectedVid !== '' && $status['vid'] !== '' && hash_equals($status['vid'], $expectedVid)) {
            return true;
        }

        // No claims table yet (an update's files are in place, its Finish has not run): nobody holds this did.
        if (! UploadClaims::available()) {
            return false;
        }

        $claim = UploadClaims::forDid($did);

        if ($claim === null || (int) $claim->user_id !== (int) Auth::id()) {
            return false;
        }

        if ($claim->verified_at !== null || $claim->state === Upload18Claim::DONE) {
            return true;
        }

        return $item !== null
            && $claim->state === Upload18Claim::PENDING
            && UploadClaims::mismatch($item, $claim->vid, $claim->file_size, $claim->file_duration) === null
            && UploadClaims::confirms($item, $claim->vid, $claim->file_size, $claim->file_duration);
    }

    private function refused(ClaimRefused $refused): JsonResponse
    {
        return response()->json($refused->payload(), $refused->status);
    }

    private function upload18(): Upload18Service
    {
        return app(Upload18Service::class);
    }

    private function chunkCompletionKey(int $userId, string $identifier): string
    {
        return 'upload-completions/'.$userId.'/'.hash('sha256', $identifier).'.json';
    }

    private function completedChunkUpload(string $completionKey, int $userId): ?Video
    {
        if (! Storage::disk('local')->exists($completionKey)) {
            return null;
        }

        $payload = json_decode((string) Storage::disk('local')->get($completionKey), true);
        $videoId = is_array($payload) ? (int) ($payload['video_id'] ?? 0) : 0;

        if ($videoId <= 0) {
            Storage::disk('local')->delete($completionKey);
            return null;
        }

        $video = Video::query()->where('user_id', $userId)->find($videoId);
        if (! $video) {
            Storage::disk('local')->delete($completionKey);
        }

        return $video;
    }

    private function upload18PendingKey(int $userId, string $did): string
    {
        return 'upload18:pending:'.$userId.':'.hash('sha256', $did);
    }

    private function upload18ClaimKey(string $did): string
    {
        return 'upload18:claim:'.hash('sha256', $did);
    }

    private function directUploadAllowed(): bool
    {
        // Allow all authenticated users with upload capabilities to upload directly to Upload18.
        return Auth::check();
    }

    /**
     * Receive base64-encoded JPEG thumbnail frames captured by the browser.
     * Save them as Thumbnail records for the video. Only real JPEG pictures are kept, decoded and encoded again by
     * this server (security plan S20): anything else is refused.
     */
    public function generateThumbnails(Request $request, Video $video): JsonResponse
    {
        $uploadSettings = UploadSettings::fromConfig();
        $maxThumbnails = $uploadSettings->thumbnailCount;
        $maxThumbnailBytes = $uploadSettings->maxThumbnailBytes();

        $request->validate([
            'thumbnails' => 'required|array|min:1|max:' . $maxThumbnails,
            'thumbnails.*' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($maxThumbnailBytes): void {
                    if (GeneratedThumbnails::estimatedBytes((string) $value) > $maxThumbnailBytes) {
                        $fail(__('The thumbnail exceeds the configured max thumbnail size.'));
                    }
                },
            ],
        ]);

        $images = [];
        foreach ($request->input('thumbnails') as $base64) {
            $imageData = GeneratedThumbnails::decode((string) $base64, $maxThumbnailBytes);

            if ($imageData === null) {
                throw ValidationException::withMessages([
                    'thumbnails' => __('One or more thumbnails are invalid or exceed the configured max thumbnail size.'),
                ]);
            }

            $images[] = $imageData;
        }

        $generated = GeneratedThumbnails::replace($video, $images, true);

        return response()->json([
            'success' => true,
            'count' => count($generated),
            'data' => $generated,
        ]);
    }

    /**
     * Generate Upload18 thumbnails server-side without exposing stream URLs to browsers.
     */
    public function generateServerThumbnails(Request $request, Video $video): JsonResponse
    {
        if (!$video->embed_code) {
            return response()->json(['message' => __('No embed code found for this video.')], 404);
        }

        if (!$video->is_upload18) {
            return response()->json(['message' => __('Server thumbnail fallback is only available for Upload18 videos.')], 422);
        }

        $uploadSettings = UploadSettings::fromConfig();
        $count = max(1, min($uploadSettings->thumbnailCount, (int) $request->integer('count', $uploadSettings->thumbnailCount)));
        $maxThumbnailBytes = $uploadSettings->maxThumbnailBytes();

        $images = app(ServerThumbnails::class)->generate($video, $count, $maxThumbnailBytes);

        if (count($images) === 0) {
            return response()->json(['message' => __('Could not capture or generate thumbnails for this video.')], 422);
        }

        $generated = GeneratedThumbnails::replace($video, $images);

        if (count($generated) === 0) {
            return response()->json(['message' => __('Could not capture or generate thumbnails for this video.')], 422);
        }

        Upload18Claim::query()->where('video_id', $video->id)->update(['needs_thumbnails' => false]);

        return response()->json([
            'success' => true,
            'count' => count($generated),
            'data' => $generated,
            'source' => 'server',
        ]);
    }

    /**
     * What the draft's page polls every 10 s. A draft still waiting for Upload18 is checked here too (at most once a
     * minute, like the scheduler), so it is finished while the member looks at it even on a host whose scheduler does
     * not run.
     */
    public function createStatus(Video $video): JsonResponse
    {
        $claim = UploadClaims::pendingFor($video);
        $failedBecause = null;

        if ($claim !== null && ($claim->next_check_at === null || $claim->next_check_at->lte(now()))) {
            $claims = app(UploadClaims::class);

            try {
                $item = $this->upload18()->findItems([$claim->did], 50, 3)[$claim->did] ?? null;

                // No longer among the newest listed videos: one detail call by the upload answer's vid (once a minute
                // at most, like the scheduler).
                if ($item === null && filled($claim->vid)) {
                    $item = $this->upload18()->itemByVid((string) $claim->vid, (string) $claim->did);
                }

                $claims->settle($claim, $item, $failedBecause);
            } catch (Upload18Unavailable) {
                $claims->waitForUpload18($claim);
            } catch (\Throwable $e) {
                report($e);
            }

            $video->refresh();
        }

        $video->loadMissing('thumbnails');

        $readyThumbnailCount = $video->thumbnails
            ->whereIn('status', [ThumbnailStatus::GENERATED, ThumbnailStatus::UPLOADED])
            ->count();

        $answer = [
            'id' => $video->id,
            'status' => $video->status?->value,
            'is_draft' => (bool) $video->is_draft,
            'is_uploading' => (bool) $video->is_uploading,
            'is_failed' => (bool) $video->is_failed,
            'upload18_pending' => UploadClaims::isProcessing($video),
            'upload18_vid' => $video->upload18_vid,
            'has_embed_code' => filled($video->embed_code),
            'embed_url' => $video->embed_url,
            'uploaded_at' => optional($video->uploaded_at)->toIso8601String(),
            'ready_thumbnail_count' => $readyThumbnailCount,
            'pending_thumbnail_count' => $video->thumbnails
                ->where('status', ThumbnailStatus::PENDING)
                ->count(),
        ];

        // This check failed the draft (Upload18 never finished it, or its video is not the member's file): the page says
        // why and stops asking. Its next check is refused (a failed video cannot be edited).
        if ($video->is_failed) {
            $answer['message'] = UploadClaims::message($failedBecause ?? 'failed');
        }

        return response()->json($answer);
    }

    public function delete(Video $video): Response
    {
        $video->forceDelete();

        return response()->noContent();
    }

    private function uploadSettings(): array
    {
        return Setting::getGroup('uploads');
    }

    private function uploadWouldExceedStorageLimit(int $incomingBytes): bool
    {
        $user = Auth::user();

        if (!$user || $user->is_premium) {
            return false;
        }

        $maxStorage = config('plans.free.max_videos_storage');

        if ($maxStorage === null) {
            return false;
        }

        return ((int) $user->uploaded_videos_size + $incomingBytes) > (int) $maxStorage;
    }
}
