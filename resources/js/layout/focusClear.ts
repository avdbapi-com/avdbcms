// R2R-02 (WCAG 2.2 2.4.11 / 2.4.12, Focus Not Obscured): a control the keyboard moves to is never left under a bar that
// stays on screen: the sticky header, the watch page's "Back to the video" bar, the channel tabs and the Account
// settings strip once they stick, on phones the bottom bar, the ad strip, the studio tab bar, the save and bulk bars,
// and the floating controls ("Back to top", the day's design pill, the mini player, a toast, a demo site's visitor
// buttons); in the admin its top bar, its table heads and its save bars (resources/admin/modules/focus.ts passes those).
//
// Keyboard only: a mouse, a touch or a pen changes nothing (no padding, no extra scroll), so a click, a tap or an
// anchor link lands exactly as it did, and a scroll the visitor makes right after a Tab (the wheel, the keys, the scroll
// bar) stays where the visitor takes the page.
//  1. Tab / Shift+Tab (Option+Tab too: Safari's way to reach every link): the bars' real boxes, measured at that moment,
//     give the root scroller its scroll-padding (an inline style on <html>: a property that is not inherited, so nothing
//     else restyles; a custom property there would restyle the whole page). The browser's own scrolls (page keys, an
//     anchor) then stop clear of them.
//  2. When the focus arrives (focusin), the control is brought clear of every bar beside it AT ONCE, before the browser
//     scrolls for it (its own scroll then finds it in view and does nothing): no frame shows it under a bar, whatever
//     the browser's own way (WebKit reveals a text field by its caret, a smooth scroll starts a frame later). Where it
//     lands is the browsers' own "centre if needed" in the window's clear spans (the window less the bars beside it: one
//     band between the bars at the window's edges; a bar that floats between them - "Back to top" above a short
//     window's bottom bars at 200 % zoom - has a span above it and one below): inside a span nothing moves, partly
//     inside one it fits it goes to that span's nearest edge, else it is centred in the largest span, taller than every
//     span its top shows. A floating control that appears past a scroll position ("Back to top") counts as soon as the
//     move takes the page there. A jump that leaves the control where it was (in a sticky column that stays stuck) is
//     taken back at once.
//  3. Twice later, once the page is still and a quarter second after, the control is checked again (a toast, a strip
//     that sticks, a bar that settles later), and again each time the keyboard changes the focused control (a tick, a
//     switch, a choice, a typed character - typing joins the looks already going on for its field), however long after
//     the Tab: the save bar or the bulk bar that the change brings never ends over it. A text area (or an editable area)
//     taller than every clear span cannot show whole: there the looks keep the line being typed - its caret (an empty
//     line of a right-to-left field: the whole line, WebKit paints that caret at either end) - clear of the bars,
//     where the browser's own caret reveal puts it, and never move the page for the field's top. Those looks
//     never undo the visitor's own act: the wheel, a pointer or a key that moves the page ends them (the arrows, the
//     page keys, Space where the control does not take it, Enter on a link, find in the page, a shortcut) - not a
//     modifier on its own, nor a key the focused control takes for itself (typing in a text field, Space on a check
//     box, a switch or a button, a choice in a select: the control changes and the page stays) - and a look moves
//     nothing once the page has been scrolled, whatever scrolled it (the scroll bar, find in the page, a script): the
//     second look since the first, the first since one frame after the focus move or the change (where this module put
//     the control or found it clear; where it left the control to the browser - a scrolling box that hides it, a
//     script's focus out of the window - the first look stays as it was, unguarded). After a key typed into the field
//     that scroll may be the browser's own reveal of the caret (Chromium's smooth one starts after that frame): the look
//     then goes on, but only further the same way, never back, and no look comes after it.
//  4. A control in a fixed or stuck bar or a fixed layer (the header's, "Skip to content", a dialog's) is on screen as it
//     is: no padding and no scroll for it, so the page does not move under a stuck header. Chromium works out its focus
//     scroll with the padding of the key press, so the key press looks at the stop it moves to (the next one in
//     document order). A sticky bar in its own place is content like any other.
//  5. A scrolling box that declares a vertical scroll-padding of its own (the bell's list, under its stuck day
//     headings) gets its focused control inside that band at once too: Firefox leaves a partly visible one where it is.
//  6. Where the box of the control fits no clear span beside the bars - even with its ring clipped - but fits one once
//     "Back to top" is left out (a short window at 200 % zoom: the band between the bars across its column holds the box
//     but not box + ring), the control is placed clear of every other bar - centred in that band, its ring clipped the
//     same on both sides where the band is shorter than box + ring (WCAG 2.4.12 is about the box, not the ring) - and
//     "Back to top" steps aside (transparent, pointers pass through, at once: the page's own rule does the same while a
//     toast shows) while that control has the focus; it comes back when the focus moves on to a control it does not
//     cover, a pointer is used, the wheel turns, a key the visitor pressed actually scrolls the page, or the focus
//     leaves the page, and it never stands aside while it has the focus itself.
// A pointer ends keyboard mode and removes the padding. The geometry (barEdges, clearSpans, fitsClear, revealDelta,
// lineDelta, restingBox) and the rules that end the later looks (endsLooks, pageScrolled) are plain functions:
// tests/js/focus_clear.test.ts.

// The site's bars (resources/views/components/layout.blade.php and its pages): the header, the watch page's bar under
// it, the sticky strips of member pages, the phone bars at the bottom, the member save and bulk bars, the floating
// controls, the toast stack, the view-only demo note where it floats and a demo site's visitor buttons (.avd-try, lane
// DW's layouts/partials/visitor-choices.blade.php: off by default). Read only: their boxes and computed position - but
// "Back to top" steps aside (see `yields`) where nothing else can keep a control clear of it.
export const SITE_BARS = [
    '[data-avd-header]',
    '#avdStickyWatchBar',
    '.mk-channel__tabs',
    '.mk-settings__nav',
    '#avdMobileBottomNav',
    '.ad-slot-mobile',
    '.mk-tabbar',
    '.mk-savebar',
    '.mk-bulkbar',
    '.back-to-top',
    '.avd-daily-pill',
    '.mk-toasts',
    '.avd-view-only-note',
    '.avd-player-shell.avd-pip-active',
    '.avd-try',
].join(', ');

export type FocusClearOptions = {
    // Every bar or floating control that can cover the page: fixed or sticky elements (others are ignored).
    bars: string;
    // Room kept between a focused control's box and a bar: its focus ring (a 2px offset + a 2px line) and some air.
    room?: number;
    // Floating controls among the bars that show once the page has scrolled past a point (px): [selector, point].
    showsPast?: Array<[string, number]>;
    // Floating controls among the bars that step aside (transparent, pointers pass through) while they would cover the
    // control the keyboard moved to and its box fits no clear place beside them: in a short window at 200 % zoom the
    // band between the header (and a strip stuck under it) and the bottom bars can be shorter than "Back to top" plus the
    // ring, so a control across its column fits no clear span with it and fits one (box, ring clipped) without it. Back
    // as soon as the focus moves to a control it does not have to leave, a pointer is used, the wheel turns or a key the
    // visitor pressed actually scrolls the page (the page's own rule steps "Back to top" aside the same way while a toast
    // shows: member/_shared.scss). Default: the controls of `showsPast` ("Back to top").
    yields?: string[];
};

