#!/usr/bin/env bash
# ==============================================================================
# tele-notify: Universal Telegram Notification CLI for AI Coding & Project Tasks
# ==============================================================================
set -e

# Default values
STATUS="SELESAI"
TITLE=""
PROJECT_NAME="$(basename "$PWD" 2>/dev/null || echo "Unknown Project")"
TIMESTAMP="$(date '+%d-%m-%Y %H:%M:%S %Z')"
DETAILS=()
CUSTOM_TOKEN=""
CUSTOM_CHAT_ID=""
READ_STDIN=false

# Auto-detect git branch if inside a git repository
GIT_BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "")"

# ------------------------------------------------------------------------------
# 1. Load Configurations (Priority: CLI args > .env.telegram > .env > ~/.config/tele-notify/config.env > env vars)
# ------------------------------------------------------------------------------
GLOBAL_CONF="$HOME/.config/tele-notify/config.env"
if [ -f "$GLOBAL_CONF" ]; then
    # shellcheck disable=SC1090
    source "$GLOBAL_CONF"
fi

if [ -f ".env.telegram" ]; then
    # shellcheck disable=SC1091
    source ".env.telegram"
elif [ -f ".env" ]; then
    # Extract only TELEGRAM variables safely
    ENV_BOT=$(grep -E '^TELEGRAM_BOT_TOKEN=' .env 2>/dev/null | cut -d '=' -f2- | tr -d '"'\'' ' || true)
    ENV_CHAT=$(grep -E '^TELEGRAM_CHAT_ID=' .env 2>/dev/null | cut -d '=' -f2- | tr -d '"'\'' ' || true)
    [ -n "$ENV_BOT" ] && TELEGRAM_BOT_TOKEN="$ENV_BOT"
    [ -n "$ENV_CHAT" ] && TELEGRAM_CHAT_ID="$ENV_CHAT"
fi

# ------------------------------------------------------------------------------
# 2. Parse CLI Arguments
# ------------------------------------------------------------------------------
show_help() {
    cat << 'EOF'
Penggunaan:
  tele-notify "Judul Tugas" [opsi]
  tele-notify --title "Judul Tugas" --status SELESAI --detail "Poin 1" --detail "Poin 2"
  command | tele-notify --stdin --title "Hasil Build" --status KENDALA

Opsi:
  -t, --title <teks>       Judul pekerjaan / tugas
  -s, --status <status>    Status: SELESAI (default), KENDALA, INFO, PROGRESS
  -d, --detail <teks>      Tambahkan rincian / detail pekerjaan (bisa diulang)
  -p, --project <nama>     Nama proyek (default: nama folder saat ini)
  --stdin                  Baca output tambahan dari stdin (pipe)
  --token <token>          Override Telegram Bot Token
  --chat-id <id>           Override Telegram Chat / Group ID
  -h, --help               Tampilkan bantuan ini
EOF
    exit 0
}

# If first arg is not a flag, treat it as title
if [ $# -gt 0 ] && [[ "$1" != -* ]]; then
    TITLE="$1"
    shift
fi

while [[ $# -gt 0 ]]; do
    case "$1" in
        -t|--title)
            TITLE="$2"
            shift 2
            ;;
        -s|--status)
            STATUS="$(echo "$2" | tr '[:lower:]' '[:upper:]')"
            shift 2
            ;;
        -d|--detail)
            DETAILS+=("$2")
            shift 2
            ;;
        -p|--project)
            PROJECT_NAME="$2"
            shift 2
            ;;
        --token)
            CUSTOM_TOKEN="$2"
            shift 2
            ;;
        --chat-id)
            CUSTOM_CHAT_ID="$2"
            shift 2
            ;;
        --stdin)
            READ_STDIN=true
            shift
            ;;
        -h|--help)
            show_help
            ;;
        *)
            # Extra positional args become details
            DETAILS+=("$1")
            shift
            ;;
    esac
