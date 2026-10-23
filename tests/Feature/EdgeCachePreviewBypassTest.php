<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Services\SeoService;
use App\Support\AppearanceDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EdgeCacheVideoHtml never makes a preview public: a request carrying avd_draft, theme, theme_preset, theme_flavor,
 * theme_mode, mode, daily_design, preview_day, a page preview (layout, hero, watch_layout) or the name of any
 * appearance setting is answered private / no-store / noindex, while the plain pages keep their edge cache headers.
 * Every query key the public design code reads is such a key.
 */
class EdgeCachePreviewBypassTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Query keys these files read that never change the design, so their pages keep the edge cache: videos-area only
     * leaves the shipped first feed page out when the address carries the feed's own cursor.
     */
    private const NOT_DESIGN_KEYS = ['cursor'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.smart_discovery_rail_enabled' => false,
            'site.creator_spotlight_enabled' => false,
            'site.trending_collections_enabled' => false,
        ]);
        Setting::set('appearance_schema_version', '2', 'appearance');
    }

    public function test_a_plain_guest_page_keeps_its_edge_cache_headers(): void
    {
        $response = $this->get('/')->assertOk();

        $this->assertStringContainsString('s-maxage=', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('X-Robots-Tag'));
    }

    public function test_every_preview_key_makes_the_page_private(): void
    {
        $queries = [
            'avd_draft' => 'not-a-token',
            'theme' => 'broadsheet',
            'theme_preset' => 'broadsheet',
            'theme_flavor' => 'coral',
            'theme_mode' => 'light',
            'mode' => 'light',
            'daily_design' => '120',
            'preview_day' => '5',
            'layout' => 'bento_mosaic',
            'hero' => 'editorial_lead',
            'watch_layout' => 'cinema_wide',
        ];

        $this->assertSame(array_keys($queries), AppearanceDraft::PREVIEW_KEYS);

        // Any appearance setting by its own name: the layout, ThemeStructure and the page views read them.
        $queries += ['header_style' => 'minimal_slim', 'sidebar_style' => 'rail', 'typography_pairing' => 'broadsheet_serif', 'css_animations_enabled' => '0', 'announcement_banner_enabled' => '0'];

        foreach ($queries as $key => $value) {
            $this->assertPrivate($this->get('/?'.http_build_query([$key => $value]))->assertOk(), $key);
        }
    }

    public function test_a_theme_preset_preview_is_private_and_renders_the_preview(): void
    {
        $response = $this->get('/?theme_preset=broadsheet')->assertOk();

        $this->assertStringContainsString('data-avd-theme="broadsheet"', (string) $response->getContent(), 'the preview is real, so it must not be cached');
        $this->assertPrivate($response, 'theme_preset');
    }

    public function test_other_query_keys_keep_the_edge_cache(): void
    {
        $response = $this->get('/?utm_source=newsletter&page=2')->assertOk();

        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('X-Robots-Tag'));
    }

    /**
     * Drift guard: every query key the public pages read to change the design for one request (the layout's
     * $queryString / $configBool / $effectEnabled helpers, ThemeStructure, AppearanceSettings, the home, categories,
     * watch and feed views) is a preview key.
     */
    public function test_every_query_key_the_public_design_code_reads_is_a_preview_key(): void
    {
        $files = [
            'resources/views/components/layout.blade.php',
            'resources/views/components/videos-area.blade.php',
            'resources/views/pages/home.blade.php',
            'resources/views/pages/categories.blade.php',
            'resources/views/videos/show.blade.php',
            'app/Support/ThemeStructure.php',
            'app/Support/AppearanceSettings.php',
        ];
        $pattern = '/(?:->query\(|->has\(|\$queryString\(|\$configBool\(|\$effectEnabled\(|queryOrConfig\(|self::option\()\s*\'([a-z0-9_]+)\'/';
        $found = [];

        foreach ($files as $file) {
            preg_match_all($pattern, (string) file_get_contents(base_path($file)), $matches);
            foreach ($matches[1] as $key) {
                $found[$key][] = $file;
            }
        }

        $this->assertArrayHasKey('theme_preset', $found, 'the scan sees the query reads');
        $this->assertGreaterThan(20, count($found));
        $found = array_diff_key($found, array_flip(self::NOT_DESIGN_KEYS));

        $request = fn (string $key) => \Illuminate\Http\Request::create('/?'.http_build_query([$key => '1']));
        $missing = array_keys(array_filter($found, fn (array $where, string $key): bool => ! AppearanceDraft::isPreviewRequest($request($key)), ARRAY_FILTER_USE_BOTH));

        $this->assertSame([], $missing, 'read by the public pages but not treated as a preview: '.json_encode(array_intersect_key($found, array_flip($missing))));
    }

    public function test_a_video_page_preview_is_private_although_the_page_asks_for_the_edge_cache(): void
    {
        $video = Video::factory()->for(User::factory())->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->subDay()]);
        $url = app(SeoService::class)->videoUrl($video);
        $plain = $this->get($url);

        if ($plain->getStatusCode() !== 200) {
            $this->markTestSkipped('The test video page is not public here (status '.$plain->getStatusCode().').');
        }

        $this->assertStringContainsString('s-maxage', (string) $plain->headers->get('Cache-Control'));

        $preview = $this->get($url.'?theme=pure_oled_black')->assertOk();
        $this->assertStringContainsString('no-store', (string) $preview->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('s-maxage', (string) $preview->headers->get('Cache-Control'));
        $this->assertSame('no-store', $preview->headers->get('Cloudflare-CDN-Cache-Control'));
        $this->assertSame('noindex', $preview->headers->get('X-Robots-Tag'));
    }

    public function test_a_signed_in_admin_preview_is_private_too(): void
    {
        $admin = User::factory()->admin()->create();
        AppearanceDraft::put($admin->id, ['values' => ['home_intro_title' => 'Draft welcome'], 'action' => null]);
        $token = AppearanceDraft::sign($admin->id, ['home_intro_title' => 'Draft welcome']);

        $response = $this->actingAs($admin)->get('/?avd_draft='.urlencode($token))->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    private function assertPrivate(\Illuminate\Testing\TestResponse $response, string $key): void
    {
        $cache = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cache, $key);
        $this->assertStringContainsString('private', $cache, $key);
        $this->assertStringNotContainsString('public', $cache, $key);
        $this->assertStringNotContainsString('s-maxage', $cache, $key);
        $this->assertSame('no-store', $response->headers->get('Cloudflare-CDN-Cache-Control'), $key);
        $this->assertSame('no-store', $response->headers->get('CDN-Cache-Control'), $key);
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'), $key);
    }
}
