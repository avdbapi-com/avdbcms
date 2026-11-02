// Ctrl+K search (<x-admin.palette>): pages, tabs and every setting from the index (App\Support\AdminSearchIndex,
// fetched once on first open, scored by lib/search.ts), members and videos from the server as you type. Old names,
// setting keys and synonyms work ("Preflight", "Mass Broadcast", "smtp", "api key", "upload18_api_key"). A developer
// page or setting found while Developer mode is off carries a "Developer mode" badge (its page says how to see it); when
// more settings match than the list shows, a last row opens Settings with the same search. Loaded on first use by
// modules/shell.ts. Both requests are background GETs (backgroundGet): searching never uses up a message or becomes
// "the page you came from".

import {$, backgroundGet, escapeHtml, icon, t} from './lib/dom';
import {plural} from './lib/plural';
import {type Result, type SearchIndex, visibleResults} from './lib/search';

type RemoteItem = {t: string; d: string; u: string; i?: string};

let dialog: HTMLDialogElement | null = null;
let input: HTMLInputElement | null = null;
let list: HTMLElement | null = null;
let indexPromise: Promise<SearchIndex | null> | null = null;
let index: SearchIndex | null = null;
// Where the index is: until it has arrived the list never says "Nothing found" (on first use, after an update or a
// change of language or Developer mode it takes a few seconds); when it could not be loaded the list says so, and the
// next key press (or opening the search again) asks once more.
let indexState: 'idle' | 'loading' | 'ready' | 'failed' = 'idle';
let results: Result[] = [];
let remote: {members: RemoteItem[]; videos: RemoteItem[]} = {members: [], videos: []};
let active = 0;
let remoteTimer = 0;
let remoteAbort: AbortController | null = null;

const loadIndex = (): Promise<SearchIndex | null> => {
    if (!indexPromise) {
        const url = dialog?.dataset.indexUrl ?? '';
        indexState = 'loading';
        indexPromise = backgroundGet(url, {Accept: 'application/json'})
            .then((response) => {
                if (!response.ok) throw new Error(`search index: ${response.status}`);

                return response.json() as Promise<SearchIndex>;
            })
            .then((data) => {
                if (!data || !Array.isArray(data.pages) || !Array.isArray(data.settings)) throw new Error('search index: no index');

                index = data;
                indexState = 'ready';

                return data;
            })
            .catch(() => {
                // An answer that is no index (a server error, a sign-in page, no connection) is never kept.
                indexPromise = null;
                indexState = 'failed';

                return null;
            });
    }

    return indexPromise;
};

/** Ask for the index (again, after a failure) and draw the list when it has arrived. */
const ensureIndex = (): void => {
    if (index || indexPromise) return;

    void loadIndex().then(() => {
        if (dialog?.open) render();
    });
};

// The search's folding, one character for one, so the match marks the same letters of the title.
const foldInPlace = (value: string): string =>
    value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[_\-]/g, ' ');

const highlight = (text: string, raw: string): string => {
    const query = raw.trim();
    const safe = escapeHtml(text);

    if (query.length < 2) return safe;

    const at = foldInPlace(text).indexOf(foldInPlace(query));

    if (at < 0) return safe;

    return escapeHtml(text.slice(0, at)) + '<mark>' + escapeHtml(text.slice(at, at + query.length)) + '</mark>' + escapeHtml(text.slice(at + query.length));
};

const render = (): void => {
    if (!list || !input) return;

    const query = input.value;
    const seeAll = (count: number): string => plural(index?.texts?.seeAll ?? 'See the 1 setting|See all :count settings', count, index?.locale);
    const {pages, settings, more, order} = visibleResults(index, query, t('moreOptions', 'More options'), seeAll);
    const members: Result[] = remote.members.map((m) => ({group: 'members', title: m.t, sub: m.d, url: m.u, avatar: m.i ?? '', score: 0}));
    const videos: Result[] = remote.videos.map((v) => ({group: 'videos', title: v.t, sub: v.d, url: v.u, icon: 'video', score: 0}));
    const settingRows = more ? [...settings, more] : settings;
    // The group with the best match comes first, so its first row (selected, opened by Enter) is the best match.
    const local: [string, string, Result[]][] = order.map((group) => group === 'settings'
        ? ['settings', t('settings', 'Settings'), settingRows]
        : ['pages', t('pages', 'Pages'), pages]);
    const groups: [string, string, Result[]][] = [
        ...local,
        ['members', t('members', 'Members'), members],
        ['videos', t('videos', 'Videos'), videos],
    ];

    results = groups.flatMap(([, , items]) => items);
    active = Math.min(active, Math.max(0, results.length - 1));

    let html = '';
    let n = 0;

    for (const [, label, items] of groups) {
        if (!items.length) continue;

        // The group's aria-label names it; the visible heading is hidden from the listbox (only options belong there).
        html += `<div class="ad-palette-group" role="group" aria-label="${escapeHtml(label)}"><h4 aria-hidden="true">${escapeHtml(label)}</h4>`;

        for (const item of items) {
            const lead = item.avatar !== undefined
                ? `<span class="ad-avatar" aria-hidden="true">${escapeHtml(item.avatar)}</span>`
                : icon(item.icon ?? 'file');
            const badge = item.badge ? ` <em class="ad-badge ad-palette-badge">${escapeHtml(item.badge)}</em>` : '';
            const sub = item.sub ? `<small>${escapeHtml(item.sub)}</small>` : '';
            html += `<a class="ad-palette-item${n === active ? ' is-active' : ''}${item.group === 'more' ? ' is-more' : ''}" id="ad-pal-${n}" role="option" aria-selected="${n === active}" href="${escapeHtml(item.url)}" data-index="${n}">`
                + `${lead}<span class="ad-palette-t"><span>${item.group === 'more' ? escapeHtml(item.title) : highlight(item.title, query)}${badge}</span>${sub}</span>`
                + `<span class="ad-kbd" aria-hidden="true">Enter</span></a>`;
            n++;
        }

        html += '</div>';
    }

    // Pages and settings not there yet: say so above what the server found already (members, videos), never
    // "Nothing found" (the 1.2.2 window claimed that for 3-5 s on first use, and for good when the index failed).
    const waiting = !index && query.trim().length > 0 && indexState !== 'ready'
        ? (indexState === 'failed'
            ? t('searchFailed', 'Pages and settings could not be loaded. Type again to try once more.')
            : t('searchLoading', 'Searching pages and settings...'))
        : '';

    if (waiting) {
        html = `<div class="ad-palette-empty" role="option" aria-disabled="true" aria-selected="false" data-palette-state="${indexState === 'failed' ? 'failed' : 'loading'}">${escapeHtml(waiting)}</div>` + html;
    } else if (!html) {
        // A disabled option: a listbox holds options only.
        const message = query.trim().length
            ? t('noResults', 'Nothing found for ":query". Try another word.').replace(':query', query.trim())
            : t('searchHint', 'Type to search pages, settings, members and videos.');
        html = `<div class="ad-palette-empty" role="option" aria-disabled="true" aria-selected="false">${escapeHtml(message)}</div>`;
    }

    list.innerHTML = html;
    input.setAttribute('aria-expanded', String(results.length > 0));

    if (results.length) input.setAttribute('aria-activedescendant', `ad-pal-${active}`);
    else input.removeAttribute('aria-activedescendant');

    // The live region says what the list shows (R2A-021): the waiting or failed state, nothing found, or how many
    // results; nothing before the first letter.
    announce(waiting
        || (!query.trim().length ? ''
            : results.length
                ? plural(status?.dataset.results || '1 result|:count results', results.length)
                : t('noResults', 'Nothing found for ":query". Try another word.').replace(':query', query.trim())));
};

