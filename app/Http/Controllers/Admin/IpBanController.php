<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminIpLock;
use App\Support\AdminPreferences;
use App\Support\BlockedAddresses;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Members > Blocked addresses: the addresses that cannot open the site, each for good or until its end. Since 1.2.3 the
 * site really blocks them (App\Support\BlockedAddresses, checked by the Security middleware on every page; up to 1.2.2
 * this list blocked nobody). An administrator cannot block their own address, and every change takes effect at once.
 */
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

        // Kept in its short form ("2001:db8::7", "203.0.113.7" for "::ffff:203.0.113.7"), as blocks are compared.
        $address = BlockedAddresses::canonical((string) $request->input('ip_address'));
        $own = BlockedAddresses::canonical(AdminIpLock::clientIp($request));

        if ($address !== '' && $address === $own) {
            return redirect()->route('admin.ip-bans.index')
                ->withInput()
                ->withErrors(['ip_address' => __('You cannot block your own address (:ip).', ['ip' => $own])]);
        }

        if (DB::table('ip_bans')->whereIn('ip_address', array_values(array_unique([$address, (string) $request->input('ip_address')])))->exists()) {
            return redirect()->route('admin.ip-bans.index')
                ->withInput()
                ->withErrors(['ip_address' => __('This address is already blocked.')]);
        }

        $duration = (int) $request->input('duration', 0);

        DB::table('ip_bans')->insert([
            'ip_address' => $address,
            'reason' => $request->reason ?? '',
            'expires_at' => $duration > 0 ? now()->addHours($duration) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        BlockedAddresses::forget();
        AuditLogController::log('security.address.blocked', $address);

        return redirect()->route('admin.ip-bans.index')
            ->withSuccess(__(':ip is blocked: it cannot open your site.', ['ip' => $address]));
    }

    public function destroy(int $id): RedirectResponse
    {
        $address = DB::table('ip_bans')->where('id', $id)->value('ip_address');

        if (DB::table('ip_bans')->where('id', $id)->delete() > 0) {
            AuditLogController::log('security.address.unblocked', is_string($address) ? $address : null);
        }

        BlockedAddresses::forget();

        return redirect()->route('admin.ip-bans.index')->withSuccess(__('Address unblocked'));
    }

    public function clearExpired(): RedirectResponse
    {
        $count = DB::table('ip_bans')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();

        BlockedAddresses::forget();

        if ($count > 0) {
            AuditLogController::log('security.address.blocks_cleared', (string) $count);
        }

        return redirect()->route('admin.ip-bans.index')
            ->withSuccess(trans_choice('Cleared 1 ended block.|Cleared :count ended blocks.', $count));
    }
}
