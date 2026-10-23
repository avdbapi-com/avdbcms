<?php

namespace App\Http\Controllers\Admin;

use App\Models\Search;
use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchHistoryController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.searches.list', $this->listPage($request));
        }

        // Get top searched queries with counts
        $topQueries = DB::table('searches')
            ->select('query', DB::raw('COUNT(*) as count'), DB::raw('MAX(results) as max_results'), DB::raw('MAX(perform_at) as last_searched'))
            ->groupBy('query')
            ->orderByDesc('count')
            ->paginate(\App\Support\AdminPagination::perPage(null, 30));

        $totalSearches = DB::table('searches')->count();
        $zeroResults = DB::table('searches')->where('results', 0)->count();

        return view('admin.searches.index', compact('topQueries', 'totalSearches', 'zeroResults'));
    }

    /**
     * The new admin's list: what people searched for, most searched first, 25 per page, all or only the searches
     * that never found anything (?none=1); the two counts in one query, kept for a minute.
     */
    private function listPage(Request $request): array
    {
        $none = $request->boolean('none');

        $queries = DB::table('searches')
            ->select('query', DB::raw('COUNT(*) as count'), DB::raw('MAX(results) as max_results'), DB::raw('MAX(perform_at) as last_searched'))
            ->groupBy('query')
            ->when($none, fn ($query) => $query->havingRaw('MAX(results) = 0'))
            ->orderByDesc('count')
            ->orderBy('query')
            ->paginate(AdminPagination::perPage($request, 25))
            ->withQueryString();

        $stats = AdminCache::remember('searches.counts.v1', now()->addMinute(), function (): array {
            $row = DB::table('searches')->selectRaw('COUNT(*) as total, SUM(CASE WHEN results = 0 THEN 1 ELSE 0 END) as zero')->first();

            return ['total' => (int) ($row->total ?? 0), 'zero' => (int) ($row->zero ?? 0)];
        });

        return compact('queries', 'stats', 'none');
    }

    public function destroy(int $id): RedirectResponse
    {
        DB::table('searches')->where('id', $id)->delete();
        return redirect()->route('admin.searches.index')
            ->withSuccess('Search record deleted.');
    }

    public function clear(): RedirectResponse
    {
        DB::table('searches')->delete();
        return redirect()->route('admin.searches.index')
            ->withSuccess('All search history cleared.');
    }
}
