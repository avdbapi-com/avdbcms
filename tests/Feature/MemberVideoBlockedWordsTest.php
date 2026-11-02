<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Tag;
use App\Models\User;
use App\Models\Video;
use App\Support\ContentSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 1.2.3 round 3 (lane KS), the members' videos and the words that are always blocked (App\Support\ContentSafety's hard
 * list, never the owner's own import list):
 *
 * KS-F1: Video details refuses a category visitors never see (its name uses such a word) exactly like a category that
 * does not exist, unless it is the video's own (an unchanged form still saves). The draft page too.
 * KS-F3 (decided: the safest default): a video whose title, tags or category use such a word is not published, made
 * unlisted or scheduled, and while it is out its title or category is not changed to one: Video details, the draft
 * page's Publish, the studio's bulk visibility, the admin's video page and the admin's bulk bar. The refusal names the
 * field, never the words. Private saves, the upload and an admin's Ban and Unban are not changed.
 *
 * Test data: neutral titles and names with one listed word.
 */
class MemberVideoBlockedWordsTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private Category $garden;

    private Category $hidden;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        ContentSafety::forget();
        $this->creator = User::factory()->create(['email_verified_at' => now()]);
        $this->garden = Category::create(['title' => 'QA garden category', 'slug' => 'qa-garden-category', 'in_menu' => true, 'position' => 1]);
        $this->hidden = Category::create(['title' => 'QA child category', 'slug' => 'qa-child-category', 'in_menu' => true, 'position' => 2]);
    }

    // ------------------------------------------------------------------------------------------------------- KS-F1

    public function test_video_details_refuse_a_hidden_category_like_a_missing_one(): void
    {
        $video = $this->video('QA evening walk', VideoStatus::PRIVATE, $this->garden);

        $missing = $this->actingAs($this->creator)->from(route('user.videos.edit', $video))
            ->put(route('user.videos.update', $video), $this->details($video, ['category_id' => 999999]))
            ->assertSessionHasErrors('category_id');
        $missingMessage = session('errors')->first('category_id');

        $this->actingAs($this->creator)->from(route('user.videos.edit', $video))
            ->put(route('user.videos.update', $video), $this->details($video, ['category_id' => $this->hidden->id]))
            ->assertSessionHasErrors('category_id');
        $this->assertSame($missingMessage, session('errors')->first('category_id'), 'the same answer as a category that does not exist');
        $this->assertSame($this->garden->id, (int) $video->fresh()->category_id);

        // the video's own category, even a hidden one, keeps an unchanged form saving (a private video)
        $own = $this->video('QA quiet morning', VideoStatus::PRIVATE, $this->hidden);
        $this->actingAs($this->creator)->put(route('user.videos.update', $own), $this->details($own, ['description' => 'Changed.']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Changed.', $own->fresh()->description);
    }

    public function test_the_draft_page_refuses_a_hidden_category_like_a_missing_one(): void
    {
        $draft = $this->video('QA new upload', VideoStatus::DRAFT, null);

        $this->actingAs($this->creator)->post(route('user.videos.store', $draft), $this->details($draft, ['status' => VideoStatus::PRIVATE->value, 'category_id' => 999999]))
            ->assertSessionHasErrors('category_id');
        $missingMessage = session('errors')->first('category_id');

        $this->actingAs($this->creator)->post(route('user.videos.store', $draft), $this->details($draft, ['action' => 'draft', 'category_id' => $this->hidden->id]))
            ->assertSessionHasErrors('category_id');
        $this->assertSame($missingMessage, session('errors')->first('category_id'));
        $this->assertNull($draft->fresh()->category_id);
    }

    // ------------------------------------------------------------------------------------------------------- KS-F3

    public function test_video_details_never_put_a_blocked_title_in_front_of_visitors(): void
    {
        $video = $this->video('QA evening walk', VideoStatus::PRIVATE, $this->garden);

        foreach ([VideoStatus::PUBLIC, VideoStatus::UNLISTED] as $status) {
            $this->actingAs($this->creator)->put(route('user.videos.update', $video), $this->details($video, ['title' => 'QA child test video', 'status' => $status->value]))
                ->assertSessionHasErrors(['title' => 'This title uses words that are not allowed on this site.']);
            $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status);
            $this->assertSame('QA evening walk', $video->fresh()->getRawOriginal('title'));
        }

        // scheduled: it would go public by itself
        $this->actingAs($this->creator)->put(route('user.videos.update', $video), $this->details($video, [
            'title' => 'QA child test video', 'status' => VideoStatus::PLANNED->value, 'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i'),
        ]))->assertSessionHasErrors('title');
        $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status);

        // private: the member may keep any title for themselves
        $this->actingAs($this->creator)->put(route('user.videos.update', $video), $this->details($video, ['title' => 'QA child test video', 'status' => VideoStatus::PRIVATE->value]))
            ->assertSessionHasNoErrors();
        $this->assertSame('QA child test video', $video->fresh()->getRawOriginal('title'));

        // ... and cannot publish it so
        $this->actingAs($this->creator)->put(route('user.videos.update', $video), $this->details($video->fresh(), ['status' => VideoStatus::PUBLIC->value]))
            ->assertSessionHasErrors('title');
        $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status);

        // a clean title goes public as before
        $this->actingAs($this->creator)->put(route('user.videos.update', $video), $this->details($video, ['title' => 'QA evening walk', 'status' => VideoStatus::PUBLIC->value]))
            ->assertSessionHasNoErrors();
        $this->assertSame(VideoStatus::PUBLIC, $video->fresh()->status);
    }

    public function test_a_public_video_never_takes_a_blocked_title_and_keeps_what_an_admin_let_through(): void
    {
        $public = $this->video('QA evening walk', VideoStatus::PUBLIC, $this->garden);
        $this->actingAs($this->creator)->put(route('user.videos.update', $public), $this->details($public, ['title' => 'QA kid test video']))
            ->assertSessionHasErrors(['title' => 'This title uses words that are not allowed on this site.']);
        $this->assertSame('QA evening walk', $public->fresh()->getRawOriginal('title'));

        // a public video whose title an admin let through after a review (Unban): its other details still save
        $reviewed = $this->video('QA kid test video', VideoStatus::PUBLIC, $this->garden);
        $this->actingAs($this->creator)->put(route('user.videos.update', $reviewed), $this->details($reviewed, ['description' => 'Changed.']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Changed.', $reviewed->fresh()->description);
    }

    public function test_a_hidden_category_or_tag_keeps_a_video_from_going_out(): void
    {
        // in a category that was renamed to a hidden name: its other details save, but it does not go public in it
        $video = $this->video('QA quiet morning', VideoStatus::PRIVATE, $this->hidden);
        $this->actingAs($this->creator)->put(route('user.videos.update', $video), $this->details($video, ['status' => VideoStatus::PUBLIC->value]))
            ->assertSessionHasErrors(['category_id' => 'This category uses words that are not allowed on this site. Choose another one.']);
        $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status);
        $this->actingAs($this->creator)->put(route('user.videos.update', $video), $this->details($video, ['status' => VideoStatus::PUBLIC->value, 'category_id' => $this->garden->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame(VideoStatus::PUBLIC, $video->fresh()->status);

        // a tag visitors never see (members do not set tags; an import or the admin's tag merge can give one)
        $tagged = $this->video('QA bright afternoon', VideoStatus::PRIVATE, $this->garden);
        $tagged->tags()->attach(Tag::create(['name' => 'QA child tag', 'slug' => 'qa-child-tag', 'usage_count' => 1])->id);
        $this->actingAs($this->creator)->put(route('user.videos.update', $tagged), $this->details($tagged, ['status' => VideoStatus::UNLISTED->value]))
            ->assertSessionHasErrors(['status' => 'A tag of this video uses words that are not allowed on this site, so it cannot be public, unlisted or scheduled.']);
        $this->assertSame(VideoStatus::PRIVATE, $tagged->fresh()->status);
    }

    public function test_the_draft_page_publishes_no_blocked_title_and_still_saves_the_draft(): void
    {
        $draft = $this->video('QA new upload', VideoStatus::DRAFT, null);

        $this->actingAs($this->creator)->post(route('user.videos.store', $draft), $this->details($draft, ['title' => 'QA child test video', 'status' => VideoStatus::PUBLIC->value]))
            ->assertSessionHasErrors(['title' => 'This title uses words that are not allowed on this site.']);
        $this->assertSame(VideoStatus::DRAFT, $draft->fresh()->status);

        // "Save draft" keeps it as a draft: nothing goes out
        $this->actingAs($this->creator)->post(route('user.videos.store', $draft), $this->details($draft, ['title' => 'QA child test video', 'action' => 'draft']))
            ->assertSessionHasNoErrors();
        $this->assertSame(VideoStatus::DRAFT, $draft->fresh()->status);

        $this->actingAs($this->creator)->post(route('user.videos.store', $draft), $this->details($draft, ['title' => 'QA new upload', 'status' => VideoStatus::PUBLIC->value]))
            ->assertSessionHasNoErrors();
        $this->assertSame(VideoStatus::PUBLIC, $draft->fresh()->status);
    }

    public function test_the_draft_pages_save_gives_a_video_that_is_out_no_blocked_title(): void
    {
        // "Save draft" and "Finish later" of a draft page left open after the video went out (or a hand-made request):
        // the route lets the owner save any video of theirs, so a public, unlisted or scheduled one meets the same rule
        // as on Video details (its title and category; its other details still save)
        foreach ([VideoStatus::PUBLIC, VideoStatus::UNLISTED, VideoStatus::PLANNED] as $status) {
            $video = $this->video('QA evening walk', $status, $this->garden);

            foreach (['draft', 'later'] as $action) {
                $this->actingAs($this->creator)->post(route('user.videos.store', $video), $this->details($video, [
                    'title' => 'QA child test video', 'status' => VideoStatus::PRIVATE->value, 'action' => $action,
                ]))->assertSessionHasErrors(['title' => 'This title uses words that are not allowed on this site.']);
                $this->assertSame('QA evening walk', $video->fresh()->getRawOriginal('title'), $status->name.' / '.$action);
                $this->assertSame($status, $video->fresh()->status);
            }

            $this->actingAs($this->creator)->post(route('user.videos.store', $video), $this->details($video, [
                'description' => 'Changed.', 'status' => VideoStatus::PRIVATE->value, 'action' => 'draft',
            ]))->assertSessionHasNoErrors();
            $this->assertSame('Changed.', $video->fresh()->description);
        }

        // a public video whose title an admin let through after a review keeps saving its other details there too
        $reviewed = $this->video('QA kid test video', VideoStatus::PUBLIC, $this->garden);
        $this->actingAs($this->creator)->post(route('user.videos.store', $reviewed), $this->details($reviewed, [
            'description' => 'Changed.', 'status' => VideoStatus::PRIVATE->value, 'action' => 'later',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('Changed.', $reviewed->fresh()->description);
        $this->assertSame(VideoStatus::PUBLIC, $reviewed->fresh()->status);
    }

    public function test_the_studios_bulk_visibility_keeps_a_blocked_video_where_it_is(): void
    {
        $clean = $this->video('QA evening walk', VideoStatus::PRIVATE, $this->garden);
        $blocked = $this->video('QA child test video', VideoStatus::PRIVATE, $this->garden);

        $this->actingAs($this->creator)->post(route('user.videos.mass-visibility'), ['ids' => [$clean->id, $blocked->id], 'visibility' => 'public'])
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '1 video is public now.')
                && str_contains($message, '1 video kept its visibility: its title, tags or category use words that are not allowed on this site.'));

        $this->assertSame(VideoStatus::PUBLIC, $clean->fresh()->status);
        $this->assertSame(VideoStatus::PRIVATE, $blocked->fresh()->status);

        // private is always allowed
        $this->actingAs($this->creator)->post(route('user.videos.mass-visibility'), ['ids' => [$clean->id], 'visibility' => 'private'])->assertRedirect();
        $this->assertSame(VideoStatus::PRIVATE, $clean->fresh()->status);
    }

    public function test_the_admin_sees_the_same_refusal_and_keeps_ban_and_unban(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        $video = $this->video('QA child test video', VideoStatus::PRIVATE, $this->garden);

        $this->actingAs($admin)->put(route('admin.videos.update', $video), [
            'title' => 'QA child test video', 'description' => 'A neutral description.', 'category_id' => $this->garden->id,
            'status' => VideoStatus::PUBLIC->value, 'language' => null,
        ])->assertSessionHasErrors(['title' => 'This title uses words that are not allowed on this site.']);
        $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status);

        // the admin's bulk bar: Make public leaves it as it is and says why
        $clean = $this->video('QA evening walk', VideoStatus::PRIVATE, $this->garden);
        $this->actingAs($admin)->post(route('admin.bulk.videos'), ['ids' => [$video->id, $clean->id], 'action' => 'make_public'])
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Made 1 video public.')
                && str_contains($message, '1 video was left as it is: its title, tags or category use words that are not allowed on this site.'));
        $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status);
        $this->assertSame(VideoStatus::PUBLIC, $clean->fresh()->status);

        // one video from its row menu: the refusal is the answer (the row's toast)
        $this->actingAs($admin)->post(route('admin.bulk.videos'), ['ids' => [$video->id], 'action' => 'make_unlisted'])
            ->assertSessionHasErrors();
        $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status);

        // moving public videos into a hidden category: they stay where they are
        $this->actingAs($admin)->post(route('admin.bulk.videos'), ['ids' => [$clean->id], 'action' => 'change_category', 'category_id' => $this->hidden->id])
            ->assertSessionHasErrors();
        $this->assertSame($this->garden->id, (int) $clean->fresh()->category_id);

        // Ban and Unban stay the admin's decisions: a video unbanned after a review goes back to its status
        $public = $this->video('QA kid test video', VideoStatus::PUBLIC, $this->garden);
        $this->actingAs($admin)->post(route('admin.videos.ban', $public))->assertRedirect();
        $this->assertSame(VideoStatus::BANNED, $public->fresh()->status);
        $this->actingAs($admin)->post(route('admin.videos.unban', $public))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(VideoStatus::PUBLIC, $public->fresh()->status);
    }

    private function video(string $title, VideoStatus $status, ?Category $category): Video
    {
        return Video::factory()->create([
            'user_id' => $this->creator->id,
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => 'A neutral description.',
            'category_id' => $category?->id,
            'status' => $status->value,
            'published_at' => $status === VideoStatus::PUBLIC ? now()->subDay() : null,
            'uploaded_at' => now()->subDay(),
            'language' => null,
        ]);
    }

    /** What Video details (and the draft page) send for the video, with changes. */
    private function details(Video $video, array $override = []): array
    {
        return array_merge([
            'title' => (string) $video->getRawOriginal('title'),
            'description' => (string) $video->getRawOriginal('description'),
            'status' => $video->status === VideoStatus::DRAFT ? VideoStatus::PUBLIC->value : $video->status->value,
            'category_id' => $video->category_id,
            'language' => null,
            'allow_comments' => 1,
            'default_comments_sort' => 'top',
            'show_likes' => 1,
            'show_ad' => 0,
        ], $override);
    }
}
