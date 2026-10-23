<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Resources\Video\VideoListResource;
use App\Models\Category;
use App\Services\PublicVideoFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class CategoryController
{
    public function videos(Request $request, Category $category, PublicVideoFeedService $feeds): JsonResponse|ResourceCollection
    {
        $cursor = (string) $request->query('cursor', '');
        $locale = app()->getLocale();
        $perPage = (int) config('site.per_page_videos', 24);
        $isGuest = ! Auth::check();
        $cacheTtl = (int) config('site.feed_cache_ttl', 30);
        $cacheKey = "feed:category:{$category->id}:p{$perPage}:cur" . md5($cursor) . ":{$locale}";

        if ($isGuest && $cacheTtl > 0) {
            $cachedData = Cache::get($cacheKey);
            if ($cachedData !== null) {
                return response()->json($cachedData)->header('X-Feed-Cache', 'HIT');
            }
        }

        $videos = $feeds
            ->category($category)
            ->with('user')
            ->cursorPaginate($perPage);

        $resource = VideoListResource::collection($videos);

        if ($isGuest && $cacheTtl > 0) {
            $data = $resource->response()->getData(true);
            Cache::put($cacheKey, $data, now()->addSeconds($cacheTtl));
            return response()->json($data)->header('X-Feed-Cache', 'MISS');
        }

        return $resource;
    }
}
