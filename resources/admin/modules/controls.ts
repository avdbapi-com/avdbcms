// Behaviour of the form components: colour field (swatch <-> hex, contrast of white/dark text on the colour),
// link list editor (rows <-> the "Label | URL | icon" text it saves; drag the grip or use its arrow keys to
// reorder), upload preview and "Reset" to default.
// One delegated listener per event type; nothing runs on pages without these components.

import {$, $$, t} from '../lib/dom';

const luminance = (hex: string): number => {
    const lin = (c: number): number => {
        const v = c / 255;
        return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    };
    const n = parseInt(hex.slice(1), 16);

    return 0.2126 * lin((n >> 16) & 255) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255);
};

const contrast = (a: string, b: string): number => {
    const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (l1 + 0.05) / (l2 + 0.05);
};

const updateColor = (box: HTMLElement, hex: string, from: 'swatch' | 'hex'): void => {
    const swatch = $<HTMLInputElement>('[data-ad-color-swatch]', box);
    const input = $<HTMLInputElement>('[data-ad-color-hex]', box);
    const badge = $('[data-ad-contrast]', box);
    const valid = /^#[0-9a-f]{6}$/i.test(hex);

    if (from === 'swatch' && input) input.value = hex;
    if (from === 'hex' && swatch && valid) swatch.value = hex.toLowerCase();
    if (!badge) return;

    if (!valid) {
        badge.textContent = '';
        return;
    }

    // The better of white or dark text on this colour (4.5:1 is the readable minimum).
    const ratio = Math.max(contrast('#ffffff', hex), contrast('#16191e', hex));
    const good = ratio >= 4.5;
    badge.className = 'ad-badge ' + (good ? 'is-ok' : 'is-warn');
    badge.textContent = t(good ? 'contrastGood' : 'contrastLow', good ? 'Text on this colour: :ratio:1 Good' : 'Text on this colour: :ratio:1 Hard to read')
        .replace(':ratio', ratio.toFixed(1));
};

const syncLinks = (box: HTMLElement): void => {
    const textarea = $<HTMLTextAreaElement>('textarea', box);

    if (!textarea) return;

    textarea.value = $$('[data-ad-link-row]', box)
        .map((row) => $$<HTMLInputElement>('[data-ad-link-part]', row).map((input) => input.value.trim().replace(/\|/g, '/')))
        .filter(([label, url]) => label !== '' || url !== '')
        .map((parts) => (parts[2] ? parts : parts.slice(0, 2)).join(' | '))
        .join('\n');
    textarea.dispatchEvent(new Event('input', {bubbles: true}));
};

// Link list: the grip moves its row (pointer drag, or ArrowUp / ArrowDown while it has the focus).
const moveRow = (row: HTMLElement, before: Element | null): void => {
    const links = row.closest<HTMLElement>('[data-ad-links]');

    if (!links || before === row) return;

    // Without a row to go before, it goes after the last one (rows stay above the "Add a link" line).
    if (before) before.before(row);
    else $$('[data-ad-link-row]', links).filter((other) => other !== row).pop()?.after(row);
    syncLinks(links);
};

