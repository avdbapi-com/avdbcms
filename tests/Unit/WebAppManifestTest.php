<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Round 3 fix pass R2R-05: the web app manifest (public/manifest.json, and its copy next to the project folder's
 * index.php) names its start page and icons relative to itself. A browser resolves them against the manifest's address:
 * at a domain's root exactly the addresses of before (/, /icons/...), for a site in a folder the folder's
 * (/site/, /site/icons/...: they were the domain's root, where another site or nothing answers).
 */
class WebAppManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function manifest(string $relative): array
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/'.$relative), true);
        self::assertIsArray($json, $relative);

        return $json;
    }

    /** A relative reference resolved against an address, as a browser does for these plain cases. */
    private static function resolve(string $base, string $reference): string
    {
        $directory = substr($base, 0, strrpos($base, '/') + 1);

        return $directory.preg_replace('#^\./#', '', $reference);
    }

    public function test_the_start_page_and_the_icons_are_the_sites_own_in_a_folder_and_as_before_at_the_root(): void
    {
        $manifest = self::manifest('public/manifest.json');
        $this->assertSame('./', $manifest['start_url']);
        $this->assertNotEmpty($manifest['icons']);

        $this->assertSame('https://example.com/', self::resolve('https://example.com/manifest.json', $manifest['start_url']));
        $this->assertSame('https://example.com/site/', self::resolve('https://example.com/site/manifest.json', $manifest['start_url']));

        foreach ($manifest['icons'] as $icon) {
            $this->assertStringStartsWith('icons/', $icon['src']);
            $this->assertFileExists(dirname(__DIR__, 2).'/public/'.$icon['src']);
            $this->assertSame('https://example.com/'.$icon['src'], self::resolve('https://example.com/manifest.json', $icon['src']));
            $this->assertSame('https://example.com/site/'.$icon['src'], self::resolve('https://example.com/site/manifest.json', $icon['src']));
        }
    }

    public function test_the_project_folders_copy_is_the_same(): void
    {
        $this->assertSame(self::manifest('public/manifest.json'), self::manifest('manifest.json'));
    }
}
