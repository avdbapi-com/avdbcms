<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\StatusProbeController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Slow server checks load after the page appears: GET /admin/status/{probe} answers JSON, cached per probe (a failure
 * for 120 s), and the pages that show them render at once - inline when the answer is cached, otherwise with a
 * placeholder section the page fills in (data-ad-defer-probes).
 */
class AdminStatusProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2']);
        Cache::flush();
        // The video providers are never asked for real: the hosts in $down fail, AVDBAPI answers $avdbAnswer.
        Http::fake(fn (Request $request) => array_filter($this->down, fn (string $host): bool => str_contains($request->url(), $host)) !== []
            ? Http::response('Bad gateway', 502)
            : Http::response(str_contains($request->url(), 'avdbapi.com') ? $this->avdbAnswer : ['code' => 1, 'list' => []]));
    }

    /** @var list<string> */
    private array $down = [];

    private array $avdbAnswer = ['code' => 1, 'list' => []];

    public static function probes(): array
    {
        return array_map(fn (string $probe): array => [$probe], array_combine(array_keys(StatusProbeController::PROBES), array_keys(StatusProbeController::PROBES)));
    }

    #[DataProvider('probes')]
    public function test_each_probe_answers_its_contract_and_is_cached(string $probe): void
    {
        $admin = User::factory()->admin()->create();

        $first = $this->actingAs($admin)
            ->getJson(route('admin.status.probe', ['probe' => $probe]))
            ->assertOk()
            ->assertJsonStructure(['ok', 'probe', 'status', 'label', 'detail', 'data', 'action', 'checked_at', 'ms', 'cached'])
            ->assertJson(['probe' => $probe, 'cached' => false])
            ->json();

        $this->assertStringContainsString('no-store', (string) $this->actingAs($admin)->getJson(route('admin.status.probe', ['probe' => $probe]))->headers->get('Cache-Control'));
        $this->assertContains($first['status'], ['ok', 'warn', 'danger', 'off']);
        $this->assertSame($first['status'] !== 'danger', $first['ok']);
        $this->assertNotSame('', $first['label']);

        $this->actingAs($admin)->getJson(route('admin.status.probe', ['probe' => $probe]))->assertJson(['cached' => true, 'checked_at' => $first['checked_at']]);
        $this->actingAs($admin)->getJson(route('admin.status.probe', ['probe' => $probe, 'refresh' => 1]))->assertJson(['cached' => false]);
    }

    public function test_only_administrators_reach_the_probes_and_only_known_ones(): void
    {
        $this->getJson('/admin/status/disk')->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson('/admin/status/disk')->assertStatus(404);
        $this->actingAs(User::factory()->admin()->create())->getJson('/admin/status/passwords')->assertNotFound();
    }

    public function test_a_provider_that_does_not_answer_is_a_warning_and_kept_for_two_minutes(): void
    {
        $this->down = ['xvidapi.com'];

        $result = StatusProbeController::result('provider');

        $this->assertSame('warn', $result['status']);
        $this->assertSame('XVIDAPI did not answer', $result['label']);
        $this->assertTrue($result['data']['providers']['AVDBAPI']['ok']);
        $this->assertFalse($result['data']['providers']['XVIDAPI']['ok']);

        $this->down = ['xvidapi.com', 'avdbapi.com'];
        $down = StatusProbeController::result('provider', true);
        $this->assertSame('danger', $down['status']);
        $this->assertFalse($down['ok']);
        $this->assertNotNull(StatusProbeController::peek('provider'));

        $this->travel(121)->seconds();
        $this->assertNull(StatusProbeController::peek('provider'), 'a failure is kept for 120 s only');
    }

    public function test_the_folder_sizes_and_the_schedule_come_from_the_probe(): void
    {
        $folders = StatusProbeController::result('folders');

        $this->assertSame(['storage/app', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'public/uploads', 'public/thumbnails', 'public/avatars'], array_keys($folders['data']['folders']));
        $this->assertArrayHasKey('formatted_size', $folders['data']['folders']['storage/app']);

        $schedule = StatusProbeController::result('schedule');
        $this->assertIsArray($schedule['data']['tasks']);
        $this->assertSame(count($schedule['data']['tasks']), $schedule['data']['count']);
    }

    public function test_redis_is_off_when_the_site_does_not_use_it(): void
    {
        config(['cache.default' => 'array', 'queue.default' => 'sync', 'session.driver' => 'array']);

        $redis = StatusProbeController::result('redis');

        $this->assertContains($redis['status'], ['off', 'ok']);
        $this->assertFalse($redis['data']['used']);
    }

    public function test_pages_render_at_once_and_fill_the_slow_parts_after_they_appear(): void
    {
        $admin = User::factory()->admin()->create();

        // Nothing cached: a placeholder section that names its probes.
        $this->actingAs($admin)
            ->get(route('admin.storage.index'))
            ->assertOk()
            ->assertSee('data-ad-defer="folders"', false)
            ->assertSee('data-ad-defer-probes="'.route('admin.status.probe', ['probe' => 'folders']).'"', false);

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('data-ad-defer="home-needs"', false)
            ->assertSee(route('admin.status.probe', ['probe' => 'schedule']), false)
            ->assertSee('Checking the scheduled tasks, the disk and the server...')
            // Is a newer AVDBCMS out? Asked after the page appears too (Tools > Updates' check, kept 12 hours).
            ->assertSee('data-ad-defer="home-release"', false)
            ->assertSee('data-ad-defer-probes="'.route('admin.status.probe', ['probe' => 'updates']).'"', false);
        Http::assertNothingSent();

        // Cached answers render inline: no placeholder, the finding is listed with its action.
        StatusProbeController::result('folders');
        foreach (['disk', 'health', 'updates'] as $probe) {
            StatusProbeController::result($probe);
        }
        Cache::put('admin.status.schedule.en', [
            'ok' => true, 'probe' => 'schedule', 'status' => 'warn', 'label' => 'Scheduled tasks are not running',
            'detail' => 'Daily imports and clean-ups will not start until this is fixed.', 'data' => ['tasks' => [], 'count' => 0],
            'action' => ['label' => 'How to fix', 'url' => route('admin.cron.index')], 'checked_at' => now()->toIso8601String(), 'ms' => 1,
        ], 300);

        $this->actingAs($admin)
            ->get(route('admin.storage.index'))
            ->assertOk()
            ->assertDontSee('data-ad-defer-probes', false)
            ->assertSee('storage/framework/views');

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertDontSee('data-ad-defer-probes', false)
            ->assertSee('Scheduled tasks are not running')
            ->assertSee('href="'.route('admin.cron.index').'"', false);
    }

    public function test_the_cron_page_answers_before_the_scheduler_and_php_checks(): void
    {
        $admin = User::factory()->admin()->create();
        config(['admin.operational_tools_enabled' => true]);

        // Neither check has run: the task list, its counts and the PHP line come after the page appears.
        $this->actingAs($admin)
            ->get(route('admin.cron.index'))
            ->assertOk()
            ->assertSee('data-ad-defer="cron-tasks"', false)
            ->assertSee('data-ad-defer="cron-process"', false)
            ->assertSee(route('admin.status.probe', ['probe' => 'schedule']).' '.route('admin.status.probe', ['probe' => 'php']), false)
            ->assertSee('Listing the scheduled tasks...')
            ->assertSee('Checking...');

        $answer = fn (string $probe, array $data): array => ['ok' => true, 'probe' => $probe, 'status' => 'ok', 'label' => $probe, 'detail' => null,
            'data' => $data, 'action' => null, 'checked_at' => now()->toIso8601String(), 'ms' => 1];
        Cache::put('admin.status.schedule.en', $answer('schedule', ['tasks' => [['expression' => '0 3 * * *', 'command' => 'php artisan sitemap:generate', 'description' => null,
            'next_due_date' => null, 'next_due_date_human' => null, 'timezone' => 'UTC']], 'count' => 1]), 300);
        Cache::put('admin.status.php.en', $answer('php', ['path' => 'php', 'usable' => true]), 600);

        // Both answers kept: the page renders them inline, nothing waits.
        $this->actingAs($admin)
            ->get(route('admin.cron.index'))
            ->assertOk()
            ->assertDontSee('data-ad-defer-probes', false)
            ->assertSee('sitemap:generate')
            ->assertDontSee('Checking...');
    }

    public function test_the_old_admin_counts_the_folders_before_it_answers(): void
    {
        config(['admin.ui' => 'v1']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.storage.index'))
            ->assertOk()
            ->assertSee('Directory Breakdown')
            ->assertDontSee('data-ad-defer', false);
    }

    /**
     * Owner decision OD6: the AVDBAPI import console shows the provider probe's catalog numbers ("Checked ..." +
     * Refresh) and never asks the provider before it answers; the old admin still asks first.
     */
    public function test_the_avdb_console_shows_the_provider_probe_and_never_waits_for_the_provider(): void
    {
        $admin = User::factory()->admin()->create();
        $this->avdbAnswer = ['code' => 1, 'total' => 250, 'pagecount' => 3, 'limit' => '100', 'list' => []];
        $probeUrl = route('admin.status.probe', ['probe' => 'provider'], false);

        // No answer yet: the page answers at once and asks the probe after it appears.
        $this->actingAs($admin)
            ->get(route('admin.avdb-import.index'))
            ->assertOk()
            ->assertSee('data-ad-defer="avdb-api-summary"', false)
            ->assertSee('data-ad-defer-probes="'.$probeUrl.'"', false)
            ->assertSee('Asking AVDBAPI for its numbers...');
        Http::assertNothingSent();

        // The probe asks AVDBAPI the console's question (100 per page) and keeps its numbers.
        $result = StatusProbeController::result('provider');
        $this->assertSame(['available' => true, 'total' => 250, 'pages' => 3, 'page_size' => 100, 'categories' => []], $result['data']['providers']['AVDBAPI']['summary']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'avdbapi.com') && $request['ac'] === 'list' && (int) $request['pagesize'] === 100);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'xvidapi.com') && (int) $request['pagesize'] === 1);
        $asked = count(Http::recorded());

        $html = $this->actingAs($admin)->get(route('admin.avdb-import.index'))->assertOk()->getContent();
        $this->assertCount($asked, Http::recorded(), 'the page asks no provider');
        $this->assertStringNotContainsString('data-ad-defer-probes', $html);
        $this->assertMatchesRegularExpression('/>250<\/span>/', $html);
        $this->assertMatchesRegularExpression('/>3<\/span>/', $html);
        $this->assertStringContainsString('100 videos / page', $html);
        $this->assertMatchesRegularExpression('/Checked \d+ seconds? ago/', $html);
        $this->assertStringContainsString('data-ad-probe-refresh="'.$probeUrl.'"', $html);

        // An answer kept before the probe recorded the numbers: the page asks for a fresh one.
        Cache::put('admin.status.provider.en', array_replace($result, ['data' => ['providers' => ['AVDBAPI' => ['ok' => true, 'ms' => 5], 'XVIDAPI' => ['ok' => true, 'ms' => 5]]]]), 300);
        $this->actingAs($admin)
            ->get(route('admin.avdb-import.index'))
            ->assertOk()
            ->assertSee('data-ad-defer-probes="'.$probeUrl.'?refresh=1"', false);

        // AVDBAPI down: the probe's failure is shown as such, with the time of the check.
        $this->down = ['avdbapi.com'];
        StatusProbeController::result('provider', true);
        $this->actingAs($admin)
            ->get(route('admin.avdb-import.index'))
            ->assertOk()
            ->assertSee('AVDBAPI did not send its numbers.')
            ->assertDontSee('data-ad-defer-probes', false);
        $this->assertCount($asked + 2, Http::recorded(), 'only the refreshed probe asked (both providers at once)');

        // The old admin (one release) asks AVDBAPI before it answers, as it always did.
        config(['admin.ui' => 'v1']);
        $this->down = [];
        Cache::forget('avdbapi_admin_api_summary_v4');
        $this->actingAs($admin)
            ->get(route('admin.avdb-import.index'))
            ->assertOk()
            ->assertDontSee('data-ad-defer', false)
            ->assertSee('250');
        $this->assertCount($asked + 3, Http::recorded());
    }

    /**
     * An answer holds its labels already translated, so each language keeps its own: an administrator reading the
     * admin in Arabic never sees the Vietnamese answer another one's visit cached a minute earlier (Home's "Needs you"
     * and /admin/status/{probe}).
     */
    public function test_each_language_keeps_its_own_answer(): void
    {
        $admin = User::factory()->admin()->create();
        $this->down = ['xvidapi.com'];
        $probeUrl = route('admin.status.probe', ['probe' => 'disk']);

        $vi = $this->actingAs($admin)->withSession(['app_locale' => 'vi'])->getJson($probeUrl)->assertOk()->json();
        $this->assertFalse($vi['cached']);
        $this->actingAs($admin)->withSession(['app_locale' => 'vi'])->getJson($probeUrl)->assertJson(['cached' => true, 'label' => $vi['label']]);

        $ar = $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->getJson($probeUrl)->assertOk()->json();
        $this->assertFalse($ar['cached'], 'Arabic does not get the Vietnamese answer');
        $this->assertNotSame($vi['label'], $ar['label']);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $ar['label']);

        // Home's "Needs you" lists the cached answers: each language its own.
        app()->setLocale('vi');
        StatusProbeController::result('provider');
        $this->assertSame('XVIDAPI không phản hồi', StatusProbeController::peek('provider')['label']);
        app()->setLocale('ar');
        $this->assertNull(StatusProbeController::peek('provider'));
        StatusProbeController::result('provider');
        $arLabel = StatusProbeController::peek('provider')['label'];
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $arLabel);
        app()->setLocale('en');

        DB::table('avdb_import_schedules')->insert(['name' => 'Nightly', 'enabled' => true, 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $home = $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->get(route('admin.index'))->assertOk()->getContent();
        $this->assertStringContainsString(e($arLabel), $home);
        $this->assertStringNotContainsString('không phản hồi', $home);
        $this->assertStringContainsString('XVIDAPI không phản hồi', $this->actingAs($admin)->withSession(['app_locale' => 'vi'])->get(route('admin.index'))->assertOk()->getContent());

        // Clearing an answer (e.g. after a folder is emptied) clears it in every language.
        app()->setLocale('vi');
        StatusProbeController::forget('provider');
        $this->assertNull(StatusProbeController::peek('provider'));
        app()->setLocale('ar');
        $this->assertNull(StatusProbeController::peek('provider'));
        app()->setLocale('en');
    }
}
