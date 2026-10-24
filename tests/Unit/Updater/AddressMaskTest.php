<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\Downloader;
use App\Support\Updater\Updater;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Addresses in error texts (F8 16; 1.2.1, B7 of the 1.2.0 tests): a signed download address must never reach the step
 * log, the page or the site log, but the text around it stays readable. 1.2.0 masked "#https?://\S+#", which also ate
 * the ")" after an address: "(see <address> for <address>]". The mask now ends where the text goes on.
 */
class AddressMaskTest extends TestCase
{
    private const SIGNED = 'https://release-assets.githubusercontent.com/github-production-release-asset/700000001/abc?sp=r&sv=2018-11-09&se=2026-10-23T14%3A39%3A00Z&sig=c2lnbmVkLWJ5LWdpdGh1Yi1mb3ItdGVzdHM9PQ';

    /** @return array<string, array{0: string, 1: string}> */
    public static function errors(): array
    {
        return [
            'curl 18: an address in brackets, then the signed one' => [
                'cURL error 18: end of response with 8388608 bytes missing (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '.self::SIGNED,
                'cURL error 18: end of response with 8388608 bytes missing (see <address>) for <address>',
            ],
            'curl 56 at the end' => ['cURL error 56: Recv failure: Connection was reset for '.self::SIGNED, 'cURL error 56: Recv failure: Connection was reset for <address>'],
            'a full stop after it' => ['Could not reach '.self::SIGNED.'. Try again.', 'Could not reach <address>. Try again.'],
            'square brackets and a comma' => ['[GET '.self::SIGNED.'], then [GET http://127.0.0.1:8198/x],', '[GET <address>], then [GET <address>],'],
            'a bracket inside the address stays masked' => ['at https://example.com/a)b?sig=SECRET1 end', 'at <address> end'],
            'quoted' => ['Redirect URI, "'.self::SIGNED.'", does not use https', 'Redirect URI, "<address>", does not use https'],
            'no address' => ['cURL error 28: Operation timed out after 25001 milliseconds', 'cURL error 28: Operation timed out after 25001 milliseconds'],
        ];
    }

    #[DataProvider('errors')]
    public function test_the_download_errors_mask_ends_where_the_address_ends(string $error, string $masked): void
    {
        $this->assertSame($masked, Downloader::mask($error));
        $this->assertStringNotContainsString('SECRET1', Downloader::mask($error));
        $this->assertStringNotContainsString('c2lnbmVk', Downloader::mask($error));
    }

    public function test_scrub_keeps_the_host_and_the_text_around_the_address(): void
    {
        $this->assertSame(
            'cURL error 18: end of response (see curl.haxx.se) for release-assets.githubusercontent.com',
            Updater::scrub('cURL error 18: end of response (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '.self::SIGNED),
        );
        $this->assertSame('Redirected to github.com. Stop.', Updater::scrub('Redirected to https://github.com/avdbapi-com/avdbcms/releases/download/v9.9.9/x.zip. Stop.'));
        $this->assertSame('token [hidden] (secret)', Updater::scrub('token '.str_repeat('ab', 32).' (secret)'));
        $this->assertStringNotContainsString('c2lnbmVk', Updater::scrub('at '.self::SIGNED.')'));
    }
}
