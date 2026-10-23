<?php

namespace Tests\Feature\Updater;

use App\Support\AppVersion;
use App\Support\Updater\Downloader;
use App\Support\Updater\ReleaseCheck;
use App\Support\Updater\UpdateException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Updater\Support\ReleaseServer;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * The download of the update zip and its checks (F8 6, 7, 18 "Download" and "Checksum"), against a GitHub that
 * redirects every asset to a signed address on its asset store, like the real one:
 *
 *  - the zip comes in Range slices appended to one .part file, each slice starting from the github.com address again;
 *    a dropped connection goes on where it stopped; a server that ignores Range starts the file again from byte 0;
 *  - a Content-Range that does not continue the file, or a total that is not the release's, stops the slice and
 *    writes nothing; a redirect away from GitHub (or to plain http) is never followed; an error status is a message;
 *  - a finished zip of the same release is used again (a week at most, of the right size);
 *  - a zip that does not match its .sha256 asset or the API's digest is deleted and nothing on the site changes; a
 *    checksum file for another zip is refused before the zip is fetched.
 */
class UpdateDownloadTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    /** An incompressible file in the release: the zip is between 2 and 3 MiB, three slices of 1 MiB. */
    private const BLOB = 2_700_000;

    /** The slice the tests ask for (Downloader::MIN_SLICE is smaller; any size from it up is taken as asked). */
    private const SLICE = 1048576;

    private const MISMATCH = 'The download does not match the release. Try again later.';

    private const INTERRUPTED = 'The download from GitHub was interrupted. Try again: it continues where it stopped.';

    private const DAMAGED = 'The download is damaged (its SHA-256 does not match). Nothing on your site was changed. Try again.';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->makeSite(self::BLOB);
        $this->assertGreaterThan(2 * self::SLICE, $this->github->size());
        $this->assertLessThan(3 * self::SLICE, $this->github->size());
    }

    protected function tearDown(): void
    {
        $this->removeSite();

        parent::tearDown();
    }

    public function test_the_zip_comes_in_range_slices_appended_to_one_file(): void
    {
        $release = (new ReleaseCheck())->get(10)['release'];
        $part = $this->box->path('download/'.$release['zip']['name'].'.part');
        $downloader = new Downloader();
        $total = $this->github->size();
        $sizes = [];

        for ($i = 0, $slice = ['complete' => false]; $i < 6 && ! $slice['complete']; $i++) {
            $slice = $downloader->slice($release, $part, self::SLICE, $total);
            clearstatcache(true, $part);
            $sizes[] = filesize($part);
        }

        $this->assertSame([self::SLICE, 2 * self::SLICE, $total], $sizes);
        $this->assertSame(['done' => $total, 'total' => $total, 'complete' => true], array_intersect_key($slice, array_flip(['done', 'total', 'complete'])));
        $this->assertSame($this->github->bytes, file_get_contents($part), 'the slices make the zip byte for byte');
        $this->assertSame(['bytes=0-1048575', 'bytes=1048576-2097151', 'bytes=2097152-3145727'], $this->github->zipRanges());

        // Each slice started from the github.com address (the signed address expires and is never kept), was
        // redirected, and only https on GitHub's hosts may be followed (5 hops at most, no referrer).
        $starts = $this->github->requestsTo($this->github->assetUrl($this->github->zipName()));
        $this->assertCount(3, $starts);
        $redirects = $starts[0]['options']['allow_redirects'];
        $this->assertSame([5, true, false, ['https']], [$redirects['max'], $redirects['strict'], $redirects['referer'], $redirects['protocols']]);
        $this->assertIsCallable($redirects['on_redirect']);
        $this->assertSame(['AVDBCMS-Updater/'.AppVersion::CURRENT], array_change_key_case($starts[0]['headers'])['user-agent']);

        // A file that is complete is not asked for again.
        $again = $downloader->slice($release, $part, self::SLICE, $total);
        $this->assertTrue($again['complete']);
        $this->assertCount(3, $this->github->zipRanges());
    }

    public function test_the_updater_downloads_and_checks_the_zip_in_its_steps(): void
    {
        $start = $this->updater()->start($this->admin);
        $this->assertTrue($start['ok'], (string) $start['message']);
        $this->assertSame(['downloading', 'step'], [$start['status'], $start['next']]);
        $this->assertSame(['done' => 0, 'total' => $this->github->size()], $start['progress']);
        $this->changePlan(['download' => ['done' => 0, 'total' => $this->github->size(), 'slice' => self::SLICE]]);

        $answer = $this->updater()->step($this->admin);

        // One request of the page: as many slices as fit in its time (the next one sized by the speed measured), each
        // asking for the bytes right after the last one.
        $this->assertTrue($answer['ok'], (string) $answer['message']);
        $this->assertSame(['verifying', 'step', 'Checking the download...'], [$answer['status'], $answer['next'], $answer['label']]);
        $ranges = $this->github->zipRanges();
        $this->assertGreaterThanOrEqual(2, count($ranges));
        $this->assertSame('bytes=0-1048575', $ranges[0]);
        $next = 0;

        foreach ($ranges as $range) {
            $this->assertMatchesRegularExpression('/^bytes='.$next.'-(\d+)$/', $range);
            preg_match('/-(\d+)$/', $range, $end);
            $next = (int) $end[1] + 1;
        }

        $this->assertGreaterThanOrEqual($this->github->size(), $next, 'the last slice reached the end');
        $this->assertCount(1, $this->github->requestsTo($this->github->signedUrl($this->github->zipName().'.sha256')), 'the .sha256 asset first');
        $this->assertSame(['done' => $this->github->size(), 'total' => $this->github->size()], array_intersect_key($this->storedPlan()['download'], ['done' => 1, 'total' => 1]));
        $this->assertSame($this->github->bytes, file_get_contents($this->zipFile()));
        $this->assertFileDoesNotExist($this->zipFile().'.part');

        $verified = $this->updater()->step($this->admin);

        $this->assertSame('unpacking', $verified['status'], (string) $verified['message']);
        $this->assertStringContainsString('SHA-256 matches the .sha256 file and the API digest', implode("\n", $this->updater()->logLines()));
        $this->assertSiteUnchanged('by the download');
    }

    public function test_a_dropped_connection_goes_on_where_it_stopped(): void
    {
        $this->github->dropRequests = [2];
        $this->updater()->start($this->admin);
        $this->changePlan(['download' => ['done' => 0, 'total' => $this->github->size(), 'slice' => self::SLICE]]);

        $dropped = $this->updater()->step($this->admin);

        $this->assertFalse($dropped['ok']);
        $this->assertTrue($dropped['retry']);
        $this->assertNull($dropped['next'], 'the page offers Continue');
        $this->assertSame(['downloading', self::INTERRUPTED], [$dropped['status'], $dropped['message']]);
        $plan = $this->storedPlan();
        $this->assertSame(self::SLICE, $plan['download']['done'], 'the slice that arrived counts');
        $this->assertSame(self::INTERRUPTED, $plan['last_error']);
        $this->assertSame(substr($this->github->bytes, 0, self::SLICE), file_get_contents($this->zipFile().'.part'));
        $this->assertSiteUnchanged();

        $continued = $this->updater()->step($this->admin);

        $this->assertTrue($continued['ok'], (string) $continued['message']);
        $this->assertSame('verifying', $continued['status']);
        $ranges = $this->github->zipRanges();
        $this->assertSame('bytes=0-1048575', $ranges[0]);
        $this->assertSame(['bytes=1048576-', 'bytes=1048576-'], [substr($ranges[1], 0, 14), substr($ranges[2], 0, 14)], 'asked again from where it stopped');
        $this->assertSame($this->github->bytes, file_get_contents($this->zipFile()));
        $this->assertArrayNotHasKey('last_error', $this->storedPlan());
    }

    public function test_a_slice_that_did_not_arrive_makes_the_next_one_smaller(): void
    {
        // A link too slow for the first slice (or one that drops it): asking for the same size again would never end.
        $this->github->dropRequests = [1];
        $this->updater()->start($this->admin);
        $this->assertSame(Downloader::FIRST_SLICE, $this->storedPlan()['download']['slice']);

        $dropped = $this->updater()->step($this->admin);

        $this->assertTrue($dropped['retry'], (string) $dropped['message']);
        $this->assertSame(Downloader::FIRST_SLICE / 4, $this->storedPlan()['download']['slice'], 'a quarter of the slice that did not arrive');

        $this->assertSame('verifying', $this->updater()->step($this->admin)['status']);
        $ranges = $this->github->zipRanges();
        $this->assertSame('bytes=0-'.(Downloader::FIRST_SLICE - 1), $ranges[0]);
        $this->assertSame('bytes=0-'.(Downloader::FIRST_SLICE / 4 - 1), $ranges[1], 'the next try asked for less');
        $this->assertSame($this->github->bytes, file_get_contents($this->zipFile()));

        // Down to the floor, never below it: 256 KB in the slice timeout still gets through at about 10 KB/s.
        $this->assertSame(256 * 1024, Downloader::MIN_SLICE);
        $this->assertSame(Downloader::MIN_SLICE, Downloader::smaller(Downloader::MIN_SLICE * 3));
        $this->assertSame(Downloader::MIN_SLICE, Downloader::smaller(1));
    }

    public function test_a_part_file_longer_than_the_release_is_thrown_away_before_anything_is_asked(): void
    {
        $release = (new ReleaseCheck())->get(10)['release'];
        $part = $this->box->path('download/'.$release['zip']['name'].'.part');
        File::ensureDirectoryExists(dirname($part));
        file_put_contents($part, $this->github->bytes.'more');

        $failure = $this->sliceFailure(fn () => (new Downloader())->slice($release, $part, self::SLICE, $this->github->size()));

        $this->assertSame(self::MISMATCH, $failure->getMessage());
        $this->assertSame(['download', true, 'the .part file is longer than the release'], [$failure->step, $failure->retry, $failure->detail]);
        $this->assertFileDoesNotExist($part, 'the next try starts from the first byte');
        $this->assertSame([], $this->github->zipRanges());
    }

    public function test_a_range_past_the_end_completes_a_whole_file_and_restarts_a_longer_one(): void
    {
        // The github.com fallback knows no size: the length comes from the asset store's 416 ("bytes */<total>").
        $release = (new ReleaseCheck())->get(10)['release'];
        $release['zip']['size'] = null;
        $part = $this->box->path('download/'.$release['zip']['name'].'.part');
        File::ensureDirectoryExists(dirname($part));
        file_put_contents($part, $this->github->bytes);

        $slice = (new Downloader())->slice($release, $part, self::SLICE, null);

        $this->assertSame(['done' => $this->github->size(), 'total' => $this->github->size(), 'complete' => true], array_intersect_key($slice, array_flip(['done', 'total', 'complete'])));
        $this->assertSame($this->github->bytes, file_get_contents($part), 'the whole file is kept');
        $this->assertSame(['bytes='.$this->github->size().'-'.($this->github->size() + self::SLICE - 1)], $this->github->zipRanges());

        // Longer than the zip on the server: not this file.
        file_put_contents($part, $this->github->bytes.'more');
        $failure = $this->sliceFailure(fn () => (new Downloader())->slice($release, $part, self::SLICE, null));

        $this->assertSame([self::MISMATCH, 'range not satisfiable'], [$failure->getMessage(), $failure->detail]);
        $this->assertTrue($failure->retry);
        $this->assertFileDoesNotExist($part);
    }

    public function test_a_server_that_ignores_range_starts_the_file_again_from_its_first_byte(): void
    {
        $release = (new ReleaseCheck())->get(10)['release'];
        $part = $this->box->path('download/'.$release['zip']['name'].'.part');
        File::ensureDirectoryExists(dirname($part));
        file_put_contents($part, str_repeat('x', self::SLICE));
        $this->github->ignoreRange = true;

        $slice = (new Downloader())->slice($release, $part, self::SLICE, $this->github->size());

        $this->assertTrue($slice['complete']);
        $this->assertSame($this->github->size(), $slice['done']);
        $this->assertSame($this->github->bytes, file_get_contents($part), 'written from byte 0, not appended');
        $this->assertSame(['bytes=1048576-2097151'], $this->github->zipRanges(), 'it asked to go on; the 200 restarted the file');
    }

    public function test_a_content_range_that_does_not_continue_the_file_appends_nothing(): void
    {
        $release = (new ReleaseCheck())->get(10)['release'];
        $part = $this->box->path('download/'.$release['zip']['name'].'.part');
        $downloader = new Downloader();
        $downloader->slice($release, $part, self::SLICE, $this->github->size());
        $this->github->gap = 512;

        $failure = $this->sliceFailure(fn () => $downloader->slice($release, $part, self::SLICE, $this->github->size()));

        $this->assertSame(self::MISMATCH, $failure->getMessage());
        $this->assertSame(['download', true, 'Content-Range does not continue the file'], [$failure->step, $failure->retry, $failure->detail]);
        $this->assertSame(substr($this->github->bytes, 0, self::SLICE), file_get_contents($part), 'nothing was appended');
    }

    public function test_a_gap_in_the_updaters_download_stops_it_and_nothing_changes(): void
    {
        $this->github->gap = 100;
        $this->updater()->start($this->admin);

        $answer = $this->updater()->step($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertTrue($answer['retry']);
        $this->assertSame(['downloading', self::MISMATCH], [$answer['status'], $answer['message']]);
        $this->assertFileDoesNotExist($this->zipFile().'.part', 'a slice that does not fit is not written');
        $this->assertStringContainsString('[Content-Range does not continue the file]', implode("\n", $this->updater()->logLines()));
        $this->assertSiteUnchanged();
    }

    public static function wrongTotals(): array
    {
        return [
            'Content-Range names another total' => ['rangeTotal', 7, false],
            'the release says it is smaller' => ['advertisedSize', -1, false],
            'the release says it is bigger' => ['advertisedSize', 5000, false],
            'a 200 of another size' => ['advertisedSize', 3, true],
        ];
    }

    #[DataProvider('wrongTotals')]
    public function test_a_total_that_is_not_the_releases_stops_the_download(string $switch, int $offset, bool $ignoreRange): void
    {
        $this->github->{$switch} = $this->github->size() + $offset;
        $this->github->ignoreRange = $ignoreRange;
        $this->updater()->start($this->admin);

        $answer = $this->updater()->step($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertSame(['downloading', self::MISMATCH], [$answer['status'], $answer['message']]);
        $this->assertMatchesRegularExpression('/\[size \d+ instead of \d+\]/', implode("\n", $this->updater()->logLines()));
        $this->assertFileDoesNotExist($this->zipFile().'.part');
        $this->assertFileDoesNotExist($this->zipFile());
        $this->assertSiteUnchanged();
    }

    public static function reusedZips(): array
    {
        return [
            'the same zip, a day old' => [86400, 0, true],
            'the same zip, older than a week' => [8 * 86400, 0, false],
            'a zip of another size' => [3600, -10, false],
        ];
    }

    #[DataProvider('reusedZips')]
    public function test_a_zip_of_an_earlier_try_is_used_again_only_when_young_and_of_the_releases_size(int $age, int $cut, bool $reused): void
    {
        File::ensureDirectoryExists($this->jobFolder());
        file_put_contents($this->zipFile(), $cut === 0 ? $this->github->bytes : substr($this->github->bytes, 0, $cut));
        touch($this->zipFile(), time() - $age);

        $start = $this->updater()->start($this->admin);

        $this->assertTrue($start['ok'], (string) $start['message']);
        $this->assertSame($reused ? 'verifying' : 'downloading', $start['status']);
        $this->assertSame($reused, str_contains(implode("\n", $this->updater()->logLines()), 'zip of an earlier try reused'));

        $unpacking = $this->stepUntil($this->updater(), fn (array $a): bool => $a['status'] === 'unpacking');

        $this->assertTrue($unpacking['ok']);
        $this->assertCount($reused ? 0 : 1, $this->github->zipRanges(), $reused ? 'no second download' : 'downloaded again');
        $this->assertSame($this->github->bytes, file_get_contents($this->zipFile()));
    }

    public static function foreignAssetRedirects(): array
    {
        return [
            'another host' => ['https://downloads.evil.example/avdbcms-update.zip', 'redirect outside GitHub'],
            'plain http' => ['http://release-assets.githubusercontent.com/github-production-release-asset/1/x', 'connection: '],
        ];
    }

    #[DataProvider('foreignAssetRedirects')]
    public function test_a_download_redirected_away_from_github_is_not_followed(string $location, string $detail): void
    {
        $this->github->assetLocation = $location;
        $this->updater()->start($this->admin);

        $answer = $this->updater()->step($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertSame(self::INTERRUPTED, $answer['message']);
        $this->assertSame([], $this->github->requestsTo(substr($location, 0, 24)), 'nothing is asked of that address');
        $this->assertStringContainsString('['.$detail, implode("\n", $this->updater()->logLines()));
        $this->assertFileDoesNotExist($this->zipFile().'.part');
        $this->assertSiteUnchanged();
    }

    public function test_an_error_of_the_asset_store_is_named_and_nothing_is_written(): void
    {
        $this->github->assetStatus = 500;
        $this->updater()->start($this->admin);

        $answer = $this->updater()->step($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertTrue($answer['retry']);
        $this->assertSame('GitHub answered with an error (500). Try again later.', $answer['message']);
        $this->assertFileDoesNotExist($this->zipFile().'.part');

        // GitHub is back: the next step downloads it.
        $this->github->assetStatus = null;
        $this->assertSame('verifying', $this->updater()->step($this->admin)['status']);
    }

    public static function damagedDownloads(): array
    {
        return [
            'a byte changed on the way (the .sha256 decides)' => ['flipByte', 'sha256 mismatch]'],
            'the API digest is another' => ['digest', 'sha256 mismatch (the API digest)]'],
        ];
    }

    #[DataProvider('damagedDownloads')]
    public function test_a_zip_that_does_not_match_its_checksum_is_deleted_and_nothing_changes(string $damage, string $detail): void
    {
        if ($damage === 'flipByte') {
            $this->github->flipByte = 4000;
            $this->github->digest = '';
        } else {
            $this->github->digest = 'sha256:'.str_repeat('ab', 32);
        }

        $this->updater()->start($this->admin);
        $this->assertSame('verifying', $this->updater()->step($this->admin)['status']);
        $this->assertFileExists($this->zipFile());

        $answer = $this->updater()->step($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertFalse($answer['retry'], 'the same zip would fail again');
        $this->assertSame(['failed', null, self::DAMAGED], [$answer['status'], $answer['next'], $answer['message']]);
        $this->assertFileDoesNotExist($this->zipFile(), 'the damaged zip is deleted');
        $this->assertSame(['update.log'], $this->jobFiles(), 'no staging, no zip: only the step log');
        $plan = $this->storedPlan();
        $this->assertSame(['verify', self::DAMAGED], [$plan['failure']['step'], $plan['failure']['reason']]);
        $this->assertStringContainsString($detail, implode("\n", $this->updater()->logLines()));
        $this->assertSiteUnchanged('by a damaged download');
        $this->assertSame(['update.started', 'update.failed'], array_column($this->auditRows(), 'action'));
        $this->assertSame('verify: '.self::DAMAGED, $this->auditRows()[1]['details']);

        // A new try downloads the zip again (a damaged one is never used again).
        $this->github->flipByte = null;
        $this->github->digest = null;
        Cache::flush();
        $retry = $this->updater()->start($this->admin);
        $this->assertSame('downloading', $retry['status']);
    }

    public static function checksumFiles(): array
    {
        return [
            'for another zip' => [fn (ReleaseServer $s): string => $s->hash().'  avdbcms-update-9.9.9.zip'."\n"],
            'an HTML page' => [fn (ReleaseServer $s): string => "<!DOCTYPE html>\n<html><body>Not Found</body></html>\n"],
            'two lines' => [fn (ReleaseServer $s): string => $s->hash().'  '.$s->zipName()."\n".$s->hash().'  '.$s->zipName()."\n"],
        ];
    }

    #[DataProvider('checksumFiles')]
    public function test_a_checksum_file_that_is_not_one_for_this_zip_stops_before_the_zip_is_fetched(\Closure $text): void
    {
        $this->github->sha256Text = $text($this->github);
        $this->updater()->start($this->admin);

        $answer = $this->updater()->step($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertTrue($answer['retry']);
        $this->assertSame('The release has no valid checksum file, so it cannot be checked. Try again later.', $answer['message']);
        $this->assertSame([], $this->github->zipRanges(), 'the zip was not fetched');
        $this->assertSiteUnchanged();
    }

    /** The UpdateException a call throws. */
    private function sliceFailure(callable $call): UpdateException
    {
        try {
            $call();
        } catch (UpdateException $e) {
            return $e;
        }

        $this->fail('The slice did not stop.');
    }
}
