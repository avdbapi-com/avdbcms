// What the existing admin views (until each is rebuilt on the ad-* components) expect from the page:
// window.bootstrap with Modal, Tab, Collapse, Alert and Toast (and their data-bs-* attributes), Alpine,
// enhanced selects, Sortable for the category order, .ajax-button and the styled confirm() of
// admin/modals/confirm-action.blade.php. The shell adds this entry only on pages that use one of these. (The old
// Videos and Comments modals that drew public components are gone: the new Comments list shows the replies in its
// rows, the Videos list links each video's statistics.)
//
// Nothing here is shared with the public bundle (resources/js/app.ts), so the public pages keep their own
// chunks: Alpine comes from its self-starting build (it sets window.Alpine and starts on the next microtask)
// and Sortable from its core build (the public vendor chunk holds the other one).

import type {Alpine as AlpineType} from 'alpinejs';
import 'alpinejs/dist/cdn.min.js';
import Alert from 'bootstrap/js/src/alert.js';
import Collapse from 'bootstrap/js/src/collapse.js';
import Modal from 'bootstrap/js/src/modal.js';
import Tab from 'bootstrap/js/src/tab.js';
import Toast from 'bootstrap/js/src/toast.js';

type TomInput = HTMLInputElement | HTMLSelectElement;
type TomSelectConstructor = typeof import('tom-select').default;

declare global {
    interface Window {
        bootstrap: Record<string, unknown>;
        Alpine: AlpineType;
        Sortable?: unknown;
    }
}

// Tooltip and Popover are not loaded: modules/tooltip.ts shows [data-bs-toggle="tooltip"] titles.
window.bootstrap = {Alert, Collapse, Modal, Tab, Toast};

// Kept here (not imported from ../lib/dom) so this chunk does not depend on the admin.ts chunk.
const csrfToken = (): string => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

// ---------------------------------------------------------------- enhanced selects (lazy)
// Tom Select is loaded the first time a multi-select or a long list (> 20 options) is focused or pressed.
let tomSelectLoader: Promise<TomSelectConstructor> | null = null;

const loadTomSelect = (): Promise<TomSelectConstructor> => {
    tomSelectLoader ??= Promise.all([import('tom-select'), import('tom-select/dist/css/tom-select.bootstrap5.css')])
        .then(([module]) => module.default);
    tomSelectLoader.catch(() => {
        tomSelectLoader = null;
    });

    return tomSelectLoader;
};

const wantsTomSelect = (el: Element): el is HTMLSelectElement =>
    el instanceof HTMLSelectElement
    && !el.hasAttribute('data-native-select')
    && !(el as TomInput & {tomselect?: unknown}).tomselect
    && (el.classList.contains('select-multiple') || el.options.length > 20);

const enhanceSelect = (el: HTMLSelectElement, focus: boolean): void => {
    void loadTomSelect().then((TomSelect) => {
        if ((el as TomInput & {tomselect?: unknown}).tomselect) return;

        const multiple = el.classList.contains('select-multiple') || el.multiple;
        const control = new TomSelect(el, multiple
            ? {dropdownParent: 'body', plugins: {remove_button: {title: 'Remove this item'}}}
            : {allowEmptyOption: true, closeAfterSelect: true, create: false, dropdownParent: 'body', maxOptions: 500});

        if (focus) control.focus();
    });
};

const onSelectIntent = (event: Event): void => {
    const el = (event.target as Element | null)?.closest('select');

    if (el && wantsTomSelect(el)) {
        enhanceSelect(el, true);
    }
};

document.addEventListener('pointerdown', onSelectIntent, true);
document.addEventListener('focusin', onSelectIntent, true);

// ---------------------------------------------------------------- category order (Sortable)
// The same plugins as the default build (auto-scroll, revert/remove on spill).
if (document.getElementById('categories_list')) {
    void import('sortablejs/modular/sortable.core.esm.js').then(({default: Sortable, AutoScroll, OnSpill}) => {
        Sortable.mount(new AutoScroll());
        Sortable.mount(...OnSpill);
        window.Sortable = Sortable;
    });
}

// ---------------------------------------------------------------- .ajax-button
document.addEventListener('click', (event) => {
    const button = (event.target as Element | null)?.closest<HTMLElement>('.ajax-button');

    if (!button?.dataset.url) return;

    void fetch(button.dataset.url, {
        method: button.dataset.method ?? 'GET',
        credentials: 'include',
        headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken()},
        body: button.dataset.body ?? null,
    });
});

