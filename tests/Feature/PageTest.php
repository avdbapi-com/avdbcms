<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Tag;
use App\Models\TagTranslation;
use App\Models\Video;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_home(): void
    {
        $this
            ->get(route('pages.home'))
            ->assertStatus(200)
            ->assertSee('<meta name="rating" content="adult">', false)
            ->assertSee('<meta name="robots" content="index, follow', false);
    }

    public function test_trend(): void
    {
        $this
            ->get(route('pages.trend'))
            ->assertStatus(200);
    }

    public function test_private_video_show(): void
    {
        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PRIVATE)
            ->create();

        $this
            ->get($video->route)
            ->assertStatus(404);
    }

    public function test_private_video_embed_uses_private_response(): void
    {
        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PRIVATE)
            ->create();

        $this
            ->get(route('video.embed', $video))
            ->assertOk()
            ->assertSee('This video is private');
    }

    public function test_planned_video_show(): void
    {
        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PLANNED)
            ->create([
                'scheduled_at' => now()->addMinutes(30)
            ]);

        $this
            ->get($video->route)
            ->assertStatus(404);
    }

    public function test_planned_public_video_show(): void
    {
        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PLANNED)
            ->create([
                'scheduled_at' => now()->subMinutes(30)
            ]);

        $this
            ->get($video->route)
            ->assertStatus(200);
    }

    public function test_deleted_video_show(): void
    {
        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'deleted_at' => now()->subMinutes(30)
            ]);

        $this
            ->get($video->route)
            ->assertStatus(404);
    }


    public function test_video_show(): void
    {
        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create();

        $this
            ->get($video->route)
            ->assertStatus(200);
    }

    public function test_avdb_video_show_renders_imported_metadata_and_tags(): void
    {
        $this->withoutVite();

        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'AVDB Metadata Video',
                'slug' => 'avdb-metadata-video',
                'description' => 'Imported synopsis for SEO.',
                'avdb_id' => 134,
                'avdb_code' => 'BOKD-197',
                'avdb_actor' => 'Misaki Kaede',
                'avdb_director' => 'Director Name',
                'avdb_writer' => 'Studio Name',
                'avdb_country' => 'Japan',
                'avdb_release_date' => '2026-10-09',
            ]);

        $tag = Tag::create([
            'name' => 'Office Lady',
            'slug' => 'office-lady',
            'usage_count' => 1,
        ]);
        $video->tags()->attach($tag);

        $html = $this->get($video->route)
            ->assertOk()
            ->assertSee('AVDB Metadata Video')
            ->assertSee('<meta name="rating" content="adult">', false)
            ->assertSee('BOKD-197')
            ->assertSee('Misaki Kaede')
            ->assertSee('Director Name')
            ->assertSee('Office Lady')
            ->assertDontSee('AVDB ID')
            ->assertDontSee('Released')
            ->getContent();

        $this->assertStringContainsString('VideoObject', $html);
        $this->assertStringContainsString('q=BOKD-197', $html);
        $this->assertStringContainsString('q=Misaki%20Kaede', $html);
        $this->assertStringContainsString('q=Director%20Name', $html);
        $this->assertStringContainsString('q=Studio%20Name', $html);
        $this->assertStringContainsString('q=Japan', $html);
        $this->assertStringContainsString('Misaki Kaede', $html);
        $this->assertStringContainsString('Office Lady', $html);
    }

    public function test_search_matches_video_tag_name(): void
    {
        $video = $this->videoWithTag('English tag search video', 'Transgender');

        $this
            ->get(route('search.index', ['q' => 'Transgender']))
            ->assertOk()
            ->assertSee($video->title);
    }

    public function test_search_matches_localized_json_tag_label(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\Language::class);
        app()->setLocale('vi');

        $video = $this->videoWithTag('Vietnamese tag search video', 'Transsexuals');

        $this
            ->get(route('search.index', ['q' => 'Tập thể chuyển giới / Nhiều người chuyển giới']))
            ->assertOk()
            ->assertSee($video->title);
    }

    public function test_search_matches_tag_translation_rows(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\Language::class);
        app()->setLocale('fr');

        [$video, $tag] = $this->videoWithTag('French tag translation search video', 'Transgender', true);

        TagTranslation::create([
            'tag_id' => $tag->id,
            'locale' => 'fr',
            'name' => 'Transgenre',
        ]);

        $this
            ->get(route('search.index', ['q' => 'Transgenre']))
            ->assertOk()
            ->assertSee($video->title);
    }

    private function videoWithTag(string $title, string $tagName, bool $returnTag = false)
    {
        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => $title,
                'slug' => \Illuminate\Support\Str::slug($title),
                'description' => 'Search fixture without the tag keyword in the description.',
            ]);

        $tag = Tag::create([
            'name' => $tagName,
            'slug' => \Illuminate\Support\Str::slug($tagName),
            'usage_count' => 1,
        ]);

        $video->tags()->attach($tag);

        return $returnTag ? [$video, $tag] : $video;
    }

    public function test_user_show(): void
    {
        $user = User::factory()->create();

        $this
            ->get($user->route)
            ->assertStatus(200);
    }
}
