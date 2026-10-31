@echo off
rem ==============================================================================
rem Telegram Agent Listener CLI Wrapper for Windows Command Prompt
rem ==============================================================================
where node >nul 2>nul
if errorlevel 1 (
    echo [ERROR] Node.js diperlukan untuk menjalankan tele-listener.
    echo Silakan install Node.js v18 atau lebih baru terlebih dahulu.
    exit /b 1
)

node "%~dp0tele-listener.js" %*
