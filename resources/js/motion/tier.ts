// Capability tier runtime (perf v2).
//
// The tier is decided by the inline <head> script in resources/views/components/layout.blade.php
// before the first paint: html[data-avd-tier] = rich | balanced | lite (html[data-avd-perf] stays
// the legacy alias: 'lite' exactly when the tier is lite), with window.AVD_TIER as its API. Every
// runtime change is a downgrade and dispatches a document 'avd:tier' event.
//
// This module reads that decision for the TypeScript side and runs the vsync-relative rAF probe,
// which may LOWER the tier (never raise it). Pages without the attribute (the Upload18 embed shell)
// have no tier system: tier() reports 'rich' there, so nothing changes, and the probe never starts.

export type Tier = 'lite' | 'balanced' | 'rich';

export type TierFx =
    | 'pageIntro'
    | 'revealMotion'
    | 'widgetCascade'
    | 'autoRail'
    | 'pointerFx'
    | 'tilt'
    | 'spotlight'
    | 'clickBurst'
    | 'cursorHalo'
    | 'particles'
    | 'modalFx'
    | 'reveal'
    | 'imageReveal'
    | 'ripple'
    | 'heroProgress'
    | 'scrollProgress';

export type TierApi = {
    get(): Tier;
    src(): string;
    at(min: Tier): boolean;
    down(to: Tier, why?: string): boolean;
    ok(): void;
};

declare global {
    interface Window {
        AVD_TIER?: TierApi;
    }
}

const ORDER: readonly Tier[] = ['lite', 'balanced', 'rich'];

// The lowest tier each effect runs on (the plan's effect matrix). The modal entrance is WAAPI on
// rich only; balanced keeps Bootstrap's own CSS transition, so its JS stays within short fades.
const MIN: Record<TierFx, Tier> = {
    pageIntro: 'rich',
    revealMotion: 'rich',
    widgetCascade: 'rich',
    autoRail: 'rich',
    pointerFx: 'rich',
    tilt: 'rich',
    spotlight: 'rich',
    clickBurst: 'rich',
    cursorHalo: 'rich',
    particles: 'rich',
    modalFx: 'rich',
    reveal: 'balanced',
    imageReveal: 'balanced',
    ripple: 'balanced',
    heroProgress: 'balanced',
    scrollProgress: 'balanced',
};

// Effects that follow the pointer also need a hovering, fine pointer.
const POINTER_FX: ReadonlySet<TierFx> = new Set<TierFx>(['pointerFx', 'tilt', 'spotlight', 'clickBurst', 'cursorHalo']);

const mediaLists = new Map<string, MediaQueryList>();
const matches = (query: string): boolean => {
    let list = mediaLists.get(query);
    if (!list) {
        list = window.matchMedia(query);
        mediaLists.set(query, list);
    }

    return list.matches;
};

const isTier = (value: unknown): value is Tier => value === 'lite' || value === 'balanced' || value === 'rich';

// The current tier, read live from the attribute (the <head> script and AVD_TIER.down() keep it
// current). Without the attribute there is no tier system, which means no tier restrictions.
export const tier = (): Tier => {
    const value = document.documentElement.getAttribute('data-avd-tier');

    return isTier(value) ? value : 'rich';
};

export const atLeast = (min: Tier): boolean => ORDER.indexOf(tier()) >= ORDER.indexOf(min);

// Whether an effect may run now: motion not switched off by the admin, no reduced-motion
// preference, the tier at or above the effect's minimum, and a hovering fine pointer for the
// pointer-driven effects. The admin data-avd-* switches still decide whether an effect exists.
export const allows = (fx: TierFx): boolean => {
    if (document.documentElement.dataset.avdMotion === 'off' || matches('(prefers-reduced-motion: reduce)')) {
        return false;
    }

    if (!atLeast(MIN[fx])) {
        return false;
    }

    return !POINTER_FX.has(fx) || matches('(hover: hover) and (pointer: fine)');
};

