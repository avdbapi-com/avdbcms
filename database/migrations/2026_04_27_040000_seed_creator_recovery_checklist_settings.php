<?php

use App\Models\Setting;
use App\Services\CreatorRecoveryChecklistService;
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
                    'group' => 'users',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        Setting::clearCache(array_keys($this->settings()), ['users']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereIn('key', array_keys($this->settings()))
            ->delete();

        Setting::clearCache(array_keys($this->settings()), ['users']);
    }

    private function settings(): array
    {
        $defaults = CreatorRecoveryChecklistService::settingsDefaults();

        return [
            'creator_recovery_checklist_enabled' => $defaults['enabled'],
            'creator_recovery_checklist_limit' => $defaults['limit'],
            'creator_recovery_checklist_inactivity_days' => $defaults['inactivity_days'],
        ];
    }
};
