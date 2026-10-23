<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('videos', 'videos_status_uploaded_id_updated_idx', ['status', 'uploaded_at', 'id', 'updated_at']);
        $this->addIndexIfMissing('videos', 'videos_status_scheduled_uploaded_id_idx', ['status', 'scheduled_at', 'uploaded_at', 'id']);
        $this->addIndexIfMissing('videos', 'videos_language_status_uploaded_id_idx', ['language', 'status', 'uploaded_at', 'id']);
        $this->addIndexIfMissing('video_translations', 'video_translations_locale_video_idx', ['locale', 'video_id']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('video_translations', 'video_translations_locale_video_idx');
        $this->dropIndexIfExists('videos', 'videos_language_status_uploaded_id_idx');
        $this->dropIndexIfExists('videos', 'videos_status_scheduled_uploaded_id_idx');
        $this->dropIndexIfExists('videos', 'videos_status_uploaded_id_updated_idx');
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
