<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Support\SiteDate;
use App\Support\WatchHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 1.2.3 round 3 fixes of the watch page, the channel, Library, History and playlist pages (lane WA): what the server
 * prints and what the watch tools' script is made of. Each test fails on the code of int-C4 (RC2 + the pre-fix pass).
 * The keyboard and the pictures (the bell's menu, the Save dialog's focus, the theater look's centre) are proven in a
 * browser by the lane.
 *  - R3-048: a tool's name and its value are joined by the language's own pattern (":label: :value", French
 *    ":label : :value", Chinese ":label：:value"), never a hard-coded ': ', and a speed or zoom is written with the
 *    language's decimal mark ("1,25x");
 *  - R3X-03: the watch tools' own texts in plain, translated words; the snapshot stamped with its real size;
 *  - R3-049: the phone sheet's brightness chips (and the toast) keep their sign before the number in Arabic;
 *  - R3-062: the Library, History, playlist and channel dates in the reader's language order;
 *  - R3-067: the theater layout's column centred from 992px.
 */
class WatchAndLibraryRoundThreeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function video(array $attributes = []): Video
    {
        return Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()
            ->create($attributes + ['title' => 'Round three watch video', 'duration' => 1384, 'show_likes' => true, 'views' => 0]);
    }

    /** A page in another language: public/index.php takes "/xx" off the address and hands it to the Language middleware. */
    private function in(string $locale, string $path, ?User $member = null): string
    {
        config(['site.default_locale' => 'en']);
        $_SERVER['DETECTED_LOCALE'] = $locale;
        try {
            $request = $member ? $this->actingAs($member) : $this;

            return $request->get($path)->assertOk()->getContent();
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
            app()->setLocale('en');
        }
    }

    private function source(string $relative): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(base_path($relative)));
    }

    /** The tools' script without its comment lines. */
    private function script(): string
    {
        return (string) preg_replace('#^\s*//.*$#m', '', $this->source('resources/js/layout/watchTools.ts'));
    }

    private function settings(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="avd-watch-tools">(.*?)</script>#s', $html, $json));

        return json_decode($json[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function layoutTexts(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="avd-i18n">(.*?)</script>#s', $html, $json));

        return json_decode($json[1], true, flags: JSON_THROW_ON_ERROR)['t'];
    }

    /** "Label: value" in the current language's punctuation. */
    private function pair(string $label, string $value, string $locale): string
    {
        return __(':label: :value', ['label' => $label, 'value' => $value], $locale);
    }

    // ------------------------------------------------------------------------------------------- R3-048, R3X-03

    public function test_the_tools_script_joins_a_name_and_its_value_through_the_languages_pattern(): void
    {
        $script = $this->script();

        // no hard-coded colon between a name and its value (the toasts, the dock's tooltip, the copy box): the
        // pattern's translation joins them; times ("1:05") and the keyboard map are not joins
        $this->assertSame(0, preg_match_all("/\\+\\s*': '\\s*\\+|\\)\\s*\\+\\s*': '|\\+\\s*':'\\s*,/", $script, $joins), implode(' | ', $joins[0] ?? []));
        $this->assertStringContainsString("return __r(':label: :value').replace(/:label|:value/g, function (key) {", $script);
        foreach ([
            "'🌙 ' + avdPair(__r('Sleep timer'), avdSleepLength(mins))",
            "'📐 ' + avdPair(__r('Aspect ratio'), mode.hud)",
            "'🎨 ' + avdPair(__r('Color filter'), avdFilterNames[preset] || preset)",
            "dockBtn.title = avdPair(__r('Color filter'), avdFilterNames[preset] || preset) + ' (C)'",
            "'🔄 ' + avdPair(__r('Rotate the video'), deg + '°')",
            "'🪞 ' + avdPair(__r('Mirror the video'), isMirrored ? __r('On') : __r('Off'))",
            "'🔁 ' + avdPair(__r('Loop the video'), vid.loop ? __r('On') : __r('Off'))",
            "avdPair(__r('Mini player'), isPip ? __r('On') + ' (P)' : __r('Off'))",
            "'⛶ ' + avdPair(__r('Full screen'), __r('On'))",
            "'🧘 ' + avdPair(__r('Focus mode'), isZen ? __r('On') + ' (Shift+F, Esc)' : __r('Off'))",
            "'📊 ' + avdPair(__r('Sound bars'), __r('On'))",
            "'✨ ' + avdPair(__r('Glow around the player'), isActive ? __r('On') : __r('Off'))",
            "'⏩ ' + avdPair(__r('Playback speed'), avdTimes(rate))",
            "prompt(__r('Copy link'), url);",
        ] as $now) {
            $this->assertStringContainsString($now, $script, $now);
        }

        // the language's decimal mark: speeds and the zoom through Intl with Western digits, as the site's cards
        $this->assertStringContainsString("new Intl.NumberFormat(avdLang, {maximumFractionDigits: 2, numberingSystem: 'latn'}).format(value)", $script);
        $this->assertStringContainsString("{ id: 'zoom', label: avdTimes(1.25), hud: avdTimes(1.25), fit: 'cover', transform: 'scale(1.25)' }", $script);
        $this->assertStringContainsString('txt.textContent = avdTimes(rate);', $script);
        $this->assertStringNotContainsString("rate + 'x'", $script);
        $this->assertStringNotContainsString("'1.25x'", $script);

        // R3X-03: plain words where a tool needs the built-in player, sentences with the time where the language puts it
        foreach (["__r('Use the gear icon inside the player to change the speed')", "__r('Bookmarks work only with the built-in player')", "avdAt(__r('Bookmark saved at :time'), timeStr)", "avdAt(__r('Jumped to :time'), avdFormatTime(secs))", "avdAt(__r('Resumed playback from :time'), avdFormatTime(avdPendingResumeTime))"] as $now) {
            $this->assertStringContainsString($now, $script, $now);
        }
        foreach (['Playback Speed', 'Upload18 iframe', 'Bookmarks require direct HTML5 video stream', "__r('Bookmark saved at') + ' '", "__r('Jumped to') + ' '", "__r('Resumed playback from') + ' '", 'AVDBCMS 4K', 'Cinema Capture'] as $old) {
            $this->assertStringNotContainsString($old, $script, $old);
        }
        // the snapshot is stamped with its real size when it holds a frame of the video, with no size otherwise
        $this->assertStringContainsString("var stampSize = captured && vid.videoWidth && vid.videoHeight ? ' ' + vid.videoWidth + '×' + vid.videoHeight : '';", $script);
        $this->assertStringContainsString("ctx.fillText('AVDBCMS' + stampSize, pillX + 16, pillY + Math.round(pillH * 0.42));", $script);
    }

    public function test_the_watch_page_gives_the_script_the_pattern_and_the_new_words_in_the_readers_language(): void
    {
        $this->withoutVite();
        $path = (string) parse_url($this->video()->route, PHP_URL_PATH);

        $patterns = [];
        foreach (['en', 'fr', 'ar', 'zh'] as $locale) {
            $texts = $this->settings($locale === 'en' ? $this->get($path)->assertOk()->getContent() : $this->in($locale, $path))['t'];
            foreach ([':label: :value', 'Playback speed', 'Use the gear icon inside the player to change the speed', 'Bookmarks work only with the built-in player', 'Bookmark saved at :time', 'Jumped to :time', 'Resumed playback from :time'] as $key) {
                $this->assertSame(__($key, [], $locale), $texts[$key] ?? null, $locale . ': ' . $key);
            }
            foreach (['Playback Speed', 'Upload18 iframe: speed is adjusted inside player frame', 'Upload18 iframe: speed is adjusted via gear icon inside player', 'Bookmarks require direct HTML5 video stream', 'Bookmark saved at', 'Jumped to', 'Resumed playback from'] as $old) {
                $this->assertArrayNotHasKey($old, $texts, $old);
            }
            $patterns[$locale] = $texts[':label: :value'] ?? null;
        }

        // the pattern itself: French puts a no-break space before the colon, Chinese a full-width colon
        $this->assertSame(['en' => ':label: :value', 'fr' => ":label\u{00A0}: :value", 'ar' => ':label: :value', 'zh' => ':label：:value'], $patterns);
    }

    public function test_the_video_details_the_shortcuts_panel_and_the_lights_out_toasts_use_the_languages_pattern(): void
    {
        $this->withoutVite();
        config(['site.watch_keyboard_shortcuts_enabled' => true]);
        $path = (string) parse_url($this->video()->route, PHP_URL_PATH);

        foreach (['en', 'fr', 'zh'] as $locale) {
            $html = $locale === 'en' ? $this->get($path)->assertOk()->getContent() : $this->in($locale, $path);
            $label = fn (string $key): string => e(__(':label:', ['label' => __($key, [], $locale)], $locale));

            // the Video details dialog: each name in the language's label form ("Durée :" in French)
            foreach (['Duration', 'Color filter', 'Aspect ratio', 'Mirror the video', 'Glow around the player'] as $key) {
                $this->assertStringContainsString('<span>' . $label($key) . '</span>', $html, $locale . ': ' . $key);
            }
            // the keyboard shortcuts panel's Night filter and the layout's lights-out toasts
            $this->assertStringContainsString('<span class="shortcut-label">' . e($this->pair(__('Color filter', [], $locale), __('Night', [], $locale), $locale)) . '</span>', $html, $locale);
            $layout = $this->layoutTexts($html);
            $this->assertSame($this->pair(__('Lights out', [], $locale), __('On', [], $locale) . ' (L)', $locale), $layout['lightsOut']);
            $this->assertSame($this->pair(__('Lights out', [], $locale), __('Off', [], $locale), $locale), $layout['lightsRestored']);

            // as each language writes it: "Durée :" (a no-break space before the colon) in French, "时长：" in Chinese
            $colon = ['en' => ':', 'fr' => "\u{00A0}:", 'zh' => '：'][$locale];
            $this->assertStringContainsString('<span>' . e(__('Duration', [], $locale)) . $colon . '</span>', $html, $locale);
            $this->assertSame(__('Lights out', [], $locale) . $colon . ($locale === 'zh' ? '' : ' ') . __('Off', [], $locale), $layout['lightsRestored'], $locale);
        }

        // and no hard-coded join is left in the views' own markup outside the guarded player block (the Night chip's
        // tooltip inside it went with the owner's approval of 30 Oct, R3-048b: WatchOwnerQuestionsTest; the whole tree:
        // WatchAndLibraryRoundThreeSecondPassTest)
        $show = $this->source('resources/views/videos/show.blade.php');
        $block = substr($show, (int) strpos($show, '<div class="avd-player-wrapper position-relative" id="avdPlayerWrapper">'), (int) strpos($show, '<!-- Smart Timestamp Quick-Bookmark Shelf -->') - (int) strpos($show, '<div class="avd-player-wrapper position-relative" id="avdPlayerWrapper">'));
        $outside = str_replace($block, '', $show);
        $this->assertSame(0, preg_match_all('/\}\}:(?:<\/| \{\{)/', $outside, $joins), implode(' | ', $joins[0]));
        $layoutSource = $this->source('resources/views/components/layout.blade.php');
        $this->assertSame(0, preg_match_all("/\\.': '\\.|\\}\\}: \\{\\{/", $layoutSource, $layoutJoins), implode(' | ', $layoutJoins[0]));
    }

    public function test_the_phone_sheets_brightness_chips_and_toast_keep_the_sign_before_the_number(): void
    {
        $this->withoutVite();
        $html = $this->in('ar', (string) parse_url($this->video()->route, PHP_URL_PATH));

        // R3-049: the phone tools sheet's chips read left to right ("+15%", never "15%+" in Arabic)
        $this->assertStringContainsString('avd-mob-bright-chip py-2" dir="ltr" onclick="window.avdSetBrightness(\'boost\', this)">+15%</button>', $html);
        $this->assertStringContainsString('avd-mob-bright-chip py-2" dir="ltr" onclick="window.avdSetBrightness(\'dim\', this)">-15%</button>', $html);
        // and the toast isolates the signed value left to right
        $this->assertStringContainsString("level === 'boost' ? '\\u2066+15%\\u2069' : (level === 'dim' ? '\\u2066-15%\\u2069' : __r('Normal'))", $this->script());
    }

    // ------------------------------------------------------------------------------------------- R3-062

    public function test_library_history_playlist_and_channel_dates_follow_the_readers_language(): void
    {
        config(['app.timezone' => 'Europe/Paris', 'site.date_format' => 'M d, Y']);
        $now = CarbonImmutable::create(2026, 10, 29, 12, 0, 0, 'Europe/Paris');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $card = CarbonImmutable::create(2026, 10, 28, 9, 0, 0, 'UTC');
        $head = CarbonImmutable::create(2026, 10, 26, 9, 0, 0, 'UTC');
        $expected = [
            'en' => ['Oct 28', 'Oct 26, 2026', 'Sunday, Oct 25', 'August 2025'],
            'de' => ['28. Okt.', '26. Okt. 2026', 'Sonntag, 25. Okt.', 'August 2025'],
            'fr' => ['28 oct.', '26 oct. 2026', 'dimanche 25 oct.', 'août 2025'],
            // (ICU writes a narrow no-break space before the Russian "г.")
            'ru' => ['28 окт.', "26 окт. 2026\u{202F}г.", 'воскресенье, 25 окт.', "август 2025\u{202F}г."],
            'ar' => ['28 أكتوبر', '26 أكتوبر 2026', 'الأحد، 25 أكتوبر', 'أغسطس 2025'],
            'ja' => ['10月28日', '2026年10月26日', '10月25日（日曜日）', '2025年8月'],
        ];
        foreach ($expected as $locale => [$short, $full, $day, $month]) {
            app()->setLocale($locale);
            $this->assertSame($short, SiteDate::dayDate($card), $locale);
            $this->assertSame($full, SiteDate::date($head), $locale);
            $this->assertSame($day, WatchHistory::dayLabel('2026-10-25', $now), $locale);
            $this->assertSame($month, SiteDate::monthYear(CarbonImmutable::create(2025, 8, 14, 9, 0, 0, 'UTC')), $locale);
        }
        app()->setLocale('en');

        // the numeric formats stay the admin's choice, in every language
        config(['site.date_format' => 'd.m.Y']);
        app()->setLocale('de');
        $this->assertSame('26.10.2026', SiteDate::date($head));
        app()->setLocale('en');

        // the pages: the Library's playlist card (German), the playlist page's head (French), the channel's About (Japanese)
        config(['site.date_format' => 'M d, Y', 'site.channel_customization_enabled' => true, 'site.ads_enabled' => false, 'site.show_history' => true]);
        $this->withoutVite();
        $member = User::factory()->create();
        $video = $this->video();
        $playlist = Playlist::factory()->for($member)->create(['title' => 'Road trip songs', 'status' => PlaylistStatus::PUBLIC]);
        $playlist->videos()->attach($video->id, ['position' => 1, 'added_at' => $now]);
        DB::table('playlists')->where('id', $playlist->id)->update(['updated_at' => $card]);

        $library = $this->in('de', '/library', $member);
        $this->assertStringContainsString(e(__('Updated :date', ['date' => '28. Okt.'], 'de')), $library);
        $this->assertStringNotContainsString('Okt 28', $library);

        $playlistPage = $this->in('fr', (string) parse_url($playlist->fresh()->route, PHP_URL_PATH), $member);
        $this->assertStringContainsString(e(__('Updated :date', ['date' => '28 oct. 2026'], 'fr')), $playlistPage);
        $this->assertStringNotContainsString('oct. 28', $playlistPage);

        $channel = User::factory()->create(['show_subscribers' => true, 'created_at' => CarbonImmutable::create(2025, 8, 14, 9, 0, 0, 'UTC')]);
        Video::factory()->for($channel)->withStatus(VideoStatus::PUBLIC)->create(['published_at' => $now->subDay()]);
        config(['site.default_locale' => 'en']);
        $_SERVER['DETECTED_LOCALE'] = 'ja';
        try {
            $about = (string) $this->getJson($channel->route . '?part=about')->assertOk()->json('html');
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
            app()->setLocale('en');
        }
        $this->assertStringContainsString('<dd>2025年8月</dd>', $about);
    }

    // ------------------------------------------------------------------------------------------- R3-067

    public function test_the_theater_layout_has_no_row_gutter_from_992px(): void
    {
        $scss = $this->source('resources/sass/video.scss');
        $this->assertMatchesRegularExpression('/@media \(min-width: 992px\) \{\s*\.avd-watch-layout\[data-avd-watch-layout="theater_cinema"\] \{\s*--bs-gutter-x: 0;\s*\}\s*\}/', $scss);
    }

    // ------------------------------------------------------------------------------------------- R3-016, R3-066

    /** The keys themselves are proven in Chromium and WebKit by the lane (runs/bell); here the parts they rely on. */
    public function test_the_bells_menu_answers_the_arrow_keys_and_its_confirmation_takes_and_gives_back_the_focus(): void
    {
        $tsx = $this->source('resources/js/components/Notifications/NotificationsList.tsx');

        // a menu of menu items, opened and closed by the sheet (the arrow keys find its items, Escape closes it on its button)
        $this->assertStringContainsString("const MENU_ITEMS = '.dropdown-item:not(.disabled):not(:disabled)';", $tsx);
        $this->assertStringContainsString('<Dropdown align="end" className="mk-notify__options" show={menuOpen} onToggle={(next: boolean) => setMenuOpen(next)}>', $tsx);
        $this->assertStringContainsString('<Dropdown.Menu ref={menuRef} className="mk-menu" role="menu">', $tsx);
        $this->assertSame(3, substr_count($tsx, 'className="dropdown-item" role="menuitem"') + substr_count($tsx, 'className="dropdown-item mk-menu__item--danger" role="menuitem"'));
        foreach (["if (event.key === 'ArrowDown') {", "} else if (event.key === 'ArrowUp') {", "} else if ((event.key === 'Home' || event.key === 'End') && menu.contains(target)) {", "window.addEventListener('keydown', onKey, true);", "window.removeEventListener('keydown', onKey, true);"] as $now) {
            $this->assertStringContainsString($now, $tsx, $now);
        }

        // Delete all and Delete hand the focus to the confirmation's Cancel; Cancel and Escape give it back to the opener
        $this->assertStringContainsString("onClick={() => askToDelete('all', toggleRef.current)}", $tsx);
        $this->assertStringContainsString("onClick={(event) => askToDelete('selected', event.currentTarget as HTMLElement)}", $tsx);
        $this->assertMatchesRegularExpression('/useEffect\(\(\) => \{\s*if \(confirming\) \{\s*cancelRef\.current\?\.focus\(\);\s*\}\s*\}, \[confirming\]\);/', $tsx);
        $this->assertStringContainsString('<button ref={cancelRef} type="button" className="mk-btn mk-btn--secondary mk-btn--sm" onClick={cancelDelete}>', $tsx);
        $this->assertMatchesRegularExpression("/if \\(confirming && event\\.key === 'Escape' && target && confirm\\?\\.contains\\(target\\)\\) \\{\\s*event\\.preventDefault\\(\\);\\s*event\\.stopPropagation\\(\\);\\s*cancelDelete\\(\\);/", $tsx);
        $this->assertStringContainsString('(canFocus(to) ? to : (canFocus(fallback) ? fallback : toggleRef.current))?.focus();', $tsx);
    }

    public function test_a_save_box_that_waits_keeps_the_keyboard_focus(): void
    {
        $tsx = $this->source('resources/js/components/Playlists/Save.tsx');

        // R3-066: aria-disabled (a press changes nothing) instead of disabled (the focus went to the dialog's Close button)
        $this->assertStringContainsString("aria-disabled={disabled ? 'true' : undefined}", $tsx);
        $this->assertStringContainsString('onClick={(event) => { if (disabled) event.preventDefault(); }}', $tsx);
        $this->assertStringNotContainsString('disabled={disabled}', $tsx);
        // and it is drawn as Bootstrap draws a disabled box
        $scss = $this->source('resources/sass/video.scss');
        $this->assertMatchesRegularExpression('/:root \.avd-save-playlist \.form-check-input\[aria-disabled="true"\] \{\s*pointer-events: none;\s*filter: none;\s*opacity: \.5;\s*\}/', $scss);
        $this->assertMatchesRegularExpression('/:root \.avd-save-playlist \.form-check-input\[aria-disabled="true"\] ~ \.form-check-label \{\s*cursor: default;\s*opacity: \.5;\s*\}/', $scss);
    }
}
