@echo off
REM Local development server (Windows). Requires php.exe on PATH and app\config.php present.
cd /d "%~dp0"
if not exist app\config.php (echo Missing app\config.php - copy app\config.example.php first & exit /b 1)
php -v >nul 2>&1 || (echo php.exe not found on PATH & exit /b 1)
echo Serving http://localhost:8080  (Ctrl+C to stop)
php -S localhost:8080 -t public public\index.php
