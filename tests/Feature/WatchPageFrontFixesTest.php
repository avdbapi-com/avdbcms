<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.2.3: the watch page (resources/views/videos/show.blade.php) outside its hands-off player regions.
 * QA ids watch-12, auth-18, watch-38, watch-17, watch-40, watch-42, watch-6, watch-24, watch-22, watch-3, perf-5.
 */
class WatchPageFrontFixesTest extends TestCase
{
    use RefreshDatabase;

    private function video(array $attributes = [], ?User $owner = null): Video
    {
        $factory = Video::factory()->withStatus(VideoStatus::PUBLIC);
        $factory = $owner ? $factory->for($owner) : $factory->forUser();

        return $factory->create($attributes + ['title' => 'Watch fixes video', 'duration' => 125]);
    }

    public function test_the_creators_show_likes_choice_reaches_the_members_like_buttons(): void
    {
        $member = User::factory()->create();

        $shown = $this->actingAs($member)->get($this->video(['show_likes' => true])->route)->assertOk()->getContent();
        $this->assertStringContainsString('show-count="true"', $shown);

        $hidden = $this->actingAs($member)->get($this->video(['show_likes' => false])->route)->assertOk()->getContent();
        $this->assertStringContainsString('show-count="false"', $hidden);
    }

    public function test_guest_prompts_sign_in_in_the_visitors_language_and_come_back_to_the_video(): void
    {
        $video = $this->video();
        $html = $this->get($video->route)->assertOk()->getContent();
        $back = '?redirect='.rawurlencode(parse_url($video->route, PHP_URL_PATH));

        $this->assertStringContainsString("href='".locale_url(route('login')).$back, html_entity_decode($html, ENT_QUOTES));
        $this->assertStringNotContainsString("href='".route('login')."'", html_entity_decode($html, ENT_QUOTES));
        // English punctuation: no space before the question mark.
        foreach (['Like this video?', "Don't like this video?", 'Save to playlist?', 'Need to report this video?', 'Want to subscribe to this channel?'] as $title) {
            $this->assertStringContainsString(e($title), $html, $title);
        }
        $this->assertStringNotContainsString('Like this video ?', $html);
    }

    public function test_the_subscriber_count_carries_both_counts_of_the_members_subscription(): void
    {
        $creator = User::factory()->create(['show_subscribers' => true]);
        $video = $this->video([], $creator);
        $member = User::factory()->create();

        $html = $this->actingAs($member)->get($video->route)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-avd-subscriber-count="'.$creator->id.'"[^>]*data-avd-count-on="1 subscriber"[^>]*data-avd-count-off="0 subscribers"/', $html);
        // The script that keeps it in step with the button.
        $this->assertStringContainsString('initSubscriberCounts', (string) file_get_contents(resource_path('js/layout/core.ts')));
    }

    public function test_the_owner_sees_shortcuts_to_edit_the_video_and_its_statistics(): void
    {
        $owner = User::factory()->create();
        $video = $this->video([], $owner);

        $html = $this->actingAs($owner)->get($video->route)->assertOk()->getContent();
        $this->assertStringContainsString(route('user.videos.edit', $video, false).'" class="btn', $html);
        $this->assertStringContainsString(route('user.videos.show', $video, false).'" class="btn', $html);

        $other = $this->actingAs(User::factory()->create())->get($video->route)->assertOk()->getContent();
        $this->assertStringNotContainsString('class="avd-owner-shortcuts', $other);
    }

    public function test_the_details_never_show_the_raw_uuid_as_a_fact(): void
    {
        $video = $this->video(['uuid' => '22222222-2222-4222-8222-222222222222', 'xvid_quality' => 'HD']);
        $html = $this->get($video->route.'?watch_layout=studio_dashboard')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<dt>\s*UUID\s*<\/dt>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<span>UUID:<\/span>/', $html);
    }

