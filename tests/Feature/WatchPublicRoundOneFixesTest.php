<?php

namespace Tests\Feature;

use App\Enums\CustomPlaylistType;
use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use App\Playlists\PlaylistManager;
use App\Playlists\Types\WatchLaterPlaylist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * 1.2.3 round 1 fixes of the watch page and the public pages (R1-057, R1-060, R1-061, R1-063, R1-125, R1-146, R1-147,
 * R1-148, R1-149, R1-151): what the server prints. The visual and interactive parts are proven in a browser.
 */
class WatchPublicRoundOneFixesTest extends TestCase
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
            ->create($attributes + ['title' => 'Round one watch video', 'duration' => 125, 'show_likes' => true, 'views' => 0]);
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

    public function test_guest_like_buttons_have_a_name_with_the_readers_count(): void
    {
        $this->withoutVite();
        $video = $this->video();
        foreach (User::factory()->count(3)->create() as $fan) {
            $video->interactions()->create(['user_id' => $fan->id, 'status' => true, 'perform_at' => now()]);
        }
        $html = $this->get($video->route)->assertOk()->getContent();

        // R1-060: the guest's buttons had no name (a screen reader said "button, 3")
        $this->assertStringContainsString('aria-label="I like this (3)"', $html);
        $this->assertStringContainsString('aria-label="I dislike this"', $html);
        $this->assertMatchesRegularExpression('/<i class="fa-regular fa-thumbs-up" aria-hidden="true"><\/i>\s*<span>3<\/span>/', $html);
    }

    public function test_the_watch_pages_view_count_is_written_in_the_readers_language(): void
    {
        $this->withoutVite();
        $path = parse_url($this->video(['views' => 11537])->route, PHP_URL_PATH);

        $this->assertStringContainsString('11,537 views', $this->get($path)->assertOk()->getContent());

        // R1-063: "11,537 vues" in French
        $french = $this->french($path);
        $this->assertStringContainsString("11\u{202F}537", $french);
        $this->assertStringNotContainsString('11,537', $french);
    }

    public function test_the_guest_save_popup_offers_create_account_only_while_sign_ups_are_open(): void
    {
        $this->withoutVite();
        $video = $this->video();
        $createAccount = "/<a href='[^']*\/register[^']*' class='btn btn-outline-secondary btn-sm'>Create account<\/a>/";

        // R1-147: "Sign Up", offered also while sign-ups were closed
        config(['site.registration_enabled' => true]);
        $open = html_entity_decode($this->get($video->route)->assertOk()->getContent(), ENT_QUOTES);
        $this->assertStringContainsString('Sign in to save this video to Watch later or a playlist.', $open);
        $this->assertMatchesRegularExpression($createAccount, $open);
        $this->assertStringNotContainsString('>Sign Up</a>', $open);

        config(['site.registration_enabled' => false]);
        $closed = html_entity_decode($this->get($video->route)->assertOk()->getContent(), ENT_QUOTES);
        $this->assertStringContainsString('Sign in to save this video to Watch later or a playlist.', $closed);
        $this->assertDoesNotMatchRegularExpression($createAccount, $closed);
    }

    public function test_the_description_panel_opens_with_a_button_and_not_from_its_links(): void
    {
        $this->withoutVite();
        $description = 'Gear list at https://example.com/gear-list and the chapters 0:00 1:30. '.str_repeat('A long description line. ', 14);
        $html = $this->get($this->video(['description' => $description])->route)->assertOk()->getContent();

        // R1-061: "Show more" was a div no keyboard reached, and any click on the panel (a link too) toggled it
        $this->assertMatchesRegularExpression('/<button type="button" class="avd-description-toggle[^"]*" aria-controls="avdDescriptionText" aria-expanded="false"/', $html);
        $this->assertStringContainsString('<div id="avdDescriptionText">', $html);
        $this->assertStringContainsString("@click=\"if (!open && !\$event.target.closest('a, button')) open = true\"", $html);
        $this->assertStringNotContainsString('@click="open=!open"', $html);
    }

    public function test_the_share_dialog_copies_with_feedback_instead_of_a_tooltip_that_never_existed(): void
    {
        $this->withoutVite();
        $html = $this->get($this->video()->route)->assertOk()->getContent();
        $share = $this->lazyTemplate($html, 'share');

        // R1-057: the dialog is drawn after start-up, so its Bootstrap tooltips were never made
        $this->assertStringNotContainsString('data-bs-toggle="tooltip"', $share);
        $this->assertStringContainsString("@click=\"copy(link, 'link', 'share-link')\"", $share);
        $this->assertStringContainsString("@click=\"copy(\$event.currentTarget.dataset.link, 'code', 'embed_code')\"", $share);
        $this->assertSame(2, substr_count($share, '<p class="visually-hidden" role="status" x-text="said"></p>'));
        $this->assertStringContainsString('copy(text, kind, fieldId) {', $html);
        // R1-147: sentence case
        $this->assertStringContainsString('>Share video</h5>', $share);
        $this->assertStringContainsString('>Video link</label>', $share);
    }

    public function test_the_phone_tools_sheet_and_the_tool_dialogs_use_plain_named_controls(): void
    {
        $this->withoutVite();
        $path = parse_url($this->video()->route, PHP_URL_PATH);
        $html = $this->get($path)->assertOk()->getContent();
        $sheet = $this->lazyTemplate($html, 'avdMobileToolsSheet');

        // R1-146: plain words, no English state badges filled by the page's script ("HTML5 Native", "Normal", "Toggle")
        $words = ' '.preg_replace('/\s+/u', ' ', strip_tags($sheet)).' ';
        foreach (['Video tools', 'Color filter', 'Vivid', 'Warm film', 'Deep black', 'Black and white', 'Rotate the video', 'Brightness', 'Mirror the video', 'Lights out', 'Focus mode', 'Full screen', 'Video details', 'Notes', 'Embed code'] as $label) {
            $this->assertStringContainsString(' '.$label.' ', $words, $label);
        }
        foreach (['Cinema Suite', 'Shaders', 'Vivid HDR', 'Noir B&amp;W', 'OLED Dark', '35mm Warm', 'avdMobilePlayerTypeBadge', 'avdMobileFilterBadge', 'avdMobileRotateBadge', 'avdMobileBrightBadge', 'avdMobileAmbiBadge', '>Toggle<', '>Inspect<', '>Fullscreen<', 'PRO</span>'] as $jargon) {
            $this->assertStringNotContainsString($jargon, $sheet, $jargon);
        }

        // R1-147: the embed dialog in sentence case
        $embed = $this->lazyTemplate($html, 'avdEmbedModal');
        $this->assertStringContainsString('Embed video player', $embed);
        $this->assertStringContainsString('Copy embed code', $embed);

        // R1-148: the close buttons are named in the reader's language ("Close" in every language before)
        $french = $this->french($path);
        $close = 'aria-label="'.e(__('Close', [], 'fr')).'"';
        $this->assertNotSame('aria-label="Close"', $close);
        foreach (['avdEmbedModal', 'avdSleepTimerModal', 'avdNotesModal', 'avdTelemetryModal', 'avdMobileToolsSheet'] as $dialog) {
            $template = $this->lazyTemplate($french, $dialog);
            $this->assertStringContainsString($close, $template, $dialog.' close button');
            $this->assertStringNotContainsString('aria-label="Close"', $template, $dialog.' close button');
        }
    }

    public function test_the_player_docks_tools_use_plain_words_the_owner_approved(): void
    {
        $this->withoutVite();
        $html = $this->get($this->video()->route)->assertOk()->getContent();
        // the dock and its Tools menu, without the HTML comments of the markup (not shown)
        $start = (int) strpos($html, 'id="avdCinemaDock"');
        $dock = (string) preg_replace('/<!--.*?-->/s', '', substr($html, $start, (int) strpos($html, 'id="avdHtml5OnlyTools"', $start) - $start));

        // R1-146 (owner approved 2026-10-27, words only): "Cinema Suite & Tools", "PRO", "Cinema Suite", "Color Grade
        // Filter" and the player-kind badge's English "Auto" / "HTML5 Native". 1.2.3 round 2, R1R-15 (owner approved
        // 2026-10-28, lane FW's patches): the rest of the dock's names in the phone sheet's words, without the English
        // state badges ("More", the player kind, "Normal", "0°", "Theme", "</>")
        $this->assertStringContainsString('title="Video tools"', $dock);
        $this->assertMatchesRegularExpression('/<span>Tools<\/span>\s*<\/button>/', $dock);
        $this->assertMatchesRegularExpression('/<\/i>Video tools<\/span>\s*<\/li>/', $dock);
        $this->assertStringContainsString('</i>Color filter', $dock);
        foreach (['Cinema Suite', '>PRO<', 'Color Grade Filter', '>Auto<', '>More<', 'id="avdPlayerTypeBadge"', 'id="avdCurrentFilterBadge"', 'id="avdCurrentRotationBadge"', 'id="avdCurrentBrightBadge"', 'id="avdCurrentAmbiBadge"', '&lt;/&gt;'] as $old) {
            $this->assertStringNotContainsString($old, $dock, $old);
        }

        // the badge's words come from the page's translations (watchTools.ts reads them with __r())
        preg_match('/<script type="application\/json" id="avd-watch-tools">(.*?)<\/script>/s', $html, $settings);
        $texts = json_decode($settings[1] ?? '{}', true)['t'] ?? [];
        foreach (['Upload18 player', 'Built-in player', 'Player ready'] as $key) {
            $this->assertArrayHasKey($key, $texts, $key);
        }
        $script = (string) file_get_contents(resource_path('js/layout/watchTools.ts'));
        $this->assertStringNotContainsString("'HTML5 Native'", $script);
        $this->assertStringContainsString("__r('Built-in player')", $script);
    }

    public function test_the_watch_later_buttons_and_messages_use_the_sites_words(): void
    {
        $this->withoutVite();
        $video = $this->video(['title' => 'Card one'])->refresh()->load('user');

        // R1-147: "Watch Later" (title case) named the cards' button; the button saves, its name says so
        foreach (['videos.card', 'videos.card-secondary'] as $view) {
            $card = view($view, ['video' => $video])->render();
            $this->assertStringContainsString('aria-label="Save to Watch later" data-watch-later-id="'.$video->id.'"', $card, $view);
            $this->assertStringNotContainsString('Watch Later', $card, $view);
        }

        $html = $this->get($video->route)->assertOk()->getContent();
        foreach (['"wlTitle":"Save to Watch later"', '"wlSavedTitle":"Remove from Watch later"', '"wlSaved":"Saved to Watch later"', '"wlRemoved":"Removed from Watch later"', '"wlFailed":"That did not work. Check your connection and try again."'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        foreach (['Saved to Watch Later!', 'Failed to update Watch Later', 'Saved in Watch Later (Click to remove)', 'Removed from Watch Later'] as $old) {
            $this->assertStringNotContainsString($old, $html);
        }
    }

    public function test_the_watch_later_list_reads_as_the_menus_words_in_the_save_dialog(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member);
        $playlist = PlaylistManager::get(CustomPlaylistType::WATCH_LATER)->getPlaylist();

        // its stored title stays the identifier the lists are found by
        $this->assertSame(WatchLaterPlaylist::getName(), $playlist->getAttributes()['title']);

        // R1-147: the Save dialog listed "Watch Later" ("Regarder plus tard") beside the menu's "Watch later" ("À regarder
        // plus tard")
        $this->getJson(route('me.playlists'))->assertOk()->assertJsonFragment(['title' => 'Watch later']);

        app()->setLocale('fr');
        $this->assertSame(__('Watch later', [], 'fr'), $playlist->fresh()->title);
        $this->assertNotSame(__('Watch Later', [], 'fr'), $playlist->fresh()->title);
        app()->setLocale('en');
    }

    public function test_the_categories_page_counts_in_words(): void
    {
        $this->withoutVite();
        Category::query()->create(['title' => 'Music', 'slug' => 'music', 'in_menu' => true]);
        Category::query()->create(['title' => 'Travel', 'slug' => 'travel', 'in_menu' => true]);

        $html = $this->get(route('pages.categories'))->assertOk()->getContent();

        // R1-147: "All Categories", a number glued to "Categories" and "Total Videos"
        $this->assertStringContainsString('All categories</h1>', $html);
        $this->assertStringContainsString('2 categories', $html);
        $this->assertStringContainsString('0 videos', $html);
        $this->assertStringNotContainsString('All Categories', $html);
        $this->assertStringNotContainsString('Total Videos', $html);
    }

    public function test_the_front_menus_language_picker_is_named_for_what_it_does(): void
    {
        $this->withoutVite();
        config(['site.allow_language_switch' => true, 'site.sidebar_locale_switcher_enabled' => true]);
        $path = parse_url($this->video()->route, PHP_URL_PATH);

        // R1-063: it was named __("Locale"), read "Temps de verrouillage (minutes)" in French
        $this->assertMatchesRegularExpression('/class="avd-locale-toggle"[^>]*aria-label="Change language"/', $this->get($path)->assertOk()->getContent());
        $french = $this->french($path);
        $this->assertMatchesRegularExpression('/class="avd-locale-toggle"[^>]*aria-label="'.preg_quote(e(__('Change language', [], 'fr')), '/').'"/u', $french);
        $this->assertStringNotContainsString('Temps de verrouillage', $french);
    }

    public function test_the_phone_pager_shows_only_when_there_is_more_than_one_page(): void
    {
        $render = fn (int $total): string => (string) (new LengthAwarePaginator(range(1, min($total, 10)), $total, 10, 1, ['path' => '/search']))
            ->links('vendor.pagination.bootstrap-5');

        // R1-149: two disabled buttons under one page of results on phones
        $one = $render(4);
        $this->assertStringNotContainsString('d-sm-none', $one);
        $this->assertStringNotContainsString(__('pagination.previous'), $one);
        $this->assertStringNotContainsString(__('pagination.next'), $one);

        $three = $render(25);
        $this->assertStringContainsString('d-sm-none', $three);
        $this->assertStringContainsString(__('pagination.next'), $three);
    }

    public function test_the_subscribe_placeholder_holds_both_faces_so_the_button_keeps_one_width(): void
    {
        $channel = User::factory()->create(['username' => 'faces_channel']);
        $this->actingAs(User::factory()->create());

        $off = view('library.subscribe', ['channel' => $channel, 'subscribed' => false])->render();
        $on = view('library.subscribe', ['channel' => $channel, 'subscribed' => true])->render();

        // R1-125: "Subscribed" with the bell's chevron is wider than "Subscribe": both faces are in the button, invisible
        // (member/_shared.scss .mk-subscribe--faces), and the face of the state is drawn over them, so the button keeps
        // its width in both states (1.2.3 round 2, R2A-027: the invisible pair is drawn in the look's local fonts)
        foreach (['off' => $off, 'on' => $on] as $state => $html) {
            $this->assertStringContainsString('mk-subscribe mk-subscribe--faces', $html, $state);
            $this->assertSame(3, substr_count($html, 'class="mk-subscribe__face '), $state);
            $this->assertMatchesRegularExpression('/class="mk-subscribe__face mk-subscribe__face--off"\s+aria-hidden="true"\s*><i class="fa-solid fa-bell fa-fw" aria-hidden="true"><\/i><span>Subscribe<\/span>/', $html, $state.': the hidden Subscribe face');
            $this->assertMatchesRegularExpression('/class="mk-subscribe__face mk-subscribe__face--on"\s+aria-hidden="true"\s*><i class="fa-solid fa-(bell|check) fa-fw" aria-hidden="true"><\/i><span>Subscribed<\/span>/', $html, $state.': the hidden Subscribed face');
            $this->assertSame(2, substr_count($html, 'aria-hidden="true"><i class="fa-solid fa-'), $state.': two hidden faces');
        }
        $this->assertMatchesRegularExpression('/class="mk-subscribe__face mk-subscribe__face--off"><i class="fa-solid fa-bell fa-fw" aria-hidden="true"><\/i><span>Subscribe<\/span><\/span>/', $off, 'the shown face');
        $this->assertMatchesRegularExpression('/class="mk-subscribe__face mk-subscribe__face--on"><i class="fa-solid fa-(bell|check) fa-fw" aria-hidden="true"><\/i><span>Subscribed<\/span>/', $on, 'the shown face');
    }

    public function test_the_home_feed_keeps_the_phone_page_gutter(): void
    {
        // R1-151: the feed's cards sat 8px from the screen's edges (px-2) while the rest of the page keeps 16px
        foreach (['views/pages/home.blade.php', 'views/pages/partials/home-feed.blade.php'] as $file) {
            $source = (string) file_get_contents(resource_path($file));
            $this->assertStringContainsString('avd-home-feed px-3 px-sm-0', $source, $file);
            $this->assertStringNotContainsString('avd-home-feed px-2', $source, $file);
        }

        $this->withoutVite();
        $this->video(['title' => 'Feed card']);
        $html = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('avd-home-feed px-2', $html);
    }

    public function test_the_trending_and_category_feeds_keep_the_phone_page_gutter(): void
    {
        // R1-151 (second pass): below 576px the trending and category pages' container has no side padding, and their
        // cards touched the screen's edges (0-5px); the feed now keeps the home feed's 16px (px-3, none from 576px)
        $this->withoutVite();
        $category = Category::query()->create(['title' => 'Travel', 'slug' => 'travel', 'in_menu' => true]);
        $this->video(['title' => 'Gutter card', 'category_id' => $category->id, 'uploaded_at' => now()->subDay(), 'published_at' => now()->subDay()]);

        foreach ([route('pages.trend'), route('pages.category', $category->slug)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<div class="px-3 px-sm-0">\s*<videos-area\b/', $html, $url);
        }
    }

    public function test_phone_heroes_and_the_category_chips_keep_the_page_gutter(): void
    {
        // R1-151 (second pass): below 576px the home container has no side padding (px-0; px-sm-3 from 576px). The
        // ticker's panel and badge, the split panel, the gallery print, the arch and the rounded billboards sat at
        // 0-4px, the full-bleed billboards ran 12px past both screen edges (text at 4px), and the chips row started
        // 4px from the edge: one phone block keeps them on the 16px gutter, without doubling it from 576px.
        $b = (string) file_get_contents(resource_path('css/layout-theme-b.css'));
        $phone = strpos($b, '@media (max-width: 575.98px) {');
        $this->assertNotFalse($phone, 'the phone (< 576px) block of layout-theme-b.css');
        $block = substr($b, $phone, (int) strpos($b, "\n}\n", $phone) - $phone);
        foreach (['.avd-hero--ticker,', '.avd-hero--arch,', '.avd-hero--billboard,', '.avd-hero--bento .avd-bento,', '.avd-hero--bento .avd-gallery,', '.avd-hud,', '.avd-shorts,', '.avd-split,', '.avd-lead .avd-l > .avd-ph {'] as $selector) {
            $this->assertStringContainsString($selector, $block);
        }
        $this->assertMatchesRegularExpression('/\.avd-lead \.avd-l > \.avd-ph \{\s*margin-inline: 1rem;/', $block);
        $this->assertMatchesRegularExpression('/html\[data-avd-hero-deco="billboard_full"\] \.avd-bb-track \{\s*margin-inline: 0;/', $block);
        $this->assertMatchesRegularExpression('/:root \.avd-chips-scroller-wrapper \.avd-category-tabs \{\s*padding-inline: 1rem !important;\s*scroll-padding-inline: 1rem;/', $block);
        // the full-bleed tracks bleed exactly the container's 1rem from 576px (-12px left a 4px strip there)
        $this->assertStringNotContainsString('margin: -12px -12px 0;', $b);
        $this->assertStringContainsString('margin: -12px -1rem 0;', $b);
        // the tiles' gutter is not added on top of the container's padding from 576px (it doubled to 32px there)
        $this->assertSame(0, preg_match('/@media \(max-width: 767\.98px\) \{(?:(?!\n\}\n).)*?margin-inline: 16px;/s', $b));

        foreach (['billboard_fade', 'split_orbit'] as $deco) {
            $css = (string) file_get_contents(resource_path("css/themes/hero-decos/{$deco}.css"));
            $this->assertStringNotContainsString('margin: -12px -12px 0;', $css, $deco);
            $this->assertStringContainsString('margin: -12px -1rem 0;', $css, $deco);
            $this->assertMatchesRegularExpression('/@media \(max-width: 575\.98px\) \{\s*html\[data-avd-hero-deco="'.$deco.'"\]\[data-avd-theme\] \.avd-hero--billboard,\s*html\[data-avd-hero-deco="'.$deco.'"\]\[data-avd-theme\] \.avd-bb-track \{\s*margin-inline: 0;/', $css, $deco);
        }
        $soft = (string) file_get_contents(resource_path('css/themes/hero-decos/banner_soft.css'));
        $this->assertMatchesRegularExpression('/@media \(max-width: 575\.98px\) \{\s*html\[data-avd-hero-deco="banner_soft"\]\[data-avd-theme\] \.avd-banner \{\s*margin-inline: 1rem;/', $soft);
        // the decorations ship inside their themes' token files (php artisan avd:themes:build)
        foreach (['deep_trench' => 'billboard_fade', 'interstellar' => 'split_orbit', 'nordic_minimal' => 'banner_soft'] as $theme => $deco) {
            $this->assertStringContainsString('html[data-avd-hero-deco="'.$deco.'"][data-avd-theme]', (string) file_get_contents(resource_path("css/themes/{$theme}.css")));
            $this->assertStringNotContainsString('margin: -12px -12px 0;', (string) file_get_contents(resource_path("css/themes/{$theme}.css")), $theme);
        }
    }

    public function test_the_save_dialog_answers_the_press_before_it_renders(): void
    {
        // R1-026 (second pass): rendering the Save to playlist dialog inside the click kept the press unanswered for
        // 232-264 ms at CPU 4x on a 1366px screen (budget 200 ms). The press paints first (ripple, focus), the dialog
        // renders in the task after that frame; react-bootstrap's own manager and Fade stay out (first pass).
        $save = (string) file_get_contents(resource_path('js/components/Playlists/Save.tsx'));
        $this->assertStringContainsString('onClick={() => afterNextPaint(() => setOpen(true))}', $save);
        $this->assertStringNotContainsString('onClick={() => setOpen(true)}', $save);
        $this->assertMatchesRegularExpression('/window\.requestAnimationFrame\(\(\) => \{\s*window\.clearTimeout\(timer\);\s*window\.setTimeout\(run, 0\);/', $save);
        $this->assertStringContainsString('aria-haspopup="dialog"', $save);
        $this->assertStringContainsString('manager={calmModalManager()} animation={false}', $save);

        // Every dialog's opening and closing restyled every element of the page that has an id (about 100 on the watch
        // page): the debug bar's rule keyed on [id^="phpdebugbar"]; it keys on the bar's classes only.
        $app = (string) file_get_contents(resource_path('sass/app.scss'));
        $this->assertSame(0, preg_match('/^\s*body\.modal-open \[id\^="phpdebugbar"\]\s*[,{]/m', $app), 'no rule keys on [id^="phpdebugbar"]');
        $this->assertMatchesRegularExpression('/body\.modal-open \.phpdebugbar,\s*body\.modal-open \.phpdebugbar-openhandler,\s*body\.modal-open \.phpdebugbar-closedhandler \{\s*display: none !important;/', $app);
    }

    public function test_the_typed_part_of_a_suggestion_keeps_its_contrast_on_the_selected_row(): void
    {
        // R1-145 (second pass): on the raised row (keyboard selection or pointer) the brand ink fell to 3.9-4.1:1 in
        // the default look, Daylight and Cinema Night; there the typed part takes the row's own ink, keeping its
        // weight and underline
        $helpers = (string) file_get_contents(resource_path('sass/helpers.scss'));
        $this->assertMatchesRegularExpression('/\.avd-search-hit em \{\s*color: var\(--avd-brand-ink, var\(--bs-link-color\)\);\s*text-decoration: underline;\s*text-decoration-thickness: 2px;/', $helpers);
        $this->assertMatchesRegularExpression('/\.avd-search-result:hover \.avd-search-hit em,\s*\.avd-search-result\.selected \.avd-search-hit em \{\s*color: inherit;\s*\}/', $helpers);
    }
}
