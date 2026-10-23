<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('avdb_import_runs')) {
            return;
        }

        Schema::create('avdb_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('queued')->index();
            $table->json('settings')->nullable();
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('lang_skipped')->default(0);
            $table->unsignedInteger('tags_created')->default(0);
            $table->unsignedInteger('thumbs_downloaded')->default(0);
            $table->unsignedInteger('total_pages')->default(0);
            $table->unsignedInteger('completed_pages')->default(0);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedTinyInteger('current_category')->nullable();
            $table->unsignedInteger('current_page')->nullable();
            $table->string('message', 500)->nullable();
            $table->longText('log')->nullable();
            $table->boolean('cancel_requested')->default(false)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avdb_import_runs');
    }
};
