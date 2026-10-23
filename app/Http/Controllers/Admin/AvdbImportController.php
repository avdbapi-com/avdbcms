<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\RunAvdbImport;
use App\Jobs\SyncAvdbDurationBatch;
use App\Models\AvdbImportRun;
use App\Models\AvdbImportSchedule;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Video;
use App\Support\AdminPreferences;
use App\Support\AvdbDurationBackfill;
use App\Support\AvdbStarterViews;
use App\Support\Environment;
use App\Support\LaravelSchedulerCron;
use App\Support\PhpBinary;
use App\Support\RuntimeEnv;
use App\Support\SeoSitemapCache;
use App\Support\ShellRunner;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AvdbImportController
{
    private const DEFAULT_API_BASE = 'https://avdbapi.com/api.php/provide/vod';

    private const ACTIVE_RUN_STATUSES = ['queued', 'starting', 'running'];

    private const STALE_ACTIVE_RUN_MINUTES = 30;

    private const LOCAL_STATS_CACHE_SECONDS = 120;

    private const TRANSLATED_COUNT_CACHE_SECONDS = 300;

    private const API_CONNECTION_CACHE_SECONDS = 600;

    private const API_SUMMARY_CACHE_SECONDS = 600;

    /** The provider question behind the specification card (also asked by the provider status probe). */
    public const API_SUMMARY_QUERY = ['ac' => 'list', 'at' => 'json', 'pagesize' => 100];

    private const LOCAL_STATS_INDEX = 'videos_avdb_category_metric_idx';

    private string $apiBase = self::DEFAULT_API_BASE;

    public function index()
    {
        $stats = $this->cachedLocalStats();
        // The new admin shows the provider status probe's last answer and asks the provider after the page appears
        // (owner decision OD6); the old admin (one release) still asks before it answers.
        $newAdmin = AdminPreferences::usesNewAdmin();
        $apiProbe = $newAdmin ? $this->probedApiSummary() : null;
        $apiSummary = $newAdmin
            ? ($apiProbe['summary'] ?? ['available' => false, 'total' => 0, 'pages' => 0, 'page_size' => 0, 'categories' => []])
            : $this->cachedApiSummary();

        $hasImportRuns = Schema::hasTable('avdb_import_runs');
        if ($hasImportRuns) {
            $this->markStaleImportRuns();
        }

        $latestRun = $hasImportRuns
            ? AvdbImportRun::latest('id')->first()
            : null;

        if ($latestRun) {
            $this->markStaleRunIfInactive($latestRun);
            $latestRun->refresh();
            $this->autoStartQueuedRun($latestRun);
            $latestRun->refresh();
        }

        $recentRuns = $hasImportRuns
            ? AvdbImportRun::latest('id')->limit(10)->get()
            : collect();
        $latestRunPayload = $this->formatRun($latestRun);
        $hasSchedulesTable = Schema::hasTable('avdb_import_schedules');
        if ($hasSchedulesTable) {
            self::ensureDefaultScheduleExists();
        }
        $importSchedules = $hasSchedulesTable
            ? AvdbImportSchedule::latest('id')->limit(20)->get()
            : collect();
        $avdbCronHealth = $this->avdbCronHealth($importSchedules);

        $avdbCategories = [
            1 => ['name' => 'Censored', 'icon' => 'fa-lock'],
            2 => ['name' => 'Uncensored', 'icon' => 'fa-lock-open'],
            3 => ['name' => 'Uncensored Leaked', 'icon' => 'fa-droplet'],
            4 => ['name' => 'Amateur', 'icon' => 'fa-user'],
            5 => ['name' => 'Chinese AV', 'icon' => 'fa-flag'],
            6 => ['name' => 'Hentai', 'icon' => 'fa-wand-magic-sparkles'],
            7 => ['name' => 'English Subtitle', 'icon' => 'fa-closed-captioning'],
        ];

        $languages = [
            'en' => 'English',
            'ja' => 'Japanese',
            'zh' => 'Chinese',
            'ko' => 'Korean',
            'vi' => 'Vietnamese',
            'th' => 'Thai',
            'ms' => 'Malay',
            'id' => 'Indonesian',
            'fr' => 'French',
            'de' => 'German',
            'es' => 'Spanish',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            'ar' => 'Arabic',
            'hi' => 'Hindi',
            'tl' => 'Filipino',
            'tr' => 'Turkish',
        ];

        $defaultApiBase = self::DEFAULT_API_BASE;
        $starterViewModes = AvdbStarterViews::modes();
        $starterViewDefaults = AvdbStarterViews::defaults();

        $selectedCategories = old('categories', [1, 2, 3, 4, 5, 6, 7]);
        if (! is_array($selectedCategories)) {
            $selectedCategories = [1, 2, 3, 4, 5, 6, 7];
        }

        $allCategoriesChecked = count($selectedCategories) === 7;

        return view('admin.avdb-import.index', compact(
            'stats',
            'apiSummary',
            'newAdmin',
            'apiProbe',
            'recentRuns',
            'latestRun',
            'latestRunPayload',
            'importSchedules',
            'avdbCronHealth',
            'avdbCategories',
            'languages',
            'defaultApiBase',
            'starterViewModes',
            'starterViewDefaults',
            'selectedCategories',
            'allCategoriesChecked'
        ));
    }

    /**
     * Read the provider's advertised catalog total for the specification card.
     * This is optional metadata and is cached separately from local import stats.
     *
     * @return array{available:bool,total:int,pages:int,page_size:int,categories:array<int,int>}
     */
    private function cachedApiSummary(): array
    {
        $cacheKey = 'avdbapi_admin_api_summary_v4';
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
                ->get(self::DEFAULT_API_BASE, self::API_SUMMARY_QUERY);

            $summary = self::apiSummaryFrom($response->successful(), $response->json());

            if (! $summary['available']) {
                return $summary;
            }

            Cache::put($cacheKey, $summary, now()->addSeconds(self::API_SUMMARY_CACHE_SECONDS));

            return $summary;
        } catch (\Throwable $e) {
            Log::notice('AVDBAPI specification metadata unavailable: '.$e->getMessage());

            return ['available' => false, 'total' => 0, 'pages' => 0, 'page_size' => 0, 'categories' => []];
        }
    }

    /**
     * The catalog numbers in the provider's answer to API_SUMMARY_QUERY, or "unavailable" when the answer is not a
     * valid one. Shared with the provider status probe, whose answer the new admin shows on this page.
     *
     * @return array{available:bool,total:int,pages:int,page_size:int,categories:array<int,int>}
     */
    public static function apiSummaryFrom(bool $successful, mixed $data): array
    {
        $totalRaw = is_array($data) ? ($data['total'] ?? null) : null;
        $pageCountRaw = is_array($data) ? ($data['pagecount'] ?? null) : null;
        $limitRaw = is_array($data) ? ($data['limit'] ?? null) : null;
        $validInteger = static fn (mixed $value): bool => (is_int($value)
            || (is_string($value) && preg_match('/^\d+$/', $value) === 1))
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false;

        if (! $successful || ! is_array($data)
            || ! in_array($data['code'] ?? null, [1, '1'], true)
            || ! $validInteger($totalRaw) || ! $validInteger($pageCountRaw)
            || ! $validInteger($limitRaw) || (int) $limitRaw < 1
            || ((int) $totalRaw > 0 && (int) $pageCountRaw < 1)) {
            return ['available' => false, 'total' => 0, 'pages' => 0, 'page_size' => 0, 'categories' => []];
        }

        $total = (int) $totalRaw;

        return [
            'available' => true,
            'total' => $total,
            'pages' => $total === 0 ? 0 : (int) $pageCountRaw,
            'page_size' => (int) $limitRaw,
            'categories' => [],
        ];
    }

    /**
     * The new admin's specification card: the provider status probe's last answer (kept up to 5 minutes, a failure
     * 2 minutes), never a request to the provider before the page answers. Null when there is no usable answer yet
     * (the page then asks the probe after it appears); 'stale' = an answer without the catalog numbers (cached
     * before the probe recorded them), which the page asks for again.
     *
     * @return array{summary: array, checked_at: ?string, stale: bool}|null
     */
    private function probedApiSummary(): ?array
    {
        $answer = StatusProbeController::peek('provider');

        if ($answer === null) {
            return null;
        }

        $summary = $answer['data']['providers']['AVDBAPI']['summary'] ?? null;

        return [
            'summary' => is_array($summary) ? $summary + ['available' => false, 'total' => 0, 'pages' => 0, 'page_size' => 0] : ['available' => false, 'total' => 0, 'pages' => 0, 'page_size' => 0],
            'checked_at' => $answer['checked_at'] ?? null,
            'stale' => ! is_array($summary),
        ];
    }

    public static function ensureDefaultScheduleExists(): ?AvdbImportSchedule
    {
        if (! Schema::hasTable('avdb_import_schedules') || AvdbImportSchedule::query()->exists()) {
            return null;
        }

        try {
            return AvdbImportSchedule::create([
                'name' => 'Hourly Automatic Video Sync',
                'enabled' => false,
                'frequency' => AvdbImportSchedule::FREQUENCY_HOURLY,
                'interval_minutes' => 60,
                'run_time' => '00:15',
                'weekday' => 'monday',
                'month_day' => 1,
                'skip_if_active' => true,
                'settings' => [
                    'categories' => [1, 2, 3, 4, 5, 6, 7],
                    'pages' => 1,
                    'start_page' => 1,
                    'pagesize' => 50,
                    'delay' => 0,
                    'language_mode' => 'auto',
                    'language' => 'null',
                    'thumbnail_mode' => 'remote',
                    'api_base_url' => config('site.avdb_api_base_url', self::DEFAULT_API_BASE),
                    'api_lang' => 'en',
                    'sort_direction' => 'desc',
                    'translation_chunk' => 50,
                    'translation_concurrency' => 4,
                    'use_movie_code_title' => false,
                    'creator_assignment_enabled' => true,
                    'skip_tags' => false,
                    'dry_run' => false,
                    'translated_only' => false,
                    'full_langs' => false,
                    'verify_full_langs' => false,
                    'starter_views_mode' => 'disabled',
                ],
                'next_run_at' => now()->subMinute(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to seed default AVDB import schedule: '.$e->getMessage());

            return null;
        }
    }

    public function testConnection(Request $request)
    {
        $request->validate([
            'api_base_url' => 'nullable|string|max:500',
        ]);

        $apiBase = $this->normalizeApiUrl((string) ($request->input('api_base_url') ?? self::DEFAULT_API_BASE));
        $cacheKey = 'avdb_api_test_connection:'.sha1($apiBase);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return response()->json($cached);
        }

        try {
            $response = Http::timeout(10)
                ->withOptions(['verify' => false])
                ->get($apiBase, ['ac' => 'detail', 'pagesize' => 1]);

            if (! $response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => __('API returned HTTP :status', ['status' => $response->status()]),
                ]);
            }

            $data = $response->json();
            $categoryCounts = [];

            foreach (range(1, 7) as $catId) {
                $catResponse = Http::timeout(10)
                    ->withOptions(['verify' => false])
                    ->get($apiBase, ['ac' => 'detail', 't' => $catId, 'pagesize' => 1]);

                if ($catResponse->successful()) {
                    $categoryCounts[$catId] = $catResponse->json('total') ?? 0;
                }
            }

            $payload = [
                'success' => true,
                'total_videos' => $data['total'] ?? 0,
                'category_counts' => $categoryCounts,
                'message' => __('Connected.'),
            ];
            Cache::put($cacheKey, $payload, now()->addSeconds(self::API_CONNECTION_CACHE_SECONDS));

            return response()->json($payload);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => __('Connection failed: :message', ['message' => Str::limit($e->getMessage(), 120)]),
            ]);
        }
    }

    public function startImport(Request $request)
    {
        $data = $request->validate([
            'api_base_url' => 'nullable|string|max:500',
            'categories' => 'nullable|array',
            'categories.*' => 'integer|between:1,7',
            'pages' => 'required|integer|min:1|max:50000',
            'start_page' => 'required|integer|min:1',
            'pagesize' => 'required|integer|min:1|max:1000',
            'delay' => 'required|integer|min:0|max:10000',
            'language_mode' => 'required|in:auto,global,fixed',
            'global_language' => 'nullable|string|max:5|regex:/^[a-zA-Z]{2,5}$/',
            'thumbnail_mode' => 'required|in:remote,download,skip',
            'api_lang' => 'nullable|string|max:5|regex:/^[a-zA-Z]{2,5}$/',
            'ids' => 'nullable|string|max:20000',
            'search_wd' => 'nullable|string|max:150',
            'search_code' => 'nullable|string|max:100',
            'search_actor' => 'nullable|string|max:150',
            'search_director' => 'nullable|string|max:150',
            'search_writer' => 'nullable|string|max:150',
            'search_category' => 'nullable|string|max:150',
            'updated_hours' => 'nullable|integer|min:0|max:8760',
            'year' => ['nullable', 'string', 'max:9', 'regex:/^\d{4}(-\d{4})?$/'],
            'sort_direction' => 'required|in:desc,asc',
            'locales' => 'nullable|string|max:120|regex:/^[a-zA-Z,\s]*$/',
            'translation_chunk' => 'required|integer|min:10|max:500',
            'translation_concurrency' => 'required|integer|min:1|max:16',
            'use_movie_code_title' => 'nullable|boolean',
            'creator_assignment_enabled' => 'nullable|boolean',
            'skip_tags' => 'nullable|boolean',
            'dry_run' => 'nullable|boolean',
            'translated_only' => 'nullable|boolean',
            'full_langs' => 'nullable|boolean',
            'verify_full_langs' => 'nullable|boolean',
            'starter_views_mode' => ['nullable', Rule::in(array_keys(AvdbStarterViews::modes()))],
            'starter_views_min' => 'nullable|integer|min:0|max:1000000000',
            'starter_views_max' => 'nullable|integer|min:0|max:1000000000',
            'starter_views_spread_days' => 'nullable|integer|min:1|max:3650',
            'starter_views_daily_stats' => 'nullable|boolean',
            'starter_views_only_zero' => 'nullable|boolean',
            'starter_views_existing_zero' => 'nullable|boolean',
        ]);

        $apiLang = strtolower($data['api_lang'] ?? 'en');
        $apiBaseUrl = $this->normalizeApiUrl((string) ($data['api_base_url'] ?? self::DEFAULT_API_BASE));
        $language = strtolower($data['global_language'] ?? 'en');
        $rawIds = trim((string) ($data['ids'] ?? ''));
        $ids = $this->normalizeIdsInput($rawIds);
        $categories = array_values(array_unique(array_map('intval', $data['categories'] ?? [])));
        if ($rawIds !== '' && $ids === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'ids' => __('Enter at least one AVDB numeric ID or an AVDB API detail URL with an ids parameter.'),
            ]);
        }

        if ($ids === '' && $categories === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'categories' => __('Select at least one category or enter specific AVDB IDs.'),
            ]);
        }

        $settings = [
            'categories' => $categories,
            'pages' => (int) $data['pages'],
            'start_page' => (int) $data['start_page'],
            'pagesize' => (int) $data['pagesize'],
            'delay' => (int) $data['delay'],
            'language_mode' => $data['language_mode'],
            'language' => $data['language_mode'] === 'fixed' ? $language : 'null',
            'thumbnail_mode' => $data['thumbnail_mode'],
            'api_base_url' => $apiBaseUrl,
            'api_lang' => $apiLang,
            'ids' => $ids,
            'search_wd' => $this->cleanApiFilter($data['search_wd'] ?? ''),
            'search_code' => $this->cleanApiFilter($data['search_code'] ?? ''),
            'search_actor' => $this->cleanApiFilter($data['search_actor'] ?? ''),
            'search_director' => $this->cleanApiFilter($data['search_director'] ?? ''),
            'search_writer' => $this->cleanApiFilter($data['search_writer'] ?? ''),
            'search_category' => $this->cleanApiFilter($data['search_category'] ?? ''),
            'updated_hours' => (int) ($data['updated_hours'] ?? 0),
            'year' => trim((string) ($data['year'] ?? '')),
            'sort_direction' => $data['sort_direction'],
            'locales' => trim((string) ($data['locales'] ?? '')),
            'translation_chunk' => (int) $data['translation_chunk'],
            'translation_concurrency' => (int) $data['translation_concurrency'],
            'use_movie_code_title' => $request->boolean('use_movie_code_title'),
            'creator_assignment_enabled' => $request->boolean('creator_assignment_enabled', true),
            'skip_tags' => $request->boolean('skip_tags'),
            'dry_run' => $request->boolean('dry_run'),
            'translated_only' => $request->boolean('translated_only', $apiLang !== 'en'),
            'full_langs' => $request->boolean('full_langs'),
            'verify_full_langs' => $request->boolean('full_langs') && $request->boolean('verify_full_langs', true),
            'date_seed' => (string) Str::uuid().'-'.Str::random(16),
            'starter_views_mode' => $data['starter_views_mode'] ?? AvdbStarterViews::MODE_DISABLED,
            'starter_views_min' => (int) ($data['starter_views_min'] ?? AvdbStarterViews::defaults()['min']),
            'starter_views_max' => (int) ($data['starter_views_max'] ?? AvdbStarterViews::defaults()['max']),
            'starter_views_spread_days' => (int) ($data['starter_views_spread_days'] ?? AvdbStarterViews::defaults()['spread_days']),
            'starter_views_daily_stats' => $request->boolean('starter_views_daily_stats', AvdbStarterViews::defaults()['daily_stats']),
            'starter_views_only_zero' => $request->boolean('starter_views_only_zero', AvdbStarterViews::defaults()['only_zero']),
            'starter_views_existing_zero' => $request->boolean('starter_views_existing_zero', AvdbStarterViews::defaults()['apply_existing_zero']),
            'starter_views_seed' => (string) Str::uuid().'-'.Str::random(16),
        ];

        $run = AvdbImportRun::create([
            'status' => 'queued',
            'settings' => $settings,
            'message' => __('Queued.'),
            'log' => '['.now()->format('H:i:s').'] '.__('Queued import run.'),
        ]);

        $started = $this->startBackgroundImport($run);

        if (! $started) {
            $run->forceFill([
                'status' => 'failed',
                'message' => __('Could not start the import. Check PHP process support, AVDB_IMPORT_PHP_BINARY, or set AVDB_IMPORT_MODE=inline to run inside the request.'),
                'finished_at' => now(),
            ])->save();
        }

        return response()->json([
            'success' => $started,
            'run_id' => $run->id,
            'message' => $started
                ? __('Import run #:id started in background.', ['id' => $run->id])
                : $run->message,
            'run' => $this->formatRun($run->fresh()),
        ]);
    }

    public function progress(AvdbImportRun $run)
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

    public function stop(AvdbImportRun $run)
    {
        if (! $this->runIsActive($run)) {
            return response()->json([
                'success' => true,
                'run' => $this->formatRun($run),
                'message' => __('Run already finished.'),
            ]);
        }

        $deletedQueueRows = $this->deleteImportRunQueueRows((int) $run->id, false);
        $run->forceFill([
            'status' => 'cancelled',
            'cancel_requested' => true,
            'message' => __('Import cancelled by admin.'),
            'progress' => 100,
            'finished_at' => now(),
        ])->save();
        $run->appendLog(__('Stop requested by admin. Active workers will exit at the next cancellation checkpoint.'));

        return response()->json([
            'success' => true,
            'run' => $this->formatRun($run->fresh()),
            'message' => $deletedQueueRows > 0
                ? __('Import cancelled. Removed :count queued job(s).', ['count' => $deletedQueueRows])
            : __('Import cancelled.'),
        ]);
    }

    public function forceStop()
    {
        $cancelledRuns = 0;
        $deletedQueueRows = 0;

        AvdbImportRun::query()
            ->whereIn('status', self::ACTIVE_RUN_STATUSES)
            ->orderBy('id')
            ->get()
            ->each(function (AvdbImportRun $run) use (&$cancelledRuns, &$deletedQueueRows): void {
                $deletedQueueRows += $this->deleteImportRunQueueRows((int) $run->id, false);
                $run->forceFill([
                    'status' => 'cancelled',
                    'cancel_requested' => true,
                    'message' => __('Force stopped by admin.'),
                    'progress' => 100,
                    'finished_at' => now(),
                ])->save();
                $run->appendLog(__('Force stopped by admin.'));
                $cancelledRuns++;
            });

        $latestRun = AvdbImportRun::query()->orderByDesc('id')->first();

        return response()->json([
            'success' => true,
            'run' => $latestRun ? $this->formatRun($latestRun) : null,
            'cancelled_runs' => $cancelledRuns,
            'deleted_queue_rows' => $deletedQueueRows,
            'message' => $cancelledRuns > 0
                ? __('Force stopped :runs active AVDB import run(s) and removed :queues queued AVDB job(s).', [
                    'runs' => $cancelledRuns,
                    'queues' => $deletedQueueRows,
                ])
                : __('No active AVDB import runs were found. Removed :queues queued AVDB job(s).', [
                    'queues' => $deletedQueueRows,
                ]),
        ]);
    }

    public function destroyRun(AvdbImportRun $run)
    {
        if ($this->runIsActive($run)) {
            return back()->withErrors(__('Import run #:id is still active. Stop it before deleting history.', ['id' => $run->id]));
        }

        $runId = (int) $run->id;
        $deletedFiles = $this->deleteRunArtifacts($runId);
        $deletedQueueRows = $this->deleteImportRunQueueRows($runId);
        $run->delete();

        return back()->withSuccess(__('Deleted import run #:id, :files log/export file(s), and :queues queue row(s).', [
            'id' => $runId,
            'files' => $deletedFiles,
            'queues' => $deletedQueueRows,
        ]));
    }

    public function cleanFinishedRuns()
    {
        $deletedRuns = 0;
        $deletedFiles = 0;
        $deletedQueueRows = 0;

        AvdbImportRun::query()
            ->whereNotIn('status', self::ACTIVE_RUN_STATUSES)
            ->orderBy('id')
            ->chunkById(100, function ($runs) use (&$deletedRuns, &$deletedFiles, &$deletedQueueRows): void {
                foreach ($runs as $run) {
                    $runId = (int) $run->id;
                    $deletedFiles += $this->deleteRunArtifacts($runId);
                    $deletedQueueRows += $this->deleteImportRunQueueRows($runId);
                    $run->delete();
                    $deletedRuns++;
                }
            });

        return back()->withSuccess(__('Cleaned :runs finished import run(s), :files log/export file(s), and :queues queue row(s). Active runs were kept.', [
            'runs' => $deletedRuns,
            'files' => $deletedFiles,
            'queues' => $deletedQueueRows,
        ]));
    }

    public function cleanFinishedRunsHelp()
    {
        return redirect()
            ->route('admin.avdb-import.index')
            ->withErrors(__('Clean Finished is an action, not a page. Use the Clean Finished button from AVDB Import; if you already clicked it and saw a 419 error, refresh the page and try again.'));
    }

    public function storeSchedule(Request $request)
    {
        if (! Schema::hasTable('avdb_import_schedules')) {
            return response()->json([
                'success' => false,
                'message' => __('Schedule table is not installed. Run migrations first.'),
            ], 500);
        }

        $settings = $this->validatedImportSettings($request);
        $data = $request->validate([
            'schedule_name' => ['nullable', 'string', 'max:120'],
            'schedule_frequency' => ['required', Rule::in([
                AvdbImportSchedule::FREQUENCY_INTERVAL,
                AvdbImportSchedule::FREQUENCY_HOURLY,
                AvdbImportSchedule::FREQUENCY_DAILY,
                AvdbImportSchedule::FREQUENCY_WEEKLY,
                AvdbImportSchedule::FREQUENCY_MONTHLY,
            ])],
            'schedule_time' => ['nullable', 'date_format:H:i'],
            'schedule_weekday' => ['nullable', 'integer', 'between:0,6'],
            'schedule_month_day' => ['nullable', 'integer', 'between:1,28'],
            'schedule_interval_minutes' => ['nullable', 'integer', 'min:15', 'max:10080'],
            'schedule_enabled' => ['nullable', 'boolean'],
            'schedule_skip_if_active' => ['nullable', 'boolean'],
        ]);

        $schedule = new AvdbImportSchedule([
            'name' => trim((string) ($data['schedule_name'] ?? '')) ?: 'AVDB import '.now()->format('Y-m-d H:i'),
            'enabled' => $request->boolean('schedule_enabled', true),
            'frequency' => $data['schedule_frequency'],
            'run_time' => $data['schedule_time'] ?? '02:00',
            'weekday' => (int) ($data['schedule_weekday'] ?? 1),
            'month_day' => (int) ($data['schedule_month_day'] ?? 1),
            'interval_minutes' => (int) ($data['schedule_interval_minutes'] ?? 1440),
            'skip_if_active' => $request->boolean('schedule_skip_if_active', true),
            'settings' => $settings,
            'last_status' => 'created',
            'last_message' => __('Schedule created from AVDB Import form.'),
        ]);
        $schedule->next_run_at = $schedule->enabled ? $schedule->calculateNextRunAt() : null;
        $schedule->save();

        $cronInstall = $schedule->enabled && $this->schedulerCronAutoInstallEnabled()
            ? LaravelSchedulerCron::ensureInstalled($this->phpBinary())
            : null;
        $message = __('Saved cron schedule #:id. Next run: :next.', [
            'id' => $schedule->id,
            'next' => $schedule->next_run_at ? $schedule->next_run_at->diffForHumans() : __('disabled'),
        ]);

        if (is_array($cronInstall) && ! empty($cronInstall['message'])) {
            $message .= ' '.$cronInstall['message'];
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'schedule_id' => $schedule->id,
            'scheduler_cron' => $cronInstall,
        ]);
    }

    public function toggleSchedule(AvdbImportSchedule $schedule)
    {
        $schedule->enabled = ! $schedule->enabled;
        $schedule->next_run_at = $schedule->enabled ? $schedule->calculateNextRunAt() : null;
        $schedule->last_status = $schedule->enabled ? 'enabled' : 'paused';
        $schedule->last_message = $schedule->enabled ? __('Schedule enabled.') : __('Schedule paused.');
        $schedule->save();

        return back()->withSuccess($schedule->enabled ? __('AVDB cron schedule enabled.') : __('AVDB cron schedule paused.'));
    }

    public function runScheduleNow(AvdbImportSchedule $schedule)
    {
        if (! $schedule->enabled) {
            return back()->withErrors(__('Enable this AVDB cron schedule before running it.'));
        }

        Artisan::call('avdb:schedules:run', ['--id' => $schedule->id]);
        $output = trim(Artisan::output());

        return back()->withSuccess($output !== '' ? $output : __('AVDB cron schedule checked.'));
    }

    public function runDueSchedules()
    {
        if (! Schema::hasTable('avdb_import_schedules')) {
            return back()->withErrors(__('AVDB cron schedule table is not installed.'));
        }

        Artisan::call('avdb:schedules:run');
        $output = trim(Artisan::output());

        return back()->withSuccess($output !== '' ? $output : __('Due AVDB cron schedules checked.'));
    }

    public function destroySchedule(AvdbImportSchedule $schedule)
    {
        $id = $schedule->id;
        $schedule->delete();

        return back()->withSuccess(__('Deleted AVDB cron schedule #:id.', ['id' => $id]));
    }

    public function exportIssues(AvdbImportRun $run, string $type)
    {
        $type = Str::of($type)->lower()->replace('_', '-')->toString();
        abort_unless(in_array($type, ['failed', 'no-full-lang', 'all'], true), 404);

        $path = $this->issueExportPath($run, $type);
        $filename = 'avdb-import-'.$run->id.'-'.$type.'.tsv';

        if (is_file($path)) {
            return response()->download($path, $filename, [
                'Content-Type' => 'text/tab-separated-values; charset=UTF-8',
            ]);
        }

        $content = "time\ttype\tavdb_id\ttitle\treason\tlink\tcategory\tpage\n";
        $content .= now()->toDateTimeString()."\tinfo\t\t\t".__('No :type rows were recorded for import run #:id.', ['type' => $type, 'id' => $run->id])."\t\t\t\n";

        return response($content, 200, [
            'Content-Type' => 'text/tab-separated-values; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function stats()
    {
        $stats = $this->cachedLocalStats();

        return response()->json([
            'total_videos' => $stats['total_imported'],
            'total_tags' => $stats['total_tags'],
            'translated_videos' => $this->cachedTranslatedVideoCount(),
            'by_category' => $stats['categories']
                ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'count' => $c->videos_count]),
        ]);
    }

    private function cachedLocalStats(): array
    {
        return Cache::remember(
            $this->cacheKey('local-stats'),
            now()->addSeconds(self::LOCAL_STATS_CACHE_SECONDS),
            function (): array {
                $categories = Category::query()
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get();

                $countsQuery = Video::query();
                if (DB::connection()->getDriverName() === 'mysql' && Schema::hasTable('videos') && Schema::hasIndex('videos', self::LOCAL_STATS_INDEX)) {
                    $countsQuery->forceIndex(self::LOCAL_STATS_INDEX);
                }
                $counts = $countsQuery
                    ->whereNotNull('avdb_id')
                    ->selectRaw('category_id, COUNT(*) as aggregate')
                    ->groupBy('category_id')
                    ->pluck('aggregate', 'category_id');

                $categories->each(function (Category $category) use ($counts): void {
                    $category->setAttribute('videos_count', (int) ($counts[$category->id] ?? 0));
                });

                return [
                    'total_imported' => (int) $counts->sum(),
                    'total_tags' => (int) Tag::count(),
                    'categories' => $categories,
                ];
            }
        );
    }

    private function cachedTranslatedVideoCount(): int
    {
        return (int) Cache::remember(
            $this->cacheKey('translated-video-count'),
            now()->addSeconds(self::TRANSLATED_COUNT_CACHE_SECONDS),
            fn (): int => (int) DB::table('video_translations')->distinct('video_id')->count('video_id')
        );
    }

    private function clearLocalStatsCache(): void
    {
        Cache::forget($this->cacheKey('local-stats'));
        Cache::forget($this->cacheKey('translated-video-count'));
    }

    private function cacheKey(string $name): string
    {
        return 'avdb-import:'.sha1(base_path()).':'.$name;
    }

    public function clean()
    {
        $cancelledRuns = $this->requestStopAllImports(__('Clean All AVDB requested; stopping active import.'));
        $deletedQueueRows = $this->deleteAvdbQueueRows();
        AvdbDurationBackfill::requestStopWorkers();
        AvdbDurationBackfill::clearPriorityQueue();

        $videoIds = Video::withTrashed()->whereNotNull('avdb_id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $count = count($videoIds);

        if ($count === 0) {
            SeoSitemapCache::clear();
            $this->clearLocalStatsCache();

            return response()->json([
                'success' => true,
                'deleted' => 0,
                'message' => $this->cleanMessage(0, 0, $cancelledRuns, $deletedQueueRows),
            ]);
        }

        $thumbnailFiles = DB::table('thumbnails')
            ->whereIn('video_id', $videoIds)
            ->whereNotNull('file')
            ->pluck('file')
            ->filter()
            ->all();

        $deletedTags = DB::transaction(function () use ($videoIds): int {
            $candidateTagIds = DB::table('video_tags')
                ->whereIn('video_id', $videoIds)
                ->pluck('tag_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

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

            if ($candidateTagIds === []) {
                return 0;
            }

            $orphanTagIds = DB::table('tags')
                ->whereIn('id', $candidateTagIds)
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('video_tags')
                        ->whereColumn('video_tags.tag_id', 'tags.id');
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($orphanTagIds === []) {
                return 0;
            }

            if (DB::getSchemaBuilder()->hasTable('tag_translations')) {
                DB::table('tag_translations')->whereIn('tag_id', $orphanTagIds)->delete();
            }

            return (int) DB::table('tags')->whereIn('id', $orphanTagIds)->delete();
        });

        foreach ($thumbnailFiles as $file) {
            Storage::disk('local')->delete(Video::THUMBNAIL_FOLDER.'/'.$file);
        }

        SeoSitemapCache::clear();
        $this->clearLocalStatsCache();

        return response()->json([
            'success' => true,
            'deleted' => $count,
            'message' => $this->cleanMessage($count, $deletedTags, $cancelledRuns, $deletedQueueRows),
        ]);
    }

    private function cleanMessage(int $deletedVideos, int $deletedTags, int $cancelledRuns, int $deletedQueueRows): string
    {
        $parts = [];

        if ($deletedVideos > 0) {
            $parts[] = __(':count AVDB videos permanently deleted.', ['count' => $deletedVideos]);
            $parts[] = __(':count orphan AVDB tag(s) were removed.', ['count' => $deletedTags]);
        } else {
            $parts[] = __('No AVDB videos found. Nothing was deleted.');
        }

        if ($cancelledRuns > 0) {
            $parts[] = __('Stop requested for :count active AVDB import run(s).', ['count' => $cancelledRuns]);
        }

        if ($deletedQueueRows > 0) {
            $parts[] = __('Removed :count queued AVDB import/duration job(s).', ['count' => $deletedQueueRows]);
        }

        return implode(' ', $parts);
    }

    private function requestStopAllImports(string $reason): int
    {
        if (! Schema::hasTable('avdb_import_runs')) {
            return 0;
        }

        $count = 0;
        AvdbImportRun::query()
            ->whereIn('status', self::ACTIVE_RUN_STATUSES)
            ->orderBy('id')
            ->chunkById(100, function ($runs) use ($reason, &$count): void {
                foreach ($runs as $run) {
                    $run->forceFill([
                        'status' => 'cancelled',
                        'cancel_requested' => true,
                        'message' => $reason,
                        'progress' => 100,
                        'finished_at' => now(),
                    ])->save();
                    $run->appendLog($reason.' '.__('Active workers will exit at the next cancellation checkpoint.'));
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Start an import run in whatever way the current environment supports.
     *
     * Decision tree:
     *   1. `AVDB_IMPORT_MODE=auto` starts a detached PHP CLI process when
     *      possible. This keeps local panels and small hosts from getting
     *      stuck in "queued" when no worker is running.
     *   2. `AVDB_IMPORT_MODE=queue` always dispatches {@see RunAvdbImport} to
     *      the Laravel queue. Use this when a worker / Horizon is running.
     *   3. `AVDB_IMPORT_MODE=inline` runs artisan synchronously inside the
     *      current request. This is the last-resort mode for locked-down hosts.
     *
     * Each step logs a structured message to `storage/logs/laravel.log`
     * so the failure mode is obvious from the admin UI.
     */
    private function startBackgroundImport(AvdbImportRun $run): bool
    {
        $mode = $this->resolveBackgroundImportMode();

        return match ($mode) {
            'queue' => $this->dispatchQueueImport($run),
            'process' => $this->spawnProcessImport($run),
            'inline' => $this->runInlineImport($run),
            default => false,
        };
    }

    private function dispatchQueueImport(AvdbImportRun $run): bool
    {
        try {
            RunAvdbImport::dispatch($run->id);
            Log::info("AVDB import {$run->id} dispatched to queue '".(config('site.avdb_import_queue') ?: 'default')."'.");
            $run->forceFill([
                'status' => 'queued',
                'message' => __('Queued. Waiting for a Laravel queue worker.'),
            ])->save();
            $run->appendLog(__('Dispatched to Laravel queue. Start a Laravel queue worker or Horizon to process it.'));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Queue dispatch for AVDB import failed: '.$e->getMessage());

            return $this->spawnProcessImport($run);
        }
    }

    private function spawnProcessImport(AvdbImportRun $run): bool
    {
        if (! Environment::canRunProcesses()) {
            Log::notice('AVDB import cannot spawn a subprocess (proc_open disabled). Falling back to inline.');

            return $this->runInlineImport($run);
        }

        $php = $this->phpBinary();
        if (! PhpBinary::usable($php)) {
            Log::warning('AVDB import cannot locate a PHP CLI binary. Set AVDB_IMPORT_PHP_BINARY to the absolute path.');

            return $this->runInlineImport($run);
        }

        $artisan = base_path('artisan');
        $log = storage_path("logs/avdb-import-{$run->id}.log");
        $command = [$php, $artisan, 'avdb:import', '--run-id='.$run->id];

        $run->forceFill([
            'status' => 'starting',
            'message' => __('Starting background import process.'),
        ])->save();
        $run->appendLog(__('Starting background import process.'));

        $ok = ShellRunner::runBackground($command, $log, base_path());
        if ($ok) {
            $this->deleteQueuedImportJobStub($run);
            Log::info("AVDB import {$run->id} spawned as background process.");

            return true;
        }

        return $this->runInlineImport($run);
    }

    private function runInlineImport(AvdbImportRun $run): bool
    {
        // Last-resort: run synchronously inside the current request.
        try {
            @ignore_user_abort(true);
            @set_time_limit(0);
            $run->appendLog(__('Running import inline in the current PHP request.'));
            \Illuminate\Support\Facades\Artisan::call('avdb:import', ['--run-id' => $run->id]);
            $this->deleteQueuedImportJobStub($run);

            return true;
        } catch (\Throwable $e) {
            Log::error('Inline AVDB import failed: '.$e->getMessage());

            return false;
        }
    }

    private function resolveBackgroundImportMode(): string
    {
        $mode = strtolower(trim((string) RuntimeEnv::get('AVDB_IMPORT_MODE', 'auto')));
        if (! in_array($mode, ['auto', 'queue', 'process', 'inline'], true)) {
            $mode = 'auto';
        }

        if ($mode !== 'auto') {
            return $mode;
        }

        return $this->canSpawnImportProcess() ? 'process' : 'inline';
    }

    private function canSpawnImportProcess(): bool
    {
        $php = $this->phpBinary();

        return Environment::canRunProcesses() && PhpBinary::usable($php);
    }

    private function autoStartQueuedRun(AvdbImportRun $run): void
    {
        if (
            $run->status === 'starting'
            && ! $run->started_at
            && ! $run->cancel_requested
            && $run->updated_at
            && $run->updated_at->lt(now()->subSeconds(30))
        ) {
            $run->forceFill([
                'status' => 'queued',
                'message' => __('Retrying import start.'),
            ])->save();
            $run->appendLog(__('Retrying start because the background process did not report running.'));
            $run->refresh();
        }

        if ($run->status !== 'queued' || $run->started_at || $run->cancel_requested) {
            return;
        }

        $newerRunId = $this->newerActiveRunId($run);
        if ($newerRunId !== null) {
            $run->forceFill([
                'status' => 'cancelled',
                'cancel_requested' => true,
                'message' => __('Cancelled because newer import run #:id is active.', ['id' => $newerRunId]),
                'finished_at' => now(),
            ])->save();
            $run->appendLog(__('Cancelled because newer import run #:id is active.', ['id' => $newerRunId]));

            return;
        }

        if ($this->resolveBackgroundImportMode() === 'queue') {
            return;
        }

        $claimed = AvdbImportRun::query()
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
        $run->appendLog(__('Auto-started queued import because no queue worker picked it up.'));

        if ($this->startBackgroundImport($run)) {
            return;
        }

        $run->forceFill([
            'status' => 'failed',
            'message' => __('Could not auto-start queued import.'),
            'finished_at' => now(),
        ])->save();
    }

    private function markStaleRunIfInactive(AvdbImportRun $run): void
    {
        if (! in_array((string) $run->status, ['starting', 'running'], true) || $run->cancel_requested) {
            return;
        }

        $lastProgressAt = $run->updated_at ?: $run->started_at ?: $run->created_at;
        if (! $lastProgressAt || $lastProgressAt->gt(now()->subMinutes(self::STALE_ACTIVE_RUN_MINUTES))) {
            return;
        }

        $message = __('Cancelled stale import run because no progress was reported for over :minutes minutes.', [
            'minutes' => self::STALE_ACTIVE_RUN_MINUTES,
        ]);

        $run->forceFill([
            'status' => 'cancelled',
            'cancel_requested' => true,
            'message' => $message,
            'progress' => 100,
            'finished_at' => now(),
        ])->save();
        $run->appendLog($message);
    }

    private function markStaleImportRuns(): void
    {
        AvdbImportRun::query()
            ->whereIn('status', ['starting', 'running'])
            ->where('cancel_requested', false)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_ACTIVE_RUN_MINUTES))
            ->orderBy('id')
            ->chunkById(50, function ($runs): void {
                foreach ($runs as $run) {
                    $this->markStaleRunIfInactive($run);
                }
            });
    }

    private function newerActiveRunId(AvdbImportRun $run): ?int
    {
        $id = AvdbImportRun::query()
            ->where('id', '>', $run->id)
            ->whereIn('status', self::ACTIVE_RUN_STATUSES)
            ->orderBy('id')
            ->value('id');

        return $id ? (int) $id : null;
    }

    private function deleteQueuedImportJobStub(AvdbImportRun $run): void
    {
        $this->deleteImportRunQueueRows((int) $run->id, false);
    }

    private function deleteAvdbQueueRows(bool $includeFailed = true): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $ids = [];
        DB::table('jobs')
            ->select(['id', 'payload'])
            ->orderBy('id')
            ->chunkById(100, function ($jobs) use (&$ids): void {
                foreach ($jobs as $job) {
                    if ($this->payloadIsAvdbQueueJob((string) $job->payload)) {
                        $ids[] = (int) $job->id;
                    }
                }
            }, 'id');

        $deleted = 0;
        if ($ids !== []) {
            $deleted += (int) DB::table('jobs')->whereIn('id', $ids)->delete();
        }

        if ($includeFailed && Schema::hasTable('failed_jobs')) {
            $failedIds = [];
            DB::table('failed_jobs')
                ->select(['id', 'payload'])
                ->orderBy('id')
                ->chunkById(100, function ($jobs) use (&$failedIds): void {
                    foreach ($jobs as $job) {
                        if ($this->payloadIsAvdbQueueJob((string) $job->payload)) {
                            $failedIds[] = (int) $job->id;
                        }
                    }
                }, 'id');

            if ($failedIds !== []) {
                $deleted += (int) DB::table('failed_jobs')->whereIn('id', $failedIds)->delete();
            }
        }

        return $deleted;
    }

    private function deleteImportRunQueueRows(int $runId, bool $includeFailed = true): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $ids = [];
        DB::table('jobs')
            ->select(['id', 'payload'])
            ->orderBy('id')
            ->chunkById(100, function ($jobs) use ($runId, &$ids): void {
                foreach ($jobs as $job) {
                    if ($this->runIdFromQueuePayload((string) $job->payload) === $runId) {
                        $ids[] = (int) $job->id;
                    }
                }
            }, 'id');

        $deleted = 0;
        if ($ids !== []) {
            $deleted += (int) DB::table('jobs')->whereIn('id', $ids)->delete();
        }

        if ($includeFailed && Schema::hasTable('failed_jobs')) {
            $failedIds = [];
            DB::table('failed_jobs')
                ->select(['id', 'payload'])
                ->orderBy('id')
                ->chunkById(100, function ($jobs) use ($runId, &$failedIds): void {
                    foreach ($jobs as $job) {
                        if ($this->runIdFromQueuePayload((string) $job->payload) === $runId) {
                            $failedIds[] = (int) $job->id;
                        }
                    }
                }, 'id');

            if ($failedIds !== []) {
                $deleted += (int) DB::table('failed_jobs')->whereIn('id', $failedIds)->delete();
            }
        }

        return $deleted;
    }

    private function payloadIsAvdbQueueJob(string $payload): bool
    {
        $payload = json_decode($payload, true);
        if (! is_array($payload)) {
            return false;
        }

        $displayName = (string) ($payload['displayName'] ?? '');
        $command = (string) data_get($payload, 'data.command', '');

        foreach ([RunAvdbImport::class, SyncAvdbDurationBatch::class] as $class) {
            if ($displayName === $class || str_contains($command, $class)) {
                return true;
            }
        }

        return false;
    }

    private function runIdFromQueuePayload(string $payload): ?int
    {
        $payload = json_decode($payload, true);
        if (! is_array($payload)) {
            return null;
        }

        $displayName = (string) ($payload['displayName'] ?? '');
        $command = (string) data_get($payload, 'data.command', '');
        if ($displayName !== RunAvdbImport::class && ! str_contains($command, RunAvdbImport::class)) {
            return null;
        }

        if (preg_match('/s:5:"runId";i:(\d+)/', $command, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    private function runIsActive(AvdbImportRun $run): bool
    {
        return in_array((string) $run->status, self::ACTIVE_RUN_STATUSES, true);
    }

    private function deleteRunArtifacts(int $runId): int
    {
        $deleted = 0;

        foreach (glob(storage_path("logs/avdb-import-{$runId}*")) ?: [] as $path) {
            if (is_file($path) && @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function avdbCronHealth($importSchedules): array
    {
        $logPaths = [
            storage_path('logs/cron.log'),
            storage_path('logs/schedule-run.log'),
            storage_path('logs/schedule-work.out.log'),
        ];

        $lastSeenTimestamp = null;
        foreach ($logPaths as $path) {
            if (is_file($path)) {
                $timestamp = filemtime($path);
                if ($timestamp !== false && ($lastSeenTimestamp === null || $timestamp > $lastSeenTimestamp)) {
                    $lastSeenTimestamp = $timestamp;
                }
            }
        }

        $lastSeenAt = $lastSeenTimestamp ? Carbon::createFromTimestamp($lastSeenTimestamp) : null;
        $overdueSchedules = $importSchedules->filter(
            fn (AvdbImportSchedule $schedule): bool => $schedule->enabled
                && $schedule->next_run_at
                && $schedule->next_run_at->lessThan(now())
        );

        $php = $this->phpBinary() ?: 'php';
        $artisan = base_path('artisan');
        $scheduleCommand = $this->quoteShellArg($php).' '.$this->quoteShellArg($artisan).' schedule:run';
        $autoInstall = null;

        if (
            $this->schedulerCronAutoInstallEnabled()
            &&
            $importSchedules->contains(fn (AvdbImportSchedule $schedule): bool => $schedule->enabled)
            && (! $lastSeenAt || $lastSeenAt->lessThan(now()->subMinutes(3)))
        ) {
            $autoInstall = Cache::remember(
                'avdb:scheduler-cron:auto-install:'.sha1(base_path()),
                300,
                fn (): array => LaravelSchedulerCron::ensureInstalled($php)
            );
        }

        return [
            'last_seen_at' => $lastSeenAt,
            'recent' => $lastSeenAt && $lastSeenAt->greaterThanOrEqualTo(now()->subMinutes(3)),
            'overdue_count' => $overdueSchedules->count(),
            'overdue_ids' => $overdueSchedules->pluck('id')->values()->all(),
            'schedule_command' => $scheduleCommand,
            'auto_install' => $autoInstall,
            'auto_install_command' => LaravelSchedulerCron::command($php),
            'panel_script_command' => LaravelSchedulerCron::panelScriptCommand($php),
            'windows_task_command' => 'schtasks /Create /SC MINUTE /MO 1 /TN "AVDBClip Laravel Scheduler" /TR '.$this->quoteShellArg($scheduleCommand).' /F',
            'unix_cron_command' => '* * * * * cd '.$this->quoteShellArg(base_path()).' && '.$this->quoteShellArg($php).' artisan schedule:run >> /dev/null 2>&1',
        ];
    }

    private function quoteShellArg(string $value): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return "'".str_replace("'", "'\\''", $value)."'";
    }

    private function phpBinary(): ?string
    {
        return PhpBinary::resolve(['AVDB_IMPORT_PHP_BINARY']);
    }

    private function schedulerCronAutoInstallEnabled(): bool
    {
        $value = strtolower(trim((string) RuntimeEnv::get('SCHEDULER_CRON_AUTO_INSTALL', '1')));

        return ! in_array($value, ['0', 'false', 'off', 'no'], true);
    }

    private function cleanApiFilter(mixed $value): string
    {
        return Str::limit(trim(strip_tags((string) $value)), 150, '');
    }

    private function normalizeIdsInput(string $input): string
    {
        $input = trim(html_entity_decode(strip_tags($input), ENT_QUOTES, 'UTF-8'));
        if ($input === '') {
            return '';
        }

        $ids = [];
        foreach (preg_split('/[\r\n\s,;]+/', $input) ?: [] as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            $idValue = null;
            if (filter_var($token, FILTER_VALIDATE_URL)) {
                $query = (string) parse_url($token, PHP_URL_QUERY);
                parse_str($query, $params);
                $idValue = $params['ids'] ?? null;
            } elseif (str_contains($token, 'ids=')) {
                parse_str(ltrim(parse_url($token, PHP_URL_QUERY) ?: $token, '?'), $params);
                $idValue = $params['ids'] ?? null;
            } else {
                $idValue = $token;
            }

            foreach (preg_split('/[,\s]+/', (string) $idValue) ?: [] as $part) {
                $id = (int) preg_replace('/\D+/', '', $part);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }

        return implode(',', array_slice(array_values($ids), 0, 200));
    }

    private function normalizeApiUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return self::DEFAULT_API_BASE;
        }

        $validationUrl = str_replace('{locale}', 'en', $url);
        if (! filter_var($validationUrl, FILTER_VALIDATE_URL)) {
            return self::DEFAULT_API_BASE;
        }

        $scheme = strtolower((string) parse_url($validationUrl, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : self::DEFAULT_API_BASE;
    }

    private function apiUrlForLocale(string $apiBase, string $locale): string
    {
        return str_contains($apiBase, '{locale}')
            ? str_replace('{locale}', $locale, $apiBase)
            : $apiBase;
    }

    private function formatRun(?AvdbImportRun $run): ?array
    {
        if (! $run) {
            return null;
        }

        $startedAt = $run->started_at;
        $finishedAt = $run->finished_at;
        $elapsedSeconds = $startedAt
            ? abs((int) $startedAt->diffInSeconds($finishedAt ?: now()))
            : 0;
        $updated = (int) ($run->updated ?? 0);
        $processedItems = (int) $run->imported + $updated + (int) $run->skipped + (int) $run->failed + (int) $run->lang_skipped;
        $pageSize = max(1, min(1000, (int) data_get($run->settings, 'pagesize', 100)));
        $estimatedItems = (int) $run->total_pages > 0
            ? max($processedItems, (int) $run->total_pages * $pageSize)
            : $processedItems;
        $remainingItems = max(0, $estimatedItems - $processedItems);
        $ratePerSecond = $elapsedSeconds > 0 ? $processedItems / $elapsedSeconds : 0.0;
        $etaSeconds = $ratePerSecond > 0 ? (int) ceil($remainingItems / $ratePerSecond) : null;

        return [
            'id' => $run->id,
            'status' => $run->status,
            'progress' => $run->progress,
            'imported' => $run->imported,
            'skipped' => $run->skipped,
            'updated' => $updated,
            'failed' => $run->failed,
            'lang_skipped' => $run->lang_skipped,
            'tags_created' => $run->tags_created,
            'thumbs_downloaded' => $run->thumbs_downloaded,
            'total_pages' => $run->total_pages,
            'completed_pages' => $run->completed_pages,
            'current_category' => $run->current_category,
            'current_page' => $run->current_page,
            'message' => $run->message,
            'log' => $run->log,
            'cancel_requested' => $run->cancel_requested,
            'started_at' => $startedAt?->toDateTimeString(),
            'finished_at' => $finishedAt?->toDateTimeString(),
            'elapsed_seconds' => $elapsedSeconds,
            'elapsed_label' => $this->formatSeconds($elapsedSeconds),
            'pages_label' => number_format((int) $run->completed_pages).' / '.number_format((int) $run->total_pages),
            'processed_items' => $processedItems,
            'estimated_items' => $estimatedItems,
            'items_label' => number_format($processedItems).' / '.number_format($estimatedItems),
            'rate_label' => $ratePerSecond > 0 ? number_format($ratePerSecond * 60, 1).'/min' : __('Calculating'),
            'eta_seconds' => $etaSeconds,
            'eta_label' => in_array($run->status, ['completed', 'failed', 'cancelled'], true)
                ? __('Done')
                : ($etaSeconds !== null ? $this->formatSeconds($etaSeconds) : __('Calculating')),
            'issue_exports' => [
                'failed' => [
                    'url' => route('admin.avdb-import.issues.export', [$run, 'failed']),
                    'count' => (int) $run->failed,
                    'available' => is_file($this->issueExportPath($run, 'failed')),
                ],
                'no_full_lang' => [
                    'url' => route('admin.avdb-import.issues.export', [$run, 'no-full-lang']),
                    'count' => (int) $run->lang_skipped,
                    'available' => is_file($this->issueExportPath($run, 'no-full-lang')),
                ],
                'all' => [
                    'url' => route('admin.avdb-import.issues.export', [$run, 'all']),
                    'count' => (int) $run->failed + (int) $run->lang_skipped,
                    'available' => is_file($this->issueExportPath($run, 'all')),
                ],
            ],
        ];
    }

    private function issueExportPath(AvdbImportRun $run, string $type): string
    {
        return storage_path("logs/avdb-import-{$run->id}-{$type}.tsv");
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedImportSettings(Request $request): array
    {
        $data = $request->validate([
            'api_base_url' => 'nullable|string|max:500',
            'categories' => 'nullable|array',
            'categories.*' => 'integer|between:1,7',
            'pages' => 'required|integer|min:1|max:50000',
            'start_page' => 'required|integer|min:1',
            'pagesize' => 'required|integer|min:1|max:1000',
            'delay' => 'required|integer|min:0|max:10000',
            'language_mode' => 'required|in:auto,global,fixed',
            'global_language' => 'nullable|string|max:5|regex:/^[a-zA-Z]{2,5}$/',
            'thumbnail_mode' => 'required|in:remote,download,skip',
            'api_lang' => 'nullable|string|max:5|regex:/^[a-zA-Z]{2,5}$/',
            'ids' => 'nullable|string|max:20000',
            'search_wd' => 'nullable|string|max:150',
            'search_code' => 'nullable|string|max:100',
            'search_actor' => 'nullable|string|max:150',
            'search_director' => 'nullable|string|max:150',
            'search_writer' => 'nullable|string|max:150',
            'search_category' => 'nullable|string|max:150',
            'updated_hours' => 'nullable|integer|min:0|max:8760',
            'year' => ['nullable', 'string', 'max:9', 'regex:/^\d{4}(-\d{4})?$/'],
            'sort_direction' => 'required|in:desc,asc',
            'locales' => 'nullable|string|max:120|regex:/^[a-zA-Z,\s]*$/',
            'translation_chunk' => 'required|integer|min:10|max:500',
            'translation_concurrency' => 'required|integer|min:1|max:16',
            'use_movie_code_title' => 'nullable|boolean',
            'creator_assignment_enabled' => 'nullable|boolean',
            'skip_tags' => 'nullable|boolean',
            'dry_run' => 'nullable|boolean',
            'translated_only' => 'nullable|boolean',
            'full_langs' => 'nullable|boolean',
            'verify_full_langs' => 'nullable|boolean',
            'starter_views_mode' => ['nullable', Rule::in(array_keys(AvdbStarterViews::modes()))],
            'starter_views_min' => 'nullable|integer|min:0|max:1000000000',
            'starter_views_max' => 'nullable|integer|min:0|max:1000000000',
            'starter_views_spread_days' => 'nullable|integer|min:1|max:3650',
            'starter_views_daily_stats' => 'nullable|boolean',
            'starter_views_only_zero' => 'nullable|boolean',
            'starter_views_existing_zero' => 'nullable|boolean',
        ]);

        $apiLang = strtolower($data['api_lang'] ?? 'en');
        $rawIds = trim((string) ($data['ids'] ?? ''));
        $ids = $this->normalizeIdsInput($rawIds);
        $categories = array_values(array_unique(array_map('intval', $data['categories'] ?? [])));

        if ($rawIds !== '' && $ids === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'ids' => __('Enter at least one AVDB numeric ID or an AVDB API detail URL with an ids parameter.'),
            ]);
        }

        if ($ids === '' && $categories === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'categories' => __('Select at least one category or enter specific AVDB IDs.'),
            ]);
        }

        $language = strtolower($data['global_language'] ?? 'en');

        return [
            'categories' => $categories,
            'pages' => (int) $data['pages'],
            'start_page' => (int) $data['start_page'],
            'pagesize' => (int) $data['pagesize'],
            'delay' => (int) $data['delay'],
            'language_mode' => $data['language_mode'],
            'language' => $data['language_mode'] === 'fixed' ? $language : 'null',
            'thumbnail_mode' => $data['thumbnail_mode'],
            'api_base_url' => $this->normalizeApiUrl((string) ($data['api_base_url'] ?? self::DEFAULT_API_BASE)),
            'api_lang' => $apiLang,
            'ids' => $ids,
            'search_wd' => $this->cleanApiFilter($data['search_wd'] ?? ''),
            'search_code' => $this->cleanApiFilter($data['search_code'] ?? ''),
            'search_actor' => $this->cleanApiFilter($data['search_actor'] ?? ''),
            'search_director' => $this->cleanApiFilter($data['search_director'] ?? ''),
            'search_writer' => $this->cleanApiFilter($data['search_writer'] ?? ''),
            'search_category' => $this->cleanApiFilter($data['search_category'] ?? ''),
            'updated_hours' => (int) ($data['updated_hours'] ?? 0),
            'year' => trim((string) ($data['year'] ?? '')),
            'sort_direction' => $data['sort_direction'],
            'locales' => trim((string) ($data['locales'] ?? '')),
            'translation_chunk' => (int) $data['translation_chunk'],
            'translation_concurrency' => (int) $data['translation_concurrency'],
            'use_movie_code_title' => $request->boolean('use_movie_code_title'),
            'creator_assignment_enabled' => $request->boolean('creator_assignment_enabled', true),
            'skip_tags' => $request->boolean('skip_tags'),
            'dry_run' => $request->boolean('dry_run'),
            'translated_only' => $request->boolean('translated_only', $apiLang !== 'en'),
            'full_langs' => $request->boolean('full_langs'),
            'verify_full_langs' => $request->boolean('full_langs') && $request->boolean('verify_full_langs', true),
            'date_seed' => (string) Str::uuid().'-'.Str::random(16),
            'starter_views_mode' => $data['starter_views_mode'] ?? AvdbStarterViews::MODE_DISABLED,
            'starter_views_min' => (int) ($data['starter_views_min'] ?? AvdbStarterViews::defaults()['min']),
            'starter_views_max' => (int) ($data['starter_views_max'] ?? AvdbStarterViews::defaults()['max']),
            'starter_views_spread_days' => (int) ($data['starter_views_spread_days'] ?? AvdbStarterViews::defaults()['spread_days']),
            'starter_views_daily_stats' => $request->boolean('starter_views_daily_stats', AvdbStarterViews::defaults()['daily_stats']),
            'starter_views_only_zero' => $request->boolean('starter_views_only_zero', AvdbStarterViews::defaults()['only_zero']),
            'starter_views_existing_zero' => $request->boolean('starter_views_existing_zero', AvdbStarterViews::defaults()['apply_existing_zero']),
            'starter_views_seed' => (string) Str::uuid().'-'.Str::random(16),
        ];
    }

    private function formatSeconds(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        if ($minutes < 60) {
            return "{$minutes}m {$remaining}s";
        }

        $hours = intdiv($minutes, 60);
        $minutes %= 60;

        return "{$hours}h {$minutes}m {$remaining}s";
    }
}
