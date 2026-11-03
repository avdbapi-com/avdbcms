<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Round 3 fix pass R2R-05: the two front controllers of a site installed in a folder of its domain
 * (https://example.com/site), run behind PHP's built-in server with routers that do what the web servers do:
 *  - public/index.php (Apache "Alias /site", nginx "location ^~ /site/"): the trailing-slash redirect stays in the folder
 *    and the folder's own home (/site/) is never redirected (1.2.3 RC2 sent /site/ to /site, which the web server sends
 *    back to /site/: a loop);
 *  - the project folder's index.php (the project folder unpacked into the domain's site/ folder, where the web server
 *    runs index.php for every address): /site/index.php/... hands the page and the folder to public/index.php, the
 *    project's closed paths stay closed in the folder (RC2 read /site/.env as a page of the site), the public/ files are
 *    sent, and at a domain's root everything is as before. This part runs a copy of index.php and
 *    app/Support/SiteAddress.php in a scratch folder, with a public/index.php that only shows what it was handed (the
 *    project folder's index.php writes into its folder: storage/, .user.ini, mirrored assets).
 * The real installs (Windows: PHP's server under /site; Linux: nginx, Apache Alias, the project folder in /proj) are in
 * lane SB's runs.
 */
class SubFolderFrontControllersTest extends TestCase
{
    private const PUBLIC_ROUTER = <<<'PHP'
<?php
// Apache "Alias /site <project>/public" / nginx "location ^~ /site/ { alias <project>/public/; ... }": every address of
// the folder runs public/index.php as /site/index.php (with its PATH_INFO when the address says /site/index.php/...).
$public = getenv('AVD_PUBLIC');
$folder = getenv('AVD_FOLDER');
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== $folder && ! str_starts_with($path, $folder.'/')) {
    http_response_code(404);
    echo 'the domain has no such page';
    return true;
}
$inner = (string) substr($path, strlen($folder));
$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
$_SERVER['SCRIPT_NAME'] = $folder.'/index.php';
$_SERVER['PHP_SELF'] = $folder.'/index.php';
unset($_SERVER['PATH_INFO']);
if (preg_match('#^/index\.php(/.*)$#', $inner, $m) === 1) {
    $_SERVER['PATH_INFO'] = $m[1];
    $_SERVER['PHP_SELF'] .= $m[1];
}
chdir($public);
require $public.'/index.php';
PHP;

    private const ROOT_ROUTER = <<<'PHP'
<?php
// A web server whose root holds the project folder as <folder>/ (or is the project folder: AVD_FOLDER ""), running the
// project folder's index.php for every address, as the server runs it: SCRIPT_NAME <folder>/index.php, PATH_INFO only
// for <folder>/index.php/... (PHP's own server sets it for every address of a router).
$root = getenv('AVD_ROOT');
$folder = (string) getenv('AVD_FOLDER');
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$_SERVER['SCRIPT_FILENAME'] = $root.'/index.php';
$_SERVER['SCRIPT_NAME'] = $folder.'/index.php';
$_SERVER['PHP_SELF'] = $folder.'/index.php';
unset($_SERVER['PATH_INFO']);
if (str_starts_with($path, $folder.'/index.php/')) {
    $_SERVER['PATH_INFO'] = (string) substr($path, strlen($folder.'/index.php'));
}
chdir($root);
require $root.'/index.php';
PHP;

    private const STUB = <<<'PHP'
<?php
// What the project folder's index.php hands to public/index.php.
header('Content-Type: application/json');
$keys = ['REQUEST_URI', 'SCRIPT_NAME', 'PHP_SELF', 'PATH_INFO', 'AVDBCLIP_USED_INDEX_PHP', 'AVDBCLIP_ROOT_FALLBACK'];
echo json_encode(array_combine($keys, array_map(static fn ($key) => $_SERVER[$key] ?? null, $keys)));
PHP;

    /** @var array<string, array{process: Process, port: int}> */
    private static array $servers = [];

    /** @var list<string> */
    private static array $dirs = [];

    /** @return array<string, array{0: string, 1: string}> request => Location */
    public static function folderRedirects(): array
    {
        return [
            'a page' => ['/site/trend/', '/site/trend'],
            'a page with a query' => ['/site/trend/?a=1', '/site/trend?a=1'],
            'a page in a language' => ['/site/fr/trend/', '/site/fr/trend'],
            'the home of a language' => ['/site/fr/', '/site/fr'],
            'through index.php' => ['/site/index.php/fr/trend/', '/site/index.php/fr/trend'],
            'never another site' => ['/site//evil.example/', '/site/evil.example'],
            'never another site through index.php' => ['/site/index.php//evil.example/', '/site/index.php/evil.example'],
            'never another site with a backslash' => ['/site/index.php/\\evil.example/', '/site/index.php/evil.example'],
        ];
    }

    #[DataProvider('folderRedirects')]
    public function test_the_trailing_slash_redirect_stays_in_the_folder(string $request, string $location): void
    {
        $answer = $this->askServer('public', $request);

        $this->assertSame(301, $answer['status'], $request);
        $this->assertSame($location, $answer['location'], $request);
    }

    public function test_the_folders_home_is_never_sent_to_the_address_without_its_slash(): void
    {
        // /site is the web server's: it adds the slash (Apache's DirectorySlash, the nginx file's "location = /site").
        foreach (['/site/', '/site/?x=1', '/site/index.php'] as $request) {
            $answer = $this->askServer('public', $request);

            $this->assertNotSame(301, $answer['status'], $request);
            $this->assertSame('', $answer['location'], $request);
        }
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function handedOver(): array
    {
        return [
            'a page through index.php' => ['/site/index.php/fr/trend?x=1', ['REQUEST_URI' => '/site/fr/trend?x=1', 'SCRIPT_NAME' => '/site/index.php', 'PHP_SELF' => '/site/index.php', 'PATH_INFO' => '/fr/trend', 'AVDBCLIP_USED_INDEX_PHP' => true, 'AVDBCLIP_ROOT_FALLBACK' => true]],
            'the folder\'s index.php' => ['/site/index.php', ['REQUEST_URI' => '/site/', 'SCRIPT_NAME' => '/site/index.php', 'PATH_INFO' => '', 'AVDBCLIP_USED_INDEX_PHP' => true]],
            'a clean page' => ['/site/fr/trend', ['REQUEST_URI' => '/site/fr/trend', 'SCRIPT_NAME' => '/site/index.php', 'PHP_SELF' => '/site/index.php', 'PATH_INFO' => null, 'AVDBCLIP_USED_INDEX_PHP' => null, 'AVDBCLIP_ROOT_FALLBACK' => true]],
            'the folder\'s home' => ['/site/', ['REQUEST_URI' => '/site/', 'SCRIPT_NAME' => '/site/index.php']],
        ];
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('handedOver')]
    public function test_the_project_folders_index_php_hands_the_page_and_the_folder_on(string $request, array $expected): void
    {
        $answer = $this->askServer('root-folder', $request);

        $this->assertSame(200, $answer['status'], $request.': '.$answer['body']);
        $handed = json_decode($answer['body'], true);
        $this->assertIsArray($handed, $answer['body']);

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $handed[$key] ?? null, $request.' '.$key);
        }
    }

    public function test_the_project_folders_closed_paths_stay_closed_in_the_folder(): void
    {
        foreach (['/site/.env', '/site/.env.backup', '/site/config/app.php', '/site/routes/web.php', '/site/vendor/autoload.php',
            '/site/composer.json', '/site/artisan', '/site/.git/config', '/site/index.php/.env', '/site/index.php/config/app.php'] as $request) {
            $this->assertSame(403, $this->askServer('root-folder', $request)['status'], $request);
        }
    }

    public function test_the_project_folders_index_php_sends_the_public_files_in_the_folder(): void
    {
        $css = $this->askServer('root-folder', '/site/build/app.css');
        $this->assertSame(200, $css['status']);
        $this->assertStringStartsWith('text/css', $css['type']);
        $this->assertSame('body{color:#123}', $css['body']);

        $upload = $this->askServer('root-folder', '/site/storage/notes/hello.txt');
        $this->assertSame(200, $upload['status']);
        $this->assertSame('hello from the public disk', $upload['body']);

        // never a file outside public/ or the public disk
        $this->assertNotSame('<?php', substr($this->askServer('root-folder', '/site/build/../index.php')['body'], 0, 5));
    }

    public function test_at_a_domains_root_the_project_folders_index_php_is_as_before(): void
    {
        $handed = json_decode($this->askServer('root-root', '/index.php/fr/trend?x=1')['body'], true);
        $this->assertSame(['REQUEST_URI' => '/fr/trend?x=1', 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php', 'PATH_INFO' => '/fr/trend',
            'AVDBCLIP_USED_INDEX_PHP' => true, 'AVDBCLIP_ROOT_FALLBACK' => true], $handed);

        $handed = json_decode($this->askServer('root-root', '/fr/trend')['body'], true);
        $this->assertSame('/fr/trend', $handed['REQUEST_URI'] ?? null);
        $this->assertSame('/index.php', $handed['SCRIPT_NAME'] ?? null);

        // a folder of the site's own pages named like the folder of a site in a folder changes nothing at the root
        $handed = json_decode($this->askServer('root-root', '/site/index.php')['body'], true);
        $this->assertSame('/site/index.php', $handed['REQUEST_URI'] ?? null);

        $this->assertSame(403, $this->askServer('root-root', '/.env')['status']);
        $this->assertSame(403, $this->askServer('root-root', '/config/app.php')['status']);
        $this->assertSame('body{color:#123}', $this->askServer('root-root', '/build/app.css')['body']);
    }

    /**
     * 1.2.3 refresh (the robots.txt edge lag of the nginx nodes): the project folder's index.php sends robots.txt (the
     * file the site writes when its SEO settings are saved) with the five-minute life its route and public/.htaccess
     * give it, at a domain's root and in a folder, as /robots.txt and as /index.php/robots.txt. Without a Cache-Control
     * a CDN kept the old text for its default hours. The other static files keep their lives (a built asset a month, an
     * uploaded file a day).
     */
    public function test_the_project_folders_index_php_sends_robots_txt_with_a_five_minute_life(): void
    {
        foreach (['root-root' => '', 'root-folder' => '/site'] as $server => $folder) {
            foreach ([$folder.'/robots.txt', $folder.'/index.php/robots.txt'] as $request) {
                $answer = $this->askServer($server, $request);

                $this->assertSame(200, $answer['status'], $request);
                $this->assertStringStartsWith('text/plain', $answer['type'], $request);
                $this->assertSame("User-agent: *\nAllow: /\n", $answer['body'], $request);
                $this->assertSame('public, max-age=300', $answer['cache'], $request.': five minutes, as the route');
            }

            $this->assertSame('public, max-age=2592000, immutable', $this->askServer($server, $folder.'/build/app.css')['cache'], $server.': a built asset');
            $this->assertSame('public, max-age=86400', $this->askServer($server, $folder.'/storage/notes/hello.txt')['cache'], $server.': an uploaded file');
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $server) {
            $server['process']->stop(3);
        }

        self::$servers = [];

        foreach (self::$dirs as $dir) {
            if (is_dir($dir)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                    $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }

                @rmdir($dir);
            }
        }

        self::$dirs = [];

        parent::tearDownAfterClass();
    }

    /** @return array{status: int, location: string, type: string, cache: string, body: string} */
    private function askServer(string $server, string $path): array
    {
        $port = $this->server($server);
        $socket = fsockopen('127.0.0.1', $port, $errno, $error, 10);
        $this->assertNotFalse($socket, 'connected: '.$error);
        stream_set_timeout($socket, 30);
        fwrite($socket, "GET {$path} HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $response = '';

        while (! feof($socket)) {
            $chunk = fread($socket, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $response .= $chunk;
        }

        fclose($socket);
        [$head, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        preg_match('#^HTTP/\S+\s+(\d{3})#', $head, $status);
        preg_match('#^Location:\s*(.*?)\r?$#mi', $head, $location);
        preg_match('#^Content-Type:\s*(.*?)\r?$#mi', $head, $type);
        preg_match('#^Cache-Control:\s*(.*?)\r?$#mi', $head, $cache);

        return ['status' => (int) ($status[1] ?? 0), 'location' => trim((string) ($location[1] ?? '')), 'type' => trim((string) ($type[1] ?? '')), 'cache' => trim((string) ($cache[1] ?? '')), 'body' => $body];
    }

    /** The port of the named test server, started on first use. */
    private function server(string $name): int
    {
        if (isset(self::$servers[$name]) && self::$servers[$name]['process']->isRunning()) {
            return self::$servers[$name]['port'];
        }

        $dir = str_replace('\\', '/', storage_path('framework/testing/sub-folder-front-'.$name.'-'.Str::lower(Str::random(8))));
        File::ensureDirectoryExists($dir);
        self::$dirs[] = $dir;

        if ($name === 'public') {
            file_put_contents($dir.'/router.php', self::PUBLIC_ROUTER);
            $env = ['AVD_PUBLIC' => str_replace('\\', '/', public_path()), 'AVD_FOLDER' => '/site'];
            $cwd = public_path();
        } else {
            // a scratch project folder: the project folder's index.php and the helper it loads, public/ with a front
            // controller that shows what it was handed, a built asset and a file of the public disk
            $project = $dir.'/project';
            File::ensureDirectoryExists($project.'/app/Support');
            File::ensureDirectoryExists($project.'/public/build');
            File::ensureDirectoryExists($project.'/storage/app/public/notes');
            File::ensureDirectoryExists($project.'/config');
            File::ensureDirectoryExists($project.'/routes');
            copy(base_path('index.php'), $project.'/index.php');
            copy(app_path('Support/SiteAddress.php'), $project.'/app/Support/SiteAddress.php');
            file_put_contents($project.'/public/index.php', self::STUB);
            file_put_contents($project.'/public/build/app.css', 'body{color:#123}');
            file_put_contents($project.'/public/robots.txt', "User-agent: *\nAllow: /\n");
            file_put_contents($project.'/storage/app/public/notes/hello.txt', 'hello from the public disk');
            file_put_contents($project.'/config/app.php', '<?php return [];');
            file_put_contents($project.'/routes/web.php', '<?php');
            file_put_contents($project.'/.env', 'APP_KEY=never-handed-out');
            file_put_contents($dir.'/router.php', self::ROOT_ROUTER);
            $env = ['AVD_ROOT' => $project, 'AVD_FOLDER' => $name === 'root-folder' ? '/site' : ''];
            $cwd = $project;
        }

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $socketName = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($socketName, strrpos($socketName, ':') + 1);
        $process = new Process([PHP_BINARY, '-d', 'opcache.enable=0', '-S', '127.0.0.1:'.$port, $dir.'/router.php'], $cwd, $env, null, null);
        $process->start();
        self::$servers[$name] = ['process' => $process, 'port' => $port];

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

            if ($socket !== false) {
                fclose($socket);

                return $port;
            }

            usleep(100_000);
        }

        $this->fail('The test server did not start: '.$process->getErrorOutput());
    }
}
