@echo off
title Marketing Command — Scheduler Worker

echo ============================================================
echo  Marketing Command — Scheduled Post Publisher
echo ============================================================
echo.
echo  This window MUST remain open for scheduled posts to publish.
echo  Posts will be checked every 60 seconds.
echo  Logs: storage\logs\laravel.log
echo.
echo  Starting scheduler at %DATE% %TIME%
echo ============================================================
echo.

cd /d "%~dp0"
php artisan schedule:work

echo.
echo [ERROR] Scheduler stopped unexpectedly. Press any key to restart.
pause
start "" "%~f0"
