<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\Support\BundledSql;
use Tests\TestCase;

/**
 * The bundled backup a new site imports (database/install/example.sql, the installer's default "Import SQL backup") is a
 * clean seed: rows in the seed tables only, no secret setting filled in (the Upload18 key ships on purpose), and no
 * account anybody can sign in to. Each user row has its own random, unusable bcrypt hash, made by the release tool
 * from secrets nobody keeps. Up to 1.2.2 the admin row kept a hash whose
 * password the users of the older packages know: the installer gives that row the owner's password, but an install that
 * stopped before that step left it open. The owner still signs in with the password typed in the wizard.
 *
 * No assertion message prints a value or a hash.
 */
class BundledSeedHygieneTest extends TestCase
{
    use RefreshDatabase;

    /** The tables example.sql fills: everything else of a new site starts empty. */
    private const SEED_TABLES = ['categories', 'languages', 'migrations', 'plans', 'settings', 'users'];

    /** Passwords people try first. No bundled account opens with one, nor with its own user name or e-mail address. */
    public const COMMON_PASSWORDS = [
        'password', 'Password', 'password1', 'password123', 'admin', 'Admin', 'admin123', 'administrator', '12345678',
        '123456789', '1234567890', '123456', 'qwerty', 'qwerty123', 'letmein', 'welcome', 'secret', 'demo', 'Demo@2026',
        'demo123', 'changeme', 'test', 'test123', 'abc123', '111111', '000000', 'avdbcms', 'avdbclip', 'root', 'P@ssw0rd',
    ];

    /** The lowest bcrypt cost a bundled hash may have. */
    private const MIN_COST = 10;

    /** Settings that hold a secret: they ship empty. upload18_api_key ships on purpose (owner decision 2026-10-13). */
    private const SECRET_SETTINGS = '/(secret|password|_token)$|^(smtp_username|indexnow_key|captcha_site_key)$/';

