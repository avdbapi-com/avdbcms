<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'videos_slug_index';

    public function up(): void
    {
        if (! Schema::hasTable('videos')
            || ! Schema::hasColumn('videos', 'slug')
            || Schema::hasIndex('videos', self::INDEX)) {
            return;
        }

        Schema::table('videos', function (Blueprint $table): void {
            $table->index('slug', self::INDEX);
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
