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
