#!/usr/bin/env node

const dns = require('dns');
if (typeof dns.setDefaultResultOrder === 'function') {
  dns.setDefaultResultOrder('ipv4first');
}
// ==============================================================================
// Task Queue Manager CLI (tele-task)
// Menjembatani perintah dari IDE Chat / Terminal langsung ke Dashboard Server
// ==============================================================================

const fs = require('fs');
const path = require('path');
const os = require('os');
const { execSync } = require('child_process');

const args = process.argv.slice(2);
if (args.length === 0 || args[0] === '-h' || args[0] === '--help') {
  console.log('Penggunaan:');
  console.log('  tele-task add "<Instruksi>" [--sender "IDE Chat"] [--status IN_PROGRESS] [--notes "Catatan"]');
  console.log('  tele-task pull');
  console.log('  tele-task done "<TASK-ID>" "<Laporan hasil>"');
  console.log('  tele-task failed "<TASK-ID>" "<Penyebab kendala>"');
  console.log('  tele-task list');
  console.log('  tele-task whoami');
  console.log('  tele-task upload <path_to_file> [--caption "Catatan"] [--task "#TASK-xxx"] [--notify]');
  process.exit(0);
}

const command = args[0].toLowerCase();

function parseJsonConfig(filePath) {
  if (!fs.existsSync(filePath)) return {};
  try {
    const raw = fs.readFileSync(filePath, 'utf-8');
    const parsed = JSON.parse(raw);
    const res = {};
    if (parsed['telegramNotifier.webhookUrl']) res.AGENTS_TELE_WEBHOOK_URL = parsed['telegramNotifier.webhookUrl'];
    if (parsed['telegramNotifier.secretKey']) res.AGENTS_TELE_SECRET = parsed['telegramNotifier.secretKey'];
    if (parsed['telegramNotifier.project']) res.AGENTS_TELE_PROJECT = parsed['telegramNotifier.project'];
    return res;
  } catch (e) {
    return {};
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

const cwd = process.cwd();
const homeDir = os.homedir();
const globalConf = path.join(homeDir, '.config', 'tele-notify', 'config.env');
const localVscodeConf = path.join(cwd, '.vscode', 'tele-vs-code', 'config.env');
const localConf = path.join(cwd, 'config.env');
const localEnv = path.join(cwd, '.env');

const cfgGlobal = parseEnvFile(globalConf);
const cfgVscode = parseEnvFile(localVscodeConf);
const cfgLocal = parseEnvFile(localConf);
const cfgEnv = parseEnvFile(localEnv);
const localSettingsJson = path.join(cwd, '.vscode', 'settings.json');
const cfgSettings = parseJsonConfig(localSettingsJson);

const webhookUrl = process.env.AGENTS_TELE_WEBHOOK_URL ||
                   cfgSettings.AGENTS_TELE_WEBHOOK_URL ||
                   cfgVscode.AGENTS_TELE_WEBHOOK_URL ||
                   cfgLocal.AGENTS_TELE_WEBHOOK_URL ||
                   cfgEnv.AGENTS_TELE_WEBHOOK_URL ||
                   cfgGlobal.AGENTS_TELE_WEBHOOK_URL ||
                   'https://biru.co.id/tele-vs-code/webhook';

const secretKey = process.env.AGENTS_TELE_SECRET ||
                  cfgSettings.AGENTS_TELE_SECRET ||
                  cfgVscode.AGENTS_TELE_SECRET ||
                  cfgLocal.AGENTS_TELE_SECRET ||
                  cfgEnv.AGENTS_TELE_SECRET ||
                  cfgGlobal.AGENTS_TELE_SECRET ||
                  '';

let projectKey = process.env.AGENTS_TELE_PROJECT ||
                 cfgSettings.AGENTS_TELE_PROJECT ||
                 cfgVscode.AGENTS_TELE_PROJECT ||
                 cfgLocal.AGENTS_TELE_PROJECT ||
                 cfgEnv.AGENTS_TELE_PROJECT ||
                 cfgGlobal.AGENTS_TELE_PROJECT ||
                 '';

for (let i = 1; i < args.length; i++) {
  if (args[i] === '--project' || args[i] === '-p') {
    projectKey = args[++i];
  }
}

const normalizedUrl = webhookUrl.endsWith('/') ? webhookUrl : `${webhookUrl}/`;
const queueFile = path.join(cwd, 'TASK_QUEUE.md');

function getNotifyScript() {
  const candidates = [
    path.join(__dirname, 'tele-notify.js'),
    path.join(cwd, '.vscode', 'tele-vs-code', 'tele-notify.js'),
    path.join(cwd, 'tele-notify.js')
  ];
  return candidates.find(p => fs.existsSync(p)) || null;
}

// Cek apakah ada tugas lain yang sedang [IN_PROGRESS] di antrean aktif
function getOtherActiveInProgressTask(currentTaskId = '') {
  if (!fs.existsSync(queueFile)) return null;
  try {
    const content = fs.readFileSync(queueFile, 'utf-8');
    const activeSection = content.split('## ✅ Riwayat Tugas Selesai')[0];
    const regex = /###\s+\[IN_PROGRESS\]\s+(#?TASK-[A-Za-z0-9_-]+)/gi;
    let match;
    const cleanCurrent = (currentTaskId || '').toLowerCase().replace(/^#/, '');
    while ((match = regex.exec(activeSection)) !== null) {
      const foundId = match[1];
      const cleanFound = foundId.toLowerCase().replace(/^#/, '');
      if (cleanFound !== cleanCurrent) {
        return foundId.startsWith('#') ? foundId : '#' + foundId;
      }
    }
  } catch (e) {}
  return null;
}

// Format durasi pengerjaan ke teks yang mudah dibaca
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

// Selain cari di lokal, cari juga di remote server Biru
async function fetchRemoteTask(taskId) {
  if (!secretKey || !taskId) return null;
  try {
    const cleanId = taskId.startsWith('#') ? taskId : '#' + taskId;
    const url = `${normalizedUrl}?action=get_task&secret=${secretKey}&id=${encodeURIComponent(cleanId)}`;
    const res = await safeFetch(url, { timeout: 8000 }, 2);
    if (res.ok) {
      const data = await res.json();
      if (data.ok && data.task) return data.task;
    }
  } catch (e) {}
  return null;
}

function updateLocalQueueFile(taskId, instruction, sender, status, notes = '', attachmentUrl = '', attachmentType = '', attachmentName = '', agent = 'Antigravity AI Agent') {
  try {
    if (!fs.existsSync(queueFile)) {
      fs.writeFileSync(queueFile, '# 📋 TASK QUEUE (Antrean Tugas Agent)\n\n## ⏳ Tugas Aktif\n\n## ✅ Riwayat Tugas Selesai\n', 'utf-8');
    }
    let content = fs.readFileSync(queueFile, 'utf-8');
    const now = new Date().toISOString().replace('T', ' ').substring(0, 19);
    const escapedId = (taskId || '').replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
    const rawShort = (taskId || '').trim().replace(/^#/, '');
    const clean4 = rawShort.length >= 4 ? rawShort.slice(-4) : rawShort;
    const clean4Escaped = clean4.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
    const idPattern = `(?:#?(?:TASK-)?[a-zA-Z0-9_-]*${clean4Escaped}|${escapedId})`;

    if (status === 'DONE' || status === 'FAILED') {
      let finalInstruction = instruction;
      let finalSender = sender;

      // Ambil instruksi dan pengirim dari blok aktif jika parameter kosong
      const regexActive = new RegExp(`### \\[(PENDING|WAITING|QUEUE|IN_PROGRESS)\\]\\s+${idPattern}[\\s\\S]*?(?=(###|##|$))`, 'g');
      const match = content.match(regexActive);
      if (match && match[0]) {
        if (!finalInstruction) {
          const mInst = match[0].match(/- \*\*Instruksi:\*\*\s*(.+)/);
          if (mInst) finalInstruction = mInst[1].trim();
        }
        if (!finalSender || finalSender === 'IDE Chat') {
          const mSend = match[0].match(/- \*\*Pengirim:\*\*\s*(.+)/);
          if (mSend) finalSender = mSend[1].trim();
        }
      }
      content = content.replace(regexActive, '');

      const cleanInstruction = (finalInstruction || 'Perintah dari IDE Chat').replace(/\r?\n/g, ' ');
      let taskStartTime = '';
      if (match && match[0]) {
        const mTime = match[0].match(/- \*\*Waktu:\*\*\s*([^\r\n]+)/);
        if (mTime) taskStartTime = mTime[1].trim();
      }
      const durationStr = formatDuration(taskStartTime, now);
      const durationLine = durationStr ? `- **Durasi:** ${durationStr}\n` : '';
      const doneBlock = `### [${status}] ${taskId}\n- **Waktu:** ${now}\n- **Pengirim:** ${finalSender || 'IDE Chat'}\n- **Agent:** ${agent || 'Antigravity AI Agent'}\n${durationLine}- **Instruksi:** ${cleanInstruction}\n- **Status:** ${status}\n- **Laporan Agent:** ${notes}\n\n`;
      const doneMarker = '## ✅ Riwayat Tugas Selesai';
      if (content.includes(doneMarker)) {
        content = content.replace(doneMarker, `${doneMarker}\n\n${doneBlock}`);
      } else {
        content += `\n\n${doneMarker}\n\n${doneBlock}`;
      }
    } else if (status === 'IN_PROGRESS') {
      // Jika tugas sudah ada sebagai [PENDING] atau [WAITING], ubah statusnya menjadi [IN_PROGRESS]
      const regexPending = new RegExp(`### \\[(PENDING|WAITING|QUEUE)\\]\\s+${idPattern}[\\s\\S]*?(?=(###|##|$))`, 'i');
      const matchPending = content.match(regexPending);
      if (matchPending && matchPending[0]) {
        let block = matchPending[0];
        block = block.replace(/### \[(PENDING|WAITING|QUEUE)\]/i, '### [IN_PROGRESS]');
        block = block.replace(/- \*\*Status:\*\*\s*[^\r\n]+/i, '- **Status:** IN_PROGRESS');
        if (notes) {
          if (block.includes('- **Catatan:**')) {
            block = block.replace(/- \*\*Catatan:\*\*\s*[^\r\n]+/i, `- **Catatan:** ${notes}`);
          } else {
            block = block.trimEnd() + `\n- **Catatan:** ${notes}\n\n`;
          }
        }
        content = content.replace(regexPending, block);
      } else if (!content.includes(taskId)) {
        let attachLines = '';
        if (attachmentUrl) {
          attachLines = `\n- **Lampiran:** [${attachmentName || 'Buka File'}](${attachmentUrl}) (${attachmentType || 'file'})`;
        }
        const activeBlock = `### [IN_PROGRESS] ${taskId}\n- **Waktu:** ${now}\n- **Pengirim:** ${sender || 'IDE Chat'}\n- **Agent:** ${agent || 'Antigravity AI Agent'}\n- **Instruksi:** ${(instruction || '').replace(/\r?\n/g, ' ')}${attachLines}\n- **Status:** IN_PROGRESS\n- **Catatan:** ${notes || 'Sedang dikerjakan oleh AI Agent...'}\n\n`;
        const activeMarker = '## ⏳ Tugas Aktif';
        if (content.includes(activeMarker)) {
          content = content.replace(activeMarker, `${activeMarker}\n\n${activeBlock}`);
          content = content.replace(/\*\(Tidak ada tugas aktif[^)]*\)\*/g, '');
        } else {
          content = `${activeMarker}\n\n${activeBlock}\n\n${content}`;
        }
      }
    } else {
      // Status PENDING / WAITING: Masuk ke antrean aktif
      if (!content.includes(taskId)) {
        let attachLines = '';
        if (attachmentUrl) {
          attachLines = `\n- **Lampiran:** [${attachmentName || 'Buka File'}](${attachmentUrl}) (${attachmentType || 'file'})`;
        }
        const activeBlock = `### [${status}] ${taskId}\n- **Waktu:** ${now}\n- **Pengirim:** ${sender}\n- **Instruksi:** ${instruction.replace(/\r?\n/g, ' ')}${attachLines}\n- **Status:** ${status}\n- **Catatan:** ${notes || 'Menunggu giliran eksekusi oleh AI Agent'}\n\n`;
        const activeMarker = '## ⏳ Tugas Aktif';
        if (content.includes(activeMarker)) {
          content = content.replace(activeMarker, `${activeMarker}\n\n${activeBlock}`);
          content = content.replace(/\*\(Tidak ada tugas aktif[^)]*\)\*/g, '');
        } else {
          content = `${activeMarker}\n\n${activeBlock}\n\n${content}`;
        }
      }
    }
    fs.writeFileSync(queueFile, content, 'utf-8');
  } catch (e) {}
}

async function run() {
  if (command === 'add' || command === 'push') {
    const instruction = args[1] || '';
    if (!instruction) {
      console.error('[ERROR] Instruksi tugas wajib disertakan!');
      process.exit(1);
    }

    let sender = 'IDE Chat';
    let status = 'IN_PROGRESS';
    let notes = 'Sedang dikerjakan oleh AI Agent...';
    let cmd = 'tele-task add';

    for (let i = 2; i < args.length; i++) {
      if (args[i] === '--sender' || args[i] === '-u') sender = args[++i] || sender;
      else if (args[i] === '--status' || args[i] === '-s') status = (args[++i] || status).toUpperCase();
      else if (args[i] === '--notes' || args[i] === '-n') notes = args[++i] || notes;
      else if (args[i] === '--command' || args[i] === '-c') cmd = args[++i] || cmd;
    }

    try {
      let url = `${normalizedUrl}?action=push&secret=${secretKey}`;
      if (projectKey) url += `&project=${encodeURIComponent(projectKey)}`;
      const params = new URLSearchParams({ instruction, sender, status, notes, command: cmd, is_ide: '1' });
      const res = await fetch(url, { method: 'POST', body: params });
      const data = await res.json();

      if (data.ok) {
        console.log(`✅ [${data.id}] Berhasil dicatat ke Dashboard Status Server (${status}).`);
        updateLocalQueueFile(data.id, instruction, sender, status, notes);

        // Kirim notifikasi ke Telegram bahwa tugas dari IDE Chat sedang diproses
        const notifyJs = getNotifyScript();
        if (notifyJs) {
          try {
            const shortInst = instruction.length > 80 ? (instruction.substring(0, 77) + '...') : instruction;
            const statArg = status === 'IN_PROGRESS' ? 'PROSES' : (status === 'DONE' ? 'SELESAI' : 'INFO');
            execSync(`node "${notifyJs}" "Tugas IDE: ${shortInst.replace(/"/g, '\\"')}" --status ${statArg} --detail "Instruksi: ${instruction.replace(/"/g, '\\"')}" --detail "Pengirim: ${sender}" --detail "ID: ${data.id}"`, { stdio: 'inherit' });
          } catch (e) {}
        }
      } else {
        console.error(`❌ Gagal mencatat ke server: ${data.error || 'Unknown error'}`);
      }
    } catch (err) {
      console.error(`❌ Network error: ${err.message}`);
    }
  } else if (command === 'pull') {
    try {
      const operator = `${os.userInfo().username}@${os.hostname()}`;
      let url = `${normalizedUrl}?action=pull&secret=${secretKey}&limit=5&format=json&mark=0&workspace_folder=${encodeURIComponent(cwd)}&operator=${encodeURIComponent(operator)}`;
      if (projectKey) url += `&project=${encodeURIComponent(projectKey)}`;
      const res = await fetch(url);
      const data = await res.json();
      if (data.ok && Array.isArray(data.tasks) && data.tasks.length > 0) {
        console.log(`📥 Menarik ${data.tasks.length} tugas baru dari server...`);
        for (const t of data.tasks) {
          let localAbsUrl = '';
          if (t.attachment_url) {
            try {
              const attachDir = path.join(cwd, 'attachments', t.id.replace(/[^a-zA-Z0-9_-]/g, ''));
              if (!fs.existsSync(attachDir)) fs.mkdirSync(attachDir, { recursive: true });
              const localFilePath = path.join(attachDir, t.attachment_name || 'lampiran.bin');
              if (!fs.existsSync(localFilePath)) {
                const fRes = await fetch(t.attachment_url);
                if (fRes.ok) {
                  const fBuf = Buffer.from(await fRes.arrayBuffer());
                  fs.writeFileSync(localFilePath, fBuf);
                  console.log(`   📎 Lampiran diunduh: ${localFilePath}`);
                }
              }
              localAbsUrl = `file:///${localFilePath.replace(/\\/g, '/')}`;
            } catch (dErr) {
              console.error(`   ⚠️ Gagal download lampiran: ${dErr.message}`);
            }
          }
          const attachUrlToShow = localAbsUrl || t.attachment_url;
          const targetStatus = (t.status === 'DONE' || t.status === 'FAILED') ? t.status : (t.status === 'IN_PROGRESS' ? 'IN_PROGRESS' : 'PENDING');
          const targetNote = targetStatus === 'IN_PROGRESS' ? 'Sedang dikerjakan oleh AI Agent' : 'Menunggu giliran eksekusi oleh AI Agent';
          updateLocalQueueFile(t.id, t.instruction, t.sender, targetStatus, targetNote, attachUrlToShow, t.attachment_type, t.attachment_name);
          console.log(`   ✨ [${t.id}] ${t.instruction.substring(0, 80)}...`);
        }
      } else {
        console.log('ℹ️ Tidak ada tugas baru di antrean server.');
      }
    } catch (err) {
      console.error(`❌ Gagal menarik tugas: ${err.message}`);
    }
    } else if (command === 'start' || command === 'proses' || command === 'in_progress') {
    const taskId = args[1] || '';
    const escapedId = (taskId || '').replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
    const note = args[2] || 'Sedang dikerjakan oleh AI Agent...';

    if (!taskId) {
      console.error('[ERROR] Task ID wajib disertakan! Contoh: node tele-task.js start "#TASK-123"');
      process.exit(1);
    }

    // Aturan Sekuensial: Periksa apakah ada tugas lain yang masih [IN_PROGRESS]
    let forceStart = false;
    for (let i = 2; i < args.length; i++) {
      if (args[i] === '--force' || args[i] === '-f') forceStart = true;
    }
    const otherInProgress = getOtherActiveInProgressTask(taskId);
    if (otherInProgress && !forceStart) {
      console.error(`⚠️ [BLOCKED] Tugas ${otherInProgress} saat ini masih berstatus [IN_PROGRESS].`);
      console.error(`   Sesuai aturan, tugas harus dikerjakan satu per satu secara sekuensial.`);
      console.error(`   Tugas ${taskId} tetap [PENDING] sampai tugas ${otherInProgress} selesai 100%.`);
      process.exit(1);
    }

    try {
      const url = `${normalizedUrl}?action=start&secret=${secretKey}&id=${encodeURIComponent(taskId)}`;
      const params = new URLSearchParams({ notes: note, status: 'IN_PROGRESS' });
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      });
      const data = await res.json();
      if (data.ok) {
        console.log(`⚡ [${taskId}] Status di server berhasil diperbarui ke: IN_PROGRESS`);

        // Perbarui status di TASK_QUEUE.md lokal menjadi [IN_PROGRESS]
        updateLocalQueueFile(taskId, '', '', 'IN_PROGRESS', note);

        // 1. Cari instruksi dari TASK_QUEUE.md lokal
        let foundInst = '';
        if (fs.existsSync(queueFile)) {
          const qText = fs.readFileSync(queueFile, 'utf-8');
          const m = new RegExp(`### \\[.*?\\]\\s+${taskId}[\\s\\S]*?-\\s+\\*\\*Instruksi:\\*\\*\\s*([^\\r\\n]+)`, 'i').exec(qText);
          if (m) foundInst = m[1].trim();
        }

        // 2. SELAIN CARI DI LOKAL, CARI JUGA DI REMOTE SERVER
        let remoteTask = null;
        if (!foundInst || (data && data.instruction)) {
          foundInst = (data && data.instruction) ? data.instruction : '';
          if (!foundInst) {
            remoteTask = await fetchRemoteTask(taskId);
            if (remoteTask && remoteTask.instruction) foundInst = remoteTask.instruction;
          }
        }

        // Jika di TASK_QUEUE.md lokal tugas belum ada sama sekali, sinkronkan dari data remote/server
        if (foundInst) {
          const senderName = (data && data.sender) || (remoteTask && remoteTask.sender) || 'Telegram User';
          const agentName = (data && data.agent) || (remoteTask && remoteTask.agent) || 'Antigravity AI Agent';
          const attachUrl = (data && data.attachment_url) || (remoteTask && remoteTask.attachment_url) || '';
          const attachType = (data && data.attachment_type) || (remoteTask && remoteTask.attachment_type) || '';
          const attachName = (data && data.attachment_name) || (remoteTask && remoteTask.attachment_name) || '';
          updateLocalQueueFile(taskId, foundInst, senderName, 'IN_PROGRESS', note, attachUrl, attachType, attachName, agentName);
        }

        const notifyJs = getNotifyScript();
        // Cegah notifikasi ganda: Hanya kirim jika server belum mengirimkan notifikasi mulai (1x saja)
        const alreadyNotified = (data && (data.start_notified || data.notified_now || data.already_notified));
        if (notifyJs && !alreadyNotified) {
          try {
            const details = [];
            if (foundInst) details.push(`--detail "Perintah: ${foundInst.replace(/"/g, '\\"')}"`);
            details.push(`--detail "Catatan: ${note.replace(/"/g, '\\"')}"`);
            details.push(`--detail "Status: Sedang dikerjakan oleh AI Agent"`);
            execSync(`node "${notifyJs}" "Memulai Pekerjaan: ${taskId}" --status PROSES ${details.join(' ')}`, { stdio: 'inherit' });
          } catch (e) {}
        }
      } else {
        console.error(`❌ Gagal update server: ${data.error}`);
        if (fs.existsSync(queueFile)) {
          const qText = fs.readFileSync(queueFile, 'utf-8');
          if (new RegExp(`### \\[.*?\\]\\s+${escapedId}`, 'i').test(qText)) {
            console.log(`ℹ️ Memperbarui status tugas lokal [${taskId}] ke IN_PROGRESS di TASK_QUEUE.md...`);
            updateLocalQueueFile(taskId, '', 'IDE Chat', 'IN_PROGRESS', note);
          }
        }
      }
    } catch (err) {
      console.error(`❌ Network error: ${err.message}`);
    }
  } else if (command === 'done' || command === 'failed') {
    const taskId = args[1] || '';
    const escapedId = (taskId || '').replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
    const report = args[2] || (command === 'done' ? 'Selesai 100%' : 'Gagal');

    if (!taskId) {
      console.error('[ERROR] Task ID wajib disertakan!');
      process.exit(1);
    }

    let cmd = '';
    for (let i = 3; i < args.length; i++) {
      if (args[i] === '--command' || args[i] === '-c') cmd = args[++i] || cmd;
    }
    try {
      let computedDuration = '';
      let localStartTime = '';
      if (fs.existsSync(queueFile)) {
        const qText = fs.readFileSync(queueFile, 'utf-8');
        const mActive = new RegExp(`### \\[.*?\\]\\s+${escapedId}[\\s\\S]*?-\\s+\\*\\*Waktu:\\*\\*\\s*([^\\r\\n]+)`, 'i').exec(qText);
        if (mActive) {
          localStartTime = mActive[1].trim();
          computedDuration = formatDuration(localStartTime, new Date());
        }
      }

      // SELAIN CARI DI LOKAL, CARI JUGA DI REMOTE SERVER (untuk waktu mulai / durasi akurat)
      let remoteTaskDone = null;
      if (!localStartTime) {
        remoteTaskDone = await fetchRemoteTask(taskId);
        if (remoteTaskDone && remoteTaskDone.created_at) {
          computedDuration = formatDuration(remoteTaskDone.created_at, new Date());
        }
      }

      const act = command === 'done' ? 'done' : 'failed';
      const url = `${normalizedUrl}?action=${act}&secret=${secretKey}&id=${encodeURIComponent(taskId)}`;
      const paramsObj = { notes: report, result: report };
      if (computedDuration) paramsObj.duration = computedDuration;
      if (cmd) paramsObj.command = cmd;
      const params = new URLSearchParams(paramsObj);
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      });
      const data = await res.json();

      if (data.ok) {
        console.log(`✅ [${taskId}] Status di server berhasil diperbarui ke: ${command.toUpperCase()}`);
        updateLocalQueueFile(taskId, '', 'IDE Chat', command.toUpperCase(), report);

        // Kirim laporan ke Telegram via tele-notify
        const notifyJs = getNotifyScript();
        if (notifyJs) {
          try {
            const statArg = command === 'done' ? 'SELESAI' : 'KENDALA';
            const notifyArgs = [];
            if (computedDuration) notifyArgs.push(`--duration "${computedDuration}"`);
            notifyArgs.push(`--detail "${report.replace(/"/g, '\\"')}"`);
            execSync(`node "${notifyJs}" "Tugas: ${taskId}" --status ${statArg} ${notifyArgs.join(' ')}`, { stdio: 'inherit' });
          } catch (e) {}
        }
      } else {
        console.error(`❌ Gagal update server: ${data.error}`);
        if (fs.existsSync(queueFile)) {
          const qText = fs.readFileSync(queueFile, 'utf-8');
          if (new RegExp(`### \\[.*?\\]\\s+${escapedId}`, 'i').test(qText)) {
            console.log(`ℹ️ Memperbarui status tugas lokal [${taskId}] ke ${command.toUpperCase()} di TASK_QUEUE.md...`);
            updateLocalQueueFile(taskId, '', 'IDE Chat', command.toUpperCase(), report);
          }
        }
      }
    } catch (err) {
      console.error(`❌ Network error: ${err.message}`);
    }
  } else if (command === 'whoami') {
    const operator = `${os.userInfo().username}@${os.hostname()}`;
    console.log(`\n============================================================`);
    console.log(`👤 IDENTIFIKASI WORKSPACE TELE-VS-CODE`);
    console.log(`============================================================`);
    console.log(`📂 Folder Workspace : ${cwd}`);
    console.log(`💻 Operator Host     : ${operator}`);
    console.log(`🌐 Server Webhook   : ${normalizedUrl}`);
    console.log(`🏷️  Proyek Terpilih  : ${projectKey || '(Semua Proyek / Default)'}`);
    console.log(`🔑 API Secret       : ${secretKey ? (secretKey.substring(0, 8) + '••••••••') : '(Belum diatur)'}`);

    if (secretKey) {
      try {
        const url = `${normalizedUrl}?action=projects&secret=${secretKey}`;
        const res = await fetch(url);
        const data = await res.json();
        if (data.ok && Array.isArray(data.projects)) {
          console.log(`\n📋 Daftar Proyek di Server Biru:`);
          for (const p of data.projects) {
            const isMatch = (projectKey && (p.code === projectKey.toLowerCase() || p.project_key === projectKey || p.name.toLowerCase() === projectKey.toLowerCase())) || (!projectKey && p.id === data.projects[0].id);
            const mark = isMatch ? ' 👉 [AKTIF]' : '   ';
            const folderInfo = p.last_workspace_folder ? ` (Folder: ${p.last_workspace_folder})` : '';
            console.log(`${mark} [#${p.code || p.id}] ${p.name}${folderInfo}`);
          }
        }
      } catch (e) {
        console.log(`⚠️ Tidak dapat menghubungi server untuk list proyek: ${e.message}`);
      }
    }
    console.log(`============================================================\n`);
  } else if (command === 'list') {
    try {
      const url = `${normalizedUrl}?action=status&secret=${secretKey}`;
      const res = await fetch(url);
      const data = await res.json();
      if (data.ok) {
        console.log('📊 Ringkasan Antrean Dashboard:');
        console.log(`   Menunggu (PENDING)    : ${data.summary.PENDING}`);
        console.log(`   Diproses (IN_PROGRESS): ${data.summary.IN_PROGRESS}`);
        console.log(`   Selesai  (DONE)       : ${data.summary.DONE}`);
        console.log(`   Gagal    (FAILED)     : ${data.summary.FAILED}\n`);
      }
    } catch (err) {
      console.error(`❌ Gagal mengambil status: ${err.message}`);
    }
  } else if (command === 'upload') {
    const filePath = args[1];
    if (!filePath) {
      console.error('[ERROR] Path file wajib disertakan! Contoh: node tele-task.js upload screenshot.png --caption "Hasil pengujian"');
      process.exit(1);
    }
    const absPath = path.isAbsolute(filePath) ? filePath : path.resolve(cwd, filePath);
    if (!fs.existsSync(absPath)) {
      console.error(`[ERROR] File tidak ditemukan di: ${absPath}`);
      process.exit(1);
    }
    let caption = '';
    let taskId = '';
    let notifyTele = false;
    for (let i = 2; i < args.length; i++) {
      if (args[i] === '--caption' || args[i] === '-c') caption = args[++i] || '';
      else if (args[i] === '--task' || args[i] === '-t') taskId = args[++i] || '';
      else if (args[i] === '--notify' || args[i] === '-n') notifyTele = true;
    }

    try {
      console.log(`📤 Mengunggah berkas "${path.basename(absPath)}" ke Server Biru untuk dokumentasi...`);
      const fileBuf = fs.readFileSync(absPath);
      const blob = new Blob([fileBuf]);
      const formData = new FormData();
      formData.append('file', blob, path.basename(absPath));
      if (caption) formData.append('caption', caption);
      if (taskId) formData.append('task_id', taskId);
      if (projectKey) formData.append('project', projectKey);
      formData.append('uploaded_by', `${os.userInfo().username}@${os.hostname()} (CLI tele-task)`);
      if (notifyTele) formData.append('notify_telegram', '1');

      const baseUrl = normalizedUrl.replace(/\/webhook\/?$/, '/').replace(/\/$/, '');
      const uploadUrl = `${baseUrl}/?action=upload_media&secret=${encodeURIComponent(secretKey)}`;
      const res = await fetch(uploadUrl, { method: 'POST', body: formData });
      const data = await res.json();
      if (res.ok && data.ok) {
        console.log(`✅ [SUKSES] Berkas berhasil diunggah ke Server Biru!`);
        console.log(`📁 Nama Berkas : ${data.file_name}`);
        console.log(`🌐 URL Server   : ${data.file_url}`);
        console.log(`📦 Ukuran      : ${(data.file_size / 1024).toFixed(1)} KB`);
        if (data.task_id) console.log(`🆔 ID Tugas    : ${data.task_id}`);
      } else {
        console.error(`❌ Gagal mengunggah: ${data.error || res.status}`);
      }
    } catch (err) {
      console.error(`❌ Network error: ${err.message}`);
    }
  }
}

run();

