@echo off
rem ==============================================================================
rem Telegram Task Worker CLI Wrapper for Windows CMD
rem ==============================================================================
where node >nul 2>nul
if errorlevel 1 (
    echo [ERROR] Node.js diperlukan untuk menjalankan tele-worker.
    exit /b 1
)

node "%~dp0tele-worker.js" %*

