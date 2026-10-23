<?php

use App\Models\Setting;
use App\Services\AdaptiveCreatorSpotlightService;
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

        foreach ($this->settings() as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => (string) $value,
                    'group' => 'discovery',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        Setting::clearCache(array_keys($this->settings()), ['discovery']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereIn('key', array_keys($this->settings()))
            ->delete();

        Setting::clearCache(array_keys($this->settings()), ['discovery']);
    }

    private function settings(): array
    {
        $defaults = AdaptiveCreatorSpotlightService::settingsDefaults();

        return [
            'creator_spotlight_enabled' => $defaults['enabled'],
            'creator_spotlight_limit' => $defaults['limit'],
            'creator_spotlight_lookback_days' => $defaults['lookback_days'],
        ];
    }
};
