<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PasswordResetController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.password-resets.list', $this->listPage($request));
        }

        $resets = DB::table('password_reset_tokens')
            ->leftJoin('users', 'password_reset_tokens.email', '=', 'users.email')
            ->select(
                'password_reset_tokens.*',
                'users.id as user_id',
                'users.username',
                'users.email_verified_at'
            )
            ->orderByDesc('password_reset_tokens.created_at')
            ->paginate(\App\Support\AdminPagination::perPage(null, 25));

        $stats = [
            'total'  => DB::table('password_reset_tokens')->count(),
            'recent' => DB::table('password_reset_tokens')->where('created_at', '>=', now()->subHour())->count(),
        ];

        return view('admin.password-resets.index', compact('resets', 'stats'));
    }

    /**
     * The new admin's list: 25 per page, the latest first, with the member the address belongs to (the token itself
     * is not read); the two counts in one query, kept for a minute.
     */
    private function listPage(Request $request): array
    {
        $resets = DB::table('password_reset_tokens')
            ->leftJoin('users', 'password_reset_tokens.email', '=', 'users.email')
            ->select('password_reset_tokens.email', 'password_reset_tokens.created_at', 'users.id as user_id', 'users.username', 'users.email_verified_at')
            ->orderByDesc('password_reset_tokens.created_at')
            ->paginate(AdminPagination::perPage($request, 25))
            ->withQueryString();

        $stats = AdminCache::remember('password-resets.counts.v1', now()->addMinute(), function (): array {
            $row = DB::table('password_reset_tokens')
                ->selectRaw('COUNT(*) as total, SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as recent', [now()->subHour()])
                ->first();

            return ['total' => (int) ($row->total ?? 0), 'recent' => (int) ($row->recent ?? 0)];
        });

        return compact('resets', 'stats');
    }

    public function destroy(string $email): RedirectResponse
    {
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        return redirect()->route('admin.password-resets.index')->withSuccess('Token deleted.');
    }

    public function clear(): RedirectResponse
    {
        DB::table('password_reset_tokens')->delete();
        return redirect()->route('admin.password-resets.index')->withSuccess('All reset tokens cleared.');
    }
}
