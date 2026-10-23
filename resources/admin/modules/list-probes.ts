// Deferred sections: a part of a page that shows the answer of slow server checks (scheduler, disk, folder sizes,
// health check ...) is rendered at once with a placeholder when the server has no fresh answer yet:
// <section data-ad-defer="id" data-ad-defer-probes="url url">. This asks the status probes once the page is shown
// (each answer is cached on the server), then fetches the page again and swaps in the same sections, now rendered
// with the answers. One try only; a section the server still cannot fill keeps its placeholder.
// A Refresh button inside a section (<button data-ad-probe-refresh="url url">) asks its probes again without their
// cache (?refresh=1) and swaps the section the same way.

import {$$} from '../lib/dom';
import {adoptSymbols} from './list-rows';

const fetchOk = async (url: string, accept: string): Promise<Response | null> => {
    try {
        const response = await fetch(url, {credentials: 'same-origin', headers: {Accept: accept, 'X-Requested-With': 'XMLHttpRequest'}});

        return response.ok ? response : null;
    } catch {
        return null;
    }
};

/** Ask the probes, then swap the sections from a fresh copy of the page. */
const fill = async (sections: HTMLElement[], urls: Set<string>): Promise<void> => {
    sections.forEach((section) => section.setAttribute('aria-busy', 'true'));

    await Promise.all([...urls].map((url) => fetchOk(url, 'application/json')));

    const page = await fetchOk(window.location.href, 'text/html');
    const html = page ? await page.text() : '';
    const fresh = html ? new DOMParser().parseFromString(html, 'text/html') : null;

    if (fresh) adoptSymbols(fresh);

    sections.forEach((section) => {
        const id = section.dataset.adDefer ?? '';
        const next = fresh ? Array.from(fresh.querySelectorAll<HTMLElement>('[data-ad-defer]')).find((el) => el.dataset.adDefer === id) : undefined;

        section.removeAttribute('aria-busy');

        if (next && !next.hasAttribute('data-ad-defer-probes')) {
            // Keep keyboard focus where it was when it sat inside the section (by its id, if it has one).
            const focusedId = section.contains(document.activeElement) ? (document.activeElement as HTMLElement).id : '';
            const node = document.importNode(next, true);
            section.replaceWith(node);

            if (focusedId) document.getElementById(focusedId)?.focus();
        }
    });
};

const probeUrls = (value: string | undefined): string[] => (value ?? '').split(/\s+/).filter(Boolean);

export const initProbes = async (): Promise<void> => {
    const sections = $$<HTMLElement>('[data-ad-defer][data-ad-defer-probes]');

    if (sections.length === 0) return;

    const urls = new Set<string>();
    sections.forEach((section) => probeUrls(section.dataset.adDeferProbes).forEach((url) => urls.add(url)));

    await fill(sections, urls);
};

export const refreshProbes = async (button: HTMLButtonElement): Promise<void> => {
    const section = button.closest<HTMLElement>('[data-ad-defer]');

    // aria-disabled, not disabled: the button keeps the focus, which then moves to the new section's button.
    if (!section || button.getAttribute('aria-disabled') === 'true') return;

    button.setAttribute('aria-disabled', 'true');

    const urls = new Set(probeUrls(button.dataset.adProbeRefresh).map((url) => {
        const fresh = new URL(url, window.location.href);
        fresh.searchParams.set('refresh', '1');

        return fresh.href;
    }));

    await fill([section], urls);

    // Still here when the section could not be swapped (the page did not answer): it can be pressed again.
    button.removeAttribute('aria-disabled');
};
