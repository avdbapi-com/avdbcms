<?php

namespace App\Console\Commands;

use App\Enums\ThumbnailStatus;
use App\Enums\VideoStatus;
use App\Models\AvdbImportRun;
use App\Models\Tag;
use App\Models\TagTranslation;
use App\Models\Thumbnail;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoTranslation;
use App\Support\AvdbCreatorAvatarFactory;
use App\Support\AvdbDurationBackfill;
use App\Support\AvdbStarterViews;
use App\Support\ChannelCustomizationSettings;
use App\Support\RemoteVideoDuration;
use App\Support\SeoSitemapCache;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportAvdbVideos extends Command
{
    protected $signature = 'avdb:import
        {--category=  : AVDB category ID (1-7), or "all" for everything}
        {--categories= : Comma-separated AVDB category IDs; overrides --category}
        {--ids=       : Comma-separated AVDB video IDs to import directly}
        {--pages=     : Number of pages to import}
        {--start=1    : Starting page number}
        {--pagesize=100 : Requested videos per page (UI max 1000; AVDB returns max 100)}
        {--skip-thumbs : Skip thumbnail downloading}
        {--skip-tags   : Skip tag parsing}
        {--dry-run     : Show what would be imported without writing}
        {--delay=0     : Delay between API requests in milliseconds}
        {--updated-hours= : Only import videos updated in the last N hours}
        {--year=      : Filter by year or year range, e.g. 2024 or 2020-2024}
        {--sort=desc  : API sort direction: desc or asc}
        {--wd=        : Search keyword / title / code}
        {--code=      : Search exact JAV code}
        {--actor=     : Search actor name}
        {--director=  : Search director name}
        {--writer=    : Search writer or studio}
        {--filter-category= : Search category/tag keyword}
        {--api-base=  : Custom AVDB-compatible provide/vod API URL}
        {--language=   : "auto", "null" for all locales, or a locale code}
        {--api-lang=   : Single translated locale to require/save}
        {--translated-only : For non-English API language, import only if a real translation exists}
        {--full-langs  : Fetch and save all supported locale translations from AVDB}
        {--verify-full-langs : Require translated titles to differ from the English title before import}
        {--locales=    : Comma-separated locales for full-langs; defaults to all supported locales}
        {--translation-chunk=200 : Translation ID batch size}
        {--translation-concurrency=16 : Parallel locale requests for translations}
        {--movie-code-title : Use API movie_code as the imported video title}
        {--creator-assignment=1 : Auto-create/reuse AVDB creator profiles from director, actor, or tag}
        {--starter-views=disabled : Starter view mode: disabled, natural, age_weighted}
        {--starter-min=25 : Minimum starter views}
        {--starter-max=5000 : Maximum starter views}
        {--starter-spread-days=90 : Days used to spread starter daily stats}
        {--starter-daily-stats : Seed video_view_daily_stats with starter views}
        {--starter-existing-zero : Also seed existing AVDB videos that still have zero views}
        {--run-id=     : avdb_import_runs id for progress tracking}';

    protected $description = 'Import videos from AVDB API into the AVDBCMS database';

    private const EN_API = 'https://avdbapi.com/api.php/provide/vod';

    private const ALL_LOCALES = [
        'ja', 'zh', 'ko', 'vi', 'th', 'ms', 'id',
        'fr', 'de', 'es', 'pt', 'ru', 'ar', 'hi', 'tl', 'tr',
    ];

    private const TRANSLATION_ID_CHUNK_SIZE = 200;

    private const TRANSLATION_LOCALE_CONCURRENCY = 16;

    private const FULL_TRANSLATION_SAFE_ID_CHUNK_SIZE = 5;

    private const UI_MAX_PAGE_SIZE = 1000;

    private const AVDB_API_PAGE_SIZE = 100;

    private const TRANSLATION_HTTP_TIMEOUT_SECONDS = 45;

    private const TRANSLATION_HTTP_FALLBACK_TIMEOUT_SECONDS = 12;

    private const TRANSLATION_HTTP_RETRIES = 1;

    private const TRANSLATION_HTTP_FALLBACK_ID_CHUNK_SIZE = 5;

    private const LOCAL_TRANSLATION_BULK_REMOTE_FALLBACK_LIMIT = 50;

    private const ISSUE_EXPORT_HEADER = "time\ttype\tavdb_id\ttitle\treason\tlink\tcategory\tpage\n";

    private array $categoryMap = [
        1 => 1,
        2 => 2,
        3 => 3,
        4 => 4,
        5 => 5,
        6 => 6,
        7 => 7,
    ];

    private ?AvdbImportRun $run = null;

    private int $imported = 0;

    private int $skipped = 0;

    private int $updated = 0;

    private int $failed = 0;

    private int $thumbsDownloaded = 0;

    private int $tagsCreated = 0;

    private int $langSkipped = 0;

    private int $plannedPages = 0;

    private bool $plannedPagesPrecomputed = false;

    private int $completedPages = 0;

    private int $translationIdChunkSize = self::TRANSLATION_ID_CHUNK_SIZE;

    private int $translationLocaleConcurrency = self::TRANSLATION_LOCALE_CONCURRENCY;

    private bool $requireExactTranslationTitles = true;

    private bool $useMovieCodeTitle = false;

    private string $apiBaseUrl = self::EN_API;

    private int $dateSequence = 0;

    private int $dateTotalSlots = 1;

    private int $dateSpreadDays = 0;

    private string $dateSortDirection = 'desc';

    private ?Carbon $dateAnchor = null;

    private string $dateSeed = '';

    private array $dateUsedTimestamps = [];

    private array $starterViewSettings = [];

    private array $avdbCreatorUserCache = [];

    private ?bool $usersHaveAvdbCreatorColumns = null;

    private ?int $fallbackImportUserId = null;

    private bool $creatorAssignmentWarningWritten = false;

    private bool $creatorAssignmentEnabled = true;

    private bool $lastAvdbCreatorProfileChanged = false;

    private ?bool $localAvdbApiTranslationsAvailable = null;

    private bool $dryRunActive = false;

    private const MAX_IMPORT_DATE_SPREAD_DAYS = 3650;

    private const PAGE_PROGRESS_LOG_INTERVAL = 100;

    private const PAGE_ISSUE_EXAMPLE_LIMIT = 24;

    private const IMPORT_RUN_DB_RETRY_ATTEMPTS = 3;

    private const IMPORT_RUN_DB_RETRY_SLEEP_US = 250000;

    private const IMPORT_ITEM_DB_RETRY_ATTEMPTS = 5;

    private const IMPORT_ITEM_DB_RETRY_SLEEP_US = 350000;

    private const DURATION_BACKFILL_IMPORT_INTERVAL = 500;

    private const DURATION_BACKFILL_THROTTLE_SECONDS = 60;

    private int $lastDurationBackfillImportedCount = 0;

    private int $lastDurationBackfillStartedAt = 0;

    public function handle(): int
    {
        $this->raiseImportMemoryLimit();
        DB::disableQueryLog();

        $settings = $this->loadRunSettings();

        $ids = $this->resolveIds($settings);
        $categories = $this->resolveCategories($settings);
        if ($ids === [] && $categories === []) {
            $this->failRun('No valid AVDB categories selected.');

            return self::FAILURE;
        }

        $startPage = max(1, (int) ($settings['start_page'] ?? $this->option('start') ?: 1));
        $requestedPageSize = max(1, min(self::UI_MAX_PAGE_SIZE, (int) ($settings['pagesize'] ?? $this->option('pagesize') ?: self::AVDB_API_PAGE_SIZE)));
        $pageSize = min(self::AVDB_API_PAGE_SIZE, $requestedPageSize);
        $maxPages = max(1, (int) ($settings['pages'] ?? $this->option('pages') ?: 1));
        $delay = max(0, (int) ($settings['delay'] ?? $this->option('delay') ?: 500));
        $thumbnailMode = (string) ($settings['thumbnail_mode'] ?? 'remote');
        $skipThumbs = (bool) $this->option('skip-thumbs') || in_array($thumbnailMode, ['remote', 'skip'], true);
        $skipTags = (bool) $this->option('skip-tags') || (bool) ($settings['skip_tags'] ?? false);
        $dryRun = (bool) $this->option('dry-run') || (bool) ($settings['dry_run'] ?? false);
        $this->dryRunActive = $dryRun;
        $fullLangs = (bool) ($settings['full_langs'] ?? $this->option('full-langs'));
        $verifyFullLangs = (bool) ($settings['verify_full_langs'] ?? $this->option('verify-full-langs'));
        $apiLang = $this->normalizeLocale((string) ($settings['api_lang'] ?? $this->option('api-lang') ?: 'en')) ?: 'en';
        $translatedOnly = (bool) ($settings['translated_only'] ?? ($this->option('translated-only') || $apiLang !== 'en'));
        $languageOpt = $this->resolveLanguageOption($settings);
        $locales = $this->resolveLocales((string) ($settings['locales'] ?? $this->option('locales') ?: ''));
        $apiFilters = $this->resolveApiFilters($settings);
        $this->apiBaseUrl = $this->normalizeApiBaseUrl((string) ($settings['api_base_url'] ?? $this->option('api-base') ?: self::EN_API));
        $this->translationIdChunkSize = max(10, min(500, (int) ($settings['translation_chunk'] ?? $this->option('translation-chunk') ?: self::TRANSLATION_ID_CHUNK_SIZE)));
        $requestedTranslationConcurrency = max(1, min(16, (int) ($settings['translation_concurrency'] ?? $this->option('translation-concurrency') ?: self::TRANSLATION_LOCALE_CONCURRENCY)));
        $this->translationLocaleConcurrency = $this->memorySafeTranslationLocaleConcurrency($requestedTranslationConcurrency);
        $this->useMovieCodeTitle = (bool) ($settings['use_movie_code_title'] ?? $this->option('movie-code-title'));
        $creatorAssignment = filter_var(
            $settings['creator_assignment_enabled'] ?? $this->option('creator-assignment') ?? true,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        $this->creatorAssignmentEnabled = $creatorAssignment ?? true;
        $this->starterViewSettings = $this->resolveStarterViewSettings($settings);
        if ($fullLangs) {
            $apiLang = 'en';
            $translatedOnly = true;
            $this->requireExactTranslationTitles = $verifyFullLangs;
        } else {
            $this->requireExactTranslationTitles = true;
        }

        $listApiBase = ($translatedOnly || $fullLangs) ? $this->apiBaseForLocale('en') : $this->apiBaseForLocale($apiLang);
        $plannedPages = $ids !== []
            ? count($ids)
            : $this->estimatePlannedCategoryPages($categories, $listApiBase, $startPage, $maxPages, $requestedPageSize, $apiFilters);
        $estimatedSlots = $ids !== [] ? count($ids) : max(1, $plannedPages * $requestedPageSize);

        $this->configureImportTimeline(
            $ids,
            $categories,
            $maxPages,
            $requestedPageSize,
            (string) ($apiFilters['sort_direction'] ?? 'desc'),
            (string) ($settings['date_seed'] ?? ''),
            $estimatedSlots
        );

        $useMovieCodeTitle = $this->useMovieCodeTitle;
        $dateTimeline = $this->dateTimelineContext();
        $apiBaseUrl = $this->apiBaseUrl;
        $starterViews = $this->starterViewSettings;
        $creatorAssignmentEnabled = $this->creatorAssignmentEnabled;
        $translationConcurrency = $this->translationLocaleConcurrency;
        $translationChunk = $this->translationIdChunkSize;
        $this->plannedPages = $plannedPages;
        $this->plannedPagesPrecomputed = $ids === [];

        $this->markRunRunning(compact(
            'categories',
            'startPage',
            'pageSize',
            'requestedPageSize',
            'maxPages',
            'delay',
            'thumbnailMode',
            'fullLangs',
            'verifyFullLangs',
            'apiLang',
            'translatedOnly',
            'languageOpt',
            'apiFilters',
            'apiBaseUrl',
            'useMovieCodeTitle',
            'requestedTranslationConcurrency',
            'translationConcurrency',
            'translationChunk',
            'dateTimeline',
            'starterViews',
            'creatorAssignmentEnabled'
        ));
        try {
            if ($ids !== []) {
                if (! $this->importIds(
                    $ids, $delay, $skipThumbs, $skipTags, $dryRun, $languageOpt, $apiLang, $translatedOnly, $fullLangs, $verifyFullLangs, $locales
                )) {
                    $this->finishRun('cancelled', 'Import cancelled.');

                    return self::SUCCESS;
                }
            } else {
                foreach ($categories as $catId) {
                    if (! $this->importCategory(
                        $catId,
                        $listApiBase,
                        $startPage,
                        $requestedPageSize,
                        $pageSize,
                        $maxPages,
                        $delay,
                        $skipThumbs,
                        $skipTags,
                        $dryRun,
                        $languageOpt,
                        $apiLang,
                        $translatedOnly,
                        $fullLangs,
                        $verifyFullLangs,
                        $locales,
                        $apiFilters
                    )) {
                        $this->finishRun('cancelled', 'Import cancelled.');

                        return self::SUCCESS;
                    }
                }
            }

            $this->finishRun('completed', 'Import completed.');
            $this->printSummary();

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failRun(Str::limit($e->getMessage(), 450));
            throw $e;
        }
    }

    private function importIds(
        array $ids,
        int $delay,
        bool $skipThumbs,
        bool $skipTags,
        bool $dryRun,
        string $languageOpt,
        string $apiLang,
        bool $translatedOnly,
        bool $fullLangs,
        bool $verifyFullLangs,
        array $fullLocales
    ): bool {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
        $this->plannedPages = count($ids);
        $this->syncRunCounters('Importing selected AVDB IDs');

        $needsTranslations = $fullLangs || ($translatedOnly && $apiLang !== 'en');
        $fastTrustedFullLangs = $fullLangs && ! $verifyFullLangs;
        $idChunkSize = $needsTranslations && $fullLangs
            ? max(1, min($this->translationIdChunkSize, self::FULL_TRANSLATION_SAFE_ID_CHUNK_SIZE))
            : max(1, min(100, $this->translationIdChunkSize));

        foreach (array_chunk($ids, $idChunkSize) as $chunk) {
            if ($this->shouldCancel()) {
                return false;
            }

            $chunkStartedAt = microtime(true);
            $issues = $this->newPageIssues();
            $before = $this->counterSnapshot();
            $chunkLabel = count($chunk) === 1
                ? "AVDB {$chunk[0]}"
                : 'AVDB IDs '.reset($chunk).'-'.end($chunk);
            $this->syncRunCounters("{$chunkLabel}: fetching details", ['current_page' => $this->completedPages + 1]);
            $this->write("{$chunkLabel}: fetching details for ".count($chunk).' ID(s)');

            $data = $this->apiGet($this->apiBaseForLocale('en'), [
                'ac' => 'detail',
                'ids' => implode(',', $chunk),
            ], 30);

            $itemsById = collect($data['list'] ?? [])
                ->filter(fn ($item) => is_array($item) && (int) ($item['id'] ?? 0) > 0)
                ->keyBy(fn ($item) => (int) $item['id'])
                ->all();

            $batchTranslations = [];
            $fastSkippedIds = [];
            $requiredLocales = $needsTranslations ? ($fullLangs ? $fullLocales : [$apiLang]) : [];
            if ($itemsById !== []) {
                [$remainingItems, $fastSkippedIds] = $this->fastSkipUnchangedExistingItems(
                    array_values($itemsById),
                    null,
                    $skipTags,
                    $dryRun,
                    $needsTranslations,
                    $requiredLocales,
                    $issues
                );
                if ($fastSkippedIds !== []) {
                    $itemsById = collect($remainingItems)
                        ->keyBy(fn ($item) => (int) $item['id'])
                        ->all();
                    $this->write("{$chunkLabel}: fast-skipped ".count($fastSkippedIds).' unchanged existing item(s)'.($needsTranslations ? ' before translation fetch' : ''));
                }
            }

            if ($needsTranslations && $itemsById !== []) {
                $this->write("{$chunkLabel}: checking translations for ".count($itemsById).' item(s)');
                $batchTranslations = $this->fetchBatchTranslations(
                    array_values($itemsById),
                    $requiredLocales,
                    $delay,
                    $chunkLabel,
                    $fastTrustedFullLangs
                );
                $this->write("{$chunkLabel}: translations ready for ".count($batchTranslations).' item(s)');
            }
            $fastSkippedIdSet = array_flip($fastSkippedIds);

            foreach ($chunk as $avdbId) {
                if ($this->shouldCancel()) {
                    return false;
                }

                if (isset($fastSkippedIdSet[$avdbId])) {
                    $this->completedPages++;
                    $this->syncRunCounters("AVDB {$avdbId}: fast-skipped unchanged", ['current_page' => $this->completedPages]);

                    continue;
                }

                $item = $itemsById[$avdbId] ?? null;
                if (! is_array($item)) {
                    $this->failed++;
                    $this->recordPageIssue($issues, 'failed', $avdbId, '', 'detail not found or API failed');
                    $this->completedPages++;
                    $this->syncRunCounters("AVDB {$avdbId}: not found", ['current_page' => $this->completedPages]);

                    continue;
                }

                $catId = $this->categoryIdFromItem($item);
                $this->importItem(
                    $item,
                    $catId,
                    $delay,
                    $skipThumbs,
                    $skipTags,
                    $dryRun,
                    $languageOpt,
                    $apiLang,
                    $translatedOnly,
                    $fullLangs,
                    $verifyFullLangs,
                    $fullLocales,
                    $needsTranslations ? ($batchTranslations[$avdbId] ?? null) : null,
                    $issues
                );

                $this->completedPages++;
                $this->syncRunCounters("AVDB {$avdbId}: processed", ['current_page' => $this->completedPages]);
            }

            $delta = $this->counterDelta($before);
            $this->syncRunCounters("{$chunkLabel}: processed", ['current_page' => $this->completedPages]);
            $this->write("{$chunkLabel}: processed ".count($chunk).' ID(s) in '.$this->formatSeconds(microtime(true) - $chunkStartedAt).' | '.$this->formatCounterDelta($delta));
            $this->writeIssueSummary($issues);
            $this->maybeStartDurationBackfillDuringImport($dryRun);
            $this->sleepMs(min($delay, 250));
        }

        return true;
    }

    private function importCategory(
        int $catId,
        string $listApiBase,
        int $startPage,
        int $requestedPageSize,
        int $pageSize,
        int $maxPages,
        int $delay,
        bool $skipThumbs,
        bool $skipTags,
        bool $dryRun,
        string $languageOpt,
        string $apiLang,
        bool $translatedOnly,
        bool $fullLangs,
        bool $verifyFullLangs,
        array $fullLocales,
        array $apiFilters = []
    ): bool {
        if ($this->shouldCancel()) {
            return false;
        }

        $probe = $this->apiGet($listApiBase, $this->apiParams($apiFilters, [
            'ac' => 'detail',
            't' => $catId,
            'pagesize' => 1,
        ]));
        if (! $probe) {
            $this->failed++;
            $this->write("Category {$catId}: probe failed");
            $this->syncRunCounters("Category {$catId}: probe failed");

            return true;
        }

        $totalVideos = (int) ($probe['total'] ?? 0);
        $totalPages = max(1, (int) ceil(max(1, $totalVideos) / max(1, $requestedPageSize)));
        $endPage = min($startPage + $maxPages - 1, $totalPages);
        $categoryPages = max(0, $endPage - $startPage + 1);

        if ($this->plannedPagesPrecomputed) {
            $this->plannedPages = max($this->plannedPages, $this->completedPages + $categoryPages);
        } else {
            $this->plannedPages = max(
                $this->completedPages + $categoryPages,
                $this->plannedPages > 0
                    ? $this->plannedPages + ($categoryPages - $maxPages)
                    : $categoryPages
            );
        }
        $this->syncRunCounters("Category {$catId}: {$totalVideos} videos, pages {$startPage}-{$endPage}");
        $this->write("Category {$catId}: {$totalVideos} videos available; importing logical pages {$startPage}-{$endPage} with {$requestedPageSize} item target ({$pageSize} per AVDB request)");

        for ($page = $startPage; $page <= $endPage; $page++) {
            if ($this->shouldCancel()) {
                return false;
            }

            $pageStartedAt = microtime(true);
            $before = $this->counterSnapshot();
            $issues = $this->newPageIssues();
            $this->syncRunCounters("Category {$catId}, page {$page}", [
                'current_category' => $catId,
                'current_page' => $page,
            ]);
            $this->write("Category {$catId}, page {$page}: fetching logical page");

            $pageChunks = $this->fetchLogicalCategoryPageChunks($listApiBase, $catId, $page, $requestedPageSize, $pageSize, $apiFilters);
            $items = [];
            foreach ($pageChunks as $chunk) {
                array_push($items, ...$chunk['items']);
            }

            if ($items === []) {
                $this->failed++;
                $this->recordPageIssue($issues, 'failed', 0, '', 'API page empty or failed');
                $this->completedPages++;
                $this->syncRunCounters("Category {$catId}, page {$page}: empty or failed");
                $this->write("Category {$catId}, page {$page}: empty or failed in ".$this->formatSeconds(microtime(true) - $pageStartedAt));
                $this->writeIssueSummary($issues);

                continue;
            }

            $pageImported = 0;
            $scanItems = array_slice($items, 0, $requestedPageSize);
            $items = $scanItems;
            $scanItemCount = count($scanItems);
            $needsTranslations = $fullLangs || ($translatedOnly && $apiLang !== 'en');
            $requiredLocales = $needsTranslations ? ($fullLangs ? $fullLocales : [$apiLang]) : [];
            [$items, $fastSkippedIds] = $this->fastSkipUnchangedExistingItems(
                $items,
                $catId,
                $skipTags,
                $dryRun,
                $needsTranslations,
                $requiredLocales,
                $issues
            );
            $sortLabel = ($apiFilters['sort_direction'] ?? 'desc') === 'asc' ? 'oldest first' : 'newest first';
            $fastSkippedCount = count($fastSkippedIds);
            $this->write("Category {$catId}, page {$page}: received {$scanItemCount} item(s), AVDB IDs ".$this->itemIdRangeLabel($scanItems).", {$sortLabel}".($fastSkippedCount > 0 ? "; fast-skipped {$fastSkippedCount} unchanged" : '').($needsTranslations && $items !== [] ? '; checking translations' : ''));

            $processed = $fastSkippedCount;
            $pageTranslations = [];
            if ($needsTranslations && $items !== []) {
                $batchLabel = "Category {$catId}, page {$page}";
                if ($fullLangs) {
                    $this->write("{$batchLabel}: full-language page; using fast parallel AVDB locale page fetches");
                }

                $pageItemIds = array_flip(array_map(fn ($pageItem) => (int) ($pageItem['id'] ?? 0), $items));
                foreach ($pageChunks as $chunk) {
                    $chunkItems = array_values(array_filter($chunk['items'], function ($item) use ($pageItemIds) {
                        $avdbId = (int) ($item['id'] ?? 0);

                        return $avdbId > 0 && isset($pageItemIds[$avdbId]);
                    }));

                    if ($chunkItems === []) {
                        continue;
                    }

                    $failedLocales = [];
                    $chunkLabel = "{$batchLabel}, API page {$chunk['api_page']}";
                    $pageTranslations = $this->mergeTranslationSets($pageTranslations, $this->fetchPageTranslations(
                        $chunkItems,
                        $catId,
                        (int) $chunk['api_page'],
                        $pageSize,
                        $requiredLocales,
                        $apiFilters,
                        $delay,
                        $chunkLabel,
                        $failedLocales
                    ));

                    if ($failedLocales !== []) {
                        $missingChunkItems = array_values(array_filter($chunkItems, function ($item) use ($pageTranslations, $requiredLocales) {
                            $avdbId = (int) ($item['id'] ?? 0);

                            return $avdbId > 0 && ! $this->translationsAreComplete($pageTranslations[$avdbId] ?? [], $requiredLocales);
                        }));

                        if ($missingChunkItems !== []) {
                            $this->write("{$chunkLabel}: retrying ".count($missingChunkItems).' item(s) for failed/timed-out locale(s): '.implode(',', $failedLocales));
                            $pageTranslations = $this->mergeTranslationSets($pageTranslations, $this->fetchBatchTranslations(
                                $missingChunkItems,
                                $failedLocales,
                                $delay,
                                "{$chunkLabel}, failed-locale fallback",
                                false
                            ));
                        }
                    }
                }

                $missingTranslationItems = count(array_filter($items, function ($item) use ($pageTranslations, $requiredLocales) {
                    $avdbId = (int) ($item['id'] ?? 0);

                    return $avdbId > 0 && ! $this->translationsAreComplete($pageTranslations[$avdbId] ?? [], $requiredLocales);
                }));

                if ($missingTranslationItems > 0) {
                    $missingLabel = $this->requireExactTranslationTitles ? 'exact locale titles' : 'locale titles';
                    $this->write("{$batchLabel}: {$missingTranslationItems} item(s) missing {$missingLabel} after translation fetch");
                }
            }

            foreach ($items as $item) {
                if ($this->shouldCancel()) {
                    return false;
                }

                $avdbId = (int) ($item['id'] ?? 0);
                if ($this->importItem(
                    $item,
                    $catId,
                    $delay,
                    $skipThumbs,
                    $skipTags,
                    $dryRun,
                    $languageOpt,
                    $apiLang,
                    $translatedOnly,
                    $fullLangs,
                    $verifyFullLangs,
                    $fullLocales,
                    $needsTranslations ? ($pageTranslations[$avdbId] ?? []) : null,
                    $issues
                )) {
                    $pageImported++;
                }

                $processed++;
                if ($processed % self::PAGE_PROGRESS_LOG_INTERVAL === 0 || $processed === $scanItemCount) {
                    $this->syncRunCounters("Category {$catId}, page {$page}: processed {$processed}/{$scanItemCount}");
                    $this->write("Category {$catId}, page {$page}: processed {$processed}/{$scanItemCount} | imported {$this->imported}, updated {$this->updated}, skipped {$this->skipped}, no-lang {$this->langSkipped}, failed {$this->failed}");
                }
            }

            $this->completedPages++;
            $delta = $this->counterDelta($before);
            $this->syncRunCounters("Category {$catId}, page {$page}: +{$pageImported}");
            $this->write("Category {$catId}, page {$page}: completed in ".$this->formatSeconds(microtime(true) - $pageStartedAt)." | scanned {$scanItemCount} | ".$this->formatCounterDelta($delta));
            $this->writeIssueSummary($issues);
            $this->maybeStartDurationBackfillDuringImport($dryRun);

            if ($page < $endPage) {
                $this->sleepMs($delay);
            }
        }

        return true;
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, int>}
     */
    private function fastSkipUnchangedExistingItems(
        array $items,
        ?int $catId,
        bool $skipTags,
        bool $dryRun,
        bool $needsTranslations,
        array $requiredLocales,
        ?array &$pageIssues = null
    ): array {
        $ids = array_values(array_unique(array_filter(array_map(
            fn ($item) => is_array($item) ? (int) ($item['id'] ?? 0) : 0,
            $items
        ), fn ($id) => $id > 0)));

        if ($ids === []) {
            return [$items, []];
        }

        try {
            $existingByAvdbId = Video::with(['user'])
                ->withTrashed()
                ->whereIn('avdb_id', $ids)
                ->get()
                ->keyBy(fn (Video $video) => (int) $video->avdb_id);
        } catch (\Throwable) {
            return [$items, []];
        }

        if ($existingByAvdbId->isEmpty()) {
            return [$items, []];
        }

        $requiredLocales = array_values(array_unique(array_filter(array_map(
            fn ($locale) => $this->normalizeLocale($locale),
            $requiredLocales
        ), fn ($locale) => $locale && $locale !== 'en')));

        $translationCountsByVideoId = [];
        if ($needsTranslations && $requiredLocales !== []) {
            $videoIds = $existingByAvdbId
                ->filter(fn (Video $video) => ! $video->trashed())
                ->pluck('id')
                ->all();

            if ($videoIds !== []) {
                try {
                    $translationCountsByVideoId = DB::table('video_translations as vt')
                        ->join('videos as v', 'v.id', '=', 'vt.video_id')
                        ->select('vt.video_id', DB::raw('count(distinct vt.locale) as locale_count'))
                        ->whereIn('vt.video_id', $videoIds)
                        ->whereIn('vt.locale', $requiredLocales)
                        ->whereRaw("trim(coalesce(vt.title, '')) <> ''")
                        ->where(function ($query) {
                            $query
                                ->whereRaw("lower(trim(coalesce(vt.title, ''))) <> lower(trim(coalesce(v.title, '')))")
                                ->orWhere(function ($q) {
                                    $q->whereRaw("trim(coalesce(vt.description, '')) <> ''")
                                        ->whereRaw("lower(trim(coalesce(vt.description, ''))) <> lower(trim(coalesce(v.description, '')))");
                                });
                        })
                        ->groupBy('vt.video_id')
                        ->pluck('locale_count', 'video_id')
                        ->map(fn ($count) => (int) $count)
                        ->all();
                } catch (\Throwable) {
                    return [$items, []];
                }
            }
        }

        $remaining = [];
        $skippedIds = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $avdbId = (int) ($item['id'] ?? 0);
            $existing = $existingByAvdbId->get($avdbId);
            if (! $existing || $existing->trashed()) {
                $remaining[] = $item;

                continue;
            }

            $itemCatId = $catId ?? $this->categoryIdFromItem($item);
            $canSkip = $dryRun || (
                ! $this->existingVideoNeedsRefresh($existing, $item, $itemCatId, ! $skipTags)
                && ! $this->shouldSeedExistingStarterViews($existing)
                && ! $this->existingAvdbCreatorNeedsReassignment($existing, $item)
            );

            if ($canSkip && $needsTranslations && $requiredLocales !== []) {
                $canSkip = (int) ($translationCountsByVideoId[(int) $existing->id] ?? 0) >= count($requiredLocales);
            }

            if (! $canSkip) {
                $remaining[] = $item;

                continue;
            }

            $this->skipped++;
            $skippedIds[] = $avdbId;
            $this->recordPageIssue($pageIssues, 'skipped', $avdbId, $this->titleFromItem($item), 'duplicate unchanged', $this->safeVideoRoute($existing));
        }

        return [$remaining, $skippedIds];
    }

    private function importItem(
        array $item,
        int $catId,
        int $delay,
        bool $skipThumbs,
        bool $skipTags,
        bool $dryRun,
        string $languageOpt,
        string $apiLang,
        bool $translatedOnly,
        bool $fullLangs,
        bool $verifyFullLangs,
        array $fullLocales,
        ?array $preloadedTranslations = null,
        ?array &$pageIssues = null
    ): bool {
        $avdbId = (int) ($item['id'] ?? 0);
        if ($avdbId <= 0) {
            $this->failed++;
            $this->recordPageIssue($pageIssues, 'failed', 0, '', 'missing AVDB id');

            return false;
        }

        $sourceTitle = $this->titleFromItem($item);
        $playableLink = $this->extractEmbedCode($item);
        $posterUrl = $this->posterUrlFromItem($item);

        try {
            $existing = $this->retryImportItemDb(
                fn () => Video::withTrashed()->where('avdb_id', $avdbId)->first(),
                $avdbId,
                $sourceTitle
            );
        } catch (\Throwable $e) {
            return $this->failImportItem($pageIssues, $avdbId, $sourceTitle, $e, $playableLink);
        }

        if ($existing && ! $existing->trashed()) {
            try {
                [$wasUpdated, $starterViewsSeeded, $creatorReassigned] = $this->retryImportItemDb(
                    fn () => DB::transaction(function () use ($existing, $item, $catId, $skipTags, $dryRun): array {
                        $wasUpdated = false;
                        $starterViewsSeeded = false;
                        if (! $dryRun && $this->existingVideoNeedsRefresh($existing, $item, $catId, ! $skipTags)) {
                            $wasUpdated = $this->updateExistingVideoMetadata($existing, $item, $catId, $skipTags);
                        }

                        if (! $dryRun && ! $skipTags && ! $wasUpdated && $this->existingVideoNeedsTagRefresh($existing, $item)) {
                            $wasUpdated = $this->parseTags($existing, $item, true);
                        }

                        if (! $dryRun && $this->shouldSeedExistingStarterViews($existing)) {
                            $starterViewsSeeded = $this->seedStarterViewsForExisting($existing, $item);
                            $wasUpdated = $starterViewsSeeded || $wasUpdated;
                        }

                        $creatorReassigned = false;
                        if (! $dryRun) {
                            $creatorReassigned = $this->updateExistingAvdbCreator($existing, $item, $catId);
                            $wasUpdated = $creatorReassigned || $wasUpdated;
                        }

                        return [$wasUpdated, $starterViewsSeeded, $creatorReassigned];
                    }),
                    $avdbId,
                    $sourceTitle
                );
            } catch (\Throwable $e) {
                return $this->failImportItem($pageIssues, $avdbId, $sourceTitle, $e, $this->safeVideoRoute($existing, $playableLink));
            }

            $translationsUpdated = false;
            $existingTranslations = [];
            if ($fullLangs && $preloadedTranslations !== null && $this->translationsAreComplete($preloadedTranslations, $fullLocales)) {
                $existingTranslations = $preloadedTranslations;
            } elseif ($translatedOnly && $apiLang !== 'en' && ! empty($preloadedTranslations[$apiLang]['title'])) {
                $existingTranslations = [$apiLang => $preloadedTranslations[$apiLang]];
            }

            if (! $dryRun && $existingTranslations !== []) {
                try {
                    $translationsUpdated = (bool) $this->retryImportItemDb(
                        fn () => DB::transaction(function () use ($existing, $item, $skipTags, $existingTranslations): bool {
                            $changed = $this->saveTranslations($existing, $existingTranslations, $this->useMovieCodeTitle ? $existing->getRawOriginal('title') : null);
                            if (! $skipTags) {
                                $changed = $this->saveTagTranslations($item, $existingTranslations) || $changed;
                            }

                            return $changed;
                        }),
                        $avdbId,
                        $sourceTitle
                    );
                } catch (\Throwable $e) {
                    return $this->failImportItem($pageIssues, $avdbId, $sourceTitle, $e, $this->safeVideoRoute($existing, $playableLink));
                }
            }

            if ($wasUpdated || $translationsUpdated) {
                $this->updated++;
                $this->recordPageIssue(
                    $pageIssues,
                    'updated',
                    $avdbId,
                    $sourceTitle,
                    $translationsUpdated
                        ? 'exact translations changed'
                        : ($creatorReassigned
                        ? 'creator assignment changed'
                        : ($starterViewsSeeded ? 'starter views seeded' : 'AVDB content changed')),
                    $this->safeVideoRoute($existing)
                );
            } else {
                $this->skipped++;
                $this->recordPageIssue($pageIssues, 'skipped', $avdbId, $sourceTitle, 'duplicate unchanged', $this->safeVideoRoute($existing));
            }

            return false;
        }

        if ($existing && $existing->trashed()) {
            try {
                $this->retryImportItemDb(
                    fn () => $this->deleteVideoGraph([$existing->id]),
                    $avdbId,
                    $sourceTitle
                );
            } catch (\Throwable $e) {
                return $this->failImportItem($pageIssues, $avdbId, $sourceTitle, $e, $playableLink);
            }
        }

        $englishTitle = $this->cleanText($item['name'] ?? '');
        $importTitle = $sourceTitle;
        if ($importTitle === '') {
            $this->failed++;
            $this->recordPageIssue($pageIssues, 'failed', $avdbId, '', 'missing title');

            return false;
        }

        if (! $playableLink) {
            $this->failed++;
            $this->recordPageIssue($pageIssues, 'broken', $avdbId, $importTitle, 'missing playable link');

            return false;
        }

        if (! $posterUrl) {
            $this->recordPageIssue($pageIssues, 'broken', $avdbId, $importTitle, 'missing poster/thumbnail URL', $playableLink);
        }

        $englishTranslation = $this->translationFromItem($item, null, 'en');
        $translationCompareTitle = ($englishTranslation['title'] ?? '') !== ''
            ? $englishTranslation['title']
            : ($englishTitle !== '' ? $englishTitle : $importTitle);

        $translations = [];
        if ($fullLangs) {
            $translations = $preloadedTranslations !== null
                ? ($this->translationsAreComplete($preloadedTranslations, $fullLocales) ? $preloadedTranslations : null)
                : $this->fetchAllTranslations($avdbId, $item, $fullLocales, $delay);
            if ($translations === null) {
                $this->langSkipped++;
                $reason = $this->requireExactTranslationTitles
                    ? 'missing exact full translations'
                    : 'missing full locale responses';
                $this->recordPageIssue($pageIssues, 'skipped', $avdbId, $importTitle, $reason, $playableLink);

                return false;
            }
        } elseif ($translatedOnly && $apiLang !== 'en') {
            $translation = $preloadedTranslations !== null
                ? ($preloadedTranslations[$apiLang] ?? null)
                : $this->fetchTranslation($avdbId, $apiLang, $translationCompareTitle, $englishTranslation);
            if ($translation === null) {
                $this->langSkipped++;
                $this->recordPageIssue($pageIssues, 'skipped', $avdbId, $importTitle, "missing {$apiLang} translation", $playableLink);

                return false;
            }
            $translations[$apiLang] = $translation;
        }

        if ($dryRun) {
            $this->imported++;

            return true;
        }

        try {
            $video = $this->retryImportItemDb(
                fn () => DB::transaction(function () use ($item, $catId, $skipTags, $languageOpt, $translations): ?Video {
                    $video = $this->createVideo($item, $catId, $skipTags, $languageOpt);
                    if (! $video) {
                        return null;
                    }

                    $this->saveTranslations($video, $translations, $this->useMovieCodeTitle ? $video->getRawOriginal('title') : null);
                    if (! $skipTags) {
                        $this->saveTagTranslations($item, $translations);
                    }

                    return $video;
                }),
                $avdbId,
                $importTitle
            );

            if (! $video) {
                $this->failed++;
                $this->recordPageIssue($pageIssues, 'failed', $avdbId, $importTitle, 'database create returned empty', $playableLink);

                return false;
            }

            if (! $skipThumbs) {
                $this->downloadThumbnail($video, $item);
            }

            $this->imported++;

            return true;
        } catch (\Throwable $e) {
            return $this->failImportItem($pageIssues, $avdbId, $importTitle, $e, $playableLink);
        }
    }

    private function failImportItem(?array &$pageIssues, int $avdbId, string $title, \Throwable $e, ?string $link = null): bool
    {
        $this->failed++;
        $reason = $e instanceof QueryException && $this->isTransientDatabaseError($e)
            ? 'transient DB error after '.self::IMPORT_ITEM_DB_RETRY_ATTEMPTS.' attempts: '.Str::limit($e->getMessage(), 90, '')
            : Str::limit($e->getMessage(), 120, '');

        $this->recordPageIssue($pageIssues, 'failed', $avdbId, $title, $reason, $link);
        $this->write("AVDB {$avdbId}: ".Str::limit($e->getMessage(), 160));

        return false;
    }

    private function createVideo(array $item, int $catId, bool $skipTags, string $languageOpt): ?Video
    {
        $avdbId = (int) $item['id'];
        $title = Str::limit($this->titleFromItem($item), 250, '');
        $slugSource = $this->useMovieCodeTitle ? Str::slug($title) : (string) ($item['slug'] ?? Str::slug($title));
        $slug = $this->uniqueSlug($slugSource);
        $metadata = $this->metadataFromItem($item, $catId, ! $skipTags);
        $movieCode = $metadata['avdb_code'] ?? '';
        $description = $this->cleanDescription($item['description'] ?? $title);

        $publishedAt = $this->nextImportPublishedAt($avdbId);
        $createdAt = $publishedAt->copy();
        $userId = $this->avdbCreatorUserIdForNewVideo($item, $catId, $publishedAt);
        $embedCode = $this->extractEmbedCode($item);
        $posterUrl = $this->posterUrlFromItem($item);
        $country = $this->arrayToString($item['country'] ?? '');
        $duration = $this->durationFromItem($item);
        $starterViews = $this->starterViewsForItem($item, $publishedAt);
        $language = match ($languageOpt) {
            'null' => null,
            'auto' => $this->mapLanguage($country),
            default => $languageOpt,
        };
        // An empty string is not a locale: the public feeds filter on
        // `language = :locale OR language IS NULL`, so '' would hide the video
        // from every localized rail/feed. Store NULL (= language-neutral) instead.
        $language = is_string($language) && trim($language) !== '' ? trim($language) : null;

        $video = Video::create([
            'uuid' => (string) Str::uuid(),
            'title' => $title,
            'slug' => $slug,
            'description' => $description ?: $title,
            'file' => null,
            'embed_code' => $embedCode,
            'poster_url' => $posterUrl,
            'upload18_vid' => null,
            'avdb_id' => $avdbId,
            ...$metadata,
            'original_file_name' => $movieCode ?: $title,
            'original_mimetype' => 'video/mp4',
            'duration' => $duration,
            'size' => null,
            'user_id' => $userId,
            'category_id' => $this->categoryMap[$catId] ?? 1,
            'status' => VideoStatus::PUBLIC->value,
            'language' => $language,
            'published_at' => $publishedAt,
            'uploaded_at' => $publishedAt,
            'created_at' => $createdAt,
            'updated_at' => $publishedAt,
            'allow_comments' => true,
            'show_likes' => true,
            'show_ad' => true,
            'is_live' => false,
            'is_short' => false,
            'views' => $starterViews,
        ]);

        $this->seedStarterDailyStats($video, $starterViews, $publishedAt, $avdbId);

        if (! $skipTags) {
            $this->parseTags($video, $item);
        }

        return $video;
    }

    private function starterViewsForItem(array $item, Carbon $publishedAt): int
    {
        $avdbId = (int) ($item['id'] ?? 0);
        if ($avdbId <= 0) {
            return 0;
        }

        $releaseDate = $this->parseDate($item['vod_pubdate'] ?? null) ?: $publishedAt;

        return AvdbStarterViews::countFor($avdbId, $releaseDate, $this->starterViewSettings);
    }

    private function seedStarterDailyStats(Video $video, int $views, Carbon $endAt, int $avdbId): void
    {
        if ($views <= 0 || ! (bool) ($this->starterViewSettings['daily_stats'] ?? false)) {
            return;
        }

        AvdbStarterViews::seedDailyStats(
            (int) $video->id,
            $views,
            $endAt,
            (int) ($this->starterViewSettings['spread_days'] ?? AvdbStarterViews::defaults()['spread_days']),
            (string) ($this->starterViewSettings['seed'] ?? '').'|'.$avdbId
        );
    }

    private function shouldSeedExistingStarterViews(Video $video): bool
    {
        if (! AvdbStarterViews::enabled($this->starterViewSettings)) {
            return false;
        }

        if (! (bool) ($this->starterViewSettings['apply_existing_zero'] ?? false)) {
            return false;
        }

        return ! (bool) ($this->starterViewSettings['only_zero'] ?? true) || (int) $video->views <= 0;
    }

    private function seedStarterViewsForExisting(Video $video, array $item): bool
    {
        $avdbId = (int) ($item['id'] ?? $video->avdb_id ?? 0);
        $publishedAt = $video->published_at instanceof Carbon ? $video->published_at->copy() : now();
        $starterViews = $this->starterViewsForItem($item, $publishedAt);
        if ($starterViews <= 0) {
            return false;
        }

        $currentViews = max(0, (int) $video->views);
        $newViews = (bool) ($this->starterViewSettings['only_zero'] ?? true)
            ? $starterViews
            : min(1000000000, $currentViews + $starterViews);
        $delta = max(0, $newViews - $currentViews);
        if ($delta <= 0) {
            return false;
        }

        $video->forceFill(['views' => $newViews])->save();
        $this->seedStarterDailyStats($video, $delta, $publishedAt, $avdbId);

        return true;
    }

    private function existingVideoNeedsRefresh(Video $video, array $item, int $catId, bool $includeTags = true): bool
    {
        $remoteHash = $this->avdbContentHashFromItem($item, $catId, $includeTags);
        $localHash = trim((string) ($video->avdb_content_hash ?? ''));
        if ($localHash !== '') {
            return ! hash_equals($localHash, $remoteHash);
        }

        $remoteUpdatedAt = $this->avdbUpdatedAtFromItem($item);
        if ($remoteUpdatedAt && $video->avdb_updated_at && $remoteUpdatedAt->ne($video->avdb_updated_at)) {
            return true;
        }

        return true;
    }

    private function updateExistingVideoMetadata(Video $video, array $item, int $catId, bool $skipTags): bool
    {
        $metadata = $this->metadataFromItem($item, $catId, ! $skipTags);
        $duration = $this->durationFromItem($item);
        $title = Str::limit($this->titleFromItem($item), 250, '');
        $description = $this->cleanDescription($item['description'] ?? $title);
        $posterUrl = $this->posterUrlFromItem($item);
        $embedCode = $this->extractEmbedCode($item);
        $movieCode = $metadata['avdb_code'] ?? '';
        $updates = [
            ...$metadata,
            'title' => $title,
            'description' => $description ?: $title,
            'original_file_name' => $movieCode ?: $title,
        ];

        if ($posterUrl) {
            $updates['poster_url'] = $posterUrl;
        }

        if ($embedCode) {
            $updates['embed_code'] = $embedCode;
        }

        if (isset($this->categoryMap[$catId])) {
            $updates['category_id'] = $this->categoryMap[$catId];
        }

        if ($duration !== null) {
            $updates['duration'] = $duration;
        }

        $changed = false;
        if ($updates !== []) {
            $video->forceFill($updates)->save();
            $changed = $video->wasChanged();
        }

        if ($this->useMovieCodeTitle && $title !== '') {
            VideoTranslation::query()
                ->where('video_id', $video->id)
                ->update(['title' => $title]);
        }

        if (! $skipTags) {
            $changed = $this->parseTags($video, $item, true) || $changed;
        }

        return $changed;
    }

    private function avdbCreatorUserIdForNewVideo(array $item, int $catId, Carbon $publishedAt): int
    {
        if (! $this->creatorAssignmentEnabled) {
            return $this->fallbackImportUserId();
        }

        $candidate = $this->avdbCreatorCandidateFromItem($item);
        if ($candidate === null) {
            return $this->fallbackImportUserId();
        }

        return $this->firstOrCreateAvdbCreator($candidate, $item, $catId, $publishedAt)?->id
            ?? $this->fallbackImportUserId();
    }

    private function updateExistingAvdbCreator(Video $video, array $item, int $catId): bool
    {
        if (! $this->creatorAssignmentEnabled) {
            return false;
        }

        if (! $this->canReassignExistingAvdbVideo($video)) {
            return false;
        }

        $candidate = $this->avdbCreatorCandidateFromItem($item);
        if ($candidate === null) {
            return false;
        }

        $publishedAt = $video->published_at instanceof Carbon
            ? $video->published_at->copy()
            : ($video->uploaded_at instanceof Carbon ? $video->uploaded_at->copy() : now());

        $creator = $this->firstOrCreateAvdbCreator($candidate, $item, $catId, $publishedAt);
        $profileChanged = $this->lastAvdbCreatorProfileChanged;
        if (! $creator || (int) $video->user_id === (int) $creator->id) {
            return $profileChanged;
        }

        $video->forceFill(['user_id' => $creator->id])->save();

        return $video->wasChanged('user_id') || $profileChanged;
    }

    private function existingAvdbCreatorNeedsReassignment(Video $video, array $item): bool
    {
        if (! $this->creatorAssignmentEnabled || ! $this->canReassignExistingAvdbVideo($video)) {
            return false;
        }

        $candidate = $this->avdbCreatorCandidateFromItem($item);
        if ($candidate === null) {
            return false;
        }

        $user = $video->relationLoaded('user')
            ? $video->user
            : User::withTrashed()->find((int) $video->user_id);

        if (! $user) {
            return true;
        }

        $candidateKey = (string) ($candidate['key'] ?? '');
        if ($this->usersHaveAvdbCreatorColumns() && trim((string) ($user->avdb_creator_key ?? '')) === $candidateKey) {
            return $this->avdbCreatorProfileNeedsRefresh($user, $candidate);
        }

        return strtolower((string) $user->email) !== strtolower($this->avdbCreatorEmail($candidateKey));
    }

    private function avdbCreatorProfileNeedsRefresh(User $user, array $candidate): bool
    {
        if ($this->usersHaveAvdbCreatorColumns()) {
            if (trim((string) ($user->avdb_creator_source ?? '')) === '' || trim((string) ($user->avdb_creator_name ?? '')) === '') {
                return true;
            }
        }

        if (! $user->description) {
            return true;
        }

        if (! $user->channel_customization) {
            return true;
        }

        return AvdbCreatorAvatarFactory::needsRepair($user);
    }

    private function avdbCreatorCandidateFromItem(array $item): ?array
    {
        $director = $this->firstCreatorName($item['director'] ?? '');
        if ($director !== null) {
            return $this->avdbCreatorCandidate('director', $director);
        }

        $actor = $this->firstCreatorName($item['actor'] ?? '');
        if ($actor !== null) {
            return $this->avdbCreatorCandidate('actor', $actor);
        }

        // Tags are content labels, not reliable creator identities. Falling
        // back to tags produced fake channels such as numeric codes.
        return null;
    }

    private function firstCreatorName(mixed $value): ?string
    {
        foreach ($this->cleanList($value) as $name) {
            $name = $this->normalizeCreatorName($name);
            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    private function avdbCreatorCandidate(string $source, string $name): ?array
    {
        $name = $this->normalizeCreatorName($name);
        if ($name === null) {
            return null;
        }

        $slug = Str::slug($name);
        if ($slug === '') {
            $slug = 'creator-'.substr(hash('sha1', $name), 0, 12);
        }

        $scope = $source === 'tag' ? 'tag' : 'person';

        return [
            'source' => $source,
            'name' => $name,
            'key' => $scope.':'.$slug,
        ];
    }

    private function normalizeCreatorName(mixed $value): ?string
    {
        $name = $this->cleanText($value);
        $name = preg_replace('/\s+/u', ' ', $name) ?: '';
        $name = trim($name, " \t\n\r\0\x0B-_,.;:|/");

        $lowerName = strtolower($name);
        if ($name === '' || in_array($lowerName, ['----', 'unknown', 'n/a', 'none', 'updating'], true)) {
            return null;
        }

        if (mb_strlen($name) < 2) {
            return null;
        }

        if (preg_match('/^\d+$/', $name)) {
            return null;
        }

        if (preg_match('/^[a-z]{1,12}[-_\s]?\d{1,8}$/i', $name) || preg_match('/^\d{1,8}[-_\s]?[a-z]{1,12}$/i', $name)) {
            return null;
        }

        return Str::limit($name, 100, '');
    }

    private function firstOrCreateAvdbCreator(array $candidate, array $item, int $catId, Carbon $publishedAt): ?User
    {
        $key = (string) $candidate['key'];
        $this->lastAvdbCreatorProfileChanged = false;
        if (isset($this->avdbCreatorUserCache[$key])) {
            return User::query()->find($this->avdbCreatorUserCache[$key]);
        }

        try {
            $email = $this->avdbCreatorEmail($key);
            $user = $this->findExistingAvdbCreator($key, $email);

            if ($user) {
                if ($user->trashed()) {
                    $user->restore();
                }

                $this->lastAvdbCreatorProfileChanged = $this->refreshAvdbCreatorProfile($user, $candidate, $item, $catId, $publishedAt);
                $this->avdbCreatorUserCache[$key] = (int) $user->id;

                return $user;
            }

            $createdAt = $this->avdbCreatorCreatedAt($key, $publishedAt);
            $username = $this->uniqueAvdbCreatorUsername((string) $candidate['name'], $key);
            $slug = $this->uniqueAvdbCreatorSlug($username, $key);

            $attributes = [
                'username' => $username,
                'slug' => $slug,
                'email' => $email,
                'password' => Str::random(48),
                'email_verified_at' => $createdAt,
                'description' => $this->avdbCreatorDescription($candidate, $item, $catId),
                'avatar' => AvdbCreatorAvatarFactory::ensure($candidate),
                'country' => $this->countryCodeFromItem($item),
                'website' => null,
                'show_subscribers' => true,
                'language' => $this->mapLanguage($this->arrayToString($item['country'] ?? '')),
                'channel_customization' => $this->avdbCreatorChannelCustomization($candidate, $item),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            if ($this->usersHaveAvdbCreatorColumns()) {
                $attributes['avdb_creator_key'] = $key;
                $attributes['avdb_creator_source'] = (string) $candidate['source'];
                $attributes['avdb_creator_name'] = Str::limit((string) $candidate['name'], 150, '');
            }

            $user = User::query()->create($attributes);
            $this->lastAvdbCreatorProfileChanged = true;
            $this->avdbCreatorUserCache[$key] = (int) $user->id;

            return $user;
        } catch (\Throwable $e) {
            if (! $this->creatorAssignmentWarningWritten) {
                $this->creatorAssignmentWarningWritten = true;
                $this->write('AVDB creator assignment fallback: '.Str::limit($e->getMessage(), 160, ''));
            }

            return null;
        }
    }

    private function findExistingAvdbCreator(string $key, string $email): ?User
    {
        if ($this->usersHaveAvdbCreatorColumns()) {
            $user = User::withTrashed()->where('avdb_creator_key', $key)->first();
            if ($user) {
                return $user;
            }
        }

        return User::withTrashed()->where('email', $email)->first();
    }

    private function refreshAvdbCreatorProfile(User $user, array $candidate, array $item, int $catId, Carbon $publishedAt): bool
    {
        $updates = [];

        if ($this->usersHaveAvdbCreatorColumns()) {
            $updates['avdb_creator_key'] = (string) $candidate['key'];
            $updates['avdb_creator_source'] = (string) $candidate['source'];
            $updates['avdb_creator_name'] = Str::limit((string) $candidate['name'], 150, '');
        }

        if (! $user->email_verified_at) {
            $updates['email_verified_at'] = $this->avdbCreatorCreatedAt((string) $candidate['key'], $publishedAt);
        }

        if (! $user->description) {
            $updates['description'] = $this->avdbCreatorDescription($candidate, $item, $catId);
        }

        if (! $user->avatar || AvdbCreatorAvatarFactory::isGenerated($user->avatar) || ! Storage::exists(User::AVATAR_FOLDER.'/'.$user->avatar)) {
            $updates['avatar'] = AvdbCreatorAvatarFactory::ensure($candidate);
        }

        if (! $user->country && ($country = $this->countryCodeFromItem($item))) {
            $updates['country'] = $country;
        }

        if (! $user->channel_customization) {
            $updates['channel_customization'] = $this->avdbCreatorChannelCustomization($candidate, $item);
        }

        if ($updates !== []) {
            $updates['updated_at'] = now();
            $user->forceFill($updates)->save();

            return true;
        }

        return false;
    }

    private function canReassignExistingAvdbVideo(Video $video): bool
    {
        $currentUserId = (int) $video->user_id;
        if ($currentUserId <= 0) {
            return true;
        }

        if ($currentUserId === $this->fallbackImportUserId()) {
            return true;
        }

        $user = $video->relationLoaded('user')
            ? $video->user
            : User::withTrashed()->find($currentUserId);
        if (! $user) {
            return true;
        }

        return $user->is_admin || $this->isAvdbGeneratedCreator($user);
    }

    private function isAvdbGeneratedCreator(User $user): bool
    {
        if ($this->usersHaveAvdbCreatorColumns() && trim((string) ($user->avdb_creator_key ?? '')) !== '') {
            return true;
        }

        return str_ends_with(strtolower((string) $user->email), '@creators.avdbclip.invalid');
    }

    private function fallbackImportUserId(): int
    {
        if ($this->fallbackImportUserId !== null) {
            return $this->fallbackImportUserId;
        }

        $this->fallbackImportUserId = (int) (
            User::query()->whereKey(1)->value('id')
            ?: User::query()->where('is_admin', true)->value('id')
            ?: User::query()->value('id')
            ?: 1
        );

        return $this->fallbackImportUserId;
    }

    private function usersHaveAvdbCreatorColumns(): bool
    {
        if ($this->usersHaveAvdbCreatorColumns !== null) {
            return $this->usersHaveAvdbCreatorColumns;
        }

        return $this->usersHaveAvdbCreatorColumns = DB::getSchemaBuilder()->hasColumn('users', 'avdb_creator_key');
    }

    private function avdbCreatorEmail(string $key): string
    {
        return 'avdb-'.substr(hash('sha256', $key), 0, 32).'@creators.avdbclip.invalid';
    }

    private function uniqueAvdbCreatorUsername(string $name, string $key): string
    {
        $max = max(12, (int) config('validation.user.username.max', 50));
        $base = trim(Str::limit($name, $max, ''));
        if (mb_strlen($base) < 3) {
            $base = 'AVDB Creator';
        }

        $candidate = $base;
        $suffix = substr(hash('sha1', $key), 0, 6);
        $attempt = 0;

        while (User::withTrashed()->where('username', $candidate)->exists()) {
            $tail = $attempt === 0 ? ' '.strtoupper($suffix) : ' '.strtoupper($suffix).$attempt;
            $candidate = Str::limit($base, $max - mb_strlen($tail), '').$tail;
            $attempt++;
        }

        return $candidate;
    }

    private function uniqueAvdbCreatorSlug(string $username, string $key): string
    {
        $base = Str::slug($username);
        if ($base === '') {
            $base = 'avdb-creator-'.substr(hash('sha1', $key), 0, 8);
        }

        $base = Str::limit($base, 90, '');
        $candidate = $base;
        $attempt = 0;

        while (User::withTrashed()->where('slug', $candidate)->exists()) {
            $suffix = '-'.substr(hash('sha1', $key.'|'.$attempt), 0, 8);
            $candidate = Str::limit($base, 100 - strlen($suffix), '').$suffix;
            $attempt++;
        }

        return $candidate;
    }

    private function avdbCreatorDescription(array $candidate, array $item, int $catId): string
    {
        $name = (string) $candidate['name'];
        $role = match ((string) $candidate['source']) {
            'director' => 'director',
            'actor' => 'performer',
            'tag' => 'topic curator',
            default => 'creator',
        };

        $focus = $this->cleanText($item['type_name'] ?? '') ?: ($this->categoryMap[$catId] ?? 'AVDB');
        $movieCode = $this->movieCodeFromItem($item);
        $country = $this->cleanText($item['country'] ?? '');
        $parts = array_filter([$focus, $country, $movieCode ? "including {$movieCode}" : null]);
        $context = $parts === [] ? 'AVDB videos' : implode(', ', $parts);

        return Str::limit(
            "{$name} is an imported {$role} channel featuring {$context}. Follow this profile for organized releases, searchable metadata, and fresh videos from the AVDB catalog.",
            (int) config('validation.user.description.max', 5000),
            ''
        );
    }

    private function avdbCreatorChannelCustomization(array $candidate, array $item): array
    {
        $seed = (string) $candidate['key'].'|'.((int) ($item['id'] ?? 0));
        $options = ChannelCustomizationSettings::options();
        $pick = fn (string $key, string $fallback) => $this->stableOption(array_keys($options[$key] ?? []), $seed.'|'.$key, $fallback);

        return ChannelCustomizationSettings::normalizeForStorage([
            'layout' => 'spotlight',
            'theme' => $pick('theme', 'midnight'),
            'accent' => $pick('accent', 'coral'),
            'surface' => $pick('surface', 'glass'),
            'banner_treatment' => 'cinematic',
            'avatar_frame' => $pick('avatar_frame', 'ring'),
            'motion' => 'gentle',
            'pattern' => $pick('pattern', 'signal'),
            'header_shape' => 'framed',
            'stats_style' => 'pills',
            'video_layout' => 'grid',
            'featured_mode' => $pick('featured_mode', 'latest'),
            'featured_layout' => 'compact',
            'featured_columns' => '3',
            'featured_rows' => '1',
            'latest_uploads_layout' => 'grid',
            'latest_uploads_columns' => '4',
            'latest_uploads_rows' => '2',
            'playlists_layout' => 'stacked',
            'density' => 'comfortable',
            'tagline' => $this->creatorTagline($candidate),
            'show_stats' => '1',
            'show_featured_video' => '1',
            'show_latest_uploads' => '1',
            'show_playlists' => '0',
            'show_about_panel' => '1',
            'show_social_links' => '0',
            'show_upload_rhythm' => '1',
            'show_subscribe_cta' => '1',
            'dedupe_home_sections' => '1',
        ]);
    }

    private function creatorTagline(array $candidate): string
    {
        return match ((string) $candidate['source']) {
            'director' => 'Director picks and new releases',
            'actor' => 'Fresh videos and featured appearances',
            'tag' => 'A focused AVDB topic collection',
            default => 'Fresh AVDB releases',
        };
    }

    private function countryCodeFromItem(array $item): ?string
    {
        $country = strtolower($this->cleanText($item['country'] ?? ''));
        if ($country === '') {
            return null;
        }

        $map = [
            'japan' => 'JP',
            'japanese' => 'JP',
            'china' => 'CN',
            'chinese' => 'CN',
            'korea' => 'KR',
            'korean' => 'KR',
            'vietnam' => 'VN',
            'vietnamese' => 'VN',
            'thai' => 'TH',
            'thailand' => 'TH',
            'english' => 'US',
            'usa' => 'US',
            'united states' => 'US',
            'france' => 'FR',
            'germany' => 'DE',
            'spain' => 'ES',
            'portugal' => 'PT',
            'russia' => 'RU',
        ];

        foreach ($map as $needle => $code) {
            if (str_contains($country, $needle)) {
                return $code;
            }
        }

        return preg_match('/^[a-z]{2}$/', $country) ? strtoupper($country) : null;
    }

    private function avdbCreatorCreatedAt(string $key, Carbon $publishedAt): Carbon
    {
        $anchor = $publishedAt->lessThan(now()) ? $publishedAt->copy() : now();

        return $anchor
            ->subDays($this->stableNumber($key.'|created-days', 7, 540))
            ->setTime(
                $this->stableNumber($key.'|created-hour', 0, 23),
                $this->stableNumber($key.'|created-minute', 0, 59),
                $this->stableNumber($key.'|created-second', 0, 59)
            );
    }

    private function stableOption(array $values, string $seed, string $fallback): string
    {
        $values = array_values(array_filter($values, fn ($value) => is_string($value) && $value !== 'custom'));
        if ($values === []) {
            return $fallback;
        }

        return $values[$this->stableNumber($seed, 0, count($values) - 1)] ?? $fallback;
    }

    private function stableNumber(string $seed, int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        $span = $max - $min + 1;

        return $min + (int) (hexdec(substr(hash('sha256', $seed), 0, 8)) % $span);
    }

    /**
     * Full-language imports are intentionally checked in small ID groups.
     * Import each group as soon as its translations are known so the admin
     * page shows real imported videos instead of waiting for the whole API
     * page to finish hundreds of translation requests.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function translationProcessingBatches(array $items, bool $needsTranslations, bool $fullLangs): array
    {
        if (! $needsTranslations || $items === []) {
            return [$items];
        }

        $chunkSize = $fullLangs
            ? min($this->translationIdChunkSize, self::FULL_TRANSLATION_SAFE_ID_CHUNK_SIZE)
            : $this->translationIdChunkSize;

        return array_chunk($items, max(1, $chunkSize));
    }

    private function fetchPageTranslations(
        array $englishItems,
        int $catId,
        int $page,
        int $pageSize,
        array $locales,
        array $apiFilters,
        int $delay,
        ?string $label = null,
        ?array &$failedLocales = null
    ): array {
        $label = $label ?: "Category {$catId}, page {$page}";
        if ($failedLocales !== null) {
            $failedLocales = [];
        }

        $locales = array_values(array_filter(array_map(
            fn ($locale) => $this->normalizeLocale($locale),
            $locales
        ), fn ($locale) => $locale && $locale !== 'en'));

        if ($englishItems === [] || $locales === []) {
            return [];
        }

        $translationsById = [];
        $englishTitlesById = [];
        $englishTranslationsById = [];
        foreach ($englishItems as $item) {
            $avdbId = (int) ($item['id'] ?? 0);
            $translation = $this->translationFromItem($item, null, 'en');
            if ($avdbId <= 0 || $translation === null) {
                continue;
            }

            $translationsById[$avdbId] = ['en' => $translation];
            $englishTitlesById[$avdbId] = $translation['title'];
            $englishTranslationsById[$avdbId] = $translation;
        }

        if ($translationsById === []) {
            $this->syncRunCounters("{$label}: no translation candidates");

            return [];
        }

        $localTranslations = $this->loadLocalAvdbApiTranslations(array_keys($translationsById), $locales, $englishTitlesById, $englishTranslationsById);
        foreach ($localTranslations as $avdbId => $translations) {
            if (isset($translationsById[$avdbId])) {
                $translationsById[$avdbId] = array_replace($translationsById[$avdbId], $translations);
            }
        }

        $completeFromLocal = array_filter(
            $translationsById,
            fn ($translations) => $this->translationsAreComplete($translations, $locales)
        );
        if ($completeFromLocal !== []) {
            $this->write("{$label}: local avdbapi translation database completed ".count($completeFromLocal).'/'.count($translationsById).' candidate(s)');
        }
        if (count($completeFromLocal) === count($translationsById)) {
            return $completeFromLocal;
        }

        foreach (array_keys($completeFromLocal) as $completedAvdbId) {
            unset($translationsById[$completedAvdbId], $englishTitlesById[$completedAvdbId], $englishTranslationsById[$completedAvdbId]);
        }

        $localeGroups = array_chunk($locales, $this->translationLocaleConcurrency);
        $totalBatches = count($localeGroups);
        $batchNumber = 0;
        $throttleMs = min($delay, 250);
        $startedAt = microtime(true);

        $this->write("{$label}: fetching page translations for ".count($translationsById).' candidate ID(s) across '.count($locales).' locale(s)');

        foreach ($localeGroups as $localeGroup) {
            if ($this->shouldCancel()) {
                return [];
            }

            $batchNumber++;
            $batchMessage = "{$label}: page translation batch {$batchNumber}/{$totalBatches}, locales ".implode(',', $localeGroup);
            $this->syncRunCounters($batchMessage);
            $this->write($batchMessage);

            try {
                $responses = Http::pool(function (Pool $pool) use ($localeGroup, $apiFilters, $catId, $page, $pageSize) {
                    foreach ($localeGroup as $locale) {
                        $pool->as($locale)
                            ->timeout(self::TRANSLATION_HTTP_TIMEOUT_SECONDS)
                            ->retry(self::TRANSLATION_HTTP_RETRIES, 300, null, false)
                            ->withOptions(['verify' => false])
                            ->get($this->apiBaseForLocale($locale), $this->apiParams($apiFilters, [
                                'ac' => 'detail',
                                't' => $catId,
                                'pg' => $page,
                                'pagesize' => $pageSize,
                            ]));
                    }
                });
            } catch (\Throwable) {
                $responses = [];
            }

            foreach ($localeGroup as $locale) {
                $response = $responses[$locale] ?? null;
                $titleIds = [];
                if (is_object($response) && method_exists($response, 'successful') && $response->successful()) {
                    $items = $response->json('list');
                    $titleIds = $this->mergeLocaleTranslationItems(
                        $locale,
                        is_array($items) ? $items : [],
                        $translationsById,
                        $englishTitlesById,
                        $englishTranslationsById
                    );
                    $this->write("{$label}: locale {$locale} has ".count($titleIds).'/'.count($englishTitlesById).' usable title(s)');
                } else {
                    if ($failedLocales !== null) {
                        $failedLocales[] = $locale;
                    }

                    $this->write("{$label}: locale {$locale} page fetch failed or timed out; queued for ID fallback");
                }

                unset($responses[$locale], $response, $items);
                $this->collectGarbage();
            }

            $completeCount = count(array_filter(
                $translationsById,
                fn ($translations) => $this->translationsAreComplete($translations, $locales)
            ));
            $this->write("{$label}: page translation batch {$batchNumber}/{$totalBatches} done in ".$this->formatSeconds((int) ceil(microtime(true) - $startedAt))."; {$completeCount} complete");
            unset($responses);
            $this->collectGarbage();
            $this->sleepMs($throttleMs);
        }

        return $completeFromLocal + $translationsById;
    }

    private function mergeTranslationSets(array $base, array $extra): array
    {
        foreach ($extra as $avdbId => $translations) {
            if (! is_array($translations)) {
                continue;
            }

            $base[$avdbId] = array_replace($base[$avdbId] ?? [], $translations);
        }

        return $base;
    }

    private function fetchBatchTranslations(array $englishItems, array $locales, int $delay, ?string $label = null, bool $trustedFastMode = false): array
    {
        $label = $label ?: 'Translations';
        $locales = array_values(array_filter(array_map(
            fn ($locale) => $this->normalizeLocale($locale),
            $locales
        ), fn ($locale) => $locale && $locale !== 'en'));

        if ($englishItems === [] || $locales === []) {
            $this->syncRunCounters("{$label}: no translation candidates");

            return [];
        }

        $translationsById = [];
        $englishTitlesById = [];
        $englishTranslationsById = [];

        foreach ($englishItems as $item) {
            $avdbId = (int) ($item['id'] ?? 0);
            $translation = $this->translationFromItem($item);
            if ($avdbId <= 0 || $translation === null) {
                continue;
            }

            $translationsById[$avdbId] = ['en' => $translation];
            $englishTitlesById[$avdbId] = $translation['title'];
            $englishTranslationsById[$avdbId] = $translation;
        }

        if ($translationsById === []) {
            $this->syncRunCounters("{$label}: no valid translation candidates");

            return [];
        }

        $ids = array_keys($translationsById);
        $localTranslations = $this->loadLocalAvdbApiTranslations($ids, $locales, $englishTitlesById, $englishTranslationsById);
        foreach ($localTranslations as $avdbId => $translations) {
            if (! isset($translationsById[$avdbId])) {
                continue;
            }

            $translationsById[$avdbId] = array_replace($translationsById[$avdbId], $translations);
        }

        $completeFromLocal = array_filter(
            $translationsById,
            fn ($translations) => $this->translationsAreComplete($translations, $locales)
        );
        if ($completeFromLocal !== []) {
            $this->write("{$label}: local avdbapi translation database completed ".count($completeFromLocal).'/'.count($translationsById).' candidate(s)');
        }
        if (count($completeFromLocal) === count($translationsById)) {
            return $completeFromLocal;
        }

        if (
            $this->localAvdbApiTranslationsAvailable()
            && count($locales) > 1
            && count($ids) > self::LOCAL_TRANSLATION_BULK_REMOTE_FALLBACK_LIMIT
            && ! $trustedFastMode
        ) {
            $missingLocal = count($translationsById) - count($completeFromLocal);
            $this->write("{$label}: local translation database is authoritative for bulk full-language import; skipping slow remote fallback for {$missingLocal} incomplete candidate(s)");

            return $completeFromLocal;
        }

        foreach (array_keys($completeFromLocal) as $completedAvdbId) {
            unset($translationsById[$completedAvdbId], $englishTitlesById[$completedAvdbId], $englishTranslationsById[$completedAvdbId]);
        }

        if ($translationsById === []) {
            return $completeFromLocal;
        }

        $throttleMs = min($delay, 250);
        $ids = array_keys($translationsById);
        $effectiveChunkSize = count($locales) > 1
            ? min($this->translationIdChunkSize, self::FULL_TRANSLATION_SAFE_ID_CHUNK_SIZE)
            : $this->translationIdChunkSize;
        $idChunks = array_chunk($ids, max(1, $effectiveChunkSize));
        $localeGroups = array_chunk($locales, $this->translationLocaleConcurrency);
        $totalBatches = max(1, count($idChunks) * count($localeGroups));
        $batchNumber = 0;
        $startedAt = microtime(true);

        $this->write("{$label}: checking ".count($ids).' candidate ID(s) across '.count($locales)." locale(s), {$totalBatches} API batch(es), {$effectiveChunkSize} ID(s) per batch");

        foreach ($idChunks as $chunk) {
            $seenLocalesForChunk = [];

            foreach ($localeGroups as $localeGroup) {
                $chunk = array_values(array_filter($chunk, fn ($id) => isset($translationsById[$id])));
                if ($chunk === []) {
                    $this->write("{$label}: no candidates remain for this ID chunk");
                    break;
                }

                if ($this->shouldCancel()) {
                    return [];
                }

                $batchNumber++;
                $localeList = implode(',', $localeGroup);
                $idLabel = count($chunk) === 1 ? (string) reset($chunk) : reset($chunk).'-'.end($chunk);
                $batchMessage = "{$label}: translation batch {$batchNumber}/{$totalBatches}, IDs {$idLabel}, locales {$localeList}";
                $this->syncRunCounters($batchMessage);
                $this->write($batchMessage);

                try {
                    $responses = Http::pool(function (Pool $pool) use ($localeGroup, $chunk) {
                        foreach ($localeGroup as $locale) {
                            $pool->as($locale)
                                ->timeout(self::TRANSLATION_HTTP_TIMEOUT_SECONDS)
                                ->retry(self::TRANSLATION_HTTP_RETRIES, 300, null, false)
                                ->withOptions(['verify' => false])
                                ->get($this->apiBaseForLocale($locale), [
                                    'ac' => 'detail',
                                    'ids' => implode(',', $chunk),
                                ]);
                        }
                    });
                } catch (\Throwable) {
                    $responses = [];
                }

                $fetchedLocalesForGroup = [];
                foreach ($localeGroup as $locale) {
                    $response = $responses[$locale] ?? null;
                    $titleIds = [];
                    $fetchedLocale = false;

                    if (! is_object($response) || ! method_exists($response, 'successful') || ! $response->successful()) {
                        $this->write("{$label}: locale {$locale} batch failed or timed out; retrying sequentially");
                        $items = $this->fetchTranslationBatchItemsAdaptively($locale, $chunk, self::TRANSLATION_HTTP_FALLBACK_TIMEOUT_SECONDS, false);
                        if ($items !== null) {
                            $fetchedLocale = true;
                            $titleIds = $this->mergeLocaleTranslationItems($locale, $items, $translationsById, $englishTitlesById, $englishTranslationsById);
                        }
                    } else {
                        $fetchedLocale = true;
                        $data = $response->json();
                        $items = is_array($data['list'] ?? null) ? $data['list'] : [];
                        $titleIds = $this->mergeLocaleTranslationItems($locale, $items, $translationsById, $englishTitlesById, $englishTranslationsById);
                    }

                    if ($fetchedLocale) {
                        $missingIds = array_values(array_diff($chunk, $titleIds));
                        if ($missingIds !== []) {
                            $this->write("{$label}: locale {$locale} returned ".count($titleIds).'/'.count($chunk).' title(s); marking '.count($missingIds).' missing candidate(s) as no full lang without retry');
                        }

                        $fetchedLocalesForGroup[] = $locale;
                    }

                    unset($responses[$locale], $response, $data, $items);
                    $this->collectGarbage();
                }

                $seenLocalesForChunk = array_values(array_unique(array_merge($seenLocalesForChunk, $fetchedLocalesForGroup)));
                foreach ($chunk as $avdbId) {
                    if (! $this->translationsHaveRequiredLocaleTitles($translationsById[$avdbId] ?? [], $seenLocalesForChunk)) {
                        unset($translationsById[$avdbId], $englishTitlesById[$avdbId], $englishTranslationsById[$avdbId]);
                    }
                }

                $remainingInChunk = count(array_filter($chunk, fn ($id) => isset($translationsById[$id])));
                $completeCount = count(array_filter(
                    $translationsById,
                    fn ($translations) => $this->translationsAreComplete($translations, $locales)
                ));
                $this->syncRunCounters("{$label}: translation batch {$batchNumber}/{$totalBatches} done; {$remainingInChunk} candidate(s) still possible, {$completeCount} complete");
                $this->write("{$label}: translation batch {$batchNumber}/{$totalBatches} done in ".$this->formatSeconds((int) ceil(microtime(true) - $startedAt))."; {$remainingInChunk} candidate(s) still possible, {$completeCount} complete");

                unset($responses);
                $this->collectGarbage();
                $this->sleepMs($throttleMs);
            }
        }

        $completeFromHttp = array_filter(
            $translationsById,
            fn ($translations) => $this->translationsAreComplete($translations, $locales)
        );

        return $completeFromLocal + $completeFromHttp;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchTranslationBatchItems(string $locale, array $ids, int $timeout = self::TRANSLATION_HTTP_TIMEOUT_SECONDS, bool $retry = true): ?array
    {
        $locale = $this->normalizeLocale($locale);
        $ids = array_values(array_unique(array_filter(
            array_map(fn ($id) => (int) $id, $ids),
            fn ($id) => $id > 0
        )));

        if (! $locale || $locale === 'en' || $ids === []) {
            return null;
        }

        try {
            $request = Http::timeout(max(1, $timeout))
                ->withOptions(['verify' => false]);

            if ($retry) {
                $request = $request->retry(self::TRANSLATION_HTTP_RETRIES + 1, 500, null, false);
            }

            $response = $request->get($this->apiBaseForLocale($locale), [
                'ac' => 'detail',
                'ids' => implode(',', $ids),
            ]);

            if (! $response->successful()) {
                return null;
            }

            $items = $response->json('list');

            return is_array($items) ? $items : [];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchTranslationBatchItemsAdaptively(string $locale, array $ids, int $timeout = self::TRANSLATION_HTTP_FALLBACK_TIMEOUT_SECONDS, bool $tryWholeBatch = true): ?array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn ($id) => (int) $id, $ids),
            fn ($id) => $id > 0
        )));

        if ($ids === []) {
            return null;
        }

        if ($tryWholeBatch) {
            $items = $this->fetchTranslationBatchItems($locale, $ids, $timeout, false);
            if ($items !== null || count($ids) === 1) {
                return $items;
            }
        } elseif (count($ids) === 1) {
            return $this->fetchTranslationBatchItems($locale, $ids, $timeout, false);
        }

        $merged = [];
        $receivedAnyResponse = false;
        $failedSubchunks = [];
        foreach (array_chunk($ids, self::TRANSLATION_HTTP_FALLBACK_ID_CHUNK_SIZE) as $subchunk) {
            $items = $this->fetchTranslationBatchItems($locale, $subchunk, $timeout, false);
            if ($items === null) {
                $failedSubchunks[] = $subchunk;
                continue;
            }

            $receivedAnyResponse = true;
            array_push($merged, ...$items);
            $this->sleepMs(75);
        }

        foreach ($failedSubchunks as $subchunk) {
            foreach ($subchunk as $id) {
                $items = $this->fetchTranslationBatchItems($locale, [$id], $timeout, false);
                if ($items === null) {
                    continue;
                }

                $receivedAnyResponse = true;
                array_push($merged, ...$items);
                $this->sleepMs(75);
            }
        }

        return $receivedAnyResponse ? $merged : null;
    }

    /**
     * @return array<int, int>
     */
    private function mergeLocaleTranslationItems(string $locale, array $items, array &$translationsById, array $englishTitlesById, array $englishTranslationsById = []): array
    {
        $titleIds = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $avdbId = (int) ($item['id'] ?? 0);
            if ($avdbId <= 0 || ! isset($englishTitlesById[$avdbId])) {
                continue;
            }

            $translation = $this->translationFromItem(
                $item,
                $englishTitlesById[$avdbId],
                $locale,
                $englishTranslationsById[$avdbId] ?? null
            );
            if ($translation !== null) {
                $translationsById[$avdbId][$locale] = $translation;
                $titleIds[] = $avdbId;
            }
        }

        return array_values(array_unique($titleIds));
    }

    private function fetchAllTranslations(int $avdbId, array $englishItem, array $locales, int $delay): ?array
    {
        $englishTranslation = $this->translationFromItem($englishItem);
        if ($englishTranslation === null) {
            return null;
        }

        $translations = ['en' => $englishTranslation];

        foreach ($locales as $locale) {
            $translation = $this->fetchTranslation($avdbId, $locale, $englishTranslation['title'], $englishTranslation);
            if ($translation === null) {
                return null;
            }

            $translations[$locale] = $translation;
            $this->sleepMs($delay);
        }

        return $this->translationsAreComplete($translations, $locales) ? $translations : null;
    }

    private function loadLocalAvdbApiTranslations(array $ids, array $locales, array $englishTitlesById, array $englishTranslationsById = []): array
    {
        if ($ids === [] || $locales === [] || ! $this->localAvdbApiTranslationsAvailable()) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = DB::select(
                "select vod_id, titles, descs from avdbapi.javcms_translate where complete = 1 and vod_id in ({$placeholders})",
                $ids
            );
        } catch (\Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $avdbId = (int) ($row->vod_id ?? 0);
            if ($avdbId <= 0 || ! isset($englishTitlesById[$avdbId])) {
                continue;
            }

            $titles = json_decode((string) ($row->titles ?? ''), true);
            $descs = json_decode((string) ($row->descs ?? ''), true);
            if (! is_array($titles)) {
                continue;
            }

            foreach ($locales as $locale) {
                $locale = $this->normalizeLocale($locale);
                if (! $locale || $locale === 'en') {
                    continue;
                }

                $title = $this->cleanText($titles[$locale] ?? '');
                if ($title === '') {
                    continue;
                }

                $translation = [
                    'title' => Str::limit($title, 497, ''),
                    'description' => $this->cleanDescription($descs[$locale] ?? ''),
                    'country' => '',
                    'type_name' => '',
                    'categories' => [],
                ];

                $englishTranslation = $englishTranslationsById[$avdbId] ?? ['title' => $englishTitlesById[$avdbId]];
                if (! empty($descs['en']) && empty($englishTranslation['description'])) {
                    $englishTranslation['description'] = $this->cleanDescription($descs['en']);
                }

                if (
                    $this->requireExactTranslationTitles
                    && $this->sameCleanText($translation['title'], $englishTitlesById[$avdbId])
                    && ! $this->translationHasLocalizedMetadata($translation, $englishTranslation)
                    && ! $this->titleCanRemainUnchangedAcrossLocales($translation['title'], $translation, $englishTranslation)
                ) {
                    continue;
                }

                $result[$avdbId][$locale] = $translation;
            }
        }

        return $result;
    }

    private function localAvdbApiTranslationsAvailable(): bool
    {
        if ($this->localAvdbApiTranslationsAvailable !== null) {
            return $this->localAvdbApiTranslationsAvailable;
        }

        try {
            $row = DB::selectOne(
                "select count(*) as found from information_schema.tables where table_schema = 'avdbapi' and table_name = 'javcms_translate'"
            );
            $this->localAvdbApiTranslationsAvailable = (int) ($row->found ?? 0) > 0;
        } catch (\Throwable) {
            $this->localAvdbApiTranslationsAvailable = false;
        }

        return $this->localAvdbApiTranslationsAvailable;
    }

    private function fetchTranslation(int $avdbId, string $locale, string $englishTitle, ?array $englishTranslation = null): ?array
    {
        $locale = $this->normalizeLocale($locale);
        if (! $locale || $locale === 'en') {
            return null;
        }

        $data = $this->apiGet($this->apiBaseForLocale($locale), [
            'ac' => 'detail',
            'ids' => $avdbId,
        ], self::TRANSLATION_HTTP_FALLBACK_TIMEOUT_SECONDS);

        $item = $data['list'][0] ?? null;
        if (! is_array($item)) {
            return null;
        }

        return $this->translationFromItem($item, $englishTitle, $locale, $englishTranslation);
    }

    private function translationFromItem(array $item, ?string $englishTitle = null, ?string $locale = null, ?array $englishTranslation = null): ?array
    {
        $title = $this->cleanText($item['name'] ?? '');
        if ($title === '') {
            return null;
        }

        $translation = [
            'title' => Str::limit($title, 497, ''),
            'description' => $this->cleanDescription($item['description'] ?? ''),
            'country' => $this->cleanText($this->arrayToString($item['country'] ?? '')),
            'type_name' => $this->cleanText($this->arrayToString($item['type_name'] ?? '')),
            'categories' => $this->cleanList($item['category'] ?? []),
        ];

        $locale = $this->normalizeLocale($locale);
        $englishTranslation = $englishTranslation ?: ($englishTitle !== null ? ['title' => $englishTitle] : null);
        if (
            $this->requireExactTranslationTitles
            && $englishTitle !== null
            && $locale !== null
            && $locale !== 'en'
            && $this->sameCleanText($translation['title'], $englishTitle)
            && ! $this->translationHasLocalizedMetadata($translation, $englishTranslation)
            && ! $this->titleCanRemainUnchangedAcrossLocales($translation['title'], $translation, $englishTranslation)
        ) {
            return null;
        }

        return $translation;
    }

    private function translationHasLocalizedMetadata(array $translation, ?array $englishTranslation): bool
    {
        if ($englishTranslation === null) {
            return false;
        }

        foreach (['description', 'country', 'type_name'] as $field) {
            $localized = $this->cleanText($translation[$field] ?? '');
            if ($localized === '') {
                continue;
            }

            $english = $this->cleanText($englishTranslation[$field] ?? '');
            if ($english === '' || ! $this->sameCleanText($localized, $english)) {
                return true;
            }
        }

        $localizedCategories = $this->comparableList($translation['categories'] ?? []);
        if ($localizedCategories === []) {
            return false;
        }

        $englishCategories = $this->comparableList($englishTranslation['categories'] ?? []);

        return $englishCategories === [] || $localizedCategories !== $englishCategories;
    }

    private function comparableList(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];
        $values = array_values(array_unique(array_filter(array_map(
            fn ($value) => $this->comparableText($value),
            $values
        ))));

        sort($values);

        return $values;
    }

    private function titleCanRemainUnchangedAcrossLocales(string $title, ?array $translation = null, ?array $englishTranslation = null): bool
    {
        $title = trim($title);
        if ($title === '') {
            return false;
        }

        if ($this->translationLooksLikeHentaiTitle($translation) || $this->translationLooksLikeHentaiTitle($englishTranslation)) {
            return true;
        }

        if (preg_match('/\b(?:FC2[-_\s]*(?:PPV[-_\s]*)?)?\d{5,}\b/i', $title)) {
            return true;
        }

        if (preg_match('/\b[A-Z]{2,12}[-_\s]?\d{2,6}(?:[-_\s]?[A-Z0-9]{1,8})?\b/i', $title)) {
            return true;
        }

        if (preg_match('/\b\d{2,6}[-_\s]?[A-Z]{2,12}[-_\s]?\d{1,6}\b/i', $title)) {
            return true;
        }

        if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\'._-]*(?:\s+[\p{L}\p{N}][\p{L}\p{N}\'._-]*){0,3}$/u', $title)) {
            return preg_match('/\d/u', $title) === 1;
        }

        return false;
    }

    private function translationLooksLikeHentaiTitle(?array $translation): bool
    {
        if ($translation === null) {
            return false;
        }

        foreach (['type_name', 'country'] as $field) {
            $value = $this->comparableText($translation[$field] ?? '');
            if (str_contains($value, 'hentai')) {
                return true;
            }
        }

        foreach ($this->comparableList($translation['categories'] ?? []) as $category) {
            if (str_contains($category, 'hentai')) {
                return true;
            }
        }

        return false;
    }

    private function sameCleanText(mixed $left, mixed $right): bool
    {
        $left = $this->comparableText($left);
        $right = $this->comparableText($right);

        return $left !== '' && $right !== '' && hash_equals($left, $right);
    }

    private function comparableText(mixed $value): string
    {
        $value = $this->cleanText($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::lower(trim($value));
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = $this->cleanText($value);

        return $value === '' ? null : $value;
    }

    private function translationsAreComplete(array $translations, array $locales): bool
    {
        return $this->translationsHaveRequiredLocaleTitles($translations, $locales);
    }

    private function translationsHaveRequiredLocaleTitles(array $translations, array $locales): bool
    {
        foreach ($locales as $locale) {
            $locale = $this->normalizeLocale($locale);
            if (! $locale || empty($translations[$locale]['title'])) {
                return false;
            }
        }

        return true;
    }

    private function saveTranslations(Video $video, array $translations, ?string $forcedTitle = null): bool
    {
        $forcedTitle = $forcedTitle !== null ? Str::limit($this->cleanText($forcedTitle), 497, '') : null;
        $now = now();
        $rows = [];

        foreach ($translations as $locale => $translation) {
            $title = $forcedTitle ?: ($translation['title'] ?? '');
            if ($title === '') {
                continue;
            }

            $rows[] = [
                'video_id' => $video->id,
                'locale' => $locale,
                'title' => $title,
                'description' => $translation['description'] ?: null,
                'country' => $translation['country'] ?: null,
                'type_name' => $translation['type_name'] ?: null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return false;
        }

        $existingRows = DB::table('video_translations')
            ->where('video_id', $video->id)
            ->whereIn('locale', array_column($rows, 'locale'))
            ->get()
            ->keyBy('locale');

        $changedRows = array_values(array_filter($rows, function (array $row) use ($existingRows): bool {
            $existing = $existingRows->get($row['locale']);
            if (! $existing) {
                return true;
            }

            foreach (['title', 'description', 'country', 'type_name'] as $field) {
                if ($this->nullableText($row[$field] ?? null) !== $this->nullableText($existing->{$field} ?? null)) {
                    return true;
                }
            }

            return false;
        }));

        if ($changedRows === []) {
            return false;
        }

        DB::table('video_translations')->upsert(
            $changedRows,
            ['video_id', 'locale'],
            ['title', 'description', 'country', 'type_name', 'updated_at']
        );

        return true;
    }

    private function downloadThumbnail(Video $video, array $item): void
    {
        $posterUrl = $this->posterUrlFromItem($item);
        if (! $posterUrl) {
            return;
        }

        try {
            $response = Http::timeout(10)->withOptions(['verify' => false])->get($posterUrl);
            if (! $response->successful() || $response->body() === '') {
                return;
            }

            $filename = (string) Str::uuid().'.jpg';
            Storage::disk('local')->put(Video::THUMBNAIL_FOLDER.'/'.$filename, $response->body());

            Thumbnail::create([
                'video_id' => $video->id,
                'file' => $filename,
                'is_active' => true,
                'status' => ThumbnailStatus::GENERATED->value,
            ]);

            $this->thumbsDownloaded++;
        } catch (\Throwable $e) {
            $this->write("Thumbnail failed for video {$video->id}: ".Str::limit($e->getMessage(), 120));
        }
    }

    private function parseTags(Video $video, array $item, bool $replace = false): bool
    {
        $tagNames = $this->extractTagNames($item);
        if ($tagNames === []) {
            return false;
        }

        $tagIds = [];
        foreach ($tagNames as $name) {
            $slug = Str::slug($name);
            if (! $slug) {
                continue;
            }

            $tag = Tag::firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'usage_count' => 0]
            );

            if ($tag->wasRecentlyCreated) {
                $this->tagsCreated++;
            }

            $tagIds[] = $tag->id;
        }

        if ($tagIds === []) {
            return false;
        }

        $tagIds = array_values(array_unique($tagIds));
        $existingTagIds = $video->tags()
            ->pluck('tags.id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $newTagIds = array_values(array_diff($tagIds, $existingTagIds));
        $removedTagIds = $replace ? array_values(array_diff($existingTagIds, $tagIds)) : [];

        if ($replace) {
            $video->tags()->sync($tagIds);
        } else {
            $video->tags()->syncWithoutDetaching($tagIds);
        }

        if ($newTagIds !== []) {
            Tag::whereIn('id', $newTagIds)->increment('usage_count');
        }

        if ($removedTagIds !== []) {
            Tag::whereIn('id', $removedTagIds)
                ->where('usage_count', '>', 0)
                ->decrement('usage_count');
        }

        return $newTagIds !== [] || $removedTagIds !== [];
    }

    private function existingVideoNeedsTagRefresh(Video $video, array $item): bool
    {
        $desiredSlugs = collect($this->extractTagNames($item))
            ->map(fn ($name) => Str::slug($name))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($desiredSlugs->isEmpty()) {
            return false;
        }

        $currentSlugs = $video->tags()
            ->pluck('tags.slug')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return $desiredSlugs->diff($currentSlugs)->isNotEmpty()
            || $currentSlugs->diff($desiredSlugs)->isNotEmpty();
    }

    private function extractTagNames(array $item): array
    {
        $tagString = $item['tag'] ?? '';
        if (is_array($tagString)) {
            $tagString = implode(', ', $tagString);
        }

        if ($tagString === '' || $tagString === '----') {
            $catField = $item['category'] ?? '';
            $tagString = is_array($catField) ? implode(', ', $catField) : (string) $catField;
        }

        $tagString = trim((string) $tagString);
        if ($tagString === '' || $tagString === '----') {
            return [];
        }

        $tagNames = preg_split('/\s*,\s*|\s*\|\s*/', $tagString) ?: [];

        return array_values(array_unique(array_filter(array_map(
            fn ($tag) => $this->cleanText($tag),
            $tagNames
        ), fn ($tag) => strlen($tag) > 1 && strlen($tag) < 50)));
    }

    private function saveTagTranslations(array $englishItem, array $translations): bool
    {
        if ($translations === [] || ! DB::getSchemaBuilder()->hasTable('tag_translations')) {
            return false;
        }

        $baseNames = $this->extractTagNames($englishItem);
        if ($baseNames === []) {
            return false;
        }

        $baseTags = Tag::whereIn('slug', array_map(fn ($name) => Str::slug($name), $baseNames))
            ->get()
            ->keyBy('slug');

        $changed = false;
        foreach ($translations as $locale => $translation) {
            if ($locale === 'en' || empty($translation['categories']) || ! is_array($translation['categories'])) {
                continue;
            }

            foreach ($baseNames as $index => $baseName) {
                $localizedName = $this->cleanText($translation['categories'][$index] ?? '');
                if ($localizedName === '' || $localizedName === $baseName) {
                    continue;
                }

                $tag = $baseTags->get(Str::slug($baseName));
                if (! $tag) {
                    continue;
                }

                $tagTranslation = TagTranslation::updateOrCreate(
                    ['tag_id' => $tag->id, 'locale' => $locale],
                    ['name' => Str::limit($localizedName, 150, '')]
                );
                $changed = $tagTranslation->wasRecentlyCreated || $tagTranslation->wasChanged('name') || $changed;
            }
        }

        return $changed;
    }

    private function apiGet(string $baseUrl, array $params, int $timeout = 25): ?array
    {
        try {
            $response = Http::timeout($timeout)
                ->retry(2, 500)
                ->withOptions(['verify' => false])
                ->get($baseUrl, $params);

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function apiParams(array $filters, array $params): array
    {
        return array_filter(array_merge($filters, $params), fn ($value) => $value !== null && $value !== '');
    }

    private function fetchLogicalCategoryPageChunks(
        string $listApiBase,
        int $catId,
        int $logicalPage,
        int $requestedPageSize,
        int $apiPageSize,
        array $apiFilters
    ): array {
        $requestedPageSize = max(1, $requestedPageSize);
        $apiPageSize = max(1, min(self::AVDB_API_PAGE_SIZE, $apiPageSize));
        $offset = max(0, ($logicalPage - 1) * $requestedPageSize);
        $apiPage = intdiv($offset, $apiPageSize) + 1;
        $offsetInFirstPage = $offset % $apiPageSize;
        $remaining = $requestedPageSize;
        $chunks = [];

        while ($remaining > 0) {
            $data = $this->apiGet($listApiBase, $this->apiParams($apiFilters, [
                'ac' => 'detail',
                't' => $catId,
                'pg' => $apiPage,
                'pagesize' => $apiPageSize,
            ]));

            if (! $data || empty($data['list']) || ! is_array($data['list'])) {
                break;
            }

            $apiItems = array_values($data['list']);
            $available = count($apiItems);
            if ($offsetInFirstPage > 0) {
                $apiItems = array_slice($apiItems, $offsetInFirstPage);
                $offsetInFirstPage = 0;
            }

            $items = array_slice($apiItems, 0, $remaining);
            if ($items === []) {
                break;
            }

            $chunks[] = [
                'api_page' => $apiPage,
                'items' => $items,
            ];

            $remaining -= count($items);
            if ($available < $apiPageSize) {
                break;
            }

            $apiPage++;
        }

        return $chunks;
    }

    private function estimatePlannedCategoryPages(array $categories, string $listApiBase, int $startPage, int $maxPages, int $requestedPageSize, array $apiFilters): int
    {
        $planned = 0;
        $requestedPageSize = max(1, min(self::UI_MAX_PAGE_SIZE, $requestedPageSize));

        foreach ($categories as $catId) {
            if ($this->shouldCancel()) {
                break;
            }

            $probe = $this->apiGet($listApiBase, $this->apiParams($apiFilters, [
                'ac' => 'detail',
                't' => (int) $catId,
                'pagesize' => 1,
            ]), 20);

            if (! $probe) {
                $planned += $maxPages;

                continue;
            }

            $totalVideos = max(0, (int) ($probe['total'] ?? 0));
            $totalPages = max(1, (int) ceil(max(1, $totalVideos) / $requestedPageSize));
            if ($startPage > $totalPages) {
                continue;
            }

            $planned += min($maxPages, $totalPages - $startPage + 1);
        }

        return max(1, $planned);
    }

    private function itemIdRangeLabel(array $items): string
    {
        $ids = array_values(array_filter(array_map(
            fn ($item) => (int) ($item['id'] ?? 0),
            $items
        ), fn ($id) => $id > 0));

        if ($ids === []) {
            return 'n/a';
        }

        return count($ids) === 1 ? (string) $ids[0] : reset($ids).'-'.end($ids);
    }

    private function resolveApiFilters(array $settings): array
    {
        $map = [
            'search_wd' => ['wd', 'wd'],
            'search_code' => ['code', 'code'],
            'search_actor' => ['actor', 'actor'],
            'search_director' => ['director', 'director'],
            'search_writer' => ['writer', 'writer'],
            'search_category' => ['category', 'filter-category'],
        ];

        $filters = [];
        foreach ($map as $settingKey => [$apiKey, $optionKey]) {
            $value = trim((string) ($settings[$settingKey] ?? $this->option($optionKey) ?? ''));
            if ($value !== '') {
                $filters[$apiKey] = Str::limit($value, 150, '');
            }
        }

        $hours = (int) ($settings['updated_hours'] ?? $this->option('updated-hours') ?: 0);
        if ($hours > 0) {
            $filters['h'] = min($hours, 8760);
        }

        $year = trim((string) ($settings['year'] ?? $this->option('year') ?? ''));
        if ($year !== '' && preg_match('/^\d{4}(-\d{4})?$/', $year)) {
            $filters['year'] = $year;
        }

        $sort = strtolower(trim((string) ($settings['sort_direction'] ?? $this->option('sort') ?: 'desc')));
        $filters['sort_direction'] = $sort === 'asc' ? 'asc' : 'desc';

        return $filters;
    }

    private function apiBaseForLocale(string $locale): string
    {
        $locale = $this->normalizeLocale($locale);
        if (! $locale || $locale === 'en') {
            return str_contains($this->apiBaseUrl, '{locale}')
                ? str_replace('{locale}', 'en', $this->apiBaseUrl)
                : $this->apiBaseUrl;
        }

        if (str_contains($this->apiBaseUrl, '{locale}')) {
            return str_replace('{locale}', $locale, $this->apiBaseUrl);
        }

        $parts = parse_url($this->apiBaseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'avdbapi.com';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '/api.php/provide/vod';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        if (str_starts_with($path, "/{$locale}/")) {
            return $this->apiBaseUrl;
        }

        $localizedPath = preg_match('#^/[a-z]{2,5}/api\.php/provide/vod$#i', $path)
            ? preg_replace('#^/[a-z]{2,5}/#i', "/{$locale}/", $path)
            : (str_starts_with($path, '/api.php/provide/vod') ? "/{$locale}{$path}" : $path);

        return "{$scheme}://{$host}{$port}{$localizedPath}{$query}";
    }

    private function normalizeApiBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return self::EN_API;
        }

        $validationUrl = str_replace('{locale}', 'en', $url);
        if (! filter_var($validationUrl, FILTER_VALIDATE_URL)) {
            return self::EN_API;
        }

        $scheme = strtolower((string) parse_url($validationUrl, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : self::EN_API;
    }

    private function resolveCategories(array $settings): array
    {
        $selected = $settings['categories'] ?? null;

        if (is_string($this->option('categories')) && $this->option('categories') !== '') {
            $selected = explode(',', (string) $this->option('categories'));
        }

        if ($selected === null) {
            $category = (string) ($this->option('category') ?: 'all');
            $selected = $category === 'all' ? array_keys($this->categoryMap) : [$category];
        }

        return array_values(array_filter(array_map('intval', (array) $selected), fn ($id) => isset($this->categoryMap[$id])));
    }

    private function resolveIds(array $settings): array
    {
        $raw = $settings['ids'] ?? $this->option('ids') ?? '';
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[\s,]+/', (string) $raw) ?: [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $parts), fn ($id) => $id > 0)));

        return array_slice($ids, 0, 200);
    }

    private function categoryIdFromItem(array $item): int
    {
        $typeId = (int) ($item['type_id'] ?? 0);
        if (isset($this->categoryMap[$typeId])) {
            return $typeId;
        }

        $typeName = strtolower($this->arrayToString($item['type_name'] ?? ''));

        return match (true) {
            str_contains($typeName, 'uncensored leaked') => 3,
            str_contains($typeName, 'english subtitle') => 7,
            str_contains($typeName, 'uncensored') => 2,
            str_contains($typeName, 'amateur') => 4,
            str_contains($typeName, 'chinese') => 5,
            str_contains($typeName, 'hentai') => 6,
            default => 1,
        };
    }

    private function resolveLocales(string $option): array
    {
        if ($option === '') {
            return self::ALL_LOCALES;
        }

        $locales = array_map(fn ($locale) => $this->normalizeLocale($locale), explode(',', $option));
        $locales = array_filter($locales, fn ($locale) => $locale && $locale !== 'en');

        return array_values(array_intersect(self::ALL_LOCALES, $locales)) ?: self::ALL_LOCALES;
    }

    private function resolveLanguageOption(array $settings): string
    {
        if (($settings['language_mode'] ?? null) === 'auto') {
            return 'auto';
        }

        $language = (string) ($settings['language'] ?? $this->option('language') ?: 'null');
        $language = strtolower(trim($language));

        if ($language === '' || $language === 'global') {
            return 'null';
        }

        return $language === 'auto' ? 'auto' : ($this->normalizeLocale($language) ?: 'null');
    }

    private function resolveStarterViewSettings(array $settings): array
    {
        $defaults = AvdbStarterViews::defaults();

        return AvdbStarterViews::normalize([
            'mode' => $settings['starter_views_mode'] ?? $this->option('starter-views') ?? $defaults['mode'],
            'min' => $settings['starter_views_min'] ?? $this->option('starter-min') ?? $defaults['min'],
            'max' => $settings['starter_views_max'] ?? $this->option('starter-max') ?? $defaults['max'],
            'spread_days' => $settings['starter_views_spread_days'] ?? $this->option('starter-spread-days') ?? $defaults['spread_days'],
            'daily_stats' => array_key_exists('starter_views_daily_stats', $settings)
                ? $settings['starter_views_daily_stats']
                : (bool) $this->option('starter-daily-stats'),
            'only_zero' => $settings['starter_views_only_zero'] ?? $defaults['only_zero'],
            'apply_existing_zero' => array_key_exists('starter_views_existing_zero', $settings)
                ? $settings['starter_views_existing_zero']
                : (bool) $this->option('starter-existing-zero'),
            'seed' => $settings['starter_views_seed'] ?? '',
        ]);
    }

    private function normalizeLocale(?string $locale): ?string
    {
        $locale = strtolower(trim((string) $locale));

        return preg_match('/^[a-z]{2,5}$/', $locale) ? $locale : null;
    }

    private function uniqueSlug(string $slug): string
    {
        $base = Str::limit(Str::slug($slug) ?: Str::random(12), 240, '');
        $slug = $base;
        $counter = 1;

        while (Video::withTrashed()->where('slug', $slug)->exists()) {
            $slug = Str::limit($base, 235, '').'-'.$counter++;
        }

        return $slug;
    }

    private function extractEmbedCode(array $item): ?string
    {
        return RemoteVideoDuration::playableLinkFromAvdbItem($item);
    }

    private function posterUrlFromItem(array $item): ?string
    {
        foreach (['poster_url', 'thumb_url', 'pic', 'vod_pic'] as $field) {
            $url = trim((string) ($item[$field] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }

    private function configureImportTimeline(array $ids, array $categories, int $maxPages, int $pageSize, string $sortDirection, ?string $seed = null, ?int $estimatedSlotsOverride = null): void
    {
        $estimatedSlots = $ids !== []
            ? count($ids)
            : ($estimatedSlotsOverride ?? max(1, count($categories) * $maxPages * $pageSize));

        $this->dateSequence = 0;
        $this->dateTotalSlots = max(1, $estimatedSlots);
        $this->dateSpreadDays = min(self::MAX_IMPORT_DATE_SPREAD_DAYS, max(0, $this->dateTotalSlots - 1));
        $this->dateSortDirection = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';
        $this->dateAnchor = now();
        $this->dateSeed = trim((string) $seed) ?: (string) Str::uuid().'-'.Str::random(16);
        $this->dateUsedTimestamps = [];
    }

    private function dateTimelineContext(): array
    {
        return [
            'sort' => $this->dateSortDirection,
            'estimated_slots' => $this->dateTotalSlots,
            'spread_days' => $this->dateSpreadDays,
            'anchor' => ($this->dateAnchor ?: now())->toDateTimeString(),
        ];
    }

    private function nextImportPublishedAt(int $avdbId): Carbon
    {
        $anchor = $this->dateAnchor ?: now();
        $index = $this->dateSequence++;
        $totalSlots = max(1, $this->dateTotalSlots);
        $totalSeconds = max(0, $this->dateSpreadDays * 86400);

        if ($totalSeconds <= 0 || $totalSlots <= 1) {
            $ageSeconds = $this->timelineHashInt($avdbId, $index, 'single') % max(1, $this->secondsSinceStartOfDay($anchor));
        } else {
            $position = min($index, $totalSlots - 1);
            $ratio = $totalSlots > 1 ? $position / ($totalSlots - 1) : 0.0;
            $baseAge = $this->dateSortDirection === 'asc'
                ? (int) round($totalSeconds * (1 - $ratio))
                : (int) round($totalSeconds * $ratio);
            $slotGap = $totalSlots > 1 ? max(1, (int) floor($totalSeconds / ($totalSlots - 1))) : 1;
            $jitterWindow = max(0, min(21600, (int) floor($slotGap / 4)));
            $jitter = $jitterWindow > 0
                ? $this->timelineHashInt($avdbId, $index, 'jitter') % ($jitterWindow + 1)
                : 0;
            $ageSeconds = max(0, $baseAge + $jitter);
        }

        $date = $anchor->copy()->subSeconds($ageSeconds);

        if ($date->greaterThan($anchor)) {
            $date = $anchor->copy();
        }

        while (isset($this->dateUsedTimestamps[$date->timestamp])) {
            $date = $this->dateSortDirection === 'asc'
                ? $date->copy()->addSecond()
                : $date->copy()->subSecond();

            if ($date->greaterThan($anchor)) {
                $date = $anchor->copy()->subSecond();
            }
        }

        $this->dateUsedTimestamps[$date->timestamp] = true;

        return $date;
    }

    private function timelineHashInt(int $avdbId, int $index, string $purpose): int
    {
        $material = implode('|', [
            config('app.url'),
            config('app.key'),
            gethostname() ?: '',
            $this->dateSeed,
            $this->dateSortDirection,
            $purpose,
            $avdbId,
            $index,
        ]);

        return (int) hexdec(substr(hash('sha256', $material), 0, 12));
    }

    private function secondsSinceStartOfDay(Carbon $date): int
    {
        return ((int) $date->format('H') * 3600) + ((int) $date->format('i') * 60) + (int) $date->format('s');
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d{10,13}$/', trim($value)))) {
            $timestamp = (int) $value;
            if ($timestamp > 9999999999) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            try {
                return Carbon::createFromTimestamp($timestamp);
            } catch (\Throwable $e) {
                return null;
            }
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse(str_replace('/', '-', $value));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function mapLanguage(string $country): string
    {
        $country = strtolower($country);

        return match (true) {
            str_contains($country, 'china'), str_contains($country, 'chinese') => 'zh',
            str_contains($country, 'korea') => 'ko',
            str_contains($country, 'vietnam') => 'vi',
            str_contains($country, 'thai') => 'th',
            str_contains($country, 'english'), str_contains($country, 'usa') => 'en',
            default => 'ja',
        };
    }

    private function titleFromItem(array $item): string
    {
        $name = $this->cleanText($item['name'] ?? '');

        if ($this->useMovieCodeTitle) {
            $movieCode = $this->movieCodeFromItem($item);
            if ($movieCode !== '') {
                return $movieCode;
            }
        }

        return $name;
    }

    private function movieCodeFromItem(array $item): string
    {
        return $this->cleanText($item['movie_code'] ?? $item['vod_en'] ?? '');
    }

    private function metadataFromItem(array $item, ?int $catId = null, bool $includeTags = true): array
    {
        $catId ??= $this->categoryIdFromItem($item);
        $publishedAt = $this->parseDate($item['vod_pubdate'] ?? null);
        $updatedAt = $this->avdbUpdatedAtFromItem($item);

        return [
            'avdb_code' => $this->metadataValue($this->movieCodeFromItem($item), 100),
            'avdb_actor' => $this->metadataValue($item['actor'] ?? '', 500),
            'avdb_director' => $this->metadataValue($item['director'] ?? '', 255),
            'avdb_writer' => $this->metadataValue($item['writer'] ?? '', 255),
            'avdb_country' => $this->metadataValue($item['country'] ?? '', 100),
            'avdb_release_date' => $publishedAt?->toDateString(),
            'avdb_updated_at' => $updatedAt?->toDateTimeString(),
            'avdb_content_hash' => $this->avdbContentHashFromItem($item, $catId, $includeTags),
        ];
    }

    private function avdbContentHashFromItem(array $item, int $catId, bool $includeTags = true): string
    {
        $publishedAt = $this->parseDate($item['vod_pubdate'] ?? null);
        $updatedAt = $this->avdbUpdatedAtFromItem($item);

        $payload = [
            'title' => $this->titleFromItem($item),
            'name' => $this->cleanText($item['name'] ?? ''),
            'description' => $this->cleanDescription($item['description'] ?? ''),
            'movie_code' => $this->movieCodeFromItem($item),
            'actor' => $this->normalizedHashList($item['actor'] ?? ''),
            'director' => $this->normalizedHashList($item['director'] ?? ''),
            'writer' => $this->normalizedHashList($item['writer'] ?? ''),
            'country' => $this->normalizedHashList($item['country'] ?? ''),
            'type_id' => $catId,
            'type_name' => $this->cleanText($item['type_name'] ?? ''),
            'category' => $this->normalizedHashList($item['category'] ?? ''),
            'poster_url' => $this->posterUrlFromItem($item) ?? '',
            'embed_code' => $this->extractEmbedCode($item) ?? '',
            'duration' => $this->durationFromItem($item) ?? 0,
            'release_date' => $publishedAt?->toDateString() ?? '',
            'updated_at' => $updatedAt?->toDateTimeString() ?? '',
        ];

        if ($includeTags) {
            $tags = $this->extractTagNames($item);
            natcasesort($tags);
            $payload['tags'] = array_values($tags);
        }

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function avdbUpdatedAtFromItem(array $item): ?Carbon
    {
        foreach (['vod_time', 'updated_at', 'update_time', 'vod_update_time', 'last_update'] as $field) {
            $date = $this->parseDate($item[$field] ?? null);
            if ($date) {
                return $date->seconds(0);
            }
        }

        return null;
    }

    private function durationFromItem(array $item): ?int
    {
        return RemoteVideoDuration::fromDurationFields($item);
    }

    private function normalizedHashList(mixed $value): array
    {
        $items = $this->cleanList($value);
        natcasesort($items);

        return array_values($items);
    }

    private function metadataValue(mixed $value, int $limit): ?string
    {
        $value = $this->cleanText($value);
        if ($value === '' || in_array($value, ['----', 'Updating', 'N/A', 'Unknown'], true)) {
            return null;
        }

        return Str::limit($value, $limit, '');
    }

    private function arrayToString(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_filter(array_map(fn ($v) => trim((string) $v), $value)));
        }

        return trim((string) $value);
    }

    private function cleanText(mixed $value): string
    {
        return trim(html_entity_decode(strip_tags($this->arrayToString($value)), ENT_QUOTES, 'UTF-8'));
    }

    private function cleanList(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/\s*,\s*|\s*\|\s*/', (string) $value);

        return array_values(array_filter(array_map(
            fn ($item) => $this->cleanText($item),
            $items ?: []
        ), fn ($item) => $item !== '' && $item !== '----'));
    }

    private function cleanDescription(mixed $value): string
    {
        return $this->cleanText($value);
    }

    private function sleepMs(int $delay): void
    {
        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }

    private function loadRunSettings(): array
    {
        $runId = (int) ($this->option('run-id') ?: 0);
        if ($runId <= 0) {
            return [];
        }

        $this->run = $this->retryImportRunDb(fn () => AvdbImportRun::find($runId));
        if (! $this->run) {
            $this->error("Import run {$runId} not found.");

            return [];
        }

        return $this->run->settings ?? [];
    }

    private function counterSnapshot(): array
    {
        return [
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'updated' => $this->updated,
            'lang_skipped' => $this->langSkipped,
            'failed' => $this->failed,
            'thumbs_downloaded' => $this->thumbsDownloaded,
            'tags_created' => $this->tagsCreated,
        ];
    }

    private function counterDelta(array $before): array
    {
        $after = $this->counterSnapshot();
        foreach ($after as $key => $value) {
            $after[$key] = $value - (int) ($before[$key] ?? 0);
        }

        return $after;
    }

    private function formatCounterDelta(array $delta): string
    {
        return sprintf(
            'imported +%d, updated +%d, skipped +%d, no-lang +%d, failed +%d, thumbs +%d, new-tags +%d',
            $delta['imported'] ?? 0,
            $delta['updated'] ?? 0,
            $delta['skipped'] ?? 0,
            $delta['lang_skipped'] ?? 0,
            $delta['failed'] ?? 0,
            $delta['thumbs_downloaded'] ?? 0,
            $delta['tags_created'] ?? 0
        );
    }

    private function newPageIssues(): array
    {
        return [
            'counts' => [],
            'examples' => [],
        ];
    }

    private function recordPageIssue(?array &$issues, string $type, int $avdbId, string $title, string $reason, ?string $link = null): void
    {
        if ($issues === null) {
            return;
        }

        $type = strtolower($type);
        $reason = trim($reason) !== '' ? trim($reason) : 'unknown';
        $key = "{$type}: {$reason}";
        $issues['counts'][$key] = (int) ($issues['counts'][$key] ?? 0) + 1;

        $exportTitle = Str::limit($this->cleanText($title), 250, '');
        $title = Str::limit($exportTitle, 90, '');
        $label = $avdbId > 0 ? "AVDB {$avdbId}" : 'API page';
        if ($title !== '') {
            $label .= " - {$title}";
        }

        $line = strtoupper($type).": {$label} ({$reason})";
        if ($link) {
            $line .= " | {$link}";
        }

        $this->appendIssueExport($type, $avdbId, $exportTitle, $reason, $link);

        if (count($issues['examples']) >= self::PAGE_ISSUE_EXAMPLE_LIMIT) {
            return;
        }

        $issues['examples'][] = $line;
    }

    private function safeVideoRoute(?Video $video, ?string $fallback = null): ?string
    {
        if (! $video) {
            return $fallback;
        }

        try {
            $route = $video->route ?? null;
            if (is_string($route) && $route !== '') {
                return $route;
            }
        } catch (\Throwable) {
            // Route generation can lazy-load relations. Import logging must not
            // fail an item when the database briefly refuses that extra read.
        }

        $uuid = trim((string) ($video->uuid ?? ''));
        if ($uuid === '') {
            return $fallback;
        }

        $slug = trim((string) ($video->slug ?? ''));
        if ($slug === '') {
            $rawTitle = method_exists($video, 'getRawOriginal') ? (string) $video->getRawOriginal('title') : '';
            $slug = Str::slug($rawTitle) ?: 'video';
        }

        try {
            return url('/video/'.trim($slug, '-').'-'.$uuid);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function appendIssueExport(string $type, int $avdbId, string $title, string $reason, ?string $link = null): void
    {
        if (! $this->run) {
            return;
        }

        $bucket = $this->issueExportBucket($type, $reason);
        if ($bucket === null) {
            return;
        }

        $row = [
            now()->toDateTimeString(),
            $type,
            (string) $avdbId,
            $title,
            $reason,
            (string) $link,
            (string) ($this->run->current_category ?? ''),
            (string) ($this->run->current_page ?? ''),
        ];

        $line = implode("\t", array_map(fn ($value) => $this->issueExportCell($value), $row))."\n";
        foreach (array_unique([$bucket, 'all']) as $target) {
            $path = $this->issueExportPath($target);
            if (! is_file($path)) {
                @file_put_contents($path, self::ISSUE_EXPORT_HEADER, LOCK_EX);
            }
            @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        }
    }

    private function issueExportBucket(string $type, string $reason): ?string
    {
        if (in_array($type, ['failed', 'broken'], true)) {
            return 'failed';
        }

        if ($type === 'skipped' && str_starts_with(strtolower($reason), 'missing ')) {
            return 'no-full-lang';
        }

        return null;
    }

    private function issueExportPath(string $bucket): string
    {
        $runId = $this->run?->id ?: 'manual';

        return storage_path("logs/avdb-import-{$runId}-{$bucket}.tsv");
    }

    private function issueExportCell(mixed $value): string
    {
        return str_replace(["\t", "\r", "\n"], [' ', ' ', ' '], trim((string) $value));
    }

    private function writeIssueSummary(array $issues): void
    {
        if (($issues['counts'] ?? []) === []) {
            return;
        }

        $summary = collect($issues['counts'])
            ->map(fn ($count, $reason) => "{$reason}={$count}")
            ->values()
            ->implode('; ');

        $this->write("Issue summary: {$summary}");

        foreach (($issues['examples'] ?? []) as $example) {
            $this->write(" - {$example}");
        }
    }

    private function formatSeconds(float|int $seconds): string
    {
        $seconds = max(0, (float) $seconds);
        if ($seconds < 60) {
            return number_format($seconds, 1).'s';
        }

        $minutes = (int) floor($seconds / 60);
        $remaining = (int) round($seconds % 60);

        return "{$minutes}m {$remaining}s";
    }

    private function memorySafeTranslationLocaleConcurrency(int $requested): int
    {
        $requested = max(1, min(16, $requested));
        $bytes = $this->phpMemoryLimitBytes();
        if ($bytes === null) {
            return $requested;
        }

        $mb = 1024 * 1024;
        $cap = match (true) {
            $bytes <= 192 * $mb => 1,
            $bytes <= 384 * $mb => 2,
            $bytes <= 768 * $mb => 4,
            default => 16,
        };

        return max(1, min($requested, $cap));
    }

    private function raiseImportMemoryLimit(): void
    {
        $bytes = $this->phpMemoryLimitBytes();
        if ($bytes === null || $bytes >= 512 * 1024 * 1024) {
            return;
        }

        @ini_set('memory_limit', '512M');
        if (function_exists('gc_enable')) {
            @gc_enable();
        }
    }

    private function collectGarbage(): void
    {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    private function ensureDatabaseConnection(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            try {
                DB::reconnect();
            } catch (\Throwable $re) {
                // Ignore reconnection failure
            }
        }
    }

    private function phpMemoryLimitBytes(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return null;
        }

        if (! preg_match('/^(\d+)([kmgt])?$/i', $value, $matches)) {
            return null;
        }

        $bytes = (int) $matches[1];
        $unit = strtolower($matches[2] ?? '');

        return match ($unit) {
            't' => $bytes * 1024 * 1024 * 1024 * 1024,
            'g' => $bytes * 1024 * 1024 * 1024,
            'm' => $bytes * 1024 * 1024,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }

    private function markRunRunning(array $context): void
    {
        $message = 'Import started.';
        $this->write($message);

        if (! $this->run) {
            return;
        }

        $this->saveImportRun(fn () => $this->run->forceFill([
            'status' => 'running',
            'started_at' => $this->run->started_at ?: now(),
            'message' => $message,
        ])->save());

        $categories = implode(',', $context['categories'] ?? []);
        $filters = array_filter((array) ($context['apiFilters'] ?? []), fn ($value) => $value !== null && $value !== '');
        $requestedPageSize = (int) ($context['requestedPageSize'] ?? $context['pageSize'] ?? self::AVDB_API_PAGE_SIZE);
        $actualPageSize = (int) ($context['pageSize'] ?? self::AVDB_API_PAGE_SIZE);
        $pageSizeLabel = $requestedPageSize !== $actualPageSize
            ? "{$actualPageSize} actual / {$requestedPageSize} requested"
            : (string) $actualPageSize;

        $this->write('Plan: categories='.($categories !== '' ? $categories : 'direct IDs')
            .', pages='.($context['maxPages'] ?? 1)
            .', start='.($context['startPage'] ?? 1)
            .', page_size='.$pageSizeLabel
            .', API='.Str::limit((string) ($context['apiBaseUrl'] ?? $this->apiBaseUrl), 120, ''));
        $this->write('Mode: thumbnails='.($context['thumbnailMode'] ?? 'remote')
            .', full_langs='.((bool) ($context['fullLangs'] ?? false) ? 'yes' : 'no')
            .', verify_full_langs='.((bool) ($context['verifyFullLangs'] ?? false) ? 'yes' : 'no')
            .', api_lang='.($context['apiLang'] ?? 'en')
            .', translated_only='.((bool) ($context['translatedOnly'] ?? false) ? 'yes' : 'no')
            .', creator_assignment='.((bool) ($context['creatorAssignmentEnabled'] ?? true) ? 'yes' : 'no')
            .', visibility='.($context['languageOpt'] ?? 'null')
            .', filters='.($filters === [] ? 'none' : json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        $requestedConcurrency = (int) ($context['requestedTranslationConcurrency'] ?? $context['translationConcurrency'] ?? self::TRANSLATION_LOCALE_CONCURRENCY);
        $actualConcurrency = (int) ($context['translationConcurrency'] ?? self::TRANSLATION_LOCALE_CONCURRENCY);
        $this->write('Translation batching: id_chunk='.($context['translationChunk'] ?? self::TRANSLATION_ID_CHUNK_SIZE)
            .', locale_concurrency='.$actualConcurrency
            .($actualConcurrency < $requestedConcurrency ? " (auto-capped from {$requestedConcurrency} for PHP memory_limit)" : ''));
        $sortDirection = strtolower((string) ($filters['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $this->write('Source order: '.($sortDirection === 'asc' ? 'oldest first' : 'newest first')." (API sort_direction={$sortDirection})");
        $starterViews = (array) ($context['starterViews'] ?? []);
        if (($starterViews['mode'] ?? AvdbStarterViews::MODE_DISABLED) !== AvdbStarterViews::MODE_DISABLED) {
            $this->write('Starter views: mode='.($starterViews['mode'] ?? 'disabled')
                .', range='.($starterViews['min'] ?? 0).'-'.($starterViews['max'] ?? 0)
                .', daily_stats='.((bool) ($starterViews['daily_stats'] ?? false) ? 'yes' : 'no')
                .', existing_zero='.((bool) ($starterViews['apply_existing_zero'] ?? false) ? 'yes' : 'no'));
        }
    }

    private function shouldCancel(): bool
    {
        if (! $this->run) {
            return false;
        }

        $cancelRequested = $this->retryImportRunDb(
            fn () => AvdbImportRun::query()
                ->whereKey($this->run?->getKey())
                ->value('cancel_requested'),
            false,
            false
        );

        // If the admin deletes/cleans the run row while this process is still
        // alive, stop importing instead of becoming an invisible orphan.
        return $cancelRequested === null || (bool) $cancelRequested;
    }

    private function syncRunCounters(string $message, array $extra = []): void
    {
        $progress = $this->plannedPages > 0
            ? min(99, (int) floor(($this->completedPages / $this->plannedPages) * 100))
            : 0;

        $attrs = array_merge([
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'updated' => $this->updated,
            'failed' => $this->failed,
            'lang_skipped' => $this->langSkipped,
            'tags_created' => $this->tagsCreated,
            'thumbs_downloaded' => $this->thumbsDownloaded,
            'total_pages' => $this->plannedPages,
            'completed_pages' => $this->completedPages,
            'progress' => $progress,
            'message' => $message,
        ], $extra);

        if ($this->run) {
            $this->saveImportRun(fn () => $this->run->forceFill($attrs)->save());
        }
    }

    private function finishRun(string $status, string $message): void
    {
        if (! $this->dryRunActive) {
            SeoSitemapCache::clear();
        }

        if ($this->run && $this->run->started_at) {
            $message .= ' Runtime: '.$this->formatSeconds(abs($this->run->started_at->diffInSeconds(now())));
        }

        $this->syncRunCounters($message, [
            'status' => $status,
            'progress' => in_array($status, ['completed', 'cancelled'], true) ? 100 : 0,
            'finished_at' => now(),
        ]);
        $this->write($message);

        if ($status === 'completed' && ! $this->dryRunActive) {
            $this->queueDurationBackfillAfterImport();
        }

    }

    private function queueDurationBackfillAfterImport(): void
    {
        try {
            $result = AvdbDurationBackfill::start(10000, 100);
        } catch (\Throwable $e) {
            $this->write('Duration backfill start failed: '.Str::limit($e->getMessage(), 250, ''));

            return;
        }

        $this->write($result['message']);
    }

    private function maybeStartDurationBackfillDuringImport(bool $dryRun): void
    {
        if ($dryRun || $this->imported <= 0) {
            return;
        }

        if (($this->imported - $this->lastDurationBackfillImportedCount) < self::DURATION_BACKFILL_IMPORT_INTERVAL) {
            return;
        }

        if ($this->lastDurationBackfillStartedAt > 0 && (time() - $this->lastDurationBackfillStartedAt) < self::DURATION_BACKFILL_THROTTLE_SECONDS) {
            return;
        }

        $this->lastDurationBackfillImportedCount = $this->imported;
        $this->lastDurationBackfillStartedAt = time();

        try {
            $result = AvdbDurationBackfill::start(10000, 100);
        } catch (\Throwable $e) {
            $this->write('Duration backfill check failed: '.Str::limit($e->getMessage(), 250, ''));

            return;
        }

        $this->write('Duration backfill check: '.$result['message']);
    }

    private function failRun(string $message): void
    {
        if ($this->run) {
            $this->saveImportRun(fn () => $this->run->forceFill([
                'status' => 'failed',
                'message' => $message,
                'finished_at' => now(),
            ])->save());
            $this->appendRunLogSafely('Failed: '.$message);
        }

        $this->error($message);
    }

    private function write(string $message): void
    {
        $this->line($message);
        if ($this->run) {
            $this->appendRunLogSafely($message);
        }
    }

    private function saveImportRun(callable $callback): void
    {
        $this->retryImportRunDb($callback, null, false);
    }

    private function appendRunLogSafely(string $message): void
    {
        $this->retryImportRunDb(fn () => $this->run?->appendLog($message), null, false);
    }

    private function retryImportRunDb(callable $callback, mixed $fallback = null, bool $throw = true): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $callback();
            } catch (QueryException $e) {
                $attempt++;

                if ($attempt >= self::IMPORT_RUN_DB_RETRY_ATTEMPTS || ! $this->isTransientDatabaseError($e)) {
                    if ($throw) {
                        throw $e;
                    }

                    $this->recordImportRunDbWarning($e);

                    return $fallback;
                }

                $this->reconnectImportRunDatabase();
                usleep(self::IMPORT_RUN_DB_RETRY_SLEEP_US * $attempt);
            }
        }
    }

    private function retryImportItemDb(callable $callback, int $avdbId, string $title = ''): mixed
    {
        $attempt = 0;

        while (true) {
            $counterSnapshot = $this->counterSnapshot();

            try {
                return $callback();
            } catch (QueryException $e) {
                $attempt++;

                if ($attempt >= self::IMPORT_ITEM_DB_RETRY_ATTEMPTS || ! $this->isTransientDatabaseError($e)) {
                    throw $e;
                }

                $this->restoreCounterSnapshot($counterSnapshot);
                $this->reconnectImportRunDatabase();

                $label = $avdbId > 0 ? "AVDB {$avdbId}" : 'AVDB item';
                if ($title !== '') {
                    $label .= ' - '.Str::limit($title, 60, '');
                }

                $this->write(
                    "{$label}: transient database/socket error; retry "
                    .($attempt + 1).'/'.self::IMPORT_ITEM_DB_RETRY_ATTEMPTS
                );

                usleep(self::IMPORT_ITEM_DB_RETRY_SLEEP_US * $attempt);
            }
        }
    }

    private function restoreCounterSnapshot(array $snapshot): void
    {
        $this->imported = (int) ($snapshot['imported'] ?? $this->imported);
        $this->skipped = (int) ($snapshot['skipped'] ?? $this->skipped);
        $this->updated = (int) ($snapshot['updated'] ?? $this->updated);
        $this->langSkipped = (int) ($snapshot['lang_skipped'] ?? $this->langSkipped);
        $this->failed = (int) ($snapshot['failed'] ?? $this->failed);
        $this->thumbsDownloaded = (int) ($snapshot['thumbs_downloaded'] ?? $this->thumbsDownloaded);
        $this->tagsCreated = (int) ($snapshot['tags_created'] ?? $this->tagsCreated);
    }

    private function isTransientDatabaseError(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach ([
            'sqlstate[hy000] [2002]',
            'sqlstate[hy000] [2006]',
            'sqlstate[hy000] [2013]',
            'server has gone away',
            'lost connection',
            'connection refused',
            'actively refused',
            'no connection could be made',
            'attempt was made to access a socket',
            'too many connections',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function reconnectImportRunDatabase(): void
    {
        try {
            DB::disconnect();
            DB::reconnect();
        } catch (\Throwable) {
            // The next retry will surface or recover from the real error.
        }
    }

    private function recordImportRunDbWarning(QueryException $e): void
    {
        $line = '['.now()->toDateTimeString().'] Import run progress DB write skipped after retries: '
            .Str::limit($e->getMessage(), 500, '').PHP_EOL;

        @file_put_contents(storage_path('logs/avdb-import-db-retry.log'), $line, FILE_APPEND | LOCK_EX);
    }

    private function printSummary(): void
    {
        $this->newLine();
        $this->info('IMPORT COMPLETE');
        $this->info("Imported:  {$this->imported}");
        $this->info("Updated:   {$this->updated}");
        $this->info("Skipped:   {$this->skipped}");
        $this->info("LangSkip:  {$this->langSkipped}");
        $this->info("Failed:    {$this->failed}");
        $this->info("Thumbs:    {$this->thumbsDownloaded}");
        $this->info("Tags:      {$this->tagsCreated} new");
    }

    private function deleteVideoGraph(array $videoIds): void
    {
        $videoIds = array_values(array_filter(array_map('intval', $videoIds)));
        if ($videoIds === []) {
            return;
        }

        DB::table('video_translations')->whereIn('video_id', $videoIds)->delete();
        DB::table('video_tags')->whereIn('video_id', $videoIds)->delete();
        DB::table('thumbnails')->whereIn('video_id', $videoIds)->delete();
        DB::table('views')->whereIn('video_id', $videoIds)->delete();
        if (DB::getSchemaBuilder()->hasTable('video_view_daily_stats')) {
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
        SeoSitemapCache::clear();
    }
}
