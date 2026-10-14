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
if not exist public\build\manifest.json (
    echo Building frontend...
    call npm ci
    if errorlevel 1 goto fail
    call npm run build
    if errorlevel 1 goto fail
)
if exist public\build xcopy /E /I /Y public\build build >nul
start "" http://localhost/local_sync/
echo XAMPP should be running Apache and MySQL.
exit /b 0
:fail
echo Preparation failed. Review the message above.
pause
exit /b 1
