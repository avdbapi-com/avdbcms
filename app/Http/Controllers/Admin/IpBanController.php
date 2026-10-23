<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IpBanController
{
    public function index(Request $request): View
    {
        $bans = DB::table('ip_bans')
            ->orderByDesc('created_at')
            ->paginate(\App\Support\AdminPagination::perPage(null, 25));

        $stats = [
            'total' => DB::table('ip_bans')->count(),
            'permanent' => DB::table('ip_bans')->whereNull('expires_at')->count(),
            'temporary' => DB::table('ip_bans')->whereNotNull('expires_at')->count(),
            'active' => DB::table('ip_bans')
                ->where(function($q) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
                })->count(),
        ];

        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.ip-bans.list', ['bans' => $bans->withQueryString(), 'stats' => $stats]);
        }

        return view('admin.ip-bans.index', compact('bans', 'stats'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'ip_address' => 'required|ip',
            'reason' => 'nullable|string|max:500',
            'duration' => 'nullable|integer|min:0',
        ]);

        // Check if exists
        if (DB::table('ip_bans')->where('ip_address', $request->ip_address)->exists()) {
            return redirect()->route('admin.ip-bans.index')
                ->withErrors(['ip_address' => 'This IP is already banned.']);
        }

        $duration = (int) $request->input('duration', 0);

        DB::table('ip_bans')->insert([
            'ip_address' => $request->ip_address,
            'reason' => $request->reason ?? '',
            'expires_at' => $duration > 0 ? now()->addHours($duration) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.ip-bans.index')
            ->withSuccess("IP {$request->ip_address} banned successfully.");
    }

    public function destroy(int $id): RedirectResponse
    {
        DB::table('ip_bans')->where('id', $id)->delete();
        return redirect()->route('admin.ip-bans.index')->withSuccess('IP ban removed.');
    }

    public function clearExpired(): RedirectResponse
    {
        $count = DB::table('ip_bans')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();

        return redirect()->route('admin.ip-bans.index')
            ->withSuccess("Cleared {$count} expired bans.");
    }
}
