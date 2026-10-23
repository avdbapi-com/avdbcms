// Layout core (perf plan step 25b): the page features of resources/views/components/layout.blade.php
// that were ~85 KB of classic inline script at the end of the body, compiled during the parse of
// every page without a code cache. As part of the module bundle they are cached and run once the
// document has been parsed, before DOMContentLoaded, on every page of that layout (public and admin);
// other layouts (the Upload18 embed shell, maintenance) have no #avd-i18n and nothing runs there.
//
// What stays inline in the layout, for timing: the saved accent and personal glow restore (in the
// <head> theme script, before the first paint), the theater-mode restore (at its old point in the
// parse), the first watch-later badge pass (at the end of the parse), the click ripple engine, and the
// queue stubs below.
//
// Every window.* name keeps working at the moment it is called. A small inline script in the <head>
// defines queue stubs for the names that onclick attributes, show.blade.php and admin pages call
// (window.__avdQ); this module replaces them with the real functions and replays the queued calls.
// The Daily HUD's controls load with their chunk (./daily-hud) on the first
// openDailyAestheticModal(), the rich tier's particle canvas with ./decor.
import {Modal} from 'bootstrap';
import {optionalLoad} from '../dom/navIntent';
import {installAudio} from './audio';
import {byId, escapeHtml, layoutConfig, safely, store, syncGlowGroup, type LayoutConfig, type LayoutUser} from './config';

type Queued = [string, unknown[]];
type AnyFunction = (...args: unknown[]) => unknown;

declare global {
    interface Window {
        __avdQ?: Queued[] | {push: (entry: Queued) => number};
        __avdCore?: boolean;
        avdToggleTheater: () => void;
        avdToggleLightsOut: () => void;
        showCopyToast: (msg?: string) => void;
        avdToast: (msg: string, type?: string, iconClass?: string) => void;
        alertToast: (msg: string, type?: string, iconClass?: string) => void;
        avdQuickShare: (url?: string, title?: string) => void;
        avdQuickSave: (videoId: string | number) => void;
        avdShowAuthPrompt: (options?: {title?: string; message?: string; icon?: string}) => void;
        updateWatchLaterCardBadges: (root?: Element | Document | null) => void;
        openDailyAestheticModal: () => void;
        openSpotlightSearch: () => void;
        closeSpotlightSearch: () => void;
        AVD_USER: LayoutUser;
        __avdAddedRootsBus?: boolean;
    }
}

const win = window as unknown as Record<string, unknown>;

// Watch-page player controls (show.blade.php) the global shortcuts call when present.
const call = (name: string, ...args: unknown[]): boolean => {
    const fn = win[name];

    if (typeof fn !== 'function') {
        return false;
    }

    (fn as AnyFunction)(...args);
    return true;
};

const playClick = () => {
    if (window.AvdAudio) window.AvdAudio.playClick();
};
const playChime = () => {
    if (window.AvdAudio) window.AvdAudio.playChime();
};

// At DOMContentLoaded, where the inline scripts ran this work, not at once: this module runs just before
// that event, ahead of the feed mount, and the geometry reads below would force the page's first layout
// there. Later, if the event has passed (navigation timing has its start).
const atDomContentLoaded = (callback: () => void): void => {
    let done = false;
    const run = (): void => {
        if (!done) {
            done = true;
            callback();
        }
    };
    const timing = window.performance && window.performance.timing;

    if (document.readyState === 'complete' || (timing && timing.domContentLoadedEventStart > 0)) {
        run();
        return;
    }

    document.addEventListener('DOMContentLoaded', run, {once: true});
    window.addEventListener('load', run, {once: true});
};

// --sidebar-top follows the header's bottom edge. That edge only moves while the header itself
// travels: a sticky header until it docks at the top edge (ad slot, alert banner or header margin
// above it), a static header until it has scrolled away. An IntersectionObserver on the header arms
// the scroll listener for exactly that stretch, so a docked header costs no work per scroll frame.
const initSidebarTop = (): void => {
    const header = document.querySelector<HTMLElement>('.header');

    if (!header) {
        return;
    }

    const sticky = header.classList.contains('sticky-top');
    let sidebars: HTMLElement[] | null = null;
    let rafId = 0;
    let scrollArmed = false;

    const getSidebars = (): HTMLElement[] | null => {
        if (!sidebars && document.readyState !== 'loading') {
            sidebars = Array.prototype.slice.call(document.querySelectorAll('#responsive-sidebar, .avd-watch-sidebar'));
        }

        return sidebars;
    };

    const setSidebarTop = (): void => {
        const list = getSidebars();

        if (!list || !list.length) {
            return;
        }

        const rect = header.getBoundingClientRect();
        // A docked sticky header (listener released) sits at the top edge: use its height, so a
        // sub-pixel scroll position at the moment it docked cannot leave a rounding gap that would
        // then never be refreshed.
        const bottom = sticky && !scrollArmed && rect.top < 1 ? rect.bottom - Math.max(0, rect.top) : rect.bottom;
        const value = Math.max(0, Math.round(bottom)) + 'px';

        // Only these containers consume the offset; root inheritance would recalculate styles
        // across the whole page on scroll.
        for (let i = 0; i < list.length; i++) {
            if (list[i].style.getPropertyValue('--sidebar-top') !== value) {
                list[i].style.setProperty('--sidebar-top', value);
            }
        }
    };

    const scheduleSidebarTop = (): void => {
        if (rafId) {
            return;
        }

        rafId = window.requestAnimationFrame(() => {
            rafId = 0;
            setSidebarTop();
        });
    };

    const armScroll = (armed: boolean): void => {
        if (armed === scrollArmed) {
            return;
        }

        scrollArmed = armed;

        if (armed) {
            window.addEventListener('scroll', scheduleSidebarTop, {passive: true});
        } else {
            window.removeEventListener('scroll', scheduleSidebarTop);
        }

        // Settle the final value once whenever the listener is armed or released.
        scheduleSidebarTop();
    };

    // No measurement here: the inline script's parse-time call never measured (the sidebars were not
    // parsed yet), the first value came after DOMContentLoaded, as it still does.
    atDomContentLoaded(scheduleSidebarTop);
    window.addEventListener('load', scheduleSidebarTop, {passive: true});
    document.addEventListener('closed.bs.alert', scheduleSidebarTop);

    if ('IntersectionObserver' in window) {
        // Sticky: the root is shrunk by 1px at the top, so docking (top 0) drops the ratio below 1
        // and undocking brings it back. Static: the header leaving or re-entering the viewport is the
        // only transition.
        new IntersectionObserver((entries) => {
            const entry = entries[entries.length - 1];
            const rect = entry.boundingClientRect;

            armScroll(sticky ? rect.top >= 1 : (entry.isIntersecting || rect.bottom > 0));
        }, sticky ? {rootMargin: '-1px 0px 0px 0px', threshold: [0, 1]} : {threshold: [0]}).observe(header);
    } else {
        armScroll(true);
    }

    // Size changes of the header, the ad slot or the alert move the edge too; window resizes only
    // matter through those sizes, so they need no listener of their own.
    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(scheduleSidebarTop);

        ['.header', '#ad-slot-header', '.page-wrapper > .alert'].forEach((selector) => {
            const element = document.querySelector(selector);

            if (element) {
                observer.observe(element);
            }
        });
    }
};

