import {requestBase} from "@/functions/sitePath";

// The header of a request the page sends by itself (App\Http\Middleware\BackgroundRequest::HEADER). Every GET of this
// helper is one: the lists, comments, notifications, history and searches the page loads after it appears. The
// server reads the session for it but changes nothing there, so a one-time message waits for the page it belongs to
// and an older copy of the session (signed in, signed out) is never written over a newer one. Writes stay unmarked.
export const BACKGROUND_HEADER = 'X-Background-Request';

export async function jsonFetch(
    url: string,
    method: string = 'GET',
    json?: Object
) {
    const body = json ? JSON.stringify(json) : undefined;

    let headers: Record<string, string> = {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') as string,
        'X-Locale': window.LANG || 'en',
    };

    if (method.toUpperCase() === 'GET') {
        headers[BACKGROUND_HEADER] = '1';
    }

    // A path of the site's API ("/api/..."): the site's folder in front, and /index.php where the page came through it or
    // the site's links carry it (sitePath.ts). An address the server built (window.API_BASE, a feed's URL) is whole already.
    let targetUrl = url;
    if (targetUrl.startsWith('/api/') && typeof window !== 'undefined') {
        targetUrl = requestBase() + targetUrl;
    }

    const response = await fetch(targetUrl, {
        method,
        body,
        headers: headers,
        credentials: 'include',
    });

    if (response.status === 204) {
        return null;
    }

    // Every error thrown here carries the answer's HTTP status (error.status), so a component can say what the member
    // can do (signed out, page out of date, too many tries) instead of the server's raw English (R1-016). The error
    // types and messages are the same as before.
    let data;
    try {
        data = await response.json();
    } catch (error) {
        if (error instanceof Error) {
            Object.assign(error, {status: response.status});
        }
        throw error;
    }

    if (response.ok) {
        return data;
    }

    throw Object.assign(new Error(data?.message ?? 'Oups something went wrong !'), {status: response.status});
}
