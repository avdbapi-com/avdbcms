<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security plan S16: the premium welcome window prints the member's e-mail address as text. An address with a quoted
 * local part may hold markup ("<svg/onload=...>"@example.com passes the e-mail rule); it must never become HTML.
 */
class PremiumModalEscapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_members_address_is_printed_as_text(): void
    {
        $member = User::factory()->create(['email' => '"<svg/onload=alert(1)>"@example.com']);

        // The welcome window's own template, wherever it is shown (1.2.3: /?premium redirects to the /premium page,
        // member design 6.16, which hosts the welcome sheet).
        $this->get('/?premium=1')->assertRedirect();
        $this->actingAs($member);
        $html = view('modals.premium')->render();

        $this->assertStringContainsString('&lt;svg/onload=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<svg/onload', $html);
    }

    public function test_an_ordinary_address_reads_as_before(): void
    {
        $member = User::factory()->create(['email' => 'member@example.com']);

        $this->actingAs($member);
        $html = view('modals.premium')->render();

        $this->assertStringContainsString('<span class="fw-bold">member@example.com', $html);
        $this->assertStringContainsString('href="'.route('user.edit').'"', $html);
    }
}
