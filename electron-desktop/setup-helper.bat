@echo off
echo ========================================
echo  Gold Exchange Desktop - Setup Helper
echo ========================================
echo.

:menu
echo.
echo What would you like to do?
echo.
echo 1. Install Dependencies (npm install)
echo 2. Run in Development Mode
echo 3. Build Windows Installer
echo 4. Open Project Folder
echo 5. View Documentation
echo 6. Exit
echo.
set /p choice="Enter your choice (1-6): "

if "%choice%"=="1" goto install
if "%choice%"=="2" goto dev
if "%choice%"=="3" goto build
if "%choice%"=="4" goto folder
if "%choice%"=="5" goto docs
if "%choice%"=="6" goto end

echo Invalid choice! Please try again.
goto menu

:install
echo.
echo Installing dependencies...
echo This may take a few minutes...
echo.
call npm install
echo.
echo ========================================
echo Installation complete!
echo ========================================
pause
goto menu

:dev
echo.
echo Starting development mode...
echo.
echo The app will open in a new window.
echo Check the console for any errors.
echo Press Ctrl+C to stop the server.
echo.
call npm run dev
pause
goto menu

:build
echo.
echo Building Windows installer...
echo This will take 5-10 minutes...
echo.
call npm run build-win
echo.
echo ========================================
echo Build complete!
echo Check the dist/ folder for the installer.
echo ========================================
pause
goto menu

:folder
echo.
echo Opening project folder...
explorer .
goto menu

:docs
echo.
echo Opening documentation...
start START_HERE.md
start SETUP_INSTRUCTIONS.md
start QUICK_REFERENCE.md
goto menu

:end
echo.
echo Thank you for using Gold Exchange Desktop!
echo.
exit
