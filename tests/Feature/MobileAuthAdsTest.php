<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileAuthAdsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.ads_enabled' => true,
            'site.ads_hide_for_premium' => false,
            'site.ad_onclick_enabled' => true,
            'site.ad_code_header' => '<div>header test ad</div>',
            'site.ad_code_footer' => '<div>footer test ad</div>',
            'site.ad_code_mobile' => '<div>mobile test ad</div>',
            'site.ad_code_onclick' => '<script>window.testOnclickAd = true;</script>',
        ]);
    }

    public function test_auth_entry_pages_do_not_render_ad_layers(): void
    {
        foreach ([
            'login' => [],
            'registration' => [],
            'password.forgot' => [],
        ] as $routeName => $parameters) {
            $this->get(route($routeName, $parameters))
                ->assertOk()
                ->assertDontSee('id="ad-slot-header"', false)
                ->assertDontSee('id="ad-slot-footer"', false)
                ->assertDontSee('id="ad-slot-mobile"', false)
                ->assertDontSee('id="ad-slot-onclick"', false)
                ->assertSee('enabled: false', false);
        }
    }

    public function test_public_content_layout_keeps_ad_layers_enabled(): void
    {
        $this->get(route('contact.show'))
            ->assertOk()
            ->assertSee('id="ad-slot-header"', false)
            ->assertSee('id="ad-slot-footer"', false)
            ->assertSee('id="ad-slot-mobile"', false)
            ->assertSee('id="ad-slot-onclick"', false)
            ->assertSee('enabled: true', false);
    }
}
