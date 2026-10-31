<#
.SYNOPSIS
    Telegram Agent Listener CLI Wrapper for Windows PowerShell
#>
param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$ScriptArgs
)

if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
    Write-Error "Node.js diperlukan untuk menjalankan tele-listener. Silakan install Node.js (v18+) terlebih dahulu."
    exit 1
}

$ScriptPath = Join-Path $PSScriptRoot "tele-listener.js"
& node $ScriptPath @ScriptArgs

