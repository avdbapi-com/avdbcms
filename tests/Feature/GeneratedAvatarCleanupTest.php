<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AvdbCreatorAvatarFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * R1-096: a member whose stored avatar is an older generated badge gets the 1.2.3 initials avatar the next time it is
 * shown; the old badge's SVG stayed on the disk, one unused file per such member. The replaced file is removed once no
 * account points to it. Uploaded pictures and pack pictures are never removed.
 */
class GeneratedAvatarCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
    }

    /** The file name 1.2.2 gave a member's generated badge (style adult-brand-badge-v2). */
    private function oldBadge(string $username, string $source = 'user'): string
    {
        return 'avdb-creator-'.substr(hash('sha256', 'adult-brand-badge-v2|'.$source.'|user:'.strtolower($username)), 0, 24).'.svg';
    }

    public function test_the_older_badge_file_goes_when_the_new_avatar_replaces_it(): void
    {
        $member = User::factory()->create(['username' => 'old_badge', 'avatar' => null]);
        $old = $this->oldBadge('old_badge');
        Storage::put(User::AVATAR_FOLDER.'/'.$old, '<svg><text>CREATOR</text></svg>');
        $member->forceFill(['avatar' => $old])->saveQuietly();

        $new = AvdbCreatorAvatarFactory::ensureForUser($member->fresh());

        $this->assertNotSame($old, $new);
        $this->assertSame($new, $member->fresh()->avatar);
        Storage::assertExists(User::AVATAR_FOLDER.'/'.$new);
        Storage::assertMissing(User::AVATAR_FOLDER.'/'.$old);

        // shown again: nothing more changes
        $this->assertSame($new, AvdbCreatorAvatarFactory::ensureForUser($member->fresh()));
        Storage::assertExists(User::AVATAR_FOLDER.'/'.$new);
    }

    public function test_a_badge_another_account_still_points_to_stays(): void
    {
        $member = User::factory()->create(['username' => 'shared_one', 'avatar' => null]);
        $old = $this->oldBadge('shared_one');
        Storage::put(User::AVATAR_FOLDER.'/'.$old, '<svg/>');
        $member->forceFill(['avatar' => $old])->saveQuietly();
        // a hand-made row (a restored backup, an import) that uses the same file
        $other = User::factory()->create(['username' => 'other_one', 'avatar' => null]);
        DB::table('users')->where('id', $other->id)->update(['avatar' => $old]);

        AvdbCreatorAvatarFactory::ensureForUser($member->fresh());

        Storage::assertExists(User::AVATAR_FOLDER.'/'.$old);
    }

    public function test_an_uploaded_picture_and_a_pack_picture_are_never_removed(): void
    {
        $uploaded = User::factory()->create(['username' => 'has_photo', 'avatar' => null]);
        Storage::put(User::AVATAR_FOLDER.'/my-photo.jpg', 'jpeg');
        $uploaded->forceFill(['avatar' => 'my-photo.jpg'])->saveQuietly();
        $this->assertSame('my-photo.jpg', AvdbCreatorAvatarFactory::ensureForUser($uploaded->fresh()));
        Storage::assertExists(User::AVATAR_FOLDER.'/my-photo.jpg');

        // a member whose stored pack picture is gone gets a generated one; the other pack files stay
        $packed = User::factory()->create(['username' => 'had_pack', 'avatar' => null]);
        Storage::put(User::AVATAR_FOLDER.'/avdb-pack-0123456789abcdef01234567.png', 'png');
        $packed->forceFill(['avatar' => 'avdb-pack-ffffffffffffffffffffffff.png'])->saveQuietly();
        $this->assertNotSame('avdb-pack-ffffffffffffffffffffffff.png', AvdbCreatorAvatarFactory::ensureForUser($packed->fresh()));
        Storage::assertExists(User::AVATAR_FOLDER.'/avdb-pack-0123456789abcdef01234567.png');
    }
}
