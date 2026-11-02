<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Thumbnail;
use App\Models\User;
use App\Models\Video;
use App\Notifications\Account\BanAccount;
use App\Notifications\Account\DeleteAccount;
use App\Notifications\Account\PasswordUpdate;
use App\Notifications\Premium\CardExpired;
use App\Notifications\Premium\Unpaid;
use App\Notifications\Premium\Welcome;
use App\Notifications\Video\VideoBanned;
use App\Support\NumberFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Round 1 fixes of the member e-mails (judge R1-051, R1-052, R1-132, R1-133, R1-134) and of the notification settings
 * (R1-143):
 *  - a mail a console process renders (the scheduler's trial-end and card-expiry mails, the queue worker) links to
 *    the member's language: locale_url() compares with the site's default language, never with app.locale (which
 *    Laravel's setLocale() makes the mail's own language while it is rendered); a member whose stored language the
 *    site does not offer gets the site's language there too (User::preferredLocale(), not the configured app.locale);
 *  - "Your video was suspended" shows the video's picture inside the mail (an inline part: the thumbnail's address
 *    answers only the signed-in owner once the video is suspended), a poster from elsewhere by its full address, no
 *    broken or relative picture;
 *  - the Premium mails' "Need help? Contact us." is a link to the contact page (HTML and text);
 *  - amounts with the currency symbol, written the language's way ("5,00 €"), never "EUR 5.00";
 *  - the deleted and suspended account mails have no "Manage e-mails" link;
 *  - Account settings > Notifications lists "Upload results" only where members may upload.
 */
class MemberMailFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        Cache::flush();
        config(['mail.default' => 'array', 'app.name' => 'Sandbox Site']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ R1-051

    public function test_mails_of_the_scheduled_jobs_link_in_the_members_language(): void
    {
        // a console process (the scheduler): the web settings are not applied, the site's default language is English
        // (read again from the settings: what this test process read when it started is forgotten)
        $this->asAConsoleProcess();
        config(['plans.trial_period.email_reminder' => 7, 'cashier.currency' => 'eur']);
        $plan = Plan::query()->create(['name' => 'Monthly', 'price' => 500, 'duration' => 1]);
        $member = User::factory()->create(['language' => 'fr', 'username' => 'membre_fr']);
        DB::table('premiums')->insert(['stripe_status' => 'trialing', 'user_id' => $member->id, 'plan_id' => $plan->id, 'stripe_id' => 'sub_trial',
            'trial_ends_at' => now()->addDays(7), 'card_last4' => '4242', 'card_expired_at' => now()->endOfMonth()->toDateString(),
            'payment_provider' => 'stripe', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(0, Artisan::call('premium:trial_end'));
        $trial = $this->lastMail();
        $this->assertSame(__('Your free trial ends soon', [], 'fr'), $trial->getSubject());
        $text = (string) $trial->getTextBody();
        $this->assertStringContainsString(url('/fr/profile/edit'), $text, 'the button in French');
        $this->assertStringContainsString(url('/fr/profile/edit?section=notifications'), $text, 'the footer link in French');
        $this->assertStringNotContainsString(url('/profile/edit'), $text);
        $this->assertStringContainsString('/fr/profile/edit', (string) $trial->getHtmlBody());
        // R1-133: the plan's price with its symbol, the French way
        $this->assertMatchesRegularExpression('/5\s€/u', $text);
        $this->assertStringNotContainsString('EUR', $text);

        $this->assertSame(0, Artisan::call('premium:card_expiration'));
        $card = (string) $this->lastMail()->getTextBody();
        $this->assertStringContainsString(url('/fr/profile/edit')."\n", $card, '"Update my card" in French');
        $this->assertStringNotContainsString(url('/profile/edit')."\n", $card);
    }

    public function test_a_site_whose_language_is_french_links_its_english_members_to_english_pages(): void
    {
        // a console process that starts on a French site (this test's site is made after the app started, so what the
        // process read when it started is forgotten and read again)
        $this->asAConsoleProcess();
        DB::table('languages')->update(['is_default' => false]);
        DB::table('languages')->where('code', 'fr')->update(['is_default' => true]);
        Language::flushCache();

        $english = User::factory()->create(['language' => 'en']);
        $english->notify(new PasswordUpdate());
        $this->assertStringContainsString(url('/en/contact'), (string) $this->lastMail()->getTextBody(), 'English pages carry /en on a French site');

        $french = User::factory()->create(['language' => 'fr']);
        $french->notify(new PasswordUpdate());
        $text = (string) $this->lastMail()->getTextBody();
        $this->assertStringContainsString(url('/contact')."\n", $text, 'the site\'s own language has no prefix');
        $this->assertStringNotContainsString('/fr/contact', $text);
    }

    public function test_a_member_without_a_language_of_the_site_gets_the_scheduled_mails_in_the_sites_language(): void
    {
        // a French site, and a member whose stored language the site does not offer (an old account, a language the
        // admin turned off): the mails go out in the site's language, from a console process too (there it was the
        // configured app.locale, English, with /en links)
        $this->asAConsoleProcess();
        config(['plans.trial_period.email_reminder' => 7, 'cashier.currency' => 'eur']);
        DB::table('languages')->update(['is_default' => false]);
        DB::table('languages')->where('code', 'fr')->update(['is_default' => true]);
        Language::flushCache();
        $plan = Plan::query()->create(['name' => 'Monthly', 'price' => 500, 'duration' => 1]);
        $member = User::factory()->create(['language' => 'xx', 'username' => 'sans_langue']);
        $this->assertSame('fr', $member->preferredLocale(), 'the site\'s language');
        DB::table('premiums')->insert(['stripe_status' => 'trialing', 'user_id' => $member->id, 'plan_id' => $plan->id, 'stripe_id' => 'sub_trial_xx',
            'trial_ends_at' => now()->addDays(7), 'card_last4' => '4242', 'card_expired_at' => now()->endOfMonth()->toDateString(),
            'payment_provider' => 'stripe', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(0, Artisan::call('premium:trial_end'));
        $mail = $this->lastMail();
        $this->assertSame(__('Your free trial ends soon', [], 'fr'), $mail->getSubject());
        $this->assertStringContainsString('<html lang="fr"', (string) $mail->getHtmlBody());
        $text = (string) $mail->getTextBody();
        $this->assertStringContainsString(url('/profile/edit')."\n", $text, 'the site\'s own language has no prefix');
        $this->assertStringNotContainsString('/en/', $text);

        // a web request's setting is the answer wherever it is set (as before)
        config(['site.default_locale' => 'de']);
        $this->assertSame('de', $member->fresh()->preferredLocale());
        $this->assertSame('fr', User::factory()->create(['language' => 'fr'])->preferredLocale(), 'a language of the site stays the member\'s');
    }

    public function test_a_console_process_reads_the_sites_language_once_and_leaves_the_webs_setting_alone(): void
    {
        // kept apart from site.default_locale (and from the site.* config, the admin settings' mirror): the request
        // middleware and the controllers that take "site.default_locale, else app.locale" behave as before (an admin's
        // own language for a flash line, CountedMessagesTest); the test process read it when it started
        // (AppServiceProvider), so its requests count no query for it
        $this->assertTrue(app()->bound('site_default_locale.console'));
        $this->assertIsString(app('site_default_locale.console'));
        $this->assertNotSame('', app('site_default_locale.console'));
        $this->assertNull(config('site.default_locale'));

        // a console process reads it on its first link as the web does (the languages table's default over the "Site
        // language" setting, else the configured language), and it only READS the web's caches (a cron job may run as
        // another system user: a cache file it wrote, the web could not replace)
        Setting::set('default_locale', 'de', 'localization');
        $fresh = function (): string {
            app()->forgetInstance('site_default_locale.console');
            Cache::flush();

            return site_default_locale();
        };
        $this->assertSame('en', $fresh(), 'the languages table\'s default wins over the setting');
        foreach (['settings.__all_grouped', 'languages_all_collection', 'schema.has_languages_table'] as $key) {
            $this->assertFalse(Cache::has($key), $key.': not written by a console process');
        }
        DB::table('languages')->update(['is_default' => false]);
        $this->assertSame('de', $fresh(), 'no default language: the "Site language" setting');
        Setting::set('default_locale', '', 'localization');
        $this->assertSame((string) config('app.fallback_locale'), $fresh(), 'neither: the configured language');

        // the web's caches, where it filled them, answer without a query
        DB::table('languages')->where('code', 'fr')->update(['is_default' => true]);
        Cache::flush();
        Setting::getAllGrouped();
        Language::getAllCached();
        app()->forgetInstance('site_default_locale.console');
        DB::enableQueryLog();
        $this->assertSame('fr', site_default_locale());
        $this->assertSame([], DB::getQueryLog(), 'read from the web\'s caches');

        // a language set while a mail is rendered (setLocale() writes app.locale) is not the site's; asked again: no query
        app()->setLocale('ru');
        $this->assertSame('fr', site_default_locale());
        $this->assertSame('/ru/contact', locale_url(route('contact.show')));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();

        // a web request's setting (applyDbSettings) is the answer wherever it is set
        config(['site.default_locale' => 'ru']);
        $this->assertSame('ru', site_default_locale());
        $this->assertSame('/contact', locale_url(route('contact.show')));
    }

    // ------------------------------------------------------------------ R1-052

    public function test_the_suspended_video_mail_carries_the_videos_picture_inside(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $video = Video::factory()->for($owner)->create(['title' => 'Night drive', 'poster_url' => null]);
        $thumbnail = Thumbnail::query()->create(['video_id' => $video->id, 'file' => 'night-drive.png', 'is_active' => true]);
        Storage::disk('local')->put(Video::THUMBNAIL_FOLDER.'/'.$thumbnail->file, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8z8DAwMDAxMDAwMDAAAANHQEDK+mmyAAAAABJRU5ErkJggg=='));

        $owner->notify(new VideoBanned($video->fresh()));
        $mail = $this->lastMail();
        $html = (string) $mail->getHtmlBody();
        $this->assertMatchesRegularExpression('#<img src="cid:[^"]+" alt="Night drive"#', $html, 'the picture is a part of the mail');
        $this->assertStringNotContainsString('/thumbnail"', $html, 'not the address only the signed-in owner can open');
        $inline = collect($mail->getAttachments())->filter(fn ($part) => $part->getDisposition() === 'inline');
        $this->assertCount(1, $inline);
        $this->assertSame('image/png', $inline->first()->getMediaType().'/'.$inline->first()->getMediaSubtype());

        // a poster from elsewhere: its full address; one of the site's own files: its full address
        $remote = Video::factory()->for($owner)->create(['title' => 'Remote', 'poster_url' => 'https://cdn.example.test/p/remote.jpg']);
        $owner->notify(new VideoBanned($remote));
        $this->assertStringContainsString('<img src="https://cdn.example.test/p/remote.jpg"', (string) $this->lastMail()->getHtmlBody());
        $local = Video::factory()->for($owner)->create(['title' => 'Demo', 'poster_url' => '/images/tr/thumb_01.jpg']);
        $owner->notify(new VideoBanned($local));
        $this->assertStringContainsString('<img src="'.url('/images/tr/thumb_01.jpg').'"', (string) $this->lastMail()->getHtmlBody());

        // no picture at all: no broken one (the placeholder SVG is not shown by most mail clients)
        $none = Video::factory()->for($owner)->create(['title' => 'None', 'poster_url' => null]);
        $owner->notify(new VideoBanned($none));
        $plain = (string) $this->lastMail()->getHtmlBody();
        $this->assertStringNotContainsString('<img', $plain);
        $this->assertStringNotContainsString('no-image.svg', $plain);
    }

    // ------------------------------------------------------------------ R1-132, R1-134

    public function test_premium_mails_link_their_help_line_and_closed_accounts_have_no_settings_link(): void
    {
        config(['site.default_locale' => 'en']);
        $member = User::factory()->create(['username' => 'paying_member']);
        $contact = url('/contact');

        foreach (['welcome' => new Welcome(), 'unpaid' => new Unpaid(500), 'card' => new CardExpired()] as $name => $notification) {
            $member->notify($notification);
            $mail = $this->lastMail();
            $html = (string) $mail->getHtmlBody();
            $this->assertMatchesRegularExpression('#<a href="'.preg_quote($contact, '#').'"[^>]*>'.preg_quote(e(__('Need help? Contact us.')), '#').'</a>#', $html, $name);
            $this->assertStringContainsString(__('Need help? Contact us.')."\n".$contact, (string) $mail->getTextBody(), $name);
            $this->assertStringContainsString(e(__('Manage e-mails')), $html, $name.': a member can manage the e-mails');
        }

        foreach (['ban' => new BanAccount(), 'delete' => new DeleteAccount()] as $name => $notification) {
            $member->notify($notification);
            $mail = $this->lastMail();
            $this->assertStringNotContainsString(e(__('Manage e-mails')), (string) $mail->getHtmlBody(), $name);
            $this->assertStringNotContainsString(__('Manage e-mails'), (string) $mail->getTextBody(), $name);
            $this->assertStringNotContainsString('section=notifications', (string) $mail->getTextBody(), $name);
            $this->assertStringContainsString(__('You get this e-mail because of your account on :site.', ['site' => 'Sandbox Site']), (string) $mail->getTextBody(), $name);
        }
    }

    // ------------------------------------------------------------------ R1-133

    public function test_amounts_carry_the_symbol_written_the_languages_way(): void
    {
        $space = '[\s\x{00A0}\x{202F}]';
        $this->assertSame('€5.00', NumberFormat::money(500, 'eur', 'en', 2));
        $this->assertMatchesRegularExpression('/^5,00'.$space.'€$/u', NumberFormat::money(500, 'EUR', 'fr', 2));
        $this->assertMatchesRegularExpression('/^1\.234,50'.$space.'€$/u', NumberFormat::money(123450, 'EUR', 'de', 2));
        $this->assertMatchesRegularExpression('/^\x{200F}?5\.00'.$space.'€$/u', NumberFormat::money(500, 'EUR', 'ar', 2));
        $this->assertSame('€5', NumberFormat::money(500, 'EUR', 'en'), 'a whole amount without decimals');
        $this->assertSame('€4.17', NumberFormat::money(417, 'EUR', 'en'));
        $this->assertMatchesRegularExpression('/^(¥|￥)500$/u', NumberFormat::money(500, 'JPY', 'ja'), 'yen have no cents');
        $this->assertStringNotContainsString('EUR', NumberFormat::money(500, 'EUR', 'tr'));

        config(['cashier.currency' => 'eur', 'site.default_locale' => 'en']);
        $member = User::factory()->create(['language' => 'fr']);
        $member->notify(new Unpaid(500));
        $text = (string) $this->lastMail()->getTextBody();
        $this->assertMatchesRegularExpression('/5,00'.$space.'€/u', $text);
        $this->assertStringNotContainsString('EUR 5.00', $text);
    }

    // ------------------------------------------------------------------ R1-143

    public function test_upload_results_are_listed_only_where_members_may_upload(): void
    {
        config(['site.default_locale' => 'en', 'app.locale' => 'en']);
        $member = User::factory()->create();

        config(['site.allow_uploads' => true]);
        $on = (string) $this->actingAs($member)->get(route('user.edit', ['section' => 'notifications']))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('Upload results')), $on);
        $this->assertStringContainsString(e(__('Account e-mails')), $on);

        config(['site.allow_uploads' => false]);
        $off = (string) $this->actingAs($member)->get(route('user.edit', ['section' => 'notifications']))->assertOk()->getContent();
        $this->assertStringNotContainsString(e(__('Upload results')), $off, 'members cannot upload: no upload results');
        $this->assertStringContainsString(e(__('Account e-mails')), $off);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A console process (the scheduler, the queue worker): the web's applyDbSettings() never ran there, so the key
     * site.default_locale is ABSENT (not null: "site.default_locale, else app.locale" then read app.locale, which
     * Laravel's setLocale() makes the mail's own language), and the process has not read the site's language yet.
     */
    private function asAConsoleProcess(): void
    {
        $site = (array) config('site', []);
        unset($site['default_locale']);
        config(['site' => $site]);
        app()->forgetInstance('site_default_locale.console');
        $this->assertFalse(config()->has('site.default_locale'), 'no site.default_locale, as in a console process');
    }

    private function lastMail(): Email
    {
        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertGreaterThan(0, $messages->count(), 'a mail was sent');

        return $messages->last()->getOriginalMessage();
    }
}
