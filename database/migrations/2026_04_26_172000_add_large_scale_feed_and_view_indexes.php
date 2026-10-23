<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('videos', 'videos_status_views_id_idx', ['status', 'views', 'id']);
        $this->addIndexIfMissing('videos', 'videos_language_status_published_id_idx', ['language', 'status', 'published_at', 'id']);
        $this->addIndexIfMissing('videos', 'videos_language_status_views_id_idx', ['language', 'status', 'views', 'id']);

        $this->addIndexIfMissing('views', 'views_video_user_view_at_idx', ['video_id', 'user_id', 'view_at']);
        $this->addIndexIfMissing('views', 'views_video_ip_view_at_idx', ['video_id', 'ip', 'view_at']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('views', 'views_video_ip_view_at_idx');
        $this->dropIndexIfExists('views', 'views_video_user_view_at_idx');
        $this->dropIndexIfExists('videos', 'videos_language_status_views_id_idx');
        $this->dropIndexIfExists('videos', 'videos_language_status_published_id_idx');
        $this->dropIndexIfExists('videos', 'videos_status_views_id_idx');
    }

    private function addIndexIfMissing(string $table, string $name, array $columns): void
    {
        if (!Schema::hasTable($table) || $this->indexExists($table, $name)) {
            return;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
            $blueprint->index($columns, $name);
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (!Schema::hasTable($table) || ! $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name) {
            $blueprint->dropIndex($name);
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        return Schema::hasIndex($table, $name);
    }
};
