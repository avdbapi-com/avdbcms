<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminMaintenanceRouteMetadataTest extends TestCase
{
    #[DataProvider('destructiveMaintenanceRoutes')]
    public function test_destructive_admin_maintenance_routes_are_admin_only_with_expected_methods(string $name, string $method): void
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertNotNull($route, "Route [{$name}] is not registered.");
        $this->assertContains('admin', $route->gatherMiddleware());
        $this->assertContains($method, $route->methods());
        $this->assertNotContains('GET', array_diff($route->methods(), ['HEAD']));
    }

    public static function destructiveMaintenanceRoutes(): array
    {
        return [
            'database migrate' => ['admin.database.migrate', 'POST'],
            'database truncate' => ['admin.database.truncate', 'DELETE'],
            'backup delete' => ['admin.backups.delete', 'DELETE'],
            'system clear cache' => ['admin.system.clear', 'POST'],
            'audit clear' => ['admin.audit.clear', 'POST'],
        ];
    }
}
