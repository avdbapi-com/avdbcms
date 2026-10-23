<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Plan;
use App\Services\AdaptiveCreatorSpotlightService;
use App\Services\AdaptiveTrendingCollectionsService;
use App\Services\SmartDiscoveryRailService;
use App\Support\SafeCache;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use App\Services\PublicVideoFeedService;
use Illuminate\Support\Facades\Auth;

class PageController
{
    public function home(
        Request $request,
        SmartDiscoveryRailService $smartDiscoveryRail,
        AdaptiveCreatorSpotlightService $creatorSpotlight,
        AdaptiveTrendingCollectionsService $trendingCollections,
        PublicVideoFeedService $videoFeedService
    ): View {
        $heroFeaturedVideos = SafeCache::remember('home_hero_featured_videos', 180, function () use ($videoFeedService) {
            return $videoFeedService->trending()
                ->with(['user', 'category'])
                ->take(10)
                ->get();
        });

        return view('pages.home', [
            'smartDiscoveryTags' => $smartDiscoveryRail->tags(),
            'creatorSpotlightCreators' => $creatorSpotlight->creators(),
            'trendingCollections' => $trendingCollections->collections(),
            'heroFeaturedVideos' => $heroFeaturedVideos,
            'showPremiumModal' => $request->has('premium') && Auth::check()
        ]);
    }

    public function trend(): View|RedirectResponse
    {
        if (!config('site.show_trending', true)) {
            return redirect()->route('pages.home')->with('info', __('Trending is currently unavailable.'));
        }

        return view('pages.trend');
    }

    public function history(): View|RedirectResponse
    {
        if (!config('site.show_history', true)) {
            return redirect()->route('pages.home')->with('info', __('Watch history is currently unavailable.'));
        }

        return view('pages.history');
    }

    public function categories(): View
    {
        $categories = Category::query()
            ->withCount(['videos' => fn ($query) => $query->active()->forLocale()])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->unique('slug')
            ->values();

        return view('pages.categories', [
            'categories' => $categories
        ]);
    }

    public function category(string $slug): View
    {
        $category = Category::query()
            ->where('slug', $slug)
            ->withCount(['videos' => fn ($query) => $query->active()->forLocale()])
            ->orderBy('position')
            ->orderBy('id')
            ->firstOrFail();

        return view('pages.category', [
            'category' => $category
        ]);
    }

    public function terms (): View {
        return view('pages.terms');
    }

    public function premium (): View {
        return view('pages.premium', [
            'plans' => Plan::query()->orderBy('duration')->get()
        ]);
    }

}
