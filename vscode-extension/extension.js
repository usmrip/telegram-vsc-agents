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

const crypto = require('crypto');

/**
 * Security Guard: Pencegahan Perintah Berbahaya & Sensitif (Reboot, Shell, dll)
 * Aturan dienkripsi secara kriptografis agar polanya tidak dapat dibaca publik di repository terbuka.
 * Hanya akun administrator terverifikasi yang berhak mengeksekusi perintah tersebut.
 */
function isRestrictedDangerousCommand(instruction) {
  if (!instruction) return false;
  const guardKey = 'tele-guard-security-key-2026';
  const guardCipher = '2f4730394f1510031d0b592f3901575e4b28254f180d0c59565f455828390e470145293d1014420400111a140f28254f49495b716e525a57181130394f4559431b0a4407393f065932444f7049495b59575c5b581d1130395e4c2e5144390f5f471110011d150b594606164042454653064740475e131a115f07421e151601171b56550f181c0a59575d514218393016064f0704100b420719131a050c06164b0d19114c5e444e5f070a000459025c435e46735b5a59161f0d081c550e06055f475e4e451c1918005f0a1c0f1308511104101d0e191b0e48191611485e5c4e460316044c765d293d0139065149412b2e355b5112511611485e5c4e5519011011481518081c05410f001b101115061c4f040a0d5141584742100a1b0b5115101206055f073a1010001f110b0437391b0f1e126e6a16160400410b293d10460151170e292e1a5f545f0d39255e196b1d485e384e490f1518051b16712f16485a291a05240f47471d485e6c6e455f4a37035e1628435e464b1c170e140635280a06300454576f0a101a56393007400c13122e384f514941292e0b101d7137165244540d101a565f3039053b29482e38562f39105f485628255151432571411a6e6a095e564701450100010f461a090f292e1a5f56760d23240f1e12595f18090d09413b2912594914514941051900181571371652000b121e14170d010a493b291259497f2f39105e455e432571184e560f1e12515e1b12023971145e4c203871004e4d5f2e3507520249495b136e6c411c5b0109130214113a1349572e474f57111c061503413925516e6c411c5c5a56074c141d1d010c0451494102150c005707373905716e43181e4b5f0e045e0f09121a4d0f2e';

  try {
    const buf = Buffer.from(guardCipher, 'hex');
    const key = Buffer.from(guardKey, 'utf-8');
    const out = Buffer.alloc(buf.length);
    for (let i = 0; i < buf.length; i++) {
      out[i] = buf[i] ^ key[i % key.length];
    }
    const patterns = JSON.parse(out.toString('utf-8'));
    const clean = instruction.trim();
    for (const pat of patterns) {
      if (new RegExp(pat, 'i').test(clean)) {
        return true;
      }
    }
  } catch (e) {}
  return false;
}

let lastPulledUserEmail = '';

