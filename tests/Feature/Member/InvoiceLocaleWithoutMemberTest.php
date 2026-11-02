<?php

namespace Tests\Feature\Member;

use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The language of an invoice whose member is gone (a deleted account; R1R-07): the site's language, read the way the
 * web and a console process both read it (site_default_locale()). In a console process (the queue worker, the
 * scheduler's commands) site.default_locale is never set, so "site.default_locale, else app.locale" took the
 * install-time APP_LOCALE - or the language of the last mail Laravel rendered there, since setLocale() writes
 * app.locale - instead of the "Site language" the admin chose.
 */
class InvoiceLocaleWithoutMemberTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app()->forgetInstance('site_default_locale.console');

        parent::tearDown();
    }

    public function test_a_payment_without_a_member_gets_the_sites_language_in_a_console_process(): void
    {
        $this->siteLanguage('de');
        $orphan = $this->payment(null);
        $deleted = $this->payment(User::factory()->create(['language' => 'fr']));
        DB::table('users')->where('id', $deleted->user_id)->delete();
        $deleted = $deleted->fresh();
        $this->assertNull($deleted->user, 'the member\'s account is gone');

        $this->asAConsoleProcess();
        // the install-time language, and then a mail rendered in Russian: neither is the site's
        app()->setLocale('en');
        $this->assertSame('de', InvoiceService::locale($orphan));
        $this->assertSame('de', InvoiceService::locale($deleted));
        app()->setLocale('ru');
        $this->assertSame('de', InvoiceService::locale($orphan), 'a language set while a mail was rendered is not the site\'s');

        // a member who still has an account keeps their own language
        $this->assertSame('fr', InvoiceService::locale($this->payment(User::factory()->create(['language' => 'fr']))));
        app()->setLocale('en');
    }

    public function test_on_the_web_the_sites_language_setting_is_the_answer_and_one_the_pdf_cannot_draw_is_english(): void
    {
        $orphan = $this->payment(null);

        config(['site.default_locale' => 'es']);
        $this->assertSame('es', InvoiceService::locale($orphan));

        // the PDF font draws no Japanese: English, as for a member
        config(['site.default_locale' => 'ja']);
        $this->assertSame('en', InvoiceService::locale($orphan));

        // a console process of a Japanese site: English too
        $this->siteLanguage('ja');
        $this->asAConsoleProcess();
        $this->assertSame('en', InvoiceService::locale($orphan));
    }

    /** The admin's "Site language" (the languages table's default and the setting, as the admin saves them). */
    private function siteLanguage(string $code): void
    {
        Setting::set('default_locale', $code, 'localization');
        // the languages table's default wins where the table names one: the same language there, or none at all
        DB::table('languages')->update(['is_default' => false]);
        DB::table('languages')->where('code', $code)->update(['is_default' => true, 'is_active' => true]);

        Cache::flush();
    }

    /**
     * A console process (the scheduler, the queue worker): the web's applyDbSettings() never ran there, so the key
     * site.default_locale is ABSENT, and the process has not read the site's language yet.
     */
    private function asAConsoleProcess(): void
    {
        $site = (array) config('site', []);
        unset($site['default_locale']);
        config(['site' => $site]);
        app()->forgetInstance('site_default_locale.console');
        $this->assertFalse(config()->has('site.default_locale'));
    }

    private function payment(?User $member): Transaction
    {
        static $n = 0;
        $n++;

        return Transaction::query()->create([
            'stripe_id' => 'in_locale_'.$n,
            'amount' => 500,
            'tax' => 0,
            'fee' => 0,
            'date' => Carbon::create(2026, 9, 24, 10, 0, 0),
            'user_id' => $member?->id,
            'subscription_id' => null,
            'name' => 'Ana Buyer',
            'address' => '1 Long Road',
            'city' => 'Lisbon',
            'postal_code' => '1000-001',
            'country' => 'PT',
        ]);
    }
}
