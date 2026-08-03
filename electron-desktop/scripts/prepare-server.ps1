# Copies PHP app + XAMPP runtime into electron-desktop/server for packaging.

param(
    [string]$XamppPath = "C:\xampp",
    [string]$ProjectRoot = (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
)

$ErrorActionPreference = "Stop"
$ServerDir = Join-Path (Split-Path -Parent $PSScriptRoot) "server"

function Write-Step($msg) {
    Write-Host "`n==> $msg" -ForegroundColor Cyan
}

Write-Step "Preparing Electron server bundle at $ServerDir"
if (Test-Path $ServerDir) {
    Remove-Item $ServerDir -Recurse -Force
}
New-Item -ItemType Directory -Path (Join-Path $ServerDir "www") -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $ServerDir "php") -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $ServerDir "mysql") -Force | Out-Null

$excludeDirs = @(
    ".git", ".cursor", ".vscode", "node_modules", "backups", "desktop_package",
    "desktop-build", "electron-desktop", "dist", "win-unpacked"
)

Write-Step "Copying PHP application"
Get-ChildItem -Path $ProjectRoot -Force | Where-Object {
    $name = $_.Name
    if ($excludeDirs -contains $name) { return $false }
    if ($name -like "*.zip" -or $name -like "*.rar") { return $false }
    return $true
} | ForEach-Object {
    Copy-Item -Path $_.FullName -Destination (Join-Path $ServerDir "www\$($_.Name)") -Recurse -Force
}

Write-Step "Copying PHP from XAMPP"
Copy-Item -Path (Join-Path $XamppPath "php\*") -Destination (Join-Path $ServerDir "php") -Recurse -Force

Write-Step "Copying MySQL from XAMPP"
$mysqlSrc = Join-Path $XamppPath "mysql"
$mysqlDst = Join-Path $ServerDir "mysql"
Copy-Item -Path (Join-Path $mysqlSrc "bin") -Destination (Join-Path $mysqlDst "bin") -Recurse -Force
foreach ($dir in @("share", "lib")) {
    $src = Join-Path $mysqlSrc $dir
    if (Test-Path $src) {
        Copy-Item -Path $src -Destination (Join-Path $mysqlDst $dir) -Recurse -Force
    }
}

Write-Step "Bundling SumatraPDF (fallback silent print)"
$sumatraTools = Join-Path (Split-Path -Parent $PSScriptRoot) "..\desktop-build\tools\SumatraPDF.exe"
$sumatraPkg = Join-Path $ServerDir "SumatraPDF\SumatraPDF.exe"
New-Item -ItemType Directory -Path (Split-Path $sumatraPkg) -Force | Out-Null
if (Test-Path $sumatraTools) {
    Copy-Item $sumatraTools $sumatraPkg -Force
} else {
    Write-Host "   SumatraPDF not cached; Electron will use built-in silent print." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Electron server bundle ready." -ForegroundColor Green
