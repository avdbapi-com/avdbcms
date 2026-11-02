<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 1.2.3: how the layout core (resources/js/layout/core.ts) wires what
 * moved out of the pages. The behaviour itself was also checked in browsers: the scroll lock (the same paddings as
 * Bootstrap, less work in the click), every watch tool and dialog, no icon without its rule, the ripple and the intro.
 */
class FrontRound3ContractTest extends TestCase
{
    /** perf-14: dialogs and side panels lock the page's scroll with the reads before the writes. */
    public function test_dialogs_and_side_panels_lock_the_scroll_without_the_layout_thrash(): void
    {
        $lock = $this->source('resources/js/layout/scrollLock.ts');
        $this->assertStringContainsString("import {Modal, Offcanvas} from 'bootstrap';", $lock);
        // Modal gets the lock as its scroll bar helper; Offcanvas skips its own helper only while Bootstrap reads the option
        $this->assertStringContainsString('this._scrollBar = modalScrollBar;', $lock);
        $this->assertStringContainsString('config.scroll = true; // Bootstrap\'s own helper is skipped', $lock);
        $this->assertStringContainsString('config.scroll = false; // ... and its focus trap rule', $lock);
        // every read before the first write
        $body = substr($lock, (int) strpos($lock, 'export const lockPageScroll'), (int) strpos($lock, 'export const unlockPageScroll') - (int) strpos($lock, 'export const lockPageScroll'));
        $firstWrite = strpos($body, 'style.setProperty(');
        $this->assertNotFalse($firstWrite);
        foreach (['clientWidth', 'getComputedStyle'] as $read) {
            $this->assertLessThan($firstWrite, strrpos($body, $read), $read.' after a write');
        }
        $core = $this->source('resources/js/layout/core.ts');
        $this->assertStringContainsString("import {installCalmScrollLock} from './scrollLock';", $core);
        $this->assertStringContainsString('safely(installCalmScrollLock);', $core);
    }

    /** watch-7: dialogs in <template data-avd-lazy> are rendered on intent, on their trigger, or when the page is idle. */
    public function test_lazy_dialogs_render_before_bootstrap_looks_them_up(): void
    {
        $core = $this->source('resources/js/layout/core.ts');
        $this->assertStringContainsString("window.addEventListener('click', early, true);", $core, 'capture on window: before Bootstrap\'s capture listener on document');
        $this->assertStringContainsString("window.addEventListener('pointerover', early, {capture: true, passive: true});", $core);
        $this->assertStringContainsString("window.addEventListener('focusin', early, true);", $core);
        $this->assertStringContainsString('alpine.initTree?.(root)', $core);
        $this->assertStringContainsString('window.avdLazy = materializeLazy;', $core);
        $this->assertStringContainsString('safely(initLazyTemplates);', $core);

        $tools = $this->source('resources/js/layout/watchTools.ts');
        foreach (['avdSleepTimerModal', 'avdNotesModal', 'avdEmbedModal', 'avdMobileToolsSheet', 'avdTelemetryModal'] as $dialog) {
            $this->assertStringContainsString("avdLazy('".$dialog."')", $tools, $dialog);
        }

        // a panel whose badges follow the player (the phone tool sheet, the video details dialog) is brought up to date
        // when it is rendered: core.ts announces every rendered template, the watch tools answer for their two panels
        $this->assertStringContainsString("document.dispatchEvent(new CustomEvent('avd:lazy-rendered', {detail: {id}}))", $core);
        $this->assertStringContainsString("document.addEventListener('avd:lazy-rendered', function(event) {", $tools);
        $sync = substr($tools, (int) strpos($tools, 'function avdSyncRenderedPanel(id) {'));
        $this->assertNotSame('', $sync);
        foreach (['avdMobileFilterBadge', '.avd-mob-filter-chip', 'avdMobileRotateBadge', 'avdMobileBrightBadge', 'avdMobileAmbiBadge', 'avdUpdatePlayerCapabilities();', 'avdTelemFilter', 'avdTelemAspect', 'avdTelemMirror', 'avdTelemAmbilight', 'avdBindMobileSheet(sheet);'] as $field) {
            $this->assertStringContainsString($field, $sync, $field.' is brought up to date');
        }
    }

    /** watch-7: the watch tools run after the layout core: the keys, the share and the early clicks keep working. */
    public function test_the_watch_tools_module_keeps_the_keys_share_and_early_clicks(): void
    {
        $tools = $this->source('resources/js/layout/watchTools.ts');
        // the layout's shortcut handler skips the keys the watch handler ran: the watch handler runs first (capture)
        $this->assertStringContainsString("document.addEventListener('keydown', function(e) {\n            if (e.cancelBubble) return;", $tools);
        $this->assertStringContainsString("window.avdRelativeSeek && window.avdRelativeSeek(-5);\n            }\n        }, true);", $tools);
        // the dock's share is registered with the layout's avdQuickShare (cards keep sharing their own video)
        $this->assertStringContainsString('window.avdPageShare = function() {', $tools);
        $this->assertStringNotContainsString('window.avdQuickShare = function', $tools);
        $this->assertStringContainsString('typeof window.avdPageShare === \'function\' ? window.avdPageShare : earlyShare', $this->source('resources/js/layout/core.ts'));
        // the view's queue stubs are replayed
        $this->assertStringContainsString('window.__avdWatchQ = {', $tools);
        $this->assertStringContainsString("var settingsEl = document.getElementById('avd-watch-tools');", $tools);
        // core.ts loads the module and the stylesheet when the page could not link them (and puts both in the manifest)
        $core = $this->source('resources/js/layout/core.ts');
        $this->assertStringContainsString("void optionalLoad(import('./watchTools')).catch(() => undefined);", $core);
        $this->assertStringContainsString("void optionalLoad(import('../../css/watch-page.css')).catch(() => undefined);", $core);
    }

