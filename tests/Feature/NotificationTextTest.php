<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Models\Video;
use App\Notifications\Activity\NewSubscriber;
use App\Notifications\Activity\NewVideo;
use App\Notifications\Support\NotificationTasks;
use App\Notifications\Support\NotificationText;
use App\Notifications\Video\VideoBanned;
use App\Notifications\Video\VideoError;
use App\Notifications\Video\VideoUploaded;
use App\Support\AdminPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The words of a bell notification are translated when it is shown, from the text and values it stores; rows of 1.2.2
 * and older (only an English sentence) are read back into their values, anything unknown is shown cleaned; the live push
 * goes only through a real broadcaster, in the member's language.
 */
class NotificationTextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        NotificationTasks::flushState();
    }

    public function test_a_notification_stores_its_text_and_values_and_a_corrected_english_sentence(): void
    {
        $owner = User::factory()->create(['username' => 'cabin_builders']);
        $member = User::factory()->create();
        $video = Video::factory()->for($owner)->create(['title' => 'Cabin build day 3']);

        $uploaded = (new VideoUploaded($video))->toArray($member);
        $this->assertSame('video_ready', $uploaded['text']);
        $this->assertSame(['title' => 'Cabin build day 3'], $uploaded['params']);
        $this->assertSame('Your video is ready: Cabin build day 3', $uploaded['message'], 'no space before the colon');
        $this->assertFalse($uploaded['is_read']);

        $this->assertSame('cabin_builders published a new video: Cabin build day 3', (new NewVideo($video))->toArray($member)['message']);
        $this->assertSame('Your video could not be processed: Cabin build day 3', (new VideoError($video))->toArray($member)['message']);
        $this->assertSame('Your video was suspended: Cabin build day 3', (new VideoBanned($video))->toArray($member)['message']);
        $this->assertSame('cabin_builders subscribed to your channel.', (new NewSubscriber($owner))->toArray($member)['message']);
        $this->assertSame('Your export failed.', NotificationText::data('export_failed', [])['message']);
        $this->assertSame('Your invoice from Demo Site is ready.', NotificationText::data('invoice_ready', ['site' => 'Demo Site'])['message']);
        $this->assertSame('pbergstrom published a new video.', NotificationText::data('new_video', ['name' => 'pbergstrom', 'title' => '  '])['message'], 'no title: the plain sentence');
        $this->assertSame('You have a new comment on your video.', NotificationText::data('new_comment', ['name' => '', 'title' => 'x'])['message']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function oldRows(): array
    {
        return [
            'uploaded' => ['App\\Notifications\\Video\\VideoUploaded', 'Your video : Tokyo night walk was successfully processed.', 'Your video is ready: Tokyo night walk'],
            'failed' => ['App\\Notifications\\Video\\VideoError', 'The processing of your video : Tokyo night walk failed !', 'Your video could not be processed: Tokyo night walk'],
            'banned' => ['App\\Notifications\\Video\\VideoBanned', 'You video Tokyo night walk was banned', 'Your video was suspended: Tokyo night walk'],
            'new video' => ['App\\Notifications\\Activity\\NewVideo', 'nightdrive_tv has published new video !', 'nightdrive_tv published a new video.'],
            'subscriber' => ['App\\Notifications\\Activity\\NewSubscriber', 'scale_sub_000123 has subscribed to your channel !', 'scale_sub_000123 subscribed to your channel.'],
            'comment' => ['App\\Notifications\\Activity\\NewComment', 'You have new comment on your video !', 'You have a new comment on your video.'],
            'export' => ['App\\Notifications\\Export\\ExportSuccess', 'Your videos.csv export is ready !', 'Your export is ready: videos.csv'],
            'export failed' => ['App\\Notifications\\Export\\ExportError', 'Your export was failed !', 'Your export failed.'],
            'invoice' => ['App\\Notifications\\Premium\\Invoice', 'Your AVDBCMS invoice is available', 'Your invoice from AVDBCMS is ready.'],
            'unknown kind' => ['App\\Notifications\\SomethingElse', 'Your report : 12 items  were checked !', 'Your report: 12 items were checked!'],
        ];
    }

    #[DataProvider('oldRows')]
    public function test_an_old_row_is_read_back_and_shown_in_the_new_words(string $type, string $stored, string $shown): void
    {
        $this->assertSame($shown, NotificationText::message($type, json_encode(['message' => $stored, 'url' => '/x']), 'en'));
    }

    public function test_the_text_is_translated_when_it_is_shown(): void
    {
        $member = User::factory()->create();
        app('translator')->addLines(['*.:name published a new video: :title' => ':name hat ein neues Video veröffentlicht: :title'], 'de');
        $data = NotificationText::data('new_video', ['name' => 'lofi_room', 'title' => 'Beats'], ['url' => '/v']);
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\Activity\\NewVideo',
            'notifiable_type' => $member->getMorphClass(), 'notifiable_id' => $member->id, 'data' => json_encode($data),
            'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame('lofi_room published a new video: Beats', $data['message'], 'stored in English for older code');
        $this->actingAs($member)->withHeaders(['X-Locale' => 'de'])->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('data.0.message', 'lofi_room hat ein neues Video veröffentlicht: Beats')
            ->assertJsonPath('data.0.kind', 'video');
        $this->actingAs($member)->withHeaders(['X-Locale' => 'en'])->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('data.0.message', 'lofi_room published a new video: Beats');
    }

    public function test_a_notification_from_the_admin_keeps_the_owners_words(): void
    {
        $member = User::factory()->create();
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => NotificationText::BROADCAST_TYPE,
            'notifiable_type' => $member->getMorphClass(), 'notifiable_id' => $member->id,
            'data' => json_encode(['title' => 'Weekly picks', 'message' => 'Our 10 favourite videos !']), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($member)->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('data.0.title', 'Weekly picks')
            ->assertJsonPath('data.0.message', 'Our 10 favourite videos !')
            ->assertJsonPath('data.0.kind', 'message');
    }

    public function test_the_live_push_needs_a_real_broadcaster_and_speaks_the_members_language(): void
    {
        $member = User::factory()->create(['language' => 'de']);
        $video = Video::factory()->for(User::factory()->create(['username' => 'oceanlens']))->create(['title' => 'Waves']);

        config(['broadcasting.default' => 'log']);
        $this->assertSame(['database'], (new NewVideo($video))->via($member));
        $this->assertSame(['mail', 'database'], (new VideoBanned($video))->via($member));

        config(['broadcasting.default' => 'pusher']);
        $this->assertSame(['database', 'broadcast'], (new NewVideo($video))->via($member));

        app('translator')->addLines(['*.:name published a new video: :title' => ':name hat ein neues Video veröffentlicht: :title'], 'de');
        $payload = (new NewVideo($video))->toBroadcast($member)->data;
        $this->assertSame('oceanlens hat ein neues Video veröffentlicht: Waves', $payload['message']);
        $this->assertFalse($payload['is_read']);
        $this->assertSame('video', $payload['kind'], 'the row pushed live shows its own icon at once');
        $this->assertSame('ready', (new VideoUploaded($video))->toBroadcast($member)->data['kind']);
        $this->assertSame('suspended', (new VideoBanned($video))->toBroadcast($member)->data['kind']);
    }

    public function test_the_live_push_of_a_new_video_to_the_subscribers_carries_the_kind(): void
    {
        config(['broadcasting.default' => 'pusher']);
        Event::fake([BroadcastNotificationCreated::class]);
        $channel = User::factory()->create(['username' => 'harbourcam']);
        foreach (User::factory()->count(3)->create() as $subscriber) {
            DB::table('subscriptions')->insert(['subscriber_id' => $subscriber->id, 'user_id' => $channel->id, 'subscribe_at' => now(), 'read_at' => now()]);
        }

        NotificationTasks::announceVideo(Video::factory()->for($channel)->create(['title' => 'Harbour lights']));

        Event::assertDispatchedTimes(BroadcastNotificationCreated::class, 3);
        Event::assertDispatched(BroadcastNotificationCreated::class, fn (BroadcastNotificationCreated $event): bool => $event->data['kind'] === 'video'
            && $event->data['message'] === 'harbourcam published a new video: Harbour lights' && $event->data['is_read'] === false);
    }

    public function test_the_admin_lists_show_each_notification_in_the_admins_language(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $rows = [
            ['App\\Notifications\\Activity\\NewVideo', ['message' => 'lofi_room has published new video !', 'url' => '/v']],
            [NotificationText::BROADCAST_TYPE, ['title' => 'Weekly picks', 'message' => 'Our 10 favourite videos']],
            [NotificationText::BROADCAST_TYPE, ['message' => 'Plain words from the team']],
        ];
        foreach ($rows as $i => [$type, $data]) {
            DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => $type, 'notifiable_type' => $member->getMorphClass(),
                'notifiable_id' => $member->id, 'data' => json_encode($data), 'created_at' => now()->subMinutes($i), 'updated_at' => now()]);
        }
        // the German line next to the language file's own (addLines() would read the sentence's final "." as a key separator)
        $translator = app('translator');
        $translator->load('*', '*', 'de');
        $loaded = (fn (): array => $this->loaded)->call($translator);
        $loaded['*']['*']['de'][':name published a new video.'] = ':name hat ein neues Video veröffentlicht.';
        $translator->setLoaded($loaded);

        foreach (['v2', 'v1'] as $ui) {
            config(['admin.ui' => $ui]);
            AdminPreferences::set($admin, 'admin_ui', $ui);
            // the new admin lists them on the Sent page (its old "To everyone" tab opens the one form there: R1-161)
            $page = $this->actingAs($admin)->withSession(['app_locale' => 'de'])->get(route($ui === 'v2' ? 'admin.notifications.index' : 'admin.broadcast.index'))->assertOk()->getContent();

            // (the old admin cuts the line at 40 characters)
            $this->assertStringContainsString('lofi_room hat ein neues Video', $page, $ui.': the site\'s own, translated (a row of 1.2.2 too)');
            $this->assertStringNotContainsString('has published new video', $page, $ui);
            $this->assertStringContainsString('Weekly picks', $page, $ui.': an admin\'s notification as written');
            $this->assertStringContainsString('Our 10 favourite videos', $page, $ui);
            $this->assertStringContainsString('Plain words from the team', $page, $ui);
        }
    }

    public function test_the_model_gives_the_kind_title_and_sentence(): void
    {
        $member = User::factory()->create();
        $notification = new Notification();
        $notification->forceFill(['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\Video\\VideoUploaded',
            'notifiable_type' => $member->getMorphClass(), 'notifiable_id' => $member->id,
            'data' => ['message' => 'Your video : Old title was successfully processed.', 'url' => '/profile/videos']]);

        $this->assertSame('ready', $notification->kind);
        $this->assertNull($notification->title);
        $this->assertSame('Your video is ready: Old title', $notification->message);
        $this->assertSame('/profile/videos', $notification->url);
    }
}
