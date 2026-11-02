<?php

namespace Tests\Feature\Studio;

use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lean studio HTML (member design 4.3 page-content budgets, studio-20):
 * - a row prints its numbers, never Blade's own words (the comments cell of a video without comments printed
 *   "0@else" and the dash after it: "0@else" is no directive for Blade);
 * - the counted words of the bulk bars and dialogs are literal trans_choice() keys, so the translation tools find and
 *   translate them (passed through a helper they were invisible: every language showed them in English);
 * - rows carry no menu element and no duplicated data: the menu is built on open from one template and one JSON of
 *   address templates; links are root-relative; the content section goes out without its template indentation, and a
 *   description's own line breaks and indentation are kept exactly.
 */
class StudioLeanHtmlTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $viewer;

    private Video $video;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('thumbnails');
        $this->member = User::factory()->create(['username' => 'nightdrive_tv']);
        $this->viewer = User::factory()->create(['username' => 'qa_studio_viewer']);
        $this->video = $this->video($this->member, ['title' => 'Sunset drive along the coast road in 4K', 'views' => 140]);
    }

    public function test_a_video_without_comments_prints_zero_and_no_blade_directive(): void
    {
        $html = $this->actingAs($this->member)->get(route('user.videos.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~<div class="mk-row__cell mk-row__cell--comments mk-row__num">0</div>~', $html);
        foreach (['@else', '@elseif', '@endif', '@unless', '@endunless'] as $directive) {
            $this->assertStringNotContainsString($directive, $html, 'the page prints Blade\'s '.$directive);
        }
    }

    public function test_the_counted_words_are_literal_keys_the_translation_tools_find(): void
    {
        require_once base_path('scripts/i18n/I18n.php');

        $expected = [
            'users/videos/index.blade.php' => [
                ':count video selected|:count videos selected', 'Delete :count video?|Delete :count videos?', 'Delete :count video|Delete :count videos',
                'Make :count video private|Make :count videos private', 'Make :count video public|Make :count videos public',
                'Make :count video unlisted|Make :count videos unlisted', 'Change the visibility of :count video|Change the visibility of :count videos',
                'and :count more|and :count more', ':count comment and its replies|:count comments and their replies', ':count like|:count likes',
                ':count dislike|:count dislikes', 'Its place in :count playlist|Its place in :count playlists',
            ],
            'users/comments/index.blade.php' => [':count comment selected|:count comments selected', 'Remove :count comment?|Remove :count comments?', 'Remove :count comment|Remove :count comments'],
            'users/playlists/index.blade.php' => [':count playlist selected|:count playlists selected', 'Delete :count playlist?|Delete :count playlists?', 'Delete :count playlist|Delete :count playlists'],
        ];

        foreach ($expected as $view => $keys) {
            $found = \Avd\I18n\Extractor::fromSource((string) file_get_contents(resource_path('views/'.$view)));
            foreach ($keys as $key) {
                $this->assertContains($key, $found, $view.' must use "'.$key.'" as a literal translation key');
            }
        }
    }

    public function test_my_videos_rows_are_lean_and_their_menus_come_from_one_template(): void
    {
        $this->video($this->member, ['title' => 'Untitled upload 0425', 'status' => VideoStatus::DRAFT->value, 'published_at' => null]);
        $origin = request()->getSchemeAndHttpHost();

        $html = $this->actingAs($this->member)->get(route('user.videos.index'))->assertOk()->getContent();
        $root = $this->pageRoot($html);

        // no menu element and no absolute link of the site inside the rows; the row's own links are root-relative
        $this->assertStringNotContainsString('class="dropdown-menu dropdown-menu-end mk-menu"', $root);
        $this->assertStringContainsString('href="/profile/videos/'.$this->video->id.'/edit"', $root);
        $this->assertDoesNotMatchRegularExpression('~<li class="mk-row".*?(href|src)="'.preg_quote($origin, '~').'/.*?</li>~s', $root);
        $this->assertStringContainsString('data-mk-menu-tpl="mk-video-menu" data-mk-menu-data="mk-video-rows"', $root);

        // one JSON: address templates, the items each row has, the counted words of this page's counts
        preg_match('~<script type="application/json" id="mk-video-rows">(.*?)</script>~s', $html, $m);
        $data = json_decode($m[1] ?? 'null', true);
        $this->assertSame('/profile/videos/__ID__/edit', $data['u']['edit']);
        $this->assertSame('/profile/videos/__ID__', $data['u']['delete']);
        $row = $data['r'][$this->video->id];
        $this->assertSame(1, $row['edit']);
        $this->assertSame(1, $row['pin']);
        $this->assertArrayNotHasKey('unpin', $row);
        $this->assertStringStartsWith('/', $row['view']);
        $this->assertSame('0 comments and their replies', $data['w']['c'][0]);
        $this->assertSame(':likes and :dislikes', $data['w']['and']);

        // the counted words as runs: one text per form, the number marked
        preg_match('~<script type="application/json" id="mk-video-labels">(.*?)</script>~s', $html, $m);
        $labels = json_decode($m[1] ?? 'null', true);
        $this->assertSame([[1, "\u{1} video selected"], [2, "\u{1} videos selected"]], $labels['selected']);

        // no template indentation left between tags
        $this->assertStringNotContainsString("\n    <", $root);
        $this->assertStringNotContainsString(">\n    ", $root);
    }

    public function test_a_description_keeps_its_own_line_breaks_and_indentation(): void
    {
        $text = "Chapters:\n    01:30 The coast\n\n        04:10 The light";
        $this->video->forceFill(['description' => $text])->save();

        $html = $this->actingAs($this->member)->get(route('user.videos.edit', $this->video))->assertOk()->getContent();

        $this->assertStringContainsString('>'.e($text).'</textarea>', $html);
    }

    public function test_comment_rows_carry_no_duplicated_addresses_or_texts(): void
    {
        $comment = $this->comment($this->video, $this->viewer, 'Wow, the light at 04:10 is unreal.');
        $this->comment($this->video, $this->member, 'Thank you!', $comment);
        $this->comment($this->video, $this->viewer, str_repeat('A long comment about the cabin build. ', 20));

        $html = $this->actingAs($this->member)->get(route('user.comments.index'))->assertOk()->getContent();
        $root = $this->pageRoot($html);

        $this->assertStringNotContainsString('data-title=', $root);
        $this->assertStringNotContainsString('data-action=', $root);
        $this->assertStringNotContainsString('x-data="{ open: false }"', $root);
        $this->assertStringContainsString('data-mk-delete="'.$comment->id.'"', $root);
        // dir="auto": a comment keeps its own direction in every language (1.2.3 round 1, R1-025)
        $this->assertStringContainsString('<p class="mk-clamp-3" data-mk-name data-mk-clamp dir="auto">Wow, the light at 04:10 is unreal.</p>', $root);
        $this->assertMatchesRegularExpression('~href="/profile/comments/'.$comment->id.'/replies" data-mk-replies>~', $root);
        $this->assertStringContainsString('data-mk-more', $root);

        preg_match('~<script type="application/json" id="mk-comment-rows">(.*?)</script>~s', $html, $m);
        $data = json_decode($m[1] ?? 'null', true);
        $this->assertSame('/profile/comments/__ID__', $data['u']['delete']);
        $this->assertCount(1, $data['s'], 'the video\'s address is written once for its comments');
        $this->assertSame([0], $data['r'][$comment->id]['view']);
    }

    public function test_playlist_rows_name_their_title_for_the_dialog(): void
    {
        $playlist = Playlist::factory()->for($this->member)->create(['title' => 'Night drives', 'status' => PlaylistStatus::PUBLIC, 'sort' => PlaylistSort::MANUAL, 'is_deletable' => true]);
        DB::table('playlist_has_videos')->insert(['playlist_id' => $playlist->id, 'video_id' => $this->video->id, 'position' => 0, 'added_at' => now()]);

        $html = $this->actingAs($this->member)->get(route('user.playlists.index'))->assertOk()->getContent();

        // the title isolated (<bdi>): it keeps its own direction on an Arabic page (1.2.3 round 1, R1-025)
        $this->assertMatchesRegularExpression('~<a class="mk-item__title" href="[^"]+" data-mk-name><bdi>Night drives</bdi></a>~', $html);
        preg_match('~<script type="application/json" id="mk-playlist-rows">(.*?)</script>~s', $html, $m);
        $data = json_decode($m[1] ?? 'null', true);
        $this->assertSame('/profile/playlists/__ID__', $data['u']['delete']);
        $this->assertSame(1, $data['r'][$playlist->id]['delete']);
        $this->assertSame(1, $data['r'][$playlist->id]['copy']);
    }

    // ---------------------------------------------------------------------------------------------------- helpers

    /** The page's .mk-page root, up to its own closing </div> (the layout around it is not the page's). */
    private function pageRoot(string $html): string
    {
        $start = strpos($html, '<div class="avd-mk mk-page');
        $this->assertNotFalse($start, 'the page prints the kit root');
        preg_match_all('~<div\b|</div\s*>~i', $html, $tags, PREG_OFFSET_CAPTURE, $start);
        $depth = 0;

        foreach ($tags[0] as [$tag, $offset]) {
            $depth += str_starts_with($tag, '</') ? -1 : 1;

            if ($depth === 0) {
                return substr($html, $start, $offset + strlen($tag) - $start);
            }
        }

        $this->fail('the kit root is never closed');
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
}
