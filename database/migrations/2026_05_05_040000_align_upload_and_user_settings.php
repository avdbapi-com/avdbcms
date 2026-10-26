<?php

use App\Enums\VideoStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->setDefault('uploads', 'upload_max_size', '2048');
        $this->setDefault('uploads', 'upload_max_duration', '3600');
        $this->setDefault('uploads', 'upload_allowed_formats', 'mp4,webm,avi,mov,mkv');
        $this->setDefault('uploads', 'default_video_language', 'en');
        $this->setDefault('uploads', 'allow_custom_thumbnails', '1');
        $this->setDefault('users', 'show_subscriber_count', '1');
        $this->setDefault('sociallinks', 'social_telegram', 'https://t.me/+jj6McO-iYfA0MTM1');
        $this->setDefault('seo', 'adult_rating_enabled', '1');

        $statusAliases = [
            'public' => (string) VideoStatus::PUBLIC->value,
            'private' => (string) VideoStatus::PRIVATE->value,
            'planned' => (string) VideoStatus::PLANNED->value,
            'unlisted' => (string) VideoStatus::UNLISTED->value,
        ];

        $currentStatus = DB::table('settings')
            ->where('group', 'uploads')
            ->where('key', 'default_video_status')
            ->value('value');

        if ($currentStatus === null) {
            $this->setDefault('uploads', 'default_video_status', (string) VideoStatus::PUBLIC->value);
        } elseif (array_key_exists(strtolower((string) $currentStatus), $statusAliases)) {
            DB::table('settings')
                ->where('group', 'uploads')
                ->where('key', 'default_video_status')
                ->update([
                    'value' => $statusAliases[strtolower((string) $currentStatus)],
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'default_video_language',
            'allow_custom_thumbnails',
            'show_subscriber_count',
        ])->delete();
    }

    private function setDefault(string $group, string $key, string $value): void
    {
        if (DB::table('settings')->where('key', $key)->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'key' => $key,
            'value' => $value,
            'group' => $group,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
