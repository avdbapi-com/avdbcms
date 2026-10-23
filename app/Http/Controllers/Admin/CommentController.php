<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function index(Request $request) : View {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.comments.list', $this->listPage($request));
        }

        return view('admin.comments.index', [
            'comments' => Comment::filter()
                    ->with([
                        'video' => fn(BelongsTo $query) => $query->withTrashed()->with('user'),
                        'user'
                    ])
                    ->whereNull('parent_id')
                    ->withCount(['likes', 'dislikes', 'interactions', 'replies'])
                    ->orderBy('created_at', 'desc')
                    ->paginate(\App\Support\AdminPagination::perPage(null, 12))
                    ->withQueryString()
        ]);
    }

    /**
     * The new admin's list: each comment with its video, author, like and reply counts and its three latest replies
     * (shown in the row instead of the old replies modal), all eager loaded; 25 rows by default; tab counts cached.
     */
    private function listPage(Request $request): array
    {
        $locale = app()->getLocale();
        $translated = $locale !== 'en' && $locale !== config('app.fallback_locale', 'en');

        return [
            'comments' => Comment::filter()
                ->with([
                    'video' => fn (BelongsTo $query) => $query->withTrashed()->without('thumbnail')->with(['user:id,username', 'category:id,title'])
                        ->when($translated, fn ($query) => $query->with(['translations' => fn ($q) => $q->where('locale', $locale)])),
                    'user:id,username',
                    'replies' => fn (HasMany $query) => $query->with('user:id,username')->latest('id')->limit(3),
                ])
                ->whereNull('parent_id')
                ->withCount(['likes', 'replies'])
                ->orderBy('created_at', 'desc')
                ->paginate(AdminPagination::perPage($request, 25))
                ->withQueryString(),
            'counts' => AdminCache::remember('comments.tabs.v1', now()->addMinute(), function (): array {
                $counts = Comment::query()->whereNull('parent_id')->toBase()
                    ->selectRaw('COUNT(*) as total, SUM(CASE WHEN banned_at IS NULL THEN 0 ELSE 1 END) as hidden')
                    ->first();

                return ['all' => (int) ($counts->total ?? 0), 'hidden' => (int) ($counts->hidden ?? 0)];
            }),
        ];
    }

    public function ban (Comment $comment) : RedirectResponse {

        $comment->update([
            'banned_at' => now()
        ]);

        return redirect()->route('admin.comments.index')->withSuccess('Comment banned successfully.');
    }

    public function unban (Comment $comment) : RedirectResponse {

        $comment->update([
            'banned_at' => null
        ]);

        return redirect()->route('admin.comments.index')->withSuccess('Comment unbanned successfully.');
    }

    public function destroy (Comment $comment) : RedirectResponse {

        // Clean up interactions (likes/dislikes) on this comment
        $comment->interactions()->delete();
        // Clean up any replies
        $comment->replies()->each(function ($reply) {
            $reply->interactions()->delete();
            $reply->forceDelete();
        });
        $comment->forceDelete();

        return redirect()->route('admin.comments.index')->withSuccess('Comment deleted permanently.');
    }
}
