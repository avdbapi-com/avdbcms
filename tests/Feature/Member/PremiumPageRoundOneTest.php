<?php

namespace Tests\Feature\Member;

use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Support\PremiumPageSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The Premium page after round 1 of 1.2.3:
 * - R1-004: the side menu is pinned on /premium (ThemeStructure) and the page sits beside it: the page's own "ms-0"
 *   took the main column's start margin away, so the menu covered 208-215 px of the page from 992 to 1919 px.
 * - R1-114: the plan card reads "Your billing starts on <date>. Cancel anytime." (it read "... for the month plan.
 *   Cancel anytime", no full stop) and "Save 2 months" is said once (the yearly card's badge, from the real prices); a
 *   stored text that is exactly the old shipped one gets the new default (migration 2026_10_27_130000), any other
 *   text stays.
 * - R1-123: "Ads on videos" shows the benefit: Premium "No ads" with the check, Free "Yes" without a mark; the admin's
 *   help of the two switches says so (review).
 * - R1-122: the Arabic saving badge for 2 months uses the dual (شهرين); the trial's days are said with the forms of the
 *   number in every language while the shipped texts are kept (review: "1 days", Arabic 1, 2 and 11 or more).
 */
class PremiumPageRoundOneTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_27_130000_correct_premium_page_default_texts';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config([
            'site.default_locale' => 'en',
            'app.locale' => 'en',
            'cashier.currency' => 'eur',
            'plans.trial_period.period' => 0,
            'site.premium_enabled' => true,
            'site.stripe_enabled' => true,
            'site.paypal_enabled' => false,
            'cashier.key' => 'pk_test_page',
            'cashier.secret' => 'sk_test_page',
            'cashier.webhook.secret' => 'whsec_test_page',
        ]);
        $this->withoutVite();
    }

    private function plans(): void
    {
        Plan::factory()->create(['name' => 'monthly', 'duration' => 1, 'price' => 500, 'stripe_id' => 'price_month']);
        Plan::factory()->create(['name' => 'yearly', 'duration' => 12, 'price' => 5000, 'stripe_id' => 'price_year']);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }

    /** The main column's classes of a page (components/layout.blade.php: #main-container). */
    private function mainClasses(string $html): array
    {
        $this->assertSame(1, preg_match('/<div id="main-container" class="([^"]*)"/', $html, $match));

        return preg_split('/\s+/', trim($match[1])) ?: [];
    }

    public function test_the_premium_page_sits_beside_the_pinned_side_menu(): void
    {
        foreach ([null, User::factory()->create()] as $visitor) {
            if ($visitor) {
                $this->actingAs($visitor);
            }

            $html = $this->get(route('pages.premium'))->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/<body class="[^"]*\bhas-sidebar\b/', $html, 'the menu is pinned on /premium');
            $this->assertNotContains('ms-0', $this->mainClasses($html), 'R1-004: the main column keeps the margin that keeps it out from under the menu');
        }
    }

    public function test_the_plan_card_reads_two_full_sentences_and_the_saving_is_said_once(): void
    {
        $this->plans();

        $html = $this->actingAs(User::factory()->create())->get(route('pages.premium'))->assertOk()->getContent();

        $this->assertStringContainsString('<p class="mk-muted">'.e(__('Your billing starts today.').' '.__('Cancel anytime.')).'</p>', $html);
        $this->assertStringNotContainsString(e(__('month')).' plan', $html);
        $this->assertStringNotContainsString('Save 2 months with the annual subscription', $html, 'no note repeating the badge');
        $badge = e(trans_choice('{1} Save :count month|[2,*] Save :count months', 2, ['count' => 2]));
        $this->assertSame(1, substr_count($html, $badge), 'the saving once, on the yearly card');

        // with a free trial: the trial's date
        config(['plans.trial_period.period' => 30]);
        $trial = $this->actingAs(User::factory()->create())->get(route('pages.premium'))->assertOk()->getContent();
        // the date as every member page writes it (R2A-038: BillingFormat::date, "Nov 5, 2026", no leading zero)
        $date = \App\Support\BillingFormat::date(now()->addDays(30));
        $this->assertStringContainsString('<p class="mk-muted">'.e(__('Your billing starts on :date.', ['date' => $date]).' '.__('Cancel anytime.')).'</p>', $trial);

        // an owner's own texts are used as they are
        config(['site.premium_billing_without_trial_summary' => 'Billed from today.', 'site.premium_cancel_anytime_label' => 'Stop when you like.', 'site.premium_annual_savings_text' => 'Pay yearly, save.', 'plans.trial_period.period' => 0]);
        $own = $this->actingAs(User::factory()->create())->get(route('pages.premium'))->assertOk()->getContent();
        $this->assertStringContainsString('Billed from today. Stop when you like.', $own);
        $this->assertStringContainsString('Pay yearly, save.', $own);
    }

    public function test_the_defaults_of_the_page_the_settings_and_the_migration_agree(): void
    {
        $defaults = PremiumPageSettings::defaults();
        $settings = config('admin_settings.settings');

        foreach (PremiumPageSettings::RETIRED_DEFAULTS as $key => [$old, $new]) {
            $this->assertSame($new, $defaults[$key], $key.': PremiumPageSettings');
            $this->assertSame($new, $settings[$key]['default'] ?? null, $key.': config/admin_settings.php (scripts/admin_settings/generate_config.py)');
            $this->assertSame([$old, $new], $this->migration()::CORRECTIONS[$key], $key.': the migration');
        }

        // the default texts the page translates are in the translation tools' list (scripts/i18n/sources.json)
        $this->assertContains('Your billing starts on :date.', PremiumPageSettings::translatableDefaults());
        $this->assertNotContains(':date', PremiumPageSettings::translatableDefaults(), 'a bare placeholder is not a text');
        $this->assertArrayNotHasKey('premium_feature_uploads_icon', PremiumPageSettings::translatableDefaults());
        foreach (PremiumPageSettings::COUNTED_DEFAULTS as $key) {
            $this->assertArrayHasKey($key, $defaults);
            $this->assertArrayNotHasKey($key, PremiumPageSettings::translatableDefaults(), $key.': said with plural lines, never translated itself');
        }
        $sources = json_decode((string) file_get_contents(base_path('scripts/i18n/sources.json')), true);
        $this->assertArrayHasKey('App\\Support\\PremiumPageSettings::translatableDefaults', $sources['catalogs']);
    }

    public function test_the_migration_changes_only_the_exact_shipped_texts(): void
    {
        User::factory()->create();
        $rows = [
            'premium_billing_trial_summary' => 'Your billing starts on :date for the :period plan.',
            'premium_billing_without_trial_summary' => 'Your billing starts today for the :period plan. ', // not exactly the shipped text
            'premium_cancel_anytime_label' => 'Cancel anytime',
            'premium_annual_savings_text' => 'Save 2 months with the annual subscription',
            'premium_page_subtitle' => 'Unleash the full potential of your creative journey with our premium plan',
        ];
        foreach ($rows as $key => $value) {
            Setting::set($key, $value, 'pages');
        }
        Setting::set('premium_then_label', 'Cancel anytime', 'pages'); // the same words in another setting

        $this->migration()->up();

        $stored = DB::table('settings')->pluck('value', 'key');
        $this->assertSame('Your billing starts on :date.', $stored['premium_billing_trial_summary']);
        $this->assertSame('Your billing starts today for the :period plan. ', $stored['premium_billing_without_trial_summary'], 'an owner text stays');
        $this->assertSame('Cancel anytime.', $stored['premium_cancel_anytime_label']);
        $this->assertSame('', $stored['premium_annual_savings_text']);
        $this->assertSame($rows['premium_page_subtitle'], $stored['premium_page_subtitle'], 'other texts are not touched');
        $this->assertSame('Cancel anytime', $stored['premium_then_label']);

        $audit = DB::table('admin_audit_logs')->where('action', 'update.premium_page_texts')->get();
        $this->assertCount(1, $audit);
        $this->assertSame('1.2.3: Premium page texts set to the new wording: billing line, cancel line, savings note', $audit[0]->details);

        // safe to run again: nothing matches, nothing more is logged
        $this->migration()->up();
        $this->assertSame(1, DB::table('admin_audit_logs')->where('action', 'update.premium_page_texts')->count());
        $this->assertSame($stored->all(), DB::table('settings')->pluck('value', 'key')->all());
    }

    public function test_ads_on_videos_shows_the_benefit_of_premium(): void
    {
        $this->plans();
        config(['site.premium_comparison_enabled' => true, 'site.premium_comparison_ads_row_enabled' => true, 'site.premium_comparison_ads_free_enabled' => true, 'site.premium_comparison_ads_premium_enabled' => false]);

        $html = $this->get(route('pages.premium'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<td class="fw-bold">'.preg_quote(e(__('Ads on videos')), '#').'</td>\s*<td>(.*?)</td>\s*<td><strong>(.*?)</strong></td>#s', $html, $row));
        $this->assertSame(e(__('Yes')), trim($row[1]), 'Free: "Yes", without a check');
        $this->assertSame('<i class="fa-solid fa-check" aria-hidden="true"></i> '.e(__('No ads')), trim($row[2]), 'Premium: the benefit with the check');
        $this->assertStringNotContainsString('fa-xmark', $row[1].$row[2]);
    }

    /**
     * The admin's help of the two "has ads" switches says what the page shows (review of R1-123: it still said "Tick
     * shown in the Free column." while a tick now stands for "No ads").
     */
    public function test_the_admin_help_of_the_ads_switches_says_what_the_page_shows(): void
    {
        $free = 'On: the Free plan column says "Yes". Off: it shows a tick and "No ads".';
        $premium = 'On: the Premium plan column says "Yes". Off: it shows a tick and "No ads".';
        $settings = config('admin_settings.settings');

        $this->assertSame($free, $settings['premium_comparison_ads_free_enabled']['help'] ?? null);
        $this->assertSame($premium, $settings['premium_comparison_ads_premium_enabled']['help'] ?? null);
        $this->assertSame('Tick shown in the Free column.', $settings['premium_comparison_downloads_free_enabled']['help'] ?? null, 'the Downloads row keeps its tick');

        config(['admin.ui' => 'v2']);
        $this->actingAs(User::factory()->admin()->create())->get('/admin/money/premium-page')->assertOk()
            ->assertSee(__($free))
            ->assertSee(__($premium));
    }

    /**
     * The trial's days are said with the forms of the number (review of R1-122): the kept default texts read "1 days
     * trial" and "We will email you 1 days before your trial ends."; an owner's own text is used as written.
     */
    public function test_the_trial_texts_say_the_days_in_the_form_of_the_number(): void
    {
        $this->plans();
        $member = User::factory()->create();

        config(['plans.trial_period.period' => 2, 'plans.trial_period.email_reminder' => 1]);
        $two = $this->actingAs($member)->get(route('pages.premium'))->assertOk()->getContent();
        $this->assertStringContainsString('<span class="mk-badge mk-badge--premium">2 days trial</span>', $two);
        $this->assertStringContainsString('<strong>2 days trial</strong>', $two, 'the timeline');
        $this->assertStringContainsString('<span>We will email you 1 day before your trial ends.</span>', $two);
        $this->assertStringNotContainsString('1 days', $two);

        config(['plans.trial_period.period' => 1, 'plans.trial_period.email_reminder' => 0]);
        $one = $this->actingAs($member)->get(route('pages.premium'))->assertOk()->getContent();
        $this->assertStringContainsString('<span class="mk-badge mk-badge--premium">1 day trial</span>', $one);
        $this->assertStringContainsString('<strong>1 day trial</strong>', $one);
        $this->assertStringNotContainsString('1 days', $one);

        // an owner's own texts are used as they are
        config([
            'plans.trial_period.period' => 2,
            'plans.trial_period.email_reminder' => 1,
            'site.premium_trial_badge_template' => 'Try :days days',
            'site.premium_timeline_trial_title' => ':days days on us at :site',
            'site.premium_timeline_reminder_description' => 'A note :days days ahead.',
        ]);
        $own = $this->actingAs($member)->get(route('pages.premium'))->assertOk()->getContent();
        $this->assertStringContainsString('<span class="mk-badge mk-badge--premium">Try 2 days</span>', $own);
        $this->assertStringContainsString('<strong>2 days on us at '.e(config('app.name')).'</strong>', $own);
        $this->assertStringContainsString('<span>A note 1 days ahead.</span>', $own);
    }

    /**
     * The same lines in Arabic and Russian use every form of the number (review of R1-122: the Arabic reminder said
     * "أيام" for 1, 2 and 11 or more days). Needs the lane's i18n.json applied (the orchestrator's merge step).
     */
    public function test_the_arabic_and_russian_trial_texts_use_the_forms_of_the_number(): void
    {
        $trial = '{1} :count day trial|[2,*] :count days trial';
        $reminder = '{1} We will email you :count day before your trial ends.|[2,*] We will email you :count days before your trial ends.';

        $this->assertSame('تجربة ليوم واحد', trans_choice($trial, 1, [], 'ar'));
        $this->assertSame('تجربة ليومين', trans_choice($trial, 2, [], 'ar'));
        $this->assertSame('تجربة لمدة 7 أيام', trans_choice($trial, 7, [], 'ar'));
        $this->assertSame('تجربة لمدة 14 يومًا', trans_choice($trial, 14, [], 'ar'));
        $this->assertSame('سنرسل إليك رسالة بالبريد الإلكتروني قبل انتهاء الفترة التجريبية بيوم واحد.', trans_choice($reminder, 1, [], 'ar'));
        $this->assertSame('سنرسل إليك رسالة بالبريد الإلكتروني قبل انتهاء الفترة التجريبية بيومين.', trans_choice($reminder, 2, [], 'ar'));
        $this->assertSame('سنرسل إليك رسالة بالبريد الإلكتروني قبل انتهاء الفترة التجريبية بـ 7 أيام.', trans_choice($reminder, 7, [], 'ar'));
        $this->assertSame('سنرسل إليك رسالة بالبريد الإلكتروني قبل انتهاء الفترة التجريبية بـ 11 يومًا.', trans_choice($reminder, 11, [], 'ar'));

        $this->assertSame('Мы отправим вам письмо за 1 день до окончания пробного периода.', trans_choice($reminder, 1, [], 'ru'));
        $this->assertSame('Мы отправим вам письмо за 3 дня до окончания пробного периода.', trans_choice($reminder, 3, [], 'ru'));
        $this->assertSame('Мы отправим вам письмо за 7 дней до окончания пробного периода.', trans_choice($reminder, 7, [], 'ru'));
        $this->assertSame('Мы отправим вам письмо за 21 день до окончания пробного периода.', trans_choice($reminder, 21, [], 'ru'));
    }

    public function test_the_arabic_saving_badge_uses_the_dual_for_two_months(): void
    {
        $line = '{1} Save :count month|[2,*] Save :count months';

        $this->assertSame('وفّر شهرين', trans_choice($line, 2, ['count' => 2], 'ar'));
        $this->assertSame('وفّر شهرًا واحدًا', trans_choice($line, 1, ['count' => 1], 'ar'));
        $this->assertSame('وفّر 3 أشهر', trans_choice($line, 3, ['count' => 3], 'ar'));
        $this->assertSame('وفّر 11 شهرًا', trans_choice($line, 11, ['count' => 11], 'ar'));
    }
}
