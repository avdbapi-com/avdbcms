// Every entrance below runs on WAAPI; this chunk no longer carries anime.js. The pointer effects
// (tilt, spotlight, magnet, click burst, cursor halo) live in ./pointerFx and the click ripple in the
// layout. The header elevation and the scroll progress bar live in ./scrollChrome (app.ts), outside
// this chunk.
import {initScrollMotionScheduler, isWindowScrolling, subscribeWindowScroll} from './scrollMotionScheduler';
import {allows, atLeast, onTierChange, type TierFx} from './tier';
import {watchAddedElements} from '../dom/addedRoots';

// Capability tier (html[data-avd-tier], see ./tier.ts): lite never loads this chunk; balanced gets
// short opacity-only entrances and no decorative JS loops; rich keeps everything. The tier is read
// live, and a runtime downgrade ('avd:tier') disposes every installer whose effect the new tier no
// longer allows. Decorative loops and cascades (rails, widget cascade) need rich, motion on and no
// reduced-motion preference; rails then show their static final state instead.
const decorativeMotionBlocked = () => !allows('autoRail');

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const configuredMotion = () => document.documentElement.dataset.avdMotion ?? 'full';

// One MediaQueryList, read per call (the reveal observer reads it in every callback).
let finePointerQuery: MediaQueryList | null = null;
const finePointer = () => (finePointerQuery ??= window.matchMedia('(pointer: fine)')).matches;

type Disposer = () => void;

// Installers run one per idle slot instead of all in one boot task: scheduler.postTask at
// background priority where available, otherwise requestIdleCallback (with a timeout, so a busy
// page cannot starve them).
const runSoon = (task: () => void): void => {
    const scheduler = (window as Window & {
        scheduler?: {postTask?: (callback: () => void, options?: {priority?: string}) => Promise<unknown>};
    }).scheduler;

    if (typeof scheduler?.postTask === 'function') {
        scheduler.postTask(task, {priority: 'background'}).catch(() => undefined);
        return;
    }

    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(() => task(), {timeout: 1000});
        return;
    }

    window.setTimeout(task, 16);
};

const runQueue = (tasks: Array<() => void>): void => {
    const next = () => {
        const task = tasks.shift();

        if (!task) {
            return;
        }

        try {
            task();
        } catch (error) {
            // One failing installer must not keep the others from running.
            console.error(error);
        }

        if (tasks.length) {
            runSoon(next);
        }
    };

    runSoon(next);
};

// How long ago the page first painted content: from the first-contentful-paint entry, or, where
// paint timing is missing, from the first response byte (minus a typical byte-to-paint gap).
// null when neither is known.
const sinceFirstPaint = (): number | null => {
    try {
        const fcp = performance.getEntriesByName('first-contentful-paint')[0];

        if (fcp) {
            return performance.now() - fcp.startTime;
        }

        // Paint timing is supported but nothing has painted yet: the page is not on screen.
        if (typeof PerformanceObserver !== 'undefined' && PerformanceObserver.supportedEntryTypes?.includes('paint')) {
            return 0;
        }
    } catch {
        /* no paint timing */
    }

    try {
        const navigation = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming | undefined;

        if (navigation && navigation.responseStart > 0) {
            return Math.max(0, performance.now() - navigation.responseStart - 500);
        }
    } catch {
        /* no navigation timing */
    }

    return null;
};

// Entrances for content that is already on screen only look right right after the first paint:
// later, they blink painted content out and back in. The page intro and the first reveal batch
// therefore run only when init happens within this window of the first paint.
const FIRST_PAINT_WINDOW_MS = 300;

const initIsTimely = (): boolean => {
    const since = sinceFirstPaint();

    return since === null || since <= FIRST_PAINT_WINDOW_MS;
};

// On screen right now (not just inside the 12% early-reveal margin).
const onScreen = (entry: IntersectionObserverEntry): boolean => {
    const rect = entry.boundingClientRect;

    return rect.bottom > 0 && rect.top < window.innerHeight;
};

type HomeWidgetDirection = 'left' | 'right' | 'up' | 'down' | 'none';
type HomeWidgetMotionStyle = 'slide' | 'lift' | 'zoom' | 'fade';

const clampNumber = (value: unknown, fallback: number, min: number, max: number): number => {
    const parsed = Number.parseInt(String(value ?? ''), 10);

    return Number.isFinite(parsed) ? Math.max(min, Math.min(max, parsed)) : fallback;
};

const homeWidgetMotionSettings = () => {
    const settings = window.AVD_SETTINGS?.homeWidgetMotion ?? {};

    return {
        enabled: settings.enabled !== false,
        style: settings.style === 'slide' || settings.style === 'lift' || settings.style === 'zoom' || settings.style === 'fade'
            ? settings.style
            : 'slide',
        durationMs: clampNumber(settings.durationMs, 720, 120, 2000),
        distancePx: clampNumber(settings.distancePx, 34, 0, 160),
        staggerMs: clampNumber(settings.staggerMs, 70, 0, 240),
        cardCascade: settings.cardCascade !== false,
        autoRun: settings.autoRun !== false,
        pauseOnHover: settings.pauseOnHover !== false,
        speedPxPerSecond: clampNumber(settings.speedPxPerSecond, 48, 4, 160),
    };
};

const visible = (element: Element): element is HTMLElement => {
    const rect = element.getBoundingClientRect();
    const style = window.getComputedStyle(element);

    return rect.width > 0 && rect.height > 0 && style.display !== 'none' && style.visibility !== 'hidden';
};

const $all = <T extends Element = HTMLElement>(selector: string, root: ParentNode = document): T[] => {
    return Array.from(root.querySelectorAll<T>(selector)).filter(visible);
};

