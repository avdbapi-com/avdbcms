// Dialogs and side panels lock the page's scroll without the layout thrash (perf-14, 1.2.3).
//
// Bootstrap's ScrollBarHelper locks the page when a dialog (Modal) or a side panel (Offcanvas) opens: it hides the
// page's scrollbar (body overflow hidden) and pads the body and the fixed and sticky bars by the scrollbar's width, so
// nothing moves. It interleaves layout reads with those writes: it measures the scrollbar again after hiding it, then
// reads each bar's width after the previous write. Every read after a write forces a layout of the whole page, the first
// one in the in-between state (no scrollbar, no padding yet: every line of the page one scrollbar wider). On a weak
// laptop that was a 230 ms forced layout inside the click (perf-14: Save to playlist 232-416 ms, the comments sheet
// 248-312 ms, the menu 152-208 ms at CPU 4x).
//
// This lock gives the same result (the same overflow, paddings and margins, restored the same way) with every read done
// before any write: one layout at most, of the page as it already is, and the next one sees the final state. Bootstrap's
// own components keep everything else: Modal gets this lock as its scroll bar helper (_scrollBar, which its show, hide
// and dialog adjustment use), Offcanvas skips its helper while this lock runs (its scroll option is set only for the
// moment Bootstrap reads it; the focus trap rule still sees the panel's own option). Nested locks (a dialog opened from
// a side panel) count: the page is restored when the last one closes.
import {Modal, Offcanvas} from 'bootstrap';

type Saved = {element: HTMLElement; property: string; value: string};

const FIXED_CONTENT = '.fixed-top, .fixed-bottom, .is-fixed, .sticky-top';
const STICKY_CONTENT = '.sticky-top';

let count = 0;
let width = 0;
let saved: Saved[] = [];

export const lockPageScroll = (): void => {
    count += 1;

    if (count > 1) {
        return;
    }

    const body = document.body;
    const viewport = window.innerWidth;
    // Reads first: the scrollbar's width, which bars span the page, and their current padding or margin.
    width = Math.abs(viewport - document.documentElement.clientWidth);
    const writes: Array<[HTMLElement, string, string]> = [[body, 'overflow', 'hidden']];

    if (width > 0) {
        writes.push([body, 'padding-right', `${Number.parseFloat(window.getComputedStyle(body).paddingRight) + width}px`]);
        // Bootstrap pads a bar that would span the whole window once the scrollbar is gone, which it measures after the
        // body's padding: a fixed bar across the window (it grows by the scrollbar's width), or a bar in the page that is
        // already as wide as the window (100vw). A bar in the page's flow keeps its width (the padded body holds it).
        const spans = (element: HTMLElement, style: CSSStyleDeclaration): boolean => style.position === 'fixed'
            ? element.clientWidth > 0 && element.clientWidth + width >= viewport
            : element.clientWidth >= viewport;
        body.querySelectorAll<HTMLElement>(FIXED_CONTENT).forEach((element) => {
            const style = window.getComputedStyle(element);
            if (spans(element, style)) {
                writes.push([element, 'padding-right', `${Number.parseFloat(style.paddingRight) + width}px`]);
            }
        });
        body.querySelectorAll<HTMLElement>(STICKY_CONTENT).forEach((element) => {
            const style = window.getComputedStyle(element);
            if (spans(element, style)) {
                writes.push([element, 'margin-right', `${Number.parseFloat(style.marginRight) - width}px`]);
            }
        });
    }

    // Then the writes, keeping each property's inline value to restore.
    saved = writes.map(([element, property]) => ({element, property, value: element.style.getPropertyValue(property)}));
    writes.forEach(([element, property, value]) => element.style.setProperty(property, value));
};

export const unlockPageScroll = (): void => {
    if (count === 0) {
        return;
    }

    count -= 1;

    if (count > 0) {
        return;
    }

    for (let i = saved.length - 1; i >= 0; i -= 1) {
        const {element, property, value} = saved[i];

        if (value) {
            element.style.setProperty(property, value);
        } else {
            element.style.removeProperty(property);
        }
    }

    saved = [];
    width = 0;
};

// What Bootstrap's Modal asks of its scroll bar helper. Modal measures the scroll bar (_adjustDialog) only after hide()
// has locked the page, when Bootstrap's own helper reads 0 (no scroll bar any more): the same answer here, without the
// layout read. Outside a lock (nothing in Modal asks then) the window is measured as Bootstrap does.
const scrollBarWidth = (): number => (count > 0 ? 0 : Math.abs(window.innerWidth - document.documentElement.clientWidth));
const modalScrollBar = {
    hide: lockPageScroll,
    reset: unlockPageScroll,
    getWidth: scrollBarWidth,
    isOverflowing: (): boolean => scrollBarWidth() > 0,
};

type BootstrapInternals = {
    _scrollBar?: unknown;
    _config?: {scroll?: boolean};
    _element?: HTMLElement;
    _isShown?: boolean;
    __avdLocked?: boolean;
};

let installed = false;

export const installCalmScrollLock = (): void => {
    if (installed || typeof Modal !== 'function' || typeof Offcanvas !== 'function') {
        return;
    }

    installed = true;

    const modal = Modal.prototype as unknown as {show: (this: BootstrapInternals, ...args: unknown[]) => unknown};
    const modalShow = modal.show;
    modal.show = function (this: BootstrapInternals, ...args: unknown[]) {
        this._scrollBar = modalScrollBar;

        return modalShow.apply(this, args);
    };

    const offcanvas = Offcanvas.prototype as unknown as {
        show: (this: BootstrapInternals, ...args: unknown[]) => unknown;
        hide: (this: BootstrapInternals, ...args: unknown[]) => unknown;
    };
    const offcanvasShow = offcanvas.show;
    const offcanvasHide = offcanvas.hide;

    offcanvas.show = function (this: BootstrapInternals, ...args: unknown[]) {
        const config = this._config;

        if (!config || config.scroll !== false || this._isShown) {
            return offcanvasShow.apply(this, args);
        }

        config.scroll = true; // Bootstrap's own helper is skipped ...
        try {
            const result = offcanvasShow.apply(this, args);

            if (this._isShown && !this.__avdLocked) {
                this.__avdLocked = true;
                lockPageScroll();
            }

            return result;
        } finally {
            config.scroll = false; // ... and its focus trap rule (read when the panel has opened) sees the panel's own option
        }
    };

    offcanvas.hide = function (this: BootstrapInternals, ...args: unknown[]) {
        const config = this._config;
        const element = this._element;

        if (!this.__avdLocked || !config || !element) {
            return offcanvasHide.apply(this, args);
        }

        // Bootstrap restores the page in the panel's closing transition when its scroll option is off: skipped here, this
        // lock restores it once the panel is hidden.
        config.scroll = true;
        const done = (): void => {
            element.removeEventListener('hidden.bs.offcanvas', done);
            config.scroll = false;

            if (this.__avdLocked) {
                this.__avdLocked = false;
                unlockPageScroll();
            }
        };
        element.addEventListener('hidden.bs.offcanvas', done);
        const result = offcanvasHide.apply(this, args);

        if (this._isShown) {
            // the hide was cancelled (a hide.bs.offcanvas listener prevented it): nothing changes
            element.removeEventListener('hidden.bs.offcanvas', done);
            config.scroll = false;
        }

        return result;
    };
};
