// Round 3 fix pass R3-018 and R2R-05: the start of every address the site's scripts build
// (resources/js/functions/sitePath.ts), run on its results:
//  - the comments' "Sign in" (signInPath) keeps /index.php on a server without rewrite rules (it was /login there: the
//    web server's 404 page) and the site's folder for a site installed in one (https://example.com/site), in the page's
//    language, coming back to the page as the browser addresses it;
//  - the API calls go through /index.php when the page came through it (as up to 1.2.3 RC2) and keep the folder;
//  - a file of the public folder (a picture, a sound) never gets /index.php;
//  - at a domain's root with clean addresses every address is exactly what 1.2.3 RC2 built, and a page printed by RC2
//    (no window.SITE_BASE) keeps RC2's rule.
// Run: node tests/js/site_path.test.ts  (Node 22.18+ runs TypeScript directly). Exit code 1 on any failure.
// tests/Unit/SitePathNodeTest.php runs this file when Node is available.

type FakeWindow = {SITE_BASE?: string; LOCALE_PREFIX?: string; location: {pathname: string; search: string}};
const page: FakeWindow = {location: {pathname: '/', search: ''}};
(globalThis as Record<string, unknown>).window = page;

const {siteBase, sitePath, localePath, siteFile, requestBase, currentPage, signInPath} = await import('../../resources/js/functions/sitePath.ts');

let failures = 0;
let passed = 0;
const check = (name: string, actual: unknown, expected: unknown): void => {
    if (JSON.stringify(actual) === JSON.stringify(expected)) {
        passed++;
    } else {
        failures++;
        console.error(`FAIL ${name}: got ${JSON.stringify(actual)}, expected ${JSON.stringify(expected)}`);
    }
};

const open = (siteBaseValue: string | undefined, localePrefix: string, pathname: string, search = ''): void => {
    if (siteBaseValue === undefined) {
        delete page.SITE_BASE;
    } else {
        page.SITE_BASE = siteBaseValue;
    }
    page.LOCALE_PREFIX = localePrefix;
    page.location = {pathname, search};
};

// What 1.2.3 RC2 built (resources/js/components/Comments/signInUrl.ts, functions/api.ts, Search/SearchBar.tsx).
const rc2SignIn = (): string => (page.LOCALE_PREFIX || '') + '/login?' + new URLSearchParams({redirect: page.location.pathname + page.location.search});
const rc2Api = (url: string): string => (page.location.pathname.includes('/index.php') ? '/index.php' + url : url);
const rc2Search = (): string => `${page.location.pathname.startsWith('/index.php') ? '/index.php' : ''}${page.LOCALE_PREFIX || ''}/search`;

// A domain's root, clean addresses: exactly RC2's addresses.
for (const [prefix, pathname, search] of [['', '/video/abc', '?t=12'], ['/fr', '/fr/video/abc', ''], ['/vi', '/vi', ''], ['', '/', '']] as Array<[string, string, string]>) {
    open('', prefix, pathname, search);
    check(`root ${pathname}: sign in as RC2`, signInPath(), rc2SignIn());
    check(`root ${pathname}: API as RC2`, requestBase() + '/api/videos/1', rc2Api('/api/videos/1'));
    check(`root ${pathname}: search as RC2`, localePath('/search'), rc2Search());
    check(`root ${pathname}: files as RC2`, siteFile('/images/placeholders/no-image.svg'), '/images/placeholders/no-image.svg');
}
open('', '', '/video/abc', '?t=12');
check('root: the sign-in address', signInPath(), '/login?redirect=%2Fvideo%2Fabc%3Ft%3D12');
check('root: this page', currentPage(), '/video/abc?t=12');

// A domain's root on a server without rewrite rules (the fleet's nodes 2/4/7 layout): R3-018.
open('/index.php', '/fr', '/index.php/fr/video/abc');
check('index.php: the comments sign in keeps /index.php', signInPath(), '/index.php/fr/login?redirect=%2Findex.php%2Ffr%2Fvideo%2Fabc');
check('index.php: RC2 dropped it (the defect)', rc2SignIn().startsWith('/fr/login'), true);
check('index.php: API as RC2', requestBase() + '/api/x', rc2Api('/api/x'));
check('index.php: search as RC2', localePath('/search'), rc2Search());
check('index.php: a file never gets /index.php', siteFile('/sounds/notification.mp3'), '/sounds/notification.mp3');
check('index.php: a site path', sitePath('/profile/notifications/1/click'), '/index.php/profile/notifications/1/click');

// A page printed by RC2 (no window.SITE_BASE): RC2's rule (its own address says /index.php).
open(undefined, '/fr', '/index.php/fr/video/abc');
check('no SITE_BASE, through /index.php', [siteBase(), signInPath()], ['/index.php', '/index.php/fr/login?redirect=%2Findex.php%2Ffr%2Fvideo%2Fabc']);
open(undefined, '', '/video/abc');
check('no SITE_BASE, clean', [siteBase(), signInPath(), requestBase()], ['', '/login?redirect=%2Fvideo%2Fabc', '']);

// A site in a folder (https://example.com/site), clean addresses: R2R-05.
open('/site', '', '/site/video/abc', '?t=3');
check('folder: sign in', signInPath(), '/site/login?redirect=%2Fsite%2Fvideo%2Fabc%3Ft%3D3');
check('folder: API', requestBase() + '/api/videos/1/like', '/site/api/videos/1/like');
check('folder: search', localePath('/search'), '/site/search');
check('folder: a file', siteFile('/images/placeholders/no-image.svg'), '/site/images/placeholders/no-image.svg');
open('/site', '/ar', '/site/ar/video/abc');
check('folder, another language: sign in', signInPath(), '/site/ar/login?redirect=%2Fsite%2Far%2Fvideo%2Fabc');
check('folder, another language: a member page', localePath('/profile/reports'), '/site/ar/profile/reports');
// opened through /index.php although the links are clean: the API goes the way the page came, the links stay clean
open('/site', '', '/site/index.php/video/abc');
check('folder, page through /index.php: API through it', requestBase(), '/site/index.php');
check('folder, page through /index.php: links as the site builds them', signInPath(), '/site/login?redirect=%2Fsite%2Findex.php%2Fvideo%2Fabc');

// A site in a folder on a server without rewrite rules.
open('/site/index.php', '/vi', '/site/index.php/vi/video/abc');
check('folder + index.php: sign in', signInPath(), '/site/index.php/vi/login?redirect=%2Fsite%2Findex.php%2Fvi%2Fvideo%2Fabc');
check('folder + index.php: API', requestBase() + '/api/x', '/site/index.php/api/x');
check('folder + index.php: search', localePath('/search'), '/site/index.php/vi/search');
check('folder + index.php: a file keeps the folder only', siteFile('/sounds/notification.mp3'), '/site/sounds/notification.mp3');
check('folder + index.php: the home of the folder', [sitePath(''), siteFile('')], ['/site/index.php', '/site']);

// Two levels of folders.
open('/apps/site', '', '/apps/site/video/abc');
check('two folders: sign in', signInPath(), '/apps/site/login?redirect=%2Fapps%2Fsite%2Fvideo%2Fabc');

console.log(`site path: ${passed} passed, ${failures} failed`);
if (failures > 0) {
    process.exit(1);
}
