#!/usr/bin/env node

const dns = require('dns');
if (typeof dns.setDefaultResultOrder === 'function') {
  dns.setDefaultResultOrder('ipv4first');
}
// ==============================================================================
// Universal Cross-Platform Telegram Notifier (Node.js Engine)
// Menjamin UTF-8 sempurna, bebas mojibake dan bebas error batch %0A
// ==============================================================================

const fs = require('fs');
const path = require('path');
const os = require('os');

const args = process.argv.slice(2);
if (args.length === 0 || args[0] === '-h' || args[0] === '--help') {
  console.log('Penggunaan:');
  console.log('  tele-notify "<Judul>" [STATUS] [Detail 1] [Detail 2] ...');
  console.log('  tele-notify "<Judul>" --status SELESAI --detail "<Rincian 1>" --detail "<Rincian 2>"');
  console.log('  tele-notify "<Judul>" --status PROSES --detail "<Sedang dikerjakan>"');
  console.log('  tele-notify "<Judul Kendala>" --status KENDALA --detail "<Penyebab>"');
  process.exit(0);
}

let title = '';
let status = 'SELESAI';
let agent = 'Antigravity AI Agent';
let mediaUrl = null;
const details = [];

for (let i = 0; i < args.length; i++) {
  const arg = args[i];
  if (arg === '--status' || arg === '-s') {
    status = (args[++i] || 'SELESAI').toUpperCase();
  } else if (arg === '--agent' || arg === '-a') {
    if (args[i + 1]) agent = args[++i];
  } else if (arg === '--media' || arg === '--attachment' || arg === '--image' || arg === '--video' || arg === '--file') {
    if (args[i + 1]) mediaUrl = args[++i];
  } else if (arg === '--detail' || arg === '-d') {
    if (args[i + 1]) details.push(args[++i]);
  } else if (arg === '--title' || arg === '-t') {
    title = args[++i] || '';
  } else if (!title) {
    title = arg;
  } else if (details.length === 0 && !arg.startsWith('-') && ['SELESAI', 'SUKSES', 'SUCCESS', 'DONE', 'PROSES', 'PROGRESS', 'IN_PROGRESS', 'RUNNING', 'KENDALA', 'ERROR', 'FAILED', 'GAGAL', 'INFO'].includes(arg.toUpperCase())) {
    status = arg.toUpperCase();
  } else {
    details.push(arg);
  }
}

if (!title) title = 'Laporan Tugas';
status = status.toUpperCase();

