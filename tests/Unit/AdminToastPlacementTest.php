<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AVDBCMS 1.2.3: an error toast stays until it is closed, and at 1440x900 it covered the save bar's
 * Save changes and Discard (found in the 1.2.2 test). The stack now sits above any bar at the bottom of the window while one shows,
 * phones too (resources/admin/modules/toast.ts with the geometry of resources/admin/lib/placement.ts).
 */
class AdminToastPlacementTest extends TestCase
{
    public function test_the_placement_rule_passes_its_cases_in_node(): void
    {
        $node = $this->nodeBinary();

        if ($node === null) {
            $this->markTestSkipped('Node.js 22.18 or newer is needed to run tests/js/toast_placement.test.ts.');
        }

        $root = dirname(__DIR__, 2);
        $output = [];
        exec(escapeshellarg($node).' '.escapeshellarg($root.'/tests/js/toast_placement.test.ts').' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertMatchesRegularExpression('/toast placement: \d+ passed, 0 failed/', implode("\n", $output));
    }

    public function test_the_toasts_keep_clear_of_every_bottom_bar_without_a_scroll_listener(): void
    {
        $root = dirname(__DIR__, 2);
        $toast = (string) file_get_contents($root.'/resources/admin/modules/toast.ts');

        // the save bars (settings pages and <x-admin.savebar>), the language editor's save row, the Appearance publish bar,
        // the view-only note, and any bar marked for it
        foreach (['.ad-savebar', '.ad-translate-save', '.ad-publish', '.avd-view-only-note', '[data-ad-bottom-bar]'] as $bar) {
            $this->assertStringContainsString($bar, $toast);
        }

        $this->assertStringContainsString("from '../lib/placement'", $toast);
        $this->assertStringNotContainsString("'scroll'", $toast, 'no scroll listener (the admin has none)');
        $this->assertStringContainsString("attributeFilter: ['hidden', 'class']", $toast, 'a bar that appears or hides moves the stack');
        $this->assertStringContainsString('unwatch()', $toast, 'nothing is watched while no toast shows');
    }

    private function nodeBinary(): ?string
    {
        if (! function_exists('exec')) {
            return null;
        }

        $finder = PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null';
        $paths = [];
        @exec($finder, $paths);
        $node = trim((string) ($paths[0] ?? ''));

        if ($node === '' || ! is_file($node)) {
            return null;
        }

        $version = [];
        @exec(escapeshellarg($node).' -p "process.versions.node" 2>&1', $version);
        $parts = array_map('intval', explode('.', trim((string) ($version[0] ?? '0'))));

        return ($parts[0] ?? 0) > 22 || (($parts[0] ?? 0) === 22 && ($parts[1] ?? 0) >= 18) ? $node : null;
    }
}
