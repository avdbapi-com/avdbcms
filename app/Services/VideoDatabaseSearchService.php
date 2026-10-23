<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VideoDatabaseSearchService
{
    private const FULLTEXT_INDEX_EXTENDED = 'videos_search_fulltext';
    private const FULLTEXT_INDEX_BASIC = 'videos_title_description_fulltext';

    public function apply(Builder $query, string $term, ?bool $tagMatched = null): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query->whereRaw('1 = 0');
        }

        $tagMatched ??= $this->hasTagMatch($term);

        if ($tagMatched) {
            return $this->applyTagFilter($query, $term);
        }

        if ($this->fullTextColumns() !== []) {
            return $this->fullTextSearchFast($query, $term);
        }

        return $this->likeSearch($query, $term);
    }

    /**
     * Restrict a query to videos carrying a matching tag.
     *
     * This is intentionally separate from the full text path. Tag clicks are
     * exact intent and a relation filter lets MySQL use the video_tags/tag
     * indexes without forcing a full text query to scan the video table.
     */
    public function applyTagFilter(Builder $query, string $term): Builder
    {
        $terms = $this->searchTerms($term);

        if ($terms === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('tags', function (Builder $query) use ($terms): void {
            $this->applyTagTermConditions($query, $terms, false);
        });
    }

    /**
     * Check whether a query names an existing tag in the database.
     *
     * Search engines can contain stale or partially indexed tag fields. A
     * known tag therefore stays on the database path until the external index
     * is rebuilt.
     */
    public function hasTagMatch(string $term): bool
    {
        $terms = $this->searchTerms($term);

        if ($terms === []) {
            return false;
        }

        return \App\Models\Tag::query()
            ->where(function (Builder $query) use ($terms): void {
                $this->applyTagTermConditions($query, $terms, false);
            })
            ->exists();
    }

    /**
     * FULLTEXT search using BOOLEAN MODE with wildcard support.
     *
     * Previously used NATURAL LANGUAGE MODE which only matches exact whole
     * words (e.g. "xem" would NOT match "Xemmmm"). BOOLEAN MODE with the
     * trailing wildcard (*) supports prefix/partial matching so "xem*"
     * will correctly match "Xemmmm", "xem-phim", etc.
     *
     * If FULLTEXT yields zero rows we transparently fall back to LIKE
     * so the user always gets the best possible results.
     */
    private function fullTextSearch(Builder $query, string $term): Builder
    {
        // Build a boolean-mode expression: each word gets a trailing wildcard
        // so "xem phim" becomes "+xem* +phim*"
        $booleanTerm = $this->buildBooleanTerm($term);
        // Clone the query to check if FULLTEXT produces results
        $ftQuery = (clone $query)
            ->where(function (Builder $query) use ($booleanTerm): void {
                $query->whereRaw(
                    'MATCH(videos.title, videos.description) AGAINST (? IN BOOLEAN MODE)',
                    [$booleanTerm]
                );
            });

        $hasFullTextResults = $ftQuery->limit(1)->exists();

        if ($hasFullTextResults) {
            $query
                ->select('videos.*')
                ->selectRaw(
                    'MATCH(videos.title, videos.description) AGAINST (? IN BOOLEAN MODE) AS search_score',
                    [$booleanTerm]
                )
                ->where(function (Builder $query) use ($booleanTerm): void {
                    $query->whereRaw(
                        'MATCH(videos.title, videos.description) AGAINST (? IN BOOLEAN MODE)',
                        [$booleanTerm]
                    );
                })
                ->orderByDesc('search_score');

            return $query;
        }

        // FULLTEXT found nothing — fall back to LIKE for partial matching
        return $this->likeSearch($query, $term);
    }

    /**
     * Build a BOOLEAN MODE search expression with trailing wildcards.
     *
     * Input:  "xem phim"
     * Output: "+xem* +phim*"
     *
     * Each word is required (+) and prefix-expanded (*).
     */
    private function buildBooleanTerm(string $term): string
    {
        // Strip FULLTEXT special characters to prevent injection
        $cleaned = preg_replace('/[+\-><()~*"@]+/', ' ', $term);
        $words = array_filter(preg_split('/\s+/', trim($cleaned)));

        if (empty($words)) {
            return $term . '*';
        }

        // For single short words, just use the wildcard without requiring
        return count($words) === 1
            ? $words[0] . '*'
            : implode(' ', array_map(fn($w) => '+' . $w . '*', $words));
    }

    private function fullTextSearchFast(Builder $query, string $term): Builder
    {
        $columns = $this->fullTextColumns();
        $booleanTerms = $this->booleanTerms($term);
        $indexName = $this->fullTextIndexName();

        if ($columns === [] || $booleanTerms === []) {
            return $this->exactSearch($query, $term);
        }

        if ($indexName !== null && DB::connection()->getDriverName() === 'mysql') {
            $query->forceIndex($indexName);
        }

        $match = $this->matchExpression($columns);
        $ftQuery = (clone $query)->where(function (Builder $query) use ($match, $booleanTerms): void {
            foreach ($booleanTerms as $index => $booleanTerm) {
                $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                $query->{$method}("{$match} AGAINST (? IN BOOLEAN MODE)", [$booleanTerm]);
            }
        });

        if ($ftQuery->limit(1)->exists()) {
            $scoreSql = implode(' + ', array_fill(0, count($booleanTerms), "{$match} AGAINST (? IN BOOLEAN MODE)"));

            return $query
                ->select('videos.*')
                ->selectRaw("({$scoreSql}) AS search_score", $booleanTerms)
                ->where(function (Builder $query) use ($match, $booleanTerms): void {
                    foreach ($booleanTerms as $index => $booleanTerm) {
                        $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                        $query->{$method}("{$match} AGAINST (? IN BOOLEAN MODE)", [$booleanTerm]);
                    }
                })
                ->orderByDesc('search_score');
        }

        return $this->exactSearch($query, $term);
    }

    /**
     * @return array<int, string>
     */
    private function booleanTerms(string $term): array
    {
        $terms = $this->searchTerms($term);
        usort($terms, fn (string $a, string $b): int => $this->termSearchWeight($b) <=> $this->termSearchWeight($a));
        $terms = array_slice($terms, 0, 1);

        return array_values(array_unique(array_filter(
            array_map(fn (string $term): string => $this->buildBooleanTerm($term), $terms),
            fn (string $term): bool => $term !== ''
        )));
    }

    private function termSearchWeight(string $term): int
    {
        $weight = preg_match('/[A-Za-z0-9]/', $term) ? 10 : 0;
        $length = mb_strlen(trim($term), 'UTF-8');

        return $weight + min(5, max(0, $length));
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function matchExpression(array $columns): string
    {
        return 'MATCH('.implode(', ', array_map(fn (string $column): string => "videos.{$column}", $columns)).')';
    }

    private function exactSearch(Builder $query, string $term): Builder
    {
        $terms = array_slice($this->searchTerms($term), 0, 5);

        return $query->where(function (Builder $query) use ($terms): void {
            foreach ($terms as $index => $searchTerm) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $query->{$method}(function (Builder $query) use ($searchTerm): void {
                    // Every value the watch page links to search (code, actor, studio, director, country,
                    // quality, category, channel) must find its videos when FULLTEXT has no match.
                    $query
                        ->where('avdb_code', $searchTerm)
                        ->orWhere('xvid_code', $searchTerm)
                        ->orWhere('title', $searchTerm)
                        ->orWhere('avdb_actor', $searchTerm)
                        ->orWhere('xvid_actor', $searchTerm)
                        ->orWhere('avdb_director', $searchTerm)
                        ->orWhere('avdb_writer', $searchTerm)
                        ->orWhere('xvid_author', $searchTerm)
                        ->orWhere('avdb_country', $searchTerm)
                        ->orWhere('xvid_quality', $searchTerm)
                        ->orWhereHas('category', fn (Builder $query) => $query->where('title', $searchTerm))
                        ->orWhereHas('user', fn (Builder $query) => $query->where('username', $searchTerm));
                });
            }

        });
    }

    /**
     * Fallback LIKE-based search for partial matching.
     * Always works regardless of FULLTEXT index availability.
     */
    private function likeSearch(Builder $query, string $term): Builder
    {
        $terms = $this->searchTerms($term);

        return $query->where(function (Builder $query) use ($terms) {
            foreach ($terms as $index => $searchTerm) {
                $needle = $this->likeNeedle($searchTerm);
                $method = $index === 0 ? 'where' : 'orWhere';

                $query->{$method}(function (Builder $query) use ($needle) {
                    $query
                        ->where('title', 'like', $needle)
                        ->orWhere('description', 'like', $needle)
                        ->orWhere('avdb_code', 'like', $needle)
                        ->orWhere('xvid_code', 'like', $needle)
                        ->orWhere('avdb_actor', 'like', $needle)
                        ->orWhere('xvid_actor', 'like', $needle)
                        ->orWhere('avdb_director', 'like', $needle)
                        ->orWhere('avdb_writer', 'like', $needle)
                        ->orWhere('xvid_author', 'like', $needle)
                        ->orWhere('avdb_country', 'like', $needle)
                        ->orWhere('xvid_quality', 'like', $needle)
                        ->orWhereHas('user', fn (Builder $query) => $query->where('username', 'like', $needle))
                        ->orWhereHas('category', fn (Builder $query) => $query->where('title', 'like', $needle));
                });
            }

            $this->orWhereTagMatches($query, $terms);
        });
    }

    private function orWhereTagMatches(Builder $query, array $terms): void
    {
        if ($terms === []) {
            return;
        }

        $query->orWhereHas('tags', function (Builder $query) use ($terms): void {
            $this->applyTagTermConditions($query, $terms);
        });
    }

    /**
     * Apply tag name, slug, and translated-name matching to a Tag query.
     */
    private function applyTagTermConditions(Builder $query, array $terms, bool $partial = true): void
    {
        $query->where(function (Builder $query) use ($terms, $partial): void {
            foreach ($terms as $index => $searchTerm) {
                $slug = Str::slug($searchTerm);
                $method = $index === 0 ? 'where' : 'orWhere';

                $query->{$method}(function (Builder $query) use ($searchTerm, $slug, $partial): void {
                    if ($partial) {
                        $needle = $this->likeNeedle($searchTerm);
                        $slugNeedle = $slug !== '' ? $this->likeNeedle($slug) : null;

                        $query
                            ->where('tags.name', 'like', $needle)
                            ->when($slugNeedle !== null, fn (Builder $query) => $query->orWhere('tags.slug', 'like', $slugNeedle))
                            ->orWhereHas('translations', fn (Builder $query) => $query->where('name', 'like', $needle));

                        return;
                    }

                    $query
                        ->whereRaw('LOWER(tags.name) = LOWER(?)', [$searchTerm])
                        ->when($slug !== '', fn (Builder $query) => $query->orWhere('tags.slug', $slug))
                        ->orWhereHas('translations', fn (Builder $query) => $query->whereRaw('LOWER(name) = LOWER(?)', [$searchTerm]));
                });
            }
        });
    }

    private function searchTerms(string $term): array
    {
        $terms = $this->splitTerm($term);
        $lookup = $this->localizedJsonLookup(app()->getLocale());

        foreach ($terms as $candidate) {
            foreach ($lookup[$this->normalizeTerm($candidate)] ?? [] as $source) {
                $terms[] = $source;
            }
        }

        return array_values(array_unique(array_filter(
            array_map(fn (string $value) => trim($value), $terms),
            fn (string $value) => $value !== ''
        )));
    }

    /**
     * Split translated labels such as "Transgender / Waria" while keeping the
     * complete query available for normal phrase search.
     *
     * @return array<int, string>
     */
    private function splitTerm(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $parts = preg_split('/[\/|,;]+/u', $term) ?: [];

        return array_values(array_unique(array_filter(
            array_merge([$term], array_map('trim', $parts)),
            fn (string $value) => $value !== ''
        )));
    }

    /**
     * Build a reverse lookup so a localized JSON tag label can find the English
     * tag stored in the database when tag_translations has no row yet.
     *
     * @return array<string, array<int, string>>
     */
    private function localizedJsonLookup(string $locale): array
    {
        static $lookups = [];

        if ($locale === 'en' || $locale === config('app.fallback_locale', 'en')) {
            return [];
        }

        if (array_key_exists($locale, $lookups)) {
            return $lookups[$locale];
        }

        $path = base_path("lang/{$locale}.json");
        if (!is_file($path)) {
            return $lookups[$locale] = [];
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return $lookups[$locale] = [];
        }

        $lookup = [];
        foreach ($data as $source => $translated) {
            if (!is_string($source) || !is_string($translated) || $source === '' || $translated === '') {
                continue;
            }

            foreach (array_merge($this->splitTerm($source), $this->splitTerm($translated)) as $label) {
                $normalized = $this->normalizeTerm($label);
                if ($normalized !== '') {
                    $lookup[$normalized][] = $source;
                }
            }
        }

        foreach ($lookup as $key => $sources) {
            $lookup[$key] = array_values(array_unique($sources));
        }

        return $lookups[$locale] = $lookup;
    }

    private function normalizeTerm(string $term): string
    {
        $term = mb_strtolower(trim($term), 'UTF-8');
        $term = preg_replace('/\s+/u', ' ', $term) ?: $term;

        return $term;
    }

    private function likeNeedle(string $term): string
    {
        return '%' . addcslashes($term, '\\%_') . '%';
    }

    /**
     * @return array<int, string>
     */
    private function fullTextColumns(): array
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return [];
        }

        return Cache::remember('database.videos_fulltext_columns', now()->addMinutes(10), function () {
            if (count(DB::select('SHOW INDEX FROM `videos` WHERE Key_name = ?', [self::FULLTEXT_INDEX_EXTENDED])) > 0) {
                return ['title', 'description', 'avdb_code', 'avdb_actor', 'avdb_director'];
            }

            if (count(DB::select('SHOW INDEX FROM `videos` WHERE Key_name = ?', [self::FULLTEXT_INDEX_BASIC])) > 0) {
                return ['title', 'description'];
            }

            return [];
        });
    }

    private function fullTextIndexName(): ?string
    {
        $columns = $this->fullTextColumns();

        if (in_array('avdb_code', $columns, true)) {
            return self::FULLTEXT_INDEX_EXTENDED;
        }

        return $columns !== [] ? self::FULLTEXT_INDEX_BASIC : null;
    }
}
