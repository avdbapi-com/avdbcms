<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.2.3 round 3 (R3-050): the first Tab stop of every page of the site's layout was the site's name, and a keyboard
 * user went through the header (menu, search, random, Upload, bell, avatar) and, in the studio, the studio menu (about
 * 19 stops) before the page. Every page of components/layout.blade.php (public pages, member pages, the studio, the
 * error pages, the old admin) starts with "Skip to content" to its content (#main-container), shown while it has the
 * focus; the admin's own document (<x-admin.shell>) has its own (.ad-skip to #ad-main). In every language. Its
 * tabindex="0" puts it into Safari's default Tab order too (Tab there skips links unless "Press Tab to highlight each
 * item" is on).
 */
class SkipToContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_of_the_layout_starts_with_the_skip_link(): void
    {
        $member = User::factory()->create();
        config(['admin.ui' => 'v1']);
        $admin = User::factory()->admin()->create();

        $pages = [
            'home' => fn () => $this->get(route('pages.home')),
            'trend' => fn () => $this->get(route('pages.trend')),
            'sign in' => fn () => $this->get(route('login')),
            'a missing page (404)' => fn () => $this->get('/this-page-does-not-exist-r3-050'),
            'the studio' => fn () => $this->actingAs($member)->get(route('user.videos.index')),
            'account settings' => fn () => $this->actingAs($member)->get(route('user.edit')),
            'library' => fn () => $this->actingAs($member)->get(route('pages.library')),
            'the old admin' => fn () => $this->actingAs($admin)->get(route('admin.index')),
        ];

        foreach ($pages as $page => $request) {
            $html = (string) $request()->getContent();
            $this->assertSame(1, preg_match('#<body\b[^>]*>\s*(<[^>]+>[^<]*</a>)#', $html, $first), $page.': the body starts with a link');
            $this->assertSame('<a class="avd-skip-link" href="#main-container" tabindex="0">'.e(__('Skip to content')).'</a>', $first[1], $page);
            $this->assertSame(1, substr_count($html, 'class="avd-skip-link"'), $page.': once');
            $this->assertStringContainsString('id="main-container"', $html, $page.': its target');
        }
    }

    public function test_the_skip_link_speaks_the_pages_language(): void
    {
        // /ar/trend: public/index.php takes the language off the address and hands it over as DETECTED_LOCALE
        $_SERVER['DETECTED_LOCALE'] = 'ar';
        try {
            $html = $this->get(route('pages.trend'))->assertOk()->getContent();
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
        }

        $arabic = json_decode((string) file_get_contents(lang_path('ar.json')), true)['Skip to content'] ?? null;
        $this->assertIsString($arabic);
        $this->assertNotSame('Skip to content', $arabic);
        $this->assertStringContainsString('<a class="avd-skip-link" href="#main-container" tabindex="0">'.e($arabic).'</a>', $html);

        foreach (glob(lang_path('*.json')) as $file) {
            $this->assertArrayHasKey('Skip to content', json_decode((string) file_get_contents($file), true), basename($file));
        }
    }

    public function test_the_new_admin_keeps_its_own_skip_link(): void
    {
        config(['admin.ui' => 'v2']);
        $html = $this->actingAs(User::factory()->admin()->create())->get(route('admin.index'))->assertOk()->getContent();

        // (tabindex="0" as on the site's link: proposed to lane AD, proposed/R3-050_admin_skip_tabindex.patch)
        $this->assertMatchesRegularExpression('#<body\b[^>]*>\s*<a class="ad-skip" href="\#ad-main"(?: tabindex="0")?>#', $html);
        $this->assertMatchesRegularExpression('#<main id="ad-main"[^>]*tabindex="-1"#', $html);
        $this->assertStringNotContainsString('avd-skip-link', $html);
    }

    public function test_it_shows_on_focus_and_hands_the_focus_to_the_content(): void
    {
        $scss = (string) file_get_contents(resource_path('sass/header.scss'));
        // out of sight until it has the focus, then a box over the header's start corner (logical: mirrored in Arabic)
        $this->assertMatchesRegularExpression('/\.avd-skip-link:not\(:focus\) \{\s*position: absolute !important;\s*width: 1px;\s*height: 1px;\s*overflow: hidden;\s*clip: rect\(0 0 0 0\);/', $scss);
        $this->assertMatchesRegularExpression('/\.avd-skip-link:focus \{\s*position: fixed;\s*z-index: 1100;\s*inset-block-start: \.5rem;\s*inset-inline-start: \.5rem;/', $scss);
        $this->assertStringContainsString('min-block-size: 44px;', $scss);
        $this->assertStringContainsString('outline: 2px solid var(--avd-focus, currentColor);', $scss);
        $this->assertMatchesRegularExpression('/#main-container\[tabindex="-1"\]:focus \{\s*outline: 0;/', $scss);

        // the click (or Enter) focuses the content for that moment, without a #main-container in the address
        $core = (string) file_get_contents(resource_path('js/layout/core.ts'));
        $this->assertStringContainsString("document.querySelector<HTMLAnchorElement>('a.avd-skip-link')", $core);
        $this->assertStringContainsString('event.preventDefault();', $core);
        $this->assertStringContainsString("target.setAttribute('tabindex', '-1');", $core);
        $this->assertStringContainsString("target.addEventListener('blur', () => target.removeAttribute('tabindex'), {once: true});", $core);
        $this->assertStringContainsString('safely(initSkipLink);', $core);
    }
}
