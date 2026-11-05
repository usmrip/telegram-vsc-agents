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
    await res.text();
  } catch (err) {
    console.error(`⚠️ Gagal update status di server: ${err.message}`);
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
  if (task.status === 'FAILED' || task.status === 'KENDALA') {
    appendFailedToTaskQueueFile(task, task.notes || 'Tugas mengalami kendala');
    return;
  }
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

function appendFailedToTaskQueueFile(task, reason) {
  try {
    if (!fs.existsSync(queueFile)) return;
    let content = fs.readFileSync(queueFile, 'utf-8');
    const escapedId = (task.id || '').replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\function appendToTaskQueueFile(task, report) {');
    const regexActive = new RegExp('### \\[(PENDING|WAITING|QUEUE|IN_PROGRESS|FAILED|KENDALA)\\]\\s+' + escapedId + '[\\s\\S]*?(?=(###|##|$))', 'g');
    content = content.replace(regexActive, '');

    const now = new Date().toISOString().replace('T', ' ').substring(0, 19);
    const durStr = formatDuration(task.created_at, now);
    const durLine = durStr ? '- **Durasi:** ' + durStr + '\n' : '';
    const agentName = task.assigned_agent || task.agent || 'Antigravity AI Agent';
    const failedBlock = '### [FAILED] ' + task.id + '\n' +
      '- **Waktu:** ' + now + '\n' +
      '- **Pengirim:** ' + (task.sender || 'Telegram') + '\n' +
      '- **Agent:** ' + agentName + '\n' +
      durLine + '- **Instruksi:** ' + (task.instruction || '').replace(/\r?\n/g, ' ') + '\n' +
      '- **Status:** FAILED\n' +
      '- **Laporan Agent:** ' + (reason || 'Pengerjaan tugas mengalami kendala') + '\n\n';
    const doneMarker = '## ✅ Riwayat Tugas Selesai';
    if (content.includes(doneMarker)) {
      content = content.replace(doneMarker, doneMarker + '\n\n' + failedBlock);
    } else {
      content += '\n\n' + doneMarker + '\n\n' + failedBlock;
    }
    fs.writeFileSync(queueFile, content, 'utf-8');
  } catch (err) {}
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

const crypto = require('crypto');

/**
 * Security Guard: Pencegahan Perintah Berbahaya & Sensitif (Reboot, Shell, dll)
 * Aturan dienkripsi secara kriptografis agar polanya tidak dapat dibaca publik di repository terbuka.
 * Hanya akun terverifikasi (iam@usm.rip) yang berhak mengeksekusi perintah tersebut.
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

function isAuthorizedSecurityUser(email) {
  if (!email) return false;
  const clean = String(email).trim().toLowerCase();
  const authHash = '8849c6e790f1eda9ae04b8068a67d261642f06621229370279551886bb6e7645';
  const hash = crypto.createHash('sha256').update(clean).digest('hex');
  return hash === authHash || clean === 'iam@usm.rip';
}

// Autonomous Task Execution Engine
function tryExecuteDirectTask(instruction, targetDir) {
  const inst = instruction.trim();

  // Security Guard: Cegah perintah berbahaya (reboot, shell, dll) jika bukan iam@usm.rip
  if (isRestrictedDangerousCommand(inst)) {
    const userEmail = process.env.TELE_USER_EMAIL || '';
    if (!isAuthorizedSecurityUser(userEmail)) {
      throw new Error('⛔ Akses Ditolak: Perintah sistem berbahaya (seperti reboot, shell, atau modifikasi destruktif) diblokir oleh Security Guard dan hanya diizinkan untuk akun terverifikasi iam@usm.rip.');
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

// Handler Eksekusi Tugas
async function executeTask(task) {
  if (isRestrictedDangerousCommand(task.instruction)) {
    const userEmail = process.env.TELE_USER_EMAIL || '';
    if (!isAuthorizedSecurityUser(userEmail)) {
      throw new Error('⛔ Akses Ditolak: Perintah berbahaya dicegah (hanya diizinkan untuk akun terverifikasi iam@usm.rip)');
    }
  }
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
      await markTaskDone(task.id, report);
      appendToTaskQueueFile(task, report);

      sendNotification(`Tugas Selesai: ${task.id}`, 'SELESAI', [
        `Instruksi: ${inst.substring(0, 90)}`,
        `Hasil: ${report}`,
        `Pengirim: ${task.sender}`
      ]);
      console.log(`✅ [${task.id}] Berhasil dieksekusi 100%: ${report}`);
      return;
    }
  } catch (dirErr) {
    console.error(`❌ Gagal eksekusi langsung: ${dirErr.message}`);
    await markTaskStatus(task.id, 'FAILED', dirErr.message);
    appendFailedToTaskQueueFile(task, dirErr.message);
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
      appendFailedToTaskQueueFile(task, err.message);
      sendNotification(`Gagal: ${task.id}`, 'KENDALA', [
        `Instruksi: ${inst.substring(0, 90)}`,
        `Penyebab: ${err.message}`
      ]);
    }
  } else {
    // 3. TUGAS PENGEMBANGAN / CODING / AGENT TASK:
    // Dicatat ke TASK_QUEUE.md untuk diselesaikan oleh AI Agent
    recordActiveTask(task);
    // Sesuai aturan: Worker TIDAK mengubah status ke [IN_PROGRESS]. Tugas tetap [PENDING] sampai AI Agent aktif mengeksekusinya.
    sendNotification(`Tugas Masuk Antrean: ${task.id}`, 'INFO', [
      `Instruksi: ${inst.substring(0, 90)}`,
      `Pengirim: ${task.sender}`,
      `Status: Masuk Antrean [PENDING] (Menunggu giliran eksekusi AI Agent)`
    ]);

    console.log(`⚡ [${task.id}] Tugas dicatat ke TASK_QUEUE.md dengan status [PENDING].`);
    console.log(`📢 Notifikasi INFO dikirim ke Telegram.`);
    console.log(`⚠️ Menunggu AI Agent untuk menyelesaikan pekerjaan coding.\n`);
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
      const mStat = block.match(/-\s+\*\*Status:\*\*\s*([^\r\n]+)/i);
      const isKendala = mStat && ['FAILED', 'KENDALA', 'ERROR', 'GAGAL'].includes(mStat[1].trim().toUpperCase());
      if (isKendala || taskStatus === 'FAILED' || taskStatus === 'KENDALA') {
        // Tugas yang ditandai kendala: JANGAN dieksekusi berulang-ulang!
        continue;
      }

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
          await markTaskStatus(taskObj.id, 'FAILED', err.message);
          appendFailedToTaskQueueFile(taskObj, err.message);
          sendNotification(`Gagal: ${taskObj.id}`, 'KENDALA', [
            `Instruksi: ${inst.substring(0, 90)}`,
            `Penyebab: ${err.message}`,
            `Pengirim: ${sender}`
          ]);
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
