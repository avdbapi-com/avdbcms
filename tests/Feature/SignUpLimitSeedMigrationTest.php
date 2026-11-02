<?php

namespace Tests\Feature;

use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Up to 1.2.2 the bundled database/install/example.sql stored "Sign-ups allowed per visitor" as 1 (one account per
 * internet address and minute): a second person at the same home or office was refused. 1.2.3 allows 3 in 10 minutes
 * while no number is stored. The migration treats a stored value that is exactly that old seed (the text "1", never
 * changed since it was written) as unset; any other value, or a 1 the owner set later, is the owner's choice and stays.
 */
class SignUpLimitSeedMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_27_100000_unset_bundled_sign_up_limit.php';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    /** The row as example.sql (1.2.0 - 1.2.2) inserted it: created_at = updated_at. */
    private function seedRow(string $value, ?string $updatedAt = null): int
    {
        return (int) DB::table('settings')->insertGetId([
            'key' => 'max_register_attempts',
            'value' => $value,
            'group' => 'users',
            'created_at' => '2026-04-26 09:59:30',
            'updated_at' => $updatedAt ?? '2026-04-26 09:59:30',
        ]);
    }

    public function test_the_bundled_seed_is_unset_and_the_default_applies(): void
    {
        $this->seedRow('1');
        Setting::getGroup('users'); // the site has the old value cached
        $this->assertSame([1, RegisterRequest::STORED_WINDOW], RegisterRequest::signUpLimit());

        $this->migration()->up();

        $this->assertDatabaseMissing('settings', ['key' => 'max_register_attempts']);
        $this->assertSame([RegisterRequest::SIGNUP_DEFAULT, RegisterRequest::SIGNUP_WINDOW], RegisterRequest::signUpLimit(), 'the cache was cleared');
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'update.sign_up_limit_default']);

        // safe to run again: nothing left to change, no second log row
        $this->migration()->up();
        $this->assertSame(1, DB::table('admin_audit_logs')->where('action', 'update.sign_up_limit_default')->count());
    }

    public function test_an_owners_value_stays(): void
    {
        foreach (['2', '5', '20', ' 1', '1 ', '01', '', 'abc'] as $value) {
            DB::table('settings')->where('key', 'max_register_attempts')->delete();
            $this->seedRow($value);

            $this->migration()->up();

            $this->assertSame($value, DB::table('settings')->where('key', 'max_register_attempts')->value('value'), json_encode($value));
        }
        $this->assertSame(0, DB::table('admin_audit_logs')->where('action', 'update.sign_up_limit_default')->count());
    }

    public function test_a_one_the_owner_saved_later_stays(): void
    {
        // the owner changed the number and set 1 again: updated_at moved on, so it is a choice, not the seed
        $this->seedRow('1', '2026-09-01 12:00:00');

        $this->migration()->up();

        $this->assertSame('1', DB::table('settings')->where('key', 'max_register_attempts')->value('value'));
        $this->assertSame([1, RegisterRequest::STORED_WINDOW], RegisterRequest::signUpLimit());
    }

    public function test_a_row_in_another_group_and_a_missing_row_are_left_alone(): void
    {
        DB::table('settings')->insert(['key' => 'max_register_attempts', 'value' => '1', 'group' => 'security', 'created_at' => now(), 'updated_at' => now()]);
        $this->migration()->up();
        $this->assertSame('security', DB::table('settings')->where('key', 'max_register_attempts')->value('group'));

        DB::table('settings')->delete();
        $this->migration()->up();
        $this->assertSame(0, DB::table('settings')->count());
    }

    public function test_down_changes_nothing(): void
    {
        $this->seedRow('1');
        $this->migration()->up();
        $this->migration()->down();

        $this->assertDatabaseMissing('settings', ['key' => 'max_register_attempts']);
    }
}
