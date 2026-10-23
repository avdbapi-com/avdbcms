<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Thumbnail;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class ThumbnailServingTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_receives_real_thumbnail_file_on_sendfile_capable_servers(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $video = Video::factory()->create([
            'user_id' => $user->id,
            'status' => VideoStatus::DRAFT,
            'category_id' => null,
            'language' => null,
        ]);

        $filename = 'generated-thumb.jpg';
        $path = Video::THUMBNAIL_FOLDER.'/'.$filename;

        Storage::disk('local')->put($path, 'generated-thumbnail-bytes');

        $thumbnail = Thumbnail::factory()->generated()->active()->create([
            'video_id' => $video->id,
            'file' => $filename,
        ]);

        $response = $this
            ->withServerVariables(['SERVER_SOFTWARE' => 'LiteSpeed'])
            ->actingAs($user)
            ->get(route('video.thumbnails', [
                'video' => $video->uuid,
                'thumbnail' => $thumbnail->id,
            ]));

        $response->assertOk();

        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertFalse($response->baseResponse->headers->has('X-LiteSpeed-Location'));
        $this->assertSame(
            realpath(Storage::disk('local')->path($path)),
            realpath($response->baseResponse->getFile()->getPathname())
        );
    }

    public function test_missing_thumbnail_file_falls_back_to_placeholder(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $video = Video::factory()->create([
            'user_id' => $user->id,
            'status' => VideoStatus::DRAFT,
            'category_id' => null,
            'language' => null,
        ]);

        $thumbnail = Thumbnail::factory()->generated()->active()->create([
            'video_id' => $video->id,
            'file' => 'missing-thumb.jpg',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('video.thumbnails', [
                'video' => $video->uuid,
                'thumbnail' => $thumbnail->id,
            ]));

        $response->assertOk();

        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame(
            realpath(public_path('images/placeholders/no-image.svg')),
            realpath($response->baseResponse->getFile()->getPathname())
        );
    }
}
