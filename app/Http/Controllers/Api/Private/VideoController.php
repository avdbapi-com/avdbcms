<?php

namespace App\Http\Controllers\Api\Private;

use App\Data\UploadSettings;
use App\Enums\ThumbnailStatus;
use App\Enums\VideoStatus;
use App\Events\Video\VideoUploaded;
use App\Helpers\Number;
use App\Http\Requests\Video\CompleteUploadRequest;
use App\Http\Requests\Video\FileRequest;
use App\Jobs\UploadToUpload18;
use App\Models\Setting;
use App\Models\Thumbnail;
use App\Models\Video;
use App\Support\MediaBinary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Api\Private\Traits\Upload18ServerStreamTrait;
use App\Services\Upload18Service;
use Illuminate\Support\Str;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class VideoController
{
    use Upload18ServerStreamTrait;

    /**
     * Return Upload18 API config for direct browser upload.
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
            'cid' => $cid,
            'mycid' => $mycid,
            'fid' => $fid,
            'chunk_size' => $uploadSettings->chunkSizeMb * 1000 * 1000,
            'server_chunk_size' => min(5 * 1000 * 1000, $uploadSettings->chunkSizeMb * 1000 * 1000),
            'chunk_size_mb' => $uploadSettings->chunkSizeMb,
            'thumbnail_count' => $uploadSettings->thumbnailCount,
        ];

        if ($directAllowed) {
            $payload['apikey'] = $apiKey;
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

        try {
            $status = app(Upload18Service::class)->statusForDid((string) $request->input('did'));
        } catch (\Throwable) {
            return response()->json(['error' => __('Video processing status is not available.')], 502);
        }

        $expectedVid = (string) $request->input('vid', '');
        if ($expectedVid !== '' && $status['vid'] !== '' && $status['vid'] !== $expectedVid) {
            return response()->json(['error' => __('Upload18 returned a different video ID.')], 409);
        }

        if ($status['found']) {
            $claimKey = $this->upload18ClaimKey((string) $request->input('did'));
            $claimedBy = Cache::get($claimKey);
            if ($claimedBy !== null && (int) $claimedBy !== (int) Auth::id()) {
                return response()->json([
                    'message' => __('This Upload18 video is already claimed by another upload session.'),
                ], 403);
            }

            Cache::add($claimKey, (int) Auth::id(), now()->addMinutes(30));
            Cache::put(
                $this->upload18PendingKey((int) Auth::id(), (string) $request->input('did')),
                ['vid' => (string) ($status['vid'] ?? '')],
                now()->addMinutes(30)
            );
        }

        return response()->json($status);
    }

    /**
     * Called by the browser after upload + polling completes.
     * The browser sends did (Upload18 ID) and vid (embed hash).
     * No queue jobs needed — everything is already done.
     */
    public function completeUpload(CompleteUploadRequest $request): JsonResponse
    {
        if (! $this->directUploadAllowed()) {
            return response()->json([
                'message' => __('Direct Upload18 uploads are restricted. Please log in to upload.'),
            ], 403);
        }

        $data = $request->validated();
        $did = $data['did'];
        $vid = $data['vid'];
        $originalName = $data['filename'];
        $size = (int) $data['size'];
        $user = Auth::user();

        $existing = Video::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($did, $vid) {
                $query->where('upload18_vid', $did)
                    ->orWhere('embed_code', $vid);
            })
            ->first();

        if ($existing) {
            Cache::forget($this->upload18PendingKey((int) $user->id, $did));
            $images = $this->extractImagesFromPayload($data['thumbnails'] ?? []);
            if (count($images) > 0) {
                $readyCount = $existing->thumbnails()
                    ->whereIn('status', [ThumbnailStatus::GENERATED, ThumbnailStatus::UPLOADED])
                    ->count();
                if ($readyCount === 0) {
                    $this->replaceGeneratedThumbnails($existing, $images);
                }
            }

            return response()->json([
                'route' => route('user.videos.create', $existing),
            ]);
        }

        $pending = Cache::get($this->upload18PendingKey((int) $user->id, $did));
        $claimedBy = Cache::get($this->upload18ClaimKey($did));
        if (($claimedBy !== null && (int) $claimedBy !== (int) $user->id)
            || ! is_array($pending)
            || ($pending['vid'] ?? '') !== $vid) {
            return response()->json([
                'message' => __('Upload18 status must be checked from this account before finalizing the upload.'),
            ], 409);
        }

        if ($this->uploadWouldExceedStorageLimit($size)) {
            return response()->json([
                'message' => __('Sorry ! Your available video storage is not enough for this file. Upgrade to Premium plan to increase your storage space.'),
            ], 403);
        }

        try {
            $remoteStatus = app(Upload18Service::class)->statusForDid($did);
        } catch (\Throwable) {
            return response()->json([
                'message' => __('Upload18 status is temporarily unavailable. Please retry finalizing in a moment.'),
            ], 502);
        }

        if (!$remoteStatus['found'] || !$remoteStatus['ready'] || $remoteStatus['vid'] !== $vid) {
            return response()->json([
                'message' => __('Upload18 has not finished processing this video yet. Please wait and retry.'),
                'status' => $remoteStatus,
            ], 409);
        }

        $duration = $remoteStatus['duration_seconds']
            ?: (($data['duration_seconds'] ?? null) ?: Upload18Service::durationToSeconds($data['duration'] ?? null));

        $maxDuration = max(1, (int) (config('site.upload_max_duration') ?? 3600));
        if ($duration && $duration > $maxDuration) {
            return response()->json([
                'message' => __('The video duration exceeds the allowed limit.'),
            ], 422);
        }

        $title = pathinfo($originalName, PATHINFO_FILENAME);

        $video = Video::create([
            'uuid' => Number::unique(),
            'title' => $title,
            'slug' => Str::slug($title),
            'original_file_name' => $originalName,
            'size' => $size,
            'status' => VideoStatus::DRAFT,
            'user_id' => $user->id,
            'upload18_vid' => $did,
            'embed_code' => $vid,
            'duration' => $duration,
            'uploaded_at' => now(),
        ]);

        $images = $this->extractImagesFromPayload($data['thumbnails'] ?? []);

        if (count($images) > 0) {
            $this->replaceGeneratedThumbnails($video, $images);
        } else {
            for ($i = 0; $i < Video::generatedThumbnailCount(); $i++) {
                $video->thumbnails()->create([
                    'status' => ThumbnailStatus::PENDING,
                    'is_active' => $i === 0,
                ]);
            }
        }

        // Fire the video uploaded event (notifications, etc.)
        VideoUploaded::dispatch($video);
        Cache::forget($this->upload18PendingKey((int) $user->id, $did));

        return response()->json([
            'route' => route('user.videos.create', $video),
        ]);
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
     * Save them as Thumbnail records for the video.
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
                    if ($this->estimatedDecodedThumbnailBytes((string) $value) > $maxThumbnailBytes) {
                        $fail(__('The thumbnail exceeds the configured max thumbnail size.'));
                    }
                },
            ],
        ]);

        foreach ($request->input('thumbnails') as $base64) {
            if ($this->decodeGeneratedThumbnail((string) $base64, $maxThumbnailBytes) === null) {
                throw ValidationException::withMessages([
                    'thumbnails' => __('One or more thumbnails are invalid or exceed the configured max thumbnail size.'),
                ]);
            }
        }

        $images = [];
        foreach ($request->input('thumbnails') as $base64) {
            $imageData = $this->decodeGeneratedThumbnail((string) $base64, $maxThumbnailBytes);

            if ($imageData === null) {
                throw ValidationException::withMessages([
                    'thumbnails' => __('One or more thumbnails are invalid or exceed the configured max thumbnail size.'),
                ]);
            }

            $images[] = $imageData;
        }

        $generated = $this->replaceGeneratedThumbnails($video, $images);

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

        $images = [];

        // Attempt stream extraction only if the server environment supports running CLI processes
        if (MediaBinary::canRunProcesses() && MediaBinary::hasFfmpeg()) {
            $streamData = $this->resolveUpload18Stream($video);

            if (!empty($streamData['stream_url'])) {
                $streamUrl = $streamData['stream_url'];
                $tempDir = storage_path('app/tmp/server-thumbnails/' . $video->id . '-' . Str::uuid());

                if (is_dir($tempDir) || mkdir($tempDir, 0775, true)) {
                    try {
                        $images = $this->captureServerThumbnailsFromSegments($video, $streamUrl, $count, $tempDir, $maxThumbnailBytes);

                        if (count($images) === 0 && MediaBinary::hasFfprobe()) {
                            $duration = $this->probeStreamDuration($streamUrl, $video);
                            $positions = $this->thumbnailPositions($duration, $count);

                            foreach ($positions as $index => $position) {
                                $output = $tempDir . DIRECTORY_SEPARATOR . 'thumb-hls-' . ($index + 1) . '.jpg';

                                if (!$this->captureServerThumbnail($streamUrl, $position, $output, $video)) {
                                    continue;
                                }

                                $imageData = $this->readGeneratedJpeg($output, $maxThumbnailBytes);
                                if ($imageData !== null) {
                                    $images[] = $imageData;
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Server thumbnail capture exception for video ' . $video->id . ': ' . $e->getMessage());
                    } finally {
                        $this->deleteDirectory($tempDir);
                    }
                }
            }
        }

        // If ffmpeg / stream capture was unavailable or yielded 0 images (e.g. Cloudflare blocked datacenter IP or proc_open disabled),
        // generate branded 16:9 poster cards using PHP GD so thumbnails are NEVER left in stuck pending state!
        if (count($images) === 0) {
            $images = $this->generateFallbackCardThumbnails($video, $count, $maxThumbnailBytes);
        }

        if (count($images) === 0) {
            return response()->json(['message' => __('Could not capture or generate thumbnails for this video.')], 422);
        }

        $generated = $this->replaceGeneratedThumbnails($video, $images);

        return response()->json([
            'success' => true,
            'count' => count($generated),
            'data' => $generated,
            'source' => 'server',
        ]);
    }

    public function createStatus(Video $video): JsonResponse
    {
        $video->loadMissing('thumbnails');

        $readyThumbnailCount = $video->thumbnails
            ->whereIn('status', [ThumbnailStatus::GENERATED, ThumbnailStatus::UPLOADED])
            ->count();

        return response()->json([
            'id' => $video->id,
            'status' => $video->status?->value,
            'is_draft' => (bool) $video->is_draft,
            'is_uploading' => (bool) $video->is_uploading,
            'upload18_vid' => $video->upload18_vid,
            'has_embed_code' => filled($video->embed_code),
            'embed_url' => $video->embed_url,
            'uploaded_at' => optional($video->uploaded_at)->toIso8601String(),
            'ready_thumbnail_count' => $readyThumbnailCount,
            'pending_thumbnail_count' => $video->thumbnails
                ->where('status', ThumbnailStatus::PENDING)
                ->count(),
        ]);
    }

    private function captureServerThumbnailsFromSegments(Video $video, string $streamUrl, int $count, string $tempDir, int $maxThumbnailBytes): array
    {
        $manifest = $this->fetchUpload18StreamBody($video, $streamUrl);
        if ($manifest === null || !str_contains($manifest, '#EXTM3U')) {
            return [];
        }

        $segmentUrls = $this->collectHlsSegmentUrls($video, $streamUrl, $manifest);

        $images = [];
        $positions = [0.75, 1.5, 2.25, 3.25, 4.5, 6.0];

        foreach ($segmentUrls as $segmentIndex => $segmentUrl) {
            if (count($images) >= $count) {
                break;
            }

            $segmentBody = $this->fetchUpload18StreamBody($video, $segmentUrl);
            if ($segmentBody === null || strlen($segmentBody) < 1000) {
                continue;
            }

            $segmentPath = $tempDir . DIRECTORY_SEPARATOR . 'segment-' . ($segmentIndex + 1) . '.ts';
            file_put_contents($segmentPath, $segmentBody);

            $remaining = $count - count($images);
            $seriesPattern = $tempDir . DIRECTORY_SEPARATOR . 'thumb-segment-' . ($segmentIndex + 1) . '-%02d.jpg';
            if ($this->captureServerThumbnailSeries($segmentPath, $remaining, $seriesPattern)) {
                $seriesFiles = glob($tempDir . DIRECTORY_SEPARATOR . 'thumb-segment-' . ($segmentIndex + 1) . '-*.jpg') ?: [];
                sort($seriesFiles);

                foreach ($seriesFiles as $seriesFile) {
                    if (count($images) >= $count) {
                        break 2;
                    }

                    $imageData = $this->readGeneratedJpeg($seriesFile, $maxThumbnailBytes);
                    if ($imageData !== null) {
                        $images[] = $imageData;
                    }
                }
            }

            foreach ($positions as $positionIndex => $position) {
                if (count($images) >= $count) {
                    break 2;
                }

                $output = $tempDir . DIRECTORY_SEPARATOR . 'thumb-segment-' . ($segmentIndex + 1) . '-' . ($positionIndex + 1) . '.jpg';
                if (!$this->captureServerThumbnail($segmentPath, $position, $output)) {
                    continue;
                }

                $imageData = $this->readGeneratedJpeg($output, $maxThumbnailBytes);
                if ($imageData !== null) {
                    $images[] = $imageData;
                }
            }
        }

        return $images;
    }

    private function collectHlsSegmentUrls(Video $video, string $playlistUrl, string $manifest, int $depth = 0): array
    {
        if ($depth > 2) {
            return [];
        }

        $segments = [];
        $variants = [];
        $urlBase = substr($playlistUrl, 0, strrpos($playlistUrl, '/') + 1);
        $previousLine = '';

        foreach (preg_split('/\r?\n/', $manifest) as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#')) {
                $previousLine = $line;
                continue;
            }

            $absoluteUrl = $this->absoluteStreamUrl($line, $urlBase);
            $path = strtolower(parse_url($absoluteUrl, PHP_URL_PATH) ?? '');

            if (str_contains($path, '.m3u8') || str_contains(strtolower($line), 'm3u8') || str_starts_with($previousLine, '#EXT-X-STREAM-INF')) {
                $variants[] = $absoluteUrl;
            } else {
                $segments[] = $absoluteUrl;
            }

            $previousLine = $line;
        }

        if (count($segments) > 0) {
            return $segments;
        }

        foreach (array_slice($variants, 0, 3) as $variantUrl) {
            $variantManifest = $this->fetchUpload18StreamBody($video, $variantUrl);
            if ($variantManifest === null || !str_contains($variantManifest, '#EXTM3U')) {
                continue;
            }

            $segments = $this->collectHlsSegmentUrls($video, $variantUrl, $variantManifest, $depth + 1);
            if (count($segments) > 0) {
                return $segments;
            }
        }

        return [];
    }

    private function captureServerThumbnailSeries(string $input, int $count, string $outputPattern): bool
    {
        if (!MediaBinary::canRunProcesses() || !MediaBinary::hasFfmpeg() || $count <= 0) {
            return false;
        }

        $process = new Process([
            $this->ffmpegBinary(),
            '-y',
            '-hide_banner',
            '-loglevel', 'error',
            '-i', $input,
            '-vf', 'fps=1,scale=640:-2',
            '-frames:v', (string) $count,
            '-an',
            '-q:v', '3',
            $outputPattern,
        ]);
        $process->setTimeout(120);

        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful() && count(glob(str_replace('%02d', '*', $outputPattern)) ?: []) > 0;
    }

    private function readGeneratedJpeg(string $path, int $maxThumbnailBytes): ?string
    {
        $imageData = @file_get_contents($path);

        if (!is_string($imageData) || $imageData === '' || strlen($imageData) > $maxThumbnailBytes || !str_starts_with($imageData, "\xff\xd8")) {
            return null;
        }

        return $imageData;
    }

    private function replaceGeneratedThumbnails(Video $video, array $images): array
    {
        $generated = [];
        $isFirst = true;

        $video->thumbnails()
            ->where(function ($q) {
                $q->where('status', ThumbnailStatus::GENERATED)
                  ->orWhere('status', ThumbnailStatus::PENDING);
            })
            ->where('video_id', $video->id)
            ->each(function (Thumbnail $thumbnail) {
                if ($thumbnail->file) {
                    Storage::disk('local')->delete(Video::THUMBNAIL_FOLDER . '/' . $thumbnail->file);
                }

                $thumbnail->delete();
            });

        foreach ($images as $imageData) {
            $filename = Str::uuid() . '.jpg';
            Storage::disk('local')->put(Video::THUMBNAIL_FOLDER . '/' . $filename, $imageData);

            $thumbnail = $video->thumbnails()->create([
                'file' => $filename,
                'status' => ThumbnailStatus::GENERATED,
                'is_active' => $isFirst,
            ]);

            if ($isFirst) {
                $video->thumbnails()
                    ->where('id', '!=', $thumbnail->id)
                    ->update(['is_active' => false]);
            }

            $generated[] = [
                'id' => $thumbnail->id,
                'url' => $thumbnail->url,
                'status' => $thumbnail->status->value,
                'is_active' => (bool) $thumbnail->is_active,
            ];

            $isFirst = false;
        }

        return $generated;
    }

    private function probeStreamDuration(string $streamUrl, ?Video $video = null): ?float
    {
        if (!MediaBinary::canRunProcesses() || !MediaBinary::hasFfprobe()) {
            return null;
        }

        $command = [
            $this->ffprobeBinary(),
            '-v', 'error',
        ];

        if (str_starts_with($streamUrl, 'http://') || str_starts_with($streamUrl, 'https://')) {
            array_push($command,
                '-user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            );

            if ($video) {
                array_push($command, '-headers', $this->upload18FfmpegHeaders($video));
            }
        }

        array_push($command,
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $streamUrl,
        );

        $process = new Process($command);
        $process->setTimeout(75);
        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        $duration = (float) trim($process->getOutput());

        return $duration > 0 ? $duration : null;
    }

    private function thumbnailPositions(?float $duration, int $count): array
    {
        if ($duration === null || $duration <= 1) {
            return array_slice([0.75, 1.5, 2.25, 3.0, 4.0], 0, $count);
        }

        $positions = [];
        $safeDuration = max(1.0, $duration - 0.5);

        for ($i = 1; $i <= $count; $i++) {
            $positions[] = max(0.5, min($safeDuration, $duration * ($i / ($count + 1))));
        }

        return $positions;
    }

    private function captureServerThumbnail(string $streamUrl, float $position, string $output, ?Video $video = null): bool
    {
        if (!MediaBinary::canRunProcesses() || !MediaBinary::hasFfmpeg()) {
            return false;
        }

        $command = [
            $this->ffmpegBinary(),
            '-y',
            '-hide_banner',
            '-loglevel', 'error',
        ];

        if (str_starts_with($streamUrl, 'http://') || str_starts_with($streamUrl, 'https://')) {
            $command[] = '-user_agent';
            $command[] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

            if ($video) {
                $command[] = '-headers';
                $command[] = $this->upload18FfmpegHeaders($video);
            }
        }

        array_push($command,
            '-ss', number_format($position, 3, '.', ''),
            '-i', $streamUrl,
            '-frames:v', '1',
            '-an',
            '-vf', 'scale=640:-2',
            '-q:v', '3',
            $output,
        );

        $process = new Process($command);
        $process->setTimeout(120);
        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful() && is_file($output) && filesize($output) > 1024;
    }

    private function ffmpegBinary(): string
    {
        return MediaBinary::ffmpeg();
    }

    private function ffprobeBinary(): string
    {
        return MediaBinary::ffprobe();
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
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

    private function decodeGeneratedThumbnail(string $payload, int $maxDecodedBytes): ?string
    {
        $base64 = $this->normalizeBase64Thumbnail($payload);

        if ($base64 === '' || $this->estimatedDecodedThumbnailBytes($base64) > $maxDecodedBytes) {
            return null;
        }

        $decoded = base64_decode($base64, true);

        if ($decoded === false || $decoded === '' || strlen($decoded) > $maxDecodedBytes) {
            return null;
        }

        if (!str_starts_with($decoded, "\xff\xd8")) {
            return null;
        }

        return $decoded;
    }

    private function estimatedDecodedThumbnailBytes(string $payload): int
    {
        $base64 = $this->normalizeBase64Thumbnail($payload);

        if ($base64 === '') {
            return 0;
        }

        $padding = substr_count(substr($base64, -2), '=');

        return max(0, (int) floor((strlen($base64) * 3) / 4) - $padding);
    }

    private function normalizeBase64Thumbnail(string $payload): string
    {
        $base64 = preg_replace('/^data:image\/[A-Za-z0-9.+-]+;base64,/i', '', trim($payload)) ?? '';

        return preg_replace('/\s+/', '', $base64) ?? '';
    }

    private function extractImagesFromPayload(mixed $rawThumbnails): array
    {
        if (!is_array($rawThumbnails) || empty($rawThumbnails)) {
            return [];
        }

        $uploadSettings = UploadSettings::fromConfig();
        $maxThumbnailBytes = $uploadSettings->maxThumbnailBytes();
        $images = [];

        foreach ($rawThumbnails as $base64) {
            $imageData = $this->decodeGeneratedThumbnail((string) $base64, $maxThumbnailBytes);
            if ($imageData !== null) {
                $images[] = $imageData;
            }
        }

        return $images;
    }

    private function generateFallbackCardThumbnails(Video $video, int $count, int $maxThumbnailBytes): array
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            return [];
        }

        $themes = [
            [
                'bg_top' => [28, 12, 20],
                'bg_bot' => [12, 8, 14],
                'accent' => [239, 68, 68],
            ],
            [
                'bg_top' => [16, 20, 40],
                'bg_bot' => [8, 10, 20],
                'accent' => [99, 102, 241],
            ],
            [
                'bg_top' => [12, 26, 34],
                'bg_bot' => [6, 14, 20],
                'accent' => [14, 165, 233],
            ],
            [
                'bg_top' => [24, 20, 12],
                'bg_bot' => [12, 10, 6],
                'accent' => [234, 179, 8],
            ],
            [
                'bg_top' => [14, 26, 18],
                'bg_bot' => [6, 14, 10],
                'accent' => [34, 197, 94],
            ],
        ];

        $width = 640;
        $height = 360;
        $images = [];
        $title = $video->title ?: 'Video';

        for ($i = 0; $i < $count; $i++) {
            $theme = $themes[$i % count($themes)];
            $im = @imagecreatetruecolor($width, $height);
            if (!$im) {
                continue;
            }

            // Vertical subtle gradient
            for ($y = 0; $y < $height; $y++) {
                $ratio = $y / $height;
                $r = (int) ($theme['bg_top'][0] + ($theme['bg_bot'][0] - $theme['bg_top'][0]) * $ratio);
                $g = (int) ($theme['bg_top'][1] + ($theme['bg_bot'][1] - $theme['bg_top'][1]) * $ratio);
                $b = (int) ($theme['bg_top'][2] + ($theme['bg_bot'][2] - $theme['bg_top'][2]) * $ratio);
                $color = imagecolorallocate($im, $r, $g, $b);
                imageline($im, 0, $y, $width, $y, $color);
            }

            // Ambient glow circle in center
            $glowColor = imagecolorallocatealpha($im, $theme['accent'][0], $theme['accent'][1], $theme['accent'][2], 100);
            imagefilledellipse($im, (int) ($width / 2), (int) ($height / 2), 150, 150, $glowColor);

            // Center button circle
            $buttonColor = imagecolorallocatealpha($im, $theme['accent'][0], $theme['accent'][1], $theme['accent'][2], 25);
            imagefilledellipse($im, (int) ($width / 2), (int) ($height / 2), 76, 76, $buttonColor);

            // Center play triangle
            $white = imagecolorallocate($im, 255, 255, 255);
            $cx = (int) ($width / 2);
            $cy = (int) ($height / 2);
            $points = [
                $cx - 10, $cy - 16,
                $cx - 10, $cy + 16,
                $cx + 16, $cy,
            ];
            imagefilledpolygon($im, $points, 3, $white);

            // Clean bottom title label
            $safeTitle = mb_strimwidth($title, 0, 46, '...');
            $textColor = imagecolorallocate($im, 220, 225, 235);
            $font = 4;
            $textWidth = imagefontwidth($font) * strlen($safeTitle);
            $tx = max(20, (int) (($width - $textWidth) / 2));
            imagestring($im, $font, $tx, $height - 36, $safeTitle, $textColor);

            ob_start();
            imagejpeg($im, null, 85);
            $data = ob_get_clean();
            imagedestroy($im);

            if (is_string($data) && strlen($data) > 100 && strlen($data) <= $maxThumbnailBytes && str_starts_with($data, "\xff\xd8")) {
                $images[] = $data;
            }
        }

        return $images;
    }
}