// Entrances run on WAAPI, i.e. on the compositor: no main-thread tween, no inline-style
// residue and no permanent will-change. Only the FROM keyframe is given (offset 0). The
// implicit TO keyframe is the element's own resting style, so a resting transform or opacity
// is kept and nothing snaps at the end (anime blended with composition 'blend'). fill
// 'backwards' holds the FROM state through the stagger delay, like anime's synchronous
// initial render did.
const EASE_OUT = 'cubic-bezier(.33,1,.68,1)'; // anime 'out(3)'

const enter = (element: Element, from: string | null, duration: number, delay = 0): void => {
    if (typeof element.animate !== 'function') {
        return;
    }

    const start: Keyframe = from ? {opacity: 0, transform: from, offset: 0} : {opacity: 0, offset: 0};
    const timing: KeyframeAnimationOptions = {duration, delay, easing: EASE_OUT, fill: 'backwards'};

    try {
        element.animate([start], timing);
    } catch {
        // Engines without implicit to-keyframes (Chrome < 84, Safari < 13.1) need the end state.
        try {
            element.animate([start, from ? {opacity: 1, transform: 'none'} : {opacity: 1}], timing);
        } catch {
            /* no usable WAAPI: the element simply shows in its resting state */
        }
    }
};

const cardSelector = [
    '.avd-video-card',
    '.video-card-modern',
    '.suggested_video.avd-related-card',
    '.avd-channel-card',
    '.avd-section-card',
    '.avd-channel-panel',
    '.avd-description-panel',
    '.avd-watch-sidebar-panel',
].join(',');

// Loading placeholders (the server-rendered feed skeletons and VideosList's own) are cards too,
// but they are replaced within moments: they do not count toward the reveal budget below.
const isSkeletonCard = (element: Element): boolean => element.firstElementChild?.classList.contains('avd-placeholder-thumb') === true;

const runPageIntro = () => {
    // Same groups, offsets, start positions and staggers as the old 620 ms timeline. Every
    // visibility read happens first (one style/layout pass), then all animations start, so
    // reads and writes never interleave. Hidden duplicates (the off-canvas or mobile copies)
    // still get no stagger slot, exactly as before.
    const groups: Array<{targets: HTMLElement[]; from: string; at: number; step: number}> = [
        {targets: $all('.avd-brand, .avd-sidebar-toggle'), from: 'translate3d(-14px,0,0) scale(.96)', at: 0, step: 0},
        {targets: $all('.avd-header-actions > *, search-bar'), from: 'translate3d(0,-10px,0)', at: 60, step: 55},
        {targets: $all('.avd-page-kicker, .avd-home-title, .avd-home-subtitle, .avd-watch-title'), from: 'translate3d(0,18px,0)', at: 120, step: 80},
        {targets: $all('.avd-category-tabs .avd-tag'), from: 'translate3d(0,12px,0) scale(.94)', at: 240, step: 38},
        {targets: $all('.avd-sidebar-link'), from: 'translate3d(-10px,0,0)', at: 180, step: 22},
    ];

    groups.forEach(({targets, from, at, step}) => {
        targets.forEach((element, index) => enter(element, from, 620, at + index * step));
    });
};

// skipPainted: init came late, so content that was already on screen at init keeps its painted
// state instead of fading in again (it would blink). Content added or scrolled to later still fades.
const installRevealObserver = (skipPainted: boolean): Disposer => {
    const seen = new WeakSet<Element>();
    const initial = new WeakSet<Element>();
    let initialScan = true;
    const revealSelector = [
        cardSelector,
        '.avd-mobile-comments',
        '.avd-empty-state',
        '.avd-error-state',
        '.avd-footer-top',
        '.avd-footer-col',
        '.avd-search-video-card',
        '.avd-playlist-card',
        '.avd-user-card',
    ].join(',');
    const observed = new WeakSet<Element>();
    let observedCount = 0;
    const observedLimit = configuredMotion() === 'reduced' ? 48 : 84;

    const revealObserver = new IntersectionObserver((entries) => {
        const isMobile = !finePointer();
        // Rich: the slide/scale fade. Balanced: a 200 ms opacity fade, no offset or scale.
        const motion = allows('revealMotion');

        entries.forEach((entry, index) => {
            if (!entry.isIntersecting || seen.has(entry.target)) {
                return;
            }

            seen.add(entry.target);
            revealObserver.unobserve(entry.target);

            if (skipPainted && initial.has(entry.target) && onScreen(entry)) {
                return;
            }

            if (!motion) {
                enter(entry.target, null, 200, Math.min(index * 16, 96));
                return;
            }

            // Short and started just before the card scrolls in: content was invisible for up to ~0.9s
            // after every scroll step when the reveal waited until the card was well inside the viewport.
            enter(
                entry.target,
                isMobile ? 'translate3d(0,10px,0)' : 'translate3d(0,16px,0) scale(.99)',
                isMobile ? 260 : 360,
                Math.min(index * (isMobile ? 16 : 24), 96),
            );
        });
    }, {
        threshold: 0,
        rootMargin: '0px 0px 12% 0px',
    });

    const scan = (root: ParentNode = document) => {
        const candidates: HTMLElement[] = [];

        if (root instanceof HTMLElement && root.matches(revealSelector)) {
            candidates.push(root);
        }

        candidates.push(...Array.from(root.querySelectorAll<HTMLElement>(revealSelector)));

        candidates.forEach((element) => {
            if (element.closest('[data-avd-home-widget]') || isSkeletonCard(element)) {
                return;
            }

            if (!seen.has(element) && !observed.has(element) && observedCount < observedLimit) {
                observed.add(element);
                observedCount += 1;
                if (initialScan) initial.add(element);
                revealObserver.observe(element);
            }
        });
    };

    scan();
    initialScan = false;

    let scheduled: number | null = null;
    const pendingRoots = new Set<HTMLElement>();
    // Added content inside #main-container, from the shared added-nodes bus (own observer on the
    // embed shell).
    const scheduleScan = (roots: readonly Element[]) => {
        roots.forEach((node) => {
            if (node instanceof HTMLElement) {
                pendingRoots.add(node);
            }
        });

        if (scheduled !== null) {
            return;
        }

        scheduled = window.setTimeout(() => {
            scheduled = null;
            pendingRoots.forEach((root) => scan(root));
            pendingRoots.clear();
            animateSearchPanels();
        }, 240);
    };

    const stopWatching = watchAddedElements(document.getElementById('main-container') ?? document.body, scheduleScan);

    return () => {
        revealObserver.disconnect();
        stopWatching();
        if (scheduled !== null) window.clearTimeout(scheduled);
        scheduled = null;
        pendingRoots.clear();
    };
};