// The transparent header bar sits over the home hero (html[data-avd-header-style="transparent_overlay"]
// and a .avd-hero-zone on the page). Once the hero has left the screen under the bar, the bar takes the
// solid page colours (html[data-avd-past-hero], layout-theme-a.css), so its links stay readable over the
// feed in both modes. An IntersectionObserver on the hero zone: no script runs while scrolling.
const initOverlayHeader = (): void => {
    const root = document.documentElement;
    const hero = document.querySelector<HTMLElement>('.avd-hero-zone');

    if (root.getAttribute('data-avd-header-style') !== 'transparent_overlay' || !hero || typeof IntersectionObserver !== 'function') {
        return;
    }

    // The bar is at most 72px high: the hero counts as gone once nothing of it shows below that.
    new IntersectionObserver((entries) => {
        root.toggleAttribute('data-avd-past-hero', !entries[entries.length - 1].isIntersecting);
    }, {rootMargin: '-72px 0px 0px 0px'}).observe(hero);
};

// A select that Tom Select has enhanced (the footer language select, filters...) stays in the page as a hidden 1px
// element, out of the tab order; focus that still reaches it (a script, focus the browser restores, a label) moves to
// its visible control, so focus never rests on an invisible element. One capture listener, no per-select work.
const initEnhancedSelectFocus = (): void => {
    document.addEventListener('focusin', (event) => {
        const select = event.target as (HTMLSelectElement & {tomselect?: {focus: () => void}}) | null;

        if (select instanceof HTMLSelectElement && select.classList.contains('tomselected') && select.tomselect) {
            select.tomselect.focus();
        }
    }, true);
};

// Back-to-top shows once the page has scrolled past 420px. A sentinel is watched by an
// IntersectionObserver, so scrolling runs no script here; the class is only written on an actual
// transition. It spans the top 420px of the document: in view at scrollY 0 on any viewport height, so
// every crossing of 420 flips it, jumps included (scroll restoration, End key), and it reproduces
// scrollY > 420 exactly (an edge-adjacent sentinel still counts as intersecting). overflow-anchor:none
// keeps it from becoming the scroll anchor: it never moves with the content, so Chrome would stop
// compensating for content that grows above the viewport.
const initBackToTop = (): void => {
    const btt = byId('back-to-top');

    if (!btt) {
        return;
    }

    let bttVisible: boolean | null = null;
    const setBackToTop = (shouldShow: boolean): void => {
        if (shouldShow === bttVisible) {
            return;
        }
        bttVisible = shouldShow;
        btt.classList.toggle('visible', shouldShow);
        // Hidden (opacity 0), it is out of the tab order too: no invisible focus stop at the end of every page.
        btt.tabIndex = shouldShow ? 0 : -1;
    };

    if (typeof IntersectionObserver === 'function') {
        let sentinel = byId('avd-top-sentinel');
        if (!sentinel) {
            sentinel = document.createElement('div');
            sentinel.id = 'avd-top-sentinel';
            sentinel.setAttribute('aria-hidden', 'true');
            sentinel.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:420px;pointer-events:none;overflow-anchor:none';
            document.body.insertBefore(sentinel, document.body.firstChild);
        }
        new IntersectionObserver((entries) => {
            setBackToTop(!entries[entries.length - 1].isIntersecting);
        }).observe(sentinel);
    } else {
        let frame = 0;
        window.addEventListener('scroll', () => {
            if (frame) {
                return;
            }
            frame = window.requestAnimationFrame(() => {
                frame = 0;
                setBackToTop(window.scrollY > 420);
            });
        }, {passive: true});
    }
};

// Theater mode and lights out. The saved theater mode is restored by the layout's inline script at
// its old point in the parse; the toggle below changes it. The horizontal scroll reset only follows
// a real change of mode (perf plan step 25f): it used to force a layout during the parse of every
// watch page at init.
const initTheaterAndLightsOut = (config: LayoutConfig, theaterButtons: NodeListOf<HTMLElement>): void => {
    const setTheaterMode = (enabled: boolean): void => {
        const changed = document.body.classList.contains('theater-mode') !== enabled;
        document.body.classList.toggle('theater-mode', enabled);
        if (config.theaterMemory) {
            store.set('theater', enabled ? '1' : '0');
        }
        if (changed) {
            document.documentElement.scrollLeft = 0;
            document.body.scrollLeft = 0;
        }

        document.querySelectorAll('[data-theater-toggle]').forEach((button) => {
            button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
            const icon = button.querySelector('i');

            if (icon) {
                icon.classList.toggle('fa-expand', !enabled);
                icon.classList.toggle('fa-compress', enabled);
            }
        });
    };

    window.avdToggleTheater = () => {
        setTheaterMode(!document.body.classList.contains('theater-mode'));
    };

    window.avdToggleLightsOut = () => {
        const isLightsOut = document.body.classList.toggle('avd-lights-out');
        document.querySelectorAll('#avdLightsOutBtn, .avd-lights-out-btn').forEach((b) => {
            b.classList.toggle('active', isLightsOut);
            b.setAttribute('aria-pressed', isLightsOut ? 'true' : 'false');
        });
        store.set('avd_lights_out', isLightsOut ? '1' : '0');
        if (window.showCopyToast) {
            window.showCopyToast(isLightsOut ? config.t.lightsOut : config.t.lightsRestored);
        }
    };

    theaterButtons.forEach((button) => {
        button.addEventListener('click', () => {
            window.avdToggleTheater();
        });
    });
    // The <head> stub script's delegated click listener queued theater clicks until now.
    window.__avdCore = true;
};

