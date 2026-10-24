{{--
    <x-videos-area>: server wrapper for the <videos-area> custom element (VideosList.tsx).

    1. Reserved space (perf plan step 20). The light DOM holds the same skeleton grid VideosList renders
       while it loads, so every feed has its final size from the first paint instead of growing from a
       0 px inline element when the chunk arrives. preact-custom-element (shadow:false) clears the light
       DOM and renders synchronously on upgrade, so the swap to Preact's own skeletons happens in one task.
       Skeleton count per breakpoint = what VideosList renders there (skeletonCount) = rows x visible
       columns, with visible columns = mobile setting (1|2) below 768, 2 from 768 to 1199, the feed's columns
       (else 3) from 1200 to 1399 and the feed's columns (else the grid_columns setting) from 1400.
       Rows = max-rows (or max-items / columns) for row-limited feeds, else 4 (capped by max-items), which keeps
       whatever follows the feed below the fold until the taller first page arrives. `skeleton-items` (a known
       feed size, e.g. the category's video count) caps the count so a small feed does not collapse on load.
       `phone-strip-screen` (the home main feed only): below 768px horizontal strip cards reserve a phone screen
       of them (PHONE_STRIP_SKELETON_ITEMS) instead of 4.
    2. Load path (step 21). Only the first feed of a request gets `early` (fetched at once; the <head>
       early fetch keys off request()->attributes 'avd.early_feeds') and `priority-row` (its first row
       of thumbnails loads eagerly). Every other feed is fetched when it comes within 800 px of the viewport or at
       the first idle moment after load, whichever comes first (VideosList). Two feeds can share the role by width:
       an early feed takes the widths no earlier early feed has, `early-width` ('phone' = below 768px, 'wide' = from
       768px) limits it to one (the home page hides its rails below 768px under a photo or list hero: the first
       rail's feed is early from 768px, the main feed, `early`, below). A feed early at one width carries
       `early-media` (the media query the <head> script and VideosList check) and its skeleton pictures load lazily;
       the <head> preloads, gated by the same query, fetch them where it is early.
       The VideosList chunk and its static imports are pushed once to the 'preload' stack as modulepreload.
       For guests, a home feed's first page ships with the page when the API holds it in its guest cache
       (the early feed's 'page', the layout prints it as #avdFeedEarlyPage): the skeleton's first four cards show its
       thumbnails (img in .avd-placeholder-thumb) and VideosList takes the page as its initial data, so no request
       is made and the cards replace the skeleton when the app boots. On a cold API cache the page carries only the
       first row's pictures (one lean query: the videos and their thumbnails), which the skeleton shows (server and
       VideoSkeleton alike) until the early request's cards arrive.

    KEEP IN SYNC: the card markup below copies resources/js/components/Skeletons/VideoSkeleton.tsx, the grid
    wrapper copies the grid in VideosList.tsx, and the count logic mirrors VideosList.tsx (positiveInteger,
    effectiveMaxRows, visibleColumnCount, skeletonCount). The wide grid ad box (data-avd-ad-reserve) mirrors
    skeletonAdAfter / wideAdVideoIndexes in VideosList.tsx.
--}}
@props([
    'url',
    'columns' => null,
    'maxItems' => null,
    'maxRows' => null,
    'loadMode' => null,
    'adContext' => null,
    'early' => null,
    'earlyWidth' => null,
    'skeletonItems' => null,
    'phoneStripScreen' => false,
])
@php
    $avdFeedRequest = request();

    // First feed of the request: no viewport gate, eager first row, and the URL the <head> early fetch uses
    // ('avd.early_feeds'). An early feed takes the widths - below 768px ('phone'), from 768px ('wide') - that no earlier
    // early feed of the request has; `early-width` limits it to one of them. Width null = every width.
    $avdFeedOrdinal = (int) $avdFeedRequest->attributes->get('avd.feed_ordinal', 0);
    $avdFeedRequest->attributes->set('avd.feed_ordinal', $avdFeedOrdinal + 1);
    $avdFeedIsEarly = $early === null ? $avdFeedOrdinal === 0 : filter_var($early, FILTER_VALIDATE_BOOLEAN);
    $avdEarlyFeeds = (array) $avdFeedRequest->attributes->get('avd.early_feeds', []);
    $avdEarlyWidth = null;

    if ($avdFeedIsEarly) {
        $avdEarlyClaimed = array_map(fn (array $feed): string => $feed['width'] ?? 'all', $avdEarlyFeeds);
        $avdEarlyFree = in_array('all', $avdEarlyClaimed, true) ? [] : array_values(array_diff(['phone', 'wide'], $avdEarlyClaimed));
        $avdEarlyGets = array_values(array_intersect(in_array($earlyWidth, ['phone', 'wide'], true) ? [$earlyWidth] : ['phone', 'wide'], $avdEarlyFree));
        $avdFeedIsEarly = $avdEarlyGets !== [];
        $avdEarlyWidth = count($avdEarlyGets) === 1 ? $avdEarlyGets[0] : null;
    }

    $avdEarlyMedia = match ($avdEarlyWidth) {
        'phone' => '(max-width: 767.98px)',
        'wide' => '(min-width: 768px)',
        default => null,
    };
    $avdShippedThumbs = [];
    $avdShippedEager = false;

    if ($avdFeedIsEarly) {
        $avdEarlyFeed = ['url' => (string) $url, 'width' => $avdEarlyWidth, 'media' => $avdEarlyMedia, 'page' => null, 'thumbs' => []];

        // Guests: a home feed's first page ships with the page (its 'page'; the layout prints it as #avdFeedEarlyPage
        // and the <head> script hands it to VideosList), so its first row renders as soon as the app boots instead of
        // after a second request (Gate A: under the ranked lists the largest picture of the first screen is a feed
        // thumbnail that waited for the API). It is the API's own guest cache entry, read only (a warm render adds no
        // query): on a miss the page ships without it, the <head> script's early request loads it (and warms the entry
        // for the next guests for feed_cache_ttl seconds) and the page carries only the first row's pictures (two
        // queries). KEEP IN SYNC with App\Http\Controllers\Api\Public\VideoController::index (inputs, key, payload).
        $avdFeedParts = parse_url((string) $url);
        $avdFeedTtl = (int) config('site.feed_cache_ttl', 30);

        if ($avdFeedTtl > 0 && ! auth()->check() && ! $avdFeedRequest->has('cursor')
            && str_ends_with(rtrim((string) ($avdFeedParts['path'] ?? ''), '/'), '/api/videos')) {
            parse_str((string) ($avdFeedParts['query'] ?? ''), $avdFeedQuery);
            $avdFeedSource = (string) ($avdFeedQuery['source'] ?? 'newest');
            $avdFeedSource = array_key_exists($avdFeedSource, \App\Support\AppearanceSettings::homeFeedSources()) ? $avdFeedSource : 'newest';
            $avdFeedCategory = max(0, (int) ($avdFeedQuery['category'] ?? 0));
            $avdFeedPerPage = max(1, min(\App\Support\AppearanceSettings::HOME_FEED_MAX_ITEMS, (int) ($avdFeedQuery['per_page'] ?? config('site.per_page_videos', 24))));
            $avdFeedKey = "feed:index:{$avdFeedSource}:c{$avdFeedCategory}:p{$avdFeedPerPage}:cur".md5('').':'.app()->getLocale();

            try {
                $avdFeedPage = \Illuminate\Support\Facades\Cache::get($avdFeedKey);
            } catch (\Throwable $e) {
                $avdFeedPage = null; // the early request of the <head> script loads it instead
            }

            if (is_array($avdFeedPage) && is_array($avdFeedPage['data'] ?? null) && is_array($avdFeedPage['meta'] ?? null)) {
                $avdEarlyFeed['page'] = $avdFeedPage;
                // The skeleton's first cards show these thumbnails from the first paint (VideosList renders the same
                // pictures in their place, from this page, when it mounts). Eager unless the hero has a photo of its
                // own (its partial pushed it to 'preload'), which then stays the first picture to load, or the feed
                // is early at one width only (the preloads of its width fetch them there).
                $avdShippedThumbs = array_slice(array_map(fn ($item) => is_array($item) && is_string($item['thumbnail'] ?? null) ? $item['thumbnail'] : null, $avdFeedPage['data']), 0, 4);
                $avdShippedEager = $avdEarlyWidth === null && ! str_contains($__env->yieldPushContent('preload'), 'as="image"');
            } else {
                // A cold API cache: the first row's pictures only (the same feed query, four rows, with the thumbnails
                // it eager-loads), so they show and load from the first paint; the layout preloads them and hands
                // them to VideosList's skeleton (its 'thumbs').
                try {
                    $avdShippedThumbs = app(\App\Services\PublicVideoFeedService::class)->home($avdFeedSource, $avdFeedCategory)
                        ->limit(4)->get()->map(fn ($video): string => (string) $video->thumbnail_url)->all();
                } catch (\Throwable $e) {
                    $avdShippedThumbs = [];
                }

                if ($avdShippedThumbs !== []) {
                    $avdEarlyFeed['thumbs'] = $avdShippedThumbs;
                    $avdShippedEager = $avdEarlyWidth === null && ! str_contains($__env->yieldPushContent('preload'), 'as="image"');
                }
            }
        }

        $avdEarlyFeeds[] = $avdEarlyFeed;
        $avdFeedRequest->attributes->set('avd.early_feeds', $avdEarlyFeeds);
    }

    // VideosList: feedColumns = min(6, positiveInteger(columns)), itemLimit = positiveInteger(max-items),
    // effectiveMaxRows = max-rows, else round(itemLimit / feedColumns) when both are set.
    $avdPositive = static fn ($value): int => is_scalar($value) ? max(0, (int) $value) : 0;
    $avdFeedColumns = min(6, $avdPositive($columns));
    $avdItemLimit = $avdPositive($maxItems);
    $avdExplicitRows = $avdPositive($maxRows);
    $avdFeedRows = $avdExplicitRows > 0
        ? $avdExplicitRows
        : ($avdItemLimit > 0 && $avdFeedColumns > 0 ? max(1, (int) round($avdItemLimit / $avdFeedColumns)) : 0);

    // Columns per tier (< 768, 768-1199, 1200-1399, >= 1400), from the same sources as the layout:
    // <html data-avd-mobile-video-columns> and --avd-grid-columns.
    $avdMobileColumnsValue = $avdFeedRequest->has('mobile_video_columns')
        ? $avdFeedRequest->query('mobile_video_columns')
        : config('site.mobile_video_columns', 2);
    $avdMobileColumns = max(1, min(2, is_scalar($avdMobileColumnsValue) ? (int) $avdMobileColumnsValue : 1));
    $avdGridColumns = max(2, min(6, (int) config('site.grid_columns', 4)));
    $avdTierColumns = [
        $avdMobileColumns,
        2,
        $avdFeedColumns > 0 ? $avdFeedColumns : 3,
        $avdFeedColumns > 0 ? $avdFeedColumns : $avdGridColumns,
    ];

    // Same value VideosList reads from <html data-avd-card-layout> (the layout's video_card_layout_style).
    $avdCardLayoutValue = $avdFeedRequest->has('video_card_layout_style')
        ? $avdFeedRequest->query('video_card_layout_style')
        : config('site.video_card_layout_style', 'card_standard');
    $avdCardLayout = is_string($avdCardLayoutValue) && array_key_exists($avdCardLayoutValue, \App\Support\AppearanceSettings::videoCardLayoutStyles())
        ? $avdCardLayoutValue
        : 'card_standard';

    // Row-limited feeds: exactly their rows. Others: 4 full rows, capped by max-items. A known feed size
    // (skeleton-items, e.g. a category's video count) caps both, so a small feed does not collapse on load.
    // Horizontal strip cards stand one per row on phones (responsive.scss) and are a quarter as tall as a standard
    // card. On the home page (phone-strip-screen) the main feed then reserves 12 of them below 768px: with 4 the
    // footer sat just below the fold and the ink of its shadow inside the first screen until the first page
    // replaced the skeleton (Code Editor / Phosphor / Raw Concrete phone home: 0.09 CLS). Only there: a feed that
    // can be shorter than a phone screen (/trend: 6 videos) would shrink by half a screen and pull the footer in.
    // KEEP IN SYNC with PHONE_STRIP_SKELETON_ITEMS in VideosList.tsx.
    $avdSkeletonCap = $avdPositive($skeletonItems);
    $avdPhoneStrip = $avdCardLayout === 'card_horizontal_strip' && filter_var($phoneStripScreen, FILTER_VALIDATE_BOOLEAN);
    $avdVisibleCounts = array_map(
        static function (int $tierColumns, int $tier) use ($avdFeedRows, $avdItemLimit, $avdSkeletonCap, $avdPhoneStrip): int {
            $unlimited = $tier === 0 && $avdPhoneStrip ? 12 : 4 * $tierColumns;
            $count = $avdFeedRows > 0
                ? $avdFeedRows * $tierColumns
                : ($avdItemLimit > 0 ? min($avdItemLimit, $unlimited) : $unlimited);

            return $avdSkeletonCap > 0 ? min($count, $avdSkeletonCap) : $count;
        },
        $avdTierColumns,
        array_keys($avdTierColumns)
    );

    // Bootstrap display classes so each tier shows exactly its count (mobile first).
    $avdTierPrefixes = ['d', 'd-md', 'd-xl', 'd-xxl'];
    $avdSkeletonClasses = [];
    for ($avdIndex = 0, $avdTotal = max($avdVisibleCounts); $avdIndex < $avdTotal; $avdIndex++) {
        $avdParts = ['col'];
        $avdShownBefore = true;

        foreach ($avdVisibleCounts as $avdTier => $avdCount) {
            $avdShown = $avdIndex < $avdCount;

            if ($avdShown !== $avdShownBefore) {
                $avdParts[] = $avdTierPrefixes[$avdTier] . ($avdShown ? '-block' : '-none');
            }

            $avdShownBefore = $avdShown;
        }

        $avdSkeletonClasses[] = implode(' ', $avdParts);
    }

    // Wide grid ad box (perf P4). VideosList puts a wide_row ad, a full-width row, after the row that holds video
    // N, 2N, ... ("Ad after every N": wideAdVideoIndexes in feedSlots.ts, at most ad_grid_max_per_feed ads),
    // so a feed grew by that row when its data arrived. The skeleton holds the box at the same place per breakpoint
    // (App\Support\FeedAdSlots::wideAdAfter, the same rule; sized in feed-skeleton.scss), only when VideosList will
    // render the ad there: window.AD_CONFIG shows ads on this page (the layout's $showAds: ads on, not hidden for a
    // premium member, not an auth page; <x-videos-area> only renders in the public layout), the feed's ad context
    // has a grid ad code and resolves to wide_row, and the skeleton reaches the ad position. A grid-card ad takes
    // one card cell, which a skeleton card already holds.
    // KEEP IN SYNC with window.AD_CONFIG in components/layout.blade.php and skeletonAdAfter in VideosList.tsx.
    $avdAdReserveClasses = [];
    $avdAdContextKey = is_string($adContext) && $adContext !== '' ? $adContext : 'default';
    $avdGridAdContexts = ['smart_discovery', 'creator_spotlight', 'trending_actors', 'new_videos', 'trend_page', 'category_page', 'subscriptions', 'channel_videos', 'search_results', 'playlist_videos', 'watch_related', 'channel_home_featured', 'channel_home_latest'];
    $avdAdsOn = config('site.ads_enabled', false);
    $avdAdRoute = $avdFeedRequest->route()?->getName();
    $avdAdsShown = $avdAdsOn
        && ! ($avdAdsOn && config('site.ads_hide_for_premium', true) && auth()->user()?->is_premium)
        && ! ($avdAdRoute && (in_array($avdAdRoute, ['login', 'registration'], true) || \Illuminate\Support\Str::startsWith($avdAdRoute, ['password.', 'verification.', 'phone.', 'oauth.'])));
    $avdGridAdCode = $avdAdsShown && in_array($avdAdContextKey, $avdGridAdContexts, true)
        ? trim((string) config('site.ad_code_grid_' . $avdAdContextKey, ''))
        : '';
    $avdGridAdPosition = \App\Support\FeedAdSlots::adEvery(config('site.ad_grid_position', 6));
    $avdGridAdLayout = config('site.ad_grid_layout_' . $avdAdContextKey, 'inherit');

    if ($avdGridAdLayout !== 'grid_card' && $avdGridAdLayout !== 'wide_row') {
        $avdGridAdLayout = config('site.ad_grid_layout', 'grid_card') === 'wide_row' ? 'wide_row' : 'grid_card';
    }

    if ($avdGridAdCode !== '' && $avdGridAdPosition > 0 && $avdGridAdLayout === 'wide_row') {
        $avdMaxAds = \App\Support\FeedAdSlots::maxAds(config('site.ad_grid_max_per_feed', 1));
        $avdAdAfterByTier = [];

        foreach ($avdTierColumns as $avdTier => $avdColumnsAtTier) {
            $avdAdAfterByTier[$avdTier] = array_fill_keys(
                \App\Support\FeedAdSlots::wideAdAfter($avdVisibleCounts[$avdTier], $avdColumnsAtTier, $avdGridAdPosition, $avdMaxAds),
                true
            );
        }

        // One box per skeleton position, shown at the breakpoints whose ad goes there (mobile first, like the cards).
        foreach (array_unique(array_merge(...array_map('array_keys', $avdAdAfterByTier))) as $avdAdAfter) {
            $avdParts = ['col-12 avd-grid-ad-col avd-grid-ad-col--wide'];
            $avdShownBefore = true;

            foreach ($avdAdAfterByTier as $avdTier => $avdAfterSet) {
                $avdShown = isset($avdAfterSet[$avdAdAfter]);

                if ($avdShown !== $avdShownBefore) {
                    $avdParts[] = $avdTierPrefixes[$avdTier] . ($avdShown ? '-flex' : '-none');
                }

                $avdShownBefore = $avdShown;
            }

            $avdAdReserveClasses[$avdAdAfter] = implode(' ', $avdParts);
        }
    }
@endphp
@pushOnce('preload', 'avd-videos-list-modulepreload')
@php
    // The VideosList chunk and its static imports, so they download in parallel with main.js instead of
    // after it runs. The chunk is found by its manifest key, or by its stable chunk name while it is only
    // a shared chunk. Everything the main entry already loads is skipped. Any problem: no preload.
    $avdFeedPreloads = [];

    try {
        $avdVite = app(\Illuminate\Foundation\Vite::class);
        $avdManifestPath = public_path('build/manifest.json');
        $avdManifest = ! $avdVite->isRunningHot() && is_file($avdManifestPath)
            ? json_decode((string) file_get_contents($avdManifestPath), true)
            : null;

        if (is_array($avdManifest)) {
            $avdListKey = isset($avdManifest['resources/js/components/Videos/VideosList.tsx'])
                ? 'resources/js/components/Videos/VideosList.tsx'
                : collect($avdManifest)->search(fn ($chunk) => is_array($chunk) && ($chunk['name'] ?? null) === 'VideosList' && empty($chunk['isEntry']));
            $avdLoaded = [];
            $avdQueue = ['resources/js/app.ts'];

            while ($avdQueue !== []) {
                $avdKey = array_shift($avdQueue);

                if (isset($avdLoaded[$avdKey]) || ! isset($avdManifest[$avdKey])) {
                    continue;
                }

                $avdLoaded[$avdKey] = true;
                array_push($avdQueue, ...($avdManifest[$avdKey]['imports'] ?? []));
            }

            $avdQueue = is_string($avdListKey) ? [$avdListKey] : [];
            $avdSeen = [];

            while ($avdQueue !== []) {
                $avdKey = array_shift($avdQueue);

                if (isset($avdSeen[$avdKey]) || isset($avdLoaded[$avdKey]) || ! isset($avdManifest[$avdKey]['file'])) {
                    continue;
                }

                $avdSeen[$avdKey] = true;
                $avdFeedPreloads[] = $avdVite->asset($avdKey);
                array_push($avdQueue, ...($avdManifest[$avdKey]['imports'] ?? []));
            }
        }
    } catch (\Throwable) {
        $avdFeedPreloads = [];
    }

    $avdPreloadNonce = $avdFeedPreloads !== [] ? \Illuminate\Support\Facades\Vite::cspNonce() : null;
@endphp
@foreach($avdFeedPreloads as $avdPreloadUrl)
    <link rel="modulepreload" as="script" href="{{ $avdPreloadUrl }}"@if($avdPreloadNonce) nonce="{{ $avdPreloadNonce }}"@endif>
@endforeach
@endPushOnce
<videos-area
    url="{{ $url }}"
    @if($columns !== null && $columns !== '') columns="{{ $columns }}" @endif
    @if($maxItems !== null && $maxItems !== '') max-items="{{ $maxItems }}" @endif
    @if($maxRows !== null && $maxRows !== '') max-rows="{{ $maxRows }}" @endif
    @if($loadMode !== null && $loadMode !== '') load-mode="{{ $loadMode }}" @endif
    @if($adContext !== null && $adContext !== '') ad-context="{{ $adContext }}" @endif
    @if($avdSkeletonCap > 0) skeleton-items="{{ $avdSkeletonCap }}" @endif
    @if($avdPhoneStrip) phone-strip-screen @endif
    @if($avdFeedIsEarly) early priority-row @endif
    @if($avdEarlyMedia !== null) early-media="{{ $avdEarlyMedia }}" @endif
    {{ $attributes }}
><div class="row avd-video-grid avd-feed-grid row-cols-2 row-cols-sm-2 row-cols-md-2 row-cols-lg-2 row-cols-xl-3 row-cols-xxl-4"@if($avdFeedColumns > 0) data-avd-feed-columns="{{ $avdFeedColumns }}" style="--avd-feed-columns: {{ $avdFeedColumns }}"@endif data-avd-card-layout="{{ $avdCardLayout }}" data-avd-feed-skeleton aria-hidden="true">@foreach($avdSkeletonClasses as $avdSkeletonPosition => $avdSkeletonClass)<div class="{{ $avdSkeletonClass }}"><div class="avd-video-card">@if(isset($avdShippedThumbs[$avdSkeletonPosition]))<div class="avd-placeholder avd-placeholder-thumb avd-has-photo"><img src="{{ $avdShippedThumbs[$avdSkeletonPosition] }}" alt="" width="1280" height="720" loading="{{ $avdShippedEager ? 'eager' : 'lazy' }}"@if($avdShippedEager && $avdSkeletonPosition < 2) fetchpriority="high"@endif decoding="async"></div>@else<div class="avd-placeholder avd-placeholder-thumb"></div>@endif<div class="avd-card-body"><div class="avd-placeholder avd-placeholder-avatar"></div><div class="d-flex flex-column gap-2 pt-1"><div class="avd-placeholder avd-placeholder-line w-95"></div><div class="avd-placeholder avd-placeholder-line w-75"></div><div class="avd-placeholder avd-placeholder-line w-50"></div></div></div></div></div>@if(isset($avdAdReserveClasses[$avdSkeletonPosition + 1]))<div class="{{ $avdAdReserveClasses[$avdSkeletonPosition + 1] }}" data-avd-ad-reserve><div class="ad-slot ad-slot-grid ad-slot-grid--wide h-100 avd-placeholder"></div></div>@endif @endforeach</div></videos-area>
