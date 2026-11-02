<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Subscribe, the subscriptions feed, Manage subscriptions and Discover of the member redesign (member design 6.10,
 * 6.11; QA channel-17, 23, 24, 25, 33, studio-23).
 */
class MemberSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['site.ads_enabled' => false]);
    }

    public function test_subscribe_with_a_named_state_never_flips_back_and_answers_the_new_count(): void
    {
        $member = User::factory()->create();
        $channel = User::factory()->create(['show_subscribers' => true]);

        // QA channel-17: the toggle unsubscribed a member whose button showed a stale state (a second tab, a double tap)
        foreach ([1, 2] as $try) {
            $this->actingAs($member, 'sanctum')->postJson(route('subscribe', $channel), ['state' => 'on'])
                ->assertOk()
                ->assertExactJson(['subscribed' => true, 'subscribers' => 1]);
        }
        $this->assertSame(1, DB::table('subscriptions')->where('user_id', $channel->id)->count());

        foreach ([1, 2] as $try) {
            $this->actingAs($member, 'sanctum')->postJson(route('subscribe', $channel), ['state' => 'off'])
                ->assertOk()
                ->assertExactJson(['subscribed' => false, 'subscribers' => 0]);
        }
        $this->assertSame(0, DB::table('subscriptions')->where('user_id', $channel->id)->count());
    }

    public function test_the_answer_hides_the_count_where_the_channel_or_the_site_hides_it(): void
    {
        $member = User::factory()->create();
        $hidden = User::factory()->create(['show_subscribers' => false]);

        $this->actingAs($member, 'sanctum')->postJson(route('subscribe', $hidden), ['state' => 'on'])
            ->assertOk()
            ->assertExactJson(['subscribed' => true, 'subscribers' => null]);

        $shown = User::factory()->create(['show_subscribers' => true]);
        config(['site.show_subscriber_count' => false]);
        $this->actingAs($member, 'sanctum')->postJson(route('subscribe', $shown), ['state' => 'on'])
            ->assertOk()
            ->assertJsonPath('subscribers', null);
    }

    public function test_a_subscription_keeps_the_applications_clock(): void
    {
        // QA studio-23: subscribe_at took the database's own time zone ("5 hours from now")
        Carbon::setTestNow(Carbon::create(2026, 10, 25, 12, 0, 0));
        try {
            $member = User::factory()->create();
            $channel = User::factory()->create();

            $this->actingAs($member, 'sanctum')->postJson(route('subscribe', $channel), ['state' => 'on'])->assertOk();
            $this->assertSame('2026-10-25 12:00:00', (string) DB::table('subscriptions')->where('user_id', $channel->id)->value('subscribe_at'));

            $other = User::factory()->create();
            $this->actingAs($member, 'sanctum')->post(route('subscribe', $other))->assertNoContent();   // the 1.2.2 toggle
            $this->assertSame('2026-10-25 12:00:00', (string) DB::table('subscriptions')->where('user_id', $other->id)->value('subscribe_at'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_manage_pages_searches_sorts_and_respects_hidden_counts(): void
    {
        config(['site.per_page_users' => 2]);
        $member = User::factory()->create();
        $names = ['alpha_channel' => true, 'bravo_channel' => false, 'charlie_channel' => true];
        $n = 0;
        foreach ($names as $name => $showsCount) {
            $channel = User::factory()->create(['username' => $name, 'show_subscribers' => $showsCount]);
            DB::table('subscriptions')->insert(['user_id' => $channel->id, 'subscriber_id' => $member->id, 'subscribe_at' => now()->subDays(++$n), 'read_at' => now()]);
        }

        // recently subscribed first, two a page (QA channel-25: every subscription at once, no paging or search)
        // (the page's list only: the site's side menu lists every channel the member follows)
        $first = $this->between($this->actingAs($member)->get(route('subscription.manage'))->assertOk()->getContent(), 'mk-subs-manage__rows', '</section>');
        $this->assertStringContainsString('alpha_channel', $first);
        $this->assertStringContainsString('bravo_channel', $first);
        $this->assertStringNotContainsString('charlie_channel', $first);
        $this->assertStringContainsString('class="mk-pager"', $first);
        // QA channel-24: a channel that hides its count shows "Count hidden", never the number
        $this->assertStringContainsString('Count hidden', $first);

        $this->actingAs($member)->get(route('subscription.manage', ['sort' => 'name', 'page' => 2]))->assertOk()->assertSee('charlie_channel');
        $found = $this->between($this->actingAs($member)->get(route('subscription.manage', ['q' => 'brav']))->assertOk()->getContent(), 'mk-subs-manage__rows', '</section>');
        $this->assertStringContainsString('bravo_channel', $found);
        $this->assertStringNotContainsString('alpha_channel', $found);
        $this->actingAs($member)->get(route('subscription.manage', ['q' => '%_%']))->assertOk()->assertSee('No channel matches your search');
    }

    public function test_the_feed_has_a_page_head_and_the_guest_page_offers_sign_in_and_create_account(): void
    {
        $member = User::factory()->create();
        $channel = User::factory()->create(['username' => 'feed_channel']);
        DB::table('subscriptions')->insert(['user_id' => $channel->id, 'subscriber_id' => $member->id, 'subscribe_at' => now(), 'read_at' => now()]);

        // QA channel-23: no title, a green button, px-0 on phones, a guest page with Sign in only
        $html = $this->actingAs($member)->get(route('subscription.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Latest from your channels', $html);
        $this->assertStringContainsString('feed_channel', $html);
        $this->assertStringNotContainsString('btn-success', $this->between($html, 'mk-subs-feed', '</main>'));
        $this->assertStringNotContainsString('class="container-fluid my-3 px-0', $html);

        auth()->logout();
        config(['site.registration_enabled' => true]);
        $this->get(route('subscription.index'))->assertOk()->assertSee('Sign in')->assertSee('Create account')->assertSee('Discover channels');
        config(['site.registration_enabled' => false]);
        $this->get(route('subscription.index'))->assertOk()->assertDontSee(route('registration'));
    }

    public function test_manage_explains_the_bell_once_the_site_has_lane_m3s_channel_bell(): void
    {
        $member = User::factory()->create();
        $channel = User::factory()->create(['username' => 'bell_channel']);
        DB::table('subscriptions')->insert(['user_id' => $channel->id, 'subscriber_id' => $member->id, 'subscribe_at' => now(), 'read_at' => now()]);

        if (! \Illuminate\Support\Facades\Route::has('notifications.channel')) {
            // without the channel bell route: no bell, so the head says how to unsubscribe and the button has no chevron
            $html = $this->actingAs($member)->get(route('subscription.manage'))->assertOk()->getContent();
            $this->assertStringContainsString('1 channel. Unsubscribe from one with its Subscribed button.', $html);
            $this->assertStringNotContainsString('mk-bell-menu__chevron', $html);
            \Illuminate\Support\Facades\Route::middleware(['api', 'auth:sanctum'])->prefix('api')
                ->get('/notifications/channels/{user}', fn () => response()->json(['level' => 'all']))
                ->name('notifications.channel');
            \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
        }

        // with the bell (the mockup's words), and the Subscribed placeholder shows the bell and the chevron of its menu
        $html = $this->actingAs($member)->get(route('subscription.manage'))->assertOk()->getContent();
        $this->assertStringContainsString('1 channel. The bell on its button sets what it notifies you about.', $html);
        $this->assertStringContainsString('mk-bell-menu__chevron', $html);
    }

    public function test_discover_cards_carry_a_one_line_subscribe_and_the_live_count(): void
    {
        $member = User::factory()->create();
        $channel = User::factory()->create(['username' => 'discover_me', 'show_subscribers' => true]);
        Video::factory()->for($channel)->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);

        $html = $this->actingAs($member)->get(route('subscription.discover'))->assertOk()->getContent();

        $this->assertStringContainsString('discover_me', $html);
        $this->assertStringContainsString('<subscribe-button user="' . $channel->id . '" subscribed="0"', $html);
        $this->assertStringContainsString('data-count-on="1 subscriber"', $html);
        $this->assertStringContainsString('mk-btn--fixed mk-subscribe', $html);
        // QA channel-33: no animated gradient title (the old avd-home-title repainted the page for 18 s)
        $this->assertStringNotContainsString('avd-home-title', $html);
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = (int) strpos($html, $from);
        $end = strpos($html, $to, $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }
}
