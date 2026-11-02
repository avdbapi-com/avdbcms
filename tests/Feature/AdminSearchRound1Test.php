<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsPageController;
use App\Models\User;
use App\Support\AdminPreferences;
use App\Support\AdminSettingsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin's two searches after round 1:
 *  - R1-077: the payment keys of Money > Payment setup (values of the .env file) are found, "webhook" first of all, and
 *    a result lands on the key's row; the settings form never draws or saves them;
 *  - R1-155: both searches put the same setting first (tests/js/admin_search.test.ts compares every query's order);
 *  - R1-156: no place reads "Advanced > Advanced": Settings > Advanced shows its cards at once;
 *  - R1-157: Ctrl+K finds a page by the name of its first tab ("Settings file").
 */
class AdminSearchRound1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2', 'app.locale' => 'en']);
    }

    public function test_the_payment_keys_are_found_by_both_searches(): void
    {
        $admin = $this->admin(developer: false);

        $webhook = array_column(SettingsPageController::searchSettings('webhook', $admin), 'key');
        $this->assertSame(['STRIPE_WEBHOOK_SECRET', 'PAYPAL_WEBHOOK_ID'], array_slice($webhook, 0, 2), 'webhook finds the two webhook values first');

        foreach (['STRIPE_KEY' => 'stripe key', 'STRIPE_SECRET' => 'stripe secret key', 'PAYPAL_CLIENT_ID' => 'paypal client id',
                     'CASHIER_CURRENCY' => 'currency', 'PAYPAL_MODE' => 'paypal mode', 'STRIPE_WEBHOOK_SECRET' => 'webhook signing secret'] as $key => $query) {
            $found = array_column(SettingsPageController::searchSettings($query, $admin), 'key');
            $this->assertContains($key, array_slice($found, 0, 6), "\"{$query}\" finds {$key} among the first results");
        }

        $first = SettingsPageController::searchSettings('webhook', $admin)[0];
        $this->assertStringEndsWith('/admin/money/setup#STRIPE_WEBHOOK_SECRET', $first['url']);
        $this->assertSame('Payment setup > Card payments (Stripe)', $first['where']);

        // The Ctrl+K index lists them too, with the same address.
        $index = $this->actingAs($admin)->getJson(route('admin.shell.search-index'))->assertOk()->json();
        $rows = collect($index['settings'])->keyBy('k');
        foreach (['STRIPE_KEY', 'STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET', 'CASHIER_CURRENCY', 'PAYPAL_MODE', 'PAYPAL_CLIENT_ID', 'PAYPAL_CLIENT_SECRET', 'PAYPAL_WEBHOOK_ID'] as $key) {
            $this->assertArrayHasKey($key, $rows->all(), "{$key} is in the Ctrl+K index");
            $this->assertStringEndsWith('/admin/money/setup#'.$key, $rows[$key]['u']);
        }
    }

    public function test_a_payment_key_result_lands_on_its_row_and_the_settings_form_never_draws_or_saves_it(): void
    {
        $admin = $this->admin(developer: false);
        $html = (string) $this->actingAs($admin)->get('/admin/money/setup')->assertOk()->getContent();

        foreach (['STRIPE_KEY', 'STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET', 'CASHIER_CURRENCY', 'PAYPAL_MODE', 'PAYPAL_CLIENT_ID', 'PAYPAL_CLIENT_SECRET', 'PAYPAL_WEBHOOK_ID'] as $key) {
            $this->assertSame(1, substr_count($html, 'id="'.$key.'-row"'), "{$key}: one row to land on (the keys form's)");
            $this->assertStringNotContainsString('name="groups[env]['.$key.']"', $html, "{$key}: the settings form has no control for it");
            $this->assertTrue((bool) (AdminSettingsCatalog::setting($key)['env'] ?? false));
        }

        // The settings save takes no .env key.
        $this->actingAs($admin)
            ->putJson(route('admin.settings.save'), ['groups' => ['env' => ['STRIPE_KEY' => 'pk_test_x']], 'fields' => ['env' => 'STRIPE_KEY']])
            ->assertStatus(422);
    }

    public function test_both_searches_put_the_same_setting_first_for_the_review_queries(): void
    {
        $admin = $this->admin(developer: false);
        $index = $this->actingAs($admin)->getJson(route('admin.shell.search-index'))->assertOk()->json();

        $first = fn (string $query): ?string => SettingsPageController::searchSettings($query, $admin)[0]['key'] ?? null;

        $this->assertSame('smtp_host', $first('smtp'));
        $this->assertSame('trial_period_days', $first('trial'));
        $this->assertSame('custom_code_enabled', $first('custom code'));
        $this->assertSame('premium_enabled', $first('premium'));
        // The index is in the catalogue's order, the order the search of Settings goes through.
        $this->assertSame(array_keys((array) config('admin_settings.settings')), array_column($index['settings'], 'k'));
    }

    public function test_settings_advanced_shows_its_cards_at_once_and_no_place_says_advanced_twice(): void
    {
        $admin = $this->admin(developer: false);
        $html = (string) $this->actingAs($admin)->get('/admin/settings/advanced')->assertOk()->getContent();

        $this->assertStringNotContainsString('<details class="ad-adv"', $html, 'no fold around every card of the page');
        $this->assertStringContainsString('id="card-cleanup"', $html);
        $this->assertStringContainsString('id="search_history_max_rows-row"', $html);
        $this->assertTrue(AdminSettingsCatalog::unfoldsAdvanced('settings.advanced'));
        $this->assertFalse(AdminSettingsCatalog::unfoldsAdvanced('settings.videos'), 'a page with everyday cards keeps its fold');
        $this->assertFalse(AdminSettingsCatalog::unfoldsAdvanced('members.blocked'), 'a list page keeps the fold below its list');

        foreach (['database', 'Notifications sent per batch', 'Most searches kept'] as $query) {
            foreach (SettingsPageController::searchSettings($query, $this->admin(developer: true), 50) as $row) {
                $this->assertStringNotContainsString('Advanced > Advanced', $row['where'], "\"{$query}\": {$row['key']}");
            }
        }

        $index = $this->actingAs($this->admin(developer: true))->getJson(route('admin.shell.search-index'))->assertOk()->json();
        $maxRows = collect($index['settings'])->firstWhere('k', 'search_history_max_rows');
        $this->assertArrayNotHasKey('a', $maxRows, 'Ctrl+K places it on the Advanced page without a fold');

        // Still folded where the page has everyday cards.
        $this->assertSame('advanced', AdminSettingsCatalog::fold('upload18_api_key'));
    }

    public function test_ctrl_k_finds_a_page_by_its_first_tab(): void
    {
        $index = $this->actingAs($this->admin(developer: true))->getJson(route('admin.shell.search-index'))->assertOk()->json();
        $developerZone = collect($index['pages'])->firstWhere('id', 'tools.developer');

        $this->assertNotNull($developerZone);
        $this->assertContains('Settings file', $developerZone['k']);
        $this->assertContains('Checks', collect($index['pages'])->firstWhere('id', 'tools.health')['k']);
    }

    private function admin(bool $developer): User
    {
        $admin = User::factory()->admin()->create();

        if ($developer && AdminPreferences::available($admin)) {
            AdminPreferences::set($admin, 'developer_mode', true);
        }

        return $admin->fresh();
    }
}
