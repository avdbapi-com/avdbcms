<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsPageController;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Passwords and keys in the redesigned admin are write-only: the pages never print them (they only say "Saved"),
 * an empty field keeps the stored value, and the maintenance preview link is shown only after the admin types
 * their password again. The IndexNow key stays visible because search engines read it from the site anyway.
 */
class AdminSecretsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETS = [
        'smtp_password' => ['email', 'Smtp-Secret-7f3a91'],
        'upload18_api_key' => ['uploads', 'U18-Key-c0ffee42'],
        'captcha_secret_key' => ['security', 'Captcha-Secret-5b2d'],
        'maintenance_secret' => ['maintenance', 'bypass-word-9e7c1'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2']);

        foreach (self::SECRETS as $key => [$group, $value]) {
            Setting::set($key, $value, $group);
        }
    }

    public function test_no_admin_page_of_the_redesigned_settings_prints_a_secret(): void
    {
        $admin = User::factory()->admin()->create();
        $urls = array_merge(
            ['/admin/settings', '/admin/settings?q=password', '/admin/settings?q=secret', '/admin/money', '/admin/money/ads', '/admin/money/setup', '/admin/money/premium-page'],
            array_map(fn (string $slug): string => '/admin/settings/'.$slug, array_keys(SettingsPageController::PAGES))
        );

        foreach ($urls as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            foreach (self::SECRETS as $key => [, $value]) {
                $this->assertStringNotContainsString($value, $html, "{$key} printed on {$url}");
            }
        }

        // The rows say that a value is saved, without printing it.
        $this->actingAs($admin)->get('/admin/settings/email')
            ->assertSee('data-write-only="1"', false)
            ->assertSee('data-secret-status', false)
            ->assertSee('Saved');
    }

    public function test_developer_mode_shows_the_expert_settings_without_printing_secrets(): void
    {
        $admin = User::factory()->admin()->create();

        if (! \App\Support\AdminPreferences::available($admin)) {
            $this->markTestSkipped('Developer mode needs the users.admin_preferences column (WP6 migration).');
        }

        \App\Support\AdminPreferences::set($admin, 'developer_mode', true);

        $this->actingAs($admin)->get('/admin/settings/videos')
            ->assertOk()
            ->assertSee('id="upload18_api_key-row"', false)
            ->assertSee('data-secret-status', false)
            ->assertDontSee('U18-Key-c0ffee42');

        $this->actingAs($admin)->get('/admin/settings/security')
            ->assertOk()
            ->assertSee('id="admin_ip_whitelist-row"', false)
            ->assertSee('Your address is 127.0.0.1. When the list is not empty, saving adds your address to it so you keep access.')
            ->assertSee('If it is not in the list you will be locked out immediately. We add it for you.')
            ->assertSee('Your current address is 127.0.0.1.')
            ->assertDontSee('Captcha-Secret-5b2d');
    }

    public function test_an_empty_secret_keeps_the_saved_value_and_a_new_one_replaces_it(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->putJson('/admin/settings/save', [
                'groups' => ['email' => ['smtp_password' => '', 'smtp_host' => 'mail.example.com'], 'security' => ['captcha_secret_key' => '']],
                'fields' => ['email' => ['smtp_password', 'smtp_host'], 'security' => ['captcha_secret_key']],
            ])
            ->assertOk()
            ->assertJsonPath('saved', ['email' => ['smtp_host']]);

        $this->assertSame('Smtp-Secret-7f3a91', Setting::get('smtp_password'));
        $this->assertSame('Captcha-Secret-5b2d', Setting::get('captcha_secret_key'));

        $response = $this->actingAs($admin)
            ->putJson('/admin/settings/save', [
                'groups' => ['email' => ['smtp_password' => 'New-Smtp-Pass-1']],
                'fields' => ['email' => ['smtp_password']],
            ])
            ->assertOk()
            ->assertJsonPath('saved', ['email' => ['smtp_password']]);

        $this->assertSame('New-Smtp-Pass-1', Setting::get('smtp_password'));
        $this->assertStringNotContainsString('New-Smtp-Pass-1', $response->getContent());
    }

    public function test_a_secret_can_be_removed_on_purpose_only(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->putJson('/admin/settings/save', [
                'fields' => ['security' => ['captcha_secret_key']],
                'clear' => ['security' => ['captcha_secret_key']],
            ])
            ->assertOk();
        $this->assertSame('', Setting::get('captcha_secret_key'));

        $this->actingAs($admin)
            ->putJson('/admin/settings/save', [
                'fields' => ['general' => ['site_name']],
                'groups' => ['general' => ['site_name' => 'Kept']],
                'clear' => ['general' => ['site_name']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['general.site_name']);
        $this->assertNotSame('', Setting::get('site_name'));
    }

    public function test_the_indexnow_key_stays_visible_because_it_is_public(): void
    {
        Setting::set('indexnow_key', 'PublicIndexNowKey42', 'seo');

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/settings/seo')
            ->assertOk()
            ->assertSee('value="PublicIndexNowKey42"', false)
            ->assertSee(url('/PublicIndexNowKey42.txt'), false);
    }

    public function test_the_maintenance_link_is_revealed_only_after_the_password(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-horse-battery']);

        $this->postJson('/admin/settings/maintenance/reveal', ['password' => 'correct-horse-battery'])->assertNotFound();
        $this->actingAs($admin)->postJson('/admin/settings/maintenance/reveal', [])->assertStatus(422);

        $this->actingAs($admin)
            ->postJson('/admin/settings/maintenance/reveal', ['password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonMissingPath('secret')
            ->assertDontSee('bypass-word-9e7c1');

        $this->actingAs($admin)
            ->postJson('/admin/settings/maintenance/reveal', ['password' => 'correct-horse-battery'])
            ->assertOk()
            ->assertJsonPath('secret', 'bypass-word-9e7c1')
            ->assertJsonPath('link', url('/bypass-word-9e7c1'));
    }

    public function test_repeated_wrong_passwords_are_slowed_down(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-horse-battery']);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($admin)->postJson('/admin/settings/maintenance/reveal', ['password' => 'nope'.$i])->assertStatus(422);
        }

        $this->actingAs($admin)
            ->postJson('/admin/settings/maintenance/reveal', ['password' => 'correct-horse-battery'])
            ->assertStatus(429)
            ->assertJsonMissingPath('secret');
    }

    public function test_payment_keys_are_written_to_the_environment_file_and_never_shown(): void
    {
        // A .env and a storage folder of the test's own: the backups must not land in the copy's real storage.
        $directory = storage_path('framework/testing/env-'.uniqid());
        File::ensureDirectoryExists($directory.'/storage/app');
        file_put_contents($directory.'/.env', "APP_NAME=Test\nSTRIPE_SECRET=sk_test_OldSecret111\nPAYPAL_MODE=sandbox\n");
        $this->app->useEnvironmentPath($directory);
        $this->app->useStoragePath($directory.'/storage');

        try {
            // Payment keys are saved with the admin's own password (tests/Feature/AdminPaymentKeysTest.php).
            $admin = User::factory()->admin()->create(['password' => 'correct-horse-battery']);
            $password = ['password' => 'correct-horse-battery'];

            $this->actingAs($admin)
                ->putJson('/admin/money/setup/keys', [
                    'STRIPE_KEY' => 'pk_test_Publishable222',
                    'STRIPE_SECRET' => '',
                    'PAYPAL_MODE' => 'live',
                    'CASHIER_CURRENCY' => 'EUR',
                ] + $password)
                ->assertOk()
                ->assertJsonPath('saved', ['STRIPE_KEY', 'CASHIER_CURRENCY', 'PAYPAL_MODE'])
                ->assertDontSee('pk_test_Publishable222')
                ->assertDontSee('sk_test_OldSecret111');

            $env = file_get_contents($directory.'/.env');
            $this->assertStringContainsString("STRIPE_KEY=pk_test_Publishable222\n", $env);
            $this->assertStringContainsString("STRIPE_SECRET=sk_test_OldSecret111\n", $env);
            $this->assertStringContainsString("PAYPAL_MODE=live\n", $env);
            $this->assertStringContainsString("CASHIER_CURRENCY=eur\n", $env);
            $this->assertStringContainsString("APP_NAME=Test\n", $env);

            $this->assertCount(1, glob($directory.'/storage/app/env-backups/.env.*'));

            // Saving the page again with the same currency and mode writes nothing and keeps no new copy.
            $this->travel(5)->seconds();
            $this->actingAs($admin)
                ->putJson('/admin/money/setup/keys', ['STRIPE_KEY' => '', 'PAYPAL_MODE' => 'live', 'CASHIER_CURRENCY' => 'eur'])
                ->assertOk()
                ->assertJsonPath('saved', [])
                ->assertJsonPath('message', 'Nothing to save.');
            $this->assertSame($env, file_get_contents($directory.'/.env'));
            $this->assertCount(1, glob($directory.'/storage/app/env-backups/.env.*'));

            // Only the newest copies are kept.
            foreach (['usd', 'gbp', 'jpy', 'aud', 'cad', 'chf'] as $currency) {
                $this->travel(5)->seconds();
                $this->actingAs($admin)->putJson('/admin/money/setup/keys', ['CASHIER_CURRENCY' => $currency] + $password)->assertOk()->assertJsonPath('saved', ['CASHIER_CURRENCY']);
            }
            $this->assertCount(5, glob($directory.'/storage/app/env-backups/.env.*'));
            $this->assertStringContainsString("CASHIER_CURRENCY=chf\n", file_get_contents($directory.'/.env'));
            $this->assertSame([], glob($directory.'/.env.*.tmp'));

            $this->actingAs($admin)
                ->putJson('/admin/money/setup/keys', ['STRIPE_SECRET' => "sk_test_x\nAPP_DEBUG=true"] + $password)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['STRIPE_SECRET']);
            $this->assertStringNotContainsString('APP_DEBUG', file_get_contents($directory.'/.env'));

            $_ENV['STRIPE_SECRET'] = 'sk_test_ShownNowhere333';
            $this->actingAs($admin)->get('/admin/money/setup')->assertOk()->assertDontSee('sk_test_ShownNowhere333')->assertSee('Saved');
        } finally {
            unset($_ENV['STRIPE_SECRET']);
            File::deleteDirectory($directory);
        }
    }
}
