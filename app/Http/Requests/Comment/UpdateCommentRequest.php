<?php

namespace App\Http\Requests\Comment;

use App\Http\Requests\Concerns\RefusesBlockedAuthors;
use App\Rules\SpamWords;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCommentRequest extends FormRequest
{
    use RefusesBlockedAuthors;

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
            // Settings > Spam: the blocked words (and, in after(), the blocked e-mail addresses) apply to comments.
            'content' => [
                'required',
                'string',
                'max:'.config('validation.comment.content.max'),
                new SpamWords,
            ]
        ];
    }
}
