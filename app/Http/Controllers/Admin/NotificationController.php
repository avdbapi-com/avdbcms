<?php

namespace App\Http\Controllers\Admin;

use App\Data\NotificationBroadcastSettings;
use App\Models\User;
use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.notifications.list', $this->listPage($request));
        }

        $notifications = DB::table('notifications')
            ->orderByDesc('created_at')
            ->paginate(\App\Support\AdminPagination::perPage(null, 25));

        $stats = [
            'total' => DB::table('notifications')->count(),
            'unread' => DB::table('notifications')->whereNull('read_at')->count(),
        ];

        return view('admin.notifications.index', compact('notifications', 'stats'));
    }

    /**
     * The new admin's list: 25 per page, the latest first, all or only the unread ones (?show=unread), with the
     * member each one went to (one join); the two counts in one query, kept for a minute.
     */
    private function listPage(Request $request): array
    {
        $unreadOnly = $request->query('show') === 'unread';

        $notifications = DB::table('notifications')
            ->leftJoin('users', fn ($join) => $join->on('notifications.notifiable_id', '=', 'users.id')->where('notifications.notifiable_type', '=', User::class))
            ->select('notifications.id', 'notifications.type', 'notifications.notifiable_id', 'notifications.data', 'notifications.read_at', 'notifications.created_at', 'users.username')
            ->when($unreadOnly, fn ($query) => $query->whereNull('notifications.read_at'))
            ->orderByDesc('notifications.created_at')
            ->paginate(AdminPagination::perPage($request, 25))
            ->withQueryString();

        $stats = AdminCache::remember('notifications.counts.v1', now()->addMinute(), function (): array {
            $row = DB::table('notifications')->selectRaw('COUNT(*) as total, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread')->first();

            return ['total' => (int) ($row->total ?? 0), 'unread' => (int) ($row->unread ?? 0)];
        });

        return compact('notifications', 'stats', 'unreadOnly');
    }

    public function broadcast(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title'   => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:1000'],
            'target'  => ['required', 'in:all,premium,unverified'],
        ]);

        $query = User::query();

        if ($validated['target'] === 'premium') {
            $query->whereHas('premium_subscription', fn ($query) => $query->active());
        } elseif ($validated['target'] === 'unverified') {
            $query->whereNull('email_verified_at');
        }

        $chunkSize = NotificationBroadcastSettings::fromConfig()->chunkSize;
        $data = json_encode([
            'title'   => $validated['title'],
            'message' => $validated['message'],
        ]);
        $now = now();
        $count = 0;

        $query->select('id')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($users) use (&$count, $data, $now): void {
                $notifications = $users->map(fn (User $user) => [
                    'id' => Str::uuid()->toString(),
                    'type' => 'App\\Notifications\\AdminBroadcast',
                    'notifiable_type' => 'App\\Models\\User',
                    'notifiable_id' => $user->id,
                    'data' => $data,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($notifications !== []) {
                    DB::table('notifications')->insert($notifications);
                    $count += count($notifications);
                }
            });

        return redirect()->route('admin.notifications.index')
            ->withSuccess("Notification broadcast to {$count} users.");
    }

    public function destroy(string $id): RedirectResponse
    {
        DB::table('notifications')->where('id', $id)->delete();
        return redirect()->route('admin.notifications.index')
            ->withSuccess('Notification deleted.');
    }

    public function clear(): RedirectResponse
    {
        DB::table('notifications')->delete();
        return redirect()->route('admin.notifications.index')
            ->withSuccess('All notifications cleared.');
    }
}
