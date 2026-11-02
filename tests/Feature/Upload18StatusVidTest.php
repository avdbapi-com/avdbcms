<?php

namespace Tests\Feature;

use App\Models\Upload18Claim;
use App\Models\User;
use App\Models\Video;
use App\Support\Upload18\UploadClaims;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Round 1b judge R1B-027 (S09 residual; owner-approved change of the Upload18 status check, 2026-10-27, server side only):
 * GET /api/videos/upload18/status?did=N answered {found, ready, vid, ...} for any did among Upload18's newest items that
 * has no claim (an upload made before 1.2.3 that never reached /complete, an announce that never finished, a released
 * claim, a video the owner put on Upload18 directly). With the vid a member could play a video that never became a site
 * video.
 *
 * Now the vid (and the length, which could stand in for a size check) is given only
 *  - to the member who holds the did (a claim Upload18 confirmed, a finished upload, or a pending claim whose file the
 *    listing confirms now), or
 *  - to a request that already brings the matching vid (the member's browser got it in Upload18's own upload answer:
 *    the normal flow of VideoUpload.tsx, answered exactly as before);
 * an unclaimed did gets no vid until the member has gone through /pending with its size check (between an update's file
 * swap and its Finish, before the claims table exists, every did is unclaimed), and such a check no longer claims the
 * did for 30 minutes. Guests, unverified members and the view-only demo account are refused as before.
 * Upload18 is faked: nothing leaves this machine, no real upload.
 */
