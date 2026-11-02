<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsPageController;
use App\Models\User;
use App\Support\AdminSearchIndex;
use App\Support\AdminSettingsSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R1R-18: the admin's settings search finds a renamed setting by the label the new admin showed before (1.2.0-1.2.2),
 * first, in both searches (the search of Settings and Ctrl+K). "Creators can change the upload schedule" (now Creators
 * can show "Last upload") found nothing; "Right-to-left languages" (now Show every language right to left) found it
 * only through its words. The former labels, in English and as the admin's other languages showed them, are search
 * words of their setting (scripts/admin_settings/generate_config.py FORMER_LABELS, config/admin_settings.php
 * 'synonyms'); nothing else of the search changed.
 */
class AdminSearchFormerLabelsTest extends TestCase
{
    use RefreshDatabase;

    /** former label => the setting, as an owner of 1.2.0-1.2.2 typed it */
    private const FORMER = [
        'Creators can change the upload schedule' => 'channel_customization_upload_rhythm_enabled',
        'Right-to-left languages' => 'rtl_support',
        'Skip button after' => 'ad_preroll_duration',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2']);
    }

    public function test_the_search_of_settings_finds_a_renamed_setting_first_by_its_former_label(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (self::FORMER as $former => $key) {
            $now = (string) config("admin_settings.settings.{$key}.label");
            $this->assertNotSame('', $now, "{$key} is a setting");
            $this->assertNotSame(AdminSettingsSearch::fold($former), AdminSettingsSearch::fold($now), "{$key} has another label now");

            // As it was shown, in capitals, without the hyphens, and inside a longer query.
            foreach ([$former, mb_strtoupper($former), str_replace('-', ' ', $former), 'setting '.$former] as $query) {
                $found = array_column(SettingsPageController::searchSettings($query, $admin), 'key');
                $this->assertSame($key, $found[0] ?? null, "\"{$query}\" lists first: ".implode(', ', array_slice($found, 0, 5)));
            }
        }

        // The brief's two examples through the page: Settings > search (the JSON of the live search and the page).
        $this->actingAs($admin)->getJson('/admin/settings?q='.rawurlencode('Creators can change the upload schedule'))
            ->assertOk()
            ->assertJsonPath('results.0.key', 'channel_customization_upload_rhythm_enabled')
            ->assertJsonPath('results.0.label', 'Creators can show "Last upload"');
        $this->actingAs($admin)->getJson('/admin/settings?q='.rawurlencode('Right-to-left languages'))
            ->assertOk()
            ->assertJsonPath('results.0.key', 'rtl_support')
            ->assertJsonPath('results.0.label', 'Show every language right to left');
        $this->actingAs($admin)->get('/admin/settings?q='.rawurlencode('Creators can change the upload schedule'))
            ->assertOk()
            ->assertSee('#channel_customization_upload_rhythm_enabled', false);
    }

    /**
     * The 1.2.0-1.2.2 admin showed these labels in the admin's language ("Ersteller können den Upload-Zeitplan ändern"):
     * in every language of the admin, each of them (as shown, and without its final full stop and quote marks) finds
     * its setting first, and none is the label of another setting in that language.
     */
    public function test_in_every_language_of_the_admin_a_former_label_finds_its_setting_first(): void
    {
        $admin = User::factory()->admin()->create();
        $terms = [];

        foreach ((array) config('admin_settings.synonyms') as $term => $targets) {
            if (count((array) $targets) === 1 && in_array($targets[0], self::FORMER, true)) {
                $terms[(string) $term] = (string) $targets[0];
            }
        }

        // Each former label in English and in the 17 other languages (the same text in two languages counts once).
        foreach (self::FORMER as $key) {
            $this->assertGreaterThanOrEqual(15, count(array_keys($terms, $key, true)), "{$key}: its former label in every language");
        }

        $examples = [
            'de' => ['Ersteller können den Upload-Zeitplan ändern' => 'channel_customization_upload_rhythm_enabled', 'Schaltfläche Überspringen danach' => 'ad_preroll_duration'],
            'ja' => ['右から左に記述する言語' => 'rtl_support'],
            'ar' => ['اللغات التي تكتب من اليمين إلى اليسار' => 'rtl_support'],
            'es' => ['Las creadoras pueden cambiar el horario de carga' => 'channel_customization_upload_rhythm_enabled'],
            'tr' => ['İçerik oluşturucular yükleme planını değiştirebilir' => 'channel_customization_upload_rhythm_enabled'],
        ];

        foreach (['en', 'ar', 'de', 'es', 'fil', 'fr', 'hi', 'id', 'ja', 'ko', 'ms', 'pt', 'ru', 'th', 'tl', 'tr', 'vi', 'zh'] as $locale) {
            app()->setLocale($locale);
            $folded = [];

            foreach ($terms + ($examples[$locale] ?? []) as $query => $key) {
                $found = array_column(SettingsPageController::searchSettings((string) $query, $admin), 'key');
                $this->assertSame($key, $found[0] ?? null, "{$locale}: \"{$query}\" lists first: ".implode(', ', array_slice($found, 0, 3)));
                $folded[AdminSettingsSearch::fold((string) $query)] = $key;
            }

            foreach (AdminSettingsSearch::entries() as $entry) {
                foreach ($entry['labels'] as $label) {
                    if (isset($folded[$label]) && $folded[$label] !== $entry['key']) {
                        $this->fail("{$locale}: the former label \"{$label}\" of {$folded[$label]} is the label of {$entry['key']}");
                    }
                }
            }

            $this->addToAssertionCount(1);
        }

        app()->setLocale('en');
    }

