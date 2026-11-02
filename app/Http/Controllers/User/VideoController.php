<?php

namespace App\Http\Controllers\User;

use App\Actions\Video\StoreVideoAction;
use App\Actions\Video\UpdateVideoAction;
use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Video\StoreVideoRequest;
use App\Http\Requests\Video\UpdateVideoRequest;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Playlist;
use App\Models\Video;
use App\Services\SeoService;
use App\Support\Studio\Dashboard;
use App\Support\Studio\StudioChrome;
use App\Support\Studio\StudioFormat;
use App\Support\Studio\StudioSettings;
use App\Support\Studio\ViewsSeries;
use App\Support\VideoPublishWords;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The studio's video pages (member design 6.3-6.6): My videos (tabs, filters, bulk visibility and delete),
 * the delete dialogs, Analytics, Video details and the draft page.
 */
class VideoController extends Controller
{
    /** The tabs of My videos: label => status filter value (null = All). Banned shows only when there is one. */
    public const TABS = [
        'all' => null,
        'public' => VideoStatus::PUBLIC,
        'scheduled' => VideoStatus::PLANNED,
        'unlisted' => VideoStatus::UNLISTED,
        'private' => VideoStatus::PRIVATE,
        'drafts' => VideoStatus::DRAFT,
        'failed' => VideoStatus::FAILED,
        'banned' => VideoStatus::BANNED,
    ];

    /** The visibilities the bulk menu, the visibility sheet and "Make private instead" may set. */
    public const BULK_VISIBILITIES = [
        'public' => VideoStatus::PUBLIC,
        'unlisted' => VideoStatus::UNLISTED,
        'private' => VideoStatus::PRIVATE,
    ];

    public function __construct()
    {
        $this->authorizeResource(Video::class, 'video');
    }

    public function index(Request $request): View|RedirectResponse
    {
        $userId = (int) Auth::id();
        $perPage = max(1, min(100, (int) config('site.per_page_videos', 24)));

        $videos = Video::filter($this->videoFilters($request))
            ->where('videos.user_id', $userId)
            ->with(['category'])
            // the row counts the comments viewers see (one a moderator hid is not counted: 1.2.3 round 1, R1-165), the
            // delete dialog every comment the video takes with it (comments_all_count)
            ->withCount([
                'comments' => fn (Builder $query) => $query->whereNull('comments.banned_at'),
                'comments as comments_all_count',
                'likes',
                'dislikes',
                'playlists',
            ])
            ->tap(fn (Builder $query) => $this->applySort($query, is_string($request->query('sort')) ? $request->query('sort') : ''))
            ->paginate($perPage)
            ->withQueryString();

        // A page past the end of the list (its last rows were deleted, the member is sent back to that page number): the
        // list's last page, with the same filters, instead of "No videos match these filters".
        if ($videos->total() > 0 && $videos->currentPage() > $videos->lastPage()) {
            return redirect()->to($videos->url($videos->lastPage()));
        }

        // Every row is the member's own video: it gets its owner without a query (the thumbnail and the links read it).
        $videos->getCollection()->each(fn (Video $video) => $video->setRelation('user', Auth::user()));

        $categoriesCount = Category::query()->count();

        return view('users.videos.index', [
            'videos' => $videos,
            'tabs' => $this->tabCounts($userId),
            'currentTab' => $this->currentTab($request),
            // 1.2.3: no category named with a word that is always blocked (App\Support\ContentSafety)
            'categories' => \App\Support\ContentSafety::visibleCategories(Category::query()->orderBy('title')->get(['id', 'title'])),
            'categoriesNative' => $categoriesCount <= 30,
            'filtersActive' => $this->filtersActive($request),
            'uploadsAllowed' => StudioSettings::on('allow_uploads', true),
        ]);
    }

    public function create(Video $video): View|RedirectResponse
    {
        // Refresh video data to get real uploaded_at
        $video->refresh();

        if (! $video->is_draft) {
            return redirect()->route('user.videos.edit', $video);
        }

        return view('users.videos.create', $this->formData($video));
    }

