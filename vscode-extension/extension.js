// ==============================================================================
// Telegram Dev Notifier - Private VS Code Extension
// Integrasi Notifikasi Telegram & Task Queue Bridge dengan Worker Otomasi Latar Belakang
// Multi-Workspace, Auto-Setup .vscode/tele-vs-code, & Server Biru Integration
// ==============================================================================

const dns = require('dns');
if (typeof dns.setDefaultResultOrder === 'function') {
  dns.setDefaultResultOrder('ipv4first');
}

const vscode = require('vscode');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { execSync } = require('child_process');

function parseEnvFile(filePath) {
  if (!fs.existsSync(filePath)) return {};
  try {
    const content = fs.readFileSync(filePath, 'utf-8');
    const result = {};
    for (const line of content.split(/\r?\n/)) {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith('#')) continue;
      const match = trimmed.match(/^([A-Za-z_0-9]+)\s*=\s*(.*)$/);
      if (match) {
        let val = match[2].trim();
        if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) {
          val = val.slice(1, -1);
        }
        result[match[1]] = val;
      }
    }
    return result;
  } catch (e) {
    return {};
  }
}



// ==============================================================================
// Helper Safe Fetch dengan Auto-Retry & Perlindungan Stale Socket Keep-Alive
// ==============================================================================
async function safeFetch(url, options = {}, maxRetries = 2) {
  let lastErr = null;
  const timeoutMs = options.timeout || 15000;

  for (let attempt = 0; attempt <= maxRetries; attempt++) {
    try {
      const fetchHeaders = {
        'Connection': 'close',
        'User-Agent': 'Tele-vs-Code/1.0.15',
        ...(options.headers || {})
      };
      const opt = {
        ...options,
        headers: fetchHeaders,
        signal: options.signal || (typeof AbortSignal !== 'undefined' && AbortSignal.timeout ? AbortSignal.timeout(timeoutMs) : undefined)
      };

      const res = await fetch(url, opt);
      return res;
    } catch (err) {
      lastErr = err;
      if (err.name === 'AbortError' && options.signal && options.signal.aborted) {
        throw err;
      }
      if (attempt < maxRetries) {
        await new Promise(resolve => setTimeout(resolve, 500 * (attempt + 1)));
      }
    }
  }

  const causeDetail = lastErr && lastErr.cause
    ? (lastErr.cause.code || lastErr.cause.message || '')
    : '';
  const errorMsg = causeDetail && !lastErr.message.includes(causeDetail)
    ? `${lastErr.message} (${causeDetail})`
    : (lastErr ? lastErr.message : 'Network request failed');

  const wrapped = new Error(errorMsg);
  if (lastErr && lastErr.cause) wrapped.cause = lastErr.cause;
  throw wrapped;
}

// ==============================================================================
// Sistem Dual Bahasa / Localization (ID & EN)
// ==============================================================================
const I18N = {
  id: {
    appName: "Tele-vs-Code",
    workerActive: "Worker Otomasi Aktif",
    workerPaused: "Dijeda",
    setupRequired: "Setup Diperlukan",
    idleStatus: "Idle",
    processingStatus: "Memproses Tugas...",
    warningStatus: "Peringatan",
    statusBarSetupTooltip: "Tele-vs-Code: Belum terhubung ke Server Biru. Klik untuk membuka Setup Wizard.",
    statusBarPausedTooltip: "Tele-vs-Code ({name}{code})\nWorker Otomatis: NONAKTIF / DIJEDA\nKlik untuk membuka menu",
    statusBarWorkingTooltip: "Tele-vs-Code ({name}{code})\nStatus: SEDANG BEKERJA (PROSES)\nAktivitas: {detail}\nKlik untuk melihat log",
    statusBarWarningTooltip: "⚠️ Peringatan Tele-vs-Code:\n{detail}\nKlik untuk membuka menu atau periksa log",
    statusBarIdleTooltip: "Tele-vs-Code ({name}{code})\nStatus: IDLE / SIAP (Memantau setiap {interval}s)\nFolder: {folder}\nKlik untuk membuka menu",
    statusBarAgentTooltip: "AI Agent Handler: {handler} (Klik untuk memilih atau isi manual)",
    switchLanguageTitle: "🌐 Pilih Bahasa Tampilan (Interface Language)",
    switchLanguageSuccess: "✅ Bahasa antarmuka diubah ke Bahasa Indonesia (ID).",
    selectAgentTitle: "🤖 Pengaturan AI Agent Handler (Tele-vs-Code)",
    agentPlaceholder: "Agent Handler Aktif Saat Ini: {agent}",
    agentNoneLabel: "$(circle-slash) [Kosong / Nonaktif] Tidak Ada Eksekusi Otomatis",
    agentNoneDesc: "Tugas hanya dicatat ke TASK_QUEUE.md tanpa menjalankan agent",
    agentNoneDetail: "Pilihan aman jika ingin mengerjakan sendiri secara manual di IDE Chat",
    agentAutoLabel: "$(sparkle) [Auto] Deteksi Otomatis Agen Terbaik yang Terpasang",
    agentAutoDesc: "Otomatis memilih agent pertama yang terdeteksi di sistem (Linux/macOS/Windows)",
    agentAutoDetail: "Mencari CLI: agy, claude, cline, roo, gemini, copilot di PATH atau ekstensi VS Code",
    agentIdeLabel: "$(comment-discussion) [Agent IDE] Eksekusi Langsung di Chat IDE (VS Code / Antigravity / Copilot / Gemini)",
    agentIdeDesc: "Solusi tanpa CLI: Otomatis salin prompt ke clipboard dan buka Chat IDE",
    agentIdeDetail: "Sangat direkomendasikan jika CLI agents (agy/claude/gemini) belum terpasang di komputer",
    agentIdeToast: "📋 [Agent IDE] Prompt tugas {id} disalin ke clipboard dan Chat dibuka! Tempel (Ctrl+V) dan kirim ke Agent IDE.",
    agentInstalledHeader: "Agen AI Terdeteksi di Sistem / VS Code",
    agentOtherHeader: "Agen AI Lainnya",
    agentCustomHeader: "Kustom / Input Manual",
    agentManualLabel: "$(pencil) [Isi Manual] Ketik Perintah Terminal / Custom CLI Sendiri...",
    agentManualDesc: "Masukkan baris perintah eksekusi agent kustom",
    agentManualDetail: "Mendukung placeholder {prompt}, {instruction}, {task_id}. Cocok untuk binary Linux/macOS kustom.",
    agentManualTitle: "Ketik Perintah Terminal Kustom untuk AI Agent",
    agentManualPrompt: "Contoh: agy --dangerously-skip-permissions -p \"{prompt}\" atau /usr/local/bin/my-agent --task \"{instruction}\"",
    agentEmptyToast: "ℹ️ Agent Handler dikosongkan. Tugas dari Telegram hanya akan dicatat ke TASK_QUEUE.md tanpa eksekusi otomatis.",
    menuAgentCronInterval: "Atur Interval Pemantauan Berkala Agent...",
    menuAgentCronIntervalDesc: "Jeda waktu pemeriksaan TASK_QUEUE.md (Saat ini: {interval} menit)",
    setCronIntervalTitle: "⏱️ Atur Interval Pemantauan Berkala Agent",
    setCronIntervalPrompt: "Masukkan jeda waktu pemeriksaan berkala TASK_QUEUE.md oleh AI Agent (1 - 60 menit):",
    setCronIntervalValidate: "Masukkan angka bulat antara 1 sampai 60 menit",
    setCronIntervalToast: "⏱️ Interval pemantauan berkala AI Agent disetel ke: {interval} menit.",
    agentScheduleStarted: "🚀 Jadwal pemantauan berkala AI Agent aktif ({agent}, setiap {interval}m).",
    agentSetToast: "✅ AI Agent Handler disetel ke: {agent}",
    agentRunningToast: "🤖 {agent} sedang mengeksekusi tugas {id}...",
    agentNoAgentWarning: "⚠️ [Tele-vs-Code] Tidak ditemukan AI Agent terpasang di sistem. Menggunakan pencatatan antrean saja.",
    agentCustomEmptyWarning: "⚠️ [Tele-vs-Code] Custom Agent Command masih kosong. Atur via menu \"Pilih AI Agent Handler\".",
    agentInactiveWarning: "⚠️ Agent Handler saat ini kosong / dinonaktifkan. Pilih agen terlebih dahulu untuk mengeksekusi tugas {id}.",
    newTaskToast: "⚡ [{id}] Tugas Baru: \"{brief}\" (Prompt disalin ke clipboard)",
    newTaskDoneToast: "✅ [{id}] Tugas Telegram Selesai: \"{brief}\"",
    btnRunVia: "Jalankan via {agent}",
    btnSelectAgent: "Pilih Agent Handler",
    btnOpenQueue: "Buka TASK_QUEUE",
    btnClose: "Close",
    menuTitle: "⚡ Menu Tele-vs-Code ({name})",
    menuSetupTitle: "⚠️ Setup Tele-vs-Code",
    menuSetFolder: "Atur Folder Workspace / Working Directory",
    menuSetFolderDesc: "Pilih folder tempat TASK_QUEUE.md dan file aturan AI Agent dikelola",
    menuRunNext: "Kerjakan Antrean Tugas Berikutnya",
    menuRunNextDesc: "Salin instruksi tugas aktif di TASK_QUEUE & kirim ke AI Chat",
    menuTasks: "Antrean Tugas (Tasks & Jobs)...",
    menuTasksDesc: "Tambah tugas baru, buka TASK_QUEUE.md, & pantau status",
    menuWorkerTerminal: "Jalankan Worker di Terminal",
    menuWorkerTerminalDesc: "Buka terminal tele-worker untuk eksekusi skrip mandiri",
    menuWorkerControl: "Kontrol Worker & Durasi Polling...",
    menuWorkerControlActive: "🟢 Aktif memantau setiap {interval} detik",
    menuWorkerControlPaused: "⚪ Dijeda / Nonaktif",
    menuAgentHandler: "Pilih AI Agent Handler...",
    menuAgentActiveDesc: "Agent aktif: {agent}",
    menuAgentInactiveDesc: "⚪ Nonaktif / Kosong (Hanya antrean)",
    menuUploadMedia: "Unggah File / Media Dokumentasi ke Server Biru",
    menuUploadMediaDesc: "Unggah gambar, tangkapan layar, atau dokumen proyek ke server",
    menuSwitchLang: "Ganti Bahasa / Switch Language (ID / EN)",
    menuTest: "Pengujian (IDE & Telegram)...",
    menuTestDesc: "Uji kirim notifikasi, uji tugas dari IDE, & diagnostik koneksi",
    menuLog: "Lihat Log Aktivitas Tele-vs-Code",
    menuLogDesc: "Buka panel log aktivitas worker & eksekusi tugas real-time",
    menuDashboard: "Buka Web Dashboard",
    menuSetup: "Hubungkan ke Server Biru (Setup Wizard)",
    menuSetupDesc: "Atur API Secret Key & pilih proyek dari server Biru",
    menuWhoami: "Siapa Saya? (Identifikasi Workspace)",
    tasksMenuTitle: "📋 Menu Tasks & Jobs ({name})",
    tasksMenuPlaceholder: "Pilih aksi manajemen tugas:",
    tasksRunNext: "$(play-circle) Kerjakan Antrean Tugas Berikutnya (Kirim ke Chat AI)",
    tasksRunNextDesc: "Salin instruksi tugas aktif di TASK_QUEUE & buka chat AI",
    tasksAdd: "$(add) Tambah Tugas Baru / Kirim Perintah ke Antrean",
    tasksAddDesc: "Kirim tugas ke antrean TASK_QUEUE.md & notifikasi Telegram",
    tasksOpen: "$(file-text) Buka TASK_QUEUE.md",
    tasksOpenDesc: "Lihat daftar tugas antrean dan riwayat selesai",
    tasksTerminal: "$(terminal) Jalankan Autonomous CLI Worker di Terminal",
    tasksTerminalDesc: "Buka terminal tele-worker untuk eksekusi skrip mandiri",
    tasksBack: "$(arrow-left) Kembali ke Menu Utama",
    workerMenuTitle: "⚙️ Kontrol Worker Otomatis & Polling ({name})",
    workerPause: "$(debug-pause) Jeda Worker Otomatis (Pause Auto-Pull)",
    workerStart: "$(play) Aktifkan Worker Otomatis (Start Auto-Pull)",
    workerPullNow: "$(cloud-download) Tarik Antrean Sekarang (Manual Pull Seketika)",
    workerPullNowDesc: "Sinkronisasi instan task dari server webhook ke TASK_QUEUE.md",
    workerInterval: "$(watch) Atur Durasi Refresh Polling Worker",
    workerIntervalDesc: "Interval saat ini: {interval} detik (Klik untuk mengubah)",
    testMenuTitle: "🧪 Menu Pengujian IDE & Telegram ({name})",
    testMenuPlaceholder: "Pilih pengujian yang ingin dijalankan:",
    testSuccess: "$(bell) Test Notifikasi Telegram: SELESAI",
    testSuccessDesc: "Kirim notifikasi laporan tugas 100% tuntas ke Telegram",
    testProgress: "$(zap) Test Notifikasi Telegram: PROSES",
    testProgressDesc: "Kirim notifikasi laporan progres pekerjaan ke Telegram",
    testError: "$(warning) Test Notifikasi Telegram: KENDALA (Warning)",
    testErrorDesc: "Kirim notifikasi kendala/error ke Telegram & picu popup warning",
    testIde: "$(sparkle) Test Buat Tugas Baru dari IDE",
    testIdeDesc: "Simulasi kirim tugas dari IDE Chat/Command & verifikasi ke server",
    testDiag: "$(heart) Self-Test Diagnostik Lengkap",
    testDiagDesc: "Uji koneksi server, token bot, folder workspace, dan log aktivitas",
    noActiveTasks: "ℹ️ Tidak ada tugas berstatus [PENDING] atau [IN_PROGRESS] di antrean.",
    noNewTasks: "ℹ️ Tidak ada tugas baru di antrean webhook.",
    whoamiTitle: "👤 Identitas Tele-vs-Code Workspace",
    whoamiClose: "Tutup"
  },
  en: {
    appName: "Tele-vs-Code",
    workerActive: "Automation Worker Active",
    workerPaused: "Paused",
    setupRequired: "Setup Required",
    idleStatus: "Idle",
    processingStatus: "Processing Task...",
    warningStatus: "Warning",
    statusBarSetupTooltip: "Tele-vs-Code: Not connected to Biru Server. Click to open Setup Wizard.",
    statusBarPausedTooltip: "Tele-vs-Code ({name}{code})\nAutomation Worker: INACTIVE / PAUSED\nClick to open menu",
    statusBarWorkingTooltip: "Tele-vs-Code ({name}{code})\nStatus: WORKING (PROCESSING)\nActivity: {detail}\nClick to view log",
    statusBarWarningTooltip: "⚠️ Tele-vs-Code Warning:\n{detail}\nClick to open menu or inspect log",
    statusBarIdleTooltip: "Tele-vs-Code ({name}{code})\nStatus: IDLE / READY (Polling every {interval}s)\nFolder: {folder}\nClick to open menu",
    statusBarAgentTooltip: "AI Agent Handler: {handler} (Click to select or enter custom command)",
    switchLanguageTitle: "🌐 Select Interface Language (Pilih Bahasa Tampilan)",
    switchLanguageSuccess: "✅ Interface language switched to English (EN).",
    selectAgentTitle: "🤖 AI Agent Handler Settings (Tele-vs-Code)",
    agentPlaceholder: "Current Active Agent Handler: {agent}",
    agentNoneLabel: "$(circle-slash) [Empty / Off] No Automatic Execution",
    agentNoneDesc: "Tasks are only recorded in TASK_QUEUE.md without running any agent",
    agentNoneDetail: "Safe choice if you want to manually execute tasks in IDE Chat",
    agentAutoLabel: "$(sparkle) [Auto] Auto-Detect Best Installed Agent",
    agentAutoDesc: "Automatically selects the first agent detected on your system (Linux/macOS/Windows)",
    agentAutoDetail: "Searches for CLI: agy, claude, cline, roo, gemini, copilot in PATH or VS Code extensions",
    agentIdeLabel: "$(comment-discussion) [Agent IDE] Direct Execution in IDE Chat (VS Code / Antigravity / Copilot / Gemini)",
    agentIdeDesc: "No external CLI required: Auto-copies prompt to clipboard and opens IDE Chat",
    agentIdeDetail: "Highly recommended if CLI agents are not installed on your system",
    agentIdeToast: "📋 [Agent IDE] Task {id} prompt copied to clipboard and Chat opened! Paste (Ctrl+V) and send to Agent IDE.",
    agentInstalledHeader: "AI Agents Detected on System / VS Code",
    agentOtherHeader: "Other AI Agents",
    agentCustomHeader: "Custom / Manual Input",
    agentManualLabel: "$(pencil) [Manual Input] Type Custom Terminal Command / CLI...",
    agentManualDesc: "Enter custom agent execution command line",
    agentManualDetail: "Supports placeholders {prompt}, {instruction}, {task_id}. Ideal for custom Linux/macOS binaries.",
    agentManualTitle: "Type Custom Terminal Command for AI Agent",
    agentManualPrompt: "Example: agy --dangerously-skip-permissions -p \"{prompt}\" or /usr/local/bin/my-agent --task \"{instruction}\"",
    agentEmptyToast: "ℹ️ Agent Handler is empty. Telegram tasks will only be logged in TASK_QUEUE.md without auto-execution.",
    menuAgentCronInterval: "Set Agent Periodic Check Interval...",
    menuAgentCronIntervalDesc: "TASK_QUEUE.md check interval (Current: {interval} min)",
    setCronIntervalTitle: "⏱️ Set Agent Periodic Check Interval",
    setCronIntervalPrompt: "Enter periodic check interval for AI Agent to check TASK_QUEUE.md (1 - 60 minutes):",
    setCronIntervalValidate: "Please enter an integer between 1 and 60 minutes",
    setCronIntervalToast: "⏱️ AI Agent periodic check interval set to: {interval} minutes.",
    agentScheduleStarted: "🚀 AI Agent periodic check schedule active ({agent}, every {interval}m).",
    agentSetToast: "✅ AI Agent Handler set to: {agent}",
    agentRunningToast: "🤖 {agent} is executing task {id}...",
    agentNoAgentWarning: "⚠️ [Tele-vs-Code] No AI Agent found on your system. Using queue logging only.",
    agentCustomEmptyWarning: "⚠️ [Tele-vs-Code] Custom Agent Command is empty. Configure via \"Select AI Agent Handler\".",
    agentInactiveWarning: "⚠️ Agent Handler is currently empty / disabled. Please select an agent first to execute task {id}.",
    newTaskToast: "⚡ [{id}] New Task: \"{brief}\" (Prompt copied to clipboard)",
    newTaskDoneToast: "✅ [{id}] Telegram Task Completed: \"{brief}\"",
    btnRunVia: "Run via {agent}",
    btnSelectAgent: "Select Agent Handler",
    btnOpenQueue: "Open TASK_QUEUE",
    btnClose: "Close",
    menuTitle: "⚡ Tele-vs-Code Menu ({name})",
    menuSetupTitle: "⚠️ Tele-vs-Code Setup",
    menuSetFolder: "Set Workspace / Working Directory",
    menuSetFolderDesc: "Select folder where TASK_QUEUE.md and AI Agent rules are managed",
    menuRunNext: "Execute Next Task in Queue",
    menuRunNextDesc: "Copy active task instruction in TASK_QUEUE & open AI Chat",
    menuTasks: "Tasks & Jobs Queue...",
    menuTasksDesc: "Add new task, open TASK_QUEUE.md, & monitor status",
    menuWorkerTerminal: "Run Worker in Terminal",
    menuWorkerTerminalDesc: "Open tele-worker terminal for standalone autonomous script execution",
    menuWorkerControl: "Worker & Polling Interval Control...",
    menuWorkerControlActive: "🟢 Active polling every {interval}s",
    menuWorkerControlPaused: "⚪ Paused / Inactive",
    menuAgentHandler: "Select AI Agent Handler...",
    menuAgentActiveDesc: "Active agent: {agent}",
    menuAgentInactiveDesc: "⚪ Inactive / Empty (Queue only)",
    menuUploadMedia: "Upload File / Media Documentation to Biru Server",
    menuUploadMediaDesc: "Upload images, screenshots, or project documents to the server",
    menuSwitchLang: "Switch Language / Ganti Bahasa (EN / ID)",
    menuTest: "Testing (IDE & Telegram)...",
    menuTestDesc: "Test notifications, test IDE task creation, & connection diagnostics",
    menuLog: "View Tele-vs-Code Activity Log",
    menuLogDesc: "Open real-time worker activity and task execution log panel",
    menuDashboard: "Open Web Dashboard",
    menuSetup: "Connect to Biru Server (Setup Wizard)",
    menuSetupDesc: "Configure API Secret Key & select project from Biru server",
    menuWhoami: "Who am I? (Workspace Identity)",
    tasksMenuTitle: "📋 Tasks & Jobs Menu ({name})",
    tasksMenuPlaceholder: "Select task management action:",
    tasksRunNext: "$(play-circle) Execute Next Task in Queue (Send to AI Chat)",
    tasksRunNextDesc: "Copy active task from TASK_QUEUE & open AI chat",
    tasksAdd: "$(add) Add New Task / Send Command to Queue",
    tasksAddDesc: "Send task to TASK_QUEUE.md & trigger Telegram notification",
    tasksOpen: "$(file-text) Open TASK_QUEUE.md",
    tasksOpenDesc: "View task queue list and completion history",
    tasksTerminal: "$(terminal) Run Autonomous CLI Worker in Terminal",
    tasksTerminalDesc: "Open tele-worker terminal for standalone execution",
    tasksBack: "$(arrow-left) Back to Main Menu",
    workerMenuTitle: "⚙️ Automation Worker & Polling Control ({name})",
    workerPause: "$(debug-pause) Pause Automation Worker (Pause Auto-Pull)",
    workerStart: "$(play) Start Automation Worker (Start Auto-Pull)",
    workerPullNow: "$(cloud-download) Pull Tasks Now (Instant Sync)",
    workerPullNowDesc: "Instant task synchronization from webhook server to TASK_QUEUE.md",
    workerInterval: "$(watch) Set Worker Polling Refresh Interval",
    workerIntervalDesc: "Current interval: {interval} seconds (Click to change)",
    testMenuTitle: "🧪 IDE & Telegram Testing Menu ({name})",
    testMenuPlaceholder: "Select test to execute:",
    testSuccess: "$(bell) Test Telegram Notification: COMPLETED",
    testSuccessDesc: "Send 100% completed task report notification to Telegram",
    testProgress: "$(zap) Test Telegram Notification: IN_PROGRESS",
    testProgressDesc: "Send task progress report notification to Telegram",
    testError: "$(warning) Test Telegram Notification: ERROR / WARNING",
    testErrorDesc: "Send error/issue notification to Telegram & trigger warning popup",
    testIde: "$(sparkle) Test Create New Task from IDE",
    testIdeDesc: "Simulate sending task from IDE Chat/Command & verify with server",
    testDiag: "$(heart) Full Diagnostic Self-Test",
    testDiagDesc: "Test server connection, bot token, workspace folder, and activity logs",
    noActiveTasks: "ℹ️ No tasks with [PENDING] or [IN_PROGRESS] status in queue.",
    noNewTasks: "ℹ️ No new tasks in webhook queue.",
    whoamiTitle: "👤 Tele-vs-Code Workspace Identity",
    whoamiClose: "Close"
  }
};

