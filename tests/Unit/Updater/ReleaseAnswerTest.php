<?php

namespace Tests\Unit\Updater;

use App\Support\AppVersion;
use App\Support\Updater\ReleaseCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The API's release answer (F8 4, 18): only a published release with both update assets uploaded to this
 * repository's releases, at most 300 MB, is offered; every refusal has its own message.
 */
class ReleaseAnswerTest extends TestCase
{
    private const VERSION = '1.0.1';

    public function test_a_complete_release_is_offered(): void
    {
        $found = ReleaseCheck::release($this->release());

        $this->assertNull($found['error']);
        $release = $found['release'];
        $this->assertSame('1.0.1', $release['version']);
        $this->assertSame('v1.0.1', $release['tag']);
        $this->assertSame('AVDBCMS 1.0.1', $release['name']);
        $this->assertSame('2026-10-24T10:00:00Z', $release['published_at']);
        $this->assertSame('avdbcms-update-1.0.1.zip', $release['zip']['name']);
        $this->assertSame('https://github.com/avdbapi-com/avdbcms/releases/download/v1.0.1/avdbcms-update-1.0.1.zip', $release['zip']['url']);
        $this->assertSame(65000000, $release['zip']['size']);
        $this->assertSame(str_repeat('ab', 32), $release['zip']['digest']);
        $this->assertSame('https://github.com/avdbapi-com/avdbcms/releases/download/v1.0.1/avdbcms-update-1.0.1.zip.sha256', $release['sha256']['url']);
        $this->assertSame("Fixes.\n<b>x</b>", $release['body'], 'notes are kept as text (the page escapes them)');
    }

    #[DataProvider('ignoredReleases')]
    public function test_drafts_and_pre_releases_are_ignored(array $change): void
    {
        $found = ReleaseCheck::release(array_replace($this->release(), $change));

        $this->assertNull($found['release']);
        $this->assertNull($found['error']);
    }

    public static function ignoredReleases(): array
    {
        return ['draft' => [['draft' => true]], 'pre-release' => [['prerelease' => true]], 'pre-release tag' => [['tag_name' => 'v1.0.1-rc.1']]];
    }

    #[DataProvider('refusedReleases')]
    public function test_an_incomplete_or_foreign_release_is_refused_with_its_reason(callable $change, string $code, string $message): void
    {
        $json = $this->release();
        $change($json);
        $found = ReleaseCheck::release($json);

        $this->assertNull($found['release']);
        $this->assertSame($code, $found['error']);
        $this->assertSame($message, ReleaseCheck::message(['state' => 'error', 'error' => ['code' => $found['error'], 'version' => $found['version']]]));
    }

    public static function refusedReleases(): array
    {
        $notUploaded = 'AVDBCMS 1.0.1 is out, but its update package is not uploaded yet. Try again later.';
        $foreign = 'AVDBCMS 1.0.1 points to a download outside its GitHub page, so it is not offered.';

        return [
            'no update zip' => [fn (array &$j) => array_splice($j['assets'], 0, 1), 'missing_asset', $notUploaded],
            'no .sha256' => [fn (array &$j) => array_splice($j['assets'], 1, 1), 'missing_asset', $notUploaded],
            'zip still uploading' => [function (array &$j): void {
                $j['assets'][0]['state'] = 'starter';
            }, 'not_uploaded', $notUploaded],
            'zip on another host' => [function (array &$j): void {
                $j['assets'][0]['browser_download_url'] = 'https://evil.example/avdbcms-update-1.0.1.zip';
            }, 'foreign_host', $foreign],
            'zip of another repository' => [function (array &$j): void {
                $j['assets'][0]['browser_download_url'] = 'https://github.com/someone/avdbcms/releases/download/v1.0.1/avdbcms-update-1.0.1.zip';
            }, 'foreign_host', $foreign],
            'zip of another tag' => [function (array &$j): void {
                $j['assets'][0]['browser_download_url'] = 'https://github.com/avdbapi-com/avdbcms/releases/download/v1.0.0/avdbcms-update-1.0.1.zip';
            }, 'foreign_host', $foreign],
            'another asset on another host' => [function (array &$j): void {
                $j['assets'][2]['browser_download_url'] = 'http://github.com.evil.example/x.zip';
            }, 'foreign_host', $foreign],
            'above 300 MB' => [function (array &$j): void {
                $j['assets'][0]['size'] = 300 * 1024 * 1024 + 1;
            }, 'too_big', 'The update package of AVDBCMS 1.0.1 is larger than 300 MB, so it is not offered.'],
        ];
    }

    public function test_every_state_has_its_sentence(): void
    {
        $this->assertNull(ReleaseCheck::message(null));
        $this->assertNull(ReleaseCheck::message(['state' => 'available']));
        $this->assertSame('Your site is up to date.', ReleaseCheck::message(['state' => 'current']));
        $this->assertSame('No release found yet.', ReleaseCheck::message(['state' => 'none']));
        $this->assertSame('This server cannot check secure connections to GitHub. Ask your host to update the CA certificates.', ReleaseCheck::message(['state' => 'error', 'error' => ['code' => 'tls']]));
        $this->assertSame('GitHub answered with an error (502). Try again later.', ReleaseCheck::message(['state' => 'error', 'error' => ['code' => 'http', 'status' => 502]]));
        $this->assertSame('GitHub sent an answer the updater cannot read. Try again later.', ReleaseCheck::message(['state' => 'error', 'error' => ['code' => 'invalid']]));

        config(['app.timezone' => 'UTC']);
        $this->assertSame('GitHub has had too many requests from this server. Try again after 13:45.',
            ReleaseCheck::message(['state' => 'error', 'error' => ['code' => 'rate_limited', 'reset' => gmmktime(13, 45, 0, 10, 24, 2026)]]));
    }

    private function release(): array
    {
        $base = AppVersion::downloadBase(self::VERSION);

        return [
            'tag_name' => 'v1.0.1',
            'name' => 'AVDBCMS 1.0.1',
            'draft' => false,
            'prerelease' => false,
            'published_at' => '2026-10-24T10:00:00Z',
            'body' => "Fixes.\n<b>x</b>",
            'assets' => [
                ['name' => 'avdbcms-update-1.0.1.zip', 'size' => 65000000, 'state' => 'uploaded', 'digest' => 'sha256:'.str_repeat('AB', 32), 'browser_download_url' => $base.'avdbcms-update-1.0.1.zip'],
                ['name' => 'avdbcms-update-1.0.1.zip.sha256', 'size' => 92, 'state' => 'uploaded', 'browser_download_url' => $base.'avdbcms-update-1.0.1.zip.sha256'],
                ['name' => 'avdbcms-1.0.1.zip', 'size' => 290000000, 'state' => 'uploaded', 'browser_download_url' => $base.'avdbcms-1.0.1.zip'],
            ],
        ];
    }
}
