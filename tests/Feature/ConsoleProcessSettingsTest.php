<?php

namespace Tests\Feature;

use App\Jobs\DeleteUser;
use App\Models\Language;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Premium\TrialEnd;
use App\Providers\AppServiceProvider;
use App\Support\ChannelAccent;
use App\Support\ChannelCustomizationSettings;
use App\Support\ConsoleSiteSettings;
use App\Support\SiteSettingsSource;
use Illuminate\Bus\Queueable;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/**
 * Console processes use the admin's settings (judge R1R-06; also 1.2.2): the scheduler's commands and the queue worker
 * apply what a web request applies (AppServiceProvider::applySettings(), one code path), so their mails go through the
 * SMTP server, with the sender, the site name, the time zone, the date format, the trial reminder and the languages the
 * admin saved, not the .env values of the installation; a worker that started before the admin changed them uses the
 * new ones from its next job on. The commands that build or repair the database or write the framework's caches never
 * apply them, nor does a process while there is no settings table or an empty one. The test process itself keeps its
 * behaviour (nothing is registered there): these tests start the console part the way a console process does.
 */
class ConsoleProcessSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> what each mail went out with */
    private array $sent = [];

    private string $zoneBefore = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();

        $this->zoneBefore = date_default_timezone_get();
        Cache::flush();
        config(['app.name' => 'Name from the installation', 'mail.default' => 'array', 'mail.from.address' => 'install@example.test',
            'mail.mailers.smtp.host' => 'smtp.installation.test', 'site.date_format' => 'M d, Y']);
        $this->sent = [];

        // Every mail is looked at as it goes and then stopped: no mail server is ever asked.
        Event::listen(MessageSending::class, function (MessageSending $event) {
            $transport = app('mail.manager')->mailer()->getSymfonyTransport();
            $this->sent[] = [
                'host' => $transport instanceof EsmtpTransport ? $transport->getStream()->getHost() : get_class($transport),
                'port' => $transport instanceof EsmtpTransport ? $transport->getStream()->getPort() : null,
                'from' => $event->message->getFrom()[0]->getAddress(),
                'from_name' => $event->message->getFrom()[0]->getName(),
                'subject' => (string) $event->message->getSubject(),
                'html' => (string) $event->message->getHtmlBody(),
                'text' => (string) $event->message->getTextBody(),
                'zone' => date_default_timezone_get(),
            ];

            return false;
        });
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zoneBefore);

        parent::tearDown();
    }

    public function test_the_test_process_registers_nothing_of_it(): void
    {
        $this->assertFalse($this->app->bound(ConsoleSiteSettings::class), 'the test process keeps its behaviour');
        $this->saveSettings(self::NEW);
        $this->assertSame('Name from the installation', config('app.name'));
    }

    public function test_a_scheduled_mail_goes_with_the_settings_the_admin_saved(): void
    {
        // The trial ends in 3 days (the admin's reminder) in the admin's time zone, late in the evening there.
        $ends = Carbon::now('Pacific/Kiritimati')->addDays(3)->setTime(23, 30);
        $member = $this->trialMember($ends->format('Y-m-d H:i:s'));
        $this->saveSettings(self::OLD);
        $this->saveSettings(self::NEW);

        // The scheduler starts "php artisan premium:trial_end": a process of its own.
        $this->assertTrue($this->consoleProcess('premium:trial_end')->active());
        $this->assertSame(0, Artisan::call('premium:trial_end'));

        $this->assertCount(1, $this->sent, 'the trial reminder of the admin (3 days, in the admin\'s time zone) found the member');
        $mail = $this->sent[0];
        $this->assertSame('smtp.new.example', $mail['host']);
        $this->assertSame(2526, $mail['port']);
        $this->assertSame('news@site.example', $mail['from']);
        $this->assertSame('New sender', $mail['from_name']);
        $this->assertSame('Pacific/Kiritimati', $mail['zone']);
        $this->assertStringContainsString('New site name', $mail['text']);
        $this->assertStringContainsString($ends->format('d/m/Y'), $mail['text'], "the admin's date format and time zone");
        $this->assertStringNotContainsString('Name from the installation', $mail['html']);
        $this->assertSame($member->id, $member->fresh()->id);
    }

    public function test_a_worker_that_started_before_the_admin_changed_the_settings_sends_with_the_new_ones(): void
    {
        $this->saveSettings(self::OLD);
        $worker = $this->consoleProcess('queue:work');
        $this->assertSame('smtp.old.example', config('mail.mailers.smtp.host'));
        $this->assertSame('Old site name', config('app.name'));
        $before = app('mail.manager')->mailer('smtp');

        // The admin saves Settings > Email and the site settings while the worker runs; then members' mails are queued.
        $this->saveSettings(self::NEW);
        $leaving = User::factory()->create(['username' => 'leaving_member']);
        dispatch((new DeleteUser($leaving))->onConnection('database'));
        $ends = Carbon::now('Pacific/Kiritimati')->addDays(3)->setTime(23, 30);
        $member = $this->trialMember($ends->format('Y-m-d H:i:s'));
        $member->notify((new ConsoleProcessQueuedTrialEnd())->onConnection('database'));

        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096]));

        $this->assertCount(2, $this->sent);
        foreach ($this->sent as $mail) {
            $this->assertSame('smtp.new.example', $mail['host'], $mail['subject']);
            $this->assertSame('news@site.example', $mail['from'], $mail['subject']);
            $this->assertSame('Pacific/Kiritimati', $mail['zone'], $mail['subject']);
            $this->assertStringContainsString('New site name', $mail['text'], $mail['subject']);
        }
        $this->assertStringContainsString($ends->format('d/m/Y'), $this->sent[1]['text']);
        $this->assertNotSame($before, app('mail.manager')->mailer('smtp'), 'the old transport was forgotten');
        $this->assertSame(0, DB::table('jobs')->count());

        // Unchanged settings are not applied again before the next job.
        $this->assertFalse($worker->refresh());
        Setting::set('date_format', 'Y-m-d', 'localization');
        $this->assertTrue($worker->refresh());
        $this->assertSame('Y-m-d', config('site.date_format'));
    }

    public function test_the_languages_the_admin_turned_off_are_off_for_the_mails_too(): void
    {
        Language::query()->delete();
        foreach ([['en', 'English', true, true], ['fr', 'Français', true, false], ['de', 'Deutsch', false, false]] as $i => [$code, $label, $active, $default]) {
            Language::query()->create(['code' => $code, 'label' => $label, 'emoji' => '', 'direction' => 'ltr', 'is_active' => $active, 'is_default' => $default, 'sort_order' => $i]);
        }
        $german = User::factory()->create(['language' => 'de']);
        $french = User::factory()->create(['language' => 'fr']);
        $this->saveSettings(self::NEW);

        $this->consoleProcess('queue:work');

        $this->assertSame(['en', 'fr'], array_keys(config('languages')));
        $this->assertSame('en', $german->preferredLocale(), 'German is off: the site language');
        $this->assertSame('fr', $french->preferredLocale());
    }

    public function test_the_commands_that_build_the_database_or_the_framework_caches_never_apply_them(): void
    {
        foreach (['migrate', 'migrate:fresh', 'migrate:status', 'db:seed', 'db:wipe', 'db:create', 'schema:dump', 'key:generate',
            'package:discover', 'config:cache', 'config:clear', 'optimize', 'optimize:clear', 'view:cache', 'route:cache',
            'event:cache', 'cache:clear', 'vendor:publish', 'storage:link', 'list', 'help', '', 'make:model', 'down'] as $command) {
            $this->assertTrue(ConsoleSiteSettings::skips($command), "{$command} skips them");
        }

        foreach (['schedule:run', 'schedule:work', 'queue:work', 'queue:listen', 'horizon', 'horizon:work', 'premium:trial_end',
            'premium:card_expiration', 'video:published', 'avdb:schedules:run', 'notifications:tidy', 'tinker'] as $command) {
            $this->assertFalse(ConsoleSiteSettings::skips($command), "{$command} applies them");
        }

        $this->saveSettings(self::NEW);
        $settings = $this->consoleProcess('migrate');
        $this->assertFalse($settings->active());
        $this->assertSame('smtp.installation.test', config('mail.mailers.smtp.host'));
        $this->assertSame('Name from the installation', config('app.name'));
        // Only the process's own command decides: what it calls (Artisan::call) changes nothing.
        $this->assertFalse($settings->commandStarting('queue:work'));
        $this->assertSame('Name from the installation', config('app.name'));
    }

    public function test_nothing_is_applied_without_settings_and_the_caches_are_only_read(): void
    {
        // A fresh install, a database being built: an empty table, then none.
        Setting::query()->delete();
        Cache::flush();
        $this->assertNull(SiteSettingsSource::console());
        $this->assertTrue($this->consoleProcess('queue:work')->active());
        $this->assertSame('Name from the installation', config('app.name'));
        Schema::drop('settings');
        $this->assertNull(SiteSettingsSource::console());
        $this->assertFalse($this->app->make(ConsoleSiteSettings::class)->refresh(true));
        $this->assertSame('smtp.installation.test', config('mail.mailers.smtp.host'));
    }

    public function test_a_console_process_reads_the_caches_of_the_web_and_never_writes_them(): void
    {
        $this->saveSettings(self::NEW);
        // The channel accent every site updated from 1.2.x still has: the installer's "coral", never saved by the admin
        // (created_at = updated_at). Its check is kept in the admin cache by the web; a console process applying the
        // settings wrote that entry (judge round 1b BS-RV-05), owned by the cron's system user.
        Setting::set('channel_default_accent', ChannelCustomizationSettings::SEED_ACCENT, 'users');
        DB::table('settings')->where('key', 'channel_default_accent')->update(['updated_at' => DB::raw('created_at')]);
        Cache::flush();
        $written = [];
        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$written): void {
            $written[] = $event->key;
        });

        $worker = $this->consoleProcess('schedule:run');
        Setting::set('date_format', 'Y-m-d', 'localization');
        $this->assertTrue($worker->refresh(), 'and again before a job, after a change');

        $this->assertSame([], $written, 'no cache entry at all was written by the console process');
        $this->assertSame('New site name', config('app.name'));
        $this->assertSame(ChannelAccent::LOOK, config('site.channel_default_accent'), "the seed check still applies: the look's colour");
        foreach (['settings.__all_grouped', 'languages_all_collection', 'schema.has_languages_table', 'settings.webhooks', 'settings.maintenance'] as $key) {
            $this->assertFalse(Cache::has($key), "{$key} is not written by a console process");
        }

        // Only that part reads without writing: the same check elsewhere in the process keeps its cache entry.
        ChannelCustomizationSettings::accentIsInstallerSeed();
        $this->assertCount(1, $written);
        $this->assertStringEndsWith(ChannelCustomizationSettings::SEED_CACHE_KEY, $written[0]);

        // What the web keeps is what it reads (no query for the settings).
        Setting::getAllGrouped();
        DB::enableQueryLog();
        $this->assertNotNull(SiteSettingsSource::console());
        $this->assertSame([], array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], '"settings"')));
    }

    public function test_the_member_mails_of_the_scheduler_skip_a_deleted_member_and_still_reach_the_others(): void
    {
        // The first subscription belongs to a member who deleted the account (the queue has not removed it yet): 1.2.x
        // stopped at it ("notify() on null") and nobody after it got the mail.
        $ends = now()->addDays((int) config('plans.trial_period.email_reminder'))->format('Y-m-d 21:00:00');
        $gone = $this->trialMember($ends, 'gone_member');
        $gone->delete();
        $staying = $this->trialMember($ends, 'staying_member');

        $this->assertSame(0, Artisan::call('premium:trial_end'));
        $this->assertSame(0, Artisan::call('premium:card_expiration'));

        $this->assertCount(2, $this->sent, 'one trial mail and one card mail, both to the member who stays');
        $this->assertSame(['Your free trial ends soon', 'Your card expires soon'], array_column($this->sent, 'subject'));
        foreach ($this->sent as $mail) {
            $this->assertStringContainsString('staying_member', $mail['text']);
        }
        $this->assertNotNull($staying->fresh());
    }

    public function test_a_time_zone_php_does_not_know_is_left_out_and_the_rest_still_applies(): void
    {
        $this->saveSettings(['localization' => ['timezone' => 'Mars/Olympus_Mons', 'date_format' => 'd.m.Y']]);
        $zone = config('app.timezone');

        $this->consoleProcess('schedule:run');

        $this->assertSame($zone, config('app.timezone'));
        $this->assertSame('d.m.Y', config('site.date_format'), 'the settings after it still apply');

        // The web's path is the same one.
        config(['site.date_format' => 'M d, Y']);
        $this->app->getProvider(AppServiceProvider::class)->applySettings(SiteSettingsSource::web());
        $this->assertSame('d.m.Y', config('site.date_format'));
        $this->assertSame($zone, config('app.timezone'));
    }

    // ------------------------------------------------------------------ helpers

    private const OLD = [
        'email' => ['mail_mailer' => 'smtp', 'smtp_host' => 'smtp.old.example', 'smtp_port' => '2525', 'mail_from_address' => 'old@site.example', 'mail_from_name' => 'Old sender'],
        'general' => ['site_name' => 'Old site name'],
        'localization' => ['timezone' => 'UTC', 'date_format' => 'M d, Y'],
        'planlimits' => ['trial_email_reminder' => '7'],
    ];

    private const NEW = [
        'email' => ['mail_mailer' => 'smtp', 'smtp_host' => 'smtp.new.example', 'smtp_port' => '2526', 'mail_from_address' => 'news@site.example', 'mail_from_name' => 'New sender'],
        'general' => ['site_name' => 'New site name'],
        'localization' => ['timezone' => 'Pacific/Kiritimati', 'date_format' => 'd/m/Y'],
        'planlimits' => ['trial_email_reminder' => '3'],
    ];

    /** What the admin's save does: the rows, and the caches of the web forgotten. */
    private function saveSettings(array $groups): void
    {
        foreach ($groups as $group => $rows) {
            foreach ($rows as $key => $value) {
                Setting::set($key, $value, $group);
            }
        }
    }

    /** A console process starts with $command: what AppServiceProvider::boot() registers outside the tests. */
    private function consoleProcess(string $command): ConsoleSiteSettings
    {
        $provider = $this->app->getProvider(AppServiceProvider::class);
        $settings = $provider->consoleSettings();
        $provider->listenInConsole($settings);
        $settings->commandStarting($command);

        return $settings;
    }

    private function trialMember(string $trialEndsAt, string $username = 'trial_member'): User
    {
        $plan = Plan::query()->create(['name' => 'Monthly', 'price' => 500, 'duration' => 1]);
        $member = User::factory()->create(['language' => 'en', 'username' => $username]);
        // the card expires at the end of this month (the card reminder looks for that)
        DB::table('premiums')->insert(['stripe_status' => 'trialing', 'user_id' => $member->id, 'plan_id' => $plan->id, 'stripe_id' => 'sub_'.$username,
            'trial_ends_at' => $trialEndsAt, 'card_last4' => '4242', 'card_expired_at' => now()->endOfMonth()->toDateString(), 'payment_provider' => 'stripe',
            'created_at' => now(), 'updated_at' => now()]);

        return $member;
    }
}

/** The trial-end mail queued (the queue worker sends it): its date shows the time zone and the date format. */
class ConsoleProcessQueuedTrialEnd extends TrialEnd implements ShouldQueue
{
    use Queueable;
}
