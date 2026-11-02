<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The side menu's cached list of followed channels (App\View\Composers\SidebarComposer) keeps the name, picture and
 * address of each channel, never its account data: the cache is a file on the server with the default store, and the
 * entry held every followed channel's password hash, e-mail address, phone, sign-in IP and social ids.
 */
class SidebarCacheSecretsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_cached_list_of_followed_channels_holds_no_account_data(): void
    {
        $member = User::factory()->create();
        $channel = User::factory()->create(['username' => 'followed_channel']);
        DB::table('subscriptions')->insert(['user_id' => $channel->id, 'subscriber_id' => $member->id, 'subscribe_at' => now()->subHour(), 'read_at' => now()]);

        $this->actingAs($member)->get(route('user.index'))->assertOk();

        $blob = '';
        foreach ((new ReflectionProperty(ArrayStore::class, 'storage'))->getValue(Cache::store()->getStore()) as $key => $entry) {
            if (str_starts_with((string) $key, 'sidebar.subscriptions.')) {
                $blob .= serialize($entry['value']);
            }
        }

        $this->assertStringContainsString('followed_channel', $blob, 'the followed channel is in the cached list');
        $row = DB::table('users')->where('id', $channel->id)->first();
        $this->assertStringNotContainsString((string) $row->email, $blob, 'no e-mail address in the cache');
        $this->assertStringNotContainsString((string) $row->password, $blob, 'no password hash in the cache');
    }
}
