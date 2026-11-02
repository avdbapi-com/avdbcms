<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\AppearanceSettings;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\HtmlString;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round 1 (R1-150) and its review: the "Today's design" sheet in plain words ("365-DAY AESTHETIC IDENTITY", "SOUNDSCAPE
 * SYNTHESIS", "Audio FX: ON" while the site plays no sounds, "Custom Tone: #FF0066" in every language), the sound switch
 * only where the site plays click sounds, colour codes kept left to right in Arabic, the saved accent's label in the
 * reader's language each time the sheet opens, the wallpaper's "Day 180 of 365" (it printed "Day 180 of 180s": ':day'
 * was replaced inside ':days'), and the sheet's rules in their own stylesheet, out of the main CSS every visitor parses
 * before the first paint (RV-FC-08).
 */
class DailyDesignSheetTest extends TestCase
{
    use RefreshDatabase;

    private function dailyDesignOn(bool $sounds): void
    {
        Setting::set('daily_design_auto_enabled', '1', 'appearance');
        Setting::set('daily_design_show_badge', '1', 'appearance');
        Setting::set('ui_audio_feedback_enabled', $sounds ? '1' : '0', 'appearance');
        AppearanceSettings::applyToConfig(Setting::getGroup('appearance'));
        config(['site.effects_enabled' => true, 'site.ui_audio_feedback_enabled' => $sounds]);
    }

    public function test_the_sheet_speaks_plain_words_and_offers_sounds_only_where_the_site_plays_them(): void
    {
        $this->dailyDesignOn(false);
        $html = $this->get(route('pages.home'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<div class="modal fade" id="dailyAestheticModal".*?</div>\s*</div>\s*</div>#s', $html, $sheet), 'the sheet');
        $sheet = $sheet[0];
        foreach (["Today's design", "Today's colours", 'Click a colour to copy its code.', 'Glow strength', 'Subtle', 'Balanced', 'Vivid',
            'Your accent colour', "Use today's colour", 'Tomorrow', 'Preview', 'Surprise me', 'Download wallpaper'] as $words) {
            $this->assertStringContainsString(e(__($words)), $sheet, $words);
        }
        $this->assertMatchesRegularExpression('/Day \d{1,3} of 36[56]/', $sheet, 'the day of the year, in words');
        foreach (['365-DAY AESTHETIC IDENTITY', 'Soundscape Synthesis', 'Audio FX', 'Custom Tone', 'Live Custom Accent Hue', 'Export Wallpaper Card',
            'Personal Glow Intensity', 'Preview Tomorrow', 'Reset to Today'] as $jargon) {
            $this->assertStringNotContainsStringIgnoringCase($jargon, $sheet, $jargon);
        }
        // no sound switch while the site plays no click sounds
        $this->assertStringNotContainsString('id="avdSoundToggleBtn"', $html);

        // colour codes keep their left-to-right order in Arabic; the colours' part is a size container (rows when narrow)
        $this->assertSame(3, substr_count($sheet, 'class="avd-daily-swatch__code" dir="ltr"'));
        $this->assertStringContainsString('class="avd-daily-sheet__part avd-daily-sheet__colours"', $sheet);

        // the wallpaper's and the HUD's words come translated, with their placeholders
        $this->assertSame(1, preg_match('#<script type="application/json" id="avd-i18n">(.*?)</script>#s', $html, $config));
        $texts = json_decode($config[1], true)['t'];
        $this->assertSame(__('Day :day of :days'), $texts['dayOf']);
        $this->assertSame(__('Your colour: :code'), $texts['customColour']);

        // each time the sheet opens, a saved accent reads "Your colour: #..." in the reader's language (core.ts wrote
        // "Custom Tone: ..." on page load), the code isolated left to right, and the sheet's rules apply
        $this->assertStringContainsString("sheet.addEventListener('show.bs.modal'", $html);
        $this->assertStringContainsString("localStorage.getItem('avd_custom_accent')", $html);
        $this->assertStringContainsString(json_encode(__('Your colour: :code'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT).".replace(':code',", $html);
        $this->assertStringContainsString("'\\u2066'+String(hex).toUpperCase()+'\\u2069'", $html);

        $this->dailyDesignOn(true);
        $withSounds = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertStringContainsString('id="avdSoundToggleBtn"', $withSounds);
        $this->assertStringContainsString(e(__('Sound effects: on')), $withSounds);
    }

    public function test_the_sheet_rules_load_on_their_own_without_holding_the_page(): void
    {
        // the tests have no build of their own: Vite::asset() answers the entry's own path
        Facade::clearResolvedInstance(Vite::class);
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null)
            {
                return new HtmlString('');
            }

            public function asset($asset, $buildDirectory = null)
            {
                return '/build/'.$asset;
            }

            public function __call($method, $parameters)
            {
                return '';
            }

            public function __toString(): string
            {
                return '';
            }

            public function isRunningHot()
            {
                return false;
            }

            public function cspNonce()
            {
                return null;
            }
        });

        $this->dailyDesignOn(false);
        $html = $this->get(route('pages.home'))->assertOk()->getContent();

        // printed with the sheet, loaded without holding the first paint (print, then all once loaded)
        $this->assertSame(1, substr_count($html, "<link rel=\"stylesheet\" id=\"avdDailySheetCss\" href=\"/build/resources/sass/daily-sheet.scss\" media=\"print\" onload=\"this.media='all'\">"));
        $this->assertLessThan(strpos($html, 'id="dailyAestheticModal"'), strpos($html, 'id="avdDailySheetCss"'));

        // its own entry; its rules are in it and no longer in the main CSS (app.scss imports enhancements.scss), the
        // pill (shown at the first paint) stays there
        $this->assertStringContainsString("'daily-sheet': 'resources/sass/daily-sheet.scss',", (string) file_get_contents(base_path('vite.config.ts')));
        $sheet = (string) file_get_contents(resource_path('sass/daily-sheet.scss'));
        $main = (string) file_get_contents(resource_path('sass/enhancements.scss'));
        foreach (['.avd-daily-sheet__swatches', '.avd-daily-swatch', '.avd-daily-sheet__choices', '.avd-daily-choice {', '@container avd-daily-colours'] as $rule) {
            $this->assertStringContainsString($rule, $sheet, $rule);
            $this->assertStringNotContainsString($rule, $main, $rule);
        }
        $this->assertStringContainsString('.avd-daily-pill {', $main);
        $this->assertStringContainsString('@import "enhancements";', (string) file_get_contents(resource_path('sass/app.scss')));

        // no daily design, no sheet and no link
        Setting::set('daily_design_show_badge', '0', 'appearance');
        AppearanceSettings::applyToConfig(Setting::getGroup('appearance'));
        $without = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="avdDailySheetCss"', $without);
        $this->assertStringNotContainsString('id="dailyAestheticModal"', $without);
    }

    public function test_the_wallpaper_fills_the_longest_placeholder_first(): void
    {
        $hud = (string) file_get_contents(resource_path('js/layout/daily-hud.ts'));

        // one pass over the text, the names longest first: ':days' is never read as ':day' + 's' ("Day 180 of 180s")
        $this->assertStringContainsString('const keys = Object.keys(values).sort((a, b) => b.length - a.length);', $hud);
        $this->assertStringContainsString("const pattern = new RegExp(':(' + keys.map(", $hud);
        $this->assertStringNotContainsString(".split(':' + key).join(", $hud);
        // the codes inside translated texts are isolated left to right
        $this->assertStringContainsString("const ltr = (code: string): string => '\\u2066' + code + '\\u2069';", $hud);
        $this->assertStringContainsString('{code: ltr(hex.toUpperCase())}', $hud);
    }
}
