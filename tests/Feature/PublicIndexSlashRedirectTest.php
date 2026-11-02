<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Security plan S10: the trailing-slash redirect of public/index.php (it runs before Laravel) always answers with a path
 * on this site. /index.php//evil.example/ used to answer "Location: //evil.example", which a browser follows to another
 * site. The real public/index.php runs behind PHP's built-in server with a router that does what nginx's
 * "try_files $uri $uri/ /index.php" does.
 */
class PublicIndexSlashRedirectTest extends TestCase
{
    private const ROUTER = <<<'PHP'
<?php
// try_files $uri $uri/ /index.php: an existing file is served as it is, every other address goes to public/index.php.
$public = getenv('AVD_PUBLIC');
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/' && $path !== '' && is_file($public.$path)) {
    return false;
}
$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
chdir($public);
require $public.'/index.php';
PHP;

    private static ?Process $server = null;

    private static string $url = '';

    private static string $dir = '';

    public static function redirects(): array
    {
        return [
            // An address that came in through /index.php keeps it (round 1b review BWR-06: on a server without rewrite
            // rules the clean address is the web server's 404 page); still always a path on this site.
            'index.php prefix and a second slash' => ['/index.php//evil.example/', '/index.php/evil.example'],
            'public prefix and a second slash' => ['/public//evil.example/', '/evil.example'],
            'index.php prefix, a second slash and a path' => ['/index.php//evil.example/x/', '/index.php/evil.example/x'],
            // A browser reads "/\host" as "//host": a raw backslash must never lead the answer.
            'index.php prefix and a backslash' => ['/index.php/\\evil.example/', '/index.php/evil.example'],
            'index.php prefix and two backslashes' => ['/index.php/\\\\evil.example/', '/index.php/evil.example'],
            // An encoded backslash stays encoded: a path on this site.
            'index.php prefix and an encoded backslash' => ['/index.php/%5Cevil.example/', '/index.php/%5Cevil.example'],
            'a page' => ['/trend/', '/trend'],
            'a page in a language' => ['/vi/trend/', '/vi/trend'],
            'the home page of a language' => ['/vi/', '/vi'],
            'a page with a query' => ['/trend/?a=1', '/trend?a=1'],
            'a page through index.php' => ['/index.php/trend/', '/index.php/trend'],
            'a page in a language through index.php' => ['/index.php/vi/trend/', '/index.php/vi/trend'],
            'the home page of a language through index.php' => ['/index.php/vi/', '/index.php/vi'],
            'a page with a query through index.php' => ['/index.php/trend/?a=1', '/index.php/trend?a=1'],
        ];
    }

    public static function realFolders(): array
    {
        return [
            'the public disk link' => ['/storage/'],
            'the public disk link through public/' => ['/public/storage/'],
            'the public disk link through index.php' => ['/index.php/storage/'],
            'the build folder' => ['/build/'],
        ];
    }

    /**
     * Round 1b review BWR-05: on the public-root nginx files /storage/ was sent to PHP (its location's try_files), PHP
     * took the slash off, nginx added it to the real folder again: 301 for ever. A real folder of public/ is never
     * redirected (Laravel answers it: 404).
     */
    #[DataProvider('realFolders')]
    public function test_a_real_folder_of_public_is_never_redirected(string $request): void
    {
        $this->startServer();

        $answer = $this->rawGet($request);

        $this->assertNotSame(301, $answer['status'], $request);
        $this->assertSame('', $answer['location'], $request);
    }

    #[DataProvider('redirects')]
    public function test_the_trailing_slash_redirect_stays_on_this_site(string $request, string $location): void
    {
        $this->startServer();

        $answer = $this->rawGet($request);

        $this->assertSame(301, $answer['status'], $request);
        $this->assertSame($location, $answer['location'], $request);
        $this->assertStringStartsWith('/', $answer['location']);
        $this->assertStringStartsNotWith('//', $answer['location']);
        $this->assertStringStartsNotWith('/\\', $answer['location']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop(3);
        self::$server = null;

        // The application is gone by now: plain PHP removes the router's folder.
        if (self::$dir !== '' && is_dir(self::$dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }

            @rmdir(self::$dir);
        }

        parent::tearDownAfterClass();
    }

    /** @return array{status: int, location: string} the status and Location of the answer (redirects not followed) */
    private function rawGet(string $path): array
    {
        $socket = fsockopen('127.0.0.1', (int) parse_url(self::$url, PHP_URL_PORT), $errno, $error, 10);
        $this->assertNotFalse($socket, 'connected: '.$error);
        stream_set_timeout($socket, 20);
        fwrite($socket, "GET {$path} HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $head = '';

        while (! feof($socket) && ! str_contains($head, "\r\n\r\n")) {
            $chunk = fread($socket, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $head .= $chunk;
        }

        fclose($socket);
        preg_match('#^HTTP/\S+\s+(\d{3})#', $head, $status);
        preg_match('#^Location:\s*(.*?)\r?$#mi', $head, $location);

        return ['status' => (int) ($status[1] ?? 0), 'location' => trim((string) ($location[1] ?? ''))];
    }

    private function startServer(): void
    {
        if (self::$server?->isRunning()) {
            return;
        }

        self::$dir = str_replace('\\', '/', storage_path('framework/testing/slash-redirect-'.Str::lower(Str::random(8))));
        File::ensureDirectoryExists(self::$dir);
        file_put_contents(self::$dir.'/router.php', self::ROUTER);

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        self::$url = 'http://127.0.0.1:'.$port;
        self::$server = new Process([PHP_BINARY, '-d', 'opcache.enable=0', '-S', '127.0.0.1:'.$port, self::$dir.'/router.php'],
            public_path(), ['AVD_PUBLIC' => str_replace('\\', '/', public_path())], null, null);
        self::$server->start();

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(100_000);
        }

        $this->fail('The test server did not start: '.self::$server->getErrorOutput());
    }
}
