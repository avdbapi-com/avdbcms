<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('notifications', 'notifications_unread_count_idx', [
            'notifiable_type',
            'notifiable_id',
            'read_at',
            'id',
        ]);

        $this->addIndexIfMissing('searches', 'searches_perform_id_idx', [
            'perform_at',
            'id',
        ]);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('searches', 'searches_perform_id_idx');
        $this->dropIndexIfExists('notifications', 'notifications_unread_count_idx');
    }

    private function addIndexIfMissing(string $table, string $name, array $columns): void
    {
        if (!Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
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
        if (!Schema::hasTable($table) || !Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name) {
            $blueprint->dropIndex($name);
        });
    }
};
