import {requestBase, sitePath} from "@/functions/sitePath";

// The site's API, with the site's folder (a site installed at https://example.com/site) and /index.php where the page
// came through it (sitePath.ts), else the server's own address of it (window.API_BASE).
function resolveApiUrl(): string {
    if (typeof window !== 'undefined') {
        if (window.location.pathname.includes('/index.php')) {
            return requestBase() + '/api';
        }
        const base = (window as any).API_BASE;
        if (base && typeof base === 'string') {
            return base;
        }
    }
    return sitePath('/api');
}

export const API_URL = resolveApiUrl();
