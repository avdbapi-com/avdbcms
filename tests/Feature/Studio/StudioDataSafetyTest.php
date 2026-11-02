<?php

namespace Tests\Feature\Studio;

use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\Interaction;
use App\Models\Playlist;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Video;
use App\Services\InvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * 1.2.3 (member design 6.4, 6.7, 6.8): the studio's data-loss fixes. Each test fails on the code before 1.2.3:
 * studio-3 (saving a video took it out of every other member's playlists), studio-4 (saving a playlist kept only the
 * videos the editor had loaded), studio-18 (deleting a comment deleted other activities with the same id), studio-23
 * (an interaction's activity had no moment of its own) and studio-28 / account-7 (a missing invoice PDF downloaded as
 * an empty file), plus the scheduling fields (6.4: one day + one time, in the site's time zone).
 */
class StudioDataSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('thumbnails');
    }

    // ---------------------------------------------------------------------------------------------------- studio-3

    public function test_saving_a_video_never_takes_it_out_of_other_members_playlists(): void
    {
        $creator = User::factory()->create();
        $viewer = User::factory()->create();
        $video = $this->video($creator);

        $theirs = $this->playlist($viewer);
        $theirWatchLater = $this->playlist($viewer, ['is_deletable' => false, 'title' => 'Watch later']);
        $mine = $this->playlist($creator);
        $this->attach($theirs, [$video->id]);
        $this->attach($theirWatchLater, [$video->id]);
        $this->attach($mine, [$video->id]);

        $this->actingAs($creator)
            ->put(route('user.videos.update', $video), $this->videoPayload($video, ['playlists_present' => '1', 'playlists' => [$mine->id]]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertTrue($this->holds($theirs, $video), "another member's playlist lost the video");
        $this->assertTrue($this->holds($theirWatchLater, $video), "another member's Watch later lost the video");
        $this->assertTrue($this->holds($mine, $video));
    }

    public function test_saving_a_video_adds_and_removes_it_only_in_the_members_own_playlists(): void
    {
        $creator = User::factory()->create();
        $video = $this->video($creator);
        $kept = $this->playlist($creator);
        $dropped = $this->playlist($creator);
        $added = $this->playlist($creator);
        $ownWatchLater = $this->playlist($creator, ['is_deletable' => false, 'title' => 'Watch later']);
        $other = $this->video($creator);
        $this->attach($kept, [$other->id, $video->id]);
        $this->attach($dropped, [$video->id]);
        $this->attach($added, [$other->id]);
        $this->attach($ownWatchLater, [$video->id]);

        $this->actingAs($creator)
            ->put(route('user.videos.update', $video), $this->videoPayload($video, ['playlists_present' => '1', 'playlists' => [$kept->id, $added->id]]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->holds($kept, $video));
        $this->assertFalse($this->holds($dropped, $video));
        $this->assertTrue($this->holds($added, $video));
        $this->assertTrue($this->holds($ownWatchLater, $video), 'the member\'s own Watch later is not offered on the page and is never changed');
        // a newly chosen playlist gets the video at its end
        $this->assertSame([$other->id, $video->id], $this->order($added));
    }

    public function test_a_save_without_the_playlists_field_changes_no_playlist(): void
    {
        $creator = User::factory()->create();
        $video = $this->video($creator);
        $mine = $this->playlist($creator);
        $this->attach($mine, [$video->id]);

        $this->actingAs($creator)
            ->put(route('user.videos.update', $video), $this->videoPayload($video))
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->holds($mine, $video));
    }

    public function test_the_playlists_of_another_member_cannot_be_chosen(): void
    {
        $creator = User::factory()->create();
        $viewer = User::factory()->create();
        $video = $this->video($creator);
        $theirs = $this->playlist($viewer);

        $this->actingAs($creator)
            ->put(route('user.videos.update', $video), $this->videoPayload($video, ['playlists_present' => '1', 'playlists' => [$theirs->id]]))
            ->assertSessionHasErrors('playlists.0');

        $this->assertFalse($this->holds($theirs, $video));
    }

    // ---------------------------------------------------------------------------------------------------- studio-4

    public function test_the_playlist_editor_carries_every_video_of_a_long_playlist(): void
    {
        $member = User::factory()->create();
        $playlist = $this->playlist($member);
        $ids = collect(range(1, 30))->map(fn () => $this->video($member)->id)->all();
        $this->attach($playlist, $ids);

        $html = $this->actingAs($member)->get(route('user.playlists.edit', $playlist))->assertOk()->getContent();

        // one field each (PHP's max_input_vars never cuts a long list): the list as it will be saved, and the list loaded
        $this->assertStringContainsString('<input type="hidden" name="videos_order" value="'.implode(',', $ids).'">', $html, 'the editor must post the whole list in its order, also the videos it does not show yet');
        $this->assertStringContainsString('<input type="hidden" name="videos_loaded" value="'.implode(',', $ids).'">', $html);
        $this->assertStringNotContainsString('name="videos[]"', $html);
    }

    public function test_saving_the_whole_list_keeps_every_video_and_its_new_order(): void
    {
        $member = User::factory()->create();
        $playlist = $this->playlist($member);
        $ids = collect(range(1, 30))->map(fn () => $this->video($member)->id)->all();
        $this->attach($playlist, $ids);
        $reversed = array_reverse($ids);

        $this->actingAs($member)
            ->put(route('user.playlists.update', $playlist), $this->playlistPayload(['videos_loaded' => implode(',', $ids), 'videos_order' => implode(',', $reversed)]))
            ->assertSessionHasNoErrors();

        $this->assertSame($reversed, $this->order($playlist));
    }

    public function test_a_save_without_the_whole_list_never_removes_a_video(): void
    {
        $member = User::factory()->create();
        $playlist = $this->playlist($member);
        $ids = collect(range(1, 30))->map(fn () => $this->video($member)->id)->all();
        $this->attach($playlist, $ids);
        $extra = $this->video($member)->id;

        // an older page or another client that sends only the first 24 (and one new video), without the editor's list
        $this->actingAs($member)
            ->put(route('user.playlists.update', $playlist), $this->playlistPayload(['videos' => [...array_slice($ids, 0, 24), $extra]]))
            ->assertSessionHasNoErrors();

        $this->assertSame([...$ids, $extra], $this->order($playlist));
    }

    public function test_removing_videos_in_the_editor_removes_exactly_those(): void
    {
        $member = User::factory()->create();
        $playlist = $this->playlist($member);
        $ids = collect(range(1, 10))->map(fn () => $this->video($member)->id)->all();
        $this->attach($playlist, $ids);
        $left = array_values(array_diff($ids, [$ids[2], $ids[7]]));

        $this->actingAs($member)
            ->put(route('user.playlists.update', $playlist), $this->playlistPayload(['videos_loaded' => implode(',', $ids), 'videos_order' => implode(',', $left)]))
            ->assertSessionHasNoErrors();

        $this->assertSame($left, $this->order($playlist));
    }

    // --------------------------------------------------------------------------------------------- studio-18, 23

    public function test_deleting_a_comment_keeps_another_activity_with_the_same_id(): void
    {
        $member = User::factory()->create();
        $video = $this->video(User::factory()->create());

        $this->actingAs($member);
        $like = Interaction::query()->create(['user_id' => $member->id, 'likeable_type' => Video::class, 'likeable_id' => $video->id, 'status' => true, 'perform_at' => now()->subHour()]);
        DB::table('comments')->insert(['id' => $like->id, 'video_id' => $video->id, 'user_id' => $member->id, 'content' => 'same id', 'ip' => '203.0.113.9', 'created_at' => now(), 'updated_at' => now()]);
        Activity::query()->create(['user_id' => $member->id, 'subject_type' => Comment::class, 'subject_id' => $like->id, 'perform_at' => now()]);
        $this->assertSame(2, Activity::query()->where('subject_id', $like->id)->count());

        Comment::query()->findOrFail($like->id)->forceDelete();

        $this->assertDatabaseMissing('activities', ['subject_type' => Comment::class, 'subject_id' => $like->id]);
        $this->assertDatabaseHas('activities', ['subject_type' => Interaction::class, 'subject_id' => $like->id]);
    }

    public function test_the_activity_of_a_like_takes_the_moment_of_the_like(): void
    {
        $member = User::factory()->create();
        $video = $this->video(User::factory()->create());
        $at = CarbonImmutable::parse('2026-10-20 13:05:00', config('app.timezone'));

        $this->actingAs($member);
        $like = Interaction::query()->create(['user_id' => $member->id, 'likeable_type' => Video::class, 'likeable_id' => $video->id, 'status' => true, 'perform_at' => $at]);

        $activity = Activity::query()->where('subject_type', Interaction::class)->where('subject_id', $like->id)->firstOrFail();
        $this->assertNotNull($activity->perform_at, 'an interaction has no created_at: its activity must take perform_at');
        $this->assertTrue($activity->perform_at->equalTo($at));
    }

    // ------------------------------------------------------------------------------------- studio-28, account-7

    public function test_a_missing_invoice_pdf_is_made_again_on_download(): void
    {
        Storage::fake();
        $member = User::factory()->create();
        $transaction = $this->transaction($member);
        // as the real generate() does, the PDF carries this payment's fingerprint (R1-027)
        $made = '%PDF-1.4 m1 test '.InvoiceService::fingerprint($transaction);
        $this->mock(InvoiceService::class, function ($mock) use ($made) {
            $mock->shouldReceive('generate')->once()->andReturnUsing(function (Transaction $transaction) use ($made) {
                Storage::put($transaction->invoice_path, $made);
            });
        });

        $response = $this->actingAs($member)->get(route('user.invoices.show', $transaction));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertSame($made, $response->streamedContent());
    }

    public function test_an_invoice_that_cannot_be_made_says_so_instead_of_an_empty_pdf(): void
    {
        Storage::fake();
        $member = User::factory()->create();
        $transaction = $this->transaction($member);
        Storage::put($transaction->invoice_path, '');
        $this->mock(InvoiceService::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andThrow(new RuntimeException('dompdf failed'));
        });

        $this->actingAs($member)
            ->get(route('user.invoices.show', $transaction))
            ->assertRedirect(route('user.invoices.index'))
            ->assertSessionHas('info', 'This invoice is being prepared, try again in a minute.');
    }

    public function test_an_existing_invoice_pdf_is_sent_as_it_is(): void
    {
        Storage::fake();
        $member = User::factory()->create();
        $transaction = $this->transaction($member);
        // a PDF of this payment made with the current template (R1-027: one without its fingerprint is made again)
        $stored = '%PDF-1.4 stored '.InvoiceService::fingerprint($transaction);
        Storage::put($transaction->invoice_path, $stored);
        $this->mock(InvoiceService::class, fn ($mock) => $mock->shouldNotReceive('generate'));

        $response = $this->actingAs($member)->get(route('user.invoices.show', $transaction));

        $response->assertOk();
        $this->assertSame($stored, $response->streamedContent());
    }

    public function test_another_members_invoice_stays_closed(): void
    {
        Storage::fake();
        $transaction = $this->transaction(User::factory()->create());
        $this->mock(InvoiceService::class, fn ($mock) => $mock->shouldNotReceive('generate'));

        // InvoicePolicy answers as if it did not exist
        $this->actingAs(User::factory()->create())
            ->get(route('user.invoices.show', $transaction))
            ->assertNotFound();
    }

    // --------------------------------------------------------------------------------------------- scheduling (6.4)

    public function test_a_scheduled_video_takes_the_day_and_time_in_the_sites_time_zone(): void
    {
        // the site's zone is the app's (config/app.php: Europe/Paris; the admin's "Time zone" sets both)
        $this->assertSame('Europe/Paris', config('app.timezone'));
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00', 'Europe/Paris'));

        $creator = User::factory()->create();
        $video = $this->video($creator, ['status' => VideoStatus::PRIVATE->value, 'published_at' => null]);

        $this->actingAs($creator)
            ->put(route('user.videos.update', $video), $this->videoPayload($video, [
                'status' => VideoStatus::PLANNED->value,
                'scheduled_date' => '2026-11-06',
                'scheduled_time' => '20:00',
            ]))
            ->assertSessionHasNoErrors();

        $fresh = $video->fresh();
        $this->assertSame(VideoStatus::PLANNED, $fresh->status);
        $this->assertSame('2026-11-06 20:00', $fresh->scheduled_at->setTimezone('Europe/Paris')->format('Y-m-d H:i'));
    }

    public function test_a_scheduled_day_in_the_past_is_refused_with_a_plain_message(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00', 'Europe/Paris'));

        $creator = User::factory()->create();
        $video = $this->video($creator, ['status' => VideoStatus::PRIVATE->value, 'published_at' => null]);

        $this->actingAs($creator)
            ->put(route('user.videos.update', $video), $this->videoPayload($video, [
                'status' => VideoStatus::PLANNED->value,
                'scheduled_date' => '2026-10-01',
                'scheduled_time' => '20:00',
            ]))
            ->assertSessionHasErrors(['scheduled_at' => 'Pick a day and a time that are still to come.']);

        $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status);
    }

    public function test_a_broken_time_is_refused(): void
    {
        $creator = User::factory()->create();
        $video = $this->video($creator, ['status' => VideoStatus::PRIVATE->value, 'published_at' => null]);

        $this->actingAs($creator)
            ->put(route('user.videos.update', $video), $this->videoPayload($video, [
                'status' => VideoStatus::PLANNED->value,
                'scheduled_date' => now()->addWeek()->toDateString(),
                'scheduled_time' => 'soon',
            ]))
            ->assertSessionHasErrors('scheduled_at');
    }

    // ---------------------------------------------------------------------------------------------------- helpers

    private function video(User $owner, array $attributes = []): Video
    {
        return Video::factory()->for($owner, 'user')->create(array_merge([
            'status' => VideoStatus::PUBLIC->value,
            'category_id' => null,
            'language' => null,
            'published_at' => now()->subDays(3),
            'uploaded_at' => now()->subDays(3),
        ], $attributes));
    }

    private function playlist(User $owner, array $attributes = []): Playlist
    {
        return Playlist::factory()->for($owner)->create(array_merge([
            'status' => PlaylistStatus::PUBLIC,
            'sort' => PlaylistSort::MANUAL,
            'is_deletable' => true,
        ], $attributes));
    }

    /** @param list<int> $videoIds */
    private function attach(Playlist $playlist, array $videoIds): void
    {
        $start = (int) (DB::table('playlist_has_videos')->where('playlist_id', $playlist->id)->max('position') ?? -1) + 1;

        foreach (array_values($videoIds) as $i => $id) {
            DB::table('playlist_has_videos')->insert(['playlist_id' => $playlist->id, 'video_id' => $id, 'position' => $start + $i, 'added_at' => now()->subMinutes(100 - $i)]);
        }
    }

    private function holds(Playlist $playlist, Video $video): bool
    {
        return DB::table('playlist_has_videos')->where('playlist_id', $playlist->id)->where('video_id', $video->id)->exists();
    }

    /** @return list<int> */
    private function order(Playlist $playlist): array
    {
        return DB::table('playlist_has_videos')->where('playlist_id', $playlist->id)->orderBy('position')->orderBy('id')->pluck('video_id')->map(fn ($id) => (int) $id)->all();
    }

    private function videoPayload(Video $video, array $override = []): array
    {
        return array_merge([
            'title' => (string) $video->getRawOriginal('title'),
            'description' => 'A calm drive.',
            'status' => VideoStatus::PUBLIC->value,
            'category_id' => null,
            'language' => null,
            'allow_comments' => 1,
            'default_comments_sort' => 'top',
            'show_likes' => 1,
            'show_ad' => 0,
        ], $override);
    }

    private function playlistPayload(array $override = []): array
    {
        return array_merge([
            'title' => 'Night drives',
            'status' => PlaylistStatus::PUBLIC->value,
            'sort' => PlaylistSort::MANUAL->value,
        ], $override);
    }

    private function transaction(User $member): Transaction
    {
        return Transaction::query()->create([
            'payment_provider' => 'stripe',
            'stripe_id' => 'in_m1_'.uniqid(),
            'amount' => 500,
            'tax' => 0,
            'fee' => 45,
            'date' => now()->subDays(3),
            'user_id' => $member->id,
            'subscription_id' => null,
            'name' => 'Nightdrive TV',
            'address' => '1 Harbour road',
            'city' => 'Lyon',
            'postal_code' => '69001',
            'country' => 'FR',
            'vat_id' => null,
        ]);
    }
}