const widgetDirection = (element: HTMLElement): HomeWidgetDirection => {
    const direction = element.dataset.avdMotionDirection;

    return direction === 'left'
        || direction === 'right'
        || direction === 'up'
        || direction === 'down'
        || direction === 'none'
        ? direction
        : 'up';
};

const widgetMotionOffset = (direction: HomeWidgetDirection, distance: number): string => {
    if (direction === 'right') {
        return `translate3d(${-distance}px,0,0) `;
    }

    if (direction === 'left') {
        return `translate3d(${distance}px,0,0) `;
    }

    if (direction === 'up') {
        return `translate3d(0,${distance}px,0) `;
    }

    if (direction === 'down') {
        return `translate3d(0,${-distance}px,0) `;
    }

    return '';
};

// The widget's FROM transform for its entrance (null = opacity only), same offsets as before
// for the slide, lift, zoom and fade styles.
const widgetFrom = (element: HTMLElement): string | null => {
    const settings = homeWidgetMotionSettings();

    if (settings.style === 'slide') {
        return `${widgetMotionOffset(widgetDirection(element), settings.distancePx)}scale(.985)`;
    }

    if (settings.style === 'lift') {
        return `translate3d(0,${Math.max(12, Math.round(settings.distancePx * 0.75))}px,0) scale(.985)`;
    }

    if (settings.style === 'zoom') {
        return 'scale(.94)';
    }

    return null;
};

// Rich tier only (widgetCascade). skipPainted: see installRevealObserver.
const installHomeWidgetMotion = (skipPainted: boolean): Disposer | undefined => {
    const settings = homeWidgetMotionSettings();

    if (!settings.enabled || document.documentElement.dataset.avdHomeWidgetMotion !== 'on') {
        return;
    }

    // Balanced / lite / motion-off: skip the entrance cascade and leave widgets in their final
    // (fully visible) state. No CSS pre-hides these, so returning early is the final state.
    if (!allows('widgetCascade')) {
        return;
    }

    const seen = new WeakSet<Element>();
    const observed = new WeakSet<Element>();
    const initial = new WeakSet<Element>();
    let initialScan = true;

    const animateWidget = (element: HTMLElement, index: number) => {
        if (seen.has(element)) {
            return;
        }

        seen.add(element);

        // a section reveal hides real content until it ends: honour shorter settings, never longer than 480ms
        enter(element, widgetFrom(element), Math.min(settings.durationMs, 480), Math.min(index * settings.staggerMs, 120));

        if (!settings.cardCascade) {
            return;
        }

        // No visibility pre-filter: it forced a style/layout read right after the widget's own
        // animation was queued, once per widget.
        const children = Array.from(element.querySelectorAll<HTMLElement>('.avd-discovery-head > *, .avd-discovery-card:not([data-avd-motion-clone="true"]), .avd-channel-card:not([data-avd-motion-clone="true"])')).slice(0, 18);
        // capped cascade: with stagger(70ms) the 18th card of a rail only started after ~1.2s
        const step = Math.min(settings.staggerMs, 30);
        const duration = Math.min(420, Math.max(240, settings.durationMs - 120));

        children.forEach((child, childIndex) => {
            enter(child, 'translate3d(0,14px,0) scale(.965)', duration, Math.min(childIndex * step, 200));
        });
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry, index) => {
            if (!entry.isIntersecting || !(entry.target instanceof HTMLElement)) {
                return;
            }

            observer.unobserve(entry.target);

            if (skipPainted && initial.has(entry.target) && onScreen(entry)) {
                seen.add(entry.target);
                return;
            }

            animateWidget(entry.target, index);
        });
    }, {
        threshold: 0,
        rootMargin: '0px 0px 12% 0px',
    });

    const scan = (root: ParentNode = document) => {
        const candidates: HTMLElement[] = [];

        if (root instanceof HTMLElement && root.matches('[data-avd-home-widget]')) {
            candidates.push(root);
        }

        candidates.push(...Array.from(root.querySelectorAll<HTMLElement>('[data-avd-home-widget]')));

        candidates.forEach((element) => {
            if (!observed.has(element) && !seen.has(element)) {
                observed.add(element);
                if (initialScan) initial.add(element);
                observer.observe(element);
            }
        });
    };

    scan();
    initialScan = false;

    let scheduled: number | null = null;
    const pendingRoots = new Set<HTMLElement>();
    // Added content inside #main-container, from the shared added-nodes bus (own observer on the
    // embed shell).
    const scheduleScan = (roots: readonly Element[]) => {
        roots.forEach((node) => {
            if (node instanceof HTMLElement) {
                pendingRoots.add(node);
            }
        });

        if (scheduled !== null) {
            return;
        }

        scheduled = window.setTimeout(() => {
            scheduled = null;
            pendingRoots.forEach((root) => scan(root));
            pendingRoots.clear();
        }, 240);
    };

    const stopWatching = watchAddedElements(document.getElementById('main-container') ?? document.body, scheduleScan);

    return () => {
        observer.disconnect();
        stopWatching();
        if (scheduled !== null) window.clearTimeout(scheduled);
        scheduled = null;
        pendingRoots.clear();
    };
};

