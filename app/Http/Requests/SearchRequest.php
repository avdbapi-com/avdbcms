<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SearchRequest extends FormRequest
{
    /** The longest query the site searches for: a longer one (a long link, the address bar) is cut to it. */
    public const MAX_QUERY_LENGTH = 255;

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
     * An over-long query is searched for in its first 255 characters instead of being refused (watch-26): a refused
     * query sent the visitor "back", to whatever the session took for the previous page (it could be a picture).
     */
    protected function prepareForValidation(): void
    {
        $query = $this->input('q');

        if (is_string($query) && mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            $this->merge(['q' => Str::substr($query, 0, self::MAX_QUERY_LENGTH)]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules() : array
    {
        return [
            'q' => [
                Rule::requiredIf(request()->is('api/*')),
                'string',
                'max:'.self::MAX_QUERY_LENGTH,
            ],
       ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'q' => 'query',
        ];
    }
}
