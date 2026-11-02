<?php

namespace App\Policies;

use App\Support\NumberFormat;
use App\Models\User;
use App\Models\Video;
use App\Support\AdminIpLock;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class VideoPolicy
{
    use HandlesAuthorization;

    public const array ADMIN_ABILITIES = ['view', 'file', 'update', 'thumbnail', 'thumbnails', 'interactions'];

    public function before(?User $user, string $ability): bool|null
    {
        if (in_array($ability, self::ADMIN_ABILITIES) && AdminIpLock::adminPowersHere($user)) {
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
            return Response::denyWithStatus(410, __('This video has been removed'));
        }

        if ($video->user_id && !$video->user) {
            return Response::denyWithStatus(410, __('This video is no longer available'));
        }

        // No language condition: the page opens in every language, as the lists show the video there (1.2.3, decision of
        // 29 Oct: App\Support\VideoLanguages::visible()); where it is not indexable the controller answers it with noindex

        return $video->is_public || $video->user()->is($user) || AdminIpLock::adminPowersHere($user)
            ? Response::allow()
            : Response::denyWithStatus(404, __('This video is private'));
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
            return Response::denyWithStatus(404, __('This video is deleted'));
        }

        return $video->is_public || $video->user()->is($user)
            ? Response::allow()
            : Response::denyWithStatus(403, __('This video is private'));
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
            : Response::denyWithStatus(403, __('You are not authorized to edit this video'));
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
            : Response::denyWithStatus(403, __('You are not authorized to report this video'));
    }

    /**
     * Determine whether the user can upload video.
     *
     * @param  User $user
     * @return Response|bool
     */
    public function upload(User $user) : Response|bool
    {
        // Global admin toggle: allow_uploads. The answers below are read by the member in the upload window: plain
        // sentences in the member's language (owner question OQ-7, 1.2.3 round 3; they were English in every
        // language). Only the words change: the checks, their order and the 403 status are as before.
        if (!config('site.allow_uploads', true)) {
            return Response::denyWithStatus(403, __('Uploads are switched off on this site.'));
        }

        // Premium members and administrators (the site's owners) have no count or space limit.
        if ($user->is_premium || $user->is_admin) {
            return Response::allow();
        }

        if ($user->uploaded_videos >= config('plans.free.max_uploads')) {
            $limit = (int) config('plans.free.max_uploads');

            return Response::denyWithStatus(403, trans_choice(
                '{1} The free plan allows :count video. Upgrade to Premium to upload more.|[2,*] The free plan allows :count videos. Upgrade to Premium to upload more.',
                $limit,
                ['count' => $limit]
            ));
        }

        $user_space = $user->uploaded_videos_size;
        $incomingSize = (int) (request()->get('resumableTotalSize') ?? request()->get('size') ?? 0);

        if ($incomingSize > 0 && $user_space + $incomingSize > config('plans.free.max_videos_storage')) {
            return Response::denyWithStatus(403, __('The free plan has :size of space for videos and :free of it is left. Upgrade to Premium for more space.', [
                'size' => NumberFormat::localFileSize(config('plans.free.max_videos_storage')),
                'free' => NumberFormat::localFileSize(max(0, (int) config('plans.'.$user->plan.'.max_videos_storage') - (int) $user_space)),
            ]));
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
            : Response::denyWithStatus(403, __('You are not authorized to interact with this video'));
    }

    /**
     * Determine whether the user can see video interactions: who liked or disliked the video.
     *
     * That list is the creator's own information (Studio > Analytics, "See who rated it"); administrators see it through
     * before() (the admin IP lock applies). Any other member got it for every active video, also when the creator hides
     * the likes (R1-090): now that member gets exactly the answer of a video id that does not exist (the text
     * App\Exceptions\Handler gives a missing model), so the answer does not tell which private or deleted ids exist.
     *
     * @param User $user
     * @param Video $video
     * @return Response|bool
     */
    public function interactions(User $user, Video $video): Response|bool
    {
        if (! $video->user?->is($user)) {
            return Response::denyWithStatus(404, __('The route :route could not be found.', ['route' => request()->path()]));
        }

        return $video->trashed()
            ? Response::denyWithStatus(404, 'This video is deleted')
            : Response::allow();
    }
}
