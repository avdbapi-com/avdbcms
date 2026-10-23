<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
            $table->string('locale', 5);        // e.g. 'ja', 'vi', 'zh'
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['video_id', 'locale']); // One translation per language per video
            $table->index('locale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_translations');
    }
};
