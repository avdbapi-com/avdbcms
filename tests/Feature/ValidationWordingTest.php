<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * R1-121: the validation sentences a member meets on the account and sign-up forms.
 * - English: Laravel's "The :attribute ..." with the forms' capitalised field names read "The Username has already been
 *   taken." and "The New password must be at least 8 characters.": the field name now starts the sentence ("Username is
 *   already taken."), and the e-mail rule asks for an address without repeating the field name.
 * - Russian and Arabic: the sentences agree with any field name (Russian: the field "Поле «...»" is their subject;
 *   Arabic: "حقل ..." and a count of letters instead of a counted noun).
 */
class ValidationWordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.default_locale' => 'en', 'app.locale' => 'en', 'site.captcha_provider' => 'none', 'site.max_register_attempts' => 1000]);
        RateLimiter::clear('register|127.0.0.1');
    }

    /** The profile save of $member refused for a taken user name, in $locale: the user name's error. */
    private function takenUsernameError(string $locale): string
    {
        User::factory()->create(['username' => 'taken_'.$locale]);
        $member = User::factory()->create(['username' => 'mine_'.$locale]);

        $this->withSession(['app_locale' => $locale])->actingAs($member)->from(route('user.edit'))
            ->put(route('user.update'), ['section' => 'profile', 'username' => 'taken_'.$locale, 'slug' => (string) $member->slug, 'email' => $member->email])
            ->assertSessionHasErrors('username');

        return (string) session('errors')->first('username');
    }

    /** The password change of a new member refused for $password / $confirmation, in $locale. */
    private function newPasswordError(string $locale, string $password, string $confirmation): string
    {
        $member = User::factory()->create();

        $this->withSession(['app_locale' => $locale])->actingAs($member)->from(route('user.edit', ['section' => 'security']))
            ->post(route('user.update.password'), ['current_password' => 'password', 'new_password' => $password, 'new_password_confirmation' => $confirmation])
            ->assertSessionHasErrors('new_password');

        return (string) session('errors')->first('new_password');
    }

    public function test_english_sentences_start_with_the_field_name(): void
    {
        $this->assertSame(__('Username').' is already taken.', $this->takenUsernameError('en'));
        $this->assertSame(__('New password').' must be at least 6 characters.', $this->newPasswordError('en', 'abc', 'abc'));
        $this->assertSame(__('New password').' does not match its confirmation.', $this->newPasswordError('en', 'long enough 1', 'other words 1'));

        // no line a member meets reads "The <Field name> ..." any more
        $lines = require lang_path('en/validation.php');
        foreach (['accepted', 'confirmed', 'email', 'required', 'string', 'unique'] as $rule) {
            $this->assertStringNotContainsString('The :attribute', $lines[$rule], $rule);
        }
        foreach (['min', 'max'] as $rule) {
            $this->assertStringNotContainsString('The :attribute', $lines[$rule]['string'], $rule);
        }
    }

    public function test_the_sign_up_form_asks_for_an_address_and_names_the_field_first(): void
    {
        $this->post(route('registration.perform'), ['username' => 'new_member', 'email' => 'not-an-address', 'password' => 'abc', 'password_confirmation' => 'abc', 'cgu' => true])
            ->assertSessionHasErrors([
                'email' => 'Enter a valid e-mail address, like name@example.com.',
                'password' => 'Password must be at least 6 characters.',
            ]);
    }

    public function test_russian_sentences_agree_with_any_field_name(): void
    {
        $this->assertSame('Такое значение поля «'.trans('Username', [], 'ru').'» уже занято.', $this->takenUsernameError('ru'));
        $this->assertSame('Количество символов в поле «'.trans('New password', [], 'ru').'» должно быть не меньше 6.', $this->newPasswordError('ru', 'abc', 'abc'));
        $this->assertSame('Поле «'.trans('New password', [], 'ru').'» не совпадает с подтверждением.', $this->newPasswordError('ru', 'long enough 1', 'other words 1'));

        // every line of the forms names the field as "поле «...»" (neuter), never as the subject of a verb that must agree
        $lines = require lang_path('ru/validation.php');
        foreach ([$lines['required'], $lines['string'], $lines['unique'], $lines['confirmed'], $lines['min']['string'], $lines['max']['string'], $lines['password']['letters'], $lines['password']['mixed'], $lines['password']['numbers'], $lines['password']['symbols']] as $line) {
            $this->assertStringContainsString('«:attribute»', $line, $line);
        }
    }

    public function test_arabic_sentences_agree_with_any_field_name(): void
    {
        $this->assertSame('قيمة حقل '.trans('Username', [], 'ar').' مستخدمة بالفعل.', $this->takenUsernameError('ar'));
        $this->assertSame('يجب ألا يقل عدد أحرف حقل '.trans('New password', [], 'ar').' عن 6.', $this->newPasswordError('ar', 'abc', 'abc'));
        $this->assertSame('حقل '.trans('New password', [], 'ar').' لا يطابق التأكيد.', $this->newPasswordError('ar', 'long enough 1', 'other words 1'));

        $lines = require lang_path('ar/validation.php');
        // a number of letters, never "8 أحرف" / "11 حرفًا" after the number (wrong for most numbers)
        $this->assertStringNotContainsString(':min أحرف', $lines['min']['string']);
        $this->assertStringNotContainsString(':max حرفًا', $lines['max']['string']);
    }
}
