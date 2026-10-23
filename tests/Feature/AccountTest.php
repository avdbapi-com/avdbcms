<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Notifications\Account\VerifyUpdatedEmail;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->actingAs($this->user);
    }
    public function test_update_email() :void
    {
        Notification::fake();

        $this->fromRoute('user.edit')->put(route('user.update'), [
            'email' => 'new_email@example.com',
        ])->assertRedirectToRoute('user.edit');

        Notification::assertSentTo(
            [$this->user], VerifyUpdatedEmail::class
        );
    }

    public function test_resend_updated_email_verification_handles_mail_delivery_failure(): void
    {
        $this->user->forceFill(['temporary_email' => 'admin@avdbcms.example'])->save();

        $this->app->instance(NotificationDispatcher::class, new class implements NotificationDispatcher {
            public function send($notifiables, $notification): void
            {
                throw new RuntimeException('SMTP account is not yet activated');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new RuntimeException('SMTP account is not yet activated');
            }
        });

        $this->fromRoute('user.edit')
            ->post(route('verification.send.update'))
            ->assertRedirectToRoute('user.edit')
            ->assertSessionHas('error', 'Verification email could not be sent because the SMTP account is not activated. Activate the Brevo SMTP account, then try again.');
    }

    public function test_dashboard_page ()
    {
        $this
            ->get(route('user.index'))
            ->assertStatus(200);
    }

    public function test_videos_page ()
    {
        $response = $this->get(route('user.videos.index'));

        $response->assertStatus(200);
    }

    public function test_videos_create_page ()
    {
        $video = Video::factory()
            ->for($this->user)
            ->withStatus(VideoStatus::DRAFT)
            ->create();

        $response = $this->get(route('user.videos.create', $video));

        $response->assertStatus(200);
    }

    public function test_videos_edit_page ()
    {
        $video = Video::factory()
            ->for($this->user)
            ->withStatus(VideoStatus::PUBLIC)
            ->create();

        $response = $this->get(route('user.videos.edit', $video));

        $response->assertStatus(200);
    }

    public function test_playlist_page ()
    {
        $response = $this->get(route('user.videos.index'));

        $response->assertStatus(200);
    }

    public function test_subscribers_page ()
    {
        $response = $this->get(route('user.subscribers'));

        $response->assertStatus(200);
    }

    public function test_comments_page ()
    {
        $response = $this->get(route('user.comments.index'));

        $response->assertStatus(200);
    }

    public function test_activity_page ()
    {
        $response = $this->get(route('user.activity.index'));

        $response->assertStatus(200);
    }

    public function test_reports_page ()
    {
        $response = $this->get(route('user.reports.index'));

        $response->assertStatus(200);
    }

    public function test_invoices_page ()
    {
        $response = $this->get(route('user.invoices.index'));

        $response->assertStatus(200);
    }

    public function test_profile_page ()
    {
        $this
            ->get(route('user.edit'))
            ->assertStatus(200);
    }

    public function test_profile_avatar_upload_is_persisted_and_cache_busted(): void
    {
        Storage::fake();

        $previousAvatarUrl = $this->user->avatar_url;

        $this->fromRoute('user.edit')->put(route('user.update'), [
            'username' => $this->user->username,
            'slug' => $this->user->slug,
            'email' => $this->user->email,
            'avatar' => $this->fakeImageUpload('avatar.jpg', 240, 240),
        ])->assertRedirectToRoute('user.edit');

        $this->user->refresh();

        $this->assertNotNull($this->user->avatar);
        Storage::assertExists(User::AVATAR_FOLDER.'/'.$this->user->avatar);
        $this->assertNotSame($previousAvatarUrl, $this->user->avatar_url);
        $this->assertStringContainsString('v=', $this->user->avatar_url);
    }

    public function test_profile_banner_upload_is_persisted_and_cache_busted(): void
    {
        Storage::fake();

        $previousBannerUrl = $this->user->banner_url;

        $this->fromRoute('user.edit')->put(route('user.update'), [
            'username' => $this->user->username,
            'slug' => $this->user->slug,
            'email' => $this->user->email,
            'banner' => $this->fakeImageUpload('banner.jpg', 1400, 420),
        ])->assertRedirectToRoute('user.edit');

        $this->user->refresh();

        $this->assertNotNull($this->user->banner);
        Storage::assertExists(User::BANNER_FOLDER.'/'.$this->user->banner);
        $this->assertNotSame($previousBannerUrl, $this->user->banner_url);
        $this->assertStringContainsString('v=', $this->user->banner_url);
    }

    public function test_profile_update_with_empty_phone_does_not_start_phone_verification(): void
    {
        $this->assertNull($this->user->phone);

        $this->fromRoute('user.edit')->put(route('user.update'), [
            'username' => $this->user->username,
            'slug' => $this->user->slug,
            'email' => $this->user->email,
            'prefix' => '+33',
            'code' => 'FR',
            'phone' => '',
        ])->assertRedirectToRoute('user.edit');

        $this->user->refresh();

        $this->assertNull($this->user->phone);
        $this->assertNull($this->user->phone_confirmation_code);
    }
}
