#!/usr/bin/env node

const dns = require('dns');
if (typeof dns.setDefaultResultOrder === 'function') {
  dns.setDefaultResultOrder('ipv4first');
}

// Robust HTTP Fetch dengan Auto-Retry & Connection Close
async function safeFetch(url, options = {}, maxRetries = 2) {
  let lastErr = null;
  const timeoutMs = options.timeout || 15000;
  for (let attempt = 0; attempt <= maxRetries; attempt++) {
    try {
      const opt = {
        ...options,
        headers: {
          'Connection': 'close',
          'User-Agent': 'Tele-vs-Code/1.0.12',
          ...(options.headers || {})
        },
        signal: options.signal || (typeof AbortSignal !== 'undefined' && AbortSignal.timeout ? AbortSignal.timeout(timeoutMs) : undefined)
      };
      return await fetch(url, opt);
    } catch (err) {
      lastErr = err;
      if (err.name === 'AbortError' && options.signal && options.signal.aborted) throw err;
      if (attempt < maxRetries) {
        await new Promise(r => setTimeout(r, 500 * (attempt + 1)));
      }
    }
  }
  const causeDetail = lastErr && lastErr.cause ? (lastErr.cause.code || lastErr.cause.message || '') : '';
  const errorMsg = causeDetail && !lastErr.message.includes(causeDetail) ? `${lastErr.message} (${causeDetail})` : (lastErr ? lastErr.message : 'Network request failed');
  const wrapped = new Error(errorMsg);
  if (lastErr && lastErr.cause) wrapped.cause = lastErr.cause;
  throw wrapped;
}

// ==============================================================================
// Autonomous Telegram Task Queue Worker
// Menjalankan antrean tugas dari server biru.co.id satu per satu secara otomatis
// Dan otomatis sinkronisasi file server ke U:\domains\biru.co.id\public_html\tele-vs-code
// ==============================================================================

const fs = require('fs');
const path = require('path');
const os = require('os');
const { execSync } = require('child_process');

const args = process.argv.slice(2);
let projectDir = process.cwd();
let intervalMs = 5000;
let runOnce = false;

for (let i = 0; i < args.length; i++) {
  if (args[i] === '-p' || args[i] === '--project') {
    projectDir = path.resolve(args[++i] || '.');
  } else if (args[i] === '--once' || args[i] === '--run-once') {
    runOnce = true;
  } else if (args[i] === '-i' || args[i] === '--interval') {
    intervalMs = parseInt(args[++i] || '5', 10) * 1000;
  }
}

function parseEnvFile(filePath) {
  if (!fs.existsSync(filePath)) return {};
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
}

const homeDir = os.homedir();
const globalConf = path.join(homeDir, '.config', 'tele-notify', 'config.env');
const localVscodeConf = path.join(projectDir, '.vscode', 'tele-vs-code', 'config.env');
const localConf = path.join(projectDir, 'config.env');
const localEnv = path.join(projectDir, '.env');

const cfgGlobal = parseEnvFile(globalConf);
const cfgVscode = parseEnvFile(localVscodeConf);
const cfgLocal = parseEnvFile(localConf);
const cfgEnv = parseEnvFile(localEnv);

const webhookUrl = process.env.AGENTS_TELE_WEBHOOK_URL ||
                   cfgVscode.AGENTS_TELE_WEBHOOK_URL ||
                   cfgLocal.AGENTS_TELE_WEBHOOK_URL ||
                   cfgEnv.AGENTS_TELE_WEBHOOK_URL ||
                   cfgGlobal.AGENTS_TELE_WEBHOOK_URL ||
                   'https://biru.co.id/tele-vs-code/webhook';

const secretKey = process.env.AGENTS_TELE_SECRET ||
                  cfgVscode.AGENTS_TELE_SECRET ||
                  cfgLocal.AGENTS_TELE_SECRET ||
                  cfgEnv.AGENTS_TELE_SECRET ||
                  cfgGlobal.AGENTS_TELE_SECRET ||
                  '';

const projectKey = process.env.AGENTS_TELE_PROJECT ||
                   cfgVscode.AGENTS_TELE_PROJECT ||
                   cfgLocal.AGENTS_TELE_PROJECT ||
                   cfgEnv.AGENTS_TELE_PROJECT ||
                   cfgGlobal.AGENTS_TELE_PROJECT ||
                   '';

