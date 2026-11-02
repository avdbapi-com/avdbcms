<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationSetting;
use App\Notifications\Support\BellResource;
use App\Notifications\Support\Inbox;
use App\Notifications\Support\NotificationRetention;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** Opening a notification marks it read and goes to its page on this site (the home page when it has none). */
    public function click (Request $request, Notification $notification) : RedirectResponse {

        $notification->markAsRead();
        Inbox::forget($request->user());

        // Only a page of this site: a stored link that names another host (for example one stored while 1.2.2 believed
        // forwarded headers, security plan S33) goes home instead of sending the member off-site from a real notification.
        $url = (string) ($notification->url ?? '');

        return redirect()->to(self::isOnThisSite($url, $request) ? $url : '/');
    }

    /**
     * A path on this site ("/@member", "/video/...?x=1") or an http(s) address whose host is the site's own (APP_URL's,
     * or the one this request came to), without a user name. A backslash, whitespace or control character anywhere
     * refuses it: browsers read such an address differently from parse_url() ("https://evil.example\@site/" goes to
     * evil.example; a tab in "/<TAB>/evil.example" is dropped, leaving "//evil.example").
     */
    private static function isOnThisSite(string $url, Request $request): bool
    {
        if ($url === '' || preg_match('/[\\\\\s\x00-\x1f\x7f]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (preg_match('#^/(?!/)#', $url) === 1) {
            return true;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $own = array_filter([
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
            strtolower($request->getHost()),
        ]);

        return in_array($scheme, ['http', 'https'], true) && $host !== '' && in_array($host, $own, true);
    }

    /**
     * The notifications page /profile/notifications (member design 6.14): the studio's Activity group and the account
     * panel lead here. Tabs All / Unread, the rows grouped Today / Yesterday / This week (2 to 6 days ago) / Earlier,
     * newest first, a page at a time (by key, quick at any size: Inbox), each row with its "..." menu (Mark as read /
     * unread, Delete: the bell's own POST / DELETE calls), "Mark all as read" and Settings in the head.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $tab = $request->query('show') === 'unread' ? 'unread' : 'all';
        [$page, $markers] = $tab === 'unread' ? Inbox::unreadPage($user) : Inbox::page($user);
        BellResource::preload($page->items(), $user);

        $groups = [];

        // Each row's group and time come from BellResource::time() (the site's zone and date format), the same words the
        // bell shows for it.
        foreach ($page->items() as $notification) {
            $row = (new BellResource($notification))->toArray($request);
            $groups[$row['group']][] = $row;
        }

        $order = ['today' => __('Today'), 'yesterday' => __('Yesterday'), 'week' => __('This week'), 'earlier' => __('Earlier')];
        $grouped = [];

        foreach ($order as $key => $title) {
            if (! empty($groups[$key])) {
                $grouped[] = ['key' => $key, 'title' => $title, 'rows' => $groups[$key]];
            }
        }

        return view('users.notifications.index', [
            'tab' => $tab,
            'groups' => $grouped,
            'page' => $page,
            'unread' => Inbox::unreadCount($user, $markers),
            'total' => Inbox::totalCount($user, $markers),
            'keptDays' => NotificationRetention::READ_DAYS,
            'keptCount' => NotificationRetention::KEEP_PER_MEMBER,
        ]);
    }

    /**
     * Account settings > Notifications (member design 6.14): New subscribers, Comments on my videos, Replies to my
     * comments, New videos from channels I follow. Upload results and account e-mails are always on (not settings). A
     * view-only demo account is refused before this runs (ViewOnlyDemo).
     */
    public function settings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'new_subscribers' => ['sometimes', 'boolean'],
            'comments' => ['sometimes', 'boolean'],
            'replies' => ['sometimes', 'boolean'],
            'new_videos' => ['sometimes', 'boolean'],
        ]);

        $back = redirect()->to(locale_url(route('user.edit', ['section' => 'notifications'])).'#notification-settings');

        // Until "Finish update" has added the table nothing can be kept: say so (as the bell's API does with its 409),
        // never "saved".
        if (! NotificationSetting::ready()) {
            return $back->with('error', __('This setting is not available until the site finishes its update.'));
        }

        // The form sends every switch (a hidden 0 before each check box); a switch a request leaves out stays as it was.
        NotificationSetting::store($request->user(), $validated);

        return $back->with('success', __('Notification settings saved.'));
    }
}
