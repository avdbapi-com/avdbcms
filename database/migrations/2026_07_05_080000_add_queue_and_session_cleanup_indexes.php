<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('jobs', 'jobs_cleanup_candidate_idx', ['reserved_at', 'available_at', 'created_at', 'id']);
        $this->addIndexIfMissing('jobs', 'jobs_queue_cleanup_idx', ['queue', 'reserved_at', 'available_at', 'id']);
        $this->addIndexIfMissing('failed_jobs', 'failed_jobs_failed_at_id_idx', ['failed_at', 'id']);
        $this->addIndexIfMissing('job_batches', 'job_batches_finished_cancelled_idx', ['finished_at', 'cancelled_at', 'id']);
        $this->addIndexIfMissing('sessions', 'sessions_last_activity_id_idx', ['last_activity', 'id']);
        $this->addIndexIfMissing('sessions', 'sessions_user_last_activity_idx', ['user_id', 'last_activity']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('jobs', 'jobs_queue_cleanup_idx');
        $this->dropIndexIfExists('jobs', 'jobs_cleanup_candidate_idx');
        $this->dropIndexIfExists('failed_jobs', 'failed_jobs_failed_at_id_idx');
        $this->dropIndexIfExists('job_batches', 'job_batches_finished_cancelled_idx');
        $this->dropIndexIfExists('sessions', 'sessions_user_last_activity_idx');
        $this->dropIndexIfExists('sessions', 'sessions_last_activity_id_idx');
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