export type Box = {top: number; bottom: number; left: number; right: number};

// How far the bars stuck to the top and to the bottom edge of the window reach into it (px). Only bars across at least
// 40 % of the window count (a side column, the studio menu or the Account settings column, covers nothing beside it);
// the top one grows by the bars that touch it (the watch page's bar or a stuck strip right under the header), the
// bottom one likewise (the ad strip floats 8px above the bottom bar).
export const barEdges = (bars: Box[], width: number, height: number): {top: number; bottom: number} => {
    const wide = bars.filter((box) => box.right - box.left >= width * 0.4 && box.bottom > box.top && box.bottom > 0 && box.top < height);
    let top = 0;

    wide.filter((box) => box.top < height / 2).sort((a, b) => a.top - b.top).forEach((box) => {
        if (box.top <= top + 2) top = Math.max(top, box.bottom);
    });

    let bottom = height;

    wide.filter((box) => box.top >= height / 2).sort((a, b) => b.bottom - a.bottom).forEach((box) => {
        if (box.bottom >= bottom - 12) bottom = Math.min(bottom, box.top);
    });

    return {top: Math.max(0, Math.round(top)), bottom: Math.max(0, Math.round(height - bottom))};
};

// The window's clear spans beside a box (px from the window's top, top to bottom): the window less every bar across the
// box's width, `room` px from each bar (none at a window edge with no bar). The bars stuck to the window's edges (the
// header and what sticks under it, the bottom bars) leave the one band between them; a bar that floats between them
// leaves a span above it and one below - "Back to top" above a short window's save bar and tab bar (Video details at
// 200 % zoom of a 1366x625 window: 133-173 in a 312px window) is no bar "from above" because its middle is in the
// window's upper half (lane FE's verification FE-V4-01: sorted that way, it left a 4px band below it, and the controls
// across its column ended partly under it while the 57px above it were free).
export const clearSpans = (box: Box, bars: Box[], height: number, room: number): Array<[number, number]> => {
    let spans: Array<[number, number]> = [[0, height]];

    for (const bar of bars) {
        if (bar.right <= box.left || bar.left >= box.right || bar.bottom <= bar.top) {
            continue;
        }

        const next: Array<[number, number]> = [];

        for (const [from, to] of spans) {
            if (bar.bottom + room <= from || bar.top - room >= to) {
                next.push([from, to]);
                continue;
            }

            if (bar.top - room > from) {
                next.push([from, bar.top - room]);
            }

            if (bar.bottom + room < to) {
                next.push([bar.bottom + room, to]);
            }
        }

        spans = next;
    }

    return spans;
};

// Whether a box fits whole in one of the window's clear spans beside it with its focus ring clear too (room on each
// side): a control taller than every span (a text area at 200 % zoom or in a short window) cannot show whole. The later
// looks use this to tell a tall field (its caret's line counts) from one that fits.
export const fitsClear = (box: Box, bars: Box[], height: number, room: number): boolean =>
    clearSpans(box, bars, height, room).some(([from, to]) => to - from >= box.bottom - box.top);

// Whether a box fits whole in one of the window's clear spans beside it with its ring clipped if need be (room 0): no
// part of the box is under a bar, though its focus ring (half the room on each side) may be clipped where the band left
// between the bars is shorter than box + ring - the 49.8px band on the watch page past its sticky bar at 200 % zoom
// holds a 44px control's box but not its ring (lane FE's verification FE-V5-02). WCAG 2.4.12 is about the component (the
// box), not its ring; revealDelta centres such a box so the ring is clipped the same on both sides. This decides whether
// "Back to top" must step aside and whether the box can be placed clear of it. A box taller than every raw band (the
// video, a card, a text area) cannot be shown whole even so.
export const fitsBox = (box: Box, bars: Box[], height: number): boolean =>
    clearSpans(box, bars, height, 0).some(([from, to]) => to - from >= box.bottom - box.top);

// Whether a floating control that can step aside ("Back to top"; `bar`: its box where it shows, null while the page does
// not show it) must stand aside for a focused control's box: the box fits no clear span beside every bar (`all`) even
// with its ring clipped but fits one (ring clipped if need be) beside every bar that does not step aside (`solid`) - in a
// short window at 200 % zoom the band left between the bars across the button's column is shorter than the box -, and the
// floating control covers the box's ring (half the room: a 2px offset + a 2px line). Never where the box fits beside it
// (the control goes there, the button stays) nor for a box taller than every raw band even without it (stepping aside
// would not show it whole). The fit is on the box, not box + ring (WCAG 2.4.12: no part of the COMPONENT hidden), so the
// button steps aside where the band holds the box but clips the ring (FE-V5-02).
export const mustStepAside = (box: Box, all: Box[], solid: Box[], bar: Box | null, height: number, room: number): boolean =>
    bar !== null && reaches(bar, box, room / 2) && solid.length < all.length && !fitsBox(box, all, height) && fitsBox(box, solid, height);

// Whether a bar's box reaches a control's box grown by `reach` px on every side (its ring).
const reaches = (bar: Box, box: Box, reach: number): boolean =>
    bar.left < box.right + reach && bar.right > box.left - reach && bar.top < box.bottom + reach && bar.bottom > box.top - reach;

// The scroll (px, as for window.scrollBy) that shows a control's box `room` px clear of the bars beside it, the way the
// browsers' own focus scroll treats the root's scroll-padding ("centre if needed"), in the window's clear spans beside
// it (clearSpans): nothing when it is inside a span; partly inside a span it fits: to that span's nearest edge (the
// smaller move of two); else (under a bar, out of the window): centred in the largest span; taller than every span: its
// top at the largest span's top (nothing when its top already shows inside that span) - but one that still fits the raw
// band between the bars (the band can be shorter than box + ring at 200 % zoom: lane FE's FE-V5-02) is centred in that
// band so no part of the box is under a bar and its ring (half the room: a 2px offset + a 2px line) is clipped the same
// on both sides (nothing when the box already lies within the raw band); one taller than the roomed span by no more than
// the room is centred in it, its ring clear on both sides (nothing when it already lies within the span widened by half
// the room). With the bars at the window's edges only, the one span is the band between them and every result is the
// browsers' own; a floating bar has a place above it and one below. No span (a column every bar covers): nothing, the
// control is left to the browser.
export const revealDelta = (box: Box, bars: Box[], height: number, room: number): number => {
    const spans = clearSpans(box, bars, height, room).filter(([from, to]) => to - from >= 1);

    if (spans.length === 0) {
        return 0;
    }

    if (spans.some(([from, to]) => box.top >= from && box.bottom <= to)) {
        return 0;
    }

    const size = box.bottom - box.top;
    const middle = (box.top + box.bottom) / 2;
    // the largest span (of two alike, the one nearer the box)
    let [top, bottom] = spans[0];

    for (const [from, to] of spans) {
        const gain = (to - from) - (bottom - top);

        if (gain > 0 || (gain === 0 && Math.abs((from + to) / 2 - middle) < Math.abs((top + bottom) / 2 - middle))) {
            top = from;
            bottom = to;
        }
    }

    if (size > bottom - top + room) {
        // taller than the roomed span by more than the room: where the box still fits the raw band between the bars (it
        // can be shorter than box + ring at 200 % zoom: lane FE's FE-V5-02), centre it there so no part of the box is
        // under a bar and the ring is clipped the same on both sides; else show its top at the span's top
        const raw = clearSpans(box, bars, height, 0);
        let band: [number, number] | null = null;

        for (const [from, to] of raw) {
            if (from - 1 <= top && to + 1 >= bottom && (band === null || to - from > band[1] - band[0])) {
                band = [from, to];
            }
        }

        if (band !== null && size <= band[1] - band[0]) {
            return box.top >= band[0] && box.bottom <= band[1] ? 0 : Math.round(middle - (band[0] + band[1]) / 2);
        }

        return box.top >= top && box.top < bottom ? 0 : Math.round(box.top - top);
    }

    if (size > bottom - top) {
        return box.top >= top - room / 2 && box.bottom <= bottom + room / 2 ? 0 : Math.round(middle - (top + bottom) / 2);
    }

    let nearest: number | null = null;

    for (const [from, to] of spans) {
        if (to - from >= size && box.bottom > from && box.top < to) {
            const move = box.top < from ? Math.floor(box.top - from) : Math.ceil(box.bottom - to);

            if (nearest === null || Math.abs(move) < Math.abs(nearest)) {
                nearest = move;
            }
        }
    }

    if (nearest !== null) {
        return nearest;
    }

    return Math.round(middle - (top + bottom) / 2);
};

