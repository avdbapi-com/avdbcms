<?php

namespace Tests\Feature\Updater;

use App\Http\Controllers\Admin\StatusProbeController;
use App\Support\AppVersion;
use App\Support\Updater\ReleaseCheck;
use App\Support\Updater\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Updater\Support\ReleaseServer;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * Is a newer AVDBCMS out? (F8 4, 18 "Check" and "Fallback"): one GET of the GitHub API's latest release, without a
 * token and with nothing about the site, TLS always checked; the answer kept 12 hours, a failure 1 hour, the last good
 * release kept through a failure; "Check now" asks past the kept answer, at most once a minute; a rate-limited API
 * falls back to the tag github.com/<repo>/releases/latest redirects to, and only to a plain tag of this repository.
 */
class UpdateCheckTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->makeSite();
    }

    protected function tearDown(): void
    {
        $this->removeSite();

        parent::tearDown();
    }

    public function test_a_newer_release_is_offered_with_its_update_assets_and_nothing_about_the_site_is_sent(): void
    {
        $answer = (new ReleaseCheck())->get(10);

        $this->assertSame('available', $answer['state']);
        $this->assertSame('api', $answer['source']);
        $this->assertNull($answer['error']);
        $this->assertNull(ReleaseCheck::message($answer), 'an available release needs no sentence: the page names it');
        $this->assertEqualsWithDelta(time(), $answer['checked_at'], 5);

        $zip = AppVersion::updateAsset($this->next);
        $release = $answer['release'];
        $this->assertSame([$this->next, 'v'.$this->next, 'AVDBCMS '.$this->next, '2026-10-24T10:00:00Z'], [$release['version'], $release['tag'], $release['name'], $release['published_at']]);
        $this->assertSame(['name' => $zip, 'url' => AppVersion::downloadBase($this->next).$zip, 'size' => $this->github->size(), 'digest' => $this->github->hash()], $release['zip']);
        $this->assertSame(['name' => $zip.'.sha256', 'url' => AppVersion::downloadBase($this->next).$zip.'.sha256'], $release['sha256']);

        // One GET of the fixed address, no token, no cookie, no referrer; certificates checked; 5 s to connect, 10 s in all.
        $this->assertCount(1, $this->github->requests);
        $request = $this->github->requests[0];
        $headers = array_change_key_case($request['headers']);
        $this->assertSame('GET', $request['method']);
        $this->assertSame(AppVersion::API_BASE.'/repos/'.AppVersion::REPOSITORY.'/releases/latest', $request['url']);
        $this->assertSame(['application/vnd.github+json'], $headers['accept']);
        $this->assertSame(['2022-11-28'], $headers['x-github-api-version']);
        $this->assertSame(['AVDBCMS-Updater/'.AppVersion::CURRENT], $headers['user-agent']);

        foreach (['authorization', 'cookie', 'referer', 'x-forwarded-for'] as $header) {
            $this->assertArrayNotHasKey($header, $headers, $header.' is never sent');
        }

        $this->assertSame([10, 5], [$request['options']['timeout'], $request['options']['connect_timeout']]);
        $this->assertNotFalse($request['options']['verify'] ?? true, 'TLS verification stays on');

        // What Home and the page render from: the same answer, without asking again.
        $this->assertSame($answer, (new ReleaseCheck())->cached());
        $this->assertCount(1, $this->github->requests);
    }

    public function test_the_home_probe_asks_with_three_seconds(): void
    {
        $probe = StatusProbeController::result('updates');

        $this->assertSame('warn', $probe['status']);
        $this->assertSame('AVDBCMS '.$this->next.' is available', $probe['label']);
        $this->assertCount(1, $this->github->requests);
        $this->assertSame([3, 3], [$this->github->requests[0]['options']['timeout'], $this->github->requests[0]['options']['connect_timeout']]);
    }

    public function test_the_same_or_an_older_release_means_the_site_is_up_to_date(): void
    {
        foreach ([AppVersion::CURRENT, self::olderVersion()] as $version) {
            Cache::flush();
            $this->github->api = [200, (new ReleaseServer($version, $this->box->path('release/'.$this->github->zipName())))->releaseJson()];

            $answer = (new ReleaseCheck())->get(10);

            $this->assertSame('current', $answer['state'], $version);
            $this->assertSame($version, $answer['release']['version']);
            $this->assertSame('Your site is up to date.', ReleaseCheck::message($answer));
        }

        // Nothing to install: the update is refused before anything is asked of the release.
        $refused = $this->updater()->start($this->admin);
        $this->assertFalse($refused['ok']);
        $this->assertSame('Your site is up to date.', $refused['message']);
        $this->assertSame([], $this->github->requestsTo(AppVersion::WEB_BASE.'/'));
        $this->assertNull($this->storedPlan());
    }

    public function test_a_kept_answer_is_used_for_twelve_hours(): void
    {
        $check = new ReleaseCheck();
        $check->get(10);

        $this->travel(43140)->seconds();
        $this->assertSame('available', $check->get(10)['state']);
        $this->assertCount(1, $this->github->requests, 'kept: GitHub is not asked again');

        $this->travel(120)->seconds();
        $this->assertNull($check->cached(), 'gone after 12 hours');
        $this->assertSame('available', $check->get(10)['state']);
        $this->assertCount(2, $this->github->requests);
    }

    public function test_no_release_yet_says_so_and_is_kept_twelve_hours(): void
    {
        $this->github->api = [404, ['message' => 'Not Found', 'documentation_url' => 'https://docs.github.com/rest/releases/releases#get-the-latest-release']];

        $answer = (new ReleaseCheck())->get(10);

        $this->assertSame('none', $answer['state']);
        $this->assertNull($answer['release']);
        $this->assertSame('No release found yet.', ReleaseCheck::message($answer));

        $this->travel(43140)->seconds();
        $this->assertSame('none', (new ReleaseCheck())->get(10)['state']);
        $this->assertCount(1, $this->github->requests);

        $refused = $this->updater()->start($this->admin);
        $this->assertFalse($refused['ok']);
        $this->assertSame('No release found yet.', $refused['message']);
        $this->assertNull($this->storedPlan());
    }

    public function test_a_rate_limited_api_says_when_to_try_again_and_the_last_good_release_is_kept(): void
    {
        $good = (new ReleaseCheck())->get(10);
        $reset = time() + 1800;
        $this->github->api = [403, ['message' => 'API rate limit exceeded for 203.0.113.7.'], [
            'X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) $reset, 'X-RateLimit-Resource' => 'core',
        ]];

        $answer = (new ReleaseCheck())->refresh()['answer'];

        $this->assertSame('error', $answer['state']);
        $this->assertSame(['code' => 'rate_limited', 'reset' => $reset], $answer['error']);
        $this->assertSame($good['release'], $answer['last_good'], 'the last good release stays known');
        $time = Carbon::createFromTimestamp($reset)->setTimezone(config('app.timezone'))->translatedFormat('H:i');
        $this->assertSame('GitHub has had too many requests from this server. Try again after '.$time.'.', ReleaseCheck::message($answer));
        // github.com was asked for the tag (it has no such limit), had none to give, so the error stands.
        $this->assertCount(1, $this->github->requestsTo(AppVersion::WEB_BASE.'/'.AppVersion::REPOSITORY.'/releases/latest'));

        // Kept one hour, release and all.
        $this->travel(3540)->seconds();
        $this->assertSame($answer, (new ReleaseCheck())->cached());
        $this->travel(120)->seconds();
        $this->assertNull((new ReleaseCheck())->cached());

        // Meanwhile the page shows the release it knows, without the install button, and says why.
        Cache::put(ReleaseCheck::CACHE_KEY, $answer, 3600);
        $this->app->instance(Updater::class, $this->updater());
        $this->actingAs($this->admin)
            ->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSee('AVDBCMS '.$this->next)
            ->assertSee('GitHub has had too many requests from this server. Try again after '.$time.'.')
            ->assertDontSee('data-ad-updates-open="ad-upd-install"', false);

        // Nothing can be installed from a check that failed: no plan, no download.
        $refused = $this->updater()->start($this->admin);
        $this->assertFalse($refused['ok']);
        $this->assertSame('GitHub has had too many requests from this server. Try again after '.$time.'.', $refused['message']);
        $this->assertNull($this->storedPlan());
        $this->assertSame([], $this->github->requestsTo(AppVersion::downloadBase($this->next)));
    }

    public function test_a_429_with_retry_after_is_a_rate_limit_too(): void
    {
        $this->github->api = [429, ['message' => 'You have exceeded a secondary rate limit.'], ['Retry-After' => '120']];

        $answer = (new ReleaseCheck())->get(10);

        $this->assertSame('rate_limited', $answer['error']['code']);
        $this->assertEqualsWithDelta(time() + 120, $answer['error']['reset'], 3);
        $this->assertNull($answer['last_good'], 'no earlier good answer to keep');
        $this->assertStringStartsWith('GitHub has had too many requests from this server. Try again after ', (string) ReleaseCheck::message($answer));
    }

    public function test_an_error_of_github_that_is_no_rate_limit_is_named_with_its_status(): void
    {
        foreach ([403, 500, 502] as $status) {
            Cache::flush();
            $before = count($this->github->requests);
            $this->github->api = [$status, ['message' => 'Something else']];

            $answer = (new ReleaseCheck())->get(10);

            $this->assertSame(['code' => 'http', 'status' => $status], $answer['error'], (string) $status);
            $this->assertSame('GitHub answered with an error ('.$status.'). Try again later.', ReleaseCheck::message($answer));
            $this->assertCount($before + 1, $this->github->requests, 'no fallback for an error that is not a rate limit');
        }
    }

    public static function connectionFailures(): array
    {
        return [
            'timeout' => ['cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received', 'timeout',
                'GitHub did not answer in time. Try again in a few minutes.'],
            'certificates' => ['cURL error 60: SSL certificate problem: unable to get local issuer certificate', 'tls',
                'This server cannot check secure connections to GitHub. Ask your host to update the CA certificates.'],
            'no connection' => ['cURL error 6: Could not resolve host: api.github.com', 'connection',
                'This server could not reach GitHub. Ask your host whether PHP may open https://api.github.com.'],
        ];
    }

    #[DataProvider('connectionFailures')]
    public function test_a_check_that_gets_no_answer_says_why_and_is_kept_one_hour(string $failure, string $code, string $message): void
    {
        $good = (new ReleaseCheck())->get(10);
        $this->github->apiFailure = $failure;
        Cache::forget(ReleaseCheck::CACHE_KEY);

        $answer = (new ReleaseCheck())->get(10);

        $this->assertSame('error', $answer['state']);
        $this->assertSame(['code' => $code], $answer['error']);
        $this->assertSame($message, ReleaseCheck::message($answer));
        $this->assertSame($good['release'], $answer['last_good']);

        $this->travel(3540)->seconds();
        (new ReleaseCheck())->get(10);
        $this->assertCount(2, $this->github->requests, 'a failure is kept one hour');

        $this->travel(120)->seconds();
        $this->github->apiFailure = null;
        $this->assertSame('available', (new ReleaseCheck())->get(10)['state'], 'then GitHub is asked again');
        $this->assertCount(3, $this->github->requests);
    }

    public function test_check_now_asks_past_the_kept_answer_at_most_once_a_minute(): void
    {
        $check = new ReleaseCheck();
        $check->get(10);

        $first = $check->refresh();

        $this->assertSame(0, $first['wait']);
        $this->assertSame('available', $first['answer']['state']);
        $this->assertCount(2, $this->github->requests, 'Check now does not use the kept answer');

        $second = $check->refresh();

        $this->assertGreaterThan(0, $second['wait']);
        $this->assertLessThanOrEqual(60, $second['wait']);
        $this->assertSame($first['answer'], $second['answer'], 'too soon: the kept answer');
        $this->assertCount(2, $this->github->requests, 'too soon: GitHub is not asked');

        $this->travel(61)->seconds();
        $this->assertSame(0, $check->refresh()['wait']);
        $this->assertCount(3, $this->github->requests);
    }

    public function test_check_now_on_the_page_writes_one_admin_log_row_per_question_to_github(): void
    {
        $this->app->instance(Updater::class, $this->updater());

        $this->actingAs($this->admin)->postJson(route('admin.updates.check'))->assertOk()->assertJson(['ok' => true, 'state' => 'available']);
        $this->actingAs($this->admin)->postJson(route('admin.updates.check'))->assertOk()->assertJsonPath('ok', false);

        $this->assertCount(1, $this->github->requests);
        $rows = $this->auditRows();
        $this->assertSame(['update.check'], array_column($rows, 'action'), 'one check, one row: '.json_encode($rows));
        $this->assertSame((int) $this->admin->getKey(), $rows[0]['user_id']);
        $this->assertStringStartsWith('available '.$this->next, (string) $rows[0]['details']);

        // A minute later GitHub is asked again: one more row.
        $this->travel(61)->seconds();
        $this->actingAs($this->admin)->postJson(route('admin.updates.check'))->assertOk()->assertJsonPath('ok', true);
        $this->assertCount(2, $this->github->requests);
        $this->assertSame(['update.check', 'update.check'], array_column($this->auditRows(), 'action'));
    }

    public function test_a_rate_limited_api_falls_back_to_the_tag_github_redirects_to(): void
    {
        $this->github->api = [403, ['message' => 'API rate limit exceeded'], ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (time() + 900)]];
        $this->github->webLocation = AppVersion::WEB_BASE.'/'.AppVersion::REPOSITORY.'/releases/tag/v'.$this->next;

        $answer = (new ReleaseCheck())->get(10);

        $this->assertSame('available', $answer['state']);
        $this->assertSame('web', $answer['source']);
        $this->assertNull($answer['error']);
        $this->assertSame(ReleaseCheck::fromTag('v'.$this->next), $answer['release'], 'asset addresses from the tag, no size, no digest');
        $this->assertNull($answer['release']['zip']['digest']);

        // github.com was asked once, its redirect read but not followed.
        $web = $this->github->requestsTo(AppVersion::WEB_BASE.'/'.AppVersion::REPOSITORY.'/releases/');
        $this->assertCount(1, $web);
        $this->assertSame(AppVersion::WEB_BASE.'/'.AppVersion::REPOSITORY.'/releases/latest', $web[0]['url']);
        $this->assertFalse($web[0]['options']['allow_redirects']);
        $this->assertSame(['AVDBCMS-Updater/'.AppVersion::CURRENT], array_change_key_case($web[0]['headers'])['user-agent']);

        // An update from that answer: the .sha256 asset alone checks the download.
        $start = $this->updater()->start($this->admin);
        $this->assertTrue($start['ok'], (string) $start['message']);
        $unpacking = $this->stepUntil($this->updater(), fn (array $a): bool => $a['status'] === 'unpacking');
        $this->assertTrue($unpacking['ok']);
        $log = implode("\n", $this->updater()->logLines());
        $this->assertStringContainsString('checked through github.com', $log);
        $this->assertStringContainsString('SHA-256 matches the .sha256 file', $log);
        $this->assertStringNotContainsString('API digest', $log);
        $this->assertSame($this->github->hash(), hash_file('sha256', $this->zipFile()));
    }

    public static function foreignRedirects(): array
    {
        $tag = 'v'.UpdaterSandbox::nextVersion();

        return [
            'another repository' => [AppVersion::WEB_BASE.'/someone-else/avdbcms/releases/tag/'.$tag],
            'another host' => ['https://github.com.evil.example/'.AppVersion::REPOSITORY.'/releases/tag/'.$tag],
            'plain http' => ['http://github.com/'.AppVersion::REPOSITORY.'/releases/tag/'.$tag],
            'a pre-release' => [AppVersion::WEB_BASE.'/'.AppVersion::REPOSITORY.'/releases/tag/'.$tag.'-beta.1'],
            'not a tag' => [AppVersion::WEB_BASE.'/'.AppVersion::REPOSITORY.'/releases'],
            'a path trick' => [AppVersion::WEB_BASE.'/'.AppVersion::REPOSITORY.'/releases/tag/'.$tag.'/../../../../someone-else/x'],
        ];
    }

    #[DataProvider('foreignRedirects')]
    public function test_the_fallback_takes_only_a_plain_tag_of_this_repository(string $location): void
    {
        $this->github->api = [403, ['message' => 'API rate limit exceeded'], ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (time() + 900)]];
        $this->github->webLocation = $location;

        $answer = (new ReleaseCheck())->get(10);

        $this->assertSame('error', $answer['state']);
        $this->assertSame('rate_limited', $answer['error']['code']);
        $this->assertNull($answer['release']);
        $this->assertSame([], array_filter($this->github->requests, fn (array $r): bool => ! str_starts_with($r['url'], AppVersion::API_BASE.'/')
            && $r['url'] !== AppVersion::WEB_BASE.'/'.AppVersion::REPOSITORY.'/releases/latest'), 'the redirect itself is never followed');
    }

    /** A version older than CURRENT (whatever CURRENT is). */
    private static function olderVersion(): string
    {
        [$major, $minor, $patch] = array_map('intval', explode('.', AppVersion::CURRENT));

        return match (true) {
            $patch > 0 => $major.'.'.$minor.'.'.($patch - 1),
            $minor > 0 => $major.'.'.($minor - 1).'.99',
            default => ($major - 1).'.99.99',
        };
    }
}
