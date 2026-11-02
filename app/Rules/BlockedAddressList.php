<?php

namespace App\Rules;

use App\Support\AdminIpLock;
use App\Support\BlockedAddresses;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The list of Members > Blocked addresses, card "Blocked ranges" (security.global_ip_blacklist), as it is saved
 * (App\Http\Requests\Admin\SettingsSaveRequest): every entry is an address or a CIDR range (203.0.113.7, 203.0.113.0/24,
 * 2001:db8::/32), because anything else (203.0.113.*, 203.0.113.1-203.0.113.9) would block nobody; and the list may not
 * cover the address of the administrator who saves it (AdminIpLock::clientIp(), the address the admin lock adds).
 * App\Support\BlockedAddresses checks the list on every page.
 */
class BlockedAddressList implements ValidationRule
{
    /** @param  string|null  $adminIp  the saving administrator's address (null: the current request's) */
    public function __construct(private readonly ?string $adminIp = null)
    {
    }

    /**
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $invalid = BlockedAddresses::invalidEntries($value);

        if ($invalid !== []) {
            // The admin lock's message: both lists take the same entries.
            $fail(__('Use one address per line, like 203.0.113.7 or 2001:db8::7, or a range like 203.0.113.0/24. Not an address: :entries', [
                'entries' => implode(', ', array_map(fn (string $entry): string => mb_substr($entry, 0, 60), array_slice($invalid, 0, 5))),
            ]));

            return;
        }

        $ip = $this->adminIp ?? AdminIpLock::clientIp(request());

        if ($ip !== '' && BlockedAddresses::listBlocks($value, $ip)) {
            $fail(__('This list would block your own address (:ip). Take it out of the list.', ['ip' => $ip]));
        }
    }
}
