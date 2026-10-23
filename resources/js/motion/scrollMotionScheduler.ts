// Keep decorative loops out of scroll frames and off-screen, without changing saved motion settings.
// A host is MARKED once (data-avd-deco) when a decorative loop starts on it or on its
// ::before/::after, and one IntersectionObserver flips data-avd-offscreen on it. The CSS gate
// in motion.scss (@layer avd-motion-gate) then pauses the loops of [data-avd-deco] hosts that
// are off-screen (every device) or while html[data-avd-scroll-active="1"] (touch / lite).
// There are no Animation API calls here: reading playState and calling pause()/play() per
// animation forced one full style flush per animation on every scroll start and stop.
// Keep this list in sync with the keyframe names in sass/*.scss, layout.blade.php,
// videos/show.blade.php and hero-ticker.blade.php: a renamed keyframe silently stops being gated.
const decorativeNames = new Set([
    // UI loops
    'avd-soft-pulse', 'avd-soft-pulse-replay', 'avd-surface-scan', 'avd-title-sheen', 'avd-title-sheen-replay',
    'avd-icon-breathe', 'avd-accent-shift-x',
    // watch-page halos
    'avdAmbientPulse', 'avdAmbilightPulse', 'avdAmbiPulse', 'avd-ambilight-glow-breathe',
    // border beam
    'avdBorderBeamSpin', 'avd-border-beam-spin',
    // background atmospheres (the rules in effect; the matrix rain moves by transform)
    'avd-atmosphere-drift', 'avd-aurora-wave', 'avd-solar-flare-pulse', 'avd-matrix-rain-y',
    // channel page and hero ticker
    'avd-channel-float', 'avd-channel-pulse', 'avd-channel-pulse-o', 'avd-channel-sweep',
    'avd-live-dot', 'avd-live-ring',
]);
const excluded = 'video-player, video, audio, iframe, .ad-slot, .avd-grid-ad-col, [role="complementary"], [role="progressbar"], [aria-busy="true"]';
const decoAttr = 'data-avd-deco';
const offscreenAttr = 'data-avd-offscreen';
const quietPeriod = 200;
let cleanup: (() => void) | undefined;

// ONE passive window scroll listener drives both scroll signals. It is bound lazily by the
// first subscriber or by initScrollMotionScheduler, whichever comes first.
// - The all-device signal (isWindowScrolling / subscribeWindowScroll) settles
//   windowScrollIdleMs after the last scroll event; JS loops such as the desktop rail
//   marquee pause on it.
// - html[data-avd-scroll-active] and the 'avd:scroll-activity' event stay touch / lite only
//   (see the gate in initScrollMotionScheduler). They settle quietPeriod after the last scroll
//   event and are held, without polling, while a finger rests on the page after a vertical drag.
// The handler never reads window.scrollY and never clears or re-arms a timer per event: it
// stamps the time, and one timer per gesture re-arms itself for the remaining quiet time.
const windowScrollIdleMs = 150;
const minTimerMs = 16;
let windowScrolling = false;
let windowScrollBound = false;
let lastScrollAt = 0;
let quietTimer = 0;
const windowScrollSubscribers = new Set<(active: boolean) => void>();

// Owned by initScrollMotionScheduler; inert until it runs (and again after its cleanup).
let scrollActive = false;
let scrollGate: () => boolean = () => false;
let setScrollActive: (next: boolean) => void = () => undefined;
let touch: {x: number; y: number; vertical: boolean} | undefined;

const emitWindowScrolling = (next: boolean) => {
    if (next === windowScrolling) return;
    windowScrolling = next;
    windowScrollSubscribers.forEach((callback) => {
        try {
            callback(next);
        } catch {
            /* a misbehaving subscriber must never break scroll handling */
        }
    });
};

const armQuiet = (delay: number) => {
    // Never tighter than one frame, so a re-arm can never spin.
    quietTimer = window.setTimeout(quiet, Math.max(minTimerMs, delay));
};

