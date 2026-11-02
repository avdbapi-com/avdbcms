// The scoring of the admin's Ctrl+K search (resources/admin/palette.ts), without the page around it, so Node can run it
// against the real index (tests/js/admin_search.test.ts). The index is App\Support\AdminSearchIndex::build(): pages and
// tabs, every setting at every level (a developer setting while Developer mode is off carries x: 1, a hidden one its
// hint n and the place that sets it now in u), and the synonyms ("smtp", "api key"). No imports: Node runs it as it is.
//
// Which settings match is the rule of the search of Settings (App\Support\AdminSettingsSearch, PHP): every word of the
// query starts a word of the setting's texts (label, old names, help, card, page name, English and translated, and the
// key), or a search word made for the setting or its page is among the query's words ("api key", plural "api keys").
// So the row "See all N settings" opens Settings with the same N settings. Only the order is this file's own.

export type PageEntry = {id: string; t: string; s: string; d: string; u: string; k: string[]; i: string; p?: string; x?: 1};
export type SettingEntry = {
    k: string; t: string; h: string; p: string; c: string; u: string;
    o?: string[]; e?: string[]; a?: 1 | 2; x?: 1; n?: string;
    /** The English label, help and card where the admin's language words them differently. */
    et?: string; eh?: string; ec?: string;
};
export type SearchIndex = {
    v: string;
    sections: Record<string, string>;
    pageUrls: Record<string, string>;
    pageLabels?: Record<string, string[]>;
    pages: PageEntry[];
    settings: SettingEntry[];
    synonyms: Record<string, string[]>;
    texts?: Record<string, string>;
    hub?: string;
    locale?: string;
    fold?: 'nfd' | 'plain';
};
export type Result = {
    group: 'pages' | 'settings' | 'members' | 'videos' | 'more';
    title: string;
    sub: string;
    url: string;
    icon?: string;
    avatar?: string;
    badge?: string;
    key?: string;
    score: number;
    /** A setting's place by the rule of the search of Settings (settingRank()): the order of the settings group. */
    rank?: number;
    /** A setting's place in the catalogue: equal ranks keep it, as the search of Settings does. */
    position?: number;
};

/** What a result list shows of each group before "See all". */
export const MAX_PER_GROUP = 6;

/**
 * Lower case, no accents (unless the site's PHP cannot take them off: the index says "plain"), "_" and "-" as spaces,
 * one space between words: "Upload18_API-key" -> "upload18 api key". The same as AdminSettingsSearch::fold().
 */
export const fold = (value: string, accents = true): string => {
    let out = String(value ?? '').toLowerCase();
    if (accents) out = out.normalize('NFD').replace(/[̀-ͯ]/g, '');

    return out.replace(/[_-]/g, ' ').replace(/\s+/g, ' ').trim();
};

const words = (folded: string): string[] => folded.split(' ').filter(Boolean);

/**
 * Characters a word starts after: space, ASCII punctuation and the quotes, brackets and commas of the admin's languages
 * (AdminSettingsSearch::BOUNDARY, the same list). A query word must start a word: "ads" finds "AdSense", not "uploads".
 */
export const BOUNDARY = ' \t!"#$%&\'()*+,./:;<=>?@[\\]^`{|}~«»‘’“”…、。「」（），';

/**
 * Chinese, Japanese and Thai (no spaces between words) and Arabic (words glued to "al-"): found anywhere
 * (AdminSettingsSearch::ANYWHERE). Plain ranges, no \p{...}: the admin also runs on browsers without them.
 */
const ANYWHERE = /[؀-ۿݐ-ݿࢠ-ࣿ฀-๿぀-ヿㇰ-ㇿ㐀-䶿一-鿿豈-﫿ﭐ-﷿ﹰ-﻿ｦ-ﾟ]/;

/** Whether a folded query word starts a word of a folded text (AdminSettingsSearch::wordFound()). */
export const wordFound = (text: string, word: string): boolean => {
    if (!word) return true;
    if (ANYWHERE.test(word)) return text.includes(word);

    for (let at = text.indexOf(word); at !== -1; at = text.indexOf(word, at + 1)) {
        if (at === 0 || BOUNDARY.includes(text[at - 1])) return true;
    }

    return false;
};

/** How well a folded text matches the folded query: whole text, start, a word start, anywhere, all its words. */
const scoreFolded = (value: string, query: string, tokens: string[], weight: number): number => {
    if (!value) return 0;
    if (value === query) return weight * 1.25;
    if (value.startsWith(query)) return weight;
    if (value.includes(' ' + query)) return weight * 0.8;
    if (value.includes(query)) return weight * 0.6;
    if (tokens.length > 1 && tokens.every((token) => value.includes(token))) return weight * 0.5;

    return 0;
};

