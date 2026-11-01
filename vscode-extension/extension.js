// ==============================================================================
// Telegram Dev Notifier - Private VS Code Extension
// Integrasi Notifikasi Telegram & Task Queue Bridge dengan Worker Otomasi Latar Belakang
// Multi-Workspace, Auto-Setup .vscode/tele-vs-code, & Server Biru Integration
// ==============================================================================

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
  const autoPull = vsConfig.get('autoPull') !== false;
  const askOnStartup = vsConfig.get('askOnStartup') === true;
  const pullInterval = Math.max(1, Math.min(60, vsConfig.get('pullInterval') || 2));
  const showToastOnNewTask = vsConfig.get('showToastOnNewTask') !== false;
  const autoExecuteSimpleTasks = vsConfig.get('autoExecuteSimpleTasks') === true;

  const botToken = cfgVscode.TELEGRAM_BOT_TOKEN || cfgLocal.TELEGRAM_BOT_TOKEN || cfgEnv.TELEGRAM_BOT_TOKEN || vsConfig.get('botToken') || cfgGlobal.TELEGRAM_BOT_TOKEN || '';
  const chatId = cfgVscode.TELEGRAM_CHAT_ID || cfgLocal.TELEGRAM_CHAT_ID || cfgEnv.TELEGRAM_CHAT_ID || vsConfig.get('chatId') || cfgGlobal.TELEGRAM_CHAT_ID || '';

  return { folder, botToken, chatId, webhookUrl, secretKey, timezone, project, wsFolder, autoPull, askOnStartup, pullInterval, showToastOnNewTask, autoExecuteSimpleTasks, vscodeConf };
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
    const res = await fetch(url);
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

async function sendTelegramMessage(botToken, chatId, title, status, details = [], folderName = '', timezone = '') {
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

// TreeDataProvider untuk Task Queue View di Sidebar
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

    if (!element) {
      return [
        new vscode.TreeItem('⏳ Tugas Aktif (In Progress / Pending)', vscode.TreeItemCollapsibleState.Expanded),
        new vscode.TreeItem('✅ Riwayat Tugas Selesai', vscode.TreeItemCollapsibleState.Collapsed)
      ];
    }

    if (!fs.existsSync(queueFile)) {
      return [new vscode.TreeItem('File TASK_QUEUE.md belum dibuat', vscode.TreeItemCollapsibleState.None)];
    }

    const content = fs.readFileSync(queueFile, 'utf-8');
    const items = [];

    if (element.label.startsWith('⏳ Tugas Aktif')) {
      const activeMatch = content.match(/## ⏳ Tugas Aktif([\s\S]*?)(?=## ✅ Riwayat|$)/);
      if (activeMatch) {
        const lines = activeMatch[1].split(/\r?\n/);
        for (const l of lines) {
          if (l.startsWith('### [')) {
            const item = new vscode.TreeItem(l.replace('### ', ''), vscode.TreeItemCollapsibleState.None);
            item.iconPath = new vscode.ThemeIcon('play-circle');
            items.push(item);
          }
        }
      }
      if (items.length === 0) {
        items.push(new vscode.TreeItem('Tidak ada tugas aktif di antrean', vscode.TreeItemCollapsibleState.None));
      }
      return items;
    }

    if (element.label.startsWith('✅ Riwayat Tugas Selesai')) {
      const doneMatch = content.match(/## ✅ Riwayat Tugas Selesai([\s\S]*)$/);
      if (doneMatch) {
        const lines = doneMatch[1].split(/\r?\n/);
        for (const l of lines) {
          if (l.startsWith('### [DONE]')) {
            const item = new vscode.TreeItem(l.replace('### ', ''), vscode.TreeItemCollapsibleState.None);
            item.iconPath = new vscode.ThemeIcon('check');
            items.push(item);
          }
        }
      }
      if (items.length === 0) {
        items.push(new vscode.TreeItem('Belum ada tugas selesai', vscode.TreeItemCollapsibleState.None));
      }
      return items.slice(0, 10);
    }

    return [];
  }
}

let autoPullTimer = null;
let isPulling = false;
let isWorkerActive = false;

function activate(context) {
  const curInitial = getEffectiveConfig();

  // 1. Inisialisasi otomatis berkas tool ke .vscode/tele-vs-code & wrapper root
  ensureWorkspaceTools(context, curInitial.folder);

  const taskProvider = new TaskQueueProvider(() => getEffectiveConfig().folder);
  vscode.window.registerTreeDataProvider('telegramNotifier.taskView', taskProvider);

  // Status Bar Item
  const statusBar = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Right, 100);
  async function updateStatusBar() {
    const cur = getEffectiveConfig();
    const resolved = await getResolvedProject();
    const prjTag = resolved.name ? ` [${resolved.name}]` : (resolved.projectKey ? ` [${resolved.projectKey}]` : "");
    const codeTag = resolved.code ? ` (#${resolved.code})` : "";

    if (!cur.project || !resolved.projectKey || !cur.secretKey) {
      statusBar.text = "$(radio-tower) Tele-vs-Code (Pilih Proyek)";
      statusBar.tooltip = "Tele-vs-Code: Belum terhubung ke Server Biru. Klik untuk membuka menu.";
      statusBar.command = "telegramNotifier.showMenu";
      statusBar.show();
      return;
    }

    if (isWorkerActive) {
      statusBar.text = `$(radio-tower) Tele-vs-Code${prjTag}`;
      statusBar.tooltip = `Tele-vs-Code (Proyek: ${resolved.name}${codeTag})\nFolder: ${cur.folder}\nWorker Otomatis: AKTIF (Polling ${cur.pullInterval}s)\nKlik untuk buka menu`;
    } else {
      statusBar.text = `$(bell) Tele-vs-Code${prjTag}`;
      statusBar.tooltip = `Tele-vs-Code (Proyek: ${resolved.name}${codeTag})\nFolder: ${cur.folder}\nWorker Otomatis: NONAKTIF / DIJEDA\nKlik untuk buka menu`;
    }
    statusBar.command = "telegramNotifier.showMenu";
    statusBar.show();
  }
  updateStatusBar();
  statusBar.command = 'telegramNotifier.showMenu';
  statusBar.show();
  context.subscriptions.push(statusBar);
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
          vscode.window.showInformationMessage(`✅ Tugas [${dId}] Selesai 100%! "${instSnippet}"`);
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
  const mFolder = inst.match(/^(?:buat|bikin|create|make)?\s*(?:folder|foldsr|dir|direktori)\s+(.+)$/i) ||
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
  const mFile = inst.match(/^(?:buat|bikin|create|touch)?\s*(?:file|dokumen|document|berkas)\s+(?:dengan\s+nama\s+)?([^\s\r\n]+)(?:\s+(?:isinya|isi|content)\s+([\s\S]*))?$/i) ||
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
  const mDelete = inst.match(/^(?:hapus|delete|remove|rm|del)\s*(?:folder|dir|file|dokumen|berkas)?\s+([^\s\r\n]+)$/i);
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

  return { executed: false };
}

