// The Appearance pages (resources/views/admin/appearance/*): every change becomes this admin's draft
// (POST /admin/appearance/draft), the preview on the right shows it on the real site through a signed link, and
// Publish makes it live (POST /admin/appearance/publish) with Undo in the toast and in History.
// Loaded by admin.ts only on pages with [data-ad-appearance]; one delegated listener per event type.

import {$, $$, csrfToken, escapeHtml} from '../lib/dom';
import './appearance.scss';

type Change = {key: string; label: string; page: string | null; page_label: string; value: string | null};
type Action = {type: 'look' | 'theme'; id: string; flavor?: string; layouts?: boolean; keys: string[]} | null;
type DraftState = {
    token: string | null;
    expires_at: string | null;
    count: number;
    changes: Change[];
    values: Record<string, string | null>;
    action: Action;
    action_label: string | null;
};
type Boot = {
    page: string;
    published: Record<string, string>;
    draft: DraftState;
    urls: Record<string, string>;
    texts: Record<string, string>;
};
type Edits = {set: Record<string, string>; unset: string[]; reset: string[]; look?: string; theme?: {id: string; flavor: string | null; layouts: boolean}; cancel?: boolean};
type Flavor = {id: string; name: string; colours: string[]; formerly: string | null};

const root = $<HTMLElement>('[data-ad-appearance]');
const form = $<HTMLFormElement>('[data-ad-appearance-form]');
const bootEl = document.getElementById('ad-appearance-state');
const TOAST_KEY = 'ad-appearance-toast';

