<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use Throwable;

class Installer
{
    public const string BACKUP_FILENAME = 'example.sql';

    /**
     * Seconds of work one request of the installation does at most (R1B-001): well under the 30 seconds after which a
     * strict host ends a request (PHP-FPM's request_terminate_timeout, which PHP cannot see), and capped by PHP's own
     * max_execution_time (App\Support\TimeBudget). A new database change starts only while a third of it is left.
     */
    public const float RUN_BUDGET = 15.0;

    /** The shortest steps: after a request the host ended early, the next ones get half the time, down to this. */
    public const float RUN_MIN_BUDGET = 3.0;

    /** How often the host may end a request at the same point (one change, one table) before the installer stops and says why. */
    public const int RUN_MAX_ENDS_AT_ONE_POINT = 3;

    /** PHP's own time limit for a step, where the host lets set_time_limit() raise it (the budget keeps the step short). */
    private const int RUN_TIME_LIMIT = 120;

    /** Where nothing can be locked (no flock, no GET_LOCK): a "running" note younger than this belongs to a step still at work. */
    private const int RUN_STALE_SECONDS = 60;

    /**
     * With INSTALLER_ALLOW_REINSTALL=true, Install Now in another browser starts again in place of an unfinished
     * installation that no browser has driven for this long (its owner's session was lost); one that is still being
     * driven is never taken over (startRun()).
     */
    public const int RUN_IDLE_SECONDS = 120;

    /** The answer of this request's storage check (storageExposure()). */
    private ?string $storageExposure = null;

    /**
     * What the last installSchedulerCron() did (LaravelSchedulerCron::ensureInstalled()'s answer and 'php'), read by the
     * last step for its result page (schedulerAdvice()); null until it said (a test's replacement may set it).
     *
     * @var array<string, mixed>|null
     */
    protected ?array $schedulerCronResult = null;

    /** The step of the installation this process is doing (read by the migration listener, listenForMigrations()). */
    private static ?\stdClass $migrationSlice = null;

    /** The event dispatcher the migration listener is registered on. */
    private static ?\WeakReference $listeningOn = null;

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
        // The owner's explicit reinstall switch stays first.
        if ($this->envFlag('INSTALLER_ALLOW_REINSTALL')) {
            return false;
        }

        // The lock the web installer writes beats an APP_INSTALLED=false that a copied .env.example brought back.
        if (is_file($this->lockPath())) {
            // Only next to a run note (one stat when there is none, the normal case):
            if (is_file($this->runStatePath()) && ($run = $this->loadRun()) !== null) {
                // a last step cut off after its .env and lock: the cron line and the end of its note follow;
                if ($this->completeCutOffFinish($run)) {
                    return true;
                }

                // a reinstallation (INSTALLER_ALLOW_REINSTALL) that has begun rebuilding this site's own database and did
                // not finish, after the switch was taken away again: the lock belongs to a site that is gone. Its owner
                // continues it (unfinishedInstallation(): the password of its database); nobody else sees the wizard.
                if ($this->runUnfinished($run) && $this->runReplacesSiteDatabase($run)) {
                    return false;
                }
            }

            return true;
        }

        if ($this->hasExplicitFalseEnvFlag('APP_INSTALLED')) {
            return false;
        }

        // An installation of this installer in progress is not installed yet, whatever the database or .env say already:
        // resuming an earlier installer's half-made site, .env says APP_INSTALLED=true from the start and the run's own
        // admin step comes before its final .env. Only its last step cut off after it had written .env counts as done.
        $run = $this->loadRun();
        if ($run !== null && $run->get('status') !== 'finished' && ! $this->finishCutOffAfterEnv($run)) {
            return false;
        }

        try {
            if ($this->hasAdministrator()) {
                if ($run !== null) {
                    $this->completeCutOffFinish($run);
                }

                return true;
            }
        } catch (Throwable) {
            // A database error (a restart, too many connections) on a site whose .env says it is installed or already
            // names a key or a database user is a site that is down, not a fresh upload: the wizard stays closed.
            return $this->envFlag('APP_INSTALLED') || $this->hasConfiguredEnvironment();
        }

