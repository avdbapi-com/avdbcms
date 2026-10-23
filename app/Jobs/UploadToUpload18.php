<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Events\Video\VideoError;
use App\Events\Video\VideoUploaded;
use App\Helpers\VideoMetadata;
use App\Models\Video;
use App\Services\FileMerger;
use App\Services\Upload18Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadToUpload18 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes (upload can be slow)
    public int $tries = 2;
    public bool $deleteWhenMissingModels = true;

    public string $folder;
    public Video $video;
    public string $extension;

    public function __construct(string $folder, Video $video, string $extension)
    {
        $this->folder = $folder;
        $this->video = $video;
        $this->extension = $extension;
        $this->onQueue(config('queue.names.uploads', 'uploads'));
    }

    public function handle(): void
    {
        // 1. Merge chunks into a single file
        $files = Storage::disk('local')->files(Video::CHUNK_FOLDER . '/' . $this->folder);

        if (empty($files)) {
            Log::warning("UploadToUpload18: No chunk files found in {$this->folder}");
            throw new \Exception("No chunk files found for video {$this->video->id}");
        }

        natcasesort($files);

        $fileName = uniqid() . '.' . $this->extension;
        $path = Video::VIDEO_FOLDER . '/' . $fileName;
        $absolutePath = Storage::disk('local')->path($path);

        $fileMerger = new FileMerger($absolutePath);

        foreach ($files as $file) {
            $fileMerger->appendFile(Storage::disk('local')->path($file));
            Storage::disk('local')->delete($file);
        }

        $fileMerger->close();
        Storage::disk('local')->deleteDirectory(Video::CHUNK_FOLDER . '/' . $this->folder);

        Log::info("UploadToUpload18: Merged file {$absolutePath} (" . round(filesize($absolutePath) / 1024 / 1024, 1) . " MB)");

        $localFileAttributes = [
            'file' => $fileName,
            'original_mimetype' => Storage::disk('local')->mimeType($path) ?: Video::MIMETYPE,
            'size' => filesize($absolutePath),
        ];

        $thumbnailJobs = [];

        // 2. Generate thumbnails from local file BEFORE uploading
        try {
            $duration = VideoMetadata::getDuration($absolutePath);
            $timecodes = [1, intval(round($duration / 2)), intval($duration - 1)];
            $thumbnails = $this->video->thumbnails()->orderBy('id')->get();

            foreach ($timecodes as $index => $time) {
                $thumbnail = $thumbnails->get($index);
                if ($thumbnail) {
                    $thumbnailJobs[] = new GenerateThumbnail($thumbnail, $time);
                }
            }

            // Update video duration/size from local file
            $this->video->update(array_merge($localFileAttributes, [
                'duration' => round($duration),
            ]));
        } catch (\Exception $e) {
            Log::warning("UploadToUpload18: Could not extract metadata: " . $e->getMessage());
            $this->video->update($localFileAttributes);
            // Continue anyway — Upload18 will handle the video
        }

        // 3. Upload to Upload18
        $upload18 = new Upload18Service();

        try {
            $result = $upload18->upload($absolutePath, $this->video->title);

            Log::info("UploadToUpload18: Upload API response", $result);

            // Upload returns: {"status":"success","msg":"Video upload complete","did":"1107533","vid":""}
            // The vid (embed hash) is empty immediately — we need to poll /myvideo for it
            $did = $result['did'] ?? null;

            if (!$did) {
                throw new \Exception("Upload18: Upload succeeded but no 'did' in response: " . json_encode($result));
            }

            // Store did as upload18_vid temporarily and mark uploaded
            $this->video->update([
                'upload18_vid' => $did,
                'uploaded_at' => now(),
            ]);

            // 4. Poll for the embed code (vid) — wait for transcoding to complete
            Log::info("UploadToUpload18: Polling for embed code (did={$did})...");
            $videoData = $upload18->pollForEmbedCode($did, 600); // Wait up to 10 minutes

            if ($videoData && !empty($videoData['vid'])) {
                $embedCode = $videoData['vid'];
                $duration = Upload18Service::durationToSeconds($videoData['duration'] ?? null);
                $this->video->update([
                    'embed_code' => $embedCode,
                    'duration' => $duration ?: $this->video->getRawOriginal('duration'),
                ]);
                Log::info("UploadToUpload18: Video {$this->video->id} ready with embed_code={$embedCode}");
            } else {
                Log::warning("UploadToUpload18: Video {$this->video->id} uploaded (did={$did}) but embed code not yet available. Will need manual polling.");
            }

        } catch (\Exception $e) {
            Log::error("UploadToUpload18: Upload failed: " . $e->getMessage());
            // Clean up local file
            @unlink($absolutePath);
            $this->video->update(['file' => null]);
            throw $e;
        }

        // 5. Generate thumbnails batch
        if (!empty($thumbnailJobs)) {
            $videoId = $this->video->id;

            Bus::batch($thumbnailJobs)
                ->name("GenerateThumbnails for video : {$this->video->id}")
                ->finally(function (Batch $batch) use ($absolutePath, $videoId) {
                    // Delete local video file after thumbnails are generated
                    @unlink($absolutePath);
                    Video::whereKey($videoId)->update(['file' => null]);
                })
                ->dispatch();
        } else {
            // No thumbnail jobs, just delete the local file
            @unlink($absolutePath);
            $this->video->update(['file' => null]);
        }

        VideoUploaded::dispatch($this->video);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("UploadToUpload18: Job failed for video {$this->video->id}: " . $exception->getMessage());
        $this->video->update(['status' => VideoStatus::FAILED]);
        VideoError::dispatch($this->video);
    }
}
