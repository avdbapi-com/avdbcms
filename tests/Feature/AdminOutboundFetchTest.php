<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AvdbImportController;
use App\Http\Controllers\Admin\XvidImportController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Security plan S22: the admin's connection and stream tests and the import bases never reach this server, its private
 * network or a cloud metadata address (a refused address is never fetched), redirects are followed only to public
 * addresses, and the provider APIs are fetched with TLS verification (only the six media-host probes keep it off: they
 * store a duration or a checked poster, and third-party hosts with broken certificates would only lose those).
 */
class AdminOutboundFetchTest extends TestCase
{
    use RefreshDatabase;

    private const REFUSAL = 'This address is not reachable from the internet.';

    public static function internalAddresses(): array
    {
        return [
            'a local service' => ['http://127.0.0.1:6379/'],
            'the cloud metadata address' => ['http://169.254.169.254/latest/meta-data/'],
            'a private network' => ['http://10.0.0.5/api.php/provide/vod'],
            'localhost' => ['http://localhost:8080/api.php/provide/vod'],
        ];
    }

    #[DataProvider('internalAddresses')]
    public function test_the_connection_tests_never_fetch_an_internal_address(string $url): void
    {
        Http::fake();
        $admin = User::factory()->admin()->create();

        foreach (['/admin/avdb-import/test-connection', '/admin/xvid-import/test-connection'] as $endpoint) {
            $this->actingAs($admin)->postJson($endpoint, ['api_base_url' => $url])
                ->assertJson(['success' => false, 'message' => self::REFUSAL]);
        }

        $this->actingAs($admin)->postJson('/admin/settings/test-stream', ['url' => $url])
            ->assertStatus(422)->assertJson(['success' => false, 'message' => self::REFUSAL]);

        Http::assertNothingSent();
    }

    public function test_a_public_address_still_works(): void
    {
        Http::fake([
            '8.8.8.8/*' => Http::response(['code' => 1, 'total' => 1234, 'class' => [], 'list' => []]),
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/admin/avdb-import/test-connection', ['api_base_url' => 'https://8.8.8.8/api.php/provide/vod'])
            ->assertJson(['success' => true, 'total_videos' => 1234]);
        $this->actingAs($admin)->postJson('/admin/xvid-import/test-connection', ['api_base_url' => 'https://8.8.8.8/api.php/provide/vod'])
            ->assertJson(['success' => true, 'total' => 1234]);

        Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'https://8.8.8.8/api.php/provide/vod'));
    }

    public function test_a_redirect_to_an_internal_address_is_refused(): void
    {
        Http::fake([
            '8.8.8.8/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1:6379/']),
            '127.0.0.1*' => Http::response('internal service', 200),
        ]);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/admin/settings/test-stream', ['url' => 'https://8.8.8.8/stream.m3u8']);

        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertStringContainsString(self::REFUSAL, (string) $response->json('message'));
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '127.0.0.1'));
    }

    public function test_an_import_base_that_is_not_public_is_saved_as_the_default(): void
    {
        $avdb = new ReflectionMethod(AvdbImportController::class, 'normalizeApiUrl');
        $xvid = new ReflectionMethod(XvidImportController::class, 'normalizeApiUrl');
        $avdbController = app(AvdbImportController::class);
        $xvidController = app(XvidImportController::class);

        foreach (['http://127.0.0.1:6379/', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5/{locale}/api.php/provide/vod', 'ftp://8.8.8.8/x', 'nonsense'] as $url) {
            $this->assertSame('https://avdbapi.com/api.php/provide/vod', $avdb->invoke($avdbController, $url), $url);
            $this->assertSame('https://xvidapi.com/api.php/provide/vod', $xvid->invoke($xvidController, $url), $url);
        }

        $this->assertSame('https://8.8.8.8/{locale}/api.php/provide/vod', $avdb->invoke($avdbController, 'https://8.8.8.8/{locale}/api.php/provide/vod'));
        $this->assertSame('https://8.8.8.8/api.php/provide/vod', $xvid->invoke($xvidController, 'https://8.8.8.8/api.php/provide/vod/'));

        // And through the schedule form.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson('/admin/avdb-import/schedules', [
            'api_base_url' => 'http://169.254.169.254/latest/meta-data/',
            'categories' => [1],
            'pages' => 1, 'start_page' => 1, 'pagesize' => 20, 'delay' => 0,
            'language_mode' => 'auto', 'thumbnail_mode' => 'remote', 'sort_direction' => 'desc',
            'translation_chunk' => 50, 'translation_concurrency' => 2,
            'schedule_frequency' => 'daily', 'schedule_time' => '03:00', 'schedule_enabled' => false,
        ])->assertSuccessful();

        $settings = \Illuminate\Support\Facades\DB::table('avdb_import_schedules')->latest('id')->value('settings');
        $this->assertSame('https://avdbapi.com/api.php/provide/vod', json_decode((string) $settings, true)['api_base_url'] ?? null);
    }

    public function test_tls_verification_stays_off_only_at_the_six_media_host_probes(): void
    {
        $sites = [];

        foreach (File::allFiles(app_path()) as $file) {
            foreach (preg_split('/\R/', (string) file_get_contents($file->getPathname())) as $i => $line) {
                if (str_contains($line, "'verify' => false")) {
                    $sites[] = str_replace('\\', '/', $file->getRelativePathname()).':'.trim($line);
                }
            }
        }

        $expected = [
            'Console/Commands/ImportAvdbVideos.php' => 1, // remote posters
            'Console/Commands/ImportXvidVideos.php' => 1, // remote posters
            'Support/RemoteVideoDuration.php' => 4,       // embed and m3u8 duration probes
            // The storage/ check (S03) asks this site itself for a random test file it just wrote, also through
            // 127.0.0.1 with the site's name, where an origin certificate (Cloudflare origin CA, a panel's own) does
            // not verify: a certificate error must not hide a server that hands out storage/. Nothing is sent.
            'Support/StorageExposureProbe.php' => 1,
            // The site's checks of its own web server (round 1b R1B-003 / R1B-007: clean addresses, nginx's internal
            // location for the videos) ask it the same way for a random file it just wrote; only that file's random body
            // counts as an answer, so a certificate error cannot forge one. Nothing is sent.
            'Support/ServerChecks.php' => 1,
        ];
        $counted = [];

        foreach ($sites as $site) {
            $file = strstr($site, ':', true);
            $counted[$file] = ($counted[$file] ?? 0) + 1;
        }

        ksort($counted);
        ksort($expected);
        $this->assertSame($expected, $counted, implode("\n", $sites));
    }
}
