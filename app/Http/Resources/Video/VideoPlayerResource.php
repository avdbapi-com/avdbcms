<?php

namespace App\Http\Resources\Video;

use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

/**
 * @mixin Video
 */
class VideoPlayerResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @param Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request) : array
    {
        /** @var Video $video */
        $video = $this->resource;
        $duration = (int) $this->getRawOriginal('duration');

        // Keep the player bootstrap request local and deterministic. A remote
        // Upload18 metadata scan here made the first frame wait on a provider
        // list request whenever an imported row had no stored duration. The
        // scheduled metadata sync owns that backfill; playback must never
        // depend on it.

        return [
            'thumbnail' => $this->thumbnail_url,
            'file' => $this->file_url,
            'embed_url' => $video->embed_url,
            'is_upload18' => $video->is_upload18,
            'duration' => $duration,
            'formated_duration' => $duration > 0 ? $this->formatSeconds($duration) : $this->duration,
            'show_ad' => $this->show_ad
                && config('site.ads_enabled', false)
                && config('site.ad_preroll_enabled', false)
                && !Auth::user()?->is_premium
                && $this->status === VideoStatus::PUBLIC,
        ];
    }

    private function formatSeconds(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }

        return sprintf('%d:%02d', $m, $s);
    }
}
