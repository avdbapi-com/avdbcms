<?php

namespace Tests\Unit;

use App\Http\Requests\Video\CompleteUploadRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CompleteUploadRequestTest extends TestCase
{
    public function test_complete_upload_rejects_untrusted_identifiers_filename_and_size(): void
    {
        $request = new CompleteUploadRequest();

        $validator = Validator::make([
            'did' => '../bad',
            'vid' => 'bad/hash',
            'filename' => '../video.mp4',
            'size' => config('plans.free.max_file_size') + 1,
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('did', $validator->errors()->messages());
        $this->assertArrayHasKey('vid', $validator->errors()->messages());
        $this->assertArrayHasKey('filename', $validator->errors()->messages());
        $this->assertArrayHasKey('size', $validator->errors()->messages());
    }

    public function test_complete_upload_accepts_bounded_upload18_payload(): void
    {
        $request = new CompleteUploadRequest();

        $validator = Validator::make([
            'did' => '123456789',
            'vid' => 'abcDEF_123-456',
            'filename' => 'example-video.mp4',
            'size' => 1024,
            'duration_seconds' => 60,
        ], $request->rules());

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }
}
