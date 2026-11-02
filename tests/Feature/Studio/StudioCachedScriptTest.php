<?php

namespace Tests\Feature\Studio;

use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;
use Tests\TestCase;

/**
 * 1.2.3 round 1b (R1R-01, member design 4.3): the studio's scripts are one cached file (resources/js/member/studio-pages.js,
 * a Vite input printed once by layouts/user.blade.php) instead of 2 to 15 KB of inline script in every back-office page
 * (the member bars, the studio parts, the replies sheet) and My videos' 5 KB component in its content. Every back-office
 * page gets the bars; a studio page names its parts; the components are registered, the page roots name them.
 */
class StudioCachedScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_back_office_page_gets_the_cached_script_once_and_no_inline_copy(): void
    {
        $member = User::factory()->create();
        Video::factory()->for($member)->create(['title' => 'Sunset drive']);
        $this->fakeBuild();

        $pages = [
            route('user.videos.index') => 'core,lists',
            route('user.comments.index') => 'core,lists,readmore',
            route('user.index') => 'core,readmore,confirm',
            route('user.subscribers') => 'core',
            route('user.edit') => null, // Account settings: the bars only, no studio part
        ];
        foreach ($pages as $url => $parts) {
            $html = $this->actingAs($member)->get($url)->assertOk()->getContent();
            $this->assertSame(1, preg_match_all('~<script src="/build/assets/studio-pages-test\.js"([^>]*)></script>~', $html, $tags), $url.': once');
            if ($parts === null) {
                $this->assertSame('', $tags[1][0], $url.': no parts');
            } else {
                $this->assertStringContainsString(' data-parts="'.$parts.'"', $tags[1][0], $url);
            }
            $this->assertSame(1, substr_count($html, '<link rel="preload" as="script" href="/build/assets/studio-pages-test.js">'), $url);
            foreach (['window.__mkStudioScripts', 'var LAYERS', 'window.__mkVideoForm', 'The replies could not be loaded. Try again.</script>'] as $inline) {
                $this->assertStringNotContainsString($inline, $html, $url.': no inline copy');
            }
        }

        $myVideos = $this->actingAs($member)->get(route('user.videos.index'))->getContent();
        $this->assertMatchesRegularExpression('~<div class="avd-mk mk-page"\s+x-data="mkVideos"~', $myVideos);
        $this->assertStringNotContainsString('selected: [],', $myVideos, 'the component is not printed in the page');
        $comments = $this->actingAs($member)->get(route('user.comments.index'))->getContent();
        $this->assertMatchesRegularExpression('~<div class="avd-mk mk-page"\s+x-data="mkComments"~', $comments);

        $script = (string) file_get_contents(resource_path('js/member/studio-pages.js'));
        foreach (["window.Alpine.data('mkVideos'", "window.Alpine.data('mkComments'", "window.Alpine.data('mkVideoForm'", "document.addEventListener('alpine:init'",
            "if (window.__mkStudioBars) return;", "const LAYERS = '.mk-toasts, .back-to-top, .avd-daily-pill, [data-upload18-failed-notice], #video-ready-refresh-notice, .mk-bars-room';",
            "if (!window.__mkRepliesSheet) {", "const parts = String(config.parts || '')"] as $part) {
            $this->assertStringContainsString($part, $script);
        }
        $this->assertStringContainsString("'studio-pages': 'resources/js/member/studio-pages.js',", (string) file_get_contents(base_path('vite.config.ts')));
    }

    public function test_without_a_build_the_page_is_served_all_the_same(): void
    {
        $member = User::factory()->create();

        // the tests' own Vite double: no file, so no tag, and no error
        $html = $this->actingAs($member)->get(route('user.videos.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('studio-pages', $html);
    }

    private function fakeBuild(): void
    {
        $this->swap(Vite::class, new class extends Vite
        {
            public function asset($asset, $buildDirectory = null)
            {
                return $asset === 'resources/js/member/studio-pages.js' ? '/build/assets/studio-pages-test.js' : '';
            }

            public function __invoke($entrypoints, $buildDirectory = null)
            {
                return new HtmlString('');
            }
        });
    }
}
