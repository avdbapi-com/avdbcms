// R2R-02 (WCAG 2.2 Focus Not Obscured): the geometry of resources/js/layout/focusClear.ts with the bars measured on the
// real pages (390x844 phones and 1366x768; lane FE's survey of the bars in Chromium;
// at 200 % zoom 683x312, 640x360 and 960x540: runs/p7/survey_zoom_chromium_en.json):
// how far the stuck bars reach into the window (the root's scroll-padding while the keyboard is used), the window's
// clear spans beside a control (a bar that floats between the edge bars has a span above it and one below), the scroll
// that brings a focused control clear of every bar beside it (the browsers' own "centre if needed", for the control's
// whole box, in those spans), the scroll that keeps the line typed in a text area taller than every clear span clear of
// the bars (where the browsers' own caret reveal puts it; never the field's top), where a control that fades in from a
// smaller, lower place rests once shown ("Back to top"), and what ends the later looks (the visitor's own key or scroll
// right after a Tab is never undone; a key the focused control takes for itself is not that); an empty line of a
// right-to-left text area counts across its whole width (WebKit paints that caret at either end).
// Run: node tests/js/focus_clear.test.ts  (Node 22.18+ runs TypeScript directly). Exit code 1 on any failure.
// tests/Unit/FocusClearGeometryTest.php runs this file when Node is available.
import {barEdges, clearSpans, emptyLineAt, endsLooks, fitsBox, fitsClear, lineDelta, mustStepAside, pageScrolled, restingBox, revealDelta, type Box} from '../../resources/js/layout/focusClear.ts';

let failures = 0;
let passed = 0;
const check = (name: string, actual: unknown, expected: unknown): void => {
    if (JSON.stringify(actual) === JSON.stringify(expected)) {
        passed++;
    } else {
        failures++;
        console.error(`FAIL ${name}: got ${JSON.stringify(actual)}, expected ${JSON.stringify(expected)}`);
    }
};
const box = (left: number, top: number, width: number, height: number): Box => ({left, top, right: left + width, bottom: top + height});
const round = (b: Box): number[] => [b.left, b.top, b.right, b.bottom].map((v) => Math.round(v * 100) / 100);

// Phone 390x844, a public page with the phone ad strip: header 60px, ad strip 703-768 (8px above the bottom bar), the
// bottom bar 776-844, "Back to top" 40x40 at the end of the line.
const header = box(0, 0, 390, 60);
const strip = box(12, 703, 367, 65);
const bottomBar = box(0, 776, 390, 68);
const backToTop = box(334, 650, 40, 40);
check('phone: the header on top, the ad strip and the bottom bar below', barEdges([header, strip, bottomBar, backToTop], 390, 844), {top: 60, bottom: 141});
check('phone without the ad strip', barEdges([header, bottomBar], 390, 844), {top: 60, bottom: 68});

// The watch page's "Back to the video" bar right under the header joins it; the channel tabs only once they stick.
check('watch: the bar under the header adds to it', barEdges([header, box(0, 60, 390, 61), bottomBar], 390, 844), {top: 121, bottom: 68});
check('channel: tabs stuck under the header', barEdges([header, box(12, 60, 366, 45)], 390, 844), {top: 105, bottom: 0});
check('channel: tabs still in their place (not stuck) do not count', barEdges([header, box(12, 358, 366, 45)], 390, 844), {top: 60, bottom: 0});

// Studio phone: the tab bar (61px) and the save bar above it.
check('studio: tab bar', barEdges([header, box(0, 783, 390, 61)], 390, 844), {top: 60, bottom: 61});
check('studio: save bar on the tab bar', barEdges([header, box(0, 683, 390, 100), box(0, 783, 390, 61)], 390, 844), {top: 60, bottom: 161});

// 1366x768: side columns (the pinned side menu, the Account settings column) cover nothing beside them.
check('desktop: side menu and settings column are not bars', barEdges([box(0, 0, 1356, 64), box(0, 64, 260, 704), box(284, 80, 240, 236)], 1366, 768), {top: 64, bottom: 0});
check('admin: top bar beside the sidebar', barEdges([box(248, 0, 1118, 56), box(0, 0, 248, 768)], 1366, 768), {top: 56, bottom: 0});
check('nothing stuck', barEdges([], 1366, 768), {top: 0, bottom: 0});