// Global keyboard shortcuts. show.blade.php registers its own player shortcuts first; this listener
// still runs after it, as before. That handler already runs T, L, P, A, Z, B, W, V and N on the watch
// page, so they are not run a second time here: twice cancelled every toggle (T, L, P, A, Z, V and N did
// nothing, L toasted 'Lights Restored') and moved B and W on by two steps. Other keys are unchanged.
const initShortcuts = (theaterButtons: NodeListOf<HTMLElement>): void => {
    const overlay = byId('shortcuts-overlay');

    document.addEventListener('keydown', (e) => {
        // Global Spotlight Command Palette (Ctrl+K or Cmd+K)
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            if (window.openSpotlightSearch) window.openSpotlightSearch();
            return;
        }

        const target = e.target as HTMLElement | null;
        const tag = target && target.tagName;

        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (target && target.isContentEditable)) {
            return;
        }

        const plain = !e.ctrlKey && !e.metaKey && !e.altKey;
        const player = (name: string, ...args: unknown[]): void => {
            if (plain && typeof win[name] === 'function') {
                e.preventDefault();
                call(name, ...args);
            }
        };
        // The watch page's handler is defined in the same script as avdTogglePip (show.blade.php only), and it
        // calls preventDefault() for every key it ran, except P.
        const watchKeys = typeof win.avdTogglePip === 'function';
        const watchHandled = watchKeys && e.defaultPrevented;

        switch (e.key) {
            case '/':
                e.preventDefault();
                if (window.openSpotlightSearch) window.openSpotlightSearch();
                break;
            case '?':
                if (overlay) {
                    e.preventDefault();
                    const nowActive = overlay.classList.toggle('active');
                    if (window.AvdAudio && nowActive) window.AvdAudio.playClick();
                }
                break;
            case 'Escape':
                if (overlay && overlay.classList.contains('active')) {
                    overlay.classList.remove('active');
                }
                if (document.body.classList.contains('avd-lights-out')) {
                    if (window.avdToggleLightsOut) window.avdToggleLightsOut();
                }
                if (window.closeSpotlightSearch) window.closeSpotlightSearch();
                break;
            case 'd':
            case 'D':
                if (plain) {
                    e.preventDefault();
                    if (window.openDailyAestheticModal) window.openDailyAestheticModal();
                }
                break;
            case 't':
            case 'T':
                if (!e.ctrlKey && !e.metaKey && theaterButtons.length && !watchHandled) {
                    window.avdToggleTheater();
                }
                break;
            case 'l':
            case 'L':
                if (!e.ctrlKey && !e.metaKey && !watchHandled) {
                    if (window.avdToggleLightsOut) window.avdToggleLightsOut();
                }
                break;
            case 'p':
            case 'P':
                // The mini player was toggled by the watch page's handler, which does not preventDefault() P.
                if (plain && watchKeys) e.preventDefault();
                break;
            case 'a':
            case 'A':
                if (!watchHandled) player('avdToggleAmbilight');
                break;
            case 's':
            case 'S':
                player('avdCaptureFrame');
                break;
            case '[':
                player('avdChangeSpeed', -1);
                break;
            case ']':
                player('avdChangeSpeed', 1);
                break;
            case 'z':
            case 'Z':
                if (!watchHandled) player('avdToggleSleepTimerModal');
                break;
            case 'b':
            case 'B':
                if (!watchHandled) player('avdCycleAudioBoost');
                break;
            case 'c':
            case 'C':
                player('avdAddBookmark');
                break;
            case 'w':
            case 'W':
                if (!watchHandled) player('avdCycleAspectRatio');
                break;
            case 'v':
            case 'V':
                if (!watchHandled) player('avdToggleVisualizer');
                break;
            case 'n':
            case 'N':
                // Shift+N is the watch page's Night Shift filter: no notes toggle with it any more either.
                if (!watchHandled) player('avdToggleNotes');
                break;
        }
    });
};

const initToasts = (config: LayoutConfig): void => {
    window.showCopyToast = (msg?: string) => {
        if (!config.copyToast) {
            return;
        }

        const toast = byId('copy-toast');

        if (!toast) {
            return;
        }

        if (msg) {
            const span = toast.querySelector('span');
            if (span) span.textContent = msg;
        }

        toast.classList.add('show');
        setTimeout(() => {
            toast.classList.remove('show');
        }, 2000);
    };

    // msg is markup (page scripts pass HTML or entity-escaped text); this module's own callers pass their
    // plain text through escapeHtml().
    window.avdToast = (msg: string, type?: string, iconClass?: string) => {
        type = type || 'success';
        let container = byId('avd-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'avd-toast-container';
            container.style.cssText = 'position: fixed; bottom: 24px; right: 24px; z-index: 999999; display: flex; flex-direction: column; gap: 8px; pointer-events: none;';
            document.body.appendChild(container);
        }
        const toast = document.createElement('div');
        toast.style.cssText = 'pointer-events: auto; display: flex; align-items: center; gap: 10px; padding: 10px 18px; border-radius: 9999px; background: rgba(15, 20, 32, 0.95); border: 1px solid rgba(255,255,255,0.18); box-shadow: 0 10px 30px rgba(0,0,0,0.5), 0 0 20px rgba(var(--avd-primary-rgb, 255,61,97), 0.28); color: #fff; font-size: 0.85rem; font-weight: 600; opacity: 0; transform: translateY(12px) scale(0.95); transition: opacity 0.22s ease, transform 0.22s ease; font-family: var(--avd-font-body, system-ui);';
        const icon = iconClass || (type === 'success' ? 'fa-solid fa-circle-check text-success' : (type === 'danger' ? 'fa-solid fa-circle-exclamation text-danger' : 'fa-solid fa-bell text-info'));
        toast.innerHTML = '<i class="' + icon + '"></i><span>' + msg + '</span>';
        container.appendChild(toast);
        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0) scale(1)';
        });
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-8px) scale(0.95)';
            setTimeout(() => {
                toast.remove();
            }, 250);
        }, 3000);
    };
    window.alertToast = window.avdToast;
};

