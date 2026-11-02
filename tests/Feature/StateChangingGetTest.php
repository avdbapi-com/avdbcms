<?php

namespace Tests\Feature;

use App\Exports\UsersExport;
use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\Export;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Security plan S13 and S32: actions that change something are POSTs with the CSRF token, so a link on another site
 * (which a browser opens with the site's Lax session cookie) cannot run them: taking over a member's account
 * (impersonation), queueing a members export, and unlinking a social sign-in. The CSRF check runs for real here
 * (VerifyCsrfToken skips itself in unit tests otherwise).
 */
class StateChangingGetTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'csrf-test-token-0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.operational_tools_enabled' => true]);
        $this->app->instance(VerifyCsrfToken::class, new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    public function test_taking_over_a_member_needs_a_post_with_the_token(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $url = '/admin/impersonate/take/'.$member->id;

        $this->assertGetDoesNothing($this->actingAs($admin)->get($url));
        $this->assertFalse(session()->has('impersonated_by'));
        $this->assertSame($admin->id, auth()->id());

        $this->actingAs($admin)->post($url)->assertStatus(419);
        $this->assertSame($admin->id, auth()->id());

        $this->actingAs($admin)->withSession(['_token' => self::TOKEN])->post($url, ['_token' => self::TOKEN])
            ->assertRedirect(route('user.index'));
        $this->assertSame($member->id, auth()->id(), 'signed in as the member');
        $this->assertSame($admin->id, session('impersonated_by'));
    }

    public function test_the_members_export_needs_a_post_with_the_token(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $query = ['search' => 'probe', 'status' => 'premium'];

        $this->assertGetDoesNothing($this->actingAs($admin)->get(route('admin.users.export', $query)));
        $this->actingAs($admin)->post(route('admin.users.export', $query))->assertStatus(419);
        $this->assertDatabaseCount('exports', 0);
        Queue::assertNothingPushed();

        $this->actingAs($admin)->withSession(['_token' => self::TOKEN])->post(route('admin.users.export', $query), ['_token' => self::TOKEN])
            ->assertRedirect(route('admin.users.index', $query));

        $this->assertDatabaseCount('exports', 1);
        Queue::assertPushed(Export::class, function (Export $job) use ($query): bool {
            $filters = (fn () => $this->filters ?? null)->call($job);
            $type = (fn () => $this->exportType ?? null)->call($job);

            return $filters === $query && $type === UsersExport::class;
        });
    }

    public function test_unlinking_a_social_sign_in_needs_a_post_with_the_token(): void
    {
        $member = User::factory()->create();
        $member->forceFill(['google_id' => 'google-123'])->save();

        $this->assertGetDoesNothing($this->actingAs($member)->get('/oauth/unlink/google'));
        $this->actingAs($member)->post('/oauth/unlink/google')->assertStatus(419);
        $this->assertSame('google-123', $member->fresh()->google_id);

        $this->actingAs($member)->withSession(['_token' => self::TOKEN])->post('/oauth/unlink/google', ['_token' => self::TOKEN])
            ->assertRedirect(locale_url(route('user.edit', ['section' => 'security'])));
        $this->assertNull($member->fresh()->google_id);
    }

    /** A GET reaches no action: 405, or 404 from the site's catch-all video address (it answers every GET). */
    private function assertGetDoesNothing(\Illuminate\Testing\TestResponse $response): void
    {
        $this->assertContains($response->getStatusCode(), [404, 405]);
    }

    public function test_the_pages_offer_these_actions_as_forms_with_the_token(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        foreach (['v2', 'v1'] as $ui) {
            config(['admin.ui' => $ui]);
            $html = (string) $this->actingAs($admin)->get('/admin/users')->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#<form method="POST" action="[^"]*/admin/users/export[^"]*"[^>]*>\s*<input type="hidden" name="_token"#', $html, $ui);
            $this->assertStringNotContainsString('href="'.url('/admin/users/export'), $html, $ui);
            $this->assertStringNotContainsString('href="'.url('/admin/impersonate'), $html, $ui);
        }

        config(['admin.ui' => 'v2']);
        $record = (string) $this->actingAs($admin)->get(route('admin.users.show', $member))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<form method="POST" action="[^"]*/admin/impersonate/take/'.$member->id.'"#', $record);

        $exports = (string) $this->actingAs($admin)->get('/admin/exports')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<form method="POST" action="[^"]*/admin/users/export"#', $exports);

        $member->forceFill(['google_id' => 'google-123', 'facebook_id' => 'facebook-123'])->save();
        // Account settings > Sign-in and security lists the linked services (member design 6.9).
        $profile = (string) $this->actingAs($member)->get(route('user.edit', ['section' => 'security']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<form method="POST" action="[^"]*/oauth/unlink/google"#', $profile);
        $this->assertMatchesRegularExpression('#<form method="POST" action="[^"]*/oauth/unlink/facebook"#', $profile);
        $this->assertStringNotContainsString('href="'.url('/oauth/unlink'), $profile);
    }
}