done

# Apply token & chat ID overrides if specified
[ -n "$CUSTOM_TOKEN" ] && TELEGRAM_BOT_TOKEN="$CUSTOM_TOKEN"
[ -n "$CUSTOM_CHAT_ID" ] && TELEGRAM_CHAT_ID="$CUSTOM_CHAT_ID"

if [ -z "$TELEGRAM_BOT_TOKEN" ] || [ -z "$TELEGRAM_CHAT_ID" ]; then
    echo "❌ Error: TELEGRAM_BOT_TOKEN atau TELEGRAM_CHAT_ID belum diatur!"
    echo "💡 Solusi: Simpan token & chat_id di ~/.config/tele-notify/config.env atau berikan parameter --token & --chat-id"
    exit 1
fi

if [ -z "$TITLE" ]; then
    TITLE="Pembaruan Proyek"
fi

# Read stdin if pipe is detected or --stdin flag is set
STDIN_CONTENT=""
if [ "$READ_STDIN" = true ] || [ ! -t 0 ]; then
    if [ ! -t 0 ]; then
        STDIN_CONTENT=$(cat | tail -n 25)
    fi
fi

# ------------------------------------------------------------------------------
# 3. Format Telegram Message
# ------------------------------------------------------------------------------
case "$STATUS" in
    SELESAI|DONE|SUCCESS)
        HEADER_EMOJI="✅"
        STATUS_LABEL="SELESAI BERHASIL"
        ;;
    KENDALA|ERROR|FAIL|FAILED)
        HEADER_EMOJI="🚨"
        STATUS_LABEL="TERJADI KENDALA"
        ;;
    PROGRESS|PENDING|PROSES)
        HEADER_EMOJI="⏳"
        STATUS_LABEL="SEDANG BERJALAN"
        ;;
    *)
        HEADER_EMOJI="ℹ️"
        STATUS_LABEL="$STATUS"
        ;;
esac

MESSAGE="${HEADER_EMOJI} *${TITLE}: ${STATUS_LABEL}*

📁 *Proyek:* \`${PROJECT_NAME}\`"

if [ -n "$GIT_BRANCH" ]; then
    MESSAGE="${MESSAGE} (cabang: \`${GIT_BRANCH}\`)"
fi

MESSAGE="${MESSAGE}
🕒 *Waktu:* ${TIMESTAMP}
👤 *Operator:* \`${USER}@$(hostname)\`
"

if [ ${#DETAILS[@]} -gt 0 ]; then
    MESSAGE="${MESSAGE}
📋 *Detail Pekerjaan:*
"
    for item in "${DETAILS[@]}"; do
        MESSAGE="${MESSAGE}• ${item}
"
    done
fi

if [ -n "$STDIN_CONTENT" ]; then
    MESSAGE="${MESSAGE}
📜 *Output Cuplikan Log:*
\`\`\`
${STDIN_CONTENT}
\`\`\`
"
fi

MESSAGE="${MESSAGE}
🤖 _Dikirim otomatis oleh Antigravity Dev Notifier_"

# ------------------------------------------------------------------------------
# 4. Send Message via Telegram Bot API
# ------------------------------------------------------------------------------
RESPONSE=$(curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
    -d "chat_id=${TELEGRAM_CHAT_ID}" \
    -d "parse_mode=Markdown" \
    --data-urlencode "text=${MESSAGE}" \
    -d "disable_web_page_preview=true")

SUCCESS=$(echo "$RESPONSE" | grep -o '"ok":true' || true)

if [ -n "$SUCCESS" ]; then
    echo "✅ Notifikasi Telegram berhasil dikirim ke grup [Chat ID: ${TELEGRAM_CHAT_ID}]."
    exit 0
else
    echo "❌ Gagal mengirim notifikasi Telegram!"
    echo "Respon API: $RESPONSE"
    exit 1
fi

