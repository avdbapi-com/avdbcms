<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function index(): View
    {
        if (!Auth::check()) {
            return view('subscription.index', [
                'subscription_count' => 0,
            ]);
        }

        $subscription_count = Auth::user()->subscriptions()->count();

        return view('subscription.index', [
            'subscription_count' => $subscription_count,
        ]);
    }

    public function manage(): View
    {
        return view('subscription.manage', [
            'subscriptions' => Auth::user()
                ->subscriptions()
                ->withCount([
                    'subscribers',
                    'videos' => fn ($query) => $query->active()->forLocale(),
                ])
                ->latest('subscribe_at')
                ->get()
        ]);
    }

    public function discover(): View
    {
        return view('subscription.discover', [
            'users' => $this->discoverUsers(),
        ]);
    }

    private function discoverUsers(): Collection
    {
        $activeVideoCounts = Video::query()->active()->forLocale();

        if (DB::connection()->getDriverName() === 'mysql' && Schema::hasTable('videos') && Schema::hasIndex('videos', 'videos_user_active_count_idx')) {
            $activeVideoCounts->forceIndex('videos_user_active_count_idx');
        }

        $activeVideoCounts
            ->select('user_id')
            ->selectRaw('COUNT(*) AS videos_count')
            ->groupBy('user_id');

        $subscriberCounts = DB::table('subscriptions')
            ->select('user_id')
            ->selectRaw('COUNT(*) AS subscribers_count')
            ->groupBy('user_id');

        return User::query()
            ->active()
            ->where('show_subscribers', true)
            ->joinSub($activeVideoCounts, 'active_video_counts', function ($join): void {
                $join->on('active_video_counts.user_id', '=', 'users.id');
            })
            ->leftJoinSub($subscriberCounts, 'subscriber_counts', function ($join): void {
                $join->on('subscriber_counts.user_id', '=', 'users.id');
            })
            ->when(Auth::check(), fn($query) => $query
                ->whereNot('id', Auth::id())
                ->whereNotIn('id', fn($query) => $query
                    ->select('user_id')
                    ->from('subscriptions')
                    ->where('subscriber_id', Auth::id())))
            ->select('users.*')
            ->selectRaw('active_video_counts.videos_count AS videos_count')
            ->selectRaw('COALESCE(subscriber_counts.subscribers_count, 0) AS subscribers_count')
            ->orderBy('subscribers_count', 'desc')
            ->orderBy('videos_count', 'desc')
            ->orderBy('users.created_at', 'asc')
            ->limit(config('site.discover_users_limit', 24))
            ->get();
    }
}
