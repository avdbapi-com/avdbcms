<?php

namespace App\Http\Controllers;

use App\Filters\SearchFilters;
use App\Http\Requests\SearchRequest;
use App\Models\Search;
use App\Models\Video;
use App\Services\VideoDatabaseSearchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function index(SearchRequest $request, SearchFilters $filters): View
    {
        $q = trim((string) $request->validated('q'));

        if ($q === '') {
            $page = max(1, min(100, (int) request()->query('page', 1)));
            $query = Video::query()
                ->with('user')
                ->active()
                ->forLocale();

            $this->applyDatabaseFilters($query, $filters->receivedFilters());

            $order = request()->input('order');
            if ($order === 'random') {
                $query->inRandomOrder();
            } elseif ($order === 'latest' || $order === 'date') {
                $query->latest('published_at');
            } elseif ($order === 'likes') {
                $query->orderByDesc('likes_count');
            } else {
                $query->orderByDesc('views')->latest('published_at');
            }

            $videos = $query->paginate(config('site.per_page_search', 20), ['*'], 'page', $page)
                ->withQueryString();

            return view('pages.search', [
                'search'  => $q,
                'results' => $videos,
                'filters' => $filters->receivedFilters()
            ]);
        }

        $cacheKey = 'search_web_results_' . md5(implode('|', [
            app()->getLocale(),
            $q,
            json_encode($filters->receivedFilters()),
            (string) $request->input('order', ''),
            (string) $request->integer('page', 1),
            // Cached paginators also retain the request's link parameters.
            json_encode($request->query()),
        ]));
        $videos = Cache::remember($cacheKey, 60, function () use ($q, $filters) {
            $databaseSearch = app(VideoDatabaseSearchService::class);
            $tagMatched = $databaseSearch->hasTagMatch($q);

            return $this->shouldUseMeilisearch() && ! $tagMatched
                ? $this->searchWithMeilisearch($q, $filters)
                : $this->searchWithDatabase($q, $filters, $databaseSearch, $tagMatched);
        });

        try {
            Search::firstOrCreate(['query' => mb_strtolower(trim($q))]);
        } catch (\Throwable) {}

        return view('pages.search', [
            'search'  => $q,
            'results' => $videos,
            'filters' => $filters->receivedFilters()
        ]);
    }

    private function shouldUseMeilisearch(): bool
    {
        return config('scout.driver') === 'meilisearch'
            && class_exists('Meilisearch\Client')
            && filled(config('scout.meilisearch.host'));
    }

    private function searchWithMeilisearch(string $q, SearchFilters $filters): LengthAwarePaginator
    {
        return Video::search($q, function($index, $query, $options) use ($filters) {
                $options['filter'] = $filters->apply();
                return $index->rawSearch($query, $options);
            })
            ->query(function (Builder $query) {
                return $query->with('user');
            })
            ->orderBy('views', 'desc')
            ->latest('published_at')
            ->paginate(config('site.per_page_search', 20))
            ->withQueryString();
    }

    private function searchWithDatabase(string $q, SearchFilters $filters, VideoDatabaseSearchService $databaseSearch, bool $tagMatched = false): LengthAwarePaginator
    {
        $page = max(1, min(100, (int) request()->query('page', 1)));
        $query = $databaseSearch->apply(
            Video::query()
                ->with('user')
                ->active()
                ->forLocale(),
            $q,
            $tagMatched
        );

        $this->applyDatabaseFilters($query, $filters->receivedFilters());

        $order = request()->input('order');
        if ($order === 'random') {
            $query->inRandomOrder();
        } elseif ($order === 'latest' || $order === 'date') {
            $query->latest('published_at');
        } elseif ($order === 'likes') {
            $query->orderByDesc('likes_count');
        } else {
            $query->orderByDesc('views')->latest('published_at');
        }

        return $query
            ->paginate(config('site.per_page_search', 20), ['*'], 'page', $page)
            ->withQueryString();
    }

    private function applyDatabaseFilters(Builder $query, array $filters): void
    {
        // Legacy installs store duration as text; comparisons must use seconds,
        // not alphabetical ordering (for example, "90" versus "240").
        $durationSql = $query->getConnection()->getDriverName() === 'mysql'
            ? 'CAST(videos.duration AS UNSIGNED)'
            : 'CAST(videos.duration AS INTEGER)';

        if (($filters['duration'] ?? null) === '4') {
            $query->whereRaw($durationSql.' < ?', [240]);
        } elseif (($filters['duration'] ?? null) === '4-20') {
            $query->whereRaw($durationSql.' BETWEEN ? AND ?', [240, 1200]);
        } elseif (($filters['duration'] ?? null) === '20') {
            $query->whereRaw($durationSql.' > ?', [1200]);
        }

        $date = $filters['date'] ?? null;

        if ($date === 'hour') {
            $query->where('published_at', '>=', now()->subHour());
        } elseif ($date === 'today') {
            $query->where('published_at', '>=', now()->startOfDay());
        } elseif ($date === 'week') {
            $query->where('published_at', '>=', now()->startOfWeek());
        } elseif ($date === 'month') {
            $query->where('published_at', '>=', now()->startOfMonth());
        } elseif ($date === 'year') {
            $query->where('published_at', '>=', now()->startOfYear());
        }
    }
}
