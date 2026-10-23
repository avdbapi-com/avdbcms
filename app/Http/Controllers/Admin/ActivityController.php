<?php

namespace App\Http\Controllers\Admin;

use App\Models\Activity;
use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.activities.list', $this->listPage($request));
        }

        $activities = DB::table('activities')
            ->join('users', 'activities.user_id', '=', 'users.id')
            ->select('activities.*', 'users.username', 'users.email')
            ->orderByDesc('activities.perform_at')
            ->paginate(\App\Support\AdminPagination::perPage(null, 30));

        $stats = [
            'total'   => DB::table('activities')->count(),
            'today'   => DB::table('activities')->whereDate('perform_at', today())->count(),
            'types'   => DB::table('activities')->select('subject_type', DB::raw('COUNT(*) as count'))
                            ->groupBy('subject_type')->orderByDesc('count')->get(),
        ];

        return view('admin.activities.index', compact('activities', 'stats'));
    }

    /**
     * The new admin's list: 25 per page, the latest first, one kind of activity (?type=comment = the subject's class
     * name); the counts per kind (and today's) in one query, kept for a minute.
     */
    private function listPage(Request $request): array
    {
        $kinds = AdminCache::remember('activities.kinds.v1', now()->addMinute(), fn (): array => DB::table('activities')
            ->selectRaw('subject_type, COUNT(*) as total, SUM(CASE WHEN perform_at >= ? THEN 1 ELSE 0 END) as today', [today()])
            ->groupBy('subject_type')
            ->orderByDesc('total')
            ->get()
            ->mapWithKeys(fn ($row) => [strtolower(class_basename((string) $row->subject_type)) => ['class' => (string) $row->subject_type, 'total' => (int) $row->total, 'today' => (int) $row->today]])
            ->all());
        $type = array_key_exists((string) $request->query('type'), $kinds) ? (string) $request->query('type') : null;

        $activities = DB::table('activities')
            ->join('users', 'activities.user_id', '=', 'users.id')
            ->select('activities.id', 'activities.user_id', 'activities.subject_type', 'activities.subject_id', 'activities.perform_at', 'users.username', 'users.email')
            ->when($type !== null, fn ($query) => $query->where('activities.subject_type', $kinds[$type]['class']))
            ->orderByDesc('activities.perform_at')
            ->paginate(AdminPagination::perPage($request, 25))
            ->withQueryString();

        return compact('activities', 'kinds', 'type');
    }

    public function destroy(int $id): RedirectResponse
    {
        DB::table('activities')->where('id', $id)->delete();
        return redirect()->route('admin.activities.index')->withSuccess('Activity deleted.');
    }

    public function clear(): RedirectResponse
    {
        DB::table('activities')->delete();
        return redirect()->route('admin.activities.index')->withSuccess('All activities cleared.');
    }
}