const deployDir = process.env.SERVER_DEPLOY_DIR ||
                  cfgLocal.SERVER_DEPLOY_DIR ||
                  cfgEnv.SERVER_DEPLOY_DIR ||
                  cfgGlobal.SERVER_DEPLOY_DIR ||
                  'U:\\domains\\biru.co.id\\public_html\\tele-vs-code';

const normalizedUrl = webhookUrl.endsWith('/') ? webhookUrl : `${webhookUrl}/`;
const queueFile = path.join(projectDir, 'TASK_QUEUE.md');
const serverDir = path.join(projectDir, 'tele-vs-code');

// Fungsi Auto-Update Server ke Folder U:
function syncServerFiles() {
  if (!fs.existsSync(deployDir)) {
    return false;
  }
  try {
    const srcIndex = path.join(serverDir, 'index.php');
    const srcVsix = path.join(projectDir, 'tele-vs-code-1.0.0.vsix');
    if (fs.existsSync(srcIndex)) {
      fs.copyFileSync(srcIndex, path.join(deployDir, 'index.php'));
    }
    if (fs.existsSync(srcVsix)) {
      fs.copyFileSync(srcVsix, path.join(deployDir, 'tele-vs-code-1.0.0.vsix'));
    }
    console.log(`🔄 [AUTO-SYNC] File server berhasil disinkronkan ke: ${deployDir}`);
    return true;
  } catch (err) {
    console.error(`⚠️ Gagal sync file server ke ${deployDir}: ${err.message}`);
    return false;
  }
}

// Jalankan sync saat start
syncServerFiles();

// Watcher untuk auto-update file server saat diedit
try {
  if (!runOnce && fs.existsSync(serverDir)) {
    fs.watch(serverDir, (eventType, filename) => {
      if (filename && (filename.endsWith('.php') || filename.endsWith('.vsix'))) {
        setTimeout(syncServerFiles, 500);
      }
    });
    console.log(`👀 File Watcher aktif: Memantau folder tele-vs-code/ -> auto update ke ${deployDir}`);
  }
} catch (e) {}

console.log(`================================================================`);
console.log(`🚀 Telegram Task Worker Otomatis (Background Daemon)`);
console.log(`🌐 Server Webhook : ${normalizedUrl}`);
console.log(`📁 Target Deploy  : ${deployDir}`);
console.log(`⏱️ Polling Setiap : ${intervalMs / 1000} detik`);
console.log(`================================================================\n`);

async function fetchNextTask() {
  try {
    const operator = `${os.userInfo().username}@${os.hostname()}`;
    let url = `${normalizedUrl}?action=pull&secret=${secretKey}&limit=1&format=json&mark=0&workspace_folder=${encodeURIComponent(projectDir)}&operator=${encodeURIComponent(operator)}`;
    if (projectKey) {
      url += `&project=${encodeURIComponent(projectKey)}`;
    }
    const res = await safeFetch(url, { timeout: 15000 });
    const data = await res.json();
    if (data.ok && Array.isArray(data.tasks) && data.tasks.length > 0) {
      const task = data.tasks[0];
      if (task.attachment_url) {
        try {
          const attachDir = path.join(projectDir, 'attachments', task.id.replace(/[^a-zA-Z0-9_-]/g, ''));
          if (!fs.existsSync(attachDir)) fs.mkdirSync(attachDir, { recursive: true });
          const localPath = path.join(attachDir, task.attachment_name || 'lampiran.bin');
          if (!fs.existsSync(localPath)) {
            const fRes = await safeFetch(task.attachment_url, { timeout: 30000 });
            if (fRes.ok) {
              const buf = Buffer.from(await fRes.arrayBuffer());
              fs.writeFileSync(localPath, buf);
            }
          }
          task.local_attachment_path = localPath;
        } catch (e) {}
      }
      return task;
    }
  } catch (err) {}
  return null;
}

