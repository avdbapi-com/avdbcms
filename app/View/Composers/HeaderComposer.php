<?php

namespace App\View\Composers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Throwable;

class HeaderComposer
{
    /**
     * Bind data to the view.
     *
     * @param View  $view
     * @return void
     */
    public function compose(View $view): void
    {
        $view->with([
            'unread_notifications' => config('site.header_notifications_enabled', true)
                ? $this->unreadNotificationsCount()
                : 0,
            'show_sidebar' => config('site.front_sidebar_pinned_enabled', true)
                && !in_array(request()->route()?->getName(), ['video.show', 'video.seo-show', 'pages.premium']),
        ]);
    }

    private function unreadNotificationsCount(): int
    {
        $user = Auth::user();

        if (!$user) {
            return 0;
        }

        try {
            $cacheKey = 'admin.header.unread_notifications.' . $user->getAuthIdentifier();

            return (int) Cache::remember($cacheKey, now()->addMinutes(1), fn () => $user->unreadNotifications()->count());
        } catch (Throwable) {
            return 0;
        }
    }
}
