<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Models\View;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ApiStatefulCsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('sanctum.stateful', ['localhost', 'localhost:8080']);
        Config::set('sanctum.middleware.validate_csrf_token', StrictSanctumCsrfMiddleware::class);
    }

    public function test_stateful_api_mutation_rejects_browser_session_without_csrf_token(): void
    {
        $viewer = User::factory()->create();
        $view = $this->createHistoryView($viewer);

        $this->actingAs($viewer)
            ->withHeader('Origin', 'http://localhost')
            ->postJson(route('me.clear-all'))
            ->assertStatus(419);

        $this->assertDatabaseHas('views', [
            'id' => $view->id,
            'deleted_at' => null,
        ]);
    }

    public function test_stateful_api_mutation_accepts_matching_csrf_token(): void
    {
        $viewer = User::factory()->create();
        $view = $this->createHistoryView($viewer);
        $token = 'stateful-api-csrf-token';

        $this->actingAs($viewer)
            ->withSession(['_token' => $token])
            ->withHeaders([
                'Origin' => 'http://localhost',
                'X-CSRF-TOKEN' => $token,
            ])
            ->postJson(route('me.clear-all'))
            ->assertNoContent();

        $this->assertSoftDeleted('views', ['id' => $view->id]);
    }

    public function test_bearer_token_api_mutation_does_not_require_browser_csrf_header(): void
    {
        $viewer = User::factory()->create();
        $view = $this->createHistoryView($viewer);
        $token = $viewer->createToken('csrf-boundary-test')->plainTextToken;

        $this->withHeader('Origin', 'https://attacker.example')
            ->withToken($token)
            ->postJson(route('me.clear-all'))
            ->assertNoContent();

        $this->assertSoftDeleted('views', ['id' => $view->id]);
    }

    private function createHistoryView(User $viewer): View
    {
        $video = Video::factory()
            ->for(User::factory(), 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create();

        return View::query()->create([
            'video_id' => $video->id,
            'user_id' => $viewer->id,
            'ip' => '127.0.0.1',
            'view_at' => now(),
        ]);
    }
}

class StrictSanctumCsrfMiddleware extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}