export const scoreText = (haystack: string, query: string, tokens: string[], weight: number, accents = true): number =>
    scoreFolded(fold(haystack, accents), query, tokens, weight);

/**
 * Whether a folded search word ("api key") is among the query's words, as whole words in that order; its last word may
 * carry a plural "s" or "es" ("api keys"). AdminSettingsSearch::termApplies().
 */
export const termApplies = (term: string, queryWords: string[]): boolean => {
    const termWords = words(term);
    const length = termWords.length;

    if (!length) return false;

    for (let start = 0; start + length <= queryWords.length; start++) {
        let all = true;
        for (let i = 0; i < length; i++) {
            const word = queryWords[start + i];
            const wanted = termWords[i];
            if (word !== wanted && !(i === length - 1 && (word === wanted + 's' || word === wanted + 'es'))) {
                all = false;
                break;
            }
        }
        if (all) return true;
    }

    return false;
};

type FoldedSetting = {
    t: string; h: string; c: string; k: string; o: string[]; e: string[]; text: string;
    /** Label, help and card in the admin's language and in English (each once), for settingRank(). */
    labels: string[]; helps: string[]; cards: string[];
};
type FoldedPage = {t: string; pt: string; k: string[]; d: string};
type Prepared = {
    accents: boolean;
    terms: Array<[string, string[]]>;
    /** Per setting: its texts folded, and all of them in one (" label old help card page key ... "). */
    settings: FoldedSetting[];
    pages: FoldedPage[];
};

const preparedCache = new WeakMap<SearchIndex, Prepared>();

/** The folded texts of every page and setting and the folded search words, made once per index (not per key press). */
const prepare = (index: SearchIndex): Prepared => {
    const cached = preparedCache.get(index);
    if (cached) return cached;

    const accents = index.fold !== 'plain';
    const f = (value: string | undefined): string => fold(value ?? '', accents);
    const prepared: Prepared = {
        accents,
        terms: Object.entries(index.synonyms ?? {}).map(([term, items]) => [f(term), items]),
        settings: index.settings.map((setting) => {
            const o = (setting.o ?? []).map(f);
            const e = (setting.e ?? []).map(f);
            const page = (index.pageLabels?.[setting.p] ?? []).map(f);
            const folded = {t: f(setting.t), h: f(setting.h), c: f(setting.c), k: f(setting.k), o, e};
            const both = (here: string, english: string | undefined): string[] =>
                [...new Set([here, f(english ?? '') || here])].filter(Boolean);

            return {
                ...folded,
                text: ' ' + [folded.t, ...o, folded.h, folded.c, ...page, ...e, folded.k].filter(Boolean).join(' ') + ' ',
                labels: both(folded.t, setting.et),
                helps: both(folded.h, setting.eh),
                cards: both(folded.c, setting.ec),
            };
        }),
        pages: index.pages.map((page) => ({t: f(page.t), pt: page.p ? f(`${page.p} ${page.t}`) : '', k: page.k.map(f), d: f(page.d)})),
    };
    preparedCache.set(index, prepared);

    return prepared;
};

/** The setting keys and page ids the search words among the query's words name (AdminSettingsSearch::targets()). */
export const settingTargets = (index: SearchIndex, queryWords: string[]): Set<string> => {
    const targets = new Set<string>();

    for (const [term, items] of prepare(index).terms) {
        if (termApplies(term, queryWords)) items.forEach((item) => targets.add(item));
    }

    return targets;
};

/**
 * A matching setting's place, by the rule of the search of Settings (SettingsPageController::searchSettings()), so both
 * searches list the matches in the same order and Enter opens the setting Settings lists first (R1-155: "smtp",
 * "premium", "trial" and "custom code" put different settings first). A key typed as it is first, then the label as
 * typed, an old name as typed, a label that starts with the words, a help text as typed; else the words found in the
 * texts (each one counts 3 in a label, 1 elsewhere) and where the whole query stands (the card's own name, a label or
 * an old name, a card, a help). A search word made for the setting adds 600 when it is the whole query, else 150; one
 * made for its page adds 20; a developer setting
 * while Developer mode is off loses 2, a hidden one 5. Equal places keep the catalogue's order (the index's).
 */
