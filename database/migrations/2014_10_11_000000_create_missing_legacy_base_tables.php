<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createCategories();
        $this->createUsers();
        $this->createPlans();
        $this->createVideos();
        $this->createVideoSupportTables();
        $this->createPlaylistTables();
        $this->createPremiumTables();
        $this->createPlatformTables();
    }

    public function down(): void
    {
        foreach ([
            'views',
            'transactions',
            'thumbnails',
            'subscriptions',
            'subtitles',
            'searches',
            'reports',
            'premiums',
            'playlist_has_videos',
            'personal_access_tokens',
            'password_reset_tokens',
            'notifications',
            'job_batches',
            'interactions',
            'favorites_playlist',
            'failed_jobs',
            'exports',
            'comments',
            'videos',
            'users_activity',
            'playlists',
            'plans',
            'users',
            'categories',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createCategories(): void
    {
        if (Schema::hasTable('categories')) {
            return;
        }

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->nullable();
            $table->string('icon')->nullable();
            $table->string('background')->nullable();
            $table->boolean('in_menu')->default(false);
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
        });
    }

    private function createUsers(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('slug')->nullable()->unique();
            $table->string('email')->unique();
            $table->string('temporary_email')->nullable();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->boolean('is_admin')->nullable();
            $table->rememberToken();
            $table->string('avatar')->nullable();
            $table->string('banner')->nullable();
            $table->text('description')->nullable();
            $table->string('country', 3)->nullable();
            $table->string('website')->nullable();
            $table->boolean('show_subscribers')->default(true);
            $table->timestamp('banned_at')->nullable();
            $table->unsignedBigInteger('pinned_video_id')->nullable();
            $table->string('facebook_id')->nullable()->unique();
            $table->string('google_id')->nullable()->unique();
            $table->string('stripe_id')->nullable()->unique();
            $table->string('phone')->nullable()->unique();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('phone_confirmation_code')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users_activity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->timestamp('perform_at')->useCurrent();
            $table->index('user_id');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    private function createPlans(): void
    {
        if (Schema::hasTable('plans')) {
            return;
        }

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('price');
            $table->smallInteger('duration');
            $table->string('stripe_id')->nullable()->unique();
            $table->timestamps();
        });
    }

    private function createVideos(): void
    {
        if (Schema::hasTable('videos')) {
            return;
        }

        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('file')->nullable();
            $table->string('original_file_name');
            $table->string('original_mimetype')->nullable();
            $table->string('duration')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedTinyInteger('status')->default(0);
            $table->string('language', 3)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('allow_comments')->default(true);
            $table->string('default_comments_sort')->default('top');
            $table->boolean('show_likes')->default(true);
            $table->unsignedBigInteger('pinned_comment_id')->nullable();
            $table->timestamp('banned_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->string('youtube_id')->nullable();
            $table->boolean('is_live')->default(false);
            $table->boolean('is_short')->default(false);
            $table->integer('views')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index('category_id');
            $table->index('user_id');
        });
    }

    private function createVideoSupportTables(): void
    {
        if (!Schema::hasTable('comments')) {
            Schema::create('comments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('video_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->longText('content');
                $table->string('ip', 45);
                $table->timestamp('banned_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index('video_id');
                $table->index('user_id');
                $table->index('parent_id');
            });
        }

        if (!Schema::hasTable('interactions')) {
            Schema::create('interactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('likeable_type');
                $table->unsignedBigInteger('likeable_id');
                $table->boolean('status');
                $table->timestamp('perform_at')->useCurrent();
                $table->index('user_id');
                $table->index(['likeable_type', 'likeable_id']);
            });
        }

        if (!Schema::hasTable('reports')) {
            Schema::create('reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('reportable_type');
                $table->unsignedBigInteger('reportable_id');
                $table->string('reason');
                $table->text('comment')->nullable();
                $table->unsignedTinyInteger('status')->default(0);
                $table->timestamps();
                $table->index('user_id');
                $table->index(['reportable_type', 'reportable_id']);
            });
        }

        if (!Schema::hasTable('searches')) {
            Schema::create('searches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('query');
                $table->timestamp('perform_at')->useCurrent();
                $table->index('user_id');
            });
        }

        if (!Schema::hasTable('thumbnails')) {
            Schema::create('thumbnails', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('video_id');
                $table->string('file')->nullable();
                $table->boolean('is_active')->default(false);
                $table->unsignedTinyInteger('status')->default(0);
                $table->timestamps();
                $table->index('video_id');
            });
        }

        if (!Schema::hasTable('subtitles')) {
            Schema::create('subtitles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('video_id');
                $table->string('name');
                $table->string('language', 3);
                $table->string('file');
                $table->unsignedTinyInteger('status')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->index('video_id');
            });
        }

        if (!Schema::hasTable('views')) {
            Schema::create('views', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('video_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('ip', 45);
                $table->string('user_agent')->nullable();
                $table->timestamp('view_at')->useCurrent();
                $table->index('video_id');
                $table->index('user_id');
            });
        }
    }

    private function createPlaylistTables(): void
    {
        if (!Schema::hasTable('playlists')) {
            Schema::create('playlists', function (Blueprint $table) {
                $table->id();
                $table->uuid();
                $table->string('title');
                $table->text('description')->nullable();
                $table->unsignedBigInteger('user_id');
                $table->unsignedTinyInteger('status')->default(0);
                $table->boolean('is_deletable')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->index('user_id');
            });
        }

        if (!Schema::hasTable('playlist_has_videos')) {
            Schema::create('playlist_has_videos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('playlist_id');
                $table->unsignedBigInteger('video_id');
                $table->unsignedTinyInteger('position')->default(0);
                $table->index('playlist_id');
                $table->index('video_id');
            });
        }

        if (!Schema::hasTable('favorites_playlist')) {
            Schema::create('favorites_playlist', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('playlist_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('added_at')->useCurrent();
                $table->index('playlist_id');
                $table->index('user_id');
            });
        }

        if (!Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subscriber_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('subscribe_at')->useCurrent();
                $table->timestamp('read_at')->useCurrent();
                $table->index('subscriber_id');
                $table->index('user_id');
            });
        }
    }

    private function createPremiumTables(): void
    {
        if (!Schema::hasTable('premiums')) {
            Schema::create('premiums', function (Blueprint $table) {
                $table->id();
                $table->string('stripe_status');
                $table->timestamp('next_payment')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('plan_id')->nullable();
                $table->string('stripe_id')->nullable()->unique();
                $table->timestamp('trial_ends_at')->nullable();
                $table->string('card_last4');
                $table->date('card_expired_at');
                $table->timestamp('ends_at')->nullable();
                $table->timestamps();
                $table->index('user_id');
                $table->index('plan_id');
            });
        }

        if (!Schema::hasTable('transactions')) {
            Schema::create('transactions', function (Blueprint $table) {
                $table->id();
                $table->string('stripe_id')->unique();
                $table->unsignedInteger('amount');
                $table->unsignedInteger('tax');
                $table->unsignedInteger('fee');
                $table->timestamp('date');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('subscription_id')->nullable();
                $table->string('name');
                $table->string('address');
                $table->string('city');
                $table->string('postal_code');
                $table->string('country');
                $table->string('vat_id')->nullable();
                $table->index('user_id');
                $table->index('subscription_id');
            });
        }
    }

    private function createPlatformTables(): void
    {
        if (!Schema::hasTable('exports')) {
            Schema::create('exports', function (Blueprint $table) {
                $table->id();
                $table->string('file')->nullable();
                $table->unsignedInteger('size')->nullable();
                $table->unsignedTinyInteger('status')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        if (!Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('type')->nullable();
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
    }
};
