<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RI4-P1 (since 1.2.2): "Ask new members to confirm their email" was 0 in the bundled database/install/example.sql but
 * the migration 2026_05_05_030000 inserted '1' in migrations+seeders mode, so the two install modes disagreed and a
 * site installed by the migrations locked new members out of uploading and commenting before its email was set up.
 * Decision (owner's zero-config rule): OFF in both modes until email works -- the migration inserts '0', the bundled
 * example.sql keeps '0', and the admin_settings default is '0'; the setting's help says to turn it on once email works.
 */
class EmailVerificationDefaultOffTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_05_05_030000_add_email_settings_and_verification_toggle';

    private const KEY = 'email_verification_required';

    public function test_a_migrations_install_starts_with_email_confirmation_off(): void
    {
        // RefreshDatabase ran every migration (migrations+seeders mode): the setting is present and OFF.
        $this->assertSame('0', (string) DB::table('settings')->where('key', self::KEY)->value('value'),
            'a site installed by the migrations starts with email confirmation off');

        // The migration's own insert branch, on a fresh settings table (the row not there yet).
        DB::table('settings')->where('key', self::KEY)->delete();
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $migration->up();

        $this->assertSame('0', (string) DB::table('settings')->where('key', self::KEY)->value('value'),
            'the migration inserts 0, not 1 (RI4-P1)');
        $this->assertSame('users', (string) DB::table('settings')->where('key', self::KEY)->value('group'));

        // A value the owner already chose is never overwritten: running it again leaves the row as it is.
        DB::table('settings')->where('key', self::KEY)->update(['value' => '1']);
        $migration->up();
        $this->assertSame('1', (string) DB::table('settings')->where('key', self::KEY)->value('value'),
            'an existing (owner) value stays');
    }

    public function test_the_bundled_example_sql_starts_with_email_confirmation_off(): void
    {
        // example.sql install mode: the bundled data imports the setting as OFF.
        $sql = (string) file_get_contents(database_path('install/example.sql'));

        $this->assertSame(1, preg_match("/\\(\\d+,'".self::KEY."','([^']*)','users'/", $sql, $m),
            'example.sql has the setting row');
        $this->assertSame('0', $m[1], 'the bundled example.sql starts with email confirmation off (RI4-P1)');
    }

    public function test_the_admin_setting_default_is_off_and_its_help_tells_you_to_turn_it_on_once_email_works(): void
    {
        $config = require config_path('admin_settings.php');
        $setting = $config['settings'][self::KEY] ?? [];

        $this->assertSame('0', $setting['default'] ?? null, 'the admin_settings default is off (RI4-P1)');
        $this->assertSame('bool', $setting['type'] ?? null);
        $this->assertStringContainsString('turn it on', (string) ($setting['help'] ?? ''), 'the help tells the owner to turn it on');
        $this->assertStringContainsString('Settings > Email', (string) ($setting['help'] ?? ''), 'and where to make email work');
    }
}