// The reveal (room 8: the 4px ring and 4px of air). Partly inside the clear band: to its nearest edge.
check('clear control: no scroll', revealDelta(box(20, 300, 200, 40), [header, strip, bottomBar], 844, 8), 0);
check('under the header: scroll up until 8px below it', revealDelta(box(20, 42, 200, 40), [header], 844, 8), -26);
check('ring touching the header: scroll up', revealDelta(box(20, 64, 200, 40), [header], 844, 8), -4);
check('under the ad strip: scroll down until 8px above it', revealDelta(box(12, 665, 182, 180), [strip, bottomBar], 844, 8), 150);
check('under "Back to top" only (end of the line)', revealDelta(box(300, 620, 80, 44), [backToTop], 844, 8), 22);
check('beside "Back to top": clear', revealDelta(box(20, 620, 200, 44), [backToTop, bottomBar], 844, 8), 0);
check('taller than the band: its top shows', revealDelta(box(20, 40, 350, 800), [header, bottomBar], 844, 8), -28);
check('taller than the band, its top inside the band: no scroll', revealDelta(box(20, 300, 350, 1400), [header, bottomBar], 844, 8), 0);
check('admin: under the top bar, not under the sidebar', revealDelta(box(270, 30, 300, 36), [box(248, 0, 1118, 56), box(0, 0, 248, 768)], 768, 8), -34);
check('admin: a sidebar link beside the top bar is clear', revealDelta(box(8, 30, 230, 36), [box(248, 0, 1118, 56)], 768, 8), 0);
check('a bar far above it or beside it: clear', revealDelta(box(20, 620, 80, 44), [box(16, 0, 40, 30), box(300, 610, 40, 40)], 844, 8), 0);
check('partly below the window, no bar: its bottom to the edge', revealDelta(box(20, 1350, 200, 40), [], 1366, 8), 24);

// Wholly outside the clear band (under a bar or out of the window): centred in the band, as the browsers' own focus
// scroll does ("centre if needed").
check('wholly under the tab bar: centred above it', revealDelta(box(23, 794, 28, 28), [box(0, 783, 390, 61)], 844, 8), 421);
check('below the window (Tab): centred between the header and the bottom bar', revealDelta(box(20, 900, 300, 44), [header, bottomBar], 844, 8), 504);
check('above the window (Shift+Tab): centred', revealDelta(box(20, -300, 300, 44), [header, bottomBar], 844, 8), -696);
check('below the window, taller than the band: its top at the band\'s top', revealDelta(box(20, 1000, 300, 900), [header, bottomBar], 844, 8), 932);
check('a bar layout with no band left: no scroll', revealDelta(box(20, 400, 300, 44), [box(0, 0, 390, 430), box(0, 420, 390, 424)], 844, 8), 0);
// "Back to top" about to show (the module counts it once the move takes the page past its point): a full-width field
// at the end of the line is centred above it, not under it.
check('below the window, "Back to top" at the end of the line: centred above it', revealDelta(box(20, 1200, 350, 44), [header, backToTop], 844, 8), 867);

// The clear spans beside a box (the reveal's own): the window less the bars across the box's width, 8px from each (none
// at a window edge with no bar). The bars at the window's edges leave the one band between them.
check('spans: the header and the studio save bar across a field: the one band between them', clearSpans(box(20, 100, 350, 200), [header, box(0, 683, 390, 100), box(0, 783, 390, 61)], 844, 8), [[68, 675]]);
check('spans: "Back to top" beside the field is not across it', clearSpans(box(20, 600, 200, 44), [backToTop, bottomBar], 844, 8), [[0, 768]]);
check('spans: no bar', clearSpans(box(20, 600, 200, 44), [], 844, 8), [[0, 844]]);
check('spans: "Back to top" across a full-width field (phone, public page): a span above it and one below', clearSpans(box(20, 600, 350, 44), [header, backToTop, bottomBar], 844, 8), [[68, 642], [698, 768]]);
check('spans: the ad strip 8px above the bottom bar joins it (no span between them)', clearSpans(box(20, 600, 350, 44), [header, strip, bottomBar], 844, 8), [[68, 695]]);
check('spans: a bar layout with no band left: none', clearSpans(box(20, 400, 300, 44), [box(0, 0, 390, 430), box(0, 420, 390, 424)], 844, 8), []);
check('fits: a control that fits the band', fitsClear(box(20, 600, 350, 44), [header, bottomBar], 844, 8), true);
check('fits: a control taller than every span', fitsClear(box(20, 40, 350, 800), [header, bottomBar], 844, 8), false);

