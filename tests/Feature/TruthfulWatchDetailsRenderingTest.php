<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Actions\Video\ShowVideoAction;
use App\Models\Video;
use App\Http\Controllers\VideoController;
use App\Services\SeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TruthfulWatchDetailsRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_studio_panel_and_details_modal_render_stored_values_only(): void
    {
        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Truthful Details Video',
                'slug' => 'truthful-details-video',
                'uuid' => '11111111-1111-4111-8111-111111111111',
                'xvid_quality' => 'Source label 720p',
                'duration' => 3723,
            ]);

        $request = Request::create($video->route.'?watch_layout=studio_dashboard', 'GET');
        $this->app->instance('request', $request);
        $response = app(VideoController::class)->show(
            $video->slug,
            $video,
            app(ShowVideoAction::class),
            app(SeoService::class),
        );
        $html = $response->getContent();

        $this->assertGreaterThanOrEqual(2, substr_count($html, 'Video details'));
        $this->assertStringContainsString('Source Quality', $html);
        $this->assertStringContainsString('Source label 720p', $html);
        $this->assertStringContainsString('1:02:03', $html);
        $this->assertStringContainsString('11111111-1111-4111-8111-111111111111', $html);

        foreach ([
            'STUDIO STREAM TELEMETRY',
            'LIVE MONITOR',
            '1080p FHD',
            'MP4 / HLS',
            '6,200 Kbps',
            '2,800 Kbps',
            '60 FPS',
            'AAC-LC Stereo',
            'CLUSTER STATUS:',
            'CDN EDGE:',
            'Engine Architecture:',
            'Hardware Accelerated (CSS3 / WebGL)',
        ] as $unsupportedClaim) {
            $this->assertStringNotContainsString($unsupportedClaim, $html);
        }
    }
}