const i18n = (...args) => t(...args);
function t(key, replacements = {}, langOverride = null) {
  let lang = langOverride;
  if (!lang) {
    try {
      const cfg = vscode.workspace.getConfiguration('telegramNotifier');
      lang = cfg.get('language') || 'id';
    } catch (e) {
      lang = 'id';
    }
  }
  const dict = I18N[lang] || I18N.id;
  let str = dict[key] || I18N.id[key] || key;
  for (const [k, v] of Object.entries(replacements)) {
    str = str.replace(new RegExp(`\{${k}\}`, 'g'), String(v));
  }
  return str;
}

// Migrasi otomatis folder warisan .telegram-dev-notifier ke .tele-vs-code
function migrateLegacyDirectories(wsFolder) {
  if (!wsFolder) return;
  try {
    const oldWsDir = path.join(wsFolder, '.telegram-dev-notifier');
    const newWsDir = path.join(wsFolder, '.tele-vs-code');
    if (fs.existsSync(oldWsDir) && !fs.existsSync(newWsDir)) {
      fs.renameSync(oldWsDir, newWsDir);
    }
  } catch (e) {}

  try {
    const homeDir = os.homedir();
    const oldHomeDir = path.join(homeDir, '.config', 'tele-notify');
    const oldHomeDir2 = path.join(homeDir, '.telegram-dev-notifier');
    const newHomeDir = path.join(homeDir, '.config', 'tele-vs-code');
    if (!fs.existsSync(newHomeDir)) {
      if (fs.existsSync(oldHomeDir)) {
        fs.mkdirSync(path.dirname(newHomeDir), { recursive: true });
        if (fs.cpSync) fs.cpSync(oldHomeDir, newHomeDir, { recursive: true });
        else fs.renameSync(oldHomeDir, newHomeDir);
      } else if (fs.existsSync(oldHomeDir2)) {
        fs.mkdirSync(path.dirname(newHomeDir), { recursive: true });
        if (fs.cpSync) fs.cpSync(oldHomeDir2, newHomeDir, { recursive: true });
        else fs.renameSync(oldHomeDir2, newHomeDir);
      }
    }
  } catch (e) {}
}

function getEffectiveConfig() {
  const wsFolder = vscode.workspace.workspaceFolders && vscode.workspace.workspaceFolders.length > 0
    ? vscode.workspace.workspaceFolders[0].uri.fsPath
    : process.cwd();

  const vsConfig = vscode.workspace.getConfiguration('telegramNotifier');
  const configuredFolder = vsConfig.get('folder');
  let folder = wsFolder;
  if (configuredFolder && typeof configuredFolder === 'string' && configuredFolder.trim()) {
    const trimmed = configuredFolder.trim();
    if (fs.existsSync(trimmed)) {
      folder = trimmed;
    }
  }

  const homeDir = os.homedir();
  const globalConf = path.join(homeDir, '.config', 'tele-notify', 'config.env');
  const vscodeConf = path.join(folder, '.vscode', 'tele-vs-code', 'config.env');
  const localConf = path.join(folder, 'config.env');
  const localEnv = path.join(folder, '.env');

  const cfgGlobal = parseEnvFile(globalConf);
  const cfgVscode = parseEnvFile(vscodeConf);
  const cfgLocal = parseEnvFile(localConf);
  const cfgEnv = parseEnvFile(localEnv);

  // PRIORITAS: .vscode/tele-vs-code/config.env > config.env lokal > .env lokal > VS Code Setting > global
  const project = cfgVscode.AGENTS_TELE_PROJECT || cfgLocal.AGENTS_TELE_PROJECT || cfgEnv.AGENTS_TELE_PROJECT || vsConfig.get('project') || cfgGlobal.AGENTS_TELE_PROJECT || '';
  const secretKey = cfgVscode.AGENTS_TELE_SECRET || cfgLocal.AGENTS_TELE_SECRET || cfgEnv.AGENTS_TELE_SECRET || vsConfig.get('secretKey') || cfgGlobal.AGENTS_TELE_SECRET || '';
  const webhookUrl = cfgVscode.AGENTS_TELE_WEBHOOK_URL || cfgLocal.AGENTS_TELE_WEBHOOK_URL || cfgEnv.AGENTS_TELE_WEBHOOK_URL || vsConfig.get('webhookUrl') || 'https://biru.co.id/tele-vs-code/webhook';
  const timezone = cfgVscode.TIMEZONE || cfgLocal.TIMEZONE || cfgEnv.TIMEZONE || vsConfig.get('timezone') || 'Asia/Jakarta';
  const language = vsConfig.get('language') || 'id';
  migrateLegacyDirectories(folder);
  const autoPull = vsConfig.get('autoPull') !== false;
  const askOnStartup = vsConfig.get('askOnStartup') === true;
  const pullInterval = Math.max(1, Math.min(60, vsConfig.get('pullInterval') || 2));
  const showToastOnNewTask = vsConfig.get('showToastOnNewTask') !== false;
  const autoExecuteSimpleTasks = vsConfig.get('autoExecuteSimpleTasks') !== false;

  const botToken = cfgVscode.TELEGRAM_BOT_TOKEN || cfgLocal.TELEGRAM_BOT_TOKEN || cfgEnv.TELEGRAM_BOT_TOKEN || vsConfig.get('botToken') || cfgGlobal.TELEGRAM_BOT_TOKEN || '';
  const chatId = cfgVscode.TELEGRAM_CHAT_ID || cfgLocal.TELEGRAM_CHAT_ID || cfgEnv.TELEGRAM_CHAT_ID || vsConfig.get('chatId') || cfgGlobal.TELEGRAM_CHAT_ID || '';

  // AI Agent Handler Configuration
  const agentHandler = cfgVscode.TELE_AGENT_HANDLER || cfgLocal.TELE_AGENT_HANDLER || vsConfig.get('agentHandler') || '';
  const customAgentCommand = cfgVscode.TELE_CUSTOM_AGENT_COMMAND || cfgLocal.TELE_CUSTOM_AGENT_COMMAND || vsConfig.get('customAgentCommand') || '';
  const autoDispatchAgent = vsConfig.get('autoDispatchAgent') === true;

  return { folder, botToken, chatId, webhookUrl, secretKey, timezone, language, project, wsFolder, autoPull, askOnStartup, pullInterval, showToastOnNewTask, autoExecuteSimpleTasks, agentHandler, customAgentCommand, autoDispatchAgent, vscodeConf };
}

// ------------------------------------------------------------------------------
// Inisialisasi Otomatis Berkas Tool ke .vscode/tele-vs-code/ & Root Wrappers
// ------------------------------------------------------------------------------
function ensureWorkspaceTools(context, wsFolder) {
  if (!wsFolder || !fs.existsSync(wsFolder)) return;

  const toolsDir = path.join(wsFolder, '.vscode', 'tele-vs-code');
  if (!fs.existsSync(toolsDir)) {
    fs.mkdirSync(toolsDir, { recursive: true });
  }

  // 1. Salin script dari aset internal ekstensi jika ada
  const extAssetsDir = path.join(context.extensionPath, 'assets', 'tele-vs-code');
  if (fs.existsSync(extAssetsDir)) {
    const assetFiles = [
      'tele-worker.js', 'tele-notify.js', 'tele-task.js',
      'tele-worker.cmd', 'tele-notify.cmd', 'tele-task.cmd',
      'tele-worker.sh', 'tele-notify.sh', 'tele-task.sh',
      'tele-worker', 'tele-notify', 'tele-task'
    ];
    for (const f of assetFiles) {
      const src = path.join(extAssetsDir, f);
      const dest = path.join(toolsDir, f);
      if (fs.existsSync(src)) {
        try {
          fs.copyFileSync(src, dest);
          if (process.platform !== 'win32') {
            try { fs.chmodSync(dest, 0o755); } catch (e) {}
          }
        } catch (e) {}
      }
    }
  }

  // 2. Bersihkan file tool di root workspace jika ada dari versi sebelumnya, agar file tool HANYA ada di dalam .vscode/tele-vs-code/
  const filesToCleanFromRoot = [
    'tele-worker.js', 'tele-notify.js', 'tele-task.js',
    'tele-worker.cmd', 'tele-notify.cmd', 'tele-task.cmd',
    'tele-worker.sh', 'tele-notify.sh', 'tele-task.sh'
  ];
  for (const rf of filesToCleanFromRoot) {
    const rootFilePath = path.join(wsFolder, rf);
    if (fs.existsSync(rootFilePath)) {
      try {
        fs.unlinkSync(rootFilePath);
      } catch (e) {}
    }
  }
  // 3. Pastikan TASK_QUEUE.md ada di root
  const queueFile = path.join(wsFolder, 'TASK_QUEUE.md');
  if (!fs.existsSync(queueFile)) {
    const queueTpl = `# 📋 TASK QUEUE (Antrean Tugas Agent)\n\n> File ini diperbarui otomatis oleh **Telegram Dev Notifier** dan dibaca oleh **AI Agent**.\n\n---\n\n## ⏳ Tugas Aktif\n\n*(Tidak ada tugas aktif di antrean saat ini)*\n\n---\n\n## ✅ Riwayat Tugas Selesai\n\n`;
    try {
      fs.writeFileSync(queueFile, queueTpl, 'utf-8');
    } catch (e) {}
  }

  // 4. Pastikan aturan AI Agent (AGENTS.md, CLAUDE.md, GEMINI.md, .cursorrules) ada agar AI di IDE Chat otomatis mengeksekusi TASK_QUEUE & lapor ke Telegram
  const agentRuleTpl = `# 🤖 PANDUAN KERJA OTOMATIS AI AGENT (Tele-vs-Code)

## ⚡ 1. MANDATORY TRIGGER: EKSEKUSI OTOMATIS ANTREAN TUGAS (TASK_QUEUE.md)
Setiap kali Anda menerima giliran bicara, prompt, atau instruksi apapun dari USER di IDE Chat ini:

1. **LANGKAH 1 (PRIORITAS UTAMA - PERIKSA TASK_QUEUE.md):**
   - Periksa file \`TASK_QUEUE.md\` di root proyek.
   - Jika terdapat tugas berstatus \`[PENDING]\` atau \`[IN_PROGRESS]\` (terutama tugas masuk dari Telegram):
     👉 **ANDA WAJIB OTOMATIS MENGEKSEKUSI TUGAS TERSEBUT SAMPAI SELESAI 100%!**
     - Ubah status menjadi \`[IN_PROGRESS]\` saat mulai dikerjakan.
     - Kerjakan instruksinya dan uji verifikasi hingga tuntas.
     - Setelah selesai 100%, tandai selesai menggunakan perintah:
       node .vscode/tele-vs-code/tele-task.js done "<TASK-ID>" "<Ringkasan hasil 100% selesai>"
     - Selesaikan antrean berurutan satu per satu.

2. **LANGKAH 2 (PENCATATAN PERINTAH BARU):**
   - Jika antrean kosong/sudah selesai dan user memberikan perintah baru:
     node .vscode/tele-vs-code/tele-task.js add "<Perintah User>" --sender "IDE Chat" --status IN_PROGRESS
   - Kerjakan tugas hingga tuntas 100%, lalu tandai selesai dengan:
     node .vscode/tele-vs-code/tele-task.js done "<TASK-ID>" "<Ringkasan hasil 100% selesai>"

3. **KATA KUNCI PEMICU INSTAN:**
   Jika user mengetik: \`proses antrian\`, \`kerjakan queue\`, \`next task\`, \`/queue\`, atau \`lanjutkan\`:
   👉 Langsung baca \`TASK_QUEUE.md\` dan selesaikan tugas berikutnya tanpa menunggu konfirmasi!

## 📢 2. NOTIFIKASI TELEGRAM
- **Jika Sukses (100% selesai & teruji):**
  node .vscode/tele-vs-code/tele-notify.js "<Judul Tugas>" --status SELESAI --detail "<Rincian 1>" --detail "<Rincian 2>"
- **Jika Sedang Dikerjakan:**
  node .vscode/tele-vs-code/tele-notify.js "<Judul Tugas>" --status PROSES --detail "<Status>"
- **Jika Ada Kendala Error:**
  node .vscode/tele-vs-code/tele-notify.js "<Judul Kendala>" --status KENDALA --detail "<Penyebab>"

> ⚠️ **ATURAN MUTLAK:** JANGAN PERNAH mengirimkan status \`SELESAI\` sebelum tugas benar-benar 100% tuntas dan teruji.
`;

  const targetRuleFiles = ['AGENTS.md', 'CLAUDE.md', 'GEMINI.md', '.cursorrules'];
  for (const rf of targetRuleFiles) {
    const rPath = path.join(wsFolder, rf);
    if (!fs.existsSync(rPath)) {
      try { fs.writeFileSync(rPath, agentRuleTpl, 'utf-8'); } catch (e) {}
    } else {
      try {
        const curContent = fs.readFileSync(rPath, 'utf-8');
        if (!curContent.includes('tele-task.js') && !curContent.includes('tele-task add')) {
          fs.appendFileSync(rPath, `\n\n${agentRuleTpl}`, 'utf-8');
        }
      } catch (e) {}
    }
  }
}

// Cache Konfigurasi Proyek dari Server Biru
let serverProjectCache = null;
let lastCacheTime = 0;

async function fetchServerProjects(force = false) {
  const cur = getEffectiveConfig();
  if (!cur.secretKey) return null;

  const now = Date.now();
  if (!force && serverProjectCache && (now - lastCacheTime < 30000)) {
    return serverProjectCache;
  }

  try {
    const url = `${cur.webhookUrl.replace(/\/?$/, '/')}?action=projects&secret=${cur.secretKey}`;
    const res = await safeFetch(url, { timeout: 10000 });
    const data = await res.json();
    if (data.ok && Array.isArray(data.projects)) {
      serverProjectCache = data.projects;
      lastCacheTime = now;
      return data.projects;
    }
  } catch (e) {}
  return serverProjectCache;
}

async function getResolvedProject() {
  const cur = getEffectiveConfig();
  const projects = await fetchServerProjects();

  // Jika cur.project belum disetel (instal pertama kali atau belum pilih proyek)
  if (!cur.project) {
    if (projects && projects.length === 1) {
      // Auto-resolve jika akun hanya memiliki 1 proyek (sangat membantu di remote SSH / headless)
      const matched = projects[0];
      return {
        id: matched.id,
        botToken: matched.bot_token || cur.botToken,
        chatId: matched.chat_id || cur.chatId,
        name: matched.name || path.basename(cur.folder),
        code: matched.code || '',
        projectKey: matched.project_key || '',
        timezone: matched.timezone || cur.timezone,
        lastFolder: matched.last_workspace_folder || '',
        lastSeen: matched.last_seen || ''
      };
    }
    return {
      id: '',
      botToken: cur.botToken,
      chatId: cur.chatId,
      name: '(Belum Dipilih)',
      code: '',
      projectKey: '',
      timezone: cur.timezone,
      lastFolder: '',
      lastSeen: ''
    };
  }

  if (!projects || projects.length === 0) {
    return {
      id: '',
      botToken: cur.botToken,
      chatId: cur.chatId,
      name: `Proyek ${cur.project}`,
      code: cur.project,
      projectKey: cur.project.startsWith('prj_') ? cur.project : '',
      timezone: cur.timezone,
      lastFolder: '',
      lastSeen: ''
    };
  }

  const cleanP = cur.project.toLowerCase().trim();
  const matched = projects.find(p =>
    (p.project_key && p.project_key.toLowerCase() === cleanP) ||
    (p.code && p.code.toLowerCase() === cleanP) ||
    (p.name && p.name.toLowerCase() === cleanP) ||
    (String(p.id) === cleanP)
  );

  if (!matched) {
    return {
      id: '',
      botToken: cur.botToken,
      chatId: cur.chatId,
      name: `(Proyek Tidak Ditemukan: ${cur.project})`,
      code: '',
      projectKey: cur.project,
      timezone: cur.timezone,
      lastFolder: '',
      lastSeen: ''
    };
  }

  return {
    id: matched.id,
    botToken: matched.bot_token || cur.botToken,
    chatId: matched.chat_id || cur.chatId,
    name: matched.name || path.basename(cur.folder),
    code: matched.code || '',
    projectKey: matched.project_key || '',
    timezone: matched.timezone || cur.timezone,
    lastFolder: matched.last_workspace_folder || '',
    lastSeen: matched.last_seen || ''
  };
}

function escapeHtml(text) {
  if (!text) return '';
  return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

async function sendTelegramMessage(botToken, chatId, title, status, details = [], folderName = '', timezone = '', duration = '') {
  let resolvedToken = botToken;
  let resolvedChat = chatId;
  let effectiveTz = timezone;
  let prjName = folderName;

  if (!resolvedToken || !resolvedChat) {
    const resolved = await getResolvedProject();
    if (resolved) {
      resolvedToken = resolvedToken || resolved.botToken;
      resolvedChat = resolvedChat || resolved.chatId;
      effectiveTz = effectiveTz || resolved.timezone;
      prjName = prjName || resolved.name;
    }
  }

  if (!resolvedToken || !resolvedChat) {
    throw new Error('Telegram Bot Token atau Chat ID belum disetel di server Biru untuk proyek ini. Silakan atur di dashboard https://biru.co.id/tele-vs-code/');
  }

  const statUpper = (status || 'SELESAI').toUpperCase();
  let emoji = '✅';
  if (['KENDALA', 'ERROR', 'FAILED', 'GAGAL'].includes(statUpper)) emoji = '🚨';
  else if (['PROSES', 'PROGRESS', 'IN_PROGRESS'].includes(statUpper)) emoji = '⚡';
  else if (statUpper === 'INFO') emoji = 'ℹ️';

  const tz = effectiveTz || 'Asia/Jakarta';
  let tzLabel = tz;
  if (tz === 'Asia/Jakarta') tzLabel = 'WIB';
  else if (tz === 'Asia/Makassar') tzLabel = 'WITA';
  else if (tz === 'Asia/Jayapura') tzLabel = 'WIT';
  else if (tz === 'UTC') tzLabel = 'UTC';
  else if (tz === 'Asia/Singapore') tzLabel = 'SGT';
  else if (tz === 'Asia/Bangkok') tzLabel = 'ICT';

  const now = new Date();
  let dateStr, timeStr;
  try {
    dateStr = now.toLocaleDateString('id-ID', { timeZone: tz, day: '2-digit', month: '2-digit', year: 'numeric' });
    timeStr = now.toLocaleTimeString('id-ID', { timeZone: tz, hour: '2-digit', minute: '2-digit', second: '2-digit' }).replace(/\./g, ':');
  } catch (e) {
    dateStr = now.toLocaleDateString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric' });
    timeStr = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).replace(/\./g, ':');
    tzLabel = 'WIB';
  }

  const userHost = `${os.userInfo().username}@${os.hostname()}`;
  const projectName = prjName || 'Workspace';

  let textHtml = `<b>${emoji} ${escapeHtml(title)}: ${escapeHtml(statUpper)}</b>\n\n`
               + `📁 <b>Proyek:</b> <code>${escapeHtml(projectName)}</code>\n`
               + `🕒 <b>Waktu:</b> ${dateStr} ${timeStr} ${tzLabel}\n`
               + `👤 <b>Operator:</b> <code>${escapeHtml(userHost)}</code>\n`;

  if (details && details.length > 0) {
    textHtml += `\n📋 <b>Detail:</b>\n`;
    for (const d of details) {
      if (d && d.trim()) textHtml += `• ${escapeHtml(d.trim())}\n`;
    }
  }

  textHtml += `\n🤖 <i>Dikirim via Tele-vs-Code</i>`;

  const url = `https://api.telegram.org/bot${resolvedToken}/sendMessage`;
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      chat_id: resolvedChat,
      text: textHtml,
      parse_mode: 'HTML',
      disable_web_page_preview: true
    })
  });

  const data = await res.json();
  if (!data.ok) {
    throw new Error(data.description || 'Gagal mengirim pesan Telegram');
  }
  return data;
}

