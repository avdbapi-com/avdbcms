<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\SettingsController;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

class AdminSettingsRouteMetadataTest extends TestCase
{
    public function test_every_admin_settings_group_has_route_metadata(): void
    {
        $schema = $this->settingsSchema();

        $indexRoute = Route::getRoutes()->getByName('admin.settings.index');
        $updateRoute = Route::getRoutes()->getByName('admin.settings.update.group');

        $this->assertNotNull($indexRoute);
        $this->assertNotNull($updateRoute);
        $this->assertContains('admin', $indexRoute->gatherMiddleware());
        $this->assertContains('admin', $updateRoute->gatherMiddleware());
        $this->assertContains('PUT', $updateRoute->methods());

        foreach (array_keys($schema) as $group) {
            $this->assertSame(
                url('/admin/settings/update/' . $group),
                route('admin.settings.update.group', $group)
            );
        }
    }

    private function settingsSchema(): array
    {
        $method = new ReflectionMethod(SettingsController::class, 'settingsSchema');
        $method->setAccessible(true);

        return $method->invoke(app(SettingsController::class));
    }
}
