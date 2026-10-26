<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->default('general');
            $table->timestamps();
        });

        // Seed default settings
        $defaults = [
            ['key' => 'site_name', 'value' => 'AVDBCMS', 'group' => 'general'],
            ['key' => 'site_description', 'value' => 'Share and Watch Amazing Videos', 'group' => 'general'],
            ['key' => 'site_keywords', 'value' => 'video,streaming,upload', 'group' => 'general'],
            ['key' => 'registration_enabled', 'value' => '1', 'group' => 'general'],
            ['key' => 'upload_max_size', 'value' => '2048', 'group' => 'uploads'],
            ['key' => 'upload_max_duration', 'value' => '3600', 'group' => 'uploads'],
            ['key' => 'upload_allowed_formats', 'value' => 'mp4,webm,avi,mov,mkv', 'group' => 'uploads'],
            ['key' => 'comments_enabled', 'value' => '1', 'group' => 'comments'],
            ['key' => 'comments_approval_required', 'value' => '0', 'group' => 'comments'],
            ['key' => 'default_video_status', 'value' => 'public', 'group' => 'uploads'],
            ['key' => 'maintenance_mode', 'value' => '0', 'group' => 'general'],
            ['key' => 'contact_email', 'value' => 'support@example.com', 'group' => 'general'],
            ['key' => 'per_page_videos', 'value' => '24', 'group' => 'general'],
            ['key' => 'per_page_comments', 'value' => '20', 'group' => 'general'],
            ['key' => 'allow_social_login', 'value' => '1', 'group' => 'general'],
            ['key' => 'premium_enabled', 'value' => '1', 'group' => 'monetization'],
            ['key' => 'ads_enabled', 'value' => '0', 'group' => 'monetization'],
            ['key' => 'ad_code_header', 'value' => '', 'group' => 'monetization'],
            ['key' => 'ad_code_sidebar', 'value' => '', 'group' => 'monetization'],
            ['key' => 'ad_code_video', 'value' => '', 'group' => 'monetization'],
            ['key' => 'custom_css', 'value' => '', 'group' => 'appearance'],
            ['key' => 'custom_js', 'value' => '', 'group' => 'appearance'],
            ['key' => 'footer_text', 'value' => '(c) 2026 AVDBCMS. All rights reserved.', 'group' => 'appearance'],
            ['key' => 'smtp_host', 'value' => '', 'group' => 'email'],
            ['key' => 'smtp_port', 'value' => '587', 'group' => 'email'],
            ['key' => 'smtp_username', 'value' => '', 'group' => 'email'],
            ['key' => 'smtp_password', 'value' => '', 'group' => 'email'],
            ['key' => 'smtp_encryption', 'value' => 'tls', 'group' => 'email'],
            ['key' => 'mail_from_address', 'value' => 'noreply@example.com', 'group' => 'email'],
            ['key' => 'mail_from_name', 'value' => 'AVDBCMS', 'group' => 'email'],
            ['key' => 'social_telegram', 'value' => 'https://t.me/+jj6McO-iYfA0MTM1', 'group' => 'sociallinks'],
        ];

        foreach ($defaults as $setting) {
            DB::table('settings')->insert(array_merge($setting, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
