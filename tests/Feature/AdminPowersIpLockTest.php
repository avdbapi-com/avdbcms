<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Playlist;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Video;
use App\Support\AdminIpLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Security plan S14: with an admin IP list set (Settings > Security: "Only these internet addresses can open the
 * admin"), an administrator's powers outside /admin (other members' private videos, video edits, invoices, private
 * playlists) work only from an address on the list, like the admin itself. From anywhere else an administrator is an
 * ordinary member there. An empty list changes nothing; commands and queued jobs are unchanged.
 */
class AdminPowersIpLockTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_IP = '203.0.113.7';

    private const ELSEWHERE = '198.51.100.1';

    public function test_from_an_address_not_on_the_list_an_administrator_is_an_ordinary_member_outside_the_admin(): void
    {
        config(['site.admin_ip_whitelist' => self::OWNER_IP]);
        [$admin, $video, $transaction, $playlist] = $this->membersPrivateThings();

        $as = fn (string $ip) => $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => $ip]);

        $as(self::ELSEWHERE)->get($video->route)->assertNotFound();
        // like any member: another member's video is not found on the studio's routes (round 2a R2A-062)
        $as(self::ELSEWHERE)->get(route('user.videos.create', $video))->assertNotFound();
        $as(self::ELSEWHERE)->put(route('user.videos.update', $video), ['title' => 'Changed by an admin'])->assertNotFound();
        $as(self::ELSEWHERE)->get(route('user.invoices.show', $transaction))->assertNotFound();
        $as(self::ELSEWHERE)->get(route('playlist.show', $playlist))->assertNotFound();
        $as(self::ELSEWHERE)->getJson(route('playlists.show', $playlist))->assertNotFound();
        $this->assertNotSame('Changed by an admin', $video->fresh()->title);
    }

    public function test_from_an_address_on_the_list_everything_works_as_before(): void
    {
        config(['site.admin_ip_whitelist' => self::OWNER_IP]);
        [$admin, $video, $transaction, $playlist] = $this->membersPrivateThings();

        $as = fn () => $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => self::OWNER_IP]);

        $as()->get($video->route)->assertOk();
        // Allowed: a video that is no draft any more goes on to its edit page.
        $as()->get(route('user.videos.create', $video))->assertRedirect(route('user.videos.edit', $video));
        $as()->get(route('user.invoices.show', $transaction))->assertOk();
        $as()->get(route('playlist.show', $playlist))->assertOk();
        $as()->getJson(route('playlists.show', $playlist))->assertOk();
    }

    public function test_an_empty_list_keeps_the_powers_everywhere(): void
    {
        config(['site.admin_ip_whitelist' => '']);
        [$admin, $video, $transaction, $playlist] = $this->membersPrivateThings();

        $as = fn () => $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => self::ELSEWHERE]);

        $as()->get($video->route)->assertOk();
        $as()->get(route('user.invoices.show', $transaction))->assertOk();
        $as()->getJson(route('playlists.show', $playlist))->assertOk();
    }

    public function test_commands_and_queued_jobs_are_unchanged(): void
    {
        config(['site.admin_ip_whitelist' => self::OWNER_IP]);
        $admin = User::factory()->admin()->create();

        // No routed request: a command, a queued job or the scheduler acts for the site itself.
        $this->app->instance('request', \Illuminate\Http\Request::create('http://localhost/', 'GET', server: ['REMOTE_ADDR' => self::ELSEWHERE]));
        $this->assertTrue(AdminIpLock::adminPowersHere($admin));
        $this->assertFalse(AdminIpLock::adminPowersHere(User::factory()->create()));
        $this->assertFalse(AdminIpLock::adminPowersHere(null));
    }

    /** @return array{0: User, 1: Video, 2: Transaction, 3: Playlist} an administrator and a member's private things */
    private function membersPrivateThings(): array
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $video = Video::factory()->for($member)->withStatus(VideoStatus::PRIVATE)->create(['uploaded_at' => now()->subHour()]);
        $id = DB::table('transactions')->insertGetId([
            'payment_provider' => 'stripe', 'stripe_id' => 'ch_s14_'.$member->id, 'amount' => 999, 'tax' => 0, 'fee' => 0,
            'date' => '2026-10-01 10:00:00', 'user_id' => $member->id, 'name' => 'Member', 'address' => 'Street 1',
            'city' => 'Town', 'postal_code' => '1000', 'country' => 'de',
        ]);
        $transaction = Transaction::query()->findOrFail($id);
        Storage::put($transaction->invoice_path, "%PDF-1.4\n");
        $this->beforeApplicationDestroyed(fn () => Storage::delete($transaction->invoice_path));
        $playlist = Playlist::factory()->for($member)->create(['status' => PlaylistStatus::PRIVATE]);

        return [$admin, $video, $transaction, $playlist];
    }
}