const quiet = () => {
    quietTimer = 0;
    const idleFor = performance.now() - lastScrollAt;

    if (windowScrolling) {
        if (idleFor < windowScrollIdleMs) {
            armQuiet(windowScrollIdleMs - idleFor);
            return;
        }
        emitWindowScrolling(false);
    }

    // A finger still rests on the page after a vertical drag or fling: keep the pause
    // without polling. touchend/touchcancel arm the final quiet() run.
    if (!scrollActive || touch?.vertical) return;

    if (idleFor < quietPeriod) {
        armQuiet(quietPeriod - idleFor);
        return;
    }
    setScrollActive(false);
};

const onWindowScroll = () => {
    lastScrollAt = performance.now();
    emitWindowScrolling(true);
    if (!scrollActive && scrollGate()) setScrollActive(true);
    if (!quietTimer) armQuiet(windowScrollIdleMs);
};

const ensureWindowScrollWatch = () => {
    if (windowScrollBound || typeof window === 'undefined') return;
    windowScrollBound = true;
    window.addEventListener('scroll', onWindowScroll, {passive: true});
};

// True while the window has scrolled within the last ~150ms, on any pointer type.
export const isWindowScrolling = (): boolean => windowScrolling;

// Subscribe to all-device scroll start/stop. Binds the shared passive listener on
// first use and returns an unsubscribe function. The callback receives `false` when
// scrolling has settled, which is the cue to resume a paused loop.
export const subscribeWindowScroll = (callback: (active: boolean) => void): (() => void) => {
    ensureWindowScrollWatch();
    windowScrollSubscribers.add(callback);

    return () => {
        windowScrollSubscribers.delete(callback);
    };
};

