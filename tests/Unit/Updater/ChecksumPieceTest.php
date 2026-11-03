<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\Checksum;
use RuntimeException;
use Tests\TestCase;

/**
 * RP4-01: the update's verify step hashes its zip one Checksum::PIECE_BYTES piece per call of Checksum::piece(), with
 * the progress (the serialized hash context, the bytes done, the file's size and mtime) handed back and forth through
 * plan.json, so the hash of a big zip on a slow disk can be spread over several requests instead of starting from
 * the first byte again every time a host's time limit kills the request.
 */
class ChecksumPieceTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = sys_get_temp_dir().'/avdbcms-checksum-piece-'.bin2hex(random_bytes(4));
        mkdir($this->folder, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->folder);
        parent::tearDown();
    }

    /** A file of $pieces full pieces plus one byte (so the last piece is a short one). */
    private function file(int $pieces, string $name = 'release.zip'): string
    {
        $path = $this->folder.'/'.$name;
        $handle = fopen($path, 'wb');
        $block = 'avdbcms-checksum-piece-test';

        for ($written = 0; $written < $pieces * Checksum::PIECE_BYTES + 1; $written += strlen($block)) {
            $block = hash('sha256', $block, true).hash('sha256', $block.'x', true);
            fwrite($handle, $block);
        }

        fclose($handle);
        // The file is longer than the exact count by at most one block; cut it to the exact size.
        $handle = fopen($path, 'r+b');
        ftruncate($handle, $pieces * Checksum::PIECE_BYTES + 1);
        fclose($handle);
        clearstatcache(true, $path);

        return $path;
    }

    /** What plan.json does with the progress between two requests. */
    private static function throughJson(array $progress): array
    {
        return (array) json_decode((string) json_encode($progress, JSON_UNESCAPED_SLASHES), true);
    }

    public function test_the_hash_is_read_one_piece_per_call_and_the_progress_survives_plan_json(): void
    {
        $file = $this->file(2);
        $size = 2 * Checksum::PIECE_BYTES + 1;
        $this->assertSame($size, (int) filesize($file));

        $first = Checksum::piece($file, null);
        $this->assertSame(Checksum::PIECE_BYTES, $first['done'], 'one piece per call');
        $this->assertSame($size, $first['size']);
        $this->assertSame((int) filemtime($file), $first['mtime']);
        $this->assertArrayNotHasKey('sha256', $first, 'not finished yet');
        $this->assertIsString($first['context'] ?? null, 'the hash context travels serialized');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $first['context'], 'as base64, safe in JSON');

        $second = Checksum::piece($file, self::throughJson($first));
        $this->assertSame(2 * Checksum::PIECE_BYTES, $second['done']);
        $this->assertArrayNotHasKey('sha256', $second);
        $this->assertIsString($second['context'] ?? null);

        $third = Checksum::piece($file, self::throughJson($second));
        $this->assertSame($size, $third['done'], 'the last byte is in');
        $this->assertArrayNotHasKey('context', $third, 'a finished hash carries no context');
        $this->assertSame(hash_file('sha256', $file), $third['sha256'] ?? null, 'the hash of the pieces is the hash of the file');
        $this->assertSame(Checksum::file($file), $third['sha256'], 'and the same as the one-request read');
    }

    public function test_a_file_of_exactly_one_piece_is_finished_by_the_first_call(): void
    {
        $file = $this->folder.'/one.zip';
        file_put_contents($file, str_repeat('p', Checksum::PIECE_BYTES));

        $progress = Checksum::piece($file, null);

        $this->assertSame(Checksum::PIECE_BYTES, $progress['done']);
        $this->assertSame(hash_file('sha256', $file), $progress['sha256'] ?? null);
        $this->assertArrayNotHasKey('context', $progress);
    }

    public function test_a_small_file_and_an_empty_file_are_finished_by_the_first_call(): void
    {
        $small = $this->folder.'/small.zip';
        file_put_contents($small, 'a few bytes');
        $this->assertSame(hash('sha256', 'a few bytes'), Checksum::piece($small, null)['sha256'] ?? null);

        $empty = $this->folder.'/empty.zip';
        file_put_contents($empty, '');
        $progress = Checksum::piece($empty, null);
        $this->assertSame(0, $progress['done']);
        $this->assertSame(hash('sha256', ''), $progress['sha256'] ?? null);
    }

    public function test_a_progress_of_another_file_starts_over_from_the_first_byte(): void
    {
        $file = $this->file(1);
        $first = Checksum::piece($file, null);
        $this->assertArrayNotHasKey('sha256', $first);

        // The file was downloaded again meanwhile (another mtime): the kept context is not for these bytes.
        $other = $first;
        $other['mtime'] = $first['mtime'] - 100;
        $again = Checksum::piece($file, $other);
        $this->assertSame(Checksum::PIECE_BYTES, $again['done'], 'from the first byte again');
        $this->assertArrayNotHasKey('sha256', $again);

        // Another size.
        $other = $first;
        $other['size'] = $first['size'] + 1;
        $this->assertSame(Checksum::PIECE_BYTES, Checksum::piece($file, $other)['done']);

        // More bytes done than the file has.
        $other = $first;
        $other['done'] = $first['size'] + 5;
        $this->assertSame(Checksum::PIECE_BYTES, Checksum::piece($file, $other)['done']);

        // And the real progress still finishes correctly.
        $this->assertSame(hash_file('sha256', $file), Checksum::piece($file, $first)['sha256'] ?? null);
    }

    public function test_a_context_that_cannot_be_read_starts_over_without_an_error(): void
    {
        $file = $this->file(1);
        $first = Checksum::piece($file, null);

        foreach (['', 'not base64!', base64_encode('garbage'), base64_encode(serialize(['x' => 1])), base64_encode(substr(base64_decode($first['context']), 0, 80)), 123, null] as $broken) {
            $progress = $first;
            $progress['context'] = $broken;
            $again = Checksum::piece($file, $progress);
            $this->assertSame(Checksum::PIECE_BYTES, $again['done'], 'started over for '.var_export($broken, true));
            $this->assertArrayNotHasKey('sha256', $again);
        }

        // A context of another algorithm is refused before it is even unserialized.
        $md5 = hash_init('md5');
        $progress = $first;
        $progress['context'] = base64_encode(serialize($md5));
        $this->assertSame(Checksum::PIECE_BYTES, Checksum::piece($file, $progress)['done']);
    }

    public function test_a_file_that_cannot_be_read_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        Checksum::piece($this->folder.'/missing.zip', null);
    }
}
