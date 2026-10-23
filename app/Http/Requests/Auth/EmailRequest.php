<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ValidatesCaptcha;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class EmailRequest extends FormRequest
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
            'email' => ['required', 'email'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateCaptcha($validator, 'password_reset');
    }
}
