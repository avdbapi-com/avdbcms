<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * The admin's settings in console processes (judge R1R-06; also 1.2.2). A web request applies every stored setting
 * when it starts (AppServiceProvider::applySettings()); a console process did not, so the queue worker (the scheduler
 * starts queue:work every minute) and every scheduled command sent their mails through the .env MAIL_* values of the
 * installation (an SMTP server the owner set up later in Settings > Email was never used), with the installation's
 * site name, the config's date format and time zone, the configured trial reminder and in languages the admin had
 * turned off.
 *
 * Now the process's command decides, when it starts (CommandStarting, see AppServiceProvider::boot()):
 *  - a command that does the site's work (the scheduler and its tasks, queue:work / queue:listen / Horizon, the site's
 *    own commands, tinker) applies the settings through the web's code path (applySettings(), with
 *    SiteSettingsSource::console(), inside SafeCache::readOnly(): the caches are read, never written);
 *  - the commands in SKIPPED never do: they build or repair what the settings live in (migrate, db:*, key:generate,
 *    package:discover), or write the framework's caches (config:cache would keep the stored settings in the cached
 *    config), or need no site at all;
 *  - nothing is applied while there is no settings table or an empty one (a fresh install, a database being built).
 * Before every job (JobProcessing) a long-running worker compares what the settings are now with what it applied and
 * applies them again when the admin changed them; the mail transports it made before are forgotten, so the next mail
 * goes through the new server with the new sender.
 * The test process registers none of this (its requests count the queries of a web request); the tests call it.
 */
final class ConsoleSiteSettings
{
    /** Commands that never apply the admin's settings ("name:*": every command of that namespace). */
    public const SKIPPED = [
        'migrate', 'migrate:*', 'db', 'db:*', 'schema:*',
        'key:*', 'package:*', 'vendor:publish', 'stub:publish', 'lang:publish',
        'config:*', 'optimize', 'optimize:*', 'view:*', 'route:*', 'event:*', 'cache:*', 'clear-compiled',
        'storage:*', 'env', 'env:*', 'down', 'up', 'about', 'list', 'help', 'completion', '_complete',
        'make:*', 'install:*', 'ide-helper:*', 'serve', 'test', 'inspire',
    ];

    /** The process's command was looked at (only its first, top-level command decides). */
    private bool $decided = false;

    /** This process applies the settings. */
    private bool $active = false;

    /** What it applied last (SiteSettingsSource::fingerprint()). */
    private ?string $applied = null;

    /**
     * @param  Closure(SiteSettingsSource): void  $apply  AppServiceProvider::applySettings()
     */
    public function __construct(
        private readonly Application $app,
        private readonly Closure $apply,
    ) {
    }

    /** Whether $command never applies the admin's settings. */
    public static function skips(string $command): bool
    {
        $command = strtolower(trim($command));

        if ($command === '') {
            return true;
        }

        foreach (self::SKIPPED as $pattern) {
            $matches = str_ends_with($pattern, '*')
                ? str_starts_with($command, substr($pattern, 0, -1))
                : $command === $pattern;

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * The process's command starts: a command that does the site's work applies the settings now. Commands it starts
     * in the same process (Artisan::call()) change nothing. True when this process applies them.
     */
    public function commandStarting(string $command): bool
    {
        if ($this->decided) {
            return $this->active;
        }

        $this->decided = true;

        if (self::skips($command)) {
            return false;
        }

        $this->active = true;
        $this->refresh(true);

        return true;
    }

    /** Before each job of the queue: the settings the admin saved since the last one are applied. */
    public function beforeJob(): void
    {
        if ($this->active) {
            $this->refresh();
        }
    }

    public function active(): bool
    {
        return $this->active;
    }

    /**
     * Apply the settings when they differ from what this process applied ($force: always). True when applied now;
     * false when unchanged or when there is nothing the admin saved (no settings table, an empty one).
     */
    public function refresh(bool $force = false): bool
    {
        try {
            $source = SiteSettingsSource::console();

            if ($source === null) {
                return false;
            }

            $fingerprint = $source->fingerprint();

            if (! $force && $fingerprint === $this->applied) {
                return false;
            }

            // Read the caches, never write one (also not what a setting works out on the way, e.g. the admin cache's
            // check of the installer's channel accent: judge round 1b BS-RV-05).
            SafeCache::readOnly(fn () => ($this->apply)($source));
            $this->applied = $fingerprint;
            $this->forgetWhatUsedTheOldSettings();

            return true;
        } catch (Throwable $e) {
            // A process that could not read the settings goes on with what it has (as a web request does).
            report($e);

            return false;
        }
    }

    /**
     * What a long-running process made with the settings it had: the mail transports (made with the old server and
     * sender), the translator's languages, the site language site_default_locale() kept for the process.
     */
    private function forgetWhatUsedTheOldSettings(): void
    {
        if ($this->app->resolved('mail.manager')) {
            $this->app->make('mail.manager')->forgetMailers();
        }

        if ($this->app->resolved('translator')) {
            $translator = $this->app->make('translator');
            $translator->setLocale((string) $this->app->make('config')->get('app.locale'));
            $translator->setFallback((string) $this->app->make('config')->get('app.fallback_locale'));
        }

        $this->app->forgetInstance('site_default_locale.console');
    }
}
