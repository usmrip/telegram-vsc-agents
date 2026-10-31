// ==============================================================================
// Official & Standalone Hybrid VSIX Packager for VS Code Extension
// Menggunakan @vscode/vsce resmi jika ada, atau fallback standalone
// ==============================================================================

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const extDir = __dirname;
const pkgPath = path.join(extDir, 'package.json');
if (!fs.existsSync(pkgPath)) {
  console.error('[ERROR] package.json tidak ditemukan di:', extDir);
  process.exit(1);
}

const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf-8'));
const version = pkg.version || '1.0.0';
const publisher = pkg.publisher || 'BiruIndonesiaCreative';
const name = pkg.name || 'tele-vs-code';
const displayName = pkg.displayName || 'Tele-vs-Code';
const description = pkg.description || 'Telegram sebagai pesuruh VS Code dari mana aja';
const minEngine = (pkg.engines && pkg.engines.vscode) ? pkg.engines.vscode.replace(/[\^~]/, '') : '1.75.0';

const vsixFileName = `${name}-${version}.vsix`;
const outputVsix = path.join(extDir, vsixFileName);
const rootOutputVsix = path.join(path.dirname(extDir), vsixFileName);
const teleVsCodeWebDir = path.join(path.dirname(extDir), 'tele-vs-code');
const liveWebDir = 'U:\\domains\\biru.co.id\\public_html\\tele-vs-code';

console.log(`📦 Memulai pembuatan paket VSIX resmi: ${vsixFileName}`);

// 1. Sinkronkan berkas tools ke folder assets ekstensi
const assetsDir = path.join(extDir, 'assets', 'tele-vs-code');
if (!fs.existsSync(assetsDir)) fs.mkdirSync(assetsDir, { recursive: true });
const rootTools = [
  'tele-worker.js', 'tele-notify.js', 'tele-task.js',
  'tele-worker.cmd', 'tele-notify.cmd', 'tele-task.cmd',
  'tele-worker.sh', 'tele-notify.sh', 'tele-task.sh',
  'tele-worker', 'tele-notify', 'tele-task'
];
for (const tf of rootTools) {
  const p = path.join(path.dirname(extDir), tf);
  const pAssets = path.join(assetsDir, tf);
  if (fs.existsSync(p)) {
    fs.copyFileSync(p, pAssets);
  }
}

// 2. Gunakan @vscode/vsce resmi jika tersedia (100% Marketplace Compliant)
const vsceBin = path.join(extDir, 'node_modules', '@vscode', 'vsce', 'vsce');
if (fs.existsSync(vsceBin)) {
  try {
    console.log('[INFO] Memaketkan via @vscode/vsce resmi...');
    execSync(`node "${vsceBin}" package --no-git-tag-version --allow-missing-repository -o "${outputVsix}"`, {
      cwd: extDir,
      stdio: 'inherit'
    });

    // Salin ke root
    fs.copyFileSync(outputVsix, rootOutputVsix);

    // Salin ke web lokal
    if (fs.existsSync(teleVsCodeWebDir)) {
      fs.copyFileSync(outputVsix, path.join(teleVsCodeWebDir, vsixFileName));
    }

    // Salin ke server live
    if (fs.existsSync(liveWebDir)) {
      try {
        fs.copyFileSync(outputVsix, path.join(liveWebDir, vsixFileName));
        console.log('[LIVE DEPLOY] Berhasil disinkronkan ke server live!');
      } catch (e) {}
    }

    console.log(`\n🎉 Paket VSIX Resmi Microsoft Berhasil Dibuat!`);
    console.log(`📁 Lokasi file VSIX : ${outputVsix}`);
    console.log(`📁 Lokasi Root     : ${rootOutputVsix}\n`);
    process.exit(0);
  } catch (vsceErr) {
    console.warn(`[WARN] vsce resmi gagal (${vsceErr.message}), beralih ke fallback internal...`);
  }
}

// Fallback Standalone Generator (OPC Compliant)
const stagingDir = path.join(extDir, '.staging_vsix');
if (fs.existsSync(stagingDir)) {
  fs.rmSync(stagingDir, { recursive: true, force: true });
}
fs.mkdirSync(stagingDir, { recursive: true });

const extTargetDir = path.join(stagingDir, 'extension');
fs.mkdirSync(extTargetDir, { recursive: true });

const filesToCopy = ['package.json', 'extension.js', 'README.md', 'icon.png', 'LICENSE'];
for (const file of filesToCopy) {
  const src = path.join(extDir, file);
  if (fs.existsSync(src)) {
    fs.copyFileSync(src, path.join(extTargetDir, file));
  }
}

const srcAssets = path.join(extDir, 'assets');
const targetAssets = path.join(extTargetDir, 'assets');
if (fs.existsSync(srcAssets)) {
  fs.cpSync(srcAssets, targetAssets, { recursive: true });
}