    public function test_the_up_next_and_playlist_pictures_are_real_images_and_the_tick_is_earned(): void
    {
        config(['site.creator_verified_badge_enabled' => true]);
        $creator = User::factory()->create(['phone_verified_at' => null, 'is_admin' => false]);
        $video = $this->video([], $creator);
        $this->video(['title' => 'Next one'], $creator);

        $upNext = fn (): string => $this->between($this->get($video->route)->assertOk()->getContent(), '<section class="avd-up-next-card', '</section>');

        $card = $upNext();
        $this->assertStringContainsString('Next one', $card);
        $this->assertStringNotContainsString('<image-loaded', $card);
        // watch-24 (the old assertion looked for title="Verified" and passed whatever the card printed):
        // a channel nobody checked gets no tick on the Up next card...
        $this->assertStringNotContainsString('fa-circle-check', $card);

        // ...a channel whose owner confirmed a phone number gets it...
        $creator->forceFill(['phone_verified_at' => now()])->save();
        $this->assertStringContainsString('fa-circle-check', $upNext());

        // ...and with the "Verified check mark" switch off nobody does.
        config(['site.creator_verified_badge_enabled' => false]);
        $this->assertStringNotContainsString('fa-circle-check', $upNext());
    }

    public function test_time_codes_know_the_videos_length_and_the_comments_are_not_clipped(): void
    {
        $video = $this->video(['duration' => 90, 'description' => 'Best part at 1:10, the end at 2:30 and 12:05:00']);
        $html = $this->get($video->route)->assertOk()->getContent();

        $this->assertStringContainsString('window.AVD_WATCH = {duration: 90, seekable: true};', $html);
        // In the description: inside the length a button, past it text.
        $this->assertStringContainsString("data-timecode='70'", $html);
        $this->assertStringContainsString('<span class="avd-timecode-text">2:30</span>', $html);
        // watch-3: no paint containment on the comments column (it clipped the comment menus).
        $this->assertDoesNotMatchRegularExpression('/#comments_area,\s*\.avd-mobile-comments,\s*\.avd-comments-offcanvas\s*\{[^}]*contain:\s*layout style paint/', $html);
    }

    public function test_the_tool_dock_is_styled_before_it_is_parsed(): void
    {
        // watch-7: the dock's rules are in the watch page's cached stylesheet, linked in the <head>, so they
        // are there before the dock is parsed (perf-5), and before the first paint.
        $this->withVite();
        $html = $this->get($this->video()->route)->assertOk()->getContent();
        $link = strpos($html, 'data-avd-watch-css');
        $head = strpos($html, '</head>');
        $dock = strpos($html, 'id="avdCinemaDock"');

        $this->assertNotFalse($link, 'the watch page links its stylesheet');
        $this->assertNotFalse($dock);
        $this->assertLessThan($head, $link, 'the watch stylesheet is linked in the head');
        $this->assertLessThan($dock, $link, 'the dock rules come after the dock (perf-5)');
        $this->assertStringContainsString('.avd-cinema-dock {', (string) file_get_contents(resource_path('css/watch-page.css')));
    }

