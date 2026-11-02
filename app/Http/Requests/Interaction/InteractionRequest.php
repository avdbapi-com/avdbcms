<?php

namespace App\Http\Requests\Interaction;

use App\Models\Comment;
use App\Models\Video;
use App\Support\ShownToMember;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class InteractionRequest extends FormRequest
{
    private const LIKEABLE_MODELS = [
        Video::class,
        Comment::class,
    ];

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
            'model' => [
                'bail',
                'required',
                Rule::in(self::LIKEABLE_MODELS)
            ],
            'id' => [
                'bail',
                'required',
                'integer',
                'min:1',
                $this->shownToMember($this->input('model')),
            ]
        ];
    }

    /**
     * Only what the site shows this member can be liked or disliked; any other id is refused exactly like one that does
     * not exist (round 2a R2A-062, the siblings of R1-090's route): the same answer and the same work, one query. Before,
     * another member's private video, draft or video planned for later answered 403 and a deleted one 404 against 422
     * for a missing id, which told which of them exist; a comment under such a video, under a video with comments
     * turned off or under a comment a moderator hid was even liked (204).
     *
     * A model sent as a list (model[]=...) is no model: refused as an invalid model, never a 500 (it reached ?string).
     */
    private function shownToMember(mixed $model): Exists
    {
        $user = $this->user();

        return $model === Comment::class
            ? Rule::exists('comments', 'id')->where(fn (Builder $query) => ShownToMember::comments($query, $user))
            : Rule::exists('videos', 'id')->whereNull('deleted_at')->where(fn (Builder $query) => ShownToMember::videos($query, $user));
    }
}
