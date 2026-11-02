<?php

namespace App\Models;

use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Models\Pivots\FavoritePlaylist;
use App\Models\Pivots\PlaylistVideo;
use App\Models\Traits\Filterable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperPlaylist
 */
class Playlist extends Model
{
    use HasFactory, Filterable, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'status' => PlaylistStatus::class,
        'is_deletable' => 'boolean',
        'sort' => PlaylistSort::class
    ];

    /**
     * A playlist deleted for good takes its rows with it on every site, whatever way it goes (the studio, the admin, the
     * member's account, the daily clean-up): its videos' rows and the other members' saves of it. Only the bundled
     * database's foreign keys removed them; a site installed by the migrations kept them for a playlist that no longer
     * existed (judge R3-009).
     */
    protected static function booted(): void
    {
        static::forceDeleting(function (Playlist $playlist): void {
            \Illuminate\Support\Facades\DB::table('playlist_has_videos')->where('playlist_id', $playlist->getKey())->delete();
            \Illuminate\Support\Facades\DB::table('favorites_playlist')->where('playlist_id', $playlist->getKey())->delete();
        });
    }

    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videos() : BelongsToMany
    {
        return $this->belongsToMany(Video::class, 'playlist_has_videos')
            ->using(PlaylistVideo::class)
            ->withPivot(['position', 'added_at'])
            ->when($this->sort, fn($q) => $q->orderBy($this->sort->getSortedColumn(), $this->sort->getSortedDirection()));
    }

    public function users () : BelongsToMany {
        return $this->belongsToMany(User::class, 'favorites_playlist', 'user_id', 'playlist_id')
            ->using(FavoritePlaylist::class)
            ->withPivot(['added_at']);
    }

    /**
     * -------------------- ATTRIBUTES --------------------
     */

    protected function route(): Attribute
    {
        return Attribute::make(
            get: fn () => route('playlist.show', $this),
        );
    }

    protected function type(): Attribute
    {
        return Attribute::make(
            get: fn () => 'playlist',
        );
    }

    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === PlaylistStatus::PUBLIC || $this->status === PlaylistStatus::UNLISTED
        );
    }

    public function firstVideo(): Attribute
    {
        return Attribute::make(
            get: function () {
                // A constrained relation is already authoritative for profile,
                // public and API listings. Reusing it keeps the preview aligned
                // with the visible count and the rendered video list.
                if ($this->relationLoaded('videos')) {
                    return $this->videos->first()?->loadMissing('thumbnail');
                }

                return $this->videos()
                    ->active()
                    ->forLocale()
                    ->with('thumbnail')
                    ->first();
            }
        );
    }

    protected function title(): Attribute
    {
        return Attribute::make(
            get: function ($value) {

                if ($this->is_deletable) {
                    return $value;
                }

                // R1-147: the stored title of the Watch later list is its identifier ("Watch Later", looked up by
                // WatchLaterPlaylist::getName()); it reads as the menu's own words in every language
                if ($value === \App\Playlists\Types\WatchLaterPlaylist::getName()) {
                    return __('Watch later');
                }

                return __($value);
            }
        );
    }

    /**
     * -------------------- SCOPES --------------------
     */

    /**
     * Scope a query to only include active videos.
     *
     * @param  Builder $query
     * @return void
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', PlaylistStatus::PUBLIC);
    }
}
