<?php

namespace App\View\Composers;

use App\Enums\PlaylistStatus;
use App\Models\Category;
use App\Support\CachedUsers;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

class SidebarComposer
{
    /**
     * The saved playlists the side menu lists and counts: those their channel keeps public or unlisted, the same rule as
     * the Saved playlists page (PlaylistController::manage). A playlist made private after it was saved opened a 404 from
     * the menu, and the menu counted it ("Playlists (4)" next to 3 cards).
     */
    private const OPEN_PLAYLIST_STATUSES = [PlaylistStatus::PUBLIC->value, PlaylistStatus::UNLISTED->value];

    /**
     * Bind data to the view.
     *
     * @param View  $view
     * @return void
     */
    public function compose(View $view): void
    {
        // One page composes this for the page itself, the side menu and the footer: count once per request.
        $memoKey = 'avd.sidebar_categories.'.app()->getLocale();
        $categories = request()->attributes->get($memoKey);

        if (! $categories instanceof Collection) {
            try {
                // 1.2.3: no category named with a word that is always blocked (App\Support\ContentSafety)
                $categories = Cache::rememberForever('categories', fn() => \App\Support\ContentSafety::visibleCategories(Category::query()->where('in_menu', true)->ordered()->get()));

                // The displayed video counts follow the category page's own rule (active videos of active creators,
                // in the current language), counted for every category at once and kept until a write or a
                // scheduled moment can change them (App\Support\CategoryVideoCounts, judge R3-022: recounting on every
                // page view cost 0.6 s at 300,000 videos).
                \App\Support\CategoryVideoCounts::apply($categories);
            } catch (Throwable) {
                Cache::forget('categories');
                $categories = collect();
            }

            request()->attributes->set($memoKey, $categories);
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
            $channels = $user
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

            // The cache is a file on the server with the default store: the channels keep their public columns only, and
            // each pivot loses the row it was read through (the signed-in member's own users row, password hash
            // included). The menu prints the name, the picture, the address and the new-video dot.
            return CachedUsers::publicOnly($channels, ['has_new_video']);
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
            return CachedUsers::publicOnly($user
                ->favorites_playlist()
                ->whereIn('playlists.status', self::OPEN_PLAYLIST_STATUSES)
                ->latest('added_at')
                ->orderByDesc('playlists.id')
                ->limit($fetchLimit)
                ->get());
        });
    }

    private function cachedSidebarPlaylistsCount($user): int
    {
        $cacheTtl = now()->addMinutes(2);
        $cacheKey = 'sidebar.playlists_count.' . $user->id;

        return (int) Cache::remember($cacheKey, $cacheTtl, function () use ($user): int {
            return (int) $user->favorites_playlist()->whereIn('playlists.status', self::OPEN_PLAYLIST_STATUSES)->count();
        });
    }
}
