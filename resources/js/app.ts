// Build: AVDBClip Lazyload & Feed Performance v2.4
import '../sass/app.scss';
import * as bootstrap from 'bootstrap'
import Alpine from 'alpinejs'
// The layout's page features (perf plan step 25), first: they used to be inline scripts that ran
// before any module code. Only pages of resources/views/components/layout.blade.php (public and
// admin) run them; the Upload18 embed shell and the maintenance page have no #avd-i18n.
import './layout/core';
import './lang/config';
import './components/index'
import {addedRootsBusEnabled, onAddedRoots, startAddedRoots} from './dom/addedRoots';
import {optionalLoad, requestAssetReload, startNavIntent} from './dom/navIntent';
import {initScrollChrome} from './motion/scrollChrome';
import {initScrollMotionScheduler} from './motion/scrollMotionScheduler';
import {allows, startTierProbe, tier} from './motion/tier';

type TomInput = HTMLInputElement | HTMLSelectElement;
type TomRenderData = {
    text?: string;
    input?: string;
};
type TomSelectConstructor = typeof import('tom-select').default;

window.bootstrap = bootstrap;
window.Alpine = Alpine

const isMobileViewport = () => window.matchMedia('(max-width: 767.98px)').matches || window.matchMedia('(pointer: coarse)').matches;
const isAdminPage = () => document.body?.classList.contains('avd-admin') === true;
// The Upload18 embed player pages (layouts/embed.blade.php, videos/embed/public.blade.php) also
// load this entry on body.avd-embed-shell. New initializers must stay out of them.
const isEmbedPage = () => document.body?.classList.contains('avd-embed-shell') === true;

if (document.getElementById('crop')) {
    Promise.all([
        import('cropperjs'),
        import('cropperjs/dist/cropper.min.css'),
    ]).then(([module]) => {
        window.Cropper = module.default;
    });
}

if (document.getElementById('categories_list')) {
    import('sortablejs').then((module) => {
        window.Sortable = module.default;
    });
}

let tomSelectLoader: Promise<TomSelectConstructor> | null = null;
let tomSelectWarmOnly = false;

// warm: the warm-up of initSelectsOnApproach. A load only warm-ups asked for is optional
// (./dom/navIntent): when it fails, typically cancelled because the visitor is leaving, the page is
// never reloaded. Once a select needs the control (approach, focus, added select), the same load is
// required again, so a stale chunk still recovers. Each import is marked on its own, as Promise.all
// only passes on the first rejection. A failed load is not memoized: the next request tries again.
const loadTomSelect = (warm = false): Promise<TomSelectConstructor> => {
    if (!tomSelectLoader) {
        tomSelectWarmOnly = warm;

        const stillOptional = () => tomSelectWarmOnly;
        const loader = Promise.all([
            optionalLoad(import('tom-select'), stillOptional),
            optionalLoad(import('tom-select/dist/css/tom-select.bootstrap5.css'), stillOptional),
        ]).then(([module]) => module.default);

        tomSelectLoader = loader;
        loader.catch(() => {
            if (tomSelectLoader === loader) {
                tomSelectLoader = null;
            }
        });
    } else if (!warm) {
        tomSelectWarmOnly = false;
    }

    return tomSelectLoader;
};

const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY;

if (pusherKey && pusherKey !== 'app-key' && pusherKey !== '') {
    Promise.all([
        import('laravel-echo'),
        import('pusher-js'),
    ]).then(([EchoModule, PusherModule]) => {
        const Echo = EchoModule.default;
        const Pusher = PusherModule.default;

        window.Pusher = Pusher;
        window.Echo = new Echo({
            broadcaster: 'pusher',
            cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
            key: pusherKey,
            wsHost: import.meta.env.VITE_PUSHER_WEBSOCKETS_HOST,
            wsPort: import.meta.env.VITE_PUSHER_PORT,
            wssPort: import.meta.env.VITE_PUSHER_PORT,
            forceTLS: false,
            encrypted: true,
            disableStats: true,
            enabledTransports: ['ws', 'wss'],
        });
    });
}

// Stale build after a deploy: reload once for fresh assets, through ./dom/navIntent. It skips the
// reload while the visitor is leaving (WebKit cancels the chunk loads of a page being left, and an
// immediate reload replaced the navigation), and decides after a short re-check. Every page type.
startNavIntent();
window.addEventListener('vite:preloadError', (event) => {
    requestAssetReload(
        'avd_vite_preload_reload',
        '[Vite] Preload error detected, reloading page for fresh assets...',
        (event as Event & {payload?: unknown}).payload,
    );
});

