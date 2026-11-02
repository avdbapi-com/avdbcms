// The one click ripple engine, on every page of resources/views/components/layout.blade.php (the Upload18 embed shell
// has its own layout). Until 1.2.3 it was ~9 KB of classic inline script at the end of every page's body,
// compiled during the parse without a code cache (member design 4.3: the guest layout is <= 100 KB / 20 KB gzip);
// here it is part of the cached module bundle and is installed by ./core before DOMContentLoaded. The behaviour is the
// inline engine's:
// - each target gets one span, reused on every click and animated with WAAPI (compositor transform and opacity), and
//   taken out again when its ripple ends, so the target's markup is unchanged at rest. Engines without WAAPI (Safari <
//   13.1, older WebViews) run the same ripple as a CSS animation (.avd-click-ripple-css);
// - mouse ripples on press; touch and pen ripple on 'click', which only taps fire (pointerdown also starts every
//   scroll gesture); cards get no touch ripple (their coarse :active press is the tap feedback);
// - the card tilt, spotlight, glare, magnet, click burst and cursor halo live in resources/js/motion/pointerFx.ts.

const RIPPLE_TARGETS = '.btn, .avd-chip, .avd-tag, .avd-category-pill, .nav-link, .avd-mob-nav-item, .avd-sidebar-link, .avd-shelf-paddle, .avd-player-control, .avd-video-card';
// Mouse clicks keep a ripple on the controls only the motion chunk's ripple covered (public pages, rich tier, full
// motion, anime effects on).
const RICH_MOUSE_TARGETS = '.avd-icon-button, .avd-sidebar-toggle, .avd-social-btn, .avd-action-pill';
// Where the layout's ripples never showed, the motion chunk's script-driven mouse ripple still did, on its own
// controls: the click ripple switch off, the motion engine 'off' setting (it cuts every CSS animation to 1 ms) and
// 'CSS animations off' (it removes them).
const MOTION_CHUNK_TARGETS = '.btn:not(.btn-close), .avd-icon-button, .avd-sidebar-toggle, .avd-tag, .avd-social-btn, .avd-player-control, .avd-action-pill';
// Framework-managed media subtrees (player, preroll ad, upload) never get injected nodes.
const EXCLUDED_SUBTREES = 'video-player, video-upload, .avd-player-stage, .avd-preroll-ad, .avd-embed-shell';

type RippleEntry = {span: HTMLSpanElement; animation: Animation | null; timer: number};

let installed = false;

