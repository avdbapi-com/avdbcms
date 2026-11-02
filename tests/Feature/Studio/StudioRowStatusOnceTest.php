<?php

namespace Tests\Feature\Studio;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.2.3 round 1b, second pass (R1R-01, member design 4.3: no hidden duplicate markup): a row of My videos printed its
 * status (the chip and the date line) twice, as the meta line of the phone and compact layouts and again as the full
 * table's Visibility cell, one of them hidden by CSS at every width (3.6 KB and 72 nodes of 24 rows). It is printed
 * once; the full table places that one line in its Visibility column (member/_kit.scss). Browser proof (the rows' boxes,
 * words, date style and pixels unchanged; 12 looks, 390 / 1366 / 1600 / 1920 px, Chromium and WebKit, English and
 * Arabic): the lane's row comparison.
 */
class StudioRowStatusOnceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_row_of_my_videos_prints_its_status_once(): void
    {
        $member = User::factory()->create();
        Video::factory()->for($member)->withStatus(VideoStatus::PUBLIC)->create(['title' => 'Sunset drive']);
        Video::factory()->for($member)->withStatus(VideoStatus::PRIVATE)->create(['title' => 'Rooftop session']);
        Video::factory()->for($member)->withStatus(VideoStatus::UNLISTED)->create(['title' => 'Harbour lights']);

        $html = $this->actingAs($member)->get(route('user.videos.index'))->assertOk()->getContent();

        $this->assertSame(3, preg_match_all('~<li class="mk-row" data-id="\d+">(.*?)</li>~s', $html, $rows));
        foreach ($rows[1] as $row) {
            $this->assertSame(1, preg_match_all('~class="mk-status mk-status--[a-z]+"~', $row), 'one status chip per row');
            $this->assertSame(1, substr_count($row, '<div class="mk-row__meta mk-row__meta--status">'), 'one status line');
            $this->assertStringNotContainsString('mk-row__cell--status', $row, 'no second copy for the table');
        }
        // the status line stays where the phone and compact layouts read it: in the title cell, after the title
        $this->assertMatchesRegularExpression('~<div class="mk-row__main">\s*<a class="mk-row__title[^>]*>.*?</a>\s*<div class="mk-row__meta mk-row__meta--status"><span><span class="mk-status mk-status--[a-z]+">[^<]+</span>\s*</span><span>[^<]+</span></div>~s', $rows[1][0]);
    }

    public function test_the_full_table_places_that_line_in_its_visibility_column(): void
    {
        $kit = (string) file_get_contents(resource_path('sass/member/_kit.scss'));
        $this->assertSame(1, preg_match('~@container mklist \(min-width: 77\.5rem\) \{\s*:root \.mk-list__head \{(.*?)\n\}\n~s', $kit, $table), 'the full table\'s block');

        $this->assertMatchesRegularExpression('~:root \.mk-row \.mk-row__main \{\s*grid-column: main-start / status-end;\s*grid-template-columns: minmax\(0, 1fr\) 9rem;\s*grid-template-rows: 1fr auto auto 1fr;\s*gap: 0 \.75rem;\s*\}~', $table[1], 'the title cell spans the title and Visibility columns (the same tracks and gap)');
        $this->assertMatchesRegularExpression('~:root \.mk-row \.mk-row__main > \.mk-row__meta--status:not\(\.mk-row__meta--plain\) \{\s*grid-area: 1 / 2 / -1 / 3;\s*align-self: center;\s*display: grid;~', $table[1]);
        $this->assertStringContainsString(':root .mk-row .mk-row__main > .mk-row__meta--status > :first-child { display: contents; }', $table[1], 'the chip is the grid item, as in the cell');
        $this->assertStringContainsString(':root .mk-row .mk-row__main > .mk-row__meta--status:not(.mk-row__meta--plain) > * + *::before { content: none; }', $table[1], 'no dots in the column');
        $this->assertStringNotContainsString('.mk-row__meta--status { display: none; }', $kit, 'no longer hidden in the table');
        $this->assertStringNotContainsString('.mk-row__cell--status', $kit);

        // touch screens: above the title's row-wide link, like the cells
        $this->assertMatchesRegularExpression('~@container mklist \(min-width: 77\.5rem\) \{\s*:root \.mk-row \.mk-row__main > \.mk-row__meta--status \{\s*position: relative;\s*z-index: 1;~', $kit);
    }
}
