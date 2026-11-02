<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\NotifyBannedVideos;
use App\Models\Video;
use App\Models\Playlist;
use App\Enums\ReportStatus;
use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Comment;
use App\Support\AuditDetails;
use App\Support\EngagementCounters;
use App\Support\VideoBans;
use App\Support\VideoPublishWords;
use App\Support\VideoSearchIndex;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The bulk bars of the admin's lists. Each answer says what happened in the admin's language, counted right for every
 * number ("Made 1 member an administrator.", "Made 3 members administrators.": trans_choice, the forms of each language
 * in lang/<locale>.json).
 *
 * Every change is also a row in Tools > Logs > Admin actions (R2A-020), naming what it changed (the first ones and how
 * many more): one item (a row menu of the new admin posts here too) is "video.banned", several "videos.banned". Nothing
 * that changed nothing is written.
 */
class BulkActionController
{
    public function videos(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'action' => 'required|in:delete,ban,unban,make_public,make_private,make_unlisted,change_category,resync_duration',
            'category_id' => 'nullable|integer',
        ]);

        $ids = $request->ids;
        $action = $request->action;
        // 1.2.3 round 3 (KS-F2): the optional external search hears of these plain updates (App\Support\VideoSearchIndex)
        VideoSearchIndex::watch();

        switch ($action) {
            case 'change_category':
                $catId = $request->integer('category_id');
                $category = $catId > 0 ? \App\Models\Category::query()->find($catId) : null;
                if ($category === null) {
                    return redirect()->route('admin.videos.index')->withErrors(['category_id' => __('Pick a category from the list.')]);
                }
                // 1.2.3 round 3 (KS-F3): a public, unlisted or scheduled video is not moved into a category whose name uses a
                // word that is always blocked (App\Support\VideoPublishWords); it stays where it is
                $refused = \App\Support\ContentSafety::hides((string) $category->title)
                    ? Video::query()->whereIn('id', $ids)->whereIn('status', array_map(fn (VideoStatus $status): int => $status->value, VideoPublishWords::OUT))
                        ->where(fn ($query) => $query->whereNull('category_id')->orWhere('category_id', '!=', $category->id))->pluck('id')->map(fn ($id): int => (int) $id)->all()
                    : [];
                $ids = array_values(array_diff(array_map('intval', $ids), $refused));
                $refusedNote = trans_choice('1 public, unlisted or scheduled video was not moved: the name of that category uses words that are not allowed on this site.|:count public, unlisted or scheduled videos were not moved: the name of that category uses words that are not allowed on this site.', count($refused));
                if ($ids === [] && $refused !== []) {
                    return redirect()->route('admin.videos.index')->withErrors(['category_id' => $refusedNote]);
                }
                $moved = Video::query()->whereIn('id', $ids)->where(fn ($query) => $query->whereNull('category_id')->orWhere('category_id', '!=', $category->id))->pluck('id')->all();
                $count = Video::whereIn('id', $ids)->update(['category_id' => $category->id]);
                $msg = trans_choice('Moved 1 video to the category ":category".|Moved :count videos to the category ":category".', $count, ['category' => (string) $category->title]);
                if ($moved !== []) {
                    AuditLogController::log(count($moved) === 1 ? 'video.category_changed' : 'videos.category_changed', self::videoNames($moved).' to the category '.trim((string) $category->title));
                }
                if ($refused !== []) {
                    $msg .= ' '.$refusedNote;
                }
                VideoSearchIndex::follow($moved);
                break;
            case 'resync_duration':
                // The duration sync only ever checks AVDB videos that have no duration (avdb:sync-durations, Tools >
                // Cron): those of the selection start over (their retry tracking is cleared, so the next sync takes
                // them first, as Tools > Cron's reset does for all of them); the others are left as they are and the
                // answer says so. 1.2.2 wrote a column no version has (avdb_duration_sync_status): a server error.
                $chosen = Video::query()->withoutGlobalScopes()->whereIn('id', $ids);
                $due = (clone $chosen)->whereNotNull('avdb_id')->missingDuration()->pluck('id')->all();
                $count = count($due);
                $others = max(0, (clone $chosen)->count() - $count);

                if ($count === 0) {
                    $msg = __('Nothing to check: the chosen videos already have a duration or are not AVDB videos.');
                    break;
                }

                $tracking = [];
                if (Schema::hasColumn('videos', 'avdb_duration_sync_attempted_at')) {
                    $tracking['avdb_duration_sync_attempted_at'] = null;
                }
                if (Schema::hasColumn('videos', 'avdb_duration_sync_failed_count')) {
                    $tracking['avdb_duration_sync_failed_count'] = 0;
                }
                if ($tracking !== []) {
                    Video::query()->withoutGlobalScopes()->whereIn('id', $due)->update($tracking);
                }

                $msg = trans_choice('1 video gets its duration checked again.|:count videos get their durations checked again.', $count);
                AuditLogController::log($count === 1 ? 'video.duration_check' : 'videos.duration_check', self::videoNames($due));
                if ($others > 0) {
                    $msg .= ' '.trans_choice('1 other chosen video needs no check: it already has a duration or is not an AVDB video.|:count other chosen videos need no check: they already have a duration or are not AVDB videos.', $others);
                }
                break;
            case 'delete':
                $videos = Video::whereIn('id', $ids)->get();
                $names = self::videoNames($videos->pluck('id')->all());
                $videos->each(fn($video) => $video->forceDelete());
                $msg = trans_choice('Deleted 1 video permanently.|Deleted :count videos permanently.', $videos->count());
                if ($videos->isNotEmpty()) {
                    AuditLogController::log($videos->count() === 1 ? 'video.deleted' : 'videos.deleted', $names);
                }
                break;
            case 'ban':
                // each keeps its status for Unban (App\Support\VideoBans); one banned already keeps its ban as it is
                $banned = Video::whereIn('id', $ids)->where('status', '!=', VideoStatus::BANNED->value)->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $count = $banned === [] ? 0 : VideoBans::ban(Video::whereIn('id', $banned));
                // Each creator hears of each ban, as with "Ban video" on one row (the VideoBanned event), from the queue:
                // a page of videos must not wait for as many e-mails.
                if ($count > 0) {
                    NotifyBannedVideos::dispatch($banned);
                    AuditLogController::log(count($banned) === 1 ? 'video.banned' : 'videos.banned', self::videoNames($banned));
                    VideoSearchIndex::follow($banned);
                }
                $msg = trans_choice('Banned 1 video.|Banned :count videos.', $count);
                break;
            case 'unban':
                // each goes back to its status from before the ban (1.2.2 made them all public)
                $unbanned = Video::query()->whereIn('id', $ids)->where('status', VideoStatus::BANNED->value)->pluck('id')->all();
                ['unbanned' => $count, 'unknown' => $unknown] = VideoBans::unban(Video::whereIn('id', $ids));
                if ($count > 0 && $unbanned !== []) {
                    AuditLogController::log(count($unbanned) === 1 ? 'video.unbanned' : 'videos.unbanned', self::videoNames($unbanned));
                    VideoSearchIndex::follow($unbanned);
                }
                $msg = trans_choice('Unbanned 1 video.|Unbanned :count videos.', $count);
                if ($unknown > 0) {
                    $msg .= ' '.trans_choice('1 video is private now, because its status from before the ban is not known.|:count videos are private now, because their status from before the ban is not known.', $unknown);
                }
                break;
            case 'make_public':
            case 'make_private':
            case 'make_unlisted':
                // A banned video stays banned: only Unban lifts a ban (it made a banned video public or editable again).
                $status = ['make_public' => VideoStatus::PUBLIC, 'make_private' => VideoStatus::PRIVATE, 'make_unlisted' => VideoStatus::UNLISTED][$action];
                // 1.2.3 round 3 (KS-F3): a video whose title, tags or category use a word that is always blocked does not go
                // out to visitors; it is left as it is (App\Support\VideoPublishWords). Unban stays the admin's way back.
                $refused = in_array($status, VideoPublishWords::OUT, true)
                    ? Video::query()->whereIn('id', $ids)->where('status', '!=', VideoStatus::BANNED->value)->with(['category:id,title', 'tags:id,name'])->get()
                        ->filter(fn (Video $video): bool => VideoPublishWords::refusals($video, ['status' => $status]) !== [])
                        ->map(fn (Video $video): int => (int) $video->id)->values()->all()
                    : [];
                $ids = array_values(array_diff(array_map('intval', $ids), $refused));
                $refusedNote = trans_choice('1 video was left as it is: its title, tags or category use words that are not allowed on this site.|:count videos were left as they are: their titles, tags or categories use words that are not allowed on this site.', count($refused));
                if ($ids === [] && $refused !== []) {
                    return redirect()->route('admin.videos.index')->withErrors(['ids' => $refusedNote]);
                }
                $changed = Video::query()->whereIn('id', $ids)->whereNotIn('status', [VideoStatus::BANNED->value, $status->value])->pluck('id')->all();
                $count = Video::whereIn('id', $ids)->where('status', '!=', VideoStatus::BANNED->value)->update(['status' => $status->value]);
                if ($changed !== []) {
                    $one = count($changed) === 1;
                    AuditLogController::log(match ($status) {
                        VideoStatus::PUBLIC => $one ? 'video.made_public' : 'videos.made_public',
                        VideoStatus::PRIVATE => $one ? 'video.made_private' : 'videos.made_private',
                        default => $one ? 'video.made_unlisted' : 'videos.made_unlisted',
                    }, self::videoNames($changed));
                }
                if ($status === VideoStatus::PUBLIC) {
                    // a video made public is published now (a scheduled one too), as when its creator publishes it
                    Video::whereIn('id', $ids)->where('status', VideoStatus::PUBLIC->value)
                        ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '>', now()))
                        ->update(['published_at' => now()]);
                }
                $msg = match ($status) {
                    VideoStatus::PUBLIC => trans_choice('Made 1 video public.|Made :count videos public.', $count),
                    VideoStatus::PRIVATE => trans_choice('Made 1 video private.|Made :count videos private.', $count),
                    default => trans_choice('Made 1 video unlisted.|Made :count videos unlisted.', $count),
                };
                $banned = Video::whereIn('id', $ids)->where('status', VideoStatus::BANNED->value)->count();
                if ($banned > 0) {
                    $msg .= ' '.trans_choice('1 banned video was left as it is: unban it first.|:count banned videos were left as they are: unban them first.', $banned);
                }
                if ($refused !== []) {
                    $msg .= ' '.$refusedNote;
                }
                VideoSearchIndex::follow($changed);
                break;
            default:
                $msg = __('Nothing was changed: that action is not known.');
        }

        return redirect()->route('admin.videos.index')->withSuccess(VideoSearchIndex::answer($msg));
    }

    public function users(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'action' => 'required|in:delete,ban,unban,verify,make_admin,remove_admin',
        ]);

        $ids = $request->ids;
        $action = $request->action;

        // Never modify the current admin user
        $ids = array_diff($ids, [auth()->id()]);

        // The members this action changes (Tools > Logs > Admin actions names them; R2A-020).
        $changing = fn (callable $where): array => $where(User::query()->whereIn('id', $ids))->orderBy('id')->get(['id', 'username'])->all();

        switch ($action) {
            case 'delete':
                $users = User::whereIn('id', $ids)->get();
                $names = AuditDetails::names($users->map(fn (User $user): string => AuditDetails::member($user)));
                $users->each(fn($user) => $user->forceDelete());
                $msg = trans_choice('Deleted 1 member permanently.|Deleted :count members permanently.', $users->count());
                if ($users->isNotEmpty()) {
                    AuditLogController::log($users->count() === 1 ? 'member.deleted' : 'members.deleted', $names);
                }
                break;
            case 'ban':
                $changed = $changing(fn ($query) => $query->whereNull('banned_at'));
                $count = User::whereIn('id', $ids)->update(['banned_at' => now()]);
                $msg = trans_choice('Blocked 1 member.|Blocked :count members.', $count);
                if ($changed !== []) {
                    AuditLogController::log(count($changed) === 1 ? 'member.blocked' : 'members.blocked', self::memberNames($changed));
                }
                break;
            case 'unban':
                $changed = $changing(fn ($query) => $query->whereNotNull('banned_at'));
                $count = User::whereIn('id', $ids)->update(['banned_at' => null]);
                $msg = trans_choice('Unblocked 1 member.|Unblocked :count members.', $count);
                if ($changed !== []) {
                    AuditLogController::log(count($changed) === 1 ? 'member.unblocked' : 'members.unblocked', self::memberNames($changed));
                }
                break;
            case 'verify':
                $changed = $changing(fn ($query) => $query->whereNull('email_verified_at'));
                $count = User::whereIn('id', $ids)->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
                $msg = trans_choice('Marked 1 email as confirmed.|Marked :count emails as confirmed.', $count);
                if ($changed !== []) {
                    AuditLogController::log(count($changed) === 1 ? 'member.email_confirmed' : 'members.email_confirmed', self::memberNames($changed));
                }
                break;
            case 'make_admin':
                // an older account may have no value (NULL) instead of false
                $changed = $changing(fn ($query) => $query->where(fn ($query) => $query->where('is_admin', false)->orWhereNull('is_admin')));
                $count = User::whereIn('id', $ids)->update(['is_admin' => true]);
                $msg = trans_choice('Made 1 member an administrator.|Made :count members administrators.', $count);
                if ($changed !== []) {
                    AuditLogController::log(count($changed) === 1 ? 'member.admin.granted' : 'members.admin.granted', self::memberNames($changed));
                }
                break;
            case 'remove_admin':
                $changed = $changing(fn ($query) => $query->where('is_admin', true));
                $count = User::whereIn('id', $ids)->update(['is_admin' => false]);
                $msg = trans_choice('1 member is no longer an administrator.|:count members are no longer administrators.', $count);
                if ($changed !== []) {
                    AuditLogController::log(count($changed) === 1 ? 'member.admin.removed' : 'members.admin.removed', self::memberNames($changed));
                }
                break;
            default:
                $msg = __('Nothing was changed: that action is not known.');
        }

        return redirect()->route('admin.users.index')->withSuccess($msg);
    }

    public function comments(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'action' => 'required|in:delete,ban,unban',
        ]);

        $ids = $request->ids;
        $action = $request->action;

        switch ($action) {
            case 'delete':
                $comments = Comment::whereIn('id', $ids)->get();
                $names = AuditDetails::ids($comments->pluck('id'));
                $comments->each(function ($comment) {
                    $comment->interactions()->delete();
                    $comment->replies()->each(function ($reply) {
                        $reply->interactions()->delete();
                        $reply->forceDelete();
                    });
                    $comment->forceDelete();
                });
                $msg = trans_choice('Deleted 1 comment permanently.|Deleted :count comments permanently.', $comments->count());
                if ($comments->isNotEmpty()) {
                    AuditLogController::log($comments->count() === 1 ? 'comment.deleted' : 'comments.deleted', $names);
                }
                break;
            case 'ban':
                $changed = Comment::whereIn('id', $ids)->whereNull('banned_at')->orderBy('id')->pluck('id')->all();
                // their videos' comment counts follow (a plain update skipped the events that keep them)
                $count = EngagementCounters::hideComments(Comment::whereIn('id', $ids));
                $msg = trans_choice('Hid 1 comment.|Hid :count comments.', $count);
                if ($changed !== []) {
                    AuditLogController::log(count($changed) === 1 ? 'comment.hidden' : 'comments.hidden', AuditDetails::ids($changed));
                }
                break;
            case 'unban':
                $changed = Comment::whereIn('id', $ids)->whereNotNull('banned_at')->orderBy('id')->pluck('id')->all();
                $count = EngagementCounters::showComments(Comment::whereIn('id', $ids));
                $msg = trans_choice('1 comment shows again.|:count comments show again.', $count);
                if ($changed !== []) {
                    AuditLogController::log(count($changed) === 1 ? 'comment.shown' : 'comments.shown', AuditDetails::ids($changed));
                }
                break;
            default:
                $msg = __('Nothing was changed: that action is not known.');
        }

        return redirect()->route('admin.comments.index')->withSuccess($msg);
    }

    public function playlists(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'action' => 'required|in:delete',
        ]);

        $ids = $request->ids;

        $playlists = Playlist::whereIn('id', $ids)->get();
        $names = AuditDetails::names($playlists->map(fn (Playlist $playlist): string => AuditDetails::titled($playlist)));
        $playlists->each(function ($playlist) {
            $playlist->videos()->detach();
            $playlist->forceDelete();
        });
        $msg = trans_choice('Deleted 1 playlist permanently.|Deleted :count playlists permanently.', $playlists->count());
        if ($playlists->isNotEmpty()) {
            AuditLogController::log($playlists->count() === 1 ? 'playlist.deleted' : 'playlists.deleted', $names);
        }

        return redirect()->route('admin.playlists.index')->withSuccess($msg);
    }

    public function notifications(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'string',
            'action' => 'required|in:delete,mark_read',
        ]);

        $ids = $request->ids;
        $action = $request->action;

        switch ($action) {
            case 'delete':
                $changed = DB::table('notifications')->whereIn('id', $ids)->orderBy('id')->pluck('id')->all();
                $count = DB::table('notifications')->whereIn('id', $ids)->delete();
                $msg = trans_choice('Deleted 1 notification permanently.|Deleted :count notifications permanently.', $count);
                if ($count > 0) {
                    AuditLogController::log($count === 1 ? 'notification.deleted' : 'notifications.deleted', AuditDetails::ids($changed, $count));
                }
                break;
            case 'mark_read':
                $changed = DB::table('notifications')->whereIn('id', $ids)->whereNull('read_at')->orderBy('id')->pluck('id')->all();
                $count = DB::table('notifications')->whereIn('id', $ids)->whereNull('read_at')->update(['read_at' => now()]);
                $msg = trans_choice('Marked 1 notification as read.|Marked :count notifications as read.', $count);
                if ($count > 0) {
                    AuditLogController::log($count === 1 ? 'notification.read' : 'notifications.read', AuditDetails::ids($changed, $count));
                }
                break;
            default:
                $msg = __('Nothing was changed: that action is not known.');
        }

        return redirect()->route('admin.notifications.index')->withSuccess($msg);
    }

    public function activities(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'action' => 'required|in:delete',
        ]);

        $changed = DB::table('activities')->whereIn('id', $request->ids)->orderBy('id')->pluck('id')->all();
        $count = DB::table('activities')->whereIn('id', $request->ids)->delete();
        $msg = trans_choice('Deleted 1 entry from the log.|Deleted :count entries from the log.', $count);
        if ($count > 0) {
            AuditLogController::log($count === 1 ? 'activity.deleted' : 'activities.deleted', AuditDetails::ids($changed, $count));
        }

        return redirect()->route('admin.activities.index')->withSuccess($msg);
    }

    public function interactions(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'action' => 'required|in:delete',
        ]);

        // the liked videos get their like counts again (a plain delete skips the events that keep them)
        $liked = DB::table('interactions')->whereIn('id', $request->ids)->where('likeable_type', (new Video())->getMorphClass())->distinct()->pluck('likeable_id');
        $changed = DB::table('interactions')->whereIn('id', $request->ids)->orderBy('id')->pluck('id')->all();
        $count = DB::table('interactions')->whereIn('id', $request->ids)->delete();
        EngagementCounters::recount($liked);
        $msg = trans_choice('Deleted 1 like or dislike.|Deleted :count likes and dislikes.', $count);
        if ($count > 0) {
            AuditLogController::log($count === 1 ? 'like.deleted' : 'likes.deleted', AuditDetails::ids($changed, $count));
        }

        return redirect()->route('admin.interactions.index')->withSuccess($msg);
    }

    public function devices(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'action' => 'required|in:delete',
        ]);

        $changed = DB::table('personal_access_tokens')->whereIn('id', $request->ids)->orderBy('id')->pluck('id')->all();
        $count = DB::table('personal_access_tokens')->whereIn('id', $request->ids)->delete();
        $msg = trans_choice('Signed out 1 device.|Signed out :count devices.', $count);
        if ($count > 0) {
            AuditLogController::log($count === 1 ? 'device.signed_out' : 'devices.signed_out', AuditDetails::ids($changed, $count));
        }

        return redirect()->route('admin.devices.index')->withSuccess($msg);
    }

    public function failedJobs(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'string',
            'action' => 'required|in:delete,retry',
        ]);

        $ids = $request->ids;
        $action = $request->action;

        switch ($action) {
            case 'delete':
                $changed = DB::table('failed_jobs')->whereIn('uuid', $ids)->orderBy('id')->pluck('uuid')->all();
                $count = DB::table('failed_jobs')->whereIn('uuid', $ids)->delete();
                $msg = trans_choice('Deleted 1 failed job permanently.|Deleted :count failed jobs permanently.', $count);
                if ($count > 0) {
                    AuditLogController::log($count === 1 ? 'queue.failed.delete' : 'queue.failed.delete_some', $count === 1 ? 'Deleted failed job '.$changed[0].'.' : AuditDetails::ids($changed, $count));
                }
                break;
            case 'retry':
                $changed = DB::table('failed_jobs')->whereIn('uuid', $ids)->orderBy('id')->pluck('uuid')->all();
                foreach ($ids as $uuid) {
                    try { \Illuminate\Support\Facades\Artisan::call('queue:retry', ['id' => [$uuid]]); } catch (\Throwable $e) {}
                }
                $msg = trans_choice('1 failed job will run again.|:count failed jobs will run again.', count($ids));
                if ($changed !== []) {
                    AuditLogController::log(count($changed) === 1 ? 'queue.failed.retry' : 'queue.failed.retry_some', count($changed) === 1 ? 'Retried failed job '.$changed[0].'.' : AuditDetails::ids($changed));
                }
                break;
            default:
                $msg = __('Nothing was changed: that action is not known.');
        }

        return redirect()->route('admin.failed-jobs.index')->withSuccess($msg);
    }

    public function contacts(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'action' => 'required|in:delete,mark_read',
        ]);

        $ids = $request->ids;
        $action = $request->action;

        switch ($action) {
            case 'delete':
                $changed = DB::table('contact_messages')->whereIn('id', $ids)->orderBy('id')->pluck('id')->all();
                $count = DB::table('contact_messages')->whereIn('id', $ids)->delete();
                $msg = trans_choice('Deleted 1 message permanently.|Deleted :count messages permanently.', $count);
                if ($count > 0) {
                    AuditLogController::log($count === 1 ? 'message.deleted' : 'messages.deleted', AuditDetails::ids($changed, $count));
                }
                break;
            case 'mark_read':
                $changed = DB::table('contact_messages')->whereIn('id', $ids)->where('is_read', false)->orderBy('id')->pluck('id')->all();
                $count = DB::table('contact_messages')->whereIn('id', $ids)->update(['is_read' => true]);
                $msg = trans_choice('Marked 1 message as read.|Marked :count messages as read.', $count);
                if ($changed !== []) {
                    AuditLogController::log(count($changed) === 1 ? 'message.read' : 'messages.read', AuditDetails::ids($changed));
                }
                break;
            default:
                $msg = __('Nothing was changed: that action is not known.');
        }

        return redirect()->route('admin.contacts.index')->withSuccess($msg);
    }

    public function reports(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'action' => 'required|in:delete,resolve',
        ]);

        $ids = $request->ids;
        $action = $request->action;

        switch ($action) {
            case 'delete':
                $changed = DB::table('reports')->whereIn('id', $ids)->orderBy('id')->pluck('id')->all();
                $count = DB::table('reports')->whereIn('id', $ids)->delete();
                $msg = trans_choice('Deleted 1 report permanently.|Deleted :count reports permanently.', $count);
                if ($count > 0) {
                    AuditLogController::log($count === 1 ? 'report.deleted' : 'reports.deleted', AuditDetails::ids($changed, $count));
                }
                break;
            case 'resolve':
                // The status column holds ReportStatus numbers (0 pending, 1 blocked, 2 cancelled): the word
                // 'cancelled' became 0 (pending again) or a database error under strict SQL before 1.2.2.
                $changed = DB::table('reports')->whereIn('id', $ids)->where('status', '!=', ReportStatus::CANCELLED->value)->orderBy('id')->pluck('id')->all();
                $count = DB::table('reports')
                    ->whereIn('id', $ids)
                    ->where('status', '!=', ReportStatus::CANCELLED->value)
                    ->update(['status' => ReportStatus::CANCELLED->value]);
                $msg = trans_choice('Resolved 1 report.|Resolved :count reports.', $count);
                if ($count > 0) {
                    AuditLogController::log($count === 1 ? 'report.resolved' : 'reports.resolved', AuditDetails::ids($changed, $count));
                }
                break;
            default:
                $msg = __('Nothing was changed: that action is not known.');
        }

        return redirect()->route('admin.reports.index')->withSuccess($msg);
    }

    /**
     * "My video (#12), Other (#13) and 4 more": the titles of the first videos (one query), and how many more.
     *
     * @param  array<int, int|string>  $ids
     */
    private static function videoNames(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $videos = Video::query()->withoutGlobalScopes()->whereIn('id', array_slice($ids, 0, AuditDetails::LIST_MAX))->orderBy('id')->get(['id', 'title']);

        return AuditDetails::names($videos->map(fn (Video $video): string => AuditDetails::titled($video)), count($ids));
    }

    /** @param  array<int, User>  $users */
    private static function memberNames(array $users): string
    {
        return AuditDetails::names(array_map(fn (User $user): string => AuditDetails::member($user), $users));
    }
}
