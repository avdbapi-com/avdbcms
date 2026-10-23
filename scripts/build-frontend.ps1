param(
    [string] $NodeVersion = "22.12.0"
)

$ErrorActionPreference = "Stop"

$RootDir = Resolve-Path (Join-Path $PSScriptRoot "..")
$ToolsDir = Join-Path $RootDir ".tools"
$NodeRoot = Join-Path $ToolsDir "node"
$DownloadDir = Join-Path $ToolsDir "downloads"

function Test-NodeCompatible([string] $NodePath) {
    if (-not (Test-Path $NodePath)) {
        return $false
    }

    $version = & $NodePath -p "process.versions.node" 2> $null
    if ($LASTEXITCODE -ne 0 -or -not $version) {
        return $false
    }

    $parts = "$version".Trim().Split(".")
    $major = [int] $parts[0]
    $minor = [int] $parts[1]

    return ($major -eq 20 -and $minor -ge 19) -or ($major -eq 22 -and $minor -ge 12) -or ($major -gt 22)
}

function Get-SystemNode {
    $cmd = Get-Command node -ErrorAction SilentlyContinue
    if ($cmd -and (Test-NodeCompatible $cmd.Source)) {
        return $cmd.Source
    }

    return $null
}

function Get-PortableNode {
    if (-not [Environment]::Is64BitOperatingSystem) {
        throw "Portable Node.js requires a 64-bit Windows install."
    }

    $dist = "node-v$NodeVersion-win-x64"
    $nodeDir = Join-Path $NodeRoot $dist
    $nodeExe = Join-Path $nodeDir "node.exe"

    if (Test-NodeCompatible $nodeExe) {
        return $nodeExe
    }

    New-Item -ItemType Directory -Force $NodeRoot, $DownloadDir | Out-Null
    $archive = Join-Path $DownloadDir "$dist.zip"
    $url = "https://nodejs.org/dist/v$NodeVersion/$dist.zip"

    Write-Host "Downloading Node.js $NodeVersion for win-x64..."
    Invoke-WebRequest -Uri $url -OutFile $archive

    if (Test-Path $nodeDir) {
        Remove-Item -LiteralPath $nodeDir -Recurse -Force
    }

    Expand-Archive -LiteralPath $archive -DestinationPath $NodeRoot -Force
    Remove-Item -LiteralPath $archive -Force

    if (-not (Test-NodeCompatible $nodeExe)) {
        throw "Downloaded Node.js is not compatible with Vite."
    }

    return $nodeExe
}

$node = Get-SystemNode
if (-not $node) {
    $node = Get-PortableNode
}

$nodeBin = Split-Path $node -Parent
$npm = Join-Path $nodeBin "npm.cmd"
if (-not (Test-Path $npm)) {
    throw "npm was not found next to $node."
}

Set-Location $RootDir
$env:PATH = "$nodeBin;$env:PATH"

$viteBin = Join-Path $RootDir "node_modules\.bin\vite.cmd"
if (-not (Test-Path (Join-Path $RootDir "node_modules")) -or -not (Test-Path $viteBin)) {
    if (Test-Path (Join-Path $RootDir "package-lock.json")) {
        & $npm ci
    } else {
        & $npm install
    }
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

& $npm run build:vite
exit $LASTEXITCODE