// Bootstrap modal of the layout, or the plain show/hide the inline script fell back to.
const showModal = (modalEl: HTMLElement): void => {
    (Modal.getInstance(modalEl) || new Modal(modalEl)).show();
};

// Global Spotlight command palette (Ctrl+K / '/').
const initSpotlight = (): void => {
    window.openSpotlightSearch = () => {
        const modalEl = byId('avdSpotlightModal');
        if (!modalEl) return;
        showModal(modalEl);
        setTimeout(() => {
            const inp = byId<HTMLInputElement>('avdSpotlightInput');
            if (inp) {
                inp.focus();
                inp.select();
            }
        }, 150);
        playClick();
    };

    window.closeSpotlightSearch = () => {
        const modalEl = byId('avdSpotlightModal');
        if (!modalEl) return;
        const bsModal = Modal.getInstance(modalEl);
        if (bsModal) {
            bsModal.hide();
        } else {
            modalEl.classList.remove('show');
            modalEl.style.display = 'none';
        }
    };

    const spotlightInput = byId<HTMLInputElement>('avdSpotlightInput');
    if (spotlightInput) {
        spotlightInput.addEventListener('input', function () {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll<HTMLElement>('#avdSpotlightModal a').forEach((a) => {
                const text = (a.textContent || '').toLowerCase();
                a.style.display = !q || text.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }
};

const HUD_NAMES = ['copyColorHex', 'setPersonalGlow', 'setCustomAccent', 'resetCustomAccent', 'avdSurpriseMe', 'generateAestheticCard'];

let hudLoad: Promise<void> | null = null;
const loadHud = (): Promise<void> => {
    if (!hudLoad) {
        const load = import('./daily-hud').then(({installHud}) => installHud());
        hudLoad = load;
        load.catch(() => {
            if (hudLoad === load) hudLoad = null;
        });
    }

    return hudLoad;
};

// The Daily Aesthetic badge and its HUD, and the visitor's saved accent and glow.
const initDaily = (config: LayoutConfig): void => {
    const daily = config.daily;

    // HUD controls: stubs until ./daily-hud has loaded (the HUD's buttons cannot be reached earlier,
    // the modal opens through openDailyAestheticModal below).
    const names = daily ? HUD_NAMES.concat('copyToClipboard') : HUD_NAMES;
    names.forEach((name) => {
        const stub = (...args: unknown[]) => {
            loadHud().then(() => {
                const fn = win[name];
                if (typeof fn === 'function' && fn !== stub) (fn as AnyFunction)(...args);
            }, () => undefined);
        };
        win[name] = stub;
    });

    window.openDailyAestheticModal = () => {
        const modalEl = byId('dailyAestheticModal');
        if (!modalEl) return;
        // The HUD's controls load while the modal fades in.
        loadHud().catch(() => undefined);
        if (daily) {
            playChime();
            showModal(modalEl);
        } else {
            showModal(modalEl);
            playChime();
        }
    };

    // The saved accent (--avd-brand) and glow (--avd-brand-soft) were applied by the <head> theme
    // script; the HUD's picker shows the saved accent.
    const savedAccent = store.get('avd_custom_accent');
    if (savedAccent) {
        const picker = byId<HTMLInputElement>('avdCustomColorPicker');
        if (picker) picker.value = savedAccent;
        const nameEl = byId('avdCustomColorName');
        if (nameEl) nameEl.textContent = 'Custom Tone: ' + savedAccent.toUpperCase();
    }

    if (!daily) {
        return;
    }

    // Seasonal particles (rich tier, ./decor). Lower tiers hide the canvas; the HUD toggle records the
    // choice either way.
    const canvas = byId<HTMLCanvasElement>('avdSeasonalAtmosphere');
    if (canvas) {
        const enabled = store.get('avd_atmosphere_enabled') !== '0';
        const atmosphereToggle = byId<HTMLInputElement>('modalAtmosphereToggle');
        if (!enabled && atmosphereToggle) atmosphereToggle.checked = false;

        const rich = !!(window.AVD_TIER && window.AVD_TIER.at('rich'));
        if (rich && typeof canvas.getContext === 'function') {
            if (!enabled) canvas.style.display = 'none';
            // Decorative: a failed load never reloads the page (./dom/navIntent).
            const start = optionalLoad(import('./decor')).then(({startAtmosphere}) => startAtmosphere(canvas, daily, enabled));
            // Until the engine runs, the toggle records the choice and is replayed on the engine.
            const pending = (on: boolean) => {
                store.set('avd_atmosphere_enabled', on ? '1' : '0');
                start.then(() => {
                    const toggle = window.toggleSeasonalAtmosphere;
                    if (toggle && toggle !== pending) toggle(on);
                }, () => undefined);
            };
            window.toggleSeasonalAtmosphere = pending;
            start.catch(() => undefined);
        } else {
            canvas.style.display = 'none';
            window.toggleSeasonalAtmosphere = (on: boolean) => {
                store.set('avd_atmosphere_enabled', on ? '1' : '0');
            };
        }
    }

    const savedGlow = store.get('avd_personal_glow');
    if (savedGlow) {
        syncGlowGroup(savedGlow);
    }

    document.querySelectorAll<HTMLInputElement>('.avd-sound-toggle-input').forEach((toggle) => {
        toggle.checked = window.AvdAudio.isSoundOn();
    });
};

// Universal category chips scroller.
const setupChipsScroller = (wrapper: HTMLElement): void => {
    if (!wrapper || wrapper.dataset.chipsInitialized) return;
    wrapper.dataset.chipsInitialized = 'true';

    const track = wrapper.querySelector<HTMLElement>('.avd-category-tabs');
    const prevBtn = wrapper.querySelector<HTMLElement>('[data-chips-prev]');
    const nextBtn = wrapper.querySelector<HTMLElement>('[data-chips-next]');
    if (!track) return;

    // Edge state comes from two sentinels, the first and the last chip, watched by an
    // IntersectionObserver rooted at the track. Scrolling (the desktop marquee included), resizes,
    // font loads and theme switches then cost no script work, and the classes and arrow styles are
    // written only when the state actually changes.
    let atStart = true;
    let atEnd = true;
    let noScroll = true;
    let appliedState: string | null = null;
    let firstChip: Element | null = null;
    let lastChip: Element | null = null;
    let edgeObserver: IntersectionObserver | null = null;
    // The masks (.at-start fades the right edge, .at-end the left one) and the arrows (prev scrolls
    // left, next scrolls right) are physical, while the sentinels follow DOM order, which runs right
    // to left on RTL pages: map DOM edges to sides.
    const rtl = document.documentElement.dir === 'rtl';

    // A hidden arrow is also visibility:hidden, so it leaves the tab order (an invisible focus stop showed no ring);
    // if it had focus, the track (a focusable scroll region) takes it. Its opacity has no transition to cut short.
    const setButtonState = (button: HTMLElement | null, opacity: string, pointerEvents: string): void => {
        if (!button) return;
        const visibility = opacity === '0' ? 'hidden' : '';
        if (visibility && document.activeElement === button) track.focus({preventScroll: true});
        if (button.style.opacity !== opacity) button.style.opacity = opacity;
        if (button.style.pointerEvents !== pointerEvents) button.style.pointerEvents = pointerEvents;
        if (button.style.visibility !== visibility) button.style.visibility = visibility;
    };

    const applyNav = (): void => {
        noScroll = atStart && atEnd;
        const state = noScroll ? 'none' : (atStart ? 'start' : (atEnd ? 'end' : 'middle'));
        if (state === appliedState) return;
        appliedState = state;

        wrapper.classList.toggle('no-scroll', noScroll);

        if (noScroll) {
            setButtonState(prevBtn, '0', 'none');
            setButtonState(nextBtn, '0', 'none');
            wrapper.classList.toggle('at-start', false);
            wrapper.classList.toggle('at-end', false);
            return;
        }

        const leftDone = rtl ? atEnd : atStart;
        const rightDone = rtl ? atStart : atEnd;
        wrapper.classList.toggle('at-start', leftDone);
        wrapper.classList.toggle('at-end', rightDone);
        setButtonState(prevBtn, leftDone ? '0' : '1', leftDone ? 'none' : 'auto');
        setButtonState(nextBtn, rightDone ? '0' : '1', rightDone ? 'none' : 'auto');
    };

    // One-off geometry read (init, and engines without IntersectionObserver). RTL scrollLeft runs
    // from 0 at the start (right edge) down to negative values.
    const measureNav = (): void => {
        const sl = rtl ? Math.abs(track.scrollLeft) : track.scrollLeft;
        const maxScroll = Math.max(0, track.scrollWidth - track.clientWidth);
        if (maxScroll > 8) {
            atStart = sl <= 6;
            atEnd = sl >= maxScroll - 6;
        } else {
            atStart = true;
            atEnd = true;
        }
    };

    if ('IntersectionObserver' in window) {
        // The root is the track's padding box. No horizontal margin: the chips sit inside the track
        // padding, so an edge chip stays fully visible for the same 6px of scroll as the old
        // tolerance (0.997 leaves under 1px of slack for chips up to ~300px wide). The large vertical
        // margin keeps the chips' vertical intro and hover transforms from ever counting as clipping.
        // RTL only: WebKit leaves the inline-end padding out of an RTL track's scroll range, so the
        // last chip stops ~6px inside the padding edge and never reads as fully visible; a 6px side
        // margin restores the far-end state there.
        edgeObserver = new IntersectionObserver((entries) => {
            for (let i = 0; i < entries.length; i++) {
                const entry = entries[i];
                const fullyVisible = entry.isIntersecting && entry.intersectionRatio >= 0.997;
                if (entry.target === firstChip) atStart = fullyVisible;
                if (entry.target === lastChip) atEnd = fullyVisible;
            }
            applyNav();
        }, {root: track, rootMargin: rtl ? '200px 6px' : '200px 0px', threshold: [0, 0.997]});
    }

    // (Re)target the sentinels: marquee clones are appended as the last children.
    const watchEdges = (): void => {
        const observer = edgeObserver as IntersectionObserver;
        const first = track.firstElementChild;
        const last = track.lastElementChild;
        if (first === firstChip && last === lastChip) return;
        // Unobserve and observe again so each edge gets a fresh notification for its role.
        if (firstChip) observer.unobserve(firstChip);
        if (lastChip && lastChip !== firstChip) observer.unobserve(lastChip);
        firstChip = first;
        lastChip = last;
        if (!first) {
            atStart = true;
            atEnd = true;
            applyNav();
            return;
        }
        observer.observe(first);
        if (last && last !== first) observer.observe(last);
    };

    if (prevBtn) {
        prevBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const step = Math.max(220, Math.round(track.clientWidth * 0.7));
            track.scrollBy({left: -step, behavior: 'smooth'});
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const step = Math.max(220, Math.round(track.clientWidth * 0.7));
            track.scrollBy({left: step, behavior: 'smooth'});
        });
    }

    // Desktop Mouse drag-to-scroll
    let isDown = false;
    let startPageX = 0;
    let scrollStart = 0;
    let hasDragged = false;

    track.addEventListener('mousedown', (e) => {
        if (e.button !== 0) return;
        isDown = true;
        hasDragged = false;
        startPageX = e.pageX;
        scrollStart = track.scrollLeft;
        track.style.cursor = 'grabbing';
    });

    window.addEventListener('mouseup', () => {
        if (!isDown) return;
        isDown = false;
        track.style.cursor = '';
        setTimeout(() => {
            hasDragged = false;
        }, 60);
    });

    track.addEventListener('mousemove', (e) => {
        if (!isDown) return;
        e.preventDefault();
        const walk = (e.pageX - startPageX) * 1.4;
        if (Math.abs(walk) > 5) {
            hasDragged = true;
        }
        track.scrollLeft = scrollStart - walk;
    });

    track.addEventListener('click', (e) => {
        if (hasDragged) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);

    // Wheel horizontal scroll support (cached state, no layout read)
    track.addEventListener('wheel', (e) => {
        if (noScroll) return;
        if (Math.abs(e.deltaY) > Math.abs(e.deltaX) && !e.shiftKey) {
            track.scrollLeft += e.deltaY;
            e.preventDefault();
        }
    }, {passive: false});

    // Scroll active tag into view if needed, then seed the edge state from the same layout pass so
    // the first paint is already right; the observer keeps it current.
    const activeTag = track.querySelector<HTMLElement>('.avd-tag.active');
    const trackWidth = track.clientWidth;
    if (activeTag && activeTag.offsetLeft > trackWidth / 2) {
        track.scrollLeft = activeTag.offsetLeft - (trackWidth / 2) + (activeTag.clientWidth / 2);
    }
    measureNav();
    applyNav();

    if (edgeObserver) {
        watchEdges();
        if ('MutationObserver' in window) {
            new MutationObserver(watchEdges).observe(track, {childList: true});
        }
    } else {
        let navFrame = 0;
        const scheduleNavUpdate = (): void => {
            if (navFrame) return;
            navFrame = window.requestAnimationFrame(() => {
                navFrame = 0;
                measureNav();
                applyNav();
            });
        };
        track.addEventListener('scroll', scheduleNavUpdate, {passive: true});
        window.addEventListener('resize', scheduleNavUpdate, {passive: true});
    }
};

// Home hero carousels ([data-avd-carousel], resources/views/pages/partials/hero/billboard.blade.php): full-width
// slides in a scroll-snap track ([data-avd-carousel-track] > [data-avd-carousel-slide]), dot buttons
// ([data-avd-carousel-dot]), arrow keys and an optional autoplay (data-avd-carousel-autoplay = seconds,
// 0 = off). The active slide comes from an IntersectionObserver rooted at the track, so swiping and scrolling run no
// script. Autoplay waits while any pause reason holds: pointer over it, keyboard focus inside, a touch, off screen,
// a hidden page, a page scroll in progress ('avd:scroll-activity') or reduced motion.
const setupCarousel = (root: HTMLElement): void => {
    const track = root.querySelector<HTMLElement>('[data-avd-carousel-track]');
    const slides = Array.from(root.querySelectorAll<HTMLElement>('[data-avd-carousel-slide]'));
    const dots = Array.from(root.querySelectorAll<HTMLElement>('[data-avd-carousel-dot]'));
    if (!track || slides.length < 2 || root.dataset.avdCarouselReady) return;
    root.dataset.avdCarouselReady = '1';

    const rtl = document.documentElement.dir === 'rtl';
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const autoplayMs = Math.max(0, Number.parseInt(root.dataset.avdCarouselAutoplay || '0', 10) || 0) * 1000;
    const paused = new Set<string>();
    let current = 0;
    let timer = 0;

    // The dot button carries aria-current; its inner <i> is the visible dot (.avd-dots i.active).
    const mark = (index: number): void => {
        current = index;
        dots.forEach((dot, i) => {
            dot.querySelector('i')?.classList.toggle('active', i === index);
            if (i === index) dot.setAttribute('aria-current', 'true');
            else dot.removeAttribute('aria-current');
        });
    };

    const go = (index: number): void => {
        const next = (index + slides.length) % slides.length;
        track.scrollTo({left: next * track.clientWidth * (rtl ? -1 : 1), behavior: reducedMotion.matches ? 'auto' : 'smooth'});
        mark(next);
    };

    const sync = (): void => {
        window.clearTimeout(timer);
        timer = 0;
        if (autoplayMs > 0 && paused.size === 0) {
            timer = window.setTimeout(() => {
                timer = 0;
                go(current + 1);
                sync();
            }, autoplayMs);
        }
    };

    const setPaused = (reason: string, on: boolean): void => {
        if (paused.has(reason) === on) return;
        if (on) paused.add(reason);
        else paused.delete(reason);
        sync();
    };

    if ('IntersectionObserver' in window) {
        const slideObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting && entry.intersectionRatio >= 0.6) {
                    mark(slides.indexOf(entry.target as HTMLElement));
                }
            });
        }, {root: track, threshold: [0.6]});
        slides.forEach((slide) => slideObserver.observe(slide));

        paused.add('offscreen');
        new IntersectionObserver((entries) => {
            setPaused('offscreen', !entries[entries.length - 1].isIntersecting);
        }).observe(root);
    }

    dots.forEach((dot) => dot.addEventListener('click', () => {
        go(Number.parseInt(dot.dataset.avdCarouselDot || '0', 10) || 0);
        sync();
    }));
    root.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        go(current + ((event.key === 'ArrowRight') !== rtl ? 1 : -1));
    });
    root.addEventListener('pointerenter', (event) => {
        if (event.pointerType === 'mouse') setPaused('hover', true);
    }, {passive: true});
    root.addEventListener('pointerleave', () => setPaused('hover', false), {passive: true});
    root.addEventListener('focusin', () => setPaused('focus', true));
    root.addEventListener('focusout', (event) => {
        if (!root.contains(event.relatedTarget as Node | null)) setPaused('focus', false);
    });
    track.addEventListener('touchstart', () => setPaused('touch', true), {passive: true});
    track.addEventListener('touchend', () => setPaused('touch', false), {passive: true});
    track.addEventListener('touchcancel', () => setPaused('touch', false), {passive: true});
    document.addEventListener('visibilitychange', () => setPaused('hidden', document.hidden));
    document.addEventListener('avd:scroll-activity', (event) => {
        const detail = (event as CustomEvent<{active?: boolean}>).detail;
        setPaused('scroll', !!(detail && detail.active === true));
    });
    const onMotionChange = (): void => setPaused('reduced-motion', reducedMotion.matches);
    if (reducedMotion.addEventListener) reducedMotion.addEventListener('change', onMotionChange);

    if (document.hidden) paused.add('hidden');
    if (reducedMotion.matches) paused.add('reduced-motion');
    sync();
};

