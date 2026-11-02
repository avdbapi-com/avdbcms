<?php

namespace App\Http\Controllers\User;

use App\Enums\CustomPlaylistType;
use App\Http\Controllers\Controller;
use App\Models\Interaction;
use App\Models\Playlist;
use App\Playlists\PlaylistManager;
use App\Playlists\Types\WatchLaterPlaylist;
use App\Models\Video;
use App\Support\LinkProof;
use App\Support\LocalNumber;
use App\Support\WatchHistory;
use App\Support\WatchLaterState;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CustomPlaylistController extends Controller
{
    /** Rows of Watch later / Liked videos per page ("Show more" adds the next ones). */
    public const ROWS_PER_PAGE = 30;

    /**
     * Rows the page prints itself (R1-069): 8, as History (App\Support\WatchHistory::FIRST_PAGE), so the page content
     * stays within its budget (member design 4.3: 16 KB / 3 KB gzip / 250 nodes; 30 rows were about 50 KB). "Show more"
     * then adds ROWS_PER_PAGE at a time, after the rows on screen.
     */
    public const FIRST_PAGE = 8;

    /** How long "Undo" can bring back the videos "Remove watched" took out of Watch later. */
    public const UNDO_MINUTES = 10;

    /** The session key of the last "Remove watched" (its rows, for Undo). */
    public const UNDO_KEY = 'watch_later_removed';

    /**
     * Watch later and Liked videos (member design 6.11, QA channel-6, channel-27): a compact head with the count,
     * Play all and (Watch later) Remove watched; the rows are part of the page (no inner scroll box). The title is the
     * list's translated name (1.2.2 printed the stored "Liked Videos", which no language file has). A GET with
     * ?part=rows&page=N and Accept: application/json answers the next rows ({html, next_page}).
     */
    public function show(Request $request, CustomPlaylistType $type): View|JsonResponse
    {
        $playlist = PlaylistManager::get($type)->getPlaylist();
        $rowsPart = $request->query('part') === 'rows' && $request->expectsJson();
        // the page prints its first FIRST_PAGE rows (R1-069); the rows parts come ROWS_PER_PAGE at a time after them
        $page = $rowsPart ? max(1, (int) $request->query('page', 1)) : 1;
        $perPage = $rowsPart ? self::ROWS_PER_PAGE : self::FIRST_PAGE;
        // "Show more" sends the number of rows on screen (?shown=): the next rows follow them, also after the member took
        // rows out of Watch later on the page (a page number alone would then skip as many rows); a page number alone
        // counts the rows of the pages before it as on screen
        $shown = $rowsPart && is_numeric($request->query('shown')) ? max(0, (int) $request->query('shown')) : null;
        if ($rowsPart && $shown === null && $page > 1) {
            $shown = self::FIRST_PAGE + ($page - 2) * self::ROWS_PER_PAGE;
        }
        $videos = $type === CustomPlaylistType::WATCH_LATER ? $this->watchLaterPage($playlist, $page, $shown, $perPage) : $this->likedPage($page, $shown, $perPage);

        $isWatchLater = $type === CustomPlaylistType::WATCH_LATER;
        // the rows of Liked videos print "Save to" or "Remove from Watch later" as it is (QA: the item always said Save)
        $watchLaterIds = $isWatchLater ? [] : WatchLaterState::ids($videos->getCollection());

        if ($rowsPart) {
            return response()->json([
                'html' => view('library.playlist-rows', [
                    'videos' => $videos->getCollection(),
                    'playlist' => $playlist,
                    'context' => $type->value,
                    // the list ad keeps its place in the whole list (after row N): in this part when row N is in it and
                    // the page shows no ad yet (?ad=shown)
                    'adAfter' => $request->query('ad') === 'shown' ? 0 : \App\Support\FeedAdSlots::adEvery(config('site.ad_grid_position', 6)),
                    'rowsBefore' => (int) ($shown ?? 0),
                    'listRows' => (int) $videos->total(),
                    'watchLaterIds' => $watchLaterIds,
                ])->render(),
                'next_page' => $videos->hasMorePages() ? $videos->currentPage() + 1 : null,
            ]);
        }

        $total = (int) $videos->total();
        $watchedCount = $isWatchLater ? $this->watchedQuery($playlist)->count() : 0;

        return view('playlists.custom', [
            'type' => $type,
            'playlist' => $playlist,
            'videos' => $videos,
            'title' => $isWatchLater ? __('Watch later') : __('Liked videos'),
            'summary' => $isWatchLater
                ? self::watchLaterSummary($total, $this->totalSeconds($playlist))
                : trans_choice(':count video you liked. Private: only you see this list.|:count videos you liked. Private: only you see this list.', $total, ['count' => LocalNumber::short($total)]),
            'watchedCount' => $watchedCount,
            'watched' => $isWatchLater ? self::removeWatchedTexts($watchedCount, $total) : null,
            'watchLaterIds' => $watchLaterIds,
            'undo' => $isWatchLater ? $this->undoToken($request) : null,
        ]);
    }

    /**
     * The line under the Watch later title: "9 videos&nbsp;· 3 h 14 min. Private: only you see this list." ("2 h" when the
     * minutes are 0, "14 min" under an hour).
     */
    public static function watchLaterSummary(int $count, int $seconds): string
    {
        $videos = trans_choice('videos', $count, ['count' => LocalNumber::short($count)]);

        if ($seconds <= 0) {
            return __(':count. Private: only you see this list.', ['count' => $videos]);
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $length = match (true) {
            $hours > 0 && $minutes > 0 => __(':hours h :minutes min', ['hours' => $hours, 'minutes' => $minutes]),
            $hours > 0 => __(':hours h', ['hours' => $hours]),
            default => __(':minutes min', ['minutes' => max(1, $minutes)]),
        };

        // a no-break space before the dot (member design 7): a wrapped line never starts with it
        return __(":count\u{00A0}· :length. Private: only you see this list.", ['count' => $videos, 'length' => $length]);
    }

    /**
     * The "Remove watched videos?" question of Watch later for $watched of $total videos: its sentence and its button.
     *
     * @return array{text: string, confirm: string}
     */
    public static function removeWatchedTexts(int $watched, int $total): array
    {
        return [
            'text' => trans_choice(':count of the :total videos in Watch later is in your history. It leaves the list; you can undo it right after.|:count of the :total videos in Watch later are in your history. They leave the list; you can undo it right after.', $watched, ['count' => $watched, 'total' => $total]),
            'confirm' => trans_choice('Remove :count video|Remove :count videos', $watched, ['count' => $watched]),
        ];
    }

    /**
     * Remove watched videos from Watch later (QA channel-27: one click took out 4 of 5 videos with no question and no
     * way back). The page asks first (a dialog that names how many go); the rows that leave are kept in the session for
     * UNDO_MINUTES, and the same address with "undo" = the token the page shows puts them back where they were.
     */
    public function clearWatchedVideos(Request $request): RedirectResponse
    {
        $playlistManager = PlaylistManager::get(CustomPlaylistType::WATCH_LATER);
        /* @var WatchLaterPlaylist $playlistManager */
        $playlist = $playlistManager->getPlaylist();

        if ($request->filled('undo')) {
            return $this->undoClearWatched($request, $playlist);
        }

        $rows = DB::table('playlist_has_videos')
            ->where('playlist_id', $playlist->getKey())
            ->whereIn('video_id', $this->watchedQuery($playlist)->select('videos.id'))
            ->get(['video_id', 'position', 'added_at']);

        if ($rows->isEmpty()) {
            return redirect()->back()->with('info', __('No video in Watch later is in your history yet.'));
        }

        $playlist->videos()->detach($rows->pluck('video_id')->all());
        $playlist->touch();

        $token = Str::random(24);
        $request->session()->put(self::UNDO_KEY, [
            'token' => $token,
            'at' => now()->getTimestamp(),
            'rows' => $rows->map(fn ($row) => [
                'video_id' => (int) $row->video_id,
                'position' => (int) $row->position,
                'added_at' => (string) $row->added_at,
            ])->values()->all(),
        ]);

        return redirect()->back()->with('success', trans_choice(':count watched video left Watch later.|:count watched videos left Watch later.', $rows->count(), ['count' => $rows->count()]));
    }

    /**
     * Save a video to the member's Watch later or take it out.
     *
     * - With "state" = "on" or "off" (the rows of the library pages since 1.2.3): the video ends up in the list or not,
     *   whatever the page showed before; a second "Remove" (a row left on screen in another tab) can no longer put it
     *   back (QA). The answer says what is true now (status added/removed).
     * - Without it (the watch page, the site's cards, the mobile app): the old toggle.
     * - With summary=1 (the Watch later page) the answer also carries the page's head line and its "Remove watched"
     *   question as they are after the change.
     */
    public function toggleWatchLater(Request $request, $videoId): JsonResponse
    {
        $video = Video::findOrFail($videoId);
        $state = $request->input('state');

        $playlistManager = PlaylistManager::get(CustomPlaylistType::WATCH_LATER);
        $playlist = $playlistManager->getPlaylist();

        $exists = $playlist->videos()->where('video_id', $video->id)->exists();
        $wanted = in_array($state, ['on', 'off'], true) ? $state === 'on' : ! $exists;

        // A video that is not in the member's Watch later and that the member may not watch (another member's private
        // video or draft, a banned one) is answered exactly like an id that does not exist, whether the request removes
        // or adds it (R1-168): "removed" (200) for such an id against 404 for a missing one, or "This video is private"
        // against the missing id's text, told which ids exist. A video already in the list can always be taken out.
        // Round 3 R2R-06: another member's UNLISTED video too, without proof that the member has its link (its page
        // opened in this browser, `video_uuid`, the member's own lists or history: App\Support\LinkProof; a browser route,
        // the apps save to Watch later through /api/playlists/save):
        // Watch later would have shown its link to a member who only tried numbers.
        $uuid = is_string($request->input('video_uuid')) ? $request->input('video_uuid') : null;

        if (! $exists && (Gate::denies('show', $video) || ! LinkProof::allowsVideo($video, $request->user(), $uuid))) {
            throw (new ModelNotFoundException())->setModel(Video::class, [$videoId]);
        }

        if (! $wanted) {
            if ($exists) {
                $playlist->videos()->detach($video->id);
                $playlist->touch();
            }

            return $this->watchLaterAnswer($request, $playlist, false);
        }

        // Adding needs a video the member may watch (a private one answers 404, as its page does). Removing never does:
        // a video that went private since it was saved can still be taken out.
        $this->authorize('show', $video);

        if (! $exists) {
            $lastPosition = (int) ($playlist->videos()->max('playlist_has_videos.position') ?? 0);
            $playlist->videos()->attach($video->id, [
                'position' => $lastPosition + 1,
                'added_at' => now(),
            ]);
            $playlist->touch();
        }

        return $this->watchLaterAnswer($request, $playlist, true);
    }

    /**
     * The answer of toggleWatchLater(): what is true now, the list's count, and (summary=1) the Watch later page's head.
     */
    private function watchLaterAnswer(Request $request, Playlist $playlist, bool $saved): JsonResponse
    {
        $count = $playlist->videos()->count();
        $answer = [
            'success' => true,
            'status' => $saved ? 'added' : 'removed',
            'saved' => $saved,
            'message' => $saved ? __('Saved to Watch later') : __('Removed from Watch later'),
            'count' => $count,
        ];

        if ($request->boolean('summary')) {
            $watched = $this->watchedQuery($playlist)->count();
            $answer['summary'] = self::watchLaterSummary($count, $this->totalSeconds($playlist));
            $answer['watched'] = $watched;
            $answer['watched_texts'] = self::removeWatchedTexts($watched, $count);
        }

        return response()->json($answer);
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

    /**
     * Put back the rows of the last "Remove watched" (only with its own token, within UNDO_MINUTES, never twice).
     */
    private function undoClearWatched(Request $request, Playlist $playlist): RedirectResponse
    {
        $saved = $request->session()->get(self::UNDO_KEY);
        $fresh = is_array($saved)
            && hash_equals((string) ($saved['token'] ?? ''), (string) $request->input('undo'))
            && now()->getTimestamp() - (int) ($saved['at'] ?? 0) <= self::UNDO_MINUTES * 60;

        if (! $fresh) {
            return redirect()->back()->with('warning', __('That can no longer be undone.'));
        }

        $request->session()->forget(self::UNDO_KEY);
        $present = $playlist->videos()->pluck('videos.id')->map(fn ($id) => (int) $id)->all();
        $restored = 0;

        foreach ((array) ($saved['rows'] ?? []) as $row) {
            $videoId = (int) ($row['video_id'] ?? 0);
            if ($videoId <= 0 || in_array($videoId, $present, true) || ! Video::query()->whereKey($videoId)->exists()) {
                continue;
            }

            $playlist->videos()->attach($videoId, [
                'position' => (int) ($row['position'] ?? 0),
                'added_at' => $row['added_at'] ?? now(),
            ]);
            $restored++;
        }

        $playlist->touch();

        return redirect()->back()->with('success', trans_choice(':count video is back in Watch later.|:count videos are back in Watch later.', $restored, ['count' => $restored]));
    }

    /**
     * The token of a "Remove watched" that can still be undone, or null.
     */
    private function undoToken(Request $request): ?string
    {
        $saved = $request->session()->get(self::UNDO_KEY);

        if (! is_array($saved) || now()->getTimestamp() - (int) ($saved['at'] ?? 0) > self::UNDO_MINUTES * 60) {
            return null;
        }

        return (string) ($saved['token'] ?? '') ?: null;
    }

    /**
     * Videos of Watch later the member has watched (a view in their history).
     */
    private function watchedQuery(Playlist $playlist): Builder
    {
        $userId = Auth::id();

        return Video::query()
            ->whereIn('videos.id', DB::table('playlist_has_videos')->select('video_id')->where('playlist_id', $playlist->getKey()))
            ->whereIn('videos.id', DB::table('views')->select('video_id')->where('user_id', $userId)->whereNull('deleted_at'));
    }

    private function watchLaterPage(Playlist $playlist, int $page, ?int $shown = null, int $perPage = self::ROWS_PER_PAGE): LengthAwarePaginator
    {
        // the channel, and what the owner's video link format prints in each row's link
        $query = $playlist->videos()->with(WatchHistory::videoRelations());
        $total = (clone $query)->count();
        $rows = $query
            ->orderBy('playlist_has_videos.position')
            ->orderBy('playlist_has_videos.id')
            ->skip($shown ?? ($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return self::rowsPaginator($rows, $total, $page, $shown, $perPage);
    }

    /**
     * A page of rows; with $shown (the rows on screen) "more pages" means rows after $shown + this page's rows.
     */
    public static function rowsPaginator(\Illuminate\Support\Collection $rows, int $total, int $page, ?int $shown = null, int $perPage = self::ROWS_PER_PAGE): LengthAwarePaginator
    {
        if ($shown !== null) {
            // the page number that has more pages exactly when rows follow these ones
            $page = $shown + $rows->count() < $total ? 1 : max(1, (int) ceil($total / $perPage));
        }

        // The page's address in a form that reaches the site (public/index.php took /index.php off it).
        return new LengthAwarePaginator($rows, $total, $perPage, $page, ['path' => \App\Support\CleanUrls::requestForm(request()->url())]);
    }

    /**
     * Liked videos: likes of active videos, the newest like first.
     */
    private function likedPage(int $page, ?int $shown = null, int $perPage = self::ROWS_PER_PAGE): LengthAwarePaginator
    {
        $likes = Interaction::query()
            ->where('user_id', Auth::id())
            ->where('status', true)
            ->where('likeable_type', Video::class)
            ->whereHasMorph('likeable', [Video::class], fn (Builder $video) => $video->active());

        $total = (clone $likes)->count();
        $ids = $likes->latest('perform_at')->latest('id')->skip($shown ?? ($page - 1) * $perPage)->take($perPage)->pluck('likeable_id')->all();
        $videos = Video::query()->whereKey($ids)->with(WatchHistory::videoRelations())->get()
            ->sortBy(fn (Video $video) => array_search($video->getKey(), $ids))
            ->values();

        return self::rowsPaginator($videos, $total, $page, $shown, $perPage);
    }

    /**
     * The running time of every video in the list, in seconds (the head's "3 h 14 min").
     */
    private function totalSeconds(Playlist $playlist): int
    {
        // the stored seconds (the Video accessor formats "2:51"); a value that is not a whole number counts as 0
        return (int) $playlist->videos()
            ->toBase()
            ->pluck('videos.duration')
            ->sum(fn ($seconds) => is_numeric($seconds) ? max(0, (int) $seconds) : 0);
    }
}
