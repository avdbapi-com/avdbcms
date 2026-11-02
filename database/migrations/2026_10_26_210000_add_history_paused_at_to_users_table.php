<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Pause history" (member design 6.11): users.history_paused_at is the time a member paused their watch
 * history, NULL while it is on (the default for every member). While it is set, App\Services\ViewService stores no new
 * history row for that member; the video still counts the view. Added only when missing (a site that ran a test build
 * keeps its data) and removed on rollback. Until "Finish update" has added it, the History page does not offer pausing
 * (App\Support\WatchHistory::pauseReady()).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'history_paused_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('history_paused_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'history_paused_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('history_paused_at');
            });
        }
    }
};
