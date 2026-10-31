#!/usr/bin/env bash
# ==============================================================================
# Telegram Task Queue Sync (Tarik tugas dari Webhook PHP ke TASK_QUEUE.md)
# ==============================================================================
set -e

PROJECT_DIR="${1:-.}"
QUEUE_FILE="$PROJECT_DIR/TASK_QUEUE.md"

# Load config (Priority: CLI/env > .env.telegram > .env > ~/.config/tele-notify/config.env)
GLOBAL_CONF="$HOME/.config/tele-notify/config.env"
[ -f "$GLOBAL_CONF" ] && source "$GLOBAL_CONF"

if [ -f "$PROJECT_DIR/.env.telegram" ]; then
    source "$PROJECT_DIR/.env.telegram"
elif [ -f "$PROJECT_DIR/.env" ]; then
    ENV_URL=$(grep -E '^AGENTS_TELE_WEBHOOK_URL=' "$PROJECT_DIR/.env" 2>/dev/null | cut -d '=' -f2- | tr -d '"'\'' ' || true)
    ENV_SEC=$(grep -E '^AGENTS_TELE_SECRET=' "$PROJECT_DIR/.env" 2>/dev/null | cut -d '=' -f2- | tr -d '"'\'' ' || true)
    [ -n "$ENV_URL" ] && AGENTS_TELE_WEBHOOK_URL="$ENV_URL"
    [ -n "$ENV_SEC" ] && AGENTS_TELE_SECRET="$ENV_SEC"
fi

WEBHOOK_URL="${AGENTS_TELE_WEBHOOK_URL:-https://biru.co.id/tele-vs-code/webhook}"
# Pastikan berakhiran slash jika path direktori
[[ "$WEBHOOK_URL" != */ ]] && [[ "$WEBHOOK_URL" != *.php ]] && WEBHOOK_URL="${WEBHOOK_URL}/"

SECRET_KEY="${AGENTS_TELE_SECRET:-}"
if [ -z "$SECRET_KEY" ]; then
    echo "❌ [ERROR] AGENTS_TELE_SECRET belum dikonfigurasi di config.env!"
    exit 1
fi

# Inisialisasi TASK_QUEUE.md jika belum ada
if [ ! -f "$QUEUE_FILE" ]; then
    PROJECT_NAME=$(basename "$(cd "$PROJECT_DIR" && pwd)")
    cat << EOF > "$QUEUE_FILE"
# 📋 TASK QUEUE (Antrean Tugas Agent) - Proyek: $PROJECT_NAME

> File ini diperbarui otomatis oleh **Telegram Sync** dan dibaca oleh **Agent AI (Antigravity)**.
> Status yang valid: \`[PENDING]\`, \`[IN_PROGRESS]\`, \`[DONE]\`, \`[FAILED]\`.

---

## ⏳ Tugas Aktif

---

## ✅ Riwayat Tugas Selesai
EOF
fi

# Tarik tugas dari server via curl (dengan -L untuk mengikuti redirect)
API_URL="${WEBHOOK_URL}?action=pull&secret=${SECRET_KEY}&format=markdown"
NEW_TASKS=$(curl -s -L -f "$API_URL" || true)

if echo "$NEW_TASKS" | grep -q '### \[PENDING\]'; then
    # Masukkan ke bawah ## ⏳ Tugas Aktif
    TEMP_FILE=$(mktemp)
    awk -v tasks="$NEW_TASKS" '
        /## ⏳ Tugas Aktif/ {
            print $0 RS RS tasks;
            next
        }
        { print }
    ' "$QUEUE_FILE" > "$TEMP_FILE"
    mv "$TEMP_FILE" "$QUEUE_FILE"

    echo "📥 Berhasil menarik tugas baru dari server biru.co.id ke: $QUEUE_FILE"
    echo "$NEW_TASKS"
else
    echo "✨ Tidak ada antrean tugas baru di server biru.co.id."
fi
