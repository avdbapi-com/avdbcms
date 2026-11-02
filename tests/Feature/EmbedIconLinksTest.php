<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The embed pages (R1B-023, owner-approved change of their icon links only): every page another site can frame - the
 * player of a public video, and the private, banned, missing and "embedding disabled" pages - links the site's own
 * icons, the ones the main layout links (the owner's "Browser tab icon", else /icons/favicon.ico, and
 * /icons/apple-touch-icon.png). They linked three files under /images/icons/ that no release has, so every embed page
 * opened on its own asked for icons that answer 404.
 */
class EmbedIconLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['site.allow_embedding' => true, 'site.favicon_url' => '']);
    }

    /** @return array<string, array{0: string}> */
    public static function embedPages(): array
    {
        return [
            'public video' => ['public'],
            'unlisted video' => ['unlisted'],
            'private video' => ['private'],
            'banned video' => ['banned'],
            'missing video' => ['missing'],
            'embedding turned off' => ['disabled'],
        ];
    }

    #[DataProvider('embedPages')]
    public function test_every_icon_an_embed_page_links_is_a_file_of_the_site(string $page): void
    {
        $html = $this->embedPage($page);
        $icons = self::iconLinks($html);

        $this->assertSame(['icon', 'apple-touch-icon'], array_column($icons, 'rel'), $page.': the main layout\'s two icon links, nothing else');
        $this->assertStringNotContainsString('/images/icons/', $html, $page.': no link to the missing /images/icons/ files');

        foreach ($icons as $icon) {
            $path = (string) parse_url($icon['href'], PHP_URL_PATH);
            $this->assertFileExists(public_path(ltrim($path, '/')), $page.': '.$icon['rel'].' '.$icon['href']);
        }

        $this->assertSame([url('/icons/favicon.ico'), url('/icons/apple-touch-icon.png')], array_column($icons, 'href'));
    }

    public function test_the_owners_browser_tab_icon_is_the_icon_of_the_embed_pages_too(): void
    {
        config(['site.favicon_url' => '/uploads/brand/tab-icon.png']);

        foreach (['public', 'private', 'missing'] as $page) {
            $icons = self::iconLinks($this->embedPage($page));
            $this->assertSame([url('/uploads/brand/tab-icon.png'), url('/icons/apple-touch-icon.png')], array_column($icons, 'href'), $page);
        }

        // an icon on another host (a CDN) is linked as it is, as the main layout does
        config(['site.favicon_url' => 'https://cdn.example.test/tab.png']);
        $this->assertSame('https://cdn.example.test/tab.png', self::iconLinks($this->embedPage('public'))[0]['href']);
    }

    /** The HTML of an embed page of the given kind. */
    private function embedPage(string $page): string
    {
        if ($page === 'disabled') {
            config(['site.allow_embedding' => false]);
        }

        $uuid = match ($page) {
            'missing' => '00000000-0000-4000-8000-000000000000',
            default => Video::factory()->forUser()->withStatus(match ($page) {
                'private' => VideoStatus::PRIVATE,
                'banned' => VideoStatus::BANNED,
                'unlisted' => VideoStatus::UNLISTED,
                default => VideoStatus::PUBLIC,
            })->create()->uuid,
        };

        $html = (string) $this->get('/video/'.$uuid.'/embed')->assertOk()->getContent();
        $expected = match ($page) {
            'private' => 'This video is private',
            'banned' => 'This video was banned',
            'missing' => 'This video has been deleted or does not exist.',
            'disabled' => 'Embedding is currently disabled',
            default => 'avd-embed-shell',
        };
        $this->assertStringContainsString($expected, $html, $page);

        return $html;
    }

    /** @return list<array{rel: string, href: string}> every icon link of the page's head, in order */
    private static function iconLinks(string $html): array
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $icons = [];

        foreach ($document->getElementsByTagName('link') as $link) {
            $rel = strtolower(trim((string) $link->getAttribute('rel')));

            if (in_array('icon', preg_split('/\s+/', $rel) ?: [], true) || $rel === 'apple-touch-icon') {
                $icons[] = ['rel' => $rel, 'href' => (string) $link->getAttribute('href')];
            }
        }

        return $icons;
    }
}
