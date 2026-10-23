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

        return redirect()->route('admin.redirects.index')
            ->withSuccess('Redirect rule created.');
    }

    public function destroy(int $id): RedirectResponse
    {
        DB::table('redirects')->where('id', $id)->delete();
        return redirect()->route('admin.redirects.index')
            ->withSuccess('Redirect deleted.');
    }

    public function toggle(int $id): RedirectResponse
    {
        $current = DB::table('redirects')->where('id', $id)->value('is_active');
        DB::table('redirects')->where('id', $id)->update(['is_active' => !$current, 'updated_at' => now()]);
        return redirect()->route('admin.redirects.index')
            ->withSuccess('Redirect ' . (!$current ? 'activated' : 'deactivated') . '.');
    }
}
