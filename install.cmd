@echo off
setlocal EnableDelayedExpansion

rem ==============================================================================
rem Installer & Updater untuk Telegram Dev Notifier, Sync & Worker (Windows)
rem ==============================================================================

set "TARGET_DIR=%~1"

if "%TARGET_DIR%"=="" (
    echo.
    echo ====================================================================
    echo [ERROR] Instalasi / Update ditolak: Parameter folder target wajib diisi.
    echo ====================================================================
    echo.
    echo Penggunaan:
    echo   install.bat ^<path_folder_proyek^>
    echo.
    echo Contoh:
    echo   install.bat C:\ngodings\aplikasi-saya
    echo   install.bat .
    echo.
    exit /b 1
)

if not exist "%TARGET_DIR%" (
    echo.
    echo [ERROR] Direktori target "%TARGET_DIR%" tidak ditemukan.
    exit /b 1
)

set "SCRIPT_DIR=%~dp0"
if "%SCRIPT_DIR:~-1%"=="\" set "SCRIPT_DIR=%SCRIPT_DIR:~0,-1%"

pushd "%TARGET_DIR%"
set "ABS_TARGET_DIR=%CD%"
popd

echo.
echo ====================================================================
echo [START] Memulai Instalasi / Pembaruan Telegram Notifier ^& Task Agent
echo [INFO]  Proyek Target : %ABS_TARGET_DIR%
echo ====================================================================
echo.

rem ------------------------------------------------------------------------------
rem 1. Pasang Binary CLI ke Global Profile (%USERPROFILE%\bin)
rem ------------------------------------------------------------------------------
set "GLOBAL_BIN=%USERPROFILE%\bin"
if not exist "%GLOBAL_BIN%" mkdir "%GLOBAL_BIN%"

copy /y "%SCRIPT_DIR%\tele-notify.cmd" "%GLOBAL_BIN%\tele-notify.cmd" >nul
copy /y "%SCRIPT_DIR%\tele-notify.js" "%GLOBAL_BIN%\tele-notify.js" >nul
copy /y "%SCRIPT_DIR%\tele-notify.ps1" "%GLOBAL_BIN%\tele-notify.ps1" >nul
copy /y "%SCRIPT_DIR%\tele-sync.cmd" "%GLOBAL_BIN%\tele-sync.cmd" >nul
copy /y "%SCRIPT_DIR%\tele-sync.ps1" "%GLOBAL_BIN%\tele-sync.ps1" >nul
copy /y "%SCRIPT_DIR%\tele-worker.cmd" "%GLOBAL_BIN%\tele-worker.cmd" >nul
copy /y "%SCRIPT_DIR%\tele-worker.js" "%GLOBAL_BIN%\tele-worker.js" >nul
copy /y "%SCRIPT_DIR%\tele-task.cmd" "%GLOBAL_BIN%\tele-task.cmd" >nul
copy /y "%SCRIPT_DIR%\tele-task.js" "%GLOBAL_BIN%\tele-task.js" >nul
copy /y "%SCRIPT_DIR%\tele-listener.cmd" "%GLOBAL_BIN%\tele-listener.cmd" >nul
copy /y "%SCRIPT_DIR%\tele-listener.js" "%GLOBAL_BIN%\tele-listener.js" >nul

echo [1/5] Perangkat CLI global berhasil dipasang di: %GLOBAL_BIN%

rem ------------------------------------------------------------------------------
rem 2. Salin Helper CLI ke Direktori Proyek Target (Opsional agar siap pakai langsung)
rem ------------------------------------------------------------------------------
if /i not "%SCRIPT_DIR%"=="%ABS_TARGET_DIR%" (
    copy /y "%SCRIPT_DIR%\tele-notify.cmd" "%ABS_TARGET_DIR%\tele-notify.cmd" >nul
    copy /y "%SCRIPT_DIR%\tele-notify.js" "%ABS_TARGET_DIR%\tele-notify.js" >nul
    copy /y "%SCRIPT_DIR%\tele-sync.cmd" "%ABS_TARGET_DIR%\tele-sync.cmd" >nul
    copy /y "%SCRIPT_DIR%\tele-worker.cmd" "%ABS_TARGET_DIR%\tele-worker.cmd" >nul
    copy /y "%SCRIPT_DIR%\tele-worker.js" "%ABS_TARGET_DIR%\tele-worker.js" >nul
    echo [2/5] Helper CLI proyek disinkronkan ke: %ABS_TARGET_DIR%
) else (
    echo [2/5] Direktori target adalah sumber utama proyek, file lokal dilewati.
)

rem ------------------------------------------------------------------------------
rem 3. Konfigurasi Kredensial Global (~/.config/tele-notify/config.env)
rem ------------------------------------------------------------------------------
set "GLOBAL_CONFIG_DIR=%USERPROFILE%\.config\tele-notify"
if not exist "%GLOBAL_CONFIG_DIR%" mkdir "%GLOBAL_CONFIG_DIR%"

