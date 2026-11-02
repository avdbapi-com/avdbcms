// Small DOM helpers shared by the admin modules.

export const $ = <T extends Element = HTMLElement>(selector: string, root: ParentNode = document): T | null =>
    root.querySelector<T>(selector);

export const $$ = <T extends Element = HTMLElement>(selector: string, root: ParentNode = document): T[] =>
    Array.from(root.querySelectorAll<T>(selector));

export const csrfToken = (): string =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

/** The translated strings the shell prints into #ad-i18n (data-* attributes), with an English fallback. */
export const t = (key: string, fallback: string): string => {
    const value = document.getElementById('ad-i18n')?.dataset[key];

    return value && value.length > 0 ? value : fallback;
};

export const icon = (name: string, extra = ''): string =>
    `<svg class="ad-i${extra ? ' ' + extra.replace(/[^\w -]/g, '') : ''}" aria-hidden="true" focusable="false"><use href="#i-${name.replace(/[^a-z0-9-]/g, '')}"/></svg>`;

export const escapeHtml = (value: string): string =>
    value.replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c] as string));

/**
 * The header of a request the page sends by itself (App\Http\Middleware\BackgroundRequest::HEADER). The server reads
 * the session for it but changes nothing there: a one-time message ("Saved", a refusal) waits for the page it belongs
 * to, and an older copy of the session is never written over a newer one.
 */
export const BACKGROUND_HEADER = 'X-Background-Request';

// ---------------------------------------------------------------- leaving a page (R2A-057)
// When the administrator leaves a page, Safari cancels the requests still under way and reports each one in the
// console ("Fetch API cannot load ... due to access control checks."), even when the page handles the failure. So
// once leaving begins (beforeunload), the page stops its own background reads itself (Safari reports nothing for
// those) and starts no new one. When the page stays after all (a download link, a leave the browser did not finish,
// the back button's cache), the stopped and the waiting reads run then as if nothing had happened: on the next key or
// click on the page, or 30 s later (longer than a host lets a page load: a real leave has replaced the page by then).
// A leave the page itself asks the browser to confirm (unsaved settings, an update running: their beforeunload
// listeners come first, the watch starts with the first background read) stops nothing: the administrator may stay.

let leaving = false;
let watchingLeave = false;
let stayTimer = 0;
const underWay = new Set<AbortController>();
let waiting: Array<() => void> = [];

const stay = (): void => {
    if (!leaving) return;

    leaving = false;
    window.clearTimeout(stayTimer);
    const resume = waiting;
    waiting = [];
    resume.forEach((go) => go());
};

const leave = (): void => {
    leaving = true;
    underWay.forEach((controller) => controller.abort());
    underWay.clear();
    window.clearTimeout(stayTimer);
    stayTimer = window.setTimeout(stay, 30000);
};

const watchLeave = (): void => {
    if (watchingLeave) return;

    watchingLeave = true;
    window.addEventListener('beforeunload', (event) => {
        if (!event.defaultPrevented) leave();
    });
    window.addEventListener('pagehide', leave);
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) stay();
    });
    window.addEventListener('pointerdown', stay, true);
    window.addEventListener('keydown', stay, true);
};

/** Statuses whose answer has no body (a Response made with one throws). */
const NO_BODY = [101, 204, 205, 304];

/**
 * Every GET the admin sends in the background goes through here: a part filled in after the page appears, a list
 * fetched again after a row action, the Ctrl+K search, the Appearance pages' data. Same-site cookies, the marker, and
 * the caller's own headers (Accept, X-Requested-With) and abort signal. Writes (POST, PUT, DELETE) never use it.
 * The answer is read whole before it is handed over (a body still arriving is a request under way), and a read the
 * page stopped because it was being left runs again when the page stays; until then its promise waits.
 */
export const backgroundGet = async (url: string | URL, headers: Record<string, string> = {}, signal?: AbortSignal): Promise<Response> => {
    watchLeave();

    for (;;) {
        if (leaving) await new Promise<void>((resolve) => waiting.push(resolve));

        if (signal?.aborted) throw new DOMException('The read was stopped.', 'AbortError');

        const controller = new AbortController();
        const follow = (): void => controller.abort();
        signal?.addEventListener('abort', follow, {once: true});
        underWay.add(controller);

        try {
            const response = await fetch(url.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: {...headers, [BACKGROUND_HEADER]: '1'},
                signal: controller.signal,
            });
            const body = NO_BODY.includes(response.status) ? null : await response.blob();

            return new Response(body, {status: response.status, statusText: response.statusText, headers: response.headers});
        } catch (error) {
            // stopped because the page is being left, not by the caller: once more when the page stays
            if (controller.signal.aborted && !signal?.aborted) continue;

            throw error;
        } finally {
            underWay.delete(controller);
            signal?.removeEventListener('abort', follow);
        }
    }
};

export const postJson = async (url: string, body: Record<string, unknown>): Promise<Response> =>
    fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });

/** Elements that can take keyboard focus inside a container (for dialogs and the phone drawer). */
export const focusables = (root: ParentNode): HTMLElement[] =>
    $$<HTMLElement>('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', root)
        .filter((el) => el.offsetParent !== null || el === document.activeElement);

export const isTyping = (target: EventTarget | null): boolean =>
    target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
