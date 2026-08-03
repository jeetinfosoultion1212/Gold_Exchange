@echo off
setlocal EnableExtensions
cd /d "%~dp0"

set "MYSQL_ADMIN=%CD%\runtime\mysql\bin\mysqladmin.exe"

echo Stopping Gold Exchange services...

for /f "tokens=5" %%a in ('netstat -ano ^| findstr /R /C:":8080 .*LISTENING"') do (
    taskkill /PID %%a /F >nul 2>&1
)

if exist "%MYSQL_ADMIN%" (
    "%MYSQL_ADMIN%" -u root -h 127.0.0.1 -P 3307 shutdown >nul 2>&1
)

echo Done.
timeout /t 2 /nobreak >nul
