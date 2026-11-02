<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Services\AdaptiveCreatorSpotlightService;
use App\Services\PublicVideoFeedService;
use App\Support\CachedUsers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use SplObjectStorage;
use Tests\TestCase;

/**
 * Nothing the site caches holds account data (security, round 1 R1-086). The default cache store is a file on the
 * server: these tests use the FILE store in a folder of their own, open the pages and lists that cache users rows (the
 * home page with its hero, shorts shelf and creator spotlight, search results, the side menu of a signed-in member with
 * followed channels and saved playlists, the admin's member list) and then read every file of the store back.
 *
 * Shown to visitors or kept for one member: only the public columns of a users row (App\Support\CachedUsers), never a
 * password hash, remember token, e-mail address, phone number or its code, sign-in address or social / payment id, also
 * not through a pivot's parent row (the side menu's lists held the signed-in member's own row that way). The admin's
 * own cached lists (the old admin's member list and dashboard) show e-mail addresses to administrators, but the cache
 * holds only the public columns: a list read from the cache gets the rest back from the database (round 1 review
 * RV-FC-06: the admin's lists kept e-mail addresses, phone numbers, sign-in addresses and social / payment ids).
 */
class CachedAccountDataTest extends TestCase
{
    use RefreshDatabase;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avd-cache-test-'.Str::lower(Str::random(10));
        config(['cache.default' => 'file', 'cache.stores.file.path' => $this->cacheDir]);
        app('cache')->forgetDriver(['file', 'array']);
        Cache::setDefaultDriver('file');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cacheDir);

        parent::tearDown();
    }

    public function test_public_lists_and_the_side_menu_cache_no_account_data(): void
    {
        [$member, $channels] = $this->accounts();

        // a guest: the home page (hero, shorts shelf, creator spotlight) and search results
        $this->get('/')->assertOk();
        $this->get('/search?q='.urlencode('Harbour'))->assertOk();
        app(PublicVideoFeedService::class)->heroVideos();
        app(PublicVideoFeedService::class)->heroShorts();
        app(AdaptiveCreatorSpotlightService::class)->creators();

        // a member: the side menu's followed channels and saved playlists (BelongsToMany lists with pivots)
        $this->actingAs($member)->get('/')->assertOk();
        $this->actingAs($member)->get(route('pages.trend'))->assertOk();

        $entries = $this->entries();
        $this->assertNotEmpty($entries['sidebar.subscriptions'] ?? [], 'the side menu cached its followed channels');
        $this->assertNotEmpty($entries['sidebar.favorite_playlists'] ?? [], 'the side menu cached its saved playlists');
        $this->assertNotEmpty($entries['users'] ?? [], 'users rows were cached at all (hero, spotlight, search, side menu)');

        $allowed = array_flip(CachedUsers::PUBLIC_COLUMNS);
        foreach ($entries['users'] as [$file, $path, $user]) {
            $extra = array_filter(array_keys($user->getAttributes()), fn (string $name): bool => ! isset($allowed[$name])
                && ! str_ends_with($name, '_count') && $name !== 'has_new_video');
            $this->assertSame([], array_values($extra), "a cached users row ({$path} in {$file}) holds only public columns");
        }
        $this->assertSame([], $entries['pivot_parents'], 'no cached pivot keeps the row it was read through');

        foreach ($this->secretsOf(array_merge([$member], $channels)) as $label => $needle) {
            foreach ($entries['raw'] as $file => $raw) {
                $this->assertFalse(str_contains($raw, $needle), "{$label} is in the cache file {$file}");
            }
        }
    }

    public function test_the_side_menu_still_shows_names_pictures_and_new_video_dots_from_the_cache(): void
    {
        [$member, $channels] = $this->accounts();

        $first = $this->actingAs($member)->get(route('pages.trend'))->assertOk()->getContent();
        // the second view reads the lists from the cache
        $second = $this->actingAs($member)->get(route('pages.trend'))->assertOk()->getContent();

        foreach ([$first, $second] as $html) {
            $this->assertStringContainsString($channels[0]->username, $html);
            $this->assertStringContainsString('avd-sidebar-dot', $html, 'the dot of a channel with a new video');
            $this->assertStringContainsString('Harbour lights playlist', $html, 'the saved public playlist');
        }
    }

    public function test_the_admins_cached_lists_hold_no_account_data_and_still_show_email_addresses(): void
    {
        [$member, $channels] = $this->accounts();
        $admin = User::factory()->admin()->create(['last_login_ip' => '198.51.100.9', 'phone' => '+15550009999']);
        config(['admin.ui' => 'v1']);

        // the old admin's member list and dashboard, each twice: the second view reads its list from the cache and
        // still shows every e-mail address (read back from the database)
        foreach ([route('admin.users.index'), route('admin.index')] as $page) {
            $first = $this->actingAs($admin)->get($page)->assertOk()->getContent();
            $second = $this->actingAs($admin)->get($page)->assertOk()->getContent();
            foreach ([$first, $second] as $html) {
                $this->assertStringContainsString(e($member->email), $html, $page);
                $this->assertStringContainsString(e($channels[1]->email), $html, $page);
            }
        }

        $entries = $this->entries();
        $this->assertTrue(collect($entries['raw'])->contains(fn (string $bytes): bool => str_contains($bytes, 'admin.cache.')
            || str_contains($bytes, $member->username)), 'the admin lists were cached');
        $cachedMember = collect($entries['users'])->first(fn (array $entry): bool => $entry[2]->getKey() === $member->getKey());
        $this->assertNotNull($cachedMember, 'the member list was cached with its users rows');

        // the users rows of the cached lists: public columns and the counts the query added, nothing else
        $allowed = array_flip(CachedUsers::PUBLIC_COLUMNS);
        foreach ($entries['users'] as [$file, $path, $user]) {
            $extra = array_filter(array_keys($user->getAttributes()), fn (string $name): bool => ! isset($allowed[$name])
                && ! str_ends_with($name, '_count') && $name !== 'has_new_video');
            $this->assertSame([], array_values($extra), "a cached users row ({$path} in {$file}) holds only public columns");
        }

        // no byte of any account's private data: e-mail and pending e-mail, phone and its code, sign-in address,
        // social and payment ids, password hash, remember token
        foreach ($this->secretsOf(array_merge([$member, $admin], $channels)) as $label => $needle) {
            foreach ($entries['raw'] as $file => $raw) {
                $this->assertFalse(str_contains($raw, $needle), "{$label} is in the cache file {$file}");
            }
        }
    }

    public function test_cached_users_keeps_public_columns_counts_and_drops_pivot_parents(): void
    {
        [$member, $channels] = $this->accounts();

        $list = $member->subscriptions()->withCount('subscribers')->get();
        $this->assertNotNull($list->first()->pivot->pivotParent, 'a fresh BelongsToMany pivot holds the member row');

        CachedUsers::publicOnly($list, ['has_new_video']);

        $channel = $list->first();
        $this->assertNull($channel->pivot->pivotParent);
        $this->assertArrayNotHasKey('email', $channel->getAttributes());
        $this->assertArrayNotHasKey('password', $channel->getAttributes());
        $this->assertArrayHasKey('subscribers_count', $channel->getAttributes());
        $this->assertSame($channels[0]->username, $list->firstWhere('id', $channels[0]->id)->username);
        $this->assertSame(route('user.show', User::query()->find($channel->id)), $channel->route, 'the channel address is the same');
        $this->assertNotNull($channel->pivot->subscribe_at, 'the pivot keeps its own columns');

        // the admin's lists: the cache gets a copy without the private columns (the caller's list is left whole), and a
        // list read back gets them again, never the secrets
        $admin = User::query()->whereKey($member->id)->withCount('subscriptions')->get();
        $copy = CachedUsers::forAdminCache($admin);
        $this->assertNotSame($admin, $copy);
        $this->assertSame($member->email, $admin->first()->email, 'the caller keeps its own, whole list');
        foreach (['email', 'temporary_email', 'phone', 'phone_confirmation_code', 'last_login_ip', 'google_id', 'facebook_id', 'stripe_id', 'password', 'remember_token'] as $column) {
            $this->assertArrayNotHasKey($column, $copy->first()->getAttributes(), $column);
        }
        $this->assertSame(2, (int) $copy->first()->subscriptions_count, 'a count the query added stays');

        $read = CachedUsers::restoreAdminRows(unserialize(serialize($copy)));
        $this->assertSame($member->email, $read->first()->email, 'read back: the e-mail address');
        $this->assertSame('203.0.113.10', $read->first()->last_login_ip);
        $this->assertArrayNotHasKey('password', $read->first()->getAttributes(), 'never the secrets');
        $this->assertArrayNotHasKey('remember_token', $read->first()->getAttributes());
        $this->assertSame(2, (int) $read->first()->subscriptions_count);
        $this->assertSame(['counts' => [1, 2]], CachedUsers::forAdminCache(['counts' => [1, 2]]), 'no users row: as it is');
    }

    /**
     * A member who follows two channels and saved two of their playlists (one public, one that went private), and
     * public videos of both channels. Every account carries every kind of account data.
     *
     * @return array{0: User, 1: list<User>}
     */
    private function accounts(): array
    {
        $secrets = fn (int $n): array => [
            'phone' => '+1555000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'phone_confirmation_code' => 'code-'.Str::random(12),
            'last_login_ip' => '203.0.113.'.$n,
            'google_id' => 'google-'.Str::random(16),
            'facebook_id' => 'facebook-'.Str::random(16),
            'stripe_id' => 'cus_'.Str::random(14),
            'temporary_email' => 'pending-'.Str::lower(Str::random(8)).'@example.test',
            'remember_token' => Str::random(40),
        ];
        $member = User::factory()->create(['username' => 'cache_member'] + $secrets(10));
        $channels = [
            User::factory()->create(['username' => 'cache_channel_one', 'show_subscribers' => true] + $secrets(11)),
            User::factory()->create(['username' => 'cache_channel_two', 'show_subscribers' => true] + $secrets(12)),
        ];

        foreach ($channels as $i => $channel) {
            Video::factory()->withStatus(VideoStatus::PUBLIC)->count(3)->create([
                'user_id' => $channel->id, 'title' => 'Harbour walk '.($i + 1), 'views' => 100 + $i, 'duration' => 60 + $i,
                'published_at' => now()->subHours(2), 'language' => null,
            ]);
            DB::table('subscriptions')->insert(['user_id' => $channel->id, 'subscriber_id' => $member->id,
                'subscribe_at' => now()->subDays(2), 'read_at' => now()->subDays(3)]);
        }

        foreach ([[0, 'Harbour lights playlist', PlaylistStatus::PUBLIC], [1, 'Made private later', PlaylistStatus::PRIVATE]] as [$i, $title, $status]) {
            $playlist = Playlist::query()->create(['uuid' => (string) Str::uuid(), 'title' => $title, 'user_id' => $channels[$i]->id,
                'status' => $status, 'is_deletable' => true]);
            DB::table('favorites_playlist')->insert(['user_id' => $member->id, 'playlist_id' => $playlist->id, 'added_at' => now()->subDay()]);
        }

        return [$member, $channels];
    }

    /**
     * Every value of these accounts that no cache file may hold.
     *
     * @param  list<User>  $users
     * @return array<string, string>
     */
    private function secretsOf(array $users): array
    {
        $needles = [];
        foreach ($users as $user) {
            $row = (array) DB::table('users')->where('id', $user->id)->first();
            foreach (['password', 'remember_token', 'email', 'temporary_email', 'phone', 'phone_confirmation_code', 'last_login_ip', 'google_id', 'facebook_id', 'stripe_id'] as $column) {
                if (is_string($row[$column] ?? null) && $row[$column] !== '') {
                    $needles["{$column} of user {$user->id}"] = $row[$column];
                }
            }
        }

        return $needles;
    }

    /**
     * Every file of the store: its bytes, the users rows found anywhere in its value (relations, pivots, pivot parents),
     * the pivots that still hold a parent row, and which side menu lists are there.
     *
     * @return array{raw: array<string, string>, users: list<array{0: string, 1: string, 2: User}>, pivot_parents: list<string>, sidebar.subscriptions?: list<string>, sidebar.favorite_playlists?: list<string>}
     */
    private function entries(): array
    {
        $out = ['raw' => [], 'users' => [], 'pivot_parents' => []];

        foreach (File::allFiles($this->cacheDir) as $file) {
            $bytes = (string) file_get_contents($file->getPathname());
            $name = $file->getRelativePathname();
            $out['raw'][$name] = $bytes;
            $value = @unserialize(substr($bytes, 10));

            if (str_contains($bytes, 'has_new_video')) {
                $out['sidebar.subscriptions'][] = $name;
            }
            if (str_contains($bytes, 'Harbour lights playlist') && str_contains($bytes, 'FavoritePlaylist')) {
                $out['sidebar.favorite_playlists'][] = $name;
            }

            $seen = new SplObjectStorage();
            $walk = function (mixed $node, string $path) use (&$walk, &$out, $seen, $name): void {
                if (is_object($node)) {
                    if ($seen->contains($node)) {
                        return;
                    }
                    $seen->attach($node);
                }
                if ($node instanceof User) {
                    $out['users'][] = [$name, $path, $node];
                }
                if ($node instanceof Model) {
                    foreach ($node->getRelations() as $relation => $related) {
                        $walk($related, $path.'->'.$relation);
                    }
                    if (property_exists($node, 'pivotParent') && $node->pivotParent !== null) {
                        $out['pivot_parents'][] = $name.' '.$path;
                        $walk($node->pivotParent, $path.'->pivotParent');
                    }

                    return;
                }
                if ($node instanceof Collection) {
                    foreach ($node->all() as $i => $item) {
                        $walk($item, $path.'['.$i.']');
                    }

                    return;
                }
                if (is_object($node) && method_exists($node, 'getCollection')) {
                    $walk($node->getCollection(), $path.'->items');

                    return;
                }
                if (is_array($node)) {
                    foreach ($node as $i => $item) {
                        $walk($item, $path.'['.$i.']');
                    }
                }
            };
            $walk($value, 'entry');
        }

        return $out;
    }
}
