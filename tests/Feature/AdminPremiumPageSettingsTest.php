<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsController;
use App\Models\User;
use App\Support\PremiumPageSettings;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\Support\CreatesAdminSettingsSchema;
use Tests\TestCase;

class AdminPremiumPageSettingsTest extends TestCase
{
    use CreatesAdminSettingsSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withMiddleware();
        $this->refreshAdminSettingsSchema();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->dropAdminSettingsSchema();
        Cache::flush();

        parent::tearDown();
    }

    public function test_pages_settings_form_renders_premium_page_controls(): void
    {
        $this->withoutVite();

        $response = $this->actingAs($this->userWithAdminFlag(true))
            ->get(route('admin.settings.index', ['tab' => 'pages']))
            ->assertOk()
            ->assertSee('Premium page content')
            ->assertSee('Premium billing copy')
            ->assertSee('Premium feature cards')
            ->assertSee('Premium checkout timeline')
            ->assertSee('Premium comparison table')
            ->assertSee('Terms of Service');

        foreach (array_keys(PremiumPageSettings::defaults()) as $key) {
            $response->assertSee('name="' . $key . '"', false);
        }

        $response->assertSee('name="terms_content"', false);
    }

    public function test_admin_can_update_premium_page_controls_and_clear_cache(): void
    {
        $schema = $this->pagesSettingsSchema();

        foreach (array_keys($schema) as $key) {
            Cache::forever('setting.' . $key, 'stale');
        }

        Cache::forever('settings.pages', ['stale' => true]);
        Cache::forever('settings.__all_grouped', ['pages' => ['stale' => true]]);

        $payload = $this->validPagesPayload([
            'premium_page_title' => ':site Studio Plus',
            'premium_page_subtitle' => 'Admin controlled premium copy.',
            'premium_feature_uploads_title' => 'Creator upload power',
            'premium_feature_uploads_icon' => 'fa-bolt',
            'premium_comparison_title' => 'Admin plan matrix',
            'premium_unlimited_label' => 'No limit',
        ]);

        unset(
            $payload['premium_feature_downloads_enabled'],
            $payload['premium_comparison_ads_row_enabled'],
            $payload['premium_comparison_ads_premium_enabled']
        );

        $this->actingAs($this->userWithAdminFlag(true))
            ->put(route('admin.settings.update.group', 'pages'), $payload)
            ->assertRedirect(route('admin.settings.index', ['tab' => 'pages']));

        $this->assertDatabaseHas('settings', [
            'key' => 'premium_page_title',
            'value' => ':site Studio Plus',
            'group' => 'pages',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'premium_feature_uploads_icon',
            'value' => 'fa-bolt',
            'group' => 'pages',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'premium_feature_downloads_enabled',
            'value' => '0',
            'group' => 'pages',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'premium_comparison_ads_row_enabled',
            'value' => '0',
            'group' => 'pages',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'premium_unlimited_label',
            'value' => 'No limit',
            'group' => 'pages',
        ]);

        $this->assertFalse(Cache::has('settings.pages'));
        $this->assertFalse(Cache::has('settings.__all_grouped'));

        foreach (array_keys($schema) as $key) {
            $this->assertFalse(Cache::has('setting.' . $key), 'Per-key cache was not cleared for ' . $key);
        }
    }

    public function test_premium_page_icon_settings_reject_invalid_values(): void
    {
        $this->actingAs($this->userWithAdminFlag(true))
            ->from(route('admin.settings.index', ['tab' => 'pages']))
            ->put(route('admin.settings.update.group', 'pages'), $this->validPagesPayload([
                'premium_feature_uploads_icon' => 'fa-"><script>alert(1)</script>',
            ]))
            ->assertSessionHasErrors(['premium_feature_uploads_icon']);

        $this->assertDatabaseMissing('settings', [
            'key' => 'premium_feature_uploads_icon',
            'value' => 'fa-"><script>alert(1)</script>',
        ]);
    }

    private function userWithAdminFlag(bool $isAdmin): User
    {
        $name = $isAdmin ? 'premium-page-admin' : 'premium-page-member';

        return tap(new User(), function (User $user) use ($isAdmin, $name) {
            $user->forceFill([
                'username' => $name,
                'slug' => $name,
                'email' => $name . '@example.test',
                'password' => 'password',
                'is_admin' => $isAdmin,
                'email_verified_at' => now(),
            ]);
            $user->save();
        });
    }

    private function pagesSettingsSchema(): array
    {
        $method = new ReflectionMethod(SettingsController::class, 'settingsSchema');
        $method->setAccessible(true);

        return $method->invoke(app(SettingsController::class))['pages'];
    }

    private function validPagesPayload(array $overrides = []): array
    {
        $defaults = ['terms_content' => '<p>Terms</p>'] + PremiumPageSettings::defaults();

        foreach (PremiumPageSettings::booleanKeys() as $key) {
            $defaults[$key] = '1';
        }

        return array_replace($defaults, $overrides);
    }
}
