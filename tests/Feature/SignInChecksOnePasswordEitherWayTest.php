<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PasswordCheck;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Round 3 R3-021: both sign-ins (the sign-in page and the apps' /api/login) checked a password only when an account had
 * the name or address, so a wrong password for an existing account answered one password check later than anything
 * typed for an address without an account (+66 ms at the shipped cost, 20 known against 20 unknown, p < 0.001): the time
 * told whether an address has an account. Now each sign-in does exactly one check of the site's cost either way
 * (App\Support\PasswordCheck: the account's hash, or a fixed hash of the site's driver and cost), with the same answer;
 * and a password hashed with another cost is hashed again with the site's at the next sign-in (as Laravel's own sign-in
 * does), so an account costs the same one check as an address without one.
 */
class SignInChecksOnePasswordEitherWayTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> the hashes the sign-in checked a password against */
    private array $checked = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.captcha_provider' => 'none',
            'site.captcha_on_login' => false,
            'site.login_rate_limit' => 1000,
            'site.default_locale' => 'en',
            'cache.limiter' => 'array',
        ]);
        Cache::flush();
    }

    public function test_the_sign_in_page_checks_one_password_of_the_sites_cost_for_every_kind_of_name(): void
    {
        User::factory()->create(['username' => 'known_member', 'email' => 'known@example.test', 'password' => 'the-right-password']);
        $social = User::factory()->create(['username' => 'social_member', 'email' => 'social@example.test']);
        DB::table('users')->where('id', $social->id)->update(['password' => '']);
        $this->countChecks();

        $answers = [];
        foreach ([
            'an address without an account' => 'nobody@example.test',
            'a name without an account' => 'nobody_at_all',
            'an account, wrong password' => 'known@example.test',
            'its name, wrong password' => 'known_member',
            'an account without a password' => 'social@example.test',
        ] as $case => $name) {
            $this->checked = [];
            $this->app['auth']->forgetGuards();
            $response = $this->from('/login')->post('/login', ['username' => $name, 'password' => 'a-wrong-password', 'remember' => '1']);

            $this->assertCount(1, $this->checked, $case.': exactly one password check');
            $this->assertSame($this->siteCost(), $this->cost($this->checked[0]), $case.': of the site\'s cost');
            $this->assertGuest();
            $answers[$case] = [
                $response->getStatusCode(),
                $response->headers->get('Location'),
                session('error'),
                session('auth_failed'),
                session('_old_input.username') === $name,
                session('_old_input.remember'),
            ];
        }

        // the same answer for every one of them
        $this->assertCount(1, array_unique(array_map('serialize', $answers)), json_encode($answers));
        $this->assertSame([302, url('/login'), 'The username or password is wrong.', true, true, '1'], reset($answers));
    }

    public function test_the_apps_sign_in_checks_one_password_of_the_sites_cost_for_every_kind_of_name(): void
    {
        User::factory()->create(['username' => 'known_member', 'email' => 'known@example.test', 'password' => 'the-right-password']);
        $this->countChecks();

        $answers = [];
        foreach (['nobody@example.test', 'nobody_at_all', 'known@example.test', 'known_member'] as $name) {
            $this->checked = [];
            $response = $this->postJson('/api/login', ['username' => $name, 'password' => 'a-wrong-password', 'device_name' => 'phone']);

            $this->assertCount(1, $this->checked, $name.': exactly one password check');
            $this->assertSame($this->siteCost(), $this->cost($this->checked[0]), $name.': of the site\'s cost');
            $answers[$name] = [$response->getStatusCode(), $response->getContent()];
        }

        $this->assertCount(1, array_unique(array_map('serialize', $answers)), json_encode($answers));
        $this->assertSame(401, reset($answers)[0]);
        $this->assertSame(0, DB::table('personal_access_tokens')->count(), 'no token for a wrong password');
    }

    public function test_the_right_password_still_signs_in_on_both_and_a_suspended_account_is_still_refused(): void
    {
        $member = User::factory()->create(['email' => 'member@example.test', 'password' => 'the-right-password']);
        User::factory()->create(['email' => 'banned@example.test', 'password' => 'the-right-password', 'banned_at' => now()]);

        $this->post('/login', ['username' => 'member@example.test', 'password' => 'the-right-password'])->assertRedirect('/profile');
        $this->assertAuthenticatedAs($member);
        $this->post('/logout');
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/login', ['username' => 'member@example.test', 'password' => 'the-right-password', 'device_name' => 'phone'])
            ->assertOk()->assertJsonStructure(['token']);

        $this->from('/login')->post('/login', ['username' => 'banned@example.test', 'password' => 'the-right-password'])
            ->assertRedirect('/login')->assertSessionHas('auth_banned', true);
        $this->assertGuest();
        $this->postJson('/api/login', ['username' => 'banned@example.test', 'password' => 'the-right-password'])->assertForbidden();
    }

    public function test_the_fixed_hash_has_the_sites_driver_and_cost_and_matches_no_password(): void
    {
        foreach ([4, 10, 12] as $cost) {
            config(['hashing.driver' => 'bcrypt', 'hashing.bcrypt.rounds' => $cost]);
            $dummy = PasswordCheck::dummyHash();
            $info = password_get_info($dummy);
            $this->assertSame('bcrypt', $info['algoName']);
            $this->assertSame($cost, $info['options']['cost']);
            $this->assertSame(60, strlen($dummy));
        }

        config(['hashing.bcrypt.rounds' => 4]);
        foreach (['', 'password', 'never-a-password', 'a-wrong-password', str_repeat('x', 72)] as $password) {
            $this->assertFalse(password_verify($password, PasswordCheck::dummyHash()), 'no password matches it: '.$password);
        }

        // the other drivers of config/hashing.php: their own parameters (Laravel's defaults when a value is not set)
        config(['hashing.driver' => 'argon', 'hashing.argon' => ['memory' => 2048, 'threads' => 1, 'time' => 2]]);
        $this->assertSame(['algoName' => 'argon2i', 'memory_cost' => 2048, 'time_cost' => 2, 'threads' => 1], $this->argonInfo(PasswordCheck::dummyHash()));
        $this->assertFalse(password_verify('password', PasswordCheck::dummyHash()));
        config(['hashing.driver' => 'argon2id']);
        $this->assertSame(['algoName' => 'argon2id', 'memory_cost' => 2048, 'time_cost' => 2, 'threads' => 1], $this->argonInfo(PasswordCheck::dummyHash()));
        config(['hashing.argon' => []]);
        $this->assertSame(['algoName' => 'argon2id', 'memory_cost' => 1024, 'time_cost' => 2, 'threads' => 2], $this->argonInfo(PasswordCheck::dummyHash()));

        // the verifying hasher (hashing.bcrypt.verify) accepts it: the check never throws for a missing account
        config(['hashing.driver' => 'bcrypt', 'hashing.bcrypt' => ['rounds' => 4, 'verify' => true]]);
        $this->app['hash']->forgetDrivers();
        $this->assertFalse(PasswordCheck::matches(null, 'password'));
    }

    public function test_a_password_of_another_cost_is_hashed_again_with_the_sites_at_sign_in(): void
    {
        $siteCost = $this->siteCost();
        $member = User::factory()->create(['email' => 'old@example.test']);
        $member2 = User::factory()->create(['email' => 'old2@example.test']);
        foreach ([$member, $member2] as $account) {
            DB::table('users')->where('id', $account->id)->update(['password' => password_hash('the-old-password', PASSWORD_BCRYPT, ['cost' => $siteCost + 1])]);
        }

        $this->post('/login', ['username' => 'old@example.test', 'password' => 'the-old-password'])->assertRedirect('/profile');
        $after = (string) DB::table('users')->where('id', $member->id)->value('password');
        $this->assertSame($siteCost, $this->cost($after), 'the sign-in page hashed it again with the site\'s cost');
        $this->assertTrue(password_verify('the-old-password', $after), 'the same password, hashed once');
        $this->post('/logout');
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['username' => 'old@example.test', 'password' => 'the-old-password'])->assertRedirect('/profile');
        $this->post('/logout');
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/login', ['username' => 'old2@example.test', 'password' => 'the-old-password', 'device_name' => 'phone'])->assertOk();
        $this->assertSame($siteCost, $this->cost((string) DB::table('users')->where('id', $member2->id)->value('password')), 'the apps\' sign-in too');

        // a wrong password changes nothing; with hashing.rehash_on_login off nothing is hashed again
        $third = User::factory()->create(['email' => 'old3@example.test']);
        $old = password_hash('the-old-password', PASSWORD_BCRYPT, ['cost' => $siteCost + 1]);
        DB::table('users')->where('id', $third->id)->update(['password' => $old]);
        $this->from('/login')->post('/login', ['username' => 'old3@example.test', 'password' => 'wrong'])->assertRedirect('/login');
        $this->assertSame($old, DB::table('users')->where('id', $third->id)->value('password'));
        config(['hashing.rehash_on_login' => false]);
        $this->post('/login', ['username' => 'old3@example.test', 'password' => 'the-old-password'])->assertRedirect('/profile');
        $this->assertSame($old, DB::table('users')->where('id', $third->id)->value('password'));
    }

    /** Every Hash::check of the request is recorded (the hash it compared with), then done by the real hasher. */
    private function countChecks(): void
    {
        $real = $this->app['hash'];
        $test = $this;
        $spy = new class($real, $test) implements Hasher
        {
            public function __construct(private $real, private $test)
            {
            }

            public function info($hashedValue)
            {
                return $this->real->info($hashedValue);
            }

            public function make(#[\SensitiveParameter] $value, array $options = [])
            {
                return $this->real->make($value, $options);
            }

            public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = [])
            {
                $this->test->recordCheck((string) $hashedValue);

                return $this->real->check($value, $hashedValue, $options);
            }

            public function needsRehash($hashedValue, array $options = [])
            {
                return $this->real->needsRehash($hashedValue, $options);
            }

            public function __call($method, $parameters)
            {
                return $this->real->{$method}(...$parameters);
            }
        };
        Hash::swap($spy);
        $this->app['auth']->forgetGuards();
    }

    public function recordCheck(string $hash): void
    {
        $this->checked[] = $hash;
    }

    private function siteCost(): int
    {
        return (int) password_get_info(Hash::make('probe'))['options']['cost'];
    }

    private function cost(string $hash): int
    {
        return (int) (password_get_info($hash)['options']['cost'] ?? 0);
    }

    /** @return array{algoName: string, memory_cost: int, time_cost: int, threads: int} */
    private function argonInfo(string $hash): array
    {
        $info = password_get_info($hash);

        return ['algoName' => $info['algoName'], 'memory_cost' => $info['options']['memory_cost'], 'time_cost' => $info['options']['time_cost'], 'threads' => $info['options']['threads']];
    }
}
