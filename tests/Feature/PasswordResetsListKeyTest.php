<?php

namespace Tests\Feature;

use App\Http\Middleware\ViewOnlyDemo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Security plan S23: the Password resets list keyed each row with the first 10 characters of the md5 of its e-mail
 * address. A view-only viewer sees the addresses masked, but could still confirm a guessed address offline by its md5.
 * The key is now an HMAC with the site's key: stable per row, not computable without APP_KEY.
 */
class PasswordResetsListKeyTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESSES = ['guess.me@private-owner.test', 'second.member@example.test'];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['admin.ui' => 'v2']);

        foreach (self::ADDRESSES as $email) {
            DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => 'hash', 'created_at' => now()]);
        }
    }

    public function test_a_view_only_viewer_gets_no_key_derived_from_an_address(): void
    {
        $demo = User::factory()->admin()->create();
        $demo->forceFill([ViewOnlyDemo::COLUMN => true])->save();

        $html = (string) $this->actingAs($demo->fresh())->get('/admin/password-resets')->assertOk()->getContent();

        foreach (self::ADDRESSES as $email) {
            $this->assertStringNotContainsString(substr(md5($email), 0, 10), $html, 'no md5 of '.$email);
            $this->assertStringNotContainsString($email, $html, 'the address itself is masked');
        }
    }

    public function test_an_administrator_still_gets_working_row_menus(): void
    {
        $admin = User::factory()->admin()->create();

        $html = (string) $this->actingAs($admin)->get('/admin/password-resets')->assertOk()->getContent();

        foreach (self::ADDRESSES as $email) {
            $key = 'ad-r-'.substr(hash_hmac('sha256', $email, (string) config('app.key')), 0, 10);
            $this->assertStringContainsString('id="'.$key.'"', $html);
            $this->assertStringContainsString('aria-labelledby="ad-l-act '.$key.'"', $html);
            $this->assertStringContainsString('data-id="'.$key.'"', $html);
            $this->assertStringContainsString('data-url="'.route('admin.password-resets.delete', $email, false).'"', $html);
            $this->assertStringNotContainsString(substr(md5($email), 0, 10), $html);
        }

        // The row menu's delete still removes the row.
        $this->actingAs($admin)->delete(route('admin.password-resets.delete', self::ADDRESSES[0]))->assertRedirect();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => self::ADDRESSES[0]]);
    }
}
