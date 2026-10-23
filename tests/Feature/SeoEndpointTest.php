<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Support\SeoSitemapCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SeoEndpointTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('publicSeoEndpoints')]
    public function test_public_seo_endpoint_smoke(string $routeName): void
    {
        $this->get(route($routeName))->assertOk();
    }

    public function test_sitemap_index_uses_clean_video_sitemap_urls(): void
    {
        Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create();

        $this->get(route('seo.sitemap'))
            ->assertOk()
            ->assertSee(url('/sitemap-pages-en.xml'), false)
            ->assertSee(url('/sitemap-pages-vi.xml'), false)
            ->assertSee(url('/sitemap-videos-en-1.xml'), false)
            ->assertSee(url('/sitemap-videos-vi-1.xml'), false)
            ->assertSee('<?xml-stylesheet type="text/xsl" href="' . url('/sitemap.xsl') . '"?>', false)
            ->assertDontSee('sitemap-videos.xml?from=', false);
    }

    public function test_sitemap_stylesheet_endpoint_is_available(): void
    {
        $this->get(route('seo.sitemap.stylesheet'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<xsl:stylesheet', false)
            ->assertSee('Sitemap Index', false);
    }

    public function test_sitemap_video_limit_is_capped_for_large_video_metadata(): void
    {
        config(['site.sitemap_video_limit' => 10000]);

        $this->get(route('seo.sitemap'))
            ->assertOk();

        $controller = app(\App\Http\Controllers\SeoController::class);
        $method = new \ReflectionMethod($controller, 'sitemapLimit');
        $method->setAccessible(true);

        $this->assertSame(1000, $method->invoke($controller));
    }

    public function test_empty_video_sitemap_response_is_not_cacheable(): void
    {
        $response = $this->get(route('seo.sitemap.videos.locale.page', ['locale' => 'th', 'page' => 1]))
            ->assertOk()
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0')
            ->assertHeader('X-Sitemap-Empty', '1');

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
    }

    public function test_sitemap_index_does_not_cache_zero_locale_video_counts(): void
    {
        SeoSitemapCache::clear();

        $this->get(route('seo.sitemap'))
            ->assertOk()
            ->assertDontSee(url('/sitemap-videos-th-1.xml'), false);

        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Thai cache sitemap video',
                'slug' => 'thai-cache-sitemap-video',
                'avdb_id' => 700000,
            ]);

        $video->translations()->create([
            'locale' => 'th',
            'title' => 'Thai cache sitemap title',
            'description' => 'Thai cache sitemap description.',
        ]);

        $this->get(route('seo.sitemap'))
            ->assertOk()
            ->assertSee(url('/sitemap-videos-th-1.xml'), false);
    }

    public function test_pages_sitemap_lists_localized_alternates(): void
    {
        $response = $this->get(route('seo.sitemap.pages.locale', ['locale' => 'vi']))
            ->assertOk()
            ->assertSee('xmlns:xhtml="http://www.w3.org/1999/xhtml"', false)
            ->assertSee('<loc>' . url('/vi') . '</loc>', false)
            ->assertSee('hreflang="vi"', false)
            ->assertSee('hreflang="en"', false)
            ->assertSee('hreflang="x-default"', false)
            ->assertSee('href="' . url('/') . '"', false);

        $this->assertXml($response->getContent());
    }

    public function test_video_sitemap_lists_avdb_videos_in_every_active_locale(): void
    {
        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Localized AVDB sitemap video',
                'slug' => 'localized-avdb-sitemap-video',
                'description' => 'English sitemap description.',
                'avdb_id' => 12345,
                'embed_code' => 'localized-avdb-sitemap-video',
            ]);

        $video->translations()->create([
            'locale' => 'vi',
            'title' => 'Vietnamese sitemap title',
            'description' => 'Vietnamese sitemap description.',
        ]);

        $response = $this->get(route('seo.sitemap.videos.locale.page', ['locale' => 'vi', 'page' => 1]))
            ->assertOk()
            ->assertSee(url('/vi/video/localized-avdb-sitemap-video-' . $video->uuid), false)
            ->assertSee('hreflang="en"', false)
            ->assertSee('hreflang="vi"', false)
            ->assertSee('hreflang="fr"', false);

        $this->assertXml($response->getContent());

        $this->get(route('seo.sitemap.videos.locale.page', ['locale' => 'fr', 'page' => 1]))
            ->assertOk()
            ->assertSee(url('/fr/video/localized-avdb-sitemap-video-' . $video->uuid), false);
    }

    public function test_locale_video_sitemap_paginates_after_locale_filtering(): void
    {
        config(['site.sitemap_video_limit' => 100]);

        Video::factory()
            ->count(100)
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->sequence(fn ($sequence) => [
                'avdb_id' => 500000 + $sequence->index,
                'title' => 'English only AVDB sitemap video ' . $sequence->index,
                'slug' => 'english-only-avdb-sitemap-video-' . $sequence->index,
            ])
            ->create();

        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Thai translated AVDB sitemap video',
                'slug' => 'thai-translated-avdb-sitemap-video',
                'description' => 'English sitemap description.',
                'avdb_id' => 600000,
                'embed_code' => 'thai-translated-avdb-sitemap-video',
            ]);

        $video->translations()->create([
            'locale' => 'th',
            'title' => 'Thai sitemap title',
            'description' => 'Thai sitemap description.',
        ]);

        SeoSitemapCache::clear();

        $this->get(route('seo.sitemap'))
            ->assertOk()
            ->assertSee(url('/sitemap-videos-th-1.xml'), false)
            ->assertSee(url('/sitemap-videos-th-2.xml'), false);

        $pageOne = $this->get(route('seo.sitemap.videos.locale.page', ['locale' => 'th', 'page' => 1]))
            ->assertOk();

        $this->assertSame(100, substr_count($pageOne->getContent(), '<url>'));
        $this->assertXml($pageOne->getContent());

        $response = $this->get(route('seo.sitemap.videos.locale.page', ['locale' => 'th', 'page' => 2]))
            ->assertOk()
            ->assertSee(url('/th/video/thai-translated-avdb-sitemap-video-' . $video->uuid), false);

        $this->assertSame(1, substr_count($response->getContent(), '<url>'));
        $this->assertXml($response->getContent());
    }

    public static function publicSeoEndpoints(): array
    {
        return [
            'robots' => ['seo.robots'],
            'sitemap' => ['seo.sitemap'],
            'sitemap stylesheet' => ['seo.sitemap.stylesheet'],
            'pages sitemap' => ['seo.sitemap.pages'],
            'video sitemap' => ['seo.sitemap.videos'],
        ];
    }

    private function assertXml(string $content): void
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($content);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded);
    }
}
