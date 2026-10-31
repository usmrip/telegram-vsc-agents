#!/usr/bin/env bash
set -e
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
echo "=============================================================================="
echo "[BUILD] Memaketkan Ekstensi VS Code Private (.VSIX)"
echo "=============================================================================="
node "$DIR/vscode-extension/build-vsix.js"
echo ""
echo "Selesai! File VSIX siap diinstall: tele-vs-code-1.0.0.vsix"
echo "Jalankan './install-vsix.sh' untuk langsung menginstal ke VS Code."
echo "=============================================================================="

