<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('searches', 'searches_query_perform_at_idx', ['query', 'perform_at']);
        $this->addFullTextIfMissing('videos', 'videos_title_description_fulltext', ['title', 'description']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('videos', 'videos_title_description_fulltext');
        $this->dropIndexIfExists('searches', 'searches_query_perform_at_idx');
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

    private function addFullTextIfMissing(string $table, string $name, array $columns): void
    {
        if (
            Schema::getConnection()->getDriverName() !== 'mysql'
            || !Schema::hasTable($table)
            || $this->indexExists($table, $name)
        ) {
            return;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
            $blueprint->fullText($columns, $name);
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
