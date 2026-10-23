<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\ImportXvidVideos;
use App\Jobs\RunXvidImport;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Video;
use App\Models\XvidImportRun;
use App\Models\XvidImportSchedule;
use App\Support\AvdbStarterViews;
use App\Support\Environment;
use App\Support\PhpBinary;
use App\Support\RuntimeEnv;
use App\Support\ShellRunner;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class XvidImportController
{
    private const DEFAULT_API_BASE = ImportXvidVideos::DEFAULT_API_BASE;

    // Category set a cron schedule falls back to when none was ticked (same as the seeded default schedule).
    public const DEFAULT_SCHEDULE_CATEGORIES = [65];

    private const API_SUMMARY_CACHE_SECONDS = 600;

    public function index()
    {
        $stats = $this->cachedLocalStats();
        $apiSummary = $this->cachedApiSummary();

        $hasImportRuns = Schema::hasTable('xvid_import_runs');
        if ($hasImportRuns) {
            $this->markStaleImportRuns();
        }

        $latestRun = $hasImportRuns
            ? XvidImportRun::latest('id')->first()
            : null;

        $recentRuns = $hasImportRuns
            ? XvidImportRun::latest('id')->limit(15)->get()
            : collect();

        $latestRunPayload = $latestRun ? $this->formatRun($latestRun) : null;

        self::ensureDefaultScheduleExists();

        $importSchedules = Schema::hasTable('xvid_import_schedules')
            ? XvidImportSchedule::latest('id')->limit(20)->get()
            : collect();

        $xvidCategories = ImportXvidVideos::CATEGORY_MAP;
        $defaultApiBase = self::DEFAULT_API_BASE;
        $starterViewModes = AvdbStarterViews::modes();
        $starterViewDefaults = AvdbStarterViews::defaults();

        return view('admin.xvid-import.index', compact(
            'stats',
            'apiSummary',
            'xvidCategories',
            'latestRun',
            'recentRuns',
            'latestRunPayload',
            'importSchedules',
            'defaultApiBase',
            'starterViewModes',
            'starterViewDefaults'
        ));
    }

    /**
     * Read the provider's advertised catalog total for the specification card.
     * The importer itself remains usable when this optional metadata request is
     * unavailable; the card falls back to N/A in that case.
     *
     * @return array{available:bool,total:int,pages:int,page_size:int,categories:int}
     */
    private function cachedApiSummary(): array
    {
        $cacheKey = 'xvidapi_admin_api_summary_v4';
        $cached = Cache::get($cacheKey);

        // Only a validated provider response is cacheable. This also clears
        // unavailable values written by older versions so a transient outage
        // cannot keep the card stuck on N/A for the full cache window.
        if (is_array($cached) && ($cached['available'] ?? false) === true) {
            return $cached;
        }
        if ($cached !== null) {
            Cache::forget($cacheKey);
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0',
                    'Accept' => 'application/json',
                ])
                ->withOptions(['verify' => false])
                ->get(self::DEFAULT_API_BASE, [
                    'ac' => 'list',
                    'at' => 'json',
                    'pagesize' => 100,
                ]);

            $data = $response->json();
            $totalRaw = is_array($data) ? ($data['total'] ?? null) : null;
            $pageCountRaw = is_array($data) ? ($data['pagecount'] ?? null) : null;
            $limitRaw = is_array($data) ? ($data['limit'] ?? null) : null;
            $validInteger = static fn (mixed $value): bool => (is_int($value)
                || (is_string($value) && preg_match('/^\d+$/', $value) === 1))
                && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false;

            if (! $response->successful() || ! is_array($data)
                || ! in_array($data['code'] ?? null, [1, '1'], true)
                || ! $validInteger($totalRaw) || ! $validInteger($pageCountRaw)
                || ! $validInteger($limitRaw) || (int) $limitRaw < 1
                || ((int) $totalRaw > 0 && (int) $pageCountRaw < 1)) {
                return ['available' => false, 'total' => 0, 'pages' => 0, 'page_size' => 0, 'categories' => 0];
            }

            $total = (int) $totalRaw;
            $pages = $total === 0 ? 0 : (int) $pageCountRaw;
            $pageSize = (int) $limitRaw;
            $summary = [
                'available' => true,
                'total' => $total,
                'pages' => $pages,
                'page_size' => $pageSize,
                'categories' => is_array($data) && is_array($data['class'] ?? null)
                    ? count($data['class'])
                    : count(ImportXvidVideos::CATEGORY_MAP),
            ];

            Cache::put($cacheKey, $summary, now()->addSeconds(self::API_SUMMARY_CACHE_SECONDS));

            return $summary;
        } catch (\Throwable $e) {
            Log::notice('XVIDAPI specification metadata unavailable: '.$e->getMessage());

            return ['available' => false, 'total' => 0, 'pages' => 0, 'page_size' => 0, 'categories' => 0];
        }
    }

    public function testConnection(Request $request)
    {
        $apiBase = rtrim((string) $request->input('api_base_url', self::DEFAULT_API_BASE), '/');

        $start = microtime(true);
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                    'Accept' => 'application/json',
                ])
                ->withOptions(['verify' => false])
                ->get($apiBase, ['ac' => 'detail', 'at' => 'json', 'pagesize' => 1]);

            $elapsedMs = (int) round((microtime(true) - $start) * 1000);

            if (! $response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => __('API returned HTTP status :status', ['status' => $response->status()]),
                    'response_time_ms' => $elapsedMs,
                ]);
            }

            $data = $response->json();
            if (! is_array($data) || ! isset($data['code'])) {
                return response()->json([
                    'success' => false,
                    'message' => __('Invalid JSON response from XVIDAPI.'),
                    'response_time_ms' => $elapsedMs,
                ]);
            }

            $totalVideos = (int) ($data['total'] ?? 0);
            $classesCount = count($data['class'] ?? ImportXvidVideos::CATEGORY_MAP);

            return response()->json([
                'success' => true,
                'total' => $totalVideos,
                'formatted_total' => number_format($totalVideos),
                'response_time_ms' => $elapsedMs,
                'categories_count' => $classesCount,
                'message' => __('Connection successful! Found :total videos across :cats categories.', [
                    'total' => number_format($totalVideos),
                    'cats' => $classesCount,
                ]),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => __('Connection failed: :error', ['error' => $e->getMessage()]),
            ]);
        }
    }

    public function startImport(Request $request)
    {
        $data = $request->validate([
            'api_base_url' => 'nullable|string|max:500',
            'categories' => 'nullable|array',
            'categories.*' => 'integer',
            'pages' => 'nullable|integer|min:1|max:50000',
            'start_page' => 'nullable|integer|min:1',
            'pagesize' => 'nullable|integer|min:1|max:100',
            'delay' => 'nullable|integer|min:0|max:10000',
            'skip_thumbs' => 'nullable|boolean',
            'skip_tags' => 'nullable|boolean',
            'dry_run' => 'nullable|boolean',
            'ids' => 'nullable|string|max:20000',
            'search_wd' => 'nullable|string|max:150',
            'search_code' => 'nullable|string|max:100',
            'search_actor' => 'nullable|string|max:150',
            'search_director' => 'nullable|string|max:150',
            'search_writer' => 'nullable|string|max:150',
            'search_category' => 'nullable|string|max:150',
            'updated_hours' => 'nullable|integer|min:0|max:8760',
            'year' => ['nullable', 'string', 'max:9', 'regex:/^\d{4}(-\d{4})?$/'],
            'search_source_language' => ['nullable', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[a-z]{2,4})?$/'],
            'translate_tags' => 'nullable|boolean',
            'sort_direction' => 'nullable|in:desc,asc',
            'starter_views_mode' => 'nullable|string',
            'starter_views_min' => 'nullable|integer|min:0',
            'starter_views_max' => 'nullable|integer|min:0',
            'starter_views_spread_days' => 'nullable|integer|min:1',
            'starter_views_daily_stats' => 'nullable|boolean',
            'starter_views_existing_zero' => 'nullable|boolean',
        ]);

        $data['delay'] = (int) ($data['delay'] ?? 0);
        $data['start_page'] = (int) ($data['start_page'] ?? 1);
        $data['pages'] = (int) ($data['pages'] ?? 1);
        $data['pagesize'] = (int) ($data['pagesize'] ?? 50);
        $data['sort_direction'] = $data['sort_direction'] ?? 'desc';

        $ids = trim((string) ($data['ids'] ?? ''));
        $categories = array_values(array_filter(array_map('intval', (array) ($data['categories'] ?? []))));

        if ($ids === '' && $categories === []) {
            return response()->json([
                'success' => false,
                'message' => __('Please select at least one category or enter video IDs.'),
            ], 422);
        }

        $settings = [
            'api_base_url' => rtrim((string) (($data['api_base_url'] ?? null) ?: self::DEFAULT_API_BASE), '/'),
            'categories' => $categories,
            'pages' => (int) ($data['pages'] ?? 1),
            'start_page' => (int) ($data['start_page'] ?? 1),
            'pagesize' => (int) ($data['pagesize'] ?? 50),
            'delay' => (int) ($data['delay'] ?? 0),
            'skip_thumbs' => $request->boolean('skip_thumbs', true),
            'skip_tags' => $request->boolean('skip_tags'),
            'dry_run' => $request->boolean('dry_run'),
            'ids' => $ids,
            'search_wd' => trim((string) ($data['search_wd'] ?? '')),
            'search_code' => trim((string) ($data['search_code'] ?? '')),
            'search_actor' => trim((string) ($data['search_actor'] ?? '')),
            'search_director' => trim((string) ($data['search_director'] ?? '')),
            'search_writer' => trim((string) ($data['search_writer'] ?? '')),
            'search_category' => trim((string) ($data['search_category'] ?? '')),
            'updated_hours' => (int) ($data['updated_hours'] ?? 0),
            'year' => trim((string) ($data['year'] ?? '')),
            'search_source_language' => trim((string) ($data['search_source_language'] ?? '')),
            'translate_tags' => $request->boolean('translate_tags', true),
            'sort_direction' => (string) ($data['sort_direction'] ?? 'desc'),
            'starter_views_mode' => $data['starter_views_mode'] ?? AvdbStarterViews::MODE_DISABLED,
            'starter_views_min' => (int) ($data['starter_views_min'] ?? AvdbStarterViews::defaults()['min']),
            'starter_views_max' => (int) ($data['starter_views_max'] ?? AvdbStarterViews::defaults()['max']),
            'starter_views_spread_days' => (int) ($data['starter_views_spread_days'] ?? AvdbStarterViews::defaults()['spread_days']),
            'starter_views_daily_stats' => $request->boolean('starter_views_daily_stats', false),
            'starter_views_existing_zero' => $request->boolean('starter_views_existing_zero', false),
            'date_seed' => (string) Str::uuid(),
        ];

        $run = XvidImportRun::create([
            'status' => 'queued',
            'settings' => $settings,
            'message' => __('Queued.'),
            'log' => '[' . now()->format('H:i:s') . '] ' . __('Queued XVIDAPI import run.'),
        ]);

        $started = $this->startBackgroundImport($run);

        return response()->json([
            'success' => true,
            'run_id' => $run->id,
            'message' => $started ? __('Import started in background.') : __('Queued for processing.'),
        ]);
    }

    public function progress(XvidImportRun $run)
    {
        $this->markStaleRunIfInactive($run);
        $run->refresh();
        $this->autoStartQueuedRun($run);
        $run->refresh();

        return response()->json([
            'success' => true,
            'run' => $this->formatRun($run),
        ]);
    }

    public function stop(XvidImportRun $run)
    {
        $run->forceFill([
            'cancel_requested' => true,
            'message' => __('Cancellation requested...'),
        ])->save();
        $run->appendLog(__('User requested cancellation.'));

        return response()->json([
            'success' => true,
            'message' => __('Cancellation signal sent.'),
        ]);
    }

    public function forceStop()
    {
        XvidImportRun::whereIn('status', ['queued', 'starting', 'running'])->update([
            'status' => 'cancelled',
            'message' => __('Force stopped by admin.'),
            'finished_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => __('All active XVIDAPI import runs force stopped.'),
        ]);
    }

    public function stats()
    {
        return response()->json($this->cachedLocalStats());
    }

    public function clean()
    {
        $this->forceStop();

        $videoIds = Video::withTrashed()
            ->where(function ($q) {
                $q->whereNotNull('xvid_id')->orWhere('provider', 'xvidapi');
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $count = count($videoIds);

        if ($count === 0) {
            Cache::forget('xvidapi_admin_stats');

            return response()->json([
                'success' => true,
                'deleted' => 0,
                'message' => __('No XVIDAPI videos found to delete.'),
            ]);
        }

        DB::transaction(function () use ($videoIds): void {
            DB::table('video_translations')->whereIn('video_id', $videoIds)->delete();
            DB::table('video_tags')->whereIn('video_id', $videoIds)->delete();
            DB::table('thumbnails')->whereIn('video_id', $videoIds)->delete();
            DB::table('views')->whereIn('video_id', $videoIds)->delete();
            if (Schema::hasTable('video_view_daily_stats')) {
                DB::table('video_view_daily_stats')->whereIn('video_id', $videoIds)->delete();
            }
            DB::table('comments')->whereIn('video_id', $videoIds)->delete();
            DB::table('subtitles')->whereIn('video_id', $videoIds)->delete();
            DB::table('playlist_has_videos')->whereIn('video_id', $videoIds)->delete();
            DB::table('playback_progress_signals')->whereIn('video_id', $videoIds)->delete();
            DB::table('featured_content')->whereIn('video_id', $videoIds)->delete();
            DB::table('interactions')->where('likeable_type', Video::class)->whereIn('likeable_id', $videoIds)->delete();
            DB::table('reports')->where('reportable_type', Video::class)->whereIn('reportable_id', $videoIds)->delete();
            DB::table('videos')->whereIn('id', $videoIds)->delete();
        });

        Cache::forget('xvidapi_admin_stats');

        return response()->json([
            'success' => true,
            'deleted' => $count,
            'message' => __('Deleted :count XVIDAPI video(s) and their metadata.', ['count' => number_format($count)]),
        ]);
    }

    public function storeSchedule(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'frequency' => 'required|in:interval,hourly,daily,weekly,monthly',
            'interval_minutes' => 'nullable|integer|min:5|max:1440',
            'run_time' => 'nullable|string|regex:/^\d{1,2}:\d{2}$/',
            'weekday' => 'nullable|integer|between:0,6',
            'month_day' => 'nullable|integer|between:1,31',
            'categories' => 'nullable|array',
            'pages' => 'required|integer|min:1|max:500',
            'start_page' => 'required|integer|min:1',
            'pagesize' => 'required|integer|min:1|max:100',
            'delay' => 'required|integer|min:0|max:10000',
            'skip_thumbs' => 'nullable|boolean',
            'skip_tags' => 'nullable|boolean',
            'skip_if_active' => 'nullable|boolean',
            'updated_hours' => 'nullable|integer|min:0|max:8760',
        ]);

        // An empty category list would make every run import nothing, so fall back to the default set.
        $categories = array_values(array_filter(array_map('intval', (array) ($data['categories'] ?? []))));
        if ($categories === []) {
            $categories = self::DEFAULT_SCHEDULE_CATEGORIES;
        }

        $schedule = new XvidImportSchedule();
        $schedule->fill([
            'name' => $data['name'],
            'is_active' => true,
            'categories' => $categories,
            'pages' => (int) $data['pages'],
            'start_page' => (int) $data['start_page'],
            'pagesize' => (int) $data['pagesize'],
            'delay' => (int) $data['delay'],
            'settings' => [
                'frequency' => $data['frequency'],
                'interval_minutes' => (int) ($data['interval_minutes'] ?? 60),
                'run_time' => $data['run_time'] ?? '03:00',
                'weekday' => (int) ($data['weekday'] ?? 0),
                'month_day' => (int) ($data['month_day'] ?? 1),
                'skip_thumbs' => $request->boolean('skip_thumbs', true),
                'skip_tags' => $request->boolean('skip_tags'),
                'skip_if_active' => $request->boolean('skip_if_active', true),
                'updated_hours' => (int) ($data['updated_hours'] ?? 0),
            ],
            'next_run_at' => now()->addMinutes(5),
        ]);
        $schedule->save();

        return response()->json([
            'success' => true,
            'message' => __('Schedule created successfully.'),
        ]);
    }

    public function toggleSchedule(XvidImportSchedule $schedule)
    {
        $schedule->update(['is_active' => ! $schedule->is_active]);

        return back()->with('success', __('Schedule status updated.'));
    }

    public function runScheduleNow(XvidImportSchedule $schedule)
    {
        Artisan::call('xvid:schedules:run', ['--id' => $schedule->id]);

        return back()->with('success', __('Schedule triggered successfully.'));
    }

    public function destroySchedule(XvidImportSchedule $schedule)
    {
        $schedule->delete();

        return back()->with('success', __('Schedule deleted.'));
    }

    public function destroyRun(XvidImportRun $run)
    {
        $run->delete();

        return back()->with('success', __('Run history deleted.'));
    }

    public function cleanFinishedRuns()
    {
        XvidImportRun::whereIn('status', ['finished', 'cancelled', 'failed'])->delete();

        return back()->with('success', __('Finished run history cleared.'));
    }

    private function cachedLocalStats(): array
    {
        return Cache::remember('xvidapi_admin_stats', 60, function () {
            $totalVideos = Video::withTrashed()->whereNotNull('xvid_id')->count();
            $importedToday = Video::whereNotNull('xvid_id')
                ->where('created_at', '>=', now()->startOfDay())
                ->count();
            $xvidCategoriesCount = Video::whereNotNull('xvid_id')
                ->distinct('category_id')
                ->count('category_id');
            $activeRuns = XvidImportRun::whereIn('status', ['queued', 'starting', 'running'])->count();

            return [
                'total_videos' => $totalVideos,
                'imported_today' => $importedToday,
                'active_categories' => $xvidCategoriesCount,
                'active_runs' => $activeRuns,
            ];
        });
    }

    public static function ensureDefaultScheduleExists(): ?XvidImportSchedule
    {
        if (! Schema::hasTable('xvid_import_schedules') || XvidImportSchedule::query()->exists()) {
            return null;
        }

        try {
            return XvidImportSchedule::create([
                'name' => 'Hourly XVIDAPI Video Sync',
                'is_active' => false,
                'categories' => [65],
                'pages' => 1,
                'start_page' => 1,
                'pagesize' => 50,
                'delay' => 0,
                'settings' => [
                    'frequency' => XvidImportSchedule::FREQUENCY_HOURLY,
                    'interval_minutes' => 60,
                    'run_time' => '00:30',
                    'weekday' => 1,
                    'month_day' => 1,
                    'skip_if_active' => true,
                    'categories' => [65],
                    'pages' => 1,
                    'start_page' => 1,
                    'pagesize' => 50,
                    'delay' => 0,
                    'skip_thumbs' => true,
                    'skip_tags' => false,
                    'dry_run' => false,
                    'api_base_url' => config('site.xvid_api_base_url', self::DEFAULT_API_BASE),
                    'sort_direction' => 'desc',
                    'starter_views_mode' => 'disabled',
                ],
                'next_run_at' => now()->subMinute(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create default XVIDAPI import schedule: ' . $e->getMessage());
            return null;
        }
    }

    public function startBackgroundImport(XvidImportRun $run): bool
    {
        $mode = strtolower(trim((string) RuntimeEnv::get('XVID_IMPORT_MODE', 'auto')));
        if ($mode === 'queue') {
            try {
                RunXvidImport::dispatch($run->id);
                $run->appendLog('Dispatched to background queue.');

                $php = $this->phpBinary();
                if ($php && Environment::canRunProcesses() && PhpBinary::usable($php)) {
                    ShellRunner::runBackground(
                        [$php, base_path('artisan'), 'queue:work', '--once', '--timeout=3600'],
                        storage_path('logs/queue-worker.log'),
                        base_path()
                    );
                }

                return true;
            } catch (\Throwable $e) {
                Log::warning('Queue dispatch failed, attempting subprocess: ' . $e->getMessage());
            }
        }

        if ($this->canSpawnImportProcess()) {
            return $this->spawnProcessImport($run);
        }

        return $this->runInlineImport($run);
    }

    private function canSpawnImportProcess(): bool
    {
        $php = $this->phpBinary();
        return Environment::canRunProcesses() && PhpBinary::usable($php);
    }

    private function phpBinary(): ?string
    {
        return PhpBinary::resolve(['XVID_IMPORT_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']);
    }

    private function spawnProcessImport(XvidImportRun $run): bool
    {
        $php = $this->phpBinary();
        if (! PhpBinary::usable($php)) {
            return $this->runInlineImport($run);
        }

        $artisan = base_path('artisan');
        $log = storage_path("logs/xvid-import-{$run->id}.log");
        $command = [$php, $artisan, 'xvid:import', '--run-id=' . $run->id];

        $run->forceFill([
            'status' => 'starting',
            'message' => __('Starting background import process.'),
            'started_at' => now(),
            'updated_at' => now(),
        ])->save();

        $ok = ShellRunner::runBackground($command, $log, base_path());
        if ($ok) {
            $run->appendLog('Spawned detached background process.');
            return true;
        }

        return $this->runInlineImport($run);
    }

    private function autoStartQueuedRun(XvidImportRun $run): void
    {
        if ($run->status !== 'queued' || $run->started_at || $run->cancel_requested) {
            return;
        }

        $claimed = XvidImportRun::query()
            ->whereKey($run->id)
            ->where('status', 'queued')
            ->whereNull('started_at')
            ->where('cancel_requested', false)
            ->update([
                'status' => 'starting',
                'message' => __('Starting queued import automatically.'),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $run->refresh();
        $run->appendLog(__('Auto-started queued import in background.'));

        $this->startBackgroundImport($run);
    }

    private function runInlineImport(XvidImportRun $run): bool
    {
        try {
            @ignore_user_abort(true);
            @set_time_limit(0);
            Artisan::call('xvid:import', ['--run-id' => $run->id]);
            return true;
        } catch (\Throwable $e) {
            Log::error('Inline XVIDAPI import failed: ' . $e->getMessage());
            return false;
        }
    }

    private function markStaleImportRuns(): void
    {
        XvidImportRun::whereIn('status', ['queued', 'starting', 'running'])
            ->where('updated_at', '<', now()->subMinutes(30))
            ->update([
                'status' => 'failed',
                'message' => __('Marked failed due to inactivity.'),
                'finished_at' => now(),
            ]);
    }

    private function markStaleRunIfInactive(XvidImportRun $run): void
    {
        if (in_array($run->status, ['queued', 'starting', 'running'], true)) {
            if ($run->updated_at && $run->updated_at->lt(now()->subMinutes(30))) {
                $run->forceFill([
                    'status' => 'failed',
                    'message' => __('Marked failed due to inactivity.'),
                    'finished_at' => now(),
                ])->save();
            }
        }
    }

    private function formatRun(?XvidImportRun $run): ?array
    {
        if (! $run) {
            return null;
        }

        return [
            'id' => $run->id,
            'status' => $run->status,
            'imported' => $run->imported,
            'skipped' => $run->skipped,
            'updated' => $run->updated,
            'failed' => $run->failed,
            'tags_created' => $run->tags_created,
            'thumbs_downloaded' => $run->thumbs_downloaded,
            'total_pages' => $run->total_pages,
            'completed_pages' => $run->completed_pages,
            'progress' => $run->progress,
            'current_category' => $run->current_category,
            'current_page' => $run->current_page,
            'message' => $run->message,
            'log' => $run->log,
            'cancel_requested' => $run->cancel_requested,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }
}
