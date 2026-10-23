<?php

namespace App\Http\Controllers;

use App\Enums\ImageType;
use App\Models\Pivots\Subscription;
use App\Models\User;
use App\Support\AvdbCreatorAvatarFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UserController
{
    public function show (Request $request, User $user) : View|RedirectResponse {

        $requested = ltrim((string) $request->segment(1), '@');
        if ($requested !== $user->profileRouteKeyValue()) {
            return redirect()->to($user->route, 301);
        }

        $isSubscribed = Auth::user()?->isSubscribeTo($user);
        $channel = $user->channelCustomization();
        $featuredVideoLimit = (int) $channel['featured_columns'] * (int) $channel['featured_rows'];
        $latestVideoLimit = (int) $channel['latest_uploads_columns'] * (int) $channel['latest_uploads_rows'];
        $playlistLimit = (int) $channel['playlists_columns'] * (int) $channel['playlists_rows'];
        $playlistVideoLimit = (int) $channel['playlist_video_columns'] * (int) $channel['playlist_video_rows'];
        $profileVideoLimit = max((int) config('site.profile_videos_limit', 8), $featuredVideoLimit, $latestVideoLimit);
        $profilePlaylistLimit = max((int) config('site.profile_playlists_limit', 6), $playlistLimit);
        $playlistPreviewVideoLimit = max(1, $playlistVideoLimit);

        if ($isSubscribed){
            $this->updateReadDate($user);
        }

        return view('users.show', [
            'user' => $user->load([
                'videos' => function ($q) use ($profileVideoLimit) {
                    $q->with('user')
                        ->active()
                        ->forLocale()
                        ->latest('published_at')
                        ->limit($profileVideoLimit);
                },
                'playlists' => function ($q) use ($profilePlaylistLimit, $playlistPreviewVideoLimit) {
                    $q->withCount([
                            'videos' => fn ($videoQuery) => $videoQuery->active()->forLocale(),
                        ])
                      ->withWhereHas('videos', function($q) use ($playlistPreviewVideoLimit) {
                            $q->active()
                                ->forLocale()
                                ->with('user')
                                ->limit($playlistPreviewVideoLimit);
                            })
                        ->active()
                        ->latest('updated_at')
                        ->limit($profilePlaylistLimit);
                },
                'pinned_video' => fn ($q) => $q
                    ->with('user')
                    ->forLocale(),
                'reportByAuthUser'
            ])->loadCount([
                'subscribers',
                'videos_comments' => fn ($query) => $query
                    ->whereNull('comments.banned_at')
                    ->whereHas('video', fn ($videoQuery) => $videoQuery->active()->forLocale()),
                'videos as active_videos_count' => fn ($query) => $query->active()->forLocale(),
                'playlists as active_playlists_count' => fn ($query) => $query
                    ->active()
                    ->whereHas('videos', fn ($videoQuery) => $videoQuery->active()->forLocale()),
            ])->loadSum([
                'videos as videos_views_count' => fn ($query) => $query->active()->forLocale(),
            ], 'views'),
            'is_subscribed' => $isSubscribed
        ]);
    }

    private function updateReadDate (User $user) : void {
        Subscription::where([
            'user_id' => $user->id,
            'subscriber_id' => Auth::user()->id,
        ])->update([
            'read_at' => now()
        ]);
    }

    public function avatar (User $user): Response|BinaryFileResponse
    {
        $avatar = AvdbCreatorAvatarFactory::ensureForUser($user);

        if ($avatar) {
            $path = User::AVATAR_FOLDER.'/'.$avatar;

            if (Storage::exists($path)) {
                try {
                    $file = Storage::get($path);

                    if (str_ends_with(strtolower($avatar), '.svg')) {
                        $file = AvdbCreatorAvatarFactory::withoutSmil((string) $file);
                    }

                    return response($file)->withHeaders([
                        'Content-Type' => $this->storageMimeType($path, ImageType::PNG->value),
                        ...$this->profileImageCacheHeaders($user),
                    ]);
                } catch (\Throwable) {
                    // Fall through to memory SVG generation
                }
            }
        }

        // Resilient dynamic fallback: if disk storage is inaccessible or permission-restricted,
        // dynamically generate the branded creator/user SVG in memory so the visitor never sees default silhouette.
        $svg = AvdbCreatorAvatarFactory::svgForUser($user);
        if ($svg !== null) {
            return response($svg)->withHeaders([
                'Content-Type' => 'image/svg+xml',
                ...$this->profileImageCacheHeaders($user),
            ]);
        }

        return response()->file(public_path(User::DEFAULT_AVATAR), [
            'Content-Type' => ImageType::PNG->value,
            ...$this->profileImageCacheHeaders($user),
        ]);
    }

    public function banner (User $user): Response|BinaryFileResponse
    {
        if ($user->banner) {
            $path = User::BANNER_FOLDER.'/'.$user->banner;

            if (Storage::exists($path)) {
                $file = Storage::get($path);

                return response($file)->withHeaders([
                    'Content-Type' => $this->storageMimeType($path, ImageType::JPG->value),
                    ...$this->profileImageCacheHeaders($user),
                ]);
            }
        }

        return response()->file(public_path(User::DEFAULT_BANNER), [
            'Content-Type' => ImageType::JPG->value,
            ...$this->profileImageCacheHeaders($user),
        ]);
    }

    private function profileImageCacheHeaders(User $user): array
    {
        if ($user->is_active) {
            return ['Cache-Control' => 'public, max-age=604800, immutable'];
        }

        return ['Cache-Control' => 'private, no-store'];
    }

    private function storageMimeType(string $path, string $fallback): string
    {
        try {
            return Storage::mimeType($path) ?: $this->mimeTypeFromExtension($path, $fallback);
        } catch (\Throwable) {
            return $this->mimeTypeFromExtension($path, $fallback);
        }
    }

    private function mimeTypeFromExtension(string $path, string $fallback): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => ImageType::JPG->value,
            'png' => ImageType::PNG->value,
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => $fallback,
        };
    }
}
