<?php

namespace App\Http\Controllers\User;

use App\Enums\CustomPlaylistType;
use App\Http\Controllers\Controller;
use App\Playlists\PlaylistManager;
use App\Playlists\Types\WatchLaterPlaylist;
use App\Models\Video;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomPlaylistController extends Controller
{

    public function show(CustomPlaylistType $type): View
    {
        $playlistManager = PlaylistManager::get($type);

        $playlist = $playlistManager->getPlaylist();
        $videos = $playlistManager->getVideos();

        $playlist->setRelation('videos', $videos);
        $playlist->setRelation('videos_count', $videos->count());

        return view('playlists.custom', [
            'playlist' => $playlist,
            'actions' => $playlistManager->getActions(),
        ]);
    }

    /**
     * Remove watched videos from Watch Later playlist
     */
    public function clearWatchedVideos(): RedirectResponse
    {
        $playlistManager = PlaylistManager::get(CustomPlaylistType::WATCH_LATER);

        /* @var WatchLaterPlaylist $playlistManager */
        $playlistManager->removeWatchedVideos();

        $playlistManager->getPlaylist()->touch();

        return redirect()->back();
    }

    /**
     * Toggle video in user's Watch Later playlist
     */
    public function toggleWatchLater(Request $request, $videoId): JsonResponse
    {
        $video = Video::findOrFail($videoId);

        $playlistManager = PlaylistManager::get(CustomPlaylistType::WATCH_LATER);
        $playlist = $playlistManager->getPlaylist();

        $exists = $playlist->videos()->where('video_id', $video->id)->exists();

        if ($exists) {
            $playlist->videos()->detach($video->id);
            $playlist->touch();

            return response()->json([
                'success' => true,
                'status' => 'removed',
                'saved' => false,
                'message' => __('Removed from Watch Later'),
                'count' => $playlist->videos()->count(),
            ]);
        }

        $lastPosition = (int) ($playlist->videos()->max('playlist_has_videos.position') ?? 0);
        $playlist->videos()->attach($video->id, [
            'position' => $lastPosition + 1,
            'added_at' => now(),
        ]);
        $playlist->touch();

        return response()->json([
            'success' => true,
            'status' => 'added',
            'saved' => true,
            'message' => __('Saved to Watch Later!'),
            'count' => $playlist->videos()->count(),
        ]);
    }

    /**
     * Get video IDs in user's Watch Later playlist
     */
    public function getWatchLaterIds(): JsonResponse
    {
        $playlistManager = PlaylistManager::get(CustomPlaylistType::WATCH_LATER);
        $ids = $playlistManager->getPlaylist()
            ->videos()
            ->pluck('videos.id')
            ->map(fn($id) => (int) $id)
            ->values();

        return response()->json([
            'ids' => $ids,
        ]);
    }
}
