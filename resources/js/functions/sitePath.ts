// The start of every address the site's scripts build (round 3 fix pass R3-018, R2R-05): the folder the site is installed
// in ("/site" for a site at https://example.com/site, "" at a domain's root), then "/index.php" where the site's links
// carry it (a server without rewrite rules: /login there is the web server's 404 page). The page prints it with its
// other values (window.SITE_BASE, resources/views/components/layout.blade.php), from the same decision as every link
// the server builds; a page without it keeps the rule of the scripts up to 1.2.3 RC2 (its own address says /index.php).
// The comments' sign-in links, the search box and the API calls use it, so none of them drops the folder or /index.php.

/** The folder of the site, then /index.php where the site's links carry it: "", "/index.php", "/site", "/site/index.php". */
export const siteBase = (): string => {
    if (typeof window === 'undefined') {
        return '';
    }

    const base = window.SITE_BASE;

    if (typeof base === 'string') {
        return base;
    }

    return window.location.pathname.startsWith('/index.php') ? '/index.php' : '';
};

/** A path of the site ("/login", "/api/...") as the browser asks for it. */
export const sitePath = (path: string): string => siteBase() + path;

/** A page of the site in the page's language ("/fr/login" on a French page). */
export const localePath = (path: string): string => siteBase() + (window.LOCALE_PREFIX || '') + path;

/** A file of the site's public folder (a picture, a sound): its folder only, never /index.php. */
export const siteFile = (path: string): string => siteBase().replace(/\/index\.php$/, '') + path;

/**
 * The start of a request the page's scripts send (the API): through /index.php when the page itself came through it
 * (as up to 1.2.3 RC2: it reaches the site on any server), else as the site's links (siteBase()).
 */
export const requestBase = (): string =>
    typeof window !== 'undefined' && window.location.pathname.includes('/index.php') ? siteFile('/index.php') : siteBase();

/** This page as the browser addresses it: the page a sign-in link comes back to (ReturnPath reads it so). */
export const currentPage = (): string => window.location.pathname + window.location.search;

/** The sign-in page in the page's language, coming back to this page after signing in. */
export const signInPath = (): string => localePath('/login') + '?' + new URLSearchParams({redirect: currentPage()});
