<?php

namespace App\Http\Requests\Concerns;

use App\Services\CaptchaService;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesCaptcha
{
    protected function validateCaptcha(Validator $validator, string $action): void
    {
        $validator->after(function (Validator $validator) use ($action): void {
            if (config('site.honeypot_enabled', true) && trim((string) $this->input('website', '')) !== '') {
                $validator->errors()->add('captcha', __('Please complete the security check.'));
                return;
            }

            $message = app(CaptchaService::class)->validate($this, $action);

            if ($message !== null) {
                $validator->errors()->add('captcha', $message);
            }
        });
    }
}
