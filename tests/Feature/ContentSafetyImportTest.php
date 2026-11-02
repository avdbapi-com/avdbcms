<?php

namespace Tests\Feature;

use App\Jobs\RunAvdbImport;
use App\Jobs\RunXvidImport;
use App\Models\AvdbImportRun;
use App\Models\AvdbImportSchedule;
use App\Models\Category;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\TagTranslation;
use App\Models\Thumbnail;
use App\Models\User;
use App\Models\Video;
use App\Models\XvidImportRun;
use App\Support\ContentSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 1.2.3 (lane KS): the AVDBAPI and XVIDAPI importers never import an item whose title, tags or categories use a blocked
 * word (App\Support\ContentSafety: the hard list, always, and the site's list, the default list until the owner saves
 * one): it is skipped before anything is written (no video, no tag, no category, no tag translation, no thumbnail
 * download), counted with the skipped items and named in the run log ("Skipped 4 videos: blocked words.") by its number
 * only. Every way in is covered: category pages, ID lists, dry runs, an existing video's update, the queue jobs (manual
 * runs from the admin) and the scheduled runs, which all run these two commands.
 */
class ContentSafetyImportTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        ContentSafety::forget();
        Storage::fake('local');
        User::factory()->create(['id' => 1]);
    }

    public function test_an_avdb_category_page_imports_the_clean_items_and_skips_the_blocked_ones_before_any_write(): void
    {
        $this->fakeApis($this->avdbPage(), []);
        $run = AvdbImportRun::create(['status' => 'queued', 'settings' => ['categories' => [1], 'pages' => 1, 'pagesize' => 10, 'thumbnail_mode' => 'download', 'delay' => 0]]);

        $this->artisan('avdb:import', ['--run-id' => $run->id])->assertExitCode(0);

        $this->assertEqualsCanonicalizing([1001, 1006], Video::query()->pluck('avdb_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertBlockedWordsNeverWritten();
        $this->assertEqualsCanonicalizing(['Drama', 'Featured', 'Massage'], Tag::query()->pluck('name')->all());
        $this->assertSame(2, Thumbnail::query()->count(), 'the two clean posters only');
        $this->assertPostersNotFetched([1002, 1003, 1004, 1005]);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, (int) $run->imported);
        $this->assertSame(4, (int) $run->skipped, 'blocked items count as skipped');
        $this->assertSame(0, (int) $run->failed);
        $this->assertStringContainsString('Skipped 4 videos: blocked words.', (string) $run->message);
        $this->assertStringContainsString('Skipped 4 videos: blocked words.', (string) $run->log);
        $this->assertStringContainsString('AVDB 1003 (blocked words)', (string) $run->log);
        $this->assertLogNeverRepeatsTheWords((string) $run->log);
    }

    public function test_an_avdb_id_list_skips_the_blocked_ids_without_counting_them_as_failed(): void
    {
        $this->fakeApis($this->avdbPage(), []);
        $run = AvdbImportRun::create(['status' => 'queued', 'settings' => ['ids' => '1001,1002,1003,1004,1005,1006', 'thumbnail_mode' => 'download', 'delay' => 0]]);

        $this->artisan('avdb:import', ['--run-id' => $run->id])->assertExitCode(0);

        $this->assertEqualsCanonicalizing([1001, 1006], Video::query()->pluck('avdb_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertBlockedWordsNeverWritten();
        $this->assertPostersNotFetched([1002, 1003, 1004, 1005]);
        $run->refresh();
        $this->assertSame([2, 4, 0], [(int) $run->imported, (int) $run->skipped, (int) $run->failed]);
        $this->assertStringContainsString('Skipped 4 videos: blocked words.', (string) $run->log);
    }

    public function test_an_avdb_dry_run_counts_the_blocked_items_and_writes_nothing(): void
    {
        $this->fakeApis($this->avdbPage(), []);
        $run = AvdbImportRun::create(['status' => 'queued', 'settings' => ['categories' => [1], 'pages' => 1, 'pagesize' => 10, 'dry_run' => true, 'delay' => 0]]);

        $this->artisan('avdb:import', ['--run-id' => $run->id])->assertExitCode(0);

        $this->assertSame(0, Video::query()->count());
        $this->assertSame(0, Tag::query()->count());
        $run->refresh();
        $this->assertSame([2, 4], [(int) $run->imported, (int) $run->skipped], 'the dry run says what would happen');
        $this->assertStringContainsString('Skipped 4 videos: blocked words.', (string) $run->message);
    }

    public function test_an_existing_avdb_video_is_not_updated_from_an_item_that_now_uses_a_blocked_word(): void
    {
        $video = Video::factory()->create(['user_id' => 1, 'avdb_id' => 1004, 'title' => 'Kept title', 'avdb_content_hash' => 'an-old-hash']);
        $this->fakeApis([$this->avdbItem(1004, ['name' => 'A new name', 'tag' => 'Drama, Voyeur'])], []);

        $this->artisan('avdb:import', ['--ids' => '1004', '--skip-thumbs' => true])->assertExitCode(0);

        $this->assertSame('Kept title', $video->fresh()->title);
        $this->assertSame('an-old-hash', $video->fresh()->avdb_content_hash);
        $this->assertSame(0, Tag::query()->count());
    }

    public function test_the_owners_list_replaces_the_default_list_and_the_hard_list_stays(): void
    {
        Setting::set(ContentSafety::SETTING, "Massage\n", ContentSafety::SETTING_GROUP);
        $this->fakeApis($this->avdbPage(), []);

        $this->artisan('avdb:import', ['--category' => '1', '--pages' => '1', '--pagesize' => '10', '--skip-thumbs' => true, '--delay' => 0])->assertExitCode(0);

        // Massage is the owner's word now; voyeur and the leaked category are allowed again; the hard list is always on.
        $this->assertEqualsCanonicalizing([1001, 1004, 1005], Video::query()->pluck('avdb_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertNull(Tag::query()->where('name', 'Massage')->first());
        $this->assertNotNull(Tag::query()->where('name', 'Voyeur')->first());
        $this->assertNull(Tag::query()->where('name', 'JK Girl')->first());
    }

    public function test_an_owner_who_saved_an_empty_list_keeps_only_the_hard_list(): void
    {
        Setting::set(ContentSafety::SETTING, '', ContentSafety::SETTING_GROUP);
        $this->fakeApis($this->avdbPage(), []);

        $this->artisan('avdb:import', ['--category' => '1', '--pages' => '1', '--pagesize' => '10', '--skip-thumbs' => true, '--delay' => 0])->assertExitCode(0);

        $this->assertEqualsCanonicalizing([1001, 1004, 1005, 1006], Video::query()->pluck('avdb_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_the_admins_manual_run_and_a_scheduled_run_go_through_the_same_guard(): void
    {
        $this->fakeApis($this->avdbPage(), []);

        // A manual run of the admin: the queue job runs the command with its run.
        $manual = AvdbImportRun::create(['status' => 'queued', 'settings' => ['categories' => [1], 'pages' => 1, 'pagesize' => 10, 'delay' => 0]]);
        (new RunAvdbImport($manual->id))->handle();
        $this->assertSame([2, 4], [(int) $manual->fresh()->imported, (int) $manual->fresh()->skipped]);

        // A scheduled run: the scheduler queues a run of the schedule's settings, the worker runs the same job.
        Video::query()->delete();
        $_ENV['AVDB_IMPORT_MODE'] = 'queue';

        try {
            Queue::fake();
            $schedule = AvdbImportSchedule::create([
                'name' => 'Nightly', 'enabled' => true, 'frequency' => 'daily', 'run_time' => '03:00',
                'settings' => ['categories' => [1], 'pages' => 1, 'pagesize' => 10, 'delay' => 0], 'skip_if_active' => false,
            ]);
            $this->artisan('avdb:schedules:run', ['--id' => $schedule->id])->assertExitCode(0);
            $scheduled = AvdbImportRun::query()->whereKeyNot($manual->id)->latest('id')->firstOrFail();
            Queue::assertPushed(RunAvdbImport::class, fn (RunAvdbImport $job): bool => $job->runId === $scheduled->id);
            (new RunAvdbImport($scheduled->id))->handle();
        } finally {
            unset($_ENV['AVDB_IMPORT_MODE']);
        }

        $scheduled->refresh();
        $this->assertSame([2, 4], [(int) $scheduled->imported, (int) $scheduled->skipped]);
        $this->assertStringContainsString('Skipped 4 videos: blocked words.', (string) $scheduled->message);
        $this->assertBlockedWordsNeverWritten();
    }

    public function test_an_xvid_category_page_imports_the_clean_items_and_skips_the_blocked_ones_before_any_write(): void
    {
        $this->fakeApis([], $this->xvidPage());
        $run = XvidImportRun::create(['status' => 'queued', 'settings' => ['categories' => [65], 'pages' => 1, 'pagesize' => 10, 'translate_tags' => true]]);

        $this->artisan('xvid:import', ['--run-id' => $run->id])->assertExitCode(0);

        $this->assertEqualsCanonicalizing([2001, 2004], Video::query()->pluck('xvid_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertBlockedWordsNeverWritten();
        $this->assertPostersNotFetched([2002, 2003, 2005, 2006]);
        // the tag translations are asked for the clean items only, and only clean tags get them
        $asked = $this->recordedIds(fn (HttpRequest $request): bool => str_contains($request->url(), 'xvidapi.com') && str_contains($request->url(), 'language='));
        $this->assertNotSame([], $asked, 'the site languages ask for translated tags');
        $this->assertSame([], array_values(array_intersect($asked, [2002, 2003, 2005, 2006])));
        $this->assertSame(0, TagTranslation::query()->whereNotIn('tag_id', Tag::query()->pluck('id'))->count());
        $this->assertNull(Category::query()->where('title', 'Schoolgirl Club')->first(), 'no category of a blocked item');

        $run->refresh();
        $this->assertSame('finished', $run->status);
        $this->assertSame([2, 4, 0], [(int) $run->imported, (int) $run->skipped, (int) $run->failed]);
        $this->assertStringContainsString('Skipped 4 videos: blocked words.', (string) $run->message);
        $this->assertStringContainsString('Skipped 4 videos: blocked words.', (string) $run->log);
        $this->assertLogNeverRepeatsTheWords((string) $run->log);
    }

    public function test_an_xvid_id_list_and_a_dry_run_skip_the_blocked_items(): void
    {
        $this->fakeApis([], $this->xvidPage());
        $run = XvidImportRun::create(['status' => 'queued', 'settings' => ['ids' => '2001,2002,2003,2004,2005,2006', 'skip_thumbs' => true]]);

        $this->artisan('xvid:import', ['--run-id' => $run->id])->assertExitCode(0);

        $this->assertEqualsCanonicalizing([2001, 2004], Video::query()->pluck('xvid_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertBlockedWordsNeverWritten();
        $this->assertSame([2, 4], [(int) $run->fresh()->imported, (int) $run->fresh()->skipped]);

        Video::query()->forceDelete();
        Tag::query()->delete();
        $dry = XvidImportRun::create(['status' => 'queued', 'settings' => ['categories' => [65], 'pages' => 1, 'pagesize' => 10, 'dry_run' => true]]);
        (new RunXvidImport($dry->id))->handle();

        $this->assertSame(0, Video::query()->count());
        $this->assertSame(0, Tag::query()->count());
        $this->assertSame([2, 4], [(int) $dry->fresh()->imported, (int) $dry->fresh()->skipped]);
    }

    /**
     * Round 2 (the reviewer's KS-R02): "Fetch content in" another language with "Require real translation" off lists the
     * pages in that language. The English details of the page's items are checked too, before anything else: an item
     * whose English tags use a word of the lists is skipped although its Vietnamese words do not, an item whose English
     * details do not arrive is not imported this run (failed, its words could not be checked), a Vietnamese word that is
     * always blocked is caught in its own language, and the clean item is imported.
     */
    public function test_an_avdb_page_listed_in_another_language_is_checked_in_english_before_anything_else(): void
    {
        $vietnamese = [
            $this->avdbItem(5001, ['name' => 'Cô gái sau giờ học', 'tag' => 'Đồng phục', 'category' => ['Đồng phục']]),
            $this->avdbItem(5002, ['name' => 'Một câu chuyện', 'tag' => 'Kịch', 'category' => ['Kịch']]),
            $this->avdbItem(5003, ['name' => 'Nữ sinh dâm đãng', 'tag' => 'Kịch', 'category' => ['Kịch']]),
            $this->avdbItem(5004, ['name' => 'Không có bản tiếng Anh', 'tag' => 'Kịch', 'category' => ['Kịch']]),
        ];
        $english = [
            $this->avdbItem(5001, ['name' => 'A girl after class', 'tag' => 'Schoolgirl, Uniform', 'category' => ['Schoolgirl', 'Uniform']]),
            $this->avdbItem(5002, ['name' => 'A clean story', 'tag' => 'Drama', 'category' => ['Drama']]),
            $this->avdbItem(5003, ['name' => 'A story', 'tag' => 'Drama', 'category' => ['Drama']]),
        ];
        Http::fake(function (HttpRequest $request) use ($english, $vietnamese) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $only = isset($query['ids']) ? array_map('intval', explode(',', (string) $query['ids'])) : null;
            $list = str_contains($url, 'avdbapi.com/vi/') ? $vietnamese : (str_contains($url, 'avdbapi.com') ? $english : null);

            if ($list === null) {
                return Http::response('', 404);
            }

            $list = array_values(array_filter($list, fn (array $item): bool => $only === null || in_array((int) $item['id'], $only, true)));

            return Http::response(['code' => 1, 'total' => count($list), 'pagecount' => 1, 'list' => $list], 200);
        });
        $run = AvdbImportRun::create(['status' => 'queued', 'settings' => [
            'categories' => [1], 'pages' => 1, 'pagesize' => 10, 'api_lang' => 'vi', 'translated_only' => false,
            'thumbnail_mode' => 'remote', 'delay' => 0,
        ]]);

        $this->artisan('avdb:import', ['--run-id' => $run->id])->assertExitCode(0);

        $this->assertSame([5002], Video::query()->pluck('avdb_id')->map(fn ($id): int => (int) $id)->all());
        $askedInEnglish = $this->recordedIds(fn (HttpRequest $request): bool => str_contains($request->url(), 'avdbapi.com/api.php') && str_contains($request->url(), 'ids='));
        $this->assertEqualsCanonicalizing([5001, 5002, 5004], $askedInEnglish, 'the English details of the page, after the Vietnamese words were checked');
        $run->refresh();
        $this->assertSame([1, 2, 1], [(int) $run->imported, (int) $run->skipped, (int) $run->failed]);
        $this->assertStringContainsString('Skipped 2 videos: blocked words.', (string) $run->message);
        $this->assertStringContainsString('Not imported this time: 1 video whose English details did not arrive', (string) $run->log);
        $this->assertNull(Tag::query()->where('name', 'Đồng phục')->first(), 'nothing of a blocked item is written');
    }

    /**
     * Round 2: the texts of other languages fetched for an item (here the Japanese page of a "Fetch content in Japanese"
     * run, which carries the original title) are checked too: an item whose Japanese title names a high school girl is
     * skipped before anything is written, although its English words are clean.
     */
    public function test_an_avdb_item_whose_fetched_translation_names_a_minor_is_skipped(): void
    {
        $english = [
            $this->avdbItem(5101, ['name' => 'A clean story', 'tag' => 'Drama', 'category' => ['Drama']]),
            $this->avdbItem(5102, ['name' => 'Another clean story', 'tag' => 'Drama', 'category' => ['Drama']]),
        ];
        $japanese = [
            $this->avdbItem(5101, ['name' => '普通の物語', 'tag' => 'ドラマ', 'category' => ['ドラマ'], 'description' => '説明']),
            $this->avdbItem(5102, ['name' => '女子高生の放課後', 'tag' => 'ドラマ', 'category' => ['ドラマ'], 'description' => '説明']),
        ];
        Http::fake(function (HttpRequest $request) use ($english, $japanese) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $only = isset($query['ids']) ? array_map('intval', explode(',', (string) $query['ids'])) : null;
            $list = str_contains($url, 'avdbapi.com/ja/') ? $japanese : (str_contains($url, 'avdbapi.com') ? $english : null);

            if ($list === null) {
                return Http::response('', 404);
            }

            $list = array_values(array_filter($list, fn (array $item): bool => $only === null || in_array((int) $item['id'], $only, true)));

            return Http::response(['code' => 1, 'total' => count($list), 'pagecount' => 1, 'list' => $list], 200);
        });

        $this->artisan('avdb:import', [
            '--category' => '1', '--pages' => '1', '--pagesize' => '10', '--api-lang' => 'ja', '--translated-only' => true,
            '--skip-thumbs' => true, '--delay' => 0,
        ])->assertExitCode(0);

        $this->assertSame([5101], Video::query()->pluck('avdb_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('video_translations')->where('title', 'like', '%女子高生%')->count());
    }

    /**
     * Round 2: a tag name of another language that uses a word that is always blocked (a translation of a clean English
     * tag) is never stored: the tag keeps its own name in that language; the other translations are stored.
     */
    public function test_a_tag_name_of_another_language_that_names_a_minor_is_never_stored(): void
    {
        $page = [$this->xvidItem(2101, ['name' => 'Clean xvid', 'tag' => 'Amateur,Student'])];
        Http::fake(function (HttpRequest $request) use ($page) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (! str_contains($url, 'xvidapi.com')) {
                return Http::response('', 404);
            }

            $list = $page;

            if (isset($query['language'])) {
                // the provider's translation of "Student" names a school pupil in Thai
                $list = array_map(fn (array $item): array => $item + [
                    'source_vod_tag' => $item['tag'],
                    'localized_vod_tag' => $query['language'] === 'th' ? 'มือสมัครเล่น,นักเรียน' : 'Amateur ('.$query['language'].'),Student ('.$query['language'].')',
                ], $list);
            }

            return Http::response(['code' => 1, 'total' => count($list), 'pagecount' => 1, 'list' => $list], 200);
        });
        \App\Models\Language::query()->updateOrCreate(['code' => 'th'], ['label' => 'Thai', 'is_active' => true]);
        $run = XvidImportRun::create(['status' => 'queued', 'settings' => ['categories' => [65], 'pages' => 1, 'pagesize' => 10, 'translate_tags' => true, 'skip_thumbs' => true]]);

        $this->artisan('xvid:import', ['--run-id' => $run->id])->assertExitCode(0);

        $student = Tag::query()->where('name', 'Student')->firstOrFail();
        $amateur = Tag::query()->where('name', 'Amateur')->firstOrFail();
        $this->assertNull(TagTranslation::query()->where('tag_id', $student->id)->where('locale', 'th')->first(), 'never stored');
        $this->assertSame('มือสมัครเล่น', TagTranslation::query()->where('tag_id', $amateur->id)->where('locale', 'th')->value('name'));
        $this->assertSame(0, TagTranslation::query()->where('name', 'like', '%นักเรียน%')->count());
    }

    /**
     * Round 2: the translation backfill of existing AVDBAPI videos (avdb:translate) never stores a translation that uses
     * a word that is always blocked; the other languages are stored.
     */
    public function test_the_translation_backfill_never_stores_a_translation_that_names_a_minor(): void
    {
        $video = Video::factory()->create(['user_id' => 1, 'avdb_id' => 5201, 'title' => 'A clean story']);
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();

            if (str_contains($url, 'avdbapi.com/ja/')) {
                return Http::response(['code' => 1, 'total' => 1, 'pagecount' => 1, 'list' => [$this->avdbItem(5201, ['name' => '女子高生の物語'])]], 200);
            }

            if (str_contains($url, 'avdbapi.com/vi/')) {
                return Http::response(['code' => 1, 'total' => 1, 'pagecount' => 1, 'list' => [$this->avdbItem(5201, ['name' => 'Một câu chuyện'])]], 200);
            }

            return Http::response('', 404);
        });

        $this->artisan('avdb:translate', ['--locales' => 'ja,vi', '--video-ids' => (string) $video->id, '--concurrency' => 1])->assertExitCode(0);

        $titles = \Illuminate\Support\Facades\DB::table('video_translations')->where('video_id', $video->id)->pluck('title', 'locale')->all();
        $this->assertSame(['vi' => 'Một câu chuyện'], $titles);
    }

    /** The posters of these items were never downloaded. */
    private function assertPostersNotFetched(array $ids): void
    {
        foreach ($ids as $id) {
            Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'img.test/'.$id.'.jpg'));
        }
    }

    /** No video, tag or category named with a word of the default list or the hard list exists. */
    private function assertBlockedWordsNeverWritten(): void
    {
        $matcher = ContentSafety::forImports();

        foreach (Video::query()->withTrashed()->pluck('title') as $title) {
            $this->assertFalse($matcher->blocks((string) $title), 'video '.$title);
        }

        foreach (Tag::query()->pluck('name') as $name) {
            $this->assertFalse($matcher->blocks((string) $name), 'tag '.$name);
        }

        foreach (Category::query()->pluck('title') as $title) {
            $this->assertFalse($matcher->blocks((string) $title), 'category '.$title);
        }
    }

    /** The run log names a skipped item by its number, never by the words that blocked it. */
    private function assertLogNeverRepeatsTheWords(string $log): void
    {
        foreach (['schoolgirl', 'JK Girl', 'Voyeur', 'Loli', 'Hidden camera', 'Schoolgirl Club'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $log);
        }
    }

    /** @return list<int> the ids the matching requests asked for */
    private function recordedIds(callable $filter): array
    {
        $ids = [];

        foreach (Http::recorded() as [$request]) {
            if ($filter($request)) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $ids = array_merge($ids, array_map('intval', explode(',', (string) ($query['ids'] ?? ''))));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<array<string, mixed>>  $avdb
     * @param  list<array<string, mixed>>  $xvid
     */
    private function fakeApis(array $avdb, array $xvid): void
    {
        Http::fake(function (HttpRequest $request) use ($avdb, $xvid) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $only = isset($query['ids']) ? array_map('intval', explode(',', (string) $query['ids'])) : null;
            $pick = fn (array $items): array => array_values(array_filter($items, fn (array $item): bool => $only === null || in_array((int) $item['id'], $only, true)));

            if (str_contains($url, 'avdbapi.com')) {
                $list = $pick($avdb);

                return Http::response(['code' => 1, 'total' => count($list), 'pagecount' => 1, 'list' => $list], 200);
            }

            if (str_contains($url, 'xvidapi.com')) {
                $list = $pick($xvid);

                if (isset($query['language'])) {
                    // a localized answer: the tags in that language, in the same order
                    $list = array_map(fn (array $item): array => $item + [
                        'source_vod_tag' => $item['tag'],
                        'localized_vod_tag' => implode(',', array_map(fn (string $tag): string => $tag.' ('.$query['language'].')', explode(',', $item['tag']))),
                    ], $list);
                }

                return Http::response(['code' => 1, 'total' => count($list), 'pagecount' => 1, 'list' => $list], 200);
            }

            if (str_contains($url, 'img.test/')) {
                return Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('', 404);
        });
    }

    /** @return list<array<string, mixed>> two clean items and four blocked ones (title, tag, default tag, category) */
    private function avdbPage(): array
    {
        return [
            $this->avdbItem(1001, ['name' => 'Clean title one', 'tag' => 'Drama, Featured']),
            $this->avdbItem(1002, ['name' => 'A schoolgirl story']),
            $this->avdbItem(1003, ['tag' => 'Drama, JK Girl']),
            $this->avdbItem(1004, ['tag' => 'Voyeur']),
            $this->avdbItem(1005, ['type_name' => 'Uncensored Leaked']),
            $this->avdbItem(1006, ['name' => 'Clean title two', 'tag' => 'Massage']),
        ];
    }

    private function avdbItem(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => 'Item '.$id.' title',
            'slug' => 'item-'.$id,
            'movie_code' => 'ITEM-'.$id,
            'description' => 'A description.',
            'type_id' => 1,
            'type_name' => 'Censored',
            'country' => 'Japan',
            'tag' => 'Drama',
            'category' => ['Drama'],
            'time' => '00:20:00',
            'vod_pubdate' => '2026-10-01',
            'vod_time' => '2026-10-02 00:00:00',
            'poster_url' => 'https://img.test/'.$id.'.jpg',
            'episodes' => ['server_data' => 'item-'.$id],
        ], $overrides);
    }

    /** @return list<array<string, mixed>> two clean items and four blocked ones (tag, title, origin name, category) */
    private function xvidPage(): array
    {
        return [
            $this->xvidItem(2001, ['name' => 'Clean xvid one', 'tag' => 'Amateur,Outdoor']),
            $this->xvidItem(2002, ['tag' => 'Amateur,Loli']),
            $this->xvidItem(2003, ['name' => 'Hidden camera in the gym']),
            $this->xvidItem(2004, ['name' => 'Clean xvid two', 'tag' => 'Blonde']),
            $this->xvidItem(2005, ['name' => '', 'origin_name' => 'JK after class']),
            $this->xvidItem(2006, ['type_name' => 'Schoolgirl Club']),
        ];
    }

    private function xvidItem(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => 'Xvid item '.$id,
            'slug' => 'xvid-item-'.$id,
            'description' => 'A description.',
            'type_name' => 'Teen',
            'tag' => 'Amateur',
            'category' => [],
            'time' => '00:12:00',
            'quality' => 'HD',
            'poster_url' => 'https://img.test/'.$id.'.jpg',
            'episodes' => ['server_data' => ['Full' => ['link_embed' => 'https://embed.test/v/'.$id]]],
        ], $overrides);
    }
}
