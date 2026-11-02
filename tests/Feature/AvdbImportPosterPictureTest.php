<?php

namespace Tests\Feature;

use App\Console\Commands\ImportAvdbVideos;
use App\Models\Thumbnail;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Security plan S20, part 3: an AVDB import in download mode stores the provider's poster as the video's thumbnail only
 * when it is a real picture; an HTML or script body (a hostile provider, or someone on the network path) is not stored.
 */
class AvdbImportPosterPictureTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_real_poster_picture_is_stored(): void
    {
        Storage::fake('local');
        $image = imagecreatetruecolor(32, 18);
        ob_start();
        imagejpeg($image, null, 85);
        $jpeg = (string) ob_get_clean();

        Http::preventStrayRequests();
        Http::fake([
            'posters.example.com/html.jpg' => Http::response("\xFF\xD8<!DOCTYPE html><script>alert(1)</script>", 200, ['Content-Type' => 'image/jpeg']),
            'posters.example.com/real.jpg' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $video = Video::factory()->for(User::factory())->create();
        $download = new ReflectionMethod(ImportAvdbVideos::class, 'downloadThumbnail');
        $command = app(ImportAvdbVideos::class);

        $download->invoke($command, $video, ['poster_url' => 'https://posters.example.com/html.jpg']);
        $this->assertSame(0, Thumbnail::query()->where('video_id', $video->id)->count());

        $download->invoke($command, $video, ['poster_url' => 'https://posters.example.com/real.jpg']);
        $thumbnail = Thumbnail::query()->where('video_id', $video->id)->firstOrFail();
        $this->assertSame($jpeg, Storage::disk('local')->get(Video::THUMBNAIL_FOLDER.'/'.$thumbnail->file));
    }
}
