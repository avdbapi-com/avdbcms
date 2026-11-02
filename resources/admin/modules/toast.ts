// Toasts: bottom-right (bottom on phones), success disappears after 4 s, errors stay until closed.
// window.adminToast(text, {type, undo}) - undo: a URL to open or a function to call (shown as "Undo").
//
// The stack never covers a bar at the bottom of the window: the save bars (their Save changes and Discard), the
// Appearance publish bar on smaller screens, a language's save row, the view-only note, and any element marked
// [data-ad-bottom-bar]. While toasts show, the stack stays where it is when it fits below every such bar, else it sits
// above the highest one; phones too. A bar that sticks to the bottom of the window counts where it stands stuck and
// where it stands at the end of the page, so scrolling never brings it under the stack. Measured when a toast appears
// and when, while toasts show, a bar appears or hides or the window or the page changes size: no scroll listener.

import {$, escapeHtml, icon, t} from '../lib/dom';
import {liftFor, stickyReach, type Reach} from '../lib/placement';

export type ToastOptions = {
    type?: 'ok' | 'error' | 'info';
    undo?: string | (() => void);
    undoLabel?: string;
    timeout?: number;
};

/** Bars at the bottom of the window the stack keeps clear of (only the ones that are fixed or sticky count). */
export const BOTTOM_BARS = '.ad-savebar, .ad-translate-save, .ad-publish, .avd-view-only-note, [data-ad-bottom-bar]';

const container = (): HTMLElement => {
    let box = $('#ad-toasts');

    if (!box) {
        box = document.createElement('div');
        box.id = 'ad-toasts';
        box.className = 'ad-toasts';
        document.body.appendChild(box);
    }

    // Errors interrupt (assertive); confirmations wait for a pause (polite).
    box.setAttribute('aria-live', 'polite');

    return box;
};

/** Where a bar can stand, or null when it is not shown or not a bar that stays at the bottom of the window. */
const reachOf = (bar: HTMLElement, viewport: number): Reach | null => {
    if (bar.closest('#ad-toasts') || bar.hidden || bar.getClientRects().length === 0) return null;

    const style = window.getComputedStyle(bar);

    if (style.display === 'none' || style.visibility === 'hidden') return null;

    const rect = bar.getBoundingClientRect();

    if (rect.height === 0 || rect.width === 0) return null;

    if (style.position === 'fixed') {
        return {low: viewport - rect.bottom, high: viewport - rect.top, left: rect.left, right: rect.right};
    }

    if (style.position !== 'sticky') return null;

    // Where it stands in the page when not stuck (sticky and static take the same room, so nothing else moves).
    const inline = bar.style.position;
    bar.style.position = 'static';
    const natural = bar.getBoundingClientRect();
    bar.style.position = inline;

    return stickyReach(natural.bottom + window.scrollY, natural.height, parseFloat(style.bottom) || 0, viewport,
        document.documentElement.scrollHeight, rect.left, rect.right);
};

let frame = 0;
let watching = false;

const place = (): void => {
    const box = $('#ad-toasts');

    if (!box) return;

    box.style.bottom = '';

    if (box.childElementCount === 0) {
        unwatch();

        return;
    }

    const viewport = document.documentElement.clientHeight;
    const base = parseFloat(window.getComputedStyle(box).bottom) || 0;
    const stack = box.getBoundingClientRect();
    const bars = Array.from(document.querySelectorAll<HTMLElement>(BOTTOM_BARS))
        .map((bar) => reachOf(bar, viewport))
        .filter((reach): reach is Reach => reach !== null);
    const bottom = liftFor({left: stack.left, right: stack.right, height: stack.height}, base, bars, viewport);

    if (bottom > base) box.style.bottom = `${Math.round(bottom)}px`;
};

const schedule = (): void => {
    if (frame) return;

    frame = window.requestAnimationFrame(() => {
        frame = 0;
        place();
    });
};