const homeWidgetAutoSurface = (element: HTMLElement): HTMLElement | null => {
    if (element.matches('.avd-category-tabs, .avd-discovery-track, .avd-creator-track, .avd-shelf-track, .avd-shorts-track, #avdCyberGridTrack')) {
        return element;
    }

    if (element.matches('[data-avd-chips-scroller]') || element.dataset.avdHomeWidget === 'category_chips') {
        return element.querySelector<HTMLElement>('.avd-category-tabs');
    }

    if (element.matches('.avd-creator-spotlight') || element.id === 'home-widget-creator-spotlight') {
        return element.querySelector<HTMLElement>('.avd-creator-track, .avd-discovery-track, .row');
    }

    if (element.matches('[data-avd-shelf]') || element.matches('.avd-shelf-track-container') || element.dataset.avdHomeWidget?.startsWith('cinema_')) {
        return element.querySelector<HTMLElement>('.avd-shelf-track');
    }

    if (element.matches('.avd-shorts-section') || element.dataset.avdHomeWidget === 'tiktok_shorts') {
        return element.querySelector<HTMLElement>('.avd-shorts-track');
    }

    if (element.matches('.avd-cyber-recon-shelf') || element.dataset.avdHomeWidget === 'cyber_recon') {
        return element.querySelector<HTMLElement>('#avdCyberGridTrack, [data-shelf-track]');
    }

    return element.querySelector<HTMLElement>('.avd-discovery-track, .avd-creator-track, .avd-shelf-track, .avd-slider-track, .avd-shorts-track, #avdCyberGridTrack, [data-shelf-track]');
};

const originalAutoChildren = (surface: HTMLElement): HTMLElement[] => {
    return Array.from(surface.children)
        .filter((child): child is HTMLElement => child instanceof HTMLElement && child.dataset.avdMotionClone !== 'true');
};

const prepareAutoSurface = (surface: HTMLElement): number => {
    if (surface.dataset.avdAutoMotionReady === 'true') {
        return Number.parseFloat(surface.dataset.avdAutoMotionLoopWidth ?? '0');
    }

    const originals = originalAutoChildren(surface);

    if (originals.length < 2) {
        return 0;
    }

    surface.classList.add('avd-home-auto-motion');
    surface.dataset.avdAutoMotionReady = 'true';
    surface.style.setProperty('scroll-behavior', 'auto', 'important');

    let sets = 1;
    const appendSet = () => {
        originals.forEach((child) => {
            const clone = child.cloneNode(true) as HTMLElement;
            clone.dataset.avdMotionClone = 'true';
            clone.setAttribute('aria-hidden', 'true');
            // Clean up any entrance animation inline styles from original element so clone never flashes
            clone.style.removeProperty('opacity');
            clone.style.removeProperty('transform');
            clone.querySelectorAll<HTMLElement>('a, button, input, select, textarea, [tabindex]').forEach((focusable) => {
                focusable.tabIndex = -1;
            });
            surface.appendChild(clone);
        });
        sets += 1;
    };

    appendSet();

    // clientWidth (the track viewport) does not change as overflowing clones are appended,
    // so read it once instead of on every loop iteration; only scrollWidth must be re-read.
    const viewport = surface.clientWidth;
    while (surface.scrollWidth < viewport * 1.8 && sets < 3) {
        appendSet();
    }

    const firstClone = surface.querySelector<HTMLElement>('[data-avd-motion-clone="true"]');
    const loopWidth = firstClone
        ? Math.max(0, firstClone.offsetLeft - originals[0].offsetLeft)
        : Math.max(0, surface.scrollWidth / sets);

    surface.dataset.avdAutoMotionLoopWidth = String(loopWidth);

    return loopWidth;
};

