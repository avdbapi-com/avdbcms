<?php

namespace Tests\Feature;

use App\Console\Commands\BuildNotificationIndexes;
use App\Models\User;
use App\Notifications\Support\Inbox;
use App\Notifications\Support\NotificationIndexes;
use App\Notifications\Support\NotificationTasks;
use App\Notifications\Support\UnreadKeys;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The notifications page's "Unread" tab at any size (judge R1-008): the same rows, in the same order, page by page in
 * both directions, with the unread list index and before it exists (a member with few unread notifications, a member
 * with many, a long run of read ones in between), with a running "Mark all as read" / "Delete all"; the migration builds
 * the index at once on a small table and leaves a big one to the scheduler (notifications:index), which builds it once.
 */
class NotificationsUnreadTabTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_VIDEO = 'App\\Notifications\\Activity\\NewVideo';

    private const MIGRATION = 'database/migrations/2026_10_27_120000_add_notifications_unread_list_index.php';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:30'));
        NotificationTasks::flushState();
        NotificationIndexes::forget();
        UnreadKeys::reset();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        NotificationIndexes::$buildNowRows = 100000;
        UnreadKeys::$walkRows = 20000;
        UnreadKeys::reset();
        NotificationIndexes::forget();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_index_is_built_by_the_migration_on_a_small_table(): void
    {
        $this->assertTrue(Schema::hasIndex('notifications', NotificationIndexes::UNREAD_LIST), 'a fresh install has it');
        $this->assertTrue(NotificationIndexes::has());
        $this->assertFalse(NotificationIndexes::due(), 'nothing for the scheduler');
    }

    public function test_every_way_of_reading_the_tab_gives_the_same_rows_in_both_directions(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        // 150 unread among 420: runs of read rows of every length (the longest 120), rows sharing a second
        $this->addPattern($member, 420, fn (int $i): bool => ! (($i >= 40 && $i < 160) || ($i % 7 === 3) || ($i >= 300 && $i < 330)));
        $this->addPattern($other, 60, fn (int $i): bool => true); // another member's unread rows are never shown

        $expected = $this->reference($member);
        UnreadKeys::$smallSet = 100;
        $this->assertGreaterThan(UnreadKeys::$smallSet, count($expected));

        $this->assertPaging($member, $expected, ['index']);

        $this->dropIndex();
        $this->assertPaging($member, $expected, ['walk']);

        UnreadKeys::$walkRows = 30; // a long run of read rows: the rest of such a page from the unread-count index
        $this->assertPaging($member, $expected, ['walk', 'walk+rest'], 'walk+rest');

        // more unread rows than the rest may sort: such a page ends where the walk stopped, the next one goes on from there
        UnreadKeys::$restMax = 20;
        $this->assertPaging($member, $expected, ['walk', 'walk+resume'], 'walk+resume');
    }

    public function test_a_page_the_walk_ended_without_rows_says_more_may_follow_going_on_and_back(): void
    {
        config(['site.per_page_comments' => 10]);
        $member = User::factory()->create();
        // 30 unread at the top, 400 read, 30 unread at the bottom
        $this->addPattern($member, 460, fn (int $i): bool => $i < 30 || $i >= 430);
        $expected = $this->reference($member);
        $this->dropIndex();
        UnreadKeys::$smallSet = 10;
        UnreadKeys::$walkRows = 50;
        UnreadKeys::$restMax = 10;

        // the pages from $url by the pager's $rel links to the last one: the ids of each page, how many had no row, the
        // last page; a page without rows says that more may follow, in words that name no direction (it is reached by
        // Next, toward older rows, and by Previous, toward newer ones)
        $walk = function (string $url, string $rel) use ($member): array {
            $pages = [];
            $empty = 0;

            for ($guard = 0; $guard < 40; $guard++) {
                $html = (string) $this->actingAs($member)->get($url)->assertOk()->getContent();
                preg_match_all('#/profile/notifications/([0-9a-f-]{36})/click#', $html, $rows);
                $pages[] = $rows[1];

                if ($rows[1] === []) {
                    $empty++;
                    $this->assertStringContainsString(e(__('More unread notifications may follow.')), $html, $rel);
                    $this->assertStringContainsString('fa-hourglass-half', $html, $rel);
                    $this->assertStringNotContainsString('Older unread', $html, $rel.': no direction named');
                    $this->assertStringNotContainsString(e(__('Nothing unread')), $html, 'never "nothing unread" while more may follow');
                }

                if (! preg_match('#href="([^"]+)" rel="'.$rel.'"#', $html, $link)) {
                    return [$pages, $empty, $html];
                }

                $url = html_entity_decode($link[1]);
            }

            $this->fail('the pages never ended ('.$rel.')');
        };

        // on by Next to the last page
        [$pages, $empty, $last] = $walk(route('user.notifications.index', ['show' => 'unread']), 'next');
        $this->assertGreaterThan(0, $empty, 'the read run made a page without rows');
        $this->assertSame($expected, array_merge(...$pages), 'every unread row once, newest first, across the pages the walk cut short');
        $this->assertStringContainsString(e(__('You are all caught up')), $last);

        // back by Previous from the last page to the first: through the read run again (pages without rows going back
        // too), every unread row once
        $this->assertSame(1, preg_match('#href="([^"]+)" rel="prev"#', $last, $prev));
        [$back, $emptyBack] = $walk(html_entity_decode($prev[1]), 'prev');
        $this->assertGreaterThan(0, $emptyBack, 'going back, the read run made a page without rows too');
        $this->assertSame($expected, array_merge(...array_reverse($back), ...[end($pages)]), 'back to the first page: every unread row once');
    }

    public function test_a_member_with_few_unread_notifications_is_read_at_once_without_the_index(): void
    {
        $member = User::factory()->create();
        // 3 unread at the top, 1 deep down, 400 read rows around them
        $this->addPattern($member, 404, fn (int $i): bool => in_array($i, [0, 1, 2, 390], true));
        $this->dropIndex();
        UnreadKeys::$smallSet = 100;

        $expected = $this->reference($member);
        $this->assertCount(4, $expected);
        $this->assertPaging($member, $expected, ['small'], 'small', 2);
    }

    public function test_a_running_mark_all_as_read_and_delete_all_are_applied_as_the_bell_shows_them(): void
    {
        $member = User::factory()->create();
        $ids = $this->addPattern($member, 140, fn (int $i): bool => true, now()->subMinutes(30));
        $click = now()->subMinutes(10);
        $newer = $this->addPattern($member, 6, fn (int $i): bool => true, now()->subMinutes(2));
        $kept = [$ids[50], $ids[90]];

        foreach ([true, false] as $withIndex) {
            if (! $withIndex) {
                $this->dropIndex();
            }

            // "Mark all as read" at $click, two rows marked unread again since: the newer rows, then those two
            $markers = ['read_before' => $click, 'cleared_before' => null, 'kept' => $kept];
            $this->assertSame(array_merge($newer, array_values(array_intersect($ids, $kept))), $this->allKeys($member, $markers, 4));

            // "Delete all" at $click: only the newer rows
            $markers = ['read_before' => null, 'cleared_before' => $click, 'kept' => []];
            $this->assertSame($newer, $this->allKeys($member, $markers, 4));
        }

        // a kept id of another member is never shown
        $stranger = User::factory()->create();
        $foreign = $this->addPattern($stranger, 1, fn (int $i): bool => true, now()->subMinutes(20));
        $markers = ['read_before' => $click, 'cleared_before' => null, 'kept' => [$foreign[0], $ids[50]]];
        $this->assertSame(array_merge($newer, [$ids[50]]), $this->allKeys($member, $markers, 50));
    }

    public function test_the_page_shows_the_unread_rows_and_its_pager_goes_on_and_back(): void
    {
        config(['site.per_page_comments' => 10]);
        $member = User::factory()->create();
        $this->addPattern($member, 60, fn (int $i): bool => $i % 2 === 0);
        $expected = $this->reference($member);
        $this->dropIndex();

        $html = (string) $this->actingAs($member)->get(route('user.notifications.index', ['show' => 'unread']))->assertOk()->getContent();
        $this->assertSame(10, substr_count($html, 'class="mk-notif is-unread"'));
        $this->assertSame(0, substr_count($html, 'class="mk-notif"'), 'no read row on the Unread tab');
        $this->assertMatchesRegularExpression('#href="[^"]*show=unread[^"]*cursor=[^"]+" rel="next"#', $html);
        preg_match('#href="([^"]+)" rel="next"#', $html, $next);
        $second = (string) $this->actingAs($member)->get(html_entity_decode($next[1]))->assertOk()->getContent();
        $this->assertStringContainsString('/profile/notifications/'.$expected[10].'/click', $second);
        $this->assertStringNotContainsString('/profile/notifications/'.$expected[9].'/click', $second);
        preg_match('#href="([^"]+)" rel="prev"#', $second, $prev);
        $first = (string) $this->actingAs($member)->get(html_entity_decode($prev[1]))->assertOk()->getContent();
        $this->assertStringContainsString('/profile/notifications/'.$expected[0].'/click', $first);
        $this->assertStringContainsString('/profile/notifications/'.$expected[9].'/click', $first);
    }

    public function test_a_big_table_is_left_to_the_scheduler_which_builds_the_index_once(): void
    {
        $member = User::factory()->create();
        $this->addPattern($member, 30, fn (int $i): bool => $i % 3 === 0);
        $migration = require base_path(self::MIGRATION);
        $migration->down();
        $this->assertFalse(Schema::hasIndex('notifications', NotificationIndexes::UNREAD_LIST));

        // a table bigger than the "Finish update" limit: the migration only asks for the index
        NotificationIndexes::$buildNowRows = 10;
        $migration->up();
        $this->assertFalse(Schema::hasIndex('notifications', NotificationIndexes::UNREAD_LIST), 'not built inside the update');
        $this->assertTrue(NotificationIndexes::due(), 'the scheduler starts notifications:index');
        $this->assertSame($this->reference($member), $this->allKeys($member, ['read_before' => null, 'cleared_before' => null, 'kept' => []], 4), 'the tab works meanwhile');

        // the scheduler has the task, in the background, started only while the index is missing
        $event = collect($this->app->make(ConsoleKernel::class)->resolveConsoleSchedule()->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'notifications:index'));
        $this->assertNotNull($event, 'scheduled');
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->filtersPass($this->app));

        // another builder holds the lock: this run builds nothing
        $lock = Cache::lock('notifications.indexes.build', 60);
        $this->assertTrue($lock->get());
        $this->assertSame('another build is running', NotificationIndexes::build()['skipped']);
        $lock->release();

        $this->assertSame(0, Artisan::call('notifications:index'));
        $this->assertStringContainsString('built '.NotificationIndexes::UNREAD_LIST, Artisan::output());
        $this->assertTrue(Schema::hasIndex('notifications', NotificationIndexes::UNREAD_LIST));
        $this->assertFalse(NotificationIndexes::due(), 'once built, never again');
        $this->assertFalse($event->filtersPass($this->app));
        $this->assertTrue(BuildNotificationIndexes::due() === false);

        // the migration twice: nothing to do; down() then up() on a small table: built at once
        $migration->up();
        NotificationIndexes::$buildNowRows = 100000;
        $migration->down();
        $this->assertFalse(Schema::hasIndex('notifications', NotificationIndexes::UNREAD_LIST));
        $migration->up();
        $this->assertTrue(Schema::hasIndex('notifications', NotificationIndexes::UNREAD_LIST));
        $this->assertTrue(Schema::hasIndex('notifications', UnreadKeys::UNREAD_COUNT_INDEX), 'the badge index of 1.2.2 stays');
    }

    public function test_the_admin_list_indexes_of_a_big_table_are_left_to_the_scheduler_too(): void
    {
        $member = User::factory()->create();
        $this->addPattern($member, 30, fn (int $i): bool => true);
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_created_id_idx');
            $table->dropIndex('notifications_read_created_id_idx');
        });
        NotificationIndexes::forget();

        // "Finish update" on a table bigger than the limit: migration 2026_10_25_120000 makes its task table only
        NotificationIndexes::$buildNowRows = 10;
        $listMigration = require base_path('database/migrations/2026_10_25_120000_add_notification_tasks_and_list_indexes.php');
        $listMigration->up();
        $this->assertTrue(Schema::hasTable('notification_tasks'));
        $this->assertFalse(Schema::hasIndex('notifications', 'notifications_created_id_idx'), 'not built inside the update');
        $this->assertFalse(Schema::hasIndex('notifications', 'notifications_read_created_id_idx'));
        $this->assertTrue(NotificationIndexes::due());

        // the scheduler's run builds both (with any other missing one) in one pass
        $this->assertSame(0, Artisan::call('notifications:index'));
        $this->assertTrue(Schema::hasIndex('notifications', 'notifications_created_id_idx'));
        $this->assertTrue(Schema::hasIndex('notifications', 'notifications_read_created_id_idx'));
        $this->assertFalse(NotificationIndexes::due());

        // a small table: built inside the update as before (the migration test of NotificationsAtScaleTest)
        NotificationIndexes::$buildNowRows = 100000;
        $listMigration->down();
        $listMigration->up();
        $this->assertTrue(Schema::hasIndex('notifications', 'notifications_created_id_idx'));
        $this->assertTrue(Schema::hasIndex('notifications', 'notifications_read_created_id_idx'));
    }

    public function test_after_a_failed_build_the_next_try_is_told_in_the_sites_time_zone(): void
    {
        // the real clock (the pause is counted from it) on a site far from Greenwich
        Carbon::setTestNow();
        $zone = date_default_timezone_get();
        date_default_timezone_set('Asia/Ho_Chi_Minh');

        try {
            $migration = require base_path(self::MIGRATION);
            $migration->down();
            $this->assertTrue(NotificationIndexes::due(), 'the index is missing and its migration ran');

            // the build fails (as when a long transaction holds the table): a pause, then the next try
            DB::connection()->beforeExecuting(function (string $query): void {
                if (str_contains($query, NotificationIndexes::UNREAD_LIST) && stripos($query, 'index') !== false && stripos($query, 'drop') === false) {
                    throw new \RuntimeException('Lock wait timeout exceeded; try restarting transaction');
                }
            });
            $this->assertSame(0, Artisan::call('notifications:index'));
            $this->assertStringContainsString('index not built yet', Artisan::output());
            $this->assertFalse(NotificationIndexes::due(), 'a pause runs');

            $this->assertSame(0, Artisan::call('notifications:index'));
            $said = Artisan::output();
            $this->assertSame(1, preg_match('/^(\S+ \S+) - notifications: an earlier try failed; the next one is after (\S+ \S+) /m', $said, $times), $said);
            $line = Carbon::createFromFormat('Y-m-d H:i:s', $times[1], 'Asia/Ho_Chi_Minh');
            $next = Carbon::createFromFormat('Y-m-d H:i:s', $times[2], 'Asia/Ho_Chi_Minh');
            $this->assertTrue($next->greaterThanOrEqualTo($line), 'the next try comes after the line\'s own time: '.$said);
            $this->assertLessThanOrEqual(61, $line->diffInSeconds($next), 'a minute later, in the same zone: '.$said);
            $this->assertSame('Asia/Ho_Chi_Minh', NotificationIndexes::nextTry()?->getTimezone()->getName());
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function test_before_finish_update_the_scheduler_builds_nothing(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->down();
        DB::table('migrations')->where('migration', NotificationIndexes::MIGRATION)->delete();

        $this->assertFalse(NotificationIndexes::due(), 'the update is not finished: the database is left as it is');
        Artisan::call('notifications:index');
        $this->assertFalse(Schema::hasIndex('notifications', NotificationIndexes::UNREAD_LIST));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Pages through the Unread tab with next cursors to the end, then back with previous cursors to the start: both
     * give $expected (in its order), each page read one of the $allowed ways, $needed at least once.
     *
     * @param  list<string>  $expected
     * @param  list<string>  $allowed
     */
    private function assertPaging(User $member, array $expected, array $allowed, ?string $needed = null, int $perPage = 7): void
    {
        NotificationIndexes::forget();
        $needed ??= $allowed[0];
        $label = implode('/', $allowed);
        $used = [];
        $seen = [];
        $pages = [];
        $cursor = null;

        do {
            $page = $this->tab($member, $cursor, $perPage);
            $this->assertContains(UnreadKeys::$lastStrategy, $allowed, 'read the '.$label.' way');
            $used[UnreadKeys::$lastStrategy] = true;
            $ids = array_map(fn ($row) => (string) $row->id, $page->items());
            $pages[] = [$ids, $page];
            $seen = array_merge($seen, $ids);
            $cursor = $page->nextCursor();
        } while ($cursor !== null && count($pages) < 200);

        $this->assertSame($expected, $seen, 'every unread row once, newest first ('.$label.')');
        $this->assertFalse($pages[count($pages) - 1][1]->hasMorePages());
        $this->assertArrayHasKey($needed, $used, 'some page read the '.$needed.' way');

        if (! in_array('walk+resume', $allowed, true)) {
            // back from the last page: each previous page is the page before it
            for ($i = count($pages) - 1; $i > 0; $i--) {
                $back = $this->tab($member, $pages[$i][1]->previousCursor(), $perPage);
                $this->assertContains(UnreadKeys::$lastStrategy, $allowed);
                $this->assertSame($pages[$i - 1][0], array_map(fn ($row) => (string) $row->id, $back->items()), 'previous page '.$i.' ('.$label.')');
            }
        }

        // back from the last page to the first by the previous links: every row once (pages a walk cut short included)
        $back = $pages[count($pages) - 1][0];
        $current = $pages[count($pages) - 1][1];

        for ($guard = 0; ($cursor = $current->previousCursor()) !== null && $guard < 200; $guard++) {
            $current = $this->tab($member, $cursor, $perPage);
            $this->assertContains(UnreadKeys::$lastStrategy, $allowed);
            $back = array_merge(array_map(fn ($row) => (string) $row->id, $current->items()), $back);
        }

        $this->assertSame($expected, $back, 'back to the first page: every unread row once ('.$label.')');
        $this->assertTrue($current->onFirstPage() || $current->previousCursor() === null);
    }

    private function tab(User $member, ?Cursor $cursor, int $perPage): \Illuminate\Contracts\Pagination\CursorPaginator
    {
        request()->query->set('cursor', $cursor?->encode());
        request()->request->remove('cursor');

        return Inbox::unreadPage($member, $perPage)[0];
    }

    /**
     * Every key UnreadKeys gives for these markers, page by page (the next cursors).
     *
     * @param  array{read_before: ?Carbon, cleared_before: ?Carbon, kept: array<int, string>}  $markers
     * @return list<string>
     */
    private function allKeys(User $member, array $markers, int $perPage): array
    {
        $ids = [];
        $cursor = null;

        for ($guard = 0; $guard < 500; $guard++) {
            [$rows, $resume] = UnreadKeys::page($member, $cursor, $perPage + 1, $markers);
            $page = array_slice($rows, 0, $perPage);
            $ids = array_merge($ids, array_map(fn ($row) => (string) $row->id, $page));

            if ($resume !== null) {
                $cursor = $resume;

                continue;
            }

            if (count($rows) <= $perPage) {
                return $ids;
            }

            $last = end($page);
            $cursor = new Cursor(['created_at' => (string) $last->created_at, 'id' => (string) $last->id], true);
        }

        $this->fail('the pages never ended');
    }

    /** @return list<string> the member's unread ids, newest first, in the database's own order */
    private function reference(User $member): array
    {
        return Inbox::rows($member)->whereNull('read_at')->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    private function dropIndex(): void
    {
        if (Schema::hasIndex('notifications', NotificationIndexes::UNREAD_LIST)) {
            Schema::table('notifications', fn (Blueprint $table) => $table->dropIndex(NotificationIndexes::UNREAD_LIST));
        }

        NotificationIndexes::forget();
        Cache::forget('notifications.indexes.state.v1');
    }

    /**
     * $count notifications of $member, newest first from $start, three to a second; $unread($i) says which are unread.
     *
     * @return list<string> their ids, newest first
     */
    private function addPattern(User $member, int $count, callable $unread, ?Carbon $start = null): array
    {
        $start = ($start ?? now()->subMinutes(5))->copy()->startOfSecond();
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $at = $start->copy()->subSeconds(intdiv($i, 3));
            $rows[] = [
                'id' => (string) Str::uuid(),
                'type' => self::NEW_VIDEO,
                'notifiable_type' => $member->getMorphClass(),
                'notifiable_id' => $member->id,
                'data' => json_encode(['message' => 'chan'.$i.' has published new video !', 'url' => '/video/'.$i]),
                'read_at' => $unread($i) ? null : $at->copy()->addMinute(),
                'created_at' => $at,
                'updated_at' => $at,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('notifications')->insert($chunk);
        }

        return Inbox::rows($member)->whereIn('id', array_column($rows, 'id'))->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
    }
}
