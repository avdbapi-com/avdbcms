<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('videos') && !Schema::hasColumn('videos', 'avdb_updated_at')) {
            Schema::table('videos', function (Blueprint $table) {
                $table->timestamp('avdb_updated_at')->nullable()->after('avdb_release_date')->index();
            });
        }

        if (Schema::hasTable('avdb_import_runs') && !Schema::hasColumn('avdb_import_runs', 'updated')) {
            Schema::table('avdb_import_runs', function (Blueprint $table) {
                $table->unsignedInteger('updated')->default(0)->after('skipped');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('avdb_import_runs') && Schema::hasColumn('avdb_import_runs', 'updated')) {
            Schema::table('avdb_import_runs', function (Blueprint $table) {
                $table->dropColumn('updated');
            });
        }

        if (Schema::hasTable('videos') && Schema::hasColumn('videos', 'avdb_updated_at')) {
            Schema::table('videos', function (Blueprint $table) {
                $table->dropColumn('avdb_updated_at');
            });
        }
    }
};
