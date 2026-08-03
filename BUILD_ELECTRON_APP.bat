@echo off
setlocal EnableExtensions
cd /d "%~dp0"

title Build Gold Exchange Electron App
echo.
echo ========================================
echo   Gold Exchange - Electron Desktop Build
echo ========================================
echo.

where node >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Node.js is not installed.
    echo Download from https://nodejs.org/ and run this again.
    pause
    exit /b 1
)

if not exist "C:\xampp\php\php.exe" (
    echo [ERROR] XAMPP not found at C:\xampp
    echo Install XAMPP first ^(PHP + MySQL^).
    pause
    exit /b 1
)

echo Step 1/4: Preparing server bundle...
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0electron-desktop\scripts\prepare-server.ps1"
if errorlevel 1 goto :failed

echo.
echo Step 2/4: Installing Electron dependencies...
cd /d "%~dp0electron-desktop"
call npm install
if errorlevel 1 goto :failed

echo.
echo Step 3/4: Building Windows installer...
call npm run build
if errorlevel 1 goto :failed

echo.
echo ========================================
echo   BUILD COMPLETE
echo ========================================
echo.
echo Installer: electron-desktop\dist\GoldExchange-Setup-1.0.0.exe
echo.
echo To run in dev mode ^(no installer^):
echo   cd electron-desktop
echo   npm run dev
echo.
pause
exit /b 0

:failed
echo.
echo [ERROR] Build failed.
pause
exit /b 1
