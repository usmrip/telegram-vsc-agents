#!/usr/bin/env bash
# ==============================================================================
# Installer & Updater untuk Telegram Dev Notifier, Sync & Worker (Linux/macOS)
# ==============================================================================
set -e

TARGET_PROJECT_DIR="$1"
if [ -z "$TARGET_PROJECT_DIR" ]; then
    echo ""
    echo "===================================================================="
    echo "❌ Instalasi / Update ditolak: Parameter folder target wajib diisi."
    echo "===================================================================="
    echo ""
    echo "Penggunaan:"
    echo "  $0 <path_folder_proyek>"
    echo ""
    echo "Contoh:"
    echo "  $0 /home/user/my-project"
    echo "  $0 ."
    echo ""
    exit 1
fi

if [ ! -d "$TARGET_PROJECT_DIR" ]; then
    echo ""
    echo "❌ [ERROR] Direktori target '$TARGET_PROJECT_DIR' tidak ditemukan."
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ABS_TARGET_DIR="$(cd "$TARGET_PROJECT_DIR" && pwd)"

echo ""
echo "===================================================================="
echo "🚀 Memulai Instalasi / Pembaruan Telegram Notifier & Task Agent"
echo "📁 Proyek Target : $ABS_TARGET_DIR"
echo "===================================================================="
echo ""

# ------------------------------------------------------------------------------
# 1. Pasang Binary CLI ke Global Profile ($HOME/bin)
# ------------------------------------------------------------------------------
TARGET_DIR="$HOME/bin"
mkdir -p "$TARGET_DIR"

cp "$SCRIPT_DIR/tele-notify.sh" "$TARGET_DIR/tele-notify"
chmod +x "$TARGET_DIR/tele-notify"

if [ -f "$SCRIPT_DIR/tele-notify.js" ]; then
    cp "$SCRIPT_DIR/tele-notify.js" "$TARGET_DIR/tele-notify.js"
fi

if [ -f "$SCRIPT_DIR/tele-sync.sh" ]; then
    cp "$SCRIPT_DIR/tele-sync.sh" "$TARGET_DIR/tele-sync"
    chmod +x "$TARGET_DIR/tele-sync"
fi

if [ -f "$SCRIPT_DIR/tele-worker.sh" ]; then
    cp "$SCRIPT_DIR/tele-worker.sh" "$TARGET_DIR/tele-worker"
    cp "$SCRIPT_DIR/tele-worker.js" "$TARGET_DIR/tele-worker.js"
    chmod +x "$TARGET_DIR/tele-worker"
fi

if [ -f "$SCRIPT_DIR/tele-task.sh" ]; then
    cp "$SCRIPT_DIR/tele-task.sh" "$TARGET_DIR/tele-task"
    cp "$SCRIPT_DIR/tele-task.js" "$TARGET_DIR/tele-task.js"
    chmod +x "$TARGET_DIR/tele-task"
fi

if [ -f "$SCRIPT_DIR/tele-listener.sh" ]; then
    cp "$SCRIPT_DIR/tele-listener.sh" "$TARGET_DIR/tele-listener"
    cp "$SCRIPT_DIR/tele-listener.js" "$TARGET_DIR/tele-listener.js"
    chmod +x "$TARGET_DIR/tele-listener"
fi

echo "✅ [1/5] Perangkat CLI global berhasil dipasang di: $TARGET_DIR/"

