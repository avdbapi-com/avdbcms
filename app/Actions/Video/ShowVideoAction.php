<?php

namespace App\Actions\Video;

use App\Data\NextVideoDTO;
use App\Models\Interaction;
use App\Models\Playlist;
use App\Models\Video;
use App\Playlists\Types\LikedVideosPlaylist;
use App\Services\VideoService;
use App\Support\AdminIpLock;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Collection;

class ShowVideoAction
{
    private Video $video;
    private ?Playlist $playlist = null;
    private ?int $currentIndex = null;
    private ?Collection $playlistVideos = null;

    public function __construct(private readonly VideoService $videoService)
    {
    }

    public function data(Video $video): array
    {
        $this->video = $video;

        $this->playlist = $this->getPlaylist();

        $suggestedVideos = $this->getSuggestedVideos();

        $nextVideo = $this->getNextVideo($suggestedVideos);

        return [
            'video' => $this->getVideoData(),
            'videos' => $suggestedVideos,
            'nextVideo' => $nextVideo,
            'playlist' => $this->playlist,
            'playlistVideos' => $this->playlistVideos,
            'currentIndex' => $this->currentIndex,
        ];
    }

    private function getSuggestedVideos(): Collection
    {
        return $this->videoService->getSuggestedVideos($this->video);
    }

    private function getNextVideo(Collection $suggestedVideos): NextVideoDTO|null
    {
        return $this->videoService->getNextVideo($suggestedVideos, $this->playlist, $this->playlistVideos, $this->currentIndex);
    }

    private function getVideoData(): Video
    {
        $video = $this->video
            ->load([
                'user' => fn(BelongsTo $query) => $query->withCount('subscribers'),
                'reportByAuthUser',
                'tags.translations',
            ])
            ->loadCount([
                'likes',
                'dislikes',
                'comments as comments_count' => fn ($query) => $query->public(),
            ])
            ->loadExists([
                'likes as liked_by_auth_user' => fn($query) => $query->where('user_id', Auth::id()),
                'dislikes as disliked_by_auth_user' => fn($query) => $query->where('user_id', Auth::id()),
            ]);

        // 1.2.3: no tag named with a word that is always blocked, on the page or in its keywords (App\Support\ContentSafety)
        return $video->setRelation('tags', \App\Support\ContentSafety::visibleTags($video->tags));
    }

    private function getPlaylist(): ?Playlist
    {
        $playlistUuid = request()->string('list');
        $viewer = Auth::user();

        if ($playlistUuid->isEmpty()) {
            return null;
        }

        $playlistQuery = Playlist::query()
            ->where('uuid', $playlistUuid->value());

        // Another member's private playlist only for an administrator with admin powers here: from an address
        // outside the admin IP lock an administrator is an ordinary member (R1-089), as on the playlist page.
        if (! AdminIpLock::adminPowersHere($viewer)) {
            $playlistQuery->where(function (EloquentBuilder $query) use ($viewer) {
                $query->active();

                if ($viewer) {
                    $query->orWhere('user_id', $viewer->id);
                }
            });
        }

        $playlist = $playlistQuery->first();

        if (!$playlist) {
            return null;
        }

        $this->loadPlaylistVideos($playlist);

        return $playlist;
    }

    /** The most videos of a Liked videos queue on the watch page (the newest likes). */
    private const LIKED_QUEUE_LIMIT = 200;

    private function loadPlaylistVideos(Playlist $playlist): void
    {
        $viewer = Auth::user();

        // The viewer's own Liked videos: its videos are the viewer's likes (App\Playlists\Types\LikedVideosPlaylist),
        // never rows of the list, so its "Play all" had an empty queue. The queue is the likes of active videos, the
        // newest first, as the Liked videos page lists them (member design 6.11).
        if (
            $viewer !== null
            && (int) $playlist->user_id === (int) $viewer->id
            && ! $playlist->is_deletable
            && ($playlist->getAttributes()['title'] ?? null) === LikedVideosPlaylist::getName()
        ) {
            $ids = Interaction::query()
                ->where('user_id', $viewer->id)
                ->where('status', true)
                ->where('likeable_type', Video::class)
                ->whereHasMorph('likeable', [Video::class], fn (EloquentBuilder $video) => $video->active())
                ->latest('perform_at')
                ->latest('id')
                ->limit(self::LIKED_QUEUE_LIMIT)
                ->pluck('likeable_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $playlist->setAttribute('videos_count', count($ids));
            $this->playlistVideos = Video::query()
                ->whereKey($ids)
                ->with('user')
                ->get()
                ->sortBy(fn (Video $video) => array_search((int) $video->getKey(), $ids, true))
                ->values();
            $this->currentIndex = $this->playlistVideos->search(fn (Video $video) => $video->id === $this->video->id);

            return;
        }

        $videosQuery = $playlist->videos();
        $canViewAllPlaylistVideos = AdminIpLock::adminPowersHere($viewer)
            || ($viewer !== null && $viewer->id === $playlist->user_id);

        if (! $canViewAllPlaylistVideos) {
            $videosQuery
                ->active()
                ->forLocale();
        }

        $playlist->setAttribute('videos_count', (clone $videosQuery)->count());

        $this->playlistVideos = $videosQuery
            ->with('user')
            ->get();

        // if index not found search return false but is transformed to 0 because currentIndex is int
        $this->currentIndex = $this->playlistVideos
            ->search(fn(Video $video) => $video->id === $this->video->id);
    }
}
