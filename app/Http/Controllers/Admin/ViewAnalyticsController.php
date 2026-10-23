<?php

namespace App\Http\Controllers\Admin;

use App\Models\Video;
use App\Services\ViewHistoryRetentionService;
use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ViewAnalyticsController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.views.list', $this->listPage($request));
        }

        $dailyStatsReady = Schema::hasTable('video_view_daily_stats');

        $views = DB::table('views')
            ->leftJoin('videos', 'views.video_id', '=', 'videos.id')
            ->leftJoin('users', 'views.user_id', '=', 'users.id')
            ->select(
                'views.*',
                'videos.title as video_title',
                'videos.uuid as video_uuid',
                'users.username',
            )
            ->orderByDesc('views.view_at')
            ->paginate(\App\Support\AdminPagination::perPage(null, 30));

        $rawStats = DB::table('views')->selectRaw('
            COUNT(DISTINCT ip) as unique_ips,
            COUNT(DISTINCT user_id) as logged_in_viewers
        ')->first();

        $todayViews = $dailyStatsReady
            ? (int) DB::table('video_view_daily_stats')->where('view_date', now()->toDateString())->sum('views_count')
            : (int) DB::table('views')->whereDate('view_at', now()->toDateString())->count();

        $stats = (object) [
            'total' => (int) Video::query()->sum('views'),
            'today' => $todayViews,
            'unique_videos' => (int) Video::query()->where('views', '>', 0)->count(),
            'unique_ips' => (int) ($rawStats->unique_ips ?? 0),
            'logged_in_viewers' => (int) ($rawStats->logged_in_viewers ?? 0),
        ];

        $topVideos = Video::query()
            ->where('views', '>', 0)
            ->orderByDesc('views')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['title', 'uuid', 'views'])
            ->map(fn (Video $video) => (object) [
                'title' => $video->title,
                'uuid' => $video->uuid,
                'view_count' => (int) $video->views,
            ]);

        $viewLogMode = app(ViewHistoryRetentionService::class)->logMode();

        return view('admin.views.index', compact('views', 'stats', 'topVideos', 'viewLogMode'));
    }

    /**
     * The new admin's list: the latest views, 25 per page (without the browser text), and the same numbers as the
     * old page (totals from the video counter, today's from the daily table when it exists, the 10 most watched),
     * worked out once a minute.
     */
    private function listPage(Request $request): array
    {
        $views = DB::table('views')
            ->leftJoin('videos', 'views.video_id', '=', 'videos.id')
            ->leftJoin('users', 'views.user_id', '=', 'users.id')
            ->select('views.id', 'views.video_id', 'views.user_id', 'views.ip', 'views.view_at', 'videos.title as video_title', 'users.username')
            ->orderByDesc('views.view_at')
            ->paginate(AdminPagination::perPage($request, 25))
            ->withQueryString();

        $summary = AdminCache::remember('views.summary.v1', now()->addMinute(), function (): array {
            $history = DB::table('views')->selectRaw('COUNT(DISTINCT ip) as unique_ips, COUNT(DISTINCT user_id) as logged_in_viewers')->first();
            $videos = Video::query()->toBase()->selectRaw('COALESCE(SUM(views), 0) as total, SUM(CASE WHEN views > 0 THEN 1 ELSE 0 END) as watched')->first();

            return [
                'total' => (int) ($videos->total ?? 0),
                'watched' => (int) ($videos->watched ?? 0),
                'today' => Schema::hasTable('video_view_daily_stats')
                    ? (int) DB::table('video_view_daily_stats')->where('view_date', now()->toDateString())->sum('views_count')
                    : (int) DB::table('views')->whereDate('view_at', now()->toDateString())->count(),
                'unique_ips' => (int) ($history->unique_ips ?? 0),
                'logged_in_viewers' => (int) ($history->logged_in_viewers ?? 0),
                'top' => Video::query()->where('views', '>', 0)->orderByDesc('views')->orderByDesc('id')->limit(10)
                    ->get(['id', 'title', 'views'])
                    ->map(fn (Video $video): array => ['id' => $video->id, 'title' => (string) $video->title, 'views' => (int) $video->views])
                    ->all(),
            ];
        });

        return ['views' => $views, 'summary' => $summary, 'viewLogMode' => app(ViewHistoryRetentionService::class)->logMode()];
    }

    public function destroy(int $id): RedirectResponse
    {
        DB::table('views')->where('id', $id)->delete();
        return redirect()->route('admin.views.index')->withSuccess('View record deleted.');
    }

    public function clear(): RedirectResponse
    {
        DB::table('views')->delete();
        return redirect()->route('admin.views.index')->withSuccess('All view records cleared.');
    }
}
