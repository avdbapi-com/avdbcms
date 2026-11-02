<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            // The new admin: 25 per page (50 or 100 on request), the latest first; the counts in one query, kept for
            // a minute.
            return view('admin.audit.list', [
                'logs' => DB::table('admin_audit_logs')
                    ->leftJoin('users', 'admin_audit_logs.user_id', '=', 'users.id')
                    ->select('admin_audit_logs.id', 'admin_audit_logs.user_id', 'admin_audit_logs.action', 'admin_audit_logs.details', 'admin_audit_logs.ip_address', 'admin_audit_logs.created_at', 'users.username')
                    // Newest first; rows of the same second by their id, so the order never flips and no page repeats
                    // or skips a row.
                    ->orderByDesc('admin_audit_logs.created_at')
                    ->orderByDesc('admin_audit_logs.id')
                    ->paginate(AdminPagination::perPage($request, 25))
                    ->withQueryString(),
                'stats' => AdminCache::remember('audit.counts.v1', now()->addMinute(), function (): array {
                    $row = DB::table('admin_audit_logs')->selectRaw('COUNT(*) as total, SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as today, COUNT(DISTINCT user_id) as admins', [today()])->first();

                    return ['total' => (int) ($row->total ?? 0), 'today' => (int) ($row->today ?? 0), 'admins' => (int) ($row->admins ?? 0)];
                }),
            ]);
        }

        $logs = DB::table('admin_audit_logs')
            ->leftJoin('users', 'admin_audit_logs.user_id', '=', 'users.id')
            ->select('admin_audit_logs.*', 'users.username', 'users.email as user_email')
            ->orderByDesc('admin_audit_logs.created_at')
            ->orderByDesc('admin_audit_logs.id')
            ->paginate(\App\Support\AdminPagination::perPage(null, 50));

        $stats = [
            'total' => DB::table('admin_audit_logs')->count(),
            'today' => DB::table('admin_audit_logs')->whereDate('created_at', today())->count(),
            'admins' => DB::table('admin_audit_logs')->distinct('user_id')->count('user_id'),
        ];

        return view('admin.audit.index', compact('logs', 'stats'));
    }

    public function clear(): RedirectResponse
    {
        $rows = DB::table('admin_audit_logs')->exists();
        DB::table('admin_audit_logs')->truncate();
        // The emptied log starts with who emptied it and when (R2A-020); an empty log stays empty (its empty state stays
        // true; judge R2CD-RV-03).
        if ($rows) {
            self::log('audit.cleared');
        }
        AdminCache::forget('audit.counts.v1');

        return redirect()->route('admin.audit.index')->withSuccess(__('Audit log cleared.'));
    }

    /**
     * Log admin actions (called by other controllers)
     */
    public static function log(string $action, ?string $details = null, ?int $userId = null): void
    {
        try {
            DB::table('admin_audit_logs')->insert([
                'user_id' => $userId ?? auth()->id(),
                'action' => $action,
                'details' => $details,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Silent fail - audit logging should never break operations
        }
    }
}
