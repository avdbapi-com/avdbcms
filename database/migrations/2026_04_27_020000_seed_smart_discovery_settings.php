<?php

use App\Models\Setting;
use App\Services\SmartDiscoveryRailService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('settings')) {
            return;
        }

        foreach ($this->settings() as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => $value,
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
        if (!Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereIn('key', array_keys($this->settings()))
            ->delete();

        Setting::clearCache(array_keys($this->settings()), ['discovery']);
    }

    private function settings(): array
    {
        return [
            'smart_discovery_rail_enabled' => SmartDiscoveryRailService::DEFAULT_ENABLED ? '1' : '0',
            'smart_discovery_rail_limit' => (string) SmartDiscoveryRailService::DEFAULT_LIMIT,
            'smart_discovery_rail_lookback_days' => (string) SmartDiscoveryRailService::DEFAULT_LOOKBACK_DAYS,
        ];
    }
};
