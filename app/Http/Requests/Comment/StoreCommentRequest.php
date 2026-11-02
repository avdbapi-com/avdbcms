<?php

namespace App\Http\Requests\Comment;

use App\Http\Requests\Concerns\RefusesBlockedAuthors;
use App\Models\Video;
use App\Rules\SpamWords;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommentRequest extends FormRequest
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
        $video = $this->route('video');
        $videoId = $video instanceof Video ? $video->id : null;

        return [
            'parent_id' => [
                Rule::prohibitedIf(!config('site.allow_replies', true)),
                'nullable',
                Rule::exists('comments', 'id')->where(function ($query) use ($videoId) {
                    $query->whereNull('parent_id');

                    if ($videoId) {
                        return $query->where('video_id', $videoId);
                    }

                    return $query->whereRaw('1 = 0');
                }),
            ],
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
