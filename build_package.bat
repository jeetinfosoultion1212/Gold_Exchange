@echo off
SETLOCAL EnableDelayedExpansion
TITLE Gold Exchange - Build Package

echo ========================================
echo   Gold Exchange - Build Package
echo ========================================
echo.

REM Get the directory where this script is located
set "SCRIPT_DIR=%~dp0"
set "SOURCE_DIR=%SCRIPT_DIR%"
set "DEST_DIR=%SCRIPT_DIR%desktop_package"

echo Source: %SOURCE_DIR%
echo Destination: %DEST_DIR%
echo.

echo [1/4] Cleaning previous www build...
if exist "%DEST_DIR%\phpdesktop\www" (
    rmdir /s /q "%DEST_DIR%\phpdesktop\www"
    mkdir "%DEST_DIR%\phpdesktop\www"
)

echo [2/4] Copying application files...
REM We use robocopy to exclude the desktop_package folder itself and other non-project files
robocopy "%SOURCE_DIR%." "%DEST_DIR%\phpdesktop\www" /E /XD "desktop_package" ".git" ".vscode" "backups" /XF "*.bat" "*.md" "*.txt" "composer.lock" "package-lock.json"

REM Re-copy config.php explicitly to ensure it's there
REM Re-copy config.php explicitly to ensure it's there
xcopy /E /I /Y "config\database.php" "desktop_package\phpdesktop\www\config\"
xcopy /Y "config_desktop.php" "desktop_package\phpdesktop\www\"
xcopy /Y "desktop_package\THIRD_PARTY_LICENSES.txt" "desktop_package\phpdesktop\www\" >NUL
copy "desktop_package\check_dependencies.bat" "desktop_package\" >NUL

echo [3/4] Copying documentation...
copy "%DEST_DIR%\README.txt" "%DEST_DIR%\phpdesktop\" >NUL

echo [4/4] Verifying structure...
if exist "%DEST_DIR%\phpdesktop\www\index.php" (
    echo [OK] index.php found.
) else (
    echo [WARNING] index.php not found in build directory.
)

if exist "%DEST_DIR%\mariadb\my.ini" (
    echo [OK] MariaDB configuration found.
) else (
    echo [WARNING] MariaDB configuration (my.ini) not found.
)

echo.
echo ========================================
echo   Build Complete!
echo ========================================
echo.
echo The package is ready in: %DEST_DIR%
echo.
echo Next steps:
echo 1. Download PHP Desktop to %DEST_DIR%\phpdesktop
echo    Link: https://github.com/cztomczak/phpdesktop/releases/download/chrome-v57.0-rc-php-7.1.3/phpdesktop-chrome-57.0-rc-php-7.1.3.zip
echo 2. Download MariaDB Portable to %DEST_DIR%\mariadb
echo    Link: https://archive.mariadb.org/mariadb-10.6.16/winx64-packages/mariadb-10.6.16-winx64.zip
echo 3. Run scripts\install.bat to initialize DB
echo.
pause
