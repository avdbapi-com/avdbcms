<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 1.2.3 round 3 (R3-068): fast Tab presses through the category chips row went round the row again (three passes in 26
 * presses at 120 ms): the last chip's focus scrolls the row smoothly, a Tab during that scroll lands on "Scroll right",
 * and when the row reached its end the arrow hid and handed the focus to the track, which comes before the chips. An
 * arrow the keyboard is on (:focus-visible) now stays, shown and in the tab order, until the focus leaves it; a mouse
 * focus still goes to the track as before. Browser proof: lane FE's chiptab.js (Chromium, WebKit, Firefox).
 */
class ChipsRowKeyboardTest extends TestCase
{
    public function test_an_arrow_the_keyboard_is_on_stays_until_the_focus_leaves_it(): void
    {
        $core = (string) file_get_contents(resource_path('js/layout/core.ts'));
        $start = strpos($core, 'const setupChipsScroller');
        $this->assertNotFalse($start);
        $chips = substr($core, (int) $start, (int) strpos($core, 'const applyNav', (int) $start) - (int) $start);

        $this->assertStringContainsString("return button.matches(':focus-visible');", $chips, 'keyboard focus only');
        $this->assertMatchesRegularExpression('/if \(opacity === \'0\' && document\.activeElement === button\) \{\s*if \(keyboardFocused\(button\)\) \{\s*heldArrows\.set\(button, \[opacity, pointerEvents\]\);\s*return;\s*\}\s*track\.focus\(\{preventScroll: true\}\);\s*\}/', $chips);
        // the held state is applied when the focus leaves the arrow
        $this->assertMatchesRegularExpression("/button\.addEventListener\('focusout', \(\) => \{\s*const held = heldArrows\.get\(button\);\s*if \(held\) \{\s*heldArrows\.delete\(button\);\s*writeButtonState\(button, held\[0\], held\[1\]\);/", $chips);
        // a shown state cancels a held hide
        $this->assertMatchesRegularExpression('/heldArrows\.delete\(button\);\s*writeButtonState\(button, opacity, pointerEvents\);/', $chips);
    }
}
