<?php

use App\Support\HomeWidgetVideoSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->setDefault('comments', 'allow_replies', '1');

        foreach (HomeWidgetVideoSettings::contentDefaults() as $key => $value) {
            $this->setDefault('discovery', $key, $value);
        }

        $legacyMaintenanceMode = DB::table('settings')
            ->where('group', 'general')
            ->where('key', 'maintenance_mode')
            ->value('value');

        if ($legacyMaintenanceMode !== null && !DB::table('settings')->where('key', 'maintenance_mode_enabled')->exists()) {
            $this->setDefault('maintenance', 'maintenance_mode_enabled', (string) $legacyMaintenanceMode);
        }

        DB::table('settings')
            ->where(function ($query) {
                $query->where('group', 'player')
                    ->orWhere(function ($query) {
                        $query->where('group', 'comments')
                            ->where('key', 'comments_approval_required');
                    })
                    ->orWhere(function ($query) {
                        $query->where('group', 'general')
                            ->where('key', 'maintenance_mode');
                    });
            })
            ->delete();
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'allow_replies')->delete();

        foreach (array_keys(HomeWidgetVideoSettings::contentDefaults()) as $key) {
            DB::table('settings')->where('key', $key)->delete();
        }
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
