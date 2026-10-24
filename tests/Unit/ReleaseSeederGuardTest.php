<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * scripts/build-community-release.ps1 (1.2.2): only the seeders the installer runs (DatabaseSeeder, CategorySeeder,
 * PlanSeeder) enter a release. Test-SkippedReleasePath leaves any other seeder out of the staging copy, and
 * Assert-CleanReleaseZip refuses a package that has one or that lacks DatabaseSeeder. The two functions are loaded
 * from the script itself (PowerShell AST), so the test checks the code the build runs.
 */
class ReleaseSeederGuardTest extends TestCase
{
    private const HARNESS = <<<'PS'
param([string] $Script, [string] $Work)
$ErrorActionPreference = 'Stop'
$tokens = $null; $errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($Script, [ref]$tokens, [ref]$errors)
if ($errors.Count -gt 0) { throw ('The build script does not parse: ' + $errors[0].Message) }
foreach ($name in @('Test-SkippedReleasePath', 'Assert-CleanReleaseZip')) {
    $fn = $ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq $name }, $true) | Select-Object -First 1
    if ($null -eq $fn) { throw "The build script has no function $name" }
    Invoke-Expression $fn.Extent.Text
}
$skipped = [ordered]@{}
foreach ($path in @(
    'database/seeders/DatabaseSeeder.php', 'database/seeders/CategorySeeder.php', 'database/seeders/PlanSeeder.php',
    'database/seeders/FleetDataEnricherSeeder.php', 'database/seeders/UserSeeder.php', 'database/seeders/ModelSeeder.php',
    'database/seeders/AnyNewDevSeeder.php', 'database/factories/UserFactory.php', 'database/install/example.sql',
    'database/migrations/2026_10_24_120100_remove_shipped_development_seeders.php',
    'tests/Fixtures/shipped-seeders/UserSeeder-1.2.1.php.txt', 'app/Models/User.php'
)) {
    $skipped[$path] = [bool] (Test-SkippedReleasePath -RelativePath $path -IsDirectory $false)
}
$skipped['database/seeders/'] = [bool] (Test-SkippedReleasePath -RelativePath 'database/seeders' -IsDirectory $true)
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$required = @('README.md', 'COMMUNITY_SETUP_GUIDE.md', '.env.example', 'index.php', '.htaccess', 'public/index.php',
    'public/.htaccess', 'public/build/manifest.json', 'vendor/autoload.php', 'artisan', 'database/install/example.sql',
    'database/seeders/DatabaseSeeder.php', 'app/Support/ResilientPackageManifest.php', 'bootstrap/app.php',
    'app/Support/AppVersion.php', 'avdbcms-manifest.json')
$cases = [ordered]@{
    clean = $required + @('database/seeders/CategorySeeder.php', 'database/seeders/PlanSeeder.php', 'database/factories/UserFactory.php')
    fleet = $required + @('database/seeders/FleetDataEnricherSeeder.php')
    user = $required + @('database/seeders/UserSeeder.php')
    no_database_seeder = @($required | Where-Object { $_ -ne 'database/seeders/DatabaseSeeder.php' })
}
$zips = [ordered]@{}
foreach ($case in $cases.Keys) {
    $zipPath = Join-Path $Work ($case + '.zip')
    $zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($entry in $cases[$case]) { $stream = $zip.CreateEntry($entry).Open(); $stream.Close() }
    } finally { $zip.Dispose() }
    try {
        Assert-CleanReleaseZip -ZipPath $zipPath -Kind 'update' 3> $null
        $zips[$case] = 'accepted'
    } catch {
        $zips[$case] = 'refused: ' + $_.Exception.Message
    }
}
[ordered]@{ skipped = $skipped; zips = $zips } | ConvertTo-Json -Depth 4 -Compress
PS;

    private ?string $work = null;

    protected function tearDown(): void
    {
        if ($this->work !== null) {
            File::deleteDirectory($this->work);
        }

        parent::tearDown();
    }

    public function test_only_the_installer_seeders_enter_a_release(): void
    {
        $result = $this->runHarness();

        $this->assertSame([
            'database/seeders/DatabaseSeeder.php' => false,
            'database/seeders/CategorySeeder.php' => false,
            'database/seeders/PlanSeeder.php' => false,
            'database/seeders/FleetDataEnricherSeeder.php' => true,
            'database/seeders/UserSeeder.php' => true,
            'database/seeders/ModelSeeder.php' => true,
            'database/seeders/AnyNewDevSeeder.php' => true,
            'database/factories/UserFactory.php' => false,
            'database/install/example.sql' => false,
            'database/migrations/2026_10_24_120100_remove_shipped_development_seeders.php' => false,
            'tests/Fixtures/shipped-seeders/UserSeeder-1.2.1.php.txt' => true,
            'app/Models/User.php' => false,
            'database/seeders/' => false,
        ], $result['skipped']);

        $this->assertSame('accepted', $result['zips']['clean']);
        $this->assertStringStartsWith('refused: Release package contains forbidden runtime files', $result['zips']['fleet']);
        $this->assertStringContainsString('database/seeders/FleetDataEnricherSeeder.php', $result['zips']['fleet']);
        $this->assertStringContainsString('database/seeders/UserSeeder.php', $result['zips']['user']);
        $this->assertSame('refused: Release package is missing required file: database/seeders/DatabaseSeeder.php', $result['zips']['no_database_seeder']);
    }

    /** @return array{skipped: array<string, bool>, zips: array<string, string>} */
    private function runHarness(): array
    {
        $powershell = PHP_OS_FAMILY === 'Windows' ? (new ExecutableFinder())->find('powershell') : null;
        if ($powershell === null) {
            $this->markTestSkipped('Windows PowerShell runs the release build; it is not available here.');
        }

        $this->work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avdbcms-release-guard-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->work);
        $harness = $this->work.DIRECTORY_SEPARATOR.'harness.ps1';
        file_put_contents($harness, self::HARNESS);

        $process = new Process([
            $powershell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $harness,
            '-Script', base_path('scripts/build-community-release.ps1'), '-Work', $this->work,
        ]);
        $process->setTimeout(120)->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $result = json_decode(trim($process->getOutput()), true);
        $this->assertIsArray($result, $process->getOutput());

        return $result;
    }
}
