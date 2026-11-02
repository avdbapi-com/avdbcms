<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminPreferences;
use App\Support\AuditDetails;
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

        $id = DB::table('announcements')->insertGetId([
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

        // Tools > Logs > Admin actions (R3-019): the announcement by its title and number.
        AuditLogController::log('announcement.created', AuditDetails::titled((object) ['id' => $id, 'title' => (string) $request->title]));

        return redirect()->route('admin.announcements.index')
            ->withSuccess(__('Announcement created'));
    }

    public function toggle(int $id): RedirectResponse
    {
        $announcement = DB::table('announcements')->where('id', $id)->first(['id', 'title', 'is_active']);
        $current = $announcement?->is_active;

        if ($announcement !== null) {
            DB::table('announcements')->where('id', $id)->update([
                'is_active' => ! $current,
                'updated_at' => now(),
            ]);
            AuditLogController::log($current ? 'announcement.hidden' : 'announcement.shown', AuditDetails::titled($announcement));
        }

        return redirect()->route('admin.announcements.index')
            ->withSuccess($current ? __('Announcement hidden') : __('Announcement shown'));
    }

    public function destroy(int $id): RedirectResponse
    {
        $announcement = DB::table('announcements')->where('id', $id)->first(['id', 'title']);

        // Only an announcement that was there is written in Admin actions (a second click deletes nothing).
        if ($announcement !== null && DB::table('announcements')->where('id', $id)->delete() > 0) {
            AuditLogController::log('announcement.deleted', AuditDetails::titled($announcement));
        }

        return redirect()->route('admin.announcements.index')
            ->withSuccess(__('Announcement deleted'));
    }
}