    /**
     * watch-7: the watch page carried its tools (84 KB of inline script) and its own rules (33 KB of
     * inline style) in every page's HTML. The tools are the cached module resources/js/layout/watchTools.ts, which reads
     * its settings from a JSON block; the rules are resources/css/watch-page.css. The translations go in as plain text
     * (the module escapes the ones it puts into markup), and a hostile title cannot end the JSON's <script> element.
     */
    public function test_the_watch_tools_run_from_a_cached_module_with_their_settings(): void
    {
        $video = $this->video(['title' => 'Tom\'s </script><script>alert(1)</script> & "night"']);
        $html = $this->get($video->route)->assertOk()->getContent();

        // the old inline code and block are gone
        $this->assertStringNotContainsString('function avdPipResolveElements()', $html);
        $this->assertStringNotContainsString('window.avdToggleAmbilight = function', $html);
        $this->assertStringNotContainsString('id="avdWatchEnhancedStyles"', $html);
        // 200 KB in this test's rendering (factory data, no build tags); the old inline tools (84 KB) or rules (33 KB) would cross it
        $this->assertLessThan(225 * 1024, strlen($html), 'the watch page HTML of a guest');

        // the settings
        $this->assertSame(1, preg_match('#<script type="application/json" id="avd-watch-tools">(.*?)</script>#s', $html, $json));
        $this->assertStringNotContainsString('</script', $json[1]);
        $this->assertStringNotContainsString('<', $json[1]);
        $settings = json_decode($json[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($video->title, $settings['title']);
        $this->assertSame($video->uuid, $settings['uuid']);
        $this->assertSame(route('video.embed', ['video' => $video->uuid]), $settings['embedUrl']);
        $this->assertIsBool($settings['ambientGlow']);

        // every text the module asks for is in the settings
        $module = (string) preg_replace('#^\s*//.*$#m', '', (string) file_get_contents(resource_path('js/layout/watchTools.ts')));
        preg_match_all("/__(?:t|r)\\('((?:[^'\\\\]|\\\\.)+)'\\)/u", $module, $keys);
        $this->assertGreaterThan(40, count(array_unique($keys[1])));
        foreach (array_unique($keys[1]) as $key) {
            $this->assertArrayHasKey($key, $settings['t'], $key);
        }

        // the mini-player switch and the queue stubs for the onclick names until the module runs
        $this->assertMatchesRegularExpression('/var avdPipAllowed = (true|false);/', $html);
        $this->assertStringContainsString("'avdSetVideoFilter'", $html);
        $this->assertStringContainsString('w.__avdWatchQ', $html);
    }

    public function test_the_watch_page_links_its_module_and_stylesheet_from_the_build(): void
    {
        $this->withVite();
        $html = $this->get($this->video()->route)->assertOk()->getContent();
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

        $this->assertMatchesRegularExpression('#<script type="module" src="[^"]*'.preg_quote($manifest['resources/js/layout/watchTools.ts']['file'], '#').'" data-avd-watch-tools></script>#', $html);
        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="[^"]*'.preg_quote($manifest['resources/css/watch-page.css']['file'], '#').'" data-avd-watch-css>#', $html);
        $this->assertMatchesRegularExpression('#<link rel="modulepreload" href="[^"]*'.preg_quote($manifest['resources/js/layout/watchTools.ts']['file'], '#').'">#', $html);
    }

    /**
     * watch-7: the dialogs most visitors never open are not in the page's document until they are wanted: Share, Sleep
     * timer, Notes and Embed code are <template data-avd-lazy> (core.ts renders one on its trigger, on the hover or focus
     * that comes before, or when the page is idle). The share dialog's Alpine components stay registered outside it.
     */
    public function test_the_tool_dialogs_render_on_first_use(): void
    {
        $html = $this->get($this->video()->route)->assertOk()->getContent();

        foreach (['share' => 'id="share"', 'avdSleepTimerModal' => 'id="avdSleepTimerModal"', 'avdNotesModal' => 'id="avdNotesModal"', 'avdEmbedModal' => 'id="avdEmbedModal"', 'avdMobileToolsSheet' => 'id="avdMobileToolsSheet"', 'avdTelemetryModal' => 'id="avdTelemetryModal"'] as $id => $marker) {
            $this->assertSame(1, preg_match('/<template data-avd-lazy="'.$id.'"[^>]*>/', $html, $found, PREG_OFFSET_CAPTURE), $id);
            $open = $found[0][1];
            $close = strpos($html, '</template>', $open);
            $at = strpos($html, $marker, $open);
            $this->assertNotFalse($at, $id);
            $this->assertLessThan($close, $at, $id.' is inside its template');
        }

        // the phone tool sheet (laid out while hidden) is rendered on first use only, the dialogs also when the page is idle
        $this->assertStringContainsString('<template data-avd-lazy="avdMobileToolsSheet" data-avd-lazy-idle="off">', $html);
        $this->assertSame(1, substr_count($html, 'data-avd-lazy-idle="off"'));

        $share = strpos($html, '<template data-avd-lazy="share">');
        $alpine = strpos($html, "Alpine.data('avdShareModal'");
        $this->assertGreaterThan(strpos($html, '</template>', $share), $alpine, "the share dialog's Alpine components are registered outside the template");
        // the trigger stays a plain Bootstrap trigger
        $this->assertStringContainsString('data-bs-toggle="modal" data-bs-target="#share"', $html);
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, 'missing '.$start);
        $to = strpos($html, $end, $from);

        return substr($html, $from, ($to === false ? strlen($html) : $to) - $from);
    }
}
