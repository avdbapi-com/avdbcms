<?php

namespace App\Http\Requests\Interaction;

use App\Models\Comment;
use App\Models\Video;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $table = $this->tableFor($this->input('model'));

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
                Rule::exists($table, 'id'),
            ]
        ];
    }

    private function tableFor(?string $model): string
    {
        return in_array($model, self::LIKEABLE_MODELS, true)
            ? (new $model())->getTable()
            : (new Video())->getTable();
    }
}