const initLinkDrag = (): void => {
    document.addEventListener('keydown', (event) => {
        const grip = (event.target as Element).closest?.<HTMLElement>('[data-ad-link-grip]');
        const row = grip?.closest<HTMLElement>('[data-ad-link-row]');

        if (!grip || !row || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) return;

        event.preventDefault();
        const rows = $$('[data-ad-link-row]', row.parentElement ?? document);
        const index = rows.indexOf(row);

        if (event.key === 'ArrowUp' && index > 0) moveRow(row, rows[index - 1]);
        if (event.key === 'ArrowDown' && index < rows.length - 1) moveRow(row, rows[index + 2] ?? null);
        grip.focus();
    });

    document.addEventListener('pointerdown', (event) => {
        const grip = (event.target as Element).closest<HTMLElement>('[data-ad-link-grip]');
        const row = grip?.closest<HTMLElement>('[data-ad-link-row]');

        if (!grip || !row || event.button !== 0) return;

        // The row moves in the document while dragging, which would drop a pointer capture: listen on document.
        event.preventDefault();
        row.classList.add('is-dragging');

        const onMove = (move: PointerEvent): void => {
            const rows = $$('[data-ad-link-row]', row.parentElement ?? document).filter((other) => other !== row);
            // The first row whose middle is below the pointer: the dragged row goes before it.
            const next = rows.find((other) => {
                const box = other.getBoundingClientRect();
                return move.clientY < box.top + box.height / 2;
            }) ?? null;

            const inPlace = next ? next.previousElementSibling === row : rows[rows.length - 1]?.nextElementSibling === row;

            if (!inPlace) moveRow(row, next);
        };
        const onEnd = (): void => {
            row.classList.remove('is-dragging');
            document.removeEventListener('pointermove', onMove);
            document.removeEventListener('pointerup', onEnd);
            document.removeEventListener('pointercancel', onEnd);
            grip.focus({preventScroll: true});
        };

        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onEnd);
        document.addEventListener('pointercancel', onEnd);
    });
};

export const initControls = (): void => {
    initLinkDrag();

    document.addEventListener('input', (event) => {
        const el = event.target as HTMLElement;
        const color = el.closest<HTMLElement>('[data-ad-color]');

        if (color && el instanceof HTMLInputElement) {
            updateColor(color, el.value.trim(), el.hasAttribute('data-ad-color-swatch') ? 'swatch' : 'hex');
            if (el.hasAttribute('data-ad-color-swatch')) {
                $('[data-ad-color-hex]', color)?.dispatchEvent(new Event('input', {bubbles: true}));
            }
        }

        const links = el.closest<HTMLElement>('[data-ad-links]');
        if (links && el.hasAttribute('data-ad-link-part')) syncLinks(links);
    });

    document.addEventListener('change', (event) => {
        const input = event.target as HTMLInputElement;

        if (!input.matches?.('[data-ad-drop-input]') || !input.files?.[0]) return;

        const preview = input.closest('[data-ad-drop]')?.querySelector<HTMLImageElement>('[data-ad-drop-preview]');
        if (preview) {
            preview.src = URL.createObjectURL(input.files[0]);
            preview.hidden = false;
        }
    });

    document.addEventListener('click', (event) => {
        const target = event.target as Element;
        const reset = target.closest<HTMLElement>('[data-ad-reset], [data-ad-color-reset]');

        if (reset) {
            const box = reset.closest<HTMLElement>('[data-ad-color]');
            const input = box
                ? $<HTMLInputElement>('[data-ad-color-hex]', box)
                : document.getElementById(reset.dataset.adReset ?? '') as HTMLInputElement | null;

            if (input) {
                input.value = reset.dataset.adResetValue ?? reset.dataset.adColorReset ?? '';
                input.dispatchEvent(new Event('input', {bubbles: true}));
            }

            return;
        }

        const links = target.closest<HTMLElement>('[data-ad-links]');

        if (links && target.closest('[data-ad-link-add]')) {
            const rows = $$('[data-ad-link-row]', links);
            const copy = rows[rows.length - 1]?.cloneNode(true) as HTMLElement | undefined;

            if (copy) {
                $$<HTMLInputElement>('input', copy).forEach((input) => {
                    input.value = '';
                });
                rows[rows.length - 1].after(copy);
                $<HTMLInputElement>('input', copy)?.focus();
            }
        } else if (links && target.closest('[data-ad-link-remove]')) {
            const rows = $$('[data-ad-link-row]', links);
            const row = target.closest('[data-ad-link-row]');

            if (rows.length > 1) row?.remove();
            else $$<HTMLInputElement>('input', row ?? links).forEach((input) => {
                input.value = '';
            });
            syncLinks(links);
        }
    });

    $$('[data-ad-color]').forEach((box) => {
        const input = $<HTMLInputElement>('[data-ad-color-hex]', box);
        if (input) updateColor(box, input.value, 'hex');
    });
};
