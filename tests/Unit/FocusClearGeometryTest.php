<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AVDBCMS 1.2.3 (R2R-02, WCAG 2.2 Focus Not Obscured): a control the keyboard moves to is never left under a bar that
 * stays on screen (the sticky header, the watch page's bar, the stuck member strips, the phone bars at the bottom, the
 * member save and bulk bars, the floating controls; in the admin its top bar, table heads and save bars; the day headings
 * of the bell's list). The script (resources/js/layout/focusClear.ts, installed by resources/js/layout/core.ts and
 * resources/admin/modules/focus.ts) works for the keyboard only, brings the control clear when the focus arrives (no
 * frame shows it under a bar) and again when the keyboard changes it (the save or bulk bar the change brings), places it
 * in the window's clear spans (a bar that floats between the edge bars, "Back to top" at 200 % zoom, has a place above
 * it and one below), keeps the line typed in a text area taller than every clear span clear of the bars (never the
 * field's top), never undoes a scroll the visitor makes after the Tab, and keeps the geometry and those rules in plain
 * functions that tests/js/focus_clear.test.ts checks in Node.
 */
class FocusClearGeometryTest extends TestCase
{
    public function test_the_geometry_passes_its_cases_in_node(): void
    {
        $node = $this->nodeBinary();

        if ($node === null) {
            $this->markTestSkipped('Node.js 22.18 or newer is needed to run tests/js/focus_clear.test.ts (set NODE_BINARY to its path if it is not found).');
        }

        $root = dirname(__DIR__, 2);
        $output = [];
        exec(escapeshellarg($node).' '.escapeshellarg($root.'/tests/js/focus_clear.test.ts').' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertMatchesRegularExpression('/focus clear: \d+ passed, 0 failed/', implode("\n", $output));
    }

    public function test_it_runs_for_the_keyboard_only_on_the_site_and_in_the_admin(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/resources/js/layout/focusClear.ts');
        $core = (string) file_get_contents($root.'/resources/js/layout/core.ts');
        $admin = (string) file_get_contents($root.'/resources/admin/modules/focus.ts');
        $adminEntry = (string) file_get_contents($root.'/resources/admin/admin.ts');

        // keyboard mode starts with Tab (Option+Tab too: Safari's Tab through every item; Ctrl / Cmd + Tab are the
        // browser's) and ends with any pointer; the padding goes with it
        $this->assertStringContainsString("if (event.key !== 'Tab' || event.ctrlKey || event.metaKey) {", $module);
        // no Alt / Option test keeps Option+Tab out of keyboard mode: the one use of event.altKey is the key rule's
        // argument (Alt + an arrow scrolls the page from a text field), inside the branch of the other keys
        $this->assertDoesNotMatchRegularExpression("/event\.key !== 'Tab'[^{\n]*altKey/", $module);
        $this->assertSame(1, substr_count($module, 'event.altKey'));
        $this->assertStringContainsString('event.ctrlKey || event.metaKey, event.altKey, controlKind(event.target)', $module);
        $this->assertStringContainsString("addEventListener('pointerdown'", $module);
        $this->assertStringContainsString("removeProperty('scroll-padding-top')", $module);
        // an inline style of the root (a property that is not inherited), never a custom property there
        $this->assertStringContainsString("root.style.setProperty('scroll-padding-top'", $module);
        $this->assertStringNotContainsString("setProperty('--", $module);
        // no scroll listener: the work happens on a Tab key and on the focus change it causes
        $this->assertDoesNotMatchRegularExpression("/addEventListener\(\s*'scroll'/", $module);
        $this->assertStringNotContainsString('onscroll', $module);
        // the control is brought clear when the focus arrives, before the browser's own focus scroll, as a jump (the
        // page's scroll-behavior is smooth); later looks catch a bar that comes afterwards
        $this->assertMatchesRegularExpression('/setPadding\(\);\s*(\/\/[^\n]*\n\s*)*const placed = reveal\(element, tabbed\);\s*(\/\/[^\n]*\n\s*)*stepAsideFor\(element\);\s*check\(element, false, placed\);/', $module);
        $this->assertStringContainsString("root.style.setProperty('scroll-behavior', 'auto');", $module);
        $this->assertStringContainsString('window.scrollBy(0, delta);', $module);
        // a jump that leaves the control where it was (a sticky column that stays stuck) is taken back: the page never
        // moves for nothing
        $this->assertMatchesRegularExpression('/if \(after && Math\.abs\(after\.top - before\.top\) < 0\.5\) \{\s*jump\(y - window\.scrollY\);\s*return false;/', $module);
        $this->assertStringContainsString('const reveal = (element: HTMLElement, tabbed: boolean): boolean => {', $module);
        $this->assertMatchesRegularExpression('/if \(delta === 0\) \{\s*return true;\s*\}\s*if \(!clearBy\(element, delta\)\) \{\s*return false;\s*\}/', $module);
        // left to the browser (no box, a script's focus out of the window, a scrolling box that hides it): not placed
        $this->assertMatchesRegularExpression('/hiddenByScroller\(element, shown\)\) \{\s*return false;\s*\}/', $module);
        // after a script's focus() (no Tab key), a control out of the window is left to that script
        $this->assertStringContainsString('(!tabbed && (shown.bottom <= 0 || shown.top >= height))', $module);
        // a sticky bar covers only where it sticks, and its controls are "in a bar" only then (a table's head row whose
        // table has scrolled away is content like any other)
        $this->assertStringContainsString('const stuck = (box: DOMRect, style: CSSStyleDeclaration): boolean => {', $module);
        $this->assertStringContainsString("if (style.position === 'fixed' || (style.position === 'sticky' && stuck(bar.getBoundingClientRect(), style))) {", $module);
        // a fixed control ("Skip to content" while it has the focus) or one in a fixed layer is never scrolled for
        $this->assertStringContainsString('for (let node: Element | null = element; node', $module);
        // a key press that moves into a bar (the header's controls from a page scrolled down) sets no padding: Chromium
        // works out its focus scroll with the padding of the key press and would scroll the page under the header
        $this->assertStringContainsString('nextStop(from, event.shiftKey)', $module);
        $this->assertMatchesRegularExpression('/if \(next && insideFixed\(next, bars\)\) \{\s*clearPadding\(\);\s*\} else \{\s*setPadding\(\);/', $module);
        $this->assertStringContainsString('document.createTreeWalker(document.body, NodeFilter.SHOW_ELEMENT)', $module);

        foreach (['[data-avd-header]', '#avdStickyWatchBar', '.mk-channel__tabs', '.mk-settings__nav', '#avdMobileBottomNav', '.ad-slot-mobile', '.mk-tabbar', '.mk-savebar', '.mk-bulkbar', '.back-to-top', '.avd-try'] as $bar) {
            $this->assertStringContainsString("'{$bar}'", $module, $bar.' is one of the site\'s bars');
        }

        // "Back to top" counts as soon as a keyboard move takes the page past the point where it shows (one constant)
        $this->assertStringContainsString('const BACK_TO_TOP_PAST = 420;', $core);
        $this->assertStringContainsString("installFocusClear({bars: SITE_BARS, showsPast: [['#back-to-top', BACK_TO_TOP_PAST]]})", $core);
        $this->assertStringContainsString('height:${BACK_TO_TOP_PAST}px;', $core);
        $this->assertStringContainsString('setBackToTop(window.scrollY > BACK_TO_TOP_PAST);', $core);

        $this->assertStringContainsString(".ad-top, .ad-table th, .ad-toasts, \${BOTTOM_BARS}", $admin);
        $this->assertStringContainsString('initFocusClear', $adminEntry);
    }

    /**
     * The later looks (a toast, a strip that sticks, a bar that comes after the Tab) never undo the visitor's own act: a
     * wheel or a key scroll made within a quarter second of a Tab was pulled back by hundreds of pixels (lane FE's
     * verification FE-V1-01). The wheel, a pointer and a key that moves the page end them (a modifier on its own and a
     * key the focused control takes for itself aside - typing in a text field, Space on a check box, a switch or a
     * button, an arrow in a select: tests/js/focus_clear.test.ts has the key rules, measured in three engines), and the
     * second look moves nothing once the page has been scrolled since the first, whatever scrolled it.
     */
    public function test_the_later_looks_never_undo_the_visitors_own_scroll(): void
    {
        $module = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/layout/focusClear.ts');

        // the wheel ends them: passive (it never holds a scroll back), and only from the first Tab on (a page used by
        // mouse or touch only has no such listener)
        $this->assertMatchesRegularExpression("/document\.addEventListener\('wheel', \(\) => \{\s*pending\+\+;\s*stepAsideFor\(null\);\s*\}, \{capture: true, passive: true\}\);/", $module);
        $this->assertMatchesRegularExpression('/keyboard = true;\s*tabbing = true;\s*listenWheel\(\);/', $module);
        $this->assertSame(1, substr_count($module, "addEventListener('wheel'"));
        // any other key ends them by the rule endsLooks() keeps: Ctrl / Cmd held = a shortcut, Alt / Option held, and
        // what the focused control does with the key by itself (its kind); a key event with no key name (a browser's
        // autofill) is read as an empty name, never as undefined
        // "Back to top" comes back not on the key itself but once it actually scrolls the page (bringBackOnScroll): a key
        // that moves nothing (Escape, a letter) must not re-cover the control it stepped aside for (lane FE's FE-V5-01)
        $this->assertMatchesRegularExpression('/if \(event\.key !== \'Tab\' \|\| event\.ctrlKey \|\| event\.metaKey\) \{\s*(\/\/[^\n]*\n\s*)*if \(endsLooks\(String\(event\.key \|\| \'\'\), event\.ctrlKey \|\| event\.metaKey, event\.altKey, controlKind\(event\.target\)\)\) \{\s*pending\+\+;\s*bringBackOnScroll\(\);\s*\}\s*return;\s*\}/', $module);
        // bringBackOnScroll watches the frames after such a key, only while a control stands aside, and brings the button
        // back once the page moves - not a scroll listener (no work on every scroll of the page's life)
        $this->assertStringContainsString('const bringBackOnScroll = (): void => {', $module);
        $this->assertMatchesRegularExpression('/if \(aside\.size === 0\) \{\s*return;\s*\}\s*const ticket = \+\+watching;/', $module);
        $this->assertMatchesRegularExpression('/if \(still < 4 && \+\+frames < 60\) \{\s*window\.requestAnimationFrame\(look\);/', $module);
        $this->assertStringContainsString('export const endsLooks = (key: string, shortcut: boolean, alt: boolean, kind: ControlKind): boolean => {', $module);
        $this->assertStringContainsString("export type ControlKind = 'text' | 'choice' | 'slider' | 'tick' | 'radio' | 'press' | 'other';", $module);
        // the kinds come from the element itself: a select or a list box, a check box or a switch, a radio, a range, a
        // button, a summary or a button-like input, a text-like field; anything else (a link, a block) takes no key
        $this->assertStringContainsString('const controlKind = (target: EventTarget | null): ControlKind => {', $module);
        $this->assertMatchesRegularExpression("/if \(target instanceof HTMLSelectElement\) \{\s*return 'choice';\s*\}/", $module);
        $this->assertMatchesRegularExpression("/if \(type === 'checkbox'\) \{\s*return 'tick';\s*\}/", $module);
        $this->assertMatchesRegularExpression("/if \(type === 'radio'\) \{\s*return 'radio';\s*\}/", $module);
        $this->assertMatchesRegularExpression("/if \(type === 'range'\) \{\s*return 'slider';\s*\}/", $module);
        $this->assertStringContainsString("return target instanceof HTMLButtonElement || target.localName === 'summary' ? 'press' : 'other';", $module);
        $this->assertStringNotContainsString('typesText', $module);
        // a pointer still ends keyboard mode and the looks
        $this->assertMatchesRegularExpression("/addEventListener\('pointerdown', \(\) => \{\s*keyboard = false;\s*tabbing = false;\s*pending\+\+;/", $module);
        // the second look: nothing once the page has been scrolled since the first (the scroll bar, find in the page):
        // the looks end - after a key typed into the field (the browser's own caret reveal may be that scroll) they go
        // on, but never move the page back
        $this->assertStringContainsString('export const pageScrolled = (before: {y: number; at: number}, y: number, top: number): boolean =>', $module);
        $this->assertMatchesRegularExpression('/if \(settled !== null && pageScrolled\(settled, window\.scrollY, box\.top\)\) \{\s*if \(!own\.follows\) \{\s*end\(\);\s*return;\s*\}\s*along = Math\.sign\(window\.scrollY - settled\.y\);\s*\}/', $module);
        $this->assertStringContainsString('if (delta !== 0 && (along === 0 || Math.sign(delta) === along)) {', $module);
        $this->assertMatchesRegularExpression('/if \(\+\+rounds < 2 && along === 0\) \{\s*settled = \{y: window\.scrollY, at: element\.getBoundingClientRect\(\)\.top \+ window\.scrollY\};/', $module);
        // the first look too (a scroll the visitor starts within a few frames of the Tab, before a slow device's first
        // look): it compares with the page one frame after the focus move or the change, when `guard` (this module placed
        // the control, or a change started the looks); the reference is taken once, in the first frame of the first round
        $this->assertStringContainsString('const check = (element: HTMLElement, refresh = false, guard = false, follows = false): void => {', $module);
        $this->assertMatchesRegularExpression('/const box = element\.getBoundingClientRect\(\);\s*if \(guard && rounds === 0 && frames === 0\) \{\s*settled = \{y: window\.scrollY, at: box\.top \+ window\.scrollY\};\s*\}/', $module);
        $this->assertSame(1, substr_count($module, 'if (guard && rounds === 0 && frames === 0) {'));
        // still no scroll listener: no work while the page scrolls
        $this->assertDoesNotMatchRegularExpression("/addEventListener\(\s*'scroll'/", $module);
        // the module stays free of imports (the admin takes it alone)
        $this->assertDoesNotMatchRegularExpression('/^import /m', $module);
    }

    /**
     * A change the keyboard makes on the focused control brings the looks again, however long after the Tab: the save
     * bar a switch or a typed character brings (Video details, Account settings, the admin's record pages) and the
     * phone bulk bar a ticked row brings (My videos) never end over the control (lane FE's verification FE-V2-01: a
     * switch and a row's check box ended wholly under those bars, a description 53-87px under the save bar).
     */
    public function test_a_change_made_with_the_keyboard_brings_the_looks_again(): void
    {
        $module = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/layout/focusClear.ts');

        // input and change, in the capture phase (before the page's own handlers show the bar; the looks come in the
        // next frames), listened to once, from the first Tab on (a page used by mouse or touch only has no such
        // listener)
        $this->assertSame(1, substr_count($module, "document.addEventListener('input', changed, true);"));
        $this->assertSame(1, substr_count($module, "document.addEventListener('change', changed, true);"));
        $this->assertSame(1, substr_count($module, "addEventListener('input'"));
        $this->assertSame(1, substr_count($module, "addEventListener('change'"));
        $this->assertMatchesRegularExpression('/keyboard = true;\s*tabbing = true;\s*listenWheel\(\);\s*listenChanges\(\);/', $module);
        $this->assertMatchesRegularExpression('/const listenChanges = \(\): void => \{\s*if \(!changes\) \{\s*changes = true;/', $module);
        // only in keyboard mode (a pointer ends it first: a click or a tap that changes a control is left alone), only
        // for the focused control itself
        $this->assertMatchesRegularExpression('/if \(!keyboard \|\| !\(element instanceof HTMLElement\) \|\| element !== document\.activeElement\) \{\s*return;\s*\}/', $module);
        // typing joins the looks already going on for its field (their next look measures the bars again): a key typed
        // costs no work of this module in its own frame (the fifth pass's typing measure); a tick or a choice starts
        // them over (its bar is cleared in a few frames)
        $this->assertStringContainsString('let chain: {element: HTMLElement; ticket: number; refresh: boolean; follows: boolean} | null = null;', $module);
        $this->assertMatchesRegularExpression("/const typed = controlKind\\(element\\) === 'text';\\s*if \\(typed && chain !== null && chain\\.element === element && chain\\.ticket === pending\\) \\{\\s*chain\\.refresh = true;\\s*chain\\.follows = true;\\s*return;\\s*\\}\\s*check\\(element, true, true, typed\\);/", $module);
        $this->assertMatchesRegularExpression('/const own = \{element, ticket, refresh, follows\};\s*chain = own;/', $module);
        $this->assertMatchesRegularExpression('/const end = \(\): void => \{\s*if \(chain === own\) \{\s*chain = null;\s*\}\s*\};/', $module);
        // no style read in the change's own event: the fixed-layer check (a dialog's field is on screen as it is) is
        // in the look, in the frame
        $this->assertDoesNotMatchRegularExpression('/const changed = \(event: Event\): void => \{(?:(?!\n    \};)[\s\S])*insideFixed/', $module);
        // those looks measure the bars again for the root's padding (the bar the change brought counts for the browser's
        // own scrolls too), after the second look's guard (nothing once the visitor has scrolled the page)
        $this->assertStringContainsString('const check = (element: HTMLElement, refresh = false, guard = false, follows = false): void => {', $module);
        $this->assertMatchesRegularExpression('/if \(settled !== null && pageScrolled\(settled, window\.scrollY, box\.top\)\) \{\s*if \(!own\.follows\) \{\s*end\(\);\s*return;\s*\}\s*along = Math\.sign\(window\.scrollY - settled\.y\);\s*\}\s*if \(own\.refresh\) \{\s*if \(insideFixed\(element, bars\)\) \{\s*end\(\);\s*return;\s*\}\s*setPadding\(\);\s*\}/', $module);
        // a focus move keeps its own looks (no re-measure there: the Tab key measured the bars), guarded where this module
        // placed the control
        $this->assertMatchesRegularExpression('/const placed = reveal\(element, tabbed\);\s*(\/\/[^\n]*\n\s*)*stepAsideFor\(element\);\s*check\(element, false, placed\);/', $module);
        // a pointer still ends keyboard mode: the change listeners then do nothing
        $this->assertMatchesRegularExpression("/addEventListener\('pointerdown', \(\) => \{\s*keyboard = false;/", $module);
    }

    /**
     * A text area taller than its clear band (200 % zoom, a short window: the admin's Spam word lists and Pages content,
     * Video details' description) cannot show whole: the later looks keep the line being typed clear of the bars, where
     * the browsers' own caret reveal puts it, instead of taking the field's top to the band's top - that rule undid the
     * caret reveal at every key and left the caret under the save bar (lane FE's verification FE-V3-01). The browser's
     * own caret reveal after a typed key (Chromium's smooth one starts after the looks' reference frame) is not taken for
     * someone's scroll: the looks go on, but never move the page back.
     */
    public function test_typing_in_a_field_taller_than_its_band_keeps_its_caret_line_clear(): void
    {
        $module = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/layout/focusClear.ts');

        // the geometry is plain functions (tests/js/focus_clear.test.ts): the caret's line, not the field's box
        $this->assertStringContainsString('export const lineDelta = (line: Box, bars: Box[], height: number, room: number): number => {', $module);
        // the line goes to the nearest place in the window where it fits clear of the bars over the caret - a bar that
        // floats ("Back to top" in a short window's upper half) has a place above it and one below (the window's clear
        // spans, the box rule's own)
        $this->assertStringContainsString('const over = bars.filter((bar) => bar.right > line.left && bar.left < line.right && bar.bottom > bar.top);', $module);
        $this->assertStringContainsString('for (const [from, to] of clearSpans(line, over, height, room)) {', $module);
        $this->assertStringContainsString('const delta = line.top - Math.min(Math.max(line.top, from), to - size);', $module);
        $this->assertStringContainsString('export const clearSpans = (box: Box, bars: Box[], height: number, room: number): Array<[number, number]> => {', $module);
        $this->assertStringContainsString('const spans = clearSpans(box, bars, height, room).filter(([from, to]) => to - from >= 1);', $module);
        // a look: a text area or an editable area taller than every clear span goes by its caret's line (left to the
        // browser when that cannot be told), every other control by its box
        $this->assertStringContainsString('const typesLines = (element: HTMLElement): boolean => element instanceof HTMLTextAreaElement || element.isContentEditable;', $module);
        $this->assertMatchesRegularExpression('/const covering = placing\(element, shown\);\s*if \(typesLines\(element\) && !fitsClear\(shown, covering, height, room\)\) \{\s*const line = caretLine\(element\);\s*return line \? lineDelta\(line, covering, height, room\) : 0;\s*\}\s*return revealDelta\(shown, covering, height, room\);/', $module);
        $this->assertStringContainsString('const delta = lookDelta(element, shown);', $module);
        // the focus move itself keeps the box rule (the browsers then reveal the caret they keep, on keyboard focus)
        $this->assertStringContainsString('const first = revealDelta(shown, placing(element, shown), height, room);', $module);
        $this->assertStringContainsString('check(element, false, placed);', $module);
        // a text area's caret line: a hidden copy of its text up to the caret (and the rest of its line), out of the
        // page's flow and scroll range, never painted, removed at once; the field's own scroll bar and scroll position
        // taken off; not past CARET_TEXT_MAX characters; an editable area: its selection's caret
        $this->assertStringContainsString('const CARET_TEXT_MAX = 20000;', $module);
        $this->assertStringContainsString("if (typeof at !== 'number' || at > CARET_TEXT_MAX) {", $module);
        foreach (["copy.style.setProperty('position', 'fixed');", "copy.style.setProperty('visibility', 'hidden');", "copy.style.setProperty('pointer-events', 'none');", "copy.style.setProperty('height', '0');", "copy.style.setProperty('overflow', 'hidden');"] as $rule) {
            $this->assertStringContainsString($rule, $module);
        }
        $this->assertMatchesRegularExpression('/document\.body\.appendChild\(copy\);\s*const first = marker\.getClientRects\(\)\[0\];\s*const origin = copy\.getBoundingClientRect\(\);\s*copy\.remove\(\);/', $module);
        $this->assertStringContainsString('element.offsetWidth - element.clientWidth', $module);
        $this->assertStringContainsString('- element.scrollTop - lead', $module);
        $this->assertStringContainsString("element.value.slice(at).split('\\n', 1)[0]", $module);
        $this->assertStringContainsString('range.setStart(node, selection.focusOffset);', $module);
        // an empty line of a right-to-left text area counts across its whole width: WebKit paints that caret at the
        // line's left end (under "Back to top") after a typed Enter, the copy puts it at the right
        $this->assertStringContainsString("export const emptyLineAt = (value: string, at: number): boolean => (at <= 0 || value.charAt(at - 1) === '\\n')", $module);
        $this->assertMatchesRegularExpression("/if \\(style\\.direction === 'rtl' && emptyLineAt\\(element\\.value, at\\)\\) \\{\\s*return \\{top, bottom, left: field\\.left \\+ borderLeft, right: field\\.right - borderRight\\};\\s*\\}\\s*return \\{top, bottom, left: x - 1, right: x \\+ 1\\};/", $module);
        // a typed key's looks (follows) go on after a scroll since their reference - the browser's own caret reveal -
        // but only further the same way; ticks, choices and the Tab's own looks still end there
        $this->assertMatchesRegularExpression('/if \(!own\.follows\) \{\s*end\(\);\s*return;\s*\}\s*along = Math\.sign\(window\.scrollY - settled\.y\);/', $module);
        $this->assertStringContainsString('if (delta !== 0 && (along === 0 || Math.sign(delta) === along)) {', $module);
        $this->assertMatchesRegularExpression('/settled = \{y: window\.scrollY, at: element\.getBoundingClientRect\(\)\.top \+ window\.scrollY\};\s*own\.follows = false;/', $module);
        // and a look that went on after someone's scroll is the last one: the next would compare with the page as that
        // scroll left it and could take it back (WebKit: the first look came after a script scroll made 60 ms after a
        // typed key, refused to move against it, and the second look undid it)
        $this->assertMatchesRegularExpression('/if \(\+\+rounds < 2 && along === 0\) \{/', $module);
        $this->assertSame(1, substr_count($module, '++rounds < 2'));
        $this->assertMatchesRegularExpression('/chain\.refresh = true;\s*chain\.follows = true;\s*return;/', $module);
        $this->assertStringContainsString('check(element, true, true, typed);', $module);
    }

    /**
     * A bar that floats between the bars at the window's edges ("Back to top" above a short window's save bar and tab
     * bar at 200 % zoom, its middle in the window's upper half) has a place above it and one below for every control
     * across its column: the box rule works in the window's clear spans, never sorting a bar by its middle (lane FE's
     * verification FE-V4-01: taken for a bar from above, the button left a 4px band below it and the switches and selects
     * of Video details ended partly under it while the 57px above it were free). With the bars at the window's edges
     * only, the one span is the band between them and every earlier result stands (tests/js/focus_clear.test.ts).
     */
    public function test_a_bar_that_floats_has_a_place_above_it_and_one_below(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/resources/js/layout/focusClear.ts');

        // the window's clear spans beside a box: the window less every bar across the box's width, `room` px from each;
        // a bar splits the span it falls in into the part above it and the part below it
        $this->assertStringContainsString('export const clearSpans = (box: Box, bars: Box[], height: number, room: number): Array<[number, number]> => {', $module);
        $this->assertMatchesRegularExpression('/if \(bar\.top - room > from\) \{\s*next\.push\(\[from, bar\.top - room\]\);\s*\}\s*if \(bar\.bottom \+ room < to\) \{\s*next\.push\(\[bar\.bottom \+ room, to\]\);\s*\}/', $module);
        // no bar is sorted by its middle any more (the scroll-padding's edge chains of wide bars aside: barEdges)
        $this->assertStringNotContainsString('(bar.top + bar.bottom) / 2 < height / 2', $module);
        $this->assertStringNotContainsString('clearBand', $module);
        // the box rule in those spans: nothing inside one; partly inside one it fits: that span's nearest edge (the
        // smaller move); else centred in the largest span; taller than every span: its top at the largest span's top -
        // centred there when it is taller by no more than the room, its ring (half the room) clear on both sides
        $this->assertStringContainsString('const spans = clearSpans(box, bars, height, room).filter(([from, to]) => to - from >= 1);', $module);
        $this->assertMatchesRegularExpression('/if \(spans\.some\(\(\[from, to\]\) => box\.top >= from && box\.bottom <= to\)\) \{\s*return 0;\s*\}/', $module);
        $this->assertStringContainsString('if (gain > 0 || (gain === 0 && Math.abs((from + to) / 2 - middle) < Math.abs((top + bottom) / 2 - middle))) {', $module);
        // taller than the roomed span by more than the room: centred in the raw band between the bars where the box fits
        // it (the band can be shorter than box + ring at 200 % zoom, so the ring is clipped the same on both sides:
        // FE-V5-02); else its top at the span's top
        $this->assertStringContainsString('const raw = clearSpans(box, bars, height, 0);', $module);
        $this->assertMatchesRegularExpression('/if \(band !== null && size <= band\[1\] - band\[0\]\) \{\s*return box\.top >= band\[0\] && box\.bottom <= band\[1\] \? 0 : Math\.round\(middle - \(band\[0\] \+ band\[1\]\) \/ 2\);\s*\}\s*return box\.top >= top && box\.top < bottom \? 0 : Math\.round\(box\.top - top\);/', $module);
        $this->assertMatchesRegularExpression('/if \(size > bottom - top\) \{\s*return box\.top >= top - room \/ 2 && box\.bottom <= bottom \+ room \/ 2 \? 0 : Math\.round\(middle - \(top \+ bottom\) \/ 2\);\s*\}/', $module);
        $this->assertMatchesRegularExpression('/if \(to - from >= size && box\.bottom > from && box\.top < to\) \{\s*const move = box\.top < from \? Math\.floor\(box\.top - from\) : Math\.ceil\(box\.bottom - to\);/', $module);
        $this->assertMatchesRegularExpression('/if \(nearest !== null\) \{\s*return nearest;\s*\}\s*return Math\.round\(middle - \(top \+ bottom\) \/ 2\);/', $module);
        // a text area or an editable area goes by its caret's line only when it fits no span
        $this->assertStringContainsString('export const fitsClear = (box: Box, bars: Box[], height: number, room: number): boolean =>', $module);
        $this->assertStringContainsString('if (typesLines(element) && !fitsClear(shown, covering, height, room)) {', $module);
        // the focus move and every later look use the same rule
        $this->assertStringContainsString('const first = revealDelta(shown, placing(element, shown), height, room);', $module);
        $this->assertStringContainsString('return revealDelta(shown, covering, height, room);', $module);

        // the Node cases hold the verifier's own layout (683x312, "Back to top" at 617-657 x 133-173) and the zoomed
        // layouts of the survey (200 % of 1366x625, 1280x720 and 1920x1080)
        $cases = (string) file_get_contents($root.'/tests/js/focus_clear.test.ts');
        $this->assertStringContainsString('const zoomBars = [box(0, 0, 683, 60), box(617, 133, 40, 40), box(0, 193, 683, 65), box(0, 258, 683, 54)];', $cases);
        $this->assertStringContainsString("check('200 %: the \"Allow comments\" switch half under the button: centred above it (84-108)', revealDelta(box(602, 153, 44, 24), zoomBars, 312, 8), 69);", $cases);
        $this->assertStringContainsString('const details640 = ', $cases);
        $this->assertStringContainsString('const details960 = ', $cases);
    }

    /**
     * Where "Back to top" fills the band left between the bars across its column (a short window at 200 % zoom: the watch
     * page past its sticky bar, the channel past its stuck tabs), no clear place exists for a control there: the control
     * is placed clear of every other bar and "Back to top" steps aside (transparent, pointers pass through, at once) while
     * that control has the focus, as the page's own rule steps it aside while a toast shows. Never where a clear place
     * exists (the control goes there) nor for a control taller than every span anyway; never while it has the focus
     * itself; back on a pointer, the wheel, a key that scrolls the page, the focus gone; keyboard only.
     */
    public function test_back_to_top_steps_aside_only_where_no_clear_place_is_left(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/resources/js/layout/focusClear.ts');
        $core = (string) file_get_contents($root.'/resources/js/layout/core.ts');

        // the floating controls that step aside: by default those that show past a scroll point ("Back to top", as
        // core.ts installs the module: no change there)
        $this->assertStringContainsString('yields?: string[];', $module);
        $this->assertStringContainsString('export const installFocusClear = ({bars, room = 8, showsPast = [], yields = showsPast.map(([selector]) => selector)}: FocusClearOptions): void => {', $module);
        $this->assertStringContainsString("installFocusClear({bars: SITE_BARS, showsPast: [['#back-to-top', BACK_TO_TOP_PAST]]})", $core);
        // the rule is a plain function (tests/js/focus_clear.test.ts): the control fits no clear span beside every bar
        // but fits one beside the others, and the floating control reaches its ring
        $this->assertStringContainsString('export const mustStepAside = (box: Box, all: Box[], solid: Box[], bar: Box | null, height: number, room: number): boolean =>', $module);
        // the fit is on the box, not box + ring (WCAG 2.4.12 is about the component): the button steps aside where the band
        // holds the box but clips the ring (FE-V5-02)
        $this->assertStringContainsString('bar !== null && reaches(bar, box, room / 2) && solid.length < all.length && !fitsBox(box, all, height) && fitsBox(box, solid, height);', $module);
        $this->assertStringContainsString('export const fitsBox = (box: Box, bars: Box[], height: number): boolean =>', $module);
        $this->assertStringContainsString('clearSpans(box, bars, height, 0).some(([from, to]) => to - from >= box.bottom - box.top);', $module);
        // the bars without those that step aside, and the placement that leaves them out only then (the box-fit, not box + ring)
        $this->assertStringContainsString("if ((except && bar.contains(except)) || (solid && yieldsSel !== '' && bar.matches(yieldsSel))) {", $module);
        $this->assertMatchesRegularExpression('/const placing = \(element: HTMLElement, shown: Box, atY: number = window\.scrollY\): Box\[\] => \{\s*const height = window\.innerHeight;\s*const covering = covers\(element, atY\);\s*if \(yieldsSel === \'\' \|\| fitsBox\(shown, covering, height\)\) \{\s*return covering;\s*\}\s*const solid = covers\(element, atY, true\);\s*return solid\.length < covering\.length && fitsBox\(shown, solid, height\) \? solid : covering;/', $module);
        // standing aside: transparent, pointers pass through, no fade (no frame shows the control under it), its own
        // inline values kept and put back as they were
        $this->assertStringContainsString("const ASIDE_STYLE: Array<[string, string]> = [['opacity', '0'], ['pointer-events', 'none'], ['transition', 'none']];", $module);
        $this->assertStringContainsString("ASIDE_STYLE.forEach(([name, value]) => bar.style.setProperty(name, value, 'important'));", $module);
        $this->assertStringContainsString('bar.style.getPropertyPriority(name)', $module);
        // never for itself or a control inside it, and only in keyboard mode
        $this->assertStringContainsString('!bar.contains(element) && !element.contains(bar) ? floatingBox(bar) : null;', $module);
        // one the page is taking away (the move went back above its scroll point; it fades out) that still reaches the
        // control is gone at once, not fading out over it
        $this->assertStringContainsString('const going = point !== null && window.scrollY <= point;', $module);
        $this->assertStringContainsString('if (going && !aside.has(bar) && Number(style.opacity) < 0.1) {', $module);
        $this->assertMatchesRegularExpression('/if \(floating\.going\) \{\s*(\/\/[^\n]*\n\s*)*on = true;\s*\} else \{\s*all = all \|\| covers\(element\);\s*solid = solid \|\| covers\(element, window\.scrollY, true\);\s*on = mustStepAside\(shown, all, solid, floating\.box, window\.innerHeight, room\);/', $module);
        $this->assertStringContainsString('const shown = element !== null && keyboard ? visibleBox(element) : null;', $module);
        // when: right after the focus move's reveal and at every later look; back on a pointer, the wheel, a key that
        // scrolls the page, the focus gone from the page, a control in a bar or a layer ("Back to top" itself)
        $this->assertMatchesRegularExpression('/const placed = reveal\(element, tabbed\);\s*(\/\/[^\n]*\n\s*)*stepAsideFor\(element\);/', $module);
        $this->assertMatchesRegularExpression('/clearBy\(element, delta\);\s*\}\s*\}\s*(\/\/[^\n]*\n\s*)*stepAsideFor\(element\);/', $module);
        $this->assertMatchesRegularExpression("/addEventListener\('pointerdown', \(\) => \{\s*keyboard = false;\s*tabbing = false;\s*pending\+\+;\s*clearPadding\(\);\s*stepAsideFor\(null\);/", $module);
        $this->assertMatchesRegularExpression("/addEventListener\('focusout', \(event\) => \{\s*if \(\(event as FocusEvent\)\.relatedTarget === null\) \{\s*stepAsideFor\(null\);/", $module);
        $this->assertMatchesRegularExpression('/if \(insideFixed\(element, bars\)\) \{\s*(\/\/[^\n]*\n\s*)*clearPadding\(\);\s*stepAsideFor\(null\);\s*return;/', $module);
        // the wheel, a pointer, the focus gone and a control in a bar/layer bring it back at once; a key brings it back only
        // through bringBackOnScroll, once the page moves (FE-V5-01), so the keydown no longer calls stepAsideFor(null)
        $this->assertSame(4, substr_count($module, 'stepAsideFor(null);'));
        // the reveal and the looks place the control by the same bars
        $this->assertStringContainsString('const first = revealDelta(shown, placing(element, shown), height, room);', $module);
        $this->assertStringContainsString('const covering = placing(element, shown);', $module);
        // the Node cases hold the survey's two layouts where no clear span is left (the watch page, the channel)
        $cases = (string) file_get_contents($root.'/tests/js/focus_clear.test.ts');
        $this->assertStringContainsString("check('200 % watch: the look switch fits the band without the button: it steps aside', mustStepAside(switcherZoom, [...watchZoom, bttZoom], watchZoom, bttZoom, 312, 8), true);", $cases);
        $this->assertStringContainsString("check('200 %: a switch that fits the span above the button: the button stays',", $cases);
        // and the FE-V5-02 cases: a 44px control whose box fits the 49.8px watch band but whose box + ring does not steps
        // aside and is centred there (fitsBox, not fitsClear)
        $this->assertStringContainsString("check('fitsBox: a 44px control fits the 49.8px watch band (ring clipped)', fitsBox(box(513, 132, 134, 44), watchZoom, 312), true);", $cases);
        $this->assertStringContainsString("mustStepAside(langZoom, [...watchZoom, bttZoom], watchZoom, bttZoom, 312, 8), true);", $cases);
        $this->assertStringContainsString("check('200 % watch: that select centred in the band, the ring clipped evenly, no part of the box under a bar', revealDelta(langZoom, watchZoom, 312, 8), 8);", $cases);
    }

    /**
     * The bell's list keeps its day headings stuck at its top (member/_notify.scss): the list's own focus scroll stops a
     * heading's height and some room below its top, and a row's ring is drawn inside the row.
     */
    public function test_the_bell_list_keeps_a_focused_row_clear_of_its_stuck_day_heading(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/sass/member/_notify.scss');

        $this->assertMatchesRegularExpression('/:root \.mk-notify__group \{\s*position: sticky;\s*inset-block-start: 0;\s*z-index: 1;\s*padding: \.55rem 1\.25rem \.4rem;[^}]*font-size: \.75rem;/', $css);
        $this->assertMatchesRegularExpression('/:root \.avd-bell-scroll \{\s*scroll-padding-block-start: calc\(2\.075rem \+ \.5rem\);\s*\}/', $css);
        $this->assertMatchesRegularExpression('/:root \.avd-bell-scroll \.mk-notif__link:focus-visible \{\s*outline: 2px solid var\(--avd-focus\);\s*outline-offset: -2px;\s*\}/', $css);

        // the keyboard module honours that scroll-padding at once where the browser does not (Firefox leaves a partly
        // visible row under the heading), before it looks at fixed layers (the bell's sheet is one)
        $module = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/layout/focusClear.ts');
        $this->assertStringContainsString('const clearInScroller = (element: HTMLElement): void => {', $module);
        $this->assertStringContainsString('const padTop = parseFloat(style.scrollPaddingTop) || 0;', $module);
        $this->assertMatchesRegularExpression('/clearInScroller\(element\);\s*if \(insideFixed\(element, bars\)\) \{/', $module);
    }

    /**
     * Node.js >= 22.18 (it runs TypeScript files directly): NODE_BINARY if set, then the PATH as PHP sees it (each entry
     * trimmed of quotes: a quoted entry hides Node from cmd.exe's own search on some machines), the shell's search, and
     * the usual install folders.
     */
    private function nodeBinary(): ?string
    {
        if (! function_exists('exec')) {
            return null;
        }

        $windows = PHP_OS_FAMILY === 'Windows';
        $names = $windows ? ['node.exe'] : ['node'];
        $candidates = [];

        foreach (['NODE_BINARY', 'NODE'] as $variable) {
            $value = trim((string) getenv($variable), " \t\"'");

            if ($value !== '') {
                $candidates[] = $value;
            }
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $folder) {
            $folder = trim($folder, " \t\"'");

            if ($folder !== '') {
                foreach ($names as $name) {
                    $candidates[] = rtrim($folder, '/\\').DIRECTORY_SEPARATOR.$name;
                }
            }
        }

        $found = [];
        @exec($windows ? 'where node 2>NUL' : 'command -v node 2>/dev/null', $found);
        $candidates = array_merge($candidates, array_map('trim', $found));

        if ($windows) {
            foreach (['ProgramFiles', 'ProgramW6432', 'ProgramFiles(x86)', 'LOCALAPPDATA'] as $variable) {
                $base = (string) getenv($variable);

                if ($base !== '') {
                    $candidates[] = $base.'\\nodejs\\node.exe';
                    $candidates[] = $base.'\\Programs\\nodejs\\node.exe';
                }
            }

            $candidates[] = 'C:\\Program Files\\nodejs\\node.exe';
        } else {
            array_push($candidates, '/usr/local/bin/node', '/usr/bin/node', '/opt/homebrew/bin/node');
        }

        foreach (array_unique($candidates) as $candidate) {
            if ($candidate === '' || ! is_file($candidate)) {
                continue;
            }

            $version = [];
            @exec(escapeshellarg($candidate).' -p "process.versions.node" 2>&1', $version);
            $parts = array_map('intval', explode('.', trim((string) ($version[0] ?? '0'))));

            if (($parts[0] ?? 0) > 22 || (($parts[0] ?? 0) === 22 && ($parts[1] ?? 0) >= 18)) {
                return $candidate;
            }
        }

        return null;
    }
}
