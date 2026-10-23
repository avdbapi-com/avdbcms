<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('avdb_import_schedules')) {
            return;
        }

        Schema::create('avdb_import_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->boolean('enabled')->default(true);
            $table->string('frequency', 24)->default('daily');
            $table->time('run_time')->nullable();
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('month_day')->nullable();
            $table->unsignedInteger('interval_minutes')->nullable();
            $table->boolean('skip_if_active')->default(true);
            $table->json('settings');
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('last_run_id')->nullable()->constrained('avdb_import_runs')->nullOnDelete();
            $table->string('last_status', 32)->nullable();
            $table->text('last_message')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamps();

            $table->index(['enabled', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avdb_import_schedules');
    }
};
