<?php

namespace App\Http\Requests\Concerns;

use App\Services\CaptchaService;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesCaptcha
{
    protected function validateCaptcha(Validator $validator, string $action): void
    {
        $validator->after(function (Validator $validator) use ($action): void {
            // The hidden "website" field (the honeypot) is filled: a robot, or a password manager or autofill that
            // filled every field it found. The form is refused as before, on the robot check's key (scripts and apps
            // read it there), with a sentence a person can act on: the pages print it in their alert while the robot
            // check is off for the form (it was "Please complete the security check." under a check the page did not
            // show, so nothing was shown at all).
            if (config('site.honeypot_enabled', true) && trim((string) $this->input('website', '')) !== '') {
                $validator->errors()->add('captcha', __('The form was not sent: a hidden anti-spam field was filled in, which autofill sometimes does. Fill in the form by hand and send it again.'));
                return;
            }

            $message = app(CaptchaService::class)->validate($this, $action);

            if ($message !== null) {
                $validator->errors()->add('captcha', $message);
            }
        });
    }
}
