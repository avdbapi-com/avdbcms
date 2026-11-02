<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;
use App\Notifications\Support\Inbox;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * A member acts on their own notifications only. Another member's notification answers 404, the same as one that
 * does not exist: the answer never tells whether a notification id exists.
 */
class NotificationPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can read notification.
     *
     * @param User $user
     * @param Notification $notification
     * @return Response
     */
    public function read(User $user, Notification $notification): Response
    {
        return $this->own($user, $notification);
    }

    /**
     * Determine whether the user can unread notification.
     *
     * @param User $user
     * @param Notification $notification
     * @return Response
     */
    public function unread(User $user, Notification $notification): Response
    {
        return $this->own($user, $notification);
    }

    /**
     * Determine whether the user can view click on notification.
     *
     * @param User $user
     * @param Notification $notification
     * @return Response
     */
    public function click(User $user, Notification $notification): Response
    {
        return $this->own($user, $notification);
    }

    /**
     * Determine whether the user can delete notification.
     *
     * @param User $user
     * @param Notification $notification
     * @return Response
     */
    public function delete(User $user, Notification $notification): Response
    {
        return $this->own($user, $notification);
    }

    private function own(User $user, Notification $notification): Response
    {
        return Inbox::owns($user, $notification) ? Response::allow() : Response::denyAsNotFound();
    }
}
