<?php

namespace App\Http\Controllers\Admin;

use App\Notifications\Support\NotificationTasks;
use App\Support\AdminCache;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BroadcastController
{
    public function index(Request $request): View|RedirectResponse
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            // The new admin has ONE "send a notification" form (judge R1-161): New notification on the Sent page, which
            // sends in batches at any size (NotificationTasks). This address (the old To everyone tab, bookmarks, the
            // old Settings link) opens that form.
            return redirect()->to(route('admin.notifications.index', ['new' => 1]).'#new-notification');
        }

        $stats = [
            'total_users' => DB::table('users')->count(),
            'verified_users' => DB::table('users')->whereNotNull('email_verified_at')->count(),
            'premium_users' => DB::query()->fromSub(self::activePremiumUsersQuery(), 'active_premium_users')->count(),
            'recent_notifications' => $this->recent(),
        ];

        return view('admin.broadcast.index', compact('stats'));
    }

    /**
     * The 10 latest notifications, the newest first (index notifications_created_id_idx); those of an admin's "Delete
     * all" still being carried out are left out, as in the list. The views show their text in the admin's language.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function recent(): \Illuminate\Support\Collection
    {
        return DB::table('notifications')
            ->select('id', 'data', 'type', 'created_at')
            ->when(NotificationTasks::clearedBefore(), fn ($query, $at) => $query->where('created_at', '>', $at))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    /**
     * The old admin's Mass Broadcast form (and any page still open on the old To everyone tab): the same fields and
     * the same sending as New notification (NotificationController::send(): in batches by key, the first ones now, the
     * rest by the queue / the scheduler, so a site with many members never waits on one long page).
     */
    public function sendNotification(Request $request): RedirectResponse
    {
        $result = NotificationController::send($request);

        return (AdminPreferences::usesNewAdmin($request->user()) ? redirect()->route('admin.notifications.index') : redirect()->route('admin.broadcast.index'))
            ->withSuccess(NotificationController::sentMessage($result));
    }

    /**
     * How many members each choice of the form reaches (two queries, kept a minute).
     *
     * @return array{all: int, verified: int, unverified: int, premium: int}
     */
    public static function audience(): array
    {
        return AdminCache::remember('broadcast.audience.v2', now()->addMinute(), function (): array {
            $users = DB::table('users')->selectRaw('COUNT(*) as total, SUM(CASE WHEN email_verified_at IS NULL THEN 0 ELSE 1 END) as verified')->first();
            $total = (int) ($users->total ?? 0);
            $verified = (int) ($users->verified ?? 0);

            return [
                'all' => $total,
                'verified' => $verified,
                'unverified' => max(0, $total - $verified),
                'premium' => DB::query()->fromSub(self::activePremiumUsersQuery(), 'active_premium_users')->count(),
            ];
        });
    }

    private static function activePremiumUsersQuery(): Builder
    {
        return DB::table('premiums')
            ->whereIn('stripe_status', ['active', 'trialing'])
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->whereNotNull('user_id')
            ->distinct()
            ->select('user_id');
    }
}
