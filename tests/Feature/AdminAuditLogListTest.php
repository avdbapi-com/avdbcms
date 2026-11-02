<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tools > Logs > Admin actions (R1-083, R1-099): the actions read in plain words ("update.telegram_links" was shown as
 * it is stored), the details are shown in full (they were cut at about 70 characters), and rows written in the same
 * second keep the newest-first order (sorted by the time only, they came oldest first and a page could repeat or skip
 * a row).
 */
class AdminAuditLogListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2', 'app.locale' => 'en']);
    }

    public function test_actions_read_in_plain_words_and_details_are_shown_in_full(): void
    {
        $details = '1.2.3: new AVDBAPI Telegram group t.me/+jj6McO-iYfA0MTM1 in: Telegram channel, footer Support links (group + support)';
        $this->row('update.telegram_links', $details, '2026-10-27 10:00:00');
        $this->row('some.future.action', 'kept as it is', '2026-10-27 09:00:00');
        $long = str_repeat('A long detail of an update. ', 20);
        $this->row('update.texts', $long, '2026-10-27 08:00:00');

        $html = $this->actingAs(User::factory()->admin()->create())->get('/admin/audit')->assertOk()->getContent();

        $this->assertStringContainsString('>Update: new Telegram links</b>', $html);
        $this->assertStringContainsString('data-audit-action="update.telegram_links"', $html, 'the key stays in the row');
        // the whole detail, not cut: in English it reads as stored, its version and link set apart (<bdi>, R2A-054)
        $this->assertStringContainsString(e($details), strip_tags($html), 'the whole detail, not cut');
        $this->assertStringContainsString('>some.future.action</b>', $html, 'an unknown action shows its key');
        $this->assertStringContainsString(e(trim($long)), $html, 'a long detail is there in full (folded)');
        $this->assertStringContainsString('<details class="ad-audit-detail">', $html);
    }

    public function test_rows_of_the_same_second_stay_newest_first_across_pages(): void
    {
        $second = '2026-10-27 10:00:00';
        $ids = [];

        foreach (range(1, 30) as $n) {
            $ids[] = $this->row('cron.task.run', 'row '.$n, $second);
        }

        $admin = User::factory()->admin()->create();
        $first = $this->details($this->actingAs($admin)->get('/admin/audit?per_page=25')->assertOk()->getContent());
        $second = $this->details($this->actingAs($admin)->get('/admin/audit?per_page=25&page=2')->assertOk()->getContent());

        $this->assertSame(array_map(fn (int $n): string => 'row '.$n, range(30, 6)), $first, 'the newest row first, then by id');
        $this->assertSame(array_map(fn (int $n): string => 'row '.$n, range(5, 1)), $second, 'the second page goes on without a repeat or a gap');
    }

    public function test_both_admins_order_the_rows_of_one_second_by_id(): void
    {
        // The rows alone cannot show a missing tie-break on SQLite: it reads the created_at index backwards, which
        // already gives the rows of one second newest id first (MariaDB and MySQL sort them in any order, RC1 gave
        // them oldest first there). So the list query itself must order by the id after the time, in both admins.
        $this->row('cron.task.run', 'one', '2026-10-27 10:00:00');
        $admin = User::factory()->admin()->create();

        foreach (['v2', 'v1'] as $ui) {
            config(['admin.ui' => $ui]);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->get('/admin/audit')->assertOk();
            $lists = collect(DB::getQueryLog())->pluck('query')
                ->filter(fn (string $sql): bool => str_contains($sql, 'from "admin_audit_logs"') && str_contains($sql, ' order by '))
                ->values();
            DB::disableQueryLog();

            $this->assertCount(1, $lists, $ui.': one list query');
            $this->assertStringContainsString('order by "admin_audit_logs"."created_at" desc, "admin_audit_logs"."id" desc', $lists[0], $ui);
        }
    }

    private function row(string $action, string $details, string $at): int
    {
        return (int) DB::table('admin_audit_logs')->insertGetId([
            'user_id' => null,
            'action' => $action,
            'details' => $details,
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => $at,
        ]);
    }

    /** @return list<string> the details of the rows, in page order */
    private function details(string $html): array
    {
        preg_match_all('#<div class="ad-audit-detail">(row \d+)</div>#', $html, $matches);

        return $matches[1];
    }
}
