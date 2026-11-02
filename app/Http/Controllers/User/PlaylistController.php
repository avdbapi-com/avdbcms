<?php

namespace App\Http\Controllers\User;

use App\Actions\Playlist\StorePlaylistAction;
use App\Actions\Playlist\UpdatePlaylistAction;
use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Playlist\StorePlaylistRequest;
use App\Http\Requests\Playlist\UpdatePlaylistRequest;
use App\Models\Playlist;
use App\Models\Video;
use App\Support\Studio\Dashboard;
use App\Support\Studio\StudioFormat;
use App\Support\Studio\StudioSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * The studio's Playlists list and editor (member design 6.7).
 */
class PlaylistController extends Controller
{
    /** The editor shows this many videos first ("Showing 8 of 12" + "Show 4 more"). */
    public const EDITOR_FIRST = 8;

    public function __construct()
    {
        $this->authorizeResource(Playlist::class, 'playlist');
    }

    public function index(Request $request): View|RedirectResponse {
        $filters = [];

        foreach (['search', 'status'] as $key) {
            $value = $request->query($key);

            if (is_string($value) && trim($value) !== '') {
                $filters[$key] = trim($value);
            }
        }

        $sort = is_string($request->query('sort')) ? $request->query('sort') : '';

        $query = Playlist::filter($filters === [] ? ['__none' => '1'] : $filters)
            ->where('user_id', Auth::id())
            ->where('is_deletable', true)
            ->withCount(['videos'])
            // the cover: the first video the member may show (one of theirs, or one anybody can watch), one query
            ->with(['videos' => fn ($videos) => $videos
                ->where(fn ($q) => $q->where('videos.user_id', Auth::id())->orWhere(fn ($active) => $active->active()))
                ->limit(1)]);

        match ($sort) {
            'title' => $query->orderBy('title')->orderBy('id'),
            'created' => $query->latest('created_at')->latest('id'),
            default => $query->latest('updated_at')->latest('id'),
        };

        $playlists = $query->paginate(StudioSettings::int('per_page_users', 15, 1, 100))->withQueryString();

        // A page past the end of the list (its last rows were deleted): its last page.
        if ($playlists->total() > 0 && $playlists->currentPage() > $playlists->lastPage()) {
            return redirect()->to($playlists->url($playlists->lastPage()));
        }

        return view('users.playlists.index', [
            'playlists' => $playlists,
            'total' => Playlist::query()->where('user_id', Auth::id())->where('is_deletable', true)->count(),
            'filtersActive' => $filters !== [] || $sort !== '',
            'status' => PlaylistStatus::get(),
        ]);
    }

    public function create(): View {
        // A create the server refused comes back with the videos the member had added (never lose work, 6.7).
        $old = session()->getOldInput();
        $items = is_array($old['videos'] ?? null)
            ? $this->restoredItems(null, array_values(array_filter(array_map('intval', $old['videos']), fn (int $id) => $id > 0)))
            : [];

        return view('users.playlists.create', [
            'status' => PlaylistStatus::get(),
            'sorts' => PlaylistSort::get(),
            'items' => $items,
            'loaded' => [],
        ]);
    }

    public function store(StorePlaylistRequest $request, StorePlaylistAction $storePlaylistAction): RedirectResponse {

        $storePlaylistAction->execute($request);
        Dashboard::forget(Auth::id());

        return redirect()->route('user.playlists.index')->with('success', __('Playlist created.'));
    }

    public function edit(Playlist $playlist): View|RedirectResponse {

        $items = $this->editorItems($playlist);
        $loaded = array_map(fn (array $item) => (int) $item['id'], $items);

        // A save the server refused (a field error) comes back with the member's list as it was when they pressed Save:
        // their order, removals and additions, and the list the page FIRST loaded (what the merge may remove), so
        // nothing they did is lost and the next save still keeps what was added elsewhere meanwhile (6.7, principle 7).
        $old = session()->getOldInput();

        if (array_key_exists('videos_order', $old)) {
            $loaded = UpdatePlaylistRequest::ids($old['videos_loaded'] ?? null);
            $items = $this->restoredItems($playlist, UpdatePlaylistRequest::ids($old['videos_order']), $items);
        }

        return view('users.playlists.edit', [
            'playlist' => $playlist,
            'status' => PlaylistStatus::get(),
            'sorts' => PlaylistSort::get(),
            'items' => $items,
            'loaded' => $loaded,
        ]);
    }

    public function update(UpdatePlaylistRequest $request, Playlist $playlist, UpdatePlaylistAction $updatePlaylistAction): RedirectResponse {

        $leftOut = $updatePlaylistAction->execute($request, $playlist);
        Dashboard::forget(Auth::id());

        $redirect = $request->string('action')->is('save')
            ? redirect()->to(route('user.playlists.edit', $playlist))->with('toast', __('Playlist saved.'))
            : redirect()->route('user.playlists.index')->with('success', __('Playlist saved.'));

        if ($leftOut > 0) {
            $redirect->with('info', trans_choice(':count video is not available any more and was left out of the playlist.|:count videos are not available any more and were left out of the playlist.', $leftOut, ['count' => StudioFormat::number($leftOut)]));
        }

        return $redirect;
    }

