<?php

namespace App\Http\Resources\Playlist;

use App\Http\Resources\User\UserListResource;
use App\Http\Resources\Video\VideoListResource;
use App\Models\Playlist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin Playlist
 */
class PlaylistShowResource extends JsonResource
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
        $viewer = $request->user();
        $videosQuery = $this->videos()
            ->when(! $viewer?->is_admin && $viewer?->id !== $this->user_id,
                fn ($query) => $query->active()->forLocale());
        $videoCount = (clone $videosQuery)->count();
        $playlistVideos = $videosQuery
            ->with('user')
            ->limit(config('site.per_page_videos', 24))
            ->get();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => Str::limit($this->title, 90),
            'thumbnail' => $playlistVideos->first()?->thumbnail_url,
            'description' => $this->description,
            'icon' => $this->status->icon(),
            'is_active' => $this->is_active,
            'route' => $this->route,
            'videos_count' => $videoCount,
            'user' => UserListResource::make($this->user),
            'videos' => VideoListResource::collection($playlistVideos)
        ];
    }
}
