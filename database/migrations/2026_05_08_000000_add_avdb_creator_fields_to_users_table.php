<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'avdb_creator_key')) {
                $table->string('avdb_creator_key', 191)->nullable()->unique()->after('language');
            }

            if (! Schema::hasColumn('users', 'avdb_creator_source')) {
                $table->string('avdb_creator_source', 32)->nullable()->after('avdb_creator_key');
            }

            if (! Schema::hasColumn('users', 'avdb_creator_name')) {
                $table->string('avdb_creator_name', 150)->nullable()->after('avdb_creator_source');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            foreach (['avdb_creator_name', 'avdb_creator_source', 'avdb_creator_key'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
