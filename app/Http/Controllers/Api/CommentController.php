<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comment\StoreCommentRequest;
use App\Http\Requests\Comment\UpdateCommentRequest;
use App\Http\Resources\CommentResource;
use App\Http\Resources\RepliesCollection;
use App\Models\Comment;
use App\Models\Video;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class CommentController extends Controller
{

    public function __construct()
    {
        $this->authorizeResource('App\Models\Comment,video', ['comment,video']);
    }

    /**
     * @param Video $video
     * @param Request $request
     * @return ResourceCollection
     * @throws AuthorizationException
     */
    public function index(Video $video, Request $request) : ResourceCollection {

        $sort = $request->get('sort', 'top');

        return (CommentResource::collection(
            $video
                ->comments()
                ->public()
                ->whereNull('parent_id')
                ->with([
                    'user',
                    'video' => fn($q) => $q->with(['user', 'pinned_comment']),
                    'replies' => function($q) {
                        return $q
                            ->public()
                            ->with([
                                'user',
                                'video' => fn($q) => $q->with('user'),
                                'replies',
                                'reportByAuthUser',
                            ])
                            ->withCount([
                                'likes',
                                'dislikes'
                            ])
                            ->withExists([
                                'likes as liked_by_auth_user' => fn($q) => $q->where('user_id', Auth::id()),
                                'dislikes as disliked_by_auth_user' => fn($q) => $q->where('user_id', Auth::id()),
                            ])
                            ->when(true, fn($query) => $this->orderByTopScore($query))
                            ->latest()
                            ->limit(config('site.per_page_replies', 10));
                    },
                    'reportByAuthUser'
                ])
                ->withCount([
                    'replies as total_replies' => fn($q) => $q->public(),
                    'likes',
                    'dislikes'
                ])
                ->withExists([
                    'likes as liked_by_auth_user' => fn($q) => $q->where('user_id', Auth::id()),
                    'dislikes as disliked_by_auth_user' => fn($q) => $q->where('user_id', Auth::id()),
                    'replies as is_video_author_reply' => fn($q) => $q->where('user_id', $video->user_id),
                    'likes as is_video_author_like' => fn($q) => $q->where('user_id', $video->user_id),
                ])
                ->when(
                    $video->pinned_comment_id,
                    fn($query) => $query->orderByRaw(
                        'CASE WHEN id = ? THEN 0 ELSE 1 END',
                        [(int) $video->pinned_comment_id]
                    )
                )
                ->when($sort === 'top', fn($query) => $this->orderByTopScore($query))
                ->latest()
                ->simplePaginate(config('site.per_page_comments', 20))
                ->withQueryString()
        ))->additional([
            'count' => $video->comments()->public()->count(),
        ]);
    }

    public function replies (Video $video, Comment $comment, Request $request) : ResourceCollection {

        $sort = $request->get('sort', 'top');

        return (new RepliesCollection(
            $comment
                ->loadCount('replies')
                ->replies()
                ->with([
                    'user',
                    'video' => fn($q) => $q->with('user'),
                    'replies',
                    'reportByAuthUser'
                ])
                ->withCount([
                    'likes',
                    'dislikes'
                ])
                ->withExists([
                    'likes as liked_by_auth_user' => fn($q) => $q->where('user_id', Auth::id()),
                    'dislikes as disliked_by_auth_user' => fn($q) => $q->where('user_id', Auth::id())
                ])
                ->when($sort === 'top', fn($query) => $this->orderByTopScore($query))
                ->latest()
                ->simplePaginate(config('site.per_page_replies', 10))
                ->withQueryString()
        ))->additional([
            'total' => $comment->replies_count,
        ]);

    }

    public function store (Video $video, StoreCommentRequest $request) : CommentResource
    {
        // Enforce admin settings toggle
        if (!config('site.comments_enabled', true)) {
            abort(403, __('Comments are currently disabled.'));
        }

        $validated = $request->safe()->merge([
            'user_id' => Auth::user()->id,
        ])->toArray();

        $comment = $video->comments()->create($validated);

        return new CommentResource($comment);
    }

    public function update(Video $video, Comment $comment, UpdateCommentRequest $request): CommentResource
    {
        $comment->update($request->validated());

        return new CommentResource($comment);
    }

    public function delete(Video $video, Comment $comment): Response
    {
        $this->authorize('delete', $comment);

        $comment->interactions()->delete();
        $comment->forceDelete();
        return response()->noContent();
    }

    public function pin(Video $video, Comment $comment): Response
    {
        $this->authorize('pin', $comment);

        $comment->video->update([
            'pinned_comment_id' => $comment->id
        ]);

        return response()->noContent();
    }

    public function unpin(Video $video, Comment $comment): Response
    {
        $this->authorize('unpin', $comment);

        $comment->video->update([
            'pinned_comment_id' => null
        ]);

        return response()->noContent();
    }

    private function orderByTopScore($query)
    {
        return $query->orderByRaw(
            '((select count(*) from interactions where comments.id = interactions.likeable_id and interactions.likeable_type = ? and interactions.status = ?) - (select count(*) from interactions where comments.id = interactions.likeable_id and interactions.likeable_type = ? and interactions.status = ?)) DESC',
            [Comment::class, 1, Comment::class, 0]
        );
    }
}
