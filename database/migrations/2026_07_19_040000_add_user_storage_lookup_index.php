<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'videos_user_deleted_status_size_idx';

    public function up(): void
    {
        $columns = ['user_id', 'deleted_at', 'status', 'size'];

        if (! Schema::hasTable('videos') || Schema::hasIndex('videos', self::INDEX)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn('videos', $column)) {
                return;
            }
        }

        Schema::table('videos', function (Blueprint $table) use ($columns): void {
            $table->index($columns, self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('videos') || ! Schema::hasIndex('videos', self::INDEX)) {
            return;
        }

        Schema::table('videos', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
