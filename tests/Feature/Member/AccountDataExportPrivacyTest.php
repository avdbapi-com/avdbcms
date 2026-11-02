<?php

namespace Tests\Feature\Member;

use App\Enums\VideoStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * R1-088: "Download my data" (Account settings > Delete account) printed the current title of every video a member had
 * in a playlist, commented on or watched, also of another member's video that went private, became a draft, was
 * banned or deleted since: titles that History and the playlist editor hide. The export keeps the member's own records
 * (the video ids) and prints a title only for the member's own videos and the ones anybody can watch.
 */
class AccountDataExportPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.default_locale' => 'en', 'app.locale' => 'en']);
    }

    private function video(User $owner, string $title, VideoStatus $status, array $extra = []): Video
    {
        return Video::factory()->for($owner)->create($extra + [
            'title' => $title,
            'status' => $status,
            'uploaded_at' => now()->subDay(),
            'published_at' => now()->subDay(),
        ]);
    }

    public function test_the_export_never_prints_the_title_of_another_members_hidden_video(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();

        $public = $this->video($other, 'OTHER PUBLIC CLIP', VideoStatus::PUBLIC);
        $unlisted = $this->video($other, 'OTHER UNLISTED CLIP', VideoStatus::UNLISTED);
        $private = $this->video($other, 'OTHER SECRET PRIVATE', VideoStatus::PRIVATE);
        $draft = $this->video($other, 'OTHER SECRET DRAFT', VideoStatus::DRAFT);
        $banned = $this->video($other, 'OTHER SECRET BANNED', VideoStatus::BANNED);
        $deleted = $this->video($other, 'OTHER SECRET DELETED', VideoStatus::PUBLIC);
        $mine = $this->video($member, 'MY OWN PRIVATE CLIP', VideoStatus::PRIVATE);
        $all = [$public, $unlisted, $private, $draft, $banned, $deleted, $mine];

        // a playlist of the member with all of them, a comment on each and a view of each
        $playlist = Playlist::factory()->for($member)->create(['title' => 'Mixed', 'is_deletable' => true]);
        foreach ($all as $position => $video) {
            DB::table('playlist_has_videos')->insert(['playlist_id' => $playlist->id, 'video_id' => $video->id, 'position' => $position]);
            DB::table('comments')->insert(['user_id' => $member->id, 'video_id' => $video->id, 'content' => 'My comment '.$position, 'ip' => '127.0.0.1', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('views')->insert(['user_id' => $member->id, 'video_id' => $video->id, 'ip' => '127.0.0.1', 'view_at' => now()->subMinutes($position)]);
        }
        $deleted->delete();

        $response = $this->actingAs($member)->get(route('user.export'))->assertOk();
        $content = $response->streamedContent();
        $data = json_decode($content, true);
        $this->assertIsArray($data, 'valid JSON');

        foreach (['OTHER SECRET PRIVATE', 'OTHER SECRET DRAFT', 'OTHER SECRET BANNED', 'OTHER SECRET DELETED'] as $secret) {
            $this->assertStringNotContainsString($secret, $content, $secret);
        }

        $expected = [
            $public->id => 'OTHER PUBLIC CLIP',
            $unlisted->id => 'OTHER UNLISTED CLIP',
            $private->id => null,
            $draft->id => null,
            $banned->id => null,
            $deleted->id => null,
            $mine->id => 'MY OWN PRIVATE CLIP',
        ];

        $list = collect($data['playlists'])->firstWhere('title', 'Mixed');
        $this->assertSame($expected, array_column($list['videos'], 'title', 'id'), 'playlist: every video kept, titles only where allowed');
        $this->assertSame($expected, array_column($data['comments'], 'video_title', 'video_id'), 'comments: every comment kept');
        $this->assertCount(count($all), $data['comments']);
        $this->assertSame($expected, array_column($data['watch_history'], 'video_title', 'video_id'), 'history: every view kept');
    }
}