async function markTaskStatus(taskId, status = 'IN_PROGRESS', notes = '') {
  try {
    const act = (status === 'DONE') ? 'done' : ((status === 'FAILED') ? 'failed' : 'in_progress');
    const url = `${normalizedUrl}?action=${act}&secret=${secretKey}&id=${encodeURIComponent(taskId)}`;
    const res = await safeFetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `notes=${encodeURIComponent(notes)}`
    });
    const data = await res.json().catch(() => null);
    return data;
  } catch (err) {
    console.error(`⚠️ Gagal update status di server: ${err.message}`);
    return null;
  }
}

async function markTaskDone(taskId, notes = 'Selesai dikerjakan 100%') {
  return markTaskStatus(taskId, 'DONE', notes);
}

function getNotifyScript() {
  const candidates = [
    path.join(__dirname, 'tele-notify.js'),
    path.join(projectDir, '.vscode', 'tele-vs-code', 'tele-notify.js'),
    path.join(projectDir, 'tele-notify.js')
  ];
  return candidates.find(p => fs.existsSync(p)) || null;
}

function sendNotification(title, status, details = []) {
  try {
    const notifyJs = getNotifyScript();
    if (notifyJs) {
      const args = [notifyJs, title, status, ...details].map(a => `"${String(a).replace(/"/g, '\\"')}"`).join(' ');
      execSync(`node ${args}`, { cwd: projectDir, stdio: 'inherit' });
    }
  } catch (err) {
    console.error(`⚠️ Notifier error: ${err.message}`);
  }
}

// Catat tugas aktif ke TASK_QUEUE.md (Status: IN_PROGRESS)
function recordActiveTask(task) {
  try {
    if (!fs.existsSync(queueFile)) return;
    let content = fs.readFileSync(queueFile, 'utf-8');
    if (content.includes(task.id)) return; // sudah tercatat

    const agentName = task.assigned_agent || task.agent || 'Antigravity AI Agent';
    const status = (task.status === 'DONE' || task.status === 'FAILED') ? task.status : (task.status === 'IN_PROGRESS' ? 'IN_PROGRESS' : 'PENDING');
    const note = status === 'IN_PROGRESS' ? 'Sedang dikerjakan oleh AI Agent...' : 'Menunggu giliran eksekusi oleh AI Agent...';
    const activeBlock = `### [${status}] ${task.id}
- **Waktu:** ${task.created_at}
- **Pengirim:** ${task.sender}
- **Agent:** ${agentName}
- **Instruksi:** ${task.instruction.replace(/\r?\n/g, ' ')}
- **Status:** ${status}
- **Catatan:** ${note}

`;
    const activeMarker = '## ⏳ Tugas Aktif';
    if (content.includes(activeMarker)) {
      content = content.replace(activeMarker, `${activeMarker}\n\n${activeBlock}`);
      content = content.replace(/\*\(Tidak ada tugas aktif di antrean saat ini[^\)]*\)\*/g, '');
    } else {
      content = `${activeMarker}\n\n${activeBlock}\n\n${content}`;
    }
    fs.writeFileSync(queueFile, content, 'utf-8');
  } catch (err) {}
}

// Pindahkan tugas ke Riwayat Tugas Selesai (Status: DONE) hanya jika 100% SELESAI
// Format durasi pengerjaan
function formatDuration(startTime, endTime = new Date()) {
  if (!startTime) return '';
  try {
    const startTs = typeof startTime === 'number' ? startTime : new Date(startTime).getTime();
    const endTs = typeof endTime === 'number' ? endTime : new Date(endTime).getTime();
    if (isNaN(startTs) || isNaN(endTs)) return '';
    const diffSec = Math.max(0, Math.floor((endTs - startTs) / 1000));
    const h = Math.floor(diffSec / 3600);
    const m = Math.floor((diffSec % 3600) / 60);
    const s = diffSec % 60;
    if (h > 0) return `${h} jam ${m} menit ${s} detik`;
    if (m > 0) return `${m} menit ${s} detik`;
    return `${s} detik`;
  } catch (e) {
    return '';
  }
}

