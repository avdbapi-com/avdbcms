<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * database/factories/UserFactory.php ships in the release, and fakerphp/faker is a production dependency, so on a site
 * `php artisan tinker` with User::factory()->admin()->create() worked. Up to 1.2.1 that account had the password
 * "password". From 1.2.2 on the factory's known password exists only inside the test suite (APP_ENV=testing).
 */
class UserFactoryPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_test_suite_keeps_the_factory_password(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Hash::check('password', (string) DB::table('users')->where('id', $user->id)->value('password')));
    }

    public function test_a_factory_run_on_a_site_gives_no_known_password(): void
    {
        $environment = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->assertFalse($this->app->runningUnitTests());
            $made = User::factory()->make();
            $admin = User::factory()->admin()->create();
        } finally {
            $this->app['env'] = $environment;
        }

        $stored = (string) DB::table('users')->where('id', $admin->id)->value('password');
        $this->assertTrue(Hash::isHashed($stored));
        $this->assertFalse(Hash::check('password', $stored), 'an admin made by the factory on a site');
        $this->assertFalse(Hash::check('password', (string) $made->getAttributes()['password']));
    }
}