const settingRank = (folded: FoldedSetting, setting: SettingEntry, query: string, tokens: string[], byWords: boolean, targets: Set<string>, exact: Set<string>): number => {
    const has = (list: string[], needle: string): boolean => list.some((text) => text !== '' && text.includes(needle));
    let rank = 0;

    if (setting.k.includes('_') && folded.k === query) rank = 1000;
    else if (folded.labels.includes(query)) rank = 500;
    else if (folded.o.includes(query)) rank = 400;
    else if (folded.labels.some((text) => text !== '' && text.startsWith(query))) rank = 300;
    else if (folded.helps.includes(query)) rank = 250;
    else if (byWords) {
        for (const token of tokens) rank += has(folded.labels, token) ? 3 : 1;

        if (folded.cards.includes(query)) rank += 110;
        else if (has([...folded.labels, ...folded.o], query)) rank += 100;
        else if (has(folded.cards, query)) rank += 70;
        else if (has(folded.helps, query)) rank += 50;
    }

    if (exact.has(setting.k)) rank += 600;
    else if (targets.has(setting.k)) rank += 150;
    else if (targets.has(setting.p)) rank += 20;

    return rank - (setting.x ? 2 : (setting.n ? 5 : 0));
};

/**
 * The setting keys and page ids of the search words that ARE the whole query ("premium", "smtp", "api keys"): they come
 * first in both searches (AdminSettingsSearch::exactTargets()).
 */
export const exactTargets = (index: SearchIndex, query: string): Set<string> => {
    const targets = new Set<string>();

    for (const [term, items] of prepare(index).terms) {
        if (term !== '' && (query === term || query === term + 's' || query === term + 'es')) items.forEach((item) => targets.add(item));
    }

    return targets;
};

/** Pages a synonym of the query names, also while it is typed ("smt" -> the email settings): pages only. */
export const synonymTargets = (index: SearchIndex, query: string): Set<string> => {
    const targets = new Set<string>();

    if (query.length < 2) return targets;

    for (const [folded, items] of prepare(index).terms) {
        if (folded === query || folded.startsWith(query) || (query.length >= 4 && query.startsWith(folded))) {
            items.forEach((item) => targets.add(item));
        }
    }

    return targets;
};

/**
 * Every page and setting that matches, best first. A setting is found by its key (an exact key comes first), its label,
 * its old name, its card, its help and its page (the rule of AdminSettingsSearch); a developer setting found while
 * Developer mode is off and a hidden one come a little lower and carry their badge or hint in the sub line.
 */
