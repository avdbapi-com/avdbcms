<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('users', 'users_email_verified_id_idx', [
            'email_verified_at',
            'id',
        ]);

        $this->addIndexIfMissing('premiums', 'premiums_status_ends_user_idx', [
            'stripe_status',
            'ends_at',
            'user_id',
        ]);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('premiums', 'premiums_status_ends_user_idx');
        $this->dropIndexIfExists('users', 'users_email_verified_id_idx');
    }

    private function addIndexIfMissing(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->index($columns, $name);
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });
    }
};
