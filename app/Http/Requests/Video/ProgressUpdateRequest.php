<?php

namespace App\Http\Requests\Video;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class ProgressUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxDuration = max(1, (int) config('site.upload_max_duration', 3600));

        return [
            'position_seconds' => ['required', 'integer', 'min:1', 'max:' . $maxDuration],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:' . $maxDuration],
            'played_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $durationSeconds = $this->durationSeconds();

            if ($durationSeconds !== null && $this->positionSeconds() > $durationSeconds) {
                $validator->errors()->add('position_seconds', __('The playback position cannot exceed the video duration.'));
            }
        });
    }

    public function positionSeconds(): int
    {
        return (int) $this->input('position_seconds');
    }

    public function durationSeconds(): ?int
    {
        $duration = $this->input('duration_seconds');

        return $duration === null ? null : (int) $duration;
    }

    public function playedAt(): ?Carbon
    {
        $playedAt = $this->input('played_at');

        return $playedAt ? Carbon::parse((string) $playedAt) : null;
    }
}
