<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Video;
use App\Services\SeoService;
use App\Support\Studio\StudioFormat;
use App\Support\Studio\StudioSettings;
use App\Support\Studio\ZoneLabel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The studio's Comments page (member design 6.8). The security rule (S02) is kept everywhere: this page reads
 * and removes comments on the member's OWN videos only; any other comment id answers 404 (the list, the replies sheet,
 * a removal and the bulk removal have the same scope). A comment's author removes it from the video page (the API and
 * CommentPolicy); admins in the admin.
 */
class CommentController extends Controller
{
    public function index(Request $request, SeoService $seo) : View|RedirectResponse {

        $filters = [];

        foreach (['search', 'video'] as $key) {
            $value = $request->query($key);

            if (is_string($value) && trim($value) !== '') {
                $filters[$key] = trim($value);
            }
        }

        $date = $request->query('date');

        if (is_string($date) && in_array($date, ['7', '28', '90'], true)) {
            $filters['date_start'] = CarbonImmutable::now(ZoneLabel::zone())->startOfDay()->subDays(((int) $date) - 1)->toDateTimeString();
        }

        // The search box reads "Search comments or names": it matches the text and the author's name (the shared
        // CommentFilters::search, used by the admin too, matches the text only).
        $search = $filters['search'] ?? null;
        $listFilters = array_diff_key($filters, ['search' => true]);

        $comments = Comment::filter($listFilters === [] ? ['__none' => '1'] : $listFilters)
            ->whereHas('video', function (Builder $query) {
                $query->where('user_id', Auth::id());
            })
            ->when($search !== null, function (Builder $query) use ($search) {
                $like = '%'.addcslashes((string) $search, '\%_').'%';
                $query->where(function (Builder $query) use ($like) {
                    $query->where('comments.content', 'LIKE', $like)
                        ->orWhereHas('user', fn (Builder $user) => $user->where('username', 'LIKE', $like));
                });
            })
            ->whereNull('parent_id')
            // 1.2.3 round 1 (R1-165): the comments and replies viewers see; one a moderator hid (banned_at) is not listed
            // or counted here (the sheet listed it without a word and counted "2 replies" for 1)
            ->whereNull('comments.banned_at')
            // each row links its video: the link format may name the category (an admin choice), loaded with the page
            // instead of once per video; the owner is the member (set below without a query)
            ->with(['user', 'video' => fn ($videoQuery) => $videoQuery->with(array_values(array_diff($seo->videoPathRelations(), ['user'])))])
            ->withCount(['replies' => fn (Builder $query) => $query->whereNull('banned_at')])
            ->orderBy('comments.created_at', 'desc')
            ->orderBy('comments.id', 'desc')
            ->paginate(StudioSettings::int('per_page_comments', 20, 1, 100))
            ->withQueryString();

        // A page past the end of the list (its last comments were removed): its last page, with the same filters.
        if ($comments->total() > 0 && $comments->currentPage() > $comments->lastPage()) {
            return redirect()->to($comments->url($comments->lastPage()));
        }

        $comments->getCollection()->each(fn (Comment $comment) => $comment->video?->setRelation('user', Auth::user()));

        return view('users.comments.index', [
            'comments' => $comments,
            'videos' => Video::query()
                ->withoutGlobalScopes()
                ->where('user_id', Auth::id())
                ->whereNull('deleted_at')
                // the videos the list above can show a comment of (R1-165: a comment a moderator hid is not listed, so a
                // video whose only comments are hidden is not offered by the filter either)
                ->whereHas('comments', fn (Builder $query) => $query->whereNull('parent_id')->whereNull('banned_at'))
                ->orderBy('title')
                ->limit(200)
                ->get(['id', 'title']),
            'filtersActive' => $filters !== [],
            'days' => is_string($date) && in_array($date, ['7', '28', '90'], true) ? (int) $date : null,
            'previewLength' => StudioSettings::int('comment_preview_length', 500, 50, 5000),
            'commentsEnabled' => StudioSettings::on('comments_enabled', true),
        ]);
    }