export const searchIndex = (index: SearchIndex | null, raw: string, moreOptions = 'More options'): Result[] => {
    if (!index) return [];

    const prepared = prepare(index);
    const query = fold(raw, prepared.accents);
    const tokens = words(query);

    if (!query) return [];

    const boosted = synonymTargets(index, query);
    const targets = settingTargets(index, tokens);
    const exact = exactTargets(index, query);
    const pageById = new Map(index.pages.filter((p) => !p.id.includes('#')).map((p) => [p.id, p]));
    const developer = index.texts?.developer ?? 'Developer mode';
    const advanced = index.texts?.advanced ?? 'Advanced';
    // a label and its value as the admin's language writes them ("Page：tab" in Chinese, "Page : tab" in French)
    const labelled = (label: string, value: string): string => label + (index.texts?.label ?? ': ') + value;
    const out: Result[] = [];

    index.pages.forEach((page, at) => {
        const folded = prepared.pages[at];
        const base = page.id.split('#')[0];
        let score = Math.max(
            scoreFolded(folded.t, query, tokens, 100),
            folded.pt ? scoreFolded(folded.pt, query, tokens, 70) : 0,
            ...folded.k.map((k) => scoreFolded(k, query, tokens, 75)),
            scoreFolded(folded.d, query, tokens, 25),
        );
        if (boosted.has(base)) score = Math.max(score, 90);
        if (score > 0) {
            const section = index.sections[page.s] ?? '';
            out.push({
                group: 'pages',
                title: page.p ? labelled(page.p, page.t) : page.t,
                sub: page.d ? labelled(section, page.d) : section,
                url: page.u,
                icon: page.i,
                badge: page.x ? developer : undefined,
                // A developer page while Developer mode is off comes a little lower than an everyday match.
                score: score + (page.p ? 0 : 2) - (page.x ? 8 : 0),
            });
        }
    });

    index.settings.forEach((setting, at) => {
        if (!setting.u) return;

        const folded = prepared.settings[at];
        const byWords = tokens.every((token) => wordFound(folded.text, token));
        const byTarget = targets.has(setting.k) || targets.has(setting.p);

        // The shared rule decides what matches; the scores below only decide the order.
        if (!byWords && !byTarget) return;

        // A setting key typed as it is ("upload18_api_key") comes first. A key that is a plain word ("theme") is no
        // key search: the word's meaning decides (the Themes, not the "Visitors see" setting stored as "theme").
        const exactKey = setting.k.includes('_') && folded.k === query;
        let score = Math.max(
            exactKey ? 200 : 0,
            scoreFolded(folded.t, query, tokens, 95),
            ...folded.o.map((o) => scoreFolded(o, query, tokens, 70)),
            // The card's name lists the card's settings ("Admin access lock", "Sign-up & login").
            scoreFolded(folded.c, query, tokens, 80),
            scoreFolded(folded.k, query, tokens, 60),
            scoreFolded(folded.h, query, tokens, 20),
            ...folded.e.map((e) => scoreFolded(e, query, tokens, 20)),
            // Found by words spread over its texts (label and page, help and card, ...).
            byWords ? 10 : 0,
        );
        // A search word made for this setting ("api key" -> the Upload18 key) puts it above pages that only share a
        // word; one made for its page lists it after the settings that hold the words.
        if (targets.has(setting.k)) score = exactKey ? score : Math.max(score, 100) + 10;
        else if (byTarget) score = Math.max(score, 8);

        const page = pageById.get(setting.p);
        const card = setting.c;
        const path = (setting.a === 2 && !setting.x
            ? [index.sections[page?.s ?? ''] ?? '', page?.t ?? '', advanced, card]
            : [index.sections[page?.s ?? ''] ?? '', page?.t ?? '', card]).filter(Boolean).join(' > ');
        const note = setting.n ? ' - ' + setting.n : (setting.a === 1 && !setting.x ? ' > ' + moreOptions : '');

        out.push({
            group: 'settings',
            title: setting.t,
            sub: path + note,
            url: setting.u,
            icon: 'sliders',
            badge: setting.x ? developer : undefined,
            key: setting.k,
            score: score - (setting.a ? 3 : 0) - (setting.x ? 4 : 0) - (setting.n ? 6 : 0),
            rank: settingRank(folded, setting, query, tokens, byWords, targets, exact),
            position: at,
        });
    });

    // Pages by their score (equal scores keep the index order); the settings in the order of the search of Settings
    // (settingRank(), equal ranks in catalogue order). The score of a setting only weighs its group against the pages.
    const pages = out
        .filter((result) => result.group === 'pages')
        .map((result, position) => ({result, position}))
        .sort((a, b) => b.result.score - a.result.score || a.position - b.position)
        .map(({result}) => result);
    const settings = out
        .filter((result) => result.group === 'settings')
        .sort((a, b) => (b.rank ?? 0) - (a.rank ?? 0) || (a.position ?? 0) - (b.position ?? 0));

    return [...pages, ...settings];
};

/**
 * The groups a result list shows: the first MAX_PER_GROUP pages and settings and, when more settings match, a last
 * row that opens Settings with the same search (it lists the same settings). seeAll writes that row's counted text
 * (the palette passes the translated line through lib/plural.ts). order puts the group with the best match first, so
 * the first row (the one Enter opens) is the best match: "API key" opens the Upload18 key, "Database" the Database page.
 */
export const visibleResults = (
    index: SearchIndex | null,
    raw: string,
    moreOptions = 'More options',
    seeAll: (count: number) => string = (count) => `See all ${count} settings`,
): {pages: Result[]; settings: Result[]; more: Result | null; order: Array<'pages' | 'settings'>; total: number} => {
    const local = searchIndex(index, raw, moreOptions);
    const pages = local.filter((r) => r.group === 'pages').slice(0, MAX_PER_GROUP);
    const allSettings = local.filter((r) => r.group === 'settings');
    const settings = allSettings.slice(0, MAX_PER_GROUP);
    let more: Result | null = null;

    if (allSettings.length > MAX_PER_GROUP && index?.hub) {
        const url = new URL(index.hub, 'http://localhost');
        url.searchParams.set('q', raw.trim());
        more = {
            group: 'more',
            title: seeAll(allSettings.length),
            sub: index.texts?.seeAllHelp ?? '',
            url: /^https?:/.test(index.hub) ? url.toString() : url.pathname + url.search,
            icon: 'search',
            score: 0,
        };
    }

    // The settings are in the order of the search of Settings: their best score (not the first row's) weighs them
    // against the best page.
    const bestSetting = allSettings.reduce((best, result) => Math.max(best, result.score), -Infinity);
    const order: Array<'pages' | 'settings'> = bestSetting > (pages[0]?.score ?? -Infinity) ? ['settings', 'pages'] : ['pages', 'settings'];

    return {pages, settings, more, order, total: allSettings.length};
};
