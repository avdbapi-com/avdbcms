<?php

namespace App\Services\Concerns;

use App\Enums\VideoStatus;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

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
}