if (!mediaUrl) {
  for (const d of details) {
    const m = d.match(/https?:\/\/[^\s"']+\.(?:jpg|jpeg|png|webp|mp4|mov)/i) || d.match(/https?:\/\/biru\.co\.id\/tele-vs-code\/uploads\/[^\s"']+/i);
    if (m) {
      mediaUrl = m[0];
      break;
    }
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
const cwd = process.cwd();
const globalConf = path.join(homeDir, '.config', 'tele-notify', 'config.env');
const localVscodeConf = path.join(cwd, '.vscode', 'tele-vs-code', 'config.env');
const localConf = path.join(cwd, 'config.env');
const localEnv = path.join(cwd, '.env');

const cfgGlobal = parseEnvFile(globalConf);
const cfgVscode = parseEnvFile(localVscodeConf);
const cfgLocal = parseEnvFile(localConf);
const cfgEnv = parseEnvFile(localEnv);

const botToken = process.env.TELEGRAM_BOT_TOKEN ||
                 cfgVscode.TELEGRAM_BOT_TOKEN ||
                 cfgLocal.TELEGRAM_BOT_TOKEN ||
                 cfgEnv.TELEGRAM_BOT_TOKEN ||
                 cfgGlobal.TELEGRAM_BOT_TOKEN;

const chatId = process.env.TELEGRAM_CHAT_ID ||
               cfgLocal.TELEGRAM_CHAT_ID ||
               cfgEnv.TELEGRAM_CHAT_ID ||
               cfgGlobal.TELEGRAM_CHAT_ID;

if (!botToken || !chatId) {
  console.error('[ERROR] TELEGRAM_BOT_TOKEN atau TELEGRAM_CHAT_ID belum disetel!');
  process.exit(1);
}

let emoji = '✅';
if (['KENDALA', 'ERROR', 'FAILED', 'GAGAL'].includes(status)) {
  emoji = '🚨';
} else if (['PROSES', 'PROGRESS', 'IN_PROGRESS', 'RUNNING'].includes(status)) {
  emoji = '⚡';
} else if (status === 'INFO') {
  emoji = 'ℹ️';
} else {
  emoji = '✅';
}

const timezone = process.env.TIMEZONE ||
                 cfgVscode.TIMEZONE ||
                 cfgLocal.TIMEZONE ||
                 cfgEnv.TIMEZONE ||
                 cfgGlobal.TIMEZONE ||
                 'Asia/Jakarta';

let tzLabel = timezone;
if (timezone === 'Asia/Jakarta') tzLabel = 'WIB';
else if (timezone === 'Asia/Makassar') tzLabel = 'WITA';
else if (timezone === 'Asia/Jayapura') tzLabel = 'WIT';
else if (timezone === 'UTC') tzLabel = 'UTC';
else if (timezone === 'Asia/Singapore') tzLabel = 'SGT';
else if (timezone === 'Asia/Bangkok') tzLabel = 'ICT';

const now = new Date();
let dateStr, timeStr;
try {
  dateStr = now.toLocaleDateString('id-ID', { timeZone: timezone, day: '2-digit', month: '2-digit', year: 'numeric' });
  timeStr = now.toLocaleTimeString('id-ID', { timeZone: timezone, hour: '2-digit', minute: '2-digit', second: '2-digit' }).replace(/\./g, ':');
} catch (e) {
  dateStr = now.toLocaleDateString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric' });
  timeStr = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).replace(/\./g, ':');
  tzLabel = 'WIB';
}

const userHost = `${os.userInfo().username}@${os.hostname()}`;
const projectName = path.basename(cwd);

function escapeHtml(text) {
  if (!text) return '';
  return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

let textHtml = `<b>${emoji} ${escapeHtml(title)}: ${escapeHtml(status)}</b>\n\n`
             + `📁 <b>Proyek:</b> <code>${escapeHtml(projectName)}</code>\n`
             + `🕒 <b>Waktu:</b> ${dateStr} ${timeStr} ${tzLabel}\n`
             + `🤖 <b>Eksekutor:</b> <code>${escapeHtml(agent)}</code>\n`
             + `👤 <b>Operator:</b> <code>${escapeHtml(userHost)}</code>\n`;

if (details.length > 0) {
  textHtml += `\n📋 <b>Detail:</b>\n`;
  for (const d of details) {
    textHtml += `• ${escapeHtml(d)}\n`;
  }
}

textHtml += `\n🤖 <i>Dikirim via tele-notify</i>`;

async function ensureMediaUploaded() {
  if (!mediaUrl) return;
  if (/^https?:\/\//i.test(mediaUrl)) return;

  const localPath = path.isAbsolute(mediaUrl) ? mediaUrl : path.resolve(cwd, mediaUrl);
  if (fs.existsSync(localPath)) {
    const secretKey = process.env.AGENTS_TELE_SECRET || cfgVscode.AGENTS_TELE_SECRET || cfgLocal.AGENTS_TELE_SECRET || cfgEnv.AGENTS_TELE_SECRET || '';
    const projectKey = process.env.AGENTS_TELE_PROJECT_KEY || cfgVscode.AGENTS_TELE_PROJECT_KEY || cfgLocal.AGENTS_TELE_PROJECT_KEY || cfgEnv.AGENTS_TELE_PROJECT_KEY || '';
    const webhookUrl = process.env.AGENTS_TELE_WEBHOOK_URL || cfgVscode.AGENTS_TELE_WEBHOOK_URL || cfgLocal.AGENTS_TELE_WEBHOOK_URL || 'https://biru.co.id/tele-vs-code/webhook';
    const baseUrl = webhookUrl.replace(/\/webhook\/?$/, '/').replace(/\/$/, '');

    if (secretKey) {
      try {
        console.log(`📤 Mengunggah berkas media "${path.basename(localPath)}" ke Server Biru untuk dokumentasi...`);
        const fileBuf = fs.readFileSync(localPath);
        const blob = new Blob([fileBuf]);
        const formData = new FormData();
        formData.append('file', blob, path.basename(localPath));
        if (projectKey) formData.append('project', projectKey);
        formData.append('caption', title || 'Dokumentasi Tugas');
        formData.append('uploaded_by', `${os.userInfo().username}@${os.hostname()} (CLI tele-notify)`);

        const uploadUrl = `${baseUrl}/?action=upload_media&secret=${encodeURIComponent(secretKey)}`;
        const upRes = await fetch(uploadUrl, { method: 'POST', body: formData });
        const upData = await upRes.json();
        if (upRes.ok && upData.ok && upData.file_url) {
          console.log(`✅ [Dokumentasi Tersimpan] Berkas berhasil diunggah ke Server Biru: ${upData.file_url}`);
          mediaUrl = upData.file_url;
        } else {
          console.warn(`⚠️ Gagal unggah media ke Server Biru: ${upData.error || upRes.status}`);
        }
      } catch (upErr) {
        console.warn(`⚠️ Error upload media: ${upErr.message}`);
      }
    }
  }
}

async function send() {
  await ensureMediaUploaded();
  try {
    // Jika ada lampiran foto dan panjang pesan muat di caption Telegram (<= 1024 char)
    if (mediaUrl && textHtml.length <= 1024 && (mediaUrl.match(/\.(jpg|jpeg|png|webp)($|\?)/i) || mediaUrl.includes('/uploads/'))) {
      try {
        const photoRes = await fetch(`https://api.telegram.org/bot${botToken}/sendPhoto`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            chat_id: chatId,
            photo: mediaUrl,
            caption: textHtml,
            parse_mode: 'HTML'
          })
        });
        const pData = await photoRes.json();
        if (pData.ok) {
          console.log('[OK] Notifikasi Telegram dengan foto berhasil terkirim.');
          return;
        }
      } catch (e) {}
    }

    // Default atau fallback: Kirim sendMessage dengan link preview media jika ada
    const payload = {
      chat_id: chatId,
      text: textHtml,
      parse_mode: 'HTML'
    };

    if (mediaUrl) {
      payload.text = `<a href="${mediaUrl}">&#8205;</a>` + textHtml;
      payload.link_preview_options = {
        is_disabled: false,
        url: mediaUrl,
        prefer_large_media: true,
        show_above_text: false
      };
    } else {
      payload.disable_web_page_preview = true;
    }

    const res = await fetch(`https://api.telegram.org/bot${botToken}/sendMessage`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.ok) {
      console.log('[OK] Notifikasi Telegram berhasil terkirim.');
    } else {
      console.error('[WARN] Respon Telegram:', data.description);
    }
  } catch (err) {
    console.error('[ERROR] Gagal mengirim:', err.message);
  }
}

send();

