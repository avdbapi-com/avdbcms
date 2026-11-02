<?php

namespace Tests\Feature\UserFlows;

use PHPUnit\Framework\TestCase;

final class UserFlowTemplateContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 3);
    }

    public function test_mobile_filter_controls_are_focusable_and_control_the_real_form(): void
    {
        $template = $this->read('resources/views/forms/filter_form.blade.php');

        self::assertStringContainsString('aria-controls="filter-fields"', $template);
        self::assertStringContainsString(':aria-expanded="filters.toString()"', $template);
        self::assertStringContainsString("'id' => 'filter-fields'", $template);
        self::assertStringContainsString("'x-show.important' => 'filters'", $template);
        self::assertStringContainsString('aria-label="{{ __(\'Search\') }}"', $template);
        self::assertStringContainsString('aria-label="{{ __(\'Reset\') }}"', $template);
    }

    public function test_channel_tabs_have_keyboard_addresses_and_matching_panels(): void
    {
        $template = $this->read('resources/views/users/show.blade.php');

        // 1.2.3 (member design 6.10, QA channel-14): each tab is a link with its own address, the current one is
        // marked, and it names the panel it shows
        self::assertStringContainsString('<nav class="mk-tabs" aria-label="{{ __(\'Channel\') }}">', $template);
        foreach (['home' => 'channelHome', 'videos' => 'channelVideos', 'playlists' => 'channelPlaylists', 'about' => 'channelAbout'] as $hash => $panel) {
            self::assertStringContainsString('href="#' . $hash . '" aria-controls="' . $panel . '"', $template);
            self::assertStringContainsString('id="' . $panel . '"', $template);
        }
        // Home is printed as the open tab; the page's script moves is-active + aria-current="page" to the open tab
        self::assertStringContainsString('<a class="mk-tab is-active" href="#home" aria-controls="channelHome" aria-current="page">', $template);
        // the page's script: one cached file since 1.2.3 (resources/js/member/library-pages.js, printed by the page)
        self::assertStringContainsString("@include('library.pages-script')", $template);
        $script = $this->read('resources/js/member/library-pages.js');
        self::assertStringContainsString("this.\$watch('tab', (name) => { this.markTabs(name);", $script);
        self::assertStringContainsString("link.setAttribute('aria-current', 'page')", $script);
        self::assertStringContainsString("link.removeAttribute('aria-current')", $script);
    }

    public function test_playlist_and_search_cards_preserve_mobile_image_and_action_contracts(): void
    {
        $playlistList = $this->read('resources/views/users/playlists/index.blade.php');
        $searchPlaylist = $this->read('resources/views/search/playlist.blade.php');
        $playlistShow = $this->read('resources/views/playlists/show.blade.php');
        $playlistRows = $this->read('resources/views/library/playlist-rows.blade.php');

        // 1.2.3 (member design 6.7): the playlists list is kit rows; every row action has an accessible name (Edit,
        // the "More actions" menu with View / Copy link / Delete playlist) and the cover keeps its 16:9 box (no shift).
        self::assertStringContainsString('aria-label="{{ __(\'More actions\') }}"', $playlistList);
        self::assertStringContainsString('aria-label="{{ __(\'Edit playlist\') }}"', $playlistList);
        self::assertStringContainsString('{{ __(\'Delete playlist…\') }}', $playlistList);
        self::assertStringContainsString('width="160" height="90"', $playlistList);
        self::assertStringContainsString('width="640" height="360"', $searchPlaylist);
        self::assertStringContainsString("asset('images/placeholders/no-image.svg')", $searchPlaylist);
        // 1.2.3 (member design 6.11, QA channel-6, channel-26): the playlist page lists its rows from the shared rows
        // partial: a fixed-size thumbnail and the row menu with its accessible name
        self::assertStringContainsString("@include('library.playlist-rows'", $playlistShow);
        self::assertStringContainsString('<li class="mk-lrow" data-playlist-row="{{ $video->id }}">', $playlistRows);
        self::assertStringContainsString('width="168" height="94"', $playlistRows);
        self::assertStringContainsString("aria-label=\"' . e(__('More actions')) . '\"", $playlistRows);
    }

    public function test_category_chip_rail_exposes_scroll_and_current_page_semantics(): void
    {
        $template = $this->read('resources/views/pages/partials/category-chips-scroller.blade.php');

        self::assertStringContainsString('role="region"', $template);
        self::assertStringContainsString('aria-label="{{ __(\'Categories\') }}"', $template);
        self::assertSame(2, substr_count($template, 'aria-controls="category-tabs"'));
        self::assertStringContainsString('aria-current="page"', $template);
    }

    private function read(string $relative): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
