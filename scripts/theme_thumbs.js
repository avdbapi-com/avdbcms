#!/usr/bin/env node
// Gallery pictures of the admin's Appearance > Themes page, rendered from the REAL built site (never from mockups):
//   themes/<theme>-<dark|light>.webp        640 x 400  home page, /?theme=<id>&theme_mode=<mode>
//   themes/<theme>-<dark|light>-phone.webp  180 x 390  the same on a phone
//   <look>.webp + <look>-phone.webp                    the 12 Complete looks, through the admin's own draft preview
//   fonts/<pairing>.webp                    320 x 80   heading + text of each font pairing (the admin never loads fonts)
//   manifest.json                                      file => size + hash (AppearanceController reads it)
// into public/images/admin/looks/ (<= 3 MB in total: the quality steps down until it fits).
//
// Release step: run it against a seeded install of the FINAL build, then commit the pictures.
//   PWPATH=<path to playwright> PLAYWRIGHT_BROWSERS_PATH=<browsers> ADMIN_EMAIL=<admin> ADMIN_PW_FILE=<file with the password> \
//   node scripts/theme_thumbs.js --base http://127.0.0.1:8118 [--only themes,looks,fonts] [--themes tokyo_neon,broadsheet] [--quality 0.62]
// Looks and fonts need the admin login (the draft preview and the pairing list are admin-only); without it only the
// themes are rendered. The password is read from the file and never printed. Looks are drawn through that admin's
// own draft, so they show the signed-in header, and the script stops when the admin has unpublished changes
// (it would replace them) unless --discard-draft is given.

import crypto from 'node:crypto';
import fs from 'node:fs';
import {createRequire} from 'node:module';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

// The project's package.json says "type": "module"; Playwright is loaded from PWPATH (it is not a dependency).
const require = createRequire(import.meta.url);
const __dirname = path.dirname(fileURLToPath(import.meta.url));

const args = process.argv.slice(2);
const arg = (name, fallback = '') => {
    const i = args.findIndex((a) => a === `--${name}` || a.startsWith(`--${name}=`));
    if (i < 0) return fallback;
    return args[i].includes('=') ? args[i].slice(args[i].indexOf('=') + 1) : (args[i + 1] ?? fallback);
};

const ROOT = path.resolve(__dirname, '..');
const BASE = (arg('base') || process.env.BASE || 'http://127.0.0.1:8000').replace(/\/$/, '');
const OUT = path.resolve(arg('out', path.join(ROOT, 'public/images/admin/looks')));
const ONLY = (arg('only', 'themes,looks,fonts')).split(',').filter(Boolean);
const PICK = (arg('themes', '')).split(',').filter(Boolean);
const BUDGET = Number(arg('budget', String(3 * 1024 * 1024)));
const DISCARD_DRAFT = args.includes('--discard-draft');
let quality = Number(arg('quality', '0.62'));

const SIZES = {
    desktop: {viewport: {width: 1280, height: 800}, out: [640, 400]},
    phone: {viewport: {width: 390, height: 844}, out: [180, 390]},
    font: {viewport: {width: 640, height: 160}, out: [320, 80]},
};

let pw;
try {
    pw = require(process.env.PWPATH || 'playwright');
} catch (e) {
    console.error('Playwright not found: set PWPATH to its folder (e.g. .tmp-playwright/node_modules/playwright).');
    process.exit(2);
}

const log = (...a) => console.log('[theme_thumbs]', ...a);
const host = new URL(BASE).host;

// Assets are the same for every theme: keep them in memory so only the themed documents hit the server.
const cache = new Map();
const cacheable = /\/build\/|\/images\/|\/storage\/|\/users\/[^/?]+\/(avatar|banner)|\/api\/videos|fonts\.(googleapis|gstatic)\.com|\.(svg|png|jpe?g|webp|woff2?|css|js)(\?|$)/;

