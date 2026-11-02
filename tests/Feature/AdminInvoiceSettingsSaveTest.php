<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invoice seller settings (Money > Payment setup > Invoices: generate_config.py and its two outputs) are
 * saved by the new admin and reach the invoice PDF; the whole Payment setup page still saves without JavaScript.
 */
class AdminInvoiceSettingsSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // the new admin (phpunit.xml runs the suite with ADMIN_UI=v1, where /admin/money/setup redirects to the old page)
        config(['admin.ui' => 'v2']);
    }

    public function test_the_invoice_seller_settings_are_saved_and_printed(): void
    {
        $admin = User::factory()->admin()->create();
        $fields = ['invoice_seller_name', 'invoice_seller_address', 'invoice_seller_tax_id', 'invoice_tax_label', 'invoice_tax_rate'];

        $this->actingAs($admin)->get('/admin/money/setup')->assertOk()->assertSee('id="invoice_seller_name-row"', false);

        $this->actingAs($admin)
            ->putJson('/admin/settings/save', [
                'groups' => ['monetization' => [
                    'invoice_seller_name' => 'Clip Town Ltd',
                    'invoice_seller_address' => "2 Market Street\nDublin",
                    'invoice_seller_tax_id' => 'IE1234567T',
                    'invoice_tax_label' => 'VAT',
                    'invoice_tax_rate' => '23',
                ]],
                'fields' => ['monetization' => $fields],
            ])
            ->assertOk()
            ->assertJsonPath('saved', ['monetization' => $fields]);

        $this->assertSame('Clip Town Ltd', Setting::getGroup('monetization')['invoice_seller_name'] ?? null);
        $this->assertSame(23.0, InvoiceService::seller()['tax_rate']);

        $this->actingAs($admin)
            ->putJson('/admin/settings/save', [
                'groups' => ['monetization' => ['invoice_tax_rate' => '150']],
                'fields' => ['monetization' => ['invoice_tax_rate']],
            ])
            ->assertStatus(422);
    }

    /** Without JavaScript the page sends its whole manifest: Premium, Stripe, PayPal and the Invoices card together. */
    public function test_the_payment_setup_page_saves_as_a_whole_without_javascript(): void
    {
        $admin = User::factory()->admin()->create();
        $html = (string) $this->actingAs($admin)->get('/admin/money/setup')->assertOk()->getContent();

        preg_match_all('#<input type="hidden" name="fields\[([a-z_]+)\]" value="([^"]*)">#', $html, $rows, PREG_SET_ORDER);
        $manifest = [];
        foreach ($rows as [, $group, $keys]) {
            $manifest[$group] = html_entity_decode($keys);
        }
        $this->assertStringContainsString('invoice_seller_name', $manifest['monetization'] ?? '', 'the Invoices card is in the page');

        $this->actingAs($admin)->from('/admin/money/setup')
            ->put('/admin/settings/save', [
                'groups' => ['monetization' => ['premium_enabled' => '1', 'invoice_seller_name' => 'Clip Town Ltd', 'invoice_tax_rate' => '0']],
                'fields' => $manifest,
            ])
            ->assertRedirect('/admin/money/setup')
            ->assertSessionHasNoErrors();

        $this->assertSame('Clip Town Ltd', Setting::getGroup('monetization')['invoice_seller_name'] ?? null);
    }
}