if exist "%SCRIPT_DIR%\config.env" (
    copy /y "%SCRIPT_DIR%\config.env" "%GLOBAL_CONFIG_DIR%\config.env" >nul
    echo [3/5] Kredensial berhasil disinkronkan ke: %GLOBAL_CONFIG_DIR%\config.env
) else if not exist "%GLOBAL_CONFIG_DIR%\config.env" (
    copy /y "%SCRIPT_DIR%\config.env.example" "%GLOBAL_CONFIG_DIR%\config.env" >nul
    echo [3/5] Template konfigurasi dibuat di: %GLOBAL_CONFIG_DIR%\config.env
) else (
    echo [3/5] Konfigurasi kredensial global sudah ada.
)

rem ------------------------------------------------------------------------------
rem 4. Injeksi & Update Petunjuk ke AGENTS.md di Folder Target
rem ------------------------------------------------------------------------------
set "AGENTS_FILE=%ABS_TARGET_DIR%\AGENTS.md"

if exist "%AGENTS_FILE%" (
    findstr /C:"## NOTIFIKASI TELEGRAM" "%AGENTS_FILE%" >nul 2>nul
    if errorlevel 1 (
        echo.>>"%AGENTS_FILE%"
        echo ## NOTIFIKASI TELEGRAM>>"%AGENTS_FILE%"
        echo Setiap kali Anda menyelesaikan suatu tugas atau jika menemukan kendala error yang memblokir proses, jalankan perintah terminal:>>"%AGENTS_FILE%"
        echo - Jika sukses ^(HANYA jika tugas benar-benar 100%% selesai dan terverifikasi^):>>"%AGENTS_FILE%"
        echo   tele-notify "<Judul Tugas>" --status SELESAI --detail "<Rincian 1>" --detail "<Rincian 2>">>"%AGENTS_FILE%"
        echo - Jika dalam proses pengerjaan:>>"%AGENTS_FILE%"
        echo   tele-notify "<Judul Tugas>" --status PROSES --detail "<Status progres saat ini>">>"%AGENTS_FILE%"
        echo - Jika kendala:>>"%AGENTS_FILE%"
        echo   tele-notify "<Judul Kendala>" --status KENDALA --detail "<Penyebab>">>"%AGENTS_FILE%"
        echo.>>"%AGENTS_FILE%"
        echo ^> ATURAN: JANGAN mengirim status SELESAI saat tugas baru dimulai. Status SELESAI HANYA untuk pekerjaan yang sudah 100%% selesai.>>"%AGENTS_FILE%"
        echo [4/5] Petunjuk NOTIFIKASI TELEGRAM ditambahkan ke AGENTS.md
    ) else (
        echo [4/5] Petunjuk NOTIFIKASI TELEGRAM sudah ada di AGENTS.md
    )

    findstr /C:"TASK_QUEUE.md" "%AGENTS_FILE%" >nul 2>nul
    if errorlevel 1 (
        echo.>>"%AGENTS_FILE%"
        echo ## ANTREAN TUGAS TELEGRAM ^(TASK_QUEUE.md^)>>"%AGENTS_FILE%"
        echo Jika terdapat file TASK_QUEUE.md di root proyek, periksa tugas berstatus [PENDING]:>>"%AGENTS_FILE%"
        echo 1. Ubah status menjadi [IN_PROGRESS] saat mulai mengerjakan.>>"%AGENTS_FILE%"
        echo 2. Kerjakan tugas sesuai instruksi yang tercantum.>>"%AGENTS_FILE%"
        echo 3. Setelah selesai, ubah status menjadi [DONE] atau [FAILED] jika gagal dan catat ringkasan perubahannya.>>"%AGENTS_FILE%"
        echo 4. Kirimkan notifikasi ke Telegram menggunakan perintah terminal tele-notify di atas.>>"%AGENTS_FILE%"
        echo [4/5] Petunjuk TASK_QUEUE.md ditambahkan ke AGENTS.md
    )

    findstr /C:"DIRECT SAVE" "%AGENTS_FILE%" >nul 2>nul
    if errorlevel 1 (
        echo.>>"%AGENTS_FILE%"
        echo ## MODE EKSEKUSI ^& PENYIMPANAN LANGSUNG ^(DIRECT SAVE^)>>"%AGENTS_FILE%"
        echo Karena seluruh riwayat pekerjaan dapat di-undo melalui Git dan IDE:>>"%AGENTS_FILE%"
        echo - Langsung simpan ^(save^) dan terapkan semua perubahan file, penambahan kode, dan pembuatan fitur tanpa perlu meminta konfirmasi berulang.>>"%AGENTS_FILE%"
        echo - Langsung jalankan pengujian/verifikasi hingga tuntas 100%%.>>"%AGENTS_FILE%"
        echo - Commit hasil pekerjaan yang sudah selesai ke Git.>>"%AGENTS_FILE%"
    )
) else (
    echo ## NOTIFIKASI TELEGRAM>"%AGENTS_FILE%"
    echo Setiap kali Anda menyelesaikan suatu tugas atau jika menemukan kendala error yang memblokir proses, jalankan perintah terminal:>>"%AGENTS_FILE%"
    echo - Jika sukses ^(HANYA jika tugas benar-benar 100%% selesai dan terverifikasi^):>>"%AGENTS_FILE%"
    echo   tele-notify "<Judul Tugas>" --status SELESAI --detail "<Rincian 1>" --detail "<Rincian 2>">>"%AGENTS_FILE%"
    echo - Jika dalam proses pengerjaan:>>"%AGENTS_FILE%"
    echo   tele-notify "<Judul Tugas>" --status PROSES --detail "<Status progres saat ini>">>"%AGENTS_FILE%"
    echo - Jika kendala:>>"%AGENTS_FILE%"
    echo   tele-notify "<Judul Kendala>" --status KENDALA --detail "<Penyebab>">>"%AGENTS_FILE%"
    echo.>>"%AGENTS_FILE%"
    echo ^> ATURAN: JANGAN mengirim status SELESAI saat tugas baru dimulai. Status SELESAI HANYA untuk pekerjaan yang sudah 100%% selesai.>>"%AGENTS_FILE%"
    echo.>>"%AGENTS_FILE%"
    echo ## ANTREAN TUGAS TELEGRAM ^(TASK_QUEUE.md^)>>"%AGENTS_FILE%"
    echo Jika terdapat file TASK_QUEUE.md di root proyek, periksa tugas berstatus [PENDING]:>>"%AGENTS_FILE%"
    echo 1. Ubah status menjadi [IN_PROGRESS] saat mulai mengerjakan.>>"%AGENTS_FILE%"
    echo 2. Kerjakan tugas sesuai instruksi yang tercantum.>>"%AGENTS_FILE%"
    echo 3. Setelah selesai, ubah status menjadi [DONE] atau [FAILED] jika gagal dan catat ringkasan perubahannya.>>"%AGENTS_FILE%"
    echo 4. Kirimkan notifikasi ke Telegram menggunakan perintah terminal tele-notify di atas.>>"%AGENTS_FILE%"
    echo.>>"%AGENTS_FILE%"
    echo ## MODE EKSEKUSI ^& PENYIMPANAN LANGSUNG ^(DIRECT SAVE^)>>"%AGENTS_FILE%"
    echo Karena seluruh riwayat pekerjaan dapat di-undo melalui Git dan IDE:>>"%AGENTS_FILE%"
    echo - Langsung simpan ^(save^) dan terapkan semua perubahan file, penambahan kode, dan pembuatan fitur tanpa perlu meminta konfirmasi berulang.>>"%AGENTS_FILE%"
    echo - Langsung jalankan pengujian/verifikasi hingga tuntas 100%%.>>"%AGENTS_FILE%"
    echo - Commit hasil pekerjaan yang sudah selesai ke Git.>>"%AGENTS_FILE%"
    echo [4/5] File AGENTS.md berhasil dibuat beserta instruksi.
)

