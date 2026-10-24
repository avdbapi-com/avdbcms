<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Member accounts that older packages created with a password other people know:
 *
 * - The two sample members of example.sql (pbergstrom, blackbox_creator). The AVDBClip community packages before
 *   AVDBCMS 1.2.0 shipped them with password hashes from the developers' test site (one of them a very common
 *   password; later both had the password of the package's admin row). 1.2.0 gave new sites unusable passwords,
 *   but a site installed before still has the old ones.
 * - Members of the model factory: until 20 September 2026, "Run migrations and seeders" in the installer also ran
 *   the development seeder UserSeeder, whose members have the factory password "password" and e-mail addresses at
 *   example.com/.net/.org (ModelSeeder makes the same kind). The members of FleetDataEnricherSeeder are not among
 *   them: the User model hashed its already hashed "password123" again, so nobody can sign in with it.
 *
 * Each such member gets a random password that nobody knows, a new "remember me" token, and loses its API tokens and
 * stored sessions. The account, its name and its content stay, and Tools › Logs › Admin actions names it. Admin
 * accounts are never changed, and an account whose password was changed since is not touched. Safe to run again.
 */
return new class extends Migration
{
    /** SHA-256 of the exact password hashes example.sql stored for its sample members before 1.2.0 (not the hashes). */
    public const BUNDLED_HASHES = [
        '3a5381c93825ebd058b2762f1be9a48f0fe74afbb660fb2c039e12085f47d76c', // pbergstrom, packages of August/September 2026
        'f3e4fae23d8b2347893cca0452e7f7c489093ad198ee684c59d22015c33080f4', // blackbox_creator, packages of August/September 2026
        '1511419e2de78f77483f895f01f510c0d17b919bd42390c8b168faab89271a1b', // both, AVDBClip community packages v2 to v7.1
    ];

    /** Members of the model factory: Faker's reserved e-mail domains and the factory password. */
    public const FACTORY_EMAIL_DOMAINS = ['@example.com', '@example.net', '@example.org'];

    public const FACTORY_PASSWORD = 'password';

    /** The factory members are the oldest accounts; checking a password costs time, so at most this many are checked. */
    public const FACTORY_LIMIT = 100;

    /** The admin lists show the details up to 120 characters (old admin) or 160 (new admin); more names take more rows. */
    public const AUDIT_MAX_LENGTH = 120;

    /** A longer user name is shortened in the Admin actions text. */
    public const NAME_MAX_LENGTH = 40;

    public function up(): void
    {
        if (! $this->usersReady()) {
            return;
        }

        $this->lockAccounts(array_merge(
            $this->findBundledHashes(self::BUNDLED_HASHES),
            $this->findFactoryMembers(),
        ));
    }

    public function down(): void
    {
        // Nothing to put back: the old passwords were known to others.
    }

    /**
     * Locks the members whose stored password hash has one of these SHA-256 fingerprints.
     *
     * @param  list<string>  $fingerprints
     * @return list<int>  the ids locked
     */
    public function lockBundledHashes(array $fingerprints): array
    {
        return $this->usersReady() ? $this->lockAccounts($this->findBundledHashes($fingerprints)) : [];
    }

    /** @return list<int> */
    private function findBundledHashes(array $fingerprints): array
    {
        $found = [];

        DB::table('users')->select(['id', 'password', 'is_admin'])->chunkById(1000, function ($users) use ($fingerprints, &$found): void {
            foreach ($users as $user) {
                if (! $this->isAdmin($user->is_admin) && is_string($user->password) && in_array(hash('sha256', $user->password), $fingerprints, true)) {
                    $found[] = (int) $user->id;
                }
            }
        });

        return $found;
    }

    /**
     * The oldest members with a Faker e-mail address whose password is still the factory's.
     *
     * @return list<int>
     */
    private function findFactoryMembers(): array
    {
        $found = [];
        $members = DB::table('users')
            ->select(['id', 'email', 'password', 'is_admin'])
            ->where(fn (Builder $query) => $query->whereNull('is_admin')->orWhere('is_admin', '!=', 1))
            ->where(function (Builder $query): void {
                foreach (self::FACTORY_EMAIL_DOMAINS as $domain) {
                    $query->orWhere('email', 'like', '%'.$domain);
                }
            })
            ->orderBy('id')
            ->limit(self::FACTORY_LIMIT)
            ->get();

        foreach ($members as $user) {
            if (! $this->isAdmin($user->is_admin)
                && $this->endsWithAny((string) $user->email, self::FACTORY_EMAIL_DOMAINS)
                && is_string($user->password) && $user->password !== ''
                && password_verify(self::FACTORY_PASSWORD, $user->password)) {
                $found[] = (int) $user->id;
            }
        }

        return $found;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function lockAccounts(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        if ($ids === []) {
            return [];
        }

        $hasRememberToken = Schema::hasColumn('users', 'remember_token');

        foreach ($ids as $id) {
            $values = ['password' => Hash::make(Str::random(64))];

            if ($hasRememberToken) {
                $values['remember_token'] = Str::random(60);
            }

            DB::table('users')->where('id', $id)->update($values);
        }

        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->whereIn('tokenable_id', $ids)->delete();
        }

        if (Schema::hasTable('sessions') && Schema::hasColumn('sessions', 'user_id')) {
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
        }

        // Tools › Logs › Admin actions is the owner's record of which members changed: sites log only errors to
        // laravel.log. The members are named by their user name, which the Members search finds.
        try {
            if (Schema::hasTable('admin_audit_logs')) {
                $now = now();

                DB::table('admin_audit_logs')->insert(array_map(fn (string $details): array => [
                    'user_id' => null,
                    'action' => 'security.known_passwords_reset',
                    'details' => $details,
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => $now,
                ], self::auditDetails($this->auditNames($ids))));
            }

            Log::notice('AVDBCMS 1.2.2: member accounts with a password from an old package or a development seeder got a new random password.', ['user_ids' => $ids]);
        } catch (Throwable) {
            // The log entries are a courtesy; the accounts are locked.
        }

        return $ids;
    }

    /**
     * The Admin actions rows for these members, each at most AUDIT_MAX_LENGTH characters, for example "1.2.2: 2 members
     * had a password others know and got a random one: pbergstrom, blackbox_creator". More members take more rows.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function auditDetails(array $names): array
    {
        $rows = [];
        $chunk = [];

        foreach ($names as $name) {
            // Width as Str::limit() measures it in the lists (a CJK character counts twice).
            if ($chunk !== [] && mb_strwidth(self::auditText([...$chunk, $name]), 'UTF-8') > self::AUDIT_MAX_LENGTH) {
                $rows[] = self::auditText($chunk);
                $chunk = [];
            }

            $chunk[] = $name;
        }

        if ($chunk !== []) {
            $rows[] = self::auditText($chunk);
        }

        return $rows;
    }

    /** @param list<string> $names */
    private static function auditText(array $names): string
    {
        return count($names) === 1
            ? '1.2.2: 1 member had a password others know and got a random one: '.$names[0]
            : sprintf('1.2.2: %d members had a password others know and got a random one: %s', count($names), implode(', ', $names));
    }

    /**
     * Each member's user name for the Admin actions text ("#id" without one), shortened to NAME_MAX_LENGTH.
     *
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function auditNames(array $ids): array
    {
        $names = Schema::hasColumn('users', 'username')
            ? DB::table('users')->whereIn('id', $ids)->pluck('username', 'id')->all()
            : [];

        return array_map(function (int $id) use ($names): string {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($names[$id] ?? '')));

            if ($name === '') {
                return '#'.$id;
            }

            return mb_strwidth($name, 'UTF-8') > self::NAME_MAX_LENGTH
                ? rtrim(mb_strimwidth($name, 0, self::NAME_MAX_LENGTH - 3, '', 'UTF-8')).'...'
                : $name;
        }, $ids);
    }

    private function usersReady(): bool
    {
        return Schema::hasTable('users') && Schema::hasColumns('users', ['id', 'email', 'password', 'is_admin']);
    }

    private function isAdmin(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }

    /** @param list<string> $endings */
    private function endsWithAny(string $email, array $endings): bool
    {
        $email = strtolower($email);

        foreach ($endings as $ending) {
            if (str_ends_with($email, strtolower($ending))) {
                return true;
            }
        }

        return false;
    }
};
