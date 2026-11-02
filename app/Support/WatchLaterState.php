<?php

namespace App\Support;

use App\Models\Playlist;
use App\Models\User;
use App\Playlists\Types\WatchLaterPlaylist;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Which videos of a list are in the member's Watch later (member design 6.11; QA: the rows' "Save to Watch later"
 * took a video OUT of Watch later when it was there already). The row menus of Liked videos and the playlist pages print
 * the item that fits ("Save to Watch later" or "Remove from Watch later") and send the state they want (state=on|off),
 * so a click can never do the opposite of what it says. Two small queries per page of rows (the list, then which of
 * the page's videos it holds); nothing is created (a member without a Watch later list has nothing in it).
 */
final class WatchLaterState
{
    /**
     * The ids of $videos that are in $member's Watch later (the signed-in member by default; [] for a guest).
     *
     * @param  iterable<\App\Models\Video>  $videos
     * @return list<int>
     */
    public static function ids(iterable $videos, ?User $member = null): array
    {
        $member ??= Auth::user();
        $ids = collect($videos)->map(fn ($video) => (int) ($video->id ?? 0))->filter()->unique()->values()->all();

        if (! $member instanceof User || $ids === []) {
            return [];
        }

        // the list the Watch later button saves to: the same query as App\Playlists\CustomPlaylist::initPlaylist (the
        // member's list of that name, a deleted one left out by the model's SoftDeletes scope)
        $playlistId = Playlist::query()
            ->where('user_id', $member->getKey())
            ->where('title', WatchLaterPlaylist::getName())
            ->value('id');

        if ($playlistId === null) {
            return [];
        }

        return DB::table('playlist_has_videos')
            ->where('playlist_id', $playlistId)
            ->whereIn('video_id', $ids)
            ->pluck('video_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
