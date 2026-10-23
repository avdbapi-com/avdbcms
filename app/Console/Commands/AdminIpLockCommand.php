<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\AuditLogController;
use App\Models\Setting;
use App\Support\AdminCache;
use App\Support\AdminIpLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * The way back in after an admin IP lock-out (Settings > Security > Admin access lock, a Developer mode card: "Only
 * these internet addresses can open the admin"), for the owner on the server's command line. `status` says whether the lock is on, how many addresses are listed
 * (never which) and the address of the computer this SSH session comes from; `off` empties the list and clears the
 * settings cache, so the admin opens from anywhere again. The blocked admin page points here.
 */
class AdminIpLockCommand extends Command
{
    protected $signature = 'avd:admin-ip-lock
        {action=status : status (is the admin locked, and for which address) or off (empty the list)}';

    protected $description = 'Show the admin IP lock, or switch it off after a lock-out';

    private const KEY = 'admin_ip_whitelist';

    public function handle(): int
    {
        if (! Schema::hasTable('settings')) {
            $this->error('The settings table does not exist yet: the site is not installed.');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'status' => $this->status(),
            'off' => $this->off(),
            default => $this->unknownAction(),
        };
    }

    private function status(): int
    {
        $list = $this->storedList();
        $entries = AdminIpLock::entries($list);
        $invalid = count(AdminIpLock::invalidEntries($list));

        if ($entries === []) {
            $this->info('The admin IP lock is off: the list is empty, so the admin opens from any address.');
        } else {
            $this->warn(sprintf('The admin IP lock is on: %d %s on the list.', count($entries), count($entries) === 1 ? 'address or range is' : 'addresses or ranges are'));

            if ($invalid > 0) {
                $this->line($invalid === 1 ? '1 of them is not an address and never matches.' : "{$invalid} of them are not addresses and never match.");
            }
        }

        $ssh = $this->sshClientAddress();

        if ($ssh === null) {
            $this->line('This shell is not an SSH connection, so the address of your computer is not known here. When the admin is blocked, the page shows the address the site sees for your browser.');
        } else {
            $this->line("The address of the computer you connect from (this SSH session): {$ssh}");

            if ($entries !== []) {
                $this->line(AdminIpLock::matches($ssh, $entries)
                    ? 'It is on the list: the admin opens from it (when your browser uses the same internet connection).'
                    : 'It is not on the list: the admin is locked for it. Run "php artisan avd:admin-ip-lock off" to open the admin again.');
            }
        }

        // The site reads its settings through the cache: an edit made in the database alone is not used yet.
        if (trim((string) (Setting::getAllGrouped()['security'][self::KEY] ?? '')) !== trim($list)) {
            $this->line('The site still uses an older copy of the list from its cache: run "php artisan cache:clear" (or "php artisan avd:admin-ip-lock off") to use what is saved.');
        }

        return self::SUCCESS;
    }

    private function off(): int
    {
        $count = count(AdminIpLock::entries($this->storedList()));

        Setting::set(self::KEY, '', 'security');
        Setting::clearCache([self::KEY], ['security']);
        AdminCache::bumpVersion();

        if ($count === 0) {
            $this->info('The admin IP lock was already off. The settings cache is cleared.');

            return self::SUCCESS;
        }

        AuditLogController::log('security.admin_ip_lock.off', sprintf('Turned off from the command line: %d %s removed.', $count, $count === 1 ? 'entry' : 'entries'));
        $this->info(sprintf('The admin IP lock is off: the list of %d %s is empty, and the settings cache is cleared.', $count, $count === 1 ? 'entry' : 'entries'));
        $this->line('The admin opens from any address now. To lock it again, turn on Developer mode in the account menu, then add your address in Settings > Security > Admin access lock.');

        return self::SUCCESS;
    }

    private function unknownAction(): int
    {
        $this->error('Unknown action. Use: php artisan avd:admin-ip-lock status   or   php artisan avd:admin-ip-lock off');

        return self::INVALID;
    }

    /** The saved list itself (not the cached copy the site may still use). */
    private function storedList(): string
    {
        return (string) Setting::query()->where('key', self::KEY)->value('value');
    }

    /** The client address of this SSH session (SSH_CONNECTION / SSH_CLIENT: "address port ..."), or null. */
    private function sshClientAddress(): ?string
    {
        foreach (['SSH_CONNECTION', 'SSH_CLIENT'] as $name) {
            $value = getenv($name);
            $address = is_string($value) ? AdminIpLock::normalize((string) strtok($value, ' ')) : '';

            if ($address !== '') {
                return $address;
            }
        }

        return null;
    }
}
