<?php

namespace Tests\Feature\Member;

use App\Http\Middleware\ViewOnlyDemo;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BrevoService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeSmsClient;
use Tests\TestCase;

/**
 * 1.2.3 round 3 (the judge's findings on Account settings, Premium and the invoices, lane ST). Each test fails on RC2 /
 * int-C4 and passes with the fix:
 *  - R3-014 the sheets a page opens by itself give the keyboard focus back when they close: the picture crop sheet to
 *    the picker that opened the file chooser, the Welcome to Premium sheet to the page's heading (never <body>);
 *  - R3-055 the "This is a demo" window gives the focus back to the button that opened a Bootstrap sheet
 *    (data-bs-target), not to the page's main region;
 *  - R3-057 a member who already has Premium reads no "Card or PayPal" note under plans without buttons;
 *  - R3-058 the English invoices list says "Invoice No. INV-…" as the PDF says "No. INV-…";
 *  - R3-059 no test reaches the SMS service: the base TestCase's SMS client records the texts;
 *  - R3X-02 the picture field's own messages print their values ("allowedTypes" was printed as a word);
 *  - R3-086 (page-content budget) a section is printed without its empty lines and without Windows line ends.
 */
class AccountRoundThreeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.default_locale' => 'en', 'app.locale' => 'en', 'cashier.currency' => 'eur', 'plans.trial_period.period' => 0,
            'site.premium_enabled' => true, 'site.stripe_enabled' => true, 'site.paypal_enabled' => true,
            'cashier.key' => '', 'cashier.secret' => '', 'cashier.webhook.secret' => '',
            'services.paypal.client_id' => '', 'services.paypal.client_secret' => '', 'services.paypal.webhook_id' => '']);
        $this->withoutVite();
    }

    // ------------------------------------------------------------------------------------------------- R3-057

    public function test_a_member_who_has_premium_reads_no_payment_methods_note(): void
    {
        $this->sellWithCardAndPayPal();
        [$monthly] = $this->plans();
        $note = e(__('Card or PayPal'));

        // a visitor and a member without Premium: the note sits under the plans' buttons
        $this->assertStringContainsString($note, $this->get(route('pages.premium'))->assertOk()->getContent());
        $free = User::factory()->create();
        $this->assertStringContainsString($note, $this->actingAs($free)->get(route('pages.premium'))->assertOk()->getContent());

        // a member who has Premium (active, trial, ending, paying with PayPal) gets no buttons and no note about them
        foreach ([['stripe_status' => 'active'], ['stripe_status' => 'trialing', 'trial_ends_at' => now()->addDays(5)],
            ['stripe_status' => 'active', 'ends_at' => now()->addDays(9)], ['stripe_status' => 'active', 'payment_provider' => 'paypal']] as $state) {
            $member = User::factory()->create();
            Subscription::factory()->for($member)->for($monthly)->create(array_merge(['ends_at' => null, 'trial_ends_at' => null], $state));
            $html = $this->actingAs($member->fresh())->get(route('pages.premium'))->assertOk()->getContent();

            $this->assertStringNotContainsString(route('premium.subscribe', $monthly), $html, json_encode($state).': no buttons');
            $this->assertStringNotContainsString($note, $html, json_encode($state).': no stray note');
        }
    }

    // ------------------------------------------------------------------------------------------------- R3-058

    public function test_the_english_invoices_list_writes_the_number_with_its_abbreviation_point(): void
    {
        $member = User::factory()->create();
        $transaction = $this->invoice($member);
        $number = InvoiceService::number($transaction);

        $html = $this->actingAs($member)->get(route('user.invoices.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Invoice No. '.$number, $html);
        $this->assertStringNotContainsString('Invoice No '.$number, $html);
        // the PDF's own label, for the same number
        $this->assertSame('No. '.$number, __('No. :number', ['number' => $number]));
    }

    // ------------------------------------------------------------------------------------------------- R3-059

    public function test_no_test_reaches_the_sms_service(): void
    {
        $this->assertInstanceOf(FakeSmsClient::class, app(BrevoService::class), 'the base TestCase binds the fake SMS client');

        // a site that can send texts, and a member who enters a new number: the code goes out through the SMS channel
        Http::fake();
        Cache::flush();
        config(['services.brevo.api_key' => 'xkeysib-test-0123456789']);
        $member = User::factory()->create();

        $this->actingAs($member)->fromRoute('user.edit')->put(route('user.update'), [
            'section' => 'profile', 'username' => $member->username, 'slug' => $member->slug, 'email' => $member->email,
            'prefix' => '+33', 'code' => 'FR', 'phone' => '612345678',
        ])->assertRedirectToRoute('user.edit')->assertSessionHasNoErrors();

        Http::assertNothingSent();
        $sent = app(BrevoService::class)->sent;
        $this->assertCount(1, $sent, 'the code was "sent" through the fake');
        $this->assertSame('+33612345678', $sent[0]['number']);
        $this->assertMatchesRegularExpression('/\d{5}/', $sent[0]['content']);
    }

    // ------------------------------------------------------------------------------------------------- R3-014

    public function test_the_welcome_sheet_gives_the_focus_to_the_pages_heading_when_it_closes(): void
    {
        $this->sellWithCardAndPayPal();
        [$monthly] = $this->plans();
        $member = User::factory()->create();
        Subscription::factory()->for($member)->for($monthly)->create(['stripe_status' => 'active', 'ends_at' => null]);

        $html = $this->actingAs($member->fresh())->get(route('pages.premium', ['checkout' => 'success']))->assertOk()->getContent();
        $script = substr($html, (int) strpos($html, "getElementById('premium')"), 2600);

        $this->assertStringContainsString('id="premium"', $html, 'the welcome sheet');
        $this->assertStringContainsString("hidden.bs.modal", $script);
        // the page's heading takes the focus (made focusable for it), never <body>
        $this->assertStringContainsString('.avd-premium-page h1', $script);
        $this->assertStringContainsString("setAttribute('tabindex', '-1')", $script);
        $this->assertStringContainsString('.focus(', $script);
    }

    public function test_the_crop_sheet_gives_the_focus_back_to_the_picker_that_opened_it(): void
    {
        $source = File::get(resource_path('js/components/Images/ImageUpload.tsx'));

        // the picker that opened the file chooser is remembered for the opening, and gets the focus back on close
        // (Bootstrap gives it back only to a visible trigger: the sheet's opener is a hidden button)
        $this->assertStringContainsString('cropReturn = target', $source);
        $this->assertMatchesRegularExpression('~addEventListener\(\'hidden\.bs\.modal\'.*?back\.focus\(~s', $source);
    }

    // ------------------------------------------------------------------------------------------------- R3-055

    public function test_the_demo_window_gives_the_focus_back_to_the_opener_of_a_sheet(): void
    {
        $demo = User::factory()->create();
        $demo->forceFill([ViewOnlyDemo::COLUMN => true])->save();

        $html = (string) $this->actingAs($demo->fresh())->get(route('user.edit', ['section' => 'delete']))->assertOk()->getContent();

        // the sheet's opener names the sheet with Bootstrap's data-bs-target (no aria-controls)
        $this->assertMatchesRegularExpression('~<button type="button" class="mk-btn mk-btn--danger-quiet" data-bs-toggle="modal" data-bs-target="#mk-delete-dialog">~', $html);
        $script = substr($html, (int) strpos($html, 'function controllerOf('), 1200);
        $this->assertNotSame('', $script);
        // the window's script follows data-bs-target (and a link's #href) to the opener, as it follows aria-controls
        $this->assertStringContainsString('data-bs-target', $script);
        $this->assertStringContainsString("'#' + node.id", $script);
    }

    // ------------------------------------------------------------------------------------------------- R3X-02

    public function test_the_picture_fields_messages_print_their_values(): void
    {
        $source = File::get(resource_path('js/components/Images/ImageUpload.tsx'));

        $this->assertStringNotContainsString('allowedTypes', $source, 'the word "allowedTypes" was printed to members');
        // every option of a t() call of the picture components is a {placeholder} of its text (the same mistake in the
        // other picture texts: none)
        foreach (['Images/ImageUpload.tsx', 'Images/ImageLoaded.tsx', 'Videos/Thumbnails.tsx'] as $component) {
            preg_match_all('~\bt\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*\{([^{}]*)\}~s', File::get(resource_path('js/components/'.$component)), $calls, PREG_SET_ORDER);
            foreach ($calls as [, , $text, $options]) {
                preg_match_all('~(?:^|,)\s*([A-Za-z_]\w*)\s*(?=[:,]|$)~', trim($options), $keys);
                foreach ($keys[1] as $key) {
                    $this->assertStringContainsString('{'.$key, $text, $component.': "'.$text.'" never prints its "'.$key.'"');
                }
            }
        }

        // the three messages are the crop window's own (data-msg-type / -size / -small), translated in every language
        foreach (['This file type cannot be used ({type}). Use {types}.', 'This picture is too large ({size} MB). Use one of {max} MB or less.',
            'This picture is too small ({width} x {height}). Use one of at least {minWidth} x {minHeight} pixels.'] as $key) {
            $this->assertStringContainsString("t('".$key."'", $source);
            foreach (['ar', 'de', 'es', 'fr', 'hi', 'id', 'ja', 'ko', 'ms', 'pt', 'ru', 'th', 'tl', 'tr', 'vi', 'zh'] as $locale) {
                $lines = json_decode(File::get(lang_path($locale.'.json')), true);
                $this->assertNotSame($key, $lines[$key] ?? $key, "$locale: \"$key\" is translated");
            }
        }
    }

    // ------------------------------------------------------------------------------------------------- R3-086

    public function test_a_section_is_printed_without_empty_lines_or_windows_line_ends(): void
    {
        // a run of white space that holds a line break (empty lines, the indentation, "\r\n") becomes one line break;
        // typed text inside <textarea> and <pre> stays exactly as it is
        $html = "<div>\r\n    <p>a</p>\r\n\r\n        \n    <p>b</p>   \n<textarea name=\"d\">x\r\n\r\n  y</textarea>\r\n</div>";

        $this->assertSame(
            "<div>\n<p>a</p>\n<p>b</p>\n<textarea name=\"d\">x\r\n\r\n  y</textarea>\n</div>",
            \App\Http\Controllers\User\ProfileController::compactHtml($html)
        );

        // a long run of spaces without a line break (a refused tagline printed back) is passed over in one go, also
        // where PHP runs without the PCRE JIT (a pattern that could start at every space took seconds there). A pattern
        // keeps the JIT it was compiled with in this process, so the check runs in a PHP of its own with pcre.jit=0.
        $probe = tempnam(sys_get_temp_dir(), 'compact');
        File::put($probe, '<?php require '.var_export(base_path('vendor/autoload.php'), true).';'
            .' $html = \'<input value="a\'.str_repeat(\' \', 100000).\'b">\'; $started = microtime(true);'
            .' $same = App\Http\Controllers\User\ProfileController::compactHtml($html) === $html;'
            .' echo json_encode([\'same\' => $same, \'seconds\' => microtime(true) - $started, \'jit\' => ini_get(\'pcre.jit\')]);');

        try {
            $run = \Illuminate\Support\Facades\Process::timeout(60)->run([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'pcre.jit=0', $probe]);
            $answer = preg_match('/\{[^{}]*\}\s*$/', $run->output(), $json) === 1 ? json_decode($json[0], true) : null;
            $this->assertIsArray($answer, 'the probe answered: '.$run->errorOutput());
            $this->assertSame('0', (string) $answer['jit'], 'the probe ran without the JIT');
            $this->assertTrue($answer['same'], 'a run without a line break stays as it is');
            $this->assertLessThan(1.0, $answer['seconds'], 'one pass over the run');
        } finally {
            File::delete($probe);
        }
    }

    // ------------------------------------------------------------------------------------------------- helpers

    private function sellWithCardAndPayPal(): void
    {
        config(['cashier.key' => 'pk_test_page', 'cashier.secret' => 'sk_test_page', 'cashier.webhook.secret' => 'whsec_test_page',
            'services.paypal.client_id' => 'paypal-client', 'services.paypal.client_secret' => 'paypal-secret', 'services.paypal.webhook_id' => 'WH-1']);
    }

    /** @return array{0: Plan, 1: Plan} */
    private function plans(): array
    {
        return [
            Plan::factory()->create(['name' => 'monthly', 'duration' => 1, 'price' => 500, 'stripe_id' => 'price_month', 'paypal_plan_id' => 'P-MONTH']),
            Plan::factory()->create(['name' => 'yearly', 'duration' => 12, 'price' => 5000, 'stripe_id' => 'price_year', 'paypal_plan_id' => 'P-YEAR']),
        ];
    }

    private function invoice(User $member): Transaction
    {
        $plan = Plan::factory()->create(['duration' => 1, 'price' => 500]);
        $subscription = Subscription::factory()->for($member)->for($plan)->create();

        return Transaction::query()->create([
            'stripe_id' => 'in_st3_list', 'amount' => 500, 'tax' => 0, 'fee' => 0, 'date' => Carbon::create(2026, 9, 24, 10, 0, 0),
            'user_id' => $member->id, 'subscription_id' => $subscription->id, 'name' => 'Ana Buyer', 'address' => '1 Long Road',
            'city' => 'Lisbon', 'postal_code' => '1000-001', 'country' => 'PT', 'vat_id' => null,
        ]);
    }
}
