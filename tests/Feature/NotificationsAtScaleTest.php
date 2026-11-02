<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Events\Video\VideoPublished;
use App\Http\Controllers\Admin\NotificationController as AdminNotificationController;
use App\Http\Middleware\ViewOnlyDemo;
use App\Jobs\RunNotificationTasks;
use App\Models\Notification;
use App\Models\User;
use App\Models\Video;
use App\Notifications\Support\Inbox;
use App\Notifications\Support\NotificationRetention;
use App\Notifications\Support\NotificationTasks;
use App\Support\AdminPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Notifications at scale (1.2.3): the bell's list by key without skipped or repeated rows, the capped badge, 404 for
 * another member's notification, "Mark all as read" / "Delete all" answered at once and carried out in batches, the
 * new-video fan-out and the admin's notification to everyone in batches by key (once per video), the admin list by key
 * with capped counts, "Delete all" of the admin, the daily tidy-up, and the migration run twice.
 */
class NotificationsAtScaleTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_VIDEO = 'App\\Notifications\\Activity\\NewVideo';

    protected function setUp(): void
    {
        parent::setUp();
        // a fixed moment: the tests count minutes back from "now", and the hour a clock goes back in autumn would make
        // two of those minutes the same local time
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:30'));
        NotificationTasks::flushState();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        NotificationTasks::$batch = 2000;
        NotificationTasks::$requestBatch = 500;
        NotificationTasks::$requestSeconds = 0.5;
        NotificationTasks::$workerSeconds = NotificationTasks::WORKER_SECONDS;
        AdminNotificationController::$countCap = 100000;
        NotificationTasks::flushState();
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ the member's list and badge

    public function test_the_list_never_skips_or_repeats_rows_that_share_a_second(): void
    {
        config(['site.per_page_comments' => 10]);
        $member = User::factory()->create();
        $expected = $this->addNotifications($member, 57, sameSecond: 20); // pages of 10 cut the groups of 20
        $this->addNotifications(User::factory()->create(), 15);

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = $this->actingAs($member)->getJson('/api/notifications'.($cursor ? '?cursor='.urlencode($cursor) : ''))->assertOk();
            $seen = array_merge($seen, array_column($response->json('data'), 'id'));
            $cursor = $response->json('meta.next_cursor');
            $pages++;
        } while ($cursor !== null && $pages < 20);

        $this->assertSame($expected, $seen, 'every notification once, newest first by (created_at, id)');
        $this->assertSame(6, $pages);

        // a page opened before 1.2.3 sends a cursor with created_at only: the list starts again at the newest
        $old = (new \Illuminate\Pagination\Cursor(['created_at' => now()->subMinutes(10)->toDateTimeString()], true))->encode();
        $this->actingAs($member)->getJson('/api/notifications?cursor='.$old)->assertOk()->assertJsonPath('data.0.id', $expected[0]);
        $this->actingAs($member)->getJson('/api/notifications?cursor=not-a-cursor')->assertOk()->assertJsonPath('data.0.id', $expected[0]);
    }

    public function test_the_badge_counts_up_to_a_hundred_and_shows_99_plus(): void
    {
        $member = User::factory()->create();
        $this->addNotifications($member, 150);
        $this->addNotifications($member, 10, ['read_at' => now()]);

        $this->actingAs($member)->getJson('/api/notifications')->assertOk()->assertJsonPath('unread', 100)->assertJsonPath('unread_cap', 99);
        $this->assertSame(100, Inbox::unreadCount($member));
        $this->assertSame('99+', Inbox::badgeText(100));
        $this->assertSame('99', Inbox::badgeText(99));
        $this->assertSame('7', Inbox::badgeText(7));
        $this->assertSame('', Inbox::badgeText(0));

        $few = User::factory()->create();
        $this->addNotifications($few, 3);
        $this->addNotifications($few, 2, ['read_at' => now()]);
        $this->assertSame(3, Inbox::unreadCount($few));
        $this->assertStringContainsString('count="3"', $this->actingAs($few)->get(route('user.edit'))->assertOk()->getContent());
    }

    public function test_another_members_notification_answers_404_and_stays_untouched(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        [$id] = $this->addNotifications($owner, 1);

        $this->actingAs($other)->postJson("/api/notifications/{$id}/read")->assertNotFound();
        $this->actingAs($other)->postJson("/api/notifications/{$id}/unread")->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/notifications/{$id}/delete")->assertNotFound();
        $this->actingAs($other)->get("/profile/notifications/{$id}/click")->assertNotFound();
        $this->actingAs($other)->postJson('/api/notifications/delete-selected', ['ids' => [$id]])->assertOk()->assertJsonPath('deleted', 0);
        $this->actingAs($other)->postJson('/api/notifications/'.Str::uuid().'/read')->assertNotFound();

        $this->assertNull(Notification::query()->findOrFail($id)->read_at);

        $this->actingAs($owner)->postJson("/api/notifications/{$id}/read")->assertOk()->assertJsonPath('unread', 0);
        $this->assertNotNull(Notification::query()->findOrFail($id)->read_at);
        $this->actingAs($owner)->postJson("/api/notifications/{$id}/unread")->assertOk()->assertJsonPath('unread', 1);
        $this->actingAs($owner)->deleteJson("/api/notifications/{$id}/delete")->assertOk()->assertJsonPath('unread', 0);
        $this->assertDatabaseMissing('notifications', ['id' => $id]);
    }

    public function test_the_get_of_pages_opened_before_the_update_needs_the_page_token(): void
    {
        $member = User::factory()->create();
        [$id] = $this->addNotifications($member, 1);

        // a link or an image on another site cannot send the token header
        $this->actingAs($member)->getJson("/api/notifications/{$id}/read")->assertStatus(419);
        $this->actingAs($member)->getJson('/api/notifications/read-all')->assertStatus(419);
        $this->assertNull(Notification::query()->findOrFail($id)->read_at);

        // an Authorization header does not stand in for the token while the browser's session signs the member in
        $this->actingAs($member)->withHeaders(['Authorization' => 'Bearer lane-n-not-a-token'])->getJson("/api/notifications/{$id}/read")->assertStatus(419);
        $this->assertNull(Notification::query()->findOrFail($id)->read_at);

        $this->actingAs($member)
            ->withSession(['_token' => 'lane-n-page-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'lane-n-page-token', 'Referer' => rtrim((string) config('app.url'), '/').'/'])
            ->getJson("/api/notifications/{$id}/read")
            ->assertOk();
        $this->assertNotNull(Notification::query()->findOrFail($id)->read_at);

        // an app signed in with an API token is not affected
        $this->app['auth']->forgetGuards();
        $token = $member->createToken('lane-n-app', ['api'])->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson("/api/notifications/{$id}/unread")->assertOk()->assertJsonPath('unread', 1);
    }

    public function test_a_view_only_demo_account_reads_its_bell_but_changes_nothing(): void
    {
        $member = User::factory()->create();
        $member->forceFill([ViewOnlyDemo::COLUMN => true])->save();
        $member = $member->fresh();
        [$id] = $this->addNotifications($member, 3);

        $this->actingAs($member)->getJson('/api/notifications')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('unread', 3);
        $this->actingAs($member)->postJson('/api/notifications/read-all')->assertForbidden();
        $this->actingAs($member)->deleteJson('/api/notifications/delete-all')->assertForbidden();
        $this->actingAs($member)->postJson("/api/notifications/{$id}/read")->assertForbidden();
        $this->actingAs($member)->deleteJson("/api/notifications/{$id}/delete")->assertForbidden();
        $this->actingAs($member)->postJson('/api/notifications/delete-selected', ['ids' => [$id]])->assertForbidden();

        $this->assertSame(3, Inbox::rows($member)->whereNull('read_at')->count());
        $this->assertSame(0, DB::table(NotificationTasks::TABLE)->count(), 'no long work was started');
    }

    public function test_a_view_only_demo_account_is_refused_on_the_old_get_addresses_too(): void
    {
        $member = User::factory()->create();
        $member->forceFill([ViewOnlyDemo::COLUMN => true])->save();
        $member = $member->fresh();
        [$id] = $this->addNotifications($member, 2);
        $refusal = 'This is a view-only demo account: you can look at everything, but changes are switched off.';

        foreach (["/api/notifications/{$id}/read", "/api/notifications/{$id}/unread", '/api/notifications/read-all'] as $address) {
            // the page's own token header: everything a page opened before the update sends
            $this->actingAs($member)
                ->withSession(['_token' => 'lane-n-page-token'])
                ->withHeaders(['X-CSRF-TOKEN' => 'lane-n-page-token', 'Referer' => rtrim((string) config('app.url'), '/').'/'])
                ->getJson($address)
                ->assertForbidden()
                ->assertHeader(ViewOnlyDemo::HEADER, '1')
                ->assertJsonPath('success', false)
                ->assertJsonPath('errors.view_only_demo.0', $refusal);
        }

        $this->assertSame(2, Inbox::rows($member)->whereNull('read_at')->count());
        $this->assertSame(0, DB::table(NotificationTasks::TABLE)->count(), 'no long work was started');
        $this->assertFalse(Cache::has('notifications.tasks.pending'), 'no flag left that makes the bell carry work on');
        $this->assertFalse(Cache::has('notifications.tasks.progress_at'));
        $this->assertFalse(Cache::has('notifications.tasks.chain'));
    }

    public function test_delete_selected_takes_at_most_two_hundred_of_the_members_own(): void
    {
        $member = User::factory()->create();
        $mine = $this->addNotifications($member, 5);
        $theirs = $this->addNotifications(User::factory()->create(), 2);

        $this->actingAs($member)->postJson('/api/notifications/delete-selected', ['ids' => [$mine[0], $mine[1], $theirs[0]]])
            ->assertOk()->assertJsonPath('deleted', 2)->assertJsonPath('unread', 3);
        $this->assertDatabaseHas('notifications', ['id' => $theirs[0]]);
        $this->actingAs($member)->postJson('/api/notifications/delete-selected', ['ids' => array_fill(0, 201, $mine[2])])->assertUnprocessable();
    }

    // ------------------------------------------------------------------ "Mark all as read" / "Delete all"

    public function test_read_all_answers_at_once_and_finishes_in_batches(): void
    {
        NotificationTasks::$batch = 50;
        NotificationTasks::$requestSeconds = 0.0; // one batch in the request
        Carbon::setTestNow(now()->startOfSecond());
        $member = User::factory()->create();
        $ids = $this->addNotifications($member, 300);
        $this->addNotifications($member, 20, ['read_at' => now()->subDay()]);
        $other = User::factory()->create();
        $this->addNotifications($other, 30);

        $this->actingAs($member)->postJson('/api/notifications/read-all')->assertOk()
            ->assertJsonPath('finished', false)->assertJsonPath('unread', 0);
        $this->assertSame(250, Inbox::rows($member)->whereNull('read_at')->count(), 'one batch of 50 in the request');

        // a notification the member marks unread again after the click stays unread
        Carbon::setTestNow(now()->addSeconds(2));
        $kept = $ids[299];
        $this->actingAs($member)->postJson("/api/notifications/{$kept}/unread")->assertOk();
        // one arriving after the click is new
        $this->addNotifications($member, 1, ['created_at' => now(), 'updated_at' => now()], now());

        [$page] = Inbox::page($member, 50);
        $shown = collect($page->items())->keyBy('id');
        $this->assertNotNull($shown[$ids[10]]->read_at, 'shown read before its batch ran');
        $this->assertSame(2, Inbox::unreadCount($member), 'the new one and the one marked unread again are counted');
        $this->actingAs($member)->getJson('/api/notifications')->assertOk()->assertJsonPath('unread', 2);

        Artisan::call('notifications:tidy', ['--seconds' => 10]);

        $this->assertSame(2, Inbox::rows($member)->whereNull('read_at')->count());
        $this->assertNull(Notification::query()->findOrFail($kept)->read_at);
        $this->assertSame(30, Inbox::rows($other)->whereNull('read_at')->count(), "another member's stay unread");
        $this->assertSame(0, DB::table(NotificationTasks::TABLE)->whereNull('finished_at')->count());
    }

    public function test_delete_all_hides_everything_at_once_and_deletes_in_batches(): void
    {
        NotificationTasks::$batch = 40;
        NotificationTasks::$requestSeconds = 0.0;
        Carbon::setTestNow(now()->startOfSecond());
        $member = User::factory()->create();
        $this->addNotifications($member, 200);
        $other = User::factory()->create();
        $this->addNotifications($other, 25);

        $this->actingAs($member)->deleteJson('/api/notifications/delete-all')->assertOk()->assertJsonPath('finished', false)->assertJsonPath('unread', 0);
        $this->assertSame(160, Inbox::rows($member)->count());
        $this->actingAs($member)->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('unread', 0);

        Carbon::setTestNow(now()->addSeconds(2));
        $this->addNotifications($member, 1, ['created_at' => now(), 'updated_at' => now()], now());
        Artisan::call('notifications:tidy', ['--seconds' => 10]);

        $this->assertSame(1, Inbox::rows($member)->count(), 'only the one that came after the click');
        $this->assertSame(25, Inbox::rows($other)->count());
    }

    public function test_on_the_sync_queue_the_next_bell_requests_carry_on(): void
    {
        config(['queue.default' => 'sync']); // no queue worker, and no scheduler runs in this test
        NotificationTasks::$batch = 40;
        NotificationTasks::$requestSeconds = 0.0;
        Carbon::setTestNow(now()->startOfSecond());
        $member = User::factory()->create();
        $this->addNotifications($member, 200);

        $this->actingAs($member)->deleteJson('/api/notifications/delete-all')->assertOk()->assertJsonPath('finished', false);
        $this->assertSame(160, Inbox::rows($member)->count());

        // each answer of the bell is sent first, then a short step runs (app()->terminating)
        for ($i = 0; $i < 10 && Inbox::rows($member)->count() > 0; $i++) {
            $this->actingAs($member)->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('unread', 0);
        }

        $this->assertSame(0, Inbox::rows($member)->count());
        $this->assertSame(0, DB::table(NotificationTasks::TABLE)->count());
    }

    public function test_a_click_asks_the_queue_once_and_the_job_carries_on(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        NotificationTasks::$batch = 40;
        NotificationTasks::$requestSeconds = 0.0;
        $member = User::factory()->create();
        $this->addNotifications($member, 200);

        $this->actingAs($member)->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('finished', false);
        $this->actingAs($member)->deleteJson('/api/notifications/delete-all')->assertOk()->assertJsonPath('finished', false);
        Queue::assertPushed(RunNotificationTasks::class, 1); // one job: it asks for the next one itself

        $this->runJob(0);

        $this->assertSame(0, Inbox::rows($member)->count());
        $this->assertSame(0, DB::table(NotificationTasks::TABLE)->count());
        Queue::assertPushed(RunNotificationTasks::class, 1); // nothing left: no next job
    }

    // ------------------------------------------------------------------ the queued job: one chain, never a loop

    public function test_a_task_another_worker_holds_never_makes_the_job_spin(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $member = User::factory()->create();
        $this->addNotifications($member, 60);
        // a "Mark all as read" a worker took 80 seconds ago and never gave back (the worker died): free in 40 seconds
        $this->heldTask($member, now()->addSeconds(40));

        NotificationTasks::kick();
        $this->assertCount(1, $this->pushedJobs());

        $this->runJob(0);
        $jobs = $this->pushedJobs();
        $this->assertCount(2, $jobs, 'one next job, not one after another');
        $this->assertSame(40, $jobs[1]->delay, 'asked for the moment the task is free again');
        $this->assertSame(60, Inbox::rows($member)->whereNull('read_at')->count(), 'nothing done while another worker holds it');

        // the same job again (a copy left in the queue) and a job without a token stop at once
        $this->runJob(0);
        (new RunNotificationTasks())->handle();
        $this->assertCount(2, $this->pushedJobs());

        // once the task is free, the next job finishes it and asks for no more
        Carbon::setTestNow(now()->addSeconds(41));
        $this->runJob(1);
        $this->assertSame(0, Inbox::rows($member)->whereNull('read_at')->count());
        $this->assertSame(0, DB::table(NotificationTasks::TABLE)->count());
        $this->assertCount(2, $this->pushedJobs());
        $this->assertFalse(NotificationTasks::hasWork());
    }

    public function test_a_task_the_scheduler_holds_is_waited_for_until_its_run_ends(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $member = User::factory()->create();
        $this->addNotifications($member, 5);
        // taken just now by the scheduler: its lock reaches 120 seconds ahead, its run ends within 20
        $this->heldTask($member, now()->addSeconds(120));
        $this->assertFalse(NotificationTasks::hasWork(), 'the scheduler is not started again for a held task');

        NotificationTasks::kick();
        $this->runJob(0);

        $this->assertCount(2, $this->pushedJobs());
        $this->assertSame(25, $this->pushedJobs()[1]->delay);
    }

    public function test_a_failing_task_is_waited_for_until_its_pause_ends(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $member = User::factory()->create();
        $this->addNotifications($member, 5);
        $task = $this->heldTask($member, now()->addSeconds(180), failures: 3);

        NotificationTasks::kick();
        $this->runJob(0);
        $this->assertSame(180, $this->pushedJobs()[1]->delay);

        // a pause of an hour: the job comes back after 15 minutes at most (the longest every queue driver delays)
        DB::table(NotificationTasks::TABLE)->where('id', $task)->update(['locked_until' => now()->addHour(), 'failures' => 60]);
        $this->runJob(1);
        $this->assertCount(3, $this->pushedJobs());
        $this->assertSame(900, $this->pushedJobs()[2]->delay);
    }

    public function test_while_work_is_left_each_job_asks_for_exactly_one_next_job(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        NotificationTasks::$batch = 40;
        NotificationTasks::$requestSeconds = 0.0;
        NotificationTasks::$workerSeconds = 0.0; // one batch a job
        $member = User::factory()->create();
        $this->addNotifications($member, 200);

        $this->actingAs($member)->deleteJson('/api/notifications/delete-all')->assertOk()->assertJsonPath('finished', false);
        $this->assertSame(160, Inbox::rows($member)->count());

        for ($runs = 0; $runs < $this->pushedJobs()->count() && $runs < 20; $runs++) {
            $queued = $this->pushedJobs()->count();
            $this->runJob($runs);
            $this->assertLessThanOrEqual($queued + 1, $this->pushedJobs()->count(), 'one next job at most');
            $this->assertNull($this->pushedJobs()->last()->delay, 'a task to work on: the next job runs at once');
        }

        $this->assertSame(0, Inbox::rows($member)->count());
        $this->assertSame(0, DB::table(NotificationTasks::TABLE)->count());
        $this->assertSame(5, $runs, '4 batches of 40, then the job that finds the end');
    }

    public function test_extra_jobs_in_the_queue_stop_without_work_and_without_a_next_job(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        NotificationTasks::$batch = 40;
        NotificationTasks::$requestSeconds = 0.0;
        $member = User::factory()->create();
        $this->addNotifications($member, 200);

        $this->actingAs($member)->deleteJson('/api/notifications/delete-all')->assertOk();
        foreach (range(1, 4) as $i) {
            RunNotificationTasks::dispatch('lane-n-left-over-'.$i);
        }
        $this->assertCount(5, $this->pushedJobs());

        foreach (range(1, 4) as $i) {
            $this->runJob($i);
        }
        $this->assertSame(160, Inbox::rows($member)->count(), 'the extra jobs did nothing');
        $this->assertCount(5, $this->pushedJobs(), 'and asked for nothing');

        $this->runJob(0);
        $this->assertSame(0, Inbox::rows($member)->count());
        $this->assertCount(5, $this->pushedJobs());
    }

    public function test_new_work_does_not_wait_behind_a_job_that_waits_for_a_held_task(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        NotificationTasks::$batch = 40;
        NotificationTasks::$requestSeconds = 0.0;
        $paused = User::factory()->create();
        $this->addNotifications($paused, 5);
        $this->heldTask($paused, now()->addSeconds(600), failures: 5);

        NotificationTasks::kick();
        $this->runJob(0);
        $this->assertSame(600, $this->pushedJobs()[1]->delay);

        // a minute later another member clicks "Delete all": a job that runs now, not in 9 minutes
        Carbon::setTestNow(now()->addSeconds(61));
        $member = User::factory()->create();
        $this->addNotifications($member, 200);
        $this->actingAs($member)->deleteJson('/api/notifications/delete-all')->assertOk()->assertJsonPath('finished', false);
        $this->assertCount(3, $this->pushedJobs());
        $this->assertNull($this->pushedJobs()[2]->delay);

        $this->runJob(1); // the waiting job was replaced: it stops
        $this->assertCount(3, $this->pushedJobs());
        $this->runJob(2);
        $this->assertSame(0, Inbox::rows($member)->count());
        $this->assertCount(4, $this->pushedJobs(), 'the paused task is still waited for');
        $this->assertSame(539, $this->pushedJobs()[3]->delay);
    }

    public function test_on_the_sync_queue_a_job_never_runs_another_one_inside_it(): void
    {
        config(['queue.default' => 'sync']);
        $member = User::factory()->create();
        $this->addNotifications($member, 5);
        $this->heldTask($member, now()->addSeconds(40));

        (new RunNotificationTasks('lane-n-sync'))->handle(); // returns: nothing to take, and no job inside this one

        $this->assertSame(5, Inbox::rows($member)->whereNull('read_at')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_read_all_and_delete_all_tell_the_panel_the_moment_of_the_click(): void
    {
        $member = User::factory()->create();
        $this->addNotifications($member, 5);
        $moment = now()->startOfSecond()->toIso8601ZuluString();

        $this->actingAs($member)->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('finished', true)->assertJsonPath('before', $moment);
        $this->actingAs($member)->deleteJson('/api/notifications/delete-all')->assertOk()->assertJsonPath('finished', true)->assertJsonPath('before', $moment);
        $this->assertStringEndsWith('Z', $moment);
    }

    public function test_the_click_takes_a_small_batch_and_the_workers_bigger_ones(): void
    {
        NotificationTasks::$batch = 100;
        NotificationTasks::$requestBatch = 30;
        NotificationTasks::$requestSeconds = 0.0;
        Carbon::setTestNow(now()->startOfSecond());
        $member = User::factory()->create();
        $this->addNotifications($member, 250);

        $this->actingAs($member)->deleteJson('/api/notifications/delete-all')->assertOk()->assertJsonPath('finished', false);
        $this->assertSame(220, Inbox::rows($member)->count(), 'one small batch in the page that answers');

        $this->assertFalse(NotificationTasks::run((int) DB::table(NotificationTasks::TABLE)->value('id'), 0.0));
        $this->assertSame(120, Inbox::rows($member)->count(), 'a worker batch is the bigger one');

        Artisan::call('notifications:tidy', ['--seconds' => 10]);
        $this->assertSame(0, Inbox::rows($member)->count());
        $this->assertSame(0, DB::table(NotificationTasks::TABLE)->count());
    }

    // ------------------------------------------------------------------ fan-out and sending to everyone

    public function test_a_new_video_reaches_every_subscriber_in_batches_and_only_once(): void
    {
        config(['site.broadcast_notification_chunk_size' => 50]);
        NotificationTasks::$requestSeconds = 0.0;
        $channel = User::factory()->create(['username' => 'lane_n_channel']);
        $subscribers = User::factory()->count(130)->create();
        foreach ($subscribers as $subscriber) {
            DB::table('subscriptions')->insert(['subscriber_id' => $subscriber->id, 'user_id' => $channel->id, 'subscribe_at' => now(), 'read_at' => now()]);
        }
        $subscribers->first()->delete(); // a deleted account gets nothing
        $video = Video::factory()->for($channel)->create(['title' => 'Harbour lights', 'status' => VideoStatus::PRIVATE, 'published_at' => null]);

        $video->update(['status' => VideoStatus::PUBLIC, 'published_at' => now()]);

        // one batch of 50 subscriptions (by key); the deleted account's is passed over
        $this->assertSame(49, DB::table('notifications')->where('type', self::NEW_VIDEO)->count(), 'the publishing page wrote one batch');
        Artisan::call('notifications:tidy', ['--seconds' => 10]);
        $this->assertSame(129, DB::table('notifications')->where('type', self::NEW_VIDEO)->count());
        $this->assertSame(129, DB::table('notifications')->where('type', self::NEW_VIDEO)->distinct()->count('notifiable_id'));

        $row = Notification::query()->where('type', self::NEW_VIDEO)->firstOrFail();
        $this->assertSame('new_video', $row->data['text']);
        $this->assertSame(['name' => 'lane_n_channel', 'title' => 'Harbour lights'], $row->data['params']);
        $this->assertSame('lane_n_channel published a new video: Harbour lights', $row->message);

        VideoPublished::dispatch($video->fresh());
        Artisan::call('notifications:tidy', ['--seconds' => 10]);
        $this->assertSame(129, DB::table('notifications')->where('type', self::NEW_VIDEO)->count(), 'announced once');
    }

    public function test_a_scheduled_video_of_a_missed_minute_is_announced_once(): void
    {
        $channel = User::factory()->create();
        $subscriber = User::factory()->create();
        DB::table('subscriptions')->insert(['subscriber_id' => $subscriber->id, 'user_id' => $channel->id, 'subscribe_at' => now(), 'read_at' => now()]);
        Cache::forever('notifications.announce.since', now()->subMinutes(30)->startOfMinute()->getTimestamp());
        $at = now()->subMinutes(5)->startOfMinute();
        Video::factory()->for($channel)->create(['status' => VideoStatus::PLANNED, 'scheduled_at' => $at, 'published_at' => $at]);

        Artisan::call('video:published');
        Artisan::call('video:published');

        $this->assertSame(1, Inbox::rows($subscriber)->where('type', self::NEW_VIDEO)->count());
    }

    public function test_sending_to_every_member_goes_out_in_batches(): void
    {
        config(['site.broadcast_notification_chunk_size' => 50, 'admin.ui' => 'v2']);
        NotificationTasks::$requestSeconds = 0.0;
        $admin = User::factory()->admin()->create();
        User::factory()->count(119)->create();
        User::factory()->create()->delete();
        $members = User::query()->count();

        $this->actingAs($admin)->post(route('admin.notifications.broadcast'), ['title' => 'Site news', 'message' => 'We moved.', 'target' => 'all'])
            ->assertRedirect(route('admin.notifications.index'))
            ->assertSessionHas('success', "Sending to {$members} members. The last ones get it within a few minutes.");
        $this->assertSame(50, DB::table('notifications')->count());

        Artisan::call('notifications:tidy', ['--seconds' => 10]);
        $this->assertSame($members, DB::table('notifications')->count());
        $this->assertSame($members, DB::table('notifications')->distinct()->count('notifiable_id'));
    }

    // ------------------------------------------------------------------ the admin

    public function test_the_admin_list_goes_by_key_with_capped_counts(): void
    {
        config(['admin.ui' => 'v2']);
        AdminNotificationController::$countCap = 30;
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'admin_ui', 'v2');
        $this->addNotifications(User::factory()->create(), 70, sameSecond: 7);

        $first = $this->actingAs($admin)->get(route('admin.notifications.index', ['per_page' => 25]))->assertOk()->getContent();
        $this->assertStringContainsString('More than 30 notifications were sent.', $first);
        $this->assertStringContainsString('More than 30 are not read yet.', $first);
        $this->assertMatchesRegularExpression('/rel="next"/', $first);
        $this->assertDoesNotMatchRegularExpression('/Showing \d/', $first);

        preg_match('/href="([^"]+)" rel="next"/', $first, $next);
        $second = $this->actingAs($admin)->get(html_entity_decode($next[1]))->assertOk()->getContent();
        // the rows' boxes (the row menu's template carries name="ids[]" value="__ID__")
        preg_match_all('/name="ids\[\]" value="([0-9a-f-]{36})"/', $first, $a);
        preg_match_all('/name="ids\[\]" value="([0-9a-f-]{36})"/', $second, $b);
        $this->assertCount(25, $a[1]);
        $this->assertCount(25, $b[1]);
        $this->assertSame([], array_intersect($a[1], $b[1]), 'no row on two pages');
    }

    public function test_admin_delete_all_empties_the_table_at_once(): void
    {
        config(['admin.ui' => 'v2']);
        NotificationTasks::$batch = 10;
        NotificationTasks::$requestSeconds = 0.0;
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $this->addNotifications($member, 40);
        $this->actingAs($member)->postJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(1, DB::table(NotificationTasks::TABLE)->count());

        $this->actingAs($admin)->delete(route('admin.notifications.clear'))
            ->assertRedirect(route('admin.notifications.index'))
            ->assertSessionHas('success', 'All notifications were deleted.');

        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(0, DB::table(NotificationTasks::TABLE)->count(), 'the member tasks have nothing left to do');
    }

    // ------------------------------------------------------------------ growth

    public function test_old_notifications_are_tidied_by_the_daily_rule(): void
    {
        $busy = User::factory()->create();
        $this->addNotifications($busy, 1000, [], now()->subMinutes(5));
        $this->addNotifications($busy, 30, ['read_at' => now()->subDays(40)], now()->subDays(45));
        $young = $this->addNotifications($busy, 10, [], now()->subDays(10)->subHours(5));
        $this->addNotifications($busy, 10, [], now()->subDays(60));
        $quiet = User::factory()->create();
        $this->addNotifications($quiet, 5, ['read_at' => now()->subDays(190)], now()->subDays(200));
        $oldUnread = $this->addNotifications($quiet, 5, [], now()->subDays(250));
        $recentRead = $this->addNotifications($quiet, 3, ['read_at' => now()->subDays(2)], now()->subDays(3));
        $oldButReadLately = $this->addNotifications($quiet, 2, ['read_at' => now()->subDays(10)], now()->subDays(200));
        // a busy member far down the member numbers (the members are counted a range at a time)
        $far = User::factory()->create(['id' => 40000]);
        $this->addNotifications($far, 1003, [], now()->subDays(60));

        $this->assertTrue(NotificationRetention::due());
        $result = NotificationRetention::run(30);

        $this->assertSame('idle', $result['phase']);
        $this->assertSame(1010, Inbox::rows($busy)->count(), 'the newest 1,000 and the unread ones younger than 30 days');
        $this->assertSame(10, Inbox::rows($busy)->whereIn('id', $young)->count());
        $this->assertSame(10, Inbox::rows($quiet)->count(), 'read more than 180 days ago go; unread and recently read ones stay');
        $this->assertSame(5, Inbox::rows($quiet)->whereIn('id', $oldUnread)->count());
        $this->assertSame(3, Inbox::rows($quiet)->whereIn('id', $recentRead)->count());
        $this->assertSame(2, Inbox::rows($quiet)->whereIn('id', $oldButReadLately)->count(), '180 days from the reading');
        $this->assertSame(1000, Inbox::rows($far)->count(), 'the oldest 3, unread but older than 30 days, go');
        $this->assertFalse(NotificationRetention::due(), 'once a day');
    }

    public function test_the_daily_tidy_up_removes_the_notifications_of_accounts_that_no_longer_exist(): void
    {
        $kept = User::factory()->create();
        $this->addNotifications($kept, 5);
        $softDeleted = User::factory()->create();
        $this->addNotifications($softDeleted, 4);
        $softDeleted->delete(); // in the trash: still an account
        // removed without the account's own clean-up (as the removal of unconfirmed accounts does), young and unread
        $removed = User::factory()->create();
        $this->addNotifications($removed, 7, [], now()->subHour());
        $far = User::factory()->create(['id' => 40000]);
        $this->addNotifications($far, 3);
        DB::table('users')->whereIn('id', [$removed->id, $far->id])->delete();

        $result = NotificationRetention::run(30);

        $this->assertSame('idle', $result['phase']);
        $this->assertSame(10, $result['deleted']);
        $this->assertSame(5, NotificationTasks::memberRows($kept->id)->count());
        $this->assertSame(4, NotificationTasks::memberRows($softDeleted->id)->count());
        $this->assertSame(0, NotificationTasks::memberRows($removed->id)->count());
        $this->assertSame(0, NotificationTasks::memberRows(40000)->count());
    }

    public function test_deleting_an_account_removes_its_notifications_in_batches(): void
    {
        config(['queue.default' => 'sync']); // no queue worker: the scheduler carries on
        NotificationTasks::$requestBatch = 30;
        $gone = User::factory()->create();
        $this->addNotifications($gone, 100);
        $stays = User::factory()->create();
        $this->addNotifications($stays, 10);
        $goneId = (int) $gone->id;

        $gone->forceDelete();

        $this->assertDatabaseMissing('users', ['id' => $goneId]);
        $this->assertSame(70, NotificationTasks::memberRows($goneId)->count(), 'one small batch in the page that deletes');
        $this->assertSame(1, DB::table(NotificationTasks::TABLE)->where('kind', NotificationTasks::DELETE_ALL)->where('user_id', $goneId)->count());

        Artisan::call('notifications:tidy', ['--seconds' => 10]);

        $this->assertSame(0, NotificationTasks::memberRows($goneId)->count());
        $this->assertSame(10, NotificationTasks::memberRows((int) $stays->id)->count(), "another member's stay");
        $this->assertSame(0, DB::table(NotificationTasks::TABLE)->count());
    }

    public function test_an_account_with_few_notifications_is_cleaned_in_the_page_that_deletes_it(): void
    {
        $gone = User::factory()->create();
        $this->addNotifications($gone, 12);
        $goneId = (int) $gone->id;

        $gone->forceDelete();

        $this->assertSame(0, NotificationTasks::memberRows($goneId)->count());
        $this->assertSame(0, DB::table(NotificationTasks::TABLE)->count(), 'nothing left for later');
    }

    // ------------------------------------------------------------------ updates

    public function test_the_migration_is_safe_to_run_twice_and_to_roll_back(): void
    {
        $migration = require base_path('database/migrations/2026_10_25_120000_add_notification_tasks_and_list_indexes.php');

        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasTable('notification_tasks'));
        $this->assertTrue(Schema::hasIndex('notifications', 'notifications_created_id_idx'));
        $this->assertTrue(Schema::hasIndex('notifications', 'notifications_read_created_id_idx'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('notification_tasks'));
        $this->assertFalse(Schema::hasIndex('notifications', 'notifications_created_id_idx'));
        $this->assertTrue(Schema::hasIndex('notifications', 'notifications_notifiable_created_id_idx'), 'the indexes of 1.2.2 stay');

        $migration->up();
        $this->assertTrue(Schema::hasTable('notification_tasks'));
    }

    public function test_before_finish_update_the_work_runs_the_old_way_in_batches(): void
    {
        Schema::drop(NotificationTasks::TABLE);
        NotificationTasks::flushState();
        config(['site.broadcast_notification_chunk_size' => 50]);
        $member = User::factory()->create();
        $this->addNotifications($member, 120);

        $this->actingAs($member)->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('finished', true)->assertJsonPath('unread', 0);
        $this->assertSame(0, Inbox::rows($member)->whereNull('read_at')->count());

        $channel = User::factory()->create();
        foreach (User::factory()->count(70)->create() as $subscriber) {
            DB::table('subscriptions')->insert(['subscriber_id' => $subscriber->id, 'user_id' => $channel->id, 'subscribe_at' => now(), 'read_at' => now()]);
        }
        VideoPublished::dispatch(Video::factory()->for($channel)->create());
        $this->assertSame(70, DB::table('notifications')->where('type', self::NEW_VIDEO)->where('notifiable_id', '!=', $member->id)->count(), 'every subscriber, in batches, in this request');
    }

    // ------------------------------------------------------------------ helpers

    /** The RunNotificationTasks jobs the fake queue took, in order. */
    private function pushedJobs(): \Illuminate\Support\Collection
    {
        return Queue::pushed(RunNotificationTasks::class)->values();
    }

    /** Runs the $index-th RunNotificationTasks job the fake queue took, as a worker would. */
    private function runJob(int $index): void
    {
        $this->pushedJobs()[$index]->handle();
    }

    /** A "Mark all as read" of $user that another worker holds until $until (with $failures: one that pauses after failures). */
    private function heldTask(User $user, Carbon $until, int $failures = 0): int
    {
        return (int) DB::table(NotificationTasks::TABLE)->insertGetId(['kind' => NotificationTasks::READ_ALL, 'user_id' => $user->id,
            'before_at' => now(), 'done' => 0, 'runs' => 1, 'failures' => $failures, 'locked_until' => $until,
            'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Notifications of $user, newest first: $count rows, $sameSecond of them per second going back from $start.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<int, string> their ids, newest first by (created_at, id)
     */
    private function addNotifications(User $user, int $count, array $overrides = [], ?Carbon $start = null, int $sameSecond = 1): array
    {
        $start = ($start ?? now()->subMinutes(10))->copy()->startOfSecond();
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $at = $start->copy()->subSeconds(intdiv($i, max(1, $sameSecond)));
            $rows[] = array_merge([
                'id' => (string) Str::uuid(),
                'type' => self::NEW_VIDEO,
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->id,
                'data' => json_encode(['message' => 'chan'.$i.' has published new video !', 'url' => '/video/'.$i]),
                'read_at' => null,
                'created_at' => $at,
                'updated_at' => $at,
            ], $overrides);
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('notifications')->insert($chunk);
        }

        usort($rows, fn (array $a, array $b) => [Carbon::parse($b['created_at'])->getTimestamp(), $b['id']] <=> [Carbon::parse($a['created_at'])->getTimestamp(), $a['id']]);

        return array_column($rows, 'id');
    }
}
