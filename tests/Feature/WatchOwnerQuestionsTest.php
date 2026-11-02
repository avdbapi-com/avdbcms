<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.2.3 round 3, lane WA's owner questions inside the guarded player block of resources/views/videos/show.blade.php,
 * approved by the owner on 30 Oct 2026 (each test belongs to one approved change; inside the block: words and attributes
 * only, no markup structure, id, class, script or behaviour change; OQ-2 and OQ-3 are outside it, in the watch tools'
 * script and the video page's styles):
 *  - OQ-4: the player block's last English words in plain, translated words (the mini player's two buttons, the Tools
 *    menu's Upload18 notice, the hidden HUDs' first texts);
 *  - OQ-5: the x of "Continue where you left off?" is named "Dismiss" for screen readers (its icon named it before);
 *  - OQ-6: the Tools menu's brightness chips keep the sign before the number in right-to-left languages (as R3-049 did
 *    for the phone sheet's chips);
 *  - R3-048b: the Night chip's tooltip joins the filter's name and value through the language's pattern;
 *  - OQ-1: the decorative icons inside the block's buttons are hidden from screen readers, each button keeps a name;
 *  - OQ-2: the sticky "Back to the video" bar is moved next to the site header once (resources/js/layout/watchTools.ts);
 *  - OQ-3: the sleep timer's message fits on phones and in the mini player (resources/sass/video.scss).
 */
class WatchOwnerQuestionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);

        parent::tearDown();
    }

    private function page(string $locale = 'en'): string
    {
        $this->withoutVite();
        $video = Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()
            ->create(['title' => 'Owner questions video', 'duration' => 125, 'show_likes' => true, 'views' => 0]);
        $path = (string) parse_url($video->route, PHP_URL_PATH);
        config(['site.default_locale' => 'en']);
        if ($locale !== 'en') {
            $_SERVER['DETECTED_LOCALE'] = $locale;
        }
        try {
            return $this->get($path)->assertOk()->getContent();
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
            app()->setLocale('en');
        }
    }

    public function test_oq4_the_player_blocks_last_english_words_are_plain_and_translated(): void
    {
        foreach (['en', 'fr'] as $locale) {
            $html = $this->page($locale);
            $this->assertStringContainsString('<span id="avdSpeedHudText">1x</span>', $html);
            $this->assertStringContainsString('<span id="avdAspectHudText">16:9</span>', $html);
            $this->assertStringContainsString('title="' . e(__('Expand to the full player', [], $locale)) . '"', $html);
            $this->assertStringContainsString('title="' . e(__('Close the mini player', [], $locale)) . '"', $html);
            $this->assertStringContainsString(e(__('This video plays in the Upload18 player: change its speed, volume and quality inside the player.', [], $locale)), $html);
            foreach (['1.0x Speed', 'Fit (16:9)', 'Expand to Full Player', 'Close Mini Player', 'Upload18 Stream Active'] as $old) {
                $this->assertStringNotContainsString($old, $html, $old);
            }
        }
    }

    public function test_oq5_the_resume_prompts_dismiss_button_has_a_name(): void
    {
        $html = $this->page('fr');
        $this->assertStringContainsString('onclick="window.avdDismissResume()" title="' . e(__('Dismiss', [], 'fr')) . '" aria-label="' . e(__('Dismiss', [], 'fr')) . '">', $html);
    }

    public function test_oq6_the_tools_menus_brightness_chips_read_left_to_right(): void
    {
        $html = $this->page('ar');
        $this->assertSame(1, substr_count($html, 'avd-bright-chip px-2 py-0.5 flex-fill text-center" style="font-size: 11px; border-radius: 8px;" dir="ltr" onclick="window.avdSetBrightness(\'boost\', this)">+15%</button>'));
        $this->assertSame(1, substr_count($html, 'avd-bright-chip px-2 py-0.5 flex-fill text-center" style="font-size: 11px; border-radius: 8px;" dir="ltr" onclick="window.avdSetBrightness(\'dim\', this)">-15%</button>'));
    }

    public function test_r3_048b_the_night_chips_tooltip_uses_the_languages_pattern(): void
    {
        foreach (['en' => ':', 'fr' => "\u{00A0}:", 'zh' => '：'] as $locale => $colon) {
            $html = $this->page($locale);
            $title = __('Color filter', [], $locale) . $colon . ($locale === 'zh' ? '' : ' ') . __('Night', [], $locale) . ' (Shift+N)';
            $this->assertStringContainsString('onclick="window.avdSetVideoFilter(\'night\', this)" title="' . e($title) . '">', $html, $locale);
        }
    }

    /** The guarded player block, as the hands-off guard reads it: from the <div id="avdPlayerWrapper"> line to the line that closes it. */
    private function playerBlock(): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", (string) file_get_contents(resource_path('views/videos/show.blade.php'))));
        $start = null;
        foreach ($lines as $n => $line) {
            if (str_contains($line, 'id="avdPlayerWrapper"') && str_contains($line, '<div')) {
                $start = $n;
                break;
            }
        }
        $this->assertNotNull($start, 'the player block');
        $depth = 0;
        for ($n = $start, $count = count($lines); $n < $count; $n++) {
            $depth += preg_match_all('/<div\b/', $lines[$n]) - preg_match_all('/<\/div>/', $lines[$n]);
            if ($depth <= 0 && $n > $start) {
                return implode("\n", array_slice($lines, $start, $n - $start + 1));
            }
        }
        $this->fail('the player block has no end');
    }

    public function test_oq1_the_player_blocks_button_icons_are_hidden_and_every_button_keeps_a_name(): void
    {
        $this->assertGreaterThanOrEqual(50, preg_match_all('/<button\b([^>]*)>(.*?)<\/button>/s', $this->playerBlock(), $buttons, PREG_SET_ORDER));
        $icons = 0;
        foreach ($buttons as [$button, $attributes, $inner]) {
            foreach (preg_match_all('/<i\b[^>]*>/', $inner, $found) ? $found[0] : [] as $icon) {
                $icons++;
                $this->assertStringContainsString(' aria-hidden="true"', $icon, $button);
            }
            // a button with icons only is named by its aria-label or title (its icon no longer names it)
            if (trim(strip_tags($inner)) === '') {
                $this->assertMatchesRegularExpression('/\s(aria-label|title)="[^"]+"/', $attributes, $button);
            }
        }
        $this->assertGreaterThanOrEqual(34, $icons);
    }

    public function test_oq2_the_back_to_the_video_bar_is_moved_next_to_the_site_header(): void
    {
        $script = str_replace("\r\n", "\n", (string) file_get_contents(resource_path('js/layout/watchTools.ts')));
        $find = "var avdSiteHeader = avdStickyBar && document.querySelector('.header');";
        $move = 'if (avdSiteHeader && avdSiteHeader.parentNode) avdSiteHeader.parentNode.insertBefore(avdStickyBar, avdSiteHeader.nextSibling);';
        $this->assertSame(1, substr_count($script, $find));
        $this->assertSame(1, substr_count($script, $move));
        // once, right after the bar is found and before its scroll watcher reads the player
        $this->assertLessThan(strpos($script, $find), strpos($script, "var avdStickyBar = document.getElementById('avdStickyWatchBar');"));
        $this->assertLessThan(strpos($script, 'var avdStickyPlayerWrapper'), strpos($script, $move));
        // the watch page has the bar and one site header to put it after
        $html = $this->page();
        $this->assertSame(1, substr_count($html, 'id="avdStickyWatchBar"'));
        $this->assertSame(1, preg_match_all('/\sclass="(?:[^"]*\s)?header(?:\s[^"]*)?"/', $html), 'one element with the class header (querySelector takes the first)');
    }

    public function test_oq3_the_sleep_timers_message_fits_on_phones_and_in_the_mini_player(): void
    {
        $css = str_replace("\r\n", "\n", (string) file_get_contents(resource_path('sass/video.scss')));
        foreach ([
            ":root #avdSleepFinishedOverlay {\n    justify-content: flex-start !important;\n    overflow-y: auto;\n    overscroll-behavior: contain;\n}",
            ":root #avdSleepFinishedOverlay > :first-child {\n    margin-block-start: auto;\n}",
            ":root #avdSleepFinishedOverlay > :last-child {\n    flex-shrink: 0;\n    margin-block-end: auto;\n}",
            "@media (max-width: 575.98px) {\n    :root #avdSleepFinishedOverlay {\n        padding: 0.75rem !important;\n    }\n\n    :root #avdSleepFinishedOverlay > :first-child {\n        display: none;\n    }",
            ":root .avd-pip-active #avdSleepFinishedOverlay > :first-child {\n    display: none;\n}",
        ] as $rule) {
            $this->assertStringContainsString($rule, $css);
        }
        // the overlay's markup is unchanged: the moon is its first child (hidden there), then the heading, the sentence and Resume
        $html = $this->page();
        $this->assertSame(1, preg_match('/<div id="avdSleepFinishedOverlay"[^>]*>\s*<div class="mb-3"[^>]*>🌙<\/div>\s*<h3 [^>]*>[^<]+<\/h3>\s*<p [^>]*>[^<]+<\/p>\s*<button type="button" [^>]*onclick="window\.avdResumeFromSleep\(\)">/u', $html));
    }
}
