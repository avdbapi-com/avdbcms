<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 1.2.3: the front's text logic runs on its results in Node (tests/js/front_logic.test.ts):
 * card numbers like the server cards (channel-19), calendar days in the visitor's zone (channel-30), safe search
 * highlights (watch-45), time codes inside the video's length (watch-22) and the share dialog's "Start at" (watch-11).
 * The source-text checks of FrontAssetsContractTest could pass while the logic was wrong.
 */
class FrontLogicNodeTest extends TestCase
{
    public function test_the_front_logic_passes_its_cases_in_node(): void
    {
        $node = $this->nodeBinary();

        if ($node === null) {
            $this->markTestSkipped('Node.js 22.18 or newer is needed to run tests/js/front_logic.test.ts.');
        }

        $root = dirname(__DIR__, 2);
        $output = [];
        exec(escapeshellarg($node).' '.escapeshellarg($root.'/tests/js/front_logic.test.ts').' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertMatchesRegularExpression('/front logic: \d+ passed, 0 failed/', implode("\n", $output));
    }

    public function test_the_components_use_the_tested_helpers(): void
    {
        $root = dirname(__DIR__, 2);

        $search = (string) file_get_contents($root.'/resources/js/components/Search/SearchBar.tsx');
        $this->assertStringContainsString('from "./highlight"', $search);
        $this->assertStringNotContainsString('function highlightTyped(', $search, 'a second copy of the helper');

        $comment = (string) file_get_contents($root.'/resources/js/components/Comments/ExpandComment.tsx');
        $this->assertStringContainsString('from "./timeCodes"', $comment);
        $this->assertStringContainsString('playableTimeCodes(html, window.AVD_WATCH', $comment);

        // auth-5: the entrances ask the tested reveal policy, and nothing else decides when they run.
        $anime = (string) file_get_contents($root.'/resources/js/motion/animeEnhancements.ts');
        $this->assertStringContainsString("from './revealPolicy'", $anime);
        $this->assertStringNotContainsString('const onScreen = (', $anime, 'a second copy of the helper');
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
