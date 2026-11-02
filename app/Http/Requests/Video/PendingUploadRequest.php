<?php

namespace App\Http\Requests\Video;

/**
 * POST /api/videos/upload18/pending: the member's browser says Upload18 has the whole file (its answer carried did and
 * vid). The same file rules as the finish (CompleteUploadRequest); the vid may still be empty in older Upload18 answers;
 * file_duration is the length the browser read from the file; duplicate is Upload18's own "duplicate" answer field.
 */
class PendingUploadRequest extends CompleteUploadRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        return [
            'did' => $rules['did'],
            'vid' => ['nullable', 'string', 'max:160', 'regex:/^[A-Za-z0-9_-]+$/'],
            'filename' => $rules['filename'],
            'size' => $rules['size'],
            'file_duration' => $rules['file_duration'],
            'duplicate' => ['nullable', 'boolean'],
        ];
    }
}
