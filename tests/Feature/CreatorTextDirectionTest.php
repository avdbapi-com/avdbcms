<?php

namespace Tests\Feature;

use App\Enums\CustomPlaylistType;
use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Playlists\Types\LikedVideosPlaylist;
use App\Playlists\Types\WatchLaterPlaylist;
use App\Support\TextDirection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * R2A-064: on a page of the other direction (Arabic), a channel's @handle, its address and website, and the creator's
 * own words (name, tagline, description, video and playlist titles) keep their own order: "@Qa3chBand" read
 * "Qa3chBand@", a Latin tagline showed its final period first. The handle and the addresses are isolated left to right
 * in the markup.
 *
 * 1.2.3 round 3 (R3-015, R3-060): the creator's texts carry their own direction as a dir attribute (App\Support\
 * TextDirection: the one dir="auto" would give), printed only where it differs from the page's: R2A-064's unicode-bidi:
 * plaintext left the element right to left, so WebKit and Firefox drew a clamp's "…" over the first letters and
 * Chromium pushed it out of the box; the band's button label, the playlist page's description and title and the side
 * menu's playlist names were not isolated at all.
 */
class CreatorTextDirectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['site.channel_customization_enabled' => true, 'site.ads_enabled' => false, 'site.show_history' => true]);
    }

    public function test_the_handle_address_and_website_are_isolated_left_to_right(): void
    {
        $channel = User::factory()->create(['username' => 'qa3ch_band', 'slug' => 'Qa3chBand', 'show_subscribers' => true,
            'website' => 'band.example.test', 'description' => 'A channel whose name is Latin.']);
        Video::factory()->for($channel)->withStatus(VideoStatus::PUBLIC)->create(['title' => 'Is this the best night drive?',
            'published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);

        $page = $this->get($channel->route)->assertOk()->getContent();
        $this->assertStringContainsString('<span><bdi dir="ltr">@Qa3chBand</bdi></span>', $page, 'the band\'s handle');

        $about = (string) $this->getJson($channel->route.'?part=about')->assertOk()->json('html');
        $this->assertMatchesRegularExpression('#<span class="mk-copy__value" dir="ltr">[^<]*/@Qa3chBand</span>#', $about, 'the channel address');
        $this->assertMatchesRegularExpression('#<a href="https?://band\.example\.test/?"[^>]* dir="ltr">band\.example\.test</a>#', $about, 'the website');

        $member = User::factory()->create();
        DB::table('subscriptions')->insert(['user_id' => $channel->id, 'subscriber_id' => $member->id, 'subscribe_at' => now(), 'read_at' => now()]);
        $manage = $this->actingAs($member)->get(route('subscription.manage'))->assertOk()->getContent();
        $this->assertStringContainsString('<span><bdi dir="ltr">@Qa3chBand</bdi></span>', $manage, 'Manage subscriptions');
    }

    public function test_a_creators_latin_texts_carry_their_direction_on_an_arabic_page_and_nothing_on_an_english_one(): void
    {
        [$channel, $member, $playlist] = $this->latinChannel();

        $ar = $this->page($channel->route, 'ar');
        $this->assertStringContainsString('<h1 class="mk-channel__name" dir=ltr>qa3ch_curator</h1>', $ar, 'the name');
        $this->assertMatchesRegularExpression('#<p class="mk-channel__tagline[^"]*" dir=ltr>Calm sets for long evenings[^<]*</p>#', $ar, 'the tagline');
        $this->assertStringContainsString('<span dir=ltr>Get the monthly calm-set newsletter today!</span></a>', $ar, 'the band\'s button label');
        $about = $this->page($channel->route.'?part=about', 'ar', true);
        $this->assertStringContainsString('<p class="mk-prose" dir=ltr>Hand-picked sets', $about, 'the About description');

        $history = $this->page(route('pages.history'), 'ar', false, $member);
        $this->assertMatchesRegularExpression('#<a class="mk-lrow__title mk-clamp-2" href="[^"]+" dir=ltr>Tokyo night walk in the rain - 4K binaural city ambience</a>#', $history, 'a History title');
        $library = $this->page(route('pages.library'), 'ar', false, $member);
        $this->assertStringContainsString('<span class="mk-card__title" dir=ltr>Tokyo night walk in the rain - 4K binaural city ambience</span>', $library, 'a card title');
        $this->assertStringContainsString('<span class="mk-card__title" dir=ltr>Night drives and city lights</span>', $library, 'a playlist card title');
        $list = $this->page($playlist->route, 'ar', false, $member);
        $this->assertStringContainsString('<h1 class="mk-head__title" dir=ltr>Night drives and city lights</h1>', $list, 'the playlist title');
        $this->assertStringContainsString('<p class="mk-playlist__desc" dir=ltr>Every night drive on this site.</p>', $list, 'the playlist description');
        $this->assertMatchesRegularExpression('#<span class="text-sm text-nowrap overflow-hidden text-truncate" dir=ltr>Night drives and city li\.\.\.</span>#', $list, 'the side menu\'s saved playlist');
        $manage = $this->page(route('subscription.manage'), 'ar', false, $member);
        $this->assertMatchesRegularExpression('#<span class="mk-muted mk-clamp-1 mk-subs-manage__desc" dir=ltr>Hand-picked sets#', $manage, 'a Manage description');

        // the same pages in English: nothing of their own (the page's direction is the texts' one)
        foreach ([$this->page($channel->route, 'en'), $this->page(route('pages.history'), 'en', false, $member),
            $this->page(route('pages.library'), 'en', false, $member), $this->page($playlist->route, 'en', false, $member),
            $this->page(route('subscription.manage'), 'en', false, $member)] as $html) {
            $this->assertSame(0, preg_match_all('# dir=(?:ltr|rtl)[ >]#', $html), 'no direction of their own on an English page');
        }
    }

    /**
     * The other elements that print a creator's text carry its direction too (each view's attribute is asserted once,
     * so a merge that drops one fails here): Manage subscriptions' channel name, the Discover card's name and
     * description, the channel's featured video (title and description), the rows of Watch later, Liked videos and a
     * playlist page (and the rows "Show more" adds), and the side menu's second list of saved playlists. English pages:
     * nothing.
     */
    public function test_every_other_creator_text_carries_its_direction_on_an_arabic_page(): void
    {
        [$channel, $member, $playlist] = $this->latinChannel();
        // one lead video on the channel's Home: its title and description
        $channel->forceFill(['channel_customization' => array_merge((array) $channel->channel_customization, ['featured_columns' => '1', 'featured_rows' => '1'])])->save();
        $video = $playlist->videos()->firstOrFail();
        $video->forceFill(['description' => 'A calm walk through Shinjuku at night, with the rain and the last trains.'])->save();
        // Watch later and Liked videos
        $later = Playlist::factory()->for($member)->create(['title' => WatchLaterPlaylist::getName(), 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false]);
        $later->videos()->attach($video->id, ['position' => 1, 'added_at' => now()]);
        Playlist::factory()->for($member)->create(['title' => LikedVideosPlaylist::getName(), 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false]);
        DB::table('interactions')->insert(['user_id' => $member->id, 'likeable_type' => Video::class, 'likeable_id' => $video->id, 'status' => 1, 'perform_at' => now()]);
        // a second saved playlist: with one shown, it is in the side menu's second list
        $second = Playlist::factory()->for($channel)->create(['title' => 'City lights after midnight', 'status' => PlaylistStatus::PUBLIC]);
        DB::table('favorites_playlist')->insert(['user_id' => $member->id, 'playlist_id' => $second->id, 'added_at' => now()->subDay()]);
        config(['site.sidebar_playlists_limit' => 1]);

        $row = '#<a class="mk-lrow__title mk-clamp-2" href="[^"]+" dir=ltr>Tokyo night walk in the rain - 4K binaural city ambience</a>#';
        $home = $this->page($channel->route, 'ar', false, $member);
        $this->assertStringContainsString('<span class="mk-feature__title" id="mkFeatTitle" dir=ltr>Tokyo night walk in the rain - 4K binaural city ambience</span>', $home, 'the featured title');
        $this->assertStringContainsString('<span class="mk-feature__desc mk-clamp-2" dir=ltr>A calm walk through Shinjuku', $home, 'the featured description');
        $manage = $this->page(route('subscription.manage'), 'ar', false, $member);
        $this->assertMatchesRegularExpression('#<a class="mk-item__title" href="[^"]+" dir=ltr>qa3ch_curator</a>#', $manage, 'a Manage channel name');
        // Discover lists the channels a member does not follow yet (the member above follows it)
        $viewer = User::factory()->create();
        $discover = $this->page(route('subscription.discover'), 'ar', false, $viewer);
        $this->assertMatchesRegularExpression('#<a class="mk-item__title mk-chan-card__name" href="[^"]+" dir=ltr>qa3ch_curator</a>#', $discover, 'a Discover card name');
        $this->assertStringContainsString('<p class="mk-muted mk-clamp-2 mk-chan-card__desc" dir=ltr>Hand-picked sets', $discover, 'a Discover card description');
        $laterUrl = route('custom-playlist.show', CustomPlaylistType::WATCH_LATER);
        $this->assertMatchesRegularExpression($row, $this->page($laterUrl, 'ar', false, $member), 'a Watch later row');
        $this->assertMatchesRegularExpression($row, $this->page($laterUrl.'?part=rows&page=1', 'ar', true, $member), 'a Watch later row "Show more" adds');
        $this->assertMatchesRegularExpression($row, $this->page(route('custom-playlist.show', CustomPlaylistType::LIKED_VIDEOS), 'ar', false, $member), 'a Liked videos row');
        $list = $this->page($playlist->route, 'ar', false, $member);
        $this->assertMatchesRegularExpression($row, $list, 'a playlist page row');
        $this->assertMatchesRegularExpression('#<span class="text-sm text-nowrap overflow-hidden text-truncate" dir=ltr>City lights after midnig\.\.\.</span>#', $list, 'the side menu\'s second list');

        // the same pages in English (they print the same texts): nothing of their own
        foreach ([[$this->page($channel->route, 'en', false, $member), 'id="mkFeatTitle">Tokyo night walk'],
            [$this->page(route('subscription.manage'), 'en', false, $member), '>qa3ch_curator</a>'],
            [$this->page(route('subscription.discover'), 'en', false, $viewer), 'mk-chan-card__desc">Hand-picked sets'],
            [$this->page($laterUrl, 'en', false, $member), '>Tokyo night walk'],
            [$this->page($laterUrl.'?part=rows&page=1', 'en', true, $member), '>Tokyo night walk'],
            [$this->page(route('custom-playlist.show', CustomPlaylistType::LIKED_VIDEOS), 'en', false, $member), '>Tokyo night walk'],
            [$this->page($playlist->route, 'en', false, $member), 'text-truncate">City lights after midnig...']] as [$html, $text]) {
            $this->assertStringContainsString($text, $html);
            $this->assertSame(0, preg_match_all('# dir=(?:ltr|rtl)[ >]#', $html), 'no direction of their own on an English page: '.$text);
        }
    }

    public function test_an_arabic_creators_texts_carry_rtl_on_an_english_page(): void
    {
        $channel = User::factory()->create(['username' => 'قناة_الليل', 'slug' => 'Qa3chArabic', 'show_subscribers' => true,
            'description' => 'قناة هادئة لرحلات الليل.',
            'channel_customization' => ['tagline' => 'رحلات هادئة في المدينة ليلاً.', 'cta_label' => 'اشترك في النشرة!', 'cta_url' => 'https://arabic.example/news', 'show_subscribe_cta' => '1']]);
        Video::factory()->for($channel)->withStatus(VideoStatus::PUBLIC)->create(['title' => 'رحلة ليلية على الطريق الساحلي',
            'published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);

        $html = $this->page($channel->route, 'en', false, User::factory()->create());
        $this->assertStringContainsString('<h1 class="mk-channel__name" dir=rtl>قناة_الليل</h1>', $html);
        $this->assertMatchesRegularExpression('#<p class="mk-channel__tagline[^"]*" dir=rtl>رحلات#u', $html);
        $this->assertStringContainsString('<span dir=rtl>اشترك في النشرة!</span>', $html);
        $this->assertStringContainsString('<span class="mk-card__title" dir=rtl>رحلة ليلية على الطريق الساحلي</span>', $html);
        // ... and nothing on the Arabic page
        $this->assertSame(0, preg_match_all('# dir=(?:ltr|rtl)[ >]#', $this->page($channel->route, 'ar')));
    }

    public function test_the_page_direction_is_the_layouts(): void
    {
        foreach (['en' => false, 'ar' => true, 'de' => false] as $locale => $rtl) {
            $html = $this->page('/', $locale);
            $this->assertMatchesRegularExpression('#<html\b[^>]*\bdir="'.($rtl ? 'rtl' : 'ltr').'"#', $html, $locale);
            app()->setLocale($locale);
            $this->assertSame($rtl, TextDirection::pageIsRtl(), $locale);
        }
        // the admin's site-wide right-to-left switch makes every language's page right to left: a Latin text is foreign
        config(['site.rtl_support' => true]);
        $this->assertMatchesRegularExpression('#<html\b[^>]*\bdir="rtl"#', $this->page('/', 'en'));
        app()->setLocale('en');
        $this->assertTrue(TextDirection::pageIsRtl());
        $this->assertSame(' dir=ltr', TextDirection::attr('Night drives'));
    }

    public function test_the_stylesheets_take_the_direction_from_the_markup(): void
    {
        $member = '';
        foreach (glob(base_path('resources/sass/member/*.scss')) as $file) {
            $member .= (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file));
        }
        $this->assertStringNotContainsString('unicode-bidi: plaintext', $member, 'R2A-064\'s rule is gone (it left the clamped text right to left)');
        $css = (string) preg_replace('/\s+/', ' ', $member);
        // the creator's texts with a direction of their own sit on the page's side, as wide as their text
        $this->assertMatchesRegularExpression('#:root :is\(\.mk-channel__name, \.mk-channel__tagline, \.mk-prose, \.mk-playlist__desc, \.mk-head__title, \.mk-item__title, \.mk-lrow__title, \.mk-card__title, \.mk-subs-manage__desc, \.mk-feature__title, \.mk-feature__desc\)\[dir\] \{ inline-size: fit-content; \}#', $css);
        // ... and their lines start at their own start in WebKit and Firefox too (list items take their list's alignment)
        $this->assertStringContainsString(':root .avd-mk :where(li) { text-align: inherit; }', $css);
    }

    /** A Latin channel, a member who follows it, watched its video, saved its playlist. @return array{User, User, Playlist} */
    private function latinChannel(): array
    {
        $channel = User::factory()->create(['username' => 'qa3ch_curator', 'slug' => 'Qa3chCurator', 'show_subscribers' => true,
            'description' => 'Hand-picked sets of the calmest videos on this site: night drives, rain, oceans and cabins.',
            'channel_customization' => [
                'tagline' => 'Calm sets for long evenings: drives, rain, oceans and cabins, picked by hand every month.',
                'cta_label' => 'Get the monthly calm-set newsletter today!',
                'cta_url' => 'https://curator.example/newsletter',
                'show_subscribe_cta' => '1',
            ]]);
        $video = Video::factory()->for($channel)->withStatus(VideoStatus::PUBLIC)->create(['title' => 'Tokyo night walk in the rain - 4K binaural city ambience',
            'published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);
        $playlist = Playlist::factory()->for($channel)->create(['title' => 'Night drives and city lights', 'description' => 'Every night drive on this site.',
            'status' => PlaylistStatus::PUBLIC]);
        $playlist->videos()->attach($video->id, ['position' => 1]);
        $member = User::factory()->create();
        DB::table('subscriptions')->insert(['user_id' => $channel->id, 'subscriber_id' => $member->id, 'subscribe_at' => now(), 'read_at' => now()]);
        DB::table('views')->insert(['video_id' => $video->id, 'user_id' => $member->id, 'ip' => '127.0.0.1', 'view_at' => now()->subMinutes(5)]);
        DB::table('favorites_playlist')->insert(['user_id' => $member->id, 'playlist_id' => $playlist->id, 'added_at' => now()]);

        return [$channel, $member, $playlist];
    }

    /** A page in a language (public/index.php takes the language off the address: DETECTED_LOCALE), as a guest or a member. */
    private function page(string $url, string $locale, bool $part = false, ?User $user = null): string
    {
        $_SERVER['DETECTED_LOCALE'] = $locale;
        try {
            $request = $user ? $this->actingAs($user) : $this;
            $response = $part ? $request->getJson($url) : $request->get($url);
            $response->assertOk();

            return $part ? (string) $response->json('html') : (string) $response->getContent();
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
            app()->setLocale('en');
        }
    }
}
