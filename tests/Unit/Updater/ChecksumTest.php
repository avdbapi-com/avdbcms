<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\Checksum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\TestCase;

/** The .sha256 asset and the API digest (F8 7, 18): one sha256sum line for the update zip, nothing else. */
class ChecksumTest extends TestCase
{
    private const NAME = 'avdbcms-update-1.0.1.zip';

    public function test_a_sha256sum_line_gives_the_hash_in_lower_case(): void
    {
        $hex = hash('sha256', 'zip');

        $this->assertSame($hex, Checksum::parse($hex.'  '.self::NAME."\n", self::NAME), 'text mode, two spaces');
        $this->assertSame($hex, Checksum::parse($hex.' *'.self::NAME."\n", self::NAME), 'binary mode, space and star');
        $this->assertSame($hex, Checksum::parse($hex."\n", self::NAME), 'the bare hash');
        $this->assertSame($hex, Checksum::parse($hex.'  '.self::NAME, self::NAME), 'no final line break');
        $this->assertSame($hex, Checksum::parse($hex.'  '.self::NAME."\r\n", self::NAME), 'Windows line break');
        $this->assertSame($hex, Checksum::parse(strtoupper($hex).'  '.self::NAME."\n", self::NAME), 'upper-case hex');
    }

    #[DataProvider('invalidFiles')]
    public function test_anything_else_is_refused(string $text): void
    {
        $this->assertNull(Checksum::parse($text, self::NAME));
    }

    public static function invalidFiles(): array
    {
        $hex = hash('sha256', 'zip');

        return [
            'wrong file name' => [$hex.'  avdbcms-1.0.1.zip'."\n"],
            'another version' => [$hex.'  avdbcms-update-1.0.2.zip'."\n"],
            'extra line' => [$hex.'  '.self::NAME."\n".$hex.'  other.zip'."\n"],
            'empty second line' => [$hex.'  '.self::NAME."\n\n"],
            'not hex' => [str_replace(substr($hex, 0, 1), 'g', $hex).'  '.self::NAME."\n"],
            '63 digits' => [substr($hex, 1).'  '.self::NAME."\n"],
            '65 digits' => [$hex.'0  '.self::NAME."\n"],
            'one space' => [$hex.' '.self::NAME."\n"],
            'trailing space' => [$hex.'  '.self::NAME." \n"],
            'empty' => [''],
            'over 1 KB' => [$hex.'  '.self::NAME.str_repeat(' ', 1024)."\n"],
            'an HTML error page' => ["<!DOCTYPE html><html><body>Not Found</body></html>\n"],
        ];
    }

    public function test_the_api_digest_is_read_only_as_sha256(): void
    {
        $hex = hash('sha256', 'zip');

        $this->assertSame($hex, Checksum::fromDigest('sha256:'.$hex));
        $this->assertSame($hex, Checksum::fromDigest('sha256:'.strtoupper($hex)));
        $this->assertNull(Checksum::fromDigest('md5:'.md5('zip')));
        $this->assertNull(Checksum::fromDigest($hex));
        $this->assertNull(Checksum::fromDigest(null));
        $this->assertNull(Checksum::fromDigest(['sha256:'.$hex]));
    }

    public function test_a_file_is_hashed_in_pieces_and_compared_in_constant_time(): void
    {
        $box = new UpdaterSandbox();

        try {
            $content = random_bytes(3 * 1024 * 1024 + 17);
            $box->write(['big.bin' => $content]);
            $hash = Checksum::file($box->root.'/big.bin');

            $this->assertSame(hash('sha256', $content), $hash);
            $this->assertTrue(Checksum::matches(strtoupper($hash), $hash));
            $this->assertFalse(Checksum::matches(hash('sha256', 'other'), $hash));
            $this->assertFalse(Checksum::matches('', $hash), 'an empty expected hash never matches');
            $this->expectException(\RuntimeException::class);
            Checksum::file($box->root.'/missing.bin');
        } finally {
            $box->delete();
        }
    }
}
