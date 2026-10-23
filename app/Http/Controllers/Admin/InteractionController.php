<?php

namespace App\Http\Controllers\Admin;

use App\Models\Video;
use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InteractionController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.interactions.list', $this->listPage($request));
        }

        $interactions = DB::table('interactions')
            ->join('users', 'interactions.user_id', '=', 'users.id')
            ->select('interactions.*', 'users.username', 'users.email')
            ->orderByDesc('interactions.perform_at')
            ->paginate(\App\Support\AdminPagination::perPage(null, 30));

        $stats = DB::table('interactions')->selectRaw('
            COUNT(*) as total,
            SUM(CASE WHEN interactions.status = 1 THEN 1 ELSE 0 END) as likes,
            SUM(CASE WHEN interactions.status = 0 THEN 1 ELSE 0 END) as dislikes,
            COUNT(DISTINCT user_id) as unique_users
        ')->first();

        $stats->today = DB::table('interactions')
            ->whereDate('perform_at', Carbon::today())
            ->count();

        $topLiked = DB::table('interactions')
            ->where('interactions.likeable_type', 'App\Models\Video')
            ->where('interactions.status', 1)
            ->join('videos', 'interactions.likeable_id', '=', 'videos.id')
            ->select('videos.title', 'videos.uuid', DB::raw('COUNT(*) as likes'))
            ->groupBy('interactions.likeable_id', 'videos.title', 'videos.uuid')
            ->orderByDesc('likes')
            ->limit(10)
            ->get();

        return view('admin.interactions.index', compact('interactions', 'stats', 'topLiked'));
    }

    /**
     * The new admin's list: 25 per page, the latest first, likes or dislikes only (?kind=), with the liked video's
     * title (one join); the counts (today's too) in one query and the 10 most liked videos, kept for a minute.
     */
    private function listPage(Request $request): array
    {
        $kind = in_array($request->query('kind'), ['likes', 'dislikes'], true) ? (string) $request->query('kind') : null;

        $interactions = DB::table('interactions')
            ->join('users', 'interactions.user_id', '=', 'users.id')
            ->leftJoin('videos', fn ($join) => $join->on('interactions.likeable_id', '=', 'videos.id')->where('interactions.likeable_type', '=', Video::class))
            ->select('interactions.id', 'interactions.user_id', 'interactions.likeable_type', 'interactions.likeable_id', 'interactions.status', 'interactions.perform_at', 'users.username', 'users.email', 'videos.title as video_title')
            ->when($kind !== null, fn ($query) => $query->where('interactions.status', $kind === 'likes' ? 1 : 0))
            ->orderByDesc('interactions.perform_at')
            ->paginate(AdminPagination::perPage($request, 25))
            ->withQueryString();

        $summary = AdminCache::remember('interactions.summary.v1', now()->addMinute(), function (): array {
            $row = DB::table('interactions')->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as likes,
                SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as dislikes,
                COUNT(DISTINCT user_id) as members,
                SUM(CASE WHEN perform_at >= ? THEN 1 ELSE 0 END) as today
            ', [Carbon::today()])->first();

            return [
                'total' => (int) ($row->total ?? 0),
                'likes' => (int) ($row->likes ?? 0),
                'dislikes' => (int) ($row->dislikes ?? 0),
                'members' => (int) ($row->members ?? 0),
                'today' => (int) ($row->today ?? 0),
                'top' => DB::table('interactions')
                    ->where('interactions.likeable_type', Video::class)
                    ->where('interactions.status', 1)
                    ->join('videos', 'interactions.likeable_id', '=', 'videos.id')
                    ->select('videos.id', 'videos.title', DB::raw('COUNT(*) as likes'))
                    ->groupBy('videos.id', 'videos.title')
                    ->orderByDesc('likes')
                    ->limit(10)
                    ->get()
                    ->map(fn ($video): array => ['id' => (int) $video->id, 'title' => (string) $video->title, 'likes' => (int) $video->likes])
                    ->all(),
            ];
        });

        return compact('interactions', 'summary', 'kind');
    }

    public function destroy(int $id): RedirectResponse
    {
        DB::table('interactions')->where('id', $id)->delete();
        return redirect()->route('admin.interactions.index')->withSuccess('Interaction deleted.');
    }

    public function clear(): RedirectResponse
    {
        DB::table('interactions')->delete();
        return redirect()->route('admin.interactions.index')->withSuccess('All interactions cleared.');
    }
}
