<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CronController;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

/**
 * R1R-17: Tools > Scheduled tasks describes every task of the Kernel in the page's own plain words, which the site
 * translates, never with the command's developer description (English in every language).
 */
class ScheduledTaskPlainWordsTest extends TestCase
{
    public function test_every_scheduled_command_of_the_kernel_has_plain_words(): void
    {
        // queue:work is scheduled only with a real queue connection (the tests run with "sync").
        config(['queue.default' => 'database']);
        $events = $this->app->make(ConsoleKernel::class)->resolveConsoleSchedule()->events();

        $commands = [];
        $missing = [];
        foreach ($events as $event) {
            /** @var Event $event */
            $command = (string) $event->command;
            $name = preg_match('/artisan[\'"]?\s+[\'"]?([\w:\-]+)/', $command, $match) === 1 ? $match[1] : ($event->description ?: $command);
            $commands[] = $name;

            // Without the command's own description, describe() can answer only with the page's own words.
            if (CronController::describe(['command' => $command, 'description' => '']) === null) {
                $missing[] = $name;
            }
        }

        $this->assertGreaterThanOrEqual(20, count(array_unique($commands)), 'the Kernel defines its tasks in this test');
        foreach (['history:fix-old-times', 'notifications:index', 'premium:trial_end', 'queue:work', 'discovery:snapshots'] as $expected) {
            $this->assertContains($expected, $commands);
        }
        $this->assertSame([], array_values(array_unique($missing)), 'scheduled commands without plain words in CronController::TASK_DESCRIPTIONS');
    }

    public function test_the_two_tasks_of_1_2_3_and_the_trial_reminder_read_in_plain_words(): void
    {
        $artisan = "'/usr/bin/php8.3' 'artisan'";

        // 1.2.3 round 3 (R3-005, R3-035): the conversion also covers likes / dislikes in Your activity and playlist "Date added"
        $this->assertSame(
            'After the update to 1.2.3, sets the older times of history, subscription, playlist, like and dislike entries to the site time zone, a little at a time',
            CronController::describe(['command' => "{$artisan} history:fix-old-times --seconds=20", 'description' => 'Put the times of history, subscription, saved-playlist, like / dislike activity and playlist "Date added" rows written before 1.2.3 in the site time zone'])
        );
        $this->assertSame(
            'On sites with many notifications, finishes the part of the update that keeps the "Unread" notifications tab fast',
            CronController::describe(['command' => "{$artisan} notifications:index", 'description' => 'Build the notifications index that "Finish update" left to the scheduler on a big table'])
        );
        // R3-009 and R3-022's look-around: the video indexes a big table gets from the scheduler
        $this->assertSame(
            'On sites with many videos, finishes the part of the update that keeps the video lists and counts fast',
            CronController::describe(['command' => "{$artisan} videos:index", 'description' => 'Give the video indexes that "Finish update" left to the scheduler on a big table their 1.2.3 definitions'])
        );
        // DB-V1-2: Home's rails, its shorts shelf and the search chips of a big site, worked out exactly in the background
        $this->assertSame(
            'On sites with many videos, counts the popular tags, creators and actors of the home page and checks the search suggestions in the background',
            CronController::describe(['command' => "{$artisan} discovery:snapshots --seconds=50", 'description' => 'Work out the home page rails and search chips that pages of a big site leave to the scheduler'])
        );

        // The reminder goes out as many days before the end of the trial as the admin set (Money > Premium plans),
        // which the console now applies too (R1R-06): the page says that number, not a fixed 7.
        config(['plans.trial_period.email_reminder' => 3]);
        $this->assertSame('E-mails members whose free trial ends soon (days before the end: 3)', CronController::describe(['command' => "{$artisan} premium:trial_end"]));

        // Translated with the site's language, the number kept.
        $key = 'E-mails members whose free trial ends soon (days before the end: :days)';
        app('translator')->addLines(['*.'.$key => 'Gửi email cho thành viên có thời gian dùng thử miễn phí sắp kết thúc (số ngày trước khi kết thúc: :days)'], 'vi');
        app()->setLocale('vi');
        $this->assertSame('Gửi email cho thành viên có thời gian dùng thử miễn phí sắp kết thúc (số ngày trước khi kết thúc: 3)', CronController::describe(['command' => "{$artisan} premium:trial_end"]));
    }
}
