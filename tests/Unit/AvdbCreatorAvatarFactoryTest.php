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
}
