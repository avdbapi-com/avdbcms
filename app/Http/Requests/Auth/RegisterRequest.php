<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ValidatesCaptcha;
use App\Models\Setting;
use App\Providers\RouteServiceProvider;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegisterRequest extends FormRequest
{
    use ValidatesCaptcha;

    /**
     * A user name: letters (any script, with their accents), numbers, dots, dashes and underscores. No spaces, slashes or
     * emoji: they break the channel address and mentions built from the name (auth-23).
     */
    public const USERNAME_PATTERN = '/^[\pL\pM\pN._-]+$/u';

    /** Sign-ups one address may make in SIGNUP_WINDOW seconds while no "Sign-ups allowed per visitor" is stored. */
    public const SIGNUP_DEFAULT = 3;

    /** Ten minutes, the window of that default (auth-16: one a minute stopped a second person at home). */
    public const SIGNUP_WINDOW = 600;

    /**
     * The window of a number the owner stored: one minute, what "Sign-ups allowed per visitor" has always meant. An
     * owner's setting keeps its meaning; only the unset default changed in 1.2.3. The 1 the installer stored up to 1.2.2
     * is not an owner's choice: the migration 2026_10_27_100000_unset_bundled_sign_up_limit removes that exact,
     * untouched row, so updated sites get the default too.
     */
    public const STORED_WINDOW = 60;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize() : bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules() : array
    {
        $password = Password::min(config('validation.user.password.min'));

        if (config('site.require_strong_password', false)) {
            $password = $password->mixedCase()->numbers()->symbols();
        }

        return [
            'username' => [
                'required',
                'string',
                'min:'.config('validation.user.username.min'),
                'max:'.config('validation.user.username.max'),
                'regex:'.self::USERNAME_PATTERN,
                'unique:users,username'
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email'
            ],
            'password' => [
                'required',
                'string',
                $password,
            ],
            // The mismatch is the confirmation's error, so the form marks the field it is about (auth-24).
            'password_confirmation' => ['required', 'string', 'same:password'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cgu' => ['accepted']
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => __('Use letters, numbers, dots, dashes or underscores: no spaces.'),
            'password_confirmation.same' => __('The passwords are not the same.'),
            'password_confirmation.required' => __('Type the password again.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateCaptcha($validator, 'register');
    }

    /**
     * How many accounts one address may create (signUpLimit()). Above that the form says how many seconds to wait.
     */
    protected function passedValidation() :void
    {
        $key = $this->throttleKey();
        [$allowed, $window] = self::signUpLimit();

        if (RateLimiter::tooManyAttempts($key, $allowed)) {
            throw ValidationException::withMessages([
                'throttle' => RouteServiceProvider::waitMessage(RateLimiter::availableIn($key)),
            ]);
        }

        RateLimiter::hit($key, $window);
    }

    protected function throttleKey(): string
    {
        return 'register' . '|' . $this->ip();
    }

    /**
     * The sign-ups one address may make and the window in seconds: the owner's stored "Sign-ups allowed per visitor"
     * (1-20) per minute, as before 1.2.3; without a stored number, 3 in 10 minutes.
     *
     * @return array{0: int, 1: int}
     */
    public static function signUpLimit(): array
    {
        $stored = self::storedSignUps();

        return $stored === null
            ? [self::SIGNUP_DEFAULT, self::SIGNUP_WINDOW]
            : [$stored, self::STORED_WINDOW];
    }

    /** The owner's stored "Sign-ups allowed per visitor" (1-20), or null while none is stored. */
    private static function storedSignUps(): ?int
    {
        try {
            $stored = Setting::getGroup('users')['max_register_attempts'] ?? null;
        } catch (Throwable) {
            return null;
        }

        if ($stored === null || ! is_numeric(trim((string) $stored))) {
            return null;
        }

        return max(1, min(20, (int) $stored));
    }
}