// The scroll (px, as for window.scrollBy) that shows the line a text control types on - its caret's line box `line`
// (left / right: around the caret) - for a control taller than every clear span, which cannot show whole (a text area at
// 200 % zoom or in a short window): nothing while no bar covers any of the line and its middle is inside the window,
// which is where the browsers' own caret reveal leaves it (the root's scroll-padding keeps it `room` px clear of the
// bars; at an edge with no bar they take it to the window's edge, the line box a few px past it); else the line to the
// nearest place in the window where it fits clear of the bars, `room` px from each (none at an edge with no bar), as
// the browser does for the next key typed - a bar that floats ("Back to top" in the middle of a short window) has a
// place above it and one below. Never the field's top: that rule undid the browser's caret reveal at every key (lane
// FE's verification FE-V3-01). Only the bars over the caret count ("Back to top" covers the end of a line; for an empty
// line of a right-to-left field `line` spans the whole line: see emptyLineAt); nothing when no place fits the line.
export const lineDelta = (line: Box, bars: Box[], height: number, room: number): number => {
    const over = bars.filter((bar) => bar.right > line.left && bar.left < line.right && bar.bottom > bar.top);
    const middle = (line.top + line.bottom) / 2;

    if (middle >= 0 && middle <= height && !over.some((bar) => bar.top < line.bottom && bar.bottom > line.top)) {
        return 0;
    }

    // the nearest place the line fits in, in the window's spans clear of every bar over the caret, `room` px from each
    // (none at an edge with no bar)
    const size = line.bottom - line.top;
    let best: number | null = null;

    for (const [from, to] of clearSpans(line, over, height, room)) {
        if (to - from < size) {
            continue;
        }

        const delta = line.top - Math.min(Math.max(line.top, from), to - size);

        if (best === null || Math.abs(delta) < Math.abs(best)) {
            best = delta;
        }
    }

    if (best === null) {
        return 0;
    }

    return best < 0 ? Math.floor(best) : Math.ceil(best);
};

// The box of an element before a scale-and-move transform (a control that fades in from a smaller, lower place, as
// "Back to top" does on public pages): its drawn box, the transform's scale (a, d) and move (e, f), and the transform's
// origin inside the box (px).
export const restingBox = (box: Box, a: number, d: number, e: number, f: number, originX: number, originY: number): Box => {
    const left = box.left - e - originX * (1 - a);
    const top = box.top - f - originY * (1 - d);

    return {left, top, right: left + (box.right - box.left) / a, bottom: top + (box.bottom - box.top) / d};
};

// Keys that change nothing on their own: the modifiers (Shift begins Shift+Tab, Option begins Option+Tab) and the locks.
const MODIFIERS = ['Shift', 'Control', 'Alt', 'AltGraph', 'Meta', 'OS', 'Fn', 'FnLock', 'Hyper', 'Super', 'Symbol', 'SymbolLock', 'CapsLock', 'NumLock', 'ScrollLock'];

// What a focused control does with a key by itself (lane FE's probe in Chromium, Firefox and WebKit on a static page):
// 'text' (a text-like input, a text area, an editable area) types and
// moves its caret; 'choice' (a select, a list box) picks with every plain key; 'slider' (a range) moves with the arrows,
// Page Up / Page Down, Home and End; 'tick' (a check box, a switch) ticks with Space; 'radio' is checked with Space;
// 'press' (a button, a summary, a submit / file / colour input) is pressed with Space or Enter; 'other' (a link, a
// focusable block) takes no key of its own.
export type ControlKind = 'text' | 'choice' | 'slider' | 'tick' | 'radio' | 'press' | 'other';

// Whether a key press ends the later looks: it is the visitor's own act after the Tab, and a look must never move the
// page against it - a key that scrolls the page (the arrows, Page Up / Page Down, Home / End, Space where the control
// does not take it), Enter on a link (an in-page link jumps the page), find in the page and the other function keys, a
// shortcut (`shortcut`: Ctrl / Cmd held), a page's own key. Not a Tab (its own focus move brings its own looks; Ctrl /
// Cmd + Tab leave the page), not a modifier on its own, and not a key the focused control (`kind`) takes for itself: the
// control changes and the page stays, so the looks still clear it of a bar the change brings (the save bar, the bulk
// bar). Measured exceptions: in a text field Page Up / Page Down and Alt / Option (`alt`) with an arrow, Home or End
// scroll the page (Chromium, WebKit); Space on a range scrolls it (Chromium, WebKit); an arrow on a radio at the end of
// its group scrolls it (WebKit) - and where an arrow moves the focus to the next radio, that focus brings its own looks.
export const endsLooks = (key: string, shortcut: boolean, alt: boolean, kind: ControlKind): boolean => {
    if (key === 'Tab' || MODIFIERS.includes(key)) {
        return false;
    }

    if (shortcut || /^F\d{1,2}$/.test(key)) {
        return true;
    }

    const press = key === ' ' || key === 'Spacebar' || key === 'Enter';
    const page = key === 'PageUp' || key === 'PageDown';
    const moves = page || key === 'Home' || key === 'End' || key.startsWith('Arrow');

    switch (kind) {
        case 'text':
            return page || (alt && moves);
        case 'choice':
            return false;
        case 'slider':
            return !moves;
        case 'tick':
        case 'radio':
        case 'press':
            return !press;
        default:
            return true;
    }
};

// Whether the page has been scrolled since a look (`before`: the page's scroll position then and the control's place in
// the document, its window top + that scroll position): the scroll position moved more than 1px and the control moved
// with the page (its place in the document is where it was). Scroll anchoring (content above it grew or shrank, the
// browser keeping the view still) moves the scroll position and the control's place together and is no scroll; a
// layout change in view leaves the scroll position where it was.
export const pageScrolled = (before: {y: number; at: number}, y: number, top: number): boolean =>
    Math.abs(y - before.y) > 1 && Math.abs(top + y - before.at) <= 1;

