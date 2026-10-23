// Machine translation of interface strings for lang/<locale>.json and resources/js/lang/<locale>.ts
// (google-translate-api-x).
//
//   php scripts/i18n/sync.php --todo=storage/framework/i18n              # 1. fallbacks + <locale>.todo.json
//   node scripts/translate_engine.mjs [--root DIR] [--work DIR] [--locales ar,de]   # 2. -> <locale>.mt.json
//   php scripts/i18n/sync.php --from=storage/framework/i18n --accept-english   # 3. merge (placeholders re-checked)
//   php scripts/i18n_check.php --min-coverage=95
//
// --root  target project (default: the project this script belongs to; env AVD_I18N_ROOT)
// --work  folder with the todo lists, receives <locale>.mt.json + <locale>.failed.json (default <root>/storage/framework/i18n)
// Only the keys of the todo lists are sent (interface strings from lang/ and the source code - never user content).
// Placeholders (:name, {word}, HTML tags, &entities;) and technical text (URLs, paths, file names, commands, snake_case
// names, language-code lists) are swapped for numbered markers before sending and verified after; a string whose
// markers do not come back is left out (it keeps its fallback) and listed in <locale>.failed.json.
// Brand and product names of scripts/i18n/brands.json are masked the same way (they come back verbatim).
// trans_choice() lines ("1 video|:count videos") are translated form by form with a sample number and written in the
// shape the locale's plural rule needs (pluralLine); a form for 1 that keeps neither the digit nor a number word of
// scripts/i18n/number_words.json goes to <locale>.failed.json for hand translation; sync.php re-checks the lines with
// Plurals::wrongNumbers() and scripts/i18n_check.php with Plurals::lostOne().
// Vietnamese is reviewed by hand and is skipped unless named in --locales.
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import translate from 'google-translate-api-x';

const SCRIPT_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

// lang file code -> Google Translate code
export const LANG_MAP = {
    ar: 'ar', de: 'de', es: 'es', fil: 'tl', fr: 'fr', hi: 'hi', id: 'id', ja: 'ja', ko: 'ko',
    ms: 'ms', pt: 'pt', ru: 'ru', th: 'th', tl: 'tl', tr: 'tr', vi: 'vi', zh: 'zh-CN',
};

// Same token rules as scripts/i18n/I18n.php (Placeholders::PATTERN): Laravel :name replacements (also camelCase,
// :repliesCount), {word} template words, HTML tags and entities, printf markers.
const TOKEN = /<\/?[a-zA-Z][^<>]*>|&[a-zA-Z]+;|&#\d+;|\{[A-Za-z_][A-Za-z0-9_.]*\}|\{\d+\}|(?<![\w:/]):[A-Za-z_][A-Za-z0-9_]*|%(?:\d+\$)?[sd]/g;
// Technical text the translator must leave alone either; it is sent masked and must come back like a placeholder:
// URLs, e-mail addresses, paths and file names (storage/logs/mail.log, /admin/settings, ads.txt, .env), artisan and
// other commands (php artisan queue:work, schedule:run), snake_case names (movie_code), CLI flags (--force),
// language-code lists after "e.g." / "Examples:" ("e.g. vi, fr, ja"), emoji and flags, HTTP header names and values.
const FILE_EXT = 'txt|xml|php|env|json|css|js|ts|log|sql|zip|png|svg|webp|jpe?g|gif|ico|html|ini|conf|htaccess|m3u8|mp4|woff2|csv';
const TECH = new RegExp([
    String.raw`https?:\/\/[^\s"'<>)]+`,
    String.raw`[\w.+-]+@[\w-]+(?:\.[\w-]+)+`,
    String.raw`\bphp artisan [\w:-]+(?: --?[\w=-]+)*`,
    String.raw`(?<![\w/:.])\/[a-z0-9_{}-]+(?:[.\/][a-z0-9_{}-]+)*\/?`,
    String.raw`\b(?:[\w.-]+\/)*[\w-]+\.(?:${FILE_EXT})\b`,
    String.raw`\b(?:storage|public|app|resources|bootstrap|config|database|routes|lang|vendor)\/[\w-]+(?:[.\/][\w-]+)*`,
    String.raw`(?<![\w.])\.env\b`,
    String.raw`\b[a-z]+:[a-z][a-z-]+\b`,
    String.raw`\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b`,
    String.raw`(?<![\w-])--[a-z][a-z-]+(?:=[\w-]+)?`,
    String.raw`(?<=(?:\be\.g\.|\b[Ee]xamples?:|\bsuch as)\s*)[a-z]{2,3}(?:-[A-Za-z]{2,4})?(?:\s*,\s*[a-z]{2,3}(?:-[A-Za-z]{2,4})?)+\b`,
    String.raw`(?:[\uD83C-\uD83E][\uDC00-\uDFFF]\uFE0F?)+`, // emoji and flags (pairs of regional indicators)
    String.raw`\bX-[A-Z][A-Za-z]*(?:-[A-Z][A-Za-z]*)*(?:-\*)?|\b(?:Strict-Transport-Security|Referrer-Policy|Content-Security-Policy|Permissions-Policy|Access-Control-[A-Za-z-]+)\b|\b(?:nosniff|SAMEORIGIN|DENY)\b`, // HTTP headers
].join('|'), 'g');
const IGNORED = new Set(['{braces}']);