export const installRipple = (): void => {
    if (installed || !document.body || typeof WeakMap !== 'function') {
        return;
    }

    installed = true;
    const root = document.documentElement;
    const waapi = typeof document.body.animate === 'function';
    const reducedMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    const ripples = new WeakMap<Element, RippleEntry>();
    let pendingTap: HTMLElement | null = null;

    // The balanced tier or above (lite has none), motion on and no reduced-motion preference.
    const rippleOn = (): boolean => {
        if (root.getAttribute('data-avd-motion') === 'off') return false;
        if (reducedMotion && reducedMotion.matches) return false;

        return window.AVD_TIER ? window.AVD_TIER.at('balanced') : root.getAttribute('data-avd-perf') !== 'lite';
    };

    // pointerFx (rich tier, fine pointer) sets data-avd-pointer-budget="ok" when the page is within the old motion
    // chunk's budget (128 cards, comments, list and dropdown items), as its ripple required.
    const richMouseRipple = (): boolean => !document.body.classList.contains('avd-admin')
        && (root.getAttribute('data-avd-motion') || 'full') === 'full'
        && !!(window.AVD_TIER && window.AVD_TIER.at('rich'))
        && !(window.AVD_SETTINGS?.animeEffects === false)
        && root.getAttribute('data-avd-pointer-budget') === 'ok';

    const rippleTargetFor = (node: EventTarget | null, mouse: boolean): HTMLElement | null => {
        if (!(node instanceof Element) || !rippleOn()) return null;
        let target: HTMLElement | null;
        if (root.getAttribute('data-avd-ripple') !== 'on' || root.getAttribute('data-avd-motion-engine') === 'off' || root.getAttribute('data-avd-css-animations') === 'off') {
            target = mouse && richMouseRipple() ? node.closest<HTMLElement>(MOTION_CHUNK_TARGETS) : null;
        } else {
            target = node.closest<HTMLElement>(RIPPLE_TARGETS) || (mouse && richMouseRipple() ? node.closest<HTMLElement>(RICH_MOUSE_TARGETS) : null);
        }
        if (!target || target.getAttribute('data-avd-no-ripple') === 'true' || target.closest(EXCLUDED_SUBTREES)) return null;

        return target;
    };

    const spawnRipple = (target: HTMLElement, clientX: number, clientY: number): void => {
        let entry = ripples.get(target);
        if (!entry) {
            const span = document.createElement('span');
            span.className = 'avd-click-ripple';
            span.setAttribute('aria-hidden', 'true');
            entry = {span, animation: null, timer: 0};
            ripples.set(target, entry);
        }
        const current = entry;
        const rect = target.getBoundingClientRect();
        const size = Math.max(rect.width, rect.height) * 2;
        const style = window.getComputedStyle(target);
        // The target clips its ripple. The stylesheet makes most targets relative and clipped; the others get the
        // inline pair the old engine wrote on every click.
        if (style.position === 'static') target.style.position = 'relative';
        if (style.overflow !== 'hidden') target.style.overflow = 'hidden';
        if (current.animation) current.animation.cancel();
        current.span.style.width = current.span.style.height = `${size}px`;
        current.span.style.left = `${clientX - rect.left - size / 2}px`;
        current.span.style.top = `${clientY - rect.top - size / 2}px`;
        if (current.span.parentNode !== target) target.appendChild(current.span);
        if (!waapi) {
            // Restart the CSS ripple (a reflow between dropping and adding the class), and take the span out once
            // it is over.
            current.span.classList.remove('avd-click-ripple-css');
            void current.span.offsetWidth;
            current.span.classList.add('avd-click-ripple-css');
            window.clearTimeout(current.timer);
            current.timer = window.setTimeout(() => {
                current.timer = 0;
                current.span.classList.remove('avd-click-ripple-css');
                current.span.parentNode?.removeChild(current.span);
            }, 700);

            return;
        }
        const animation = current.span.animate([
            {transform: 'scale(0)', opacity: 1},
            {transform: 'scale(1)', opacity: 0},
        ], {duration: 650, easing: 'ease-out'});
        current.animation = animation;
        animation.onfinish = () => {
            if (current.animation !== animation) return;
            current.animation = null;
            current.span.parentNode?.removeChild(current.span);
        };
    };

    // Mouse ripples on press, as before. Touch and pen: pointerdown also starts every scroll gesture, so remember the
    // target and ripple on 'click', which only taps fire. Cards get no touch ripple.
    document.addEventListener('pointerdown', (e: PointerEvent) => {
        const mouse = e.pointerType === 'mouse';
        const target = rippleTargetFor(e.target, mouse);
        pendingTap = null;
        if (!target) return;
        if (mouse) {
            spawnRipple(target, e.clientX, e.clientY);
        } else if (!target.classList.contains('avd-video-card')) {
            pendingTap = target;
        }
    }, {passive: true});

    document.addEventListener('pointercancel', () => {
        pendingTap = null;
    }, {passive: true});

    // Capture phase: buttons whose own handlers stop the click (card actions, carousel controls) still get their tap
    // ripple. Engines without pointer events (Safari < 13) ripple every input on click, cards excepted.
    const pointerEvents = typeof window.PointerEvent === 'function';
    document.addEventListener('click', (e: MouseEvent) => {
        let target = pendingTap;
        pendingTap = null;
        if (!pointerEvents) {
            target = e.detail ? rippleTargetFor(e.target, false) : null;
            if (target && !target.classList.contains('avd-video-card')) spawnRipple(target, e.clientX, e.clientY);

            return;
        }
        if (!target || !e.detail || !target.isConnected) return;
        if (rippleTargetFor(e.target, false) !== target) return;
        spawnRipple(target, e.clientX, e.clientY);
    }, {capture: true, passive: true});
};