const installHomeWidgetAutoRun = (): Disposer | undefined => {
    const settings = homeWidgetMotionSettings();

    if (!settings.enabled || !settings.autoRun || document.documentElement.dataset.avdHomeWidgetMotion !== 'on') {
        return;
    }

    // Balanced / lite / motion-off: never start the autorun marquees. Rails stay static (final
    // state) and remain manually scrollable. A later downgrade is handled by motionAllowed() +
    // the data-avd-tier/data-avd-perf MutationObserver below, which stop live loops, and by the
    // disposer this returns.
    // Touch devices (no hover or no fine pointer) keep rails static and natively swipeable
    // too: the per-frame scrollLeft writes, and the scroll events they fire into the chips and
    // cinema nav handlers, were the weak-phone cost. window.avdScrollRail then stays undefined
    // and the home.blade.php rail buttons fall back to scrollBy().
    if (decorativeMotionBlocked() || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        return;
    }

    const coarsePointer = !finePointer();
    // Use smooth 60fps requestAnimationFrame on all devices instead of 12.5fps (80ms) jerky intervals
    const frameIntervalMs = 0;
    const running = new WeakSet<HTMLElement>();
    const observed = new WeakSet<HTMLElement>();
    type AutoRunRow = {
        surface: HTMLElement;
        direction: 'left' | 'right';
        loopWidth: number;
        offset: number;
        paused: boolean;
        inView: boolean;
        restoreSnap: () => void;
    };
    const rows: AutoRunRow[] = [];
    const pendingVisibleWidgets = new Set<HTMLElement>();
    const motionPreference = window.matchMedia('(prefers-reduced-motion: reduce)');
    let frame: number | null = null;
    let cadenceTimer: number | null = null;
    let lastFrame = performance.now();
    let disposed = false;
    // Web font loads (see the listeners next to the resize one below): the loop widths are measured
    // again once document.fonts.ready settles, one wait at a time.
    const fontSet = (document as Document & {fonts?: FontFaceSet}).fonts;
    let fontsWaiting = false;
    const remeasureWhenFontsReady = () => {
        if (!fontSet || fontsWaiting || disposed) return;
        fontsWaiting = true;
        fontSet.ready.then(() => {
            fontsWaiting = false;
            if (!disposed) onResize();
        }, () => {
            fontsWaiting = false;
        });
    };

    // Global helper for rail navigation buttons
    (window as any).avdScrollRail = (triggerEl: HTMLElement, direction: number): boolean => {
        const section = triggerEl.closest<HTMLElement>('section') || triggerEl.closest<HTMLElement>('[data-avd-home-widget]');
        if (!section) return false;
        const track = section.querySelector<HTMLElement>('.avd-discovery-track, .avd-creator-track, .row');
        if (!track) return false;

        const activeRow = rows.find((r) => r.surface === track);
        if (activeRow) {
            activeRow.paused = true;
        }

        const step = Math.max(280, Math.round(track.clientWidth * 0.72));
        const targetScroll = track.scrollLeft + (direction * step);

        track.style.setProperty('scroll-behavior', 'smooth', 'important');
        track.scrollTo({ left: targetScroll, behavior: 'smooth' });

        window.setTimeout(() => {
            track.style.setProperty('scroll-behavior', 'auto', 'important');
            if (activeRow) {
                activeRow.offset = track.scrollLeft;
                activeRow.paused = false;
                lastFrame = performance.now();
                schedule();
            }
        }, 750);

        return true;
    };

    const motionAllowed = () => !disposed
        && !document.hidden
        && !motionPreference.matches
        && configuredMotion() !== 'off'
        && !decorativeMotionBlocked()
        // Pause during active scroll: coarse-pointer attribute (immediate touch response)
        // plus the all-device window-scroll signal (covers desktop + mobile momentum).
        && document.documentElement.dataset.avdScrollActive !== '1'
        && !isWindowScrolling();
    const canRun = (row: AutoRunRow) => motionAllowed() && row.inView && !row.paused && row.surface.isConnected;

    const schedule = () => {
        if (!rows.some(canRun)) {
            return;
        }

        if (frameIntervalMs > 0) {
            if (cadenceTimer === null) {
                cadenceTimer = window.setTimeout(() => {
                    cadenceTimer = null;
                    tick(performance.now());
                }, frameIntervalMs);
            }
            return;
        }

        if (frame === null) {
            frame = window.requestAnimationFrame(tick);
        }
    };

    const tick = (now: number) => {
        frame = null;

        const delta = Math.min(now - lastFrame, 160) / 1000;
        lastFrame = now;

        // Evaluate the shared gate (hidden / reduced / off / lite / scrolling) ONCE per
        // frame instead of twice per row, then do a pure write pass: each rail advances a
        // JS-tracked offset and only WRITES scrollLeft. No scrollLeft/scrollWidth/rect is
        // read here, so the loop never forces a reflow.
        if (!motionAllowed()) {
            return;
        }

        let anyRan = false;

        rows.forEach((row) => {
            if (!row.inView || row.paused || !row.surface.isConnected) {
                return;
            }

            anyRan = true;
            const step = settings.speedPxPerSecond * delta;
            row.offset += row.direction === 'right' ? -step : step;

            if (row.direction === 'left' && row.offset >= row.loopWidth) {
                row.offset -= row.loopWidth;
            }

            if (row.direction === 'right' && row.offset <= 0) {
                row.offset += row.loopWidth;
            }

            row.surface.scrollLeft = row.offset;
        });

        if (anyRan && frame === null) {
            frame = window.requestAnimationFrame(tick);
        }
    };

    const start = (widget: HTMLElement): AutoRunRow | null => {
        const surface = homeWidgetAutoSurface(widget);

        if (!surface || running.has(surface)) {
            return rows.find((row) => row.surface === surface) ?? null;
        }

        const loopWidth = prepareAutoSurface(surface);

        if (loopWidth <= 0) {
            return null;
        }

        // Measured with fallback metrics: measure again once the fonts are in.
        if (fontSet?.status === 'loading') {
            remeasureWhenFontsReady();
        }

        running.add(surface);
        surface.style.setProperty('scroll-behavior', 'auto', 'important');

        // Snap points fight the per-frame scrollLeft writes (the cinema shelves carry an inline
        // 'x mandatory', the shorts and cyber tracks 'x proximity'). They are suspended while the
        // rail is under autorun control and handed back when the user scrolls a paused rail
        // sideways. Never on hover alone: re-snapping there would visibly jump the rail.
        const inlineSnap = surface.style.getPropertyValue('scroll-snap-type');
        const inlineSnapPriority = surface.style.getPropertyPriority('scroll-snap-type');
        let snapSuspended = false;
        const suspendSnap = () => {
            if (snapSuspended) return;
            snapSuspended = true;
            surface.style.setProperty('scroll-snap-type', 'none', 'important');
        };
        const restoreSnap = () => {
            if (!snapSuspended) return;
            snapSuspended = false;
            if (inlineSnap) surface.style.setProperty('scroll-snap-type', inlineSnap, inlineSnapPriority);
            else surface.style.removeProperty('scroll-snap-type');
        };
        suspendSnap();

        const direction = widgetDirection(widget) === 'right' ? 'right' : 'left';
        const row: AutoRunRow = {
            surface,
            direction,
            loopWidth,
            offset: direction === 'right' ? loopWidth : surface.scrollLeft,
            paused: false,
            inView: false,
            restoreSnap,
        };

        if (direction === 'right') {
            surface.scrollLeft = row.offset;
        }

        const resume = () => {
            // After a downgrade the rail stays static with its own snap behaviour.
            if (disposed) return;
            row.paused = false;
            row.offset = surface.scrollLeft;
            suspendSnap();
            lastFrame = performance.now();
            schedule();
        };

        let resumeTimer: number | null = null;
        const resumeAfterIdle = () => {
            if (resumeTimer !== null) {
                window.clearTimeout(resumeTimer);
            }

            resumeTimer = window.setTimeout(() => {
                resumeTimer = null;
                resume();
            }, coarsePointer ? 180 : 0);
        };

        if (settings.pauseOnHover) {
            surface.addEventListener('pointerenter', (e) => {
                if (e.pointerType === 'mouse') row.paused = true;
            }, {passive: true});
            surface.addEventListener('pointerleave', resume, {passive: true});
        }

        // Direct interaction must pause motion independently of hover preferences.
        // Otherwise a touch swipe or keyboard action fights the animation loop.
        {
            surface.addEventListener('pointerdown', () => {
                row.paused = true;
            }, {passive: true});
            surface.addEventListener('touchstart', () => {
                row.paused = true;
            }, {passive: true});
            surface.addEventListener('pointerup', coarsePointer ? resumeAfterIdle : resume, {passive: true});
            surface.addEventListener('pointercancel', coarsePointer ? resumeAfterIdle : resume, {passive: true});
            surface.addEventListener('touchend', coarsePointer ? resumeAfterIdle : resume, {passive: true});
            surface.addEventListener('touchcancel', coarsePointer ? resumeAfterIdle : resume, {passive: true});
            surface.addEventListener('wheel', (event: WheelEvent) => {
                if (coarsePointer) {
                    row.paused = true;
                    resumeAfterIdle();
                    return;
                }

                // Fine pointer: a hovered rail is already paused by pointerenter and resumes on
                // pointerleave. No resume() here: it read scrollLeft on every wheel event and
                // un-paused the hovered rail. A sideways scroll of a paused rail gets its snap back.
                if (row.paused && (event.shiftKey || Math.abs(event.deltaX) > Math.abs(event.deltaY))) {
                    restoreSnap();
                }
            }, {passive: true});
            surface.addEventListener('click', resumeAfterIdle, {passive: true});
            surface.addEventListener('focusin', () => {
                row.paused = true;
            });
            surface.addEventListener('focusout', resume);
            surface.addEventListener('scroll', () => {
                if (row.paused) {
                    row.offset = surface.scrollLeft;
                }
            }, {passive: true});
            surface.addEventListener('mouseleave', resume, {passive: true});

            const head = widget.querySelector<HTMLElement>('.avd-discovery-head');
            if (head) {
                head.addEventListener('pointerenter', (e) => {
                    if (e.pointerType === 'mouse') row.paused = true;
                }, {passive: true});
                head.addEventListener('pointerleave', resume, {passive: true});
                head.addEventListener('pointerdown', () => {
                    row.paused = true;
                }, {passive: true});
                head.addEventListener('touchstart', () => {
                    row.paused = true;
                }, {passive: true});
                head.addEventListener('pointerup', coarsePointer ? resumeAfterIdle : resume, {passive: true});
                head.addEventListener('touchend', coarsePointer ? resumeAfterIdle : resume, {passive: true});
                head.addEventListener('touchcancel', coarsePointer ? resumeAfterIdle : resume, {passive: true});
            }
        }

        // Desktop mouse drag-to-scroll. The chips row and the cinema shelves bring their own
        // inline drag handlers, so binding this one as well double-handled every drag there.
        if (!surface.matches('.avd-category-tabs, [data-shelf-track]')) {
            let isDown = false;
            let startPageX = 0;
            let scrollLeftStart = 0;

            surface.addEventListener('mousedown', (e: MouseEvent) => {
                if (e.button !== 0) return;
                isDown = true;
                row.paused = true;
                surface.classList.add('is-dragging');
                startPageX = e.pageX;
                scrollLeftStart = surface.scrollLeft;
            });

            const stopDrag = () => {
                if (!isDown) return;
                isDown = false;
                surface.classList.remove('is-dragging');
                // resume() re-reads scrollLeft into row.offset
                resume();
            };

            window.addEventListener('mouseup', stopDrag, {passive: true});

            // pageX delta only: no offsetLeft (layout) read and no scrollLeft read per mousemove;
            // the paused rail's scroll listener keeps row.offset in sync.
            surface.addEventListener('mousemove', (e: MouseEvent) => {
                if (!isDown) return;
                e.preventDefault();
                surface.scrollLeft = scrollLeftStart - (e.pageX - startPageX) * 1.35;
            });
        }

        rows.push(row);
        schedule();

        return row;
    };

    const syncCadence = () => {
        if (!motionAllowed()) {
            if (frame !== null) window.cancelAnimationFrame(frame);
            if (cadenceTimer !== null) window.clearTimeout(cadenceTimer);
            frame = null;
            cadenceTimer = null;
            return;
        }
        pendingVisibleWidgets.forEach(widget => {
            if (!widget.isConnected) return;
            const row = start(widget);
            if (row) row.inView = true;
        });
        pendingVisibleWidgets.clear();
        lastFrame = performance.now();
        schedule();
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting || !(entry.target instanceof HTMLElement)) {
                if (entry.target instanceof HTMLElement) pendingVisibleWidgets.delete(entry.target);
                const surface = entry.target instanceof HTMLElement ? homeWidgetAutoSurface(entry.target) : null;
                const row = surface ? rows.find((candidate) => candidate.surface === surface) : null;

                if (row) {
                    row.inView = false;
                }

                return;
            }

            // Preparing a rail clones/measures cards. Defer that first-entry
            // work as well as steady animation while the user scrolls.
            if (!motionAllowed()) {
                pendingVisibleWidgets.add(entry.target);
                return;
            }
            const row = start(entry.target);

            if (row) {
                row.inView = true;
                lastFrame = performance.now();
                schedule();
            }
        });
    }, {
        threshold: 0.06,
        rootMargin: '12% 0px 12% 0px',
    });

    const autoWidgetSelector = '[data-avd-home-widget], [data-avd-shelf], .avd-shorts-section, .avd-shelf-track-container, .avd-cyber-recon-shelf';
    const scan = (root: ParentNode = document) => {
        const widgets: HTMLElement[] = [];

        if (root instanceof HTMLElement && root.matches(autoWidgetSelector)) {
            widgets.push(root);
        }

        widgets.push(...Array.from(root.querySelectorAll<HTMLElement>(autoWidgetSelector)));

        widgets.forEach((widget) => {
            if (!observed.has(widget) && homeWidgetAutoSurface(widget)) {
                observed.add(widget);
                observer.observe(widget);
            }
        });
    };

    scan();

    document.addEventListener('avd:scroll-activity', syncCadence);
    document.addEventListener('visibilitychange', syncCadence);
    if (motionPreference.addEventListener) motionPreference.addEventListener('change', syncCadence);
    else motionPreference.addListener(syncCadence);
    // Watch data-avd-motion, data-avd-tier and data-avd-perf so a later downgrade (to balanced
    // or lite) or motion off stops the running loops (syncCadence cancels the frame when
    // motionAllowed() turns false).
    const attributeObserver = new MutationObserver(syncCadence);
    attributeObserver.observe(document.documentElement, {
        attributes: true, attributeFilter: ['data-avd-motion', 'data-avd-tier', 'data-avd-perf'],
    });

    // All-device scroll pause: when the shared window-scroll signal settles, resume from a
    // fresh timestamp. During scrolling motionAllowed() is false, so tick stops writing.
    // syncCadence() also starts the rails that became visible during the scroll (parked in
    // pendingVisibleWidgets); a bare schedule() left wheel-reached rails never starting.
    const unsubscribeScroll = subscribeWindowScroll((scrolling) => {
        if (!scrolling) {
            syncCadence();
        }
    });

    // Cached loop widths go stale when the viewport changes (orientation / resize). Refresh
    // them off the animation path, debounced, batching every layout READ before any write so
    // the re-measure itself never thrashes. Never read per frame.
    let resizeTimer: number | null = null;
    const remeasureLoopWidths = () => {
        resizeTimer = null;

        const measured: Array<{row: AutoRunRow; loopWidth: number}> = [];
        rows.forEach((row) => {
            const surface = row.surface;
            if (!surface.isConnected) return;

            const originals = originalAutoChildren(surface);
            const firstClone = surface.querySelector<HTMLElement>('[data-avd-motion-clone="true"]');
            if (!originals.length || !firstClone) return;

            const loopWidth = Math.max(0, firstClone.offsetLeft - originals[0].offsetLeft);
            if (loopWidth > 0) measured.push({row, loopWidth});
        });

        if (!measured.length) return;

        measured.forEach(({row, loopWidth}) => {
            row.loopWidth = loopWidth;
            row.surface.dataset.avdAutoMotionLoopWidth = String(loopWidth);
            if (row.offset >= loopWidth) row.offset %= loopWidth;
        });

        lastFrame = performance.now();
        schedule();
    };
    const onResize = () => {
        if (resizeTimer !== null) window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(remeasureLoopWidths, 180);
    };
    window.addEventListener('resize', onResize, {passive: true});
    // A rail prepared while the web fonts were still loading measured its loop with the fallback
    // metrics, so every wrap jumped by the difference. Measure again once they have loaded:
    // 'loadingdone' (Chromium, Firefox), and document.fonts.ready after a 'loading' event or a rail
    // prepared mid-load (start() above). WebKit fires 'loading' for the CSS @font-face fonts but never
    // 'loadingdone', and its first 'loading' can come before this runs.
    if (fontSet && typeof fontSet.addEventListener === 'function') {
        fontSet.addEventListener('loadingdone', onResize);
        fontSet.addEventListener('loading', remeasureWhenFontsReady);
    }

    let scheduled: number | null = null;
    const pendingRoots = new Set<HTMLElement>();
    // Added content inside #main-container, from the shared added-nodes bus (own observer on the
    // embed shell). The rail clones this engine appends arrive here too, as before.
    const scheduleScan = (roots: readonly Element[]) => {
        roots.forEach((node) => {
            if (node instanceof HTMLElement) {
                pendingRoots.add(node);
            }
        });

        if (scheduled !== null) {
            return;
        }

        scheduled = window.setTimeout(() => {
            scheduled = null;
            pendingRoots.forEach((root) => scan(root));
            pendingRoots.clear();
        }, coarsePointer ? 320 : 120);
    };

    const stopWatching = watchAddedElements(document.getElementById('main-container') ?? document.body, scheduleScan);

    // Tier downgrade: stop for good. The rails stay where they are, static and swipeable, with
    // their own snap behaviour back; window.avdScrollRail keeps scrolling them on demand.
    return () => {
        disposed = true;
        if (frame !== null) window.cancelAnimationFrame(frame);
        if (cadenceTimer !== null) window.clearTimeout(cadenceTimer);
        if (resizeTimer !== null) window.clearTimeout(resizeTimer);
        if (scheduled !== null) window.clearTimeout(scheduled);
        frame = null;
        cadenceTimer = null;
        resizeTimer = null;
        scheduled = null;
        observer.disconnect();
        attributeObserver.disconnect();
        stopWatching();
        unsubscribeScroll();
        document.removeEventListener('avd:scroll-activity', syncCadence);
        document.removeEventListener('visibilitychange', syncCadence);
        if (motionPreference.removeEventListener) motionPreference.removeEventListener('change', syncCadence);
        else motionPreference.removeListener(syncCadence);
        window.removeEventListener('resize', onResize);
        if (fontSet && typeof fontSet.removeEventListener === 'function') {
            fontSet.removeEventListener('loadingdone', onResize);
            fontSet.removeEventListener('loading', remeasureWhenFontsReady);
        }
        pendingVisibleWidgets.clear();
        pendingRoots.clear();
        rows.forEach((row) => row.restoreSnap());
    };
};

