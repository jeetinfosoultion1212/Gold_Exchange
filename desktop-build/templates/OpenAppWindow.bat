@echo off
setlocal EnableExtensions
set "APP_URL=%~1"
if "%APP_URL%"=="" set "APP_URL=http://127.0.0.1:8080/login.php"

set "EDGE86=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
set "EDGE64=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"
set "CHROME86=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
set "CHROME64=%ProgramFiles%\Google\Chrome\Application\chrome.exe"

if exist "%EDGE86%" (
    start "" "%EDGE86%" --app="%APP_URL%" --window-size=1280,900 --disable-features=TranslateUI
    exit /b 0
)
if exist "%EDGE64%" (
    start "" "%EDGE64%" --app="%APP_URL%" --window-size=1280,900 --disable-features=TranslateUI
    exit /b 0
)
if exist "%CHROME64%" (
    start "" "%CHROME64%" --app="%APP_URL%" --window-size=1280,900
    exit /b 0
)
if exist "%CHROME86%" (
    start "" "%CHROME86%" --app="%APP_URL%" --window-size=1280,900
    exit /b 0
)

REM Fallback if no Edge/Chrome found
start "" "%APP_URL%"
exit /b 0
