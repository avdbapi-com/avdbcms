<?php

namespace App\Support;

use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Proof that a member has the LINK of another member's unlisted video or playlist (round 3 R2R-06).
 *
 * An unlisted video is shown to whoever has its link, so the watch page's rule (App\Support\ShownToMember::videos())
 * lets it through - and Save to playlist, Watch later and the report of the video (or of a comment under it) take a
 * video by its NUMBER: a member who tried numbers had another member's unlisted video saved, and their own playlist,
 * Watch later or Reports page then showed its link. The same for Save of another member's unlisted playlist (the
 * Library then showed its link). Decided (the safest default): saving another member's unlisted video or playlist needs
 * proof that the member has its link, else the answer is the one of a missing id. The proofs:
 *  - this browser opened it by its link: the watch page or the embed page of the video, the page of the playlist
 *    (remembered in the session, the last KEEP of each);
 *  - the request carries its link token, the uuid: `video_uuid` on the apps' Save to playlist (/api/playlists/save, their
 *    one address for it - Watch later too, by the member's Watch later playlist id) and on reports (/api/report). The
 *    site's own Watch later button and Save of a playlist are browser routes (session and page token: an app's token
 *    gets 419 there); they read `video_uuid` / `playlist_uuid` as well, but their proof is this browser's session;
 *  - the member's own lists show it already: one of their playlists (Watch later included) holds it, or it is in the
 *    member's history (a view is recorded by the video's link only). Another member's playlist is no proof: its page
 *    lists to visitors only the videos that are out for everybody.
 * The member's own unlisted videos and playlists, public ones and the rows saved before the update are unchanged.
 */
final class LinkProof
{
    /** How many links of each kind the session remembers (the newest). */
    public const KEEP = 200;

    private const VIDEOS = 'link_proof.videos';

    private const PLAYLISTS = 'link_proof.playlists';

    /** The watch page and the embed page: this browser has the link of the video. */
    public static function rememberVideo(Video $video): void
    {
        if ($video->status === VideoStatus::UNLISTED && ! self::owns($video->user_id)) {
            self::remember(self::VIDEOS, (int) $video->getKey());
        }
    }

    /** The page of a playlist: this browser has the link of the playlist. */
    public static function rememberPlaylist(Playlist $playlist): void
    {
        if ($playlist->status === PlaylistStatus::UNLISTED && ! self::owns($playlist->user_id)) {
            self::remember(self::PLAYLISTS, (int) $playlist->getKey());
        }
    }

    /** @return list<int> the unlisted videos this browser opened by their link */
    public static function videoIds(): array
    {
        return self::remembered(self::VIDEOS);
    }

    /**
     * Keeps the rows of `videos` a member may save by naming their number, of those the watch page shows them: every
     * one but another member's unlisted video without proof of its link (see the class). Conditions of the same ONE
     * query, so a refused number takes the same way and work as a missing one.
     */
    public static function savable(Builder $query, ?User $user, ?string $uuid = null): void
    {
        if (AdminIpLock::adminPowersHere($user)) {
            return;
        }

        $userId = (int) ($user?->getAuthIdentifier() ?? 0);
        $proven = self::videoIds();
        // a video's link token: a uuid, or the ten digits of an upload (Video::UUID_PATTERN)
        $uuid = is_string($uuid) && preg_match('/^'.Video::UUID_PATTERN.'$/', $uuid) === 1 ? strtolower($uuid) : null;

        $query->where(function (Builder $proof) use ($userId, $proven, $uuid): void {
            $proof->where('videos.status', '!=', VideoStatus::UNLISTED->value);

            if ($proven !== []) {
                $proof->orWhereIn('videos.id', $proven);
            }

            if ($uuid !== null) {
                $proof->orWhere('videos.uuid', $uuid);
            }

            if ($userId > 0) {
                // (another member's playlist is no proof: its page lists only the videos out for everybody to visitors)
                $proof->orWhere('videos.user_id', $userId)
                    ->orWhereExists(fn (Builder $row) => $row->selectRaw('1')->from('playlist_has_videos as proof_rows')
                        ->join('playlists as proof_lists', 'proof_lists.id', '=', 'proof_rows.playlist_id')
                        ->whereColumn('proof_rows.video_id', 'videos.id')
                        ->where('proof_lists.user_id', $userId))
                    ->orWhereExists(fn (Builder $seen) => $seen->selectRaw('1')->from('views as proof_views')
                        ->whereColumn('proof_views.video_id', 'videos.id')
                        ->where('proof_views.user_id', $userId)
                        ->whereNull('proof_views.deleted_at'));
            }
        });
    }

    /** Whether the member may save this video by its number (savable(), for one video already read). */
    public static function allowsVideo(Video $video, ?User $user, ?string $uuid = null): bool
    {
        if ($video->status !== VideoStatus::UNLISTED) {
            return true;
        }

        return DB::table('videos')->where('videos.id', $video->getKey())
            ->where(fn (Builder $query) => self::savable($query, $user, $uuid))
            ->exists();
    }

    /**
     * Whether the member may save (favorite) another member's unlisted playlist by its number: this browser opened its
     * page, the request carries its link (`playlist_uuid`), or the member saved it already (taking it out stays open).
     */
    public static function allowsPlaylist(Playlist $playlist, ?User $user, ?string $uuid = null): bool
    {
        if ($playlist->status !== PlaylistStatus::UNLISTED || self::owns($playlist->user_id, $user)) {
            return true;
        }

        if (in_array((int) $playlist->getKey(), self::remembered(self::PLAYLISTS), true)
            || (is_string($uuid) && $uuid !== '' && hash_equals(strtolower((string) $playlist->uuid), strtolower($uuid)))) {
            return true;
        }

        return $user !== null && DB::table('favorites_playlist')
            ->where('playlist_id', $playlist->getKey())
            ->where('user_id', $user->getKey())
            ->exists();
    }

    private static function owns(mixed $ownerId, ?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && $ownerId !== null && (string) $ownerId === (string) $user->getAuthIdentifier();
    }

    private static function remember(string $key, int $id): void
    {
        $request = request();

        if ($id <= 0 || ! $request->hasSession()) {
            return;
        }

        $session = $request->session();
        $ids = array_values(array_filter((array) $session->get($key, []), fn ($known) => (int) $known !== $id));
        $ids[] = $id;
        $session->put($key, array_slice(array_map('intval', $ids), -self::KEEP));
    }

    /** @return list<int> */
    private static function remembered(string $key): array
    {
        $request = request();

        if (! $request->hasSession()) {
            return [];
        }

        return array_values(array_filter(array_map('intval', (array) $request->session()->get($key, [])), fn (int $id) => $id > 0));
    }
}
