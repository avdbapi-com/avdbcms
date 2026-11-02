<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.3 round 4 (TL-VERIFY10-4): the old settings page (admin_ui v1) prints the unit after its day, millisecond
 * and pixel number boxes, as the new admin prints its 'suffix', because their translated labels are the new admin's
 * labels, written to lead into the box and its unit ("Ряд «Популярные теги»: учитывать последние [30] дн."). Where the
 * label already names the unit (the English old labels "Discovery Window Days", "Duration Ms") nothing is added.
 * The page is rendered for real here (App\Support\NumberUnit in the view, the language of the admin's session).
 */
class OldSettingsPageUnitsTest extends TestCase
{
    use RefreshDatabase;

    /** setting => [the old page's tab, the unit the new admin prints] */
    private const FIELDS = [
        'home_widget_motion_duration_ms' => ['appearance', 'ms'],
        'home_widget_motion_distance_px' => ['appearance', 'px'],
        'home_widget_motion_stagger_ms' => ['appearance', 'ms'],
        'smart_discovery_rail_lookback_days' => ['discovery', 'days'],
        'creator_spotlight_lookback_days' => ['discovery', 'days'],
        'trending_collections_lookback_days' => ['discovery', 'days'],
        'view_history_retention_days' => ['comments', 'days'],
        'creator_quality_pulse_lookback_days' => ['users', 'days'],
        'studio_health_alerts_lookback_days' => ['users', 'days'],
        'creator_recovery_checklist_inactivity_days' => ['users', 'days'],
    ];

    private function page(string $locale, string $tab): string
    {
        Cache::flush();
        config(['admin.ui' => 'v1']);
        $admin = User::factory()->admin()->create();

        return (string) $this->actingAs($admin)->withSession(['app_locale' => $locale])
            ->get(route('admin.settings.index', ['tab' => $tab]))->assertOk()->getContent();
    }

    public function test_the_number_boxes_show_their_unit_in_a_translated_language(): void
    {
        $pages = [];
        foreach (['ru', 'ar'] as $locale) {
            foreach (self::FIELDS as $name => [$tab, $unit]) {
                $html = $pages[$locale] ??= $this->page($locale, $tab); // every tab of the old page is in the one page
                $word = trim((string) __($unit, [], $locale));
                $this->assertMatchesRegularExpression(
                    '/<div class="input-group"><input id="'.$name.'" type="number" [^>]*>\s*<span class="input-group-text">'.preg_quote(e($word), '/').'<\/span><\/div>/u',
                    $html,
                    "$locale $name: the unit after the box"
                );
            }
        }
        $this->assertSame('дн.', trim((string) __('days', [], 'ru')));
    }

    public function test_english_labels_that_name_the_unit_get_no_second_one(): void
    {
        $pages = [];
        foreach (self::FIELDS as $name => [$tab]) {
            $html = $pages['en'] ??= $this->page('en', $tab);
            $this->assertMatchesRegularExpression(
                '/<div class="input-group"><input id="'.$name.'" type="number" [^>]*>\s*<\/div>/u',
                $html,
                "en $name: the box alone (the label says the unit)"
            );
        }
    }
}
