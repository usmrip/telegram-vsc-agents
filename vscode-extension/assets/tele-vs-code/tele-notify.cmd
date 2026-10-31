@echo off
rem ==============================================================================
rem tele-notify.cmd: Universal Telegram Notification CLI for Windows
rem Menggunakan Node.js native engine jika ada, atau PowerShell fallback
rem ==============================================================================
where node >nul 2>nul
if not errorlevel 1 (
    node "%~dp0tele-notify.js" %*
    exit /b %ERRORLEVEL%
)

rem Fallback ke PowerShell jika node belum terpasang
powershell -ExecutionPolicy Bypass -NoProfile -File "%~dp0tele-notify.ps1" -Title "%~1" -Status "%~2" -Details @("%~3","%~4","%~5")
exit /b %ERRORLEVEL%
