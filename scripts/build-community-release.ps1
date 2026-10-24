# Builds the AVDBCMS release assets for the version in app/Support/AppVersion.php (AppVersion::CURRENT), to be
# uploaded to the GitHub release tagged "v" + CURRENT:
#
#   avdbcms-X.Y.Z.zip         + .sha256   the full package for new installs (with bin/, the bundled FFmpeg builds)
#   avdbcms-update-X.Y.Z.zip  + .sha256   the same files without bin/, for the one-click updater (Tools > Updates)
#
# Both zips carry avdbcms-manifest.json at their root: product, version, requires_php, min_from, built_at, files
# (path -> SHA-256 of every file except bin/, storage/, database/install/) and removed (the paths the previous
# release's manifest names, its files and its own removed list, that this one does not ship). The .sha256 files are
# in sha256sum format ("<hex>  <name>" and a line feed). Each
# zip is checked before it gets its final name (Assert-CleanReleaseZip, then scripts/release-manifest.php verify: the
# zip equals the staging folder, the updater's own entry guard accepts it, ZipArchive and PharData unpack every file
# with the hash of the manifest; with the previous release's zip, its updater must take the update zip too), and each
# .sha256 file is checked against its zip after it is written.
#
#   -Tag vX.Y.Z             refuse to build unless it is exactly "v" + AppVersion::CURRENT (nothing is written then)
#   -PreviousManifest FILE  the previous release's zip (best: also checks that its updater takes the new update zip)
#                           or its avdbcms-manifest.json; gives "removed"
#   -OutDir DIR             where the four assets go (default: the folder that holds the project)
#   -FromStaging DIR        package an existing staging folder only (no copy, no translation check, no Composer),
#                           e.g. one a build left with -KeepStaging; the manifest is written into it
#   -Root DIR               build another project tree with this script (never the workspace)
#   -KeepStaging            keep build/release/avdbcms-community-<time> after the build
#   -PhpPath FILE           the PHP 8.3+ to run (default: the OSPanel PHP 8.3 / 8.4, then php on PATH)
#   -RequiresPhp, -MinFrom  requires_php (default 8.3.0) and min_from (default 1.2.0) of the manifest
#   -OutputPath FILE        older parameter: only its folder is used now (the asset names come from the version)

param(
    [string] $OutputPath = '',
    [switch] $KeepStaging,
    [string] $Root = '',
    [string] $Tag = '',
    [string] $PreviousManifest = '',
    [string] $OutDir = '',
    [string] $FromStaging = '',
    [string] $PhpPath = '',
    [string] $RequiresPhp = '8.3.0',
    [string] $MinFrom = '1.2.0'
)

$ErrorActionPreference = 'Stop'

