<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'videos_avdb_duration_metric_idx';

    public function up(): void
    {
        if (! Schema::hasTable('videos') || ! Schema::hasIndex('videos', self::INDEX)) {
            return;
        }

        Schema::table('videos', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    public function down(): void
    {
        // The replaced index is intentionally not restored.
    }
};
