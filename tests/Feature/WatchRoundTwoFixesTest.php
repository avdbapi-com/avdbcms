<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.2.3 round 2 fixes of the watch page, its tools and the Subscribe button (R2A-022, R2A-027, R2A-059, R1R-15, R1-147;
 * R2A-016 / R2A-028 / R2A-029 in their components): what the server prints and what the scripts are made of. The
 * interactive parts (focus, scrolling, clicks, the web font's arrival) are proven in a browser by the lane.
 */
class WatchRoundTwoFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);

        parent::tearDown();
    }

    private function video(array $attributes = []): Video
    {
        return Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()
            ->create($attributes + ['title' => 'Round two watch video', 'duration' => 125, 'show_likes' => true, 'views' => 0]);
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

    /** The whole <template data-avd-lazy="$id"> element (the dialogs drawn on first use), nested templates included. */
    private function lazyTemplate(string $html, string $id): string
    {
        $start = strpos($html, '<template data-avd-lazy="'.$id.'"');
        $this->assertNotFalse($start, 'the '.$id.' template');

        $depth = 0;
        $offset = $start;
        while (preg_match('/<template\b|<\/template>/', $html, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            [$tag, $at] = $match[0];
            $depth += $tag === '</template>' ? -1 : 1;
            $offset = $at + strlen($tag);
            if ($depth === 0) {
                return substr($html, $start, $offset - $start);
            }
        }

        $this->fail('the '.$id.' template is not closed');
    }

    /** The words of a piece of markup, one space between them. */
    private function words(string $html): string
    {
        return ' '.trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5))).' ';
    }

    /** The watch tools' settings printed as JSON (#avd-watch-tools). */
    private function toolSettings(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="avd-watch-tools">(.*?)</script>#s', $html, $json));

        return json_decode($json[1], true, flags: JSON_THROW_ON_ERROR);
    }

    /** The dock's Tools menu (its <ul>, nested lists included). */
    private function dockMenu(string $html): string
    {
        $start = strpos($html, 'aria-labelledby="avdMoreToolsBtn"');
        $this->assertNotFalse($start, 'the dock menu');
        $start = (int) strrpos(substr($html, 0, $start), '<ul');
        $depth = 0;
        $offset = $start;
        while (preg_match('/<ul\b|<\/ul>/', $html, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            [$tag, $at] = $match[0];
            $depth += $tag === '</ul>' ? -1 : 1;
            $offset = $at + strlen($tag);
            if ($depth === 0) {
                return substr($html, $start, $offset - $start);
            }
        }

        $this->fail('the dock menu is not closed');
    }

    private function source(string $relative): string
    {
        $text = file_get_contents(base_path($relative));
        $this->assertNotFalse($text, $relative);

        return str_replace("\r\n", "\n", (string) $text);
    }

    public function test_the_share_dialogs_embed_part_keeps_the_focus_and_its_back_arrow_has_a_name(): void
    {
        $this->withoutVite();
        $path = parse_url($this->video()->route, PHP_URL_PATH);
        $html = $this->get($path)->assertOk()->getContent();
        $share = $this->lazyTemplate($html, 'share');

        // R2A-022: the Embed icon hid the part that held the focus (the focus fell to the page, where Escape no longer
        // closed the dialog) and the back arrow had no name
        $this->assertMatchesRegularExpression('/<button type="button" class="[^"]*avd-share-embed"[^>]*@click="openEmbed\(\)" aria-label="Embed"/', $share);
        $this->assertMatchesRegularExpression('/<button type="button" class="bg-transparent avd-share-back" @click="closeEmbed\(\)" aria-label="Back" title="Back">\s*<i class="fa-solid fa-arrow-left" aria-hidden="true"><\/i>/', $share);
        $this->assertStringNotContainsString('@click="embed = true"', $share);
        $this->assertStringNotContainsString('@click="embed = false"', $share);
        // the dialog is named by the part it shows
        $this->assertStringContainsString('aria-labelledby="share-title" :aria-labelledby="embed ? \'share-embed-title\' : \'share-title\'"', $share);
        $this->assertStringContainsString('<h5 class="modal-title" id="share-title">Share video</h5>', $share);
        $this->assertStringContainsString('<h5 class="modal-title" id="share-embed-title">Embed</h5>', $share);

        // the part that opens takes the focus (as soon as it is drawn), and "Back" gives it to the Embed icon again
        $this->assertStringContainsString('openEmbed() {', $html);
        $this->assertStringContainsString("this.focusShown(dialog?.querySelector('.avd-share-back'));", $html);
        $this->assertStringContainsString('closeEmbed() {', $html);
        $this->assertStringContainsString("this.focusShown(dialog?.querySelector('.avd-share-embed'));", $html);
        $this->assertStringContainsString('focusShown(target) {', $html);

        // the back arrow is named in the reader's language
        $french = $this->lazyTemplate($this->french($path), 'share');
        $this->assertStringContainsString('aria-label="'.e(__('Back', [], 'fr')).'" title="'.e(__('Back', [], 'fr')).'"', $french);
    }

    public function test_the_sleep_timer_notes_and_details_dialogs_speak_plainly(): void
    {
        $this->withoutVite();
        $path = parse_url($this->video()->route, PHP_URL_PATH);
        $html = $this->get($path)->assertOk()->getContent();

        // R2A-059: "Cinema Sleep Timer", "Off (Disabled)", "15 Minutes", "End of Video"
        $sleep = $this->lazyTemplate($html, 'avdSleepTimerModal');
        $words = $this->words($sleep);
        foreach (['Sleep timer', 'The video pauses when the time is up.', 'Off', '15 minutes', '30 minutes', '45 minutes', 'At the end of the video'] as $text) {
            $this->assertStringContainsString(' '.$text.' ', $words, $text);
        }
        foreach (['Cinema', 'Disabled', 'Minutes', 'End of Video', 'Automatically fade out'] as $old) {
            $this->assertStringNotContainsString($old, $words, $old);
        }
        $this->assertStringNotContainsString('<i class="fa-solid fa-moon text-info"></i>', $sleep, 'the icons are not read out');

        // "Video Study Notes & Scratchpad", "Autosaved locally", "Insert Timestamp", "Copy All", "Export .txt"
        $notes = $this->lazyTemplate($html, 'avdNotesModal');
        $words = $this->words($notes);
        foreach (['Notes', 'Saved in this browser for this video', 'Add the current time', 'Copy all', 'Download'] as $text) {
            $this->assertStringContainsString(' '.$text.' ', $words, $text);
        }
        foreach (['Scratchpad', 'Autosaved', 'Insert Timestamp', 'Copy All', 'Export'] as $old) {
            $this->assertStringNotContainsString($old, $words, $old);
        }
        $this->assertStringContainsString('placeholder="Write your notes here…" aria-label="Notes"', $notes);

        // the video details dialog: the tools' names, the mirror's state in words
        $details = $this->lazyTemplate($html, 'avdTelemetryModal');
        $words = $this->words($details);
        foreach (['Color filter:', 'Aspect ratio: 16:9 ', 'Mirror the video: Off', 'Glow around the player:'] as $text) {
            $this->assertStringContainsString(' '.$text, $words, $text);
        }
        foreach (['Cinema Color Grading', 'Mirror Flip View', 'Player Ambient Glow', 'Aspect Ratio', 'Mirrored', 'Standard'] as $old) {
            $this->assertStringNotContainsString($old, $words, $old);
        }

        // the sleep timer's overlay (owner approved 2026-10-28, its heading only): the dialog's words
        $this->assertStringContainsString('<h3 class="h4 fw-bold text-white mb-2">Paused by the sleep timer</h3>', $html);
        $this->assertStringNotContainsString('Stream Paused by Cinema Sleep Timer', $html);

        // the timer's toast says the length as the dialog does
        $settings = $this->toolSettings($html);
        $this->assertSame(['15' => '15 minutes', '30' => '30 minutes', '45' => '45 minutes'], array_map('strval', $settings['sleepLengths']));

        // in the reader's language (the same words with or without their translation)
        $french = $this->french($path);
        $this->assertStringContainsString(e(__('Sleep timer', [], 'fr')), $this->lazyTemplate($french, 'avdSleepTimerModal'));
        $this->assertStringContainsString(e(trans_choice('1 minute|:count minutes', 15, ['count' => '15'], 'fr')), $this->lazyTemplate($french, 'avdSleepTimerModal'));
        $this->assertStringContainsString(e(__('Paused by the sleep timer', [], 'fr')), $french);
        $this->assertSame(trans_choice('1 minute|:count minutes', 30, ['count' => '30'], 'fr'), $this->toolSettings($french)['sleepLengths']['30']);
    }

    public function test_the_dock_uses_the_phone_sheets_words_without_english_badges(): void
    {
        $this->withoutVite();
        $html = $this->get(parse_url($this->video()->route, PHP_URL_PATH))->assertOk()->getContent();
        $menu = $this->dockMenu($html);
        $words = $this->words($menu);

        // R1R-15 (owner approved 2026-10-28, lane FW's patches): the dock's tools in the phone sheet's words
        foreach (['Video tools', 'Color filter', 'Normal', 'Vivid', 'Warm film', 'Cyberpunk', 'Deep black', 'Black and white', 'Night', 'Anime', 'Rotate the video', 'Brightness', 'Glow around the player', 'Theme', 'Pulse', 'Neon', 'Warm', 'Mirror the video', 'Focus mode', 'Full screen', 'Embed code', 'Notes', 'More options'] as $label) {
            $this->assertStringContainsString(' '.$label.' ', $words, $label);
        }
        $old = ['Vivid HDR', '35mm Warm', 'OLED Dark', 'Noir B&W', 'Night Shift', 'Anime Pop', 'Rotate Video Frame', 'Screen Brightness', 'Ambilight Mode', 'Theme Sync', 'Neon Aura', 'Warm Hearth', 'Zen Focus Immersion', 'True Fullscreen', 'Embed Video Player', 'Quick-Notes Scratchpad', 'HTML5 Stream Controls'];
        // shown words only (the markup's HTML comments are not shown; the keyboard shortcuts panel of the layout, lane
        // CC's, keeps its own words)
        $shown = (string) preg_replace('/<!--.*?-->/s', '', $menu);
        foreach ([...$old, 'Mirror Flip View'] as $name) {
            $this->assertStringNotContainsString($name, $shown, $name);
        }
        // no English state badges (the script kept writing "Normal", "0°", "Theme" into them in every language)
        foreach (['avdPlayerTypeBadge', 'avdCurrentFilterBadge', 'avdCurrentRotationBadge', 'avdCurrentBrightBadge', 'avdCurrentAmbiBadge'] as $badge) {
            $this->assertStringNotContainsString('id="'.$badge.'"', $html, $badge);
        }
        $this->assertStringNotContainsString('&lt;/&gt;', $menu);
        // the tools themselves are unchanged
        foreach (["avdSetVideoFilter('noir', this)", "avdRotateVideo(90, this)", "avdSetBrightness('boost', this)", "avdSetAmbiMode('neon', this)", 'avdToggleMirror()', 'avdToggleZenMode()', 'avdToggleFullscreen()', 'avdOpenEmbedModal()', 'avdToggleNotes()', 'id="avdHtml5OnlyTools"'] as $tool) {
            $this->assertStringContainsString($tool, $menu, $tool);
        }
    }

    public function test_the_phone_tools_sheets_chips_never_split_a_word(): void
    {
        $this->withoutVite();
        $html = $this->get(parse_url($this->video()->route, PHP_URL_PATH))->assertOk()->getContent();
        $sheet = $this->lazyTemplate($html, 'avdMobileToolsSheet');

        // RV-CW-04: every chip of the sheet allowed a break anywhere in its word and the columns had no minimum width,
        // so a word longer than its chip was split (Russian "Нормальный": "Нормальн" / "ый" at 360-390 px). A chip
        // breaks a word now only when the word is wider than the whole sheet: the filter grid's columns are at least
        // as wide as their longest word, and the rows of chips share their width equally only down to each chip's
        // longest word (a row that cannot hold them all goes on to a second line).
        $this->assertMatchesRegularExpression('/\.avd-mobile-sheet \.btn \{[^}]*overflow-wrap: break-word;[^}]*\}/', $html);
        $this->assertDoesNotMatchRegularExpression('/\.avd-mobile-sheet \.btn \{[^}]*overflow-wrap: anywhere;[^}]*\}/', $html);
        $this->assertMatchesRegularExpression('/\.avd-mobile-filter-grid \{\s*grid-template-columns: repeat\(3, minmax\(min-content, 1fr\)\) !important;\s*\}/', $html);
        $this->assertMatchesRegularExpression('/@media \(max-width: 360px\) \{\s*\.avd-mobile-filter-grid \{\s*grid-template-columns: repeat\(2, minmax\(min-content, 1fr\)\) !important;\s*\}/', $html);
        $this->assertStringNotContainsString('minmax(0, 1fr)) !important', $html);
        $this->assertMatchesRegularExpression('/\.avd-mobile-sheet \.row\.g-1 > \[class\*="col-"\] \{\s*flex: 1 1 0;\s*width: auto;\s*\}/', $html);

        // the rule reaches the brightness, rotation and glow rows (each a .row.g-1 of .col-* chips); the scripts find
        // the chips by their own classes, which stay
        foreach (['avd-mob-bright-chip' => 3, 'avd-mob-rotate-chip' => 4] as $chip => $count) {
            $this->assertSame($count, preg_match_all('/<div class="col-\d"><button type="button" class="[^"]*\b'.$chip.'\b/', $sheet), $chip);
        }
        // rotation and brightness, plus the glow row when the site shows the glow
        $this->assertSame(str_contains($sheet, 'avd-mob-ambi-chip') ? 3 : 2, substr_count($sheet, '<div class="row g-1">'));
        $this->assertSame(8, substr_count($sheet, 'avd-mob-filter-chip'));
    }

    public function test_the_tools_toasts_are_plain_text_in_the_tools_words(): void
    {
        $tools = $this->source('resources/js/layout/watchTools.ts');

        // core.ts's showCopyToast writes its message as text: an HTML-escaped translation showed its entities (French
        // "Code d'intégration copié" read "d&#039;intégration")
        preg_match_all('/^.*showCopyToast\(.*$/m', $tools, $toasts);
        $this->assertGreaterThan(35, count($toasts[0]));
        foreach ($toasts[0] as $line) {
            $this->assertStringNotContainsString('__t(', $line, trim($line));
            $this->assertStringNotContainsString('avdEsc(', $line, trim($line));
        }

        // the tools' toasts and the values they write name them as the dock, the phone sheet and the dialogs do
        // (1.2.3 round 3, R3-048: a name and its value joined by the language's own pattern, avdPair, and a speed in the
        // language's decimals, avdTimes)
        foreach (["avdPair(__r('Sleep timer'), __r('Off'))", "avdPair(__r('Sleep timer'), __r('At the end of the video'))", "avdPair(__r('Sleep timer'), avdSleepLength(mins))", "__r('Paused by the sleep timer')", "avdPair(__r('Color filter'), ", "avdPair(__r('Rotate the video'), ", "avdPair(__r('Brightness'), ", "avdPair(__r('Glow around the player'), ", "avdPair(__r('Mirror the video'), ", "avdPair(__r('Focus mode'), ", "avdPair(__r('Full screen'), __r('On'))", "telemMirror.textContent = isMirrored ? __r('On') : __r('Off');", "'theme': __r('Theme'), 'pulse': __r('Pulse'), 'neon': __r('Neon'), 'warm': __r('Warm')", "'vivid': __r('Vivid')", "dockBtn.title = avdPair(__r('Color filter')", "'📐 ' + avdPair(__r('Aspect ratio'), mode.hud)", "{ id: 'fill', label: __r('Fill'), hud: __r('Fill')", "label.textContent = m + ':' + (s < 10 ? '0' : '') + s;", "avdPair(__r('Glow around the player'), isActive ? __r('On') : __r('Off'))", "telemAmbi.textContent = isActive ? __r('Active') : __r('Disabled');", "txt.textContent = avdTimes(rate);"] as $now) {
            $this->assertStringContainsString($now, $tools, $now);
        }
        foreach (["'🔄 Frame Rotation: '", "'☀️ Brightness: '", 'Ambilight Glow: ', 'Mirrored (Flipped)', 'Cinema Sleep Timer Disabled', 'Sleep Timer Set:', 'Goodnight', 'Video Finished', 'Zen Immersion', 'Fullscreen Active', 'Fullscreen Exited', 'Mirror Flip: ', "'🎨 Filter: '", "'Vivid HDR'", "'Noir B&W'", "__r('Cinema Color Grading')", "-15%' : 'Normal'", "'Fit (16:9)'", 'Cinemascope (21:9)', 'Fill Frame (Stretch)', 'Cinema Zoom 1.25x', "m + 'm ' + s + 's'", 'Ambilight Ambient Glow ON', 'Ambilight Glow OFF', "isActive ? 'Active' : 'Disabled'", "'x Speed'"] as $old) {
            $this->assertStringNotContainsString($old, $tools, $old);
        }

        // the settings carry exactly the texts the module reads (WatchPageFrontFixesTest: every key read is there)
        $this->withoutVite();
        $settings = $this->toolSettings($this->get(parse_url($this->video()->route, PHP_URL_PATH))->assertOk()->getContent());
        $code = (string) preg_replace('#^\s*//.*$#m', '', $tools);
        preg_match_all("/__(?:t|r)\\('((?:[^'\\\\]|\\\\.)+)'\\)/u", $code, $keys);
        $this->assertSame([], array_values(array_diff(array_keys($settings['t']), array_unique($keys[1]))), 'no text the module never reads');
    }

    public function test_the_title_case_words_round_one_left_are_gone(): void
    {
        $this->withoutVite();
        config(['site.sidebar_explore_limit' => 1, 'site.header_random_button_enabled' => true]);
        Category::query()->create(['title' => 'Music', 'slug' => 'music', 'in_menu' => true]);
        Category::query()->create(['title' => 'Travel', 'slug' => 'travel', 'in_menu' => true]);
        $video = $this->video();
        $this->video(['title' => 'A related one']);

        $html = $this->get(parse_url($video->route, PHP_URL_PATH))->assertOk()->getContent();

        // R1-147: the side menu's "All Categories", the header's "Surprise Me / Random Video", the related list's
        // "Curated For You"; looked around: the Up next card ("Next Video", "Play Next"), the verified mark
        $this->assertStringContainsString('<span class="text-sm">All categories</span>', $html);
        $this->assertMatchesRegularExpression('/class="avd-icon-button" title="Random video" aria-label="Random video"/', $html);
        foreach (['All Categories', 'Surprise Me', 'Random Video', 'Curated For You', 'Next Video', 'Play Next', 'Verified Creator', 'Top Comments'] as $old) {
            $this->assertStringNotContainsString($old, $html, $old);
        }
        $this->assertStringContainsString("t('Top comments')", $this->source('resources/js/components/Comments/CommentsList.tsx'));
        $this->assertStringContainsString("__('Picked for you')", $this->source('resources/views/videos/show.blade.php'));
        foreach (['resources/views/videos/card.blade.php', 'resources/views/videos/card-secondary.blade.php', 'resources/views/videos/show.blade.php'] as $view) {
            $this->assertStringNotContainsString("__('Verified Creator')", $this->source($view), $view);
        }
    }

    public function test_the_subscribe_button_keeps_its_width_when_the_looks_web_font_arrives(): void
    {
        $this->withoutVite();
        $channel = User::factory()->create(['username' => 'width_channel']);
        $this->actingAs(User::factory()->create());

        $html = $this->get(parse_url($channel->route, PHP_URL_PATH))->assertOk()->getContent();

        // R2A-027: the invisible pair of faces gives the button its width in the look's local fallback face and the
        // local fonts after it (never the web font); the list is set in the page's head, before the first paint, once
        $head = substr($html, 0, (int) strpos($html, '</head>'));
        $this->assertSame(1, substr_count($html, "r.style.setProperty('--mk-sizer',s.slice(1).join(','))"));
        $this->assertStringContainsString("r.style.setProperty('--mk-sizer',s.slice(1).join(','))", $head);
        $this->assertStringContainsString("f(s[1])===f(s[0])+' Fallback'", $head);
        $this->assertStringContainsString('class="mk-subscribe__face mk-subscribe__face--off" aria-hidden="true"', $html);
        $this->assertStringContainsString('class="mk-subscribe__face mk-subscribe__face--on" aria-hidden="true"', $html);

        $css = $this->source('resources/sass/member/_shared.scss');
        $this->assertMatchesRegularExpression('/:root \.mk-subscribe__face\[aria-hidden\] > span \{\s*font-family: var\(--mk-sizer\) !important;\s*\}/', $css);
        $this->assertMatchesRegularExpression('/:root \.mk-subscribe__face:not\(\[aria-hidden\]\) \{\s*position: absolute;\s*inset: 0;\s*grid-area: auto;\s*\}/', $css);

        // the buttons drawn only by the component (the watch page) set the same list
        $component = $this->source('resources/js/components/Actions/Subscribe.tsx');
        $this->assertStringContainsString("root.style.setProperty('--mk-sizer', stack.slice(1).join(','));", $component);
        $this->assertStringContainsString('const sizer = <>{offFace(false)}{onFace(false)}</>;', $component);
    }

    public function test_the_bell_menu_loads_its_level_first_and_never_covers_the_header(): void
    {
        $bell = $this->source('resources/js/components/Notifications/ChannelBell.tsx');

        // R2A-016: the levels were disabled until the member's level arrived, so the first keyboard opening focused
        // "Unsubscribe..."; the level is asked for before the menu opens and the levels are never disabled
        $this->assertStringNotContainsString('disabled={level === null}', $bell);
        $this->assertStringContainsString('onPointerDown={loadNow}', $bell);
        $this->assertStringContainsString('onFocus={loadSoon}', $bell);
        $this->assertStringContainsString('setLevel((current) => current ?? (isLevel(answer?.level) ? answer.level : \'all\'));', $bell);

        // R2A-028: the menu (on the window's layer, above the sticky header) never opens upwards into the header and
        // closes when its button goes under the header
        $this->assertStringContainsString("const header = document.querySelector<HTMLElement>('[data-avd-header]');", $bell);
        $this->assertStringContainsString("{name: 'flip', options: {padding: {top: headerBottom, right: 0, bottom: 0, left: 0}}}", $bell);
        $this->assertStringContainsString(' data-avd-header>', $this->source('resources/views/layouts/menus/header.blade.php'));

        // A button the header covers when the menu opens (the keyboard reached it there) is first moved just below the
        // header, at once (not with the site's smooth scroll), so the menu never opens over the header
        $this->assertStringContainsString('openGap.current = onPage && toggle !== null ? revealBelowHeader(toggle) : 0;', $bell);
        $this->assertMatchesRegularExpression(
            "/style\\.setProperty\\('scroll-behavior', 'auto', 'important'\\);\\s+window\\.scrollBy\\(0, top - edge - REVEAL_ROOM\\);/",
            $bell
        );
        // the page's own inline value is put back as it was
        $this->assertStringContainsString("style.removeProperty('scroll-behavior');", $bell);
        $this->assertStringContainsString("style.setProperty('scroll-behavior', value, priority);", $bell);

        // Only a button that goes further under the header than at the opening closes the menu: the browser's own
        // smooth scroll that brings a focused button out from under the header keeps it open (no lost key press)
        $this->assertStringContainsString('const limit = openGap.current < 0 ? openGap.current - 1 : 0;', $bell);
        $this->assertStringContainsString('if (toggle.getBoundingClientRect().top - edge < limit) {', $bell);
        $this->assertStringNotContainsString('toggle.getBoundingClientRect().top < edge', $bell);
    }
}
