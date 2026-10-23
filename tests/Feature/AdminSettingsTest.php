<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\Language;
use App\Services\SeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_settings_are_grouped_for_readability(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.settings.index', ['tab' => 'general']))
            ->assertOk()
            ->assertSee('Site Identity & Branding')
            ->assertSee('Catalog Pagination & Widget Limits')
            ->assertSee('Feature & Access Switches')
            ->assertSee('Site Name')
            ->assertSee('Videos Per Page');
    }

    public function test_admin_settings_update_writes_values_and_clears_cache(): void
    {
        $admin = User::factory()->admin()->create();

        Setting::set('maintenance_retry_after', '30', 'maintenance');
        Cache::forever('settings.maintenance', ['maintenance_retry_after' => '30']);
        Cache::forever('setting.maintenance_retry_after', '30');

        $this->actingAs($admin)
            ->put(route('admin.settings.update.group', 'maintenance'), [
                'maintenance_mode_enabled' => '0',
                'maintenance_allow_admins' => '1',
                'maintenance_secret' => '',
                'maintenance_message' => 'Maintenance window',
                'maintenance_retry_after' => '120',
                'maintenance_allowed_ips' => '',
            ])
            ->assertRedirect(route('admin.settings.index', ['tab' => 'maintenance']));

        $this->assertSame('120', Setting::get('maintenance_retry_after'));
        $this->assertSame('120', Setting::getGroup('maintenance')['maintenance_retry_after']);
    }

    public function test_unknown_settings_group_returns_a_json_validation_error(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->putJson(route('admin.settings.update.group', 'unknown-group'), ['example' => 'value'])
            ->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'success' => false,
                'group' => 'unknown-group',
                'message' => 'This settings group is not editable.',
            ]);
    }

    public function test_unknown_settings_group_returns_a_form_error_instead_of_success_feedback(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('admin.settings.index'))
            ->put(route('admin.settings.update.group', 'unknown-group'), [])
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHasErrors(['group' => 'This settings group is not editable.']);
    }

    public function test_localization_settings_update_syncs_default_language(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.settings.update.group', 'localization'), [
                'default_locale' => 'vi',
                'timezone' => 'Asia/Ho_Chi_Minh',
                'date_format' => 'Y-m-d',
            ])
            ->assertRedirect(route('admin.settings.index', ['tab' => 'localization']));

        $this->assertSame('vi', Setting::get('default_locale'));
        $this->assertSame('0', Setting::get('allow_language_switch'));
        $this->assertTrue((bool) Language::query()->where('code', 'vi')->value('is_default'));
        $this->assertFalse((bool) Language::query()->where('code', 'en')->value('is_default'));
    }

    public function test_seo_settings_render_domain_template_controls(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.settings.index', ['tab' => 'seo']))
            ->assertOk()
            ->assertSee('name="home_meta_title_template"', false)
            ->assertSee('name="category_meta_description_template"', false)
            ->assertSee('name="video_meta_title_template"', false)
            ->assertSee('name="profile_uid_urls_enabled"', false)
            ->assertSee('name="search_pages_indexable"', false);
    }

    public function test_admin_can_update_domain_seo_template_controls(): void
    {
        $admin = User::factory()->admin()->create();

        $payload = $this->validSeoPayload([
            'home_meta_title_template' => 'Fresh videos on {site_domain}',
            'category_meta_description_template' => '{category} archive on {site_name} with {videos_count} videos.',
            'profile_uid_urls_enabled' => '0',
            'search_pages_indexable' => '1',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.settings.update.group', 'seo'), $payload)
            ->assertRedirect(route('admin.settings.index', ['tab' => 'seo']));

        $this->assertSame('Fresh videos on {site_domain}', Setting::get('home_meta_title_template'));
        $this->assertSame('{category} archive on {site_name} with {videos_count} videos.', Setting::get('category_meta_description_template'));
        $this->assertSame('0', Setting::get('profile_uid_urls_enabled'));
        $this->assertSame('1', Setting::get('search_pages_indexable'));
    }

    private function validSeoPayload(array $overrides = []): array
    {
        return array_merge([
            'meta_title_template' => '{title} | {site_name}',
            'default_meta_description' => 'Default description',
            ...SeoService::templateDefaults(),
            'og_image' => '',
            'twitter_card_type' => 'summary_large_image',
            'twitter_handle' => '',
            'facebook_app_id' => '',
            'google_verification' => '',
            'adult_rating_enabled' => '1',
            'profile_uid_urls_enabled' => '1',
            'video_pages_indexable' => '1',
            'category_pages_indexable' => '1',
            'profile_pages_indexable' => '1',
            'playlist_pages_indexable' => '1',
            'search_pages_indexable' => '0',
            'video_url_prefix' => 'video',
            'video_url_pattern' => '{slug}-{uuid}',
            'robots_max_snippet' => '-1',
            'robots_max_video_preview' => '-1',
            'robots_max_image_preview' => 'large',
            'schema_organization_type' => 'Organization',
            'canonical_enabled' => '1',
            'hreflang_enabled' => '1',
            'website_schema_enabled' => '1',
            'search_action_schema_enabled' => '1',
            'video_schema_enabled' => '1',
            'video_seek_action_enabled' => '0',
            'sitemap_enabled' => '1',
            'video_sitemap_enabled' => '1',
            'sitemap_video_limit' => '1000',
            'indexnow_enabled' => '0',
            'indexnow_key' => '',
            'indexnow_endpoint' => 'https://api.indexnow.org/indexnow',
            'robots_txt' => '',
        ], $overrides);
    }
}