// The opacity a bar is on its way to: one that is fading in ("Back to top" once the page passes 420px) covers already.
const comingOpacity = (bar: HTMLElement, style: CSSStyleDeclaration): number => {
    let opacity = Number(style.opacity);

    if (typeof bar.getAnimations === 'function') {
        bar.getAnimations().forEach((animation) => {
            const effect = animation.effect;

            if (typeof KeyframeEffect !== 'undefined' && effect instanceof KeyframeEffect) {
                const frames = effect.getKeyframes();
                const end = frames.length > 0 ? frames[frames.length - 1].opacity : undefined;

                if (end !== undefined && end !== null && end !== '') {
                    opacity = Math.max(opacity, Number(end));
                }
            }
        });
    }

    return opacity;
};

// A sticky bar covers only where it sticks: at its top inset (a strip under the header, a table's head) or at its bottom
// inset (a save bar). In its own place in the page it is content like any other.
const stuck = (box: DOMRect, style: CSSStyleDeclaration): boolean => {
    if (style.position === 'fixed') {
        return true;
    }

    const top = parseFloat(style.top);
    const bottom = parseFloat(style.bottom);

    return (!Number.isNaN(top) && Math.abs(box.top - top) <= 1.5)
        || (!Number.isNaN(bottom) && Math.abs(window.innerHeight - box.bottom - bottom) <= 1.5);
};

// A bar's box while it can cover something: shown (or fading in), fixed or stuck, inside the window.
const coverBox = (bar: HTMLElement): DOMRect | null => {
    const box = bar.getBoundingClientRect();

    if (box.width < 1 || box.height < 1 || box.bottom <= 0 || box.top >= window.innerHeight) {
        return null;
    }

    const style = window.getComputedStyle(bar);

    if ((style.position !== 'fixed' && style.position !== 'sticky') || style.visibility === 'hidden' || comingOpacity(bar, style) < 0.1 || !stuck(box, style)) {
        return null;
    }

    return box;
};

// The box of a floating control that shows past a scroll position, where it rests once shown (its fade-in transform
// undone; a transform other than a scale and a move is taken as drawn).
const shownBox = (bar: HTMLElement, style: CSSStyleDeclaration): Box | null => {
    const box = bar.getBoundingClientRect();

    if (box.width < 1 || box.height < 1 || style.display === 'none' || style.visibility === 'hidden') {
        return null;
    }

    const matrix = /^matrix\(([^)]+)\)$/.exec(style.transform || '');

    if (!matrix) {
        return box;
    }

    const [a, b, c, d, e, f] = matrix[1].split(',').map(Number);
    const [originX, originY] = (style.transformOrigin || '0 0').split(' ').map((value) => parseFloat(value) || 0);

    return b === 0 && c === 0 && a > 0 && d > 0 ? restingBox(box, a, d, e, f, originX, originY) : box;
};

// The box a sighted keyboard user looks at: a visually hidden input (a switch, a check box, a file input) shows its
// focus on its label or tile.
const visibleBox = (element: HTMLElement): DOMRect | null => {
    let target: Element = element;
    const own = element.getBoundingClientRect();

    if (own.width < 2 || own.height < 2 || Number(window.getComputedStyle(element).opacity) === 0) {
        target = element.closest('label') || element.parentElement || element;
    }

    const box = target.getBoundingClientRect();

    return box.width < 1 || box.height < 1 ? null : box;
};

// A control that a scrolling box around it hides (in part): the browser scrolls that box for it first, so the window's
// reveal is left to the later looks (a list in a scrolling panel, the admin's side column).
const hiddenByScroller = (element: HTMLElement, box: Box): boolean => {
    for (let node = element.parentElement; node && node !== document.body && node !== document.documentElement; node = node.parentElement) {
        const overflow = window.getComputedStyle(node).overflowY;

        if ((overflow === 'auto' || overflow === 'scroll' || overflow === 'overlay') && node.scrollHeight > node.clientHeight + 1) {
            const port = node.getBoundingClientRect();

            if (box.top < port.top - 1 || box.bottom > port.bottom + 1) {
                return true;
            }
        }
    }

    return false;
};

// A scrolling box that declares its own vertical scroll-padding (the bell's list keeps a row clear of its stuck day
// heading: member/_notify.scss) has a focused control brought inside that padded band at once, to its nearest edge:
// Firefox leaves a control that is partly visible where it is, under the heading. Only the nearest such box; a box
// without a vertical scroll-padding is left alone.
const clearInScroller = (element: HTMLElement): void => {
    for (let node = element.parentElement; node && node !== document.body && node !== document.documentElement; node = node.parentElement) {
        const style = window.getComputedStyle(node);
        const overflow = style.overflowY;

        if ((overflow !== 'auto' && overflow !== 'scroll' && overflow !== 'overlay') || node.scrollHeight <= node.clientHeight + 1) {
            continue;
        }

        const padTop = parseFloat(style.scrollPaddingTop) || 0;
        const padBottom = parseFloat(style.scrollPaddingBottom) || 0;

        if (padTop <= 0 && padBottom <= 0) {
            continue;
        }

        const port = node.getBoundingClientRect();
        const box = element.getBoundingClientRect();
        const top = port.top + node.clientTop + padTop;
        const bottom = port.top + node.clientTop + node.clientHeight - padBottom;
        const fits = box.bottom - box.top <= bottom - top;
        let move = 0;

        if (box.top < top && (fits || box.bottom < bottom)) {
            move = box.top - top;
        } else if (box.bottom > bottom && fits) {
            move = box.bottom - bottom;
        }

        if (move !== 0) {
            node.scrollTop += move;
        }

        return;
    }
};

// A control inside a bar that is fixed or stuck, or inside a fixed layer (a dialog, a side panel, a header menu), or
// fixed itself ("Skip to content" while it has the focus), moves with it: nothing covers it and the page cannot be
// scrolled to clear it. A sticky bar in its own place (a table's head row whose table has scrolled away, a strip below
// the window) is content like any other: its controls are brought clear as the rest.
const insideFixed = (element: Element, bars: string): boolean => {
    const bar = element.closest(bars);

    if (bar) {
        const style = window.getComputedStyle(bar);

        if (style.position === 'fixed' || (style.position === 'sticky' && stuck(bar.getBoundingClientRect(), style))) {
            return true;
        }
    }

    for (let node: Element | null = element; node && node !== document.body && node !== document.documentElement; node = node.parentElement) {
        if (window.getComputedStyle(node).position === 'fixed') {
            return true;
        }
    }

    return false;
};

const FOCUSABLE = 'a[href], area[href], button, input, select, textarea, iframe, summary, [tabindex], [contenteditable]';

// A stop of the browser's Tab: focusable, tabindex >= 0, enabled, rendered and visible, not inert.
const isStop = (element: Element): boolean => {
    if (!element.matches(FOCUSABLE) || (element as HTMLElement).tabIndex < 0) {
        return false;
    }

    if ((element as HTMLInputElement).disabled || (element as HTMLInputElement).type === 'hidden') {
        return false;
    }

    return element.getClientRects().length > 0 && !element.closest('[inert]') && window.getComputedStyle(element).visibility !== 'hidden';
};

