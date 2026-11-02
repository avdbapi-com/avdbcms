// Build: AVDBClip Lazyload & Feed Performance v2.4
import '../sass/app.scss';
import * as bootstrap from 'bootstrap'
import Alpine from 'alpinejs'
// The layout's page features, first: they used to be inline scripts that ran
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

declare global {
    interface Window {
        // The crop sheet (users/partials/crop.blade.php) waits for Cropper.js before it builds its crop box (account-10:
        // a picture picked before the background import ended opened a broken dialog, "Cropper is not defined").
        avdCropperReady?: Promise<unknown>;
        avdLoadCropper?: () => Promise<unknown>;
    }
}

window.bootstrap = bootstrap;
window.Alpine = Alpine

const isMobileViewport = () => window.matchMedia('(max-width: 767.98px)').matches || window.matchMedia('(pointer: coarse)').matches;
const isAdminPage = () => document.body?.classList.contains('avd-admin') === true;
// The Upload18 embed player pages (layouts/embed.blade.php, videos/embed/public.blade.php) also
// load this entry on body.avd-embed-shell. New initializers must stay out of them.
const isEmbedPage = () => document.body?.classList.contains('avd-embed-shell') === true;

// Cropper.js, once, as a promise the crop sheet awaits (window.avdCropperReady; member design 9.3): started at boot
// on a page with the crop sheet (#crop), else on the first read of window.avdCropperReady or avdLoadCropper(). A failed
// load is not kept: the next read tries again.
let cropperLoad: Promise<unknown> | null = null;
const loadCropper = (): Promise<unknown> => {
    if (!cropperLoad) {
        const load = Promise.all([
            import('cropperjs'),
            import('cropperjs/dist/cropper.min.css'),
        ]).then(([module]) => {
            window.Cropper = module.default;

            return module.default;
        });

        cropperLoad = load;
        load.catch(() => {
            if (cropperLoad === load) cropperLoad = null;
        });
    }

    return cropperLoad;
};

window.avdLoadCropper = loadCropper;

if (document.getElementById('crop')) {
    window.avdCropperReady = loadCropper();
    window.avdCropperReady.catch(() => undefined);
} else {
    Object.defineProperty(window, 'avdCropperReady', {configurable: true, enumerable: true, get: loadCropper});
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
    const payload = (event as Event & {payload?: unknown}).payload;
    const message = payload instanceof Error ? payload.message : String(payload ?? '');

    // watch-27: a stylesheet a chunk could not preload does not stop the chunk. Handled (preventDefault), Vite goes on
    // importing the module instead of rethrowing an unhandled rejection, which WebKit logged on every page left while a
    // chunk was loading ("Unable to preload CSS ..."). A module that failed to load keeps its rejection: handled, its
    // import would resolve to undefined and break the importer's own error path (the components' registration, the
    // motion chunk). The reload decision is ../dom/navIntent's (never while the visitor is leaving).
    if (/^Unable to preload CSS/i.test(message)) {
        event.preventDefault();
    }

    requestAssetReload('avd_vite_preload_reload', '[Vite] Preload error detected, reloading page for fresh assets...', payload);
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

// The searchable lists' own words, translated (studio-27): the layout's text block (#avd-i18n, components/layout.blade.php).
const selectTexts = ((): {noResults?: string; removeItem?: string} => {
    try {
        return JSON.parse(document.getElementById('avd-i18n')?.textContent || '{}').t || {};
    } catch {
        return {};
    }
})();

const tomSelectRender = {
    option: function(data: TomRenderData, escape: (value: string) => string) {
        return '<div class="avd-select-option-label">' + escape(data.text ?? '') + '</div>';
    },
    item: function(data: TomRenderData, escape: (value: string) => string ) {
        return '<div class="avd-select-item-label">' + escape(data.text ?? '') + '</div>';
    },
    no_results:function(data: TomRenderData,escape: (value: string) => string){
        const text = selectTexts.noResults || 'No results for ":query"';

        return '<div class="no-results">' + escape(text).split(':query').join(escape(data.input ?? '')) + '</div>';
    },
};

// The search of the searchable lists (R1-065). TomSelect's own accent-insensitive search (diacritics: true) builds a
// table of the variants of every character (65,536 of them) at the first search: one task of 0.3-1.8 s on a phone,
// which the keys typed meanwhile wait for. Here each label is folded once (accents, case, wide and joined letters:
// C-circumflex-ote -> "cote", A-ring-land -> "aland") and the typed words are folded the same way, so "etats" still
// finds the French "Etats-Unis" written with an accent. The scoring is TomSelect's default one on the folded texts: every
// word must be in the label, a label that starts with a word and a shorter label come first.
const foldSearchText = (value: string): string => value
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/\u00df/g, 'ss')
    .replace(/\u00e6/g, 'ae')
    .replace(/\u0153/g, 'oe')
    .replace(/[\u00f8\u0111\u00f0\u0142\u0131]/g, (letter) => ({'\u00f8': 'o', '\u0111': 'd', '\u00f0': 'd', '\u0142': 'l', '\u0131': 'i'} as Record<string, string>)[letter] ?? letter);
