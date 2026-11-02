<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Upload video" in the account panel closes the panel and opens the upload window (member design 5.14). The panel's
 * button is hidden by then, so Bootstrap cannot give the focus back to it when the window closes: the focus fell on
 * the page itself (keyboard and screen-reader users lost their place). The button now hands the focus to the header's
 * account button, the control that opened the panel, once the window is closed.
 */
class AccountPanelUploadFocusTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_panels_upload_gives_the_focus_back_to_the_account_button(): void
    {
        $member = User::factory()->create();
        $html = (string) $this->actingAs($member)->get('/trend')->assertOk()->getContent();

        preg_match('#<button type="button" class="mk-account__link" data-bs-toggle="modal" data-bs-target="\#video_create"\s+@click="([^"]+)"#', $html, $button);
        $this->assertNotEmpty($button, 'the panel has its Upload video button');
        $click = html_entity_decode($button[1], ENT_QUOTES);

        // it still closes the panel first (5.14) ...
        $this->assertStringStartsWith("window.bootstrap && window.bootstrap.Offcanvas.getInstance(\$el.closest('.offcanvas'))", $click);
        // ... and once the upload window is hidden, the focus goes to the header's account button (once)
        $this->assertStringContainsString("addEventListener('hidden.bs.modal'", $click);
        $this->assertStringContainsString('.avd-avatar-button', $click);
        $this->assertStringContainsString('{ once: true }', $click);
        // the header's account button exists on the page to receive it
        $this->assertStringContainsString('class="avd-avatar-button"', $html);
    }
}