// ---------------------------------------------------------------- styled confirm()
// Same contract as the old inline script of admin/modals/confirm-action.blade.php: window.confirm() opens the
// #admin_confirm modal; "Confirm" re-runs the click or submit that asked. Alpine buttons that open the modal
// with an adminConfirm {title, warning, action, method} object build and submit the form.
const initLegacyConfirm = (): void => {
    const modalEl = document.getElementById('admin_confirm');

    if (!modalEl) return;

    const modal = Modal.getOrCreateInstance(modalEl) as {show: () => void; hide: () => void};
    const titleEl = document.getElementById('admin_confirm_title');
    const messageEl = document.getElementById('admin_confirm_message');
    const submitBtn = document.getElementById('admin_confirm_submit') as HTMLButtonElement | null;
    const labelEl = submitBtn?.querySelector('span');
    const defaults = {
        title: titleEl?.textContent ?? '',
        message: messageEl?.textContent ?? '',
        label: labelEl?.textContent ?? '',
    };

    let pendingForm: HTMLFormElement | null = null;
    let pendingClick: HTMLElement | null = null;
    let confirmed = false;
    let alpineSubmit: (() => void) | null = null;

    // "Delete all unused tags?" -> "Delete"; other questions keep "Confirm". Only questions with a word of
    // data-destructive ("delete", "clear", "permanently", ...) keep the red button and "This action cannot be undone."
    const verbs = (modalEl.dataset.verbs ?? '').split('|').filter(Boolean);
    const destructive = (modalEl.dataset.destructive ?? '').split('|').filter(Boolean).map((v) => v.toLowerCase());
    const symbol = modalEl.querySelector<HTMLElement>('.ad-sym');
    const firstWord = (message: string): string => message.trim().split(/\s+/)[0]?.replace(/[^\p{L}-]/gu, '').toLowerCase() ?? '';
    const labelFor = (message: string): string => {
        const first = firstWord(message);
        const verb = verbs.find((v) => v.split('=')[0].toLowerCase() === first);

        return verb ? verb.split('=')[1] : defaults.label;
    };
    // "deleted", "clears" count too; words of 4 letters or fewer ("ban", "drop") only as they are.
    const isDestructive = (message: string): boolean => destructive.length === 0
        || message.toLowerCase().split(/[^\p{L}]+/u).some((word) => destructive.some((v) => word === v || (v.length > 4 && word.startsWith(v))));
    const setTone = (message: string): void => {
        const danger = isDestructive(message);

        submitBtn?.classList.toggle('btn-danger', danger);
        submitBtn?.classList.toggle('btn-primary', !danger);
        symbol?.classList.toggle('is-danger', danger);
        symbol?.classList.toggle('is-info', !danger);
        if (messageEl) {
            messageEl.textContent = defaults.message;
            messageEl.hidden = !danger;
        }
    };

    window.confirm = (message?: string): boolean => {
        if (confirmed) {
            confirmed = false;
            return true;
        }

        const text = (message ?? '').trim() || defaults.message;
        if (titleEl) titleEl.textContent = text;
        if (labelEl) labelEl.textContent = labelFor(text);
        setTone(text);

        const active = document.activeElement as HTMLElement | null;
        pendingForm = null;
        pendingClick = null;

        if (active) {
            if (active.getAttribute('onclick') || (active.tagName === 'BUTTON' && active.getAttribute('type') === 'button') || active.tagName === 'A') {
                pendingClick = active;
            } else {
                pendingForm = active.closest('form') ?? (active as HTMLInputElement).form ?? null;
            }
        }

        alpineSubmit = null;
        modal.show();

        return false;
    };

    submitBtn?.addEventListener('click', () => {
        modal.hide();

        if (alpineSubmit) {
            const run = alpineSubmit;
            alpineSubmit = null;
            run();
        } else if (pendingForm) {
            const form = pendingForm;
            pendingForm = null;
            confirmed = true;
            const button = form.querySelector<HTMLElement>('button[type="submit"], input[type="submit"]');
            if (button) button.click();
            else form.submit();
        } else if (pendingClick) {
            const el = pendingClick;
            pendingClick = null;
            confirmed = true;
            el.click();
        }
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        pendingForm = null;
        pendingClick = null;
        confirmed = false;
        alpineSubmit = null;
        if (titleEl) titleEl.textContent = defaults.title;
        if (labelEl) labelEl.textContent = defaults.label;
        setTone(defaults.label);
    });

    // Bootstrap focuses the dialog box itself; the safe choice gets the focus instead.
    modalEl.addEventListener('shown.bs.modal', () => {
        modalEl.querySelector<HTMLElement>('[data-bs-dismiss="modal"]')?.focus();
    });

    modalEl.addEventListener('show.bs.modal', (event) => {
        const button = (event as Event & {relatedTarget?: Element}).relatedTarget;

        if (!button) return;

        try {
            const scope = window.Alpine.$data(button as HTMLElement) as {adminConfirm?: {title?: string; warning?: string; action?: string; method?: string}};
            const ac = scope?.adminConfirm;

            if (!ac?.action) return;

            setTone(ac.title || defaults.title);
            if (titleEl) titleEl.textContent = ac.title || defaults.title;
            if (messageEl) {
                messageEl.textContent = ac.warning || defaults.message;
                messageEl.hidden = false;
            }

            alpineSubmit = () => {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = ac.action as string;
                form.hidden = true;
                const add = (name: string, value: string): void => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value;
                    form.appendChild(input);
                };
                add('_token', csrfToken());
                if (ac.method && ac.method.toUpperCase() !== 'POST') add('_method', ac.method);
                document.body.appendChild(form);
                form.submit();
            };
        } catch {
            // No Alpine scope on this button.
        }
    });
};

initLegacyConfirm();
