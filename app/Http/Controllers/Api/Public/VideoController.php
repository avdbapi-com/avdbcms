<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Resources\Video\VideoListResource;
use App\Http\Resources\Video\VideoPlayerResource;
use App\Http\Resources\Video\VideoShowResource;
use App\Models\Video;
use App\Services\PublicVideoFeedService;
use App\Services\ViewService;
use App\Support\AppearanceSettings;
use App\Support\AvdbDurationBackfill;
use App\Support\RuntimeEnv;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class VideoController
{
    public function index(Request $request, PublicVideoFeedService $feeds): JsonResponse|ResourceCollection
    {
        $source = (string) $request->query('source', 'newest');
        $source = array_key_exists($source, AppearanceSettings::homeFeedSources()) ? $source : 'newest';
        $categoryId = max(0, (int) $request->query('category', 0));
        $perPage = max(1, min(
            AppearanceSettings::HOME_FEED_MAX_ITEMS,
            (int) $request->query('per_page', config('site.per_page_videos', 24))
        ));
        $cursor = (string) $request->query('cursor', '');
        $locale = app()->getLocale();

        $isGuest = ! Auth::check();
        $cacheTtl = (int) config('site.feed_cache_ttl', 30);
        $cacheKey = "feed:index:{$source}:c{$categoryId}:p{$perPage}:cur" . md5($cursor) . ":{$locale}";

        if ($isGuest && $cacheTtl > 0) {
            $cachedData = Cache::get($cacheKey);
            if ($cachedData !== null) {
                return response()->json($cachedData)->header('X-Feed-Cache', 'HIT');
            }
        }

        $videos = $feeds
            ->home($source, $categoryId)
            ->with('user')
            ->cursorPaginate($perPage);

        $this->prioritizeMissingDurations($videos->getCollection());

        $resource = VideoListResource::collection($videos);

        if ($isGuest && $cacheTtl > 0) {
            $data = $resource->response()->getData(true);
            Cache::put($cacheKey, $data, now()->addSeconds($cacheTtl));
            return response()->json($data)->header('X-Feed-Cache', 'MISS');
        }

        return $resource;
    }

    public function trend(Request $request, PublicVideoFeedService $feeds): JsonResponse|ResourceCollection
    {
        $cursor = (string) $request->query('cursor', '');
        $locale = app()->getLocale();
        $perPage = (int) config('site.per_page_videos', 24);
        $isGuest = ! Auth::check();
        $cacheTtl = (int) config('site.feed_cache_ttl', 30);
        $cacheKey = "feed:trend:p{$perPage}:cur" . md5($cursor) . ":{$locale}";

        if ($isGuest && $cacheTtl > 0) {
            $cachedData = Cache::get($cacheKey);
            if ($cachedData !== null) {
                return response()->json($cachedData)->header('X-Feed-Cache', 'HIT');
            }
        }

        $videos = $feeds
            ->trending()
            ->with('user')
            ->cursorPaginate($perPage);

        $this->prioritizeMissingDurations($videos->getCollection());

        $resource = VideoListResource::collection($videos);

        if ($isGuest && $cacheTtl > 0) {
            $data = $resource->response()->getData(true);
            Cache::put($cacheKey, $data, now()->addSeconds($cacheTtl));
            return response()->json($data)->header('X-Feed-Cache', 'MISS');
        }

        return $resource;
    }

    public function show(Video $video): VideoShowResource
    {
        return new VideoShowResource(
            $video
                ->load([
                    'user' => function ($q) {
                        return $q
                            ->withCount('subscribers')
                            ->withExists([
                                'subscribers as subscribed_by_auth_user' => fn ($q) => $q->where('subscriber_id', Auth::id()),
                            ]);
                    },
                    'reportByAuthUser',
                ])
                ->loadCount([
                    'likes',
                    'dislikes',
                    'comments as comments_count' => fn ($query) => $query->public(),
                ])
                ->loadExists([
                    'likes as liked_by_auth_user' => fn ($q) => $q->where('user_id', Auth::id()),
                    'dislikes as disliked_by_auth_user' => fn ($q) => $q->where('user_id', Auth::id()),
                    'reports as reported_by_auth_user' => fn ($q) => $q->where('user_id', Auth::id()),
                ])
        );
    }

    public function player(Video $video): VideoPlayerResource
    {
        return new VideoPlayerResource($video);
    }

    public function view(Video $video, ViewService $viewService): Response
    {
        $viewService->incrementView($video);

        return response()->noContent();
    }

    private function prioritizeMissingDurations($videos): void
    {
        $publicPriorityEnabled = in_array(
            strtolower((string) RuntimeEnv::get('AVDB_DURATION_PUBLIC_PRIORITY', 'false')),
            ['1', 'true', 'yes', 'on'],
            true
        );

        if (! $publicPriorityEnabled) {
            return;
        }

        $ids = collect($videos)
            ->filter(fn (Video $video): bool => $video->avdb_id !== null && (int) $video->getRawOriginal('duration') <= 0)
            ->pluck('id')
            ->all();

        if ($ids !== []) {
            AvdbDurationBackfill::prioritizeVisible($ids);
        }
    }
}