// A bar that floats between the edge bars (lane FE's verification FE-V4-01): Video details with an unsaved change at
// 683x312 (200 % zoom of a 1366x625 window; the verifier's numbers, verify4/runs/kbd312/, shots/shot312_*): the header
// 0-60, "Back to top" at 617-657 x 133-173 (its middle in the window's upper half), the save bar 193-258, the tab bar
// 258-312. Sorted by its middle as a bar from above, the button left a 4px band below it (181-185) for every control
// across its column, and the switches, the selects and a Shift+Tab select ended partly under it or under the header
// while the 57px above it were free. In spans: one above it (68-125) and one below (181-185); a control goes into a span
// it fits: nothing inside one, the nearest edge partly inside one, else centred in the largest; its top there when it is
// taller than every span.
const zoomBars = [box(0, 0, 683, 60), box(617, 133, 40, 40), box(0, 193, 683, 65), box(0, 258, 683, 54)];
check('200 %: a switch across the button\'s column: a span above it and one below', clearSpans(box(602, 153, 44, 24), zoomBars, 312, 8), [[68, 125], [181, 185]]);
check('200 %: a control left of the button: the one band from the header to the save bar', clearSpans(box(27, 150, 273, 24), zoomBars, 312, 8), [[68, 185]]);
check('200 %: the "Allow comments" switch half under the button: centred above it (84-108)', revealDelta(box(602, 153, 44, 24), zoomBars, 312, 8), 69);
check('200 %: a switch whose ring reaches 5px under the button: up to the span\'s edge (101-125)', revealDelta(box(602, 110, 44, 24), zoomBars, 312, 8), 9);
check('200 %: a full-width select 17px into the button\'s room: to the span\'s edge', revealDelta(box(27, 102, 629, 40), zoomBars, 312, 8), 17);
check('200 %: a select reached by Shift+Tab under the header: 8px below it', revealDelta(box(350, 49, 306, 40), zoomBars, 312, 8), -19);
check('200 %: a switch below the window (Tab): centred above the button', revealDelta(box(602, 330, 44, 24), zoomBars, 312, 8), 246);
check('200 %: a switch above the window (Shift+Tab): centred above the button', revealDelta(box(602, -100, 44, 24), zoomBars, 312, 8), -184);
check('200 %: a control left of the button, inside its band: no scroll', revealDelta(box(27, 150, 273, 24), zoomBars, 312, 8), 0);
check('200 %: the description (197px, taller than every span): its top at the largest span\'s top', revealDelta(box(27, 140, 629, 197), zoomBars, 312, 8), 72);
check('200 %: the description with its top inside the largest span: no scroll', revealDelta(box(27, 90, 629, 197), zoomBars, 312, 8), 0);
check('200 %: a 50px text area across the button fits the span above it (the box rule, not the caret\'s line)', fitsClear(box(27, 100, 629, 50), zoomBars, 312, 8), true);
check('200 %: the description does not fit any span (the caret\'s line rule)', fitsClear(box(27, 90, 629, 197), zoomBars, 312, 8), false);
// Home at 683x312 past the point where "Back to top" shows (lane FE's survey, device pixel ratio 2): the header 0-60,
// the phone ad strip 170.8-236, the bottom bar 244-312, the button at 617-657 x 118-158. By its middle the button was a
// bar from above, which left no band at all for its column (166 > 162.8): nothing moved and the browser's own focus
// scroll put a card's menu button under it.
const homeZoom = [box(0, 0, 673, 60), box(16, 170.8, 641, 65.2), box(0, 244, 673, 68), box(617, 118, 40, 40)];
check('200 % Home: a menu button across the button\'s column: the span above it only', clearSpans(box(610, 130, 40, 40), homeZoom, 312, 8), [[68, 110]]);
check('200 % Home: that menu button under "Back to top": centred above it', revealDelta(box(610, 130, 40, 40), homeZoom, 312, 8), 61);
check('200 % Home: a control left of the button, 7.2px into the ad strip\'s room: to the band\'s edge', revealDelta(box(16, 130, 200, 40), homeZoom, 312, 8), 8);
// Taller than every span by no more than the room (8px): centred in the largest span, its ring (4px) clear of the bars on
// both sides; nothing when it already lies within the span widened by 4px. Taller still: its top at the span's top.
check('200 % Home: a 48px link across the button (the span is 42px): centred, its ring clear of the header and the button', revealDelta(box(100, 100, 560, 48), homeZoom, 312, 8), 35);
check('200 % Home: the same link 4px below the header, its ring clear of both: no scroll', revealDelta(box(100, 64, 560, 48), homeZoom, 312, 8), 0);
// A 56px control under the button: its box fits the 58px RAW band above the button (60-118) though box + ring (64) does
// not, so it is centred in that band, clear of the button, the ring clipped the same on both sides (FE-V5-02); before, the
// box rule took its top to the roomed span's top (68), leaving its bottom 6px under the button.
check('200 % Home: a 56px control under the button: centred in the raw band above it, clear of the button', revealDelta(box(100, 120, 560, 56), homeZoom, 312, 8), 59);
check('200 % Home: that 56px control with its top in the span but its body under the button: centred clear of the button', revealDelta(box(100, 100, 560, 56), homeZoom, 312, 8), 39);
check('200 % Home: a 60px control taller than the raw band (58px): its top at the span\'s top (cannot show whole)', revealDelta(box(100, 120, 560, 60), homeZoom, 312, 8), 52);
// Where "Back to top" floats in the window's lower half (640x360 and 960x540 at 200 %, 390 and 1366) the span below it is
// smaller than a control: the results are the band's, as before.
const details640 = [box(0, 0, 630, 60), box(0, 233, 630, 65), box(0, 299.4, 630, 60.6), box(574, 181, 40, 40)];
check('200 % of 1280x720: the span below the button is gone, one band', clearSpans(box(560, 190, 44, 24), details640, 360, 8), [[68, 173]]);
check('200 % of 1280x720: a switch under the button: centred in the band above it (as before)', revealDelta(box(560, 190, 44, 24), details640, 360, 8), 82);
const details960 = [box(0, 0, 950, 64), box(0, 475, 950, 65), box(806.3, 423, 127.7, 40)];
check('200 % of 1920x1080: a switch under the button: centred in the band above it (as before)', revealDelta(box(820, 430, 44, 24), details960, 540, 8), 199);
check('200 % of 1920x1080: a 400px field beside the button in a 395px band: centred, its ring clear of the header and the save bar', revealDelta(box(20, 100, 280, 400), details960, 540, 8), 31);
const desk = [box(0, 0, 1356, 64), box(1212, 712, 128, 40)];
check('1366: the 8px below "Back to top" fits no control: a link partly there is centred in the band (as before)', revealDelta(box(1250, 762, 60, 16), desk, 768, 8), 382);
// A floating bar in the window's lower half with room below it (a mini player 400-600 in a 1366x768 window): a control
// partly in the span below it goes to that span's edge, as the browser's own focus scroll would put it.
const player = [box(0, 0, 1356, 64), box(1000, 400, 340, 200)];
check('a floating player: a control partly below it: to the edge of the span below it', revealDelta(box(1100, 590, 200, 40), player, 768, 8), -18);
check('a floating player: a control partly above it: to the edge of the span above it', revealDelta(box(1100, 380, 200, 40), player, 768, 8), 28);
check('a floating player: a control wholly under it: centred in the largest span (above it)', revealDelta(box(1100, 480, 200, 40), player, 768, 8), 268);
// Where "Back to top" fills the band between the bars across its column, no clear span fits a control there at all
// (683x312, the survey's layouts): the watch page past its sticky bar (the band 129-162.8 under the "Back to the video"
// bar, the button at 118-158) and the channel past its stuck tabs (the band 113-162.8). The control is then placed clear
// of every other bar and "Back to top" steps aside for it (mustStepAside); where a clear place exists, or the control is
// taller than every span even without the button, it does not.
const watchZoom = [box(0, 0, 673, 60), box(0, 60, 673, 61), box(16, 170.8, 641, 65.2), box(0, 244, 673, 68)];
const bttZoom = box(617, 118, 40, 40);
const switcherZoom = box(513.1, 139.7, 134.8, 26);
check('200 % watch: the footer\'s look switch across the button\'s column: no clear span with it', clearSpans(switcherZoom, [...watchZoom, bttZoom], 312, 8), []);
check('200 % watch: the look switch fits the band without the button: it steps aside', mustStepAside(switcherZoom, [...watchZoom, bttZoom], watchZoom, bttZoom, 312, 8), true);
check('200 % watch: the look switch placed clear of every other bar (2.9px into the ad strip\'s room: to the band\'s edge)', revealDelta(switcherZoom, watchZoom, 312, 8), 3);
const channelZoom = [box(0, 0, 673, 60), box(12, 60, 649, 45), box(16, 170.8, 641, 65.2), box(0, 244, 673, 68)];
const quietZoom = box(589.8, 121, 71.2, 34);
check('200 % channel: a 34px button under "Back to top" past the stuck tabs: it steps aside', mustStepAside(quietZoom, [...channelZoom, bttZoom], channelZoom, bttZoom, 312, 8), true);
check('200 % channel: that button already lies clear of every other bar: no scroll', revealDelta(quietZoom, channelZoom, 312, 8), 0);
check('200 %: a switch that fits the span above the button: the button stays', mustStepAside(box(602, 153, 44, 24), zoomBars, zoomBars.filter((b) => b.left !== 617), box(617, 133, 40, 40), 312, 8), false);
check('200 %: the description (taller than every span, with or without the button): the button stays', mustStepAside(box(27, 90, 629, 197), zoomBars, zoomBars.filter((b) => b.left !== 617), box(617, 133, 40, 40), 312, 8), false);
check('200 % watch: the button not shown yet (before its point): nothing steps aside', mustStepAside(switcherZoom, watchZoom, watchZoom, null, 312, 8), false);
check('200 % watch: a control left of the button (the button does not reach its ring): nothing steps aside', mustStepAside(box(16, 139.7, 200, 26), [...watchZoom, bttZoom], watchZoom, bttZoom, 312, 8), false);
check('phone: a control that fits the band beside "Back to top": the button stays', mustStepAside(box(20, 640, 350, 44), [header, backToTop, bottomBar], [header, bottomBar], backToTop, 844, 8), false);

