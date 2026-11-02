<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Support\Inbox;
use App\Notifications\Support\NotificationIndexes;
use App\Notifications\Support\NotificationTasks;
use App\Notifications\Support\UnreadKeys;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The notifications page's "All" tab and the bell's list at any size (judge R3-002): each page of a member's list names
 * the member's own newest-first index (notifiable_type, notifiable_id, created_at, id) on MySQL / MariaDB while it
 * exists, so a page is one range read of its entries at any depth. Left to itself, MySQL 8.0 / 8.4 paged a member who
 * holds a big share of the table by the admin list's (created_at, id) index and read every newer notification of every
 * member before the page (23.8 s for page 2 of a 30,000-notification member on MySQL 8.4). Inbox::$hints = true makes
 * SQLite name it as MySQL / MariaDB do ("indexed by"); the rows and their order are the same with and without the name,
 * in both directions, and a page whose index was dropped is read without it.
 */
class NotificationsAllTabIndexTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_VIDEO = 'App\\Notifications\\Activity\\NewVideo';

    private const MEMBER_INDEX = 'notifications_notifiable_created_id_idx';

    /** @var list<string> */
    private array $statements = [];

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
        Inbox::$hints = null;
        UnreadKeys::reset();
        NotificationIndexes::forget();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_page_of_the_all_tab_names_the_members_own_index_and_gives_the_same_rows_both_ways(): void
    {
        [$member, $expected] = $this->memberAmongOthers();
        $this->listen();

        // as on MySQL / MariaDB: every page's keys from the member's own index, page by page and back
        Inbox::$hints = true;
        $pages = $this->assertAllPages($member, $expected, 'named');
        $keys = $this->keyStatements();
        $this->assertGreaterThanOrEqual(2 * count($pages) - 1, count($keys), 'every page forward and back');
        foreach ($keys as $sql) {
            $this->assertStringContainsString('indexed by '.self::MEMBER_INDEX, $sql);
            $this->assertStringContainsString('order by "created_at" ', $sql);
        }

        // another database: the same pages, no index named
        Inbox::$hints = false;
        $this->statements = [];
        $this->assertSame($pages, $this->assertAllPages($member, $expected, 'not named'));
        $this->assertNotEmpty($this->keyStatements());
        $this->assertSame([], array_values(array_filter($this->statements, fn (string $sql) => str_contains($sql, 'indexed by'))));
    }

    public function test_the_notifications_page_and_the_bell_read_their_older_pages_by_the_members_index(): void
    {
        config(['site.per_page_comments' => 10]);
        [$member, $expected] = $this->memberAmongOthers();
        Inbox::$hints = true;
        $this->listen();

        // the notifications page (All): the first page, then the Older link
        $html = (string) $this->actingAs($member)->get(route('user.notifications.index'))->assertOk()->getContent();
        $this->assertSame(array_slice($expected, 0, 10), $this->clicks($html));
        $this->assertSame(1, preg_match('#href="([^"]+)" rel="next"#', $html, $next));
        $second = (string) $this->actingAs($member)->get(html_entity_decode($next[1]))->assertOk()->getContent();
        $this->assertSame(array_slice($expected, 10, 10), $this->clicks($second));

        // the bell: its first page and the next one (it loads older notifications as the member scrolls)
        $bell = $this->actingAs($member)->getJson('/api/notifications')->assertOk();
        $this->assertSame(array_slice($expected, 0, 10), array_column($bell->json('data'), 'id'));
        $cursor = (string) $bell->json('meta.next_cursor');
        $this->assertNotSame('', $cursor);
        $older = $this->actingAs($member)->getJson('/api/notifications?cursor='.urlencode($cursor))->assertOk();
        $this->assertSame(array_slice($expected, 10, 10), array_column($older->json('data'), 'id'));

        $keys = $this->keyStatements();
        $this->assertCount(4, $keys, 'two pages of the notifications page, two of the bell');
        foreach ($keys as $sql) {
            $this->assertStringContainsString('indexed by '.self::MEMBER_INDEX, $sql);
        }
    }

    public function test_a_page_whose_index_is_gone_is_read_without_it_and_the_index_is_looked_up_again(): void
    {
        [$member, $expected] = $this->memberAmongOthers();
        Inbox::$hints = true;
        $this->assertTrue(NotificationIndexes::has(self::MEMBER_INDEX), 'seen once: remembered');

        // dropped by hand (or a restored backup without it): the statement that still names it is refused, reported, and
        // the page is read without it; the next pages look the index up again and name nothing
        Schema::table('notifications', fn (Blueprint $table) => $table->dropIndex(self::MEMBER_INDEX));
        Exceptions::fake();
        $this->listen();
        $this->assertAllPages($member, $expected, 'index gone');
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(QueryException::class);
        $keys = $this->keyStatements();
        $this->assertStringContainsString('indexed by '.self::MEMBER_INDEX, $keys[0], 'the first page still named it: refused');
        $this->assertStringNotContainsString('indexed by', $keys[1], 'the same page again without it');
        $this->assertSame([], array_values(array_filter(array_slice($keys, 2), fn (string $sql) => str_contains($sql, 'indexed by'))), 'then no more');
        $this->assertFalse(NotificationIndexes::has(self::MEMBER_INDEX));
    }

    public function test_a_running_delete_all_is_applied_on_the_named_index_too(): void
    {
        $member = User::factory()->create();
        $old = $this->addRows($member, 30, now()->subHours(3));
        $click = now()->subHour();
        $newer = $this->addRows($member, 12, now()->subMinutes(10));
        $this->addRows(User::factory()->create(), 20, now()->subMinutes(5));
        $this->assertCount(30, $old);
        DB::table(NotificationTasks::TABLE)->insert([
            'kind' => NotificationTasks::DELETE_ALL, 'user_id' => $member->id, 'before_at' => $click, 'cursor' => null,
            'done' => 0, 'created_at' => $click, 'updated_at' => $click,
        ]);
        NotificationTasks::flushState();
        Inbox::$hints = true;
        $this->listen();

        // only the notifications after the click, forward and back
        $this->assertSame($newer, array_merge(...$this->assertAllPages($member, $newer, 'delete all running', 5)));
        $this->assertNotEmpty($this->keyStatements());
        foreach ($this->keyStatements() as $sql) {
            $this->assertStringContainsString('indexed by '.self::MEMBER_INDEX, $sql);
        }
    }

    public function test_the_admin_list_keeps_reading_the_whole_table_by_its_own_order(): void
    {
        [$member] = $this->memberAmongOthers();
        Inbox::$hints = true;
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        \App\Support\AdminPreferences::set($admin, 'admin_ui', 'v2');
        $this->listen();

        $this->actingAs($admin)->get(route('admin.notifications.index'))->assertOk();

        $keys = $this->keyStatements();
        $this->assertNotEmpty($keys);
        foreach ($keys as $sql) {
            $this->assertStringNotContainsString(self::MEMBER_INDEX, $sql, 'the admin list is every member\'s: not the member index');
        }
        $this->assertGreaterThan(0, Inbox::rows($member)->count());
    }

    /**
     * A member with 95 notifications (three to a second, so rows share a second at page edges) among newer and older
     * rows of two other members.
     *
     * @return array{0: User, 1: list<string>} the member and its ids, newest first
     */
    private function memberAmongOthers(): array
    {
        $member = User::factory()->create();
        $this->addRows(User::factory()->create(), 60, now()->subMinute());
        $ids = $this->addRows($member, 95, now()->subMinutes(5));
        $this->addRows(User::factory()->create(), 40, now()->subMinutes(30));

        return [$member, $ids];
    }

    /**
     * Pages $member's All tab to the end by the next cursors, then back to the first by the previous ones; asserts every
     * row once, newest first, both ways.
     *
     * @param  list<string>  $expected
     * @return list<list<string>> the ids of each page
     */
    private function assertAllPages(User $member, array $expected, string $label, int $perPage = 10): array
    {
        $pages = [];
        $paginators = [];
        $cursor = null;

        do {
            $page = $this->allTab($member, $cursor, $perPage);
            $pages[] = array_map(fn ($row) => (string) $row->id, $page->items());
            $paginators[] = $page;
            $cursor = $page->nextCursor();
        } while ($cursor !== null && count($pages) < 100);

        $this->assertSame($expected, array_merge(...$pages), 'every row once, newest first ('.$label.')');

        for ($i = count($paginators) - 1; $i > 0; $i--) {
            $back = $this->allTab($member, $paginators[$i]->previousCursor(), $perPage);
            $this->assertSame($pages[$i - 1], array_map(fn ($row) => (string) $row->id, $back->items()), 'previous page '.$i.' ('.$label.')');
        }

        return $pages;
    }

    private function allTab(User $member, ?Cursor $cursor, int $perPage): CursorPaginator
    {
        request()->query->set('cursor', $cursor?->encode());
        request()->request->remove('cursor');

        return Inbox::page($member, $perPage)[0];
    }

    /** @return list<string> the statements that read a page's keys */
    private function keyStatements(): array
    {
        return array_values(array_filter($this->statements, fn (string $sql) => str_starts_with($sql, 'select "id", "created_at" from "notifications"')));
    }

    /** Records every statement as it is sent (also one the database refuses). */
    private function listen(): void
    {
        $this->statements = [];
        DB::connection()->beforeExecuting(function (string $query): void {
            $this->statements[] = $query;
        });
    }

    /** @return list<string> the notification ids of a page's rows, in the page's order */
    private function clicks(string $html): array
    {
        preg_match_all('#/profile/notifications/([0-9a-f-]{36})/click#', $html, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * $count notifications of $member, newest first from $start, three to a second.
     *
     * @return list<string> their ids, newest first
     */
    private function addRows(User $member, int $count, Carbon $start): array
    {
        $start = $start->copy()->startOfSecond();
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $at = $start->copy()->subSeconds(intdiv($i, 3));
            $rows[] = [
                'id' => (string) Str::uuid(),
                'type' => self::NEW_VIDEO,
                'notifiable_type' => $member->getMorphClass(),
                'notifiable_id' => $member->id,
                'data' => json_encode(['message' => 'chan'.$i.' has published new video !', 'url' => '/video/'.$i]),
                'read_at' => $i % 4 === 0 ? $at->copy()->addMinute() : null,
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
