<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class HasLinks implements ValidationRule
{
    /**
     * Run the validation rule. Any case: HTTPS://... is a link too. A value that is not text is left to the field's
     * "string" rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match('/https?:\/\/[^\s]+/i', $value)) {
            $fail(__('Links are not authorized in message'));
        }
    }
}
