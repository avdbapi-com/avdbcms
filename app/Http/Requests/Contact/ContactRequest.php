<?php

namespace App\Http\Requests\Contact;

use App\Http\Requests\Concerns\ValidatesCaptcha;
use App\Rules\HasLinks;
use App\Rules\SpamWords;
use App\Rules\SpamEmails;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ContactRequest extends FormRequest
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
     * @return array
     */
    public function rules() : array
    {
        return [
            'name' => [
                'required',
                'min:'.config('validation.contact.name.min'),
                'max:'.config('validation.contact.name.max'),
            ],
            'email' => [
                'bail',
                'required',
                'email',
                 new SpamEmails
            ],
            'message' => [
                'required',
                'string',
                'min:'.config('validation.contact.message.min'),
                'max:'.config('validation.contact.message.max'),
                new SpamWords,
                new HasLinks
            ],
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateCaptcha($validator, 'contact');
    }
}
