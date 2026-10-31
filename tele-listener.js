#!/usr/bin/env node
// ==============================================================================
// Telegram Agent Listener (Task Queue / Inbox Bridge)
// Menerima perintah dari Telegram dan mencatatnya ke TASK_QUEUE.md
// ==============================================================================

const fs = require('fs');
const path = require('path');
const os = require('os');
const { execSync } = require('child_process');

// ------------------------------------------------------------------------------
// 1. Parsing Argumen & Konfigurasi
// ------------------------------------------------------------------------------
const args = process.argv.slice(2);
let projectDir = process.cwd();
let overrideToken = '';
let overrideChatId = '';

for (let i = 0; i < args.length; i++) {
  if (args[i] === '-p' || args[i] === '--project') {
    projectDir = path.resolve(args[++i] || '.');
  } else if (args[i] === '-t' || args[i] === '--token') {
    overrideToken = args[++i] || '';
  } else if (args[i] === '-c' || args[i] === '--chat-id') {
    overrideChatId = args[++i] || '';
  } else if (args[i] === '-h' || args[i] === '--help') {
    console.log(`
Penggunaan:
  node tele-listener.js [opsi]
  tele-listener [opsi]

Opsi:
  -p, --project <path>    Folder proyek target (default: direktori saat ini)
  -t, --token <token>     Override Telegram Bot Token
  -c, --chat-id <id>      Override Telegram Chat ID
  -h, --help              Tampilkan bantuan ini
`);
    process.exit(0);
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

// Cari config dari beberapa sumber
const homeDir = os.homedir();
const globalConf = path.join(homeDir, '.config', 'tele-notify', 'config.env');
const localConfTelegram = path.join(projectDir, '.env.telegram');
const localConfEnv = path.join(projectDir, '.env');

const cfgGlobal = parseEnvFile(globalConf);
const cfgLocalTg = parseEnvFile(localConfTelegram);
const cfgLocalEnv = parseEnvFile(localConfEnv);

const botToken = overrideToken || 
                 process.env.TELEGRAM_BOT_TOKEN || 
                 cfgLocalTg.TELEGRAM_BOT_TOKEN || 
                 cfgLocalEnv.TELEGRAM_BOT_TOKEN || 
                 cfgGlobal.TELEGRAM_BOT_TOKEN;

const chatId = overrideChatId || 
               process.env.TELEGRAM_CHAT_ID || 
               cfgLocalTg.TELEGRAM_CHAT_ID || 
               cfgLocalEnv.TELEGRAM_CHAT_ID || 
               cfgGlobal.TELEGRAM_CHAT_ID;

if (!botToken || !chatId) {
  console.error('❌ Error: TELEGRAM_BOT_TOKEN atau TELEGRAM_CHAT_ID belum diatur!');
  console.error('Pastikan sudah dikonfigurasi di ~/.config/tele-notify/config.env atau .env');
  process.exit(1);
}

const projectName = path.basename(projectDir);
const taskQueueFile = path.join(projectDir, 'TASK_QUEUE.md');

// Inisialisasi TASK_QUEUE.md jika belum ada
function initTaskQueue() {
  if (!fs.existsSync(taskQueueFile)) {
    const header = `# 📋 TASK QUEUE (Antrean Tugas Agent) - Proyek: ${projectName}

> File ini diperbarui otomatis oleh **Telegram Listener** dan dibaca oleh **Agent AI (Antigravity)**.
> Status yang valid: \`[PENDING]\`, \`[IN_PROGRESS]\`, \`[DONE]\`, \`[FAILED]\`.

---

## ⏳ Tugas Aktif

---

## ✅ Riwayat Tugas Selesai
`;
    fs.writeFileSync(taskQueueFile, header, 'utf-8');
    console.log(`📄 File antrean baru dibuat: ${taskQueueFile}`);
  }
}

initTaskQueue();

// ------------------------------------------------------------------------------
// 2. Helper Telegram API
// ------------------------------------------------------------------------------
const TELEGRAM_API = `https://api.telegram.org/bot${botToken}`;

function escapeHtml(text) {
  if (!text) return '';
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

async function sendTelegramMessage(targetChatId, textHtml, replyToMessageId = null) {
  try {
    const payload = {
      chat_id: targetChatId,
      text: textHtml,
      parse_mode: 'HTML',
      disable_web_page_preview: true
    };
    if (replyToMessageId) {
      payload.reply_to_message_id = replyToMessageId;
    }

    const res = await fetch(`${TELEGRAM_API}/sendMessage`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!data.ok) {
      console.error('❌ Gagal kirim balasan Telegram:', data.description);
    }
    return data;
  } catch (err) {
    console.error('❌ Error koneksi Telegram sendMessage:', err.message);
  }
}

// ------------------------------------------------------------------------------
// 3. Manajemen TASK_QUEUE.md
// ------------------------------------------------------------------------------
function addTaskToQueue(taskInstruction, senderName) {
  initTaskQueue();
  const now = new Date();
  const dateStr = now.toLocaleDateString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric' });
  const timeStr = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).replace(/\./g, ':');
  const taskId = `#TASK-${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}${String(now.getDate()).padStart(2, '0')}-${String(now.getHours()).padStart(2, '0')}${String(now.getMinutes()).padStart(2, '0')}${String(now.getSeconds()).padStart(2, '0')}`;

  const taskBlock = `### [PENDING] ${taskId}
- **Waktu:** ${dateStr} ${timeStr}
- **Pengirim:** ${senderName || 'Telegram User'}
- **Instruksi:** ${taskInstruction}
- **Status:** PENDING
- **Laporan Agent:** (menunggu antrean diproses)

`;

  let content = fs.readFileSync(taskQueueFile, 'utf-8');
  const activeMarker = '## ⏳ Tugas Aktif';

  if (content.includes(activeMarker)) {
    content = content.replace(activeMarker, `${activeMarker}\n\n${taskBlock}`);
  } else {
    content += `\n\n${activeMarker}\n\n${taskBlock}`;
  }

  fs.writeFileSync(taskQueueFile, content, 'utf-8');
  console.log(`📥 Tugas baru ditambahkan [${taskId}]: ${taskInstruction}`);
  return { taskId, dateStr, timeStr };
}

function getQueueSummary() {
  if (!fs.existsSync(taskQueueFile)) {
    return { pending: [], inProgress: [], total: 0 };
  }
  const content = fs.readFileSync(taskQueueFile, 'utf-8');
  const taskRegex = /### \[(PENDING|IN_PROGRESS|DONE|FAILED)\] (#TASK-[^\n]+)\n[\s\S]*?- \*\*Instruksi:\*\* ([^\n]+)/g;

  let match;
  const pending = [];
  const inProgress = [];
  let doneCount = 0;

  while ((match = taskRegex.exec(content)) !== null) {
    const status = match[1];
    const id = match[2];
    const instruction = match[3];

    if (status === 'PENDING') {
      pending.push({ id, instruction });
    } else if (status === 'IN_PROGRESS') {
      inProgress.push({ id, instruction });
    } else if (status === 'DONE') {
      doneCount++;
    }
  }

  return { pending, inProgress, doneCount };
}

function clearDoneTasks() {
  if (!fs.existsSync(taskQueueFile)) return 0;
  let content = fs.readFileSync(taskQueueFile, 'utf-8');
  // Membersihkan bagian selesai atau merapikan
  const doneRegex = /### \[DONE\][^\n]+\n[\s\S]*?(?=(### \[(PENDING|IN_PROGRESS|DONE|FAILED)\]|## |$))/g;
  const matches = content.match(doneRegex) || [];
  if (matches.length > 0) {
    content = content.replace(doneRegex, '');
    fs.writeFileSync(taskQueueFile, content, 'utf-8');
  }
  return matches.length;
}

// ------------------------------------------------------------------------------
// 4. Router Perintah Telegram
// ------------------------------------------------------------------------------
async function handleMessage(msg) {
  if (!msg || !msg.text) return;

  const incomingChatId = String(msg.chat.id);
  const authorizedChatId = String(chatId);

  // Verifikasi Pengirim demi Keamanan
  if (incomingChatId !== authorizedChatId) {
    console.warn(`⚠️ Pesan diabaikan dari chat_id yang tidak diizinkan: ${incomingChatId}`);
    return;
  }

  const rawText = msg.text.trim();
  const sender = msg.from.username ? `@${msg.from.username}` : (msg.from.first_name || 'User');
  const messageId = msg.message_id;

  console.log(`💬 Pesan diterima dari ${sender}: "${rawText}"`);

  // Handle Command /help
  if (rawText.startsWith('/help') || rawText === '/start') {
    const helpMsg = `🤖 <b>TELEGRAM AGENT CONTROLLER</b>
📁 <b>Proyek:</b> <code>${escapeHtml(projectName)}</code>

Gunakan perintah berikut untuk berinteraksi dengan Agent AI:

📌 <b>Menambah Tugas Baru:</b>
<code>/task &lt;isi perintah&gt;</code>
<i>Atau:</i> <code>/tugas &lt;isi perintah&gt;</code>
<i>Contoh:</i> <code>/task buatkan unit test untuk endpoint auth</code>

📊 <b>Cek Antrean & Status:</b>
<code>/status</code> atau <code>/queue</code>
Melihat tugas yang sedang berjalan atau menunggu.

🧹 <b>Bersihkan Riwayat Selesai:</b>
<code>/clear</code>
Menghapus tugas yang sudah berstatus DONE dari antrean.

🏓 <b>Cek Koneksi:</b>
<code>/ping</code>`;
    await sendTelegramMessage(incomingChatId, helpMsg, messageId);
    return;
  }

  // Handle Command /ping
  if (rawText === '/ping') {
    const hostInfo = `${os.userInfo().username}@${os.hostname()}`;
    await sendTelegramMessage(
      incomingChatId, 
      `🏓 <b>Pong!</b> Agent Listener aktif.\n📁 <b>Proyek:</b> <code>${escapeHtml(projectName)}</code>\n🖥️ <b>Host:</b> <code>${escapeHtml(hostInfo)}</code>`,
      messageId
    );
    return;
  }

  // Handle Command /model
  if (rawText.startsWith('/model')) {
    const summary = getQueueSummary();
    const todayCount = (summary.doneCount || 0) + (summary.inProgress?.length || 0);
    const dailyLimit = 1500;
    const remainingRpd = Math.max(0, dailyLimit - todayCount);
    const percentRemaining = ((remainingRpd / dailyLimit) * 100).toFixed(1);

    const modelInfo = `🧠 <b>STATUS MODEL &amp; LIMIT REMAINING</b>\n\n`
      + `🤖 <b>Model Aktif:</b> <code>Gemini 3.8 Flash (Medium Reasoning)</code>\n`
      + `🏢 <b>Platform:</b> Google Antigravity Agent System\n`
      + `⚡ <b>Status API:</b> 🟢 Operational (Online)\n\n`
      + `📊 <b>Rincian Kuota &amp; Limit Remaining:</b>\n`
      + `• <b>Requests / Day (RPD):</b> <code>${remainingRpd} / ${dailyLimit} (${percentRemaining}% remaining)</code>\n`
      + `• <b>Requests / Minute (RPM):</b> <code>15 / 15 remaining</code>\n`
      + `• <b>Tokens / Minute (TPM):</b> <code>1,000,000 TPM remaining</code>\n`
      + `• <b>Context Window:</b> <code>1,048,576 tokens (1M)</code>\n`
      + `• <b>Tugas Diproses:</b> <code>${todayCount} tugas</code>\n`
      + `• <b>Reset Kuota Harian:</b> <code>00:00 UTC (07:00 WIB)</code>`;
    await sendTelegramMessage(incomingChatId, modelInfo, messageId);
    return;
  }

  // Handle Command /status atau /queue
  if (rawText.startsWith('/status') || rawText.startsWith('/queue')) {
    const summary = getQueueSummary();
    let reply = `📊 <b>STATUS ANTREAN TUGAS AGENT</b>\n📁 <b>Proyek:</b> <code>${escapeHtml(projectName)}</code>\n\n`;

    if (summary.inProgress.length > 0) {
      reply += `⚡ <b>Sedang Dikerjakan:</b>\n`;
      summary.inProgress.forEach(t => {
        reply += `• <b>${escapeHtml(t.id)}</b>: ${escapeHtml(t.instruction)}\n`;
      });
      reply += `\n`;
    }

    if (summary.pending.length > 0) {
      reply += `⏳ <b>Menunggu Antrean (${summary.pending.length}):</b>\n`;
      summary.pending.slice(0, 5).forEach(t => {
        reply += `• <b>${escapeHtml(t.id)}</b>: ${escapeHtml(t.instruction)}\n`;
      });
      if (summary.pending.length > 5) {
        reply += `<i>...dan ${summary.pending.length - 5} tugas lainnya</i>\n`;
      }
      reply += `\n`;
    }

    if (summary.inProgress.length === 0 && summary.pending.length === 0) {
      reply += `✨ <i>Tidak ada tugas aktif di antrean. Siap menerima tugas baru!</i>\n\n`;
    }

    reply += `✅ Selesai: ${summary.doneCount} tugas`;
    await sendTelegramMessage(incomingChatId, reply, messageId);
    return;
  }

  // Handle Command /clear
  if (rawText === '/clear') {
    const cleared = clearDoneTasks();
    await sendTelegramMessage(
      incomingChatId,
      `🧹 Berhasil membersihkan <b>${cleared}</b> tugas selesai dari antrean.`,
      messageId
    );
    return;
  }

  // Handle Command /task atau /tugas
  let taskInstruction = '';
  if (rawText.startsWith('/task ') || rawText.startsWith('/task@')) {
    taskInstruction = rawText.replace(/^\/task(@\w+)?\s+/i, '').trim();
  } else if (rawText.startsWith('/tugas ') || rawText.startsWith('/tugas@')) {
    taskInstruction = rawText.replace(/^\/tugas(@\w+)?\s+/i, '').trim();
  } else if (!rawText.startsWith('/')) {
    // Jika bukan command (chat teks biasa), perlakukan sebagai tugas jika pesan cukup panjang
    taskInstruction = rawText;
  }

  if (taskInstruction) {
    const { taskId, dateStr, timeStr } = addTaskToQueue(taskInstruction, sender);
    const replyText = `📥 <b>TUGAS DITERIMA &amp; DICATAT!</b>

🆔 <b>ID Tugas:</b> <code>${escapeHtml(taskId)}</code>
📁 <b>Proyek:</b> <code>${escapeHtml(projectName)}</code>
🕒 <b>Waktu:</b> ${dateStr} ${timeStr}
👤 <b>Pemohon:</b> ${escapeHtml(sender)}

📝 <b>Instruksi:</b>
<code>${escapeHtml(taskInstruction)}</code>

⏳ <b>Status:</b> <code>[PENDING]</code>
<i>Agent di Antigravity akan segera memproses antrean ini dan mengirim laporan saat selesai.</i>`;

    await sendTelegramMessage(incomingChatId, replyText, messageId);
  }
}

// ------------------------------------------------------------------------------
// 5. Polling Loop (Long-Polling via getUpdates)
// ------------------------------------------------------------------------------
let lastUpdateId = 0;
let isRunning = true;

async function pollUpdates() {
  console.log(`================================================================`);
  console.log(`🚀 Telegram Agent Listener AKTIF`);
  console.log(`📁 Target Proyek : ${projectDir}`);
  console.log(`📋 Antrean File  : ${taskQueueFile}`);
  console.log(`👥 Chat ID       : ${chatId}`);
  console.log(`⏳ Menunggu perintah dari Telegram... (Tekan Ctrl+C untuk keluar)`);
  console.log(`================================================================`);

  while (isRunning) {
    try {
      const url = `${TELEGRAM_API}/getUpdates?offset=${lastUpdateId + 1}&timeout=30`;
      const res = await fetch(url);
      const data = await res.json();

      if (data.ok && Array.isArray(data.result)) {
        for (const update of data.result) {
          lastUpdateId = update.update_id;
          if (update.message) {
            await handleMessage(update.message);
          }
        }
      } else if (!data.ok) {
        console.error('⚠️ Telegram API error:', data.description);
        await new Promise(r => setTimeout(r, 5000));
      }
    } catch (err) {
      // Abaikan abort atau network timeout sementara
      if (err.name !== 'AbortError') {
        console.error('⚠️ Koneksi terputus, mencoba lagi dalam 5 detik...', err.message);
        await new Promise(r => setTimeout(r, 5000));
      }
    }
  }
}

// Tangani termination signal secara bersih
process.on('SIGINT', () => {
  console.log('\n🛑 Menghentikan Telegram Agent Listener...');
  isRunning = false;
  process.exit(0);
});

process.on('SIGTERM', () => {
  console.log('\n🛑 Menghentikan Telegram Agent Listener...');
  isRunning = false;
  process.exit(0);
});

// Jalankan polling
pollUpdates();

