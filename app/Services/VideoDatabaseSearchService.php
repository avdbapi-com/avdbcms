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

    /** The most tags a query's exact terms can name (a name, an address or a translation's name each). */
    private const EXACT_TAGS = 1000;

    /** @var array<string, list<int>> per query: the ids of the tags visitors see that its terms name exactly */
    private array $exactTags = [];

    /** @var array<string, list<int>> per query: the ids of the hidden tags its terms match in part */
    private array $hiddenPartialTags = [];

    /** @var list<int>|null the ids of the categories visitors never see, read once per search */
    private ?array $hiddenCategories = null;

    /** Whether findsVideos() runs: no match by a part of a tag or category name (see there). */
    private bool $probe = false;

    /**
     * Whether a search for $term finds a video of $query, in the queries the check of the Ctrl+K chips ran before 1.2.3
     * round 3 (App\Support\SpotlightChips: on a cold cache of every page, once an hour per language): a tag the term
     * names exactly (never a hidden one, exactTagIds()), the video's own fields (FULLTEXT, or LIKE on a site without it),
     * a category whose title is the term. A match by a part of a tag or category name is not counted (it would first
     * read which of those names visitors never see): a chip whose only matches are such parts stays hidden, so a chip
     * never opens an empty search page, and no hidden name is ever a key.
     */
    public function findsVideos(Builder $query, string $term, bool $bounded = true): bool
    {
        $term = trim($term);
        $this->probeCut = false;

        if ($term === '') {
            return false;
        }

        $this->probe = true;
        $this->probeBounded = $bounded;

        try {
            return $this->apply($query, $term, $this->hasTagMatch($term))->exists();
        } finally {
            $this->probe = false;
            $this->probeBounded = true;
        }
    }

    /**
     * Whether the last findsVideos() read only the newest PROBE_ROWS videos where no index served the match
     * (newestOnly()): its "no" is then not the last word (an older video may match; App\Support\SpotlightChips asks the
     * background for the whole check).
     */
    public function lastCheckWasBounded(): bool
    {
        return $this->probeCut;
    }

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
        $ids = $this->exactTagIds($term);

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('tags', fn (Builder $query) => $query->whereIn('tags.id', $ids));
    }

    /**
     * Check whether a query names an existing tag in the database.
     *
     * Search engines can contain stale or partially indexed tag fields. A
     * known tag therefore stays on the database path until the external index
     * is rebuilt.
     *
     * 1.2.3 round 3 (R3-004): a tag visitors never see (its name uses a word that is always blocked) is no known tag:
     * its query goes where no hidden tag is (the external index leaves them out, Video::toSearchableArray()).
     */
    public function hasTagMatch(string $term): bool
    {
        return $this->exactTagIds($term) !== [];
    }

    /**
     * The ids of the tags a query names exactly (a term is a tag's name, its address or one of its translations), never
     * a tag visitors never see (App\Support\ContentSafety::hides(): its name uses a word that is always blocked; not by
     * its translations either) and never by a term that itself uses such a word. One query per search.
     *
     * @return list<int>
     */
    private function exactTagIds(string $term): array
    {
        $terms = $this->keyTerms($this->searchTerms($term));
        $key = app()->getLocale()."\x1F".implode("\x1F", $terms);

        if (array_key_exists($key, $this->exactTags)) {
            return $this->exactTags[$key];
        }

        if ($terms === []) {
            return $this->exactTags[$key] = [];
        }

        return $this->exactTags[$key] = \App\Models\Tag::query()
            ->where(function (Builder $query) use ($terms): void {
                $this->applyTagTermConditions($query, $terms, false);
            })
            ->orderBy('tags.id')
            ->limit(self::EXACT_TAGS)
            ->get(['tags.id', 'tags.name'])
            ->reject(fn ($tag): bool => \App\Support\ContentSafety::hides((string) $tag->name))
            ->map(fn ($tag): int => (int) $tag->id)
            ->values()
            ->all();
    }

    /**
     * The ids of the tags visitors never see that a query's terms match in part (the LIKE search of a site without a
     * FULLTEXT index): left out of the match. Their names are read as they come (a cursor of the matching tags, ids and
     * names only).
     *
     * @param  array<int, string>  $terms
     * @return list<int>
     */
    private function hiddenPartialTagIds(array $terms): array
    {
        $key = implode("\x1F", $terms);

        if (array_key_exists($key, $this->hiddenPartialTags)) {
            return $this->hiddenPartialTags[$key];
        }

        $hidden = [];

        foreach (\App\Models\Tag::query()
            ->where(function (Builder $query) use ($terms): void {
                $this->applyTagTermConditions($query, $terms);
            })
            ->select(['tags.id', 'tags.name'])
            ->lazyById(2000, 'tags.id', 'id') as $tag) {
            if (\App\Support\ContentSafety::hides((string) $tag->name)) {
                $hidden[] = (int) $tag->id;
            }
        }

        return $this->hiddenPartialTags[$key] = $hidden;
    }

    /**
     * The terms that may name a tag or a category: never one that uses a word that is always blocked (a hidden tag's or
     * category's own name is one; App\Support\ContentSafety).
     *
     * @param  array<int, string>  $terms
     * @return list<string>
     */
    private function keyTerms(array $terms): array
    {
        return array_values(array_filter($terms, fn (string $term): bool => ! \App\Support\ContentSafety::hides($term)));
    }

    /** @return list<int> the ids of the categories visitors never see (their name uses a word that is always blocked) */
    private function hiddenCategoryIds(): array
    {
        return $this->hiddenCategories ??= \App\Models\Category::query()
            ->get(['id', 'title'])
            ->filter(fn ($category): bool => \App\Support\ContentSafety::hides((string) $category->title))
            ->map(fn ($category): int => (int) $category->id)
            ->values()
            ->all();
    }

    /**
     * The videos of a category whose title the term is (or contains, $partial): never a category visitors never see, and
     * never by a term that itself uses a word that is always blocked (1.2.3 round 3, with R3-004).
     */
    private function orWhereCategoryTitle(Builder $query, string $searchTerm, bool $partial): Builder
    {
        if ($this->keyTerms([$searchTerm]) === []) {
            return $query;
        }

        if ($this->probe) {
            // findsVideos(): a title equal to a term that uses no blocked word is no hidden category's; no part of a title
            return $partial ? $query : $query->orWhereHas('category', fn (Builder $query) => $query->where('title', $searchTerm));
        }

        $hidden = $this->hiddenCategoryIds();

        return $query->orWhereHas('category', fn (Builder $query) => $query
            ->where('title', $partial ? 'like' : '=', $partial ? $this->likeNeedle($searchTerm) : $searchTerm)
            ->when($hidden !== [], fn (Builder $query) => $query->whereNotIn('categories.id', $hidden)));
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

    /**
     * The newest videos findsVideos() (the Ctrl+K chips' check) reads the fields of where no index serves the match (the
     * exact and LIKE fallbacks read every row: at 300,000 videos 0.6-0.9 s per chip on MariaDB and MySQL 8.0, 2.5-4.6 s
     * on MySQL 8.4; look-around DB-X2), unless it is asked for the whole check ($bounded false: the scheduler's task,
     * App\Support\DiscoverySnapshots): a chip whose only matches are such fields of older videos stays hidden until that
     * check found them, so a chip never opens an empty search page. The search page itself reads them all.
     */
    private const PROBE_ROWS = 2000;

    /** The lowest video id findsVideos() reads the fields of (newestOnly()), worked out once. */
    private ?int $probeFloor = null;

    /** Whether findsVideos() reads only the newest PROBE_ROWS videos where no index serves the match (its $bounded). */
    private bool $probeBounded = true;

    /** Whether the last findsVideos() left older videos unread (lastCheckWasBounded()). */
    private bool $probeCut = false;

    /** findsVideos(): the newest PROBE_ROWS videos only, by their key (MySQL / MariaDB: one MAX(id) per check). */
    private function newestOnly(Builder $query): void
    {
        if (! $this->probe || ! $this->probeBounded || ! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $this->probeFloor ??= max(0, (int) DB::table('videos')->max('id') - self::PROBE_ROWS);

        if ($this->probeFloor > 0) {
            $this->probeCut = true;
            $query->where('videos.id', '>', $this->probeFloor);
        }
    }

    private function fullTextSearchFast(Builder $query, string $term): Builder
    {
        $columns = $this->fullTextColumns();
        $booleanTerms = $this->booleanTerms($term);
        $indexName = $this->fullTextIndexName();

        if ($columns === [] || $booleanTerms === []) {
            return $this->exactSearch($query, $term);
        }

        // the FULLTEXT index is named for the queries that match by it, not for the exact fallback below: an index named
        // for a query it cannot serve made MySQL read every row there whatever else the query asked (DB-X2)
        $fullText = $indexName !== null && DB::connection()->getDriverName() === 'mysql'
            ? fn (Builder $query): Builder => $query->forceIndex($indexName)
            : fn (Builder $query): Builder => $query;

        $match = $this->matchExpression($columns);
        $ftQuery = $fullText(clone $query)->where(function (Builder $query) use ($match, $booleanTerms): void {
            foreach ($booleanTerms as $index => $booleanTerm) {
                $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                $query->{$method}("{$match} AGAINST (? IN BOOLEAN MODE)", [$booleanTerm]);
            }
        });

        if ($ftQuery->limit(1)->exists()) {
            $scoreSql = implode(' + ', array_fill(0, count($booleanTerms), "{$match} AGAINST (? IN BOOLEAN MODE)"));

            return $fullText($query)
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
        $this->newestOnly($query);

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
                        ->tap(fn (Builder $query) => $this->orWhereCategoryTitle($query, $searchTerm, false))
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
        $this->newestOnly($query);

        return $query->where(function (Builder $query) use ($terms) {
            foreach ($terms as $index => $searchTerm) {
                $needle = $this->likeNeedle($searchTerm);
                $method = $index === 0 ? 'where' : 'orWhere';

                $query->{$method}(function (Builder $query) use ($needle, $searchTerm) {
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
                        ->tap(fn (Builder $query) => $this->orWhereCategoryTitle($query, $searchTerm, true));
                });
            }

            $this->orWhereTagMatches($query, $terms);
        });
    }

    private function orWhereTagMatches(Builder $query, array $terms): void
    {
        // 1.2.3 round 3 (R3-004): never by a term that uses a word that is always blocked, never a hidden tag
        $terms = $this->keyTerms($terms);

        // findsVideos(): no match by a part of a tag name (the exact names went through exactTagIds())
        if ($terms === [] || $this->probe) {
            return;
        }

        $hidden = $this->hiddenPartialTagIds($terms);

        $query->orWhereHas('tags', function (Builder $query) use ($terms, $hidden): void {
            $this->applyTagTermConditions($query, $terms);

            if ($hidden !== []) {
                $query->whereNotIn('tags.id', $hidden);
            }
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

                    // the translations' names once for the query (an IN list), not once for every tag (MySQL 8 ran that
                    // EXISTS for each of 20,000 tags: 0.14 s per search and per Ctrl+K chip; DB-X2)
                    $query
                        ->whereRaw('LOWER(tags.name) = LOWER(?)', [$searchTerm])
                        ->when($slug !== '', fn (Builder $query) => $query->orWhere('tags.slug', $slug))
                        ->orWhereIn('tags.id', \App\Models\TagTranslation::query()->select('tag_id')->whereRaw('LOWER(name) = LOWER(?)', [$searchTerm]));
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
