<?php

namespace App\Support;

use App\Enums\VideoStatus;
use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * What the site's pages show a member, written as conditions of ONE database query, for the requests that name a video or
 * a comment by its number (Save to playlist, like and dislike, the report of a comment). A number the member may not see
 * is then refused exactly like one that does not exist: the same answer and the same work (one query either way), so the
 * answer never tells whether another member's private video, draft or video planned for later exists (round 2a R2A-061,
 * R2A-062).
 *
 * For videos the callers add "not deleted" with their exists rule (whereNull('deleted_at')); comments() has it, with the
 * comment's thread and video.
 */
final class ShownToMember
{
    /**
     * Keeps the rows of `videos` the watch page shows this member, by its own rule (VideoPolicy::show): not banned, its
     * channel still there, and out for everybody (published, its date reached, finished uploading) or the member's own,
     * in every language (App\Support\VideoLanguages::visible(): 1.2.3, the page follows the lists). An administrator
     * (with the admin IP lock's powers here) sees every one.
     */
    public static function videos(Builder $query, ?User $user): void
    {
        $userId = (int) ($user?->getAuthIdentifier() ?? 0);
        $admin = AdminIpLock::adminPowersHere($user);
        $now = now();

        $query->where('videos.status', '!=', VideoStatus::BANNED->value)
            ->where(fn (Builder $owner) => $owner->whereNull('videos.user_id')->orWhere('videos.user_id', 0)
                ->orWhereExists(fn (Builder $users) => $users->selectRaw('1')->from('users')
                    ->whereColumn('users.id', 'videos.user_id')->whereNull('users.deleted_at')));

        if (! $admin) {
            $query->where(function (Builder $seen) use ($userId, $now): void {
                $seen->where(fn (Builder $out) => $out->whereNotNull('videos.uploaded_at')
                    ->where(fn (Builder $status) => $status
                        ->where(fn (Builder $public) => $public->where('videos.status', VideoStatus::PUBLIC->value)
                            ->where(fn (Builder $date) => $date->whereNull('videos.published_at')->orWhere('videos.published_at', '<=', $now)))
                        ->orWhere(fn (Builder $planned) => $planned->where('videos.status', VideoStatus::PLANNED->value)
                            ->where('videos.scheduled_at', '<=', $now))
                        ->orWhere('videos.status', VideoStatus::UNLISTED->value)));

                if ($userId > 0) {
                    $seen->orWhere('videos.user_id', $userId);
                }
            });
        }
    }

    /**
     * Keeps the rows of `comments` that a video's comment list shows this member (Api\CommentController::index and
     * replies): not deleted, not hidden by a moderator, a reply only under a comment the list shows, under a video the
     * watch page shows the member (videos()) whose comments are on or that is the member's own (CommentPolicy::viewAny;
     * an administrator sees the comments of every video).
     */
    public static function comments(Builder $query, ?User $user): void
    {
        $userId = (int) ($user?->getAuthIdentifier() ?? 0);
        $admin = AdminIpLock::adminPowersHere($user);

        $query->whereNull('comments.deleted_at')
            ->whereNull('comments.banned_at')
            ->where(fn (Builder $thread) => $thread->whereNull('comments.parent_id')
                ->orWhereExists(fn (Builder $parent) => $parent->selectRaw('1')->from('comments as parent_comments')
                    ->whereColumn('parent_comments.id', 'comments.parent_id')
                    ->whereNull('parent_comments.deleted_at')
                    ->whereNull('parent_comments.banned_at')))
            ->whereExists(function (Builder $videos) use ($user, $userId, $admin): void {
                $videos->selectRaw('1')->from('videos')
                    ->whereColumn('videos.id', 'comments.video_id')
                    ->whereNull('videos.deleted_at');
                self::videos($videos, $user);

                if (! $admin) {
                    $videos->where(function (Builder $on) use ($userId): void {
                        $on->where('videos.allow_comments', true);

                        if ($userId > 0) {
                            $on->orWhere('videos.user_id', $userId);
                        }
                    });
                }
            });
    }
}
