<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('views', 'admin_views_view_at_id_idx', ['view_at', 'id']);
        $this->addIndexIfMissing('users', 'admin_users_created_id_idx', ['created_at', 'id']);
        $this->addIndexIfMissing('videos', 'admin_videos_created_id_idx', ['created_at', 'id']);
        $this->addIndexIfMissing('reports', 'admin_reports_created_id_idx', ['created_at', 'id']);
        $this->addIndexIfMissing('videos', 'dashboard_videos_user_created_id_idx', ['user_id', 'created_at', 'id']);
        $this->addIndexIfMissing('interactions', 'interactions_likeable_perform_idx', ['likeable_type', 'likeable_id', 'perform_at']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('interactions', 'interactions_likeable_perform_idx');
        $this->dropIndexIfExists('videos', 'dashboard_videos_user_created_id_idx');
        $this->dropIndexIfExists('reports', 'admin_reports_created_id_idx');
        $this->dropIndexIfExists('videos', 'admin_videos_created_id_idx');
        $this->dropIndexIfExists('users', 'admin_users_created_id_idx');
        $this->dropIndexIfExists('views', 'admin_views_view_at_id_idx');
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
