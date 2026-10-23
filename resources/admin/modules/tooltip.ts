// One delegated tooltip for the whole admin (no Popper): [data-ad-tip] and the old
// [data-bs-toggle="tooltip"] (their title moves to data-bs-title so the browser's own tooltip stays away).

let tip: HTMLDivElement | null = null;
let owner: HTMLElement | null = null;

const SELECTOR = '[data-ad-tip], [data-bs-toggle="tooltip"]';

const textOf = (el: HTMLElement): string => {
    if (el.hasAttribute('title')) {
        el.dataset.bsTitle = el.getAttribute('title') ?? '';
        el.removeAttribute('title');
    }

    return (el.dataset.adTip || el.dataset.bsTitle || '').trim();
};

const show = (el: HTMLElement): void => {
    const text = textOf(el);

    if (!text) return;

    if (!tip) {
        tip = document.createElement('div');
        tip.className = 'ad-tip';
        tip.id = 'ad-tip';
        tip.setAttribute('role', 'tooltip');
        document.body.appendChild(tip);
    }

    owner = el;
    tip.textContent = text;
    tip.hidden = false;
    el.setAttribute('aria-describedby', 'ad-tip');

    const box = el.getBoundingClientRect();
    const tipBox = tip.getBoundingClientRect();
    const top = box.top - tipBox.height - 8 < 8 ? box.bottom + 8 : box.top - tipBox.height - 8;
    const left = Math.min(Math.max(8, box.left + box.width / 2 - tipBox.width / 2), window.innerWidth - tipBox.width - 8);

    // Page coordinates: the tip scrolls with the page, so no scroll listener is needed.
    tip.style.top = `${Math.round(top + window.scrollY)}px`;
    tip.style.left = `${Math.round(left + window.scrollX)}px`;
};

const hide = (): void => {
    if (tip) tip.hidden = true;
    owner?.removeAttribute('aria-describedby');
    owner = null;
};

export const initTooltips = (): void => {
    document.addEventListener('pointerover', (event) => {
        const el = (event.target as Element | null)?.closest<HTMLElement>(SELECTOR);
        if (el && el !== owner) show(el);
        else if (!el && owner) hide();
    });
    document.addEventListener('focusin', (event) => {
        const el = (event.target as Element | null)?.closest<HTMLElement>(SELECTOR);
        if (el) show(el);
    });
    document.addEventListener('focusout', hide);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') hide();
    });
};