// FE-V5-02: on the watch page past its sticky bar at 200 % zoom the band left between the "Back to the video" bar (121)
// and the ad strip (170.8) is 49.8px. A 44px control's box fits it, but box + ring (60px) does not: the step-aside and the
// placement go by the box (WCAG 2.4.12 is about the component, not the ring), so the button steps aside and the control is
// centred in the band with the ring clipped the same on both sides. fitsBox tells box-fit from fitsClear's box + ring.
check('fitsBox: a 44px control fits the 49.8px watch band (ring clipped)', fitsBox(box(513, 132, 134, 44), watchZoom, 312), true);
check('fitsClear: the same control does not fit with its ring clear (needs box + ring = 60px)', fitsClear(box(513, 132, 134, 44), watchZoom, 312, 8), false);
check('fitsBox: a control taller than every raw band does not fit either', fitsBox(box(27, 90, 629, 197), zoomBars, 312), false);
const langZoom = box(513, 132, 134, 44);
check('200 % watch: the footer language select (44px box in the 49.8px band): the button steps aside (the box fits without it)', mustStepAside(langZoom, [...watchZoom, bttZoom], watchZoom, bttZoom, 312, 8), true);
check('200 % watch: that select centred in the band, the ring clipped evenly, no part of the box under a bar', revealDelta(langZoom, watchZoom, 312, 8), 8);
// a control left of the button (the button is not across its column, so it never covers it): it, too, is centred in the
// band instead of ending under the ad strip. The old box rule top-aligned it (bottom 2px under the strip, move 0).
check('200 % watch: a 43px title link left of the button, 2px under the ad strip: centred in the band, box clear', revealDelta(box(27, 130, 300, 43), watchZoom, 312, 8), 6);
check('200 % watch: a 50px control taller than the 49.8px band even without the button: cannot be centred clear (top at the span)', fitsBox(box(513, 132, 134, 50), watchZoom, 312), false);

