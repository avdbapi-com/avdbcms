<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Support\ChannelAccent;
use App\Support\ChannelCustomizationSettings;
use App\Support\LocalNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The channel page /@channel of the member redesign (member design 6.10, 6.19): the header band, the tabs,
 * Home, the Videos and Playlists tabs that page through everything, About, the empty states and the creator's options.
 */
class MemberChannelPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['site.channel_customization_enabled' => true, 'site.ads_enabled' => false]);
    }

    public function test_the_about_tab_shows_the_real_channel_address_with_a_copy_button(): void
    {
        $channel = $this->channel(['username' => 'night_drive', 'slug' => 'NightDrive', 'profile_uid' => null]);
        config(['site.profile_uid_urls_enabled' => false]);

        $this->get($channel->route)->assertOk();
        $html = (string) $this->getJson($channel->route . '?part=about')->assertOk()->json('html');

        // QA channel-8: 1.2.2 printed APP_URL/@<username>, an address that answered 404
        $this->assertStringContainsString('data-copy-text="' . $channel->route . '"', $html);
        $this->assertStringNotContainsString(config('app.url') . '/@night_drive', $html);
        $this->assertStringContainsString(__('Channel address'), $html);
    }

    public function test_about_writes_the_views_with_thousands_separators_and_the_last_upload_label(): void
    {
        $channel = $this->channel();
        $this->video($channel, ['views' => 6552004, 'published_at' => now()->subDays(3)]);

        $this->get($channel->route)
            ->assertOk()
            ->assertSee('Last upload: 3 days ago')   // QA channel-18: "Upload rhythm" read "upload a rhythm"
            ->assertDontSee('Upload rhythm');
        // QA channel-16: "6552004 views" (the About tab)
        $this->assertStringContainsString('<dd>6,552,004</dd>', (string) $this->getJson($channel->route . '?part=about')->assertOk()->json('html'));
    }

    public function test_the_about_tab_loads_when_it_is_opened_and_follows_the_about_switch(): void
    {
        $channel = $this->channel(['website' => 'creator.example.test', 'description' => "First line\nSecond line", 'country' => 'DE']);

        // the first screen carries no About markup (4.3: a channel's first screen <= 16 KB / 3 KB / 250 nodes)
        $page = $this->get($channel->route)->assertOk()->getContent();
        $this->assertStringContainsString('data-part-list="about"', $page);
        $this->assertStringNotContainsString('mkDetailsTitle', $page);

        $about = (string) $this->getJson($channel->route . '?part=about')->assertOk()->json('html');
        $this->assertStringContainsString('id="mkDetailsTitle"', $about);
        $this->assertStringContainsString('First line<br />', $about);
        $this->assertStringContainsString('href="https://creator.example.test"', $about);

        $channel->forceFill(['channel_customization' => ['show_about_panel' => '0']])->save();
        $this->getJson($channel->fresh()->route . '?part=about')->assertNotFound();
        $this->assertStringNotContainsString('href="#about"', $this->get($channel->fresh()->route)->assertOk()->getContent());
    }

    public function test_the_page_prints_no_side_padding_override_and_the_tabs_are_links(): void
    {
        $channel = $this->channel();
        $this->video($channel);

        $html = $this->get($channel->route)->assertOk()->getContent();

        // 5.2: the site's own gutter (1.2.2 printed px-0 and the band touched the screen edges on phones)
        $this->assertStringNotContainsString('class="container-fluid my-3 px-0', $html);
        // QA channel-14: each tab has an address of its own
        foreach (['#home', '#videos', '#playlists', '#about'] as $hash) {
            $this->assertStringContainsString('href="' . $hash . '"', $html);
        }
        // QA channel-13: "See all" opens the Videos tab (no inline Bootstrap tab call)
        $this->assertStringNotContainsString("new bootstrap.Tab('#videos_link')", $html);
    }

    public function test_the_playlists_tab_pages_through_every_public_playlist(): void
    {
        $channel = $this->channel();
        $video = $this->video($channel);
        $public = [];

        // QA channel-2: the tab showed the 6 newest (profile_playlists_limit); 14 public ones need two pages of 12
        foreach (range(1, 14) as $n) {
            $playlist = Playlist::factory()->for($channel)->create(['title' => 'Public list ' . $n, 'status' => PlaylistStatus::PUBLIC]);
            $playlist->videos()->attach($video->id, ['position' => 1]);
            DB::table('playlists')->where('id', $playlist->id)->update(['updated_at' => now()->subMinutes($n)]);
            $public[] = 'Public list ' . $n;
        }
        foreach ([PlaylistStatus::PRIVATE, PlaylistStatus::UNLISTED] as $status) {
            $hidden = Playlist::factory()->for($channel)->create(['title' => 'Hidden ' . $status->name, 'status' => $status]);
            $hidden->videos()->attach($video->id, ['position' => 1]);
        }

        $titles = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->getJson($channel->route . '?' . http_build_query(array_filter(['part' => 'playlists', 'cursor' => $cursor])))
                ->assertOk()
                ->assertJsonStructure(['html', 'next_cursor', 'count']);
            $this->assertSame(14, $page->json('count'));
            // the card's title (its two lines come from the stylesheet since R1-069, no clamp class)
            preg_match_all('#<span class="mk-card__title">([^<]+)</span>#', (string) $page->json('html'), $found);
            $titles = array_merge($titles, array_map('html_entity_decode', $found[1]));
            $cursor = $page->json('next_cursor');
        } while ($cursor !== null && ++$pages < 5);

        $this->assertSame(2, $pages + 1);
        $this->assertSame($public, $titles);
    }

    public function test_the_videos_tab_pages_and_sorts_the_public_videos_and_places_the_list_ad(): void
    {
        $channel = $this->channel();
        $titles = [];
        foreach (range(1, 30) as $n) {
            $this->video($channel, ['title' => 'Clip ' . $n, 'views' => $n * 10, 'published_at' => now()->subHours($n)]);
            $titles[] = 'Clip ' . $n;
        }
        $this->video($channel, ['title' => 'Secret clip'], VideoStatus::PRIVATE);

        config([
            'site.ads_enabled' => true,
            'site.ad_grid_position' => 6,
            'site.ad_grid_max_per_feed' => 2,
            'site.ad_code_grid_channel_videos' => '<span class="probe-ad">ad</span>',
        ]);

        $first = $this->getJson($channel->route . '?part=videos&sort=latest')->assertOk();
        $this->assertSame(30, $first->json('count'));
        $html = (string) $first->json('html');
        $this->assertSame(24, substr_count($html, 'class="mk-card"'));
        $this->assertStringNotContainsString('Secret clip', $html);
        // "Ad after every 6", at most 2 a list: after the 6th and the 12th video
        $this->assertSame(2, substr_count($html, 'probe-ad'));
        $this->assertLessThan(strpos($html, 'Clip 7<'), strpos($html, 'probe-ad'));

        $second = $this->getJson($channel->route . '?part=videos&sort=latest&shown=24&cursor=' . urlencode((string) $first->json('next_cursor')))->assertOk();
        $this->assertNull($second->json('next_cursor'));
        $this->assertSame(6, substr_count((string) $second->json('html'), 'class="mk-card"'));
        $this->assertSame(0, substr_count((string) $second->json('html'), 'probe-ad'));

        $popular = (string) $this->getJson($channel->route . '?part=videos&sort=popular')->json('html');
        $this->assertLessThan(strpos($popular, 'Clip 29<'), strpos($popular, 'Clip 30<'));
    }

    public function test_an_empty_channel_tells_its_owner_how_to_start_and_visitors_that_nothing_is_there(): void
    {
        $owner = $this->channel();

        $this->actingAs($owner)->get($owner->route)
            ->assertOk()
            ->assertSee('Upload your first video')
            ->assertSee('data-bs-target="#video_create"', false)
            ->assertSee('Customize channel');

        // uploads switched off: the empty state keeps Customize channel and has no Upload button
        config(['site.allow_uploads' => false]);
        $html = $this->actingAs($owner)->get($owner->route)->assertOk()->getContent();
        $empty = substr($html, (int) strpos($html, 'class="mk-empty'), 1500);
        $this->assertStringContainsString('Customize channel', $empty);
        $this->assertStringNotContainsString('data-bs-target="#video_create"', $empty);

        $this->actingAs(User::factory()->create())->get($owner->route)
            ->assertOk()
            ->assertSee('No videos yet')
            ->assertDontSee('Upload your first video')
            ->assertDontSee('Channel without content');
    }

    public function test_subscribe_states_and_the_live_count_texts(): void
    {
        $channel = $this->channel(['show_subscribers' => true]);
        $this->video($channel);
        $fan = User::factory()->create();
        DB::table('subscriptions')->insert(['user_id' => $channel->id, 'subscriber_id' => $fan->id, 'subscribe_at' => now(), 'read_at' => now()]);

        // a follower: the Subscribed state; the count texts for both states (2 with them, 1 without)
        $this->actingAs($fan)->get($channel->route)
            ->assertOk()
            ->assertSee('subscribed="1"', false)
            ->assertSee('data-count-on="1 subscriber"', false)
            ->assertSee('data-count-off="0 subscribers"', false);

        // a member who does not follow: Subscribe, and the count one higher once subscribed
        $this->actingAs(User::factory()->create())->get($channel->route)
            ->assertOk()
            ->assertSee('subscribed="0"', false)
            ->assertSee('data-count-on="2 subscribers"', false);

        // a guest: a primary Subscribe that opens the sign-in prompt
        auth()->logout();
        $this->get($channel->route)->assertOk()->assertSee('data-auth-prompt', false)->assertDontSee('<subscribe-button', false);
    }

    public function test_hidden_subscriber_counts_are_never_printed(): void
    {
        $channel = $this->channel(['show_subscribers' => false]);
        $this->video($channel);

        $this->get($channel->route)->assertOk()->assertDontSee('data-subscriber-count', false);

        $channel->forceFill(['show_subscribers' => true])->save();
        config(['site.show_subscriber_count' => false]);
        $this->get($channel->route)->assertOk()->assertDontSee('data-subscriber-count', false);
    }

    public function test_every_customization_switch_hides_its_part_of_the_page(): void
    {
        $channel = $this->channel([
            'website' => 'creator.example.test',
            'channel_customization' => [
                'cta_label' => 'Visit my shop',
                'cta_url' => 'https://shop.example.test',
                'show_subscribe_cta' => '1',
                'show_featured_video' => '1',
                'show_latest_uploads' => '1',
                'show_playlists' => '1',
                'show_about_panel' => '1',
                'show_stats' => '1',
                'dedupe_home_sections' => '1',
                'featured_columns' => '1',
                'featured_rows' => '1',
            ],
        ]);
        $first = $this->video($channel, ['title' => 'Featured candidate', 'published_at' => now()->subHour()]);
        $this->video($channel, ['title' => 'Older upload', 'published_at' => now()->subDays(2)]);
        $list = Playlist::factory()->for($channel)->create(['title' => 'Channel playlist one', 'status' => PlaylistStatus::PUBLIC]);
        $list->videos()->attach($first->id, ['position' => 1]);

        $viewer = User::factory()->create();
        $this->actingAs($viewer)->get($channel->route)
            ->assertOk()
            ->assertSee('mk-channel__featured', false)
            ->assertSee('Latest uploads')
            ->assertSee('Channel playlist one')
            ->assertSee('href="#about"', false)
            ->assertSee('Visit my shop')
            ->assertSee('(opens another site)');

        foreach ([
            'site.channel_customization_featured_video_enabled' => 'mk-channel__featured',
            'site.channel_customization_latest_uploads_enabled' => 'mkLatestTitle',
            'site.channel_customization_playlists_enabled' => 'mkPlaylistsTitle',
            'site.channel_customization_sections_enabled' => 'href="#about"',
            'site.channel_customization_cta_enabled' => 'Visit my shop',
        ] as $switch => $part) {
            config([$switch => false]);
            $this->actingAs($viewer)->get($channel->route)->assertOk()->assertDontSee($part, false);
            config([$switch => true]);
        }
    }

    public function test_featured_is_one_lead_card_and_latest_uploads_start_with_the_newest_other_video(): void
    {
        // one featured video (1 x 1) is the lead card; more are a card grid (the next test)
        $channel = $this->channel(['channel_customization' => ['featured_mode' => 'popular', 'featured_columns' => '1', 'featured_rows' => '1', 'dedupe_home_sections' => '1']]);
        $this->video($channel, ['title' => 'Most watched', 'views' => 5000, 'published_at' => now()->subDays(10)]);
        $this->video($channel, ['title' => 'Uploaded today', 'views' => 3, 'published_at' => now()->subHour()]);
        $this->video($channel, ['title' => 'Uploaded yesterday', 'views' => 4, 'published_at' => now()->subDay()]);

        $html = $this->get($channel->route)->assertOk()->getContent();
        $featured = substr($html, strpos($html, 'mk-channel__featured'), 2000);
        $latest = substr($html, (int) strpos($html, 'mkLatestTitle'));

        $this->assertStringContainsString('Most watched', $featured);
        $this->assertStringNotContainsString('Most watched', $latest);
        // revision 2: Latest uploads newest first
        $this->assertLessThan(strpos($latest, 'Uploaded yesterday'), strpos($latest, 'Uploaded today'));
    }

    public function test_more_featured_videos_are_a_card_grid_in_the_creators_layout_without_repeats_in_latest(): void
    {
        $channel = $this->channel(['channel_customization' => [
            'featured_mode' => 'latest', 'featured_columns' => '2', 'featured_rows' => '1', 'featured_layout' => 'hero',
            'dedupe_home_sections' => '1',
        ]]);
        foreach (range(1, 4) as $n) {
            $this->video($channel, ['title' => 'Upload number ' . $n, 'published_at' => now()->subHours($n)]);
        }

        $html = $this->get($channel->route)->assertOk()->getContent();
        $featured = substr($html, (int) strpos($html, 'mkFeatTitle'), 4000);
        $latest = substr($html, (int) strpos($html, 'mkLatestTitle'));

        $this->assertStringContainsString('mk-cards--showcase', $featured);   // "Lead story": the first card is the lead
        $this->assertSame(2, substr_count(substr($featured, 0, (int) strpos($featured, '</section>')), '<a class="mk-card"'));
        $this->assertStringContainsString('Upload number 1', $featured);
        $this->assertStringNotContainsString('Upload number 1', $latest);
        $this->assertStringContainsString('Upload number 3', $latest);
    }

    public function test_the_look_accent_adds_no_colour_and_an_own_accent_is_adjusted_for_each_mode(): void
    {
        $look = $this->channel(['channel_customization' => ['accent' => 'look']]);
        $html = $this->get($look->route)->assertOk()->getContent();
        $this->assertStringContainsString('data-channel-accent="look"', $html);
        $this->assertStringNotContainsString('--mk-accent-dark', $html);

        $custom = $this->channel(['channel_customization' => ['accent' => 'custom', 'custom_accent' => '#fff200']]);
        $html = $this->get($custom->route)->assertOk()->getContent();
        $inks = ChannelAccent::inks('custom', '#fff200');
        $this->assertNotNull($inks);
        $this->assertStringContainsString('--mk-accent-dark:' . $inks['dark'] . ';--mk-accent-light:' . $inks['light'] . ';', $html);
    }

    public function test_follow_the_look_is_a_band_and_accent_choice_and_the_stored_defaults_are_kept(): void
    {
        $this->assertArrayHasKey('look', ChannelCustomizationSettings::options()['theme']);
        $this->assertArrayHasKey('look', ChannelCustomizationSettings::options()['accent']);
        $this->assertSame('look', ChannelCustomizationSettings::merge(['theme' => 'look'])['theme']);

        // OD-M14: the site's default band stays as stored (midnight unless the owner chose another)
        config(['site.channel_default_theme' => 'midnight']);
        $this->assertSame('midnight', ChannelCustomizationSettings::merge([])['theme']);
        // an accent the owner stored stays theirs
        config(['site.channel_default_accent' => 'coral']);
        $this->assertSame('coral', ChannelCustomizationSettings::merge([])['accent']);

        $channel = $this->channel(['channel_customization' => ['theme' => 'look']]);
        $this->get($channel->route)->assertOk()->assertSee('data-channel-theme="look"', false);
    }

    public function test_a_channel_without_a_banner_shows_the_looks_colours_not_the_stock_picture(): void
    {
        $channel = $this->channel(['banner' => null]);

        $this->get($channel->route)
            ->assertOk()
            ->assertSee('mk-channel__banner--token', false)
            ->assertDontSee('default_banner.jpg');
    }

    public function test_a_long_tagline_keeps_its_two_lines_from_the_first_paint(): void
    {
        // QA channel-20: the band grew when the web font arrived (CLS 0.158 at 1366 with a long tagline)
        $long = $this->channel(['channel_customization' => ['tagline' => 'Night drives, rain on the windscreen and synth music for late hours']]);
        $short = $this->channel(['channel_customization' => ['tagline' => 'Night drives']]);

        $this->get($long->route)->assertOk()
            ->assertSee('class="mk-channel__tagline mk-clamp-2 mk-channel__tagline--two"', false);
        $html = $this->get($short->route)->assertOk()->getContent();
        $this->assertStringContainsString('class="mk-channel__tagline mk-clamp-2">Night drives</p>', $html);
        $this->assertStringNotContainsString('mk-channel__tagline--two', $html);
    }

    public function test_a_channel_can_be_shared_from_its_band(): void
    {
        // QA channel-9: there was no way to share a channel (no Share button, no copy-link, no share sheet)
        $channel = $this->channel(['username' => 'share_me', 'slug' => 'ShareMe']);
        $this->video($channel);

        $html = $this->actingAs(User::factory()->create())->get($channel->route)->assertOk()->getContent();

        $this->assertStringContainsString('data-share-dialog="#mkShareChannel"', $html);
        $this->assertStringContainsString('id="mkShareChannel"', $html);
        $this->assertStringContainsString('data-share-url="' . $channel->route . '"', $html);
        $this->assertStringContainsString('data-copy-text="' . $channel->route . '"', $html);
        foreach (['x', 'facebook', 'whatsapp', 'telegram', 'email'] as $service) {
            $this->assertStringContainsString('data-share-to="' . $service . '"', $html);
        }
        // the dialog sits after the page root (Bootstrap's place for a dialog), outside the channel's content budget
        $this->assertGreaterThan(strpos($html, 'class="avd-mk mk-page mk-channel-page"'), strpos($html, 'id="mkShareChannel"'));
    }

    // ------------------------------------------------------------------------------------------- review fixes

    public function test_home_honours_the_admin_limits_above_24_and_the_creators_playlist_size(): void
    {
        $channel = $this->channel(['channel_customization' => ['show_featured_video' => '0', 'playlists_columns' => '4', 'playlists_rows' => '3']]);
        $videos = [];
        foreach (range(1, 32) as $n) {
            $videos[] = $this->video($channel, ['title' => 'Home clip ' . $n, 'published_at' => now()->subHours($n)]);
        }
        foreach (range(1, 14) as $n) {
            $playlist = Playlist::factory()->for($channel)->create(['title' => 'Home list ' . $n, 'status' => PlaylistStatus::PUBLIC, 'updated_at' => now()->subMinutes($n)]);
            $playlist->videos()->attach($videos[$n]->id, ['position' => 1]);
        }

        // the admin may set 1-48 (QA: Home stopped at 24 whatever the admin set)
        config(['site.profile_videos_limit' => 30, 'site.profile_playlists_limit' => 6]);
        $html = $this->get($channel->route)->assertOk()->getContent();
        $home = substr($html, (int) strpos($html, 'id="channelHome"'), (int) strpos($html, 'id="channelVideos"') - (int) strpos($html, 'id="channelHome"'));
        $this->assertSame(30, substr_count($this->section($home, 'mkLatestTitle'), 'class="mk-card"'));
        // the creator's playlists columns x rows (4 x 3) wins over the admin's 6, as 1.2.2 loaded them
        $this->assertSame(12, substr_count($this->section($home, 'mkPlaylistsTitle'), 'class="mk-card mk-card--playlist"'));
    }

    public function test_an_empty_channel_says_no_videos_yet_once(): void
    {
        $channel = $this->channel();

        $html = $this->get($channel->route)->assertOk()->getContent();
        $facts = substr($html, (int) strpos($html, 'class="mk-channel__facts"'), 600);
        $this->assertStringContainsString('No videos yet', $facts);
        $this->assertStringNotContainsString('0 videos', $facts);   // QA: "0 videos · No videos yet"
    }

    public function test_an_untouched_installer_accent_follows_the_look_and_a_saved_one_is_kept(): void
    {
        $seeded = now()->subMonths(6)->startOfSecond();
        DB::table('settings')->updateOrInsert(['key' => 'channel_default_accent'], ['value' => 'coral', 'group' => 'users', 'created_at' => $seeded, 'updated_at' => $seeded]);
        $read = function (): string {
            Cache::flush();

            return ChannelCustomizationSettings::text(['channel_default_accent' => 'coral'], 'channel_default_accent');
        };

        // the installer's seed of every site before 1.2.3: nobody chose it, so the channels follow the look (QA channel-11)
        $this->assertSame('look', $read());
        // other values and other keys are as stored
        $this->assertSame('cyan', ChannelCustomizationSettings::text(['channel_default_accent' => 'cyan'], 'channel_default_accent'));
        $this->assertSame('midnight', ChannelCustomizationSettings::text(['channel_default_theme' => 'midnight'], 'channel_default_theme'));

        // an owner who changed it since (the row has a later time) keeps coral
        DB::table('settings')->where('key', 'channel_default_accent')->update(['updated_at' => $seeded->copy()->addDay()]);
        $this->assertSame('coral', $read());

        // an automatic revision (an update's snapshot) is no choice of the owner
        DB::table('settings')->where('key', 'channel_default_accent')->update(['updated_at' => $seeded]);
        \App\Support\SettingsRevision::record('upgrade_snapshot', 'users', ['users' => ['channel_default_accent' => 'coral']], ['users' => ['channel_default_accent' => 'coral']]);
        $this->assertSame('look', $read());

        // an owner who saved Coral on Appearance > Channels (a "publish" revision) keeps it, also when the value did not
        // change
        \App\Support\SettingsRevision::record('publish', 'users', ['users' => ['channel_default_accent' => 'coral']], ['users' => ['channel_default_accent' => 'coral']]);
        $this->assertSame('coral', $read());

        // ... and so does one who saved it on a settings page
        DB::table('admin_setting_revisions')->delete();
        $this->assertSame('look', $read());
        \App\Support\SettingsRevision::record('settings_save', 'users', ['users' => ['channel_default_accent' => 'coral']], ['users' => ['channel_default_accent' => 'coral']]);
        $this->assertSame('coral', $read());
    }

    public function test_the_videos_tab_prints_a_full_width_ad_after_its_video_and_the_grid_packs_it_after_that_row(): void
    {
        $channel = $this->channel();
        foreach (range(1, 14) as $n) {
            $this->video($channel, ['title' => 'Wide clip ' . $n, 'published_at' => now()->subHours($n)]);
        }
        config([
            'site.ads_enabled' => true,
            'site.ad_grid_position' => 6,
            'site.ad_grid_max_per_feed' => 1,
            'site.ad_code_grid_channel_videos' => '<span class="probe-ad">ad</span>',
            'site.ad_grid_layout_channel_videos' => 'wide_row',
        ]);

        $html = (string) $this->getJson($channel->route . '?part=videos&sort=latest')->assertOk()->json('html');
        $this->assertSame(1, substr_count($html, 'probe-ad'));
        $this->assertStringContainsString('ad-slot-grid--wide', $html);
        $this->assertLessThan(strpos($html, 'Wide clip 7<'), strpos($html, 'probe-ad'));

        // the card grids pack densely, so the videos after the ad fill the row that holds video 6 first and the
        // full-width ad comes after that row at any column count (QA: empty cells before it at 4 columns); the channel
        // page's own rules are its own stylesheet since 1.2.3 round 3 (R1-070 = R2R-10)
        $scss = (string) file_get_contents(resource_path('sass/member/_channel-page.scss'));
        $rule = substr($scss, (int) strpos($scss, ':root .mk-cards--cols {'), 600);
        $this->assertStringContainsString('grid-auto-flow: row dense;', $rule);
    }

    public function test_numbers_use_western_digits_in_every_language(): void
    {
        foreach (['ar', 'fr', 'de', 'hi'] as $locale) {
            app()->setLocale($locale);
            // QA: Arabic printed Arabic-Indic digits next to Western ones on the same channel page
            $formatted = LocalNumber::format(6552004);
            $this->assertSame('6552004', preg_replace('/[^0-9]/', '', $formatted), $locale . ': ' . $formatted);
            // no Arabic-Indic, Persian or Devanagari digits
            $this->assertDoesNotMatchRegularExpression('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}\x{0966}-\x{096F}]/u', $formatted, $locale);
        }
        app()->setLocale('en');
        $this->assertSame('6,552,004', LocalNumber::format(6552004));
    }

    public function test_discover_leaves_out_channels_that_hide_their_count_before_its_limit(): void
    {
        // a creator who turns "Show how many people subscribe" off is not listed in Discover (the Channel section of
        // Account settings says so); 1.2.3 has no separate Discover switch (no page could store it)
        $this->assertArrayNotHasKey('show_in_discover', ChannelCustomizationSettings::userDefaults());
        $this->assertArrayNotHasKey('show_in_discover', ChannelCustomizationSettings::merge(['show_in_discover' => '0']));
        $fan = User::factory()->create();
        foreach (['opted_one' => 3, 'opted_two' => 2, 'listed_one' => 1, 'listed_two' => 0] as $name => $followers) {
            $creator = $this->channel(['username' => $name, 'show_subscribers' => ! str_starts_with($name, 'opted')]);
            $this->video($creator);
            foreach (range(1, $followers) as $n) {
                if ($followers > 0) {
                    DB::table('subscriptions')->insert(['user_id' => $creator->id, 'subscriber_id' => User::factory()->create()->id, 'subscribe_at' => now(), 'read_at' => now()]);
                }
            }
        }

        // two places: the two most followed hide their count, so both other channels fill them (the filter runs before
        // the limit: the list never comes out short)
        config(['site.discover_users_limit' => 2]);
        $this->actingAs($fan)->get(route('subscription.discover'))
            ->assertOk()
            ->assertSee('listed_one')
            ->assertSee('listed_two')
            ->assertDontSee('opted_one')
            ->assertDontSee('opted_two');
    }

    public function test_the_tabs_load_in_the_pages_language(): void
    {
        $channel = $this->channel(['username' => 'langue_chaine', 'slug' => 'LangueChaine']);
        $this->video($channel);

        // public/index.php takes the language off the address (/fr/@...) and hands it to the Language middleware
        config(['site.default_locale' => 'en']);
        $_SERVER['DETECTED_LOCALE'] = 'fr';
        try {
            $html = $this->get((string) parse_url($channel->route, PHP_URL_PATH))->assertOk()->getContent();
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
            app()->setLocale('en');
        }
        // QA: the fragments came from the address without its language, so they followed the session's last language
        preg_match('/data-part-url="([^"]*)"/', $html, $partUrl);
        $this->assertStringStartsWith('/fr/@', $partUrl[1] ?? '', 'data-part-url=' . ($partUrl[1] ?? '(none)'));
    }

    // ------------------------------------------------------------------------------------------- more review fixes

    public function test_every_copy_button_shows_its_copied_status_where_the_script_looks_for_it(): void
    {
        // QA channel-8: on the About tab "Copy channel address" copied, but its "Address copied" status
        // (role=status) never showed: the script looked in the nearest details list, and the status follows that list.
        // The script's own lookup is read from its source (the button's own row first when the script says so, then
        // the nearest of its class list), so the markup and the script cannot drift apart again.
        // the actions' script: one cached file since R1-069 (library/actions-script prints it)
        $script = (string) file_get_contents(resource_path('js/member/library-pages.js'));
        $ownRow = str_contains($script, "copyButton.parentElement?.querySelector('[data-copy-done]')");
        $this->assertSame(1, preg_match("/copyButton\\.closest\\('([^']+)'\\)\\?\\.querySelector\\('\\[data-copy-done\\]'\\)/", $script, $lookup), 'the copy handler and its status lookup');
        $scopes = array_map(function (string $selector): string {
            $selector = trim($selector);
            $this->assertMatchesRegularExpression('/^\.[A-Za-z0-9_-]+$/', $selector, 'the lookup is a list of plain class selectors');

            return substr($selector, 1);
        }, explode(',', $lookup[1]));
        // ... and a screen reader reads it out: the status is shown empty and its words go in after
        // that, because a live region announces what is added to it, while one that only stops being hidden is not
        // read by every screen reader
        $this->assertSame(1, preg_match('/const show = \(\) => \{(?<body>.*?)\n\s*\};/s', $script, $show), 'the copy status');
        $this->assertMatchesRegularExpression("/done\\.textContent = '';\\s*done\\.hidden = false;\\s*setTimeout\\(\\(\\) => \\{ done\\.textContent = words; \\}/", $show['body']);

        $channel = $this->channel(['username' => 'copy_me', 'slug' => 'CopyMe', 'profile_uid' => null]);
        $this->video($channel);
        $page = $this->actingAs(User::factory()->create())->get($channel->route)->assertOk()->getContent();
        $about = (string) $this->getJson($channel->route . '?part=about')->assertOk()->json('html');

        foreach (['the share dialog of the band' => $page, 'the About tab' => $about] as $where => $html) {
            $found = $this->copyButtonScopes($html, $scopes, $ownRow);
            $this->assertNotEmpty($found, $where . ': no copy button');
            foreach ($found as $button => $hasStatus) {
                $this->assertTrue($hasStatus, $where . ': the status of "' . $button . '" is not inside the element the script searches');
            }
        }
        // where the member looks: the About tab's status is in the address row, with the address (read left to right on a
        // right-to-left page: R2A-064) and its button
        $this->assertMatchesRegularExpression('#<dd class="mk-channel__address"><span class="mk-copy__value" dir="ltr">[^<]*</span><button[^>]*data-copy-text="[^"]*"[^>]*>.*?</button><p class="mk-help mk-copy__done" role="status" data-copy-done hidden>Address copied</p></dd>#s', $about);
    }

    public function test_the_channel_first_screen_stays_within_its_content_budget_for_guests_and_subscribers(): void
    {
        // member design 4.3: a channel's first screen, the page root inside #main-container, is at most
        // 16 KB raw, 3 KB gzip -9 and 250 element nodes, measured on the root as the server sends it.
        // A channel shaped like the one of the QA run: a two-line tagline, the creator's 42-character button, a website, 3
        // featured and 3 latest videos and 6 of its 8 playlists on Home; seen by a guest and by a subscribed member.
        $channel = $this->channel([
            'username' => 'nightdrive_tv',
            'slug' => 'NightdriveTV',
            'profile_uid' => null,
            'website' => 'nightdrive.example',
            'channel_customization' => [
                'tagline' => 'Calm drives through cities at night. New video every Friday.',
                'cta_label' => 'Buy my night-drive LUT pack and presets no',
                'cta_url' => 'https://shop.nightdrive.example/luts',
                'show_subscribe_cta' => '1',
            ],
        ]);
        $titles = ['Neon alley walk', 'Golden hour drive along the coast', 'Rooftop session: acoustic covers', 'Drone flight over the pine valley', 'City skyline hyperlapse', 'Sunset beach bonfire session'];
        $videos = [];
        foreach ($titles as $n => $title) {
            $videos[] = $this->video($channel, [
                'title' => $title,
                'slug' => \Illuminate\Support\Str::slug($title),
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'poster_url' => '/images/tr/thumb_' . str_pad((string) (10 + $n), 2, '0', STR_PAD_LEFT) . '.jpg',
                'views' => [2934, 505, 398112, 396004, 1630, 354][$n],
                'duration' => 600 + 300 * $n,
                'published_at' => now()->subWeeks(3 + 3 * $n),
            ]);
        }
        foreach (['Coast drives', 'Night rain sounds', 'QA big list', 'City lights', 'Mountain passes', 'Ferries and bridges', 'Tunnels', 'Harbour nights'] as $n => $title) {
            $playlist = Playlist::factory()->for($channel)->create(['title' => $title, 'status' => PlaylistStatus::PUBLIC, 'updated_at' => now()->subDays(6 + 9 * $n)]);
            $playlist->videos()->attach($videos[$n % 6]->id, ['position' => 1]);
        }
        $fan = User::factory()->create();
        DB::table('subscriptions')->insert(['user_id' => $channel->id, 'subscriber_id' => $fan->id, 'subscribe_at' => now(), 'read_at' => now()]);

        $budgets = [];
        foreach (['a guest' => null, 'a subscribed member' => $fan] as $who => $viewer) {
            $response = $viewer ? $this->actingAs($viewer)->get($channel->route) : $this->get($channel->route);
            [$raw, $gzip, $nodes] = $this->pageRootBudget($response->assertOk()->getContent());
            $budgets[$who] = sprintf('%s: %.2f KB / %.2f KB gzip / %d nodes', $who, $raw / 1024, $gzip / 1024, $nodes);
            $this->assertStringContainsString('Neon alley walk', $response->getContent(), $who);
            $this->assertLessThanOrEqual(16 * 1024, $raw, $budgets[$who]);
            $this->assertLessThanOrEqual(3 * 1024, $gzip, $budgets[$who]);
            $this->assertLessThanOrEqual(250, $nodes, $budgets[$who]);
        }
        fwrite(STDERR, PHP_EOL . implode(PHP_EOL, $budgets) . PHP_EOL);
    }

    public function test_the_skeletons_the_generic_error_and_the_tab_states_live_outside_the_page_root(): void
    {
        // 4.3: the tabs' skeleton cards, the generic error sentence and the Alpine bindings of every tab and
        // sort button were printed inside the page root; the script now draws them from one template after the root
        $channel = $this->channel();
        $this->video($channel);
        $html = $this->get($channel->route)->assertOk()->getContent();
        [$root] = $this->pageRoot($html);

        $this->assertStringNotContainsString('x-for=', $root);
        $this->assertStringNotContainsString('data-message-error', $root);
        $this->assertStringNotContainsString(":aria-current=", $root);
        $this->assertStringNotContainsString(":aria-pressed=", $root);
        $this->assertStringContainsString('data-skel="1"', $root);
        $this->assertStringContainsString('<a class="mk-tab is-active" href="#home" aria-controls="channelHome" aria-current="page">', $root);
        $this->assertStringContainsString('data-sort="popular"', $root);
        // the one template the skeletons come from, and the generic sentence, after the root
        $this->assertMatchesRegularExpression('/<template id="mkChannelSkel">.*mk-card--skel/s', $html);
        $this->assertStringContainsString('data-message-error="' . e(__('That did not work. Check your connection and try again.')) . '"', $html);
        // no line breaks and runs of spaces between a tag's attributes
        $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\n\s+[a-z:@-]+=/', $root);
    }

    public function test_a_guests_subscribe_prompt_words_are_printed_once_outside_the_page_root(): void
    {
        // 4.3: the sign-in prompt's title and message of a guest's Subscribe were attributes of every button,
        // inside the page root; one holder after the root now carries them (the button names it)
        $channel = $this->channel(['username' => 'prompt_me']);
        $this->video($channel);
        $html = $this->get($channel->route)->assertOk()->getContent();
        [$root] = $this->pageRoot($html);

        $this->assertStringContainsString('data-auth-prompt="mkAuthSubscribe"', $root);
        $this->assertStringNotContainsString('data-auth-title', $root);
        $this->assertSame(1, substr_count($html, '<template id="mkAuthSubscribe" data-auth-icon="fa-solid fa-bell" data-auth-title="Sign in to subscribe" data-auth-message="Subscribe to see the new videos of this channel in your feed."></template>'));
        // the script (one cached file since R1-069) reads the words from the holder the button names
        $this->assertStringContainsString("document.getElementById(prompt.getAttribute('data-auth-prompt') || '') || prompt", (string) file_get_contents(resource_path('js/member/library-pages.js')));

        // Discover: one holder for all its guest buttons
        foreach (['second_channel', 'third_channel'] as $name) {
            $this->video($this->channel(['username' => $name]));
        }
        $discover = $this->get(route('subscription.discover'))->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(3, substr_count($discover, 'data-auth-prompt="mkAuthSubscribe"'));
        $this->assertSame(1, substr_count($discover, '<template id="mkAuthSubscribe"'));
    }

    public function test_the_videos_tab_uses_the_admins_videos_per_row(): void
    {
        // member design 6.0 (grid_columns, "the site's grid settings"): the Videos tab's grid and its
        // skeleton take the admin's "Videos per row" (the layout's --avd-grid-columns, 2-6), as 1.2.2's Videos tab used
        // the site's grid; it was the creator's Latest uploads columns (3), so 3 videos a row where the mockup has 4
        $channel = $this->channel();
        $this->video($channel);
        $html = $this->get($channel->route)->assertOk()->getContent();
        [$root] = $this->pageRoot($html);

        $this->assertSame(2, substr_count($root, 'style="--mk-cols: var(--avd-grid-columns, 4)"'));
        $this->assertStringContainsString('style="--mk-cols: var(--avd-grid-columns, 4)" data-part-list="videos"', $root);
        $this->assertMatchesRegularExpression('/:root \{\s*--avd-grid-columns: [2-6];/', $html);
    }

    /**
     * Each copy button of $html => whether the script finds a [data-copy-done] status for it: in the button's own row
     * (its parent, when $ownRow) or else in the nearest ancestor with one of the $scopes classes.
     *
     * @param  list<string>  $scopes
     * @return array<string, bool>
     */
    private function copyButtonScopes(string $html, array $scopes, bool $ownRow = false): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);
        $found = [];
        foreach ($xpath->query('//*[@data-copy-text]') as $button) {
            $scope = null;
            for ($node = $button->parentNode; $node instanceof \DOMElement; $node = $node->parentNode) {
                if (array_intersect(preg_split('/\s+/', trim($node->getAttribute('class'))), $scopes) !== []) {
                    $scope = $node;
                    break;
                }
            }
            $inRow = $ownRow && $button->parentNode instanceof \DOMElement && $xpath->query('.//*[@data-copy-done]', $button->parentNode)->length > 0;
            $found[$button->getAttribute('aria-label') . ' ' . $button->getAttribute('data-copy-text')] = $inRow || ($scope !== null && $xpath->query('.//*[@data-copy-done]', $scope)->length > 0);
        }

        return $found;
    }

    /**
     * The content budget of member design 4.3 as it is measured: the element whose class has
     * "mk-page", from its start tag to its end tag as the server sent them: [raw bytes, gzip -9 bytes, element nodes].
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function pageRootBudget(string $html): array
    {
        [$root, $nodes] = $this->pageRoot($html);

        return [strlen($root), strlen((string) gzencode($root, 9)), $nodes];
    }

    /**
     * The page root as sent (the element whose class has "mk-page", start tag to end tag) and its element nodes.
     *
     * @return array{0: string, 1: int}
     */
    private function pageRoot(string $html): array
    {
        $this->assertSame(1, preg_match('/<div class="[^"]*\bmk-page\b[^"]*"/', $html, $match, PREG_OFFSET_CAPTURE), 'the page root');
        $start = $match[0][1];
        $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
        preg_match_all('/<(\/?)([a-zA-Z][a-zA-Z0-9-]*)(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $start);
        $depth = 0;
        $nodes = 0;
        $end = null;
        foreach ($tags as $tag) {
            $name = strtolower($tag[2][0]);
            if ($tag[1][0] === '/') {
                if (in_array($name, $void, true)) {
                    continue;
                }
                $depth--;
                if ($depth === 0) {
                    $end = $tag[0][1] + strlen($tag[0][0]);
                    break;
                }
                continue;
            }
            $nodes++;
            if (! in_array($name, $void, true) && ! str_ends_with($tag[0][0], '/>')) {
                $depth++;
            }
        }
        $this->assertNotNull($end, 'the end of the page root');

        return [substr($html, $start, $end - $start), $nodes];
    }

    private function section(string $html, string $titleId): string
    {
        $start = (int) strpos($html, 'id="' . $titleId . '"');
        $end = strpos($html, '</section>', $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    private function channel(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['show_subscribers' => true]);
    }

    private function video(User $channel, array $attributes = [], VideoStatus $status = VideoStatus::PUBLIC): Video
    {
        $at = $attributes['published_at'] ?? now()->subDay();

        return Video::factory()->for($channel)->withStatus($status)->create($attributes + [
            'published_at' => $at,
            'uploaded_at' => $at,
        ]);
    }
}