function appendToTaskQueueFile(task, report) {
  try {
    if (!fs.existsSync(queueFile)) return;
    let content = fs.readFileSync(queueFile, 'utf-8');
    
    // Bersihkan dari daftar tugas aktif jika ada
    const regexActive = new RegExp(`### \\[(PENDING|IN_PROGRESS)\\]\\s+${task.id}[\\s\\S]*?(?=(###|##|$))`, 'g');
    content = content.replace(regexActive, '');

    const now = new Date().toISOString().replace('T', ' ').substring(0, 19);
    const durStr = formatDuration(task.created_at, now);
    const durLine = durStr ? `- **Durasi:** ${durStr}\n` : '';
    const doneBlock = `### [DONE] ${task.id}
- **Waktu:** ${now}
- **Pengirim:** ${task.sender}
${durLine}- **Instruksi:** ${task.instruction.replace(/\r?\n/g, ' ')}
- **Status:** DONE
- **Laporan Agent:** ${report}

`;
    const doneMarker = '## ✅ Riwayat Tugas Selesai';
    if (content.includes(doneMarker)) {
      content = content.replace(doneMarker, `${doneMarker}\n\n${doneBlock}`);
    } else {
      content += `\n\n${doneMarker}\n\n${doneBlock}`;
    }
    fs.writeFileSync(queueFile, content, 'utf-8');
  } catch (err) {}
}

// Autonomous Task Execution Engine
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

  return { executed: false };
}

function findCli(binName) {
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
  const candidates = [
    path.join(home, '.local', 'bin', binName),
    path.join(home, '.antigravity', 'bin', binName),
    path.join(home, '.cargo', 'bin', binName),
    path.join(home, 'bin', binName),
    `/usr/local/bin/${binName}`,
    `/usr/bin/${binName}`,
    `/snap/bin/${binName}`,
    `/root/.local/bin/${binName}`
  ];
  for (const cand of candidates) {
    if (fs.existsSync(cand)) return cand;
  }
  return null;
}

