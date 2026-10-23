<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->usesMysql() && Schema::hasTable('videos') && Schema::hasColumn('videos', 'views')) {
            DB::statement('ALTER TABLE `videos` MODIFY `views` BIGINT UNSIGNED NOT NULL DEFAULT 0');
        }

        if ($this->usesMysql() && Schema::hasTable('playlist_has_videos') && Schema::hasColumn('playlist_has_videos', 'position')) {
            DB::statement('ALTER TABLE `playlist_has_videos` MODIFY `position` INT UNSIGNED NOT NULL DEFAULT 0');
        }

        $this->addIndexIfMissing('videos', 'videos_published_id_idx', ['published_at', 'id']);
        $this->addIndexIfMissing('videos', 'videos_views_id_idx', ['views', 'id']);

        $this->addIndexIfMissing('playlists', 'playlists_uuid_idx', ['uuid']);
        $this->addIndexIfMissing('playlists', 'playlists_user_status_updated_id_idx', ['user_id', 'status', 'updated_at', 'id']);

        $this->addIndexIfMissing('playlist_has_videos', 'playlist_has_videos_playlist_position_idx', ['playlist_id', 'position', 'id']);
        $this->addIndexIfMissing('playlist_has_videos', 'playlist_has_videos_playlist_added_idx', ['playlist_id', 'added_at', 'id']);
        $this->addIndexIfMissing('playlist_has_videos', 'playlist_has_videos_playlist_video_idx', ['playlist_id', 'video_id']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('playlist_has_videos', 'playlist_has_videos_playlist_video_idx');
        $this->dropIndexIfExists('playlist_has_videos', 'playlist_has_videos_playlist_added_idx');
        $this->dropIndexIfExists('playlist_has_videos', 'playlist_has_videos_playlist_position_idx');
        $this->dropIndexIfExists('playlists', 'playlists_user_status_updated_id_idx');
        $this->dropIndexIfExists('playlists', 'playlists_uuid_idx');
        $this->dropIndexIfExists('videos', 'videos_views_id_idx');
        $this->dropIndexIfExists('videos', 'videos_published_id_idx');

        if ($this->usesMysql() && Schema::hasTable('playlist_has_videos') && Schema::hasColumn('playlist_has_videos', 'position')) {
            DB::statement('ALTER TABLE `playlist_has_videos` MODIFY `position` TINYINT UNSIGNED NOT NULL DEFAULT 0');
        }

        if ($this->usesMysql() && Schema::hasTable('videos') && Schema::hasColumn('videos', 'views')) {
            DB::statement('ALTER TABLE `videos` MODIFY `views` INT NOT NULL DEFAULT 0');
        }
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

    private function usesMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
