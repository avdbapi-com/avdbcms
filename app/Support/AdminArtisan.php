<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Facades\Facade;
use ReflectionProperty;
use Throwable;

/**
 * Tools > Commands runs an Artisan command inside a web request the way the command line runs it (R1BR-01, also 1.2.2).
 *
 * config:cache, route:cache, event:cache and optimize (which runs them) build the configuration or the routes in a
 * fresh application of their own. Inside a web request that application took itself for a web request too, so
 * AppServiceProvider applied the admin's settings to it, and config:cache wrote those database values into
 * bootstrap/cache/config.php: the SMTP password and the API keys sat in a cached PHP file, and the cached base hid a
 * later change of the admin wherever the runtime does not apply the settings again. Here the fresh applications know
 * they are a console process (APP_RUNNING_IN_CONSOLE, Laravel's own switch, set only while the command runs), like
 * `php artisan config:cache` on the server: a console process applies the admin's settings only for the site's work
 * (App\Support\ConsoleSiteSettings), never for config:* or optimize*. The cached file holds the configuration files
 * only, and every request, and every console process that does the site's work, applies the settings on top of it.
 *
 * A fresh application also makes itself the global one (its constructor) and points the facades and Eloquent at itself
 * (its bootstrapping), so the rest of the request answered from an application that never started this request's
 * session: the page lost the command's output ("Waiting for a command..." after config:cache or optimize). The
 * request's own application is put back after every command.
 *
 * Its bootstrapping also sets what PHP keeps for the whole process: the default time zone (the configuration file's,
 * not the admin's Time zone this request applied: the "Command run" row of Admin actions was stamped hours off), the
 * error reporting and the handlers it adds (and the application they report to), Carbon's language (the
 * configuration's, not the admin's), the paginator's request and Eloquent's booted models. All of it is put back as it
 * was before the command (judge R2CD-RV-01).
 */
final class AdminArtisan
{
    /** Laravel reads it before PHP_SAPI to tell a console process (Application::runningInConsole()). */
    public const CONSOLE_SWITCH = 'APP_RUNNING_IN_CONSOLE';

    /**
     * Statics of the framework that a fresh application sets for itself while it bootstraps: the application the error
     * handlers report to, the paginator's request, Eloquent's booted models (DatabaseServiceProvider::register() clears
     * them, so the request's models would boot again and add their listeners twice).
     *
     * @var array<class-string, list<string>>
     */
    private const STATICS = [
        HandleExceptions::class => ['app'],
        AbstractPaginator::class => ['currentPathResolver', 'currentPageResolver', 'queryStringResolver', 'viewFactoryResolver'],
        AbstractCursorPaginator::class => ['currentCursorResolver'],
        Model::class => ['booted', 'bootedCallbacks', 'globalScopes', 'traitInitializers'],
    ];

    /** The classes whose language Carbon's service provider sets (Carbon\Laravel\ServiceProvider::updateLocale()). */
    private const CARBON = [Carbon::class, CarbonImmutable::class, CarbonPeriod::class, CarbonInterval::class, \Illuminate\Support\Carbon::class];

    /**
     * Runs the command on the request's own console kernel (never through a facade a fresh application could have
     * taken over): its exit status and its output.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{status: int, output: string}
     */
    public static function call(string $command, array $parameters = []): array
    {
        $app = Container::getInstance();
        $kernel = $app->make(ConsoleKernel::class);
        $process = self::processState();
        $before = self::switchOn();

        try {
            $status = $kernel->call($command, $parameters);
            $output = $kernel->output();
        } finally {
            self::switchBack($before);
            self::restore($app);
            self::restoreProcessState($process);
        }

        return ['status' => (int) $status, 'output' => (string) $output];
    }

    /**
     * What PHP and the framework keep for the whole process, as this request has it before the command.
     *
     * @return array{timezone: string, encoding: string|false, reporting: int, display: string|false, error: mixed, exception: mixed, carbon: array<class-string, string>, statics: list<array{0: ReflectionProperty, 1: mixed}>}
     */
    private static function processState(): array
    {
        $carbon = [];

        foreach (self::CARBON as $class) {
            try {
                if (class_exists($class)) {
                    $carbon[$class] = (string) $class::getLocale();
                }
            } catch (Throwable) {
                // a class without a static language: nothing to keep
            }
        }

        $statics = [];

        foreach (self::STATICS as $class => $properties) {
            foreach ($properties as $name) {
                try {
                    if (class_exists($class) && property_exists($class, $name)) {
                        $property = new ReflectionProperty($class, $name);

                        if ($property->isStatic()) {
                            $statics[] = [$property, $property->getValue()];
                        }
                    }
                } catch (Throwable) {
                    // another version of the framework: that static is not there
                }
            }
        }

        return [
            'timezone' => date_default_timezone_get(),
            'encoding' => function_exists('mb_internal_encoding') ? mb_internal_encoding() : false,
            'reporting' => error_reporting(),
            'display' => ini_get('display_errors'),
            'error' => self::errorHandler(),
            'exception' => self::exceptionHandler(),
            'carbon' => $carbon,
            'statics' => $statics,
        ];
    }

