param(
    [string]$WebhookUrl = "https://biru.co.id/tele-vs-code/webhook",
    [string]$Secret = ""
)

if (-not $Secret) {
    if ($env:AGENTS_TELE_SECRET) {
        $Secret = $env:AGENTS_TELE_SECRET
    } elseif (Test-Path "config.env") {
        foreach ($line in (Get-Content "config.env")) {
            if ($line -match '^AGENTS_TELE_SECRET=["'']?([^"'']+)["'']?') {
                $Secret = $matches[1]
                break
            }
        }
    }
}

Write-Host "============================================================" -ForegroundColor Cyan
Write-Host "TELEGRAM WEBHOOK & AGENT QUEUE TEST SUITE" -ForegroundColor Cyan
Write-Host "============================================================" -ForegroundColor Cyan

# 1. Test Server Endpoint Availability
Write-Host "`n[1/3] Menguji Endpoint Webhook Server..." -ForegroundColor Yellow
try {
    $res = Invoke-RestMethod -Uri "$WebhookUrl`?action=status&secret=$Secret" -Method Get -TimeoutSec 10
    if ($res.ok) {
        Write-Host "[OK] Server Status OK!" -ForegroundColor Green
        Write-Host "   Antrean di Server: PENDING=$($res.summary.PENDING), IN_PROGRESS=$($res.summary.IN_PROGRESS), DONE=$($res.summary.DONE)" -ForegroundColor Gray
    } else {
        Write-Host "[WARN] Respon server tidak valid." -ForegroundColor Red
    }
} catch {
    Write-Host "[FAIL] Gagal menghubungi server webhook: $_" -ForegroundColor Red
}

# 2. Test Pull Task Queue (tele-sync)
Write-Host "`n[2/3] Menguji Sinkronisasi Tugas (tele-sync)..." -ForegroundColor Yellow
cmd.exe /c "tele-sync.cmd ."

# 3. Test TASK_QUEUE.md validation
Write-Host "`n[3/3] Memeriksa File TASK_QUEUE.md Lokal..." -ForegroundColor Yellow
if (Test-Path "TASK_QUEUE.md") {
    $content = Get-Content "TASK_QUEUE.md" -Raw
    $pendingMatches = [regex]::Matches($content, "### \[PENDING\]")
    $doneMatches = [regex]::Matches($content, "### \[DONE\]")
    Write-Host "[OK] TASK_QUEUE.md ditemukan!" -ForegroundColor Green
    Write-Host "   Total Tugas PENDING: $($pendingMatches.Count)" -ForegroundColor Gray
    Write-Host "   Total Tugas DONE: $($doneMatches.Count)" -ForegroundColor Gray
} else {
    Write-Host "[INFO] TASK_QUEUE.md belum dibuat di proyek lokal." -ForegroundColor Yellow
}

Write-Host "`n============================================================" -ForegroundColor Cyan
Write-Host "Pengujian selesai." -ForegroundColor Cyan
Write-Host "============================================================" -ForegroundColor Cyan
