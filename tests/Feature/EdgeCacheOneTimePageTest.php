<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Setting;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 1.2.3: a guest page that shows one visitor's flashed message (the toast "Your account is closed"
 * after the account is deleted, a status line, form errors) is never made public for the CDN and the browser, which
 * would show it to every visitor of the address for minutes. The flash data is gone from the session when
 * EdgeCacheVideoHtml looks at the response, so the layout marks the page while it renders (App\Support\OneTimePage).
 */
class EdgeCacheOneTimePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.smart_discovery_rail_enabled' => false,
            'site.creator_spotlight_enabled' => false,
            'site.trending_collections_enabled' => false,
        ]);
        Setting::set('appearance_schema_version', '2', 'appearance');
    }

    public function test_a_guest_page_that_shows_a_flashed_message_is_never_public(): void
    {
        foreach (['toast' => 'Your account is closed.', 'status' => 'Saved.'] as $key => $message) {
            // As the redirect left the session: the message and Laravel's list of what was flashed for this request.
            $response = $this->withSession([$key => $message, '_flash' => ['old' => [$key], 'new' => []]])->get('/')->assertOk();

            $this->assertPrivate($response, $key);
            $this->flushSession();
        }

        // The next view of the page has no message and keeps the edge cache.
        $this->assertStringContainsString('s-maxage=', (string) $this->get('/')->assertOk()->headers->get('Cache-Control'));
    }

    public function test_a_watch_page_the_controller_made_public_is_private_while_it_shows_a_message(): void
    {
        $video = Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()->create();

        $response = $this->withSession(['toast' => 'Thanks for your report.', '_flash' => ['old' => ['toast'], 'new' => []]])
            ->get($video->route)->assertOk();
        $this->assertStringContainsString('Thanks for your report.', (string) $response->getContent());
        $this->assertPrivate($response, 'watch page');

        $this->flushSession();
        $this->assertStringContainsString('s-maxage=', (string) $this->get($video->route)->assertOk()->headers->get('Cache-Control'));
    }

    private function assertPrivate(TestResponse $response, string $case): void
    {
        $cache = (string) $response->headers->get('Cache-Control');

        $this->assertStringNotContainsString('public', $cache, $case);
        $this->assertStringNotContainsString('s-maxage', $cache, $case);
        $this->assertStringContainsString('private', $cache, $case);
        $this->assertSame('no-store', $response->headers->get('Cloudflare-CDN-Cache-Control'), $case);
    }
}
