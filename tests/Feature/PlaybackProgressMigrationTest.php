<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlaybackProgressMigrationTest extends TestCase
{
    private const MIGRATION_PATH = 'database/migrations/2026_04_27_042000_create_playback_progress_signals_and_settings.php';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('playback_progress_signals');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('videos');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('videos', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->default('general');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('playback_progress_signals');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('videos');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_playback_progress_migration_runs_and_rolls_back_on_sqlite(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->up();

        $this->assertTrue(Schema::hasTable('playback_progress_signals'));
        $this->assertTrue(Schema::hasIndex('playback_progress_signals', 'playback_progress_user_video_unique'));
        $this->assertTrue(Schema::hasIndex('playback_progress_signals', 'playback_progress_user_expiry_played_idx'));
        $this->assertTrue(Schema::hasIndex('playback_progress_signals', 'playback_progress_video_played_idx'));
        $this->assertTrue(Schema::hasIndex('playback_progress_signals', 'playback_progress_expiry_id_idx'));
        $this->assertSame(3, DB::table('settings')->whereIn('key', $this->settingKeys())->count());

        $userId = DB::table('users')->insertGetId([
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $videoId = DB::table('videos')->insertGetId([
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('playback_progress_signals')->insert([
            'user_id' => $userId,
            'video_id' => $videoId,
            'position_seconds' => 42,
            'duration_seconds' => 120,
            'progress_basis_points' => 3500,
            'last_played_at' => now(),
            'expires_at' => now()->addDays(90),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('playback_progress_signals', [
            'user_id' => $userId,
            'video_id' => $videoId,
            'position_seconds' => 42,
        ]);

        $migration->down();

        $this->assertFalse(Schema::hasTable('playback_progress_signals'));
        $this->assertSame(0, DB::table('settings')->whereIn('key', $this->settingKeys())->count());
    }

    private function settingKeys(): array
    {
        return [
            'playback_progress_signal_enabled',
            'playback_progress_signal_min_duration_seconds',
            'playback_progress_signal_retention_days',
        ];
    }
}