// The control Tab (Shift+Tab) moves to from `from`: the next (previous) stop in document order, which is the order of
// the site's pages (none has a positive tabindex). Null when it is not found within a short walk.
const nextStop = (from: Element, backward: boolean): Element | null => {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_ELEMENT);
    walker.currentNode = from;

    for (let steps = 0; steps < 600; steps++) {
        const node = (backward ? walker.previousNode() : walker.nextNode()) as Element | null;

        if (!node) {
            return null;
        }

        if (isStop(node)) {
            return node;
        }
    }

    return null;
};

const TEXT_TYPES = ['text', 'search', 'email', 'url', 'tel', 'password', 'number', 'date', 'datetime-local', 'month', 'week', 'time'];
// inputs pressed like a button (Space / Enter run their own action)
const PRESS_TYPES = ['button', 'submit', 'reset', 'image', 'file', 'color'];

// The kind of the focused control, for endsLooks(): what it does with a key by itself.
const controlKind = (target: EventTarget | null): ControlKind => {
    if (!(target instanceof HTMLElement)) {
        return 'other';
    }

    if (target.isContentEditable || target instanceof HTMLTextAreaElement) {
        return 'text';
    }

    if (target instanceof HTMLSelectElement) {
        return 'choice';
    }

    if (target instanceof HTMLInputElement) {
        const type = target.type;

        if (TEXT_TYPES.includes(type)) {
            return 'text';
        }

        if (type === 'checkbox') {
            return 'tick';
        }

        if (type === 'radio') {
            return 'radio';
        }

        if (type === 'range') {
            return 'slider';
        }

        return PRESS_TYPES.includes(type) ? 'press' : 'other';
    }

    return target instanceof HTMLButtonElement || target.localName === 'summary' ? 'press' : 'other';
};

// A control that types lines (a text area, an editable area): taller than every clear span, its caret's line counts.
const typesLines = (element: HTMLElement): boolean => element instanceof HTMLTextAreaElement || element.isContentEditable;

// The CSS properties that lay a text area's text out, taken over by the hidden copy that finds its caret's line.
const TEXT_LAYOUT = [
    'direction', 'unicode-bidi', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border-top-width',
    'border-right-width', 'border-bottom-width', 'border-left-width', 'font-family', 'font-size', 'font-style',
    'font-variant', 'font-weight', 'font-stretch', 'font-size-adjust', 'font-kerning', 'font-feature-settings',
    'font-variation-settings', 'font-optical-sizing', 'font-synthesis', 'line-height', 'letter-spacing', 'word-spacing',
    'tab-size', 'text-align', 'text-transform', 'text-indent', 'text-rendering', 'white-space', 'word-break',
    'overflow-wrap', 'line-break', 'hyphens',
];

// Past this many characters before the caret its line is not looked for (laying the copy out would cost too much in a
// frame): the field is left to the browser's own caret reveal.
const CARET_TEXT_MAX = 20000;

// Whether the caret at `at` in a text area's value stands on an empty line (no character before it on its line, none
// after it). In a right-to-left field such a caret has no place the hidden copy can tell: WebKit paints it at the line's
// LEFT end (the inline end, where "Back to top" floats) after a typed Enter and on an empty line between two others, at
// the right end (the line's start, where the copy puts it, as Chromium and Firefox always do) after a value set by script
// or a Backspace (lane FE's probe of the caret in a right-to-left field): the whole line counts there.
export const emptyLineAt = (value: string, at: number): boolean => (at <= 0 || value.charAt(at - 1) === '\n')
    && (at >= value.length || value.charAt(at) === '\n');

// The line a text area or an editable area types on - its caret, the moving end of the selection - in window px: the
// line's top and bottom, left / right 1px around the caret (an empty line of a right-to-left text area: the field's
// whole width inside its border, its caret's place unknown - emptyLineAt). A text area's caret has no box of its own: a
// hidden copy of its text up to the caret (and the rest of the caret's line in a marker, so the last word wraps as in the
// field), laid out at the field's text width (its own scroll bar left out) with its font, spacing and wrapping, tells the
// line (lane FE's probe of the caret: within 1px of the painted caret in Chromium,
// its line box where Firefox and WebKit reveal their caret). Null when it cannot be told (no caret in it, a vertical
// writing mode, a very long text before the caret, a line the field's own scroll hides): the field is then left to the
// browser.
const caretLine = (element: HTMLElement): Box | null => {
    const style = window.getComputedStyle(element);

    if (style.writingMode && style.writingMode !== 'horizontal-tb') {
        return null;
    }

    if (element instanceof HTMLTextAreaElement) {
        const at = element.selectionDirection === 'backward' ? element.selectionStart : element.selectionEnd;

        if (typeof at !== 'number' || at > CARET_TEXT_MAX) {
            return null;
        }

        const field = element.getBoundingClientRect();
        const borderLeft = parseFloat(style.borderLeftWidth) || 0;
        const borderRight = parseFloat(style.borderRightWidth) || 0;
        // the field's own vertical scroll bar, when it shows one (an overlay scroll bar takes no room)
        const scrollBar = element.offsetWidth - element.clientWidth - Math.round(borderLeft + borderRight);
        const width = field.width - borderLeft - borderRight - (parseFloat(style.paddingLeft) || 0)
            - (parseFloat(style.paddingRight) || 0) - (scrollBar > 1 ? scrollBar : 0);
        const copy = document.createElement('div');
        TEXT_LAYOUT.forEach((name) => copy.style.setProperty(name, style.getPropertyValue(name)));
        // out of the page's flow and scroll range, never painted, as wide as the field's text
        copy.style.setProperty('box-sizing', 'content-box');
        copy.style.setProperty('width', `${Math.max(0, width)}px`);
        copy.style.setProperty('height', '0');
        copy.style.setProperty('overflow', 'hidden');
        copy.style.setProperty('border-style', 'solid');
        copy.style.setProperty('position', 'fixed');
        copy.style.setProperty('top', '0');
        copy.style.setProperty('left', '0');
        copy.style.setProperty('visibility', 'hidden');
        copy.style.setProperty('pointer-events', 'none');
        copy.textContent = element.value.slice(0, at);
        const marker = document.createElement('span');
        marker.textContent = element.value.slice(at).split('\n', 1)[0].slice(0, 200) || '\u200b';
        copy.appendChild(marker);
        document.body.appendChild(copy);
        const first = marker.getClientRects()[0];
        const origin = copy.getBoundingClientRect();
        copy.remove();

        if (!first) {
            return null;
        }

        // the font's box of the caret's line, grown by the line's leading to the line's own box
        const lineHeight = parseFloat(style.lineHeight);
        const lead = Number.isFinite(lineHeight) ? Math.max(0, (lineHeight - first.height) / 2) : 0;
        const top = field.top + first.top - origin.top - element.scrollTop - lead;
        const bottom = top + first.height + 2 * lead;
        const x = field.left + (style.direction === 'rtl' ? first.right : first.left) - origin.left - element.scrollLeft;

        // a line the field's own scroll hides is the field's business (the browser scrolls the field to its caret first)
        if (bottom <= field.top + (parseFloat(style.borderTopWidth) || 0) || top >= field.bottom - (parseFloat(style.borderBottomWidth) || 0)) {
            return null;
        }

        // an empty line of a right-to-left field: its caret may be at either end (WebKit), the whole line counts
        if (style.direction === 'rtl' && emptyLineAt(element.value, at)) {
            return {top, bottom, left: field.left + borderLeft, right: field.right - borderRight};
        }

        return {top, bottom, left: x - 1, right: x + 1};
    }

    if (element.isContentEditable) {
        const selection = window.getSelection();
        const node = selection ? selection.focusNode : null;

        if (!selection || !node || !element.contains(node)) {
            return null;
        }

        const range = document.createRange();
        range.setStart(node, selection.focusOffset);
        range.collapse(true);
        const rects = range.getClientRects();
        const caret = rects.length > 0 ? rects[0] : range.getBoundingClientRect();

        return caret.height < 1 ? null : {top: caret.top, bottom: caret.bottom, left: caret.left - 1, right: caret.left + 1};
    }

    return null;
};

