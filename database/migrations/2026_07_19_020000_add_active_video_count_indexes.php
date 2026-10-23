<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        'videos_user_active_count_idx' => [
            'user_id',
            'status',
            'deleted_at',
            'uploaded_at',
            'published_at',
            'scheduled_at',
        ],
        'videos_category_active_count_idx' => [
            'category_id',
            'status',
            'deleted_at',
            'uploaded_at',
            'published_at',
            'scheduled_at',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('videos')) {
            return;
        }

        foreach (self::INDEXES as $name => $columns) {
            if (Schema::hasIndex('videos', $name) || ! $this->hasColumns($columns)) {
                continue;
            }

            Schema::table('videos', function (Blueprint $table) use ($name, $columns): void {
                $table->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('videos')) {
            return;
        }

        foreach (array_reverse(array_keys(self::INDEXES)) as $name) {
            if (! Schema::hasIndex('videos', $name)) {
                continue;
            }

            Schema::table('videos', function (Blueprint $table) use ($name): void {
                $table->dropIndex($name);
            });
        }
    }

    private function hasColumns(array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn('videos', $column)) {
                return false;
            }
        }

        return true;
    }
};
