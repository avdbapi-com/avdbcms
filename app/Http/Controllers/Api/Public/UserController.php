<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Resources\Playlist\PlaylistListResource;
use App\Http\Resources\User\UserShowResource;
use App\Http\Resources\Video\VideoListResource;
use App\Models\User;
use App\Support\SafeCursor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Auth;

class UserController
{
    public function show(User $user): UserShowResource
    {
        return new UserShowResource(
            $user
                ->loadCount([
                    'subscribers',
                    'videos as videos_count' => fn ($query) => $query->active()->forLocale(),
                ])
                ->loadSum([
                    'videos as videos_views_count' => fn ($query) => $query->active()->forLocale(),
                ], 'views')
                ->load([
                    'pinned_video' => fn ($query) => $query
                        ->with('user')
                        ->forLocale(),
                    'videos' => function ($q) {
                        $q->with('user')
                            ->active()
                            ->forLocale()
                            ->latest('published_at')
                            ->limit(config('site.profile_videos_limit', 8));
                    },
                    'playlists' => function ($q) {
                        $q->withCount([
                                'videos' => fn ($videoQuery) => $videoQuery->active()->forLocale(),
                            ])
                            ->withWhereHas('videos', function($q) {
                                $q->active()
                                    ->forLocale()
                                    ->with('user')
                                    ->limit(config('site.profile_videos_limit', 8));
                            })
                            ->active()
                            ->latest('updated_at')
                            ->limit(config('site.profile_playlists_limit', 6));
                    },
                ])
                ->loadExists([
                    'subscribers as subscribed_by_auth_user' => fn($q) => $q->where('subscriber_id', Auth::id()),
                ])
        );
    }

    public function videos(User $user, Request $request): ResourceCollection
    {
        $sort = $request->string('sort', 'latest')->toString();
        $sort = in_array($sort, ['latest', 'popular', 'oldest'], true) ? $sort : 'latest';
        $excludePinned = $request->exists('excludePinned');
        $pinnedVideoId = $excludePinned
            ? $user->pinned_video()->forLocale()->value('id')
            : null;

        $videos = $user->videos()
            ->active()
            ->forLocale()
            ->when($pinnedVideoId !== null, fn ($query) => $query->where('id', '!=', $pinnedVideoId))
            ->with('user')
            ->when($sort === 'latest', fn($query) => $query->latest('published_at')->orderByDesc('id'))
            ->when($sort === 'popular', fn($query) => $query->orderByDesc('views')->orderByDesc('id'))
            ->when($sort === 'oldest', fn($query) => $query->oldest('published_at')->orderBy('id'));

        // A cursor of another sort (or a hand-made one) gives the first page instead of HTTP 500.
        return VideoListResource::collection(
            SafeCursor::paginate($videos, (int) config('site.per_page_videos', 24), $request->input('cursor'))
        );
    }

    public function playlists(User $user, Request $request): ResourceCollection
    {
        $playlists = $user->playlists()
            ->active()
            ->withCount([
                'videos' => fn ($query) => $query->active()->forLocale(),
            ])
            ->whereHas('videos', fn ($query) => $query->active()->forLocale())
            ->latest('updated_at')
            // playlists changed in the same second keep a fixed order: none is skipped or shown twice at a page edge
            ->orderByDesc('playlists.id');

        return PlaylistListResource::collection(
            SafeCursor::paginate($playlists, (int) config('site.per_page_users', 15), $request->input('cursor'))
        );
    }
}
