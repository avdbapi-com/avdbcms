<?php

namespace App\Models\Pivots;

use App\Support\LegacyRowTimes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @mixin IdeHelperPlaylistVideo
 */
class PlaylistVideo extends Pivot
{
    use HasFactory;

    protected $table = 'playlist_has_videos';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'added_at' => 'datetime',
    ];

    /**
     * "Date added" of a video put into a playlist by any way that does not give it (Save to playlist, a new playlist of
     * the studio, the video's playlists on the details pages): the site's clock, like Watch later and the studio's
     * playlist editor (judge R3-035: these left it to the database's clock, hours off on a site whose database runs in
     * another zone). Until the update has noted the older rows the database's clock stays, as on every row before them
     * (App\Support\LegacyRowTimes::playlistStamp(); Watch later always had the site's clock).
     */
    protected static function booted(): void
    {
        static::creating(function (PlaylistVideo $pivot): void {
            if ($pivot->getAttribute('added_at') === null) {
                foreach (LegacyRowTimes::playlistStamp($pivot->pivotParent) as $column => $value) {
                    $pivot->setAttribute($column, $value);
                }
            }
        });
    }
}
