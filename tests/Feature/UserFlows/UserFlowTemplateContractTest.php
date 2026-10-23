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

        foreach (['home-tab', 'videos_link', 'playlists_link', 'about-tab'] as $tabId) {
            self::assertStringContainsString('id="' . $tabId . '"', $template);
            self::assertStringContainsString('role="tab"', $template);
        }

        self::assertStringContainsString('href="#home"', $template);
        self::assertStringContainsString('aria-labelledby="videos_link"', $template);
        self::assertStringContainsString('aria-labelledby="playlists_link"', $template);
    }

    public function test_playlist_and_search_cards_preserve_mobile_image_and_action_contracts(): void
    {
        $playlistList = $this->read('resources/views/users/playlists/index.blade.php');
        $searchPlaylist = $this->read('resources/views/search/playlist.blade.php');
        $playlistShow = $this->read('resources/views/playlists/show.blade.php');

        self::assertStringContainsString('aria-label="{{__(\'Delete playlist\')}}"', $playlistList);
        self::assertStringContainsString('width="120" height="68"', $playlistList);
        self::assertStringContainsString('width="640" height="360"', $searchPlaylist);
        self::assertStringContainsString("asset('images/placeholders/no-image.svg')", $searchPlaylist);
        self::assertStringContainsString('<li class="d-flex gap-3 align-items-center list-group-item', $playlistShow);
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
