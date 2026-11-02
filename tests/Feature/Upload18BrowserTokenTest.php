<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Upload18Service;
use App\Support\Upload18\KeyInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Upload18 API V2.1 keys and tokens (1.2.3): the browser gets a short-lived upload token, never the site's key;
 * without a token it may get the key only when /keyinfo says it is a restricted Upload + Read key; every server call sends
 * the key in the Authorization: Bearer header; the Upload18 card warns about a key that is too powerful or too weak.
 */
class Upload18BrowserTokenTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'u18r_SITEkeyForTests0123456789abcdefABCDE';

    private const API = 'https://upload18.test/api';

    private const TOKEN = 'u18t_eyJ2IjoxLCJ1IjoiZXhhbXBsZSJ9.c2lnbmF0dXJl';

    /** What the fake Upload18 answers: uploadtoken => [status, json], keyinfo => [status, json] */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.upload18.api_key' => self::KEY,
            'services.upload18.api_url' => self::API,
            'services.upload18.default_cid' => '15',
            'services.upload18.default_mycid' => '0',
            'services.upload18.default_fid' => '18',
            'services.upload18.allow_server_fallback' => false,
            'site.allow_uploads' => true,
        ]);

        $this->answers = [
            'uploadtoken' => [200, ['status' => 'success', 'upload_token' => self::TOKEN, 'token_type' => 'upload', 'expires_in' => 21600, 'upload_url' => 'https://upload18.test/api/upload', 'limits' => ['cid' => 15]]],
            'keyinfo' => [200, ['status' => 'success', 'data' => ['type' => 'restricted', 'scopes' => ['upload', 'read']]]],
        ];

        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) {
            foreach (['uploadtoken', 'keyinfo'] as $endpoint) {
                if (str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?? '', '/'.$endpoint)) {
                    [$status, $json] = $this->answers[$endpoint];

                    return $json === null ? Http::response('', $status) : Http::response($json, $status);
                }
            }

            if (str_contains($request->url(), '/myvideo')) {
                return Http::response(['status' => 'success', 'data' => [['id' => 7, 'vid' => 'abc', 'zt' => '2', 'size_bytes' => 10]], 'total_page' => 1]);
            }

            return Http::response(['status' => 'success', 'data' => ['id' => 7, 'vid' => 'abc', 'zt' => '2']]);
        });
    }

    public function test_the_browser_gets_a_short_lived_upload_token_never_the_key(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('videos.upload18.config'))
            ->assertOk()
            ->assertJsonPath('upload_mode', 'direct')
            ->assertJsonPath('apikey', self::TOKEN)
            ->assertJsonPath('upload_url', 'https://upload18.test/api/upload')
            ->assertJsonPath('pending_url', route('videos.upload18.pending', [], false));

        $this->assertStringNotContainsString(self::KEY, $response->getContent());

        Http::assertSent(function (HttpRequest $request): bool {
            if (! str_ends_with($request->url(), '/uploadtoken')) {
                return false;
            }

            parse_str($request->body(), $fields);

            return $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer '.self::KEY)
                && ($fields['expires_in'] ?? null) === '21600'
                && ($fields['cid'] ?? null) === '15'
                && ($fields['fid'] ?? null) === '18'
                && ! array_key_exists('mycid', $fields) // 0 = no folder: nothing to lock
                && ! str_contains($request->body(), self::KEY)
                && ! str_contains($request->url(), self::KEY);
        });
        Http::assertNotSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/keyinfo'));
    }

    public function test_the_token_is_locked_to_the_sites_folder_when_one_is_set(): void
    {
        config(['services.upload18.default_mycid' => '139']);

        $this->actingAs(User::factory()->create())->getJson(route('videos.upload18.config'))->assertOk();

        Http::assertSent(function (HttpRequest $request): bool {
            parse_str($request->body(), $fields);

            return str_ends_with($request->url(), '/uploadtoken') && ($fields['mycid'] ?? null) === '139';
        });
    }

    public function test_without_a_token_a_restricted_upload_and_read_key_may_go_to_the_browser(): void
    {
        $this->answers['uploadtoken'] = [500, ['status' => 'error', 'msg' => 'temporarily unavailable']];

        $this->actingAs(User::factory()->create())
            ->getJson(route('videos.upload18.config'))
            ->assertOk()
            ->assertJsonPath('apikey', self::KEY)
            ->assertJsonPath('upload_url', self::API.'/upload');

        // An upload-only key is safe too.
        $this->answers['keyinfo'] = [200, ['status' => 'success', 'data' => ['type' => 'restricted', 'scopes' => ['upload']]]];
        config(['services.upload18.api_key' => self::KEY.'2']);

        $this->actingAs(User::factory()->create())
            ->getJson(route('videos.upload18.config'))
            ->assertOk()
            ->assertJsonPath('apikey', self::KEY.'2');
    }

    public function test_without_a_token_a_master_key_never_goes_to_the_browser(): void
    {
        $this->answers['uploadtoken'] = [503, null];
        $this->answers['keyinfo'] = [200, ['status' => 'success', 'data' => ['type' => 'master', 'scopes' => ['upload', 'read', 'edit', 'delete']]]];

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('videos.upload18.config'))
            ->assertStatus(503)
            ->assertJsonPath('error', 'Upload18 could not start the upload. Try again in a minute.')
            ->assertJsonMissingPath('apikey');

        $this->assertStringNotContainsString(self::KEY, $response->getContent());
    }

    public function test_without_a_token_a_key_that_can_edit_or_delete_never_goes_to_the_browser(): void
    {
        $this->answers['uploadtoken'] = [403, ['status' => 'error', 'error_code' => 'insufficient_scope']];

        foreach ([['upload', 'read', 'delete'], ['upload', 'read', 'edit'], ['upload', 'read', 'admin']] as $i => $scopes) {
            $this->answers['keyinfo'] = [200, ['status' => 'success', 'data' => ['type' => 'restricted', 'scopes' => $scopes]]];
            config(['services.upload18.api_key' => self::KEY.$i]);

            $response = $this->actingAs(User::factory()->create())
                ->getJson(route('videos.upload18.config'))
                ->assertStatus(503)
                ->assertJsonMissingPath('apikey');

            $this->assertStringNotContainsString(self::KEY, $response->getContent());
        }
    }

    public function test_without_a_token_and_without_keyinfo_nothing_goes_to_the_browser(): void
    {
        $this->answers['uploadtoken'] = [200, ['status' => 'success', 'upload_token' => '']];
        $this->answers['keyinfo'] = [500, null];

        $this->actingAs(User::factory()->create())
            ->getJson(route('videos.upload18.config'))
            ->assertStatus(503)
            ->assertJsonMissingPath('apikey');
    }

    public function test_keyinfo_is_asked_about_once_a_day_per_key(): void
    {
        $this->answers['uploadtoken'] = [500, null];
        $member = User::factory()->create();

        $this->actingAs($member)->getJson(route('videos.upload18.config'))->assertOk();
        $this->actingAs($member)->getJson(route('videos.upload18.config'))->assertOk();
        $this->assertSame(1, $this->sent('/keyinfo'));

        $this->travel(25)->hours();
        $this->actingAs($member)->getJson(route('videos.upload18.config'))->assertOk();
        $this->assertSame(2, $this->sent('/keyinfo'));

        // Another key is asked about at once.
        config(['services.upload18.api_key' => self::KEY.'X']);
        $this->actingAs($member)->getJson(route('videos.upload18.config'))->assertOk();
        $this->assertSame(3, $this->sent('/keyinfo'));
    }

    public function test_every_server_call_sends_the_key_as_a_bearer_header_only(): void
    {
        Storage::fake('local');
        $file = storage_path('framework/testing/upload18-bearer-test.mp4');
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, str_repeat("\0", 64));

        try {
            $service = app(Upload18Service::class);
            $service->listVideos(1, 10);
            $service->listVideosStrict(1, 10);
            $service->findVideoByDid('7', 10, 1);
            $service->findItems(['7'], 10, 1);
            $service->statusForDid('7');
            $service->getVideoDetail('abc');
            $service->itemByVid('abc', '7');
            $service->updateVideo('abc', ['name' => 'Renamed', 'apikey' => 'never-sent']);
            $service->deleteVideo('abc');
            $service->upload($file, 'Bearer test');
            $service->mintUploadToken(['cid' => 15], 600);
            $service->keyInfo();
        } finally {
            @unlink($file);
        }

        $recorded = Http::recorded();
        $this->assertGreaterThanOrEqual(12, $recorded->count());

        foreach ($recorded as [$request]) {
            /** @var HttpRequest $request */
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.self::KEY), 'Bearer header on '.$request->url());
            $this->assertStringNotContainsString('apikey', $request->url());
            $this->assertStringNotContainsString(self::KEY, $request->url());

            if ($request->isMultipart()) {
                // The file upload: its parts are the category, folder, server and the video, never a key.
                $parts = array_column($request->data(), 'name');
                sort($parts);
                $this->assertSame(['cid', 'fid', 'mycid', 'video'], $parts);

                continue;
            }

            $this->assertStringNotContainsString('apikey', $request->body());
            $this->assertStringNotContainsString(self::KEY, $request->body());
        }
    }

    public function test_the_upload18_card_warns_about_a_key_that_is_too_powerful_or_too_weak(): void
    {
        $cases = [
            'master' => [[200, ['status' => 'success', 'data' => ['type' => 'master', 'scopes' => ['upload', 'read', 'edit', 'delete']]]], 'This is your Upload18 master key.'],
            'delete' => [[200, ['status' => 'success', 'data' => ['type' => 'restricted', 'scopes' => ['upload', 'read', 'delete']]]], 'This key can also edit or delete your Upload18 videos.'],
            'no-read' => [[200, ['status' => 'success', 'data' => ['type' => 'restricted', 'scopes' => ['upload']]]], 'This key cannot read your video list'],
            'no-upload' => [[200, ['status' => 'success', 'data' => ['type' => 'restricted', 'scopes' => ['read']]]], 'This key cannot upload videos.'],
            'refused' => [[401, ['status' => 'error', 'error_code' => 'invalid_api_key']], 'Upload18 does not accept this key.'],
            'token' => [[200, ['status' => 'success', 'data' => ['type' => 'upload_token', 'scopes' => ['upload']]]], 'This is a short-lived upload token, not a key.'],
        ];

        foreach ($cases as $name => [$answer, $text]) {
            $this->answers['keyinfo'] = $answer;
            config(['services.upload18.api_key' => self::KEY.'-'.$name]);

            $html = view('admin.settings.extras.card-settings-videos-upload18')->render();

            $this->assertStringContainsString('Check your Upload18 key', $html, $name);
            $this->assertStringContainsString($text, $html, $name);
            $this->assertStringNotContainsString(self::KEY, $html, $name);
        }

        $this->answers['keyinfo'] = [200, ['status' => 'success', 'data' => ['type' => 'restricted', 'scopes' => ['read', 'upload']]]];
        config(['services.upload18.api_key' => self::KEY.'-good']);
        $html = view('admin.settings.extras.card-settings-videos-upload18')->render();
        $this->assertStringNotContainsString('Check your Upload18 key', $html);
        $this->assertStringContainsString('Key checked: a restricted key with Upload + Read.', $html);

        // Upload18 could not be asked: no warning, no claim either way.
        $this->answers['keyinfo'] = [502, null];
        config(['services.upload18.api_key' => self::KEY.'-down']);
        $html = view('admin.settings.extras.card-settings-videos-upload18')->render();
        $this->assertStringNotContainsString('Check your Upload18 key', $html);
        $this->assertStringNotContainsString('Key checked', $html);

        // No key saved: nothing to check, nothing asked.
        config(['services.upload18.api_key' => '']);
        $calls = Http::recorded()->count();
        $this->assertSame('', trim(view('admin.settings.extras.card-settings-videos-upload18')->render()));
        $this->assertSame($calls, Http::recorded()->count());
    }

    /**
     * The settings page never waits for Upload18 once the key was checked: the card shows the last answer and asks again
     * after the page was sent. Only a key never checked before (the owner just saved it) is asked while the page is made.
     */
    public function test_the_upload18_card_never_waits_for_upload18_once_the_key_was_checked(): void
    {
        config(['services.upload18.api_key' => self::KEY.'-card']);
        $card = fn (): string => view('admin.settings.extras.card-settings-videos-upload18')->render();

        // A key never checked before: asked now, so the owner sees at once what the new key may do.
        $this->assertStringContainsString('Key checked: a restricted key with Upload + Read.', $card());
        $this->assertSame(1, $this->sent('/keyinfo'));

        // The same day: the kept answer, nothing asked.
        $this->assertStringContainsString('Key checked', $card());
        $this->app->terminate();
        $this->assertSame(1, $this->sent('/keyinfo'));

        // A day later the key became a master key on Upload18: the page still shows the last answer at once ...
        $this->travel(25)->hours();
        $this->answers['keyinfo'] = [200, ['status' => 'success', 'data' => ['type' => 'master', 'scopes' => ['upload', 'read', 'edit', 'delete']]]];
        $this->assertStringContainsString('Key checked', $card());
        $this->assertSame(1, $this->sent('/keyinfo'), 'nothing is asked while the page is made');

        // ... and Upload18 is asked once the page was sent; the next page shows the new answer.
        $this->app->terminate();
        $this->assertSame(2, $this->sent('/keyinfo'));
        $this->assertStringContainsString('This is your Upload18 master key.', $card());
        $this->app->terminate();
        $this->assertSame(2, $this->sent('/keyinfo'));

        // A members' upload start never uses an answer older than a day (it decides whether the key may go to a browser).
        $this->answers['uploadtoken'] = [500, null];
        $this->travel(25)->hours();
        $this->answers['keyinfo'] = [200, ['status' => 'success', 'data' => ['type' => 'restricted', 'scopes' => ['upload', 'read']]]];
        $this->actingAs(User::factory()->create())
            ->getJson(route('videos.upload18.config'))
            ->assertOk()
            ->assertJsonPath('apikey', self::KEY.'-card');
        $this->assertSame(3, $this->sent('/keyinfo'));
    }

    /** The token travels with every piece of the file: its upload address is https (http only for an http test API). */
    public function test_an_upload_address_that_is_not_https_never_reaches_the_browser(): void
    {
        $this->answers['uploadtoken'][1]['upload_url'] = 'http://upload18.test/api/upload';

        $this->actingAs(User::factory()->create())
            ->getJson(route('videos.upload18.config'))
            ->assertOk()
            ->assertJsonPath('apikey', self::TOKEN)
            ->assertJsonPath('upload_url', self::API.'/upload');

        $this->answers['uploadtoken'][1]['upload_url'] = 'javascript:alert(1)';
        $this->actingAs(User::factory()->create())
            ->getJson(route('videos.upload18.config'))
            ->assertOk()
            ->assertJsonPath('upload_url', self::API.'/upload');

        $this->answers['uploadtoken'][1]['upload_url'] = 'https://s2.upload18.test/api/upload';
        $this->actingAs(User::factory()->create())
            ->getJson(route('videos.upload18.config'))
            ->assertOk()
            ->assertJsonPath('upload_url', 'https://s2.upload18.test/api/upload');

        // A site whose own Upload18 address is http (a local test server) may use an http upload address.
        config(['services.upload18.api_url' => 'http://127.0.0.1:8189/api']);
        $this->answers['uploadtoken'][1]['upload_url'] = 'http://127.0.0.1:8189/api/upload';
        $this->actingAs(User::factory()->create())
            ->getJson(route('videos.upload18.config'))
            ->assertOk()
            ->assertJsonPath('upload_url', 'http://127.0.0.1:8189/api/upload');
    }

    public function test_keyinfo_answers_are_read_safely(): void
    {
        $restricted = KeyInfo::fromAnswer(['status' => 'success', 'data' => ['type' => 'restricted', 'scopes' => ['upload', 'read']]]);
        $this->assertTrue($restricted->allowsBrowserFallback());
        $this->assertSame([], $restricted->problems());
        $this->assertTrue($restricted->isRecommended());

        $text = KeyInfo::fromAnswer(['data' => ['type' => 'restricted', 'permissions' => 'upload, read , delete']]);
        $this->assertFalse($text->allowsBrowserFallback());
        $this->assertSame(['edit_delete'], $text->problems());

        $map = KeyInfo::fromAnswer(['data' => ['type' => 'Restricted', 'scopes' => ['upload' => true, 'read' => '1', 'edit' => false]]]);
        $this->assertTrue($map->allowsBrowserFallback());

        $star = KeyInfo::fromAnswer(['data' => ['scopes' => ['*']]]);
        $this->assertTrue($star->isMaster());
        $this->assertFalse($star->allowsBrowserFallback());

        $this->assertSame(['invalid'], KeyInfo::fromAnswer(null, 401)->problems());
        $this->assertSame(['invalid'], KeyInfo::fromAnswer(['status' => 'error', 'error_code' => 'invalid_api_key'])->problems());
        $this->assertSame(KeyInfo::UNKNOWN, KeyInfo::fromAnswer('<html>')->status);
        $this->assertSame(KeyInfo::UNKNOWN, KeyInfo::fromAnswer(['status' => 'success', 'data' => []])->status);
        $this->assertFalse(KeyInfo::fromAnswer(['status' => 'success', 'data' => ['type' => 'restricted']])->allowsBrowserFallback());
        $this->assertSame(['token'], KeyInfo::fromAnswer(['data' => ['type' => 'upload_token', 'scopes' => ['upload']]])->problems());
    }

    private function sent(string $endpoint): int
    {
        return Http::recorded(fn (HttpRequest $request): bool => str_ends_with($request->url(), $endpoint))->count();
    }
}