// ==============================================================================
// Helper: Unggah File / Media Dokumentasi ke Server Biru
// ==============================================================================
async function uploadMediaToServer(filePath, caption = '', taskId = '', notifyTelegram = false) {
  if (!fs.existsSync(filePath)) {
    throw new Error(`Berkas tidak ditemukan: ${filePath}`);
  }

  const cur = getEffectiveConfig();
  const secretKey = cur.secretKey || getEnvVal('AGENTS_TELE_SECRET');
  if (!secretKey) {
    throw new Error('API Secret Key belum disetel. Hubungkan workspace ini ke Server Biru melalui Setup Wizard.');
  }

  const resolved = await getResolvedProject();
  const projectKey = cur.project || resolved.projectKey || getEnvVal('AGENTS_TELE_PROJECT_KEY') || getEnvVal('AGENTS_TELE_PROJECT');
  const normalizedWebhook = (cur.webhookUrl || getEnvVal('AGENTS_TELE_WEBHOOK_URL') || 'https://biru.co.id/tele-vs-code/webhook')
    .replace(/\/webhook\/?$/, '/');

  const fileBuffer = fs.readFileSync(filePath);
  const fileName = path.basename(filePath);
  const blob = new Blob([fileBuffer]);

  const formData = new FormData();
  formData.append('file', blob, fileName);
  if (caption) formData.append('caption', caption);
  if (taskId) formData.append('task_id', taskId);
  if (projectKey) formData.append('project', projectKey);
  formData.append('uploaded_by', `${os.userInfo().username}@${os.hostname()} (VS Code)`);
  if (notifyTelegram) formData.append('notify_telegram', '1');

  const uploadUrl = `${normalizedWebhook.replace(/\/$/, '')}/?action=upload_media&secret=${encodeURIComponent(secretKey)}`;
  const res = await safeFetch(uploadUrl, {
    method: 'POST',
    body: formData
  });

  const data = await res.json();
  if (!res.ok || !data.ok) {
    throw new Error(data.error || `Gagal mengunggah berkas (HTTP ${res.status})`);
  }
  return data;
}

// Helper Parser Blok Tugas Markdown untuk Activity Bar
function parseTaskBlocks(sectionText) {
  const blocks = [];
  if (!sectionText) return blocks;
  const regex = /###\s+\[(PENDING|IN_PROGRESS|DONE|FAILED)\]\s+(#?TASK-[A-Za-z0-9_-]+)[\r\n]+([\s\S]*?)(?=(?:###\s+\[|##|$))/gi;
  let match;
  while ((match = regex.exec(sectionText)) !== null) {
    const status = match[1].toUpperCase();
    const taskId = match[2].trim();
    const body = match[3];

    const mWaktu = body.match(/-\s+\*\*Waktu:\*\*\s*([^\r\n]+)/i);
    const mPengirim = body.match(/-\s+\*\*Pengirim:\*\*\s*([^\r\n]+)/i);
    const mAgent = body.match(/-\s+\*\*(?:Agent|Eksekutor):\*\*\s*([^\r\n]+)/i);
    const mInstruksi = body.match(/-\s+\*\*Instruksi:\*\*\s*([^\r\n]+)/i);
    const mLaporan = body.match(/-\s+\*\*(?:Laporan Agent|Result|Hasil):\*\*\s*([^\r\n]+)/i);
    const mCatatan = body.match(/-\s+\*\*Catatan:\*\*\s*([^\r\n]+)/i);
    const mCommand = body.match(/-\s+\*\*Command:\*\*\s*([^\r\n]+)/i);

    blocks.push({
      id: taskId,
      status: status,
      waktu: mWaktu ? mWaktu[1].trim() : '',
      pengirim: mPengirim ? mPengirim[1].trim() : '',
      agent: mAgent ? mAgent[1].trim() : 'Antigravity AI Agent',
      instruksi: mInstruksi ? mInstruksi[1].trim() : '',
      result: mLaporan ? mLaporan[1].trim() : (mCatatan ? mCatatan[1].trim() : ''),
      command: mCommand ? mCommand[1].trim() : ''
    });
  }
  return blocks;
}

// Tree Item Classes untuk Struktur Hierarki (Accordion / Tree View)
class TaskCategoryItem extends vscode.TreeItem {
  constructor(label, collapsibleState, category) {
    super(label, collapsibleState);
    this.category = category; // 'active' | 'done'
    this.contextValue = 'taskCategory';
  }
}

class TaskItem extends vscode.TreeItem {
  constructor(task, collapsibleState = vscode.TreeItemCollapsibleState.Collapsed) {
    const statusPrefix = `[${task.status}]`;
    super(`${statusPrefix} ${task.id}`, collapsibleState);
    this.task = task;
    this.contextValue = 'taskItem';

    // Deskripsi ringkas perintah
    this.description = task.instruksi
      ? (task.instruksi.length > 35 ? task.instruksi.substring(0, 35) + '...' : task.instruksi)
      : '';

    // Ikon status dinamis
    if (task.status === 'DONE') {
      this.iconPath = new vscode.ThemeIcon('pass-filled', new vscode.ThemeColor('charts.green'));
    } else if (task.status === 'IN_PROGRESS') {
      this.iconPath = new vscode.ThemeIcon('sync~spin', new vscode.ThemeColor('charts.blue'));
    } else if (task.status === 'FAILED') {
      this.iconPath = new vscode.ThemeIcon('error', new vscode.ThemeColor('charts.red'));
    } else {
      this.iconPath = new vscode.ThemeIcon('clock', new vscode.ThemeColor('charts.yellow'));
    }

    const tt = new vscode.MarkdownString();
    tt.appendMarkdown(`### ${task.status === 'DONE' ? '✅' : '⚡'} Tugas [${task.id}]\n\n`);
    tt.appendMarkdown(`- **Status:** \`${task.status}\`\n`);
    if (task.waktu) tt.appendMarkdown(`- **Waktu:** ${task.waktu}\n`);
    if (task.pengirim) tt.appendMarkdown(`- **Pengirim:** ${task.pengirim}\n`);
    tt.appendMarkdown(`- **Eksekutor:** \`${task.agent || 'Antigravity AI Agent'}\`\n`);
    if (task.instruksi) tt.appendMarkdown(`\n**📝 Perintah / Instruksi:**\n> ${task.instruksi}\n`);
    if (task.command) tt.appendMarkdown(`\n**💻 Command:**\n\`${task.command}\`\n`);
    if (task.result) tt.appendMarkdown(`\n**${task.status === 'DONE' ? '📋 Hasil / Result' : '📌 Catatan Progres'}:**\n> ${task.result}\n`);
    tt.appendMarkdown(`\n---\n*(Buka panah akordion ▶ untuk melihat rincian Perintah & Result, atau klik untuk dialog)*`);
    this.tooltip = tt;
  }
}

class TaskDetailItem extends vscode.TreeItem {
  constructor(labelPrefix, value, iconName, colorTheme = null, copyVal = null) {
    const displayVal = value ? (value.length > 60 ? value.substring(0, 60) + '...' : value) : '-';
    super(`${labelPrefix}: ${displayVal}`, vscode.TreeItemCollapsibleState.None);
    this.value = value;
    this.contextValue = 'taskDetail';
    if (iconName) {
      this.iconPath = colorTheme
        ? new vscode.ThemeIcon(iconName, new vscode.ThemeColor(colorTheme))
        : new vscode.ThemeIcon(iconName);
    }
    const tt = new vscode.MarkdownString();
    tt.appendMarkdown(`**${labelPrefix}**\n\n${value || '-'}\n\n`);
    if (copyVal) {
      tt.appendMarkdown(`---\n*(Klik item ini untuk menyalin ke clipboard)*`);
      this.command = {
        command: 'telegramNotifier.copyTaskDetailText',
        title: 'Salin Teks',
        arguments: [copyVal, labelPrefix]
      };
    }
    this.tooltip = tt;
  }
}

// TreeDataProvider untuk Task Queue View di Activity Bar (Struktur Akordion / Tree)
class TaskQueueProvider {
  constructor(getFolderFn) {
    this.getFolderFn = getFolderFn;
    this._onDidChangeTreeData = new vscode.EventEmitter();
    this.onDidChangeTreeData = this._onDidChangeTreeData.event;
  }

  refresh() {
    this._onDidChangeTreeData.fire();
  }

  getTreeItem(element) {
    return element;
  }