const initResponsiveNavigationAccessibility = (): void => {
    const isVisible = (element: HTMLElement): boolean => {
        const style = window.getComputedStyle(element);

        return element.getClientRects().length > 0 && style.display !== 'none' && style.visibility !== 'hidden';
    };

    const focusElement = (element: HTMLElement | null): void => {
        if (!element || !element.isConnected) {
            return;
        }

        try {
            element.focus({preventScroll: true});
        } catch {
            element.focus();
        }
    };

    const mobileSearchToggle = document.getElementById('mobile-search-toggle') as HTMLButtonElement | null;
    const mobileSearchPanel = document.getElementById('mobile-search-panel');
    const mobileSearchClose = document.getElementById('mobile-search-close') as HTMLButtonElement | null;

    if (mobileSearchToggle && mobileSearchPanel && mobileSearchClose) {
        let searchObserver: MutationObserver | null = null;
        let searchFocusTimeout: number | null = null;

        const restoreSearchFocus = (): void => {
            // Alpine reveals the surrounding header after its queued x-show
            // update. Focusing a button while that ancestor is hidden is ignored.
            let observer: MutationObserver | null = null;
            let timeout: number | null = null;
            const stop = (): void => {
                observer?.disconnect();
                if (timeout !== null) window.clearTimeout(timeout);
            };
            const restore = (): boolean => {
                if (!isVisible(mobileSearchToggle)) return false;
                focusElement(mobileSearchToggle);
                stop();
                return true;
            };
            if (restore()) return;
            observer = new MutationObserver(restore);
            observer.observe(mobileSearchToggle.closest('.avd-header') ?? document.body, {
                attributes: true, subtree: true, attributeFilter: ['style', 'class'],
            });
            timeout = window.setTimeout(stop, 2000);
            window.requestAnimationFrame(restore);
        };

        const stopSearchFocus = (): void => {
            searchObserver?.disconnect();
            searchObserver = null;

            if (searchFocusTimeout !== null) {
                window.clearTimeout(searchFocusTimeout);
                searchFocusTimeout = null;
            }
        };

        const tryFocusSearchInput = (): boolean => {
            if (!isVisible(mobileSearchPanel)) {
                stopSearchFocus();
                return false;
            }

            const input = mobileSearchPanel.querySelector<HTMLInputElement>('input[type="search"]');
            if (!input || !isVisible(input)) {
                return false;
            }

            focusElement(input);
            stopSearchFocus();
            return true;
        };

        const focusSearchInputWhenReady = (): void => {
            stopSearchFocus();

            if (tryFocusSearchInput()) {
                return;
            }

            searchObserver = new MutationObserver(() => tryFocusSearchInput());
            searchObserver.observe(mobileSearchPanel, {childList: true, subtree: true});
            searchFocusTimeout = window.setTimeout(stopSearchFocus, 2000);
            window.requestAnimationFrame(() => tryFocusSearchInput());
        };

        mobileSearchToggle.addEventListener('click', focusSearchInputWhenReady);
        mobileSearchClose.addEventListener('click', () => {
            stopSearchFocus();
            restoreSearchFocus();
        });

        document.addEventListener('keydown', (event: KeyboardEvent) => {
            if (event.key !== 'Escape' || !isVisible(mobileSearchPanel)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            mobileSearchClose.click();
        }, true);
    }

    const sidebar = document.getElementById('responsive-sidebar');
    const sidebarToggles = Array.from(document.querySelectorAll<HTMLElement>(
        '[data-bs-toggle="offcanvas"][data-bs-target="#responsive-sidebar"]',
    ));

    if (!sidebar || sidebarToggles.length === 0) {
        return;
    }

    const setSidebarExpanded = (expanded: boolean): void => {
        sidebarToggles.forEach(toggle => toggle.setAttribute('aria-expanded', String(expanded)));
    };

    const visibleSidebarToggle = (): HTMLElement | null =>
        sidebarToggles.find(toggle => isVisible(toggle)) ?? null;

    sidebar.addEventListener('show.bs.offcanvas', () => setSidebarExpanded(true));
    sidebar.addEventListener('shown.bs.offcanvas', () => {
        setSidebarExpanded(true);

        if (!visibleSidebarToggle()) {
            return;
        }

        const firstFocusable = Array.from(sidebar.querySelectorAll<HTMLElement>(
            '.offcanvas-header [data-bs-dismiss="offcanvas"], .offcanvas-header [autofocus], [autofocus]',
        )).find(element => isVisible(element));

        focusElement(firstFocusable ?? sidebar);
    });
    sidebar.addEventListener('hide.bs.offcanvas', () => setSidebarExpanded(false));
    sidebar.addEventListener('hidden.bs.offcanvas', () => {
        setSidebarExpanded(false);
        focusElement(visibleSidebarToggle());
    });
};

const reducedMotionPreferred = (): boolean => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// optional: the deferred balanced import, whose failure never reloads the page (./dom/navIntent). A
// failed import is handled here (the reload decision is the 'vite:preloadError' listener's); errors
// thrown by initAnimeEnhancements itself still surface.
const loadMotion = (optional = false): void => {
    const chunk = import('./motion/animeEnhancements');

    (optional ? optionalLoad(chunk) : chunk).then(({initAnimeEnhancements}) => initAnimeEnhancements(), () => undefined);
};

// Motion chunk by capability tier (html[data-avd-tier], decided in the <head>):
// - rich: imported at once, as before, so the page intro can still play while the page first paints;
// - balanced: imported at the first idle moment, after the feed boot (short opacity fades only);
// - lite, motion off, reduced motion: never downloaded. Motion off and reduced motion only ever
//   installed the scroll gate from it.
// The header elevation and the scroll progress bar (./motion/scrollChrome) run outside the chunk, on
// every tier, under the same switches as before (anime effects on, motion not off, no reduced motion).
const startMotion = (): void => {
    if (window.AVD_SETTINGS?.animeEffects === false) {
        return;
    }

    const motionOff = document.documentElement.dataset.avdMotion === 'off' || reducedMotionPreferred();

    if (motionOff) {
        return;
    }

    initScrollChrome();

    if (tier() === 'lite') {
        return;
    }

    if (tier() === 'rich') {
        loadMotion();
        return;
    }

    const start = () => {
        // The tier may have dropped to lite while waiting.
        if (tier() !== 'lite') loadMotion(true);
    };

    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(start, {timeout: 1500});
    } else {
        window.setTimeout(start, 300);
    }
};