    public function store(StoreVideoRequest $request, Video $video, StoreVideoAction $storeVideoAction): RedirectResponse
    {
        // 1.2.3 round 3 (KS-F1): the draft's category is one visitors see or its own, as on Video details: a category
        // visitors never see answers like one that does not exist (the upload's own request is not changed)
        ValidatorFacade::make($request->only('category_id'), ['category_id' => ['nullable', VideoPublishWords::categoryRule($video)]])->validate();

        // "Save draft" (6.4): the details are kept, the video stays a draft (not public, no date); the visibility and the
        // playlists apply when it is published. "Finish later" with typed changes saves the same way and goes back to
        // My videos (1.2.3 round 1, R1-024: it left without saving them).
        $action = (string) $request->string('action');

        if (in_array($action, ['draft', 'later'], true)) {
            // 1.2.3 round 3 (KS-F3): the route lets the owner save any video of theirs here (a draft page left open
            // after the video went out, a hand-made request): one that is public, unlisted or scheduled takes no title or
            // category that use a word that is always blocked, as on Video details; a draft keeps any title until it is
            // published (no status is given, so the video's own counts)
            VideoPublishWords::check($video, $request->only(['title', 'category_id']));

            $video->update(array_merge(
                $request->safe()->only(['title', 'description', 'category_id', 'language', 'allow_comments', 'default_comments_sort', 'show_likes', 'show_ad']),
                ['slug' => $request->string('title')->slug()],
            ));
            \App\Services\ThumbnailService::save($request);
            Dashboard::forget(Auth::id());

            return ($action === 'later' ? redirect()->route('user.videos.index') : redirect()->route('user.videos.create', $video))
                ->with('toast', __('Draft saved.'));
        }

        // 1.2.3 round 3 (KS-F3): Publish (public, unlisted or scheduled) never puts a title, tags or category that use a
        // word that is always blocked in front of visitors (App\Support\VideoPublishWords); the draft stays as it was
        VideoPublishWords::check($video, $request->only(['status', 'title', 'category_id']));

        $storeVideoAction->execute($request, $video);
        Dashboard::forget(Auth::id());

        $status = VideoStatus::tryFrom($request->integer('status'));

        return redirect()
            ->route('user.videos.index')
            ->with('success', match ($status) {
                VideoStatus::PUBLIC => __('Your video is published.'),
                VideoStatus::PLANNED => __('Your video is scheduled.'),
                default => __('Your video is saved.'),
            });
    }

    public function show(Request $request, Video $video): View
    {
        // the comments viewers see (1.2.3 round 1, R1-165: a comment a moderator hid is not counted or listed here)
        $video->loadCount(['likes', 'dislikes', 'comments' => fn ($query) => $query->whereNull('comments.banned_at')]);
        // a hand-made address can carry anything (?period[]=7 answered 500)
        $period = is_string($request->query('period')) ? $request->query('period') : ViewsSeries::DEFAULT_PERIOD;
        $dailyStatsEnabled = StudioSettings::on('view_daily_stats_enabled', true);

        [$last7, $previous7] = $dailyStatsEnabled ? ViewsSeries::lastDays($video, 7) : [0, 0];

        return view('users.videos.show', [
            'video' => $video,
            'series' => $dailyStatsEnabled ? ViewsSeries::build($video, $period) : null,
            'period' => in_array($period, ViewsSeries::PERIODS, true) ? $period : ViewsSeries::DEFAULT_PERIOD,
            'dailyStatsEnabled' => $dailyStatsEnabled,
            'last7' => $last7,
            'previous7' => $previous7,
            'latestComments' => Comment::query()
                ->where('video_id', $video->id)
                ->whereNull('parent_id')
                ->whereNull('banned_at')
                ->with('user')
                ->latest('comments.created_at')
                ->limit(2)
                ->get(),
        ]);
    }

    public function edit(Video $video): View|RedirectResponse
    {
        if ($video->is_draft) {
            return redirect()->route('user.videos.create', $video);
        }

        return view('users.videos.edit', $this->formData($video));
    }

    public function update(UpdateVideoRequest $request, Video $video, UpdateVideoAction $updateVideoAction): RedirectResponse
    {
        $updateVideoAction->execute($request, $video);
        Dashboard::forget(Auth::id());

        // "Video saved" is a toast (6.4): Save stays on the page, Save and close goes back to My videos.
        if ($request->string('action')->is('save')) {
            return redirect()->to(route('user.videos.edit', $video))->with('toast', __('Video saved'));
        }

        return redirect()->route('user.videos.index')->with('toast', __('Video saved'));
    }

