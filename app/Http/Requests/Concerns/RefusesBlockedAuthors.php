<?php

namespace App\Http\Requests\Concerns;

use App\Services\SpamService;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Settings > Spam promises: comments and messages from one of the blocked e-mail addresses are treated as spam. A
 * form request that uses this refuses the signed-in author whose address contains a line of the list (any case),
 * with "Spam detected" on $field. Security plan S30.
 */
trait RefusesBlockedAuthors
{
    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $email = (string) $this->user()?->email;

            if ($email === '') {
                return;
            }

            foreach ((new SpamService())->getEmails() as $blocked) {
                if (Str::contains($email, $blocked, true)) {
                    $validator->errors()->add($this->blockedAuthorField(), __('Spam detected'));

                    return;
                }
            }
        }];
    }

    protected function blockedAuthorField(): string
    {
        return 'content';
    }
}
