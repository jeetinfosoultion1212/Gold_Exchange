# Builds a portable offline desktop package for customer distribution.
# Requires XAMPP (or set -XamppPath) for PHP + MySQL binaries.

param(
    [string]$XamppPath = "C:\xampp",
    [string]$OutputName = "GoldExchangePortable",
    [switch]$SkipZip
)

$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $PSScriptRoot
$OutRoot = Join-Path $ProjectRoot "desktop_package"
$OutDir = Join-Path $OutRoot $OutputName
$Templates = Join-Path $PSScriptRoot "templates"

function Write-Step($msg) {
    Write-Host "`n==> $msg" -ForegroundColor Cyan
}

function Test-Xampp {
    param([string]$Base)
    $php = Join-Path $Base "php\php.exe"
    $mysql = Join-Path $Base "mysql\bin\mysqld.exe"
    if (-not (Test-Path $php)) { throw "PHP not found at $php" }
    if (-not (Test-Path $mysql)) { throw "MySQL not found at $mysql" }
}

function Ensure-SumatraPdf {
    param([string]$DestDir)
    $exe = Join-Path $DestDir "SumatraPDF.exe"
    if (Test-Path $exe) { return $exe }

    $toolsDir = Join-Path $PSScriptRoot "tools"
    $toolsExe = Join-Path $toolsDir "SumatraPDF.exe"
    New-Item -ItemType Directory -Path $DestDir -Force | Out-Null

    if (Test-Path $toolsExe) {
        Copy-Item $toolsExe $exe -Force
        return $exe
    }

    $zipPath = Join-Path $toolsDir "SumatraPDF.zip"
    $url = "https://www.sumatrapdfreader.org/dl/rel/3.5.2/SumatraPDF-3.5.2-64.zip"
    New-Item -ItemType Directory -Path $toolsDir -Force | Out-Null
    Write-Host "   Downloading SumatraPDF (silent printing)..." -ForegroundColor Yellow
    Invoke-WebRequest -Uri $url -OutFile $zipPath -UseBasicParsing
    Expand-Archive -Path $zipPath -DestinationPath $toolsDir -Force
    $found = Get-ChildItem -Path $toolsDir -Filter "SumatraPDF*.exe" -Recurse | Select-Object -First 1
    if ($found) {
        Copy-Item $found.FullName $toolsExe -Force
    }
    if (-not (Test-Path $toolsExe)) {
        throw "SumatraPDF download failed. Place SumatraPDF.exe in desktop-build/tools/"
    }
    Copy-Item $toolsExe $exe -Force
    return $exe
}

Write-Step "Checking XAMPP at $XamppPath"
Test-Xampp -Base $XamppPath

Write-Step "Preparing output folder: $OutDir"
if (Test-Path $OutDir) {
    Remove-Item $OutDir -Recurse -Force
}
New-Item -ItemType Directory -Path $OutDir -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $OutDir "app") -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $OutDir "runtime\php") -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $OutDir "runtime\mysql") -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $OutDir "data") -Force | Out-Null

Write-Step "Bundling SumatraPDF (silent default-printer output)"
$null = Ensure-SumatraPdf -DestDir (Join-Path $OutDir "runtime\SumatraPDF")

Write-Step "Copying application files"
$excludeDirs = @(
    ".git", ".cursor", ".vscode", "node_modules", "backups", "desktop_package",
    "desktop-build", "electron-desktop", "dist", "win-unpacked"
)
$excludeFiles = @("*.zip", "*.rar", "*.log", "_test_repro.html", "_repro_shot.png", "_crop_right.png")

Get-ChildItem -Path $ProjectRoot -Force | Where-Object {
    $name = $_.Name
    if ($excludeDirs -contains $name) { return $false }
    if ($name -like "*.zip" -or $name -like "*.rar") { return $false }
    return $true
} | ForEach-Object {
    Copy-Item -Path $_.FullName -Destination (Join-Path $OutDir "app\$($_.Name)") -Recurse -Force
}

Write-Step "Copying PHP runtime"
Copy-Item -Path (Join-Path $XamppPath "php\*") -Destination (Join-Path $OutDir "runtime\php") -Recurse -Force

Write-Step "Copying MySQL runtime"
$mysqlSrc = Join-Path $XamppPath "mysql"
$mysqlDst = Join-Path $OutDir "runtime\mysql"
Copy-Item -Path (Join-Path $mysqlSrc "bin") -Destination (Join-Path $mysqlDst "bin") -Recurse -Force
if (Test-Path (Join-Path $mysqlSrc "share")) {
    Copy-Item -Path (Join-Path $mysqlSrc "share") -Destination (Join-Path $mysqlDst "share") -Recurse -Force
}
if (Test-Path (Join-Path $mysqlSrc "lib")) {
    Copy-Item -Path (Join-Path $mysqlSrc "lib") -Destination (Join-Path $mysqlDst "lib") -Recurse -Force
}

Write-Step "Adding launcher scripts"
Copy-Item -Path (Join-Path $Templates "Start Gold Exchange.bat") -Destination $OutDir -Force
Copy-Item -Path (Join-Path $Templates "Stop Gold Exchange.bat") -Destination $OutDir -Force
Copy-Item -Path (Join-Path $Templates "OpenAppWindow.bat") -Destination $OutDir -Force
Copy-Item -Path (Join-Path $Templates "README.txt") -Destination $OutDir -Force

$zipPath = Join-Path $OutRoot "$OutputName.zip"
if (-not $SkipZip) {
    Write-Step "Creating ZIP: $zipPath"
    if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
    Compress-Archive -Path $OutDir -DestinationPath $zipPath -CompressionLevel Optimal
}

Write-Host ""
Write-Host "BUILD COMPLETE" -ForegroundColor Green
Write-Host "  Folder: $OutDir"
if (-not $SkipZip) { Write-Host "  ZIP:    $zipPath" }
Write-Host ""
Write-Host "Next steps:"
Write-Host "  1. Test: open $OutDir and run 'Start Gold Exchange.bat'"
Write-Host "  2. Register a company on first launch"
Write-Host "  3. Give customers the ZIP file"
Write-Host ""
