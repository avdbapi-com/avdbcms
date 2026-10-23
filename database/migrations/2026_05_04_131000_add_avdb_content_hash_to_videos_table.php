<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('videos') && !Schema::hasColumn('videos', 'avdb_content_hash')) {
            Schema::table('videos', function (Blueprint $table) {
                $table->string('avdb_content_hash', 64)->nullable()->after('avdb_updated_at')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('videos') && Schema::hasColumn('videos', 'avdb_content_hash')) {
            Schema::table('videos', function (Blueprint $table) {
                $table->dropColumn('avdb_content_hash');
            });
        }
    }
};
