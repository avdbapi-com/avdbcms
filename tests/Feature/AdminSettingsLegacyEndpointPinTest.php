<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsController;
use App\Models\Language;
use App\Models\Setting;
use App\Models\User;
use App\Support\AppearanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\In;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Pins the CURRENT behaviour of the legacy PUT /admin/settings/update/{group}.
 *
 * Fleet scripts call this endpoint, so it must stay byte-identical while the admin is
 * redesigned: same validation, same writes (every key of the group is written, a missing
 * switch saves '0', a missing text saves ''), same side effects and the same response bytes.
 *
 * Each group is saved with the payload an old client sends, generated from the group's
 * rules (settings marked 'only_when_sent' are left out: old clients do not know them).
 * The expectations are computed from the schema, so a key another package adds is still
 * checked by the same rules. GOLDEN was recorded on the untouched controller and holds,
 * per group, a fingerprint of that payload, a hash of the SET of keys the save writes and
 * a hash of the rows it writes. Only what a save writes is hashed (the rows of the keys it
 * writes and every row it adds, changes or deletes), never the rest of the table, so rows
 * that other packages' migrations seed do not matter. The key set is always compared: a
 * key added to a group must be 'only_when_sent', or old clients would reset it. The rows
 * are compared while the payload is the recorded one. Re-record (on the untouched
 * controller) with AVD_PIN_RECORD=1 ... --filter AdminSettingsLegacyEndpointPinTest.
 */
class AdminSettingsLegacyEndpointPinTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-17 12:34:56';

    private const GROUPS = [
        'general', 'uploads', 'notifications', 'email', 'comments', 'users', 'monetization',
        'appearance', 'discovery', 'seo', 'pages', 'security', 'localization', 'validation',
        'planlimits', 'api', 'sociallinks', 'maintenance',
    ];

    /** group => [payload fingerprint, hash of the keys the JSON save writes, hash of the rows it writes] */
    private const GOLDEN = [
        'general' => ['5a2c3d3e73d0d97bc210a004fa75b79247dc71e4', '8077de1b42dd60f3c09ce657aaf9b610e1d561f4', '6b57d9167376982b8920052dee5ecf5d89aaa8bf'],
        'uploads' => ['99c51f575696305064946a977a0a68e14719e16f', '8a0cf3ea4847072dc525bb2bd63a88ca55d65f5f', 'bceee47c0cbb9bc50a30bbf3ae4409e698157977'],
        'notifications' => ['b342f847574d50abffd57f325b774786fa67605a', 'c3c6dc41b892838da547b8a6d0de179f24d3757c', 'bc581dc68e8f0eb33c3fc4df59398a3b97c1921c'],
        'email' => ['f3ccca7cf755839f9ab0f64e0a9e84f91fef3dff', 'db9befcf0ebe9eb91665db95061d11ff76e85094', '89a62269895a24a266d42a7ec8b70c777c2a679e'],
        'comments' => ['68a81ab1c0e7dbeebbf72d2ee367d6a9b7259a88', '37f95afc61667461a14c5ab27e74ea4bd521bbf7', '41ae4ccc811d0cbb5a37fd007f7067896b8165a3'],
        'users' => ['92f56d878c0b6180fd0d9d6512d637d1b7bdb165', '21dac9fa82a379124869caca59dc97eef55a858d', 'f117e67b5fb025d3258a147213465c2338876b4c'],
        'monetization' => ['de3f73aac8d1b3a3d8de9130ed93d749b9e08542', '3977ea07d05b8e4bd9ca060f0eff12d17f3bc632', 'c59c06d6288ed5e8ea99be2a0c8ea4cecf928bf4'],
        'appearance' => ['f8716e9dabc9f8953ed0a6bb5d0e01417bda936f', 'b1b0f43a68a18448000070906cdec20337393775', 'c26d5c1f540cd46b2bfcf2f16f51cd17bc32c102'],
        'discovery' => ['9d29a439060f96a82bd08951270a643117007f41', '15bcd6cfc79a7a628f72470a90d33e4e3ac3253c', '9db7ee78c77e78ff67032f3d70ee2ab1cfeaa431'],
        'seo' => ['29611e7de3e65b547f7a3e435da62f365c2fab32', '44a66a26f143ad75b272284729f6bca6a0d891d1', '3824a0bac17a3d96946fb2d0e96b387b46e40577'],
        'pages' => ['3b3af043ab0712f072e1b0ba662acb3525ebd27e', '986ce29d09adac81939566a7b408f2e3d643b057', '316533c754b5075f41876631157e8a618940632c'],
        'security' => ['cf5e32400741cd088749e6f0e0f5cac34f0d487e', 'd777f5d7474201933a1d9c60f87e454c1ef7cbee', '36fb82a5d697a80ea7e0072108b5c4d6d9f39e0c'],
        'localization' => ['f2e3c4d4a04f6daf426dc977af68ea60c359c37d', 'd6bf6b4f399f4804fb7a7dfc635b036cdb2cbfb0', 'c4cb9a6c7533a71ae7e399b7df0749233264c990'],
        'validation' => ['7dd57f9dfeee195d0da80654a194468d78488d4d', '692f7e7cbd6dea8153830e15b56d16969ce7d83e', 'ad28f1ece2f140d75abcb5be960c853f51d0fdf4'],
        'planlimits' => ['702642b083cdee5d497daf359ac6e6db485464f1', '5a97134e3c64c0442a7f7623db01ba407ce82f7d', '829638eb908224df5e47c28324a2240e6e631d13'],
        'api' => ['c5379af79b0b6f2150c58eb1aafec4745e5d2d65', '2f1aae802ecd9cda8e123a0d0de760946ad01d6d', 'bb06337b1aa9744dcc170575cf9f7e5473f514de'],
        'sociallinks' => ['628c99b2e1c91dbc4ab8d0cfa0534fe1a70c7756', '5a032116068b9e160f107c05346d6ed25a745dcb', '5150728ee549568269b8a8ed7bab873d7b47a5ea'],
        'maintenance' => ['05afb01c2a8d404587a970e17ebe2269e24651af', 'ec16ccfbbdf0c07fd9e9ef4ef5e26824eda45d82', '7a8e62988f6ae73f646899e620539c4b9ad00db3'],
    ];

    public static function settingsGroups(): array
    {
        return array_combine(self::GROUPS, array_map(fn (string $group): array => [$group], self::GROUPS));
    }

    public function test_the_pinned_group_list_is_the_schema_group_list(): void
    {
        $this->assertSame(self::GROUPS, array_keys($this->schema()));
    }

    #[DataProvider('settingsGroups')]
    public function test_json_save_response_bytes_and_writes_are_pinned(string $group): void
    {
        $this->travelTo(Carbon::parse(self::NOW));
        $fields = $this->schema()[$group];
        $payload = $this->payloadFor($group, $fields);
        $before = $this->settingsRows();

        $response = $this->actingAs($this->admin())
            ->putJson('/admin/settings/update/'.$group, $payload)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json');

        $this->assertSame(sprintf(
            '{"ok":true,"success":true,"message":"%s settings saved in real-time.","group":"%s","timestamp":"%s","formatted_time":"%s"}',
            ucfirst($group),
            $group,
            str_replace('/', '\\/', now()->toIso8601String()),
            now()->format('H:i:s')
        ), $response->getContent());

        $after = $this->settingsRows();
        $expected = $this->expectedWrites($group, $fields, $payload);
        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $after, "{$group}.{$key} was not written");
            $this->assertSame([$value, $group], $after[$key], "{$group}.{$key} was written differently");
        }

        // Nothing outside the group (and its documented side effects) changes.
        foreach ($before as $key => $row) {
            if (! array_key_exists($key, $expected)) {
                $this->assertSame($row, $after[$key] ?? null, "{$key} outside {$group} changed");
            }
        }
        $this->assertSame([], array_values(array_diff(array_keys($after), array_keys($before), array_keys($expected))));

        $this->assertGolden($group, $payload, $this->writtenRows($before, $after, array_keys($expected)));
    }

    public function test_settings_added_later_are_written_only_when_a_client_sends_them(): void
    {
        $late = [];
        foreach ($this->schema() as $group => $fields) {
            foreach ($fields as $key => $field) {
                if (! empty($field['only_when_sent'])) {
                    $late[$group][$key] = $field;
                }
            }
        }

        if ($late === []) {
            $this->markTestSkipped('This install has no settings that are written only when sent (the theme catalogue v2 adds them).');
        }

        foreach ($late as $group => $fields) {
            foreach ($fields as $key => $field) {
                Setting::set($key, ($field['type'] ?? 'string') === 'bool' ? '1' : 'kept', $group);
            }

            $this->actingAs($this->admin())
                ->putJson('/admin/settings/update/'.$group, $this->payloadFor($group, $this->schema()[$group]))
                ->assertOk();

            foreach ($fields as $key => $field) {
                $this->assertSame(($field['type'] ?? 'string') === 'bool' ? '1' : 'kept', Setting::getGroup($group)[$key] ?? null, "{$group}.{$key} was written although the client did not send it");
            }

            $sent = [];
            foreach ($fields as $key => $field) {
                $sent[$key] = ($field['type'] ?? 'string') === 'bool' ? '0' : $this->validValue($key, $field['rules']);
            }

            $this->actingAs($this->admin())
                ->putJson('/admin/settings/update/'.$group, array_merge($this->payloadFor($group, $this->schema()[$group]), $sent))
                ->assertOk();

            foreach ($sent as $key => $value) {
                $this->assertSame($value, Setting::getGroup($group)[$key] ?? null, "{$group}.{$key} was not written although the client sent it");
            }
        }
    }

    #[DataProvider('settingsGroups')]
    public function test_form_save_redirects_to_the_group_tab_with_the_pinned_flash(string $group): void
    {
        $fields = $this->schema()[$group];

        $this->actingAs($this->admin())
            ->put('/admin/settings/update/'.$group, $this->payloadFor($group, $fields))
            ->assertStatus(302)
            ->assertRedirect(route('admin.settings.index', ['tab' => $group]))
            ->assertSessionHas('success', ucfirst($group).' settings updated successfully.');
    }

    public function test_a_partial_payload_still_writes_every_key_of_the_group(): void
    {
        Setting::set('maintenance_message', 'Old message', 'maintenance');
        Setting::set('maintenance_allow_admins', '1', 'maintenance');
        Setting::set('maintenance_secret', 'old-secret', 'maintenance');

        $this->actingAs($this->admin())
            ->putJson('/admin/settings/update/maintenance', ['maintenance_retry_after' => '90'])
            ->assertOk();

        $this->assertSame('90', Setting::get('maintenance_retry_after'));
        $this->assertSame('0', Setting::get('maintenance_allow_admins'));
        $this->assertSame('0', Setting::get('maintenance_mode_enabled'));
        $this->assertSame('', Setting::get('maintenance_message'));
        $this->assertSame('', Setting::get('maintenance_secret'));
        $this->assertSame('', Setting::get('maintenance_allowed_ips'));
    }

    public function test_validation_errors_are_pinned_and_write_nothing(): void
    {
        $before = $this->settingsRows();

        $response = $this->actingAs($this->admin())
            ->putJson('/admin/settings/update/maintenance', [
                'maintenance_message' => 'Back soon',
                'maintenance_retry_after' => 'soon',
            ])
            ->assertStatus(422);

        $this->assertSame(
            '{"message":"The maintenance retry after must be an integer.","errors":{"maintenance_retry_after":["The maintenance retry after must be an integer."]}}',
            $response->getContent()
        );
        $this->assertSame($before, $this->settingsRows());

        $this->actingAs($this->admin())
            ->from('/admin/settings?tab=maintenance')
            ->put('/admin/settings/update/maintenance', ['maintenance_retry_after' => ''])
            ->assertRedirect('/admin/settings?tab=maintenance')
            ->assertSessionHasErrors(['maintenance_retry_after']);
        $this->assertSame($before, $this->settingsRows());
    }

    public function test_unknown_group_responses_are_pinned(): void
    {
        $response = $this->actingAs($this->admin())
            ->putJson('/admin/settings/update/cdn', ['cdn_enabled' => '1'])
            ->assertStatus(422);

        $this->assertSame(
            '{"ok":false,"success":false,"message":"This settings group is not editable.","group":"cdn"}',
            $response->getContent()
        );

        $this->actingAs($this->admin())
            ->from('/admin/settings')
            ->put('/admin/settings/update/cdn', ['cdn_enabled' => '1'])
            ->assertRedirect('/admin/settings')
            ->assertSessionHasErrors(['group' => 'This settings group is not editable.']);

        $this->assertNull(Setting::get('cdn_enabled'));
    }

    public function test_seo_addresses_are_normalised_before_they_are_stored(): void
    {
        $fields = $this->schema()['seo'];
        $payload = array_merge($this->payloadFor('seo', $fields), [
            'video_url_prefix' => 'Movies//Clips/',
            'video_url_pattern' => '/{slug}-{id}/',
        ]);

        $this->actingAs($this->admin())->putJson('/admin/settings/update/seo', $payload)->assertOk();

        $this->assertSame('movies/clips', Setting::get('video_url_prefix'));
        $this->assertSame('{slug}-{id}', Setting::get('video_url_pattern'));
    }

    public function test_appearance_keeps_the_mobile_dock_in_sync_with_the_bottom_bar(): void
    {
        $fields = $this->schema()['appearance'];
        $payload = $this->payloadFor('appearance', $fields);

        $payload['mobile_bottom_nav_enabled'] = '1';
        $payload['mobile_bottom_dock_enabled'] = '0';
        $this->actingAs($this->admin())->putJson('/admin/settings/update/appearance', $payload)->assertOk();
        $this->assertSame('1', Setting::get('mobile_bottom_nav_enabled'));
        $this->assertSame('1', Setting::get('mobile_bottom_dock_enabled'));

        unset($payload['mobile_bottom_nav_enabled']);
        $payload['mobile_bottom_dock_enabled'] = '0';
        $this->actingAs($this->admin())->putJson('/admin/settings/update/appearance', $payload)->assertOk();
        $this->assertSame('0', Setting::get('mobile_bottom_nav_enabled'));
        $this->assertSame('0', Setting::get('mobile_bottom_dock_enabled'));
    }

    public function test_appearance_applies_a_theme_preset_only_when_it_was_switched(): void
    {
        $presets = array_keys(AppearanceSettings::themePresets());
        $target = end($presets);
        $fields = $this->schema()['appearance'];
        $payload = array_merge($this->payloadFor('appearance', $fields), [
            'theme_preset' => $target,
            'apply_theme_preset' => '1',
        ]);

        $this->actingAs($this->admin())->putJson('/admin/settings/update/appearance', $payload)->assertOk();

        $this->assertSame($target, Setting::get('theme_preset'));
        $this->assertSame('0', Setting::get('daily_design_auto_enabled'));
        foreach (AppearanceSettings::presetValues($target) as $key => $value) {
            if ($key !== 'theme_preset') {
                $this->assertSame((string) $value, Setting::getGroup('appearance')[$key] ?? null, "preset key {$key}");
            }
        }

        // Saving again with the same preset is a plain save: the posted values win.
        $payload['primary_color'] = '#123456';
        $this->actingAs($this->admin())->putJson('/admin/settings/update/appearance', $payload)->assertOk();
        $this->assertSame('#123456', Setting::getGroup('appearance')['primary_color']);
    }

    public function test_localization_save_moves_the_default_language(): void
    {
        $fields = $this->schema()['localization'];
        $payload = array_merge($this->payloadFor('localization', $fields), ['default_locale' => 'vi']);

        $this->actingAs($this->admin())->putJson('/admin/settings/update/localization', $payload)->assertOk();

        $this->assertSame('vi', Setting::get('default_locale'));
        $this->assertTrue((bool) Language::query()->where('code', 'vi')->value('is_default'));
        $this->assertSame(1, Language::query()->where('is_default', true)->count());
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** @return array<string, array<string, array{rules: array, type?: string}>> */
    private function schema(): array
    {
        $controller = app(SettingsController::class);
        $method = new ReflectionMethod($controller, 'settingsSchema');
        $method->setAccessible(true);

        return $method->invoke($controller);
    }

    /**
     * A valid payload built from the group's rules, as an old client sends it: every second switch is left out,
     * and settings an old client does not know ('only_when_sent') are not sent.
     */
    private function payloadFor(string $group, array $fields): array
    {
        $fields = array_filter($fields, fn (array $field): bool => empty($field['only_when_sent']));
        $special = [
            'video_url_prefix' => 'Watch/Clips/',
            'video_url_pattern' => '/{uuid}/',
            'indexnow_key' => 'PinIndexNowKey123',
            'upload_allowed_formats' => 'mp4, webm',
            'admin_ip_whitelist' => '127.0.0.1',
            'global_ip_blacklist' => '203.0.113.250',
            'maintenance_allowed_ips' => '203.0.113.251',
        ];
        $payload = [];
        $index = 0;

        foreach ($fields as $key => $field) {
            $index++;

            if (($field['type'] ?? 'string') === 'bool') {
                if ($index % 2 === 1) {
                    $payload[$key] = '1';
                }

                continue;
            }

            $payload[$key] = $special[$key] ?? $this->validValue($key, $field['rules']);
        }

        return $payload;
    }

    private function validValue(string $key, array $rules): string
    {
        $max = null;
        $min = null;

        foreach ($rules as $rule) {
            if ($rule instanceof In) {
                $values = str_getcsv(substr((string) $rule, 3));
                foreach ($values as $value) {
                    if ($value !== '') {
                        return $key === 'default_locale' && in_array('vi', $values, true) ? 'vi' : $value;
                    }
                }
            }

            if (is_string($rule) && str_starts_with($rule, 'max:')) {
                $max = (int) substr($rule, 4);
            }

            if (is_string($rule) && str_starts_with($rule, 'min:')) {
                $min = (int) substr($rule, 4);
            }
        }

        if (in_array('timezone', $rules, true)) {
            return 'UTC';
        }

        if (in_array('email', $rules, true)) {
            return 'pin.'.str_replace('_', '-', $key).'@example.com';
        }

        foreach ($rules as $rule) {
            if (is_string($rule) && ($rule === 'url' || str_starts_with($rule, 'url:'))) {
                return 'https://example.com/'.str_replace('_', '-', $key);
            }

            if (is_string($rule) && str_starts_with($rule, 'regex:/^#')) {
                return '#1A2B3C';
            }
        }

        if (in_array('integer', $rules, true)) {
            $value = ($min ?? 0) + 1;

            return (string) ($max !== null ? min($value, $max) : $value);
        }

        return substr('Pin '.$key, 0, $max ?? 255);
    }

    /** What the legacy endpoint writes for the group, computed from the schema. */
    private function expectedWrites(string $group, array $fields, array $payload): array
    {
        $expected = [];

        foreach ($fields as $key => $field) {
            if (! empty($field['only_when_sent']) && ! array_key_exists($key, $payload)) {
                continue;
            }

            if (($field['type'] ?? 'string') === 'bool') {
                $expected[$key] = filter_var($payload[$key] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';

                continue;
            }

            $value = trim((string) ($payload[$key] ?? ''));

            if ($group === 'seo' && $key === 'video_url_prefix') {
                $value = trim($value, '/');
                $value = preg_replace('/[^A-Za-z0-9\-\/]/', '', $value) ?: 'video';
                $value = preg_replace('#/+#', '/', $value);
                $value = strtolower(trim($value, '/')) ?: 'video';
            }

            if ($group === 'seo' && $key === 'video_url_pattern') {
                $value = trim($value, '/');
            }

            $expected[$key] = $value;
        }

        if ($group === 'appearance') {
            $enabled = array_key_exists('mobile_bottom_nav_enabled', $payload)
                ? filter_var($payload['mobile_bottom_nav_enabled'], FILTER_VALIDATE_BOOLEAN)
                : filter_var($payload['mobile_bottom_dock_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $expected['mobile_bottom_nav_enabled'] = $enabled ? '1' : '0';
            $expected['mobile_bottom_dock_enabled'] = $enabled ? '1' : '0';
        }

        return $expected;
    }

    /** @return array<string, array{0: string, 1: string}> key => [value, group] */
    private function settingsRows(): array
    {
        return DB::table('settings')
            ->orderBy('key')
            ->get(['key', 'value', 'group'])
            ->mapWithKeys(fn (object $row): array => [$row->key => [(string) $row->value, (string) $row->group]])
            ->all();
    }

    /**
     * What a save wrote: the rows of the keys it is expected to write plus every row it added, changed or deleted
     * anywhere (null = deleted), by key. Rows it did not touch, such as settings that other packages' migrations
     * seed, are left out: the hash pins the endpoint, not the table it started from.
     *
     * @return array<string, array{0: string, 1: string}|null>
     */
    private function writtenRows(array $before, array $after, array $expectedKeys): array
    {
        $keys = $expectedKeys;

        foreach ($after as $key => $row) {
            if (($before[$key] ?? null) !== $row) {
                $keys[] = $key;
            }
        }

        foreach (array_diff_key($before, $after) as $key => $row) {
            $keys[] = $key;
        }

        $rows = [];
        foreach (array_unique($keys) as $key) {
            $rows[$key] = $after[$key] ?? null;
        }
        ksort($rows, SORT_STRING);

        return $rows;
    }

    private function assertGolden(string $group, array $payload, array $written): void
    {
        $fingerprint = sha1(json_encode($payload));
        $keys = sha1(json_encode(array_keys($written)));
        $rows = sha1(json_encode($written));

        if (getenv('AVD_PIN_RECORD')) {
            fwrite(STDERR, sprintf("        '%s' => ['%s', '%s', '%s'],\n", $group, $fingerprint, $keys, $rows));
        }

        if (! isset(self::GOLDEN[$group])) {
            $this->markTestIncomplete("No golden recorded for {$group}.");
        }

        [$goldenFingerprint, $goldenKeys, $goldenRows] = self::GOLDEN[$group];

        // Old clients send what they always sent, so the keys a save writes never change: a setting added to a
        // group must be 'only_when_sent', or every old client would reset it.
        $this->assertSame($goldenKeys, $keys, "Saving {$group} writes a different set of keys than the legacy endpoint did.");

        if ($goldenFingerprint !== $fingerprint) {
            // Another package changed the group's rules so the generated payload differs (for example the first
            // allowed value of a list): the computed expectations above still pin the behaviour, the rows cannot.
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertSame($goldenRows, $rows, "Saving {$group} writes different rows than the legacy endpoint did.");
    }
}
