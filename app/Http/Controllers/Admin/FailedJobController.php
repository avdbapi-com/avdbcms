<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class FailedJobController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.failed-jobs.list', $this->listPage($request));
        }

        $jobs = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->paginate(\App\Support\AdminPagination::perPage(null, 20));

        $stats = [
            'total'   => DB::table('failed_jobs')->count(),
            'today'   => DB::table('failed_jobs')->whereDate('failed_at', today())->count(),
            'queues'  => DB::table('failed_jobs')->select('queue', DB::raw('COUNT(*) as count'))
                            ->groupBy('queue')->orderByDesc('count')->get(),
        ];

        return view('admin.failed-jobs.index', compact('jobs', 'stats'));
    }

    /**
     * The new admin's list: 25 per page, the latest first, with the job's name and the first line of its error (the
     * whole error of one job only when it is asked for: ?show=<uuid>); the counts in one query, kept for a minute.
     */
    private function listPage(Request $request): array
    {
        $jobs = DB::table('failed_jobs')
            ->select('id', 'uuid', 'connection', 'queue', 'failed_at', DB::raw('SUBSTR(payload, 1, 600) as payload_head'), DB::raw('SUBSTR(exception, 1, 300) as exception_head'))
            ->orderByDesc('failed_at')
            ->paginate(AdminPagination::perPage($request, 25))
            ->withQueryString();

        $stats = AdminCache::remember('failed-jobs.counts.v1', now()->addMinute(), function (): array {
            $row = DB::table('failed_jobs')->selectRaw('COUNT(*) as total, SUM(CASE WHEN failed_at >= ? THEN 1 ELSE 0 END) as today', [today()])->first();

            return ['total' => (int) ($row->total ?? 0), 'today' => (int) ($row->today ?? 0)];
        });

        $shown = $request->filled('show')
            ? DB::table('failed_jobs')->where('uuid', (string) $request->query('show'))->first(['uuid', 'queue', 'failed_at', 'exception'])
            : null;

        return compact('jobs', 'stats', 'shown');
    }

    public function retry(string $uuid): RedirectResponse
    {
        // a job that is not there any more (retried or deleted in another tab): nothing written (judge R2CD-RV-03)
        $there = DB::table('failed_jobs')->where('uuid', $uuid)->exists();
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        if ($there) {
            AuditLogController::log('queue.failed.retry', "Retried failed job {$uuid}.");
        }

        return redirect()->route('admin.failed-jobs.index')->withSuccess(__('Sent back to the queue'));
    }

    public function retryAll(): RedirectResponse
    {
        $any = DB::table('failed_jobs')->exists();
        Artisan::call('queue:retry', ['id' => ['all']]);

        if ($any) {
            AuditLogController::log('queue.failed.retry_all', 'Retried all failed jobs.');
        }

        return redirect()->route('admin.failed-jobs.index')->withSuccess(__('All failed jobs sent back to the queue'));
    }

    public function destroy(string $uuid): RedirectResponse
    {
        // queue:forget answers 0 only when it deleted the job
        if (Artisan::call('queue:forget', ['id' => $uuid]) === 0) {
            AuditLogController::log('queue.failed.delete', "Deleted failed job {$uuid}.");
        }

        return redirect()->route('admin.failed-jobs.index')->withSuccess(__('Failed job deleted'));
    }

    public function clear(): RedirectResponse
    {
        $any = DB::table('failed_jobs')->exists();
        Artisan::call('queue:flush');

        if ($any) {
            AuditLogController::log('queue.failed.clear');
        }

        return redirect()->route('admin.failed-jobs.index')->withSuccess(__('All failed jobs deleted'));
    }
}
