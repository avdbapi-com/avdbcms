<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PublicVideoFeedService
{
    protected static ?array $existingIndexes = null;

    public function home(string $source = 'newest', int $categoryId = 0): Builder
    {
        return match ($source) {
            'most_viewed' => $this->mostViewed(),
            'most_commented' => $this->mostCommented(),
            'trending' => $this->trending(),
            'category' => $categoryId > 0 ? $this->categoryId($categoryId) : $this->latest(),
            default => $this->latest(),
        };
    }

    public function latest(): Builder
    {
        return $this->baseQuery('videos_status_published_id_idx')
            ->latest('published_at')
            ->orderByDesc('id');
    }

    public function mostViewed(): Builder
    {
        return $this->baseQuery('videos_status_views_id_idx')
            ->orderByDesc('views')
            ->latest('published_at')
            ->orderByDesc('id');
    }

    public function mostCommented(): Builder
    {
        return $this->baseQuery()
            ->withCount(['comments as comments_count' => fn ($query) => $query->public()])
            ->orderByDesc('comments_count')
            ->latest('published_at')
            ->orderByDesc('id');
    }

    public function trending(): Builder
    {
        return $this->baseQuery('videos_status_views_id_idx')
            ->orderByDesc('views')
            ->latest('published_at')
            ->orderByDesc('id');
    }

    public function category(Category $category): Builder
    {
        return $this->categoryId($category->id);
    }

    public function categoryId(int $categoryId): Builder
    {
        return $this->baseQuery('videos_category_status_published_id_idx')
            ->where('category_id', $categoryId)
            ->latest('published_at')
            ->orderByDesc('id');
    }

    public function baseQuery(?string $mysqlIndex = null): Builder
    {
        $query = Video::query();

        if ($mysqlIndex) {
            $this->applyIndex($query, $mysqlIndex);
        }

        return $query
            ->active()
            ->forLocale();
    }

    protected function applyIndex(Builder $query, string $indexName): Builder
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return $query;
        }

        if (static::$existingIndexes === null) {
            try {
                static::$existingIndexes = collect(DB::select('SHOW INDEX FROM videos'))
                    ->pluck('Key_name')
                    ->flip()
                    ->all();
            } catch (\Throwable) {
                static::$existingIndexes = [];
            }
        }

        if (isset(static::$existingIndexes[$indexName])) {
            $query->forceIndex($indexName);
        }

        return $query;
    }
}

