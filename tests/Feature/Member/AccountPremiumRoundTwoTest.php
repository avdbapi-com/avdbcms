<?php

namespace Tests\Feature\Member;

use App\Http\Controllers\Auth\PhoneVerification;
use App\Http\Middleware\ViewOnlyDemo;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\Account\VerifyPhone;
use App\Services\InvoiceService;
use App\Support\BillingFormat;
use App\Support\PremiumPageSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Account settings, Premium and invoices after round 2a of 1.2.3:
 * - R2A-038: dates and amounts read the same, in the member's language, on the Invoices list, in Account settings >
 *   Premium, on the Premium page and in the invoice PDF (App\Support\BillingFormat: the studio's dates, the site's
 *   currency the language's way); a date that ends with its own full stop ends a sentence with one; the Invoices list
 *   writes the site's currency (it wrote euros on every site);
 * - R2A-015: the view-only demo's refused payment comes back to /premium with "Not saved" and why;
 * - R2A-007, R2A-044, R1-115: a refused field is put on screen between the bars, a sheet's field gets the focus once its
 *   sheet is shown (the script's contract; the browser checks are the lane's focus matrix);
 * - R2A-026, R2A-039: the stacked comparison keeps each value whole; the account sheets keep their buttons on one line;
 * - R2A-040: the phone code and e-mail limits count in Arabic; R2A-041: German member pages use one form of address.
 */
class AccountPremiumRoundTwoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config([
            'app.name' => 'Clip Town',
            'site.default_locale' => 'en',
            'app.locale' => 'en',
            'cashier.currency' => 'eur',
            'site.date_format' => 'M d, Y',
            'plans.trial_period.period' => 0,
            'site.premium_enabled' => true,
            'site.stripe_enabled' => true,
            'site.paypal_enabled' => false,
            'cashier.key' => 'pk_test_r2cp',
            'cashier.secret' => 'sk_test_r2cp',
            'cashier.webhook.secret' => 'whsec_test_r2cp',
            'site.invoice_seller_name' => 'Nordlicht Media GmbH',
            'site.invoice_seller_address' => "Friedrichstraße 123\n10117 Berlin",
            'site.invoice_seller_tax_id' => 'DE999999999',
            'site.invoice_tax_label' => 'VAT',
            'site.invoice_tax_rate' => '19',
        ]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        unset($_SERVER['DETECTED_LOCALE']);

        parent::tearDown();
    }

    /**
     * A page as /<language>/... shows it: public/index.php takes the language out of the address and hands it to the
     * Language middleware in DETECTED_LOCALE (as AccountSettingsTest does); English is the address without a language.
     */
    private function page(?User $user, string $language, string $url): string
    {
        if ($language === 'en') {
            unset($_SERVER['DETECTED_LOCALE']);
        } else {
            $_SERVER['DETECTED_LOCALE'] = $language;
        }

        try {
            $test = $user ? $this->actingAs($user) : $this;

            return (string) $test->get($url)->assertOk()->getContent();
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
        }
    }

    /** Spaces of every kind as one, no direction marks: ICU writes no-break spaces ("1 234,50 €") and Arabic money with a mark. */
    private function norm(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', (string) preg_replace('/[\x{200E}\x{200F}\x{2066}-\x{2069}]/u', '', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }

    /** @return array{0: User, 1: Transaction} */
    private function payment(string $language, int $amount = 123450, int $tax = 19710): array
    {
        $member = User::factory()->create(['language' => $language]);
        $plan = Plan::factory()->create(['name' => 'monthly', 'duration' => 1, 'price' => 500, 'stripe_id' => 'price_month_'.$member->id]);
        $subscription = Subscription::factory()->for($member)->for($plan)->create([
            'stripe_status' => 'active', 'ends_at' => null, 'trial_ends_at' => null, 'next_payment' => Carbon::create(2026, 11, 5, 10, 15),
            'card_last4' => '4242', 'payment_provider' => 'stripe',
        ]);
        $transaction = Transaction::query()->create([
            'stripe_id' => 'in_r2cp_'.$member->id,
            'amount' => $amount,
            'tax' => $tax,
            'fee' => 0,
            'date' => Carbon::create(2026, 10, 5, 10, 15),
            'user_id' => $member->id,
            'subscription_id' => $subscription->id,
            'name' => 'Ana Buyer',
            'address' => '1 Long Road',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'DE',
        ]);

        return [$member->fresh(), $transaction->fresh(['subscription.plan', 'user'])];
    }

    /** The date and the amount of the first invoice row of a page. */
    private function row(string $html): array
    {
        $this->assertSame(1, preg_match('#<li class="mk-item mk-item--invoice">(.*?)</li>#s', $html, $match), 'an invoice row');
        $this->assertSame(1, preg_match('#<div class="mk-row__meta">\s*<span>(.*?)</span>#s', $match[1], $date));
        $this->assertSame(1, preg_match('#<span class="mk-row__num mk-amount">(.*?)</span>#s', $match[1], $amount));
        $this->assertSame(1, preg_match('#<span class="mk-item__title">(.*?)</span>#s', $match[1], $title));

        return ['date' => $this->norm(strip_tags($date[1])), 'amount' => $this->norm(strip_tags($amount[1])), 'title' => $this->norm(strip_tags($title[1]))];
    }

    /**
     * R2A-038: one invoice reads one way. The judge's Turkish case ("26 Eki 2026 … €5,00" on the Invoices list,
     * "Eki 26, 2026 … €5.00" in Account settings and the PDF) and the other written-month orders.
     */
    public function test_one_invoice_reads_the_same_on_the_invoices_list_in_account_settings_and_in_the_pdf(): void
    {
        $expected = [
            'en' => ['Oct 5, 2026', '€1,234.50', 'October 2026'],
            'tr' => ['5 Eki 2026', '€1.234,50', 'Ekim 2026'],
            'fr' => ['5 oct. 2026', '1 234,50 €', 'octobre 2026'],
            'de' => ['5. Okt. 2026', '1.234,50 €', 'Oktober 2026'],
            'ru' => ['5 окт. 2026 г.', '1 234,50 €', 'октябрь 2026 г.'],
            'ar' => ['5 أكتوبر 2026', '1,234.50 €', 'أكتوبر 2026'],
            'ja' => ['2026年10月5日', '€1,234.50', '2026年10月'],
        ];

        foreach ($expected as $language => [$date, $amount, $month]) {
            [$member, $transaction] = $this->payment($language);

            $list = $this->row($this->page($member, $language, route('user.invoices.index')));
            $settings = $this->row($this->page($member, $language, route('user.edit', ['section' => 'premium'])));

            $this->assertSame($date, $list['date'], "$language: the Invoices list's date");
            $this->assertSame($amount, $list['amount'], "$language: the Invoices list's amount");
            $this->assertSame($list, $settings, "$language: Account settings > Premium prints the invoice as the Invoices list does");
            $this->assertStringEndsWith($month, $list['title'], "$language: the month in the language's own order");

            // the PDF (in the member's language where its font can draw it, else English: the Arabic and Japanese PDF)
            $pdfLanguage = InvoiceService::locale($transaction);
            $details = InvoiceService::details($transaction, $pdfLanguage);
            if ($pdfLanguage === $language) {
                $this->assertSame($date, $this->norm($details['date']), "$language: the PDF's date");
                $this->assertSame($date, $this->norm($details['paid_on']), "$language: the PDF's paid-on date");
                $this->assertSame($amount, $this->norm($details['total']), "$language: the PDF's total");
            } else {
                $this->assertSame('en', $pdfLanguage);
                $this->assertSame(['Oct 5, 2026', '€1,234.50'], [$this->norm($details['date']), $this->norm($details['total'])]);
            }
        }
    }

    /** R2A-038: the PDF's tax line and period in the member's language; the stored PDFs of the template before are made again. */
    public function test_the_pdf_writes_its_tax_rate_period_and_paid_on_sentence_the_members_way(): void
    {
        $this->assertSame(3, InvoiceService::TEMPLATE_VERSION, 'version 2 PDFs printed "Eki 5, 2026" / "€4.20": made again once');

        [, $turkish] = $this->payment('tr');
        $details = InvoiceService::details($turkish, 'tr');
        $this->assertSame('5 Eki 2026 – 4 Kas 2026', $this->norm($details['period']));
        $this->assertSame('VAT %19', $this->norm((string) $details['tax_label']), 'Turkish writes the percent sign first');
        $this->assertSame(['€1.037,40', '€197,10'], [$this->norm($details['subtotal']), $this->norm((string) $details['tax'])]);

        [, $russian] = $this->payment('ru');
        $html = view('users.invoice', ['transaction' => $russian, 'invoice' => InvoiceService::details($russian, 'ru')])->render();
        $text = $this->norm(strip_tags($html));
        $this->assertStringContainsString('VAT 19 %', $text);
        $this->assertStringContainsString('5 окт. 2026 г.', $text);
        $this->assertStringNotContainsString('г..', $text, 'the date\'s own full stop ends "Paid on :date."');
    }

    /** R2A-038: the Invoices list writes the site's currency (it wrote "€5.00" on every site, ¥500 as "€5.00"). */
    public function test_the_invoices_list_writes_the_sites_currency(): void
    {
        foreach (['usd' => ['$5.00', 500], 'jpy' => ['¥500', 500]] as $currency => [$expected, $cents]) {
            config(['cashier.currency' => $currency]);
            [$member] = $this->payment('en', $cents, 0);

            $list = $this->row((string) $this->actingAs($member)->get(route('user.invoices.index'))->assertOk()->getContent());

            $this->assertSame($expected, str_replace('￥', '¥', $list['amount']), $currency);
        }
    }

    /** R2A-038: a written date that ends with a full stop ends a sentence with one ("г." in Russian, R2A-023's rule). */
    public function test_a_date_that_ends_with_its_own_full_stop_ends_the_sentence_with_one(): void
    {
        $this->assertSame('Due 5 Nov.', BillingFormat::fill('Due :date.', ['date' => '5 Nov.']));
        $this->assertSame('From 5 Nov., then', BillingFormat::fill('From :date, then', ['date' => '5 Nov.']));
        $this->assertSame('Nov 5, 2026 Nov 5, 2026.', BillingFormat::fill(':Date :date.', ['date' => 'Nov 5, 2026']), 'Laravel\'s own replacements');
        $this->assertSame('Your billing starts on 26 нояб. 2026 г.', PremiumPageSettings::format('Your billing starts on :date.', ['date' => '26 нояб. 2026 г.']));
        $this->assertSame('7 days', PremiumPageSettings::format(':days days', ['days' => 7]));

        app()->setLocale('ru');
        $this->assertSame('Оплачено 5 окт. 2026 г.', $this->norm(BillingFormat::fill(__('Paid on :date.'), ['date' => BillingFormat::date(Carbon::create(2026, 10, 5, 10))])));

        // Account settings > Premium of a Russian member whose Premium ended
        [$member] = $this->payment('ru');
        $member->premium_subscription?->forceFill(['stripe_status' => 'canceled', 'ends_at' => Carbon::create(2026, 10, 5, 10)])->save();
        $html = $this->norm(strip_tags($this->page($member->fresh(), 'ru', route('user.edit', ['section' => 'premium']))));
        $this->assertStringContainsString('5 окт. 2026 г.', $html);
        $this->assertStringNotContainsString('г..', $html);
    }

    /** R2A-038: the Premium page's prices and billing dates in the reader's language. */
    public function test_the_premium_page_writes_prices_and_billing_dates_in_the_readers_language(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 28, 12));
        config(['plans.trial_period.period' => 30, 'plans.trial_period.email_reminder' => 7]);
        Plan::factory()->create(['name' => 'monthly', 'duration' => 1, 'price' => 500, 'stripe_id' => 'price_month']);
        Plan::factory()->create(['name' => 'yearly', 'duration' => 12, 'price' => 5000, 'stripe_id' => 'price_year']);

        // English first: a page opened in French keeps French in the session, and the address without a language then
        // goes to the French one
        $english = $this->norm(strip_tags($this->page(null, 'en', route('pages.premium'))));
        $this->assertStringContainsString('€4.17', $english);
        $this->assertStringContainsString('Nov 27, 2026', $english);

        $french = $this->norm(strip_tags($this->page(null, 'fr', route('pages.premium'))));
        $this->assertStringContainsString('5 €', $french, 'the monthly price');
        $this->assertStringContainsString('4,17 €', $french, 'what the yearly plan costs a month');
        $this->assertStringContainsString('27 nov. 2026', $french, 'the trial ends: the French order');
        $this->assertStringNotContainsString('nov. 27, 2026', $french);
        $this->assertStringNotContainsString('€4.17', $french);
    }

    /** R2A-015: the view-only demo's "Start with card" / PayPal comes back to /premium with "Not saved" and why. */
    public function test_the_view_only_demo_reads_why_a_payment_did_not_start(): void
    {
        config(['site.paypal_enabled' => true, 'services.paypal.client_id' => 'id', 'services.paypal.client_secret' => 'secret', 'services.paypal.webhook_id' => 'hook']);
        $plan = Plan::factory()->create(['name' => 'monthly', 'duration' => 1, 'price' => 500, 'stripe_id' => 'price_month', 'paypal_plan_id' => 'P-R2CP']);
        $demo = User::factory()->create();
        $demo->forceFill([ViewOnlyDemo::COLUMN => true])->save();
        $message = __('This is a view-only demo account: you can look at everything, but changes are switched off.');

        foreach ([route('premium.subscribe', $plan), route('premium.subscribe.paypal', $plan)] as $start) {
            $this->actingAs($demo->fresh())->from(route('pages.premium'))->get($start)
                ->assertStatus(303)
                ->assertRedirect(route('pages.premium'))
                ->assertSessionHasErrors('view_only_demo');

            $html = (string) $this->actingAs($demo->fresh())->get(route('pages.premium'))->assertOk()->getContent();

            $this->assertStringContainsString('data-view-only-refusal', $html, $start);
            // shown once (the "This is a demo" window also carries the sentence in its data-said attribute, for its
            // script, and hides this note once it opens)
            $this->assertSame(1, substr_count(strip_tags($html), e($message)), 'said once');
            $this->assertStringContainsString(e(__('Not saved')), $html);
        }

        // the page's own one-time messages are still printed once (the refusal part prints no flash message)
        $html = (string) $this->actingAs(User::factory()->create())->withSession(['error' => 'One message'])->get(route('pages.premium'))->getContent();
        $this->assertSame(1, substr_count($html, 'One message'));
        $this->assertStringNotContainsString('data-view-only-refusal', $html);
    }

    /**
     * R2A-007, R2A-044, R1-115: after a refused save the first field with an error gets the focus without a scroll and is
     * then put on screen between the bars (Safari's own scroll left it under the tab bar or the save bar); a field of a
     * sheet gets the focus once its sheet is shown (Bootstrap focuses the sheet itself when it opens).
     */
    public function test_a_refused_field_is_put_on_screen_and_a_sheet_field_gets_the_focus_once_its_sheet_is_shown(): void
    {
        $member = User::factory()->create();
        $errors = fn (array $messages): ViewErrorBag => (new ViewErrorBag())->put('default', new MessageBag($messages));

        $sheet = (string) $this->actingAs($member)->withSession([
            'errors' => $errors(['current_password' => ['That password is wrong. Try again.']]),
            '_old_input' => ['_sheet' => 'delete'],
        ])->get(route('user.edit', ['section' => 'delete']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="delete_password"[^>]*aria-invalid="true"/', $sheet);
        $this->assertStringContainsString("sheet.addEventListener('shown.bs.modal', show, {once: true})", $sheet);
        $this->assertStringContainsString('field.focus({preventScroll: true})', $sheet);
        $this->assertStringNotContainsString('focus({preventScroll: false})', $sheet, 'a plain focus() scrolled only to the edge of the screen in Safari');

        $page = (string) $this->actingAs($member)->withSession([
            'errors' => $errors(['website' => ['Use a web address like example.com or https://example.com/page.']]),
            '_old_input' => ['section' => 'channel', 'website' => 'not a website'],
        ])->get(route('user.edit', ['section' => 'channel']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="website"[^>]*aria-invalid="true"/', $page);
        $this->assertStringContainsString("scrollIntoView({block: 'center', inline: 'nearest'})", $page);
        $this->assertStringContainsString('window.scrollBy(0, delta)', $page, 'then exactly between the bars that stay on screen');
        $this->assertStringContainsString("getComputedStyle(el).position", $page);

        // a page without a refused field carries none of it (4.3: every byte of a page is downloaded)
        $this->flushSession();
        $plain = (string) $this->actingAs($member)->get(route('user.edit', ['section' => 'channel']))->assertOk()->getContent();
        // (the page root's component is in the cached studio script since 1.2.3 round 3, R3-086)
        $this->assertStringContainsString('x-data="avdAccountSettings(false)"', $plain);
        $this->assertStringNotContainsString('window.avdRevealRefused = ', $plain);
    }

    /** R2A-026 and R2A-039: the stacked comparison keeps each value whole; the account sheets keep their buttons on one line. */
    public function test_the_stacked_comparison_and_the_account_sheets_keep_their_words_together(): void
    {
        $scss = (string) file_get_contents(resource_path('sass/member/_account.scss'));

        $this->assertMatchesRegularExpression('/:root \.mk-compare__row \{[^}]*display: flex;[^}]*flex-wrap: wrap;/s', $scss);
        // a cell is as wide as its name and value on one line, but never wider than the row (fit-content): a plan name
        // the owner wrote longer than the row wraps inside its cell ("max-content" made a 46-character name push every
        // cell past the panel, cut at the screen's edge); only a single word longer than the row breaks
        $this->assertMatchesRegularExpression('/:root \.mk-compare__row > span:not\(\.mk-compare__what\) \{[^}]*min-inline-size: fit-content;[^}]*overflow-wrap: anywhere;/s', $scss, 'a value never splits while it fits the row; a longer text wraps inside the row');
        $this->assertDoesNotMatchRegularExpression('/:root \.mk-compare__row > span:not\(\.mk-compare__what\) \{[^}]*min-inline-size: max-content;/s', $scss);
        $this->assertMatchesRegularExpression('/:root \.mk-compare__row > span:not\(\.mk-compare__what\) > \.mk-muted:first-child \{[^}]*display: block;/s', $scss, 'the plan\'s name over its value');
        $this->assertMatchesRegularExpression('/:root \.avd-profile-settings \.mk-dialog \.mk-sheet__foot > \.mk-btn \{[^}]*white-space: nowrap;/s', $scss);
    }

    /**
     * Review of round 2 (RV-CP-03, already in int-C2): the Theme choice of Account settings > Profile (Light / Dark /
     * Device) never cuts its words. The kit's three equal segments cut a word longer than a third with "..." (es/pt
     * "Dispositivo", ru "Устройство", tl "Maliwanag" at every width; 15 of 17 languages at 320px): here a segment is at
     * least as wide as its word and the three wrap when they do not fit in one row.
     */
    public function test_the_theme_choice_of_account_settings_never_cuts_its_words(): void
    {
        $scss = (string) file_get_contents(resource_path('sass/member/_account.scss'));

        $this->assertMatchesRegularExpression('/:root \.avd-profile-settings \.mk-seg--block \{[^}]*display: flex;[^}]*flex-wrap: wrap;/s', $scss);
        $this->assertMatchesRegularExpression('/:root \.avd-profile-settings \.mk-seg--block > \.mk-seg__item \{[^}]*flex: 1 1 0;[^}]*min-inline-size: fit-content;[^}]*padding-inline: 0;/s', $scss, 'a segment is at least as wide as its word');
        $this->assertMatchesRegularExpression('/:root \.avd-profile-settings \.mk-seg--block \.mk-seg__label \{[^}]*padding-inline: \.25rem;/s', $scss);

        // the rule reaches the control: Account settings' page wrapper holds the Theme choice in the kit's block form
        $member = User::factory()->create();
        $html = (string) $this->actingAs($member)->get(route('user.edit', ['section' => 'profile']))->assertOk()->getContent();
        $page = strpos($html, 'avd-profile-settings');
        $control = strpos($html, '<div class="mk-seg mk-seg--block" role="radiogroup" aria-labelledby="theme-label">');
        $this->assertNotFalse($page);
        $this->assertNotFalse($control);
        $this->assertGreaterThan($page, $control);
        $this->assertSame(3, substr_count($html, 'name="avd_theme_mode"'));
    }

    /** R2A-040: the phone code and e-mail limits count in Arabic (10 minutes take another word than 1, 2 or 11 minutes). */
    public function test_the_phone_code_and_e_mail_limits_count_in_arabic(): void
    {
        app()->setLocale('en');
        $this->assertSame('Code sent. It works for 10 minutes.', PhoneVerification::codeSentMessage(), 'English unchanged');

        app()->setLocale('ar');
        $this->assertStringContainsString('10 دقائق', PhoneVerification::codeSentMessage());
        $member = User::factory()->create(['phone' => '+33612345678']);
        $member->forceFill(['phone_confirmation_code' => '12345'])->save();
        $sms = (new VerifyPhone())->toSms($member->fresh())->getLines();
        $this->assertStringContainsString('10 دقائق', $sms);
        $this->assertStringContainsString('12345', $sms);

        $email = '{1} Your e-mail was not changed: you changed it too often. Try again in :count minute.|[2,*] Your e-mail was not changed: you changed it too often. Try again in :count minutes.';
        $codes = '{1} No code was sent: you asked for too many codes. Try again in :count minute.|[2,*] No code was sent: you asked for too many codes. Try again in :count minutes.';
        foreach ([$email, $codes] as $line) {
            $this->assertStringContainsString('دقيقة واحدة', trans_choice($line, 1, ['count' => 1]));
            $this->assertStringContainsString('دقيقتين', trans_choice($line, 2, ['count' => 2]));
            $this->assertStringContainsString('10 دقائق', trans_choice($line, 10, ['count' => 10]));
            $this->assertStringContainsString('55 دقيقة', trans_choice($line, 55, ['count' => 55]), 'the judge\'s "55 دقائق"');
        }
        $tries = '{1} That code is wrong. Check the message and try again. :count try left.|[2,*] That code is wrong. Check the message and try again. :count tries left.';
        $this->assertStringContainsString('محاولتان', trans_choice($tries, 2, ['count' => 2]));
        $this->assertStringContainsString('3 محاولات', trans_choice($tries, 3, ['count' => 3]));
    }

    /**
     * R2A-041: German member pages address the reader one way ("Sie"), and the trial reminder keeps its number in place.
     * Second pass: also no "du" imperative without a pronoun ("Genieße Lieblingsinhalte ...", "Spare 2 Monate",
     * "Versuche es noch einmal", "Gib ... ein"): a sentence that starts with a verb whose "Sie" form the German texts
     * themselves use ("Genießen Sie", "Versuchen Sie", "Fordern Sie" for "Fordere").
     */
    public function test_german_member_pages_use_one_form_of_address(): void
    {
        $german = json_decode((string) file_get_contents(lang_path('de.json')), true);
        $du = '/\b(du|dich|dir|dein|deine|deinem|deinen|deiner|deines|Du|Dich|Dir|Dein|Deine|Deinem|Deinen|Deiner|Deines)\b/u';
        $sources = [
            'resources/views/pages/premium.blade.php', 'resources/views/modals/premium.blade.php', 'app/Http/Controllers/PremiumController.php',
            'resources/views/users/profile/edit.blade.php', 'resources/views/users/profile/sections/profile.blade.php',
            'resources/views/users/profile/sections/premium.blade.php', 'resources/views/users/profile/sections/security.blade.php',
            'resources/views/users/profile/sections/delete.blade.php', 'resources/views/users/profile/sections/channel.blade.php',
            'resources/views/users/invoices/index.blade.php', 'resources/views/users/invoice.blade.php',
            'app/Http/Controllers/User/ProfileController.php', 'app/Http/Controllers/Auth/PhoneVerification.php', 'app/Models/Traits/MustVerifyPhone.php',
            // second pass: the refusals and help of Account settings, its picture dialog, the contact page, every page's layout
            'app/Http/Requests/UpdateUserRequest.php', 'app/Http/Requests/Profile/CodeRequest.php', 'app/Http/Middleware/RequirePassword.php',
            'resources/views/users/partials/crop.blade.php', 'resources/views/contact/show.blade.php', 'app/Http/Requests/Contact/ContactRequest.php',
            'resources/views/components/layout.blade.php',
        ];
        $keys = array_values(PremiumPageSettings::translatableDefaults());
        foreach ($sources as $source) {
            preg_match_all("/(?:__|trans_choice)\\(\\s*'((?:\\\\.|[^'\\\\])*)'/", (string) file_get_contents(base_path($source)), $matches);
            $keys = array_merge($keys, array_map(fn ($key) => str_replace("\\'", "'", $key), $matches[1]));
        }
        $keys = array_unique($keys);
        $this->assertGreaterThan(150, count($keys));

        $mixed = array_values(array_filter($keys, fn ($key) => is_string($german[$key] ?? null) && preg_match($du, $german[$key]) === 1));
        $this->assertSame([], $mixed, 'German member pages say "Sie" everywhere');

        // a "du" imperative without a pronoun: the first word of a sentence (after "Bitte": the next one), not followed by
        // "Sie", is a verb whose "Sie" form the German texts use ("Bitte versuche es" / "Bitte versuchen Sie es"). Not an
        // address of the reader: "Gehe zu" (a fixed menu heading, as in every German program), the reader's own words
        // to the site ("Überrasch mich" for "Surprise me") and the noun "Test" ("Test beenden").
        preg_match_all('/\b(\p{Lu}\p{Ll}+n) Sie\b/u', implode("\n", array_filter($german, 'is_string')), $found);
        $sieForms = array_flip($found[1]);
        $imperative = function (string $value) use ($sieForms): bool {
            $value = (string) preg_replace('/[\{\[][^\}\]]*[\}\]]\s*/u', '', $value);
            foreach (preg_split('/(?<=[.!?:])\s+|\|/u', $value) ?: [] as $sentence) {
                $sentence = trim($sentence);
                if (preg_match('/^Bitte\s+(\p{Ll})(\p{Ll}*)(?:\s+(\S+))?/u', $sentence, $m) === 1) {
                    // "Bitte versuche es ..." / "Bitte versuchen Sie es ...": the verb follows "Bitte"
                    [$word, $next] = [mb_strtoupper($m[1]).$m[2], $m[3] ?? ''];
                } elseif (preg_match('/^(\p{Lu}\p{Ll}+)(?:\s+(\S+))?/u', $sentence, $m) === 1) {
                    [$word, $next] = [$m[1], $m[2] ?? ''];
                } else {
                    continue;
                }
                if (preg_match('/^Sie\b/u', $next) === 1 || in_array($word, ['Gehe', 'Überrasch', 'Überrasche', 'Test'], true)) {
                    continue;
                }
                $forms = [$word.'n', $word.'en'];
                if (str_ends_with($word, 'ere')) {
                    $forms[] = substr($word, 0, -3).'ern';
                }
                if (str_ends_with($word, 'le')) {
                    $forms[] = substr($word, 0, -2).'eln';
                }
                foreach ($forms as $form) {
                    if (isset($sieForms[$form])) {
                        return true;
                    }
                }
            }

            return false;
        };
        $imperatives = array_values(array_filter($keys, fn ($key) => is_string($german[$key] ?? null) && $imperative($german[$key])));
        $this->assertSame([], $imperatives, 'German member pages never address the reader with a "du" imperative either');

        $reminder = trans_choice('{1} We will email you :count day before your trial ends.|[2,*] We will email you :count days before your trial ends.', 7, ['count' => 7], 'de');
        $this->assertStringContainsString('7 Tage vor', $reminder);
    }

    /**
     * R2A-041, the whole site (review RV-CP-04): German says "Sie" on every page, the admin's too (round 2 changed 169
     * German values for that), so a German text added later with "du" (another change merged into 1.2.3) must not bring
     * the two forms back. Every text a source uses (what the translation tools require: PHP, Blade, the settings
     * catalogue, the Preact texts) is checked. The only "du" left is the owner's: the texts of the upload window and of
     * a video that Upload18 is still processing stay as they are (owner decision of 25 Oct; the upload flow is hands-off).
     */
    public function test_german_says_sie_on_every_page_but_the_upload_texts_the_owner_keeps(): void
    {
        require_once base_path('scripts/i18n/I18n.php');
        $du = '/\b(du|dich|dir|dein|deine|deinem|deinen|deiner|deines)\b/iu';
        $kept = [
            // the upload window
            'Your upload limits',
            'By submitting your videos to :app_name, you acknowledge that you agree to our <a href=":terms_url" class="text-decoration-none">:terms_text</a>',
            // a video Upload18 is still processing (the player block, the draft's status poll, the studio's video form)
            'The processing of your video failed.',
            'Try to re-upload your file, if the problem persist please contact your support..',
            'Your video is processing',
            'Your video is processing ...',
            'Your video is almost ready ...',
            'You will receive a notification once the processing is complete',
            'You will receive a notification once the processing is complete.',
            'Upload18 returned an older video instead of your file, so it was not added. Please upload it again.',
            'The video Upload18 returned does not match your file, so it was not added. Please upload it again.',
            // the upload window's Preact texts
            'js: Your video is safe.',
            'js: Upload18 is still processing your video.',
            'js: Upload18 is still processing it. It will appear in your studio when it is ready, so you can close this page.',
            'js: Your video is uploading ...',
        ];

        [$german] = \Avd\I18n\LangFile::read(lang_path('de.json'));
        [$germanJs] = \Avd\I18n\JsLangFile::read(\Avd\I18n\JsLangFile::path(base_path(), 'de'));
        $required = \Avd\I18n\Requirements::collect(base_path());
        $requiredJs = \Avd\I18n\Requirements::collectJs(base_path());
        $this->assertGreaterThan(8000, count($required));
        $this->assertGreaterThan(150, count($requiredJs));

        $saysDu = [];
        foreach (array_keys($required) as $key) {
            if (is_string($german[$key] ?? null) && preg_match($du, $german[$key]) === 1) {
                $saysDu[] = $key;
            }
        }
        foreach (array_keys($requiredJs) as $key) {
            if (is_string($germanJs[$key] ?? null) && preg_match($du, $germanJs[$key]) === 1) {
                $saysDu[] = 'js: '.$key;
            }
        }

        $this->assertSame([], array_values(array_diff($saysDu, $kept)), 'a German text says "du" where the site says "Sie"');
    }

    /**
     * R2A-038 look-around: "Joined ..." on Account settings writes the month and year in the language's own order
     * ("2026年10月", "octubre de 2026"; Carbon's "F Y" wrote "10月 2026", "octubre 2026"), and the Russian line reads
     * with the month as it is written alone ("С нами с октябрь" needed another case of the month).
     */
    public function test_joined_writes_the_month_in_the_languages_own_order(): void
    {
        $member = User::factory()->create(['created_at' => Carbon::create(2026, 10, 5, 12)]);
        $expected = [
            'en' => 'Joined October 2026',
            'ja' => '2026年10月',
            'es' => 'octubre de 2026',
            'ru' => 'Дата регистрации: октябрь 2026 г.',
        ];

        foreach ($expected as $language => $line) {
            $html = $this->page($member->fresh(), $language, route('user.edit'));

            $this->assertSame(1, preg_match('#<span class="mk-muted mk-avatar-edit__meta">(.*?)</span>#s', $html, $match), "$language: the Joined line");
            $this->assertStringContainsString($line, $this->norm($match[1]), $language);
        }
    }

    /** R2A-038 look-around: the counts of the delete panel use the reader's digit grouping ("1 234", not "1,234"). */
    public function test_the_delete_panel_counts_use_the_readers_digit_grouping(): void
    {
        app()->setLocale('fr');
        $html = view('users.profile.sections.delete', [
            'counts' => ['videos' => 1234, 'playlists' => 2, 'comments' => 12345, 'subscribers' => 3, 'subscriptions' => 0],
            'errors' => new ViewErrorBag(),
        ])->render();
        $text = $this->norm(strip_tags($html));

        $this->assertStringContainsString('1 234', $text);
        $this->assertStringContainsString('12 345', $text);
        $this->assertStringNotContainsString('1,234', $text);
    }
}