// Called with the new tier after every runtime downgrade. Returns an unsubscribe function.
export const onTierChange = (callback: (next: Tier) => void): (() => void) => {
    const listener = () => callback(tier());
    document.addEventListener('avd:tier', listener);

    return () => document.removeEventListener('avd:tier', listener);
};

// ---------------------------------------------------------------------------------------------
// rAF probe: one 1.5 s sample at the first idle moment after load (window A) and one on the
// first scroll (window B), each at most once per tab session. A verdict is only taken while the
// page is visible and not prerendering: rAF does not run in background tabs or prerendered pages,
// and a sample from there would read as 'starved'. Any visibility or prerendering change aborts
// the sample, which is retried later. A single-signal downgrade only pins this tab session; the
// <head> script writes the 7-day hint once a second independent signal agrees.
//
// How far one bad (janky or starved) window may lower the tier depends on the engine:
// - With Long Animation Frames (Chromium) the <head> LoAF governor watches the same windows, so
//   the probe keeps its direct rule: starved -> lite, janky -> one tier down.
// - Without them (Safari, Firefox) the probe is the only runtime signal, and one sample can be
//   spoiled by the engine itself (load work, a slow renderer). A single bad window lowers rich to
//   balanced at most; lite needs the other window of this tab session to have been bad too, or an
//   earlier session that already ended on lite through the probe (its pending hint, the existing
//   hint policy's second session). The <head> script then confirms the 7-day hint as before.
// ---------------------------------------------------------------------------------------------

type Verdict = 'ok' | 'janky' | 'starved' | 'inconclusive' | 'aborted';

const SAMPLE_MS = 1500;

type PrerenderDocument = Document & {prerendering?: boolean};

const pageHidden = (): boolean => document.visibilityState !== 'visible' || (document as PrerenderDocument).prerendering === true;

const clamp = (value: number, min: number, max: number): number => Math.max(min, Math.min(max, value));

// Vsync-relative verdict over the rAF timestamps of one sample.
const judge = (stamps: number[], endedAt: number): Verdict => {
    const gaps: number[] = [];

    for (let i = 1; i < stamps.length; i += 1) {
        gaps.push(stamps[i] - stamps[i - 1]);
    }

    if (gaps.length < 3) {
        return 'starved';
    }

    const sorted = gaps.slice().sort((a, b) => a - b);

    // Privacy-clamped timers (Firefox resistFingerprinting) report many identical timestamps:
    // there is no usable cadence, so no verdict either way.
    if (sorted.filter((gap) => gap < 1).length > sorted.length / 4) {
        return 'inconclusive';
    }

    // The display's frame interval: the mean of the 10th-25th percentile gaps, clamped to
    // 6.9-17.5 ms (144 Hz .. 57 Hz). Not the minimum: one short gap after a long task would make
    // a healthy 60 Hz screen look janky.
    const from = Math.floor(sorted.length * 0.1);
    const to = Math.max(from, Math.floor(sorted.length * 0.25));
    let sum = 0;

    for (let i = from; i <= to; i += 1) {
        sum += sorted[i];
    }

    const raw = sum / (to - from + 1);
    const p75 = sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * 0.75))];
    // A steady 22-40 fps cadence (even the 10th-25th percentile gaps are above 25 ms, and the 75th percentile
    // stays within 30% of them) is a frame-rate cap: Low Power Mode, thermal mitigation, Energy Saver. Judge it
    // against its own interval.
    // Clamped to 17.5 ms it would double the expected frame count, and a single 133 ms stall at 30 fps
    // would read as janky while a 60 Hz screen tolerates several 200 ms stalls. Slower cadences stay
    // clamped, so a 10-20 fps device is still starved or janky.
    const capped = raw > 25 && raw < 45 && p75 <= raw * 1.3;
    const base = capped ? raw : clamp(raw, 6.9, 17.5);
    const expected = Math.max(0, endedAt - stamps[0]) / base;

    if (gaps.length < expected * 0.2) {
        return 'starved';
    }

    if (gaps.length < expected * 0.5 || p75 > base * 2.2) {
        return 'janky';
    }

    return 'ok';
};