// Protected brand and product names (scripts/i18n/brands.json, the list Brands::lost() in I18n.php checks): masked like
// TECH so the service cannot translate them ("Stripe" -> "stripe/band", "Upload18" -> "upload 18"). Global names are
// masked everywhere, per-key names ("Horizon" = Laravel Horizon) only in their keys. Longest names first.
let BRANDS = { global: [], keys: {} };
const escapeRx = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const brandRx = (names) => (names.length
    ? new RegExp(`(?<![A-Za-z0-9])(?:${[...names].sort((a, b) => b.length - a.length).map(escapeRx).join('|')})(?![A-Za-z0-9])`, 'g')
    : null);
export function loadBrands(root) {
    const doc = readJson(path.join(root, 'scripts', 'i18n', 'brands.json'), {});
    BRANDS = { global: Object.keys(doc.brands || {}), keys: doc.keys || {} };
    return BRANDS;
}
/** The brand pattern for one key (global names + that key's own), null when there are none. */
function brandsFor(key) {
    const extra = key && BRANDS.keys[key] ? BRANDS.keys[key] : [];
    return brandRx([...new Set([...BRANDS.global, ...extra])]);
}
const MAX_CHARS = 3500;
const MAX_ITEMS = 60;

export function options(argv = process.argv.slice(2)) {
    const out = {};
    for (let i = 0; i < argv.length; i++) {
        const a = argv[i];
        if (!a.startsWith('--')) continue;
        const [k, v] = a.slice(2).split(/=(.*)/s);
        if (v !== undefined) out[k] = v;
        else if (argv[i + 1] && !argv[i + 1].startsWith('--')) out[k] = argv[++i];
        else out[k] = true;
    }
    const root = path.resolve(out.root || process.env.AVD_I18N_ROOT || SCRIPT_ROOT);
    if (!fs.existsSync(path.join(root, 'lang', 'en.json'))) {
        throw new Error(`Not a project root (no lang/en.json): ${root}`);
    }
    const work = path.resolve(out.work || path.join(root, 'storage', 'framework', 'i18n'));
    const locales = typeof out.locales === 'string' ? out.locales.split(',').map((s) => s.trim()).filter(Boolean) : null;
    return { root, work, locales, raw: out };
}

// Laravel plural syntax: explicit "{0} none|{1} one|[2,*] many" or implicit "1 video|:count videos" (translated by
// pluralLine() below into forms that follow each language's plural rule). Same rule as Placeholders::plural().
export function isPlural(text) {
    return text.includes('|') && /(?:^|\|)\s*(?:\{\d+\}|\[\d+\s*,\s*(?:\d+|\*)\])|(?<![\w:/]):count(?![A-Za-z0-9_])/.test(text);
}

// Laravel's MessageSelector::getPluralIndex() for the project's locales: which implicit form trans_choice() prints.
export function pluralIndex(locale, n) {
    switch (locale) {
        case 'de': case 'en': case 'es': case 'pt':
            return n === 1 ? 0 : 1;
        case 'fil': case 'fr': case 'hi':
            return n === 0 || n === 1 ? 0 : 1;
        case 'ru':
            return n % 10 === 1 && n % 100 !== 11 ? 0 : (n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20) ? 1 : 2);
        case 'ar':
            return n === 0 ? 0 : n === 1 ? 1 : n === 2 ? 2 : (n % 100 >= 3 && n % 100 <= 10 ? 3 : (n % 100 >= 11 && n % 100 <= 99 ? 4 : 5));
        default: // id, ja, ko, ms, th, tl, tr, vi, zh: one form
            return 0;
    }
}

