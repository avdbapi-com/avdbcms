<?php

namespace App\Filters;

use App\Enums\VideoStatus;
use App\Filters\Drivers\MySQLFilter;
use App\Filters\Traits\DateFilter;
use App\Filters\Traits\UserFilter;
use App\Support\Studio\ZoneLabel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class VideoFilters extends MySQLFilter
{
    use DateFilter, UserFilter;

    /** The date presets of My videos' "Date" filter (member design 6.3). */
    public const DATE_PRESETS = ['7', '28', '90', 'year', 'custom'];

    protected string $dateField = 'published_at';

    public function search(string $search): Builder
    {
        $match = '%'.$search.'%';

        return $this->builder->where(fn($query) => $query->where('title', 'LIKE', $match)->orWhere('description', 'LIKE', $match));
    }

    public function status(string $status): Builder
    {
        return $this->builder->when($status == VideoStatus::PUBLIC->value, fn(Builder $query) => $query->scopes('active'))
            ->when($status == VideoStatus::PLANNED->value, fn($query) => $query->where('status', VideoStatus::PLANNED->value)->where('scheduled_at', '>', now()))
            ->when(in_array($status, [VideoStatus::PRIVATE->value, VideoStatus::UNLISTED->value, VideoStatus::BANNED->value, VideoStatus::DRAFT->value, VideoStatus::FAILED->value]), fn($query) => $query->where('status', $status));
    }

    /** ?unpublished=1: the public videos that were never published (Content > All videos points the owner to them). */
    public function unpublished(mixed $value): Builder
    {
        return $this->builder->when($value === '1', fn (Builder $query) => $query->neverPublished());
    }

    /**
     * ?reason=blocked-words: the videos the update to 1.2.3 banned because they use words that are always blocked
     * (App\Support\BlockedWordsPass; Home's "Needs you" links to them). Any other value changes nothing.
     */
    public function reason(string $reason): Builder
    {
        return $reason === \App\Support\BlockedWordsPass::FILTER
            ? \App\Support\BlockedWordsPass::constrain($this->builder)
            : $this->builder;
    }

    public function category(string $category): Builder
    {
        return $this->builder->when($category === 'without', fn($query) => $query->doesntHave('category'))
            ->when($category !== 'without', fn($query) => $query->whereRelation('category', 'id', $category));
    }

    /** Type (member design 6.23): "videos" leaves the Shorts out, "shorts" keeps only them. */
    public function type(string $type): Builder
    {
        return $this->builder
            ->when($type === 'videos', fn ($query) => $query->where('videos.is_short', false))
            ->when($type === 'shorts', fn ($query) => $query->where('videos.is_short', true));
    }

    /**
     * Date presets of My videos: the last 7, 28 or 90 days (today included) or this year, by the day the video went
     * public or was uploaded, in the site's time zone. "custom" reads from / to. (date_start / date_end keep their
     * meaning for the other lists that use this filter: the publication date.)
     */
    public function date(string $preset): Builder
    {
        $today = CarbonImmutable::now(ZoneLabel::zone())->startOfDay();
        $from = match ($preset) {
            '7', '28', '90' => $today->subDays(((int) $preset) - 1),
            'year' => $today->startOfYear(),
            default => null,
        };

        if ($from === null) {
            return $this->builder;
        }

        return $this->builder->where(fn ($query) => $this->listDateFrom($query, $from));
    }

    /** My videos' custom dates, from a day (Y-m-d): the list's date is the publication date, else the upload date. */
    public function from(string $date): Builder
    {
        $day = $this->day($date);

        if ($day === null) {
            return $this->builder;
        }

        return $this->builder->where(fn ($query) => $this->listDateFrom($query, $day));
    }

    /** My videos' custom dates, to a day (Y-m-d), that whole day included. */
    public function to(string $date): Builder
    {
        $day = $this->day($date);

        if ($day === null) {
            return $this->builder;
        }

        $end = $day->endOfDay();

        return $this->builder->where(function ($query) use ($end): void {
            $query->where(fn ($q) => $q->whereNotNull('videos.published_at')->where('videos.published_at', '<=', $end))
                ->orWhere(fn ($q) => $q->whereNull('videos.published_at')->where('videos.created_at', '<=', $end));
        });
    }

    private function listDateFrom(Builder $query, CarbonImmutable $from): void
    {
        $query->where(fn ($q) => $q->whereNotNull('videos.published_at')->where('videos.published_at', '>=', $from))
            ->orWhere(fn ($q) => $q->whereNull('videos.published_at')->where('videos.created_at', '>=', $from));
    }

    private function day(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if ($value === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value, ZoneLabel::zone())?->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
