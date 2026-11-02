<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.2.3, round 3 pre-fix pass (R2R-01, owner approved 2026-10-28: "Remaining tools-menu names ... OK to change them to
 * plain words? ok"): the rest of the player tools' words. The dock buttons' tooltips (their titles; the accessibility
 * tree reads them as each button's description, because the icon inside an icon-only button gives it its name: the
 * lane's owner question), the sticky bar's, the Tools menu's built-in player tools, the sleep timer's overlay and
 * the texts the tools' script (resources/js/layout/watchTools.ts) writes name the tools as the phone tools sheet, the
 * Tools menu and the keyboard shortcuts panel do, keyboard hints kept. Words only: the buttons, their ids, classes,
 * handlers and order are the same. The browser proof (hover, keyboard focus, the accessibility tree, every tool used in
 * Chromium and WebKit, English and Arabic, 390 and 1366 px) is the lane's.
 */
class WatchToolWordsRoundThreeTest extends TestCase
{
    use RefreshDatabase;

    /** The old words, none of which the watch page or the tools' script may show any more. */
    private const OLD = [
        'Theater Cinema Mode (T)', 'Lights Out Ambient Spotlight (L)', 'True Fullscreen (F)', 'Mini Player Floating PiP (P)',
        'Ambient Backlight Halo (A)', 'Cinema Aspect: 16:9 / 21:9 / Fill / Zoom (W)',
        'Cinema Color Grading: Vivid / 35mm / Cyberpunk / OLED (C)', 'Cinema Sleep Timer (Z)', 'Reload Player Stream',
        'Mirror Horizontal Flip (M)', 'Zen Focus Immersion (Shift+F)', 'Share Video Link', 'Keyboard Shortcuts (?)',
        'More Cinema Tools &amp; Controls', 'More Cinema Tools & Controls', 'Zen Mode (Shift+F)', 'Lights Out (L)', 'Reload Stream',
        'Your scheduled sleep timer reached zero.', 'Resume Playback', 'Back to Video', 'Capture Snapshot', 'Infinite Loop',
        'Audio EQ Boost', 'Spectrum Visualizer', 'Eye-Care Warm Blue Light Filter (Shift+N)',
        'Cinema Snapshot Saved', 'Snapshot is active for direct HTML5 video streams', 'Upload18 iframe: loop is controlled',
        'Standard Stereo', 'Cinema Bass Boost', 'Vocal Clarity', 'Night Listening', 'Audio equalizer is active for native video streams',
        'Audio Spectrum Visualizer', 'Mini Player Mode Active (Press P to Restore)', 'Restored to Standard Player',
        'Player stream reloaded!', 'Video stream reloaded!', 'Stream resumed!',
    ];

    /** The new words the tools' script reads from the page's settings (#avd-watch-tools). */
    private const SCRIPT_TEXTS = [
        'Snapshot saved', 'Snapshots work only with the built-in player', 'Loop the video', 'Looping works only with the built-in player',
        'Normal sound', 'Bass boost', 'Clear voices', 'Night listening', 'Sound modes work only with the built-in player', 'Sound bars',
        'Mini player', 'Video reloaded', 'Video resumed', 'Copy link',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // every dock button on (some follow the site's effects / tools settings)
        config([
            'site.effects_enabled' => true,
            'site.watch_player_ambient_glow_enabled' => true,
            'site.watch_floating_mini_player_enabled' => true,
            'site.watch_theater_button_enabled' => true,
            'site.watch_keyboard_shortcuts_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);

        parent::tearDown();
    }

    private function path(): string
    {
        $video = Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()
            ->create(['title' => 'Round three tool words', 'duration' => 125, 'views' => 0]);

        return (string) parse_url($video->route, PHP_URL_PATH);
    }

    /** A page in French: public/index.php takes "/fr" off the address and hands it to the Language middleware. */
    private function french(string $path): string
    {
        config(['site.default_locale' => 'en']);
        $_SERVER['DETECTED_LOCALE'] = 'fr';
        try {
            return $this->get($path)->assertOk()->getContent();
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
            app()->setLocale('en');
        }
    }

    /** The guarded player block as the page prints it, from its wrapper to the bookmark shelf after the dock. */
    private function playerBlock(string $html): string
    {
        $start = strpos($html, 'id="avdPlayerWrapper"');
        $end = strpos($html, 'id="avdBookmarkShelf"');
        $this->assertNotFalse($start, 'the player block');
        $this->assertNotFalse($end, 'the bookmark shelf');

        return substr($html, (int) $start, (int) $end - (int) $start);
    }

    private function settings(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="avd-watch-tools">(.*?)</script>#s', $html, $json));

        return json_decode($json[1], true, flags: JSON_THROW_ON_ERROR);
    }

    /** The tools' script without its comment lines (its comments still say what each part was called). */
    private function script(): string
    {
        $source = (string) file_get_contents(resource_path('js/layout/watchTools.ts'));

        return (string) preg_replace('#^\s*//.*$#m', '', str_replace("\r\n", "\n", $source));
    }

    /**
     * Each button exactly as before, with its new tooltip: the same attributes in the same order (id, handler, state),
     * only the words of its title changed. $t: the translation of a key in the page's language.
     */
    private function buttons(callable $t, string $locale = 'en'): array
    {
        return [
            'data-theater-toggle aria-pressed="false" onclick="window.AvdAudio && window.AvdAudio.playWhoosh()" title="'.$t('Theater mode').' (T)"',
            'id="avdLightsOutBtn" onclick="window.avdToggleLightsOut(); window.AvdAudio && window.AvdAudio.playWhoosh();" title="'.$t('Lights out').' (L)"',
            'id="avdFullscreenBtn" onclick="window.avdToggleFullscreen()" title="'.$t('Full screen').' (F)"',
            'id="avdPipToggle" onclick="window.avdTogglePip()" title="'.$t('Mini player').' (P)"',
            'id="avdAmbilightToggle" aria-pressed="true" onclick="window.avdToggleAmbilight()" title="'.$t('Glow around the player').' (A)"',
            'id="avdAspectBtn" onclick="window.avdCycleAspectRatio()" title="'.$t('Aspect ratio').' (W)"',
            'id="avdFilterBtn" onclick="window.avdCycleVideoFilter()" title="'.$t('Color filter').' (C)"',
            'id="avdSleepTimerBtn" onclick="window.avdToggleSleepTimerModal()" title="'.$t('Sleep timer').' (Z)"',
            '<button type="button" class="avd-dock-btn" onclick="window.avdReloadPlayer()" title="'.$t('Reload the video').'">',
            'id="avdMirrorBtn" onclick="window.avdToggleMirror()" title="'.$t('Mirror the video').' (M)"',
            'id="avdZenBtn" onclick="window.avdToggleZenMode()" title="'.$t('Focus mode').' (Shift+F)"',
            '<button type="button" class="avd-dock-btn" onclick="window.avdQuickShare()" title="'.$t('Copy video link').'">',
            "window.AvdAudio && window.AvdAudio.playClick(); }\" title=\"".$t('Keyboard shortcuts').' (?)"',
            'data-bs-display="static" aria-expanded="false" title="'.$t('Video tools').'"',
            // the sticky bar shown once the page is scrolled past the player
            'avd-lights-out-btn" onclick="window.avdToggleLightsOut()" title="'.$t('Lights out').' (L)"',
            'rounded-pill px-2 py-1" onclick="window.avdToggleZenMode()" title="'.$t('Focus mode').' (Shift+F)"',
            'rounded-pill px-2 py-1" onclick="window.avdReloadPlayer()" title="'.$t('Reload the video').'"',
            // the Tools menu's Night chip: its name and value in the language's pattern (R3-048b, owner-approved 30 Oct)
            "onclick=\"window.avdSetVideoFilter('night', this)\" title=\"".e(__(':label: :value', ['label' => __('Color filter', [], $locale), 'value' => __('Night', [], $locale)], $locale)).' (Shift+N)"',
        ];
    }

    public function test_the_dock_and_the_sticky_bar_name_the_tools_in_plain_words(): void
    {
        $this->withoutVite();
        $html = $this->get($this->path())->assertOk()->getContent();
        $block = $this->playerBlock($html);

        foreach ($this->buttons(fn (string $key): string => $key) as $button) {
            $this->assertStringContainsString($button, $block, $button);
        }
        // no aria-label was added (words only, markup unchanged): the title is each button's tooltip and, in the
        // accessibility tree, its description
        $this->assertSame(0, preg_match_all('/<button[^>]*class="avd-dock-btn[^"]*"[^>]*aria-label=/', $block));

        // the sticky bar's button, the Tools menu's four built-in player tools, the sleep timer's overlay (an icon may be
        // hidden from screen readers: this lane's owner proposal)
        $this->assertMatchesRegularExpression('#<i class="fa-solid fa-arrow-up me-1"(?: aria-hidden="true")?></i> Back to the video\s*</button>#', $block);
        $this->assertStringContainsString('<span class="flex-grow-1">Take a snapshot</span>', $block);
        $this->assertStringContainsString('<span class="flex-grow-1">Loop the video</span>', $block);
        $this->assertStringContainsString('<span class="flex-grow-1" id="avdMenuAudioLabel">Sound mode</span>', $block);
        $this->assertStringContainsString('<span class="flex-grow-1">Sound bars</span>', $block);
        $this->assertMatchesRegularExpression('#<h3 class="h4 fw-bold text-white mb-2">Paused by the sleep timer</h3>\s*<p class="text-white-50 small mb-4" style="max-width: 420px; line-height: 1.5;">\s*The time you set is up, so the video was paused\.\s*</p>#', $block);
        $this->assertMatchesRegularExpression('#onclick="window\.avdResumeFromSleep\(\)">\s*<i class="fa-solid fa-play"(?: aria-hidden="true")?></i>\s*<span>Resume</span>#', $block);

        // the tools themselves are unchanged
        foreach (['onclick="window.avdCaptureFrame()"', 'onclick="window.avdToggleLoop()"', 'onclick="window.avdCycleAudioBoost()"', 'onclick="window.avdToggleVisualizer()"', 'id="avdMenuSnapshotItem"', 'id="avdMenuLoopItem"', 'id="avdMenuAudioItem"', 'id="avdMenuVisualizerItem"', 'id="avdSleepFinishedOverlay"', 'id="avdStickyWatchBar"', 'id="avdCinemaDock"'] as $tool) {
            $this->assertStringContainsString($tool, $block, $tool);
        }

        // none of the old words on the page (its HTML comments are not shown), nor in the script's settings
        $shown = (string) preg_replace('/<!--.*?-->/s', '', $html);
        foreach (self::OLD as $old) {
            $this->assertStringNotContainsString($old, $shown, $old);
        }
    }

    public function test_the_words_are_in_the_readers_language(): void
    {
        $this->withoutVite();
        $french = $this->french($this->path());
        $block = $this->playerBlock($french);
        $fr = fn (string $key): string => e(__($key, [], 'fr'));

        foreach ($this->buttons($fr, 'fr') as $button) {
            $this->assertStringContainsString($button, $block, $button);
        }
        // translated words, not the English ones (keys French already has)
        $this->assertStringNotContainsString('title="Sleep timer (Z)"', $block);
        $this->assertStringNotContainsString('title="Focus mode (Shift+F)"', $block);
        foreach (['Take a snapshot', 'Loop the video', 'Sound mode', 'Sound bars', 'The time you set is up, so the video was paused.', 'Resume', 'Back to the video'] as $text) {
            $this->assertStringContainsString($fr($text), $block, $text);
        }

        // the script's words come from the page's language as well
        $texts = $this->settings($french)['t'];
        foreach (self::SCRIPT_TEXTS as $key) {
            $this->assertSame(__($key, [], 'fr'), $texts[$key] ?? null, $key);
        }
    }

    public function test_the_tools_script_writes_the_same_words(): void
    {
        $script = $this->script();

        // the toasts and labels of the renamed tools name them as the dock and the menu do
        foreach ([
            "window.showCopyToast('📸 ' + __r('Snapshot saved') + ' [' + timeStr + ']');",
            "window.showCopyToast('ℹ️ ' + __r('Snapshots work only with the built-in player'));",
            "window.showCopyToast('ℹ️ ' + __r('Looping works only with the built-in player'));",
            "window.showCopyToast('🔁 ' + avdPair(__r('Loop the video'), vid.loop ? __r('On') : __r('Off')));",
            "{ id: 'standard', name: __r('Normal sound'), tag: 'Audio' },",
            "{ id: 'bass', name: __r('Bass boost') + ' 🔊', tag: 'Bass 🔊' },",
            "{ id: 'vocal', name: __r('Clear voices') + ' 🎙️', tag: 'Vocal 🎙️' },",
            "{ id: 'night', name: __r('Night listening') + ' 🌙', tag: 'Night 🌙' }",
            "window.showCopyToast('ℹ️ ' + __r('Sound modes work only with the built-in player'));",
            "window.showCopyToast('📊 ' + avdPair(__r('Sound bars'), __r('On')));",
            "window.showCopyToast('📊 ' + avdPair(__r('Sound bars'), __r('Off')));",
            "window.showCopyToast(avdPair(__r('Mini player'), isPip ? __r('On') + ' (P)' : __r('Off')));",
            "window.showCopyToast('▶️ ' + __r('Video resumed'));",
        ] as $now) {
            $this->assertStringContainsString($now, $script, $now);
        }
        $this->assertSame(2, substr_count($script, "window.showCopyToast('🔄 ' + __r('Video reloaded'));"));
        // the browser's own copy box (no clipboard, e.g. a site without https) in the reader's language (round 3,
        // R3-048: its words alone, no hard-coded colon after them)
        $this->assertSame(2, substr_count($script, "prompt(__r('Copy link'), url);"));
        $this->assertStringNotContainsString("prompt('Copy link:'", $script);
        foreach (self::OLD as $old) {
            $this->assertStringNotContainsString($old, $script, $old);
        }

        // the page prints exactly the texts the script reads (the other two watch tests check both directions)
        $this->withoutVite();
        $texts = $this->settings($this->get($this->path())->assertOk()->getContent())['t'];
        foreach (self::SCRIPT_TEXTS as $key) {
            $this->assertSame(__($key), $texts[$key] ?? null, $key);
        }
        foreach (self::OLD as $old) {
            foreach (array_keys($texts) as $key) {
                $this->assertStringNotContainsString($old, $key, $old);
            }
        }
    }
}
