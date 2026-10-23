<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class ApiTokenController
{
    public function index(): View
    {
        $tokens = DB::table('personal_access_tokens')
            ->leftJoin('users', 'personal_access_tokens.tokenable_id', '=', 'users.id')
            ->select('personal_access_tokens.*', 'users.username', 'users.email as user_email')
            ->orderByDesc('personal_access_tokens.created_at')
            ->paginate(\App\Support\AdminPagination::perPage(null, 50));

        $stats = [
            'total' => DB::table('personal_access_tokens')->count(),
            'used_today' => DB::table('personal_access_tokens')->whereDate('last_used_at', today())->count(),
            'never_used' => DB::table('personal_access_tokens')->whereNull('last_used_at')->count(),
        ];

        return view('admin.api-tokens.index', compact('tokens', 'stats'));
    }

    public function destroy(int $id): RedirectResponse
    {
        DB::table('personal_access_tokens')->where('id', $id)->delete();
        return redirect()->route('admin.api-tokens.index')->withSuccess('Token revoked.');
    }

    public function revokeAll(): RedirectResponse
    {
        DB::table('personal_access_tokens')->truncate();
        return redirect()->route('admin.api-tokens.index')->withSuccess('All tokens revoked.');
    }
}
