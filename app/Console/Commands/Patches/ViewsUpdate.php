<?php

namespace App\Console\Commands\Patches;

use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ViewsUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'views:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update views count';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle() : int
    {
        $videos = Video::query()->withCount('viewsHistory')->get();
        $dailyStatsReady = Schema::hasTable('video_view_daily_stats');

        foreach ($videos as $video) {

            $historyViews = (int) $video->views_history_count;
            $dailyViews = $dailyStatsReady
                ? (int) DB::table('video_view_daily_stats')->where('video_id', $video->id)->sum('views_count')
                : 0;
            $views = max((int) $video->views, $historyViews, $dailyViews);

            $video->update([
                'views' => $views
            ]);
        }

        return Command::SUCCESS;
    }
}