class Upload18StatusVidTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'site-key-for-tests-r1b027';

    private const API = 'https://upload18.test/api';

    /** @var array<string, array<string, mixed>> did => the item as Upload18 lists it */
    private array $listed = [];

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
                return Http::response(['status' => 'success', 'data' => array_values($this->listed), 'total_page' => 1]);
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

    public function test_an_unclaimed_did_gets_no_vid_until_the_member_announced_it_with_its_size(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        // An item nobody on this site claimed (e.g. an upload made before 1.2.3 that never reached /complete).
        $this->list('8101', 'vidUNC8101', zt: '2', size: 2048, duration: '00:12', seconds: 12.0);

        $this->assertWithoutVid($this->checkStatus($member, '8101'), found: true);
        // The check claimed nothing: another member is not locked out, and gets no vid either.
        $this->assertWithoutVid($this->checkStatus($other, '8101'), found: true);
        $this->assertNull(Cache::get('upload18:claim:'.hash('sha256', '8101')));

        // Another member's guess of the size fails the size check: still no vid for them.
        $this->actingAs($other)->postJson(route('videos.upload18.pending'), $this->file('8101', '', 'guess.mp4', 1000))
            ->assertStatus(409)->assertJsonPath('code', 'mismatch');
        $this->assertWithoutVid($this->checkStatus($other, '8101'), found: true);

        // The member whose file it is goes through /pending with its size: from now on the check gives the vid.
        $this->actingAs($member)->postJson(route('videos.upload18.pending'), $this->file('8101', '', 'mine.mp4', 2048))->assertCreated();
        $this->checkStatus($member, '8101')->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('ready', true)
            ->assertJsonPath('vid', 'vidUNC8101')
            ->assertJsonPath('duration_seconds', 12);

        // ... and to nobody else: the did is the member's now.
        $this->checkStatus($other, '8101')->assertForbidden()->assertJsonMissing(['vid' => 'vidUNC8101']);
    }

    public function test_a_did_whose_item_has_no_vid_yet_never_claims_it_for_the_caller(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $this->list('8102', '', zt: '1', size: 2048);

        $this->assertWithoutVid($this->checkStatus($other, '8102'), found: true);

        // Upload18 finishes; the member's own browser (with the vid of its upload answer) is not locked out by the other's check.
        $this->list('8102', 'vidOWN8102', zt: '2', size: 2048, duration: '00:30');
        $this->checkStatus($member, '8102', 'vidOWN8102')->assertOk()->assertJsonPath('ready', true)->assertJsonPath('vid', 'vidOWN8102');
    }

    public function test_the_members_own_claim_gives_the_vid(): void
    {
        $member = User::factory()->create();
        $this->list('8201', 'vidOWN8201', zt: '1', size: 4096);

        // Announced with the upload answer's vid and size: the claim is confirmed at once.
        $this->actingAs($member)->postJson(route('videos.upload18.pending'), $this->file('8201', 'vidOWN8201', 'own.mp4', 4096))->assertCreated();
        $this->list('8201', 'vidOWN8201', zt: '2', size: 4096, duration: '01:00', seconds: 60.0);

        // With or without the vid in the request (an older browser page, "Keep waiting"): the member's own did.
        foreach (['', 'vidOWN8201'] as $vid) {
            $this->checkStatus($member, '8201', $vid)->assertOk()
                ->assertJsonPath('ready', true)
                ->assertJsonPath('vid', 'vidOWN8201')
                ->assertJsonPath('duration_seconds', 60);
        }

        // Finished, then checked again: still the member's.
        $this->actingAs($member)->postJson(route('videos.upload18.complete'), $this->completePayload('8201', 'vidOWN8201', 'own.mp4', 4096))->assertOk();
        $this->assertSame(Upload18Claim::DONE, Upload18Claim::query()->where('did', '8201')->value('state'));
        $this->checkStatus($member, '8201')->assertOk()->assertJsonPath('vid', 'vidOWN8201');
    }

    public function test_an_own_claim_made_before_upload18_listed_it_gets_the_vid_only_when_the_listing_confirms_it(): void
    {
        $member = User::factory()->create();
        $guesser = User::factory()->create();

        // The member announced before Upload18 listed the item (nothing could be checked yet).
        $this->actingAs($member)->postJson(route('videos.upload18.pending'), $this->file('8301', '', 'early.mp4', 2048))->assertCreated();
        $this->assertNull(Upload18Claim::query()->where('did', '8301')->value('verified_at'));
        $this->assertWithoutVid($this->checkStatus($member, '8301'), found: false);

        // Upload18 lists it with the member's size: the listing confirms the claim, the vid is the member's.
        $this->list('8301', 'vidEARLY8301', zt: '2', size: 2048, duration: '00:20');
        $this->checkStatus($member, '8301')->assertOk()->assertJsonPath('vid', 'vidEARLY8301')->assertJsonPath('ready', true);

        // A guess announced for a did Upload18 had not reached yet, with a size that is not the file's: no vid.
        $this->actingAs($guesser)->postJson(route('videos.upload18.pending'), $this->file('8302', '', 'guess.mp4', 1000))->assertCreated();
        $this->list('8302', 'vidREAL8302', zt: '2', size: 4096, duration: '00:40');
        $this->assertWithoutVid($this->checkStatus($guesser, '8302'), found: true);
    }

    public function test_another_members_claim_never_gives_the_vid(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->list('8401', 'vidOWN8401', zt: '2', size: 5000, duration: '01:00');

        $this->actingAs($owner)->postJson(route('videos.upload18.pending'), $this->file('8401', 'vidOWN8401', 'mine.mp4', 5000))->assertCreated();

        foreach (['', 'vidOWN8401'] as $vid) {
            $this->checkStatus($other, '8401', $vid)->assertForbidden()->assertJsonMissing(['vid' => 'vidOWN8401']);
        }

        // Long after the 30 minutes of the old cache claim: still the owner's.
        Cache::flush();
        $this->travel(2)->hours();
        $this->checkStatus($other, '8401')->assertForbidden()->assertJsonMissing(['vid' => 'vidOWN8401']);
        $this->checkStatus($owner, '8401')->assertOk()->assertJsonPath('vid', 'vidOWN8401');
    }

    public function test_the_requests_own_matching_vid_is_answered_as_before(): void
    {
        $member = User::factory()->create();
        // A page opened before 1.2.3 (no announce), or the announce still on its way: the browser has the vid of
        // Upload18's own upload answer and sends it with the check.
        $this->list('8501', 'vidAPI8501', zt: '1', size: 4096);
        $this->checkStatus($member, '8501', 'vidAPI8501')->assertOk()
            ->assertExactJson(['found' => true, 'ready' => false, 'vid' => 'vidAPI8501', 'zt' => '1', 'duration' => null, 'duration_seconds' => null]);

        $this->list('8501', 'vidAPI8501', zt: '2', size: 4096, duration: '01:30');
        $this->checkStatus($member, '8501', 'vidAPI8501')->assertOk()
            ->assertExactJson(['found' => true, 'ready' => true, 'vid' => 'vidAPI8501', 'zt' => '2', 'duration' => '01:30', 'duration_seconds' => 90]);

        // ... and the finish that follows works as before (it needs this member's status check).
        $this->actingAs($member)->postJson(route('videos.upload18.complete'), $this->completePayload('8501', 'vidAPI8501', 'old-page.mp4', 4096))->assertOk();
        $this->assertSame('vidAPI8501', Video::query()->where('user_id', $member->id)->value('embed_code'));

        // A vid that is not the listed one is refused as before.
        $this->list('8502', 'vidAPI8502', zt: '2', size: 4096, duration: '00:10');
        $this->checkStatus($member, '8502', 'vidSOMETHINGELSE')->assertStatus(409)->assertJsonPath('error', 'Upload18 returned a different video ID.');
    }

    public function test_guests_unverified_members_and_the_view_only_demo_are_refused_as_before(): void
    {
        $this->list('8601', 'vidX8601', zt: '2', size: 2048, duration: '00:10');

        $this->getJson(route('videos.upload18.status', ['did' => '8601']))->assertUnauthorized();

        config(['site.email_verification_required' => true]);
        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->getJson(route('videos.upload18.status', ['did' => '8601']))->assertForbidden()->assertJsonMissing(['vid' => 'vidX8601']);
        config(['site.email_verification_required' => false]);

        $demo = User::factory()->create();
        $demo->forceFill(['is_view_only' => true])->save();
        $this->actingAs($demo)->getJson(route('videos.upload18.status', ['did' => '8601', 'vid' => 'vidX8601']))->assertForbidden()->assertJsonMissing(['vid' => 'vidX8601']);
    }

    /**
     * The calls VideoUpload.tsx makes, in its order (config, the file to Upload18, /pending next to the first status
     * check, the checks every 10 s until ready, /complete with the frames): a member's own upload gets everything it
     * needs at each step, with the announce arriving before or after the first check, with or without the vid in
     * Upload18's upload answer.
     */
    public function test_the_upload_panels_calls_still_finish_a_members_own_upload(): void
    {
        foreach ([
            'announce first, the answer had the vid' => ['7101', true, true],
            'first check first, the answer had the vid' => ['7102', false, true],
            'announce first, an answer without the vid' => ['7103', true, false],
            'first check first, an answer without the vid' => ['7104', false, false],
        ] as $case => [$did, $announceFirst, $answerHasVid]) {
            $member = User::factory()->create();
            $vid = 'vidFLOW'.$did;
            $browserVid = $answerHasVid ? $vid : '';

            // 1. the panel's config (direct upload with a short-lived token)
            $this->actingAs($member)->getJson(route('videos.upload18.config'))->assertOk()->assertJsonPath('upload_mode', 'direct');

            // 2. the file went to Upload18 (not here: faked), its answer carried the did (and the vid)
            $this->list($did, $vid, zt: '1', size: 3000);

            // 3. /pending next to the first status check
            $first = null;
            if ($announceFirst) {
                $this->actingAs($member)->postJson(route('videos.upload18.pending'), $this->file($did, $browserVid, 'flow.mp4', 3000, 15.0))->assertCreated();
                $first = $this->checkStatus($member, $did, $browserVid)->assertOk();
            } else {
                $first = $this->checkStatus($member, $did, $browserVid)->assertOk();
                $this->actingAs($member)->postJson(route('videos.upload18.pending'), $this->file($did, $browserVid, 'flow.mp4', 3000, 15.0))->assertCreated();
            }
            $this->assertFalse((bool) $first->json('ready'), $case.': not ready yet');

            // 4. the checks every 10 s: Upload18 finishes, the check says ready with the vid
            $this->list($did, $vid, zt: '2', size: 3000, duration: '00:15', seconds: 15.0);
            $ready = $this->checkStatus($member, $did, $browserVid)->assertOk();
            $this->assertTrue((bool) $ready->json('ready'), $case);
            $this->assertSame($vid, $ready->json('vid'), $case);

            // 5. /complete with the check's vid (status.vid || the answer's vid), as the panel sends it
            $this->actingAs($member)->postJson(route('videos.upload18.complete'), $this->completePayload($did, $ready->json('vid') ?: $browserVid, 'flow.mp4', 3000, 15.0, $ready->json('duration'), $ready->json('duration_seconds')))
                ->assertOk()
                ->assertJsonStructure(['route']);

            $video = Video::query()->where('user_id', $member->id)->firstOrFail();
            $this->assertSame($vid, $video->embed_code, $case);
            $this->assertSame(1, Video::query()->where('user_id', $member->id)->count(), $case.': one video');
        }
    }

    public function test_between_an_updates_file_swap_and_its_finish_only_the_requests_own_vid_is_answered(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $this->list('8701', 'vidOLD8701', zt: '2', size: 2048, duration: '00:10');

        // The claims table comes with the update's Finish: until then nobody holds a did. A check without the vid learns
        // nothing and claims nothing; the member's own upload (VideoUpload.tsx sends the vid of Upload18's upload
        // answer, a page opened before the update too) is answered and finished exactly as before.
        Schema::drop('upload18_claims');
        $this->forgetClaimsTable();

        try {
            $this->assertWithoutVid($this->checkStatus($other, '8701'), found: true);
            $this->assertWithoutVid($this->checkStatus($member, '8701'), found: true);
            $this->assertNull(Cache::get('upload18:claim:'.hash('sha256', '8701')));

            $this->checkStatus($member, '8701', 'vidOLD8701')->assertOk()
                ->assertExactJson(['found' => true, 'ready' => true, 'vid' => 'vidOLD8701', 'zt' => '2', 'duration' => '00:10', 'duration_seconds' => 10]);

            $this->actingAs($member)->postJson(route('videos.upload18.complete'), $this->completePayload('8701', 'vidOLD8701', 'during-update.mp4', 2048))->assertOk();
            $this->assertSame('vidOLD8701', Video::query()->where('user_id', $member->id)->value('embed_code'));
        } finally {
            $this->forgetClaimsTable();
        }
    }

    private function forgetClaimsTable(): void
    {
        $seen = new \ReflectionProperty(UploadClaims::class, 'tableSeen');
        $seen->setValue(null, false);
    }

    private function checkStatus(User $member, string $did, string $vid = ''): TestResponse
    {
        return $this->actingAs($member)->getJson(route('videos.upload18.status', array_filter(['did' => $did, 'vid' => $vid], static fn ($v) => $v !== '')));
    }

    private function assertWithoutVid(TestResponse $response, bool $found): void
    {
        $response->assertOk()->assertExactJson([
            'found' => $found,
            'ready' => false,
            'vid' => '',
            'zt' => $found ? (string) $response->json('zt') : '0',
            'duration' => null,
            'duration_seconds' => null,
        ]);
    }

    private function list(string $did, string $vid, string $zt = '2', ?int $size = null, ?string $duration = null, ?float $seconds = null): void
    {
        $this->listed[$did] = array_filter([
            'id' => (int) $did,
            'vid' => $vid,
            'zt' => $zt,
            'size_bytes' => $size,
            'duration' => $duration,
            'duration_seconds' => $seconds,
        ], static fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    private function file(string $did, string $vid, string $name, int $size, ?float $duration = null): array
    {
        return array_filter([
            'did' => $did,
            'vid' => $vid !== '' ? $vid : null,
            'filename' => $name,
            'size' => $size,
            'file_duration' => $duration,
        ], static fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    private function completePayload(string $did, string $vid, string $name, int $size, ?float $fileDuration = null, mixed $duration = null, mixed $durationSeconds = null): array
    {
        return array_filter([
            'did' => $did,
            'vid' => $vid,
            'filename' => $name,
            'size' => $size,
            'duration' => $duration,
            'duration_seconds' => $durationSeconds,
            'file_duration' => $fileDuration,
        ], static fn ($value) => $value !== null);
    }
}
