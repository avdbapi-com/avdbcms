<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AppearancePages;
use App\Support\AppearanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sites installed before the rename to AVDBCMS 1.2.0 still store the factory footer line of the community test packages
 * (old example.sql row, 2026_05_05 rebrand migration) and no 1.2.0 migration rewrites it: the public footer and the
 * Copyright line field show the AVDBCMS line instead, in every language, and nothing is written. A line the owner wrote
 * stays exactly as written. The old line is no longer a translation key.
 */
class RenamedFooterLineTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_LINE = '(c) 2026 AVDBClip. All rights reserved.';

    private const NEW_LINE = '(c) 2026 AVDBCMS. All rights reserved.';

    public function test_a_stored_old_default_renders_the_avdbcms_line_in_every_language(): void
    {
        $this->withoutVite();
        Setting::set('footer_text', self::OLD_LINE, 'appearance');
        // What AppServiceProvider::applyDbSettings() does on every web request.
        AppearanceSettings::applyToConfig(Setting::getGroup('appearance'));

        $this->assertSame(self::NEW_LINE, config('site.footer_text'));

        foreach (array_keys(config('languages')) as $locale) {
            app()->setLocale($locale);
            $line = __(self::NEW_LINE);

            $this->assertStringContainsString('AVDBCMS', $line, "{$locale}: the brand stays untranslated");
            $this->assertSame(e($line), $this->footerLine(view('layouts.default')->render()), "{$locale}: footer line");
        }

        $this->assertSame(self::OLD_LINE, Setting::getGroup('appearance')['footer_text'], 'showing it writes nothing');
    }

    public function test_the_copyright_line_field_shows_the_avdbcms_line_in_both_admins(): void
    {
        $this->withoutVite();
        Setting::set('footer_text', self::OLD_LINE, 'appearance');
        $admin = User::factory()->admin()->create();

        $this->assertSame(self::NEW_LINE, AppearanceSettings::formValues(Setting::getGroup('appearance'))['footer_text']);
        $this->assertSame(['footer_text' => self::NEW_LINE], AppearancePages::published(['footer_text']));

        // New admin: Appearance > Menus (footer card).
        config(['admin.ui' => 'v2']);
        $this->actingAs($admin)->get(route('admin.appearance.page', 'menus'))
            ->assertOk()
            ->assertSee('value="' . e(self::NEW_LINE) . '"', false)
            ->assertDontSee(self::OLD_LINE, false);

        // Old admin: Settings > Appearance (Footer Text).
        config(['admin.ui' => 'v1']);
        $this->actingAs($admin)->get(route('admin.settings.index', ['tab' => 'appearance']))
            ->assertOk()
            ->assertSee('value="' . e(self::NEW_LINE) . '"', false)
            ->assertDontSee(self::OLD_LINE, false);

        $this->assertSame(self::OLD_LINE, Setting::getGroup('appearance')['footer_text'], 'showing it writes nothing');
    }

    public function test_any_other_footer_line_stays_exactly_as_stored(): void
    {
        $this->assertSame(self::NEW_LINE, AppearanceSettings::footerText(' ' . self::OLD_LINE . ' '));

        foreach (['(c) 2026 My Video Site. All rights reserved.', self::NEW_LINE, 'All rights reserved.', '', null] as $own) {
            $this->assertSame($own, AppearanceSettings::footerText($own));
        }

        AppearanceSettings::applyToConfig(['footer_text' => '(c) 2026 My Video Site. All rights reserved.']);
        $this->assertSame('(c) 2026 My Video Site. All rights reserved.', config('site.footer_text'));
        $this->assertSame('Mine', AppearanceSettings::formValues(['footer_text' => 'Mine'])['footer_text']);

        AppearanceSettings::applyToConfig([]);
        $this->assertSame('', config('site.footer_text'), 'never stored: the footer keeps its own line');
    }

    public function test_no_language_file_keeps_the_old_line_as_a_key(): void
    {
        $files = glob(lang_path('*.json')) ?: [];
        $this->assertGreaterThanOrEqual(18, count($files));

        foreach ($files as $file) {
            $strings = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            $this->assertArrayNotHasKey(self::OLD_LINE, $strings, basename($file));
            $this->assertStringContainsString('AVDBCMS', $strings[self::NEW_LINE] ?? '', basename($file));
        }
    }

    /** The text of the footer's bottom line (layouts/menus/footer.blade.php). */
    private function footerLine(string $html): string
    {
        $this->assertSame(1, preg_match('#<div class="avd-footer-bottom">\s*<span>(.*?)</span>#s', $html, $match), 'the footer bottom line renders');

        return $match[1];
    }
}
