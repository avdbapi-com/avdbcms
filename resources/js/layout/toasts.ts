// The site's one toast system (watch-47; member design 4.4 and 5.10): the member kit's toast (.mk-toast in the
// .mk-toasts stack the layout prints, #avd-toasts; its CSS is in the main CSS, resources/sass/member/_shared.scss), on
// every page of the layout, public and member. It replaces the three systems the site had: the red Bootstrap toast at
// the top end (resources/js/functions/toast.ts), the inline-styled pill at the bottom end (window.avdToast) and the
// copy pill at the bottom centre (#copy-toast).
//
// - Placement is the stylesheet's: above the bottom bar, the ad strip and the member bars (--avd-bottom-chrome,
//   --mk-bars-h), bottom-centre on phones and bottom-end from 768px; at the TOP centre while a dialog is open
//   (body.modal-open) or a side panel is open (.mk-toasts--top, set here on Bootstrap's offcanvas events).
// - While a toast is on screen <html data-mk-toast> is set (the layout prints it when a toast comes with the page),
//   so "Back to top" and the daily-design pill step aside (opacity only) instead of sharing the corner.
// - A toast stays 4-8 s (errors longest), pauses while hovered or focused, and has a close button. At most three show
//   at once (the oldest goes). Errors are announced as alerts, the others as status messages.
// - 1.2.3 round 1b (R1R-12): the stack is above every dialog, sheet and backdrop (its z-index, member/_shared.scss).
//   While a dialog or a side panel is open, a screen reader may read only that dialog (aria-modal): the toast's words
//   are then said from a live region inside it, and the toast on screen carries no live role of its own, so they are
//   said once. Where they are said is decided when they are said (announce(), a moment after the toast shows), not
//   when the toast shows: a dialog that closes in the same moment (a failed Unsubscribe closes its dialog and shows its
//   error in one handler) is no longer open then, and the words are said from the page's own live region (in the
//   stack) once that dialog has gone, never into a dialog that is being removed.
// - While a dialog or a side panel is open the stack also keeps clear of its panel (placeStack(), R1R-12): the
//   stylesheet's place (top centre, under the header) when it leaves the panel free, else the same height beside a side
//   panel (in the free band next to a side sheet on a wide screen), else the top edge or the bottom edge of the screen
//   where the panel leaves room (a phone's Save to playlist dialog had its first playlists under the toast). A panel
//   that leaves no room anywhere (a full-screen panel on a phone) gets the place that covers the fewest of its
//   controls, and the toasts let taps through there (.mk-toasts--through: only their own buttons and links take taps),
//   so a control under a toast still works.
// - Text is text: showToast() writes textContent unless a caller passes `html` (window.avdToast keeps its old
//   contract: its callers pass escaped text or their own markup).

export type ToastType = 'success' | 'error' | 'info' | 'warning';

export type ToastOptions = {
    type?: ToastType;
    // The message is markup (window.avdToast's callers); otherwise it is set as text.
    html?: boolean;
    // A Font Awesome glyph class (e.g. 'fa-link'); the type's icon otherwise.
    icon?: string;
    // Milliseconds on screen; the type's default otherwise.
    duration?: number;
};

const ICONS: Record<ToastType, string> = {
    success: 'fa-circle-check',
    error: 'fa-triangle-exclamation',
    warning: 'fa-triangle-exclamation',
    info: 'fa-circle-info',
};

const DURATIONS: Record<ToastType, number> = {
    success: 4000,
    info: 5000,
    warning: 7000,
    error: 8000,
};

const MAX_VISIBLE = 3;
const LEAVE_MS = 160;

const live: HTMLElement[] = [];
const timers = new WeakMap<HTMLElement, {timer: number; remaining: number; started: number}>();
// the live region that said a toast's words (a layer's or the page's), emptied when the toast goes
const saidIn = new WeakMap<HTMLElement, {region: HTMLElement; words: string}>();
let closeLabel = 'Close';
let stack: HTMLElement | null = null;

