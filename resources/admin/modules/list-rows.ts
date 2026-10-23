// Row menus and row actions of the list pages and Home.
//
// Row menus: a row's button [data-ad-row-menu="template-id"] (or a button.is-more in a table that names the template
// for all its rows: <table data-ad-row-menu="template-id">) controls a .ad-menu - an empty one next to it, or one made
// when it first opens; the first time it opens, the
// menu is filled from <template id="template-id">: __ID__-style placeholders take the button's data-* values (data-id,
// data-url ...), items with data-ad-when="public,private" stay only when the row's data-state is one of them (a leading
// "!" negates), items with data-ad-if="comments" only when the button has that data value. One template per page
// instead of a full menu per row keeps a 25-row list small. (WP6's shell.ts then opens it as any [data-ad-menu].)
//
// Row actions: forms with data-ad-row-action, or a submit button inside a row that names such a form (form="...",
// with its own formaction). WP6's confirm dialog asks first (data-ad-confirm on the form or the button); then:
//   data-ad-row-action="delete"  the row disappears and the toast offers Undo for 8 seconds; the request is sent when
//                                that time is over or the page is left (nothing is sent after Undo).
//   data-ad-row-action="change"  sent at once; the toast offers Undo when data-ad-undo-url says how to put it back
//                                (data-ad-undo-method, data-ad-undo-fields = JSON of the fields to send).
// data-ad-done = the toast text. The button's data-ad-* values win over the form's. After a change the page's lists
// ([data-ad-list]) are fetched again and swapped in. Without this script the forms submit as they always did.
//
// Every action is checked before the toast says it worked: the action's answer must not be an error, and when the row
// is still listed afterwards, its data-state must match data-ad-expect ("admin", "!admin", "public,unlisted"); an Undo
// must bring the row's state back, and a deleted row must be gone.

import {$$, csrfToken, escapeHtml, t} from '../lib/dom';

const HOLD_MS = 8000;
const held = new Map<HTMLElement, () => void>();

type Outcome = {ok: boolean; message?: string; page?: Document};

const sameAsHere = (url: string): boolean => {
    try {
        const there = new URL(url);
        const here = new URL(window.location.href);
        there.hash = '';
        here.hash = '';

        return there.href === here.href;
    } catch {
        return false;
    }
};

/**
 * Send a row action. The actions answer with a redirect to an admin page that shows the outcome as a flash message
 * (which this request uses up): an error callout there (.ad-flash) means the action did not happen, and so does any page
 * that is not an admin page (the sign-in page after the session ended). When that page is this page, the lists are
 * swapped from it instead of asking for it again.
 */
const send = async (url: string, body: FormData, keepalive = false): Promise<Outcome> => {
    let response: Response;

    try {
        response = await fetch(url, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            keepalive,
            headers: {Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest'},
        });
    } catch {
        return {ok: false};
    }

    if (!response.ok) return {ok: false};
    if (keepalive || !(response.headers.get('content-type') ?? '').includes('text/html')) return {ok: true};

    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
    const error = page.querySelector('.ad-flash');

    if (error) {
        return {ok: false, message: (error.querySelector('h3') ?? error).textContent?.trim() || undefined};
    }

    if (!page.documentElement.hasAttribute('data-admin-ui')) return {ok: false};

    return {ok: true, page: sameAsHere(response.url) ? page : undefined};
};

const fields = (json: string | undefined, method: string): FormData => {
    const body = new FormData();
    body.append('_token', csrfToken());

    if (method !== 'POST') body.append('_method', method);

    try {
        // {"ids": ["7"]} is sent as ids[]=7 (a list), {"action": "x"} as action=x.
        Object.entries(JSON.parse(json || '{}') as Record<string, unknown>).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach((one) => body.append(`${key}[]`, String(one)));
            } else {
                body.append(key, String(value));
            }
        });
    } catch {
        // No extra fields.
    }

    return body;
};

/**
 * The page's icon sprite holds only the icons its first answer used: copy the symbols a fetched copy of the page uses
 * that are not here yet.
 */
export const adoptSymbols = (fresh: Document): void => {
    const sprite = document.querySelector('svg.ad-sprite');

    fresh.querySelectorAll('svg.ad-sprite symbol[id]').forEach((symbol) => {
        if (sprite && !document.getElementById(symbol.id)) {
            sprite.appendChild(document.importNode(symbol, true));
        }
    });
};

const ROW_MENU_BUTTON = 'button[data-ad-row-menu], table[data-ad-row-menu] button.is-more';

/**
 * A long list's row buttons may leave out aria-haspopup (it costs 20 bytes a row): it is set here, when the script
 * that makes the button open its menu runs (without the script the button opens nothing).
 */
const markMenuButtons = (): void => {
    $$<HTMLElement>('table[data-ad-row-menu] button.is-more:not([aria-haspopup])').forEach((button) => button.setAttribute('aria-haspopup', 'true'));
};

