<?php

namespace Tests\Unit;

use App\Support\Color\Contrast;
use App\Support\Color\Oklch;
use App\Support\ThemeTokens;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Theme collection v2 token build: `php artisan avd:themes:build` writes resources/css/themes/<id>.css and
 * <id>/<flavor>.css from the catalogue and the colour engine. The reference derivation (pageFit off) must reproduce
 * the redesign's reference token files (tests/Fixtures/theme_tokens_reference.json.gz = spec/theme/tokens/*.css with
 * production selectors) to within one sRGB step per channel; the shipped derivation differs from it only where an
 * ink is fitted to the colours the page backdrop paints behind text and by the real-page bar and card tokens; the
 * committed files must be exactly what the command writes.
 */
class ThemeTokensBuildTest extends TestCase
{
    private const RTL_THEMES = ['amazon_rainforest', 'cyber_gladiator', 'overwatch_pulse', 'razer_poison', 'shibuya_rain'];

    /** The tokens the page-backdrop fit may move: the inks and the tokens that carry them. */
    private const PAGE_FIT_TOKENS = [
        '--avd-brand-ink', '--avd-focus', '--avd-brand-hot', '--avd-kicker-color', '--avd-section-prefix-color', '--avd-icon-color',
        '--avd-sidebar-active-text', '--avd-sidebar-active-bar', '--avd-hover-glow', '--avd-display-fill', '--avd-card-hover-border',
        '--avd-accent-ink', '--avd-muted', '--avd-faint', '--avd-sidebar-muted',
        '--avd-success', '--avd-success-rgb', '--avd-warning', '--avd-warning-rgb', '--avd-danger', '--avd-danger-rgb', '--avd-info', '--avd-info-rgb',
        '--bs-primary', '--bs-primary-rgb', '--bs-link-color', '--bs-link-hover-color', '--bs-secondary-color', '--bs-tertiary-color',
        '--bs-success', '--bs-success-rgb', '--bs-warning', '--bs-warning-rgb', '--bs-danger', '--bs-danger-rgb', '--bs-info', '--bs-info-rgb',
    ];

    // Real-page tokens that are not inks: the transparent bar off a hero + its overlay tokens, the solid surface
    // style's colour band and frameless card (ThemeTokens::build, $pageFit).
    private const REAL_PAGE_TOKENS = [
        '--avd-header-bg', '--avd-header-text', '--avd-header-overlay-bg', '--avd-header-overlay-text', '--avd-header-solid-bg',
        '--avd-card-solid-bg', '--avd-card-body-pad',
    ];

    private ?string $tmp = null;

    protected function tearDown(): void
    {
        if ($this->tmp !== null) {
            File::deleteDirectory($this->tmp);
        }

        parent::tearDown();
    }

    public function test_the_catalogue_has_the_32_themes_and_174_flavors(): void
    {
        $themes = ThemeTokens::themes();

        $this->assertCount(32, $themes);
        $this->assertSame(174, array_sum(array_map(fn (array $theme): int => count($theme['flavors']), $themes)));
        $this->assertArrayHasKey(ThemeTokens::DEFAULT_THEME, $themes);
    }