// Two spans alike: the one nearer the control.
const middleBar = [box(0, 190, 400, 20)];
check('two spans alike: a control above the window is centred in the upper one', revealDelta(box(20, -50, 100, 24), middleBar, 400, 8), -129);
check('two spans alike: a control below the window is centred in the lower one', revealDelta(box(20, 450, 100, 24), middleBar, 400, 8), 153);

// A text area taller than its clear band (lane FE's verification FE-V3-01: 200 % zoom or a short window; the admin's
// Spam word list 338px high in a 1366x400 window under the admin's top bar, 0-56, and its save bar, 326-388): the later
// looks keep the line being typed (its caret's line box; left / right around the caret) clear of the bars, where the
// browsers' own caret reveal puts it with the root's scroll-padding (lane FE's probe in three engines: the caret ends at
// the padded band's edge, 318 here), and never move the page for the field's top.
const adminTop = box(0, 0, 1366, 56);
const adminSave = box(0, 326, 1366, 62);
const spamField = box(240, 12, 900, 338);
check('tall field: its span between the top bar and the save bar (and the 4px under the save bar, which sticks 12px above the window\'s edge)', clearSpans(spamField, [adminTop, adminSave], 400, 8), [[64, 318], [396, 400]]);
check('tall field: taller than every span (338 > 254)', fitsClear(spamField, [adminTop, adminSave], 400, 8), false);
check('tall field: the box rule would take its top to the band\'s top, 52px back up (the jump at every key)', revealDelta(spamField, [adminTop, adminSave], 400, 8), -52);
check('tall field: the line the browser revealed at the padded edge stays (no jump)', lineDelta(box(299, 298, 2, 20), [adminTop, adminSave], 400, 8), 0);
check('tall field: a line box a few px past the padded edge, clear of the bar (WebKit paints over the whole line)', lineDelta(box(299, 301, 2, 20), [adminTop, adminSave], 400, 8), 0);
check('tall field: the first key brought the save bar over the line: to the padded edge above it', lineDelta(box(299, 318, 2, 20), [adminTop, adminSave], 400, 8), 20);
check('tall field: the line wholly under the save bar: to the nearest edge, not centred', lineDelta(box(299, 340, 2, 20), [adminTop, adminSave], 400, 8), 42);
check('tall field: the line under the top bar (typing up): 8px below it', lineDelta(box(299, 40, 2, 20), [adminTop, adminSave], 400, 8), -24);
check('tall field: the line below the window: to the band\'s edge', lineDelta(box(299, 420, 2, 20), [adminTop, adminSave], 400, 8), 122);
// no bar at the bottom (admin > Pages content at 683x312): the browsers reveal the caret to the window's edge
const pagesTop = box(0, 0, 683, 56);
check('no bar below: the line box 4px past the window\'s edge (where the browser left it): no scroll', lineDelta(box(99, 296, 2, 20), [pagesTop], 312, 8), 0);
check('no bar below: more than half of the line below the window: to the edge', lineDelta(box(99, 305, 2, 20), [pagesTop], 312, 8), 13);
check('no bar above: more than half of the line above the window: to the edge', lineDelta(box(99, -12, 2, 20), [], 312, 8), -12);
check('no bar above: less than half above it (the browser\'s own edge): no scroll', lineDelta(box(99, -8, 2, 20), [], 312, 8), 0);
// "Back to top" (Video details at 200 % zoom, 683x380: the header 0-60, the save bar 253-318, the tab bar 319-380, the
// button just above the save bar at the end of the line) counts only over the caret
const detailsBars = [box(0, 0, 683, 60), box(0, 253, 683, 65), box(0, 319, 683, 61), box(620, 190, 46, 46)];
check('"Back to top" over the caret at the end of the line: above it', lineDelta(box(639, 200, 2, 22), detailsBars, 380, 8), 40);
check('"Back to top" not over the caret (start of the line): clear', lineDelta(box(99, 200, 2, 22), detailsBars, 380, 8), 0);
check('a band lower than the line (bars over most of the window): no scroll', lineDelta(box(99, 100, 2, 22), [box(0, 0, 683, 190), box(0, 200, 683, 180)], 380, 8), 0);
// An empty line of a right-to-left field (Video details in Arabic at 683x380: "Back to top" at the inline end, the LEFT,
// just above the save bar): WebKit paints its caret at the left end after a typed Enter, the copy (and Chromium, Firefox)
// at the right; the whole line counts (lane FE's probe runs/p6/caret_rtl_probe_*.json).
const ar = 'سطر';
check('empty line: after a typed Enter at the end', emptyLineAt(`${ar}\n`, 4), true);
check('empty line: between two lines', emptyLineAt(`${ar}\n\n${ar}`, 4), true);
check('empty line: the empty field', emptyLineAt('', 0), true);
check('empty line: two Enters, the caret on the last', emptyLineAt(`${ar}\n\n`, 5), true);
check('not empty: the start of a line with text', emptyLineAt(`${ar}\n${ar}`, 4), false);
check('not empty: the end of a line with text', emptyLineAt(`${ar}\n${ar}`, 7), false);
check('not empty: before the line break, after a letter', emptyLineAt('a\n', 1), false);
const detailsBarsAr = [box(0, 0, 683, 60), box(0, 253, 683, 65), box(0, 319, 683, 61), box(17, 190, 46, 46)];
check('RTL empty line, the whole line: "Back to top" at its left end counts, above it', lineDelta(box(28, 200, 627, 22), detailsBarsAr, 380, 8), 40);
check('RTL line with text, the caret at its right: "Back to top" at the left end is not over it', lineDelta(box(653, 200, 2, 22), detailsBarsAr, 380, 8), 0);
check('RTL empty line, the whole line, clear of every bar: no scroll', lineDelta(box(28, 150, 627, 22), detailsBarsAr, 380, 8), 0);
// Video details in Arabic at 683x312 (200 % zoom of 1366x625): "Back to top" floats at 133-173, in the window's upper
// half, between the header (0-60) and the save bar (193-258; the tab bar 258-312). Taken for a top bar (by its middle),
// it left a 4px band below it, lower than the line: no move, the caret under it (lane FE's own check on build p6b,
// runs/p6/p6b/tall/). A floating bar has a place above it and one below: the line goes to the nearest one it fits in.
const shortAr = [box(0, 0, 683, 60), box(0, 193, 683, 65), box(0, 258, 683, 54), box(17, 133, 40, 40)];
check('short window: an empty right-to-left line under the floating "Back to top": above it', lineDelta(box(28, 155, 627, 23), shortAr, 312, 8), 53);
check('short window: the same line with its caret at the right (text typed): clear', lineDelta(box(653, 155, 2, 23), shortAr, 312, 8), 0);
check('short window: a line above the floating button, clear of it: no scroll', lineDelta(box(28, 100, 627, 22), shortAr, 312, 8), 0);
check('a floating bar over the caret: to its nearer side (below it here)', lineDelta(box(610, 135, 2, 20), [box(0, 0, 683, 60), box(600, 100, 40, 40)], 600, 8), -13);
check('a floating bar over the caret: to its nearer side (above it here)', lineDelta(box(610, 105, 2, 20), [box(0, 0, 683, 60), box(600, 100, 40, 40)], 600, 8), 33);

