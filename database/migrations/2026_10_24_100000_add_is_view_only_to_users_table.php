<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * AVDBCMS 1.2.2: the view-only demo account (App\Http\Middleware\ViewOnlyDemo). Off for every account, existing ones
 * included; an administrator switches it on in Members > one member. Safe to run twice and on 1.2.0 / 1.2.1 databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'is_view_only')) {
            return;
        }

        // Added last (no ->after()), so MySQL / MariaDB add it without copying the users table.
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_view_only')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'is_view_only')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_view_only');
        });
    }
};
