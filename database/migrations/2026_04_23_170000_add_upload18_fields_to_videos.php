<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->string('embed_code', 255)->nullable()->after('file')->comment('Upload18 embed hash for iframe player');
            $table->string('upload18_vid', 255)->nullable()->after('embed_code')->comment('Upload18 video ID for API operations');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['embed_code', 'upload18_vid']);
        });
    }
};
