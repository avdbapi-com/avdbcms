<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Http\Controllers\Api\Private\VideoController;
use App\Http\Middleware\CheckMaintenanceMode;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Round 2a R2A-063: maintenance mode closes the API since 1.2.3, and an upload that is already running must finish, so
 * CheckMaintenanceMode opened the upload routes - by their controller: every route of Api\Private\VideoController, so
 * DELETE /api/videos/{uuid} stayed open too (a member or an app could delete videos while every other API call answered
 * 503). The upload routes are now opened by name (CheckMaintenanceMode::UPLOAD_ROUTES, unchanged otherwise); deleting a
 * video waits for the end of maintenance like everything else. Nothing here reaches Upload18 (stray requests fail).
 */
class MaintenanceUploadRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'site.maintenance_mode' => true,
            'site.maintenance_allow_admins' => true,
            'site.maintenance_retry_after' => 3600,
            'site.maintenance_message' => CheckMaintenanceMode::DEFAULT_MESSAGE,
            'site.default_locale' => 'en',
            'cache.limiter' => 'array',
        ]);
        Cache::flush();
    }

    public function test_deleting_a_video_waits_for_the_end_of_maintenance(): void
    {
        $member = User::factory()->create();
        $video = Video::factory()->for($member)->withStatus(VideoStatus::PUBLIC)->create();

        $this->actingAs($member, 'sanctum')->deleteJson('/api/videos/'.$video->uuid)
            ->assertStatus(503)
            ->assertHeader('Retry-After', '3600')
            ->assertJson(['maintenance' => true, 'message' => __('We are updating the site. Try again soon.')]);
        $this->assertNotNull(Video::query()->find($video->id), 'the video is still there');

        // after maintenance the same request deletes it, as before
        config(['site.maintenance_mode' => false]);
        $this->actingAs($member, 'sanctum')->deleteJson('/api/videos/'.$video->uuid)->assertSuccessful();
        $this->assertNull(Video::query()->find($video->id));
    }

    public function test_an_upload_that_is_running_still_passes(): void
    {
        $member = User::factory()->create();
        $draft = Video::factory()->for($member)->withStatus(VideoStatus::DRAFT)->create();

        // Each upload route answers as it does without maintenance (empty requests: refused by their own checks, nothing
        // is uploaded and nothing reaches Upload18), never with the maintenance answer.
        foreach ([
            ['POST', '/api/videos/upload18/pending'],
            ['POST', '/api/videos/upload18/chunk'],
            ['POST', '/api/videos/upload18/complete'],
            ['GET', '/api/videos/upload18/status?uploadId=missing'],
            ['GET', '/api/videos/'.$draft->id.'/create-status'],
        ] as [$method, $uri]) {
            $open = $this->actingAs($member, 'sanctum')->json($method, $uri, []);
            config(['site.maintenance_mode' => false]);
            $closedSite = $this->actingAs($member, 'sanctum')->json($method, $uri, []);
            config(['site.maintenance_mode' => true]);

            $this->assertNotSame(503, $open->getStatusCode(), $method.' '.$uri);
            $this->assertNotSame(true, $open->json('maintenance'), $method.' '.$uri);
            $this->assertSame($closedSite->getStatusCode(), $open->getStatusCode(), $method.' '.$uri.': the same answer as without maintenance');
        }
    }

    public function test_only_the_upload_routes_of_the_video_controller_are_open(): void
    {
        $middleware = new CheckMaintenanceMode();
        $alwaysOpen = new ReflectionMethod($middleware, 'alwaysOpen');
        $seen = [];

        foreach (Route::getRoutes() as $route) {
            try {
                $controller = $route->getControllerClass();
            } catch (\Throwable) {
                $controller = null;
            }

            if ($controller !== VideoController::class) {
                continue;
            }

            $request = Request::create('/'.str_replace(['{video}'], ['1'], $route->uri()), $route->methods()[0]);
            $request->setRouteResolver(fn () => $route);
            $open = $alwaysOpen->invoke($middleware, $request);
            $seen[$route->getName()] = $open;

            $this->assertSame(in_array($route->getName(), CheckMaintenanceMode::UPLOAD_ROUTES, true), $open, (string) $route->getName());
        }

        // every listed name is a route of that controller (a renamed route would close an upload silently)
        foreach (CheckMaintenanceMode::UPLOAD_ROUTES as $name) {
            $this->assertArrayHasKey($name, $seen, $name.' is a route of the upload controller');
            $this->assertTrue($seen[$name], $name);
        }

        $this->assertFalse($seen['videos.delete'], 'deleting a video is not part of an upload');
    }
}