// Capability tier (<head> tier script): the border-beam markup needs balanced. The card tilt,
// spotlight, glare and cursor halo live in resources/js/motion/pointerFx.ts, the click ripple in the
// layout's inline engine.
const initBorderBeamGlow = (): void => {
    if (document.documentElement.getAttribute('data-avd-border-beam') === 'off') return;
    if (!(window.AVD_TIER && window.AVD_TIER.at('balanced'))) return;
    document.querySelectorAll('.has-border-beam, .avd-card-featured, .studio-border-beam-card, .avd-featured-card').forEach((card) => {
        const marked = card as Element & {_hasBorderBeam?: boolean};
        if (marked._hasBorderBeam) return;
        marked._hasBorderBeam = true;
        if (!card.querySelector('.avd-border-beam')) {
            const beam = document.createElement('div');
            beam.className = 'avd-border-beam';
            card.appendChild(beam);
        }
    });
};

// Quick share. Cards call avdQuickShare(url, title) with their video. The watch page (show.blade.php)
// defines its own avdQuickShare() before this module runs, for its dock button, which calls it without
// arguments: it copies the page's URL. That function stays in charge of calls without a URL, so the dock
// shares the watch page (replacing it shared 'undefined'); calls with a URL share that URL, as before.
const initShare = (): void => {
    const own = window.avdQuickShare as (((...args: unknown[]) => unknown) & {avdQueued?: boolean}) | undefined;
    const pageShare = typeof own === 'function' && !own.avdQueued ? own : null;

    const share = (url: string, title?: string): void => {
        if (navigator.share) {
            navigator.share({title, url} as ShareData).catch(() => undefined);
        } else if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(() => {
                window.avdToast('Video link copied to clipboard!', 'success', 'fa-solid fa-link text-info');
            }).catch(() => {
                window.avdToast('Share URL: ' + escapeHtml(url), 'info', 'fa-solid fa-link text-info');
            });
        } else {
            window.avdToast('Share URL: ' + escapeHtml(url), 'info', 'fa-solid fa-link text-info');
        }
    };

    window.avdQuickShare = (url?: string, title?: string) => {
        if (url) {
            share(url, title);
        } else if (pageShare) {
            pageShare();
        } else {
            share(window.location.href, title || document.title);
        }
    };
};

