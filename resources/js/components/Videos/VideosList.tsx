import {Fragment} from "preact";
import {useCallback, useEffect, useMemo, useRef, useState} from "preact/hooks";
import {Video} from "./Video";
import {VideoSkeleton} from "@/components/Skeletons/VideoSkeleton";
import {QueryClient, QueryClientProvider} from "@tanstack/react-query";
import {getVideos} from '@/api/avdbclip'
import {useCursorQuery} from "@/hooks";
import {ApiError} from "@/components/Commons";
import {useTranslation} from "react-i18next";
import {HtmlAdSlot} from "./AdSlot";
import {buildFeedSlots, wideAdVideoIndexes} from "./feedSlots";
import {useInView} from "react-intersection-observer";
import {CursorPaginator, VideoType} from "@/types";

// <x-videos-area> (resources/views/components/videos-area.blade.php) server-renders the loading state of this
// component: the grid wrapper below plus VideoSkeleton cards, as many per breakpoint as initialSkeletonCount gives,
// and the wide grid ad box (skeletonAdAfter). Keep the grid classes, the skeleton count, the ad box and
// visibleColumnCount in sync with it.
type Props = {
    url: string,
    columns?: string | number,
    loadMode?: string,
    maxItems?: string | number,
    maxRows?: string | number,
    widgetTemplateIds?: string,
    widgetIntervalRows?: string | number,
    adContext?: string,
    // Boolean attributes the component sets on the first feed of a page only: `early` = fetch at once
    // (no viewport gate), `priority-row` = load its first row of thumbnails eagerly. `early-media`: both apply
    // only where this media query matches (when the page loads); elsewhere the feed loads like any other.
    early?: string | boolean,
    priorityRow?: string | boolean,
    earlyMedia?: string,
    // Known upper bound of the feed's size (e.g. a category's video count): caps the initial skeletons so a
    // small feed does not collapse when its cards arrive.
    skeletonItems?: string | number,
    // Boolean attribute of the home main feed: strip cards reserve a phone screen of skeletons there
    // (PHONE_STRIP_SKELETON_ITEMS).
    phoneStripScreen?: string | boolean,
    'load-mode'?: string,
    'max-items'?: string | number,
    'max-rows'?: string | number,
    'widget-template-ids'?: string,
    'widget-interval-rows'?: string | number,
    'ad-context'?: string,
    'priority-row'?: string | boolean,
    'early-media'?: string,
    'skeleton-items'?: string | number,
    'phone-strip-screen'?: string | boolean,
}

type FeedLoadMode = 'infinite' | 'load_more' | 'pagination';
type GridAdLayout = 'grid_card' | 'wide_row';

const videosQueryClient = new QueryClient({
    defaultOptions: {
        queries: {
            retry: false,
            staleTime: 30_000,
            gcTime: 5 * 60_000,
            refetchOnWindowFocus: false,
        },
    },
});

const UNLIMITED_SKELETON_ROWS = 4;

// The feed's first cards render from their first frame; later ones keep content-visibility:auto (skipped while off
// screen). VideosList marks them (data-avd-first-page) instead of the CSS counting cards with :nth-of-type(): the
// same cards, without a positional rule on a grid that grows by a page at a time. KEEP IN SYNC with
// resources/sass/feed-skeleton.scss.
const FIRST_PAGE_CARDS = 24;

// Horizontal strip cards stand one per row below 768px (responsive.scss) and are a quarter as tall as a standard
// card, so 4 rows of them left the footer on the first screen of the home page while its main feed loaded. Only
// a feed with `phone-strip-screen` (the home main feed) reserves this many: a shorter feed (the trend page's 6)
// would shrink by half a screen when its cards arrive. KEEP IN SYNC with $avdPhoneStrip in
// resources/views/components/videos-area.blade.php (the server skeleton).
const PHONE_STRIP_SKELETON_ITEMS = 12;

function isPhoneWidth(): boolean {
    return typeof window !== 'undefined' && window.innerWidth < 768;
}

// The infinite-scroll sentinel at the end of the grid starts the next page this far ahead of the viewport (px), so
// the page normally lands (and grows the feed) while the end of the feed and whatever follows it are still off
// screen. At least 1500px; a feed whose pages are taller (two columns on a phone: about 2400px a page) uses 1.25
// pages, up to 4000px, so that a slow API answer still lands before a fast scroll reaches the footer.
const NEXT_PAGE_MARGIN_MIN = 1500;
const NEXT_PAGE_MARGIN_MAX = 4000;