// [Content_Types].xml resmi OPC
const contentTypesXml = `<?xml version="1.0" encoding="utf-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension=".cmd" ContentType="application/octet-stream"/><Default Extension=".sh" ContentType="application/x-sh"/><Default Extension=".js" ContentType="application/javascript"/><Default Extension=".json" ContentType="application/json"/><Default Extension=".md" ContentType="text/markdown"/><Default Extension=".png" ContentType="image/png"/><Default Extension=".txt" ContentType="text/plain"/><Default Extension=".vsixmanifest" ContentType="text/xml"/></Types>`;
fs.writeFileSync(path.join(stagingDir, '[Content_Types].xml'), contentTypesXml, 'utf-8');

const vsixManifestXml = `<?xml version="1.0" encoding="utf-8"?>
<PackageManifest Version="2.0.0" xmlns="http://schemas.microsoft.com/developer/vsx-schema/2011" xmlns:d="http://schemas.microsoft.com/developer/vsx-schema-design/2011">
  <Metadata>
    <Identity Language="en-US" Id="${name}" Version="${version}" Publisher="${publisher}" />
    <DisplayName>${displayName.replace(/&/g, '&amp;')}</DisplayName>
    <Description xml:space="preserve">${description.replace(/&/g, '&amp;')}</Description>
    <Tags>telegram,vscode,agent,ai-agent,task-queue,remote,automation</Tags>
    <Categories>Other,Programming Languages,Machine Learning</Categories>
    <GalleryFlags>Public</GalleryFlags>
    <Properties>
      <Property Id="Microsoft.VisualStudio.Code.Engine" Value="^${minEngine}" />
      <Property Id="Microsoft.VisualStudio.Code.ExtensionPublisherDisplayName" Value="Biru Indonesia Creative" />
      <Property Id="Microsoft.VisualStudio.Code.ExtensionKind" Value="workspace,ui" />
      <Property Id="Microsoft.VisualStudio.Services.Links.Source" Value="https://github.com/usmrip/telegram-vsc-agents.git" />
      <Property Id="Microsoft.VisualStudio.Services.Links.Support" Value="https://github.com/usmrip/telegram-vsc-agents/issues" />
      <Property Id="Microsoft.VisualStudio.Services.Links.Learn" Value="https://github.com/usmrip/telegram-vsc-agents#readme" />
      <Property Id="Microsoft.VisualStudio.Services.GitHubFlavoredMarkdown" Value="true" />
      <Property Id="Microsoft.VisualStudio.Services.Content.Pricing" Value="Free" />
    </Properties>
    <License>extension/LICENSE</License>
    <Icon>extension/icon.png</Icon>
  </Metadata>
  <Installation>
    <InstallationTarget Id="Microsoft.VisualStudio.Code"/>
  </Installation>
  <Dependencies/>
  <Assets>
    <Asset Type="Microsoft.VisualStudio.Code.Manifest" Path="extension/package.json" Addressable="true" />
    <Asset Type="Microsoft.VisualStudio.Services.Content.Details" Path="extension/README.md" Addressable="true" />
    <Asset Type="Microsoft.VisualStudio.Services.Content.License" Path="extension/LICENSE" Addressable="true" />
    <Asset Type="Microsoft.VisualStudio.Services.Icons.Default" Path="extension/icon.png" Addressable="true" />
  </Assets>
</PackageManifest>`;
fs.writeFileSync(path.join(stagingDir, 'extension.vsixmanifest'), vsixManifestXml, 'utf-8');

if (fs.existsSync(outputVsix)) fs.unlinkSync(outputVsix);
if (fs.existsSync(rootOutputVsix)) fs.unlinkSync(rootOutputVsix);

try {
  const isWindows = process.platform === 'win32';
  if (isWindows) {
    const psScript = `
      Add-Type -AssemblyName System.IO.Compression.FileSystem;
      [System.IO.Compression.ZipFile]::CreateFromDirectory('${stagingDir}', '${outputVsix}');
    `;
    execSync(`powershell -NoProfile -Command "${psScript.replace(/\r?\n/g, ' ')}"`, { stdio: 'inherit' });
  } else {
    execSync(`cd "${stagingDir}" && zip -r "${outputVsix}" .`, { stdio: 'inherit' });
  }

  fs.copyFileSync(outputVsix, rootOutputVsix);
  if (fs.existsSync(teleVsCodeWebDir)) {
    fs.copyFileSync(outputVsix, path.join(teleVsCodeWebDir, vsixFileName));
  }
  if (fs.existsSync(liveWebDir)) {
    try {
      fs.copyFileSync(outputVsix, path.join(liveWebDir, vsixFileName));
      console.log('[LIVE DEPLOY] Disalin ke server');
    } catch (e) {}
  }

  fs.rmSync(stagingDir, { recursive: true, force: true });
  console.log(`\n🎉 Paket VSIX Berhasil Dibuat!`);
} catch (err) {
  console.error(`❌ Gagal kompresi VSIX: ${err.message}`);
  process.exit(1);
}
