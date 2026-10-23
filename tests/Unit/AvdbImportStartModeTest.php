<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\AvdbImportController;
use ReflectionClass;
use Tests\TestCase;

class AvdbImportStartModeTest extends TestCase
{
    public function test_auto_mode_does_not_pick_laravel_queue_without_explicit_request(): void
    {
        $this->withImportMode('auto', function (): void {
            config(['queue.default' => 'database']);

            $this->assertContains($this->resolveMode(), ['process', 'inline']);
        });
    }

    public function test_auto_mode_avoids_sync_queue_driver(): void
    {
        $this->withImportMode('auto', function (): void {
            config(['queue.default' => 'sync']);

            $this->assertContains($this->resolveMode(), ['process', 'inline']);
        });
    }

    public function test_auto_mode_avoids_null_queue_driver(): void
    {
        $this->withImportMode('auto', function (): void {
            config(['queue.default' => 'null']);

            $this->assertContains($this->resolveMode(), ['process', 'inline']);
        });
    }

    public function test_explicit_queue_mode_still_uses_laravel_queue(): void
    {
        $this->withImportMode('queue', function (): void {
            config(['queue.default' => 'database']);

            $this->assertSame('queue', $this->resolveMode());
        });
    }

    private function resolveMode(): string
    {
        $controller = new AvdbImportController;
        $method = (new ReflectionClass($controller))->getMethod('resolveBackgroundImportMode');
        $method->setAccessible(true);

        return $method->invoke($controller);
    }

    private function withImportMode(string $mode, callable $callback): void
    {
        $previousEnv = $_ENV['AVDB_IMPORT_MODE'] ?? null;
        $previousServer = $_SERVER['AVDB_IMPORT_MODE'] ?? null;

        $_ENV['AVDB_IMPORT_MODE'] = $mode;
        unset($_SERVER['AVDB_IMPORT_MODE']);

        try {
            $callback();
        } finally {
            if ($previousEnv === null) {
                unset($_ENV['AVDB_IMPORT_MODE']);
            } else {
                $_ENV['AVDB_IMPORT_MODE'] = $previousEnv;
            }

            if ($previousServer === null) {
                unset($_SERVER['AVDB_IMPORT_MODE']);
            } else {
                $_SERVER['AVDB_IMPORT_MODE'] = $previousServer;
            }
        }
    }
}
