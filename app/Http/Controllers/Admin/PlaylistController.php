<?php

namespace App\Http\Controllers\Admin;

use App\Models\Playlist;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PlaylistController
{
    public function index(Request $request): View
    {
        $newAdmin = AdminPreferences::usesNewAdmin($request->user());

        // The new list also searches titles and filters by who can see them (the playlist filters of the public pages).
        $filters = $newAdmin ? array_filter($request->only(['search', 'status']), fn ($value) => is_string($value) && $value !== '') : [];

        return view($newAdmin ? 'admin.playlists.list' : 'admin.playlists.index', [
            'playlists' => ($filters !== [] ? Playlist::filter($filters) : Playlist::query())
                ->with($newAdmin ? 'user:id,username' : 'user')
                ->withCount('videos')
                ->latest()
                ->paginate(\App\Support\AdminPagination::perPage($request, $newAdmin ? 25 : 20))
                ->withQueryString()
        ]);
    }

    public function destroy(Playlist $playlist): RedirectResponse
    {
        $playlist->videos()->detach();
        $playlist->forceDelete();

        return redirect()->route('admin.playlists.index')
            ->withSuccess('Playlist deleted successfully.');
    }
}