// Pointer effects (./motion/pointerFx: card tilt and glare, spotlight, magnet, click burst, cursor halo) for
// the rich tier with a hovering fine pointer, imported at the first idle moment. Like the layout engines it
// replaces, it does not depend on AVD_SETTINGS.animeEffects; the effects that came from the motion chunk
// check that switch themselves. The click ripple stays in the layout (every page type). haloOnly: admin
// pages (below).
const startPointerFx = (options?: {haloOnly?: boolean}): void => {
    if (!allows('pointerFx')) {
        return;
    }

    const start = () => {
        // The tier may have dropped while waiting.
        if (allows('pointerFx')) {
            optionalLoad(import('./motion/pointerFx')).then(({initPointerFx}) => initPointerFx(options), () => undefined);
        }
    };

    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(start, {timeout: 1500});
    } else {
        window.setTimeout(start, 300);
    }
};

Alpine.start()
// Decorative-loop gate (off-screen and touch-scroll pause) for every public page, independent of
// AVD_SETTINGS.animeEffects. It is idempotent, so the call inside initAnimeEnhancements is a no-op.
// The tier probe (rAF sample at the first idle moment and on the first scroll) may lower the tier.
// The shared added-nodes bus (./dom/addedRoots) also feeds the layout's inline card pass through
// the 'avd:added-roots' event, so it runs even when no module subscriber is left.
if (!isAdminPage() && !isEmbedPage()) {
    initScrollMotionScheduler();
    startTierProbe();
    startAddedRoots();
}
initResponsiveNavigationAccessibility();
if (isEmbedPage()) {
    // The Upload18 embed shell (hands-off) keeps its original path: it has no tier system.
    if (window.AVD_SETTINGS?.animeEffects !== false) {
        loadMotion();
    }
} else if (!isAdminPage()) {
    startMotion();
    startPointerFx();
} else if (document.documentElement.dataset.avdCursorTrail === 'on') {
    // Admin pages (always the rich tier): the cursor glow trail halo alone, which the layout's former inline
    // follower drew there too. The card, magnet and burst effects never ran on admin pages.
    startPointerFx({haloOnly: true});
}

const tooltips = [...document.querySelectorAll('[data-bs-toggle="tooltip"]')]
tooltips.forEach(element => new bootstrap.Tooltip(element))

