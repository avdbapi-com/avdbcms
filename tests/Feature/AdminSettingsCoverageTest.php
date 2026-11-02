<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AdminPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminSettingsCoverage;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.3 (owner: "in admin setting not see setting api key for upload18.org, may be many other admin
 * setting missing too"): no setting is missing from the admin.
 *
 *  (a) every key of config/admin_settings.php is on a page that really shows it (Developer mode on), or, without a row
 *      of its own, on the page part that sets it; a hidden one says where it is changed now;
 *  (b) every key the save schema accepts, the bundled example.sql stores, and every config('site.*') value the code
 *      reads has an admin field, or is listed in no_field with the reason it has none;
 *  (c) every tab of the old settings page is on "What moved where" with the pages its settings are on now;
 *  and while Developer mode is off, a developer setting's page says how to see it, with a one-click switch.
 *
 * The coverage fails as soon as a setting key has no page (see the *_is_caught tests).
 */
class AdminSettingsCoverageTest extends TestCase
{
    use AdminSettingsCoverage;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The redesigned admin, whatever ADMIN_UI the test environment sets.
        config(['admin.ui' => 'v2']);
    }

    public function test_the_catalogue_places_every_setting_on_a_page_and_card(): void
    {
        $this->assertSame([], $this->catalogueProblems((array) config('admin_settings')));
    }

    public function test_every_page_shows_its_settings_in_developer_mode(): void
    {
        $this->assertSame([], $this->settingsNotShown($this->admin(developer: true)));
    }

    /** @return list<string> "<key> is not shown on <page>" for every setting with a row that its page does not show */
    private function settingsNotShown(User $admin, ?array $onlyPages = null): array
    {
        $settings = (array) config('admin_settings.settings');
        $missing = [];

        foreach ((array) config('admin_settings.pages') as $pageId => $page) {
            if ($onlyPages !== null && ! in_array($pageId, $onlyPages, true)) {
                continue;
            }

            $keys = array_keys(array_filter($settings, fn (array $s): bool => $s['page'] === $pageId && $s['level'] !== 'hidden' && empty($s['link'])));

            if ($keys === []) {
                continue;
            }

            $response = $this->actingAs($admin)->get($page['url']);
            $this->assertSame(200, $response->getStatusCode(), "{$pageId} ({$page['url']}) opens");

            foreach ($this->notShown((string) $response->getContent(), $keys) as $key) {
                $missing[] = "{$key} is not shown on {$pageId} ({$page['url']})";
            }
        }

        return $missing;
    }

    public function test_settings_without_a_row_of_their_own_lead_to_the_part_that_sets_them(): void
    {
        $admin = $this->admin(developer: true);
        $settings = (array) config('admin_settings.settings');
        $pages = (array) config('admin_settings.pages');
        $problems = [];
        $html = [];
        $open = function (string $url) use ($admin, &$html): string {
            return $html[$url] ??= (string) $this->actingAs($admin)->get($url)->assertOk()->getContent();
        };

        foreach ($settings as $key => $setting) {
            if (! empty($setting['link'])) {
                if (! str_contains($open($setting['link']), $setting['marker'])) {
                    $problems[] = "{$key}: {$setting['link']} has no {$setting['marker']}";
                }
            } elseif (! empty($setting['see'])) {
                $see = $settings[$setting['see']];

                if ($this->notShown($open($pages[$see['page']]['url']), [$setting['see']]) !== []) {
                    $problems[] = "{$key}: {$setting['see']} is not shown on {$see['page']}";
                }
            } elseif (! empty($setting['anchor'])) {
                if (! str_contains($open($pages[$setting['page']]['url']), 'id="'.$setting['anchor'].'"')) {
                    $problems[] = "{$key}: {$setting['page']} has no #{$setting['anchor']}";
                }
            } elseif ($setting['level'] === 'hidden') {
                $problems[] = "{$key}: hidden, and nothing says where it is changed";
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_developer_settings_say_how_to_see_them_while_developer_mode_is_off(): void
    {
        $admin = $this->admin(developer: false);
        $settings = (array) config('admin_settings.settings');
        $pages = (array) config('admin_settings.pages');
        $problems = [];

        foreach ($pages as $pageId => $page) {
            $expert = array_keys(array_filter($settings, fn (array $s): bool => $s['page'] === $pageId && $s['level'] === 'expert'));

            if ($expert === []) {
                continue;
            }

            if ($page['level'] === 'expert') {
                // A developer page answers with the 403 page that switches Developer mode on, and names the setting.
                foreach ($expert as $key) {
                    $response = $this->actingAs($admin)->get($page['url'].'?setting='.$key);
                    $html = (string) $response->getContent();

                    if ($response->getStatusCode() !== 403 || ! str_contains($html, 'Turn on Developer mode')
                        || ! str_contains($html, e(__($settings[$key]['label']))) || ! str_contains($html, 'name="anchor" value="'.$key.'"')) {
                        $problems[] = "{$key}: {$page['url']} does not name it with a Turn on Developer mode button";
                    }
                }

                continue;
            }

            $html = (string) $this->actingAs($admin)->get($page['url'])->assertOk()->getContent();

            foreach ($this->notShown($html, $expert) as $key) {
                $problems[] = "{$key}: {$page['url']} has no row for it while Developer mode is off";
            }

            if (! str_contains($html, 'This setting appears in Developer mode (account menu).')
                || ! str_contains($html, 'name="key" value="developer_mode"') || ! str_contains($html, 'Turn on Developer mode')) {
                $problems[] = "{$pageId}: no note and no Turn on Developer mode button";
            }

            // The row says so, and no control of a developer setting is on the page.
            foreach ($expert as $key) {
                if (str_contains($html, 'name="groups['.$settings[$key]['group'].']['.$key.']"')) {
                    $problems[] = "{$key}: its control is on {$pageId} although Developer mode is off";
                }
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_every_saved_and_stored_setting_has_a_field_or_a_reason(): void
    {
        $catalogue = (array) config('admin_settings');

        $this->assertSame([], array_merge(
            $this->unlistedKeys($catalogue, $this->saveSchemaKeys(), 'save schema'),
            $this->unlistedKeys($catalogue, $this->exampleSqlKeys(), 'example.sql'),
        ));
        $this->assertGreaterThan(500, count($this->exampleSqlKeys()), 'the settings rows of example.sql are read');
    }

    public function test_every_site_setting_the_code_reads_has_a_field_or_a_reason(): void
    {
        $reads = $this->siteConfigReads();

        $this->assertGreaterThan(200, count($reads), 'the code is scanned');
        $this->assertArrayHasKey('site.require_strong_password', $reads);
        $this->assertSame([], $this->unlistedSiteReads((array) config('admin_settings'), $reads));
    }

    public function test_settings_the_code_reads_without_a_field_got_one(): void
    {
        // Read by the sign-up, password reset, profile and member forms, and by the API sign-up, with no switch before.
        foreach (['require_strong_password' => 'security', 'api_registration' => 'api'] as $key => $group) {
            $setting = config("admin_settings.settings.{$key}");
            $this->assertIsArray($setting, $key);
            $this->assertSame([$group, 'bool', 'settings.members', 'advanced'], [$setting['group'], $setting['type'], $setting['page'], $setting['level']], $key);
            $this->assertArrayHasKey($key, app(\App\Http\Controllers\Admin\SettingsController::class)->saveSchema()[$group], "{$key} can be saved");
        }

        $admin = $this->admin(developer: false);
        $this->actingAs($admin)->get('/admin/settings/members')
            ->assertOk()
            ->assertSee('id="require_strong_password-row"', false)
            ->assertSee('id="api_registration-row"', false)
            ->assertSee('Ask for strong passwords')
            ->assertSee('Sign-ups from apps');

        $this->actingAs($admin)
            ->putJson('/admin/settings/save', [
                'groups' => ['security' => ['require_strong_password' => '1'], 'api' => ['api_registration' => '1']],
                'fields' => ['security' => ['require_strong_password'], 'api' => ['api_registration']],
            ])
            ->assertOk()
            ->assertJsonPath('saved', ['security' => ['require_strong_password'], 'api' => ['api_registration']]);

        $this->assertSame('1', \App\Models\Setting::getGroup('security')['require_strong_password'] ?? null);
        $this->assertSame('1', \App\Models\Setting::getGroup('api')['api_registration'] ?? null);
    }

    public function test_the_settings_that_had_no_page_in_the_new_admin_have_one(): void
    {
        // Unreachable in 1.2.2 (only the old admin could change them): the CORS rules on the Developer zone, the Terms
        // text on Info pages, the blocked ranges on Blocked addresses, the switcher and right-to-left on Languages.
        $admin = $this->admin(developer: true);
        // A stored value other than the default ("*") prints "Default: *  Reset": shown as it is, never as a translation key.
        \App\Models\Setting::set('cors_allowed_origins', 'https://app.example.com', 'api');
        \App\Models\Setting::set('cors_allowed_methods', 'GET,POST', 'api');

        $this->actingAs($admin)->get('/admin/env-editor')->assertOk()
            ->assertSee('id="cors_allowed_origins-row"', false)->assertSee('id="cors_supports_credentials-row"', false)
            ->assertSee('Default: *');
        $this->actingAs($admin)->get('/admin/pages')->assertOk()->assertSee('id="terms_content-row"', false);
        $this->actingAs($admin)->get('/admin/ip-bans')->assertOk()->assertSee('id="global_ip_blacklist-row"', false);
        $languages = (string) $this->actingAs($admin)->get('/admin/languages')->assertOk()
            ->assertSee('id="allow_language_switch-row"', false)->assertSee('id="rtl_support-row"', false)->getContent();

        // The texts say what the code does. rtl_support turns EVERY language and the admin right to left (Arabic and the
        // other right-to-left languages do it by themselves), so it is no "turn on for Arabic" switch: under More options.
        $this->assertSame(['advanced', 'Show every language right to left'], [config('admin_settings.settings.rtl_support.level'), config('admin_settings.settings.rtl_support.label')]);
        $this->assertMatchesRegularExpression('/<details class="ad-more"[^>]*>.*id="rtl_support-row"/s', $languages);
        $this->assertStringContainsString(e('Arabic, Persian, Hebrew and Urdu already show right to left by themselves.'), $languages);
        $this->assertStringNotContainsString('so the layout flips correctly', $languages);
        // "*" means any site only while signed-in requests from other sites are off (App\Support\CorsConfig).
        $this->assertStringContainsString('only your own site while they are on', (string) config('admin_settings.settings.cors_allowed_origins.help'));
        $this->assertSame('0', config('admin_settings.settings.cors_max_age.default'), 'the required field shows the value config/cors.php uses');
        // The Terms page prints the text as HTML and shows the standard terms while it is empty.
        $this->assertStringContainsString('in HTML', (string) config('admin_settings.settings.terms_content.help'));

        // And they are saved like every other setting.
        $this->actingAs($admin)
            ->putJson('/admin/settings/save', [
                'groups' => ['localization' => ['rtl_support' => '1'], 'pages' => ['terms_content' => '<p>Our rules</p>']],
                'fields' => ['localization' => ['rtl_support'], 'pages' => ['terms_content']],
            ])
            ->assertOk();
        $this->assertSame('1', \App\Models\Setting::getGroup('localization')['rtl_support'] ?? null);
    }

    public function test_what_moved_where_lists_every_old_settings_tab(): void
    {
        $html = (string) $this->actingAs($this->admin(developer: false))->get(route('admin.shell.moved'))->assertOk()->getContent();
        $tabs = (array) config('admin_settings.old_tabs');

        $this->assertCount(19, $tabs, 'the old settings page had 19 tabs');

        foreach ($tabs as $tab => $info) {
            $this->assertStringContainsString('data-old-tab="'.$tab.'"', $html, "old tab {$tab}");
        }

        // Every setting of an old tab is counted on the page that holds it now.
        $counted = [];
        foreach ((array) config('admin_settings.settings') as $key => $setting) {
            if (($setting['old'][0] ?? null) !== null && $setting['level'] !== 'hidden') {
                $counted[$setting['old'][0]][$setting['page']] = ($counted[$setting['old'][0]][$setting['page']] ?? 0) + 1;
            }
        }
        $this->assertStringContainsString('data-old-tab="uploads"', $html);
        preg_match('/<tr data-old-tab="uploads">(.*?)<\/tr>/s', $html, $row);
        $this->assertStringContainsString(e(__('Videos & uploads')), $row[1] ?? '');
        $this->assertStringContainsString('('.$counted['uploads']['settings.videos'].')', $row[1] ?? '');
        $this->assertStringContainsString(__('Search finds every setting by its old name too.'), $html);
    }

    // ---------------------------------------------------------------- the coverage catches a setting without a page

    public function test_a_setting_without_a_page_is_caught(): void
    {
        $catalogue = (array) config('admin_settings');
        $catalogue['settings']['lane_g_probe'] = ['group' => 'general', 'type' => 'string', 'page' => 'settings.nowhere', 'card' => 'x', 'level' => 'basic', 'label' => 'Probe', 'help' => '', 'control' => 'text', 'default' => '', 'old' => [null, '']];
        $catalogue['settings']['lane_g_probe_2'] = ['group' => 'general', 'type' => 'string', 'page' => 'settings.site', 'card' => 'site', 'level' => 'basic', 'label' => 'Probe 2', 'help' => '', 'control' => 'text', 'default' => '', 'old' => [null, '']];

        $problems = $this->catalogueProblems($catalogue);

        $this->assertContains('lane_g_probe: its page settings.nowhere does not exist', $problems);
        $this->assertContains('lane_g_probe_2: the card settings.site/site does not list it', $problems);
    }

    public function test_a_setting_its_page_does_not_show_is_caught(): void
    {
        // A page of the catalogue whose address shows another page's settings form (as the Developer zone, Info pages,
        // Blocked addresses and Languages did in 1.2.2): its setting is reported.
        $pages = (array) config('admin_settings.pages');
        $pages['lane.probe'] = ['section' => 'money', 'slug' => null, 'url' => '/admin/plans', 'route' => 'admin.plans.index', 'label' => 'Probe', 'description' => '', 'level' => 'basic', 'template' => 'T2', 'rendered_by' => null,
            'cards' => ['probe' => ['title' => 'Probe', 'description' => '', 'level' => 'basic', 'fields' => ['lane_g_probe']]]];
        config([
            'admin_settings.pages' => $pages,
            'admin_settings.settings.lane_g_probe' => ['group' => 'general', 'type' => 'string', 'page' => 'lane.probe', 'card' => 'probe', 'level' => 'basic', 'label' => 'Probe', 'help' => '', 'control' => 'text', 'default' => '', 'old' => [null, '']],
        ]);

        $this->assertSame(['lane_g_probe is not shown on lane.probe (/admin/plans)'], $this->settingsNotShown($this->admin(developer: true), ['lane.probe', 'money.plans']));
    }

    public function test_a_stored_or_read_setting_without_a_field_or_reason_is_caught(): void
    {
        $catalogue = (array) config('admin_settings');

        $this->assertSame(['lane_g_orphan (example.sql): no admin field and no reason in no_field'], $this->unlistedKeys($catalogue, ['site_name', 'lane_g_orphan'], 'example.sql'));
        $this->assertSame(
            ['site.lane_g_orphan (read in app/X.php:1): no admin field and no reason in no_field'],
            $this->unlistedSiteReads($catalogue, ['site.site_name' => ['app/X.php:1'], 'site.lane_g_orphan' => ['app/X.php:1'], 'site.admin_email' => ['app/X.php:2']]),
        );
    }

    private function admin(bool $developer): User
    {
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', $developer);

        return $admin->fresh();
    }
}
