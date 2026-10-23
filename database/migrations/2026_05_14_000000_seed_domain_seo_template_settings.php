<?php

use App\Models\Setting;
use App\Services\SeoService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $defaults = [
            'profile_uid_urls_enabled' => '1',
            'video_pages_indexable' => '1',
            'category_pages_indexable' => '1',
            'profile_pages_indexable' => '1',
            'playlist_pages_indexable' => '1',
            'search_pages_indexable' => '0',
            ...SeoService::templateDefaults(),
        ];

        foreach ($defaults as $key => $value) {
            $this->setDefault('seo', $key, (string) $value);
        }

        Setting::clearCache(array_keys($defaults), ['seo']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $keys = array_merge([
            'profile_uid_urls_enabled',
            'video_pages_indexable',
            'category_pages_indexable',
            'profile_pages_indexable',
            'playlist_pages_indexable',
            'search_pages_indexable',
        ], array_keys(SeoService::templateDefaults()));

        DB::table('settings')
            ->where('group', 'seo')
            ->whereIn('key', $keys)
            ->delete();

        Setting::clearCache($keys, ['seo']);
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
