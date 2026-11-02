<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Judge R3-030: the RC2 packages shipped 7 new empty folders left by deleted files (database/schema/, images/errors/,
 * public/images/errors/ and four dialog folders of the views); new installs got them and updated sites kept an empty
 * database/schema/. scripts/build-community-release.ps1 now leaves every empty folder out of the staging copy before the
 * manifest and the zips (Remove-EmptyReleaseFolders), except the runtime folders under storage/, and Assert-CleanReleaseZip
 * refuses a package with an empty folder anywhere else. The functions are loaded from the script itself (PowerShell AST),
 * as ReleaseSeederGuardTest does, so the test checks the code the build runs.
 */
class ReleaseEmptyFoldersTest extends TestCase
{
    private const HARNESS = <<<'PS'
param([string] $Script, [string] $Work)
$ErrorActionPreference = 'Stop'
$tokens = $null; $errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($Script, [ref]$tokens, [ref]$errors)
if ($errors.Count -gt 0) { throw ('The build script does not parse: ' + $errors[0].Message) }
foreach ($name in @('Assert-PathInside', 'Remove-EmptyReleaseFolders', 'Assert-CleanReleaseZip')) {
    $fn = $ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq $name }, $true) | Select-Object -First 1
    if ($null -eq $fn) { throw "The build script has no function $name" }
    Invoke-Expression $fn.Extent.Text
}
$stage = Join-Path $Work 'stage'
foreach ($folder in @('database/schema', 'images/errors', 'public/images/errors', 'resources/views/users/activity/types',
    'public/vendor/hls', 'a/b/c', 'storage/app/chunks', 'storage/app/tmp/server-thumbnails', 'app')) {
    New-Item -ItemType Directory -Path (Join-Path $stage $folder) -Force | Out-Null
}
Set-Content -LiteralPath (Join-Path $stage 'app/x.php') -Value '<?php' -NoNewline
New-Item -ItemType Directory -Path (Join-Path $stage 'images/pages') -Force | Out-Null
Set-Content -LiteralPath (Join-Path $stage 'images/pages/contact.png') -Value 'png' -NoNewline
Remove-EmptyReleaseFolders -Stage $stage 6> $null | Out-Null
$left = @(Get-ChildItem -LiteralPath $stage -Recurse -Directory -Force | ForEach-Object { $_.FullName.Substring($stage.Length + 1).Replace('\', '/') } | Sort-Object)
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$required = @('README.md', 'COMMUNITY_SETUP_GUIDE.md', '.env.example', 'index.php', '.htaccess', 'public/index.php',
    'public/.htaccess', 'public/build/manifest.json', 'vendor/autoload.php', 'artisan', 'database/install/example.sql',
    'database/seeders/DatabaseSeeder.php', 'app/Support/ResilientPackageManifest.php', 'bootstrap/app.php',
    'app/Support/AppVersion.php', 'avdbcms-manifest.json')
$cases = [ordered]@{ storage = $required + @('storage/app/chunks/'); errors = $required + @('images/errors/'); schema = $required + @('database/schema/') }
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
[ordered]@{ left = $left; zips = $zips } | ConvertTo-Json -Depth 4 -Compress
PS;

    private ?string $work = null;

    protected function tearDown(): void
    {
        if ($this->work !== null) {
            File::deleteDirectory($this->work);
        }

        parent::tearDown();
    }

    public function test_the_build_ships_no_empty_folder_outside_storage(): void
    {
        // Also where Windows keeps it (another test of the run may have changed PATH).
        $powershell = PHP_OS_FAMILY === 'Windows'
            ? (new ExecutableFinder())->find('powershell', null, [rtrim((string) (getenv('SystemRoot') ?: 'C:\\Windows'), '\\/').'\\System32\\WindowsPowerShell\\v1.0'])
            : null;

        if ($powershell === null) {
            $this->markTestSkipped('Windows PowerShell runs the release build; it is not available here.');
        }

        $this->work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avdbcms-release-empty-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->work);
        file_put_contents($this->work.DIRECTORY_SEPARATOR.'harness.ps1', self::HARNESS);

        $process = new Process([
            $powershell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $this->work.DIRECTORY_SEPARATOR.'harness.ps1',
            '-Script', base_path('scripts/build-community-release.ps1'), '-Work', $this->work,
        ]);
        $process->setTimeout(120)->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $result = json_decode(trim($process->getOutput()), true);
        $this->assertIsArray($result, $process->getOutput());

        // The runtime folders under storage/ stay as placeholders; every other empty folder is gone, nested ones too.
        $this->assertSame(['app', 'images', 'images/pages', 'storage', 'storage/app', 'storage/app/chunks', 'storage/app/tmp', 'storage/app/tmp/server-thumbnails'], $result['left']);

        $this->assertSame('accepted', $result['zips']['storage']);
        $this->assertStringStartsWith('refused: Release package contains forbidden runtime files', $result['zips']['errors']);
        $this->assertStringContainsString('images/errors/ (an empty folder)', $result['zips']['errors']);
        $this->assertStringContainsString('database/schema/ (an empty folder)', $result['zips']['schema']);
    }
}
