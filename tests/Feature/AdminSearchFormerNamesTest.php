<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SettingsPageController;
use App\Models\User;
use App\Support\AdminSearchIndex;
use App\Support\AdminSettingsSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R3-071: 1.2.3 corrected the translations of many settings' names (Arabic 69, German 48, Spanish 46 ...), and only the
 * settings renamed in English kept their 1.2.0-1.2.2 names as search words (R1R-18): an Arabic owner who typed
 * "قناة التليجرام" (1.2.2's name of Telegram channel) found nothing. The names the 1.2.2 admin showed, in each language,
 * where 1.2.3 shows another are now search words of their setting in that language (made from the 1.2.2 package by
 * scripts/admin_settings/generate_config.py --former-122: scripts/admin_settings/former_names_122.json ->
 * resources/admin/lib/former-names.json), and both searches list the setting first.
 *
 * Both searches read a name the way they read every text: letter case and accents aside (AdminSettingsSearch::fold()).
 * So 1.2.2 names that differ only there are ONE search, and it names every setting those names named in 1.2.2; the
 * searches list all of them first, in the catalogue's order, the same way as a name two settings shared outright (the
 * German old settings page name "Angesagte Schauspieler" of the two "Trending actors" ad settings). The 1.2.3
 * translations made such pairs (merge c5): Indonesian "Bentuk tajuk" (1.2.2's label of Header shape) and "Bentuk Tajuk"
 * (1.2.2's old settings page name of "Creators can change the header shape"), the Russian feature boxes' "Поле функции
 * (загрузки): значок" (uploads) and "Поле функций (загрузки): значок" (downloads, й folded to и). A name that is now
 * the label of another setting, as shown or as typed (without its quote marks and final full stop), is no search word:
 * the current label wins (generate_config.py --former-122; the Indonesian 1.2.2 name 'Judul "Kategori".' of the
 * footer's "Categories" heading, typed, is the label of the side menu's Categories heading now).
 *
 * R3X-01: Money > Payment setup "Premium badge on members" (show_premium_badge) was saved and loaded by every release and
 * read by nothing (the help site said it shows a badge next to paying members). It is out of the admin (both admins: no
 * page shows or sends it; the legacy endpoint old clients and fleet scripts use writes it as before, byte for byte), and
 * its names in every language lead to the badge Premium members do see: Premium badge in the header.
 */
class AdminSearchFormerNamesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array<string, list<string>>> locale => 1.2.2 name => setting keys */
    private static function formerNames(): array
    {
        return (array) json_decode((string) file_get_contents(base_path('scripts/admin_settings/former_names_122.json')), true)['names'];
    }

    /**
     * The settings each 1.2.2 name of a language finds: those of every name of that language the searches read as the
     * same text (AdminSettingsSearch::fold(): letter case and accents aside).
     *
     * @param  array<string, list<string>>  $found  1.2.2 name => setting keys
     * @return array<string, list<string>> 1.2.2 name => setting keys (sorted)
     */
    private static function settingsOfTheText(array $found): array
    {
        $byText = [];

        foreach ($found as $name => $keys) {
            $text = AdminSettingsSearch::fold((string) $name);
            $byText[$text] = array_merge($byText[$text] ?? [], $keys);
        }

        $settings = [];

        foreach (array_keys($found) as $name) {
            $keys = array_values(array_unique($byText[AdminSettingsSearch::fold((string) $name)]));
            sort($keys);
            $settings[(string) $name] = $keys;
        }

        return $settings;
    }

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

    public function test_the_1_2_2_names_of_every_language_find_their_setting_first(): void
    {
        $admin = User::factory()->admin()->create();
        $names = self::formerNames();
        $missed = [];
        $count = 0;

        // every language of the admin has its names, the judge's counts and more (labels and old names)
        foreach (['ar' => 69, 'de' => 48, 'es' => 46, 'fr' => 41, 'ru' => 47, 'tr' => 47, 'zh' => 50, 'ja' => 43, 'ko' => 34] as $locale => $atLeast) {
            $this->assertGreaterThanOrEqual($atLeast, count($names[$locale] ?? []), "{$locale}: its 1.2.2 names");
        }

        foreach ($names as $locale => $found) {
            app()->setLocale($locale);

            foreach (self::settingsOfTheText($found) as $name => $keys) {
                $count++;
                // its setting first; a text that named several settings lists them all before any other
                $top = array_slice(array_column(SettingsPageController::searchSettings($name, $admin), 'key'), 0, count($keys));
                $listed = $top;
                sort($listed);

                if ($listed !== $keys) {
                    $missed[] = "{$locale}: \"{$name}\" lists ".($top === [] ? 'nothing' : implode(', ', $top)).' first, not '.implode(', ', $keys);
                }
            }
        }

        $this->assertGreaterThan(700, $count);
        $this->assertSame([], $missed);
    }

    public function test_the_label_a_setting_has_now_wins_over_a_1_2_2_name(): void
    {
        $admin = User::factory()->admin()->create();
        $settings = (array) config('admin_settings.settings');
        $locales = array_values(array_unique(array_merge(['en'], array_keys((array) json_decode((string) file_get_contents(resource_path('admin/lib/former-names.json')), true)))));
        $lost = [];

        $this->assertGreaterThan(10, count($locales));

        foreach ($locales as $locale) {
            app()->setLocale($locale);
            $labels = [];

            foreach ($settings as $key => $setting) {
                $labels[AdminSettingsSearch::fold(AdminSettingsSearch::translate((string) $setting['label']))][] = (string) $key;
            }

            // a search word of a 1.2.2 name (as shown or as typed; the English ones count in every language) that is the
            // label of a setting it does not name would list that other setting above the one the admin shows the label of
            foreach (AdminSearchIndex::formerNames($locale) as $term => $keys) {
                $others = array_values(array_diff($labels[AdminSettingsSearch::fold((string) $term)] ?? [], $keys));

                if ($others !== []) {
                    $first = array_column(SettingsPageController::searchSettings((string) $term, $admin), 'key')[0] ?? 'nothing';
                    $lost[] = "{$locale}: \"{$term}\" is the label of ".implode(', ', $others)." now; Settings lists {$first} first";
                }
            }
        }

        $this->assertSame([], $lost);
    }

    public function test_the_judges_example_and_the_ctrl_k_index(): void
    {
        $admin = User::factory()->admin()->create();
        app()->setLocale('ar');

        $this->assertSame('social_telegram', array_column(SettingsPageController::searchSettings('قناة التليجرام', $admin), 'key')[0] ?? null);
        $this->assertSame('premium_start_paypal_label', array_column(SettingsPageController::searchSettings('الصفحة المميزة: ابدأ نص PayPal', $admin), 'key')[0] ?? null);

        // the Ctrl+K index of an Arabic admin carries the Arabic names, not those of the other languages
        $index = $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->getJson(route('admin.shell.search-index'))->assertOk()->json();
        $this->assertSame(['social_telegram'], $index['synonyms']['قناة التليجرام'] ?? null);
        $german = array_values(array_filter(array_keys(self::formerNames()['de']), fn (string $name): bool => ! array_key_exists(mb_strtolower($name), (array) config('admin_settings.synonyms'))));
        $this->assertNotSame([], $german);
        $this->assertArrayNotHasKey(mb_strtolower($german[0]), $index['synonyms'], 'German names stay in the German admin');
        $this->assertSame(['header_premium_chip_enabled'], $index['synonyms']['premium badge on members'] ?? null, 'English names in every language');

        // the search of Settings and Ctrl+K read the same words (the server's list for that language)
        $server = AdminSearchIndex::formerNames('ar');
        $browser = array_intersect_key($index['synonyms'], $server);
        ksort($server);
        ksort($browser);
        $this->assertSame($server, $browser);
    }

    public function test_the_browser_search_lists_them_first(): void
    {
        $node = $this->nodeBinary();

        if ($node === null) {
            $this->markTestSkipped('Node.js 22.18 or newer is needed to run the browser search (resources/admin/lib/search.ts).');
        }

        $admin = User::factory()->admin()->create();
        $queries = [];

        // the first 25 names of four languages, and in every language each name another name of which is the same search
        // (letter case and accents aside: "Bentuk tajuk" / "Bentuk Tajuk")
        foreach (['ar', 'de', 'ja', 'th'] as $locale) {
            $queries[$locale] = array_keys(array_slice(self::formerNames()[$locale], 0, 25, true));
        }

        foreach (self::formerNames() as $locale => $found) {
            $texts = array_count_values(array_map(fn (string $name): string => AdminSettingsSearch::fold($name), array_map('strval', array_keys($found))));

            foreach (array_keys($found) as $name) {
                if ($texts[AdminSettingsSearch::fold((string) $name)] > 1) {
                    $queries[$locale][] = (string) $name;
                }
            }
        }

        foreach ($queries as $locale => $names) {
            $names = array_values(array_unique($names));
            $index = $this->actingAs($admin)->withSession(['app_locale' => $locale])->getJson(route('admin.shell.search-index'))->assertOk()->json();
            $settings = self::settingsOfTheText(self::formerNames()[$locale]);
            app()->setLocale($locale);
            $browser = $this->browserFirst($node, $index, $names);

            foreach ($names as $at => $name) {
                // the name's settings first, in the order of the search of Settings (Enter opens the setting Settings lists first)
                $keys = $settings[$name];
                $server = array_slice(array_column(SettingsPageController::searchSettings($name, $admin), 'key'), 0, count($keys));
                $listed = $server;
                sort($listed);
                $this->assertSame($keys, $listed, "{$locale}: {$name} (Settings)");
                $this->assertSame($server, array_slice($browser[$at] ?? [], 0, count($keys)), "{$locale}: {$name} (Ctrl+K)");
            }
        }
    }

    public function test_premium_badge_on_members_is_out_of_the_admin_and_its_names_find_the_header_badge(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertArrayNotHasKey('show_premium_badge', (array) config('admin_settings.settings'));
        $this->assertNotSame('', trim((string) config('admin_settings.no_field.show_premium_badge')), 'why a stored value is read by nothing');
        // the legacy PUT /admin/settings/update/monetization keeps writing it (AdminSettingsLegacyEndpointPinTest), and
        // only there: it is in that group's schema, and no settings page of the new admin lists it
        $this->assertArrayHasKey('show_premium_badge', app(SettingsController::class)->saveSchema()['monetization']);
        foreach ((array) config('admin_settings.pages') as $page => $definition) {
            $this->assertStringNotContainsString('show_premium_badge', (string) json_encode($definition), "no page lists it ({$page})");
        }

        // neither admin shows a switch for it
        $this->actingAs($admin)->get('/admin/money/setup')->assertOk()->assertDontSee('show_premium_badge', false);
        config(['admin.ui' => 'v1']);
        $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()->assertDontSee('name="show_premium_badge"', false);
        config(['admin.ui' => 'v2']);

        // its names, English, the old settings page's and the other languages', find the header's Premium badge
        foreach (['en' => ['Premium badge on members', 'Show Premium Badge'], 'ar' => ['شارة مميزة على الأعضاء'], 'de' => ['Premium-Abzeichen für Mitglieder'], 'ja' => ['メンバーのプレミアムバッジ']] as $locale => $queries) {
            app()->setLocale($locale);

            foreach ($queries as $query) {
                $this->assertSame('header_premium_chip_enabled', array_column(SettingsPageController::searchSettings($query, $admin), 'key')[0] ?? null, "{$locale}: {$query}");
            }
        }
    }

    /** @param  list<string>  $queries @return list<list<string>> the first settings (up to six) the browser's search lists for each */
    private function browserFirst(string $node, array $index, array $queries): array
    {
        $dir = storage_path('framework/testing');
        is_dir($dir) || mkdir($dir, 0775, true);
        $id = bin2hex(random_bytes(4));
        $indexPath = $dir.'/former-names-index-'.$id.'.json';
        $queriesPath = $dir.'/former-names-queries-'.$id.'.json';
        $script = $dir.'/former-names-'.$id.'.ts';
        file_put_contents($indexPath, json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        file_put_contents($queriesPath, json_encode($queries, JSON_UNESCAPED_UNICODE));
        $searchTs = 'file://'.(PHP_OS_FAMILY === 'Windows' ? '/' : '').str_replace('\\', '/', base_path('resources/admin/lib/search.ts'));
        file_put_contents($script, "import {readFileSync} from 'node:fs';\n"
            ."import {searchIndex} from ".json_encode($searchTs, JSON_UNESCAPED_SLASHES).";\n"
            ."const index = JSON.parse(readFileSync(process.argv[2], 'utf8'));\n"
            ."const out: string[][] = [];\n"
            ."for (const q of JSON.parse(readFileSync(process.argv[3], 'utf8'))) out.push(searchIndex(index, q).filter((r) => r.group === 'settings').slice(0, 6).map((r) => r.key ?? ''));\n"
            ."console.log(JSON.stringify(out));\n");

        try {
            $output = [];
            exec(escapeshellarg($node).' '.escapeshellarg($script).' '.escapeshellarg($indexPath).' '.escapeshellarg($queriesPath).' 2>&1', $output, $code);
        } finally {
            @unlink($indexPath);
            @unlink($queriesPath);
            @unlink($script);
        }

        $this->assertSame(0, $code, implode("\n", $output));

        return (array) json_decode((string) end($output), true);
    }

    private function nodeBinary(): ?string
    {
        if (! function_exists('exec')) {
            return null;
        }

        $paths = [];
        @exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null', $paths);
        $node = trim((string) ($paths[0] ?? ''));

        if ($node === '' || ! is_file($node)) {
            return null;
        }

        $version = [];
        @exec(escapeshellarg($node).' -p "process.versions.node" 2>&1', $version);
        $parts = array_map('intval', explode('.', trim((string) ($version[0] ?? '0'))));

        return ($parts[0] ?? 0) > 22 || (($parts[0] ?? 0) === 22 && ($parts[1] ?? 0) >= 18) ? $node : null;
    }
}