const foldedLabels = new Map<string, string>();
const foldedLabel = (label: string): string => {
    let folded = foldedLabels.get(label);

    if (folded === undefined) {
        folded = foldSearchText(label);
        foldedLabels.set(label, folded);
    }

    return folded;
};
// TomSelect also marks the typed letters in each result (its "highlight"): with the character table, or without it only
// where the label has the letters as typed ("etats" left the accented "Etats" plain). The marks are made here from the
// folded texts instead, after every refresh of the list like TomSelect's own: the first place of each typed word in each
// text of a result, a decomposed accent staying with its letter. Folded texts are kept with the place of every letter
// (ASCII letters only lower-cased, the rest folded one character at a time), at most 2,000 of them.
type FoldedText = { text: string; starts: number[]; ends: number[] };
const foldedTexts = new Map<string, FoldedText>();
const foldWithPlaces = (value: string): FoldedText => {
    let folded = foldedTexts.get(value);

    if (folded === undefined) {
        folded = {text: '', starts: [], ends: []};
        let at = 0;

        for (const character of value) {
            const part = character.charCodeAt(0) < 128 ? character.toLowerCase() : foldSearchText(character);

            for (let i = 0; i < part.length; i++) {
                folded.starts.push(at);
                folded.ends.push(at + character.length);
            }

            folded.text += part;
            at += character.length;
        }

        if (foldedTexts.size >= 2000) {
            foldedTexts.clear();
        }

        foldedTexts.set(value, folded);
    }

    return folded;
};
const markFoldedWords = (content: HTMLElement, query: string): void => {
    content.querySelectorAll('span.highlight').forEach((mark) => {
        const parent = mark.parentNode;

        if (parent) {
            parent.replaceChild(document.createTextNode(mark.textContent ?? ''), mark);
            parent.normalize();
        }
    });

    const words = foldSearchText(query).split(/\s+/).filter((word) => word !== '');

    if (words.length === 0) {
        return;
    }

    content.querySelectorAll('.option').forEach((option) => {
        const walker = document.createTreeWalker(option, NodeFilter.SHOW_TEXT);
        const texts: Text[] = [];

        for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
            texts.push(node as Text);
        }

        for (const text of texts) {
            const folded = foldWithPlaces(text.data);
            const places: Array<[number, number]> = [];

            for (const word of words) {
                const at = folded.text.indexOf(word);

                if (at === -1) {
                    continue;
                }

                let end = folded.ends[at + word.length - 1];

                while (end < text.data.length && /[\u0300-\u036f]/.test(text.data.charAt(end))) {
                    end++;
                }

                places.push([folded.starts[at], end]);
            }

            places.sort((a, b) => a[0] - b[0]);

            const merged: Array<[number, number]> = [];

            for (const [start, end] of places) {
                const last = merged[merged.length - 1];

                if (last !== undefined && start <= last[1]) {
                    last[1] = Math.max(last[1], end);
                } else {
                    merged.push([start, end]);
                }
            }

            // from the last place back, so the places before it keep their offsets in the text node
            for (let i = merged.length - 1; i >= 0; i--) {
                const [start, end] = merged[i];
                const middle = text.splitText(start);
                const mark = document.createElement('span');

                middle.splitText(end - start);
                mark.className = 'highlight';
                middle.parentNode?.replaceChild(mark, middle);
                mark.appendChild(middle);
            }
        }
    });
};
const tomSelectSearch = {
    diacritics: false,
    highlight: false,
    onInitialize: function (this: InstanceType<TomSelectConstructor>) {
        this.hook('after', 'refreshOptions', () => markFoldedWords(this.dropdown_content, this.inputValue()));
    },
    score: function (query: string) {
        const words = foldSearchText(query).split(/\s+/).filter((word) => word !== '');

        return (item?: TomRenderData): number => {
            if (words.length === 0) {
                return 1;
            }

            const label = foldedLabel(String(item?.text ?? ''));
            let score = 0;

            for (const word of words) {
                const at = label.indexOf(word);

                if (at === -1) {
                    return 0;
                }

                score += word.length / label.length + (at === 0 ? 0.5 : 0);
            }

            return score / words.length;
        };
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
        ...tomSelectSearch,
        dropdownParent: 'body',
        render: {
            ...tomSelectRender,
        },
        plugins: {
            remove_button:{
                title: selectTexts.removeItem || 'Remove',
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
        ...tomSelectSearch,
        allowEmptyOption: true,
        closeAfterSelect: true,
        create: false,
        dropdownParent: 'body',
        // perf-4, account-15: the list draws at most 50 options; typing finds the others (250 countries were drawn in
        // a 930 ms task on a weak phone).
        maxOptions: 50,
        render: {
            ...tomSelectRender,
            item: function(data: TomRenderData, escape: (value: string) => string ) {
                return '<div class="avd-select-item-label">' + escape(data.text ?? '') + '</div>';
            },
        },
    });
};

// select.select-multiple, never the class alone (studio-17, perf-10): TomSelect copies the select's classes onto its
// own wrapper <div>, and the added-nodes bus then ran new TomSelect(div), which threw "Cannot read properties of
// undefined (reading 'trim')" on every Video details and draft page load.
const MULTIPLE_SELECT = 'select.select-multiple';

const initSelects = (root: ParentNode = document) => {
    selectElements(root, MULTIPLE_SELECT).forEach(initMultipleSelect);
    selectElements(root, singleSelectSelector).forEach(initSingleSelect);
};

// Public pages: TomSelect, its chunk and the stylesheet it injects are built at
// boot only for the selects that are within 300 px of the viewport, or inside a hidden container (a
// closed modal, an inactive tab: no box), which must be ready the moment it opens. The others, such as
// the footer language select, are enhanced when they come within 300 px of the viewport, or on focus
// (keyboard), whichever comes first; a focused select hands its focus to the new control. Selects
// added later are enhanced at once, as before.
const SELECT_WARM_MARGIN_PX = 1200;
// A long list (the 250 countries) is enhanced only when the member reaches for it (focus, or a press that would open
// the native list), never while the page scrolls past it (account-15: up to 18 TomSelects built during a scroll).
const LAZY_SELECT_OPTIONS = 50;
const isLongList = (element: Element): boolean => element instanceof HTMLSelectElement && !element.multiple && element.options.length > LAZY_SELECT_OPTIONS;

const initSelectsOnApproach = (): void => {
    const candidates = new Map<Element, (element: Element) => Promise<void>>();

    selectElements(document, MULTIPLE_SELECT).forEach((element) => candidates.set(element, initMultipleSelect));
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

    // A press on a long list that waits for its control: the control opens instead of the native list.
    const onPointerDown = (event: PointerEvent): void => {
        const target = event.target instanceof Element ? event.target.closest('select') : null;

        if (target && candidates.has(target) && isLongList(target)) {
            event.preventDefault();
            enhance(target, true, true);
        }
    };

    const enhance = (element: Element, focused: boolean, open = false): void => {
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
            document.removeEventListener('pointerdown', onPointerDown, true);
        }

        init(element).then(() => {
            const control = (element as TomInput & {tomselect?: {focus: () => void; open: () => void}}).tomselect;

            if (control && (open || (focused && document.activeElement === element))) {
                control.focus();

                if (open) {
                    control.open();
                }
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
    document.addEventListener('pointerdown', onPointerDown, true);
    candidates.forEach((_init, element) => {
        if (!isLongList(element)) {
            observer?.observe(element);
        }
    });

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