# ------------------------------------------------------------------------------
# 2. Salin Helper CLI ke Direktori Proyek Target (Jika Berbeda Folder)
# ------------------------------------------------------------------------------
if [ "$SCRIPT_DIR" != "$ABS_TARGET_DIR" ]; then
    cp "$SCRIPT_DIR/tele-notify.sh" "$ABS_TARGET_DIR/tele-notify.sh"
    [ -f "$SCRIPT_DIR/tele-notify.js" ] && cp "$SCRIPT_DIR/tele-notify.js" "$ABS_TARGET_DIR/tele-notify.js"
    [ -f "$SCRIPT_DIR/tele-sync.sh" ] && cp "$SCRIPT_DIR/tele-sync.sh" "$ABS_TARGET_DIR/tele-sync.sh"
    [ -f "$SCRIPT_DIR/tele-worker.sh" ] && cp "$SCRIPT_DIR/tele-worker.sh" "$ABS_TARGET_DIR/tele-worker.sh"
    [ -f "$SCRIPT_DIR/tele-worker.js" ] && cp "$SCRIPT_DIR/tele-worker.js" "$ABS_TARGET_DIR/tele-worker.js"
    chmod +x "$ABS_TARGET_DIR"/*.sh 2>/dev/null || true
    echo "✅ [2/5] Helper CLI proyek disinkronkan ke: $ABS_TARGET_DIR"
else
    echo "ℹ️ [2/5] Direktori target adalah sumber utama proyek, file lokal dilewati."
fi

# ------------------------------------------------------------------------------
# 3. Setup Kredensial Global (~/.config/tele-notify/config.env)
# ------------------------------------------------------------------------------
mkdir -p "$HOME/.config/tele-notify"
if [ -f "$SCRIPT_DIR/config.env" ]; then
    cp "$SCRIPT_DIR/config.env" "$HOME/.config/tele-notify/config.env"
    chmod 600 "$HOME/.config/tele-notify/config.env"
    echo "🔑 [3/5] Kredensial berhasil disinkronkan ke: ~/.config/tele-notify/config.env"
elif [ ! -f "$HOME/.config/tele-notify/config.env" ]; then
    cp "$SCRIPT_DIR/config.env.example" "$HOME/.config/tele-notify/config.env"
    chmod 600 "$HOME/.config/tele-notify/config.env"
    echo "📄 [3/5] File konfigurasi template dibuat di: ~/.config/tele-notify/config.env"
else
    chmod 600 "$HOME/.config/tele-notify/config.env"
    echo "ℹ️ [3/5] Konfigurasi kredensial global sudah ada."
fi

# ------------------------------------------------------------------------------
# 4. Injeksi & Update Petunjuk ke AGENTS.md di Folder Target
# ------------------------------------------------------------------------------
AGENTS_FILE="$ABS_TARGET_DIR/AGENTS.md"

NOTIFICATION_INSTRUCTION=$(cat << 'EOF'
## NOTIFIKASI TELEGRAM
Setiap kali Anda menyelesaikan suatu tugas atau jika menemukan kendala error yang memblokir proses, jalankan perintah terminal:
- Jika sukses (HANYA jika tugas benar-benar 100% selesai dan terverifikasi):
  tele-notify "<Judul Tugas>" --status SELESAI --detail "<Rincian 1>" --detail "<Rincian 2>"
- Jika sedang dalam proses pengerjaan (opsional untuk update progres):
  tele-notify "<Judul Tugas>" --status PROSES --detail "<Status progres pekerjaan saat ini>"
- Jika kendala:
  tele-notify "<Judul Kendala>" --status KENDALA --detail "<Penyebab>"

> ⚠️ **ATURAN MUTLAK:** JANGAN PERNAH mengirimkan status `SELESAI` saat tugas baru dimulai atau sedang dikerjakan. Status `SELESAI` HANYA untuk pekerjaan yang sudah 100% tuntas dan teruji.

## ANTREAN TUGAS TELEGRAM (TASK_QUEUE.md)
Jika terdapat file TASK_QUEUE.md di root proyek, periksa tugas berstatus [PENDING] atau [IN_PROGRESS]:
1. Ubah status menjadi [IN_PROGRESS] saat mulai mengerjakan.
2. Kerjakan tugas sesuai instruksi yang tercantum sampai selesai dan teruji.
3. Setelah BENAR-BENAR SELESAI 100%:
   - Ubah status menjadi [DONE] (atau [FAILED] jika gagal).
   - Catat ringkasan perubahannya di TASK_QUEUE.md.
   - Perbarui status di server webhook (jika terhubung).
   - Kirimkan notifikasi ke Telegram dengan status SELESAI menggunakan `tele-notify`.

## MODE EKSEKUSI & PENYIMPANAN LANGSUNG (DIRECT SAVE)
Karena seluruh riwayat pekerjaan dapat di-undo melalui Git dan IDE:
- Langsung simpan (save) dan terapkan semua perubahan file, penambahan kode, dan pembuatan fitur tanpa perlu meminta konfirmasi berulang.
- Langsung jalankan pengujian/verifikasi hingga tuntas 100%.
- Commit hasil pekerjaan yang sudah selesai ke Git.
EOF
)

if [ -f "$AGENTS_FILE" ]; then
    if grep -q "## NOTIFIKASI TELEGRAM" "$AGENTS_FILE"; then
        echo "ℹ️ [4/5] Petunjuk NOTIFIKASI TELEGRAM sudah ada di $AGENTS_FILE"
        if ! grep -q "TASK_QUEUE.md" "$AGENTS_FILE"; then
            echo "" >> "$AGENTS_FILE"
            echo "## ANTREAN TUGAS TELEGRAM (TASK_QUEUE.md)" >> "$AGENTS_FILE"
            echo "Jika terdapat file TASK_QUEUE.md di root proyek, periksa tugas berstatus [PENDING]:" >> "$AGENTS_FILE"
            echo "1. Ubah status menjadi [IN_PROGRESS] saat mulai mengerjakan." >> "$AGENTS_FILE"
            echo "2. Kerjakan tugas sesuai instruksi yang tercantum." >> "$AGENTS_FILE"
            echo "3. Setelah selesai, ubah status menjadi [DONE] (atau [FAILED] jika gagal) dan catat ringkasan perubahannya di bagian laporan tugas." >> "$AGENTS_FILE"
            echo "4. Kirimkan notifikasi ke Telegram menggunakan perintah terminal tele-notify di atas." >> "$AGENTS_FILE"
            echo "📝 [4/5] Petunjuk TASK_QUEUE.md ditambahkan ke: $AGENTS_FILE"
        fi
    else
        [ -s "$AGENTS_FILE" ] && echo "" >> "$AGENTS_FILE"
        echo "$NOTIFICATION_INSTRUCTION" >> "$AGENTS_FILE"
        echo "📝 [4/5] Petunjuk notifikasi & antrean tugas berhasil ditambahkan ke: $AGENTS_FILE"
    fi
else
    echo "$NOTIFICATION_INSTRUCTION" > "$AGENTS_FILE"
    echo "📝 [4/5] File $AGENTS_FILE berhasil dibuat beserta petunjuk notifikasi & antrean tugas."
fi

# Inisialisasi TASK_QUEUE.md jika belum ada
if [ ! -f "$ABS_TARGET_DIR/TASK_QUEUE.md" ]; then
    cat << EOF > "$ABS_TARGET_DIR/TASK_QUEUE.md"
# 📋 TASK QUEUE (Antrean Tugas Agent)

> File ini diperbarui otomatis oleh **Telegram Worker** dan dibaca oleh **Agent AI (Antigravity)**.
> Status yang valid: \`[PENDING]\`, \`[IN_PROGRESS]\`, \`[DONE]\`, \`[FAILED]\`.

---

## ⏳ Tugas Aktif

---

## ✅ Riwayat Tugas Selesai
EOF
fi

# ------------------------------------------------------------------------------
# 5. Sinkronisasi Server Webhook Otomatis (Jika Tersedia)
# ------------------------------------------------------------------------------
SERVER_DEPLOY_DIR="U:/domains/biru.co.id/public_html/tele-vs-code"
if [ -d "$SERVER_DEPLOY_DIR" ]; then
    cp "$SCRIPT_DIR/tele-vs-code/index.php" "$SERVER_DEPLOY_DIR/index.php"
    cp "$SCRIPT_DIR/tele-vs-code-1.0.0.vsix" "$SERVER_DEPLOY_DIR/tele-vs-code-1.0.0.vsix"
    echo "🔄 [5/5] File server otomatis disinkronkan ke: $SERVER_DEPLOY_DIR"
else
    echo "ℹ️ [5/5] Direktori deploy server tidak terdeteksi (dilewati)."
fi

echo ""
echo "===================================================================="
echo "🎉 Pemasangan / Pembaruan Berhasil!"
echo "===================================================================="
echo ""
echo "Pastikan '$TARGET_DIR' ada di PATH Anda."
echo "Uji coba kirim notifikasi:"
echo "   tele-notify \"Halo dari Terminal\" --status SELESAI --detail \"Pemasangan sukses\""
echo "Tarik tugas dari server Webhook PHP:"
echo "   tele-sync"
echo "Jalankan worker otomatis 1 per 1 (background):"
echo "   tele-worker"
echo ""
