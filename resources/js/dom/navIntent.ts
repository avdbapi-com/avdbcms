// Navigation intent, and the one guarded stale-asset reload (perf fix P1).
//
// After a deploy, an open page's lazy chunks are gone (404) and the page recovers by reloading
// once: app.ts ('vite:preloadError') and components/index.ts (handleChunkError) both come here.
// WebKit (Safari, every iOS browser) also fails a module or stylesheet load that a navigation
// cancels: leaving while a chunk is loading, or starting one while the next page is on its way,
// rejects the import and Vite reports 'vite:preloadError'. Reloading at once replaced the
// visitor's navigation: a tapped link reloaded the current page instead.
//
// - isNavigating(): true within NAV_WINDOW_MS of a same-tab link click or form submit that no
//   handler prevented (or of a cross-document 'navigate' event where the Navigation API exists),
//   from pagehide until the page is shown again, and while the document is hidden.
// - requestAssetReload(): skipped while navigating; otherwise it waits RECHECK_MS, checks again,
//   then reloads at most once per RELOAD_GUARD_MS (sessionStorage keys, as before). The re-check
//   includes one HEAD request for this page's own main chunk: WebKit signals nothing before a
//   browser-initiated navigation (address bar, back button) commits, but fails every new request
//   while one is pending, and a reload then would replace it. A failure skipped because the page
//   was leaving, hidden or offline is tried again when the page is shown again (back/forward
//   cache, a tab opened in the background) or back online, so a stale build still recovers.
// - optionalLoad(): wraps an optional warm-up import; its failure never reloads the page.
//
// The listeners are passive (they never cancel anything) and run on every page type, like the
// reload paths that read them.

const NAV_WINDOW_MS = 4000;
const RECHECK_MS = 400;
const RELOAD_GUARD_MS = 12000;

type AssetFailure = {key: string; message: string; error: unknown};
type NavigateEventLike = Event & {hashChange?: boolean; downloadRequest?: string | null; destination?: {sameDocument?: boolean}};

let started = false;
let leaving = false;
let intentAt = 0;
let intentEvent: Event | null = null;
let intentBefore = 0;
let failures: AssetFailure[] = [];
let timer = 0;
const optionalFailures = new WeakSet<object>();

// Same origin only: connect-src always allows the site's own origin (its feeds use it). With the
// assets on another origin (a CDN asset URL) there is no probe and the re-check decides alone.
const PROBE_URL = ((): string => {
    try {
        const own = new URL(import.meta.url);

        return own.origin === location.origin ? own.href : '';
    } catch {
        return '';
    }
})();

export const isNavigating = (): boolean =>
    leaving || document.visibilityState === 'hidden' || Date.now() - intentAt < NAV_WINDOW_MS;

const markIntent = (event: Event): void => {
    intentBefore = intentAt;
    intentEvent = event;
    intentAt = Date.now();
};

// Bubble phase on window, after the page's own handlers: a prevented click or submit stays here.
const unmarkPrevented = (event: Event): void => {
    if (event === intentEvent && event.defaultPrevented) {
        intentAt = intentBefore;
        intentEvent = null;
    }
};

const sameTab = (target: string | null | undefined): boolean => !target || /^_(self|top|parent)$/i.test(target);

// http(s) only (not javascript:, mailto:, tel:), and not a fragment of this same document.
const leavesDocument = (href: string): boolean => {
    let url: URL;

    try {
        url = new URL(href, document.baseURI);
    } catch {
        return false;
    }

    return (url.protocol === 'http:' || url.protocol === 'https:')
        && !(url.href.indexOf('#') >= 0 && url.href.split('#')[0] === location.href.split('#')[0]);
};

const onClick = (event: MouseEvent): void => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
    const href = link?.getAttribute('href')?.trim() ?? '';

    if (link && href.charAt(0) !== '#' && !link.hasAttribute('download') && sameTab(link.getAttribute('target')) && leavesDocument(href)) {
        markIntent(event);
    }
};

