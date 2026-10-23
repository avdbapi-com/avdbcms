<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
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
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', $password],
        ];
    }
}
