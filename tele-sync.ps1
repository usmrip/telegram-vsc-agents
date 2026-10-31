# ==============================================================================
# Telegram Task Queue Sync (Pull) Script (PowerShell)
# Menarik antrean tugas berstatus PENDING dari server biru.co.id ke TASK_QUEUE.md
# ==============================================================================

param(
    [string]$ProjectDir = "."
)

$QueueFile = Join-Path $ProjectDir "TASK_QUEUE.md"
$WebhookUrl = $env:AGENTS_TELE_WEBHOOK_URL
$SecretKey = $env:AGENTS_TELE_SECRET

$LocalEnv = Join-Path $ProjectDir "config.env"
$GlobalConf = Join-Path $env:USERPROFILE ".gemini\telegram-vsc-agents\config.env"
if (-not (Test-Path $GlobalConf)) {
    $GlobalConf = Join-Path $env:USERPROFILE ".gemini\telegram-dev-notifier\config.env"
}

if (Test-Path $LocalEnv) {
    Get-Content $LocalEnv | ForEach-Object {
        if ($_ -match "^\s*AGENTS_TELE_WEBHOOK_URL\s*=\s*(.*)") { if (-not $WebhookUrl) { $WebhookUrl = $matches[1].Trim('"' + "'") } }
        if ($_ -match "^\s*AGENTS_TELE_SECRET\s*=\s*(.*)") { if (-not $SecretKey) { $SecretKey = $matches[1].Trim('"' + "'") } }
    }
}

if (Test-Path $GlobalConf) {
    Get-Content $GlobalConf | ForEach-Object {
        if ($_ -match "^\s*AGENTS_TELE_WEBHOOK_URL\s*=\s*(.*)") { if (-not $WebhookUrl) { $WebhookUrl = $matches[1].Trim('"' + "'") } }
        if ($_ -match "^\s*AGENTS_TELE_SECRET\s*=\s*(.*)") { if (-not $SecretKey) { $SecretKey = $matches[1].Trim('"' + "'") } }
    }
}

if (-not $WebhookUrl) { $WebhookUrl = "https://biru.co.id/tele-vs-code/webhook" }
if (-not $WebhookUrl.EndsWith("/") -and -not $WebhookUrl.EndsWith(".php")) { $WebhookUrl += "/" }
if (-not $SecretKey) {
    Write-Host "[ERROR] AGENTS_TELE_SECRET belum dikonfigurasi di config.env!" -ForegroundColor Red
    exit 1
}

# Inisialisasi TASK_QUEUE.md jika belum ada
if (-not (Test-Path $QueueFile)) {
    $Header = "# TASK QUEUE`r`n`r`n## Tugas Aktif`r`n`r`n## Riwayat Tugas Selesai`r`n"
    Set-Content -Path $QueueFile -Value $Header -Encoding UTF8
}

$ApiUrl = $WebhookUrl + "?action=pull&secret=" + [System.Uri]::EscapeDataString($SecretKey) + "&format=markdown"

try {
    $res = (Invoke-RestMethod -Uri $ApiUrl -Method Get -TimeoutSec 10)
    if ($res -is [string] -and $res.Contains("### [PENDING]")) {
        $NewTasks = $res
        $Content = Get-Content $QueueFile -Raw -Encoding UTF8
        $Marker = "## Tugas Aktif"
        if (-not $Content.Contains($Marker)) {
            $Marker = "## ⏳ Tugas Aktif"
        }
        if ($Content.Contains($Marker)) {
            $Updated = $Content.Replace($Marker, "$Marker`r`n`r`n$NewTasks")
            Set-Content -Path $QueueFile -Value $Updated -Encoding UTF8
        } else {
            Add-Content -Path $QueueFile -Value "`r`n$NewTasks" -Encoding UTF8
        }
        Write-Host "[SUCCESS] Berhasil menarik tugas baru ke $QueueFile" -ForegroundColor Green
        Write-Host $NewTasks
    } else {
        Write-Host "[INFO] Tidak ada antrean tugas baru di server." -ForegroundColor Yellow
    }
} catch {
    Write-Host "[WARN] Gagal menghubungi server: $($_.Exception.Message)" -ForegroundColor Red
}
