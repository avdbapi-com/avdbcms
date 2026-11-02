<?php

namespace Tests\Unit;

use App\Support\Upload18\Upload18Item;
use App\Support\Upload18\UploadClaims;
use PHPUnit\Framework\TestCase;

/**
 * How the site reads one Upload18 video of the account list and when it is not the file a member's browser
 * sent: a duplicate, another vid, another size, or (once Upload18 knows it) another length.
 */
class Upload18ItemTest extends TestCase
{
    public function test_items_are_read_whatever_the_number_types(): void
    {
        $item = Upload18Item::fromArray(['id' => 1107533, 'vid' => 'abcDEF_12', 'zt' => 2, 'size_bytes' => '398025770', 'duration' => '1:58:09', 'duration_seconds' => '7089.86']);

        $this->assertSame('1107533', $item->did);
        $this->assertTrue($item->ready());
        $this->assertSame(398025770, $item->sizeBytes);
        $this->assertEqualsWithDelta(7089.86, $item->durationSeconds, 0.001);
        $this->assertSame(7090, $item->durationForVideo());
        $this->assertFalse($item->duplicate);

        $processing = Upload18Item::fromArray(['id' => '7', 'vid' => '', 'zt' => '1', 'size' => '3.16 MB', 'duration' => '00:00']);
        $this->assertFalse($processing->ready());
        $this->assertNull($processing->sizeBytes, 'a size text is not a byte count');
        $this->assertNull($processing->durationSeconds);

        $this->assertNull(Upload18Item::fromArray(['vid' => 'x']));
        $this->assertNull(Upload18Item::fromArray('nope'));
        $this->assertSame('3312563', (string) Upload18Item::fromArray(['id' => 1, 'filesize' => 3312563])->sizeBytes);

        foreach ([1, '1', true, 'true', ' TRUE '] as $yes) {
            $this->assertTrue(Upload18Item::fromArray(['id' => 1, 'duplicate' => $yes])->duplicate);
        }

        foreach ([0, '0', false, null, 'no', 2] as $no) {
            $this->assertFalse(Upload18Item::fromArray(['id' => 1, 'duplicate' => $no])->duplicate);
        }
    }

    public function test_an_item_that_is_not_the_browsers_file_is_named(): void
    {
        $item = Upload18Item::fromArray(['id' => 9, 'vid' => 'vidX', 'zt' => '2', 'size_bytes' => 3312563, 'duration_seconds' => 12.27]);

        $this->assertNull(UploadClaims::mismatch($item, 'vidX', 3312563, 12.27));
        $this->assertNull(UploadClaims::mismatch($item, null, null, null), 'nothing known: nothing to refuse');
        $this->assertNull(UploadClaims::mismatch($item, 'vidX', 3312563, 13.9), 'within 2 seconds');
        $this->assertSame('mismatch', UploadClaims::mismatch($item, 'vidX', 3812493, 13.97));
        $this->assertSame('mismatch', UploadClaims::mismatch($item, 'vidX', 3312563, 60.0));
        $this->assertSame('vid_mismatch', UploadClaims::mismatch($item, 'vidY', 3312563, 12.27));

        $duplicate = Upload18Item::fromArray(['id' => 9, 'vid' => 'vidX', 'zt' => '2', 'duplicate' => 1]);
        $this->assertSame('duplicate', UploadClaims::mismatch($duplicate, 'vidX', 1, 1.0));

        // A long film: 1 % of two hours is allowed between the file's own length and Upload18's.
        $film = Upload18Item::fromArray(['id' => 10, 'vid' => 'vidF', 'zt' => '2', 'duration_seconds' => 7089.0]);
        $this->assertNull(UploadClaims::mismatch($film, 'vidF', null, 7089.86));
        $this->assertNull(UploadClaims::mismatch($film, 'vidF', null, 7140.0));
        $this->assertSame('mismatch', UploadClaims::mismatch($film, 'vidF', null, 7400.0));

        // While Upload18 still converts, its length is not compared.
        $converting = Upload18Item::fromArray(['id' => 11, 'vid' => 'vidC', 'zt' => '1', 'duration_seconds' => 3.0]);
        $this->assertNull(UploadClaims::mismatch($converting, 'vidC', null, 60.0));
    }
}
