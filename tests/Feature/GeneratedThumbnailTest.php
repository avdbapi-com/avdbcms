<?php

namespace Tests\Feature;

use App\Enums\ThumbnailStatus;
use App\Enums\VideoStatus;
use App\Models\Thumbnail;
use App\Models\User;
use App\Models\Video;
use App\Support\Upload18\JpegImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Security plan S20: a "thumbnail" that only starts with the JPEG marker and carries HTML or script was stored
 * as the active thumbnail and served as text/html, so opening its address ran script on the site. Now only real JPEG
 * pictures are stored (decoded and encoded again by the server), and every stored thumbnail is answered as a picture with
 * nosniff, whatever is in the file.
 */
class GeneratedThumbnailTest extends TestCase
{
    use RefreshDatabase;

    private const HTML = "\xFF\xD8<!DOCTYPE html><html><body><script>document.title='pwned'</script></body></html>";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_html_behind_a_jpeg_marker_is_refused_and_nothing_changes(): void
    {
        [$owner, $video, $active] = $this->videoWithActiveThumbnail();

        foreach ([self::HTML, "\xFF\xD8\xFF\xD9", "\xFF\xD8\xFF<svg onload=alert(1)>"] as $payload) {
            $this->actingAs($owner)
                ->postJson(route('videos.generate-thumbnails', $video->id), ['thumbnails' => ['data:image/jpeg;base64,'.base64_encode($payload)]])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['thumbnails']);
        }

