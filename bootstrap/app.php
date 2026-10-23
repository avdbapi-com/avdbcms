<?php

require_once dirname(__DIR__) . '/app/Support/Polyfills.php';

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The first thing we will do is create a new Laravel application instance
| which serves as the "glue" for all the components of Laravel, and is
| the IoC container for the system binding all of the various parts.
|
*/

$app = new Illuminate\Foundation\Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

/*
|--------------------------------------------------------------------------
| Tolerate Half-Installed Development Packages
|--------------------------------------------------------------------------
|
| Uploaded copies and release archives sometimes ship a vendor/ folder whose
| composer/installed.json still lists dev-only packages while the autoloader
| was dumped with --no-dev. Package discovery would then register provider
| classes that cannot be loaded and every page would fail right after the
| installer clears the caches. The resilient manifest skips those entries.
|
*/

$app->singleton(
    Illuminate\Foundation\PackageManifest::class,
    fn () => new App\Support\ResilientPackageManifest(
        new Illuminate\Filesystem\Filesystem,
        $app->basePath(),
        $app->getCachedPackagesPath()
    )
);

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
|
| Next, we need to bind some important interfaces into the container so
| we will be able to resolve them when needed. The kernels serve the
| incoming requests to this application from both the web and CLI.
|
*/

$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

/*
|--------------------------------------------------------------------------
| Return The Application
|--------------------------------------------------------------------------
|
| This script returns the application instance. The instance is given to
| the calling script so we can separate the building of the instances
| from the actual running of the application and sending responses.
|
*/

return $app;