const popovers = [...document.querySelectorAll('[data-bs-toggle="popover"]')]
popovers.forEach(element => {
    let options : {content? : string, html? : boolean} = {};

    if (element.hasAttribute('data-bs-content-id')) {
        options.content = document.getElementById(element.getAttribute('data-bs-content-id')!)!.innerHTML
        options.html = true;
    }

    return new bootstrap.Popover(element, options)
});

const uploadModal = document.getElementById('video_create');
if (uploadModal) {
    let openingUploadModal = false;
    const resetOpeningState = () => {
        openingUploadModal = false;
        document.body.classList.remove('avd-upload-modal-opening');
    };
    const normalizeBackdrops = () => {
        const openModals = document.querySelectorAll('.modal.show');
        const backdrops = [...document.querySelectorAll('.modal-backdrop')];

        if (openModals.length === 0) {
            backdrops.forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
            return;
        }

        if (uploadModal.classList.contains('show') && backdrops.length > 1) {
            backdrops.slice(0, -1).forEach(backdrop => backdrop.remove());
        }
    };

    document.querySelectorAll<HTMLElement>('[data-bs-target="#video_create"]').forEach(trigger => {
        trigger.addEventListener('click', event => {
            if (openingUploadModal || uploadModal.classList.contains('show')) {
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }

            openingUploadModal = true;
            document.body.classList.add('avd-upload-modal-opening');
            window.setTimeout(resetOpeningState, 700);
        }, true);
    });

    uploadModal.addEventListener('shown.bs.modal', () => {
        resetOpeningState();
        normalizeBackdrops();
    });

    uploadModal.addEventListener('hidden.bs.modal', () => {
        resetOpeningState();
        normalizeBackdrops();
    });
}

const tomSelectRender = {
    option: function(data: TomRenderData, escape: (value: string) => string) {
        return '<div class="avd-select-option-label">' + escape(data.text ?? '') + '</div>';
    },
    item: function(data: TomRenderData, escape: (value: string) => string ) {
        return '<div class="avd-select-item-label">' + escape(data.text ?? '') + '</div>';
    },
    no_results:function(data: TomRenderData,escape: (value: string) => string){
        return '<div class="no-results">No results found for "'+escape(data.input ?? '')+'"</div>';
    },
};

const hasTomSelect = (element: Element): boolean => Boolean((element as TomInput & { tomselect?: unknown }).tomselect);

const singleSelectSelector = [
    'select.form-select',
    'select.form-control',
    'select.avd-footer-select',
].map(selector => `${selector}:not(.select-multiple):not([multiple]):not([data-native-select])`).join(',');

const selectElements = (root: ParentNode, selector: string): Element[] => [
    ...(root instanceof Element && root.matches(selector) ? [root] : []),
    ...Array.from(root.querySelectorAll(selector)),
];

const initMultipleSelect = async (element: Element) => {
    if (hasTomSelect(element)) {
        return;
    }

    const TomSelect = await loadTomSelect();

    new TomSelect(element as TomInput, {
        dropdownParent: 'body',
        render: {
            ...tomSelectRender,
        },
        plugins: {
            remove_button:{
                title:'Remove this item',
            }
        }
    });
};

const initSingleSelect = async (element: Element) => {
    if (hasTomSelect(element)) {
        return;
    }

    const TomSelect = await loadTomSelect();

    new TomSelect(element as TomInput, {
        allowEmptyOption: true,
        closeAfterSelect: true,
        create: false,
        dropdownParent: 'body',
        maxOptions: 500,
        render: {
            ...tomSelectRender,
            item: function(data: TomRenderData, escape: (value: string) => string ) {
                return '<div class="avd-select-item-label">' + escape(data.text ?? '') + '</div>';
            },
        },
    });
};

const initSelects = (root: ParentNode = document) => {
    selectElements(root, '.select-multiple').forEach(initMultipleSelect);
    selectElements(root, singleSelectSelector).forEach(initSingleSelect);
};

// Perf plan step 22.5 (public pages): TomSelect, its chunk and the stylesheet it injects are built at
// boot only for the selects that are within 300 px of the viewport, or inside a hidden container (a
// closed modal, an inactive tab: no box), which must be ready the moment it opens. The others, such as
// the footer language select, are enhanced when they come within 300 px of the viewport, or on focus
// (keyboard), whichever comes first; a focused select hands its focus to the new control. Selects
// added later are enhanced at once, as before.
const SELECT_WARM_MARGIN_PX = 1200;

