<?php

namespace App\Filters;

use App\Filters\Drivers\MySQLFilter;
use App\Filters\Traits\DateFilter;
use App\Models\Comment;
use App\Models\Interaction;
use App\Models\Video;
use App\Support\Studio\ZoneLabel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class ActivityFilters extends MySQLFilter
{
    use DateFilter;

    /** The filter links of the Activity page (member design 6.8): All, Likes, Dislikes, Comments, Replies. */
    public const TYPES = ['likes', 'dislikes', 'comments', 'replies'];

    protected string $dateField = 'perform_at';

    public function type(string $value): Builder
    {
        return $this->builder->when($value === 'video_likes', fn($q) => $q->whereHasMorph('subject', [Interaction::class], fn($query) => $query->whereHasMorph('likeable', [Video::class])->where('status', true)))
            ->when($value === 'comment_likes', fn($q) => $q->whereHasMorph('subject', [Interaction::class], fn($query) => $query->whereHasMorph('likeable', [Comment::class])->where('status', true)))
            ->when($value === 'video_dislikes', fn($q) => $q->whereHasMorph('subject', [Interaction::class], fn($query) => $query->whereHasMorph('likeable', [Video::class])->where('status', false)))
            ->when($value === 'comment_dislikes', fn($q) => $q->whereHasMorph('subject', [Interaction::class], fn($query) => $query->whereHasMorph('likeable', [Comment::class])->where('status', false)))
            ->when($value === 'interactions', fn($q) => $q->where('subject_type', Interaction::class))
            // studio-9: likes and dislikes read the interaction's own status (the activities table has no "properties"
            // column: the filter answered 500)
            ->when($value === 'likes', fn($q) => $q->whereHasMorph('subject', [Interaction::class], fn($query) => $query->where('status', true)))
            ->when($value === 'dislikes', fn($q) => $q->whereHasMorph('subject', [Interaction::class], fn($query) => $query->where('status', false)))
            ->when($value === 'comments', fn($q) => $q->whereHasMorph('subject', [Comment::class], fn($query) => $query->withTrashed()->whereNull('parent_id')))
            ->when($value === 'replies', fn($q) => $q->whereHasMorph('subject', [Comment::class], fn($query) => $query->withTrashed()->whereNotNull('parent_id')));
    }

    /** Date presets of the Activity page: the last 7, 28 or 90 days (today included), in the site's time zone. */
    public function date(string $preset): Builder
    {
        if (! in_array($preset, ['7', '28', '90'], true)) {
            return $this->builder;
        }

        $from = CarbonImmutable::now(ZoneLabel::zone())->startOfDay()->subDays(((int) $preset) - 1);

        return $this->builder->where('perform_at', '>=', $from);
    }
}
