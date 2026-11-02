<?php

namespace Tests\Feature\Studio;

use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\VideoStatus;
use App\Http\Requests\Playlist\UpdatePlaylistRequest;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\Interaction;
use App\Models\Playlist;
use App\Models\Report;
use App\Models\User;
use App\Models\Video;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 1.2.3, the second review of the studio: each test fails on the code before the fix and passes after it.
 *  - a draft nobody changed starts clean: its bar shows (Publish) through its own condition, its leave warning waits for
 *    the member's first real input, so the Upload18 completion poll reloads it quietly and the studio menu leaves it
 *    without "Leave site?"; "Save draft" lands on a clean page too;
 *  - the playlist editor's list fields are checked without a regular expression: a playlist of 5,000 videos saves (the
 *    old pattern ran out of PCRE's JIT stack at ~4,900 ids), a malformed list is still refused;
 *  - "Remove comment" removes the comment AND its replies, as its dialog says, and counts every row off the video's
 *    stored comment count exactly once (the bulk removal too, also when a hand-made request names a reply and its
 *    comment);
 *  - the studio dialogs follow the admin's "Pop-up window style" (member design 6.0, T: one test per style).
 */
class StudioVerifierFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('thumbnails');
        $this->member = User::factory()->create(['username' => 'nightdrive_tv']);
        $this->viewer = User::factory()->create(['username' => 'qa_studio_viewer']);
    }

    // ------------------------------------------------------------------------------------------ the draft page

    public function test_a_draft_nobody_changed_starts_clean_and_still_shows_its_bar(): void
    {
        $draft = $this->draft();

        $html = $this->actingAs($this->member)->get(route('user.videos.create', $draft))->assertOk()->getContent();

        // nothing typed: no unsaved changes, so the leave warning is not armed ... (1.2.3 round 3, R3-086: the starting
        // values are a plain object, "dirty" left out while false; the leave warning and the bar's condition are the
        // component's own, in the cached studio script, no longer attributes of the page root)
        $this->assertStringContainsString("mkVideoForm({draft:true,status:", $this->rootTag($html), 'a draft nobody changed must start without unsaved changes');
        $script = File::get(resource_path('js/member/studio-pages.js'));
        $this->assertStringContainsString("if (this.dirty && !this.sending) { event.preventDefault(); event.returnValue = ''; }", $script, 'the leave warning waits for unsaved changes');
        // ... while its bar (Publish, Save draft, Finish later) shows from the start through its own condition
        $this->assertStringContainsString("document.documentElement.dataset.mkBar = (this.dirty || this.draft) ? 'save' : ''", $script);
        $this->assertStringContainsString('Not published yet', $html);
        $this->assertStringContainsString('Publish', $html);
        // the Upload18 hooks stay on the kit markup
        $this->assertStringContainsString('data-video-draft-form', $html);
    }

    public function test_video_details_never_show_the_bar_without_unsaved_changes(): void
    {
        $video = $this->video($this->member, ['title' => 'Sunset drive']);

        $html = $this->actingAs($this->member)->get(route('user.videos.edit', $video))->assertOk()->getContent();

        $this->assertStringContainsString('mkVideoForm({status:', $html, 'neither unsaved changes nor a draft (both left out while false)');
    }

    public function test_save_draft_lands_on_a_clean_draft_and_a_refused_draft_save_keeps_its_unsaved_changes(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->member)
            ->from(route('user.videos.create', $draft))
            ->post(route('user.videos.store', $draft), ['title' => 'Harbour at dawn', 'status' => VideoStatus::PUBLIC->value, 'action' => 'draft', 'allow_comments' => 1, 'show_likes' => 1, 'show_ad' => 0, 'default_comments_sort' => 'top'])
            ->assertRedirect(route('user.videos.create', $draft));
        $saved = $this->actingAs($this->member)->get(route('user.videos.create', $draft))->assertOk()->getContent();
        $this->assertStringContainsString('mkVideoForm({draft:true,status:', $saved, 'a saved draft is clean');
        $this->assertSame('Harbour at dawn', (string) $draft->fresh()->getRawOriginal('title'));

        // a refused save comes back with what the member typed: still unsaved (principle 7)
        $this->actingAs($this->member)
            ->from(route('user.videos.create', $draft))
            ->post(route('user.videos.store', $draft), ['title' => '', 'status' => VideoStatus::PUBLIC->value, 'action' => 'draft'])
            ->assertRedirect(route('user.videos.create', $draft));
        $refused = $this->actingAs($this->member)->get(route('user.videos.create', $draft))->assertOk()->getContent();
        $this->assertStringContainsString('mkVideoForm({dirty:true,draft:true,status:', $refused, 'a refused save keeps its unsaved changes');
    }

    // ------------------------------------------------------------------------------------------ the playlist editor

    public function test_the_editor_saves_a_playlist_of_5000_videos(): void
    {
        $playlist = Playlist::factory()->for($this->member)->create(['title' => 'Everything', 'status' => PlaylistStatus::PUBLIC, 'sort' => PlaylistSort::MANUAL, 'is_deletable' => true]);
        $ids = $this->bulkVideos($this->member, 5000);
        foreach (array_chunk($ids, 500) as $c => $chunk) {
            DB::table('playlist_has_videos')->insert(array_map(fn ($id, $i) => ['playlist_id' => $playlist->id, 'video_id' => $id, 'position' => $c * 500 + $i, 'added_at' => now()], $chunk, array_keys($chunk)));
        }

        // the two fields as the editor writes them (users/playlists/partials/editor: one comma-separated list each)
        $order = array_reverse($ids);
        $this->actingAs($this->member)->put(route('user.playlists.update', $playlist), [
            'title' => 'Everything',
            'status' => PlaylistStatus::PUBLIC->value,
            'sort' => PlaylistSort::MANUAL->value,
            'videos_order' => implode(',', $order),
            'videos_loaded' => implode(',', $ids),
            'action' => 'save',
        ])->assertSessionHasNoErrors();

        $saved = DB::table('playlist_has_videos')->where('playlist_id', $playlist->id)->orderBy('position')->pluck('video_id')->map(fn ($id) => (int) $id)->all();
        $this->assertCount(5000, $saved);
        $this->assertSame($order[0], $saved[0], 'the member\'s order applies');
        $this->assertSame($order[4999], $saved[4999]);
    }

    public function test_an_editor_list_is_ids_separated_by_commas(): void
    {
        foreach (['1', '12,5,9', str_repeat('7', 20), implode(',', range(1, 20000))] as $ok) {
            $this->assertTrue(UpdatePlaylistRequest::isIdList($ok), 'accepted: '.Str::limit($ok, 30));
        }

        foreach (['', ',', '1,', ',1', '1,,2', '1, 2', 'a', '1;2', '-1', '1.5', str_repeat('7', 21), "1\n2"] as $bad) {
            $this->assertFalse(UpdatePlaylistRequest::isIdList($bad), 'refused: '.json_encode($bad));
        }

        $this->assertFalse(UpdatePlaylistRequest::isIdList(null));
        $this->assertFalse(UpdatePlaylistRequest::isIdList(['1']));

        // a malformed field is still refused with the editor's message, and nothing changes
        $video = $this->video($this->member, ['title' => 'Kept']);
        $playlist = Playlist::factory()->for($this->member)->create(['title' => 'Night drives', 'status' => PlaylistStatus::PUBLIC, 'sort' => PlaylistSort::MANUAL, 'is_deletable' => true]);
        DB::table('playlist_has_videos')->insert(['playlist_id' => $playlist->id, 'video_id' => $video->id, 'position' => 0, 'added_at' => now()]);

        $this->actingAs($this->member)->from(route('user.playlists.edit', $playlist))->put(route('user.playlists.update', $playlist), [
            'title' => 'Night drives', 'status' => PlaylistStatus::PUBLIC->value, 'sort' => PlaylistSort::MANUAL->value,
            'videos_order' => '1,,2', 'videos_loaded' => (string) $video->id,
        ])->assertSessionHasErrors(['videos_order' => __('The list of videos could not be read. Reload the page and try again.')]);
        $this->assertSame(1, DB::table('playlist_has_videos')->where('playlist_id', $playlist->id)->count());
    }

    // ------------------------------------------------------------------------------------------ removing comments

    public function test_removing_one_comment_removes_its_replies_as_the_dialog_says(): void
    {
        $video = $this->video($this->member, ['title' => 'Night market']);
        $other = $this->video($this->member, ['title' => 'Harbour']);
        $comment = $this->comment($video, $this->viewer, 'Top comment');
        $replyOne = $this->comment($video, $this->member, 'Reply one', $comment);
        $replyTwo = $this->comment($video, $this->viewer, 'Reply two', $comment);
        $kept = $this->comment($video, $this->viewer, 'Another comment');
        $keptReply = $this->comment($video, $this->member, 'Its reply', $kept);
        $elsewhere = $this->comment($other, $this->viewer, 'On the other video');
        Interaction::query()->create(['user_id' => $this->member->id, 'likeable_type' => Comment::class, 'likeable_id' => $replyTwo->id, 'status' => true, 'perform_at' => now()]);
        Interaction::query()->create(['user_id' => $this->member->id, 'likeable_type' => Comment::class, 'likeable_id' => $comment->id, 'status' => true, 'perform_at' => now()]);
        $this->assertSame(5, (int) DB::table('videos')->where('id', $video->id)->value('comments_count'));

        $html = $this->actingAs($this->member)->get(route('user.comments.index'))->assertOk()->getContent();
        $this->assertStringContainsString('The comment and its replies are removed from your video.', $html);

        $this->actingAs($this->member)->from(route('user.comments.index'))->delete(route('user.comments.destroy', $comment))
            ->assertRedirect(route('user.comments.index'))->assertSessionHas('success', 'Comment removed.');

        foreach ([$comment, $replyOne, $replyTwo] as $gone) {
            $this->assertNull(Comment::withTrashed()->find($gone->id), 'the comment and its replies are gone');
        }
        foreach ([$kept, $keptReply, $elsewhere] as $stays) {
            $this->assertNotNull(Comment::query()->find($stays->id), 'other comments stay');
        }
        $this->assertSame(0, Interaction::query()->where('likeable_type', Comment::class)->whereIn('likeable_id', [$comment->id, $replyTwo->id])->count(), 'their likes go too');
        $this->assertSame(0, Activity::query()->where('subject_type', Comment::class)->whereIn('subject_id', [$comment->id, $replyOne->id, $replyTwo->id])->count(), 'their activity goes');
        $this->assertSame(2, (int) DB::table('videos')->where('id', $video->id)->value('comments_count'), 'the video counts the 2 comments left');
        $this->assertSame(1, (int) DB::table('videos')->where('id', $other->id)->value('comments_count'));
    }

    public function test_removing_one_reply_keeps_its_comment(): void
    {
        $video = $this->video($this->member, ['title' => 'Night market']);
        $comment = $this->comment($video, $this->viewer, 'Top comment');
        $reply = $this->comment($video, $this->member, 'Reply one', $comment);
        $sibling = $this->comment($video, $this->viewer, 'Reply two', $comment);

        $this->actingAs($this->member)->from(route('user.comments.index'))->delete(route('user.comments.destroy', $reply))
            ->assertRedirect()->assertSessionHas('success', 'Reply removed.');

        $this->assertNull(Comment::withTrashed()->find($reply->id));
        $this->assertNotNull(Comment::query()->find($comment->id));
        $this->assertNotNull(Comment::query()->find($sibling->id));
        $this->assertSame(2, (int) DB::table('videos')->where('id', $video->id)->value('comments_count'));
    }

    public function test_the_bulk_removal_counts_each_comment_off_the_video_once(): void
    {
        $video = $this->video($this->member, ['title' => 'Night market']);
        $first = $this->comment($video, $this->viewer, 'First');
        $firstReply = $this->comment($video, $this->member, 'First reply', $first);
        $second = $this->comment($video, $this->viewer, 'Second');
        $secondReply = $this->comment($video, $this->viewer, 'Second reply', $second);
        $kept = $this->comment($video, $this->viewer, 'Kept');
        $foreign = $this->comment($this->video($this->viewer, ['title' => 'Not mine']), $this->member, 'Not on my video');
        $this->assertSame(5, (int) DB::table('videos')->where('id', $video->id)->value('comments_count'));

        // a hand-made request names a reply AND its comment, and a comment on another member's video
        $this->actingAs($this->member)->from(route('user.comments.index'))
            ->delete(route('user.comments.mass-delete'), ['ids' => [$first->id, $firstReply->id, $second->id, $foreign->id]])
            ->assertRedirect()->assertSessionHas('success', 'Deleted 3 comments.');

        foreach ([$first, $firstReply, $second, $secondReply] as $gone) {
            $this->assertNull(Comment::withTrashed()->find($gone->id));
        }
        $this->assertNotNull(Comment::query()->find($kept->id));
        $this->assertNotNull(Comment::query()->find($foreign->id), 'another member\'s video keeps its comment');
        $this->assertSame(1, (int) DB::table('videos')->where('id', $video->id)->value('comments_count'), 'each removed row is counted off once');
    }

    // ------------------------------------------------------------------------------------------ pop-up window style

    /** @return array<string, array{string}> */
    public static function popUpStyles(): array
    {
        return [
            'centred' => ['modal_glassmorphic_center'],
            'sliding sheet' => ['modal_slide_sheet'],
            'full screen' => ['modal_fullscreen_cinema'],
            'sharp' => ['modal_brutalist_sharp'],
        ];
    }

    /**
     * Member design 6.0 (modal_presentation_style, T: one test per style) and 5.11: every studio dialog is the kit
     * dialog the site's "Pop-up window style" restyles (a Bootstrap .modal > .modal-dialog.modal-dialog-centered.mk-dialog,
     * a confirmation with .mk-confirm), on a page that prints the admin's style on <html>; none fixes its own size or
     * presentation (no modal-fullscreen / modal-sm|lg|xl, no inline style), so the style decides it.
     */
    #[DataProvider('popUpStyles')]
    public function test_the_studio_dialogs_follow_the_pop_up_window_style(string $style): void
    {
        config(['site.modal_presentation_style' => $style]);
        $fixture = $this->dialogFixture();

        $pages = [
            'My videos' => [route('user.videos.index'), ['mk-delete-video' => true, 'mk-delete-videos' => true, 'mkf-videos-dates' => false]],
            'Video details' => [route('user.videos.edit', $fixture['video']), ['mk-delete-video' => true]],
            'Dashboard' => [route('user.index'), ['mk-remove-comment' => true, 'mk-dash-delete' => true, 'mk-dash-dates' => false]],
            'Comments' => [route('user.comments.index'), ['mk-remove-comment' => true, 'mk-remove-comments' => true, 'mk-remove-reply' => true]],
            'Playlists' => [route('user.playlists.index'), ['mk-delete-playlist' => true, 'mk-delete-playlists' => true]],
            'Reports' => [route('user.reports.index'), ['mk-cancel-report' => true]],
            'Analytics' => [route('user.videos.show', $fixture['video']), ['mk-likes' => false]],
        ];

        foreach ($pages as $page => [$url, $dialogs]) {
            $html = $this->actingAs($this->member)->get($url)->assertOk()->getContent();
            $xpath = $this->xpath($html);

            $root = $xpath->query('/html')->item(0);
            $this->assertInstanceOf(DOMElement::class, $root);
            $this->assertSame($style, $root->getAttribute('data-avd-modal-style'), "{$page}: the page prints the admin's pop-up window style");

            foreach ($dialogs as $id => $confirmation) {
                $modal = $xpath->query('//*[@id="'.$id.'"]')->item(0);
                $this->assertInstanceOf(DOMElement::class, $modal, "{$page}: #{$id} is printed");
                $this->assertSame(['fade', 'modal'], $this->classes($modal), "{$page}: #{$id} is a Bootstrap modal the style restyles");
                $this->assertNull($modal->getAttribute('style') ?: null, "{$page}: #{$id} has no inline style");
                $this->assertNotNull($modal->parentNode);
                $this->assertNotEmpty($xpath->query('ancestor::*[contains(concat(" ", normalize-space(@class), " "), " mk-page ")]', $modal), "{$page}: #{$id} sits in the page root (5.11)");

                $dialog = $xpath->query('./div[contains(concat(" ", normalize-space(@class), " "), " modal-dialog ")]', $modal)->item(0);
                $this->assertInstanceOf(DOMElement::class, $dialog, "{$page}: #{$id} > .modal-dialog");
                $expected = $confirmation
                    ? ['mk-confirm', 'mk-dialog', 'modal-dialog', 'modal-dialog-centered']
                    : ['mk-dialog', 'modal-dialog', 'modal-dialog-centered'];
                $this->assertSame($expected, $this->classes($dialog), "{$page}: #{$id} is the kit's centred dialog (the style decides its place and size)");

                $content = $xpath->query('./div[contains(concat(" ", normalize-space(@class), " "), " modal-content ")]', $dialog)->item(0);
                $this->assertInstanceOf(DOMElement::class, $content, "{$page}: #{$id} > .modal-dialog > .modal-content");
                $this->assertContains('avd-mk', $this->classes($content), "{$page}: #{$id} carries the kit scope");
                $this->assertNotEmpty($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " mk-sheet__head ")]//*[@data-bs-dismiss="modal"]', $content), "{$page}: #{$id} has its close button");
            }

            // no studio dialog fixes its own presentation
            $this->assertSame(0, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " mk-page ")]//*[contains(@class, "modal-fullscreen") or contains(concat(" ", normalize-space(@class), " "), " modal-sm ") or contains(concat(" ", normalize-space(@class), " "), " modal-lg ") or contains(concat(" ", normalize-space(@class), " "), " modal-xl ")]')->length, "{$page}: no fixed dialog size");
            // and there is no other kind of confirmation (the side sheets are the Filters and Visibility panels only)
            foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " mk-page ")]//*[contains(concat(" ", normalize-space(@class), " "), " offcanvas ")]') as $sheet) {
                $this->assertMatchesRegularExpression('/^(mk-visibility-sheet|mk-replies|mkf-[a-z]+-sheet)$/', $sheet->getAttribute('id'), "{$page}: a side panel is a list or a tool, never a confirmation");
            }
        }
    }

    // ------------------------------------------------------------------------------------------ helpers

    /** @return array{video: Video} */
    private function dialogFixture(): array
    {
        $video = $this->video($this->member, ['title' => 'Sunset drive']);
        $this->video($this->member, ['title' => 'Harbour timelapse']);
        $comment = $this->comment($video, $this->viewer, 'Lovely drive.');
        $this->comment($video, $this->member, 'Thank you!', $comment);
        Interaction::query()->create(['user_id' => $this->viewer->id, 'likeable_type' => Video::class, 'likeable_id' => $video->id, 'status' => true, 'perform_at' => now()]);
        DB::table('videos')->where('id', $video->id)->update(['likes_count' => 1]);
        $playlist = Playlist::factory()->for($this->member)->create(['title' => 'Night drives', 'status' => PlaylistStatus::PUBLIC, 'sort' => PlaylistSort::MANUAL, 'is_deletable' => true]);
        DB::table('playlist_has_videos')->insert(['playlist_id' => $playlist->id, 'video_id' => $video->id, 'position' => 0, 'added_at' => now()]);
        $reported = $this->video($this->viewer, ['title' => 'Loud city traffic']);
        Report::query()->create(['user_id' => $this->member->id, 'reportable_type' => Video::class, 'reportable_id' => $reported->id, 'reason' => ReportReason::MISINFORMATION, 'comment' => '', 'status' => ReportStatus::PENDING]);

        return ['video' => $video->fresh()];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    /** @return list<string> sorted */
    private function classes(DOMElement $element): array
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        sort($classes);

        return array_values(array_filter($classes, fn ($class) => $class !== ''));
    }

    /** The opening tag of the page root of the video form. */
    private function rootTag(string $html): string
    {
        $start = strpos($html, '<div class="avd-mk mk-page mk-page--form');
        $this->assertNotFalse($start, 'the form page root is printed');

        return substr($html, $start, strpos($html, '>', $start + 1) - $start + 1);
    }

    private function draft(): Video
    {
        return Video::factory()->for($this->member, 'user')->create([
            'status' => VideoStatus::DRAFT->value,
            'title' => 'Untitled upload',
            'category_id' => null,
            'language' => null,
            'published_at' => null,
        ]);
    }

    private function video(User $owner, array $attributes = []): Video
    {
        return Video::factory()->for($owner, 'user')->create(array_merge([
            'status' => VideoStatus::PUBLIC->value,
            'category_id' => null,
            'language' => null,
            'published_at' => now()->subDays(3),
            'uploaded_at' => now()->subDays(3),
            'created_at' => now()->subDays(3),
        ], $attributes));
    }

    private function comment(Video $video, User $author, string $text, ?Comment $parent = null): Comment
    {
        return Comment::query()->create([
            'video_id' => $video->id,
            'user_id' => $author->id,
            'parent_id' => $parent?->id,
            'content' => $text,
        ]);
    }

    /** @return list<int> */
    private function bulkVideos(User $owner, int $count): array
    {
        $rows = [];
        $now = now();
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'uuid' => (string) Str::uuid(), 'title' => 'Bulk '.$i, 'slug' => 'bulk-'.$i.'-'.Str::lower(Str::random(6)), 'original_file_name' => 'bulk.mp4',
                'user_id' => $owner->id, 'status' => VideoStatus::PUBLIC->value, 'published_at' => $now, 'uploaded_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('videos')->insert($chunk);
        }

        return DB::table('videos')->where('user_id', $owner->id)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
