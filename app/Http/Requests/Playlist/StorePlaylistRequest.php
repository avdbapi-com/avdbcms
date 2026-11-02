<?php

namespace App\Http\Requests\Playlist;

use App\Enums\CustomPlaylistType;
use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Models\Video;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Creating a playlist (member design 6.7). The studio's editor sends its list as one field, videos_order
 * ("12,5,9": PHP's max_input_vars cut a form with one field per video); it becomes videos[] before the rules run, so
 * StorePlaylistAction and other clients (videos[]) are served alike. The ids are checked together in a few queries.
 */
class StorePlaylistRequest extends FormRequest
{
    /** @var array<int, true>|null the ids the member may add (memo of allowedIds()) */
    private ?array $allowed = null;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize() : bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('videos_order')) {
            $order = UpdatePlaylistRequest::ids($this->input('videos_order'));
            $this->request->remove('videos_order');
            $this->merge(['videos' => $order]);
        }

        // A browser posts a textarea's line breaks as CRLF; the page counts one character each (StudioFormat::lineBreaks).
        if (is_string($this->input('description'))) {
            $this->merge(['description' => \App\Support\Studio\StudioFormat::lineBreaks($this->input('description'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules() : array
    {
        return [
            'title' => [
                'required',
                'string',
                'max:'.config('validation.playlist.title.max'),
                 Rule::notIn(CustomPlaylistType::get())
            ],
            'description' => [
                'nullable',
                'string',
                'max:'.config('validation.playlist.description.max')
            ],
            'status' => [
                'required',
                new Enum(PlaylistStatus::class)
            ],
            'sort' => [
                'sometimes',
                'required',
                new Enum(PlaylistSort::class)
            ],
            'videos' => 'nullable|array|max:'.UpdatePlaylistRequest::MAX_IDS,
            'videos.*' => [
                'numeric',
                $this->videoTheMemberMayAdd(...),
            ]
        ];
    }

    /**
     * A video the member may put into a new playlist: one anybody can watch or one of the member's own. Another
     * member's private video fails like an id that does not exist. (Checked with Eloquent: Video::scopeActive() needs
     * the user relation, which the plain query of Rule::exists()->where() cannot run.)
     */
    public function videoTheMemberMayAdd(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! isset($this->allowedIds()[(int) $value])) {
            $fail($this->messages()['videos.*.exists']);
        }
    }

    /**
     * The posted ids anybody can watch or the member's own, as keys: one query per thousand ids.
     *
     * @return array<int, true>
     */
    public function allowedIds(): array
    {
        if ($this->allowed !== null) {
            return $this->allowed;
        }

        $posted = is_array($this->input('videos')) ? $this->input('videos') : [];
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($posted, fn ($id) => is_numeric($id))), fn (int $id) => $id > 0)));
        $allowed = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            Video::query()
                ->whereIn('videos.id', $chunk)
                ->where(fn ($query) => $query->active()->orWhere('videos.user_id', Auth::id()))
                ->pluck('videos.id')
                ->each(function ($id) use (&$allowed): void {
                    $allowed[(int) $id] = true;
                });
        }

        return $this->allowed = $allowed;
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        // studio-27: every message in the member's language (they were English sentences built in code)
        return [
            'videos.required' => __('Add at least one video.'),
            'videos.*.exists' => __('One of the videos is not available any more. Remove it and save again.'),
            'videos.*.numeric' => __('One of the videos is not available any more. Remove it and save again.'),
            'title.not_in' => __('This name belongs to a list every member has (:names). Pick another name.', ['names' => CustomPlaylistType::nameToString()]),
        ];
    }
}
