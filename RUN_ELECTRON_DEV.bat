@echo off
cd /d "%~dp0electron-desktop"
if not exist "node_modules\electron" (
    echo Installing Electron dependencies...
    call npm install
)
call npm run dev
