<?php

namespace Tests\Unit;

use Illuminate\Translation\MessageSelector;
use PHPUnit\Framework\TestCase;

/**
 * AVDBCMS 1.2.3: the admin's scripts count with the same forms as the server. The save bar's
 * "3 unsaved changes", the Appearance pages' "2 changes not published" and "1 colour style" come from the translated
 * line of a "one|other" key, chosen by resources/admin/lib/plural.ts the way trans_choice() chooses on the server. This
 * runs the chooser in Node against PHP's MessageSelector for every plural line of every language file and every count
 * from 0 to 120.
 */
class AdminPluralTest extends TestCase
{
    public function test_the_built_in_cases_pass(): void
    {
        [$code, $output] = $this->node([]);

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/plural: \d+ passed, 0 failed/', $output);
    }

    public function test_the_scripts_choose_what_the_server_chooses_in_every_language(): void
    {
        $root = dirname(__DIR__, 2);
        $selector = new MessageSelector();
        $cases = [];

        foreach (glob($root.'/lang/*.json') ?: [] as $file) {
            $locale = basename($file, '.json');
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            foreach ($data as $key => $line) {
                if (! is_string($line) || ! str_contains((string) $key, '|') || ! str_contains($line, '|')) {
                    continue;
                }

                for ($n = 0; $n <= 120; $n++) {
                    $cases[] = ['locale' => $locale, 'line' => $line, 'n' => $n, 'expected' => $selector->choose($line, $n, $locale)];
                }
            }
        }

        $this->assertGreaterThan(10000, count($cases), 'every language file holds plural lines');

        $dir = $root.'/storage/framework/testing';
        is_dir($dir) || mkdir($dir, 0775, true);
        $path = $dir.'/plural-cases-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, json_encode($cases, JSON_UNESCAPED_UNICODE));

        try {
            [$code, $output] = $this->node([$path]);
        } finally {
            @unlink($path);
        }

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/plural: '.count($cases).' passed, 0 failed/', $output);
    }

    /** @return array{0: int, 1: string} */
    private function node(array $arguments): array
    {
        $node = $this->nodeBinary();

        if ($node === null) {
            $this->markTestSkipped('Node.js 22.18 or newer is needed to run tests/js/plural.test.ts.');
        }

        $command = escapeshellarg($node).' '.escapeshellarg(dirname(__DIR__, 2).'/tests/js/plural.test.ts');
        foreach ($arguments as $argument) {
            $command .= ' '.escapeshellarg($argument);
        }

        $output = [];
        exec($command.' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
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
