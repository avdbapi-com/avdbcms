<?php

namespace App\Http\Controllers;

use App\Enums\PlaylistStatus;
use App\Models\Playlist;
use App\Support\AdminIpLock;
use App\Support\LinkProof;
use App\Support\WatchHistory;
use App\Support\WatchLaterState;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class PlaylistController
{
    /** Rows of a playlist page; "Show more" adds the next ones (QA channel-26: every video loaded at once). */
    public const ROWS_PER_PAGE = 30;

    /**
     * Rows the page prints itself (R1-069): 8, as History and Watch later, so the page stays light (member design 4.3:
     * the 30 rows of a long playlist were about 48 KB of the page); "Show more" adds ROWS_PER_PAGE after the rows on screen.
     */
    public const FIRST_PAGE = 8;

    /** Saved playlists per page of /playlists/manage. */
    public const SAVED_PER_PAGE = 24;

    /**
     * A playlist page /playlist/{uuid} (member design 6.11, QA channel-26): the head (cover, title, owner, count,
     * visibility, Play all, Save, Share, the owner's Edit) and its videos in order, a page at a time. A GET of the same
     * address with ?part=rows&page=N and an Accept: application/json header answers the next rows ({html, next_page}).
     */
    public function show (Request $request, Playlist $playlist) : View|JsonResponse {
        $viewer = Auth::user();
        // Another member's unlisted playlist opened by its link: this browser may save it (round 3 R2R-06, LinkProof).
        LinkProof::rememberPlaylist($playlist);
        // the channel, and what the owner's video link format prints in each row's link
        $videosQuery = $playlist->videos()
            ->with(WatchHistory::videoRelations());

        // Keep the owner's private playlist view intact. Public visitors should
        // receive the same active, locale-visible set used by playlist metrics
        // and public playlist cards.
        if (! AdminIpLock::adminPowersHere($viewer) && $viewer?->id !== $playlist->user_id) {
            $videosQuery
                ->active()
                ->forLocale();
        }

        // the page prints its first FIRST_PAGE rows (R1-069); "Show more" asks for the rows after those on screen
        // (?shown=, as Watch later), ROWS_PER_PAGE at a time; a page number alone counts the pages before it as on screen
        $rowsPart = $request->query('part') === 'rows' && $request->expectsJson();
        $page = $rowsPart ? max(1, (int) $request->query('page', 1)) : 1;
        $shown = $rowsPart && is_numeric($request->query('shown')) ? max(0, (int) $request->query('shown')) : null;
        if ($rowsPart && $shown === null && $page > 1) {
            $shown = self::FIRST_PAGE + ($page - 2) * self::ROWS_PER_PAGE;
        }
        $videos = $this->rowsPage($videosQuery, $page, $shown, $rowsPart ? self::ROWS_PER_PAGE : self::FIRST_PAGE);

        if ($rowsPart) {
            return response()->json([
                'html' => view('library.playlist-rows', [
                    'videos' => $videos->getCollection(),
                    'playlist' => $playlist,
                    'context' => 'playlist',
                    // the list ad keeps its place in the whole list (after row N), in this part when row N is in it and
                    // the page shows no ad yet (?ad=shown)
                    'adAfter' => $request->query('ad') === 'shown' ? 0 : \App\Support\FeedAdSlots::adEvery(config('site.ad_grid_position', 6)),
                    'rowsBefore' => (int) ($shown ?? 0),
                    'listRows' => (int) $videos->total(),
                    'watchLaterIds' => WatchLaterState::ids($videos->getCollection()),
                ])->render(),
                'next_page' => $videos->hasMorePages() ? $videos->currentPage() + 1 : null,
            ]);
        }

        return view('playlists.show', [
            'playlist' => $playlist,
            'videos' => $videos,
            'favorite_by_auth_user' => Auth::user()?->favorites_playlist()->where('playlist_id', $playlist->id)->exists(),
            'watchLaterIds' => WatchLaterState::ids($videos->getCollection()),
        ]);
    }

    /**
     * Saved playlists /playlists/manage (QA channel-28): playlists of other channels the member saved, a page at a time.
     */
    public function manage (): View {
        return view('playlists.manage', [
            'playlists' => Auth::user()->favorites_playlist()
                // a playlist its channel made private since it was saved would only open a 404
                ->whereIn('playlists.status', [PlaylistStatus::PUBLIC->value, PlaylistStatus::UNLISTED->value])
                ->with([
                    'user',
                    'videos' => fn ($query) => $query->active()->forLocale()->limit(1),
                ])
                ->withCount([
                    'videos' => fn ($query) => $query->active()->forLocale(),
                ])
                ->latest('added_at')
                ->orderByDesc('playlists.id')
                ->paginate(self::SAVED_PER_PAGE)
                ->withQueryString(),
        ]);
    }

    /**
     * One page of a playlist's rows in the playlist's own order: $perPage rows after the $shown rows on screen (or after the
     * pages before $page); with $shown, "more pages" means rows after them and this page's.
     */
    private function rowsPage(BelongsToMany $query, int $page, ?int $shown = null, int $perPage = self::ROWS_PER_PAGE): LengthAwarePaginator
    {
        $total = (clone $query)->count();
        $rows = (clone $query)
            ->orderBy('playlist_has_videos.position')
            ->orderBy('playlist_has_videos.id')
            ->skip($shown ?? ($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        if ($shown !== null) {
            // the page number that has more pages exactly when rows follow these ones
            $page = $shown + $rows->count() < $total ? 1 : max(1, (int) ceil($total / $perPage));
        }

        return new LengthAwarePaginator($rows, $total, $perPage, $page, [
            // The page's address in a form that reaches the site (public/index.php took /index.php off it).
            'path' => \App\Support\CleanUrls::requestForm(request()->url()),
            'pageName' => 'page',
        ]);
    }
}
