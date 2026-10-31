<#
.SYNOPSIS
    Universal Telegram Notification CLI for Windows PowerShell
.EXAMPLE
    .\tele-notify.ps1 -Title "Pekerjaan Selesai" -Status "SELESAI" -Details @("Fitur A selesai", "Database migrasi berhasil")
#>
param(
    [Parameter(Position=0, Mandatory=$true)]
    [string]$Title,

    [Parameter(Position=1)]
    [ValidateSet("SELESAI", "KENDALA", "INFO", "PROGRESS")]
    [string]$Status = "SELESAI",

    [Parameter(Position=2)]
    [string[]]$Details = @(),

    [string]$Token = $env:TELEGRAM_BOT_TOKEN,
    [string]$ChatId = $env:TELEGRAM_CHAT_ID
)

# Check config in current folder (config.env, .env.telegram, .env)
$ConfigCandidates = @("config.env", ".env.telegram", ".env", "$HOME\.config\tele-notify\config.env", "$HOME\.tele-notify.env")
foreach ($cfg in $ConfigCandidates) {
    if ((-not $Token -or -not $ChatId) -and (Test-Path $cfg)) {
        Get-Content $cfg | ForEach-Object {
            if ($_ -match "^\s*TELEGRAM_BOT_TOKEN\s*=\s*(.*)") { if (-not $Token) { $Token = $matches[1].Trim('"' + "'") } }
            if ($_ -match "^\s*TELEGRAM_CHAT_ID\s*=\s*(.*)") { if (-not $ChatId) { $ChatId = $matches[1].Trim('"' + "'") } }
        }
    }
}

if (-not $Token -or -not $ChatId) {
    Write-Error "TELEGRAM_BOT_TOKEN atau TELEGRAM_CHAT_ID belum diatur!"
    exit 1
}

$Emoji = switch ($Status) {
    "SELESAI" { "✅" }
    "KENDALA" { "🚨" }
    "PROGRESS" { "⏳" }
    default { "ℹ️" }
}

$Timestamp = (Get-Date).ToString("dd-MM-yyyy HH:mm:ss")
$Project = Split-Path -Leaf (Get-Location)

$Msg = "$Emoji *$Title: $Status*`n`n" +
       "📁 *Proyek:* `$Project`n" +
       "🕒 *Waktu:* $Timestamp`n" +
       "👤 *Operator:* $env:USERNAME@$env:COMPUTERNAME`n"

if ($Details.Count -gt 0) {
    $Msg += "`n📋 *Detail:*`n"
    foreach ($d in $Details) {
        $Msg += "• $d`n"
    }
}

$Msg += "`n🤖 _Dikirim via PowerShell tele-notify_"

$Uri = "https://api.telegram.org/bot$Token/sendMessage"
$Body = @{
    chat_id = $ChatId
    parse_mode = "Markdown"
    text = $Msg
    disable_web_page_preview = $true
}

try {
    $Response = Invoke-RestMethod -Uri $Uri -Method Post -Body $Body
    if ($Response.ok) {
        Write-Host "✅ Notifikasi Telegram berhasil terkirim." -ForegroundColor Green
    } else {
        Write-Error "Gagal mengirim ke Telegram."
    }
} catch {
    Write-Error "Error: $_"
}

