<?php

namespace Tests\Feature\Studio;

use App\Enums\VideoStatus;
use App\Http\Middleware\BackgroundRequest;
use App\Models\User;
use App\Models\Video;
use App\Support\Studio\StudioFormat;
use App\Support\Studio\ViewsSeries;
use App\Support\Studio\ZoneLabel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 1.2.3 round 2 (the round 2a judge's studio findings, lane CS). Each test fails on int-C2 and passes with the fix:
 *  - R2A-008 the draft page's completion check (and the upload window's checks) never use up "Draft saved.": they are
 *    background requests by their route, as their hands-off scripts cannot send the header;
 *  - R2A-009 a scheduled video says when it goes public, never "Published <future date>"; a video that never went
 *    public says when it was uploaded, and its chart starts that day;
 *  - R2A-034 a draft still processing on Upload18 offers "Finish the draft" only (Video details sends it there);
 *  - R2A-023 a short date that ends a sentence ends it with one full stop ("le 22 oct.", not "le 22 oct..");
 *  - R2A-037 the site's time zone city is written in the member's language (ICU's exemplar city), and its sentence
 *    ends with the language's own full stop;
 *  - R2A-012 titles and names that are cut take their own direction (dir="auto"), so a cut keeps their start; the
 *    confirm dialogs print them in their own direction too; the Reports line lets a long name give way as well (it
 *    ran out of its column: the review's R2CS-RV-01);
 *  - R2A-011 the bulk bars' select-all box shows "some rows ticked" (mixed) as well;
 *  - R1-026 the first row menu of a page is laid out once in an idle moment after load (the first open is not slower).
 */
class StudioRound2FixesTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private ?string $sessions = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('thumbnails');
        $this->member = User::factory()->create(['username' => 'nightdrive_tv']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);

        if ($this->sessions !== null) {
            File::deleteDirectory($this->sessions);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------- R2A-008

    public function test_a_completion_check_between_save_draft_and_its_page_leaves_draft_saved(): void
    {
        $draft = $this->video(['title' => 'Untitled upload 0425', 'status' => VideoStatus::DRAFT->value, 'published_at' => null, 'uploaded_at' => null]);
        $id = $this->fileSessions();
        $this->actingAs($this->member)->withCookie(config('session.cookie'), $id);
        $page = route('user.videos.create', $draft);

        // Save draft: back to the draft page with the one-time "Draft saved."
        $this->freshStore();
        $this->post(route('user.videos.store', $draft), $this->draftForm('Rain on the windshield'))->assertRedirect($page);
        $saved = File::get($this->sessionFile($id));
        $this->assertStringContainsString('Draft saved.', $saved);

        // the page's completion check (every 10 s, the hands-off poll partial: no background header) reaches the site
        // before the redirected page does
        $check = $this->poll('/api/videos/'.$draft->id.'/create-status', $page)->assertOk();
        $this->assertFalse($check->json('has_embed_code'));
        $this->assertSame($saved, File::get($this->sessionFile($id)), 'the check never writes the session');
        $this->assertSame([$id], $this->sessionIds($check), 'the browser keeps its session (the same id)');

        // the page the save led to shows the toast, once
        $this->freshStore();
        $this->get($page)->assertOk()->assertSee('<template data-mk-flash-toast data-type="success">Draft saved.</template>', false);
        $this->freshStore();
        $this->get($page)->assertOk()->assertDontSee('data-mk-flash-toast', false);
    }

    public function test_the_upload_windows_progress_check_leaves_a_one_time_message_too(): void
    {
        $id = $this->fileSessions();
        $this->actingAs($this->member)->withCookie(config('session.cookie'), $id);
        $draft = $this->video(['title' => 'Untitled upload 0426', 'status' => VideoStatus::DRAFT->value, 'published_at' => null, 'uploaded_at' => null]);

        $this->freshStore();
        $this->post(route('user.videos.store', $draft), array_merge($this->draftForm('Second draft'), ['action' => 'later']))->assertRedirect(route('user.videos.index'));
        $saved = File::get($this->sessionFile($id));

        // the upload window (VideoUpload.tsx, hands-off) asks for its settings and then for its progress every 10 s
        $this->poll('/api/videos/upload18/config', url('/profile/videos'));
        $this->poll('/api/videos/upload18/status?did=r2cs-did&vid=r2cs-vid', url('/profile/videos'));
        $this->assertSame($saved, File::get($this->sessionFile($id)), 'neither check writes the session');

        $this->freshStore();
        $this->get(route('user.videos.index'))->assertOk()->assertSee('<template data-mk-flash-toast data-type="success">Draft saved.</template>', false);
    }

    public function test_only_those_routes_and_only_their_gets_are_background_by_their_route(): void
    {
        $request = function (string $method, string $routeName, array $server = []): Request {
            $request = Request::create('/api/x', $method, [], [], [], $server);
            $route = (new Route([$method], 'api/x', fn () => null))->name($routeName);
            $request->setRouteResolver(fn () => $route);

            return $request;
        };

        foreach (BackgroundRequest::ROUTES as $name) {
            $this->assertTrue(BackgroundRequest::is($request('GET', $name)), $name);
            $this->assertTrue(BackgroundRequest::is($request('HEAD', $name)), $name);
            $this->assertFalse(BackgroundRequest::is($request('POST', $name)), 'a POST never is: '.$name);
        }

        $this->assertSame(['videos.create-status', 'videos.upload18.status', 'videos.upload18.config'], BackgroundRequest::ROUTES);
        $this->assertFalse(BackgroundRequest::is($request('GET', 'videos.upload18.chunk')));
        $this->assertFalse(BackgroundRequest::is($request('GET', 'user.videos.create')), 'a page view is not');
        $this->assertTrue(BackgroundRequest::is($request('GET', 'user.videos.create', ['HTTP_X_BACKGROUND_REQUEST' => '1'])), 'the header still marks any GET');
        $this->assertFalse(BackgroundRequest::is(Request::create('/api/x', 'GET')), 'no route matched yet: only the header counts');

        // and the routes exist under these names (routes/api.php)
        foreach (BackgroundRequest::ROUTES as $name) {
            $this->assertTrue(app('router')->has($name), $name);
            $this->assertContains('GET', app('router')->getRoutes()->getByName($name)->methods(), $name);
        }
    }

    public function test_the_comments_replies_sheet_is_read_in_the_background(): void
    {
        // the studio's own background read (the Comments page's replies sheet): marked like the site's other ones
        $script = File::get(resource_path('js/member/studio-pages.js'));
        $this->assertStringContainsString("fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html', 'X-Background-Request': '1'}, credentials: 'same-origin'})", $script);
        $this->assertSame(BackgroundRequest::HEADER, 'X-Background-Request');
    }

    // ------------------------------------------------------------------------------------------------- R2A-009

    public function test_a_scheduled_video_says_when_it_goes_public_never_published(): void
    {
        config(['app.timezone' => 'Europe/Paris', 'site.date_format' => 'M d, Y']);
        $at = CarbonImmutable::now('Europe/Paris')->addDays(2)->setTime(19, 0);
        $scheduled = $this->video(['title' => 'Premiere: harbour lights at night', 'status' => VideoStatus::PLANNED->value,
            'scheduled_at' => $at, 'published_at' => $at, 'uploaded_at' => now()->subDay(), 'created_at' => now()->subDay(), 'views' => 0]);
        $goes = ZoneLabel::goesPublic($at);

        $edit = $this->actingAs($this->member)->get(route('user.videos.edit', $scheduled))->assertOk()->getContent();
        $head = $this->between($edit, '<p class="mk-head__desc">', '</p>');
        $this->assertStringContainsString(e($goes), $head);
        $this->assertStringNotContainsString('Published', $head);

        $analytics = $this->actingAs($this->member)->get(route('user.videos.show', $scheduled))->assertOk()->getContent();
        $head = $this->between($analytics, '<p class="mk-head__desc">', '</p>');
        $this->assertStringContainsString(e($goes), $head);
        $this->assertStringNotContainsString('Published', $analytics);
        $this->assertStringContainsString('Since '.StudioFormat::date(now()->subDay()), $analytics, 'the views count from the upload');

        // in French too (the /fr page: public/index.php hands the prefix over as DETECTED_LOCALE)
        $_SERVER['DETECTED_LOCALE'] = 'fr';
        $head = $this->between($this->actingAs($this->member)->get(route('user.videos.edit', $scheduled))->assertOk()->getContent(), '<p class="mk-head__desc">', '</p>');
        $this->assertStringContainsString('Planifiée', $head);
        $this->assertStringNotContainsString('Publiée le', $head);
    }

    public function test_a_public_video_and_one_that_never_went_public_keep_their_own_dates(): void
    {
        config(['app.timezone' => 'Europe/Paris', 'site.date_format' => 'M d, Y']);
        $published = CarbonImmutable::now()->subDays(5);
        $public = $this->video(['title' => 'Sunset drive', 'published_at' => $published]);
        $private = $this->video(['title' => 'Private cut', 'status' => VideoStatus::PRIVATE->value, 'published_at' => null, 'uploaded_at' => now()->subDays(3), 'created_at' => now()->subDays(3)]);

        $this->assertStringContainsString('Published '.StudioFormat::date($published), $this->between($this->actingAs($this->member)->get(route('user.videos.edit', $public))->getContent(), '<p class="mk-head__desc">', '</p>'));
        $this->assertStringContainsString('Published '.StudioFormat::date($published), $this->between($this->actingAs($this->member)->get(route('user.videos.show', $public))->getContent(), '<p class="mk-head__desc">', '</p>'));
        $head = $this->between($this->actingAs($this->member)->get(route('user.videos.show', $private))->getContent(), '<p class="mk-head__desc">', '</p>');
        $this->assertStringContainsString('Uploaded '.StudioFormat::date(now()->subDays(3)), $head, 'never public: its upload day');
        $this->assertStringNotContainsString('Published', $head);
    }

    public function test_a_scheduled_videos_chart_starts_at_its_upload_and_never_says_published(): void
    {
        config(['app.timezone' => 'Europe/Paris']);
        $at = CarbonImmutable::now()->addDays(3);
        $scheduled = $this->video(['title' => 'Owner preview', 'status' => VideoStatus::PLANNED->value, 'scheduled_at' => $at, 'published_at' => $at,
            'uploaded_at' => now()->subDays(4), 'created_at' => now()->subDays(4), 'views' => 3]);
        DB::table('video_view_daily_stats')->insert(['video_id' => $scheduled->id, 'view_date' => now()->subDays(2)->toDateString(), 'views_count' => 3, 'created_at' => now(), 'updated_at' => now()]);

        $series = ViewsSeries::build($scheduled, 'all');
        $this->assertFalse($series['wentPublic']);
        $this->assertSame(CarbonImmutable::now(ZoneLabel::zone())->subDays(4)->toDateString(), $series['published']->toDateString(), 'the chart starts the day it was uploaded');

        $html = $this->actingAs($this->member)->get(route('user.videos.show', ['video' => $scheduled, 'period' => 'all']))->assertOk()->getContent();
        $this->assertStringNotContainsString('since it was published', $html);
        $this->assertStringNotContainsString('so the chart starts that day', $html);
        $this->assertStringContainsString('Views per day, from', $html);

        $this->assertTrue(ViewsSeries::build($this->video(['title' => 'Public one', 'published_at' => now()->subDays(2)]), 'all')['wentPublic']);
    }

    // ------------------------------------------------------------------------------------------------- R2A-034

    public function test_a_draft_still_processing_offers_finish_the_draft_only(): void
    {
        $processing = $this->video(['title' => 'Draft still processing on Upload18', 'status' => VideoStatus::DRAFT->value, 'published_at' => null, 'uploaded_at' => null, 'upload18_vid' => 'r2cs-did-1', 'embed_code' => null]);
        $draft = $this->video(['title' => 'Draft: rain', 'status' => VideoStatus::DRAFT->value, 'published_at' => null]);
        $uploading = $this->video(['title' => 'Private server upload', 'status' => VideoStatus::PRIVATE->value, 'published_at' => null, 'uploaded_at' => null]);

        $data = $this->rowsJson($this->actingAs($this->member)->get(route('user.videos.index'))->assertOk()->getContent(), 'mk-video-rows');

        $this->assertSame(1, $data['r'][$processing->id]['finish'] ?? null);
        $this->assertArrayNotHasKey('edit', $data['r'][$processing->id], 'Video details of a draft is the draft page: one entry for one place');
        $this->assertArrayNotHasKey('edit', $data['r'][$draft->id]);
        $this->assertSame(1, $data['r'][$uploading->id]['edit'] ?? null, 'a video (not a draft) still being processed keeps Edit details');
        $this->actingAs($this->member)->get(route('user.videos.edit', $processing))->assertRedirect(route('user.videos.create', $processing));
    }

    // ------------------------------------------------------------------------------------------------- R2A-023

    public function test_a_short_date_that_ends_a_sentence_ends_it_with_one_full_stop(): void
    {
        $this->skipUnlessIntl();
        config(['site.date_format' => 'M d, Y', 'app.timezone' => 'Europe/Paris']);
        $date = CarbonImmutable::create(2026, 10, 22, 12, 0, 0, 'Europe/Paris');

        // fr and pt end the sentence with the date, de and ru keep it inside: one point after "oct." in every case
        foreach (['en' => 'on Oct 22.', 'fr' => 'le 22 oct.', 'pt' => 'em 22 de out.', 'de' => 'am 22. Okt. nicht fertig verarbeitet.', 'ru' => '22 окт. не завершилась.', 'es' => 'el 22 oct.'] as $locale => $tail) {
            app()->setLocale($locale);
            $short = StudioFormat::shortDate($date);
            $line = StudioFormat::endingWith(__('The file did not finish processing on :date.', ['date' => $short]), $short);
            $this->assertStringNotContainsString('..', $line, $locale.': '.$line);
            $this->assertStringEndsWith($tail, $line, $locale);
            $this->assertStringContainsString($short, $line, $locale.': the date itself is whole');
        }

        $this->assertSame('a 22 oct. b.', StudioFormat::endingWith('a 22 oct. b.', '22 oct.'), 'only where the date ends the sentence');
        $this->assertSame('x.', StudioFormat::endingWith('x.', ''));
    }

    public function test_the_dashboards_failed_upload_line_has_one_full_stop_in_french(): void
    {
        $this->skipUnlessIntl();
        // a fixed day in October: the French short month carries its own point ("27 oct."), which is when the sentence's
        // full stop must not be added after it (in May, June or August the month has no point and nothing can go wrong)
        $this->travelTo(CarbonImmutable::create(2026, 10, 28, 12, 0, 0, 'Europe/Paris'));
        config(['site.date_format' => 'M d, Y', 'app.timezone' => 'Europe/Paris']);
        $this->video(['title' => 'Rainy day vlog', 'status' => VideoStatus::FAILED->value, 'published_at' => null, 'uploaded_at' => null, 'updated_at' => now()->subDay()]);

        $_SERVER['DETECTED_LOCALE'] = 'fr';
        $html = $this->actingAs($this->member)->get(route('user.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Échec de l', $html, 'the French dashboard');

        // the line exactly as the Dashboard prints it, up to its closing tag: one point after "oct.", none added
        $this->assertSame(1, preg_match('~>(Le traitement du fichier [^<]*)</span>~u', $html, $line), 'the failed-upload line');
        $this->assertSame(e("Le traitement du fichier ne s'est pas terminé le 27 oct."), $line[1]);
        $this->assertStringNotContainsString('27 oct..', $html);
    }

    // ------------------------------------------------------------------------------------------------- R2A-037

    public function test_the_time_zone_city_is_written_in_the_members_language(): void
    {
        $this->skipUnlessIntl();
        config(['app.timezone' => 'Europe/Paris']);

        foreach (['en' => 'Paris', 'ar' => 'باريس', 'ru' => 'Париж', 'ja' => 'パリ', 'zh' => '巴黎', 'ko' => '파리', 'th' => 'ปารีส'] as $locale => $city) {
            app()->setLocale($locale);
            $this->assertSame($city, ZoneLabel::city(), $locale);
        }

        app()->setLocale('es');
        config(['app.timezone' => 'America/New_York']);
        $this->assertSame('Nueva York', ZoneLabel::city());

        // zones without a city keep their own name
        foreach (['UTC' => 'UTC', 'Etc/GMT+5' => 'GMT+5'] as $zone => $name) {
            config(['app.timezone' => $zone]);
            foreach (['en', 'ar', 'fr'] as $locale) {
                app()->setLocale($locale);
                $this->assertSame($name, ZoneLabel::city(), $zone.' '.$locale);
            }
        }

        app()->setLocale('ar');
        config(['app.timezone' => 'Europe/Paris']);
        $this->assertStringContainsString('باريس', ZoneLabel::zoneLine(CarbonImmutable::parse('2026-10-29 19:00', 'Europe/Paris')));

        // the zone sentence carries its own full stop (the member's language: "。" in Japanese and Chinese, "।" in Hindi,
        // none in Thai): neither the page nor the script adds a Latin "." after it any more, and the script's changed
        // date keeps the page's calendar and digits (the Thai page turned "2026" into the Buddhist "2569")
        app()->setLocale('en');
        config(['site.date_format' => 'M d, Y']);
        $this->assertSame('Paris time (GMT+1 on Oct 29).', ZoneLabel::zoneLine(CarbonImmutable::parse('2026-10-29 19:00', 'Europe/Paris')));
        // (1.2.3 round 3, R3-086: the sentence is printed once with its changing parts marked, which the script rewrites)
        $form = File::get(resource_path('views/users/videos/partials/form.blade.php'));
        $this->assertStringContainsString("\$mark(__(':city time (:offset on :date).', ['city' => ZoneLabel::city()]), [':offset' => '<span data-o>'", $form);
        $this->assertStringContainsString('<span aria-atomic="true">{!! $zoneHtml !!}</span>', $form);
        $script = File::get(resource_path('js/member/studio-pages.js'));
        $this->assertStringNotContainsString("+ (full ? '' : '.')", $script);
        $this->assertSame(2, substr_count($script, "calendar: 'gregory', numberingSystem: 'latn'"));
    }

    // ------------------------------------------------------------------------------------------------- R2A-012

    public function test_titles_and_names_that_are_cut_take_their_own_direction(): void
    {
        $latin = 'Tokyo night walk in the rain - 4K binaural city ambience';
        $video = $this->video(['title' => $latin]);
        $member = $this->member;
        $_SERVER['DETECTED_LOCALE'] = 'ar';

        $list = $this->actingAs($member)->get(route('user.videos.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<a class="mk-row__title mk-clamp-2" href="[^"]+" dir="auto">'.preg_quote(e($latin), '~').'</a>~u', $list, 'My videos row');
        $this->assertStringContainsString('<strong class="mk-clamp-2" dir="auto" x-text="current.title"></strong>', $list, 'the delete dialog');

        foreach ([route('user.videos.show', $video), route('user.videos.edit', $video)] as $url) {
            $page = $this->actingAs($member)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('~<ol class="mk-crumbs">.*?<(a|span)[^>]* dir="auto">'.preg_quote(e($latin), '~').'</\1>~su', $page, 'the title crumb: '.$url);
            $this->assertStringContainsString('<h1 class="mk-head__title mk-clamp-2" dir="auto">'.e($latin).'</h1>', $page, 'the head: '.$url);
            $this->assertStringNotContainsString('<bdi>'.e($latin).'</bdi>', $page, 'dir="auto" reads the text itself (it skips a <bdi>)');
        }

        $dashboard = $this->actingAs($member)->get(route('user.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<a class="mk-item__title mk-clamp-2" href="[^"]+" dir="auto">'.preg_quote(e($latin), '~').'</a>~u', $dashboard, 'latest videos');

        // the confirm dialogs that print a member-typed title or text: Video details' "Delete this video?", the
        // Dashboard's failed-upload delete, the Playlists delete and the replies' "Remove this reply?" (the Comments one:
        // the next test)
        $edit = $this->actingAs($member)->get(route('user.videos.edit', $video))->assertOk()->getContent();
        $this->assertStringContainsString('<strong class="mk-clamp-2" dir="auto">'.e($latin).'</strong>', $edit, 'the Video details delete dialog');
        $this->assertStringContainsString('<strong data-mk-title dir="auto"></strong>', $dashboard, 'the failed-upload delete dialog');
        $this->assertStringContainsString('<strong data-mk-title dir="auto"></strong>', File::get(resource_path('views/users/playlists/index.blade.php')), 'the playlist delete dialog');
        $this->assertStringContainsString('<p class="mk-sheet__lead mk-clamp-3" data-mk-title dir="auto"></p>', File::get(resource_path('views/users/comments/partials/remove-reply.blade.php')), 'the reply remove dialog');

        // the kit: such a title's box sits at the page's start, its lines in its own direction; "on <title>" lines
        $kit = File::get(resource_path('sass/member/_kit.scss'));
        $this->assertStringContainsString(':root :is(.mk-head__title, .mk-row__title, .mk-item__title, .mk-clamp-2)[dir="auto"]:not([data-mk-clamp]) {', $kit);
        $this->assertStringContainsString(':root .mk-ref-line > :is(.mk-ref-line__fit, .mk-ref-line__name) {', $kit);
    }

    public function test_the_on_title_lines_cut_the_title_at_its_own_end(): void
    {
        $latin = 'Rooftop session: acoustic covers of night songs';
        $video = $this->video(['title' => $latin]);
        $fan = User::factory()->create(['username' => 'r2cs_fan']);
        \App\Models\Comment::query()->create(['video_id' => $video->id, 'user_id' => $fan->id, 'parent_id' => null, 'content' => 'First! Loved the colours.']);

        $comments = $this->actingAs($this->member)->get(route('user.comments.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<span class="mk-ref-line">on <bdi class="mk-ref-line__fit" dir="auto">'.e($latin).'</bdi></span>', $comments);
        // "Remove this comment?" prints the comment in its own direction too ("First! Loved the colours." keeps its full
        // stop at its end in the Arabic studio)
        $this->assertStringContainsString('<p class="mk-sheet__lead mk-clamp-3" data-mk-title dir="auto"></p>', $comments, 'the comment remove dialog');
    }

    public function test_the_reports_line_lets_a_long_name_give_way_as_well(): void
    {
        // R2CS-RV-01: "comment by <name> on <title>" in Reports. A member's name may have 40 characters: it shares the
        // room with the title (each cut at its own end, in its own direction) instead of running out of the column
        $latin = 'Rooftop session: acoustic covers of night songs';
        $name = 'night_drive_archive_official_channel_tv1';
        $video = $this->video(['title' => $latin]);
        $author = User::factory()->create(['username' => $name]);
        $comment = \App\Models\Comment::query()->create(['video_id' => $video->id, 'user_id' => $author->id, 'parent_id' => null, 'content' => 'Spam link in every reply.']);
        \App\Models\Report::query()->create(['user_id' => $this->member->id, 'reportable_type' => \App\Models\Comment::class, 'reportable_id' => $comment->id, 'reason' => 1, 'status' => \App\Enums\ReportStatus::PENDING]);
        $_SERVER['DETECTED_LOCALE'] = 'ar';

        $page = $this->actingAs($this->member)->get(route('user.reports.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<span class="mk-muted mk-ref-line"[^>]*>[^<]*<bdi class="mk-ref-line__name" dir="auto">'.$name.'</bdi>[^<]*<a class="mk-quiet-link mk-ref-line__fit" href="[^"]+" dir="auto">'.preg_quote(e($latin), '~').'</a></span>~u', $page);

        $kit = File::get(resource_path('sass/member/_kit.scss'));
        $this->assertMatchesRegularExpression('~:root \.mk-ref-line \{\s*display: flex;\s*flex-wrap: wrap;~', $kit, 'words that cannot fit wrap: nothing runs out, nothing is clipped');
        $this->assertMatchesRegularExpression('~:root \.mk-ref-line > :is\(\.mk-ref-line__fit, \.mk-ref-line__name\) \{\s*flex: 1 1 0;\s*min-inline-size: 0;\s*max-inline-size: max-content;\s*overflow: hidden;\s*text-overflow: ellipsis;~', $kit, 'name and title grow from nothing up to their own text and are cut at their own end');
        $this->assertMatchesRegularExpression('~:root \.mk-ref-line > \.mk-ref-line__name \{\s*flex-grow: 2;\s*\}~', $kit, 'the name gets up to two thirds of the room');
    }

    // ------------------------------------------------------------------------------------------------- R2A-011

    public function test_the_select_all_boxes_also_say_when_only_some_rows_are_ticked(): void
    {
        $this->video(['title' => 'Sunset drive']);
        $list = $this->actingAs($this->member)->get(route('user.videos.index'))->assertOk()->getContent();
        $this->assertStringContainsString('x-effect="$el.indeterminate = selected.length > 0 && selected.length < rows"', $list);

        foreach (['users/comments/index.blade.php', 'users/playlists/index.blade.php'] as $view) {
            $this->assertStringContainsString('x-effect="$el.indeterminate = selected.length > 0 && selected.length < boxes().length"', File::get(resource_path('views/'.$view)), $view);
        }

        // phones: the bar keeps its select-all box (member/_kit.scss no longer hides it below 576px)
        $kit = File::get(resource_path('sass/member/_kit.scss'));
        $this->assertStringNotContainsString(":root .mk-bulkbar .mk-bulkbar__all,\n", $kit);
        $this->assertStringContainsString(':root .mk-bulkbar .mk-bulkbar__all { order: 0;', $kit);
    }

    // ------------------------------------------------------------------------------------------------- R1-026

    public function test_the_first_row_menu_is_laid_out_in_an_idle_moment_after_load(): void
    {
        $script = File::get(resource_path('js/member/studio-pages.js'));

        $this->assertStringContainsString('const warm = () => {', $script);
        $this->assertStringContainsString("window.requestIdleCallback(warm, {timeout: 3000});", $script);
        $this->assertStringContainsString("menu.setAttribute('aria-hidden', 'true');", $script);
        $this->assertMatchesRegularExpression('~void menu\.offsetHeight;\s*menu\.remove\(\);~', $script, 'laid out and removed in the same task');
    }

    // ------------------------------------------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function draftForm(string $title): array
    {
        return ['action' => 'draft', 'title' => $title, 'description' => 'Typed on the draft page.', 'status' => VideoStatus::PUBLIC->value,
            'allow_comments' => 1, 'default_comments_sort' => 'top', 'show_likes' => 1, 'show_ad' => 1];
    }

    /** A check the page's script sends: JSON, X-Requested-With, from the page (Sanctum's stateful site), no background header. */
    private function poll(string $uri, string $from): TestResponse
    {
        $this->freshStore();
        config(['sanctum.stateful' => [(string) parse_url(url('/'), PHP_URL_HOST).(parse_url(url('/'), PHP_URL_PORT) ? ':'.parse_url(url('/'), PHP_URL_PORT) : '')]]);

        return $this->call('GET', $uri, [], $this->prepareCookiesForRequest(), [], $this->transformHeadersToServerVars([
            'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', 'Referer' => $from,
        ]));
    }

    /** Sessions in files, a new store for every request (as a real site): the test client otherwise keeps one in memory. */
    private function fileSessions(): string
    {
        $this->sessions = storage_path('framework/testing/sessions-r2cs-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($this->sessions);
        config(['session.driver' => 'file', 'session.files' => $this->sessions, 'session.lottery' => [0, 100]]);
        $this->freshStore();

        return Str::random(40);
    }

    private function freshStore(): void
    {
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app['cookie']->flushQueuedCookies();
    }

    private function sessionFile(string $id): string
    {
        return $this->sessions.'/'.$id;
    }

    /** @return list<string> */
    private function sessionIds(TestResponse $response): array
    {
        $cookie = $response->getCookie((string) config('session.cookie'));

        return $cookie === null ? [] : [(string) $cookie->getValue()];
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'not found: '.$from);
        $end = strpos($html, $to, (int) $start + strlen($from));

        return substr($html, (int) $start, $end === false ? null : $end - (int) $start);
    }

    /** @return array<string, mixed> */
    private function rowsJson(string $html, string $id): array
    {
        preg_match('~<script type="application/json" id="'.preg_quote($id, '~').'">(.*?)</script>~s', $html, $m);

        return json_decode($m[1] ?? 'null', true) ?? [];
    }

    private function skipUnlessIntl(): void
    {
        if (! class_exists(\IntlDatePatternGenerator::class)) {
            $this->markTestSkipped('intl is not installed');
        }
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
