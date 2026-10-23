<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class SessionController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.sessions.list', $this->listPage($request));
        }

        // Try to get sessions from DB (if session driver is database)
        $sessions = collect();
        $driver = config('session.driver');

        if ($driver === 'database') {
            $sessions = DB::table('sessions')
                ->leftJoin('users', 'sessions.user_id', '=', 'users.id')
                ->select('sessions.*', 'users.username', 'users.email as user_email')
                ->orderByDesc('sessions.last_activity')
                ->paginate(\App\Support\AdminPagination::perPage(null, 50));
        }

        $stats = [
            'driver' => $driver,
            'lifetime' => config('session.lifetime') . ' minutes',
            'expire_on_close' => config('session.expire_on_close') ? 'Yes' : 'No',
            'encrypt' => config('session.encrypt') ? 'Yes' : 'No',
            'total' => $driver === 'database' ? DB::table('sessions')->count() : 'N/A (not DB driver)',
            'authenticated' => $driver === 'database' ? DB::table('sessions')->whereNotNull('user_id')->count() : 'N/A',
            'guests' => $driver === 'database' ? DB::table('sessions')->whereNull('user_id')->count() : 'N/A',
        ];

        return view('admin.sessions.index', compact('sessions', 'stats', 'driver'));
    }

    /**
     * The new admin's list (only the database session store keeps sessions in a table): 25 per page, the latest
     * first, everyone / members / visitors (?who=); the session data itself is not read. The counts come from one
     * query, kept for a minute.
     */
    private function listPage(Request $request): array
    {
        $driver = (string) config('session.driver');

        if ($driver !== 'database') {
            return ['driver' => $driver, 'sessions' => null, 'stats' => null, 'who' => null, 'current' => null];
        }

        $who = in_array($request->query('who'), ['members', 'visitors'], true) ? (string) $request->query('who') : null;

        $sessions = DB::table('sessions')
            ->leftJoin('users', 'sessions.user_id', '=', 'users.id')
            ->select('sessions.id', 'sessions.user_id', 'sessions.ip_address', 'sessions.user_agent', 'sessions.last_activity', 'users.username', 'users.email as user_email')
            ->when($who === 'members', fn ($query) => $query->whereNotNull('sessions.user_id'))
            ->when($who === 'visitors', fn ($query) => $query->whereNull('sessions.user_id'))
            ->orderByDesc('sessions.last_activity')
            ->paginate(AdminPagination::perPage($request, 25))
            ->withQueryString();

        $stats = AdminCache::remember('sessions.counts.v1', now()->addMinute(), function (): array {
            $row = DB::table('sessions')->selectRaw('COUNT(*) as total, SUM(CASE WHEN user_id IS NULL THEN 0 ELSE 1 END) as members')->first();
            $total = (int) ($row->total ?? 0);

            return ['total' => $total, 'members' => (int) ($row->members ?? 0), 'visitors' => $total - (int) ($row->members ?? 0)];
        });

        return ['driver' => $driver, 'sessions' => $sessions, 'stats' => $stats, 'who' => $who, 'current' => $request->session()->getId()];
    }

    public function destroy(string $id): RedirectResponse
    {
        DB::table('sessions')->where('id', $id)->delete();
        return redirect()->route('admin.sessions.index')->withSuccess('Session terminated.');
    }

    public function clearAll(): RedirectResponse
    {
        $currentId = session()->getId();
        DB::table('sessions')->where('id', '!=', $currentId)->delete();
        return redirect()->route('admin.sessions.index')->withSuccess('All other sessions terminated.');
    }

    public function clearGuests(): RedirectResponse
    {
        DB::table('sessions')->whereNull('user_id')->delete();
        return redirect()->route('admin.sessions.index')->withSuccess('Guest sessions cleared.');
    }
}
