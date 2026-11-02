<?php

namespace App\Casts;

use Illuminate\Database\Eloquent\Model;
use Propaganistas\LaravelPhone\Casts\E164PhoneNumberCast;
use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

/**
 * users.phone: the member's number as the phone library's PhoneNumber, kept in the column in E.164 ("+33612345678").
 *
 * 1.2.3 (round 1b, R1R-08): the library's own cast throws "Queried value for phone is not in international format" when
 * a stored number cannot be placed in a country (an imported database, a number saved before newer phone metadata, a
 * national number without its country code), so every page and job that read $user->phone answered 500 for that member
 * (Account settings, the phone code routes, "Download my data", the queued code). A stored number the library cannot
 * read counts as no number here: it reads as null for showing and sending, and the member can enter a new one. The
 * stored value itself is left as it is (a null is not kept as the read value, so saving anything else never rewrites
 * the column); entering a new number or "Remove" replaces it. Writing is the library's, unchanged: the account form
 * only ever writes a number its phone rule accepted.
 */
class StoredPhoneNumberCast extends E164PhoneNumberCast
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?PhoneNumber
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return parent::get($model, $key, $value, $attributes);
        } catch (Throwable) {
            return null;
        }
    }
}
