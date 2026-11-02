// The sentence a refused or failed request shows (R1-016), kept apart from the hook (hooks/useErrorMutation.ts) so
// tests/js/front_logic.test.ts can run it in Node: the server's own words when it gave a real sentence (a view-only demo
// account, a validation message, a limit with its wait), else the page's words for the answer's status, which
// resources/js/functions/api.ts puts on the error: signed out (401), page out of date (419), too many tries (429), the
// server's fault (5xx), and the connection when no answer came at all.

type Translate = (key: string) => string;

// Laravel's own English answers that tell a member nothing they can act on ("Unauthenticated." for a session that
// ended, "CSRF token mismatch." for a page left open too long, ...): replaced by the page's words for their status.
const RAW_ANSWERS = new Set([
    'Unauthenticated.',
    'CSRF token mismatch.',
    'Page Expired',
    'Too Many Attempts.',
    'Too many attempts !',
    'Server Error',
    'This action is unauthorized.',
    'Forbidden',
    'Oups something went wrong !',
    'Whoops! An error occurred. Please try again later.',
]);

export function errorText(error: unknown, t: Translate): string {
    const status = typeof (error as {status?: unknown})?.status === 'number' ? (error as {status: number}).status : 0;
    const message = error instanceof Error ? error.message.trim() : '';

    if (status === 401) {
        return t('You are signed out. Sign in and try again.');
    }
    if (status === 419) {
        return t('That did not work. Reload the page and try again.');
    }
    // a plain Error is the server's answer (jsonFetch); a TypeError (no answer) or SyntaxError (not JSON) is not
    if (error instanceof Error && error.constructor === Error && message !== '' && !RAW_ANSWERS.has(message)) {
        return message;
    }
    if (status === 429) {
        return t('Too many tries. Wait a minute and try again.');
    }
    if (status >= 500 || status === 403 || status === 404) {
        return t('Something went wrong. Try again.');
    }

    return t('That did not work. Check your connection and try again.');
}
