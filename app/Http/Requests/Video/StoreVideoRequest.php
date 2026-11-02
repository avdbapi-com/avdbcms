<?php

namespace App\Http\Requests\Video;

use App\Data\UploadSettings;
use App\Enums\ImageType;
use App\Enums\VideoStatus;
use App\Models\Video;
use App\Rules\MimetypeEnum;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreVideoRequest extends FormRequest
{
    use CombinesScheduledFields;

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
     * The draft page sends the scheduled date and time as two native fields (member design 6.4, studio-8): they
     * become scheduled_at, in the site's time zone, before the rules run.
     */
    protected function prepareForValidation(): void
    {
        $this->combineScheduledFields();

        // A browser posts a textarea's line breaks as CRLF; the page, its counter and maxlength count one character:
        // stored as one LF each (a no-op save no longer changes the text, the max length means what the counter says).
        if (is_string($this->input('description'))) {
            $this->merge(['description' => \App\Support\Studio\StudioFormat::lineBreaks($this->input('description'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules() : array
    {
        /** @var Video $video */
        $video = $this->route('video');

        return [
            'title' => [
                'required',
                'string',
                'max:'.config('validation.video.title.max')
            ],
            'description' => [
                'nullable',
                'string',
                'max:'.config('validation.video.description.max')
            ],
            'thumbnail_file' => [
                Rule::prohibitedIf(!config('site.allow_custom_thumbnails', true)),
                'sometimes',
                'file',
                new MimetypeEnum(ImageType::class),
                'max:'.UploadSettings::fromConfig()->maxThumbnailSizeKb,
            ],
            'thumbnail' => [
                'nullable',
                'numeric',
                'min:0',
                Rule::when($this->filled('thumbnail') && (int) $this->get('thumbnail') > 0, Rule::exists('thumbnails', 'id')->where(function (Builder $query) use ($video) {
                    return $query->where('video_id', $video->id);
                }))
            ],
            'status' => [
                'required',
                Rule::in(VideoStatus::validStatus()),
            ],
            'scheduled_at' => [
                Rule::excludeIf($this->status != VideoStatus::PLANNED->value),
                'required',
                'date',
                'after:now'
            ],
            'category_id' => 'nullable|exists:categories,id',
            'language' => [
                'nullable',
                Rule::in(array_keys(Video::languages())),
            ],
            'allow_comments' => 'required|boolean',
            'default_comments_sort' => [
                'required',
                Rule::in(['top', 'newest']),
            ],
            'show_likes' => 'required|boolean',
            'show_ad' => 'required|boolean',
            'playlists' => 'nullable|array',
            'playlists.*' => [
                'numeric',
                Rule::exists('playlists', 'id')->where(function (Builder $query){
                    return $query
                        ->where('user_id', Auth::user()->id)
                        ->where('is_deletable', true);
                })
            ],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->scheduledMessages() + [
            'playlists.*.exists' => __('One of the chosen playlists is not one of yours any more. Pick your playlists again.'),
            'playlists.*.numeric' => __('One of the chosen playlists is not one of yours any more. Pick your playlists again.'),
        ];
    }

    /**
     * The fields' names in the messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->scheduledAttributes();
    }
}