    public function test_tokens_match_the_reference_token_files(): void
    {
        $reference = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__).'/Fixtures/theme_tokens_reference.json.gz')), true, 512, JSON_THROW_ON_ERROR)['themes'];
        $failures = [];
        $tokens = 0;

        $this->assertSame(array_keys($reference), array_keys(array_intersect_key($reference, ThemeTokens::themes())));
        $this->assertCount(32, $reference);

        foreach ($reference as $id => $blocks) {
            $got = ThemeTokens::blocks(ThemeTokens::theme($id), pageFit: false);

            if (array_keys($got) !== array_keys($blocks)) {
                $failures[] = "{$id}: blocks ".json_encode(array_keys($got)).' want '.json_encode(array_keys($blocks));

                continue;
            }

            foreach ($blocks as $selector => $want) {
                if (array_keys($got[$selector]) !== array_keys($want)) {
                    $failures[] = "{$id} {$selector}: token names or order differ";
                }

                foreach ($want as $name => $value) {
                    $tokens++;

                    if (! $this->sameValue($got[$selector][$name] ?? null, $value)) {
                        $failures[] = "{$id} {$selector} {$name}: ".json_encode($got[$selector][$name] ?? null)." want {$value}";
                    }
                }
            }
        }

        $this->assertGreaterThan(23000, $tokens);
        $this->assertSame([], array_slice($failures, 0, 30), count($failures).' token differences');
    }

    public function test_inks_read_on_the_colours_the_page_backdrop_paints(): void
    {
        $failures = [];
        $changed = [];

        foreach (ThemeTokens::themes() as $id => $theme) {
            foreach ($theme['flavors'] as $flavor) {
                $flavor = ThemeTokens::flavor($theme, $flavor['id']);

                foreach (['dark', 'light'] as $mode) {
                    foreach (ThemeTokens::checks($theme, $mode, $flavor) as $row) {
                        if (str_contains($row['check'], '/backdrop ') && ! $row['ok']) {
                            $failures[] = "{$id}/{$flavor['id']}/{$mode} {$row['check']}: {$row['ratio']}";
                        }
                    }

                    $reference = ThemeTokens::derive($theme, $mode, $flavor, pageFit: false);

                    foreach (ThemeTokens::derive($theme, $mode, $flavor) as $name => $value) {
                        if (($reference[$name] ?? null) !== $value) {
                            $changed[$name] = true;
                        }
                    }
                }
            }
        }

        $this->assertSame([], array_slice($failures, 0, 20), count($failures).' inks below 4.5:1 on a colour of the page backdrop');
        // Only inks move (Liquid Glass light on its peach mesh stop, Fairy Floss dark under its glow...), plus the
        // real-page bar and card tokens: fills, surfaces, borders, shapes and fonts stay exactly the reference's.
        $this->assertSame([], array_values(array_diff(array_keys($changed), [...self::PAGE_FIT_TOKENS, ...self::REAL_PAGE_TOKENS])));
        $this->assertArrayHasKey('--avd-brand-ink', $changed);
        // No backdrop: the real-page derivation is the reference plus, at most, real-page tokens of its own.
        $real = ThemeTokens::derive(ThemeTokens::theme('broadsheet'), 'light');
        $reference = ThemeTokens::derive(ThemeTokens::theme('broadsheet'), 'light', pageFit: false);
        $this->assertSame($reference, array_intersect_key($real, $reference));
        $this->assertSame([], array_values(array_diff(array_keys(array_diff_key($real, $reference)), self::REAL_PAGE_TOKENS)));
    }

    public function test_phase_b_inks_read_where_the_page_glows_overlap_and_phase_a_keeps_its_signed_off_colours(): void
    {
        // Where page glows overlap on screen, or stack on the backdrop's gradient floor, the backdrop gets lighter (dark
        // mode) or darker (light mode) than any single stop: Interstellar's danger ink read 4.24-4.32:1 on a phone,
        // Outrun's inks ~4.0 on its horizon glow over the floor. Phase B inks are fitted to that point too.
        $bad = [];
        $checked = 0;

        foreach (ThemeTokens::themes() as $id => $theme) {
            foreach ($theme['flavors'] as $flavor) {
                $flavor = ThemeTokens::flavor($theme, $flavor['id']);

                foreach (['dark', 'light'] as $mode) {
                    $real = ThemeTokens::derive($theme, $mode, $flavor);

                    if (in_array($id, ThemeTokens::PHASE_A_THEMES, true)) {
                        continue;
                    }

                    $peak = (new \ReflectionMethod(ThemeTokens::class, 'backdropPeak'))->invoke(null, $real['--avd-page-backdrop'], $real['--avd-bg'], $mode === 'dark');

                    foreach (['--avd-danger', '--avd-success', '--avd-warning', '--avd-info', '--avd-muted', '--avd-brand-ink', '--avd-accent-ink'] as $ink) {
                        $checked++;

                        if (Contrast::contrast($real[$ink], $peak) < 4.5) {
                            $bad[] = "{$id}/{$flavor['id']}/{$mode} {$ink} ".round(Contrast::contrast($real[$ink], $peak), 2)." on {$peak}";
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(1700, $checked);
        $this->assertSame([], array_slice($bad, 0, 20), count($bad).' Phase B inks below 4.5:1 on the backdrop peak');

        // The peak of the stacked glows: Interstellar orion (two glows, no floor) and Outrun (horizon glow over its floor).
        $peak = fn (string $id, string $mode, ?string $flavor = null): string => (new \ReflectionMethod(ThemeTokens::class, 'backdropPeak'))->invoke(
            null, ($t = ThemeTokens::derive(ThemeTokens::theme($id), $mode, ThemeTokens::flavor(ThemeTokens::theme($id), $flavor)))['--avd-page-backdrop'], $t['--avd-bg'], $mode === 'dark');
        $orion = ThemeTokens::derive(ThemeTokens::theme('interstellar'), 'dark', ThemeTokens::flavor(ThemeTokens::theme('interstellar'), 'orion'));
        $this->assertGreaterThan(Oklch::relativeLuminance($orion['--avd-bg']), Oklch::relativeLuminance($peak('interstellar', 'dark', 'orion')));
        $this->assertGreaterThanOrEqual(4.5, Contrast::contrast($orion['--avd-danger'], $peak('interstellar', 'dark', 'orion')));
        $outrun = ThemeTokens::derive(ThemeTokens::theme('synthwave_sunset'), 'dark');
        $this->assertGreaterThanOrEqual(4.5, Contrast::contrast($outrun['--avd-brand-ink'], $peak('synthwave_sunset', 'dark')));
        // No glow: the page background itself.
        $this->assertSame('#123456', (new \ReflectionMethod(ThemeTokens::class, 'backdropPeak'))->invoke(null, 'none', '#123456', true));

        // Phase A (signed off at Gate A, incl. the D1 default tokyo_neon) keeps the colours it was accepted with: the
        // backdrop peak never takes part in its fitting, whatever its glows do (tokyo_neon dark: the Gate A ink).
        foreach (ThemeTokens::PHASE_A_THEMES as $id) {
            $this->assertNotNull(ThemeTokens::theme($id), $id);
        }

        $this->assertSame(['#f93f62', '#c1073f'], [
            ThemeTokens::derive(ThemeTokens::theme('tokyo_neon'), 'dark')['--avd-brand-ink'],
            ThemeTokens::derive(ThemeTokens::theme('tokyo_neon'), 'light')['--avd-brand-ink'],
        ]);
    }

    public function test_real_pages_get_a_readable_bar_off_the_hero_and_keep_frameless_cards_frameless(): void
    {
        $oled = ThemeTokens::theme('pure_oled_black');

        foreach (['dark', 'light'] as $mode) {
            $real = ThemeTokens::derive($oled, $mode);
            $reference = ThemeTokens::derive($oled, $mode, pageFit: false);

            // Off a hero the transparent bar is the page colour with the page ink (Gate A: white links on a white bar
            // in light mode); over the hero layout-theme-a.css reads the overlay tokens, a scrim with white ink.
            $this->assertSame([$real['--avd-bg'], $real['--avd-text']], [$real['--avd-header-bg'], $real['--avd-header-text']], $mode);
            $this->assertStringStartsWith('linear-gradient(180deg, rgba(0,0,0,.8) 0%', $real['--avd-header-overlay-bg']);
            $this->assertSame('#ffffff', $real['--avd-header-overlay-text']);
            $this->assertGreaterThanOrEqual(4.5, Contrast::contrast($real['--avd-header-text'], $real['--avd-header-bg']));
            // The reference derivation keeps the spec's scrim tokens.
            $this->assertSame('#ffffff', $reference['--avd-header-text']);
            $this->assertArrayNotHasKey('--avd-header-overlay-bg', $reference);
            // A card without a fill stays frameless under the solid surface style.
            $this->assertSame('transparent', $real['--avd-card-bg']);
            $this->assertSame('transparent', $real['--avd-card-solid-bg']);
        }

        // ... and a frameless card's body keeps its last line clear of large bottom corners (Velvet Boudoir: 18px).
        $velvet = ThemeTokens::derive(ThemeTokens::theme('rose_gold_velvet'), 'dark');
        $this->assertSame('18px', $velvet['--avd-r-card']);
        $this->assertSame('10px 2px calc(18px * var(--avd-radius-k, 1) * .55) 2px', $velvet['--avd-card-body-pad']);
        $this->assertSame('10px 2px 0', ThemeTokens::derive(ThemeTokens::theme('rose_gold_velvet'), 'dark', pageFit: false)['--avd-card-body-pad']);
        $this->assertSame('10px 2px 0', ThemeTokens::derive(ThemeTokens::theme('pure_oled_black'), 'dark')['--avd-card-body-pad']);

        // A designed colour band keeps its colour under the solid surface style; see-through bars and filled cards
        // take the solid surface (no token: layout-theme-a.css falls back to --avd-surface-solid).
        foreach (['vaporwave_mall', 'neo_pop'] as $id) {
            $band = ThemeTokens::derive(ThemeTokens::theme($id), 'light');
            $this->assertSame($band['--avd-header-bg'], $band['--avd-header-solid-bg'], $id);
        }

        $glass = ThemeTokens::derive(ThemeTokens::theme('lavender_cloud'), 'light');
        $this->assertArrayNotHasKey('--avd-header-solid-bg', $glass);
        $this->assertArrayNotHasKey('--avd-card-solid-bg', $glass);
    }

    public function test_build_command_writes_exactly_the_committed_files(): void
    {
        $this->tmp = storage_path('framework/testing/theme-tokens-'.uniqid());

        $this->assertSame(0, Artisan::call('avd:themes:build', ['--path' => $this->tmp]));

        // One file per theme and one per other colour style (174 colour styles, 32 of them signatures).
        $written = [...glob($this->tmp.'/*.css'), ...glob($this->tmp.'/*/*.css')];
        $this->assertCount(174, $written);
        $this->assertCount(32, glob($this->tmp.'/*.css'));
        // Hero decoration sources (css/themes/hero-decos) are not token files.
        $committed = array_filter(
            [...glob(resource_path('css/themes/*.css')), ...glob(resource_path('css/themes/*/*.css'))],
            fn (string $file): bool => basename(dirname($file)) !== ThemeTokens::HERO_DECO_DIR
        );
        $this->assertCount(174, $committed, 'no stale token file is left in resources/css/themes');

        foreach ($written as $file) {
            $path = substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', $this->tmp)) + 1);
            $this->assertFileExists(resource_path('css/themes/'.$path));
            $this->assertSame(file_get_contents(resource_path('css/themes/'.$path)), file_get_contents($file), $path.' is out of date: run php artisan avd:themes:build');
        }

        $this->assertSame(0, Artisan::call('avd:themes:build', ['--check' => true]));
        $this->assertSame(0, Artisan::call('avd:themes:build', ['--check' => true, '--path' => $this->tmp]));

        file_put_contents($this->tmp.'/tokyo_neon/coral.css', '/* stale */');
        $this->assertSame(1, Artisan::call('avd:themes:build', ['--check' => true, '--path' => $this->tmp]));
        $this->assertSame(1, Artisan::call('avd:themes:build', ['--theme' => ['no_such_theme'], '--path' => $this->tmp]));
    }

    public function test_the_files_of_a_theme_hold_every_block_once(): void
    {
        foreach (ThemeTokens::themes() as $id => $theme) {
            $blocks = ThemeTokens::blocks($theme);
            $seen = [];

            foreach (ThemeTokens::files($theme) as $path => $css) {
                $flavor = $path === $id.'.css' ? null : basename($path, '.css');
                $this->assertContains($path, [$id.'.css', $id.'/'.$flavor.'.css'], $id);

                preg_match_all('/^(html\[data-bs-theme[^{]+) \{$/m', $css, $selectors);

                foreach ($selectors[1] as $selector) {
                    // The theme file holds the signature (and RTL) blocks; a colour style file only its own blocks.
                    $this->assertSame($flavor, preg_match('/\[data-avd-flavor="([a-z0-9_]+)"\]/', $selector, $m) === 1 ? $m[1] : null, "{$path}: {$selector}");
                    $this->assertStringContainsString(ThemeTokens::cssBlock($selector, $blocks[$selector]), $css);
                    $seen[] = $selector;
                }
            }

            $this->assertEqualsCanonicalizing(array_keys($blocks), $seen, $id);
        }
    }

    public function test_a_hero_decoration_ships_at_the_end_of_its_themes_token_file_only(): void
    {
        $this->tmp = storage_path('framework/testing/hero-decos-'.uniqid());
        File::ensureDirectoryExists($this->tmp);
        $deco = (string) ThemeTokens::theme('shibuya_rain')['theme_owned_recipe']['hero_deco'];
        $css = "html[data-avd-hero-deco=\"{$deco}\"][data-avd-theme] .avd-hud { gap: 18px; }\n"
            ."@media (max-width: 767.98px) {\n    html[data-avd-hero-deco=\"{$deco}\"][data-avd-theme] .avd-hud { gap: 8px; }\n}\n";
        file_put_contents($this->tmp."/{$deco}.css", $css);

        $plain = ThemeTokens::files(ThemeTokens::theme('shibuya_rain'));
        $files = ThemeTokens::files(ThemeTokens::theme('shibuya_rain'), $this->tmp);
        $this->assertStringStartsWith($plain['shibuya_rain.css'], $files['shibuya_rain.css']);
        $this->assertStringEndsWith(trim($css)."\n", $files['shibuya_rain.css']);
        // The colour style files and the other themes' files stay token blocks only.
        $this->assertSame(array_diff_key($plain, ['shibuya_rain.css' => true]), array_diff_key($files, ['shibuya_rain.css' => true]));
        $this->assertSame(ThemeTokens::files(ThemeTokens::theme('tokyo_neon')), ThemeTokens::files(ThemeTokens::theme('tokyo_neon'), $this->tmp));

        // The inline form (AVD_THEME_CSS_INLINE) keeps the decoration with the page's own token blocks.
        $inline = \App\Support\ThemeCss::themeBlocks($files['shibuya_rain.css'], ['dark'], 'signature');
        $this->assertStringContainsString('html[data-bs-theme="dark"]{--avd-bg:', $inline);
        $this->assertStringNotContainsString('data-bs-theme="light"', $inline);
        $this->assertStringContainsString("@media (max-width: 767.98px){html[data-avd-hero-deco=\"{$deco}\"][data-avd-theme] .avd-hud { gap: 8px; }}", $inline);

        // Only rules keyed on the decoration and the theme can ship (the build refuses the rest): the token file loads
        // before layout-theme-b.css, so a one-attribute key would lose to a shared hero rule with the same chain.
        $prefix = 'html[data-avd-hero-deco="'.$deco.'"][data-avd-theme]';
        $this->assertSame([], ThemeTokens::heroDecoProblems($deco, $css));
        $this->assertSame(['not under '.$prefix.': .avd-hud img'], ThemeTokens::heroDecoProblems($deco, $css.'.avd-hud img { width: 100%; }'));
        $this->assertSame(['not under '.$prefix.': html[data-avd-hero-deco="'.$deco.'"] .avd-hud img'], ThemeTokens::heroDecoProblems($deco, $css.'html[data-avd-hero-deco="'.$deco.'"] .avd-hud img { width: 100%; }'));
        $this->assertSame(['unbalanced braces'], ThemeTokens::heroDecoProblems($deco, $css.'}'));
        $this->assertNull(ThemeTokens::heroDecoCss('no_such_deco', $this->tmp));
        // A selector list inside :is() (the language reset) is one selector; only top-level commas split.
        $this->assertSame([], ThemeTokens::heroDecoProblems($deco, $prefix.':is([lang|="ar"], [lang|="hi"]) .avd-hud b, '.$prefix.' [data-x="a,b"] { letter-spacing: 0; }'));
        $this->assertSame(['not under '.$prefix.': [lang|="hi"] .avd-hud b'], ThemeTokens::heroDecoProblems($deco, $prefix.' .a, [lang|="hi"] .avd-hud b { letter-spacing: 0; }'));
    }

    public function test_the_phase_b_hero_decorations_ship_with_their_themes_only(): void
    {
        $owners = [];

        foreach (ThemeTokens::themes() as $id => $theme) {
            $owners[(string) ($theme['theme_owned_recipe']['hero_deco'] ?? '')][] = $id;
        }

        $files = glob(resource_path('css/themes/'.ThemeTokens::HERO_DECO_DIR.'/*.css')) ?: [];
        $this->assertGreaterThanOrEqual(21, count($files), 'the Phase B hero decorations');
        $layoutCss = file_get_contents(resource_path('css/layout-theme-a.css')).file_get_contents(resource_path('css/layout-theme-b.css'));
        $tokenFiles = [];

        foreach (ThemeTokens::themes() as $id => $theme) {
            $tokenFiles[$id] = ThemeTokens::files($theme)[$id.'.css'];
        }

        foreach ($files as $file) {
            $deco = basename($file, '.css');
            $css = (string) file_get_contents($file);
            $this->assertSame([], ThemeTokens::heroDecoProblems($deco, $css), $deco);
            // Not in the shared theme CSS any more: it loads with the token file of the theme that names it only.
            $this->assertStringNotContainsString('data-avd-hero-deco="'.$deco.'"', $layoutCss, $deco);

            foreach ($tokenFiles as $id => $themeFile) {
                in_array($id, $owners[$deco] ?? [], true)
                    ? $this->assertStringEndsWith(trim($css)."\n", $themeFile, "{$deco} ends the {$id} token file")
                    : $this->assertStringNotContainsString('data-avd-hero-deco="'.$deco.'"', $themeFile, "{$deco} is not in {$id}");
            }
        }

        // The Phase A decorations stay in layout-theme-b (every page that can show them loads it).
        foreach (['ticker', 'billboard_full', 'billboard_inset', 'bento', 'split_glass'] as $phaseA) {
            $this->assertFileDoesNotExist(resource_path('css/themes/'.ThemeTokens::HERO_DECO_DIR.'/'.$phaseA.'.css'));
        }
    }

    public function test_the_token_css_of_a_page_stays_within_the_budget(): void
    {
        // What a page loads: the theme file and the largest colour style file, minified like the build, gzip -9.
        $gzip = fn (string $css): int => strlen((string) gzencode((string) preg_replace(['#/\*.*?\*/#s', '/\s*([{};:,])\s*/', '/\s+/'], ['', '$1', ' '], $css), 9));
        $pages = [];

        foreach (ThemeTokens::themes() as $id => $theme) {
            $files = ThemeTokens::files($theme);
            $themeFile = $gzip($files[$id.'.css']);
            unset($files[$id.'.css']);
            $pages[$id] = $themeFile + max([0, ...array_map($gzip, $files)]);
            $this->assertLessThanOrEqual(3400, $themeFile, "{$id}: theme file");
        }

        sort($pages);
        // build_spec WP1 speed: largest <= 8.6 KB, median <= 3.4 KB (the theme CSS budget then holds for every theme).
        $this->assertLessThanOrEqual(8.6 * 1024, max($pages));
        $this->assertLessThanOrEqual(3.4 * 1024, $pages[intdiv(count($pages), 2)]);
    }

    public function test_token_files_are_keyed_on_production_selectors(): void
    {
        $css = (string) file_get_contents(resource_path('css/themes/tokyo_neon.css'));

        $this->assertStringNotContainsString(':root[data-mode', $css);
        $this->assertStringNotContainsString('[data-flavor=', $css);
        $this->assertStringContainsString("html[data-bs-theme=\"dark\"] {\n  --avd-bg: #0b0e15;", $css);
        $this->assertStringContainsString('html[data-bs-theme="light"] {', $css);
        // The colour styles live in their own files, which a page loads only when it uses that style.
        $this->assertStringNotContainsString('data-avd-flavor', $css);
        $coral = (string) file_get_contents(resource_path('css/themes/tokyo_neon/coral.css'));
        $this->assertStringContainsString('html[data-bs-theme="dark"][data-avd-flavor="coral"] {', $coral);
        $this->assertStringContainsString('html[data-bs-theme="light"][data-avd-flavor="coral"] {', $coral);
        // demo1 parity (owner decision D1): white labels on the #ea094e -> #7928ca gradient.
        $this->assertStringContainsString('--avd-brand-fill: #ea094e;', $css);
        $this->assertStringContainsString('--avd-on-brand: #ffffff;', $css);
        $this->assertStringContainsString('--avd-btn-bg: linear-gradient(135deg, #ea094e 0%, #7928ca 100%);', $css);
        // Audit G3: the five compatibility names the player CSS reads are defined.
        foreach (['--avd-primary:', '--avd-primary-rgb:', '--avd-surface-rgb:', '--avd-dark-surface-rgb:', '--avd-surface-card:', '--avd-cyan:', '--avd-cyan-rgb:'] as $name) {
            $this->assertStringContainsString($name, $css);
        }
    }

    public function test_rtl_blocks_mirror_asymmetric_shapes(): void
    {
        foreach (ThemeTokens::themes() as $id => $theme) {
            $rtl = array_filter(array_keys(ThemeTokens::blocks($theme)), fn (string $selector): bool => str_contains($selector, '[dir="rtl"]'));
            $this->assertSame(in_array($id, self::RTL_THEMES, true), $rtl !== [], "{$id} RTL blocks");
        }

        // x mirrored (0 <-> 100%, Npx <-> calc(100% - Npx)), points reversed to keep the winding.
        $this->assertSame(
            ['--avd-btn-clip' => 'polygon(100% 10px, 100% 100%, 10px 100%, 0 calc(100% - 10px), 0 0, calc(100% - 10px) 0)'],
            ThemeTokens::rtlMirror(['--avd-btn-clip' => 'polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px)'])
        );
        $this->assertSame(['--avd-r-card' => '4px 18px 4px 18px'], ThemeTokens::rtlMirror(['--avd-r-card' => '18px 4px 18px 4px', '--avd-r-chip' => '999px']));
        // No shape, single radii and symmetric pairs need no RTL value.
        $this->assertSame([], ThemeTokens::rtlMirror(['--avd-card-clip' => 'none', '--avd-r-thumb' => '10px', '--avd-r-badge' => '6px 6px']));
    }

    public function test_resolve_accepts_v2_ids_and_the_120_old_ids(): void
    {
        $this->assertSame(['theme' => 'tokyo_neon', 'flavor' => 'signature'], ThemeTokens::resolve('tokyo_neon'));
        $this->assertSame(['theme' => 'tokyo_neon', 'flavor' => 'coral'], ThemeTokens::resolve('tokyo_neon', 'coral'));
        $this->assertSame(['theme' => 'tokyo_neon', 'flavor' => 'signature'], ThemeTokens::resolve('tokyo_neon', 'not_a_flavor'));
        $this->assertSame(['theme' => 'daylight_paper', 'flavor' => 'signature'], ThemeTokens::resolve('daylight_paper', ''));
        // An old id brings its own flavor (the factory default cyberpunk_neon is the coral Midnight Spotlight).
        $this->assertSame(['theme' => 'tokyo_neon', 'flavor' => 'coral'], ThemeTokens::resolve('cyberpunk_neon'));
        $this->assertSame(['theme' => 'tokyo_neon', 'flavor' => 'coral'], ThemeTokens::resolve('cyberpunk_neon', 'signature'));
        $this->assertSame(['theme' => 'tokyo_neon', 'flavor' => 'signature'], ThemeTokens::resolve('laser_grid'));
        $this->assertSame(['theme' => 'tokyo_neon', 'flavor' => 'signature'], ThemeTokens::resolve('no_such_theme'));
        $this->assertSame(['theme' => 'tokyo_neon', 'flavor' => 'signature'], ThemeTokens::resolve(null));
        $this->assertTrue(ThemeTokens::isKnown('sakura_blossom'));
        $this->assertFalse(ThemeTokens::isKnown('no_such_theme'));
    }

    public function test_owner_colours_run_through_the_engine(): void
    {
        // The flavor's own colour is not an override.
        $this->assertSame('', ThemeTokens::ownerOverrides(['primary_color' => '#FF0055'], 'tokyo_neon', 'signature'));
        $this->assertSame('', ThemeTokens::ownerOverrides(['primary_color' => 'not a colour'], 'tokyo_neon', 'signature'));

        // The old factory coral keeps white labels on a fitted fill; brand text is fitted per mode.
        $css = ThemeTokens::ownerOverrides(['primary_color' => '#ff3d61'], 'tokyo_neon', 'signature');
        $this->assertStringContainsString('html[data-bs-theme="dark"][data-avd-flavor]{', $css);
        $this->assertStringContainsString('html[data-bs-theme="light"][data-avd-flavor]{', $css);
        $this->assertStringContainsString('--avd-brand:#ff3d61;', $css);
        $this->assertStringContainsString('--avd-primary:#ff3d61;', $css);
        preg_match('/--avd-brand-fill:(#[0-9a-f]{6})/', $css, $fill);
        $this->assertGreaterThanOrEqual(4.5, Contrast::contrast('#ffffff', $fill[1]));
        $this->assertLessThanOrEqual(1536, strlen(ThemeTokens::ownerOverrides(['primary_color' => '#ff3d61'], 'tokyo_neon', 'signature', ['dark'])));

        // brand_contrast_autofix off: the exact colour on fills.
        $exact = ThemeTokens::ownerOverrides(['primary_color' => '#ff3d61', 'brand_contrast_autofix' => '0'], 'tokyo_neon', 'signature', ['dark']);
        $this->assertStringContainsString('--avd-brand-fill:#ff3d61;', $exact);
        $this->assertStringContainsString('--avd-brand-ink:#ff3d61;', $exact);
        // Everything built from the fill follows it (gradient buttons and chips, badges).
        $this->assertStringContainsString('--avd-btn-bg:linear-gradient(135deg, #ff3d61 0%, #7928ca 100%);', $exact);
        $this->assertStringContainsString('--avd-badge-bg:#ff3d61;', $exact);
        $this->assertStringNotContainsString('light', $exact);

        // A mode-specific background only touches its own mode.
        $dark = ThemeTokens::ownerOverrides(['dark_bg_color' => '#000000'], 'tokyo_neon', 'signature');
        $this->assertStringContainsString('html[data-bs-theme="dark"][data-avd-flavor]{--avd-bg:#000000;', $dark);
        $this->assertStringNotContainsString('data-bs-theme="light"', $dark);
    }

    public function test_fonts_respect_the_budget(): void
    {
        foreach (ThemeTokens::themes() as $id => $theme) {
            $fonts = ThemeTokens::fonts($theme);

            $this->assertSame($theme['typography']['pairing'], $fonts['pairing'], $id);
            $this->assertLessThanOrEqual(2, count($fonts['families']), "{$id}: at most 2 families");
            $this->assertSame([], $fonts['tokens'], "{$id}: the theme's own pairing needs no font tokens");
            $this->assertStringStartsWith('https://fonts.googleapis.com/css2?family=', (string) $fonts['url']);
            $this->assertStringEndsWith('&display=swap', (string) $fonts['url']);
            $this->assertStringNotContainsString('Poppins:wght@400..700', (string) $fonts['url']);
        }

        // Only the styles a theme uses: Silver Screen's italic Playfair, never its upright cut.
        $this->assertStringContainsString('family=Playfair+Display:ital,wght@1,500..900&family=Inter:wght@400..800', (string) ThemeTokens::fonts(ThemeTokens::theme('silver_screen'))['url']);
        $this->assertSame('Playfair+Display:wght@500..900', ThemeTokens::fontSpec('Playfair+Display:ital,wght@0,500..900;1,500..900', ['normal']));
        $this->assertSame('Exo+2:ital,wght@0,400..800;1,600..900', ThemeTokens::fontSpec('Exo+2:ital,wght@0,400..800;1,600..900', ['italic', 'normal']));
        $this->assertSame('VT323', ThemeTokens::fontSpec('VT323', ['normal']));

        // Vietnamese pages swap Outfit (no Vietnamese glyphs) for the pairing's vi_display.
        $vi = ThemeTokens::fonts(ThemeTokens::theme('tokyo_neon'), 'modern_tech', 'vi');
        $this->assertSame(['Plus Jakarta Sans' => ['normal']], $vi['families']);
        $this->assertSame(["'Plus Jakarta Sans', system-ui, 'Segoe UI', Roboto, sans-serif"], array_values($vi['tokens']));

        // Another pairing drives the fonts; Poppins is requested with its static weights.
        $pop = ThemeTokens::fonts(ThemeTokens::theme('tokyo_neon'), 'playful_pop');
        $this->assertStringContainsString('family=Poppins:wght@400;500;600;700&family=Quicksand:wght@500;700', (string) $pop['url']);
        $this->assertSame("'Poppins', system-ui, 'Segoe UI', Roboto, sans-serif", $pop['tokens']['--avd-font-display']);

        // The v1 back office keeps every family, with Poppins fixed, plus the theme's own.
        $backOffice = (string) ThemeTokens::backOfficeFontsUrl(['Poppins' => 'Poppins:wght@400..700', 'Inter' => 'Inter:wght@400..800'], ['Geist' => ['normal']]);
        $this->assertSame('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Inter:wght@400..800&family=Geist:wght@400..800&display=swap', $backOffice);
    }

    /** Equal, or the same value with colours at most one sRGB step apart per channel. */
    private function sameValue(?string $got, string $want): bool
    {
        if ($got === $want) {
            return true;
        }

        if ($got === null) {
            return false;
        }

        $pattern = '/#[0-9a-f]{6}\b|rgba?\(\d+,\d+,\d+|\b\d{1,3},\d{1,3},\d{1,3}\b/i';
        preg_match_all($pattern, $got, $a);
        preg_match_all($pattern, $want, $b);

        if (preg_replace($pattern, 'C', $got) !== preg_replace($pattern, 'C', $want) || count($a[0]) !== count($b[0])) {
            return false;
        }

        foreach ($a[0] as $i => $colour) {
            $x = $this->channels($colour);
            $y = $this->channels($b[0][$i]);

            for ($k = 0; $k < 3; $k++) {
                if (abs($x[$k] - $y[$k]) > 1) {
                    return false;
                }
            }
        }

        return true;
    }

    private function channels(string $colour): array
    {
        if ($colour[0] === '#') {
            return array_slice(Oklch::parse($colour), 0, 3);
        }

        preg_match_all('/\d+/', $colour, $m);

        return array_map('intval', array_slice($m[0], 0, 3));
    }
}
