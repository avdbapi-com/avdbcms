<?php

namespace Tests\Unit\Updater;

use App\Support\AppVersion;
use App\Support\Updater\ReleaseCheck;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\TestCase;

/**
 * The version of the code and release tags (F8 3, 18): only plain "vX.Y.Z" tags count, numbers compare as numbers,
 * and the GitHub addresses are constants of the code.
 */
class AppVersionTest extends TestCase
{
    public function test_the_code_has_a_plain_version_and_fixed_github_addresses(): void
    {
        $this->assertTrue(AppVersion::isVersion(AppVersion::CURRENT));
        $this->assertSame('avdbapi-com/avdbcms', AppVersion::REPOSITORY);
        $this->assertSame('https://api.github.com', AppVersion::API_BASE);
        $this->assertSame('https://github.com', AppVersion::WEB_BASE);
        $this->assertSame('v'.AppVersion::CURRENT, AppVersion::tag(AppVersion::CURRENT));
        $this->assertSame('https://github.com/avdbapi-com/avdbcms/releases/download/v1.2.3/', AppVersion::downloadBase('1.2.3'));
        $this->assertSame('avdbcms-update-1.2.3.zip', AppVersion::updateAsset('1.2.3'));
        $this->assertStringEndsWith('#v1-2-3', AppVersion::changelogUrl('1.2.3'));

        foreach (AppVersion::DOWNLOAD_HOSTS as $prefix) {
            $this->assertStringStartsWith('https://', $prefix);
            $this->assertStringEndsWith('/', $prefix, 'a host prefix ends with "/", so "github.com.evil.example" is not allowed');
        }
    }

    public function test_numbers_compare_as_numbers(): void
    {
        $this->assertSame('1.10.0', AppVersion::fromTag('v1.10.0'));
        $this->assertSame('1.9.0', AppVersion::fromTag('V1.9.0'));
        $this->assertTrue(AppVersion::isNewer('1.10.0', '1.9.0'));
        $this->assertFalse(AppVersion::isNewer('1.9.0', '1.10.0'));
        $this->assertTrue(AppVersion::isNewer('2.0.0', '1.99.99'));
        $this->assertTrue(AppVersion::isNewer(UpdaterSandbox::nextVersion()), 'the next patch of CURRENT is newer');
    }

    public function test_the_same_version_is_up_to_date(): void
    {
        $this->assertFalse(AppVersion::isNewer(AppVersion::CURRENT));
        $this->fakeLatest(AppVersion::CURRENT);

        $answer = (new ReleaseCheck())->fetch();

        $this->assertSame('current', $answer['state']);
        $this->assertSame(AppVersion::CURRENT, $answer['release']['version']);
        $this->assertSame('Your site is up to date.', ReleaseCheck::message($answer));
    }

    public function test_a_newer_release_is_available(): void
    {
        $next = UpdaterSandbox::nextVersion();
        $this->fakeLatest($next);

        $answer = (new ReleaseCheck())->fetch();

        $this->assertSame('available', $answer['state']);
        $this->assertSame($next, $answer['release']['version']);
        $this->assertNull(ReleaseCheck::message($answer));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/repos/avdbapi-com/avdbcms/releases/latest'
            && $request->header('User-Agent')[0] === 'AVDBCMS-Updater/'.AppVersion::CURRENT
            && $request->header('X-GitHub-Api-Version')[0] === '2022-11-28'
            && ! $request->hasHeader('Authorization'));
    }

    #[DataProvider('ignoredTags')]
    public function test_tags_that_are_not_plain_versions_are_ignored(string $tag): void
    {
        $this->assertNull(AppVersion::fromTag($tag));

        $found = ReleaseCheck::release(['tag_name' => $tag, 'draft' => false, 'prerelease' => false, 'assets' => []]);

        $this->assertNull($found['release']);
        $this->assertNull($found['error'], 'an ignored tag is not an error either');
    }

    public static function ignoredTags(): array
    {
        return [
            'pre-release suffix' => ['v1.1.0-beta'],
            'two parts' => ['1.1'],
            'two parts with v' => ['v1.1'],
            'no v' => ['1.1.0'],
            'latest' => ['latest'],
            'empty' => [''],
            'leading zero' => ['v01.1.0'],
            'four parts' => ['v1.1.0.1'],
            'space' => [' v1.1.0'],
            'build metadata' => ['v1.1.0+5'],
            // "$" matches before a final line break; a version ends up in folder and file names.
            'line break at the end' => ["v1.1.0\n"],
        ];
    }

    public function test_a_version_with_a_line_break_is_no_version(): void
    {
        $this->assertFalse(AppVersion::isVersion("1.0.1\n"));
        $this->assertFalse(AppVersion::isNewer("9.0.0\n"));
        $this->assertFalse(AppVersion::isVersion("1.0.1\r\n"));
        $this->assertTrue(AppVersion::isVersion('1.0.1'));
    }

    private function fakeLatest(string $version): void
    {
        $base = AppVersion::downloadBase($version);
        $zip = AppVersion::updateAsset($version);

        Http::fake(['api.github.com/*' => Http::response([
            'tag_name' => 'v'.$version,
            'name' => 'AVDBCMS '.$version,
            'draft' => false,
            'prerelease' => false,
            'published_at' => '2026-10-24T10:00:00Z',
            'assets' => [
                ['name' => $zip, 'size' => 1000, 'state' => 'uploaded', 'browser_download_url' => $base.$zip],
                ['name' => $zip.'.sha256', 'size' => 92, 'state' => 'uploaded', 'browser_download_url' => $base.$zip.'.sha256'],
            ],
        ])]);
    }
}
