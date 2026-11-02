<?php

namespace App\Http\Controllers;

use App\Enums\PlaylistStatus;
use App\Models\Interaction;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Playlists\Types\LikedVideosPlaylist;
use App\Playlists\Types\WatchLaterPlaylist;
use App\Support\WatchHistory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The member's library page /library (route pages.library; member design 6.11, OD-M5): one shelf each for History,
 * Watch later, Liked videos, the member's own playlists, the playlists they saved (channel-29, R1-034) and the channels
 * they follow, each with its count and "See all".
 *
 * A shelf holds exactly one row of cards: the shelf counts its own columns (2 to 6 by its width) and hides the cards past
 * the last column, so the server sends SHELF_ITEMS, the most a 1280px page shows. A member
 * with fewer items simply gets a shorter row. Nothing here creates a record: a system list that does not exist yet
 * (Watch later, Liked videos) is an empty shelf.
 */
class LibraryController extends Controller
{
    /** Cards per shelf (the widest shelf shows six columns). */
    public const SHELF_ITEMS = 6;

    public function index(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $showHistory = (bool) config('site.show_history', true);

        $history = $showHistory ? $this->history($user) : ['count' => 0, 'videos' => collect()];
        $watchLater = $this->watchLater($user);
        $liked = $this->liked($user);
        $playlists = $this->playlists($user);
        $saved = $this->savedPlaylists($user);
        $subscriptions = $this->subscriptions($user);

        return view('pages.library', [
            'showHistory' => $showHistory,
            'history' => $history,
            'watchLater' => $watchLater,
            'liked' => $liked,
            'playlists' => $playlists,
            'saved' => $saved,
            'subscriptions' => $subscriptions,
            'isEmpty' => $history['count'] + $watchLater['count'] + $liked['count'] + $playlists['count'] + $saved['count'] + $subscriptions['count'] === 0,
        ]);
    }

    /**
     * The last videos watched (each video once, the most recent view first) and the number of history rows.
     *
     * @return array{count: int, videos: Collection<int, Video>}
     */
    private function history(User $user): array
    {
        $rows = WatchHistory::query($user)->limit(self::SHELF_ITEMS * 3)->get();

        return [
            'count' => WatchHistory::count($user),
            'videos' => $rows->pluck('video')->filter()->unique('id')->take(self::SHELF_ITEMS)->values(),
        ];
    }

    /**
     * @return array{count: int, videos: Collection<int, Video>}
     */
    private function watchLater(User $user): array
    {
        $playlist = $this->systemPlaylist($user, WatchLaterPlaylist::getName());

        if (! $playlist) {
            return ['count' => 0, 'videos' => collect()];
        }

        return [
            'count' => $playlist->videos()->count(),
            'videos' => $playlist->videos()->public(true)->with(WatchHistory::videoRelations())->limit(self::SHELF_ITEMS)->get(),
        ];
    }

    /**
     * Liked videos, as the Liked videos page lists them (likes of active videos, the newest like first).
     *
     * @return array{count: int, videos: Collection<int, Video>}
     */
    private function liked(User $user): array
    {
        $likes = Interaction::query()
            ->where('user_id', $user->getKey())
            ->where('status', true)
            ->where('likeable_type', Video::class)
            ->whereHasMorph('likeable', [Video::class], fn (Builder $video) => $video->active());

        $ids = (clone $likes)->latest('perform_at')->latest('id')->limit(self::SHELF_ITEMS)->pluck('likeable_id')->all();
        $videos = Video::query()->whereKey($ids)->with(WatchHistory::videoRelations())->get()->sortBy(fn (Video $video) => array_search($video->getKey(), $ids))->values();

        return ['count' => $likes->count(), 'videos' => $videos];
    }

    /**
     * The member's own playlists (not the system lists Watch later and Liked videos), the last updated first.
     *
     * @return array{count: int, items: Collection<int, Playlist>}
     */
    private function playlists(User $user): array
    {
        $query = $user->playlists()->where('is_deletable', true);

        return [
            'count' => (clone $query)->count(),
            'items' => (clone $query)
                ->withCount('videos')
                // each card's cover: its first video in one query for the shelf (QA: one query per card)
                ->with(['videos' => fn ($videos) => $videos->active()->forLocale()->limit(1)])
                ->latest('updated_at')
                ->latest('id')
                ->limit(self::SHELF_ITEMS)
                ->get(),
        ];
    }

    /**
     * The playlists of other channels the member saved, as the Saved playlists page lists them
     * (PlaylistController::manage): only those that open (public or unlisted: one its channel made private since it was
     * saved would answer 404), the last saved first.
     *
     * @return array{count: int, items: Collection<int, Playlist>}
     */
    private function savedPlaylists(User $user): array
    {
        $query = $user->favorites_playlist()->whereIn('playlists.status', [PlaylistStatus::PUBLIC->value, PlaylistStatus::UNLISTED->value]);

        return [
            'count' => (clone $query)->count(),
            'items' => (clone $query)
                ->withCount(['videos' => fn ($videos) => $videos->active()->forLocale()])
                // each card's cover: its first video, in one query for the shelf; and its channel's name (R2A-031)
                ->with(['videos' => fn ($videos) => $videos->active()->forLocale()->limit(1), 'user'])
                ->latest('added_at')
                ->orderByDesc('playlists.id')
                ->limit(self::SHELF_ITEMS)
                ->get(),
        ];
    }

    /**
     * The channels the member follows, the latest first (chips): SHELF_ITEMS of them like every other shelf (member
     * design 6.11; "Manage" lists them all). Twelve wrapped onto a second row on wide screens and six rows on a phone,
     * and took the page past its content budget (4.3: 16 KB / 250 nodes, R1-069).
     *
     * @return array{count: int, channels: Collection<int, User>}
     */
    private function subscriptions(User $user): array
    {
        return [
            'count' => $user->subscriptions()->count(),
            'channels' => $user->subscriptions()
                ->latest('subscriptions.subscribe_at')
                ->latest('users.id')
                ->limit(self::SHELF_ITEMS)
                ->get(),
        ];
    }

    private function systemPlaylist(User $user, string $title): ?Playlist
    {
        return Playlist::query()
            ->where('user_id', $user->getKey())
            ->where('title', $title)
            ->where('is_deletable', false)
            ->first();
    }
}
