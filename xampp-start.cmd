@echo off
setlocal
cd /d "%~dp0"
where php >nul 2>nul
if errorlevel 1 (
    echo PHP was not found in PATH. Add XAMPP's php folder to PATH.
    pause
    exit /b 1
)
if not exist vendor\autoload.php (
    echo Installing PHP dependencies...
    call composer install --no-interaction --prefer-dist
    if errorlevel 1 goto fail
)
if exist public\hot del /Q public\hot >nul
if not exist .env copy .env.example .env >nul
php bootstrap-local.php
if errorlevel 1 goto fail
rem Rebuild the frontend on every start, so a git pull shows up. Machines without Node keep the build they were given.
call npm --version >nul 2>nul
if errorlevel 1 (
    if not exist public\build\manifest.json (
        echo Node.js was not found and there is no frontend build. Install Node.js and run this file again.
        goto fail
    )
    echo WARNING: npm was not found, so the existing frontend build is kept and may be out of date.
    echo Install Node.js, open a NEW Command Prompt, and run this file again to rebuild it.
) else (
    if not exist node_modules (
        echo Installing frontend dependencies...
        call npm ci
        if errorlevel 1 goto fail
    )
    echo Building frontend...
    call npm run build
    if errorlevel 1 goto fail
)
if exist public\build xcopy /E /I /Y public\build build >nul
rem The URL uses this folder's name, so htdocs\quinos_sync opens localhost/quinos_sync/.
for %%I in ("%~dp0.") do set "APPFOLDER=%%~nxI"
start "" http://localhost/%APPFOLDER%/
echo XAMPP should be running Apache and MySQL.
exit /b 0
:fail
echo Preparation failed. Review the message above.
pause
exit /b 1
