<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AdminText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The admin's lists write a relative time short ("48s ago", "hace 48s") only in a language that has short units of its
 * own (Carbon). Arabic has none and Tagalog lacks most, and Carbon took English's units instead: "منذ 48s", "4h ang
 * nakalipas" in 14 lists (Admin actions, comments, reports, sessions ...). Those languages now get the time written out
 * ("منذ 48 ثانية"); every other language keeps its short form.
 */
class AdminShortTimesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_short_times_only_where_the_language_has_short_units(): void
    {
        $this->assertFalse(AdminText::shortTimes('ar'));
        $this->assertFalse(AdminText::shortTimes('tl'));

        foreach (['en', 'de', 'es', 'fil', 'fr', 'hi', 'id', 'ja', 'ko', 'ms', 'pt', 'ru', 'th', 'tr', 'vi', 'zh'] as $locale) {
            $this->assertTrue(AdminText::shortTimes($locale), $locale);
        }

        $this->assertTrue(AdminText::shortTimes('xx'), 'a language Carbon does not know: as before');
    }

    public function test_the_admin_actions_list_writes_arabic_times_without_english_unit_letters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00'));
        config(['admin.operational_tools_enabled' => true, 'admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create(['language' => 'ar']);
        DB::table('admin_audit_logs')->insert(['user_id' => $admin->id, 'action' => 'update.check', 'details' => 'current 1.2.3 (api)', 'created_at' => '2026-10-28 11:59:12']);

        $arabic = (string) $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->get('/admin/audit')->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/منذ \d+(?:s|m|h|d|w|y|min)\b/u', $arabic, 'no English unit letters in an Arabic list');
        $this->assertStringContainsString('منذ 48 ثانية', $arabic);

        $reader = User::factory()->admin()->create(['language' => 'en']);
        $english = (string) $this->actingAs($reader)->withSession(['app_locale' => 'en'])->get('/admin/audit')->assertOk()->getContent();
        $this->assertStringContainsString('48s ago', $english, 'English keeps its short form');
    }
}
