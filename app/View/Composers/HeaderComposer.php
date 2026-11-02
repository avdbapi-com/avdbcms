<?php

namespace App\View\Composers;

use App\Models\User;
use App\Notifications\Support\Inbox;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

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

    /**
     * The bell's unread count: counted up to 100 (the badge shows "99+" from there), kept a minute per member and
     * forgotten when the member reads or deletes notifications (App\Notifications\Support\Inbox).
     */
    private function unreadNotificationsCount(): int
    {
        $user = Auth::user();

        if (!$user instanceof User) {
            return 0;
        }

        return Inbox::cachedUnreadCount($user);
    }
}
