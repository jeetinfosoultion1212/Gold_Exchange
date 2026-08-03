@echo off
setlocal
cd /d "%~dp0"

set "MYSQL=C:\xampp\mysql\bin\mysql.exe"
set "PORT=3307"
if not exist "%MYSQL%" (
  echo XAMPP MySQL not found at %MYSQL%
  echo Edit FIX_DESKTOP_DATABASE.bat if MySQL is installed elsewhere.
  pause
  exit /b 1
)

echo Applying desktop schema fix on 127.0.0.1:%PORT% ...
"%MYSQL%" -u root -h 127.0.0.1 -P %PORT% < "config\desktop_fix_schema.sql"
if errorlevel 1 (
  echo Fix failed. Is the desktop MySQL server running?
  pause
  exit /b 1
)

echo Done. Restart the desktop app and try Exchange again.
pause
