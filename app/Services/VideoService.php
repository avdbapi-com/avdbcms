<?php

namespace App\Services;

use App\Data\NextVideoDTO;
use App\Models\Playlist;
use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class VideoService {

    public function getSuggestedVideos(Video $parentVideo): Collection
    {
        $total = config('site.suggested_videos_count', 9);
        $source = (string) config('site.watch_related_source', 'balanced');

        return match ($source) {
            'same_creator' => $this->creatorVideos($parentVideo, $total),
            'same_category' => $this->categoryVideos($parentVideo, $total),
            'latest' => $this->latestVideos($parentVideo, $total),
            'nearby' => $this->nearbyVideos($parentVideo, [$parentVideo->id], $total),
            default => $this->balancedVideos($parentVideo, $total),
        };
    }

    private function balancedVideos(Video $parentVideo, int $total): Collection
    {
        $userLimit = max(1, min($total, (int) round($total / 3)));
        $user_videos = $this->nearbyFilteredVideos(
            $parentVideo,
            [],
            $userLimit,
            function (Builder $query) use ($parentVideo) {
                $query->where('user_id', $parentVideo->user_id)
                    ->when(
                        $parentVideo->category_id,
                        fn (Builder $query) => $query->where('category_id', $parentVideo->category_id)
                    );
            }
        );

        if ($parentVideo->category_id) {
            $categoryLimit = max(0, min($total - $user_videos->count(), (int) round($total * 2 / 3) - $user_videos->count()));
            $category_videos = $categoryLimit > 0
                ? $this->nearbyFilteredVideos(
                    $parentVideo,
                    $user_videos->pluck('id')->all(),
                    $categoryLimit,
                    fn (Builder $query) => $query->where('category_id', $parentVideo->category_id)
                )
                : collect();
        } else {
            $category_videos = collect();
        }

        $excludeIds = [$parentVideo->id, ...$user_videos->pluck('id'), ...$category_videos->pluck('id')];
        $remaining = $total - ($user_videos->count() + $category_videos->count());
        $fallback_videos = $remaining > 0
            ? $this->nearbyVideos($parentVideo, $excludeIds, $remaining)
            : collect();

        return $user_videos->merge($category_videos)->merge($fallback_videos);
    }

    private function creatorVideos(Video $parentVideo, int $limit): Collection
    {
        $videos = $this->nearbyFilteredVideos(
            $parentVideo,
            [],
            $limit,
            fn (Builder $query) => $query->where('user_id', $parentVideo->user_id)
        );

        if ($videos->count() >= $limit) {
            return $videos;
        }

        return $videos->merge($this->categoryVideos($parentVideo, $limit - $videos->count(), $videos->pluck('id')->all()));
    }

    private function categoryVideos(Video $parentVideo, int $limit, array $excludeIds = []): Collection
    {
        if (! $parentVideo->category_id) {
            return $this->nearbyVideos($parentVideo, $excludeIds, $limit);
        }

        $videos = $this->nearbyFilteredVideos(
            $parentVideo,
            $excludeIds,
            $limit,
            fn (Builder $query) => $query->where('category_id', $parentVideo->category_id)
        );

        if ($videos->count() >= $limit) {
            return $videos;
        }

        return $videos->merge($this->nearbyVideos($parentVideo, array_merge($excludeIds, $videos->pluck('id')->all()), $limit - $videos->count()));
    }

    private function latestVideos(Video $parentVideo, int $limit): Collection
    {
        return $this->nearbyVideos($parentVideo, [$parentVideo->id], $limit);
    }

    private function suggestedBaseQuery(Video $parentVideo, array $excludeIds = []): Builder
    {
        $excludeIds[] = $parentVideo->id;

        return Video::query()
            ->whereNotIn('id', array_values(array_unique(array_filter($excludeIds))))
            ->active()
            ->forLocale()
            ->with('user');
    }

    private function nearbyVideos(Video $parentVideo, array $excludeIds, int $limit): Collection
    {
        return $this->nearbyFilteredVideos($parentVideo, $excludeIds, $limit);
    }

    private function nearbyFilteredVideos(Video $parentVideo, array $excludeIds, int $limit, ?callable $filter = null): Collection
    {
        if ($limit <= 0) {
            return new Collection();
        }

        $before = $this->nearbyBaseQuery($parentVideo, $excludeIds)
            ->where('id', '<', $parentVideo->id);

        if ($filter) {
            $filter($before);
        }

        $before = $before->orderByDesc('id')->limit($limit)->get();

        if ($before->count() >= $limit) {
            return $before;
        }

        $excludeIds = array_merge($excludeIds, $before->pluck('id')->all());
        $after = $this->nearbyBaseQuery($parentVideo, $excludeIds)
            ->where('id', '>', $parentVideo->id);

        if ($filter) {
            $filter($after);
        }

        $after = $after->orderBy('id')->limit($limit - $before->count())->get();

        return $before->merge($after);
    }

    private function nearbyBaseQuery(Video $parentVideo, array $excludeIds = []): Builder
    {
        $query = $this->suggestedBaseQuery($parentVideo, $excludeIds);

        if (DB::connection()->getDriverName() === 'mysql') {
            $query->forceIndex('PRIMARY');
        }

        return $query;
    }

    public function getNextVideo (Collection $suggestedVideos, ?Playlist $playlist, ?Collection $playlistVideos, ?int $currentIndex) : NextVideoDTO|null {

        if ($playlist && ($currentIndex + 1 < $playlist->videos_count)) {

            /** @var Video|null $nextPlaylistVideo */
            $nextPlaylistVideo = $playlistVideos->skip($currentIndex + 1)->first(function (Video $video, int $index) {
                return ($video->user && $video->user->is(Auth::user())) || $video->is_public;
            });

            if ($nextPlaylistVideo) {
                return NextVideoDTO::fromPlaylist($nextPlaylistVideo, $playlist);
            }

            /** @var Video $video */
            $video = $suggestedVideos->first();

            return NextVideoDTO::fromSuggested($video);

        }

        if ($suggestedVideos->isEmpty()) {
            return null;
        }

        /** @var Video $video */
        $video = $suggestedVideos->first();

        return NextVideoDTO::fromSuggested($video);
    }
}
