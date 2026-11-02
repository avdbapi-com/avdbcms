<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class CodeRequest extends FormRequest
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
        return [
            'code' => ['required', 'digits:5']
        ];
    }

    /**
     * The code field's errors in plain words, under the field like the code's other answers (R1-115, R1-121: Laravel's
     * "The code must be 5 digits." was English in most languages).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $message = __('The code has 5 digits. Check the text message and try again.');

        return [
            'code.required' => $message,
            'code.digits' => $message,
        ];
    }
}
