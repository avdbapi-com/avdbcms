<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvdbCreatorAvatarFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_avatar_access_reuses_hydrated_creator_candidate(): void
    {
        Storage::fake('local');

        $created = User::factory()->create([
            'username' => 'Hydrated Creator',
            'avatar' => null,
            'avdb_creator_key' => null,
            'avdb_creator_source' => null,
            'avdb_creator_name' => null,
        ]);

        // Discovery and API lists intentionally select a narrow user column
        // set. Rehydrate that shape so the first avatar access must resolve
        // the omitted creator metadata, while later accesses can reuse it.
        $user = User::query()
            ->select(['id', 'username', 'avatar', 'is_admin'])
            ->findOrFail($created->getKey());

        $userLookups = 0;
        DB::listen(function (QueryExecuted $query) use (&$userLookups): void {
            $sql = strtolower($query->sql);

            if (str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'users')) {
                $userLookups++;
            }
        });

        $firstAvatarUrl = $user->avatar_url;
        $firstCandidate = [
            'key' => $user->avdb_creator_key,
            'source' => $user->avdb_creator_source,
            'name' => $user->avdb_creator_name,
        ];
        $secondAvatarUrl = $user->avatar_url;

        $this->assertSame($firstAvatarUrl, $secondAvatarUrl);
        $this->assertSame('user:hydrated creator', $firstCandidate['key']);
        $this->assertSame('user', $firstCandidate['source']);
        $this->assertSame('Hydrated Creator', $firstCandidate['name']);
        $this->assertSame(1, $userLookups);

        foreach (array_keys($firstCandidate) as $attribute) {
            $this->assertFalse($user->isDirty('avdb_creator_'.$attribute));
        }
    }

    public function test_a_fully_loaded_member_renders_its_avatar_without_reading_its_row_again(): void
    {
        Storage::fake('local');

        $created = User::factory()->create(['username' => 'Loaded Member']);
        // Comment, subscriber and channel lists load whole rows: an ordinary member's creator key is loaded as NULL.
        $member = User::query()->findOrFail($created->getKey());
        $this->assertNull($member->avdb_creator_key);
        $this->assertNotNull($member->avatar);

        $userLookups = $this->countUserLookups();
        $member->avatar_url;

        $this->assertSame(0, $userLookups());
        $this->assertSame('user:loaded member', $member->avdb_creator_key);
        $this->assertSame('user', $member->avdb_creator_source);
    }

    public function test_a_narrow_admin_model_gets_the_same_avatar_candidate_as_the_full_one(): void
    {
        Storage::fake('local');

        $admin = User::factory()->admin()->create(['username' => 'Site Owner']);
        $full = User::query()->findOrFail($admin->getKey());
        $narrow = User::query()->select(['id', 'username', 'avatar'])->findOrFail($admin->getKey());

        $full->avatar_url;
        $narrow->avatar_url;

        $this->assertSame('admin', $full->avdb_creator_source);
        $this->assertSame($full->avdb_creator_key, $narrow->avdb_creator_key);
        $this->assertSame($full->avdb_creator_source, $narrow->avdb_creator_source);
    }

    public function test_a_cached_member_never_replaces_an_avatar_uploaded_since(): void
    {
        Storage::fake('local');

        $created = User::factory()->create(['username' => 'Cached Member']);
        // A member without an avatar (an older database), as a cached home rail or hero still holds them ...
        DB::table('users')->where('id', $created->getKey())->update(['avatar' => null]);
        $cached = User::query()->findOrFail($created->getKey());
        // ... who uploads a picture of their own while that copy is still cached.
        Storage::put(User::AVATAR_FOLDER.'/own-picture.jpg', 'jpeg-bytes');
        DB::table('users')->where('id', $created->getKey())->update(['avatar' => 'own-picture.jpg']);

        $avatarUrl = $cached->avatar_url;

        $this->assertSame('own-picture.jpg', DB::table('users')->where('id', $created->getKey())->value('avatar'));
        $this->assertSame('own-picture.jpg', $cached->avatar);
        $this->assertStringContainsString('v='.substr(sha1('own-picture.jpg'), 0, 16), $avatarUrl);
    }

    /** @return callable(): int  the number of SELECTs on the users table since this call */
    private function countUserLookups(): callable
    {
        $lookups = 0;
        DB::listen(function (QueryExecuted $query) use (&$lookups): void {
            $sql = strtolower($query->sql);

            if (str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'users')) {
                $lookups++;
            }
        });

        return function () use (&$lookups): int {
            return $lookups;
        };
    }
}
