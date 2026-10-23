<?php

use App\Models\Setting;
use App\Services\ViewHistoryRetentionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('video_view_daily_stats')) {
            Schema::create('video_view_daily_stats', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('video_id')->constrained()->cascadeOnDelete();
                $table->date('view_date');
                $table->unsignedBigInteger('views_count')->default(0);
                $table->timestamps();

                $table->unique(['video_id', 'view_date'], 'video_view_daily_video_date_unique');
                $table->index(['view_date', 'video_id'], 'video_view_daily_date_video_idx');
            });
        }

        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach ($this->settings() as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => (string) $value,
                    'group' => 'comments',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        Setting::clearCache(array_keys($this->settings()), ['comments']);
    }

    public function down(): void
    {
        if (Schema::hasTable('video_view_daily_stats')) {
            Schema::dropIfExists('video_view_daily_stats');
        }

        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereIn('key', array_keys($this->settings()))
            ->delete();

        Setting::clearCache(array_keys($this->settings()), ['comments']);
    }

    private function settings(): array
    {
        $defaults = ViewHistoryRetentionService::settingsDefaults();

        return [
            'view_log_mode' => $defaults['log_mode'],
            'view_daily_stats_enabled' => $defaults['daily_stats_enabled'],
            'view_history_retention_days' => $defaults['retention_days'],
        ];
    }
};