/**
 * Swap in the page's lists (rows, counts, pager) from a fresh copy of this page - the one given, or fetched now -,
 * keeping the scroll position.
 */
export const refreshLists = async (fresh?: Document): Promise<void> => {
    const lists = $$<HTMLElement>('[data-ad-list]');

    if (lists.length === 0) return;

    let page = fresh;

    if (!page) {
        try {
            const response = await fetch(window.location.href, {credentials: 'same-origin', headers: {Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest'}});

            if (!response.ok) return;

            page = new DOMParser().parseFromString(await response.text(), 'text/html');
        } catch {
            // The list stays as it is; the next page load shows the change.
            return;
        }
    }

    adoptSymbols(page);

    lists.forEach((list) => {
        const next = Array.from(page.querySelectorAll<HTMLElement>('[data-ad-list]')).find((el) => el.dataset.adList === list.dataset.adList);

        if (next) list.replaceWith(document.importNode(next, true));
    });

    markMenuButtons();
};

const toast = (text: string, options: {type?: 'ok' | 'error'; undo?: () => void; timeout?: number} = {}): void => {
    window.adminToast?.(text, options);
};

// The words come from the page (data-ad-failed on the table or list), in the admin's language; the server's own
// reason (an error message of the action) comes first when there is one.
const failed = (source: Element, message?: string): void =>
    toast(message || source.closest<HTMLElement>('[data-ad-failed]')?.dataset.adFailed || document.querySelector<HTMLElement>('[data-ad-failed]')?.dataset.adFailed || 'That did not work. Reload the page and try again.', {type: 'error'});

const focusNear = (row: HTMLElement): void => {
    const next = (row.nextElementSibling ?? row.previousElementSibling) as HTMLElement | null;
    const target = next?.querySelector<HTMLElement>('a[href], button:not([disabled]), input:not([type="hidden"])');

    target?.focus();
};

/** The template of a row's menu: the button's own, or its table's. */
const templateOf = (button: HTMLElement): string =>
    button.dataset.adRowMenu ?? button.closest<HTMLElement>('table[data-ad-row-menu]')?.dataset.adRowMenu ?? '';

/** A row's menu button: which template, which record, which state (read before the lists are swapped). */
type RowKey = {template: string; id: string; state: string};

const rowKeyOf = (source: HTMLElement): RowKey | null => {
    const menu = source.closest<HTMLElement>('.ad-menu');
    const button = menu?.id ? document.querySelector<HTMLElement>(`[aria-controls="${CSS.escape(menu.id)}"]`) : null;

    const template = button ? templateOf(button) : '';

    if (!button || !template || !button.dataset.id) return null;

    return {template, id: button.dataset.id, state: button.dataset.state ?? ''};
};

const rowButton = (key: RowKey): HTMLElement | null =>
    document.querySelector<HTMLElement>(`button[data-ad-row-menu="${CSS.escape(key.template)}"][data-id="${CSS.escape(key.id)}"], `
        + `table[data-ad-row-menu="${CSS.escape(key.template)}"] button.is-more[data-id="${CSS.escape(key.id)}"]`);

const stateMatches = (rule: string, state: string): boolean => {
    const negate = rule.startsWith('!');

    return rule.replace(/^!/, '').split(',').includes(state) !== negate;
};

/** After the lists were swapped: is the row (still listed) in the state the action promised? */
const rowIs = (key: RowKey | null, rule: string | undefined): boolean => {
    const button = key && rule ? rowButton(key) : null;

    return !button || stateMatches(rule as string, button.dataset.state ?? '');
};

type RowAction = {url: string; body: FormData; source: HTMLElement; row: HTMLElement | null; key: RowKey | null; option: (name: string) => string | undefined};

const holdDelete = (action: RowAction): void => {
    const {row, key} = action;
    let done = false;

    const commit = (keepalive = false): void => {
        if (done) return;
        done = true;
        held.delete(action.source);
        void send(action.url, action.body, keepalive).then(async (outcome) => {
            if (keepalive) return;

            if (!outcome.ok) {
                if (row) row.hidden = false;
                failed(action.source, outcome.message);

                return;
            }

            await refreshLists(outcome.page);

            // Still listed after the delete: it did not happen.
            if (key && rowButton(key)) failed(action.source);
        });
    };

    const undo = (): void => {
        if (done) return;
        done = true;
        held.delete(action.source);
        window.clearTimeout(timer);
        if (row) {
            row.hidden = false;
            row.querySelector<HTMLElement>('[data-ad-menu]')?.focus();
        }
    };

    if (row) {
        focusNear(row);
        row.hidden = true;
    }

    const timer = window.setTimeout(() => commit(), HOLD_MS);
    held.set(action.source, () => {
        window.clearTimeout(timer);
        commit(true);
    });
    toast(action.option('adDone') || t('undo', 'Undo'), {undo, timeout: HOLD_MS});
};

const change = async (action: RowAction): Promise<void> => {
    const outcome = await send(action.url, action.body);

    if (!outcome.ok) {
        failed(action.source, outcome.message);

        return;
    }

    await refreshLists(outcome.page);

    if (!rowIs(action.key, action.option('adExpect'))) {
        failed(action.source);

        return;
    }

    const undoUrl = action.option('adUndoUrl');
    const method = (action.option('adUndoMethod') || 'POST').toUpperCase();

    toast(action.option('adDone') || '', {
        undo: undoUrl
            ? () => {
                void send(undoUrl, fields(action.option('adUndoFields'), method)).then(async (undone) => {
                    if (!undone.ok) {
                        failed(action.source, undone.message);

                        return;
                    }

                    await refreshLists(undone.page);

                    // The row is back in the state it had before the action.
                    if (action.key && !rowIs(action.key, action.key.state)) failed(action.source);
                });
            }
            : undefined,
    });
};

/**
 * Fill a row's menu from its template the first time it opens. A row may carry only its button: the menu box is made
 * here, next to it (the cell is the box's anchor), and the button gets data-ad-menu + aria-controls for shell.ts.
 */
const fillMenu = (button: HTMLElement): void => {
    const template = document.getElementById(templateOf(button));
    let menu = document.getElementById(button.getAttribute('aria-controls') ?? '');

    if (!(template instanceof HTMLTemplateElement)) return;

    if (!menu) {
        menu = document.createElement('div');
        menu.className = 'ad-menu';
        menu.id = `${template.id}-${(button.dataset.id ?? Math.random().toString(36).slice(2)).replace(/[^\w-]/g, '_')}`;
        menu.hidden = true;
        button.after(menu);
        button.setAttribute('aria-controls', menu.id);
        button.setAttribute('data-ad-menu', '');
    }

    if (menu.childElementCount > 0) return;

    const data = button.dataset;
    menu.innerHTML = template.innerHTML.replace(/__([A-Z]+)__/g, (_, key: string) => escapeHtml(data[key.toLowerCase()] ?? ''));

    $$<HTMLElement>('[data-ad-when]', menu).forEach((item) => {
        if (!stateMatches(item.dataset.adWhen ?? '', data.state ?? '')) item.remove();
    });

    $$<HTMLElement>('[data-ad-if]', menu).forEach((item) => {
        if (!data[item.dataset.adIf ?? '']) item.remove();
    });

    // No two separators in a row, none at the edges.
    $$<HTMLElement>('hr', menu).forEach((hr) => {
        const previous = hr.previousElementSibling;
        if (!previous || previous.tagName === 'HR' || !hr.nextElementSibling) hr.remove();
    });
};

export const initRows = (): void => {
    markMenuButtons();

    // Capture phase: the menu is filled before WP6's shell.ts opens it and moves the focus into it.
    document.addEventListener('click', (event) => {
        const button = (event.target as Element | null)?.closest<HTMLElement>(ROW_MENU_BUTTON);

        if (button) fillMenu(button);
    }, true);

    // Bubble phase: WP6's confirm dialog (capture phase) has already stopped the submit when it still asks.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        const submitter = (event as SubmitEvent).submitter as HTMLButtonElement | HTMLInputElement | null;

        if (!(form instanceof HTMLFormElement) || !form.dataset.adRowAction || event.defaultPrevented) return;

        event.preventDefault();

        const source: HTMLElement = submitter ?? form;
        const key = rowKeyOf(source);
        const menu = source.closest<HTMLElement>('.ad-menu');

        if (menu && !menu.hidden) {
            menu.hidden = true;
            document.querySelector(`[aria-controls="${CSS.escape(menu.id)}"]`)?.setAttribute('aria-expanded', 'false');
        }

        const body = new FormData(form, submitter ?? undefined);

        // The row actions' shared forms carry no token of their own (they only work through this script).
        if (!body.has('_token')) body.append('_token', csrfToken());

        const action: RowAction = {
            // The form's own attribute, not form.action: a field named "action" in the form would stand in its place.
            url: submitter?.hasAttribute('formaction') ? submitter.formAction : new URL(form.getAttribute('action') ?? '', window.location.href).href,
            body,
            source,
            row: source.closest<HTMLElement>('tr, [data-ad-row]') ?? form.closest<HTMLElement>('tr, [data-ad-row]'),
            key,
            option: (name) => submitter?.dataset[name] ?? form.dataset[name],
        };

        // A row menu sits outside its row's table cell: the row is the one of the menu's button.
        if (!action.row && key) action.row = rowButton(key)?.closest<HTMLElement>('tr, [data-ad-row]') ?? null;

        if (form.dataset.adRowAction === 'delete') {
            holdDelete(action);
        } else {
            void change(action);
        }
    });

    // Leaving the page sends the deletes that are still waiting for their Undo time.
    window.addEventListener('pagehide', () => {
        [...held.values()].forEach((flush) => flush());
    });
};
