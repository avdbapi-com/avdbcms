<?php

namespace Tests\Feature\Member;

use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The invoice PDF (member design 6.24, account-1, OD-M8): the seller block from the owner's settings (Money >
 * Payment setup > Invoices), the site name only until they are filled, never the French company every site printed
 * before (address, SIRET, VAT number, a fixed "TVA (20%)"); a tax line only with a rate; the member's language when the
 * PDF font can draw it; a missing PDF made again instead of a 0-byte download.
 */
class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.name' => 'Clip Town',
            'app.url' => 'https://cliptown.example',
            'cashier.currency' => 'eur',
            'site.date_format' => 'M d, Y',
            'site.default_locale' => 'en',
            'app.locale' => 'en',
        ]);
        foreach (['invoice_seller_name', 'invoice_seller_address', 'invoice_seller_tax_id', 'invoice_tax_label', 'invoice_tax_rate'] as $key) {
            config(['site.'.$key => null]);
        }
    }

    private function payment(array $user = [], int $amount = 500, int $tax = 0, int $months = 1): Transaction
    {
        $member = User::factory()->create($user + ['username' => 'buyer']);
        $plan = Plan::factory()->create(['duration' => $months, 'price' => $amount]);
        $subscription = Subscription::factory()->for($member)->for($plan)->create();

        return Transaction::query()->create([
            'stripe_id' => 'in_test_'.$member->id,
            'amount' => $amount,
            'tax' => $tax,
            'fee' => 0,
            'date' => Carbon::create(2026, 9, 24, 10, 0, 0),
            'user_id' => $member->id,
            'subscription_id' => $subscription->id,
            'name' => 'Ana Buyer',
            'address' => '1 Long Road',
            'city' => 'Lisbon',
            'postal_code' => '1000-001',
            'country' => 'PT',
            'vat_id' => null,
        ]);
    }

    private function html(Transaction $transaction): string
    {
        return view('users.invoice', [
            'transaction' => $transaction,
            'invoice' => InvoiceService::details($transaction, InvoiceService::locale($transaction)),
        ])->render();
    }

    public function test_without_seller_settings_the_invoice_names_the_site_only_and_has_no_tax_line(): void
    {
        $html = $this->html($this->payment());

        $this->assertStringContainsString('Clip Town', $html);
        foreach (['Saint-Michel', 'SIRET', '84011597600036', 'FR44840115976', 'TVA'] as $leftover) {
            $this->assertStringNotContainsString($leftover, $html, 'OD-M8: never another company');
        }
        $this->assertStringNotContainsString('%', strip_tags(preg_replace('#<style.*?</style>#s', '', $html)), 'no tax line without a rate');
        $this->assertStringContainsString('€5.00', $html);
        $this->assertStringContainsString('INV-2026-', $html);
        $this->assertStringContainsString('Sep 24, 2026 – Oct 23, 2026', $html, 'the month the payment pays for');
        $this->assertStringContainsString('Portugal', $html);
    }

    public function test_the_owners_seller_details_and_tax_rate_are_printed(): void
    {
        // saved in Money > Payment setup > Invoices (the monetization group)
        Setting::set('invoice_seller_name', 'Clip Town Ltd', 'monetization');
        Setting::set('invoice_seller_address', "2 Market Street\r\nDublin D02 X285\n\nIreland", 'monetization');
        Setting::set('invoice_seller_tax_id', 'IE1234567T', 'monetization');
        Setting::set('invoice_tax_label', 'VAT', 'monetization');
        Setting::set('invoice_tax_rate', '20', 'monetization');

        $html = $this->html($this->payment([], 600));

        $this->assertStringContainsString('Clip Town Ltd', $html);
        $this->assertStringContainsString('2 Market Street', $html);
        $this->assertStringContainsString('Dublin D02 X285', $html);
        $this->assertStringContainsString(e(__('Tax number: :number', ['number' => 'IE1234567T'])), $html);
        $this->assertStringContainsString('VAT 20%', $html);
        $this->assertStringContainsString('€1.00', $html, 'the tax inside a €6 price at 20%');
        $this->assertStringContainsString('€5.00', $html, 'the subtotal');
        $this->assertStringContainsString('€6.00', $html, 'the total');
    }

    public function test_the_tax_the_payment_service_charged_wins_over_the_computed_one(): void
    {
        config(['site.invoice_tax_rate' => '21', 'site.invoice_tax_label' => 'IVA']);

        $details = InvoiceService::details($this->payment([], 1210, 210));

        $this->assertSame('IVA 21%', $details['tax_label']);
        $this->assertSame('€2.10', $details['tax']);
        $this->assertSame('€10.00', $details['subtotal']);
        $this->assertSame('€12.10', $details['total']);
    }

    public function test_a_wrong_tax_rate_prints_no_tax_line(): void
    {
        foreach (['abc', '-5', '150', '0', ''] as $rate) {
            config(['site.invoice_tax_rate' => $rate]);
            $this->assertSame(0.0, InvoiceService::seller()['tax_rate'], $rate);
        }

        config(['site.invoice_tax_rate' => '7,5']);
        $this->assertSame(7.5, InvoiceService::seller()['tax_rate'], 'a decimal comma is read');
    }

    public function test_the_invoice_is_written_in_the_members_language_when_the_font_can_draw_it(): void
    {
        $french = $this->payment(['language' => 'fr', 'email' => 'fr@example.test', 'username' => 'acheteur']);
        $this->assertSame('fr', InvoiceService::locale($french));

        // Arabic would print unjoined, the others not at all: English
        foreach (['ar', 'ja', 'zh', 'ko', 'hi', 'th'] as $locale) {
            $payment = $this->payment(['language' => $locale, 'email' => $locale.'@example.test', 'username' => 'buyer_'.$locale]);
            $this->assertSame('en', InvoiceService::locale($payment), $locale);
        }

        foreach (['de', 'es', 'pt', 'ru', 'tr', 'vi', 'id'] as $locale) {
            $payment = $this->payment(['language' => $locale, 'email' => $locale.'@example.test', 'username' => 'buyer_'.$locale]);
            $this->assertSame($locale, InvoiceService::locale($payment), $locale);
        }

        // the French invoice is in French; names and addresses stay as typed
        app()->setLocale('fr');
        $html = $this->html($french);
        app()->setLocale('en');
        $this->assertStringContainsString('Ana Buyer', $html);
        $this->assertStringContainsString('lang="fr"', $html);
    }

    public function test_generate_writes_a_pdf_and_a_missing_one_is_made_again(): void
    {
        Storage::fake(config('filesystems.default'));
        $transaction = $this->payment(['language' => 'de', 'email' => 'de@example.test', 'username' => 'kaeufer']);

        $this->assertTrue(app(InvoiceService::class)->ensurePdf($transaction), 'made when missing');
        $path = $transaction->invoice_path;
        Storage::assertExists($path);
        $this->assertStringStartsWith('%PDF', Storage::get($path));
        $this->assertSame('en', app()->getLocale(), 'the page language is put back');

        // a 0-byte file (studio-28 / account-7) is made again
        Storage::put($path, '');
        $this->assertTrue(app(InvoiceService::class)->ensurePdf($transaction));
        $this->assertGreaterThan(1000, Storage::size($path));
    }

    public function test_the_invoice_number_and_labels(): void
    {
        $monthly = $this->payment();
        $this->assertSame(sprintf('INV-2026-%04d', $monthly->id), InvoiceService::number($monthly));
        $this->assertSame(__('Premium, monthly'), InvoiceService::planLabel($monthly));
        $this->assertSame(__('Premium, monthly')."\u{00A0}· September 2026", InvoiceService::title($monthly));
        $this->assertSame('€5.00', InvoiceService::amount($monthly));

        $yearly = $this->payment(['email' => 'y@example.test', 'username' => 'yearly'], 5000, 0, 12);
        $this->assertSame(__('Premium, yearly'), InvoiceService::planLabel($yearly));

        // a plan without a known length: "Premium plan" ("Premium" alone reads "insurance premium" or "bonus" in German,
        // Indonesian and Turkish, three of the invoice languages)
        $unknown = $this->payment(['email' => 'g@example.test', 'username' => 'unknown_length'], 500, 0, 0);
        $this->assertSame(__('Premium plan'), InvoiceService::planLabel($unknown));
    }

    /**
     * The columns and the total say "Price" and "Total paid": the site's shared "Amount" and "Total" read "quantity"
     * (es Cantidad, ru Количество, tr Miktar) and a wrong adjective (fr Totale, es totales, ru Общий) on this legal
     * document.
     */
    public function test_the_invoice_says_price_and_total_paid_in_every_language(): void
    {
        $english = $this->html($this->payment());
        $this->assertStringContainsString('<th class="num">'.e(__('Price')).'</th>', $english);
        $this->assertStringContainsString('<td>'.e(__('Total paid')).'</td>', $english);
        $this->assertStringNotContainsString('>Amount<', $english);
        $this->assertStringNotContainsString('<td>Total</td>', $english);

        foreach (['es' => ['Cantidad', 'totales'], 'ru' => ['Количество', 'Общий'], 'tr' => ['Miktar'], 'fr' => ['Totale']] as $locale => $wrong) {
            $payment = $this->payment(['language' => $locale, 'email' => $locale.'@example.test', 'username' => 'buyer_'.$locale]);
            app()->setLocale($locale);
            try {
                $html = $this->html($payment);
            } finally {
                app()->setLocale('en');
            }
            foreach ($wrong as $word) {
                $this->assertStringNotContainsString($word, $html, $locale.': '.$word);
            }
        }
    }
}