  getChildren(element) {
    const folder = this.getFolderFn();
    const queueFile = path.join(folder, 'TASK_QUEUE.md');

    // LEVEL 1: Root Categories
    if (!element) {
      return [
        new TaskCategoryItem('⏳ Tugas Aktif (In Progress / Pending)', vscode.TreeItemCollapsibleState.Expanded, 'active'),
        new TaskCategoryItem('✅ Riwayat Tugas Selesai', vscode.TreeItemCollapsibleState.Expanded, 'done')
      ];
    }

    if (!fs.existsSync(queueFile)) {
      if (element instanceof TaskCategoryItem) {
        return [new vscode.TreeItem('File TASK_QUEUE.md belum dibuat', vscode.TreeItemCollapsibleState.None)];
      }
      return [];
    }

    const content = fs.readFileSync(queueFile, 'utf-8');

    // LEVEL 2: Daftar Tugas dalam Kategori
    if (element instanceof TaskCategoryItem) {
      const items = [];
      if (element.category === 'active') {
        const activeMatch = content.match(/## ⏳ Tugas Aktif([\s\S]*?)(?=## ✅ Riwayat|$)/);
        if (activeMatch) {
          const tasks = parseTaskBlocks(activeMatch[1]);
          for (const t of tasks) {
            // Tugas aktif dibuka (Expanded) otomatis agar terlihat akordion isinya
            const taskItem = new TaskItem(t, vscode.TreeItemCollapsibleState.Expanded);
            items.push(taskItem);
          }
        }
        if (items.length === 0) {
          const emptyItem = new vscode.TreeItem('Tidak ada tugas aktif di antrean', vscode.TreeItemCollapsibleState.None);
          emptyItem.iconPath = new vscode.ThemeIcon('check', new vscode.ThemeColor('charts.green'));
          items.push(emptyItem);
        }
        return items;
      }

      if (element.category === 'done') {
        const doneMatch = content.match(/## ✅ Riwayat Tugas Selesai([\s\S]*)$/);
        if (doneMatch) {
          const tasks = parseTaskBlocks(doneMatch[1]);
          for (const t of tasks.slice(0, 30)) {
            // Riwayat selesai di-collapse (akordion tertutup) agar ringkas & rapi
            const taskItem = new TaskItem(t, vscode.TreeItemCollapsibleState.Collapsed);
            items.push(taskItem);
          }
        }
        if (items.length === 0) {
          const emptyItem = new vscode.TreeItem('Belum ada riwayat tugas selesai', vscode.TreeItemCollapsibleState.None);
          items.push(emptyItem);
        }
        return items;
      }
    }

    // LEVEL 3: Sub-item di dalam TaskItem (Akordion Perintah, Result, Command, Info)
    if (element instanceof TaskItem) {
      const t = element.task;
      const subItems = [];

      // 1. Sub-item Perintah
      if (t.instruksi) {
        const pItem = new TaskDetailItem('📝 Perintah', t.instruksi, 'symbol-keyword', 'charts.yellow', t.instruksi);
        subItems.push(pItem);
      }

      // 1b. Sub-item Eksekutor Agent
      if (t.agent) {
        const aItem = new TaskDetailItem('🤖 Agent', t.agent, 'hubot', 'charts.blue', t.agent);
        subItems.push(aItem);
      }

      // 2. Sub-item Result / Progres
      if (t.status === 'DONE') {
        const rVal = t.result || '(Tugas tuntas 100%)';
        const rItem = new TaskDetailItem('📋 Result', rVal, 'pass', 'charts.green', rVal);
        subItems.push(rItem);
      } else {
        const pVal = t.result || 'Sedang dikerjakan oleh AI Agent...';
        const pItem = new TaskDetailItem('⚡ Progres', pVal, 'sync~spin', 'charts.blue', pVal);
        subItems.push(pItem);
      }

      // 3. Sub-item Command Terminal (jika ada)
      if (t.command) {
        const cItem = new TaskDetailItem('💻 Command', t.command, 'terminal', 'charts.purple', t.command);
        subItems.push(cItem);
      }

      // 4. Sub-item Pengirim & Waktu
      const infoVal = `${t.pengirim || 'Telegram'}${t.waktu ? ' • ' + t.waktu : ''}`;
      const iItem = new TaskDetailItem('👤 Info', infoVal, 'person', 'charts.foreground');
      subItems.push(iItem);

      // 5. Aksi Modal Lengkap (Dialog)
      const detailAction = new vscode.TreeItem('🔍 Buka Dialog Detail Tugas', vscode.TreeItemCollapsibleState.None);
      detailAction.iconPath = new vscode.ThemeIcon('eye');
      detailAction.tooltip = 'Klik untuk membuka dialog rincian lengkap tugas';
      detailAction.command = {
        command: 'telegramNotifier.showTaskDetail',
        title: 'Lihat Detail Tugas',
        arguments: [t]
      };
      subItems.push(detailAction);

      return subItems;
    }

    return [];
  }
}

let autoPullTimer = null;
let isPulling = false;
let isWorkerActive = false;

function activate(context) {
  const curInitial = getEffectiveConfig();

  // Output Channel & Logging Engine (Requirement 3: Buat log)
  const outputChannel = vscode.window.createOutputChannel('Tele-vs-Code');
  context.subscriptions.push(outputChannel);

  function log(level, message, data = null) {
    const now = new Date();
    const timeStr = now.toTimeString().split(' ')[0] + '.' + String(now.getMilliseconds()).padStart(3, '0');
    const line = `[${timeStr}] [${level.toUpperCase()}] ${message}`;
    outputChannel.appendLine(line);
    if (data) {
      if (typeof data === 'object') {
        try { outputChannel.appendLine(JSON.stringify(data, null, 2)); }
        catch (e) { outputChannel.appendLine(String(data)); }
      } else {
        outputChannel.appendLine(String(data));
      }
    }
  }

  log('info', 'Ekstensi Tele-vs-Code diaktifkan.');

  // Helper Deteksi Binary di OS (Linux / macOS / Windows)
  function findCli(binName, extraPaths = []) {
    const envVar = `${binName.toUpperCase().replace(/[^A-Z0-9]/g, '_')}_PATH`;
    if (process.env[envVar] && fs.existsSync(process.env[envVar])) {
      return process.env[envVar];
    }
    try {
      const whichCmd = process.platform === 'win32' ? `where ${binName}` : `which ${binName}`;
      const found = execSync(whichCmd, { encoding: 'utf-8', stdio: ['pipe', 'pipe', 'ignore'] }).trim().split(/\r?\n/)[0];
      if (found && fs.existsSync(found)) return found;
    } catch (e) {}

    const home = os.homedir();
    const localAppData = process.env.LOCALAPPDATA || (process.env.USERPROFILE ? path.join(process.env.USERPROFILE, 'AppData', 'Local') : '');
    const standardPaths = [
      ...extraPaths,
      path.join(home, '.local', 'bin', binName),
      path.join(home, '.local', 'bin', `${binName}.exe`),
      path.join(home, '.agy', 'bin', binName),
      path.join(home, '.agy', 'bin', `${binName}.exe`),
      path.join(home, '.antigravity', 'bin', binName),
      path.join(home, '.cargo', 'bin', binName),
      path.join(home, '.cargo', 'bin', `${binName}.exe`),
      `/usr/local/bin/${binName}`,
      `/usr/bin/${binName}`,
      localAppData ? path.join(localAppData, binName, 'bin', `${binName}.exe`) : '',
      localAppData ? path.join(localAppData, 'Programs', binName, `${binName}.exe`) : ''
    ].filter(Boolean);

    for (const p of standardPaths) {
      if (fs.existsSync(p)) return p;
    }
    return null;
  }

  // Deteksi AI Agent yang terpasang di OS atau VS Code pengguna (Linux/macOS/Windows)
  function detectAvailableAgents() {
    const agents = [];

    // 0. Agent IDE (VS Code Chat / Antigravity Chat / Copilot / Gemini)
    // Agen interaktif yang berjalan langsung di IDE (tanpa membutuhkan CLI eksternal)
    const ideAgents = [];
    if (vscode.extensions.all.some(e => /antigravity/i.test(e.id))) ideAgents.push('Antigravity');
    if (vscode.extensions.all.some(e => /copilot/i.test(e.id))) ideAgents.push('Copilot');
    if (vscode.extensions.all.some(e => /gemini/i.test(e.id))) ideAgents.push('Gemini');
    if (vscode.extensions.all.some(e => /cline|claude-dev/i.test(e.id))) ideAgents.push('Cline');
    if (vscode.extensions.all.some(e => /roo/i.test(e.id))) ideAgents.push('Roo');
    const ideSummary = ideAgents.length > 0 ? ideAgents.join(', ') : 'VS Code Chat';

    agents.push({
      id: 'ide',
      name: `Agent IDE (${ideSummary})`,
      installed: true,
      cliPath: null,
      isIde: true,
      detail: `Eksekusi langsung di Chat IDE (${ideSummary}) - Tanpa butuh CLI`
    });

    // 1. Google Antigravity (AGY)
    const agyCli = findCli('agy');
    const hasAgyExt = vscode.extensions.all.some(e => /antigravity/i.test(e.id));
    agents.push({
      id: 'agy',
      name: 'Google Antigravity (agy CLI)',
      installed: !!agyCli || hasAgyExt,
      cliPath: agyCli,
      detail: agyCli ? `Terdeteksi di: ${agyCli}` : (hasAgyExt ? 'Ekstensi Antigravity aktif' : 'Belum terpasang di sistem')
    });

    // 2. Claude Code (Anthropic)
    const claudeCli = findCli('claude');
    const hasClaudeExt = vscode.extensions.all.some(e => /claude/i.test(e.id));
    agents.push({
      id: 'claude',
      name: 'Claude Code (claude CLI)',
      installed: !!claudeCli || hasClaudeExt,
      cliPath: claudeCli,
      detail: claudeCli ? `Terdeteksi di: ${claudeCli}` : (hasClaudeExt ? 'Ekstensi Claude terpasang' : 'Belum terpasang di sistem')
    });

    // 3. Cline Autonomous Dev
    const clineCli = findCli('cline');
    const hasClineExt = vscode.extensions.all.some(e => e.id === 'saoudrizwan.claude-dev' || /cline/i.test(e.id));
    agents.push({
      id: 'cline',
      name: 'Cline Autonomous Agent',
      installed: !!clineCli || hasClineExt,
      cliPath: clineCli,
      detail: clineCli ? `Terdeteksi di: ${clineCli}` : (hasClineExt ? 'Ekstensi VS Code Cline aktif' : 'Belum terpasang di sistem')
    });

    // 4. Roo Code Autonomous Dev
    const rooCli = findCli('roo') || findCli('roo-cline');
    const hasRooExt = vscode.extensions.all.some(e => /roo-cline/i.test(e.id));
    agents.push({
      id: 'roo',
      name: 'Roo Code Autonomous Agent',
      installed: !!rooCli || hasRooExt,
      cliPath: rooCli,
      detail: rooCli ? `Terdeteksi di: ${rooCli}` : (hasRooExt ? 'Ekstensi VS Code Roo Code aktif' : 'Belum terpasang di sistem')
    });

    // 5. Gemini CLI / Code Assist
    const geminiCli = findCli('gemini');
    const hasGeminiExt = vscode.extensions.all.some(e => /gemini/i.test(e.id));
    agents.push({
      id: 'gemini',
      name: 'Gemini CLI / Code Assist',
      installed: !!geminiCli || hasGeminiExt,
      cliPath: geminiCli,
      detail: geminiCli ? `Terdeteksi di: ${geminiCli}` : (hasGeminiExt ? 'Ekstensi Gemini aktif' : 'Belum terpasang di sistem')
    });

    // 6. GitHub Copilot
    const copilotCli = findCli('copilot') || findCli('gh');
    const hasCopilotExt = vscode.extensions.all.some(e => e.id.toLowerCase().includes('copilot'));
    agents.push({
      id: 'copilot',
      name: 'GitHub Copilot',
      installed: !!copilotCli || hasCopilotExt,
      cliPath: copilotCli,
      detail: hasCopilotExt ? 'Ekstensi GitHub Copilot aktif di VS Code' : (copilotCli ? `CLI: ${copilotCli}` : 'Belum terpasang di sistem')
    });

    return agents;
  }

  // Format Prompt Resmi AI Agent (dengan instruksi pemantauan berkala & eksekusi sekuensial)
  function buildAgentPrompt(taskId = '', instruction = '') {
    const periodicDirective = 'Baca TASK_QUEUE.md secara berkala, buat task berkala untuk membaca dan kerjakan yang berstatus waiting satu persatu.';
    if (taskId) {
      return `Periksa file TASK_QUEUE.md dan selesaikan tugas ${taskId} ("${instruction || ''}") sampai tuntas 100% sesuai AGENTS.md. ${periodicDirective}`;
    }
    return `Periksa file TASK_QUEUE.md dan selesaikan seluruh tugas aktif sampai tuntas 100% sesuai AGENTS.md. ${periodicDirective}`;
  }

  // Eksekusi tugas melalui Agent Handler terpilih
  async function executeTaskWithAgent(taskId, instruction, agentOverride = null) {
    const cur = getEffectiveConfig();
    const handler = (agentOverride || cur.agentHandler || '').trim();

    // Sesuai aturan: Jika kosong, TIDAK AKAN EKSEKUSI APAPUN
    if (!handler || handler === 'none') {
      return { executed: false, reason: 'Agent Handler kosong / dinonaktifkan (hanya mencatat ke antrean)' };
    }

    const targetFolder = cur.folder || process.cwd();
    const queueFile = path.join(targetFolder, 'TASK_QUEUE.md');

    // Aturan Sekuensial: Periksa apakah ada tugas lain yang masih [IN_PROGRESS]
    if (taskId && fs.existsSync(queueFile)) {
      try {
        const qText = fs.readFileSync(queueFile, 'utf-8');
        const activeSection = qText.split('## ✅ Riwayat Tugas Selesai')[0];
        const regexProg = /###\s+\[IN_PROGRESS\]\s+(#?TASK-[A-Za-z0-9_-]+)/gi;
        let mProg;
        const cleanCurrent = taskId.toLowerCase().replace(/^#/, '');
        while ((mProg = regexProg.exec(activeSection)) !== null) {
          const foundId = mProg[1];
          if (foundId.toLowerCase().replace(/^#/, '') !== cleanCurrent) {
            const warnMsg = `⚠️ [Tele-vs-Code] Tugas ${foundId} masih berstatus [IN_PROGRESS]. Berdasarkan aturan, tugas harus dikerjakan satu per satu secara sekuensial.`;
            log('warn', warnMsg);
            vscode.window.showWarningMessage(warnMsg);
            return { executed: false, reason: 'Ada tugas lain yang sedang IN_PROGRESS' };
          }
        }
      } catch (qErr) {}
    }

    // Panggil tele-task start untuk mengubah status ke IN_PROGRESS jika taskId disediakan
    if (taskId) {
      try {
        const taskCandidates = [
          path.join(targetFolder, '.vscode', 'tele-vs-code', 'tele-task.js'),
          path.join(targetFolder, 'tele-task.js')
        ];
        const taskScript = taskCandidates.find(p => fs.existsSync(p));
        if (taskScript) {
          execSync(`node "${taskScript}" start "${taskId}" --force`, { cwd: targetFolder, stdio: 'pipe' });
        }
      } catch (e) {}
    }
    const prompt = buildAgentPrompt(taskId, instruction);

    let activeHandler = handler;
    if (activeHandler === 'auto') {
      // Prioritas 1: Cari CLI agent yang benar-benar terpasang di PATH
      const detectedCli = detectAvailableAgents().find(a => a.installed && !a.isIde && a.cliPath);
      if (detectedCli) {
        activeHandler = detectedCli.id;
      } else {
        // SOLUSI jika CLI agents tidak terinstall dan yang ada IDE agents:
        // Otomatis arahkan ke 'ide' (Agent IDE) dengan penyalinan prompt & buka Chat
        activeHandler = 'ide';
        log('info', 'CLI Agent tidak ditemukan di PATH. Mengalihkan otomatis ke Agent IDE.');
      }
    }

    // Penanganan khusus untuk Agent IDE (Chat IDE interaktif, tanpa perlu terminal CLI)
    if (activeHandler === 'ide') {
      try {
        await vscode.env.clipboard.writeText(prompt);
      } catch (clipErr) {
        log('warn', `Gagal menyalin prompt ke clipboard: ${clipErr.message}`);
      }

      // Buka panel chat IDE
      try {
        await vscode.commands.executeCommand('workbench.action.chat.open');
      } catch (chatErr) {
        try {
          await vscode.commands.executeCommand('workbench.action.quickchat.open');
        } catch (qcErr) {}
      }

      const taskDisplay = taskId ? ` ${taskId}` : '';
      const msg = (cur.language === 'en'
        ? `📋 [Agent IDE] Task${taskDisplay} prompt copied to clipboard and Chat opened! Paste (Ctrl+V) and send to Agent IDE.`
        : `📋 [Agent IDE] Prompt tugas${taskDisplay} disalin ke clipboard dan Chat dibuka! Tempel (Ctrl+V) dan kirim ke Agent IDE.`);

      showTimedNotification(msg, 15, 'Buka Chat', 'Buka TASK_QUEUE.md').then(async (act) => {
        if (act === 'Buka Chat') {
          try {
            await vscode.commands.executeCommand('workbench.action.chat.open');
          } catch (e) {
            await vscode.commands.executeCommand('workbench.action.quickchat.open');
          }
        } else if (act === 'Buka TASK_QUEUE.md') {
          if (fs.existsSync(queueFile)) {
            const doc = await vscode.workspace.openTextDocument(queueFile);
            await vscode.window.showTextDocument(doc);
          }
        }
      });

      return { executed: true, handler: 'ide', method: 'clipboard+chat_open' };
    }

    // Terminal runner untuk CLI Agents
    let term = vscode.window.terminals.find(t => t.name === 'Tele-vs-Code Agent');
    if (!term) {
      term = vscode.window.createTerminal({ name: 'Tele-vs-Code Agent', cwd: targetFolder });
    }
    term.show();

    if (activeHandler === 'custom') {
      const rawCmd = (cur.customAgentCommand || '').trim();
      if (!rawCmd) {
        vscode.window.showWarningMessage('⚠️ [Tele-vs-Code] Custom Agent Command masih kosong. Atur via menu "Pilih AI Agent Handler".');
        return { executed: false, reason: 'Custom command kosong' };
      }
      const finalCmd = rawCmd
        .replace(/\{task_id\}/g, taskId || '')
        .replace(/\{instruction\}/g, (instruction || '').replace(/"/g, '\"'))
        .replace(/\{prompt\}/g, prompt.replace(/"/g, '\"'));
      term.sendText(finalCmd);
      vscode.window.showInformationMessage(`🚀 Menjalankan Custom Command Agent untuk tugas ${taskId || ''}...`);
      return { executed: true, handler: 'custom', command: finalCmd };
    }

    if (activeHandler === 'agy') {
      const agyBin = findCli('agy');
      const cmd = agyBin ? `"${agyBin}"` : 'agy';
      if (process.platform === 'win32') {
        term.sendText(`& ${cmd} --dangerously-skip-permissions -p "${prompt.replace(/"/g, '`"')}"`);
      } else {
        term.sendText(`${cmd} --dangerously-skip-permissions -p "${prompt.replace(/"/g, '\"')}"`);
      }
      vscode.window.showInformationMessage(`🤖 Antigravity CLI (agy) sedang mengeksekusi tugas ${taskId || ''}...`);
      return { executed: true, handler: 'agy' };
    }

    if (activeHandler === 'claude') {
      const cmd = process.platform === 'win32' ? 'claude.cmd' : 'claude';
      term.sendText(`${cmd} -p "${prompt.replace(/"/g, '\"')}"`);
      vscode.window.showInformationMessage(`🧠 Claude Code sedang mengeksekusi tugas ${taskId || ''}...`);
      return { executed: true, handler: 'claude' };
    }

    if (activeHandler === 'cline') {
      term.sendText(`cline -y "${prompt.replace(/"/g, '\"')}"`);
      vscode.window.showInformationMessage(`⚡ Cline sedang mengeksekusi tugas ${taskId || ''}...`);
      return { executed: true, handler: 'cline' };
    }

    if (activeHandler === 'roo') {
      term.sendText(`roo -y "${prompt.replace(/"/g, '\"')}"`);
      vscode.window.showInformationMessage(`🦘 Roo Code sedang mengeksekusi tugas ${taskId || ''}...`);
      return { executed: true, handler: 'roo' };
    }

    if (activeHandler === 'gemini') {
      term.sendText(`gemini -p "${prompt.replace(/"/g, '\"')}"`);
      vscode.window.showInformationMessage(`♊ Gemini CLI sedang mengeksekusi tugas ${taskId || ''}...`);
      return { executed: true, handler: 'gemini' };
    }

    if (activeHandler === 'copilot') {
      term.sendText(`gh copilot explain "${(instruction || '').replace(/"/g, '\"')}"`);
      vscode.window.showInformationMessage(`🐙 GitHub Copilot dipanggil untuk tugas ${taskId || ''}...`);
      return { executed: true, handler: 'copilot' };
    }

    return { executed: false, reason: `Handler ${activeHandler} tidak didukung` };
  }

  // Helper Notifikasi Toast dengan Tombol "Close" dan Auto-Close 10 Detik
  function showTimedNotification(message, timeoutSec = 10, ...actions) {
    const btns = actions.length > 0 ? [...actions, 'Close'] : ['Close'];
    const p = vscode.window.showInformationMessage(message, ...btns);
    if (timeoutSec > 0) {
      setTimeout(() => {
        try {
          vscode.commands.executeCommand('notifications.hideToasts');
        } catch (e) {}
      }, timeoutSec * 1000);
    }
    return p;
  }

  // Status Bar State Machine (Requirement 2, 6, 7: idle / proses / warning dengan warna & ikon & popup)
  let currentWorkerState = 'idle'; // 'idle' | 'proses' | 'warning'
  let currentActivityText = '';
  let currentActivityDetail = '';
  let lastWarningMessage = '';
  let lastWarningTime = 0;

  function setStatusBarState(state, activity = '', detail = '', isQuiet = false) {
    currentWorkerState = state;
    currentActivityText = activity;
    currentActivityDetail = detail || activity;

    // Catat selalu ke log jika state warning
    if (state === 'warning' && detail) {
      log('warn', `[STATUS BAR WARNING] ${activity ? activity + ': ' : ''}${detail}`);
    }

    // Aturan User: Jika koneksi webhook terputus, network error, server offline, atau isQuiet:
    // JANGAN munculkan dialog pop-up, biarkan tampil sebagai indikator warning di status bar dan tercatat di log.
    const isNetworkOrWebhookError = /koneksi|server|webhook|network|fetch|timeout|econnrefused|enotfound|offline/i.test(`${activity} ${detail}`);

    if (state === 'warning' && detail && !isNetworkOrWebhookError && !isQuiet) {
      const now = Date.now();
      if (now - lastWarningTime > 25000 || detail !== lastWarningMessage) {
        lastWarningTime = now;
        lastWarningMessage = detail;
        vscode.window.showWarningMessage(`⚠️ [Tele-vs-Code Warning] ${detail}`, 'Buka Menu', 'Lihat Log').then(act => {
          if (act === 'Buka Menu') vscode.commands.executeCommand('telegramNotifier.showMenu');
          else if (act === 'Lihat Log') outputChannel.show();
        });
      }
    }
    updateStatusBar();
  }

  // 1. Inisialisasi otomatis berkas tool ke .vscode/tele-vs-code & wrapper root
  ensureWorkspaceTools(context, curInitial.folder);

  const taskProvider = new TaskQueueProvider(() => getEffectiveConfig().folder);
  vscode.window.registerTreeDataProvider('telegramNotifier.taskView', taskProvider);

  // Status Bar Item
  const statusBar = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Right, 100);
  async function updateStatusBar() {
    updateStatusBarAgent();
    const cur = getEffectiveConfig();
    const resolved = await getResolvedProject();
    const prjTag = resolved.name ? ` [${resolved.name}]` : (resolved.projectKey ? ` [${resolved.projectKey}]` : "");
    const codeTag = resolved.code ? ` (#${resolved.code})` : "";

    // State A: Belum Terhubung (Warning Setup)
    if (!cur.project || !resolved.projectKey || !cur.secretKey) {
      statusBar.text = `$(warning) Tele-vs-Code: ${t("setupRequired")}`;
      statusBar.color = '#f87171'; // Red Warning
      statusBar.backgroundColor = new vscode.ThemeColor('statusBarItem.warningBackground');
      statusBar.tooltip = t("statusBarSetupTooltip");
      statusBar.command = "telegramNotifier.showMenu";
      statusBar.show();
      return;
    }

    // State B: Worker Dijeda / Nonaktif
    if (!isWorkerActive) {
      statusBar.text = `$(bell-slash) Tele-vs-Code${prjTag}: ${t("workerPaused")}`;
      statusBar.color = '#94a3b8'; // Muted
      statusBar.backgroundColor = undefined;
      statusBar.tooltip = t("statusBarPausedTooltip", { name: resolved.name, code: codeTag });
      statusBar.command = "telegramNotifier.showMenu";
      statusBar.show();
      return;
    }

    // State C: PROSES (Sedang Menangani Pekerjaan / Tarik Antrean)
    if (currentWorkerState === 'proses') {
      const act = currentActivityText || 'Memproses Tugas...';
      statusBar.text = `$(sync~spin) Tele-vs-Code: ${act}`;
      statusBar.color = '#38bdf8'; // Sky Blue
      statusBar.backgroundColor = undefined;
      statusBar.tooltip = `Tele-vs-Code (${resolved.name}${codeTag})\nStatus: SEDANG BEKERJA (PROSES)\nAktivitas: ${currentActivityDetail || act}\nKlik untuk melihat log`;
    }
    // State D: WARNING (Terjadi Kendala / Error)
    else if (currentWorkerState === 'warning') {
      const warn = currentActivityText || 'Peringatan';
      statusBar.text = `$(warning) Tele-vs-Code: ${warn}`;
      statusBar.color = '#f87171'; // Red
      statusBar.backgroundColor = new vscode.ThemeColor('statusBarItem.warningBackground');
      statusBar.tooltip = `⚠️ Peringatan Tele-vs-Code:\n${lastWarningMessage || currentActivityDetail || warn}\nKlik untuk membuka menu atau periksa log`;
    }
    // State E: IDLE (Siap Memantau Server)
    else {
      statusBar.text = `$(radio-tower) Tele-vs-Code${prjTag}: Idle (${cur.pullInterval}s)`;
      statusBar.color = '#4ade80'; // Bright Green
      statusBar.backgroundColor = undefined;
      statusBar.tooltip = `Tele-vs-Code (${resolved.name}${codeTag})\nStatus: IDLE / SIAP (Memantau setiap ${cur.pullInterval}s)\nFolder: ${cur.folder}\nKlik untuk membuka menu`;
    }

    statusBar.command = "telegramNotifier.showMenu";
    statusBar.show();
  }
  updateStatusBar();
  statusBar.command = 'telegramNotifier.showMenu';
  statusBar.show();
  context.subscriptions.push(statusBar);

  // Status Bar Item untuk AI Agent Handler
  const statusBarAgent = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Right, 99);
  function updateStatusBarAgent() {
    const cur = getEffectiveConfig();
    const h = (cur.agentHandler || '').trim();
    if (!h || h === 'none') {
      statusBarAgent.text = '$(robot) Agent: None (Idle)';
      statusBarAgent.color = '#94a3b8';
      statusBarAgent.tooltip = t('statusBarAgentTooltip', { handler: cur.language === 'en' ? 'None / Disabled' : 'Nonaktif / Kosong' });
    } else if (h === 'auto') {
      statusBarAgent.text = '$(robot) Agent: Auto';
      statusBarAgent.color = '#38bdf8';
      statusBarAgent.tooltip = 'AI Agent Handler: Deteksi Otomatis (Klik untuk mengubah)';
    } else if (h === 'ide') {
      statusBarAgent.text = '$(comment-discussion) Agent: IDE Chat';
      statusBarAgent.color = '#38bdf8';
      statusBarAgent.tooltip = 'AI Agent Handler: Agent IDE (Chat IDE Aktif)\nKlik untuk mengubah';
    } else if (h === 'custom') {
      statusBarAgent.text = '$(tools) Agent: Custom';
      statusBarAgent.color = '#fbbf24';
      statusBarAgent.tooltip = `AI Agent Handler: Custom (${cur.customAgentCommand || 'Belum diisi'})\nKlik untuk mengubah`;
    } else {
      statusBarAgent.text = `$(robot) Agent: ${h.toUpperCase()}`;
      statusBarAgent.color = '#a78bfa';
      statusBarAgent.tooltip = `AI Agent Handler: ${h.toUpperCase()} aktif\nKlik untuk mengubah`;
    }
    statusBarAgent.command = 'telegramNotifier.selectAgentHandler';
    statusBarAgent.show();
  }
  updateStatusBarAgent();
  context.subscriptions.push(statusBarAgent);

  context.subscriptions.push(vscode.workspace.onDidChangeWorkspaceFolders(() => {
    const cur = getEffectiveConfig();
    ensureWorkspaceTools(context, cur.folder);
    updateStatusBar();
    taskProvider.refresh();
  }));
  context.subscriptions.push(vscode.workspace.onDidChangeConfiguration(e => {
    if (e.affectsConfiguration('telegramNotifier')) {
      const cur = getEffectiveConfig();
      ensureWorkspaceTools(context, cur.folder);
      updateStatusBar();
      taskProvider.refresh();
      if (cur.autoPull && !isWorkerActive) {
        startAutoWorker();
      }
    }
  }));
  context.subscriptions.push(vscode.window.onDidChangeWindowState(state => {
    if (state.focused) {
      pullTaskQueue(true);
    }
  }));

  // Watcher TASK_QUEUE.md: deteksi tugas selesai secara live & tampilkan popup selesai
  let lastKnownDoneIds = new Set();
  function checkDoneTasksInQueue(targetFolder) {
    const qFile = path.join(targetFolder, 'TASK_QUEUE.md');
    if (!fs.existsSync(qFile)) return;
    try {
      const qContent = fs.readFileSync(qFile, 'utf-8');
      const doneSection = qContent.split('## ✅ Riwayat Tugas Selesai')[1] || '';
      const regex = /###\s+\[DONE\]\s+(#?TASK-[A-Za-z0-9_-]+)[\r\n]+(?:-\s+\*\*Waktu:\*\*\s*[^\r\n]*[\r\n]+)?(?:-\s+\*\*Pengirim:\*\*\s*[^\r\n]*[\r\n]+)?(?:-\s+\*\*Instruksi:\*\*\s*([^\r\n]*))?/gi;
      let m;
      while ((m = regex.exec(doneSection)) !== null) {
        const dId = m[1].trim();
        const dInst = (m[2] || '').trim();
        if (lastKnownDoneIds.size > 0 && !lastKnownDoneIds.has(dId)) {
          const instSnippet = dInst ? (dInst.length > 50 ? dInst.substring(0, 50) + '...' : dInst) : '';
          showTimedNotification(`✅ Tugas [${dId}] Selesai 100%! "${instSnippet}"`, 10);
          taskProvider.refresh();
        }
        lastKnownDoneIds.add(dId);
      }
    } catch (e) {}
  }
  checkDoneTasksInQueue(curInitial.folder);

  try {
    const qWatcher = vscode.workspace.createFileSystemWatcher('**/TASK_QUEUE.md');
    qWatcher.onDidChange(() => {
      checkDoneTasksInQueue(getEffectiveConfig().folder);
      taskProvider.refresh();
    });
    qWatcher.onDidCreate(() => {
      checkDoneTasksInQueue(getEffectiveConfig().folder);
      taskProvider.refresh();
    });
    context.subscriptions.push(qWatcher);
  } catch (wErr) {}

// Autonomous Task Execution Engine for VS Code Extension
function tryExecuteDirectTask(instruction, targetDir) {
  const inst = instruction.trim();

  // 1. Pembuatan Folder / Direktori
  // Contoh: "buat folder ddfa", "buat foldsr ssf", "mkdir components", "bikin folder src/assets"
  const mFolder = inst.match(/^(?:buat(?:kan)?|bikin(?:kan)?|create|make)?\s*(?:folder|foldsr|dir|direktori)\s+(.+)$/i) ||
                  inst.match(/^mkdir\s+(.+)$/i);
  if (mFolder) {
    const rawName = mFolder[1].trim().replace(/^['"`]|['"`]$/g, '');
    const cleanRel = path.normalize(rawName).replace(/^(\.\.[\/\\])+/, '');
    const fullPath = path.resolve(targetDir, cleanRel);
    if (!fullPath.startsWith(path.resolve(targetDir))) {
      throw new Error(`Path tidak aman di luar workspace: ${rawName}`);
    }
    fs.mkdirSync(fullPath, { recursive: true });
    return {
      executed: true,
      report: `Folder "${cleanRel}" berhasil dibuat di direktori workspace`,
      type: 'mkdir'
    };
  }

  // 2. Pembuatan Dokumen / File
  // Contoh: "buat dokumen dengan nama xyz.txt", "buat file data.json", "touch readme.md"
  const mFile = inst.match(/^(?:buat(?:kan)?|bikin(?:kan)?|create|touch)?\s*(?:file|dokumen|document|berkas)\s+(?:dengan\s+nama\s+)?([^\s\r\n]+)(?:\s+(?:isinya|isi|content)\s+([\s\S]*))?$/i) ||
                inst.match(/^touch\s+([^\s\r\n]+)$/i);
  if (mFile) {
    const rawName = mFile[1].trim().replace(/^['"`]|['"`]$/g, '');
    const fileContent = mFile[2] ? mFile[2].trim() : '';
    const cleanRel = path.normalize(rawName).replace(/^(\.\.[\/\\])+/, '');
    const fullPath = path.resolve(targetDir, cleanRel);
    if (!fullPath.startsWith(path.resolve(targetDir))) {
      throw new Error(`Path tidak aman di luar workspace: ${rawName}`);
    }
    fs.mkdirSync(path.dirname(fullPath), { recursive: true });
    fs.writeFileSync(fullPath, fileContent, 'utf-8');
    return {
      executed: true,
      report: `Dokumen "${cleanRel}" berhasil dibuat di workspace` + (fileContent ? ` (isi: ${fileContent.length} karakter)` : ''),
      type: 'touch'
    };
  }

  // 3. Penghapusan File / Folder Aman
  const mDelete = inst.match(/^(?:hapus(?:kan)?|delete|remove|rm|del)\s*(?:folder|dir|file|dokumen|berkas)?\s+([^\s\r\n]+)$/i);
  if (mDelete) {
    const rawName = mDelete[1].trim().replace(/^['"`]|['"`]$/g, '');
    const cleanRel = path.normalize(rawName).replace(/^(\.\.[\/\\])+/, '');
    const fullPath = path.resolve(targetDir, cleanRel);
    if (!fullPath.startsWith(path.resolve(targetDir))) {
      throw new Error(`Path tidak aman di luar workspace: ${rawName}`);
    }
    if (fs.existsSync(fullPath)) {
      const isDir = fs.statSync(fullPath).isDirectory();
      fs.rmSync(fullPath, { recursive: true, force: true });
      return {
        executed: true,
        report: `${isDir ? 'Folder' : 'File'} "${cleanRel}" berhasil dihapus dari workspace`,
        type: 'delete'
      };
    } else {
      return {
        executed: true,
        report: `Target "${cleanRel}" sudah tidak ada di workspace`,
        type: 'delete'
      };
    }
  }

  // 4. Eksekusi Perintah Terminal / Shell
  const mShell = inst.match(/^(?:cmd|exec|run|sh|terminal|bash)[:\s]+([\s\S]+)$/i) ||
                 inst.match(/^(git|npm|node|python|composer|dir|ls|echo|type|cat)\s+([\s\S]+)$/i);
  if (mShell) {
    const cmdStr = mShell[2] !== undefined ? `${mShell[1]} ${mShell[2]}` : mShell[1];
    try {
      const output = execSync(cmdStr, {
        cwd: targetDir,
        encoding: 'utf-8',
        timeout: 60000,
        windowsHide: true,
        maxBuffer: 1024 * 1024 * 2
      });
      const trimmed = (output || '').trim();
      const snippet = trimmed.length > 200 ? trimmed.substring(0, 200) + '...' : trimmed;
      return {
        executed: true,
        report: `Perintah [${cmdStr}] berhasil dieksekusi:\n${snippet || '(Selesai tanpa output)'}`,
        type: 'shell',
        rawOutput: trimmed
      };
    } catch (cErr) {
      const errMsg = (cErr.stderr || cErr.stdout || cErr.message || '').trim();
      const snippet = errMsg.length > 200 ? errMsg.substring(0, 200) + '...' : errMsg;
      throw new Error(`Gagal eksekusi [${cmdStr}]: ${snippet}`);
    }
  }

  // 5. Restart / Reload VS Code Window
  const mRestart = inst.match(/^(?:restart|reload)\s*(?:vscode|vs\s*code|window|windows)?$/i);
  if (mRestart) {
    setTimeout(() => {
      try {
        vscode.commands.executeCommand('workbench.action.reloadWindow');
      } catch (rErr) {}
    }, 1500);
    return {
      executed: true,
      report: 'Perintah reload window VS Code berhasil dipicu.',
      type: 'reload'
    };
  }

  return { executed: false };
}

async function markServerTaskDone(cur, taskId, notes = '') {
  try {
    const url = `${cur.webhookUrl.replace(/\/?$/, '/')}?action=done&secret=${cur.secretKey}&id=${encodeURIComponent(taskId)}`;
    await safeFetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `notes=${encodeURIComponent(notes)}`
    });
  } catch (e) {}
}

async function markServerTaskFailed(cur, taskId, notes = '') {
  try {
    const url = `${cur.webhookUrl.replace(/\/?$/, '/')}?action=failed&secret=${cur.secretKey}&id=${encodeURIComponent(taskId)}`;
    await safeFetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `notes=${encodeURIComponent(notes)}`
    });
  } catch (e) {}
}

async function processActiveLocalTasks(cur) {
  const queueFile = path.join(cur.folder, 'TASK_QUEUE.md');
  if (!fs.existsSync(queueFile)) return;
  try {
    const queueContent = fs.readFileSync(queueFile, 'utf-8');
    const activeSection = queueContent.split('## ✅ Riwayat Tugas Selesai')[0];
    const regex = /###\s+\[(PENDING|IN_PROGRESS)\]\s+(#?TASK-[A-Za-z0-9_-]+)[\r\n]+([\s\S]*?)(?=(?:###\s+\[|##|$))/gi;
    let match;
    while ((match = regex.exec(activeSection)) !== null) {
      const taskId = match[2].startsWith('#') ? match[2] : `#${match[2]}`;
      const block = match[3];
      const mInst = block.match(/-\s+\*\*Instruksi:\*\*\s*([^\r\n]+)/i);
      const mSender = block.match(/-\s+\*\*Pengirim:\*\*\s*([^\r\n]+)/i);
      const mTime = block.match(/-\s+\*\*Waktu:\*\*\s*([^\r\n]+)/i);

      if (mInst) {
        const inst = mInst[1].trim();
        const sender = mSender ? mSender[1].trim() : 'Telegram';
        const taskObj = {
          id: taskId,
          instruction: inst,
          sender: sender,
          created_at: mTime ? mTime[1].trim() : new Date().toISOString()
        };
        try {
          const execRes = tryExecuteDirectTask(inst, cur.folder);
          if (execRes && execRes.executed) {
            const rep = execRes.report;
            await markServerTaskDone(cur, taskObj.id, rep);
            appendDoneToTaskQueueFile(queueFile, taskObj, rep);
            try {
              await sendTelegramMessage(cur.botToken, cur.chatId, `Tugas Selesai: ${taskObj.id}`, 'SELESAI', [
                `Instruksi: ${inst.substring(0, 90)}`,
                `Hasil: ${rep}`,
                `Pengirim: ${sender}`
              ], cur.project, cur.timezone);
            } catch (nErr) {}
            showTimedNotification(`✅ Tugas [${taskObj.id}] berhasil dieksekusi: ${rep}`, 10);
          }
        } catch (execErr) {
          await markServerTaskFailed(cur, taskObj.id, execErr.message);
        }
      }
    }
  } catch (e) {}
}

function appendDoneToTaskQueueFile(queueFile, task, report) {
  try {
    if (!fs.existsSync(queueFile)) return;
    let content = fs.readFileSync(queueFile, 'utf-8');
    const regexActive = new RegExp(`### \\[(PENDING|IN_PROGRESS)\\]\\s+${task.id}[\\s\\S]*?(?=(###|##|$))`, 'g');
    content = content.replace(regexActive, '');
    const agentName = task.assigned_agent || task.agent || 'Antigravity AI Agent';
    const doneBlock = `### [DONE] ${task.id}\n- **Waktu:** ${task.created_at || new Date().toISOString().replace('T', ' ').substring(0, 19)}\n- **Pengirim:** ${task.sender}\n- **Agent:** ${agentName}\n- **Instruksi:** ${task.instruction.replace(/\r?\n/g, ' ')}\n- **Status:** DONE\n- **Laporan Agent:** ${report}\n\n`;
    const doneMarker = '## ✅ Riwayat Tugas Selesai';
    if (content.includes(doneMarker)) {
      content = content.replace(doneMarker, `${doneMarker}\n\n${doneBlock}`);
    } else {
      content += `\n\n${doneMarker}\n\n${doneBlock}`;
    }
    fs.writeFileSync(queueFile, content, 'utf-8');
  } catch (e) {}
}

  let consecutivePullFailures = 0;

  // Helper Tarik Antrean Tugas (dengan Heartbeat Multi-Workspace)
  async function pullTaskQueue(silent = false) {
    if (isPulling) return;
    isPulling = true;
    const cur = getEffectiveConfig();

    if (!cur.secretKey) {
      isPulling = false;
      setStatusBarState('warning', 'Secret Key Kosong', 'API Secret Key belum diatur');
      if (!silent) {
        vscode.window.showWarningMessage('API Secret Key belum diatur. Pilih "Hubungkan ke Server Biru" di menu Tele-vs-Code.');
      }
      return;
    }

    setStatusBarState('proses', 'Memeriksa Server...', 'Worker memeriksa antrean tugas ke server');
    log('info', `Worker memeriksa antrean tugas ke ${cur.webhookUrl} (Polling ${cur.pullInterval}s)...`);

    // 1. Eksekusi terlebih dahulu tugas lokal yang masih aktif di TASK_QUEUE.md (jika autoExecuteSimpleTasks aktif)
    if (cur.autoExecuteSimpleTasks) {
      await processActiveLocalTasks(cur);
    }

    const operator = `${os.userInfo().username}@${os.hostname()}`;
    let url = `${cur.webhookUrl.replace(/\/?$/, '/')}?action=pull&secret=${cur.secretKey}&limit=5&format=json&mark=0&workspace_folder=${encodeURIComponent(cur.folder)}&workspace_name=${encodeURIComponent(path.basename(cur.folder))}&operator=${encodeURIComponent(operator)}`;
    if (cur.project) {
      url += `&project=${encodeURIComponent(cur.project)}`;
    }

    try {
      const res = await safeFetch(url, { timeout: 15000 }, 2);
      const data = await res.json();
      if (data.ok && Array.isArray(data.tasks) && data.tasks.length > 0) {
        const queueFile = path.join(cur.folder, 'TASK_QUEUE.md');
        let content = fs.existsSync(queueFile)
          ? fs.readFileSync(queueFile, 'utf-8')
          : '# TASK QUEUE\n\n## ⏳ Tugas Aktif\n\n## ✅ Riwayat Tugas Selesai\n';

        let newTasksCount = 0;
        consecutivePullFailures = 0;
        log('info', `Ditemukan ${data.tasks.length} tugas di antrean server.`);
        for (const taskItem of data.tasks) {
          setStatusBarState('proses', `Menangani [${taskItem.id}]`, `Sedang menangani tugas ${taskItem.id}: "${taskItem.instruction}"`);
          log('info', `[TUGAS MASUK] ID: ${taskItem.id} | Pengirim: ${taskItem.sender} | Instruksi: "${taskItem.instruction}"`);
          let attachLines = '';
          if (taskItem.attachment_url) {
            try {
              const attachDir = path.join(cur.folder, 'attachments', taskItem.id.replace(/[^a-zA-Z0-9_-]/g, ''));
              if (!fs.existsSync(attachDir)) {
                fs.mkdirSync(attachDir, { recursive: true });
              }
              const localFilePath = path.join(attachDir, taskItem.attachment_name || 'lampiran.bin');
              if (!fs.existsSync(localFilePath)) {
                const fRes = await fetch(taskItem.attachment_url);
                if (fRes.ok) {
                  const fBuf = Buffer.from(await fRes.arrayBuffer());
                  fs.writeFileSync(localFilePath, fBuf);
                }
              }
              const relPath = path.relative(cur.folder, localFilePath).replace(/\\/g, '/');
              const absFileUrl = `file:///${localFilePath.replace(/\\/g, '/')}`;
              attachLines = `\n- **Lampiran Lokal:** [${taskItem.attachment_name || 'Buka File'}](${absFileUrl}) (${taskItem.attachment_type || 'file'})\n- **URL Lampiran:** [Buka di Browser](${taskItem.attachment_url})`;
            } catch (dErr) {
              attachLines = `\n- **Lampiran:** [${taskItem.attachment_name || 'File'}](${taskItem.attachment_url}) (${taskItem.attachment_type || 'file'})`;
            }
          }

          // Catat tugas ke TASK_QUEUE.md (Hak ubah status: Tugas baru dari server masuk sebagai PENDING)
          const agentName = taskItem.assigned_agent || taskItem.agent || 'Antigravity AI Agent';
          const targetStatus = (taskItem.status === 'DONE' || taskItem.status === 'FAILED') ? taskItem.status : (taskItem.status === 'IN_PROGRESS' ? 'IN_PROGRESS' : 'PENDING');
          const targetNote = targetStatus === 'IN_PROGRESS' ? 'Sedang dikerjakan oleh AI Agent...' : 'Menunggu giliran eksekusi oleh AI Agent...';
          const taskBlock = `### [${targetStatus}] ${taskItem.id}\n- **Waktu:** ${taskItem.created_at}\n- **Pengirim:** ${taskItem.sender}\n- **Agent:** ${agentName}\n- **Instruksi:** ${taskItem.instruction}${attachLines}\n- **Status:** ${targetStatus}\n- **Catatan:** ${targetNote}\n\n`;
          let isNewTask = false;
          if (!content.includes(taskItem.id)) {
            newTasksCount++;
            isNewTask = true;
            if (content.includes('## ⏳ Tugas Aktif')) {
              content = content.replace('## ⏳ Tugas Aktif', `## ⏳ Tugas Aktif\n\n${taskBlock}`);
              content = content.replace(/\*\(Tidak ada tugas aktif di antrean saat ini[^\)]*\)\*/g, '');
            } else {
              content = `## ⏳ Tugas Aktif\n\n${taskBlock}\n\n${content}`;
            }
          }
          fs.writeFileSync(queueFile, content, 'utf-8');

          // Kirim notifikasi antrean ke Telegram jika tugas baru masuk
          if (isNewTask && taskItem.status !== 'DONE' && !cur.autoExecuteSimpleTasks) {
            try {
              const notifyStatus = targetStatus === 'IN_PROGRESS' ? 'PROSES' : 'INFO';
              const notifyTitle = targetStatus === 'IN_PROGRESS' ? `Memulai Pekerjaan: ${taskItem.id}` : `Tugas Masuk Antrean: ${taskItem.id}`;
              const statusDesc = targetStatus === 'IN_PROGRESS'
                ? 'Diterima oleh VS Code & sedang dikerjakan oleh AI Agent...'
                : 'Masuk Antrean [PENDING] (Menunggu giliran eksekusi AI Agent)';
              await sendTelegramMessage(cur.botToken, cur.chatId, notifyTitle, notifyStatus, [
                `Perintah: ${taskItem.instruction}`,
                `Pengirim: ${taskItem.sender}`,
                `Waktu Diterima: ${taskItem.created_at || 'Baru saja'}`,
                `Status: ${statusDesc}`
              ], cur.project, cur.timezone);
            } catch (pErr) {}
          }

          // EKSEKUTOR OTOMATIS: Hanya jika diaktifkan (default: false agar tugas tetap di TASK_QUEUE untuk AI Agent)
          if (cur.autoExecuteSimpleTasks) {
            try {
              const execRes = tryExecuteDirectTask(taskItem.instruction, cur.folder);
              if (execRes && execRes.executed) {
                const rep = execRes.report;
                await markServerTaskDone(cur, taskItem.id, rep);
                appendDoneToTaskQueueFile(queueFile, taskItem, rep);
                content = fs.readFileSync(queueFile, 'utf-8');

                try {
                  await sendTelegramMessage(cur.botToken, cur.chatId, `Tugas Selesai: ${taskItem.id}`, 'SELESAI', [
                    `Instruksi: ${taskItem.instruction.substring(0, 90)}`,
                    `Hasil: ${rep}`,
                    `Pengirim: ${taskItem.sender}`
                  ], cur.project, cur.timezone);
                } catch (nErr) {}

                log('info', `[TUGAS SELESAI] ID: ${taskItem.id} tuntas dieksekusi: ${rep}`);
                showTimedNotification(`✅ Tugas [${taskItem.id}] berhasil dieksekusi: ${rep}`, 10);
                setStatusBarState('idle');
                continue;
              }
            } catch (execErr) {
              await markServerTaskFailed(cur, taskItem.id, execErr.message);
              log('error', `[TUGAS GAGAL] ID: ${taskItem.id} gagal: ${execErr.message}`);
              setStatusBarState('warning', `Gagal [${taskItem.id}]`, execErr.message);
              try {
                await sendTelegramMessage(cur.botToken, cur.chatId, `Gagal: ${taskItem.id}`, 'KENDALA', [
                  `Instruksi: ${taskItem.instruction.substring(0, 90)}`,
                  `Penyebab: ${execErr.message}`
                ], cur.project, cur.timezone);
              } catch (nErr) {}
              vscode.window.showErrorMessage(`❌ Gagal mengeksekusi tugas [${taskItem.id}]: ${execErr.message}`);
              continue;
            }
          }

          // Beri tahu user bahwa tugas baru masuk antrean
          if (!silent || cur.showToastOnNewTask) {
            const instBrief = taskItem.instruction ? (taskItem.instruction.length > 55 ? taskItem.instruction.substring(0, 55) + '...' : taskItem.instruction) : '';
            const isIdeTask = (taskItem.id && (taskItem.id.includes('-IDE-') || taskItem.id.startsWith('#TASK-IDE'))) ||
                              (taskItem.sender && (taskItem.sender.includes('IDE') || taskItem.sender.includes('VS Code')));

            if (isIdeTask) {
              // Tugas dari IDE (dengan kode khusus -IDE-): JANGAN tampilkan pop-up dialog delegasi
              // Tampilkan pop-up status tugas dikerjakan / selesai
              if (taskItem.status === 'DONE') {
                showTimedNotification(`✅ [${taskItem.id}] Tugas IDE Selesai: "${instBrief}"`, 10);
              } else {
                showTimedNotification(`⚡ [${taskItem.id}] Tugas IDE Sedang Dikerjakan: "${instBrief}"`, 10);
              }
            } else {
              // Tugas dari Telegram: tampilkan notifikasi toast informatif langsung (tanpa dialog tombol yang menuntut klik manual)
              if (taskItem.status === 'DONE') {
                showTimedNotification(i18n('newTaskDoneToast', { id: taskItem.id, brief: instBrief }), 10);
              } else {
                // Salin prompt ke clipboard sebagai kemudahan
                const prompt = buildAgentPrompt(taskItem.id, taskItem.instruction);
                await vscode.env.clipboard.writeText(prompt);

                const currentHandler = (cur.agentHandler || '').trim();
                const agentDisplayName = currentHandler === 'ide' ? 'Agent IDE' : (currentHandler === 'custom' ? 'Custom Agent' : currentHandler.toUpperCase());
                const runBtnLabel = currentHandler ? i18n('btnRunVia', { agent: agentDisplayName }) : i18n('btnSelectAgent');

                // Jika autoDispatchAgent aktif dan handler terisi, periksa aturan sekuensial
                if (cur.autoDispatchAgent && currentHandler) {
                  const qContentNow = fs.existsSync(queueFile) ? fs.readFileSync(queueFile, 'utf-8') : '';
                  const activeSec = qContentNow.split('## ✅ Riwayat Tugas Selesai')[0];
                  const mActive = /###\s+\[IN_PROGRESS\]\s+(#?TASK-[A-Za-z0-9_-]+)/i.exec(activeSec);
                  if (!mActive || mActive[1].toLowerCase() === taskItem.id.toLowerCase()) {
                    executeTaskWithAgent(taskItem.id, taskItem.instruction);
                  } else {
                    log('info', `[SEKUENSIAL] Menunda auto-dispatch ${taskItem.id} karena tugas ${mActive[1]} masih [IN_PROGRESS].`);
                  }
                }

                showTimedNotification(
                  `⚡ [${taskItem.id}] Tugas Baru: "${instBrief}" (Prompt disalin ke clipboard)`,
                  15,
                  runBtnLabel,
                  'Buka TASK_QUEUE'
                ).then(async (act) => {
                  if (act === runBtnLabel) {
                    if (!currentHandler) {
                      vscode.commands.executeCommand('telegramNotifier.selectAgentHandler');
                    } else {
                      executeTaskWithAgent(taskItem.id, taskItem.instruction);
                    }
                  } else if (act === 'Buka TASK_QUEUE') {
                    vscode.commands.executeCommand('telegramNotifier.openQueue');
                  }
                });
              }
            }
          }
        }

        taskProvider.refresh();
        statusBar.text = `$(check) Worker Otomasi Aktif`;
        setTimeout(() => updateStatusBar(), 4000);
      } else {
        consecutivePullFailures = 0;
        if (!silent) {
          vscode.window.showInformationMessage('ℹ️ Tidak ada tugas baru di antrean webhook.');
        }
      }
    } catch (err) {
      consecutivePullFailures++;
      const isTransient = consecutivePullFailures < 2;
      if (isTransient) {
        log('warn', `Koneksi sementara ke server webhook terputus (${err.message}), mencoba kembali di siklus berikutnya...`);
      } else {
        log('error', `Gagal menghubungi server webhook: ${err.message}`);
      }
      // Sesuai instruksi user: jangan munculkan pop-up saat koneksi webhook terputus atau network error,
      // tampilkan warning di status bar dan catat di log
      setStatusBarState('warning', 'Koneksi Server Terputus', `Gagal terhubung ke server webhook: ${err.message}`, true);
    } finally {
      isPulling = false;
      if (consecutivePullFailures === 0 && currentWorkerState !== 'warning') {
        setStatusBarState('idle');
      }
    }
  }

  function startAutoWorker() {
    if (autoPullTimer) {
      clearInterval(autoPullTimer);
      autoPullTimer = null;
    }
    const cur = getEffectiveConfig();
    if (!cur.secretKey) return;

    isWorkerActive = true;
    setTimeout(() => pullTaskQueue(true), 300);
    autoPullTimer = setInterval(() => {
      pullTaskQueue(true);
    }, cur.pullInterval * 1000);
    updateStatusBar();
  }

  function stopAutoWorker() {
    if (autoPullTimer) {
      clearInterval(autoPullTimer);
      autoPullTimer = null;
    }
    isWorkerActive = false;
    updateStatusBar();
  }

  // ------------------------------------------------------------------------------
  // ------------------------------------------------------------------------------
  // Startup Logic: Verifikasi Konfigurasi & Pertanyaan Aktivasi Worker
  // ------------------------------------------------------------------------------
  setTimeout(async () => {
    const cur = getEffectiveConfig();

    if (!cur.secretKey || !cur.project) {
      // Belum terkonfigurasi lengkap: Tawari setup wizard
      vscode.window.showInformationMessage(
        "👋 Selamat datang di Tele-vs-Code! Hubungkan workspace ini ke Server Biru dan pilih proyek untuk mengaktifkan notifikasi dan antrean tugas AI Agent.",
        "Hubungkan ke Server Biru",
        "Nanti Saja"
      ).then(act => {
        if (act === "Hubungkan ke Server Biru") {
          vscode.commands.executeCommand("telegramNotifier.quickSetup");
        }
      });
      return;
    }

    let resolved = await getResolvedProject();
    const effectiveProjectName = resolved.name || cur.project || 'Default';
    const effectiveProjectKey = resolved.projectKey || cur.project || '';

    // Di remote/SSH atau saat network lambat, JANGAN return / blokir worker!
    // Worker tetap harus aktif memantau TASK_QUEUE.md dan antrean server secara berkala.
    if (!resolved.projectKey) {
      log('warn', `Proyek "${cur.project}" belum ter-cache dari server Biru, tetap menjalankan worker otomatis.`);
    }

    if (cur.askOnStartup) {
      vscode.window.showInformationMessage(
        `🤖 Tele-vs-Code: Workspace terhubung ke Proyek "${effectiveProjectName}" (${effectiveProjectKey}). Apakah Anda ingin mengaktifkan worker otomasi sekarang?`,
        "Aktifkan Otomasi",
        "Selalu Aktifkan",
        "Jeda Otomasi"
      ).then(async act => {
        if (act === "Aktifkan Otomasi") {
          startAutoWorker();
          vscode.window.showInformationMessage(`🚀 Worker otomasi aktif untuk proyek "${effectiveProjectName}".`);
        } else if (act === "Selalu Aktifkan") {
          const vsConfig = vscode.workspace.getConfiguration("telegramNotifier");
          await vsConfig.update("autoPull", true, vscode.ConfigurationTarget.Global);
          await vsConfig.update("askOnStartup", false, vscode.ConfigurationTarget.Global);
          startAutoWorker();
          vscode.window.showInformationMessage("🚀 Worker otomasi akan selalu aktif otomatis di setiap sesi.");
        } else if (act === "Jeda Otomasi") {
          stopAutoWorker();
          vscode.window.showInformationMessage("⏸️ Worker otomasi dijeda.");
        }
      });
    } else if (cur.autoPull) {
      startAutoWorker();
    }

    // Popup Panduan Manual: Muncul setiap kali VSIX diaktifkan (Startup)
    setTimeout(() => {
      showTimedNotification(
        "🤖 Tele-vs-Code Siap! Prioritas Pekerja: Antigravity AI Agent. Ketik 'proses antrian' di Chat AI untuk menyelesaikan TASK_QUEUE.md.",
        10,
        "📋 Salin Prompt AI",
        "📖 Buka TASK_QUEUE.md"
      ).then(act => {
        if (act === "📋 Salin Prompt AI") {
          const prompt = buildAgentPrompt('', '');
          vscode.env.clipboard.writeText(prompt);
          showTimedNotification("📋 Prompt 'proses antrian' berhasil disalin ke clipboard! Tempelkan di Chat AI Agent Anda.", 5);
        } else if (act === "📖 Buka TASK_QUEUE.md") {
          vscode.commands.executeCommand("telegramNotifier.openQueue");
        }
      });
    }, 2500);
  }, 1200);

  // 1. Menu Utama via Status Bar
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.showMenu', async () => {
    const cur = getEffectiveConfig();
    const resolved = await getResolvedProject();

    const isConfigValid = !!(cur.secretKey && cur.project && resolved.projectKey);

    let menuItems = [];
    if (!isConfigValid) {
      // Jika API Secret dan Project Key belum valid: tampilkan Folder Workspace, Hubungkan ke Server Biru, dan Whoami
      menuItems = [
        {
          label: `$(folder) Folder Workspace: ${path.basename(cur.folder)}`,
          description: cur.folder,
          detail: 'Working directory tempat AGENTS.md & TASK_QUEUE.md dikelola. Klik untuk ubah folder.',
          cmd: 'telegramNotifier.setFolder'
        },
        {
          label: '$(gear) ' + t('menuSetup'),
          description: t('menuSetupDesc'),
          detail: '⚠️ API Secret atau Project Key belum valid. Klik untuk menghubungkan workspace ke Server Biru.',
          cmd: 'telegramNotifier.quickSetup'
        },
        {
          label: '$(account) ' + t('menuWhoami'),
          description: 'Belum Terhubung ke Server Biru',
          detail: `Folder: ${cur.folder}`,
          cmd: 'telegramNotifier.whoami'
        }
      ];
    } else {
      // Menu Utama Sederhana, Bersih & Informatif (Tanpa kata "Buka Sub-menu")
      menuItems = [
        // 0. Pemicu Manual AI Agent (PALING ATAS DENGAN IKON & DESKRIPSI KUAT)
        {
          label: '$(play-circle) 🚀 Perintahkan AI Agent: Kerjakan TASK_QUEUE.md (Salin Prompt)',
          description: 'Prioritas: Antigravity AI Agent (Ketik "proses antrian")',
          detail: 'Salin prompt "proses antrian" ke clipboard untuk memicu AI Agent aktif segera mengeksekusi antrean.',
          cmd: 'telegramNotifier.copyAiPrompt'
        },
        // 1. Folder Workspace
        {
          label: `$(folder) Folder Workspace: ${path.basename(cur.folder)}`,
          description: cur.folder,
          cmd: 'telegramNotifier.setFolder'
        },
                // 1.5. Pilih AI Agent Handler
        {
          label: '$(robot) ' + t('menuAgentHandler'),
          description: cur.agentHandler ? t('menuAgentActiveDesc', { agent: cur.agentHandler === 'ide' ? 'AGENT IDE' : cur.agentHandler.toUpperCase() }) : t('menuAgentInactiveDesc'),
          cmd: 'telegramNotifier.selectAgentHandler'
        },
        // Ganti Bahasa / Switch Language
        {
          label: '$(globe) ' + t('menuSwitchLang'),
          description: cur.language === 'en' ? 'English (EN)' : 'Bahasa Indonesia (ID)',
          cmd: 'telegramNotifier.switchLanguage'
        },
        // 2. Kerjakan Antrean Tugas Berikutnya
        {
          label: '$(play-circle) ' + t('menuRunNext'),
          description: t('menuRunNextDesc'),
          cmd: 'telegramNotifier.runNextTask'
        },
        // 3. Kelola Antrean Tugas (Tasks & Jobs)
        {
          label: '$(checklist) ' + t('menuTasks'),
          description: t('menuTasksDesc'),
          sub: 'tasks'
        },
        // 4. Jalankan Autonomous CLI Worker di Terminal (Requirement 1: Munculkan kembali di menu utama)
        {
          label: '$(terminal) ' + t('menuWorkerTerminal'),
          description: t('menuWorkerTerminalDesc'),
          cmd: 'telegramNotifier.startCliWorker'
        },
        // 5. Kontrol Worker & Durasi Polling
        {
          label: '$(sync) ' + t('menuWorkerControl'),
          description: isWorkerActive ? t('menuWorkerControlActive', { interval: cur.pullInterval }) : t('menuWorkerControlPaused'),
          sub: 'worker'
        },
        // 6. Pengujian IDE & Telegram (Requirement 4)
        {
          label: '$(beaker) ' + t('menuTest'),
          description: t('menuTestDesc'),
          sub: 'test_menu'
        },
        // 7. Lihat Log Aktivitas (Requirement 3: Buat log)
        {
          label: '$(output) ' + t('menuLog'),
          description: t('menuLogDesc'),
          cmd: 'telegramNotifier.showLog'
        },
        // 8. Buka Web Dashboard
        {
          label: '$(browser) ' + t('menuDashboard'),
          description: 'https://biru.co.id/tele-vs-code/',
          cmd: 'telegramNotifier.openDashboard'
        },
        // 9. Hubungkan ke Server Biru
        {
          label: '$(gear) ' + t('menuSetup'),
          description: t('menuSetupDesc'),
          cmd: 'telegramNotifier.quickSetup'
        },
        // 9b. Unggah File / Media Dokumentasi ke Server Biru
        {
          label: '$(file-media) ' + t('menuUploadMedia'),
          description: t('menuUploadMediaDesc'),
          cmd: 'telegramNotifier.uploadMedia'
        },
        // 10. Whoami
        {
          label: '$(account) ' + t('menuWhoami'),
          description: `${resolved.name || 'Proyek'} (#${resolved.code || resolved.projectKey || '-'})`,
          detail: `Folder: ${path.basename(cur.folder)} | Worker: ${isWorkerActive ? `🟢 Aktif (${cur.pullInterval}s)` : '⚪ Dijeda'}`,
          cmd: 'telegramNotifier.whoami'
        }
      ];
    }

    const placeholderText = isConfigValid
      ? `Tele-vs-Code [${resolved.name} - ${resolved.projectKey}] - Pilih Aksi:`
      : 'Tele-vs-Code (Belum Terhubung ke Server Biru) - Pilih Aksi:';

    const choice = await vscode.window.showQuickPick(menuItems, {
      title: isConfigValid ? `⚡ Menu Tele-vs-Code (${resolved.name})` : '⚠️ Setup Tele-vs-Code',
      placeHolder: placeholderText
    });

    if (!choice) return;

    // Sub-Menu: Tasks & Jobs
    if (choice.sub === 'tasks') {
      const taskSubItems = [
        { label: t('tasksRunNext'), description: t('tasksRunNextDesc'), cmd: 'telegramNotifier.runNextTask' },
        { label: t('tasksAdd'), description: t('tasksAddDesc'), cmd: 'telegramNotifier.addTask' },
        { label: t('tasksOpen'), description: t('tasksOpenDesc'), cmd: 'telegramNotifier.openQueue' },
        { label: '$(terminal) Jalankan Autonomous CLI Worker di Terminal', description: t('menuWorkerTerminalDesc'), cmd: 'telegramNotifier.startCliWorker' },
        { label: t('tasksBack'), description: '', cmd: 'telegramNotifier.showMenu' }
      ];
      const subPick = await vscode.window.showQuickPick(taskSubItems, {
        title: t('tasksMenuTitle', { name: resolved.name }),
        placeHolder: t('tasksMenuPlaceholder')
      });
      if (subPick && subPick.cmd) {
        vscode.commands.executeCommand(subPick.cmd);
      }
      return;
    }

    // Sub-Menu: Kontrol Worker & Durasi Polling
    if (choice.sub === 'worker') {
      const workerSubItems = [
        {
          label: isWorkerActive ? t('workerPause') : t('workerStart'),
          description: isWorkerActive ? `Sedang aktif memantau server setiap ${cur.pullInterval}s` : 'Mulai memantau tugas masuk dari server secara otomatis',
          cmd: 'telegramNotifier.toggleAutoPull'
        },
        {
          label: t('workerPullNow'),
          description: t('workerPullNowDesc'),
          cmd: 'telegramNotifier.pullTasks'
        },
        {
          label: `$(watch) Atur Durasi Refresh Polling Worker (Saat ini: ${cur.pullInterval} detik)`,
          description: 'Ubah frekuensi jeda pemeriksaan antrean tugas baru ke server',
          cmd: 'telegramNotifier.setPullInterval'
        },
        { label: '$(arrow-left) Kembali ke Menu Utama', description: 'Tutup submenu worker', cmd: 'telegramNotifier.showMenu' }
      ];
      const subPick = await vscode.window.showQuickPick(workerSubItems, {
        title: `🔄 Kontrol Worker Otomatis & Polling (${resolved.name})`,
        placeHolder: `Status Worker: ${isWorkerActive ? `🟢 AKTIF (${cur.pullInterval}s)` : '⚪ DIJEDA'}`
      });
      if (subPick && subPick.cmd) {
        vscode.commands.executeCommand(subPick.cmd);
      }
      return;
    }

    // Sub-Menu: Pengujian (IDE & Telegram)
    if (choice.sub === 'test_menu') {
      const testSubItems = [
        { label: t('testSuccess'), description: t('testSuccessDesc'), cmd: 'telegramNotifier.sendSuccess' },
        { label: t('testProgress'), description: t('testProgressDesc'), cmd: 'telegramNotifier.sendProgress' },
        { label: t('testError'), description: t('testErrorDesc'), cmd: 'telegramNotifier.sendError' },
        { label: t('testIde'), description: t('testIdeDesc'), cmd: 'telegramNotifier.testIdeTask' },
        { label: t('testDiag'), description: t('testDiagDesc'), cmd: 'telegramNotifier.runSelfTest' },
        { label: '$(arrow-left) Kembali ke Menu Utama', description: 'Kembali ke daftar menu utama', cmd: 'telegramNotifier.showMenu' }
      ];
      const subPick = await vscode.window.showQuickPick(testSubItems, {
        title: t('testMenuTitle', { name: resolved.name }),
        placeHolder: t('testMenuPlaceholder')
      });
      if (subPick && subPick.cmd) {
        vscode.commands.executeCommand(subPick.cmd);
      }
      return;
    }

    if (choice.cmd) {
      vscode.commands.executeCommand(choice.cmd);
    }
  }));

  // 2. Command /whoami di VS Code: Menampilkan Identitas Workspace & Routing (Paling Bawah di Menu)
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.whoami', async () => {
    const cur = getEffectiveConfig();
    const resolved = await getResolvedProject();
    const operator = `${os.userInfo().username}@${os.hostname()}`;

    const workerStatusText = isWorkerActive
      ? `🟢 MENYALA OTOMATIS (Refresh setiap ${cur.pullInterval} detik)`
      : `⚪ TIDAK MENYALA (Dijeda / Nonaktif)`;

    const items = [
      { label: `📁 Proyek: ${resolved.name || "(Belum Dipilih)"}${resolved.code ? ` (#${resolved.code})` : ""}`, description: `ID Proyek: ${resolved.id ? resolved.id : "(Belum Disetel)"}` },
      { label: `📂 Folder Workspace (Working Directory):`, description: cur.folder },
      { label: `📡 Status Worker Otomatis:`, description: workerStatusText },
      { label: `⏱️ Durasi Refresh Polling:`, description: isWorkerActive ? `${cur.pullInterval} detik (Menyala Aktif)` : `${cur.pullInterval} detik (Tidak Menyala)` },
      { label: `💻 Operator Host:`, description: operator },
      { label: `🌐 Server Webhook:`, description: cur.webhookUrl },
      { label: `🔑 API Key Secret:`, description: cur.secretKey ? (cur.secretKey.substring(0, 8) + '••••••••') : '(Belum diatur)' },
      { label: `🤖 Bot Telegram:`, description: resolved.botToken ? `Chat ID: ${resolved.chatId}` : '(Mengikuti server Biru)' }
    ];

    const pick = await vscode.window.showQuickPick(items, {
      title: '👤 Identifikasi Workspace Tele-vs-Code',
      placeHolder: `Status Worker: ${isWorkerActive ? `🟢 MENYALA (${cur.pullInterval}s)` : '⚪ TIDAK MENYALA'} | Klik item untuk menyalin`
    });

    if (pick && pick.description) {
      if (pick.label.includes('Folder Workspace')) {
        vscode.commands.executeCommand('telegramNotifier.setFolder');
        return;
      }
      if (pick.label.includes('Status Worker') || pick.label.includes('Durasi Refresh')) {
        vscode.commands.executeCommand('telegramNotifier.setPullInterval');
        return;
      }
      await vscode.env.clipboard.writeText(pick.description);
      vscode.window.showInformationMessage(`📋 Disalin: ${pick.description}`);
    }
  }));

  // 2b. Atur Durasi Refresh Polling Worker
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.setPullInterval', async () => {
    const cur = getEffectiveConfig();
    const input = await vscode.window.showInputBox({
      title: '⏱️ Atur Durasi Refresh Worker (Polling Interval)',
      prompt: 'Masukkan jeda waktu pemeriksaan tugas baru ke server (1 - 60 detik):',
      value: String(cur.pullInterval),
      validateInput: val => {
        const num = parseInt(val, 10);
        if (isNaN(num) || num < 1 || num > 60) {
          return 'Masukkan angka bulat antara 1 sampai 60 detik';
        }
        return null;
      }
    });

    if (!input) return;
    const newInterval = parseInt(input, 10);
    const vsConfig = vscode.workspace.getConfiguration('telegramNotifier');
    await vsConfig.update('pullInterval', newInterval, vscode.ConfigurationTarget.Global);

    if (isWorkerActive) {
      startAutoWorker();
    } else {
      updateStatusBar();
    }

    vscode.window.showInformationMessage(`⏱️ Durasi refresh polling worker berhasil diubah menjadi ${newInterval} detik.`);
  }));

  // 2b. Atur Working Directory / Folder Workspace (AGENTS.md, CLAUDE.md, TASK_QUEUE.md)
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.setFolder', async () => {
    const cur = getEffectiveConfig();
    const wsFolders = vscode.workspace.workspaceFolders || [];

    const choices = [];
    if (wsFolders.length > 0) {
      for (const wf of wsFolders) {
        choices.push({
          label: `$(root-folder) Workspace: ${wf.name}`,
          description: wf.uri.fsPath,
          detail: wf.uri.fsPath === cur.folder ? '⭐ (Working Directory Saat Ini)' : 'Pilih folder workspace ini sebagai working directory',
          folderPath: wf.uri.fsPath
        });
      }
    }

    choices.push({
      label: '$(folder) Pilih Folder Lain dari Komputer... (Browse)',
      description: 'Pilih folder kerja manapun di disk',
      detail: 'Membuka dialog pemilih direktori',
      folderPath: '__browse__'
    });

    if (cur.folder !== cur.wsFolder) {
      choices.push({
        label: '$(sync) Reset ke Default Workspace Utama',
        description: cur.wsFolder,
        detail: 'Kembalikan working directory ke root workspace utama VS Code',
        folderPath: cur.wsFolder
      });
    }

    const pick = await vscode.window.showQuickPick(choices, {
      title: '📂 Atur Folder Workspace / Working Directory (Tele-vs-Code)',
      placeHolder: `Working directory aktif saat ini: ${cur.folder}`
    });

    if (!pick) return;

    let targetPath = '';
    if (pick.folderPath === '__browse__') {
      const selected = await vscode.window.showOpenDialog({
        canSelectFiles: false,
        canSelectFolders: true,
        canSelectMany: false,
        openLabel: 'Pilih Sebagai Working Directory',
        title: 'Pilih Folder Tempat AGENTS.md, CLAUDE.md, & TASK_QUEUE.md Dikelola',
        defaultUri: vscode.Uri.file(cur.folder)
      });
      if (selected && selected[0]) {
        targetPath = selected[0].fsPath;
      }
    } else {
      targetPath = pick.folderPath;
    }

    if (targetPath) {
      const vsConfig = vscode.workspace.getConfiguration('telegramNotifier');
      await vsConfig.update('folder', targetPath, vscode.ConfigurationTarget.Workspace);

      ensureWorkspaceTools(context, targetPath);
      taskProvider.refresh();
      await updateStatusBar();

      vscode.window.showInformationMessage(
        `📂 Working Directory disetel ke: ${targetPath}\nBerkas AGENTS.md, CLAUDE.md, dan TASK_QUEUE.md siap digunakan oleh AI Agent.`,
        'Buka TASK_QUEUE.md'
      ).then(act => {
        if (act === 'Buka TASK_QUEUE.md') {
          vscode.commands.executeCommand('telegramNotifier.openQueue');
        }
      });
    }
  }));

  // 2c. Kerjakan Antrean Tugas Berikutnya via AI Chat
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.runNextTask', async () => {
    const cur = getEffectiveConfig();
    const queueFile = path.join(cur.folder, 'TASK_QUEUE.md');
    if (!fs.existsSync(queueFile)) {
      ensureWorkspaceTools(context, cur.folder);
      vscode.window.showInformationMessage('ℹ️ File TASK_QUEUE.md belum memiliki antrean tugas aktif.');
      return;
    }

    try {
      const content = fs.readFileSync(queueFile, 'utf-8');
      const activeSection = content.split('## ✅ Riwayat Tugas Selesai')[0];
      // Prioritaskan tugas yang sedang IN_PROGRESS terlebih dahulu
      let match = /###\s+\[IN_PROGRESS\]\s+(#?TASK-[A-Za-z0-9_-]+)[\r\n]+([\s\S]*?)(?=(?:###\s+\[|##|$))/i.exec(activeSection);
      if (!match) {
        // Jika tidak ada yang IN_PROGRESS, ambil tugas PENDING pertama
        match = /###\s+\[(PENDING|WAITING|QUEUE)\]\s+(#?TASK-[A-Za-z0-9_-]+)[\r\n]+([\s\S]*?)(?=(?:###\s+\[|##|$))/i.exec(activeSection);
      }
      if (!match) {
        vscode.window.showInformationMessage('ℹ️ Tidak ada tugas aktif [PENDING] atau [IN_PROGRESS] di antrean TASK_QUEUE.md.');
        return;
      }

      const taskId = match[2].startsWith('#') ? match[2] : `#${match[2]}`;
      const block = match[3];
      const mInst = block.match(/-\s+\*\*Instruksi:\*\*\s*([^\r\n]+)/i);
      const instruction = mInst ? mInst[1].trim() : '';

      const prompt = buildAgentPrompt(taskId, instruction);
      await vscode.env.clipboard.writeText(prompt);

      // Prompt disalin ke clipboard untuk digunakan oleh AI Agent aktif

      vscode.window.showInformationMessage(
        `🚀 Tugas [${taskId}] disalin ke clipboard! Tempelkan (Ctrl+V) di chat AI atau jalankan via AGY CLI.`,
        'Jalankan via AGY',
        'Buka TASK_QUEUE.md'
      ).then(act => {
        if (act === 'Jalankan via AGY') {
          vscode.commands.executeCommand('telegramNotifier.runAgentAgy', taskId, instruction);
        } else if (act === 'Buka TASK_QUEUE.md') {
          vscode.commands.executeCommand('telegramNotifier.openQueue');
        }
      });
    } catch (e) {
      vscode.window.showErrorMessage(`❌ Gagal membaca antrean tugas: ${e.message}`);
    }
  }));

  // 2d. Jalankan AI Agent Otomatis (Antigravity agy CLI)
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.runAgentAgy', async (taskId, instruction) => {
    const cur = getEffectiveConfig();
    const agyBin = findAgyCli();
    const targetFolder = cur.folder || process.cwd();
    const prompt = buildAgentPrompt(taskId, instruction);

    let term = vscode.window.terminals.find(t => t.name === 'Tele-vs-Code Agent');
    if (!term) {
      term = vscode.window.createTerminal({ name: 'Tele-vs-Code Agent', cwd: targetFolder });
    }
    term.show();

    if (agyBin) {
      if (process.platform === 'win32') {
        term.sendText(`& "${agyBin}" --dangerously-skip-permissions -p "${prompt.replace(/"/g, '`"')}"`);
      } else {
        term.sendText(`"${agyBin}" --dangerously-skip-permissions -p "${prompt.replace(/"/g, '\\"')}"`);
      }
      vscode.window.showInformationMessage(`🤖 Antigravity CLI (agy) sedang mengeksekusi tugas ${taskId || ''}...`);
    } else {
      term.sendText(`node .vscode/tele-vs-code/tele-worker.js --once`);
      vscode.window.showInformationMessage(`⚡ Menjalankan Tele-vs-Code Worker di terminal...`);
    }
  }));

  
  // Command: Pilih AI Agent Handler (Dropdown & Deteksi Otomatis / Isi Manual)
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.selectAgentHandler', async () => {
    const cur = getEffectiveConfig();
    const detectedAgents = detectAvailableAgents();

    const hasAnyCliInstalled = detectedAgents.some(a => a.installed && !a.isIde && a.cliPath);
    const quickPickItems = [
      {
        id: '',
        label: t('agentNoneLabel'),
        description: t('agentNoneDesc'),
        detail: t('agentNoneDetail')
      },
      {
        id: 'auto',
        label: t('agentAutoLabel'),
        description: t('agentAutoDesc'),
        detail: t('agentAutoDetail')
      },
      {
        id: 'ide',
        label: hasAnyCliInstalled ? t('agentIdeLabel') : `$(star-full) ${t('agentIdeLabel')} (Rekomendasi)`,
        description: hasAnyCliInstalled ? t('agentIdeDesc') : '✅ Solusi Terbaik: Tanpa perlu instalasi CLI eksternal',
        detail: hasAnyCliInstalled ? t('agentIdeDetail') : 'Gunakan antarmuka Chat AI yang sudah aktif di IDE Anda (Antigravity / Copilot / Gemini)'
      }
    ];

    // Tambahkan daftar agen terpasang
    const installed = detectedAgents.filter(a => a.installed && a.id !== 'ide');
    const notInstalled = detectedAgents.filter(a => !a.installed);

    if (installed.length > 0) {
      quickPickItems.push({ kind: vscode.QuickPickItemKind.Separator, label: t('agentInstalledHeader') });
      for (const a of installed) {
        quickPickItems.push({
          id: a.id,
          label: `$(check) ${a.name}`,
          description: '✅ Terpasang & Siap',
          detail: a.detail
        });
      }
    }

    if (notInstalled.length > 0) {
      quickPickItems.push({ kind: vscode.QuickPickItemKind.Separator, label: t('agentOtherHeader') });
      for (const a of notInstalled) {
        quickPickItems.push({
          id: a.id,
          label: `$(terminal) ${a.name}`,
          description: 'Belum Terdeteksi di PATH',
          detail: a.detail
        });
      }
    }

    quickPickItems.push({ kind: vscode.QuickPickItemKind.Separator, label: t('agentCustomHeader') });
    quickPickItems.push({
      id: 'custom',
      label: t('agentManualLabel'),
      description: cur.customAgentCommand ? `${cur.language === 'en' ? 'Current' : 'Saat ini'}: "${cur.customAgentCommand}"` : t('agentManualDesc'),
      detail: t('agentManualDetail')
    });

    const chosen = await vscode.window.showQuickPick(quickPickItems, {
      title: t('selectAgentTitle'),
      placeHolder: `Agent Handler Aktif Saat Ini: ${cur.agentHandler ? (cur.agentHandler === 'ide' ? 'AGENT IDE' : cur.agentHandler.toUpperCase()) : 'Nonaktif / Kosong'}`
    });

    if (chosen === undefined) return;

    let targetHandler = chosen.id;
    let targetCustomCmd = cur.customAgentCommand;

    if (targetHandler === 'custom') {
      const inputCmd = await vscode.window.showInputBox({
        title: t('agentManualTitle'),
        prompt: t('agentManualPrompt'),
        value: cur.customAgentCommand || 'agy --dangerously-skip-permissions -p "{prompt}"',
        ignoreFocusOut: true
      });
      if (inputCmd === undefined) return;
      targetCustomCmd = inputCmd.trim();
      if (!targetCustomCmd) {
        vscode.window.showWarningMessage('⚠️ Perintah kustom kosong. Eksekusi dinonaktifkan.');
        targetHandler = '';
      }
    }

    // Simpan ke konfigurasi VS Code
    const vsConfig = vscode.workspace.getConfiguration('telegramNotifier');
    await vsConfig.update('agentHandler', targetHandler, vscode.ConfigurationTarget.Global);
    if (targetCustomCmd) {
      await vsConfig.update('customAgentCommand', targetCustomCmd, vscode.ConfigurationTarget.Global);
    }

    // Simpan juga ke .vscode/tele-vs-code/config.env agar tele-worker mandiri juga membacanya
    if (cur.vscodeConf && fs.existsSync(path.dirname(cur.vscodeConf))) {
      try {
        let envText = fs.existsSync(cur.vscodeConf) ? fs.readFileSync(cur.vscodeConf, 'utf-8') : '';
        if (/^TELE_AGENT_HANDLER=/m.test(envText)) {
          envText = envText.replace(/^TELE_AGENT_HANDLER=.*$/m, `TELE_AGENT_HANDLER=${targetHandler}`);
        } else {
          envText += `\nTELE_AGENT_HANDLER=${targetHandler}\n`;
        }
        if (targetCustomCmd) {
          if (/^TELE_CUSTOM_AGENT_COMMAND=/m.test(envText)) {
            envText = envText.replace(/^TELE_CUSTOM_AGENT_COMMAND=.*$/m, `TELE_CUSTOM_AGENT_COMMAND=${targetCustomCmd}`);
          } else {
            envText += `TELE_CUSTOM_AGENT_COMMAND=${targetCustomCmd}\n`;
          }
        }
        fs.writeFileSync(cur.vscodeConf, envText, 'utf-8');
      } catch (e) {}
    }

    updateStatusBarAgent();
    updateStatusBar();

    if (!targetHandler) {
      vscode.window.showInformationMessage(t('agentEmptyToast'));
    } else {
      vscode.window.showInformationMessage(t('agentSetToast', { agent: chosen.label.replace(/\$\([^)]+\)\s*/, '') }));
    }
  }));

  // Command: Jalankan Tugas Aktif via Agent Handler Terpilih
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.executeActiveTask', async () => {
    const cur = getEffectiveConfig();
    const qPath = path.join(cur.folder, 'TASK_QUEUE.md');
    if (!fs.existsSync(qPath)) {
      vscode.window.showWarningMessage('⚠️ File TASK_QUEUE.md belum ditemukan di workspace.');
      return;
    }
    const qContent = fs.readFileSync(qPath, 'utf-8');
    const taskMatch = qContent.match(/###\s*\[(PENDING|IN_PROGRESS)\]\s*(#TASK-[A-Za-z0-9_-]+)/i);
    const taskId = taskMatch ? taskMatch[2] : '';
    const instMatch = qContent.match(/-\s*\*\*Instruksi:\*\*\s*(.*)/i);
    const inst = instMatch ? instMatch[1].trim() : '';

    if (!taskId) {
      vscode.window.showInformationMessage('ℹ️ Tidak ada tugas berstatus [PENDING] atau [IN_PROGRESS] di antrean.');
      return;
    }

    if (!cur.agentHandler) {
      const act = await vscode.window.showInformationMessage(
        `💡 Agent Handler belum disetel. Jalankan tugas ${taskId} menggunakan Agent IDE (Chat IDE)?`,
        'Ya, Jalankan via Agent IDE', 'Pilih Agent Lain', 'Tutup'
      );
      if (act === 'Ya, Jalankan via Agent IDE') {
        const vsConfig = vscode.workspace.getConfiguration('telegramNotifier');
        await vsConfig.update('agentHandler', 'ide', vscode.ConfigurationTarget.Global);
        updateStatusBarAgent();
        updateStatusBar();
        await executeTaskWithAgent(taskId, inst, 'ide');
        return;
      } else if (act === 'Pilih Agent Lain') {
        vscode.commands.executeCommand('telegramNotifier.selectAgentHandler');
        return;
      }
      return;
    }

    await executeTaskWithAgent(taskId, inst);
  }));

  // 3. Setup Wizard: Input Secret & Project Key -> Fetch Biru Server -> Test -> Konfirmasi Otomasi
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.quickSetup', async () => {
    const cur = getEffectiveConfig();

    // Pastikan folder .vscode/tele-vs-code ada
    ensureWorkspaceTools(context, cur.folder);

    // Langkah 1: Masukkan AGENTS_TELE_SECRET
    const secretInput = await vscode.window.showInputBox({
      title: 'Langkah 1/2: Masukkan API Secret Key (AGENTS_TELE_SECRET)',
      prompt: 'Masukkan API Secret Key akun Anda dari https://biru.co.id/tele-vs-code/ (Format: tvsc_...)',
      value: cur.secretKey,
      ignoreFocusOut: true
    });
    if (secretInput === undefined) return;
    const cleanSecret = secretInput.trim();
    if (!cleanSecret) {
      vscode.window.showErrorMessage('❌ AGENTS_TELE_SECRET tidak boleh kosong. Proses dihentikan.');
      return;
    }

    // Hubungi Server Biru untuk validasi Secret Key dan ambil daftar proyek
    let serverProjects = [];
    try {
      const url = `${cur.webhookUrl.replace(/\/?$/, '/')}?action=projects&secret=${cleanSecret}`;
      const res = await safeFetch(url, { timeout: 15000 }, 2);
      if (!res.ok) {
        vscode.window.showErrorMessage(`❌ AGENTS_TELE_SECRET tidak valid atau server menolak permintaan (HTTP ${res.status}). Proses dihentikan.`);
        return; // STOP! Tidak bisa dilanjutkan
      }
      const data = await res.json();
      if (!data.ok || !Array.isArray(data.projects) || data.projects.length === 0) {
        vscode.window.showErrorMessage('❌ AGENTS_TELE_SECRET tidak valid atau akun Anda belum memiliki proyek di https://biru.co.id/tele-vs-code/. Proses dihentikan.');
        return; // STOP! Tidak bisa dilanjutkan
      }
      serverProjects = data.projects;
    } catch (netErr) {
      vscode.window.showErrorMessage(`❌ Gagal menghubungi server Biru: ${netErr.message}. Pastikan koneksi internet aktif.`);
      return; // STOP!
    }

    // Langkah 2: Masukkan atau Pilih Project Key (prj_********)
    let selectedProjectKey = '';
    const projectChoices = serverProjects.map(p => ({
      label: `$(key) ${p.project_key}`,
      description: `${p.name} (Tag: #${p.code || 'a'})`,
      detail: `Chat ID: ${p.chat_id || '(Default)'} | Bot: ${p.bot_token ? p.bot_token.substring(0, 10) + '...' : '(Default)'}`,
      projectKey: p.project_key
    }));
    projectChoices.push({
      label: '$(edit) Masukkan Project Key Manual (prj_...)',
      description: 'Ketik manual Project Key proyek Anda',
      detail: 'Format harus berupa prj_xxxxxxxxxx',
      projectKey: '__manual__'
    });

    const chosen = await vscode.window.showQuickPick(projectChoices, {
      title: 'Langkah 2/2: Pilih atau Masukkan Project Key (prj_********)',
      placeHolder: 'Pilih Project Key proyek yang sesuai untuk workspace ini'
    });
    if (!chosen) return;

    if (chosen.projectKey === '__manual__') {
      const manualKey = await vscode.window.showInputBox({
        title: 'Project Key (prj_********)',
        prompt: 'Masukkan Project Key unik dari dashboard https://biru.co.id/tele-vs-code/ (Format: prj_xxxxxxxxxx)',
        value: cur.project && cur.project.startsWith('prj_') ? cur.project : '',
        placeHolder: 'Contoh: prj_1d373cdfb9',
        ignoreFocusOut: true
      });
      if (manualKey === undefined) return;
      selectedProjectKey = manualKey.trim();
    } else {
      selectedProjectKey = chosen.projectKey;
    }

    if (!selectedProjectKey) {
      vscode.window.showErrorMessage('❌ Project Key tidak boleh kosong. Proses dihentikan.');
      return;
    }

    // ==============================================================================
    // VALIDASI KETAT: Jika AGENTS_TELE_SECRET dan Project Key TIDAK COCOK -> STOP!
    // ==============================================================================
    const cleanProjectKey = selectedProjectKey.trim();
    const matchedProject = serverProjects.find(p => p.project_key && p.project_key.toLowerCase() === cleanProjectKey.toLowerCase());

    if (!matchedProject) {
      const availableList = serverProjects.map(p => `• ${p.project_key} (${p.name})`).join('\n');
      vscode.window.showErrorMessage(
        `❌ AGENTS_TELE_SECRET dan Project Key TIDAK COCOK! Proses dihentikan.`,
        {
          modal: true,
          detail: `Project Key "${cleanProjectKey}" tidak terdaftar pada akun API Secret ini.\n\nDaftar Project Key yang valid untuk akun Anda:\n${availableList}\n\nPastikan Anda menyalin Project Key yang benar dari menu Pengaturan & Proyek di dashboard https://biru.co.id/tele-vs-code/.`
        }
      );
      return; // STOP! TIDAK BISA DILANJUTKAN!
    }

    // ==============================================================================
    // JIKA COCOK -> SIMPAN KONFIGURASI KE .vscode/tele-vs-code/config.env
    // ==============================================================================
    const targetConfig = cur.vscodeConf;
    const targetDir = path.dirname(targetConfig);
    if (!fs.existsSync(targetDir)) {
      fs.mkdirSync(targetDir, { recursive: true });
    }

    const envContent = `# ==============================================================================
# Konfigurasi Tele-vs-Code (Otomatis dari Server Biru)
# Folder: ${cur.folder}
# ==============================================================================
AGENTS_TELE_SECRET="${cleanSecret}"
AGENTS_TELE_PROJECT="${matchedProject.project_key}"
AGENTS_TELE_PROJECT_KEY="${matchedProject.project_key}"
AGENTS_TELE_WEBHOOK_URL="${cur.webhookUrl}"
TELEGRAM_BOT_TOKEN="${matchedProject.bot_token || ''}"
TELEGRAM_CHAT_ID="${matchedProject.chat_id || ''}"
PROJECT_NAME="${matchedProject.name || ''}"
TIMEZONE="${matchedProject.timezone || 'Asia/Jakarta'}"
`;

    fs.writeFileSync(targetConfig, envContent, 'utf-8');

    // Simpan juga ke setting VS Code Workspace
    const vsConfig = vscode.workspace.getConfiguration('telegramNotifier');
    await vsConfig.update('secretKey', cleanSecret, vscode.ConfigurationTarget.Global);
    await vsConfig.update('project', matchedProject.project_key, vscode.ConfigurationTarget.Workspace);

    serverProjectCache = null;
    taskProvider.refresh();
    await updateStatusBar();

    // Jalankan Tes Notifikasi ke Telegram
    vscode.window.showInformationMessage(`📤 Menguji koneksi Telegram untuk proyek "${matchedProject.name}" (${matchedProject.project_key})...`);
    try {
      await sendTelegramMessage(
        matchedProject.bot_token,
        matchedProject.chat_id,
        'Verifikasi Koneksi Workspace',
        'INFO',
        [
          `AGENTS_TELE_SECRET & Project Key BERHASIL TERVERIFIKASI!`,
          `Folder: ${cur.folder}`,
          `Proyek: ${matchedProject.name}`,
          `Project Key: ${matchedProject.project_key}`
        ],
        matchedProject.name,
        matchedProject.timezone
      );

      // ==============================================================================
      // JIKA COCOK & PASS TEST -> TAMPILKAN DIALOG OTOMASI
      // ==============================================================================
      const confirmAction = await vscode.window.showInformationMessage(
        `✅ Project Key "${matchedProject.project_key}" (${matchedProject.name}) COCOK & TERVERIFIKASI! Apakah Anda ingin mengaktifkan worker otomasi sekarang?`,
        'Aktifkan Otomasi',
        'Nanti Saja'
      );

      if (confirmAction === 'Aktifkan Otomasi') {
        startAutoWorker();
        vscode.window.showInformationMessage(`🚀 Worker otomasi aktif untuk proyek "${matchedProject.name}" (${matchedProject.project_key}). Antrean tugas dipantau setiap ${cur.pullInterval} detik.`);
      }
    } catch (testErr) {
      vscode.window.showErrorMessage(`❌ Gagal mengirim tes ke Telegram: ${testErr.message}`, 'Coba Lagi').then(a => {
        if (a === 'Coba Lagi') vscode.commands.executeCommand('telegramNotifier.quickSetup');
      });
    }
  }));

  // 4. Toggle Auto-Pull Worker
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.toggleAutoPull', async () => {
    if (isWorkerActive) {
      stopAutoWorker();
      vscode.window.showInformationMessage('⏸️ Worker Otomasi DIJEDA.');
    } else {
      startAutoWorker();
      const cur = getEffectiveConfig();
      vscode.window.showInformationMessage(`✅ Worker Otomasi AKTIF (Memantau tugas setiap ${cur.pullInterval} detik).`);
    }
  }));

  // 5. Jalankan CLI Worker di Terminal
  
  // Command: Tampilkan Output Channel Log
    // Command: Tampilkan Detail Lengkap Tugas saat Item di Activity Bar Diklik (Requirement 2)
  // Command: Salin Teks Detail Tugas ke Clipboard dari Item Akordion (Activity Bar)
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.copyTaskDetailText', async (text, label) => {
    if (!text) return;
    await vscode.env.clipboard.writeText(text);
    showTimedNotification(`📋 ${label || 'Detail'} disalin ke clipboard!`, 5);
  }));

  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.showTaskDetail', async (task) => {
    if (!task) return;
    const detailItems = [
      {
        label: `$(tag) ID Tugas: ${task.id}`,
        description: `[${task.status}]`,
        detail: `Waktu: ${task.waktu || '-'} | Pengirim: ${task.pengirim || '-'}`
      },
      {
        label: '$(symbol-keyword) Perintah / Instruksi:',
        description: task.instruksi || '(Tidak ada instruksi)',
        detail: 'Klik untuk salin perintah ini ke clipboard',
        copyVal: task.instruksi
      }
    ];

    if (task.command) {
      detailItems.push({
        label: '$(terminal) Command Terminal:',
        description: task.command,
        detail: 'Klik untuk salin perintah command terminal ini ke clipboard',
        copyVal: task.command
      });
    }

    if (task.result) {
      detailItems.push({
        label: '$(pass) Hasil / Result Pekerjaan:',
        description: task.result,
        detail: 'Klik untuk salin hasil/laporan pekerjaan ini ke clipboard',
        copyVal: task.result
      });
    } else if (task.status === 'IN_PROGRESS') {
      detailItems.push({
        label: '$(sync~spin) Hasil / Result:',
        description: 'Sedang dikerjakan oleh AI Agent / Worker...',
        detail: 'Hasil akan muncul setelah tugas selesai dieksekusi'
      });
    }

    detailItems.push(
      {
        label: '$(copy) Salin Seluruh Ringkasan (Perintah & Result)',
        description: `${task.id} - ${task.status}`,
        detail: 'Salin semua rincian tugas ke clipboard',
        action: 'copy_all'
      },
      {
        label: '$(file-text) Buka Berkas TASK_QUEUE.md',
        description: 'Buka file TASK_QUEUE.md di editor untuk melihat langsung',
        action: 'open_file'
      }
    );

    if (task.status !== 'DONE') {
      detailItems.push({
        label: '$(play-circle) Kerjakan dengan AI Agent (Kirim ke Chat)',
        description: 'Salin prompt instruksi dan buka AI Chat',
        action: 'run_ai'
      });
    }

    const pick = await vscode.window.showQuickPick(detailItems, {
      title: `📋 Detail Tugas: ${task.id} [${task.status}]`,
      placeHolder: `Perintah: "${task.instruksi ? task.instruksi.substring(0, 60) : ''}..."`
    });

    if (!pick) return;

    if (pick.copyVal) {
      await vscode.env.clipboard.writeText(pick.copyVal);
      showTimedNotification(`📋 Berhasil disalin: "${pick.copyVal.substring(0, 45)}..."`, 5);
    } else if (pick.action === 'copy_all') {
      const allText = `Tugas: ${task.id}\nStatus: ${task.status}\nWaktu: ${task.waktu}\nPengirim: ${task.pengirim}\nPerintah: ${task.instruksi}\n${task.command ? `Command: ${task.command}\n` : ''}Result: ${task.result || '-'}`;
      await vscode.env.clipboard.writeText(allText);
      showTimedNotification(`📋 Seluruh ringkasan tugas [${task.id}] telah disalin ke clipboard!`, 5);
    } else if (pick.action === 'open_file') {
      vscode.commands.executeCommand('telegramNotifier.openQueue');
    } else if (pick.action === 'run_ai') {
      const prompt = buildAgentPrompt(task.id, task.instruksi);
      await vscode.env.clipboard.writeText(prompt);
      showTimedNotification(`📋 Prompt tugas [${task.id}] disalin ke clipboard! Tempelkan di Chat AI Agent Anda.`, 5);
    }
  }));

    // Command: Ganti Bahasa / Switch Language (ID / EN)
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.switchLanguage', async () => {
    const vsConfig = vscode.workspace.getConfiguration('telegramNotifier');
    const currentLang = vsConfig.get('language') || 'id';

    const items = [
      {
        label: '$(globe) 🇮🇩 Bahasa Indonesia',
        description: currentLang === 'id' ? '(Aktif Saat Ini)' : '',
        code: 'id'
      },
      {
        label: '$(globe) 🇬🇧 English',
        description: currentLang === 'en' ? '(Currently Active)' : '',
        code: 'en'
      }
    ];

    const pick = await vscode.window.showQuickPick(items, {
      title: '🌐 Switch Language / Ganti Bahasa (ID / EN)',
      placeHolder: 'Pilih bahasa tampilan untuk ekstensi Tele-vs-Code:'
    });

    if (pick) {
      await vsConfig.update('language', pick.code, vscode.ConfigurationTarget.Global);
      if (pick.code === 'en') {
        showTimedNotification('🌐 Language changed to English! Extension interface updated.', 5);
      } else {
        showTimedNotification('🌐 Bahasa antarmuka diubah ke Bahasa Indonesia!', 5);
      }
      taskProvider.refresh();
      await updateStatusBar();
    }
  }));

  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.showLog', () => {
    outputChannel.show();
    log('info', 'Panel log aktivitas dibuka oleh pengguna.');
  }));

  // Command: Test Buat Tugas dari IDE
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.testIdeTask', async () => {
    const cur = getEffectiveConfig();
    const resolved = await getResolvedProject();
    const defaultInst = `Uji coba tugas dari IDE pada ${new Date().toLocaleTimeString()} - Verifikasi Status Bar & Log`;
    const inst = await vscode.window.showInputBox({
      title: '🧪 Test Tambah Tugas Baru dari IDE',
      prompt: 'Masukkan instruksi pengujian tugas IDE:',
      value: defaultInst,
      ignoreFocusOut: true
    });
    if (!inst) return;

    setStatusBarState('proses', 'Mengirim Tugas IDE...', 'Mencatat tugas uji coba dari IDE ke server');
    log('info', `[TEST IDE] Mengirim tugas uji coba dari IDE: "${inst}"`);

    try {
      const url = `${cur.webhookUrl.replace(/\/?$/, '/')}?action=push&secret=${cur.secretKey}`;
      const params = new URLSearchParams({
        instruction: inst,
        sender: 'IDE Test Runner',
        status: 'IN_PROGRESS',
        notes: 'Dibuat via Fitur Pengujian VSIX',
        is_ide: '1'
      });
      if (cur.project) params.append('project', cur.project);

      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      });
      const data = await res.json();
      if (data.ok) {
        log('info', `[TEST IDE OK] Tugas berhasil dicatat dengan ID: ${data.task_id || data.id}`);
        vscode.window.showInformationMessage(`✅ [Test IDE Sukses] Tugas [${data.task_id || data.id}] berhasil dicatat ke antrean server & TASK_QUEUE.md!`, 'Lihat Log').then(a => {
          if (a === 'Lihat Log') outputChannel.show();
        });
        pullTaskQueue(true);
      } else {
        throw new Error(data.error || 'Server menolak request');
      }
    } catch (err) {
      log('error', `[TEST IDE ERROR] Gagal mengirim tugas test: ${err.message}`);
      setStatusBarState('warning', 'Gagal Test IDE', err.message);
      vscode.window.showErrorMessage(`❌ [Test IDE Gagal] ${err.message}`);
    }
  }));

  // Command: Self-Test Diagnostik Lengkap
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.runSelfTest', async () => {
    const cur = getEffectiveConfig();
    const resolved = await getResolvedProject();

    outputChannel.show();
    log('info', '=======================================================');
    log('info', '🔍 MEMULAI SELF-TEST DIAGNOSTIK TELE-VS-CODE');
    log('info', '=======================================================');

    setStatusBarState('proses', 'Menjalankan Diagnostik...', 'Memeriksa semua komponen');

    let errors = [];

    // 1. Cek Folder Workspace & Izin Tulis
    log('info', `[1/4] Memeriksa folder workspace: ${cur.folder}`);
    try {
      const testFile = path.join(cur.folder, '.tele_test_tmp');
      fs.writeFileSync(testFile, 'test', 'utf-8');
      fs.unlinkSync(testFile);
      log('info', '  ✅ Izin tulis workspace: NORMAL');
    } catch (e) {
      errors.push(`Workspace tidak dapat ditulis: ${e.message}`);
      log('error', `  ❌ Izin tulis workspace GAGAL: ${e.message}`);
    }

    // 2. Cek Koneksi ke Server Webhook
    log('info', `[2/4] Memeriksa koneksi server webhook: ${cur.webhookUrl}`);
    try {
      const testUrl = `${cur.webhookUrl.replace(/\/?$/, '/')}?action=pull&secret=${cur.secretKey}&limit=1`;
      const res = await fetch(testUrl);
      const data = await res.json();
      if (data.ok) {
        log('info', `  ✅ Koneksi Server Webhook: NORMAL (HTTP ${res.status}, Proyek: ${data.project_name || resolved.name})`);
      } else {
        errors.push(`Server webhook error: ${data.error || 'Respon tidak OK'}`);
        log('error', `  ❌ Respon server: ${data.error || 'Gagal'}`);
      }
    } catch (e) {
      errors.push(`Gagal terhubung ke server webhook: ${e.message}`);
      log('error', `  ❌ Koneksi server webhook GAGAL: ${e.message}`);
    }

    // 3. Cek Kredensial Bot Telegram
    log('info', `[3/4] Memeriksa kredensial Bot Telegram...`);
    if (cur.botToken && cur.chatId) {
      try {
        const teleRes = await fetch(`https://api.telegram.org/bot${cur.botToken}/getMe`);
        const teleData = await teleRes.json();
        if (teleData.ok) {
          log('info', `  ✅ Bot Telegram: AKTIF (@${teleData.result.username}, ID: ${teleData.result.id})`);
        } else {
          errors.push(`Bot Token tidak valid: ${teleData.description}`);
          log('error', `  ❌ Bot Telegram GAGAL: ${teleData.description}`);
        }
      } catch (e) {
        errors.push(`Gagal kontak Telegram API: ${e.message}`);
        log('error', `  ❌ Telegram API GAGAL: ${e.message}`);
      }
    } else {
      log('info', `  ℹ️ Bot Token / Chat ID mengikuti konfigurasi server Biru`);
    }

    // 4. Cek Status Worker Otomatis
    log('info', `[4/4] Status Worker Otomasi:`);
    log('info', `  - Status Worker: ${isWorkerActive ? 'AKTIF (Menyala)' : 'DIJEDA / NONAKTIF'}`);
    log('info', `  - Interval Refresh: ${cur.pullInterval} detik`);
    log('info', `  - Eksekusi Otomatis (autoExecuteSimpleTasks): ${cur.autoExecuteSimpleTasks ? 'AKTIF' : 'NONAKTIF'}`);

    log('info', '=======================================================');
    if (errors.length === 0) {
      log('info', '🎉 HASIL DIAGNOSTIK: SEMUA KOMPONEN BERFUNGSI NORMAL 100%!');
      setStatusBarState('idle');
      vscode.window.showInformationMessage('🎉 [Diagnostik Sukses] Semua komponen Tele-vs-Code berfungsi normal 100%!', 'Tutup');
    } else {
      log('error', `⚠️ HASIL DIAGNOSTIK: Ditemukan ${errors.length} kendala.`);
      setStatusBarState('warning', 'Diagnostik Ada Kendala', errors.join('; '));
    }
    log('info', '=======================================================');
  }));

  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.startCliWorker', async () => {
    const cur = getEffectiveConfig();
    const term = vscode.window.createTerminal({
      name: 'Tele Worker Daemon',
      cwd: cur.folder
    });
    term.show();
    term.sendText('node .vscode/tele-vs-code/tele-worker.js');
    vscode.window.showInformationMessage('🚀 Autonomous Worker dijalankan di terminal.');
  }));

  // 6. Kirim Notifikasi: SELESAI
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.sendSuccess', async () => {
    const cur = getEffectiveConfig();
    const resolved = await getResolvedProject();
    const title = await vscode.window.showInputBox({
      title: 'Kirim Notifikasi SELESAI (100% Selesai)',
      prompt: 'Masukkan judul tugas yang telah selesai 100%',
      placeHolder: 'Contoh: Integrasi Fitur Login Selesai',
      ignoreFocusOut: true
    });
    if (!title) return;

    const detailStr = await vscode.window.showInputBox({
      title: 'Rincian Hasil (Pisahkan dengan koma atau titik)',
      prompt: 'Rincian pekerjaan yang telah tuntas dan teruji',
      placeHolder: 'Contoh: Unit test pass, UI responsif, Bug fixed',
      ignoreFocusOut: true
    });

    const details = detailStr ? detailStr.split(/[,;\n]/).map(d => d.trim()).filter(Boolean) : [];

    try {
      await sendTelegramMessage(resolved.botToken, resolved.chatId, title, 'SELESAI', details, resolved.name, resolved.timezone);
      vscode.window.showInformationMessage(`✅ Notifikasi SELESAI berhasil terkirim ke Telegram!`);
      taskProvider.refresh();
    } catch (err) {
      vscode.window.showErrorMessage(`❌ ${err.message}`);
    }
  }));

  // 7. Kirim Notifikasi: PROSES
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.sendProgress', async () => {
    const cur = getEffectiveConfig();
    const resolved = await getResolvedProject();
    const title = await vscode.window.showInputBox({
      title: 'Kirim Notifikasi PROSES (Sedang Dikerjakan)',
      prompt: 'Masukkan nama tugas yang sedang dikerjakan',
      placeHolder: 'Contoh: Migrasi Database ke PostgreSQL',
      ignoreFocusOut: true
    });
    if (!title) return;

    const progressNote = await vscode.window.showInputBox({
      title: 'Status Progres',
      prompt: 'Keterangan progres pekerjaan saat ini',
      placeHolder: 'Contoh: Skema tabel selesai, sedang migrasi data...',
      ignoreFocusOut: true
    });

    const details = progressNote ? [progressNote.trim()] : [];

    try {
      await sendTelegramMessage(resolved.botToken, resolved.chatId, title, 'PROSES', details, resolved.name, resolved.timezone);
      vscode.window.showInformationMessage(`⚡ Notifikasi PROSES berhasil terkirim ke Telegram!`);
      taskProvider.refresh();
    } catch (err) {
      vscode.window.showErrorMessage(`❌ ${err.message}`);
    }
  }));

  // 8. Kirim Notifikasi: KENDALA
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.sendError', async () => {
    const cur = getEffectiveConfig();
    const resolved = await getResolvedProject();
    const title = await vscode.window.showInputBox({
      title: 'Kirim Notifikasi KENDALA / ERROR',
      prompt: 'Masukkan judul kendala atau error yang memblokir proses',
      placeHolder: 'Contoh: Build Gagal pada Module Auth',
      ignoreFocusOut: true
    });
    if (!title) return;

    const cause = await vscode.window.showInputBox({
      title: 'Penyebab Kendala',
      prompt: 'Jelaskan error atau kendala yang dihadapi',
      placeHolder: 'Contoh: Dependency library X tidak kompatibel dengan Node 20',
      ignoreFocusOut: true
    });

    const details = cause ? [cause.trim()] : [];

    try {
      await sendTelegramMessage(resolved.botToken, resolved.chatId, title, 'KENDALA', details, resolved.name, resolved.timezone);
      vscode.window.showInformationMessage(`🚨 Notifikasi KENDALA berhasil terkirim ke Telegram!`);
      taskProvider.refresh();
    } catch (err) {
      vscode.window.showErrorMessage(`❌ ${err.message}`);
    }
  }));

  // 8c. Unggah File / Media Dokumentasi ke Server Biru
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.uploadMedia', async (resourceUri) => {
    let targetFilePath = '';
    if (resourceUri && resourceUri.fsPath) {
      targetFilePath = resourceUri.fsPath;
    } else {
      const selected = await vscode.window.showOpenDialog({
        title: 'Pilih File / Media Dokumentasi untuk Diunggah ke Server Biru',
        openLabel: 'Unggah ke Server Biru',
        canSelectFiles: true,
        canSelectFolders: false,
        canSelectMany: false
      });
      if (!selected || selected.length === 0) return;
      targetFilePath = selected[0].fsPath;
    }

    if (!fs.existsSync(targetFilePath)) {
      vscode.window.showErrorMessage(`File tidak ditemukan: ${targetFilePath}`);
      return;
    }

    const caption = await vscode.window.showInputBox({
      title: 'Keterangan Dokumentasi (Opsional)',
      prompt: `Masukkan catatan / deskripsi untuk berkas "${path.basename(targetFilePath)}"`,
      placeHolder: 'Contoh: Screenshot perbaikan bug login, arsitektur v1.0.9, dll.',
      ignoreFocusOut: true
    });

    const sendTeleChoice = await vscode.window.showQuickPick([
      { label: '$(check) Ya, kirim juga notifikasi ke Telegram', send: true },
      { label: '$(close) Tidak, simpan saja di Server Biru', send: false }
    ], {
      title: 'Kirim Notifikasi Dokumentasi ke Telegram?',
      placeHolder: 'Pilih opsi pengiriman notifikasi'
    });
    if (!sendTeleChoice) return;

    await vscode.window.withProgress({
      location: vscode.ProgressLocation.Notification,
      title: `Mengunggah "${path.basename(targetFilePath)}" ke Server Biru...`,
      cancellable: false
    }, async () => {
      try {
        const result = await uploadMediaToServer(targetFilePath, caption || '', '', sendTeleChoice.send);
        const action = await vscode.window.showInformationMessage(
          `🎉 [Dokumentasi Tersimpan] Berkas "${result.file_name}" berhasil diunggah ke Server Biru!`,
          'Salin Link Dokumentasi',
          'Buka di Browser'
        );
        if (action === 'Salin Link Dokumentasi') {
          await vscode.env.clipboard.writeText(result.file_url);
          vscode.window.showInformationMessage(`📋 Link tersalin ke clipboard: ${result.file_url}`);
        } else if (action === 'Buka di Browser') {
          vscode.env.openExternal(vscode.Uri.parse(result.file_url));
        }
      } catch (err) {
        vscode.window.showErrorMessage(`❌ Gagal unggah dokumentasi: ${err.message}`);
      }
    });
  }));

  // 8b. Tambah Tugas Baru dari IDE ke TASK_QUEUE & Telegram
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.addTask', async () => {
    const cur = getEffectiveConfig();
    const inst = await vscode.window.showInputBox({
      title: '➕ Tambah Tugas Baru / Kirim Perintah ke Antrean',
      prompt: 'Masukkan instruksi tugas atau perintah untuk AI Agent:',
      placeHolder: 'Contoh: Buatkan modul autentikasi atau perbaiki bug...'
    });
    if (!inst || !inst.trim()) return;

    try {
      const candidates = [
        path.join(cur.folder, '.vscode', 'tele-vs-code', 'tele-task.js'),
        path.join(cur.folder, 'tele-task.js')
      ];
      const taskJs = candidates.find(p => fs.existsSync(p));
      if (taskJs) {
        execSync(`node "${taskJs}" add "${inst.trim().replace(/"/g, '\\"')}" --sender "VS Code IDE" --status IN_PROGRESS`, { cwd: cur.folder, stdio: 'inherit' });
        vscode.window.showInformationMessage(`✅ Tugas berhasil ditambahkan ke antrean & notifikasi Telegram terkirim!`);
        taskProvider.refresh();
      } else {
        vscode.window.showErrorMessage('❌ Skrip tele-task.js tidak ditemukan di folder workspace.');
      }
    } catch (e) {
      vscode.window.showErrorMessage(`❌ Gagal menambah tugas: ${e.message}`);
    }
  }));

  // 9. Tarik Antrean Tugas dari Server Webhook (Manual Trigger)
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.pullTasks', async () => {
    await pullTaskQueue(false);
  }));

  // 10. Buka File TASK_QUEUE.md
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.openQueue', async () => {
    const cur = getEffectiveConfig();
    const queueFile = path.join(cur.folder, 'TASK_QUEUE.md');
    if (!fs.existsSync(queueFile)) {
      ensureWorkspaceTools(context, cur.folder);
    }
    const doc = await vscode.workspace.openTextDocument(queueFile);
    await vscode.window.showTextDocument(doc);
  }));

  // 11. Buka Web Dashboard
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.openDashboard', async () => {
    vscode.env.openExternal(vscode.Uri.parse('https://biru.co.id/tele-vs-code/'));
  }));

  // 12. Setup API Secret Key
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.setupSecretKey', async () => {
    vscode.commands.executeCommand('telegramNotifier.quickSetup');
  }));
}

function deactivate() {
  if (autoPullTimer) {
    clearInterval(autoPullTimer);
    autoPullTimer = null;
  }
}

module.exports = {
  activate,
  deactivate
};
