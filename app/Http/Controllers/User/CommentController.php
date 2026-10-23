<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class CommentController extends Controller
{
    public function index() : View {

        return view('users.comments.index', [
            'comments' => Comment::filter()
                ->whereHas('video', function (Builder $query) {
                    $query->where('user_id', Auth::id());
                })
                ->whereNull('parent_id')
                ->with(['video', 'user'])
                ->withCount(['likes', 'dislikes', 'interactions', 'replies'])
                ->orderBy('created_at', 'desc')
                ->paginate(config('site.per_page_comments', 15))
                ->withQueryString()
        ]);
    }


    public function destroy(Comment $comment): RedirectResponse {

        $comment->interactions()->delete();
        $comment->forceDelete();

        return redirect()->back();
    }

    public function massDelete(\Illuminate\Http\Request $request): RedirectResponse {

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        // Only delete comments that belong to videos owned by the current user
        $comments = Comment::whereIn('id', $request->ids)
            ->whereHas('video', fn(Builder $q) => $q->where('user_id', Auth::id()))
            ->get();

        $comments->each(function ($comment) {
            $comment->interactions()->delete();
            $comment->replies()->each(function ($reply) {
                $reply->interactions()->delete();
                $reply->forceDelete();
            });
            $comment->forceDelete();
        });

        return redirect()->back()->withSuccess("Deleted {$comments->count()} comments.");
    }
}