    public function destroy(Request $request, Comment $comment): RedirectResponse {

        // Only a comment on one of the member's own videos (the same scope as the list and massDelete); any other
        // comment answers as if it did not exist.
        abort_unless((int) $comment->video?->user_id === (int) Auth::id(), 404);

        $isReply = $comment->parent_id !== null;
        // "The comment and its replies are removed from your video" (the dialog of Comments and of the dashboard): a
        // comment goes with its replies, as the bulk removal does. Before, this single removal deleted the comment only:
        // its replies stayed in the database under a parent that no longer existed, and the video's stored comment
        // count kept counting them.
        $this->remove(new Collection([$comment]));
        // the dashboard's cached lists and figures must not show it any more (studio-29)
        \App\Support\Studio\Dashboard::forget(Auth::id());

        return redirect()->back()->with('success', $isReply ? __('Reply removed.') : __('Comment removed.'));
    }

    public function massDelete(Request $request): RedirectResponse {

        $request->validate([
            'ids' => 'required|array|max:500',
            'ids.*' => 'integer',
        ]);

        // Only delete comments that belong to videos owned by the current user
        $comments = Comment::whereIn('id', $request->ids)
            ->whereHas('video', fn(Builder $q) => $q->where('user_id', Auth::id()))
            ->get();

        $this->remove($comments);
        \App\Support\Studio\Dashboard::forget(Auth::id());

        return redirect()->back()->with('success', trans_choice('Deleted 1 comment.|Deleted :count comments.', $comments->count(), ['count' => StudioFormat::number($comments->count())]));
    }

    /**
     * Removes comments of the member's videos for good, each top-level one with all of its replies (what every studio
     * dialog says), in one transaction. Every row goes through the model exactly once, so CommentObserver counts each
     * one off its video's stored comments_count and the activity listener drops its activity:
     *  - a reply that is also in the list (a hand-made request may name a reply and its comment) is removed once, with
     *    the list, never a second time through its comment (a second delete would count it off the video twice);
     *  - replies are read in id order (chunkById), never in offset pages, which skip rows while rows are deleted.
     *
     * @param  Collection<int, Comment>  $comments  comments on the member's own videos (checked by the caller)
     */
    private function remove(Collection $comments): void
    {
        if ($comments->isEmpty()) {
            return;
        }

        $listed = $comments->map(fn (Comment $comment) => (int) $comment->getKey())->all();
        $parents = $comments->whereNull('parent_id')->map(fn (Comment $comment) => (int) $comment->getKey())->values()->all();

        DB::transaction(function () use ($comments, $listed, $parents): void {
            $drop = function (Comment $comment): void {
                $comment->interactions()->delete();
                $comment->forceDelete();
            };

            if ($parents !== []) {
                Comment::query()
                    ->whereIn('parent_id', $parents)
                    ->whereNotIn('id', $listed)
                    ->chunkById(200, function ($replies) use ($drop): void {
                        foreach ($replies as $reply) {
                            $drop($reply);
                        }
                    });
            }

            foreach ($comments as $comment) {
                $drop($comment);
            }
        });
    }

    /**
     * The replies sheet of a comment (member design 6.8, studio-21): the replies of a comment on one of the member's
     * own videos (else 404, the security rule), newest last, per_page_replies at a time. Fetched into the sheet (an HTML
     * part) or opened as a page of its own without JavaScript.
     */
    public function replies(Request $request, Comment $comment): View|RedirectResponse
    {
        abort_unless((int) $comment->video?->user_id === (int) Auth::id(), 404);

        $comment->loadMissing(['user', 'video']);
        $comment->video?->setRelation('user', Auth::user());

        $replies = Comment::query()
            ->where('parent_id', $comment->id)
            // the replies viewers see (R1-165): one a moderator hid is not listed or counted
            ->whereNull('banned_at')
            ->with('user')
            ->orderBy('comments.created_at')
            ->orderBy('comments.id')
            ->paginate(StudioSettings::int('per_page_replies', 10, 1, 100), ['*'], 'page')
            ->withQueryString();

        $data = [
            'comment' => $comment,
            'replies' => $replies,
            'me' => Auth::user(),
        ];

        if ($request->ajax() || $request->boolean('part')) {
            return view('users.comments.partials.replies', $data);
        }

        // the page of its own: a page past the end is its last page, as in the studio's lists (1.2.3 round 1, R1-108)
        if ($replies->total() > 0 && $replies->currentPage() > $replies->lastPage()) {
            return redirect()->to($replies->url($replies->lastPage()));
        }

        return view('users.comments.replies', $data);
    }
}