const reducedMotion = (): boolean => {
    try {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch {
        return false;
    }
};

const toastStack = (): HTMLElement => {
    if (stack && stack.isConnected) {
        return stack;
    }

    stack = document.getElementById('avd-toasts');

    if (!stack) {
        stack = document.createElement('div');
        stack.id = 'avd-toasts';
        stack.className = 'mk-toasts avd-mk';
        stack.setAttribute('role', 'region');
        stack.setAttribute('data-avd-toasts', '');
        document.body.appendChild(stack);
    }

    syncPanels();

    return stack;
};

// <html data-mk-toast> exactly while at least one toast is on screen.
// While toasts show, --mk-toast-room is their stack's height plus its gap: the footer keeps that much empty room at the
// page's end (enhancements.scss), so a toast never covers its last links there.
const syncFlag = (): void => {
    const on = live.length > 0;
    const root = document.documentElement;

    if (root.hasAttribute('data-mk-toast') !== on) {
        root.toggleAttribute('data-mk-toast', on);
    }

    if (on && stack) {
        root.style.setProperty('--mk-toast-room', Math.ceil(stack.getBoundingClientRect().height) + 24 + 'px');
    } else {
        root.style.removeProperty('--mk-toast-room');
    }

    watchLayers(on);
};

// A side panel (Bootstrap offcanvas: the account panel, the notifications sheet, Filters, the front menu's drawer...)
// covers the bottom of the page on phones: the toasts move to the top while one is open. Only a panel Bootstrap opened
// counts (.show / .showing). The front menu is the same element pinned on wide screens (.show_sidebar, never .show
// there); opened as a drawer it carries .show too, so it counts like any other panel (it did not: a toast stayed at the
// bottom, over the drawer's lower links and its footer controls, instead of moving to the top with the other panels).
export const PANEL_OPEN_SELECTOR = '.offcanvas.show, .offcanvas.showing';

const syncPanels = (): void => {
    if (!stack) {
        return;
    }

    const open = document.querySelector(PANEL_OPEN_SELECTOR) !== null;

    if (stack.classList.contains('mk-toasts--top') !== open) {
        stack.classList.toggle('mk-toasts--top', open);
    }
};

const clearTimer = (toast: HTMLElement): void => {
    const state = timers.get(toast);

    if (state && state.timer) {
        window.clearTimeout(state.timer);
        state.remaining = Math.max(800, state.remaining - (Date.now() - state.started));
        state.timer = 0;
    }
};

const startTimer = (toast: HTMLElement): void => {
    const state = timers.get(toast);

    if (!state || state.timer || !live.includes(toast)) {
        return;
    }

    state.started = Date.now();
    state.timer = window.setTimeout(() => dismissToast(toast), state.remaining);
};

export const dismissToast = (toast: HTMLElement): void => {
    const index = live.indexOf(toast);

    if (index < 0) {
        return;
    }

    live.splice(index, 1);
    clearTimer(toast);

    // its words leave the live region that said them (as they left with the toast when it was the live region itself)
    const said = saidIn.get(toast);

    if (said && said.region.textContent === said.words) {
        said.region.textContent = '';
    }

    const done = (): void => {
        toast.remove();
        syncFlag();
        placeStack();
    };

    if (reducedMotion()) {
        done();
        return;
    }

    toast.classList.add('is-leaving');
    window.setTimeout(done, LEAVE_MS);
};

// Hover and keyboard focus keep a toast on screen (WCAG 2.2.1); the close button ends it.
const arm = (toast: HTMLElement, duration: number): void => {
    if (live.includes(toast)) {
        return;
    }

    live.push(toast);
    timers.set(toast, {timer: 0, remaining: duration, started: Date.now()});
    toast.addEventListener('pointerenter', () => clearTimer(toast));
    toast.addEventListener('pointerleave', () => startTimer(toast));
    toast.addEventListener('focusin', () => clearTimer(toast));
    toast.addEventListener('focusout', (event) => {
        if (!(event.relatedTarget instanceof Node) || !toast.contains(event.relatedTarget)) startTimer(toast);
    });
    toast.querySelector<HTMLElement>('[data-avd-toast-close], .mk-toast__close')?.addEventListener('click', () => dismissToast(toast));

    while (live.length > MAX_VISIBLE) {
        dismissToast(live[0]);
    }

    syncFlag();
    startTimer(toast);
};

const typeOf = (toast: HTMLElement): ToastType => {
    for (const type of ['error', 'warning', 'info'] as ToastType[]) {
        if (toast.classList.contains('mk-toast--' + type)) return type;
    }

    return 'success';
};

// A Font Awesome glyph from a caller's icon classes ('fa-solid fa-link text-info' -> 'fa-link'): the kit colours the
// icon by the toast's type, so colour utilities are dropped.
const glyphOf = (icon: string | undefined): string | null => {
    if (!icon) return null;
    const glyph = icon.split(/\s+/).find((name) => /^fa-[a-z0-9-]+$/.test(name) && !/^fa-(solid|regular|brands|light|thin|duotone|fw|lg|xl|2x|3x)$/.test(name));

    return glyph ?? null;
};

// The open layer a screen reader is kept in: the last open dialog (Bootstrap's and react-bootstrap's .modal.show), else
// the last open side panel (Bootstrap's offcanvas); null when none is open.
const LAYER_SELECTOR = '.modal.show, .offcanvas.show, .offcanvas.showing';
// A layer on its way out: no longer shown, but still on screen and still modal for a screen reader (Bootstrap removes
// aria-modal at the end of its hide transition; a closing side panel slides out as .hiding). A closing dialog is
// display: none at once (the layout's ".modal:not(.show)" rule), so it is no longer one.
const CLOSING_SELECTOR = '.modal[aria-modal="true"]:not(.show), .offcanvas.hiding';
const SAY_ATTRIBUTE = 'data-avd-toast-say';
const SAY_AFTER_MS = 60;
const CLOSING_WAIT_MS = 1000;

const openLayer = (): HTMLElement | null => {
    const open = Array.from(document.querySelectorAll<HTMLElement>(LAYER_SELECTOR));
    const dialogs = open.filter((layer) => layer.classList.contains('modal'));
    const from = dialogs.length > 0 ? dialogs : open;

    return from.length > 0 ? from[from.length - 1] : null;
};

const closingLayer = (): boolean => Array.from(document.querySelectorAll<HTMLElement>(CLOSING_SELECTOR))
    .some((layer) => layer.isConnected && layer.getClientRects().length > 0);

// The visually hidden live region at the end of a layer, or of the toast stack for the page (one each, kept for the next
// toast; .mk-sr is out of the stack's flow).
const regionIn = (host: HTMLElement): HTMLElement => {
    let say = Array.from(host.children).find((child) => child.hasAttribute(SAY_ATTRIBUTE)) as HTMLElement | undefined;

    if (!say) {
        say = document.createElement('div');
        say.className = 'mk-sr';
        say.setAttribute(SAY_ATTRIBUTE, '');
        say.setAttribute('aria-atomic', 'true');
        host.appendChild(say);
    }

    return say;
};

// The region is emptied first and the words go in a moment later: a live region reads out what is added to it, also
// the same words twice in a row.
const sayIn = (region: HTMLElement, toast: HTMLElement, words: string, type: ToastType): void => {
    region.setAttribute('role', type === 'error' ? 'alert' : 'status');
    region.textContent = '';
    saidIn.set(toast, {region, words});
    window.setTimeout(() => {
        region.textContent = words;
    }, SAY_AFTER_MS);
};

// A toast shown while a layer was open has no live role: its words are said from the layer that is open when they are
// said, else from the page's region once every closing layer has gone (bounded wait).
const announce = (toast: HTMLElement, words: string, type: ToastType, waited = 0): void => {
    const layer = openLayer();

    if (layer) {
        sayIn(regionIn(layer), toast, words, type);
        return;
    }

    if (waited < CLOSING_WAIT_MS && closingLayer()) {
        window.setTimeout(() => announce(toast, words, type, waited + 50), 50);
        return;
    }

    sayIn(regionIn(toastStack()), toast, words, type);
};

// Where the stack sits while a layer is open (see the top of this file). Tried in this order; '' is the stylesheet's
// place, BESIDE the same height in the free band next to a side panel (a side sheet on a wide screen: the toast over
// the dimmed page, not on the sheet's edge; set as the stack's left / right, no rule of its own).
const BESIDE = 'beside';
const PLACES = ['', BESIDE, 'mk-toasts--edge-top', 'mk-toasts--edge-bottom'];
const PLACE_CLASSES = ['mk-toasts--edge-top', 'mk-toasts--edge-bottom', 'mk-toasts--through'];

const clearPlace = (host: HTMLElement): void => {
    host.classList.remove(...PLACE_CLASSES);
    host.style.removeProperty('left');
    host.style.removeProperty('right');
};

// The wider free band beside the panel when the toasts fit in it with 16 px on each side (null otherwise).
const besideBand = (panel: DOMRect, widest: number): {left: number; right: number} | null => {
    const width = document.documentElement.clientWidth;
    const start = panel.left;
    const end = width - panel.right;
    const free = Math.max(start, end);

    if (free < widest + 32) {
        return null;
    }

    return start >= end ? {left: 16, right: width - start + 16} : {left: panel.right + 16, right: 16};
};
const CONTROL_SELECTOR = 'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])';
const CLOSE_SELECTOR = '.btn-close, [data-bs-dismiss]';

type Box = {left: number; top: number; right: number; bottom: number};

const meets = (a: Box, b: Box): boolean => Math.min(a.right, b.right) - Math.max(a.left, b.left) > 1
    && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 1;

const panelOf = (layer: HTMLElement): HTMLElement => (layer.classList.contains('modal') ? layer.querySelector<HTMLElement>('.modal-content') : null) ?? layer;

const placeStack = (): void => {
    if (!stack) {
        return;
    }

    const host = stack;
    const layer = live.length > 0 ? openLayer() : null;

    // the side panels' top place follows react-bootstrap's panels too (no Bootstrap events: the stack stayed at the top
    // after the notifications sheet closed, until the next toast)
    syncPanels();

    if (!layer) {
        if (PLACE_CLASSES.some((name) => host.classList.contains(name)) || host.style.left !== '' || host.style.right !== '') {
            clearPlace(host);
        }

        return;
    }

    const panel = panelOf(layer);
    const panelBox = panel.getBoundingClientRect();
    // the panel's controls on screen; covering its close button counts most
    const controls = Array.from(panel.querySelectorAll<HTMLElement>(CONTROL_SELECTOR))
        .filter((control) => control.getClientRects().length > 0)
        .map((control) => ({box: control.getBoundingClientRect() as Box, weight: control.matches(CLOSE_SELECTOR) ? 10 : 1}));
    const toasts = live.filter((toast) => toast.parentElement === host);
    let best: {place: string; covered: number} | null = null;

    // puts the stack at a place; false when the place does not exist here (no free band beside the panel)
    const apply = (place: string): boolean => {
        clearPlace(host);

        if (place === BESIDE) {
            const band = besideBand(panelBox, Math.max(0, ...toasts.map((toast) => toast.offsetWidth)));

            if (band === null) {
                return false;
            }

            host.style.left = band.left + 'px';
            host.style.right = band.right + 'px';
        } else if (place !== '') {
            host.classList.add(place);
        }

        return true;
    };

    for (const place of PLACES) {
        if (!apply(place)) {
            continue;
        }

        // the toasts' boxes at this place (their stack is fixed and never transformed)
        const origin = host.getBoundingClientRect();
        const boxes: Box[] = toasts.map((toast) => ({
            left: origin.left + toast.offsetLeft,
            top: origin.top + toast.offsetTop,
            right: origin.left + toast.offsetLeft + toast.offsetWidth,
            bottom: origin.top + toast.offsetTop + toast.offsetHeight,
        }));

        if (!boxes.some((box) => meets(box, panelBox))) {
            return;
        }

        const covered = controls.reduce((sum, control) => sum + (boxes.some((box) => meets(box, control.box)) ? control.weight : 0), 0);

        if (best === null || covered < best.covered) {
            best = {place, covered};
        }
    }

    // every place lies on the panel: the one covering the fewest controls, letting taps through (a panel that scrolls
    // can bring another control under it later)
    apply(best !== null ? best.place : '');
    host.classList.add('mk-toasts--through');
};

// While toasts show: a layer that opens or closes (Bootstrap's events; react-bootstrap's dialogs and panels toggle
// body.modal-open and come and go as portals at the end of <body>) or a turned phone places the stack again, in the
// next frame and once more when the layer has settled (a dialog slides in for 300 ms).
const SETTLE_MS = 350;
let placing = 0;
let settling = 0;
let watcher: MutationObserver | null = null;

const placeSoon = (): void => {
    if (live.length === 0) {
        return;
    }

    if (!placing) {
        placing = window.requestAnimationFrame(() => {
            placing = 0;
            placeStack();
        });
    }

    window.clearTimeout(settling);
    settling = window.setTimeout(() => {
        settling = 0;
        placeStack();
    }, SETTLE_MS);
};

const watchLayers = (on: boolean): void => {
    if (on && !watcher && typeof MutationObserver === 'function') {
        watcher = new MutationObserver(placeSoon);
        watcher.observe(document.body, {attributes: true, attributeFilter: ['class'], childList: true});
        window.addEventListener('resize', placeSoon);
    } else if (!on && watcher) {
        watcher.disconnect();
        watcher = null;
        window.removeEventListener('resize', placeSoon);
    }
};

export const showToast = (message: string, options: ToastOptions = {}): HTMLElement | null => {
    // While a view-only demo account's "This is a demo" window is open (App\Support\ViewOnlyDemoWindow), it is the
    // answer to the refused request: the toast its caller asks for is not shown (the refusal is said once).
    if (!document.body || !message || document.documentElement.hasAttribute('data-view-only-demo-open')) {
        return null;
    }

    const type: ToastType = options.type ?? 'success';
    const layer = openLayer();
    const toast = document.createElement('div');
    toast.className = 'mk-toast mk-toast--' + type;

    if (!layer) {
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    }

    toast.setAttribute('data-avd-toast', '');

    const icon = document.createElement('i');
    icon.className = 'fa-solid ' + (glyphOf(options.icon) ?? ICONS[type]);
    icon.setAttribute('aria-hidden', 'true');

    const text = document.createElement('span');
    text.className = 'mk-toast__text';

    if (options.html) {
        text.innerHTML = message;
    } else {
        text.textContent = message;
    }

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'mk-icon-btn mk-icon-btn--sm mk-toast__close';
    close.setAttribute('aria-label', closeLabel);
    close.setAttribute('data-avd-toast-close', '');
    close.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';

    toast.append(icon, text, close);
    toastStack().appendChild(toast);
    arm(toast, options.duration ?? DURATIONS[type]);

    if (layer) {
        const words = (text.textContent ?? '').replace(/\s+/g, ' ').trim();
        window.setTimeout(() => announce(toast, words, type), SAY_AFTER_MS);
        // in its first frame at a place that leaves the panel free, and again once an opening panel has settled
        placeStack();
        placeSoon();
    }

    return toast;
};

// Old type names of window.avdToast: 'danger' is an error.
export const toastType = (type?: string): ToastType => {
    switch (type) {
        case 'danger':
        case 'error':
            return 'error';
        case 'warning':
            return 'warning';
        case 'info':
            return 'info';
        default:
            return 'success';
    }
};

let started = false;

// Once per page: the toasts that came with the page, the panel listeners and the translated close label.
export const initToastSystem = (label?: string): void => {
    if (started) {
        return;
    }

    started = true;

    if (label) {
        closeLabel = label;
    }

    document.querySelectorAll<HTMLElement>('[data-avd-toast]').forEach((toast) => arm(toast, DURATIONS[typeOf(toast)]));
    syncFlag();

    ['show.bs.offcanvas', 'shown.bs.offcanvas', 'hide.bs.offcanvas', 'hidden.bs.offcanvas'].forEach((name) => {
        document.addEventListener(name, () => window.requestAnimationFrame(() => {
            syncPanels();
            placeSoon();
        }));
    });

    ['shown.bs.modal', 'hidden.bs.modal'].forEach((name) => {
        document.addEventListener(name, placeSoon);
    });
};
