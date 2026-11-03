<?php

namespace Tests\Feature;

use App\Models\AvdbImportRun;
use App\Models\User;
use App\Models\Video;
use App\Models\XvidImportRun;
use App\Support\BlockedCreatorsPass;
use App\Support\ContentSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 1.2.3 refresh 2 (lane CS2, N2-SAFE-01): the AVDBAPI and XVIDAPI importers never make, restore or reuse a creator
 * channel whose name, handle or description uses a word that is always blocked (App\Support\ContentSafety::hardMatch:
 * words that present a child or a school-age minor). Up to the refresh every demo site got a public channel
 * "schoolgirls-hd" ("Official creator channel for Schoolgirls HD.") from a studio name, linked from its video's page,
 * in the profiles sitemap and indexable, because only the video's title, tags and categories were checked. Now such a
 * video (its own texts are clean, so it is imported) goes to the administrator's account, a hard-matched actor is not
 * used as a creator name either, the run log says so once per name (never with the word) and the run's message counts
 * the videos. The accounts the importers make are the ones the refresh's pass recognises (BlockedCreatorsPass).
 */
class ContentSafetyCreatorImportTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const XVID_LINE = 'No creator channel for a studio or actor whose name uses blocked words: its videos go to the administrator\'s account.';

    private const AVDB_LINE = 'No creator channel for a director or actor whose name uses blocked words: their videos go to the administrator\'s account.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        ContentSafety::forget();
        Storage::fake('local');
        // the administrator's account: the XVIDAPI importer's fallback (the first administrator) and the AVDBAPI one's (id 1)
        $this->admin = User::factory()->admin()->create(['id' => 1, 'username' => 'owner', 'description' => 'The site owner.']);
    }

    public function test_an_xvid_studio_or_actor_whose_name_uses_a_blocked_word_gets_no_creator_channel(): void
    {
        $this->fakeApis([], [
            $this->xvidItem(3001, ['author' => 'Schoolgirls HD']),
            $this->xvidItem(3002, ['author' => 'Clean Films']),
            $this->xvidItem(3003, ['actor' => 'Loli Chan, Jane Doe']),
            $this->xvidItem(3004, ['actor' => 'Jane Doe']),
            $this->xvidItem(3005, ['author' => 'Schoolgirls HD']),
        ]);
        $run = XvidImportRun::create(['status' => 'queued', 'settings' => ['categories' => [65], 'pages' => 1, 'pagesize' => 10, 'skip_thumbs' => true, 'translate_tags' => false]]);

        $this->artisan('xvid:import', ['--run-id' => $run->id])->assertExitCode(0);

        // every video is imported (its own texts are clean) ...
        $this->assertEqualsCanonicalizing([3001, 3002, 3003, 3004, 3005], Video::query()->pluck('xvid_id')->map(fn ($id): int => (int) $id)->all());
        // ... the ones of a hard-matched studio or first actor under the administrator's account
        foreach ([3001, 3003, 3005] as $id) {
            $this->assertSame(1, (int) Video::query()->where('xvid_id', $id)->value('user_id'), 'xvid '.$id.' goes to the administrator');
        }
        // ... the others under their creator channel, made as before
        $clean = Video::query()->where('xvid_id', 3002)->firstOrFail()->user;
        $this->assertSame('clean-films', $clean->username);
        $this->assertSame('Official creator channel for Clean Films.', $clean->description);
        $this->assertStringEndsWith('@xvidapi.local', $clean->email);
        $this->assertTrue(BlockedCreatorsPass::importerMade($clean), 'the refresh\'s pass recognises the account the importer made');
        $this->assertSame('jane-doe', Video::query()->where('xvid_id', 3004)->firstOrFail()->user->username);
        $this->assertNoCreatorWithBlockedWords();

        // the run log says so once per name, never with the word; the run's message counts the videos
        $run->refresh();
        $this->assertSame('finished', $run->status);
        $this->assertSame([5, 0, 0], [(int) $run->imported, (int) $run->skipped, (int) $run->failed]);
        $this->assertSame(2, substr_count((string) $run->log, self::XVID_LINE), 'once per studio or actor');
        $this->assertStringContainsString('3 videos imported without a creator channel: blocked words in the studio or actor name.', (string) $run->message);
        $this->assertStringContainsString('3 videos imported without a creator channel: blocked words in the studio or actor name.', (string) $run->log);
        $this->assertLogNeverRepeatsTheWords((string) $run->log);
    }

    public function test_an_xvid_creator_channel_made_before_whose_texts_use_a_blocked_word_is_not_reused(): void
    {
        // the channel the importer made for the studio "HD Films" before the refresh (its e-mail form), whose
        // description an older import gave a word that is always blocked
        $existing = User::factory()->create([
            'username' => 'hd-films',
            'email' => 'creator.'.substr(hash('sha1', 'studio:hd-films'), 0, 16).'@xvidapi.local',
            'description' => 'Official creator channel for HD Films. Schoolgirls only.',
        ]);
        $this->fakeApis([], [$this->xvidItem(3101, ['author' => 'HD Films']), $this->xvidItem(3102, ['author' => 'HD Films'])]);
        $run = XvidImportRun::create(['status' => 'queued', 'settings' => ['ids' => '3101,3102', 'skip_thumbs' => true]]);

        $this->artisan('xvid:import', ['--run-id' => $run->id])->assertExitCode(0);

        $this->assertSame([1, 1], Video::query()->whereIn('xvid_id', [3101, 3102])->pluck('user_id')->map(fn ($id): int => (int) $id)->all(), 'not reused: the administrator\'s account');
        $this->assertSame(0, Video::query()->where('user_id', $existing->id)->count());
        $this->assertSame(1, User::query()->where('email', 'like', '%@xvidapi.local')->count(), 'no second channel for the studio either');
        $run->refresh();
        $this->assertSame(1, substr_count((string) $run->log, self::XVID_LINE));
        $this->assertStringContainsString('2 videos imported without a creator channel', (string) $run->message);
    }

    public function test_an_avdb_director_or_actor_whose_name_uses_a_blocked_word_gets_no_creator_channel(): void
    {
        $this->fakeApis([
            $this->avdbItem(4001, ['director' => 'Loli Pop Studio', 'actor' => 'Jane Doe']),
            $this->avdbItem(4002, ['director' => '', 'actor' => 'JK Girl, Jane Doe']),
            $this->avdbItem(4003, ['director' => 'Clean Director', 'actor' => 'Jane Doe']),
            $this->avdbItem(4004, ['director' => '', 'actor' => 'Jane Doe']),
            $this->avdbItem(4005, ['director' => 'Loli Pop Studio', 'actor' => '']),
        ], []);
        $run = AvdbImportRun::create(['status' => 'queued', 'settings' => ['ids' => '4001,4002,4003,4004,4005', 'thumbnail_mode' => 'remote', 'skip_tags' => true, 'delay' => 0]]);

        $this->artisan('avdb:import', ['--run-id' => $run->id])->assertExitCode(0);

        $this->assertEqualsCanonicalizing([4001, 4002, 4003, 4004, 4005], Video::query()->pluck('avdb_id')->map(fn ($id): int => (int) $id)->all());
        foreach ([4001, 4002, 4005] as $id) {
            $this->assertSame(1, (int) Video::query()->where('avdb_id', $id)->value('user_id'), 'avdb '.$id.' goes to the administrator');
        }
        $director = Video::query()->where('avdb_id', 4003)->firstOrFail()->user;
        $this->assertSame('Clean Director', $director->username);
        $this->assertSame('person:clean-director', $director->avdb_creator_key);
        $this->assertStringEndsWith('@creators.avdbclip.invalid', $director->email);
        $this->assertTrue(BlockedCreatorsPass::importerMade($director), 'the refresh\'s pass recognises the account the importer made');
        $this->assertSame('Jane Doe', Video::query()->where('avdb_id', 4004)->firstOrFail()->user->username);
        $this->assertNoCreatorWithBlockedWords();

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame([5, 0, 0], [(int) $run->imported, (int) $run->skipped, (int) $run->failed]);
        $this->assertSame(2, substr_count((string) $run->log, self::AVDB_LINE), 'once per director or actor');
        $this->assertStringContainsString('3 videos imported without a creator channel: blocked words in the director or actor name.', (string) $run->message);
        $this->assertLogNeverRepeatsTheWords((string) $run->log);
    }

    public function test_an_avdb_creator_channel_made_before_whose_texts_use_a_blocked_word_is_neither_reused_nor_restored(): void
    {
        // the channel the importer made for the director "Rori Films" before the refresh (its key and e-mail form), deleted
        // by the admin since; its name uses a word that is always blocked
        $existing = User::factory()->create([
            'username' => 'Rori Films',
            'email' => 'avdb-'.substr(hash('sha256', 'person:rori-films'), 0, 32).'@creators.avdbclip.invalid',
            'avdb_creator_key' => 'person:rori-films',
            'avdb_creator_source' => 'director',
            'avdb_creator_name' => 'Rori Films',
            'description' => 'Rori Films is an imported director channel featuring Censored, Japan.',
        ]);
        $existing->delete();
        // ... and one whose stored description got such a word although the name is clean (a clean name is checked
        // against the stored texts of the channel it would reuse)
        $edited = User::factory()->create([
            'username' => 'Plain Director',
            'email' => 'avdb-'.substr(hash('sha256', 'person:plain-director'), 0, 32).'@creators.avdbclip.invalid',
            'avdb_creator_key' => 'person:plain-director',
            'avdb_creator_source' => 'director',
            'avdb_creator_name' => 'Plain Director',
            'description' => 'Plain Director is an imported director channel featuring Schoolgirl, Japan.',
        ]);
        $this->fakeApis([
            $this->avdbItem(4101, ['director' => 'Rori Films', 'actor' => 'Jane Doe']),
            $this->avdbItem(4102, ['director' => 'Plain Director', 'actor' => 'Jane Doe']),
        ], []);

        $this->artisan('avdb:import', ['--ids' => '4101,4102', '--skip-thumbs' => true, '--skip-tags' => true, '--delay' => 0])->assertExitCode(0);

        $this->assertSame([1, 1], Video::query()->whereIn('avdb_id', [4101, 4102])->orderBy('avdb_id')->pluck('user_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertNotNull(User::withTrashed()->find($existing->id)->deleted_at, 'not restored');
        $this->assertSame(0, Video::query()->where('user_id', $edited->id)->count(), 'not reused');
        $this->assertSame(2, User::withTrashed()->where('email', 'like', '%@creators.avdbclip.invalid')->count(), 'no new channel either');
    }

    public function test_an_existing_avdb_video_is_not_moved_to_a_creator_whose_name_uses_a_blocked_word(): void
    {
        $video = Video::factory()->create(['user_id' => 1, 'avdb_id' => 4201, 'title' => 'Kept title', 'avdb_content_hash' => 'an-old-hash']);
        $this->fakeApis([$this->avdbItem(4201, ['name' => 'Kept title', 'director' => 'Loli Pop Studio', 'actor' => 'Jane Doe'])], []);

        $this->artisan('avdb:import', ['--ids' => '4201', '--skip-thumbs' => true, '--skip-tags' => true, '--delay' => 0])->assertExitCode(0);

        $this->assertSame(1, (int) $video->fresh()->user_id, 'stays with the administrator');
        $this->assertSame(1, User::query()->count(), 'no channel made');
    }

    /**
     * The run log names the rule only when the run acted on it: a re-run over the existing videos of such a director
     * (every video skipped, or refreshed, nothing imported) says nothing and counts nothing; the next new video of the
     * same director says it once again.
     */
    public function test_a_rerun_over_existing_videos_of_a_director_whose_name_uses_a_blocked_word_says_nothing(): void
    {
        // one fake for the whole test (a second Http::fake would not replace the first): its page is changed below
        $page = [$this->avdbItem(4301, ['director' => 'Loli Pop Studio', 'actor' => 'Jane Doe'])];
        Http::fake(function (HttpRequest $request) use (&$page) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $only = isset($query['ids']) ? array_map('intval', explode(',', (string) $query['ids'])) : null;
            $list = array_values(array_filter($page, fn (array $item): bool => $only === null || in_array((int) $item['id'], $only, true)));

            return str_contains($request->url(), 'avdbapi.com')
                ? Http::response(['code' => 1, 'total' => count($list), 'pagecount' => 1, 'list' => $list], 200)
                : Http::response('', 404);
        });
        $first = $this->avdbRun('4301');
        $this->assertSame([1, 0], [(int) $first->imported, (int) $first->skipped]);
        $this->assertSame(1, substr_count((string) $first->log, self::AVDB_LINE));
        $this->assertStringContainsString('1 video imported without a creator channel', (string) $first->message);

        // the same page again: skipped as a duplicate (a scheduled re-run)
        $second = $this->avdbRun('4301');
        $this->assertSame([0, 1], [(int) $second->imported, (int) $second->skipped]);
        $this->assertStringNotContainsString(self::AVDB_LINE, (string) $second->log, 'nothing was imported: nothing to say');
        $this->assertStringNotContainsString('without a creator channel', (string) $second->message);

        // the item changed (its title): the video is refreshed, keeps its creator, and still nothing is said
        $page = [$this->avdbItem(4301, ['name' => 'Item 4301 renamed', 'director' => 'Loli Pop Studio', 'actor' => 'Jane Doe'])];
        $third = $this->avdbRun('4301');
        $this->assertSame([0, 1], [(int) $third->imported, (int) $third->updated]);
        $this->assertSame('Item 4301 renamed', Video::query()->where('avdb_id', 4301)->value('title'));
        $this->assertSame(1, (int) Video::query()->where('avdb_id', 4301)->value('user_id'));
        $this->assertStringNotContainsString(self::AVDB_LINE, (string) $third->log);
        $this->assertStringNotContainsString('without a creator channel', (string) $third->message);

        // a page with the existing video and a new one of the same director: said once, the new video counted
        $page[] = $this->avdbItem(4302, ['director' => 'Loli Pop Studio', 'actor' => 'Jane Doe']);
        $fourth = $this->avdbRun('4301,4302');
        $this->assertSame([1, 1], [(int) $fourth->imported, (int) $fourth->skipped]);
        $this->assertSame(1, (int) Video::query()->where('avdb_id', 4302)->value('user_id'));
        $this->assertSame(1, substr_count((string) $fourth->log, self::AVDB_LINE));
        $this->assertStringContainsString('1 video imported without a creator channel', (string) $fourth->message);
        $this->assertSame(1, User::query()->count(), 'no channel made');
        $this->assertLogNeverRepeatsTheWords((string) $first->log.$second->log.$third->log.$fourth->log);
    }

    /** One AVDBAPI run of these ids (remote thumbnails, no tags, no delay), completed. */
    private function avdbRun(string $ids): AvdbImportRun
    {
        $run = AvdbImportRun::create(['status' => 'queued', 'settings' => ['ids' => $ids, 'thumbnail_mode' => 'remote', 'skip_tags' => true, 'delay' => 0]]);
        $this->artisan('avdb:import', ['--run-id' => $run->id])->assertExitCode(0);
        $run->refresh();
        $this->assertSame('completed', $run->status);

        return $run;
    }

    /** No account whose handle or description uses a word that is always blocked exists (what the importers may make). */
    private function assertNoCreatorWithBlockedWords(): void
    {
        foreach (User::withTrashed()->get() as $user) {
            $this->assertNull(ContentSafety::hardMatchAny([$user->username, $user->description, $user->avdb_creator_name]), 'user '.$user->username);
        }
    }

    /** The run log names a refused name by its number only, never by the words that blocked it. */
    private function assertLogNeverRepeatsTheWords(string $log): void
    {
        foreach (['schoolgirl', 'Loli', 'JK Girl', 'Rori'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $log);
        }
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

                return Http::response(['code' => 1, 'total' => count($list), 'pagecount' => 1, 'list' => $list], 200);
            }

            if (str_contains($url, 'img.test/')) {
                return Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('', 404);
        });
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
            'actor' => '',
            'director' => '',
            'tag' => 'Drama',
            'category' => ['Drama'],
            'time' => '00:20:00',
            'vod_pubdate' => '2026-10-01',
            'vod_time' => '2026-10-02 00:00:00',
            'poster_url' => 'https://img.test/'.$id.'.jpg',
            'episodes' => ['server_data' => 'item-'.$id],
        ], $overrides);
    }

    private function xvidItem(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => 'Xvid item '.$id,
            'slug' => 'xvid-item-'.$id,
            'description' => 'A description.',
            'type_name' => 'Amateur',
            'tag' => 'Amateur',
            'category' => [],
            'author' => '',
            'actor' => '',
            'time' => '00:12:00',
            'quality' => 'HD',
            'poster_url' => 'https://img.test/'.$id.'.jpg',
            'episodes' => ['server_data' => ['Full' => ['link_embed' => 'https://embed.test/v/'.$id]]],
        ], $overrides);
    }
}
