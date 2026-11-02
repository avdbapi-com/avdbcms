<?php

namespace Tests\Feature\Studio;

use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Playlist;
use App\Models\Thumbnail;
use App\Models\User;
use App\Models\Video;
use App\Support\NumberFormat;
use App\Support\Studio\StudioFormat;
use App\Support\Studio\ZoneLabel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 1.2.3 round 3 (the judge's studio findings, lane ST). Each test fails on RC2 / int-C4 and passes with the fix:
 *  - R3-043 Arabic: the head of a scheduled video isolates "Goes public <date>, <time> GMT+1", so the Latin zone no
 *    longer pulls the views count into its run ("19:00 0 · GMT+1 مشاهدة"), and the count stays with its word on
 *    narrow lines;
 *  - R3-044 the head of a video that never went public says when it was uploaded, as its Analytics head does;
 *  - R3-045 the file size under the preview in the member's language (decimal mark and unit);
 *  - R3-046 Japanese and Chinese: no space after the full-width stop between the schedule help's two sentences;
 *  - R3-053 the Title and Description fields (Video details, the draft page, the playlist editor) take the direction
 *    of what is typed in them (dir="auto"), so a Latin title in an Arabic page shows its start.
 */
class StudioRound3FixesTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('thumbnails');
        config(['app.timezone' => 'Europe/Paris', 'site.date_format' => 'M d, Y']);
        // one clock for the page and the test's own texts ("It is 14:32 there now.")
        $this->freezeTime();
        $this->member = User::factory()->create(['username' => 'nightdrive_tv']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------- R3-043

    public function test_the_arabic_head_of_a_scheduled_video_isolates_when_it_goes_public(): void
    {
        $at = CarbonImmutable::now('Europe/Paris')->addDays(2)->setTime(19, 0);
        $scheduled = $this->video(['title' => 'Premiere: harbour lights at night', 'status' => VideoStatus::PLANNED->value,
            'scheduled_at' => $at, 'published_at' => $at, 'uploaded_at' => now()->subDay(), 'created_at' => now()->subDay(), 'views' => 0]);

        $head = $this->headLine($this->page(route('user.videos.edit', $scheduled), 'ar'));

        app()->setLocale('ar');
        $goes = e(ZoneLabel::goesPublic($at));
        $words = trans_choice(':count view|:count views', 0, ['count' => StudioFormat::number(0)]);
        app()->setLocale('en');
        $this->assertStringContainsString(' ', $words, 'the Arabic count and its word are two words');
        // the count and its word are joined by a no-break space: a narrow line (390 and 412 pixels in Arabic) cannot end
        // with the count and start the next line with its word
        $views = e(str_replace(' ', "\u{00A0}", $words));

        // (GMT+1 in winter, GMT+2 in summer: the test runs on any day)
        $this->assertMatchesRegularExpression('/GMT[+-]\d/', $goes, 'the zone is written with its Latin offset');
        // the goes-public part is one isolated run: its "GMT+1" can no longer take the views count into its direction
        $this->assertStringContainsString('<bdi>'.$goes.'</bdi>', $head);
        $this->assertMatchesRegularExpression('~</bdi>&nbsp;· '.preg_quote($views, '~').'$~u', trim($head), 'the count follows the isolated part, next to its word');
        $this->assertStringNotContainsString(e($words), $head, 'no breakable space between the count and its word');
        // the same line in English reads as before (the isolation and the no-break space are added)
        $en = $this->headLine($this->page(route('user.videos.edit', $scheduled), 'en'));
        $this->assertStringContainsString('<bdi>'.e(ZoneLabel::goesPublic($at)).'</bdi>&nbsp;· 0'."\u{00A0}".'views', $en);
    }

    // ------------------------------------------------------------------------------------------------- R3-044

    public function test_a_video_that_never_went_public_says_when_it_was_uploaded_on_both_heads(): void
    {
        foreach ([VideoStatus::PRIVATE, VideoStatus::UNLISTED] as $status) {
            $uploaded = now()->subDays(4)->setTime(10, 0);
            $video = $this->video(['title' => 'Never public '.$status->name, 'status' => $status->value, 'published_at' => null,
                'uploaded_at' => $uploaded, 'created_at' => $uploaded, 'views' => 3]);

            foreach (['en', 'fr', 'ar'] as $lang) {
                $details = $this->headLine($this->page(route('user.videos.edit', $video), $lang));
                $analytics = $this->headLine($this->page(route('user.videos.show', $video), $lang));

                app()->setLocale($lang);
                $said = e(__('Uploaded :date', ['date' => StudioFormat::date($uploaded)]));
                $views = e(str_replace(' ', "\u{00A0}", trans_choice(':count view|:count views', 3, ['count' => StudioFormat::number(3)])));
                app()->setLocale('en');

                $this->assertStringContainsString($said, $analytics, "$lang: Analytics says when it was uploaded");
                $this->assertStringContainsString($said.'&nbsp;· '.$views, $details, "$lang {$status->name}: Video details says the same, then its views");
            }
        }

        // a public video keeps "Published <date>" on both heads
        $public = $this->video(['title' => 'Sunset drive', 'published_at' => now()->subDays(5)]);
        $this->assertStringContainsString('Published '.StudioFormat::date(now()->subDays(5)), $this->headLine($this->page(route('user.videos.edit', $public), 'en')));
    }

    // ------------------------------------------------------------------------------------------------- R3-045

    public function test_the_file_size_under_the_preview_follows_the_members_language(): void
    {
        $video = $this->video(['title' => 'Golden hour drive', 'size' => 1142116, 'original_file_name' => 'sample.mp4', 'duration' => '8:57']);
        $draft = $this->video(['title' => 'Untitled upload', 'status' => VideoStatus::DRAFT->value, 'published_at' => null, 'size' => 1142116, 'original_file_name' => 'sample.mp4']);

        foreach (['en' => '1.09', 'fr' => '1,09', 'de' => '1,09', 'ru' => '1,09', 'ar' => '1.09', 'ja' => '1.09', 'es' => '1,09', 'tr' => '1,09'] as $lang => $number) {
            app()->setLocale($lang);
            $size = e(__(':size MB', ['size' => $number]));
            app()->setLocale('en');

            foreach ([route('user.videos.edit', $video), route('user.videos.create', $draft)] as $address) {
                $facts = $this->between($this->page($address, $lang), 'sample.mp4', '</div>');
                $this->assertStringContainsString($size, $facts, "$lang: the size in the language's own form");
            }
            $this->assertSame(html_entity_decode($size), NumberFormat::localFileSize(1142116, $lang));
        }

        // the units the same pages use (the picture help's "up to :size MB"): French Mo, Russian МБ, Arabic ميغابايت
        $this->assertSame('1,09 Mo', NumberFormat::localFileSize(1142116, 'fr'));
        $this->assertSame('1,09 МБ', NumberFormat::localFileSize(1142116, 'ru'));
        $this->assertSame('1.09 ميغابايت', NumberFormat::localFileSize(1142116, 'ar'));
        $this->assertSame('1,09 MB', NumberFormat::localFileSize(1142116, 'de'));
        $this->assertSame('1.09 MB', NumberFormat::localFileSize(1142116, 'en'));
        // the other steps keep fileSize()'s precision (two decimals under 10, one under 100, none above)
        $this->assertSame('300 B', NumberFormat::localFileSize(300, 'en'));
        $this->assertSame('300 o', NumberFormat::localFileSize(300, 'fr'));
        $this->assertSame('12,5 Go', NumberFormat::localFileSize(12.5 * 1024 ** 3, 'fr'));
        $this->assertSame('512 KB', NumberFormat::localFileSize(512 * 1024, 'en'));
        $this->assertSame('2 GB', NumberFormat::localFileSize(2147483648, 'en'));
        $this->assertSame('', NumberFormat::localFileSize(null, 'en'));
        // the upload window keeps its own texts (the owner's decision): fileSize() is unchanged
        $this->assertSame('1.09 MB', NumberFormat::fileSize(1142116));
    }

    // ------------------------------------------------------------------------------------------------- R3-046

    public function test_the_schedule_help_has_no_space_after_a_full_width_stop(): void
    {
        $at = CarbonImmutable::now('Europe/Paris')->addDays(2)->setTime(19, 0);
        $scheduled = $this->video(['title' => 'Premiere', 'status' => VideoStatus::PLANNED->value, 'scheduled_at' => $at, 'published_at' => $at, 'views' => 0]);

        foreach (['ja' => '', 'zh' => '', 'en' => ' ', 'fr' => ' ', 'ar' => ' ', 'hi' => ' '] as $lang => $gap) {
            $html = $this->page(route('user.videos.edit', $scheduled), $lang);
            $help = $this->between($html, '<p class="mk-help mk-schedule__zone"', '<br>');

            app()->setLocale($lang);
            $zone = ZoneLabel::zoneLine($at);
            $now = ZoneLabel::nowLine();
            app()->setLocale('en');

            // the zone sentence (its changing parts marked for the script), then the language's gap, then "It is ... now."
            $this->assertStringContainsString('</span>'.$gap.e($now), $help, "$lang: the two sentences joined the language's way");
            // a rewritten sentence is read whole by the live region (aria-atomic), as when the script rewrote it whole
            $this->assertStringContainsString('<span aria-atomic="true">', $help, "$lang: the zone sentence is atomic");
            $this->assertStringContainsString('<strong aria-atomic="true">', $this->between($html, '<p class="mk-help mk-schedule__zone"', '</p>'), "$lang: the goes-public line is atomic");
            $text = trim(html_entity_decode(strip_tags(substr($help, (int) strpos($help, '>') + 1))));
            $this->assertSame($zone.$gap.$now, $text, "$lang: the help reads as one text, the zone sentence as before");
            if ($gap === '') {
                $this->assertStringNotContainsString('。 ', $text, "$lang: no space after the full stop");
            }
        }
    }

    // ------------------------------------------------------------------------------------------------- R3-053

    public function test_title_and_description_fields_take_the_direction_of_their_text(): void
    {
        $video = $this->video(['title' => 'A very long title to test the studio rows and wrapping']);
        $draft = $this->video(['title' => 'Untitled upload', 'status' => VideoStatus::DRAFT->value, 'published_at' => null]);
        $playlist = Playlist::factory()->for($this->member)->create(['title' => 'Best of the night drives', 'description' => 'Night drives.',
            'status' => PlaylistStatus::PUBLIC, 'sort' => PlaylistSort::MANUAL, 'is_deletable' => true]);

        foreach ([route('user.videos.edit', $video), route('user.videos.create', $draft), route('user.playlists.edit', $playlist)] as $address) {
            $html = $this->page($address, 'ar');
            $this->assertMatchesRegularExpression('~<input class="mk-input" id="title"[^>]*\sdata-mk-dir dir="auto"~', $html, $address.': the title field');
            $this->assertMatchesRegularExpression('~<textarea class="mk-input" id="description"[^>]*\sdata-mk-dir dir="auto"~', $html, $address.': the description field');
        }
        // an empty field keeps the page's direction (an empty dir="auto" field is laid out left to right); the studio
        // script gives it dir="auto" once something is typed (data-mk-dir)
        $new = $this->page(route('user.playlists.create'), 'ar');
        $this->assertMatchesRegularExpression('~<input class="mk-input" id="title"[^>]*\sdata-mk-dir required~', $new);
        $this->assertDoesNotMatchRegularExpression('~<input class="mk-input" id="title"[^>]*\sdir="auto"~', $new);
        $script = \Illuminate\Support\Facades\File::get(resource_path('js/member/studio-pages.js'));
        $this->assertStringContainsString("if (field && field.hasAttribute && field.hasAttribute('data-mk-dir')) follow(field);", $script);
    }

    // ------------------------------------------------------------------------------------------------- ST-RV-01

    /**
     * The thumbnail picker (Thumbnails.tsx) starts from the list the page prints and asks the thumbnails API for it again
     * (after 10 s when the connection comes back, after an error). The two must be the same data: a different answer is a
     * new list for the picker, which then picks the stored picture again and drops the member's unsaved choice (lane ST
     * review ST-RV-01: a byte trim of R3-086 printed the pictures' paths, the API answers their full addresses).
     */
    public function test_the_thumbnail_pickers_first_list_is_the_list_the_api_answers(): void
    {
        $video = $this->video(['title' => 'Golden hour drive']);
        Thumbnail::factory()->generated()->for($video)->count(3)->create();
        Thumbnail::factory()->uploaded()->for($video)->create(['is_active' => false]);
        $draft = $this->video(['title' => 'Untitled upload', 'status' => VideoStatus::DRAFT->value, 'published_at' => null]);
        Thumbnail::factory()->generated()->for($draft)->count(2)->create();

        foreach ([[$video, route('user.videos.edit', $video)], [$draft, route('user.videos.create', $draft)]] as [$owner, $address]) {
            $api = $this->actingAs($this->member, 'sanctum')->getJson(route('thumbnails.list', $owner))->assertOk()->json('data');
            $this->assertNotEmpty($api);
            foreach (['en', 'fr', 'ar'] as $lang) {
                $html = $this->page($address, $lang);
                $this->assertSame(1, preg_match("~<thumbnails-select [^>]*initial='([^']*)'~", $html, $match), "$lang $address: the picker and its first list");
                $printed = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame($api, $printed, "$lang $address: the same pictures, addresses, states and active picture as the API's answer");
            }
        }
    }

    // ------------------------------------------------------------------------------------------------- ST-RV-04

    /**
     * From 960px of toolbar width a studio list's filters come onto the toolbar's row only once the studio script has
     * measured that they fit there whole (data-mk-inline); until then, and without the script, they stay in "Filters"
     * on the same one row, so the toolbar never takes a second row (member design 5.6) and nothing below it moves.
     * Layout itself is proven in the browser (lane ST tools p2/p_tb.js, p2/p_hints.js, p2/p_flash.js); this keeps the
     * three parts of the contract together: the markup, the rule and the script.
     */
    public function test_the_filters_come_onto_the_toolbar_only_where_the_script_measured_that_they_fit(): void
    {
        $this->video(['title' => 'Golden hour drive']);
        $html = $this->page(route('user.videos.index'), 'fr');
        $bar = $this->between($html, '<form class="mk-toolbar"', '</form>');
        $this->assertStringContainsString('id="mkf-videos-bar"', $bar);
        $this->assertStringNotContainsString('data-mk-inline', $bar, 'the server prints the folded state: the script decides');
        $this->assertSame(4, substr_count($bar, 'mk-toolbar__filter'), 'the four filters');
        $this->assertStringContainsString('mk-toolbar__more', $bar, 'and the "Filters" button');

        $css = \Illuminate\Support\Facades\File::get(resource_path('sass/member/_kit.scss'));
        $this->assertStringContainsString(':root .avd-mk .mk-toolbar:not([data-mk-inline]) .mk-toolbar__filter { display: none; }', $css);
        $this->assertStringContainsString(':root .avd-mk .mk-toolbar:not([data-mk-inline]) .mk-toolbar__more { display: inline-flex; }', $css);
        $script = \Illuminate\Support\Facades\File::get(resource_path('js/member/studio-pages.js'));
        $this->assertStringContainsString("bar.setAttribute('data-mk-inline', '');", $script);
        $this->assertStringContainsString("if (!shown || !fits(bar)) bar.removeAttribute('data-mk-inline');", $script);
        $this->assertStringContainsString('new ResizeObserver(', $script);
    }

    // ------------------------------------------------------------------------------------------------- ST-VF-01

    /**
     * The focus follows the toolbar's fold both ways: a filter that has it and folds into "Filters" (narrower) hands it to
     * "Filters", and "Filters" that has it and goes because its filters came onto the row (wider: a window, a zoom, the
     * studio menu) hands it to the filter that gave it, else to the row's first filter; it never falls to <body>, from
     * where the next Tab went past the four filters to the list (lane ST verifier ST-VF-01). A dialog or a side sheet whose
     * opener went that way while it was open gives the focus to the same stand-in, and the Custom dates dialog of a Date
     * filter names its select as its opener (it gave the focus to <body> on Cancel or Escape). The behaviour itself is
     * proven in the browser (lane ST tools p3/p_fold_focus.js, Chromium and WebKit); this keeps the parts together.
     */
    public function test_the_focus_follows_the_toolbars_fold_both_ways_and_comes_back_from_its_dialogs(): void
    {
        $this->video(['title' => 'Golden hour drive']);
        foreach (['en', 'fr', 'ar'] as $lang) {
            $bar = $this->between($this->page(route('user.videos.index'), $lang), '<form class="mk-toolbar"', '</form>');
            $this->assertStringContainsString('window.bootstrap.Modal.getOrCreateInstance(dialog).show(select); return;', $bar, "$lang: the Custom dates dialog opens with its Date select as the opener");
            $this->assertSame(1, substr_count($bar, 'data-custom="1"'), "$lang: the Date filter that opens it");
        }

        $script = \Illuminate\Support\Facades\File::get(resource_path('js/member/studio-pages.js'));
        // the part that had the focus is read before the measure, either kind: a filter or "Filters"
        $this->assertStringContainsString("active.closest('.mk-toolbar__filter, .mk-toolbar__more')", $script);
        $this->assertStringContainsString('const next = toolbarStandIn(had);', $script, 'the measure gives the focus to the stand-in of a part it hid');
        // "Filters" gone: the filter that handed it the focus, else the row's first filter
        $standIn = $this->between($script, 'const toolbarStandIn = (control) => {', "\n    };");
        $this->assertStringContainsString("if (control.classList.contains('mk-toolbar__more')) {", $standIn);
        $this->assertStringContainsString('const back = handedBy.get(bar);', $standIn);
        $this->assertStringContainsString("Array.from(bar.querySelectorAll('.mk-toolbar__filter')).find(", $standIn);
        // a filter gone: "Filters", which remembers the filter
        $this->assertStringContainsString('handedBy.set(bar, filter);', $standIn);
        $this->assertStringContainsString('return more;', $standIn);
        // a dialog or a sheet whose opener went away while it was open: the same stand-in
        $hidden = $this->between($script, "['hidden.bs.modal', 'hidden.bs.offcanvas'].forEach((type) => {", '});');
        $this->assertStringContainsString('if (!target.getClientRects().length) target = toolbarStandIn(target);', $hidden);
    }

    // ------------------------------------------------------------------------------------------------- helpers

    private function page(string $address, string $lang): string
    {
        $_SERVER['DETECTED_LOCALE'] = $lang;

        try {
            return $this->actingAs($this->member)->get($address)->assertOk()->getContent();
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
            app()->setLocale('en');
        }
    }

    private function headLine(string $html): string
    {
        return substr($this->between($html, '<p class="mk-head__desc">', '</p>'), strlen('<p class="mk-head__desc">'));
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'not found: '.$from);
        $end = strpos($html, $to, (int) $start + strlen($from));

        return substr($html, (int) $start, $end === false ? null : $end - (int) $start);
    }

    private function video(array $attributes = []): Video
    {
        return Video::factory()->for($this->member, 'user')->create(array_merge([
            'status' => VideoStatus::PUBLIC->value,
            'category_id' => null,
            'language' => null,
            'published_at' => now()->subDays(3),
            'uploaded_at' => now()->subDays(3),
            'created_at' => now()->subDays(3),
        ], $attributes));
    }
}
