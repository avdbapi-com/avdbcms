<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminRouteSecurityTest extends TestCase
{
    public function test_admin_mutation_routes_are_inside_web_csrf_group(): void
    {
        $route = Route::getRoutes()->getByName('admin.settings.update.group');

        $this->assertNotNull($route);
        $this->assertContains('admin', $route->gatherMiddleware());
        $this->assertContains(VerifyCsrfToken::class, app('router')->getMiddlewareGroups()['web']);
    }

    public function test_destructive_admin_routes_are_not_get_and_remain_admin_csrf_protected(): void
    {
        $destructiveNames = [
            'delete',
            'destroy',
            'clear',
            'flush',
            'migrate',
            'truncate',
            'ban',
            'unban',
            'toggle',
            'retry',
            'revoke',
            'verify',
            'unverify',
            'activate',
            'cancel',
            'block',
            'set-active',
            'set-default',
            'delete-unused',
            'merge',
            'mark-read',
        ];

        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.'))
            ->filter(function ($route) use ($destructiveNames) {
                $routeName = strtolower((string) $route->getName());
                $name = str($routeName)->afterLast('.')->toString();
                $uri = trim(strtolower($route->uri()), '/');
                $uriTail = str($uri)->afterLast('/')->toString();

                foreach ($destructiveNames as $needle) {
                    $uriTailMatches = $uriTail === $needle
                        || str_starts_with($uriTail, $needle . '-')
                        || str_ends_with($uriTail, '-' . $needle);

                    if ($name === $needle || str_contains($name, $needle) || $uriTailMatches) {
                        return true;
                    }
                }

                return false;
            });

        $this->assertNotEmpty($routes, 'Expected to find destructive admin routes to inspect.');

        foreach ($routes as $route) {
            $methods = array_diff($route->methods(), ['HEAD']);
            $middleware = $route->gatherMiddleware();

            $this->assertNotContains('GET', $methods, "Destructive admin route {$route->getName()} must not allow GET.");
            $this->assertContains('admin', $middleware, "Destructive admin route {$route->getName()} must use admin middleware.");
        }

        $this->assertContains(VerifyCsrfToken::class, app('router')->getMiddlewareGroups()['web']);
    }
}
