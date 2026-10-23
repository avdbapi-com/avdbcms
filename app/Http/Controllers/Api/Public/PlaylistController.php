<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Resources\Playlist\PlaylistShowResource;
use App\Http\Resources\Video\VideoListResource;
use App\Models\Playlist;
use Illuminate\Http\Resources\Json\ResourceCollection;

class PlaylistController
{
    public function show(Playlist $playlist): PlaylistShowResource
    {
        return new PlaylistShowResource($playlist);
    }

    public function videos(Playlist $playlist): ResourceCollection
    {
        $viewer = auth()->user();
        $videos = $playlist->videos()
            ->when(! $viewer?->is_admin && $viewer?->id !== $playlist->user_id,
                fn ($query) => $query->active()->forLocale())
            ->with('user')
            ->paginate(config('site.per_page_videos', 24));

        return VideoListResource::collection($videos);
    }
}