if (root && form && bootEl) {
    const boot = JSON.parse(bootEl.textContent || '{}') as Boot;
    const text = (key: string, fallback = ''): string => boot.texts[key] ?? fallback;
    let state: DraftState = boot.draft;
    const published: Record<string, string> = {...boot.published};
    let pending: Edits = {set: {}, unset: [], reset: []};
    let timer = 0;
    let busy: Promise<void> | null = null;
    let showLiveAfter = false;

    // ---------- requests

    const send = async (url: string, method: string, body?: object): Promise<{status: number; data: any}> => {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        let data: any = {};
        try {
            data = await response.json();
        } catch {
            data = {};
        }

        return {status: response.status, data};
    };

    const toast = (message: string, type: 'ok' | 'error' | 'info' = 'ok', undo?: string): void => {
        if (window.adminToast) window.adminToast(message, undo ? {type, undo: () => void restore(undo)} : {type});
    };

    const confirmed = async (title: string, body: string, confirm: string): Promise<boolean> =>
        window.adminConfirm ? window.adminConfirm({title, body, confirm, tone: 'primary'}) : window.confirm(title);

    // ---------- fields

    const rowOf = (el: Element | null): HTMLElement | null => el?.closest<HTMLElement>('[data-key]') ?? null;

    const rowFor = (key: string): HTMLElement | null => form.querySelector<HTMLElement>(`[data-key="${CSS.escape(key)}"]`);

    const readValue = (row: HTMLElement): string => {
        const toggle = $<HTMLInputElement>('input[type="checkbox"][name^="groups["]', row);
        if (toggle) return toggle.checked ? '1' : '0';

        const radios = $$<HTMLInputElement>('input[type="radio"][name^="groups["]', row);
        if (radios.length) return radios.find((radio) => radio.checked)?.value ?? '';

        const control = $<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>('[name^="groups["]', row);

        return control ? control.value : '';
    };

    const writeValue = (row: HTMLElement, value: string): void => {
        const toggle = $<HTMLInputElement>('input[type="checkbox"][name^="groups["]', row);

        if (toggle) {
            toggle.checked = value === '1';
        } else {
            const radios = $$<HTMLInputElement>('input[type="radio"][name^="groups["]', row);

            if (radios.length) {
                radios.forEach((radio) => {
                    radio.checked = radio.value === value;
                });
            } else {
                const control = $<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>('[name^="groups["]', row);
                if (control) control.value = value;
                // The colour field's swatch follows its text box.
                const swatch = $<HTMLInputElement>('[data-ad-color-swatch]', row);
                if (swatch && /^#[0-9a-f]{6}$/i.test(value)) swatch.value = value.toLowerCase();
            }
        }
    };

    const markRows = (): void => {
        $$<HTMLElement>('[data-key]', form).forEach((row) => {
            const key = row.dataset.key ?? '';
            const changed = Object.prototype.hasOwnProperty.call(state.values, key);
            row.classList.toggle('is-changed', changed);
            let meta = $<HTMLElement>(':scope > [data-ad-changed]', row);

            if (changed && !meta) {
                meta = document.createElement('div');
                meta.className = 'ad-field-meta ad-changed-meta';
                meta.dataset.adChanged = '';
                meta.innerHTML = `<span>${escapeHtml(text('changed', 'Not published yet'))}</span> <button type="button" class="ad-link" data-ad-field-undo>${escapeHtml(text('undo', 'Undo'))}</button>`;
                row.append(meta);
            } else if (!changed && meta) {
                meta.remove();
            }
        });
    };

    const showErrors = (errors: Record<string, string[]>): void => {
        $$('[data-ad-error]', form).forEach((el) => el.remove());
        $$('.is-error', form).forEach((el) => el.classList.remove('is-error'));

        Object.entries(errors || {}).forEach(([name, messages]) => {
            const key = name.includes('.') ? name.slice(name.indexOf('.') + 1) : name;
            const row = rowFor(key);
            if (!row) return;
            row.classList.add('is-error');
            const error = document.createElement('div');
            error.className = 'ad-error';
            error.setAttribute('role', 'alert');
            error.dataset.adError = '';
            error.textContent = messages[0] ?? '';
            row.append(error);
        });
    };

    // ---------- publish bar and preview

    const publishBar = (): void => {
        const title = $('[data-ad-pub-title]');
        const detail = $('[data-ad-pub-detail]');
        const discard = $<HTMLButtonElement>('[data-ad-discard]');
        const publish = $<HTMLButtonElement>('[data-ad-publish]');
        const count = state.count;

        if (title) {
            title.textContent = state.action_label
                ? text('previewing', 'Previewing :name').replace(':name', state.action_label)
                : count === 0
                    ? text('noChanges', 'No changes')
                    : (count === 1 ? text('oneChange', '1 change not published') : text('manyChanges', ':count changes not published').replace(':count', String(count)));
        }

        if (detail) {
            const labels = Array.from(new Set(state.changes.map((change) => change.label))).slice(0, 4);
            const action = state.action;
            detail.textContent = count === 0
                ? text('allPublished', 'Everything is published.')
                : action
                    ? text(action.type === 'look' ? 'lookNote' : (action.layouts ? 'themeLayoutsNote' : 'themeNote'))
                    : (labels.length ? labels.join(', ') : text('notPublished'));
        }

        if (discard) discard.hidden = count === 0;
        if (publish) publish.disabled = count === 0;
        // Narrow screens keep the bar at the bottom of the window while something is unpublished.
        root.classList.toggle('has-changes', count > 0);
    };

    const monitor = (): HTMLElement | null => $<HTMLElement>('[data-ad-monitor]');

    const withToken = (url: string): string => {
        const target = new URL(url, location.origin);
        target.searchParams.delete('avd_draft');
        if (state.token) target.searchParams.set('avd_draft', state.token);

        return target.pathname + target.search + target.hash;
    };

    const retarget = (show: boolean): void => {
        const screen = monitor();
        if (!screen) return;

        $$<HTMLOptionElement>('[data-ad-monitor-page] option', screen).forEach((option) => {
            option.value = withToken(option.value);
        });
        if (screen.dataset.src) screen.dataset.src = withToken(screen.dataset.src);

        const open = $<HTMLAnchorElement>('[data-ad-monitor-open]', screen);
        if (open) open.href = withToken(open.getAttribute('href') || '/');

        if (show || $('iframe', screen)) screen.dispatchEvent(new CustomEvent('ad:monitor-refresh', {bubbles: true}));
    };

    const apply = (data: any): void => {
        if (data && typeof data === 'object' && 'count' in data) {
            state = {
                token: data.token ?? null,
                expires_at: data.expires_at ?? null,
                count: data.count ?? 0,
                changes: data.changes ?? [],
                values: data.values ?? {},
                action: data.action ?? null,
                action_label: data.action_label ?? null,
            };
        }

        publishBar();
        markRows();
        showErrors(data?.errors ?? {});

        if (typeof data?.speed_html === 'string') {
            const card = $('[data-ad-speed]');
            const holder = document.createElement('div');
            holder.innerHTML = data.speed_html;
            const fresh = holder.firstElementChild;
            if (card && fresh) card.replaceWith(fresh);
        }

        if (typeof data?.level === 'string') {
            $$<HTMLInputElement>('input[name="ad_effects_level"]', form).forEach((radio) => {
                radio.checked = radio.value === data.level;
            });
            const custom = $('[data-ad-level-custom]', form);
            if (custom) custom.hidden = data.level !== 'custom';
        }

        if (data?.shown) {
            Object.entries(data.shown as Record<string, string>).forEach(([key, value]) => {
                const row = rowFor(key);
                if (row) writeValue(row, value);
            });
        }

        if (typeof data?.colour_note_html === 'string') {
            const note = $('[data-ad-colour-note]');
            const holder = document.createElement('div');
            holder.innerHTML = data.colour_note_html;
            const fresh = holder.firstElementChild;
            if (note && fresh) note.replaceWith(fresh);
        }

        syncThemeUi();
        retarget(showLiveAfter);
        showLiveAfter = false;
    };

    // ---------- draft

    const flush = async (): Promise<void> => {
        window.clearTimeout(timer);
        const edits = pending;
        pending = {set: {}, unset: [], reset: []};

        if (busy) await busy;

        busy = (async () => {
            try {
                const {data} = await send(boot.urls.draft, 'POST', {...edits, page: boot.page});
                apply(data);
            } catch {
                toast(text('offline', 'Could not reach your site. Your changes are still here.'), 'error');
            }
        })();

        await busy;
        busy = null;
    };

    const queue = (delay: number): void => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => void flush(), delay);
    };

    const changed = (row: HTMLElement, delay: number): void => {
        const key = row.dataset.key ?? '';
        if (!key) return;
        const value = readValue(row);

        delete pending.set[key];
        pending.unset = pending.unset.filter((item) => item !== key);
        pending.reset = pending.reset.filter((item) => item !== key);

        if (Object.prototype.hasOwnProperty.call(published, key) && published[key] === value) {
            pending.unset.push(key);
        } else {
            pending.set[key] = value;
        }

        if (row.hasAttribute('data-picker')) showLiveAfter = true;
        queue(delay);
    };

    form.addEventListener('change', (event) => {
        const target = event.target as HTMLElement;
        if (target.closest('[data-ad-ignore]') || target.hasAttribute('data-ad-ignore')) return;

        if (target.matches('input[name="ad_effects_level"]')) {
            const writes = JSON.parse((target as HTMLInputElement).dataset.adLevel || '{}') as Record<string, string>;
            Object.entries(writes).forEach(([key, value]) => {
                const row = rowFor(key);
                if (row) writeValue(row, value);
                pending.set[key] = value;
                pending.unset = pending.unset.filter((item) => item !== key);
            });
            queue(0);

            return;
        }

        if (target.matches('[data-upload-file]')) {
            void upload(target as HTMLInputElement);

            return;
        }

        const row = rowOf(target);
        if (row) changed(row, 150);
    });

    form.addEventListener('input', (event) => {
        const target = event.target as HTMLElement;
        if (target.hasAttribute('data-ad-ignore') || !(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement)) return;
        if (['checkbox', 'radio', 'file'].includes((target as HTMLInputElement).type)) return;

        const row = rowOf(target);
        if (!row) return;
        // Colours are only sent once they are a complete #rrggbb.
        if (target.hasAttribute('data-ad-color-hex') && !/^#[0-9a-f]{6}$/i.test(target.value.trim())) return;
        changed(row, 700);
    });

    const upload = async (input: HTMLInputElement): Promise<void> => {
        const row = rowOf(input);
        const file = input.files?.[0];
        if (!row || !file) return;

        const body = new FormData();
        body.append('key', row.dataset.key ?? '');
        body.append('file', file);
        const response = await fetch(boot.urls.upload, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest'},
            body,
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok || !data.ok) {
            showErrors({[row.dataset.key ?? '']: [data.message ?? text('failed')]});

            return;
        }

        const field = $<HTMLInputElement>('input[type="text"][name^="groups["]', row);
        if (field) field.value = data.value;
        const preview = $<HTMLImageElement>('[data-upload-preview]', row);
        if (preview) {
            preview.src = data.preview;
            preview.hidden = false;
        }
        changed(row, 0);
    };

    // ---------- clicks

    document.addEventListener('click', async (event) => {
        const target = event.target as HTMLElement;

        const undoField = target.closest('[data-ad-field-undo]');
        if (undoField) {
            const row = rowOf(undoField);
            const key = row?.dataset.key;
            if (row && key) {
                if (Object.prototype.hasOwnProperty.call(published, key)) writeValue(row, published[key]);
                pending.unset.push(key);
                delete pending.set[key];
                void flush();
            }

            return;
        }

        const reset = target.closest<HTMLElement>('[data-ad-appearance-reset]');
        if (reset) {
            const key = reset.dataset.adAppearanceReset ?? '';
            pending.reset.push(key);
            delete pending.set[key];
            void flush();

            return;
        }

        const defaultReset = target.closest<HTMLElement>('[data-reset]');
        if (defaultReset && form.contains(defaultReset)) {
            const row = rowOf(defaultReset);
            if (row?.dataset.default !== undefined) {
                writeValue(row, row.dataset.default);
                changed(row, 0);
            }

            return;
        }

        if (target.closest('[data-ad-discard]')) {
            if (await confirmed(text('discardTitle'), text('discardBody'), text('discard', 'Discard'))) {
                await send(boot.urls.discard, 'DELETE');
                location.reload();
            }

            return;
        }

        if (target.closest('[data-ad-preview-phone]')) {
            const screen = monitor();
            const phone = screen ? $<HTMLInputElement>('input[name^="ad_monitor_device"][value="phone"]', screen) : null;
            if (phone && screen) {
                phone.checked = true;
                phone.dispatchEvent(new Event('change', {bubbles: true}));
                screen.dispatchEvent(new CustomEvent('ad:monitor-refresh', {bubbles: true}));
                screen.scrollIntoView({block: 'nearest', behavior: 'smooth'});
            }

            return;
        }

        const undoLink = target.closest<HTMLElement>('[data-ad-undo], [data-ad-restore-snapshot]');
        if (undoLink) {
            const snapshot = undoLink.hasAttribute('data-ad-restore-snapshot');
            const url = undoLink.dataset.adUndo || undoLink.dataset.adRestoreSnapshot || '';
            const ok = await confirmed(
                snapshot ? text('restoreTitle') : text('undoTitle'),
                snapshot ? text('restoreBody') : text('undoBody'),
                snapshot ? text('restore', 'Restore') : text('undo', 'Undo'),
            );
            if (ok) await restore(url);

            return;
        }

        if (target.closest('[data-ad-history-open]')) {
            event.preventDefault();
            void openHistory();

            return;
        }

        if (target.closest('[data-ad-history-close]')) {
            $<HTMLDialogElement>('[data-ad-history]')?.close();

            return;
        }

        const historyUndo = target.closest<HTMLElement>('[data-ad-history-undo]');
        if (historyUndo) {
            const snapshot = historyUndo.hasAttribute('data-snapshot');
            const ok = await confirmed(snapshot ? text('restoreTitle') : text('undoTitle'), snapshot ? text('restoreBody') : text('undoBody'), snapshot ? text('restore') : text('undo'));
            if (ok) await restore(historyUndo.dataset.adHistoryUndo ?? '');

            return;
        }

        const look = target.closest<HTMLElement>('[data-ad-look]');
        if (look) {
            pending.look = look.dataset.adLook;
            showLiveAfter = true;
            $$('.ad-look', form).forEach((card) => card.classList.toggle('is-on', card.contains(look)));
            void flush();

            return;
        }

        const theme = target.closest<HTMLElement>('[data-ad-theme]');
        if (theme) {
            pickTheme(theme.dataset.adTheme ?? '', null);

            return;
        }

        const tab = target.closest<HTMLAnchorElement>('[data-ad-gallery-tabs] a[data-tab]');
        if (tab) {
            event.preventDefault();
            showTab(tab.dataset.tab ?? 'looks');

            return;
        }

        const family = target.closest<HTMLElement>('[data-ad-families] [data-family]');
        if (family) {
            $$('[data-ad-families] [data-family]').forEach((chip) => {
                chip.classList.toggle('is-on', chip === family);
                chip.setAttribute('aria-pressed', chip === family ? 'true' : 'false');
            });
            gallery.family = family.dataset.family ?? '';
            void loadThemes(false);

            return;
        }

        if (target.closest('[data-ad-themes-more]')) {
            event.preventDefault();
            void loadThemes(true);

            return;
        }

        const step = target.closest<HTMLElement>('[data-ad-cal-step]');
        if (step) {
            // The daily designs are this year's: no wrapping into another year.
            const month = calendar.month + Number(step.dataset.adCalStep);
            if (month >= 1 && month <= 12) {
                calendar.month = month;
                void loadCalendar();
            }

            return;
        }

        const day = target.closest<HTMLElement>('[data-ad-cal-day]');
        if (day) {
            const screen = monitor();
            if (screen) {
                const url = withToken(day.dataset.adCalDay ?? '/');
                screen.dataset.src = url;
                const select = $<HTMLSelectElement>('[data-ad-monitor-page]', screen);
                if (select) {
                    let option = $$<HTMLOptionElement>('option', select).find((item) => item.dataset.day !== undefined);
                    if (!option) {
                        option = document.createElement('option');
                        option.dataset.day = '';
                        select.append(option);
                    }
                    option.value = url;
                    option.textContent = day.getAttribute('aria-label') ?? '';
                    select.value = url;
                }
                screen.dispatchEvent(new CustomEvent('ad:monitor-refresh', {bubbles: true}));
            }
        }
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        void publish();
    });

    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's' && state.count > 0) {
            event.preventDefault();
            void publish();
        }
    });

    // ---------- publish, undo, history

    const publish = async (): Promise<void> => {
        await flush();
        if (state.count === 0) return;

        const button = $<HTMLButtonElement>('[data-ad-publish]');
        const label = button?.textContent ?? '';
        if (button) {
            button.disabled = true;
            button.textContent = text('publishing', 'Publishing...');
        }

        try {
            const {status, data} = await send(boot.urls.publish, 'POST', {page: boot.page});

            if (status === 422 || !data.ok) {
                showErrors(data.errors ?? {});
                toast(data.message ?? text('failed'), 'error');

                return;
            }

            // The Themes page shows what is on the site now (the current look, its badges, the colour style): it
            // comes back from the server, with the toast and its Undo.
            if (boot.page === 'appearance.themes') {
                try {
                    sessionStorage.setItem(TOAST_KEY, JSON.stringify({message: data.message ?? text('published'), undo: data.undo ?? ''}));
                } catch {
                    // Private mode: the page still reloads, without the toast.
                }
                location.reload();

                return;
            }

            // What was the draft is what visitors see now.
            $$<HTMLElement>('[data-key]', form).forEach((row) => {
                if (Object.prototype.hasOwnProperty.call(state.values, row.dataset.key ?? '')) {
                    published[row.dataset.key ?? ''] = readValue(row);
                }
            });
            apply(data);
            toast(data.message ?? text('published'), 'ok', data.undo ?? undefined);
        } catch {
            toast(text('offline'), 'error');
        } finally {
            if (button) {
                button.textContent = label;
                button.disabled = state.count === 0;
            }
            // The Themes page names what is published ("Publish this look"): follow the draft, not the old label.
            syncThemeUi();
        }
    };

    const restore = async (url: string): Promise<void> => {
        const {data} = await send(url, 'POST');

        if (data.ok) {
            toast(data.message ?? text('undone'), 'ok');
            window.setTimeout(() => location.reload(), 600);
        } else {
            toast(data.message ?? text('failed'), 'error');
        }
    };

    const openHistory = async (): Promise<void> => {
        const dialog = $<HTMLDialogElement>('[data-ad-history]');
        const list = $('[data-ad-history-list]');
        if (!dialog || !list) return;

        list.innerHTML = `<li class="ad-muted">${escapeHtml(text('loading', 'Loading...'))}</li>`;
        dialog.showModal();

        try {
            const {data} = await send(boot.urls.history, 'GET');
            const items = (data.items ?? []) as Array<{id: number; title: string; detail: string; when: string; by: string | null; restorable: boolean; snapshot: boolean; expires: string | null}>;

            list.innerHTML = items.length === 0
                ? `<li class="ad-muted">${escapeHtml(text('historyEmpty'))}</li>`
                : items.map((item) => `<li><span class="ad-history-text"><b>${escapeHtml(item.title)}</b><span class="ad-muted">${escapeHtml([item.when, item.by, item.detail].filter(Boolean).join(' · '))}</span></span>${item.restorable
                    ? `<button type="button" class="ad-btn sm" data-ad-history-undo="${escapeHtml(boot.urls.history)}/${item.id}/restore"${item.snapshot ? ' data-snapshot' : ''}>${escapeHtml(item.snapshot ? text('restore') : text('undo'))}</button>`
                    : ''}</li>`).join('');
        } catch {
            list.innerHTML = `<li class="ad-muted">${escapeHtml(text('loadFailed'))}</li>`;
        }
    };

    // ---------- Themes page: tabs, looks, themes, colour styles, calendar

    const gallery = {family: '', mode: '', query: '', total: Number($('[data-ad-themes]')?.dataset.total ?? 0)};
    const calendar = {month: Number($('[data-ad-calendar]')?.dataset.month ?? new Date().getMonth() + 1), loaded: false};

    const showTab = (name: string): void => {
        $$<HTMLAnchorElement>('[data-ad-gallery-tabs] a[data-tab]').forEach((link) => {
            const on = link.dataset.tab === name;
            link.classList.toggle('is-active', on);
            if (on) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
        $$<HTMLElement>('.ad-gallery-panel').forEach((panel) => {
            panel.hidden = panel.dataset.panel !== name;
        });
        const url = new URL(location.href);
        url.searchParams.set('tab', name);
        history.replaceState(null, '', url.toString());
        if (name === 'daily' && !calendar.loaded) void loadCalendar();
    };

    const swatch = (colours: string[]): string => {
        const [a, b, c] = [colours[0] ?? '#888888', colours[1] ?? colours[0] ?? '#888888', colours[2] ?? colours[0] ?? '#888888'].map((colour) => escapeHtml(colour));

        return `<svg class="ad-pie" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 10V0a10 10 0 0 1 0 20z" fill="${a}"/><path d="M10 10v10A10 10 0 0 1 1.34 5z" fill="${b}"/><path d="M10 10L1.34 5A10 10 0 0 1 10 0z" fill="${c}"/></svg>`;
    };

    const selectedTheme = (): string => $('[data-ad-flavors]')?.dataset.theme ?? '';

    const themeCard = (item: any): string => {
        const on = item.id === selectedTheme();
        const styles = text('colourStyles', ':count colour styles').replace(':count', String(item.flavors.length));
        const note = item.match || item.formerly || '';

        return `<li class="ad-theme${on ? ' is-on' : ''}" data-theme-id="${escapeHtml(item.id)}"><button type="button" class="ad-theme-pick" data-ad-theme="${escapeHtml(item.id)}" aria-pressed="${on}" data-flavors="${escapeHtml(JSON.stringify(item.flavors))}">`
            + (item.thumb ? `<img src="${escapeHtml(item.thumb)}" alt="" width="320" height="200" loading="lazy" decoding="async">` : `<span class="ad-theme-ph" aria-hidden="true">${swatch(item.flavors[0]?.colours ?? [])}</span>`)
            + `<span class="ad-theme-b"><b>${escapeHtml(item.name)}${item.current ? ` <span class="ad-badge is-ok">${escapeHtml(text('current'))}</span>` : ''}</b>`
            + `<small>${escapeHtml(item.family_name)} · ${escapeHtml(styles)}${note ? ' · ' + escapeHtml(note) : ''}</small>`
            + `<span class="ad-dots" aria-hidden="true">${item.flavors.slice(0, 8).map((flavor: Flavor) => `<svg viewBox="0 0 14 14"><circle cx="7" cy="7" r="6.5" fill="${escapeHtml(flavor.colours[0] ?? '#888888')}"/></svg>`).join('')}</span></span></button></li>`;
    };

    // A filter or a search loads the first 24 themes again; "Show all" asks for the ones after the cards shown (the
    // page itself shows 12 first), so no theme is skipped whatever was shown before.
    const loadThemes = async (append: boolean): Promise<void> => {
        const list = $('[data-ad-themes]');
        if (!list) return;

        const shown = append ? $$('.ad-theme', list).length : 0;
        const url = new URL(boot.urls.gallery, location.origin);
        url.searchParams.set('tab', 'themes');
        url.searchParams.set('offset', String(shown));
        if (gallery.family) url.searchParams.set('family', gallery.family);
        if (gallery.mode) url.searchParams.set('mode', gallery.mode);
        if (gallery.query) url.searchParams.set('q', gallery.query);

        list.setAttribute('aria-busy', 'true');
        const {data} = await send(url.toString(), 'GET');
        list.removeAttribute('aria-busy');
        const items = (data.items ?? []) as any[];
        const html = items.map(themeCard).join('');
        const more = $<HTMLElement>('[data-ad-themes-more]');
        const hadFocus = !!more && more.contains(document.activeElement);

        if (append) {
            list.insertAdjacentHTML('beforeend', html);
        } else {
            list.innerHTML = html || `<li class="ad-muted ad-themes-none">${escapeHtml(text('noThemes'))}</li>`;
        }
        gallery.total = data.total ?? items.length;

        const count = $$('.ad-theme', list).length;
        if (more) more.hidden = count >= gallery.total;
        // The button goes away once everything is shown: keep the keyboard on the first new theme.
        if (append && hadFocus && more?.hidden && items[0]) {
            $<HTMLElement>(`[data-ad-theme="${CSS.escape(String(items[0].id))}"]`, list)?.focus();
        }
    };

    const renderFlavors = (themeId: string, flavors: Flavor[], name: string, current: string | null): void => {
        const panel = $<HTMLElement>('[data-ad-flavors]');
        const box = $('[data-ad-swatches]');
        if (!panel || !box) return;

        panel.dataset.theme = themeId;
        const title = $('#ad-flavors-title', panel);
        if (title) title.textContent = text('colourStyleFor', 'Colour style for :name').replace(':name', name);
        box.innerHTML = flavors.map((flavor, index) => `<label class="ad-swatch"${flavor.formerly ? ` title="${escapeHtml(flavor.formerly)}"` : ''}><input type="radio" class="ad-sr" name="ad_flavor" value="${escapeHtml(flavor.id)}" data-ad-ignore${(current ? flavor.id === current : index === 0) ? ' checked' : ''}>${swatch(flavor.colours)}<span>${escapeHtml(flavor.name)}</span></label>`).join('');
    };

    const layoutsWanted = (): boolean => $<HTMLInputElement>('[data-ad-theme-layouts]')?.checked ?? false;

    const pickTheme = (themeId: string, flavor: string | null): void => {
        if (!themeId) return;
        const button = $<HTMLElement>(`[data-ad-theme="${CSS.escape(themeId)}"]`);

        if (button && flavor === null) {
            const flavors = JSON.parse(button.dataset.flavors || '[]') as Flavor[];
            renderFlavors(themeId, flavors, $('b', button)?.firstChild?.textContent?.trim() ?? themeId, null);
            flavor = flavors[0]?.id ?? null;
        }

        $$('.ad-theme', form).forEach((card) => {
            const on = (card as HTMLElement).dataset.themeId === themeId;
            card.classList.toggle('is-on', on);
            $('[data-ad-theme]', card)?.setAttribute('aria-pressed', on ? 'true' : 'false');
        });

        pending.theme = {id: themeId, flavor, layouts: layoutsWanted()};
        showLiveAfter = true;
        void flush();
    };

    form.addEventListener('change', (event) => {
        const target = event.target as HTMLInputElement;

        if (target.name === 'ad_flavor') {
            pickTheme(selectedTheme(), target.value);
        } else if (target.matches('[data-ad-theme-layouts]') && state.action?.type === 'theme') {
            pickTheme(state.action.id, state.action.flavor ?? null);
        } else if (target.name === 'ad_theme_mode_filter') {
            gallery.mode = target.value;
            void loadThemes(false);
        }
    });

    let searchTimer = 0;
    form.addEventListener('input', (event) => {
        const target = event.target as HTMLInputElement;
        if (!target.matches('[data-ad-theme-search]')) return;
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
            gallery.query = target.value.trim();
            void loadThemes(false);
        }, 250);
    });

    // Enter in a text box updates the preview; it never publishes (only the Publish button and Ctrl+S do).
    form.addEventListener('keydown', (event) => {
        const target = event.target as HTMLElement;
        if (event.key !== 'Enter' || !(target instanceof HTMLInputElement) || ['checkbox', 'radio', 'submit', 'button'].includes(target.type)) return;
        event.preventDefault();
        if (!target.matches('[data-ad-theme-search]')) void flush();
    });

    const syncThemeUi = (): void => {
        const action = state.action;
        $$('.ad-look', form).forEach((card) => {
            const id = $<HTMLElement>('[data-ad-look]', card)?.dataset.adLook;
            const on = action?.type === 'look' && action.id === id;
            card.classList.toggle('is-on', on);
            $('[data-ad-look]', card)?.setAttribute('aria-pressed', on ? 'true' : 'false');
        });

        const publish = $<HTMLButtonElement>('[data-ad-publish]');
        if (publish && boot.page === 'appearance.themes') {
            publish.textContent = action?.type === 'look' ? (text('publishLook') || text('publish')) : action?.type === 'theme' ? (text('publishTheme') || text('publish')) : text('publish', 'Publish');
        }
    };

    const loadCalendar = async (): Promise<void> => {
        const box = $<HTMLElement>('[data-ad-calendar]');
        const grid = $('[data-ad-cal-days]');
        if (!box || !grid) return;

        calendar.loaded = true;
        $$<HTMLButtonElement>('[data-ad-cal-step]', box).forEach((button) => {
            button.disabled = Number(button.dataset.adCalStep) < 0 ? calendar.month <= 1 : calendar.month >= 12;
        });
        const url = new URL(boot.urls.calendar, location.origin);
        url.searchParams.set('month', String(calendar.month));

        try {
            const {data} = await send(url.toString(), 'GET');
            const title = $('[data-ad-cal-title]', box);
            if (title) title.textContent = data.name ?? '';
            const link = $<HTMLAnchorElement>('[data-ad-cal-link]', box);
            if (link) link.href = `${boot.urls.calendar}?month=${calendar.month}`;

            const blanks = Math.max(0, (data.first_weekday ?? 1) - 1);
            grid.innerHTML = '<li class="ad-cal-blank" aria-hidden="true"></li>'.repeat(blanks) + (data.days ?? []).map((day: any) =>
                `<li class="ad-cal-day${day.today ? ' is-today' : ''}"><button type="button" data-ad-cal-day="${escapeHtml(day.preview)}" aria-label="${escapeHtml(`${day.date}: ${day.label}${day.holiday ? ' (' + day.holiday + ')' : ''}`)}"><span class="ad-cal-n">${day.day_of_month}</span>${swatch(day.colours)}${day.holiday ? `<small>${escapeHtml(day.holiday)}</small>` : ''}</button></li>`,
            ).join('');
        } catch {
            grid.innerHTML = `<li class="ad-muted">${escapeHtml(text('loadFailed'))}</li>`;
        }
    };

    // ---------- start

    // Gallery and font pictures below the first screen (data-ad-src) load when they come near it, so a page asks
    // only for the pictures in view; hidden tabs and closed folds load theirs when opened.
    const deferred = $$<HTMLImageElement>('img[data-ad-src]');
    const reveal = (img: HTMLImageElement): void => {
        img.src = img.dataset.adSrc ?? '';
        img.removeAttribute('data-ad-src');
    };
    if (deferred.length && 'IntersectionObserver' in window) {
        const watcher = new IntersectionObserver((entries) => entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            watcher.unobserve(entry.target);
            reveal(entry.target as HTMLImageElement);
        }), {rootMargin: '300px 0px'});
        deferred.forEach((img) => watcher.observe(img));
    } else {
        deferred.forEach(reveal);
    }

    // The toast of a publish that reloaded the page (Themes).
    try {
        const saved = sessionStorage.getItem(TOAST_KEY);
        if (saved) {
            sessionStorage.removeItem(TOAST_KEY);
            const shown = JSON.parse(saved) as {message?: string; undo?: string};
            if (shown.message) toast(shown.message, 'ok', shown.undo || undefined);
        }
    } catch {
        // No storage: no toast.
    }

    // Long code boxes load their saved value after the page (as on the settings pages).
    const lazyRows = $$<HTMLElement>('[data-key][data-lazy]', form);
    if (lazyRows.length) {
        void send(`${boot.urls.values}?keys=${encodeURIComponent(lazyRows.map((row) => row.dataset.key).join(','))}`, 'GET').then(({data}) => {
            lazyRows.forEach((row) => {
                const key = row.dataset.key ?? '';
                const box = $<HTMLTextAreaElement>('textarea', row);
                const value = String(data.values?.[key] ?? '');
                published[key] = value;
                if (box) {
                    box.value = Object.prototype.hasOwnProperty.call(state.values, key) ? String(state.values[key] ?? '') : value;
                    box.readOnly = false;
                    box.placeholder = '';
                }
            });
        });
    }

    if ($('[data-ad-calendar]') && !$<HTMLElement>('[data-panel="daily"]')?.hidden) void loadCalendar();

    publishBar();
    markRows();
    syncThemeUi();

    // A link older than 25 minutes is renewed before it is used again.
    window.setInterval(() => {
        if (state.token && state.expires_at && Date.parse(state.expires_at) - Date.now() < 5 * 60 * 1000) void flush();
    }, 60 * 1000);

}
