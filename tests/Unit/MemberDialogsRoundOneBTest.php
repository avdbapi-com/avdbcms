<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 1.2.3 round 1b, the member dialogs (the browser proof: the lane's dialog probe, 12 looks x 390 /
 * 768 / 1366 px x Chromium and WebKit x English and Arabic):
 *  - R1R-10 Save to playlist: a playlist is one row-wide label (box, name, visibility icon), so a tap on the box, the
 *    name or the rest of the row ticks it once; in Arabic the icon lay on the box (Bootstrap's left-to-right check row);
 *  - R1R-11 the close X of a dialog or side panel sits at the end of its header in both directions (in Arabic it
 *    overlapped the title's last word); the upload panel (hands-off) keeps its header;
 *  - R1R-12 toasts are above every dialog, sheet and backdrop, and announced from inside an open dialog or panel;
 *    second pass: announced where a screen reader is when the words are said (a dialog closing in the same moment no
 *    longer swallows them), and kept clear of the open panel's controls.
 */
class MemberDialogsRoundOneBTest extends TestCase
{
    public function test_a_save_to_playlist_row_is_one_label_in_reading_order(): void
    {
        $save = $this->source('resources/js/components/Playlists/Save.tsx');

        $this->assertMatchesRegularExpression('~<label className="form-check avd-save-playlist mb-3" htmlFor=\{\'playlist-\' \+ playlist\.id\}>\s*<input\s+className="form-check-input"~', $save);
        $this->assertStringContainsString('<span className="form-check-label text-break">{playlist.title}</span>', $save);
        $this->assertStringContainsString("<i className={'fa-solid fa-' + playlist.icon} aria-hidden=\"true\"></i>\n        </label>", $save);
        $this->assertStringNotContainsString('<div className="d-flex align-items-center gap-3">', $save, 'no box floated beside its label any more');

        $scss = $this->source('resources/sass/video.scss');
        $this->assertMatchesRegularExpression('~:root \.avd-save-playlist \{\s*display: flex;~', $scss);
        $this->assertMatchesRegularExpression('~:root \.avd-save-playlist \.form-check-input \{\s*flex: none;\s*float: none;~', $scss);
        // the other check rows of the site's dialogs (Report's reasons) start at the reading start in Arabic
        $this->assertMatchesRegularExpression('~:root\[dir="rtl"\] \.modal \.form-check:not\(\.form-check-reverse\):not\(\.form-switch\):not\(\.avd-save-playlist\) \{\s*padding-inline: 1\.5em 0;~', $scss);
        $this->assertMatchesRegularExpression('~\.form-check-input \{\s*float: inline-start;\s*margin-inline: -1\.5em 0;~', $scss);
    }

    public function test_the_close_x_sits_at_the_end_of_the_header_in_both_directions(): void
    {
        $app = $this->source('resources/sass/app.scss');

        $this->assertMatchesRegularExpression('~\.modal:not\(\[id="video_create"\]\) \.modal-header \.btn-close \{\s*margin-block: calc\(-\.5 \* var\(--bs-modal-header-padding-y\)\);\s*margin-inline: auto calc\(-\.5 \* var\(--bs-modal-header-padding-x\)\);~', $app);
        $this->assertMatchesRegularExpression('~\.offcanvas-header \.btn-close \{\s*margin-block: calc\(-\.5 \* var\(--bs-offcanvas-padding-y\)\);\s*margin-inline: auto calc\(-\.5 \* var\(--bs-offcanvas-padding-x\)\);~', $app);
    }

    public function test_toasts_are_above_every_dialog_and_announced_from_inside_it(): void
    {
        $shared = $this->source('resources/sass/member/_shared.scss');
        $app = $this->source('resources/sass/app.scss');
        preg_match('~:root \.mk-toasts \{[^}]*z-index: (\d+);~', $shared, $toasts);
        preg_match('~\n\.modal \{\s*z-index: (\d+);~', $app, $modal);
        preg_match('~\n\.modal-backdrop \{\s*z-index: (\d+);~', $app, $backdrop);
        $this->assertNotEmpty($toasts);
        $this->assertNotEmpty($modal);
        $this->assertGreaterThan((int) $modal[1], (int) $toasts[1], 'above the dialogs (a dialog later in the document painted over a toast with the same z-index)');
        $this->assertGreaterThan((int) $backdrop[1], (int) $toasts[1]);
        $this->assertGreaterThan(1095, (int) $toasts[1], 'above the watch page\'s phone tools sheet');
        $this->assertLessThan(1120, (int) $toasts[1], 'under the open menus');

        $ts = $this->source('resources/js/layout/toasts.ts');
        $this->assertStringContainsString("const LAYER_SELECTOR = '.modal.show, .offcanvas.show, .offcanvas.showing';", $ts);
        $this->assertMatchesRegularExpression('~if \(!layer\) \{\s*toast\.setAttribute\(\'role\', type === \'error\' \? \'alert\' : \'status\'\);\s*\}~', $ts, 'said once: the toast keeps no live role while a dialog says it');
    }

    /**
     * Second pass (BF-RV-01): a toast shown in the same moment a dialog closes (a failed Unsubscribe closes its dialog
     * and shows its error in one handler) was said into the closing dialog's region, which was then removed: nobody
     * heard it. The region is chosen when the words are said: the layer open then, else the page's own region in the
     * toast stack once every closing layer has gone. Browser proof: the lane's toast probe.
     */
    public function test_the_words_are_said_where_a_screen_reader_is_when_they_are_said(): void
    {
        $ts = $this->source('resources/js/layout/toasts.ts');

        $this->assertStringContainsString("const CLOSING_SELECTOR = '.modal[aria-modal=\"true\"]:not(.show), .offcanvas.hiding';", $ts);
        $this->assertStringNotContainsString('sayInLayer', $ts, 'never into the layer that was open when the toast showed');
        $this->assertMatchesRegularExpression('~if \(layer\) \{\s*const words = [^;]+;\s*window\.setTimeout\(\(\) => announce\(toast, words, type\), SAY_AFTER_MS\);~', $ts);

        preg_match('~const announce = \(toast: HTMLElement, words: string, type: ToastType, waited = 0\): void => \{(.*?)\n\};~s', $ts, $announce);
        $this->assertNotEmpty($announce);
        $this->assertMatchesRegularExpression('~^\s*const layer = openLayer\(\);\s*if \(layer\) \{\s*sayIn\(regionIn\(layer\), toast, words, type\);\s*return;\s*\}~', $announce[1], 'the layer open when the words are said');
        $this->assertMatchesRegularExpression('~if \(waited < CLOSING_WAIT_MS && closingLayer\(\)\) \{\s*window\.setTimeout\(\(\) => announce\(toast, words, type, waited \+ 50\), 50\);~', $announce[1], 'a closing layer first goes');
        $this->assertStringContainsString('sayIn(regionIn(toastStack()), toast, words, type);', $announce[1], 'then the page\'s region');

        // the words leave the region with their toast
        $this->assertMatchesRegularExpression('~const said = saidIn\.get\(toast\);\s*if \(said && said\.region\.textContent === said\.words\) \{\s*said\.region\.textContent = \'\';~', $ts);
    }

    /**
     * Second pass (BF-RV-03): above an open dialog, the toast lay on its first controls on phones (Save to playlist's
     * first playlists, Report's first reason) for up to 8 s. The stack takes the first place that leaves the panel free
     * (under the header, the same height beside a side panel, the top edge, the bottom edge); where none does, the place
     * covering the fewest of its controls (its close button counts most), and the toasts let taps through to the
     * panel. Browser proof:
     * the lane's placement probe.
     */
    public function test_toasts_keep_clear_of_an_open_panel_and_let_taps_through_where_they_cannot(): void
    {
        $ts = $this->source('resources/js/layout/toasts.ts');

        $this->assertStringContainsString("const PLACES = ['', BESIDE, 'mk-toasts--edge-top', 'mk-toasts--edge-bottom'];", $ts);
        $this->assertStringContainsString("const CLOSE_SELECTOR = '.btn-close, [data-bs-dismiss]';", $ts);
        $this->assertMatchesRegularExpression('~if \(free < widest \+ 32\) \{\s*return null;\s*\}~', $ts, 'beside a side panel only where the toasts fit with 16 px on each side');
        $this->assertMatchesRegularExpression('~if \(!boxes\.some\(\(box\) => meets\(box, panelBox\)\)\) \{\s*return;\s*\}~', $ts, 'the first place clear of the panel wins');
        $this->assertMatchesRegularExpression('~apply\(best !== null \? best\.place : \'\'\);\s*host\.classList\.add\(\'mk-toasts--through\'\);\s*\};~', $ts, 'on the panel: taps go through');
        $this->assertMatchesRegularExpression('~if \(layer\) \{.*?placeStack\(\);\s*placeSoon\(\);~s', $ts, 'placed when it shows over a layer');
        $this->assertStringContainsString("watcher.observe(document.body, {attributes: true, attributeFilter: ['class'], childList: true});", $ts, 'and when a layer opens or closes while it shows');
        $this->assertMatchesRegularExpression('~const placeStack = \(\): void => \{.*?syncPanels\(\);\s*if \(!layer\) \{~s', $ts, 'a closed react panel gives the stack its own place back');

        // after the open-layer rule (:root body.modal-open .mk-toasts, :root .mk-toasts.mk-toasts--top), at its
        // specificity or more
        $shared = $this->source('resources/sass/member/_shared.scss');
        $open = strpos($shared, ':root body.modal-open .mk-toasts,');
        $this->assertNotFalse($open);
        $this->assertGreaterThan($open, strpos($shared, ':root body .mk-toasts.mk-toasts--edge-top {'));
        $this->assertMatchesRegularExpression('~:root body \.mk-toasts\.mk-toasts--edge-top \{\s*inset-block: max\(12px, env\(safe-area-inset-top\)\) auto;~', $shared);
        $this->assertMatchesRegularExpression('~:root body \.mk-toasts\.mk-toasts--edge-bottom \{\s*inset-block: auto max\(12px, env\(safe-area-inset-bottom\)\);~', $shared);
        $this->assertMatchesRegularExpression('~:root \.mk-toasts--through \.mk-toast \{\s*pointer-events: none;\s*\}\s*:root \.mk-toasts--through \.mk-toast :is\(a, button\) \{\s*pointer-events: auto;~', $shared);
    }

    private function source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative);
    }
}