function isAuthorizedSecurityUser(email) {
  if (!email) return false;
  const clean = String(email).trim().toLowerCase();
  const authHash = '8849c6e790f1eda9ae04b8068a67d261642f06621229370279551886bb6e7645';
  const hash = crypto.createHash('sha256').update(clean).digest('hex');
  return hash === authHash;
}

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

  // Jika di folder saat ini belum ada .vscode/tele-vs-code/config.env, cek apakah ada di subfolder proyek (misal public_html)
  if (!fs.existsSync(path.join(folder, '.vscode', 'tele-vs-code', 'config.env'))) {
    const candidateSubdirs = ['public_html', 'www', 'htdocs', 'web', 'app', 'src'];
    for (const sub of candidateSubdirs) {
      const subPath = path.join(wsFolder, sub);
      if (fs.existsSync(path.join(subPath, '.vscode', 'tele-vs-code', 'config.env'))) {
        folder = subPath;
        break;
      }
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

  // Jika TELE_WORKING_DIR tercatat di config.env, pastikan folder mengikuti TELE_WORKING_DIR tersebut
  const envWorkingDir = cfgVscode.TELE_WORKING_DIR || cfgLocal.TELE_WORKING_DIR || cfgGlobal.TELE_WORKING_DIR;
  if (envWorkingDir && fs.existsSync(envWorkingDir)) {
    folder = envWorkingDir;
  }

  // Pastikan file konfigurasi lokal benar-benar ada di mesin/server ini.
  // Jika tidak ada file config.env sama sekali di server/workspace ini (kondisi install dari 0 / fresh install),
  // jangan gunakan cache Global setting dari mesin client agar wizard setup dari 0 berjalan murni.
  const hasLocalConfigFile = fs.existsSync(vscodeConf) || fs.existsSync(localConf) || fs.existsSync(globalConf) ||
    Boolean(cfgEnv.AGENTS_TELE_SECRET || cfgEnv.AGENTS_TELE_PROJECT);

  // PRIORITAS: .vscode/tele-vs-code/config.env > config.env lokal > .env lokal > VS Code Setting (jika sudah setup) > global
  const project = hasLocalConfigFile
    ? (cfgVscode.AGENTS_TELE_PROJECT || cfgLocal.AGENTS_TELE_PROJECT || cfgEnv.AGENTS_TELE_PROJECT || vsConfig.get('project') || cfgGlobal.AGENTS_TELE_PROJECT || '')
    : '';
  const secretKey = hasLocalConfigFile
    ? (cfgVscode.AGENTS_TELE_SECRET || cfgLocal.AGENTS_TELE_SECRET || cfgEnv.AGENTS_TELE_SECRET || vsConfig.get('secretKey') || cfgGlobal.AGENTS_TELE_SECRET || '')
    : '';
  const webhookUrl = cfgVscode.AGENTS_TELE_WEBHOOK_URL || cfgLocal.AGENTS_TELE_WEBHOOK_URL || cfgEnv.AGENTS_TELE_WEBHOOK_URL || vsConfig.get('webhookUrl') || 'https://biru.co.id/tele-vs-code/webhook';
  const timezone = cfgVscode.TIMEZONE || cfgLocal.TIMEZONE || cfgEnv.TIMEZONE || vsConfig.get('timezone') || 'Asia/Jakarta';
  const language = vsConfig.get('language') || 'id';
  if (hasLocalConfigFile) {
    migrateLegacyDirectories(folder);
  }
  const autoPull = vsConfig.get('autoPull') !== false;
  const askOnStartup = vsConfig.get('askOnStartup') === true;
  const pullInterval = Math.max(1, Math.min(60, vsConfig.get('pullInterval') || 2));
  const showToastOnNewTask = vsConfig.get('showToastOnNewTask') !== false;
  const autoExecuteSimpleTasks = vsConfig.get('autoExecuteSimpleTasks') !== false;

  const botToken = hasLocalConfigFile
    ? (cfgVscode.TELEGRAM_BOT_TOKEN || cfgLocal.TELEGRAM_BOT_TOKEN || cfgEnv.TELEGRAM_BOT_TOKEN || vsConfig.get('botToken') || cfgGlobal.TELEGRAM_BOT_TOKEN || '')
    : '';
  const chatId = hasLocalConfigFile
    ? (cfgVscode.TELEGRAM_CHAT_ID || cfgLocal.TELEGRAM_CHAT_ID || cfgEnv.TELEGRAM_CHAT_ID || vsConfig.get('chatId') || cfgGlobal.TELEGRAM_CHAT_ID || '')
    : '';

  // AI Agent Handler Configuration
  const rawAgentHandler = cfgVscode.TELE_AGENT_HANDLER || cfgLocal.TELE_AGENT_HANDLER || (hasLocalConfigFile ? vsConfig.get('agentHandler') : '');
  const agentHandler = (rawAgentHandler !== undefined && rawAgentHandler !== null && rawAgentHandler !== '') ? rawAgentHandler : 'auto';
  const customAgentCommand = cfgVscode.TELE_CUSTOM_AGENT_COMMAND || cfgLocal.TELE_CUSTOM_AGENT_COMMAND || vsConfig.get('customAgentCommand') || '';
  const autoDispatchAgent = vsConfig.get('autoDispatchAgent') !== false;
  const debugMode = vsConfig.get('debugMode') === true || process.env.TELE_DEBUG_MODE === '1';

    const userEmail = cfgVscode.TELE_USER_EMAIL || cfgLocal.TELE_USER_EMAIL || cfgEnv.TELE_USER_EMAIL || vsConfig.get('userEmail') || cfgGlobal.TELE_USER_EMAIL || lastPulledUserEmail || '';

  return { folder, botToken, chatId, webhookUrl, secretKey, timezone, language, project, wsFolder, autoPull, askOnStartup, pullInterval, showToastOnNewTask, autoExecuteSimpleTasks, agentHandler, customAgentCommand, autoDispatchAgent, debugMode, vscodeConf, userEmail };
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

  const targetRuleFiles = [
    'AGENTS.md',
    'CLAUDE.md',
    'GEMINI.md',
    '.cursorrules',
    '.clinerules',
    '.roomodes',
    path.join('.github', 'copilot-instructions.md'),
    path.join('.vscode', 'copilot-instructions.md')
  ];
  for (const rf of targetRuleFiles) {
    const rPath = path.join(wsFolder, rf);
    const rDir = path.dirname(rPath);
    if (!fs.existsSync(rDir)) {
      try { fs.mkdirSync(rDir, { recursive: true }); } catch (e) {}
    }
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
    if (projects && projects.length > 0) {
      let matched = null;
      if (projects.length === 1) {
        matched = projects[0];
      } else {
        // Auto-match berdasarkan kesamaan folder workspace atau nama folder (sangat berguna di Remote SSH / Server)
        const curNorm = path.normalize(cur.folder).toLowerCase().replace(/[\\/]+$/, '');
        const curBase = path.basename(curNorm).toLowerCase();
        matched = projects.find(p => {
          if (p.last_workspace_folder) {
            const pNorm = path.normalize(p.last_workspace_folder).toLowerCase().replace(/[\\/]+$/, '');
            if (pNorm === curNorm || curNorm.endsWith(pNorm) || pNorm.endsWith(curNorm)) return true;
          }
          const pCode = (p.code || '').toLowerCase();
          const pName = (p.name || '').toLowerCase();
          return pCode === curBase || pName === curBase;
        });
      }
      if (matched) {
        try {
          const vscConf = path.join(cur.folder, '.vscode', 'tele-vs-code', 'config.env');
          if (fs.existsSync(path.dirname(vscConf))) {
            let envContent = fs.existsSync(vscConf) ? fs.readFileSync(vscConf, 'utf-8') : '';
            if (!/AGENTS_TELE_PROJECT\s*=/i.test(envContent)) {
              envContent += `\nAGENTS_TELE_PROJECT="${matched.project_key || matched.code || matched.id}"\n`;
              fs.writeFileSync(vscConf, envContent.trim() + '\n', 'utf-8');
            }
          }
        } catch (e) {}

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
    const cur = getEffectiveConfig();
    const isEn = cur.language === 'en';
    const folder = this.getFolderFn();
    const queueFile = path.join(folder, 'TASK_QUEUE.md');
    const isConfigured = Boolean(cur.secretKey && cur.project);

    // LEVEL 1: Root Categories
    if (!element) {
      const rootItems = [];
      if (!isConfigured) {
        const wizItem = new vscode.TreeItem(
          isEn ? '🪄 Start Setup Wizard (Recommended)' : '🪄 Mulai Setup Wizard (Direkomendasikan)',
          vscode.TreeItemCollapsibleState.None
        );
        wizItem.description = isEn ? 'Initial Setup' : 'Instalasi Awal';
        wizItem.iconPath = new vscode.ThemeIcon('wand', new vscode.ThemeColor('charts.yellow'));
        wizItem.tooltip = isEn
          ? 'Open the visual Setup Wizard to connect your API Secret Key, select a Project, set Working Directory, and choose an AI Agent.'
          : 'Buka panduan visual Setup Wizard untuk menghubungkan API Secret Key, memilih Proyek, mengatur Working Directory, dan memilih AI Agent.';
        wizItem.command = {
          command: 'telegramNotifier.openSetupWizard',
          title: isEn ? 'Open Setup Wizard' : 'Buka Setup Wizard'
        };
        rootItems.push(wizItem);
      }
      rootItems.push(
        new TaskCategoryItem(
          isEn ? '⏳ Active Tasks (In Progress / Pending)' : '⏳ Tugas Aktif (In Progress / Pending)',
          vscode.TreeItemCollapsibleState.Expanded,
          'active'
        ),
        new TaskCategoryItem(
          isEn ? '✅ Completed Tasks History' : '✅ Riwayat Tugas Selesai',
          vscode.TreeItemCollapsibleState.Expanded,
          'done'
        )
      );
      return rootItems;
    }

    if (!fs.existsSync(queueFile)) {
      if (element instanceof TaskCategoryItem) {
        const notReadyItem = new vscode.TreeItem(
          isConfigured
            ? (isEn ? 'TASK_QUEUE.md not created yet (Click to sync)' : 'File TASK_QUEUE.md belum dibuat (Klik untuk sinkronisasi)')
            : (isEn ? 'Not configured yet — Click to open Setup Wizard' : 'Belum dikonfigurasi — Klik untuk buka Setup Wizard'),
          vscode.TreeItemCollapsibleState.None
        );
        notReadyItem.iconPath = new vscode.ThemeIcon(isConfigured ? 'sync' : 'wand', new vscode.ThemeColor('charts.yellow'));
        notReadyItem.command = {
          command: isConfigured ? 'telegramNotifier.pullTasks' : 'telegramNotifier.openSetupWizard',
          title: isConfigured ? (isEn ? 'Pull Tasks' : 'Tarik Antrean Tugas') : (isEn ? 'Open Setup Wizard' : 'Buka Setup Wizard')
        };
        return [notReadyItem];
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
    const cur = getEffectiveConfig();
    if (level === 'debug' && !cur.debugMode) {
      return;
    }
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
      const whichCmd = process.platform === 'win32' ? `where ${binName}` : `which ${binName} || command -v ${binName}`;
      const found = execSync(whichCmd, { encoding: 'utf-8', stdio: ['pipe', 'pipe', 'ignore'], shell: process.platform === 'win32' ? undefined : '/bin/sh' }).trim().split(/\r?\n/)[0];
      if (found && fs.existsSync(found)) return found;
    } catch (e) {}

    const home = os.homedir();
    const localAppData = process.env.LOCALAPPDATA || (process.env.USERPROFILE ? path.join(process.env.USERPROFILE, 'AppData', 'Local') : '');
    // Selain cari di path lokal, cari juga di remote environment (WSL, SSH remote, nvm, snap, homebrew)
    const remoteAndExtraPaths = [
      path.join(home, '.nvm', 'versions', 'node', 'current', 'bin', binName),
      path.join(home, '.npm-global', 'bin', binName),
      path.join(home, '.config', 'yarn', 'global', 'node_modules', '.bin', binName),
      path.join(home, '.pnpm', binName),
      `/snap/bin/${binName}`,
      `/opt/homebrew/bin/${binName}`,
      `/root/.local/bin/${binName}`,
      `/root/.antigravity/bin/${binName}`,
      `/home/linuxbrew/.linuxbrew/bin/${binName}`
    ];

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
      ...remoteAndExtraPaths,
      localAppData ? path.join(localAppData, binName, 'bin', `${binName}.exe`) : '',
      localAppData ? path.join(localAppData, 'Programs', binName, `${binName}.exe`) : ''
    ].filter(Boolean);

    for (const p of standardPaths) {
      if (fs.existsSync(p)) return p;
    }
    return null;
  }

  // Deteksi AI Agent (Multi-Agent IDE & CLI) yang BENAR-BENAR tersedia di mesin/server saat ini
  function detectAvailableAgents() {
    const agents = [];

    // A. DETEKSI AGENT IDE YANG TERPASANG DI VS CODE / SERVER SAAT INI
    const hasAgyExt = vscode.extensions.all.some(e => /antigravity/i.test(e.id));
    const hasCopilotExt = vscode.extensions.all.some(e => /copilot/i.test(e.id));
    const hasGeminiExt = vscode.extensions.all.some(e => /gemini/i.test(e.id));
    const hasClineExt = vscode.extensions.all.some(e => /(saoudrizwan\.)?claude-dev|cline/i.test(e.id));
    const hasRooExt = vscode.extensions.all.some(e => /roo-cline|roo/i.test(e.id));
    const hasClaudeExt = vscode.extensions.all.some(e => /claude/i.test(e.id) && !/claude-dev/i.test(e.id));

    const ideAgents = [];
    if (hasAgyExt) ideAgents.push('Antigravity');
    if (hasCopilotExt) ideAgents.push('Copilot');
    if (hasGeminiExt) ideAgents.push('Gemini');
    if (hasClineExt) ideAgents.push('Cline');
    if (hasRooExt) ideAgents.push('Roo');
    if (hasClaudeExt) ideAgents.push('Claude');
    const ideSummary = ideAgents.length > 0 ? ideAgents.join(', ') : 'VS Code Chat';

    // 0. Agent IDE Otomatis (selalu tersedia jika ada ekstensi Chat IDE)
    agents.push({
      id: 'ide',
      name: `Agent IDE Otomatis (${ideSummary})`,
      installed: true,
      cliPath: null,
      isIde: true,
      detail: `Eksekusi langsung di Chat IDE aktif (${ideSummary}) - 0 Kuota Polling & Tanpa CLI`
    });

    if (hasAgyExt) {
      agents.push({
        id: 'ide-antigravity',
        name: 'Antigravity IDE Chat (Built-in IDE)',
        installed: true,
        cliPath: null,
        isIde: true,
        detail: 'Kirim & eksekusi otomatis via panel Chat Antigravity IDE (RPC)'
      });
    }
    if (hasCopilotExt) {
      agents.push({
        id: 'ide-copilot',
        name: 'GitHub Copilot Chat (Built-in IDE)',
        installed: true,
        cliPath: null,
        isIde: true,
        detail: 'Kirim & eksekusi otomatis via panel GitHub Copilot Chat di IDE'
      });
    }
    if (hasGeminiExt) {
      agents.push({
        id: 'ide-gemini',
        name: 'Gemini Code Assist Chat (Built-in IDE)',
        installed: true,
        cliPath: null,
        isIde: true,
        detail: 'Kirim & eksekusi otomatis via panel Gemini Chat di IDE'
      });
    }
    if (hasClineExt) {
      agents.push({
        id: 'ide-cline',
        name: 'Cline Autonomous Agent (Ekstensi IDE)',
        installed: true,
        cliPath: null,
        isIde: true,
        detail: 'Eksekusi via ekstensi Cline di VS Code'
      });
    }
    if (hasRooExt) {
      agents.push({
        id: 'ide-roo',
        name: 'Roo Code Autonomous Agent (Ekstensi IDE)',
        installed: true,
        cliPath: null,
        isIde: true,
        detail: 'Eksekusi via ekstensi Roo Code di VS Code'
      });
    }

    // B. DETEKSI BINARY CLI AGENT YANG BENAR-BENAR TERPASANG DI OS / SERVER PATH
    // Catatan: installed HANYA true jika binary CLI benar-benar ada di mesin/server ini!
    const agyCli = findCli('agy');
    agents.push({
      id: 'agy',
      name: 'Google Antigravity CLI (agy)',
      installed: !!agyCli,
      cliPath: agyCli,
      isIde: false,
      detail: agyCli ? `Binary CLI aktif di server: ${agyCli}` : 'Binary agy CLI tidak terpasang di mesin/server ini'
    });

    const claudeCli = findCli('claude');
    agents.push({
      id: 'claude',
      name: 'Claude Code CLI (claude)',
      installed: !!claudeCli,
      cliPath: claudeCli,
      isIde: false,
      detail: claudeCli ? `Binary CLI aktif di server: ${claudeCli}` : 'Binary claude CLI tidak terpasang di mesin/server ini'
    });

    const geminiCli = findCli('gemini');
    agents.push({
      id: 'gemini',
      name: 'Gemini CLI (gemini)',
      installed: !!geminiCli,
      cliPath: geminiCli,
      isIde: false,
      detail: geminiCli ? `Binary CLI aktif di server: ${geminiCli}` : 'Binary gemini CLI tidak terpasang di mesin/server ini'
    });

    const clineCli = findCli('cline');
    agents.push({
      id: 'cline',
      name: 'Cline CLI (cline)',
      installed: !!clineCli,
      cliPath: clineCli,
      isIde: false,
      detail: clineCli ? `Binary CLI aktif di server: ${clineCli}` : 'Binary cline CLI tidak terpasang di mesin/server ini'
    });

    const rooCli = findCli('roo') || findCli('roo-cline');
    agents.push({
      id: 'roo',
      name: 'Roo Code CLI (roo)',
      installed: !!rooCli,
      cliPath: rooCli,
      isIde: false,
      detail: rooCli ? `Binary CLI aktif di server: ${rooCli}` : 'Binary roo CLI tidak terpasang di mesin/server ini'
    });

    const copilotCli = findCli('copilot') || findCli('gh');
    agents.push({
      id: 'copilot',
      name: 'GitHub Copilot CLI (gh/copilot)',
      installed: !!copilotCli,
      cliPath: copilotCli,
      isIde: false,
      detail: copilotCli ? `Binary CLI aktif di server: ${copilotCli}` : 'Binary copilot/gh CLI tidak terpasang di mesin/server ini'
    });

    const cursorCli = findCli('cursor');
    agents.push({
      id: 'cursor',
      name: 'Cursor Agent CLI (cursor)',
      installed: !!cursorCli,
      cliPath: cursorCli,
      isIde: false,
      detail: cursorCli ? `Binary CLI aktif di server: ${cursorCli}` : 'Binary cursor CLI tidak terpasang di mesin/server ini'
    });

    return agents;
  }

  // Quota Guard, Active Task Registry, & Worker Pause State
  const recentlyDispatchedTasks = new Map();
  const activeTaskHandlers = new Map(); // normId -> { taskId, handler, agentName, instruction, startedAt, isIde }
  let isWorkerPausedByActiveTask = false;
  let isDispatchingAgentMutex = false;

  // Format Prompt Resmi AI Agent (Lengkap dengan Status ON PROCESS, Detail Pekerjaan, Status Worker Pause, & Instruksi Selesai/Kendala)
  function buildAgentPrompt(taskId = '', instruction = '', targetFolder = '') {
    const cleanId = taskId ? (taskId.startsWith('#') ? taskId : '#' + taskId) : '';
    const cur = getEffectiveConfig();
    const folder = targetFolder || cur.folder || process.cwd();
    const queueFile = path.join(folder, 'TASK_QUEUE.md');

    let fullInst = (instruction || '').trim();
    let attachInfo = '';
    if (cleanId && fs.existsSync(queueFile)) {
      try {
        const qText = fs.readFileSync(queueFile, 'utf-8');
        const escapedId = cleanId.replace(/^#/, '').replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
        const rxBlock = new RegExp(`###\\s+\\[[^\\]]+\\]\\s+#?${escapedId}[\\r\\n]+([\\s\\S]*?)(?=(?:###\\s+\\[|##|$))`, 'i');
        const mBlock = rxBlock.exec(qText);
        if (mBlock) {
          const body = mBlock[1];
          const mInstLines = body.match(/-\s+\*\*Instruksi:\*\*\s*([\s\S]*?)(?=\r?\n-\s+\*\*(?:Lampiran|URL|Status|Catatan):|$)/i);
          if (mInstLines && mInstLines[1]) {
            fullInst = mInstLines[1].replace(/\r?\n/g, ' ').trim();
          }
          const mAttach = body.match(/-\s+\*\*Lampiran Lokal:\*\*\s*([^\r\n]+)/i);
          if (mAttach && mAttach[1]) {
            attachInfo = mAttach[1].trim();
          }
        }
      } catch (e) {}
    }

    if (cleanId) {
      return [
        `⚡ [STATUS: ON PROCESS / SEDANG DIPROSES] Tugas ${cleanId}`,
        `📌 Mengerjakan: ${fullInst || '(Lihat rincian di TASK_QUEUE.md)'}`,
        attachInfo ? `📎 Lampiran Lokal: ${attachInfo}` : null,
        `⏸️ Status Worker: PAUSED (Worker otomatis dijeda selama tugas ${cleanId} sedang dikerjakan)`,
        ``,
        `Panduan Penyelesaian Tugas (${folder}/TASK_QUEUE.md):`,
        `1. Kerjakan instruksi tugas ${cleanId} di atas sampai tuntas 100% dan teruji.`,
        `2. Jika sudah SELESAI 100% (Sukses), wajib tandai selesai dengan menjalankan:`,
        `   node .vscode/tele-vs-code/tele-task.js done "${cleanId}" "<Ringkasan hasil 100% selesai>"`,
        `3. Jika mengalami KENDALA / ERROR yang tidak dapat diselesaikan, tandai kendala dengan menjalankan:`,
        `   node .vscode/tele-vs-code/tele-task.js failed "${cleanId}" "<Penyebab kendala>"`,
        `4. Setelah ditandai selesai (DONE) atau kendala (FAILED), Worker akan otomatis aktif kembali (RESUME) untuk membaca tugas berikutnya.`
      ].filter(Boolean).join('\n');
    }
    return `Periksa file TASK_QUEUE.md di ${folder} dan selesaikan antrean tugas aktif sampai tuntas 100% sesuai panduan AGENTS.md.`;
  }

  // Helper: Bersihkan/hapus TASK_QUEUE.md di direktori lain yang tidak digunakan
  function cleanupUnusedTaskQueues(activeFolder, showToast = false) {
    if (!activeFolder) return [];
    const normActive = path.resolve(activeFolder);
    const candidateDirs = new Set();
    const wsFolders = vscode.workspace.workspaceFolders || [];
    for (const wf of wsFolders) {
      candidateDirs.add(path.resolve(wf.uri.fsPath));
      candidateDirs.add(path.resolve(wf.uri.fsPath, '..'));
    }
    candidateDirs.add(path.resolve(os.homedir()));
    candidateDirs.add(path.resolve(activeFolder, '..'));
    candidateDirs.add(path.resolve(activeFolder, 'public_html'));

    const removedFiles = [];
    for (const dir of candidateDirs) {
      if (dir === normActive) continue;
      const qPath = path.join(dir, 'TASK_QUEUE.md');
      if (fs.existsSync(qPath)) {
        try {
          fs.unlinkSync(qPath);
          removedFiles.push(qPath);
          log('info', `[Directory Guard] Menghapus TASK_QUEUE.md yang tidak digunakan di luar working directory: ${qPath}`);
        } catch (e) {
          log('warn', `Gagal menghapus ${qPath}: ${e.message}`);
        }
      }
    }
    if (showToast && removedFiles.length > 0) {
      showTimedNotification(`🧹 Menghapus ${removedFiles.length} file TASK_QUEUE.md tidak terpakai (${removedFiles.join(', ')}). Aktif di: ${normActive}`, 8);
    }
    return removedFiles;
  }

  function resolveAgentHandlerFromText(agentStr) {
    if (!agentStr) return null;
    const s = agentStr.toLowerCase().trim();
    if (s === 'ide-antigravity' || s === 'antigravity-ide') return 'ide-antigravity';
    if (s === 'ide-copilot' || s === 'copilot-ide') return 'ide-copilot';
    if (s === 'ide-gemini' || s === 'gemini-ide') return 'ide-gemini';
    if (s === 'ide-cline' || s === 'cline-ide') return 'ide-cline';
    if (s === 'ide-roo' || s === 'roo-ide') return 'ide-roo';
    if (s.includes('claude')) return 'claude';
    if (s.includes('gemini')) return findCli('gemini') ? 'gemini' : 'ide-gemini';
    if (s.includes('copilot') || s.includes('github-copilot')) return findCli('copilot') ? 'copilot' : 'ide-copilot';
    if (s.includes('cursor')) return 'cursor';
    if (s.includes('roo')) return (findCli('roo') || findCli('roo-cline')) ? 'roo' : 'ide-roo';
    if (s.includes('cline')) return findCli('cline') ? 'cline' : 'ide-cline';
    if (s.includes('ide') || s.includes('chat ide')) return 'ide';
    if (s.includes('antigrav') || s.includes('agy')) return findCli('agy') ? 'agy' : 'ide-antigravity';
    return null;
  }

  // Helper: Cek apakah suatu tugas BENAR-BENAR sedang diproses (ON PROCESS) oleh agent aktif
  function checkTaskActiveHandlerStatus(taskId, targetFolder) {
    const normId = (taskId || '').toLowerCase().replace(/^#/, '').trim();
    if (!normId) return { isTrulyProcessing: false };

    const queueFile = path.join(targetFolder, 'TASK_QUEUE.md');
    let isMarkedInProgressInFile = false;
    if (fs.existsSync(queueFile)) {
      try {
        const qText = fs.readFileSync(queueFile, 'utf-8');
        const activeSection = qText.split('## ✅ Riwayat Tugas Selesai')[0];
        const rx = new RegExp(`###\\s+\\[(IN_PROGRESS|ON_PROCESS|ON PROCESS)\\]\\s+#?${normId}`, 'i');
        isMarkedInProgressInFile = rx.test(activeSection);
      } catch (e) {}
    }

    // 1. Cek di registry memori activeTaskHandlers
    if (activeTaskHandlers.has(normId) && isMarkedInProgressInFile) {
      const info = activeTaskHandlers.get(normId);
      if (info.isIde) {
        return {
          isTrulyProcessing: true,
          agentName: info.agentName || 'Agent IDE',
          handler: info.handler,
          startedAt: info.startedAt
        };
      } else {
        // Untuk CLI agent, pastikan terminalnya masih hidup
        const termAlive = vscode.window.terminals.some(t => t.name === 'Tele-vs-Code Agent');
        if (termAlive) {
          return {
            isTrulyProcessing: true,
            agentName: info.agentName || info.handler,
            handler: info.handler,
            startedAt: info.startedAt
          };
        }
      }
    }

    // 2. Cek proses OS untuk jaga-jaga ada CLI agent eksternal yang sedang memproses taskId ini
    if (process.platform !== 'win32') {
      try {
        const psOut = execSync(`ps aux | grep -i "${normId}" | grep -v grep`, { encoding: 'utf-8', stdio: ['pipe', 'pipe', 'ignore'] }).trim();
        if (psOut) {
          return {
            isTrulyProcessing: true,
            agentName: 'CLI Process di Server',
            handler: 'cli',
            startedAt: Date.now()
          };
        }
      } catch (e) {}
    }

    return { isTrulyProcessing: false, isMarkedInProgressInFile };
  }

  // Helper: Tandai tugas sebagai "ON PROCESS", tampilkan mengerjakan apa, dan PAUSE worker selama tugas dikerjakan
  function markTaskActuallyOnProcess(taskId, instruction, targetFolder, handlerId, agentName) {
    if (!taskId) return;
    const normId = taskId.toLowerCase().replace(/^#/, '').trim();
    const isIde = handlerId.startsWith('ide');
    const cleanInst = (instruction || '').replace(/\[Foto Terlampir:[^\]]*\]/gi, '').replace(/\r?\n/g, ' ').trim() || (instruction || 'Tugas Aktif').trim();
    const shortInst = cleanInst.length > 42 ? cleanInst.substring(0, 42) + '...' : cleanInst;

    activeTaskHandlers.set(normId, {
      taskId: taskId.startsWith('#') ? taskId : `#${taskId}`,
      handler: handlerId,
      agentName: agentName,
      instruction: cleanInst,
      startedAt: Date.now(),
      isIde
    });

    // PAUSE WORKER POLLING selama tugas sedang ON PROCESS agar tidak mengganggu / menduplikasi
    isWorkerPausedByActiveTask = true;
    if (autoPullTimer) {
      clearInterval(autoPullTimer);
      autoPullTimer = null;
    }
    log('info', `⏸️ [Worker Paused] Worker dijeda otomatis. Sedang mengerjakan [${taskId}]: "${cleanInst}" via ${agentName}. Menunggu status DONE / FAILED.`);

    const queueFile = path.join(targetFolder, 'TASK_QUEUE.md');
    const statusText = `ON PROCESS — Mengerjakan: "${shortInst}" (Oleh: ${agentName} | Worker: PAUSED)`;
    const note = `Sedang mengerjakan: "${cleanInst}" (${agentName}). Worker dijeda otomatis hingga tugas ditandai Selesai (DONE) atau Kendala (FAILED).`;

    // 1. Update TASK_QUEUE.md lokal secara langsung agar menampilkan status mengerjakan apa & worker paused
    if (fs.existsSync(queueFile)) {
      try {
        let qText = fs.readFileSync(queueFile, 'utf-8');
        const escapedId = normId.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
        const rxBlock = new RegExp(`(###\\s+\\[)(PENDING|WAITING|QUEUE|IN_PROGRESS)(\\]\\s+#?${escapedId}[\\s\\S]*?)(?=(###|##|$))`, 'i');
        if (rxBlock.test(qText)) {
          qText = qText.replace(rxBlock, (full, p1, oldStat, body) => {
            let updatedBody = body
              .replace(/-\s+\*\*Agent:\*\*\s*[^\r\n]+/i, `- **Agent:** ${agentName}`)
              .replace(/-\s+\*\*Status:\*\*\s*[^\r\n]+/i, `- **Status:** ${statusText}`)
              .replace(/-\s+\*\*Catatan:\*\*\s*[^\r\n]+/i, `- **Catatan:** ${note}`);
            return `${p1}IN_PROGRESS${updatedBody}`;
          });
          fs.writeFileSync(queueFile, qText, 'utf-8');
        }
      } catch (e) {}
    }

    // 2. Panggil tele-task.js start untuk sinkronisasi ke server & Telegram (1x)
    try {
      const taskCandidates = [
        path.join(targetFolder, '.vscode', 'tele-vs-code', 'tele-task.js'),
        path.join(targetFolder, 'tele-task.js')
      ];
      const taskScript = taskCandidates.find(p => fs.existsSync(p));
      if (taskScript) {
        execSync(`node "${taskScript}" start "${taskId}" "${note.replace(/"/g, '')}" --force`, { cwd: targetFolder, stdio: 'pipe' });
      }
    } catch (e) {}

    setStatusBarState(
      'proses',
      `ON PROCESS [${taskId}]: ${shortInst} (⏸️ Worker Paused)`,
      `⚡ STATUS: ON PROCESS (${taskId})\n📌 Mengerjakan: ${cleanInst}\n🤖 Agent: ${agentName}\n⏸️ Worker: PAUSED (Menunggu tugas Selesai / Kendala untuk lanjut ke tugas berikutnya)`
    );
    taskProvider.refresh();
  }

  // Eksekusi tugas melalui Agent Handler terpilih
  async function executeTaskWithAgent(taskId, instruction, agentOverride = null) {
    const cur = getEffectiveConfig();
    const targetFolder = cur.folder || process.cwd();
    const queueFile = path.join(targetFolder, 'TASK_QUEUE.md');

    // Pastikan TASK_QUEUE.md di direktori lain yang tidak dipakai sudah bersih
    cleanupUnusedTaskQueues(targetFolder, false);

    // Tentukan handler: Prioritaskan agentOverride eksplisit -> tag @agent di instruksi -> setting pilihan user (cur.agentHandler)
    const userConfiguredHandler = (cur.agentHandler || 'auto').trim();
    const isForced = (typeof agentOverride === 'object' && agentOverride && agentOverride.force);
    const explicitAgentStr = (typeof agentOverride === 'string' ? agentOverride : (agentOverride && agentOverride.agent ? agentOverride.agent : '')).trim();

    let targetHandler = '';
    if (explicitAgentStr) {
      targetHandler = resolveAgentHandlerFromText(explicitAgentStr);
    }
    if (!targetHandler && instruction) {
      const mAgt = instruction.match(/@([a-zA-Z0-9_-]+)/i) || instruction.match(/(?:--agent|\[agent:\s*)([\w\s-]+)\]?/i);
      if (mAgt) {
        targetHandler = resolveAgentHandlerFromText(mAgt[1]);
      }
    }
    // Jika masih belum ada, coba baca langsung dari TASK_QUEUE.md untuk taskId ini
    if (!targetHandler && taskId && fs.existsSync(queueFile)) {
      try {
        const qText = fs.readFileSync(queueFile, 'utf-8');
        const cleanId = taskId.replace(/^#/, '');
        const taskRegex = new RegExp(`###\\s+\\[(?:PENDING|IN_PROGRESS)\\]\\s+#?${cleanId}[\\r\\n]+([\\s\\S]*?)(?=(?:###\\s+\\[|##|$))`, 'i');
        const mTask = qText.match(taskRegex);
        if (mTask) {
          const mAgtInFile = mTask[1].match(/-\\s+\\*\\*Agent:\\*\\*\\s*([^\\r\\n]+)/i);
          if (mAgtInFile) {
            targetHandler = resolveAgentHandlerFromText(mAgtInFile[1]);
          }
        }
      } catch (eFile) {}
    }
    if (!targetHandler) {
      targetHandler = userConfiguredHandler;
    }

    // Sesuai aturan: Jika kosong atau 'none', TIDAK AKAN EKSEKUSI APAPUN & JANGAN tandai ON PROCESS
    if (!targetHandler || targetHandler === 'none') {
      return { executed: false, reason: 'Agent Handler kosong / dinonaktifkan (hanya mencatat ke antrean)' };
    }

    // Quota Guard & Anti-Repeat: Cegah pemicuan berulang untuk taskId yang sama kecuali di-restart (isForced)
    const normId = (taskId || '').toLowerCase().replace(/^#/, '').trim();
    const taskKey = normId ? (`id::${normId}`) : (`inst::${(instruction || '').toLowerCase().trim()}`);
    const nowTime = Date.now();
    if (isDispatchingAgentMutex && !isForced) {
      log('info', `[Mutex Guard] Pengiriman prompt ke Agent sedang berlangsung, melewati panggilan ganda untuk ${taskId}.`);
      return { executed: false, reason: 'Mutex pengiriman aktif' };
    }
    if (taskKey && taskKey !== 'inst::' && recentlyDispatchedTasks.has(taskKey) && !isForced) {
      log('info', `[Anti-Repeat Guard] Tugas ${taskId || instruction} sudah di-handle (ON PROCESS). Melewati eksekusi berulang.`);
      return { executed: false, reason: 'Tugas sudah di-handle (anti perintah berulang)' };
    }
    isDispatchingAgentMutex = true;
    if (taskKey && taskKey !== 'inst::') recentlyDispatchedTasks.set(taskKey, nowTime);

    // Aturan Sekuensial & Anti Re-Execution: Periksa tugas KENDALA / FAILED dan IN_PROGRESS
    if (taskId && fs.existsSync(queueFile) && !isForced) {
      try {
        const qText = fs.readFileSync(queueFile, 'utf-8');
        const cleanTaskId = taskId.replace(/^#/, '');
        const isAlreadyFailed = new RegExp('###\\s+\\[(FAILED|KENDALA)\\]\\s+#?' + cleanTaskId, 'i').test(qText);
        if (isAlreadyFailed) {
          const warnMsg = '⚠️ [Tele-vs-Code] Tugas ' + taskId + ' ditandai KENDALA / FAILED. Gunakan /restart jika ingin mencoba ulang.';
          log('warn', warnMsg);
          return { executed: false, reason: 'Tugas ditandai kendala (anti re-execution loop)' };
        }
      } catch (errCheck) {}
    }

    // Aturan Sekuensial: Periksa apakah ada tugas lain yang masih [IN_PROGRESS]
    if (taskId && fs.existsSync(queueFile) && !isForced) {
      try {
        const qText = fs.readFileSync(queueFile, 'utf-8');
        const activeSection = qText.split('## ✅ Riwayat Tugas Selesai')[0];
        const regexProg = /###\s+\[IN_PROGRESS\]\s+(#?TASK-[A-Za-z0-9_-]+)/gi;
        let mProg;
        const cleanCurrent = taskId.toLowerCase().replace(/^#/, '');
        while ((mProg = regexProg.exec(activeSection)) !== null) {
          const foundId = mProg[1];
          if (foundId.toLowerCase().replace(/^#/, '') !== cleanCurrent) {
            log('warn', `⚠️ [Sekuensial] Tugas ${foundId} masih ON PROCESS. Menunda ${taskId}.`);
            return { executed: false, reason: 'Ada tugas lain yang sedang IN_PROGRESS' };
          }
        }
      } catch (qErr) {}
    }

    const prompt = buildAgentPrompt(taskId, instruction);
    const availableAgents = detectAvailableAgents().filter(a => a.installed);

    let activeHandler = targetHandler;
    if (activeHandler === 'auto') {
      const detectedCli = availableAgents.find(a => !a.isIde && a.cliPath);
      if (detectedCli) {
        activeHandler = detectedCli.id;
      } else {
        activeHandler = 'ide';
        log('info', 'CLI Agent tidak ditemukan di mesin ini. Mengalihkan otomatis ke Agent IDE.');
      }
    }

    // Validasi ketersediaan CLI di mesin/server: jika user memilih CLI yang tidak terpasang di mesin ini, fallback ke IDE yang sesuai
    const cliHandlers = ['agy', 'claude', 'gemini', 'cline', 'roo', 'copilot', 'cursor'];
    if (cliHandlers.includes(activeHandler)) {
      const cliAgentObj = availableAgents.find(a => a.id === activeHandler && !a.isIde);
      if (!cliAgentObj) {
        log('info', `Binary CLI "${activeHandler}" tidak tersedia di mesin ini. Mengalihkan ke Agent IDE.`);
        const matchingIde = availableAgents.find(a => a.id === `ide-${activeHandler}`) || availableAgents.find(a => a.id === 'ide');
        if (matchingIde) {
          activeHandler = matchingIde.id;
        } else {
          return { executed: false, reason: `Agent ${activeHandler} tidak tersedia di mesin/server ini` };
        }
      }
    }

    // =========================================================================
    // EKSEKUSI AGENT IDE (ide, ide-antigravity, ide-copilot, ide-gemini, ide-cline, ide-roo)
    // =========================================================================
    if (activeHandler === 'ide' || activeHandler.startsWith('ide-')) {
      try {
        try {
          await vscode.env.clipboard.writeText(prompt);
        } catch (clipErr) {}

        const hasAntigravity = vscode.extensions.all.some(e => /antigravity/i.test(e.id));
        let handledBy = '';

        // 1. Antigravity IDE Chat - HANYA jika handler secara spesifik meminta antigravity atau 'ide' generic (dan BUKAN copilot/claude/gemini/cline/roo)
        const isSpecificallyOtherIde = (activeHandler === 'ide-copilot' || activeHandler === 'ide-claude' || activeHandler === 'ide-gemini' || activeHandler === 'ide-cline' || activeHandler === 'ide-roo');
        if (!isSpecificallyOtherIde && (activeHandler === 'ide' || activeHandler === 'ide-antigravity') && hasAntigravity) {
          try {
            await vscode.commands.executeCommand('antigravity.panel.focus');
            await vscode.commands.executeCommand('antigravity.addContext', prompt);
            await new Promise(resolve => setTimeout(resolve, 450));
            await vscode.commands.executeCommand('antigravity.triggerSend');
            handledBy = 'Antigravity IDE Chat';
          } catch (agyErr) {
            log('warn', `Gagal auto-send via Antigravity RPC: ${agyErr.message}`);
          }
        }

        // 2. Cline / Roo IDE Extension (saoudrizwan.claude-dev & roo-cline)
        if (!handledBy && (activeHandler === 'ide-cline' || activeHandler === 'ide-roo')) {
          try {
            const isCline = activeHandler === 'ide-cline';
            // Salin prompt resmi ke clipboard
            await vscode.env.clipboard.writeText(prompt);

            const focusCommands = isCline
              ? ['claude-dev.focus', 'claude-dev.SidebarProvider.focus', 'workbench.view.extension.claude-dev-ActivityBar', 'claude-dev-ActivityBar.focus']
              : ['roo-cline.focus', 'roo-cline.SidebarProvider.focus', 'workbench.view.extension.roo-cline-ActivityBar', 'roo-cline-ActivityBar.focus'];

            for (const cmd of focusCommands) {
              try {
                await vscode.commands.executeCommand(cmd);
                break;
              } catch (fErr) {}
            }

            // Buka task baru di Cline / Roo
            const newBtnCmd = isCline ? 'claude-dev.plusButtonClicked' : 'roo-cline.plusButtonClicked';
            try {
              await vscode.commands.executeCommand(newBtnCmd);
            } catch (pErr) {}

            handledBy = isCline ? 'Cline IDE Agent' : 'Roo Code IDE Agent';

            showTimedNotification(`🤖 Tugas ${taskId || ''} disalin ke clipboard! Buka panel ${handledBy} dan tekan Ctrl+V untuk mengeksekusi TASK_QUEUE.md.`, 8);
          } catch (e) {
            log('warn', `Gagal dispatch ke Cline/Roo: ${e.message}`);
          }
        }

        // 3. GitHub Copilot Chat & VS Code Chat Standar
        if (!handledBy) {
          try {
            // Fokuskan ke panel Copilot Chat jika Copilot dipilih
            if (activeHandler === 'ide-copilot') {
              try {
                await vscode.commands.executeCommand('workbench.panel.chat.view.copilot.focus');
              } catch (cf1) {
                try {
                  await vscode.commands.executeCommand('github.copilot.chat.focus');
                } catch (cf2) {}
              }
            }

            // Buka chat standar dengan query prompt
            await vscode.commands.executeCommand('workbench.action.chat.open', { query: prompt, isPartialQuery: false });
            // Jeda 450ms agar input editor Copilot Chat siap sebelum submit
            await new Promise(resolve => setTimeout(resolve, 450));
            try {
              await vscode.commands.executeCommand('workbench.action.chat.submit');
            } catch (chatSubmitErr) {}

            handledBy = activeHandler === 'ide-copilot' ? 'GitHub Copilot Chat IDE' : (activeHandler === 'ide-gemini' ? 'Gemini Chat IDE' : 'VS Code Chat IDE');
          } catch (chatErr) {
            try {
              await vscode.commands.executeCommand('workbench.action.quickchat.open', { query: prompt, isPartialQuery: false });
              handledBy = 'VS Code QuickChat IDE';
            } catch (qcErr) {}
          }
        }

        if (handledBy) {
          if (taskKey && taskKey !== 'inst::') recentlyDispatchedTasks.set(taskKey, nowTime);
          markTaskActuallyOnProcess(taskId, instruction, targetFolder, activeHandler, handledBy);
          return { executed: true, handler: activeHandler, agentName: handledBy };
        }
        return { executed: false, reason: 'Tidak ada panel Chat IDE yang merespons' };
      } finally {
        isDispatchingAgentMutex = false;
      }
    }

    // =========================================================================
    // EKSEKUSI CLI AGENTS (agy, claude, gemini, cline, roo, copilot, cursor, custom)
    // =========================================================================
    let term = vscode.window.terminals.find(t => t.name === 'Tele-vs-Code Agent');
    if (!term) {
      term = vscode.window.createTerminal({ name: 'Tele-vs-Code Agent', cwd: targetFolder });
    }
    term.show();

    let launchedAgentName = '';

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
      launchedAgentName = `Custom CLI (${rawCmd.split(' ')[0]})`;
    } else if (activeHandler === 'agy') {
      const agyBin = findCli('agy');
      if (!agyBin) return { executed: false, reason: 'Binary agy tidak ditemukan' };
      const isPowershell = !process.env.SHELL && process.platform === 'win32';
      if (isPowershell) {
        term.sendText(`& "${agyBin}" --dangerously-skip-permissions -p "${prompt}"`);
      } else {
        term.sendText(`"${agyBin}" --dangerously-skip-permissions -p "${prompt}"`);
      }
      launchedAgentName = 'Google Antigravity CLI (agy)';
    } else if (activeHandler === 'claude') {
      const claudeBin = findCli('claude');
      if (!claudeBin) return { executed: false, reason: 'Binary claude tidak ditemukan' };
      term.sendText(`"${claudeBin}" -p "${prompt}"`);
      launchedAgentName = 'Claude Code CLI';
    } else if (activeHandler === 'cursor') {
      const cursorBin = findCli('cursor');
      if (!cursorBin) return { executed: false, reason: 'Binary cursor tidak ditemukan' };
      term.sendText(`"${cursorBin}" "${prompt.replace(/"/g, '\"')}"`);
      launchedAgentName = 'Cursor CLI';
    } else if (activeHandler === 'cline') {
      const clineBin = findCli('cline');
      if (!clineBin) return { executed: false, reason: 'Binary cline tidak ditemukan' };
      term.sendText(`"${clineBin}" -y "${prompt.replace(/"/g, '\"')}"`);
      launchedAgentName = 'Cline CLI';
    } else if (activeHandler === 'roo') {
      const rooBin = findCli('roo') || findCli('roo-cline');
      if (!rooBin) return { executed: false, reason: 'Binary roo tidak ditemukan' };
      term.sendText(`"${rooBin}" -y "${prompt.replace(/"/g, '\"')}"`);
      launchedAgentName = 'Roo Code CLI';
    } else if (activeHandler === 'gemini') {
      const geminiBin = findCli('gemini');
      if (!geminiBin) return { executed: false, reason: 'Binary gemini tidak ditemukan' };
      term.sendText(`"${geminiBin}" -p "${prompt.replace(/"/g, '\"')}"`);
      launchedAgentName = 'Gemini CLI';
    } else if (activeHandler === 'copilot') {
      const copilotBin = findCli('copilot') || findCli('gh');
      if (!copilotBin) return { executed: false, reason: 'Binary copilot/gh tidak ditemukan' };
      term.sendText(`gh copilot explain "${(instruction || '').replace(/"/g, '\"')}"`);
      launchedAgentName = 'GitHub Copilot CLI';
    }

    if (launchedAgentName) {
      if (taskKey && taskKey !== 'inst::') recentlyDispatchedTasks.set(taskKey, nowTime);
      markTaskActuallyOnProcess(taskId, instruction, targetFolder, activeHandler, launchedAgentName);
      vscode.window.showInformationMessage(`🚀 ${launchedAgentName} sedang memproses (ON PROCESS) tugas ${taskId || ''}...`);
      return { executed: true, handler: activeHandler, agentName: launchedAgentName };
    }

    isDispatchingAgentMutex = false;
    return { executed: false, reason: `Handler ${activeHandler} tidak didukung atau tidak terpasang` };
  }

  // ===========================================================================
  // FITUR /RESTART TASK (Requirement 5: Langkah a & b)
  // ===========================================================================

  // Helper: Persingkat Nama Task (Ambil 4 Karakter Terakhir)
  function shortTaskId(taskId) {
    if (!taskId) return '';
    const s = String(taskId).trim();
    const m = s.match(/([a-zA-Z0-9]{4})$/);
    if (m) {
      return '#' + m[1].toLowerCase();
    }
    return s;
  }

  // Helper: Pencocokan Task ID fleksibel (cocok persis atau 4 karakter terakhir)
  function isSameTaskId(a, b) {
    if (!a || !b) return false;
    const ca = String(a).toLowerCase().replace(/^#/, '').trim();
    const cb = String(b).toLowerCase().replace(/^#/, '').trim();
    if (ca === cb) return true;
    if (ca.length === 4 && cb.endsWith(ca)) return true;
    if (cb.length === 4 && ca.endsWith(cb)) return true;
    return false;
  }

  async function restartTargetTask(targetTaskId = '', agentOverride = null, isForce = false, fromTelegram = false) {
    const cur = getEffectiveConfig();
    const targetFolder = cur.folder || process.cwd();
    const queueFile = path.join(targetFolder, 'TASK_QUEUE.md');

    if (!fs.existsSync(queueFile)) {
      await pullTaskQueue(true);
    }
    if (!fs.existsSync(queueFile)) {
      vscode.window.showWarningMessage('⚠️ Belum ada file TASK_QUEUE.md di working directory aktif.');
      return { restarted: false, reason: 'TASK_QUEUE.md tidak ditemukan' };
    }

    const qContent = fs.readFileSync(queueFile, 'utf-8');
    const activeSection = qContent.split('## ✅ Riwayat Tugas Selesai')[0];
    const rxAll = /###\s+\[(PENDING|WAITING|QUEUE|IN_PROGRESS|ON_PROCESS|ON PROCESS|FAILED|KENDALA)\]\s+(#?TASK-[A-Za-z0-9_-]+)[\r\n]+([\s\S]*?)(?=(?:###\s+\[|##|$))/gi;
    const foundTasks = [];
    let m;
    while ((m = rxAll.exec(activeSection)) !== null) {
      const id = m[2].startsWith('#') ? m[2] : `#${m[2]}`;
      const body = m[3];
      const mInst = body.match(/-\s+\*\*Instruksi:\*\*\s*([^\r\n]+)/i);
      const mAgent = body.match(/-\s+\*\*Agent:\*\*\s*([^\r\n]+)/i);
      foundTasks.push({
        id,
        status: m[1].toUpperCase(),
        instruction: mInst ? mInst[1].trim() : '',
        agent: mAgent ? mAgent[1].trim() : ''
      });
    }

    if (foundTasks.length === 0) {
      vscode.window.showInformationMessage('ℹ️ Tidak ada tugas aktif di TASK_QUEUE.md untuk di-restart.');
      return { restarted: false, reason: 'Tidak ada tugas aktif' };
    }

    let selectedTask = null;
    if (targetTaskId) {
      const cleanTarget = targetTaskId.toLowerCase().replace(/^#/, '').trim();
      selectedTask = foundTasks.find(t => isSameTaskId(t.id, cleanTarget));
    } else if (foundTasks.length === 1 || fromTelegram) {
      selectedTask = foundTasks[0];
    } else {
      const pickItems = foundTasks.map(t => {
        const proc = checkTaskActiveHandlerStatus(t.id, targetFolder);
        return {
          label: `${proc.isTrulyProcessing ? '$(sync~spin) [ON PROCESS]' : '$(clock) [' + t.status + ']'} ${t.id}`,
          description: proc.isTrulyProcessing ? `Sedang di-handle oleh: ${proc.agentName}` : 'Tidak ada agent yang sedang menghandle',
          detail: t.instruction,
          taskObj: t
        };
      });
      const picked = await vscode.window.showQuickPick(pickItems, {
        title: '🔄 Pilih Tugas yang Akan Di-Restart (/restart)',
        placeHolder: 'Pilih tugas dari TASK_QUEUE.md:'
      });
      if (!picked) return { restarted: false, reason: 'Dibatalkan oleh pengguna' };
      selectedTask = picked.taskObj;
    }

    if (!selectedTask) {
      vscode.window.showWarningMessage(`⚠️ Tugas ${targetTaskId} tidak ditemukan di daftar tugas aktif TASK_QUEUE.md.`);
      return { restarted: false, reason: 'Tugas tidak ditemukan' };
    }

    const activeStatus = checkTaskActiveHandlerStatus(selectedTask.id, targetFolder);
    let chosenAgentHandler = agentOverride || cur.agentHandler || 'auto';

    // LANGKAH A: Jika tugas BENAR-BENAR sedang diproses (ON PROCESS) oleh suatu Agent
    if (activeStatus.isTrulyProcessing && !isForce) {
      const elapsedSec = activeStatus.startedAt ? Math.max(1, Math.round((Date.now() - activeStatus.startedAt) / 1000)) : 0;
      const elapsedStr = elapsedSec > 60 ? `${Math.floor(elapsedSec / 60)}m ${elapsedSec % 60}s` : `${elapsedSec} detik`;
      const infoMsg = `⚠️ Tugas [${selectedTask.id}] saat ini BENAR-BENAR SEDANG DIPROSES (ON PROCESS) oleh "${activeStatus.agentName}" (berjalan ${elapsedStr}). Apakah Anda yakin ingin me-restart tugas ini?`;

      if (fromTelegram) {
        try {
          await sendTelegramMessage(cur.botToken, cur.chatId, `Konfirmasi Restart: ${selectedTask.id}`, 'INFO', [
            `Status: SEDANG DIPROSES (ON PROCESS) oleh ${activeStatus.agentName} (${elapsedStr})`,
            `Instruksi: ${selectedTask.instruction}`,
            `Info: Dialog konfirmasi restart telah ditampilkan di VS Code. Gunakan "/restart ${selectedTask.id} paksa" jika ingin paksa restart dari Telegram.`
          ], cur.project, cur.timezone);
        } catch (e) {}
      }

      const confirmChoice = await vscode.window.showWarningMessage(
        infoMsg,
        { modal: true },
        '🔄 Ya, Restart Sekarang',
        '🤖 Restart & Pilih Agent Lain'
      );

      if (!confirmChoice) {
        log('info', `[Restart] Restart tugas ${selectedTask.id} dibatalkan karena masih ON PROCESS oleh ${activeStatus.agentName}.`);
        return { restarted: false, reason: 'Dibatalkan saat konfirmasi ON PROCESS' };
      }

      if (confirmChoice === '🤖 Restart & Pilih Agent Lain') {
        const installedList = detectAvailableAgents().filter(a => a.installed);
        const agentPick = await vscode.window.showQuickPick(
          installedList.map(a => ({ label: a.name, description: a.isIde ? 'Agent IDE' : 'Agent CLI', detail: a.detail, id: a.id })),
          { title: `🤖 Pilih Agent untuk Restart Tugas ${selectedTask.id}`, placeHolder: 'Hanya menampilkan Agent yang tersedia di mesin/server ini:' }
        );
        if (!agentPick) return { restarted: false, reason: 'Pemilihan agent dibatalkan' };
        chosenAgentHandler = agentPick.id;
      }
    } else {
      // LANGKAH B: Jika TIDAK ADA yang handle (belum berjalan / yatim / stuck), langsung restart dengan Agent yang ditunjuk!
      log('info', `[Restart] Tugas ${selectedTask.id} tidak sedang di-handle oleh proses aktif manapun. Langsung me-restart dengan agent: ${chosenAgentHandler}`);
    }

    // Reset lock deduplikasi & jalankan ulang dengan force: true
    const normId = selectedTask.id.toLowerCase().replace(/^#/, '').trim();
    recentlyDispatchedTasks.delete(`id::${normId}`);
    activeTaskHandlers.delete(normId);

    const execRes = await executeTaskWithAgent(selectedTask.id, selectedTask.instruction, {
      force: true,
      agent: chosenAgentHandler !== 'auto' ? chosenAgentHandler : undefined
    });

    if (execRes && execRes.executed) {
      if (fromTelegram) {
        try {
          await sendTelegramMessage(cur.botToken, cur.chatId, `Tugas Di-Restart: ${selectedTask.id}`, 'PROSES', [
            `Perintah: ${selectedTask.instruction}`,
            `Agent Handler: ${execRes.agentName || execRes.handler}`,
            `Status: ON PROCESS (Berhasil di-restart)`
          ], cur.project, cur.timezone);
        } catch (e) {}
      }
      showTimedNotification(`🔄 Tugas [${selectedTask.id}] berhasil di-restart menggunakan ${execRes.agentName || execRes.handler}!`, 8);
      return { restarted: true, taskId: selectedTask.id, handler: execRes.handler, agentName: execRes.agentName };
    } else {
      vscode.window.showWarningMessage(`⚠️ Gagal me-restart tugas ${selectedTask.id}: ${execRes ? execRes.reason : 'Unknown error'}`);
      return { restarted: false, reason: execRes ? execRes.reason : 'Gagal eksekusi' };
    }
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

  // 1. Inisialisasi otomatis berkas tool ke .vscode/tele-vs-code HANYA jika sudah dikonfigurasi
  // (Jika user baru install dari 0, tunggu sampai memilih Working Directory di quickSetup agar tidak mengotori folder yang salah)
  if (curInitial.secretKey && curInitial.project) {
    ensureWorkspaceTools(context, curInitial.folder);
    cleanupUnusedTaskQueues(curInitial.folder, true);
  }

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
    if (cur.secretKey && cur.project) {
      ensureWorkspaceTools(context, cur.folder);
      cleanupUnusedTaskQueues(cur.folder, true);
    }
    updateStatusBar();
    taskProvider.refresh();
  }));
  context.subscriptions.push(vscode.workspace.onDidChangeConfiguration(e => {
    if (e.affectsConfiguration('telegramNotifier')) {
      const cur = getEffectiveConfig();
      if (cur.secretKey && cur.project) {
        ensureWorkspaceTools(context, cur.folder);
        cleanupUnusedTaskQueues(cur.folder, true);
      }
      updateStatusBar();
      taskProvider.refresh();
      if (cur.autoPull && !isWorkerActive && cur.secretKey && cur.project) {
        startAutoWorker();
      }
    }
  }));
  context.subscriptions.push(vscode.window.onDidChangeWindowState(state => {
    if (state.focused) {
      pullTaskQueue(true);
    }
  }));

  // Watcher TASK_QUEUE.md: deteksi tugas selesai (DONE) atau kendala (FAILED/KENDALA) secara live,
  // lalu otomatis RESUME worker untuk membaca tugas berikutnya!
  let lastKnownFinishedIds = new Set();
  function checkDoneTasksInQueue(targetFolder) {
    const qFile = path.join(targetFolder, 'TASK_QUEUE.md');
    if (!fs.existsSync(qFile)) return false;
    let anyFinishedNow = false;
    try {
      const qContent = fs.readFileSync(qFile, 'utf-8');
      const regex = /###\s+\[(DONE|FAILED|KENDALA)\]\s+(#?TASK-[A-Za-z0-9_-]+)[\r\n]+([\s\S]*?)(?=(?:###\s+\[|##|$))/gi;
      let m;
      while ((m = regex.exec(qContent)) !== null) {
        const finStat = m[1].toUpperCase();
        const dId = m[2].trim();
        const body = m[3] || '';
        const mInst = body.match(/-\s+\*\*Instruksi:\*\*\s*([^\r\n]*)/i);
        const dInst = mInst ? mInst[1].trim() : '';
        const normFinId = dId.toLowerCase().replace(/^#/, '').trim();

        const wasActive = activeTaskHandlers.has(normFinId);
        activeTaskHandlers.delete(normFinId);

        if ((lastKnownFinishedIds.size > 0 && !lastKnownFinishedIds.has(dId)) || wasActive) {
          anyFinishedNow = true;
          const instSnippet = dInst ? (dInst.length > 50 ? dInst.substring(0, 50) + '...' : dInst) : '';
          if (finStat === 'DONE') {
            showTimedNotification(`✅ Tugas [${dId}] Tuntas 100% (SELESAI)! "${instSnippet}" — Worker melanjutkan baca tugas berikutnya.`, 8);
          } else {
            showTimedNotification(`⚠️ Tugas [${dId}] Ditandai KENDALA (${finStat}). Worker melanjutkan baca tugas berikutnya.`, 8);
          }
        }
        lastKnownFinishedIds.add(dId);
      }

      // Jika semua tugas yang sedang di-handle sudah tuntas (sukses/kendala), RESUME worker otomatis!
      if (activeTaskHandlers.size === 0 && isWorkerPausedByActiveTask) {
        isWorkerPausedByActiveTask = false;
        isDispatchingAgentMutex = false;
        log('info', '▶️ [Worker Resumed] Tugas sebelumnya telah tuntas (SELESAI/KENDALA). Worker aktif kembali membaca tugas berikutnya...');
        setStatusBarState('idle');
        const curNow = getEffectiveConfig();
        if (curNow.autoPull) {
          startAutoWorker();
        }
        anyFinishedNow = true;
      }
    } catch (e) {}
    return anyFinishedNow;
  }
  cleanupUnusedTaskQueues(curInitial.folder, false);
  checkDoneTasksInQueue(curInitial.folder);

  try {
    const qWatcher = vscode.workspace.createFileSystemWatcher('**/TASK_QUEUE.md');
    const handleQueueFsEvent = async (uri) => {
      const curConfig = getEffectiveConfig();
      const expectedQueuePath = path.resolve(curConfig.folder, 'TASK_QUEUE.md');
      if (uri && uri.fsPath && path.resolve(uri.fsPath) !== expectedQueuePath) {
        // Hapus otomatis TASK_QUEUE.md liar di luar working directory aktif
        cleanupUnusedTaskQueues(curConfig.folder, false);
        return;
      }
      checkDoneTasksInQueue(curConfig.folder);
      taskProvider.refresh();
      if (curConfig.autoDispatchAgent && (curConfig.agentHandler || 'auto') !== 'none') {
        await processActiveLocalTasks(curConfig);
      }
    };
    qWatcher.onDidChange(handleQueueFsEvent);
    qWatcher.onDidCreate(handleQueueFsEvent);
    context.subscriptions.push(qWatcher);
  } catch (wErr) {}

// Autonomous Task Execution Engine for VS Code Extension
function tryExecuteDirectTask(instruction, targetDir, cur) {
  const inst = instruction.trim();

  // Security Guard: Cegah perintah berbahaya (reboot, shell, dll) jika bukan akun administrator terverifikasi
  if (isRestrictedDangerousCommand(inst)) {
    const userEmail = cur?.userEmail || getEnvVal('TELE_USER_EMAIL') || '';
    if (!isAuthorizedSecurityUser(userEmail)) {
      throw new Error('⛔ Akses Ditolak: Perintah sistem berbahaya (seperti reboot, shell, atau modifikasi destruktif) diblokir oleh Security Guard.');
    }
  }

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
  // 4. Eksekusi Perintah Terminal / Shell
  // Mendukung prefix: cmd:, run:, sh:, bash:, terminal:, exec:, powershell:, pwsh:
  // Atau perintah CLI umum: git, npm, pnpm, yarn, bun, node, python, py, composer, php, cargo, go, dotnet, docker, dir, ls, echo, type, cat, whoami, hostname, ipconfig, ifconfig, curl, wget, find, grep, ping, netstat
  const mShell = inst.match(/^(?:cmd|exec|run|sh|terminal|bash|powershell|pwsh)[:\s]+([\s\S]+)$/i) ||
                 inst.match(/^(git|npm|pnpm|yarn|bun|node|python|py|composer|php|cargo|go|dotnet|docker|dir|ls|echo|type|cat|whoami|hostname|ipconfig|ifconfig|curl|wget|find|grep|ping|netstat)\b([\s\S]*)$/i);
  if (mShell) {
    const cmdStr = mShell[2] !== undefined ? `${mShell[1]} ${mShell[2]}`.trim() : mShell[1].trim();
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

  // 5a. Restart Task Spesifik (/restart #TASK-xxx atau restart task)
  const mRestartTask = inst.match(/^\/?restart(?:\s+task)?(?:\s+(#?TASK-[A-Za-z0-9_-]+))?(?:\s+(?:dengan|via|@|--agent\s*)([a-zA-Z0-9_-]+))?(\s+--force|\s+paksa)?$/i);
  if (mRestartTask && !/vscode|vs\s*code|window/i.test(inst)) {
    const targetId = mRestartTask[1] || '';
    const targetAgt = mRestartTask[2] ? resolveAgentHandlerFromText(mRestartTask[2]) : null;
    const forceFlag = !!mRestartTask[3];
    setTimeout(() => {
      restartTargetTask(targetId, targetAgt, forceFlag, true);
    }, 300);
    return {
      executed: true,
      report: `Perintah /restart ${targetId || '(tugas aktif)'} sedang diproses oleh Tele-vs-Code.`,
      type: 'restart_task'
    };
  }

  // 5b. Restart / Reload VS Code Window
  const mRestart = inst.match(/^(?:restart|reload)\s+(?:vscode|vs\s*code|window|windows)$/i);
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
    const res = await safeFetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `notes=${encodeURIComponent(notes)}`
    });
    return await res.json().catch(() => null);
  } catch (e) {
    return null;
  }
}

async function markServerTaskFailed(cur, taskId, notes = '') {
  try {
    const url = `${cur.webhookUrl.replace(/\/?$/, '/')}?action=failed&secret=${cur.secretKey}&id=${encodeURIComponent(taskId)}`;
    const res = await safeFetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `notes=${encodeURIComponent(notes)}`
    });
    return await res.json().catch(() => null);
  } catch (e) {
    return null;
  }
}

function appendFailedToTaskQueueFile(queueFile, task, reason) {
  try {
    if (!fs.existsSync(queueFile)) return;
    let content = fs.readFileSync(queueFile, 'utf-8');
    const escapedId = (task.id || '').replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
    const regexActive = new RegExp('### \\[(PENDING|WAITING|QUEUE|IN_PROGRESS|FAILED|KENDALA)\\]\\s+' + escapedId + '[\\s\\S]*?(?=(###|##|$))', 'g');
    content = content.replace(regexActive, '');
    const now = new Date().toISOString().replace('T', ' ').substring(0, 19);
    const agentName = task.assigned_agent || task.agent || 'Antigravity AI Agent';
    const failedBlock = '### [FAILED] ' + task.id + '\n' +
      '- **Waktu:** ' + now + '\n' +
      '- **Pengirim:** ' + (task.sender || 'Telegram') + '\n' +
      '- **Agent:** ' + agentName + '\n' +
      '- **Instruksi:** ' + (task.instruction || '').replace(/\r?\n/g, ' ') + '\n' +
      '- **Status:** FAILED\n' +
      '- **Laporan Agent:** ' + (reason || 'Pengerjaan tugas mengalami kendala') + '\n\n';
    const doneMarker = '## ✅ Riwayat Tugas Selesai';
    if (content.includes(doneMarker)) {
      content = content.replace(doneMarker, doneMarker + '\n\n' + failedBlock);
    } else {
      content += '\n\n' + doneMarker + '\n\n' + failedBlock;
    }
    fs.writeFileSync(queueFile, content, 'utf-8');
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
    const tasks = [];
    while ((match = regex.exec(activeSection)) !== null) {
      const taskId = match[2].startsWith('#') ? match[2] : `#${match[2]}`;
      const block = match[3];
      const mStat = block.match(/-\s+\*\*Status:\*\*\s*([^\r\n]+)/i);
      const taskStat = mStat ? mStat[1].trim().toUpperCase() : (match[1] || '').toUpperCase();
      if (['FAILED', 'KENDALA', 'ERROR', 'GAGAL', 'DONE'].includes(taskStat)) {
        continue;
      }
      const mInst = block.match(/-\s+\*\*Instruksi:\*\*\s*([^\r\n]+)/i);
      const mSender = block.match(/-\s+\*\*Pengirim:\*\*\s*([^\r\n]+)/i);
      const mTime = block.match(/-\s+\*\*Waktu:\*\*\s*([^\r\n]+)/i);
      const mAgt = block.match(/-\s+\*\*Agent:\*\*\s*([^\r\n]+)/i);
      if (mInst) {
        tasks.push({
          id: taskId,
          status: taskStat,
          instruction: mInst[1].trim(),
          sender: mSender ? mSender[1].trim() : 'Telegram',
          agent: mAgt ? mAgt[1].trim() : '',
          created_at: mTime ? mTime[1].trim() : new Date().toISOString()
        });
      }
    }

    if (tasks.length === 0) return;

    // 1. Cek apakah ada tugas yang saat ini berstatus IN_PROGRESS
    const currentInProgress = tasks.find(t => t.status === 'IN_PROGRESS');
    if (currentInProgress) {
      // Direct task check: eksekusi gratis 0 token jika tugas berupa shell/file sederhana
      try {
        const execRes = tryExecuteDirectTask(currentInProgress.instruction, cur.folder, cur);
        if (execRes && execRes.executed) {
          const rep = execRes.report;
          const resDone = await markServerTaskDone(cur, currentInProgress.id, rep);
          appendDoneToTaskQueueFile(queueFile, currentInProgress, rep);
          if (!resDone || (!resDone.notified_by_server && !resDone.already_notified)) {
            try {
              await sendTelegramMessage(cur.botToken, cur.chatId, `Tugas Selesai: ${currentInProgress.id}`, 'SELESAI', [
                `Instruksi: ${currentInProgress.instruction.substring(0, 90)}`,
                `Hasil: ${rep}`,
                `Pengirim: ${currentInProgress.sender}`
              ], cur.project, cur.timezone);
            } catch (nErr) {}
          }
          showTimedNotification(`✅ Tugas [${currentInProgress.id}] berhasil dieksekusi: ${rep}`, 10);
          // Rekursif ke tugas berikutnya secara berantai sampai semua tugas tuntas
          return processActiveLocalTasks(cur);
        }
      } catch (execErr) {
        log('error', '❌ Gagal eksekusi tugas lokal [' + currentInProgress.id + ']: ' + execErr.message);
        await markServerTaskFailed(cur, currentInProgress.id, execErr.message);
        appendFailedToTaskQueueFile(queueFile, currentInProgress, execErr.message);
        return processActiveLocalTasks(cur);
      }

      // Jika tugas berasal dari "IDE Chat" (sedang dikerjakan langsung oleh Agent di IDE Chat), jangan kirim ulang prompt ke IDE Chat!
      const isFromIdeChat = /ide/i.test(currentInProgress.sender || '') || (currentInProgress.id && currentInProgress.id.includes('-IDE-'));
      if (!isFromIdeChat && cur.autoDispatchAgent && (cur.agentHandler || 'auto') !== 'none') {
        const normId = (currentInProgress.id || '').toLowerCase().replace(/^#/, '').trim();
        const taskKey = normId ? (`id::${normId}`) : (`inst::${(currentInProgress.instruction || '').toLowerCase().trim()}`);
        if (!recentlyDispatchedTasks.has(taskKey)) {
          log('info', `[Auto-Dispatch] Memicu tugas IN_PROGRESS ${currentInProgress.id} (1x) ke AI Agent Handler (${cur.agentHandler || 'auto'})...`);
          await executeTaskWithAgent(currentInProgress.id, currentInProgress.instruction, { agent: currentInProgress.agent });
        }
      }
      return; // Sesuai aturan sekuensial murni: tugas PENDING harus menunggu IN_PROGRESS selesai
    }

    // 2. Jika tidak ada tugas IN_PROGRESS, ambil tugas PENDING yang pertama
    const firstPending = tasks.find(t => t.status === 'PENDING');
    if (!firstPending) return;

    // Coba eksekusi direct task terlebih dahulu
    try {
      const execRes = tryExecuteDirectTask(firstPending.instruction, cur.folder, cur);
      if (execRes && execRes.executed) {
        const rep = execRes.report;
        const resDone = await markServerTaskDone(cur, firstPending.id, rep);
        appendDoneToTaskQueueFile(queueFile, firstPending, rep);
        if (!resDone || (!resDone.notified_by_server && !resDone.already_notified)) {
          try {
            await sendTelegramMessage(cur.botToken, cur.chatId, `Tugas Selesai: ${firstPending.id}`, 'SELESAI', [
              `Instruksi: ${firstPending.instruction.substring(0, 90)}`,
              `Hasil: ${rep}`,
              `Pengirim: ${firstPending.sender}`
            ], cur.project, cur.timezone);
          } catch (nErr) {}
        }
        showTimedNotification(`✅ Tugas [${firstPending.id}] berhasil dieksekusi: ${rep}`, 10);
        // Otomatis berlanjut ke tugas PENDING berikutnya!
        return processActiveLocalTasks(cur);
      }
    } catch (execErr) {
      log('error', '❌ Gagal eksekusi tugas lokal [' + firstPending.id + ']: ' + execErr.message);
      await markServerTaskFailed(cur, firstPending.id, execErr.message);
      appendFailedToTaskQueueFile(queueFile, firstPending, execErr.message);
      return processActiveLocalTasks(cur);
    }

    // Tugas coding/AI Agent: dispatch otomatis ke Agent Handler terpilih
    if (cur.autoDispatchAgent && (cur.agentHandler || 'auto') !== 'none') {
      log('info', `[Auto-Dispatch] Mengalihkan tugas pending ${firstPending.id} ke AI Agent Handler (${firstPending.agent || cur.agentHandler || 'auto'})...`);
      await executeTaskWithAgent(firstPending.id, firstPending.instruction, { agent: firstPending.agent });
    }
  } catch (e) {
    log('error', `Gagal processActiveLocalTasks: ${e.message}`);
  }
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
    if (silent && isWorkerPausedByActiveTask && activeTaskHandlers.size > 0) {
      // Worker sedang PAUSE karena ada tugas yang sedang ON PROCESS
      return;
    }
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
      if (data && data.user_email) {
        lastPulledUserEmail = data.user_email;
      }
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

          // Jika tugas di server berstatus FAILED / KENDALA, JANGAN masukkan ke Tugas Aktif
          if (taskItem.status === 'FAILED' || taskItem.status === 'KENDALA') {
            if (!content.includes(taskItem.id)) {
              appendFailedToTaskQueueFile(queueFile, taskItem, taskItem.notes || taskItem.result || 'Pengerjaan tugas mengalami kendala');
            }
            continue;
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
            fs.writeFileSync(queueFile, content, 'utf-8');
          }

          // Cegah notifikasi ganda ke Telegram:
          // 1. Tugas dari Telegram sudah dibalas langsung oleh Bot Webhook di Telegram ("📥 TUGAS DITERIMA DI ANTREAN!").
          // 2. Tugas IN_PROGRESS sudah ditangani saat markTaskOnProcess -> tele-task start -> server (1x).
          // 3. Hanya kirim notifikasi antrean jika tugas baru ini belum pernah dikirimkan notifikasinya.
          const isFromTelegram = taskItem.sender && taskItem.sender !== 'IDE Chat' && taskItem.sender !== 'VS Code' && taskItem.sender !== 'CLI Worker';
          if (isNewTask && taskItem.status !== 'DONE' && targetStatus !== 'IN_PROGRESS' && !isFromTelegram && !cur.autoExecuteSimpleTasks) {
            try {
              const notifyStatus = 'INFO';
              const notifyTitle = `Tugas Masuk Antrean: ${taskItem.id}`;
              const statusDesc = 'Masuk Antrean [PENDING] (Menunggu giliran eksekusi AI Agent)';
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
              const execRes = tryExecuteDirectTask(taskItem.instruction, cur.folder, cur);
              if (execRes && execRes.executed) {
                const rep = execRes.report;
                const resDone = await markServerTaskDone(cur, taskItem.id, rep);
                appendDoneToTaskQueueFile(queueFile, taskItem, rep);
                content = fs.readFileSync(queueFile, 'utf-8');

                if (!resDone || (!resDone.notified_by_server && !resDone.already_notified)) {
                  try {
                    await sendTelegramMessage(cur.botToken, cur.chatId, `Tugas Selesai: ${taskItem.id}`, 'SELESAI', [
                      `Instruksi: ${taskItem.instruction.substring(0, 90)}`,
                      `Hasil: ${rep}`,
                      `Pengirim: ${taskItem.sender}`
                    ], cur.project, cur.timezone);
                  } catch (nErr) {}
                }

                log('info', `[TUGAS SELESAI] ID: ${taskItem.id} tuntas dieksekusi: ${rep}`);
                showTimedNotification(`✅ Tugas [${taskItem.id}] berhasil dieksekusi: ${rep}`, 10);
                setStatusBarState('idle');
                continue;
              }
            } catch (execErr) {
              await markServerTaskFailed(cur, taskItem.id, execErr.message);
              appendFailedToTaskQueueFile(queueFile, taskItem, execErr.message);
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

          // Beri tahu user & auto-dispatch HANYA jika tugas benar-benar baru masuk (isNewTask)
          if (isNewTask && (!silent || cur.showToastOnNewTask)) {
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

                const currentHandler = (cur.agentHandler || 'auto').trim();
                const agentDisplayName = currentHandler === 'ide' ? 'Agent IDE' : (currentHandler === 'custom' ? 'Custom Agent' : currentHandler.toUpperCase());
                const runBtnLabel = currentHandler !== 'none' ? i18n('btnRunVia', { agent: agentDisplayName }) : i18n('btnSelectAgent');

                // Sesuai instruksi: Jika autoDispatchAgent aktif dan handler bukan 'none',
                // JANGAN munculkan notifikasi pop-up dialog yang menuntut klik manual,
                // tapi LANGSUNG eksekusi sesuai dengan agen yang dipilih
                if (cur.autoDispatchAgent && currentHandler !== 'none') {
                  // Biarkan processActiveLocalTasks mengeksekusi secara sekuensial (1 pintu, bebas duplikasi!)
                  await processActiveLocalTasks(cur);
                } else {
                  // HANYA tampilkan dialog pop-up jika autoDispatchAgent dinonaktifkan (mode manual) atau handler 'none'
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
                        executeTaskWithAgent(taskItem.id, taskItem.instruction, { agent: taskItem.assigned_agent || taskItem.agent });
                      }
                    } else if (act === 'Buka TASK_QUEUE') {
                      vscode.commands.executeCommand('telegramNotifier.openQueue');
                    }
                  });
                }
              }
            }
          }
        }

        // Autonomous Mode: Pastikan processActiveLocalTasks selalu dijalankan untuk mengeksekusi antrean di background (unattended)
        if (cur.autoDispatchAgent && (cur.agentHandler || 'auto') !== 'none') {
          await processActiveLocalTasks(cur);
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
      if (consecutivePullFailures === 0 && currentWorkerState !== 'warning' && !isWorkerPausedByActiveTask && activeTaskHandlers.size === 0) {
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
      // Belum terkonfigurasi lengkap (User Baru / Install dari 0):
      // Otomatis buka Setup Wizard Visual & tampilkan sambutan
      vscode.commands.executeCommand("telegramNotifier.openSetupWizard");
      vscode.window.showInformationMessage(
        "👋 Selamat datang di Tele-vs-Code! Gunakan Setup Wizard untuk menghubungkan Server Biru, memilih Proyek, mengatur Working Directory, dan memilih AI Agent.",
        "🪄 Buka Setup Wizard",
        "⚡ Quick Setup (Pop-up)",
        "Nanti Saja"
      ).then(act => {
        if (act === "🪄 Buka Setup Wizard") {
          vscode.commands.executeCommand("telegramNotifier.openSetupWizard");
        } else if (act === "⚡ Quick Setup (Pop-up)") {
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


  }, 1200);

  // 1. Menu Utama via Status Bar (Rapi, Tanpa Menu Ganda, Bilingual ID/EN)
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.showMenu', async () => {
    const cur = getEffectiveConfig();
    const isEn = cur.language === 'en';
    const resolved = await getResolvedProject();
    const isConfigValid = !!(cur.secretKey && cur.project && resolved.projectKey);

    const activeAgentDisplay = cur.agentHandler
      ? (cur.agentHandler === 'ide' ? 'IDE Chat' : cur.agentHandler.toUpperCase())
      : (isEn ? 'Disabled' : 'Nonaktif');

    let menuItems = [];
    if (!isConfigValid) {
      // Kondisi Belum Terkonfigurasi / Pertama Kali Install: Setup Wizard menjadi menu unggulan utama
      menuItems = [
        {
          label: isEn
            ? '$(wand) 🪄 Setup Wizard — Start Initial Setup (Recommended)'
            : '$(wand) 🪄 Setup Wizard — Mulai Instalasi Awal (Direkomendasikan)',
          description: isEn ? 'Featured Visual Setup' : 'Unggulan untuk Instalasi Pertama',
          detail: isEn
            ? 'Configure API Secret Key, Project, Working Directory, and AI Agent in one visual screen.'
            : 'Atur API Secret Key, Proyek, Working Directory, dan AI Agent dalam satu layar interaktif.',
          cmd: 'telegramNotifier.openSetupWizard'
        },
        {
          label: isEn
            ? `$(folder) 📂 Select Working Directory: ${path.basename(cur.folder)}`
            : `$(folder) 📂 Pilih Folder Kerja (Working Directory): ${path.basename(cur.folder)}`,
          description: cur.folder,
          detail: isEn
            ? 'Folder where TASK_QUEUE.md and AI Agent rules are managed.'
            : 'Folder kerja tempat TASK_QUEUE.md dan aturan AI Agent dikelola.',
          cmd: 'telegramNotifier.setFolder'
        },
        {
          label: isEn
            ? '$(globe) 🌍 Switch Language / Ganti Bahasa (EN / ID)'
            : '$(globe) 🌍 Ganti Bahasa / Switch Language (ID / EN)',
          description: isEn ? 'Current: English (EN)' : 'Saat ini: Bahasa Indonesia (ID)',
          cmd: 'telegramNotifier.switchLanguage'
        },
        {
          label: isEn
            ? '$(browser) 🌐 Open Web Dashboard (Register / Get API Key)'
            : '$(browser) 🌐 Buka Web Dashboard (Daftar / Ambil API Key)',
          description: 'https://biru.co.id/tele-vs-code/',
          cmd: 'telegramNotifier.openDashboard'
        }
      ];
    } else {
      // Kondisi Sudah Terkonfigurasi: Menu Rapi, Tanpa Eksekusi Ganda, Bahasa Mudah Dipahami (ID / EN)
      menuItems = [
        // 1. Eksekusi Tugas Aktif Sekarang (Satu pintu eksekusi utama)
        {
          label: isEn
            ? '$(play-circle) ▶️ Run Next Task Now'
            : '$(play-circle) ▶️ Jalankan Tugas Berikutnya Sekarang',
          description: isEn
            ? `Execute via ${activeAgentDisplay}`
            : `Eksekusi langsung dengan ${activeAgentDisplay}`,
          detail: isEn
            ? 'Run the active [IN_PROGRESS] or [PENDING] task in TASK_QUEUE.md using your selected AI Agent.'
            : 'Jalankan tugas aktif [IN_PROGRESS] atau [PENDING] di TASK_QUEUE.md menggunakan AI Agent terpilih.',
          cmd: 'telegramNotifier.runNextTask'
        },
        // 2. Kelola Antrean Tugas (TASK_QUEUE.md)
        {
          label: isEn
            ? '$(checklist) 📋 Manage Task Queue (TASK_QUEUE.md)'
            : '$(checklist) 📋 Kelola Antrean Tugas (TASK_QUEUE.md)',
          description: isEn
            ? 'Restart task, add task, open queue, or clean unused queues'
            : 'Restart tugas, tambah tugas baru, buka antrean, atau bersihkan file liar',
          sub: 'tasks'
        },
        // 3. Pilih AI Agent Eksekutor
        {
          label: isEn
            ? '$(robot) 🤖 Select AI Agent Executor'
            : '$(robot) 🤖 Pilih AI Agent Eksekutor',
          description: isEn
            ? `Active: ${activeAgentDisplay} (Installed agents only)`
            : `Aktif: ${activeAgentDisplay} (Hanya menampilkan agent terpasang)`,
          cmd: 'telegramNotifier.selectAgentHandler'
        },
        // 4. Atur Folder Kerja (Working Directory)
        {
          label: isEn
            ? `$(folder) 📂 Working Directory: ${path.basename(cur.folder)}`
            : `$(folder) 📂 Folder Kerja (Working Directory): ${path.basename(cur.folder)}`,
          description: cur.folder,
          cmd: 'telegramNotifier.setFolder'
        },
        // 5. Kontrol Worker Otomatis & Polling
        {
          label: isEn
            ? '$(sync) ⚙️ Auto-Worker & Server Polling Control'
            : '$(sync) ⚙️ Kontrol Worker Otomatis & Durasi Cek Server',
          description: isWorkerActive
            ? (isEn ? `🟢 Active (every ${cur.pullInterval}s)` : `🟢 Aktif memantau (setiap ${cur.pullInterval} detik)`)
            : (isEn ? '⚪ Paused / Inactive' : '⚪ Dijeda / Nonaktif'),
          sub: 'worker'
        },
        // 6. Setup Wizard & Koneksi Proyek
        {
          label: isEn
            ? '$(wand) 🪄 Setup Wizard & Project Connection'
            : '$(wand) 🪄 Setup Wizard & Pengaturan Koneksi Proyek',
          description: `${resolved.name || cur.project} (${resolved.projectKey || cur.project})`,
          cmd: 'telegramNotifier.openSetupWizard'
        },
        // 7. Unggah File / Media ke Server
        {
          label: isEn
            ? '$(file-media) 📤 Upload File or Media to Server'
            : '$(file-media) 📤 Unggah File atau Media ke Server',
          description: isEn
            ? 'Send screenshots, images, or documents to Telegram & Server'
            : 'Kirim tangkapan layar, gambar, atau dokumen proyek ke Telegram & Server',
          cmd: 'telegramNotifier.uploadMedia'
        },
        // 8. Uji Koneksi & Diagnostik
        {
          label: isEn
            ? '$(beaker) 🧪 Connection Test & Diagnostics'
            : '$(beaker) 🧪 Uji Koneksi & Diagnostik',
          description: isEn
            ? 'Test Telegram notifications & run full self-test'
            : 'Uji kirim notifikasi Telegram & cek kesehatan koneksi',
          sub: 'test_menu'
        },
        // 9. Log Aktivitas
        {
          label: isEn
            ? '$(output) 📜 View Activity Log'
            : '$(output) 📜 Lihat Log Aktivitas',
          description: isEn
            ? 'Open real-time worker and agent execution log'
            : 'Buka catatan aktivitas worker dan eksekusi tugas secara real-time',
          cmd: 'telegramNotifier.showLog'
        },
        // 10. Buka Web Dashboard
        {
          label: isEn
            ? '$(browser) 🌐 Open Web Dashboard'
            : '$(browser) 🌐 Buka Web Dashboard',
          description: 'https://biru.co.id/tele-vs-code/',
          cmd: 'telegramNotifier.openDashboard'
        },
        // 11. Info Workspace (/whoami)
        {
          label: isEn
            ? '$(account) 👤 Workspace & Connection Info (/whoami)'
            : '$(account) 👤 Info Koneksi & Workspace (/whoami)',
          description: `${resolved.name || 'Project'} (#${resolved.code || resolved.projectKey || '-'})`,
          cmd: 'telegramNotifier.whoami'
        },
        // 12. Ganti Bahasa
        {
          label: isEn
            ? '$(globe) 🌍 Switch Language / Ganti Bahasa (EN / ID)'
            : '$(globe) 🌍 Ganti Bahasa / Switch Language (ID / EN)',
          description: isEn ? 'English (EN)' : 'Bahasa Indonesia (ID)',
          cmd: 'telegramNotifier.switchLanguage'
        }
      ];
    }

    const placeholderText = isConfigValid
      ? (isEn
          ? `Tele-vs-Code [${resolved.name} - ${resolved.projectKey}] — Select an action:`
          : `Tele-vs-Code [${resolved.name} - ${resolved.projectKey}] — Pilih aksi:`)
      : (isEn
          ? 'Tele-vs-Code (Not Connected Yet) — Start with Setup Wizard:'
          : 'Tele-vs-Code (Belum Terhubung) — Mulai dengan Setup Wizard:');

    const choice = await vscode.window.showQuickPick(menuItems, {
      title: isConfigValid
        ? (isEn ? `⚡ Tele-vs-Code Menu (${resolved.name})` : `⚡ Menu Utama Tele-vs-Code (${resolved.name})`)
        : (isEn ? '🪄 Tele-vs-Code Initial Setup' : '🪄 Setup Awal Tele-vs-Code'),
      placeHolder: placeholderText
    });

    if (!choice) return;

    // Sub-Menu: Kelola Antrean Tugas (Tasks & Jobs) — Tanpa duplikasi item
    if (choice.sub === 'tasks') {
      const taskSubItems = [
        {
          label: isEn ? '$(play-circle) ▶️ Run Next Task Now' : '$(play-circle) ▶️ Jalankan Tugas Berikutnya Sekarang',
          description: isEn ? 'Execute active task in TASK_QUEUE.md' : 'Eksekusi tugas aktif di TASK_QUEUE.md dengan AI Agent terpilih',
          cmd: 'telegramNotifier.runNextTask'
        },
        {
          label: isEn ? '$(debug-restart) 🔄 Restart Task (/restart)' : '$(debug-restart) 🔄 Restart Tugas (/restart)',
          description: isEn ? 'Check ON PROCESS status & restart task with selected Agent' : 'Cek status ON PROCESS & restart tugas dengan Agent terpilih',
          cmd: 'telegramNotifier.restartTask'
        },
        {
          label: isEn ? '$(add) ➕ Add New Task' : '$(add) ➕ Buat Tugas Baru',
          description: isEn ? 'Add a new task to TASK_QUEUE.md & notify Telegram' : 'Tambahkan tugas baru ke TASK_QUEUE.md & kirim notifikasi Telegram',
          cmd: 'telegramNotifier.addTask'
        },
        {
          label: isEn ? '$(file-text) 📖 Open TASK_QUEUE.md File' : '$(file-text) 📖 Buka File TASK_QUEUE.md',
          description: isEn ? 'View active tasks and completion history' : 'Lihat daftar tugas aktif dan riwayat tugas selesai',
          cmd: 'telegramNotifier.openQueue'
        },
        {
          label: isEn ? '$(trash) 🧹 Clean Up Unused TASK_QUEUE.md Files' : '$(trash) 🧹 Bersihkan TASK_QUEUE.md di Folder Lain yang Tidak Dipakai',
          description: isEn ? `Keep only TASK_QUEUE.md in ${cur.folder}` : `Pastikan hanya TASK_QUEUE.md di ${cur.folder} yang aktif`,
          cmd: 'telegramNotifier.cleanupUnusedTaskQueues'
        },
        {
          label: isEn ? '$(arrow-left) Back to Main Menu' : '$(arrow-left) Kembali ke Menu Utama',
          description: '',
          cmd: 'telegramNotifier.showMenu'
        }
      ];
      const subPick = await vscode.window.showQuickPick(taskSubItems, {
        title: isEn ? `📋 Manage Task Queue (${resolved.name})` : `📋 Kelola Antrean Tugas (${resolved.name})`,
        placeHolder: isEn ? 'Select a task action:' : 'Pilih aksi antrean tugas:'
      });
      if (subPick && subPick.cmd) {
        vscode.commands.executeCommand(subPick.cmd);
      }
      return;
    }

    // Sub-Menu: Kontrol Worker Otomatis & Polling
    if (choice.sub === 'worker') {
      const workerSubItems = [
        {
          label: isWorkerActive
            ? (isEn ? '$(debug-pause) ⏸️ Pause Auto-Worker' : '$(debug-pause) ⏸️ Jeda Worker Otomatis')
            : (isEn ? '$(play) ▶️ Start Auto-Worker' : '$(play) ▶️ Aktifkan Worker Otomatis'),
          description: isWorkerActive
            ? (isEn ? `Currently polling server every ${cur.pullInterval}s` : `Sedang aktif memantau server setiap ${cur.pullInterval} detik`)
            : (isEn ? 'Start monitoring incoming tasks from server automatically' : 'Mulai memantau tugas masuk dari server secara otomatis'),
          cmd: 'telegramNotifier.toggleAutoPull'
        },
        {
          label: isEn ? '$(cloud-download) ☁️ Pull Tasks from Server Now' : '$(cloud-download) ☁️ Tarik Antrean dari Server Sekarang',
          description: isEn ? 'Sync tasks from server to TASK_QUEUE.md immediately' : 'Sinkronisasi langsung tugas dari server ke TASK_QUEUE.md',
          cmd: 'telegramNotifier.pullTasks'
        },
        {
          label: isEn
            ? `$(watch) ⏱️ Set Server Polling Interval (Current: ${cur.pullInterval}s)`
            : `$(watch) ⏱️ Atur Jeda Cek Server (Saat ini: ${cur.pullInterval} detik)`,
          description: isEn ? 'Change how often the worker checks the server for new tasks' : 'Ubah seberapa sering worker memeriksa tugas baru di server',
          cmd: 'telegramNotifier.setPullInterval'
        },
        {
          label: isEn ? '$(terminal) 💻 Run Standalone Worker in Terminal' : '$(terminal) 💻 Jalankan Worker Mandiri di Terminal',
          description: isEn ? 'Run tele-worker in an integrated terminal session' : 'Jalankan proses tele-worker di terminal terpisah',
          cmd: 'telegramNotifier.startCliWorker'
        },
        {
          label: cur.debugMode
            ? (isEn ? '$(bug) 🐞 Debug Mode: ACTIVE' : '$(bug) 🐞 Mode Debug: AKTIF')
            : (isEn ? '$(bug) ⚪ Debug Mode: INACTIVE' : '$(bug) ⚪ Mode Debug: NONAKTIF'),
          description: cur.debugMode
            ? (isEn ? 'Detailed worker & agent execution logging is ON' : 'Pencatatan detail worker & eksekusi agen AKTIF')
            : (isEn ? 'Click to enable verbose debug logging' : 'Klik untuk mengaktifkan log debug detail'),
          cmd: 'telegramNotifier.toggleDebugMode'
        },
        {
          label: isEn ? '$(arrow-left) Back to Main Menu' : '$(arrow-left) Kembali ke Menu Utama',
          description: '',
          cmd: 'telegramNotifier.showMenu'
        }
      ];
      const subPick = await vscode.window.showQuickPick(workerSubItems, {
        title: isEn ? `⚙️ Auto-Worker & Polling Control (${resolved.name})` : `⚙️ Kontrol Worker Otomatis & Polling (${resolved.name})`,
        placeHolder: isEn
          ? `Worker Status: ${isWorkerActive ? `🟢 ACTIVE (${cur.pullInterval}s)` : '⚪ PAUSED'}`
          : `Status Worker: ${isWorkerActive ? `🟢 AKTIF (${cur.pullInterval} detik)` : '⚪ DIJEDA'}`
      });
      if (subPick && subPick.cmd) {
        vscode.commands.executeCommand(subPick.cmd);
      }
      return;
    }

    // Sub-Menu: Uji Koneksi & Diagnostik
    if (choice.sub === 'test_menu') {
      const testSubItems = [
        { label: t('testDiag'), description: t('testDiagDesc'), cmd: 'telegramNotifier.runSelfTest' },
        { label: t('testSuccess'), description: t('testSuccessDesc'), cmd: 'telegramNotifier.sendSuccess' },
        { label: t('testProgress'), description: t('testProgressDesc'), cmd: 'telegramNotifier.sendProgress' },
        { label: t('testError'), description: t('testErrorDesc'), cmd: 'telegramNotifier.sendError' },
        { label: t('testIde'), description: t('testIdeDesc'), cmd: 'telegramNotifier.testIdeTask' },
        {
          label: isEn ? '$(arrow-left) Back to Main Menu' : '$(arrow-left) Kembali ke Menu Utama',
          description: '',
          cmd: 'telegramNotifier.showMenu'
        }
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

  // =========================================================================
  // 2. Interactive WhoAmI Wizard Panel (/whoami) & Dukungan Donasi Pengembang
  // Didesain visual Studio Ink menyamai Setup Wizard, lengkap dengan tombol Donasi
  // =========================================================================
  let whoamiPanel = null;

  function getWhoamiHtml(data) {
    const isEn = data.lang === 'en';
    const workerStatusBadge = data.isWorkerActive
      ? `<span class="badge badge-success"><span class="status-dot dot-active"></span> ${isEn ? 'AUTOMATION WORKER: ACTIVE' : 'WORKER: MENYALA OTOMATIS'} (${data.pullInterval}s)</span>`
      : `<span class="badge badge-muted"><span class="status-dot dot-paused"></span> ${isEn ? 'WORKER: PAUSED / INACTIVE' : 'WORKER: DIJEDA / NONAKTIF'}</span>`;

    const autonomousBadge = `<span class="badge badge-signal">⚡ ${isEn ? 'UNATTENDED AUTONOMOUS MODE' : 'MODE OTONOM (100% OTOMATIS)'}</span>`;

    return `<!DOCTYPE html>
<html lang="${data.lang || 'id'}">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tele-vs-Code WhoAmI</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&family=Instrument+Serif:ital@0;1&display=swap');

    :root {
      --bg-base: #0d0c0a;
      --bg-surface: #14130f;
      --bg-surface-elevated: #1a1814;
      --bg-surface-inset: #0a0907;
      --text-primary: #ece4d6;
      --text-secondary: #a8a091;
      --text-muted: #777064;
      --border-subtle: rgba(236, 228, 214, 0.08);
      --border-medium: rgba(236, 228, 214, 0.14);
      --border-strong: rgba(236, 228, 214, 0.28);
      --signal: #ff5a1f;
      --accent-success: #9cc48a;
      --accent-info: #9dbbd0;
      --accent-rose: #f43f5e;
      --accent-gold: #e0ac4a;
      --font-display: 'Instrument Serif', Georgia, serif;
      --font-base: 'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      --font-mono: ui-monospace, 'SF Mono', Menlo, Consolas, monospace;
    }

    * { box-sizing: border-box; }
    body {
      font-family: var(--font-base);
      background: var(--bg-base);
      color: var(--text-primary);
      margin: 0;
      padding: 24px 28px 60px;
      line-height: 1.55;
      -webkit-font-smoothing: antialiased;
    }
    .container {
      max-width: 860px;
      margin: 0 auto;
    }
    .hero {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: linear-gradient(135deg, rgba(236,228,214,0.06), rgba(255,90,31,0.04));
      border: 1px solid var(--border-medium);
      border-radius: 14px;
      padding: 20px 24px;
      margin-bottom: 20px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.35);
    }
    .hero-title {
      font-family: var(--font-display);
      font-size: 26px;
      margin: 0 0 4px;
      display: flex;
      align-items: center;
      gap: 10px;
      color: var(--text-primary);
    }
    .hero-desc {
      margin: 0;
      color: var(--text-secondary);
      font-size: 13px;
    }
    .hero-badges {
      display: flex;
      flex-direction: column;
      gap: 6px;
      align-items: flex-end;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 3px 9px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 500;
      letter-spacing: 0.02em;
    }
    .badge-success {
      background: rgba(156, 196, 138, 0.12);
      color: #b4d8a5;
      border: 1px solid rgba(156, 196, 138, 0.3);
    }
    .badge-muted {
      background: rgba(236, 228, 214, 0.06);
      color: var(--text-secondary);
      border: 1px solid var(--border-subtle);
    }
    .badge-signal {
      background: rgba(255, 90, 31, 0.12);
      color: #ff8555;
      border: 1px solid rgba(255, 90, 31, 0.35);
    }
    .status-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
    }
    .dot-active {
      background: #4ade80;
      box-shadow: 0 0 8px #4ade80;
    }
    .dot-paused {
      background: #71717a;
    }

    .card {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: 14px;
      padding: 20px 22px;
      margin-bottom: 18px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.25);
    }
    .card-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 12px;
    }
    .card-title {
      font-size: 15px;
      font-weight: 600;
      margin: 0;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .card-subtitle {
      font-size: 12px;
      color: var(--text-secondary);
      margin: 0 0 14px;
      line-height: 1.45;
    }

    .grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    .info-tile {
      background: var(--bg-surface-elevated);
      border: 1px solid var(--border-subtle);
      border-radius: 10px;
      padding: 12px 14px;
      display: flex;
      flex-direction: column;
      gap: 4px;
      position: relative;
    }
    .info-label {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-muted);
      font-weight: 600;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .info-val {
      font-size: 13.5px;
      font-weight: 500;
      color: var(--text-primary);
      word-break: break-all;
    }
    .info-val.mono {
      font-family: var(--font-mono);
      font-size: 12px;
    }
    .info-action-btn {
      align-self: flex-start;
      margin-top: 6px;
      background: rgba(236,228,214,0.06);
      border: 1px solid var(--border-subtle);
      color: var(--text-secondary);
      font-size: 11px;
      padding: 2px 8px;
      border-radius: 6px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: all 0.15s ease;
    }
    .info-action-btn:hover {
      background: rgba(236,228,214,0.12);
      color: var(--text-primary);
      border-color: var(--border-medium);
    }

    .btn-row {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 10px;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 7px 14px;
      border-radius: 8px;
      font-size: 12.5px;
      font-weight: 500;
      cursor: pointer;
      border: 1px solid transparent;
      transition: all 0.18s ease;
      text-decoration: none;
    }
    .btn-primary {
      background: var(--signal);
      color: #fff;
      box-shadow: 0 3px 12px rgba(255,90,31,0.3);
    }
    .btn-primary:hover {
      background: #e04e17;
    }
    .btn-secondary {
      background: rgba(236,228,214,0.08);
      border-color: var(--border-subtle);
      color: var(--text-primary);
    }
    .btn-secondary:hover {
      background: rgba(236,228,214,0.14);
      border-color: var(--border-medium);
    }
    .btn-outline {
      background: transparent;
      border-color: var(--border-medium);
      color: var(--text-primary);
    }
    .btn-outline:hover {
      background: rgba(236,228,214,0.06);
    }

    /* CARD DONASI SPESIFIK */
    .card-donation {
      background: linear-gradient(135deg, rgba(244, 63, 94, 0.08), rgba(255, 90, 31, 0.04));
      border: 1px solid rgba(244, 63, 94, 0.28);
      box-shadow: 0 6px 24px rgba(244, 63, 94, 0.12);
    }
    .card-donation .card-title {
      color: #fda4af;
    }
    .quote-box {
      background: rgba(0, 0, 0, 0.35);
      border: 1px solid rgba(244, 63, 94, 0.2);
      border-left: 3px solid #f43f5e;
      border-radius: 8px;
      padding: 10px 14px;
      margin: 10px 0 14px;
      color: #fecdd3;
      font-size: 13px;
      line-height: 1.5;
    }
    .quote-title {
      font-size: 10.5px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #fda4af;
      margin-bottom: 2px;
      font-weight: 600;
    }
    .btn-donate {
      background: #e11d48;
      color: #fff;
      font-weight: 600;
      font-size: 13.5px;
      padding: 9px 18px;
      border-radius: 8px;
      border: 1px solid #be123c;
      box-shadow: 0 4px 16px rgba(225, 29, 72, 0.45);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 7px;
      text-decoration: none;
      transition: all 0.2s ease;
    }
    .btn-donate:hover {
      background: #f43f5e;
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(225, 29, 72, 0.6);
    }
    .btn-gold {
      background: rgba(224, 172, 74, 0.12);
      border: 1px solid rgba(224, 172, 74, 0.35);
      color: #f6d899;
      font-size: 12.5px;
      padding: 8px 14px;
      border-radius: 8px;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.18s ease;
    }
    .btn-gold:hover {
      background: rgba(224, 172, 74, 0.2);
      color: #fff;
    }

    @media (max-width: 640px) {
      .grid-2 { grid-template-columns: 1fr; }
      .hero { flex-direction: column; align-items: flex-start; gap: 10px; }
      .hero-badges { align-items: flex-start; }
    }
  </style>
</head>
<body>
  <div class="container">
    <!-- Hero Header -->
    <div class="hero">
      <div>
        <h1 class="hero-title">
          <span>Tele-vs-Code</span>
          <span style="font-family: var(--font-base); font-size: 13px; font-weight: normal; color: var(--text-muted);">v${data.version || '1.0.29'}</span>
        </h1>
        <p class="hero-desc">${isEn ? 'Workspace & Connection Identity, AI Routing & Autonomous Mode (/whoami)' : 'Identitas Workspace, Status Koneksi, Routing Agen & Mode Otonom (/whoami)'}</p>
      </div>
      <div class="hero-badges">
        ${workerStatusBadge}
        ${autonomousBadge}
      </div>
    </div>

    <!-- Section 1: Mode Otonom & Kontrol Worker -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">
          <span>⚙️</span>
          <span>${isEn ? 'Automation Worker & Unattended Autonomous Mode' : 'Worker Otomasi & Mode Eksekusi Otonom (Unattended)'}</span>
        </h2>
        <span class="badge ${data.isWorkerActive ? 'badge-success' : 'badge-muted'}">${data.isWorkerActive ? 'AKTIF' : 'NONAKTIF'}</span>
      </div>
      <p class="card-subtitle">
        ${isEn
          ? 'Tele-vs-Code operates in fully unattended mode: all commands from Telegram/Dashboard (shell commands, scripts, or AI Agent coding tasks) are automatically accepted and executed without requiring user presence at the computer.'
          : 'Tele-vs-Code beroperasi dalam mode otonom mandiri (unattended): semua perintah dari Telegram/Dashboard (perintah shell/terminal langsung maupun tugas coding AI Agent) otomatis diizinkan dan dieksekusi tanpa perlu kehadiran pengguna di depan PC.'}
      </p>
      <div class="btn-row">
        <button class="btn ${data.isWorkerActive ? 'btn-secondary' : 'btn-primary'}" onclick="sendAction('toggleWorker')">
          <span>${data.isWorkerActive ? '⏸️' : '▶️'}</span>
          <span>${data.isWorkerActive ? (isEn ? 'Pause Automation Worker' : 'Jeda Worker Otomatis') : (isEn ? 'Activate Automation Worker' : 'Aktifkan Worker Otomatis')}</span>
        </button>
        <button class="btn btn-secondary" onclick="sendAction('pullNow')">
          <span>⚡</span>
          <span>${isEn ? 'Pull Task Queue Now' : 'Tarik Antrean Sekarang'}</span>
        </button>
        <button class="btn btn-secondary" onclick="sendAction('setPullInterval')">
          <span>⏱️</span>
          <span>${isEn ? `Polling: ${data.pullInterval}s` : `Interval: ${data.pullInterval} detik`}</span>
        </button>
        <button class="btn btn-outline" onclick="sendAction('openQueue')">
          <span>📋</span>
          <span>${isEn ? 'Open TASK_QUEUE.md' : 'Buka TASK_QUEUE.md'}</span>
        </button>
      </div>
    </div>

    <!-- Section 2: Identitas Proyek & Workspace (Grid) -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">
          <span>📁</span>
          <span>${isEn ? 'Project & Workspace Routing Identity' : 'Identitas Proyek & Routing Workspace'}</span>
        </h2>
        <button class="btn btn-secondary" style="font-size: 11px; padding: 3px 10px;" onclick="sendAction('refresh')">
          <span>🔄</span>
          <span>${isEn ? 'Refresh' : 'Muat Ulang Data'}</span>
        </button>
      </div>
      <div class="grid-2">
        <div class="info-tile">
          <div class="info-label">
            <span>${isEn ? 'Active Project' : 'Proyek Aktif'}</span>
            <span style="color: var(--accent-info);">#${data.projectCode || data.projectKey || '-'}</span>
          </div>
          <div class="info-val">${data.projectName || '(Belum Dipilih)'}</div>
          <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">ID: ${data.projectId || '-'} | Key: ${data.projectKey || '-'}</div>
        </div>

        <div class="info-tile">
          <div class="info-label">
            <span>${isEn ? 'Working Directory' : 'Folder Workspace (Working Dir)'}</span>
            <span style="color: var(--accent-success);">LOKAL</span>
          </div>
          <div class="info-val mono" style="font-size: 11.5px;">${data.folder || '-'}</div>
          <button class="info-action-btn" onclick="sendAction('setFolder')">
            <span>📂</span>
            <span>${isEn ? 'Change Working Directory' : 'Ubah Folder Workspace'}</span>
          </button>
        </div>

        <div class="info-tile">
          <div class="info-label">
            <span>${isEn ? 'AI Agent Handler' : 'AI Agent Handler Terpilih'}</span>
            <span style="color: var(--signal);">${data.agentHandler || 'auto'}</span>
          </div>
          <div class="info-val">${data.agentDisplayName || 'Agent IDE / Auto'}</div>
          <button class="info-action-btn" onclick="sendAction('selectAgent')">
            <span>🤖</span>
            <span>${isEn ? 'Change AI Agent Handler' : 'Ganti AI Agent'}</span>
          </button>
        </div>

        <div class="info-tile">
          <div class="info-label">
            <span>${isEn ? 'Operator & Host' : 'Operator Host Mesin'}</span>
            <span>${data.platform || 'Host'}</span>
          </div>
          <div class="info-val mono">${data.operator || '-'}</div>
          <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">VS Code v${data.vscodeVersion || '-'}</div>
        </div>

        <div class="info-tile">
          <div class="info-label">
            <span>${isEn ? 'Webhook Server' : 'Server Webhook Biru'}</span>
            <span style="color: var(--accent-success);">CONNECTED</span>
          </div>
          <div class="info-val mono" style="font-size: 11px;">${data.webhookUrl || '-'}</div>
          <button class="info-action-btn" onclick="sendAction('openDashboard')">
            <span>🌐</span>
            <span>${isEn ? 'Open Web Dashboard' : 'Buka Dashboard Web'}</span>
          </button>
        </div>

        <div class="info-tile">
          <div class="info-label">
            <span>${isEn ? 'API Secret Key' : 'API Key Secret'}</span>
            <span>${data.secretKey ? 'SET' : 'EMPTY'}</span>
          </div>
          <div class="info-val mono">${data.apiKeyMasked || '(Belum Diatur)'}</div>
          <button class="info-action-btn" onclick="copySecret('${data.secretKey || ''}')">
            <span>📋</span>
            <span>${isEn ? 'Copy Secret Key' : 'Salin API Key'}</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Section 3: Dukungan & Donasi Pengembang (❤️ AYO DONASI) -->
    <div class="card card-donation">
      <div class="card-header">
        <h2 class="card-title">
          <span>❤️</span>
          <span>${isEn ? 'Support Tele-vs-Code: Donations & Reviews' : 'Dukung Pengembangan Tele-vs-Code: Donasi & Ulasan'}</span>
        </h2>
        <span class="badge" style="background: rgba(244,63,94,0.15); color: #fda4af; border: 1px solid rgba(244,63,94,0.3);">✨ OFFICIAL SUPPORT</span>
      </div>

      <div class="quote-box">
        <div class="quote-title">${isEn ? 'Donation Mission & Purpose' : 'Tujuan Donasi'}</div>
        <b>"bertahan hidup dengan teknologi dan #berkembangBaik"</b>
      </div>

      <p style="font-size: 13px; color: var(--text-secondary); margin: 0 0 14px; line-height: 1.55;">
        ${isEn
          ? 'Tele-vs-Code is built to free developers from the desk by enabling seamless remote autonomous task delegation. Your donations and 5-star Marketplace reviews directly sustain server costs, API maintenance, and active feature development.'
          : 'Tele-vs-Code dikembangkan untuk membebaskan developer dari keharusan selalu berada di depan komputer melalui delegasi tugas otonom jarak jauh. Donasi dan ulasan bintang 5 Anda sangat berarti untuk menopang biaya operasional server, kuota AI, serta riset fitur otomasi multi-agent selanjutnya.'}
      </p>

      <div class="btn-row" style="align-items: center;">
        <a href="https://ayo.ke/donasi" class="btn-donate" onclick="openExternalLink('https://ayo.ke/donasi', event)">
          <span>❤️</span>
          <span>${isEn ? 'Donate Now (ayo.ke/donasi)' : 'Ayo Donasi Sekarang'}</span>
        </a>
        <a href="https://marketplace.visualstudio.com/items?itemName=biruindonesiacreative.tele-vs-code&ssr=false#review-details" class="btn-gold" onclick="openExternalLink('https://marketplace.visualstudio.com/items?itemName=biruindonesiacreative.tele-vs-code&ssr=false#review-details', event)">
          <span>⭐</span>
          <span>${isEn ? 'Give 5-Star Review' : 'Beri Ulasan Bintang 5'}</span>
        </a>
        <a href="https://biru.co.id/tele-vs-code/" class="btn btn-outline" onclick="openExternalLink('https://biru.co.id/tele-vs-code/', event)">
          <span>🌐</span>
          <span>${isEn ? 'Web Dashboard' : 'Dashboard Web'}</span>
        </a>
      </div>
    </div>

    <!-- Section 4: Wizard & Navigasi -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
      <div style="font-size: 12px; color: var(--text-muted);">
        Tele-vs-Code &bull; Biru Indonesia Creative &bull; ${new Date().getFullYear()}
      </div>
      <div class="btn-row" style="margin-top: 0;">
        <button class="btn btn-secondary" onclick="sendAction('openWizard')">
          <span>🪄</span>
          <span>${isEn ? 'Open Setup Wizard' : 'Buka Setup Wizard'}</span>
        </button>
        <button class="btn btn-secondary" onclick="sendAction('openLog')">
          <span>📄</span>
          <span>${isEn ? 'Show Extension Log' : 'Buka Log Ekstensi'}</span>
        </button>
      </div>
    </div>
  </div>

  <script>
    const vscode = acquireVsCodeApi();

    function sendAction(type, payload = {}) {
      vscode.postMessage({ type, ...payload });
    }

    function openExternalLink(url, ev) {
      if (ev) ev.preventDefault();
      vscode.postMessage({ type: 'openUrl', url });
    }

    function copySecret(secret) {
      if (!secret) return;
      vscode.postMessage({ type: 'copyText', text: secret, label: 'API Key Secret' });
    }
  </script>
</body>
</html>`;
  }

  async function openWhoamiWebviewPanel() {
    const cur = getEffectiveConfig();
    const resolved = await getResolvedProject();
    const operator = `${os.userInfo().username}@${os.hostname()}`;
    const platform = `${process.platform} (${os.release()}, ${os.arch()})`;
    const vscodeVersion = vscode.version;

    if (whoamiPanel) {
      whoamiPanel.reveal(vscode.ViewColumn.One);
    } else {
      whoamiPanel = vscode.window.createWebviewPanel(
        'teleVsCodeWhoami',
        '👤 Identitas Workspace & Routing (/whoami)',
        vscode.ViewColumn.One,
        {
          enableScripts: true,
          retainContextWhenHidden: true
        }
      );
      whoamiPanel.onDidDispose(() => {
        whoamiPanel = null;
      });
    }

    const extPkgPath = path.join(__dirname, 'package.json');
    const extVersion = fs.existsSync(extPkgPath) ? (JSON.parse(fs.readFileSync(extPkgPath, 'utf-8')).version || '1.0.29') : '1.0.29';

    const renderWhoami = async () => {
      if (!whoamiPanel) return;
      const curNow = getEffectiveConfig();
      const resNow = await getResolvedProject();
      const agentsNow = detectAvailableAgents().filter(a => a.installed);
      const agtNow = curNow.agentHandler || 'auto';
      const agtObj = agentsNow.find(a => a.id === agtNow) || { name: agtNow === 'ide' ? 'Agent IDE (Chat AI)' : agtNow.toUpperCase() };
      const masked = curNow.secretKey
        ? (curNow.secretKey.length > 12 ? `${curNow.secretKey.substring(0, 8)}••••••••${curNow.secretKey.substring(curNow.secretKey.length - 4)}` : `${curNow.secretKey.substring(0, 4)}••••`)
        : '';

      whoamiPanel.webview.html = getWhoamiHtml({
        lang: curNow.language || 'id',
        version: extVersion,
        isWorkerActive,
        pullInterval: curNow.pullInterval,
        projectName: resNow.name,
        projectCode: resNow.code,
        projectKey: resNow.projectKey || curNow.project,
        projectId: resNow.id,
        folder: curNow.folder,
        agentHandler: agtNow,
        agentDisplayName: agtObj.name,
        operator,
        platform,
        vscodeVersion,
        webhookUrl: curNow.webhookUrl,
        secretKey: curNow.secretKey,
        apiKeyMasked: masked,
        botToken: resNow.botToken,
        chatId: resNow.chatId
      });
    };

    await renderWhoami();

    whoamiPanel.webview.onDidReceiveMessage(async (msg) => {
      if (msg.type === 'toggleWorker') {
        isWorkerActive = !isWorkerActive;
        updateStatusBar();
        await renderWhoami();
        vscode.window.showInformationMessage(isWorkerActive ? '🟢 Worker Otomatis diaktifkan' : '⚪ Worker Otomatis dijeda');
      } else if (msg.type === 'pullNow') {
        await pullTaskQueue(false);
        await renderWhoami();
      } else if (msg.type === 'setPullInterval') {
        vscode.commands.executeCommand('telegramNotifier.setPullInterval');
      } else if (msg.type === 'setFolder') {
        vscode.commands.executeCommand('telegramNotifier.setFolder');
      } else if (msg.type === 'selectAgent') {
        vscode.commands.executeCommand('telegramNotifier.selectAgentHandler');
      } else if (msg.type === 'openQueue') {
        vscode.commands.executeCommand('telegramNotifier.openQueue');
      } else if (msg.type === 'openWizard') {
        vscode.commands.executeCommand('telegramNotifier.openSetupWizard');
      } else if (msg.type === 'openDashboard') {
        vscode.commands.executeCommand('telegramNotifier.openDashboard');
      } else if (msg.type === 'openLog') {
        outputChannel.show();
      } else if (msg.type === 'refresh') {
        await renderWhoami();
        vscode.window.showInformationMessage('🔄 Data WhoAmI berhasil diperbarui');
      } else if (msg.type === 'openUrl' && msg.url) {
        vscode.env.openExternal(vscode.Uri.parse(msg.url));
      } else if (msg.type === 'copyText' && msg.text) {
        await vscode.env.clipboard.writeText(msg.text);
        vscode.window.showInformationMessage(`📋 ${msg.label || 'Teks'} berhasil disalin ke clipboard!`);
      }
    });
  }

  // 2. Command /whoami di VS Code: Menampilkan Identitas Workspace & Routing Webview Panel
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.whoami', async () => {
    await openWhoamiWebviewPanel();
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

  // Atur Interval Cron Agent (dengan Warning Penghematan Kuota Token)
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.setAgentCronInterval', async () => {
    const cur = getEffectiveConfig();
    const warnChoice = await vscode.window.showWarningMessage(
      '⚠️ Peringatan Kuota Token: Menjalankan polling berkala dengan AI Agent menguras jutaan token per hari karena memproses ulang seluruh prompt. Sangat disarankan mengandalkan Worker Ekstensi bawaan (0 token gratis). Lanjutkan?',
      'Lanjutkan Atur Interval',
      'Batal (Gunakan Worker 0 Token)'
    );
    if (warnChoice !== 'Lanjutkan Atur Interval') return;

    const input = await vscode.window.showInputBox({
      title: t('setCronIntervalTitle'),
      prompt: t('setCronIntervalPrompt'),
      value: String(cur.agentCronInterval || 3),
      validateInput: val => {
        const num = parseInt(val, 10);
        if (isNaN(num) || num < 1 || num > 60) {
          return t('setCronIntervalValidate');
        }
        return null;
      }
    });

    if (!input) return;
    const newInterval = parseInt(input, 10);
    const vsConfig = vscode.workspace.getConfiguration('telegramNotifier');
    await vsConfig.update('agentCronInterval', newInterval, vscode.ConfigurationTarget.Global);
    vscode.window.showInformationMessage(t('setCronIntervalToast').replace('{interval}', newInterval));
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
      const oldFolder = cur.folder;
      const vsConfig = vscode.workspace.getConfiguration('telegramNotifier');
      await vsConfig.update('folder', targetPath, vscode.ConfigurationTarget.Workspace);
      await vsConfig.update('folder', targetPath, vscode.ConfigurationTarget.Global);

      // 1. Hapus TASK_QUEUE.md di direktori lama / direktori lain yang tidak digunakan
      if (oldFolder && path.resolve(oldFolder) !== path.resolve(targetPath)) {
        const oldQ = path.join(oldFolder, 'TASK_QUEUE.md');
        if (fs.existsSync(oldQ)) {
          try { fs.unlinkSync(oldQ); } catch (e) {}
        }
      }
      cleanupUnusedTaskQueues(targetPath, true);

      // 2. Siapkan perangkat kerja di working directory baru & tarik pekerjaan aktif dari server
      ensureWorkspaceTools(context, targetPath);
      await pullTaskQueue(false);
      taskProvider.refresh();
      await updateStatusBar();

      vscode.window.showInformationMessage(
        `📂 Working Directory disetel ke: ${targetPath}\nTASK_QUEUE.md di direktori lama telah dihapus & antrean tugas dari server telah disinkronkan ke working directory baru.`,
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

      // SELAIN CARI DI LOKAL, CARI JUGA DI REMOTE SERVER
      if (!match && cur.secretKey) {
        log('info', 'Tugas tidak ditemukan di antrean lokal. Mencari juga di remote server...');
        await pullTaskQueue(true);
        if (fs.existsSync(queueFile)) {
          const freshContent = fs.readFileSync(queueFile, 'utf-8');
          const freshActive = freshContent.split('## ✅ Riwayat Tugas Selesai')[0];
          match = /###\s+\[IN_PROGRESS\]\s+(#?TASK-[A-Za-z0-9_-]+)[\r\n]+([\s\S]*?)(?=(?:###\s+\[|##|$))/i.exec(freshActive) ||
                  /###\s+\[(PENDING|WAITING|QUEUE)\]\s+(#?TASK-[A-Za-z0-9_-]+)[\r\n]+([\s\S]*?)(?=(?:###\s+\[|##|$))/i.exec(freshActive);
        }
      }

      if (!match) {
        vscode.window.showInformationMessage('ℹ️ Tidak ada tugas aktif [PENDING] atau [IN_PROGRESS] baik di lokal maupun di server remote.');
        return;
      }

      const taskId = match[2].startsWith('#') ? match[2] : `#${match[2]}`;
      const block = match[3];
      const mInst = block.match(/-\s+\*\*Instruksi:\*\*\s*([^\r\n]+)/i);
      const instruction = mInst ? mInst[1].trim() : '';

      // Langsung eksekusi menggunakan Agent Handler yang dipilih pengguna!
      const execRes = await executeTaskWithAgent(taskId, instruction, { force: true });
      if (!execRes || !execRes.executed) {
        const prompt = buildAgentPrompt(taskId, instruction);
        await vscode.env.clipboard.writeText(prompt);
        vscode.window.showInformationMessage(
          `📋 Prompt tugas [${taskId}] disalin ke clipboard. Pilih AI Agent Handler untuk eksekusi otomatis.`,
          'Pilih AI Agent',
          'Buka TASK_QUEUE.md'
        ).then(act => {
          if (act === 'Pilih AI Agent') vscode.commands.executeCommand('telegramNotifier.selectAgentHandler');
          else if (act === 'Buka TASK_QUEUE.md') vscode.commands.executeCommand('telegramNotifier.openQueue');
        });
      }
    } catch (e) {
      vscode.window.showErrorMessage(`❌ Gagal membaca antrean tugas: ${e.message}`);
    }
  }));

  // 2d. Command: Restart Tugas (/restart) dengan Pengecekan ON PROCESS (Langkah a & b)
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.restartTask', async (taskIdArg) => {
    const idStr = typeof taskIdArg === 'string' ? taskIdArg : (taskIdArg && taskIdArg.id ? taskIdArg.id : '');
    await restartTargetTask(idStr, null, false, false);
  }));

  // 2e. Command: Hapus TASK_QUEUE.md di Direktori Lain yang Tidak Digunakan
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.cleanupUnusedTaskQueues', async () => {
    const cur = getEffectiveConfig();
    const removed = cleanupUnusedTaskQueues(cur.folder, false);
    await pullTaskQueue(false);
    if (removed.length > 0) {
      vscode.window.showInformationMessage(`🧹 Berhasil menghapus ${removed.length} file TASK_QUEUE.md yang tidak digunakan:\n${removed.join('\n')}\n\nWorking Directory Aktif: ${cur.folder}`);
    } else {
      vscode.window.showInformationMessage(`✅ Bersih! Tidak ada file TASK_QUEUE.md liar di luar working directory aktif (${cur.folder}).`);
    }
  }));

  // 2f. Jalankan AI Agent Otomatis (Kompatibilitas perintah lama -> arahkan ke executeTaskWithAgent)
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.runAgentAgy', async (taskId, instruction) => {
    await executeTaskWithAgent(taskId, instruction, { force: true });
  }));

  // Command: Pilih AI Agent Handler (HANYA menampilkan Agent IDE & CLI yang BENAR-BENAR tersedia di mesin/server ini)
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.selectAgentHandler', async () => {
    const cur = getEffectiveConfig();
    const detectedAgents = detectAvailableAgents();
    const installedIdeAgents = detectedAgents.filter(a => a.installed && a.isIde);
    const installedCliAgents = detectedAgents.filter(a => a.installed && !a.isIde && a.cliPath);

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
      }
    ];

    if (installedIdeAgents.length > 0) {
      quickPickItems.push({ kind: vscode.QuickPickItemKind.Separator, label: '💬 AGENT IDE TERSEDIA DI MESIN / SERVER INI (Tanpa CLI)' });
      for (const a of installedIdeAgents) {
        quickPickItems.push({
          id: a.id,
          label: `$(comment-discussion) ${a.name}`,
          description: '✅ Tersedia di IDE',
          detail: a.detail
        });
      }
    }

    if (installedCliAgents.length > 0) {
      quickPickItems.push({ kind: vscode.QuickPickItemKind.Separator, label: '💻 AGENT CLI TERSEDIA DI MESIN / SERVER INI' });
      for (const a of installedCliAgents) {
        quickPickItems.push({
          id: a.id,
          label: `$(terminal) ${a.name}`,
          description: '✅ Binary CLI Terpasang',
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
    let taskMatch = qContent.match(/###\s*\[(PENDING|IN_PROGRESS)\]\s*(#TASK-[A-Za-z0-9_-]+)/i);
    let taskId = taskMatch ? taskMatch[2] : '';
    let instMatch = qContent.match(/-\s*\*\*Instruksi:\*\*\s*(.*)/i);
    let inst = instMatch ? instMatch[1].trim() : '';

    // SELAIN CARI DI LOKAL, CARI JUGA DI REMOTE SERVER
    if (!taskId && cur.secretKey) {
      log('info', 'Tugas tidak ditemukan di antrean lokal. Mencari juga di remote server...');
      await pullTaskQueue(true);
      if (fs.existsSync(qPath)) {
        const freshContent = fs.readFileSync(qPath, 'utf-8');
        taskMatch = freshContent.match(/###\s*\[(PENDING|IN_PROGRESS)\]\s*(#TASK-[A-Za-z0-9_-]+)/i);
        if (taskMatch) {
          taskId = taskMatch[2];
          instMatch = freshContent.match(/-\s*\*\*Instruksi:\*\*\s*(.*)/i);
          inst = instMatch ? instMatch[1].trim() : '';
        }
      }
    }

    if (!taskId) {
      vscode.window.showInformationMessage('ℹ️ Tidak ada tugas berstatus [PENDING] atau [IN_PROGRESS] baik di lokal maupun di remote server.');
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

  // 3a. Interactive Visual Setup Wizard (Webview Panel untuk User Baru / Install dari 0)
  let setupWizardPanel = null;
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.openSetupWizard', async () => {
    const cur = getEffectiveConfig();

    if (setupWizardPanel) {
      setupWizardPanel.reveal(vscode.ViewColumn.One);
    } else {
      setupWizardPanel = vscode.window.createWebviewPanel(
        'teleVsCodeSetupWizard',
        '🪄 Setup Wizard Tele-vs-Code (User Baru)',
        vscode.ViewColumn.One,
        {
          enableScripts: true,
          retainContextWhenHidden: true
        }
      );
      setupWizardPanel.onDidDispose(() => {
        setupWizardPanel = null;
      });
    }

    // Kumpulkan kandidat Working Directory (otomatis mendeteksi public_html, www, htdocs, dll.)
    const candidateFolders = [];
    const seenFolders = new Set();
    const addFolderCandidate = (fPath, title, badge, recommended = false) => {
      if (!fPath || !fs.existsSync(fPath)) return;
      const resolvedPath = path.resolve(fPath);
      if (seenFolders.has(resolvedPath)) return;
      seenFolders.add(resolvedPath);
      candidateFolders.push({
        path: resolvedPath,
        title,
        badge,
        recommended
      });
    };

    const subDirs = ['public_html', 'www', 'htdocs', 'web', 'app', 'src'];
    for (const sub of subDirs) {
      const subFull = path.join(cur.wsFolder, sub);
      if (fs.existsSync(subFull) && fs.statSync(subFull).isDirectory()) {
        addFolderCandidate(subFull, `Subfolder Proyek: ${sub}`, '⭐ Direkomendasikan', candidateFolders.length === 0);
      }
    }
    addFolderCandidate(cur.wsFolder, `Root Workspace Utama (${path.basename(cur.wsFolder)})`, '📁 Workspace', candidateFolders.length === 0);
    if (cur.folder && !seenFolders.has(path.resolve(cur.folder))) {
      addFolderCandidate(cur.folder, `Folder Aktif (${path.basename(cur.folder)})`, '📂 Aktif', false);
    }

    const defaultSelectedFolder = (cur.secretKey && cur.project && cur.folder)
      ? path.resolve(cur.folder)
      : (candidateFolders.find(f => f.recommended) ? candidateFolders.find(f => f.recommended).path : cur.wsFolder);

    // Kumpulkan AI Agent yang BENAR-BENAR tersedia di mesin/server ini saja
    const installedAgents = detectAvailableAgents().filter(a => a.installed);
    const agentOptions = installedAgents.map((a, idx) => ({
      id: a.id,
      name: a.name,
      isIde: Boolean(a.isIde),
      badge: a.isIde ? '💬 AGENT IDE (Tanpa CLI)' : '💻 AGENT CLI (Terpasang di Server)',
      detail: a.detail,
      recommended: a.id === 'ide-antigravity' || (idx === 0 && a.isIde)
    }));
    agentOptions.push({
      id: 'auto',
      name: 'Auto (Deteksi Otomatis)',
      isIde: true,
      badge: '✨ OTOMATIS',
      detail: 'Memilih otomatis Agent yang tersedia di mesin/server ini saat tugas masuk',
      recommended: false
    });

    const preferredAgent = (cur.secretKey && cur.project && cur.agentHandler)
      ? cur.agentHandler
      : (agentOptions.find(a => a.id === 'ide-antigravity') ? 'ide-antigravity' : (agentOptions[0] ? agentOptions[0].id : 'ide'));

    const initialData = {
      lang: cur.language || 'id',
      secretKey: cur.secretKey || '',
      projectKey: cur.project || '',
      webhookUrl: cur.webhookUrl || 'https://biru.co.id/tele-vs-code/webhook',
      folders: candidateFolders,
      selectedFolder: defaultSelectedFolder,
      agents: agentOptions,
      selectedAgent: preferredAgent
    };

    setupWizardPanel.webview.html = `<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Setup Wizard Tele-vs-Code</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&family=Instrument+Serif:ital@0;1&display=swap');

    :root {
      --bg-base: #0d0c0a;
      --bg-surface: #14130f;
      --bg-surface-elevated: #1a1814;
      --bg-surface-inset: #0a0907;
      --text-primary: #ece4d6;
      --text-secondary: #a8a091;
      --text-muted: #777064;
      --border-subtle: rgba(236, 228, 214, 0.08);
      --border-medium: rgba(236, 228, 214, 0.14);
      --border-strong: rgba(236, 228, 214, 0.28);
      --signal: #ff5a1f;
      --accent-success: #9cc48a;
      --accent-info: #9dbbd0;
      --accent-warning: #e0ac4a;
      --font-display: 'Instrument Serif', Georgia, serif;
      --font-base: 'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      --font-mono: ui-monospace, 'SF Mono', Menlo, Consolas, monospace;
    }

    * { box-sizing: border-box; }
    body {
      font-family: var(--font-base);
      background: var(--bg-base);
      color: var(--text-primary);
      margin: 0;
      padding: 28px 32px 70px;
      line-height: 1.55;
      -webkit-font-smoothing: antialiased;
    }
    .container {
      max-width: 880px;
      margin: 0 auto;
    }
    .hero {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: linear-gradient(135deg, rgba(236,228,214,0.06), rgba(255,90,31,0.04));
      border: 1px solid var(--border-medium);
      border-radius: 14px;
      padding: 22px 26px;
      margin-bottom: 24px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.35);
    }
    .hero h1 {
      margin: 0 0 6px;
      font-family: var(--font-display);
      font-size: 26px;
      font-weight: normal;
      letter-spacing: -0.01em;
      color: var(--text-primary);
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .hero p {
      margin: 0;
      color: var(--text-secondary);
      font-size: 13px;
    }
    .stepper {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 10px;
      margin-bottom: 24px;
    }
    .step-pill {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: 999px;
      padding: 8px 14px;
      font-size: 12px;
      font-weight: 500;
      color: var(--text-muted);
      display: flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s ease;
    }
    .step-pill.active {
      border-color: var(--signal);
      color: var(--text-primary);
      background: rgba(255, 90, 31, 0.08);
      box-shadow: 0 0 0 1px rgba(255, 90, 31, 0.25);
    }
    .step-pill.done {
      border-color: var(--border-strong);
      color: var(--text-primary);
      background: rgba(236, 228, 214, 0.06);
    }
    .step-num {
      width: 20px;
      height: 20px;
      border-radius: 50%;
      background: rgba(236, 228, 214, 0.1);
      color: var(--text-secondary);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      font-weight: 600;
      flex-shrink: 0;
    }
    .step-pill.active .step-num {
      background: var(--signal);
      color: #fff;
    }
    .step-pill.done .step-num {
      background: var(--text-primary);
      color: #0d0c0a;
    }

    .section-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: 14px;
      padding: 22px 24px;
      margin-bottom: 18px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.25);
      transition: border-color 0.2s ease;
    }
    .section-card:focus-within {
      border-color: var(--border-medium);
    }
    .section-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 8px;
    }
    .section-title {
      font-size: 15px;
      font-weight: 600;
      color: var(--text-primary);
      margin: 0;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .section-desc {
      font-size: 12.5px;
      color: var(--text-secondary);
      margin: 0 0 16px;
      line-height: 1.5;
    }
    .input-row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    input[type="text"], input[type="password"] {
      flex: 1;
      min-width: 240px;
      background: var(--bg-surface-inset);
      border: 1px solid var(--border-medium);
      color: var(--text-primary);
      padding: 11px 14px;
      border-radius: 10px;
      font-size: 13px;
      font-family: var(--font-mono);
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    input[type="text"]:focus, input[type="password"]:focus {
      outline: none;
      border-color: var(--signal);
      box-shadow: 0 0 0 2px rgba(255, 90, 31, 0.2);
    }
    input[type="text"]::placeholder {
      color: var(--text-muted);
    }
    button {
      cursor: pointer;
      border: none;
      border-radius: 999px;
      padding: 10px 16px;
      font-size: 12.5px;
      font-weight: 600;
      transition: all 0.18s ease;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-family: var(--font-base);
    }
    .btn-primary {
      background: var(--text-primary);
      color: #0d0c0a;
      box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    }
    .btn-primary:hover {
      background: #ffffff;
      transform: translateY(-1px);
    }
    .btn-secondary {
      background: var(--bg-surface-elevated);
      border: 1px solid var(--border-medium);
      color: var(--text-primary);
    }
    .btn-secondary:hover {
      background: rgba(236, 228, 214, 0.08);
      border-color: var(--border-strong);
    }
    .btn-success {
      background: var(--text-primary);
      color: #0d0c0a;
      font-size: 14px;
      font-weight: 700;
      padding: 14px 26px;
      width: 100%;
      justify-content: center;
      border-radius: 999px;
      box-shadow: 0 6px 20px rgba(0,0,0,0.4);
      margin-top: 10px;
    }
    .btn-success:hover {
      background: #ffffff;
      transform: translateY(-1px);
    }
    .btn-success:disabled {
      opacity: 0.4;
      cursor: not-allowed;
      transform: none;
    }

    .grid-cards {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 12px;
    }
    .choice-card {
      background: var(--bg-surface-inset);
      border: 1.5px solid var(--border-subtle);
      border-radius: 12px;
      padding: 14px 16px;
      cursor: pointer;
      transition: all 0.18s ease;
      position: relative;
    }
    .choice-card:hover {
      border-color: var(--border-strong);
      background: var(--bg-surface-elevated);
      transform: translateY(-1px);
    }
    .choice-card.selected {
      border-color: var(--text-primary);
      background: rgba(236, 228, 214, 0.06);
      box-shadow: 0 0 0 1px var(--text-primary);
    }
    .choice-title {
      font-weight: 600;
      font-size: 13.5px;
      color: var(--text-primary);
      margin-bottom: 4px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 6px;
    }
    .choice-sub {
      font-size: 12px;
      color: var(--text-secondary);
      word-break: break-all;
      font-family: var(--font-mono);
    }
    .badge {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 10.5px;
      font-weight: 600;
      background: rgba(236, 228, 214, 0.08);
      color: var(--text-secondary);
      border: 1px solid var(--border-subtle);
    }
    .badge.rec {
      background: rgba(255, 90, 31, 0.12);
      color: var(--signal);
      border-color: rgba(255, 90, 31, 0.3);
    }
    .status-box {
      margin-top: 12px;
      padding: 12px 14px;
      border-radius: 10px;
      font-size: 12.5px;
      display: none;
      line-height: 1.5;
    }
    .status-box.ok {
      display: block;
      background: rgba(156, 196, 138, 0.12);
      border: 1px solid rgba(156, 196, 138, 0.35);
      color: var(--accent-success);
    }
    .status-box.err {
      display: block;
      background: rgba(255, 90, 31, 0.12);
      border: 1px solid rgba(255, 90, 31, 0.4);
      color: #ff8555;
    }
    .status-box.info {
      display: block;
      background: rgba(157, 187, 208, 0.12);
      border: 1px solid rgba(157, 187, 208, 0.35);
      color: var(--accent-info);
    }
    .checkbox-row {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      margin-top: 14px;
      padding: 12px 14px;
      background: var(--bg-surface-inset);
      border: 1px solid var(--border-subtle);
      border-radius: 10px;
      font-size: 12.5px;
      color: var(--text-secondary);
      cursor: pointer;
      line-height: 1.55;
    }
    .checkbox-row input {
      margin-top: 3px;
      accent-color: var(--signal);
      transform: scale(1.15);
    }
    .checkbox-row b {
      color: var(--text-primary);
    }
    .checkbox-row code {
      color: var(--text-primary);
      background: rgba(236,228,214,0.06);
      padding: 1px 4px;
      border-radius: 4px;
      font-family: var(--font-mono);
      font-size: 11.5px;
    }
    .success-modal {
      display: none;
      background: var(--bg-surface);
      border: 1px solid var(--border-strong);
      border-radius: 16px;
      padding: 28px;
      margin-top: 20px;
      text-align: center;
      box-shadow: 0 16px 48px rgba(0,0,0,0.5);
    }
    .success-modal h2 {
      margin: 0 0 8px;
      font-family: var(--font-display);
      font-size: 28px;
      font-weight: normal;
      color: var(--text-primary);
    }
    .summary-table {
      text-align: left;
      background: var(--bg-surface-inset);
      border: 1px solid var(--border-subtle);
      border-radius: 12px;
      padding: 14px 18px;
      margin: 16px 0 20px;
      font-size: 13px;
      color: var(--text-secondary);
    }
    .summary-table div {
      padding: 6px 0;
      border-bottom: 1px solid var(--border-subtle);
    }
    .summary-table div:last-child { border-bottom: none; }
    .summary-table b { color: var(--text-primary); }
  </style>
</head>
<body>
  <div class="container">
    <div class="hero">
      <div>
        <h1 id="wizTitle">🪄 Setup Wizard Tele-vs-Code</h1>
        <p id="wizSubtitle">Konfigurasi lengkap dari 0: API Secret Key, Proyek, Working Directory, dan AI Agent Eksekutor.</p>
      </div>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button class="btn-secondary" id="btnToggleLang">🌍 ID / EN</button>
        <button class="btn-secondary" id="btnOpenDashboard">🌐 Dashboard</button>
      </div>
    </div>

    <div class="stepper">
      <div class="step-pill active" id="stepPill1"><span class="step-num">1</span> <span id="stepLbl1">API Secret Key</span></div>
      <div class="step-pill" id="stepPill2"><span class="step-num">2</span> <span id="stepLbl2">Pilih Proyek</span></div>
      <div class="step-pill" id="stepPill3"><span class="step-num">3</span> <span id="stepLbl3">Working Directory</span></div>
      <div class="step-pill" id="stepPill4"><span class="step-num">4</span> <span id="stepLbl4">AI Agent & Worker</span></div>
    </div>

    <!-- LANGKAH 1 -->
    <div class="section-card">
      <div class="section-header">
        <h3 class="section-title" id="sec1Title">🔑 Langkah 1/4: Masukkan API Secret Key (AGENTS_TELE_SECRET)</h3>
        <span class="badge" id="sec1Badge">Wajib</span>
      </div>
      <p class="section-desc" id="sec1Desc">Salin API Secret Key akun Anda dari dashboard <b>https://biru.co.id/tele-vs-code/</b> (Format: <code>tvsc_...</code>), lalu klik Verifikasi.</p>
      <div class="input-row">
        <input type="text" id="secretInput" placeholder="Format: tvsc_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" />
        <button class="btn-secondary" id="btnPasteSecret">📋 Tempel Clipboard</button>
        <button class="btn-primary" id="btnVerifySecret">🔍 Verifikasi & Ambil Proyek</button>
      </div>
      <div id="secretStatus" class="status-box"></div>
    </div>

    <!-- LANGKAH 2 -->
    <div class="section-card">
      <div class="section-header">
        <h3 class="section-title" id="sec2Title">📁 Langkah 2/4: Pilih Proyek (Project Key)</h3>
        <span class="badge" id="projectBadge">Menunggu Verifikasi Key</span>
      </div>
      <p class="section-desc" id="sec2Desc">Pilih proyek yang akan dihubungkan dengan workspace ini, atau ketik Project Key (<code>prj_...</code>) secara manual.</p>
      <div id="projectList" class="grid-cards"></div>
      <div class="input-row" style="margin-top: 10px;">
        <input type="text" id="projectKeyInput" placeholder="Project Key (Format: prj_xxxxxxxxxx)" />
      </div>
    </div>

    <!-- LANGKAH 3 -->
    <div class="section-card">
      <div class="section-header">
        <h3 class="section-title" id="sec3Title">📂 Langkah 3/4: Pilih Working Directory (Folder Kerja Proyek)</h3>
        <button class="btn-secondary" id="btnBrowseFolder">📂 Pilih Folder Lain... (Browse)</button>
      </div>
      <p class="section-desc" id="sec3Desc">Pilih direktori kerja tempat <code>TASK_QUEUE.md</code> diambil dari server dan dieksekusi oleh AI Agent.</p>
      <div id="folderList" class="grid-cards"></div>
      <div class="input-row" style="margin-top: 10px;">
        <input type="text" id="folderInput" readonly placeholder="Working Directory terpilih" />
      </div>
      <label class="checkbox-row">
        <input type="checkbox" id="chkCleanupQueues" checked />
        <span id="chkCleanupText"><b>Bersihkan otomatis <code>TASK_QUEUE.md</code> di direktori lain yang tidak digunakan</b> dan pastikan hanya <code>TASK_QUEUE.md</code> di Working Directory ini yang mengambil pekerjaan dari server.</span>
      </label>
    </div>

    <!-- LANGKAH 4 -->
    <div class="section-card">
      <div class="section-header">
        <h3 class="section-title" id="sec4Title">🤖 Langkah 4/4: Pilih AI Agent Eksekutor (Tersedia di Mesin/Server Ini)</h3>
        <span class="badge rec" id="sec4Badge">Hanya Agent Terpasang</span>
      </div>
      <p class="section-desc" id="sec4Desc">Daftar di bawah ini telah difilter agar hanya menampilkan AI Agent (IDE maupun CLI) yang <b>benar-benar tersedia di mesin/server ini</b>.</p>
      <div id="agentList" class="grid-cards"></div>
      <label class="checkbox-row">
        <input type="checkbox" id="chkAutoStartWorker" checked />
        <span id="chkAutoWorkerText"><b>Aktifkan Worker Otomasi Sekarang:</b> Tandai tugas <code>ON PROCESS</code> hanya sekali (tanpa duplikasi), otomatis <b>PAUSE Worker</b> selama tugas sedang dikerjakan, dan otomatis <b>RESUME Worker</b> setelah tugas ditandai <code>DONE</code> atau <code>FAILED</code>.</span>
      </label>
    </div>

    <!-- TOMBOL SIMPAN & AKTIFKAN -->
    <button class="btn-success" id="btnSaveSetup">🚀 Simpan Konfigurasi, Kirim Tes Telegram & Aktifkan Worker Sekarang</button>
    <div id="saveStatus" class="status-box"></div>

    <!-- MODAL SUKSES -->
    <div id="successModal" class="success-modal">
      <h2 id="succTitle">🎉 Setup Berhasil 100%!</h2>
      <p id="succDesc" style="color: var(--muted); margin: 0;">Workspace Anda telah terhubung ke Server Biru, notifikasi uji coba telah dikirim ke Telegram, dan Worker Otomasi sudah aktif!</p>
      <div class="summary-table" id="summaryContent"></div>
      <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
        <button class="btn-primary" id="btnOpenQueue">📖 Buka TASK_QUEUE.md</button>
        <button class="btn-secondary" id="btnPullNow">🔄 Tarik Antrean Server Sekarang</button>
        <button class="btn-secondary" id="btnCloseWizard">✅ Selesai & Tutup Wizard</button>
      </div>
    </div>
  </div>

  <script>
    const vscode = acquireVsCodeApi();
    const state = ${JSON.stringify(initialData)};
    let curLang = state.lang || 'id';
    let serverProjects = [];

    const WIZ_I18N = {
      id: {
        wizTitle: '🪄 Setup Wizard Tele-vs-Code',
        wizSubtitle: 'Konfigurasi lengkap dari 0: API Secret Key, Proyek, Working Directory, dan AI Agent Eksekutor.',
        btnLang: '🌍 Bahasa: ID (Klik ke EN)',
        btnDash: '🌐 Buka Dashboard',
        step1: 'API Secret Key',
        step2: 'Pilih Proyek',
        step3: 'Working Directory',
        step4: 'AI Agent & Worker',
        sec1Title: '🔑 Langkah 1/4: Masukkan API Secret Key (AGENTS_TELE_SECRET)',
        sec1Badge: 'Wajib',
        sec1Desc: 'Salin API Secret Key akun Anda dari dashboard <b>https://biru.co.id/tele-vs-code/</b> (Format: <code>tvsc_...</code>), lalu klik Verifikasi.',
        btnPaste: '📋 Tempel Clipboard',
        btnVerify: '🔍 Verifikasi & Ambil Proyek',
        sec2Title: '📁 Langkah 2/4: Pilih Proyek (Project Key)',
        projectWait: 'Menunggu Verifikasi Key',
        sec2Desc: 'Pilih proyek yang akan dihubungkan dengan workspace ini, atau ketik Project Key (<code>prj_...</code>) secara manual.',
        projectHint: 'Klik <b>"🔍 Verifikasi & Ambil Proyek"</b> pada Langkah 1 untuk menampilkan daftar proyek Anda dari Server Biru.',
        sec3Title: '📂 Langkah 3/4: Pilih Working Directory (Folder Kerja Proyek)',
        btnBrowse: '📂 Pilih Folder Lain... (Browse)',
        sec3Desc: 'Pilih direktori kerja tempat <code>TASK_QUEUE.md</code> diambil dari server dan dieksekusi oleh AI Agent.',
        chkCleanup: '<b>Bersihkan otomatis <code>TASK_QUEUE.md</code> di direktori lain yang tidak digunakan</b> dan pastikan hanya <code>TASK_QUEUE.md</code> di Working Directory ini yang mengambil pekerjaan dari server.',
        sec4Title: '🤖 Langkah 4/4: Pilih AI Agent Eksekutor (Tersedia di Mesin/Server Ini)',
        sec4Badge: 'Hanya Agent Terpasang',
        sec4Desc: 'Daftar di bawah ini telah difilter agar hanya menampilkan AI Agent (IDE maupun CLI) yang <b>benar-benar tersedia di mesin/server ini</b>.',
        chkWorker: '<b>Aktifkan Worker Otomasi Sekarang:</b> Tandai tugas <code>ON PROCESS</code> hanya sekali (tanpa duplikasi), otomatis <b>PAUSE Worker</b> selama tugas sedang dikerjakan, dan otomatis <b>RESUME Worker</b> setelah tugas ditandai <code>DONE</code> atau <code>FAILED</code>.',
        btnSave: '🚀 Simpan Konfigurasi, Kirim Tes Telegram & Aktifkan Worker Sekarang',
        succTitle: '🎉 Setup Berhasil 100%!',
        succDesc: 'Workspace Anda telah terhubung ke Server Biru, notifikasi uji coba telah dikirim ke Telegram, dan Worker Otomasi sudah aktif!',
        btnOpenQueue: '📖 Buka TASK_QUEUE.md',
        btnPullNow: '🔄 Tarik Antrean Server Sekarang',
        btnCloseWizard: '✅ Selesai & Tutup Wizard',
        recBadge: '⭐ Direkomendasikan'
      },
      en: {
        wizTitle: '🪄 Tele-vs-Code Setup Wizard',
        wizSubtitle: 'Complete initial setup: API Secret Key, Project, Working Directory, and AI Agent Executor.',
        btnLang: '🌍 Language: EN (Switch to ID)',
        btnDash: '🌐 Open Dashboard',
        step1: 'API Secret Key',
        step2: 'Select Project',
        step3: 'Working Directory',
        step4: 'AI Agent & Worker',
        sec1Title: '🔑 Step 1/4: Enter API Secret Key (AGENTS_TELE_SECRET)',
        sec1Badge: 'Required',
        sec1Desc: 'Copy your account API Secret Key from <b>https://biru.co.id/tele-vs-code/</b> (Format: <code>tvsc_...</code>), then click Verify.',
        btnPaste: '📋 Paste Clipboard',
        btnVerify: '🔍 Verify & Fetch Projects',
        sec2Title: '📁 Step 2/4: Select Project (Project Key)',
        projectWait: 'Waiting for Key Verification',
        sec2Desc: 'Select the project to connect with this workspace, or enter your Project Key (<code>prj_...</code>) manually.',
        projectHint: 'Click <b>"🔍 Verify & Fetch Projects"</b> in Step 1 to load your projects from Biru Server.',
        sec3Title: '📂 Step 3/4: Select Working Directory (Project Folder)',
        btnBrowse: '📂 Browse Other Folder...',
        sec3Desc: 'Select the working directory where <code>TASK_QUEUE.md</code> will be synced from the server and executed by the AI Agent.',
        chkCleanup: '<b>Automatically clean up unused <code>TASK_QUEUE.md</code> files in other directories</b> and ensure only <code>TASK_QUEUE.md</code> in this Working Directory pulls tasks from the server.',
        sec4Title: '🤖 Step 4/4: Select AI Agent Executor (Available on This Machine/Server)',
        sec4Badge: 'Installed Agents Only',
        sec4Desc: 'Filtered to show only AI Agents (IDE & CLI) that are <b>actually installed on this machine/server</b>.',
        chkWorker: '<b>Start Auto-Worker Now:</b> Mark tasks <code>ON PROCESS</code> once (no duplicates), automatically <b>PAUSE Worker</b> while a task is being processed, and automatically <b>RESUME Worker</b> when marked <code>DONE</code> or <code>FAILED</code>.',
        btnSave: '🚀 Save Configuration, Send Telegram Test & Start Worker Now',
        succTitle: '🎉 Setup 100% Complete!',
        succDesc: 'Your workspace is now connected to Biru Server, a test notification has been sent to Telegram, and the Auto-Worker is active!',
        btnOpenQueue: '📖 Open TASK_QUEUE.md',
        btnPullNow: '🔄 Pull Server Queue Now',
        btnCloseWizard: '✅ Finish & Close Wizard',
        recBadge: '⭐ Recommended'
      }
    };

    function applyLang() {
      const L = WIZ_I18N[curLang] || WIZ_I18N.id;
      document.getElementById('wizTitle').textContent = L.wizTitle;
      document.getElementById('wizSubtitle').textContent = L.wizSubtitle;
      document.getElementById('btnToggleLang').textContent = L.btnLang;
      document.getElementById('btnOpenDashboard').textContent = L.btnDash;
      document.getElementById('stepLbl1').textContent = L.step1;
      document.getElementById('stepLbl2').textContent = L.step2;
      document.getElementById('stepLbl3').textContent = L.step3;
      document.getElementById('stepLbl4').textContent = L.step4;
      document.getElementById('sec1Title').textContent = L.sec1Title;
      document.getElementById('sec1Badge').textContent = L.sec1Badge;
      document.getElementById('sec1Desc').innerHTML = L.sec1Desc;
      document.getElementById('btnPasteSecret').textContent = L.btnPaste;
      document.getElementById('btnVerifySecret').textContent = L.btnVerify;
      document.getElementById('sec2Title').textContent = L.sec2Title;
      if (!serverProjects || serverProjects.length === 0) {
        document.getElementById('projectBadge').textContent = L.projectWait;
      }
      document.getElementById('sec2Desc').innerHTML = L.sec2Desc;
      document.getElementById('sec3Title').textContent = L.sec3Title;
      document.getElementById('btnBrowseFolder').textContent = L.btnBrowse;
      document.getElementById('sec3Desc').innerHTML = L.sec3Desc;
      document.getElementById('chkCleanupText').innerHTML = L.chkCleanup;
      document.getElementById('sec4Title').textContent = L.sec4Title;
      document.getElementById('sec4Badge').textContent = L.sec4Badge;
      document.getElementById('sec4Desc').innerHTML = L.sec4Desc;
      document.getElementById('chkAutoWorkerText').innerHTML = L.chkWorker;
      document.getElementById('btnSaveSetup').textContent = L.btnSave;
      document.getElementById('succTitle').textContent = L.succTitle;
      document.getElementById('succDesc').textContent = L.succDesc;
      document.getElementById('btnOpenQueue').textContent = L.btnOpenQueue;
      document.getElementById('btnPullNow').textContent = L.btnPullNow;
      document.getElementById('btnCloseWizard').textContent = L.btnCloseWizard;
      renderProjects();
      renderFolders();
      renderAgents();
    }

    const secretInput = document.getElementById('secretInput');
    const projectKeyInput = document.getElementById('projectKeyInput');
    const folderInput = document.getElementById('folderInput');
    const secretStatus = document.getElementById('secretStatus');
    const saveStatus = document.getElementById('saveStatus');

    secretInput.value = state.secretKey || '';
    projectKeyInput.value = state.projectKey || '';
    folderInput.value = state.selectedFolder || '';

    function updateStepper() {
      const p1 = document.getElementById('stepPill1');
      const p2 = document.getElementById('stepPill2');
      const p3 = document.getElementById('stepPill3');
      const p4 = document.getElementById('stepPill4');
      p1.className = 'step-pill ' + (secretInput.value.trim() ? 'done' : 'active');
      p2.className = 'step-pill ' + (projectKeyInput.value.trim() ? 'done' : (secretInput.value.trim() ? 'active' : ''));
      p3.className = 'step-pill ' + (folderInput.value.trim() ? 'done' : '');
      p4.className = 'step-pill ' + (state.selectedAgent ? 'done' : '');
    }

    function renderProjects() {
      const container = document.getElementById('projectList');
      container.innerHTML = '';
      if (!serverProjects || serverProjects.length === 0) {
        container.innerHTML = '<div style="color: var(--muted); font-size: 12.5px;">Klik <b>"🔍 Verifikasi & Ambil Proyek"</b> pada Langkah 1 untuk menampilkan daftar proyek Anda dari Server Biru.</div>';
        return;
      }
      serverProjects.forEach(p => {
        const card = document.createElement('div');
        const isSel = (projectKeyInput.value.trim().toLowerCase() === (p.project_key || '').toLowerCase());
        card.className = 'choice-card' + (isSel ? ' selected' : '');
        card.innerHTML = \`
          <div class="choice-title">
            <span>📁 \${p.name || 'Proyek'}</span>
            <span class="badge rec">\${p.project_key}</span>
          </div>
          <div class="choice-sub">Tag: #\${p.code || 'a'} | Chat ID: \${p.chat_id || 'Default'}</div>
        \`;
        card.onclick = () => {
          projectKeyInput.value = p.project_key;
          renderProjects();
          updateStepper();
        };
        container.appendChild(card);
      });
    }

    function renderFolders() {
      const container = document.getElementById('folderList');
      container.innerHTML = '';
      state.folders.forEach(f => {
        const card = document.createElement('div');
        const isSel = (folderInput.value.trim() === f.path);
        card.className = 'choice-card' + (isSel ? ' selected' : '');
        card.innerHTML = \`
          <div class="choice-title">
            <span>\${f.title}</span>
            <span class="badge \${f.recommended ? 'rec' : ''}">\${f.badge}</span>
          </div>
          <div class="choice-sub">\${f.path}</div>
        \`;
        card.onclick = () => {
          state.selectedFolder = f.path;
          folderInput.value = f.path;
          renderFolders();
          updateStepper();
        };
        container.appendChild(card);
      });
    }

    function renderAgents() {
      const container = document.getElementById('agentList');
      container.innerHTML = '';
      state.agents.forEach(a => {
        const card = document.createElement('div');
        const isSel = (state.selectedAgent === a.id);
        card.className = 'choice-card' + (isSel ? ' selected' : '');
        card.innerHTML = \`
          <div class="choice-title">
            <span>\${a.name}</span>
            <span class="badge \${a.recommended ? 'rec' : ''}">\${a.recommended ? '⭐ Direkomendasikan' : a.badge}</span>
          </div>
          <div class="choice-sub">\${a.detail}</div>
        \`;
        card.onclick = () => {
          state.selectedAgent = a.id;
          renderAgents();
          updateStepper();
        };
        container.appendChild(card);
      });
    }

    document.getElementById('btnToggleLang').onclick = () => {
      curLang = curLang === 'id' ? 'en' : 'id';
      applyLang();
      vscode.postMessage({ type: 'setLanguage', lang: curLang });
    };
    document.getElementById('btnOpenDashboard').onclick = () => {
      vscode.postMessage({ type: 'openDashboard' });
    };
    document.getElementById('btnPasteSecret').onclick = () => {
      vscode.postMessage({ type: 'pasteClipboard' });
    };
    document.getElementById('btnVerifySecret').onclick = () => {
      const s = secretInput.value.trim();
      if (!s) {
        secretStatus.className = 'status-box err';
        secretStatus.textContent = '❌ Masukkan AGENTS_TELE_SECRET terlebih dahulu (Format: tvsc_...).';
        return;
      }
      secretStatus.className = 'status-box info';
      secretStatus.textContent = '⏳ Menghubungi Server Biru untuk memverifikasi API Secret Key...';
      vscode.postMessage({ type: 'verifySecret', secretKey: s });
    };
    document.getElementById('btnBrowseFolder').onclick = () => {
      vscode.postMessage({ type: 'browseFolder', currentFolder: folderInput.value.trim() });
    };
    document.getElementById('btnSaveSetup').onclick = () => {
      const secretKey = secretInput.value.trim();
      const projectKey = projectKeyInput.value.trim();
      const folder = folderInput.value.trim();
      const agentHandler = state.selectedAgent || 'ide';
      const cleanupQueues = document.getElementById('chkCleanupQueues').checked;
      const autoStartWorker = document.getElementById('chkAutoStartWorker').checked;

      if (!secretKey) {
        saveStatus.className = 'status-box err';
        saveStatus.textContent = '❌ Langkah 1 belum lengkap: API Secret Key (AGENTS_TELE_SECRET) wajib diisi.';
        return;
      }
      if (!projectKey) {
        saveStatus.className = 'status-box err';
        saveStatus.textContent = '❌ Langkah 2 belum lengkap: Silakan verifikasi Secret Key dan pilih Proyek (Project Key).';
        return;
      }
      if (!folder) {
        saveStatus.className = 'status-box err';
        saveStatus.textContent = '❌ Langkah 3 belum lengkap: Pilih Working Directory terlebih dahulu.';
        return;
      }

      document.getElementById('btnSaveSetup').disabled = true;
      saveStatus.className = 'status-box info';
      saveStatus.textContent = '⏳ Memvalidasi proyek, menyiapkan Working Directory, mengirim tes ke Telegram, dan mengaktifkan Worker...';

      vscode.postMessage({
        type: 'saveSetup',
        secretKey,
        projectKey,
        folder,
        agentHandler,
        cleanupQueues,
        autoStartWorker
      });
    };

    document.getElementById('btnOpenQueue').onclick = () => vscode.postMessage({ type: 'openQueue' });
    document.getElementById('btnPullNow').onclick = () => vscode.postMessage({ type: 'pullNow' });
    document.getElementById('btnCloseWizard').onclick = () => vscode.postMessage({ type: 'closeWizard' });

    window.addEventListener('message', event => {
      const msg = event.data;
      if (msg.type === 'clipboardText') {
        if (msg.text) {
          secretInput.value = msg.text.trim();
          updateStepper();
          document.getElementById('btnVerifySecret').click();
        }
      } else if (msg.type === 'verifyResult') {
        if (!msg.ok) {
          secretStatus.className = 'status-box err';
          secretStatus.textContent = '❌ ' + (msg.error || 'Gagal memverifikasi Secret Key.');
          return;
        }
        serverProjects = msg.projects || [];
        secretStatus.className = 'status-box ok';
        secretStatus.textContent = '✅ API Secret Key Valid! Ditemukan ' + serverProjects.length + ' proyek pada akun Anda.';
        document.getElementById('projectBadge').textContent = serverProjects.length + ' Proyek Tersedia';
        if (!projectKeyInput.value.trim() && serverProjects.length > 0) {
          projectKeyInput.value = serverProjects[0].project_key;
        }
        renderProjects();
        updateStepper();
      } else if (msg.type === 'folderPicked') {
        if (msg.folderPath) {
          const exists = state.folders.some(f => f.path === msg.folderPath);
          if (!exists) {
            state.folders.unshift({
              path: msg.folderPath,
              title: 'Folder Pilihan: ' + msg.folderName,
              badge: '📂 Dipilih',
              recommended: true
            });
          }
          state.selectedFolder = msg.folderPath;
          folderInput.value = msg.folderPath;
          renderFolders();
          updateStepper();
        }
      } else if (msg.type === 'saveResult') {
        document.getElementById('btnSaveSetup').disabled = false;
        if (!msg.ok) {
          saveStatus.className = 'status-box err';
          saveStatus.textContent = '❌ ' + (msg.error || 'Gagal menyimpan konfigurasi.');
          return;
        }
        saveStatus.className = 'status-box ok';
        saveStatus.textContent = '✅ Konfigurasi berhasil disimpan dan diverifikasi ke Telegram!';
        const s = msg.summary || {};
        document.getElementById('summaryContent').innerHTML = \`
          <div><b>📁 Proyek Terhubung:</b> \${s.projectName} (<code>\${s.projectKey}</code>)</div>
          <div><b>📂 Working Directory Aktif:</b> <code>\${s.folder}</code></div>
          <div><b>🤖 AI Agent Eksekutor:</b> \${s.agentName} (<code>\${s.agentHandler}</code>)</div>
          <div><b>🧹 Pembersihan TASK_QUEUE.md Liar:</b> \${s.cleanedCount > 0 ? s.cleanedCount + ' file di direktori lama dihapus' : 'Bersih (Hanya di Working Directory aktif)'}</div>
          <div><b>📡 Status Worker Otomasi:</b> \${s.workerActive ? '🟢 AKTIF (Auto-Pause saat ON PROCESS, Auto-Resume saat DONE/FAILED)' : '⚪ Dijeda'}</div>
        \`;
        document.getElementById('successModal').style.display = 'block';
        document.getElementById('successModal').scrollIntoView({ behavior: 'smooth' });
      }
    });

    applyLang();
    updateStepper();

    if (state.secretKey) {
      vscode.postMessage({ type: 'verifySecret', secretKey: state.secretKey });
    }
  </script>
</body>
</html>`;

    setupWizardPanel.webview.onDidReceiveMessage(async (msg) => {
      if (msg.type === 'setLanguage' && (msg.lang === 'id' || msg.lang === 'en')) {
        const vsConfig = vscode.workspace.getConfiguration('telegramNotifier');
        await vsConfig.update('language', msg.lang, vscode.ConfigurationTarget.Global);
        updateStatusBar();
        taskProvider.refresh();
      } else if (msg.type === 'openDashboard') {
        vscode.commands.executeCommand('telegramNotifier.openDashboard');
      } else if (msg.type === 'pasteClipboard') {
        const clip = await vscode.env.clipboard.readText();
        setupWizardPanel.webview.postMessage({ type: 'clipboardText', text: clip || '' });
      } else if (msg.type === 'verifySecret') {
        const cleanSecret = (msg.secretKey || '').trim();
        try {
          const curNow = getEffectiveConfig();
          const url = `${curNow.webhookUrl.replace(/\/?$/, '/')}?action=projects&secret=${encodeURIComponent(cleanSecret)}`;
          const res = await safeFetch(url, { timeout: 15000 }, 2);
          if (!res.ok) {
            setupWizardPanel.webview.postMessage({
              type: 'verifyResult',
              ok: false,
              error: `Server menolak permintaan (HTTP ${res.status}). Periksa kembali AGENTS_TELE_SECRET Anda.`
            });
            return;
          }
          const data = await res.json();
          if (!data.ok || !Array.isArray(data.projects) || data.projects.length === 0) {
            setupWizardPanel.webview.postMessage({
              type: 'verifyResult',
              ok: false,
              error: 'AGENTS_TELE_SECRET tidak valid atau akun Anda belum memiliki proyek di https://biru.co.id/tele-vs-code/.'
            });
            return;
          }
          setupWizardPanel.webview.postMessage({
            type: 'verifyResult',
            ok: true,
            projects: data.projects
          });
        } catch (err) {
          setupWizardPanel.webview.postMessage({
            type: 'verifyResult',
            ok: false,
            error: `Gagal menghubungi Server Biru: ${err.message}`
          });
        }
      } else if (msg.type === 'browseFolder') {
        const startUri = msg.currentFolder && fs.existsSync(msg.currentFolder)
          ? vscode.Uri.file(msg.currentFolder)
          : vscode.Uri.file(cur.wsFolder);
        const picked = await vscode.window.showOpenDialog({
          canSelectFiles: false,
          canSelectFolders: true,
          canSelectMany: false,
          openLabel: 'Pilih Sebagai Working Directory',
          title: 'Pilih Working Directory Proyek (Tempat TASK_QUEUE.md Dikelola)',
          defaultUri: startUri
        });
        if (picked && picked[0]) {
          setupWizardPanel.webview.postMessage({
            type: 'folderPicked',
            folderPath: picked[0].fsPath,
            folderName: path.basename(picked[0].fsPath)
          });
        }
      } else if (msg.type === 'saveSetup') {
        const cleanSecret = (msg.secretKey || '').trim();
        const cleanProjectKey = (msg.projectKey || '').trim();
        const selectedFolder = (msg.folder || cur.wsFolder).trim();
        const selectedAgentHandler = (msg.agentHandler || 'ide').trim();

        try {
          const curNow = getEffectiveConfig();
          const url = `${curNow.webhookUrl.replace(/\/?$/, '/')}?action=projects&secret=${encodeURIComponent(cleanSecret)}`;
          const res = await safeFetch(url, { timeout: 15000 }, 2);
          if (!res.ok) {
            setupWizardPanel.webview.postMessage({ type: 'saveResult', ok: false, error: `HTTP ${res.status}: Gagal memvalidasi API Secret Key.` });
            return;
          }
          const data = await res.json();
          const projects = (data && data.ok && Array.isArray(data.projects)) ? data.projects : [];
          const matchedProject = projects.find(p => p.project_key && p.project_key.toLowerCase() === cleanProjectKey.toLowerCase());
          if (!matchedProject) {
            setupWizardPanel.webview.postMessage({
              type: 'saveResult',
              ok: false,
              error: `Project Key "${cleanProjectKey}" tidak cocok dengan akun API Secret Key Anda.`
            });
            return;
          }

          // 1. Bersihkan TASK_QUEUE.md & file aturan liar di direktori lain yang tidak digunakan
          let cleanedList = [];
          if (msg.cleanupQueues !== false) {
            cleanedList = cleanupUnusedTaskQueues(selectedFolder, true);
          }

          // 2. Siapkan perangkat kerja (.vscode/tele-vs-code/*, TASK_QUEUE.md, AGENTS.md, dll.) HANYA di selectedFolder
          ensureWorkspaceTools(context, selectedFolder);

          // 3. Tulis .vscode/tele-vs-code/config.env di selectedFolder
          const targetConfig = path.join(selectedFolder, '.vscode', 'tele-vs-code', 'config.env');
          const targetDir = path.dirname(targetConfig);
          if (!fs.existsSync(targetDir)) {
            fs.mkdirSync(targetDir, { recursive: true });
          }

          const envContent = `# ==============================================================================
# Konfigurasi Tele-vs-Code (Otomatis dari Setup Wizard User Baru)
# Folder: ${selectedFolder}
# ==============================================================================
AGENTS_TELE_SECRET="${cleanSecret}"
AGENTS_TELE_PROJECT="${matchedProject.project_key}"
AGENTS_TELE_PROJECT_KEY="${matchedProject.project_key}"
AGENTS_TELE_WEBHOOK_URL="${curNow.webhookUrl}"
TELEGRAM_BOT_TOKEN="${matchedProject.bot_token || ''}"
TELEGRAM_CHAT_ID="${matchedProject.chat_id || ''}"
PROJECT_NAME="${matchedProject.name || ''}"
TIMEZONE="${matchedProject.timezone || 'Asia/Jakarta'}"
TELE_WORKING_DIR="${selectedFolder}"
TELE_AGENT_HANDLER="${selectedAgentHandler}"
`;
          fs.writeFileSync(targetConfig, envContent, 'utf-8');

          // 4. Simpan ke VS Code Settings (Workspace & Global)
          const vsConfig = vscode.workspace.getConfiguration('telegramNotifier');
          await vsConfig.update('folder', selectedFolder, vscode.ConfigurationTarget.Workspace);
          await vsConfig.update('folder', selectedFolder, vscode.ConfigurationTarget.Global);
          await vsConfig.update('secretKey', cleanSecret, vscode.ConfigurationTarget.Global);
          await vsConfig.update('project', matchedProject.project_key, vscode.ConfigurationTarget.Workspace);
          await vsConfig.update('project', matchedProject.project_key, vscode.ConfigurationTarget.Global);
          await vsConfig.update('agentHandler', selectedAgentHandler, vscode.ConfigurationTarget.Global);
          await vsConfig.update('autoDispatchAgent', true, vscode.ConfigurationTarget.Global);

          const matchedAgentObj = agentOptions.find(a => a.id === selectedAgentHandler);
          const agentLabel = matchedAgentObj ? matchedAgentObj.name : selectedAgentHandler;

          // 5. Kirim notifikasi verifikasi ke Telegram
          try {
            await sendTelegramMessage(
              matchedProject.bot_token,
              matchedProject.chat_id,
              'Verifikasi Setup Wizard (User Baru)',
              'INFO',
              [
                `AGENTS_TELE_SECRET & Project Key BERHASIL TERVERIFIKASI!`,
                `Working Directory: ${selectedFolder}`,
                `Proyek: ${matchedProject.name} (${matchedProject.project_key})`,
                `AI Agent Eksekutor: ${agentLabel}`
              ],
              matchedProject.name,
              matchedProject.timezone || 'Asia/Jakarta'
            );
          } catch (teleErr) {
            log('warn', `Peringatan kirim notifikasi tes Telegram: ${teleErr.message}`);
          }

          // 6. Tarik antrean dari server & aktifkan Worker Otomasi
          serverProjectCache = null;
          if (msg.autoStartWorker !== false) {
            await vsConfig.update('autoPull', true, vscode.ConfigurationTarget.Global);
            await vsConfig.update('askOnStartup', false, vscode.ConfigurationTarget.Global);
            startAutoWorker();
          } else {
            await pullTaskQueue(true);
          }
          taskProvider.refresh();
          updateStatusBarAgent();
          await updateStatusBar();

          setupWizardPanel.webview.postMessage({
            type: 'saveResult',
            ok: true,
            summary: {
              projectName: matchedProject.name,
              projectKey: matchedProject.project_key,
              folder: selectedFolder,
              agentHandler: selectedAgentHandler,
              agentName: agentLabel,
              cleanedCount: cleanedList.length,
              workerActive: msg.autoStartWorker !== false
            }
          });

          vscode.window.showInformationMessage(
            `🎉 Setup Selesai! Proyek "${matchedProject.name}" aktif di ${selectedFolder} dengan Agent "${agentLabel}".`
          );
        } catch (saveErr) {
          setupWizardPanel.webview.postMessage({
            type: 'saveResult',
            ok: false,
            error: `Gagal menyimpan konfigurasi: ${saveErr.message}`
          });
        }
      } else if (msg.type === 'openQueue') {
        vscode.commands.executeCommand('telegramNotifier.openQueue');
      } else if (msg.type === 'pullNow') {
        vscode.commands.executeCommand('telegramNotifier.pullTasks');
      } else if (msg.type === 'closeWizard') {
        if (setupWizardPanel) setupWizardPanel.dispose();
      }
    });
  }));

  // 3b. Quick Setup Wizard via InputBox/QuickPick: Input Secret -> Project Key -> Working Directory -> AI Agent Handler -> Test -> Otomasi
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.quickSetup', async () => {
    const cur = getEffectiveConfig();

    // Langkah 1/4: Masukkan AGENTS_TELE_SECRET
    const secretInput = await vscode.window.showInputBox({
      title: 'Langkah 1/4: Masukkan API Secret Key (AGENTS_TELE_SECRET)',
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

    // Langkah 2/4: Masukkan atau Pilih Project Key (prj_********)
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
      title: 'Langkah 2/4: Pilih atau Masukkan Project Key (prj_********)',
      placeHolder: 'Pilih Project Key proyek yang sesuai untuk workspace ini',
      ignoreFocusOut: true
    });
    if (!chosen) return;

    if (chosen.projectKey === '__manual__') {
      const manualKey = await vscode.window.showInputBox({
        title: 'Project Key (prj_********)',
        prompt: 'Masukkan Project Key unik dari dashboard https://biru.co.id/tele-vs-code/ (Format: prj_xxxxxxxxxx)',
        value: cur.project && cur.project.startsWith('prj_') ? cur.project : '',
        placeHolder: 'Contoh: prj_xxxxxxxxxx',
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
    // Langkah 3/4: Pilih Working Directory (Folder Tempat TASK_QUEUE.md Dikelola)
    // ==============================================================================
    const folderChoices = [];
    const seenFolders = new Set();
    const addFolderOption = (fPath, label, detail) => {
      if (!fPath || !fs.existsSync(fPath)) return;
      const resolvedPath = path.resolve(fPath);
      if (seenFolders.has(resolvedPath)) return;
      seenFolders.add(resolvedPath);
      folderChoices.push({
        label,
        description: resolvedPath,
        detail,
        folderPath: resolvedPath
      });
    };

    // Deteksi otomatis subfolder web/proyek populer (seperti public_html, www, htdocs, app) di dalam workspace
    const candidateSubdirs = ['public_html', 'www', 'htdocs', 'web', 'app', 'src'];
    for (const sub of candidateSubdirs) {
      const subFull = path.join(cur.wsFolder, sub);
      if (fs.existsSync(subFull) && fs.statSync(subFull).isDirectory()) {
        addFolderOption(
          subFull,
          `$(folder-active) Subfolder Proyek: ${sub} (Direkomendasikan)`,
          `Kelola TASK_QUEUE.md & AGENTS.md langsung di dalam folder proyek ${subFull}`
        );
      }
    }

    // Tambahkan folder aktif saat ini & root workspace
    addFolderOption(
      cur.folder,
      `$(root-folder) Folder Workspace: ${path.basename(cur.folder)}`,
      `Gunakan folder ${cur.folder} sebagai Working Directory`
    );
    addFolderOption(
      cur.wsFolder,
      `$(folder) Root Workspace Utama: ${path.basename(cur.wsFolder)}`,
      `Gunakan root workspace ${cur.wsFolder}`
    );

    folderChoices.push({
      label: '$(folder-opened) Pilih Folder Lain dari Komputer / Server... (Browse)',
      description: 'Buka dialog pemilih direktori',
      detail: 'Pilih folder kerja spesifik lainnya di mesin/server ini',
      folderPath: '__browse__'
    });

    const pickedFolderItem = await vscode.window.showQuickPick(folderChoices, {
      title: 'Langkah 3/4: Pilih Working Directory (Folder Kerja Proyek)',
      placeHolder: 'Pilih direktori tempat TASK_QUEUE.md diambil dari server & dieksekusi oleh AI Agent',
      ignoreFocusOut: true
    });
    if (!pickedFolderItem) return;

    let selectedFolder = cur.folder;
    if (pickedFolderItem.folderPath === '__browse__') {
      const browsed = await vscode.window.showOpenDialog({
        canSelectFiles: false,
        canSelectFolders: true,
        canSelectMany: false,
        openLabel: 'Pilih Sebagai Working Directory',
        title: 'Pilih Folder Kerja Proyek (Working Directory)',
        defaultUri: vscode.Uri.file(cur.folder)
      });
      if (!browsed || !browsed[0]) return;
      selectedFolder = browsed[0].fsPath;
    } else {
      selectedFolder = pickedFolderItem.folderPath;
    }

    // ==============================================================================
    // Langkah 4/4: Pilih AI Agent Handler (Hanya yang Tersedia di Mesin/Server Ini)
    // ==============================================================================
    const detectedAgents = detectAvailableAgents();
    const installedAgents = detectedAgents.filter(a => a.installed);
    const agentPickItems = [];

    for (const a of installedAgents) {
      agentPickItems.push({
        id: a.id,
        label: `${a.isIde ? '$(comment-discussion)' : '$(terminal)'} ${a.name}`,
        description: a.isIde ? '✅ Tersedia di IDE (Direkomendasikan)' : '✅ Binary CLI Terpasang di Server',
        detail: a.detail
      });
    }
    agentPickItems.push({
      id: 'auto',
      label: '$(sparkle) Auto (Deteksi Otomatis)',
      description: 'Pilih otomatis Agent yang tersedia di mesin ini',
      detail: 'Otomatis memprioritaskan Agent IDE/CLI yang terdeteksi aktif'
    });

    const pickedAgentItem = await vscode.window.showQuickPick(agentPickItems, {
      title: 'Langkah 4/4: Pilih AI Agent Eksekutor TASK_QUEUE.md',
      placeHolder: 'Hanya menampilkan AI Agent yang benar-benar terpasang di mesin/server ini',
      ignoreFocusOut: true
    });
    if (!pickedAgentItem) return;
    const selectedAgentHandler = pickedAgentItem.id;

    // ==============================================================================
    // BERSIHKAN DIREKTORI LAMA & SIMPAN KONFIGURASI KE WORKING DIRECTORY TERPILIH
    // ==============================================================================
    cleanupUnusedTaskQueues(selectedFolder, true);
    ensureWorkspaceTools(context, selectedFolder);

    const targetConfig = path.join(selectedFolder, '.vscode', 'tele-vs-code', 'config.env');
    const targetDir = path.dirname(targetConfig);
    if (!fs.existsSync(targetDir)) {
      fs.mkdirSync(targetDir, { recursive: true });
    }

    const envContent = `# ==============================================================================
# Konfigurasi Tele-vs-Code (Otomatis dari Server Biru)
# Folder: ${selectedFolder}
# ==============================================================================
AGENTS_TELE_SECRET="${cleanSecret}"
AGENTS_TELE_PROJECT="${matchedProject.project_key}"
AGENTS_TELE_PROJECT_KEY="${matchedProject.project_key}"
AGENTS_TELE_WEBHOOK_URL="${cur.webhookUrl}"
TELEGRAM_BOT_TOKEN="${matchedProject.bot_token || ''}"
TELEGRAM_CHAT_ID="${matchedProject.chat_id || ''}"
PROJECT_NAME="${matchedProject.name || ''}"
TIMEZONE="${matchedProject.timezone || 'Asia/Jakarta'}"
TELE_WORKING_DIR="${selectedFolder}"
TELE_AGENT_HANDLER="${selectedAgentHandler}"
`;

    fs.writeFileSync(targetConfig, envContent, 'utf-8');

    // Simpan ke setting VS Code (Global & Workspace) agar konsisten di seluruh sesi
    const vsConfig = vscode.workspace.getConfiguration('telegramNotifier');
    await vsConfig.update('folder', selectedFolder, vscode.ConfigurationTarget.Workspace);
    await vsConfig.update('folder', selectedFolder, vscode.ConfigurationTarget.Global);
    await vsConfig.update('secretKey', cleanSecret, vscode.ConfigurationTarget.Global);
    await vsConfig.update('project', matchedProject.project_key, vscode.ConfigurationTarget.Workspace);
    await vsConfig.update('project', matchedProject.project_key, vscode.ConfigurationTarget.Global);
    await vsConfig.update('agentHandler', selectedAgentHandler, vscode.ConfigurationTarget.Global);
    await vsConfig.update('autoDispatchAgent', true, vscode.ConfigurationTarget.Global);

    serverProjectCache = null;
    await pullTaskQueue(true);
    taskProvider.refresh();
    updateStatusBarAgent();
    await updateStatusBar();

    // Jalankan Tes Notifikasi ke Telegram
    vscode.window.showInformationMessage(`📤 Menguji koneksi Telegram untuk proyek "${matchedProject.name}" (${matchedProject.project_key})...`);
    try {
      await sendTelegramMessage(
        matchedProject.bot_token,
        matchedProject.chat_id,
        'Verifikasi Koneksi Workspace (Setup Baru)',
        'INFO',
        [
          `AGENTS_TELE_SECRET & Project Key BERHASIL TERVERIFIKASI!`,
          `Working Directory: ${selectedFolder}`,
          `Proyek: ${matchedProject.name} (${matchedProject.project_key})`,
          `AI Agent Handler: ${pickedAgentItem.label.replace(/\$\([^)]+\)\s*/, '')}`
        ],
        matchedProject.name,
        matchedProject.timezone
      );

      // ==============================================================================
      // JIKA COCOK & PASS TEST -> TAMPILKAN DIALOG OTOMASI
      // ==============================================================================
      const confirmAction = await vscode.window.showInformationMessage(
        `✅ Proyek "${matchedProject.name}" (${matchedProject.project_key}) di folder "${selectedFolder}" siap digunakan dengan Agent "${pickedAgentItem.label.replace(/\$\([^)]+\)\s*/, '')}"! Aktifkan worker otomasi sekarang?`,
        'Aktifkan Otomasi (Selalu Aktif)',
        'Aktifkan Sesi Ini Saja',
        'Nanti Saja'
      );

      if (confirmAction === 'Aktifkan Otomasi (Selalu Aktif)') {
        await vsConfig.update('autoPull', true, vscode.ConfigurationTarget.Global);
        await vsConfig.update('askOnStartup', false, vscode.ConfigurationTarget.Global);
        startAutoWorker();
        vscode.window.showInformationMessage(`🚀 Worker otomasi aktif permanen untuk proyek "${matchedProject.name}" di ${selectedFolder}.`);
      } else if (confirmAction === 'Aktifkan Sesi Ini Saja') {
        startAutoWorker();
        vscode.window.showInformationMessage(`🚀 Worker otomasi aktif untuk proyek "${matchedProject.name}" (${matchedProject.project_key}).`);
      }
    } catch (testErr) {
      vscode.window.showErrorMessage(`❌ Gagal mengirim tes ke Telegram: ${testErr.message}`, 'Coba Lagi').then(a => {
        if (a === 'Coba Lagi') vscode.commands.executeCommand('telegramNotifier.quickSetup');
      });
    }
  }));

  // 4b. Toggle Mode Debug (ON / OFF)
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.toggleDebugMode', async () => {
    const cur = getEffectiveConfig();
    const newDebugMode = !cur.debugMode;
    await cur.vscodeConf.update('debugMode', newDebugMode, vscode.ConfigurationTarget.Global);
    const queueFile = path.join(cur.folder || process.cwd(), 'TASK_QUEUE.md');

    if (newDebugMode) {
      log('info', '🐞 [MODE DEBUG AKTIF] Mode Debug diaktifkan.');
      log('debug', `[DEBUG WORKER] Direktori TASK_QUEUE.md : ${queueFile}`);
      log('debug', `[DEBUG WORKER] Eksekutor Agen Aktif   : ${cur.agentHandler || 'auto'}`);
      log('debug', `[DEBUG WORKER] Status Polling Worker   : ${isWorkerActive ? `Aktif (${cur.pullInterval}s)` : 'Dijeda'}`);
      vscode.window.showInformationMessage(
        cur.language === 'en'
          ? '🐞 Tele-vs-Code Debug Mode: ACTIVE. Detailed worker logs and execution commands will be logged.'
          : '🐞 Mode Debug Tele-vs-Code: AKTIF. Detail pekerjaan worker, direktori TASK_QUEUE.md, dan perintah eksekusi agen akan dicatat di log.',
        cur.language === 'en' ? 'View Log' : 'Lihat Log'
      ).then(act => {
        if (act === 'Lihat Log' || act === 'View Log') outputChannel.show();
      });
    } else {
      log('info', '⚪ [MODE DEBUG NONAKTIF] Mode Debug dinonaktifkan.');
      vscode.window.showInformationMessage(
        cur.language === 'en'
          ? '⚪ Tele-vs-Code Debug Mode: INACTIVE. Logging reverted to normal.'
          : '⚪ Mode Debug Tele-vs-Code: NONAKTIF. Logging kembali normal.'
      );
    }
    updateStatusBar();
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
      detailItems.push(
        {
          label: '$(play-circle) Jalankan dengan AI Agent Terpilih',
          description: 'Eksekusi langsung menggunakan AI Agent Handler aktif',
          action: 'run_ai'
        },
        {
          label: '$(debug-restart) Restart Tugas Ini (/restart)',
          description: 'Periksa apakah sedang ON PROCESS lalu restart dengan Agent terpilih',
          action: 'restart_ai'
        }
      );
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
      await executeTaskWithAgent(task.id, task.instruksi, { force: true, agent: task.agent });
    } else if (pick.action === 'restart_ai') {
      await restartTargetTask(task.id, null, false, false);
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
