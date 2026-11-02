<?php

namespace Tests\Feature\Member;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Round 1 of 1.2.3, the invoices:
 * - R1-027: a stored PDF is sent only when it is this payment's PDF of the current template. A PDF of 1.2.2's template
 *   (a French company, SIRET, a fixed "TVA 20%") or another buyer's PDF left on the disk after a database restore that
 *   reused the number is made again first; when it can not be made right now the member reads "This invoice is being
 *   prepared" and the stored file is never sent.
 * - R1-028: a new PDF embeds only the letters it uses (the whole DejaVu Sans made each one about 860 KB).
 * - R1-117: one invoice number everywhere ("INV-2026-0001"): the Invoices list said "Invoice No 1".
 */
class InvoiceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** A PDF as 1.2.2 made it: another company's details and a fixed tax line, no fingerprint. */
    private const OLD_TEMPLATE_PDF = "%PDF-1.3\n1 0 obj << /Title (Invoice) >> endobj\n% Avenue Saint-Michel SIRET 84011597600036 TVA (20%)\n%%EOF\n";

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
        Storage::fake(config('filesystems.default'));
        $this->withoutVite();
    }

    private function payment(string $buyer = 'Ana Buyer', string $paymentId = 'in_test_a', ?User $member = null, ?int $id = null): Transaction
    {
        $member ??= User::factory()->create();
        $plan = Plan::factory()->create(['duration' => 1, 'price' => 500]);
        $subscription = Subscription::factory()->for($member)->for($plan)->create();

        $transaction = new Transaction([
            'stripe_id' => $paymentId,
            'amount' => 500,
            'tax' => 0,
            'fee' => 0,
            'date' => Carbon::create(2026, 9, 24, 10, 0, 0),
            'user_id' => $member->id,
            'subscription_id' => $subscription->id,
            'name' => $buyer,
            'address' => '1 Long Road',
            'city' => 'Lisbon',
            'postal_code' => '1000-001',
            'country' => 'PT',
            'vat_id' => null,
        ]);

        if ($id !== null) {
            $transaction->forceFill(['id' => $id]);
        }

        $transaction->save();

        return $transaction->fresh();
    }

    private function fingerprintIn(string $pdf, Transaction $transaction): bool
    {
        $fingerprint = InvoiceService::fingerprint($transaction);

        return str_contains($pdf, (string) mb_convert_encoding($fingerprint, 'UTF-16BE', 'UTF-8')) || str_contains($pdf, $fingerprint);
    }

    public function test_a_new_invoice_embeds_only_the_letters_it_uses_and_carries_its_payment(): void
    {
        $transaction = $this->payment();

        app(InvoiceService::class)->generate($transaction);

        $pdf = Storage::get($transaction->invoice_path);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertLessThan(150 * 1024, strlen($pdf), 'R1-028: font subsetting (the whole font made it about 860 KB)');
        $this->assertTrue($this->fingerprintIn($pdf, $transaction), 'the PDF carries its payment and template');
        $this->assertTrue(InvoiceService::isCurrent($transaction));
        $this->assertMatchesRegularExpression('#^avd-invoice/'.InvoiceService::TEMPLATE_VERSION.'/[0-9a-f]{32}$#', InvoiceService::fingerprint($transaction));
    }

    public function test_a_pdf_of_the_old_template_is_made_again_before_it_is_sent(): void
    {
        $transaction = $this->payment();
        Storage::put($transaction->invoice_path, self::OLD_TEMPLATE_PDF);
        $this->assertFalse(InvoiceService::isCurrent($transaction), 'a PDF without the fingerprint is not this template');

        $response = $this->actingAs($transaction->user)->get(route('user.invoices.show', $transaction))->assertOk();
        $sent = $response->streamedContent();

        $this->assertStringStartsWith('%PDF', $sent);
        $this->assertStringNotContainsString('SIRET', $sent, 'the old template is never sent');
        $this->assertTrue($this->fingerprintIn($sent, $transaction), 'the PDF made now');
        $this->assertTrue(InvoiceService::isCurrent($transaction), 'the stored file is the new one');
        $this->assertStringContainsString(InvoiceService::number($transaction).'.pdf', (string) $response->headers->get('Content-Disposition'), 'R1-117: saved under the invoice number');
    }

    public function test_another_buyers_pdf_left_on_the_disk_is_never_sent(): void
    {
        // A site restored from a backup: the payment numbered 41 was another buyer's when its PDF was made...
        $first = $this->payment('Other Buyer', 'in_other_payment', null, 41);
        app(InvoiceService::class)->generate($first);
        $path = $first->invoice_path;
        $this->assertTrue(InvoiceService::isCurrent($first));
        Transaction::query()->whereKey(41)->delete();

        // ... and after the restore number 41 (same day) is this member's payment
        $member = User::factory()->create();
        $mine = $this->payment('Ana Buyer', 'in_my_payment', $member, 41);
        $this->assertSame($path, $mine->invoice_path, 'the same number and day find the same file');
        $this->assertFalse(InvoiceService::isCurrent($mine), "the other buyer's PDF is not this payment's");

        $sent = $this->actingAs($member)->get(route('user.invoices.show', $mine))->assertOk()->streamedContent();

        $this->assertTrue($this->fingerprintIn($sent, $mine), 'this payment\'s PDF');
        $this->assertFalse($this->fingerprintIn($sent, $first), "never the other buyer's");
    }

    public function test_a_pdf_that_can_not_be_made_again_is_refused_with_a_message_and_never_sent(): void
    {
        $transaction = $this->payment();
        Storage::put($transaction->invoice_path, self::OLD_TEMPLATE_PDF);

        $this->mock(InvoiceService::class, function ($mock): void {
            $mock->shouldReceive('generate')->andThrow(new RuntimeException('The PDF writer is not available.'));
        });

        $this->actingAs($transaction->user)->get(route('user.invoices.show', $transaction))
            ->assertRedirect(route('user.invoices.index'))
            ->assertSessionHas('info', __('This invoice is being prepared, try again in a minute.'));

        $this->assertSame(self::OLD_TEMPLATE_PDF, Storage::get($transaction->invoice_path), 'nothing is thrown away either');
    }

    public function test_ensure_pdf_makes_a_pdf_of_another_template_or_payment_again(): void
    {
        $transaction = $this->payment();
        Storage::put($transaction->invoice_path, self::OLD_TEMPLATE_PDF);

        $this->assertTrue(app(InvoiceService::class)->ensurePdf($transaction));
        $this->assertTrue(InvoiceService::isCurrent($transaction));
        $this->assertStringNotContainsString('SIRET', Storage::get($transaction->invoice_path));

        // a PDF that already is the current one is kept as it is
        $made = Storage::get($transaction->invoice_path);
        $this->assertTrue(app(InvoiceService::class)->ensurePdf($transaction));
        $this->assertSame($made, Storage::get($transaction->invoice_path));
    }

    /**
     * The receipt e-mail carries this payment's PDF: it read the file by its full path as a path on the disk, so every
     * receipt carried an empty PDF; without a PDF it carries no attachment at all (never an empty file).
     */
    public function test_the_receipt_email_carries_the_payments_pdf_under_the_invoice_number(): void
    {
        $transaction = $this->payment();
        Storage::put($transaction->invoice_path, self::OLD_TEMPLATE_PDF);

        $mail = (new \App\Notifications\Premium\Invoice($transaction))->toMail($transaction->user);

        $this->assertCount(1, $mail->rawAttachments);
        $attachment = $mail->rawAttachments[0];
        $this->assertSame(InvoiceService::number($transaction).'.pdf', $attachment['name']);
        $this->assertStringStartsWith('%PDF', (string) $attachment['data']);
        $this->assertGreaterThan(1000, strlen((string) $attachment['data']), 'not an empty file');
        $this->assertTrue($this->fingerprintIn((string) $attachment['data'], $transaction), 'the current PDF of this payment');

        // a PDF that can not be made: no attachment rather than an empty one
        Storage::put($transaction->invoice_path, self::OLD_TEMPLATE_PDF);
        $this->mock(InvoiceService::class, function ($mock): void {
            $mock->shouldReceive('ensurePdf')->andReturn(false);
        });
        $mail = (new \App\Notifications\Premium\Invoice($transaction))->toMail($transaction->user);
        $this->assertSame([], $mail->rawAttachments);
        $this->assertSame([], $mail->attachments);
    }

    /**
     * The fingerprint reads the payment's date as the database keeps it: a change of the site's time zone (Settings >
     * Site > Time zone) changes the moment a stored date stands for, not the invoice, so the stored PDF stays current
     * and is never made again for that (review of R1-027: every stored invoice was made again with today's details).
     */
    public function test_a_change_of_the_sites_time_zone_keeps_the_stored_invoice(): void
    {
        $zone = date_default_timezone_get();
        $setZone = function (string $tz): void {
            config(['app.timezone' => $tz]);
            date_default_timezone_set($tz);
        };

        try {
            $setZone('Europe/Paris');
            $transaction = $this->payment();
            app(InvoiceService::class)->generate($transaction);
            $fingerprint = InvoiceService::fingerprint($transaction);
            // a mark at the end of the stored file: a PDF made again would not carry it
            Storage::put($transaction->invoice_path, Storage::get($transaction->invoice_path)."%kept\n");

            $setZone('America/New_York');
            $again = Transaction::query()->findOrFail($transaction->id);

            $this->assertSame($transaction->invoice_path, $again->invoice_path, 'the same file');
            $this->assertSame($fingerprint, InvoiceService::fingerprint($again), 'the same payment in every time zone');
            $this->assertTrue(InvoiceService::isCurrent($again));
            $this->assertTrue(app(InvoiceService::class)->ensurePdf($again));
            $this->assertStringEndsWith("%kept\n", Storage::get($again->invoice_path), 'the stored invoice is not made again');
        } finally {
            $setZone($zone);
        }
    }

    /**
     * Turkish capitals (review of R1-028): dompdf capitalises the labels and the column heads with Unicode's default
     * rule, so a Turkish invoice printed "FATURA EDILEN", "KIMDEN", "FIYAT" (an I without its dot). They come
     * capitalised with the Turkish rule now; other languages are left to the style, as before.
     */
    public function test_a_turkish_invoice_prints_its_capitals_with_the_dotted_i(): void
    {
        $this->assertSame("F\u{0130}YAT", InvoiceService::capitalLabel('Fiyat', 'tr'));
        $this->assertSame('KIRMIZI', InvoiceService::capitalLabel("k\u{0131}rm\u{0131}z\u{0131}", 'tr'), 'the dotless ı has the capital I');
        $this->assertSame('Price', InvoiceService::capitalLabel('Price', 'en'), 'other languages: the style capitalises');
        $this->assertSame('Preis', InvoiceService::capitalLabel('Preis', 'de'));

        $member = User::factory()->create(['language' => 'tr']);
        $transaction = $this->payment("Ay\u{015F}e Al\u{0131}c\u{0131}", 'in_test_tr', $member);
        $this->assertSame('tr', InvoiceService::locale($transaction));

        app()->setLocale('tr');

        try {
            $html = view('users.invoice', ['transaction' => $transaction, 'invoice' => InvoiceService::details($transaction, 'tr')])->render();
        } finally {
            app()->setLocale('en');
        }

        foreach (["FATURA ED\u{0130}LEN", "K\u{0130}MDEN", "A\u{00C7}IKLAMA", "D\u{00D6}NEM", "F\u{0130}YAT"] as $label) {
            $this->assertStringContainsString('>'.$label.'</', $html, $label);
        }
        foreach (['FATURA EDILEN', 'KIMDEN', 'FIYAT'] as $wrong) {
            $this->assertStringNotContainsString($wrong, $html);
        }
        $this->assertStringContainsString("Ay\u{015F}e Al\u{0131}c\u{0131}", $html, 'names stay as typed');
    }

    public function test_the_invoices_list_prints_the_same_number_as_the_pdf(): void
    {
        $transaction = $this->payment();
        $number = InvoiceService::number($transaction);
        $this->assertSame(sprintf('INV-2026-%04d', $transaction->id), $number);

        $html = $this->actingAs($transaction->user)->get(route('user.invoices.index'))->assertOk()->getContent();

        // (with the abbreviation's point since 1.2.3 round 3, R3-058)
        $this->assertStringContainsString(e(__('Invoice No. :number', ['number' => $number])), $html);
        $this->assertStringNotContainsString(e(__('Invoice No. :number', ['number' => $transaction->id])).'<', $html, 'R1-117: never the bare id');
    }
}
