#!/usr/bin/env bash
# ==============================================================================
# Testing Suite untuk Telegram Webhook & Antrean Tugas (TASK_QUEUE)
# ==============================================================================
set -e

WEBHOOK_URL="${AGENTS_TELE_WEBHOOK_URL:-https://biru.co.id/tele-vs-code/webhook}"
SECRET="${AGENTS_TELE_SECRET:-}"
if [ -z "$SECRET" ] && [ -f "config.env" ]; then
    SECRET=$(grep "^AGENTS_TELE_SECRET=" config.env | head -n1 | cut -d'=' -f2 | tr -d '"' | tr -d "'")
fi

echo "============================================================"
echo "🧪 TELEGRAM WEBHOOK & AGENT QUEUE TEST SUITE"
echo "============================================================"

echo ""
echo "[1/3] Menguji Endpoint Webhook Server..."
STATUS_RES=$(curl -s -L -f "${WEBHOOK_URL}?action=status&secret=${SECRET}" || true)
if [ -n "$STATUS_RES" ]; then
    echo "✅ Server Status OK!"
    echo "   Respon: $STATUS_RES"
else
    echo "❌ Gagal menghubungi server webhook."
fi

echo ""
echo "[2/3] Menguji Sinkronisasi Tugas (tele-sync)..."
./tele-sync.sh .

echo ""
echo "[3/3] Memeriksa File TASK_QUEUE.md Lokal..."
if [ -f "TASK_QUEUE.md" ]; then
    echo "✅ TASK_QUEUE.md ditemukan!"
    PENDING_COUNT=$(grep -c '### \[PENDING\]' TASK_QUEUE.md || true)
    DONE_COUNT=$(grep -c '### \[DONE\]' TASK_QUEUE.md || true)
    echo "   Total Tugas PENDING: $PENDING_COUNT"
    echo "   Total Tugas DONE: $DONE_COUNT"
else
    echo "ℹ️ TASK_QUEUE.md belum dibuat di proyek lokal."
fi

echo ""
echo "============================================================"
echo "🎉 Pengujian selesai."
echo "============================================================"

