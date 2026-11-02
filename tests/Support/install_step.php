<?php

/**
 * One request's worth of the web installer (App\Support\Installer: startRun, runStep, runStep with finalize) as its own
 * PHP process, for Tests\Feature\InstallerMysqlRunTest: the test ends such a process from outside in the middle of a step
 * (Symfony Process::stop(0): SIGKILL / TerminateProcess - no PHP code runs any more, like PHP-FPM's request_terminate_timeout
 * kill) and checks that the next process goes on and the installation ends exactly like one that was never interrupted.
 *
 * usage: php tests/Support/install_step.php <work dir> <start|step|finish>
 * <work dir>/payload.json: database, admin, app, token. The installer's files (.env, lock, run state, run lock) are in the
 * work dir; its storage link, permissions, cache clearing and cron line are left out (they would act on the copy itself).
 * Prints one JSON line: the progress.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
[$self, $work, $action] = $argv + [null, null, 'step'];
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// The robots.txt of the files step (App\Support\RobotsTxt) goes into the work dir too, never into the copy's public/; so
// does its record of what it wrote (not the copy's storage/app; the test removes the work dir's files).
$app->instance(App\Support\RobotsTxt::FOLDERS, ['public' => $work, 'project' => $work, 'storage' => $work]);

$payload = json_decode((string) file_get_contents($work.'/payload.json'), true);
$token = (string) $payload['token'];
unset($payload['token']);

$installer = new class($work) extends App\Support\Installer
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

    protected function prepareFiles(App\Support\InstallRun $run): void
    {
        $run->message('Files prepared (test).');
    }

    protected function installSchedulerCron(): void
    {
    }
};

try {
    $progress = match ($action) {
        'start' => $installer->startRun($payload, $token),
        'finish' => $installer->runStep($payload, $token, false, true),
        default => $installer->runStep($payload, $token),
    };
    echo json_encode(['ok' => true, 'progress' => $progress], JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e).': '.substr($e->getMessage(), 0, 300)], JSON_UNESCAPED_SLASHES), "\n";
}
