<?php

namespace Tests\Feature;

use App\Http\Middleware\IsAdmin;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class AdminMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['site.admin_ip_whitelist' => '']);

        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('slug')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('avatar')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        Cache::flush();

        parent::tearDown();
    }

    public function test_admin_user_can_pass_admin_middleware(): void
    {
        $this->actingAs($this->userWithAdminFlag(true));

        $response = app(IsAdmin::class)->handle(
            Request::create('/admin/test', 'GET'),
            fn () => response('ok')
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }

    public function test_non_admin_user_gets_not_found_from_admin_middleware(): void
    {
        $this->actingAs($this->userWithAdminFlag(false));

        $this->expectException(NotFoundHttpException::class);

        app(IsAdmin::class)->handle(
            Request::create('/admin/test', 'GET'),
            fn () => response('ok')
        );
    }

    public function test_impersonated_non_admin_session_cannot_pass_admin_middleware(): void
    {
        $admin = $this->userWithAdminFlag(true, 'admin');
        $member = $this->userWithAdminFlag(false, 'member');

        $this->actingAs($member);
        session([
            'impersonated_by' => $admin->id,
            'impersonated_by_guard' => 'web',
        ]);

        $this->expectException(NotFoundHttpException::class);

        app(IsAdmin::class)->handle(
            Request::create('/admin/test', 'GET'),
            fn () => response('ok')
        );
    }

    private function userWithAdminFlag(bool $isAdmin, string $name = 'user'): User
    {
        return tap(new User(), function (User $user) use ($isAdmin, $name) {
            $user->forceFill([
                'username' => $name,
                'slug' => $name,
                'email' => $name . '@example.test',
                'password' => 'password',
                'is_admin' => $isAdmin,
                'email_verified_at' => now(),
            ]);
            $user->save();
        });
    }
}
