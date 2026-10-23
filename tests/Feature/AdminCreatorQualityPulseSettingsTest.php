<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsController;
use App\Models\Setting;
use App\Models\User;
use App\Support\ChannelCustomizationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

class AdminCreatorQualityPulseSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_settings_form_renders_creator_quality_pulse_controls(): void
    {
        $this->withoutVite();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.settings.index', ['tab' => 'users']))
            ->assertOk()
            ->assertSee('Creator Quality Pulse')
            ->assertSee('name="creator_quality_pulse_enabled"', false)
            ->assertSee('name="creator_quality_pulse_limit"', false)
            ->assertSee('name="creator_quality_pulse_lookback_days"', false);
    }

    public function test_users_settings_form_renders_studio_health_alerts_controls(): void
    {
        $this->withoutVite();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.settings.index', ['tab' => 'users']))
            ->assertOk()
            ->assertSee('Studio Health Alerts')
            ->assertSee('name="studio_health_alerts_enabled"', false)
            ->assertSee('name="studio_health_alerts_limit"', false)
            ->assertSee('name="studio_health_alerts_lookback_days"', false);
    }

    public function test_users_settings_form_renders_creator_recovery_checklist_controls(): void
    {
        $this->withoutVite();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.settings.index', ['tab' => 'users']))
            ->assertOk()
            ->assertSee('Creator Recovery Checklist')
            ->assertSee('name="creator_recovery_checklist_enabled"', false)
            ->assertSee('name="creator_recovery_checklist_limit"', false)
            ->assertSee('name="creator_recovery_checklist_inactivity_days"', false);
    }

    public function test_users_settings_form_renders_every_users_schema_field(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.settings.index', ['tab' => 'users']))
            ->assertOk()
            ->assertSee(route('admin.settings.update.group', 'users'), false);

        foreach (array_keys($this->usersSettingsSchema()) as $key) {
            $response->assertSee('name="' . $key . '"', false);
        }
    }

    public function test_admin_can_update_creator_quality_pulse_settings_and_clear_cache(): void
    {
        $admin = User::factory()->admin()->create();

        Setting::set('creator_quality_pulse_limit', '5', 'users');
        Setting::set('creator_quality_pulse_lookback_days', '30', 'users');
        Setting::set('studio_health_alerts_limit', '4', 'users');
        Setting::set('studio_health_alerts_lookback_days', '14', 'users');
        Setting::set('creator_recovery_checklist_limit', '4', 'users');
        Setting::set('creator_recovery_checklist_inactivity_days', '21', 'users');
        Cache::forever('settings.__all_grouped', ['users' => [
            'creator_quality_pulse_limit' => '5',
            'studio_health_alerts_limit' => '4',
            'creator_recovery_checklist_limit' => '4',
        ]]);
        Cache::forever('settings.users', [
            'creator_quality_pulse_limit' => '5',
            'studio_health_alerts_limit' => '4',
            'creator_recovery_checklist_limit' => '4',
        ]);
        Cache::forever('setting.creator_quality_pulse_limit', '5');
        Cache::forever('setting.creator_quality_pulse_lookback_days', '30');
        Cache::forever('setting.studio_health_alerts_limit', '4');
        Cache::forever('setting.studio_health_alerts_lookback_days', '14');
        Cache::forever('setting.creator_recovery_checklist_limit', '4');
        Cache::forever('setting.creator_recovery_checklist_inactivity_days', '21');

        $this->actingAs($admin)
            ->put(route('admin.settings.update.group', 'users'), $this->validUsersPayload())
            ->assertRedirect(route('admin.settings.index', ['tab' => 'users']));

        $this->assertDatabaseHas('settings', [
            'key' => 'creator_quality_pulse_enabled',
            'value' => '1',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'creator_quality_pulse_limit',
            'value' => '7',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'creator_quality_pulse_lookback_days',
            'value' => '45',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'studio_health_alerts_enabled',
            'value' => '1',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'studio_health_alerts_limit',
            'value' => '6',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'studio_health_alerts_lookback_days',
            'value' => '21',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'creator_recovery_checklist_enabled',
            'value' => '1',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'creator_recovery_checklist_limit',
            'value' => '6',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'creator_recovery_checklist_inactivity_days',
            'value' => '28',
            'group' => 'users',
        ]);
        $this->assertFalse(Cache::has('settings.__all_grouped'));
        $this->assertFalse(Cache::has('settings.users'));
        $this->assertFalse(Cache::has('setting.creator_quality_pulse_limit'));
        $this->assertFalse(Cache::has('setting.creator_quality_pulse_lookback_days'));
        $this->assertFalse(Cache::has('setting.studio_health_alerts_limit'));
        $this->assertFalse(Cache::has('setting.studio_health_alerts_lookback_days'));
        $this->assertFalse(Cache::has('setting.creator_recovery_checklist_limit'));
        $this->assertFalse(Cache::has('setting.creator_recovery_checklist_inactivity_days'));
    }

    public function test_creator_quality_pulse_settings_reject_out_of_range_values(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->from(route('admin.settings.index', ['tab' => 'users']))
            ->put(route('admin.settings.update.group', 'users'), $this->validUsersPayload([
                'creator_quality_pulse_limit' => '13',
                'creator_quality_pulse_lookback_days' => '181',
                'studio_health_alerts_limit' => '13',
                'studio_health_alerts_lookback_days' => '181',
                'creator_recovery_checklist_limit' => '13',
                'creator_recovery_checklist_inactivity_days' => '181',
            ]))
            ->assertSessionHasErrors([
                'creator_quality_pulse_limit',
                'creator_quality_pulse_lookback_days',
                'studio_health_alerts_limit',
                'studio_health_alerts_lookback_days',
                'creator_recovery_checklist_limit',
                'creator_recovery_checklist_inactivity_days',
            ]);

        $this->assertDatabaseMissing('settings', [
            'key' => 'creator_quality_pulse_limit',
            'value' => '13',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'studio_health_alerts_limit',
            'value' => '13',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'creator_recovery_checklist_limit',
            'value' => '13',
        ]);
    }

    public function test_non_admin_user_cannot_update_creator_quality_pulse_settings(): void
    {
        Setting::set('allow_account_deletion', '1', 'users');
        Setting::set('creator_quality_pulse_limit', '5', 'users');
        Setting::set('studio_health_alerts_limit', '4', 'users');
        Setting::set('creator_recovery_checklist_limit', '5', 'users');
        Setting::set('creator_recovery_checklist_inactivity_days', '21', 'users');

        $this->actingAs(User::factory()->create())
            ->putJson(route('admin.settings.update.group', 'users'), $this->validUsersPayload([
                'allow_account_deletion' => '0',
                'creator_quality_pulse_limit' => '8',
                'studio_health_alerts_limit' => '9',
                'creator_recovery_checklist_limit' => '10',
                'creator_recovery_checklist_inactivity_days' => '77',
            ]))
            ->assertNotFound();

        $this->assertDatabaseHas('settings', [
            'key' => 'allow_account_deletion',
            'value' => '1',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'creator_quality_pulse_limit',
            'value' => '5',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'studio_health_alerts_limit',
            'value' => '4',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'creator_recovery_checklist_limit',
            'value' => '5',
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'creator_recovery_checklist_inactivity_days',
            'value' => '21',
            'group' => 'users',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'allow_account_deletion',
            'value' => '0',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'creator_quality_pulse_limit',
            'value' => '8',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'studio_health_alerts_limit',
            'value' => '9',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'creator_recovery_checklist_limit',
            'value' => '10',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'creator_recovery_checklist_inactivity_days',
            'value' => '77',
        ]);
    }

    private function validUsersPayload(array $overrides = []): array
    {
        return array_replace([
            'max_register_attempts' => '3',
            'max_avatar_size' => '4096',
            'max_banner_size' => '8192',
            'allow_account_deletion' => '1',
            'creator_quality_pulse_enabled' => '1',
            'creator_quality_pulse_limit' => '7',
            'creator_quality_pulse_lookback_days' => '45',
            'studio_health_alerts_enabled' => '1',
            'studio_health_alerts_limit' => '6',
            'studio_health_alerts_lookback_days' => '21',
            'creator_recovery_checklist_enabled' => '1',
            'creator_recovery_checklist_limit' => '6',
            'creator_recovery_checklist_inactivity_days' => '28',
        ] + ChannelCustomizationSettings::defaults(), $overrides);
    }

    private function usersSettingsSchema(): array
    {
        $method = new ReflectionMethod(SettingsController::class, 'settingsSchema');
        $method->setAccessible(true);

        return $method->invoke(app(SettingsController::class))['users'];
    }
}
