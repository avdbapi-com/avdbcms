// Confirm dialog (<x-admin.confirm>, a native <dialog>): the title is the question, the body says what happens,
// Cancel has the focus, the action button is named after the action. Optional type-to-confirm for wipes.
//
// window.adminConfirm({title, body, confirm, tone, typeToConfirm}): Promise<boolean>
// Declarative: data-ad-confirm="Delete 3 videos?" on a form (submit) or a button/link (click), with optional
// data-ad-confirm-body, data-ad-confirm-label, data-ad-confirm-type (the word to type) and
// data-ad-confirm-tone="primary" (default danger).

import {$, t} from '../lib/dom';

export type ConfirmOptions = {
    title: string;
    body?: string;
    confirm?: string;
    cancel?: string;
    tone?: 'danger' | 'primary';
    typeToConfirm?: string;
};

let pending: ((value: boolean) => void) | null = null;

const dialogEl = (): HTMLDialogElement | null => $<HTMLDialogElement>('#ad-confirm');

export const adminConfirm = (options: ConfirmOptions): Promise<boolean> => {
    const dialog = dialogEl();

    if (!dialog || typeof dialog.showModal !== 'function') {
        return Promise.resolve(window.confirm(options.body ? `${options.title}\n\n${options.body}` : options.title));
    }

    pending?.(false);

    const title = $('[data-ad-confirm-title]', dialog);
    const body = $('[data-ad-confirm-body]', dialog);
    const accept = $<HTMLButtonElement>('[data-ad-confirm-accept]', dialog);
    const cancel = $<HTMLButtonElement>('[data-ad-confirm-cancel]', dialog);
    const typeBox = $('[data-ad-confirm-typebox]', dialog);
    const typeInput = $<HTMLInputElement>('[data-ad-confirm-input]', dialog);
    const typeWord = $('[data-ad-confirm-word]', dialog);

    if (title) title.textContent = options.title;
    if (body) {
        body.textContent = options.body ?? '';
        body.hidden = !options.body;
    }
    if (cancel) cancel.textContent = options.cancel ?? t('cancel', 'Cancel');
    if (accept) {
        accept.textContent = options.confirm ?? t('confirm', 'Confirm');
        accept.className = 'ad-btn ' + (options.tone === 'primary' ? 'is-primary' : 'is-danger-solid');
        accept.disabled = Boolean(options.typeToConfirm);
    }

    // A question that is not destructive (tone primary) gets the info symbol, not the red warning triangle.
    const symbol = $('.ad-sym', dialog);
    if (symbol) {
        symbol.className = 'ad-sym ' + (options.tone === 'primary' ? 'is-accent' : 'is-danger');
        symbol.querySelector('use')?.setAttribute('href', options.tone === 'primary' ? '#i-info' : '#i-alert');
    }
    if (typeBox && typeInput && typeWord) {
        typeBox.hidden = !options.typeToConfirm;
        typeWord.textContent = options.typeToConfirm ?? '';
        typeInput.value = '';
        typeInput.oninput = () => {
            if (accept) accept.disabled = typeInput.value.trim() !== (options.typeToConfirm ?? '');
        };
    }

    return new Promise<boolean>((resolve) => {
        pending = resolve;
        const finish = (value: boolean): void => {
            if (pending === resolve) {
                pending = null;
                resolve(value);
            }
            if (dialog.open) dialog.close();
        };

        accept!.onclick = () => finish(true);
        cancel!.onclick = () => finish(false);
        dialog.onclose = () => finish(false);
        dialog.onclick = (event) => {
            if (event.target === dialog) finish(false);
        };

        dialog.showModal();
        (options.typeToConfirm ? typeInput : cancel)?.focus();
    });
};

const optionsFrom = (el: HTMLElement): ConfirmOptions => ({
    title: el.dataset.adConfirm || t('confirmTitle', 'Are you sure you want to continue?'),
    body: el.dataset.adConfirmBody,
    confirm: el.dataset.adConfirmLabel,
    tone: el.dataset.adConfirmTone === 'primary' ? 'primary' : 'danger',
    typeToConfirm: el.dataset.adConfirmType,
});

const confirmed = new WeakSet<HTMLElement>();

export const initConfirm = (): void => {
    window.adminConfirm = adminConfirm;

    document.addEventListener('submit', (event) => {
        const form = event.target as HTMLFormElement;

        if (!(form instanceof HTMLFormElement)) return;

        const submitter = (event as SubmitEvent).submitter as HTMLElement | null;
        const source = form.hasAttribute('data-ad-confirm') ? form : submitter?.hasAttribute('data-ad-confirm') ? submitter : null;

        if (!source) return;
        if (confirmed.has(form)) {
            confirmed.delete(form);
            return;
        }

        event.preventDefault();

        adminConfirm(optionsFrom(source)).then((ok) => {
            if (!ok) return;
            confirmed.add(form);
            form.requestSubmit(submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement ? submitter : undefined);
        });
    }, true);

    document.addEventListener('click', (event) => {
        const el = (event.target as Element | null)?.closest<HTMLElement>('a[data-ad-confirm], button[data-ad-confirm]:not([type="submit"])');

        if (!el) return;
        if (confirmed.has(el)) {
            confirmed.delete(el);
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        adminConfirm(optionsFrom(el)).then((ok) => {
            if (!ok) return;
            confirmed.add(el);
            el.click();
        });
    }, true);
};

declare global {
    interface Window {
        adminConfirm: typeof adminConfirm;
    }
}
