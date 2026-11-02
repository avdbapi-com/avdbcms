<?php

namespace Tests\Feature;

use App\Support\InstallRun;
use App\Support\Installer;
use PDO;
use Tests\TestCase;
use Throwable;

/**
 * R1B-001 on a real MySQL or MariaDB server, end to end: the web installer's run in short steps over several requests
 * (both modes), and a REAL kill of the request in the middle of every kind of step - prepare (emptying a database),
 * importing the SQL backup, a migration, the seeders, the admin account, the settings, the files, the final .env -
 * after which the next request goes on and the installation ends EXACTLY like one that was never interrupted (the schema
 * of every table and the rows of every table).
 *
 * Opt-in, because it needs a server: AVD_TEST_MYSQL_HOST (and AVD_TEST_MYSQL_PORT, AVD_TEST_MYSQL_USER,
 * AVD_TEST_MYSQL_PASSWORD). It works in throwaway databases avdbcms_run_<random>_test, or - for a user who may not create
 * databases - in AVD_TEST_MYSQL_DATABASE (and AVD_TEST_MYSQL_DATABASE2), which it empties before and after.
 */
class InstallerMysqlRunTest extends TestCase
{
    private ?PDO $server = null;

    /** @var list<string> databases this test created (dropped at the end) or emptied */
    private array $databases = [];

