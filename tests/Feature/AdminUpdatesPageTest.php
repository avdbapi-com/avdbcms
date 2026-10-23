<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\StatusProbeController;
use App\Http\Controllers\Admin\UpdatesController;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\AdminNav;
use App\Support\AppVersion;
use App\Support\DatabaseBackup;
use App\Support\Updater\ReleaseCheck;
use App\Support\Updater\UpdatePaths;
use App\Support\Updater\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Tools > Updates (F8, the admin web layer): the page and its JSON, who reaches what (every admin; installing also needs
 * the operational tools), the password asked again with its ten-minute block, CSRF, the update cookie, Home's callout
 * from the kept check only, the `updates` status probe, the Health check row and the old dashboard's link. The updater
 * works on a temporary site root and GitHub is faked: nothing here touches the copy's own files or the network.
 */
class AdminUpdatesPageTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    /** Tools > Backups as these tests want it: no backup from the last 24 hours unless a test puts one there. */
    private DatabaseBackup $database;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2', 'admin.operational_tools_enabled' => true]);
        Cache::flush();
        Http::preventStrayRequests();

        $this->root = storage_path('framework/testing/updates-page-'.Str::lower(Str::random(10)));
        File::ensureDirectoryExists($this->root.'/storage/app', 0755);

        $this->database = new class extends DatabaseBackup
        {
            public ?array $recentBackup = null;

            public function recent(int $hours = 24): ?array
            {
                return $this->recentBackup;
            }

            public function size(): ?int
            {
                return 1048576;
            }
        };

        $this->app->instance(DatabaseBackup::class, $this->database);
        $this->app->instance(Updater::class, new Updater(new UpdatePaths($this->root, $this->root.'/storage'), new ReleaseCheck(), $this->database));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_the_page_renders_in_the_new_shell_and_asks_github_only_after_it_appears(): void
    {
        Http::fake();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSee('class="ad-shell"', false)
            ->assertSee('<h1>Updates</h1>', false)
            ->assertSee('See if a new AVDBCMS version is out and install it with one click.')
            ->assertSee('Check now')
            ->assertSee('AVDBCMS '.AppVersion::CURRENT)
            ->assertSee('data-ad-defer="updates-release"', false)
            ->assertSee('data-ad-defer-probes="'.route('admin.updates.status', ['check' => 1]).'"', false)
            ->assertSee('Asking GitHub for the newest version...')
            ->assertSee('id="ad-updates-boot"', false);

        Http::assertNothingSent();

        // An administrator on the old admin gets the same page: it exists only in the new admin.
        config(['admin.ui' => 'v1']);
        $this->actingAs($admin)
            ->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSee('class="ad-shell"', false)
            ->assertDontSee('avd-back-sidebar', false);
    }

    public function test_the_page_sits_right_after_health_check_and_installing_needs_the_operational_tools(): void
    {
        $pages = array_keys((array) config('admin_nav.sections.tools.pages'));
        $this->assertSame(array_search('tools.health', $pages, true) + 1, array_search('tools.updates', $pages, true));

        $page = AdminNav::page('tools.updates');
        $this->assertSame(['Updates', '/admin/updates', 'basic', true], [$page['label'], $page['url'], $page['level'], $page['sidebar']]);
        $this->assertSame('See if a new AVDBCMS version is out and install it with one click.', $page['description']);

        foreach (['index', 'status', 'check', 'start', 'step', 'swap', 'finish', 'cancel', 'undo', 'backups.delete'] as $name) {
            $route = Route::getRoutes()->getByName('admin.updates.'.$name);
            $this->assertNotNull($route, $name);
            $this->assertSame('tools.updates', config('admin_nav.routes')['admin.updates.'.$name] ?? null, $name);
            $this->assertContains('admin', $route->gatherMiddleware(), $name);

            if (in_array($name, ['index', 'status', 'check'], true)) {
                $this->assertNotContains('admin.ops', $route->gatherMiddleware(), $name);
            } else {
                $this->assertContains('admin.ops', $route->gatherMiddleware(), $name);
            }

            $this->assertSame(in_array($name, ['index', 'status'], true) ? ['GET'] : ['POST'], array_values(array_diff($route->methods(), ['HEAD'])), $name);
        }
    }

    public function test_guests_and_members_get_404_everywhere(): void
    {
        $member = User::factory()->create();

        foreach ($this->addresses() as [$method, $url]) {
            $this->json($method, $url)->assertNotFound();
            $this->actingAs($member)->json($method, $url)->assertNotFound();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_with_the_operational_tools_off_nothing_installs_and_the_page_shows_the_manual_way(): void
    {
        config(['admin.operational_tools_enabled' => false]);
        $this->installManifest();
        $this->keepRelease();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSee('One-click updates cannot run on this site')
            ->assertSee('Operational tools are turned off on this site, so updates cannot be installed from here.')
            ->assertSee('How to update by hand')
            ->assertSee('href="'.AppVersion::HELP_URL.'"', false)
            ->assertSee('AVDBCMS '.self::next().' is available')
            ->assertDontSee('data-ad-updates-open="ad-upd-install"', false);

        foreach ($this->addresses() as [$method, $url, $installs]) {
            if ($installs) {
                $this->actingAs($admin)->json($method, $url, ['password' => 'password'])->assertNotFound();
            }
        }

        $this->actingAs($admin)->getJson(route('admin.updates.status'))->assertOk()->assertJsonPath('ok', true);
        $this->assertFalse(is_file($this->root.'/storage/app/updates/plan.json'));
    }

    public function test_the_first_check_runs_once_then_the_page_offers_the_update(): void
    {
        $this->fakeGitHub();
        $this->installManifest();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson(route('admin.updates.status', ['check' => 1]))
            ->assertOk()
            ->assertJsonPath('check.state', 'available')
            ->assertJsonPath('check.version', self::next())
            ->assertJsonPath('plan', null);

        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === AppVersion::API_BASE.'/repos/'.AppVersion::REPOSITORY.'/releases/latest'
            && $request->hasHeader('User-Agent', 'AVDBCMS-Updater/'.AppVersion::CURRENT)
            && ! $request->hasHeader('Authorization'));

        $html = $this->actingAs($admin)->get(route('admin.updates.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-ad-defer-probes', $html);
        $this->assertStringContainsString('Update available', $html);
        $this->assertStringContainsString('data-ad-updates-open="ad-upd-install"', $html);
        $this->assertStringContainsString('Update to '.self::next(), $html);
        $this->assertStringContainsString('Update to AVDBCMS '.self::next().'?', $html);
        $this->assertStringContainsString('AVDBCMS downloads the update from GitHub (68.0 MB) and checks that it arrived complete.', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString('href="'.AppVersion::changelogUrl(self::next()).'"', $html);
        $this->assertStringContainsString('href="'.AppVersion::WEB_BASE.'/'.AppVersion::REPOSITORY.'/releases/tag/v'.self::next().'"', $html);
        // Nothing in Tools > Backups from the last day: the update makes its own database backup, no "skip" offered.
        $this->assertStringNotContainsString('Skip the database backup', $html);

        // Kept for 12 hours: asking again sends nothing.
        $this->actingAs($admin)->getJson(route('admin.updates.status', ['check' => 1]))->assertJsonPath('check.state', 'available');
        Http::assertSentCount(1);
    }

    public function test_github_notes_are_shown_as_plain_text(): void
    {
        $this->installManifest();
        $this->keepRelease(['body' => "<script>alert(1)</script>\n<b>Fixed</b> the player"]);

        $this->actingAs($this->admin())
            ->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSee('Release notes')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<b>Fixed</b>', false);
    }

    public function test_a_failed_check_says_why_and_offers_no_install(): void
    {
        $this->fakeGitHub(403, ['message' => 'API rate limit exceeded'], ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (time() + 1800)]);
        $this->installManifest();
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(route('admin.updates.status', ['check' => 1]))->assertOk()->assertJsonPath('check.state', 'error');

        $this->actingAs($admin)
            ->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSee('GitHub has had too many requests from this server. Try again after')
            ->assertDontSee('data-ad-updates-open="ad-upd-install"', false);
    }

    public function test_check_now_asks_github_at_most_once_a_minute_and_is_audited(): void
    {
        $this->fakeGitHub();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('admin.updates.check'))
            ->assertOk()
            ->assertJson(['ok' => true, 'state' => 'available', 'version' => self::next(), 'message' => 'AVDBCMS '.self::next().' is available']);

        $this->assertTrue(DB::table('admin_audit_logs')->where('action', 'update.check')->where('user_id', $admin->id)->exists());

        $again = $this->actingAs($admin)->postJson(route('admin.updates.check'))->assertOk()->assertJsonPath('ok', false)->json();
        $this->assertGreaterThan(0, $again['wait']);
        $this->assertStringStartsWith('You checked a moment ago. Try again in', $again['message']);
        Http::assertSentCount(1);

        // A plain form goes back to the page with the message.
        $this->travel(61)->seconds();
        $this->actingAs($admin)
            ->post(route('admin.updates.check'))
            ->assertRedirect(route('admin.updates.index'))
            ->assertSessionHas('success', 'AVDBCMS '.self::next().' is available');
        Http::assertSentCount(2);
    }

    public function test_a_wrong_password_is_refused_and_five_block_update_and_undo_for_ten_minutes(): void
    {
        $this->installManifest();
        $this->keepRelease();
        $admin = $this->admin();

        for ($try = 1; $try <= 4; $try++) {
            $this->actingAs($admin)
                ->postJson(route('admin.updates.start'), ['password' => 'not-the-password'])
                ->assertStatus(422)
                ->assertJson(['ok' => false, 'field' => 'password', 'message' => 'That password is not right.']);
        }

        $this->actingAs($admin)
            ->postJson(route('admin.updates.start'), ['password' => 'not-the-password'])
            ->assertStatus(429)
            ->assertJson(['ok' => false, 'locked' => true, 'message' => 'Too many wrong passwords. Try again in 10 minutes.']);

        // Blocked: the right password does not start anything, Undo is blocked too, the page says so.
        $this->actingAs($admin)->postJson(route('admin.updates.start'), ['password' => 'password'])->assertStatus(429);
        $this->actingAs($admin)->postJson(route('admin.updates.undo'), ['password' => 'password'])->assertStatus(429);
        $this->actingAs($admin)
            ->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSee('Too many wrong passwords. Try again in 10 minutes.')
            ->assertSee('data-ad-updates-open="ad-upd-install" disabled', false);
        $this->assertFalse(is_file($this->root.'/storage/app/updates/plan.json'));

        // Another administrator is not blocked by it.
        $this->actingAs($this->admin())->postJson(route('admin.updates.undo'), ['password' => 'nope'])->assertStatus(422);

        // Ten minutes later the right password starts the update (the plan lives in the temporary site root).
        $this->travel(601)->seconds();
        $this->actingAs($admin)
            ->postJson(route('admin.updates.start'), ['password' => 'password'])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'downloading', 'next' => 'step'])
            ->assertJsonMissingPath('cookie');

        $plan = json_decode((string) file_get_contents($this->root.'/storage/app/updates/plan.json'), true);
        $this->assertSame(['update', self::next(), AppVersion::CURRENT, $admin->id], [$plan['kind'], $plan['version'], $plan['from'], $plan['admin_id']]);
        $this->assertTrue(DB::table('admin_audit_logs')->where('action', 'update.started')->where('details', AppVersion::CURRENT.' -> '.self::next())->exists());

        // The page now shows the running update of this administrator and goes on with it by itself.
        $html = $this->actingAs($admin)->get(route('admin.updates.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<section class="ad-panel ad-console-block ad-upd-progress" data-ad-updates-progress aria-labelledby="ad-upd-progress-t"\s*>/', $html);
        $this->assertStringContainsString('Installing AVDBCMS '.self::next(), $html);
        $boot = $this->boot($html);
        $this->assertTrue($boot['run']);
        $this->assertSame(['next' => 'step', 'mine' => true, 'stalled' => false, 'kind' => 'update', 'version' => self::next(), 'waiting' => false], $boot['plan']);
        $this->assertSame(route('admin.updates.swap', [], false), $boot['urls']['swap']);
        $this->assertStringNotContainsString('data-ad-updates-open="ad-upd-install"', $html);
    }

    public function test_skipping_the_database_backup_is_offered_only_with_a_backup_from_the_last_day(): void
    {
        $this->installManifest();
        $this->keepRelease();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.updates.index'))->assertOk()->assertDontSee('Skip the database backup');

        $this->database->recentBackup = ['name' => 'backup_2026-10-23_100000.sql.gz', 'date' => time() - 3600, 'size' => 4096];

        $this->actingAs($admin)
            ->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSee('Skip the database backup')
            ->assertSee('The Backups page already has a backup from')
            ->assertSee('backup_2026-10-23_100000.sql.gz');

        $this->actingAs($admin)->postJson(route('admin.updates.start'), ['password' => 'password', 'skip_backup' => 1])->assertOk()->assertJsonPath('next', 'step');
        $this->assertTrue(json_decode((string) file_get_contents($this->root.'/storage/app/updates/plan.json'), true)['skip_backup']);
    }

    public function test_installing_needs_the_csrf_token(): void
    {
        $this->app->bind(VerifyCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends VerifyCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $admin = $this->admin();

        foreach (['start', 'step', 'finish', 'cancel', 'undo', 'check'] as $name) {
            $this->actingAs($admin)->postJson(route('admin.updates.'.$name), ['password' => 'password'])->assertStatus(419);
        }

        $this->actingAs($admin)
            ->withSession(['_token' => 'csrf-token-of-this-session'])
            ->postJson(route('admin.updates.start'), ['password' => 'wrong', '_token' => 'csrf-token-of-this-session'])
            ->assertStatus(422);
    }

    public function test_the_update_cookie_is_http_only_lax_twelve_hours_and_never_encrypted_or_in_the_json(): void
    {
        $this->assertTrue(app(EncryptCookies::class)->isDisabled(Updater::COOKIE));

        $respond = new ReflectionMethod(UpdatesController::class, 'respond');
        $answer = ['ok' => true, 'status' => 'swapping', 'next' => 'swap', 'token' => str_repeat('a', 64), 'cookie' => str_repeat('c', 64)];
        $json = Request::create('/admin/updates/step', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
        $response = $respond->invoke(new UpdatesController(), $json, $answer);

        $this->assertSame(str_repeat('a', 64), $response->getData(true)['token']);
        $this->assertArrayNotHasKey('cookie', $response->getData(true));
        $cookie = $this->cookieOf($response->headers->getCookies());
        $this->assertSame(str_repeat('c', 64), $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertFalse($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertEqualsWithDelta(time() + Updater::COOKIE_SECONDS, $cookie->getExpiresTime(), 5);

        // Secure on https, also on a plain form's redirect.
        $https = Request::create('https://example.test/admin/updates/step', 'POST');
        $redirect = $respond->invoke(new UpdatesController(), $https, $answer);
        $this->assertTrue($this->cookieOf($redirect->headers->getCookies())->isSecure());

        // Only a 64-hex value is ever set.
        $odd = $respond->invoke(new UpdatesController(), $json, ['ok' => true, 'cookie' => 'not-hex']);
        $this->assertSame([], $odd->headers->getCookies());
    }

    public function test_the_update_cookie_is_secure_whenever_the_session_cookie_is(): void
    {
        $respond = new ReflectionMethod(UpdatesController::class, 'respond');
        $answer = ['ok' => true, 'status' => 'downloading', 'next' => 'step', 'cookie' => str_repeat('c', 64)];
        $secure = fn (Request $request): bool => $this->cookieOf($respond->invoke(new UpdatesController(), $request, $answer)->headers->getCookies())->isSecure();
        $http = fn (array $server = []): Request => Request::create('http://example.test/admin/updates/start', 'POST', server: ['HTTP_ACCEPT' => 'application/json'] + $server);

        // An http origin behind a TLS proxy (Cloudflare Flexible): EnvironmentServiceProvider makes the session cookie
        // Secure for such a request (session.secure), and one plain-http request must not expose this cookie either.
        config(['session.secure' => true, 'app.url' => 'http://example.test']);
        $this->assertTrue($secure($http(['HTTP_X_FORWARDED_PROTO' => 'https'])));

        config(['session.secure' => null, 'app.url' => 'https://example.test']);
        $this->assertTrue($secure($http()), 'an https APP_URL counts too');

        config(['app.url' => 'http://example.test']);
        $this->assertFalse($secure($http()), 'a plain http site');
    }

    public function test_a_browser_without_the_update_cookie_gets_it_before_any_step_works(): void
    {
        $this->installManifest();
        $this->fakeGitHub();
        $admin = $this->admin();
        $toGitHub = fn (): int => count(Http::recorded(fn (ClientRequest $request): bool => str_starts_with($request->url(), AppVersion::WEB_BASE.'/')));

        $start = $this->actingAs($admin)->postJson(route('admin.updates.start'), ['password' => 'password'])
            ->assertOk()->assertJson(['ok' => true, 'status' => 'downloading', 'next' => 'step'])->assertJsonMissingPath('cookie');
        $first = $this->cookieOf($start->headers->getCookies())->getValue();
        $this->assertSame(0, $toGitHub(), 'the start answer hands out the cookie, long before anything can take the site down');

        // Another browser of the same administrator, or the start's answer never arrived: the step only hands it out.
        $handed = $this->actingAs($admin)->postJson(route('admin.updates.step'))->assertOk()->assertJson(['ok' => true, 'status' => 'downloading', 'next' => 'step']);
        $second = $this->cookieOf($handed->headers->getCookies())->getValue();
        $this->assertNotSame($first, $second);
        $this->assertSame(0, $toGitHub(), 'that answer did nothing else');

        // With it, the steps work (here the faked github.com answers 404 for the checksum file: the download waits).
        $this->withCredentials()->withUnencryptedCookie(Updater::COOKIE, $second)->actingAs($admin)->postJson(route('admin.updates.step'))
            ->assertOk()->assertJson(['ok' => false, 'retry' => true, 'message' => 'GitHub answered with an error (404). Try again later.']);
        $this->assertSame(1, $toGitHub());
        $this->assertSame(hash('sha256', $second), json_decode((string) File::get($this->root.'/storage/app/updates/plan.json'), true)['cookie']['hash']);
    }

    public function test_an_undo_that_did_not_finish_or_left_files_alone_is_worded_as_an_undo(): void
    {
        $undo = ['kind' => 'undo', 'version' => AppVersion::CURRENT, 'from' => self::next(), 'step_at' => time() - 60, 'finished_at' => time() - 60];
        $this->writePlan($undo + ['status' => 'failed', 'rolled_back' => true, 'failure' => ['step' => 'swap', 'reason' => 'app/A.php changed while the update was running.']]);

        $this->actingAs($this->admin())->get(route('admin.updates.index'))->assertOk()
            ->assertSee('The undo did not finish')
            ->assertSee('The undo could not finish. Your site keeps the new version. Nothing in the database was changed.')
            ->assertDontSee('The update could not be installed.');

        $folder = AppVersion::CURRENT.'-to-'.self::next().'-20261023120000';
        $this->writePlan($undo + ['status' => 'undone', 'report' => ['restored' => 3, 'removed' => 1, 'kept' => ['config/app.php']], 'kept_backup' => $folder]);

        $this->actingAs($this->admin())->get(route('admin.updates.index'))->assertOk()
            ->assertSee('Your files are back to AVDBCMS '.AppVersion::CURRENT.'. The database stays as it is now.')
            ->assertSee('These files changed after the update, so the undo left them as they are.')
            ->assertSee('config/app.php')
            ->assertSee('Their copies from before the update stay in storage/app/updates/backups/'.$folder.'/files/ until the next update.');
    }

    public function test_an_undo_that_is_still_comparing_files_can_be_cancelled_in_its_own_words(): void
    {
        $admin = $this->admin();
        $this->writePlan(['kind' => 'undo', 'version' => AppVersion::CURRENT, 'from' => self::next(), 'status' => 'preparing',
            'admin_id' => $admin->id, 'admin_name' => $admin->username, 'prepare' => ['done' => 2, 'total' => 9]]);

        $this->actingAs($admin)->get(route('admin.updates.index'))->assertOk()
            ->assertSee('Going back to AVDBCMS '.AppVersion::CURRENT)
            ->assertSee('Putting the files of AVDBCMS '.AppVersion::CURRENT.' back...')
            ->assertSee('data-ad-confirm="Cancel the undo?"', false)
            ->assertSee('data-ad-confirm-body="Nothing on your site was changed yet. Your site keeps the new version."', false)
            ->assertSee('>Cancel the undo</button>', false)
            ->assertDontSee('Cancel the update?');
    }

    public function test_the_status_shows_another_admins_update_without_its_secrets(): void
    {
        $this->writePlan(['status' => 'swapping', 'swap' => ['token_hash' => str_repeat('1', 64), 'cookie_hash' => str_repeat('2', 64), 'done' => 3, 'total' => 9]]);
        $admin = $this->admin();

        $status = $this->actingAs($admin)
            ->getJson(route('admin.updates.status', ['log' => 1]))
            ->assertOk()
            ->assertJsonPath('plan.status', 'swapping')
            ->assertJsonPath('plan.next', 'swap')
            ->assertJsonPath('plan.mine', false)
            ->assertJsonPath('plan.active', true)
            ->assertJsonPath('plan.progress', ['done' => 3, 'total' => 9])
            ->assertJsonPath('log', []);

        $this->assertStringNotContainsString(str_repeat('1', 64), $status->getContent());
        $this->assertStringNotContainsString(str_repeat('2', 64), $status->getContent());
        $this->assertStringContainsString('no-store', (string) $status->headers->get('Cache-Control'));

        $this->actingAs($admin)
            ->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSee('An update to '.self::next().' is being installed by otheradmin since')
            ->assertSee('Show progress');
    }

    public function test_a_stalled_update_offers_continue_and_cancel(): void
    {
        $this->writePlan(['status' => 'downloading', 'step_at' => time() - 3600, 'download' => ['done' => 1048576, 'total' => 4194304]]);

        $html = $this->actingAs($this->admin())->get(route('admin.updates.index'))->assertOk()->getContent();

        $this->assertStringContainsString('An update to '.self::next().' stopped', $html);
        $this->assertStringContainsString('Continue it or cancel it first.', $html);
        $this->assertStringContainsString('data-ad-updates-continue="step"', $html);
        $this->assertStringContainsString('action="'.route('admin.updates.cancel').'" data-ad-updates-cancel', $html);
        $this->assertStringContainsString('data-ad-confirm="Cancel the update?"', $html);
    }

    public function test_the_result_of_the_last_update_lists_kept_merged_and_replaced_files(): void
    {
        $this->writePlan([
            'status' => 'done',
            'finished_at' => time() - 60,
            'step_at' => time() - 60,
            'database_backup' => ['name' => 'backup_before_update_'.self::next().'_2026-10-23_120000.sql.gz', 'size' => 2048, 'method' => 'Pure-PHP'],
            'report' => [
                'replaced' => 12, 'added' => 3, 'removed' => 1, 'unchanged' => 400,
                'kept' => [['path' => '.htaccess', 'copy' => '.htaccess.avdbcms-'.self::next()]],
                'merged' => [['path' => 'lang/vi.json', 'added' => 7, 'kept' => 2]],
                'overwritten' => ['app/Http/Controllers/HomeController.php'],
            ],
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSee('AVDBCMS '.self::next().' is installed.')
            ->assertSee('Files replaced: 12. Added: 3. Removed: 1.')
            ->assertSee('Database backup: backup_before_update_'.self::next().'_2026-10-23_120000.sql.gz')
            ->assertSee('You changed these files, so the update kept your version. Compare them with the new copy.')
            ->assertSee('.htaccess.avdbcms-'.self::next())
            ->assertSee('These language files had texts you changed. Your texts stay; the new texts were added.')
            ->assertSee('7 new texts added')
            ->assertSee('Your changes to these files were replaced. The old copies are in the update backup.')
            ->assertSee('app/Http/Controllers/HomeController.php')
            ->assertSee('href="'.AppVersion::changelogUrl(self::next()).'"', false);
    }

    public function test_the_newest_update_backup_can_be_undone_and_any_backup_deleted(): void
    {
        $id = '0.9.9-to-'.AppVersion::CURRENT.'-20261023120000';
        $dump = 'backup_before_update_'.AppVersion::CURRENT.'_2026-10-23_120000.sql.gz';
        File::ensureDirectoryExists($this->root.'/storage/app/updates/backups/'.$id.'/files/app');
        File::put($this->root.'/storage/app/updates/backups/'.$id.'/files/app/Old.php', '<?php // old');
        File::put($this->root.'/storage/app/updates/backups/'.$id.'/backup.json', json_encode([
            'format' => 1, 'from' => '0.9.9', 'to' => AppVersion::CURRENT, 'created_at' => time() - 600, 'admin_id' => 1,
            'database_backup' => $dump, 'status' => 'complete', 'size' => 12, 'entries' => [['path' => 'app/Old.php', 'action' => 'replaced', 'old' => str_repeat('a', 64), 'new' => str_repeat('b', 64)]],
        ]));
        File::put($this->root.'/storage/app/updates/state.json', json_encode([
            'installed_version' => AppVersion::CURRENT, 'previous_version' => '0.9.9', 'updated_at' => time() - 600, 'backup_id' => $id, 'admin_id' => 1,
        ]));
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSee('Update backups')
            ->assertSee('AVDBCMS 0.9.9')
            ->assertSee('Undo this update')
            ->assertSee('Undo the update to AVDBCMS '.AppVersion::CURRENT.'?')
            ->assertSee("Your files go back to AVDBCMS 0.9.9. The database stays as it is now. If the old version shows errors, restore the database backup {$dump} with your hosting panel's database tool.")
            ->assertSee('Database backup: '.$dump)
            ->assertSee('data-ad-confirm="Delete this update backup?"', false)
            // "Last updated" from state.json
            ->assertSee('from AVDBCMS 0.9.9');

        $this->actingAs($admin)->postJson(route('admin.updates.undo'), ['password' => 'wrong'])->assertStatus(422);

        // Only well-formed backup names reach the controller (anything else is "not found" or, where another route of
        // the site takes the address, "method not allowed").
        foreach (['not-a-backup', '1.0.0-to-1.0.1-2026102312000', '1.0-to-1.0.1-20261023120000', '..', '..%2F..%2Fapp'] as $bad) {
            $this->assertContains($this->actingAs($admin)->postJson('/admin/updates/backups/'.$bad.'/delete')->getStatusCode(), [404, 405], $bad);
        }

        $this->assertDirectoryExists($this->root.'/storage/app/updates/backups/'.$id);
        $this->assertFalse(DB::table('admin_audit_logs')->where('action', 'update.backup_deleted')->exists());
        $this->actingAs($admin)
            ->postJson(route('admin.updates.backups.delete', ['id' => '1.0.0-to-1.0.1-20200101000000']))
            ->assertOk()
            ->assertJson(['ok' => false, 'message' => 'That backup is not there any more.']);

        $this->actingAs($admin)
            ->post(route('admin.updates.backups.delete', ['id' => $id]))
            ->assertRedirect(route('admin.updates.index'))
            ->assertSessionHas('success', 'The backup '.$id.' was deleted.');

        $this->assertDirectoryDoesNotExist($this->root.'/storage/app/updates/backups/'.$id);
        $this->assertTrue(DB::table('admin_audit_logs')->where('action', 'update.backup_deleted')->where('details', $id)->exists());
        $this->actingAs($admin)->get(route('admin.updates.index'))->assertOk()->assertSee('No update backups yet.')->assertDontSee('Undo this update');
    }

    public function test_an_unexpected_failure_is_answered_and_never_a_500(): void
    {
        // A storage folder that is a file: the update lock cannot be made.
        File::put($this->root.'/storage-file', 'x');
        $this->app->instance(Updater::class, new Updater(new UpdatePaths($this->root, $this->root.'/storage-file'), new ReleaseCheck(), $this->database));
        $admin = $this->admin();

        $answer = $this->actingAs($admin)->postJson(route('admin.updates.step'))->assertOk()->assertJson(['ok' => false, 'next' => null, 'retry' => true])->json();
        $this->assertStringStartsWith('The update stopped: RuntimeException:', $answer['message']);

        $this->actingAs($admin)->post(route('admin.updates.finish'))->assertRedirect(route('admin.updates.index'))->assertSessionHasErrors('update');
        $this->actingAs($admin)->get(route('admin.updates.index'))->assertOk();
    }

    public function test_home_never_asks_github_and_shows_the_kept_answer(): void
    {
        Http::fake();
        $admin = $this->admin();

        // Nothing kept: the `updates` probe is asked after the page appears.
        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('data-ad-defer="home-release"', false)
            ->assertSee('data-ad-defer-probes="'.route('admin.status.probe', ['probe' => 'updates']).'"', false)
            ->assertDontSee('See the update');

        $this->keepRelease();
        $html = $this->actingAs($admin)->get(route('admin.index'))->assertOk()->getContent();

        $this->assertStringContainsString('AVDBCMS '.self::next().' is available', $html);
        $this->assertStringContainsString('Your site runs AVDBCMS '.AppVersion::CURRENT.'.', $html);
        $this->assertStringContainsString('href="'.route('admin.updates.index').'">See the update', $html);
        $this->assertStringNotContainsString(route('admin.status.probe', ['probe' => 'updates']), $html);

        // An update of another administrator: Home says who runs it.
        $this->writePlan(['status' => 'downloading']);
        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('An update to '.self::next().' is being installed by otheradmin since')
            ->assertSee('Show progress')
            ->assertDontSee('See the update');

        Http::assertNothingSent();
    }

    public function test_the_updates_probe_names_a_newer_version_and_keeps_a_failed_check_for_an_hour(): void
    {
        $this->fakeGitHub();
        $admin = $this->admin();

        $answer = $this->actingAs($admin)->getJson(route('admin.status.probe', ['probe' => 'updates']))->assertOk()->json();

        $this->assertSame(['warn', true, 'AVDBCMS '.self::next().' is available'], [$answer['status'], $answer['ok'], $answer['label']]);
        $this->assertSame(['label' => 'See the update', 'url' => route('admin.updates.index')], $answer['action']);
        $this->assertSame(['available', AppVersion::CURRENT, self::next()], [$answer['data']['state'], $answer['data']['current'], $answer['data']['version']]);
        $this->assertArrayNotHasKey('keep', $answer);
        Http::assertSentCount(1);

        // Home shows its answer from now on, without asking again.
        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertSee('AVDBCMS '.self::next().' is available');
        Http::assertSentCount(1);

        // GitHub down: "off" (never "danger"), kept one hour like the check itself.
        Cache::flush();
        $this->fakeGitHub(500, ['message' => 'Server Error']);
        $failed = StatusProbeController::result('updates');
        $this->assertSame(['off', true, 'GitHub answered with an error (500). Try again later.'], [$failed['status'], $failed['ok'], $failed['label']]);
        $this->travel(3590)->seconds();
        $this->assertNotNull(StatusProbeController::peek('updates'));
        $this->travel(20)->seconds();
        $this->assertNull(StatusProbeController::peek('updates'));

        // No release yet: "off" with the updater's words; up to date: "ok".
        Cache::flush();
        $this->fakeGitHub(404, ['message' => 'Not Found']);
        $none = StatusProbeController::result('updates');
        $this->assertSame(['off', 'No release found yet.'], [$none['status'], $none['label']]);

        Cache::flush();
        $this->fakeGitHub(200, $this->latestRelease(AppVersion::CURRENT));
        $current = StatusProbeController::result('updates');
        $this->assertSame(['ok', 'Your site is up to date.'], [$current['status'], $current['label']]);
        Http::assertSentCount(4);
    }

    public function test_health_check_shows_the_avdbcms_version_next_to_laravels(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.system.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/AVDBCMS version<\/td>\s*<td>\s*<code>'.preg_quote(AppVersion::CURRENT, '/').'<\/code> <a href="'.preg_quote(route('admin.updates.index'), '/').'"/', $html);
        $this->assertLessThan(strpos($html, '>Version</td>'), strpos($html, 'AVDBCMS version'));
    }

    public function test_the_old_dashboard_gets_one_link_when_a_newer_version_is_kept(): void
    {
        Http::fake();
        config(['admin.ui' => 'v1']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertSee('avd-back-sidebar', false)->assertDontSee('See the update');

        $this->keepRelease();
        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('AVDBCMS '.self::next().' is available')
            ->assertSee('href="'.route('admin.updates.index').'" class="btn btn-sm btn-primary">See the update', false);

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------- helpers */

    private function admin(): User
    {
        return User::factory()->admin()->create(['username' => 'admin'.Str::lower(Str::random(6))]);
    }

    /** The version after the running one (the tests never depend on the value of AppVersion::CURRENT). */
    private static function next(): string
    {
        [$major, $minor, $patch] = array_map('intval', explode('.', AppVersion::CURRENT));

        return $major.'.'.$minor.'.'.($patch + 1);
    }

    /** @return list<array{0: string, 1: string, 2: bool}> method, address, installs (admin.ops) */
    private function addresses(): array
    {
        return [
            ['GET', route('admin.updates.index'), false],
            ['GET', route('admin.updates.status'), false],
            ['POST', route('admin.updates.check'), false],
            ['POST', route('admin.updates.start'), true],
            ['POST', route('admin.updates.step'), true],
            ['POST', route('admin.updates.swap'), true],
            ['POST', route('admin.updates.finish'), true],
            ['POST', route('admin.updates.cancel'), true],
            ['POST', route('admin.updates.undo'), true],
            ['POST', route('admin.updates.backups.delete', ['id' => '1.0.0-to-1.0.1-20261023120000']), true],
        ];
    }

    /** The installed version's list of files in the temporary site root (a site without it cannot update). */
    private function installManifest(): void
    {
        File::put($this->root.'/'.'avdbcms-manifest.json', (string) json_encode([
            'product' => AppVersion::PRODUCT,
            'version' => AppVersion::CURRENT,
            'requires_php' => '8.3.0',
            'min_from' => '1.2.0',
            'built_at' => '2026-10-23T00:00:00Z',
            'files' => ['index.php' => hash('sha256', '<?php')],
            'removed' => [],
        ]));
    }

    /**
     * What the faked GitHub API answers ([status, body, headers]); github.com itself answers 404 (no fallback tag).
     * One fake per test (Http stubs add up and the first that matches wins), so a test changes the answer here.
     *
     * @param  array<string, string>  $headers
     */
    private function fakeGitHub(int $status = 200, mixed $body = null, array $headers = []): void
    {
        $this->github = [$status, $body ?? $this->latestRelease(), $headers];

        if (! $this->faked) {
            $this->faked = true;
            Http::fake(fn (ClientRequest $request) => str_starts_with($request->url(), AppVersion::API_BASE.'/')
                ? Http::response($this->github[1], $this->github[0], $this->github[2])
                : Http::response('', 404));
        }
    }

    /** @var array{0: int, 1: mixed, 2: array<string, string>} */
    private array $github = [200, null, []];

    private bool $faked = false;

    /** The API's latest release of the repository (the next version unless told), with both update assets. */
    private function latestRelease(?string $version = null): array
    {
        $version ??= self::next();
        $zip = AppVersion::updateAsset($version);

        return [
            'tag_name' => 'v'.$version,
            'name' => 'AVDBCMS '.$version,
            'draft' => false,
            'prerelease' => false,
            'published_at' => '2026-10-30T10:00:00Z',
            'body' => 'Fixes and small improvements.',
            'assets' => [
                ['name' => $zip, 'size' => 68 * 1048576, 'state' => 'uploaded', 'digest' => 'sha256:'.str_repeat('ab', 32), 'browser_download_url' => AppVersion::downloadBase($version).$zip],
                ['name' => $zip.'.sha256', 'size' => 100, 'state' => 'uploaded', 'browser_download_url' => AppVersion::downloadBase($version).$zip.'.sha256'],
            ],
        ];
    }

    /** A kept check that found the next version (what Home and the page render from). */
    private function keepRelease(array $changes = []): void
    {
        $release = ReleaseCheck::fromTag('v'.self::next());
        $release['published_at'] = '2026-10-30T10:00:00Z';
        $release['body'] = 'Fixes and small improvements.';
        $release['zip']['size'] = 68 * 1048576;

        Cache::put(ReleaseCheck::CACHE_KEY, [
            'state' => 'available',
            'checked_at' => time() - 120,
            'release' => array_replace($release, $changes),
            'source' => 'api',
            'error' => null,
        ], 3600);
    }

    /** plan.json of an update in the temporary site root (another administrator's unless the test says otherwise). */
    private function writePlan(array $plan): void
    {
        File::ensureDirectoryExists($this->root.'/storage/app/updates');
        File::put($this->root.'/storage/app/updates/plan.json', (string) json_encode($plan + [
            'format' => 1,
            'id' => 'a1b2c3d4e5f60718',
            'kind' => 'update',
            'version' => self::next(),
            'from' => AppVersion::CURRENT,
            'admin_id' => 999999,
            'admin_name' => 'otheradmin',
            'started_at' => time() - 120,
            'step_at' => time() - 10,
            'job' => self::next(),
            'release' => ['version' => self::next(), 'tag' => 'v'.self::next(), 'name' => 'AVDBCMS '.self::next()],
            'engine' => 'PharData',
        ]));
    }

    /** The page script's boot data (#ad-updates-boot). */
    private function boot(string $html): array
    {
        $this->assertSame(1, preg_match('/<script type="application\/json" id="ad-updates-boot">(.*?)<\/script>/s', $html, $match));

        return (array) json_decode($match[1], true);
    }

    /** @param  list<Cookie>  $cookies */
    private function cookieOf(array $cookies): Cookie
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === Updater::COOKIE) {
                return $cookie;
            }
        }

        $this->fail('No update cookie was set.');
    }
}