    public function destroy(Video $video): RedirectResponse
    {
        $title = (string) $video->title;
        $video->forceDelete();
        Dashboard::forget(Auth::id());

        return redirect()
            ->route('user.videos.index', $this->listQuery())
            ->with('success', __('":title" was deleted.', ['title' => $title]));
    }

    public function pin(Video $video): RedirectResponse
    {
        $video->user->update([
            'pinned_video_id' => $video->id,
        ]);

        return redirect()->back()->with('toast', __('Pinned to your channel.'));
    }

    public function unpin(Video $video): RedirectResponse
    {
        $video->user->update([
            'pinned_video_id' => null,
        ]);

        return redirect()->back()->with('toast', __('Unpinned from your channel.'));
    }

    public function massDelete(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array|max:500',
            'ids.*' => 'integer',
        ]);

        $videos = Auth::user()->videos()->whereIn('videos.id', $request->array('ids'))->get();
        $count = $videos->count();

        $videos->each(fn (Video $video) => $video->forceDelete());
        Dashboard::forget(Auth::id());

        return redirect()
            ->route('user.videos.index', $this->listQuery())
            ->with('success', trans_choice('Deleted :count video.|Deleted :count videos.', $count, ['count' => StudioFormat::number($count)]));
    }

    /**
     * Bulk visibility of My videos (member design 6.3), also "Make private instead" in the delete dialogs: the
     * member's own videos only; banned, failed and draft videos (and uploads still being processed) keep their state.
     */
    public function massVisibility(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|max:500',
            'ids.*' => 'integer',
            'visibility' => ['required', 'string', Rule::in(array_keys(self::BULK_VISIBILITIES))],
        ]);

        $target = self::BULK_VISIBILITIES[$data['visibility']];
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));

        $videos = Video::query()
            ->where('user_id', Auth::id())
            ->whereIn('id', $ids)
            ->with(['category:id,title', 'tags:id,name'])
            ->get();

        $changeable = $videos->filter(fn (Video $video): bool => ! in_array($video->status, [VideoStatus::BANNED, VideoStatus::FAILED, VideoStatus::DRAFT], true)
            && $video->uploaded_at !== null);
        $kept = $videos->count() - $changeable->count();

        // 1.2.3 round 3 (KS-F3): a video whose title, tags or category use a word that is always blocked does not go out
        // to visitors; it keeps its visibility (App\Support\VideoPublishWords)
        $refused = 0;

        if (in_array($target, VideoPublishWords::OUT, true)) {
            $changeable = $changeable->reject(function (Video $video) use ($target, &$refused): bool {
                $no = VideoPublishWords::refusals($video, ['status' => $target]) !== [];
                $refused += (int) $no;

                return $no;
            });
        }

        DB::transaction(function () use ($changeable, $target): void {
            foreach ($changeable as $video) {
                $attributes = ['status' => $target, 'scheduled_at' => null];

                // The publication date is the first day the video went public; it is set once and kept after that.
                if ($target === VideoStatus::PUBLIC && ($video->published_at === null || $video->published_at->isFuture())) {
                    $attributes['published_at'] = now();
                }

                if ($target !== VideoStatus::PUBLIC && $video->published_at !== null && $video->published_at->isFuture()) {
                    $attributes['published_at'] = null;
                }

                $video->forceFill($attributes)->save();
            }
        });

        Dashboard::forget(Auth::id());

        if ($target === VideoStatus::PUBLIC && $changeable->isNotEmpty()) {
            $this->announceToIndexNow($changeable->modelKeys());
        }

        $changed = $changeable->count();
        $message = match ($target) {
            VideoStatus::PUBLIC => trans_choice(':count video is public now.|:count videos are public now.', $changed, ['count' => StudioFormat::number($changed)]),
            VideoStatus::UNLISTED => trans_choice(':count video is unlisted now.|:count videos are unlisted now.', $changed, ['count' => StudioFormat::number($changed)]),
            default => trans_choice(':count video is private now.|:count videos are private now.', $changed, ['count' => StudioFormat::number($changed)]),
        };

        if ($kept > 0) {
            $message .= ' '.trans_choice(':count draft or failed upload kept its state.|:count drafts or failed uploads kept their state.', $kept, ['count' => StudioFormat::number($kept)]);
        }

        if ($refused > 0) {
            $message .= ' '.trans_choice(':count video kept its visibility: its title, tags or category use words that are not allowed on this site.|:count videos kept their visibility: their titles, tags or categories use words that are not allowed on this site.', $refused, ['count' => StudioFormat::number($refused)]);
        }

        return redirect()
            ->route('user.videos.index', $this->listQuery())
            ->with($changed > 0 ? 'success' : 'info', $message);
    }

    /**
     * The videos a bulk "Make public" made public, announced to IndexNow (the admin's switch, off by default) in ONE call
     * with their addresses (IndexNow takes a list), after the answer has gone to the member (a terminating callback, as
     * NotificationTasks does: PHP-FPM sends the page first; this app's kernel has no deferred-callback middleware, so
     * Laravel's defer() would never run): before, each video was its own blocking call of up to 8 seconds inside the
     * request, so a page of 24-100 videos could run out of time and show an error page although the change was saved.
     * The videos are read again with their owner and category in one query (the address may name them), and only the
     * ones live now are announced (as submitVideoToIndexNow does). It runs once, also in a process that serves several
     * requests.
     *
     * @param  list<int|string>  $ids
     */
    private function announceToIndexNow(array $ids): void
    {
        if (! config('site.indexnow_enabled') || ! config('site.indexnow_key')) {
            return;
        }

        $done = false;

        app()->terminating(function () use ($ids, &$done): void {
            if ($done) {
                return;
            }

            $done = true;

            try {
                $seo = app(SeoService::class);
                $urls = Video::query()
                    ->whereIn('videos.id', $ids)
                    ->with(array_values(array_unique(array_merge(['user', 'category'], $seo->videoPathRelations()))))
                    ->get()
                    ->filter(fn (Video $video): bool => (bool) $video->is_active)
                    ->map(fn (Video $video): string => (string) $seo->videoUrl($video))
                    ->filter()
                    ->unique()
                    ->values();

                // An address announced in the last hour is not announced again (R1B-026: a loop of visibility changes
                // flooded the site's IndexNow key and held a worker each time); nothing new, no call at all.
                $urls = collect(\App\Support\IndexNowMemory::fresh($urls));

                if ($urls->isNotEmpty()) {
                    $this->postIndexNow($urls);
                }
            } catch (Throwable $e) {
                Log::warning('IndexNow submit failed: '.$e->getMessage());
            }
        });
    }

    /**
     * One IndexNow request per host of the addresses (all addresses of one request must share its host), at most 10,000
     * addresses each (the protocol's limit).
     *
     * @param  Collection<int, string>  $urls
     */
    private function postIndexNow(Collection $urls): void
    {
        $endpoint = rtrim((string) config('site.indexnow_endpoint', 'https://api.indexnow.org/indexnow'), '/');
        $key = (string) config('site.indexnow_key');

        foreach ($urls->groupBy(fn (string $url) => (string) parse_url($url, PHP_URL_HOST)) as $host => $list) {
            foreach ($list->chunk(10000) as $chunk) {
                Http::timeout(8)->post($endpoint, [
                    'host' => $host,
                    'key' => $key,
                    'keyLocation' => url('/'.$key.'.txt'),
                    'urlList' => $chunk->values()->all(),
                ]);
            }
        }
    }

    /**
     * The filters of the list from the query: the Type (Videos / Shorts) and the Date presets are this page's own;
     * everything else is VideoFilters' (search, status, category; the custom dates from / to).
     *
     * @return array<string, string>
     */
    private function videoFilters(Request $request): array
    {
        $filters = [];

        foreach (['search', 'status', 'category', 'type', 'date', 'from', 'to'] as $key) {
            $value = $request->query($key);

            if (is_string($value) && trim($value) !== '') {
                $filters[$key] = trim($value);
            }
        }

        // An empty array would make the filter read the whole query string itself.
        return $filters === [] ? ['__none' => '1'] : $filters;
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'oldest' => $query->orderBy('videos.created_at')->orderBy('videos.id'),
            'views' => $query->orderByDesc('videos.views')->orderByDesc('videos.id'),
            'comments' => $query->orderByDesc('comments_count')->orderByDesc('videos.id'),
            default => $query->orderByDesc('videos.created_at')->orderByDesc('videos.id'),
        };
    }

    /**
     * One grouped COUNT for the tabs. A scheduled video whose time has come is public (Video::real_status).
     *
     * @return array<string, int>
     */
    private function tabCounts(int $userId): array
    {
        // One grouped query per page. No static cache: a function-level static outlives the request in a long-running
        // worker (and across the tests of one process), so another member with the same id got stale counts.
        $rows = Video::query()
            ->withoutGlobalScopes()
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->selectRaw('CASE WHEN status = ? AND scheduled_at <= ? THEN ? ELSE status END AS tab_status, COUNT(*) AS aggregate', [
                VideoStatus::PLANNED->value, now(), VideoStatus::PUBLIC->value,
            ])
            ->groupBy('tab_status')
            ->pluck('aggregate', 'tab_status')
            ->all();

        $counts = ['all' => (int) array_sum($rows)];

        foreach (self::TABS as $key => $status) {
            if ($status !== null) {
                $counts[$key] = (int) ($rows[$status->value] ?? 0);
            }
        }

        return $counts;
    }

    private function currentTab(Request $request): string
    {
        $status = $request->query('status');

        foreach (self::TABS as $key => $value) {
            if ($value !== null && is_string($status) && $status === (string) $value->value) {
                return $key;
            }
        }

        return 'all';
    }

    private function filtersActive(Request $request): bool
    {
        foreach (['search', 'category', 'type', 'date', 'from', 'to', 'sort'] as $key) {
            $value = $request->query($key);

            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /** The list's query string to come back to after an action from it (filters, tab, sort, page). */
    private function listQuery(): array
    {
        $previous = url()->previous();

        // The list itself, read with the one rule of the site's addresses (locale_path_parts()): without the language of
        // a member who reads the site in another one, /index.php and the folder of a site installed in one. Round 3 fix
        // pass R2R-12: "/vi/profile/videos?..." was not "/profile/videos", so the list came back without them.
        $host = strtolower((string) parse_url((string) $previous, PHP_URL_HOST));

        if (! is_string($previous) || $previous === ''
            || ($host !== '' && ! in_array($host, [strtolower(request()->getHost()), strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))], true))
            || locale_path_parts($previous)['path'] !== locale_path_parts(route('user.videos.index'))['path']) {
            return [];
        }

        parse_str((string) parse_url($previous, PHP_URL_QUERY), $query);

        return array_intersect_key(array_filter($query, 'is_string'), array_flip(['search', 'status', 'category', 'type', 'date', 'from', 'to', 'sort', 'page']));
    }

    /**
     * Everything Video details and the draft page show (6.4).
     *
     * @return array<string, mixed>
     */
    private function formData(Video $video): array
    {
        $video->loadMissing(['thumbnails', 'category', 'user']);
        $video->loadCount(['likes', 'dislikes', 'comments', 'playlists']);

        // 1.2.3: no category named with a word that is always blocked (App\Support\ContentSafety), except the video's own
        // (an unchanged form still saves)
        $categories = \App\Support\ContentSafety::visibleCategories(Category::query()->orderBy('title')->get(['id', 'title']), (int) $video->category_id);

        $mode = (string) config('site.studio_video_preview_mode', \App\Support\AppearanceSettings::defaults()['studio_video_preview_mode'] ?? 'button');
        $mode = array_key_exists($mode, \App\Support\AppearanceSettings::studioVideoPreviewModes()) ? $mode : 'button';

        return [
            'video' => $video,
            'status' => VideoStatus::getActive(),
            'categories' => $categories,
            'categoriesNative' => $categories->count() <= 30,
            'languages' => Video::languages(),
            'playlists' => Playlist::query()
                ->where('user_id', Auth::id())
                ->where('is_deletable', true)
                ->withExists([
                    'videos as has_video' => fn ($q) => $q->where('video_id', $video->id),
                ])
                ->orderBy('title')
                ->get(),
            'previewMode' => $mode,
            'enhancedSelects' => StudioChrome::enhancedSelects(),
            'customThumbnails' => StudioSettings::on('allow_custom_thumbnails', true),
            'commentsEnabled' => StudioSettings::on('comments_enabled', true),
            'adsEnabled' => StudioSettings::on('ads_enabled', false),
        ];
    }
}
