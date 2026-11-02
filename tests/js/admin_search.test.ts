// The admin's Ctrl+K search (resources/admin/lib/search.ts) against the real index of the site, as the admin's browser
// gets it (App\Support\AdminSearchIndex::build(), written by tests/Feature/AdminSettingsSearchTest.php).
// Run: node tests/js/admin_search.test.ts <index.json> [<checks.json>]   (Node 22.18+ runs TypeScript directly).
// Exit code 1 on a failure.
//
// Every setting is found by its key (as the first setting), by its label, by each old name, by its help text and by
// the name of its card: in the six settings the search shows, or, when more settings share those words, through the
// "See all" row that opens Settings with the same search. The Upload18 key is the first setting for "upload18",
// "Upload18 key", "API key" and "upload key", also while Developer mode is off.
//
// checks.json (written by the PHP test): {boundary, checks: [{q, keys}]}, the settings the search of Settings
// (SettingsPageController::searchSettings(), App\Support\AdminSettingsSearch) finds for each query. Ctrl+K must find
// exactly the same settings, so its "See all N settings" opens Settings with the same N.
import {readFileSync} from 'node:fs';
import {BOUNDARY, MAX_PER_GROUP, searchIndex, visibleResults, type SearchIndex} from '../../resources/admin/lib/search.ts';

let failures = 0;
let passed = 0;
const fail = (message: string): void => {
    failures++;
    if (failures <= 25) console.error(`FAIL ${message}`);
};
const check = (ok: boolean, message: string): void => {
    if (ok) passed++;
    else fail(message);
};

const file = process.argv[2];
const checksFile = process.argv[3];

if (!file) {
    console.error('usage: node tests/js/admin_search.test.ts <index.json> [<checks.json>]');
    process.exit(2);
}

const index = JSON.parse(readFileSync(file, 'utf8')) as SearchIndex;
const settingsFor = (query: string): string[] =>
    searchIndex(index, query).filter((r) => r.group === 'settings').map((r) => r.key ?? '');
const english = (index.locale ?? 'en').startsWith('en');

