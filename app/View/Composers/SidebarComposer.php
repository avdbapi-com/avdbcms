<?php

namespace App\View\Composers;

use App\Models\Category;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

class SidebarComposer
{
    /**
     * Bind data to the view.
     *
     * @param View  $view
     * @return void
     */
    public function compose(View $view): void
    {
        try {
            $categories = Cache::rememberForever('categories', fn() => Category::query()->where('in_menu', true)->ordered()->get());

            // Keep the category metadata cache, but always derive the displayed
            // video count from the same public feed rules as the category page.
            // Counts must follow the current locale and exclude hidden/inactive
            // videos and creators; caching them forever would make the shelf lie
            // after imports, moderation, or a locale change.
            $categories->loadCount([
                'videos as videos_count' => fn ($query) => $query->active()->forLocale(),
            ]);
        } catch (Throwable) {
            Cache::forget('categories');
            $categories = collect();
        }

        $view->with([
            'categories' => $categories,
            'show_sidebar' => config('site.front_sidebar_pinned_enabled', true)
                && !in_array(request()->route()?->getName(), ['video.show', 'video.seo-show', 'pages.premium']),
        ]);

        try {
            $user = Auth::user();
        } catch (Throwable) {
            $user = null;
        }

        if (! $user) {
            return;
        }

        try {
            $subscriptions = config('site.sidebar_subscriptions_enabled', true)
                ? $this->cachedSidebarSubscriptions($user)
                : collect();

            $favoritePlaylists = config('site.sidebar_playlists_enabled', true)
                ? $this->cachedSidebarPlaylists($user)
                : collect();

            $subscriptionsCount = config('site.sidebar_subscriptions_enabled', true)
                ? $this->cachedSidebarSubscriptionsCount($user)
                : 0;

            $favoritePlaylistsCount = config('site.sidebar_playlists_enabled', true)
                ? $this->cachedSidebarPlaylistsCount($user)
                : 0;
        } catch (Throwable) {
            $subscriptions = collect();
            $favoritePlaylists = collect();
            $subscriptionsCount = 0;
            $favoritePlaylistsCount = 0;
        }

        $view->with([
            'subscriptions' => $subscriptions,
            'subscriptions_count' => $subscriptionsCount,
            'favorite_playlists' => $favoritePlaylists,
            'favorite_playlists_count' => $favoritePlaylistsCount,
        ]);
    }

    private function cachedSidebarSubscriptions($user): Collection
    {
        $cacheTtl = now()->addMinutes(2);
        $fetchLimit = max(50, (int) config('site.sidebar_subscriptions_fetch_limit', 50));
        $cacheKey = 'sidebar.subscriptions.v2.' . $user->id . '.' . $fetchLimit;

        return Cache::remember($cacheKey, $cacheTtl, function () use ($user, $fetchLimit): Collection {
            return $user
                ->subscriptions()
                ->distinct()
                ->withExists([
                    'videos as has_new_video' => function($query) {
                        return $query->active()->where(function($q) {
                            $q->whereNull('subscriptions.read_at')
                              ->orWhere('published_at', '>', DB::raw('subscriptions.read_at'));
                        });
                    }
                ])
                ->latest('subscribe_at')
                ->limit($fetchLimit)
                ->get();
        });
    }

    private function cachedSidebarSubscriptionsCount($user): int
    {
        $cacheTtl = now()->addMinutes(2);
        $cacheKey = 'sidebar.subscriptions_count.' . $user->id;

        return (int) Cache::remember($cacheKey, $cacheTtl, function () use ($user): int {
            return (int) $user->subscriptions()->count();
        });
    }

    private function cachedSidebarPlaylists($user): Collection
    {
        $cacheTtl = now()->addMinutes(2);
        $fetchLimit = max(50, (int) config('site.sidebar_playlists_fetch_limit', 50));
        $cacheKey = 'sidebar.favorite_playlists.v2.' . $user->id . '.' . $fetchLimit;

        return Cache::remember($cacheKey, $cacheTtl, function () use ($user, $fetchLimit): Collection {
            return $user
                ->favorites_playlist()
                ->latest('added_at')
                ->limit($fetchLimit)
                ->get();
        });
    }

    private function cachedSidebarPlaylistsCount($user): int
    {
        $cacheTtl = now()->addMinutes(2);
        $cacheKey = 'sidebar.playlists_count.' . $user->id;

        return (int) Cache::remember($cacheKey, $cacheTtl, function () use ($user): int {
            return (int) $user->favorites_playlist()->count();
        });
    }
}
