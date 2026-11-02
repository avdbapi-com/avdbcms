<?php

namespace App\Support;

use App\Models\Comment;
use Illuminate\Support\Facades\DB;

/**
 * The replies under comments that go for good (judge R3-009): a site installed from the bundled database has the foreign
 * key comments_parent_id_foreign (ON DELETE CASCADE), which removes them with their comment; a site installed by the
 * migrations has none, and they stayed under a parent that no longer existed (and in their video's comment count). The
 * site removes them itself, the same on both: every reply under the comments, replies of replies too, also those in the
 * bin, with their likes and dislikes (the explicit deletes of the comment pages did the same).
 */
final class CommentReplies
{
    /** Rows per statement. */
    private const CHUNK = 500;

    /** The deepest chain of replies followed (comments are one level deep; a guard against a loop in damaged data). */
    private const MAX_DEPTH = 20;

    /**
     * Deletes every reply under $parentIds (never one of $parentIds themselves). Returns the ids of the videos whose
     * comments changed (count them again).
     *
     * @param  array<int, int|string>  $parentIds
     * @return list<int>
     */
    public static function drop(array $parentIds): array
    {
        $parents = array_values(array_unique(array_filter(array_map('intval', $parentIds), fn (int $id): bool => $id > 0)));
        $seen = array_fill_keys($parents, true);
        $videos = [];
        $comment = (new Comment())->getMorphClass();

        for ($depth = 0; $parents !== [] && $depth < self::MAX_DEPTH; $depth++) {
            $next = [];

            foreach (array_chunk($parents, self::CHUNK) as $chunk) {
                $replies = DB::table('comments')->whereIn('parent_id', $chunk)->get(['id', 'video_id'])
                    ->reject(fn (object $reply): bool => isset($seen[(int) $reply->id]));

                foreach ($replies->chunk(self::CHUNK) as $part) {
                    $ids = $part->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
                    DB::table('interactions')->where('likeable_type', $comment)->whereIn('likeable_id', $ids)->delete();
                    DB::table('comments')->whereIn('id', $ids)->delete();

                    foreach ($part as $reply) {
                        $seen[(int) $reply->id] = true;
                        $next[] = (int) $reply->id;
                        $videos[(int) $reply->video_id] = true;
                    }
                }
            }

            $parents = $next;
        }

        unset($videos[0]);

        return array_keys($videos);
    }
}
