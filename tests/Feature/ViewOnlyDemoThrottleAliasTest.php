<?php

namespace Tests\Feature;

use App\Http\Middleware\ThrottleExceptViewOnlyRefusals;
use App\Http\Middleware\ViewOnlyDemo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.3: app/Http/Kernel.php's "throttle" alias names ThrottleExceptViewOnlyRefusals, so every route limit of
 * the site leaves a view-only demo account's refusals to its guard (the rules: ViewOnlyDemoRouteLimitsTest).
 */
class ViewOnlyDemoThrottleAliasTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sites_route_limits_leave_a_view_only_accounts_refusals_to_its_guard(): void
    {
        Cache::flush();
        $this->assertSame(ThrottleExceptViewOnlyRefusals::class, app('router')->getMiddleware()['throttle']);

        $demo = User::factory()->create();
        $demo->forceFill([ViewOnlyDemo::COLUMN => true])->save();
        $demo = $demo->fresh();

        // "Download your data" allows 3 in 10 minutes: the 4th and the 5th click still get the refusal and its window.
        for ($click = 1; $click <= 5; $click++) {
            $this->flushHeaders();
            $this->actingAs($demo)->withHeaders(['Referer' => url('/profile/edit'), 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-User' => '?1'])
                ->get('/profile/export')
                ->assertStatus(303)
                ->assertHeader('X-View-Only-Demo-Refusal', 'closed');
        }
    }
}
