<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DISCOVERY_KEYS = [
        'watch_momentum_digest_enabled',
        'watch_momentum_digest_limit',
        'watch_momentum_digest_lookback_days',
        'viewer_continue_queue_enabled',
        'viewer_continue_queue_limit',
        'viewer_continue_queue_recency_days',
    ];

    private const APPEARANCE_KEYS = [
        'home_widget_continue_queue_motion_direction',
        'home_widget_watch_momentum_motion_direction',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereIn('key', array_merge(self::DISCOVERY_KEYS, self::APPEARANCE_KEYS))
            ->delete();

        Setting::clearCache(array_merge(self::DISCOVERY_KEYS, self::APPEARANCE_KEYS), ['discovery', 'appearance']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach ($this->legacyDefaults() as $key => [$value, $group]) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => (string) $value,
                    'group' => $group,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        Setting::clearCache(array_keys($this->legacyDefaults()), ['discovery', 'appearance']);
    }

    private function legacyDefaults(): array
    {
        return [
            'watch_momentum_digest_enabled' => ['1', 'discovery'],
            'watch_momentum_digest_limit' => [6, 'discovery'],
            'watch_momentum_digest_lookback_days' => [14, 'discovery'],
            'viewer_continue_queue_enabled' => ['1', 'discovery'],
            'viewer_continue_queue_limit' => [8, 'discovery'],
            'viewer_continue_queue_recency_days' => [30, 'discovery'],
            'home_widget_continue_queue_motion_direction' => ['right', 'appearance'],
            'home_widget_watch_momentum_motion_direction' => ['left', 'appearance'],
        ];
    }
};
