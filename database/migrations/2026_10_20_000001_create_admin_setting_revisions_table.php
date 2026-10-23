<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_setting_revisions')) {
            return;
        }

        // One history for settings changes: the upgrade snapshot (#0), publishes, theme/look applies, factory
        // resets, restores and settings saves. before/after hold a group's complete stored rows, or the keys a
        // save wrote nested by group (group = 'seo,general'); see App\Support\SettingsRevision.
        Schema::create('admin_setting_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32)->index();
            $table->string('group', 191)->default('appearance')->index();
            $table->longText('before')->nullable();
            $table->longText('after')->nullable();
            $table->text('changed')->nullable();
            $table->text('meta')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_setting_revisions');
    }
};