const HAS_COUNT = /(?<![\w:/]):count(?![A-Za-z0-9_])/;
const ALL_COUNT = /(?<![\w:/]):count(?![A-Za-z0-9_])/g;

/** [{range: '{1}' | '[0,*]' | null, text}] of a Laravel plural line. */
export function pluralSegments(line) {
    return line.split('|').map((part) => {
        const m = part.match(/^\s*(\{\d+\}|\[\d+\s*,\s*(?:\d+|\*)\])\s*(.*)$/s);
        return m ? { range: m[1], text: m[2] } : { range: null, text: part.trim() };
    });
}

// Words that say "one" per locale (scripts/i18n/number_words.json, shared with Plurals::lostOne in I18n.php).
let NUMBER_WORDS = {};
export function loadNumberWords(root) {
    NUMBER_WORDS = readJson(path.join(root, 'scripts', 'i18n', 'number_words.json'), {}).locales || {};
    return NUMBER_WORDS;
}
/** The text says "one" with a number word of the locale (Latin words stand alone, other scripts may be inside a word). */
export function saysOne(text, locale) {
    return (NUMBER_WORDS[locale] || []).some((w) => (/^\p{Script=Latin}+$/u.test(w)
        ? new RegExp(`(?<!\\p{L})${escapeRx(w)}(?!\\p{L})`, 'iu').test(text)
        : text.toLowerCase().includes(w.toLowerCase())));
}

// One form: its :count is sent as a sample number, so the service inflects the noun for that number ("21 комментарий",
// "3 комментария"), then the number is turned back into :count. `exact`: the form stands for this one number only
// (the Arabic dual: "يومين" = two days), so a translation may say it without the digit - for 1 only when it says "one"
// with a number word of the language (saysOne): the service reads a bare sample "1" as a subject ("1 found nothing." ->
// "I found nothing."). A form that fails is left out (failed list) for hand translation.
async function translateForm(text, number, translateOne, exact = false, locale = '') {
    if (!HAS_COUNT.test(text)) {
        return translateOne(text);
    }
    const out = await translateOne(text.replace(ALL_COUNT, String(number)));
    // Arabic-Indic, Persian, Devanagari, Thai and full-width digits stand for the same number
    const ascii = out.replace(/[\u0660-\u0669\u06F0-\u06F9\u0966-\u096F\u0E50-\u0E59\uFF10-\uFF19]/g, (d) => {
        const c = d.charCodeAt(0);
        return String(c - (c >= 0xFF10 ? 0xFF10 : c >= 0x0E50 ? 0x0E50 : c >= 0x0966 ? 0x0966 : c >= 0x06F0 ? 0x06F0 : 0x0660));
    });
    // the number, not part of a longer one ("3" in "13", "3,5", "1.234")
    const rx = new RegExp(`(?<!\\d|\\d[.,])${number}(?!\\d|[.,]\\d)`);
    if (!rx.test(ascii)) {
        if (exact && !/\d/.test(ascii) && (number !== 1 || saysOne(out, locale))) return out;
        throw new Error(`the number ${number} was lost${number === 1 ? ' (no number word for 1: translate by hand)' : ''}: ${out}`);
    }
    return ascii.replace(rx, ':count');
}

// The English "one" form as written ("1 found nothing.", or "Searched once" without a digit): a form with no digit and no
// :count is translated as plain text; with a literal "1" it gets the same check as a :count form.
async function translateOneForm(text, translateOne, locale) {
    if (HAS_COUNT.test(text)) return translateForm(text, 1, translateOne, true, locale);
    if (!/(?<![\d.,])1(?![\d.,]?\d)/.test(text)) return translateOne(text);
    const out = await translateOne(text);
    const ascii = out.replace(/[٠-٩۰-۹०-९๐-๙０-９]/g, (d) => {
        const c = d.charCodeAt(0);
        return String(c - (c >= 0xFF10 ? 0xFF10 : c >= 0x0E50 ? 0x0E50 : c >= 0x0966 ? 0x0966 : c >= 0x06F0 ? 0x06F0 : 0x0660));
    });
    if (/(?<![\d.,])1(?![\d.,]?\d)/.test(ascii) || saysOne(out, locale)) return out;
    throw new Error(`the number 1 was lost (no number word for 1: translate by hand): ${out}`);
}