let status: HTMLElement | null = null;
let announceTimer = 0;
let announced = '';

/**
 * Put a text into the search's live region once typing pauses (each key press would make a screen reader start over),
 * and only when it changed. The region is emptied first, so the same words said again after a pause are announced.
 */
const announce = (text: string): void => {
    window.clearTimeout(announceTimer);

    if (!status) return;

    if (text === '') {
        announced = '';
        status.textContent = '';

        return;
    }

    if (text === announced) return;

    announceTimer = window.setTimeout(() => {
        if (!status || !dialog?.open) return;

        announced = text;
        status.textContent = '';
        window.setTimeout(() => {
            if (status && announced === text) status.textContent = text;
        }, 60);
    }, 450);
};

const fetchRemote = (raw: string): void => {
    window.clearTimeout(remoteTimer);
    remoteAbort?.abort();
    const query = raw.trim();

    if (query.length < 2) {
        remote = {members: [], videos: []};

        return;
    }

    remoteTimer = window.setTimeout(() => {
        remoteAbort = new AbortController();
        const url = new URL(dialog?.dataset.searchUrl ?? '', window.location.href);
        url.searchParams.set('q', query);

        backgroundGet(url, {Accept: 'application/json'}, remoteAbort.signal)
            .then((response) => (response.ok ? response.json() : {members: [], videos: []}))
            .then((data: {members: RemoteItem[]; videos: RemoteItem[]}) => {
                if (input?.value.trim() === query) {
                    remote = {members: data.members ?? [], videos: data.videos ?? []};
                    render();
                }
            })
            .catch(() => undefined);
    }, 220);
};

const move = (delta: number): void => {
    if (!results.length || !list) return;

    active = (active + delta + results.length) % results.length;
    list.querySelectorAll('.ad-palette-item').forEach((el, i) => {
        el.classList.toggle('is-active', i === active);
        el.setAttribute('aria-selected', String(i === active));
    });
    input?.setAttribute('aria-activedescendant', `ad-pal-${active}`);
    document.getElementById(`ad-pal-${active}`)?.scrollIntoView({block: 'nearest'});
};

const setup = (): boolean => {
    if (dialog) return true;

    dialog = $<HTMLDialogElement>('#ad-palette');
    input = dialog ? $<HTMLInputElement>('input', dialog) : null;
    list = dialog ? $('#ad-palette-list', dialog) : null;
    status = dialog ? $('#ad-palette-status', dialog) : null;

    if (!dialog || !input || !list) return false;

    input.addEventListener('input', () => {
        active = 0;
        remote = {members: [], videos: []};
        // After a failed load, typing asks for the index once more.
        ensureIndex();
        render();
        fetchRemote(input!.value);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            move(1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            move(-1);
        } else if (event.key === 'Enter') {
            const target = results[active];
            if (target) {
                event.preventDefault();
                window.location.href = target.url;
            }
        } else if (event.key === 'Escape') {
            // A search field would first clear itself; Esc closes the search at once.
            event.preventDefault();
            dialog!.close();
        }
    });

    list.addEventListener('pointermove', (event) => {
        const item = (event.target as Element).closest<HTMLElement>('.ad-palette-item');
        const i = item ? Number(item.dataset.index) : -1;
        if (i >= 0 && i !== active) move(i - active);
    });

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog!.close();
    });

    return true;
};

export const openPalette = (query = ''): void => {
    if (!setup() || !dialog || !input) return;

    if (!dialog.open) dialog.showModal();

    input.value = query;
    input.focus();
    active = 0;
    ensureIndex();
    render();

    if (query) fetchRemote(query);
};
