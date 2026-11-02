<?php

namespace Tests\Feature;

use App\Http\Middleware\RequirePassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The member scaffold (member design 9.1 step 4): the routes of the rebuilt member pages are registered
 * (routes/user.php), and the password.confirm middleware that one of
 * them sits behind hands the route's own answer back, whatever it is. Declared ': RedirectResponse', the middleware
 * turned every other answer (the stub's 404, a JSON validation error) into a TypeError, i.e. a 500.
 */
class MemberScaffoldRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_member_routes_are_registered_with_their_middleware(): void
    {
        $expected = [
            'user.videos.mass-visibility' => ['POST', 'profile/videos/mass-visibility', ['auth']],
            'user.comments.replies' => ['GET', 'profile/comments/{comment}/replies', ['auth']],
            'user.devices.sign-out-others' => ['POST', 'profile/devices/sign-out-others', ['auth', 'password.confirm']],
            // its limit (3 in 10 minutes) is the named "member-export" since round 2a R2A-030
            'user.export' => ['GET', 'profile/export', ['auth', 'throttle:member-export']],
            'user.notifications.index' => ['GET', 'profile/notifications', ['auth']],
            'user.notifications.settings' => ['PUT', 'profile/notifications/settings', ['auth']],
            'pages.library' => ['GET', 'library', ['auth']],
        ];

        foreach ($expected as $name => [$method, $uri, $middleware]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "route {$name} is missing");
            $this->assertContains($method, $route->methods(), $name);
            $this->assertSame($uri, $route->uri(), $name);
            foreach ($middleware as $alias) {
                $this->assertContains($alias, $route->gatherMiddleware(), "{$name} lost {$alias}");
            }
        }
    }

    public function test_password_confirm_hands_back_whatever_the_route_answers(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $request = Request::create('/profile/devices/sign-out-others', 'POST', ['current_password' => 'password']);

        foreach ([response('not here', 404), response()->json(['done' => true]), redirect('/profile/edit')] as $answer) {
            $this->assertSame($answer, (new RequirePassword())->handle($request, fn () => $answer));
        }
    }

    public function test_sign_out_other_devices_never_fails_behind_the_password_check(): void
    {
        $user = User::factory()->create();

        // the right password reaches the route's own answer (a stub's 404 or the built page's answer)
        $right = $this->actingAs($user)->fromRoute('user.edit')
            ->post(route('user.devices.sign-out-others'), ['current_password' => 'password']);
        $this->assertLessThan(500, $right->getStatusCode(), 'the right password must never end in a server error');
        $this->assertFalse($this->refusedByPasswordCheck($right), 'the right password was refused');

        // a wrong or missing password is sent back, as on the other password-confirmed routes
        foreach ([['current_password' => 'not-the-password'], []] as $payload) {
            $wrong = $this->actingAs($user)->fromRoute('user.edit')
                ->post(route('user.devices.sign-out-others'), $payload);
            $wrong->assertRedirect(route('user.edit'));
            $this->assertTrue($this->refusedByPasswordCheck($wrong), 'a wrong password was not refused');
        }
    }

    public function test_a_json_validation_error_behind_the_password_check_is_a_422_not_a_500(): void
    {
        $user = User::factory()->create();
        $invalid = ['current_password' => 'password', 'new_password' => 'x', 'new_password_confirmation' => 'y'];

        $this->actingAs($user)
            ->postJson(route('user.update.password'), $invalid)
            ->assertStatus(422)
            ->assertJsonValidationErrors('new_password');

        // the site's own form keeps its answer: back to Account settings with the field errors
        $this->actingAs($user)->fromRoute('user.edit')
            ->post(route('user.update.password'), $invalid)
            ->assertRedirect(route('user.edit'))
            ->assertSessionHasErrors('new_password');
    }

    /** The middleware refuses with a flash message today; a field error (an inline message) counts as well. */
    private function refusedByPasswordCheck(TestResponse $response): bool
    {
        $session = $response->baseResponse->isRedirect() ? session() : null;

        return $session !== null && ($session->has('error') || $session->has('errors'));
    }
}
