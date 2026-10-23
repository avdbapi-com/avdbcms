<?php

namespace App\Http\Controllers\Admin;

use App\Data\NotificationBroadcastSettings;
use App\Support\AdminCache;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BroadcastController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            // The new admin: the audience sizes (two queries) kept for a minute, the 10 latest notifications.
            $audience = AdminCache::remember('broadcast.audience.v1', now()->addMinute(), function (): array {
                $users = DB::table('users')->selectRaw('COUNT(*) as total, SUM(CASE WHEN email_verified_at IS NULL THEN 0 ELSE 1 END) as verified')->first();

                return [
                    'all' => (int) ($users->total ?? 0),
                    'verified' => (int) ($users->verified ?? 0),
                    'premium' => DB::query()->fromSub($this->activePremiumUsersQuery(), 'active_premium_users')->count(),
                ];
            });

            return view('admin.broadcast.form', [
                'audience' => $audience,
                'recent' => DB::table('notifications')->select('id', 'data', 'type', 'created_at')->orderByDesc('created_at')->limit(10)->get(),
            ]);
        }

        $stats = [
            'total_users' => DB::table('users')->count(),
            'verified_users' => DB::table('users')->whereNotNull('email_verified_at')->count(),
            'premium_users' => DB::query()->fromSub($this->activePremiumUsersQuery(), 'active_premium_users')->count(),
            'recent_notifications' => DB::table('notifications')->orderByDesc('created_at')->limit(10)->get(),
        ];

        return view('admin.broadcast.index', compact('stats'));
    }

    public function sendNotification(Request $request): RedirectResponse
    {
        $request->validate([
            'title' => 'required|string|max:200',
            'message' => 'required|string|max:5000',
            'target' => 'required|in:all,verified,premium,specific',
            'user_ids' => 'nullable|string',
        ]);

        $query = DB::table('users');
        switch ($request->target) {
            case 'verified':
                $query->whereNotNull('email_verified_at');
                break;
            case 'premium':
                $query->whereIn('id', $this->activePremiumUsersQuery());
                break;
            case 'specific':
                $ids = collect(explode(',', (string) $request->user_ids))
                    ->map(fn (string $id) => trim($id))
                    ->filter(fn (string $id) => ctype_digit($id))
                    ->unique()
                    ->values()
                    ->all();

                $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('id', $ids);
                break;
        }

        $now = now();
        $count = 0;
        $data = json_encode(['title' => $request->title, 'message' => $request->message]);
        $chunkSize = NotificationBroadcastSettings::fromConfig()->chunkSize;

        $query->select('id')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($users) use (&$count, $data, $now): void {
                $notifications = $users->map(fn ($user) => [
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

        return redirect()->route('admin.broadcast.index')
            ->withSuccess("Notification sent to {$count} users.");
    }

    private function activePremiumUsersQuery(): Builder
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