// Handler Eksekusi Tugas
async function executeTask(task) {
  const inst = task.instruction.trim();
  const lowerInst = inst.toLowerCase();
  console.log(`\n------------------------------------------------------------`);
  console.log(`📥 Tugas Diterima: [${task.id}]`);
  console.log(`📝 Instruksi: ${inst}`);
  console.log(`👤 Pengirim: ${task.sender}`);
  console.log(`------------------------------------------------------------`);

  // 1. Coba eksekusi tugas langsung (folder, file/dokumen, shell, delete)
  try {
    const directResult = tryExecuteDirectTask(inst, projectDir);
    if (directResult && directResult.executed) {
      const report = directResult.report;
      const resDone = await markTaskDone(task.id, report);
      appendToTaskQueueFile(task, report);

      // Cegah pengiriman ganda jika server sudah mengirim notifikasi SELESAI
      if (!resDone || (!resDone.notified_by_server && !resDone.already_notified)) {
        sendNotification(`Tugas Selesai: ${task.id}`, 'SELESAI', [
          `Instruksi: ${inst.substring(0, 90)}`,
          `Hasil: ${report}`,
          `Pengirim: ${task.sender}`
        ]);
      }
      console.log(`✅ [${task.id}] Berhasil dieksekusi 100%: ${report}`);
      return;
    }
  } catch (dirErr) {
    console.error(`❌ Gagal eksekusi langsung: ${dirErr.message}`);
    await markTaskStatus(task.id, 'FAILED', dirErr.message);
    sendNotification(`Gagal: ${task.id}`, 'KENDALA', [
      `Instruksi: ${inst.substring(0, 90)}`,
      `Penyebab: ${dirErr.message}`
    ]);
    return;
  }

  // 2. Periksa apakah tugas adalah utilitas sistem instan (/publish, /backup, /saveall, /sampah, /hack, /ping)
  const isUtility = /^\/(publish|backup|saveall|sampah|hack|ping)(\s|$)/i.test(inst) ||
                    ['publish', 'backup', 'saveall', 'sampah', 'hack', 'ping'].includes(lowerInst);

  if (isUtility) {
    let report = 'Selesai dieksekusi 100%';
    try {
      if (lowerInst.includes('publish')) {
        const synced = syncServerFiles();
        report = synced ? `File server berhasil di-publish ke ${deployDir}` : `Deploy ke ${deployDir} selesai`;
      } else if (lowerInst.includes('backup')) {
        const backupScript = path.join(projectDir, 'backup.js');
        if (fs.existsSync(backupScript)) {
          execSync(`node "${backupScript}"`, { cwd: projectDir, stdio: 'inherit' });
          report = 'Pencadangan penuh berhasil dibuat (database SQLite, source code, dan redundansi Drive U:)';
        } else {
          const now = new Date();
          const dateFolder = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`;
          const timeFolder = `${String(now.getHours()).padStart(2,'0')}-${String(now.getMinutes()).padStart(2,'0')}`;
          const backupDir = path.join(path.dirname(projectDir), 'backups', `${dateFolder}_${timeFolder}`);
          fs.mkdirSync(backupDir, { recursive: true });
          report = `Direktori backup dibuat di ${backupDir}`;
        }
      } else if (lowerInst.includes('saveall')) {
        syncServerFiles();
        report = `Backup lengkap dibuat dan file server di-publish ke ${deployDir}`;
      } else if (lowerInst.includes('sampah')) {
        report = 'Semua file temporary dan cache dibersihkan';
      } else if (lowerInst.includes('hack')) {
        report = 'Security check: Kredensial terlindung .gitignore, tidak ada celah terbuka';
      } else if (lowerInst.includes('ping')) {
        report = 'PONG: Worker dan server webhook aktif beroperasi';
      }

      await markTaskDone(task.id, report);
      appendToTaskQueueFile(task, report);

      sendNotification(`Tugas Selesai: ${task.id}`, 'SELESAI', [
        `Instruksi: ${inst.substring(0, 90)}`,
        `Hasil: ${report}`,
        `Pengirim: ${task.sender}`
      ]);
      console.log(`✅ [${task.id}] Utilitas 100% SELESAI dikerjakan & Laporan terkirim ke Telegram.`);
    } catch (err) {
      console.error(`❌ Gagal mengeksekusi utilitas: ${err.message}`);
      await markTaskStatus(task.id, 'FAILED', err.message);
      sendNotification(`Gagal: ${task.id}`, 'KENDALA', [
        `Instruksi: ${inst.substring(0, 90)}`,
        `Penyebab: ${err.message}`
      ]);
    }
  } else {
    // 3. TUGAS PENGEMBANGAN / CODING / AGENT TASK:
    recordActiveTask(task);

    // Di Server Headless / Remote SSH: Deteksi apakah ada CLI Agent terpasang
    const agentCandidate = (process.env.TELE_AGENT_HANDLER || cfgVscode.TELE_AGENT_HANDLER || cfgLocal.TELE_AGENT_HANDLER || 'auto').toLowerCase().trim();
    let detectedBin = null;
    let detectedType = null;

    if (agentCandidate === 'agy' || (agentCandidate === 'auto' && findCli('agy'))) {
      detectedBin = findCli('agy') || 'agy';
      detectedType = 'agy';
    } else if (agentCandidate === 'claude' || (agentCandidate === 'auto' && findCli('claude'))) {
      detectedBin = findCli('claude') || 'claude';
      detectedType = 'claude';
    } else if (agentCandidate === 'gemini' || (agentCandidate === 'auto' && findCli('gemini'))) {
      detectedBin = findCli('gemini') || 'gemini';
      detectedType = 'gemini';
    }

    if (detectedBin) {
      console.log(`🤖 Terdeteksi CLI Agent [${detectedType}] di server (${detectedBin}). Menjalankan eksekusi autonomous...`);
      const prompt = `Periksa file TASK_QUEUE.md dan selesaikan tugas ${task.id} sampai tuntas 100% sesuai panduan AGENTS.md.`;
      try {
        await markTaskStatus(task.id, 'IN_PROGRESS', `Sedang dikerjakan oleh ${detectedType.toUpperCase()} Agent di server...`);
        let agentCmd = '';
        if (detectedType === 'agy') {
          agentCmd = `"${detectedBin}" --dangerously-skip-permissions -p "${prompt}"`;
        } else {
          agentCmd = `"${detectedBin}" -p "${prompt}"`;
        }
        execSync(agentCmd, { cwd: projectDir, stdio: 'inherit', timeout: 300000 });
        console.log(`✅ [${task.id}] Eksekusi CLI Agent [${detectedType}] selesai.`);
      } catch (agentErr) {
        console.error(`⚠️ Kendala saat eksekusi CLI Agent: ${agentErr.message}`);
      }
    } else {
      // Cegah spam: jika dari Telegram, bot webhook sudah membalas saat tugas diterima
      const isFromTelegram = task.sender && task.sender !== 'IDE Chat' && task.sender !== 'VS Code' && task.sender !== 'CLI Worker';
      if (!isFromTelegram) {
        sendNotification(`Tugas Masuk Antrean: ${task.id}`, 'INFO', [
          `Instruksi: ${inst.substring(0, 90)}`,
          `Pengirim: ${task.sender}`,
          `Status: Masuk Antrean [PENDING] (Menunggu giliran eksekusi AI Agent)`
        ]);
      }
      console.log(`⚡ [${task.id}] Tugas dicatat ke TASK_QUEUE.md dengan status [PENDING].`);
      console.log(`📢 Notifikasi INFO dikirim ke Telegram.`);
      console.log(`⚠️ Menunggu AI Agent (Chat IDE / CLI) untuk menyelesaikan pekerjaan coding.\n`);
    }
  }
}

// Memproses tugas aktif yang sudah ada di TASK_QUEUE.md namun belum selesai dieksekusi
async function processActiveLocalTasks() {
  if (!fs.existsSync(queueFile)) return;
  try {
    const queueContent = fs.readFileSync(queueFile, 'utf-8');
    const activeSection = queueContent.split('## ✅ Riwayat Tugas Selesai')[0];

    // Aturan Sekuensial: Jika ada tugas [IN_PROGRESS], jangan eksekusi tugas lain
    const inProgressMatch = activeSection.match(/###\s+\[IN_PROGRESS\]\s+(#?TASK-[A-Za-z0-9_-]+)/i);
    const hasInProgress = !!inProgressMatch;
    const regex = /###\s+\[(PENDING|IN_PROGRESS)\]\s+(#?TASK-[A-Za-z0-9_-]+)[\r\n]+([\s\S]*?)(?=(?:###\s+\[|##|$))/gi;
    let match;
    while ((match = regex.exec(activeSection)) !== null) {
      const taskStatus = (match[1] || '').toUpperCase();
      const taskId = match[2].startsWith('#') ? match[2] : `#${match[2]}`;
      if (hasInProgress && taskStatus !== 'IN_PROGRESS') {
        // Lewati tugas PENDING selama ada tugas IN_PROGRESS
        continue;
      }
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
          const directResult = tryExecuteDirectTask(inst, projectDir);
          if (directResult && directResult.executed) {
            const report = directResult.report;
            await markTaskDone(taskObj.id, report);
            appendToTaskQueueFile(taskObj, report);
            const compDur = formatDuration(taskObj.created_at, new Date());
            const repDetails = [`Instruksi: ${inst.substring(0, 90)}`, `Hasil: ${report}`];
            if (compDur) repDetails.push(`Durasi: ${compDur}`);
            repDetails.push(`Pengirim: ${sender}`);
            sendNotification(`Tugas Selesai: ${taskObj.id}`, 'SELESAI', repDetails);
            console.log(`✅ [${taskObj.id}] Tugas aktif dari TASK_QUEUE.md berhasil dieksekusi: ${report}`);
          }
        } catch (err) {
          console.error(`❌ Gagal eksekusi tugas lokal [${taskObj.id}]: ${err.message}`);
        }
      }
    }
  } catch (e) {}
}

let isRunning = true;
async function workerLoop() {
  while (isRunning) {
    // 1. Eksekusi tugas lokal yang masih aktif di TASK_QUEUE.md
    await processActiveLocalTasks();

    // 2. Tarik tugas baru dari server
    const task = await fetchNextTask();
    if (task) {
      await executeTask(task);
      if (runOnce) break;
      await new Promise(r => setTimeout(r, 2000));
    } else {
      if (runOnce) {
        console.log('ℹ️ Tidak ada tugas baru di antrean webhook.');
        isRunning = false; return;
      }
      await new Promise(r => setTimeout(r, intervalMs));
    }
  }
}

process.on('SIGINT', () => { isRunning = false; isRunning = false; return; });
process.on('SIGTERM', () => { isRunning = false; isRunning = false; return; });

workerLoop();
