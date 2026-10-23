<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('videos')) {
            Schema::table('videos', function (Blueprint $table) {
                if (!Schema::hasColumn('videos', 'poster_url')) {
                    $table->string('poster_url', 500)->nullable()->after('embed_code');
                }

                if (!Schema::hasColumn('videos', 'avdb_id')) {
                    $table->unsignedInteger('avdb_id')->nullable()->unique()->after('poster_url');
                }
            });
        }

        if (Schema::hasTable('video_translations')) {
            Schema::table('video_translations', function (Blueprint $table) {
                if (!Schema::hasColumn('video_translations', 'country')) {
                    $table->string('country', 100)->nullable()->after('description');
                }

                if (!Schema::hasColumn('video_translations', 'type_name')) {
                    $table->string('type_name', 100)->nullable()->after('country');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('video_translations')) {
            Schema::table('video_translations', function (Blueprint $table) {
                if (Schema::hasColumn('video_translations', 'type_name')) {
                    $table->dropColumn('type_name');
                }

                if (Schema::hasColumn('video_translations', 'country')) {
                    $table->dropColumn('country');
                }
            });
        }
    }
};