const initSelectsOnApproach = (): void => {
    const candidates = new Map<Element, (element: Element) => Promise<void>>();

    selectElements(document, '.select-multiple').forEach((element) => candidates.set(element, initMultipleSelect));
    selectElements(document, singleSelectSelector).forEach((element) => candidates.set(element, initSingleSelect));

    if (candidates.size === 0) {
        return;
    }

    if (typeof IntersectionObserver !== 'function') {
        candidates.forEach((init, element) => init(element));
        return;
    }

    let observer: IntersectionObserver | null = null;
    let warmObserver: IntersectionObserver | null = null;

    const onFocusIn = (event: FocusEvent): void => {
        if (event.target instanceof Element && candidates.has(event.target)) {
            enhance(event.target, true);
        }
    };

    const enhance = (element: Element, focused: boolean): void => {
        const init = candidates.get(element);

        if (!init) {
            return;
        }

        candidates.delete(element);
        observer?.unobserve(element);

        if (candidates.size === 0) {
            observer?.disconnect();
            warmObserver?.disconnect();
            document.removeEventListener('focusin', onFocusIn, true);
        }

        init(element).then(() => {
            const control = (element as TomInput & {tomselect?: {focus: () => void}}).tomselect;

            if (focused && control && document.activeElement === element) {
                control.focus();
            }
        });
    };

    observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            const box = entry.boundingClientRect;

            if (entry.isIntersecting || (box.width === 0 && box.height === 0)) {
                enhance(entry.target, false);
            }
        });
    }, {rootMargin: '300px 0px'});

    document.addEventListener('focusin', onFocusIn, true);
    candidates.forEach((_init, element) => observer?.observe(element));

    // The chunk and its stylesheet are warmed after load while selects are still waiting, so the stylesheet's
    // document-wide restyle lands ahead of the scroll that brings the footer select near, not in it. Approach
    // and focus then only build the control (loadTomSelect is memoized).
    // - With requestIdleCallback: at the first idle moment.
    // - Without it (Safari): when a waiting select comes within SELECT_WARM_MARGIN_PX of the viewport. Never on
    //   a bare timer: it fired about when visitors tap their first link, and the navigation cancelled it.
    // A warm-up is optional: if the visitor leaves meanwhile, its cancelled load never reloads the page.
    const preload = (): void => {
        if (candidates.size > 0) {
            loadTomSelect(true).catch(() => undefined);
        }
    };
    const warm = (): void => {
        if (candidates.size === 0) {
            return;
        }

        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(preload, {timeout: 4000});
            return;
        }

        warmObserver = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                warmObserver?.disconnect();
                preload();
            }
        }, {rootMargin: `${SELECT_WARM_MARGIN_PX}px 0px`});
        candidates.forEach((_init, element) => warmObserver?.observe(element));
    };

    if (document.readyState === 'complete') warm();
    else window.addEventListener('load', warm, {once: true});
};

const shouldEnhanceSelects = window.AVD_SETTINGS?.enhancedSelects !== false;
// Public pages: lazy boot pass, then the shared added-nodes bus. Admin pages and the embed shell keep
// the boot pass and the subtree observer below, unchanged.
const selectsOnBus = shouldEnhanceSelects && addedRootsBusEnabled();

if (selectsOnBus) {
    initSelectsOnApproach();
    onAddedRoots((roots) => roots.forEach((root) => initSelects(root)));
}

if (shouldEnhanceSelects && !selectsOnBus) {
    initSelects();
}

if (typeof MutationObserver !== 'undefined' && document.body && shouldEnhanceSelects && !selectsOnBus) {
    const selectObserver = new MutationObserver(mutations => {
        mutations.forEach(mutation => {
            mutation.addedNodes.forEach(node => {
                if (node instanceof Element) {
                    initSelects(node);
                }
            });
        });
    });

    selectObserver.observe(document.body, {
        childList: true,
        subtree: true,
    });
}

const ajaxButtons = [...document.querySelectorAll('.ajax-button')];
ajaxButtons.forEach(element => element.addEventListener('click', async e => {
    const target = e.currentTarget as HTMLElement;
    const url = target.dataset.url as string;
    const method = target.dataset.method ?? 'GET';
    const body = target.dataset.body ?? null;

    await fetch(url, {
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')!.getAttribute('content') as string
        },
        method: method,
        credentials: 'include',
        body : body
    });
}))
