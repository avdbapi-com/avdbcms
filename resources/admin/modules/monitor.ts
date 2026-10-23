// Preview monitor of the Appearance pages (<x-admin.monitor>): the device switch (Computer / Phone), the page switch
// and the live preview. Until the admin asks for it ([data-ad-monitor-live]) the monitor shows its static screenshot;
// then an iframe loads the page (sandboxed, same origin) at the real device width, scaled down to fit the screen.
// A page changing a visual setting sends "ad:monitor-refresh" (on the monitor, or on document for every monitor):
// it shows the live preview, or reloads it. A site whose own security headers refuse the frame (a custom CSP
// frame-ancestors) gets "Open the preview in a new tab" in the screen instead (no load, or an opaque error page).

import {$, $$} from '../lib/dom';

const WIDTH = {computer: 1280, phone: 390};

const deviceOf = (monitor: HTMLElement): 'computer' | 'phone' =>
    $<HTMLInputElement>('input[name^="ad_monitor_device"]:checked', monitor)?.value === 'phone' ? 'phone' : 'computer';

const pageUrl = (monitor: HTMLElement): string =>
    $<HTMLSelectElement>('[data-ad-monitor-page]', monitor)?.value || monitor.dataset.src || '';

const fit = (monitor: HTMLElement): void => {
    const screen = $('[data-ad-monitor-screen]', monitor);
    const frame = $<HTMLIFrameElement>('iframe', screen ?? monitor);

    if (!screen || !frame) return;

    const width = WIDTH[deviceOf(monitor)];
    const scale = screen.clientWidth / width;
    frame.style.width = `${width}px`;
    frame.style.height = `${screen.clientHeight / scale}px`;
    frame.style.transform = `scale(${scale})`;
};

const observer = typeof ResizeObserver === 'function'
    ? new ResizeObserver((entries) => entries.forEach((entry) => {
        const monitor = (entry.target as HTMLElement).closest<HTMLElement>('[data-ad-monitor]');
        if (monitor) fit(monitor);
    }))
    : null;

const LOAD_TIMEOUT = 12000;

// Before the live preview: the static picture shows only for the page it was taken of (data-page, a path); any
// other page asks for the live preview.
const syncShot = (monitor: HTMLElement): void => {
    const shot = $<HTMLImageElement>('[data-ad-monitor-shot]', monitor);
    const empty = $<HTMLElement>('.ad-monitor-empty', monitor);
    const page = shot?.dataset.page;
    let path = '';

    try {
        path = new URL(pageUrl(monitor), location.origin).pathname;
    } catch {
        path = '';
    }

    const showShot = !!shot && (page === undefined || page === path);
    if (shot) shot.hidden = !showShot;
    if (empty) empty.hidden = showShot;
};

const syncOpenLink = (monitor: HTMLElement): void => {
    const link = $<HTMLAnchorElement>('[data-ad-monitor-open]', monitor);
    const url = pageUrl(monitor);

    if (link && url) link.href = url;
};

const showBlocked = (monitor: HTMLElement, url: string): void => {
    const screen = $('[data-ad-monitor-screen]', monitor);

    if (!screen || $('.ad-monitor-blocked', screen)) return;

    const box = document.createElement('div');
    const text = document.createElement('p');
    const link = document.createElement('a');
    box.className = 'ad-monitor-blocked';
    text.textContent = monitor.dataset.blocked ?? '';
    link.className = 'ad-btn';
    link.href = url;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = monitor.dataset.openLabel ?? '';
    box.append(text, link);
    screen.append(box);
};

const watchLoad = (monitor: HTMLElement, frame: HTMLIFrameElement, url: string): void => {
    $('.ad-monitor-blocked', monitor)?.remove();
    const timer = window.setTimeout(() => showBlocked(monitor, url), LOAD_TIMEOUT);

    frame.addEventListener('load', () => {
        window.clearTimeout(timer);
        let readable = false;

        try {
            // A refused frame shows the browser's error page, which belongs to no origin: nothing is readable.
            readable = !!frame.contentDocument && frame.contentDocument.location.href !== 'about:blank';
        } catch {
            readable = false;
        }

        if (!readable) showBlocked(monitor, url);
    }, {once: true});
};

const showLive = (monitor: HTMLElement): void => {
    const screen = $('[data-ad-monitor-screen]', monitor);
    const url = pageUrl(monitor);

    if (!screen || !url) return;

    let frame = $<HTMLIFrameElement>('iframe', screen);

    if (frame) {
        // Already live: load the page again (a visual setting changed or another page was picked).
        watchLoad(monitor, frame, url);
        frame.src = url;
    } else {
        frame = document.createElement('iframe');
        frame.className = 'ad-monitor-frame';
        frame.title = monitor.dataset.frameTitle ?? '';
        frame.setAttribute('sandbox', 'allow-same-origin allow-scripts');
        frame.setAttribute('referrerpolicy', 'same-origin');
        watchLoad(monitor, frame, url);
        frame.src = url;
        $$<HTMLElement>('img, .ad-monitor-empty', screen).forEach((img) => {
            img.hidden = true;
        });
        screen.append(frame);
        observer?.observe(screen);
    }

    syncOpenLink(monitor);
    fit(monitor);

    const button = $<HTMLElement>('[data-ad-monitor-live]', monitor);
    if (button?.dataset.reloadLabel) {
        $('span', button)?.replaceChildren(button.dataset.reloadLabel);
    }
};

export const initMonitors = (): void => {
    document.addEventListener('change', (event) => {
        const input = event.target as HTMLInputElement | HTMLSelectElement;
        const monitor = input.closest<HTMLElement>('[data-ad-monitor]');

        if (!monitor) return;

        if (input.name.startsWith('ad_monitor_device')) {
            $('[data-ad-monitor-screen]', monitor)?.classList.toggle('is-phone', deviceOf(monitor) === 'phone');
            fit(monitor);
        } else if (input.hasAttribute('data-ad-monitor-page')) {
            syncOpenLink(monitor);
            if ($('iframe', monitor)) showLive(monitor);
            else syncShot(monitor);
        }
    });

    document.addEventListener('click', (event) => {
        const button = (event.target as Element).closest('[data-ad-monitor-live]');
        const monitor = button?.closest<HTMLElement>('[data-ad-monitor]');

        if (monitor) showLive(monitor);
    });

    document.addEventListener('ad:monitor-refresh', (event) => {
        const target = event.target instanceof HTMLElement ? event.target.closest<HTMLElement>('[data-ad-monitor]') : null;

        (target ? [target] : $$<HTMLElement>('[data-ad-monitor]')).forEach(showLive);
    });
};