    private const SAMPLE_ACCOUNTS_MIGRATION = '2026_10_24_120200_lock_bundled_sample_accounts';

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.captcha_provider' => 'none', 'site.login_rate_limit' => 1000]);
    }

    public function test_the_bundled_backup_fills_the_seed_tables_only(): void
    {
        $this->assertSame(self::SEED_TABLES, BundledSql::tables(BundledSql::read()));
    }

    public function test_no_two_bundled_accounts_share_a_password_and_none_opens_with_a_known_one(): void
    {
        $users = BundledSql::rows(BundledSql::read(), 'users');

        $this->assertNotEmpty($users, 'the bundled backup has its accounts');
        $this->assertSame('1', (string) $users[0]['is_admin'], 'its first account is the admin row the installer gives the owner');
        $this->assertGreaterThanOrEqual(20, count(self::COMMON_PASSWORDS));
        $this->assertSame([], $this->accountProblems($users));
    }

    public function test_the_check_finds_each_fault(): void
    {
        // Rows made here (cost 4 for speed): the check above must catch a shared hash, a common password, an account
        // that opens with its own name, a hash of an older package, a low cost, a remember token and a missing hash.
        $shared = password_hash('a-shared-secret-'.bin2hex(random_bytes(8)), PASSWORD_BCRYPT, ['cost' => 4]);
        $migration = require database_path('migrations/'.self::SAMPLE_ACCOUNTS_MIGRATION.'.php');
        $users = [
            ['username' => 'one', 'email' => 'one@example.com', 'password' => $shared, 'remember_token' => null],
            ['username' => 'two', 'email' => 'two@example.com', 'password' => $shared, 'remember_token' => 'abc'],
            ['username' => 'three', 'email' => 'three@example.com', 'password' => password_hash('Demo@2026', PASSWORD_BCRYPT, ['cost' => 4]), 'remember_token' => null],
            ['username' => 'four', 'email' => 'four@example.com', 'password' => password_hash('four', PASSWORD_BCRYPT, ['cost' => 4]), 'remember_token' => null],
            ['username' => 'five', 'email' => 'five@example.com', 'password' => 'not a hash', 'remember_token' => null],
        ];

        $problems = implode("\n", $this->accountProblems($users, [hash('sha256', $shared)]));

        foreach (['share one password hash', 'row 3 (three): opens with a common password', 'row 4 (four): opens with a common password',
            'row 1 (one): a hash an older package shipped', 'row 1 (one): bcrypt cost 04', 'row 2 (two): has a remember token', 'row 5 (five): no bcrypt hash'] as $expected) {
            $this->assertStringContainsString($expected, $problems);
        }

        // the real list of the 1.2.2 migration is what the test above reads
        $this->assertNotEmpty($migration::BUNDLED_HASHES);

        // and a clean set passes
        $clean = array_map(fn (string $name): array => ['username' => $name, 'email' => $name.'@example.com', 'remember_token' => null,
            'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => self::MIN_COST])], ['admin', 'member']);
        $this->assertSame([], $this->accountProblems($clean));
    }

    public function test_the_bundled_settings_hold_no_secret(): void
    {
        $checked = 0;

        foreach (BundledSql::rows(BundledSql::read(), 'settings') as $setting) {
            $key = (string) $setting['key'];

            if ($key !== 'upload18_api_key' && preg_match(self::SECRET_SETTINGS, $key) === 1) {
                $checked++;
                $this->assertContains($setting['value'], [null, ''], "setting {$key} ships empty");
            }
        }

        $this->assertGreaterThanOrEqual(5, $checked, 'the secret settings are in the backup');
    }

    public function test_a_new_site_signs_in_its_owner_and_no_bundled_account(): void
    {
        // What "Import SQL backup" leaves in the users table (the columns this schema has) ...
        $columns = array_flip(Schema::getColumnListing('users'));
        foreach (BundledSql::rows(BundledSql::read(), 'users') as $row) {
            DB::table('users')->insert(array_intersect_key($row, $columns));
        }
        $bundled = DB::table('users')->orderBy('id')->get(['username', 'email'])->all();
        $adminRow = (int) DB::table('users')->where('is_admin', 1)->value('id');
        $this->assertGreaterThan(0, $adminRow, 'the backup has an admin row');

        // ... and then the installer's step for the owner's account, with the answers of the wizard.
        $installer = app(Installer::class);
        (new ReflectionMethod($installer, 'configureAdmin'))->invoke($installer, ['username' => 'site_owner', 'email' => 'owner@example.org', 'password' => 'Owner-pass-2026!']);

        $owner = User::query()->where('username', 'site_owner')->firstOrFail();
        $this->assertTrue((bool) $owner->is_admin);
        $this->assertSame($adminRow, (int) $owner->id, 'the bundled admin row became the owner\'s account');

        foreach (['site_owner', 'owner@example.org'] as $name) {
            $this->post(route('login.perform'), ['username' => $name, 'password' => 'Owner-pass-2026!'])->assertRedirect();
            $this->assertAuthenticatedAs($owner);
            auth()->logout();
            $this->app['auth']->forgetGuards();
        }

        // Nobody opens a bundled account (the admin row's old name included) with a password people try.
        foreach ($bundled as $account) {
            foreach ([$account->username, $account->email] as $name) {
                foreach (['password', 'admin', 'demo', '12345678', 'Demo@2026'] as $password) {
                    $this->post(route('login.perform'), ['username' => $name, 'password' => $password]);
                    $this->assertGuest();
                }
            }
        }
    }

    /**
     * What is wrong with these accounts' passwords; empty when nothing is.
     *
     * @param  list<array<string, string|null>>  $users
     * @param  list<string>|null  $known  SHA-256 of hashes older packages shipped (default: the 1.2.2 migration's list)
     * @return list<string>
     */
    private function accountProblems(array $users, ?array $known = null): array
    {
        $known ??= (require database_path('migrations/'.self::SAMPLE_ACCOUNTS_MIGRATION.'.php'))::BUNDLED_HASHES;
        $problems = [];
        $hashes = [];

        foreach (array_values($users) as $i => $user) {
            $row = sprintf('row %d (%s)', $i + 1, (string) $user['username']);
            $hash = (string) $user['password'];
            $hashes[] = $hash;

            if (preg_match('/^\$2y\$(\d\d)\$[.\/A-Za-z0-9]{53}$/', $hash, $shape) !== 1) {
                $problems[] = "{$row}: no bcrypt hash";

                continue;
            }

            if ((int) $shape[1] < self::MIN_COST) {
                $problems[] = "{$row}: bcrypt cost {$shape[1]}, under ".self::MIN_COST;
            }

            if (in_array(hash('sha256', $hash), $known, true)) {
                $problems[] = "{$row}: a hash an older package shipped (its password is known to others)";
            }

            if ($user['remember_token'] !== null) {
                $problems[] = "{$row}: has a remember token";
            }

            foreach (array_merge(self::COMMON_PASSWORDS, [(string) $user['username'], (string) $user['email']]) as $password) {
                if (password_verify($password, $hash)) {
                    $problems[] = "{$row}: opens with a common password";

                    break;
                }
            }
        }

        if (count(array_unique($hashes)) !== count($hashes)) {
            $problems[] = 'two accounts share one password hash';
        }

        return $problems;
    }
}