// Columns the feed grid really lays out at the current width (Bootstrap row-cols + video.scss):
// below 768 the mobile setting (1|2); 768-1199 two (row-cols-md/lg-2; the feed-column override starts at
// 1200); from 1200 the feed's own columns, else 3 (row-cols-xl-3) and from 1400 --avd-grid-columns.
function visibleColumnCount(configuredColumns = 0): number {
    const fallback = 4;

    if (typeof window === 'undefined') {
        return configuredColumns > 0 ? configuredColumns : fallback;
    }

    const width = window.innerWidth;

    if (width < 768) {
        const mobileColumns = Number.parseInt(document.documentElement.dataset.avdMobileVideoColumns || '2', 10);

        return mobileColumns === 1 ? 1 : 2;
    }

    if (width >= 1200) {
        if (configuredColumns > 0) {
            return configuredColumns;
        }

        if (width >= 1400) {
            const rootColumns = Number.parseInt(
                getComputedStyle(document.documentElement).getPropertyValue('--avd-grid-columns'),
                10
            );

            return Number.isFinite(rootColumns) && rootColumns > 0 ? rootColumns : fallback;
        }

        return 3;
    }

    return 2;
}

// Content-box height of a card (its offsetHeight minus padding and border): what contain-intrinsic-size sizes.
function cardContentHeight(card: HTMLElement): number {
    const style = getComputedStyle(card);

    return card.offsetHeight
        - (parseFloat(style.paddingTop) || 0) - (parseFloat(style.paddingBottom) || 0)
        - (parseFloat(style.borderTopWidth) || 0) - (parseFloat(style.borderBottomWidth) || 0);
}

function hasBooleanAttribute(value: string | boolean | undefined): boolean {
    return value !== undefined && value !== null && value !== false && value !== 'false';
}

// The `early` feed's role at the current width: with `early-media` (e.g. the home main feed below 768px while the
// first rail's feed is the early one from 768px) only where the query matches, as the inline <head> script checks it
// before its early request. Read once, when the feed mounts.
function isEarlyAtThisWidth(early: string | boolean | undefined, media: string | undefined): boolean {
    if (!hasBooleanAttribute(early)) {
        return false;
    }

    if (!media) {
        return true;
    }

    return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia(media).matches;
}

function isCursorPage(payload: unknown): payload is CursorPaginator<VideoType> {
    const page = payload as CursorPaginator<VideoType> | null;

    return Boolean(page && Array.isArray(page.data) && page.meta && typeof page.meta === 'object');
}

// First page of the page's `early` feed, requested by the inline <head> script before the app boots:
// window.__avdFeedEarly[url] = a Promise of the parsed JSON of exactly the request getVideos(url, null) makes
// (same URL, headers and credentials), rejected when the response is not OK. It is taken at most once, for
// the first page only. A rejection or an unexpected payload falls back to the normal request, so a missing,
// failed or stale early fetch behaves exactly like no early fetch.
function earlyFirstPage(url: string): Promise<CursorPaginator<VideoType>> | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const registry = (window as unknown as {__avdFeedEarly?: Record<string, unknown>}).__avdFeedEarly;
    const pending = registry && typeof registry === 'object' ? registry[url] as Promise<unknown> | undefined : undefined;

    if (!registry || !pending || typeof pending.then !== 'function') {
        return null;
    }

    delete registry[url];

    return Promise.resolve(pending).then(
        (payload) => isCursorPage(payload) ? payload : getVideos(url, null),
        () => getVideos(url, null),
    );
}

// The first page of the page's `early` feed when the page carries it (guests on a home feed: the inline <head> script
// parsed #avdFeedEarlyPage into window.__avdFeedShipped[url]). Taken once, as the query's initial data: the first
// render then shows the cards, in the same task that replaces the server skeleton (whose first row already shows
// these thumbnails), with no request and no skeleton frame in between.
function shippedFirstPage(url: string): CursorPaginator<VideoType> | undefined {
    if (typeof window === 'undefined') {
        return undefined;
    }

    const shipped = (window as unknown as {__avdFeedShipped?: Record<string, unknown>}).__avdFeedShipped;
    const page = shipped && typeof shipped === 'object' ? shipped[url] : undefined;

    if (!shipped || !isCursorPage(page)) {
        return undefined;
    }

    delete shipped[url];

    return page;
}