async function markServerTaskDone(cur, taskId, notes = '') {
  try {
    const url = `${cur.webhookUrl.replace(/\/?$/, '/')}?action=done&secret=${cur.secretKey}&id=${encodeURIComponent(taskId)}`;
    await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `notes=${encodeURIComponent(notes)}`
    });
  } catch (e) {}
}

async function markServerTaskFailed(cur, taskId, notes = '') {
  try {
    const url = `${cur.webhookUrl.replace(/\/?$/, '/')}?action=failed&secret=${cur.secretKey}&id=${encodeURIComponent(taskId)}`;
    await fetch(url, {
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
            vscode.window.showInformationMessage(`✅ Tugas [${taskObj.id}] berhasil dieksekusi: ${rep}`);
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
    const doneBlock = `### [DONE] ${task.id}\n- **Waktu:** ${task.created_at}\n- **Pengirim:** ${task.sender}\n- **Instruksi:** ${task.instruction.replace(/\r?\n/g, ' ')}\n- **Status:** DONE\n- **Laporan Agent:** ${report}\n\n`;
    const doneMarker = '## ✅ Riwayat Tugas Selesai';
    if (content.includes(doneMarker)) {
      content = content.replace(doneMarker, `${doneMarker}\n\n${doneBlock}`);
    } else {
      content += `\n\n${doneMarker}\n\n${doneBlock}`;
    }
    fs.writeFileSync(queueFile, content, 'utf-8');
  } catch (e) {}
}

  // Helper Tarik Antrean Tugas (dengan Heartbeat Multi-Workspace)
  async function pullTaskQueue(silent = false) {
    if (isPulling) return;
    isPulling = true;
    const cur = getEffectiveConfig();

    if (!cur.secretKey) {
      isPulling = false;
      if (!silent) {
        vscode.window.showWarningMessage('API Secret Key belum diatur. Pilih "Hubungkan ke Server Biru" di menu Tele-vs-Code.');
      }
      return;
    }

    // 1. Eksekusi terlebih dahulu tugas lokal yang masih aktif di TASK_QUEUE.md (jika autoExecuteSimpleTasks aktif)
    if (cur.autoExecuteSimpleTasks) {
      await processActiveLocalTasks(cur);
    }

    const operator = `${os.userInfo().username}@${os.hostname()}`;
    let url = `${cur.webhookUrl.replace(/\/?$/, '/')}?action=pull&secret=${cur.secretKey}&limit=5&format=json&mark=1&workspace_folder=${encodeURIComponent(cur.folder)}&workspace_name=${encodeURIComponent(path.basename(cur.folder))}&operator=${encodeURIComponent(operator)}`;
    if (cur.project) {
      url += `&project=${encodeURIComponent(cur.project)}`;
    }

    try {
      const res = await fetch(url);
      const data = await res.json();
      if (data.ok && Array.isArray(data.tasks) && data.tasks.length > 0) {
        const queueFile = path.join(cur.folder, 'TASK_QUEUE.md');
        let content = fs.existsSync(queueFile)
          ? fs.readFileSync(queueFile, 'utf-8')
          : '# TASK QUEUE\n\n## ⏳ Tugas Aktif\n\n## ✅ Riwayat Tugas Selesai\n';

        let newTasksCount = 0;
        for (const t of data.tasks) {
          let attachLines = '';
          if (t.attachment_url) {
            try {
              const attachDir = path.join(cur.folder, 'attachments', t.id.replace(/[^a-zA-Z0-9_-]/g, ''));
              if (!fs.existsSync(attachDir)) {
                fs.mkdirSync(attachDir, { recursive: true });
              }
              const localFilePath = path.join(attachDir, t.attachment_name || 'lampiran.bin');
              if (!fs.existsSync(localFilePath)) {
                const fRes = await fetch(t.attachment_url);
                if (fRes.ok) {
                  const fBuf = Buffer.from(await fRes.arrayBuffer());
                  fs.writeFileSync(localFilePath, fBuf);
                }
              }
              const relPath = path.relative(cur.folder, localFilePath).replace(/\\/g, '/');
              const absFileUrl = `file:///${localFilePath.replace(/\\/g, '/')}`;
              attachLines = `\n- **Lampiran Lokal:** [${t.attachment_name || 'Buka File'}](${absFileUrl}) (${t.attachment_type || 'file'})\n- **URL Lampiran:** [Buka di Browser](${t.attachment_url})`;
            } catch (dErr) {
              attachLines = `\n- **Lampiran:** [${t.attachment_name || 'File'}](${t.attachment_url}) (${t.attachment_type || 'file'})`;
            }
          }

          // Catat tugas ke TASK_QUEUE.md
          const taskBlock = `### [IN_PROGRESS] ${t.id}\n- **Waktu:** ${t.created_at}\n- **Pengirim:** ${t.sender}\n- **Instruksi:** ${t.instruction}${attachLines}\n- **Status:** IN_PROGRESS\n\n`;
          if (!content.includes(t.id)) {
            newTasksCount++;
            if (content.includes('## ⏳ Tugas Aktif')) {
              content = content.replace('## ⏳ Tugas Aktif', `## ⏳ Tugas Aktif\n\n${taskBlock}`);
              content = content.replace(/\*\(Tidak ada tugas aktif di antrean saat ini[^\)]*\)\*/g, '');
            } else {
              content = `## ⏳ Tugas Aktif\n\n${taskBlock}\n\n${content}`;
            }
          }
          fs.writeFileSync(queueFile, content, 'utf-8');

          // EKSEKUTOR OTOMATIS: Hanya jika diaktifkan (default: false agar tugas tetap di TASK_QUEUE untuk AI Agent)
          if (cur.autoExecuteSimpleTasks) {
            try {
              const execRes = tryExecuteDirectTask(t.instruction, cur.folder);
              if (execRes && execRes.executed) {
                const rep = execRes.report;
                await markServerTaskDone(cur, t.id, rep);
                appendDoneToTaskQueueFile(queueFile, t, rep);
                content = fs.readFileSync(queueFile, 'utf-8');

                try {
                  await sendTelegramMessage(cur.botToken, cur.chatId, `Tugas Selesai: ${t.id}`, 'SELESAI', [
                    `Instruksi: ${t.instruction.substring(0, 90)}`,
                    `Hasil: ${rep}`,
                    `Pengirim: ${t.sender}`
                  ], cur.project, cur.timezone);
                } catch (nErr) {}

                vscode.window.showInformationMessage(`✅ Tugas [${t.id}] berhasil dieksekusi: ${rep}`);
                continue;
              }
            } catch (execErr) {
              await markServerTaskFailed(cur, t.id, execErr.message);
              try {
                await sendTelegramMessage(cur.botToken, cur.chatId, `Gagal: ${t.id}`, 'KENDALA', [
                  `Instruksi: ${t.instruction.substring(0, 90)}`,
                  `Penyebab: ${execErr.message}`
                ], cur.project, cur.timezone);
              } catch (nErr) {}
              vscode.window.showErrorMessage(`❌ Gagal mengeksekusi tugas [${t.id}]: ${execErr.message}`);
              continue;
            }
          }

          // Beri tahu user bahwa tugas baru masuk antrean
          if (!silent || cur.showToastOnNewTask) {
            const instBrief = t.instruction ? (t.instruction.length > 55 ? t.instruction.substring(0, 55) + '...' : t.instruction) : '';
            const isIdeTask = (t.id && (t.id.includes('-IDE-') || t.id.startsWith('#TASK-IDE'))) ||
                              (t.sender && (t.sender.includes('IDE') || t.sender.includes('VS Code')));

            if (isIdeTask) {
              // Tugas dari IDE (dengan kode khusus -IDE-): JANGAN tampilkan pop-up dialog delegasi
              // Tampilkan pop-up status tugas dikerjakan / selesai
              if (t.status === 'DONE') {
                vscode.window.showInformationMessage(`✅ [${t.id}] Tugas IDE Selesai: "${instBrief}"`);
              } else {
                vscode.window.showInformationMessage(`⚡ [${t.id}] Tugas IDE Sedang Dikerjakan: "${instBrief}"`);
              }
            } else {
              // Tugas dari Telegram: tampilkan pop-up dialog interaktif untuk kirim ke AI Chat
              vscode.window.showInformationMessage(
                `📥 Tugas baru dari Telegram [${t.id}]: "${instBrief}"`,
                '🚀 Kerjakan dengan AI Agent',
                '📋 Buka TASK_QUEUE.md'
              ).then(async act => {
                if (act === '🚀 Kerjakan dengan AI Agent') {
                  const prompt = `Periksa file TASK_QUEUE.md dan selesaikan tugas ${t.id} ("${t.instruction}") sampai tuntas 100% sesuai AGENTS.md.`;
                  await vscode.env.clipboard.writeText(prompt);
                  try {
                    await vscode.commands.executeCommand('workbench.action.chat.open');
                  } catch (cErr) {}
                  vscode.window.showInformationMessage(`📋 Prompt tugas [${t.id}] telah disalin ke clipboard! Tempelkan (Ctrl+V) di chat AI untuk mulai mengeksekusi.`);
                } else if (act === '📋 Buka TASK_QUEUE.md') {
                  vscode.commands.executeCommand('telegramNotifier.openQueue');
                }
              });
            }
          }
        }

        taskProvider.refresh();
        statusBar.text = `$(check) Worker Otomasi Aktif`;
        setTimeout(() => updateStatusBar(), 4000);
      } else if (!silent) {
        vscode.window.showInformationMessage('ℹ️ Tidak ada tugas baru di antrean webhook.');
      }
    } catch (err) {
      if (!silent) {
        vscode.window.showErrorMessage(`❌ Gagal menarik tugas: ${err.message}`);
      }
    } finally {
      isPulling = false;
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

    const resolved = await getResolvedProject();
    if (!resolved.projectKey) {
      vscode.window.showWarningMessage(
        `⚠️ Proyek "${cur.project}" tidak ditemukan di Server Biru. Jalankan Setup Wizard untuk memilih proyek yang valid.`,
        "Pilih Proyek"
      ).then(act => {
        if (act === "Pilih Proyek") {
          vscode.commands.executeCommand("telegramNotifier.quickSetup");
        }
      });
      return;
    }

    if (cur.askOnStartup) {
      // Tanyakan konfirmasi aktifkan otomasi saat buka ulang VS Code
      vscode.window.showInformationMessage(
        `🤖 Tele-vs-Code: Workspace terhubung ke Proyek "${resolved.name}" (${resolved.projectKey}). Apakah Anda ingin mengaktifkan worker otomasi sekarang?`,
        "Aktifkan Otomasi",
        "Selalu Aktifkan",
        "Jeda Otomasi"
      ).then(async act => {
        if (act === "Aktifkan Otomasi") {
          startAutoWorker();
          vscode.window.showInformationMessage(`🚀 Worker otomasi aktif untuk proyek "${resolved.name}" (${resolved.projectKey}).`);
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
          label: '$(gear) Hubungkan ke Server Biru (Setup Wizard)',
          description: 'Atur API Secret Key & pilih proyek dari server Biru',
          detail: '⚠️ API Secret atau Project Key belum valid. Klik untuk menghubungkan workspace ke Server Biru.',
          cmd: 'telegramNotifier.quickSetup'
        },
        {
          label: '$(account) Siapa Saya? (Identifikasi Workspace)',
          description: 'Belum Terhubung ke Server Biru',
          detail: `Folder: ${cur.folder}`,
          cmd: 'telegramNotifier.whoami'
        }
      ];
    } else {
      // Menu Utama Terstruktur & Rapi:
      menuItems = [
        // 1. Folder Workspace (Klik langsung untuk ubah folder kerja)
        {
          label: `$(folder) Folder Workspace: ${path.basename(cur.folder)}`,
          description: cur.folder,
          detail: 'Working directory tempat AGENTS.md, CLAUDE.md, & TASK_QUEUE.md dikelola. Klik untuk ubah.',
          cmd: 'telegramNotifier.setFolder'
        },
        // 2. Sub-Menu: Tasks & Jobs
        {
          label: '$(checklist) Tasks & Jobs (Antrean Tugas)...',
          description: 'Antrean tugas, trigger AI Chat, & autonomous CLI worker',
          detail: 'Buka sub-menu: Kerjakan Antrean, Tambah Tugas, Buka Queue, & Terminal CLI Worker',
          sub: 'tasks'
        },
        // 3. Sub-Menu: Kontrol Worker & Durasi Polling (Kotak Hijau + Durasi)
        {
          label: '$(sync) Kontrol Worker & Durasi Polling...',
          description: isWorkerActive ? `🟢 Aktif (Polling setiap ${cur.pullInterval} detik)` : '⚪ Dijeda / Nonaktif',
          detail: `Buka sub-menu: Jeda/Mulai auto-pull, Tarik tugas manual, & Ubah durasi polling`,
          sub: 'worker'
        },
        // 4. Sub-Menu: Test Notifikasi Telegram
        {
          label: '$(bell) Test Notifikasi Telegram...',
          description: 'Kirim notifikasi pengujian ke bot Telegram proyek',
          detail: 'Buka sub-menu: Kirim pesan uji coba SELESAI, PROSES, atau KENDALA',
          sub: 'test_notify'
        },
        // 5. Buka Web Dashboard
        {
          label: '$(browser) Buka Web Dashboard',
          description: 'Buka dashboard server https://biru.co.id/tele-vs-code/',
          cmd: 'telegramNotifier.openDashboard'
        },
        // 6. Hubungkan ke Server Biru (Dipindahkan ke Bawah)
        {
          label: '$(gear) Hubungkan ke Server Biru (Setup Wizard)',
          description: 'Atur ulang API Secret Key & pilih proyek dari server Biru',
          detail: 'Konfigurasi akun dan autentikasi proyek',
          cmd: 'telegramNotifier.quickSetup'
        },
        // 7. Whoami (Paling Bawah - Menampilkan Status Worker & Durasi)
        {
          label: '$(account) Siapa Saya? (Identifikasi Workspace)',
          description: isWorkerActive ? `Worker: 🟢 MENYALA (${cur.pullInterval}s)` : 'Worker: ⚪ TIDAK MENYALA',
          detail: `Folder: ${path.basename(cur.folder)} -> [${resolved.name}] (#${resolved.code || resolved.projectKey})`,
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
        { label: '$(play-circle) Kerjakan Antrean Tugas Berikutnya (Kirim ke Chat AI)', description: 'Salin instruksi tugas aktif di TASK_QUEUE & buka chat AI', cmd: 'telegramNotifier.runNextTask' },
        { label: '$(add) Tambah Tugas Baru / Kirim Perintah ke Antrean', description: 'Kirim tugas ke antrean TASK_QUEUE.md & notifikasi Telegram', cmd: 'telegramNotifier.addTask' },
        { label: '$(file-text) Buka TASK_QUEUE.md', description: 'Lihat daftar tugas antrean dan riwayat selesai', cmd: 'telegramNotifier.openQueue' },
        { label: '$(terminal) Jalankan Autonomous CLI Worker di Terminal', description: 'Buka terminal tele-worker untuk eksekusi skrip mandiri', cmd: 'telegramNotifier.startCliWorker' },
        { label: '$(arrow-left) Kembali ke Menu Utama', description: 'Tutup submenu tasks', cmd: 'telegramNotifier.showMenu' }
      ];
      const subPick = await vscode.window.showQuickPick(taskSubItems, {
        title: `📋 Menu Tasks & Jobs (${resolved.name})`,
        placeHolder: 'Pilih aksi manajemen tugas:'
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
          label: isWorkerActive ? '$(debug-pause) Jeda Worker Otomatis (Pause Auto-Pull)' : '$(play) Aktifkan Worker Otomatis (Start Auto-Pull)',
          description: isWorkerActive ? `Sedang aktif memantau server setiap ${cur.pullInterval}s` : 'Mulai memantau tugas masuk dari server secara otomatis',
          cmd: 'telegramNotifier.toggleAutoPull'
        },
        {
          label: '$(cloud-download) Tarik Antrean Sekarang (Manual Pull Seketika)',
          description: 'Sinkronisasi instan task dari server webhook ke TASK_QUEUE.md',
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

    // Sub-Menu: Test Notifikasi Telegram
    if (choice.sub === 'test_notify') {
      const testSubItems = [
        { label: '$(check) Test Notifikasi: SELESAI (100% Tuntas)', description: 'Kirim notifikasi sukses tuntas 100% ke Telegram', cmd: 'telegramNotifier.sendSuccess' },
        { label: '$(zap) Test Notifikasi: PROSES (Sedang Dikerjakan)', description: 'Kirim notifikasi update progres tugas ke Telegram', cmd: 'telegramNotifier.sendProgress' },
        { label: '$(error) Test Notifikasi: KENDALA (Ada Masalah/Error)', description: 'Kirim notifikasi kendala atau masalah ke Telegram', cmd: 'telegramNotifier.sendError' },
        { label: '$(arrow-left) Kembali ke Menu Utama', description: 'Tutup submenu test notifikasi', cmd: 'telegramNotifier.showMenu' }
      ];
      const subPick = await vscode.window.showQuickPick(testSubItems, {
        title: `🔔 Test Notifikasi Telegram (${resolved.name})`,
        placeHolder: 'Pilih jenis pesan notifikasi yang ingin diuji:'
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
      const match = /###\s+\[(PENDING|IN_PROGRESS)\]\s+(#?TASK-[A-Za-z0-9_-]+)[\r\n]+([\s\S]*?)(?=(?:###\s+\[|##|$))/i.exec(activeSection);
      if (!match) {
        vscode.window.showInformationMessage('ℹ️ Tidak ada tugas aktif [PENDING] atau [IN_PROGRESS] di antrean TASK_QUEUE.md.');
        return;
      }

      const taskId = match[2].startsWith('#') ? match[2] : `#${match[2]}`;
      const block = match[3];
      const mInst = block.match(/-\s+\*\*Instruksi:\*\*\s*([^\r\n]+)/i);
      const instruction = mInst ? mInst[1].trim() : '';

      const prompt = `Periksa file TASK_QUEUE.md dan selesaikan tugas ${taskId} ("${instruction}") sampai tuntas 100% sesuai AGENTS.md.`;
      await vscode.env.clipboard.writeText(prompt);

      try {
        await vscode.commands.executeCommand('workbench.action.chat.open');
      } catch (cErr) {}

      vscode.window.showInformationMessage(
        `🚀 Tugas [${taskId}] disalin ke clipboard! Tempelkan (Ctrl+V) di chat AI untuk mulai mengeksekusi.`,
        'Buka TASK_QUEUE.md'
      ).then(act => {
        if (act === 'Buka TASK_QUEUE.md') {
          vscode.commands.executeCommand('telegramNotifier.openQueue');
        }
      });
    } catch (e) {
      vscode.window.showErrorMessage(`❌ Gagal membaca antrean tugas: ${e.message}`);
    }
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
      const res = await fetch(url);
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