const onSubmit = (event: Event): void => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const submitter = (event as SubmitEvent).submitter;
    const method = (submitter?.getAttribute('formmethod') || form.getAttribute('method') || '').toLowerCase();

    if (method !== 'dialog' && sameTab(submitter?.getAttribute('formtarget') ?? form.getAttribute('target'))) {
        markIntent(event);
    }
};

const onNavigate = (event: Event): void => {
    const navigate = event as NavigateEventLike;

    if (!navigate.hashChange && !navigate.downloadRequest && navigate.destination?.sameDocument === false) {
        markIntent(event);
    }
};

const optional = (failure: AssetFailure): boolean =>
    typeof failure.error === 'object' && failure.error !== null && optionalFailures.has(failure.error);

// At most one reload per RELOAD_GUARD_MS over every key involved. Without storage there is no
// loop guard, so the page stays (as before, when the storage access threw).
const reloadOnce = (batch: AssetFailure[]): void => {
    const keys = Array.from(new Set(batch.map((failure) => failure.key)));

    try {
        if (keys.some((key) => Date.now() - parseInt(sessionStorage.getItem(key) || '0', 10) <= RELOAD_GUARD_MS)) {
            return;
        }

        keys.forEach((key) => sessionStorage.setItem(key, String(Date.now())));
    } catch {
        return;
    }

    console.warn(batch[0].message);
    window.location.reload();
};

const reloadIfStaying = (): void => {
    if (failures.length > 0 && !isNavigating()) {
        const batch = failures;
        failures = [];
        reloadOnce(batch);
    }
};

const settle = (): void => {
    const kept = new Map<string, AssetFailure>();

    // Optional failures are dropped; one failure per key is enough (failed bus tags are retried and
    // reported again on every batch).
    failures.forEach((failure) => {
        if (!optional(failure) && !kept.has(failure.key)) {
            kept.set(failure.key, failure);
        }
    });
    timer = 0;
    failures = Array.from(kept.values());

    // Kept while navigating, and when the probe fails: tried again by retry() below.
    if (failures.length === 0 || isNavigating()) {
        return;
    }

    if (PROBE_URL) {
        fetch(PROBE_URL, {method: 'HEAD', cache: 'no-store'}).then(reloadIfStaying, () => undefined);
    } else {
        reloadIfStaying();
    }
};

const schedule = (): void => {
    if (!timer) {
        timer = window.setTimeout(settle, RECHECK_MS);
    }
};

const retry = (): void => {
    if (failures.length > 0 && !isNavigating()) {
        schedule();
    }
};

export const startNavIntent = (): void => {
    if (started) {
        return;
    }

    started = true;
    window.addEventListener('click', onClick, true);
    window.addEventListener('click', unmarkPrevented);
    window.addEventListener('submit', onSubmit, true);
    window.addEventListener('submit', unmarkPrevented);
    window.addEventListener('pagehide', () => {
        leaving = true;
    });
    window.addEventListener('pageshow', (event: PageTransitionEvent) => {
        if (event.persisted) {
            leaving = false;
            intentAt = 0;
            retry();
        }
    });
    document.addEventListener('visibilitychange', retry);
    window.addEventListener('online', retry);
    (window as Window & {navigation?: EventTarget}).navigation?.addEventListener('navigate', onNavigate);
};

// The stale-asset recovery: reload once for the fresh build, unless the visitor is leaving.
export const requestAssetReload = (key: string, message: string, error?: unknown): void => {
    startNavIntent();
    failures.push({key, message, error});

    if (!isNavigating()) {
        schedule();
    }
};

// An optional warm-up import: when it fails (typically cancelled because the visitor is leaving),
// its error is marked before the RECHECK_MS decision, so it never reloads the page. stillOptional
// is asked at that moment: false once a required caller has joined the same load.
export const optionalLoad = <T>(load: Promise<T>, stillOptional: () => boolean = () => true): Promise<T> =>
    load.catch((error: unknown) => {
        if (stillOptional() && typeof error === 'object' && error !== null) {
            optionalFailures.add(error);
        }

        throw error;
    });
