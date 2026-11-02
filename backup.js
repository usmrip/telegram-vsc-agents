// ==============================================================================
// Tele-vs-Code Automated Full Backup System
// Mencadangkan Database SQLite, Backend Webhook, VS Code Extension, & Konfigurasi
// ==============================================================================

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const rootDir = __dirname;
const backupsDir = path.join(rootDir, 'backups');
if (!fs.existsSync(backupsDir)) {
  fs.mkdirSync(backupsDir, { recursive: true });
}

// 1. Format timestamp waktu lokal (+07:00 / Proyek)
const now = new Date();
const pad = (n) => String(n).padStart(2, '0');
const timestamp = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}_${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
const timeFormatted = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;

console.log('==============================================================================');
console.log(`📦 Memulai Pencadangan Penuh Proyek Tele-vs-Code`);
console.log(`⏰ Waktu: ${timeFormatted}`);
console.log('==============================================================================');

const results = [];

// 2. Backup Database SQLite Live
const liveDbPath = 'U:\\domains\\biru.co.id\\data\\tele-vs-code\\tele-vs-code.db';
const liveDbDir = path.dirname(liveDbPath);
let backedUpDbFile = null;

if (fs.existsSync(liveDbPath)) {
  const dbBakName = `tele-vs-code.db.bak-${timestamp}`;
  const dbBakTarget = path.join(liveDbDir, dbBakName);
  const dbLocalBakTarget = path.join(backupsDir, dbBakName);
  try {
    fs.copyFileSync(liveDbPath, dbBakTarget);
    fs.copyFileSync(liveDbPath, dbLocalBakTarget);
    const dbSize = (fs.statSync(liveDbPath).size / 1024).toFixed(1);
    backedUpDbFile = dbLocalBakTarget;
    results.push(`Database SQLite (${dbSize} KB) dicadangkan ke:\n  - ${dbBakTarget}\n  - ${dbLocalBakTarget}`);
    console.log(`✅ [DB Backup] Berhasil mencadangkan database live (${dbSize} KB)`);
  } catch (err) {
    console.warn(`⚠️ [DB Backup] Gagal copy database: ${err.message}`);
  }
} else {
  console.log(`ℹ️ [DB Backup] Database live tidak ditemukan di ${liveDbPath}`);
}

// 3. Staging Berkas Proyek untuk Kompresi ZIP
const stagingDir = path.join(backupsDir, `.staging_${timestamp}`);
if (fs.existsSync(stagingDir)) {
  fs.rmSync(stagingDir, { recursive: true, force: true });
}
fs.mkdirSync(stagingDir, { recursive: true });

function copyFiltered(srcDir, destDir, ignoreList = []) {
  if (!fs.existsSync(srcDir)) return;
  if (!fs.existsSync(destDir)) fs.mkdirSync(destDir, { recursive: true });

  const entries = fs.readdirSync(srcDir, { withFileTypes: true });
  for (const entry of entries) {
    const srcPath = path.join(srcDir, entry.name);
    const destPath = path.join(destDir, entry.name);

    if (ignoreList.includes(entry.name)) continue;

    if (entry.isDirectory()) {
      copyFiltered(srcPath, destPath, ignoreList);
    } else {
      fs.copyFileSync(srcPath, destPath);
    }
  }
}

// Salin berkas root terpilih
const rootFiles = fs.readdirSync(rootDir, { withFileTypes: true });
for (const rf of rootFiles) {
  if (rf.isFile()) {
    if (['.env', 'README.md', 'package.json', 'AGENTS.md', 'CLAUDE.md', 'GEMINI.md', 'backup.js'].includes(rf.name) ||
        rf.name.endsWith('.cmd') || rf.name.endsWith('.sh') || rf.name.endsWith('.js') || rf.name.endsWith('.vsix')) {
      fs.copyFileSync(path.join(rootDir, rf.name), path.join(stagingDir, rf.name));
    }
  }
}

// Salin direktori proyek
console.log('📂 Menyalin berkas direktori...');
copyFiltered(path.join(rootDir, '.vscode'), path.join(stagingDir, '.vscode'), ['node_modules', '.git']);
copyFiltered(path.join(rootDir, 'tele-vs-code'), path.join(stagingDir, 'tele-vs-code'), ['node_modules', '.git', 'uploads', 'data']);
copyFiltered(path.join(rootDir, 'vscode-extension'), path.join(stagingDir, 'vscode-extension'), ['node_modules', '.staging_vsix', '.git']);

// Salin backup database ke dalam staging zip
if (backedUpDbFile && fs.existsSync(backedUpDbFile)) {
  const dbInZipDir = path.join(stagingDir, 'database');
  fs.mkdirSync(dbInZipDir, { recursive: true });
  fs.copyFileSync(backedUpDbFile, path.join(dbInZipDir, 'tele-vs-code.db'));
}

// 4. Kompresi Staging ke Berkas ZIP
const zipFileName = `tele-vs-code_backup_${timestamp}.zip`;
const zipFilePath = path.join(backupsDir, zipFileName);

try {
  const isWindows = process.platform === 'win32';
  if (isWindows) {
    const psCmd = `Add-Type -AssemblyName System.IO.Compression.FileSystem; [System.IO.Compression.ZipFile]::CreateFromDirectory('${stagingDir}', '${zipFilePath}');`;
    execSync(`powershell -NoProfile -Command "${psCmd}"`, { stdio: 'inherit' });
  } else {
    execSync(`cd "${stagingDir}" && zip -r "${zipFilePath}" .`, { stdio: 'inherit' });
  }

  // Bersihkan staging
  fs.rmSync(stagingDir, { recursive: true, force: true });

  const zipSize = (fs.statSync(zipFilePath).size / (1024 * 1024)).toFixed(2);
  results.push(`Arsip ZIP (${zipSize} MB) dibuat di: ${zipFilePath}`);
  console.log(`✅ [ZIP Archive] Berhasil membuat ${zipFileName} (${zipSize} MB)`);

  // 5. Salin arsip zip ke drive U: untuk redundansi multi-drive
  const remoteBackupDir = 'U:\\domains\\biru.co.id\\data\\tele-vs-code\\backups';
  if (fs.existsSync(path.dirname(remoteBackupDir))) {
    if (!fs.existsSync(remoteBackupDir)) fs.mkdirSync(remoteBackupDir, { recursive: true });
    const remoteZipPath = path.join(remoteBackupDir, zipFileName);
    fs.copyFileSync(zipFilePath, remoteZipPath);
    results.push(`Redundansi Drive U: ${remoteZipPath}`);
    console.log(`✅ [Redundansi Drive U:] Berhasil disalin ke ${remoteZipPath}`);
  }
} catch (zipErr) {
  console.error(`❌ Gagal membuat ZIP backup: ${zipErr.message}`);
}

console.log('==============================================================================');
console.log('🎉 PENCADANGAN SELESAI 100%!');
console.log(results.join('\n'));
console.log('==============================================================================');

// Ekspor fungsi agar dapat dipanggil dari skrip lain jika di-require
module.exports = { timestamp, zipFilePath, backedUpDbFile };