/**
 * A trans_choice() line for one locale that prints right for every number (Plurals::wrongNumbers in I18n.php):
 *   English intervals ("{0} ...|{1} ...|[2,*] ...") keep their intervals, each text translated for a number of it;
 *   one|other lines become "{1} <one>|[0,*] <other>" where the language has one or two forms (de, es, fr, hi, id, ja,
 *   ko, ms, pt, th, tl/fil, tr, zh), and the implicit forms of Laravel's own rule in ru (1/21, 3, 5) and ar (0, 1, 2,
 *   3, 11, 100), every form that stands for several numbers saying :count.
 * `translateOne(text)` translates one plain string (placeholders masked and checked by the caller).
 */
export async function pluralLine(english, locale, translateOne) {
    const parts = pluralSegments(english);
    if (parts.some((p) => p.range)) {
        const out = [];
        for (const p of parts) {
            const bounds = p.range ? p.range.match(/\d+|\*/g) : ['7'];
            const lo = Number(bounds[0]);
            const hi = bounds[1] === undefined ? lo : (bounds[1] === '*' ? Infinity : Number(bounds[1]));
            const sample = lo <= 7 && hi >= 7 ? 7 : lo;
            const text = lo === 1 && hi === 1 ? await translateOneForm(p.text, translateOne, locale) : await translateForm(p.text, sample, translateOne);
            out.push(`${p.range ? p.range + ' ' : ''}${text}`);
        }
        return out.join('|');
    }
    if (parts.length !== 2) throw new Error('not a one|other plural line');
    const [one, other] = parts.map((p) => p.text);
    if (locale === 'ru') {
        const forms = [];
        for (const n of [21, 3, 5]) forms.push(await translateForm(other, n, translateOne));
        return forms.join('|');
    }
    if (locale === 'ar') {
        // Laravel's Arabic forms: 0 | 1 | 2 | 3-10 | 11-99 | 100-102 ... (by n % 100); 1 and 2 stand for one number each
        const forms = [await translateForm(other, 0, translateOne), await translateOneForm(one, translateOne, locale),
            await translateForm(other, 2, translateOne, true)];
        for (const n of [3, 11, 100]) forms.push(await translateForm(other, n, translateOne));
        return forms.join('|');
    }
    return `{1} ${await translateOneForm(one, translateOne, locale)}|[0,*] ${await translateForm(other, 7, translateOne)}`;
}

// "|" is a token outside plural strings ("Name | address" tells the owner what to type).
const TOKEN_WITH_PIPE = new RegExp(`${TOKEN.source}|\\|`, 'g');
const tokenRule = (text) => (isPlural(text) ? TOKEN : TOKEN_WITH_PIPE);
const MASK = new RegExp(`${TOKEN.source}|${TECH.source}`, 'g');
const MASK_WITH_PIPE = new RegExp(`${MASK.source}|\\|`, 'g');

/** The placeholders a translation must keep (what scripts/i18n_check.php compares). */
export function tokens(text) {
    return (text.match(tokenRule(text)) || []).filter((t) => !IGNORED.has(t)).sort();
}

/**
 * Swaps placeholders, technical text and protected brand names for {n} markers before the text is sent. `key` selects
 * the per-key brands of brands.json (default: the text itself, which is the key for most strings).
 */
export function tokenize(text, key = text) {
    const list = [];
    const masked = text.replace(isPlural(text) ? MASK : MASK_WITH_PIPE, (t) => {
        // illustrative wording ("Words in {braces} ...") is sent as a plain word so it is translated naturally
        if (IGNORED.has(t)) return t.slice(1, -1);
        list.push(t);
        return `{${list.length - 1}}`;
    });
    const brands = brandsFor(key);
    if (!brands) return { masked, list };
    // brands only in the plain text between markers, so a marker's own content is never split
    const out = masked.split(/(\{\d+\})/).map((part, i) => (i % 2 ? part : part.replace(brands, (b) => {
        list.push(b);
        return `{${list.length - 1}}`;
    }))).join('');
    return { masked: out, list };
}

export function detokenize(text, list) {
    let ok = true;
    const seen = new Set();
    // the service sometimes returns zero-width spaces (U+200B between Korean words): they are never part of the text
    const out = text.replace(/[\u200B\uFEFF]/g, '').replace(/[{｛]\s*(\d+)\s*[}｝]/g, (m, n) => {
        const i = Number(n);
        if (i >= list.length || seen.has(i)) {
            ok = false;
            return m;
        }
        seen.add(i);
        return list[i];
    });
    return { text: out.trim(), ok: ok && seen.size === list.length };
}

