<?php

namespace App\Http\Controllers\Api\Private;

use App\Events\UserSubscribed;
use App\Http\Resources\AccountResource;
use App\Http\Resources\Playlist\PlaylistListResource;
use App\Http\Resources\User\UserListResource;
use App\Http\Resources\Video\MyVideoListResource;
use App\Http\Resources\Video\VideoListResource;
use App\Models\User;
use App\Playlists\Types\LikedVideosPlaylist;
use App\Support\SafeCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class UserController
{
    public function user (Request $request) : AccountResource{
        return new AccountResource($request->user());
    }

    public function videos(Request $request): ResourceCollection
    {
        $videos = $request
            ->user()
            ->videos()
            ->latest('updated_at')
            ->latest('id');

        return MyVideoListResource::collection(
            SafeCursor::paginate($videos, (int) config('site.per_page_videos', 15), $request->input('cursor'))
        );
    }

    public function playlists(Request $request): ResourceCollection
    {
        $videoId = $request->get('video_id');

        $playlists = $request
            ->user()
            ->playlists()
            ->orderBy('is_deletable', 'ASC')
            ->latest('created_at')
            // the playlist's id breaks ties: two playlists made in the same second were cut at a page edge
            ->orderByDesc('playlists.id')
            ->withCount('videos')
            ->with('videos')
            ->when($videoId, function (Builder $query) use ($videoId) {
                $query
                    ->withExists([
                        'videos as has_video' => fn(Builder $query) => $query->where('video_id', $videoId)
                    ])
                    ->whereNot('title', LikedVideosPlaylist::getName());
            });

        return PlaylistListResource::collection(
            SafeCursor::paginate($playlists, (int) config('site.per_page_videos', 15), $request->input('cursor'))
        );
    }

    public function subscribe (Request $request, User $user) : Response {
        // the subscription's times are the application's clock, as in Api\Private\SubscribeController (QA studio-23), once
        // the update has noted the rows the database's clock dated before (App\Support\LegacyRowTimes)
        $subscription = $request->user()->subscriptions()->toggle([$user->getKey() => \App\Support\LegacyRowTimes::stamp('subscriptions')]);
        UserSubscribed::dispatchIf($subscription['attached'] !== [], $user, $request->user());
        return response()->noContent();
    }

    public function subscriptionsVideos(Request $request): ResourceCollection
    {
        $videos = $request
            ->user()
            ->subscriptions_videos()
            ->active()
            ->with('user')
            ->latest('published_at')
            // the video's id breaks ties: two videos published in the same second were cut at a page edge
            ->orderByDesc('videos.id');

        return VideoListResource::collection(
            SafeCursor::paginate($videos, (int) config('site.per_page_videos', 15), $request->input('cursor'))
        );
    }

    public function subscriptionsChannels(Request $request): ResourceCollection
    {
        // Ordered by the pivot column with its table: the cursor then takes subscribe_at from the pivot (unqualified, the
        // channel has no such attribute, every cursor carried null and page 2 failed with "Illegal operator and value
        // combination"); the channel's id keeps channels followed in the same second in a fixed order.
        $channels = $request
            ->user()
            ->subscriptions()
            ->withExists([
                'subscribers as subscribed_by_auth_user' => fn($q) => $q->where('subscriber_id', Auth::id()),
            ])
            ->withCount('subscribers')
            ->latest('subscriptions.subscribe_at')
            ->latest('users.id');

        return UserListResource::collection(
            SafeCursor::paginate($channels, (int) config('site.per_page_videos', 15), $request->input('cursor'))
        );
    }

    public function logout (Request $request): Response {

        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

}
