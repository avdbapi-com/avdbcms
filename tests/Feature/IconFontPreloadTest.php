<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\HtmlString;
use Tests\TestCase;

/**
 * R1-071: the layout preloaded the full Font Awesome solid (158 KB) and regular (25 KB) fonts on every page, where they
 * competed with the render-blocking CSS on slow phones. It preloads the fonts cut to the main CSS's icons
 * (resources/fonts/fontawesome, scripts/fontawesome/gen_site_fa_subset.py), the full solid font as well only on a page
 * that shows an icon an owner chose outside the cut, and the full fonts on the old admin layout's pages (their full
 * icon set draws every icon from them).
 */
class IconFontPreloadTest extends TestCase
{
    use RefreshDatabase;

    private const CUT_SOLID = '/build/resources~fonts~fontawesome~fa-solid-900.woff2';

    private const CUT_REGULAR = '/build/resources~fonts~fontawesome~fa-regular-400.woff2';

    private const FULL_SOLID = '/build/node_modules~@fortawesome~fontawesome-free~webfonts~fa-solid-900.woff2';

    private const FULL_REGULAR = '/build/node_modules~@fortawesome~fontawesome-free~webfonts~fa-regular-400.woff2';

    protected function setUp(): void
    {
        parent::setUp();

        // A build whose asset() URL names the source file (the tests have no manifest of their own); the rest as
        // withoutVite() gives it.
        Facade::clearResolvedInstance(Vite::class);
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null)
            {
                return new HtmlString('');
            }

            public function __call($method, $parameters)
            {
                return '';
            }

            public function __toString(): string
            {
                return '';
            }

            public function isRunningHot()
            {
                return false;
            }

            public function cspNonce()
            {
                return null;
            }

            public function preloadedAssets()
            {
                return [];
            }

            public function reactRefresh()
            {
                return '';
            }

            public function content($asset, $buildDirectory = null)
            {
                return '';
            }

            public function asset($asset, $buildDirectory = null)
            {
                return '/build/'.str_replace('/', '~', $asset);
            }
        });
    }

    public function test_a_page_preloads_the_cut_fonts_and_not_the_full_ones(): void
    {
        $preloads = $this->fontPreloads($this->get(route('pages.trend'))->assertOk()->getContent());

        $this->assertSame([self::CUT_SOLID, self::CUT_REGULAR], $preloads);
    }

    public function test_a_page_with_an_owners_icon_outside_the_cut_also_preloads_the_full_solid_font(): void
    {
        // a menu link with an icon the main CSS does not carry (Appearance > Navigation: "Name | address | icon")
        config(['site.sidebar_primary_links' => "Dragons|dragon|/dragons\nHome|house|/"]);

        $html = $this->get(route('pages.trend'))->assertOk()->getContent();

        $this->assertStringContainsString('<style id="avd-icon-extra">.fa-dragon{--fa:"\f6d5"}</style>', $html);
        $this->assertSame([self::CUT_SOLID, self::CUT_REGULAR, self::FULL_SOLID], $this->fontPreloads($html));

        // an owner's icon of the cut needs no rule and no full font
        config(['site.sidebar_primary_links' => "Home|house|/\nTrending|fire|/trending"]);
        $html = $this->get(route('pages.trend'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="avd-icon-extra"', $html);
        $this->assertSame([self::CUT_SOLID, self::CUT_REGULAR], $this->fontPreloads($html));
    }

    public function test_the_old_admin_layout_preloads_the_full_fonts_its_icon_set_uses(): void
    {
        config(['admin.ui' => 'v1']);
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('admin.appearance.history'))->assertOk()->getContent();

        $this->assertStringContainsString('avd-back-sidebar', $html, 'the old admin layout');
        $this->assertSame([self::FULL_SOLID, self::FULL_REGULAR], $this->fontPreloads($html));
    }

    /** @return list<string> the hrefs of the page's font preloads, in order */
    private function fontPreloads(string $html): array
    {
        // fetched after the render-blocking CSS (fetchpriority low), still before the first paint
        preg_match_all('#<link rel="preload" as="font" type="font/woff2" href="([^"]*)" fetchpriority="low" crossorigin>#', $html, $links);
        $this->assertSame(substr_count($html, 'as="font"'), count($links[1]), 'every font preload has the low priority');

        return array_map(static fn (string $href): string => (string) preg_replace('#^https?://[^/]+#', '', $href), $links[1]);
    }
}
