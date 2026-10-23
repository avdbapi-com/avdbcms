<?php

namespace App\Http\Requests\Video;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class CompleteUploadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Upload18 may return its document ID as a number or nested object.
     * Normalize that API value before applying the strict string rules.
     */
    protected function prepareForValidation(): void
    {
        $did = $this->extractUpload18Id($this->input('did'));

        if ($did !== null) {
            $this->merge(['did' => $did]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'did' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_-]+$/'],
            'vid' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9_-]+$/'],
            'filename' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail) {
                    $value = (string) $value;

                    if (preg_match('/[\/\\\\\x00-\x1F\x7F]/', $value)) {
                        $fail(__('The filename contains invalid characters.'));
                    }

                    $title = pathinfo($value, PATHINFO_FILENAME);
                    $titleMax = (int) config('validation.video.title.max', 100);

                    if ($title === '' || mb_strlen($title, 'UTF-8') > $titleMax) {
                        $fail(__('Your file name does not exceed :max characters', ['max' => $titleMax]));
                    }

                    $extension = strtolower(pathinfo($value, PATHINFO_EXTENSION));
                    $allowed = collect(explode(',', (string) config('site.upload_allowed_formats', 'mp4,webm,avi,mov,mkv')))
                        ->map(fn (string $format): string => strtolower(trim($format)))
                        ->filter()
                        ->all();

                    if ($allowed && !in_array($extension, $allowed, true)) {
                        $fail(__('The video format is not allowed.'));
                    }
                },
            ],
            'size' => ['required', 'integer', 'min:1', 'max:' . $this->maxUploadSize()],
            'duration' => 'nullable|string|max:32',
            'duration_seconds' => 'nullable|integer|min:1|max:' . max(1, (int) (config('site.upload_max_duration') ?? 3600)),
            'thumbnails' => 'nullable|array|max:5',
            'thumbnails.*' => 'required|string',
        ];
    }

    private function maxUploadSize(): int
    {
        $plan = Auth::user()?->plan ?: 'free';
        return (int) config("plans.{$plan}.max_file_size", config('plans.free.max_file_size'));
    }

    private function extractUpload18Id(mixed $value, int $depth = 0): ?string
    {
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }

        if ($depth >= 5 || !is_array($value)) {
            return null;
        }

        foreach (['did', 'id', 'data', 'result'] as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }

            $did = $this->extractUpload18Id($value[$key], $depth + 1);

            if ($did !== null) {
                return $did;
            }
        }

        if (array_is_list($value)) {
            foreach ($value as $item) {
                $did = $this->extractUpload18Id($item, $depth + 1);

                if ($did !== null) {
                    return $did;
                }
            }
        }

        return null;
    }
}
