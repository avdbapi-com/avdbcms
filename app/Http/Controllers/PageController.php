<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Plan;
use App\Services\AdaptiveCreatorSpotlightService;
use App\Services\AdaptiveTrendingCollectionsService;
use App\Services\SmartDiscoveryRailService;
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
    ): View|RedirectResponse {
        // A checkout that started before 1.2.3 returns to /?premium: the welcome sheet lives on /premium now, a page
        // without ads or the popunder (member design 6.16, OD-M10). Every other visitor of / gets the home page.
        if ($request->has('premium')) {
            return redirect()->to(locale_url(route('pages.premium', ['checkout' => 'success'])), 302);
        }

        $heroFeaturedVideos = $videoFeedService->heroVideos();

        return view('pages.home', [
            'smartDiscoveryTags' => $smartDiscoveryRail->tags(),
            'creatorSpotlightCreators' => $creatorSpotlight->creators(),
            'trendingCollections' => $trendingCollections->collections(),
            'heroFeaturedVideos' => $heroFeaturedVideos,
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
        // 1.2.3: a category named with a word that is always blocked is not listed (App\Support\ContentSafety); the
        // counts are the side menu's kept ones (App\Support\CategoryVideoCounts: the same rule, judge R3-022)
        $categories = \App\Support\CategoryVideoCounts::apply(\App\Support\ContentSafety::visibleCategories(Category::query()
            ->orderBy('position')
            ->orderBy('id')
            ->get())
            ->unique('slug')
            ->values());

        return view('pages.categories', [
            'categories' => $categories
        ]);
    }

    public function category(string $slug): View
    {
        // 1.2.3: not found when it is named with a word that is always blocked (App\Support\ContentSafety)
        $category = \App\Support\ContentSafety::visibleCategories(Category::query()
            ->where('slug', $slug)
            ->orderBy('position')
            ->orderBy('id')
            ->get())
            ->first() ?? abort(404);
        $category->setAttribute('videos_count', \App\Support\CategoryVideoCounts::of($category));

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
