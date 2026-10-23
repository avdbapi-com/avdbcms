<?php

namespace App\Http\Resources\Playlist;

use App\Models\Playlist;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * @mixin Playlist
 */
class PlaylistListResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request) : array
    {
        // Public playlist listings only count active, locale-visible videos. Reuse an
        // intentionally loaded relation for private/mobile listings; otherwise keep
        // the preview query bounded to one public video.
        $firstVideo = $this->relationLoaded('videos')
            ? $this->videos->first()
            : $this->videos()
                ->active()
                ->forLocale()
                ->with('thumbnail')
                ->first();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => Str::limit($this->title, 90),
            'thumbnail' => $firstVideo?->thumbnail_url,
            'route' => locale_url($this->route),
            'is_active' => $this->is_active,
            'icon' => $this->status->icon(),
            'status' => $this->status->name,
            'has_video' => $this->whenHas('has_video'),
            'videos_count' => $this->whenCounted('videos'),
        ];
    }
}
