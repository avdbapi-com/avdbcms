<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AdminPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin on old devices and without JavaScript (1.2.2). An owner's old device showed a blank dark page: the admin
 * must stay readable when its stylesheet or its script does not run there. The browser checks (pages with JavaScript
 * off, the admin with its stylesheet blocked, the built script scanned for syntax newer than the target) are in the
 * 1.2.2 lane F notes; these tests keep the pieces in place.
 */
class AdminOldBrowsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2']);
    }

    private function adminHtml(string $scheme = 'system'): string
    {
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'admin_theme', $scheme);

        return $this->actingAs($admin->fresh())->get(route('admin.index'))->assertOk()->getContent();
    }

    public function test_the_admin_head_sizes_its_icons_before_the_stylesheet_loads(): void
    {
        $html = $this->adminHtml();
        $head = substr($html, 0, (int) strpos($html, '</head>'));

        // Inline, before the stylesheet link: without it an unstyled page drew every icon at 300 x 150 and the
        // first screen stayed dark and empty.
        $style = strpos($head, '<style>.ad-i{width:18px;height:18px;');
        $this->assertNotFalse($style, 'the inline icon sizes are in the head');
        $this->assertStringContainsString('.ad-sprite{position:absolute;width:0;height:0;overflow:hidden}', $head);
        $this->assertStringContainsString('.ad-nav-sub.is-collapsed,.ad-flyout-title{display:none}', $head);

        $stylesheet = strpos($head, 'rel="stylesheet"');
        if ($stylesheet !== false) {
            $this->assertLessThan($stylesheet, $style, 'the stylesheet comes after the inline sizes and overrides them');
        }
    }

    public function test_the_colour_scheme_script_runs_on_browsers_without_match_media(): void
    {
        $html = $this->adminHtml('system');

        $this->assertStringContainsString("if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)", $html);
    }

    public function test_no_admin_content_waits_for_javascript(): void
    {
        $html = $this->adminHtml();
        $main = substr($html, (int) strpos($html, '<main id="ad-main"'));
        $main = substr($main, 0, (int) strpos($main, '</main>'));

        // The page header and the content are in the server's HTML, not hidden until a script shows them.
        $this->assertMatchesRegularExpression('/<h1[^>]*>/', $main);
        $this->assertDoesNotMatchRegularExpression('/<main[^>]*\shidden[\s>]/', $html);
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*\bad-(shell|main|content)\b[^"]*"[^>]*\shidden[\s>]/', $html);
        $this->assertStringNotContainsString('x-cloak', $main);
    }

    public function test_the_admin_script_loads_its_old_browser_fallbacks_first_and_the_build_lowers_its_syntax(): void
    {
        $root = dirname(__DIR__, 2);
        $admin = (string) file_get_contents($root.'/resources/admin/admin.ts');
        $fallbacks = (string) file_get_contents($root.'/resources/admin/old-browsers.ts');
        $vite = (string) file_get_contents($root.'/vite.config.ts');

        // The first import, so the fallbacks run before any other module's own code.
        preg_match('/^import\s[^;]*;/m', $admin, $first);
        $this->assertSame("import './old-browsers';", $first[0] ?? null);

        foreach (['requestSubmit', 'replaceChildren', "'submitter'", 'addListener'] as $feature) {
            $this->assertStringContainsString($feature, $fallbacks);
        }

        // One part failing (a missing browser feature) does not stop the others.
        $this->assertMatchesRegularExpression('/for \(const init of \[[^\]]*initShell[^\]]*\]\) \{\s*try \{\s*init\(\);/', $admin);

        // The admin's chunks are lowered to old browsers' syntax at build time; the public site keeps its target.
        $this->assertStringContainsString("const adminScriptTarget = ['chrome64', 'edge79', 'firefox67', 'safari12', 'ios12'];", $vite);
        $this->assertStringContainsString('adminOldBrowsers(adminScriptTarget),', $vite);
        $this->assertStringContainsString("order: 'post'", $vite);
    }

    public function test_the_fallbacks_cover_dialogs_the_form_data_submitter_and_picked_choices(): void
    {
        $fallbacks = (string) file_get_contents(dirname(__DIR__, 2).'/resources/admin/old-browsers.ts');

        // <dialog> before Safari 15.4 / Firefox 98: the search, Appearance > History and the Updates dialogs.
        $this->assertStringContainsString("const dialogProbe = document.createElement('dialog')", $fallbacks);
        foreach (['showModal: {', 'close: {', 'returnValue: {', 'open: {', 'data-ad-dialog-backdrop', "event.key !== 'Escape'"] as $part) {
            $this->assertStringContainsString($part, $fallbacks);
        }
        // `x instanceof HTMLDialogElement` (the Updates page) must not throw where the name does not exist.
        $this->assertStringContainsString('Object.defineProperty(DialogShim, Symbol.hasInstance', $fallbacks);
        $this->assertStringContainsString("value.tagName === 'DIALOG'", $fallbacks);

        // new FormData(form, submitter) before Safari 16.4 / Chrome 112: the row actions that send ids[] or path.
        $this->assertStringContainsString("void new FormData(document.createElement('form'), document.createElement('button'));", $fallbacks);
        $this->assertStringContainsString('button.form === form && button.name && !button.disabled', $fallbacks);
        $this->assertStringContainsString('FormDataWithSubmitter.prototype = NativeFormData.prototype;', $fallbacks);

        // :has() before Safari 15.4 / Chrome 105 / Firefox 121: the picked radio card, picker or swatch.
        $this->assertStringContainsString("document.querySelector(':has(*)');", $fallbacks);
        $this->assertStringContainsString("box.classList.toggle('is-picked', input.checked)", $fallbacks);

        $ui = (string) file_get_contents(dirname(__DIR__, 2).'/resources/admin/components/_ui.scss');
        $this->assertStringContainsString('.ad-rc.is-picked {', $ui);
        $this->assertStringContainsString('.ad-pick.is-picked,', $ui);
        $this->assertStringContainsString('.ad-appearance-form .ad-seg label.is-picked {', $ui);

        // RTL on browsers without inset-inline-start: right 50% (the left: 50% fallback put the dialog off screen).
        $this->assertStringContainsString('[dir="rtl"] .ad-dialog { left: auto; right: 50%; transform: translateX(50%); }', $ui);
        $this->assertStringContainsString('[dir="rtl"] .ad-palette { left: auto; right: 50%; transform: translateX(50%); }', $ui);
    }

    public function test_old_views_mark_browsers_without_the_is_selector_and_keep_their_light_text_readable(): void
    {
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'admin_theme', 'light');
        $admin = $admin->fresh();
        $script = "<script>try{document.querySelector(':is(html)')}catch(e){document.documentElement.className+=' ad-no-is'}</script>";

        // An old view (the System page): the mark is set in the head, before the first paint.
        $old = $this->actingAs($admin)->get(route('admin.system.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<div class="ad-legacy">', $old);
        $head = substr($old, 0, (int) strpos($old, '</head>'));
        $this->assertStringContainsString($script, $head);

        // A page of the new admin does not need it.
        $new = $this->actingAs($admin)->get(route('admin.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString("document.querySelector(':is(html)')", $new);

        $legacy = (string) file_get_contents(dirname(__DIR__, 2).'/resources/admin/components/_legacy.scss');
        $this->assertStringContainsString('html.ad-no-is[data-bs-theme="light"] .ad-legacy {', $legacy);
        // The dark inline panels and the amber fill's headings: selector lists, which every browser keeps.
        $this->assertStringContainsString(".ad-legacy [style*=\"rgba(15, 23, 42\"],\n.ad-legacy [style*=\"#0f172a\"] {", $legacy);
        $this->assertStringContainsString(".ad-legacy .bg-warning,\n.ad-legacy .bg-warning h1,", $legacy);

        // The build keeps the fallback as written (no :is() added back by the minifier).
        $manifest = dirname(__DIR__, 2).'/public/build/manifest.json';
        $entry = is_file($manifest) ? (json_decode((string) file_get_contents($manifest), true)['resources/admin/admin.scss']['file'] ?? null) : null;

        if (is_string($entry) && is_file(dirname(__DIR__, 2).'/public/build/'.$entry)) {
            $css = (string) file_get_contents(dirname(__DIR__, 2).'/public/build/'.$entry);
            $this->assertStringContainsString('html.ad-no-is[data-bs-theme=light] .ad-legacy .text-white{color:var(--text)!important}', $css);
            $this->assertStringContainsString('.ad-legacy [style*="rgba(15, 23, 42"],.ad-legacy [style*="#0f172a"]{', $css);
            $this->assertStringContainsString('.ad-rc.is-picked{', $css);
        }
    }

    public function test_the_appearance_pictures_fit_the_theme_thumbs_budget(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root.'/scripts/theme_thumbs.js');

        $this->assertMatchesRegularExpression("/const BUDGET = Number\\(arg\\('budget', String\\(3\\.5 \\* 1024 \\* 1024\\)\\)\\);/", $script);

        $manifest = json_decode((string) file_get_contents($root.'/public/images/admin/looks/manifest.json'), true);
        $this->assertIsArray($manifest);
        $total = array_sum(array_map(static fn (array $file): int => (int) ($file['bytes'] ?? 0), $manifest['files'] ?? []));

        $this->assertGreaterThan(0, $total);
        $this->assertLessThanOrEqual((int) (3.5 * 1024 * 1024), $total, 'the shipped Appearance pictures fit the 3.5 MB budget');
    }
}
