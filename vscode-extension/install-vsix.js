// ==============================================================================
// Cross-Platform VSIX Installer
// ==============================================================================

const fs = require('fs');
const path = require('path');
const os = require('os');
const { execSync } = require('child_process');

const rootDir = path.resolve(__dirname, '..');
const pkg = require('./package.json');
const vsixFileName = `${pkg.name}-${pkg.version}.vsix`;
const vsixFile = fs.existsSync(path.join(rootDir, 'publish', vsixFileName))
  ? path.join(rootDir, 'publish', vsixFileName)
  : (fs.existsSync(path.join(rootDir, vsixFileName))
      ? path.join(rootDir, vsixFileName)
      : path.join(__dirname, vsixFileName));

if (!fs.existsSync(vsixFile)) {
  console.log('[INFO] File VSIX belum ada. Membangun paket terlebih dahulu...');
  require('./build-vsix.js');
}

console.log('==============================================================================');
console.log('📦 Menginstal Ekstensi Tele-vs-Code (Telegram VSC Agents)');
console.log('==============================================================================');

let codeExecutable = null;

// Pertimbangkan saat diinstall ke server (Remote SSH, Linux headless, code-server, openvscode-server):
// Cari binary code / code-server / cursor di berbagai path server & lokal umum
const isLinuxOrMac = process.platform !== 'win32';
const home = os.homedir ? os.homedir() : (process.env.HOME || '');

const candidates = [
  'code',
  'code-server',
  'openvscode-server',
  'cursor',
  'codium',
  'vscodium',
  'code.cmd',
  path.join(process.env.LOCALAPPDATA || '', 'Programs', 'Microsoft VS Code', 'bin', 'code.cmd'),
  path.join(process.env['ProgramFiles'] || '', 'Microsoft VS Code', 'bin', 'code.cmd'),
  path.join(process.env['ProgramFiles(x86)'] || '', 'Microsoft VS Code', 'bin', 'code.cmd'),
  '/usr/local/bin/code',
  '/usr/bin/code',
  '/usr/local/bin/code-server',
  '/usr/bin/code-server',
  '/snap/bin/code',
  '/snap/bin/code-server',
  '/opt/homebrew/bin/code',
  '/opt/homebrew/bin/code-server'
];

// Cari di instalasi internal VS Code Remote Server Linux (~/.vscode-server/bin)
if (isLinuxOrMac && home) {
  const vscServerDir = path.join(home, '.vscode-server', 'bin');
  if (fs.existsSync(vscServerDir)) {
    try {
      const commitDirs = fs.readdirSync(vscServerDir);
      for (const d of commitDirs) {
        const binCand1 = path.join(vscServerDir, d, 'bin', 'code-server');
        const binCand2 = path.join(vscServerDir, d, 'bin', 'remote-cli', 'code');
        if (fs.existsSync(binCand1)) candidates.push(binCand1);
        if (fs.existsSync(binCand2)) candidates.push(binCand2);
      }
    } catch (e) {}
  }
}

for (const cand of candidates) {
  if (!cand) continue;
  try {
    if (cand.includes(path.sep)) {
      if (fs.existsSync(cand)) {
        codeExecutable = `"${cand}"`;
        break;
      }
    } else {
      execSync(`${cand} --version`, { stdio: 'ignore' });
      codeExecutable = cand;
      break;
    }
  } catch (e) {}
}

if (!codeExecutable) {
  console.log('\n[PERHATIAN] Perintah "code" tidak ditemukan di PATH.');
  console.log('Anda dapat menginstal secara manual lewat UI VS Code:');
  console.log('  1. Buka Visual Studio Code');
  console.log('  2. Buka panel Extensions (Ctrl+Shift+X)');
  console.log('  3. Klik menu titik tiga (...) di pojok kanan atas');
  console.log('  4. Pilih "Install from VSIX..."');
  console.log(`  5. Pilih file: ${vsixFile}\n`);
  process.exit(0);
}

try {
  console.log(`[1/1] Menjalankan: ${codeExecutable} --install-extension "${vsixFile}" --force`);
  const output = execSync(`${codeExecutable} --install-extension "${vsixFile}" --force`, { encoding: 'utf-8' });
  console.log(output);
  console.log('==============================================================================');
  console.log('🎉 [SUKSES] Ekstensi Tele-vs-Code berhasil diinstal ke VS Code!');
  console.log('Langkah selanjutnya:');
  console.log('  1. Buka proyek ini di VS Code / Server: code . (atau buka sesi Remote SSH)');
  console.log('  2. Status bar akan menampilkan "((·)) Tele-vs-Code" di pojok kanan bawah.');
  console.log('  3. Klik status bar untuk menghubungkan ke Server Biru via Setup Wizard.');
  if (process.platform !== 'win32' || process.env.SSH_CONNECTION || process.env.SSH_CLIENT) {
    console.log('------------------------------------------------------------------------------');
    console.log('🖥️ [TIPS LINGKUNGAN SERVER / REMOTE SSH]');
    console.log('  • Pastikan izin eksekusi tool di server: chmod +x .vscode/tele-vs-code/tele-*');
    console.log('  • Ekstensi otomatis berjalan sebagai "workspace extension" di remote server.');
    console.log('  • Untuk worker background headless: node .vscode/tele-vs-code/tele-worker.js &');
  }
  console.log('==============================================================================');
} catch (err) {
  console.error(`❌ Gagal install ekstensi: ${err.message}`);
  process.exit(1);
}

