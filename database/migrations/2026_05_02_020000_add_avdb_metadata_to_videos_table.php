<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('videos')) {
            return;
        }

        Schema::table('videos', function (Blueprint $table) {
            if (!Schema::hasColumn('videos', 'avdb_code')) {
                $table->string('avdb_code', 100)->nullable()->after('avdb_id')->index();
            }

            if (!Schema::hasColumn('videos', 'avdb_actor')) {
                $table->string('avdb_actor', 500)->nullable()->after('avdb_code');
            }

            if (!Schema::hasColumn('videos', 'avdb_director')) {
                $table->string('avdb_director', 255)->nullable()->after('avdb_actor');
            }

            if (!Schema::hasColumn('videos', 'avdb_writer')) {
                $table->string('avdb_writer', 255)->nullable()->after('avdb_director');
            }

            if (!Schema::hasColumn('videos', 'avdb_country')) {
                $table->string('avdb_country', 100)->nullable()->after('avdb_writer');
            }

            if (!Schema::hasColumn('videos', 'avdb_release_date')) {
                $table->date('avdb_release_date')->nullable()->after('avdb_country')->index();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('videos')) {
            return;
        }

        Schema::table('videos', function (Blueprint $table) {
            foreach (['avdb_release_date', 'avdb_country', 'avdb_writer', 'avdb_director', 'avdb_actor', 'avdb_code'] as $column) {
                if (Schema::hasColumn('videos', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
