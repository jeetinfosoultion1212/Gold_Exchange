$phpDir = "D:\Project\Gold_Exchange\electron-desktop\server\php"
$phpIni = "$phpDir\php.ini"

Write-Host "Configuring PHP..." -ForegroundColor Green

# Read php.ini
$content = Get-Content $phpIni

# Set extension directory with absolute path
$extDir = "$phpDir\ext"
$content = $content -replace '; extension_dir = "ext"', "extension_dir = `"$extDir`""
$content = $content -replace ';extension_dir = "ext"', "extension_dir = `"$extDir`""

# Enable extensions
$content = $content -replace ';extension=curl', 'extension=curl'
$content = $content -replace ';extension=fileinfo', 'extension=fileinfo'
$content = $content -replace ';extension=gd', 'extension=gd'
$content = $content -replace ';extension=mbstring', 'extension=mbstring'
$content = $content -replace ';extension=mysqli', 'extension=mysqli'
$content = $content -replace ';extension=openssl', 'extension=openssl'
$content = $content -replace ';extension=pdo_mysql', 'extension=pdo_mysql'

# Save php.ini
$content | Set-Content $phpIni

Write-Host "PHP configuration updated!" -ForegroundColor Green
Write-Host ""
Write-Host "Testing extensions..." -ForegroundColor Yellow

# Test extensions
& "$phpDir\php.exe" -r "echo 'mysqli: ' . (extension_loaded('mysqli') ? 'OK' : 'MISSING') . PHP_EOL; echo 'pdo_mysql: ' . (extension_loaded('pdo_mysql') ? 'OK' : 'MISSING') . PHP_EOL; echo 'mbstring: ' . (extension_loaded('mbstring') ? 'OK' : 'MISSING') . PHP_EOL; echo 'openssl: ' . (extension_loaded('openssl') ? 'OK' : 'MISSING') . PHP_EOL;"

Write-Host ""
Write-Host "Configuration complete!" -ForegroundColor Green
