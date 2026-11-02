<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The watch page's keys run once (reviewer finding R3PW-RV-01 of the 1.2.3 round 3 pre-fix pass; the same in 1.2.2).
 * The watch page's own handler (resources/js/layout/watchTools.ts, capture phase) runs its keys first and cancels them
 * (preventDefault); the layout's shortcut handler (resources/js/layout/core.ts) then leaves every key it ran alone
 * (watchHandled), so a toggle is not undone by a second run. C was the one key without that check: on the watch page it
 * changed the color filter, as the dock's tooltip "Color filter (C)" and the keyboard shortcuts panel say, and the
 * layout's handler also saved a bookmark of the moment and opened the bookmark shelf with every press.
 */
class WatchKeysRunOnceTest extends TestCase
{
    private function source(string $file): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(resource_path('js/layout/'.$file)));
    }

    /**
     * The letters the watch page's handler runs and cancels: each branch of its keydown handler that tests a letter
     * and calls e.preventDefault().
     *
     * @return array<string, string> lower-case letter => the branch's source
     */
    private function watchPageKeys(): array
    {
        $source = $this->source('watchTools.ts');
        $start = strpos($source, "document.addEventListener('keydown', function(e) {");
        $this->assertNotFalse($start, "the watch page's keydown handler");
        $end = strpos($source, '}, true);', (int) $start);
        $this->assertNotFalse($end, "the end of the watch page's keydown handler (capture phase)");
        $handler = substr($source, (int) $start, (int) $end - (int) $start);

        $keys = [];
        $branches = preg_split('/\}\s*else if\s*/', $handler) ?: [];
        foreach ($branches as $branch) {
            if (preg_match("/^\(\(e\.key === '([a-z])' \|\| e\.key === '([A-Z])'\)/", ltrim($branch), $m)
                && strtolower($m[2]) === $m[1] && str_contains($branch, 'e.preventDefault();')) {
                $keys[$m[1]] = ($keys[$m[1]] ?? '').$branch;
            }
        }

        return $keys;
    }

    /**
     * The layout's shortcut handler: each letter case of its switch and the case's body.
     *
     * @return array<string, string>
     */
    private function layoutCases(): array
    {
        $source = $this->source('core.ts');
        $start = strpos($source, 'const initShortcuts = ');
        $this->assertNotFalse($start, "the layout's shortcut handler");
        $switch = strpos($source, 'switch (e.key) {', (int) $start);
        $this->assertNotFalse($switch);
        $end = strpos($source, "\n};", (int) $switch);
        $body = substr($source, (int) $switch, (int) $end - (int) $switch);

        preg_match_all("/case '([a-z])':\s*case '([A-Z])':(.*?)break;/s", $body, $all, PREG_SET_ORDER);
        $cases = [];
        foreach ($all as $m) {
            $this->assertSame(strtoupper($m[1]), $m[2]);
            $cases[$m[1]] = $m[3];
        }

        return $cases;
    }

    public function test_a_key_the_watch_page_ran_is_not_run_again_by_the_layout(): void
    {
        $watch = $this->watchPageKeys();
        $layout = $this->layoutCases();
        // the watch page's handler as it is today: these letters at least (a missing one means the parser broke)
        foreach (['a', 'b', 'c', 'l', 'n', 't', 'v', 'w', 'z'] as $letter) {
            $this->assertArrayHasKey($letter, $watch, "the watch page's handler runs and cancels ".$letter);
            $this->assertArrayHasKey($letter, $layout, "the layout's handler has a case for ".$letter);
        }

        $both = array_intersect(array_keys($watch), array_keys($layout));
        sort($both);
        foreach ($both as $letter) {
            $this->assertStringContainsString('!watchHandled', $layout[$letter], strtoupper($letter)." is run by the watch page's handler: the layout's handler must leave it alone there");
        }
    }

    public function test_c_is_the_color_filter_and_no_bookmark_on_the_watch_page(): void
    {
        $watch = $this->watchPageKeys();
        $this->assertStringContainsString('window.avdCycleVideoFilter && window.avdCycleVideoFilter();', $watch['c']);
        $this->assertStringNotContainsString('avdAddBookmark', $watch['c']);

        $layout = $this->layoutCases();
        $this->assertSame("if (!watchHandled) player('avdAddBookmark');", trim((string) preg_replace('#^\s*//.*$#m', '', $layout['c'])));
    }
}
