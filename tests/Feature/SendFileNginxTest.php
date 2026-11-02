<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Support\Environment;
use App\Support\SendFile;
use App\Support\ServerChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * Round 1b R1B-003: on nginx every video stored on the site answered HTTP 500. "auto" picked X-Accel-Redirect and handed
 * nginx /_internal/videos/<file>, no shipped config had that internal location, nginx sent the address back to PHP
 * (try_files), PHP answered the same header again until nginx gave up ("rewrite or internal redirection cycle"). A site
 * without STREAM_ACCELERATOR in .env was even pinned to nginx by EnvironmentServiceProvider.
 *
 * Now nginx gets a video only after the site checked that its internal location works (ServerChecks::NGINX_STREAM);
 * until then, and after a check said no, PHP streams it (200, and 206 for a range). When nginx does send the address back
 * anyway (the location was removed later), that request streams with PHP and the check's answer turns to no.
 */
class SendFileNginxTest extends TestCase
{
    use RefreshDatabase;

    private string $state;

    private ?string $software;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(storage_path('framework/testing'));
        $this->state = storage_path('framework/testing/send-file-'.bin2hex(random_bytes(4)).'.json');
        ServerChecks::$stateFile = $this->state;
        ServerChecks::reset();
        $this->software = $_SERVER['SERVER_SOFTWARE'] ?? null;
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.24.0';
        Environment::reset();
        config(['site.stream_accelerator' => null]);

        $this->file = 'r1b003-'.bin2hex(random_bytes(6)).'.mp4';
        Storage::disk('local')->put(Video::VIDEO_FOLDER.'/'.$this->file, str_repeat('0123456789', 1000));
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->delete(Video::VIDEO_FOLDER.'/'.$this->file);
        @unlink($this->state);
        ServerChecks::$stateFile = null;
        ServerChecks::reset();

        if ($this->software === null) {
            unset($_SERVER['SERVER_SOFTWARE']);
        } else {
            $_SERVER['SERVER_SOFTWARE'] = $this->software;
        }

        Environment::reset();

