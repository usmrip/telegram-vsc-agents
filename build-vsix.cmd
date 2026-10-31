@echo off
setlocal
cd /d "%~dp0"
echo ==============================================================================
echo [BUILD] Memaketkan Ekstensi VS Code Private (.VSIX)
echo ==============================================================================
node vscode-extension\build-vsix.js
if errorlevel 1 (
    echo [ERROR] Gagal memaketkan ekstensi VSIX.
    pause
    exit /b 1
)
echo.
echo Selesai! File VSIX siap diinstall: tele-vs-code-1.0.0.vsix
echo Jalankan "install-vsix.cmd" untuk langsung menginstal ke VS Code.
echo ==============================================================================

