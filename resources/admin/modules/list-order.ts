// Ordered lists that are saved as a whole (the categories of the menu): <form data-ad-order> holds <li data-ad-order-item>
// rows, each with its hidden input and two buttons [data-ad-order-move="-1|1"]. A button moves its row one place; the
// focus stays on it (or on the other button at the ends), [data-ad-order-status] says where the row is now, and the
// form sends the inputs in their new order. Without this script the list keeps its order.

import {$, $$} from '../lib/dom';

const sync = (list: HTMLElement): void => {
    const items = $$<HTMLElement>('[data-ad-order-item]', list);

    items.forEach((item, index) => {
        const up = $<HTMLButtonElement>('[data-ad-order-move="-1"]', item);
        const down = $<HTMLButtonElement>('[data-ad-order-move="1"]', item);

        if (up) up.disabled = index === 0;
        if (down) down.disabled = index === items.length - 1;
    });
};

export const initOrder = (): void => {
    document.addEventListener('click', (event) => {
        const button = (event.target as Element | null)?.closest<HTMLButtonElement>('[data-ad-order-move]');
        const item = button?.closest<HTMLElement>('[data-ad-order-item]');
        const list = item?.closest<HTMLElement>('[data-ad-order-list]');

        if (!button || !item || !list) return;

        const step = Number(button.dataset.adOrderMove);
        const sibling = (step < 0 ? item.previousElementSibling : item.nextElementSibling) as HTMLElement | null;

        if (!sibling) return;

        if (step < 0) sibling.before(item);
        else sibling.after(item);

        sync(list);

        const target = button.disabled ? $<HTMLButtonElement>(`[data-ad-order-move="${-step}"]`, item) : button;
        target?.focus();

        const items = $$<HTMLElement>('[data-ad-order-item]', list);
        const status = $('[data-ad-order-status]', list.closest('form') ?? document);
        const name = item.querySelector('b')?.textContent ?? '';

        if (status) {
            status.textContent = (status.dataset.moved || ':name: place :place of :total')
                .replace(':name', name)
                .replace(':place', String(items.indexOf(item) + 1))
                .replace(':total', String(items.length));
        }
    });
};
