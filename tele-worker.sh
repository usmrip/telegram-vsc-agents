#!/usr/bin/env bash
# ==============================================================================
# Telegram Task Worker CLI Wrapper for Linux / macOS
# ==============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if command -v node >/dev/null 2>&1; then
    exec node "$SCRIPT_DIR/tele-worker.js" "$@"
else
    echo "❌ Error: Node.js diperlukan untuk menjalankan tele-worker."
    exit 1
fi