// Watch later: the auth prompt, the quick-save toggle and the saved-state badges.
const initWatchLater = (config: LayoutConfig): void => {
    const t = config.t;
    window.AVD_USER = config.user;

    window.avdShowAuthPrompt = (options) => {
        options = options || {};
        const title = options.title || t.wlPromptTitle;
        const msg = options.message || t.wlPromptMessage;
        const icon = options.icon || 'fa-regular fa-clock';

        const modalEl = byId('avdAuthPromptModal');
        if (modalEl) {
            const titleEl = byId('avdAuthPromptTitle');
            const msgEl = byId('avdAuthPromptMessage');
            const iconEl = byId('avdAuthPromptIcon');
            if (titleEl) titleEl.textContent = title;
            if (msgEl) msgEl.textContent = msg;
            if (iconEl) iconEl.className = icon + ' fs-5';
            Modal.getOrCreateInstance(modalEl).show();
        } else {
            window.avdToast(escapeHtml(msg), 'info', 'fa-solid fa-lock text-warning');
            setTimeout(() => {
                window.location.href = window.AVD_USER?.loginUrl || '/login';
            }, 1200);
        }
    };

    // Watch-later badge state. Every property is compared first and written only when it differs,
    // so a pass over unchanged buttons touches neither attributes nor styles. Quick-save buttons carry
    // data-watch-later-id (cards, cinema billboard); the onclick pattern stays as a fallback for
    // markup without it (cached HTML, custom templates). The layout repeats these rules in its inline
    // first pass (after #avd-i18n): change both together.
    const watchLaterButtonSelector = 'button[data-watch-later-id], button[onclick*="avdQuickSave"]';
    const watchLaterIdPattern = /avdQuickSave\(['"]([^'"]+)['"]\)/;
    const watchLaterSavedShadow = '0 0 10px rgba(34, 197, 94, 0.4)';
    // The browser's own serialization of that shadow, for the comparison.
    const watchLaterSavedShadowValue = (() => {
        const probe = document.createElement('span');
        probe.style.boxShadow = watchLaterSavedShadow;
        return probe.style.boxShadow;
    })();

    const watchLaterButtonId = (btn: Element): string => {
        const id = btn.getAttribute('data-watch-later-id');
        if (id) return id;
        const match = (btn.getAttribute('onclick') || '').match(watchLaterIdPattern);
        return match && match[1] ? match[1] : '';
    };

    const syncWatchLaterButton = (btn: HTMLElement, isAuth: boolean, saved: number[]): void => {
        const id = watchLaterButtonId(btn);
        if (!id) return;
        const isSaved = !!isAuth && saved.indexOf(Number(id)) !== -1;
        if (btn.classList.contains('active') !== isSaved || btn.classList.contains('border-success') !== isSaved) {
            btn.classList.toggle('active', isSaved);
            btn.classList.toggle('border-success', isSaved);
        }
        if (btn.style.boxShadow !== (isSaved ? watchLaterSavedShadowValue : '')) {
            btn.style.boxShadow = isSaved ? watchLaterSavedShadow : '';
        }
        const title = isSaved ? t.wlSavedTitle : t.wlTitle;
        if (btn.title !== title) {
            btn.title = title;
        }
        const icon = btn.querySelector('i');
        const iconClass = isSaved ? 'fa-solid fa-check text-success' : 'fa-regular fa-clock text-white';
        if (icon && icon.className !== iconClass) {
            icon.className = iconClass;
        }
    };

    const syncWatchLaterButtons = (scope: Element | Document): void => {
        const isAuth = !!(window.AVD_USER && window.AVD_USER.isAuthenticated);
        const saved = isAuth ? (window.AVD_USER.watchLaterIds || []) : [];
        if (scope !== document && (scope as Element).matches && (scope as Element).matches(watchLaterButtonSelector)) {
            syncWatchLaterButton(scope as HTMLElement, isAuth, saved);
        }
        const btns = scope.querySelectorAll<HTMLElement>(watchLaterButtonSelector);
        for (let i = 0; i < btns.length; i++) {
            syncWatchLaterButton(btns[i], isAuth, saved);
        }
    };

    // Whole document by default; an element limits the pass to that subtree.
    window.updateWatchLaterCardBadges = (root) => {
        syncWatchLaterButtons(root && (root as Node).nodeType === 1 ? root as Element : document);
    };

    window.avdQuickSave = (videoId) => {
        if (!window.AVD_USER || !window.AVD_USER.isAuthenticated) {
            window.avdShowAuthPrompt({
                title: t.wlPromptTitle,
                message: t.wlQuickSaveMessage,
                icon: 'fa-regular fa-clock',
            });
            return;
        }

        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!token) return;

        const numId = Number(videoId);
        const currentSaved = window.AVD_USER.watchLaterIds || [];

        fetch('/user/playlists/watch-later/' + videoId, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
        })
            .then((res) => {
                if (!res.ok) {
                    throw new Error('Network error');
                }
                return res.json();
            })
            .then((data) => {
                if (data.status === 'added') {
                    if (currentSaved.indexOf(numId) === -1) {
                        currentSaved.push(numId);
                    }
                    window.AVD_USER.watchLaterIds = currentSaved;
                    window.updateWatchLaterCardBadges();
                    // The server's message is plain text too (a JSON __() value).
                    window.avdToast(escapeHtml(data.message || t.wlSaved), 'success', 'fa-solid fa-bookmark text-success');
                } else {
                    const idx = currentSaved.indexOf(numId);
                    if (idx !== -1) {
                        currentSaved.splice(idx, 1);
                    }
                    window.AVD_USER.watchLaterIds = currentSaved;
                    window.updateWatchLaterCardBadges();
                    window.avdToast(escapeHtml(data.message || t.wlRemoved), 'info', 'fa-regular fa-bookmark text-secondary');
                }
            })
            .catch(() => {
                window.avdToast(escapeHtml(t.wlFailed), 'danger', 'fa-solid fa-triangle-exclamation text-danger');
            });
    };

    // Newly loaded cards (feeds, widgets, related lists) get their watch-later state. Only
    // #main-container counts; the effect engines' own nodes (ripples, bursts, the card glare,
    // spotlight and hotspot layers) are ignored, and the added roots are queued and processed once per
    // burst when the browser is idle. Public pages: the roots come from the shared added-nodes bus
    // (app.ts, resources/js/dom/addedRoots.ts) as the document event 'avd:added-roots', already pruned
    // to their topmost nodes. Admin pages keep their own observer, as before; so does a public page
    // whose bus did not start by DOMContentLoaded.
    const ignoredAddedNodes = '.avd-click-ripple, .avd-ripple-wave, .avd-anime-ripple, .avd-click-burst, .avd-card-glare, .avd-card-spotlight, .avd-card-hotspot';
    const addedRootsScope = byId('main-container') || document.body;
    let addedRootsFromBus = !document.body.classList.contains('avd-admin');
    let pendingAddedRoots: Element[] = [];
    let addedRootsFlushPending = false;

    const flushAddedRoots = (): void => {
        addedRootsFlushPending = false;
        const roots = pendingAddedRoots.filter((root) => root.isConnected);
        pendingAddedRoots = [];
        for (let j = 0; j < roots.length; j++) {
            syncWatchLaterButtons(roots[j]);
        }
    };

    const queueAddedRoot = (node: Node): void => {
        if (node.nodeType === 1 && !(node as Element).matches(ignoredAddedNodes)) {
            pendingAddedRoots.push(node as Element);
        }
    };

    const scheduleAddedRootsFlush = (): void => {
        if (pendingAddedRoots.length && !addedRootsFlushPending) {
            addedRootsFlushPending = true;
            if (window.requestIdleCallback) {
                window.requestIdleCallback(flushAddedRoots, {timeout: 300});
            } else {
                window.setTimeout(flushAddedRoots, 120);
            }
        }
    };

    const observeAddedRoots = (): void => {
        addedRootsFromBus = false;
        new MutationObserver((mutations) => {
            for (let i = 0; i < mutations.length; i++) {
                const added = mutations[i].addedNodes;
                for (let j = 0; j < added.length; j++) {
                    queueAddedRoot(added[j]);
                }
            }
            scheduleAddedRootsFlush();
        }).observe(addedRootsScope, {childList: true, subtree: true});
    };

    if (addedRootsFromBus) {
        document.addEventListener('avd:added-roots', (event) => {
            const detail = (event as CustomEvent<{roots?: Element[]}>).detail;
            const roots = addedRootsFromBus && detail && detail.roots;
            if (!roots) return;
            for (let i = 0; i < roots.length; i++) {
                if (addedRootsScope.contains(roots[i])) {
                    queueAddedRoot(roots[i]);
                }
            }
            scheduleAddedRootsFlush();
        });
        // app.ts starts the bus after this module has run, before DOMContentLoaded.
        atDomContentLoaded(() => {
            if (!window.__avdAddedRootsBus) observeAddedRoots();
        });
    } else {
        observeAddedRoots();
    }
    // The layout's inline script after #avd-i18n ran this first pass at the end of the parse, with the same
    // rules as syncWatchLaterButton (keep the two in step); repeated here it only confirms that state.
    window.updateWatchLaterCardBadges();
};

