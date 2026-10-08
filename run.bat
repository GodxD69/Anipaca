@echo off
title AniPaca Server
cd /d "%~dp0"
echo ===================================================
echo             Starting AniPaca Anime Web App         
echo ===================================================
echo.
echo URL: http://localhost:8000
echo.
echo Press Ctrl+C to stop the server anytime.
echo.
"tools\php\php.exe" -S localhost:8000 router.php
pause
