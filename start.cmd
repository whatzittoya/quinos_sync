@echo off
setlocal
cd /d "%~dp0"
where php >nul 2>nul
if errorlevel 1 (
    echo PHP was not found. Install PHP and add it to PATH, then run this file again.
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
where npm >nul 2>nul
if errorlevel 1 (
    if not exist public\build\manifest.json (
        echo Node.js was not found and there is no frontend build. Install Node.js and run this file again.
        goto fail
    )
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
if exist public\build (
    echo Copying frontend assets for XAMPP...
    xcopy /E /I /Y public\build build >nul
)
echo Open http://127.0.0.1:8010 in your browser.
start "" http://127.0.0.1:8010
php artisan serve --host=127.0.0.1 --port=8010
exit /b 0
:fail
echo Startup failed. Review the message above.
pause
exit /b 1
