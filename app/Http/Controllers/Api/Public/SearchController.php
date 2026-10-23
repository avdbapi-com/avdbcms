<?php

namespace App\Http\Controllers\Api\Public;

use App\Events\SearchPerformed;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequest;
use App\Models\Category;
use App\Models\Video;
use App\Services\VideoDatabaseSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class SearchController extends Controller
{
    public function search(SearchRequest $request, VideoDatabaseSearchService $databaseSearch): JsonResponse
    {
        $q = trim((string) $request->validated('q'));

        if ($q === '') {
            return response()->json([
                'total' => 0,
                'has_more' => false,
                'categories' => [],
                'items' => [],
                'route' => locale_url(route('search.index').'?q='.rawurlencode($q)),
            ]);
        }

        $payload = $this->cachedPayload($q, $databaseSearch);

        try {
            SearchPerformed::dispatch($q, (int) ($payload['total'] ?? 0));
        } catch (\Throwable) {
        }

        return response()->json($payload);
    }

    /**
     * @return array{total: int, items: array<int, array<string, mixed>>, route: string}
     */
    private function cachedPayload(string $q, VideoDatabaseSearchService $databaseSearch): array
    {
        $ttl = max(0, (int) config('cache.public_search_ttl', 60));

        if ($ttl <= 0) {
            return $this->payload($q, $databaseSearch);
        }

        return Cache::remember(
            $this->cacheKey($q),
            now()->addSeconds($ttl),
            fn () => $this->payload($q, $databaseSearch)
        );
    }

    private function cacheKey(string $q): string
    {
        return 'api.public-search.' . sha1(implode('|', [
            app()->getLocale(),
            (string) config('scout.driver', 'collection'),
            $q,
        ]));
    }

    /**
     * @return array{total: int, items: array<int, array<string, mixed>>, route: string}
     */
    private function payload(string $q, VideoDatabaseSearchService $databaseSearch): array
    {
        $scoutDriver = config('scout.driver', 'collection');
        $tagMatched = $databaseSearch->hasTagMatch($q);

        if (
            $scoutDriver === 'meilisearch'
            && class_exists('Meilisearch\Client')
            && filled(config('scout.meilisearch.host'))
            && ! $tagMatched
        ) {
            $videos = Video::search($q, function ($index, $query, $options) {
                $options['attributesToRetrieve'] = ['title', 'url', 'thumbnail', 'user', 'uuid', 'views', 'published_at', 'formated_duration'];
                $options['attributesToHighlight'] = ['title', 'user'];
                $options['attributesToCrop'] = ['title:15'];

                return $index->rawSearch($query, $options);
            })
                ->orderBy('views', 'desc')
                ->latest('published_at')
                ->take(10)
                ->raw();

            $totalHits = $videos['totalHits'] ?? $videos['estimatedTotalHits'] ?? 0;

            return [
                'total' => (int) $totalHits,
                'has_more' => (int) $totalHits > count($videos['hits'] ?? []),
                'categories' => [],
                'items' => array_map(function ($hit) {
                    $hit = array_merge([
                        'title' => '',
                        'uuid' => '',
                        'url' => '',
                        'thumbnail' => null,
                        'user' => '',
                        'views' => 0,
                        'published_at' => null,
                        'formated_duration' => null,
                    ], is_array($hit) ? $hit : []);

                    if (isset($hit['url'])) {
                        $hit['url'] = locale_url($hit['url']);
                    }

                    return $hit;
                }, $videos['hits'] ?? []),
                'route' => locale_url(route('search.index') . '?q=' . rawurlencode($q)),
            ];
        }

        $query = $databaseSearch->apply(
            Video::query()
                ->active()
                ->forLocale()
                ->with('user'),
            $q,
            $tagMatched
        );

        $total = (clone $query)->reorder()->count('videos.id');
        $results = $query
            ->orderByDesc('views')
            ->latest('published_at')
            ->orderByDesc('videos.id')
            ->take(10)
            ->get();
        $hasMore = $total > $results->count();

        // Category titles are stored in the canonical language while the
        // public search UI sends the localized label. Compare both forms so
        // mobile and desktop suggestions use the same locale-aware results.
        $needle = mb_strtolower($q);
        $matchedCategories = Category::query()
            ->get(['id', 'title', 'slug'])
            ->filter(function ($category) use ($needle): bool {
                $canonical = mb_strtolower((string) $category->title);
                $localized = mb_strtolower((string) __($category->title));

                return mb_strpos($canonical, $needle) !== false
                    || mb_strpos($localized, $needle) !== false;
            })
            ->take(3)
            ->values();

        return [
            'total' => (int) $total,
            'has_more' => $hasMore,
            'categories' => $matchedCategories->map(fn ($cat) => [
                'id' => $cat->id,
                'title' => __($cat->title),
                'url' => locale_url(route('pages.category', $cat->slug)),
            ])->values()->all(),
            'items' => $results->map(fn ($video) => [
                'title' => $video->title,
                'uuid' => $video->uuid,
                'url' => locale_url($video->route),
                'thumbnail' => $video->thumbnail_url,
                'user' => $video->user?->username ?? '',
                'views' => $video->views,
                'published_at' => $video->published_at?->toISOString(),
                'formated_duration' => $video->duration,
            ])->values()->all(),
            'route' => locale_url(route('search.index') . '?q=' . rawurlencode($q)),
        ];
    }
}
