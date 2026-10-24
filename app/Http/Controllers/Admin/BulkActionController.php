<?php

namespace App\Http\Controllers\Admin;

use App\Models\Video;
use App\Models\Playlist;
use App\Enums\ReportStatus;
use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Comment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        switch ($action) {
            case 'change_category':
                $catId = $request->integer('category_id');
                if ($catId > 0 && \App\Models\Category::where('id', $catId)->exists()) {
                    $count = Video::whereIn('id', $ids)->update(['category_id' => $catId]);
                    $catTitle = \App\Models\Category::find($catId)?->title ?? '';
                    $msg = "Assigned {$count} videos to category \"{$catTitle}\".";
                } else {
                    $msg = "Invalid category selected.";
                }
                break;
            case 'resync_duration':
                $count = Video::whereIn('id', $ids)->update([
                    'avdb_duration_sync_attempted_at' => null,
                    'avdb_duration_sync_status' => null
                ]);
                $msg = "Marked {$count} videos for duration re-sync.";
                break;
            case 'delete':
                $videos = Video::whereIn('id', $ids)->get();
                $videos->each(fn($video) => $video->forceDelete());
                $msg = "Permanently deleted {$videos->count()} videos.";
                break;
            case 'ban':
                $count = Video::whereIn('id', $ids)->update(['status' => VideoStatus::BANNED, 'banned_at' => now()]);
                $msg = "Banned {$count} videos.";
                break;
            case 'unban':
                $count = Video::whereIn('id', $ids)->update(['status' => VideoStatus::PUBLIC, 'banned_at' => null]);
                $msg = "Unbanned {$count} videos.";
                break;
            case 'make_public':
                $count = Video::whereIn('id', $ids)->update(['status' => VideoStatus::PUBLIC]);
                $msg = "Made {$count} videos public.";
                break;
            case 'make_private':
                $count = Video::whereIn('id', $ids)->update(['status' => VideoStatus::PRIVATE]);
                $msg = "Made {$count} videos private.";
                break;
            case 'make_unlisted':
                $count = Video::whereIn('id', $ids)->update(['status' => VideoStatus::UNLISTED]);
                $msg = "Made {$count} videos unlisted.";
                break;
            default:
                $msg = "Unknown action.";
        }

        return redirect()->route('admin.videos.index')->withSuccess($msg);
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

        switch ($action) {
            case 'delete':
                $users = User::whereIn('id', $ids)->get();
                $users->each(fn($user) => $user->forceDelete());
                $msg = "Permanently deleted {$users->count()} users.";
                break;
            case 'ban':
                $count = User::whereIn('id', $ids)->update(['banned_at' => now()]);
                $msg = "Banned {$count} users.";
                break;
            case 'unban':
                $count = User::whereIn('id', $ids)->update(['banned_at' => null]);
                $msg = "Unbanned {$count} users.";
                break;
            case 'verify':
                $count = User::whereIn('id', $ids)->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
                $msg = "Verified {$count} users.";
                break;
            case 'make_admin':
                $count = User::whereIn('id', $ids)->update(['is_admin' => true]);
                $msg = "Made {$count} users admin.";
                break;
            case 'remove_admin':
                $count = User::whereIn('id', $ids)->update(['is_admin' => false]);
                $msg = "Removed admin from {$count} users.";
                break;
            default:
                $msg = "Unknown action.";
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
                $comments->each(function ($comment) {
                    $comment->interactions()->delete();
                    $comment->replies()->each(function ($reply) {
                        $reply->interactions()->delete();
                        $reply->forceDelete();
                    });
                    $comment->forceDelete();
                });
                $msg = "Permanently deleted {$comments->count()} comments.";
                break;
            case 'ban':
                $count = Comment::whereIn('id', $ids)->update(['banned_at' => now()]);
                $msg = "Banned {$count} comments.";
                break;
            case 'unban':
                $count = Comment::whereIn('id', $ids)->update(['banned_at' => null]);
                $msg = "Unbanned {$count} comments.";
                break;
            default:
                $msg = "Unknown action.";
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
        $playlists->each(function ($playlist) {
            $playlist->videos()->detach();
            $playlist->forceDelete();
        });
        $msg = "Permanently deleted {$playlists->count()} playlists.";

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
                $count = DB::table('notifications')->whereIn('id', $ids)->delete();
                $msg = "Permanently deleted {$count} notifications.";
                break;
            case 'mark_read':
                $count = DB::table('notifications')->whereIn('id', $ids)->whereNull('read_at')->update(['read_at' => now()]);
                $msg = "Marked {$count} notifications as read.";
                break;
            default:
                $msg = "Unknown action.";
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

        $count = DB::table('activities')->whereIn('id', $request->ids)->delete();
        $msg = "Permanently deleted {$count} activities.";

        return redirect()->route('admin.activities.index')->withSuccess($msg);
    }

    public function interactions(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'action' => 'required|in:delete',
        ]);

        $count = DB::table('interactions')->whereIn('id', $request->ids)->delete();
        $msg = "Permanently deleted {$count} interactions.";

        return redirect()->route('admin.interactions.index')->withSuccess($msg);
    }

    public function devices(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'action' => 'required|in:delete',
        ]);

        $count = DB::table('personal_access_tokens')->whereIn('id', $request->ids)->delete();
        $msg = "Revoked {$count} sessions.";

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
                $count = DB::table('failed_jobs')->whereIn('uuid', $ids)->delete();
                $msg = "Permanently deleted {$count} failed jobs.";
                break;
            case 'retry':
                foreach ($ids as $uuid) {
                    try { \Illuminate\Support\Facades\Artisan::call('queue:retry', ['id' => [$uuid]]); } catch (\Throwable $e) {}
                }
                $msg = "Retried " . count($ids) . " failed jobs.";
                break;
            default:
                $msg = "Unknown action.";
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
                $count = DB::table('contact_messages')->whereIn('id', $ids)->delete();
                $msg = "Permanently deleted {$count} messages.";
                break;
            case 'mark_read':
                $count = DB::table('contact_messages')->whereIn('id', $ids)->update(['is_read' => true]);
                $msg = "Marked {$count} messages as read.";
                break;
            default:
                $msg = "Unknown action.";
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
                $count = DB::table('reports')->whereIn('id', $ids)->delete();
                $msg = "Permanently deleted {$count} reports.";
                break;
            case 'resolve':
                // The status column holds ReportStatus numbers (0 pending, 1 blocked, 2 cancelled): the word
                // 'cancelled' became 0 (pending again) or a database error under strict SQL before 1.2.2.
                $count = DB::table('reports')
                    ->whereIn('id', $ids)
                    ->where('status', '!=', ReportStatus::CANCELLED->value)
                    ->update(['status' => ReportStatus::CANCELLED->value]);
                $msg = "Resolved {$count} reports.";
                break;
            default:
                $msg = "Unknown action.";
        }

        return redirect()->route('admin.reports.index')->withSuccess($msg);
    }
}
