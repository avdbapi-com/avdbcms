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
            // The name is printed in the mail's heading: the same spam words and no links, like the message.
            'name' => [
                'required',
                'string',
                'min:'.config('validation.contact.name.min'),
                'max:'.config('validation.contact.name.max'),
                new SpamWords,
                new HasLinks
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

    /**
     * The form's own sentences, one name per field ("your name", "your e-mail", "your message", as the labels say):
     * the general ones mixed "The name field is required." with "The Email field is required.".
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $nameMin = (int) config('validation.contact.name.min');
        $nameMax = (int) config('validation.contact.name.max');
        $messageMin = (int) config('validation.contact.message.min');
        $messageMax = (int) config('validation.contact.message.max');

        return [
            'name.required' => __('Enter your name.'),
            'name.min' => trans_choice('{1} Your name needs at least :count character.|[2,*] Your name needs at least :count characters.', $nameMin, ['count' => $nameMin]),
            'name.max' => trans_choice('{1} Your name can have at most :count character.|[2,*] Your name can have at most :count characters.', $nameMax, ['count' => $nameMax]),
            'email.required' => __('Enter your e-mail.'),
            'email.email' => __('Enter a valid e-mail, like name@example.com.'),
            'message.required' => __('Write your message.'),
            'message.min' => trans_choice('{1} Your message needs at least :count character.|[2,*] Your message needs at least :count characters.', $messageMin, ['count' => $messageMin]),
            'message.max' => trans_choice('{1} Your message can have at most :count character.|[2,*] Your message can have at most :count characters.', $messageMax, ['count' => $messageMax]),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateCaptcha($validator, 'contact');
    }
}
