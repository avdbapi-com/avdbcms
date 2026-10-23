<?php

namespace App\Http\Requests\Report;

use App\Enums\ReportReason;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportRequest extends FormRequest
{
    private const REPORTABLE_MODELS = [
        Video::class,
        Comment::class,
        User::class,
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
        $table = $this->tableFor($this->input('type'));

        return [
            'reason' => [
                'required',
                Rule::enum(ReportReason::class)
            ],
            'comment' => [
                'nullable',
                'string',
                'max:'.config('validation.report.comment.max')
            ],
            'type' => [
                'bail',
                'required',
                Rule::in(self::REPORTABLE_MODELS)
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
        return in_array($model, self::REPORTABLE_MODELS, true)
            ? (new $model())->getTable()
            : (new Video())->getTable();
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Please provide a reason for your review',
        ];
    }
}
