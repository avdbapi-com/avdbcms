<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SeoService
{
    public const SEO_TEXT_TOKENS = [
        'site_name',
        'site',
        'site_domain',
        'app_url',
        'locale',
        'year',
        'title',
        'description',
        'category',
        'videos_count',
        'search',
        'username',
        'user',
        'subscribers_count',
        'views_count',
        'playlists_count',
        'video_title',
        'tags',
        'actor',
        'director',
        'code',
    ];

    public const VIDEO_PATTERN_TOKENS = [
        'slug',
        'uuid',
        'id',
        'year',
        'month',
        'day',
        'user',
        'category',
    ];

    public static function templateDefaults(): array
    {
        return [
            'home_meta_title_template' => 'Share and Watch Amazing Videos',
            'home_meta_description_template' => 'Enjoy the videos and music you love, upload original content, and share it all with friends, family, and the world on {site_name}.',
            'trend_meta_title_template' => 'Trending',
            'trend_meta_description_template' => 'The pulse of what is trending on {site_name}. Check out the latest videos that people are watching right now.',
            'category_meta_title_template' => '{category} Videos',
            'category_meta_description_template' => 'Watch {category} videos on {site_name}.',
            'search_meta_title_template' => '{search}',
            'search_meta_description_template' => 'Search results for {search} on {site_name}.',
            'profile_meta_title_template' => '{username}',
            'profile_meta_description_template' => '{description}',
            'video_meta_title_template' => '{video_title}',
            'video_meta_description_template' => '{description}',
            'playlist_meta_title_template' => '{title}',
            'playlist_meta_description_template' => '{description}',
            'premium_meta_title_template' => 'Premium',
            'premium_meta_description_template' => 'Unleash the full potential of your creative journey with our premium plan.',
            'terms_meta_title_template' => 'Terms of Service',
            'terms_meta_description_template' => 'Read the terms of service, account rules, privacy notes, and data policies for using {site_name}.',
        ];
    }

    public static function templateGroups(): array
    {
        return [
            [
                'label' => 'Landing and feed pages',
                'description' => 'Use domain-specific words here so cloned domains do not publish identical home and feed metadata.',
                'fields' => [
                    ['key' => 'home_meta_title_template', 'label' => 'Home Title', 'tokens' => '{site_name}, {site_domain}, {year}'],
                    ['key' => 'home_meta_description_template', 'label' => 'Home Description', 'rows' => 3, 'tokens' => '{site_name}, {site_domain}, {year}'],
                    ['key' => 'trend_meta_title_template', 'label' => 'Trending Title', 'tokens' => '{site_name}, {site_domain}, {year}'],
                    ['key' => 'trend_meta_description_template', 'label' => 'Trending Description', 'rows' => 3, 'tokens' => '{site_name}, {site_domain}, {year}'],
                ],
            ],
            [
                'label' => 'Taxonomy and search pages',
                'description' => 'Category pages are indexable by default. Search pages cover tag-like query pages and stay noindex by default.',
                'fields' => [
                    ['key' => 'category_meta_title_template', 'label' => 'Category Title', 'tokens' => '{category}, {videos_count}, {site_name}, {site_domain}, {year}'],
                    ['key' => 'category_meta_description_template', 'label' => 'Category Description', 'rows' => 3, 'tokens' => '{category}, {videos_count}, {site_name}, {site_domain}, {year}'],
                    ['key' => 'search_meta_title_template', 'label' => 'Search/Tag Title', 'tokens' => '{search}, {site_name}, {site_domain}, {year}'],
                    ['key' => 'search_meta_description_template', 'label' => 'Search/Tag Description', 'rows' => 3, 'tokens' => '{search}, {site_name}, {site_domain}, {year}'],
                ],
            ],
            [
                'label' => 'Content pages',
                'description' => 'Video and playlist templates can include creator, category, tag, actor, director, and code values.',
                'fields' => [
                    ['key' => 'video_meta_title_template', 'label' => 'Video Title', 'tokens' => '{video_title}, {category}, {tags}, {actor}, {director}, {code}, {username}, {site_name}'],
                    ['key' => 'video_meta_description_template', 'label' => 'Video Description', 'rows' => 3, 'tokens' => '{description}, {video_title}, {category}, {tags}, {actor}, {director}, {code}, {username}, {site_name}'],
                    ['key' => 'playlist_meta_title_template', 'label' => 'Playlist Title', 'tokens' => '{title}, {username}, {videos_count}, {site_name}'],
                    ['key' => 'playlist_meta_description_template', 'label' => 'Playlist Description', 'rows' => 3, 'tokens' => '{description}, {title}, {username}, {videos_count}, {site_name}'],
                ],
            ],
            [
                'label' => 'Profile and legal pages',
                'description' => 'Profile templates help each domain describe creator/channel pages differently.',
                'fields' => [
                    ['key' => 'profile_meta_title_template', 'label' => 'Profile Title', 'tokens' => '{username}, {videos_count}, {views_count}, {subscribers_count}, {site_name}'],
                    ['key' => 'profile_meta_description_template', 'label' => 'Profile Description', 'rows' => 3, 'tokens' => '{description}, {username}, {videos_count}, {views_count}, {site_name}'],
                    ['key' => 'premium_meta_title_template', 'label' => 'Premium Title', 'tokens' => '{site_name}, {site_domain}, {year}'],
                    ['key' => 'premium_meta_description_template', 'label' => 'Premium Description', 'rows' => 3, 'tokens' => '{site_name}, {site_domain}, {year}'],
                    ['key' => 'terms_meta_title_template', 'label' => 'Terms Title', 'tokens' => '{site_name}, {site_domain}, {year}'],
                    ['key' => 'terms_meta_description_template', 'label' => 'Terms Description', 'rows' => 3, 'tokens' => '{site_name}, {site_domain}, {year}'],
                ],
            ],
        ];
    }

    public function videoUrl(Video $video, array $query = []): string
    {
        $path = $this->videoPath($video);
        $url = url($path);

        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    public function videoPath(Video $video): string
    {
        $prefix = $this->videoUrlPrefix();
        $pattern = trim((string) config('site.video_url_pattern', '{slug}-{uuid}'), '/');
        $date = $video->date ?? $video->published_at ?? $video->created_at ?? now();

        $values = [
            '{slug}' => $this->videoSlug($video),
            '{uuid}' => $video->uuid,
            '{id}' => (string) $video->id,
            '{year}' => $date->format('Y'),
            '{month}' => $date->format('m'),
            '{day}' => $date->format('d'),
            '{user}' => Str::slug($video->user?->username ?? '') ?: 'creator',
            '{category}' => Str::slug($video->category?->title ?? '') ?: 'videos',
        ];

        return '/' . trim($prefix . '/' . strtr($pattern, $values), '/');
    }

    /**
     * Slug used in the public URL. Titles in scripts without a Latin transliteration (Japanese, Chinese,
     * Thai, Korean, ...) slug to an empty string, which would leave the URL without a usable slug segment.
     */
    public function videoSlug(Video $video): string
    {
        // (string): right after a studio save the attribute can still be an empty Stringable object.
        $slug = trim((string) $video->slug);
        if ($slug !== '') {
            return $slug;
        }

        // Raw stored title, not the localized accessor, so the URL is the same in every locale.
        return Str::slug((string) ($video->getAttributes()['title'] ?? '')) ?: 'video';
    }

    public function videoUrlPrefix(): string
    {
        $prefix = trim((string) config('site.video_url_prefix', 'video'), '/');
        $prefix = preg_replace('/[^a-zA-Z0-9\-\/]/', '', $prefix) ?: 'video';
        $prefix = preg_replace('#/+#', '/', $prefix);
        $prefix = strtolower(trim($prefix, '/'));

        return trim($prefix, '/') ?: 'video';
    }

    public function renderTemplate(string $settingKey, array $values = [], ?string $fallback = null, int $limit = 0): string
    {
        $defaults = self::templateDefaults();
        $template = (string) config('site.' . $settingKey, $defaults[$settingKey] ?? $fallback ?? '');
        $template = trim($template);

        if ($template === '') {
            $template = (string) ($fallback ?? '');
        }

        if (($defaults[$settingKey] ?? null) === $template && $fallback !== null) {
            $template = $fallback;
        } else {
            $template = __($template);
        }

        $rendered = strtr($template, $this->templatePlaceholders($values));
        $rendered = preg_replace('/\{[A-Za-z0-9_]+\}/', '', $rendered) ?? $rendered;
        $rendered = $this->cleanSeoText($rendered, $limit);

        if ($rendered === '' && $fallback !== null) {
            return $this->cleanSeoText($fallback, $limit);
        }

        return $rendered;
    }

    public function videoTemplateValues(Video $video, string $description = ''): array
    {
        $tags = $video->relationLoaded('tags')
            ? $video->tags
            : $video->tags()->with('translations')->limit(20)->get();

        $tagNames = $tags
            ->map(fn ($tag) => $tag->localized_name ?: $tag->name)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'video_title' => $video->title,
            'title' => $video->title,
            'description' => $description,
            'category' => $video->localized_type_name ?: ($video->category?->title ? decode_html_entities($video->category->title) : ''),
            'tags' => $tagNames,
            'actor' => $video->avdb_actor,
            'director' => $video->avdb_director,
            'code' => $video->avdb_code ?: ($video->avdb_id ? $video->original_file_name : ''),
            'username' => $video->user?->username,
            'user' => $video->user?->username,
            'views_count' => (string) (int) $video->views,
        ];
    }

    public function videoPlayerUrl(Video $video): string
    {
        return $video->embed_url ?: route('video.embed', $video);
    }

    public function resolveVideoPath(string $path): ?Video
    {
        $path = '/' . trim($path, '/');
        $prefix = '/' . $this->videoUrlPrefix() . '/';

        if (!Str::startsWith($path, $prefix)) {
            return null;
        }

        $subject = trim(Str::after($path, $prefix), '/');
        $match = $this->matchVideoPattern($subject);

        if (!$match) {
            return null;
        }

        $query = Video::query()->with(['user', 'category']);

        if (!empty($match['id'])) {
            return $query->whereKey((int) $match['id'])->first();
        }

        if (!empty($match['uuid'])) {
            return $query->where('uuid', $match['uuid'])->first();
        }

        return null;
    }

    public function isCanonicalVideoRequest(Video $video): bool
    {
        return '/' . trim(request()->path(), '/') === $this->videoPath($video);
    }

    public function robotsContent(): string
    {
        if (config('site.robots_noindex')) {
            return 'noindex, nofollow';
        }

        $parts = [config('site.robots_nofollow') ? 'index, nofollow' : 'index, follow'];
        $maxSnippet = (int) config('site.robots_max_snippet', -1);
        $maxVideoPreview = (int) config('site.robots_max_video_preview', -1);
        $maxImagePreview = config('site.robots_max_image_preview', 'large');

        $parts[] = 'max-snippet:' . $maxSnippet;
        $parts[] = 'max-image-preview:' . $maxImagePreview;
        $parts[] = 'max-video-preview:' . $maxVideoPreview;

        foreach (['noarchive', 'notranslate', 'noimageindex'] as $directive) {
            if (config('site.robots_' . $directive)) {
                $parts[] = $directive;
            }
        }

        return implode(', ', array_filter($parts));
    }

    public function websiteSchema(): array
    {
        $sameAs = array_values(array_filter([
            config('site.social_facebook'),
            config('site.social_twitter'),
            config('site.social_instagram'),
            config('site.social_youtube'),
            config('site.social_tiktok'),
            config('site.social_discord'),
            config('site.social_telegram'),
        ]));

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => config('site.schema_organization_type', 'Organization'),
            'name' => config('app.name'),
            'url' => url('/'),
        ];

        if (config('site.logo_url')) {
            $schema['logo'] = site_asset_url(config('site.logo_url'));
        }

        if ($sameAs) {
            $schema['sameAs'] = $sameAs;
        }

        return $schema;
    }

    public function searchActionSchema(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => config('app.name'),
            'url' => url('/'),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => url(locale_url(route('search.index'))) . '?q={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    public function videoSchema(Video $video): array
    {
        $description = trim(strip_tags((string) $video->description));
        $duration = (int) $video->getRawOriginal('duration');
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'VideoObject',
            'name' => $video->title,
            'description' => Str::limit($description ?: config('site.default_meta_description', ''), 500, ''),
            'thumbnailUrl' => [site_asset_url($video->thumbnail_url)],
            'uploadDate' => optional($video->uploaded_at ?? $video->created_at)->toAtomString(),
            'datePublished' => optional($video->published_at ?? $video->created_at)->toAtomString(),
            'url' => $this->videoUrl($video),
            'embedUrl' => $this->videoPlayerUrl($video),
            'inLanguage' => $video->language ?: app()->getLocale(),
            'interactionStatistic' => [
                '@type' => 'InteractionCounter',
                'interactionType' => ['@type' => 'WatchAction'],
                'userInteractionCount' => (int) $video->views,
            ],
            'author' => [
                '@type' => 'Person',
                'name' => $video->user?->username ?? config('app.name'),
                'url' => $video->user ? route('user.show', $video->user) : url('/'),
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('app.name'),
                'url' => url('/'),
            ],
        ];

        if ($duration > 0) {
            $schema['duration'] = $this->isoDuration($duration);
        }

        if ($video->file_url) {
            $schema['contentUrl'] = $video->file_url;
        }

        if ($video->category?->title) {
            $categoryTitle = decode_html_entities($video->category->title);
            $schema['genre'] = $video->localized_type_name ?: __($categoryTitle);
        }

        $keywords = $this->videoKeywords($video);
        if ($keywords !== []) {
            $schema['keywords'] = implode(', ', $keywords);
        }

        $actors = $this->peopleSchema($video->avdb_actor);
        if ($actors !== []) {
            $schema['actor'] = $actors;
        }

        $directors = $this->peopleSchema($video->avdb_director);
        if ($directors !== []) {
            $schema['director'] = $directors;
        }

        if ($video->avdb_code) {
            $schema['identifier'] = $video->avdb_code;
        }

        if (config('site.video_seek_action_enabled', false)) {
            $schema['potentialAction'] = [
                '@type' => 'SeekToAction',
                'target' => $this->videoUrl($video) . '?t={seek_to_second_number}',
                'startOffset-input' => 'required name=seek_to_second_number',
            ];
        }

        return array_filter($schema, fn ($value) => !is_null($value) && $value !== '' && $value !== []);
    }

    private function videoKeywords(Video $video): array
    {
        $keywords = [];

        if ($video->avdb_code) {
            $keywords[] = $video->avdb_code;
        }

        $tags = $video->relationLoaded('tags')
            ? $video->tags
            : $video->tags()->with('translations')->limit(20)->get();

        foreach ($tags as $tag) {
            $keywords[] = $tag->localized_name ?: $tag->name;
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($keyword) => trim((string) $keyword),
            $keywords
        ))));
    }

    private function peopleSchema(?string $names): array
    {
        if (!$names) {
            return [];
        }

        $people = preg_split('/\s*,\s*|\s*;\s*|\s*\|\s*/', $names) ?: [];
        $people = array_slice(array_values(array_unique(array_filter(array_map(
            fn ($name) => trim($name),
            $people
        )))), 0, 12);

        return array_map(
            fn ($name) => ['@type' => 'Person', 'name' => $name],
            $people
        );
    }

    private function templatePlaceholders(array $values): array
    {
        $appUrl = url('/');
        $siteDomain = parse_url($appUrl, PHP_URL_HOST) ?: '';

        $base = [
            'site_name' => config('app.name'),
            'site' => config('app.name'),
            'site_domain' => $siteDomain,
            'app_url' => $appUrl,
            'locale' => app()->getLocale(),
            'year' => now()->format('Y'),
        ];

        $placeholders = [];

        foreach (array_merge($base, $values) as $key => $value) {
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            } elseif (is_array($value)) {
                $value = implode(', ', array_filter(array_map(
                    fn ($item) => is_scalar($item) ? trim((string) $item) : '',
                    $value
                )));
            } elseif (!is_scalar($value) && $value !== null) {
                $value = method_exists($value, '__toString') ? (string) $value : '';
            }

            $placeholders['{' . $key . '}'] = $this->cleanSeoText((string) ($value ?? ''));
        }

        return $placeholders;
    }

    private function cleanSeoText(string $value, int $limit = 0): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        if ($limit > 0) {
            return Str::limit($value, $limit, '');
        }

        return $value;
    }

    public function submitVideoToIndexNow(Video $video): void
    {
        if (!config('site.indexnow_enabled') || !config('site.indexnow_key') || !$video->is_active) {
            return;
        }

        try {
            $endpoint = rtrim((string) config('site.indexnow_endpoint', 'https://api.indexnow.org/indexnow'), '/');
            $url = $this->videoUrl($video);
            $host = parse_url($url, PHP_URL_HOST);
            $payload = [
                'host' => $host,
                'key' => config('site.indexnow_key'),
                'keyLocation' => url('/' . config('site.indexnow_key') . '.txt'),
                'urlList' => [$url],
            ];

            Http::timeout(8)->post($endpoint, $payload);
        } catch (\Throwable $e) {
            Log::warning('IndexNow submit failed: ' . $e->getMessage());
        }
    }

    private function matchVideoPattern(string $subject): ?array
    {
        $pattern = trim((string) config('site.video_url_pattern', '{slug}-{uuid}'), '/');
        $regex = preg_quote($pattern, '#');
        $tokens = [
            // '*?': URLs published while a video had an empty slug ('/video/-<id>') still resolve and 301 to the canonical one
            '\{slug\}' => '(?P<slug>[A-Za-z0-9._-]*?)',
            '\{uuid\}' => '(?P<uuid>' . Video::UUID_PATTERN . ')',
            '\{id\}' => '(?P<id>\d+)',
            '\{year\}' => '(?P<year>\d{4})',
            '\{month\}' => '(?P<month>\d{2})',
            '\{day\}' => '(?P<day>\d{2})',
            '\{user\}' => '(?P<user>[A-Za-z0-9._-]+?)',
            '\{category\}' => '(?P<category>[A-Za-z0-9._-]+?)',
        ];

        $regex = '#^' . strtr($regex, $tokens) . '$#';

        if (!preg_match($regex, $subject, $matches)) {
            return null;
        }

        return Arr::where($matches, fn ($value, $key) => is_string($key) && $value !== '');
    }

    private function isoDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $seconds = $seconds % 60;
        $duration = 'PT';

        if ($hours) {
            $duration .= $hours . 'H';
        }

        if ($minutes) {
            $duration .= $minutes . 'M';
        }

        if ($seconds || $duration === 'PT') {
            $duration .= $seconds . 'S';
        }

        return $duration;
    }
}
