#!/usr/bin/env bash
# ==============================================================================
# Telegram Agent Listener CLI Wrapper for Linux / macOS
# ==============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if command -v node >/dev/null 2>&1; then
    exec node "$SCRIPT_DIR/tele-listener.js" "$@"
else
    echo "❌ Error: Node.js diperlukan untuk menjalankan tele-listener."
    echo "Silakan install Node.js (v18+) terlebih dahulu."
    exit 1
fi

