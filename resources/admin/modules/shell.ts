// The shell's own behaviour: sidebar sections and "N more", the phone drawer, menus (account, site status,
// "More"), the account menu's preferences and the Ctrl+K search (palette.ts, loaded on first use).

import {$, $$, focusables, isTyping, postJson, t} from '../lib/dom';

const root = document.documentElement;

// ---------------------------------------------------------------- sidebar
const initNav = (): void => {
    document.addEventListener('click', (event) => {
        const target = event.target as Element | null;
        const toggle = target?.closest<HTMLButtonElement>('[data-ad-nav-toggle]');

        if (toggle) {
            const list = document.getElementById(toggle.getAttribute('aria-controls') ?? '');
            const open = toggle.getAttribute('aria-expanded') !== 'true';
            toggle.setAttribute('aria-expanded', String(open));
            if (list?.classList.contains('ad-nav-sub')) list.classList.toggle('is-collapsed', !open);
            else if (list) list.hidden = !open;
            toggle.closest('.ad-nav-group')?.classList.toggle('is-open', open);

            return;
        }

        const more = target?.closest<HTMLButtonElement>('[data-ad-nav-more]');

        if (more) {
            const list = more.closest('ul');
            $$('li[data-ad-more-item]', list ?? document).forEach((item) => {
                item.hidden = false;
            });
            more.closest('li')?.remove();
            list?.querySelector<HTMLElement>('li[data-ad-more-item] a')?.focus();
        }
    });
};

// ---------------------------------------------------------------- phone drawer
const initDrawer = (): void => {
    const side = $('#ad-side');
    const opener = $<HTMLButtonElement>('[data-ad-drawer-open]');

    if (!side || !opener) return;

    const close = (): void => {
        if (!root.classList.contains('nav-open')) return;
        root.classList.remove('nav-open');
        opener.setAttribute('aria-expanded', 'false');
        opener.focus();
    };

    opener.addEventListener('click', () => {
        root.classList.add('nav-open');
        opener.setAttribute('aria-expanded', 'true');
        (side.querySelector<HTMLElement>('.ad-nav a[aria-current="page"]') ?? focusables(side)[0])?.focus();
    });

    $('[data-ad-drawer-close]')?.addEventListener('click', close);

    document.addEventListener('keydown', (event) => {
        if (!root.classList.contains('nav-open')) return;

        if (event.key === 'Escape') {
            close();
        } else if (event.key === 'Tab') {
            // Focus stays inside the open drawer.
            const items = focusables(side);
            const first = items[0];
            const last = items[items.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }
        }
    });

    // Leaving the page through a drawer link closes it (also when the page comes back from the bfcache).
    window.addEventListener('pageshow', () => root.classList.remove('nav-open'));
};

// ---------------------------------------------------------------- menus
let openMenu: {button: HTMLElement; menu: HTMLElement} | null = null;

const closeMenu = (focusButton = false): void => {
    if (!openMenu) return;
    openMenu.menu.hidden = true;
    openMenu.button.setAttribute('aria-expanded', 'false');
    if (focusButton) openMenu.button.focus();
    openMenu = null;
};

const initMenus = (): void => {
    document.addEventListener('click', (event) => {
        const target = event.target as Element | null;
        const button = target?.closest<HTMLElement>('[data-ad-menu]');

        if (button) {
            const menu = document.getElementById(button.getAttribute('aria-controls') ?? '');

            if (!menu) return;

            event.preventDefault();
            const wasOpen = openMenu?.menu === menu;
            closeMenu();

            if (!wasOpen) {
                menu.hidden = false;
                button.setAttribute('aria-expanded', 'true');
                openMenu = {button, menu};
                focusables(menu)[0]?.focus({preventScroll: true});
            }

            return;
        }

        if (openMenu && target && !openMenu.menu.contains(target)) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (!openMenu) return;

        if (event.key === 'Escape') {
            closeMenu(true);
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            const items = focusables(openMenu.menu);
            const index = items.indexOf(document.activeElement as HTMLElement);
            const next = event.key === 'ArrowDown' ? index + 1 : index - 1;

            if (items.length && !(document.activeElement instanceof HTMLInputElement && document.activeElement.type === 'radio')) {
                event.preventDefault();
                items[(next + items.length) % items.length]?.focus();
            }
        }
    });

    document.addEventListener('focusin', (event) => {
        if (openMenu && !openMenu.menu.contains(event.target as Node) && event.target !== openMenu.button) {
            closeMenu();
        }
    });
};

