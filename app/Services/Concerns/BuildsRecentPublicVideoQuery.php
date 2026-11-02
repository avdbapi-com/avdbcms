<?php

namespace App\Services\Concerns;

use App\Enums\VideoStatus;
use App\Support\DiscoverySnapshots;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

trait BuildsRecentPublicVideoQuery
{
    private function recentPublicVideosSince(CarbonInterface $since): Closure
    {
        return function (EloquentBuilder $query) use ($since): EloquentBuilder {
            $this->applyActiveLocalizedVideoFilters($query);

            return $query->where(function (EloquentBuilder $query) use ($since): void {
                $query->where('videos.published_at', '>=', $since)
                    ->orWhere('videos.scheduled_at', '>=', $since);
            });
        };
    }

    private function applyJoinedActiveLocalizedVideoFilters(QueryBuilder $query, string $table = 'videos'): QueryBuilder
    {
        return $this->applyActiveLocalizedVideoFilters($query, $table)
            ->whereNull($this->qualifiedVideoColumn($table, 'deleted_at'));
    }

    private function applyActiveLocalizedVideoFilters(EloquentBuilder|QueryBuilder $query, string $table = 'videos'): EloquentBuilder|QueryBuilder
    {
        return $query
            ->where(function (EloquentBuilder|QueryBuilder $query) use ($table): void {
                $query->where(function (EloquentBuilder|QueryBuilder $query) use ($table): void {
                    $query->where($this->qualifiedVideoColumn($table, 'status'), VideoStatus::PUBLIC->value)
                        ->whereNotNull($this->qualifiedVideoColumn($table, 'uploaded_at'))
                        ->where(function (EloquentBuilder|QueryBuilder $query) use ($table): void {
                            $query->whereNull($this->qualifiedVideoColumn($table, 'published_at'))
                                ->orWhere($this->qualifiedVideoColumn($table, 'published_at'), '<=', now());
                        });
                })->orWhere(function (EloquentBuilder|QueryBuilder $query) use ($table): void {
                    $query->where($this->qualifiedVideoColumn($table, 'status'), VideoStatus::PLANNED->value)
                        ->where($this->qualifiedVideoColumn($table, 'scheduled_at'), '<=', now())
                        ->whereNotNull($this->qualifiedVideoColumn($table, 'uploaded_at'));
                });
            })
            ->where(function (EloquentBuilder|QueryBuilder $query) use ($table): void {
                $locale = app()->getLocale();
                if ($locale === 'en' || $locale === config('app.fallback_locale', 'en')) {
                    $query->where($this->qualifiedVideoColumn($table, 'language'), $locale)
                        ->orWhereNull($this->qualifiedVideoColumn($table, 'language'));

                    return;
                }

                $query->where(function (EloquentBuilder|QueryBuilder $imported) use ($table): void {
                    $imported->whereNotNull($this->qualifiedVideoColumn($table, 'avdb_id'))
                        ->orWhereNotNull($this->qualifiedVideoColumn($table, 'xvid_id'));
                })->orWhere(function (EloquentBuilder|QueryBuilder $native) use ($table, $locale): void {
                    $native->whereNull($this->qualifiedVideoColumn($table, 'avdb_id'))
                        ->whereNull($this->qualifiedVideoColumn($table, 'xvid_id'))
                        ->where(function (EloquentBuilder|QueryBuilder $language) use ($table, $locale): void {
                            $language->where($this->qualifiedVideoColumn($table, 'language'), $locale)
                                ->orWhereNull($this->qualifiedVideoColumn($table, 'language'));
                        });
                });
            });
    }

    private function qualifiedVideoColumn(string $table, string $column): string
    {
        return $table . '.' . $column;
    }

    /** The window of a rail as 1.2.2 wrote it: the videos published or scheduled since $since. */
    private function recentWindowCondition(QueryBuilder $query, CarbonInterface $since): QueryBuilder
    {
        return $query->where(function (QueryBuilder $query) use ($since): void {
            $query->where('videos.published_at', '>=', $since)
                ->orWhere('videos.scheduled_at', '>=', $since);
        });
    }

    /**
     * The ids of the videos a rail's window can count (public or scheduled, published or scheduled since $since), as a
     * subquery whose one column is avd_window_id, each id once: two ranges of the (status, published_at, id) and (status,
     * scheduled_at, id) indexes alone, so the exact count of a rail starts from its window's videos (the rail's own
     * conditions, unchanged, then decide which of them count). 1.2.2's statement left that to the database, which on MySQL
     * 8.4 read every tag of every video for it (6.5-17 s at 300,000 videos whatever the window; 0.2 s for a window of 6,000
     * videos and 3.6 s for one of 185,000 this way).
     */
    private function recentWindowIds(CarbonInterface $since): QueryBuilder
    {
        $statuses = [VideoStatus::PUBLIC->value, VideoStatus::PLANNED->value];

        return DB::table('videos')
            ->select('videos.id as avd_window_id')
            ->whereIn('videos.status', $statuses)
            ->where('videos.published_at', '>=', $since)
            ->union(DB::table('videos')
                ->select('videos.id as avd_window_id')
                ->whereIn('videos.status', $statuses)
                ->where('videos.scheduled_at', '>=', $since));
    }

    /**
     * "STRAIGHT_JOIN $columns" on MySQL / MariaDB, $columns elsewhere: a rail's exact count reads its tables in the order
     * written, its window's videos first (both chose another table first at 300,000 videos and read every video or every
     * tag of every video for it: MySQL 8.4 21 s instead of 3.6 s for a 30-day window of 185,000 videos).
     */
    private function windowFirst(string $columns): \Illuminate\Contracts\Database\Query\Expression
    {
        return DB::raw(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'STRAIGHT_JOIN '.$columns : $columns);
    }

    /**
     * Whether a rail's window holds few enough videos for the page to count it itself (at most
     * App\Support\DiscoverySnapshots::INLINE_ROWS) and, with $catalogue, whether the whole catalogue does too (the
     * creators rail's count of every date): one statement of capped counts of the indexes alone (a few ms at 300,000
     * videos).
     *
     * @return array{window: bool, catalogue: bool}
     */
    private function recentWindowIsSmall(CarbonInterface $since, bool $catalogue = false): array
    {
        $cap = DiscoverySnapshots::inlineRows() + 1;
        $statuses = [VideoStatus::PUBLIC->value, VideoStatus::PLANNED->value];
        $sizes = DB::query();

        foreach (['published_at', 'scheduled_at'] as $column) {
            $ids = DB::table('videos')
                ->select('videos.id')
                ->whereIn('videos.status', $statuses)
                ->where('videos.'.$column, '>=', $since)
                ->limit($cap);

            $sizes->selectSub(DB::query()->fromSub($ids, 'avd_window')->selectRaw('count(*)'), 'avd_by_'.$column);
        }

        if ($catalogue) {
            $sizes->selectSub(DB::query()->fromSub(DB::table('videos')->select('videos.id')->limit($cap), 'avd_all')->selectRaw('count(*)'), 'avd_catalogue');
        }

        $row = (array) $sizes->first();

        return [
            'window' => (int) ($row['avd_by_published_at'] ?? $cap) + (int) ($row['avd_by_scheduled_at'] ?? $cap) <= DiscoverySnapshots::inlineRows(),
            'catalogue' => $catalogue && (int) ($row['avd_catalogue'] ?? $cap) <= DiscoverySnapshots::inlineRows(),
        ];
    }
}
