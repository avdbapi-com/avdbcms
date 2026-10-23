<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('videos', 'videos_user_updated_id_idx', ['user_id', 'updated_at', 'id']);
        $this->addIndexIfMissing('notifications', 'notifications_notifiable_created_id_idx', ['notifiable_type', 'notifiable_id', 'created_at', 'id']);
        $this->addIndexIfMissing('personal_access_tokens', 'personal_access_tokens_expires_id_idx', ['expires_at', 'id']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('personal_access_tokens', 'personal_access_tokens_expires_id_idx');
        $this->dropIndexIfExists('notifications', 'notifications_notifiable_created_id_idx');
        $this->dropIndexIfExists('videos', 'videos_user_updated_id_idx');
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
