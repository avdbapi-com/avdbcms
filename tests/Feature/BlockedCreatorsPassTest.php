<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Services\AdaptiveCreatorSpotlightService;
use App\Support\AuditActions;
use App\Support\BlockedCreatorsPass;
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
 * 1.2.3 refresh 2 (lane CS2, N2-SAFE-01): the refresh blocks the creator channels the AVDBAPI and XVIDAPI importers
 * made whose handle, name or description uses a word of the hard list (App\Support\BlockedCreatorsPass, migration
 * 2026_11_03_120000_hide_imported_creators_with_blocked_words): blocked with the existing block of Members and the
 * stored reason "Blocked words (1.2.3)", their videos banned with the existing ban (each keeps its status) and the
 * stored reason "Blocked creator channel (1.2.3)" - a blocked member's public videos stay reachable by their address
 * and in the video sitemap otherwise. Only the importers' accounts: the administrator's and the members' own texts are
 * never read. The channel, its videos and its profile leave every public page, sitemap, search, the creators row and
 * the public API; Unblock in Members gives the channel and the videos the update banned back. The pass is idempotent,
 * never blocks a member the admin unblocked again, works in slices and gets through 50,000 members within a request
 * of Finish. Tools > Logs > Admin actions gets one row with the counts; Home and the old admin's dashboard say how
 * many, with the review list (Members > Blocked, filtered by the reason), until the admin dismisses it.
 */
class BlockedCreatorsPassTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Video> */
    private array $videos = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        ContentSafety::forget();
        BlockedWordsPass::forget();
        BlockedCreatorsPass::forget();
        TimeBudget::clear();
        config(['site.feed_cache_ttl' => 0]);
        // the migrations ran on the empty database of the test: new passes for the accounts made here
        DB::table(BlockedWordsPass::PASSES)->delete();
    }

    protected function tearDown(): void
    {
        TimeBudget::clear();

        parent::tearDown();
    }

    public function test_the_refresh_blocks_the_importer_made_channels_with_hard_words_and_their_videos_and_leaves_the_others(): void
    {
        $this->seedSite();
        $bannedBefore = (string) $this->users['banned_before']->banned_at;

        $this->runPass();

        foreach (['xvid', 'avdb', 'avdb_description'] as $name) {
            $user = $this->users[$name]->fresh();
            $this->assertNotNull($user->banned_at, $name.' is blocked');
            $reason = DB::table(BlockedCreatorsPass::REASONS)->where('user_id', $user->id)->first();
            $this->assertSame(BlockedCreatorsPass::REASON, $reason->reason, $name.' has the reason');
            $this->assertTrue(VideoBans::sameMoment($reason->banned_at, $user->banned_at), $name.': the reason belongs to this block');
        }

        // their videos: banned, each keeping its status for Unban, with the pass's own reason (a deleted one too)
        foreach (['xvid_public' => VideoStatus::PUBLIC, 'xvid_second' => VideoStatus::PUBLIC, 'xvid_private' => VideoStatus::PRIVATE, 'xvid_trashed' => VideoStatus::PUBLIC, 'avdb_public' => VideoStatus::PUBLIC, 'description_public' => VideoStatus::PUBLIC, 'banned_before_public' => VideoStatus::PUBLIC] as $name => $before) {
            $video = Video::withTrashed()->findOrFail($this->videos[$name]->id);
            $this->assertSame(VideoStatus::BANNED, $video->status, $name.' is banned');
            $this->assertSame($before, VideoBans::statusAfterUnban($video), $name.' keeps its status for Unban');
            $this->assertSame(BlockedCreatorsPass::VIDEO_REASON, DB::table(BlockedWordsPass::REASONS)->where('video_id', $video->id)->value('reason'), $name.' has the reason');
        }

        // a video an admin banned before keeps its ban, its date, and gets no reason of the update
        $this->assertSame(VideoStatus::BANNED, $this->videos['avdb_admin_banned']->fresh()->status);
        $this->assertFalse(DB::table(BlockedWordsPass::REASONS)->where('video_id', $this->videos['avdb_admin_banned']->id)->exists());

        // a channel the admin blocked before keeps its block and its date and gets no reason of the update; its videos
        // are banned all the same (a channel named with such a word keeps no public video)
        $this->assertSame($bannedBefore, (string) $this->users['banned_before']->fresh()->banned_at);
        $this->assertFalse(DB::table(BlockedCreatorsPass::REASONS)->where('user_id', $this->users['banned_before']->id)->exists());

        // never touched: the administrator's own description, a member's own texts, a clean importer-made channel and an
        // importer-made account that is an administrator
        foreach (['admin', 'member', 'clean', 'promoted'] as $name) {
            $this->assertNull($this->users[$name]->fresh()->banned_at, $name.' is not blocked');
        }
        foreach (['member_public', 'clean_public', 'admin_public', 'promoted_public'] as $name) {
            $this->assertSame(VideoStatus::PUBLIC, $this->videos[$name]->fresh()->status, $name.' stays public');
        }
        $this->assertSame(0, DB::table(BlockedWordsPass::REASONS)->where('reason', '!=', BlockedCreatorsPass::VIDEO_REASON)->count());

        $state = BlockedCreatorsPass::state();
        $this->assertNotNull($state->finished_at);
        $this->assertSame([3, 7], [(int) $state->members_hidden, (int) $state->videos_hidden]);
        $this->assertSame(count($this->users), (int) $state->members_checked);
        $this->assertSame(3, BlockedCreatorsPass::hiddenCount(), 'the reviewable ones');
        $this->assertFalse(BlockedCreatorsPass::due());
    }

    public function test_a_blocked_channel_and_its_videos_leave_the_public_side(): void
    {
        config(['site.creator_spotlight_enabled' => true, 'site.creator_spotlight_limit' => 3]);
        $this->seedSite();
        $channel = $this->users['xvid'];
        $video = $this->videos['xvid_public'];

        // before: public, listed, in the sitemaps and the creators row, found by the API
        $this->get(route('user.show', $channel))->assertOk();
        $this->get($video->route)->assertOk();
        $this->getJson(route('users.show', $channel->id))->assertOk();
        $this->assertStringContainsString($channel->slug, (string) $this->get('/sitemap-profiles-en-1.xml')->assertOk()->getContent());
        $this->assertStringContainsString($video->slug, (string) $this->get('/sitemap-videos-en-1.xml')->assertOk()->getContent());
        $this->assertContains($channel->id, app(AdaptiveCreatorSpotlightService::class)->creators()->pluck('id')->map(fn ($id): int => (int) $id)->all(), 'in the creators row before');
        $this->assertStringContainsString($video->title, (string) $this->getJson('/api/search?q='.rawurlencode($video->title))->assertOk()->getContent());

        $this->runPass();
        Cache::flush();
        $channel = $channel->fresh();
        $video = $video->fresh();

        // after: the channel page and the API say it is suspended, the watch page that the video was removed
        $this->get(route('user.show', $channel))->assertStatus(410);
        $this->getJson(route('users.show', $channel->id))->assertStatus(410);
        $this->get($video->route)->assertStatus(410);
        $this->get('/')->assertOk()->assertDontSee($video->title)->assertDontSee($this->videos['xvid_second']->title)->assertDontSee($this->videos['avdb_public']->title);
        $this->assertStringNotContainsString($channel->slug, (string) $this->get('/sitemap-profiles-en-1.xml')->assertOk()->getContent());
        $videos = (string) $this->get('/sitemap-videos-en-1.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString($video->slug, $videos);
        $this->assertStringNotContainsString($this->videos['banned_before_public']->slug, $videos, 'the videos of a channel the admin blocked without them leave too');
        $this->assertStringContainsString($this->videos['clean_public']->slug, $videos);
        $this->assertNotContains($channel->id, app(AdaptiveCreatorSpotlightService::class)->creators()->pluck('id')->map(fn ($id): int => (int) $id)->all(), 'out of the creators row');
        $this->assertStringNotContainsString($video->title, (string) $this->getJson('/api/search?q='.rawurlencode($video->title))->assertOk()->getContent());
        $this->assertStringNotContainsString($channel->username, (string) $this->get($this->videos['clean_public']->route)->assertOk()->getContent(), 'no card links to the channel');
    }

    public function test_unblock_in_members_gives_the_channel_and_the_videos_the_update_banned_back(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = $this->seedSite();
        $this->runPass();
        $xvid = $this->users['xvid']->fresh();
        $this->assertSame(3, BlockedCreatorsPass::hiddenCount());

        $this->actingAs($admin)->from(route('admin.users.index'))->post(route('admin.users.unban', $xvid))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'Member unblocked. 3 videos the update had banned are back too.');

        $this->assertNull($xvid->fresh()->banned_at);
        $this->assertSame(VideoStatus::PUBLIC, $this->videos['xvid_public']->fresh()->status);
        $this->assertSame(VideoStatus::PUBLIC, $this->videos['xvid_second']->fresh()->status);
        $this->assertSame(VideoStatus::PRIVATE, $this->videos['xvid_private']->fresh()->status, 'back to the status it had');
        $this->assertSame(VideoStatus::BANNED, Video::withTrashed()->findOrFail($this->videos['xvid_trashed']->id)->status, 'a deleted video stays banned');
        $this->assertSame(2, BlockedCreatorsPass::hiddenCount(), 'no longer counted');
        $this->assertSame(VideoStatus::BANNED, $this->videos['avdb_public']->fresh()->status, 'another channel\'s videos stay banned');
        Cache::flush();
        $this->get(route('user.show', $xvid->fresh()))->assertOk();
        $this->get($this->videos['xvid_public']->fresh()->route)->assertOk();

        // the bulk bar: the other two; a video the admin banned before keeps the admin's ban
        $this->actingAs($admin)->post(route('admin.bulk.users'), ['ids' => [$this->users['avdb']->id, $this->users['avdb_description']->id], 'action' => 'unban'])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'Unblocked 2 members. 2 videos the update had banned are back too.');
        $this->assertNull($this->users['avdb']->fresh()->banned_at);
        $this->assertSame(VideoStatus::PUBLIC, $this->videos['avdb_public']->fresh()->status);
        $this->assertSame(VideoStatus::PUBLIC, $this->videos['description_public']->fresh()->status);
        $this->assertSame(VideoStatus::BANNED, $this->videos['avdb_admin_banned']->fresh()->status, 'the admin\'s own ban stays');
        $this->assertSame(0, BlockedCreatorsPass::hiddenCount());

        // Unblock of a member the admin blocked before gives the videos the update banned back too, and the admin is told
        $this->actingAs($admin)->post(route('admin.users.unban', $this->users['banned_before']))
            ->assertSessionHas('success', 'Member unblocked. 1 video the update had banned is back too.');
        $this->assertSame(VideoStatus::PUBLIC, $this->videos['banned_before_public']->fresh()->status);

        // a member who was not blocked: nothing changes, nothing is said
        $this->actingAs($admin)->post(route('admin.users.unban', $this->users['clean']))->assertSessionHas('success', 'Member unblocked');
    }

    public function test_the_pass_is_idempotent_and_never_blocks_a_member_the_admin_unblocked_again(): void
    {
        $this->seedSite();
        $this->runPass();
        $reasons = DB::table(BlockedCreatorsPass::REASONS)->orderBy('user_id')->get()->map(fn ($row): array => (array) $row)->all();
        $videoReasons = DB::table(BlockedWordsPass::REASONS)->orderBy('video_id')->get()->map(fn ($row): array => (array) $row)->all();
        $bans = User::withTrashed()->orderBy('id')->pluck('banned_at', 'id')->map(fn ($at): ?string => $at === null ? null : (string) $at)->all();

        // the admin unblocks one after review: it is back and the notice counts one less
        User::query()->whereKey($this->users['avdb']->id)->update(['banned_at' => null]);
        BlockedCreatorsPass::restoreVideos([$this->users['avdb']->id]);
        $this->assertSame(VideoStatus::PUBLIC, $this->videos['avdb_public']->fresh()->status);
        $this->assertSame(2, BlockedCreatorsPass::hiddenCount());

        // the update's step again (a second Finish, a second process, the scheduler): nothing changes
        (require base_path('database/migrations/2026_11_03_120000_hide_imported_creators_with_blocked_words.php'))->up();
        BlockedCreatorsPass::start();
        $this->runPass();
        $this->artisan('videos:hide-blocked-words')->assertExitCode(0);

        $this->assertNull($this->users['avdb']->fresh()->banned_at, 'never blocked again');
        $this->assertSame(VideoStatus::PUBLIC, $this->videos['avdb_public']->fresh()->status, 'its videos neither');
        $this->assertSame($reasons, DB::table(BlockedCreatorsPass::REASONS)->orderBy('user_id')->get()->map(fn ($row): array => (array) $row)->all());
        $this->assertSame($videoReasons, DB::table(BlockedWordsPass::REASONS)->orderBy('video_id')->get()->map(fn ($row): array => (array) $row)->all());
        $bans[$this->users['avdb']->id] = null;
        $this->assertSame($bans, User::withTrashed()->orderBy('id')->pluck('banned_at', 'id')->map(fn ($at): ?string => $at === null ? null : (string) $at)->all());
        $this->assertSame([3, 7], [(int) BlockedCreatorsPass::state()->members_hidden, (int) BlockedCreatorsPass::state()->videos_hidden], 'counted once');
    }

    public function test_the_pass_works_in_slices_and_two_processes_never_count_twice(): void
    {
        $hard = $this->insertMembers((int) (BlockedCreatorsPass::CHUNK * 2.5), 50);
        BlockedCreatorsPass::start();

        // one slice per call: BlockedCreatorsPass::CHUNK members at a time
        $calls = 0;
        do {
            $result = BlockedCreatorsPass::run(0.0);
            $calls++;

            if ($calls === 1) {
                // a second process read the same place before the first one moved it: it does the same slice again
                DB::table(BlockedWordsPass::PASSES)->where('name', BlockedCreatorsPass::NAME)->update(['next_id' => 0]);
            }
        } while (! $result['done'] && $calls < 20);

        $this->assertTrue($result['done']);
        $this->assertSame(4, $calls, 'three slices of members (one of them twice)');
        $this->assertEqualsCanonicalizing($hard, User::query()->whereNotNull('banned_at')->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(count($hard), (int) BlockedCreatorsPass::state()->members_hidden, 'counted once');
        $this->assertSame(count($hard), DB::table(BlockedCreatorsPass::REASONS)->count());
    }

    public function test_50000_members_are_done_within_a_request_of_finish(): void
    {
        $hard = $this->insertMembers(50000, 500);
        BlockedCreatorsPass::start();

        // a request of Finish: 20 seconds with 5 in reserve, the update's step gets its share (BlockedCreatorsPass::SECONDS)
        TimeBudget::start(20.0, 5.0);
        $started = microtime(true);
        $result = BlockedCreatorsPass::run(TimeBudget::cap(BlockedCreatorsPass::SECONDS));
        $seconds = microtime(true) - $started;

        $this->assertTrue($result['done'], '50,000 members in one request of Finish');
        $this->assertSame(50000, $result['checked']);
        $this->assertSame(count($hard), $result['hidden']);
        $this->assertLessThanOrEqual(BlockedCreatorsPass::SECONDS + 1.0, $seconds);
        fwrite(STDERR, sprintf("\n[CS2] 50,000 members: %.2f s in this test database\n", $seconds));
    }

    public function test_the_scheduler_and_a_visit_of_home_carry_on_with_an_unfinished_pass(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = $this->seedSite();
        BlockedCreatorsPass::start();
        $this->assertTrue(BlockedCreatorsPass::due());
        $this->assertTrue(\App\Console\Commands\HideBlockedWordVideos::due(), 'the scheduler starts the task for this pass too');

        // the scheduler's task (the 1.2.3 pass is done: the whole run is this pass's)
        $this->artisan('videos:hide-blocked-words', ['--seconds' => 20])->assertExitCode(0);
        $this->assertFalse(BlockedCreatorsPass::due());
        $this->assertNotNull($this->users['xvid']->fresh()->banned_at);

        // a visit of Home (a big site whose cron job does not run yet): after the page was sent
        User::query()->whereKey($this->users['xvid']->id)->update(['banned_at' => null]);
        DB::table(BlockedCreatorsPass::REASONS)->delete();
        DB::table(BlockedWordsPass::PASSES)->delete();
        BlockedCreatorsPass::start();
        $this->assertTrue(BlockedCreatorsPass::due());

        $this->actingAs($admin)->get(route('admin.index'))->assertOk();

        $this->assertFalse(BlockedCreatorsPass::due(), 'the visit finished the pass after the page was sent');
        $this->assertNotNull($this->users['xvid']->fresh()->banned_at);
    }

    public function test_the_update_step_runs_the_pass_and_is_safe_to_run_twice(): void
    {
        $this->seedSite();
        $migration = require base_path('database/migrations/2026_11_03_120000_hide_imported_creators_with_blocked_words.php');

        $migration->up();
        $migration->up();

        $this->assertNotNull($this->users['xvid']->fresh()->banned_at);
        $this->assertSame(3, BlockedCreatorsPass::hiddenCount());
        $this->assertSame([3, 7], [(int) BlockedCreatorsPass::state()->members_hidden, (int) BlockedCreatorsPass::state()->videos_hidden]);
        $this->assertCount(1, DB::table('admin_audit_logs')->where('action', BlockedCreatorsPass::AUDIT_ACTION)->get());
    }

    public function test_one_admin_actions_row_with_the_counts_in_the_admins_words(): void
    {
        $this->seedSite();

        $this->runPass();
        $this->runPass();

        $rows = DB::table('admin_audit_logs')->where('action', BlockedCreatorsPass::AUDIT_ACTION)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('1.2.3 refresh: 3 creator channels made by the importers blocked (reason "Blocked words (1.2.3)") because their names or descriptions use words that are always blocked, with 7 of their videos banned (reason "Blocked creator channel (1.2.3)")', $rows[0]->details);
        $this->assertSame('Update: imported creator channels with blocked words blocked', AuditActions::label(BlockedCreatorsPass::AUDIT_ACTION));
    }

    public function test_home_says_how_many_channels_were_blocked_until_the_admin_dismisses_it(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = $this->seedSite();

        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertDontSee('creator channels were blocked');

        $this->runPass();
        Cache::flush();

        $review = route('admin.users.index', ['status' => 'banned', 'reason' => BlockedCreatorsPass::FILTER], false);
        $this->actingAs($admin)->get(route('admin.index'))->assertOk()
            ->assertSee('3 imported creator channels were blocked because their names or descriptions use blocked words')
            ->assertSee('They and their videos are banned, so visitors no longer see them. Review them and unblock any channel that was blocked by mistake.')
            ->assertSee('href="'.e($review).'"', false)
            ->assertSee('name="notice" value="'.BlockedCreatorsPass::NOTICE.'"', false);

        $this->actingAs($admin)->post(route('admin.home.dismiss'), ['notice' => BlockedCreatorsPass::NOTICE])->assertRedirect(route('admin.index'));
        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertDontSee('creator channels were blocked');
    }

    public function test_the_review_list_shows_the_channels_the_update_blocked_and_why(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = $this->seedSite();
        $this->runPass();

        $html = (string) $this->actingAs($admin)
            ->get(route('admin.users.index', ['status' => 'banned', 'reason' => BlockedCreatorsPass::FILTER]))
            ->assertOk()->getContent();

        foreach (['xvid', 'avdb', 'avdb_description'] as $name) {
            $this->assertStringContainsString('id="ad-u-'.$this->users[$name]->id.'"', $html, $name.' is listed');
        }
        $this->assertStringNotContainsString('id="ad-u-'.$this->users['banned_before']->id.'"', $html, 'blocked by the admin, not by the update');
        $this->assertStringNotContainsString('id="ad-u-'.$this->users['clean']->id.'"', $html);
        // each row says why, and the line above the list names the filter and keeps it for a search
        $this->assertSame(3 + 1, substr_count($html, 'Blocked words (1.2.3)'), 'each row says why');
        $this->assertStringContainsString('Show all blocked members', $html);
        $this->assertStringContainsString('name="reason" value="'.BlockedCreatorsPass::FILTER.'"', $html);

        // every blocked member: the admin's own block has no reason line
        $all = (string) $this->actingAs($admin)->get(route('admin.users.index', ['status' => 'banned']))->assertOk()->getContent();
        $this->assertStringContainsString('id="ad-u-'.$this->users['banned_before']->id.'"', $all);
        $this->assertSame(3, substr_count($all, 'Blocked words (1.2.3)'));
        $this->assertStringNotContainsString('Show all blocked members', $all);
    }

    public function test_the_old_admins_dashboard_and_members_list_say_it_too(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = $this->seedSite();
        \App\Support\AdminPreferences::set($admin, 'admin_ui', 'v1');

        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertDontSee('creator channels were blocked');

        $this->runPass();
        Cache::flush();

        $review = route('admin.users.index', ['status' => 'banned', 'reason' => BlockedCreatorsPass::FILTER]);
        $this->actingAs($admin)->get(route('admin.index'))->assertOk()
            ->assertSee('id="blocked-creators-notice"', false)
            ->assertSee('3 imported creator channels were blocked because their names or descriptions use blocked words')
            ->assertSee('href="'.e($review).'"', false)
            ->assertSee('name="notice" value="'.BlockedCreatorsPass::NOTICE.'"', false);

        $list = (string) $this->actingAs($admin)->get($review)->assertOk()->getContent();
        $this->assertSame(3 + 1, substr_count($list, 'Blocked words (1.2.3)'), 'the old list says why');
        $this->assertStringNotContainsString($this->users['banned_before']->username, $list, 'blocked by the admin, not by the update');
        $this->assertStringContainsString('Show all blocked members', $list);

        $this->actingAs($admin)->post(route('admin.home.dismiss'), ['notice' => BlockedCreatorsPass::NOTICE])->assertRedirect(route('admin.index'));
        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertDontSee('creator channels were blocked');
    }

    /**
     * A channel's block and its videos' bans are one transaction. A process killed between the bans and their reasons
     * (here: the video reasons cannot be written) leaves the channel as it was (not blocked, no reason, no video banned),
     * and the next run does it again with the same totals.
     */
    public function test_a_channels_block_and_its_videos_bans_are_one_transaction(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The killed slice is simulated with a SQLite trigger.');
        }

        $this->seedSite();
        BlockedCreatorsPass::start();
        DB::statement("CREATE TRIGGER cs2_reasons_killed BEFORE INSERT ON video_ban_reasons BEGIN SELECT RAISE(ABORT, 'killed'); END");

        try {
            BlockedCreatorsPass::run(600.0);
            $this->fail('the slice stops where the reasons are written');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('killed', $e->getMessage());
        }

        $this->assertNull($this->users['xvid']->fresh()->banned_at, 'no block of the slice stayed');
        $this->assertSame(0, DB::table(BlockedCreatorsPass::REASONS)->count());
        $this->assertSame(VideoStatus::PUBLIC, $this->videos['xvid_public']->fresh()->status, 'no ban without its reason');
        $state = BlockedCreatorsPass::state();
        $this->assertSame([0, 0, 0], [(int) $state->next_id, (int) $state->members_hidden, (int) $state->videos_hidden], 'the place did not move');

        DB::statement('DROP TRIGGER cs2_reasons_killed');
        $this->runPass();

        $this->assertSame([3, 7], [(int) BlockedCreatorsPass::state()->members_hidden, (int) BlockedCreatorsPass::state()->videos_hidden]);
        $this->assertSame(7, DB::table(BlockedWordsPass::REASONS)->count(), 'every ban of the pass has its reason');
    }

    /** With an external search (Meilisearch, Algolia) the videos the pass banned leave its index at once. */
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
        app(\Laravel\Scout\EngineManager::class)->extend('cs2-fake', fn () => $engine);
        $this->seedSite();
        config(['scout.driver' => 'cs2-fake', 'scout.queue' => false]);

        $this->runPass();

        $banned = DB::table(BlockedWordsPass::REASONS)->where('reason', BlockedCreatorsPass::VIDEO_REASON)->pluck('video_id')->map(fn ($id): int => (int) $id)->all();
        $this->assertCount(7, $banned);
        $this->assertEqualsCanonicalizing($banned, array_values(array_unique($engine->deleted)));
    }

    /** The pass of the refresh, from the start to the end. */
    private function runPass(): void
    {
        BlockedCreatorsPass::start();
        $result = BlockedCreatorsPass::run(600.0);
        $this->assertTrue($result['done']);
    }

    /**
     * A site of 1.2.3 before the refresh: the administrator (whose own description uses a hard word), a member whose
     * handle does, the importers' channels - an XVIDAPI studio channel and an AVDBAPI director channel named with a hard
     * word, an AVDBAPI channel whose description has one, a clean one, one the admin blocked before and one the admin made
     * an administrator - each with videos.
     *
     * @return User the administrator
     */
    private function seedSite(): User
    {
        $xvidEmail = fn (string $key): string => 'creator.'.substr(hash('sha1', $key), 0, 16).'@xvidapi.local';
        $avdbEmail = fn (string $key): string => 'avdb-'.substr(hash('sha256', $key), 0, 32).'@creators.avdbclip.invalid';

        $this->users = [
            'admin' => User::factory()->admin()->create(['username' => 'owner', 'description' => 'Our channel for children and families.']),
            'member' => User::factory()->create(['username' => 'schoolgirl_fan', 'description' => 'A member who wrote this.']),
            'xvid' => User::factory()->create(['username' => 'schoolgirls-hd', 'email' => $xvidEmail('studio:schoolgirls-hd'), 'description' => 'Official creator channel for Schoolgirls HD.']),
            'avdb' => User::factory()->create(['username' => 'Loli Pop Studio', 'email' => $avdbEmail('person:loli-pop-studio'), 'avdb_creator_key' => 'person:loli-pop-studio', 'avdb_creator_source' => 'director', 'avdb_creator_name' => 'Loli Pop Studio', 'description' => 'Loli Pop Studio is an imported director channel featuring Censored, Japan.']),
            'avdb_description' => User::factory()->create(['username' => 'Plain Director', 'email' => $avdbEmail('person:plain-director'), 'avdb_creator_key' => 'person:plain-director', 'avdb_creator_source' => 'director', 'avdb_creator_name' => 'Plain Director', 'description' => 'Plain Director is an imported director channel featuring Schoolgirl, Japan.']),
            'clean' => User::factory()->create(['username' => 'clean-films', 'email' => $xvidEmail('studio:clean-films'), 'description' => 'Official creator channel for Clean Films.']),
            'banned_before' => User::factory()->create(['username' => 'loli-chan', 'email' => $xvidEmail('actor:loli-chan'), 'description' => 'Official creator channel for Loli Chan.', 'banned_at' => Carbon::now()->subDay()->startOfSecond()]),
            'promoted' => User::factory()->admin()->create(['username' => 'rori-kei', 'email' => $xvidEmail('studio:rori-kei'), 'description' => 'Official creator channel for Rori-Kei.']),
        ];

        $make = fn (string $owner, string $title, VideoStatus $status = VideoStatus::PUBLIC): Video => Video::factory()->create([
            'user_id' => $this->users[$owner]->id, 'title' => $title, 'slug' => Str::slug($title), 'status' => $status->value,
            'published_at' => now()->subDays(2), 'uploaded_at' => now()->subDays(2), 'language' => null,
        ]);

        $this->videos = [
            'xvid_public' => $make('xvid', 'A clean evening by the lake'),
            'xvid_second' => $make('xvid', 'Another clean evening'),
            'xvid_private' => $make('xvid', 'A private clean evening', VideoStatus::PRIVATE),
            'xvid_trashed' => $make('xvid', 'A deleted clean evening'),
            'avdb_public' => $make('avdb', 'A third clean title'),
            'avdb_admin_banned' => $make('avdb', 'A title the admin banned'),
            'description_public' => $make('avdb_description', 'A fourth clean title'),
            'clean_public' => $make('clean', 'A clean studio title'),
            'banned_before_public' => $make('banned_before', 'A title of a blocked channel'),
            'member_public' => $make('member', 'A member\'s own video'),
            'admin_public' => $make('admin', 'The owner\'s own video'),
            'promoted_public' => $make('promoted', 'An administrator\'s video'),
        ];
        $this->videos['xvid_trashed']->delete();
        $this->videos['avdb_admin_banned']->forceFill(['status' => VideoStatus::BANNED->value, 'banned_at' => Carbon::now()->subDay()->startOfSecond()])->save();
        $this->videos['avdb_admin_banned'] = $this->videos['avdb_admin_banned']->fresh();

        return $this->users['admin'];
    }

    /**
     * $count members in bulk, every $every-th one an importer-made channel with a hard word in its handle (and every
     * other one of those an AVDBAPI channel with one in its stored name instead); the others members of their own.
     *
     * @return list<int> the ids the pass must block
     */
    private function insertMembers(int $count, int $every): array
    {
        $now = now()->toDateTimeString();
        $hard = [];

        for ($offset = 0; $offset < $count; $offset += 500) {
            $rows = [];

            for ($i = $offset; $i < min($count, $offset + 500); $i++) {
                $marked = $i % $every === 0;
                $avdb = $marked && $i % (2 * $every) === 0;
                $rows[] = [
                    'username' => $marked ? ($avdb ? 'Bulk Director '.$i : 'bulk-schoolgirls-'.$i) : 'bulk_member_'.$i,
                    'slug' => 'bulk-'.$i,
                    'email' => $marked ? ($avdb ? 'avdb-'.substr(hash('sha256', 'person:bulk-'.$i), 0, 32).'@creators.avdbclip.invalid' : 'creator.'.substr(hash('sha1', 'studio:bulk-'.$i), 0, 16).'@xvidapi.local') : 'bulk'.$i.'@example.test',
                    'password' => 'x',
                    'email_verified_at' => $now,
                    'description' => $marked ? 'Official creator channel for Bulk '.$i.'.' : 'A schoolgirl story is this member\'s own words, never read.',
                    'avdb_creator_key' => $avdb ? 'person:bulk-'.$i : null,
                    'avdb_creator_name' => $avdb ? 'Loli Bulk '.$i : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $first = (int) DB::table('users')->max('id') + 1;
            DB::table('users')->insert($rows);

            foreach ($rows as $index => $row) {
                if ($row['avdb_creator_key'] !== null || str_starts_with($row['username'], 'bulk-schoolgirls-')) {
                    $hard[] = $first + $index;
                }
            }
        }

        return $hard;
    }
}
