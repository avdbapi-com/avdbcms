<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Tag;
use App\Models\User;
use App\Models\Video;
use App\Services\SmartDiscoveryRailService;
use App\Support\AuditActions;
use App\Support\BlockedWordsPass;
use App\Support\ContentSafety;
use App\Support\TimeBudget;
use App\Support\VideoBans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 1.2.3 (lane KS): the update hides the videos a site already has whose title, tags or category use a word of the hard
 * list (App\Support\BlockedWordsPass, migration 2026_10_28_120000_hide_videos_with_blocked_words): banned with the
 * existing ban (each keeps its status for Unban) and the stored reason "Blocked words (1.2.3)"; words of the default list
 * leave existing videos alone; a video banned before keeps its ban and gets no reason; tags and categories named with a
 * hard word are hidden from every public list, menu and search (nothing deleted). The pass is idempotent, never bans a
 * video the admin unbanned again, works in slices and gets through 200,000 videos within a request of Finish. Tools >
 * Logs > Admin actions gets one row with the counts; Home says how many videos were hidden, with the review list,
 * until the admin dismisses it.
 */
class BlockedWordsPassTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    /** @var array<string, Video> */
    private array $videos = [];

    /** @var array<string, Category> */
    private array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        ContentSafety::forget();
        BlockedWordsPass::forget();
        TimeBudget::clear();
        // the migration ran on the empty database of the test: a new pass for the videos made here
        DB::table(BlockedWordsPass::PASSES)->delete();
    }

    protected function tearDown(): void
    {
        TimeBudget::clear();

        parent::tearDown();
    }

    public function test_the_update_bans_the_videos_with_hard_words_and_leaves_the_others(): void
    {
        $this->seedSite();
        $bannedBefore = $this->videos['admin_banned']->banned_at;

        $this->runPass();

        foreach (['hard_title' => VideoStatus::PUBLIC, 'hard_tag' => VideoStatus::PUBLIC, 'hard_category' => VideoStatus::PUBLIC, 'hard_private' => VideoStatus::PRIVATE, 'hard_trashed' => VideoStatus::PUBLIC] as $name => $before) {
            $video = Video::withTrashed()->findOrFail($this->videos[$name]->id);
            $this->assertSame(VideoStatus::BANNED, $video->status, $name.' is banned');
            $this->assertSame($before, VideoBans::statusAfterUnban($video), $name.' keeps its status for Unban');
            $this->assertSame(BlockedWordsPass::REASON, DB::table(BlockedWordsPass::REASONS)->where('video_id', $video->id)->value('reason'), $name.' has the reason');
        }

        // the default list does not change existing videos; a clean video stays as it was
        $this->assertSame(VideoStatus::PUBLIC, $this->videos['default_words']->fresh()->status);
        $this->assertSame(VideoStatus::PUBLIC, $this->videos['clean']->fresh()->status);
        // a video an admin banned before keeps its ban, its date, and gets no reason of the update
        $this->assertSame(VideoStatus::BANNED, $this->videos['admin_banned']->fresh()->status);
        $this->assertSame((string) $bannedBefore, (string) $this->videos['admin_banned']->fresh()->banned_at);
        $this->assertFalse(DB::table(BlockedWordsPass::REASONS)->where('video_id', $this->videos['admin_banned']->id)->exists());

        $state = BlockedWordsPass::state();
        $this->assertNotNull($state->finished_at);
        $this->assertSame([5, 1, 1], [(int) $state->videos_hidden, (int) $state->tags_hidden, (int) $state->categories_hidden]);
        $this->assertSame(4, BlockedWordsPass::hiddenCount(), 'the reviewable ones (the deleted video is banned too, not listed)');
        $this->assertFalse(BlockedWordsPass::due());

        // nothing is deleted: the hidden tag, the hidden category and their links stay
        $this->assertNotNull(Tag::query()->where('name', 'Loli')->first());
        $this->assertNotNull(Category::query()->where('title', 'Schoolgirl Club')->first());
        $this->assertTrue(DB::table('video_tags')->where('video_id', $this->videos['hard_tag']->id)->exists());
    }

    public function test_one_admin_actions_row_with_the_counts_in_the_admins_words(): void
    {
        $this->seedSite();

        $this->runPass();
        $this->runPass();

        $rows = DB::table('admin_audit_logs')->where('action', BlockedWordsPass::AUDIT_ACTION)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('1.2.3: 5 videos banned (reason "Blocked words (1.2.3)") because their titles, tags or categories use words that are always blocked; 1 tag and 1 category hidden from visitors', $rows[0]->details);
        $this->assertSame('Update: videos with blocked words hidden', AuditActions::label(BlockedWordsPass::AUDIT_ACTION));
    }

    public function test_the_pass_is_idempotent_and_never_bans_a_video_the_admin_unbanned_again(): void
    {
        $this->seedSite();
        $this->runPass();
        $reasons = DB::table(BlockedWordsPass::REASONS)->orderBy('video_id')->get()->map(fn ($row): array => (array) $row)->all();
        $statuses = Video::withTrashed()->orderBy('id')->pluck('status', 'id')->map(fn ($status): int => $status instanceof VideoStatus ? $status->value : (int) $status)->all();

        // the admin unbans one after review: it goes back to public and the notice counts one less
        VideoBans::unbanVideo($this->videos['hard_tag']->fresh());
        $this->assertSame(VideoStatus::PUBLIC, $this->videos['hard_tag']->fresh()->status);
        $this->assertSame(3, BlockedWordsPass::hiddenCount());

        // the update's step again (a second Finish, a second process, the scheduler): nothing changes
        (require base_path('database/migrations/2026_10_28_120000_hide_videos_with_blocked_words.php'))->up();
        BlockedWordsPass::start();
        $this->runPass();
        $this->artisan('videos:hide-blocked-words')->assertExitCode(0);

        $this->assertSame(VideoStatus::PUBLIC, $this->videos['hard_tag']->fresh()->status, 'never banned again');
        $this->assertSame($reasons, DB::table(BlockedWordsPass::REASONS)->orderBy('video_id')->get()->map(fn ($row): array => (array) $row)->all());
        $statuses[$this->videos['hard_tag']->id] = VideoStatus::PUBLIC->value;
        $this->assertSame($statuses, Video::withTrashed()->orderBy('id')->pluck('status', 'id')->map(fn ($status): int => $status instanceof VideoStatus ? $status->value : (int) $status)->all());
        $this->assertSame(5, (int) BlockedWordsPass::state()->videos_hidden);
    }

    public function test_the_pass_works_in_slices_and_two_processes_never_count_twice(): void
    {
        $this->creator = User::factory()->create(['email_verified_at' => now()]);
        $hard = $this->insertVideos((int) (BlockedWordsPass::CHUNK * 2.5), 50);
        BlockedWordsPass::start();

        // one slice per call: the tags (fewer than one slice), then BlockedWordsPass::CHUNK videos at a time
        $calls = 0;
        do {
            $result = BlockedWordsPass::run(0.0);
            $calls++;

            if ($calls === 2) {
                // a second process read the same place before the first one moved it: it does the same slice again
                DB::table(BlockedWordsPass::PASSES)->where('name', BlockedWordsPass::NAME)->update(['next_id' => 0]);
            }
        } while (! $result['done'] && $calls < 20);

        $this->assertTrue($result['done']);
        $this->assertSame(5, $calls, 'tags, then four slices of videos (one of them twice)');
        $this->assertEqualsCanonicalizing($hard, Video::query()->where('status', VideoStatus::BANNED->value)->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(count($hard), (int) BlockedWordsPass::state()->videos_hidden, 'counted once');
        $this->assertSame(count($hard), DB::table(BlockedWordsPass::REASONS)->count());
    }

    public function test_200000_videos_are_done_within_a_request_of_finish(): void
    {
        $this->creator = User::factory()->create(['email_verified_at' => now()]);
        $hard = $this->insertVideos(200000, 2000);
        BlockedWordsPass::start();

        // a request of Finish: 20 seconds with 5 in reserve, the update's step gets its share (BlockedWordsPass::SECONDS)
        TimeBudget::start(20.0, 5.0);
        $started = microtime(true);
        $result = BlockedWordsPass::run(TimeBudget::cap(BlockedWordsPass::SECONDS));
        $seconds = microtime(true) - $started;

        $this->assertTrue($result['done'], '200,000 videos in one request of Finish');
        $this->assertSame(200000, $result['checked']);
        $this->assertSame(count($hard), $result['hidden']);
        $this->assertLessThanOrEqual(BlockedWordsPass::SECONDS + 1.0, $seconds);
        fwrite(STDERR, sprintf("\n[KS] 200,000 videos: %.2f s in this test database\n", $seconds));
    }

    public function test_a_visit_of_home_goes_on_with_an_unfinished_pass_after_the_page(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        $this->seedSite();
        // the update's slice stopped after the tags (a big site), and no cron job runs
        BlockedWordsPass::start();
        BlockedWordsPass::run(0.0);
        $this->assertTrue(BlockedWordsPass::due());
        $this->assertSame(VideoStatus::PUBLIC, $this->videos['hard_title']->fresh()->status);

        $this->actingAs($admin)->get(route('admin.index'))->assertOk();

        $this->assertFalse(BlockedWordsPass::due(), 'the visit finished the pass after the page was sent');
        $this->assertSame(VideoStatus::BANNED, $this->videos['hard_title']->fresh()->status);
        $this->assertSame(4, BlockedWordsPass::hiddenCount());
    }

    public function test_home_says_how_many_videos_were_hidden_until_the_admin_dismisses_it(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertDontSee('hidden because their titles');

        $this->seedSite();
        $this->runPass();
        Cache::flush();

        $review = route('admin.videos.index', ['status' => VideoStatus::BANNED->value, 'reason' => BlockedWordsPass::FILTER], false);
        $this->actingAs($admin)->get(route('admin.index'))->assertOk()
            ->assertSee('4 videos were hidden because their titles, tags or categories use blocked words')
            ->assertSee('They are banned, so visitors no longer see them. Review them and unban any video that was hidden by mistake.')
            ->assertSee('href="'.e($review).'"', false)
            ->assertSee('name="notice" value="'.BlockedWordsPass::NOTICE.'"', false);

        $this->actingAs($admin)->post(route('admin.home.dismiss'), ['notice' => BlockedWordsPass::NOTICE])->assertRedirect(route('admin.index'));
        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertDontSee('hidden because their titles');
    }

    public function test_the_review_list_shows_the_videos_the_update_banned_and_why(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        $this->seedSite();
        $this->runPass();

        $html = (string) $this->actingAs($admin)
            ->get(route('admin.videos.index', ['status' => VideoStatus::BANNED->value, 'reason' => BlockedWordsPass::FILTER]))
            ->assertOk()->getContent();

        foreach (['hard_title', 'hard_tag', 'hard_category', 'hard_private'] as $name) {
            $this->assertStringContainsString('ad-v-'.$this->videos[$name]->id.'"', $html, $name.' is listed');
        }

        $this->assertStringNotContainsString('ad-v-'.$this->videos['admin_banned']->id.'"', $html, 'banned by the admin, not by the update');
        // each row says why, and the line above the list names the filter (round 3, R3-073)
        $this->assertSame(4 + 1, substr_count($html, 'Blocked words (1.2.3)'), 'each row says why');

        // every banned video: the admin's own ban has no reason line
        $all = (string) $this->actingAs($admin)->get(route('admin.videos.index', ['status' => VideoStatus::BANNED->value]))->assertOk()->getContent();
        $this->assertStringContainsString('ad-v-'.$this->videos['admin_banned']->id.'"', $all);
        $this->assertSame(4, substr_count($all, 'Blocked words (1.2.3)'));
    }

    public function test_tags_and_categories_with_hard_words_are_hidden_from_every_public_list(): void
    {
        $this->seedSite();
        $this->runPass();
        // an admin unbans a video after review: it is public again, its hard tag still is not shown
        VideoBans::unbanVideo($this->videos['hard_tag']->fresh());
        Cache::flush();
        ContentSafety::forget();

        $watch = $this->get($this->videos['hard_tag']->fresh()->route)->assertOk();
        $watch->assertSee('Drama')->assertDontSee('Loli');

        $this->get(route('pages.categories'))->assertOk()->assertSee('Drama')->assertDontSee('Schoolgirl Club')
            ->assertSee('Uncensored Leaked', false);
        $this->get(route('pages.category', 'schoolgirl-club'))->assertNotFound();
        $this->get(route('pages.category', 'drama'))->assertOk();
        $this->get('/')->assertOk()->assertDontSee('Schoolgirl Club');

        $sitemap = (string) $this->get(route('seo.sitemap.pages'))->assertOk()->getContent();
        $this->assertStringContainsString('/categories/drama', $sitemap);
        $this->assertStringNotContainsString('schoolgirl-club', $sitemap);

        $search = $this->getJson('/api/search?q=club')->assertOk()->json('categories');
        $this->assertSame([], array_values(array_filter((array) $search, fn ($category): bool => str_contains((string) ($category['title'] ?? ''), 'Schoolgirl'))));

        $rail = app(SmartDiscoveryRailService::class)->tags()->pluck('name')->all();
        $this->assertNotContains('Loli', $rail);
        $this->assertContains('Drama', $rail);

        $this->assertNotContains('Loli', $this->videos['hard_tag']->fresh()->load('tags.translations')->toSearchableArray()['tags']);
        $this->assertSame(['Drama', 'Uncensored Leaked'], ContentSafety::visibleCategories(Category::query()->orderBy('id')->get())->pluck('title')->all());
    }

    public function test_the_studio_offers_no_hidden_category_except_the_videos_own(): void
    {
        $this->seedSite();
        $this->actingAs($this->creator);

        $list = $this->get(route('user.videos.index'))->assertOk();
        $this->assertSame(['Drama', 'Uncensored Leaked'], collect($list->viewData('categories'))->pluck('title')->sort()->values()->all(), 'the filter of the studio list');

        $clean = $this->get(route('user.videos.edit', $this->videos['clean']))->assertOk();
        $this->assertNotContains('Schoolgirl Club', collect($clean->viewData('categories'))->pluck('title')->all(), 'a new choice');
        $clean->assertDontSee('>Schoolgirl Club</option>', false);

        // a video already in that category keeps it in its form, so an unchanged form still saves
        $own = $this->get(route('user.videos.edit', $this->videos['hard_category']))->assertOk();
        $this->assertContains('Schoolgirl Club', collect($own->viewData('categories'))->pluck('title')->all());
    }

    public function test_the_admin_sees_which_tags_and_categories_visitors_never_see(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        $this->seedSite();
        $marker = 'Hidden from visitors: its name uses a word that is always blocked.';

        $categories = (string) $this->actingAs($admin)->get(route('admin.categories.index'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($categories, $marker), 'only the category named with a hard word');
        // in the row of that category, under its name
        $this->assertMatchesRegularExpression('/Schoolgirl Club<\/a>(?:(?!<\/td>).)*<p class="ad-small ad-muted">'.preg_quote(e($marker), '/').'<\/p><\/td>/s', $categories);

        $tags = (string) $this->actingAs($admin)->get(route('admin.tags.index'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($tags, $marker), 'only the tag named with a hard word (not the default list)');

        // renamed: shown again, no marker
        $this->categories['hard']->update(['title' => 'Book Club']);
        ContentSafety::forget();
        $this->assertSame(0, substr_count((string) $this->actingAs($admin)->get(route('admin.categories.index'))->getContent(), $marker));
        $this->get(route('pages.categories'))->assertOk()->assertSee('Book Club');
    }

    /**
     * Round 2 (the reviewer's KS-R06): the old admin ("Use the old admin for now") is told too: its dashboard says how
     * many videos the update hid, with the review list and a button to hide the notice, and its video list says why a
     * video is banned.
     */
    public function test_the_old_admins_dashboard_and_video_list_say_it_too(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        \App\Support\AdminPreferences::set($admin, 'admin_ui', 'v1');

        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertDontSee('hidden because their titles');

        $this->seedSite();
        $this->runPass();
        Cache::flush();

        $review = route('admin.videos.index', ['status' => VideoStatus::BANNED->value, 'reason' => BlockedWordsPass::FILTER]);
        $this->actingAs($admin)->get(route('admin.index'))->assertOk()
            ->assertSee('id="blocked-words-notice"', false)
            ->assertSee('4 videos were hidden because their titles, tags or categories use blocked words')
            ->assertSee('They are banned, so visitors no longer see them. Review them and unban any video that was hidden by mistake.')
            ->assertSee('href="'.e($review).'"', false)
            ->assertSee('name="notice" value="'.BlockedWordsPass::NOTICE.'"', false);

        $list = (string) $this->actingAs($admin)->get($review)->assertOk()->getContent();
        // each row, and the line above the list that names the filter (round 3, R3-073)
        $this->assertSame(4 + 1, substr_count($list, 'Blocked words (1.2.3)'), 'the old list says why');
        $this->assertStringNotContainsString('Lolita by the sea', $list, 'banned by the admin, not by the update');

        $this->actingAs($admin)->post(route('admin.home.dismiss'), ['notice' => BlockedWordsPass::NOTICE])->assertRedirect(route('admin.index'));
        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertDontSee('hidden because their titles');
    }

    /**
     * Round 2 (the reviewer's KS-R08): a slice is one transaction. A process killed between the bans and their reasons
     * (here: the reasons cannot be written) leaves the slice as it was (no ban without its reason, the place where it
     * was), and the next run does the slice again with the same totals.
     */
    public function test_a_slice_is_one_transaction_so_no_ban_is_left_without_its_reason(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The killed slice is simulated with a SQLite trigger.');
        }

        $this->seedSite();
        BlockedWordsPass::start();
        DB::statement("CREATE TRIGGER ks_reasons_killed BEFORE INSERT ON video_ban_reasons BEGIN SELECT RAISE(ABORT, 'killed'); END");

        try {
            BlockedWordsPass::run(600.0);
            $this->fail('the slice stops where the reasons are written');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('killed', $e->getMessage());
        }

        $this->assertSame([$this->videos['admin_banned']->id], Video::withTrashed()->where('status', VideoStatus::BANNED->value)->pluck('id')->map(fn ($id): int => (int) $id)->all(), 'no ban of the slice stayed');
        $this->assertSame(0, DB::table(BlockedWordsPass::REASONS)->count());
        $state = BlockedWordsPass::state();
        $this->assertSame(['videos', 0, 0], [$state->stage, (int) $state->next_id, (int) $state->videos_hidden], 'the place did not move');

        DB::statement('DROP TRIGGER ks_reasons_killed');
        $this->runPass();

        $this->assertSame(5, (int) BlockedWordsPass::state()->videos_hidden);
        $this->assertSame(5, DB::table(BlockedWordsPass::REASONS)->count(), 'every ban of the pass has its reason');
    }

    /**
     * Round 2 (the reviewer's KS-R12): with an external search (Meilisearch, Algolia) the videos the pass banned leave its
     * index (the ban is a plain update the index never hears of); the built-in search asks for nothing.
     */
    public function test_the_videos_the_pass_banned_leave_an_external_search_index(): void
    {
        $engine = new class extends \Laravel\Scout\Engines\NullEngine
        {
            /** @var list<int> */
            public array $deleted = [];

            public function delete($models)
            {
                foreach ($models as $model) {
                    $this->deleted[] = (int) $model->getKey();
                }
            }
        };
        app(\Laravel\Scout\EngineManager::class)->extend('ks-fake', fn () => $engine);
        $this->seedSite();
        config(['scout.driver' => 'ks-fake', 'scout.queue' => false]);

        $this->runPass();

        $banned = DB::table(BlockedWordsPass::REASONS)->pluck('video_id')->map(fn ($id): int => (int) $id)->all();
        $this->assertCount(5, $banned);
        $this->assertEqualsCanonicalizing($banned, array_values(array_unique($engine->deleted)));

        // the built-in search: nothing to tell
        $engine->deleted = [];
        config(['scout.driver' => 'collection']);
        DB::table(BlockedWordsPass::PASSES)->delete();
        DB::table(BlockedWordsPass::REASONS)->delete();
        Video::withTrashed()->whereIn('id', $banned)->update(['status' => VideoStatus::PUBLIC->value, 'banned_at' => null]);
        $this->runPass();
        $this->assertSame([], $engine->deleted);
    }

    /**
     * Round 2 (the reviewer's KS-R07): a category named with a word that is always blocked is found nowhere a visitor
     * looks: not on its page, not in the public API's list of its videos (a video the admin unbanned, or one added after
     * the update), not on the video cards, not in the search index, not in the watch page's structured data.
     */
    public function test_a_hidden_category_is_found_nowhere_a_visitor_looks(): void
    {
        $this->seedSite();
        $this->runPass();
        // the admin unbanned the video of that category after the review: it is public again
        $video = $this->videos['hard_category']->fresh();
        VideoBans::unbanVideo($video);
        $video = $video->fresh(['category']);
        $this->assertSame(VideoStatus::PUBLIC, $video->status);
        Cache::flush();
        ContentSafety::forget();

        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route): bool => str_contains((string) $route->getActionName(), 'Api\\Public\\CategoryController@videos'));
        $this->assertNotNull($route);
        $this->getJson('/'.str_replace('{category}', (string) $this->categories['hard']->id, $route->uri()))->assertNotFound();
        $this->getJson('/'.str_replace('{category}', (string) $this->categories['drama']->id, $route->uri()))->assertOk();

        // the card look that names the category (Appearance: Bento cards)
        config(['site.video_card_layout_style' => 'card_bento_modern']);
        $card = view('videos.card', ['video' => $video->load('user')])->render();
        $this->assertStringContainsString(e($video->title), $card);
        $this->assertStringNotContainsString('Schoolgirl Club', $card, 'the card names no hidden category');
        $this->assertStringContainsString('Drama', view('videos.card', ['video' => $this->videos['clean']->fresh(['category', 'user'])])->render());

        $this->assertNull($video->toSearchableArray()['category']);
        $this->assertSame('Drama', $this->videos['clean']->fresh(['category'])->toSearchableArray()['category']);

        $seo = app(\App\Services\SeoService::class);
        $this->assertArrayNotHasKey('genre', $seo->videoSchema($video));
        $this->assertSame('', $seo->videoTemplateValues($video)['category']);
    }

    /**
     * Round 2: a stored translation (a video title of another language, a tag name a provider translated) that uses a
     * word that is always blocked is never shown, although the English one is clean; the video's own title and the
     * tag's own name are, and other translations still show.
     */
    public function test_a_translation_that_names_a_minor_is_never_shown(): void
    {
        $this->seedSite();
        $video = $this->videos['clean'];
        DB::table('video_translations')->insert([
            ['video_id' => $video->id, 'locale' => 'ja', 'title' => '女子高生の夕方', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            ['video_id' => $video->id, 'locale' => 'vi', 'title' => 'Một buổi tối sạch', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $tag = Tag::query()->where('name', 'Drama')->firstOrFail();
        \App\Models\TagTranslation::query()->create(['tag_id' => $tag->id, 'locale' => 'th', 'name' => 'นักเรียนดราม่า']);
        \App\Models\TagTranslation::query()->create(['tag_id' => $tag->id, 'locale' => 'vi', 'name' => 'Chính kịch']);

        app()->setLocale('ja');
        $this->assertSame('A clean evening', Video::query()->with('translations')->findOrFail($video->id)->title);
        app()->setLocale('vi');
        $this->assertSame('Một buổi tối sạch', Video::query()->with('translations')->findOrFail($video->id)->title, 'a clean translation still shows');
        $this->assertSame('Chính kịch', $tag->fresh()->localized_name);
        app()->setLocale('th');
        $thai = $tag->fresh()->localized_name;
        $this->assertNotSame('นักเรียนดราม่า', $thai, 'the stored translation that names a pupil is never shown');
        $this->assertNull(ContentSafety::hardMatch($thai), 'the shipped Thai name of the tag or its own name instead');
        app()->setLocale('en');
    }

    /** The pass of the update, from the start to the end. */
    private function runPass(): void
    {
        BlockedWordsPass::start();
        $result = BlockedWordsPass::run(600.0);
        $this->assertTrue($result['done']);
    }

    /**
     * A site of 1.2.2: a clean video, videos with hard words in the title, a tag, the category, a private one and a deleted
     * one, one with words of the default list only, and one an admin banned before.
     */
    private function seedSite(): void
    {
        $this->creator = User::factory()->create(['email_verified_at' => now()]);
        $this->categories = [
            'drama' => Category::create(['title' => 'Drama', 'slug' => 'drama', 'in_menu' => true, 'position' => 1]),
            'hard' => Category::create(['title' => 'Schoolgirl Club', 'slug' => 'schoolgirl-club', 'in_menu' => true, 'position' => 2]),
            'leaked' => Category::create(['title' => 'Uncensored Leaked', 'slug' => 'uncensored-leaked', 'in_menu' => true, 'position' => 3]),
        ];
        $tags = [
            'drama' => Tag::create(['name' => 'Drama', 'slug' => 'drama', 'usage_count' => 2]),
            'loli' => Tag::create(['name' => 'Loli', 'slug' => 'loli', 'usage_count' => 1]),
            'voyeur' => Tag::create(['name' => 'Voyeur', 'slug' => 'voyeur', 'usage_count' => 1]),
        ];

        $make = fn (string $title, Category $category, array $tagList = [], VideoStatus $status = VideoStatus::PUBLIC): Video => tap(
            Video::factory()->create(['user_id' => $this->creator->id, 'title' => $title, 'slug' => Str::slug($title), 'category_id' => $category->id,
                'status' => $status->value, 'published_at' => now()->subDays(2), 'uploaded_at' => now()->subDays(2), 'language' => null]),
            fn (Video $video) => $video->tags()->attach(array_map(fn (Tag $tag): int => $tag->id, $tagList))
        );

        $this->videos = [
            'clean' => $make('A clean evening', $this->categories['drama'], [$tags['drama']]),
            'hard_title' => $make('A schoolgirl story', $this->categories['drama']),
            'hard_tag' => $make('Another clean title', $this->categories['drama'], [$tags['drama'], $tags['loli']]),
            'hard_category' => $make('A third clean title', $this->categories['hard']),
            'hard_private' => $make('Joshi kousei diary', $this->categories['drama'], [], VideoStatus::PRIVATE),
            'hard_trashed' => $make('Kids corner', $this->categories['drama']),
            'default_words' => $make('Voyeur night', $this->categories['leaked'], [$tags['voyeur']]),
            'admin_banned' => $make('Lolita by the sea', $this->categories['drama']),
        ];
        $this->videos['hard_trashed']->delete();
        $this->videos['admin_banned']->forceFill(['status' => VideoStatus::BANNED->value, 'banned_at' => Carbon::now()->subDay()->startOfSecond()])->save();
        $this->videos['admin_banned'] = $this->videos['admin_banned']->fresh();
    }

    /**
     * $count videos in bulk, every $every-th one with a hard word in its title (and every other one of those through a
     * hard tag instead).
     *
     * @return list<int> the ids that use a hard word
     */
    private function insertVideos(int $count, int $every): array
    {
        $hardTag = Tag::create(['name' => 'Rori-Kei', 'slug' => 'rori-kei', 'usage_count' => 0]);
        $now = now()->toDateTimeString();
        $hard = [];
        $links = [];

        for ($offset = 0; $offset < $count; $offset += 500) {
            $rows = [];

            for ($i = $offset; $i < min($count, $offset + 500); $i++) {
                $marked = $i % $every === 0;
                $title = $marked && $i % (2 * $every) === 0 ? 'Bulk title '.$i.' high school girl' : 'Bulk title number '.$i.' with ordinary words';
                $rows[] = ['uuid' => (string) Str::uuid(), 'title' => $title, 'slug' => 'bulk-'.$i, 'original_file_name' => 'bulk.mp4',
                    'user_id' => $this->creator->id, 'status' => VideoStatus::PUBLIC->value, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            }

            DB::table('videos')->insert($rows);
        }

        foreach (DB::table('videos')->orderBy('id')->get(['id', 'title']) as $index => $row) {
            if ($index % $every !== 0) {
                continue;
            }

            $hard[] = (int) $row->id;

            if (! str_contains((string) $row->title, 'high school')) {
                $links[] = ['video_id' => (int) $row->id, 'tag_id' => $hardTag->id, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        foreach (array_chunk($links, 500) as $chunk) {
            DB::table('video_tags')->insert($chunk);
        }

        return $hard;
    }
}