type ShippedThumbs = {thumbs: string[], eager: boolean};

// The first row's pictures of the page's `early` feed when the page carries them without its first page (a cold API
// cache: window.__avdFeedThumbs[url] = {thumbs, eager}, set by the inline <head> script). Taken once: the first
// skeleton cards show them, as the server skeleton did, until the cards (the same pictures, already loaded) replace
// them. `eager` mirrors the server skeleton: false under a hero photo, which keeps the early bandwidth.
function shippedThumbs(url: string): ShippedThumbs {
    const none = {thumbs: [], eager: false};

    if (typeof window === 'undefined') {
        return none;
    }

    const registry = (window as unknown as {__avdFeedThumbs?: Record<string, unknown>}).__avdFeedThumbs;
    const entry = registry && typeof registry === 'object' ? registry[url] as {thumbs?: unknown, eager?: unknown} | undefined : undefined;

    if (!registry || !entry || typeof entry !== 'object' || !Array.isArray(entry.thumbs)) {
        return none;
    }

    delete registry[url];

    return {thumbs: entry.thumbs.filter((thumb): thumb is string => typeof thumb === 'string'), eager: entry.eager === true};
}

// Below-fold feeds also start at the first idle moment after the window load event (timeout 3 s), so their first
// page renders while the page is idle instead of during the visitor's first scroll. The boot keeps a single feed
// request (the `early` feed) and the 800px observer stays the earlier trigger. One shared wait for every feed.
let afterLoadIdlePromise: Promise<void> | null = null;

function afterLoadIdle(): Promise<void> {
    afterLoadIdlePromise ??= new Promise<void>((resolve) => {
        const idle = () => {
            if (typeof window.requestIdleCallback === 'function') {
                window.requestIdleCallback(() => resolve(), {timeout: 3000});
            } else {
                window.setTimeout(resolve, 300);
            }
        };

        if (document.readyState === 'complete') {
            idle();
        } else {
            window.addEventListener('load', idle, {once: true});
        }
    });

    return afterLoadIdlePromise;
}

// Save-Data and 2G visitors keep the pure viewport gate: a feed they never scroll to is never requested.
function prefersReducedData(): boolean {
    const connection = (navigator as Navigator & {connection?: {saveData?: boolean, effectiveType?: string}}).connection;

    return Boolean(connection && (connection.saveData === true || connection.effectiveType === '2g' || connection.effectiveType === 'slow-2g'));
}

function widgetTemplatesFromAttribute(value?: string): {id: string, html: string}[] {
    if (!value || typeof document === 'undefined') {
        return [];
    }

    return value
        .split(',')
        .map(id => id.trim())
        .filter(Boolean)
        .map(id => {
            const template = document.getElementById(id) as HTMLTemplateElement | null;

            return template ? {id, html: template.innerHTML} : null;
        })
        .filter((template): template is {id: string, html: string} => Boolean(template && template.html.trim()));
}

