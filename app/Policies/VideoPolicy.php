<?php

namespace App\Policies;

use App\Support\NumberFormat;
use App\Models\User;
use App\Models\Video;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class VideoPolicy
{
    use HandlesAuthorization;

    public const array ADMIN_ABILITIES = ['view', 'file', 'update', 'thumbnail', 'thumbnails', 'interactions'];

    public function before(?User $user, string $ability): bool|null
    {
        if (in_array($ability, self::ADMIN_ABILITIES) && $user?->is_admin) {
            return true;
        }

        return null;
    }

    /**
     * Determine whether the user can view any models.
     *
     * @param  User  $user
     * @return Response|bool
     */
    public function viewAny(User $user) : Response|bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param  User $user
     * @param  Video $video
     * @return Response|bool
     */
    public function view(User $user, Video $video): Response|bool
    {
        return $video->user()->is($user) && $video->is_created
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can view video.
     *
     * @param User|null $user
     * @param Video $video
     * @return Response|bool
     */
    public function show(?User $user, Video $video): Response|bool
    {
        if ($video->is_banned) {
            return Response::denyWithStatus(410, 'This video has been removed');
        }

        if ($video->user_id && !$video->user) {
            return Response::denyWithStatus(410, 'This video is no longer available');
        }

        $locale = app()->getLocale();
        if (
            $video->avdb_id
            && $locale !== 'en'
            && $locale !== config('app.fallback_locale', 'en')
            && !$video->translations()->where('locale', $locale)->exists()
            && !$user?->is_admin
        ) {
            return Response::denyWithStatus(404, 'This video is not available in this language');
        }

        return $video->is_public || $video->user()->is($user) || $user?->is_admin
            ? Response::allow()
            : Response::denyWithStatus(404, 'This video is private');
    }



    /**
     * Determine whether the user can see video file.
     *
     * @param User|null $user
     * @param Video $video
     * @return Response|bool
     */
    public function file(?User $user, Video $video): Response|bool
    {
        if ($video->trashed()) {
            return Response::denyWithStatus(404, 'This video is deleted');
        }

        return $video->is_public || $video->user()->is($user)
            ? Response::allow()
            : Response::denyWithStatus(403, 'This video is private');
    }

    /**
     * Determine whether the user can see video active thumbnail.
     *
     * @param User|null $user
     * @param Video $video
     * @return Response|bool
     */
    public function thumbnail(?User $user, Video $video): Response|bool
    {
        if ($video->trashed()) {
            return Response::denyWithStatus(404, 'This video is deleted');
        }

        return $video->is_public || $video->user?->is($user)
            ? Response::allow()
            : Response::denyWithStatus(403, 'This video is private');
    }

    /**
     * Determine whether the user can see video thumbnails.
     *
     * @param User $user
     * @param Video $video
     * @return Response|bool
     */
    public function thumbnails(User $user, Video $video): Response|bool
    {
        return $video->user?->is($user)
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can create the model.
     *
     * @param  User $user
     * @return Response|bool
     */
    public function create(User $user) : Response|bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param  User $user
     * @param  Video $video
     * @return Response|bool
     */
    public function update(User $user, Video $video) : Response|bool
    {
        return $video->user()->is($user) && !$video->is_banned && !$video->is_failed
            ? Response::allow()
            : Response::denyWithStatus(403, 'You are not authorized to edit this video');
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param  User $user
     * @param  Video $video
     * @return Response|bool
     */
    public function delete(User $user, Video $video) : Response|bool
    {
        return $video->user()->is($user)
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can report video.
     *
     * @param  User $user
     * @param  Video $video
     * @return Response|bool
     */
    public function report(User $user, Video $video) : Response|bool
    {
        return $video->user()->isNot($user) && $video->is_public && !$video->isReportedByUser($user)
            ? Response::allow()
            : Response::denyWithStatus(403, 'You are not authorized to report this video');
    }

    /**
     * Determine whether the user can upload video.
     *
     * @param  User $user
     * @return Response|bool
     */
    public function upload(User $user) : Response|bool
    {
        // Global admin toggle: allow_uploads
        if (!config('site.allow_uploads', true)) {
            return Response::denyWithStatus(403, 'Uploads are currently disabled by the administrator.');
        }

        // Premium members and administrators (the site's owners) have no count or space limit.
        if ($user->is_premium || $user->is_admin) {
            return Response::allow();
        }

        if ($user->uploaded_videos >= config('plans.free.max_uploads')) {
            return Response::denyWithStatus(403, 'Sorry ! You are limited to upload maximum ' .config('plans.free.max_uploads'). ' videos with free plan. Upgrade to Premium to upload more videos.');
        }

        $user_space = $user->uploaded_videos_size;
        $incomingSize = (int) (request()->get('resumableTotalSize') ?? request()->get('size') ?? 0);

        if ($incomingSize > 0 && $user_space + $incomingSize > config('plans.free.max_videos_storage')) {
            return Response::denyWithStatus(
                403,
                'Sorry ! Your space is limited to '.NumberFormat::fileSize(config('plans.free.max_videos_storage')).' with free plan. (Available space : '. NumberFormat::fileSize(config('plans.'.$user->plan.'.max_videos_storage') - $user_space) .'). Upgrade to Premium plan to increase your space storage.'
            );
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can pin video.
     *
     * @param User $user
     * @param Video $video
     * @return Response|bool
     */
    public function pin(User $user, Video $video): Response|bool
    {
        return $video->user?->is($user) && $video->is_active && !$video->is_pinned
            ? Response::allow()
            : Response::denyWithStatus(403);
    }

    /**
     * Determine whether the user can unpin video.
     *
     * @param User $user
     * @param Video $video
     * @return Response|bool
     */
    public function unpin(User $user, Video $video): Response|bool
    {
        return $video->user?->is($user) && $video->is_active && $video->is_pinned
            ? Response::allow()
            : Response::denyWithStatus(403);
    }

    /**
     * Determine whether the user can like/dislike video.
     *
     * @param User $user
     * @param Video $video
     * @return Response|bool
     */
    public function interact(User $user, Video $video): Response|bool
    {
        return $video->is_active || $video->user?->is($user)
            ? Response::allow()
            : Response::denyWithStatus(403, 'You are not authorized to interact with this video');
    }

    /**
     * Determine whether the user can see video interactions.
     *
     * @param User $user
     * @param Video $video
     * @return Response|bool
     */
    public function interactions(User $user, Video $video): Response|bool
    {
        if ($video->trashed()) {
            return Response::denyWithStatus(404, 'This video is deleted');
        }

        return $video->is_active || $video->user?->is($user)
            ? Response::allow()
            : Response::denyWithStatus(403, 'You are not authorized to see his video interactions');
    }
}
