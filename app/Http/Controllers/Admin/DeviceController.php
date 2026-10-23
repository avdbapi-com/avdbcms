<?php

namespace App\Http\Controllers\Admin;

use App\Models\Device;
use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.devices.list', $this->listPage($request));
        }

        $devices = Device::query()
            ->with('user')
            ->latest('last_used_at')
            ->paginate(\App\Support\AdminPagination::perPage(null, 30));

        $stats = [
            'total'   => Device::count(),
            'active'  => Device::whereNull('expires_at')->orWhere('expires_at', '>', now())->count(),
            'expired' => Device::where('expires_at', '<', now())->count(),
        ];

        return view('admin.devices.index', compact('devices', 'stats'));
    }

    /**
     * The new admin's list: 25 per page, the latest used first, all or only the ended ones (?state=expired); the
     * members in one query, the two counts in one, kept for a minute.
     */
    private function listPage(Request $request): array
    {
        $expired = $request->query('state') === 'expired';

        $devices = Device::query()
            ->with('user:id,username,email')
            ->when($expired, fn ($query) => $query->where('expires_at', '<', now()))
            ->latest('last_used_at')
            ->paginate(AdminPagination::perPage($request, 25))
            ->withQueryString();

        $stats = AdminCache::remember('devices.counts.v1', now()->addMinute(), function (): array {
            $row = DB::table('personal_access_tokens')
                ->selectRaw('COUNT(*) as total, SUM(CASE WHEN expires_at < ? THEN 1 ELSE 0 END) as expired', [now()])
                ->first();

            return ['total' => (int) ($row->total ?? 0), 'expired' => (int) ($row->expired ?? 0)];
        });

        return compact('devices', 'stats', 'expired');
    }

    public function destroy(Device $device): RedirectResponse
    {
        $device->delete();
        return redirect()->route('admin.devices.index')->withSuccess('Session/token revoked.');
    }

    public function revokeUser(int $userId): RedirectResponse
    {
        DB::table('personal_access_tokens')->where('tokenable_id', $userId)->delete();
        return redirect()->route('admin.devices.index')->withSuccess('All sessions for user revoked.');
    }

    public function clearExpired(): RedirectResponse
    {
        DB::table('personal_access_tokens')->where('expires_at', '<', now())->delete();
        return redirect()->route('admin.devices.index')->withSuccess('Expired sessions cleared.');
    }
}
