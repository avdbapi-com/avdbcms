// Toasts: bottom-right (bottom on phones), success disappears after 4 s, errors stay until closed.
// window.adminToast(text, {type, undo}) - undo: a URL to open or a function to call (shown as "Undo").

import {$, escapeHtml, icon, t} from '../lib/dom';

export type ToastOptions = {
    type?: 'ok' | 'error' | 'info';
    undo?: string | (() => void);
    undoLabel?: string;
    timeout?: number;
};

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

export const adminToast = (text: string, options: ToastOptions = {}): HTMLElement => {
    const type = options.type ?? 'ok';
    const toast = document.createElement('div');
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

    container().appendChild(toast);
    arm(toast, options.timeout ?? (type === 'error' ? 0 : 4000));

    return toast;
};

/** Toasts the server rendered for the session's flash messages. */
export const initToasts = (): void => {
    document.querySelectorAll<HTMLElement>('#ad-toasts .ad-toast').forEach((toast) => {
        arm(toast, toast.classList.contains('is-error') ? 0 : 6000);
    });

    window.adminToast = adminToast;
};

declare global {
    interface Window {
        adminToast: typeof adminToast;
    }
}
