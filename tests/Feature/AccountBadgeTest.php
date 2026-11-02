<?php

namespace Tests\Feature;

use App\Http\Middleware\ViewOnlyDemo;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The badge under the name in the account panel, the back sidebar and the profile: "View-only demo" for a view-only
 * demo account (administrator or not), "Administrator", "Premium account", "Standard account".
 */
class AccountBadgeTest extends TestCase
{
    use RefreshDatabase;

    private const BADGES = [
        'View-only demo' => 'fa-eye',
        'Administrator' => 'fa-shield-halved',
        'Premium account' => 'fa-star',
        'Standard account' => null,
    ];

    public function test_an_administrator_is_shown_as_administrator_in_all_three_places(): void
    {
        $this->assertBadge(User::factory()->admin()->create(), 'Administrator');
    }

    public function test_a_view_only_demo_account_is_shown_as_such(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->forceFill([ViewOnlyDemo::COLUMN => true])->save();
        $this->assertBadge($admin->fresh(), 'View-only demo');

        $member = User::factory()->create();
        $member->forceFill([ViewOnlyDemo::COLUMN => true])->save();
        $this->assertBadge($member->fresh(), 'View-only demo');
    }

    public function test_premium_and_standard_members(): void
    {
        $premium = User::factory()->create();
        Subscription::factory()->for($premium)->for(Plan::factory())->create(['ends_at' => null]);
        $this->assertTrue($premium->fresh()->is_premium);
        $this->assertBadge($premium->fresh(), 'Premium account');

        $this->assertBadge(User::factory()->create(), 'Standard account');
    }

    /** The profile page shows all three: the account panel, the back sidebar and the profile card. */
    private function assertBadge(User $user, string $label): void
    {
        $html = $this->actingAs($user)->get(route('user.edit'))->assertOk()->getContent();
        $badges = preg_match_all('/<div class="badge [^"]*">\s*(?:<i class="fa-solid ([a-z-]+)" aria-hidden="true"><\/i>\s*)?(?:<span>)?([^<]+?)(?:<\/span>)?\s*<\/div>/', $html, $found, PREG_SET_ORDER);
        $shown = array_values(array_filter($found, fn (array $match) => array_key_exists(trim($match[2]), self::BADGES)));

        $this->assertCount(3, $shown, "{$label}: the account panel, the back sidebar and the profile card");

        foreach ($shown as $match) {
            $this->assertSame($label, trim($match[2]));

            if (self::BADGES[$label] !== null) {
                $this->assertSame(self::BADGES[$label], $match[1]);
            }
        }
    }
}
