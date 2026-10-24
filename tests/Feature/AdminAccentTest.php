<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AdminPreferences;
use App\Support\AdminTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin's single accent colour (admin_design_spec.json tokens.accent): the site colour made safe by the
 * colour engine, neutral blue when the site colour is in the red zone of Delete, per-admin override.
 */
class AdminAccentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests are about the new admin, whatever ADMIN_UI the test environment sets.
        config(['admin.ui' => 'v2']);
    }

    /** Admin surface/canvas and the danger colour of each mode (resources/admin/components/_tokens.scss). */
    private const DANGER = ['light' => '#b42318', 'dark' => '#f2675b'];

    /**
     * spec/data/color_engine_golden.json admin_accent_cases (reference: contrast_engine.admin_accent).
     * [input, use site colour, mode, [accent, on_accent, accent_ink, accent_soft, accent_line, focus]]
     */
    public static function goldenAdminCases(): array
    {
        return [
            'demo1 YouTube Spotlight Pro #ff0055 light' => ['#ff0055', false, 'light', ['#1f5bc7', '#ffffff', '#1f5bc7', '#e9eff9', '#9ab5e6', '#1f5bc7']],
            'demo1 YouTube Spotlight Pro #ff0055 dark' => ['#ff0055', false, 'dark', ['#2763cf', '#ffffff', '#4381f0', '#1c283f', '#203c70', '#4381f0']],
            'demo1 YouTube Spotlight Pro #ff0055 site light' => ['#ff0055', true, 'light', ['#ea094e', '#ffffff', '#db184b', '#fde6ed', '#f690af', '#db184b']],
            'demo1 YouTube Spotlight Pro #ff0055 site dark' => ['#ff0055', true, 'dark', ['#ea094e', '#ffffff', '#fb2b5b', '#3b1a2a', '#781436', '#fb2b5b']],
            'factory default coral #ff3d61 light' => ['#ff3d61', false, 'light', ['#1f5bc7', '#ffffff', '#1f5bc7', '#e9eff9', '#9ab5e6', '#1f5bc7']],
            'factory default coral #ff3d61 dark' => ['#ff3d61', false, 'dark', ['#2763cf', '#ffffff', '#4381f0', '#1c283f', '#203c70', '#4381f0']],
            'factory default coral #ff3d61 site light' => ['#ff3d61', true, 'light', ['#e61c4e', '#ffffff', '#de0a48', '#fce8ed', '#f499af', '#de0a48']],
            'factory default coral #ff3d61 site dark' => ['#ff3d61', true, 'dark', ['#e61c4e', '#ffffff', '#f8355c', '#3b1d2a', '#761d36', '#f8355c']],
            'Swiss white #ffffff light' => ['#ffffff', false, 'light', ['#16191e', '#ffffff', '#16191e', '#e8e8e8', '#96989a', '#16191e']],
            'Swiss white #ffffff dark' => ['#ffffff', false, 'dark', ['#e9ebef', '#16191e', '#e9ebef', '#3b3e44', '#777a7f', '#e9ebef']],
            'Swiss white #ffffff site light' => ['#ffffff', true, 'light', ['#16191e', '#ffffff', '#16191e', '#e8e8e8', '#96989a', '#16191e']],
            'Swiss white #ffffff site dark' => ['#ffffff', true, 'dark', ['#e9ebef', '#16191e', '#e9ebef', '#3b3e44', '#777a7f', '#e9ebef']],
            'acid lime #ccff00 light' => ['#ccff00', false, 'light', ['#7fa009', '#16191e', '#5f790a', '#f2f6e6', '#c5d490', '#5f790a']],
            'acid lime #ccff00 dark' => ['#ccff00', false, 'dark', ['#ccff00', '#16191e', '#ccff00', '#36411d', '#6a8313', '#ccff00']],
            'acid lime #ccff00 site light' => ['#ccff00', true, 'light', ['#7fa009', '#16191e', '#5f790a', '#f2f6e6', '#c5d490', '#5f790a']],
            'acid lime #ccff00 site dark' => ['#ccff00', true, 'dark', ['#ccff00', '#16191e', '#ccff00', '#36411d', '#6a8313', '#ccff00']],
            'esports cyan #00f0ff light' => ['#00f0ff', false, 'light', ['#02a3ae', '#16191e', '#0a7c84', '#e6f6f7', '#8dd6db', '#0a7c84']],
            'esports cyan #00f0ff dark' => ['#00f0ff', false, 'dark', ['#00f0ff', '#16191e', '#00f0ff', '#163f46', '#0e7c86', '#00f0ff']],
            'esports cyan #00f0ff site light' => ['#00f0ff', true, 'light', ['#02a3ae', '#16191e', '#0a7c84', '#e6f6f7', '#8dd6db', '#0a7c84']],
            'esports cyan #00f0ff site dark' => ['#00f0ff', true, 'dark', ['#00f0ff', '#16191e', '#00f0ff', '#163f46', '#0e7c86', '#00f0ff']],
            'gold #d4af37 light' => ['#d4af37', false, 'light', ['#b3911e', '#16191e', '#896d01', '#f7f4e8', '#ddce9a', '#896d01']],
            'gold #d4af37 dark' => ['#d4af37', false, 'dark', ['#d4af37', '#16191e', '#d4af37', '#383426', '#6e5f2c', '#d4af37']],
            'gold #d4af37 site light' => ['#d4af37', true, 'light', ['#b3911e', '#16191e', '#896d01', '#f7f4e8', '#ddce9a', '#896d01']],
            'gold #d4af37 site dark' => ['#d4af37', true, 'dark', ['#d4af37', '#16191e', '#d4af37', '#383426', '#6e5f2c', '#d4af37']],
            'violet #7c3aed light' => ['#7c3aed', false, 'light', ['#7c3aed', '#ffffff', '#7c3aed', '#f2ebfd', '#c4a6f7', '#7c3aed']],
            'violet #7c3aed dark' => ['#7c3aed', false, 'dark', ['#7d3bee', '#ffffff', '#9467fd', '#2a2243', '#472a7e', '#9467fd']],
            'violet #7c3aed site light' => ['#7c3aed', true, 'light', ['#7c3aed', '#ffffff', '#7c3aed', '#f2ebfd', '#c4a6f7', '#7c3aed']],
            'violet #7c3aed site dark' => ['#7c3aed', true, 'dark', ['#7d3bee', '#ffffff', '#9467fd', '#2a2243', '#472a7e', '#9467fd']],
            // 1.2.2: hue 28 is inside the wider red zone [330, 35], so without the opt-in it takes the neutral blue.
            'netflix red #e50914 light' => ['#e50914', false, 'light', ['#1f5bc7', '#ffffff', '#1f5bc7', '#e9eff9', '#9ab5e6', '#1f5bc7']],
            'netflix red #e50914 dark' => ['#e50914', false, 'dark', ['#2763cf', '#ffffff', '#4381f0', '#1c283f', '#203c70', '#4381f0']],
            'netflix red #e50914 site light' => ['#e50914', true, 'light', ['#e50914', '#ffffff', '#dc201f', '#fce6e8', '#f39095', '#dc201f']],
            'netflix red #e50914 site dark' => ['#e50914', true, 'dark', ['#e50914', '#ffffff', '#fc332d', '#3a1a21', '#75141c', '#fc332d']],
            'ocean blue #0c74e5 light' => ['#0c74e5', false, 'light', ['#0c74e5', '#ffffff', '#146ed6', '#e7f1fc', '#92c0f3', '#146ed6']],
            'ocean blue #0c74e5 dark' => ['#0c74e5', false, 'dark', ['#0c74e5', '#ffffff', '#2483f5', '#182b42', '#14447a', '#2483f5']],
            'ocean blue #0c74e5 site light' => ['#0c74e5', true, 'light', ['#0c74e5', '#ffffff', '#146ed6', '#e7f1fc', '#92c0f3', '#146ed6']],
            'ocean blue #0c74e5 site dark' => ['#0c74e5', true, 'dark', ['#0c74e5', '#ffffff', '#2483f5', '#182b42', '#14447a', '#2483f5']],
            'emerald #10b981 light' => ['#10b981', false, 'light', ['#1b855e', '#ffffff', '#128059', '#e8f3ef', '#98c8b7', '#128059']],
            'emerald #10b981 dark' => ['#10b981', false, 'dark', ['#1b855e', '#ffffff', '#31956d', '#1a2e2c', '#1a4c3e', '#31956d']],
            'emerald #10b981 site light' => ['#10b981', true, 'light', ['#1b855e', '#ffffff', '#128059', '#e8f3ef', '#98c8b7', '#128059']],
            'emerald #10b981 site dark' => ['#10b981', true, 'dark', ['#1b855e', '#ffffff', '#31956d', '#1a2e2c', '#1a4c3e', '#31956d']],
        ];
    }

    #[DataProvider('goldenAdminCases')]
    public function test_accent_tokens_match_the_golden_admin_cases(string $input, bool $useSite, string $mode, array $expected): void
    {
        $tokens = AdminTheme::accentTokens($input, $mode, $useSite);
        $keys = ['accent', 'on_accent', 'accent_ink', 'accent_soft', 'accent_line', 'focus'];

        foreach ($keys as $i => $key) {
            $this->assertColourNear($expected[$i], $tokens[$key], "{$key} of {$input} ({$mode})");
        }
    }

    #[DataProvider('goldenAdminCases')]
    public function test_every_golden_accent_meets_its_contrast_targets(string $input, bool $useSite, string $mode): void
    {
        $tokens = AdminTheme::accentTokens($input, $mode, $useSite);
        ['surface' => $surface, 'canvas' => $canvas] = AdminTheme::SURFACES[$mode];

        $this->assertGreaterThanOrEqual(4.5, AdminTheme::contrast($tokens['on_accent'], $tokens['accent']), 'label on the accent');
        $this->assertGreaterThanOrEqual(3.0, AdminTheme::contrast($tokens['accent'], $surface), 'accent fill against the surface');
        $this->assertGreaterThanOrEqual(4.5, AdminTheme::contrast($tokens['accent_ink'], $surface), 'accent text on the surface');
        $this->assertGreaterThanOrEqual(4.5, AdminTheme::contrast($tokens['accent_ink'], $canvas), 'accent text on the canvas');
    }

    public function test_red_and_pink_site_colours_give_a_blue_primary_while_delete_stays_red(): void
    {
        foreach (['#ff0055' => 'demo1', '#ff3d61' => 'factory default'] as $site => $label) {
            foreach (['light' => '#1f5bc7', 'dark' => '#2763cf'] as $mode => $blue) {
                $accent = AdminTheme::accentTokens($site, $mode)['accent'];

                $this->assertSame($blue, $accent, "{$label} {$mode}");
                $this->assertGreaterThanOrEqual(60.0, $this->hueDistance($accent, self::DANGER[$mode]), "{$label} {$mode}: primary and Delete must differ in hue");
            }
        }
    }

    public function test_an_admin_may_choose_the_site_colour_even_in_the_red_zone(): void
    {
        $this->assertSame('#ea094e', AdminTheme::accentTokens('#ff0055', 'light', true)['accent']);
        $this->assertSame('#e61c4e', AdminTheme::accentTokens('#ff3d61', 'light', true)['accent']);
        $this->assertSame('#ffffff', AdminTheme::accentTokens('#ff0055', 'light', true)['on_accent']);
    }

    public function test_neutral_site_colours_give_a_monochrome_accent(): void
    {
        $this->assertSame('#16191e', AdminTheme::accentTokens('#ffffff', 'light')['accent']);
        $this->assertSame('#e9ebef', AdminTheme::accentTokens('#ffffff', 'dark')['accent']);
        $this->assertSame('#16191e', AdminTheme::accentTokens('#000000', 'light')['accent']);
    }

    public function test_the_style_tag_follows_the_published_site_colour_and_the_admin_choice(): void
    {
        $admin = User::factory()->admin()->create();

        Setting::set('primary_color', '#ff0055', 'appearance');
        Cache::flush();
        $redSite = (string) AdminTheme::styleTag($admin);
        // Red site: the neutral blue is the stylesheet default, so only the identity dot is set.
        $this->assertStringContainsString('--brand-dot:#ff0055', $redSite);
        $this->assertStringNotContainsString('--ac-l', $redSite);

        AdminPreferences::set($admin, 'accent_mode', 'site');
        $siteColour = (string) AdminTheme::styleTag($admin);
        $this->assertStringContainsString('--ac-l:#ea094e', $siteColour);
        $this->assertStringContainsString('--ac-d:#ea094e', $siteColour);

        AdminPreferences::set($admin, 'accent_mode', 'auto');
        Setting::set('primary_color', '#7c3aed', 'appearance');
        Cache::flush();
        $violet = (string) AdminTheme::styleTag($admin);
        $this->assertStringContainsString('--ac-l:#7c3aed', $violet);
        $this->assertStringContainsString('--ar-l:124,58,237', $violet);
        $this->assertLessThanOrEqual(400, strlen($violet), 'the accent <style> stays small');

        AdminPreferences::set($admin, 'accent_mode', 'neutral');
        $this->assertStringNotContainsString('--ac-l', (string) AdminTheme::styleTag($admin));

        AdminPreferences::set($admin, 'accent_mode', 'ink');
        $this->assertStringContainsString('--ac-l:#16191e', (string) AdminTheme::styleTag($admin));
    }

    public function test_the_admin_pages_carry_the_accent_of_the_site(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set('primary_color', '#10b981', 'appearance');
        Cache::flush();

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('<style id="ad-accent">', false)
            ->assertSee('--ac-l:#1b855e', false);
    }

    public function test_the_daily_rotated_colour_never_changes_the_admin(): void
    {
        Setting::set('primary_color', '#0c74e5', 'appearance');
        Cache::flush();
        // config('site.primary_color') carries the daily design of the public site for this request.
        config(['site.primary_color' => '#ff0055']);

        $this->assertSame('#0c74e5', AdminTheme::siteColour());
    }

    private function assertColourNear(string $expected, string $actual, string $label): void
    {
        $diff = 0;

        for ($i = 1; $i < 7; $i += 2) {
            $diff = max($diff, abs(hexdec(substr($expected, $i, 2)) - hexdec(substr($actual, $i, 2))));
        }

        $this->assertLessThanOrEqual(1, $diff, "{$label}: expected {$expected}, got {$actual} (+-1 per channel)");
    }

    private function hueDistance(string $a, string $b): float
    {
        $d = abs(AdminTheme::oklch($a)[2] - AdminTheme::oklch($b)[2]);

        return min($d, 360 - $d);
    }
}
