<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ValidatesCaptcha;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
        return [
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            // Mobile clients may omit a device label; the API controller uses
            // a safe token-name fallback. Reject arrays and objects early so
            // malformed JSON cannot reach the user lookup or token creation.
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateCaptcha($validator, 'login');
    }
}