    public function test_the_ctrl_k_index_carries_them_as_search_words(): void
    {
        $admin = User::factory()->admin()->create();
        $index = $this->actingAs($admin)->getJson(route('admin.shell.search-index'))->assertOk()->json();

        foreach (self::FORMER as $former => $key) {
            $this->assertSame([$key], $index['synonyms'][mb_strtolower($former)] ?? null, "the search word \"{$former}\"");
            $this->assertSame([$key], AdminSearchIndex::synonyms()[mb_strtolower($former)] ?? null);
        }

        // The settings that are called "... main homepage list" now (ad_grid_layout_home_feed, ad_code_grid_home_feed)
        // keep their place: the 1.2.2 names of the Classic grid list ads are their labels, no search word of the old ones.
        $this->assertArrayNotHasKey('ad shape in the main homepage list', $index['synonyms']);
        $this->assertSame('ad_grid_layout_home_feed', array_column(SettingsPageController::searchSettings('Ad shape in the main homepage list', $admin), 'key')[0] ?? null);
    }

    public function test_the_browser_search_lists_them_first(): void
    {
        $node = $this->nodeBinary();

        if ($node === null) {
            $this->markTestSkipped('Node.js 22.18 or newer is needed to run the browser search (resources/admin/lib/search.ts).');
        }

        $admin = User::factory()->admin()->create();
        $index = $this->actingAs($admin)->getJson(route('admin.shell.search-index'))->assertOk()->json();

        // resources/admin/lib/search.ts on this index: the same first setting for each former label as typed, in English
        // and in the other languages (the search words hold for every language of the admin).
        $expected = self::FORMER + [
            'Ersteller können den Upload-Zeitplan ändern' => 'channel_customization_upload_rhythm_enabled',
            '右から左に記述する言語' => 'rtl_support',
            'Кнопка «Пропустить» после' => 'ad_preroll_duration',
            'Кнопка Пропустить после' => 'ad_preroll_duration',
        ];
        $dir = storage_path('framework/testing');
        is_dir($dir) || mkdir($dir, 0775, true);
        $id = bin2hex(random_bytes(4));
        $indexPath = $dir.'/former-labels-index-'.$id.'.json';
        $queriesPath = $dir.'/former-labels-queries-'.$id.'.json';
        $script = $dir.'/former-labels-'.$id.'.ts';
        file_put_contents($indexPath, json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        file_put_contents($queriesPath, json_encode(array_keys($expected), JSON_UNESCAPED_UNICODE));
        // A file URL (an absolute Windows path is no module specifier for Node).
        $searchTs = 'file://'.(PHP_OS_FAMILY === 'Windows' ? '/' : '').str_replace('\\', '/', base_path('resources/admin/lib/search.ts'));
        file_put_contents($script, "import {readFileSync} from 'node:fs';\n"
            ."import {searchIndex} from ".json_encode($searchTs, JSON_UNESCAPED_SLASHES).";\n"
            ."const index = JSON.parse(readFileSync(process.argv[2], 'utf8'));\n"
            ."const out: Record<string, string> = {};\n"
            ."for (const q of JSON.parse(readFileSync(process.argv[3], 'utf8'))) out[q] = searchIndex(index, q).filter((r) => r.group === 'settings')[0]?.key ?? '';\n"
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
        $this->assertSame($expected, json_decode((string) end($output), true), implode("\n", $output));
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