const animateSearchPanels = () => {
    // Rich only. Balanced keeps the panel's own CSS entrance (motion.scss avd-popover-in), which
    // this WAAPI version used to override while it ran.
    if (!allows('revealMotion')) {
        return;
    }

    // The panel only exists while its results are shown (SearchBar renders it conditionally),
    // so no visibility pre-filter: it cost a style/layout read per panel and per item.
    document.querySelectorAll<HTMLElement>('.avd-search-results').forEach((panel) => {
        if (panel.dataset.avdAnimeReady === 'true') {
            return;
        }

        panel.dataset.avdAnimeReady = 'true';

        enter(panel, 'translate3d(0,-8px,0) scale(.98)', 240);

        panel.querySelectorAll('li').forEach((item, index) => {
            enter(item, 'translate3d(-12px,0,0)', 360, index * 28);
        });
    });
};

// Rich + fine pointer: the scale fade as before. Balanced or a touch device: a 260 ms opacity fade.
const installImageReveal = (): Disposer => {
    const selector = [
        '.avd-thumb img',
        '.thumbnail-hover-container img',
        '.avd-search-thumb img',
        '.suggested_video img',
        '.avd-channel-avatar img',
        '.channel-avatar img',
        '.modal img',
    ].join(',');

    // Only images that finish loading after init fade in; their 'load' fires even from the
    // memory cache when Preact inserts them. The old scan of already-complete images (and
    // its body-subtree MutationObserver) faded images that were already painted, a visible
    // blink, and the visible() check forced a style/layout read per image. No class and no
    // will-change are added: the WAAPI animation promotes the image only while it runs.
    const onLoad = (event: Event) => {
        const image = event.target;

        if (!(image instanceof HTMLImageElement) || image.dataset.avdImageReady === 'true' || !image.matches(selector)) {
            return;
        }

        image.dataset.avdImageReady = 'true';

        if (allows('revealMotion') && finePointer()) {
            enter(image, 'scale(1.035)', 520);
        } else {
            enter(image, null, 260);
        }
    };

    document.addEventListener('load', onLoad, true);

    return () => document.removeEventListener('load', onLoad, true);
};

