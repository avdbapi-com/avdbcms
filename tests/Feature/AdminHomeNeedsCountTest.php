<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Home: the header's "N things need you" and the count of the "Needs you" panel. The slow server checks (status
 * probes) answer after the page appears and the panel is filled in again from a fresh copy of the page
 * (modules/list-probes.ts); the header was not, so it read "2 things need you" next to a panel counting 3 (or no count
 * at all next to 2). The header's count is now a part of the page filled in from the same copy, so both always agree.
 */
class AdminHomeNeedsCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2', 'app.locale' => 'en']);
    }

    public function test_the_header_count_waits_for_the_same_checks_as_the_panel_and_then_agrees_with_it(): void
    {
        $admin = User::factory()->admin()->create();

        // No check answered yet: the header's count waits for exactly the checks the panel waits for.
        $html = (string) $this->actingAs($admin)->get('/admin')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-ad-defer="home-needs"\s+data-ad-defer-probes="([^"]+)"#', $html);
        preg_match('#data-ad-defer="home-needs"\s+data-ad-defer-probes="([^"]+)"#', $html, $panel);
        $this->assertStringContainsString('<span data-ad-defer="home-needs-count" data-ad-defer-probes="'.$panel[1].'">', $html);

        // Every check answered (one of them with a warning): the copy of the page the script fetches says the same
        // number in the header and on the panel's badge, and nothing waits any more.
        foreach (['schedule', 'disk', 'health', 'storage', 'provider'] as $probe) {
            Cache::put('admin.status.'.$probe.'.en', [
                'probe' => $probe,
                'status' => $probe === 'disk' ? 'warn' : 'ok',
                'label' => $probe === 'disk' ? 'The disk is almost full' : 'Fine',
                'detail' => '',
                'action' => [],
                'data' => [],
            ], 600);
        }

        $html = (string) $this->actingAs($admin)->get('/admin')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-ad-defer-probes', substr($html, (int) strpos($html, 'home-needs-count'), 200));
        $this->assertMatchesRegularExpression('#<span data-ad-defer="home-needs-count">(\d+) things? needs? you\.</span>#', $html);
        preg_match('#<span data-ad-defer="home-needs-count">(\d+) things? needs? you\.</span>#', $html, $header);
        preg_match('#id="ad-home-needs"[^>]*>[^<]*</h2>\s*<span class="ad-badge">(\d+)</span>#', $html, $badge);
        $this->assertNotEmpty($badge, 'the panel has its badge');
        $this->assertSame($badge[1], $header[1], 'the header and the panel give the same count');
        $this->assertStringContainsString('The disk is almost full', $html);
    }
}