    public function destroy(Playlist $playlist): RedirectResponse {

        $title = (string) $playlist->title;
        $playlist->videos()->detach();
        $playlist->forceDelete();
        Dashboard::forget(Auth::id());

        return redirect()->route('user.playlists.index')->with('success', __('":title" was deleted.', ['title' => $title]));
    }

    public function favorite (Playlist $playlist) : Response {

        // the saved time is the application's clock (left to the column's default, the database server's zone dated it:
        // Saved playlists then read "Saved 5 hours from now"; the same fix as the subscriptions', QA studio-23), once the
        // update has noted the rows that clock dated before (App\Support\LegacyRowTimes)
        Auth::user()->favorites_playlist()->toggle([$playlist->id => \App\Support\LegacyRowTimes::stamp('favorites_playlist')]);
        return response()->noContent();
    }

    public function massDelete(Request $request): RedirectResponse {

        $request->validate([
            'ids' => 'required|array|max:500',
            'ids.*' => 'integer',
        ]);

        $playlists = Playlist::whereIn('id', $request->ids)
            ->where('user_id', Auth::id())
            ->where('is_deletable', true)
            ->get();

        $playlists->each(function ($playlist) {
            $playlist->videos()->detach();
            $playlist->forceDelete();
        });
        Dashboard::forget(Auth::id());

        return redirect()->route('user.playlists.index')->with('success', trans_choice('Deleted 1 playlist.|Deleted :count playlists.', $playlists->count(), ['count' => StudioFormat::number($playlists->count())]));
    }

    /**
     * Every video of the playlist, in the order the playlist shows them (its "Order" setting), with what the editor
     * prints (member design 6.7, studio-4, studio-22): the editor gets the WHOLE list at once, so saving always sends
     * every id in order, also the ones further down the list (nothing is ever lost because a page was not loaded), it
     * knows which videos are already in (search results say "Added", nothing is added twice: studio-22c) and its count
     * comes from the server (studio-22d). Another member's video that went private since it was added is listed as
     * private, without its title or picture.
     *
     * @return list<array<string, mixed>>
     */
    private function editorItems(Playlist $playlist): array
    {
        $sort = $playlist->sort instanceof PlaylistSort ? $playlist->sort : (PlaylistSort::tryFrom((int) $playlist->sort) ?? PlaylistSort::MANUAL);

        $videos = $playlist->videos()
            ->reorder()
            ->orderBy($sort->getSortedColumn(), $sort->getSortedDirection())
            ->orderBy('playlist_has_videos.id')
            ->get();

        return $videos->map(fn (Video $video) => $this->editorItem($video))->values()->all();
    }

    /**
     * The editor's list rebuilt from a refused save: the ids in the member's order, the playlist's own rows for the
     * videos it holds and, for the videos the member had added, the ones they may still add (one anybody can watch or
     * one of theirs); anything else is left out.
     *
     * @param  list<int>  $order
     * @param  list<array<string, mixed>>  $heldItems
     * @return list<array<string, mixed>>
     */
    private function restoredItems(?Playlist $playlist, array $order, array $heldItems = []): array
    {
        $byId = [];

        foreach ($heldItems as $item) {
            $byId[(int) $item['id']] = $item;
        }

        $missing = array_values(array_filter($order, fn (int $id) => ! isset($byId[$id])));

        foreach (array_chunk($missing, 1000) as $chunk) {
            Video::query()
                ->whereIn('videos.id', $chunk)
                ->where(fn ($query) => $query->active()->orWhere('videos.user_id', Auth::id()))
                ->get()
                ->each(function (Video $video) use (&$byId): void {
                    $byId[(int) $video->id] = $this->editorItem($video, now()->getTimestamp());
                });
        }

        $items = [];

        foreach ($order as $id) {
            if (isset($byId[$id])) {
                $items[] = $byId[$id];
            }
        }

        return $items;
    }

    /**
     * One row of the editor. The times are Unix seconds (1.2.3 round 1, R1-022: the editor's compact rows; the date
     * added was a string without a zone that WebKit could not read, so "Date added" sorted nothing there).
     *
     * @return array<string, mixed>
     */
    private function editorItem(Video $video, ?int $added = null): array
    {
        $visible = (int) $video->user_id === (int) Auth::id() || $video->is_public;

        return [
            'id' => (int) $video->id,
            'title' => $visible ? (string) $video->title : '',
            'thumb' => $visible ? (string) $video->thumbnail_url : '',
            'dur' => $visible ? (string) $video->duration : '',
            'views' => $visible ? trans_choice(':count view|:count views', (int) $video->views, ['count' => StudioFormat::number((int) $video->views)]) : '',
            'private' => ! $visible,
            'added' => $added ?? ($video->pivot?->added_at ? Carbon::parse($video->pivot->added_at)->getTimestamp() : 0),
            'published' => $video->published_at?->getTimestamp() ?? 0,
            'viewsCount' => (int) $video->views,
        ];
    }
}
