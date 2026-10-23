// Ctrl+K search (<x-admin.palette>): pages, tabs and settings from the index (App\Support\AdminSearchIndex,
// fetched once on first open), members and videos from the server as you type. Old names and synonyms work
// ("Preflight", "Mass Broadcast", "smtp", "logo"). Loaded on first use by modules/shell.ts.

import {$, escapeHtml, icon, t} from './lib/dom';

type PageEntry = {id: string; t: string; s: string; d: string; u: string; k: string[]; i: string; p?: string};
type SettingEntry = {k: string; t: string; h: string; p: string; c: string; o?: string[]; a?: 1};
type Index = {
    v: string;
    sections: Record<string, string>;
    pageUrls: Record<string, string>;
    pages: PageEntry[];
    settings: SettingEntry[];
    synonyms: Record<string, string[]>;
};
type RemoteItem = {t: string; d: string; u: string; i?: string};
type Result = {group: string; title: string; sub: string; url: string; icon?: string; avatar?: string; score: number};

const MAX_PER_GROUP = 6;

let dialog: HTMLDialogElement | null = null;
let input: HTMLInputElement | null = null;
let list: HTMLElement | null = null;
let indexPromise: Promise<Index | null> | null = null;
let index: Index | null = null;
let pageById = new Map<string, PageEntry>();
let results: Result[] = [];
let remote: {members: RemoteItem[]; videos: RemoteItem[]} = {members: [], videos: []};
let active = 0;
let remoteTimer = 0;
let remoteAbort: AbortController | null = null;

const fold = (value: string): string =>
    value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[_\-]+/g, ' ');

const loadIndex = (): Promise<Index | null> => {
    if (!indexPromise) {
        const url = dialog?.dataset.indexUrl ?? '';
        indexPromise = fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
            .then((response) => (response.ok ? (response.json() as Promise<Index>) : null))
            .then((data) => {
                index = data;
                pageById = new Map((data?.pages ?? []).filter((p) => !p.id.includes('#')).map((p) => [p.id, p]));

                return data;
            })
            .catch(() => {
                indexPromise = null;

                return null;
            });
    }

    return indexPromise;
};

const scoreText = (haystack: string, query: string, tokens: string[], weight: number): number => {
    const value = fold(haystack);

    if (!value) return 0;
    if (value === query) return weight * 1.25;
    if (value.startsWith(query)) return weight;
    if (value.includes(' ' + query)) return weight * 0.8;
    if (value.includes(query)) return weight * 0.6;
    if (tokens.length > 1 && tokens.every((token) => value.includes(token))) return weight * 0.5;

    return 0;
};

const synonymTargets = (query: string): Set<string> => {
    const targets = new Set<string>();

    if (!index || query.length < 2) return targets;

    for (const [term, items] of Object.entries(index.synonyms)) {
        const folded = fold(term);
        if (folded === query || folded.startsWith(query) || (query.length >= 4 && query.startsWith(folded))) {
            items.forEach((item) => targets.add(item));
        }
    }

    return targets;
};

const searchIndex = (raw: string): Result[] => {
    if (!index) return [];

    const query = fold(raw.trim());
    const tokens = query.split(/\s+/).filter(Boolean);

    if (!query) return [];

    const boosted = synonymTargets(query);
    const out: Result[] = [];

    for (const page of index.pages) {
        const base = page.id.split('#')[0];
        let score = Math.max(
            scoreText(page.t, query, tokens, 100),
            page.p ? scoreText(`${page.p} ${page.t}`, query, tokens, 70) : 0,
            ...page.k.map((k) => scoreText(k, query, tokens, 75)),
            scoreText(page.d, query, tokens, 25),
        );
        if (boosted.has(base)) score = Math.max(score, 90);
        if (score > 0) {
            const section = index.sections[page.s] ?? '';
            out.push({
                group: 'pages',
                title: page.p ? `${page.p}: ${page.t}` : page.t,
                sub: `${section}${page.d ? ': ' + page.d : ''}`,
                url: page.u,
                icon: page.i,
                score: score + (page.p ? 0 : 2),
            });
        }
    }

    for (const setting of index.settings) {
        let score = Math.max(
            scoreText(setting.t, query, tokens, 95),
            ...(setting.o ?? []).map((o) => scoreText(o, query, tokens, 70)),
            scoreText(setting.k, query, tokens, 60),
            scoreText(setting.h, query, tokens, 20),
        );
        if (boosted.has(setting.k)) score = Math.max(score, 88);
        if (score > 0) {
            const page = pageById.get(setting.p);
            const url = index.pageUrls[setting.p];
            if (!url) continue;
            const path = [index.sections[page?.s ?? ''] ?? '', page?.t ?? '', setting.c].filter(Boolean).join(' > ');
            out.push({
                group: 'settings',
                title: setting.t,
                sub: path + (setting.a ? ' > ' + t('moreOptions', 'More options') : ''),
                url: url.includes('?') ? url : `${url}#${setting.k}`,
                icon: 'sliders',
                score: score - (setting.a ? 3 : 0),
            });
        }
    }

    return out.sort((a, b) => b.score - a.score);
};

const highlight = (text: string, raw: string): string => {
    const query = raw.trim();
    const safe = escapeHtml(text);

    if (query.length < 2) return safe;

    const at = fold(text).indexOf(fold(query));

    if (at < 0) return safe;

    return escapeHtml(text.slice(0, at)) + '<mark>' + escapeHtml(text.slice(at, at + query.length)) + '</mark>' + escapeHtml(text.slice(at + query.length));
};

const render = (): void => {
    if (!list || !input) return;

    const query = input.value;
    const local = searchIndex(query);
    const pages = local.filter((r) => r.group === 'pages').slice(0, MAX_PER_GROUP);
    const settings = local.filter((r) => r.group === 'settings').slice(0, MAX_PER_GROUP);
    const members: Result[] = remote.members.map((m) => ({group: 'members', title: m.t, sub: m.d, url: m.u, avatar: m.i ?? '', score: 0}));
    const videos: Result[] = remote.videos.map((v) => ({group: 'videos', title: v.t, sub: v.d, url: v.u, icon: 'video', score: 0}));

    results = [...pages, ...settings, ...members, ...videos];
    active = Math.min(active, Math.max(0, results.length - 1));

    const groups: [string, string, Result[]][] = [
        ['pages', t('pages', 'Pages'), pages],
        ['settings', t('settings', 'Settings'), settings],
        ['members', t('members', 'Members'), members],
        ['videos', t('videos', 'Videos'), videos],
    ];

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
            html += `<a class="ad-palette-item${n === active ? ' is-active' : ''}" id="ad-pal-${n}" role="option" aria-selected="${n === active}" href="${escapeHtml(item.url)}" data-index="${n}">`
                + `${lead}<span class="ad-palette-t"><span>${highlight(item.title, query)}</span><small>${escapeHtml(item.sub)}</small></span>`
                + `<span class="ad-kbd" aria-hidden="true">Enter</span></a>`;
            n++;
        }

        html += '</div>';
    }

    if (!html) {
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

        fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}, signal: remoteAbort.signal})
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

    if (!dialog || !input || !list) return false;

    input.addEventListener('input', () => {
        active = 0;
        remote = {members: [], videos: []};
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
    render();

    void loadIndex().then(() => {
        if (dialog?.open) render();
    });

    if (query) fetchRemote(query);
};
