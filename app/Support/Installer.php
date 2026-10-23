<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

class Installer
{
    public const string BACKUP_FILENAME = 'example.sql';

    /**
     * @return array<int, array{key:string,label:string}>
     */
    public function steps(): array
    {
        return [
            ['key' => 'introduction', 'label' => 'Introduction'],
            ['key' => 'requirements', 'label' => 'Requirements'],
            ['key' => 'filesystem', 'label' => 'Filesystem'],
            ['key' => 'database', 'label' => 'Database'],
            ['key' => 'admin', 'label' => 'Admin Account'],
            ['key' => 'install', 'label' => 'Install'],
        ];
    }

    public function isInstalled(): bool
    {
        if ($this->envFlag('INSTALLER_ALLOW_REINSTALL')) {
            return false;
        }

        if ($this->hasExplicitFalseEnvFlag('APP_INSTALLED')) {
            return false;
        }

        if (is_file($this->lockPath()) || $this->envFlag('APP_INSTALLED')) {
            return true;
        }

        try {
            if (Schema::hasTable('users') && User::query()->where('is_admin', true)->exists()) {
                return true;
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    public function lockPath(): string
    {
        return storage_path('app/installed.json');
    }

    public function backupPath(): ?string
    {
        foreach ($this->backupCandidatePaths() as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array{exists:bool,path:?string,filename:string,configured_filename:string,size:string,source:string,available:array<int, string>}
     */
    public function backupInfo(): array
    {
        $path = $this->backupPath();
        $configuredFilename = $this->configuredBackupFilename();

        return [
            'exists' => $path !== null,
            'path' => $path,
            'filename' => $path ? basename($path) : $configuredFilename,
            'configured_filename' => $configuredFilename,
            'size' => $path ? $this->formatBytes((int) filesize($path)) : 'Not bundled',
            'source' => $path ? $this->backupSource($path) : 'No SQL file found',
            'available' => $this->availableSqlBackups(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function backupCandidatePaths(): array
    {
        $paths = [];
        $preferred = array_values(array_unique(array_filter([
            $this->configuredBackupFilename(),
            self::BACKUP_FILENAME,
        ])));

        foreach ($preferred as $filename) {
            $paths[] = database_path('install/'.$filename);
            $paths[] = storage_path('app/backups/'.$filename);
        }

        foreach ([database_path('install'), storage_path('app/backups')] as $directory) {
            foreach ($this->sqlFilesIn($directory) as $path) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    private function configuredBackupFilename(): string
    {
        $value = trim((string) env('INSTALL_SQL_BACKUP', ''));
        if ($value === '') {
            $value = $this->envExampleValue('INSTALL_SQL_BACKUP');
        }

        $filename = basename(str_replace('\\', '/', $value));

        return $filename !== '' ? $filename : self::BACKUP_FILENAME;
    }

    /**
     * @return array<int, string>
     */
    private function availableSqlBackups(): array
    {
        $available = [];

        foreach ([database_path('install'), storage_path('app/backups')] as $directory) {
            foreach ($this->sqlFilesIn($directory) as $path) {
                $available[] = $this->relativePath($path);
            }
        }

        return $available;
    }

    /**
     * @return array<int, string>
     */
    private function sqlFilesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.sql') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files;
    }

    private function backupSource(string $path): string
    {
        return $this->relativePath($path);
    }

    private function envExampleValue(string $key): string
    {
        $examplePath = base_path('.env.example');
        if (! is_readable($examplePath)) {
            return '';
        }

        try {
            $parsed = \Dotenv\Dotenv::parse((string) file_get_contents($examplePath));

            return trim((string) ($parsed[$key] ?? ''));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @return array<string, string|bool>
     */
    public function environmentSummary(): array
    {
        $documentRoot = $this->normalizedRealPath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $publicPath = $this->normalizedRealPath(public_path());
        $basePath = $this->normalizedRealPath(base_path());
        $openBasedir = Environment::openBasedirPaths();

        return [
            'os' => Environment::osFamily(),
            'sapi' => Environment::sapi(),
            'web_server' => Environment::webServerLabel(),
            'panel' => Environment::panelLabel(),
            'document_root' => $documentRoot !== '' ? $documentRoot : 'Not reported by server',
            'project_root' => $basePath,
            'public_path' => $publicPath,
            'public_document_root' => $documentRoot !== '' && $publicPath !== '' && $documentRoot === $publicPath,
            'open_basedir' => $openBasedir === [] ? 'Not restricted' : implode(PATH_SEPARATOR, $openBasedir),
        ];
    }

    /**
     * @return array<int, array{label:string,ok:bool,detail:string,help:string}>
     */
    public function serverGuidance(): array
    {
        $environment = $this->environmentSummary();
        $webServer = Environment::webServer();
        $publicRoot = (bool) $environment['public_document_root'];
        $rootHtaccessWorks = in_array($webServer, [
            Environment::WEB_APACHE,
            Environment::WEB_LITESPEED,
            Environment::WEB_OPENLITESPEED,
        ], true);

        return [
            [
                'label' => 'Detected web server',
                'ok' => $webServer !== Environment::WEB_UNKNOWN,
                'detail' => (string) $environment['web_server'],
                'help' => 'Detected from server headers, SAPI, and known panel paths. If this is wrong, the app still uses conservative Laravel defaults.',
            ],
            [
                'label' => 'Detected control panel',
                'ok' => true,
                'detail' => (string) $environment['panel'],
                'help' => 'Panel detection is used for advice only. Install does not depend on panel-specific APIs.',
            ],
            [
                'label' => 'Document root',
                'ok' => $publicRoot || $rootHtaccessWorks || is_file(base_path('index.php')),
                'detail' => $publicRoot
                    ? 'Pointing to public/'
                    : (is_file(base_path('index.php'))
                        ? 'Using root index.php fallback'
                        : (string) $environment['document_root']),
                'help' => $publicRoot
                    ? 'Best setup. Static files and Laravel entrypoint are served from public/.'
                    : (is_file(base_path('index.php'))
                        ? 'The root index.php fallback forwards requests that reach PHP to public/index.php. Nginx clean URLs still need the try_files rewrite; without it, Nginx returns 404 before PHP runs. For best performance, set the Running Directory to /public in your panel.'
                        : ($rootHtaccessWorks
                            ? 'Apache/LiteSpeed can use the bundled root .htaccess fallback. Best production setup is still to point the domain to public/.'
                            : 'Nginx, Caddy, IIS, and most reverse proxies must point the site root to public/ or use the shown rewrite snippet. They do not read .htaccess.')),
            ],
            [
                'label' => 'Laravel routing',
                'ok' => true,
                'detail' => '/index.php/install reached Laravel',
                'help' => 'The current request reached the Laravel router. If other URLs fail after install, update the web-server rewrite rule for your stack.',
            ],
        ];
    }

    public function serverConfigSnippet(): string
    {
        $public = str_replace('\\', '/', public_path());
        $base = str_replace('\\', '/', base_path());

        return match (Environment::webServer()) {
            Environment::WEB_NGINX => <<<CONF
server {
    listen 80;
    server_name example.com www.example.com;
    root {$public};
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
CONF,
            Environment::WEB_CADDY => <<<CONF
example.com {
    root * {$public}
    php_fastcgi unix//run/php/php8.3-fpm.sock
    file_server

    @hidden path /.env /.git/* /storage/* /vendor/* /config/*
    respond @hidden 404
}
CONF,
            Environment::WEB_IIS => <<<CONF
Use the bundled public/web.config when the IIS site root is:
{$public}

If the IIS site must point to the project root, keep the bundled root web.config:
{$base}
CONF,
            Environment::WEB_APACHE, Environment::WEB_LITESPEED, Environment::WEB_OPENLITESPEED => <<<CONF
Best setup:
DocumentRoot "{$public}"

Shared-host fallback:
The bundled root .htaccess forwards requests to public/.
CONF,
            default => <<<CONF
Set the domain document root to:
{$public}

Front-controller rule:
try_files / request path -> /index.php with the original query string.
CONF,
        };
    }

    /**
     * @return array<string, string>
     */
    public function appDefaults(string $detectedUrl): array
    {
        return [
            'name' => (string) RuntimeEnv::get('APP_NAME', 'AVDBCMS'),
            'url' => $detectedUrl,
            'mail_mailer' => (string) RuntimeEnv::get('MAIL_MAILER', 'log'),
            'mail_host' => (string) RuntimeEnv::get('MAIL_HOST', ''),
            'mail_port' => (string) RuntimeEnv::get('MAIL_PORT', '587'),
            'mail_username' => (string) RuntimeEnv::get('MAIL_USERNAME', ''),
            'mail_password' => (string) RuntimeEnv::get('MAIL_PASSWORD', ''),
            'mail_encryption' => (string) RuntimeEnv::get('MAIL_ENCRYPTION', ''),
            'mail_from_address' => (string) RuntimeEnv::get('MAIL_FROM_ADDRESS', ''),
            'upload18_api_key' => (string) RuntimeEnv::get('UPLOAD18_API_KEY', ''),
            'upload18_api_url' => (string) RuntimeEnv::get('UPLOAD18_API_URL', 'https://upload18.org/api'),
            'upload18_default_cid' => (string) RuntimeEnv::get('UPLOAD18_DEFAULT_CID', '15'),
            'upload18_default_mycid' => (string) RuntimeEnv::get('UPLOAD18_DEFAULT_MYCID', '0'),
            'upload18_default_fid' => (string) RuntimeEnv::get('UPLOAD18_DEFAULT_FID', '18'),
            'upload18_allow_server_fallback' => (string) RuntimeEnv::get('UPLOAD18_ALLOW_SERVER_FALLBACK', 'false'),
        ];
    }

    public function schedulerCronCommand(): string
    {
        $php = PhpBinary::resolve(['CRON_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']) ?: 'php';

        return 'cd '.escapeshellarg(base_path())
            .' && '.escapeshellarg($php).' artisan schedule:run >> '
            .escapeshellarg(storage_path('logs/schedule-run.log')).' 2>&1';
    }

    /**
     * @return array<int, array{label:string,ok:bool,required:bool,detail:string,help:string,fix:string,action:string,auto_fix:bool,docs:string,severity:string}>
     */
    public function requirements(): array
    {
        $requiredExtensions = [
            'ctype' => 'Required by Laravel string validation and framework internals.',
            'curl' => 'Required for AVDB API requests, remote thumbnails, webhooks, and upload provider calls.',
            'dom' => 'Required by XML/HTML parsing dependencies.',
            'filter' => 'Required for URL, email, and input validation.',
            'hash' => 'Required for Laravel security, sessions, and signed values.',
            'json' => 'Required for API responses, settings, queues, and installer state.',
            'mbstring' => 'Required for UTF-8 titles, performer names, search, and validation.',
            'openssl' => 'Required for HTTPS requests, encrypted values, and secure keys.',
            'pdo' => 'Required for database access.',
            'pdo_mysql' => 'Required for MySQL/MariaDB database access.',
            'session' => 'Required for login, installer steps, and CSRF protection.',
            'tokenizer' => 'Required by Laravel, Blade, and package discovery.',
            'xml' => 'Required by XML parser dependencies.',
        ];

        $checks = [
            $this->check(
                'PHP 8.3 or newer',
                version_compare(PHP_VERSION, '8.3.0', '>='),
                true,
                PHP_VERSION,
                'Required by composer.json and Laravel 12.',
                'Change the domain PHP version to 8.3 or 8.4 in your hosting panel. Make sure the web PHP and CLI PHP use the same version.',
                'Switch PHP version in panel',
                false,
                'cPanel: MultiPHP Manager. Plesk: PHP Settings. DirectAdmin: Domain Setup. aaPanel/CyberPanel: Website PHP version. VPS: install php8.3-fpm and point the site to it.'
            ),
        ];

        foreach ($requiredExtensions as $extension => $reason) {
            $loaded = extension_loaded($extension);
            $checks[] = $this->check(
                'PHP extension: '.$extension,
                $loaded,
                true,
                $loaded ? 'Loaded' : 'Missing',
                $reason,
                'Enable the '.$extension.' extension for this domain PHP version, then restart PHP-FPM/LiteSpeed/Apache if your panel does not reload it automatically.',
                'Enable PHP extension',
                false,
                'Shared hosting: use Select PHP Version, PHP Extensions, MultiPHP INI Editor, or PHP Settings. VPS: install the matching php8.3-'.$extension.' package when available.'
            );
        }

        $bcmathLoaded = extension_loaded('bcmath');
        $checks[] = $this->check(
            'PHP extension: bcmath',
            $bcmathLoaded,
            false,
            $bcmathLoaded ? 'Loaded' : 'Missing (optional fallback enabled)',
            'Recommended for high-precision math helpers. When missing, native PHP floating-point operations are used.',
            'Enable the bcmath extension in your hosting panel when available. Installation can continue normally without it.',
            'Enable PHP extension when possible',
            false,
            '1Panel / minimal Docker PHP: Some minimal containers omit bcmath. The app functions normally without it.'
        );

        $fileinfoLoaded = extension_loaded('fileinfo');
        $checks[] = $this->check(
            'PHP extension: fileinfo',
            $fileinfoLoaded,
            false,
            $fileinfoLoaded ? 'Loaded' : 'Missing (optional fallback enabled)',
            'Recommended for upload MIME detection and media validation. When missing, built-in extension-based detection is used.',
            'Enable fileinfo in your hosting panel when available. On low-memory VPSs (such as default aaPanel), fileinfo is optional and the application runs normally without it.',
            'Enable PHP extension when possible',
            false,
            'aaPanel / low-RAM VPS: aaPanel omits fileinfo by default because compiling it requires >1GB RAM. The app provides built-in fallback MIME detection so you can proceed.'
        );

        $zipLoaded = extension_loaded('zip');
        $checks[] = $this->check(
            'PHP extension: zip',
            $zipLoaded,
            false,
            $zipLoaded ? 'Loaded' : 'Missing',
            'Recommended for ZIP exports, some backup workflows, and spreadsheet/package handling.',
            'Enable the zip extension for this domain PHP version when your host allows it. Installation can continue without it because the app is already unzipped and the SQL importer does not require ZipArchive.',
            'Enable PHP extension when possible',
            false,
            'Shared hosting: use Select PHP Version, PHP Extensions, MultiPHP INI Editor, or PHP Settings. VPS: install the matching php8.3-zip package when available.'
        );

        $vendorReady = is_file(base_path('vendor/autoload.php'));
        $checks[] = $this->check(
            'Composer dependencies',
            $vendorReady,
            true,
            $vendorReady ? 'vendor/autoload.php found' : 'vendor/autoload.php missing',
            'The release zip should already include Composer dependencies.',
            'Upload the complete community package again. If you are installing from source, run composer install --no-dev --optimize-autoloader with PHP 8.3+.',
            'Upload full package or run Composer',
            false,
            'Many shared hosts do not allow Composer. For those users, the packaged zip must include vendor/.'
        );

        $assetsReady = is_file(public_path('build/manifest.json'));
        $checks[] = $this->check(
            'Frontend assets',
            $assetsReady,
            true,
            $assetsReady ? 'public/build ready' : 'public/build missing',
            'The release zip should already include compiled Vite assets.',
            'Upload the complete community package again. If you are packaging from source, run npm install and npm run build before creating the zip.',
            'Upload full package or build assets',
            false,
            'The installer cannot compile assets on most shared hosts because Node.js is usually unavailable.'
        );

        $pdoDrivers = [];
        $pdoDetail = 'PDO extension is not loaded';
        if (class_exists(PDO::class)) {
            try {
                $pdoDrivers = PDO::getAvailableDrivers();
                $pdoDetail = implode(', ', $pdoDrivers) ?: 'No PDO drivers';
            } catch (Throwable $e) {
                $pdoDetail = 'Could not read PDO drivers: '.$e->getMessage();
            }
        }

        $mysqlReady = in_array('mysql', $pdoDrivers, true);
        $checks[] = $this->check(
            'MySQL / MariaDB support',
            $mysqlReady,
            true,
            $pdoDetail,
            'This installer imports a MySQL/MariaDB SQL backup.',
            'Enable pdo_mysql/mysqlnd for PHP, then create an empty MySQL or MariaDB database in your hosting panel.',
            'Enable pdo_mysql',
            false,
            'cPanel/Plesk/DirectAdmin usually expose this as pdo_mysql, mysqlnd, or MySQLi/PDO support. VPS users need the matching PHP MySQL package.'
        );

        $checks[] = $this->check(
            'URL rewrite to Laravel',
            true,
            true,
            '/index.php/install reached the Laravel router',
            'If another route gives 404 after install, the web-server rewrite rule needs attention.',
            'Point the domain document root to public/ when possible. Apache and LiteSpeed can use the bundled .htaccess fallback; Nginx, Caddy, and IIS need their own front-controller rule.',
            'Check document root and rewrites',
            false,
            'Use the snippet shown on this page or the files in docs/server-configs.'
        );

        try {
            $processesReady = Environment::canRunProcesses();
            $processDetail = $processesReady ? 'Available' : 'Disabled';
        } catch (Throwable $e) {
            $processesReady = false;
            $processDetail = 'Probe failed: '.$e->getMessage();
        }
        $checks[] = $this->check(
            'Process control',
            $processesReady,
            false,
            $processDetail,
            'Recommended for AVDB import, queue workers, cron helpers, backups, and media tools.',
            $this->processControlFixHint(),
            'Enable process functions if possible',
            false,
            'Some shared hosts block process control. The app can still run, but long background work should be handled by cron or a queue worker configured in the panel.'
        );

        $ffmpegProbeFailed = '';
        try {
            $ffmpeg = Environment::ffmpegBinary();
            $ffprobe = Environment::ffprobeBinary();
        } catch (Throwable $e) {
            $ffmpeg = '';
            $ffprobe = '';
            $ffmpegProbeFailed = 'Probe failed: '.$e->getMessage();
        }
        $checks[] = $this->check(
            'FFmpeg / FFprobe',
            $ffmpeg !== '' && $ffprobe !== '',
            false,
            $ffmpegProbeFailed !== ''
                ? $ffmpegProbeFailed
                : ($ffmpeg !== '' && $ffprobe !== '' ? 'Detected' : 'Not detected'),
            'Recommended for uploaded video duration, previews, thumbnails, and media validation.',
            'Install ffmpeg and ffprobe, or set FFMPEG_BINARY and FFPROBE_BINARY in .env when your host provides custom paths.',
            'Install or configure FFmpeg',
            false,
            'Remote AVDB duration backfill can still work without FFmpeg, but local uploads need these binaries for best results.'
        );

        $uploadMax = (string) ini_get('upload_max_filesize');
        $postMax = (string) ini_get('post_max_size');
        $uploadLimitReady = $this->bytesFromIni($uploadMax) >= 64 * 1024 * 1024
            && $this->bytesFromIni($postMax) >= 64 * 1024 * 1024;
        $checks[] = $this->check(
            'Upload limit',
            $uploadLimitReady,
            false,
            'upload_max_filesize='.$uploadMax.', post_max_size='.$postMax,
            'Use at least 64M for setup; production video uploads usually need 256M or more.',
            'The installer can write a .user.ini recommendation when the project root is writable. If your server ignores it, change upload_max_filesize and post_max_size in your hosting panel PHP settings.',
            'Write .user.ini when possible',
            true,
            'cPanel: MultiPHP INI Editor. Plesk: PHP Settings. DirectAdmin: PHP Settings or custom php.ini. Nginx may also need client_max_body_size.'
        );

        return $checks;
    }

    public function repairRequirements(): void
    {
        $this->repairFilesystem();
        $this->writeRecommendedUserIni();

        // Auto-fix aaPanel Nginx rewrite + open_basedir — zero-config deployment
        EnvironmentRepairer::repairInstallation();
    }

    public function requirementsPass(): bool
    {
        foreach ($this->requirements() as $check) {
            if ($check['required'] && ! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, array{label:string,path:string,ok:bool,required:bool,detail:string,help:string,fix:string,auto_fix:bool}>
     */
    public function filesystemChecks(): array
    {
        $paths = [
            ['Storage', storage_path(), true],
            ['Storage app', storage_path('app'), true],
            ['Storage public', storage_path('app/public'), true],
            ['Storage logs', storage_path('logs'), true],
            ['Framework cache', storage_path('framework/cache'), true],
            ['Framework sessions', storage_path('framework/sessions'), true],
            ['Framework views', storage_path('framework/views'), true],
            ['Bootstrap cache', base_path('bootstrap/cache'), true],
            ['Public directory', public_path(), true],
            ['Public storage link', public_path('storage'), false],
        ];

        return array_map(function (array $item): array {
            [$label, $path, $required] = $item;
            $exists = file_exists($path);
            $writable = $exists && is_writable($path);
            $linkOk = $label === 'Public storage link' && (is_link($path) || is_dir($path));
            $ok = $label === 'Public storage link' ? $linkOk : ($exists && $writable);

            return [
                'label' => $label,
                'path' => $path,
                'ok' => $ok,
                'required' => $required,
                'detail' => $exists ? ($writable || $linkOk ? 'Ready' : 'Not writable') : 'Missing',
                'help' => $label === 'Public storage link'
                    ? 'Used for public files. The installer attempts php artisan storage:link during install.'
                    : 'PHP must be able to create, update, and remove files here.',
                'fix' => $exists
                    ? 'Set write permission for the web/PHP user on this path.'
                    : 'Create this folder or let the installer create it if the parent directory is writable.',
                'auto_fix' => ! $exists || $label === 'Public storage link',
            ];
        }, $paths);
    }

    public function repairFilesystem(): void
    {
        foreach ([
            storage_path('app'),
            storage_path('app/public'),
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ] as $path) {
            if (! is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
    }

    private function writeRecommendedUserIni(): void
    {
        $path = base_path('.user.ini');
        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            return;
        }

        $managed = implode(PHP_EOL, [
            '; AVDBClip installer recommendations',
            'memory_limit=512M',
            'upload_max_filesize=256M',
            'post_max_size=260M',
            'max_execution_time=600',
            'max_input_time=300',
        ]);

        $content = is_file($path) ? (string) file_get_contents($path) : '';
        $start = '; >>> AVDBClip installer';
        $end = '; <<< AVDBClip installer';
        $block = $start.PHP_EOL.$managed.PHP_EOL.$end;

        if (str_contains($content, $start) && str_contains($content, $end)) {
            $content = preg_replace(
                '/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'/s',
                $block,
                $content
            ) ?? $content;
        } else {
            $content = rtrim($content).($content === '' ? '' : PHP_EOL.PHP_EOL).$block;
        }

        @file_put_contents($path, rtrim($content).PHP_EOL);
    }

    public function filesystemPass(): bool
    {
        foreach ($this->filesystemChecks() as $check) {
            if ($check['required'] && ! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{host:string,port:string,database:string,username:string,password:string} $database
     * @return array{ok:bool,message:string,version:?string,tables:array<int, string>}
     */
    public function testDatabase(array $database): array
    {
        try {
            $pdo = $this->pdo($database);
            $version = (string) $pdo->query('select version()')->fetchColumn();

            return [
                'ok' => true,
                'message' => 'Connected successfully.',
                'version' => $version,
                'tables' => $this->existingTables($pdo, $database['database']),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'version' => null,
                'tables' => [],
            ];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    public function install(array $payload): array
    {
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '600');
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $messages = [];
        $database = $payload['database'];
        $admin = $payload['admin'];
        $app = $payload['app'];

        // Inspect the selected database before changing .env, runtime config,
        // permissions, or any other installation state.
        $pdo = $this->targetDatabasePdo($database);
        $databaseObjects = $this->databaseObjects($pdo, (string) $database['database']);
        $overwrite = filter_var($database['overwrite'] ?? false, FILTER_VALIDATE_BOOL);
        if ($databaseObjects !== [] && ! $overwrite) {
            throw new \RuntimeException(
                'The selected database already contains tables or views. Existing data was preserved. Return to the Database step and enable replacement only when this is the intended database to replace.'
            );
        }

        $this->repairFilesystem();
        $messages[] = 'Runtime directories checked.';

        $envValues = $this->envValues($database, $admin, $app);
        $this->writeEnvironment($envValues);
        $messages[] = '.env file written for production.';

        $this->applyRuntimeDatabaseConfig($database);
        if ($overwrite && $databaseObjects !== []) {
            $this->dropExistingObjects($pdo, $databaseObjects);
            $messages[] = 'Explicit replacement enabled: removed '.count($databaseObjects).' existing database tables and views.';
        }

        $backupInfo = $this->backupInfo();
        $defaultMode = $backupInfo['exists'] ? 'backup' : 'migrate';

        if (($database['mode'] ?? $defaultMode) === 'backup' && ($path = $backupInfo['path'])) {
            $result = $this->importSqlBackup($pdo, $path);
            $messages[] = "Imported SQL backup {$backupInfo['filename']} ({$result['statements']} statements).";
            try {
                Artisan::call('migrate', ['--force' => true]);
                $messages[] = 'Database schema migrations synchronized.';
            } catch (Throwable $e) {
                // Non-fatal if schema already current
            }
        } else {
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--force' => true]);
            $messages[] = 'Database migrated and seeded.';
        }

        $this->applyRuntimeDatabaseConfig($database);
        $this->configureAdmin($admin);
        $messages[] = 'Admin account created or updated.';

        $this->writeCoreSettings($admin, $app);
        $messages[] = 'Core site settings saved.';

        try {
            Artisan::call('storage:link');
            $messages[] = 'Public storage link checked.';
        } catch (Throwable $e) {
            $messages[] = 'Storage link could not be created automatically ('.$e->getMessage().'). Public files are still served through the application fallback route; run php artisan storage:link later if your host allows symlinks.';
        }

        // Apply environment repairs including 644 file and 755 directory permissions
        try {
            EnvironmentRepairer::repairInstallation();
            $messages[] = 'Standard permissions (644 files, 755 directories) and environment verified.';
        } catch (Throwable) {
            // Non-fatal if server restricts chmod
        }

        // Prepare an inactive import template and the application's scheduler.
        // Importing content requires an explicit admin action after installation.
        try {
            \App\Http\Controllers\Admin\XvidImportController::ensureDefaultScheduleExists();
            \App\Support\LaravelSchedulerCron::ensureInstalled();
            $messages[] = 'Scheduler configured. XVIDAPI imports remain disabled until started or enabled by an admin.';
        } catch (Throwable $e) {
            // Non-fatal
        }

        try {
            Artisan::call('optimize:clear');
            $messages[] = 'Laravel caches cleared.';
        } catch (Throwable) {
            // cache clear is helpful, not fatal
        }

        $this->writeLock($admin, $app);
        $messages[] = 'Installer locked.';

        return $messages;
    }

    /**
     * @param array{host:string,port:string,database:string,username:string,password:string} $database
     */
    protected function targetDatabasePdo(array $database): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $database['host'],
            $database['port'] ?: '3306',
            $database['database']
        );

        return new PDO($dsn, $database['username'], $database['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * @param array<string, mixed> $database
     */
    private function pdo(array $database): PDO
    {
        return $this->targetDatabasePdo($database);
    }

    /**
     * @return array<int, string>
     */
    private function existingTables(PDO $pdo, string $database): array
    {
        return array_map(
            static fn (array $object): string => $object['name'],
            array_values(array_filter(
                $this->databaseObjects($pdo, $database),
                static fn (array $object): bool => $object['type'] === 'BASE TABLE'
            ))
        );
    }

    /**
     * Introspection errors must stop the install. Returning an empty list on a
     * permissions/query error would make an unknown database look empty.
     *
     * @return array<int, array{name:string,type:string}>
     */
    protected function databaseObjects(PDO $pdo, string $database): array
    {
        try {
            $statement = $pdo->prepare(
                'select table_name, table_type from information_schema.tables where table_schema = ? order by table_name'
            );
            $statement->execute([$database]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            throw new \RuntimeException('Could not safely inspect the selected database. No installation changes were made.', 0, $e);
        }

        $objects = [];
        foreach ($rows as $row) {
            // MySQL 8 returns information_schema column keys UPPERCASE (TABLE_NAME,
            // TABLE_TYPE) while MariaDB returns them lowercase. Normalize so an
            // overwrite/reinstall on a non-empty database works on both engines
            // (without this, MySQL 8 aborted as "incomplete object list").
            $row = array_change_key_case((array) $row, CASE_LOWER);
            $name = trim((string) ($row['table_name'] ?? ''));
            $type = strtoupper(trim((string) ($row['table_type'] ?? '')));
            if ($name === '' || ! in_array($type, ['BASE TABLE', 'VIEW'], true)) {
                throw new \RuntimeException('The selected database returned an incomplete object list. No installation changes were made.');
            }
            $objects[] = ['name' => $name, 'type' => $type];
        }

        return $objects;
    }

    /**
     * Drop only tables reported by the already-connected target database.
     * Identifiers are quoted, and the operation is reached only after the
     * installer receives an explicit overwrite=true value.
     *
     * @param array<int, string> $tables
     */
    private function dropExistingObjects(PDO $pdo, array $objects): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        try {
            // Remove views first so dependencies cannot prevent their source
            // tables from being removed during an intentional replacement.
            usort($objects, static fn (array $left, array $right): int =>
                (($left['type'] === 'VIEW') ? 0 : 1) <=> (($right['type'] === 'VIEW') ? 0 : 1)
            );
            foreach ($objects as $object) {
                $identifier = str_replace('`', '``', trim($object['name']));
                if ($identifier === '') {
                    continue;
                }

                $dropType = $object['type'] === 'VIEW' ? 'VIEW' : 'TABLE';
                $pdo->exec('DROP '.$dropType.' IF EXISTS `'.$identifier.'`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * @return array{statements:int}
     */
    private function importSqlBackup(PDO $pdo, string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new \RuntimeException('Could not open SQL backup: '.$path);
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $statement = '';
        $statements = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = ltrim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                    continue;
                }

                if (str_starts_with($trimmed, '/*M!999999')) {
                    continue;
                }

                if (preg_match('/^(LOCK TABLES|UNLOCK TABLES)\b/i', $trimmed)) {
                    continue;
                }

                if (stripos($trimmed, 'DISABLE KEYS') !== false || stripos($trimmed, 'ENABLE KEYS') !== false) {
                    continue;
                }

                $statement .= $line;

                if (! $this->statementComplete($statement)) {
                    continue;
                }

                $sql = trim($statement);
                $statement = '';

                if ($sql === '' || $sql === ';') {
                    continue;
                }

                $pdo->exec($sql);
                $statements++;
            }
        } finally {
            fclose($handle);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        return ['statements' => $statements];
    }

    private function statementComplete(string $sql): bool
    {
        $sql = rtrim($sql);
        if ($sql === '' || substr($sql, -1) !== ';') {
            return false;
        }

        $single = false;
        $double = false;
        $escape = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($char === '\\') {
                $escape = true;
                continue;
            }

            if ($char === "'" && ! $double) {
                $single = ! $single;
                continue;
            }

            if ($char === '"' && ! $single) {
                $double = ! $double;
            }
        }

        return ! $single && ! $double;
    }

    /**
     * @param array<string, string> $database
     */
    private function applyRuntimeDatabaseConfig(array $database): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.host', $database['host']);
        Config::set('database.connections.mysql.port', $database['port'] ?: '3306');
        Config::set('database.connections.mysql.database', $database['database']);
        Config::set('database.connections.mysql.username', $database['username']);
        Config::set('database.connections.mysql.password', $database['password']);
        DB::purge('mysql');
        DB::reconnect('mysql');
    }

    /**
     * @param array<string, string> $admin
     */
    private function configureAdmin(array $admin): void
    {
        $user = User::query()
            ->where('email', $admin['email'])
            ->orWhere('username', $admin['username'])
            ->orWhere('is_admin', true)
            ->orderByDesc('is_admin')
            ->first();

        if (! $user) {
            $user = new User();
            $user->slug = User::generateSlug($admin['username']);
        }

        $user->forceFill([
            'username' => $admin['username'],
            'email' => $admin['email'],
            'password' => $admin['password'],
            'email_verified_at' => now(),
            'is_admin' => true,
            'show_subscribers' => true,
        ])->save();
    }

    /**
     * @param array<string, string> $admin
     * @param array<string, string> $app
     */
    private function writeCoreSettings(array $admin, array $app): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        Setting::set('site_name', $app['name'], 'general');
        Setting::set('contact_email', $admin['email'], 'general');
        Setting::set('mail_mailer', $app['mail_mailer'] ?: 'log', 'email');
        Setting::set('smtp_host', $app['mail_host'] ?: '', 'email');
        Setting::set('smtp_port', $app['mail_port'] ?: '587', 'email');
        Setting::set('smtp_username', $app['mail_username'] ?: '', 'email');
        Setting::set('smtp_password', $app['mail_password'] ?: '', 'email');
        Setting::set('smtp_encryption', $app['mail_encryption'] ?: '', 'email');
        Setting::set('mail_from_address', $app['mail_from_address'] ?: $admin['email'], 'email');
        Setting::set('mail_from_name', $app['name'], 'email');

        // Keep the Upload18 key bundled in the seed unless the installer environment supplies one;
        // admins can change it at any time in Settings -> Uploads.
        $upload18Key = $this->runtimeEnvString('UPLOAD18_API_KEY');
        if ($upload18Key !== '') {
            Setting::set('upload18_api_key', $upload18Key, 'uploads');
        }
        Setting::set('upload18_api_url', $this->runtimeEnvString('UPLOAD18_API_URL', 'https://upload18.org/api'), 'uploads');
        Setting::set('upload18_default_cid', $this->runtimeEnvString('UPLOAD18_DEFAULT_CID', '15'), 'uploads');
        Setting::set('upload18_default_mycid', $this->runtimeEnvString('UPLOAD18_DEFAULT_MYCID', '0'), 'uploads');
        Setting::set('upload18_default_fid', $this->runtimeEnvString('UPLOAD18_DEFAULT_FID', '18'), 'uploads');

        Cache::flush();
    }

    /**
     * @param array<string, string> $database
     * @param array<string, string> $admin
     * @param array<string, string> $app
     * @return array<string, string>
     */
    private function envValues(array $database, array $admin, array $app): array
    {
        // Persist https when the edge terminates TLS (Cloudflare/panels) even if the install
        // request reached the origin over http (Flexible SSL) — avoids an http APP_URL/ASSET_URL
        // causing mixed-content (blocked CSS/JS) and redirect issues on an HTTPS site.
        $appUrl = $app['url'];
        if (\App\Support\Environment::isSecureRequest() && str_starts_with(strtolower($appUrl), 'http://')) {
            $appUrl = 'https://' . substr($appUrl, 7);
        }
        $host = parse_url($appUrl, PHP_URL_HOST) ?: '';
        $https = str_starts_with(strtolower($appUrl), 'https://');

        return [
            'APP_NAME' => $app['name'],
            'APP_ENV' => 'production',
            'APP_KEY' => $this->generateAppKey(),
            'APP_DEBUG' => 'false',
            'APP_URL' => $appUrl,
            'ASSET_URL' => $this->assetUrl($appUrl),
            'APP_INSTALLED' => 'true',
            'LOG_CHANNEL' => 'single',
            'LOG_LEVEL' => 'error',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $database['host'],
            'DB_PORT' => $database['port'] ?: '3306',
            'DB_DATABASE' => $database['database'],
            'DB_USERNAME' => $database['username'],
            'DB_PASSWORD' => $database['password'],
            'BROADCAST_DRIVER' => 'log',
            'CACHE_DRIVER' => 'file',
            'LIMITER_DRIVER' => 'file',
            'FILESYSTEM_DISK' => 'local',
            'QUEUE_CONNECTION' => 'database',
            'SESSION_DRIVER' => 'file',
            'SESSION_DOMAIN' => $host,
            'SESSION_SECURE_COOKIE' => $https ? 'true' : 'false',
            'SCOUT_DRIVER' => 'collection',
            'MAIL_MAILER' => $app['mail_mailer'] ?: 'log',
            'MAIL_HOST' => $app['mail_host'] ?: '',
            'MAIL_PORT' => $app['mail_port'] ?: '587',
            'MAIL_USERNAME' => $app['mail_username'] ?: '',
            'MAIL_PASSWORD' => $app['mail_password'] ?: '',
            'MAIL_ENCRYPTION' => $app['mail_encryption'] ?: '',
            'MAIL_FROM_ADDRESS' => $app['mail_from_address'] ?: $admin['email'],
            'MAIL_FROM_NAME' => $app['name'],
            'SUPPORT_EMAIL' => $admin['email'],
            'SERVER_EMAIL' => $admin['email'],
            'SANCTUM_STATEFUL_DOMAINS' => $host,
            'ADMIN_OPERATIONAL_TOOLS_ENABLED' => $this->runtimeEnvString('ADMIN_OPERATIONAL_TOOLS_ENABLED', 'true') ?: 'true',
            'AVDB_IMPORT_MODE' => 'auto',
            'AVDB_DURATION_BACKFILL_MODE' => 'auto',
            'AVDB_DURATION_REMOTE_CONCURRENCY' => '10',
            'AVDB_DURATION_REMOTE_TIMEOUT' => '8',
            'INSTALL_SQL_BACKUP' => $this->backupInfo()['filename'],
        ];
    }

    /**
     * @param array<string, string> $values
     */
    private function writeEnvironment(array $values): void
    {
        $envPath = base_path('.env');
        $examplePath = base_path('.env.example');

        $content = is_file($examplePath)
            ? (string) file_get_contents($examplePath)
            : '';

        if (is_file($envPath)) {
            // Keep the backup outside the document root. Preserve the first
            // original environment file without accumulating web-root copies.
            $backupDirectory = storage_path('app/installer-backups');
            if (! is_dir($backupDirectory)) {
                @mkdir($backupDirectory, 0700, true);
            }
            $backupPath = $backupDirectory.'/.env.backup';
            if (! is_file($backupPath) && @copy($envPath, $backupPath)) {
                @chmod($backupPath, 0600);
            }
            $content = (string) file_get_contents($envPath);
        }

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->formatEnvValue($value);
            if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $content)) {
                // preg_replace_callback: the replacement is returned literally, so a
                // value containing $1, \0 etc. (common in generated passwords) is not
                // misread as a backreference and silently corrupted.
                $content = preg_replace_callback('/^'.preg_quote($key, '/').'=.*$/m', static fn (): string => $line, $content) ?? $content;
            } else {
                $content .= PHP_EOL.$line;
            }
        }

        // Remove any temporary reinstall flags upon successful installation
        $content = preg_replace('/^INSTALLER_ALLOW_REINSTALL=.*$/m', '', $content) ?? $content;
        $content = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $content);

        file_put_contents($envPath, rtrim($content).PHP_EOL);
    }

    private function assetUrl(string $appUrl): string
    {
        $configured = $this->runtimeEnvString('ASSET_URL');
        if ($configured !== '') {
            return $configured;
        }

        return !empty($_SERVER['AVDBCLIP_ROOT_FALLBACK'])
            ? rtrim($appUrl, '/') . '/public'
            : '';
    }

    private function generateAppKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    private function formatEnvValue(?string $value): string
    {
        $value ??= '';

        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_.,:\/@+\-=]+$/', $value)) {
            return $value;
        }

        // Escape $ as well: Dotenv expands $VAR / ${VAR} inside double-quoted
        // values, so an unescaped $ in a password would be mangled when read back.
        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    /**
     * @param array<string, string> $admin
     * @param array<string, string> $app
     */
    private function writeLock(array $admin, array $app): void
    {
        file_put_contents($this->lockPath(), json_encode([
            'installed_at' => now()->toIso8601String(),
            'app_url' => $app['url'],
            'admin_email' => $admin['email'],
            'backup' => $this->backupInfo()['filename'],
        ], JSON_PRETTY_PRINT));
    }

    private function check(
        string $label,
        bool $ok,
        bool $required,
        string $detail,
        string $help,
        string $fix,
        string $action,
        bool $autoFix = false,
        string $docs = ''
    ): array
    {
        return [
            'label' => $label,
            'ok' => $ok,
            'required' => $required,
            'detail' => $detail,
            'help' => $help,
            'fix' => $fix,
            'action' => $action,
            'auto_fix' => $autoFix,
            'docs' => $docs,
            'severity' => $ok ? 'ok' : ($required ? 'danger' : 'warning'),
        ];
    }

    private function bytesFromIni(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return (int) match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function processControlFixHint(): string
    {
        if (Environment::usesLsapi()
            || in_array(Environment::webServer(), [Environment::WEB_LITESPEED, Environment::WEB_OPENLITESPEED], true)) {
            return 'For LiteSpeed/OpenLiteSpeed, remove proc_open, proc_get_status, and proc_close from the active LSAPI php.ini disable_functions list, then fully restart OpenLiteSpeed. On aaPanel this file is usually /usr/local/lsws/lsphp83/etc/php/8.3/litespeed/php.ini. Keep exec/shell_exec/system disabled unless your host policy allows them.';
        }

        return 'Ask the host to allow proc_open/proc_get_status/proc_close, or use panel cron and the built-in admin queue controls instead.';
    }

    private function envFlag(string $key): bool
    {
        $value = RuntimeEnv::get($key, false);

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function hasExplicitFalseEnvFlag(string $key): bool
    {
        $value = RuntimeEnv::get($key, null);

        return $value !== null
            && $value !== ''
            && filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === false;
    }

    private function runtimeEnvString(string $key, string $default = ''): string
    {
        return (string) RuntimeEnv::get($key, $default);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }

    private function normalizedRealPath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $real = realpath($path) ?: $path;

        return rtrim(str_replace('\\', '/', $real), '/');
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $normalized = str_replace('\\', '/', $path);

        if (str_starts_with($normalized, $base)) {
            return substr($normalized, strlen($base));
        }

        return $normalized;
    }
}
