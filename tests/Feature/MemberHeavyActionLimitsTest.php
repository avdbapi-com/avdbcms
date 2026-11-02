<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Models\Video;
use App\Services\SeoService;
use App\Support\IndexNowMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Round 1b judge R1B-026: two member routes new in 1.2.3 had no limit. "Make public" of up to 500 videos
 * (POST /profile/videos/mass-visibility) announces them to IndexNow after the answer, with no memory of addresses already
 * sent: a member flipping the visibility in a loop flooded the site's IndexNow key and held a PHP worker each time.
 * "Sign out of the other devices" (POST /profile/devices/sign-out-others) with the right password scans the session
 * folder (up to 2.5 s) every time.
 *
 * Now: a named limit per member on each (10 visibility changes a minute; 5 sign-outs of the other devices in 10
 * minutes, counting the ones that really ended sessions, so R1-085's wrong-password rule keeps its own answers), and an
 * address is announced to IndexNow at most once an hour (also when a video's details are saved again).
 */
class MemberHeavyActionLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'csrf-test-token-r1b026-0123456789abcdef0123';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([null, 'public', 'thumbnails', 'videos'] as $disk) {
            Storage::fake($disk);
        }
    }

    public function test_bulk_visibility_is_limited_per_member(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $video = $this->video($member, VideoStatus::PRIVATE);
        $theirs = $this->video($other, VideoStatus::PRIVATE);

        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($member)->from(route('user.videos.index'))
                ->post(route('user.videos.mass-visibility'), ['ids' => [$video->id], 'visibility' => $i % 2 ? 'public' : 'private'])
                ->assertRedirect()
                ->assertSessionMissing('error');
        }

        // The eleventh within the minute: a script gets 429 with the wait, the page goes back with the same sentence.
        $json = $this->actingAs($member)->postJson(route('user.videos.mass-visibility'), ['ids' => [$video->id], 'visibility' => 'unlisted']);
        $json->assertStatus(429)->assertHeader('Retry-After');
        $this->assertMatchesRegularExpression('/^Too many tries\. Wait \d+ seconds?, then try again\.$/', (string) $json->json('message'));
        $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status, 'a refused request changes nothing');

        $this->actingAs($member)->from(route('user.videos.index'))
            ->post(route('user.videos.mass-visibility'), ['ids' => [$video->id], 'visibility' => 'unlisted'])
            ->assertRedirect(route('user.videos.index'))
            ->assertSessionHas('error');
        $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status);

        // Another member has a limit of their own.
        $this->actingAs($other)->post(route('user.videos.mass-visibility'), ['ids' => [$theirs->id], 'visibility' => 'unlisted'])
            ->assertRedirect()->assertSessionMissing('error');
        $this->assertSame(VideoStatus::UNLISTED, $theirs->fresh()->status);

        // A minute later the member may change it again.
        $this->travel(61)->seconds();
        $this->actingAs($member)->post(route('user.videos.mass-visibility'), ['ids' => [$video->id], 'visibility' => 'unlisted'])
            ->assertRedirect()->assertSessionMissing('error');
        $this->assertSame(VideoStatus::UNLISTED, $video->fresh()->status);
    }

    public function test_bulk_visibility_still_refuses_guests_the_view_only_demo_a_get_and_a_missing_token(): void
    {
        $member = User::factory()->create();
        $video = $this->video($member, VideoStatus::PRIVATE);

        $this->post(route('user.videos.mass-visibility'), ['ids' => [$video->id], 'visibility' => 'public'])->assertRedirect(route('login'));
        // a GET (a link on another site) never runs it: the address is not a page
        $this->assertContains($this->actingAs($member)->get(route('user.videos.mass-visibility').'?ids[]='.$video->id.'&visibility=public')->getStatusCode(), [404, 405]);
        $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status);

        $demo = User::factory()->create();
        $demo->forceFill(['is_view_only' => true])->save();
        $mine = $this->video($demo, VideoStatus::PRIVATE);
        $this->actingAs($demo)->post(route('user.videos.mass-visibility'), ['ids' => [$mine->id], 'visibility' => 'public'])
            ->assertStatus(303)->assertSessionHasErrors('view_only_demo');
        $this->assertSame(VideoStatus::PRIVATE, $mine->fresh()->status);

        $this->checkCsrfForReal();
        $this->actingAs($member)->post(route('user.videos.mass-visibility'), ['ids' => [$video->id], 'visibility' => 'public'])->assertStatus(419);
        $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status);
        $this->actingAs($member)->withSession(['_token' => self::TOKEN])
            ->post(route('user.videos.mass-visibility'), ['_token' => self::TOKEN, 'ids' => [$video->id], 'visibility' => 'public'])
            ->assertRedirect();
        $this->assertSame(VideoStatus::PUBLIC, $video->fresh()->status);
    }

    public function test_signing_out_the_other_devices_is_limited_per_member(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();

        for ($i = 1; $i <= 5; $i++) {
            $token = $member->fresh()->remember_token;
            $this->actingAs($member)->postJson(route('user.devices.sign-out-others'), ['current_password' => 'password'])->assertRedirect();
            $this->assertNotSame($token, $member->fresh()->remember_token, 'sign-out '.$i.' ran');
        }

        $token = $member->fresh()->remember_token;
        $refused = $this->actingAs($member)->postJson(route('user.devices.sign-out-others'), ['current_password' => 'password']);
        $refused->assertStatus(429)->assertHeader('Retry-After');
        $this->assertMatchesRegularExpression('/^Too many tries\. Wait \d+ minutes?, then try again\.$/', (string) $refused->json('message'));
        $this->assertSame($token, $member->fresh()->remember_token, 'the sixth within ten minutes does not run');

        // the page form goes back to Account settings with the sentence
        $this->actingAs($member)->from(route('user.edit', ['section' => 'security']))
            ->post(route('user.devices.sign-out-others'), ['current_password' => 'password'])
            ->assertRedirect(route('user.edit', ['section' => 'security']))
            ->assertSessionHas('error');

        // Another member is never held up by it.
        $this->actingAs($other)->postJson(route('user.devices.sign-out-others'), ['current_password' => 'password'])->assertRedirect();

        $this->travel(11)->minutes();
        $this->actingAs($member)->postJson(route('user.devices.sign-out-others'), ['current_password' => 'password'])->assertRedirect();
        $this->assertNotSame($token, $member->fresh()->remember_token);
    }

    public function test_wrong_passwords_keep_the_password_checks_own_answers(): void
    {
        $member = User::factory()->create();

        // R1-085: wrong passwords are counted by the password check (5 in 10 minutes), not by this limit.
        for ($try = 1; $try <= 4; $try++) {
            $this->actingAs($member)->postJson(route('user.devices.sign-out-others'), ['current_password' => 'guess-'.$try])->assertStatus(422);
        }
        $this->actingAs($member)->postJson(route('user.devices.sign-out-others'), ['current_password' => 'password'])->assertRedirect();
        for ($try = 1; $try <= 4; $try++) {
            $this->actingAs($member)->postJson(route('user.devices.sign-out-others'), ['current_password' => 'again-'.$try])->assertStatus(422);
        }
        $this->actingAs($member)->postJson(route('user.devices.sign-out-others'), ['current_password' => 'password'])->assertRedirect();
    }

    public function test_a_password_change_that_signs_out_the_other_devices_counts_too(): void
    {
        $member = User::factory()->create();

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($member)->postJson(route('user.devices.sign-out-others'), ['current_password' => 'password'])->assertRedirect();
        }

        // A password change WITHOUT "sign out the other devices" is not held up ...
        $this->actingAs($member)->from(route('user.edit', ['section' => 'security']))
            ->post(route('user.update.password'), [
                'current_password' => 'password',
                'new_password' => 'Another-password-77',
                'new_password_confirmation' => 'Another-password-77',
            ])
            ->assertRedirect()
            ->assertSessionMissing('error');

        // ... one that asks for it waits like the button.
        $this->actingAs($member->fresh())->postJson(route('user.update.password'), [
            'current_password' => 'Another-password-77',
            'new_password' => 'Third-password-88',
            'new_password_confirmation' => 'Third-password-88',
            'sign_out_others' => '1',
        ])->assertStatus(429);
    }

    public function test_make_public_announces_each_address_to_indexnow_at_most_once_an_hour(): void
    {
        config(['site.indexnow_enabled' => true, 'site.indexnow_key' => 'r1b026indexnowkey', 'site.indexnow_endpoint' => 'https://indexnow.test/indexnow']);
        Http::fake(['*' => Http::response('', 200)]);
        $member = User::factory()->create();
        $videos = [$this->video($member, VideoStatus::PRIVATE), $this->video($member, VideoStatus::PRIVATE), $this->video($member, VideoStatus::PRIVATE)];
        $ids = array_map(static fn (Video $video) => $video->id, $videos);

        $this->actingAs($member)->post(route('user.videos.mass-visibility'), ['ids' => $ids, 'visibility' => 'public'])->assertRedirect();
        $this->assertSame([3], $this->announcedCounts(), 'one call with the three addresses');

        // Private and public again, five times in a row: nothing new is announced.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($member)->post(route('user.videos.mass-visibility'), ['ids' => $ids, 'visibility' => 'private'])->assertRedirect();
            $this->actingAs($member)->post(route('user.videos.mass-visibility'), ['ids' => $ids, 'visibility' => 'public'])->assertRedirect();
            $this->travel(61)->seconds();
        }
        $this->assertSame([3], $this->announcedCounts(), 'the same addresses are not announced again within the hour');

        // A video made public for the first time is announced, on its own.
        $fourth = $this->video($member, VideoStatus::PRIVATE);
        $this->actingAs($member)->post(route('user.videos.mass-visibility'), ['ids' => [...$ids, $fourth->id], 'visibility' => 'public'])->assertRedirect();
        $this->assertSame([3, 1], $this->announcedCounts());

        // An hour later an address may be announced again.
        $this->travel(61)->minutes();
        $this->actingAs($member)->post(route('user.videos.mass-visibility'), ['ids' => $ids, 'visibility' => 'private'])->assertRedirect();
        $this->actingAs($member)->post(route('user.videos.mass-visibility'), ['ids' => $ids, 'visibility' => 'public'])->assertRedirect();
        $this->assertSame([3, 1, 3], $this->announcedCounts());
    }

    public function test_saving_a_videos_details_again_announces_its_address_once_an_hour(): void
    {
        config(['site.indexnow_enabled' => true, 'site.indexnow_key' => 'r1b026indexnowkey', 'site.indexnow_endpoint' => 'https://indexnow.test/indexnow']);
        Http::fake(['*' => Http::response('', 200)]);
        $video = $this->video(User::factory()->create(), VideoStatus::PUBLIC);
        $seo = app(SeoService::class);

        for ($i = 0; $i < 4; $i++) {
            $seo->submitVideoToIndexNow($video->fresh(['user', 'category']));
        }
        $this->assertSame([1], $this->announcedCounts());

        $this->travel(61)->minutes();
        $seo->submitVideoToIndexNow($video->fresh(['user', 'category']));
        $this->assertSame([1, 1], $this->announcedCounts());

        // A new key (the owner set up IndexNow again) announces the address again at once.
        config(['site.indexnow_key' => 'r1b026anotherkey']);
        $seo->submitVideoToIndexNow($video->fresh(['user', 'category']));
        $this->assertSame([1, 1, 1], $this->announcedCounts());

        // Within one list an address counts once; an empty one is never announced.
        $this->assertSame(['https://example.test/a'], IndexNowMemory::fresh(['https://example.test/a', 'https://example.test/a', '']));
        $this->assertSame([], IndexNowMemory::fresh(['https://example.test/a']));
    }

    /** @return list<int> the number of addresses of each IndexNow call so far */
    private function announcedCounts(): array
    {
        return array_values(array_map(
            static fn (array $call): int => count($call[0]->data()['urlList'] ?? []),
            Http::recorded(static fn (HttpRequest $request): bool => str_contains($request->url(), 'indexnow'))->all()
        ));
    }

    private function video(User $owner, VideoStatus $status): Video
    {
        return Video::factory()->for($owner, 'user')->create([
            'status' => $status->value,
            'category_id' => null,
            'language' => null,
            'published_at' => $status === VideoStatus::PUBLIC ? now()->subDays(3) : null,
            'uploaded_at' => now()->subDays(3),
        ]);
    }

    private function checkCsrfForReal(): void
    {
        $this->app->instance(VerifyCsrfToken::class, new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }
}