$root = if ($Root -ne '') { (Resolve-Path -LiteralPath $Root).Path } else { (Resolve-Path (Join-Path $PSScriptRoot '..')).Path }
# Never package the development workspace: it holds private working files (OD11). Build from a release copy.
if ($root.TrimEnd('\') -ieq 'D:\OSPanel\domains\avdbclip-community-installer') { throw 'Refusing to build from the workspace (OD11)' }
$buildRoot = Join-Path $root 'build'
$stageRoot = Join-Path $buildRoot 'release'
$stage = Join-Path $stageRoot ("avdbcms-community-" + (Get-Date -Format "yyyyMMddHHmmss"))
$releaseTool = Join-Path $PSScriptRoot 'release-manifest.php'

function Resolve-FullPath {
    param([string] $Path)

    # Relative to the current PowerShell location, also for a folder that does not exist yet.
    return $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($Path).TrimEnd('\', '/')
}

if ($OutDir -eq '') {
    if ($OutputPath -ne '') {
        $OutDir = Split-Path (Resolve-FullPath $OutputPath) -Parent
        Write-Warning "-OutputPath only chooses the folder now: the assets are named from AppVersion::CURRENT and written to $OutDir"
    } elseif ($FromStaging -ne '') {
        $OutDir = Split-Path (Resolve-FullPath $FromStaging) -Parent
    } else {
        $OutDir = Split-Path $root -Parent
    }
}
$outDirFull = Resolve-FullPath $OutDir

function Test-SkippedReleasePath {
    param(
        [string] $RelativePath,
        [bool] $IsDirectory
    )

    $path = ($RelativePath -replace '\\', '/').Trim('/')
    if ($path -eq '') {
        return $false
    }

    $leaf = Split-Path $path -Leaf
    $top = ($path -split '/')[0]

    if ($top -in @(
        '.agents',
        '.git',
        '.phpunit.cache',
        '.tmp-npm-cache',
        '.tmp-playwright',
        '.tmp-playwright-browsers',
        '.playwright-mcp',
        '.tools',
        '.vscode',
        '.idea',
        'build',
        'chat-export',
        'current_remote',
        'deploy',
        'incident_patch',
        'node_modules',
        'ops',
        'remote_domain_patch',
        'remote_patch',
        'scratch',
        'scripts',
        'tests',
        'vendor'
    )) {
        return $true
    }

    # Conversation exports are private working material and must never enter
    # a distributable application archive, including dated export directories.
    if ($top -like 'chat-export-*') {
        return $true
    }

    # The tool files of the R2 working copies (.r2-origin.json, .r2-serve.pid).
    if ($path -like '.r2-*') {
        return $true
    }

    if ($path -in @(
        'CURRENT_STATE.md',
        'scratch_themes.json',
        'theme_test_report.json',
        'Makefile',
        'phpunit.xml',
        'phpstan.neon',
        'package.json',
        'package-lock.json',
        'bun.lockb',
        'tsconfig.json',
        'vite.config.ts',
        '.editorconfig',
        'public/hot'
    )) {
        return $true
    }

    # The R1 QA seed posters.
    if ($path -eq 'public/images/tr' -or $path.StartsWith('public/images/tr/')) {
        return $true
    }

    if ($leaf -like '.env*' -and $leaf -ne '.env.example') {
        return $true
    }

    if ($leaf -eq '.user.ini') {
        return $true
    }

    if ($path.StartsWith('bootstrap/cache/')) {
        if ($leaf -in @('.gitignore', '.gitkeep')) {
            return $false
        }

        return $true
    }

    if ($path -eq 'storage/app/installed.json') {
        return $true
    }

    if ($path.StartsWith('database/install/') -and $leaf -like '*.sql' -and $path -ne 'database/install/example.sql') {
        return $true
    }

    # Only the seeders the installer runs ("Run migrations and seeders": categories and plans) ship. A development
    # seeder can create accounts with known passwords, so it never does, even if one comes back into the tree (1.2.2).
    if (-not $IsDirectory -and $path.StartsWith('database/seeders/') -and $path -notin @('database/seeders/DatabaseSeeder.php', 'database/seeders/CategorySeeder.php', 'database/seeders/PlanSeeder.php')) {
        return $true
    }

    if (-not $IsDirectory -and $path.StartsWith('storage/framework/')) {
        return $true
    }

    # Under storage/ only the empty placeholders ship: the updater refuses any other file there.
    if (-not $IsDirectory -and $path.StartsWith('storage/') -and $leaf -ne '.gitkeep' -and $leaf -ne '.gitignore') {
        return $true
    }

    if ($leaf -like '*.zip' -or $leaf -like '*.log' -or $leaf -like '*.tsv' -or $leaf -like '*.bak*' -or $leaf -like 'admin-*.png' -or $leaf -like 'live_*.png' -or $leaf -like 'avdb-import-*' -or $leaf -like '*.py') {
        return $true
    }

    # Release checksums, and the new copies the updater leaves next to files an owner changed (<name>.avdbcms-X.Y.Z).
    if ($leaf -like '*.zip.sha256' -or $leaf -match '\.avdbcms-\d+\.\d+\.\d+$') {
        return $true
    }

    foreach ($prefix in @(
        'storage/app/backups',
        'storage/app/installer-backups',
        'storage/app/private',
        'storage/app/public',
        'storage/app/updates',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/testing',
        'storage/framework/views',
        'storage/logs',
        'public/avatars',
        'public/storage',
        'public/thumbnails',
        'public/uploads'
    )) {
        if ($path -eq $prefix -or $path.StartsWith($prefix + '/')) {
            return $true
        }
    }

    return $false
}

function Copy-ReleaseTree {
    param(
        [string] $Source,
        [string] $Destination,
        [string] $RelativePath = ''
    )

    foreach ($item in Get-ChildItem -LiteralPath $Source -Force) {
        $childRelative = if ($RelativePath -eq '') { $item.Name } else { $RelativePath + '/' + $item.Name }
        if (Test-SkippedReleasePath -RelativePath $childRelative -IsDirectory $item.PSIsContainer) {
            continue
        }

        $target = Join-Path $Destination $item.Name

        if ($item.PSIsContainer) {
            New-Item -ItemType Directory -Path $target -Force | Out-Null
            Copy-ReleaseTree -Source $item.FullName -Destination $target -RelativePath $childRelative
            continue
        }

        New-Item -ItemType Directory -Path (Split-Path $target -Parent) -Force | Out-Null
        Copy-Item -LiteralPath $item.FullName -Destination $target -Force
    }
}

function Assert-PathInside {
    param(
        [string] $Child,
        [string] $Parent
    )

    $resolvedParent = (Resolve-Path $Parent).Path.TrimEnd('\', '/')
    $resolvedChild = if (Test-Path $Child) {
        (Resolve-Path $Child).Path
    } else {
        [System.IO.Path]::GetFullPath($Child)
    }

    $parentPrefix = $resolvedParent + [System.IO.Path]::DirectorySeparatorChar
    if ($resolvedChild -ne $resolvedParent -and -not $resolvedChild.StartsWith($parentPrefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to operate outside release directory: $resolvedChild"
    }
}

function New-PortableReleaseZip {
    param(
        [string] $SourceDirectory,
        [string] $ZipPath,
        # Top-level names left out of this zip (the update package leaves out bin/).
        [string[]] $ExcludeTop = @()
    )

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $sourceRoot = [System.IO.Path]::GetFullPath($SourceDirectory).TrimEnd('\', '/')
    $zip = [System.IO.Compression.ZipFile]::Open($ZipPath, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($file in Get-ChildItem -LiteralPath $sourceRoot -Recurse -File -Force) {
            $entryName = $file.FullName.Substring($sourceRoot.Length + 1).Replace('\', '/')
            if ($ExcludeTop -contains ($entryName -split '/')[0]) {
                continue
            }
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $zip, $file.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Fastest
            ) | Out-Null
        }
        # Preserve empty runtime scaffolds using ZIP-standard slash separators.
        foreach ($directory in Get-ChildItem -LiteralPath $sourceRoot -Recurse -Directory -Force) {
            if (@(Get-ChildItem -LiteralPath $directory.FullName -Force).Count -eq 0) {
                $entryName = $directory.FullName.Substring($sourceRoot.Length + 1).Replace('\', '/') + '/'
                if ($ExcludeTop -contains ($entryName -split '/')[0]) {
                    continue
                }
                $zip.CreateEntry($entryName) | Out-Null
            }
        }
    } finally {
        $zip.Dispose()
    }
}

function Assert-CleanReleaseZip {
    param(
        [string] $ZipPath,
        # full: the package for new installs; update: the same without bin/ (the one-click updater refuses bin/).
        [ValidateSet('full', 'update')] [string] $Kind = 'full'
    )

    Add-Type -AssemblyName System.IO.Compression.FileSystem

    $zip = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
    try {
        $entries = @{}
        $forbidden = New-Object System.Collections.Generic.List[string]
        $binEntries = 0

        foreach ($entry in $zip.Entries) {
            if ($entry.FullName.Contains('\')) {
                throw "Non-portable raw ZIP entry separator: $($entry.FullName)"
            }
            $name = $entry.FullName.Trim('/')
            if ($name -eq '') {
                continue
            }

            $entries[$name] = $true
            if ($name -eq 'bin' -or $name.StartsWith('bin/')) {
                $binEntries++
                if ($Kind -eq 'update') {
                    $forbidden.Add($name)
                }
            }
            $isDirectoryEntry = $entry.Name -eq ''
            if ($isDirectoryEntry) {
                continue
            }

            $leaf = Split-Path $name -Leaf

            $isAllowedStorageKeep = $name -in @(
                'storage/app/.gitkeep',
                'storage/app/public/.gitkeep',
                'storage/framework/cache/data/.gitkeep',
                'storage/framework/sessions/.gitkeep',
                'storage/framework/views/.gitkeep',
                'storage/logs/.gitkeep',
                'bootstrap/cache/.gitkeep'
            )

            if (
                $name -eq '.env' -or
                $name -eq '.user.ini' -or
                $name -eq 'storage/app/installed.json' -or
                ($name.StartsWith('database/install/') -and $leaf -like '*.sql' -and $name -ne 'database/install/example.sql') -or
                ($name.StartsWith('database/seeders/') -and $name -notin @('database/seeders/DatabaseSeeder.php', 'database/seeders/CategorySeeder.php', 'database/seeders/PlanSeeder.php')) -or
                $name -eq 'CURRENT_STATE.md' -or
                $name -eq 'scratch_themes.json' -or
                $name -eq 'theme_test_report.json' -or
                $name.StartsWith('.agents/') -or
                $name.StartsWith('.tools/') -or
                $name.StartsWith('.playwright-mcp/') -or
                $name.StartsWith('.vscode/') -or
                $name.StartsWith('.idea/') -or
                $name.StartsWith('build/') -or
                $name.StartsWith('chat-export/') -or
                $name -like 'chat-export-*/*' -or
                $name.StartsWith('current_remote/') -or
                $name.StartsWith('incident_patch/') -or
                $name.StartsWith('node_modules/') -or
                $name.StartsWith('remote_domain_patch/') -or
                $name.StartsWith('remote_patch/') -or
                $name.StartsWith('.phpunit.cache/') -or
                $name.StartsWith('scratch/') -or
                $name.StartsWith('scripts/') -or
                $name.StartsWith('tests/') -or
                $name.StartsWith('ops/') -or
                $name.StartsWith('deploy/') -or
                $name -eq 'Makefile' -or
                $name -eq 'phpunit.xml' -or
                $name -eq 'phpstan.neon' -or
                $name -eq 'package.json' -or
                $name -eq 'package-lock.json' -or
                $name -eq 'bun.lockb' -or
                $name -eq 'tsconfig.json' -or
                $name -eq 'vite.config.ts' -or
                $name -eq '.editorconfig' -or
                $name -like '.r2-*' -or
                $name -eq 'public/hot' -or
                $name.StartsWith('public/images/tr/') -or
                $name.StartsWith('storage/app/installer-backups/') -or
                $name.StartsWith('storage/app/updates/') -or
                ($name.StartsWith('bootstrap/cache/') -and $leaf -like '*.php') -or
                $name.StartsWith('vendor/phpunit/') -or
                $name.StartsWith('vendor/mockery/') -or
                $name.StartsWith('vendor/larastan/') -or
                $name.StartsWith('vendor/phpstan/') -or
                $name.StartsWith('vendor/laravel/pint/') -or
                $name.StartsWith('vendor/barryvdh/laravel-ide-helper/') -or
                $name.StartsWith('vendor/fruitcake/laravel-debugbar/') -or
                $name.StartsWith('vendor/filp/whoops/') -or
                $name.StartsWith('vendor/sebastian/') -or
                $name.StartsWith('vendor/nunomaduro/collision/') -or
                ($name.StartsWith('storage/logs/') -and -not $isAllowedStorageKeep) -or
                ($name.StartsWith('storage/framework/') -and -not $isAllowedStorageKeep -and $leaf -ne '.gitignore') -or
                ($name.StartsWith('storage/') -and $leaf -ne '.gitkeep' -and $leaf -ne '.gitignore') -or
                $leaf -like '*.log' -or
                $leaf -like '*.tsv' -or
                $leaf -like '*.bak*' -or
                $leaf -like '*.py' -or
                $leaf -like 'admin-*.png' -or
                $leaf -like 'live_*.png' -or
                $leaf -like 'avdb-import-*' -or
                $leaf -like '*.zip.sha256' -or
                $leaf -match '\.avdbcms-\d+\.\d+\.\d+$'
            ) {
                $forbidden.Add($name)
            }
        }

        foreach ($required in @(
            'README.md',
            'COMMUNITY_SETUP_GUIDE.md',
            '.env.example',
            'index.php',
            '.htaccess',
            'public/index.php',
            'public/.htaccess',
            'public/build/manifest.json',
            'vendor/autoload.php',
            'artisan',
            'database/install/example.sql',
            'database/seeders/DatabaseSeeder.php',
            'app/Support/ResilientPackageManifest.php',
            'bootstrap/app.php',
            'app/Support/AppVersion.php',
            'avdbcms-manifest.json'
        )) {
            if (-not $entries.ContainsKey($required)) {
                throw "Release package is missing required file: $required"
            }
        }

        if ($forbidden.Count -gt 0) {
            $sample = ($forbidden | Select-Object -First 30) -join [Environment]::NewLine
            throw "Release package contains forbidden runtime files:$([Environment]::NewLine)$sample"
        }

        if ($Kind -eq 'full' -and $binEntries -eq 0) {
            Write-Warning 'The full package has no bin/ folder (the bundled FFmpeg builds): sites installed from it can only use an FFmpeg of their host.'
        }
    } finally {
        if ($null -ne $zip) {
            $zip.Dispose()
        }
        [System.GC]::Collect()
        [System.GC]::WaitForPendingFinalizers()
    }
}

function Find-Php {
    if ($PhpPath -ne '') {
        if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
            throw "PHP not found at -PhpPath $PhpPath"
        }

        return (Resolve-Path -LiteralPath $PhpPath).Path
    }

    $phpCandidates = @(
        'D:\OSPanel\modules\php\PHP_8.3\php.exe',
        'D:\OSPanel\modules\php\PHP_8.4\php.exe',
        'C:\OSPanel\modules\php\PHP_8.4\php.exe'
    )
    $found = $phpCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
    if ($found) {
        return $found
    }

    $onPath = Get-Command php -ErrorAction SilentlyContinue
    if ($null -ne $onPath) {
        return $onPath.Source
    }

    throw 'PHP 8.3 not found: cannot run the translation check and the release manifest (pass -PhpPath)'
}

function Invoke-ReleaseTool {
    param([string[]] $Arguments)

    # The tool reports problems on stderr. Under 'Stop', PowerShell 5.1 would end the build at the first such line
    # (as with composer below), so the exit code decides, and every line is shown.
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $lines = @(& $script:phpExe -d opcache.enable_cli=0 $script:releaseTool @Arguments 2>&1)
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }

    $stdout = New-Object System.Collections.Generic.List[string]
    foreach ($line in $lines) {
        if ($line -is [System.Management.Automation.ErrorRecord]) {
            Write-Host ('  ' + $line.ToString())
            continue
        }
        $text = [string] $line
        $stdout.Add($text)
        if ($text.StartsWith('WARNING: ')) {
            Write-Warning $text.Substring(9)
        } else {
            Write-Host ('  ' + $text)
        }
    }
    if ($code -ne 0) {
        throw "scripts/release-manifest.php $($Arguments[0]) failed (exit $code)"
    }

    return $stdout.ToArray()
}

function Get-ReleaseVersion {
    param([string] $Tree)

    # AppVersion::CURRENT of the tree, and the -Tag check (exactly "v" + CURRENT).
    $toolArgs = @('version', "--stage=$Tree")
    if ($Tag -ne '') {
        $toolArgs += "--tag=$Tag"
    }
    $version = @(Invoke-ReleaseTool $toolArgs) | Select-Object -Last 1
    if ($version -notmatch '^\d+\.\d+\.\d+$') {
        throw "scripts/release-manifest.php version answered '$version', which is not a version"
    }

    return $version
}

function Write-Sha256File {
    param([string] $ZipPath)

    $name = Split-Path $ZipPath -Leaf
    $hash = (Get-FileHash -LiteralPath $ZipPath -Algorithm SHA256).Hash.ToLowerInvariant()
    # sha256sum text format, like the JavCMS release assets: "<64 hex>  <name>" and a line feed (ASCII, no BOM, no CR).
    [System.IO.File]::WriteAllText($ZipPath + '.sha256', "$hash  $name`n", (New-Object System.Text.UTF8Encoding($false)))

    return $hash
}

function Test-PreviousUpdater {
    param(
        [string] $UpdateZip,
        [string] $OutFolder
    )

    # Sites unpack an update with the updater of the version they run, not the new one. When the previous release's
    # zip is given, its own updater classes must take the new update zip (a new top-level folder, for one, has to be
    # allowed by a release in between first).
    if ($PreviousManifest -eq '' -or -not $PreviousManifest.EndsWith('.zip', [StringComparison]::OrdinalIgnoreCase)) {
        if ($PreviousManifest -ne '') {
            Write-Host '  (give the previous release''s zip as -PreviousManifest to also check that its updater takes this update)'
        }
        return
    }

    $codeDir = Join-Path $OutFolder ('avdbcms-release-' + [Guid]::NewGuid().ToString('N') + '-previous')
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::OpenRead((Resolve-FullPath $PreviousManifest))
    try {
        try {
            foreach ($entry in $zip.Entries) {
                if ($entry.FullName -cmatch '^app/Support/(AppVersion\.php|Updater/[A-Za-z0-9_]+(/[A-Za-z0-9_]+)*\.php)$') {
                    $target = Join-Path $codeDir ($entry.FullName -replace '/', '\')
                    New-Item -ItemType Directory -Path (Split-Path $target -Parent) -Force | Out-Null
                    [System.IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $target, $true)
                }
            }
        } finally {
            $zip.Dispose()
        }
        Invoke-ReleaseTool @('accepts', "--stage=$codeDir", "--zip=$UpdateZip") | Out-Null
    } finally {
        if (Test-Path -LiteralPath $codeDir) {
            Remove-Item -LiteralPath $codeDir -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}

function Assert-StagingFolder {
    param([string] $Folder)

    if (-not (Test-Path -LiteralPath $Folder -PathType Container)) {
        throw "-FromStaging: no such folder: $Folder"
    }
    if ($Folder -ieq $root.TrimEnd('\', '/')) {
        throw "-FromStaging must name a staging folder, not the project itself: $Folder"
    }
    # A staging folder never has these (the copy rules leave them out); a project or the workspace does.
    foreach ($marker in @('.git', '.env', 'node_modules', 'tests', 'scripts', 'vendor/phpunit')) {
        if (Test-Path -LiteralPath (Join-Path $Folder $marker)) {
            throw "-FromStaging: $Folder has $marker, so it is not a staging folder (use one a build left with -KeepStaging)"
        }
    }
}

function Invoke-ReleasePackaging {
    param(
        [string] $Stage,
        [string] $OutFolder,
        [string] $ExpectedVersion = ''
    )

    $version = Get-ReleaseVersion -Tree $Stage
    if ($ExpectedVersion -ne '' -and $version -ne $ExpectedVersion) {
        throw "The staged code is AVDBCMS $version, the project is $ExpectedVersion"
    }
    $fullName = "avdbcms-$version.zip"
    $updateName = "avdbcms-update-$version.zip"

    $stageFull = [System.IO.Path]::GetFullPath($Stage).TrimEnd('\', '/')
    if ($OutFolder -ieq $stageFull -or $OutFolder.StartsWith($stageFull + '\', [StringComparison]::OrdinalIgnoreCase)) {
        throw "The output folder must be outside the staging folder: $OutFolder"
    }
    New-Item -ItemType Directory -Path $OutFolder -Force | Out-Null

    # Clean up any leftover temporary release zip files in the output folder
    Get-ChildItem -Path $OutFolder -Filter 'avdbcms-release-*.zip' -File -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue
    Get-ChildItem -Path $OutFolder -Filter 'avdbcms-release-*-previous' -Directory -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

    # A build that stops half-way must not leave assets of this version behind that look complete.
    $assets = @($fullName, "$fullName.sha256", $updateName, "$updateName.sha256") | ForEach-Object { Join-Path $OutFolder $_ }
    foreach ($asset in $assets) {
        if (Test-Path -LiteralPath $asset) {
            try {
                Remove-Item -LiteralPath $asset -Force -ErrorAction Stop
            } catch {
                Start-Sleep -Seconds 1
                Remove-Item -LiteralPath $asset -Force -ErrorAction Stop
            }
        }
    }

    $manifestArgs = @('manifest', "--stage=$Stage", "--requires-php=$RequiresPhp", "--min-from=$MinFrom")
    if ($PreviousManifest -ne '') {
        $manifestArgs += "--previous=$(Resolve-FullPath $PreviousManifest)"
    }
    Invoke-ReleaseTool $manifestArgs | Out-Null

    $guid = [Guid]::NewGuid().ToString('N')
    $tempFull = Join-Path $OutFolder "avdbcms-release-$guid-full.zip"
    $tempUpdate = Join-Path $OutFolder "avdbcms-release-$guid-update.zip"
    $done = $false
    try {
        New-PortableReleaseZip -SourceDirectory $Stage -ZipPath $tempFull
        Assert-CleanReleaseZip -ZipPath $tempFull -Kind 'full'
        # The update package: the same staging folder without bin/ (a site keeps the FFmpeg builds it has).
        New-PortableReleaseZip -SourceDirectory $Stage -ZipPath $tempUpdate -ExcludeTop @('bin')
        Assert-CleanReleaseZip -ZipPath $tempUpdate -Kind 'update'
        Invoke-ReleaseTool @('verify', "--stage=$Stage", "--zip=$tempFull", '--kind=full', '--engine=all') | Out-Null
        Invoke-ReleaseTool @('verify', "--stage=$Stage", "--zip=$tempUpdate", '--kind=update', '--engine=all') | Out-Null
        Test-PreviousUpdater -UpdateZip $tempUpdate -OutFolder $OutFolder

        Move-Item -LiteralPath $tempFull -Destination (Join-Path $OutFolder $fullName) -Force
        Move-Item -LiteralPath $tempUpdate -Destination (Join-Path $OutFolder $updateName) -Force
        foreach ($name in @($fullName, $updateName)) {
            $zipPath = Join-Path $OutFolder $name
            Write-Sha256File -ZipPath $zipPath | Out-Null
            Invoke-ReleaseTool @('sha256', "--stage=$Stage", "--zip=$zipPath") | Out-Null
        }
        $done = $true
    } finally {
        # If the build failed or was interrupted, delete the temporary zips (and any asset of this version) at once.
        foreach ($temp in @($tempFull, $tempUpdate)) {
            if (Test-Path -LiteralPath $temp) {
                Remove-Item -LiteralPath $temp -Force -ErrorAction SilentlyContinue
            }
        }
        if (-not $done) {
            foreach ($asset in $assets) {
                if (Test-Path -LiteralPath $asset) {
                    Remove-Item -LiteralPath $asset -Force -ErrorAction SilentlyContinue
                }
            }
        }
    }

    foreach ($name in @($fullName, $updateName)) {
        $zipPath = Join-Path $OutFolder $name
        $label = if ($name -eq $fullName) { 'Release package created' } else { 'Update package created' }
        Write-Host ('{0}: {1} ({2} bytes)' -f $label, $zipPath, (Get-Item -LiteralPath $zipPath).Length)
        Write-Host ('  {0}.sha256: {1}' -f $name, ([System.IO.File]::ReadAllText($zipPath + '.sha256')).TrimEnd("`n"))
    }
}

$phpExe = Find-Php
if (-not (Test-Path -LiteralPath $releaseTool -PathType Leaf)) {
    throw "Missing $releaseTool (the release manifest tool next to this script)"
}

if ($FromStaging -ne '') {
    $fromStage = Resolve-FullPath $FromStaging
    Assert-StagingFolder -Folder $fromStage
    Invoke-ReleasePackaging -Stage $fromStage -OutFolder $outDirFull
    return
}

# The version of the code and the -Tag check come first: a wrong tag changes nothing.
$version = Get-ReleaseVersion -Tree $root

New-Item -ItemType Directory -Path $stageRoot -Force | Out-Null
Assert-PathInside -Child $stage -Parent $stageRoot

if (Test-Path $stage) {
    Remove-Item -LiteralPath $stage -Recurse -Force
}

New-Item -ItemType Directory -Path $stage -Force | Out-Null
Copy-ReleaseTree -Source $root -Destination $stage

$composer = Get-Command composer -ErrorAction SilentlyContinue
& $phpExe -d opcache.enable_cli=0 (Join-Path $root 'scripts/i18n_check.php') "--root=$root" --min-coverage=95
if ($LASTEXITCODE -ne 0) { throw "Translation check failed (scripts/i18n_check.php exit $LASTEXITCODE)" }
$composerPhar = 'C:\ProgramData\ComposerSetup\bin\composer.phar'
$cliIni = Join-Path $root 'php-cli.ini'

if ($null -eq $composer -and -not (Test-Path $composerPhar)) {
    throw 'Composer is required to remove development dependencies from the community release.'
}

# The workspace vendor tree may contain development packages or a stale
# autoloader from tests. Always rebuild dependencies inside the staging tree so
# the archive is reproducible and production-only.
$stageVendor = Join-Path $stage 'vendor'
if (Test-Path $stageVendor) {
    Assert-PathInside -Child $stageVendor -Parent $stage
    Remove-Item -LiteralPath $stageVendor -Recurse -Force
}
New-Item -ItemType Directory -Path $stageVendor -Force | Out-Null

Push-Location $stage
try {
    # Composer writes progress to stderr; under 'Stop' PowerShell 5.1 turns that into a terminating error.
    # The explicit $LASTEXITCODE checks below still catch real composer failures.
    $ErrorActionPreference = 'Continue'

    if ($phpExe -and (Test-Path $composerPhar)) {
        $iniArgs = if (Test-Path $cliIni) { @('-c', $cliIni) } else { @() }
        & $phpExe @iniArgs -d xdebug.mode=off -d memory_limit=1024M $composerPhar install `
            --no-dev `
            --no-interaction `
            --no-progress `
            --no-scripts `
            --no-plugins `
            --no-autoloader `
            --ignore-platform-reqs
    } else {
        & $composer.Source install `
            --no-dev `
            --no-interaction `
            --no-progress `
            --no-scripts `
            --no-plugins `
            --no-autoloader `
            --ignore-platform-reqs
    }

    if ($LASTEXITCODE -ne 0) {
        throw "Composer failed while preparing production dependencies (exit code $LASTEXITCODE)."
    }

    # The source project enables optimized autoloading for development and
    # deployment workflows. A lightweight PSR-4 autoloader is more reliable in
    # this Windows packaging environment; only the staging copy is changed.
    if ($phpExe -and (Test-Path $composerPhar)) {
        & $phpExe @iniArgs -d xdebug.mode=off -d memory_limit=1024M $composerPhar config optimize-autoloader false --no-interaction --no-scripts --no-plugins
    } else {
        & $composer.Source config optimize-autoloader false --no-interaction --no-scripts --no-plugins
    }

    if ($LASTEXITCODE -ne 0) {
        throw "Composer failed while configuring the staging autoloader (exit code $LASTEXITCODE)."
    }

    if ($phpExe -and (Test-Path $composerPhar)) {
        & $phpExe @iniArgs -d xdebug.mode=off -d memory_limit=1024M $composerPhar dump-autoload --no-dev --no-interaction --no-scripts --no-plugins
    } else {
        & $composer.Source dump-autoload --no-dev --no-interaction --no-scripts --no-plugins
    }

    if ($LASTEXITCODE -ne 0) {
        throw "Composer failed while generating the staging autoloader (exit code $LASTEXITCODE)."
    }

    # Keep the distributable source metadata identical to the repository after
    # the staging-only Composer setting change above.
    Copy-Item -LiteralPath (Join-Path $root 'composer.json') -Destination (Join-Path $stage 'composer.json') -Force
} finally {
    Pop-Location
    $ErrorActionPreference = 'Stop'
}

foreach ($dir in @(
    'storage/app',
    'storage/app/public',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
    'bootstrap/cache'
)) {
    $target = Join-Path $stage $dir
    New-Item -ItemType Directory -Path $target -Force | Out-Null
    $keep = Join-Path $target '.gitkeep'
    if (-not (Test-Path $keep)) {
        Set-Content -LiteralPath $keep -Value '' -NoNewline
    }
}

# Ensure no forbidden runtime files exist in the staging directory before zipping
foreach ($dirName in @('scripts', 'tests', 'ops', 'deploy', '.agents', '.tools', '.playwright-mcp', '.vscode', '.idea', 'scratch', 'build', 'chat-export', '.tmp-npm-cache', '.tmp-playwright', '.tmp-playwright-browsers')) {
    $dirPath = Join-Path $stage $dirName
    if (Test-Path $dirPath) {
        Remove-Item -LiteralPath $dirPath -Recurse -Force -ErrorAction SilentlyContinue
    }
}

# Also remove dated/private conversation export directories defensively if a
# future copy rule changes the top-level allowlist.
Get-ChildItem -LiteralPath $stage -Directory -Force -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -like 'chat-export-*' } |
    Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

Get-ChildItem -Path $stage -Recurse -File -Force -ErrorAction SilentlyContinue | Where-Object {
    $leaf = $_.Name
    $leaf -like '*.py' -or
    $leaf -like '*.bak*' -or
    $leaf -like '*.log' -or
    $leaf -like '*.tsv' -or
    $leaf -like 'admin-*.png' -or
    $leaf -like 'live_*.png' -or
    $leaf -like 'avdb-import-*' -or
    $leaf -eq 'CURRENT_STATE.md' -or
    $leaf -eq 'scratch_themes.json' -or
    $leaf -eq 'theme_test_report.json' -or
    $leaf -eq 'Makefile' -or
    $leaf -eq 'phpunit.xml' -or
    $leaf -eq 'phpstan.neon' -or
    $leaf -eq 'package.json' -or
    $leaf -eq 'package-lock.json' -or
    $leaf -eq 'bun.lockb' -or
    $leaf -eq 'tsconfig.json' -or
    $leaf -eq 'vite.config.ts' -or
    $leaf -eq '.editorconfig' -or
    $leaf -eq '.user.ini' -or
    ($leaf -like '.env*' -and $leaf -ne '.env.example')
} | Remove-Item -Force -ErrorAction SilentlyContinue

try {
    Invoke-ReleasePackaging -Stage $stage -OutFolder $outDirFull -ExpectedVersion $version
} finally {
    # Always clean up the staging folder unless explicitly requested to keep it
    if ((-not $KeepStaging) -and (Test-Path $stage)) {
        Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue
    }
}

if ($KeepStaging) {
    Write-Host "Staging folder kept: $stage"
}
