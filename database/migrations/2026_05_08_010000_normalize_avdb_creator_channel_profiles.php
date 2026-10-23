<?php

use App\Support\ChannelCustomizationSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('users')
            || ! Schema::hasColumn('users', 'avdb_creator_key')
            || ! Schema::hasColumn('users', 'channel_customization')
        ) {
            return;
        }

        DB::table('users')
            ->whereNotNull('avdb_creator_key')
            ->orderBy('id')
            ->select(['id', 'channel_customization'])
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'channel_customization' => json_encode(
                                $this->normalizedAvdbCreatorProfile($user->channel_customization),
                                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                            ),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Existing generated creator profiles remain valid if this migration is rolled back.
    }

    private function normalizedAvdbCreatorProfile(mixed $raw): array
    {
        $current = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $current = is_array($current) ? $current : [];

        return ChannelCustomizationSettings::normalizeForStorage(array_replace($current, [
            'layout' => 'spotlight',
            'surface' => 'glass',
            'banner_treatment' => 'cinematic',
            'motion' => 'gentle',
            'header_shape' => 'framed',
            'stats_style' => 'pills',
            'video_layout' => 'grid',
            'featured_layout' => 'compact',
            'featured_columns' => '3',
            'featured_rows' => '1',
            'latest_uploads_layout' => 'grid',
            'latest_uploads_columns' => '4',
            'latest_uploads_rows' => '2',
            'playlists_layout' => 'stacked',
            'density' => 'comfortable',
            'dedupe_home_sections' => '1',
            'show_stats' => '1',
            'show_featured_video' => '1',
            'show_latest_uploads' => '1',
            'show_about_panel' => '1',
            'show_upload_rhythm' => '1',
        ]));
    }
};