async function route(rt) {
    const req = rt.request();
    const url = req.url();
    let target = '';
    try { target = new URL(url).host; } catch (e) { /* data: */ }

    // Only the site and its fonts: ads, analytics and embeds would make the pictures differ from run to run.
    if (target && target !== host && !/fonts\.(googleapis|gstatic)\.com$/.test(target)) return rt.abort().catch(() => {});
    if (req.method() !== 'GET' || req.resourceType() === 'document' || !cacheable.test(url)) return rt.continue().catch(() => {});

    let hit = cache.get(url);
    if (!hit) {
        try {
            const resp = await rt.fetch({timeout: 45000});
            const headers = {...resp.headers()};
            delete headers['set-cookie'];
            hit = {status: resp.status(), headers, body: await resp.body()};
            if (hit.status === 200) cache.set(url, hit);
        } catch (e) {
            return rt.abort().catch(() => {});
        }
    }

    return rt.fulfill(hit).catch(() => {});
}

async function settle(page) {
    await page.waitForLoadState('load', {timeout: 45000}).catch(() => {});
    await page.waitForLoadState('networkidle', {timeout: 5000}).catch(() => {});
    await page.evaluate(async () => {
        try { await Promise.race([document.fonts.ready, new Promise((r) => setTimeout(r, 5000))]); } catch (e) { /* */ }
        const visible = [...document.images].filter((i) => { const b = i.getBoundingClientRect(); return b.bottom > 0 && b.top < innerHeight && b.width > 0; });
        await Promise.race([
            Promise.all(visible.map((i) => (i.complete ? null : new Promise((r) => { i.addEventListener('load', r, {once: true}); i.addEventListener('error', r, {once: true}); })))),
            new Promise((r) => setTimeout(r, 6000)),
        ]);
    }).catch(() => {});
    // The draft banner is for the admin, never part of the picture.
    await page.addStyleTag({content: '#avd-draft-banner{display:none!important}'}).catch(() => {});
    await page.waitForTimeout(700);
}

// WebP in the browser itself (canvas), so the script needs nothing but Playwright.
async function encode(encoder, png, [w, h], q) {
    const data = await encoder.evaluate(async ({src, w, h, q}) => {
        const img = new Image();
        img.src = src;
        await img.decode();
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        // Cover: scale to the width, keep the top of the page.
        const scale = w / img.naturalWidth;
        ctx.drawImage(img, 0, 0, img.naturalWidth, Math.min(img.naturalHeight, h / scale), 0, 0, w, Math.min(h, img.naturalHeight * scale));
        return canvas.toDataURL('image/webp', q);
    }, {src: 'data:image/png;base64,' + png.toString('base64'), w, h, q});

    return Buffer.from(data.split(',')[1], 'base64');
}

async function login(ctx, page) {
    const email = process.env.ADMIN_EMAIL;
    const file = process.env.ADMIN_PW_FILE;
    if (!email || !file || !fs.existsSync(file)) return false;

    const password = fs.readFileSync(file, 'utf8').trim();
    await page.goto(BASE + '/login', {waitUntil: 'domcontentloaded', timeout: 60000});
    const form = await page.evaluate(() => {
        const v = (n) => (document.querySelector(`[name="${n}"]`) || {}).value || '';
        const answer = document.querySelector('#captcha_answer, [name=captcha_answer]');
        let scope = answer;
        for (let i = 0; i < 5 && scope && scope.parentElement; i++) scope = scope.parentElement;
        const m = ((scope ? scope.innerText : '') || '').match(/(\d+)\s*([+\-x*×])\s*(\d+)/);
        const sum = m ? String(m[2] === '+' ? +m[1] + +m[3] : m[2] === '-' ? +m[1] - +m[3] : +m[1] * +m[3]) : '';
        return {_token: v('_token'), captcha_id: v('captcha_id'), captcha_answer: sum, website: ''};
    });
    await ctx.request.post(BASE + '/login', {form: {...form, username: email, password, remember: 'on'}, maxRedirects: 0, failOnStatusCode: false});
    const admin = await page.goto(BASE + '/admin/appearance', {waitUntil: 'domcontentloaded', timeout: 120000});

    return !!admin && admin.status() === 200 && !/\/login/.test(page.url());
}

