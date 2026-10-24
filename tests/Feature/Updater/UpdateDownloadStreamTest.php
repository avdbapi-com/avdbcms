<?php

namespace Tests\Feature\Updater;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * H4 (1.2.2): every answer of the download goes to disk as it arrives, never into memory. Played with a real HTTP
 * server (PHP's built-in server on 127.0.0.1) and the downloader in a second PHP whose memory_limit (24 MB) is far
 * below the file it downloads (48 MB): a proxy that ignores Range sends the whole update zip in one 200 answer.
 *
 *  - a 200 that ignores the Range header replaces the .part file from its first byte, streamed;
 *  - a 206 is appended after the bytes the .part file already has;
 *  - the body of a redirect (longer than the slice) leaves nothing in the file: each answer starts the landing file again;
 *  - an answer whose length cannot be the release's stops at its headers and the .part file stays as it was;
 *  - the control: reading the same answer into memory, as 1.2.1 did, does not fit in that memory_limit.
 *
 * The downloader's own client is used (its GitHub-only redirect rules); only the redirect test gives fetch() a client
 * that follows plain http redirects, because the test server has no https.
 */
class UpdateDownloadStreamTest extends TestCase
{
    private const SIZE = 48 * 1048576;

    private const LIMIT = '24M';

    private string $dir;

    private string $file;

    private string $url;

    private ?Process $server = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = str_replace('\\', '/', storage_path('framework/testing/updater-stream-'.Str::lower(Str::random(8))));
        File::ensureDirectoryExists($this->dir.'/site/download');
        $this->file = $this->dir.'/release.zip';
        $out = fopen($this->file, 'wb');

        for ($i = 0; $i < self::SIZE / 1048576; $i++) {
            fwrite($out, random_bytes(1048576));
        }

        fclose($out);
        file_put_contents($this->dir.'/router.php', self::ROUTER);
        file_put_contents($this->dir.'/child.php', self::CHILD);
        $this->startServer();
    }

    protected function tearDown(): void
    {
        $this->server?->stop(3);
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_a_200_that_ignores_range_replaces_the_part_file_streamed_from_its_first_byte(): void
    {
        $part = $this->dir.'/site/download/avdbcms-update-9.9.9.zip.part';
        file_put_contents($part, str_repeat('x', 1048576));

        $result = $this->fetchInSmallPhp('/whole', $part, 1048576, 4 * 1048576, self::SIZE);

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame([200, self::SIZE, self::SIZE, self::SIZE], [$result['status'], $result['bytes'], $result['done'], $result['total']]);
        $this->assertSame(hash_file('sha256', $this->file), hash_file('sha256', $part), 'the whole zip, from its first byte, not appended');
        $this->assertLessThan($this->bytes(self::LIMIT), $result['peak'], 'the answer never sat in memory');
        $this->assertFileDoesNotExist($part.'.slice');
    }

    public function test_a_206_is_appended_after_the_bytes_the_part_file_has(): void
    {
        $part = $this->dir.'/site/download/avdbcms-update-9.9.9.zip.part';
        $source = fopen($this->file, 'rb');
        file_put_contents($part, fread($source, 3 * 1048576));
        fclose($source);

        $result = $this->fetchInSmallPhp('/ranged', $part, 3 * 1048576, 16 * 1048576, self::SIZE);

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame([206, 16 * 1048576, 19 * 1048576, self::SIZE], [$result['status'], $result['bytes'], $result['done'], $result['total']]);
        $this->assertSame(19 * 1048576, filesize($part));
        $this->assertSame(hash('sha256', (string) file_get_contents($this->file, false, null, 0, 19 * 1048576)), hash_file('sha256', $part));
        $this->assertLessThan($this->bytes(self::LIMIT), $result['peak']);
        $this->assertFileDoesNotExist($part.'.slice');
    }

    public function test_the_body_of_a_redirect_leaves_nothing_in_the_file(): void
    {
        // GitHub answers the github.com address with a redirect; this one carries a body longer than the slice.
        $part = $this->dir.'/site/download/avdbcms-update-9.9.9.zip.part';

        $result = $this->fetchInSmallPhp('/hop?body=600000', $part, 0, 256 * 1024, self::SIZE, 'http-redirects');

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame([206, 256 * 1024], [$result['status'], $result['bytes']]);
        $this->assertSame(hash('sha256', (string) file_get_contents($this->file, false, null, 0, 256 * 1024)), hash_file('sha256', $part));
        $this->assertFileDoesNotExist($part.'.slice');
    }

    public function test_an_answer_of_the_wrong_length_stops_at_its_headers_and_the_part_file_stays(): void
    {
        $part = $this->dir.'/site/download/avdbcms-update-9.9.9.zip.part';
        file_put_contents($part, str_repeat('x', 1048576));

        $result = $this->fetchInSmallPhp('/whole', $part, 1048576, 4 * 1048576, self::SIZE + 1);

        $this->assertFalse($result['ok']);
        $this->assertSame('The download does not match the release. Try again later.', $result['message']);
        $this->assertSame('size '.self::SIZE.' instead of '.(self::SIZE + 1), $result['detail']);
        $this->assertSame(str_repeat('x', 1048576), file_get_contents($part), 'nothing written');
        $this->assertFileDoesNotExist($part.'.slice');
    }

    public function test_the_same_answer_read_into_memory_does_not_fit_that_memory_limit(): void
    {
        // The control: 1.2.1 read each answer with ->body(); with this memory_limit that dies, so the tests above
        // would have caught it.
        $process = $this->smallPhp(['body', $this->url.'/whole']);
        $process->run();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('Allowed memory size', $process->getOutput().$process->getErrorOutput());
    }

    /* ----------------------------------------------------------------- helpers */

    /** @return array<string, mixed> what the second PHP answered */
    private function fetchInSmallPhp(string $path, string $part, int $start, int $slice, ?int $expected, string $client = 'downloader'): array
    {
        $process = $this->smallPhp(['fetch', $this->url.$path, $part, (string) $start, (string) $slice, (string) ($expected ?? ''), $client]);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), 'the second PHP failed: '.$process->getErrorOutput().$process->getOutput());
        $result = json_decode(trim($process->getOutput()), true);
        $this->assertIsArray($result, 'the second PHP answered: '.$process->getOutput());

        return $result;
    }

    private function smallPhp(array $arguments): Process
    {
        return new Process([PHP_BINARY, '-d', 'memory_limit='.self::LIMIT, '-d', 'opcache.enable_cli=0', $this->dir.'/child.php', base_path(), ...$arguments], $this->dir, null, null, 120);
    }

    private function startServer(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        $this->url = 'http://127.0.0.1:'.$port;
        $this->server = new Process([PHP_BINARY, '-d', 'output_buffering=0', '-d', 'memory_limit=256M', '-S', '127.0.0.1:'.$port, $this->dir.'/router.php'],
            $this->dir, ['AVD_STREAM_FILE' => $this->file], null, null);
        $this->server->start();

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(100_000);
        }

        $this->fail('The test server did not start: '.$this->server->getErrorOutput());
    }

    private function bytes(string $limit): int
    {
        return (int) $limit * 1048576;
    }

    /** The test server: /whole ignores Range (200, the whole file), /ranged honours it (206), /hop redirects to /ranged. */
    private const ROUTER = <<<'PHP'
        <?php
        $file = (string) getenv('AVD_STREAM_FILE');
        $size = filesize($file);
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $send = function (int $from, int $length) use ($file): void {
            $in = fopen($file, 'rb');
            fseek($in, $from);

            while ($length > 0 && ! feof($in)) {
                $chunk = fread($in, min(65536, $length));
                $length -= strlen($chunk);
                echo $chunk;
                flush();
            }

            fclose($in);
        };

        if ($path === '/hop') {
            header('Location: /ranged', true, 302);
            header('Content-Type: text/html');
            echo str_repeat('r', (int) ($_GET['body'] ?? 0));

            return true;
        }

        if ($path === '/whole') {
            header('Content-Type: application/octet-stream');
            header('Content-Length: '.$size);
            $send(0, $size);

            return true;
        }

        if ($path === '/ranged' && preg_match('/^bytes=(\d+)-(\d+)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $range) === 1) {
            $from = (int) $range[1];
            $to = min((int) $range[2], $size - 1);
            http_response_code(206);
            header('Content-Type: application/octet-stream');
            header('Content-Range: bytes '.$from.'-'.$to.'/'.$size);
            header('Content-Length: '.($to - $from + 1));
            $send($from, $to - $from + 1);

            return true;
        }

        http_response_code(404);
        echo 'not found';
        PHP;

    /**
     * The second PHP: the downloader's fetch() with a minimal container (the HTTP client, a translator), or (body) the
     * same answer read into memory like 1.2.1 did. Prints JSON with its peak memory.
     */
    private const CHILD = <<<'PHP'
        <?php
        $base = $argv[1];
        $mode = $argv[2];
        require $base.'/vendor/autoload.php';
        $container = new Illuminate\Container\Container();
        Illuminate\Container\Container::setInstance($container);
        Illuminate\Support\Facades\Facade::setFacadeApplication($container);
        $container->instance(Illuminate\Http\Client\Factory::class, new Illuminate\Http\Client\Factory());
        $container->instance('translator', new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'));

        if ($mode === 'body') {
            $body = Illuminate\Support\Facades\Http::timeout(60)->get($argv[3])->body();
            echo json_encode(['bytes' => strlen($body), 'peak' => memory_get_peak_usage(true)]);

            return;
        }

        [, , , $url, $part, $start, $slice, $expected, $client] = $argv;
        $downloader = new App\Support\Updater\Downloader();
        $http = $client === 'http-redirects'
            ? Illuminate\Support\Facades\Http::withOptions(['decode_content' => false, 'allow_redirects' => ['max' => 3, 'protocols' => ['http']]])
            : (new ReflectionMethod($downloader, 'client'))->invoke($downloader);

        try {
            $answer = (new ReflectionMethod($downloader, 'fetch'))->invoke($downloader, $http, $url, $part, (int) $start, (int) $slice, $expected === '' ? null : (int) $expected, '9.9.9');
            $result = ['ok' => true] + $answer;
        } catch (App\Support\Updater\UpdateException $e) {
            $result = ['ok' => false, 'message' => $e->getMessage(), 'detail' => $e->detail];
        }

        echo json_encode($result + ['peak' => memory_get_peak_usage(true)]);
        PHP;
}
