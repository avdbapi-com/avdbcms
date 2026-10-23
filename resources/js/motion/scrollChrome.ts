// Scroll chrome (perf v2): the header's scrolled shadow and the scroll progress bar, with no
// script per scroll frame on current engines.
// - Header elevation: one IntersectionObserver on a 14 px top sentinel toggles
//   .avd-header-elevated past 14 px of scroll, the threshold of the old scroll listener.
// - Progress bar: with scroll-driven animations (Chromium 115+, Safari 26+) it is a CSS scroll
//   timeline (motion.scss, [data-avd-timeline]) that runs on the compositor, attached while the page
//   is scrolled past its first pixel (a second sentinel). Older engines, and
//   html[data-avd-css-animations="off"] (whose '*' rule removes every CSS animation), keep the
//   rAF fallback below.
// app.ts installs both on public pages only (never admin or the Upload18 embed shell), under the
// switches that always gated them: AVD_SETTINGS.animeEffects on, motion not off, no reduced-motion
// preference. The bar also needs the balanced tier (scrollProgress): lite hides it, and a runtime
// downgrade to lite removes it.
import {allows, onTierChange} from './tier';

type Disposer = () => void;

// Edge-adjacent targets count as intersecting, so a 14 px sentinel at the top of the document
// stops intersecting exactly when window.scrollY > 14.
const HEADER_ELEVATION_PX = 14;

const installHeaderElevation = (): void => {
    const header = document.querySelector<HTMLElement>('.avd-header');

    if (!header || !document.body) {
        return;
    }

    let elevated = false;

    const elevate = (next: boolean) => {
        elevated = next;
        header.classList.toggle('avd-header-elevated', next);
    };

    // Re-applied after a theme switch, as the scroll listener did.
    window.addEventListener('theme-changed', () => elevate(elevated));

    if (typeof IntersectionObserver !== 'function') {
        const update = () => {
            const next = window.scrollY > HEADER_ELEVATION_PX;

            if (next !== elevated) {
                elevate(next);
            }
        };

        update();
        window.addEventListener('scroll', update, {passive: true});
        return;
    }

    const sentinel = document.createElement('div');
    sentinel.setAttribute('aria-hidden', 'true');
    // overflow-anchor:none: never the scroll anchor (it does not move with the content).
    sentinel.style.cssText = `position:absolute;top:0;left:0;width:1px;height:${HEADER_ELEVATION_PX}px;pointer-events:none;overflow-anchor:none;`;
    document.body.appendChild(sentinel);
    new IntersectionObserver((entries) => {
        elevate(!entries[entries.length - 1].isIntersecting);
    }).observe(sentinel);
};

const scrollTimelineSupported = (): boolean => {
    try {
        return typeof CSS !== 'undefined' && typeof CSS.supports === 'function' && CSS.supports('animation-timeline: scroll()');
    } catch {
        return false;
    }
};

const installScrollProgress = (): Disposer => {
    const progress = document.createElement('div');
    progress.className = 'avd-scroll-progress';

    if (scrollTimelineSupported() && document.documentElement.dataset.avdCssAnimations !== 'off') {
        document.body.appendChild(progress);

        if (typeof IntersectionObserver !== 'function') {
            progress.setAttribute('data-avd-timeline', '');

            return () => progress.remove();
        }

        // The timeline (a composited animation, and a layer for the bar) is attached only while the page is
        // scrolled: at the top the bar is invisible anyway (opacity 0 up to a 1.2% ratio), so a page at rest
        // there carries neither, like the script bar before it. A 1 px sentinel stops intersecting once
        // window.scrollY > 1 (edge-adjacent targets count as intersecting).
        const sentinel = document.createElement('div');
        sentinel.setAttribute('aria-hidden', 'true');
        sentinel.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:1px;pointer-events:none;overflow-anchor:none;';
        document.body.appendChild(sentinel);

        const observer = new IntersectionObserver((entries) => {
            const scrolled = !entries[entries.length - 1].isIntersecting;

            if (scrolled !== progress.hasAttribute('data-avd-timeline')) {
                if (scrolled) progress.setAttribute('data-avd-timeline', '');
                else progress.removeAttribute('data-avd-timeline');
            }
        });
        observer.observe(sentinel);

        return () => {
            observer.disconnect();
            sentinel.remove();
            progress.remove();
        };
    }

    document.body.appendChild(progress);

    let ticking = false;
    // document.documentElement.scrollHeight forces layout, so it must never be read inside
    // the per-scroll-frame update(). Cache the scrollable distance and refresh it off the
    // scroll path: a debounced resize, plus a ResizeObserver on <body> that also catches
    // content-height growth (lazy-loaded shelves, "load more") without a window resize.
    let docMax = 1;
    const measure = () => {
        docMax = Math.max(document.documentElement.scrollHeight - window.innerHeight, 1);
    };

    const update = () => {
        ticking = false;
        // Per frame: READ only the cheap scroll offset, then WRITE styles. No reflow.
        const ratio = Math.min(Math.max(window.scrollY / docMax, 0), 1);

        progress.style.transform = `scaleX(${ratio})`;
        progress.style.opacity = ratio > 0.012 ? '1' : '0';
    };

    const requestUpdate = () => {
        if (ticking) {
            return;
        }

        ticking = true;
        window.requestAnimationFrame(update);
    };

    const refresh = () => {
        measure();
        requestUpdate();
    };

    measure();
    update();
    window.addEventListener('scroll', requestUpdate, {passive: true});

    let resizeTimer: number | null = null;
    const onResize = () => {
        if (resizeTimer !== null) window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(refresh, 150);
    };
    window.addEventListener('resize', onResize);

    const resizeObserver = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(refresh) : null;
    resizeObserver?.observe(document.body);

    return () => {
        window.removeEventListener('scroll', requestUpdate);
        window.removeEventListener('resize', onResize);
        if (resizeTimer !== null) window.clearTimeout(resizeTimer);
        resizeObserver?.disconnect();
        progress.remove();
    };
};

const whenIdle = (task: () => void): void => {
    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(() => task(), {timeout: 1500});
    } else {
        window.setTimeout(task, 300);
    }
};

let started = false;

export const initScrollChrome = (): void => {
    if (started || !document.body) {
        return;
    }

    started = true;
    installHeaderElevation();

    // The bar comes at the first idle moment, off the boot path (the fallback measures the page).
    whenIdle(() => {
        if (!allows('scrollProgress')) {
            return;
        }

        const dispose = installScrollProgress();
        const stop = onTierChange(() => {
            if (!allows('scrollProgress')) {
                stop();
                dispose();
            }
        });
    });
};
