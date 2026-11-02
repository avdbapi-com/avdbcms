<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\EnvBackups;
use App\Support\EnvironmentRepairer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Security plan S24: .env holds every secret of the site. The permission repair never makes it world-readable (0640
 * instead of 0664, and the "files: 644" pass leaves every .env* file alone), a new .env is created 0640, and the .env
 * editor keeps the old version in storage/app/env-backups (0700/0600, a name nobody can guess, the newest 5) like the
 * payment-key page, never next to .env in the project folder. Round 1b judge R1B-016: 0640, not 0660 (the group's write
 * bit let the web server rewrite .env where it reads the site through the site's group); EnvModeTest has the rest.
 */
class EnvBackupsTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = '/^\.env\.\d{14}\.[0-9a-f]{16}$/';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        // A .env and a storage folder of the test's own (the copy's real ones stay untouched).
        $this->directory = str_replace('\\', '/', storage_path('framework/testing/env-backups-'.uniqid()));
        File::ensureDirectoryExists($this->directory.'/storage/app');
        file_put_contents($this->directory.'/.env', "APP_NAME=Test\nAPP_KEY=base64:dGVzdC1rZXktZm9yLWVudi1iYWNrdXBzLXRlc3QtMQ==\n");
        $this->app->useEnvironmentPath($this->directory);
        $this->app->useStoragePath($this->directory.'/storage');
        config(['admin.operational_tools_enabled' => true]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_the_permission_repair_never_opens_env_to_everybody(): void
    {
        $repairer = new EnvironmentRepairer();

        foreach ([true, false] as $canExec) {
            $plan = $repairer->filePermissionPlan($canExec, true);
            $this->assertSame(0640, $plan['modes'][base_path('.env')], 'written by its owner only, read by the group');
            $this->assertSame(0755, $plan['modes'][base_path('artisan')]);
        }

        $files = collect($repairer->filePermissionPlan(true, true)['commands'])->first(fn (string $command): bool => str_contains($command, '-type f'));
        $this->assertStringContainsString("! -name '.env*'", (string) $files, 'the 644 pass leaves .env and its copies alone');

        // From the console (app:doctor as another user, root) .env keeps its mode.
        $this->assertArrayNotHasKey(base_path('.env'), $repairer->filePermissionPlan(true, false)['modes']);

        // The ownership repair (storage/ not writable: chown to the PHP user) gives .env 640 too, never 660, 664 or 644.
        $source = (string) file_get_contents(app_path('Support/EnvironmentRepairer.php'));
        $this->assertStringContainsString("@exec('chmod 640 ' . escapeshellarg(\$envPath) . ' 2>&1');", $source);
        $this->assertDoesNotMatchRegularExpression('/chmod\s+0?6(?:44|64|60|66)\b[^\n]*envPath|envPath[^\n]*\b0?6(?:44|64|60|66)\b/', $source);
    }

    public function test_a_new_env_is_created_for_owner_and_group_only(): void
    {
        foreach (['index.php', 'public/index.php'] as $front) {
            $source = (string) file_get_contents(base_path($front));
            $this->assertStringContainsString('@chmod($envPath, 0640);', $source, $front);
            $this->assertStringNotContainsString('0664', $source, $front);
            $this->assertStringNotContainsString('0660', $source, $front);
        }
    }

    public function test_the_env_editor_keeps_the_old_version_in_env_backups(): void
    {
        $admin = User::factory()->admin()->create();
        $rootCopies = fn (): array => glob(base_path('.env.backup.*')) ?: [];
        $before = $rootCopies();

        for ($i = 1; $i <= 6; $i++) {
            $this->travel(1)->seconds();
            $response = $this->actingAs($admin)->put(route('admin.env-editor.update'), ['content' => "APP_NAME=Saved{$i}\n"]);
            $response->assertRedirect(route('admin.env-editor.index'));

            if ($i === 1) {
                $names = EnvBackups::names();
                $this->assertCount(1, $names);
                $this->assertMatchesRegularExpression(self::NAME, $names[0]);
                $response->assertSessionHas('success', '.env saved. The old version is kept in storage/app/env-backups/'.$names[0].'.');
            }
        }

        // (The form's text arrives trimmed: Laravel's TrimStrings.)
        $this->assertSame('APP_NAME=Saved6', file_get_contents($this->directory.'/.env'));
        $this->assertSame($before, $rootCopies(), 'nothing new next to .env in the project folder');

        $names = EnvBackups::names();
        $this->assertCount(EnvBackups::KEEP, $names, 'the newest five are kept');
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression(self::NAME, $name);
        }

        // The newest copy is the version before the last save.
        $this->assertSame('APP_NAME=Saved5', file_get_contents(EnvBackups::directory().'/'.$names[0]));
    }

    public function test_a_copy_moved_in_keeps_its_own_time_and_the_newest_five_stay(): void
    {
        foreach (['20240101000000', '20250101000000', '20260101000000', '20230101000000', '20220101000000', '20210101000000'] as $stamp) {
            $this->assertMatchesRegularExpression('/^\.env\.'.$stamp.'\.[0-9a-f]{16}$/', (string) EnvBackups::store('X='.$stamp, $stamp));
        }

        $stamps = array_map(fn (string $name): string => substr($name, 5, 14), EnvBackups::names());
        $this->assertSame(['20260101000000', '20250101000000', '20240101000000', '20230101000000', '20220101000000'], $stamps);
    }
}