async function adminJson(ctx, page, method, url, body) {
    const token = await page.evaluate(() => (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
    const resp = await ctx.request.fetch(BASE + url, {
        method,
        headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest'},
        data: body ? JSON.stringify(body) : undefined,
        failOnStatusCode: false,
    });

    return resp.json().catch(() => ({}));
}

(async () => {
    fs.mkdirSync(path.join(OUT, 'themes'), {recursive: true});
    fs.mkdirSync(path.join(OUT, 'fonts'), {recursive: true});

    const browser = await pw.chromium.launch();
    const shots = []; // {name, png, size}
    const guest = await browser.newContext({viewport: SIZES.desktop.viewport, reducedMotion: 'reduce'});
    await guest.route('**/*', route);
    const page = await guest.newPage();
    const phoneCtx = await browser.newContext({viewport: SIZES.phone.viewport, isMobile: true, hasTouch: true, reducedMotion: 'reduce'});
    await phoneCtx.route('**/*', route);
    const phone = await phoneCtx.newPage();

    // The theme list comes from the public token files the site links (data-avd-theme of every ?theme= page would
    // need the list first): read it from the build manifest.
    const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'public/build/manifest.json'), 'utf8'));
    const themes = [...new Set(Object.keys(manifest).map((k) => (k.match(/^resources\/css\/themes\/([a-z0-9_]+)\.css$/) || [])[1]).filter(Boolean))].sort();
    const picked = PICK.length ? themes.filter((t) => PICK.includes(t)) : themes;

    if (ONLY.includes('themes')) {
        for (const theme of picked) {
            for (const mode of ['dark', 'light']) {
                const query = `/?theme=${theme}&theme_mode=${mode}`;
                await page.goto(BASE + query + '&avd_tier=rich', {waitUntil: 'domcontentloaded', timeout: 120000});
                await settle(page);
                shots.push({name: `themes/${theme}-${mode}`, png: await page.screenshot(), size: SIZES.desktop.out});
                await phone.goto(BASE + query + '&avd_tier=balanced', {waitUntil: 'domcontentloaded', timeout: 120000});
                await settle(phone);
                shots.push({name: `themes/${theme}-${mode}-phone`, png: await phone.screenshot(), size: SIZES.phone.out});
                log('theme', theme, mode);
            }
        }
    }

    // Looks and fonts: through the admin (the look is shown with the draft preview of the admin's account).
    const adminCtx = await browser.newContext({viewport: SIZES.desktop.viewport, reducedMotion: 'reduce'});
    await adminCtx.route('**/*', route);
    const admin = await adminCtx.newPage();
    const loggedIn = (ONLY.includes('looks') || ONLY.includes('fonts')) && await login(adminCtx, admin);

    if (!loggedIn && (ONLY.includes('looks') || ONLY.includes('fonts'))) {
        log('no admin login (ADMIN_EMAIL / ADMIN_PW_FILE): looks and fonts skipped');
    }

    // The looks replace the admin's draft: never throw away unpublished changes without being told to.
    let drawLooks = loggedIn && ONLY.includes('looks');
    if (drawLooks && !DISCARD_DRAFT) {
        const current = await adminJson(adminCtx, admin, 'POST', '/admin/appearance/draft', {});
        if ((current.count || 0) > 0) {
            console.error(`[theme_thumbs] the admin has ${current.count} unpublished Appearance change(s): publish or discard them first, or run again with --discard-draft. Looks skipped.`);
            drawLooks = false;
        }
    }

    if (drawLooks) {
        const looks = (await adminJson(adminCtx, admin, 'GET', '/admin/appearance/gallery?tab=looks')).items || [];
        // Phone pictures come from a phone (touch, mobile viewport) signed in as the same admin.
        const adminPhoneCtx = await browser.newContext({viewport: SIZES.phone.viewport, isMobile: true, hasTouch: true, reducedMotion: 'reduce', storageState: await adminCtx.storageState()});
        await adminPhoneCtx.route('**/*', route);
        const adminPhone = await adminPhoneCtx.newPage();

        for (const look of looks) {
            const draft = await adminJson(adminCtx, admin, 'POST', '/admin/appearance/draft', {look: look.id});
            const url = draft.token ? `/?avd_draft=${encodeURIComponent(draft.token)}` : `/?theme=${look.theme}`;
            await admin.goto(BASE + url + '&avd_tier=rich', {waitUntil: 'domcontentloaded', timeout: 120000});
            await settle(admin);
            shots.push({name: look.id, png: await admin.screenshot(), size: SIZES.desktop.out});
            await adminPhone.goto(BASE + url + '&avd_tier=balanced', {waitUntil: 'domcontentloaded', timeout: 120000});
            await settle(adminPhone);
            shots.push({name: `${look.id}-phone`, png: await adminPhone.screenshot(), size: SIZES.phone.out});
            log('look', look.id, draft.token ? '(draft preview)' : '(theme only)');
        }

        await adminJson(adminCtx, admin, 'DELETE', '/admin/appearance/draft');
        await adminPhoneCtx.close();
    }

    if (loggedIn && ONLY.includes('fonts')) {
        const fonts = (await adminJson(adminCtx, admin, 'GET', '/admin/appearance/gallery?tab=fonts')).items || [];
        const sample = await browser.newPage({viewport: SIZES.font.viewport});

        for (const font of fonts) {
            const esc = (v) => String(v).replace(/[&<>"]/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            const html = `<!doctype html><html><head>${font.css ? `<link rel="stylesheet" href="${esc(font.css)}">` : ''}<style>
                html,body{margin:0;background:#fff;color:#16191e}
                body{padding:18px 26px;box-sizing:border-box;height:160px;overflow:hidden}
                h1{font:700 44px/1.1 '${esc(font.display)}',system-ui,sans-serif;margin:0 0 10px;white-space:nowrap}
                p{font:400 24px/1.3 '${esc(font.body)}',system-ui,sans-serif;margin:0;color:#4a515c;white-space:nowrap}
            </style></head><body><h1>${esc(font.display || font.name)} Aa</h1><p>${esc(font.body || font.name)} - The quick brown fox jumps</p></body></html>`;

            // The font CSS comes from the web: try twice before taking the picture with what loaded.
            for (let attempt = 0; attempt < 2; attempt++) {
                await sample.setContent(html, {waitUntil: 'load', timeout: 60000}).catch(() => {});
                const ready = await sample.evaluate(async (families) => {
                    try { await Promise.race([document.fonts.ready, new Promise((r) => setTimeout(r, 8000))]); } catch (e) { /* */ }
                    return families.every((family) => !family || document.fonts.check(`16px '${family}'`)) && document.body.innerText.trim() !== '';
                }, [font.display, font.body]).catch(() => false);
                if (ready) break;
            }
            await sample.waitForTimeout(200);
            shots.push({name: `fonts/${font.id}`, png: await sample.screenshot(), size: SIZES.font.out});
            log('font', font.id);
        }
    }

    // Encode, stepping the quality down until everything fits the budget.
    const encoder = await browser.newPage();
    let files = [];

    for (;;) {
        files = [];
        for (const shot of shots) files.push({name: shot.name + '.webp', data: await encode(encoder, shot.png, shot.size, quality), size: shot.size});
        const existing = fs.existsSync(path.join(OUT, 'manifest.json')) ? JSON.parse(fs.readFileSync(path.join(OUT, 'manifest.json'), 'utf8')).files || {} : {};
        const kept = Object.entries(existing).filter(([name]) => !files.some((f) => f.name === name)).reduce((n, [, f]) => n + (f.bytes || 0), 0);
        const total = files.reduce((n, f) => n + f.data.length, 0) + kept;
        log(`quality ${quality.toFixed(2)}: ${(total / 1024).toFixed(0)} KB`);
        if (total <= BUDGET || quality <= 0.3) break;
        quality = Math.round((quality - 0.06) * 100) / 100;
    }

    const manifestFile = path.join(OUT, 'manifest.json');
    const old = fs.existsSync(manifestFile) ? JSON.parse(fs.readFileSync(manifestFile, 'utf8')) : {files: {}};
    const entries = {...(old.files || {})};

    for (const file of files) {
        fs.mkdirSync(path.dirname(path.join(OUT, file.name)), {recursive: true});
        fs.writeFileSync(path.join(OUT, file.name), file.data);
        entries[file.name] = {w: file.size[0], h: file.size[1], bytes: file.data.length, hash: crypto.createHash('sha256').update(file.data).digest('hex').slice(0, 16)};
    }

    const total = Object.values(entries).reduce((n, f) => n + f.bytes, 0);
    fs.writeFileSync(manifestFile, JSON.stringify({generated: new Date().toISOString(), quality, total_bytes: total, files: Object.fromEntries(Object.entries(entries).sort())}, null, 1) + '\n');
    log(`${files.length} pictures written, ${Object.keys(entries).length} in the manifest, ${(total / 1024).toFixed(0)} KB in total`);

    await browser.close();
    if (total > BUDGET) {
        console.error(`[theme_thumbs] over the ${(BUDGET / 1024).toFixed(0)} KB budget`);
        process.exit(1);
    }
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
