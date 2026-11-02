<?php

namespace Tests\Feature\Member;

use App\Http\Controllers\Auth\PhoneVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Account settings after a refusal (round 1 of 1.2.3):
 * - R1-115: a refused phone code (wrong, expired, too many tries) is the code field's error: under the field, the field
 *   marked invalid (and focused by the page), never a message at the top; the profile form's save bar stays hidden. The
 *   country list of the number keeps its country after a refused code (both fields are named "code"), and the number in
 *   "We sent a 5-digit code to ..." keeps its order in a right-to-left language.
 * - R1-032: after a refused save of the section's own form the save bar shows, and its Cancel loads the section again
 *   (the form's own reset kept the refused value and its error).
 */
class AccountSettingsErrorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.default_locale' => 'en', 'app.locale' => 'en', 'services.brevo.api_key' => 'xkeysib-test-0123456789']);
        Cache::flush();
        $this->withoutVite();
    }

    /** The Account settings page as the browser gets it after the redirect (the flashed errors and old input). */
    private function pageAfter(User $member): string
    {
        return $this->actingAs($member->fresh())->get(route('user.edit'))->assertOk()->getContent();
    }

    public function test_a_wrong_code_is_an_error_of_the_code_field(): void
    {
        Queue::fake();
        $member = User::factory()->create(['phone' => '+33612345678']);
        $this->actingAs($member)->from(route('user.edit'))->post(route('phone.send'))->assertRedirect(route('user.edit'));
        $code = (string) $member->fresh()->phone_confirmation_code;
        $wrong = $code === '00000' ? '11111' : '00000';
        $left = PhoneVerification::MAX_TRIES - 1;
        $message = trans_choice(
            '{1} That code is wrong. Check the message and try again. :count try left.|[2,*] That code is wrong. Check the message and try again. :count tries left.',
            $left,
            ['count' => $left]
        );

        $this->actingAs($member)->from(route('user.edit'))->post(route('phone.verify'), ['code' => $wrong])
            ->assertRedirect(route('user.edit'))
            ->assertSessionHasErrors(['code' => $message])
            ->assertSessionMissing('error')
            ->assertSessionHas('phone_verification.tries_left', $left);

        $html = $this->pageAfter($member);
        $this->assertMatchesRegularExpression('/<input class="mk-input" id="phone-otp"[^>]*aria-invalid="true" aria-describedby="code-error"/', $html, 'marked invalid (the page focuses the first invalid field)');
        $this->assertStringContainsString('<span class="mk-error" id="code-error">'.e($message).'</span>', $html, 'under the field');
        $this->assertSame(1, substr_count($html, e($message)), 'once: no message at the top as well');
        $this->assertStringContainsString('x-data="avdAccountSettings(false)"', $html, 'nothing changed in the profile form: no save bar');

        // the JSON answer of a script is unchanged
        $this->actingAs($member)->postJson(route('phone.verify'), ['code' => $wrong])
            ->assertStatus(422)
            ->assertJsonPath('tries_left', $left - 1);
    }

    public function test_an_expired_code_and_a_mistyped_code_are_errors_of_the_code_field_too(): void
    {
        Queue::fake();
        $member = User::factory()->create(['phone' => '+33612345678']);
        $this->actingAs($member)->from(route('user.edit'))->post(route('phone.send'));

        // five digits are needed: a field error in plain words, no try counted
        $this->actingAs($member)->from(route('user.edit'))->post(route('phone.verify'), ['code' => '12ab'])
            ->assertSessionHasErrors(['code' => __('The code has 5 digits. Check the text message and try again.')]);
        $this->assertStringContainsString('x-data="avdAccountSettings(false)"', $this->pageAfter($member));

        $this->travel(PhoneVerification::CODE_MINUTES * 60 + 1)->seconds();
        $this->actingAs($member)->from(route('user.edit'))->post(route('phone.verify'), ['code' => '12345'])
            ->assertSessionHasErrors(['code' => __('This code has expired. Send a new one.')])
            ->assertSessionMissing('error');
    }

    public function test_a_mistyped_code_keeps_the_country_of_the_number_and_the_number_keeps_its_order(): void
    {
        Queue::fake();
        $member = User::factory()->create(['phone' => '+33612345678']);
        $this->actingAs($member)->from(route('user.edit'))->post(route('phone.send'));

        // the code form's field is named "code" like the country list of the number: a refused code is no country
        $this->actingAs($member)->from(route('user.edit'))->post(route('phone.verify'), ['code' => '12ab'])
            ->assertSessionHasErrors('code')
            ->assertSessionHasInput('code', '12ab');

        $html = $this->pageAfter($member);
        $this->assertMatchesRegularExpression('/<select class="mk-group__select" id="phone-code"[\s\S]*?<option value="FR" title="[^"]*" selected>FR \+33<\/option>/', $html, 'the list keeps the country of the saved number');

        // in a right-to-left sentence the number keeps its order: it stands between Unicode isolate marks
        $this->assertStringContainsString("\u{2066}+33 6 12 34 56 78\u{2069}", $html);
    }

    public function test_sending_a_code_still_answers_with_a_message_on_top(): void
    {
        $member = User::factory()->create(['phone' => null]);

        $this->actingAs($member)->from(route('user.edit'))->post(route('phone.send'))
            ->assertSessionHas('error', __('Add your phone number first.'))
            ->assertSessionDoesntHaveErrors();
    }

    public function test_a_refused_save_of_the_form_shows_the_save_bar_whose_cancel_loads_the_stored_values(): void
    {
        User::factory()->create(['username' => 'taken_name']);
        $member = User::factory()->create(['username' => 'my_name']);

        $this->actingAs($member)->from(route('user.edit'))
            ->put(route('user.update'), ['section' => 'profile', 'username' => 'taken_name', 'slug' => (string) $member->slug, 'email' => $member->email])
            ->assertRedirect(route('user.edit'))
            ->assertSessionHasErrors('username');

        $html = $this->pageAfter($member);
        $this->assertStringContainsString('x-data="avdAccountSettings(true)"', $html, 'the bar shows: the form holds what was typed');
        $this->assertStringContainsString('value="taken_name"', $html, 'the refused value is shown to be corrected');
        // (the page root's component is in the cached studio script since 1.2.3 round 3, R3-086)
        $script = \Illuminate\Support\Facades\File::get(resource_path('js/member/studio-pages.js'));
        $this->assertMatchesRegularExpression('/refused:\s*startDirty === true/', $script, 'the page knows it came back from a refusal');
        $this->assertMatchesRegularExpression('/if \(this\.refused\) \{\s*this\.dirty = false;\s*window\.location\.reload\(\);/', $script, 'Cancel loads the section again');

        // loaded again (what Cancel does): the stored value, no error, no bar
        $again = $this->actingAs($member->fresh())->get(route('user.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('value="my_name"', $again);
        $this->assertStringNotContainsString('id="username-error"', $again);
        $this->assertStringContainsString('x-data="avdAccountSettings(false)"', $again);
    }
}
