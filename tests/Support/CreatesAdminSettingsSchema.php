<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAdminSettingsSchema
{
    protected function refreshAdminSettingsSchema(): void
    {
        $this->dropAdminSettingsSchema();
        $this->createAdminSettingsSchema();
    }

    protected function dropAdminSettingsSchema(): void
    {
        foreach ([
            'settings',
            'thumbnails',
            'tag_translations',
            'video_tags',
            'tags',
            'videos',
            'comments',
            'categories',
            'plans',
            'premiums',
            'favorites_playlist',
            'subscriptions',
            'playlists',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    protected function createAdminSettingsSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('slug')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('avatar')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->timestamp('banned_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->default('general');
            $table->timestamps();
        });

        $this->createAdminSettingsSupportTables();
    }

    protected function createAdminSettingsSupportTables(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->string('background')->nullable();
            $table->boolean('in_menu')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedTinyInteger('status')->default(0);
            $table->unsignedBigInteger('size')->default(0);
            $table->text('description')->nullable();
            $table->string('language', 8)->nullable();
            $table->dateTime('uploaded_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->unsignedInteger('usage_count')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('video_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('video_id');
            $table->unsignedBigInteger('tag_id');
            $table->timestamps();
            $table->unique(['video_id', 'tag_id']);
            $table->index('tag_id');
        });

        Schema::create('tag_translations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tag_id');
            $table->string('locale', 10);
            $table->string('name', 150);
            $table->timestamps();
            $table->unique(['tag_id', 'locale']);
            $table->index(['locale', 'name']);
        });

        Schema::create('thumbnails', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('video_id');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_id')->nullable();
            $table->string('paypal_plan_id')->nullable();
            $table->unsignedInteger('duration')->default(30);
            $table->timestamps();
        });

        Schema::create('premiums', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_status')->default('canceled');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('playlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('title')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('subscriber_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('subscribe_at')->nullable();
            $table->timestamp('read_at')->nullable();
        });

        Schema::create('favorites_playlist', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('playlist_id');
            $table->timestamp('added_at')->nullable();
        });
    }
}
