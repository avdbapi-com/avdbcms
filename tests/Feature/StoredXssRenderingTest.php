<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StoredXssRenderingTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = '<script>alert("xss")</script><img src=x onerror=alert(1)>';

    public function test_comment_api_returns_escaped_rendered_html_for_stored_comment_payloads(): void
    {
        $creator = User::factory()->create();
        $commenter = User::factory()->create();
        $video = Video::factory()
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create(['allow_comments' => true]);

        Comment::factory()
            ->for($video)
            ->for($commenter, 'user')
            ->createQuietly([
                'content' => self::PAYLOAD . ' https://example.com',
            ]);

        $comment = $this->getJson(route('comments.index', $video->uuid))
            ->assertOk()
            ->json('data.0');

        $this->assertSame(self::PAYLOAD . ' https://example.com', $comment['content']);

        foreach (['parsed_content', 'short_content'] as $field) {
            $this->assertExecutablePayloadAbsent($comment[$field]);
            $this->assertEscapedPayloadPresent($comment[$field]);
            $this->assertStringContainsString('<a class="text-decoration-none"', $comment[$field]);
        }
    }

    public function test_watch_page_escapes_stored_video_title_and_description_payloads(): void
    {
        $creator = User::factory()->create();
        $video = Video::factory()
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => self::PAYLOAD,
                'slug' => 'stored-xss-video',
                'description' => self::PAYLOAD,
                'allow_comments' => true,
            ]);

        $html = $this->get($video->route)
            ->assertOk()
            ->getContent();

        $this->assertExecutablePayloadAbsent($html);
        $this->assertEscapedPayloadPresent($html);
    }

    public function test_profile_and_playlist_pages_escape_stored_user_playlist_payloads(): void
    {
        $user = User::factory()->create([
            'username' => self::PAYLOAD,
            'slug' => 'stored-xss-profile',
            'description' => self::PAYLOAD,
            'website' => 'example.com',
        ]);

        $profileHtml = $this->get(route('user.show', $user))
            ->assertOk()
            ->getContent();

        $this->assertExecutablePayloadAbsent($profileHtml);
        $this->assertEscapedPayloadPresent($profileHtml);

        $playlist = Playlist::factory()
            ->for($user)
            ->create([
                'title' => self::PAYLOAD,
                'description' => self::PAYLOAD,
                'status' => PlaylistStatus::PUBLIC,
                'is_deletable' => true,
            ]);

        $playlistHtml = $this->get(route('playlist.show', $playlist))
            ->assertOk()
            ->getContent();

        $this->assertExecutablePayloadAbsent($playlistHtml);
        $this->assertEscapedPayloadPresent($playlistHtml);
    }

    public function test_layout_escapes_admin_configured_seo_string_payloads(): void
    {
        Config::set('app.name', self::PAYLOAD);
        Config::set('site.meta_title_template', '{title} - ' . self::PAYLOAD . ' - {site_name}');
        Config::set('site.site_keywords', self::PAYLOAD);

        $html = $this->get(route('pages.home'))
            ->assertOk()
            ->getContent();

        $this->assertExecutablePayloadAbsent($html);
        $this->assertEscapedPayloadPresent($html);
    }

    private function assertExecutablePayloadAbsent(string $content): void
    {
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $content);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $content);
    }

    private function assertEscapedPayloadPresent(string $content): void
    {
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $content);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $content);
    }
}