// Where "Back to top" rests once shown: drawn hidden as translate(0, 14px) scale(0.92) about its centre.
const drawn = box(335.84, 666.84, 42.32, 42.32);
check('"Back to top" hidden (scaled 0.92, 14px lower) rests at its own box', round(restingBox(drawn, 0.92, 0.92, 0, 14, 23, 23)), [334, 651, 380, 697]);
check('no transform: the box as drawn', round(restingBox(box(334, 651, 46, 46), 1, 1, 0, 0, 23, 23)), [334, 651, 380, 697]);

// The later looks end on the visitor's own act after the Tab (lane FE's verification FE-V1-01: a wheel or ArrowDown
// scroll 150-250 ms after a Tab was pulled back 184-395px by the second look), but not on a key the focused control takes
// for itself (FE-V2-02: Space on a switch or a check box, an arrow in a select): that key changes the control, the page
// stays, and the looks still clear it of the bar the change brings. endsLooks(key, Ctrl / Cmd held, Alt / Option held,
// the focused control's kind); every "scrolls" and "takes" below was measured in Chromium, Firefox and WebKit
// (lane FE's key probe).
// A link or a focusable block takes no key: the arrows, the page keys and Space scroll the page, Enter follows the link.
check('ArrowDown on a link scrolls the page: the looks end', endsLooks('ArrowDown', false, false, 'other'), true);
check('ArrowUp scrolls the page: the looks end', endsLooks('ArrowUp', false, false, 'other'), true);
check('Page Down: the looks end', endsLooks('PageDown', false, false, 'other'), true);
check('End: the looks end', endsLooks('End', false, false, 'other'), true);
check('Space on a link scrolls the page (Firefox, WebKit): the looks end', endsLooks(' ', false, false, 'other'), true);
check('Enter follows a link (an in-page link jumps the page): the looks end', endsLooks('Enter', false, false, 'other'), true);
check('a letter outside a field (a page shortcut): the looks end', endsLooks('k', false, false, 'other'), true);
check('a key event with no key name on a link (autofill): the looks end', endsLooks('', false, false, 'other'), true);
// What never ends them: Tab and the modifiers on their own.
check('Tab: its own focus move brings its own looks', endsLooks('Tab', false, false, 'other'), false);
check('Ctrl + Tab (the browser\'s): nothing to end', endsLooks('Tab', true, false, 'other'), false);
check('Shift on its own (it begins Shift+Tab): the looks go on', endsLooks('Shift', false, false, 'other'), false);
check('Option / Alt on its own (Option+Tab): the looks go on', endsLooks('Alt', true, true, 'other'), false);
check('Control on its own: the looks go on', endsLooks('Control', true, false, 'other'), false);
check('Meta on its own: the looks go on', endsLooks('Meta', true, false, 'other'), false);
// A check box or a switch: Space ticks it (no scroll in any engine), Enter does nothing to the page (Firefox submits a
// form); the arrows, the page keys and Alt + an arrow scroll the page.
check('Space on a switch ticks it: the looks go on (the save bar it brings is cleared)', endsLooks(' ', false, false, 'tick'), false);
check('Shift + Space on a check box ticks it too (the key name is the same, Shift is not passed): the looks go on', endsLooks(' ', false, false, 'tick'), false);
check('Enter on a check box: the page stays, the looks go on', endsLooks('Enter', false, false, 'tick'), false);
check('ArrowDown on a check box scrolls the page: the looks end', endsLooks('ArrowDown', false, false, 'tick'), true);
check('Page Down on a check box scrolls the page: the looks end', endsLooks('PageDown', false, false, 'tick'), true);
check('Home on a switch scrolls the page: the looks end', endsLooks('Home', false, false, 'tick'), true);
check('Alt + ArrowDown on a check box scrolls the page (Chromium, WebKit): the looks end', endsLooks('ArrowDown', false, true, 'tick'), true);
check('a letter on a check box (a page shortcut): the looks end', endsLooks('k', false, false, 'tick'), true);
check('Ctrl / Cmd + F on a check box (find in the page): the looks end', endsLooks('f', true, false, 'tick'), true);
// A radio: Space checks it; an arrow moves to the next radio (that focus brings its own looks) or, at the end of the
// group, scrolls the page (WebKit).
check('Space on a radio checks it: the looks go on', endsLooks(' ', false, false, 'radio'), false);
check('Enter on a radio: the page stays, the looks go on', endsLooks('Enter', false, false, 'radio'), false);
check('ArrowUp on the first radio scrolls the page in WebKit: the looks end', endsLooks('ArrowUp', false, false, 'radio'), true);
check('Page Down on a radio scrolls the page: the looks end', endsLooks('PageDown', false, false, 'radio'), true);
// A button or a summary: Space and Enter press it; the arrows and the page keys scroll the page.
check('Space on a button presses it: the looks go on', endsLooks(' ', false, false, 'press'), false);
check('Enter on a button presses it: the looks go on', endsLooks('Enter', false, false, 'press'), false);
check('ArrowDown on a button scrolls the page: the looks end', endsLooks('ArrowDown', false, false, 'press'), true);
check('End on a summary scrolls the page: the looks end', endsLooks('End', false, false, 'press'), true);
// A select or a list box takes every plain key (no scroll in any engine): the arrows, the page keys, Home / End, Space,
// Enter, a letter (it picks by the first letters), Alt + ArrowDown (it opens).
check('ArrowDown in a select picks the next choice: the looks go on', endsLooks('ArrowDown', false, false, 'choice'), false);
check('ArrowUp in a select: the looks go on', endsLooks('ArrowUp', false, false, 'choice'), false);
check('Page Down in a select picks: the looks go on', endsLooks('PageDown', false, false, 'choice'), false);
check('End in a list box picks the last choice: the looks go on', endsLooks('End', false, false, 'choice'), false);
check('Space on a select opens it: the looks go on', endsLooks(' ', false, false, 'choice'), false);
check('a letter in a select picks by its first letter: the looks go on', endsLooks('f', false, false, 'choice'), false);
check('Alt + ArrowDown opens a select: the looks go on', endsLooks('ArrowDown', false, true, 'choice'), false);
check('Ctrl / Cmd + F in a select (find in the page): the looks end', endsLooks('f', true, false, 'choice'), true);
check('F3 in a select (find the next match): the looks end', endsLooks('F3', false, false, 'choice'), true);
// A range: the arrows, the page keys, Home and End move it; Space scrolls the page (Chromium, WebKit).
check('ArrowRight on a range moves it: the looks go on', endsLooks('ArrowRight', false, false, 'slider'), false);
check('Page Down on a range moves it: the looks go on', endsLooks('PageDown', false, false, 'slider'), false);
check('Home on a range moves it: the looks go on', endsLooks('Home', false, false, 'slider'), false);
check('Space on a range scrolls the page: the looks end', endsLooks(' ', false, false, 'slider'), true);
// A text field: what it types and its caret keys keep the looks (the save bar the first typed key brings is cleared);
// Page Up / Page Down and Alt + an arrow, Home or End scroll the page in Chromium and WebKit; a shortcut and the
// function keys (F3: find the next match) end them.
check('a letter typed into a text field: the looks go on (a save bar that comes is still cleared)', endsLooks('a', false, false, 'text'), false);
check('a space typed into a text field: the looks go on', endsLooks(' ', false, false, 'text'), false);
check('Enter in a text area (a new line): the looks go on', endsLooks('Enter', false, false, 'text'), false);
check('ArrowDown in a text field moves its caret: the looks go on', endsLooks('ArrowDown', false, false, 'text'), false);
check('End in a text field moves its caret: the looks go on', endsLooks('End', false, false, 'text'), false);
check('Backspace in a text field: the looks go on', endsLooks('Backspace', false, false, 'text'), false);
check('Option + a letter types a character (macOS): the looks go on', endsLooks('å', false, true, 'text'), false);
check('a key event with no key name in a text field (autofill): the looks go on', endsLooks('', false, false, 'text'), false);
check('Page Down in a text field scrolls the page (Chromium, WebKit): the looks end', endsLooks('PageDown', false, false, 'text'), true);
check('Page Up in a text area scrolls the page: the looks end', endsLooks('PageUp', false, false, 'text'), true);
check('Alt + ArrowDown in a text field scrolls the page (Chromium, WebKit): the looks end', endsLooks('ArrowDown', false, true, 'text'), true);
check('Ctrl / Cmd + F in a text field (find in the page): the looks end', endsLooks('f', true, false, 'text'), true);
check('F3 in a text field (find the next match): the looks end', endsLooks('F3', false, false, 'text'), true);

// pageScrolled(where the first look left the page {scroll position, the control's place in the document}, the scroll
// position now, the control's window top now): the second look moves nothing once the page has been scrolled.
check('a wheel scroll of 76px (My videos): scrolled', pageScrolled({y: 108, at: 94 + 108}, 184, 18), true);
check('ArrowDown x2 (Home, +80px): scrolled', pageScrolled({y: 1171, at: 96 + 1171}, 1251, 16), true);
check('a scroll back up: scrolled', pageScrolled({y: 1171, at: 96 + 1171}, 1000, 267), true);
check('scroll anchoring (120px of content above it grew, the view kept still): no scroll', pageScrolled({y: 1171, at: 96 + 1171}, 1291, 96), false);
check('a bar in view pushed the control down (no scroll): no scroll', pageScrolled({y: 108, at: 94 + 108}, 108, 140), false);
check('nothing moved: no scroll', pageScrolled({y: 108, at: 94 + 108}, 108, 94), false);
check('a sub-pixel change of the scroll position: no scroll', pageScrolled({y: 100.5, at: 300.25}, 101.25, 199.5), false);

console.log(`focus clear: ${passed} passed, ${failures} failed`);
if (failures > 0) {
    process.exit(1);
}
