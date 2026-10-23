<?php

namespace App\Http\Requests\Video;

use App\Data\UploadSettings;
use App\Enums\VideoType;
use App\Support\NumberFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Closure;

class FileRequest extends FormRequest
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
        return [
            'resumableFilename' => [
                'required',
                function (string $attribute, string $value, Closure $fail) {
                    if (mb_strlen(pathinfo($value, PATHINFO_FILENAME), 'UTF-8') > 100) {
                        $fail(__("Your file name does not exceed 100 characters"));
                    }
                },
            ],
            'resumableTotalSize' => 'required|integer|min:1|max:'.$this->maxUploadSizeBytes(),
            'file' => [
                'required',
                'file',
                // file mimetype only available on first chunk other chunk mimetype are application/octet-stream
                Rule::when($this->get('resumableChunkNumber') == 1, ['mimetypes:'.$this->allowedVideoMimeTypes()]),
                'max:'.(UploadSettings::fromConfig()->chunkSizeMb * 1024) // Admin sets MB, Laravel max uses KB
            ],
            'resumableIdentifier' => ['required', 'string', 'max:500', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'resumableChunkNumber' => [
                'required',
                'integer',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $totalChunks = (int) $this->input('resumableTotalChunks', 0);
                    if ((int) $value > $totalChunks) {
                        $fail(__('The chunk number cannot exceed the total chunk count.'));
                    }
                },
            ],
            'resumableTotalChunks' => 'required|integer|min:1|max:10000',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $fileSize = NumberFormat::fileSize($this->get('resumableTotalSize', 0));
        $maxSize = NumberFormat::fileSize($this->maxUploadSizeBytes());

        return [
            'resumableTotalSize.max' => __('Your file is too large (:fileSize) The uploading file should not exceed :maxSize', ['fileSize' => $fileSize, 'maxSize' => $maxSize]),
            'resumableFilename.max' => __('Your file name does not exceed 100 characters'),
        ];
    }

    private function maxUploadSizeBytes(): int
    {
        return (int) config('plans.'.Auth::user()->plan.'.max_file_size');
    }

    private function allowedVideoMimeTypes(): string
    {
        $extensionMap = [
            'mp4' => VideoType::MP4->value,
            'ogg' => VideoType::OGG->value,
            'webm' => VideoType::WEBM->value,
            'mov' => VideoType::MOV->value,
            'avi' => VideoType::AVI->value,
            'wmv' => VideoType::WMV->value,
            'swf' => VideoType::SWF->value,
            'mpeg' => VideoType::MPEG->value,
            'mpg' => VideoType::MPEG->value,
            'flv' => VideoType::FLV->value,
            'mkv' => VideoType::MKV->value,
        ];

        $formats = collect(explode(',', (string) config('site.upload_allowed_formats', 'mp4,webm,avi,mov,mkv')))
            ->map(fn (string $format): string => strtolower(trim($format)))
            ->filter()
            ->map(fn (string $format): ?string => $extensionMap[$format] ?? null)
            ->filter()
            ->unique()
            ->values();

        return $formats->isNotEmpty()
            ? $formats->implode(',')
            : VideoType::toString();
    }
}
