<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A member suspended while a page is open: the page's next script call (a like, a poll, a history update) is refused
 * and ends the session, as before. The next page the browser opens now goes to the sign-in page WITH "This account is
 * suspended. If you think this is a mistake, contact us." (it showed the sign-in page without a word). Once only.
 */
class BannedSignOutNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sanctum.stateful' => ['stateful.example'],
            'site.api_rate_limit' => 1000,
            'site.email_verification_required' => false,
            'cache.limiter' => 'array',
        ]);
        Cache::flush();
    }

    public function test_the_next_page_after_a_refused_script_call_explains_the_sign_out(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member)->get('/trend')->assertOk();
        $member->forceFill(['banned_at' => now()])->save();

        // the page's script call: refused, the session ends
        $this->actingAs($member)
            ->withHeaders(['Referer' => 'https://stateful.example/', 'X-XSRF-TOKEN' => 'x'])
            ->postJson('/api/like', ['type' => 'video', 'id' => 1])
            ->assertForbidden()
            ->assertJsonPath('message', __('Your account was suspended'));
        $this->assertGuest('web');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        // the next page (a public one too): the sign-in page, with the reason, as when the ban meets a page first
        $this->get('/trend')->assertRedirect(locale_url(route('login')));
        $html = (string) $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString(e(__('contact us')), $html);
        $this->assertMatchesRegularExpression('#<div class="mk-alert mk-alert--danger" role="alert"[^>]*>\s*<i class="fa-solid fa-ban"#', $html);

        // once only: the page after that is an ordinary visit
        $this->get('/trend')->assertOk();
    }

    public function test_a_visitor_without_that_notice_is_never_sent_to_sign_in(): void
    {
        $this->get('/trend')->assertOk();
        $this->getJson('/api/videos')->assertOk();
    }

    /**
     * The home page's lazy avatars load after the ban: a picture is not the page. It was sent to the sign-in page like a
     * page, the browser followed that redirect out of sight (as a picture) and the sign-in page it fetched used up the
     * one-time message, so the member's next page was the sign-in page without a word again.
     */
    public function test_a_picture_the_open_page_loads_after_the_ban_does_not_use_up_the_message(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($member)->get('/trend')->assertOk();
        $member->forceFill(['banned_at' => now()])->save();

        $picture = ['Sec-Fetch-Dest' => 'image', 'Sec-Fetch-Mode' => 'no-cors', 'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8'];
        $response = $this->withHeaders($picture)->get(route('user.avatar', $other, false));
        // what the browser does with the answer of a picture: it follows a redirect, as a picture
        for ($hops = 0; $response->isRedirect() && $hops < 3; $hops++) {
            $response = $this->withHeaders($picture)->get((string) $response->headers->get('Location'));
        }
        $this->assertSame(403, $response->getStatusCode(), 'a picture of a suspended member is refused, not sent to the sign-in page');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        // the member's next page: the sign-in page with the reason, once
        $this->withHeaders(['Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Mode' => 'navigate'])->get('/trend')->assertRedirect(locale_url(route('login')));
        $this->flushHeaders();
        $html = (string) $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString(e(__('This account is suspended.')), $html);
        $this->assertStringContainsString(e(__('contact us')), $html);
        $this->get('/trend')->assertOk();
    }

    public function test_pictures_after_a_refused_script_call_leave_the_note_for_the_page(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($member)->get('/trend')->assertOk();
        $member->forceFill(['banned_at' => now()])->save();
        $this->actingAs($member)
            ->withHeaders(['Referer' => 'https://stateful.example/', 'X-XSRF-TOKEN' => 'x'])
            ->postJson('/api/like', ['type' => 'video', 'id' => 1])
            ->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        // the open page's pictures (a browser with fetch metadata, and an older one that only says what it accepts), a
        // prefetch and a frame: none of them is the page, none takes the note
        $this->withHeaders(['Sec-Fetch-Dest' => 'image', 'Sec-Fetch-Mode' => 'no-cors', 'Accept' => 'image/*'])->get(route('user.avatar', $other, false))->assertOk();
        $this->flushHeaders();
        $this->withHeaders(['Accept' => 'image/webp,image/png,image/*;q=0.8,*/*;q=0.5'])->get(route('user.avatar', $other, false))->assertOk();
        $this->flushHeaders();
        $this->withHeaders(['Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Purpose' => 'prefetch'])->get('/trend')->assertOk();
        $this->flushHeaders();
        $this->withHeaders(['Sec-Fetch-Dest' => 'iframe', 'Sec-Fetch-Mode' => 'navigate'])->get('/trend')->assertOk();
        $this->flushHeaders();
        $this->assertTrue(session()->has(\App\Http\Middleware\IsBanned::NOTICE));

        // the next page takes it: the sign-in page with the reason
        $this->withHeaders(['Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Mode' => 'navigate'])->get('/trend')->assertRedirect(locale_url(route('login')));
        $this->flushHeaders();
        $this->assertStringContainsString(e(__('This account is suspended.')), (string) $this->get('/login')->assertOk()->getContent());
    }

    public function test_only_the_page_is_sent_to_the_sign_in_page_the_rest_is_refused(): void
    {
        $member = User::factory()->create();
        $member->forceFill(['banned_at' => now()])->save();

        // a page (with or without fetch metadata): the sign-in page with the reason
        $this->actingAs($member)->withHeaders(['Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Mode' => 'navigate'])->get('/trend')->assertRedirect(locale_url(route('login')));
        $this->flushHeaders();
        $this->actingAs($member)->get('/trend')->assertRedirect(locale_url(route('login')));
        $this->flushHeaders();

        // a frame and a prefetch: refused in words (no redirect to follow out of sight), never kept by a shared cache
        foreach ([['Sec-Fetch-Dest' => 'iframe', 'Sec-Fetch-Mode' => 'navigate'], ['Sec-Fetch-Dest' => 'document', 'Sec-Purpose' => 'prefetch']] as $headers) {
            $response = $this->actingAs($member)->withHeaders($headers)->get('/trend');
            $response->assertStatus(403);
            $this->assertSame(__('Your account was suspended'), $response->getContent());
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->flushHeaders();
        }
    }
}