        // No lock and no administrator. With APP_INSTALLED=true this is an installation that did not finish (R1B-001: an
        // earlier installer wrote .env first and the host ended its request half-way): the wizard may resume it, but only
        // for the site's owner (unfinishedInstallation(), InstallController).
        return false;
    }

    /**
     * Whether this site is being set up (round 3 R3-038; App\Http\Middleware\AnswerWhileInstalling answers its pages
     * "being set up" then): there is no installer lock, and the upload's own .env still says APP_INSTALLED=false (a fresh
     * upload, an installation not finished), or an installation of this installer is in progress (its note; a last step
     * cut off after its .env counts as finished, isInstalled()). Next to the lock only a reinstallation
     * (INSTALLER_ALLOW_REINSTALL) that has begun rebuilding this site's own database and did not finish: the installed
     * site is gone until it ends (isInstalled() says the same); a reinstallation into another database leaves the site
     * as it is. An installed site answers after two file checks (the lock, no run note); a site without a run note never
     * reads the database here.
     */
    public function beingSetUp(): bool
    {
        if (is_file($this->lockPath())) {
            if (! is_file($this->runStatePath())) {
                return false;
            }

            try {
                $run = $this->loadRun();

                return $run !== null && $this->runUnfinished($run) && $this->runReplacesSiteDatabase($run);
            } catch (Throwable) {
                return false;
            }
        }

        if ($this->hasExplicitFalseEnvFlag('APP_INSTALLED')) {
            return true;
        }

        if (! is_file($this->runStatePath())) {
            return false;
        }

        try {
            return ! $this->isInstalled();
        } catch (Throwable) {
            return false;
        }
    }

    /** Whether the site's database has an administrator account (throws when the database cannot be asked). */
    protected function hasAdministrator(): bool
    {
        return Schema::hasTable('users') && User::query()->where('is_admin', true)->exists();
    }

    /** Whether $run's last step was cut off after it had written the final .env (its note says so, finishRun()). */
    private function finishCutOffAfterEnv(InstallRun $run): bool
    {
        $running = $run->get('running');

        return $run->get('status') === 'ready' && is_array($running) && ($running['job'] ?? null) === 'finish'
            && ($running['point'] ?? null) === 'env-written' && $this->envFlag('APP_INSTALLED');
    }

    /**
     * The last step of an installation that the host ended after it had written the final .env but before the lock
     * (R1B-001): the site is installed (APP_INSTALLED=true with its administrator), so the lock, the scheduler's cron line
     * and the end of the run's note follow now - what that step would have done next. Only for such a run (true); nothing
     * happens on any other site (false).
     */
    private function completeCutOffFinish(InstallRun $run): bool
    {
        if (! $this->finishCutOffAfterEnv($run)) {
            return false;
        }

        try {
            if (! is_file($this->lockPath())) {
                $this->writeLock([], ['url' => (string) RuntimeEnv::get('APP_URL', '')]);
            }

            $run->delete();
            $this->installSchedulerCron();
        } catch (Throwable) {
            // The site works without them; the next visit tries again while the note is there.
        }

        return true;
    }

    /** Whether $run is an installation in progress: not over, and not a last step cut off after its final .env. */
    private function runUnfinished(InstallRun $run): bool
    {
        return $run->get('status') !== 'finished' && ! $this->finishCutOffAfterEnv($run);
    }

    /**
     * Whether $run works on the database this site's .env names and has begun changing it: a reinstallation that replaces
     * the site's own database (its first step empties it). From then on the installed site is gone until the run ends.
     * A run on another database leaves the installed site as it is.
     */
    private function runReplacesSiteDatabase(InstallRun $run): bool
    {
        if (! $this->runTouchedDatabase($run)) {
            return false;
        }

        $target = $this->runTarget((array) $run->get('target', []));
        $site = $this->runTarget([
            'host' => (string) RuntimeEnv::get('DB_HOST', ''),
            'port' => (string) RuntimeEnv::get('DB_PORT', '3306'),
            'database' => (string) RuntimeEnv::get('DB_DATABASE', ''),
            'username' => (string) RuntimeEnv::get('DB_USERNAME', ''),
        ]);

        return $target['database'] !== '' && $target['database'] === $site['database'] && $target['username'] === $site['username']
            && $target['port'] === $site['port'] && self::sameDatabaseHost($target['host'], $site['host']);
    }

    /** "localhost", 127.0.0.1 and ::1 are the same database server; other names are compared as written (any case). */
    private static function sameDatabaseHost(string $a, string $b): bool
    {
        $name = static fn (string $host): string => in_array(strtolower(trim($host, '[] ')), ['localhost', '127.0.0.1', '::1'], true)
            ? 'local'
            : strtolower(trim($host));

        return $name($a) === $name($b);
    }

    /** Whether $run has begun changing its database: emptying it, importing, a database change, the account, the settings. */
    private function runTouchedDatabase(InstallRun $run): bool
    {
        $touched = $run->get('touched');

        if (is_bool($touched)) {
            return $touched;
        }

        // A run note of an earlier 1.2.3 build has no "touched": a step that was done, at work or failed.
        foreach ((array) $run->get('jobs', []) as $state) {
            if ($state === 'done') {
                return true;
            }
        }

        return is_array($run->get('running')) || is_array($run->get('failed'));
    }

    /**
     * A reinstallation was started next to the installer lock and did not finish, on a database this site does not use:
     * the site stays installed, and its locked page says how the owner goes on with it.
     */
    public function hasUnfinishedReinstall(): bool
    {
        if (! is_file($this->lockPath()) || ! is_file($this->runStatePath())) {
            return false;
        }

        $run = $this->loadRun();

        return $run !== null && $this->runUnfinished($run);
    }

    /**
     * The installation that was started here and did not finish, or null. While there is one, the wizard serves only its
     * owner (R1B-001), who proves it with the password of its database (proveOwnership()):
     *  - kind "run": a run of this installer (InstallRun) whose owner's session is not this one - e.g. the browser was
     *    closed half-way. Its database host, name and user are known; the password never left the owner's session.
     *    Next to the installer lock it is a reinstallation: while INSTALLER_ALLOW_REINSTALL=true is set ("reinstall": that
     *    switch, which only somebody with access to the files can set, keeps the wizard open; its first page offers the
     *    proof, and Install Now starts again once the run is idle, startRun()), or after the switch was taken away again
     *    once the run had begun rebuilding the site's own database (the site is not installed any more, isInstalled()).
     *  - kind "env": .env says APP_INSTALLED=true, but there is no installer lock and no administrator: an earlier
     *    installer wrote .env before the database work and the host ended the request. The password is the one in .env.
     *    The reinstall switch opens the wizard without any proof here (there is no run to take over).
     * "provable": an empty database password proves nothing, so such an installation can only be started again.
     *
     * @return array{kind: string, id: string, target: array{host: string, port: string, database: string, username: string}, started_at: ?int, updated_at: ?int, percent: ?int, reinstall: bool, provable: bool}|null
     */
    public function unfinishedInstallation(): ?array
    {
        $reinstall = $this->envFlag('INSTALLER_ALLOW_REINSTALL');
        $locked = is_file($this->lockPath());
        $run = $this->loadRun();

        if ($run !== null && $this->runUnfinished($run) && (! $locked || $reinstall || $this->runReplacesSiteDatabase($run))) {
            $target = $this->runTarget((array) $run->get('target', []));

            return [
                'kind' => 'run',
                'id' => (string) $run->get('id', ''),
                'target' => $target,
                'started_at' => is_numeric($run->get('created_at')) ? (int) $run->get('created_at') : null,
                'updated_at' => is_numeric($run->get('updated_at')) ? (int) $run->get('updated_at') : null,
                'percent' => $this->runProgress($run)['percent'],
                'reinstall' => $reinstall,
                // Its database user has a password (noted when it started; a run note of an earlier build: unknown).
                'provable' => $target['host'] !== '' && $target['database'] !== '' && $target['username'] !== ''
                    && $run->get('provable', true) !== false,
            ];
        }

        if ($reinstall || $locked) {
            return null;
        }

        if (! $this->envFlag('APP_INSTALLED') || $this->hasExplicitFalseEnvFlag('APP_INSTALLED')) {
            return null;
        }

        try {
            if ($this->hasAdministrator()) {
                return null;
            }
        } catch (Throwable) {
            // isInstalled() keeps such a site closed: nothing to resume from here.
            return null;
        }

        $target = $this->runTarget([
            'host' => (string) RuntimeEnv::get('DB_HOST', '127.0.0.1'),
            'port' => (string) RuntimeEnv::get('DB_PORT', '3306'),
            'database' => (string) RuntimeEnv::get('DB_DATABASE', ''),
            'username' => (string) RuntimeEnv::get('DB_USERNAME', ''),
        ]);

        return [
            'kind' => 'env',
            'id' => hash('sha256', 'env|'.implode('|', $target)),
            'target' => $target,
            'started_at' => null,
            'updated_at' => null,
            'percent' => null,
            'reinstall' => false,
            // An empty database password proves nothing: then only the reinstall switch in .env opens the wizard.
            'provable' => trim((string) RuntimeEnv::get('DB_PASSWORD', '')) !== '',
        ];
    }

    /**
     * Does $password prove that this visitor owns the unfinished installation (unfinishedInstallation())? For an earlier
     * installer's .env: it is the database password written there. For a run of this installer: it opens a connection to
     * the run's database with the run's host and user. An empty password never does.
     *
     * @param array{kind: string, target: array{host: string, port: string, database: string, username: string}} $pending
     */
    public function proveOwnership(array $pending, string $password): bool
    {
        if ($password === '') {
            return false;
        }

        if (($pending['kind'] ?? '') === 'env') {
            $expected = (string) RuntimeEnv::get('DB_PASSWORD', '');

            return $expected !== '' && hash_equals($expected, $password);
        }

        $target = $this->runTarget((array) ($pending['target'] ?? []));

        if ($target['host'] === '' || $target['database'] === '' || $target['username'] === '') {
            return false;
        }

        try {
            $pdo = $this->targetDatabasePdo($target + ['password' => $password]);

            return $pdo->query('select 1')->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /** Whether $ownerToken (the secret of a wizard session) owns the installation run in progress. */
    public function runOwnedBy(string $ownerToken): bool
    {
        $run = $this->loadRun();

        return $run !== null && $ownerToken !== '' && hash_equals((string) $run->get('owner', ''), hash('sha256', $ownerToken));
    }

    /**
     * Whether the run in progress is the installation of these database settings (the same database, mode, replacement
     * choice and SQL backup: its key). Only then do its steps go on with the settings of a browser (BI-RV-01): a run is
     * never continued on another database than the one it started on.
     *
     * @param array<string, mixed> $database
     */
    public function runMatches(array $database): bool
    {
        $run = $this->loadRun();

        return $run !== null && hash_equals((string) $run->get('key', ''), $this->runKey($database));
    }

    /** The run in progress is from now on the run of $ownerToken's session (after proveOwnership()). */
    public function adoptRun(string $ownerToken): void
    {
        $run = $this->loadRun();

        if ($run === null || $ownerToken === '') {
            return;
        }

        $run->set('owner', hash('sha256', $ownerToken));
        $run->save();
    }

    /**
     * The database settings of a run, for the session of an owner who proved it (mode and replacement as the run has
     * them; the password is the one the owner just gave).
     *
     * @return array<string, mixed>|null
     */
    public function runDatabaseSettings(string $password): ?array
    {
        $run = $this->loadRun();

        if ($run === null) {
            return null;
        }

        return $this->runTarget((array) $run->get('target', [])) + [
            'password' => $password,
            'mode' => (string) $run->get('mode', 'migrate') === 'backup' ? 'backup' : 'migrate',
            'overwrite' => (bool) $run->get('overwrite', false),
        ];
    }

    /**
     * Whether .env already holds an application key or a database user. A fresh upload's .env (a copy of .env.example)
     * has neither, so the wizard still opens there.
     */
    protected function hasConfiguredEnvironment(): bool
    {
        return trim((string) RuntimeEnv::get('APP_KEY', '')) !== ''
            || trim((string) RuntimeEnv::get('DB_USERNAME', '')) !== '';
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
        $folder = $this->siteFolder();

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
            $folder !== '' ? [
                // A site in a folder of its domain (round 3 review SB-RV-07): the domain's own document root stays; the
                // advice of a domain's root ("set the Running Directory to /public") would move the whole domain.
                'label' => 'Document root',
                'ok' => true,
                'detail' => 'The folder '.$folder.' of its domain',
                'help' => 'The domain keeps its own document root. Apache serves the folder with "Alias '.$folder.'" to the site\'s public/ folder, or the project folder unpacked as the folder '.$folder.' of the web root sends its requests to public/ itself (its .htaccess). On nginx the location blocks of docs/server-configs/nginx-subfolder.conf go into the domain\'s server block. Never point the domain\'s own document root at this site.',
            ] : [
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
        $folder = $this->siteFolder();

        if ($folder !== '') {
            return $this->folderConfigSnippet($folder, $public, $base);
        }

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
     * The folder of the domain the site is installed in ("/site" for https://example.com/site), "" at a domain's root.
     */
    private function siteFolder(): string
    {
        try {
            return function_exists('site_folder') ? site_folder() : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * The server setup of a site installed in a folder of its domain (round 3 review SB-RV-07): what goes into the
     * domain's own configuration, never a document root or a server block of its own. Apache's Alias and nginx's
     * nginx-subfolder.conf were tried on real servers (round 3, lane SB); others get the rule they must follow.
     */
    private function folderConfigSnippet(string $folder, string $public, string $base): string
    {
        return match (Environment::webServer()) {
            Environment::WEB_NGINX => <<<CONF
# In the domain's own server block (its root and its other locations stay):
# the location blocks of docs/server-configs/nginx-subfolder.conf, with
#   __PROJECT_ROOT__  ->  {$base}
#   /site             ->  {$folder}
#   and the PHP-FPM socket of your PHP 8.3.
# Without rewrite rules, the file's header says which part to leave out.
CONF,
            Environment::WEB_APACHE, Environment::WEB_LITESPEED => (Environment::webServer() === Environment::WEB_LITESPEED
                ? "# LiteSpeed reads Apache's form; a site in a folder was not tried on it.\n" : '').<<<CONF
# In the domain's own virtual host (its DocumentRoot stays):
Alias {$folder} "{$public}"
<Directory "{$public}">
    AllowOverride All
    Require all granted
</Directory>

# Or: the project folder unpacked as the folder {$folder} of the
# domain's web root. Its .htaccess sends the requests to public/.
CONF,
            default => <<<CONF
Serve the addresses under {$folder}/ from:
{$public}
Files as they are; every other address to {$folder}/index.php
(SCRIPT_NAME {$folder}/index.php) with the original query string.
The domain's own document root stays as it is.
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
            // The example address of .env.example (copied to .env by the requirements step) is never offered: the
            // field stays empty and the administrator's e-mail is used (R1-015).
            'mail_from_address' => self::realFromAddress((string) RuntimeEnv::get('MAIL_FROM_ADDRESS', '')),
            'upload18_api_key' => (string) RuntimeEnv::get('UPLOAD18_API_KEY', ''),
            'upload18_api_url' => (string) RuntimeEnv::get('UPLOAD18_API_URL', 'https://upload18.org/api'),
            'upload18_default_cid' => (string) RuntimeEnv::get('UPLOAD18_DEFAULT_CID', '15'),
            'upload18_default_mycid' => (string) RuntimeEnv::get('UPLOAD18_DEFAULT_MYCID', '0'),
            'upload18_default_fid' => (string) RuntimeEnv::get('UPLOAD18_DEFAULT_FID', '18'),
            'upload18_allow_server_fallback' => (string) RuntimeEnv::get('UPLOAD18_ALLOW_SERVER_FALLBACK', 'false'),
        ];
    }

    /**
     * The "From" address mail can really go out with, or '' for an example one: an address at a name reserved for
     * examples and tests (example.com, example.org, example.net, *.example, *.test, *.invalid, localhost; RFC 2606 and
     * 6761), such as noreply@example.com of .env.example. Every site that kept it sent its mail from that address,
     * which SPF and DMARC refuse with SMTP (R1-015). The installer then uses the administrator's e-mail.
     */
    public static function realFromAddress(?string $address): string
    {
        $address = trim((string) $address);
        $at = strrpos($address, '@');

        if ($at === false || $at === 0 || $at === strlen($address) - 1) {
            return '';
        }

        $domain = rtrim(strtolower(substr($address, $at + 1)), '.');

        if (in_array($domain, ['example.com', 'example.org', 'example.net', 'example', 'localhost', 'localhost.localdomain', 'test', 'invalid'], true)) {
            return '';
        }

        foreach (['.example', '.test', '.invalid', '.localhost', '.example.com', '.example.org', '.example.net'] as $suffix) {
            if (str_ends_with($domain, $suffix)) {
                return '';
            }
        }

        return $address;
    }

    /**
     * The scheduler's line exactly as the installer adds it to the crontab (with its lock: two runs never overlap) and
     * Tools > Scheduled tasks shows it; on Windows the command that makes the site's task in the Task Scheduler. The
     * line without the lock that this used to give ran the scheduler a second time every minute next to the installer's
     * own line (round 3 R3-036).
     */
    public function schedulerCronCommand(): string
    {
        $php = PhpBinary::resolve(['CRON_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']) ?: 'php';

        return \App\Support\LaravelSchedulerCron::lineFor($php);
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

        // Private files under storage/ (security plan S03): installing on a server that hands them out would publish
        // password-reset links, backups and settings, so this blocks. When the site cannot reach itself the answer is
        // unknown and nothing is listed.
        $exposure = $this->storageExposure();
        if ($exposure !== StorageExposureProbe::UNKNOWN) {
            $exposed = $exposure === StorageExposureProbe::EXPOSED;
            $checks[] = $this->check(
                __('Private files are not reachable from the web'),
                ! $exposed,
                true,
                $exposed ? __('Files in storage/ can be downloaded') : __('Protected'),
                __('Logs, backups and copies of your settings are kept in storage/. The web server must never hand them out.'),
                StorageExposureProbe::fix(),
                __('Point the website folder at public/'),
                false,
                StorageExposureProbe::NGINX_INCLUDE
            );
        }

        return $checks;
    }

    /**
     * Can anybody download the files under storage/? Asked once per request (App\Support\StorageExposureProbe), at the
     * address the wizard is open at. APP_URL is asked too only when it names that same host: before the installation it
     * is still the placeholder of .env.example (http://localhost) or a former site's, where any other web server answers
     * (round 3 R3-008; the probe itself counts only an answer that came from this site).
     */
    protected function storageExposure(): string
    {
        if ($this->storageExposure === null) {
            $bases = [];

            try {
                $root = (string) request()->root();
                $bases[] = $root;
                $appUrl = (string) config('app.url');
                $host = strtolower((string) parse_url($appUrl, PHP_URL_HOST));

                if ($host !== '' && $host === strtolower((string) parse_url($root, PHP_URL_HOST))) {
                    $bases[] = $appUrl;
                }
            } catch (Throwable) {
            }

            $this->storageExposure = (new StorageExposureProbe())->probe($bases);
        }

        return $this->storageExposure;
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

        $checks = array_map(function (array $item): array {
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
                    ? $this->writeAccessFix($path, 'Set write permission for the web/PHP user on this path.')
                    : 'Create this folder or let the installer create it if the parent directory is writable.',
                'auto_fix' => ! $exists || $label === 'Public storage link',
            ];
        }, $paths);

        // First: the folder the last step writes .env into (R1B-008). It used to be found out only at the very end, as a raw
        // "file_put_contents(.env): Permission denied" after every other step was filled in.
        array_unshift($checks, $this->projectFolderCheck());

        return $checks;
    }

    /**
     * Can PHP write the site's settings (.env) in the project folder? The last step of the installation writes them there:
     * into .env when it exists (the first request copies .env.example to it when the folder lets PHP), else as a new file
     * in the folder. Checked in the Filesystem step, before any database detail is asked (R1B-008).
     *
     * @return array{label:string,path:string,ok:bool,required:bool,detail:string,help:string,fix:string,auto_fix:bool}
     */
    public function projectFolderCheck(): array
    {
        $env = $this->envPath();
        $folder = dirname($env);
        $hasEnv = is_file($env);
        $ok = $hasEnv ? is_writable($env) : (is_dir($folder) && is_writable($folder));

        return [
            'label' => 'Project folder (.env)',
            'path' => $hasEnv ? $env : $folder,
            'ok' => $ok,
            'required' => true,
            'detail' => $ok ? 'Ready' : 'Not writable',
            'help' => 'The last step writes the site\'s settings (database, address, mail, keys) to the .env file in this folder.',
            // What counts is the owner of what is written: .env itself when it exists (it can belong to somebody else than
            // its folder, e.g. made by root with "cp .env.example .env" after the folder was given to PHP), else the folder.
            'fix' => $ok ? '' : ($hasEnv
                ? $this->writeAccessFix($env, 'Allow the user PHP runs as to write the .env file in this folder (in the file manager: Permissions, write for the owner), then click Repair and Continue.', false)
                : $this->writeAccessFix($folder, 'Allow the user PHP runs as to create files in this folder (in the file manager: Permissions, write for the owner), then click Repair and Continue.')),
            'auto_fix' => false,
        ];
    }

    /**
     * Who PHP runs as and who owns the site's files (Linux and other POSIX systems; nulls where PHP cannot tell, e.g. on
     * Windows or without the posix extension). For the permission hints of the Filesystem step (R1B-008).
     *
     * @return array{php_user: ?string, php_uid: ?int, php_gid: ?int, owner: ?string, owner_uid: ?int}
     */
    public function fileOwnership(?string $path = null): array
    {
        $info = ['php_user' => null, 'php_uid' => null, 'php_gid' => null, 'owner' => null, 'owner_uid' => null];

        if ($this->onWindows() || ! function_exists('posix_geteuid')) {
            return $info;
        }

        try {
            $uid = posix_geteuid();
            $info['php_uid'] = $uid;
            $info['php_gid'] = function_exists('posix_getegid') ? posix_getegid() : null;
            $user = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
            $info['php_user'] = is_array($user) && ($user['name'] ?? '') !== '' ? (string) $user['name'] : null;

            $ownerUid = @fileowner($path ?? base_path());

            if (is_int($ownerUid)) {
                $info['owner_uid'] = $ownerUid;
                $owner = function_exists('posix_getpwuid') ? @posix_getpwuid($ownerUid) : false;
                $info['owner'] = is_array($owner) && ($owner['name'] ?? '') !== '' ? (string) $owner['name'] : null;
            }
        } catch (Throwable) {
        }

        return $info;
    }

    /**
     * The fix for a path PHP cannot write: when PHP runs as another user than the one $path belongs to (a Docker bind
     * mount, files unpacked as root or by a deploy user), giving it to the PHP user is the fix - write permission alone
     * for the owner changes nothing. $folder: the whole site folder is given to the PHP user (a folder of the site);
     * otherwise just this file (.env). Without such a difference: $fallback. On Windows: the Modify permission.
     */
    private function writeAccessFix(string $path, string $fallback, bool $folder = true): string
    {
        if ($this->onWindows()) {
            return 'Allow Modify permission for the IIS/PHP or local web-server user on this folder.';
        }

        $who = $this->fileOwnership($path);

        if ($who['php_uid'] === null || $who['owner_uid'] === null || $who['php_uid'] === $who['owner_uid']) {
            return $fallback;
        }

        $php = ($who['php_user'] ?? 'uid '.$who['php_uid']).' (user id '.$who['php_uid'].')';
        $owner = ($who['owner'] ?? 'another user').' (user id '.$who['owner_uid'].')';
        $group = $who['php_gid'] ?? $who['php_uid'];

        if (! $folder) {
            return 'PHP runs as '.$php.', but this file belongs to '.$owner.'. Give it to the PHP user - in a terminal: '
                .'chown '.$who['php_uid'].':'.$group.' '.escapeshellarg($path)
                .' - or set that owner for the file in your panel\'s file manager. Then click Repair and Continue.';
        }

        return 'PHP runs as '.$php.', but this belongs to '.$owner.'. Give the site\'s folder to the PHP user - in a terminal: '
            .'chown -R '.$who['php_uid'].':'.$group.' '.escapeshellarg(base_path())
            .' - or set that owner for the folder and its subfolders in your panel\'s file manager. Then click Repair and Continue.';
    }

    /**
     * Who owns the site's .env file, when there is one (fileOwnership()): the Permission Guide names it when it is not the
     * user PHP runs as although the folder is (BI-RV-03).
     *
     * @return array{php_user: ?string, php_uid: ?int, php_gid: ?int, owner: ?string, owner_uid: ?int}|null
     */
    public function envFileOwnership(): ?array
    {
        $env = $this->envPath();

        return is_file($env) ? $this->fileOwnership($env) : null;
    }

    protected function onWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
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
     * The whole installation in this one process (the command line, tests): the same steps as the web wizard's, without
     * a time budget. The web wizard goes through startRun() and runStep() instead, a few seconds per request.
     *
     * @param array<string, mixed> $payload database, admin, app
     * @return array<int, string> the lines of the result page
     */
    public function install(array $payload): array
    {
        $token = bin2hex(random_bytes(16));
        $run = $this->beginRun($payload, $token, null, false);
        $progress = $this->advanceRun($run, $payload, false, null, true, $token);

        if ($progress['status'] !== 'finished') {
            throw new \RuntimeException((string) ($progress['error'] ?? 'The installation did not finish.'));
        }

        return $progress['messages'];
    }

    /**
     * Starts the installation for the wizard's session $ownerToken (R1B-001), or - the same database, mode and replacement
     * choice, by the same owner - goes on with the one it started earlier, where it stopped. Nothing is written to .env
     * here: the final .env (APP_INSTALLED=true, the new key) is written only after the database work and the administrator
     * account succeeded (runStep()). The database is inspected before anything else, as before: a database with tables is
     * only replaced when the owner chose it.
     *
     * @param array<string, mixed> $payload database, admin, app
     * @return array<string, mixed> the progress (runProgress())
     */
    public function startRun(array $payload, string $ownerToken): array
    {
        // Never while a step of the installation works (another tab of this browser, or another browser): the step would
        // write its own state over the one started here.
        $lock = InstallLock::acquire($this->runLockPath());

        if ($lock === null) {
            throw new \RuntimeException('A step of the installation is running right now (in another tab or browser). Wait a moment, then click Install Now again.');
        }

        try {
            // The last step writes the site's .env: an installation that cannot do that never starts (R1B-008; also when
            // the Filesystem step was passed before the permissions changed, or skipped).
            $folder = $this->projectFolderCheck();

            if (! $folder['ok']) {
                throw new \RuntimeException('PHP cannot write the site\'s .env file ('.$folder['path'].'), which the last step of the installation writes. '.$folder['fix']);
            }

            $existing = $this->loadRun();

            if ($existing !== null && $this->runUnfinished($existing) && ! hash_equals((string) $existing->get('owner', ''), hash('sha256', $ownerToken))) {
                if (! $this->envFlag('INSTALLER_ALLOW_REINSTALL')) {
                    throw new \RuntimeException('An installation started from another browser is in progress. Continue it there, or with the password of its database on the first page of the installer.');
                }

                // The owner's reinstall switch is set (BI-RV-02): this browser may start again in place of an installation
                // whose browser is gone - but never take over one that is still being driven.
                $idle = time() - (int) $existing->get('updated_at', 0);

                if ($idle < self::RUN_IDLE_SECONDS) {
                    $wait = (int) max(1, ceil((self::RUN_IDLE_SECONDS - $idle) / 60));

                    throw new \RuntimeException('An installation started from another browser is still running (its last step was '.max(0, $idle).' seconds ago). Continue it there, or with the password of its database on the first page of the installer. To start again with these settings instead, click Install Now again in '.$wait.' minute'.($wait === 1 ? '' : 's').', when it has stopped.');
                }
            }

            return $this->runProgress($this->beginRun($payload, $ownerToken, $existing, true));
        } finally {
            $lock->release();
        }
    }

    /**
     * One step of the installation (R1B-001): as much of the remaining work as fits into this request's time budget
     * (RUN_BUDGET, less when PHP's own limit is shorter or the host ended earlier requests), done under a lock that the
     * system releases however the request ends. A request that the host ended half-way (no PHP code runs then, not even
     * a shutdown function) is found out by the next one, which goes back to a safe point and on from there. When every
     * step is done and $finalize is true (a page request, not the background loop of the progress page), this request
     * also writes the final .env and the installer lock.
     *
     * @param array<string, mixed> $payload database, admin, app (from the owner's session)
     * @return array<string, mixed> the progress (runProgress()): status working, ready, finished, failed, busy, missing or not_owner
     */
    public function runStep(array $payload, string $ownerToken, bool $retry = false, bool $finalize = false): array
    {
        $run = $this->loadRun();

        if ($run === null) {
            return $this->runProgress(null) + ['status' => 'missing'];
        }

        if (! hash_equals((string) $run->get('owner', ''), hash('sha256', $ownerToken))) {
            return self::notOwnerProgress();
        }

        return $this->advanceRun($run, $payload, $retry, (float) $run->get('budget', self::RUN_BUDGET), $finalize, $ownerToken);
    }

    /** @return array<string, mixed> */
    private static function notOwnerProgress(): array
    {
        return ['status' => 'not_owner', 'percent' => 0, 'phases' => [], 'messages' => [], 'notes' => [], 'error' => null, 'busy' => false];
    }

    /**
     * The progress of the run for a browser whose database settings are not the run's own (BI-RV-01): status "changed",
     * nothing is done. The owner either enters the run's settings again or starts again with the new ones (Install Now).
     *
     * @return array<string, mixed>
     */
    public function changedProgress(?InstallRun $run = null): array
    {
        $run ??= $this->loadRun();
        $target = $run !== null ? $this->runTarget((array) $run->get('target', [])) : ['host' => '', 'port' => '', 'database' => '', 'username' => ''];
        $progress = $this->runProgress($run);
        // Nothing works for this browser: no phase is shown as "Working".
        $progress['phases'] = array_map(static fn (array $phase): array => $phase['state'] === 'current' ? ['state' => 'waiting'] + $phase : $phase, $progress['phases']);

        return array_merge($progress, [
            'status' => 'changed',
            'error' => 'The database settings of this browser are not the ones this installation started with'
                .($target['database'] !== '' ? ' (database '.$target['database'].' on '.$target['host'].')' : '')
                .'. Nothing was done. To go on with it, enter its settings again in the Database step; to install with the new settings, click Install Now on the Install step: the installation starts again.',
        ]);
    }

    /**
     * The progress of the installation in progress: its status, a percentage, its phases (done, current, waiting,
     * failed), the lines and notes of the result page and the error of a failed step.
     *
     * @return array{status: string, percent: int, phases: list<array{key: string, label: string, state: string, detail: string}>, messages: list<string>, notes: list<string>, error: ?string, busy: bool, mode?: string, scheduler?: ?array<string, mixed>}
     */
    public function runProgress(?InstallRun $run = null, bool $busy = false): array
    {
        $run ??= $this->loadRun();

        if ($run === null) {
            return ['status' => 'missing', 'percent' => 0, 'phases' => [], 'messages' => [], 'notes' => [], 'error' => null, 'busy' => false];
        }

        $mode = (string) $run->get('mode', 'migrate');
        $status = (string) $run->get('status', 'working');
        $jobs = (array) $run->get('jobs', []);
        $weights = ['prepare' => 3, 'reset' => 2, 'import' => 30, 'migrate' => $mode === 'backup' ? 35 : 75, 'seed' => 4, 'admin' => 2, 'settings' => 2, 'files' => 6, 'finish' => 3];
        $total = 0.0;
        $done = 0.0;
        $phases = [];
        $current = null;

        foreach (array_merge(array_keys($jobs), ['finish']) as $key) {
            $state = $key === 'finish' ? ($status === 'finished' ? 'done' : 'pending') : (string) ($jobs[$key] ?? 'pending');
            $weight = (float) ($weights[$key] ?? 2);
            $total += $weight;
            $detail = '';

            if ($state === 'done') {
                $done += $weight;
            } elseif ($current === null) {
                $current = $key;
                $state = $status === 'failed' ? 'failed' : 'current';
                $fraction = $this->jobFraction($run, $key, $detail);
                $done += $weight * $fraction;
            }

            $phases[] = ['key' => $key, 'label' => $this->phaseLabel($run, $key), 'state' => $state === 'pending' ? 'waiting' : $state, 'detail' => $detail];
        }

        $percent = $status === 'finished' ? 100 : (int) min(99, floor($total > 0 ? $done * 100 / $total : 0));

        return [
            'status' => $busy ? 'busy' : $status,
            'percent' => $percent,
            'phases' => $phases,
            'messages' => array_values((array) $run->get('messages', [])),
            'notes' => array_values((array) $run->get('notes', [])),
            'error' => $run->get('error'),
            'busy' => $busy,
            'mode' => $mode,
            // The last step: what was done about the scheduler's cron line (schedulerAdvice(), the result page).
            'scheduler' => is_array($run->get('scheduler')) ? $run->get('scheduler') : null,
        ];
    }

    /** The share of a job that is done (the import by bytes, the database changes by count) and its detail text. */
    private function jobFraction(InstallRun $run, string $job, string &$detail): float
    {
        if ($job === 'import') {
            $cursor = (array) $run->get('import', []);
            $size = (int) ($cursor['size'] ?? 0);
            $offset = (int) ($cursor['offset'] ?? 0);

            if ($size > 0) {
                $detail = $this->formatBytes(min($offset, $size)).' of '.$this->formatBytes($size);

                return max(0.0, min(1.0, $offset / $size));
            }
        }

        if ($job === 'migrate') {
            $migrate = (array) $run->get('migrate', []);
            $pending = (int) ($migrate['total'] ?? 0);
            $count = (int) ($migrate['done'] ?? 0);

            if ($pending > 0) {
                $detail = min($count, $pending).' of '.$pending;

                return max(0.0, min(1.0, $count / $pending));
            }
        }

        return 0.0;
    }

    private function phaseLabel(InstallRun $run, string $job): string
    {
        $backup = $run->get('backup');

        return match ($job) {
            'prepare' => $run->get('overwrite') ? 'Empty the selected database' : 'Check the selected database',
            'reset' => 'Empty the database again',
            'import' => 'Import '.(is_string($backup) && $backup !== '' ? basename($backup) : 'the SQL backup'),
            'migrate' => $run->get('mode') === 'backup' ? 'Apply the database changes of this version' : 'Create the database tables',
            'seed' => 'Add the basic categories and plans',
            'admin' => 'Create the admin account',
            'settings' => 'Save the site settings',
            'files' => 'Check files, permissions and caches',
            'finish' => 'Write .env and lock the installer',
            default => ucfirst($job),
        };
    }

    /**
     * A new run (InstallRun) for this payload, or $existing again when it is the same installation of the same owner.
     *
     * @param array<string, mixed> $payload
     */
    private function beginRun(array $payload, string $ownerToken, ?InstallRun $existing, bool $persistent): InstallRun
    {
        $database = $payload['database'];
        $overwrite = filter_var($database['overwrite'] ?? false, FILTER_VALIDATE_BOOL);
        $mode = $this->runMode($database);
        $backup = $mode === 'backup' ? (string) $this->backupInfo()['path'] : null;
        $target = $this->runTarget($database);
        $key = $this->runKey($database);
        $owner = hash('sha256', $ownerToken);

        if ($existing !== null && $existing->get('status') !== 'finished'
            && hash_equals((string) $existing->get('key', ''), $key) && hash_equals((string) $existing->get('owner', ''), $owner)) {
            // The same installation again (the Install step sent once more, e.g. after going back to the Admin step):
            // it goes on where it stopped. The account, settings and file steps run again with this request's values.
            $this->redoAccountSteps($existing);
            $existing->set('values', $this->valuesFingerprint($payload, $ownerToken));
            $existing->save();

            return $existing;
        }

        // Inspect the selected database before any installation state changes.
        $pdo = $this->targetDatabasePdo($database);
        $databaseObjects = $this->databaseObjects($pdo, (string) $database['database']);

        if ($databaseObjects !== [] && ! $overwrite) {
            throw new \RuntimeException(
                'The selected database already contains tables or views. Existing data was preserved. Return to the Database step and enable replacement only when this is the intended database to replace.'
            );
        }

        // Another installation of this owner (another database, mode or replacement choice) is replaced by this one.
        $existing?->delete();

        $data = [
            'id' => bin2hex(random_bytes(8)),
            'key' => $key,
            'owner' => $owner,
            'created_at' => time(),
            'target' => $target,
            'mode' => $mode,
            'overwrite' => $overwrite,
            'backup' => $backup,
            // Whether its owner can prove it from another browser (its database user has a password, proveOwnership()).
            'provable' => (string) ($database['password'] ?? '') !== '',
            // The admin and site values the account, settings and file steps use (advanceRun(): changed ones run them again).
            'values' => $this->valuesFingerprint($payload, $ownerToken),
            // Whether a step has begun changing the database (isInstalled(): a reinstallation of the site's own database).
            'touched' => false,
            'jobs' => array_fill_keys($this->runJobs($mode), 'pending'),
            'status' => 'working',
            'error' => null,
            'failed' => null,
            'running' => null,
            'budget' => self::RUN_BUDGET,
            'ends' => [],
            'ends_total' => 0,
            'restarts' => 0,
            'import' => self::importCursor($backup),
            'migrate' => ['total' => null, 'done' => 0],
            'messages' => [],
            'notes' => [],
            'found_objects' => count($databaseObjects),
        ];

        return $persistent ? InstallRun::create($this->runStatePath(), $data) : InstallRun::memory($data);
    }

    /**
     * The key of an installation: the database it works on (host, port, name, user), its mode, the replacement choice and
     * the SQL backup it imports. A run goes on only with settings of the same key (beginRun(), advanceRun()).
     *
     * @param array<string, mixed> $database
     */
    private function runKey(array $database): string
    {
        $mode = $this->runMode($database);
        $backup = $mode === 'backup' ? (string) $this->backupInfo()['path'] : null;
        $overwrite = filter_var($database['overwrite'] ?? false, FILTER_VALIDATE_BOOL);

        return hash('sha256', (string) json_encode([$this->runTarget($database), $mode, $overwrite, $backup]));
    }

    /**
     * A fingerprint of the admin account and site values of a browser's session (its passwords included), keyed by the
     * secret of that session: the run file can tell whether the values changed, and nobody who reads it can test guesses.
     *
     * @param array<string, mixed> $payload
     */
    private function valuesFingerprint(array $payload, string $ownerToken): string
    {
        return hash_hmac('sha256', (string) json_encode([$payload['admin'] ?? [], $payload['app'] ?? []]), $ownerToken);
    }

    /** The account, settings and file steps run again (with the values of the browser that drives the run now). */
    private function redoAccountSteps(InstallRun $run): void
    {
        $jobs = (array) $run->get('jobs', []);

        foreach (['admin', 'settings', 'files'] as $job) {
            if (($jobs[$job] ?? null) === 'done') {
                $jobs[$job] = 'pending';
            }
        }

        $run->set('jobs', $jobs);

        if ($run->get('status') === 'ready') {
            $run->set('status', 'working');
        }
    }

    /**
     * The steps of an installation, in order. Every one can run again after a request that the host ended inside it:
     * "prepare" and "reset" empty the database again, "import" goes back to the start of the table it was importing,
     * "migrate" runs the change that was cut off again only after rebuilding the database ("reset"), "seed" is one
     * database transaction, and the account, settings and file steps write the same values again.
     *
     * @return list<string>
     */
    private function runJobs(string $mode): array
    {
        return $mode === 'backup'
            ? ['prepare', 'import', 'migrate', 'admin', 'settings', 'files']
            : ['prepare', 'migrate', 'seed', 'admin', 'settings', 'files'];
    }

    /** "backup" when the wizard chose the SQL backup and there is one (as before), else "migrate". */
    private function runMode(array $database): string
    {
        $backupInfo = $this->backupInfo();
        $defaultMode = $backupInfo['exists'] ? 'backup' : 'migrate';

        return ($database['mode'] ?? $defaultMode) === 'backup' && $backupInfo['path'] ? 'backup' : 'migrate';
    }

    /**
     * @param array<string, mixed> $database
     * @return array{host: string, port: string, database: string, username: string}
     */
    private function runTarget(array $database): array
    {
        return [
            'host' => trim((string) ($database['host'] ?? '')),
            'port' => trim((string) (($database['port'] ?? '') ?: '3306')),
            'database' => trim((string) ($database['database'] ?? '')),
            'username' => trim((string) ($database['username'] ?? '')),
        ];
    }

    /**
     * Where the import of the SQL backup stands: the byte where it goes on, the statements done, and the start of the
     * table being imported (a request that is ended inside a table goes back there).
     *
     * @return array{size: int, offset: int, statements: int, preamble_end: ?int, block_start: ?int, block_statements: int, block_table: ?string, block_kind: ?string, replay: bool}
     */
    private static function importCursor(?string $path = null): array
    {
        return [
            'size' => $path !== null && is_file($path) ? (int) filesize($path) : 0,
            'offset' => 0,
            'statements' => 0,
            'preamble_end' => null,
            'block_start' => null,
            'block_statements' => 0,
            'block_table' => null,
            'block_kind' => null,
            'replay' => false,
        ];
    }

    /** The state of the installation in progress (App\Support\InstallRun; a test points it at a file of its own). */
    protected function runStatePath(): string
    {
        return storage_path('framework/avd-install-run.json');
    }

    /** The lock file of the installation's steps (App\Support\InstallLock). */
    protected function runLockPath(): string
    {
        return storage_path('framework/avd-install.lock');
    }

    protected function loadRun(): ?InstallRun
    {
        try {
            return InstallRun::load($this->runStatePath());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The work of one request (runStep()), or of the whole installation in one process (install(): no budget).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function advanceRun(InstallRun $run, array $payload, bool $retry, ?float $budget, bool $finalize, string $ownerToken): array
    {
        if ($run->get('status') === 'finished') {
            return $this->runProgress($run);
        }

        @ini_set('memory_limit', '512M');
        // A web step: PHP's own limit well above the step's budget. The whole installation in one process: none.
        TimeBudget::raise($run->persistent() ? self::RUN_TIME_LIMIT : 0);

        $lock = InstallLock::acquire($run->persistent() ? $this->runLockPath() : null);

        if ($lock === null) {
            return $this->runProgress($run, true);
        }

        try {
            // Under the lock: what the request before this one wrote (another browser may even have started again).
            $run->reload();

            if (! hash_equals((string) $run->get('owner', ''), hash('sha256', $ownerToken))) {
                return self::notOwnerProgress();
            }

            if ($run->get('status') === 'finished') {
                return $this->runProgress($run);
            }

            // A run goes on only on the database (mode, replacement, backup) it started with (BI-RV-01): the session's
            // settings were changed after Install Now (the Database step again, then the progress page) - nothing is done.
            if (! hash_equals((string) $run->get('key', ''), $this->runKey($payload['database']))) {
                return $this->changedProgress($run);
            }

            if ($run->get('status') === 'failed' && ! $retry) {
                return $this->runProgress($run);
            }

            $this->applyRuntimeDatabaseConfig($payload['database']);

            if (! $lock->withDatabase($this->runConnection())) {
                return $this->runProgress($run, true);
            }

            $running = $run->get('running');

            if (is_array($running) && ! $lock->real() && time() - (int) ($running['at'] ?? 0) < self::RUN_STALE_SECONDS) {
                // Nothing here can be locked: a young note belongs to a step that is still at work.
                return $this->runProgress($run, true);
            }

            if ($retry && $run->get('status') === 'failed') {
                $run->set('status', 'working');
                $run->set('error', null);
                $this->recover($run, is_array($run->get('failed')) ? $run->get('failed') : null, false);
                $run->set('failed', null);
            }

            if (is_array($running)) {
                // The request before this one was ended from outside (its note was never cleared): back to a safe point.
                $this->recover($run, $running, true);

                if ($run->get('status') === 'failed') {
                    $run->save();

                    return $this->runProgress($run);
                }

                $budget = $budget === null ? null : (float) $run->get('budget', self::RUN_BUDGET);
            }

            // The admin and site values of this browser are not the ones the account, settings and file steps used (the
            // Admin step was sent again, or another browser of the owner took the run over): those steps run again, so the
            // account, the settings and the final .env all hold the values of this browser.
            $values = $this->valuesFingerprint($payload, $ownerToken);

            if (! hash_equals((string) $run->get('values', ''), $values)) {
                $this->redoAccountSteps($run);
                $run->set('values', $values);
            }

            $reserve = 0.0;

            if ($budget !== null) {
                $granted = TimeBudget::start($budget, 5.0, 1.0);
                $reserve = min(5.0, $granted / 3);
            }

            $run->set('running', ['at' => time(), 'job' => null, 'point' => null]);
            $run->save();
            $units = 0;
            $job = null;

            while (($job = $this->nextJob($run)) !== null) {
                if ($units > 0 && TimeBudget::left() < $reserve) {
                    break;
                }

                if ($job !== 'prepare') {
                    // Every step after "prepare" changes the database (prepare notes it itself, only when it empties one).
                    $run->set('touched', true);
                }

                $this->markRunning($run, $job, null);
                $complete = $this->runJob($job, $run, $payload, $reserve);
                $units++;

                if (! $complete) {
                    break;
                }

                $jobs = (array) $run->get('jobs', []);
                $jobs[$job] = 'done';
                $run->set('jobs', $jobs);
                $run->save();
            }

            if ($this->nextJob($run) === null) {
                $run->set('status', 'ready');

                if ($finalize) {
                    $this->finishRun($run, $payload);

                    return $this->runProgress($run);
                }
            }

            $run->set('running', null);
            $run->save();
        } catch (Throwable $e) {
            report($e);
            $point = $run->get('running');
            $run->set('running', null);
            $run->set('status', 'failed');
            $run->set('failed', is_array($point) ? $point : null);
            $run->set('error', $this->runErrorText($e, is_array($point) ? $point : null, $run));

            try {
                $run->save();
            } catch (Throwable) {
            }

            if (! $run->persistent()) {
                // install() in one process: its caller sees the error itself.
                throw $e;
            }
        } finally {
            TimeBudget::clear();
            $lock->release();
        }

        return $this->runProgress($run);
    }

    /** The connection the installation works on (the site's database, with the wizard's settings). */
    private function runConnection(): ?\Illuminate\Database\Connection
    {
        try {
            $connection = DB::connection();

            return $connection instanceof \Illuminate\Database\Connection ? $connection : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function nextJob(InstallRun $run): ?string
    {
        foreach ((array) $run->get('jobs', []) as $job => $state) {
            if ($state !== 'done') {
                return (string) $job;
            }
        }

        return null;
    }

    /** Notes what is about to run BEFORE it runs: a request ended inside it leaves this note for the next one. */
    private function markRunning(InstallRun $run, string $job, ?string $point): void
    {
        $run->set('running', ['at' => time(), 'job' => $job, 'point' => $point]);
        $run->save();
    }

    /**
     * Back to a safe point after a request the host ended ($killed) or a step that failed, for the step that was at work
     * ($point: its job and where in it). An ended request also halves the time of the next ones (down to RUN_MIN_BUDGET),
     * and when the host ends requests at the same point again and again the installation stops and says why.
     *
     * @param array{job?: ?string, point?: ?string}|null $point
     */
    private function recover(InstallRun $run, ?array $point, bool $killed): void
    {
        $job = isset($point['job']) && is_string($point['job']) ? $point['job'] : null;
        $where = $job === null ? 'start' : $job.':'.(string) ($point['point'] ?? '');

        if ($killed) {
            $ends = (array) $run->get('ends', []);
            $ends[$where] = (int) ($ends[$where] ?? 0) + 1;
            $run->set('ends', $ends);
            $run->set('ends_total', (int) $run->get('ends_total', 0) + 1);
            $budget = max(self::RUN_MIN_BUDGET, (float) $run->get('budget', self::RUN_BUDGET) / 2);
            $run->set('budget', $budget);
            $run->note('The server ended a step of the installation before it was done (its time limit for one request). The installer went back to a safe point and goes on in shorter steps.');
        }

        if ($job === 'import') {
            // Back to the start of the table that was being imported: its statements run again from there.
            $cursor = (array) $run->get('import', []) + self::importCursor();

            if ($cursor['block_start'] !== null) {
                $cursor['offset'] = (int) $cursor['block_start'];
                $cursor['statements'] = (int) $cursor['block_statements'];
                $cursor['replay'] = in_array($cursor['block_kind'], ['create', 'insert'], true);
            } else {
                $cursor['offset'] = 0;
                $cursor['statements'] = 0;
                $cursor['replay'] = false;
            }

            $run->set('import', $cursor);
        }

        if ($job === 'migrate' && is_string($point['point'] ?? null) && $point['point'] !== '' && ! $this->migrationRan($point['point'])) {
            // A database change that was cut off half-way cannot be trusted (and most cannot run twice): the database is
            // emptied and built again from the start.
            $this->scheduleReset($run);
        }

        $run->set('running', null);

        if ($killed && ($ends[$where] ?? 0) >= self::RUN_MAX_ENDS_AT_ONE_POINT && (float) $run->get('budget') <= self::RUN_MIN_BUDGET) {
            $run->set('status', 'failed');
            $run->set('failed', null);
            $run->set('error', 'The server ended the installation '.$ends[$where].' times at the same point ('.$this->pointLabel($run, $point).'), even in short steps. Ask your host to allow longer requests for this site (PHP-FPM request_terminate_timeout or the web server\'s timeout, at least 60 seconds), then click Try again.');
        }
    }

    /** The database work starts again from an empty database (after a change that was cut off half-way). */
    private function scheduleReset(InstallRun $run): void
    {
        $jobs = ['prepare' => 'done', 'reset' => 'pending'];

        foreach ($this->runJobs((string) $run->get('mode', 'migrate')) as $job) {
            if ($job !== 'prepare') {
                $jobs[$job] = 'pending';
            }
        }

        $run->set('jobs', $jobs);
        $run->set('import', self::importCursor(is_string($run->get('backup')) ? $run->get('backup') : null));
        // what the changes took on this server stays known: the rebuild starts the slow ones early in a step
        $run->set('migrate', ['total' => null, 'done' => 0, 'slowest' => (array) (((array) $run->get('migrate', []))['slowest'] ?? [])]);
        $run->set('restarts', (int) $run->get('restarts', 0) + 1);
        $run->note('A database change was cut off half-way, so the installer emptied the database and built it again from the start.');
    }

    /** @param array{job?: ?string, point?: ?string}|null $point */
    private function pointLabel(InstallRun $run, ?array $point): string
    {
        $job = (string) ($point['job'] ?? '');
        $label = $job !== '' ? $this->phaseLabel($run, $job) : 'the start of a step';

        if ($job === 'migrate' && is_string($point['point'] ?? null) && $point['point'] !== '') {
            return $label.': '.$point['point'];
        }

        return $label;
    }

    /** Whether the migration $name is recorded as run in the database the installation works on. */
    private function migrationRan(string $name): bool
    {
        try {
            $repository = app('migrator')->getRepository();

            return $repository->repositoryExists() && in_array($name, $repository->getRan(), true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array{job?: ?string, point?: ?string}|null $point
     */
    private function runErrorText(Throwable $e, ?array $point, InstallRun $run): string
    {
        $message = trim($e->getMessage());

        // The installer's own refusals are written for the owner; anything else names where it happened.
        if ($e instanceof \RuntimeException && ! $e instanceof \Illuminate\Database\QueryException && ! $e instanceof \PDOException) {
            return $message !== '' ? $message : 'The installation could not go on.';
        }

        $where = isset($point['job']) && is_string($point['job']) ? ' (step: '.$this->pointLabel($run, $point).')' : '';

        return Str::limit(class_basename($e).': '.$message, 400).$where;
    }

    /**
     * @param array<string, mixed> $payload
     * @return bool true: the job is done; false: this request's time is up and the next request goes on with it
     */
    private function runJob(string $job, InstallRun $run, array $payload, float $reserve): bool
    {
        return match ($job) {
            'prepare' => $this->jobPrepare($run, $payload['database']),
            'reset' => $this->jobReset($payload['database']),
            'import' => $this->jobImport($run, $payload['database'], $reserve),
            'migrate' => $this->jobMigrate($run, $reserve),
            'seed' => $this->jobSeed($run),
            'admin' => $this->jobAdmin($run, $payload['admin']),
            'settings' => $this->jobSettings($run, $payload['admin'], $payload['app']),
            'files' => $this->jobFiles($run, $payload['app']),
            default => true,
        };
    }

    /** @param array<string, mixed> $database */
    private function jobPrepare(InstallRun $run, array $database): bool
    {
        $pdo = $this->targetDatabasePdo($database);
        $objects = $this->databaseObjects($pdo, (string) $database['database']);

        if ($objects !== []) {
            if (! $run->get('overwrite')) {
                throw new \RuntimeException(
                    'The selected database already contains tables or views. Existing data was preserved. Return to the Database step and enable replacement only when this is the intended database to replace.'
                );
            }

            // Noted BEFORE the first table goes: a reinstallation of the site's own database is from here on no longer
            // the installed site (isInstalled()), even when the host ends this request in the middle of it.
            if ($run->get('touched') !== true) {
                $run->set('touched', true);
                $run->save();
            }

            $this->dropExistingObjects($pdo, $objects);
        }

        $found = (int) $run->get('found_objects', 0);

        if ($run->get('overwrite') && $found > 0) {
            $run->message('Explicit replacement enabled: removed '.$found.' existing database tables and views.');
        }

        $this->repairFilesystem();
        $run->message('Runtime directories checked.');

        return true;
    }

    /** @param array<string, mixed> $database */
    private function jobReset(array $database): bool
    {
        $pdo = $this->targetDatabasePdo($database);
        $objects = $this->databaseObjects($pdo, (string) $database['database']);

        if ($objects !== []) {
            $this->dropExistingObjects($pdo, $objects);
        }

        return true;
    }

    /** @param array<string, mixed> $database */
    private function jobImport(InstallRun $run, array $database, float $reserve): bool
    {
        $path = (string) $run->get('backup', '');

        if ($path === '' || ! is_file($path)) {
            throw new \RuntimeException('The SQL backup of this installation is no longer there ('.basename($path).'). Return to the Database step.');
        }

        $cursor = (array) $run->get('import', []) + self::importCursor($path);
        $cursor['size'] = (int) filesize($path);
        $done = $this->importSqlBackupFrom($this->targetDatabasePdo($database), $path, $cursor, function (array $cursor) use ($run): void {
            // Before the first statement of a table: a request ended inside it goes back here.
            $run->set('import', $cursor);
            $run->set('running', ['at' => time(), 'job' => 'import', 'point' => (string) $cursor['block_start']]);
            $run->save();
        }, $reserve);
        $run->set('import', $cursor);

        if ($done) {
            $run->message('Imported SQL backup '.basename($path).' ('.$cursor['statements'].' statements).');
        }

        return $done;
    }

    /**
     * The database changes (migrations) of this version that have not run yet, one at a time and in order: a new one
     * starts only while there is time for it (the first one of a request always), and each one is noted before it
     * starts. In the SQL backup mode a change that fails does not stop the installation (as before: the backup may hold
     * it already); in the migrations mode it does.
     */
    private function jobMigrate(InstallRun $run, float $reserve): bool
    {
        $this->listenForMigrations();
        $migrate = (array) $run->get('migrate', []) + ['total' => null, 'done' => 0];

        if ($migrate['total'] === null) {
            $migrate['total'] = $this->pendingMigrationCount();
            $migrate['done'] = 0;
            $run->set('migrate', $migrate);
        }

        // Done before this request: counted once; then one more per change that starts (no extra query per change).
        $doneBefore = max(0, (int) $migrate['total'] - $this->pendingMigrationCount());
        $slice = new \stdClass();
        $slice->reserve = $reserve;
        $slice->started = 0;
        $slice->last = null;
        $slice->lastSeconds = 0.0;
        // The slowest change this installation met so far (also before a rebuild): what this server may need again.
        $slice->slowest = (float) max([0.0, ...array_map('floatval', array_values((array) ($migrate['slowest'] ?? [])))]);
        $slice->mark = function (string $name) use ($run, $slice, $doneBefore): void {
            $migrate = (array) $run->get('migrate', []);
            $migrate['done'] = $doneBefore + $slice->started - 1;
            // The slowest changes of this installation (what a host with a short limit would have to allow).
            if (is_array($slice->last)) {
                $slow = (array) ($migrate['slowest'] ?? []);
                $slow[$slice->last[0]] = round($slice->lastSeconds, 2);
                arsort($slow);
                $migrate['slowest'] = array_slice($slow, 0, 5, true);
            }
            $slice->last = [$name, microtime(true)];
            $run->set('migrate', $migrate);
            $run->set('running', ['at' => time(), 'job' => 'migrate', 'point' => $name]);
            $run->save();
        };
        self::$migrationSlice = $slice;
        $failed = null;

        try {
            Artisan::call('migrate', ['--force' => true]);
            $done = true;
        } catch (InstallPaused) {
            $done = false;
        } catch (Throwable $e) {
            if ($run->get('mode') !== 'backup') {
                throw $e;
            }

            report($e);
            $failed = $e;
            $done = true;
        } finally {
            self::$migrationSlice = null;
        }

        $migrate = (array) $run->get('migrate', []);
        $migrate['done'] = max(0, (int) ($migrate['total'] ?? 0) - $this->pendingMigrationCount());

        if (is_array($slice->last) && $failed === null) {
            // the last change of this request (no next one started after it)
            $slow = (array) ($migrate['slowest'] ?? []);
            $slow[$slice->last[0]] = round(microtime(true) - $slice->last[1], 2);
            arsort($slow);
            $migrate['slowest'] = array_slice($slow, 0, 5, true);
        }

        $run->set('migrate', $migrate);

        if ($done && $run->get('mode') === 'backup') {
            if ($failed === null) {
                $run->message('Database schema migrations synchronized.');
            } else {
                $run->note('Some database changes of this version could not be applied to the imported backup ('
                    .Str::limit(class_basename($failed).': '.$failed->getMessage(), 200).'). The site works; run "php artisan migrate" later or ask for help in the Telegram group.');
            }
        }

        return $done;
    }

    /** How many migration files have not run yet on the database the installation works on. */
    private function pendingMigrationCount(): int
    {
        try {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
            $repository = $migrator->getRepository();
            $ran = $repository->repositoryExists() ? $repository->getRan() : [];

            return count(array_diff(array_keys($files), $ran));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Stops the migrations of a step before the next one starts when the step's time is used up, and notes each one
     * before it starts (MigrationStarted fires before a migration's up() and after the one before it was recorded). Only
     * while a step of the installation runs (self::$migrationSlice); other migrations (php artisan migrate, the update's
     * Finish) are not touched.
     */
    private function listenForMigrations(): void
    {
        $events = app('events');

        if (self::$listeningOn?->get() === $events) {
            return;
        }

        self::$listeningOn = \WeakReference::create($events);
        $events->listen(MigrationStarted::class, static function (MigrationStarted $event): void {
            $slice = self::$migrationSlice;

            if ($slice === null || $event->method !== 'up') {
                return;
            }

            // How long the changes took so far: on a server that made one of them slow, the next one may be as slow, so a
            // new change starts only with half as much time again left (the first change of a request always runs: on a
            // struggling server the steps become one heavy change each, and none of them runs into the host's limit).
            if (is_array($slice->last)) {
                $slice->lastSeconds = microtime(true) - $slice->last[1];
                $slice->slowest = max($slice->slowest, $slice->lastSeconds);
            }

            if ($slice->started > 0 && TimeBudget::left() < max($slice->reserve, 1.5 * $slice->slowest)) {
                throw new InstallPaused('This step of the installation is out of time; the next one goes on.');
            }

            $slice->started++;
            ($slice->mark)(self::migrationName($event->migration));
        });
    }

    /** The name of a migration (its file name), also for the anonymous classes of the migration files. */
    private static function migrationName(object $migration): string
    {
        $file = (new \ReflectionObject($migration))->getFileName();

        return is_string($file) && $file !== '' ? basename($file, '.php') : get_class($migration);
    }

    /**
     * "Run migrations and seeders": the basic categories and plans, and the settings of the bundled SQL backup that this
     * mode starts with, in ONE database transaction - a request ended inside it leaves nothing of it (the database rolls
     * it back), and once it is done the categories exist, so it never runs twice.
     */
    private function jobSeed(InstallRun $run): bool
    {
        $connection = DB::connection();

        if (! DB::table('categories')->exists() && ! DB::table('plans')->exists()) {
            // Before the transaction (ALTER TABLE ends one on MySQL / MariaDB): the ids start where an uninterrupted
            // installation starts them (round 3 R3-037).
            $this->resetSeedAutoIncrements($connection);
        }

        $connection->beginTransaction();

        try {
            if (! DB::table('categories')->exists() && ! DB::table('plans')->exists()) {
                Artisan::call('db:seed', ['--force' => true]);
                $this->applyBundledTelegramDefaults();
                $this->applyShippedUpload18Key();
            }

            $connection->commit();
        } catch (Throwable $e) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            throw $e;
        }

        $run->message('Database migrated and seeded.');

        return true;
    }

    /** The tables the seeding transaction writes rows into (jobSeed()): the seeders' and the bundled settings' rows. */
    private const array SEEDED_TABLES = ['categories', 'plans', 'settings'];

    /** resetSeedAutoIncrements(): the longest wait (seconds) for a seeded table's metadata lock. */
    private const int SEED_LOCK_WAIT = 5;

    /**
     * InnoDB keeps the auto-increment numbers that the rows of a rolled-back transaction took: after a request the host
     * ended inside the seeding transaction (R1B-001: it is rolled back and runs again), the 16 basic categories of the
     * resumed installation got ids 6-21 instead of 1-16 (round 3 R3-037). Each seeded table's next id is set back to
     * the lowest free one first - 1 for the empty categories and plans, the highest id + 1 for the settings - so a
     * resumed installation gives the same ids as an uninterrupted one. MySQL and MariaDB only: SQLite gives a
     * rolled-back row's number back by itself. Nothing here can stop the seeding (the ids only): ALTER TABLE waits for
     * the table's metadata lock, which the ended request's session holds until the server has rolled it back (at once
     * when its connection closed, later behind a proxy that keeps it), and the server's own wait is a year on MySQL and
     * a day on MariaDB - so this session waits at most SEED_LOCK_WAIT seconds per table here, then the seeding goes on
     * with the numbers as they are.
     */
    private function resetSeedAutoIncrements(\Illuminate\Database\ConnectionInterface $connection): void
    {
        if (! $connection instanceof \Illuminate\Database\Connection || ! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $previousWait = null;

        try {
            $row = $connection->selectOne('SELECT @@SESSION.lock_wait_timeout AS wait_seconds');
            $value = is_object($row) ? ($row->wait_seconds ?? null) : (is_array($row) ? ($row['wait_seconds'] ?? null) : null);
            $previousWait = is_numeric($value) ? (int) $value : null;
            $connection->statement('SET SESSION lock_wait_timeout = '.self::SEED_LOCK_WAIT);
        } catch (Throwable) {
            // A server without the variable: its own wait applies.
        }

        try {
            foreach (self::SEEDED_TABLES as $table) {
                try {
                    if (! $connection->getSchemaBuilder()->hasTable($table)) {
                        continue;
                    }

                    $next = (int) $connection->table($table)->max('id') + 1;
                    $connection->statement('ALTER TABLE '.$this->quoteIdentifier($connection->getTablePrefix().$table).' AUTO_INCREMENT = '.$next);
                } catch (Throwable) {
                    // The numbers of the ids only: the seeding goes on as before.
                }
            }
        } finally {
            if ($previousWait !== null && $previousWait > 0) {
                try {
                    $connection->statement('SET SESSION lock_wait_timeout = '.$previousWait);
                } catch (Throwable) {
                    // The session ends with the request.
                }
            }
        }
    }

    /** @param array<string, string> $admin */
    private function jobAdmin(InstallRun $run, array $admin): bool
    {
        $this->configureAdmin($admin);
        $run->message('Admin account created or updated.');

        return true;
    }

    /**
     * @param array<string, string> $admin
     * @param array<string, string> $app
     */
    private function jobSettings(InstallRun $run, array $admin, array $app): bool
    {
        $this->writeCoreSettings($admin, $app, $run);
        $run->message('Core site settings saved.');

        return true;
    }

    /** @param array<string, string> $app */
    private function jobFiles(InstallRun $run, array $app): bool
    {
        $this->prepareFiles($run);
        $this->writeRobotsTxt($run, $this->siteAddress($app));

        return true;
    }

    /**
     * The site's own robots.txt (round 4, R3M-02): web servers serve public/robots.txt before the site's /robots.txt, so
     * the file gets the SEO settings' text and the sitemap's whole address at the site's final address (App\Support\RobotsTxt;
     * the tests' scratch folder in the test process). A robots.txt the owner wrote by hand stays. Non-fatal: a file the
     * server may not write keeps the shipped one and a note on the result page.
     */
    private function writeRobotsTxt(InstallRun $run, string $siteAddress): void
    {
        try {
            $result = RobotsTxt::refresh($siteAddress, true);
            $sitemap = str_contains(RobotsTxt::text($siteAddress), "\nSitemap: ");
        } catch (Throwable $e) {
            report($e);
            $result = ['failed' => ['public/robots.txt'], 'saved' => [], 'left' => []];
            $sitemap = false;
        }

        if ($result['failed'] !== []) {
            $run->note('robots.txt could not be written ('.implode(', ', $result['failed']).'): search engines get the shipped one, without the address of the sitemap. Let the web server write that file, then open Settings > Google & sharing in the admin.');
        } elseif ($result['left'] !== []) {
            $run->message('robots.txt left as it is ('.implode(', ', $result['left']).': changed by hand).');
        } else {
            $run->message($sitemap ? 'robots.txt written with the address of the sitemap.' : 'robots.txt written.');
        }
    }

    /** The storage link, permissions, the import template and the caches (a test replaces it: they act on the copy itself). */
    protected function prepareFiles(InstallRun $run): void
    {
        try {
            Artisan::call('storage:link');
            $run->message('Public storage link checked.');
        } catch (Throwable $e) {
            $run->message('Storage link could not be created automatically ('.$e->getMessage().'). Public files are still served through the application fallback route; run php artisan storage:link later if your host allows symlinks.');
        }

        // Apply environment repairs including 644 file and 755 directory permissions
        try {
            EnvironmentRepairer::repairInstallation();
            $run->message('Standard permissions (644 files, 755 directories) and environment verified.');
        } catch (Throwable) {
            // Non-fatal if server restricts chmod
        }

        // An inactive import template; importing content requires an explicit admin action after installation.
        try {
            \App\Http\Controllers\Admin\XvidImportController::ensureDefaultScheduleExists();
        } catch (Throwable) {
            // Non-fatal
        }

        try {
            Artisan::call('optimize:clear');
            $run->message('Laravel caches cleared.');
        } catch (Throwable) {
            // cache clear is helpful, not fatal
        }
    }

    /**
     * The last step, only after the database work and the administrator account succeeded (R1B-001): the final .env
     * (APP_INSTALLED=true, the new key; INSTALLER_ALLOW_REINSTALL is dropped only now), then the lock, then the
     * scheduler's cron line (it must never run against the half-made site). The run's note is deleted: nothing is
     * unfinished any more. A request ended between .env and the lock leaves an installed site (APP_INSTALLED=true with
     * its administrator).
     *
     * @param array<string, mixed> $payload
     */
    private function finishRun(InstallRun $run, array $payload): void
    {
        $this->markRunning($run, 'finish', null);
        $this->verifyFinalDatabase($run, $payload['database']);
        $this->writeEnvironment($this->envValues($payload['database'], $payload['admin'], $payload['app']));
        // A request ended from here on left an installed site: the next visit adds the lock (completeCutOffFinish()).
        $this->markRunning($run, 'finish', 'env-written');
        $run->message('.env file written for production.');
        $this->writeLock($payload['admin'], $payload['app']);
        $run->message('Installer locked.');

        // What really happened to the scheduler's cron line (round 3 R3-036: "Scheduler configured." was said whatever
        // ensureInstalled() answered, also on Windows and where no line could be added). The result page tells the owner
        // to add a line only when the installer added none (schedulerAdvice()). A replacement of installSchedulerCron()
        // that says nothing (a test's) leaves no line about it, and the result page points to the admin's page.
        $this->schedulerCronResult = null;

        try {
            $this->installSchedulerCron();
            $scheduler = $this->schedulerCronResult !== null ? $this->schedulerAdvice($this->schedulerCronResult) : null;
        } catch (Throwable $e) {
            // Non-fatal: the site is installed; the result page says that no line was added and why.
            try {
                $scheduler = $this->schedulerAdvice(['available' => false, 'installed' => false, 'changed' => false, 'state' => 'failed',
                    'message' => 'The cron line could not be added: '.Str::limit(trim($e->getMessage()), 160), 'command' => null]);
            } catch (Throwable) {
                $scheduler = ['state' => 'failed', 'running' => false, 'summary' => 'Scheduled tasks: no cron line was added. Tools > Background jobs > Scheduled tasks in the admin shows the line to add.',
                    'reason' => '', 'line' => null, 'command' => null, 'panel_line' => null, 'windows' => $this->onWindows()];
            }
        }

        $run->set('scheduler', $scheduler);

        if ($scheduler !== null && $scheduler['running']) {
            $run->message($scheduler['summary']);
        } elseif ($scheduler !== null) {
            $run->note($scheduler['summary']);
        }

        $run->message('XVIDAPI imports remain disabled until started or enabled by an admin.');

        $run->set('running', null);
        $run->set('status', 'finished');
        $run->delete();
    }

    /**
     * The last check before the final .env: the database it is going to name answers with exactly these settings (the
     * password included) and holds an administrator account. Otherwise .env is not written and the step stops with the
     * reason - the site is never marked installed over a database it cannot use (R1B-001, BI-RV-01).
     *
     * @param array<string, mixed> $database
     */
    private function verifyFinalDatabase(InstallRun $run, array $database): void
    {
        try {
            $pdo = $this->targetDatabasePdo($database);

            try {
                // An account in the bin (users are soft-deleted) is no administrator, as for hasAdministrator().
                $admins = (int) $pdo->query('select count(*) from users where is_admin = 1 and deleted_at is null')->fetchColumn();
            } catch (Throwable) {
                // an imported backup whose users table has no deleted_at
                $admins = (int) $pdo->query('select count(*) from users where is_admin = 1')->fetchColumn();
            }
        } catch (Throwable $e) {
            throw new \RuntimeException('The database of this installation does not answer with the settings of this browser, so .env was not written ('
                .Str::limit(trim($e->getMessage()), 200).'). Check the Database step, then click Try again.', 0, $e);
        }

        if ($admins < 1) {
            // Try again makes the account (and the settings and files after it) again.
            $this->redoAccountSteps($run);

            throw new \RuntimeException('The database of this installation has no admin account, so .env was not written. Click Try again: the account is made again.');
        }
    }

    /**
     * The scheduler's cron line of the site user (App\Support\LaravelSchedulerCron::ensureInstalled(); a test replaces
     * it). What was done ('state', 'message'), with 'php' the PHP command line the line runs (the result page shows the
     * line to add by hand with it where none could be added), is kept in $schedulerCronResult. The signature stays void:
     * the tests' replacements of this method keep working (a different return type would make every one of them a fatal
     * error).
     */
    protected function installSchedulerCron(): void
    {
        $php = PhpBinary::resolve(['CRON_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']);

        $this->schedulerCronResult = \App\Support\LaravelSchedulerCron::ensureInstalled($php) + ['php' => $php];
    }

    /**
     * What the result page says about the scheduled tasks after installSchedulerCron() (round 3 R3-036):
     *  - running: the line was added, an older line of this site replaced, or the crontab already had it - the server
     *    starts the tasks every minute and the owner adds nothing (a second line would run them twice every minute);
     *  - the owner's own line in the crontab, switched off or changed by hand and left as it is: why, and the exact
     *    crontab line the installer would have added (with its lock, LaravelSchedulerCron::lineFor()) to put in its place;
     *  - no line could be added (Windows, no process functions, no PHP command line, no crontab command, a crontab that
     *    could not be written): why, and on Windows the command that makes the site's task in the Task Scheduler; on
     *    Linux and other Unix servers the command of a hosting panel's cron job (the same command with its lock, WITHOUT
     *    the crontab's schedule fields: a panel takes "every minute" in fields of its own, and the "* * * * *" of a
     *    crontab line, run by the panel's shell, never started the scheduler - round 3 IN-RV-01).
     * Both on Linux: the variant for panels that run cron jobs as root.
     *
     * @param array<string, mixed> $result
     * @return array{state: string, running: bool, summary: string, reason: string, line: ?string, command: ?string, panel_line: ?string, windows: bool}
     */
    public function schedulerAdvice(array $result): array
    {
        $state = (string) ($result['state'] ?? (($result['installed'] ?? false) ? (($result['changed'] ?? false) ? 'added' : 'current') : 'failed'));
        $running = (bool) ($result['installed'] ?? false);
        $reason = rtrim(trim((string) ($result['message'] ?? '')), '.');
        $windows = $this->onWindows();
        $php = is_string($result['php'] ?? null) && $result['php'] !== '' ? $result['php'] : null;
        $line = null;
        $command = null;
        $panelLine = null;

        if (! $running) {
            try {
                $php ??= PhpBinary::resolve(['CRON_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']);
                $line = \App\Support\LaravelSchedulerCron::lineFor($php ?: 'php');
                $command = $windows ? null : \App\Support\LaravelSchedulerCron::commandFor($php ?: 'php');
                $panelLine = $windows ? null : \App\Support\LaravelSchedulerCron::panelLineFor($php ?: 'php');
            } catch (Throwable) {
                // The page then points to Tools > Scheduled tasks, which shows the line.
                $line = null;
                $command = null;
                $panelLine = null;
            }
        }

        $summary = match ($state) {
            'added' => 'Scheduled tasks: this site\'s cron line was added to the crontab of the account PHP runs as; the server starts them every minute.',
            'updated' => 'Scheduled tasks: this site\'s older cron line in this account\'s crontab was replaced by the current one; the server starts them every minute.',
            'current' => 'Scheduled tasks: this account\'s crontab already has this site\'s cron line; the server starts them every minute.',
            'off' => 'Scheduled tasks: this site\'s cron line in this account\'s crontab is switched off (it starts with #), so it was left as it is. They run only once it is switched on (see Recommended After Install).',
            'custom' => 'Scheduled tasks: this site\'s cron line in this account\'s crontab was changed by hand, so it was left as it is. If they do not run, use the line under Recommended After Install.',
            default => 'Scheduled tasks: no cron line was added.'.($reason !== '' ? ' '.$reason.'.' : '').' '
                .($windows ? 'Create the site\'s task in the Windows Task Scheduler as shown under Recommended After Install.' : 'Add the cron job shown under Recommended After Install.'),
        };

        return ['state' => $state, 'running' => $running, 'summary' => $summary, 'reason' => $reason, 'line' => $line, 'command' => $command, 'panel_line' => $panelLine, 'windows' => $windows];
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
     * The whole SQL backup at once (the tests' and the command line's import).
     *
     * @return array{statements:int}
     */
    private function importSqlBackup(PDO $pdo, string $path): array
    {
        $cursor = self::importCursor($path);
        $this->importSqlBackupFrom($pdo, $path, $cursor, null, 0.0);

        return ['statements' => (int) $cursor['statements']];
    }

    /**
     * Imports the SQL backup from $cursor['offset'] on, statement by statement, until the file ends (true) or this
     * request's time is up (false; $cursor says where the next request goes on) - R1B-001.
     *
     * A request stops by itself only at a clean place: before the next table, or between two INSERTs of a big table.
     * The next request runs the backup's first lines again (its SET statements: character set, time zone, SQL mode and
     * checks hold for one database session only) and goes on at the offset. Before the first statement of each table
     * $checkpoint notes where that table starts: a request that the host ends inside a table is followed by one that
     * imports that whole table again from there ($cursor['replay']: a table its block created is dropped first, rows of
     * a data-only block are deleted first; a block that starts with its own DROP TABLE needs nothing). Statements are read
     * as before (the same lines are skipped).
     *
     * @param array{size: int, offset: int, statements: int, preamble_end: ?int, block_start: ?int, block_statements: int, block_table: ?string, block_kind: ?string, replay: bool} $cursor
     * @param (callable(array): void)|null $checkpoint
     */
    protected function importSqlBackupFrom(PDO $pdo, string $path, array &$cursor, ?callable $checkpoint, float $reserve): bool
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new \RuntimeException('Could not open SQL backup: '.$path);
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $ran = 0;

        try {
            if ((int) $cursor['offset'] > 0 && (int) ($cursor['preamble_end'] ?? 0) > 0) {
                foreach ($this->sqlStatements($handle, 0, (int) $cursor['preamble_end']) as [$sql]) {
                    $pdo->exec($sql);
                }
            }

            if (! empty($cursor['replay']) && is_string($cursor['block_table'] ?? null) && $cursor['block_table'] !== '') {
                $table = $this->quoteIdentifier($cursor['block_table']);

                if ($cursor['block_kind'] === 'create') {
                    $pdo->exec('DROP TABLE IF EXISTS '.$table);
                } elseif ($cursor['block_kind'] === 'insert') {
                    $pdo->exec('DELETE FROM '.$table);
                }
            }

            $cursor['replay'] = false;

            foreach ($this->sqlStatements($handle, (int) $cursor['offset'], null) as [$sql, $start, $end]) {
                [$kind, $table] = $this->statementHead($sql);
                // A table's block: its DROP, the SET lines, its CREATE and its INSERTs - one table is never split into two
                // blocks (a replay from its CREATE would miss the "SET @saved_cs_client" line before it).
                $newBlock = $kind !== 'other' && $table !== $cursor['block_table'];

                if ($newBlock || $kind === 'insert') {
                    if ($ran > 0 && TimeBudget::left() < $reserve) {
                        $cursor['offset'] = $start;

                        return false;
                    }
                }

                if ($newBlock) {
                    $cursor['preamble_end'] ??= $start;
                    $cursor['block_start'] = $start;
                    $cursor['block_statements'] = (int) $cursor['statements'];
                    $cursor['block_table'] = $table;
                    $cursor['block_kind'] = $kind;

                    if ($checkpoint !== null) {
                        $checkpoint($cursor);
                    }
                }

                $pdo->exec($sql);
                $ran++;
                $cursor['statements'] = (int) $cursor['statements'] + 1;
                $cursor['offset'] = $end;
            }

            $cursor['offset'] = max((int) $cursor['offset'], (int) filesize($path));

            return true;
        } finally {
            fclose($handle);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * The statements of the SQL file from byte $from (to byte $until), each with the byte where it starts and the byte
     * after it ends. The lines the importer always skipped are skipped (comments, MariaDB's sandbox line, LOCK/UNLOCK
     * TABLES, DISABLE/ENABLE KEYS).
     *
     * @param resource $handle
     * @return \Generator<int, array{0: string, 1: int, 2: int}>
     */
    private function sqlStatements($handle, int $from, ?int $until): \Generator
    {
        fseek($handle, $from);
        $statement = '';
        $start = $from;

        while (true) {
            $lineStart = (int) ftell($handle);

            if ($until !== null && $lineStart >= $until) {
                break;
            }

            $line = fgets($handle);

            if ($line === false) {
                break;
            }

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

            if ($statement === '') {
                $start = $lineStart;
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

            yield [$sql, $start, (int) ftell($handle)];
        }
    }

    /**
     * What a statement of the backup is: ['drop' | 'create' | 'insert' | 'other', its table or null].
     *
     * @return array{0: string, 1: ?string}
     */
    private function statementHead(string $sql): array
    {
        $name = '((?:`(?:[^`]|``)+`|[\w$]+)(?:\s*\.\s*(?:`(?:[^`]|``)+`|[\w$]+))?)';
        $patterns = [
            'drop' => '/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?'.$name.'/i',
            'create' => '/^CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?'.$name.'/i',
            'insert' => '/^(?:INSERT|REPLACE)\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*(?:INTO\s+)?'.$name.'/i',
        ];

        foreach ($patterns as $kind => $pattern) {
            if (preg_match($pattern, $sql, $match)) {
                // The table is the last part of "db.table" (a quoted name may hold a dot itself).
                preg_match('/(`(?:[^`]|``)+`|[\w$]+)\s*$/', $match[1], $last);
                $table = (string) ($last[1] ?? $match[1]);

                if (str_starts_with($table, '`') && str_ends_with($table, '`')) {
                    $table = str_replace('``', '`', substr($table, 1, -1));
                }

                return [$kind, $table];
            }
        }

        return ['other', null];
    }

    private function quoteIdentifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
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
    protected function applyRuntimeDatabaseConfig(array $database): void
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
        // Accounts in the bin (users are soft-deleted) count too: the e-mail address, the username and the slug are unique
        // over every row, so such an account made the admin step fail with a duplicate key (an imported backup with a
        // deleted account of the owner; the account step made again after its account was deleted). As before, an
        // existing administrator is taken over first, so no administrator of an imported backup keeps its old password.
        $user = User::withTrashed()
            ->where(fn ($query) => $query->where('email', $admin['email'])->orWhere('username', $admin['username'])->orWhere('is_admin', true))
            ->orderByDesc('is_admin')
            ->orderByRaw('case when deleted_at is null then 0 else 1 end')
            ->orderBy('id')
            ->first();

        if (! $user) {
            $user = new User();
            $user->slug = User::generateSlug($admin['username']);

            if (User::withTrashed()->where('slug', $user->slug)->exists()) {
                $user->slug .= '-'.Str::lower(Str::random(8));
            }
        }

        // Another account (also one in the bin) with this e-mail address or username: a clear refusal, not a duplicate key.
        foreach (['email' => 'e-mail address', 'username' => 'username'] as $field => $label) {
            $taken = User::withTrashed()->where($field, $admin[$field])
                ->when($user->exists, fn ($query) => $query->whereKeyNot($user->getKey()))
                ->exists();

            if ($taken) {
                throw new \RuntimeException('The '.$label.' '.$admin[$field].' belongs to another account in this database. Go back to the Admin Account step and choose another one.');
            }
        }

        if ($user->exists && $user->trashed()) {
            $user->deleted_at = null;
        }

        $user->forceFill([
            'username' => $admin['username'],
            'email' => $admin['email'],
            'password' => $admin['password'],
            'email_verified_at' => now(),
            'is_admin' => true,
            'show_subscribers' => true,
        ]);

        // The installer's administrator can always make changes: an existing view-only demo account (1.2.2,
        // users.is_view_only) picked above becomes a normal administrator again.
        if (Schema::hasColumn('users', 'is_view_only')) {
            $user->forceFill(['is_view_only' => false]);
        }

        $user->save();
    }

    /**
     * @param array<string, string> $admin
     * @param array<string, string> $app
     */
    private function writeCoreSettings(array $admin, array $app, ?InstallRun $run = null): void
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
        Setting::set('mail_from_address', self::realFromAddress($app['mail_from_address'] ?? '') ?: $admin['email'], 'email');
        Setting::set('mail_from_name', $app['name'], 'email');

        // Settings > Email's Alerts e-mail (where failed background jobs and scheduled tasks are mailed:
        // App\Listeners\BackgroundEventSubscriber) and Support e-mail. The bundled SQL backup ships noreply@example.com
        // and support@example.com, and the migrations copy server@ / support@example.com of .env.example: a new site
        // mailed its alerts to an example address that never receives them (round 3 R3-006). An empty or example
        // address becomes the administrator's; a real one (an imported backup of the owner's own site) stays. What this
        // run wrote there itself follows the administrator's e-mail when the step runs again with another one (the Admin
        // step sent again: redoAccountSteps()); the run's note keeps only a hash of it.
        $filled = $run !== null && is_array($run->get('mail_filled')) ? (array) $run->get('mail_filled') : [];

        foreach (['server_email', 'support_email'] as $key) {
            $stored = $this->storedSetting($key);
            $ours = is_string($filled[$key] ?? null) && $stored !== '' && hash_equals($filled[$key], self::mailFingerprint($stored));

            if ($ours || self::realFromAddress($stored) === '') {
                Setting::set($key, $admin['email'], 'email');
                $filled[$key] = self::mailFingerprint($admin['email']);
            }
        }

        $run?->set('mail_filled', $filled);

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

    /** A hash of an e-mail address for the run's note (writeCoreSettings(): which address the installer wrote itself). */
    private static function mailFingerprint(string $address): string
    {
        return hash('sha256', strtolower(trim($address)));
    }

    /**
     * "Run migrations and seeders" starts with the Upload18 key of the bundled SQL backup (R1-013): the seeders store
     * none, so a member's upload stopped at once ("Upload18 API key not configured") while the admin card promised the
     * shared key. The key is the one the package ships, read from its install data (database/install/example.sql,
     * which "Import SQL backup" imports) by App\Support\Upload18\BundledKey: never a key written in code; without
     * usable install data nothing is stored, as before. Only this mode: its database was empty before the migrations,
     * so no key there (no row, or the seeders' empty one) is anybody's choice. An imported backup keeps what it holds,
     * also an Upload18 key its owner left empty on purpose. A key in the installer's environment (UPLOAD18_API_KEY)
     * still wins: writeCoreSettings() stores it afterwards.
     */
    private function applyShippedUpload18Key(): void
    {
        if (! Schema::hasTable('settings') || $this->storedSetting('upload18_api_key') !== '') {
            return;
        }

        $key = trim((string) ($this->shippedUpload18Key() ?? ''));

        if ($key !== '') {
            Setting::set('upload18_api_key', $key, 'uploads');
        }
    }

    /** The stored value of a setting ('' when there is no row or it is empty). */
    private function storedSetting(string $key): string
    {
        return trim((string) (DB::table('settings')->where('key', $key)->value('value') ?? ''));
    }

    /** The Upload18 key of the package's install data (a test replaces it). */
    protected function shippedUpload18Key(): ?string
    {
        return \App\Support\Upload18\BundledKey::shippedKey();
    }

    /**
     * "Run migrations and seeders" starts with the Telegram settings of the bundled SQL backup (R1-014): there the site's
     * own Telegram channel (Settings > Site basics, card Social profiles) is empty, while three migrations of 1.2.0
     * seeded AVDBAPI's old public group into it, which 2026_10_26_200000_new_telegram_links turns into AVDBAPI's new
     * private group: the new site showed a Telegram button to AVDBAPI's group in its footer and listed it in its
     * Organization data (sameAs) as its own channel. Only that value (or a missing row, which shows the group too) is
     * set to empty; the footer's Support links are the same in both modes already.
     */
    private function applyBundledTelegramDefaults(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $value = DB::table('settings')->where('key', 'social_telegram')->value('value');

        if ($value === null || TelegramLinks::isRetiredGroup((string) $value) || trim((string) $value) === TelegramLinks::GROUP_URL) {
            Setting::set('social_telegram', '', 'sociallinks');
        }
    }

    /**
     * The site's address as the final .env names it (APP_URL): the address of the wizard, with https when the edge
     * terminates TLS (Cloudflare/panels) even if the install request reached the origin over http (Flexible SSL) — avoids
     * an http APP_URL/ASSET_URL causing mixed-content (blocked CSS/JS) and redirect issues on an HTTPS site.
     *
     * @param array<string, string> $app
     */
    private function siteAddress(array $app): string
    {
        $appUrl = $app['url'];
        if (\App\Support\Environment::isSecureRequest() && str_starts_with(strtolower($appUrl), 'http://')) {
            $appUrl = 'https://' . substr($appUrl, 7);
        }

        return $appUrl;
    }

    /**
     * @param array<string, string> $database
     * @param array<string, string> $admin
     * @param array<string, string> $app
     * @return array<string, string>
     */
    private function envValues(array $database, array $admin, array $app): array
    {
        $appUrl = $this->siteAddress($app);
        // The address Sanctum compares the site's pages with: "host:port" when APP_URL has a port (docker-compose 8080:80,
        // an IP with a port), else the host. The bare host alone made every member's API call answer 401 on such a site.
        $statefulAddress = \App\Providers\EnvironmentServiceProvider::statefulAddress($appUrl) ?? '';
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
            // Empty: cookies only for the site's own host, never for its subdomains (EnvironmentServiceProvider).
            'SESSION_DOMAIN' => '',
            'SESSION_SECURE_COOKIE' => $https ? 'true' : 'false',
            'SCOUT_DRIVER' => 'collection',
            'MAIL_MAILER' => $app['mail_mailer'] ?: 'log',
            'MAIL_HOST' => $app['mail_host'] ?: '',
            'MAIL_PORT' => $app['mail_port'] ?: '587',
            'MAIL_USERNAME' => $app['mail_username'] ?: '',
            'MAIL_PASSWORD' => $app['mail_password'] ?: '',
            'MAIL_ENCRYPTION' => $app['mail_encryption'] ?: '',
            'MAIL_FROM_ADDRESS' => self::realFromAddress($app['mail_from_address'] ?? '') ?: $admin['email'],
            'MAIL_FROM_NAME' => $app['name'],
            'SUPPORT_EMAIL' => $admin['email'],
            'SERVER_EMAIL' => $admin['email'],
            'SANCTUM_STATEFUL_DOMAINS' => $statefulAddress,
            'ADMIN_OPERATIONAL_TOOLS_ENABLED' => $this->runtimeEnvString('ADMIN_OPERATIONAL_TOOLS_ENABLED', 'true') ?: 'true',
            'AVDB_IMPORT_MODE' => 'auto',
            'AVDB_DURATION_BACKFILL_MODE' => 'auto',
            'AVDB_DURATION_REMOTE_CONCURRENCY' => '10',
            'AVDB_DURATION_REMOTE_TIMEOUT' => '8',
            'INSTALL_SQL_BACKUP' => $this->backupInfo()['filename'],
        ];
    }

    /** The site's .env file (a test points it at a file of its own). */
    protected function envPath(): string
    {
        return base_path('.env');
    }

    /**
     * The final .env of the installation. Written once, by its last step, after the database work and the administrator
     * account succeeded (finishRun(), R1B-001): before that the site's .env stays as the upload made it, so a request the
     * host ends at any point never leaves APP_INSTALLED=true over an unfinished database.
     *
     * @param array<string, string> $values
     */
    private function writeEnvironment(array $values): void
    {
        $envPath = $this->envPath();
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
            // One copy of the first original file, under a name nobody can guess (storage/ can be reachable from the
            // web on a misconfigured nginx); an older copy (.env.backup, 1.2.2) counts as that first one.
            if ((glob($backupDirectory.'/.env.backup*') ?: []) === []) {
                $backupPath = $backupDirectory.'/.env.backup.'.bin2hex(random_bytes(8));
                if (@copy($envPath, $backupPath)) {
                    @chmod($backupPath, 0600);
                }
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

        $existed = is_file($envPath);

        if (@file_put_contents($envPath, rtrim($content).PHP_EOL) === false) {
            // Not a raw "file_put_contents(...): Failed to open stream" (R1B-008): what is wrong and the exact fix.
            throw new \RuntimeException('The site\'s settings could not be written to '.$envPath.': PHP cannot write that file. '.$this->projectFolderCheck()['fix'].' Nothing else was changed: click Try again afterwards.');
        }

        // A .env the web installer creates is the site PHP user's: written by it only, read by the group, nothing for
        // the other accounts (R1B-016, the mode the front controllers give it). An existing file keeps its mode, and
        // the console (another user, root) never sets one.
        if (! $existed && ! app()->runningInConsole()) {
            @chmod($envPath, 0640);
        }
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
