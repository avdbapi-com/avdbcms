<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'videos_deleted_views_idx';

    public function up(): void
    {
        if (! Schema::hasTable('videos')
            || ! Schema::hasColumn('videos', 'deleted_at')
            || ! Schema::hasColumn('videos', 'views')
            || Schema::hasIndex('videos', self::INDEX)) {
            return;
        }

        Schema::table('videos', function (Blueprint $table): void {
            $table->index(['deleted_at', 'views'], self::INDEX);
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
