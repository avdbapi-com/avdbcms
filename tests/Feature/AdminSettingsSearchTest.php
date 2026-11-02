<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsPageController;
use App\Models\Setting;
use App\Models\User;
use App\Support\AdminPreferences;
use App\Support\AdminSettingsSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.3: the admin search finds every setting, at every level, by its label, help, old name and key,
 * in the Ctrl+K search (App\Support\AdminSearchIndex + resources/admin/lib/search.ts, run in Node against the real
 * index) and in the search of Settings (SettingsPageController::searchSettings()). A developer setting found while
 * Developer mode is off is listed with "Developer mode" and opens its page, which says how to see it; a hidden one
 * says where it is changed now. The owner's case: "upload18" found "Nothing found".
 */
class AdminSettingsSearchTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'U18-Lane-G-c0ffee77';

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2']);
    }

    // ---------------------------------------------------------------- the Upload18 card without Developer mode

    public function test_the_upload18_card_is_on_videos_and_uploads_without_developer_mode(): void
    {
        Setting::set('upload18_api_key', self::KEY, 'uploads');
        $html = (string) $this->actingAs($this->admin(developer: false))->get('/admin/settings/videos')->assertOk()->getContent();

        foreach (['upload18_api_key', 'upload18_api_url', 'upload18_default_cid', 'upload18_default_fid', 'upload18_default_mycid', 'upload18_chunk_size_mb', 'upload18_thumbnail_count'] as $key) {
            $this->assertStringContainsString('id="'.$key.'-row"', $html, $key);
            $this->assertStringContainsString('name="groups[uploads]['.$key.']"', $html, $key);
        }

        // The key is a write-only secret: Saved, Replace, Remove, never its value.
        $this->assertMatchesRegularExpression('/id="upload18_api_key-row"[^>]*data-write-only="1" data-saved="1"/', $html);
        $this->assertStringContainsString('data-secret-replace', $html);
        $this->assertStringContainsString('data-secret-clear', $html);
        $this->assertStringNotContainsString(self::KEY, $html);
        // The card is an everyday one: in the Advanced fold, not among the settings for developers.
        $this->assertMatchesRegularExpression('/<details class="ad-adv"[^>]*>.*id="card-upload18"/s', $html);
        $this->assertStringNotContainsString('data-devonly', $html, 'Videos & uploads has no developer settings any more');
        $this->assertSame('advanced', config('admin_settings.pages')['settings.videos']['cards']['upload18']['level']);
        // Every owner sees the card now: it says how to make a key of their own, not "only change these with support".
        // (1.2.3 round 1, R1-153: the menu path holds Upload18's screen names only, the steps on it follow in words.)
        $this->assertStringContainsString(e('make your own key: open upload18.org > API > Keys & security > Restricted keys, tick Upload and Read, click Create key and paste the key here.'), $html);
        $this->assertStringNotContainsString('Only change these with Upload18 support', $html);
    }

    public function test_the_upload18_key_is_set_replaced_and_removed_without_developer_mode(): void
    {
        $admin = $this->admin(developer: false);
        $save = fn (array $body) => $this->actingAs($admin)->putJson('/admin/settings/save', $body);

        $response = $save(['groups' => ['uploads' => ['upload18_api_key' => self::KEY]], 'fields' => ['uploads' => ['upload18_api_key']]])
            ->assertOk()->assertJsonPath('saved', ['uploads' => ['upload18_api_key']]);
        $this->assertStringNotContainsString(self::KEY, (string) $response->getContent());
        $this->assertSame(self::KEY, Setting::getGroup('uploads')['upload18_api_key'] ?? null);

        // An empty field keeps it; a new value replaces it; Remove clears it on purpose.
        $save(['groups' => ['uploads' => ['upload18_api_key' => '']], 'fields' => ['uploads' => ['upload18_api_key']]])->assertOk();
        $this->assertSame(self::KEY, Setting::getGroup('uploads')['upload18_api_key'] ?? null);
        $save(['groups' => ['uploads' => ['upload18_api_key' => 'U18-Lane-G-new-0042']], 'fields' => ['uploads' => ['upload18_api_key']]])->assertOk();
        $this->assertSame('U18-Lane-G-new-0042', Setting::getGroup('uploads')['upload18_api_key'] ?? null);
        $save(['fields' => ['uploads' => ['upload18_api_key']], 'clear' => ['uploads' => ['upload18_api_key']]])->assertOk();
        $this->assertSame('', Setting::getGroup('uploads')['upload18_api_key'] ?? null);

        // The other fields of the card too.
        $save(['groups' => ['uploads' => ['upload18_chunk_size_mb' => '40']], 'fields' => ['uploads' => ['upload18_chunk_size_mb']]])->assertOk();
        $this->assertSame('40', Setting::getGroup('uploads')['upload18_chunk_size_mb'] ?? null);
    }

    public function test_a_view_only_demo_account_still_cannot_change_the_upload18_key(): void
    {
        Setting::set('upload18_api_key', self::KEY, 'uploads');
        $demo = $this->admin(developer: false);
        $demo->forceFill(['is_view_only' => true])->save();

        $html = (string) $this->actingAs($demo->fresh())->get('/admin/settings/videos')->assertOk()->getContent();
        $this->assertStringNotContainsString(self::KEY, $html);

        $this->actingAs($demo->fresh())
            ->putJson('/admin/settings/save', ['groups' => ['uploads' => ['upload18_api_key' => 'stolen']], 'fields' => ['uploads' => ['upload18_api_key']]])
            ->assertForbidden();
        $this->assertSame(self::KEY, Setting::getGroup('uploads')['upload18_api_key'] ?? null);
    }

    public function test_the_owners_words_find_the_upload18_key_in_the_settings_search(): void
    {
        $admin = $this->admin(developer: false);

        foreach (['upload18', 'Upload18 key', 'API key', 'upload key'] as $query) {
            $results = $this->actingAs($admin)->getJson('/admin/settings?q='.urlencode($query))->assertOk()->json('results');
            $this->assertNotEmpty($results, $query);
            $this->assertSame('upload18_api_key', $results[0]['key'], "\"{$query}\" lists the Upload18 key first");
            $this->assertStringEndsWith('/admin/settings/videos#upload18_api_key', $results[0]['url']);
            $this->assertNull($results[0]['note'], 'no Developer mode needed');
        }

        $this->actingAs($admin)->get('/admin/settings?q=upload18')->assertOk()
            ->assertSee('Upload18 key')
            ->assertDontSee('No setting matches');

        // Where it is on its page: its card sits in the Advanced fold.
        $first = SettingsPageController::searchSettings('upload18', $admin)[0];
        $this->assertSame('Videos & uploads > Advanced > Upload18 connection', $first['where']);

        // A search word counts as a whole word: "uploads" is no "ads", so the ad settings that do not speak of uploads
        // stay out (the synonym "ads" lists all of Money > Ads), and a query word starts a word ("ads" is not in
        // "uploads" either).
        $uploads = array_column(SettingsPageController::searchSettings('uploads', $admin, PHP_INT_MAX), 'key');
        $this->assertNotContains('ads_enabled', $uploads);
        $this->assertNotContains('ad_code_header', $uploads);
        $ads = array_column(SettingsPageController::searchSettings('ads', $admin, PHP_INT_MAX), 'key');
        $this->assertContains('ads_enabled', $ads);
        $this->assertContains('ad_code_header', $ads);
        $this->assertNotContains('upload_allowed_formats', $ads, '"ads" is no word of "uploads"');

        // The page lists 60 and says how many more match: together the same number Ctrl+K's "See all" row names.
        $json = $this->actingAs($admin)->getJson('/admin/settings?q=ads')->assertOk()->json();
        $this->assertSame(count($ads), count($json['results']) + $json['more']);
    }

    public function test_the_settings_search_finds_every_card_by_its_name(): void
    {
        // The owner's words for a part of a page ("Admin access lock", "Sign-up & login"): its settings, never nothing.
        $admin = $this->admin(developer: false);
        $problems = [];

        foreach ((array) config('admin_settings.pages') as $pageId => $page) {
            foreach ($page['cards'] as $cardId => $card) {
                $own = array_keys(array_filter((array) config('admin_settings.settings'), fn (array $s): bool => $s['page'] === $pageId && $s['card'] === $cardId));
                $found = array_column(SettingsPageController::searchSettings($card['title'], $admin, PHP_INT_MAX), 'key');
                $missing = array_diff(array_filter($own, fn (string $key): bool => \App\Support\AdminSettingsCatalog::url($key) !== null), $found);

                if ($missing !== []) {
                    $problems[] = "card \"{$card['title']}\" ({$pageId}) does not find ".implode(', ', $missing);
                }
            }
        }

        $this->assertSame([], $problems);

        $lock = array_column(SettingsPageController::searchSettings('Admin access lock', $admin), 'key');
        $this->assertSame('admin_ip_whitelist', $lock[0] ?? null);
    }

    // ---------------------------------------------------------------- the Ctrl+K index

    public function test_the_ctrl_k_index_lists_every_setting_with_and_without_developer_mode(): void
    {
        $settings = (array) config('admin_settings.settings');
        $pages = (array) config('admin_settings.pages');
        $admin = $this->admin(developer: false);

        $index = $this->actingAs($admin)->getJson(route('admin.shell.search-index'))->assertOk()->json();
        $rows = collect($index['settings'])->keyBy('k');

        $this->assertSame([], array_values(array_diff(array_keys($settings), $rows->keys()->all())), 'every setting is in the index');

        foreach ($settings as $key => $setting) {
            $row = $rows[$key];
            $needsDeveloper = $setting['level'] === 'expert' || ($pages[$setting['page']]['level'] ?? '') === 'expert';
            $this->assertSame($needsDeveloper ? 1 : null, $row['x'] ?? null, "{$key}: x");
            $this->assertNotEmpty($row['u'], "{$key}: address");

            if ($setting['level'] === 'hidden') {
                $this->assertSame(__($setting['hint']), $row['n'] ?? null, "{$key}: hint");
            }
        }

        $this->assertSame(url('/admin/settings/videos').'#upload18_api_key', $rows['upload18_api_key']['u']);
        $this->assertSame(url('/admin/settings/seo').'#robots_txt', $rows['robots_txt']['u']);
        $this->assertSame(url('/admin/env-editor').'?setting=cors_allowed_origins#cors_allowed_origins', $rows['cors_allowed_origins']['u']);
        $this->assertSame(url('/admin/appearance/brand').'#typography_pairing', $rows['font_family']['u']);
        $this->assertSame(url('/admin/appearance?tab=themes'), $rows['theme_preset']['u']);
        $this->assertSame('Developer mode', $index['texts']['developer']);

        // Developer pages are found too, marked (they open the page that turns Developer mode on).
        $developerPage = collect($index['pages'])->firstWhere('id', 'tools.developer');
        $this->assertNotNull($developerPage);
        $this->assertSame(1, $developerPage['x'] ?? null);

        AdminPreferences::set($admin, 'developer_mode', true);
        $developer = $this->actingAs($admin->fresh())->getJson(route('admin.shell.search-index'))->json();
        $this->assertNotSame($index['v'], $developer['v']);
        $this->assertCount(count($settings), $developer['settings']);
        $this->assertSame([], collect($developer['settings'])->filter(fn (array $row) => isset($row['x']))->pluck('k')->all());
        $this->assertNull(collect($developer['pages'])->firstWhere('id', 'tools.developer')['x'] ?? null);
    }

    public function test_the_ctrl_k_search_finds_every_setting_in_the_browser_code(): void
    {
        $node = $this->nodeBinary();

        if ($node === null) {
            $this->markTestSkipped('Node.js 22.18 or newer is needed to run tests/js/admin_search.test.ts.');
        }

        // English with and without Developer mode, a language with accents (de), one without spaces (ja) and one right to
        // left with words glued to "al-" (ar): the browser's search finds every setting and, compared query by query,
        // exactly the settings the search of Settings finds (Developer mode changes no text, so English with it on runs
        // the browser checks only).
        foreach ([['en', false, true], ['en', true, false], ['de', false, true], ['ja', false, true], ['ar', false, true]] as [$locale, $developer, $compare]) {
            $admin = $this->admin(developer: $developer);
            $index = $this->actingAs($admin)->getJson(route('admin.shell.search-index', ['l' => $locale]))->assertOk()->json();
            $this->assertSame($locale, $index['locale']);

            app()->setLocale($locale);
            $checks = $compare ? $this->crossChecks($admin) : [];
            app()->setLocale('en');

            $text = $this->runNode($node, $index, ['boundary' => AdminSettingsSearch::BOUNDARY, 'checks' => $checks]);
            $this->assertMatchesRegularExpression('/admin-search: \d+ passed, 0 failed .* '.count($checks).' queries compared with Settings, locale '.$locale.'\)/', $text, "{$locale}, Developer mode ".($developer ? 'on' : 'off').":\n".$text);
        }
    }

    /**
     * The queries both searches must answer alike (every setting's key, label, old name, card, page and the first
     * words of its help, in the current language and in English, the search words, the cases a review found), each with
     * the settings the search of Settings finds.
     *
     * @return list<array{q: string, keys: list<string>}>
     */
    private function crossChecks(User $admin): array
    {
        $queries = ['ads', 'upload18', 'font', 'theme', 'logo', 'headers', 'api keys', 'API key', 'upload key', 'Upload18 key',
            'smtp', 'email not working', 'dark mode', 'ip bans', 'uploads', 'adsense', 'robots.txt', 'Sign-up & login',
            'Admin access lock', 'Browser security headers', 'API access from other sites', 'Blocked ranges', 'Payment methods',
            'Upload rules', 'Social profiles', 'nothing-matches-this', 'e', 'a b', '203.0.113.0/24', 'Việt', 'ÉCOLE', 'upload_'];
        $translate = fn (string $text): string => AdminSettingsSearch::translate($text);

        foreach ((array) config('admin_settings.settings') as $key => $setting) {
            array_push($queries, $key, $setting['label'], $translate($setting['label']));

            if (($setting['old'][1] ?? '') !== '') {
                $queries[] = $translate($setting['old'][1]);
            }

            foreach ([$setting['help'], $translate($setting['help'])] as $help) {
                $words = preg_split('/\s+/u', trim((string) $help)) ?: [];
                if ($words !== [] && $words[0] !== '') {
                    $queries[] = implode(' ', array_slice($words, 0, 3));
                }
            }
        }

        foreach ((array) config('admin_settings.pages') as $page) {
            $queries[] = $translate($page['label']);

            foreach ($page['cards'] as $card) {
                array_push($queries, $card['title'], $translate($card['title']));
            }
        }

        foreach (array_keys(\App\Support\AdminSearchIndex::synonyms()) as $term) {
            $queries[] = (string) $term;
        }

        $checks = [];

        foreach (array_values(array_unique(array_filter(array_map('trim', $queries), fn (string $q): bool => $q !== '' && mb_strlen($q) <= 200))) as $query) {
            $checks[] = ['q' => $query, 'keys' => array_column(SettingsPageController::searchSettings($query, $admin, PHP_INT_MAX), 'key')];
        }

        return $checks;
    }

    private function runNode(string $node, array $index, array $checks): string
    {
        $dir = storage_path('framework/testing');
        is_dir($dir) || mkdir($dir, 0775, true);
        $id = bin2hex(random_bytes(4));
        $indexPath = $dir.'/admin-search-index-'.$id.'.json';
        $checksPath = $dir.'/admin-search-checks-'.$id.'.json';
        file_put_contents($indexPath, json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        file_put_contents($checksPath, json_encode($checks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            $output = [];
            exec(escapeshellarg($node).' '.escapeshellarg(base_path('tests/js/admin_search.test.ts')).' '.escapeshellarg($indexPath).' '.escapeshellarg($checksPath).' 2>&1', $output, $code);
        } finally {
            @unlink($indexPath);
            @unlink($checksPath);
        }

        $text = implode("\n", $output);
        $this->assertSame(0, $code, $text);

        return $text;
    }

    // ---------------------------------------------------------------- the search of Settings

    public function test_the_settings_search_finds_every_setting_by_key_label_old_name_and_help(): void
    {
        $user = $this->admin(developer: false);
        $problems = [];

        foreach ((array) config('admin_settings.settings') as $key => $setting) {
            // By its key: first (a key that is a plain word, "theme" or "timezone", among the first results).
            $byKey = array_column(SettingsPageController::searchSettings($key, $user), 'key');
            if (str_contains($key, '_') ? ($byKey[0] ?? null) !== $key : ! in_array($key, array_slice($byKey, 0, 6), true)) {
                $problems[] = "key {$key}: first results ".implode(', ', array_slice($byKey, 0, 3));
            }

            $queries = ['label' => $setting['label'], 'help' => $setting['help']];
            if (($setting['old'][1] ?? '') !== '') {
                $queries['old name'] = $setting['old'][1];
            }

            foreach ($queries as $what => $query) {
                if (trim((string) $query) === '') {
                    continue;
                }

                $found = array_column(SettingsPageController::searchSettings((string) $query, $user, 1000), 'key');

                if (! in_array($key, $found, true)) {
                    $problems[] = "{$what} \"".mb_substr((string) $query, 0, 50)."\" does not find {$key}";
                }
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_a_developer_setting_is_found_with_a_note_while_developer_mode_is_off(): void
    {
        $admin = $this->admin(developer: false);

        $results = $this->actingAs($admin)->getJson('/admin/settings?q=robots.txt')->assertOk()->json('results');
        $robots = collect($results)->firstWhere('key', 'robots_txt');
        $this->assertNotNull($robots, 'Custom robots.txt is found');
        $this->assertSame('Developer mode', $robots['note']);
        $this->assertStringEndsWith('/admin/settings/seo#robots_txt', $robots['url']);

        $this->actingAs($admin)->get('/admin/settings?q=robots.txt&partial=1')->assertOk()
            ->assertSee('Custom robots.txt')
            ->assertSee('Developer mode');

        // The page it opens has the row, the note and the one-click switch back to it.
        $this->actingAs($admin)->get('/admin/settings/seo')->assertOk()
            ->assertSee('id="robots_txt-row"', false)
            ->assertSee('This setting appears in Developer mode (account menu).')
            ->assertSee('Turn on Developer mode')
            ->assertDontSee('name="groups[seo][robots_txt]"', false);

        // Developer mode on: the real field.
        AdminPreferences::set($admin, 'developer_mode', true);
        $this->actingAs($admin->fresh())->get('/admin/settings/seo')->assertOk()
            ->assertSee('name="groups[seo][robots_txt]"', false)
            ->assertDontSee('This setting appears in Developer mode (account menu).');
    }

    public function test_a_hidden_setting_says_where_it_is_changed_now(): void
    {
        $admin = $this->admin(developer: false);
        $results = SettingsPageController::searchSettings('font_family', $admin);

        $this->assertSame('font_family', $results[0]['key']);
        $this->assertStringEndsWith('/admin/appearance/brand#typography_pairing', $results[0]['url']);
        $this->assertSame('An old setting: the Fonts choice decides the fonts now.', $results[0]['note']);
        $this->assertTrue($results[0]['hidden']);

        // The results show a hidden setting with a plain name and its hint, never the notes written for maintainers.
        $html = (string) $this->actingAs($admin)->get('/admin/settings?q=mobile_bottom_dock_enabled&partial=1')->assertOk()->getContent();
        $this->assertStringContainsString(e('Bottom bar on phones (old copy)'), $html);
        $this->assertStringContainsString(e('An old copy of "Bottom bar on phones": it always follows that setting.'), $html);

        foreach ((array) config('admin_settings.settings') as $key => $setting) {
            if ($setting['level'] !== 'hidden') {
                continue;
            }

            foreach (['above', 'Hidden', 'Not shown', 'no page reads it', '(old copy of'] as $maintainerWords) {
                $this->assertStringNotContainsString($maintainerWords, $setting['label'].' '.$setting['help'], "{$key} speaks to maintainers");
            }
        }
    }

    public function test_the_main_developer_mode_button_comes_back_to_the_first_developer_setting(): void
    {
        // Settings > SEO lists its developer settings in one fold while Developer mode is off; in Developer mode they are
        // spread over their cards, so the button of the note comes back to the first of them (the fold's id is gone then).
        $first = null;
        foreach ((array) config('admin_settings.pages')['settings.seo']['cards'] as $card) {
            foreach ($card['fields'] as $key) {
                if ($first === null && (config("admin_settings.settings.{$key}.level") === 'expert')) {
                    $first = $key;
                }
            }
        }
        $this->assertNotNull($first);

        $admin = $this->admin(developer: false);
        $html = (string) $this->actingAs($admin)->get('/admin/settings/seo')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<div class="ad-devonly-note">.*?name="anchor" value="'.preg_quote($first, '/').'"/s', $html);
        $this->assertStringNotContainsString('value="developer-settings"', $html);

        AdminPreferences::set($admin, 'developer_mode', true);
        $this->actingAs($admin->fresh())->get('/admin/settings/seo')->assertOk()->assertSee('id="'.$first.'"', false);
    }

    public function test_turning_developer_mode_on_goes_back_to_the_setting(): void
    {
        $admin = $this->admin(developer: false);

        $this->actingAs($admin)
            ->from('/admin/settings/seo')
            ->post(route('admin.shell.preferences'), ['key' => 'developer_mode', 'value' => '1', 'anchor' => 'robots_txt'])
            ->assertRedirect(url('/admin/settings/seo').'#robots_txt');
        $this->assertTrue(AdminPreferences::developerMode($admin->fresh()));

        // Anything but a setting key is left out of the address.
        $this->actingAs($admin->fresh())
            ->from('/admin/settings/seo')
            ->post(route('admin.shell.preferences'), ['key' => 'developer_mode', 'value' => '0', 'anchor' => '"><script>'])
            ->assertRedirect(url('/admin/settings/seo'));
    }

    public function test_a_developer_page_names_the_setting_it_was_opened_for(): void
    {
        $this->actingAs($this->admin(developer: false))
            ->get('/admin/env-editor?setting=cors_allowed_origins')
            ->assertForbidden()
            ->assertSee(e(__('Sites allowed to use your API')), false)
            ->assertSee('This setting appears in Developer mode (account menu).')
            ->assertSee('name="anchor" value="cors_allowed_origins"', false)
            ->assertSee('Turn on Developer mode');

        // An unknown or foreign key is ignored.
        $this->actingAs($this->admin(developer: false))
            ->get('/admin/env-editor?setting=site_name')
            ->assertForbidden()
            ->assertDontSee('name="anchor"', false);
    }

    private function admin(bool $developer): User
    {
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', $developer);

        return $admin->fresh();
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
