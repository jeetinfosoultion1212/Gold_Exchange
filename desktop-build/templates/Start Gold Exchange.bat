@echo off
setlocal EnableExtensions EnableDelayedExpansion
cd /d "%~dp0"

title Gold Exchange
set "ROOT=%CD%"
set "ROOT_FWD=%ROOT:\=/%"
set "PHPDESKTOP_VERSION=1.0"
set "GOLD_EXCHANGE_DESKTOP=1"
set "GOLD_EXCHANGE_DB_PORT=3307"
set "GOLD_EXCHANGE_SUMATRA=%ROOT%\runtime\SumatraPDF\SumatraPDF.exe"
set "PHP=%ROOT%\runtime\php\php.exe"
set "MYSQLD=%ROOT%\runtime\mysql\bin\mysqld.exe"
set "MYSQL_INSTALL=%ROOT%\runtime\mysql\bin\mysql_install_db.exe"
set "MYSQL_ADMIN=%ROOT%\runtime\mysql\bin\mysqladmin.exe"
set "INI=%ROOT%\data\my.ini"
set "APP_URL=http://127.0.0.1:8080/login.php"

if not exist "%PHP%" (
    echo [ERROR] PHP not found. Rebuild the desktop package.
    pause
    exit /b 1
)
if not exist "%MYSQLD%" (
    echo [ERROR] MySQL not found. Rebuild the desktop package.
    pause
    exit /b 1
)

if not exist "%ROOT%\data" mkdir "%ROOT%\data"

if not exist "%ROOT%\data\mysql" (
    echo Initializing database ^(first run only^)...
    "%MYSQL_INSTALL%" --datadir="%ROOT%\data" -P 3307
    if errorlevel 1 (
        echo [ERROR] Database initialization failed.
        pause
        exit /b 1
    )
)

findstr /I /C:"basedir=" "%INI%" >nul 2>&1
if errorlevel 1 (
    echo basedir=%ROOT_FWD%/runtime/mysql>> "%INI%"
)

"%MYSQL_ADMIN%" -u root -h 127.0.0.1 -P 3307 ping >nul 2>&1
if errorlevel 1 (
    echo Starting database...
    start "" /B "%MYSQLD%" --defaults-file="%INI%" --standalone
    set /a tries=0
    :wait_db
    timeout /t 1 /nobreak >nul
    "%MYSQL_ADMIN%" -u root -h 127.0.0.1 -P 3307 ping >nul 2>&1
    if not errorlevel 1 goto db_ready
    set /a tries+=1
    if !tries! lss 20 goto wait_db
    echo [ERROR] Database did not start. Port 3307 may be in use.
    pause
    exit /b 1
)
:db_ready

netstat -ano | findstr /R /C:":8080 .*LISTENING" >nul
if errorlevel 1 (
    echo Starting application server...
    start "" /B "%PHP%" -S 127.0.0.1:8080 -t "%ROOT%\app"
    timeout /t 2 /nobreak >nul
) else (
    echo Application server already running on port 8080.
)

echo Opening Gold Exchange (desktop window)...
call "%~dp0OpenAppWindow.bat" "%APP_URL%"
echo.
echo Gold Exchange is running.
echo   Close the app window when finished, then run "Stop Gold Exchange.bat"
echo.
pause
