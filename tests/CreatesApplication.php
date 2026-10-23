<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use RuntimeException;
use Stringable;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        $environment = $app->environment();

        // Target checks documented here and mirrored in tests/README.md:
        // 1. PHPUnit must boot the Laravel app in the testing environment.
        // 2. The application database names avdbcms and avdbclip (older installs) are always forbidden.
        // 3. Non-sqlite databases must use an explicit test database suffix.
        if ($environment !== 'testing') {
            throw new RuntimeException("Refusing to run tests outside the testing environment. Current environment: {$environment}.");
        }

        $databaseName = $this->databaseName($database);

        if (in_array($databaseName, ['avdbcms', 'avdbclip'], true)) {
            throw new RuntimeException("Refusing to run tests against the application database {$databaseName}.");
        }

        if ($connection !== 'sqlite' && ! str_ends_with($databaseName, '_test')) {
            throw new RuntimeException("Refusing to run {$connection} tests against non-test database: {$databaseName}.");
        }

        return $app;
    }

    private function databaseName(mixed $database): string
    {
        if ($database instanceof Stringable) {
            $database = (string) $database;
        }

        return trim((string) $database);
    }
}
