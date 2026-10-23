<?php

use App\Models\Setting;
use App\Services\SearchHistoryRetentionService;
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
                    'value' => (string) $value,
                    'group' => 'general',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        Setting::clearCache(array_keys($this->settings()), ['general']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereIn('key', array_keys($this->settings()))
            ->delete();

        Setting::clearCache(array_keys($this->settings()), ['general']);
    }

    private function settings(): array
    {
        $defaults = SearchHistoryRetentionService::settingsDefaults();

        return [
            'search_history_retention_days' => $defaults['retention_days'],
            'search_history_max_rows' => $defaults['max_rows'],
            'search_history_cleanup_interval_minutes' => $defaults['cleanup_interval_minutes'],
        ];
    }
};
