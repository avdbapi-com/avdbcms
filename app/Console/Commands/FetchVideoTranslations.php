<?php

namespace App\Console\Commands;

use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FetchVideoTranslations extends Command
{
    protected $signature = 'avdb:translate
        {--locales=    : Comma-separated locale codes to fetch, for example "ja,vi,zh"}
        {--all         : Fetch all 16 supported locales}
        {--limit=0     : Max videos to process, 0 = all}
        {--delay=0     : Delay between API batches in milliseconds}
        {--ids=        : Comma-separated AVDB IDs to translate}
        {--video-ids=  : Comma-separated local video IDs to translate}
        {--avdb-id-file= : File containing AVDB IDs, one per line or comma-separated}
        {--video-id-file= : File containing local video IDs, one per line or comma-separated}
        {--chunk=25    : AVDB IDs per locale API request}
        {--concurrency=8 : Parallel locale requests per chunk}
        {--timeout=20  : HTTP timeout per locale request}
        {--api-base=https://avdbapi.com/api.php/provide/vod : AVDB-compatible provide/vod API URL}
        {--overwrite   : Overwrite existing non-empty translation rows}
        {--replace-placeholders : Replace rows whose title still equals the base imported title}
        {--missing-only : Only create missing rows; do not replace base-title placeholders}
        {--dry-run     : Fetch and report without writing database rows}';

    protected $description = 'Backfill exact AVDB translations for existing imported videos in batched parallel API calls';

    private const SUPPORTED_LOCALES = [
        'ja', 'zh', 'ko', 'vi', 'th', 'ms', 'id',
        'fr', 'de', 'es', 'pt', 'ru', 'ar', 'hi', 'tl', 'tr',
    ];

    private const HTTP_RETRIES = 1;

    private const FALLBACK_ID_CHUNK_SIZE = 5;

    private string $apiBaseUrl = 'https://avdbapi.com/api.php/provide/vod';

    private bool $hasCountryColumn = false;

    private bool $hasTypeNameColumn = false;

    private int $processed = 0;

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $failedBatches = 0;

    public function handle(): int
    {
        $locales = $this->resolveLocales();
        if ($locales === []) {
            $this->error('Specify --all or --locales=ja,vi,zh.');

            return self::FAILURE;
        }

        $this->apiBaseUrl = $this->normalizeApiBaseUrl((string) $this->option('api-base'));
        $limit = max(0, (int) $this->option('limit'));
        $chunkSize = max(1, min(500, (int) $this->option('chunk')));
        $concurrency = max(1, min(count($locales), min(16, (int) $this->option('concurrency'))));
        $timeout = max(3, min(120, (int) $this->option('timeout')));
        $delay = max(0, (int) $this->option('delay'));
        $overwrite = (bool) $this->option('overwrite');
        $replacePlaceholders = $overwrite || ! (bool) $this->option('missing-only') || (bool) $this->option('replace-placeholders');
        $dryRun = (bool) $this->option('dry-run');

        $this->hasCountryColumn = Schema::hasColumn('video_translations', 'country');
        $this->hasTypeNameColumn = Schema::hasColumn('video_translations', 'type_name');

        $query = $this->videoQuery();
        if (! $query) {
            $this->error('No valid video IDs were provided.');

            return self::FAILURE;
        }

        $this->info('AVDB translation backfill started.');
        $this->line('Locales: '.implode(', ', $locales));
        $this->line("Chunk: {$chunkSize} IDs | concurrency: {$concurrency} locales | timeout: {$timeout}s");
        $this->line('Mode: '.($overwrite ? 'overwrite all existing rows' : ($replacePlaceholders ? 'create missing and replace base-title placeholders' : 'create missing rows only')));
        if ($dryRun) {
            $this->line('Dry run: database writes disabled.');
        }

        $remaining = $limit;
        $query->orderBy('id')->chunkById($chunkSize, function (EloquentCollection $videos) use (
            $locales,
            $chunkSize,
            $concurrency,
            $timeout,
            $delay,
            $overwrite,
            $replacePlaceholders,
            $dryRun,
            $limit,
            &$remaining
        ) {
            if ($limit > 0) {
                if ($remaining <= 0) {
                    return false;
                }

                $videos = $videos->take($remaining);
            }

            if ($videos->isEmpty()) {
                return $limit <= 0 || $remaining > 0;
            }

            $this->processVideoChunk($videos, $locales, $concurrency, $timeout, $overwrite, $replacePlaceholders, $dryRun);
            $this->processed += $videos->count();

            if ($limit > 0) {
                $remaining -= $videos->count();
            }

            $this->line(sprintf(
                'Processed %d video(s): created %d, updated %d, skipped %d, failed batches %d',
                $this->processed,
                $this->created,
                $this->updated,
                $this->skipped,
                $this->failedBatches
            ));

            if ($delay > 0) {
                usleep($delay * 1000);
            }

            return $limit <= 0 || $remaining > 0;
        });

        $this->info(sprintf(
            'Translation backfill completed: %d video(s), %d created, %d updated, %d skipped, %d failed batch(es).',
            $this->processed,
            $this->created,
            $this->updated,
            $this->skipped,
            $this->failedBatches
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function resolveLocales(): array
    {
        $locales = (bool) $this->option('all')
            ? self::SUPPORTED_LOCALES
            : (preg_split('/[,\s]+/', (string) $this->option('locales')) ?: []);

        $locales = array_map(fn ($locale) => $this->normalizeLocale($locale), $locales);
        $locales = array_filter($locales, fn ($locale) => $locale !== null && $locale !== 'en');

        return array_values(array_unique($locales));
    }

    private function videoQuery(): ?\Illuminate\Database\Eloquent\Builder
    {
        $videoIds = array_merge(
            $this->parseIds((string) $this->option('video-ids')),
            $this->readIdsFile((string) $this->option('video-id-file'))
        );
        $avdbIds = array_merge(
            $this->parseIds((string) $this->option('ids')),
            $this->readIdsFile((string) $this->option('avdb-id-file'))
        );

        $query = Video::query()
            ->select(['id', 'avdb_id', 'title', 'description'])
            ->whereNotNull('avdb_id');

        if ($videoIds !== []) {
            return $query->whereIn('id', array_values(array_unique($videoIds)));
        }

        if ($avdbIds !== []) {
            return $query->whereIn('avdb_id', array_values(array_unique($avdbIds)));
        }

        return $query;
    }

    /**
     * @param  EloquentCollection<int, Video>  $videos
     * @param  array<int, string>  $locales
     */
    private function processVideoChunk(
        EloquentCollection $videos,
        array $locales,
        int $concurrency,
        int $timeout,
        bool $overwrite,
        bool $replacePlaceholders,
        bool $dryRun
    ): void {
        $videosByAvdbId = [];
        $videoIds = [];

        foreach ($videos as $video) {
            $avdbId = (int) $video->avdb_id;
            if ($avdbId <= 0) {
                continue;
            }

            $videosByAvdbId[$avdbId] = $video;
            $videoIds[] = (int) $video->id;
        }

        if ($videosByAvdbId === []) {
            return;
        }

        $existingRows = DB::table('video_translations')
            ->whereIn('video_id', $videoIds)
            ->whereIn('locale', $locales)
            ->get(['video_id', 'locale', 'title'])
            ->keyBy(fn ($row) => (int) $row->video_id.'|'.(string) $row->locale);

        foreach (array_chunk($locales, $concurrency) as $localeGroup) {
            $itemsByLocale = $this->fetchLocaleGroupItems($localeGroup, array_keys($videosByAvdbId), $timeout);
            $rows = [];

            foreach ($localeGroup as $locale) {
                $items = $itemsByLocale[$locale] ?? null;
                if ($items === null) {
                    $this->failedBatches++;
                    continue;
                }

                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $avdbId = (int) ($item['id'] ?? 0);
                    $video = $videosByAvdbId[$avdbId] ?? null;
                    if (! $video) {
                        continue;
                    }

                    $translation = $this->translationFromItem($item, $video->getRawOriginal('title') ?? $video->title ?? '');
                    if ($translation === null) {
                        continue;
                    }

                    $key = (int) $video->id.'|'.$locale;
                    $existing = $existingRows->get($key);
                    if (! $this->shouldSave($existing, $video, $overwrite, $replacePlaceholders)) {
                        $this->skipped++;
                        continue;
                    }

                    if ($existing) {
                        $this->updated++;
                    } else {
                        $this->created++;
                    }

                    $row = [
                        'video_id' => (int) $video->id,
                        'locale' => $locale,
                        'title' => $translation['title'],
                        'description' => $translation['description'] ?: null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if ($this->hasCountryColumn) {
                        $row['country'] = $translation['country'] ?: null;
                    }

                    if ($this->hasTypeNameColumn) {
                        $row['type_name'] = $translation['type_name'] ?: null;
                    }

                    $rows[] = $row;
                    $existingRows->put($key, (object) [
                        'video_id' => (int) $video->id,
                        'locale' => $locale,
                        'title' => $translation['title'],
                    ]);
                }
            }

            if ($rows !== [] && ! $dryRun) {
                $validVideoIds = DB::table('videos')
                    ->whereIn('id', array_values(array_unique(array_column($rows, 'video_id'))))
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->flip();

                $rows = array_values(array_filter(
                    $rows,
                    fn ($row) => isset($validVideoIds[(int) $row['video_id']])
                ));
            }

            if ($rows !== [] && ! $dryRun) {
                $updateColumns = ['title', 'description', 'updated_at'];
                if ($this->hasCountryColumn) {
                    $updateColumns[] = 'country';
                }

                if ($this->hasTypeNameColumn) {
                    $updateColumns[] = 'type_name';
                }

                DB::table('video_translations')->upsert($rows, ['video_id', 'locale'], $updateColumns);
            }
        }
    }

    /**
     * @param  array<int, string>  $locales
     * @param  array<int, int>  $avdbIds
     * @return array<string, array<int, array<string, mixed>>|null>
     */
    private function fetchLocaleGroupItems(array $locales, array $avdbIds, int $timeout): array
    {
        $result = array_fill_keys($locales, null);

        try {
            $responses = Http::pool(function (Pool $pool) use ($locales, $avdbIds, $timeout) {
                foreach ($locales as $locale) {
                    $pool->as($locale)
                        ->timeout($timeout)
                        ->retry(self::HTTP_RETRIES, 300, null, false)
                        ->withOptions(['verify' => false])
                        ->get($this->apiBaseForLocale($locale), [
                            'ac' => 'detail',
                            'ids' => implode(',', $avdbIds),
                        ]);
                }
            });
        } catch (\Throwable) {
            $responses = [];
        }

        foreach ($locales as $locale) {
            $response = $responses[$locale] ?? null;
            if (is_object($response) && method_exists($response, 'successful') && $response->successful()) {
                $items = $response->json('list');
                $result[$locale] = is_array($items) ? $items : [];
                continue;
            }

            $result[$locale] = $this->fetchLocaleItemsAdaptively($locale, $avdbIds, $timeout);
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $avdbIds
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchLocaleItemsAdaptively(string $locale, array $avdbIds, int $timeout): ?array
    {
        $items = $this->fetchLocaleItems($locale, $avdbIds, $timeout, false);
        if ($items !== null || count($avdbIds) <= 1) {
            return $items;
        }

        $merged = [];
        $received = false;
        foreach (array_chunk($avdbIds, self::FALLBACK_ID_CHUNK_SIZE) as $chunk) {
            $items = $this->fetchLocaleItems($locale, $chunk, $timeout, false);
            if ($items === null) {
                foreach ($chunk as $id) {
                    $single = $this->fetchLocaleItems($locale, [$id], $timeout, false);
                    if ($single === null) {
                        continue;
                    }

                    array_push($merged, ...$single);
                    $received = true;
                }
                continue;
            }

            array_push($merged, ...$items);
            $received = true;
        }

        return $received ? $merged : null;
    }

    /**
     * @param  array<int, int>  $avdbIds
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchLocaleItems(string $locale, array $avdbIds, int $timeout, bool $retry = true): ?array
    {
        try {
            $request = Http::timeout($timeout)->withOptions(['verify' => false]);
            if ($retry) {
                $request = $request->retry(self::HTTP_RETRIES, 300, null, false);
            }

            $response = $request->get($this->apiBaseForLocale($locale), [
                'ac' => 'detail',
                'ids' => implode(',', $avdbIds),
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

    private function shouldSave(?object $existing, Video $video, bool $overwrite, bool $replacePlaceholders): bool
    {
        if (! $existing) {
            return true;
        }

        if ($overwrite) {
            return true;
        }

        return $replacePlaceholders && $this->isPlaceholderRow($existing, $video);
    }

    private function isPlaceholderRow(object $row, Video $video): bool
    {
        if ($this->cleanText($row->title ?? '') === '') {
            return true;
        }

        return $this->sameCleanText($row->title ?? '', $video->getRawOriginal('title') ?? $video->title ?? '');
    }

    /**
     * @return array<string, string>|null
     */
    private function translationFromItem(array $item, ?string $baseTitle = null): ?array
    {
        $title = $this->cleanText($item['name'] ?? '');
        if ($title === '') {
            return null;
        }

        if ($baseTitle !== null && $this->sameCleanText($title, $baseTitle)) {
            return null;
        }

        return [
            'title' => Str::limit($title, 497, ''),
            'description' => $this->cleanDescription($item['description'] ?? ''),
            'country' => $this->cleanText($this->arrayToString($item['country'] ?? '')),
            'type_name' => $this->cleanText($this->arrayToString($item['type_name'] ?? '')),
        ];
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
            return 'https://avdbapi.com/api.php/provide/vod';
        }

        $validationUrl = str_replace('{locale}', 'en', $url);
        if (! filter_var($validationUrl, FILTER_VALIDATE_URL)) {
            return 'https://avdbapi.com/api.php/provide/vod';
        }

        $scheme = strtolower((string) parse_url($validationUrl, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : 'https://avdbapi.com/api.php/provide/vod';
    }

    private function normalizeLocale(mixed $locale): ?string
    {
        $locale = strtolower(trim((string) $locale));
        if ($locale === '' || ! preg_match('/^[a-z]{2,5}$/', $locale)) {
            return null;
        }

        return $locale;
    }

    private function cleanText(mixed $value): string
    {
        return html_entity_decode(trim($this->arrayToString($value)), ENT_QUOTES, 'UTF-8');
    }

    private function cleanDescription(mixed $value): string
    {
        return html_entity_decode(trim(strip_tags($this->arrayToString($value))), ENT_QUOTES, 'UTF-8');
    }

    private function arrayToString(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_filter(array_map(fn ($item) => is_scalar($item) ? (string) $item : '', $value)));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return array<int, int>
     */
    private function parseIds(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        preg_match_all('/\d+/', $raw, $matches);

        return array_values(array_unique(array_filter(array_map('intval', $matches[0] ?? []), fn ($id) => $id > 0)));
    }

    /**
     * @return array<int, int>
     */
    private function readIdsFile(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            return [];
        }

        $resolved = $this->resolvePath($path);
        if ($resolved === null || ! is_file($resolved)) {
            return [];
        }

        $contents = @file_get_contents($resolved);
        if (! is_string($contents) || $contents === '') {
            return [];
        }

        return $this->parseIds($contents);
    }

    private function resolvePath(string $path): ?string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        $basePath = base_path($path);
        if (is_file($basePath)) {
            return $basePath;
        }

        $storagePath = storage_path($path);
        if (is_file($storagePath)) {
            return $storagePath;
        }

        return null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
