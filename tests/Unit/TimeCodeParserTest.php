<?php

namespace Tests\Unit;

use App\Parsers\TimeCodeParser;
use Tests\TestCase;

/**
 * watch-22: only real time codes become seek buttons, never inside a link, and the watch page turns a code it cannot
 * play (past the video's length, or under a player it cannot seek) back into text.
 */
class TimeCodeParserTest extends TestCase
{
    private function parse(string $text): string
    {
        return (new TimeCodeParser())->parse($text);
    }

    public function test_real_time_codes_become_buttons_with_their_seconds(): void
    {
        $html = $this->parse('at 1:23 and 1:02:03');

        $this->assertStringContainsString("onclick='time(83)'", $html);
        $this->assertStringContainsString("data-timecode='3723'", $html);
        $this->assertStringContainsString("type='button'", $html);
        $this->assertStringContainsString('avd-timecode', $html);
        $this->assertStringContainsString("aria-label='Play from 1:23'", $html);
    }

    public function test_numbers_that_are_not_time_codes_stay_text(): void
    {
        foreach (['10:30:45:12', '1:234', '1:75', '1:61:00', 'ratio 16:9', '3:4'] as $text) {
            $this->assertStringNotContainsString('<button', $this->parse($text), $text);
        }
    }

    public function test_a_time_code_inside_a_link_is_left_alone(): void
    {
        $link = "<a href='https://example.com/watch?t=1:23' target='_blank'>https://example.com/watch?t=1:23</a>";

        $this->assertSame($link.' then <button', substr($this->parse($link.' then 0:42'), 0, strlen($link) + 13));
        $this->assertSame(1, substr_count($this->parse($link.' then 0:42'), '<button'));
    }

    public function test_the_watch_page_keeps_only_the_codes_it_can_play(): void
    {
        $html = $this->parse('intro 0:10, end 2:30, way past 22:16:01');

        $playable = TimeCodeParser::playable($html, 120, true);
        $this->assertStringContainsString("data-timecode='10'", $playable);
        $this->assertStringContainsString('<span class="avd-timecode-text">2:30</span>', $playable);
        $this->assertStringContainsString('<span class="avd-timecode-text">22:16:01</span>', $playable);

        // A player the page cannot seek (an Upload18 frame): every code is text.
        $this->assertStringNotContainsString('<button', TimeCodeParser::playable($html, 120, false));
        // Unknown length: every code stays a button.
        $this->assertSame(3, substr_count(TimeCodeParser::playable($html, 0, true), '<button'));
        $this->assertSame('', TimeCodeParser::playable(null, 10, true));
    }
}
