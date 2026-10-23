<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VideoStatus;
use App\Events\Video\VideoBanned;
use App\Models\Category;
use App\Models\Video;
use App\Services\SeoService;
use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VideoController
{
    /** Orders of the new admin's list (newest first stays the default). */
    private const SORTS = ['newest', 'oldest', 'views', 'title'];

    public function index (Request $request) : View {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.videos.list', $this->listPage($request));
        }

        return view('admin.videos.index', [
            'videos' => Video::filter()
                ->with([
                    'category:id,title',
                    'user' => fn($q) => $q->withCount(['videos', 'subscribers'])
                ])
                ->latest('created_at')
                ->paginate(AdminPagination::perPage($request, 15))
                ->withQueryString()
        ]);
    }

    /**
     * The new admin's list: only the columns it shows, eager loaded (no query per row), 25 rows by default, the status
     * tab counts cached for a minute (any admin change clears them).
     */
    private function listPage(Request $request): array
    {
        $sort = in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'newest';
        $locale = app()->getLocale();
        $query = Video::filter()
            // The creator's state too: a public video of a blocked or unconfirmed creator is not shown on the site.
            ->with(['category:id,title', 'user:id,username,email_verified_at,banned_at'])
            ->when($locale !== 'en' && $locale !== config('app.fallback_locale', 'en'), fn ($query) => $query->with(['translations' => fn ($q) => $q->where('locale', $locale)]));

        match ($sort) {
            'oldest' => $query->oldest('created_at'),
            'views' => $query->orderByDesc('views'),
            'title' => $query->orderBy('title'),
            default => $query->latest('created_at'),
        };

        return [
            'videos' => $query->paginate(AdminPagination::perPage($request, 25))->withQueryString(),
            'sort' => $sort,
            'counts' => AdminCache::remember('videos.tabs.v2', now()->addMinute(), function (): array {
                $byStatus = Video::query()->toBase()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all();

                return [
                    'all' => (int) array_sum($byStatus),
                    // Public and Scheduled count what their tabs list (published / not yet due), like the filter.
                    'public' => Video::filter(['status' => (string) VideoStatus::PUBLIC->value])->count(),
                    'unlisted' => (int) ($byStatus[VideoStatus::UNLISTED->value] ?? 0),
                    'private' => (int) ($byStatus[VideoStatus::PRIVATE->value] ?? 0),
                    'planned' => Video::filter(['status' => (string) VideoStatus::PLANNED->value])->count(),
                    'banned' => (int) ($byStatus[VideoStatus::BANNED->value] ?? 0),
                    // Public, but not shown: their creator is blocked, deleted or has not confirmed their email.
                    'not_shown' => Video::query()
                        ->where('status', VideoStatus::PUBLIC)
                        ->whereNotNull('uploaded_at')
                        ->whereDoesntHave('user', fn ($query) => $query->active())
                        ->count(),
                ];
            }),
            'categories' => AdminCache::remember('videos.categories.v1', now()->addMinutes(5), fn (): array => Category::query()->orderBy('title')->pluck('title', 'id')->all()),
        ];
    }

    public function edit (Video $video) : View {
        $video->load(['user', 'category']);
        $categories = Category::orderBy('title')->get();

        if (AdminPreferences::usesNewAdmin()) {
            // The record page shows the latest comments with their authors (one query each, not one per comment).
            $video->load(['comments' => fn ($query) => $query->with('user:id,username')->latest('id')->limit(5)]);

            return view('admin.videos.record', compact('video', 'categories'));
        }

        return view('admin.videos.edit', compact('video', 'categories'));
    }

    public function update (Video $video, Request $request, SeoService $seo) : RedirectResponse {

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'status' => ['required', 'string'],
            'language' => ['nullable', 'string', 'max:5'],
            'show_ad' => ['nullable', 'boolean'],
            'allow_comments' => ['nullable', 'boolean'],
            'show_likes' => ['nullable', 'boolean'],
            'is_short' => ['nullable', 'boolean'],
        ]);

        $status = (int) $validated['status'];
        $publishedAt = $video->published_at;
        if ($status === \App\Enums\VideoStatus::PUBLIC->value && is_null($publishedAt)) {
            $publishedAt = now();
        }

        $video->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? '',
            'category_id' => $validated['category_id'],
            'status' => $validated['status'],
            'published_at' => $publishedAt,
            'language' => $validated['language'] ?? $video->language,
            'show_ad' => $request->has('show_ad'),
            'allow_comments' => $request->has('allow_comments'),
            'show_likes' => $request->has('show_likes'),
            'is_short' => $request->has('is_short'),
        ]);

        $seo->submitVideoToIndexNow($video->fresh(['user', 'category']));

        return redirect()
            ->route('admin.videos.edit', $video)
            ->withSuccess('Video updated successfully.');
    }

    public function ban (Video $video) : RedirectResponse {

        $video->update([
           'status' => VideoStatus::BANNED,
           'banned_at' => now()
        ]);

        VideoBanned::dispatch($video);

        return redirect()->route('admin.videos.index')->withSuccess('Video banned successfully.');
    }

    public function unban (Video $video, SeoService $seo) : RedirectResponse {

        $video->update([
           'status' => VideoStatus::PUBLIC,
           'banned_at' => null
        ]);

        $seo->submitVideoToIndexNow($video->fresh(['user', 'category']));

        return redirect()->route('admin.videos.index')->withSuccess('Video unbanned successfully.');
    }

    public function destroy (Video $video) : RedirectResponse {

        // forceDelete triggers observer cascade (files, comments, views, etc.)
        $video->forceDelete();

        return redirect()->route('admin.videos.index')->withSuccess('Video deleted permanently.');
    }

    public function export (): RedirectResponse {
        return redirect()->route('admin.users.index')->withSuccess('Votre export a bien été pris en compte. Vous recevrez une <strong>notification</strong> quand celui-ci sera disponible.');
    }
}
