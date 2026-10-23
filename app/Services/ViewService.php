<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ViewService
{
    public function __construct(private readonly ViewHistoryRetentionService $retention)
    {
    }

    private function getCooldownMinutes(): int
    {
        return (int) config('site.view_cooldown_minutes', 5);
    }

    private function skipIncrementView(Video $video) : bool
    {
        $manager = app('impersonate');

        if ($manager->isImpersonating()) {
            return true;
        }

        $cooldown = $this->getCooldownMinutes();

        if ($cooldown <= 0) {
            return false;
        }

        return ! Cache::add($this->cooldownKey($video), true, now()->addMinutes($cooldown));
    }

    private function cooldownKey(Video $video): string
    {
        $identity = Auth::check()
            ? 'user:' . Auth::id()
            : 'guest:' . sha1((string) request()->getClientIp() . '|' . (string) request()->userAgent());

        return 'video:view-cooldown:' . $video->id . ':' . $identity;
    }

    public function incrementView(Video $video): void
    {
        if ($this->skipIncrementView($video)) {
            return;
        }

        $video->increment('views');
        $this->incrementDailyStats($video);

        if (! $this->retention->shouldStoreRawView(Auth::user())) {
            return;
        }

        $video->viewsHistory()->create([
            'ip' => request()->getClientIp(),
            'user_id' => Auth::id(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255),
        ]);
    }

    private function incrementDailyStats(Video $video): void
    {
        if (! $this->retention->dailyStatsEnabled()) {
            return;
        }

        $date = now()->toDateString();
        $timestamp = now();

        if (DB::connection()->getDriverName() === 'mysql') {
            try {
                DB::statement(
                    'INSERT INTO video_view_daily_stats (video_id, view_date, views_count, created_at, updated_at)
                     VALUES (?, ?, 1, ?, ?)
                     ON DUPLICATE KEY UPDATE views_count = views_count + 1, updated_at = VALUES(updated_at)',
                    [$video->getKey(), $date, $timestamp, $timestamp]
                );

                return;
            } catch (QueryException) {
                // Fall back to the portable path below if the optimized upsert is unavailable.
            }
        }

        try {
            $existing = DB::table('video_view_daily_stats')
                ->where('video_id', $video->getKey())
                ->where('view_date', $date)
                ->exists();

            if ($existing) {
                DB::table('video_view_daily_stats')
                    ->where('video_id', $video->getKey())
                    ->where('view_date', $date)
                    ->increment('views_count', 1, ['updated_at' => $timestamp]);

                return;
            }

            DB::table('video_view_daily_stats')->insert([
                'video_id' => $video->getKey(),
                'view_date' => $date,
                'views_count' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        } catch (QueryException) {
            try {
                DB::table('video_view_daily_stats')
                    ->where('video_id', $video->getKey())
                    ->where('view_date', $date)
                    ->increment('views_count', 1, ['updated_at' => now()]);
            } catch (QueryException) {
                return;
            }
        }
    }
}