    /** @var list<string> */
    private array $workDirs = [];

    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['APP_INSTALLED', 'INSTALLER_ALLOW_REINSTALL', 'UPLOAD18_API_KEY'] as $key) {
            $this->environment[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->databases as $database) {
            $this->emptyDatabase($database, true);
        }

        foreach ($this->workDirs as $dir) {
            foreach (glob($dir.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }

        foreach ($this->environment as $key => [$env, $server, $process]) {
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            putenv($process === false ? $key : $key.'='.$process);
        }

        parent::tearDown();
    }

    public function test_both_modes_run_in_short_steps_and_write_env_only_at_the_end(): void
    {
        foreach (['migrate', 'backup'] as $index => $mode) {
            $database = $this->freshDatabase($index);
            $work = $this->workDir();
            $installer = $this->installer($work);
            $payload = $this->payload($database, $mode);
            $envBefore = (string) file_get_contents($work.'/.env');

            $installer->startRun($payload, 'owner');
            // The shortest steps (TimeBudget gives every request at least one second): many requests, each stops by itself.
            $state = json_decode((string) file_get_contents($work.'/run.json'), true);
            $state['budget'] = 0.001;
            file_put_contents($work.'/run.json', json_encode($state));

            $requests = 0;
            do {
                $started = microtime(true);
                $progress = $installer->runStep($payload, 'owner');
                $requests++;
                $this->assertLessThan(30.0, microtime(true) - $started, $mode.': every step stays far under a strict host\'s 30 s');
                $this->assertNotSame('failed', $progress['status'], $mode.': '.($progress['error'] ?? ''));
                $this->assertSame($envBefore, (string) file_get_contents($work.'/.env'), $mode.': .env is untouched until the end');
                $this->assertFileDoesNotExist($work.'/installed.json');
            } while ($progress['status'] !== 'ready' && $requests < 500);

            $this->assertGreaterThan(2, $requests, $mode.': the work was spread over several requests');
            $done = $installer->runStep($payload, 'owner', false, true);
            $this->assertSame('finished', $done['status']);

            $facts = $this->facts($database, $work, $payload);
            $this->assertSame([], $facts['migrations_missing'], $mode);
            $this->assertSame(0, $facts['migrations_twice'], $mode);
            $this->assertSame(1, $facts['admins'], $mode);
            $this->assertSame(1, $facts['our_admin'], $mode);
            $this->assertSame($mode === 'migrate' ? 16 : $facts['rows']['categories'], $facts['rows']['categories'], $mode);
            $this->assertSame('true', $facts['env']['APP_INSTALLED'] ?? null, $mode);
            $this->assertSame($database, $facts['env']['DB_DATABASE'] ?? null, $mode);
            $this->assertTrue($facts['lock'], $mode);
            $this->assertFalse($facts['run_file'], $mode);
        }
    }

    public function test_a_kill_in_the_middle_of_every_kind_of_step_ends_like_an_uninterrupted_installation(): void
    {
        // job => milliseconds after its note appeared (the last step, .env and the lock, is over in a few milliseconds:
        // it is killed when the test catches it in time, and is not required to be). The seeders' transaction is over in
        // 40-55 ms on a fast machine with MariaDB, so that step is killed 5 ms after its note was seen (inside the seeding).
        $cases = [
            'migrate' => ['prepare' => 20, 'migrate' => 40, 'seed' => 5, 'admin' => 0, 'settings' => 0, 'finish' => 0],
            'backup' => ['prepare' => 20, 'import' => 30, 'migrate' => 30, 'admin' => 0, 'settings' => 0, 'finish' => 0],
        ];

        foreach ($cases as $mode => $kills) {
            $reference = $this->stepwiseRun($this->freshDatabase(0), $mode, []);
            $this->assertSame('finished', $reference['status'], $mode.' reference: '.json_encode($reference['log']));

            $killed = $this->stepwiseRun($this->freshDatabase(1, 30), $mode, $kills);
            $this->assertSame('finished', $killed['status'], $mode.': '.json_encode($killed['log']));
            $required = array_values(array_diff(array_keys($kills), ['finish']));
            $this->assertSame($required, array_values(array_diff(array_column($killed['kills'], 'job'), ['finish'])), $mode.': every planned kill happened inside its step: '.json_encode($killed['log']));

            $this->assertSame($reference['facts']['schema'], $killed['facts']['schema'], $mode.': the same tables, columns and indexes');
            $this->assertSame($reference['facts']['rows'], $killed['facts']['rows'], $mode.': the same rows in every table');
            $this->assertSame([], $killed['facts']['migrations_missing'], $mode);
            $this->assertSame(0, $killed['facts']['migrations_twice'], $mode);
            $this->assertSame(1, $killed['facts']['our_admin'], $mode);
            $this->assertSame('true', $killed['facts']['env']['APP_INSTALLED'] ?? null, $mode);
            $this->assertTrue($killed['facts']['lock'], $mode);
            $this->assertFalse($killed['facts']['run_file'], $mode);
            $this->assertStringContainsString('The server ended a step of the installation before it was done', implode(' ', $killed['notes']), $mode);
        }
    }

    /**
     * Round 3 R3-037: InnoDB keeps the auto-increment numbers of the rows a rolled-back transaction inserted. A request
     * the host ended inside the seeding transaction (it is rolled back and the next request seeds again) gave the 16
     * basic categories the ids 6-21 instead of 1-16. Now a resumed installation has the ids of an uninterrupted one.
     * To end the request surely in the middle of the seeding, a trigger of this throwaway database makes every category
     * row take 0.2 s (the kill comes 1.1 s after the seeding started: about five rows are in, none committed).
     */
    public function test_a_kill_inside_the_seeding_gives_the_ids_of_an_uninterrupted_installation(): void
    {
        $referenceDatabase = $this->freshDatabase(0);
        $reference = $this->stepwiseRun($referenceDatabase, 'migrate', []);
        $this->assertSame('finished', $reference['status'], 'reference: '.json_encode($reference['log']));
        $expected = $this->seededIds($referenceDatabase);
        $this->assertSame(range(1, 16), $expected['categories']);
        $this->assertSame([1, 2], $expected['plans']);

        $database = $this->freshDatabase(1);
        $pdo = $this->connect($database);

        // The slow seeding needs a trigger: a user without SUPER may not make one on a MySQL with binary logging on.
        try {
            $pdo->exec('CREATE TABLE r3037_probe (id int)');
            $pdo->exec('CREATE TRIGGER r3037_probe_trigger BEFORE INSERT ON r3037_probe FOR EACH ROW SET @r3037 = 1');
        } catch (Throwable $e) {
            $this->markTestSkipped('This database user may not create a trigger, which this test needs to end the seeding in its middle: '.$e->getMessage());
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS r3037_probe');
        }

        $lastLook = 0.0;
        $slowSeeding = static function () use ($pdo, &$lastLook): void {
            // a look every 100 ms: once the migrations made the categories table, the slow trigger is on it
            if (microtime(true) - $lastLook < 0.1) {
                return;
            }

            $lastLook = microtime(true);

            try {
                if ($pdo->query("show tables like 'categories'")->fetchColumn() !== false
                    && (int) $pdo->query("select count(*) from information_schema.triggers where trigger_schema = database() and trigger_name = 'r3037_slow_seed'")->fetchColumn() === 0) {
                    $pdo->exec('CREATE TRIGGER r3037_slow_seed BEFORE INSERT ON categories FOR EACH ROW SET @r3037 = SLEEP(0.2)');
                }
            } catch (Throwable) {
                // the table is being changed right now: the next look
            }
        };

        $killed = $this->stepwiseRun($database, 'migrate', ['seed' => 1100], $slowSeeding);
        $pdo->exec('DROP TRIGGER IF EXISTS r3037_slow_seed');
        $this->assertSame('finished', $killed['status'], json_encode($killed['log']));
        $this->assertSame(['seed'], array_column($killed['kills'], 'job'), 'the request was ended inside the seeding: '.json_encode($killed['log']));
        $this->assertSame($expected, $this->seededIds($database), 'the ids and the next ids of an uninterrupted installation');
    }

    /** @return array{categories: list<int>, plans: list<int>, next: array<string, int|null>, settings: array<string, int>} */
    private function seededIds(string $database): array
    {
        $pdo = $this->connect($database);
        $next = [];

        foreach (['categories', 'plans'] as $table) {
            // SHOW CREATE TABLE gives the counter itself (information_schema may keep a cached value on MySQL 8)
            preg_match('/AUTO_INCREMENT=(\d+)/', (string) $pdo->query('show create table `'.$table.'`')->fetch(PDO::FETCH_NUM)[1], $m);
            $next[$table] = isset($m[1]) ? (int) $m[1] : null;
        }

        return [
            'categories' => array_map('intval', $pdo->query('select id from categories order by id')->fetchAll(PDO::FETCH_COLUMN)),
            'plans' => array_map('intval', $pdo->query('select id from plans order by id')->fetchAll(PDO::FETCH_COLUMN)),
            'next' => $next,
            // the settings the seeding writes (the shipped Upload18 key, the Telegram channel) keep their ids too
            'settings' => array_map('intval', $pdo->query("select `key`, id from settings where `key` in ('upload18_api_key', 'social_telegram') order by `key`")->fetchAll(PDO::FETCH_KEY_PAIR)),
        ];
    }

    /**
     * The installation as the progress page drives it, one PHP process per request; $kills: job => milliseconds after
     * its "running" note appeared, when that request's process is killed (each once). $whileRunning: called again and
     * again while a request runs.
     *
     * @param array<string, int> $kills
     * @return array{status: string, kills: list<array<string, mixed>>, log: list<array<string, mixed>>, facts: array<string, mixed>, notes: list<string>}
     */
    private function stepwiseRun(string $database, string $mode, array $kills, ?\Closure $whileRunning = null): array
    {
        $work = $this->workDir();
        $payload = $this->payload($database, $mode, overwrite: true);
        file_put_contents($work.'/payload.json', json_encode($payload + ['token' => 'owner']));
        $pending = $kills;
        $done = [];
        $log = [];
        $status = $this->step($work, 'start', [], $done, $log, $whileRunning)['status'] ?? 'failed';
        $notes = [];

        for ($i = 0; $i < 200 && $status !== 'finished'; $i++) {
            $action = $status === 'ready' ? 'finish' : 'step';
            $answer = $this->step($work, $action, $pending, $done, $log, $whileRunning);
            foreach ($done as $kill) {
                unset($pending[$kill['job']]);
            }

            if ($answer === null) {
                continue; // killed: the next request goes on
            }

            $status = (string) ($answer['status'] ?? 'failed');
            $notes = array_merge($notes, (array) ($answer['notes'] ?? []));

            if ($status === 'missing' && is_file($work.'/installed.json')) {
                // The last step was "killed" after it had finished (its note gone with the run): the installation is done.
                $status = 'finished';
            }

            if (in_array($status, ['failed', 'missing', 'not_owner'], true)) {
                break;
            }
        }

        return ['status' => $status, 'kills' => $done, 'log' => $log, 'facts' => $this->facts($database, $work, $payload), 'notes' => array_values(array_unique($notes))];
    }

    /**
     * @param array<string, int> $kills
     * @param list<array<string, mixed>> $done
     * @param list<array<string, mixed>> $log
     * @return array<string, mixed>|null the progress, or null when this process was killed
     */
    private function step(string $work, string $action, array $kills, array &$done, array &$log, ?\Closure $whileRunning = null): ?array
    {
        // The request runs like a real one: without the test run's variables (phpunit.xml's SQLite, array stores, 4 bcrypt
        // rounds), which would make its steps too short to be caught.
        $environment = array_diff_key(getenv(), array_flip(['APP_ENV', 'APP_INSTALLED', 'ADMIN_UI', 'BCRYPT_ROUNDS', 'CACHE_DRIVER', 'LIMITER_DRIVER',
            'DB_CONNECTION', 'DB_DATABASE', 'MAIL_MAILER', 'QUEUE_CONNECTION', 'SESSION_DRIVER', 'INSTALLER_ALLOW_REINSTALL']));
        $command = [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'memory_limit=1G', base_path('tests/Support/install_step.php'), $work, $action];
        $outFile = $work.'/step-'.bin2hex(random_bytes(3)).'.out';
        // proc_open() with the command as an array starts PHP itself (no shell in between), so proc_terminate() ends that
        // very process at once: TerminateProcess on Windows, SIGKILL here on Linux. No PHP code of the request runs any more.
        $process = proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $outFile.'.err', 'w']], $pipes, base_path(), $environment);
        $this->assertIsResource($process);
        $seen = [];
        $jobs = [];

        while (proc_get_status($process)['running']) {
            if ($whileRunning !== null) {
                $whileRunning();
            }

            $running = (json_decode((string) @file_get_contents($work.'/run.json'), true) ?: [])['running'] ?? null;
            $job = is_array($running) ? ($running['job'] ?? null) : null;

            if (is_string($job) && end($jobs) !== $job) {
                $jobs[] = $job;
            }

            if (is_string($job) && array_key_exists($job, $kills) && ! in_array($job, array_column($done, 'job'), true)) {
                $seen[$job] ??= microtime(true);

                if ((microtime(true) - $seen[$job]) * 1000 >= $kills[$job]) {
                    proc_terminate($process, 9);
                    proc_close($process);
                    $done[] = ['job' => $job, 'point' => $running['point'] ?? null];
                    $log[] = ['action' => $action, 'killed' => $job, 'point' => $running['point'] ?? null, 'seen' => $jobs];
                    @unlink($outFile);
                    @unlink($outFile.'.err');

                    return null;
                }
            }

            usleep(2000);
        }

        proc_close($process);
        $lines = array_values(array_filter(explode("\n", trim((string) @file_get_contents($outFile)))));
        $error = (string) @file_get_contents($outFile.'.err');
        @unlink($outFile);
        @unlink($outFile.'.err');
        $answer = json_decode((string) end($lines), true);
        $log[] = ['action' => $action, 'status' => $answer['progress']['status'] ?? null, 'seen' => $jobs, 'error' => $answer['error'] ?? ($answer['progress']['error'] ?? null)];

        return is_array($answer) && ($answer['ok'] ?? false) ? $answer['progress'] : ['status' => 'failed', 'error' => $answer['error'] ?? substr($error, 0, 400)];
    }

    /** @return array<string, mixed> */
    private function facts(string $database, string $work, array $payload): array
    {
        $pdo = $this->connect($database);
        $schema = [];
        $rows = [];

        foreach ($pdo->query('show full tables')->fetchAll(PDO::FETCH_NUM) as [$name, $type]) {
            $quoted = '`'.str_replace('`', '``', $name).'`';
            $create = $pdo->query(($type === 'VIEW' ? 'show create view ' : 'show create table ').$quoted)->fetch(PDO::FETCH_NUM);
            $schema[$name] = sha1((string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) $create[1]));

            if ($type !== 'VIEW') {
                $rows[$name] = (int) $pdo->query('select count(*) from '.$quoted)->fetchColumn();
            }
        }

        ksort($schema);
        ksort($rows);
        $files = array_map(static fn (string $file): string => basename($file, '.php'), glob(database_path('migrations/*.php')) ?: []);
        $ran = $pdo->query('select migration from migrations')->fetchAll(PDO::FETCH_COLUMN);
        $username = $pdo->quote((string) $payload['admin']['username']);

        return [
            'schema' => $schema,
            'rows' => $rows,
            'migrations_missing' => array_values(array_diff($files, $ran)),
            'migrations_twice' => count($ran) - count(array_unique($ran)),
            'admins' => (int) $pdo->query('select count(*) from users where is_admin = 1')->fetchColumn(),
            'our_admin' => (int) $pdo->query('select count(*) from users where is_admin = 1 and username = '.$username)->fetchColumn(),
            'env' => is_file($work.'/.env') ? \Dotenv\Dotenv::parse((string) file_get_contents($work.'/.env')) : [],
            'lock' => is_file($work.'/installed.json'),
            'run_file' => is_file($work.'/run.json'),
        ];
    }

    private function installer(string $work): Installer
    {
        return new class($work) extends Installer
        {
            public function __construct(private string $dir)
            {
            }

            protected function envPath(): string
            {
                return $this->dir.'/.env';
            }

            public function lockPath(): string
            {
                return $this->dir.'/installed.json';
            }

            protected function runStatePath(): string
            {
                return $this->dir.'/run.json';
            }

            protected function runLockPath(): string
            {
                return $this->dir.'/run.lock';
            }

            protected function prepareFiles(InstallRun $run): void
            {
                $run->message('Files prepared (test).');
            }

            protected function installSchedulerCron(): void
            {
            }
        };
    }

    /** @return array<string, mixed> */
    private function payload(string $database, string $mode, bool $overwrite = false): array
    {
        return [
            'database' => ['host' => $this->host(), 'port' => $this->port(), 'database' => $database, 'username' => $this->user(),
                'password' => $this->password(), 'mode' => $mode, 'overwrite' => $overwrite],
            'admin' => ['username' => 'run_owner', 'email' => 'owner@run-test.net', 'password' => 'Run-owner-pass-2026!'],
            'app' => ['name' => 'Run test', 'url' => 'https://run-test.net', 'mail_mailer' => 'log', 'mail_host' => '', 'mail_port' => '587',
                'mail_username' => '', 'mail_password' => '', 'mail_encryption' => '', 'mail_from_address' => ''],
        ];
    }

    private function workDir(): string
    {
        $dir = storage_path('framework/testing/install-mysql-'.bin2hex(random_bytes(5)));
        @mkdir($dir, 0777, true);
        copy(base_path('.env.example'), $dir.'/.env');
        $this->workDirs[] = $dir;

        return $dir;
    }

    /** An empty database ($slot 0 or 1), with $tables tables of an older site in it ($tables > 0: the run replaces them). */
    private function freshDatabase(int $slot, int $tables = 0): string
    {
        $host = $this->host();
        if ($host === '' || ! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('Set AVD_TEST_MYSQL_HOST (and _PORT, _USER, _PASSWORD; _DATABASE and _DATABASE2 for a user who may not create databases) to run the installer on MySQL or MariaDB.');
        }

        $this->server ??= new PDO('mysql:host='.$host.';port='.$this->port().';charset=utf8mb4', $this->user(), $this->password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $given = (string) getenv($slot === 0 ? 'AVD_TEST_MYSQL_DATABASE' : 'AVD_TEST_MYSQL_DATABASE2');

        if ($given !== '') {
            $this->emptyDatabase($given, false);
            $database = $given;
        } else {
            $database = 'avdbcms_run_'.bin2hex(random_bytes(4)).'_test';
            $this->server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }

        $this->databases[] = $database;
        $pdo = $this->connect($database);
        for ($i = 0; $i < $tables; $i++) {
            $pdo->exec('CREATE TABLE `old_site_'.$i.'` (id int primary key, name varchar(40))');
        }

        return $database;
    }

    private function emptyDatabase(string $database, bool $atTheEnd): void
    {
        try {
            if ($atTheEnd && (string) getenv('AVD_TEST_MYSQL_DATABASE') !== $database && (string) getenv('AVD_TEST_MYSQL_DATABASE2') !== $database) {
                $this->server?->exec('DROP DATABASE IF EXISTS `'.$database.'`');

                return;
            }

            $pdo = $this->connect($database);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($pdo->query('show full tables')->fetchAll(PDO::FETCH_NUM) as [$name, $type]) {
                $pdo->exec('DROP '.($type === 'VIEW' ? 'VIEW' : 'TABLE').' IF EXISTS `'.str_replace('`', '``', $name).'`');
            }
        } catch (Throwable) {
            // the server went away: nothing to clean up from here
        }
    }

    private function connect(string $database): PDO
    {
        return new PDO('mysql:host='.$this->host().';port='.$this->port().';dbname='.$database.';charset=utf8mb4', $this->user(), $this->password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function host(): string
    {
        return (string) getenv('AVD_TEST_MYSQL_HOST');
    }

    private function port(): string
    {
        return (string) (getenv('AVD_TEST_MYSQL_PORT') ?: '3306');
    }

    private function user(): string
    {
        return (string) (getenv('AVD_TEST_MYSQL_USER') ?: 'root');
    }

    private function password(): string
    {
        return (string) (getenv('AVD_TEST_MYSQL_PASSWORD') ?: '');
    }
}