const sample = (): Promise<Verdict> => new Promise((resolve) => {
    if (pageHidden()) {
        resolve('aborted');
        return;
    }

    const stamps: number[] = [];
    let frame = 0;
    let backstop = 0;
    let done = false;

    const finish = (verdict: Verdict): void => {
        if (done) return;
        done = true;
        window.cancelAnimationFrame(frame);
        window.clearTimeout(backstop);
        document.removeEventListener('visibilitychange', abort);
        document.removeEventListener('prerenderingchange', abort);
        resolve(verdict);
    };
    const abort = (): void => finish('aborted');
    const tick = (now: number): void => {
        if (pageHidden()) {
            abort();
            return;
        }

        stamps.push(now);

        if (now - stamps[0] >= SAMPLE_MS) {
            finish(judge(stamps, now));
            return;
        }

        frame = window.requestAnimationFrame(tick);
    };

    // A starved page may never deliver enough frames to end the sample by itself.
    backstop = window.setTimeout(() => {
        if (pageHidden()) abort();
        else finish(stamps.length ? judge(stamps, performance.now()) : 'starved');
    }, SAMPLE_MS + 500);

    document.addEventListener('visibilitychange', abort);
    document.addEventListener('prerenderingchange', abort);
    frame = window.requestAnimationFrame(tick);
});

const sessionFlag = (key: string): boolean => {
    try {
        return window.sessionStorage.getItem(key) === '1';
    } catch {
        return false;
    }
};

const setSessionFlag = (key: string): void => {
    try {
        window.sessionStorage.setItem(key, '1');
    } catch {
        /* no storage: the window simply runs again on the next page */
    }
};

// Long Animation Frames: the engines where the <head> LoAF governor runs (Chromium 123+).
const hasLongAnimationFrames = (): boolean => {
    try {
        const types = typeof PerformanceObserver === 'function' ? PerformanceObserver.supportedEntryTypes : undefined;

        return Array.isArray(types) && types.indexOf('long-animation-frame') >= 0;
    } catch {
        return false;
    }
};

// Engines without LoAF: the probe windows of this tab session that came out janky or starved, as
// sessionStorage avd_probe_bad ('avd_probe_a', 'avd_probe_b' or both, comma-separated), with this
// page's own record as the fallback without storage.
const BAD_WINDOWS_KEY = 'avd_probe_bad';
const badWindowsHere: string[] = [];

const badWindows = (): string[] => {
    try {
        const stored = window.sessionStorage.getItem(BAD_WINDOWS_KEY);

        if (stored) return stored.split(',');
    } catch {
        /* no storage: this page's own record */
    }

    return badWindowsHere;
};

const rememberBadWindow = (flag: string): void => {
    const windows = badWindows().filter((name) => name !== flag).concat(flag);

    badWindowsHere.splice(0, badWindowsHere.length, ...windows);

    try {
        window.sessionStorage.setItem(BAD_WINDOWS_KEY, windows.join(','));
    } catch {
        /* no storage: kept for this page only */
    }
};

const HINT_TTL_MS = 6048e5;

// An earlier tab session (same build, within the hint's 7 days) whose probe already took it to lite: the
// pending single-signal hint the <head> script left ({t: 'lite', why: 'probe', n: 1} from another sid).
const earlierSessionEndedLite = (): boolean => {
    try {
        const hint = JSON.parse(window.localStorage.getItem('avd_tier_hint') || 'null') as {t?: unknown, ts?: unknown, v?: unknown, why?: unknown, n?: unknown, sid?: unknown} | null;
        const sid = window.sessionStorage.getItem('avd_tier_sid') || '';

        if (!hint || typeof hint !== 'object' || typeof hint.ts !== 'number' || sid === '') {
            return false;
        }

        return hint.t === 'lite' && hint.why === 'probe' && hint.n === 1 && hint.sid !== sid
            && hint.v === (document.documentElement.getAttribute('data-avd-build') || '')
            && Date.now() - hint.ts < HINT_TTL_MS;
    } catch {
        return false;
    }
};

