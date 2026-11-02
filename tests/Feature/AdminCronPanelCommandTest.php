<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\LaravelSchedulerCron;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Round 3 IN-RV-01 (Tools > Scheduled tasks): a hosting panel's cron job (cPanel, Plesk, DirectAdmin, CloudPanel,
 * HestiaCP, aaPanel ...) takes "every minute" in fields of its own and hands its command field to /bin/sh, which read the
 * "* * * * *" of the crontab line the page offered for it as file names: the job never started the scheduler. The page
 * gives such a job the same command without the schedule; the crontab line stays for the account's crontab.
 */
class AdminCronPanelCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_hosting_panels_cron_job_gets_the_command_without_the_crontab_schedule(): void
    {
        config(['admin.operational_tools_enabled' => true, 'admin.ui' => 'v2']);
        Cache::flush();
        $answer = fn (string $probe, array $data): array => ['ok' => true, 'probe' => $probe, 'status' => 'ok', 'label' => $probe, 'detail' => null,
            'data' => $data, 'action' => null, 'checked_at' => now()->toIso8601String(), 'ms' => 1];
        Cache::put('admin.status.schedule.en', $answer('schedule', ['tasks' => [], 'count' => 0]), 300);
        Cache::put('admin.status.php.en', $answer('php', ['path' => PHP_BINARY, 'usable' => true]), 600);
        $admin = User::factory()->admin()->create();

        $page = (string) $this->actingAs($admin)->get(route('admin.cron.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('A cron job set up in your hosting panel works as well: use the line below.', $page);

        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows: the Task Scheduler's command, no cron job of a panel
            $this->assertStringNotContainsString('Command for a cron job in your hosting panel (every minute):', $page);

            return;
        }

        $command = LaravelSchedulerCron::commandFor(PHP_BINARY);
        $this->assertStringStartsWith('/usr/bin/flock -n -o '.escapeshellarg(LaravelSchedulerCron::lockPath()), $command);
        $this->assertSame('* * * * * '.$command, LaravelSchedulerCron::lineFor(PHP_BINARY));
        $this->assertStringContainsString('Command for a cron job in your hosting panel (every minute):', $page);
        $this->assertStringContainsString('<code>'.e($command).'</code>', $page, 'the command alone, for the panel');
        $this->assertStringContainsString('<code>'.e('* * * * * '.$command).'</code>', $page, 'the crontab line stays, for the account\'s crontab');
        $this->assertLessThan(strpos($page, 'If your panel runs cron jobs as root, use this command instead:'), strpos($page, 'Command for a cron job in your hosting panel (every minute):'));

        if (LaravelSchedulerCron::crontabState()['state'] === 'absent') {
            $this->assertStringContainsString(e("This account's crontab has no line of this site. A cron job set up in your hosting panel works as well: use the command for it below."), $page);
        }
    }
}
