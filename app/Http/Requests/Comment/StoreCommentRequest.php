<?php

namespace App\Http\Requests\Comment;

use App\Models\Video;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommentRequest extends FormRequest
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
            'content' => [
                'required',
                'string',
                'max:'.config('validation.comment.content.max')
            ]
        ];
    }
}
