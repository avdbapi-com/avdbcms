<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Events\Video\VideoError;
use App\Events\Video\VideoUploaded;
use App\Models\Video;
use App\Services\Upload18Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lightweight job: polls Upload18's /myvideo endpoint until the video
 * is transcoded (zt=2) and the embed hash (vid) becomes available.
 * No local file I/O — the browser uploaded directly to Upload18.
 */
class PollUpload18Embed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 minutes max
    public int $tries = 3;

    public Video $video;
    public string $did; // Upload18 document ID from the upload response

    public function __construct(Video $video, string $did)
    {
        $this->video = $video;
        $this->did = $did;
        $this->onQueue(config('queue.names.uploads', 'uploads'));
    }

    public function handle(): void
    {
        $upload18 = new Upload18Service();

        Log::info("PollUpload18Embed: Polling for embed code (did={$this->did}, video={$this->video->id})");

        $videoData = $upload18->pollForEmbedCode($this->did, 600); // Wait up to 10 minutes

        if ($videoData && !empty($videoData['vid'])) {
            $embedCode = $videoData['vid'];
            $duration = Upload18Service::durationToSeconds($videoData['duration'] ?? null);

            $this->video->update([
                'embed_code' => $embedCode,
                'duration' => $duration ?: $this->video->getRawOriginal('duration'),
                'uploaded_at' => now(),
            ]);

            Log::info("PollUpload18Embed: Video {$this->video->id} ready with embed_code={$embedCode}");

            VideoUploaded::dispatch($this->video);
        } else {
            Log::warning("PollUpload18Embed: Video {$this->video->id} (did={$this->did}) — embed code not available after polling");
            // Don't fail — the video exists on Upload18, embed may appear later
            // Mark uploaded_at so the UI knows we tried
            $this->video->update([
                'uploaded_at' => now(),
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("PollUpload18Embed: Job failed for video {$this->video->id}: " . $exception->getMessage());
        $this->video->update(['status' => VideoStatus::FAILED]);
        VideoError::dispatch($this->video);
    }
}