// Rich tier only (modalFx); balanced keeps Bootstrap's own CSS transition.
const installModalEffects = (): Disposer => {
    const onShown = (event: Event) => {
        const modal = event.target instanceof HTMLElement ? event.target : null;

        if (!modal) {
            return;
        }

        const content = modal.querySelector<HTMLElement>('.modal-content');
        // One batched visibility read before any animation starts: hidden controls (other
        // steps, collapsed sections) must not take stagger slots, as before.
        const items = $all('.fa-stack, .btn, .form-control, .form-select', modal);

        if (content) {
            enter(content, 'translate3d(0,20px,0) scale(.965)', 360);
        }

        items.forEach((item, index) => {
            enter(item, 'translate3d(0,12px,0) scale(.94)', 360, index * 28);
        });
    };

    document.addEventListener('shown.bs.modal', onShown);

    return () => document.removeEventListener('shown.bs.modal', onShown);
};

// Installed effects that a later tier downgrade may switch off: each keeps the gate it was
// installed under and is disposed once that gate no longer holds.
const installed: Array<{fx: TierFx; dispose: Disposer}> = [];

const install = (fx: TierFx, run: () => Disposer | undefined): void => {
    // The gate is checked when the installer's idle slot comes, not when it was queued.
    if (!allows(fx)) {
        return;
    }

    const dispose = run();

    if (dispose) {
        installed.push({fx, dispose});
    }
};