// ---------------------------------------------------------------- light / dark for the old views
// The old views style themselves with html[data-bs-theme]; it follows the admin's choice and, for "System",
// the device (also when the device switches while the page is open).
const darkQuery = window.matchMedia('(prefers-color-scheme: dark)');

const syncBootstrapTheme = (): void => {
    const chosen = root.getAttribute('data-theme');
    root.setAttribute('data-bs-theme', chosen === 'dark' || (chosen === null && darkQuery.matches) ? 'dark' : 'light');
};

darkQuery.addEventListener('change', syncBootstrapTheme);

// ---------------------------------------------------------------- account menu preferences
// A refused choice shows the server's reason (e.g. "Finish update" has not run yet), else a generic line.
const prefError = (response: Response): void => {
    void response.json()
        .catch(() => null)
        .then((data: {message?: unknown} | null) => {
            const message = typeof data?.message === 'string' && data.message !== '' ? data.message : t('prefError', 'Could not save this choice. Try again.');
            window.adminToast?.(message, {type: 'error'});
        });
};

const initPreferences = (): void => {
    const menu = $('#ad-account-menu');

    if (!menu) return;

    const url = menu.dataset.prefsUrl ?? '';

    // Light / dark / system applies at once, then saves in the background.
    $$<HTMLInputElement>('input[name="admin_theme"]', menu).forEach((input) => {
        input.addEventListener('change', () => {
            if (!input.checked) return;
            if (input.value === 'system') root.removeAttribute('data-theme');
            else root.setAttribute('data-theme', input.value);
            syncBootstrapTheme();
            void postJson(url, {key: 'admin_theme', value: input.value}).then((response) => {
                if (!response.ok) prefError(response);
            });
        });
    });

    // Switches and the colour choice change the menu or the colours: save, then reload the page.
    $$<HTMLInputElement | HTMLSelectElement>('[data-ad-pref]', menu).forEach((input) => {
        input.addEventListener('change', () => {
            const value = input instanceof HTMLInputElement && input.type === 'checkbox' ? input.checked : input.value;

            void postJson(url, {key: input.dataset.adPref, value}).then((response) => {
                if (response.ok) window.location.reload();
                else prefError(response);
            });
        });
    });
};

// ---------------------------------------------------------------- search (Ctrl+K)
let paletteModule: Promise<typeof import('../palette')> | null = null;

const loadPalette = (): Promise<typeof import('../palette')> => {
    paletteModule ??= import('../palette');
    paletteModule.catch(() => {
        paletteModule = null;
    });

    return paletteModule;
};

const openPalette = (query = ''): void => {
    void loadPalette().then((module) => module.openPalette(query));
};

const initSearch = (): void => {
    document.addEventListener('click', (event) => {
        const trigger = (event.target as Element | null)?.closest<HTMLElement>('[data-ad-palette]');

        if (trigger) {
            event.preventDefault();
            openPalette(trigger.dataset.adPaletteQuery ?? '');
        }
    });

    // Warm the chunk when the pointer or focus reaches the search button.
    $$('[data-ad-palette]').forEach((trigger) => {
        trigger.addEventListener('pointerenter', () => void loadPalette(), {once: true});
        trigger.addEventListener('focus', () => void loadPalette(), {once: true});
    });

    document.addEventListener('keydown', (event) => {
        const key = event.key.toLowerCase();

        if ((event.ctrlKey || event.metaKey) && !event.altKey && key === 'k') {
            event.preventDefault();
            openPalette();
        } else if (key === '/' && !event.ctrlKey && !event.metaKey && !isTyping(event.target)) {
            event.preventDefault();
            openPalette();
        }
    });
};

// ---------------------------------------------------------------- deep links to a setting (#key)
const initTarget = (): void => {
    const id = decodeURIComponent(window.location.hash.slice(1));

    if (!id || !/^[\w.-]+$/.test(id)) return;

    const field = document.getElementById(id)?.closest<HTMLElement>('.ad-field, .ad-toggle-row') ?? null;

    if (field) {
        field.classList.add('is-target');
        field.scrollIntoView({block: 'center'});
        window.setTimeout(() => field.classList.remove('is-target'), 2400);
    }
};

export const initShell = (): void => {
    initNav();
    initDrawer();
    initMenus();
    initPreferences();
    initSearch();
    initTarget();
};