const whenLoaded = (callback: () => void): void => {
    if (document.readyState === 'complete') callback();
    else window.addEventListener('load', () => callback(), {once: true});
};

const whenIdle = (callback: () => void, timeout: number): void => {
    if (typeof window.requestIdleCallback === 'function') window.requestIdleCallback(() => callback(), {timeout});
    else window.setTimeout(callback, 300);
};

const whenVisible = (callback: () => void): void => {
    if (!pageHidden()) {
        callback();
        return;
    }

    const check = (): void => {
        if (pageHidden()) return;
        document.removeEventListener('visibilitychange', check);
        document.removeEventListener('prerenderingchange', check);
        callback();
    };

    document.addEventListener('visibilitychange', check);
    document.addEventListener('prerenderingchange', check);
};

let probing = false;
const queuedProbes: Array<() => void> = [];

// One sample at a time; a window that opens while another samples waits for it.
const runProbe = (flag: string, retry: () => void): void => {
    const api = window.AVD_TIER;

    if (!api || tier() === 'lite' || sessionFlag(flag)) {
        return;
    }

    if (probing) {
        queuedProbes.push(() => runProbe(flag, retry));
        return;
    }

    probing = true;

    void sample().then((verdict) => {
        probing = false;

        if (verdict === 'aborted') {
            retry();
        } else {
            setSessionFlag(flag);

            if ((verdict === 'starved' || verdict === 'janky') && hasLongAnimationFrames()) {
                // Chromium (the LoAF governor watches too): unchanged.
                if (verdict === 'starved') {
                    api.down('lite', 'probe');
                } else {
                    api.down(tier() === 'rich' ? 'balanced' : 'lite', 'probe');
                }
            } else if (verdict === 'starved' || verdict === 'janky') {
                // Safari, Firefox: the probe alone. Read before this window is recorded.
                const otherWindowBad = badWindows().indexOf(flag === 'avd_probe_a' ? 'avd_probe_b' : 'avd_probe_a') >= 0;

                rememberBadWindow(flag);

                if (otherWindowBad || earlierSessionEndedLite()) {
                    api.down('lite', 'probe');
                } else if (tier() === 'rich') {
                    api.down('balanced', 'probe');
                }
            } else if (verdict === 'ok') {
                // A clean run clears a single-signal hint left by an earlier session.
                api.ok();
            }
        }

        queuedProbes.shift()?.();
    });
};

// Window A: the first idle moment after load (timeout 3 s), while the page is visible.
const probeAfterLoad = (): void => {
    whenVisible(() => whenIdle(() => runProbe('avd_probe_a', probeAfterLoad), 3000));
};

// Window B: the first scroll (passive). An aborted sample re-arms on the next scroll.
const probeOnFirstScroll = (): void => {
    window.removeEventListener('scroll', probeOnFirstScroll);
    runProbe('avd_probe_b', () => window.addEventListener('scroll', probeOnFirstScroll, {passive: true}));
};

let probeStarted = false;

// app.ts calls this on public pages only (never admin or embed). It is a no-op without the tier
// system, when the tier is pinned (admin, visitor or QA pin), already lite, or inside a frame.
export const startTierProbe = (): void => {
    const api = window.AVD_TIER;
    const root = document.documentElement;

    if (probeStarted || !api || !root.hasAttribute('data-avd-tier')) {
        return;
    }

    probeStarted = true;

    const source = root.getAttribute('data-avd-tier-src');

    if (source === 'admin' || source === 'user' || source === 'qa' || tier() === 'lite') {
        return;
    }

    // A framed copy of the site is throttled while off-screen: its rAF cadence says nothing
    // about the device.
    try {
        if (window.self !== window.top) return;
    } catch {
        return;
    }

    whenLoaded(probeAfterLoad);
    window.addEventListener('scroll', probeOnFirstScroll, {passive: true});
};
