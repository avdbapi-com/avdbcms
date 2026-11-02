<?php

namespace App\Rules;

use App\Support\ContentSafety;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 1.2.3: the owner's blocked words of the importers (Content > Import > Blocked words, App\Support\ContentSafety): a
 * list is saved only when every word or phrase has enough letters (a word of two letters, of or in, would block nearly
 * every title), it holds at most ContentSafety::MAX_WORDS of them and none is longer than ContentSafety::MAX_WORD_LENGTH
 * characters. The answer says which words are wrong, so nothing is dropped without the owner seeing it.
 */
final class BlockedWordsList implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        foreach (ContentSafety::problems($value) as $problem) {
            $fail($problem);
        }
    }
}