/**
 * scripts/i18n/glossary.json: UI jargon the service takes literally ("Pill", "Hero", "Disabled") -> the plain English
 * actually sent. Longest terms first, whole words, case-sensitive; applied to the masked text only.
 */
export function loadGlossary(root) {
    const terms = readJson(path.join(root, 'scripts', 'i18n', 'glossary.json'), {}).terms || {};
    return Object.entries(terms)
        .sort((a, b) => b[0].length - a[0].length)
        .map(([term, plain]) => [new RegExp(`(?<![\\w-])${term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?![\\w-])`, 'g'), plain]);
}

export function clarify(masked, glossary = []) {
    return glossary.reduce((text, [rx, plain]) => text.replace(rx, plain), masked);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function translateBatch(batch, to, retries = 4) {
    for (let attempt = 1; ; attempt++) {
        try {
            const res = await translate(batch, { from: 'en', to, forceTo: true, rejectOnPartialFail: false });
            return (Array.isArray(res) ? res : [res]).map((r) => (r && typeof r.text === 'string' ? r.text : null));
        } catch (err) {
            if (attempt >= retries) throw err;
            const wait = 2000 * attempt * attempt;
            console.warn(`  [${to}] attempt ${attempt} failed (${err.message}); retrying in ${wait / 1000}s`);
            await sleep(wait);
        }
    }
}

function batches(items, maxItems = MAX_ITEMS) {
    const out = [];
    let cur = [];
    let chars = 0;
    for (const item of items) {
        if (cur.length && (cur.length >= maxItems || chars + item.masked.length > MAX_CHARS)) {
            out.push(cur);
            cur = [];
            chars = 0;
        }
        cur.push(item);
        chars += item.masked.length + 1;
    }
    if (cur.length) out.push(cur);
    return out;
}

/**
 * Translates keys (English text = key; `sources` maps keys whose English text differs, e.g. plurals) into one
 * Google language. Returns { done: {key: text}, failed: {key: reason} }. `cache` (Map) is shared across locales
 * that use the same Google code (fil and tl).
 */
export async function translateKeys(keys, to, { sources = {}, cache = new Map(), delay = 300, label = to, maxItems = MAX_ITEMS, glossary = [], locale = to } = {}) {
    const done = {};
    const failed = {};
    const items = [];
    const plurals = [];
    for (const key of keys) {
        const source = sources[key] ?? key;
        if (isPlural(source)) {
            plurals.push({ key, source });
            continue;
        }
        const hit = cache.get(`${to}\u0000${source}`);
        if (hit) {
            done[key] = hit;
            continue;
        }
        const { masked, list } = tokenize(source, key);
        items.push({ key, source, masked: clarify(masked, glossary), list });
    }
    const groups = batches(items, maxItems);
    let n = 0;
    for (const group of groups) {
        let texts;
        try {
            texts = await translateBatch(group.map((i) => i.masked), to);
        } catch (err) {
            for (const rest of groups.slice(groups.indexOf(group))) {
                for (const item of rest) failed[item.key] = `service: ${err.message}`;
            }
            console.error(`  [${label}] stopped: ${err.message}`);
            break;
        }
        group.forEach((item, i) => {
            const raw = texts[i];
            if (!raw || !raw.trim()) {
                failed[item.key] = 'empty result';
                return;
            }
            const back = detokenize(raw, item.list);
            if (!back.ok || tokens(back.text).join('\u0000') !== tokens(item.source).join('\u0000')) {
                failed[item.key] = `placeholders lost: ${raw}`;
                return;
            }
            done[item.key] = back.text;
            cache.set(`${to}\u0000${item.source}`, back.text);
        });
        n += group.length;
        process.stdout.write(`\r  [${label}] ${n}/${items.length}`);
        await sleep(delay);
    }
    if (items.length) process.stdout.write('\n');
    if (plurals.length) {
        await translatePlurals(plurals, to, { done, failed, cache, delay, label, maxItems, glossary, locale });
    }
    return { done, failed };
}

/**
 * trans_choice() lines (pluralLine): pass 1 collects every form text with its sample number, the texts go out in
 * batches like other strings (placeholders and technical text masked and verified), pass 2 builds each line.
 */
async function translatePlurals(plurals, to, { done, failed, cache, delay, label, maxItems, glossary, locale }) {
    const need = new Set();
    const collect = async (text) => {
        need.add(text);
        return text;
    };
    const todo = [];
    for (const p of plurals) {
        const hit = cache.get(`${to}\u0000plural\u0000${p.source}`);
        if (hit) {
            done[p.key] = hit;
            continue;
        }
        try {
            await pluralLine(p.source, locale, collect);
            todo.push(p);
        } catch (err) {
            failed[p.key] = `plural: ${err.message}`;
        }
    }
    const texts = [...need].map((text) => {
        const { masked, list } = tokenize(text);
        return { text, masked: clarify(masked, glossary), list };
    });
    const translated = new Map();
    for (const group of batches(texts, maxItems)) {
        let out;
        try {
            out = await translateBatch(group.map((t) => t.masked), to);
        } catch (err) {
            console.error(`  [${label}] plural forms stopped: ${err.message}`);
            break;
        }
        group.forEach((t, i) => {
            const back = out[i] ? detokenize(out[i], t.list) : { ok: false };
            if (back.ok && tokens(back.text).join('\u0000') === tokens(t.text).join('\u0000')) {
                translated.set(t.text, back.text);
            }
        });
        await sleep(delay);
    }
    const lookup = async (text) => {
        if (!translated.has(text)) throw new Error(`no translation for "${text}"`);
        return translated.get(text);
    };
    for (const p of todo) {
        try {
            const line = await pluralLine(p.source, locale, lookup);
            done[p.key] = line;
            cache.set(`${to}\u0000plural\u0000${p.source}`, line);
        } catch (err) {
            failed[p.key] = `plural: ${err.message}`;
        }
    }
    console.log(`  [${label}] plural lines: ${todo.length - todo.filter((p) => failed[p.key]).length}/${plurals.length}`);
}

function readJson(file, fallback) {
    try {
        return JSON.parse(fs.readFileSync(file, 'utf8').replace(/^\uFEFF/, ''));
    } catch {
        return fallback;
    }
}

function writeJson(file, data) {
    fs.writeFileSync(file, JSON.stringify(data, null, 2) + '\n', 'utf8');
}

export function englishSources(root) {
    const en = readJson(path.join(root, 'lang', 'en.json'), {});
    const sources = {};
    for (const [k, v] of Object.entries(en)) if (v !== k) sources[k] = v;
    return sources;
}

/** Runs the todo lists of --work through MT. `pick(locale)` returns the keys to send (default: <locale>.todo.json). */
export async function run({ root, work, locales }, pick = null, tuning = {}) {
    console.log(`translate: root ${root} | work ${work}`);
    if (!fs.existsSync(work)) throw new Error(`No work folder ${work}: run php scripts/i18n/sync.php --todo=... first`);
    const sources = englishSources(root);
    const glossary = loadGlossary(root);
    loadBrands(root);
    loadNumberWords(root);
    const cache = new Map();
    const wanted = locales || Object.keys(LANG_MAP).filter((l) => l !== 'vi');
    const summary = [];
    for (const locale of wanted) {
        const to = LANG_MAP[locale];
        if (!to) {
            console.warn(`  skip ${locale}: no Google code`);
            continue;
        }
        const keys = pick ? pick(locale) : readJson(path.join(work, `${locale}.todo.json`), null);
        if (!Array.isArray(keys)) {
            console.warn(`  skip ${locale}: no ${locale}.todo.json in ${work}`);
            continue;
        }
        const t0 = Date.now();
        const { done, failed } = await translateKeys(keys, to, { sources, cache, glossary, label: locale, locale, ...tuning });
        const outFile = path.join(work, `${locale}.mt.json`);
        writeJson(outFile, { ...readJson(outFile, {}), ...done });
        writeJson(path.join(work, `${locale}.failed.json`), failed);
        summary.push(`${locale}: ${Object.keys(done).length} translated, ${Object.keys(failed).length} failed (${((Date.now() - t0) / 1000).toFixed(0)}s)`);
        console.log(`  ${summary[summary.length - 1]}`);
    }
    console.log(`done. next: php scripts/i18n/sync.php --root=${root} --from=${work}`);
    return summary;
}

const invokedDirectly = process.argv[1]
    && path.resolve(process.argv[1]).toLowerCase() === fileURLToPath(import.meta.url).toLowerCase();
if (invokedDirectly) {
    run(options()).catch((err) => {
        console.error(err.message);
        process.exit(1);
    });
}