// Calls the inline stubs queued before this module ran, in order; later calls of a stub (a reference
// taken early) are forwarded at once.
const drainQueue = (): void => {
    const queued = Array.isArray(window.__avdQ) ? window.__avdQ : [];
    const forward = (entry: Queued): number => {
        const fn = win[entry[0]];

        if (typeof fn === 'function' && !(fn as {avdQueued?: boolean}).avdQueued) {
            safely(() => {
                (fn as AnyFunction)(...entry[1]);
            });
        }

        return 0;
    };

    window.__avdQ = {push: forward};
    queued.forEach(forward);
};

let started = false;

export const startLayoutCore = (): void => {
    const config = layoutConfig();

    if (started || !config || !document.body) {
        return;
    }

    started = true;
    const theaterButtons = document.querySelectorAll<HTMLElement>('[data-theater-toggle]');

    safely(initSidebarTop);
    safely(initBackToTop);
    safely(initEnhancedSelectFocus);
    safely(() => initTheaterAndLightsOut(config, theaterButtons));
    safely(() => initShortcuts(theaterButtons));
    safely(() => initToasts(config));
    safely(installAudio);
    safely(initSpotlight);
    safely(() => initDaily(config));
    safely(() => atDomContentLoaded(() => {
        safely(() => document.querySelectorAll<HTMLElement>('[data-avd-chips-scroller]').forEach(setupChipsScroller));
        safely(() => document.querySelectorAll<HTMLElement>('[data-avd-carousel]').forEach(setupCarousel));
        safely(initOverlayHeader);
        safely(initBorderBeamGlow);
    }));
    safely(initShare);
    safely(() => initWatchLater(config));
    drainQueue();
};

startLayoutCore();
