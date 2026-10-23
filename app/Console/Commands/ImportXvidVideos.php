<?php

namespace App\Console\Commands;

use App\Enums\ThumbnailStatus;
use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Language;
use App\Models\Tag;
use App\Models\TagTranslation;
use App\Models\Thumbnail;
use App\Models\User;
use App\Models\Video;
use App\Models\XvidImportRun;
use App\Support\AvdbCreatorAvatarFactory;
use App\Support\AvdbStarterViews;
use App\Support\DurationParser;
use App\Support\RemoteVideoDuration;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportXvidVideos extends Command
{
    protected $signature = 'xvid:import
        {--category= : XVIDAPI category ID (1-69), or "all" for everything}
        {--categories= : Comma-separated XVIDAPI category IDs; overrides --category}
        {--ids= : Comma-separated XVIDAPI video IDs to import directly}
        {--pages= : Number of pages to import}
        {--start= : Starting page number (default 1)}
        {--pagesize= : Requested videos per page (max 100, default 50)}
        {--skip-thumbs : Use remote CDN poster URL without downloading to local disk}
        {--skip-tags : Skip tag parsing}
        {--dry-run : Show what would be imported without writing to database}
        {--delay=0 : Delay between API requests in milliseconds}
        {--updated-hours= : Only import videos updated in the last N hours}
        {--year= : Filter by year or year range, e.g. 2025 or 2024-2025}
        {--sort= : API sort direction: desc (default) or asc}
        {--wd= : Search keyword / title}
        {--code= : Search exact video code / slug}
        {--actor= : Search actor name}
        {--director= : Search director name}
        {--writer= : Search writer or studio}
        {--category-filter= : Search category/tag keyword}
        {--api-base= : Custom XVIDAPI provide/vod endpoint}
        {--source-language= : Only import videos whose original language is this code (e.g. ja, zh, vi)}
        {--skip-tag-translations : Do not fetch localized tag names for the site languages}
        {--starter-views=disabled : Starter view mode: disabled, natural, age_weighted}
        {--starter-min=25 : Minimum starter views}
        {--starter-max=5000 : Maximum starter views}
        {--starter-spread-days=90 : Days used to spread starter daily stats}
        {--starter-daily-stats : Seed video_view_daily_stats with starter views}
        {--starter-existing-zero : Also seed existing XVIDAPI videos that still have zero views}
        {--run-id= : xvid_import_runs id for progress tracking}';

    protected $description = 'Import videos from XVIDAPI into the AVDBCMS database';

    public const DEFAULT_API_BASE = 'https://xvidapi.com/api.php/provide/vod';

    public const CATEGORY_MAP = [
        1 => 'Xvidapi',
        7 => 'AI',
        8 => 'Amateur',
        9 => 'Anal',
        10 => 'Arab',
        11 => 'Asian Woman',
        12 => 'ASMR',
        13 => 'Ass',
        14 => 'Ass to Mouth',
        15 => 'BBW',
        16 => 'BDSM',
        17 => 'Bi Sexual',
        18 => 'Big Ass',
        19 => 'Big Cock',
        20 => 'Big Tits',
        21 => 'Black Woman',
        22 => 'Blonde',
        23 => 'Blowjob',
        24 => 'Brunette',
        25 => 'Bukkake',
        26 => 'Cam Porn',
        27 => 'Creampie',
        28 => 'Cuckold',
        29 => 'Cumshot',
        30 => 'Exotic',
        31 => 'Facial',
        32 => 'Familial Relations',
        33 => 'Feet',
        34 => 'Femdom',
        35 => 'Fisting',
        36 => 'Fucked Up Family',
        37 => 'Gangbang',
        38 => 'Gapes',
        39 => 'Gay',
        40 => 'Heels',
        41 => 'Indian',
        42 => 'Interracial',
        43 => 'Japanese',
        44 => 'Latina',
        45 => 'Lesbian',
        46 => 'Lingerie',
        47 => 'Massage',
        48 => 'Mature',
        49 => 'MILF',
        50 => 'Oiled',
        51 => 'Orgy',
        52 => 'Pissing',
        53 => 'Pornstar',
        54 => 'Real Amateur',
        55 => 'Redhead',
        56 => 'Sex Dolls',
        57 => 'Sex Toys',
        58 => 'Sexy',
        59 => 'Shaved Pussy',
        60 => 'Shemale',
        61 => 'Solo Masturbation',
        62 => 'Squirting',
        63 => 'Stockings',
        64 => 'Swingers',
        65 => 'Teen',
        66 => 'Toons',
        67 => 'Unknown',
        68 => 'Virtual Reality',
        69 => 'Workout',
    ];

    private ?XvidImportRun $run = null;
    private int $imported = 0;
    private int $skipped = 0;
    private int $updated = 0;
    private int $failed = 0;
    private int $thumbsDownloaded = 0;
    private int $tagsCreated = 0;
    private int $tagTranslationsCreated = 0;
    private bool $translateTags = true;
    private ?array $tagTranslationLocales = null;
    private int $plannedPages = 0;
    private int $completedPages = 0;
    private string $apiBaseUrl = self::DEFAULT_API_BASE;
    private array $resolvedCategoryCache = [];
    private array $xvidCreatorUserCache = [];
    private ?int $fallbackImportUserId = null;
    private array $starterViewSettings = [];
    private int $dateSequence = 0;
    private int $dateTotalSlots = 1;
    private int $dateSpreadDays = 0;
    private string $dateSortDirection = 'desc';
    private ?Carbon $dateAnchor = null;
    private string $dateSeed = '';
    private array $dateUsedTimestamps = [];
    private bool $dryRunActive = false;

    public function handle(): int
    {
        $this->raiseMemoryLimit();
        DB::disableQueryLog();

        $runId = $this->option('run-id');
        if ($runId) {
            $this->run = XvidImportRun::find($runId);
        }

        $settings = $this->loadRunSettings();
        $this->dryRunActive = (bool) ($settings['dry_run'] ?? false);
        $this->translateTags = (bool) ($settings['translate_tags'] ?? true);
        $this->apiBaseUrl = $this->normalizeApiBaseUrl((string) ($settings['api_base_url'] ?? self::DEFAULT_API_BASE));
        $this->starterViewSettings = $this->resolveStarterViewSettings($settings);

        $delay = max(0, (int) ($settings['delay'] ?? 0));
        $skipThumbs = (bool) ($settings['skip_thumbs'] ?? false);
        $skipTags = (bool) ($settings['skip_tags'] ?? false);
        $sortDirection = (string) ($settings['sort_direction'] ?? 'desc');

        $ids = $this->resolveIds($settings);
        $categories = $this->resolveCategories($settings);

        $startPage = max(1, (int) ($settings['start_page'] ?? 1));
        $maxPages = max(1, (int) ($settings['pages'] ?? 1));
        $pageSize = max(1, min(100, (int) ($settings['pagesize'] ?? 50)));

        $this->configureTimeline($ids, $categories, $maxPages, $pageSize, $sortDirection, (string) ($settings['date_seed'] ?? ''));

        $this->markRunRunning([
            'categories' => $categories,
            'pages' => $maxPages,
            'start_page' => $startPage,
            'pagesize' => $pageSize,
            'ids_count' => count($ids),
            'api_base' => $this->apiBaseUrl,
        ]);

        $this->write("Starting XVIDAPI import (base: {$this->apiBaseUrl})");

        try {
            if ($ids !== []) {
                $this->importSpecificIds($ids, $delay, $skipThumbs, $skipTags, $this->dryRunActive);
            } else {
                $this->plannedPages = count($categories) * $maxPages;
                if ($this->run) {
                    $this->run->forceFill(['total_pages' => $this->plannedPages])->save();
                }

                foreach ($categories as $catIndex => $catId) {
                    if ($this->shouldCancel()) {
                        break;
                    }

                    $catName = self::CATEGORY_MAP[$catId] ?? "Category #{$catId}";
                    $this->write("--- Category [{$catId}] {$catName} (".($catIndex + 1)."/".count($categories).") ---");

                    if ($this->run) {
                        $this->run->forceFill(['current_category' => $catName])->save();
                    }

                    $this->importCategory(
                        $catId,
                        $startPage,
                        $maxPages,
                        $pageSize,
                        $delay,
                        $skipThumbs,
                        $skipTags,
                        $this->dryRunActive,
                        $settings
                    );
                }
            }

            if ($this->shouldCancel()) {
                $this->finishRun('cancelled', 'Import cancelled by user.');
                $this->write('Import cancelled.');
                return 0;
            }

            $this->finishRun('finished', 'Import completed successfully.');
            $this->write('Import finished successfully.');
            $this->printSummary();

            return 0;
        } catch (\Throwable $e) {
            $this->failRun('Import failed: ' . $e->getMessage());
            $this->error('Import fatal error: ' . $e->getMessage());
            return 1;
        }
    }

    private function importCategory(
        int $catId,
        int $startPage,
        int $maxPages,
        int $pageSize,
        int $delay,
        bool $skipThumbs,
        bool $skipTags,
        bool $dryRun,
        array $settings
    ): void {
        $apiFilters = $this->resolveApiFilters($settings);

        for ($pageOffset = 0; $pageOffset < $maxPages; $pageOffset++) {
            if ($this->shouldCancel()) {
                return;
            }

            $page = $startPage + $pageOffset;
            if ($this->run) {
                $this->run->forceFill(['current_page' => $page])->save();
            }

            $queryParams = array_merge([
                'ac' => 'detail',
                'at' => 'json',
                't' => $catId,
                'pg' => $page,
                'pagesize' => $pageSize,
                'sort_direction' => $settings['sort_direction'] ?? 'desc',
            ], $apiFilters);

            $this->write("Category {$catId} | Fetching page {$page}...");

            $data = $this->fetchWithRetry($this->apiBaseUrl, $queryParams);
            if (! $data || empty($data['list'])) {
                $this->write("Category {$catId} | No more videos returned on page {$page}. Moving to next category.");
                $this->completedPages += ($maxPages - $pageOffset);
                $this->syncRunCounters("Completed category {$catId}");
                break;
            }

            $items = $data['list'];
            $this->write("Category {$catId} | Page {$page}: processing " . count($items) . " videos.");

            foreach ($items as $item) {
                if ($this->shouldCancel()) {
                    return;
                }

                $this->importItem($item, $catId, $delay, $skipThumbs, $skipTags, $dryRun);
            }

            $this->translatePageTags($items, $skipTags, $dryRun);

            $this->completedPages++;
            $this->syncRunCounters("Category {$catId} page {$page} complete");

            $this->collectGarbage();
            $this->ensureDatabaseConnection();

            if ($pageOffset % 5 === 0) {
                $memMb = round(memory_get_usage(true) / 1048576, 1);
                $this->write("Category {$catId} | Memory usage: {$memMb} MB");
            }

            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }
    }

    private function importSpecificIds(
        array $ids,
        int $delay,
        bool $skipThumbs,
        bool $skipTags,
        bool $dryRun
    ): void {
        $chunks = array_chunk($ids, 50);
        $this->plannedPages = count($chunks);
        if ($this->run) {
            $this->run->forceFill(['total_pages' => $this->plannedPages])->save();
        }

        foreach ($chunks as $chunkIndex => $chunkIds) {
            if ($this->shouldCancel()) {
                return;
            }

            $idsString = implode(',', $chunkIds);
            $this->write("Fetching direct IDs chunk ".($chunkIndex + 1)."/".count($chunks)." ({$idsString})...");

            $data = $this->fetchWithRetry($this->apiBaseUrl, [
                'ac' => 'detail',
                'at' => 'json',
                'ids' => $idsString,
            ]);

            if (! $data || empty($data['list'])) {
                $this->write("No data returned for IDs chunk.");
                continue;
            }

            foreach ($data['list'] as $item) {
                if ($this->shouldCancel()) {
                    return;
                }

                $catId = $this->categoryIdFromItem($item);
                $this->importItem($item, $catId, $delay, $skipThumbs, $skipTags, $dryRun);
            }

            $this->translatePageTags($data['list'], $skipTags, $dryRun);

            $this->completedPages++;
            $this->syncRunCounters("Completed IDs chunk ".($chunkIndex + 1));

            $this->collectGarbage();
            $this->ensureDatabaseConnection();

            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }
    }

    private function importItem(
        array $item,
        int $catId,
        int $delay,
        bool $skipThumbs,
        bool $skipTags,
        bool $dryRun
    ): bool {
        $xvidId = (int) ($item['id'] ?? 0);
        if ($xvidId <= 0) {
            $this->failed++;
            return false;
        }

        $rawTitle = (string) ($item['name'] ?? $item['origin_name'] ?? '');
        $title = Str::limit($this->cleanXvidText($rawTitle), 250, '');
        if ($title === '') {
            $this->failed++;
            return false;
        }

        $playableLink = $this->extractEmbedCode($item);
        if (! $playableLink) {
            $this->failed++;
            return false;
        }

        $posterUrl = $this->posterUrlFromItem($item);

        // Check if existing video by xvid_id
        $existing = Video::withTrashed()->where('xvid_id', $xvidId)->first();

        if ($existing && ! $existing->trashed()) {
            if ($dryRun) {
                $this->skipped++;
                return false;
            }

            $contentHash = $this->contentHashFromItem($item, $catId);
            if ($existing->xvid_content_hash !== $contentHash) {
                $this->updateExistingVideo($existing, $item, $catId, $contentHash, $skipTags);
                $this->updated++;
            } else {
                $this->skipped++;
            }

            return false;
        }

        if ($existing && $existing->trashed()) {
            $existing->forceDelete();
        }

        if ($dryRun) {
            $this->imported++;
            return true;
        }

        try {
            $video = DB::transaction(function () use ($item, $catId, $xvidId, $title, $playableLink, $posterUrl, $skipTags) {
                $localCategoryId = $this->resolveLocalCategory($catId, (string) ($item['type_name'] ?? ''));
                $contentHash = $this->contentHashFromItem($item, $catId);
                $code = trim((string) ($item['slug'] ?? $item['movie_code'] ?? ''));
                $quality = trim((string) ($item['quality'] ?? ''));
                $actors = $this->cleanList($item['actor'] ?? []);
                $actorString = Str::limit(implode(', ', $actors), 500, '');
                $author = Str::limit($this->cleanXvidText($item['author'] ?? ''), 255, '');
                $author = $this->isPlaceholderValue($author) ? '' : $author;
                $description = $this->cleanXvidText($item['description'] ?? $title) ?: $title;
                $duration = $this->durationFromItem($item);

                $publishedAt = $this->nextImportPublishedAt($xvidId);
                $userId = $this->resolveCreatorUserId($item, $actors, $author, $publishedAt);
                $slug = $this->uniqueSlug($code ?: Str::slug($title));

                $releaseDate = $this->parseDate($item['vod_pubdate'] ?? $item['created_at'] ?? null) ?: $publishedAt;
                $updatedAt = $this->parseDate($item['vod_time'] ?? null) ?: now();
                $starterViews = $this->starterViewsForItem($xvidId, $publishedAt, $releaseDate);

                $video = Video::create([
                    'uuid' => (string) Str::uuid(),
                    'title' => $title,
                    'slug' => $slug,
                    'description' => $description,
                    'file' => null,
                    'embed_code' => $playableLink,
                    'poster_url' => $posterUrl,
                    'upload18_vid' => null,
                    'xvid_id' => $xvidId,
                    'xvid_code' => $code,
                    'xvid_quality' => $quality,
                    'xvid_actor' => $actorString,
                    'xvid_author' => $author,
                    'xvid_release_date' => $releaseDate,
                    'xvid_updated_at' => $updatedAt,
                    'xvid_content_hash' => $contentHash,
                    'provider' => 'xvidapi',
                    'original_file_name' => $code ?: $title,
                    'original_mimetype' => 'video/mp4',
                    'duration' => $duration,
                    'size' => null,
                    'user_id' => $userId,
                    'category_id' => $localCategoryId,
                    'status' => VideoStatus::PUBLIC->value,
                    'language' => 'en',
                    'published_at' => $publishedAt,
                    'uploaded_at' => $publishedAt,
                    'created_at' => $publishedAt,
                    'updated_at' => $publishedAt,
                    'allow_comments' => true,
                    'show_likes' => true,
                    'show_ad' => true,
                    'is_live' => false,
                    'is_short' => false,
                    'views' => $starterViews,
                ]);

                $this->seedStarterDailyStats($video, $starterViews, $publishedAt, $xvidId);

                if (! $skipTags) {
                    $this->parseTags($video, $item);
                }

                return $video;
            });

            if (! $skipThumbs && $video && $posterUrl) {
                $this->downloadThumbnail($video, $posterUrl);
            }

            $this->imported++;
            return true;
        } catch (\Throwable $e) {
            $this->failed++;
            $this->write("Failed video {$xvidId}: " . Str::limit($e->getMessage(), 120));
            return false;
        }
    }

    private function updateExistingVideo(
        Video $video,
        array $item,
        int $catId,
        string $contentHash,
        bool $skipTags
    ): void {
        $localCategoryId = $this->resolveLocalCategory($catId, (string) ($item['type_name'] ?? ''));
        $rawTitle = (string) ($item['name'] ?? $item['origin_name'] ?? '');
        $title = Str::limit($this->cleanXvidText($rawTitle), 250, '');
        $description = $this->cleanXvidText($item['description'] ?? $title) ?: $title;
        $playableLink = $this->extractEmbedCode($item);
        $posterUrl = $this->posterUrlFromItem($item);
        $code = trim((string) ($item['slug'] ?? $item['movie_code'] ?? ''));
        $quality = trim((string) ($item['quality'] ?? ''));
        $actors = $this->cleanList($item['actor'] ?? []);
        $actorString = Str::limit(implode(', ', $actors), 500, '');
        $author = Str::limit($this->cleanXvidText($item['author'] ?? ''), 255, '');
        $author = $this->isPlaceholderValue($author) ? '' : $author;
        $duration = $this->durationFromItem($item);
        $updatedAt = $this->parseDate($item['vod_time'] ?? null) ?: now();

        $video->update([
            'title' => $title,
            'description' => $description,
            'embed_code' => $playableLink ?: $video->embed_code,
            'poster_url' => $posterUrl ?: $video->poster_url,
            'xvid_code' => $code ?: $video->xvid_code,
            'xvid_quality' => $quality ?: $video->xvid_quality,
            'xvid_actor' => $actorString ?: $video->xvid_actor,
            'xvid_author' => $author ?: $video->xvid_author,
            'xvid_updated_at' => $updatedAt,
            'xvid_content_hash' => $contentHash,
            'category_id' => $localCategoryId,
            'duration' => $duration ?: $video->duration,
            'provider' => 'xvidapi',
        ]);

        if (! $skipTags) {
            $this->parseTags($video, $item);
        }
    }

    public function resolveLocalCategory(int $xvidCatId, ?string $typeName = null): int
    {
        if (isset($this->resolvedCategoryCache[$xvidCatId])) {
            return $this->resolvedCategoryCache[$xvidCatId];
        }

        $name = trim((string) ($typeName ?: (self::CATEGORY_MAP[$xvidCatId] ?? '')));
        if ($name === '' || in_array(strtolower($name), ['xvidapi', 'unknown', 'updating'], true)) {
            $name = 'General';
        }

        $slug = Str::slug($name);

        $category = Category::where('slug', $slug)
            ->orWhereRaw('LOWER(title) = ?', [strtolower($name)])
            ->first();

        if ($category) {
            $this->resolvedCategoryCache[$xvidCatId] = (int) $category->id;
            return (int) $category->id;
        }

        $maxPosition = (int) Category::max('position');
        $category = Category::create([
            'title' => $name,
            'slug' => $slug,
            'icon' => $this->categoryIcon($name),
            'background' => null,
            'in_menu' => true,
            'position' => $maxPosition + 1,
        ]);

        $this->resolvedCategoryCache[$xvidCatId] = (int) $category->id;
        return (int) $category->id;
    }

    private function categoryIcon(string $name): string
    {
        $lower = strtolower($name);
        return match (true) {
            str_contains($lower, 'amateur') => 'user',
            str_contains($lower, 'teen') => 'heart',
            str_contains($lower, 'milf') || str_contains($lower, 'mature') => 'venus',
            str_contains($lower, 'blowjob') => 'fire',
            str_contains($lower, 'anal') => 'bolt',
            str_contains($lower, 'asian') || str_contains($lower, 'japanese') || str_contains($lower, 'indian') => 'globe',
            str_contains($lower, 'lesbian') || str_contains($lower, 'gay') => 'heart-pulse',
            str_contains($lower, 'vr') || str_contains($lower, 'virtual') => 'vr-cardboard',
            str_contains($lower, 'ai') => 'robot',
            str_contains($lower, 'massage') => 'spa',
            default => 'folder',
        };
    }

    private function resolveCreatorUserId(array $item, array $actors, string $author, Carbon $publishedAt): int
    {
        $creatorName = null;
        $source = 'creator';

        if ($author !== '' && ! in_array(strtolower($author), ['updating', 'unknown', 'n/a', 'none'], true)) {
            $creatorName = $author;
            $source = 'studio';
        } elseif (! empty($actors)) {
            $firstActor = trim((string) $actors[0]);
            if ($firstActor !== '' && ! in_array(strtolower($firstActor), ['updating', 'unknown', 'n/a', 'none'], true)) {
                $creatorName = $firstActor;
                $source = 'actor';
            }
        }

        if (! $creatorName) {
            return $this->fallbackImportUserId();
        }

        $cacheKey = $source . ':' . Str::slug($creatorName);
        if (isset($this->xvidCreatorUserCache[$cacheKey])) {
            return $this->xvidCreatorUserCache[$cacheKey];
        }

        $email = 'creator.' . substr(hash('sha1', $cacheKey), 0, 16) . '@xvidapi.local';
        $user = User::where('email', $email)->first();

        if (! $user) {
            $username = Str::slug($creatorName);
            if ($username === '' || User::where('username', $username)->exists()) {
                $username = Str::limit(Str::slug($creatorName), 20, '') . '_' . Str::random(4);
            }

            $user = User::create([
                'username' => $username,
                'name' => $creatorName,
                'email' => $email,
                'password' => bcrypt(Str::random(24)),
                'email_verified_at' => $publishedAt,
                'created_at' => $publishedAt,
                'updated_at' => $publishedAt,
                'description' => "Official creator channel for {$creatorName}.",
            ]);

            $avatarCandidate = [
                'key' => $cacheKey,
                'source' => $source,
                'name' => $creatorName,
            ];
            $avatarFilename = AvdbCreatorAvatarFactory::ensure($avatarCandidate);
            if ($avatarFilename) {
                $user->forceFill(['avatar' => $avatarFilename])->save();
            }
        }

        $this->xvidCreatorUserCache[$cacheKey] = (int) $user->id;
        return (int) $user->id;
    }

    private function fallbackImportUserId(): int
    {
        if ($this->fallbackImportUserId !== null) {
            return $this->fallbackImportUserId;
        }

        $admin = User::where('is_admin', true)->first();
        if ($admin) {
            $this->fallbackImportUserId = (int) $admin->id;
            return $this->fallbackImportUserId;
        }

        $user = User::first();
        $this->fallbackImportUserId = $user ? (int) $user->id : 1;
        return $this->fallbackImportUserId;
    }

    public function cleanXvidText(mixed $value): string
    {
        $str = $this->arrayToString($value);
        if ($str === '') {
            return '';
        }

        $decoded = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/&amp;(#?\w+)[_;]/i', '&$1;', $decoded);
        $decoded = preg_replace('/&#(\d+)[_]/i', '&#$1;', $decoded);
        $decoded = preg_replace('/&bull[_;\s]*/i', ' - ', $decoded);
        $decoded = preg_replace('/&[a-zA-Z]+[_]/i', ' ', $decoded);
        $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/\s+_\s+/u', ' - ', $decoded);
        $decoded = strip_tags($decoded);
        $decoded = preg_replace('/\s+/u', ' ', $decoded);

        return trim($decoded);
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

    private function durationFromItem(array $item): ?int
    {
        foreach (['time', 'duration', 'vod_duration', 'runtime', 'length'] as $field) {
            $val = $item[$field] ?? null;
            if ($val) {
                $seconds = DurationParser::toSeconds($val);
                if ($seconds !== null && $seconds > 0) {
                    return $seconds;
                }
            }
        }

        return null;
    }

    /**
     * XVIDAPI localizes tag names when asked with `language=<code>` (titles and descriptions stay
     * in the video's original language). For every active site language, fetch the page's ids once
     * and store the localized names as tag_translations, so tag pills, SEO keywords and tag search
     * follow the visitor's language like AVDB tags do. Tags that already have a translation for a
     * locale are left untouched (admin edits survive), so re-imports cost no extra API calls.
     */
    private function translatePageTags(array $items, bool $skipTags, bool $dryRun): void
    {
        if ($skipTags || $dryRun || ! $this->translateTags || $items === []) {
            return;
        }

        $locales = $this->tagTranslationLocales();
        if ($locales === []) {
            return;
        }

        $baseSlugs = [];
        $ids = [];
        foreach ($items as $item) {
            $xvidId = (int) ($item['id'] ?? 0);
            if ($xvidId <= 0) {
                continue;
            }
            $ids[] = $xvidId;
            foreach (explode(',', (string) ($item['tag'] ?? '')) as $tagName) {
                $slug = Str::slug($this->cleanXvidText($tagName));
                if (strlen($slug) >= 2) {
                    $baseSlugs[$slug] = true;
                }
            }
        }

        if ($ids === [] || $baseSlugs === []) {
            return;
        }

        try {
            $tagIds = Tag::whereIn('slug', array_keys($baseSlugs))->pluck('id', 'slug')->all();
            if ($tagIds === []) {
                return;
            }

            $have = [];
            TagTranslation::whereIn('tag_id', array_values($tagIds))
                ->whereIn('locale', $locales)
                ->get(['tag_id', 'locale'])
                ->each(function ($row) use (&$have) {
                    $have[$row->tag_id.'|'.$row->locale] = true;
                });

            $pending = array_values(array_filter($locales, function (string $locale) use ($tagIds, $have) {
                foreach ($tagIds as $tagId) {
                    if (! isset($have[$tagId.'|'.$locale])) {
                        return true;
                    }
                }

                return false;
            }));

            if ($pending === []) {
                return;
            }

            $idsString = implode(',', array_values(array_unique($ids)));
            $responses = Http::pool(function ($pool) use ($pending, $idsString) {
                foreach ($pending as $locale) {
                    $pool->as($locale)
                        ->timeout(25)
                        ->withHeaders(['Accept' => 'application/json'])
                        ->withOptions(['verify' => false])
                        ->get($this->apiBaseUrl, ['ac' => 'detail', 'at' => 'json', 'ids' => $idsString, 'language' => $locale]);
                }
            }, 4);

            $added = 0;
            foreach ($pending as $locale) {
                $response = $responses[$locale] ?? null;
                if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->successful()) {
                    continue;
                }

                foreach ((array) ($response->json('list') ?? []) as $localizedItem) {
                    if (! isset($localizedItem['localized_vod_tag'])) {
                        continue; // the API does not localize this language
                    }

                    $source = explode(',', (string) ($localizedItem['source_vod_tag'] ?? ''));
                    $localized = explode(',', (string) $localizedItem['localized_vod_tag']);
                    if (count($source) !== count($localized)) {
                        continue; // positions must line up to pair a tag with its translation
                    }

                    foreach ($source as $index => $sourceName) {
                        $sourceClean = $this->cleanXvidText($sourceName);
                        $tagId = $tagIds[Str::slug($sourceClean)] ?? null;
                        // the API marks some localized adult terms with a trailing "18+"; the site is adult-only anyway
                        $name = trim((string) preg_replace('/\s*18\+$/u', '', $this->cleanXvidText($localized[$index])));
                        $name = Str::limit($name, 150, '');
                        if (! $tagId || $name === '' || isset($have[$tagId.'|'.$locale])
                            || mb_strtolower($name) === mb_strtolower($sourceClean)) {
                            continue;
                        }

                        TagTranslation::firstOrCreate(['tag_id' => $tagId, 'locale' => $locale], ['name' => $name]);
                        $have[$tagId.'|'.$locale] = true;
                        $added++;
                    }
                }
            }

            if ($added > 0) {
                $this->tagTranslationsCreated += $added;
                $this->write("Tag translations: +{$added} (".implode(', ', $pending).')');
            }
        } catch (\Throwable $e) {
            $this->write('Tag translation skipped: '.Str::limit($e->getMessage(), 120));
        }
    }

    /**
     * Active site languages other than the default English one; resolved once per run.
     */
    private function tagTranslationLocales(): array
    {
        if ($this->tagTranslationLocales !== null) {
            return $this->tagTranslationLocales;
        }

        $codes = [];
        try {
            if (DB::getSchemaBuilder()->hasTable('tag_translations')) {
                $codes = DB::getSchemaBuilder()->hasTable('languages')
                    ? Language::active()->pluck('code')->all()
                    : array_keys((array) config('languages', []));
            }
        } catch (\Throwable $e) {
            $codes = [];
        }

        $skip = ['en', (string) config('app.fallback_locale', 'en')];

        return $this->tagTranslationLocales = array_values(array_filter(
            array_unique(array_map(fn ($code) => strtolower(trim((string) $code)), $codes)),
            fn (string $code) => $code !== '' && ! in_array($code, $skip, true) && preg_match('/^[a-z]{2,3}(-[a-z]{2,4})?$/', $code)
        ));
    }

    private function parseTags(Video $video, array $item): void
    {
        $tagString = (string) ($item['tag'] ?? '');
        if ($tagString === '') {
            $cats = (array) ($item['category'] ?? []);
            $tagString = implode(', ', $cats);
        }

        $rawTags = array_filter(array_map('trim', explode(',', $tagString)));
        if (empty($rawTags)) {
            return;
        }

        $tagIds = [];
        foreach ($rawTags as $tagName) {
            $cleaned = $this->cleanXvidText($tagName);
            $slug = Str::slug($cleaned);
            if (! $slug || strlen($slug) < 2) {
                continue;
            }

            $tag = Tag::firstOrCreate(
                ['slug' => $slug],
                ['name' => $cleaned, 'usage_count' => 0]
            );

            if ($tag->wasRecentlyCreated) {
                $this->tagsCreated++;
            }

            $tagIds[] = $tag->id;
        }

        if (! empty($tagIds)) {
            $video->tags()->syncWithoutDetaching($tagIds);
        }
    }

    private function downloadThumbnail(Video $video, string $url): void
    {
        try {
            $response = Http::timeout(12)->withOptions(['verify' => false])->get($url);
            if (! $response->successful()) {
                return;
            }

            $body = $response->body();
            if (strlen($body) < 100) {
                return;
            }

            $filename = 'xvid_' . $video->xvid_id . '_' . Str::random(8) . '.jpg';
            $path = Video::THUMBNAIL_FOLDER . '/' . $filename;

            Storage::put($path, $body);

            Thumbnail::create([
                'video_id' => $video->id,
                'name' => $filename,
                'status' => ThumbnailStatus::COMPLETED->value,
            ]);

            $this->thumbsDownloaded++;
        } catch (\Throwable $e) {
            // Non-critical, fallback to remote poster_url
        }
    }

    private function contentHashFromItem(array $item, int $catId): string
    {
        $material = implode('|', [
            (string) ($item['name'] ?? ''),
            (string) ($item['poster_url'] ?? ''),
            (string) ($item['thumb_url'] ?? ''),
            (string) ($item['time'] ?? ''),
            (string) ($item['quality'] ?? ''),
            (string) ($item['author'] ?? ''),
            (string) ($item['tag'] ?? ''),
            (string) $catId,
        ]);

        return hash('sha256', $material);
    }

    private function starterViewsForItem(int $xvidId, Carbon $publishedAt, ?Carbon $releaseDate = null): int
    {
        if ($xvidId <= 0) {
            return 0;
        }

        $date = $releaseDate ?: $publishedAt;
        return AvdbStarterViews::countFor($xvidId, $date, $this->starterViewSettings);
    }

    private function seedStarterDailyStats(Video $video, int $views, Carbon $endAt, int $xvidId): void
    {
        if ($views <= 0 || ! (bool) ($this->starterViewSettings['daily_stats'] ?? false)) {
            return;
        }

        AvdbStarterViews::seedDailyStats(
            (int) $video->id,
            $views,
            $endAt,
            (int) ($this->starterViewSettings['spread_days'] ?? AvdbStarterViews::defaults()['spread_days']),
            (string) ($this->starterViewSettings['seed'] ?? '') . '|xvid|' . $xvidId
        );
    }

    private function fetchWithRetry(string $url, array $params, int $maxRetries = 3): ?array
    {
        $attempt = 0;
        while ($attempt < $maxRetries) {
            $attempt++;
            try {
                $response = Http::timeout(25)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                        'Accept' => 'application/json',
                    ])
                    ->withOptions(['verify' => false])
                    ->get($url, $params);

                if ($response->successful()) {
                    $json = $response->json();
                    if (is_array($json) && isset($json['code'])) {
                        return $json;
                    }
                }
            } catch (\Throwable $e) {
                if ($attempt >= $maxRetries) {
                    $this->write("API request failed after {$attempt} attempts: " . $e->getMessage());
                    return null;
                }
                usleep(500000 * $attempt);
            }
        }

        return null;
    }

    private function configureTimeline(
        array $ids,
        array $categories,
        int $maxPages,
        int $pageSize,
        string $sortDirection,
        string $seed
    ): void {
        $slots = $ids !== [] ? count($ids) : max(1, count($categories) * $maxPages * $pageSize);
        $this->dateSequence = 0;
        $this->dateTotalSlots = max(1, $slots);
        $this->dateSpreadDays = min(3650, max(0, $this->dateTotalSlots - 1));
        $this->dateSortDirection = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';
        $this->dateAnchor = now();
        $this->dateSeed = trim($seed) ?: (string) Str::uuid();
        $this->dateUsedTimestamps = [];
    }

    private function nextImportPublishedAt(int $xvidId): Carbon
    {
        $anchor = $this->dateAnchor ?: now();
        $index = $this->dateSequence++;
        $totalSlots = max(1, $this->dateTotalSlots);
        $totalSeconds = max(0, $this->dateSpreadDays * 86400);

        if ($totalSeconds <= 0 || $totalSlots <= 1) {
            $ageSeconds = abs(crc32($this->dateSeed . '|' . $xvidId . '|' . $index)) % 86400;
        } else {
            $position = min($index, $totalSlots - 1);
            $ratio = $position / ($totalSlots - 1);
            $baseAge = $this->dateSortDirection === 'asc'
                ? (int) round($totalSeconds * (1 - $ratio))
                : (int) round($totalSeconds * $ratio);
            $slotGap = max(1, (int) floor($totalSeconds / ($totalSlots - 1)));
            $jitter = abs(crc32('jitter|' . $xvidId . '|' . $index)) % max(1, (int) floor($slotGap / 4) + 1);
            $ageSeconds = max(0, $baseAge + $jitter);
        }

        $date = $anchor->copy()->subSeconds($ageSeconds);
        while (isset($this->dateUsedTimestamps[$date->timestamp])) {
            $date = $this->dateSortDirection === 'asc' ? $date->copy()->addSecond() : $date->copy()->subSecond();
        }
        $this->dateUsedTimestamps[$date->timestamp] = true;

        return $date;
    }

    private function uniqueSlug(string $base): string
    {
        $base = Str::slug($base) ?: 'video-' . Str::random(8);
        $slug = $base;
        $counter = 1;

        while (Video::withTrashed()->where('slug', $slug)->exists()) {
            if ($counter > 3) {
                $slug = Str::limit($base, 220, '') . '-' . Str::lower(Str::random(6));
                if (! Video::withTrashed()->where('slug', $slug)->exists()) {
                    return $slug;
                }
            }
            $slug = Str::limit($base, 235, '') . '-' . $counter++;
        }

        return $slug;
    }

    private function categoryIdFromItem(array $item): int
    {
        $typeName = (string) ($item['type_name'] ?? '');
        if ($typeName !== '') {
            foreach (self::CATEGORY_MAP as $id => $name) {
                if (strcasecmp($typeName, $name) === 0) {
                    return $id;
                }
            }
        }

        return 1;
    }

    private function resolveCategories(array $settings): array
    {
        $opt = $this->option('categories') ?: $this->option('category');
        if ($opt !== null && $opt !== '') {
            if (strtolower(trim($opt)) === 'all') {
                return array_keys(self::CATEGORY_MAP);
            }
            return array_values(array_filter(array_map('intval', explode(',', $opt))));
        }

        $configured = (array) ($settings['categories'] ?? []);
        if ($configured !== []) {
            return array_values(array_filter(array_map('intval', $configured)));
        }

        return [65]; // Default to Teen (category 65) if unspecified
    }

    private function resolveIds(array $settings): array
    {
        $opt = $this->option('ids') ?: ($settings['ids'] ?? '');
        if (! $opt) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $opt)))));
    }

    private function resolveApiFilters(array $settings): array
    {
        $filters = [];
        $candidates = [
            'wd' => $this->option('wd') ?: ($settings['search_wd'] ?? ''),
            'code' => $this->option('code') ?: ($settings['search_code'] ?? ''),
            'actor' => $this->option('actor') ?: ($settings['search_actor'] ?? ''),
            'director' => $this->option('director') ?: ($settings['search_director'] ?? ''),
            'writer' => $this->option('writer') ?: ($settings['search_writer'] ?? ''),
            'category' => $this->option('category-filter') ?: ($settings['search_category'] ?? ''),
            'h' => $this->option('updated-hours') ?: ($settings['updated_hours'] ?? ''),
            'year' => $this->option('year') ?: ($settings['year'] ?? ''),
            'source_language' => $this->option('source-language') ?: ($settings['search_source_language'] ?? ''),
        ];

        foreach ($candidates as $k => $v) {
            $v = trim((string) $v);
            if ($v !== '') {
                $filters[$k] = $v;
            }
        }

        return $filters;
    }

    private function resolveStarterViewSettings(array $settings): array
    {
        return [
            'mode' => $this->option('starter-views') ?: ($settings['starter_views_mode'] ?? AvdbStarterViews::MODE_DISABLED),
            'min' => (int) ($this->option('starter-min') ?: ($settings['starter_views_min'] ?? AvdbStarterViews::defaults()['min'])),
            'max' => (int) ($this->option('starter-max') ?: ($settings['starter_views_max'] ?? AvdbStarterViews::defaults()['max'])),
            'spread_days' => (int) ($this->option('starter-spread-days') ?: ($settings['starter_views_spread_days'] ?? AvdbStarterViews::defaults()['spread_days'])),
            'daily_stats' => $this->option('starter-daily-stats') || (bool) ($settings['starter_views_daily_stats'] ?? false),
            'apply_existing_zero' => $this->option('starter-existing-zero') || (bool) ($settings['starter_views_existing_zero'] ?? false),
            'seed' => (string) ($settings['starter_views_seed'] ?? Str::random(16)),
        ];
    }

    private function normalizeApiBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return self::DEFAULT_API_BASE;
        }

        return rtrim($url, '/');
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function arrayToString(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_filter(array_map([$this, 'arrayToString'], $value)));
        }

        return (string) $value;
    }

    private function cleanList(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/\s*,\s*|\s*\|\s*/', (string) $value);
        return array_values(array_filter(
            array_map([$this, 'cleanXvidText'], $items ?: []),
            fn (string $item) => $item !== '' && ! $this->isPlaceholderValue($item)
        ));
    }

    /**
     * XVIDAPI fills unknown actor/author fields with placeholders such as "Updating".
     */
    private function isPlaceholderValue(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['updating', 'unknown', 'n/a', 'na', 'none', 'null', '-', '--', '---', '----'], true);
    }

    private function loadRunSettings(): array
    {
        // The run row (UI / cron schedule) is the source of truth; a CLI option only overrides it when
        // it was actually passed. --start/--pagesize/--sort therefore have NO signature defaults, otherwise
        // every `xvid:import --run-id=N` silently reset start page to 1, page size to 50 and sort to desc.
        $settings = $this->run?->settings ?? [];

        if ($this->option('categories')) {
            $settings['categories'] = array_values(array_filter(array_map('intval', explode(',', $this->option('categories')))));
        } elseif ($this->option('category')) {
            $settings['categories'] = strtolower($this->option('category')) === 'all'
                ? array_keys(self::CATEGORY_MAP)
                : [(int) $this->option('category')];
        }

        if ($this->option('ids')) {
            $settings['ids'] = $this->option('ids');
        }
        if ($this->option('pages')) {
            $settings['pages'] = (int) $this->option('pages');
        }
        if ($this->option('start')) {
            $settings['start_page'] = (int) $this->option('start');
        }
        if ($this->option('pagesize')) {
            $settings['pagesize'] = (int) $this->option('pagesize');
        }
        if ($this->option('skip-thumbs')) {
            $settings['skip_thumbs'] = true;
        }
        if ($this->option('skip-tags')) {
            $settings['skip_tags'] = true;
        }
        if ($this->option('dry-run')) {
            $settings['dry_run'] = true;
        }
        if ($this->option('delay')) {
            $settings['delay'] = (int) $this->option('delay');
        }
        if ($this->option('sort')) {
            $settings['sort_direction'] = $this->option('sort');
        }
        if ($this->option('api-base')) {
            $settings['api_base_url'] = $this->option('api-base');
        }
        if ($this->option('skip-tag-translations')) {
            $settings['translate_tags'] = false;
        }

        return $settings;
    }

    private function markRunRunning(array $context): void
    {
        if (! $this->run) {
            return;
        }

        $this->run->forceFill([
            'status' => 'running',
            'started_at' => now(),
            'message' => 'Import running...',
        ])->save();
        $this->run->appendLog('Run started with ' . json_encode($context));
    }

    private function shouldCancel(): bool
    {
        if (! $this->run) {
            return false;
        }

        $this->run->refresh();
        return (bool) $this->run->cancel_requested;
    }

    private function syncRunCounters(string $message): void
    {
        if (! $this->run) {
            return;
        }

        $progress = $this->plannedPages > 0
            ? min(100, (int) round(($this->completedPages / $this->plannedPages) * 100))
            : 0;

        $this->run->forceFill([
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'updated' => $this->updated,
            'failed' => $this->failed,
            'tags_created' => $this->tagsCreated,
            'thumbs_downloaded' => $this->thumbsDownloaded,
            'completed_pages' => $this->completedPages,
            'progress' => $progress,
            'message' => $message,
        ])->save();

        $this->run->appendLog("[Progress {$progress}%] {$message} (imported: {$this->imported}, updated: {$this->updated}, skipped: {$this->skipped}, failed: {$this->failed})");
    }

    private function finishRun(string $status, string $message): void
    {
        if (! $this->run) {
            return;
        }

        $this->run->forceFill([
            'status' => $status,
            'progress' => $status === 'finished' ? 100 : $this->run->progress,
            'message' => $message,
            'finished_at' => now(),
        ])->save();
        $this->run->appendLog("Run finished with status {$status}: {$message}");
    }

    private function failRun(string $message): void
    {
        if (! $this->run) {
            return;
        }

        $this->run->forceFill([
            'status' => 'failed',
            'message' => Str::limit($message, 450),
            'finished_at' => now(),
        ])->save();
        $this->run->appendLog("Run failed: {$message}");
    }

    private function write(string $message): void
    {
        $this->info('[' . now()->format('H:i:s') . '] ' . $message);
        if ($this->run) {
            $this->run->appendLog($message);
        }
    }

    private function printSummary(): void
    {
        $this->table(['Metric', 'Count'], [
            ['Imported (New)', $this->imported],
            ['Updated', $this->updated],
            ['Skipped (Duplicate)', $this->skipped],
            ['Failed', $this->failed],
            ['Tags Created', $this->tagsCreated],
            ['Tag Translations Added', $this->tagTranslationsCreated],
            ['Thumbnails Downloaded', $this->thumbsDownloaded],
            ['Completed Pages', $this->completedPages],
        ]);
    }

    private function raiseMemoryLimit(): void
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);
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
}
