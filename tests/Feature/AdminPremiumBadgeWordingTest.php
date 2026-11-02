<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsPageController;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\AdminSearchIndex;
use App\Support\AdminSettingsSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.2.3 round 3 pre-fix pass, R2R-09: Appearance > Header, menus & footer called the header's Premium chip "Premium
 * button" ("A small button that opens the Premium page") and its text "Premium button text". The chip is no button: it
 * is a small badge inside the site's name link, shown only to Premium members where the header has room (from 660px),
 * and a click on it opens the home page like the name (layouts/menus/headers/brand.blade.php; the same in 1.2.2). The
 * settings say so now (scripts/admin_settings/generate_config.py SETTING_FIXES, CARD_FIXES), and the 1.2.0-1.2.2
 * names, in English and as each language of the admin showed them, stay search words of their setting (FORMER_LABELS):
 * the search of Settings and Ctrl+K still find them by the names owners knew, first.
 */
class AdminPremiumBadgeWordingTest extends TestCase
{
    use RefreshDatabase;

    /** 1.2.0-1.2.2 label => setting, as the three packages showed them */
    private const FORMER = [
        'Premium button' => 'header_premium_chip_enabled',
        'Premium button text' => 'header_premium_label',
    ];

    /** the former labels as the admin's other languages showed them (lang/<locale>.json of the 1.2.0-1.2.2 packages) */
    private const FORMER_TRANSLATED = [
        'de' => ['Premium-Taste' => 'header_premium_chip_enabled', 'Premium-Schaltflächentext' => 'header_premium_label'],
        'es' => ['Botón premium' => 'header_premium_chip_enabled', 'Texto del botón premium' => 'header_premium_label'],
        'ja' => ['プレミアムボタン' => 'header_premium_chip_enabled', 'プレミアムボタンのテキスト' => 'header_premium_label'],
        'ar' => ['زر مميز' => 'header_premium_chip_enabled', 'نص الزر المميز' => 'header_premium_label'],
        'ru' => ['Премиум-кнопка' => 'header_premium_chip_enabled', 'Текст кнопки премиум-класса' => 'header_premium_label'],
    ];

    private const NEW_TEXTS = [
        'Premium badge in the header',
        "Shows a small Premium badge next to the site's name for Premium members, on screens wide enough for both.",
        'Premium badge text',
        'The words of the Premium badge. Leave empty to use the default text (translated automatically).',
        'Change the words on the header buttons and on the Premium badge.',
    ];

