<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    /** The sorts of Manage subscriptions. */
    public const SORTS = ['recent', 'oldest', 'name'];

    /** Channel chips at the top of the subscriptions feed. */
    public const FEED_CHIPS = 12;

    /** The longest search text that is used. */
    public const MAX_SEARCH = 100;

    /**
     * The subscriptions feed /subscriptions (member design 6.11, QA channel-23): a page head with Manage, the
     * followed channels as chips and the feed of their videos (the site's feed, with the "subscriptions" list ad).
     */
    public function index(): View
    {
        if (!Auth::check()) {
            return view('subscription.index', [
                'subscription_count' => 0,
                'channels' => collect(),
            ]);
        }

        $subscriptions = Auth::user()->subscriptions();

        return view('subscription.index', [
            'subscription_count' => (clone $subscriptions)->count(),
            'channels' => (clone $subscriptions)
                ->latest('subscriptions.subscribe_at')
                ->latest('users.id')
                ->limit(self::FEED_CHIPS)
                ->get(),
        ]);
    }

    /**
     * Manage subscriptions /subscriptions/manage (QA channel-24, channel-25): compact rows, search, sort and pages; a
     * channel that hides its subscriber count (or a site that hides counts) shows "Count hidden".
     */
    public function manage(Request $request): View
    {
        $search = is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, self::MAX_SEARCH) : '';
        $sort = in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'recent';
        $total = Auth::user()->subscriptions()->count();

        $subscriptions = Auth::user()
            ->subscriptions()
            ->withCount([
                'subscribers',
                'videos' => fn ($query) => $query->active()->forLocale(),
            ])
            ->when($search !== '', fn ($query) => $query->where('users.username', 'like', '%' . addcslashes($search, '\\%_') . '%'))
            ->when($sort === 'recent', fn ($query) => $query->orderByDesc('subscriptions.subscribe_at')->orderByDesc('users.id'))
            ->when($sort === 'oldest', fn ($query) => $query->orderBy('subscriptions.subscribe_at')->orderBy('users.id'))
            ->when($sort === 'name', fn ($query) => $query->orderBy('users.username')->orderBy('users.id'))
            ->paginate(max(1, (int) config('site.per_page_users', 15)))
            ->withQueryString();

        return view('subscription.manage', [
            'subscriptions' => $subscriptions,
            'total' => $total,
            'search' => $search,
            'sort' => $sort,
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

        $users = User::query()
            ->active()
            // a creator who hides how many people subscribe is not listed (Account settings > Channel says so); in the
            // query, before the limit, so the list keeps its length
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
            ->orderBy('users.id', 'asc')
            ->limit(config('site.discover_users_limit', 24))
            ->get();

        return $users;
    }
}
