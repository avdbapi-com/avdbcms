<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('videos', 'videos_related_user_cat_status_deleted_pub_idx', [
            'user_id',
            'category_id',
            'status',
            'deleted_at',
            'published_at',
            'id',
        ]);

        $this->addIndexIfMissing('videos', 'videos_related_user_cat_lang_status_deleted_pub_idx', [
            'user_id',
            'category_id',
            'language',
            'status',
            'deleted_at',
            'published_at',
            'id',
        ]);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('videos', 'videos_related_user_cat_lang_status_deleted_pub_idx');
        $this->dropIndexIfExists('videos', 'videos_related_user_cat_status_deleted_pub_idx');
    }

    private function addIndexIfMissing(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->index($columns, $name);
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });
    }
};
