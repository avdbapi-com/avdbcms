// Save bar (<x-admin.savebar>): a form marked data-ad-form saves only with "Save changes" (or Ctrl/Cmd+S).
// The bar appears when a field differs from what the page loaded with, counts the changed fields, marks
// their rows (.ad-field.is-changed), Discard puts everything back, and leaving with unsaved changes asks
// first. Pages that save with fetch listen for the cancelable 'ad:save' event on the form.

import {$, $$, t} from '../lib/dom';

type Snapshot = Map<string, string>;

const fieldKey = (el: HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement, index: number): string =>
    `${el.name || el.id || 'field'}#${index}`;

const valueOf = (el: HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement): string => {
    if (el instanceof HTMLInputElement && (el.type === 'checkbox' || el.type === 'radio')) {
        return el.checked ? '1' : '0';
    }
    if (el instanceof HTMLSelectElement && el.multiple) {
        return Array.from(el.selectedOptions).map((o) => o.value).join('\u0000');
    }
    if (el instanceof HTMLInputElement && el.type === 'file') {
        return el.files?.length ? 'file:' + el.files.length : '';
    }

    return el.value;
};

const fields = (form: HTMLFormElement) =>
    Array.from(form.elements).filter(
        (el): el is HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement =>
            (el instanceof HTMLInputElement || el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement)
            && el.type !== 'hidden' && el.type !== 'submit' && el.type !== 'button' && !el.closest('[data-ad-ignore]'),
    );

const snapshot = (form: HTMLFormElement): Snapshot => {
    const map: Snapshot = new Map();
    fields(form).forEach((el, i) => map.set(fieldKey(el, i), valueOf(el)));

    return map;
};

const attach = (form: HTMLFormElement): void => {
    const bar = $('[data-ad-savebar]', form) ?? (form.id ? $(`[data-ad-savebar="${CSS.escape(form.id)}"]`) : null);
    let initial = snapshot(form);
    let submitting = false;
    let changedCount = 0;

    const update = (): void => {
        const changedRows = new Set<Element>();
        changedCount = 0;
        const changedNames = new Set<string>();

        fields(form).forEach((el, i) => {
            if (initial.get(fieldKey(el, i)) !== valueOf(el)) {
                changedNames.add(el.name || el.id);
                const row = el.closest('.ad-field, .ad-toggle-row');
                if (row) changedRows.add(row);
            }
        });

        changedCount = changedNames.size;
        $$('.ad-field.is-changed, .ad-toggle-row.is-changed', form).forEach((row) => {
            if (!changedRows.has(row)) row.classList.remove('is-changed');
        });
        changedRows.forEach((row) => row.classList.add('is-changed'));

        if (bar) {
            bar.hidden = changedCount === 0;
            const label = $('[data-ad-changes]', bar);
            if (label) {
                label.textContent = changedCount === 1
                    ? t('oneChange', '1 unsaved change')
                    : t('manyChanges', ':count unsaved changes').replace(':count', String(changedCount));
            }
        }

        form.dispatchEvent(new CustomEvent(changedCount ? 'ad:dirty' : 'ad:clean', {detail: {count: changedCount, names: [...changedNames]}}));
    };

    form.addEventListener('input', update);
    form.addEventListener('change', update);

    form.addEventListener('reset', () => window.setTimeout(update, 0));

    form.addEventListener('submit', (event) => {
        const save = new CustomEvent('ad:save', {cancelable: true, detail: {changed: changedCount}});

        if (!form.dispatchEvent(save)) {
            // The page saves with fetch; it calls form.adMarkSaved() when done.
            event.preventDefault();
            return;
        }

        submitting = true;
        $$<HTMLButtonElement>('[data-ad-save]', bar ?? form).forEach((button) => {
            button.classList.add('is-busy');
            button.textContent = t('saving', 'Saving...');
        });
    });

    (form as HTMLFormElement & {adMarkSaved?: () => void}).adMarkSaved = () => {
        initial = snapshot(form);
        update();
    };

    bar?.querySelector('[data-ad-discard]')?.addEventListener('click', () => {
        form.reset();
        window.setTimeout(update, 0);
    });

    bar?.querySelector('[data-ad-save]')?.addEventListener('click', () => form.requestSubmit());

    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 's') {
            event.preventDefault();
            if (changedCount > 0 && !submitting) form.requestSubmit();
        }
    });

    window.addEventListener('beforeunload', (event) => {
        if (changedCount > 0 && !submitting) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    update();
};

export const initSavebar = (): void => {
    $$<HTMLFormElement>('form[data-ad-form]').forEach(attach);
};
