<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Round 1 (R1-034, R1-036, R1-037): the side menu and the account panel lead to the Library page on every device, the
 * side menu's headings keep their count, and the side menu lists only the saved playlists that open.
 */
class FrontSidebarLibraryTest extends TestCase
{
    use RefreshDatabase;

    /** R1-034: tablets and computers had no way to /library (only the phone bottom bar linked it). */
    public function test_the_side_menu_and_the_account_panel_link_the_library_for_members(): void
    {
        // the shipped members' links (config/admin_settings.php default of sidebar_auth_links)
        config(['site.sidebar_auth_links' => "History|history|/history
Your videos|video|/profile/videos|1
Watch later|clock|/playlist/watch-later
Liked videos|thumbs-up|/playlist/liked-videos"]);
        $member = User::factory()->create();
        $library = route('pages.library');
        $href = 'href="(?:https?://[^/"]+)?/library"';

        $html = $this->actingAs($member)->get(route('pages.trend'))->assertOk()->getContent();
        $menu = $this->between($html, 'id="responsive-sidebar"', '</nav>');
        $this->assertMatchesRegularExpression('#<a class="nav-link avd-sidebar-link[^"]*" '.$href.'[^>]*><i class="fa-solid fa-folder-open" aria-hidden="true"></i><span class="text-sm">Library</span></a>#', $menu, 'the side menu');
        // the members' links start with it
        $this->assertLessThan(strpos($menu, '/history"'), strpos($menu, '/library"'));

        $panel = $this->between($html, 'id="account"', '<div class="mk-account__foot">');
        $this->assertMatchesRegularExpression('#<a class="mk-account__link" '.$href.'#', $panel, 'the account panel');
        $this->assertLessThan(strpos($panel, 'watch-later'), strpos($panel, '/library"'), 'first in the Library group');

        // marked as the page on /library
        $libraryHtml = $this->actingAs($member)->get($library)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<a class="mk-account__link" '.$href.'\s+aria-current="page"#', $libraryHtml);
    }

    public function test_an_owner_link_to_the_library_is_not_doubled_and_guests_get_none(): void
    {
        config(['site.sidebar_auth_links' => "Library|folder-open|/library\nHistory|history|/history"]);
        $member = User::factory()->create();

        $menu = $this->between($this->actingAs($member)->get(route('pages.trend'))->getContent(), 'id="responsive-sidebar"', '</nav>');
        $this->assertSame(1, preg_match_all('#href="(?:https?://[^/"]+)?/library"#', $menu));

        auth()->logout();
        $guest = $this->between($this->get(route('pages.trend'))->assertOk()->getContent(), 'id="responsive-sidebar"', '</nav>');
        $this->assertSame(0, preg_match_all('#href="(?:https?://[^/"]+)?/library"#', $guest));
    }

    public function test_the_menu_of_config_menu_php_also_starts_the_members_group_with_the_library(): void
    {
        config(['site.sidebar_static_menu_enabled' => false]);
        $member = User::factory()->create();

        $menu = $this->between($this->actingAs($member)->get(route('pages.trend'))->getContent(), 'id="responsive-sidebar"', '</nav>');
        $this->assertSame(1, preg_match_all('#href="(?:https?://[^/"]+)?/library"#', $menu));
        $this->assertLessThan(strpos($menu, '/history"'), strpos($menu, '/library"'));
    }

    /**
     * R1-037: a saved playlist its channel made private since (its address answers 404) is neither listed nor counted,
     * like on the Saved playlists page. R1-036: the count is outside the part of the heading that truncates.
     */
    public function test_the_side_menu_lists_and_counts_only_saved_playlists_that_open(): void
    {
        $member = User::factory()->create();
        $channel = User::factory()->create();
        $open = $this->savedPlaylist($member, $channel, 'Open saved playlist', PlaylistStatus::PUBLIC);
        $unlisted = $this->savedPlaylist($member, $channel, 'Unlisted saved playlist', PlaylistStatus::UNLISTED);
        $closed = $this->savedPlaylist($member, $channel, 'Went private later', PlaylistStatus::PRIVATE);

        $html = $this->actingAs($member)->get(route('pages.trend'))->assertOk()->getContent();
        $section = $this->between($html, 'avd-sidebar-section--playlists', 'avd-sidebar-section--subscriptions');

        // the links by their address (a menu link carries it in href only: R1-069 dropped the "route" attribute)
        $href = static fn (Playlist $playlist): string => '#href="(?:https?://[^/"]+)?'.preg_quote((string) parse_url($playlist->route, PHP_URL_PATH), '#').'"#';
        $this->assertMatchesRegularExpression($href($open), $section);
        $this->assertMatchesRegularExpression($href($unlisted), $section);
        $this->assertDoesNotMatchRegularExpression($href($closed), $section);
        $this->assertStringNotContainsString('Went private later', $section);
        $this->assertMatchesRegularExpression('#<span class="avd-sidebar-heading__title"><span class="avd-sidebar-heading__name">Playlists</span> <span class="avd-sidebar-heading__count">\(2\)</span></span>#', $section);
        $this->get($open->route)->assertOk();
        $this->get($closed->route)->assertNotFound();

        // the same playlists as the Saved playlists page
        $manage = $this->actingAs($member)->get(route('playlist.manage'))->assertOk()->getContent();
        $this->assertStringContainsString('Open saved playlist', $manage);
        $this->assertStringNotContainsString('Went private later', $manage);
    }

    public function test_the_subscriptions_heading_keeps_its_count_outside_the_name(): void
    {
        $member = User::factory()->create();
        foreach (User::factory()->count(3)->create() as $channel) {
            DB::table('subscriptions')->insert(['user_id' => $channel->id, 'subscriber_id' => $member->id, 'subscribe_at' => now(), 'read_at' => now()]);
        }

        $html = $this->actingAs($member)->get(route('pages.trend'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<span class="avd-sidebar-heading__title"><span class="avd-sidebar-heading__name">Subscriptions</span> <span class="avd-sidebar-heading__count">\(3\)</span></span>#', $html);

        $scss = (string) file_get_contents(resource_path('sass/header.scss'));
        $rule = fn (string $selector): string => $this->between($scss, $selector.' {', '}');
        $this->assertStringContainsString('text-overflow: ellipsis', $rule('.avd-sidebar-heading__name'), 'the name truncates');
        $this->assertStringContainsString('flex: none', $rule('.avd-sidebar-heading__count'), 'the count never shrinks');
        $this->assertStringNotContainsString('text-overflow', $rule('.avd-sidebar-heading__title'), 'the title box holds both');
    }

    private function savedPlaylist(User $member, User $channel, string $title, PlaylistStatus $status): Playlist
    {
        $playlist = Playlist::query()->create(['uuid' => (string) Str::uuid(), 'title' => $title, 'user_id' => $channel->id,
            'status' => PlaylistStatus::PUBLIC, 'is_deletable' => true]);
        DB::table('favorites_playlist')->insert(['user_id' => $member->id, 'playlist_id' => $playlist->id, 'added_at' => now()->subMinute()]);
        // the channel changes the visibility after the member saved it
        $playlist->forceFill(['status' => $status])->save();

        return $playlist->fresh();
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'missing: '.$from);
        $end = strpos($html, $to, $start + strlen($from));

        return substr($html, $start, $end === false ? null : $end - $start);
    }
}
