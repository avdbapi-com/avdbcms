// Row selection of <x-admin.table>: ticking rows ([data-ad-select]) shows the bulk bar ([data-ad-bulk]) with the count,
// marks the rows, and keeps the header box ([data-ad-select-all]) ticked, empty or mixed. Shift+click ticks a range;
// [data-ad-bulk-clear] clears the selection. The boxes are ordinary form fields (name="ids[]" form="..."), so the
// bulk action buttons submit them as they are; each change sends "ad:selection" {count, values} from the table.
// Also here, for the list pages: selects that apply at once (data-ad-autosubmit), busy forms (data-ad-busy), row
// actions with Undo (modules/list-rows.ts) and the sections filled (and refreshed) by the status probes
// (modules/list-probes.ts).

import {$, $$} from '../lib/dom';
import {initRows} from './list-rows';

let lastBox: HTMLInputElement | null = null;

// A row's box: [data-ad-select], or any box in a row's check cell (long lists leave the attribute out).
const ROW_BOX = 'input[type="checkbox"][data-ad-select], td.is-check > input[type="checkbox"]';

const rowBoxes = (wrap: Element): HTMLInputElement[] =>
    $$<HTMLInputElement>(ROW_BOX, wrap).filter((box) => !box.disabled);

const refresh = (wrap: HTMLElement): void => {
    const boxes = rowBoxes(wrap);
    const ticked = boxes.filter((box) => box.checked);
    const all = $<HTMLInputElement>('[data-ad-select-all]', wrap);
    const bar = $('[data-ad-bulk]', wrap);
    const count = $('[data-ad-bulk-count]', wrap);

    boxes.forEach((box) => box.closest('tr')?.classList.toggle('is-selected', box.checked));

    if (all) {
        all.checked = boxes.length > 0 && ticked.length === boxes.length;
        all.indeterminate = ticked.length > 0 && ticked.length < boxes.length;
    }

    if (bar) {
        bar.hidden = ticked.length === 0;

        // A choice of the bar that lists what a toolbar filter lists (e.g. the categories) copies its options the
        // first time the bar shows, so the page does not send them twice; the "all" and "none" entries stay out.
        if (!bar.hidden) {
            $$<HTMLSelectElement>('select[data-ad-options-from]', bar).forEach((select) => {
                const source = document.getElementById(select.dataset.adOptionsFrom ?? '');

                if (!(source instanceof HTMLSelectElement) || select.options.length > 1) return;

                Array.from(source.options)
                    .filter((option) => option.value !== '' && option.value !== 'without')
                    .forEach((option) => select.add(new Option(option.text, option.value)));
            });
        }

        if (count) {
            const text = ticked.length === 1 ? bar.dataset.one ?? '1 selected' : bar.dataset.many ?? ':count selected';
            count.textContent = text.replace(':count', String(ticked.length));
        }
    }

    wrap.dispatchEvent(new CustomEvent('ad:selection', {bubbles: true, detail: {count: ticked.length, values: ticked.map((box) => box.value)}}));
};

export const initTables = (): void => {
    document.addEventListener('click', (event) => {
        const target = event.target as Element;
        const box = target.closest<HTMLInputElement>(ROW_BOX);
        const wrap = target.closest<HTMLElement>('.ad-tablewrap');

        if (!wrap) return;

        if (box) {
            // Shift+click: every box between the last one clicked and this one takes this one's state.
            const boxes = rowBoxes(wrap);
            const from = lastBox ? boxes.indexOf(lastBox) : -1;
            const to = boxes.indexOf(box);

            if ((event as MouseEvent).shiftKey && from > -1 && to > -1) {
                boxes.slice(Math.min(from, to), Math.max(from, to) + 1).forEach((other) => {
                    other.checked = box.checked;
                });
            }

            lastBox = box;
            refresh(wrap);
        } else if (target.closest('[data-ad-select-all]')) {
            const all = target.closest<HTMLInputElement>('[data-ad-select-all]');
            rowBoxes(wrap).forEach((other) => {
                other.checked = Boolean(all?.checked);
            });
            refresh(wrap);
        } else if (target.closest('[data-ad-bulk-clear]')) {
            rowBoxes(wrap).forEach((other) => {
                other.checked = false;
            });
            refresh(wrap);
            $<HTMLElement>('[data-ad-select-all]', wrap)?.focus();
        }
    });

    // Boxes ticked before the script ran (back/forward cache, form restore) show their bar too.
    $$<HTMLElement>('.ad-tablewrap').forEach((wrap) => {
        if (rowBoxes(wrap).some((box) => box.checked)) refresh(wrap);
    });

    // The pager's "per page" choice and the toolbar's sort and filter selects apply at once (each sits in a GET form
    // with a submit button for when this script does not run).
    document.addEventListener('change', (event) => {
        const select = event.target;

        if (select instanceof HTMLSelectElement && select.hasAttribute('data-ad-autosubmit')) {
            select.form?.requestSubmit();
        }
    });

    // Long server actions (Finish update): the button says what is happening and cannot be pressed twice.
    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.dataset.adBusy || event.defaultPrevented) return;

        if (form.getAttribute('aria-busy') === 'true') {
            event.preventDefault();

            return;
        }

        form.setAttribute('aria-busy', 'true');
        const button = form.querySelector<HTMLButtonElement>('button[type="submit"]');

        if (button) button.textContent = form.dataset.adBusy;
    });

    initRows();

    // Slow server checks fill their sections after the page appears; a section's Refresh button asks them again.
    if (document.querySelector('[data-ad-defer-probes]')) {
        void import('./list-probes').then(({initProbes}) => initProbes());
    }

    document.addEventListener('click', (event) => {
        const button = (event.target as Element | null)?.closest<HTMLButtonElement>('button[data-ad-probe-refresh]');

        if (button) void import('./list-probes').then(({refreshProbes}) => refreshProbes(button));
    });

    // Lists put in order with Up / Down buttons (the menu's categories).
    if (document.querySelector('[data-ad-order]')) {
        void import('./list-order').then(({initOrder}) => initOrder());
    }
};