        $this->assertSame([$active->id], $video->thumbnails()->pluck('id')->all());
        $this->assertTrue((bool) $active->fresh()->is_active);
    }

    public function test_a_real_jpeg_is_stored_as_a_picture_the_server_wrote(): void
    {
        [$owner, $video] = $this->videoWithActiveThumbnail();
        // A real picture with an HTML tail after its end marker: the stored file keeps the pixels, not the tail.
        $sent = $this->jpegBytes(320, 180).'<script>alert(1)</script>';

        $response = $this->actingAs($owner)
            ->postJson(route('videos.generate-thumbnails', $video->id), ['thumbnails' => ['data:image/jpeg;base64,'.base64_encode($sent)]])
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.status', ThumbnailStatus::GENERATED->value);

        $thumbnail = Thumbnail::query()->findOrFail($response->json('data.0.id'));
        $stored = Storage::disk('local')->get(Video::THUMBNAIL_FOLDER.'/'.$thumbnail->file);

        $info = getimagesizefromstring($stored);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame([320, 180], [$info[0], $info[1]]);

        if (JpegImage::canReencode()) {
            $this->assertStringNotContainsString('<script', $stored);
            $this->assertNotSame($sent, $stored);
        }
    }

    public function test_frames_sent_with_the_finish_are_cleaned_too(): void
    {
        config(['services.upload18.api_key' => 'site-key-for-tests-0002', 'services.upload18.api_url' => 'https://upload18.test/api']);
        Http::fake(fn (HttpRequest $request) => Http::response([
            'status' => 'success',
            'data' => [['id' => 4242, 'vid' => 'vidS20', 'zt' => '2', 'duration' => '00:10', 'size_bytes' => 2048]],
            'total_page' => 1,
        ]));
        $member = User::factory()->create();

        $this->actingAs($member)->getJson(route('videos.upload18.status', ['did' => '4242', 'vid' => 'vidS20']))->assertOk();
        $this->actingAs($member)
            ->postJson(route('videos.upload18.complete'), [
                'did' => '4242',
                'vid' => 'vidS20',
                'filename' => 'frames.mp4',
                'size' => 2048,
                'thumbnails' => [
                    'data:image/jpeg;base64,'.base64_encode(self::HTML),
                    'data:image/jpeg;base64,'.base64_encode($this->jpegBytes(64, 36)),
                ],
            ])
            ->assertOk();

        $video = Video::query()->where('user_id', $member->id)->firstOrFail();
        $thumbnails = $video->thumbnails()->get();

        $this->assertCount(1, $thumbnails);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring(Storage::disk('local')->get(Video::THUMBNAIL_FOLDER.'/'.$thumbnails->first()->file))[2]);
    }

    public function test_a_stored_thumbnail_is_never_answered_as_a_page(): void
    {
        [$owner, $video, $active] = $this->videoWithActiveThumbnail(VideoStatus::PUBLIC);
        Storage::disk('local')->put(Video::THUMBNAIL_FOLDER.'/'.$active->file, self::HTML);

        // The active thumbnail of a public video (any visitor) and one thumbnail by id (its owner only).
        $responses = [
            $this->get(route('video.thumbnail', $video)),
            $this->actingAs($owner)->get(route('video.thumbnails', ['video' => $video->uuid, 'thumbnail' => $active->id])),
        ];

        foreach ($responses as $response) {
            $response->assertOk();
            $this->assertSame('image/jpeg', strtolower(explode(';', (string) $response->headers->get('Content-Type'))[0]));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertStringNotContainsStringIgnoringCase('text/html', (string) $response->headers->get('Content-Type'));
        }

        // An SVG file under a thumbnail name is not served as SVG either.
        Storage::disk('local')->put(Video::THUMBNAIL_FOLDER.'/'.$active->file, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');
        $type = (string) $this->get(route('video.thumbnail', $video))->assertOk()->headers->get('Content-Type');
        $this->assertSame('image/jpeg', strtolower(explode(';', $type)[0]));
    }

    public function test_png_and_webp_thumbnails_keep_their_type(): void
    {
        [$owner, $video, $active] = $this->videoWithActiveThumbnail(VideoStatus::PUBLIC);
        $image = imagecreatetruecolor(16, 9);

        ob_start();
        imagepng($image);
        Storage::disk('local')->put(Video::THUMBNAIL_FOLDER.'/'.$active->file, (string) ob_get_clean());
        $this->assertSame('image/png', strtolower(explode(';', (string) $this->get(route('video.thumbnail', $video))->headers->get('Content-Type'))[0]));

        if (function_exists('imagewebp')) {
            ob_start();
            imagewebp($image);
            Storage::disk('local')->put(Video::THUMBNAIL_FOLDER.'/'.$active->file, (string) ob_get_clean());
            $this->assertSame('image/webp', strtolower(explode(';', (string) $this->get(route('video.thumbnail', $video))->headers->get('Content-Type'))[0]));
        }

        Storage::disk('local')->put(Video::THUMBNAIL_FOLDER.'/'.$active->file, $this->jpegBytes(16, 9));
        $this->assertSame('image/jpeg', strtolower(explode(';', (string) $this->get(route('video.thumbnail', $video))->headers->get('Content-Type'))[0]));
    }

    public function test_jpeg_image_keeps_only_sane_jpeg_pictures(): void
    {
        $this->assertNull(JpegImage::sanitize('', 2048));
        $this->assertNull(JpegImage::sanitize(self::HTML, 1 << 20));
        $this->assertNull(JpegImage::sanitize($this->jpegBytes(64, 36), 10), 'larger than the limit');

        ob_start();
        imagepng(imagecreatetruecolor(8, 8));
        $this->assertNull(JpegImage::sanitize((string) ob_get_clean(), 1 << 20), 'a PNG is no JPEG thumbnail');

        $clean = JpegImage::sanitize($this->jpegBytes(64, 36), 1 << 20);
        $this->assertNotNull($clean);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($clean)[2]);
    }

    /** @return array{0: User, 1: Video, 2: Thumbnail} */
    private function videoWithActiveThumbnail(VideoStatus $status = VideoStatus::DRAFT): array
    {
        $owner = User::factory()->create();
        $video = Video::factory()->for($owner)->create([
            'status' => $status,
            'category_id' => null,
            'language' => null,
            'published_at' => now()->subDay(),
            'uploaded_at' => now()->subDay(),
        ]);

        Storage::disk('local')->put(Video::THUMBNAIL_FOLDER.'/active-thumb.jpg', $this->jpegBytes(64, 36));
        $active = Thumbnail::factory()->generated()->active()->create(['video_id' => $video->id, 'file' => 'active-thumb.jpg']);

        return [$owner, $video, $active];
    }

    private function jpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }
}
