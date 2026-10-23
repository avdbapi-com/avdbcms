<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ValidatesCaptcha;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class RegisterRequest extends FormRequest
{
    use ValidatesCaptcha;

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
                'confirmed',
                $password,
            ],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cgu' => ['accepted']
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateCaptcha($validator, 'register');
    }

    protected function passedValidation() :void
    {
        $key = $this->throttleKey();

        if (RateLimiter::tooManyAttempts($key, config('site.max_register_attempts', 1))) {

            throw ValidationException::withMessages([
                'throttle' => __('Too many attempts !')
            ]);
        }

        RateLimiter::increment($key);
    }

    protected function throttleKey(): string
    {
        return 'register' . '|' . $this->ip();
    }
}