    /**
     * Puts back what the command's fresh applications changed for the process: the request's time zone first (the rest
     * of the request writes its times, the "Command run" row too, in the site's zone), then the rest.
     *
     * @param  array{timezone: string, encoding: string|false, reporting: int, display: string|false, error: mixed, exception: mixed, carbon: array<class-string, string>, statics: list<array{0: ReflectionProperty, 1: mixed}>}  $state
     */
    private static function restoreProcessState(array $state): void
    {
        if (date_default_timezone_get() !== $state['timezone']) {
            @date_default_timezone_set($state['timezone']);
        }

        if ($state['encoding'] !== false && mb_internal_encoding() !== $state['encoding']) {
            @mb_internal_encoding($state['encoding']);
        }

        if (error_reporting() !== $state['reporting']) {
            error_reporting($state['reporting']);
        }

        if ($state['display'] !== false && ini_get('display_errors') !== $state['display']) {
            @ini_set('display_errors', $state['display']);
        }

        // the handlers the fresh applications added go (they report to an application nobody uses any more)
        for ($i = 0; $i < 32 && self::errorHandler() !== $state['error']; $i++) {
            restore_error_handler();
        }

        if (self::errorHandler() !== $state['error'] && is_callable($state['error'])) {
            set_error_handler($state['error']);
        }

        for ($i = 0; $i < 32 && self::exceptionHandler() !== $state['exception']; $i++) {
            restore_exception_handler();
        }

        if (self::exceptionHandler() !== $state['exception'] && is_callable($state['exception'])) {
            set_exception_handler($state['exception']);
        }

        foreach ($state['statics'] as [$property, $value]) {
            try {
                if ($property->getValue() !== $value) {
                    $property->setValue(null, $value);
                }
            } catch (Throwable) {
                // left as the command made it: nothing else depends on it in this request
            }
        }

        foreach ($state['carbon'] as $class => $locale) {
            try {
                if ($class::getLocale() !== $locale) {
                    $class::setLocale($locale);
                }
            } catch (Throwable) {
            }
        }
    }

    /** The error handler PHP calls now (set_error_handler() answers the one before it). */
    private static function errorHandler(): mixed
    {
        $handler = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $handler;
    }

    /** The exception handler PHP calls now. */
    private static function exceptionHandler(): mixed
    {
        $handler = set_exception_handler(static function (Throwable $e): void {
        });
        restore_exception_handler();

        return $handler;
    }

    /**
     * APP_RUNNING_IN_CONSOLE=true for the applications the command creates. The request's application has read whether
     * it runs in a console long before (it keeps the answer), so only the fresh ones see it.
     *
     * @return array{server: ?string, env: ?string, putenv: string|false}
     */
    private static function switchOn(): array
    {
        $before = [
            'server' => array_key_exists(self::CONSOLE_SWITCH, $_SERVER) ? (string) $_SERVER[self::CONSOLE_SWITCH] : null,
            'env' => array_key_exists(self::CONSOLE_SWITCH, $_ENV) ? (string) $_ENV[self::CONSOLE_SWITCH] : null,
            'putenv' => getenv(self::CONSOLE_SWITCH),
        ];

        $_SERVER[self::CONSOLE_SWITCH] = 'true';
        $_ENV[self::CONSOLE_SWITCH] = 'true';
        self::putenv(self::CONSOLE_SWITCH.'=true');

        return $before;
    }

    /** @param  array{server: ?string, env: ?string, putenv: string|false}  $before */
    private static function switchBack(array $before): void
    {
        if ($before['server'] === null) {
            unset($_SERVER[self::CONSOLE_SWITCH]);
        } else {
            $_SERVER[self::CONSOLE_SWITCH] = $before['server'];
        }

        if ($before['env'] === null) {
            unset($_ENV[self::CONSOLE_SWITCH]);
        } else {
            $_ENV[self::CONSOLE_SWITCH] = $before['env'];
        }

        self::putenv($before['putenv'] === false ? self::CONSOLE_SWITCH : self::CONSOLE_SWITCH.'='.$before['putenv']);
    }

    /** putenv() is disabled on some hosts: $_SERVER and $_ENV, which Laravel reads first, still carry the switch. */
    private static function putenv(string $assignment): void
    {
        if (! function_exists('putenv')) {
            return;
        }

        try {
            @putenv($assignment);
        } catch (Throwable) {
        }
    }

    /** The request's application as the global one again: the container, the facades and Eloquent. */
    private static function restore(Container $app): void
    {
        if (Container::getInstance() !== $app) {
            Container::setInstance($app);
        }

        if (! $app instanceof Application) {
            return;
        }

        if (Facade::getFacadeApplication() !== $app) {
            Facade::setFacadeApplication($app);
            Facade::clearResolvedInstances();
        }

        try {
            if ($app->bound('db')) {
                Model::setConnectionResolver($app['db']);
            }

            if ($app->bound('events')) {
                Model::setEventDispatcher($app['events']);
            }
        } catch (Throwable) {
            // the request's own bindings are there; nothing else to put back
        }
    }
}
