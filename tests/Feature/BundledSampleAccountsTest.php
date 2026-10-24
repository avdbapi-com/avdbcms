<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Accounts with a password other people know (1.2.2, migration 2026_10_24_120200). The community packages before 1.2.0
 * before 1.2.0 shipped the sample members pbergstrom and blackbox_creator in example.sql with password hashes from
 * the developers' test site (later both with the password of the package's admin row); 1.2.0 fixed example.sql for
 * new sites only. Until 20 September 2026 the installer's "Run migrations and seeders" also ran UserSeeder, whose
 * factory members have the password "password". On an updated site each such member gets a random password nobody
 * knows; admins, accounts whose password was changed and everything else stay as they are.
 */
class BundledSampleAccountsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_24_120200_lock_bundled_sample_accounts';

    public function test_the_migration_knows_the_bundled_hashes_and_the_factory_members(): void
    {
        $migration = $this->migration();

        $this->assertCount(3, $migration::BUNDLED_HASHES);
        foreach ($migration::BUNDLED_HASHES as $fingerprint) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        }

        // UserSeeder and ModelSeeder made their members with the factory as shipped up to 1.2.1.
        $this->assertSame(['@example.com', '@example.net', '@example.org'], $migration::FACTORY_EMAIL_DOMAINS);
        $this->assertSame('password', $migration::FACTORY_PASSWORD);
        foreach (['UserSeeder-1.2.1.php.txt', 'ModelSeeder-1.2.1.php.txt'] as $fixture) {
            $this->assertStringContainsString('User::factory(', (string) file_get_contents(base_path('tests/Fixtures/shipped-seeders/'.$fixture)), $fixture);
        }
    }

    public function test_the_fleet_data_enricher_members_have_no_usable_password_and_are_left_alone(): void
    {
        // FleetDataEnricherSeeder passed Hash::make('password123') to User::firstOrCreate(), and the User model hashes
        // every password it is given, so "password123" never signed anyone in: nothing to lock.
        $source = (string) file_get_contents(base_path('tests/Fixtures/shipped-seeders/FleetDataEnricherSeeder-1.2.1.php.txt'));
        $this->assertStringContainsString("\$hashedPassword = Hash::make('password123');", $source);
        $this->assertStringContainsString("'password' => \$hashedPassword,", $source);

        $seeded = (int) User::query()->firstOrCreate(['username' => 'CinemaCurator_JP'], [
            'slug' => 'cinemacurator-jp', 'email' => 'cinemacurator_jp@avdbcms.example', 'password' => Hash::make('password123'),
        ])->id;
        $stored = (string) DB::table('users')->where('id', $seeded)->value('password');
        $this->assertFalse(Hash::check('password123', $stored));
        $before = $this->rows();

        $this->migration()->up();

        $this->assertEquals($before, $this->rows());
    }

    public function test_members_with_a_bundled_password_are_locked_and_nothing_else_changes(): void
    {
        $sample = $this->user('pbergstrom', 'user2@example.com', 'the-old-package-password');
        $renamed = $this->user('film_club', 'user3@example.com', 'the-old-package-password');
        $admin = $this->user('admin', 'admin@example.com', 'the-old-package-password', admin: true);
        $member = $this->user('real_member', 'member@example.org', 'a-password-of-their-own');
        $changed = $this->user('blackbox_creator', 'user4@example.com', 'changed-by-the-owner');
        $this->tokenAndSession($sample);
        $this->tokenAndSession($member);
        $before = $this->rows();

        $listed = array_map(fn (int $id): string => hash('sha256', (string) DB::table('users')->where('id', $id)->value('password')), [$sample, $renamed, $admin]);
        $locked = $this->migration()->lockBundledHashes($listed);

        $this->assertSame([$sample, $renamed], $locked);
        foreach ([$sample, $renamed] as $id) {
            $row = DB::table('users')->where('id', $id)->first();
            $this->assertFalse(Hash::check('the-old-package-password', $row->password), "user {$id}: the old password no longer works");
            $this->assertTrue(Hash::isHashed($row->password), "user {$id}: still a valid password hash");
            $this->assertNotSame($before[$id]->remember_token, $row->remember_token, "user {$id}: remember-me token renewed");
            $this->assertSame([$before[$id]->username, $before[$id]->email], [$row->username, $row->email], "user {$id}: the account stays");
        }
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $sample)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $sample)->count());

        // Tools › Logs › Admin actions names the accounts by the user name the Members search finds, in full.
        $audit = DB::table('admin_audit_logs')->where('action', 'security.known_passwords_reset')->get();
        $this->assertCount(1, $audit);
        $this->assertNull($audit[0]->user_id);
        $this->assertSame('1.2.2: 2 members had a password others know and got a random one: pbergstrom, film_club', $audit[0]->details);
        $this->assertFitsTheAdminLists($audit[0]->details);

        // Admins, other members and accounts whose password changed: untouched, with their tokens and sessions.
        foreach ([$admin, $member, $changed] as $id) {
            $this->assertEquals($before[$id], DB::table('users')->where('id', $id)->first(), "user {$id} is untouched");
        }
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $member)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $member)->count());
    }

    public function test_members_the_model_factory_created_are_locked(): void
    {
        // UserSeeder (run by the installer's "Run migrations and seeders" until 20 September 2026) made factory
        // members: Faker e-mail addresses at example.com/.net/.org and the factory password "password".
        $factory = (int) User::factory()->create(['email' => 'lora.kling@example.net'])->id;
        $another = (int) User::factory()->create(['email' => 'hfeil@example.org'])->id;
        $realDomain = (int) User::factory()->create(['email' => 'someone@gmail.example'])->id;
        $ownPassword = $this->user('test_member', 'test@example.com', 'a-password-of-their-own');
        $admin = $this->user('helper_admin', 'helper@example.com', 'password', admin: true);
        $this->assertTrue(Hash::check('password', (string) DB::table('users')->where('id', $factory)->value('password')), 'the factory password of the test suite');
        $before = $this->rows();

        $this->migration()->up();

        foreach ([$factory, $another] as $id) {
            $this->assertFalse(Hash::check('password', (string) DB::table('users')->where('id', $id)->value('password')), "user {$id} is locked");
        }
        foreach ([$realDomain, $ownPassword, $admin] as $id) {
            $this->assertEquals($before[$id], DB::table('users')->where('id', $id)->first(), "user {$id} is untouched");
        }

        // Safe to run again: nothing matches any more, nothing more is logged.
        $after = $this->rows();
        $this->migration()->up();
        $this->assertEquals($after, $this->rows());
        $this->migration()->down();
        $this->assertEquals($after, $this->rows());
        $this->assertSame(1, DB::table('admin_audit_logs')->where('action', 'security.known_passwords_reset')->count(), 'logged once');
    }

    public function test_many_members_take_more_admin_actions_rows_that_each_fit_the_lists(): void
    {
        // A site where "Run migrations and seeders" ran UserSeeder has dozens of factory members.
        $expected = [];
        for ($i = 1; $i <= 24; $i++) {
            $name = sprintf('factory_member_%02d_%s', $i, str_repeat('x', $i % 7));
            User::factory()->create(['username' => $name, 'email' => "member{$i}@example.com"]);
            $expected[] = $name;
        }
        $long = str_repeat('a_very_long_user_name_', 4);
        User::factory()->create(['username' => $long, 'email' => 'long@example.net']);
        $wide = str_repeat('映画ファン', 6);
        User::factory()->create(['username' => $wide, 'email' => 'wide@example.org']);

        $this->migration()->up();

        $rows = DB::table('admin_audit_logs')->where('action', 'security.known_passwords_reset')->orderBy('id')->pluck('details')->all();
        $this->assertGreaterThan(1, count($rows), 'more than one row');

        $named = [];
        $total = 0;
        foreach ($rows as $details) {
            $this->assertFitsTheAdminLists($details);
            $this->assertSame(1, preg_match('/^1\.2\.2: (\d+) members? had a password others know and got a random one: (.+)$/u', $details, $match), $details);
            $names = explode(', ', $match[2]);
            $this->assertCount((int) $match[1], $names, $details);
            $total += (int) $match[1];
            $named = array_merge($named, $names);
        }

        // Every member once, in the order of their ids; very long names are shortened to 40 columns.
        $this->assertSame(26, $total);
        $this->assertSame(array_merge($expected, [mb_strimwidth($long, 0, 37, '', 'UTF-8').'...', mb_strimwidth($wide, 0, 37, '', 'UTF-8').'...']), $named);
        $this->assertSame(40, mb_strwidth($named[24], 'UTF-8'));
        $this->assertLessThanOrEqual(40, mb_strwidth($named[25], 'UTF-8'));

        // Run again: nothing more to lock, nothing more logged.
        $this->migration()->up();
        $this->assertSame(count($rows), DB::table('admin_audit_logs')->count());
    }

    public function test_the_admin_actions_text_for_one_member_and_a_member_without_a_name(): void
    {
        $migration = $this->migration();

        $this->assertSame(['1.2.2: 1 member had a password others know and got a random one: pbergstrom'], $migration::auditDetails(['pbergstrom']));
        $this->assertSame([], $migration::auditDetails([]));

        // The longest possible name still fits one row.
        $longest = str_repeat('w', $migration::NAME_MAX_LENGTH);
        $this->assertCount(1, $migration::auditDetails([$longest]));
        $this->assertFitsTheAdminLists($migration::auditDetails([$longest])[0]);

        $id = (int) User::factory()->create(['email' => 'nameless@example.com'])->id;
        DB::table('users')->where('id', $id)->update(['username' => '']);

        $this->migration()->up();

        $this->assertSame(
            ["1.2.2: 1 member had a password others know and got a random one: #{$id}"],
            DB::table('admin_audit_logs')->where('action', 'security.known_passwords_reset')->pluck('details')->all(),
        );
    }

    public function test_a_new_site_and_a_site_without_these_accounts_are_left_alone(): void
    {
        $this->user('owner', 'owner@example.org', 'owner-password', admin: true);
        $this->user('pbergstrom', 'user2@example.com', 'some-unusable-password');
        $before = $this->rows();

        $this->migration()->up();

        $this->assertEquals($before, $this->rows());
        $this->assertSame(0, DB::table('admin_audit_logs')->count(), 'nothing changed, nothing logged');
    }

    public function test_missing_tables_are_skipped(): void
    {
        $id = (int) User::factory()->create(['email' => 'factory.member@example.com'])->id;
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('sessions');

        $this->migration()->up();
        $this->assertFalse(Hash::check('password', (string) DB::table('users')->where('id', $id)->value('password')));

        Schema::disableForeignKeyConstraints();
        Schema::drop('users');
        Schema::enableForeignKeyConstraints();
        $this->assertSame([], $this->migration()->lockBundledHashes(['x']));
        $this->migration()->up();
    }

    public function test_the_sample_members_of_every_old_package_are_locked(): void
    {
        // Optional cross-check with the real packages: AVD_OLD_PACKAGES="path/a.zip;path/b.zip" (packages before 1.2.0).
        $zips = array_filter(explode(';', (string) getenv('AVD_OLD_PACKAGES')), 'is_file');
        if ($zips === [] || ! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('Set AVD_OLD_PACKAGES to the old community packages to check their sample members.');
        }

        $checked = 0;
        foreach ($zips as $path) {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($path) === true, $path);
            $sql = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                // The packages of August/September 2026 stored their paths with backslashes.
                if (str_replace('\\', '/', (string) $zip->getNameIndex($i)) === 'database/install/example.sql') {
                    $sql = (string) $zip->getFromIndex($i);
                }
            }
            $zip->close();

            preg_match_all("/\\((6|68),'(pbergstrom|blackbox_creator)','[^']*','[^']*','([^']*)',(?:NULL|'[^']*'),'(\\\$2y\\\$[^']+)'/", $sql, $rows, PREG_SET_ORDER);
            $this->assertCount(2, $rows, basename($path).': the two sample members');

            foreach ($rows as [, , $username, $email, $hash]) {
                $id = $this->user($username.'_'.$checked, $checked.'_'.$email, 'placeholder');
                DB::table('users')->where('id', $id)->update(['password' => $hash]);

                $this->assertSame([$id], $this->migration()->lockBundledHashes($this->migration()::BUNDLED_HASHES), basename($path).": {$username}");
                $this->assertNotSame($hash, DB::table('users')->where('id', $id)->value('password'));
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked);
    }

    /** The old admin's list shows Str::limit(details, 120), the new admin's 160. */
    private function assertFitsTheAdminLists(string $details): void
    {
        $this->assertLessThanOrEqual(120, mb_strwidth($details, 'UTF-8'), $details);
        $this->assertSame($details, Str::limit($details, 120), 'the old admin list shows all of it');
        $this->assertSame($details, Str::limit($details, 160), 'the new admin list shows all of it');
    }

    private function user(string $username, string $email, string $password, bool $admin = false): int
    {
        $user = User::factory()->create(['username' => $username, 'email' => $email, 'password' => $password]);
        DB::table('users')->where('id', $user->id)->update(['is_admin' => $admin ? 1 : null, 'remember_token' => 'token-'.$username]);

        return (int) $user->id;
    }

    private function tokenAndSession(int $id): void
    {
        DB::table('personal_access_tokens')->insert(['tokenable_type' => User::class, 'tokenable_id' => $id, 'name' => 'app', 'token' => hash('sha256', 'token-'.$id), 'abilities' => '["*"]']);
        DB::table('sessions')->insert(['id' => 'session-'.$id, 'user_id' => $id, 'payload' => 'x', 'last_activity' => time()]);
    }

    /** @return array<int, object> */
    private function rows(): array
    {
        return DB::table('users')->orderBy('id')->get()->keyBy('id')->all();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }
}
