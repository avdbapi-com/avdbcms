<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Providers\EnvironmentServiceProvider;
use App\Support\AdminIpLock;
use App\Support\AdminPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin IP lock checks the visitor's real address. Forwarded headers count only when the connection comes
 * from Cloudflare's published ranges, a proxy on the same machine or a private network (owner decision OD2: nobody on
 * the internet connects from there; more in AdminIpLockProxyTest) or a proxy listed in TRUSTED_PROXIES; anyone on the
 * internet can type CF-Ray / CF-Connecting-IP / X-Forwarded-For themselves. Entries may be IPv4, IPv6 or CIDR ranges.
 */
class AdminIpWhitelistTrustTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = '203.0.113.7';
    private const ATTACKER = '198.51.100.20';
    private const CLOUDFLARE_EDGE = '173.245.48.10';

    public static function requests(): array
    {
        return [
            'owner, direct' => [self::OWNER, [], 200],
            'stranger, direct' => [self::ATTACKER, [], 403],
            'stranger forging Cloudflare headers' => [self::ATTACKER, ['CF-Ray' => '8a1b2c3d4e5f-AMS', 'CF-Connecting-IP' => self::OWNER, 'X-Forwarded-For' => self::OWNER], 403],
            'stranger forging X-Forwarded-For only' => [self::ATTACKER, ['X-Forwarded-For' => self::OWNER], 403],
            'owner through Cloudflare' => [self::CLOUDFLARE_EDGE, ['CF-Ray' => '8a1b2c3d4e5f-AMS', 'CF-Connecting-IP' => self::OWNER, 'X-Forwarded-For' => self::OWNER], 200],
            'stranger through Cloudflare' => [self::CLOUDFLARE_EDGE, ['CF-Connecting-IP' => self::ATTACKER, 'X-Forwarded-For' => self::OWNER.', '.self::ATTACKER], 403],
            'owner through a local proxy' => ['127.0.0.1', ['X-Forwarded-For' => self::OWNER], 200],
            'stranger through a local proxy, forged left part' => ['127.0.0.1', ['X-Forwarded-For' => self::OWNER.', '.self::ATTACKER], 403],
            'owner through Cloudflare and a local proxy' => ['::1', ['X-Forwarded-For' => self::OWNER.', '.self::CLOUDFLARE_EDGE], 200],
            'owner through a proxy on a private network' => ['10.0.0.5', ['X-Forwarded-For' => self::OWNER, 'CF-Ray' => 'x'], 200],
            'owner through a container bridge' => ['172.17.0.9', ['X-Forwarded-For' => self::OWNER], 200],
            'stranger through a container bridge, forged left part' => ['172.17.0.9', ['X-Forwarded-For' => self::OWNER.', '.self::ATTACKER], 403],
            'stranger on a dual-stack socket forging headers' => ['::ffff:'.self::ATTACKER, ['X-Forwarded-For' => self::OWNER, 'CF-Ray' => 'x'], 403],
            'owner on a dual-stack socket' => ['::ffff:'.self::OWNER, [], 200],
        ];
    }

    #[DataProvider('requests')]
    public function test_only_the_real_visitor_address_opens_a_locked_admin(string $remote, array $headers, int $status): void
    {
        // As on a real site: without a "cdn" settings row the site trusts whoever connects as a proxy ('*'),
        // so $request->ip() follows X-Forwarded-For. The lock must not.
        config(['site.admin_ip_whitelist' => self::OWNER, 'site.trusted_proxies' => '*']);

        $this->actingAs(User::factory()->admin()->create())
            ->withServerVariables(['REMOTE_ADDR' => $remote])
            ->withHeaders($headers)
            ->get('/admin')
            ->assertStatus($status);
    }

    public function test_a_proxy_on_a_public_address_counts_once_the_owner_lists_it(): void
    {
        $admin = User::factory()->admin()->create();
        $proxy = '192.0.2.5';
        config(['site.admin_ip_whitelist' => self::OWNER, 'site.trusted_proxies' => '*']);

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => $proxy])
            ->withHeaders(['X-Forwarded-For' => self::OWNER])
            ->get('/admin')
            ->assertForbidden();

        config(['site.trusted_proxies' => "192.0.2.0/24\n"]);

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => $proxy])
            ->withHeaders(['X-Forwarded-For' => self::OWNER])
            ->get('/admin')
            ->assertOk();

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => $proxy])
            ->withHeaders(['X-Forwarded-For' => self::OWNER.', '.self::ATTACKER])
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_entries_may_be_ranges_and_ipv6_addresses(): void
    {
        $admin = User::factory()->admin()->create();
        config(['site.admin_ip_whitelist' => "203.0.113.0/24\n2001:db8::/32, 2001:0db8:ffff:0000:0000:0000:0000:0001\n\n"]);

        foreach (['203.0.113.99' => 200, '203.0.114.1' => 403, '2001:db8::5' => 200, '2001:db9::1' => 403, '2001:db8:ffff::1' => 200] as $ip => $status) {
            $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => $ip])->get('/admin')->assertStatus($status);
        }
    }

    public function test_an_empty_list_leaves_the_admin_open(): void
    {
        config(['site.admin_ip_whitelist' => "  \n "]);

        $this->actingAs(User::factory()->admin()->create())
            ->withServerVariables(['REMOTE_ADDR' => self::ATTACKER])
            ->get('/admin')
            ->assertOk();
    }

    public function test_saving_the_list_keeps_the_saving_admins_own_address(): void
    {
        config(['admin.ui' => 'v2']);
        // The lock list is an expert setting: the new admin saves it in Developer mode only.
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => self::ATTACKER])
            ->putJson('/admin/settings/save', [
                'groups' => ['security' => ['admin_ip_whitelist' => self::OWNER]],
                'fields' => ['security' => ['admin_ip_whitelist']],
            ])
            ->assertOk()
            ->assertJsonPath('values.security.admin_ip_whitelist', self::OWNER."\n".self::ATTACKER)
            ->assertJsonPath('notes.0', 'Your address '.self::ATTACKER.' was added to the list so you keep access.');

        $this->assertSame(self::OWNER."\n".self::ATTACKER, Setting::get('admin_ip_whitelist'));
    }

    public function test_a_list_that_already_covers_the_admin_is_saved_as_typed(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->putJson('/admin/settings/save', [
                'groups' => ['security' => ['admin_ip_whitelist' => '203.0.113.0/24']],
                'fields' => ['security' => ['admin_ip_whitelist']],
            ])
            ->assertOk()
            ->assertJsonPath('notes', []);

        $this->assertSame('203.0.113.0/24', Setting::get('admin_ip_whitelist'));
    }

    public function test_entries_that_are_not_addresses_are_refused_before_anyone_is_locked_out(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/admin/settings/save', [
                'groups' => ['security' => ['admin_ip_whitelist' => "203.0.113.7\nmy-home-ip\n10.0.0.0/33"]],
                'fields' => ['security' => ['admin_ip_whitelist']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['security.admin_ip_whitelist']);

        $this->assertNull(Setting::get('admin_ip_whitelist'));
    }

    public function test_a_forged_cf_ray_header_no_longer_makes_the_environment_trust_the_caller(): void
    {
        $server = $_SERVER;
        config(['site.trusted_proxies' => '']);

        try {
            $_SERVER['REMOTE_ADDR'] = self::ATTACKER;
            $_SERVER['HTTP_CF_RAY'] = '8a1b2c3d4e5f-AMS';
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
            (new EnvironmentServiceProvider($this->app))->boot();
            $this->assertSame([], Request::getTrustedProxies());

            $_SERVER['REMOTE_ADDR'] = self::CLOUDFLARE_EDGE;
            (new EnvironmentServiceProvider($this->app))->boot();
            $this->assertSame([self::CLOUDFLARE_EDGE], Request::getTrustedProxies());
        } finally {
            $_SERVER = $server;
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        }
    }

    public function test_the_address_helpers(): void
    {
        $this->assertTrue(AdminIpLock::isCloudflare('2606:4700::6810:84e5'));
        $this->assertFalse(AdminIpLock::isCloudflare(self::ATTACKER));
        $this->assertFalse(AdminIpLock::isLocalProxy('::ffff:'.self::ATTACKER));
        $this->assertTrue(AdminIpLock::isLocalProxy('::1'));
        $this->assertTrue(AdminIpLock::isLocalProxy('10.0.0.5'));
        $this->assertTrue(AdminIpLock::isLocalProxy('169.254.20.1'));
        $this->assertTrue(AdminIpLock::isLocalProxy('fe80::1'));
        $this->assertTrue(AdminIpLock::isLocalProxy('fd00::7'));
        $this->assertFalse(AdminIpLock::isLocalProxy('192.0.2.5'));
        $this->assertTrue(AdminIpLock::isLoopback('::ffff:127.0.0.1'));
        $this->assertFalse(AdminIpLock::isLoopback('10.0.0.5'));
        $this->assertSame(['203.0.113.7', '2001:db8::/32'], AdminIpLock::entries(" 203.0.113.7,\n\n2001:db8::/32 "));
        $this->assertSame(['nope', '10.0.0.0/33'], AdminIpLock::invalidEntries("10.0.0.0/8\nnope\n10.0.0.0/33"));
        $this->assertSame("203.0.113.0/24\n198.51.100.20", AdminIpLock::withAddress('203.0.113.0/24', '198.51.100.20'));
        $this->assertSame('203.0.113.0/24', AdminIpLock::withAddress('203.0.113.0/24', '203.0.113.200'));
        $this->assertSame('', AdminIpLock::withAddress('', '198.51.100.20'));
    }
}