function positiveInteger(value: string | number | undefined, fallback: number): number {
    const parsed = Number.parseInt(String(value ?? ''), 10);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

function nonNegativeInteger(value: string | number | undefined, fallback: number): number {
    const parsed = Number.parseInt(String(value ?? ''), 10);

    return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
}

function normalizedLoadMode(value?: string): FeedLoadMode {
    return value === 'load_more' || value === 'pagination' ? value : 'infinite';
}

function normalizedGridAdLayout(value?: string): GridAdLayout {
    return value === 'wide_row' ? 'wide_row' : 'grid_card';
}

function contextGridAdLayout(contextValue: string | undefined, fallbackValue: string | undefined): GridAdLayout {
    return contextValue === 'grid_card' || contextValue === 'wide_row'
        ? contextValue
        : normalizedGridAdLayout(fallbackValue);
}

function Main ({url, columns, loadMode, maxItems, maxRows, widgetTemplateIds, widgetIntervalRows, adContext, early, priorityRow, earlyMedia, skeletonItems, phoneStripScreen, 'load-mode': dashedLoadMode, 'max-items': dashedMaxItems, 'max-rows': dashedMaxRows, 'widget-template-ids': dashedWidgetIds, 'widget-interval-rows': dashedIntervalRows, 'ad-context': dashedAdContext, 'priority-row': dashedPriorityRow, 'early-media': dashedEarlyMedia, 'skeleton-items': dashedSkeletonItems, 'phone-strip-screen': dashedPhoneStripScreen} : Props) {
    const { t } = useTranslation();
    const feedColumns = Math.min(6, positiveInteger(columns, 0));
    const feedLoadMode = normalizedLoadMode(loadMode ?? dashedLoadMode);
    const itemLimit = positiveInteger(maxItems ?? dashedMaxItems, 0);
    const explicitMaxRows = positiveInteger(maxRows ?? dashedMaxRows, 0);
    const effectiveMaxRows = explicitMaxRows > 0
        ? explicitMaxRows
        : (itemLimit > 0 && feedColumns > 0 ? Math.max(1, Math.round(itemLimit / feedColumns)) : 0);
    const [pageIndex, setPageIndex] = useState(0);
    const [columnCount, setColumnCount] = useState(() => visibleColumnCount(feedColumns));
    const [nextPageMargin, setNextPageMargin] = useState(NEXT_PAGE_MARGIN_MIN);

    // Only the page's first feed (`early`, at the widths its early-media allows) fetches at once. Every other feed
    // fetches when its grid comes within 800px of the viewport or at the first idle moment after load
    // (afterLoadIdle), whichever comes first, so below-fold feeds stop competing with the first one (and with the
    // thumbnails) at boot and do not render in the middle of the first scroll. Without IntersectionObserver the feed
    // loads at once.
    const [isEarlyFeed] = useState(() => isEarlyAtThisWidth(early, earlyMedia ?? dashedEarlyMedia));
    const [shipped] = useState(() => ({url, page: isEarlyFeed ? shippedFirstPage(url) : undefined, row: isEarlyFeed ? shippedThumbs(url) : {thumbs: [], eager: false}}));
    const { ref: feedMountRef, inView: isNearViewport, entry: feedEntry } = useInView({
        rootMargin: '800px 0px',
        triggerOnce: true,
        skip: isEarlyFeed,
        fallbackInView: true,
    });
    // A feed inside a hidden container (an inactive tab pane, e.g. the channel Videos tab) has no box and never
    // intersects. Fetch it at once, as before, so the tab shows its cards as soon as it opens. Latched, so the
    // query stays enabled once the container becomes visible.
    const hiddenFeedRef = useRef(false);
    if (feedEntry && !feedEntry.isIntersecting && feedEntry.boundingClientRect.width === 0 && feedEntry.boundingClientRect.height === 0) {
        hiddenFeedRef.current = true;
    }

    const [idleReady, setIdleReady] = useState(false);

    useEffect(() => {
        if (isEarlyFeed || prefersReducedData()) {
            return;
        }

        let active = true;

        afterLoadIdle().then(() => {
            if (active) {
                setIdleReady(true);
            }
        });

        return () => {
            active = false;
        };
    }, [isEarlyFeed]);

    // Never goes back to false: every source is latched.
    const feedEnabled = isEarlyFeed || isNearViewport || idleReady || hiddenFeedRef.current;

    // Whether the grid is on screen right now (no margin). Kept in a ref so it never re-renders the feed: it is
    // only read when a first page arrives (firstPageOffscreenRef below). The observer only fires when the grid
    // enters or leaves the viewport. It stays connected because the URL (and with it the first page) can change,
    // e.g. when UserVideos changes its sort.
    const gridRef = useRef<HTMLDivElement | null>(null);
    const gridOnScreenRef = useRef(true);
    const setGridRef = useCallback((node: HTMLDivElement | null) => {
        gridRef.current = node;
        feedMountRef(node);
    }, [feedMountRef]);

    useEffect(() => {
        const grid = gridRef.current;

        if (isEarlyFeed || !grid || typeof IntersectionObserver !== 'function') {
            return;
        }

        const observer = new IntersectionObserver((entries) => {
            gridOnScreenRef.current = entries[entries.length - 1].isIntersecting;
        });

        observer.observe(grid);

        return () => observer.disconnect();
    }, [isEarlyFeed]);

    const {
        data: videos,
        isPending,
        isLoading,
        isError,
        isSuccess,
        refetch,
        error,
        isFetchingNextPage,
        fetchNextPage,
        hasNextPage,
        ref,
    } = useCursorQuery({
        queryKey: ['videos', url],
        queryFn: ({pageParam}) => (pageParam == null && earlyFirstPage(url)) || getVideos(url, pageParam),
        enabled: feedEnabled,
        initialData: shipped.page && shipped.url === url ? {pages: [shipped.page], pageParams: [null]} : undefined,
    }, {rootMargin: `${nextPageMargin}px 0px`});

    // How the first page's cards render (feed-skeleton.scss), latched per URL when it arrives. A first page that
    // arrives while the grid is off screen keeps content-visibility:auto on its cards (data-avd-feed-deferred):
    // nothing on screen can shift, and that render lays out only the cards near the viewport instead of the whole
    // page. The early feed, a grid that is on screen and a hidden tab render every card of the first page from its
    // first frame.
    const firstPageOffscreenRef = useRef<boolean | null>(null);
    const firstPageUrlRef = useRef(url);

    if (firstPageUrlRef.current !== url) {
        firstPageUrlRef.current = url;
        firstPageOffscreenRef.current = null;
    }

    if (firstPageOffscreenRef.current === null && videos) {
        firstPageOffscreenRef.current = !isEarlyFeed && !hiddenFeedRef.current && !gridOnScreenRef.current;
    }

    // Infinite feeds: the next-page margin follows the height of a loaded page (see NEXT_PAGE_MARGIN_MIN). Read after
    // the page has rendered and painted; a change below 200px keeps the current observer.
    const loadedPageCount = videos?.pages.length ?? 0;

    useEffect(() => {
        const grid = gridRef.current;

        if (!grid || loadedPageCount === 0 || feedLoadMode !== 'infinite') {
            return;
        }

        const pageHeight = grid.offsetHeight / loadedPageCount;
        const margin = Math.min(NEXT_PAGE_MARGIN_MAX, Math.max(NEXT_PAGE_MARGIN_MIN, Math.round(pageHeight * 1.25 / 100) * 100));

        setNextPageMargin((current) => Math.abs(margin - current) >= 200 ? margin : current);
    }, [loadedPageCount, feedLoadMode]);

    // Height estimate for the cards that render skipped (content-visibility:auto, feed-skeleton.scss): the cards past
    // the 24th and a first page that arrived off screen. A card that was never rendered is laid out at this estimate
    // for one frame, so with the static estimate (taller than most cards) a page landing while the end of the grid is
    // on screen, or skipped cards coming into view, moved the footer down and back up. The estimate is the content
    // height of rendered cards of this grid: the skeleton while the first page loads (always rendered, card-sized),
    // then the real cards on screen when the next page is requested (a skipped card would report the estimate
    // itself). Set on the grid element only, so nothing re-renders.
    useEffect(() => {
        const grid = gridRef.current;

        if (!grid || !(isPending || isFetchingNextPage)) {
            return;
        }

        const viewportHeight = window.innerHeight;
        let height = 0;

        grid.querySelectorAll<HTMLElement>(isPending ? ':scope > div.col > .avd-video-card' : ':scope > article.col > .avd-video-card')
            .forEach((card) => {
                if (!isPending) {
                    const rect = card.getBoundingClientRect();

                    if (rect.bottom <= 0 || rect.top >= viewportHeight) {
                        return;
                    }
                }

                height = Math.max(height, cardContentHeight(card));
            });

        if (height > 40) {
            grid.style.setProperty('--avd-card-h', `${Math.round(height)}px`);
        }
    }, [isPending, isFetchingNextPage, columnCount]);

    useEffect(() => {
        setPageIndex(0);
    }, [url, feedLoadMode]);

    useEffect(() => {
        const handleResize = () => {
            setColumnCount(visibleColumnCount(feedColumns));
        };

        window.addEventListener('resize', handleResize, {passive: true});

        return () => window.removeEventListener('resize', handleResize);
    }, [feedColumns]);

    const effectiveFeedLoadMode = feedLoadMode;
    const widgetIds = widgetTemplateIds ?? dashedWidgetIds;
    const widgets = useMemo(() => widgetTemplatesFromAttribute(widgetIds), [widgetIds]);
    const widgetInterval = positiveInteger(widgetIntervalRows ?? dashedIntervalRows, 2);
    const widgetEveryVideos = columnCount * widgetInterval;
    const pages = videos?.pages ?? [];
    const loadedVideos = useMemo(() => videos
        ? videos.pages.flatMap((page) => page.data).filter((v) => !v.is_private).slice(0, (itemLimit > 0 && effectiveMaxRows === 0) ? itemLimit : undefined)
        : [], [videos, itemLimit, effectiveMaxRows]);
    const renderedVideos = effectiveFeedLoadMode === 'pagination'
        ? (pages[pageIndex]?.data ?? [])
        : loadedVideos;
    // An empty array is meaningful only after the request has completed
    // successfully. Keeping this distinction prevents a failed feed request
    // from falling through to the empty-state copy.
    const hasSuccessfulResponse = isSuccess;
    const hasVisibleVideos = renderedVideos.length > 0;
    const reachedItemLimit = itemLimit > 0 && loadedVideos.length >= itemLimit;
    // Only the first row of the page's first feed loads eagerly (where it is the early feed); every other thumbnail
    // is lazy.
    const eagerCount = isEarlyFeed && hasBooleanAttribute(priorityRow ?? dashedPriorityRow) ? columnCount : 0;
    const cardLayout = typeof document !== 'undefined'
        ? document.documentElement.dataset.avdCardLayout || 'card_standard'
        : 'card_standard';
    // First page: row-limited feeds show exactly the rows they will fill. Other feeds show 4 full rows (capped by
    // max-items): enough to keep whatever follows the feed (the footer on short pages) below the fold until the first
    // page, which is taller, replaces them. Horizontal strip cards on a phone (one per row, a quarter as tall) show
    // PHONE_STRIP_SKELETON_ITEMS instead in the home main feed (phone-strip-screen).
    const unlimitedSkeletonCount = cardLayout === 'card_horizontal_strip' && isPhoneWidth() && hasBooleanAttribute(phoneStripScreen ?? dashedPhoneStripScreen)
        ? PHONE_STRIP_SKELETON_ITEMS
        : UNLIMITED_SKELETON_ROWS * columnCount;
    const skeletonCount = effectiveMaxRows > 0
        ? effectiveMaxRows * columnCount
        : Math.min(itemLimit > 0 ? itemLimit : unlimitedSkeletonCount, unlimitedSkeletonCount);
    const skeletonCap = positiveInteger(skeletonItems ?? dashedSkeletonItems, 0);
    const initialSkeletonCount = skeletonCap > 0 ? Math.min(skeletonCount, skeletonCap) : skeletonCount;
    // Next page (infinite scroll, Load more, the next pagination page): one row. It starts nextPageMargin
    // ahead, so it normally lands off screen, and a short or empty last page can remove at most this one row
    // instead of four.
    const nextPageSkeletonCount = columnCount;
    const gridStyle = feedColumns > 0 ? {'--avd-feed-columns': String(feedColumns)} as any : undefined;
    // Kept while later cards can only arrive ahead of the visitor: in infinite mode (the next page loads 1500px+
    // ahead, and cards past the 24th keep content-visibility:auto anyway) and until the first "Load more". Pagination
    // replaces the cards on screen and a "Load more" page can land on screen, so those render every card from its
    // first frame, like any other feed's.
    const deferredFirstPage = firstPageOffscreenRef.current === true
        && (effectiveFeedLoadMode === 'infinite' || (effectiveFeedLoadMode === 'load_more' && pages.length === 1));
    const canLoadNext = Boolean(hasNextPage && !reachedItemLimit);
    const canPageNext = pageIndex + 1 < pages.length || canLoadNext;
    const canPagePrevious = pageIndex > 0;
    const adConfig = typeof window !== 'undefined' ? (window as any).AD_CONFIG : {};
    const feedAdContext = adContext ?? dashedAdContext ?? 'default';
    const contextGridAdCode = adConfig?.gridAdCodeByContext?.[feedAdContext] || '';
    const gridAdCode = contextGridAdCode.trim();
    const gridPosition = Number(adConfig?.gridPosition || 0);
    const gridMaxPerFeed = nonNegativeInteger(adConfig?.gridMaxPerFeed, 1);
    const gridLayout = contextGridAdLayout(adConfig?.gridLayoutByContext?.[feedAdContext], adConfig?.gridLayout);
    const gridAdsEnabled = Boolean(adConfig?.enabled && gridAdCode && gridPosition > 0);
    // Card ads in a growing feed: complete rows only (feedSlots.completeRowSlotBudget) while more videos can still
    // arrive to fill the held-back places; the last page shows every video.
    const reserveAdSlots = gridAdsEnabled && gridLayout === 'grid_card' && feedLoadMode !== 'pagination' && canLoadNext;
    const feedSlots = useMemo(
        () => buildFeedSlots(renderedVideos, columnCount, gridPosition, gridMaxPerFeed, gridAdsEnabled, reserveAdSlots, gridLayout, effectiveMaxRows),
        [renderedVideos, columnCount, gridPosition, gridMaxPerFeed, gridAdsEnabled, reserveAdSlots, gridLayout, effectiveMaxRows]
    );
    // A wide grid ad (wide_row) is a full-width row between card rows. The first-page skeleton holds its box where
    // buildFeedSlots puts it for a page that fills the skeleton (data-avd-ad-reserve, sized in feed-skeleton.scss),
    // so the ad row does not push the rows below it when the data arrives. <x-videos-area> renders the same box in the
    // server skeleton (App\Support\FeedAdSlots::wideAdAfter, the same rule as feedSlots.ts). Only where the ad will
    // render: ads on, a grid ad code for this context, the wide layout and a skeleton that reaches the ad position. A
    // grid-card ad takes one card cell, which a skeleton card already holds.
    const skeletonAdAfter = useMemo(
        () => gridAdsEnabled && gridLayout === 'wide_row'
            ? wideAdVideoIndexes(initialSkeletonCount, columnCount, gridPosition, gridMaxPerFeed)
            : new Set<number>(),
        [gridAdsEnabled, gridLayout, initialSkeletonCount, columnCount, gridPosition, gridMaxPerFeed]
    );

    const handleLoadMore = () => {
        if (canLoadNext && !isFetchingNextPage) {
            fetchNextPage();
        }
    };

    const handlePageNext = () => {
        if (pageIndex + 1 < pages.length) {
            setPageIndex(pageIndex + 1);

            return;
        }

        if (canLoadNext && !isFetchingNextPage) {
            fetchNextPage().then(() => setPageIndex(pageIndex + 1));
        }
    };

    const handlePagePrevious = () => {
        if (canPagePrevious) {
            setPageIndex(pageIndex - 1);
        }
    };

    return (
        <>
            {isError && (
                <div className="row align-items-center mt-4" role="alert" aria-live="assertive">
                    <div className="col-12 col-xl-8 offset-xl-2">
                        <ApiError refetch={refetch} error={error}/>
                    </div>
                </div>
            )}
            {isLoading && !isError && (
                <div className="visually-hidden" role="status" aria-live="polite">
                    {t('Loading ...')}
                </div>
            )}
            {!isLoading && hasSuccessfulResponse && !hasVisibleVideos && (
                <div className="avd-empty-state text-center p-4 p-md-5 my-4">
                    <i className="fa-solid fa-film fa-3x mb-3 text-muted"></i>
                    <h2 className="h4 fw-black">{t('No videos yet')}</h2>
                    <p className="text-muted mb-0">{t('Check back later for new content.')}</p>
                </div>
            )}
            {/* data-avd-feed-waiting: not requested yet (more than 800px away before the idle start, or a Save-Data
                visitor who has not scrolled near), so its skeletons rest instead of sweeping. data-avd-feed-deferred:
                see deferredFirstPage. */}
            <div
                ref={setGridRef}
                className="row avd-video-grid avd-feed-grid row-cols-2 row-cols-sm-2 row-cols-md-2 row-cols-lg-2 row-cols-xl-3 row-cols-xxl-4"
                data-avd-feed-columns={feedColumns > 0 ? String(feedColumns) : undefined}
                data-avd-card-layout={cardLayout}
                data-avd-visible-columns={String(columnCount)}
                data-avd-feed-waiting={feedEnabled ? undefined : ''}
                data-avd-feed-deferred={deferredFirstPage ? '' : undefined}
                style={gridStyle}
            >
                {feedSlots.map((slot) => {
                    if (slot.type === 'ad') {
                        const isWideAd = slot.layout === 'wide_row';

                        return (
                            <div key={slot.key} className={isWideAd ? 'col-12 avd-grid-ad-col avd-grid-ad-col--wide' : 'col avd-grid-ad-col'}>
                                <HtmlAdSlot
                                    html={gridAdCode}
                                    className={isWideAd ? 'ad-slot ad-slot-grid ad-slot-grid--wide h-100' : 'ad-slot ad-slot-grid h-100'}
                                    ariaLabel={t('Advertisement')}
                                />
                            </div>
                        );
                    }

                    const globalIdx = slot.videoNumber;
                    const widgetIdx = widgetEveryVideos > 0 && globalIdx % widgetEveryVideos === 0
                        ? (globalIdx / widgetEveryVideos) - 1
                        : -1;
                    const widget = widgetIdx >= 0 ? widgets[widgetIdx] : null;

                    return (
                        <Fragment key={slot.key}>
                            <Video video={slot.video} priority={globalIdx <= eagerCount} firstPage={globalIdx <= FIRST_PAGE_CARDS}/>
                            {widget && (
                                <div className="col-12 avd-feed-widget-slot" dangerouslySetInnerHTML={{__html: widget.html}} />
                            )}
                        </Fragment>
                    );
                })}
                {/* isPending, not isLoading: a feed still waiting for the viewport is pending but not loading,
                    and it must keep the skeleton grid (its reserved space) instead of collapsing to 0px. */}
                {isPending && !isError && [...Array(initialSkeletonCount).keys()].map(i => (
                    <Fragment key={i}>
                        <VideoSkeleton thumb={shipped.url === url ? shipped.row.thumbs[i] : undefined} eager={shipped.row.eager} priority={shipped.row.eager && i < 2}/>
                        {skeletonAdAfter.has(i + 1) && (
                            <div className="col-12 avd-grid-ad-col avd-grid-ad-col--wide" data-avd-ad-reserve="" aria-hidden="true">
                                <div className="ad-slot ad-slot-grid ad-slot-grid--wide h-100 avd-placeholder"></div>
                            </div>
                        )}
                    </Fragment>
                ))}
                {isFetchingNextPage && !isPending && !isError && [...Array(nextPageSkeletonCount).keys()].map(i => <VideoSkeleton key={i}/>)}
                {effectiveFeedLoadMode === 'infinite' && canLoadNext && <span ref={ref}></span>}
            </div>
            {effectiveFeedLoadMode === 'load_more' && canLoadNext && (
                <div className="avd-feed-controls">
                    <button
                        className="avd-feed-control-btn"
                        type="button"
                        disabled={isFetchingNextPage}
                        onClick={handleLoadMore}
                    >
                        <i className="fa-solid fa-plus"></i>
                        <span>{isFetchingNextPage ? t('Loading') : t('Load more')}</span>
                    </button>
                </div>
            )}
            {effectiveFeedLoadMode === 'pagination' && videos && (
                <div className="avd-feed-controls" aria-label={t('Video feed pagination')}>
                    <button
                        className="avd-feed-control-btn"
                        type="button"
                        disabled={!canPagePrevious}
                        onClick={handlePagePrevious}
                    >
                        <i className="fa-solid fa-chevron-left"></i>
                        <span>{t('Previous')}</span>
                    </button>
                    <span className="avd-feed-page-indicator">{t('Page')} {pageIndex + 1}</span>
                    <button
                        className="avd-feed-control-btn"
                        type="button"
                        disabled={!canPageNext || isFetchingNextPage}
                        onClick={handlePageNext}
                    >
                        <span>{isFetchingNextPage ? t('Loading') : t('Next')}</span>
                        <i className="fa-solid fa-chevron-right"></i>
                    </button>
                </div>
            )}
        </>
    )
}

export function VideosList(props: Props) {
    return (
        <QueryClientProvider client={videosQueryClient}>
            <Main {...props} />
        </QueryClientProvider>
    )
}