export const installFocusClear = ({bars, room = 8, showsPast = [], yields = showsPast.map(([selector]) => selector)}: FocusClearOptions): void => {
    const root = document.documentElement;
    const yieldsSel = yields.join(', ');
    let keyboard = false;
    // a Tab key went down and its focus move has not arrived yet (it can come in a later task: Firefox)
    let tabbing = false;
    let padding = '';
    let pending = 0;
    // the later looks going on (null when none): their control, their ticket, whether they measure the bars again and
    // whether a key typed into the field since their reference point may have had the browser reveal its caret
    let chain: {element: HTMLElement; ticket: number; refresh: boolean; follows: boolean} | null = null;

    const pastPoint = (bar: HTMLElement): number | null => {
        for (const [selector, point] of showsPast) {
            if (bar.matches(selector)) {
                return point;
            }
        }

        return null;
    };

    // The bars that cover the window now (or, for one that shows past a scroll position, once the page is at `atY`
    // or where it is now: a floating control that is about to show counts already, one about to hide still does).
    // `solid`: without the floating controls that step aside (`yields`).
    const covers = (except?: HTMLElement, atY: number = window.scrollY, solid = false): Box[] => {
        const boxes: Box[] = [];

        document.querySelectorAll<HTMLElement>(bars).forEach((bar) => {
            if ((except && bar.contains(except)) || (solid && yieldsSel !== '' && bar.matches(yieldsSel))) {
                return;
            }

            const point = pastPoint(bar);
            const box = point === null
                ? coverBox(bar)
                : (Math.max(window.scrollY, atY) > point ? shownBox(bar, window.getComputedStyle(bar)) : null);

            if (box) {
                boxes.push(box);
            }
        });

        return boxes;
    };

    // The bars a control is placed clear of: every bar - or, where its box fits no clear span beside them (even with the
    // ring clipped) but fits one once the floating controls that step aside are left out (a short window at 200 % zoom:
    // "Back to top" fills the band between the bars across its column), every bar but those, which then step aside for it
    // (stepAsideFor()). The fit is on the box (fitsBox: WCAG 2.4.12 is about the component), so the button steps aside
    // where the band holds the box but clips the ring (FE-V5-02); revealDelta then centres the box, clipping the ring
    // the same on both sides.
    const placing = (element: HTMLElement, shown: Box, atY: number = window.scrollY): Box[] => {
        const height = window.innerHeight;
        const covering = covers(element, atY);

        if (yieldsSel === '' || fitsBox(shown, covering, height)) {
            return covering;
        }

        const solid = covers(element, atY, true);

        return solid.length < covering.length && fitsBox(shown, solid, height) ? solid : covering;
    };

    // A floating control that steps aside: its box where it shows (its fade-in transform undone, whatever its opacity),
    // and whether the page is taking it away (back above its scroll point: "Back to top" fades out over the next frames);
    // null while the page does not show it (above its point and no longer drawn, not standing aside) or it is not fixed
    // or sticky.
    const floatingBox = (bar: HTMLElement): {box: Box; going: boolean} | null => {
        const style = window.getComputedStyle(bar);

        if (style.position !== 'fixed' && style.position !== 'sticky') {
            return null;
        }

        const point = pastPoint(bar);
        const going = point !== null && window.scrollY <= point;

        if (going && !aside.has(bar) && Number(style.opacity) < 0.1) {
            return null;
        }

        const box = shownBox(bar, style);

        return box ? {box, going} : null;
    };

    // The floating controls standing aside now, with the inline values they had (put back as they were).
    const aside = new Map<HTMLElement, Array<[string, string, string]>>();
    const ASIDE_STYLE: Array<[string, string]> = [['opacity', '0'], ['pointer-events', 'none'], ['transition', 'none']];

    const stepAside = (bar: HTMLElement, on: boolean): void => {
        const kept = aside.get(bar);

        if (on && !kept) {
            aside.set(bar, ASIDE_STYLE.map(([name]) => [name, bar.style.getPropertyValue(name), bar.style.getPropertyPriority(name)]));
            ASIDE_STYLE.forEach(([name, value]) => bar.style.setProperty(name, value, 'important'));
        } else if (!on && kept) {
            aside.delete(bar);
            kept.forEach(([name, value, priority]) => {
                if (value) {
                    bar.style.setProperty(name, value, priority);
                } else {
                    bar.style.removeProperty(name);
                }
            });
        }
    };

    // The floating controls that must stand aside for the focused control (`element`, in keyboard mode): mustStepAside()
    // - or one the page is taking away that still reaches it; every other one comes back. Null: they all come back (a
    // pointer, the wheel, a key that scrolls the page, the focus gone or in a bar or a layer). Only while one of them
    // shows near the control are the bars measured.
    const stepAsideFor = (element: HTMLElement | null): void => {
        if (yieldsSel === '' || (element === null && aside.size === 0)) {
            return;
        }

        const shown = element !== null && keyboard ? visibleBox(element) : null;
        let all: Box[] | null = null;
        let solid: Box[] | null = null;

        document.querySelectorAll<HTMLElement>(yieldsSel).forEach((bar) => {
            let on = false;
            const floating = shown !== null && element !== null && !bar.contains(element) && !element.contains(bar) ? floatingBox(bar) : null;

            if (floating !== null && shown !== null && element !== null && reaches(floating.box, shown, room / 2)) {
                if (floating.going) {
                    // the move took the page back above its point and the page is taking it away: gone at once, not
                    // fading out over the control for the next frames
                    on = true;
                } else {
                    all = all || covers(element);
                    solid = solid || covers(element, window.scrollY, true);
                    on = mustStepAside(shown, all, solid, floating.box, window.innerHeight, room);
                }
            }

            stepAside(bar, on);
        });
    };

    // After a key that is the visitor's own act (endsLooks), "Back to top" - which may have stepped aside for the focused
    // control - comes back only once that key actually scrolls the page: a key that moves nothing (Escape, a letter on a
    // button or a link) would uncover nothing and only re-cover the control it stepped aside for (lane FE's verification
    // FE-V5-01). No scroll listener (no work on every scroll of the page's life, keeping weak devices fast): a short watch
    // of the frames after such a key, only while a control stands aside, re-measures the step-aside while the page is
    // moving (the control it stepped aside for may have scrolled clear - stepAsideFor brings the button back then) and
    // stops once the page is still again or nothing stands aside. A smooth keyboard scroll (the page's scroll-behavior is
    // smooth) settles over several frames, each re-measured; a key that scrolls nothing leaves the button aside.
    let watching = 0;
    const bringBackOnScroll = (): void => {
        if (aside.size === 0) {
            return;
        }

        const ticket = ++watching;
        let y = window.scrollY;
        let still = 0;
        let frames = 0;

        const look = (): void => {
            if (ticket !== watching || aside.size === 0) {
                return;
            }

            if (Math.abs(window.scrollY - y) > 1) {
                y = window.scrollY;
                still = 0;
                const element = keyboard && document.activeElement instanceof HTMLElement && document.activeElement !== document.body
                    ? document.activeElement
                    : null;
                stepAsideFor(element);
            } else {
                still++;
            }

            if (still < 4 && ++frames < 60) {
                window.requestAnimationFrame(look);
            }
        };

        window.requestAnimationFrame(look);
    };

    const clearPadding = (): void => {
        if (padding !== '') {
            padding = '';
            root.style.removeProperty('scroll-padding-top');
            root.style.removeProperty('scroll-padding-bottom');
        }
    };

    const setPadding = (): void => {
        const {top, bottom} = barEdges(covers(), root.clientWidth || window.innerWidth, window.innerHeight);

        if (top === 0 && bottom === 0) {
            clearPadding();
            return;
        }

        const next = `${top > 0 ? top + room : 0}px ${bottom > 0 ? bottom + room : 0}px`;

        if (next !== padding) {
            padding = next;
            root.style.setProperty('scroll-padding-top', `${top > 0 ? top + room : 0}px`);
            root.style.setProperty('scroll-padding-bottom', `${bottom > 0 ? bottom + room : 0}px`);
        }
    };

    // The page's own scroll-behavior is smooth (app.scss): the reveal is a jump, done at once (an inline value on the
    // root for that one call; "instant" is not known to every Safari that runs this script).
    const jump = (delta: number): boolean => {
        const before = window.scrollY;
        const inline = root.style.getPropertyValue('scroll-behavior');
        root.style.setProperty('scroll-behavior', 'auto');
        window.scrollBy(0, delta);

        if (inline) {
            root.style.setProperty('scroll-behavior', inline);
        } else {
            root.style.removeProperty('scroll-behavior');
        }

        return window.scrollY !== before;
    };

    // A jump that leaves the control where it was (a control in a sticky column that stays stuck, the page moving under
    // it) is taken back at once: the page stays as it was and the browser's own focus scroll decides, as before.
    const clearBy = (element: HTMLElement, delta: number): boolean => {
        const before = visibleBox(element);
        const y = window.scrollY;

        if (!before || !jump(delta)) {
            return false;
        }

        const after = visibleBox(element);

        if (after && Math.abs(after.top - before.top) < 0.5) {
            jump(y - window.scrollY);
            return false;
        }

        return true;
    };

    // The control's box brought clear of the bars, now. A move can make a strip stick or a floating control show: up to
    // three passes, each on what the page then shows. True when the control is where this module put it or found it
    // (clear of the bars, so the browser's own focus scroll has nothing left to do); false when it is left to the browser
    // (no box, a script's focus out of the window, a scrolling box that hides it, a jump taken back).
    const reveal = (element: HTMLElement, tabbed: boolean): boolean => {
        for (let pass = 0; pass < 3; pass++) {
            const shown = visibleBox(element);
            const height = window.innerHeight;

            // after a script's focus() (no Tab): a control out of the window is left to that script and the browser
            if (!shown || (!tabbed && (shown.bottom <= 0 || shown.top >= height)) || hiddenByScroller(element, shown)) {
                return false;
            }

            const first = revealDelta(shown, placing(element, shown), height, room);
            const delta = first === 0 ? 0 : revealDelta(shown, placing(element, shown, window.scrollY + first), height, room);

            if (delta === 0) {
                return true;
            }

            if (!clearBy(element, delta)) {
                return false;
            }
        }

        return true;
    };

    // The move a later look makes for the focused control: its box clear of the bars (revealDelta) - or, for a text area
    // or an editable area taller than every clear span (it cannot show whole: 200 % zoom, a short window), the line it
    // types on clear of them (lineDelta: where the browser's own caret reveal puts it); never the field's top, which took
    // the page away from the caret the browser had just revealed, at every key (lane FE's verification FE-V3-01). A caret
    // line that cannot be told leaves the field to the browser. The bars are those placing() keeps it clear of (where
    // "Back to top" steps aside for it, not that one).
    const lookDelta = (element: HTMLElement, shown: DOMRect): number => {
        const height = window.innerHeight;
        const covering = placing(element, shown);

        if (typesLines(element) && !fitsClear(shown, covering, height, room)) {
            const line = caretLine(element);

            return line ? lineDelta(line, covering, height, room) : 0;
        }

        return revealDelta(shown, covering, height, room);
    };

    // Later looks: once the page and the control are still for two frames (at most about a second), then a quarter second
    // later: one correction each if a bar covers it then (a toast, a strip that has just stuck, a bar that settled, the
    // bar a change brought). The visitor's own act ends them (`pending`: a pointer, the wheel, a key that moves the page),
    // and a look moves nothing once the page has been scrolled since the point it compares with (the scroll bar, find in
    // the page, a script: no event says so): the second look, since the first; the first look too when `guard` - since the
    // first frame after the focus move or the change, when this module put the control where it is or found it clear (on
    // a slow device the first look can come after a scroll the visitor started within a few frames of the Tab). Where the
    // control was left to the browser (a scrolling box hides it, a script's focus out of the window) the first look stays
    // unguarded: the browser may still be scrolling for it. After a key typed into the field (`follows`) the scroll since
    // that point may be the browser's own reveal of its caret, which Chromium makes smooth on the site and starts only
    // after that first frame (lane FE's probe, runs/p6/caret_probe_chromium.json): the look then goes on - its padding
    // and the bar the key brought still count - but may only move the page further the same way, never back, and it is
    // the last look, so no one's scroll is taken back. Looks that a change started or joined (`refresh`) measure the bars
    // again for the root's
    // padding first, so the bar the change brought counts for the browser's own scrolls too (the caret of the next lines
    // typed, Page Down); a control in a bar or a fixed layer (a dialog's field) is on screen as it is and ends them
    // (checked here, in the frame, where the styles are read anyway: no style read in the change's own event while the
    // visitor types).
    const check = (element: HTMLElement, refresh = false, guard = false, follows = false): void => {
        const ticket = ++pending;
        const own = {element, ticket, refresh, follows};
        chain = own;
        let last = '';
        let still = 0;
        let frames = 0;
        let rounds = 0;
        // the point a look compares with: the page's scroll position and the control's place in the document, one frame
        // after the focus move or the change (`guard`), then where the first look left the page
        let settled: {y: number; at: number} | null = null;

        const end = (): void => {
            if (chain === own) {
                chain = null;
            }
        };

        const step = (): void => {
            if (ticket !== pending || !keyboard || document.activeElement !== element) {
                end();
                return;
            }

            const box = element.getBoundingClientRect();

            if (guard && rounds === 0 && frames === 0) {
                settled = {y: window.scrollY, at: box.top + window.scrollY};
            }

            const now = `${Math.round(window.scrollY)}:${Math.round(box.top)}:${Math.round(box.left)}`;
            still = now === last ? still + 1 : 0;
            last = now;

            if (still < 2 && ++frames < 60) {
                window.requestAnimationFrame(step);
                return;
            }

            // the way the page has been scrolled since the point this look compares with: the look ends, or - after a
            // key typed into the field - goes on, never moving the page back
            let along = 0;

            if (settled !== null && pageScrolled(settled, window.scrollY, box.top)) {
                if (!own.follows) {
                    end();
                    return;
                }

                along = Math.sign(window.scrollY - settled.y);
            }

            if (own.refresh) {
                if (insideFixed(element, bars)) {
                    end();
                    return;
                }

                setPadding();
            }

            const shown = visibleBox(element);

            if (shown && shown.bottom > 0 && shown.top < window.innerHeight && !hiddenByScroller(element, shown)) {
                const delta = lookDelta(element, shown);

                if (delta !== 0 && (along === 0 || Math.sign(delta) === along)) {
                    clearBy(element, delta);
                }
            }

            // a floating control that covers it where nothing else can keep it clear steps aside ("Back to top" at 200 %
            // zoom); one that no longer has to comes back
            stepAsideFor(element);

            // a look that went on after someone's scroll is the last one: the next would compare with the page as that
            // scroll left it and could take it back (WebKit's slow frames brought the first look after a script scroll
            // made 60 ms after a typed key, and the second look undid it)
            if (++rounds < 2 && along === 0) {
                settled = {y: window.scrollY, at: element.getBoundingClientRect().top + window.scrollY};
                own.follows = false;
                window.setTimeout(() => {
                    last = '';
                    still = 0;
                    frames = 0;
                    window.requestAnimationFrame(step);
                }, 250);
            } else {
                end();
            }
        };

        window.requestAnimationFrame(step);
    };

    // The wheel (a mouse wheel, a touchpad) is the visitor's own scroll and fires no pointer event: it ends the later looks
    // too, and a floating control that stepped aside comes back. Listened to from the first Tab on (a page used by mouse
    // or touch only never has this listener); passive, it never holds a scroll back.
    let wheel = false;
    const listenWheel = (): void => {
        if (!wheel) {
            wheel = true;
            document.addEventListener('wheel', () => {
                pending++;
                stepAsideFor(null);
            }, {capture: true, passive: true});
        }
    };

    // A change the keyboard makes on the focused control (a tick, a switch, a choice, a typed character) can bring a bar:
    // the save bar while a form has unsaved changes, the phone bulk bar while rows are ticked, the admin's save bars. The
    // looks run again from it, whenever it comes (lane FE's verification FE-V2-01: a switch ticked a second after the Tab
    // ended wholly under the save bar), measuring the bars again. Capture: this runs before the page's own handlers show
    // the bar; the looks come in the next frames, once it shows. Listened to from the first Tab on (a page used by mouse
    // or touch only never has these listeners); a pointer ends keyboard mode first, so a change made with a click or a
    // tap is left alone. A control in a bar or a fixed layer (a dialog's field) is left as it is (the look checks it).
    // Typing joins the looks already going on for its field (their next look measures the bars again) instead of
    // starting them over at every key: a key typed costs no work of this module in its own frame (the fifth pass's
    // typing measure, tools/p5/perf_typing.js). A tick or a choice starts them over: its bar is cleared in a few frames.
    // A typed key (`follows`) may have the browser reveal its caret after the looks' reference point: they go on after
    // that scroll, only further the same way.
    let changes = false;
    const changed = (event: Event): void => {
        const element = event.target;

        if (!keyboard || !(element instanceof HTMLElement) || element !== document.activeElement) {
            return;
        }

        const typed = controlKind(element) === 'text';

        if (typed && chain !== null && chain.element === element && chain.ticket === pending) {
            chain.refresh = true;
            chain.follows = true;
            return;
        }

        check(element, true, true, typed);
    };
    const listenChanges = (): void => {
        if (!changes) {
            changes = true;
            document.addEventListener('input', changed, true);
            document.addEventListener('change', changed, true);
        }
    };

    document.addEventListener('keydown', (event) => {
        // Ctrl / Cmd + Tab belong to the browser; Alt + Tab to the system on Windows and Linux, while on macOS Option + Tab
        // is Safari's Tab through every item, links included (its plain Tab skips links)
        if (event.key !== 'Tab' || event.ctrlKey || event.metaKey) {
            // any other key is the visitor's own act when it moves the page (an arrow or Space scrolls it, Enter follows a
            // link): the later looks end, so none moves the page against it; a key the focused control takes for itself
            // (Space ticks a switch, an arrow picks in a select, a letter types) leaves them going (some synthetic key
            // events, e.g. a browser's autofill, carry no key name). "Back to top" comes back only once such a key really
            // scrolls the page (bringBackOnScroll), never on a key that moves nothing (Escape, a letter): that would
            // re-cover the control it stepped aside for (lane FE's verification FE-V5-01)
            if (endsLooks(String(event.key || ''), event.ctrlKey || event.metaKey, event.altKey, controlKind(event.target))) {
                pending++;
                bringBackOnScroll();
            }

            return;
        }

        keyboard = true;
        tabbing = true;
        listenWheel();
        listenChanges();

        // Chromium works out its focus scroll with the padding of the key press: a control in a stuck bar (the header's
        // own, from a page scrolled down) would be "revealed" below the padding, the page scrolling under the header for
        // nothing. No padding when the key moves into a bar or a fixed layer.
        const from = document.activeElement;
        const next = from && from !== document.body && from !== root ? nextStop(from, event.shiftKey) : null;

        if (next && insideFixed(next, bars)) {
            clearPadding();
        } else {
            setPadding();
        }
    }, true);

    document.addEventListener('keyup', (event) => {
        if (event.key === 'Tab') {
            tabbing = false;
        }
    }, true);

    document.addEventListener('pointerdown', () => {
        keyboard = false;
        tabbing = false;
        pending++;
        clearPadding();
        stepAsideFor(null);
    }, {capture: true, passive: true});

    // The focus gone from the page's controls (to the browser's own interface, a script's blur): a floating control that
    // stepped aside comes back (focusin brings the right one aside again when the focus returns).
    document.addEventListener('focusout', (event) => {
        if ((event as FocusEvent).relatedTarget === null) {
            stepAsideFor(null);
        }
    }, true);

    document.addEventListener('focusin', (event) => {
        const element = event.target;
        const tabbed = tabbing;
        tabbing = false;

        if (!keyboard || !(element instanceof HTMLElement) || element === document.body) {
            return;
        }

        clearInScroller(element);

        if (insideFixed(element, bars)) {
            // a control in a bar or a fixed layer is on screen as it is; WebKit works out its focus scroll after this
            // event (and the key press may not have known this stop): the padding goes (the next Tab sets it again);
            // "Back to top" itself among them, which never stands aside while it has the focus
            clearPadding();
            stepAsideFor(null);
            return;
        }

        setPadding();
        // where this module put the control (or found it clear) the browser's own focus scroll has nothing left to do: a
        // scroll before the first look is the visitor's or a script's, and that look leaves it alone
        const placed = reveal(element, tabbed);
        // a floating control that would still cover it where nothing else can keep it clear steps aside at once (no
        // frame shows the control under it); one that no longer has to comes back
        stepAsideFor(element);
        check(element, false, placed);
    }, true);
};
