<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnnouncementController
{
    public function index(Request $request): View
    {
        $announcements = DB::table('announcements')
            ->orderByDesc('created_at')
            ->paginate(\App\Support\AdminPagination::perPage(null, 25));

        return AdminPreferences::usesNewAdmin($request->user())
            ? view('admin.announcements.list', ['announcements' => $announcements->withQueryString()])
            : view('admin.announcements.index', compact('announcements'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'title' => 'required|string|max:200',
            'message' => 'required|string|max:2000',
            'type' => 'required|in:info,success,warning,danger',
            'position' => 'required|in:top,bottom',
            'is_active' => 'nullable|boolean',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:starts_at',
        ]);

        DB::table('announcements')->insert([
            'title' => $request->title,
            'message' => $request->message,
            'type' => $request->type,
            'position' => $request->position,
            'is_active' => $request->has('is_active'),
            'starts_at' => $request->starts_at,
            'expires_at' => $request->expires_at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.announcements.index')
            ->withSuccess('Announcement created.');
    }

    public function toggle(int $id): RedirectResponse
    {
        $current = DB::table('announcements')->where('id', $id)->value('is_active');
        DB::table('announcements')->where('id', $id)->update([
            'is_active' => !$current,
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.announcements.index')
            ->withSuccess('Announcement ' . (!$current ? 'activated' : 'deactivated') . '.');
    }

    public function destroy(int $id): RedirectResponse
    {
        DB::table('announcements')->where('id', $id)->delete();
        return redirect()->route('admin.announcements.index')
            ->withSuccess('Announcement deleted.');
    }
}