// The Upload18 key under every name owners use for it (the English names work in every language).
for (const query of ['upload18', 'Upload18 key', 'API key', 'upload key', 'upload18_api_key']) {
    const found = searchIndex(index, query).filter((r) => r.group === 'settings');
    check(found[0]?.key === 'upload18_api_key', `"${query}": the first setting is ${found[0]?.key ?? 'nothing'}, not upload18_api_key`);
    check(found[0]?.badge === undefined, `"${query}": the Upload18 key needs no Developer mode`);
    check(!!found[0]?.url && /\/admin\/settings\/videos#upload18_api_key$/.test(found[0].url), `"${query}": the Upload18 key opens ${found[0]?.url}`);
    // The list starts with it: Enter opens the Upload18 key, not a page that happens to match too ("API keys").
    check(visibleResults(index, query).order[0] === 'settings', `"${query}": the settings come first`);
    // Its card sits in the Advanced fold of the page: the result says so (not "More options").
    if (english) check(/ > Advanced > Upload18 connection$/.test(found[0]?.sub ?? ''), `"${query}": the Upload18 key's place is "${found[0]?.sub}"`);
}

const labels = new Map<string, number>();
for (const s of index.settings) labels.set(s.t.toLowerCase(), (labels.get(s.t.toLowerCase()) ?? 0) + 1);

for (const s of index.settings) {
    // By its key: the first setting (a key that is a plain word, "theme" or "timezone", among the settings shown).
    const byKey = settingsFor(s.k);
    if (s.k.includes('_')) {
        check(byKey[0] === s.k, `key ${s.k}: the first setting is ${byKey[0] ?? 'nothing'}`);
    } else {
        const at = byKey.indexOf(s.k);
        check(at >= 0 && at < MAX_PER_GROUP, `key ${s.k}: match ${at + 1}, not among the ${MAX_PER_GROUP} shown`);
    }

    // By its label, its old names and its help: among the matches, visible or behind "See all".
    const queries: Array<[string, string]> = [['label', s.t], ...(s.o ?? []).map((o): [string, string] => ['old name', o])];
    if (s.h) queries.push(['help', s.h]);
    for (const e of s.e ?? []) queries.push(['English text', e]);

    for (const [what, query] of queries) {
        const all = settingsFor(query);
        const at = all.indexOf(s.k);
        check(at >= 0, `${what} "${query.slice(0, 60)}" does not find ${s.k}`);

        if (at >= MAX_PER_GROUP) {
            check(visibleResults(index, query).more !== null, `${what} "${query.slice(0, 60)}": ${s.k} is match ${at + 1} and there is no "See all" row`);
        }

        if (what === 'label' && labels.get(s.t.toLowerCase()) === 1) {
            check(at >= 0 && at < MAX_PER_GROUP, `label "${s.t}": ${s.k} is match ${at + 1}, not among the ${MAX_PER_GROUP} shown`);
        }
    }

    check(!!s.u, `${s.k} has an address`);
}

// By the name of its card ("Admin access lock", "Sign-up & login", "Payment methods"): every setting of the card is
// found, one of them among the six shown, the rest visible or behind "See all"; never "Nothing found".
const cards = new Map<string, string[]>();
for (const s of index.settings) {
    if (!s.c) continue;
    const id = `${s.p}\u0000${s.c}`;
    cards.set(id, [...(cards.get(id) ?? []), s.k]);
}
for (const [id, keys] of cards) {
    const title = id.split('\u0000')[1];
    const all = settingsFor(title);
    const missing = keys.filter((k) => !all.includes(k));
    check(missing.length === 0, `card "${title}": ${missing.join(', ')} not found`);
    check(keys.some((k) => all.indexOf(k) >= 0 && all.indexOf(k) < MAX_PER_GROUP), `card "${title}": none of its settings is among the ${MAX_PER_GROUP} shown`);
    if (all.length > MAX_PER_GROUP) check(visibleResults(index, title).more !== null, `card "${title}": no "See all" row for ${all.length} settings`);
}

// A developer setting found while Developer mode is off says so; nothing is "Nothing found".
const developer = index.settings.filter((s) => s.x === 1);
for (const s of developer) {
    const found = searchIndex(index, s.k).find((r) => r.key === s.k);
    check(found?.badge === (index.texts?.developer ?? 'Developer mode'), `${s.k} carries the Developer mode badge`);
}

// The same settings as the search of Settings, for every query the PHP test sent.
let compared = 0;
if (checksFile) {
    const data = JSON.parse(readFileSync(checksFile, 'utf8')) as {boundary: string; checks: Array<{q: string; keys: string[]}>};
    check(data.boundary === BOUNDARY, `the word boundaries differ: PHP ${JSON.stringify(data.boundary)}, search.ts ${JSON.stringify(BOUNDARY)}`);

    for (const {q, keys} of data.checks) {
        compared++;
        const found = settingsFor(q);
        const mine = new Set(found);
        const theirs = new Set(keys);
        const onlyHere = [...mine].filter((k) => !theirs.has(k));
        const onlyThere = [...theirs].filter((k) => !mine.has(k));
        check(onlyHere.length === 0 && onlyThere.length === 0,
            `"${q.slice(0, 60)}": Ctrl+K ${mine.size}, Settings ${theirs.size}; only Ctrl+K: ${onlyHere.slice(0, 4).join(', ')}; only Settings: ${onlyThere.slice(0, 4).join(', ')}`);
        // ... in the same order (1.2.3 round 1, R1-155: "smtp", "premium", "trial" and "custom code" put another setting
        // first): Enter in Ctrl+K opens the setting Settings lists first.
        check(found[0] === keys[0], `"${q.slice(0, 60)}": Ctrl+K lists ${found[0] ?? 'nothing'} first, Settings ${keys[0] ?? 'nothing'}`);
        if (onlyHere.length === 0 && onlyThere.length === 0) {
            const at = found.findIndex((key, i) => key !== keys[i]);
            check(at === -1, `"${q.slice(0, 60)}": the order differs from match ${at + 1} (Ctrl+K ${found[at]}, Settings ${keys[at]})`);
        }

        // The count of "See all N settings" is the number Settings lists (the row shows past six settings).
        if (keys.length > MAX_PER_GROUP) {
            const {more, total} = visibleResults(index, q, 'More options', (count) => `See all ${count} settings`);
            check(total === keys.length && more?.title === `See all ${keys.length} settings`, `"${q.slice(0, 60)}": "See all" says ${more?.title ?? 'nothing'}, Settings lists ${keys.length}`);
        } else {
            check(found.length === keys.length, `"${q.slice(0, 60)}": Ctrl+K lists ${found.length}, Settings ${keys.length}`);
        }
    }
}

console.log(`admin-search: ${passed} passed, ${failures} failed (${index.settings.length} settings, ${developer.length} need Developer mode, ${cards.size} cards, ${compared} queries compared with Settings, locale ${index.locale ?? '?'})`);
process.exit(failures === 0 ? 0 : 1);
