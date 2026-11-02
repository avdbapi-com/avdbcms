<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RedirectController
{
    public function index(Request $request): View
    {
        $redirects = DB::table('redirects')
            ->orderByDesc('created_at')
            ->paginate(\App\Support\AdminPagination::perPage(null, 25));

        return AdminPreferences::usesNewAdmin($request->user())
            ? view('admin.redirects.list', ['redirects' => $redirects->withQueryString()])
            : view('admin.redirects.index', compact('redirects'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'source' => 'required|string|max:500',
            'destination' => 'required|string|max:500',
            'status_code' => 'required|in:301,302,307',
            'is_active' => 'nullable|boolean',
        ]);

        DB::table('redirects')->insert([
            'source' => $request->source,
            'destination' => $request->destination,
            'status_code' => $request->status_code,
            'is_active' => $request->has('is_active'),
            'hits' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AuditLogController::log('redirect.created', self::describe((object) $request->only('source', 'destination', 'status_code')));

        return redirect()->route('admin.redirects.index')
            ->withSuccess(__('Redirect created'));
    }

    public function destroy(int $id): RedirectResponse
    {
        $redirect = DB::table('redirects')->where('id', $id)->first();

        if ($redirect !== null && DB::table('redirects')->where('id', $id)->delete() > 0) {
            AuditLogController::log('redirect.deleted', self::describe($redirect));
        }

        return redirect()->route('admin.redirects.index')
            ->withSuccess(__('Redirect deleted'));
    }

    public function toggle(int $id): RedirectResponse
    {
        $redirect = DB::table('redirects')->where('id', $id)->first();
        $current = $redirect?->is_active;

        if ($redirect !== null) {
            DB::table('redirects')->where('id', $id)->update(['is_active' => ! $current, 'updated_at' => now()]);
            AuditLogController::log($current ? 'redirect.off' : 'redirect.on', self::describe($redirect));
        }

        return redirect()->route('admin.redirects.index')
            ->withSuccess($current ? __('Redirect turned off') : __('Redirect turned on'));
    }

    /**
     * A redirect in Tools > Logs > Admin actions (R3-019): "/old-page -> /new-page (301)", the site's own addresses and
     * the code, read left to right in every language (App\Support\AuditDetails sets them apart).
     */
    private static function describe(object $redirect): string
    {
        $cut = static fn (mixed $address): string => \Illuminate\Support\Str::limit(trim((string) $address), 200);

        return $cut($redirect->source ?? '').' -> '.$cut($redirect->destination ?? '').' ('.(int) ($redirect->status_code ?? 0).')';
    }
}