    /** perf-7: an icon that still finds no rule loads the full set once. */
    public function test_the_icon_safety_net_is_installed(): void
    {
        $icons = $this->source('resources/js/layout/icons.ts');
        $this->assertStringContainsString("getPropertyValue('--fa')", $icons);
        $this->assertStringContainsString("import('../../sass/fa-full.scss')", $icons);
        $this->assertStringContainsString('watchAddedElements(document.body', $icons);
        $core = $this->source('resources/js/layout/core.ts');
        $this->assertStringContainsString('safely(installIconSafetyNet);', $core);
    }

    /**
     * watch-7 (the low-end run): the icon check asked every icon element in one 100-140 ms task on a slow phone. Each
     * set of icon names is asked once, in idle slices that stop when the idle period runs low.
     */
    public function test_the_icon_check_runs_in_short_idle_slices(): void
    {
        $icons = $this->source('resources/js/layout/icons.ts');
        $this->assertStringContainsString('if (!names.length || known.has(key)) {', $icons);
        $this->assertStringContainsString('known.add(key);', $icons);
        $this->assertStringContainsString('while (queue.length && (asked < SLICE_MINIMUM || deadline.timeRemaining() > SLICE_RESERVE_MS)) {', $icons);
        $this->assertStringContainsString('window.requestIdleCallback((deadline) => task(deadline), {timeout});', $icons);
        // the whole document is not asked in the load event's task any more: check() only queues
        $check = substr($icons, (int) strpos($icons, 'const check = (roots'), 600);
        $this->assertStringNotContainsString('getComputedStyle', $check);
    }

    /**
     * watch-7 (the low-end run): the phone ad strip's first reads forced the page's first layout from a
     * requestAnimationFrame (200-350 ms in the watch page's first frame at CPU 4x), and its height went into a custom
     * property on <body> that restyled the whole page even when it was the stylesheets' own default.
     */
    public function test_the_ad_strip_reads_after_the_layout_and_writes_only_a_different_height(): void
    {
        $core = $this->source('resources/js/layout/core.ts');
        $this->assertStringNotContainsString('window.requestAnimationFrame(watchRows)', $core);
        $this->assertStringContainsString("const STRIP_DEFAULT_HEIGHT = '66px';", $core);
        $this->assertStringContainsString('const value = measured === STRIP_DEFAULT_HEIGHT ? \'\' : measured;', $core);
        // the yield starts in the ResizeObserver callback, with its reads before the write
        $observer = substr($core, (int) strpos($core, 'new ResizeObserver((entries) => {', (int) strpos($core, 'const initAdStrip')), 700);
        $this->assertLessThan(strpos($observer, 'write(height);'), strpos($observer, 'startYield();'));
        // reads before writes when the yield is placed
        $place = substr($core, (int) strpos($core, 'const place = (): void => {'), 500);
        $this->assertLessThan(strpos($place, 'watchRows(band);'), strpos($place, 'const band = readBand();'));
        $this->assertLessThan(strpos($place, 'watchRows(band);'), strpos($place, 'firstScreen = onFirstScreen();'));
        // the default is the stylesheets' fallback for the variable
        $this->assertStringContainsString('var(--avd-ad-strip-h, 66px)', $this->source('resources/sass/responsive.scss'));
        $this->assertStringContainsString('var(--avd-ad-strip-h, 66px)', $this->source('resources/sass/member/_shared.scss'));
    }

    /**
     * watch-7 (the low-end run): a dialog rendered where its template was, among the page's other children, restyled the
     * content around it (35-80 ms on a slow phone even for an empty element); at the end of the same parent it costs
     * under 1 ms and keeps the same ancestors. The idle pass renders one dialog per idle slot.
     */
    public function test_lazy_dialogs_are_rendered_at_the_end_of_their_parent_one_per_idle_slot(): void
    {
        $core = $this->source('resources/js/layout/core.ts');
        $materialize = substr($core, (int) strpos($core, 'const materializeLazy'), 1600);
        $this->assertStringContainsString('const parent = template.parentNode;', $materialize);
        $this->assertStringContainsString('parent.appendChild(content);', $materialize);
        $this->assertStringNotContainsString('insertBefore(content, template)', $materialize);
        // the template stays where it is (removing it restyled the content around it too), without its marker
        $this->assertStringNotContainsString('template.remove();', $materialize);
        $this->assertStringContainsString("template.removeAttribute('data-avd-lazy');", $materialize);
        $lazy = substr($core, (int) strpos($core, 'const initLazyTemplates'), 3200);
        $this->assertStringContainsString('safely(() => materializeLazy(id));', $lazy);
        $this->assertStringContainsString('idle(renderNext);', $lazy);
        // a panel laid out while hidden (the phone tool sheet) is skipped by the idle pass: first use only, started by the
        // touch on (or the pointer over) the More button so its layout is done before the click opens it
        $this->assertStringContainsString(".filter((template) => template.dataset.avdLazyIdle !== 'off')", $lazy);
        $tools = $this->source('resources/js/layout/watchTools.ts');
        $this->assertStringContainsString("if (window.innerWidth < 768) avdLazy('avdMobileToolsSheet');", $tools);
        $this->assertStringContainsString("moreButton.addEventListener('pointerdown', avdWarmMobileSheet, {passive: true});", $tools);
    }

    private function source(string $relative): string
    {
        $text = file_get_contents(dirname(__DIR__, 2).'/'.$relative);
        $this->assertNotFalse($text, $relative);

        return str_replace("\r\n", "\n", (string) $text);
    }
}