// While toasts show: a bar that appears or hides (hidden, a class), the window and the page changing size.
const changes = typeof MutationObserver === 'function' ? new MutationObserver(schedule) : null;
const sizes = typeof ResizeObserver === 'function' ? new ResizeObserver(schedule) : null;

const watch = (): void => {
    if (watching) return;

    watching = true;
    changes?.observe(document.body, {attributes: true, attributeFilter: ['hidden', 'class'], subtree: true});
    sizes?.observe(document.documentElement);
    window.addEventListener('resize', schedule);
};

const unwatch = (): void => {
    if (!watching) return;

    watching = false;
    changes?.disconnect();
    sizes?.disconnect();
    window.removeEventListener('resize', schedule);
};

const arm = (toast: HTMLElement, timeout: number): void => {
    toast.querySelector<HTMLButtonElement>('.ad-toast-x')?.addEventListener('click', () => toast.remove());

    if (timeout > 0) {
        let timer = window.setTimeout(() => toast.remove(), timeout);

        // Pointer or keyboard focus on the toast keeps it (it may hold an Undo link).
        toast.addEventListener('pointerenter', () => window.clearTimeout(timer));
        toast.addEventListener('focusin', () => window.clearTimeout(timer));
        toast.addEventListener('pointerleave', () => {
            timer = window.setTimeout(() => toast.remove(), timeout);
        });
    }
};

/** The stack moves when toasts come and go (the server's ones, adminToast's, a page's own). */
const follow = (box: HTMLElement): void => {
    if (box.dataset.adPlaced === '1') return;

    box.dataset.adPlaced = '1';

    if (typeof MutationObserver === 'function') {
        new MutationObserver(() => {
            if (box.childElementCount > 0) watch();
            schedule();
        }).observe(box, {childList: true});
    }
};

export const adminToast = (text: string, options: ToastOptions = {}): HTMLElement => {
    const type = options.type ?? 'ok';
    const toast = document.createElement('div');

    // While a view-only demo account's "This is a demo" window is open (App\Support\ViewOnlyDemoWindow), it is the
    // answer to the refused request: the caller's toast is not shown (the refusal is said once).
    if (document.documentElement.hasAttribute('data-view-only-demo-open')) return toast;
    toast.className = 'ad-toast' + (type === 'error' ? ' is-error' : '');
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

    const symbol = type === 'error' ? 'alert-circle' : type === 'info' ? 'info' : 'check-circle';
    let action = '';

    if (typeof options.undo === 'string' && /^(https?:\/\/|\/)/.test(options.undo)) {
        action = `<a href="${escapeHtml(options.undo)}">${escapeHtml(options.undoLabel ?? t('undo', 'Undo'))}</a>`;
    } else if (typeof options.undo === 'function') {
        action = `<button type="button" class="ad-toast-undo">${escapeHtml(options.undoLabel ?? t('undo', 'Undo'))}</button>`;
    }

    toast.innerHTML = `${icon(symbol)}<span>${escapeHtml(text)}</span>${action}`
        + `<button type="button" class="ad-toast-x" aria-label="${escapeHtml(t('close', 'Close'))}">${icon('x', 'sm')}</button>`;

    if (typeof options.undo === 'function') {
        const undo = options.undo;
        toast.querySelector('.ad-toast-undo')?.addEventListener('click', () => {
            toast.remove();
            undo();
        });
    }

    const box = container();
    follow(box);
    box.appendChild(toast);
    arm(toast, options.timeout ?? (type === 'error' ? 0 : 4000));
    watch();
    place();

    return toast;
};

/** Toasts the server rendered for the session's flash messages. */
export const initToasts = (): void => {
    document.querySelectorAll<HTMLElement>('#ad-toasts .ad-toast').forEach((toast) => {
        arm(toast, toast.classList.contains('is-error') ? 0 : 6000);
    });

    const box = $('#ad-toasts');

    if (box) {
        follow(box);

        if (box.childElementCount > 0) {
            watch();
            schedule();
        }
    }

    window.adminToast = adminToast;
};

declare global {
    interface Window {
        adminToast: typeof adminToast;
    }
}
