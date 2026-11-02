import {signInPath} from "@/functions/sitePath";

// auth-8, auth-18, R1-062: a guest's sign-in from the comments comes back to this page, in the page's language (the
// comment prompts, the comment box and Report comment use it; a bare /login relied on the Referer and the session's
// language). R3-018: the page's own sign-in address first (window.AVD_USER.loginUrl, built by the server like the
// header's link: the site's folder, /index.php where its links carry it, the language and a checked return address);
// where a page has none, signInPath() builds the same address (sitePath.ts). A server without rewrite rules answered the
// old /login with its 404 page.
export const signInUrl = (): string => window.AVD_USER?.loginUrl || signInPath();