    private const OLD_TEXTS = [
        'Premium button',
        'A small button that opens the Premium page.',
        'Premium button text',
        'The words shown for premium button text. Leave empty to use the default text (translated automatically).',
        'Change the words on the header buttons.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2']);
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_the_settings_call_the_chip_a_badge_and_say_what_it_does(): void
    {
        $chip = (array) config('admin_settings.settings.header_premium_chip_enabled');
        $label = (array) config('admin_settings.settings.header_premium_label');

        $this->assertSame('Premium badge in the header', $chip['label'] ?? null);
        $this->assertSame("Shows a small Premium badge next to the site's name for Premium members, on screens wide enough for both.", $chip['help'] ?? null);
        $this->assertSame('Premium badge text', $label['label'] ?? null);
        $this->assertSame('The words of the Premium badge. Leave empty to use the default text (translated automatically).', $label['help'] ?? null);
        // the page id holds a dot ("appearance.menus"), so the card is read from the pages array, not by a dotted config key
        $this->assertSame('Change the words on the header buttons and on the Premium badge.', ((array) config('admin_settings.pages'))['appearance.menus']['cards']['header_text']['description'] ?? null);

        // the rest of both settings is as it was: keys, page, card, group, type, default, old names
        $this->assertSame(['appearance', 'appearance.menus', 'header', 'bool', '1', ['appearance', 'Premium Chip']], [$chip['group'], $chip['page'], $chip['card'], $chip['type'], $chip['default'], $chip['old']]);
        $this->assertSame(['appearance', 'appearance.menus', 'header_text', 'string', 'Premium', ['appearance', 'Premium Chip Label']], [$label['group'], $label['page'], $label['card'], $label['type'], $label['default'], $label['old']]);

        foreach (self::OLD_TEXTS as $old) {
            $this->assertNotContains($old, array_column((array) config('admin_settings.settings'), 'label'));
            $this->assertNotContains($old, array_column((array) config('admin_settings.settings'), 'help'));
        }
    }

    public function test_what_the_words_describe_a_badge_in_the_name_link_for_premium_members(): void
    {
        $premium = User::factory()->create();
        Subscription::factory()->for($premium)->for(Plan::factory())->create(['ends_at' => null]);
        $premium = $premium->fresh();
        $this->assertTrue($premium->is_premium);
        $html = (string) $this->actingAs($premium)->get(route('pages.home'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<a [^>]*class="[^"]*navbar-brand avd-brand[^"]*"[^>]*href="([^"]+)"[^>]*>(.*?)</a>#s', $html, $brand), 'the site\'s name link');
        $this->assertStringContainsString('<span class="avd-premium-chip">', $brand[2], 'the chip is a badge inside the name link');
        $this->assertStringNotContainsString('premium', strtolower(parse_url($brand[1], PHP_URL_PATH) ?: '/'), 'it opens the home page like the name');

        $member = User::factory()->create();
        $this->assertStringNotContainsString('avd-premium-chip', (string) $this->actingAs($member)->get(route('pages.home'))->getContent(), 'only Premium members see it');
    }

    public function test_the_former_names_find_their_setting_first_in_both_searches(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (self::FORMER as $former => $key) {
            foreach ([$former, mb_strtoupper($former), mb_strtolower($former)] as $query) {
                $found = array_column(SettingsPageController::searchSettings($query, $admin), 'key');
                $this->assertSame($key, $found[0] ?? null, "\"{$query}\" lists first: ".implode(', ', array_slice($found, 0, 5)));
            }
            $this->assertSame([$key], AdminSearchIndex::synonyms()[mb_strtolower($former)] ?? null, 'Ctrl+K: '.$former);
        }

        $this->actingAs($admin)->getJson('/admin/settings?q='.rawurlencode('Premium button'))
            ->assertOk()
            ->assertJsonPath('results.0.key', 'header_premium_chip_enabled')
            ->assertJsonPath('results.0.label', 'Premium badge in the header');

        $index = $this->actingAs($admin)->getJson(route('admin.shell.search-index'))->assertOk()->json();
        $this->assertSame(['header_premium_label'], $index['synonyms']['premium button text'] ?? null);

        // the new names find them too
        $this->assertSame('header_premium_chip_enabled', array_column(SettingsPageController::searchSettings('Premium badge in the header', $admin), 'key')[0] ?? null);
        $this->assertSame('header_premium_label', array_column(SettingsPageController::searchSettings('Premium badge text', $admin), 'key')[0] ?? null);
    }

    public function test_in_the_admins_languages_the_former_names_find_their_setting_first(): void
    {
        $admin = User::factory()->admin()->create();
        $terms = [];
        foreach ((array) config('admin_settings.synonyms') as $term => $targets) {
            if (count((array) $targets) === 1 && in_array($targets[0], self::FORMER, true)) {
                $terms[(string) $term] = (string) $targets[0];
            }
        }
        foreach (self::FORMER as $key) {
            $this->assertGreaterThanOrEqual(15, count(array_keys($terms, $key, true)), "{$key}: its former label in every language of the admin");
        }

        foreach (self::FORMER_TRANSLATED as $locale => $examples) {
            app()->setLocale($locale);
            foreach ($examples as $query => $key) {
                $found = array_column(SettingsPageController::searchSettings($query, $admin), 'key');
                $this->assertSame($key, $found[0] ?? null, "{$locale}: \"{$query}\" lists first: ".implode(', ', array_slice($found, 0, 3)));
            }
            // no former name is the label of another setting in that language
            $folded = array_map([AdminSettingsSearch::class, 'fold'], array_keys($examples));
            foreach (AdminSettingsSearch::entries() as $entry) {
                if (! in_array($entry['key'], self::FORMER, true)) {
                    $this->assertSame([], array_values(array_intersect($folded, $entry['labels'])), "{$locale}: label of {$entry['key']}");
                }
            }
        }
    }

    public function test_the_new_words_are_translated_and_the_old_ones_are_gone(): void
    {
        $english = json_decode((string) file_get_contents(lang_path('en.json')), true);
        foreach (self::OLD_TEXTS as $old) {
            $this->assertArrayNotHasKey($old, $english, 'no source uses "'.$old.'" any more');
        }

        foreach (array_values(array_diff(array_unique(array_merge(array_keys((array) config('languages')), ['fil'])), ['en'])) as $locale) {
            $file = json_decode((string) file_get_contents(lang_path($locale.'.json')), true);
            foreach (self::NEW_TEXTS as $text) {
                $this->assertIsString($file[$text] ?? null, "{$locale}: {$text}");
                $this->assertNotSame($text, $file[$text], "{$locale} translates \"{$text}\"");
            }
        }
    }
}