const disposeDisallowed = (): void => {
    for (let i = installed.length - 1; i >= 0; i -= 1) {
        if (!allows(installed[i].fx)) {
            const [{dispose}] = installed.splice(i, 1);

            try {
                dispose();
            } catch (error) {
                console.error(error);
            }
        }
    }
};

export const initAnimeEnhancements = () => {
    if (document.documentElement.dataset.avdAnimeInit === 'true') {
        return;
    }

    document.documentElement.dataset.avdAnimeInit = 'true';
    initScrollMotionScheduler();

    if (configuredMotion() === 'off' || reducedMotion()) {
        document.documentElement.classList.add('avd-reduced-motion');
        return;
    }

    // Lite (a downgrade that landed while this chunk was loading): no motion to install. The header keeps
    // its scrolled shadow through ./scrollChrome, which app.ts installs on every tier.
    if (!atLeast('balanced')) {
        return;
    }

    document.documentElement.classList.add('avd-anime-enabled');
    onTierChange(disposeDisallowed);

    window.requestAnimationFrame(() => {
        // The intro and the first reveal batch only make sense while the page is first painting
        // (measured from first contentful paint, or from the first response byte without paint
        // timing). A later init leaves already-painted content alone instead of blinking it.
        const timely = initIsTimely();

        if (timely && allows('pageIntro')) {
            runPageIntro();
        }

        // One installer per idle slot. Each is gated by its tier minimum when its slot comes, and
        // the reveal observers re-check the first-paint window then: a slot that comes late leaves
        // the already-painted content alone.
        runQueue([
            () => install('reveal', () => installRevealObserver(!initIsTimely())),
            () => install('widgetCascade', () => installHomeWidgetMotion(!initIsTimely())),
            () => install('autoRail', installHomeWidgetAutoRun),
            () => install('imageReveal', installImageReveal),
            () => install('modalFx', installModalEffects),
            animateSearchPanels,
        ]);
    });
};
