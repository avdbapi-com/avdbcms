<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\Notification as AppNotification;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Models\Video;
use App\Notifications\Activity\NewComment;
use App\Notifications\Activity\NewReply;
use App\Notifications\Activity\NewSubscriber;
use App\Notifications\Activity\NewVideo;
use App\Notifications\Support\NotificationTasks;
use App\Notifications\Support\NotificationText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Notifications (member design 6.14): the settings of Account settings > Notifications are respected, the
 * per-channel bell of a Subscribe button wins over "New videos from channels I follow", a subscriber is announced once
 * (watch-17), the notifications page (tabs, groups, row menus, the sentence with the names in bold), the API of the bell
 * (the rows' parts, avatars and pictures) and the settings form.
 */
class MemberNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:30'));
        NotificationTasks::flushState();
        NotificationSetting::flushSchemaState();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        NotificationTasks::$requestSeconds = 0.5;
        NotificationTasks::flushState();
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ the settings

    public function test_the_settings_are_on_until_a_member_turns_one_off(): void
    {
        $member = User::factory()->create();
        $this->assertSame(NotificationSetting::SWITCHES, NotificationSetting::for($member));

        $this->actingAs($member)->put(route('user.notifications.settings'), [
            'new_subscribers' => '0', 'comments' => '1', 'replies' => '0', 'new_videos' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(['new_subscribers' => false, 'comments' => true, 'replies' => false, 'new_videos' => true], NotificationSetting::for($member->fresh()));
        $this->assertDatabaseHas('notification_settings', ['user_id' => $member->id, 'new_subscribers' => false, 'replies' => false]);
    }

    public function test_new_subscribers_follow_the_switch_and_a_subscriber_is_announced_once(): void
    {
        $creator = User::factory()->create();
        $fan = User::factory()->create();

        $creator->notify(new NewSubscriber($fan));
        $creator->notify(new NewSubscriber($fan)); // unsubscribed and subscribed again
        $this->assertSame(1, $creator->notifications()->where('type', NewSubscriber::class)->count(), 'once per subscriber');

        NotificationSetting::store($creator, ['new_subscribers' => false]);
        $creator->notify(new NewSubscriber(User::factory()->create()));
        $this->assertSame(1, $creator->notifications()->where('type', NewSubscriber::class)->count(), 'switched off');

        $row = $creator->notifications()->firstOrFail();
        $this->assertSame((int) $fan->id, $row->data['actor_id']);
    }

    public function test_comments_and_replies_follow_their_switches(): void
    {
        $owner = User::factory()->create();
        $author = User::factory()->create();
        $replier = User::factory()->create();
        $video = $this->video($owner);
        $parent = Comment::query()->forceCreate(['content' => 'First!', 'user_id' => $author->id, 'video_id' => $video->id]);

        $this->assertSame(1, $owner->notifications()->where('type', NewComment::class)->count(), 'the owner hears about the comment');

        // CommentObserver::created sends NewReply itself (merged 1.2.3): one reply, one notification for its author
        $reply = Comment::query()->forceCreate(['content' => 'Thanks', 'user_id' => $replier->id, 'video_id' => $video->id, 'parent_id' => $parent->id]);
        $this->assertSame($author->id, NewReply::recipient($reply)?->id, 'the reply goes to the comment\'s author');
        $this->assertSame(1, $author->notifications()->where('type', NewReply::class)->count(), 'the observer sent it once');
        $this->assertSame($replier->username.' replied to your comment on '.$video->title, AppNotification::query()->where('notifiable_id', $author->id)->where('type', NewReply::class)->firstOrFail()->message);

        NotificationSetting::store($author, ['replies' => false]);
        $author->notify(new NewReply($reply));
        $this->assertSame(1, $author->notifications()->where('type', NewReply::class)->count(), 'switched off');

        NotificationSetting::store($owner, ['comments' => false]);
        Comment::query()->forceCreate(['content' => 'Another', 'user_id' => $author->id, 'video_id' => $video->id]);
        $this->assertSame(2, $owner->notifications()->where('type', NewComment::class)->count(), 'the reply counted, the new one not');

        // a reply to your own comment, or on your own video, is not a "reply" notification
        $selfReply = Comment::query()->forceCreate(['content' => 'Me again', 'user_id' => $author->id, 'video_id' => $video->id, 'parent_id' => $parent->id]);
        $this->assertNull(NewReply::recipient($selfReply));
    }

    public function test_the_bell_of_a_channel_wins_over_the_global_switch_for_new_videos(): void
    {
        NotificationTasks::$requestSeconds = 0.0;
        $channel = User::factory()->create(['username' => 'bell_channel']);
        $members = [
            'default' => [null, true],          // no choice, switch on: gets it
            'switch_off' => [null, false],      // no choice, switch off: nothing
            'bell_all' => ['all', false],       // the bell wins over the switch
            'bell_none' => ['none', true],      // the bell wins over the switch
            'highlights' => ['highlights', true],
        ];
        $ids = [];

        foreach ($members as $key => [$bell, $switch]) {
            $member = User::factory()->create(['username' => 'member_'.$key]);
            DB::table('subscriptions')->insert(['subscriber_id' => $member->id, 'user_id' => $channel->id, 'subscribe_at' => now(), 'read_at' => now(), 'notify' => $bell]);
            NotificationSetting::store($member, ['new_videos' => $switch]);
            $ids[$key] = $member->id;
        }

        $video = Video::factory()->for($channel)->create(['title' => 'Long video', 'status' => VideoStatus::PRIVATE, 'published_at' => null, 'is_short' => false]);
        $video->update(['status' => VideoStatus::PUBLIC, 'published_at' => now()]);
        Artisan::call('notifications:tidy', ['--seconds' => 10]);

        $got = DB::table('notifications')->where('type', NewVideo::class)->pluck('notifiable_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame(collect([$ids['default'], $ids['bell_all'], $ids['highlights']])->sort()->values()->all(), $got);

        // a Short is not a highlight
        DB::table('notifications')->delete();
        $short = Video::factory()->for($channel)->create(['title' => 'A Short', 'status' => VideoStatus::PRIVATE, 'published_at' => null, 'is_short' => true]);
        $short->update(['status' => VideoStatus::PUBLIC, 'published_at' => now()]);
        Artisan::call('notifications:tidy', ['--seconds' => 10]);

        $got = DB::table('notifications')->where('type', NewVideo::class)->pluck('notifiable_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame(collect([$ids['default'], $ids['bell_all']])->sort()->values()->all(), $got);

        $row = DB::table('notifications')->where('notifiable_id', $ids['default'])->first();
        $data = json_decode((string) $row->data, true);
        $this->assertSame((int) $channel->id, $data['actor_id']);
        $this->assertSame((int) $short->id, $data['video_id']);
        $this->assertTrue(NewVideo::wanted('all', false, false));
        $this->assertFalse(NewVideo::wanted('none', true, true));
        $this->assertFalse(NewVideo::wanted('highlights', true, false));
        $this->assertTrue(NewVideo::wanted(null, true, false));
    }

    public function test_the_bell_api_reads_and_sets_the_level_of_a_followed_channel_only(): void
    {
        $member = User::factory()->create();
        $channel = User::factory()->create();
        $other = User::factory()->create();
        DB::table('subscriptions')->insert(['subscriber_id' => $member->id, 'user_id' => $channel->id, 'subscribe_at' => now(), 'read_at' => now()]);

        $this->actingAs($member)->getJson(route('notifications.channel', $channel))->assertOk()->assertJson(['level' => 'all']);
        $this->actingAs($member)->postJson(route('notifications.channel.update', $channel), ['level' => 'none'])->assertOk()->assertJson(['level' => 'none']);
        $this->assertSame('none', DB::table('subscriptions')->where('subscriber_id', $member->id)->where('user_id', $channel->id)->value('notify'));
        $this->actingAs($member)->postJson(route('notifications.channel.update', $channel), ['level' => 'loud'])->assertStatus(422);
        $this->actingAs($member)->getJson(route('notifications.channel', $other))->assertNotFound();
        $this->actingAs($member)->postJson(route('notifications.channel.update', $other), ['level' => 'all'])->assertNotFound();
    }

    // ------------------------------------------------------------------ the rows

    public function test_the_sentence_comes_in_parts_with_the_names_and_titles_in_bold(): void
    {
        $data = NotificationText::data('new_comment', ['name' => 'trail_runner', 'title' => 'Sunset drive'], []);

        $this->assertSame([
            ['t' => 'trail_runner', 'b' => true],
            ['t' => ' commented on your video: ', 'b' => false],
            ['t' => 'Sunset drive', 'b' => true],
        ], NotificationText::parts(NewComment::class, $data, 'en'));

        $this->assertSame(NotificationText::message(NewComment::class, $data, 'en'), implode('', array_column(NotificationText::parts(NewComment::class, $data, 'en'), 't')));
        $this->assertSame([['t' => 'Hello everyone', 'b' => false]], NotificationText::parts(NotificationText::BROADCAST_TYPE, ['title' => 'News', 'message' => 'Hello everyone']));
    }

    public function test_the_bell_api_sends_the_parts_the_avatar_and_the_picture(): void
    {
        $owner = User::factory()->create();
        $fan = User::factory()->create(['username' => 'fan_member']);
        $video = $this->video($owner);
        Comment::query()->forceCreate(['content' => 'Nice', 'user_id' => $fan->id, 'video_id' => $video->id]);

        $row = $this->actingAs($owner)->getJson('/api/notifications')->assertOk()->json('data.0');

        $this->assertSame('comment', $row['kind']);
        $this->assertSame('fan_member', $row['parts'][0]['t']);
        $this->assertTrue($row['parts'][0]['b']);
        $this->assertSame($fan->fresh()->avatar_url, $row['avatar']);
        $this->assertSame($video->fresh()->thumbnail_url, $row['thumb']);
        $this->assertArrayHasKey('message', $row, 'the fields of 1.2.2 stay');
    }

    // ------------------------------------------------------------------ the page

    public function test_the_page_groups_the_rows_and_gives_each_a_menu(): void
    {
        $member = User::factory()->create();
        $this->addRow($member, 'new_subscriber', ['name' => 'today_fan'], now()->subMinutes(12));
        $this->addRow($member, 'new_subscriber', ['name' => 'yesterday_fan'], now()->subDay(), read: true);
        $this->addRow($member, 'new_subscriber', ['name' => 'week_fan'], now()->subDays(3));
        $this->addRow($member, 'new_subscriber', ['name' => 'old_fan'], now()->subDays(11), read: true);

        $html = (string) $this->actingAs($member)->get(route('user.notifications.index'))->assertOk()->getContent();

        $this->assertStringContainsString('mk-notify-page', $html);
        foreach (['Today', 'Yesterday', 'This week', 'Earlier'] as $group) {
            $this->assertStringContainsString('<div class="mk-notify__group">'.__($group).'</div>', $html);
        }
        $this->assertLessThan(strpos($html, 'yesterday_fan'), strpos($html, 'today_fan'), 'newest first');
        // every row has its "..." (the studio lists' "More actions"), and the page prints the menu ONCE
        $this->assertSame(4, substr_count($html, 'class="mk-icon-btn mk-icon-btn--muted mk-notif__more" data-bs-toggle="dropdown" aria-expanded="false" aria-label="'.e(__('More actions')).'"'));
        $this->assertSame(1, substr_count($html, ' data-avd-row-menu>'), 'one menu element');
        $this->assertSame(1, substr_count($html, 'data-act="delete"'));
        $this->assertMatchesRegularExpression('#<div class="dropdown-menu dropdown-menu-end mk-menu" role="menu" data-avd-row-menu>\s*<button type="button" class="dropdown-item mk-menu__item" role="menuitem" data-act="read">#', $html);
        $this->assertStringContainsString('<strong>today_fan</strong>', $html);
        $this->assertSame(2, substr_count($html, 'class="mk-notif is-unread"'));
        // the screen-reader "Unread" only on the two unread rows; a row's link from the site's root path
        $this->assertSame(2, substr_count($html, '<span class="mk-sr">, '.e(__('Unread')).'</span>'));
        $this->assertMatchesRegularExpression('#<a class="mk-notif__link"\s+href="/profile/notifications/[0-9a-f-]{36}/click"#', $html);
        $this->assertStringContainsString(__('You are all caught up'), $html);

        $unread = (string) $this->actingAs($member)->get(route('user.notifications.index', ['show' => 'unread']))->assertOk()->getContent();
        $this->assertStringContainsString('today_fan', $unread);
        $this->assertStringContainsString('week_fan', $unread);
        $this->assertStringNotContainsString('yesterday_fan', $unread);
        $this->assertStringNotContainsString('old_fan', $unread);
    }

    /**
     * 4.3 page-content budget: the page holds the site's default page size (per_page_comments = 20, which
     * Inbox::perPage() uses), and its content (#main-container: raw bytes, gzip -9, element nodes) stays within the 4.3
     * row of the closest list page, "Comments, 20": 24 KB / 4 KB / 350 nodes. A copy of the row menu in every row made a
     * page of 20 about 41.6 KB and 546 nodes.
     */
    public function test_a_page_of_twenty_notifications_stays_within_its_content_budget(): void
    {
        config(['site.per_page_comments' => 20]);
        $member = User::factory()->create();
        $fans = User::factory()->count(4)->create();
        $videos = [$this->video($member), $this->video($member), $this->video($member)];
        for ($i = 0; $i < 24; $i++) {
            $fan = $fans[$i % 4];
            $video = $videos[$i % 3];
            [$type, $text, $params, $extra] = match ($i % 3) {
                0 => [NewSubscriber::class, 'new_subscriber', ['name' => $fan->username], ['actor_id' => $fan->id, 'url' => '/@'.$fan->username]],
                1 => [NewComment::class, 'new_comment', ['name' => $fan->username, 'title' => $video->title], ['actor_id' => $fan->id, 'video_id' => $video->id, 'url' => '/video/'.$video->id]],
                default => [NewVideo::class, 'new_video', ['name' => $fan->username, 'title' => 'Tokyo night walk in the rain, 4K city ambience'], ['actor_id' => $fan->id, 'video_id' => $video->id, 'url' => '/video/'.$video->id]],
            };
            $at = now()->subMinutes(50 * $i);
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => $type,
                'notifiable_type' => $member->getMorphClass(),
                'notifiable_id' => $member->id,
                'data' => json_encode(NotificationText::data($text, $params, $extra)),
                'read_at' => $i < 6 ? null : $at,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        $html = (string) $this->actingAs($member)->get(route('user.notifications.index'))->assertOk()->getContent();
        $this->assertSame(20, substr_count($html, 'class="mk-notif__link"'), 'one page of the default size');
        $this->assertGreaterThanOrEqual(10, substr_count($html, 'class="mk-avatar"'), 'rows with their members\' pictures');
        $this->assertGreaterThanOrEqual(10, substr_count($html, 'class="mk-notif__thumb"'), 'rows with their videos\' pictures');

        [$raw, $gzip, $nodes] = $this->mainContent($html);
        $this->assertLessThanOrEqual(24 * 1024, $raw, "raw {$raw} bytes");
        $this->assertLessThanOrEqual(4 * 1024, $gzip, "gzip {$gzip} bytes");
        $this->assertLessThanOrEqual(350, $nodes, "{$nodes} element nodes");
    }

    /**
     * The sheet's head: "Mark all as read" is an icon button at every width, named by aria-label + title. (1.2.3 round 3,
     * R3-016: it hands the focus to the menu's button before it goes away, markAllRead.)
     */
    public function test_the_sheets_mark_all_as_read_is_an_icon_button_at_every_width(): void
    {
        $tsx = (string) file_get_contents(base_path('resources/js/components/Notifications/NotificationsList.tsx'));
        $this->assertMatchesRegularExpression('#<button type="button" className="mk-icon-btn mk-icon-btn--muted mk-notify__markall" onClick=\{markAllRead\}\s+disabled=\{isReadAllPending\} aria-busy=\{isReadAllPending \? \'true\' : undefined\}\s+aria-label=\{t\(\'Mark all as read\'\)\} title=\{t\(\'Mark all as read\'\)\}>\s*<i className="fa-solid fa-check-double" aria-hidden="true"></i>\s*</button>#', $tsx);
        $this->assertStringNotContainsString('mk-notify__markall-label', $tsx, 'no label that crowds the title out of the 420px panel');
    }

    public function test_the_page_has_an_empty_state_and_the_settings_partial_renders(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member)->get(route('user.notifications.index'))->assertOk()->assertSeeText(__('No notifications yet'));

        $html = view('users.profile.parts.notifications')->render();
        $this->assertStringContainsString('action="'.e(locale_url(route('user.notifications.settings'))).'"', $html);
        $this->assertSame(4, substr_count($html, 'type="checkbox" role="switch" name="'));
        $this->assertSame(2, substr_count($html, 'checked disabled'), 'upload results and account e-mails are always on');
        $this->assertStringContainsString('name="_method" value="PUT"', $html);
    }

    public function test_a_view_only_demo_account_cannot_change_the_settings(): void
    {
        $demo = User::factory()->create();
        $demo->forceFill(['is_view_only' => true])->save();

        $this->actingAs($demo->fresh())->from(route('user.notifications.index'))
            ->put(route('user.notifications.settings'), ['new_subscribers' => '0'])
            ->assertStatus(303)
            ->assertSessionHasErrors('view_only_demo');
        $this->assertDatabaseMissing('notification_settings', ['user_id' => $demo->id]);
    }

    public function test_the_settings_page_does_not_say_saved_before_the_update_finished(): void
    {
        $member = User::factory()->create();
        Schema::drop('notification_settings');
        NotificationSetting::flushSchemaState();

        // nothing can be kept until "Finish update" adds the table: the page says so (as the bell's own API does), never
        // "Notification settings saved."
        $this->actingAs($member)->put(route('user.notifications.settings'), ['new_subscribers' => '0'])
            ->assertRedirect()
            ->assertSessionMissing('success')
            ->assertSessionHas('error', __('This setting is not available until the site finishes its update.'));
    }

    public function test_deleting_a_member_removes_their_notification_settings(): void
    {
        $member = User::factory()->create();
        NotificationSetting::store($member, ['new_subscribers' => false]);
        $this->assertFalse(NotificationSetting::for($member)['new_subscribers']);

        $member->forceDelete();

        $this->assertDatabaseMissing('notification_settings', ['user_id' => $member->id]);
        $this->assertTrue(NotificationSetting::for($member->id)['new_subscribers'], 'nothing is remembered for the id either');

        // a row deleted without the model (a bulk delete in SQL) takes its settings too: the foreign key
        $other = User::factory()->create();
        NotificationSetting::store($other, ['comments' => false]);
        DB::table('users')->where('id', $other->id)->delete();
        $this->assertDatabaseMissing('notification_settings', ['user_id' => $other->id]);
    }

    public function test_the_bell_and_the_page_group_and_date_a_row_the_same_way_in_the_sites_time_zone(): void
    {
        $zone = (string) config('app.timezone');
        // 00:30 on Monday 26 Oct for the site (23:30 on Sunday in UTC, already Monday morning in Bangkok)
        Carbon::setTestNow(Carbon::parse('2026-10-26 00:30:00', $zone));
        $member = User::factory()->create();
        // 23:50 on Sunday for the site: "Yesterday" on the page, and now in the bell too (the browser's zone no longer decides)
        $this->addRow($member, 'new_subscriber', ['name' => 'late_fan'], Carbon::parse('2026-10-25 23:50:00', $zone));
        $this->addRow($member, 'new_subscriber', ['name' => 'old_fan'], Carbon::parse('2026-10-14 10:00:00', $zone), read: true);

        $rows = $this->actingAs($member)->getJson('/api/notifications')->assertOk()->json('data');
        $this->assertSame(['yesterday', 'earlier'], array_column($rows, 'group'));
        $this->assertSame(__('Yesterday, :time', ['time' => '23:50']), $rows[0]['when']);
        $this->assertSame(Carbon::parse('2026-10-14 10:00:00', $zone)->translatedFormat((string) config('site.date_format', 'M d, Y')), $rows[1]['when'], 'the admin\'s date format');
        $this->assertSame(Carbon::parse('2026-10-25 23:50:00', $zone)->toIso8601String(), $rows[0]['iso']);

        $html = (string) $this->get(route('user.notifications.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<div class="mk-notify__group">'.__('Yesterday').'</div>', $html);
        foreach ($rows as $row) {
            $this->assertStringContainsString('<time datetime="'.e($row['iso']).'">'.e($row['when']).'</time>', $html, 'the page and the bell say the same');
        }
    }

    public function test_the_account_panel_counts_the_unread_notifications(): void
    {
        $member = User::factory()->create();
        foreach (['first_fan', 'second_fan', 'third_fan'] as $name) {
            $this->addRow($member, 'new_subscriber', ['name' => $name], now()->subMinutes(5));
        }
        $this->addRow($member, 'new_subscriber', ['name' => 'old_fan'], now()->subDay(), read: true);

        $link = '#<a class="mk-account__link" href="'.preg_quote(e(locale_url(route('user.notifications.index'))), '#').'"[^>]*>.*?</a>#s';

        preg_match($link, (string) $this->actingAs($member)->get('/trend')->assertOk()->getContent(), $panel);
        $this->assertNotEmpty($panel, 'the panel has its Notifications link');
        $this->assertMatchesRegularExpression('#<span>'.preg_quote(e(__('Notifications')), '#').'</span><span class="mk-count" aria-label="[^"]+">3</span></a>#', $panel[0]);

        // the owner's "Notifications" header switch off: no count, as in the header (the link stays)
        config(['site.header_notifications_enabled' => false]);
        Cache::flush();
        preg_match($link, (string) $this->actingAs($member)->get('/trend')->assertOk()->getContent(), $panel);
        $this->assertNotEmpty($panel);
        $this->assertStringNotContainsString('mk-count', $panel[0]);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('user.notifications.index'))->assertRedirect(route('login'));
        $this->put(route('user.notifications.settings'), [])->assertRedirect(route('login'));
    }

    private function video(User $owner): Video
    {
        return Video::factory()->for($owner)->create(['title' => 'Sunset drive '.Str::random(4), 'status' => VideoStatus::PUBLIC, 'published_at' => now()]);
    }

    /**
     * The page content as 4.3 measures it: the #main-container element
     * of the page, raw bytes and gzip -9 bytes, and the element nodes inside it.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function mainContent(string $html): array
    {
        $start = strpos($html, '<div id="main-container"');
        $this->assertNotFalse($start, 'the page has #main-container');
        $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
        // a tag whose quoted attribute values may hold ">" (Alpine expressions), or a comment
        $pattern = '#<!--.*?-->|<(/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:\s+[^\s"\'>/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+))?)*)\s*(/?)>#s';
        $depth = 0;
        $nodes = 0;
        $offset = (int) $start;
        $end = null;
        while ($end === null && preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $offset = $m[0][1] + strlen($m[0][0]);
            if (str_starts_with($m[0][0], '<!--')) {
                continue;
            }
            $name = strtolower($m[2][0]);
            if ($m[1][0] === '/') {
                if (--$depth === 0) {
                    $end = $offset;
                }

                continue;
            }
            if ($depth > 0) {
                $nodes++;
            }
            if (in_array($name, $void, true) || $m[4][0] === '/') {
                continue;
            }
            $depth++;
            if (in_array($name, ['script', 'style', 'textarea'], true)) {
                // raw text: its end tag is the next one of its name
                $offset = (int) stripos($html, '</'.$name, $offset);
            }
        }
        $this->assertNotNull($end, '#main-container ends');
        $content = substr($html, (int) $start, (int) $end - (int) $start);

        return [strlen($content), strlen((string) gzencode($content, 9)), $nodes];
    }

    private function addRow(User $member, string $text, array $params, Carbon $at, bool $read = false): void
    {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => NewSubscriber::class,
            'notifiable_type' => $member->getMorphClass(),
            'notifiable_id' => $member->id,
            'data' => json_encode(NotificationText::data($text, $params, ['url' => '/@x'])),
            'read_at' => $read ? $at : null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
