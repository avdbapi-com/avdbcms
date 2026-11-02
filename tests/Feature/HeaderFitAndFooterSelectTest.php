<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round 1 review (RV-FC-05): with both guest buttons in the look's capitals (R1-135), Imperial Salon's centred crest
 * cut the site's name to one to six letters at 1200-1440px in French, Spanish, German or Tagalog, and Cinema Night and
 * Swiss Bento cut it by 2-6px at 992-1366px (the brand zone took a share of every shortfall while the search had room).
 * The search gives way first (header.scss), then the bar's own links step out from the end while the name is cut or the
 * row runs off the screen (the layout's header fit; every link stays in the side menu).
 *
 * R1-073: the full footer's language select became a script-built control (TomSelect) when the page scrolled near it,
 * the long frame of a scroll gesture on a weak laptop. It is the browser's own select now, like the slim footer's,
 * drawn in the look's colours.
 */
class HeaderFitAndFooterSelectTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_layout_fits_the_header_links_around_the_site_name(): void
    {
        // only where the bar carries links: a public page of a look that shows them (Imperial Salon's crest), never the
        // look with a pinned side menu (the default look) nor the back-office bar
        $this->assertStringNotContainsString("var row=document.querySelector('.avd-header-inner')", $this->get(route('pages.trend').'?theme=tokyo_neon&theme_flavor=signature')->assertOk()->getContent());
        $member = \App\Models\User::factory()->create();
        $this->assertStringNotContainsString("var row=document.querySelector('.avd-header-inner')", $this->actingAs($member)->get(route('user.edit').'?theme=obsidian_gold&theme_flavor=signature')->assertOk()->getContent());
        auth()->logout();

        foreach (['theme=obsidian_gold&theme_flavor=signature', 'theme=obsidian_gold&theme_flavor=signature&theme_mode=light'] as $look) {
            $html = $this->get(route('pages.trend').'?'.$look)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, "var row=document.querySelector('.avd-header-inner')"), $look);
            $fit = substr($html, strpos($html, "var row=document.querySelector('.avd-header-inner')"), 2400);
            // after the header, before the page's content: it runs before the first paint
            $this->assertGreaterThan(strpos($html, 'avd-header-inner'), strpos($html, "var row=document.querySelector('.avd-header-inner')"));
            $this->assertLessThan(strpos($html, 'id="main-container"'), strpos($html, "var row=document.querySelector('.avd-header-inner')"));
            foreach ([
                "row.querySelector('.avd-hnav')",
                "classList.contains('active')?current:links",
                'text.scrollWidth>text.clientWidth+1',
                'row.scrollWidth>row.clientWidth+1',
                'links[i].hidden=true',
                'new ResizeObserver(later).observe(row)',
                "document.fonts.addEventListener('loadingdone',later)",
            ] as $part) {
                $this->assertStringContainsString($part, $fit, $part);
            }
        }

        // the script never reads as a tag to a text extractor: strip_tags() (assertSeeText) took "i<links.length" for a tag
        // and, with the quotes after it, swallowed the rest of the page
        $start = strpos($html, '<script>(function(){'."\n".'        var row=');
        $this->assertNotFalse($start);
        $body = substr($html, $start + 8, strpos($html, '</script>', $start) - $start - 8);
        $this->assertDoesNotMatchRegularExpression('#<[A-Za-z/!?]#', $body);
        // the keyboard shortcuts panel at the end of the body (its words since R1R-15)
        $this->assertStringContainsString(e(__('Glow around the player')), strip_tags($html), 'the end of the body is still text after the header');

        // Imperial Salon's crest bar carries the links the fit works on
        $imperial = $this->get(route('pages.trend').'?theme=obsidian_gold&theme_flavor=signature')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<nav class="avd-hnav d-none d-lg-flex"[^>]*>\s*<a#', $imperial);

        // the search box gives way before the brand zone (factor 1000 against 0.05)
        $scss = (string) file_get_contents(resource_path('sass/header.scss'));
        $this->assertMatchesRegularExpression('/\.avd-header-inner > search-bar \{\s*flex-shrink: 1000;\s*min-inline-size: 7\.5rem;/', $scss);
        $this->assertMatchesRegularExpression('/\.avd-header-inner > \.avd-header-brand-zone \{\s*flex-shrink: 0\.05;/', $scss);
    }

    public function test_the_footer_language_select_is_the_browsers_own(): void
    {
        $html = $this->get(route('pages.trend'))->assertOk()->getContent();

        // (round 2a, R2A-003: the language changes on an explicit choice only, data-avd-apply instead of an onchange
        // that navigated on the first arrow key; LanguageSelectExplicitChoiceTest)
        $this->assertSame(1, preg_match('#<select class="avd-footer-select" aria-label="[^"]+" data-native-select data-avd-apply>#', $html), 'the full footer\'s select');
        $this->assertStringNotContainsString('class="avd-footer-select" aria-label="'.e(__('Language')).'">', $html);

        // app.ts never builds a control for a select marked so (also on the added-nodes bus)
        $app = (string) file_get_contents(resource_path('js/app.ts'));
        $this->assertStringContainsString("'select.avd-footer-select',", $app);
        $this->assertStringContainsString(':not(.select-multiple):not([multiple]):not([data-native-select])', $app);

        // drawn in the look's colours: no system button, a chevron in the text colour, mirrored in Arabic
        $scss = (string) file_get_contents(resource_path('sass/enhancements.scss'));
        $this->assertMatchesRegularExpression('/select\.avd-footer-select \{\s*-webkit-appearance: none;\s*-moz-appearance: none;\s*appearance: none;/', $scss);
        $this->assertStringContainsString('background-image: linear-gradient(45deg, transparent 50%, currentColor 50%), linear-gradient(135deg, currentColor 50%, transparent 50%);', $scss);
        $this->assertMatchesRegularExpression('/html\[dir="rtl"\] select\.avd-footer-select \{\s*background-position: left 0\.8rem center, left 1\.15rem center;/', $scss);
    }
}
