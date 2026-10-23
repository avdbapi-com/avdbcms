<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'admin_preferences')) {
            return;
        }

        // Per-admin settings of the admin itself (App\Support\AdminPreferences): light/dark, accent,
        // advanced pages, Developer mode, old/new admin. A JSON object stored as text, so every
        // supported MySQL/MariaDB/SQLite version accepts it.
        Schema::table('users', function (Blueprint $table): void {
            $table->text('admin_preferences')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'admin_preferences')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('admin_preferences');
        });
    }
};
