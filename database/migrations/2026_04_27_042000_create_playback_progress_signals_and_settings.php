<?php

use App\Models\Setting;
use App\Services\PlaybackProgressSignalService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('playback_progress_signals')) {
            Schema::create('playback_progress_signals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('video_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('position_seconds');
                $table->unsignedInteger('duration_seconds');
                $table->unsignedSmallInteger('progress_basis_points')->default(0);
                $table->timestamp('last_played_at');
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'video_id'], 'playback_progress_user_video_unique');
                $table->index(['user_id', 'expires_at', 'last_played_at'], 'playback_progress_user_expiry_played_idx');
                $table->index(['video_id', 'last_played_at'], 'playback_progress_video_played_idx');
                $table->index(['expires_at', 'id'], 'playback_progress_expiry_id_idx');
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
        if (Schema::hasTable('playback_progress_signals')) {
            Schema::dropIfExists('playback_progress_signals');
        }

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
        $defaults = PlaybackProgressSignalService::settingsDefaults();

        return [
            'playback_progress_signal_enabled' => $defaults['enabled'],
            'playback_progress_signal_min_duration_seconds' => $defaults['min_duration_seconds'],
            'playback_progress_signal_retention_days' => $defaults['retention_days'],
        ];
    }
};
