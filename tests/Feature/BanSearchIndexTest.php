<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Jobs\RetryVideoSearchIndex;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use RuntimeException;
use Tests\TestCase;

/**
 * 1.2.3 round 3 (lane KS, KS-F2): with the optional external search (Meilisearch, Algolia), the admin's bans and unbans
 * (one video and the bulk bar, the new and the old admin, Members > Block with the member's videos) and the bulk bar's
 * other plain updates (visibility, category) tell its index, as the update's content-safety pass does: a banned video
 * leaves it, an unbanned one comes back. A search server that does not answer never stops the change on the site (a ban,
 * an unban, a member's save): the answer says the search is updated again in the background, and a queued retry does it.
 * The built-in search reads the database and is never asked.
 */
class BanSearchIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $creator;

    private object $engine;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['admin.ui' => 'v2']);
        $this->admin = User::factory()->admin()->create();
        $this->creator = User::factory()->create(['email_verified_at' => now()]);
        $this->engine = new class extends NullEngine
        {
            /** @var list<int> */
            public array $updated = [];

            /** @var list<int> */
            public array $deleted = [];

            public bool $down = false;

            public function update($models)
            {
                if ($this->down) {
                    throw new RuntimeException('The search server did not answer (test).');
                }

                foreach ($models as $model) {
                    $this->updated[] = (int) $model->getKey();
                }
            }

            public function delete($models)
            {
                if ($this->down) {
                    throw new RuntimeException('The search server did not answer (test).');
                }

                foreach ($models as $model) {
                    $this->deleted[] = (int) $model->getKey();
                }
            }
        };
        $engine = $this->engine;
        app(EngineManager::class)->extend('ks-external', fn () => $engine);
        config(['scout.driver' => 'ks-external', 'scout.queue' => false]);
    }

    public function test_bans_unbans_and_the_bulk_bar_tell_the_external_index(): void
    {
        [$a, $b, $c] = [$this->video('QA first'), $this->video('QA second'), $this->video('QA third')];
        $this->reset();

        // the bulk bar's Ban: they leave the index
        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['ids' => [$a->id, $b->id], 'action' => 'ban'])->assertRedirect();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->engine->deleted);

        // its Unban: they come back
        $this->reset();
        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['ids' => [$a->id, $b->id], 'action' => 'unban'])->assertRedirect();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->engine->updated);

        // one video's Ban and Unban
        $this->reset();
        $this->actingAs($this->admin)->post(route('admin.videos.ban', $c))->assertRedirect()->assertSessionHas('success', 'The video is banned');
        $this->assertSame([$c->id], $this->engine->deleted);
        $this->reset();
        $this->actingAs($this->admin)->post(route('admin.videos.unban', $c))->assertRedirect();
        $this->assertSame([$c->id], $this->engine->updated);

        // the bulk bar's visibility and category changes
        $this->reset();
        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['ids' => [$a->id], 'action' => 'make_private'])->assertRedirect();
        $this->assertSame([$a->id], $this->engine->deleted);
        $this->reset();
        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['ids' => [$a->id], 'action' => 'make_public'])->assertRedirect();
        $this->assertSame([$a->id], $this->engine->updated);
        $this->reset();
        $category = Category::create(['title' => 'QA garden category', 'slug' => 'qa-garden-category', 'in_menu' => true, 'position' => 1]);
        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['ids' => [$b->id], 'action' => 'change_category', 'category_id' => $category->id])->assertRedirect();
        $this->assertSame([$b->id], $this->engine->updated);

        // Members > Block, with the member's videos
        $this->reset();
        $this->actingAs($this->admin)->post(route('admin.users.ban', $this->creator), ['ban_videos' => '1'])->assertRedirect();
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $this->engine->deleted);
    }

    public function test_the_old_admin_tells_it_too(): void
    {
        \App\Support\AdminPreferences::set($this->admin, 'admin_ui', 'v1');
        $video = $this->video('QA first');
        $this->reset();

        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['ids' => [$video->id], 'action' => 'ban'])->assertRedirect();
        $this->assertSame([$video->id], $this->engine->deleted);
    }

    public function test_a_search_server_that_does_not_answer_never_stops_a_ban_and_is_tried_again(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        [$a, $b] = [$this->video('QA first'), $this->video('QA second')];
        $this->engine->down = true;
        $note = 'The search server did not answer: the change is saved, and the search is updated again in the background.';

        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['ids' => [$a->id], 'action' => 'ban'])
            ->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Banned 1 video.') && str_contains($message, $note));
        $this->assertSame(VideoStatus::BANNED, $a->fresh()->status);

        // one video's Ban: a model save, which the index follows by itself; no error page
        $this->actingAs($this->admin)->post(route('admin.videos.ban', $b))
            ->assertRedirect()->assertSessionHas('success', fn (string $message): bool => str_contains($message, $note));
        $this->assertSame(VideoStatus::BANNED, $b->fresh()->status);

        Queue::assertPushed(RetryVideoSearchIndex::class, fn (RetryVideoSearchIndex $job): bool => $job->videoIds === [$a->id]);
        Queue::assertPushed(RetryVideoSearchIndex::class, fn (RetryVideoSearchIndex $job): bool => $job->videoIds === [$b->id]);

        // the server answers again: the queued retry takes the banned videos out
        $this->engine->down = false;
        $this->reset();
        (new RetryVideoSearchIndex([$a->id, $b->id]))->handle();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->engine->deleted);

        // while it still does not answer, the retry fails so the queue tries it again later
        $this->engine->down = true;
        $this->expectException(RuntimeException::class);
        (new RetryVideoSearchIndex([$a->id]))->handle();
    }

    public function test_a_members_save_never_breaks_when_the_search_server_does_not_answer(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $video = $this->video('QA first', VideoStatus::PRIVATE);
        $this->engine->down = true;

        $this->actingAs($this->creator)->put(route('user.videos.update', $video), [
            'title' => 'QA first', 'description' => 'Changed.', 'status' => VideoStatus::PUBLIC->value, 'category_id' => null, 'language' => null,
            'allow_comments' => 1, 'default_comments_sort' => 'top', 'show_likes' => 1, 'show_ad' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(VideoStatus::PUBLIC, $video->fresh()->status);
        Queue::assertPushed(RetryVideoSearchIndex::class);
    }

    public function test_a_video_deleted_while_the_search_server_did_not_answer_leaves_the_index_on_the_retry(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $video = $this->video('QA first');
        $this->engine->down = true;

        $this->actingAs($this->admin)->delete(route('admin.videos.delete', $video))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(Video::withTrashed()->find($video->id), 'deleted for good');
        Queue::assertPushed(RetryVideoSearchIndex::class, fn (RetryVideoSearchIndex $job): bool => $job->videoIds === [$video->id]);

        // the server answers again: the retry removes it, though only its number is left
        $this->engine->down = false;
        $this->reset();
        (new RetryVideoSearchIndex([$video->id]))->handle();
        $this->assertSame([$video->id], $this->engine->deleted);
        $this->assertSame([], $this->engine->updated);
    }

    public function test_the_import_still_fails_when_the_search_server_does_not_answer(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $this->video('QA first');
        $this->engine->down = true;

        // scout:import (the nightly import, or a run of it from the admin's Cron page) fails as before: it does not say
        // every video was imported, and queues no retry per chunk of videos
        $failure = null;

        try {
            Video::makeAllSearchable();
        } catch (RuntimeException $e) {
            $failure = $e;
        }

        $this->assertNotNull($failure, 'the import fails while the search server does not answer');
        $this->assertSame('The search server did not answer (test).', $failure->getMessage());
        Queue::assertNotPushed(RetryVideoSearchIndex::class);

        // the site's own changes are guarded again afterwards
        $video = $this->video('QA second');
        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['ids' => [$video->id], 'action' => 'ban'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(VideoStatus::BANNED, $video->fresh()->status);
        Queue::assertPushed(RetryVideoSearchIndex::class, fn (RetryVideoSearchIndex $job): bool => $job->videoIds === [$video->id]);
    }

    public function test_a_public_video_whose_member_is_gone_leaves_the_index_without_an_error(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $video = $this->video('QA first');
        // a member deleted (in the trash) whose video is still public: visitors no longer see it (Video::active(), the
        // nightly import's rule), so the index removes it; its own save never breaks on the missing member
        $this->creator->delete();
        $this->reset();

        \App\Support\VideoSearchIndex::watch();
        \App\Support\VideoSearchIndex::follow([$video->id]);
        $this->assertSame([$video->id], $this->engine->deleted);
        $this->assertSame([], $this->engine->updated);

        $this->reset();
        $video->fresh()->update(['description' => 'Changed.']);
        $this->assertFalse(\App\Support\VideoSearchIndex::missed(), 'no error and no "the search server did not answer"');
        Queue::assertNotPushed(RetryVideoSearchIndex::class);
    }

    public function test_the_built_in_search_is_never_asked(): void
    {
        config(['scout.driver' => 'collection']);
        $video = $this->video('QA first');

        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['ids' => [$video->id], 'action' => 'ban'])
            ->assertRedirect()->assertSessionHas('success', 'Banned 1 video.');
        $this->assertSame([[], []], [$this->engine->updated, $this->engine->deleted]);
    }

    private function video(string $title, VideoStatus $status = VideoStatus::PUBLIC): Video
    {
        return Video::factory()->create([
            'user_id' => $this->creator->id, 'title' => $title, 'slug' => Str::slug($title), 'category_id' => null,
            'status' => $status->value, 'published_at' => $status === VideoStatus::PUBLIC ? now()->subDay() : null, 'uploaded_at' => now()->subDay(),
        ]);
    }

    private function reset(): void
    {
        $this->engine->updated = [];
        $this->engine->deleted = [];
    }
}
