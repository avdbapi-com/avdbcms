<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Support\AdminPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * R3-009 / R3-010: a site installed from the bundled database (Import SQL backup) has 24 foreign keys a site installed by
 * the migrations has not (comments, playlists, their rows, subtitles, videos' category ...). Deleting a member, a video, a
 * category, a playlist or a comment behaves the same on both: the site removes (or keeps) itself what those keys would.
 * The test database is the migrations' (no such keys), so before the fixes each case here left rows behind; the bundled
 * database's own behaviour is proven on MariaDB and MySQL by tools/scale/delete_probe.php (lane DB's runs).
 */
class DeleteBehaviourBothInstallModesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
    }

    public function test_a_category_whose_only_videos_are_in_the_bin_is_deleted_and_they_lose_it(): void
    {
        $category = $this->category('Bin only');
        $binned = $this->video(User::factory()->create(), ['category_id' => $category->id]);
        $binned->delete();

        $this->actingAs($this->admin())->delete(route('admin.categories.destroy', $category))
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertNull(DB::table('videos')->where('id', $binned->id)->value('category_id'), 'the binned video lost the category with it');
        $this->assertSame(0, DB::table('videos')->whereNotNull('category_id')->whereNotIn('category_id', DB::table('categories')->select('id'))->count(),
            'no video points at a category that no longer exists');
    }

    public function test_a_category_with_a_video_outside_the_bin_still_cannot_be_deleted(): void
    {
        $category = $this->category('In use');
        $this->video(User::factory()->create(), ['category_id' => $category->id]);
        $binned = $this->video(User::factory()->create(), ['category_id' => $category->id]);
        $binned->delete();

        $this->actingAs($this->admin())->delete(route('admin.categories.destroy', $category))
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertSame($category->id, (int) DB::table('videos')->where('id', $binned->id)->value('category_id'), 'nothing changed');
    }

    public function test_a_comment_deleted_for_good_takes_every_reply_under_it_and_their_likes(): void
    {
        $video = $this->video(User::factory()->create());
        $parent = $this->comment($video, User::factory()->create());
        $reply = $this->comment($video, User::factory()->create(), $parent);
        $binnedReply = $this->comment($video, User::factory()->create(), $parent);
        $binnedReply->delete();
        $deeper = $this->comment($video, User::factory()->create(), $reply);
        $kept = $this->comment($video, User::factory()->create());
        $like = DB::table('interactions')->insertGetId(['user_id' => User::factory()->create()->id, 'likeable_type' => (new Comment())->getMorphClass(),
            'likeable_id' => $reply->id, 'status' => 1, 'perform_at' => now()]);

        $parent->forceDelete();

        $this->assertSame([$kept->id], DB::table('comments')->where('video_id', $video->id)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'the replies, the binned one and the reply under a reply went with it');
        $this->assertDatabaseMissing('interactions', ['id' => $like]);
        $this->assertSame(1, (int) DB::table('videos')->where('id', $video->id)->value('comments_count'), 'the video counts what is left');
        $this->assertNotNull($deeper->id);
    }

    public function test_a_member_deleted_for_good_takes_the_replies_under_their_comments_and_the_saves_of_their_playlists(): void
    {
        $leaving = User::factory()->create();
        $video = $this->video(User::factory()->create());
        $theirs = $this->comment($video, $leaving);
        $answer = $this->comment($video, User::factory()->create(), $theirs);
        $other = $this->comment($video, User::factory()->create());
        $playlist = Playlist::factory()->for($leaving)->create(['status' => PlaylistStatus::PUBLIC]);
        $playlist->videos()->attach($video->id, ['position' => 0]);
        $fan = User::factory()->create();
        DB::table('favorites_playlist')->insert(['user_id' => $fan->id, 'playlist_id' => $playlist->id, 'added_at' => now()]);

        $this->actingAs($this->admin())->delete(route('admin.users.delete', $leaving))->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $leaving->id]);
        $this->assertDatabaseMissing('comments', ['id' => $theirs->id]);
        $this->assertDatabaseMissing('comments', ['id' => $answer->id]);
        $this->assertDatabaseHas('comments', ['id' => $other->id]);
        $this->assertSame(1, (int) DB::table('videos')->where('id', $video->id)->value('comments_count'));
        $this->assertSame(0, DB::table('favorites_playlist')->where('playlist_id', $playlist->id)->count(), 'the others\' saves of the member\'s playlist');
        $this->assertSame(0, DB::table('playlist_has_videos')->where('playlist_id', $playlist->id)->count());
    }

    public function test_a_video_deleted_for_good_takes_its_subtitles_and_every_view(): void
    {
        $video = $this->video(User::factory()->create());
        $other = $this->video(User::factory()->create());
        $viewer = User::factory()->create();
        foreach ([$video, $other] as $owner) {
            DB::table('subtitles')->insert(['video_id' => $owner->id, 'name' => 'English', 'language' => 'en', 'file' => 'en.vtt', 'status' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('views')->insert(['video_id' => $owner->id, 'user_id' => $viewer->id, 'ip' => '127.0.0.1', 'user_agent' => 'test', 'view_at' => now()]);
            DB::table('views')->insert(['video_id' => $owner->id, 'user_id' => $viewer->id, 'ip' => '127.0.0.1', 'user_agent' => 'test', 'view_at' => now(), 'deleted_at' => now()]);
        }

        $video->forceDelete();

        $this->assertSame(0, DB::table('subtitles')->where('video_id', $video->id)->count());
        $this->assertSame(0, DB::table('views')->where('video_id', $video->id)->count(), 'every view row, also one in the bin');
        $this->assertSame(1, DB::table('subtitles')->where('video_id', $other->id)->count());
        $this->assertSame(2, DB::table('views')->where('video_id', $other->id)->count());
    }

    public function test_a_playlist_deleted_for_good_any_way_takes_its_rows_and_the_saves_of_others(): void
    {
        $owner = User::factory()->create();
        $video = $this->video(User::factory()->create());
        $fan = User::factory()->create();
        $make = function () use ($owner, $video, $fan): Playlist {
            $playlist = Playlist::factory()->for($owner)->create(['status' => PlaylistStatus::PUBLIC, 'is_deletable' => true]);
            $playlist->videos()->attach($video->id, ['position' => 0]);
            DB::table('favorites_playlist')->insert(['user_id' => $fan->id, 'playlist_id' => $playlist->id, 'added_at' => now()]);

            return $playlist;
        };

        $studio = $make();
        $this->actingAs($owner)->delete(route('user.playlists.destroy', $studio))->assertRedirect();
        $admin = $make();
        $this->actingAs($this->admin())->delete(route('admin.playlists.delete', $admin))->assertRedirect();
        $model = $make();
        $model->forceDelete();
        $binned = $make();
        $binned->delete();
        $this->artisan('cleanup:purge-trashed')->assertSuccessful();

        foreach ([$studio, $admin, $model, $binned] as $playlist) {
            $this->assertDatabaseMissing('playlists', ['id' => $playlist->id]);
            $this->assertSame(0, DB::table('favorites_playlist')->where('playlist_id', $playlist->id)->count(), 'the saves of #'.$playlist->id);
            $this->assertSame(0, DB::table('playlist_has_videos')->where('playlist_id', $playlist->id)->count(), 'the rows of #'.$playlist->id);
        }
    }

    public function test_unconfirmed_accounts_go_with_their_rows_and_confirmed_ones_stay(): void
    {
        config(['site.email_verification_required' => true]);
        $channel = User::factory()->create();
        $video = $this->video($channel);
        $old = User::factory()->unverified()->create(['created_at' => now()->subDays(3)]);
        $recent = User::factory()->unverified()->create(['created_at' => now()->subMinutes(30)]);
        $confirmed = User::factory()->create(['created_at' => now()->subDays(3)]);
        foreach ([$old, $recent, $confirmed] as $user) {
            Playlist::factory()->for($user)->create(['title' => 'Watch Later', 'is_deletable' => false]);
            DB::table('views')->insert(['video_id' => $video->id, 'user_id' => $user->id, 'ip' => '127.0.0.1', 'user_agent' => 'test', 'view_at' => now()]);
            DB::table('subscriptions')->insert(['subscriber_id' => $user->id, 'user_id' => $channel->id, 'subscribe_at' => now(), 'read_at' => now()]);
        }

        $this->artisan('users:delete-unconfirmed')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $old->id]);
        $this->assertSame(0, DB::table('playlists')->where('user_id', $old->id)->count(), 'its playlists');
        $this->assertSame(0, DB::table('views')->where('user_id', $old->id)->count(), 'its views');
        $this->assertSame(0, DB::table('subscriptions')->where('subscriber_id', $old->id)->count(), 'its subscriptions');
        foreach ([$recent, $confirmed] as $user) {
            $this->assertDatabaseHas('users', ['id' => $user->id]);
            $this->assertSame(1, DB::table('playlists')->where('user_id', $user->id)->count());
            $this->assertSame(1, DB::table('views')->where('user_id', $user->id)->count());
        }
    }

    public function test_the_clean_up_keeps_an_unconfirmed_account_that_has_videos_and_one_failure_stops_no_other(): void
    {
        config(['site.email_verification_required' => true]);
        // an established member an administrator marked "not confirmed" (uploading needs a confirmed address)
        $member = User::factory()->unverified()->create(['created_at' => now()->subYear()]);
        $kept = $this->video($member);
        $binnedOnly = User::factory()->unverified()->create(['created_at' => now()->subYear()]);
        $this->video($binnedOnly)->delete();
        $failing = User::factory()->unverified()->create(['created_at' => now()->subDays(3)]);
        $spam = User::factory()->unverified()->create(['created_at' => now()->subDays(3)]);
        User::forceDeleting(function (User $user) use ($failing): void {
            if ($user->is($failing)) {
                throw new \RuntimeException('r3db: this account cannot go');
            }
        });

        $this->artisan('users:delete-unconfirmed')->expectsOutputToContain('1 could not be deleted')->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $member->id]);
        $this->assertDatabaseHas('videos', ['id' => $kept->id]);
        $this->assertDatabaseHas('users', ['id' => $binnedOnly->id]);
        $this->assertDatabaseHas('users', ['id' => $failing->id]);
        $this->assertDatabaseMissing('users', ['id' => $spam->id]);
    }

    private function admin(): User
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'admin_ui', 'v2');

        return $admin;
    }

    private function category(string $title): Category
    {
        return Category::query()->create(['title' => $title, 'slug' => str($title)->slug()->toString(), 'icon' => 'folder', 'in_menu' => true]);
    }

    private function video(User $owner, array $attributes = []): Video
    {
        return Video::factory()->for($owner)->withStatus(VideoStatus::PUBLIC)->create(array_merge(['uploaded_at' => now()->subDay(), 'published_at' => now()->subDay()], $attributes));
    }

    private function comment(Video $video, User $author, ?Comment $parent = null): Comment
    {
        return Comment::query()->create(['video_id' => $video->id, 'user_id' => $author->id, 'parent_id' => $parent?->id, 'content' => 'Nice one', 'ip' => '127.0.0.1']);
    }
}
