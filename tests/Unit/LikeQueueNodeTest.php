<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 1.2.3 round 2 (R2A-029): the Like / Dislike buttons never drop a click made while a request runs. Their click queue
 * (resources/js/components/Interactions/likeQueue.ts) runs in Node against a fake server that toggles like
 * Api\Private\InteractionController (tests/js/like_queue.test.ts): every click shows at once, one request at a time,
 * the server ends on the member's last choice with the same counts as the buttons, and a refusal shows what the server
 * has. The component must use that queue and nothing else to send.
 */
class LikeQueueNodeTest extends TestCase
{
    public function test_the_like_queue_passes_its_cases_in_node(): void
    {
        $node = $this->nodeBinary();

        if ($node === null) {
            $this->markTestSkipped('Node.js 22.18 or newer is needed to run tests/js/like_queue.test.ts.');
        }

        $root = dirname(__DIR__, 2);
        $output = [];
        exec(escapeshellarg($node).' '.escapeshellarg($root.'/tests/js/like_queue.test.ts').' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertMatchesRegularExpression('/like queue: \d+ passed, 0 failed/', implode("\n", $output));
    }

    public function test_the_buttons_send_through_the_tested_queue(): void
    {
        $component = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/components/Interactions/Interaction.tsx');

        $this->assertStringContainsString('from "./likeQueue"', $component);
        $this->assertStringContainsString('createLikeQueue(', $component);
        $this->assertStringContainsString('queue.current?.click(type)', $component);
        // the old rule: a click made while a request ran returned without a sign
        $this->assertDoesNotMatchRegularExpression('/if\s*\(\s*isPending\s*\)\s*\{?\s*return/', $component);
        $this->assertStringNotContainsString('const reducer = (', $component, 'a second copy of the rule');
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
