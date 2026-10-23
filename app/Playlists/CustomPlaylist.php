<?php

namespace App\Playlists;

use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Models\Playlist;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

abstract class CustomPlaylist
{
    protected ?Playlist $playlist = null;

    public function __construct()
    {
        $this->playlist = $this->initPlaylist();
    }

    /**
     * Get the display name for this playlist
     */
    abstract public static function getName(): string;

    /**
     * Get videos for this playlist
     */
    abstract public function getVideos(): Collection;

    abstract public function getActions(): Collection;

    /**
     * Get the playlist model
     */
    public function getPlaylist(): Playlist
    {
        return $this->playlist;
    }

    /**
     * Get or create the playlist record
     */
    protected function initPlaylist(): Playlist
    {
        $user = Auth::user();

        $playlist = Playlist::query()
            ->where('user_id', $user->id)
            ->where('title', $this->getName())
            ->first();

        if ($playlist) {
            return $playlist;
        }

        return Playlist::query()->create([
            'uuid' => (string) Str::uuid(),
            'title' => $this->getName(),
            'description' => $this->getName(),
            'user_id' => $user->id,
            'status' => PlaylistStatus::PRIVATE,
            'sort' => PlaylistSort::DATE_ADDED_NEWEST,
            'is_deletable' => false,
        ]);
    }
}
