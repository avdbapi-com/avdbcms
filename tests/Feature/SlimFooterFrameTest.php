<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round 1 (R1-111): the studio's and account pages' slim footer spanned the whole main column at 1920px (151px before
 * the page, its language select past the page's end) while the page above is centred at 1280 / 1180 / 880px. The
 * layout reads the page's frame from its content (.mk-page, .mk-page--form, .mk-page--narrow) and the footer keeps it
 * (avd-footer--frame-page | form | narrow; enhancements.scss gives each frame its width).
 */
class SlimFooterFrameTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_slim_footer_takes_the_frame_of_the_page_above_it(): void
    {
        $this->withoutVite();
        $member = User::factory()->create();

        foreach ([
            'page' => route('user.index'),
            'form' => route('user.edit'),
            'narrow' => route('user.notifications.index'),
        ] as $frame => $url) {
            $html = $this->actingAs($member)->get($url)->assertOk()->getContent();

            $this->assertSame(1, preg_match('#<footer class="([^"]*\bavd-footer--slim\b[^"]*)" id="site-footer">#', $html, $footer), $url.': one slim footer');
            $this->assertStringContainsString('avd-footer--frame-'.$frame, $footer[1], $url);
            foreach (array_diff(['page', 'form', 'narrow'], [$frame]) as $other) {
                $this->assertStringNotContainsString('avd-footer--frame-'.$other, $footer[1], $url);
            }
        }

        // a full footer (a public page) names no frame
        $public = $this->actingAs($member)->get(route('pages.trend'))->assertOk()->getContent();
        $this->assertStringNotContainsString('avd-footer--frame-', $public);
    }

    public function test_each_frame_has_the_width_of_its_page(): void
    {
        $scss = (string) file_get_contents(resource_path('sass/enhancements.scss'));

        // the footer's inner row is as wide as the page's frame (1280px by default), centred like .mk-page
        $this->assertStringContainsString('max-inline-size: calc(var(--avd-footer-frame, 1280px) + 2 * clamp(0.75rem, 1.75vw, 3rem));', $scss);
        $this->assertMatchesRegularExpression('/\.avd-footer--slim\.avd-footer--frame-form \{\s*--avd-footer-frame: 1180px;/', $scss);
        $this->assertMatchesRegularExpression('/\.avd-footer--slim\.avd-footer--frame-narrow \{\s*--avd-footer-frame: 880px;/', $scss);
    }
}
