<#
.SYNOPSIS
Prepares the Gold Exchange application for deployment to a live server.
#>

$ErrorActionPreference = "Stop"
$xamppPath = "C:\xampp\mysql\bin\mysqldump.exe"
$dbName = "gold_exchange"
$dbUser = "root"
$outputSql = "latest_db_dump.sql"
$zipName = "deployment_package.zip"

Write-Host "Starting Deployment Preparation..." -ForegroundColor Cyan

# 1. Export Database
Write-Host "Step 1: Exporting Database..." -ForegroundColor Yellow
if (Test-Path $xamppPath) {
    try {
        & $xamppPath --user=$dbUser --host=127.0.0.1 --port=3306 $dbName --result-file=$outputSql
        if ($LASTEXITCODE -eq 0) {
           Write-Host "Database exported successfully to $outputSql" -ForegroundColor Green
        } else {
             Write-Warning "First attempt failed. Trying Port 3307..."
             & $xamppPath --user=$dbUser --host=127.0.0.1 --port=3307 $dbName --result-file=$outputSql
        }
    } catch {
        Write-Warning "Could not auto-export database. Please export 'gold_exchange' manually using phpMyAdmin."
    }
} else {
    Write-Warning "mysqldump.exe not found. Please export database manually via phpMyAdmin."
}

# 2. Create Zip
Write-Host "Step 2: Creating Deployment Zip..." -ForegroundColor Yellow

$exclude = @(
    "*.git*",
    "*.vscode*",
    "*node_modules*",
    "*electron-desktop*",
    "*.zip",
    "*.rar",
    "*tmp*",
    "*.log",
    "*test*",
    "*backups*",
    "*.agent*"
)

# Get files to zip
$files = Get-ChildItem -Path . -Exclude $exclude | Where-Object { $_.Name -ne $zipName }

if (Test-Path $zipName) { Remove-Item $zipName -Force }

try {
    Write-Host "   Zipping files... this may take a moment."
    Compress-Archive -Path $files.FullName -DestinationPath $zipName -CompressionLevel Optimal
    Write-Host "Zip created: $zipName" -ForegroundColor Green
} catch {
    Write-Error "Failed to create zip file. $_"
}

# 3. Summary
Write-Host "Deployment Package Ready!" -ForegroundColor Cyan
Write-Host "---------------------------------------------------"
Write-Host "1. $zipName contains your website files."
Write-Host "2. $outputSql contains your database structure and data."
Write-Host "   (Note: If SQL export failed, export manually)."
Write-Host ""
Write-Host "NEXT STEP: Read DEPLOYMENT_GUIDE.md"
Write-Host "---------------------------------------------------"