export const initScrollMotionScheduler = (): (() => void) => {
    if (cleanup) return cleanup;

    const root = document.documentElement;
    const coarse = window.matchMedia('(pointer: coarse)');
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    // Scroll-time pausing is for touch and lite devices. Desktop keeps its loops running while
    // it scrolls; they still pause off-screen through the observer below. The result is cached
    // and refreshed only by the media listeners and the attribute observer (preferences below),
    // so the scroll handler never evaluates a media query.
    const gate = () => (coarse.matches || root.dataset.avdPerf === 'lite')
        && !reduced.matches && root.dataset.avdMotion !== 'off';
    let gateOpen = gate();

    // Contract kept for hero-slider.blade.php and the rail marquee: html[data-avd-scroll-active]
    // is '1' or '0', plus a document 'avd:scroll-activity' event with {detail: {active}}.
    const setActive = (next: boolean) => {
        if (next === scrollActive) return;
        scrollActive = next;
        root.dataset.avdScrollActive = next ? '1' : '0';
        document.dispatchEvent(new CustomEvent('avd:scroll-activity', {detail: {active: next}}));
    };
    scrollGate = () => gateOpen;
    setScrollActive = setActive;

    // Data attributes, not classes: Preact and Alpine className writes would drop classes.
    const seen = new WeakSet<Element>();
    const marked = new Set<Element>();
    let pruneAt = 64;
    const setFlag = (element: Element, name: string, on: boolean) => {
        // Only real changes touch the DOM, so an unchanged state costs no style invalidation.
        if (element.hasAttribute(name) === on) return;
        if (on) element.setAttribute(name, '');
        else element.removeAttribute(name);
    };
    const forget = (element: Element) => {
        io?.unobserve(element);
        marked.delete(element);
        // A re-inserted host restarts its CSS loops (a new animationstart) and is marked again.
        seen.delete(element);
        element.removeAttribute(offscreenAttr);
    };
    const io = typeof IntersectionObserver === 'function'
        ? new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.target.isConnected) forget(entry.target);
                else setFlag(entry.target, offscreenAttr, !entry.isIntersecting);
            });
        }, {rootMargin: '160px 0px'})
        : undefined;
    // Hosts detached while already off-screen get no further observer callback; drop them in
    // batches so the observer does not keep detached Preact nodes alive.
    const prune = () => {
        marked.forEach((element) => {
            if (!element.isConnected) forget(element);
        });
        pruneAt = marked.size + 64;
    };
    const mark = (element: Element) => {
        if (seen.has(element)) return;
        seen.add(element);
        if (element.closest(excluded)) return;
        element.setAttribute(decoAttr, '');
        marked.add(element);
        // The first observer callback writes the real off-screen state.
        io?.observe(element);
        if (marked.size >= pruneAt) prune();
    };
    // Pseudo-element loops report their host element as the event target.
    const onAnimationStart = (event: AnimationEvent) => {
        if (event.target instanceof Element && decorativeNames.has(event.animationName)) mark(event.target);
    };
    // One catch-up pass, at idle, for loops that started before this listener existed. Hosts
    // that already carry data-avd-deco in the server HTML are observed as well, whatever their
    // keyframe names (an opt-in for viewport-replay CSS keyed on data-avd-offscreen).
    const catchUp = () => {
        document.querySelectorAll(`[${decoAttr}]`).forEach(mark);
        if (typeof document.getAnimations !== 'function' || typeof CSSAnimation === 'undefined') return;
        document.getAnimations().forEach((animation) => {
            if (!(animation instanceof CSSAnimation) || !decorativeNames.has(animation.animationName)) return;
            const target = (animation.effect as KeyframeEffect | null)?.target;
            if (target instanceof Element) mark(target);
        });
    };
    // Vertical touch intent raises the pause before the first scroll frame. While the finger is
    // down, quiet() leaves the pause on and does not re-arm; lifting it arms one final run, which
    // also covers a vertical drag that never scrolled the page.
    const touchStart = (event: TouchEvent) => {
        if (!gateOpen || event.touches.length !== 1) return;
        touch = {x: event.touches[0].clientX, y: event.touches[0].clientY, vertical: false};
    };
    const touchMove = (event: TouchEvent) => {
        if (!touch || touch.vertical || event.touches.length !== 1) return;
        const dy = Math.abs(event.touches[0].clientY - touch.y);
        const dx = Math.abs(event.touches[0].clientX - touch.x);
        if (dy > 8 && dy > dx) {
            touch.vertical = true;
            setActive(true);
        }
    };
    const touchEnd = () => {
        touch = undefined;
        if (scrollActive && !quietTimer) armQuiet(quietPeriod);
    };
    // One-shot replays of the bounded title sheen and active-chip pulse (motion.scss). The
    // host gets [data-avd-replay] on pointer entry (fine pointer, as the old :hover rule) or
    // keyboard focus (:focus-visible), and loses it on that replay's own animationend or
    // animationcancel, so leaving early never cancels (and snaps) a replay mid-way.
    // Not installed on embed or admin pages, which have no such hosts.
    const replayAttr = 'data-avd-replay';
    const replayHosts = '.avd-home-title, .avd-watch-title, .avd-tag.active';
    const replayNames = new Set(['avd-title-sheen-replay', 'avd-soft-pulse-replay']);
    const replayPointer = window.matchMedia('(hover: hover) and (pointer: fine)');
    const bodyClasses = document.body?.classList;
    const replays = !bodyClasses?.contains('avd-embed-shell') && !bodyClasses?.contains('avd-admin');
    const startReplay = (host: Element) => {
        // Reduced motion and lite run no title/chip animation, so nothing would clear it.
        if (reduced.matches || root.dataset.avdPerf === 'lite' || host.hasAttribute(replayAttr)) return;
        host.setAttribute(replayAttr, '');
    };
    const replayOnHover = (event: MouseEvent) => {
        if (!replayPointer.matches || !(event.target instanceof Element)) return;
        const host = event.target.closest(replayHosts);
        // Moving between the host's own children is not a new hover.
        if (!host || (event.relatedTarget instanceof Node && host.contains(event.relatedTarget))) return;
        startReplay(host);
    };
    const replayOnFocus = (event: FocusEvent) => {
        const target = event.target;
        if (!(target instanceof Element) || !target.matches('.avd-tag.active')) return;
        let keyboard = false;
        try {
            keyboard = target.matches(':focus-visible');
        } catch {
            // Engines without :focus-visible dropped the old CSS focus replay as well.
        }
        if (keyboard) startReplay(target);
    };
    const replayDone = (event: AnimationEvent) => {
        if (replayNames.has(event.animationName) && event.target instanceof Element) {
            event.target.removeAttribute(replayAttr);
        }
    };
    // Motion turned off, reduced motion, or the device left the gate: never stay paused.
    const preferences = () => {
        gateOpen = gate();
        if (gateOpen) return;
        touch = undefined;
        setActive(false);
    };
    // A bfcache restore must not bring back a page frozen in its mid-scroll paused state.
    const pageShow = (event: PageTransitionEvent) => {
        if (!event.persisted) return;
        touch = undefined;
        window.clearTimeout(quietTimer);
        quietTimer = 0;
        emitWindowScrolling(false);
        setActive(false);
    };
    const settingsObserver = new MutationObserver(preferences);
    settingsObserver.observe(root, {attributes: true, attributeFilter: ['data-avd-motion', 'data-avd-perf']});
    const watchMedia = (query: MediaQueryList, remove = false) => {
        if (query.addEventListener) {
            if (remove) query.removeEventListener('change', preferences);
            else query.addEventListener('change', preferences);
        } else if (remove) query.removeListener(preferences);
        else query.addListener(preferences);
    };
    watchMedia(coarse);
    watchMedia(reduced);
    ensureWindowScrollWatch();
    window.addEventListener('touchstart', touchStart, {passive: true});
    window.addEventListener('touchmove', touchMove, {passive: true});
    window.addEventListener('touchend', touchEnd, {passive: true});
    window.addEventListener('touchcancel', touchEnd, {passive: true});
    window.addEventListener('pageshow', pageShow);
    document.addEventListener('animationstart', onAnimationStart, true);
    if (replays) {
        document.addEventListener('mouseover', replayOnHover, {passive: true});
        document.addEventListener('focusin', replayOnFocus);
        document.addEventListener('animationend', replayDone, true);
        document.addEventListener('animationcancel', replayDone, true);
    }
    const idleCallbacks = typeof window.requestIdleCallback === 'function';
    const catchUpHandle = idleCallbacks
        ? window.requestIdleCallback(catchUp, {timeout: 2000})
        : window.setTimeout(catchUp, 300);

    cleanup = () => {
        if (idleCallbacks) window.cancelIdleCallback(catchUpHandle);
        else window.clearTimeout(catchUpHandle);
        window.removeEventListener('touchstart', touchStart);
        window.removeEventListener('touchmove', touchMove);
        window.removeEventListener('touchend', touchEnd);
        window.removeEventListener('touchcancel', touchEnd);
        window.removeEventListener('pageshow', pageShow);
        document.removeEventListener('animationstart', onAnimationStart, true);
        document.removeEventListener('mouseover', replayOnHover);
        document.removeEventListener('focusin', replayOnFocus);
        document.removeEventListener('animationend', replayDone, true);
        document.removeEventListener('animationcancel', replayDone, true);
        document.querySelectorAll(`[${replayAttr}]`).forEach((element) => element.removeAttribute(replayAttr));
        watchMedia(coarse, true);
        watchMedia(reduced, true);
        settingsObserver.disconnect();
        io?.disconnect();
        marked.forEach((element) => {
            element.removeAttribute(decoAttr);
            element.removeAttribute(offscreenAttr);
        });
        marked.clear();
        touch = undefined;
        setActive(false);
        // The shared scroll listener stays bound for subscribers; it only drives their signal now.
        scrollGate = () => false;
        setScrollActive = () => undefined;
        cleanup = undefined;
    };
    return cleanup;
};
