<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AdminPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin IP lock behind proxies (owner decision OD2): zero configuration on any panel. Forwarded addresses
 * (X-Forwarded-For, CF-Connecting-IP) are believed automatically only from hops nobody on the internet can be -
 * loopback, private and link-local networks, Cloudflare's published ranges - so a Docker panel's reverse proxy, a panel
 * nginx in front of Apache / OpenLiteSpeed and Cloudflare work out of the box, while a forged X-Forwarded-For from a
 * public address is still ignored. A locked-out owner reads why on the 403 page (never the list) and gets back in with
 * `php artisan avd:admin-ip-lock off`.
 */
class AdminIpLockProxyTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = '203.0.113.7';
    private const ATTACKER = '198.51.100.20';
    private const CLOUDFLARE_EDGE = '173.245.48.10';
    private const CLOUDFLARE_EDGE_V6 = '2606:4700:10::6816:1';
    private const DOCKER_GATEWAY = '172.18.0.1';

    public static function hops(): array
    {
        return [
            // Docker-style panels (1Panel, docker compose): the proxy reaches the site over a bridge network.
            'docker proxy, owner' => [self::DOCKER_GATEWAY, ['X-Forwarded-For' => self::OWNER], 200],
            'docker proxy, stranger' => [self::DOCKER_GATEWAY, ['X-Forwarded-For' => self::ATTACKER], 403],
            'docker proxy, stranger forging the left part' => [self::DOCKER_GATEWAY, ['X-Forwarded-For' => self::OWNER.', '.self::ATTACKER], 403],
            'docker proxy, stranger forging private hops' => [self::DOCKER_GATEWAY, ['X-Forwarded-For' => self::OWNER.', 10.0.0.1, '.self::ATTACKER], 403],
            'docker proxy on a dual-stack socket' => ['::ffff:'.self::DOCKER_GATEWAY, ['X-Forwarded-For' => self::OWNER], 200],
            'LAN reverse proxy' => ['192.168.1.10', ['X-Forwarded-For' => self::OWNER], 200],
            'private 10/8 proxy, two hops' => ['10.8.0.2', ['X-Forwarded-For' => self::OWNER.', 10.8.0.9'], 200],
            'unique local IPv6 proxy' => ['fd12:3456::1', ['X-Forwarded-For' => self::OWNER], 200],
            'link-local proxy' => ['169.254.10.20', ['X-Forwarded-For' => self::OWNER], 200],
            // A panel's nginx on the same machine in front of Apache / OpenLiteSpeed.
            'loopback proxy, owner' => ['127.0.0.1', ['X-Forwarded-For' => self::OWNER], 200],
            'loopback proxy over IPv6, owner' => ['::1', ['X-Forwarded-For' => self::OWNER], 200],
            'loopback proxy, stranger' => ['127.0.0.1', ['X-Forwarded-For' => self::ATTACKER], 403],
            // Cloudflare writes CF-Connecting-IP itself (and overwrites a value the visitor sent).
            'Cloudflare edge, owner' => [self::CLOUDFLARE_EDGE, ['CF-Connecting-IP' => self::OWNER, 'X-Forwarded-For' => self::OWNER], 200],
            'Cloudflare edge over IPv6, owner' => [self::CLOUDFLARE_EDGE_V6, ['CF-Connecting-IP' => self::OWNER], 200],
            'Cloudflare edge, stranger forging X-Forwarded-For' => [self::CLOUDFLARE_EDGE, ['CF-Connecting-IP' => self::ATTACKER, 'X-Forwarded-For' => self::OWNER.', '.self::ATTACKER], 403],
            'Cloudflare, then a docker proxy, owner' => [self::DOCKER_GATEWAY, ['CF-Connecting-IP' => self::OWNER, 'X-Forwarded-For' => self::OWNER.', '.self::CLOUDFLARE_EDGE], 200],
            'Cloudflare, then a proxy that forwards only its peer, owner' => ['127.0.0.1', ['CF-Connecting-IP' => self::OWNER, 'X-Forwarded-For' => self::CLOUDFLARE_EDGE], 200],
            'Cloudflare, then a docker proxy, stranger' => [self::DOCKER_GATEWAY, ['CF-Connecting-IP' => self::ATTACKER, 'X-Forwarded-For' => self::OWNER.', '.self::CLOUDFLARE_EDGE], 403],
            // A public address can type any header: only its own address counts.
            'public spoof of X-Forwarded-For' => [self::ATTACKER, ['X-Forwarded-For' => self::OWNER], 403],
            'public spoof of Cloudflare headers' => [self::ATTACKER, ['CF-Ray' => '8a1b2c3d4e5f-AMS', 'CF-Connecting-IP' => self::OWNER, 'X-Forwarded-For' => self::OWNER], 403],
            'public spoof of private and loopback hops' => [self::ATTACKER, ['X-Forwarded-For' => self::OWNER.', 10.0.0.1, 127.0.0.1'], 403],
            'owner, direct' => [self::OWNER, [], 200],
        ];
    }

    #[DataProvider('hops')]
    public function test_forwarded_addresses_count_only_from_hops_nobody_on_the_internet_can_be(string $remote, array $headers, int $status): void
    {
        // As on a real site without a "cdn" settings row: the framework trusts whoever connects ('*'). The lock does not.
        config(['site.admin_ip_whitelist' => self::OWNER, 'site.trusted_proxies' => '*']);

        $this->actingAs(User::factory()->admin()->create())
            ->withServerVariables(['REMOTE_ADDR' => $remote])
            ->withHeaders($headers)
            ->get('/admin')
            ->assertStatus($status);
    }

    public function test_saving_the_list_through_a_docker_proxy_keeps_the_owners_real_address(): void
    {
        config(['admin.ui' => 'v2']);
        // The lock list is an expert setting: the new admin saves it in Developer mode only.
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => self::DOCKER_GATEWAY])
            ->withHeaders(['X-Forwarded-For' => self::OWNER])
            ->putJson('/admin/settings/save', [
                'groups' => ['security' => ['admin_ip_whitelist' => '192.0.2.10']],
                'fields' => ['security' => ['admin_ip_whitelist']],
            ])
            ->assertOk()
            ->assertJsonPath('values.security.admin_ip_whitelist', "192.0.2.10\n".self::OWNER)
            ->assertJsonPath('notes.0', 'Your address '.self::OWNER.' was added to the list so you keep access.');
    }

    public function test_a_locked_out_owner_reads_how_to_get_back_in_and_never_the_list(): void
    {
        config(['site.admin_ip_whitelist' => self::OWNER."\n192.0.2.0/24"]);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => self::ATTACKER])
            ->get('/admin/settings')
            ->assertForbidden()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('This address cannot open the admin')
            ->assertSee('<code>'.self::ATTACKER.'</code>', false)
            ->assertSee('php artisan avd:admin-ip-lock off')
            ->assertSee('php artisan avd:admin-ip-lock status')
            // The card by its real name, where it shows, and the cache step; names stay code (left to right).
            ->assertSee('Settings > Security > Admin access lock')
            ->assertSee('turn on Developer mode in the account menu')
            ->assertSee('<code>admin_ip_whitelist</code> in the <code>settings</code> table', false)
            ->assertSee('delete the files in <code>storage/framework/cache/data</code>', false)
            ->assertSee('empty the <code>cache</code> table', false)
            ->assertDontSee(self::OWNER)
            ->assertDontSee('192.0.2.0/24');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // Scripts get a plain answer, still without the list.
        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => self::ATTACKER])
            ->getJson('/admin/status/disk')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Admin access denied from this IP.']);

        // Visitors who are not administrators still get "not found" (the admin is not advertised).
        $this->actingAs(User::factory()->create())->withServerVariables(['REMOTE_ADDR' => self::ATTACKER])->get('/admin')->assertNotFound();
    }

    public function test_the_recovery_command_shows_the_lock_and_switches_it_off(): void
    {
        Setting::set('admin_ip_whitelist', self::OWNER."\n192.0.2.0/24\nnot-an-address", 'security');
        $this->assertSame(self::OWNER."\n192.0.2.0/24\nnot-an-address", Setting::getAllGrouped()['security']['admin_ip_whitelist'], 'the site has the list in its cache');
        $ssh = getenv('SSH_CONNECTION');

        try {
            putenv('SSH_CONNECTION='.self::ATTACKER.' 51234 10.0.0.2 22');

            $this->artisan('avd:admin-ip-lock status')
                ->expectsOutputToContain('The admin IP lock is on: 3 addresses or ranges are on the list.')
                ->expectsOutputToContain('1 of them is not an address and never matches.')
                ->expectsOutputToContain('The address of the computer you connect from (this SSH session): '.self::ATTACKER)
                ->expectsOutputToContain('It is not on the list: the admin is locked for it.')
                ->doesntExpectOutputToContain(self::OWNER)
                ->doesntExpectOutputToContain('192.0.2.0/24')
                ->assertSuccessful();

            $this->artisan('avd:admin-ip-lock off')
                ->expectsOutputToContain('The admin IP lock is off: the list of 3 entries is empty, and the settings cache is cleared.')
                ->expectsOutputToContain('To lock it again, turn on Developer mode in the account menu, then add your address in Settings > Security > Admin access lock.')
                ->assertSuccessful();

            $this->assertSame('', Setting::query()->where('key', 'admin_ip_whitelist')->value('value'));
            $this->assertSame('security', Setting::query()->where('key', 'admin_ip_whitelist')->value('group'));
            $this->assertSame('', Setting::getAllGrouped()['security']['admin_ip_whitelist'], 'the cached copy is gone');
            $this->assertSame(
                [['security.admin_ip_lock.off', 'Turned off from the command line: 3 entries removed.']],
                DB::table('admin_audit_logs')->get(['action', 'details'])->map(fn ($row): array => [$row->action, $row->details])->all()
            );

            // What the site applies on the next request (AppServiceProvider reads the cached settings): the admin opens.
            config(['site.admin_ip_whitelist' => Setting::getAllGrouped()['security']['admin_ip_whitelist']]);
            $this->actingAs(User::factory()->admin()->create())->withServerVariables(['REMOTE_ADDR' => self::ATTACKER])->get('/admin')->assertOk();

            $this->artisan('avd:admin-ip-lock status')->expectsOutputToContain('The admin IP lock is off: the list is empty')->assertSuccessful();
            $this->artisan('avd:admin-ip-lock off')->expectsOutputToContain('The admin IP lock was already off.')->assertSuccessful();
            $this->artisan('avd:admin-ip-lock wipe')->assertExitCode(2);
        } finally {
            putenv($ssh === false ? 'SSH_CONNECTION' : 'SSH_CONNECTION='.$ssh);
        }
    }

    public function test_status_without_ssh_and_a_list_edited_only_in_the_database(): void
    {
        $ssh = getenv('SSH_CONNECTION');
        $client = getenv('SSH_CLIENT');

        try {
            putenv('SSH_CONNECTION');
            putenv('SSH_CLIENT');
            Setting::set('admin_ip_whitelist', self::OWNER, 'security');
            Setting::getAllGrouped();
            // Emptied in the database alone (the recovery without a command line): the cache still has the list.
            DB::table('settings')->where('key', 'admin_ip_whitelist')->update(['value' => '']);

            $this->artisan('avd:admin-ip-lock status')
                ->expectsOutputToContain('The admin IP lock is off')
                ->expectsOutputToContain('This shell is not an SSH connection')
                ->expectsOutputToContain('The site still uses an older copy of the list from its cache')
                ->assertSuccessful();
        } finally {
            putenv($ssh === false ? 'SSH_CONNECTION' : 'SSH_CONNECTION='.$ssh);
            putenv($client === false ? 'SSH_CLIENT' : 'SSH_CLIENT='.$client);
        }
    }
}
