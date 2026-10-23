<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_audit_logs')) {
            Schema::create('admin_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('action', 150);
                $table->text('details')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('created_at')->nullable()->index();
            });
        }

        if (!Schema::hasTable('announcements')) {
            Schema::create('announcements', function (Blueprint $table) {
                $table->id();
                $table->string('title', 200);
                $table->text('message');
                $table->string('type', 20)->default('info');
                $table->string('position', 20)->default('top');
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('starts_at')->nullable()->index();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('contact_messages')) {
            Schema::create('contact_messages', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->string('subject')->nullable();
                $table->text('message');
                $table->string('ip_address', 45)->nullable();
                $table->boolean('is_read')->default(false)->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('ip_bans')) {
            Schema::create('ip_bans', function (Blueprint $table) {
                $table->id();
                $table->string('ip_address', 45)->unique();
                $table->text('reason')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('redirects')) {
            Schema::create('redirects', function (Blueprint $table) {
                $table->id();
                $table->string('source', 500)->unique();
                $table->string('destination', 500);
                $table->unsignedSmallInteger('status_code')->default(301);
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedBigInteger('hits')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }

        if (!Schema::hasTable('tags')) {
            Schema::create('tags', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('slug', 120)->unique();
                $table->unsignedInteger('usage_count')->default(0)->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('video_tags')) {
            Schema::create('video_tags', function (Blueprint $table) {
                $table->id();
                $table->foreignId('video_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['video_id', 'tag_id']);
                $table->index('tag_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('video_tags');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('ip_bans');
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('admin_audit_logs');
    }
};
