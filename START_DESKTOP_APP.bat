@echo off
cd /d "%~dp0"
set "PHPDESKTOP_VERSION=1.0"
set "GOLD_EXCHANGE_DESKTOP=1"
set "GOLD_EXCHANGE_DB_PORT=3306"

where C:\xampp\php\php.exe >nul 2>&1
if errorlevel 1 (
    echo XAMPP PHP not found at C:\xampp\php\php.exe
    pause
    exit /b 1
)

netstat -ano | findstr /R /C:":8080 .*LISTENING" >nul
if errorlevel 1 (
    echo Starting PHP server on http://127.0.0.1:8080 ...
    start "" /B C:\xampp\php\php.exe -S 127.0.0.1:8080 -t "%CD%"
    timeout /t 2 /nobreak >nul
)

call "%~dp0desktop-build\templates\OpenAppWindow.bat" "http://127.0.0.1:8080/login.php"
