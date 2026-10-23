<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('videos', 'videos_uuid_idx', ['uuid']);
        $this->addIndexIfMissing('videos', 'videos_status_published_id_idx', ['status', 'published_at', 'id']);
        $this->addIndexIfMissing('videos', 'videos_status_scheduled_id_idx', ['status', 'scheduled_at', 'id']);
        $this->addIndexIfMissing('videos', 'videos_user_status_published_id_idx', ['user_id', 'status', 'published_at', 'id']);
        $this->addIndexIfMissing('videos', 'videos_category_status_published_id_idx', ['category_id', 'status', 'published_at', 'id']);

        $this->addIndexIfMissing('thumbnails', 'thumbnails_video_active_status_idx', ['video_id', 'is_active', 'status', 'id']);

        $this->addIndexIfMissing('comments', 'comments_video_parent_created_idx', ['video_id', 'parent_id', 'created_at', 'id']);
        $this->addIndexIfMissing('comments', 'comments_user_created_idx', ['user_id', 'created_at', 'id']);

        $this->addIndexIfMissing('views', 'views_video_created_idx', ['video_id', 'created_at', 'id']);
        $this->addIndexIfMissing('views', 'views_user_created_idx', ['user_id', 'created_at', 'id']);

        $this->addIndexIfMissing('interactions', 'interactions_likeable_status_idx', ['likeable_type', 'likeable_id', 'status', 'id']);
        $this->addIndexIfMissing('interactions', 'interactions_user_likeable_idx', ['user_id', 'likeable_type', 'likeable_id']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('interactions', 'interactions_user_likeable_idx');
        $this->dropIndexIfExists('interactions', 'interactions_likeable_status_idx');
        $this->dropIndexIfExists('views', 'views_user_created_idx');
        $this->dropIndexIfExists('views', 'views_video_created_idx');
        $this->dropIndexIfExists('comments', 'comments_user_created_idx');
        $this->dropIndexIfExists('comments', 'comments_video_parent_created_idx');
        $this->dropIndexIfExists('thumbnails', 'thumbnails_video_active_status_idx');
        $this->dropIndexIfExists('videos', 'videos_category_status_published_id_idx');
        $this->dropIndexIfExists('videos', 'videos_user_status_published_id_idx');
        $this->dropIndexIfExists('videos', 'videos_status_scheduled_id_idx');
        $this->dropIndexIfExists('videos', 'videos_status_published_id_idx');
        $this->dropIndexIfExists('videos', 'videos_uuid_idx');
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
        if (!Schema::hasTable($table) || !$this->indexExists($table, $name)) {
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
