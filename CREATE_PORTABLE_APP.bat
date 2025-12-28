@echo off
title Create Mormukut Portable App
color 0B

echo.
echo ╔══════════════════════════════════════════════════════════════╗
echo ║                                                              ║
echo ║     MORMUKUT PORTABLE APP CREATOR                            ║
echo ║     Super Simple - No Installation Needed!                   ║
echo ║                                                              ║
echo ╚══════════════════════════════════════════════════════════════╝
echo.
echo This will create a PORTABLE version of Mormukut
echo Users just extract and run - NO INSTALLATION!
echo.
pause

REM Create portable app folder
echo Creating portable app folder...
if exist "MormukutPortable" rmdir /S /Q "MormukutPortable"
mkdir "MormukutPortable"
mkdir "MormukutPortable\htdocs"
mkdir "MormukutPortable\data"
mkdir "MormukutPortable\config"

REM Copy XAMPP essentials
echo.
echo Copying XAMPP files...
xcopy /E /I /Y "C:\xampp\apache" "MormukutPortable\apache" >nul
xcopy /E /I /Y "C:\xampp\php" "MormukutPortable\php" >nul
xcopy /E /I /Y "C:\xampp\mysql" "MormukutPortable\mysql" >nul

REM Copy your application
echo Copying Mormukut application...
xcopy /E /I /Y "*.php" "MormukutPortable\htdocs" >nul
xcopy /E /I /Y "components" "MormukutPortable\htdocs\components" >nul
xcopy /E /I /Y "config" "MormukutPortable\htdocs\config" >nul
xcopy /E /I /Y "css" "MormukutPortable\htdocs\css" >nul
xcopy /E /I /Y "js" "MormukutPortable\htdocs\js" >nul
xcopy /E /I /Y "phpqrcode" "MormukutPortable\htdocs\phpqrcode" >nul
xcopy /E /I /Y "tcpdf" "MormukutPortable\htdocs\tcpdf" >nul

REM Create launcher
echo Creating launcher...
(
echo @echo off
echo title Mormukut Gold Management System
echo cls
echo.
echo Starting Mormukut...
echo.
echo Starting MySQL...
echo start /B mysql\bin\mysqld.exe --console
echo timeout /t 3 /nobreak ^>nul
echo.
echo Starting Apache...
echo start /B apache\bin\httpd.exe
echo timeout /t 2 /nobreak ^>nul
echo.
echo Opening Mormukut...
echo start http://localhost:8590
echo.
echo ╔══════════════════════════════════════════════════════════╗
echo ║  Mormukut is now running!                                ║
echo ║  Browser will open automatically                         ║
echo ║  Login: admin / admin123                                 ║
echo ║  Close this window to stop Mormukut                      ║
echo ╚══════════════════════════════════════════════════════════╝
echo.
echo Press any key to stop Mormukut...
pause ^>nul
echo.
echo Stopping services...
taskkill /F /IM httpd.exe ^>nul 2^>^&1
taskkill /F /IM mysqld.exe ^>nul 2^>^&1
echo Done!
) > "MormukutPortable\Start Mormukut.bat"

REM Create README
(
echo ╔══════════════════════════════════════════════════════════╗
echo ║  MORMUKUT PORTABLE - QUICK START                         ║
echo ╚══════════════════════════════════════════════════════════╝
echo.
echo HOW TO USE:
echo   1. Extract this folder anywhere
echo   2. Double-click "Start Mormukut.bat"
echo   3. Browser opens automatically
echo   4. Login: admin / admin123
echo.
echo TO STOP:
echo   Press any key in the black window
echo.
echo FEATURES:
echo   • No installation required
echo   • Works from USB drive
echo   • Portable - take it anywhere
echo   • Your data stays with the app
echo.
echo SYSTEM REQUIREMENTS:
echo   • Windows 7 or higher
echo   • 500 MB free space
echo   • Internet browser
echo.
) > "MormukutPortable\README.txt"

echo.
echo ✅ PORTABLE APP CREATED!
echo.
echo Location: MormukutPortable\
echo.
echo Next step:
echo   1. Compress MormukutPortable folder to ZIP
echo   2. Give ZIP file to users
echo   3. They extract and run "Start Mormukut.bat"
echo.
echo That's it! No installation needed!
echo.
pause




