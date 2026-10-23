<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            if (! Schema::hasColumn('videos', 'avdb_duration_sync_attempted_at')) {
                $table->timestamp('avdb_duration_sync_attempted_at')->nullable()->after('duration')->index();
            }

            if (! Schema::hasColumn('videos', 'avdb_duration_sync_failed_count')) {
                $table->unsignedSmallInteger('avdb_duration_sync_failed_count')->default(0)->after('avdb_duration_sync_attempted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            if (Schema::hasColumn('videos', 'avdb_duration_sync_failed_count')) {
                $table->dropColumn('avdb_duration_sync_failed_count');
            }

            if (Schema::hasColumn('videos', 'avdb_duration_sync_attempted_at')) {
                $table->dropColumn('avdb_duration_sync_attempted_at');
            }
        });
    }
};
