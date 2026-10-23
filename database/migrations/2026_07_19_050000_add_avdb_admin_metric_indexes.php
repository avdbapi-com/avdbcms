<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CATEGORY_INDEX = 'videos_avdb_category_metric_idx';

    public function up(): void
    {
        $this->addIndex(self::CATEGORY_INDEX, ['deleted_at', 'category_id', 'avdb_id']);
    }

    public function down(): void
    {
        $this->dropIndex(self::CATEGORY_INDEX);
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addIndex(string $name, array $columns): void
    {
        if (! Schema::hasTable('videos') || Schema::hasIndex('videos', $name)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn('videos', $column)) {
                return;
            }
        }

        Schema::table('videos', function (Blueprint $table) use ($columns, $name): void {
            $table->index($columns, $name);
        });
    }

    private function dropIndex(string $name): void
    {
        if (! Schema::hasTable('videos') || ! Schema::hasIndex('videos', $name)) {
            return;
        }

        Schema::table('videos', function (Blueprint $table) use ($name): void {
            $table->dropIndex($name);
        });
    }
};