rem Inisialisasi TASK_QUEUE.md jika belum ada
if not exist "%ABS_TARGET_DIR%\TASK_QUEUE.md" (
    echo # 📋 TASK QUEUE ^(Antrean Tugas Agent^) > "%ABS_TARGET_DIR%\TASK_QUEUE.md"
    echo. >> "%ABS_TARGET_DIR%\TASK_QUEUE.md"
    echo ## ⏳ Tugas Aktif >> "%ABS_TARGET_DIR%\TASK_QUEUE.md"
    echo. >> "%ABS_TARGET_DIR%\TASK_QUEUE.md"
    echo ## ✅ Riwayat Tugas Selesai >> "%ABS_TARGET_DIR%\TASK_QUEUE.md"
)

rem ------------------------------------------------------------------------------
rem 5. Sinkronisasi Server Webhook Otomatis (Jika Drive U: Terhubung)
rem ------------------------------------------------------------------------------
set "SERVER_DEPLOY_DIR=U:\domains\biru.co.id\public_html\tele-vs-code"
if exist "%SERVER_DEPLOY_DIR%" (
    copy /y "%SCRIPT_DIR%\tele-vs-code\index.php" "%SERVER_DEPLOY_DIR%\index.php" >nul
    copy /y "%SCRIPT_DIR%\tele-vs-code-1.0.0.vsix" "%SERVER_DEPLOY_DIR%\tele-vs-code-1.0.0.vsix" >nul
    echo [5/5] File server otomatis disinkronkan ke: %SERVER_DEPLOY_DIR%
) else (
    echo [5/5] Drive U: server webhook tidak terdeteksi ^(dilewati^).
)

echo.
echo ====================================================================
echo [SUCCESS] Pemasangan / Pembaruan Berhasil!
echo ====================================================================
echo.
echo Uji coba kirim notifikasi:
echo   tele-notify "Halo dari Terminal" "SELESAI" "Pemasangan sukses"
echo.
echo Tarik tugas dari server biru.co.id:
echo   tele-sync
echo.
echo Jalankan antrean otomatis 1 per 1 (background):
echo   tele-worker
echo.
