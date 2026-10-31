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
  const homeDir = os.homedir();
  const globalConf = path.join(homeDir, '.config', 'tele-notify', 'config.env');
  const vscodeConf = path.join(wsFolder, '.vscode', 'tele-vs-code', 'config.env');
  const localConf = path.join(wsFolder, 'config.env');
  const localEnv = path.join(wsFolder, '.env');

  const cfgGlobal = parseEnvFile(globalConf);
  const cfgVscode = parseEnvFile(vscodeConf);
  const cfgLocal = parseEnvFile(localConf);
  const cfgEnv = parseEnvFile(localEnv);

  // PRIORITAS: .vscode/tele-vs-code/config.env > config.env lokal > .env lokal > VS Code Setting > global
  const folder = wsFolder;
  const project = cfgVscode.AGENTS_TELE_PROJECT || cfgLocal.AGENTS_TELE_PROJECT || cfgEnv.AGENTS_TELE_PROJECT || vsConfig.get('project') || cfgGlobal.AGENTS_TELE_PROJECT || '';
  const secretKey = cfgVscode.AGENTS_TELE_SECRET || cfgLocal.AGENTS_TELE_SECRET || cfgEnv.AGENTS_TELE_SECRET || vsConfig.get('secretKey') || cfgGlobal.AGENTS_TELE_SECRET || '';
  const webhookUrl = cfgVscode.AGENTS_TELE_WEBHOOK_URL || cfgLocal.AGENTS_TELE_WEBHOOK_URL || cfgEnv.AGENTS_TELE_WEBHOOK_URL || vsConfig.get('webhookUrl') || 'https://biru.co.id/tele-vs-code/webhook';
  const timezone = cfgVscode.TIMEZONE || cfgLocal.TIMEZONE || cfgEnv.TIMEZONE || vsConfig.get('timezone') || 'Asia/Jakarta';
  const autoPull = vsConfig.get('autoPull') === true;
  const askOnStartup = vsConfig.get('askOnStartup') !== false;
  const pullInterval = Math.max(2, Math.min(60, vsConfig.get('pullInterval') || 5));
  const showToastOnNewTask = vsConfig.get('showToastOnNewTask') !== false;

  const botToken = cfgVscode.TELEGRAM_BOT_TOKEN || cfgLocal.TELEGRAM_BOT_TOKEN || cfgEnv.TELEGRAM_BOT_TOKEN || vsConfig.get('botToken') || cfgGlobal.TELEGRAM_BOT_TOKEN || '';
  const chatId = cfgVscode.TELEGRAM_CHAT_ID || cfgLocal.TELEGRAM_CHAT_ID || cfgEnv.TELEGRAM_CHAT_ID || vsConfig.get('chatId') || cfgGlobal.TELEGRAM_CHAT_ID || '';

  return { folder, botToken, chatId, webhookUrl, secretKey, timezone, project, wsFolder, autoPull, askOnStartup, pullInterval, showToastOnNewTask, vscodeConf };
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
  }));

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

    // 1. Eksekusi terlebih dahulu tugas lokal yang masih aktif di TASK_QUEUE.md
    await processActiveLocalTasks(cur);

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

          // EKSEKUTOR OTOMATIS: Coba jalankan instruksi langsung (folder, file, dokumen, shell)
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

          // Jika bukan tugas eksekusi langsung, beri tahu user bahwa tugas coding masuk antrean
          if (!silent || cur.showToastOnNewTask) {
            const instBrief = t.instruction ? (t.instruction.length > 55 ? t.instruction.substring(0, 55) + '...' : t.instruction) : '';
            vscode.window.showInformationMessage(`📥 Tugas baru dari Telegram [${t.id}]: "${instBrief}" (Menunggu AI Agent)`, 'Buka TASK_QUEUE.md').then(act => {
              if (act === 'Buka TASK_QUEUE.md') {
                vscode.commands.executeCommand('telegramNotifier.openQueue');
              }
            });
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
    setTimeout(() => pullTaskQueue(true), 1000);
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
      // Jika API Secret dan Project Key belum valid: HANYA tampilkan Hubungkan ke Server Biru, lainnya sembunyikan
      menuItems = [
        {
          label: '$(gear) Hubungkan ke Server Biru (Setup Wizard)',
          description: 'Atur API Secret Key & pilih proyek dari server Biru',
          detail: '⚠️ API Secret atau Project Key belum valid. Klik untuk menghubungkan workspace ke Server Biru.',
          cmd: 'telegramNotifier.quickSetup'
        }
      ];
    } else {
      // Jika sudah valid: Tampilkan seluruh menu lengkap
      menuItems = [
        { label: '$(account) Siapa Saya? (Identifikasi Workspace)', description: `Folder: ${cur.folder} -> [${resolved.name}] (#${resolved.code})`, cmd: 'telegramNotifier.whoami' },
        { label: isWorkerActive ? '$(debug-pause) Jeda Worker Otomatis (Auto-Pull)' : '$(play) Aktifkan Worker Otomatis (Auto-Pull)', description: isWorkerActive ? `Sedang aktif memantau server setiap ${cur.pullInterval}s` : 'Mulai memantau tugas masuk dari server secara otomatis', cmd: 'telegramNotifier.toggleAutoPull' },
        { label: '$(cloud-download) Tarik Antrean Sekarang (Manual Pull)', description: 'Sinkronisasi instan task dari server webhook ke TASK_QUEUE.md', cmd: 'telegramNotifier.pullTasks' },
        { label: '$(gear) Hubungkan ke Server Biru (Setup Wizard)', description: 'Atur ulang API Secret Key & pilih proyek dari server Biru', cmd: 'telegramNotifier.quickSetup' },
        { label: '$(terminal) Jalankan Autonomous CLI Worker di Terminal', description: 'Buka terminal tele-worker untuk eksekusi skrip mandiri', cmd: 'telegramNotifier.startCliWorker' },
        { label: '$(file-text) Buka TASK_QUEUE.md', description: 'Lihat daftar tugas antrean dan riwayat selesai', cmd: 'telegramNotifier.openQueue' },
        { label: '$(check) Kirim Notifikasi: SELESAI', description: 'Kirim laporan tugas tuntas 100%', cmd: 'telegramNotifier.sendSuccess' },
        { label: '$(zap) Kirim Notifikasi: PROSES', description: 'Kirim laporan progres / sedang dikerjakan', cmd: 'telegramNotifier.sendProgress' },
        { label: '$(error) Kirim Notifikasi: KENDALA', description: 'Kirim laporan kendala / error', cmd: 'telegramNotifier.sendError' },
        { label: '$(browser) Buka Web Dashboard', description: 'Buka dashboard server biru.co.id/tele-vs-code', cmd: 'telegramNotifier.openDashboard' }
      ];
    }

    const placeholderText = isConfigValid
      ? `Tele-vs-Code [${resolved.name} - ${resolved.projectKey}] - Pilih Aksi:`
      : 'Tele-vs-Code (Belum Terhubung ke Server Biru) - Pilih Aksi:';

    const choice = await vscode.window.showQuickPick(menuItems, {
      title: isConfigValid ? `⚡ Menu Tele-vs-Code (${resolved.name})` : '⚠️ Setup Tele-vs-Code',
      placeHolder: placeholderText
    });

    if (choice && choice.cmd) {
      vscode.commands.executeCommand(choice.cmd);
    }
  }));

  // 2. Command /whoami di VS Code: Menampilkan Identitas Workspace & Routing
  context.subscriptions.push(vscode.commands.registerCommand('telegramNotifier.whoami', async () => {
    const cur = getEffectiveConfig();
    const resolved = await getResolvedProject();
    const operator = `${os.userInfo().username}@${os.hostname()}`;

    const items = [
      { label: `📁 Proyek: ${resolved.name || "(Belum Dipilih)"}${resolved.code ? ` (#${resolved.code})` : ""}`, description: `ID Proyek: ${resolved.id ? resolved.id : "(Belum Disetel)"}` },
      { label: `📂 Folder Workspace:`, description: cur.folder },
      { label: `💻 Operator Host:`, description: operator },
      { label: `📡 Status Worker:`, description: isWorkerActive ? `🟢 Aktif (Polling setiap ${cur.pullInterval} detik)` : `⚪ Dijeda / Nonaktif` },
      { label: `🌐 Server Webhook:`, description: cur.webhookUrl },
      { label: `🔑 API Key Secret:`, description: cur.secretKey ? (cur.secretKey.substring(0, 8) + '••••••••') : '(Belum diatur)' },
      { label: `🤖 Bot Telegram:`, description: resolved.botToken ? `Chat ID: ${resolved.chatId}` : '(Mengikuti server Biru)' }
    ];

    const pick = await vscode.window.showQuickPick(items, {
      title: '👤 Identifikasi Workspace Tele-vs-Code',
      placeHolder: 'Informasi status koneksi workspace ini dengan Telegram & Server Biru'
    });

    if (pick && pick.description) {
      await vscode.env.clipboard.writeText(pick.description);
      vscode.window.showInformationMessage(`📋 Disalin: ${pick.description}`);
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
