<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AdminPreferences;
use App\Support\BlockedAddresses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.3: Members > Blocked addresses really blocks (App\Support\BlockedAddresses, asked by the Security
 * middleware on every page). Up to 1.2.2 "Block an address" blocked nobody (the ip_bans table was never read), and the
 * list of the card "Blocked ranges" matched exact addresses only: 127.0.0.0/8 or 203.0.113.0/24 blocked nobody.
 *
 * Now an address or a CIDR range (IPv4 and IPv6) blocks; a list entry that is neither is refused when it is saved; the
 * owner can never lock themselves out (their own address is refused, the sign-in page stays open, a signed-in
 * administrator is never blocked; a view-only demo account is). The visitor's address is the admin lock's
 * (AdminIpLock::clientIp()): a forged X-Forwarded-For neither escapes a block nor frames somebody else.
 */
class BlockedAddressesTest extends TestCase
{
    use RefreshDatabase;

    private const VISITOR = '203.0.113.9';
    private const OTHER = '198.51.100.20';

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2', 'site.global_ip_blacklist' => '']);
        BlockedAddresses::forget();
    }

    // ---------------------------------------------------------------- the list: addresses and ranges

    public static function lists(): array
    {
        return [
            'exact address' => ['203.0.113.9', self::VISITOR, true],
            'IPv4 range' => ['203.0.113.0/24', self::VISITOR, true],
            'wide IPv4 range' => ['127.0.0.0/8', '127.0.0.1', true],
            'one of several lines' => ["198.51.100.1\n203.0.113.0/28\n192.0.2.7", self::VISITOR, true],
            'commas and spaces' => ['198.51.100.1, 203.0.113.9', self::VISITOR, true],
            'a line with a note after the address (1.2.2 lists)' => ['203.0.113.9 # spam', self::VISITOR, true],
            'IPv6 address in another form' => ['2001:DB8:0:0::7', '2001:db8::7', true],
            'IPv6 range' => ['2001:db8::/32', '2001:db8:1234::1', true],
            'IPv4 visitor on a dual-stack socket' => ['203.0.113.0/24', '::ffff:203.0.113.9', true],
            'another address' => ['203.0.113.10', self::VISITOR, false],
            'another range' => ['203.0.112.0/24', self::VISITOR, false],
            'a wildcard blocks nobody' => ['203.0.113.*', self::VISITOR, false],
            'a from-to range blocks nobody' => ['203.0.113.0-203.0.113.255', self::VISITOR, false],
            'an empty list' => ['', self::VISITOR, false],
        ];
    }

    #[DataProvider('lists')]
    public function test_the_list_takes_addresses_and_ranges(string $list, string $ip, bool $blocked): void
    {
        $this->assertSame($blocked, BlockedAddresses::listBlocks($list, $ip));

        config(['site.global_ip_blacklist' => $list]);
        $this->assertSame($blocked, BlockedAddresses::blocks($ip));
    }

    public function test_an_active_block_of_blocked_addresses_blocks_until_it_ends(): void
    {
        $this->ban('203.0.113.9');
        $this->ban('2001:DB8::0:7');
        $this->ban('198.51.100.1', now()->addHour());
        $this->ban('198.51.100.2', now()->subMinute());

        $this->assertTrue(BlockedAddresses::banned('203.0.113.9'));
        $this->assertTrue(BlockedAddresses::banned('2001:db8::7'), 'compared in the short form');
        $this->assertTrue(BlockedAddresses::banned('::ffff:203.0.113.9'));
        $this->assertTrue(BlockedAddresses::banned('198.51.100.1'), 'until its end');
        $this->assertFalse(BlockedAddresses::banned('198.51.100.2'), 'ended');
        $this->assertFalse(BlockedAddresses::banned('203.0.113.10'));

        // A site with very many blocks looks each visitor up in the table instead of the cache.
        DB::table('ip_bans')->insert(array_map(fn (int $i): array => ['ip_address' => '10.9.'.intdiv($i, 250).'.'.($i % 250), 'reason' => '', 'expires_at' => null, 'created_at' => now(), 'updated_at' => now()], range(1, BlockedAddresses::CACHE_MAX + 1)));
        BlockedAddresses::forget();
        $this->assertTrue(BlockedAddresses::banned('203.0.113.9'));
        $this->assertTrue(BlockedAddresses::banned('10.9.4.1'));
        $this->assertFalse(BlockedAddresses::banned('198.51.100.2'));
    }

    // ---------------------------------------------------------------- every page (the Security middleware)

    public function test_a_blocked_address_cannot_open_the_site_and_a_range_blocks_too(): void
    {
        $this->asVisitor(self::VISITOR)->get('/terms')->assertOk();

        config(['site.global_ip_blacklist' => '203.0.113.0/24']);
        $this->asVisitor(self::VISITOR)->get('/terms')->assertForbidden();
        $this->asVisitor(self::OTHER)->get('/terms')->assertOk();

        config(['site.global_ip_blacklist' => '2001:db8::/32']);
        $this->asVisitor('2001:db8:9::1')->get('/terms')->assertForbidden();
        $this->asVisitor(self::VISITOR)->get('/terms')->assertOk();
    }

    public function test_block_an_address_blocks_it_at_once(): void
    {
        $admin = $this->owner();

        $this->asVisitor(self::OTHER)->get('/terms')->assertOk();

        $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => self::VISITOR])
            ->post(route('admin.ip-bans.store'), ['ip_address' => self::OTHER, 'reason' => 'spam', 'duration' => ''])
            ->assertRedirect(route('admin.ip-bans.index'))
            ->assertSessionHas('success');

        $this->app['auth']->forgetGuards();
        $this->asVisitor(self::OTHER)->get('/terms')->assertForbidden();

        // Unblocked: open again at once.
        $id = (int) DB::table('ip_bans')->where('ip_address', self::OTHER)->value('id');
        $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->delete(route('admin.ip-bans.delete', ['id' => $id]))->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->asVisitor(self::OTHER)->get('/terms')->assertOk();
    }

    public function test_an_owner_cannot_lock_themselves_out(): void
    {
        config(['site.global_ip_blacklist' => self::VISITOR]);
        $this->ban(self::OTHER);

        // The sign-in page stays open to a blocked address.
        $this->asVisitor(self::VISITOR)->get('/login')->assertOk();
        $this->asVisitor(self::OTHER)->get('/login')->assertOk();

        // A signed-in administrator is never blocked, a member and a view-only demo account are.
        $this->actingAs($this->owner())->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('/terms')->assertOk();
        $this->actingAs($this->owner())->withServerVariables(['REMOTE_ADDR' => self::OTHER])->get('/admin')->assertOk();
        $this->actingAs(User::factory()->create())->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('/terms')->assertForbidden();
        $demo = $this->owner();
        $demo->forceFill(['is_view_only' => true])->save();
        $this->actingAs($demo->fresh())->withServerVariables(['REMOTE_ADDR' => self::OTHER])->get('/terms')->assertForbidden();
    }

    public function test_the_api_refuses_a_blocked_address_too(): void
    {
        // Comments, reports and likes go through /api/*: a blocked address gets no answer there either (1.2.2: always).
        config(['site.global_ip_blacklist' => '203.0.113.0/24', 'site.api_rate_limit' => 1000]);

        $this->asVisitor(self::OTHER)->getJson('/api/search?q=lane-g-open')->assertOk();
        $this->asVisitor(self::VISITOR)->getJson('/api/search?q=lane-g-closed')->assertForbidden()->assertJsonPath('message', __('Access denied.'));

        // An app can still sign in: a wrong password is answered as such, not as a blocked address.
        $login = $this->asVisitor(self::VISITOR)->postJson('/api/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password', 'device_name' => 'probe']);
        $this->assertNotSame(403, $login->getStatusCode(), (string) $login->getContent());

        // A signed-in administrator (its token) is never blocked; a member is.
        $this->actingAs($this->owner(), 'sanctum')->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->getJson('/api/search?q=lane-g-admin')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(), 'sanctum')->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->getJson('/api/search?q=lane-g-member')->assertForbidden();
    }

    public function test_signing_in_with_google_or_facebook_stays_open(): void
    {
        config(['site.global_ip_blacklist' => self::VISITOR]);

        // Not set up on a fresh site: the answer is the sign-in page with a message, never "Access denied.".
        $this->asVisitor(self::VISITOR)->get('/oauth/connect/google')->assertRedirect();
        $this->asVisitor(self::VISITOR)->get('/terms')->assertForbidden();
    }

    public function test_a_forged_forwarded_address_neither_escapes_nor_frames(): void
    {
        config(['site.global_ip_blacklist' => self::VISITOR, 'site.trusted_proxies' => '*']);

        // From a public address only its own address counts, whatever it writes in X-Forwarded-For.
        $this->asVisitor(self::VISITOR)->withHeaders(['X-Forwarded-For' => self::OTHER])->get('/terms')->assertForbidden();
        $this->asVisitor(self::OTHER)->withHeaders(['X-Forwarded-For' => self::VISITOR])->get('/terms')->assertOk();

        // Behind a proxy on this machine or Cloudflare, the visitor it forwards counts.
        $this->asVisitor('127.0.0.1')->withHeaders(['X-Forwarded-For' => self::VISITOR])->get('/terms')->assertForbidden();
        $this->asVisitor('173.245.48.10')->withHeaders(['CF-Connecting-IP' => self::VISITOR])->get('/terms')->assertForbidden();
    }

    // ---------------------------------------------------------------- the admin refuses what would block nobody or the owner

    public function test_the_list_refuses_entries_that_block_nobody_and_the_owners_own_address(): void
    {
        $admin = $this->owner();
        $save = fn (string $list) => $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => self::VISITOR])
            ->putJson('/admin/settings/save', ['groups' => ['security' => ['global_ip_blacklist' => $list]], 'fields' => ['security' => ['global_ip_blacklist']]]);

        $save("198.51.100.0/24\n203.0.113.*")->assertStatus(422)
            ->assertJsonPath('errors', ['security.global_ip_blacklist' => [__('Use one address per line, like 203.0.113.7 or 2001:db8::7, or a range like 203.0.113.0/24. Not an address: :entries', ['entries' => '203.0.113.*'])]]);

        $save("198.51.100.0/24\n203.0.113.0/24")->assertStatus(422)
            ->assertJsonPath('errors', ['security.global_ip_blacklist' => [__('This list would block your own address (:ip). Take it out of the list.', ['ip' => self::VISITOR])]]);
        $this->assertSame('', (string) (Setting::getGroup('security')['global_ip_blacklist'] ?? ''), 'nothing was saved');

        $save("198.51.100.0/24\n2001:db8::/32")->assertOk();
        $this->assertSame("198.51.100.0/24\n2001:db8::/32", Setting::getGroup('security')['global_ip_blacklist'] ?? null);

        $save('')->assertOk();
        $this->assertSame('', Setting::getGroup('security')['global_ip_blacklist'] ?? null);
    }

    public function test_block_an_address_refuses_the_owners_own_address_and_keeps_the_short_form(): void
    {
        $admin = $this->owner();
        $post = fn (string $ip) => $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => self::VISITOR])
            ->post(route('admin.ip-bans.store'), ['ip_address' => $ip, 'reason' => '', 'duration' => '24']);

        $post(self::VISITOR)->assertRedirect(route('admin.ip-bans.index'))
            ->assertSessionHasErrors(['ip_address' => __('You cannot block your own address (:ip).', ['ip' => self::VISITOR])]);
        $this->assertSame(0, DB::table('ip_bans')->count());

        $post('2001:DB8:0:0::7')->assertSessionHas('success', __(':ip is blocked: it cannot open your site.', ['ip' => '2001:db8::7']));
        $this->assertSame('2001:db8::7', DB::table('ip_bans')->value('ip_address'));
        $post('2001:db8::7')->assertSessionHasErrors(['ip_address' => __('This address is already blocked.')]);
        $this->assertSame(1, DB::table('ip_bans')->count());
    }

    public function test_the_texts_say_what_the_list_does(): void
    {
        $html = (string) $this->actingAs($this->owner())->get('/admin/ip-bans')->assertOk()->getContent();

        $this->assertStringContainsString(e(__('Block whole ranges of addresses at once, such as 203.0.113.0/24.')), $html);
        $this->assertStringContainsString(e(__('One address (203.0.113.7) or range (203.0.113.0/24) per line. These visitors cannot open your site. Administrators can always sign in, so you cannot lock yourself out.')), $html);
        $this->assertStringNotContainsString('Whole ranges of addresses, one per line.', $html);
    }

    private function asVisitor(string $ip): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function owner(): User
    {
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', false);

        return $admin->fresh();
    }

    private function ban(string $ip, mixed $until = null): void
    {
        DB::table('ip_bans')->insert(['ip_address' => $ip, 'reason' => '', 'expires_at' => $until, 'created_at' => now(), 'updated_at' => now()]);
        BlockedAddresses::forget();
    }
}
