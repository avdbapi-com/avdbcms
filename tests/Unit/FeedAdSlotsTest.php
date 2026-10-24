<?php

namespace Tests\Unit;

use App\Support\FeedAdSlots;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "Ad after every N" means N videos before each ad (1.2.2). The server side of the rule (App\Support\FeedAdSlots:
 * the <x-videos-area> skeleton's wide ad box, the channel page grids, the one-ad lists) and the browser side
 * (resources/js/components/Videos/feedSlots.ts, run with Node) answer the same cases of
 * tests/Fixtures/feed_ad_slots.json.
 */
class FeedAdSlotsTest extends TestCase
{
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/feed_ad_slots.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function wideCases(): array
    {
        $cases = [];

        foreach (self::fixture()['wide'] as $case) {
            $cases[$case['name']] = [$case['videos'], $case['columns'], $case['every'], $case['maxAds'], $case['after']];
        }

        return $cases;
    }

    #[DataProvider('wideCases')]
    public function test_wide_ad_rows_follow_the_shared_cases(int $videos, int $columns, int $every, int $maxAds, array $after): void
    {
        $this->assertSame($after, FeedAdSlots::wideAdAfter($videos, $columns, $every, $maxAds));
    }

    public function test_the_ad_position_setting_is_read_like_the_browser_reads_it(): void
    {
        $this->assertSame(8, FeedAdSlots::adEvery(8));
        $this->assertSame(8, FeedAdSlots::adEvery('8'));
        $this->assertSame(1, FeedAdSlots::adEvery(1));
        $this->assertSame(0, FeedAdSlots::adEvery(0));
        $this->assertSame(0, FeedAdSlots::adEvery(-3));
        $this->assertSame(0, FeedAdSlots::adEvery(null));
        $this->assertSame(0, FeedAdSlots::adEvery('abc'));

        $this->assertSame(1, FeedAdSlots::maxAds(1));
        $this->assertSame(0, FeedAdSlots::maxAds(0));
        $this->assertSame(0, FeedAdSlots::maxAds('0'));
        $this->assertSame(3, FeedAdSlots::maxAds('3'));
        $this->assertSame(1, FeedAdSlots::maxAds(-2));
        $this->assertSame(1, FeedAdSlots::maxAds(null));
        $this->assertSame(1, FeedAdSlots::maxAds('many'));
    }

    public function test_a_grid_card_ad_takes_the_place_after_n_videos_only_with_room_and_a_video_to_follow(): void
    {
        // 3 x 3 channel grid, N = 8: 8 videos, then the ad in the 9th place.
        $this->assertSame(8, FeedAdSlots::gridAdAfter(9, 12, 8));
        $this->assertSame(8, FeedAdSlots::gridAdAfter(9, 9, 8));
        // Only 8 videos: nothing would follow the ad.
        $this->assertSame(0, FeedAdSlots::gridAdAfter(9, 8, 8));
        // 8 places, N = 8: no room after video 8 (1.2.1 kept a place free and never showed the ad).
        $this->assertSame(0, FeedAdSlots::gridAdAfter(8, 20, 8));
        // A small N in a big grid; N = 1.
        $this->assertSame(3, FeedAdSlots::gridAdAfter(6, 20, 3));
        $this->assertSame(1, FeedAdSlots::gridAdAfter(2, 2, 1));
        // Ads off.
        $this->assertSame(0, FeedAdSlots::gridAdAfter(9, 20, 0));
    }

    public function test_a_one_ad_list_shows_its_ad_after_n_items_and_only_between_two_items(): void
    {
        $this->assertTrue(FeedAdSlots::listAdAfter(8, 12, 8));
        $this->assertTrue(FeedAdSlots::listAdAfter(8, 9, 8));
        $this->assertFalse(FeedAdSlots::listAdAfter(8, 8, 8));
        $this->assertFalse(FeedAdSlots::listAdAfter(7, 12, 8));
        $this->assertFalse(FeedAdSlots::listAdAfter(9, 12, 8));
        $this->assertFalse(FeedAdSlots::listAdAfter(1, 5, 0));
        // A list Blade cannot count keeps the ad after item N.
        $this->assertTrue(FeedAdSlots::listAdAfter(8, null, 8));
    }

    public function test_the_browser_slot_math_passes_the_shared_cases(): void
    {
        $node = $this->nodeBinary();

        if ($node === null) {
            $this->markTestSkipped('Node.js 22.18 or newer is needed to run tests/js/feed_ad_slots.test.ts.');
        }

        $root = dirname(__DIR__, 2);
        $command = escapeshellarg($node).' '.escapeshellarg($root.'/tests/js/feed_ad_slots.test.ts').' 2>&1';
        $output = [];
        exec($command, $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertMatchesRegularExpression('/feed ad slots: \d+ passed, 0 failed/', implode("\n", $output));
    }

    private function nodeBinary(): ?string
    {
        if (! function_exists('exec')) {
            return null;
        }

        $finder = PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null';
        $paths = [];
        @exec($finder, $paths);
        $node = trim((string) ($paths[0] ?? ''));

        if ($node === '' || ! is_file($node)) {
            return null;
        }

        $version = [];
        @exec(escapeshellarg($node).' -p "process.versions.node" 2>&1', $version);

        // TypeScript runs directly from Node 22.18 (type stripping on by default).
        return preg_match('/^(\d+)\.(\d+)/', trim((string) ($version[0] ?? '')), $match) === 1
            && ((int) $match[1] > 22 || ((int) $match[1] === 22 && (int) $match[2] >= 18))
            ? $node
            : null;
    }
}
