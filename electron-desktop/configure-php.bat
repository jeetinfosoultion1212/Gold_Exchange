@echo off
echo ========================================
echo  PHP Configuration Script
echo ========================================
echo.

cd /d "d:\Project\Gold_Exchange\electron-desktop\server\php"

echo Enabling PHP extensions...
echo.

REM Enable extension directory
powershell -Command "$content = Get-Content php.ini; $content = $content -replace '; extension_dir = \"ext\"', 'extension_dir = \"ext\"'; Set-Content php.ini $content"

REM Enable required extensions
powershell -Command "$content = Get-Content php.ini; $content = $content -replace ';extension=curl$', 'extension=curl'; $content = $content -replace ';extension=fileinfo$', 'extension=fileinfo'; $content = $content -replace ';extension=gd$', 'extension=gd'; $content = $content -replace ';extension=mbstring$', 'extension=mbstring'; $content = $content -replace ';extension=mysqli$', 'extension=mysqli'; $content = $content -replace ';extension=openssl$', 'extension=openssl'; $content = $content -replace ';extension=pdo_mysql$', 'extension=pdo_mysql'; Set-Content php.ini $content"

echo.
echo ========================================
echo  Testing PHP Extensions...
echo ========================================
echo.

php.exe -r "echo 'mysqli: ' . (extension_loaded('mysqli') ? 'OK' : 'MISSING') . PHP_EOL; echo 'pdo_mysql: ' . (extension_loaded('pdo_mysql') ? 'OK' : 'MISSING') . PHP_EOL; echo 'mbstring: ' . (extension_loaded('mbstring') ? 'OK' : 'MISSING') . PHP_EOL; echo 'openssl: ' . (extension_loaded('openssl') ? 'OK' : 'MISSING') . PHP_EOL; echo 'curl: ' . (extension_loaded('curl') ? 'OK' : 'MISSING') . PHP_EOL;"

echo.
echo ========================================
echo  Configuration Complete!
echo ========================================
echo.
pause
