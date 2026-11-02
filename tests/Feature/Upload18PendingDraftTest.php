<?php

namespace Tests\Feature;

use App\Enums\ThumbnailStatus;
use App\Enums\VideoStatus;
use App\Events\Video\VideoUploaded;
use App\Models\Upload18Claim;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Never lose a video" (1.2.3): as soon as Upload18 has the whole file, the member's browser tells the site
 * (POST /api/videos/upload18/pending), which keeps a private pending draft; the browser's finish (/complete) finishes
 * that same draft, and when the page was closed at 92 % the scheduler (upload18:finish-pending) or the draft's page does.
 * A did belongs to the first member who claimed it, for good (security plan S09), and an Upload18 item that is a
 * duplicate or not the file the browser sent is never attached. Upload18 is faked: nothing leaves this machine.
 */
class Upload18PendingDraftTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'site-key-for-tests-0001';

    private const API = 'https://upload18.test/api';

    /** @var array<string, array<string, mixed>> did => the item as Upload18 lists it */
    private array $listed = [];

    /** @var array<string, array<string, mixed>> vid => what GET /getvideodetail/{vid} answers (its "data") */
    private array $detail = [];

    private int $listCalls = 0;

    private int $detailCalls = 0;

    private bool $upload18Down = false;

    private bool $emptyAnswers = false;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'services.upload18.api_key' => self::KEY,
            'services.upload18.api_url' => self::API,
            'services.upload18.allow_server_fallback' => false,
            'site.allow_uploads' => true,
            'site.upload_max_duration' => 86400,
        ]);

        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();

            if (str_contains($url, '/myvideo')) {
                $this->listCalls++;

                if ($this->upload18Down) {
                    return Http::response('Bad gateway', 502);
                }

                if ($this->emptyAnswers) {
                    return Http::response('', 200);
                }

                return Http::response(['status' => 'success', 'data' => array_values($this->listed), 'total_page' => 1]);
            }

            if (str_contains($url, '/getvideodetail/')) {
                $this->detailCalls++;
                $vid = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));

                return isset($this->detail[$vid])
                    ? Http::response(['status' => 'success', 'data' => $this->detail[$vid]])
                    : Http::response(['status' => 'error', 'error_code' => 'not_found'], 404);
            }

            if (str_contains($url, '/uploadtoken')) {
                return Http::response(['status' => 'success', 'upload_token' => 'u18t_test.token', 'upload_url' => self::API.'/upload']);
            }

            if (str_contains($url, '/keyinfo')) {
                return Http::response(['status' => 'success', 'data' => ['type' => 'restricted', 'scopes' => ['upload', 'read']]]);
            }

            return Http::response(['status' => 'error', 'error_code' => 'not_found'], 404);
        });
    }

    public function test_announcing_a_finished_upload_keeps_a_private_pending_draft_at_once(): void
    {
        $member = User::factory()->create();
        $this->list('9001', 'vidAAA111', zt: '0', size: 2048);

        $response = $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('9001', 'vidAAA111', 'holiday.mp4', 2048, 12.27))
            ->assertCreated()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('state', Upload18Claim::PENDING);

        $video = Video::query()->findOrFail($response->json('video_id'));

        $this->assertSame($member->id, $video->user_id);
        $this->assertSame(VideoStatus::DRAFT, $video->status);
        $this->assertSame('9001', $video->upload18_vid);
        $this->assertNull($video->embed_code);
        $this->assertNull($video->uploaded_at);
        $this->assertSame('holiday', $video->title);
        $this->assertSame(2048, (int) $video->size);
        $this->assertFalse($video->is_public);
        $this->assertSame(route('user.videos.create', $video), $response->json('route'));

        $claim = Upload18Claim::query()->where('did', '9001')->firstOrFail();
        $this->assertSame($member->id, $claim->user_id);
        $this->assertSame($video->id, $claim->video_id);
        $this->assertSame(Upload18Claim::PENDING, $claim->state);
        $this->assertNotNull($claim->verified_at);
        $this->assertSame(2048, $claim->file_size);
        $this->assertEqualsWithDelta(12.27, $claim->file_duration, 0.001);

        $this->actingAs($member)->get(route('user.videos.index'))->assertOk()->assertSee('Processing on Upload18');
        $this->actingAs($member)->get(route('user.videos.create', $video))->assertOk()->assertSee('Processing on Upload18');
    }

    public function test_announcing_twice_keeps_one_draft(): void
    {
        $member = User::factory()->create();
        $this->list('9002', 'vidAAA222', zt: '1', size: 4096);
        $payload = $this->file('9002', 'vidAAA222', 'clip.mp4', 4096);

        $first = $this->actingAs($member)->postJson(route('videos.upload18.pending'), $payload)->assertCreated();
        $this->actingAs($member)->postJson(route('videos.upload18.pending'), $payload)
            ->assertOk()
            ->assertJsonPath('video_id', $first->json('video_id'));

        $this->assertSame(1, Video::query()->where('user_id', $member->id)->count());
        $this->assertSame(1, Upload18Claim::query()->count());
    }

    public function test_the_browsers_finish_completes_the_same_draft_without_a_second_video(): void
    {
        Event::fake([VideoUploaded::class]);
        $member = User::factory()->create();
        $this->list('9003', 'vidAAA333', zt: '0', size: 2048);

        $videoId = $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('9003', 'vidAAA333', 'holiday.mp4', 2048, 12.27))
            ->assertCreated()
            ->json('video_id');

        $this->list('9003', 'vidAAA333', zt: '2', size: 2048, duration: '00:12', seconds: 12.27);

        $this->actingAs($member)
            ->getJson(route('videos.upload18.status', ['did' => '9003', 'vid' => 'vidAAA333']))
            ->assertOk()
            ->assertJsonPath('ready', true);

        $finish = $this->completePayload('9003', 'vidAAA333', 'holiday.mp4', 2048, 12.27, [$this->jpeg(), $this->jpeg(40, 90, 200)]);

        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), $finish)
            ->assertOk()
            ->assertJsonPath('route', route('user.videos.create', $videoId));

        $this->assertSame(1, Video::query()->where('user_id', $member->id)->count());

        $video = Video::query()->findOrFail($videoId);
        $this->assertSame('vidAAA333', $video->embed_code);
        $this->assertNotNull($video->uploaded_at);
        $this->assertSame(12, (int) $video->getRawOriginal('duration'));
        $this->assertSame(VideoStatus::DRAFT, $video->status);

        $thumbnails = $video->thumbnails()->orderBy('id')->get();
        $this->assertCount(2, $thumbnails);
        $this->assertTrue($thumbnails->every(fn ($t) => $t->status === ThumbnailStatus::GENERATED));
        $this->assertTrue((bool) $thumbnails->first()->is_active);

        $claim = Upload18Claim::query()->where('did', '9003')->firstOrFail();
        $this->assertSame(Upload18Claim::DONE, $claim->state);
        $this->assertFalse($claim->needs_thumbnails);
        Event::assertDispatchedTimes(VideoUploaded::class, 1);

        // "Retry finishing" after it already worked: the same video, nothing new.
        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), $finish)
            ->assertOk()
            ->assertJsonPath('route', route('user.videos.create', $videoId));

        $this->assertSame(1, Video::query()->where('user_id', $member->id)->count());
        $this->assertSame(2, $video->thumbnails()->count());
        Event::assertDispatchedTimes(VideoUploaded::class, 1);
    }

    public function test_finishing_before_upload18_is_ready_says_not_ready_and_keeps_the_draft(): void
    {
        $member = User::factory()->create();
        $this->list('9004', 'vidAAA444', zt: '1', size: 2048);

        $videoId = $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('9004', 'vidAAA444', 'clip.mp4', 2048))
            ->assertCreated()
            ->json('video_id');

        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), $this->completePayload('9004', 'vidAAA444', 'clip.mp4', 2048))
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_ready');

        $video = Video::query()->findOrFail($videoId);
        $this->assertNull($video->embed_code);
        $this->assertSame(Upload18Claim::PENDING, Upload18Claim::query()->where('did', '9004')->value('state'));
    }

    public function test_the_scheduler_finishes_a_draft_whose_page_was_closed(): void
    {
        Event::fake([VideoUploaded::class]);
        $member = User::factory()->create();
        $this->list('9005', 'vidAAA555', zt: '1', size: 2048);

        $videoId = $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('9005', 'vidAAA555', 'closed-at-92.mp4', 2048, 14.0))
            ->assertCreated()
            ->json('video_id');

        // The member closed the page at 92 %. Upload18 finishes a moment later.
        $this->list('9005', 'vidAAA555', zt: '2', size: 2048, duration: '00:14', seconds: 13.97);
        $this->travel(2)->minutes();

        $this->assertSame(0, Artisan::call('upload18:finish-pending', ['--seconds' => 60]));

        $video = Video::query()->findOrFail($videoId);
        $this->assertSame('vidAAA555', $video->embed_code);
        $this->assertNotNull($video->uploaded_at);
        $this->assertSame(14, (int) $video->getRawOriginal('duration'));
        $this->assertSame(VideoStatus::DRAFT, $video->status);
        $this->assertSame(Upload18Claim::DONE, Upload18Claim::query()->where('did', '9005')->value('state'));
        Event::assertDispatchedTimes(VideoUploaded::class, 1);

        // The browser sent no frames: the minute check never waits for them (ffmpeg can take minutes); its own scheduled
        // pass makes them (poster cards here: the stream is not reachable in tests).
        $this->assertSame(0, $video->thumbnails()->where('status', ThumbnailStatus::GENERATED)->count());
        $this->assertTrue((bool) Upload18Claim::query()->where('did', '9005')->value('needs_thumbnails'));
        $this->assertTrue(\App\Console\Commands\FinishPendingUploads::thumbnailsDue());

        $this->assertSame(0, Artisan::call('upload18:finish-pending', ['--thumbnails' => true]));

        if (function_exists('imagecreatetruecolor')) {
            $this->assertGreaterThan(0, $video->thumbnails()->where('status', ThumbnailStatus::GENERATED)->count());
        }

        $this->assertFalse((bool) Upload18Claim::query()->where('did', '9005')->value('needs_thumbnails'));
        $this->assertFalse(\App\Console\Commands\FinishPendingUploads::thumbnailsDue());

        $this->actingAs($member)->get(route('user.videos.index'))->assertOk()->assertDontSee('Processing on Upload18');
    }

    public function test_the_scheduler_leaves_a_draft_waiting_and_does_not_ask_upload18_again_too_soon(): void
    {
        $member = User::factory()->create();
        $this->list('9006', 'vidAAA666', zt: '0', size: 2048);

        $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('9006', 'vidAAA666', 'slow.mp4', 2048))
            ->assertCreated();

        $this->travel(1)->minutes();
        Artisan::call('upload18:finish-pending', ['--seconds' => 30]);

        $claim = Upload18Claim::query()->where('did', '9006')->firstOrFail();
        $this->assertSame(Upload18Claim::PENDING, $claim->state);
        $this->assertSame(1, $claim->attempts);
        $this->assertTrue($claim->next_check_at->isFuture());

        $calls = $this->listCalls;
        Artisan::call('upload18:finish-pending', ['--seconds' => 30]);
        $this->assertSame($calls, $this->listCalls, 'a draft that is not due is not asked about');
        $this->assertFalse(\App\Console\Commands\FinishPendingUploads::due());
    }

    public function test_the_scheduler_keeps_waiting_while_upload18_is_down_or_answers_empty(): void
    {
        $member = User::factory()->create();
        $this->upload18Down = true;

        // Upload18 cannot be asked at the moment of the announce: the draft is kept anyway (not checked yet).
        $videoId = $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('9007', 'vidAAA777', 'outage.mp4', 2048))
            ->assertCreated()
            ->json('video_id');
        $this->assertNull(Upload18Claim::query()->where('did', '9007')->value('verified_at'));

        foreach ([[true, false], [false, true]] as [$down, $empty]) {
            $this->upload18Down = $down;
            $this->emptyAnswers = $empty;
            $this->travel(3)->minutes();
            Artisan::call('upload18:finish-pending', ['--seconds' => 30]);

            $claim = Upload18Claim::query()->where('did', '9007')->firstOrFail();
            $this->assertSame(Upload18Claim::PENDING, $claim->state, 'an outage never fails a draft');
            $this->assertSame('unavailable', $claim->error);
            $this->assertSame(0, $claim->attempts);
        }

        // A whole day of outage still fails nothing; once Upload18 answers again, the draft is finished.
        $this->travel(25)->hours();
        Artisan::call('upload18:finish-pending', ['--seconds' => 30]);
        $this->assertSame(Upload18Claim::PENDING, Upload18Claim::query()->where('did', '9007')->value('state'));

        $this->upload18Down = false;
        $this->emptyAnswers = false;
        $this->list('9007', 'vidAAA777', zt: '2', size: 2048, duration: '00:30');
        $this->travel(3)->minutes();
        Artisan::call('upload18:finish-pending', ['--seconds' => 30]);

        $this->assertSame('vidAAA777', Video::query()->findOrFail($videoId)->embed_code);
    }

    public function test_a_draft_upload18_never_lists_fails_after_a_day(): void
    {
        $member = User::factory()->create();

        $videoId = $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('9008', 'vidAAA888', 'lost.mp4', 2048))
            ->assertCreated()
            ->json('video_id');

        $this->travel(2)->minutes();
        Artisan::call('upload18:finish-pending', ['--seconds' => 30]);
        $this->assertSame(Upload18Claim::PENDING, Upload18Claim::query()->where('did', '9008')->value('state'));

        $this->travel(25)->hours();
        Artisan::call('upload18:finish-pending', ['--seconds' => 30]);

        $video = Video::query()->findOrFail($videoId);
        $this->assertSame(VideoStatus::FAILED, $video->status);
        $this->assertNull($video->embed_code);
        // Upload18 never confirmed it: the failed draft holds no did (test_a_failed_guess_never_holds_a_did).
        $this->assertNull($video->upload18_vid);
        $this->assertSame(0, Upload18Claim::query()->where('did', '9008')->count());
        $this->actingAs($member)->get(route('user.videos.index'))->assertOk()->assertSee('Failed');
    }

    public function test_one_listing_serves_every_pending_draft(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        foreach (['9101' => $first, '9102' => $first, '9103' => $second] as $did => $member) {
            $this->list($did, 'vid'.$did, zt: '1', size: 1000 + (int) substr($did, -1));
            $this->actingAs($member)
                ->postJson(route('videos.upload18.pending'), $this->file($did, 'vid'.$did, 'v'.$did.'.mp4', 1000 + (int) substr($did, -1)))
                ->assertCreated();
        }

        $this->travel(2)->minutes();
        $calls = $this->listCalls;
        Artisan::call('upload18:finish-pending', ['--seconds' => 30]);

        $this->assertSame($calls + 1, $this->listCalls, 'one Upload18 list call for all three drafts');
        $this->assertSame(3, Upload18Claim::query()->where('attempts', 1)->count());
    }

    public function test_another_members_did_is_refused_for_good(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->list('9201', 'vidBBB201', zt: '2', size: 5000, duration: '01:00');

        $this->actingAs($owner)
            ->postJson(route('videos.upload18.pending'), $this->file('9201', 'vidBBB201', 'mine.mp4', 5000))
            ->assertCreated();

        $this->actingAs($other)
            ->postJson(route('videos.upload18.pending'), $this->file('9201', 'vidBBB201', 'mine.mp4', 5000))
            ->assertForbidden()
            ->assertJsonPath('code', 'claimed');

        $this->actingAs($other)
            ->getJson(route('videos.upload18.status', ['did' => '9201', 'vid' => 'vidBBB201']))
            ->assertForbidden();

        $this->actingAs($other)
            ->postJson(route('videos.upload18.complete'), $this->completePayload('9201', 'vidBBB201', 'mine.mp4', 5000))
            ->assertForbidden();

        // Long after the 30 minutes of the old cache claim (security plan S09): still the owner's.
        Cache::flush();
        $this->travel(2)->hours();

        $this->actingAs($other)
            ->postJson(route('videos.upload18.complete'), $this->completePayload('9201', 'vidBBB201', 'mine.mp4', 5000))
            ->assertForbidden()
            ->assertJsonPath('code', 'claimed');
        $this->actingAs($other)
            ->getJson(route('videos.upload18.status', ['did' => '9201', 'vid' => 'vidBBB201']))
            ->assertForbidden();

        $this->assertSame(0, Video::query()->where('user_id', $other->id)->count());

        $this->actingAs($owner)
            ->postJson(route('videos.upload18.complete'), $this->completePayload('9201', 'vidBBB201', 'mine.mp4', 5000))
            ->assertOk();
        $this->assertSame('vidBBB201', Video::query()->where('user_id', $owner->id)->value('embed_code'));
    }

    public function test_a_finished_did_stays_with_its_member_after_the_video_is_deleted(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->list('9202', 'vidBBB202', zt: '2', size: 7000, duration: '00:40');

        // A page opened before 1.2.3 (no announce): status, then finish.
        $this->actingAs($owner)->getJson(route('videos.upload18.status', ['did' => '9202', 'vid' => 'vidBBB202']))->assertOk();
        $this->actingAs($owner)
            ->postJson(route('videos.upload18.complete'), $this->completePayload('9202', 'vidBBB202', 'gone.mp4', 7000))
            ->assertOk();

        $video = Video::query()->where('user_id', $owner->id)->firstOrFail();
        $this->actingAs($owner)->delete(route('user.videos.destroy', $video))->assertRedirect();
        $this->assertNull(Video::withTrashed()->find($video->id));

        Cache::flush();

        $this->actingAs($other)
            ->getJson(route('videos.upload18.status', ['did' => '9202', 'vid' => 'vidBBB202']))
            ->assertForbidden();
        $this->actingAs($other)
            ->postJson(route('videos.upload18.complete'), $this->completePayload('9202', 'vidBBB202', 'gone.mp4', 7000))
            ->assertForbidden();
        $this->assertSame(0, Video::query()->where('user_id', $other->id)->count());
    }

    public function test_a_different_file_handed_back_under_the_same_did_is_never_attached(): void
    {
        $member = User::factory()->create();
        // Upload18 handed back the item of an earlier, different file (3,312,563 bytes) for this one (3,812,493 bytes).
        $this->list('9301', 'vidCCC301', zt: '2', size: 3312563, duration: '00:12', seconds: 12.27);

        $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('9301', 'vidCCC301', 'same-name.mp4', 3812493, 13.97))
            ->assertStatus(409)
            ->assertJsonPath('code', 'mismatch')
            ->assertJsonPath('message', 'The video Upload18 returned does not match your file, so it was not added. Please upload it again.');

        $this->assertSame(0, Video::query()->count());
        $this->assertSame(0, Upload18Claim::query()->count());

        // A page opened before 1.2.3 (no announce): the finish refuses it too.
        $this->actingAs($member)->getJson(route('videos.upload18.status', ['did' => '9301', 'vid' => 'vidCCC301']))->assertOk();
        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), $this->completePayload('9301', 'vidCCC301', 'same-name.mp4', 3812493))
            ->assertStatus(409)
            ->assertJsonPath('code', 'mismatch');

        $this->assertSame(0, Video::query()->count());
    }

    public function test_an_upload18_duplicate_is_never_attached(): void
    {
        $member = User::factory()->create();
        $this->list('9302', 'vidCCC302', zt: '2', size: 2048, duration: '00:10');

        $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('9302', 'vidCCC302', 'again.mp4', 2048) + ['duplicate' => 1])
            ->assertStatus(409)
            ->assertJsonPath('code', 'duplicate');
        $this->assertSame(0, Video::query()->count());

        // The listed item itself says duplicate = 1.
        $this->listed['9302']['duplicate'] = 1;
        $this->actingAs($member)->getJson(route('videos.upload18.status', ['did' => '9302', 'vid' => 'vidCCC302']))->assertOk();
        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), $this->completePayload('9302', 'vidCCC302', 'again.mp4', 2048))
            ->assertStatus(409)
            ->assertJsonPath('code', 'duplicate');
        $this->assertSame(0, Video::query()->count());
    }

    public function test_another_length_is_refused_when_both_lengths_are_known(): void
    {
        $member = User::factory()->create();
        $this->list('9303', 'vidCCC303', zt: '2', size: 2048, duration: '00:12', seconds: 12.0);

        $this->actingAs($member)->getJson(route('videos.upload18.status', ['did' => '9303', 'vid' => 'vidCCC303']))->assertOk();
        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), $this->completePayload('9303', 'vidCCC303', 'long.mp4', 2048, 60.0))
            ->assertStatus(409)
            ->assertJsonPath('code', 'mismatch');
        $this->assertSame(0, Video::query()->count());

        // Container and stream lengths differ a little: that is the same file.
        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), $this->completePayload('9303', 'vidCCC303', 'long.mp4', 2048, 12.9))
            ->assertOk();
        $this->assertSame(1, Video::query()->count());
    }

    public function test_the_scheduler_never_attaches_an_item_that_is_not_the_announced_file(): void
    {
        $member = User::factory()->create();

        // Announced before Upload18 listed it (not checked yet), with 1,111 bytes; Upload18 then lists 2,222 bytes.
        $videoId = $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('9304', 'vidCCC304', 'mine.mp4', 1111))
            ->assertCreated()
            ->json('video_id');

        $this->list('9304', 'vidCCC304', zt: '2', size: 2222, duration: '00:20');
        $this->travel(2)->minutes();
        Artisan::call('upload18:finish-pending', ['--seconds' => 30]);

        $video = Video::query()->findOrFail($videoId);
        $this->assertSame(VideoStatus::FAILED, $video->status);
        $this->assertNull($video->embed_code);
        $this->assertNull($video->upload18_vid, 'never this member\'s upload: the did is free again');
        $this->assertSame(0, Upload18Claim::query()->where('did', '9304')->count());

        // Checked at the announce (size and vid matched), but Upload18's length is not the file's: never attached either.
        $this->list('9305', 'vidCCC305', zt: '1', size: 3333);
        $secondId = $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('9305', 'vidCCC305', 'short.mp4', 3333, 10.0))
            ->assertCreated()
            ->json('video_id');
        $this->list('9305', 'vidCCC305', zt: '2', size: 3333, duration: '05:00', seconds: 300.0);
        $this->travel(2)->minutes();
        Artisan::call('upload18:finish-pending', ['--seconds' => 30]);

        $this->assertNull(Video::query()->findOrFail($secondId)->embed_code);
        $this->assertSame(VideoStatus::FAILED, Video::query()->findOrFail($secondId)->status);
        $this->assertSame('mismatch', Upload18Claim::query()->where('did', '9305')->value('error'));
    }

    public function test_a_claim_nobody_could_check_does_not_lock_out_the_real_uploader(): void
    {
        $guesser = User::factory()->create();
        $uploader = User::factory()->create();

        // Announced for a did Upload18 does not list yet, with made-up details.
        $guessedVideoId = $this->actingAs($guesser)
            ->postJson(route('videos.upload18.pending'), $this->file('9401', 'vidGUESS', 'guess.mp4', 1000))
            ->assertCreated()
            ->json('video_id');

        // The real upload gets that did.
        $this->list('9401', 'vidREAL401', zt: '1', size: 2048);

        $this->actingAs($uploader)
            ->postJson(route('videos.upload18.pending'), $this->file('9401', 'vidREAL401', 'real.mp4', 2048))
            ->assertCreated();

        $this->assertSame($uploader->id, Upload18Claim::query()->where('did', '9401')->value('user_id'));
        $this->assertNull(Video::withTrashed()->find($guessedVideoId));
    }

    public function test_pages_opened_before_the_update_still_finish_through_status_and_complete(): void
    {
        $member = User::factory()->create();
        $this->list('9501', 'vidDDD501', zt: '2', size: 4096, duration: '01:30');

        $this->actingAs($member)->getJson(route('videos.upload18.status', ['did' => '9501', 'vid' => 'vidDDD501']))->assertOk();
        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), [
                'did' => '9501',
                'vid' => 'vidDDD501',
                'filename' => 'old-page.mp4',
                'size' => 4096,
                'duration_seconds' => 90,
                'thumbnails' => [$this->jpeg()],
            ])
            ->assertOk();

        $video = Video::query()->where('user_id', $member->id)->firstOrFail();
        $this->assertSame('vidDDD501', $video->embed_code);
        $this->assertSame(90, (int) $video->getRawOriginal('duration'));
        $this->assertSame(1, $video->thumbnails()->where('status', ThumbnailStatus::GENERATED)->count());

        $claim = Upload18Claim::query()->where('did', '9501')->firstOrFail();
        $this->assertSame([$member->id, $video->id, Upload18Claim::DONE], [$claim->user_id, $claim->video_id, $claim->state]);
    }

    public function test_a_free_member_can_finish_the_last_upload_the_plan_allows(): void
    {
        config(['plans.free.max_uploads' => 1]);
        $member = User::factory()->create();
        $this->list('9601', 'vidEEE601', zt: '1', size: 2048);

        $this->actingAs($member)->getJson(route('videos.upload18.config'))->assertOk();
        $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('9601', 'vidEEE601', 'last.mp4', 2048))
            ->assertCreated();

        // Its own draft is not counted twice at 92 %.
        $this->actingAs($member)->getJson(route('videos.upload18.status', ['did' => '9601', 'vid' => 'vidEEE601']))->assertOk();

        $this->list('9601', 'vidEEE601', zt: '2', size: 2048, duration: '00:20');
        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), $this->completePayload('9601', 'vidEEE601', 'last.mp4', 2048))
            ->assertOk();

        // A further upload is refused by the plan as before.
        $this->actingAs($member)->getJson(route('videos.upload18.config'))->assertForbidden();
        $this->actingAs($member)->getJson(route('videos.upload18.status', ['did' => '9999', 'vid' => 'x']))->assertForbidden();
    }

    public function test_the_draft_page_finishes_a_due_draft_without_the_scheduler(): void
    {
        $member = User::factory()->create();
        $this->list('9701', 'vidFFF701', zt: '0', size: 2048);

        $videoId = $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('9701', 'vidFFF701', 'no-cron.mp4', 2048))
            ->assertCreated()
            ->json('video_id');

        $this->actingAs($member)
            ->getJson(route('videos.create-status', ['video' => $videoId]))
            ->assertOk()
            ->assertJsonPath('upload18_pending', true)
            ->assertJsonPath('has_embed_code', false);

        $this->list('9701', 'vidFFF701', zt: '2', size: 2048, duration: '00:33');
        $this->travel(2)->minutes();

        $this->actingAs($member)
            ->getJson(route('videos.create-status', ['video' => $videoId]))
            ->assertOk()
            ->assertJsonPath('upload18_pending', false)
            ->assertJsonPath('has_embed_code', true)
            ->assertJsonPath('is_failed', false);

        $this->assertSame('vidFFF701', Video::query()->findOrFail($videoId)->embed_code);
    }

    public function test_a_view_only_demo_account_cannot_announce_an_upload(): void
    {
        $demo = User::factory()->create(['is_admin' => true]);
        $demo->forceFill(['is_view_only' => true])->save();

        $this->actingAs($demo)
            ->postJson(route('videos.upload18.pending'), $this->file('9801', 'vidGGG801', 'demo.mp4', 2048))
            ->assertForbidden();

        $this->assertSame(0, Video::query()->count());
        $this->assertSame(0, Upload18Claim::query()->count());
    }

    public function test_the_claims_migration_keeps_older_member_uploads_with_their_member(): void
    {
        $owner = User::factory()->create();
        $video = Video::factory()->for($owner)->create(['upload18_vid' => '9901', 'embed_code' => 'vidOLD901', 'original_file_name' => 'old.mp4', 'size' => 1234]);
        Video::factory()->for(User::factory())->create(['upload18_vid' => null, 'embed_code' => 'https://upload18.net/play/index/imported']);
        DB::table('upload18_claims')->delete();

        $migration = require database_path('migrations/2026_10_25_140000_create_upload18_claims_table.php');
        $migration->up();
        $migration->up();

        $this->assertSame(1, Upload18Claim::query()->count());
        $claim = Upload18Claim::query()->where('did', '9901')->firstOrFail();
        $this->assertSame([$owner->id, $video->id, 'vidOLD901', Upload18Claim::DONE], [$claim->user_id, $claim->video_id, $claim->vid, $claim->state]);

        $other = User::factory()->create();
        $this->list('9901', 'vidOLD901', zt: '2', size: 1234, duration: '00:10');
        $this->actingAs($other)
            ->getJson(route('videos.upload18.status', ['did' => '9901', 'vid' => 'vidOLD901']))
            ->assertForbidden();
    }

    /**
     * Security fix (S09): /complete with any did and the embed code of one of the member's own videos used to take the
     * "existing video" branch and record that did as the member's for good, with no Upload18 check.
     */
    public function test_a_member_cannot_take_a_did_with_the_embed_code_of_an_own_video(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $own = Video::factory()->for($member)->create(['upload18_vid' => '1000', 'embed_code' => 'vidMINE1000']);

        $calls = $this->listCalls;
        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), $this->completePayload('7999', 'vidMINE1000', 'x.mp4', 1))
            ->assertOk()
            ->assertJsonPath('route', route('user.videos.create', $own));

        $this->assertSame($calls, $this->listCalls, 'answered from the member\'s own video, as before 1.2.3');
        $this->assertSame(0, Upload18Claim::query()->where('did', '7999')->count(), 'a did the member never uploaded stays free');

        // The upload that really gets did 7999 stays its member's.
        $this->list('7999', 'vidREAL7999', zt: '0', size: 2048);
        $this->actingAs($other)
            ->postJson(route('videos.upload18.pending'), $this->file('7999', 'vidREAL7999', 'holiday.mp4', 2048))
            ->assertCreated();
        $this->assertSame($other->id, Upload18Claim::query()->where('did', '7999')->value('user_id'));

        // The member's own earlier upload (the same did) is still recorded as theirs on a repeated finish.
        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), $this->completePayload('1000', 'vidMINE1000', 'x.mp4', 1))
            ->assertOk()
            ->assertJsonPath('route', route('user.videos.create', $own));
        $this->assertSame([$member->id, $own->id], [
            (int) Upload18Claim::query()->where('did', '1000')->value('user_id'),
            (int) Upload18Claim::query()->where('did', '1000')->value('video_id'),
        ]);
    }

    /**
     * A claim is verified (kept for good) only on positive evidence. A guess without the upload answer's vid, whose size
     * Upload18 does not list, gives way to the member whose browser has that vid.
     */
    public function test_a_guess_without_evidence_gives_way_to_the_member_whose_browser_has_the_vid(): void
    {
        $guesser = User::factory()->create();
        $uploader = User::factory()->create();

        $guessId = $this->actingAs($guesser)
            ->postJson(route('videos.upload18.pending'), ['did' => '7401', 'filename' => 'guess.mp4', 'size' => 1000])
            ->assertCreated()
            ->json('video_id');

        // Upload18 lists the real upload without its size: nothing confirms the guess.
        $this->listed['7401'] = ['id' => 7401, 'vid' => 'vidREAL7401', 'zt' => '0', 'duration' => '00:00'];

        // The scheduler's check does not make the guess "verified" either.
        $this->travel(1)->minutes();
        Artisan::call('upload18:finish-pending', ['--seconds' => 30]);
        $this->assertNull(Upload18Claim::query()->where('did', '7401')->value('verified_at'));

        $this->actingAs($uploader)
            ->postJson(route('videos.upload18.pending'), $this->file('7401', 'vidREAL7401', 'real.mp4', 2048))
            ->assertCreated();

        $claim = Upload18Claim::query()->where('did', '7401')->firstOrFail();
        $this->assertSame($uploader->id, $claim->user_id);
        $this->assertNotNull($claim->verified_at, 'the upload answer\'s vid is this member\'s evidence');
        $this->assertNull(Video::withTrashed()->find($guessId), 'the guess and its empty draft are gone');
    }

    /** While Upload18 lists nothing, another member's unconfirmed claim never refuses the uploader: the size decides later. */
    public function test_a_guess_never_refuses_the_uploader_while_upload18_lists_nothing(): void
    {
        $guesser = User::factory()->create();
        $uploader = User::factory()->create();

        $this->actingAs($guesser)
            ->postJson(route('videos.upload18.pending'), ['did' => '7403', 'filename' => 'guess.mp4', 'size' => 1000])
            ->assertCreated();

        $this->actingAs($uploader)
            ->postJson(route('videos.upload18.pending'), $this->file('7403', 'vidREAL7403', 'real.mp4', 2048, 20.0))
            ->assertStatus(202)
            ->assertJsonPath('saved', false);
        $this->assertSame($guesser->id, Upload18Claim::query()->where('did', '7403')->value('user_id'));

        // Upload18 lists the item with its size: the guess is not this file, so the uploader's normal finish goes through.
        $this->list('7403', 'vidREAL7403', zt: '2', size: 2048, duration: '00:20', seconds: 20.0);

        $this->actingAs($uploader)
            ->getJson(route('videos.upload18.status', ['did' => '7403', 'vid' => 'vidREAL7403']))
            ->assertOk()
            ->assertJsonPath('ready', true);
        $this->actingAs($uploader)
            ->postJson(route('videos.upload18.complete'), $this->completePayload('7403', 'vidREAL7403', 'real.mp4', 2048, 20.0))
            ->assertOk();

        $this->assertSame('vidREAL7403', Video::query()->where('user_id', $uploader->id)->value('embed_code'));
        $this->assertSame(0, Video::withTrashed()->where('user_id', $guesser->id)->count());
        $this->assertSame($uploader->id, Upload18Claim::query()->where('did', '7403')->value('user_id'));
    }

    /** "Retry finishing" and the draft's page find an upload that left the newest 150 listed videos (GET /getvideodetail). */
    public function test_retry_finishing_and_the_draft_page_find_an_upload_that_left_the_newest_list(): void
    {
        $member = User::factory()->create();

        foreach (['7501', '7502'] as $did) {
            $this->list($did, 'vid'.$did, zt: '1', size: 2048);
            $this->actingAs($member)
                ->postJson(route('videos.upload18.pending'), $this->file($did, 'vid'.$did, 'busy'.$did.'.mp4', 2048))
                ->assertCreated();

            // The shared account got busy: Upload18 knows the upload only by its vid now.
            unset($this->listed[$did]);
            $this->detail['vid'.$did] = ['id' => (int) $did, 'vid' => 'vid'.$did, 'zt' => 2, 'size_bytes' => 2048, 'duration_seconds' => 20.0, 'duration' => '00:20'];
        }

        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), $this->completePayload('7501', 'vid7501', 'busy7501.mp4', 2048))
            ->assertOk();
        $this->assertSame('vid7501', Video::query()->where('upload18_vid', '7501')->value('embed_code'));

        $draft = Video::query()->where('upload18_vid', '7502')->firstOrFail();
        $this->travel(2)->minutes();
        $this->actingAs($member)
            ->getJson(route('videos.create-status', ['video' => $draft->id]))
            ->assertOk()
            ->assertJsonPath('has_embed_code', true)
            ->assertJsonPath('upload18_pending', false);
        $this->assertSame('vid7502', $draft->fresh()->embed_code);
    }

    /** The detail of a video is used only when it names the claimed did: a public embed code of another upload is never attached. */
    public function test_a_video_detail_of_another_upload_is_never_attached(): void
    {
        $member = User::factory()->create();

        // Announced with the embed code of someone else's public video (any watch page shows it) and a did nobody lists.
        $this->detail['vidPUBLIC'] = ['id' => 5555, 'vid' => 'vidPUBLIC', 'zt' => 2, 'size_bytes' => 1000, 'duration_seconds' => 9.0];
        $this->detail['vidNOID'] = ['vid' => 'vidNOID', 'zt' => 2, 'size_bytes' => 1000, 'duration_seconds' => 9.0];

        $first = $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('7601', 'vidPUBLIC', 'a.mp4', 1000))
            ->assertCreated()->json('video_id');
        $second = $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('7602', 'vidNOID', 'b.mp4', 1000))
            ->assertCreated()->json('video_id');

        $this->travel(2)->minutes();
        Artisan::call('upload18:finish-pending', ['--seconds' => 30]);
        $this->assertGreaterThan(0, $this->detailCalls);

        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), $this->completePayload('7601', 'vidPUBLIC', 'a.mp4', 1000))
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_ready');

        foreach ([$first, $second] as $videoId) {
            $this->assertNull(Video::query()->findOrFail($videoId)->embed_code);
        }

        $this->assertSame(2, Upload18Claim::query()->where('state', Upload18Claim::PENDING)->count());
    }

    /** "Retry finishing" after the 10-minute wait may carry no vid (the upload answer had none): the kept draft still finishes. */
    public function test_a_finish_without_a_vid_finishes_the_kept_draft_and_never_answers_422(): void
    {
        $member = User::factory()->create();

        $videoId = $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), ['did' => '7701', 'vid' => '', 'filename' => 'novid.mp4', 'size' => 2048])
            ->assertCreated()
            ->json('video_id');

        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), ['did' => '7701', 'vid' => '', 'filename' => 'novid.mp4', 'size' => 2048])
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_ready');

        $this->list('7701', 'vid7701', zt: '2', size: 2048, duration: '00:15');
        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), ['did' => '7701', 'vid' => '', 'filename' => 'novid.mp4', 'size' => 2048])
            ->assertOk()
            ->assertJsonPath('route', route('user.videos.create', $videoId));
        $this->assertSame('vid7701', Video::query()->findOrFail($videoId)->embed_code);

        // A page without a kept draft: still a passing answer ("check the status first"), never a validation error.
        $this->list('7702', 'vid7702', zt: '2', size: 4096, duration: '00:15');
        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), ['did' => '7702', 'vid' => '', 'filename' => 'novid2.mp4', 'size' => 4096])
            ->assertStatus(409)
            ->assertJsonPath('code', 'status_required');
        $this->assertSame(0, Video::query()->where('upload18_vid', '7702')->count());

        // The browser always sends the field (empty at most): a request without it is refused as before.
        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), ['did' => '7702', 'filename' => 'novid2.mp4', 'size' => 4096])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vid']);
    }

    /** A draft that fails while its page is open: the page's check says so once (with the reason), then answers 403. */
    public function test_the_draft_page_is_told_when_its_upload_failed(): void
    {
        $member = User::factory()->create();

        $videoId = $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('7801', 'vid7801', 'mine.mp4', 1111))
            ->assertCreated()
            ->json('video_id');

        $page = $this->actingAs($member)->get(route('user.videos.create', $videoId))->assertOk();
        $page->assertSee('data-upload18-failed-notice', false);
        $page->assertSee('href="'.locale_url(route('user.videos.index')).'"', false);
        $page->assertSee('Manage videos');
        $page->assertSee('data-video-draft-form', false);

        // Upload18 lists another file under that did.
        $this->list('7801', 'vid7801', zt: '2', size: 2222, duration: '00:20');
        $this->travel(2)->minutes();

        $this->actingAs($member)
            ->getJson(route('videos.create-status', ['video' => $videoId]))
            ->assertOk()
            ->assertJsonPath('is_failed', true)
            ->assertJsonPath('upload18_pending', false)
            ->assertJsonPath('message', 'The video Upload18 returned does not match your file, so it was not added. Please upload it again.');

        $this->actingAs($member)->getJson(route('videos.create-status', ['video' => $videoId]))->assertForbidden();
    }

    /**
     * The minute check of pending drafts and the server thumbnails are two scheduled tasks: ffmpeg on a long stream can
     * take minutes, and the check of every other draft must never wait for it.
     */
    public function test_the_scheduler_runs_the_minute_check_and_the_server_thumbnails_as_two_tasks(): void
    {
        $events = collect(app(\Illuminate\Contracts\Console\Kernel::class)->resolveConsoleSchedule()->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'upload18:finish-pending'))
            ->values();

        $this->assertCount(2, $events);

        $check = $events->first(fn ($event): bool => str_contains((string) $event->command, '--seconds=50'));
        $thumbnails = $events->first(fn ($event): bool => str_contains((string) $event->command, '--thumbnails'));

        $this->assertNotNull($check);
        $this->assertNotNull($thumbnails);

        foreach ([$check, $thumbnails] as $event) {
            $this->assertSame('* * * * *', $event->expression);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->runInBackground);
        }

        $this->assertNotSame($check->mutexName(), $thumbnails->mutexName());

        // Neither starts while there is nothing to do.
        $this->assertFalse(\App\Console\Commands\FinishPendingUploads::due());
        $this->assertFalse(\App\Console\Commands\FinishPendingUploads::thumbnailsDue());
    }

    /**
     * A guess for a did Upload18 has not reached yet fails after a day, and then holds nothing: only a claim Upload18
     * confirmed keeps its did after it failed. Otherwise a member could fill future dids with guesses, and each real
     * upload that later gets one would be refused at 92 % as "already claimed".
     */
    public function test_a_failed_guess_never_holds_a_did(): void
    {
        $guesser = User::factory()->create();
        $uploader = User::factory()->create();

        $guessId = $this->actingAs($guesser)
            ->postJson(route('videos.upload18.pending'), ['did' => '8801', 'filename' => 'future.mp4', 'size' => 1000])
            ->assertCreated()
            ->json('video_id');

        $this->travel(25)->hours();
        Artisan::call('upload18:finish-pending', ['--seconds' => 30]);

        $draft = Video::withTrashed()->findOrFail($guessId);
        $this->assertSame(VideoStatus::FAILED, $draft->status);
        $this->assertNull($draft->upload18_vid);
        $this->assertSame(0, Upload18Claim::query()->where('did', '8801')->count());

        // Days later the real upload gets that did.
        $this->list('8801', 'vidREAL8801', zt: '0', size: 4096);
        $this->actingAs($uploader)
            ->postJson(route('videos.upload18.pending'), $this->file('8801', 'vidREAL8801', 'real.mp4', 4096))
            ->assertCreated();
        $this->assertSame($uploader->id, Upload18Claim::query()->where('did', '8801')->value('user_id'));

        // A claim Upload18 confirmed stays its member's when it fails (here: longer than the site allows).
        config(['site.upload_max_duration' => 60]);
        $this->list('8802', 'vidLONG8802', zt: '1', size: 5000);
        $this->actingAs($uploader)
            ->postJson(route('videos.upload18.pending'), $this->file('8802', 'vidLONG8802', 'long.mp4', 5000))
            ->assertCreated();
        $this->list('8802', 'vidLONG8802', zt: '2', size: 5000, duration: '05:00', seconds: 300.0);
        $this->travel(2)->minutes();
        Artisan::call('upload18:finish-pending', ['--seconds' => 30]);

        $claim = Upload18Claim::query()->where('did', '8802')->firstOrFail();
        $this->assertSame([Upload18Claim::FAILED, 'too_long', $uploader->id], [$claim->state, $claim->error, $claim->user_id]);
    }

    /** Without a saved key there is nobody to ask: the minute task does not start, and the drafts wait for a key. */
    public function test_without_a_saved_key_the_minute_task_waits_for_one(): void
    {
        $member = User::factory()->create();

        $videoId = $this->actingAs($member)
            ->postJson(route('videos.upload18.pending'), $this->file('7901', 'vid7901', 'nokey.mp4', 2048))
            ->assertCreated()
            ->json('video_id');

        $this->travel(1)->minutes();
        $this->assertTrue(\App\Console\Commands\FinishPendingUploads::due());

        config(['services.upload18.api_key' => '']);
        $this->assertFalse(\App\Console\Commands\FinishPendingUploads::due());

        $calls = $this->listCalls;
        $this->travel(30)->hours();
        Artisan::call('upload18:finish-pending', ['--seconds' => 10]);
        $this->assertSame($calls, $this->listCalls);
        $this->assertSame(Upload18Claim::PENDING, Upload18Claim::query()->where('did', '7901')->value('state'));

        // The owner saves a key again: the draft is finished.
        config(['services.upload18.api_key' => self::KEY]);
        $this->list('7901', 'vid7901', zt: '2', size: 2048, duration: '00:11');
        Artisan::call('upload18:finish-pending', ['--seconds' => 10]);
        $this->assertSame('vid7901', Video::query()->findOrFail($videoId)->embed_code);
    }

    /** @return array<string, mixed> */
    private function file(string $did, string $vid, string $name, int $size, ?float $seconds = null): array
    {
        return array_filter([
            'did' => $did,
            'vid' => $vid,
            'filename' => $name,
            'size' => $size,
            'file_duration' => $seconds,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    private function completePayload(string $did, string $vid, string $name, int $size, ?float $seconds = null, array $thumbnails = []): array
    {
        return array_filter([
            'did' => $did,
            'vid' => $vid,
            'filename' => $name,
            'size' => $size,
            'file_duration' => $seconds,
            'thumbnails' => $thumbnails ?: null,
        ], fn ($value) => $value !== null);
    }

    private function list(string $did, string $vid, string $zt, int $size, string $duration = '00:00', ?float $seconds = null): void
    {
        $this->listed[$did] = array_filter([
            'id' => (int) $did,
            'vid' => $vid,
            'name' => 'test-'.$did,
            'zt' => $zt,
            'duration' => $duration,
            'duration_seconds' => $seconds,
            'size_bytes' => $size,
        ], fn ($value) => $value !== null);
    }

    /** A real JPEG frame as the member's browser sends it (data URL). */
    private function jpeg(int $r = 200, int $g = 40, int $b = 40): string
    {
        $image = imagecreatetruecolor(64, 36);
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();

        return 'data:image/jpeg;base64,'.base64_encode($bytes);
    }
}
