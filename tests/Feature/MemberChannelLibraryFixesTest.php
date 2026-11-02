<?php

namespace Tests\Feature;

use App\Enums\CustomPlaylistType;
use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Support\ChannelCustomizationSettings;
use App\Support\SettingsRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Round 1 fixes of the channel, library and playlist pages (judge items R1-033, R1-038, R1-039, R1-041, R1-042, R1-067,
 * R1-069, R1-124, R1-129). Each test fails on the code of release candidate 1.
 */
class MemberChannelLibraryFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['site.channel_customization_enabled' => true, 'site.ads_enabled' => false, 'site.show_history' => true]);
    }

    // ------------------------------------------------------------------------------------------- R1-041

    public function test_the_canonical_redirect_keeps_the_pages_language_and_query(): void
    {
        $channel = $this->channel(['username' => 'nightdrive_tv', 'slug' => 'NightdriveTV']);
        config(['site.default_locale' => 'en', 'site.profile_uid_urls_enabled' => false]);

        // public/index.php takes the language off the address (/ar/@nightdrive_tv) and hands it to the Language middleware
        $_SERVER['DETECTED_LOCALE'] = 'ar';
        try {
            $response = $this->get('/@nightdrive_tv?ref=share');
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
            app()->setLocale('en');
        }

        $response->assertStatus(301);
        $this->assertStringEndsWith('/ar/@NightdriveTV?ref=share', (string) $response->headers->get('Location'));

        // the default language keeps its address without a prefix (a new visitor: the session above chose Arabic)
        $this->flushSession();
        $this->get('/@nightdrive_tv')->assertStatus(301)->assertRedirect(url('/@NightdriveTV'));
        $this->get($channel->route)->assertOk();
    }

    // ------------------------------------------------------------------------------------------- R1-033

    public function test_watch_later_shows_why_a_view_only_demo_account_could_not_remove_watched_videos(): void
    {
        $demo = User::factory()->create(['is_view_only' => true]);
        $page = route('custom-playlist.show', CustomPlaylistType::WATCH_LATER);
        $this->actingAs($demo)->get($page)->assertOk();

        $this->actingAs($demo)->from($page)->post(route('custom-playlist.clear-watched-videos'))->assertRedirect($page);

        $this->actingAs($demo)->get($page)->assertOk()
            ->assertSee('data-view-only-refusal', false)
            ->assertSee(__('Not saved'));
    }

    public function test_the_library_pages_print_the_view_only_refusal(): void
    {
        $demo = User::factory()->create(['is_view_only' => true]);

        foreach ([route('pages.history'), route('pages.library'), route('playlist.manage'), route('subscription.manage')] as $page) {
            $this->actingAs($demo)->from($page)->post(route('custom-playlist.clear-watched-videos'))->assertRedirect($page);
            $this->actingAs($demo)->get($page)->assertOk()->assertSee('data-view-only-refusal', false);
        }
    }

    // ------------------------------------------------------------------------------------------- R1-038

    public function test_the_old_default_coral_of_a_channel_saved_before_1_2_3_follows_the_sites_default(): void
    {
        config(['site.channel_default_accent' => 'look']);
        // 1.2.2 stored every field of the channel form with each save, the default "coral" too
        $old = $this->channel(['channel_customization' => array_merge(ChannelCustomizationSettings::userDefaults(), ['accent' => 'coral', 'tagline' => 'Saved under 1.2.2'])]);
        $this->assertSame('coral', $old->channel_customization['accent']);

        $html = $this->get($old->route)->assertOk()->getContent();
        $this->assertStringContainsString('data-channel-accent="look"', $html);
        $this->assertStringNotContainsString('--mk-accent-dark', $html);
        $this->assertSame('coral', $old->refresh()->channel_customization['accent'], 'nothing stored is rewritten');

        // the creator's form shows what the channel uses; it sends no mark of a pick (R2A-017: a save picks an accent by
        // changing it, App\Support\ChannelCustomizationSettings::chosenDefaults())
        $colours = $this->actingAs($old)->get(route('user.edit', ['section' => 'channel', 'group' => 'colours']), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/value="look"[^>]*checked/', $colours);
        $this->assertDoesNotMatchRegularExpression('/value="coral"[^>]*checked/', $colours);
        $this->assertStringNotContainsString('name="channel_customization[accent_chosen]"', $colours);

        // another accent stored under 1.2.2 is a choice and stays
        $cyan = $this->channel(['channel_customization' => array_merge(ChannelCustomizationSettings::userDefaults(), ['accent' => 'cyan'])]);
        $this->assertStringContainsString('data-channel-accent="cyan"', $this->get($cyan->route)->getContent());
        // an owner's own default accent (coral chosen in the admin) still applies to channels without a pick
        config(['site.channel_default_accent' => 'coral']);
        $this->assertSame('coral', $old->refresh()->channelCustomization()['accent']);
    }

    public function test_coral_picked_in_the_channel_form_since_1_2_3_is_kept(): void
    {
        config(['site.channel_default_accent' => 'look']);
        $creator = $this->channel(['channel_customization' => array_merge(ChannelCustomizationSettings::userDefaults(), ['accent' => 'coral'])]);

        $this->actingAs($creator)->from(route('user.edit', ['section' => 'channel']))
            ->put(route('user.update'), ['section' => 'channel', 'channel_customization' => ['accent' => 'coral', 'accent_chosen' => '1']])
            ->assertRedirect();

        $stored = $creator->refresh()->channel_customization;
        $this->assertSame('coral', $stored['accent']);
        $this->assertSame('1', $stored[ChannelCustomizationSettings::ACCENT_CHOSEN]);
        $this->assertStringContainsString('data-channel-accent="coral"', $this->get($creator->route)->getContent());

        // a later save of another group keeps the pick
        $this->actingAs($creator)->from(route('user.edit', ['section' => 'channel']))
            ->put(route('user.update'), ['section' => 'channel', 'channel_customization' => ['motion' => 'none']])
            ->assertRedirect();
        $this->assertSame('coral', $creator->refresh()->channelCustomization()['accent']);
    }

    // ------------------------------------------------------------------------------------------- R1-039

    public function test_the_admin_shows_the_default_accent_channels_use_and_keeps_an_owners_coral(): void
    {
        $this->seedInstallerAccent();
        $admin = User::factory()->admin()->create();

        // the new admin, Appearance > Channels
        config(['admin.ui' => 'v2']);
        $select = $this->accentSelect($this->actingAs($admin)->get(route('admin.appearance.page', ['page' => 'channels']))->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/<option value="look" selected/', $select, 'the untouched seed shows as the look colour');
        $this->assertDoesNotMatchRegularExpression('/<option value="coral" selected/', $select);

        // the old settings page
        config(['admin.ui' => 'v1']);
        $html = $this->actingAs($admin)->get(route('admin.settings.index', ['tab' => 'users']))->assertOk()->getContent();
        $old = $this->between($html, 'id="channel_default_accent"', '</select>');
        $this->assertMatchesRegularExpression('/<option value="look"\s+selected/', $old);

        // an owner who saved Coral keeps seeing (and getting) Coral
        SettingsRevision::record('settings_save', 'users', ['users' => ['channel_default_accent' => 'coral']], ['users' => ['channel_default_accent' => 'coral']]);
        Cache::flush();
        config(['admin.ui' => 'v2']);
        $select = $this->accentSelect($this->actingAs($admin)->get(route('admin.appearance.page', ['page' => 'channels']))->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/<option value="coral" selected/', $select);
    }

    public function test_pages_saved_without_touching_the_default_accent_keep_channels_on_the_look_colour(): void
    {
        $this->seedInstallerAccent();
        $admin = User::factory()->admin()->create();

        // Appearance > Channels posted whole (as without JavaScript): every field as the page shows it
        config(['admin.ui' => 'v2']);
        [$fields, $groups] = $this->appearanceForm($this->actingAs($admin)->get(route('admin.appearance.page', ['page' => 'channels']))->assertOk()->getContent());
        $this->assertSame('look', $groups['users']['channel_default_accent'] ?? null);
        $this->actingAs($admin)->post(route('admin.appearance.publish'), ['fields' => $fields, 'groups' => $groups])->assertRedirect();
        $this->assertSame('look', $this->effectiveDefaultAccent());

        // the old settings page's Users tab saved as shown
        config(['admin.ui' => 'v1']);
        $form = $this->formFields($this->actingAs($admin)->get(route('admin.settings.index', ['tab' => 'users']))->assertOk()->getContent(), '/update/users');
        $this->assertSame('look', $form['channel_default_accent'] ?? null);
        $this->actingAs($admin)->from(route('admin.settings.index', ['tab' => 'users']))
            ->put(route('admin.settings.update.group', 'users'), $form)->assertSessionHasNoErrors();
        $this->assertSame('look', $this->effectiveDefaultAccent());
    }

    public function test_coral_picked_in_appearance_channels_over_the_installer_seed_is_kept(): void
    {
        $this->seedInstallerAccent();
        $admin = User::factory()->admin()->create();
        config(['admin.ui' => 'v2']);

        // the page's own path: the draft, then Publish
        $this->actingAs($admin)->postJson(route('admin.appearance.draft'), ['set' => ['channel_default_accent' => 'coral'], 'page' => 'appearance.channels'])->assertOk();
        $published = $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk();
        $this->assertContains('channel_default_accent', (array) $published->json('changed'));
        $this->assertNotNull($published->json('revision'), 'History lists the change');

        Cache::flush();
        $this->assertSame('coral', $this->effectiveDefaultAccent(), 'channels without their own accent take Coral');
        $select = $this->accentSelect($this->actingAs($admin)->get(route('admin.appearance.page', ['page' => 'channels']))->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/<option value="coral" selected/', $select);
        $revision = DB::table('admin_setting_revisions')->where('id', $published->json('revision'))->first(['before', 'after']);
        $this->assertSame('look', json_decode((string) $revision->before, true)['users']['channel_default_accent'], 'a change from the look colour');
        $this->assertSame('coral', json_decode((string) $revision->after, true)['users']['channel_default_accent']);

        // Undo returns channels to the look colour
        $this->actingAs($admin)->postJson(route('admin.appearance.restore', $published->json('revision')))->assertOk();
        Cache::flush();
        $this->assertSame('look', $this->effectiveDefaultAccent());
    }

    public function test_coral_picked_in_appearance_channels_without_javascript_is_kept(): void
    {
        $this->seedInstallerAccent();
        $admin = User::factory()->admin()->create();
        config(['admin.ui' => 'v2']);

        // the page posted whole, Coral chosen in the select
        [$fields, $groups] = $this->appearanceForm($this->actingAs($admin)->get(route('admin.appearance.page', ['page' => 'channels']))->assertOk()->getContent());
        $groups['users']['channel_default_accent'] = 'coral';
        $this->actingAs($admin)->post(route('admin.appearance.publish'), ['fields' => $fields, 'groups' => $groups])->assertRedirect();

        Cache::flush();
        $this->assertSame('coral', $this->effectiveDefaultAccent());
        $select = $this->accentSelect($this->actingAs($admin)->get(route('admin.appearance.page', ['page' => 'channels']))->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/<option value="coral" selected/', $select);
    }

    public function test_coral_picked_on_the_old_settings_page_over_the_installer_seed_is_kept(): void
    {
        $this->seedInstallerAccent();
        $admin = User::factory()->admin()->create();
        config(['admin.ui' => 'v1']);

        $form = $this->formFields($this->actingAs($admin)->get(route('admin.settings.index', ['tab' => 'users']))->assertOk()->getContent(), '/update/users');
        $form['channel_default_accent'] = 'coral';
        $this->actingAs($admin)->from(route('admin.settings.index', ['tab' => 'users']))
            ->put(route('admin.settings.update.group', 'users'), $form)->assertSessionHasNoErrors();

        Cache::flush();
        $this->assertSame('coral', $this->effectiveDefaultAccent(), 'channels without their own accent take Coral');
        $html = $this->actingAs($admin)->get(route('admin.settings.index', ['tab' => 'users']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="coral"\s+selected/', $this->between($html, 'id="channel_default_accent"', '</select>'));
        // a later save of the tab as shown keeps it
        $again = $this->formFields($html, '/update/users');
        $this->actingAs($admin)->from(route('admin.settings.index', ['tab' => 'users']))
            ->put(route('admin.settings.update.group', 'users'), $again)->assertSessionHasNoErrors();
        Cache::flush();
        $this->assertSame('coral', $this->effectiveDefaultAccent());
    }

    // ------------------------------------------------------------------------------------------- R1-042, R1-124

    public function test_the_playlist_video_sizes_that_change_nothing_are_no_longer_offered(): void
    {
        $creator = $this->channel(['channel_customization' => ChannelCustomizationSettings::merge(['playlist_video_columns' => '2'])]);
        $playlist = Playlist::factory()->for($creator)->create(['status' => PlaylistStatus::PUBLIC]);
        $playlist->videos()->attach($this->video($creator)->id, ['position' => 1, 'added_at' => now()]);

        $sizes = $this->actingAs($creator)->get(route('user.edit', ['section' => 'channel', 'group' => 'sizes']), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
        $this->assertStringContainsString('channel_customization[playlists_columns]', $sizes);
        $this->assertStringNotContainsString('channel_customization[playlist_video_columns]', $sizes);
        $this->assertStringNotContainsString('channel_customization[playlist_video_rows]', $sizes);
        $this->assertStringNotContainsString('data-channel-playlist-video-columns', $this->get($creator->route)->assertOk()->getContent());
        $this->assertSame('2', $creator->refresh()->channel_customization['playlist_video_columns'], 'stored values stay');

        // the admin: hidden settings, found by the search with the reason
        foreach (['channel_default_playlist_video_columns', 'channel_default_playlist_video_rows'] as $key) {
            $setting = config('admin_settings.settings.' . $key);
            $this->assertSame('hidden', $setting['level']);
            $this->assertSame('Not in use: a channel page shows each playlist as one card.', $setting['hint']);
        }
        $admin = User::factory()->admin()->create();
        config(['admin.ui' => 'v2']);
        $this->actingAs($admin)->get(route('admin.appearance.page', ['page' => 'channels']))->assertOk()
            ->assertDontSee('groups[users][channel_default_playlist_video_columns]', false);
        // the old settings page keeps sending the stored values (its save of the tab requires them)
        config(['admin.ui' => 'v1']);
        $old = $this->actingAs($admin)->get(route('admin.settings.index', ['tab' => 'users']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input type="hidden" name="channel_default_playlist_video_columns" value="[1-6]">/', $old);
        $this->assertStringNotContainsString(__('Default Playlist Videos Per Row'), $old);
    }

    public function test_the_last_upload_switch_says_what_it_does(): void
    {
        $setting = config('admin_settings.settings.channel_customization_upload_rhythm_enabled');
        $this->assertSame('Creators can show "Last upload"', $setting['label']);
        $this->assertSame('Lets creators show "Last upload: today" on their channel page. Off hides it on every channel.', $setting['help']);
        $this->assertSame(['users', 'Upload Rhythm'], $setting['old'], 'the old name is still found by the search');

        $admin = User::factory()->admin()->create();
        config(['admin.ui' => 'v1']);
        $html = $this->actingAs($admin)->get(route('admin.settings.index', ['tab' => 'users']))->assertOk()->getContent();
        $switch = $this->between($html, 'name="channel_customization_upload_rhythm_enabled"', '</label>');
        $this->assertStringContainsString(__('Last upload'), $switch);
        $this->assertStringNotContainsString('Upload Rhythm', $switch);
    }

    // ------------------------------------------------------------------------------------------- R1-067

    public function test_first_screen_thumbnails_load_at_once_and_later_lists_stay_lazy(): void
    {
        $channel = $this->channel(['channel_customization' => ChannelCustomizationSettings::merge(['featured_columns' => '3', 'featured_rows' => '2', 'featured_mode' => 'latest'])]);
        $videos = collect(range(1, 9))->map(fn (int $n) => $this->video($channel, ['published_at' => now()->subDays($n)]));

        $html = $this->get($channel->route)->assertOk()->getContent();
        $featured = $this->between($html, 'avd-channel-featured-grid', '</section>');
        $images = $this->images($featured);
        $this->assertCount(6, $images);
        $this->assertStringContainsString('fetchpriority="high"', $images[0], 'no banner of its own: the first card is the largest picture');
        foreach (array_slice($images, 0, 4) as $image) {
            $this->assertStringNotContainsString('loading="lazy"', $image);
        }
        foreach (array_slice($images, 4) as $image) {
            $this->assertStringContainsString('loading="lazy"', $image);
        }
        foreach ($this->images($this->between($html, 'avd-channel-latest', '</section>')) as $image) {
            $this->assertStringContainsString('loading="lazy"', $image, 'the second section is below the first screen');
        }
        // from 576px the first row (3 columns) is pictures of one size: the 2nd and 3rd load with a high priority there
        $head = substr($html, 0, (int) strpos($html, '</head>'));
        $this->assertSame(2, substr_count($head, 'fetchpriority="high" media="(min-width: 576px)"'));
        foreach ([1, 2] as $n) {
            $this->assertStringContainsString('<link rel="preload" as="image" href="' . e(\App\Support\PageUrl::local($videos[$n]->thumbnail_url)) . '" fetchpriority="high" media="(min-width: 576px)">', $head);
        }
        // a channel with a banner: the banner is the largest picture (its own high priority), no card preloads
        $bannered = $this->channel(['banner' => 'banners/test-banner.jpg', 'channel_customization' => ChannelCustomizationSettings::merge(['featured_columns' => '3', 'featured_mode' => 'latest'])]);
        collect(range(1, 3))->each(fn (int $n) => $this->video($bannered, ['published_at' => now()->subDays($n)]));
        $banneredHtml = $this->get($bannered->route)->assertOk()->getContent();
        $this->assertStringNotContainsString('media="(min-width: 576px)"', substr($banneredHtml, 0, (int) strpos($banneredHtml, '</head>')));
        $this->assertStringNotContainsString('fetchpriority="high"', implode('', $this->images($this->between($banneredHtml, 'avd-channel-featured-grid', '</section>'))));
        // the Videos tab's pages come later: lazy
        $part = $this->getJson($channel->route . '?part=videos')->assertOk()->json('html');
        $this->assertStringNotContainsString('fetchpriority', $part);
        $this->assertSame(count($this->images($part)), substr_count($part, 'loading="lazy"'));

        // History: its first rows; "Show older" (the API) lazy
        $member = User::factory()->create();
        foreach ($videos as $n => $video) {
            DB::table('views')->insert(['video_id' => $video->id, 'user_id' => $member->id, 'ip' => '127.0.0.1', 'user_agent' => 'test', 'view_at' => now()->subHours($n + 1)]);
        }
        $historyPage = $this->actingAs($member)->get(route('pages.history'))->assertOk()->getContent();
        $history = $this->images($this->between($historyPage, 'data-history-list', 'data-history-empty'));
        $this->assertStringContainsString('fetchpriority="high"', $history[0]);
        $this->assertStringNotContainsString('loading="lazy"', $history[3]);
        $this->assertStringContainsString('loading="lazy"', $history[4]);
        // from 576px rows 2-4 (pictures of one size) load with a high priority too
        $this->assertSame(3, substr_count(substr($historyPage, 0, (int) strpos($historyPage, '</head>')), 'fetchpriority="high" media="(min-width: 576px)"'));
        $api = (string) $this->actingAs($member)->getJson('/api/me/history?format=html')->assertOk()->json('html');
        $this->assertStringNotContainsString('fetchpriority', $api);

        // Watch later: its first rows; the rows added later lazy
        $this->watchLater($member, $videos);
        $laterPage = $this->actingAs($member)->get(route('custom-playlist.show', CustomPlaylistType::WATCH_LATER))->assertOk()->getContent();
        $rows = $this->images($this->between($laterPage, 'data-rows>', '</ol>'));
        $this->assertStringContainsString('fetchpriority="high"', $rows[0]);
        $this->assertStringNotContainsString('loading="lazy"', $rows[3]);
        $this->assertStringContainsString('loading="lazy"', $rows[4]);
        $this->assertSame(3, substr_count(substr($laterPage, 0, (int) strpos($laterPage, '</head>')), 'fetchpriority="high" media="(min-width: 576px)"'));

        // the Library: the first two cards of the first two shelves with cards (a phone shows about one and a half of
        // each, a wide screen loads the rest of the row right after), the first card of each with a high priority (the
        // cards are one size: the largest picture can be either shelf's); the shelves after them stay lazy
        $libraryPage = $this->actingAs($member)->get(route('pages.library'))->assertOk()->getContent();
        $historyShelf = $this->images($this->between($libraryPage, 'mkShelfHistory', 'mkShelfLater'));
        $laterShelf = $this->images($this->between($libraryPage, 'mkShelfLater', 'mkShelfLiked'));
        foreach ([$historyShelf[0], $laterShelf[0]] as $image) {
            $this->assertStringNotContainsString('loading="lazy"', $image);
            $this->assertStringContainsString('fetchpriority="high"', $image);
        }
        foreach ([$historyShelf[1], $laterShelf[1]] as $image) {
            $this->assertStringNotContainsString('loading="lazy"', $image);
            $this->assertStringNotContainsString('fetchpriority', $image);
        }
        $this->assertStringContainsString('loading="lazy"', $historyShelf[2]);
        $this->assertStringContainsString('loading="lazy"', $laterShelf[2]);
        $this->assertSame(2, substr_count($this->between($libraryPage, 'mk-library', 'mkShelfSubs'), 'fetchpriority="high"'));
        // from 576px a shelf is a row of cards of one size: each eager shelf's second picture gets a high priority there
        $libraryHead = substr($libraryPage, 0, (int) strpos($libraryPage, '</head>'));
        $this->assertSame(2, substr_count($libraryHead, 'fetchpriority="high" media="(min-width: 576px)"'));
    }

    // ------------------------------------------------------------------------------------------- R1-069

    public function test_the_page_scripts_come_from_one_cached_file_not_inline(): void
    {
        $channel = $this->channel();
        $this->video($channel);
        $member = User::factory()->create();

        // the build's file: a classic script where the inline ones were (it runs before Alpine starts), preloaded
        $this->swap(Vite::class, new class extends Vite
        {
            public function asset($asset, $buildDirectory = null)
            {
                return $asset === 'resources/js/member/library-pages.js' ? '/build/assets/member-pages-test.js' : '';
            }

            public function __invoke($entrypoints, $buildDirectory = null)
            {
                return new \Illuminate\Support\HtmlString('');
            }
        });

        foreach ([$channel->route, route('pages.history'), route('custom-playlist.show', CustomPlaylistType::WATCH_LATER)] as $page) {
            $html = $this->actingAs($member)->get($page)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, '<script src="/build/assets/member-pages-test.js"></script>'), $page);
            $this->assertSame(1, substr_count($html, '<link rel="preload" as="script" href="/build/assets/member-pages-test.js">'), $page);
            $this->assertStringNotContainsString("Alpine.data('mkChannel'", $html);
            $this->assertStringNotContainsString("Alpine.data('mkHistory'", $html);
            $this->assertStringNotContainsString('const watchLaterPage', $html);
        }

        $script = (string) file_get_contents(resource_path('js/member/library-pages.js'));
        foreach (["window.Alpine.data('mkChannel'", "window.Alpine.data('mkHistory'", 'const watchLaterPage', "document.addEventListener('alpine:init'"] as $part) {
            $this->assertStringContainsString($part, $script);
        }
        $this->assertStringContainsString("'resources/js/member/library-pages.js'", (string) file_get_contents(base_path('vite.config.ts')));
    }

    public function test_the_library_shows_six_channel_chips_like_its_other_shelves_with_short_links(): void
    {
        $member = User::factory()->create();
        $channels = User::factory()->count(9)->create();
        foreach ($channels as $n => $channel) {
            DB::table('subscriptions')->insert(['subscriber_id' => $member->id, 'user_id' => $channel->id, 'subscribe_at' => now()->subMinutes($n), 'read_at' => now()->subMinutes($n)]);
        }

        $html = $this->actingAs($member)->get(route('pages.library'))->assertOk()->getContent();
        $chips = $this->between($html, 'class="mk-chips-row"', '</div>');
        $this->assertSame(\App\Http\Controllers\LibraryController::SHELF_ITEMS, substr_count($chips, 'class="mk-chan-chip"'), 'one shelf of six, like the others');
        $this->assertStringContainsString('href="' . parse_url($channels[0]->route, PHP_URL_PATH) . '"', $chips, 'the latest first, as a path');
        $this->assertStringNotContainsString('href="http', $chips);
        $this->assertStringNotContainsString('src="http', $chips);
        $this->assertStringNotContainsString('href="' . $channels[8]->route . '"', $html);
        // the shelves' "See all" / "Manage" links are paths too
        $this->assertStringNotContainsString('href="' . url('/'), $this->between($html, 'mk-page mk-library', 'class="mk-chips-row"'));
        $this->assertStringContainsString('href="/subscriptions/manage"', $html);
    }

    public function test_watch_later_and_liked_videos_print_their_first_eight_rows_lean_and_compact(): void
    {
        $member = User::factory()->create();
        $channel = $this->channel();
        $videos = collect(range(1, 11))->map(fn (int $n) => $this->video($channel, ['title' => 'Clip number ' . $n]));
        $this->watchLater($member, $videos);
        Playlist::factory()->for($member)->create(['title' => 'Liked Videos', 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false]);
        foreach ($videos as $n => $video) {
            DB::table('interactions')->insert(['user_id' => $member->id, 'likeable_type' => Video::class, 'likeable_id' => $video->id, 'status' => 1, 'perform_at' => now()->subMinutes($n)]);
        }

        foreach ([CustomPlaylistType::WATCH_LATER, CustomPlaylistType::LIKED_VIDEOS] as $type) {
            $html = $this->actingAs($member)->get(route('custom-playlist.show', $type))->assertOk()->getContent();
            $list = $this->between($html, '<ol class="mk-lrows', '</ol>');
            $this->assertSame(\App\Http\Controllers\User\CustomPlaylistController::FIRST_PAGE, substr_count($list, 'data-playlist-row="'), $type->value . ': the first rows');
            $this->assertStringContainsString('data-next-page="2"', $html);
            // the Watch later item's two labels once, on the list; each row's Share takes its own title link
            $this->assertSame(1, substr_count($html, 'data-label-on="' . e(__('Remove from Watch later')) . '"'));
            $this->assertSame(1, substr_count($html, 'data-label-off="' . e(__('Save to Watch later')) . '"'));
            $this->assertStringNotContainsString('data-share-url=', $list);
            $this->assertSame(\App\Http\Controllers\User\CustomPlaylistController::FIRST_PAGE, substr_count($list, 'data-share-row>'));
            // the page root without line breaks between its tags (it renders the same)
            $this->assertDoesNotMatchRegularExpression('/>\s*\n\s*</', $this->between($html, 'class="avd-mk mk-page mk-page--narrow mk-playlist"', '</ol>'));
            // "Show more": the rest after the rows on screen
            $part = (string) $this->actingAs($member)->getJson(route('custom-playlist.show', $type) . '?part=rows&page=2&shown=8')->assertOk()->json('html');
            $this->assertSame(3, substr_count($part, 'data-playlist-row="'));
        }
    }

    public function test_the_list_ad_keeps_its_place_now_that_watch_later_prints_its_first_rows(): void
    {
        config(['site.ads_enabled' => true, 'site.ad_code_grid_playlist_videos' => '<div class="test-list-ad">Ad</div>']);
        $member = User::factory()->create();
        $channel = $this->channel();
        $videos = collect(range(1, 20))->map(fn (int $n) => $this->video($channel, ['title' => 'Row ' . $n]));
        $this->watchLater($member, $videos);
        $url = route('custom-playlist.show', CustomPlaylistType::WATCH_LATER);
        $after = function (string $html, int $row): bool {
            $ad = strpos($html, 'test-list-ad');

            return $ad !== false && strpos($html, 'data-playlist-row="' . $row . '"') < $ad;
        };

        // after the 6th row (the default): on the page, as before; the next rows carry none
        config(['site.ad_grid_position' => '6']);
        $list = $this->between($this->actingAs($member)->get($url)->assertOk()->getContent(), '<ol class="mk-lrows', '</ol>');
        $this->assertSame(1, substr_count($list, 'test-list-ad'));
        $this->assertTrue($after($list, $videos[5]->id) && ! $after($list, $videos[6]->id), 'between the 6th and the 7th row');
        $this->assertStringNotContainsString('test-list-ad', (string) $this->actingAs($member)->getJson($url . '?part=rows&page=2&shown=8&ad=shown')->json('html'));

        // after the 8th row, the last the page prints: on the page, since more rows follow in the list
        config(['site.ad_grid_position' => '8']);
        $list = $this->between($this->actingAs($member)->get($url)->assertOk()->getContent(), '<ol class="mk-lrows', '</ol>');
        $this->assertSame(1, substr_count($list, 'test-list-ad'));
        $this->assertTrue($after($list, $videos[7]->id));

        // after the 12th row: in the rows "Show more" brings, after the list's 12th row; never twice
        config(['site.ad_grid_position' => '12']);
        $this->assertStringNotContainsString('test-list-ad', $this->between($this->actingAs($member)->get($url)->assertOk()->getContent(), '<ol class="mk-lrows', '</ol>'));
        $part = (string) $this->actingAs($member)->getJson($url . '?part=rows&page=2&shown=8')->assertOk()->json('html');
        $this->assertSame(1, substr_count($part, 'test-list-ad'));
        $this->assertTrue($after($part, $videos[11]->id) && ! $after($part, $videos[12]->id), 'between the 12th and the 13th row of the list');
        $this->assertStringNotContainsString('test-list-ad', (string) $this->actingAs($member)->getJson($url . '?part=rows&page=2&shown=8&ad=shown')->json('html'));
    }

    public function test_a_playlist_page_prints_its_first_eight_rows_and_keeps_the_list_ad_in_place(): void
    {
        config(['site.ads_enabled' => true, 'site.ad_code_grid_playlist_videos' => '<div class="test-list-ad">Ad</div>', 'site.ad_grid_position' => '12']);
        $channel = $this->channel();
        // the playlist's own order (the factory picks a random sort, which would order the rows by views or dates)
        $playlist = Playlist::factory()->for($channel)->create(['status' => PlaylistStatus::PUBLIC, 'sort' => \App\Enums\PlaylistSort::MANUAL]);
        $videos = collect(range(1, 20))->map(fn (int $n) => $this->video($channel, ['title' => 'Row ' . $n]));
        foreach ($videos as $n => $video) {
            $playlist->videos()->attach($video->id, ['position' => $n + 1]);
        }
        $member = User::factory()->create();

        $html = $this->actingAs($member)->get($playlist->route)->assertOk()->getContent();
        $list = $this->between($html, '<ol class="mk-lrows', '</ol>');
        $this->assertSame(\App\Http\Controllers\PlaylistController::FIRST_PAGE, substr_count($list, 'data-playlist-row="'));
        $this->assertStringNotContainsString('test-list-ad', $list, 'the ad belongs after the 12th row');
        $this->assertSame(1, substr_count($html, 'data-label-on="' . e(__('Remove from Watch later')) . '"'));

        $part = (string) $this->actingAs($member)->getJson($playlist->route . '?part=rows&page=2&shown=8')->assertOk()->json('html');
        $this->assertSame(12, substr_count($part, 'data-playlist-row="'));
        $ad = strpos($part, 'test-list-ad');
        $this->assertNotFalse($ad);
        $this->assertTrue(strpos($part, 'data-playlist-row="' . $videos[11]->id . '"') < $ad && strpos($part, 'data-playlist-row="' . $videos[12]->id . '"') > $ad);
        $this->assertStringNotContainsString('test-list-ad', (string) $this->actingAs($member)->getJson($playlist->route . '?part=rows&page=2&shown=8&ad=shown')->json('html'));
    }

    public function test_history_prints_its_page_root_compact_with_short_addresses(): void
    {
        $member = User::factory()->create();
        $channel = $this->channel();
        foreach (range(1, 3) as $n) {
            DB::table('views')->insert(['video_id' => $this->video($channel)->id, 'user_id' => $member->id, 'ip' => '127.0.0.1', 'user_agent' => 'test', 'view_at' => now()->subHours($n * 9)]);
        }

        $html = $this->actingAs($member)->get(route('pages.history'))->assertOk()->getContent();
        $root = $this->pageRoot($html);
        $this->assertDoesNotMatchRegularExpression('/>\s*\n\s*</', $root, 'no line breaks between tags');
        $this->assertStringContainsString('data-api="/api/me/history"', $root);
        $this->assertStringContainsString('action="/history"', $root);
        // a row's Remove takes its row's id (printed once per row); the menu that holds it is printed once, as the page's
        // template, and copied into a row's menu when its button opens it (round 3, R1-069: its words were in every row)
        $this->assertSame(3, preg_match_all('/data-history-row="\d+"/', $root));
        $this->assertSame(1, substr_count($root, 'data-history-remove>'));
        $this->assertSame(1, substr_count($root, '<template data-history-row-menu>'));

        // round 3 (R1-069): no line breaks and runs of spaces inside a tag, no attribute the page does not need (an empty
        // search, no older rows), no copy of the layout's "That did not work" sentence (the script reads the layout's),
        // the duration drawn from the thumbnail's data-dur (as the Library's cards); the confirmation stays in the page
        // (the orchestrator's ruling: nothing moved out of a page to meet a budget), with the words a day's removal takes
        $page = substr($root, 0, (int) strpos($root, '<div class="modal fade" id="mkHistoryConfirm"'));
        $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\n/', $root, 'no line break inside a tag');
        $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\s{2,}[^>]*>/', $page, "no run of spaces inside a tag (the page's own markup; the shared dialog prints as everywhere)");
        $this->assertStringNotContainsString('data-q=', $root);
        $this->assertStringNotContainsString('data-next=', $root);
        $this->assertStringNotContainsString('data-message-error', $root);
        $this->assertStringContainsString('"wlFailed":' . json_encode(__('That did not work. Check your connection and try again.'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), $html);
        $this->assertStringNotContainsString('mk-thumb__dur', $root);
        $this->assertSame(3, preg_match_all('/<span class="mk-thumb" data-dur="[^"]+"><img /', $root));
        $this->assertSame(1, preg_match('/<div class="modal fade" id="mkHistoryConfirm"[^>]*data-remove-day-text="' . preg_quote(e(__('The videos you watched that day leave your history. Your likes and playlists stay.')), '/') . '" data-remove-day-confirm="' . preg_quote(e(__('Remove')), '/') . '"/', $root));
        $this->assertSame(1, substr_count($html, 'id="mkHistoryConfirm"'));
    }

    public function test_history_library_and_watch_later_stay_within_their_page_content_budget(): void
    {
        // member design 4.3: History, Library (and Watch later, a library page alike) <= 16 KB / 3 KB gzip / 250 nodes, with
        // a full first screen of data like the QA site's (titles, channel names, short local pictures); measured in English
        // and in the languages whose words weigh the most (the judge measured History and Library over 3 KB gzip in hi,
        // ru, ja, th)
        $member = User::factory()->create();
        $names = ['nightdrive_tv', 'oceanlens', 'cabin_builders', 'street_bites', 'lofi_room', 'sandboxadmin'];
        $titles = ['Golden hour drive along the coast', 'Tokyo night walk in the rain - 4K binaural city ambience', 'Mountain sunrise timelapse', 'How we built a tiny cabin in 30 days (full build)', 'Lo-fi beats to study and relax to', 'Street food tour: 12 dishes in one night market', 'City skyline hyperlapse', 'Downtown jazz club live set', 'The quiet library - 3 hour reading room', 'Sunset beach bonfire session', 'Interview: making music on a budget', 'Sandbox playback test'];
        $channels = collect($names)->map(fn (string $name) => $this->channel(['username' => $name, 'slug' => \Illuminate\Support\Str::studly($name)]));
        $videos = collect($titles)->map(fn (string $title, int $n) => $this->video($channels[$n % 6], ['title' => $title, 'slug' => \Illuminate\Support\Str::slug($title), 'views' => 1100 + 97 * $n, 'duration' => 300 + 977 * $n, 'poster_url' => sprintf('/images/tr/thumb_%02d.jpg', $n + 1)]));
        foreach ($videos->take(10) as $n => $video) {
            DB::table('views')->insert(['video_id' => $video->id, 'user_id' => $member->id, 'ip' => '127.0.0.1', 'user_agent' => 'test', 'view_at' => now()->subHours(5 * $n)]);
            DB::table('interactions')->insert(['user_id' => $member->id, 'likeable_type' => Video::class, 'likeable_id' => $video->id, 'status' => 1, 'perform_at' => now()->subMinutes($n)]);
        }
        $this->watchLater($member, $videos);
        Playlist::factory()->for($member)->create(['title' => 'Liked Videos', 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false]);
        foreach (range(1, 6) as $n) {
            $own = Playlist::factory()->for($member)->create(['title' => 'My mix ' . $n, 'status' => PlaylistStatus::PUBLIC]);
            $own->videos()->attach($videos[$n]->id, ['position' => 1, 'added_at' => now()]);
        }
        foreach ($channels->merge(User::factory()->count(6)->create()) as $n => $channel) {
            DB::table('subscriptions')->insert(['subscriber_id' => $member->id, 'user_id' => $channel->id, 'subscribe_at' => now()->subMinutes($n), 'read_at' => now()->subMinutes($n)]);
        }

        // The orchestrator's ruling of round 3 (R1-069): the budgets hold on the ENGLISH page; another language may go over
        // them only by the extra bytes of its translated words, with the same markup as English (the same tags; the
        // markup's gzip, words taken out, within a few bytes), and by at most 10 % of the budget
        $measured = [];
        $english = [];
        foreach (['en', 'ar', 'hi', 'ru', 'th', 'ja'] as $locale) {
            foreach (['History' => route('pages.history'), 'Library' => route('pages.library'), 'Watch later' => route('custom-playlist.show', CustomPlaylistType::WATCH_LATER)] as $page => $url) {
                $_SERVER['DETECTED_LOCALE'] = $locale;
                try {
                    $html = $this->actingAs($member)->get($url)->assertOk()->getContent();
                } finally {
                    unset($_SERVER['DETECTED_LOCALE']);
                    app()->setLocale('en');
                }
                $root = $this->pageRoot($html);
                $nodes = preg_match_all('/<[a-zA-Z]/', $root);
                $gzip = strlen((string) gzencode($root, 9));
                $markup = strlen((string) gzencode($this->markupOnly($root), 9));
                $label = sprintf('%s (%s): %d B, %d B gzip (markup %d B), %d nodes', $page, $locale, strlen($root), $gzip, $markup, $nodes);
                $measured[] = $label;
                if ($locale === 'en') {
                    $english[$page] = ['nodes' => $nodes, 'markup' => $markup];
                    $this->assertLessThanOrEqual(16 * 1024, strlen($root), $label);
                    $this->assertLessThanOrEqual(3 * 1024, $gzip, $label);
                    $this->assertLessThanOrEqual(250, $nodes, $label);
                    continue;
                }
                $this->assertSame($english[$page]['nodes'], $nodes, $label . ': the same tags as English');
                $this->assertLessThanOrEqual(16, abs($markup - $english[$page]['markup']), $label . ': the same markup as English');
                $this->assertLessThanOrEqual((int) floor(3 * 1024 * 1.1), $gzip, $label . ': at most 10 % over, by its words');
            }
            $this->flushSession();
        }
        fwrite(STDERR, PHP_EOL . implode(PHP_EOL, $measured) . PHP_EOL);
    }

    /**
     * A page root with its words taken out: every text between tags and every attribute that holds words emptied, and
     * the direction a creator's text gets on a page of the other direction (App\Support\TextDirection: " dir=ltr" on a
     * Latin title of an Arabic page), which the ruling's addition of 30 Oct counts as part of the language's difference.
     */
    private function markupOnly(string $root): string
    {
        $markup = (string) preg_replace('/>[^<]+</u', '><', $root);
        $markup = (string) preg_replace('/\sdir=(?:"(?:ltr|rtl|auto)"|ltr|rtl|auto)(?=[\s>\/])/u', '', $markup);

        return (string) preg_replace('/\s(aria-label|title|alt|placeholder|data-label[a-z-]*|data-message-[a-z-]+|data-day-question|data-remove-day-[a-z]+)="[^"]*"/u', ' $1=""', $markup);
    }

    // ------------------------------------------------------------------------------------------- R1-129

    public function test_a_featured_row_of_one_row_stays_one_row(): void
    {
        $one = $this->channel(['channel_customization' => ChannelCustomizationSettings::merge(['featured_columns' => '5', 'featured_rows' => '1', 'featured_mode' => 'latest'])]);
        $two = $this->channel(['channel_customization' => ChannelCustomizationSettings::merge(['featured_columns' => '3', 'featured_rows' => '2', 'featured_mode' => 'latest'])]);
        foreach ([$one, $two] as $channel) {
            foreach (range(1, 6) as $n) {
                $this->video($channel, ['published_at' => now()->subDays($n)]);
            }
        }

        $this->assertMatchesRegularExpression('/class="[^"]*mk-cards--one-row[^"]*avd-channel-featured-grid"/', $this->get($one->route)->getContent());
        $this->assertStringNotContainsString('mk-cards--one-row', $this->get($two->route)->getContent());
    }

    // ------------------------------------------------------------------------------------------- helpers

    private function accentSelect(string $html): string
    {
        return $this->between($html, 'name="groups[users][channel_default_accent]"', '</select>');
    }

    /** The default accent as sites installed before 1.2.3 have it: the installer's "coral", never saved since. */
    private function seedInstallerAccent(): void
    {
        $seeded = now()->subMonths(6)->startOfSecond();
        DB::table('settings')->updateOrInsert(['key' => 'channel_default_accent'], ['value' => 'coral', 'group' => 'users', 'created_at' => $seeded, 'updated_at' => $seeded]);
        Cache::flush();
        $this->assertTrue(ChannelCustomizationSettings::accentIsInstallerSeed());
    }

    /** The default accent channels without their own get, as the site reads the stored value (AppServiceProvider). */
    private function effectiveDefaultAccent(): string
    {
        Cache::flush();
        $stored = (string) DB::table('settings')->where('key', 'channel_default_accent')->value('value');

        return ChannelCustomizationSettings::text(['channel_default_accent' => $stored], 'channel_default_accent');
    }

    /**
     * Appearance > Channels as a browser without JavaScript posts it: fields[group] = its keys, groups[group][key] = values.
     *
     * @return array{0: array<string, string>, 1: array<string, array<string, string>>}
     */
    private function appearanceForm(string $html): array
    {
        $fields = [];
        $groups = [];
        foreach ($this->formFields($html, '/appearance/publish') as $name => $value) {
            if (preg_match('/^groups\[([a-z_]+)\]\[([a-z0-9_]+)\]$/', $name, $m)) {
                $groups[$m[1]][$m[2]] = $value;
                $fields[$m[1]][] = $m[2];
            }
        }
        $this->assertNotSame([], $groups, 'the page has its form');

        return [array_map(fn (array $keys) => implode(',', $keys), $fields), $groups];
    }

    /**
     * The values a browser sends for the form whose action ends with $action (checked boxes only, the selected option).
     *
     * @return array<string, string>
     */
    private function formFields(string $html, string $action): array
    {
        $doc = new \DOMDocument();
        $errors = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        $xpath = new \DOMXPath($doc);
        $out = [];
        foreach ($xpath->query('//form') as $form) {
            if (! str_ends_with((string) $form->getAttribute('action'), $action)) {
                continue;
            }
            foreach ($xpath->query('.//input|.//select|.//textarea', $form) as $field) {
                $name = $field->getAttribute('name');
                if ($name === '' || $field->hasAttribute('disabled')) {
                    continue;
                }
                $tag = strtolower($field->nodeName);
                if ($tag === 'select') {
                    $chosen = null;
                    foreach ($xpath->query('.//option', $field) as $option) {
                        $chosen ??= $option->getAttribute('value');
                        if ($option->hasAttribute('selected')) {
                            $chosen = $option->getAttribute('value');
                        }
                    }
                    $out[$name] = (string) $chosen;
                } elseif ($tag === 'textarea') {
                    $out[$name] = $field->textContent;
                } else {
                    $type = strtolower($field->getAttribute('type') ?: 'text');
                    if (in_array($type, ['checkbox', 'radio'], true)) {
                        if ($field->hasAttribute('checked')) {
                            $out[$name] = $field->getAttribute('value') ?: 'on';
                        }
                    } elseif (! in_array($type, ['submit', 'button', 'file', 'image', 'reset'], true)) {
                        $out[$name] = $field->getAttribute('value');
                    }
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function images(string $html): array
    {
        preg_match_all('/<img [^>]*>/', $html, $matches);

        return array_values(array_filter($matches[0], fn (string $tag) => ! str_contains($tag, 'mk-avatar')));
    }

    /** The page root (the first element whose class has mk-page) with its own end tag, as the 4.3 budget measures it. */
    private function pageRoot(string $html): string
    {
        $this->assertSame(1, preg_match('/<(div)\b[^>]*\bclass="[^"]*\bmk-page\b[^"]*"/', $html, $match, PREG_OFFSET_CAPTURE));
        $start = (int) $match[0][1];
        $depth = 0;
        preg_match_all('/<(\/?)div\b[^>]*>/', $html, $tags, PREG_OFFSET_CAPTURE, $start);
        foreach ($tags[0] as $n => [$tag, $at]) {
            $depth += $tags[1][$n][0] === '/' ? -1 : 1;
            if ($depth === 0) {
                return substr($html, $start, $at + strlen($tag) - $start);
            }
        }
        $this->fail('the page root has no end');
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'not found: ' . $from);
        $end = strpos($html, $to, (int) $start + strlen($from));

        return substr($html, (int) $start, ($end === false ? strlen($html) : $end) - (int) $start);
    }

    /**
     * The member's Watch later as the site makes it (App\Playlists\CustomPlaylist: sorted newest added first), holding
     * these videos in the order given: video n was added n minutes before the first, so it is the list's row n + 1.
     * R3-063: the factory gave the playlist a random sort, and with "newest added first" (or "newest published first")
     * the rows the test added in the same second came in the order of the second the clock crossed while it added them:
     * the list ad's row moved now and then (1 failure in 7 runs).
     */
    private function watchLater(User $member, iterable $videos): Playlist
    {
        $later = Playlist::factory()->for($member)->create(['title' => 'Watch Later', 'status' => PlaylistStatus::PRIVATE, 'sort' => PlaylistSort::DATE_ADDED_NEWEST, 'is_deletable' => false]);
        $first = now()->startOfSecond();
        foreach (array_values(collect($videos)->all()) as $n => $video) {
            $later->videos()->attach($video->id, ['position' => $n + 1, 'added_at' => $first->copy()->subMinutes($n)]);
        }

        return $later;
    }

    private function channel(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['show_subscribers' => true]);
    }

    private function video(User $channel, array $attributes = []): Video
    {
        $at = $attributes['published_at'] ?? now()->subDay();

        return Video::factory()->for($channel)->withStatus(VideoStatus::PUBLIC)->create($attributes + ['published_at' => $at, 'uploaded_at' => $at]);
    }
}