        parent::tearDown();
    }

    public function test_auto_streams_with_php_until_the_internal_location_was_checked(): void
    {
        $this->assertSame(SendFile::MODE_NONE, SendFile::effectiveMode());

        $response = $this->send();

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertFalse($response->headers->has('X-Accel-Redirect'));
    }

    public function test_a_site_without_stream_accelerator_in_env_is_no_longer_pinned_to_nginx(): void
    {
        $method = new \ReflectionMethod(\App\Providers\EnvironmentServiceProvider::class, 'applyWebServerStreamingDefault');
        $method->invoke(new \App\Providers\EnvironmentServiceProvider($this->app));

        $this->assertSame(SendFile::MODE_AUTO, config('site.stream_accelerator'));
        $this->assertSame(SendFile::MODE_NONE, SendFile::effectiveMode());
    }

    public function test_auto_hands_the_file_to_nginx_once_the_check_said_the_location_works(): void
    {
        ServerChecks::record(ServerChecks::NGINX_STREAM, ServerChecks::YES, 'test');

        $response = $this->send();

        $accel = (string) $response->headers->get('X-Accel-Redirect');
        $this->assertMatchesRegularExpression('#^/_internal/videos/'.preg_quote($this->file, '#').'\?'.SendFile::LOOP_PARAMETER.'=[a-f0-9]{32}$#', $accel);
        $this->assertSame('', $response->getContent());
        // nginx passes this type on with the file (without it the answer carried Laravel's text/html).
        $this->assertSame(Video::MIMETYPE, $response->headers->get('Content-Type'));
    }

    public function test_when_nginx_sends_the_address_back_php_streams_the_file_and_stops_asking_nginx(): void
    {
        ServerChecks::record(ServerChecks::NGINX_STREAM, ServerChecks::YES, 'test');
        $accel = (string) $this->send()->headers->get('X-Accel-Redirect');
        parse_str((string) parse_url($accel, PHP_URL_QUERY), $query);

        // The same request again, as nginx's try_files hands it to index.php: the original address, nginx's query string.
        $loop = $this->send(Request::create('/video/file/'.$this->file, 'GET', $query, [], [], ['REQUEST_URI' => '/video/file/'.$this->file]));

        $this->assertInstanceOf(BinaryFileResponse::class, $loop);
        $this->assertSame(ServerChecks::NO, ServerChecks::answer(ServerChecks::NGINX_STREAM));
        $this->assertFalse($this->send()->headers->has('X-Accel-Redirect'), 'the next request streams with PHP at once');

        // A visitor's own query string can never make PHP believe that.
        ServerChecks::record(ServerChecks::NGINX_STREAM, ServerChecks::YES, 'test');
        $forged = $this->send(Request::create('/video/file/'.$this->file, 'GET', [SendFile::LOOP_PARAMETER => str_repeat('a', 32)]));
        $this->assertTrue($forged->headers->has('X-Accel-Redirect'));
    }

    public function test_an_explicit_nginx_is_honoured_once_the_check_did_not_find_it_failing(): void
    {
        // Round 1b review BWR-01: STREAM_ACCELERATOR=nginx sent X-Accel-Redirect before the site had checked anything, and
        // a server without the internal location and without try_files answered 404 for every video until the check ran.
        config(['site.stream_accelerator' => 'nginx', 'app.url' => 'https://site.test']);
        $this->assertSame(SendFile::MODE_NONE, SendFile::effectiveMode(), 'PHP until the first check (it runs after this answer)');
        $this->assertInstanceOf(BinaryFileResponse::class, $this->send());

        ServerChecks::record(ServerChecks::NGINX_STREAM, ServerChecks::YES, 'test');
        $this->assertSame(SendFile::MODE_NGINX, SendFile::effectiveMode());

        ServerChecks::$stateFile = $this->state.'.unknown';
        ServerChecks::reset();
        ServerChecks::record(ServerChecks::NGINX_STREAM, ServerChecks::UNKNOWN, 'the site cannot reach itself');
        $this->assertSame(SendFile::MODE_NGINX, SendFile::effectiveMode(), 'nobody can tell: the owner\'s choice');
        @unlink($this->state.'.unknown');
        ServerChecks::$stateFile = $this->state;
        ServerChecks::reset();

        ServerChecks::record(ServerChecks::NGINX_STREAM, ServerChecks::NO, 'test');
        $this->assertSame(SendFile::MODE_NONE, SendFile::effectiveMode());
        $this->assertInstanceOf(BinaryFileResponse::class, $this->send());
    }

    public function test_an_explicit_nginx_is_followed_where_the_site_cannot_check_itself(): void
    {
        // An installed site whose address is local checks itself (round 3 R3-040): before its check PHP sends the files.
        config(['site.stream_accelerator' => 'nginx', 'app.url' => 'http://localhost']);
        $this->assertSame(SendFile::MODE_NONE, SendFile::effectiveMode());

        // APP_URL still the example's localhost of an install in progress: no check can run, the setting counts as it is.
        $installed = $_ENV['APP_INSTALLED'] ?? null;
        $_ENV['APP_INSTALLED'] = 'false';
        ServerChecks::$installLock = storage_path('framework/testing/no-installer-lock-'.bin2hex(random_bytes(4)).'.json');

        try {
            $this->assertSame(SendFile::MODE_NGINX, SendFile::effectiveMode());

            ServerChecks::record(ServerChecks::NGINX_STREAM, ServerChecks::NO, 'Re-probe from the Health check');
            $this->assertSame(SendFile::MODE_NONE, SendFile::effectiveMode());
        } finally {
            if ($installed === null) {
                unset($_ENV['APP_INSTALLED']);
            } else {
                $_ENV['APP_INSTALLED'] = $installed;
            }

            ServerChecks::$installLock = null;
        }
    }

    public function test_a_public_video_plays_from_php_with_ranges(): void
    {
        $video = Video::factory()->forUser()->withStatus(VideoStatus::PUBLIC)->create(['file' => $this->file]);

        $whole = $this->get(route('video.file', $video));
        $whole->assertOk();
        $this->assertFalse($whole->headers->has('X-Accel-Redirect'));
        $this->assertSame('bytes', $whole->headers->get('Accept-Ranges'));

        $part = $this->get(route('video.file', $video), ['Range' => 'bytes=100-199']);
        $part->assertStatus(206);
        $this->assertSame('bytes 100-199/10000', $part->headers->get('Content-Range'));
        ob_start();
        $part->baseResponse->sendContent();
        $this->assertSame(str_repeat('0123456789', 10), ob_get_clean());
    }

    private function send(?Request $request = null): \Symfony\Component\HttpFoundation\Response
    {
        $request ??= Request::create('/video/file/'.$this->file, 'GET');

        return SendFile::stream(
            Storage::disk('local')->path(Video::VIDEO_FOLDER.'/'.$this->file),
            $request,
            ['Content-Type' => Video::MIMETYPE],
            Storage::disk('local')->path('')
        );
    }
}
