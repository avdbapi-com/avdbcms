<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\Support\MemberMail;
use App\Support\BillingFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * R2A-038 in the member e-mails (proposal of lane CP for MemberMail.php): the receipt, the trial and the welcome mails
 * write dates as the member's pages and the attached invoice PDF do (BillingFormat::date: "5 Kas 2026", not
 * "Kas 05, 2026"), and a sentence that ends with a date that ends with its own full stop keeps one ("5 нояб. 2026 г.").
 */
class MemberMailDatesTest extends TestCase
{
    use RefreshDatabase;

    private function receipt(string $language): array
    {
        config(['site.date_format' => 'M d, Y', 'app.name' => 'Clip Town']);
        $member = User::factory()->create(['language' => $language]);
        $plan = Plan::factory()->create(['duration' => 1, 'price' => 500, 'stripe_id' => 'price_mail_'.$member->id]);
        $subscription = Subscription::factory()->for($member)->for($plan)->create(['stripe_status' => 'active', 'next_payment' => Carbon::create(2026, 11, 5, 10)]);
        $transaction = Transaction::query()->create([
            'stripe_id' => 'in_mail_'.$member->id, 'amount' => 500, 'tax' => 0, 'fee' => 0, 'date' => Carbon::create(2026, 10, 5, 10),
            'user_id' => $member->id, 'subscription_id' => $subscription->id, 'name' => 'A', 'address' => 'B', 'city' => 'C', 'postal_code' => 'D', 'country' => 'DE',
        ]);
        app()->setLocale($language);

        return MemberMail::invoice($member, $transaction->fresh(['subscription']));
    }

    private function text(array $mail): string
    {
        return (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', implode(' ', $mail['paragraphs'] ?? []));
    }

    public function test_the_receipt_writes_its_dates_as_the_invoice_pdf_does(): void
    {
        $turkish = $this->text($this->receipt('tr'));
        $this->assertStringContainsString('5 Eki 2026', $turkish);
        $this->assertStringContainsString('5 Kas 2026', $turkish);
        $this->assertStringNotContainsString('Eki 05, 2026', $turkish);

        $english = $this->text($this->receipt('en'));
        $this->assertStringContainsString('Oct 5, 2026', $english);
        $this->assertSame(BillingFormat::date(Carbon::create(2026, 11, 5, 10)), MemberMail::date(Carbon::create(2026, 11, 5, 10)));
    }

    public function test_a_sentence_that_ends_with_a_date_keeps_one_full_stop(): void
    {
        $russian = $this->text($this->receipt('ru'));
        $this->assertStringContainsString('5 нояб. 2026 г.', $russian);
        $this->assertStringNotContainsString('г..', $russian);
    }
}
