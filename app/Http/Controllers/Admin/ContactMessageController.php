<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminCache;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactMessageController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.contacts.list', $this->listPage($request));
        }

        $messages = DB::table('contact_messages')
            ->orderByDesc('created_at')
            ->paginate(\App\Support\AdminPagination::perPage(null, 25));

        $stats = [
            'total' => DB::table('contact_messages')->count(),
            'unread' => DB::table('contact_messages')->where('is_read', false)->count(),
            'today' => DB::table('contact_messages')->whereDate('created_at', today())->count(),
        ];

        return view('admin.contacts.index', compact('messages', 'stats'));
    }

    /**
     * The new admin's list: 25 per page, newest first, the Unread tab (?show=unread); the three counts in one query,
     * kept for a minute (any admin change clears them).
     */
    private function listPage(Request $request): array
    {
        $messages = DB::table('contact_messages')
            ->when($request->query('show') === 'unread', fn ($query) => $query->where('is_read', false))
            ->orderByDesc('created_at')
            ->paginate(\App\Support\AdminPagination::perPage($request, 25))
            ->withQueryString();

        $stats = AdminCache::remember('contacts.tabs.v1', now()->addMinute(), function (): array {
            $row = DB::table('contact_messages')
                ->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_read THEN 0 ELSE 1 END) as unread, SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as today', [today()])
                ->first();

            return ['total' => (int) ($row->total ?? 0), 'unread' => (int) ($row->unread ?? 0), 'today' => (int) ($row->today ?? 0)];
        });

        return compact('messages', 'stats');
    }

    public function show(int $id): View
    {
        $message = DB::table('contact_messages')->where('id', $id)->firstOrFail();

        DB::table('contact_messages')->where('id', $id)->update(['is_read' => true]);

        return view(AdminPreferences::usesNewAdmin() ? 'admin.contacts.record' : 'admin.contacts.show', compact('message'));
    }

    public function destroy(int $id): RedirectResponse
    {
        DB::table('contact_messages')->where('id', $id)->delete();
        return redirect()->route('admin.contacts.index')->withSuccess('Message deleted.');
    }

    public function markAllRead(): RedirectResponse
    {
        DB::table('contact_messages')->where('is_read', false)->update(['is_read' => true]);
        return redirect()->route('admin.contacts.index')->withSuccess('All messages marked as read.');
    }
}
