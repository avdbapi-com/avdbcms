<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Security plan S04: a ban reaches the API. A banned member's token and the session the site's scripts share are
 * refused with 403 JSON (the session is signed out), the API sign-in hands a banned member no new token, and the API
 * sign-in and sign-up work on standard installs (SANCTUM_TOKEN_EXPIRATION comes from .env as text).
 */
class ApiBanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.captcha_provider' => 'none',
            'site.api_rate_limit' => 1000,
            'site.login_rate_limit' => 1000,
            'site.comment_rate_limit' => 1000,
            'site.email_verification_required' => false,
        ]);

        foreach (['127.0.0.1', 'register:127.0.0.1', 'register|127.0.0.1', md5('register127.0.0.1'), md5('login127.0.0.1')] as $key) {
            RateLimiter::clear($key);
        }
    }

    public function test_a_banned_members_token_is_refused(): void
    {
        $member = User::factory()->create();
        $token = $member->createToken('phone', ['api'])->plainTextToken;
        $video = $this->publicVideo();

        // Before the ban the token works.
        $this->withToken($token)->postJson(route('comments.store', $video->uuid), ['content' => 'Before the ban.'])->assertCreated();

        $this->banByAnAdministrator($member);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson(route('comments.store', $video->uuid), ['content' => 'After the ban.'])
            ->assertForbidden()->assertJsonPath('message', 'Your account was suspended');
        $this->assertDatabaseMissing('comments', ['content' => 'After the ban.']);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/like', ['type' => 'video', 'id' => $video->id])->assertForbidden();
        $this->assertSame(0, DB::table('interactions')->where('user_id', $member->id)->count());
    }

    public function test_a_session_signed_in_before_the_ban_is_refused_and_signed_out(): void
    {
        config(['sanctum.stateful' => ['stateful.example']]);
        $member = User::factory()->create();
        $video = $this->publicVideo();
        $member->forceFill(['banned_at' => now()])->save();

        $this->actingAs($member)
            ->withHeaders(['Referer' => 'https://stateful.example/', 'X-XSRF-TOKEN' => 'x'])
            ->postJson(route('comments.store', $video->uuid), ['content' => 'From the page.'])
            ->assertForbidden()->assertJsonPath('message', 'Your account was suspended');

        $this->assertGuest('web');
        $this->assertDatabaseMissing('comments', ['content' => 'From the page.']);
    }

    public function test_the_api_sign_in_gives_a_banned_member_no_token(): void
    {
        $member = User::factory()->create(['email' => 'banned-phone@example.test', 'password' => 'correct-password']);
        $member->forceFill(['banned_at' => now()])->save();

        $this->postJson('/api/login', ['username' => $member->email, 'password' => 'correct-password'])
            ->assertForbidden()->assertJsonPath('message', 'Your account was suspended');

        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $member->id)->count());
    }

    public function test_the_api_sign_in_and_sign_up_work_with_the_lifetime_from_the_env_file(): void
    {
        config(['sanctum.expiration' => '43200', 'site.registration_enabled' => true, 'site.api_registration' => true, 'site.max_register_attempts' => 1000]);
        $member = User::factory()->create(['email' => 'phone@example.test', 'password' => 'correct-password']);

        $this->postJson('/api/login', ['username' => $member->email, 'password' => 'correct-password'])
            ->assertOk()->assertJsonPath('token', fn (mixed $token): bool => is_string($token) && $token !== '');
        $expires = DB::table('personal_access_tokens')->where('tokenable_id', $member->id)->value('expires_at');
        $this->assertNotNull($expires);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), strtotime((string) $expires), 120);

        $this->postJson('/api/register', [
            'username' => 'phone-signup',
            'email' => 'phone-signup@example.test',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
            'cgu' => true,
        ])->assertCreated();

        // No lifetime: tokens that do not expire.
        config(['sanctum.expiration' => null]);
        $this->postJson('/api/login', ['username' => $member->email, 'password' => 'correct-password'])->assertOk();
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $member->id)->whereNull('expires_at')->count());
    }

    public function test_members_and_visitors_are_not_affected(): void
    {
        $member = User::factory()->create();
        $video = $this->publicVideo();

        $this->actingAs($member)->postJson(route('comments.store', $video->uuid), ['content' => 'A normal comment.'])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->getJson(route('comments.index', $video->uuid))->assertOk();
    }

    private function banByAnAdministrator(User $member): void
    {
        $this->actingAs(User::factory()->admin()->create())->post('/admin/users/'.$member->id.'/ban')->assertRedirect();
        $this->assertTrue($member->fresh()->is_banned);
    }

    private function publicVideo(): Video
    {
        return Video::factory()->for(User::factory()->create(), 'user')->withStatus(VideoStatus::PUBLIC)
            ->create(['allow_comments' => true, 'uploaded_at' => now()->subHour(), 'published_at' => now()->subHour()]);
    }
}
