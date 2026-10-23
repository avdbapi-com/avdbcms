<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

class PlaylistController
{
    public function show (Playlist $playlist) : View {
        $viewer = Auth::user();
        $videosQuery = $playlist->videos()
            ->with('user')
            ->withCount([
                'likes',
                'dislikes',
                'comments as comments_count' => fn ($commentQuery) => $commentQuery->public(),
            ]);

        // Keep the owner's private playlist view intact. Public visitors should
        // receive the same active, locale-visible set used by playlist metrics
        // and public playlist cards.
        if (! $viewer?->is_admin && $viewer?->id !== $playlist->user_id) {
            $videosQuery
                ->active()
                ->forLocale();
        }

        return view('playlists.show', [
            'playlist' => $playlist,
            'videos' => $videosQuery->get(),
            'favorite_by_auth_user' => Auth::user()?->favorites_playlist()->where('playlist_id', $playlist->id)->exists()
        ]);
    }

    public function manage (): View {
        return view('playlists.manage', [
            'playlists' => Auth::user()->favorites_playlist()
                ->with('user')
                ->withCount([
                    'videos' => fn ($query) => $query->active()->forLocale(),
                ])
                ->latest('added_at')
                ->get()
        ]);
    }
}
