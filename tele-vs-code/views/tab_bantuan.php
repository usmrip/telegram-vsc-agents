<?php
// views/tab_bantuan.php - Help, Command Reference & Getting Started Guide
if (!defined('TELE_VS_CODE')) exit;
?>
            <!-- ================================================================ -->
            <!-- TAB 3: BANTUAN & PANDUAN LENGKAP                                 -->
            <!-- ================================================================ -->
            <section class="tab-pane">
                <!-- Sub-tab Segmented Control -->
                <div class="sub-nav">
                    <button type="button" class="sub-pill active" id="btnSubCommands" onclick="switchSubTab('commands')">
                        <?= renderSvg('terminal', 13) ?>
                        <span>Perintah Telegram</span>
                    </button>
                    <button type="button" class="sub-pill" id="btnSubGuide" onclick="switchSubTab('guide')">
                        <?= renderSvg('help', 13) ?>
                        <span>Petunjuk Penggunaan (4 Langkah)</span>
                    </button>
                </div>

                <!-- Sub-Pane 1: Perintah Telegram -->
                <div id="subPaneCommands" class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <h3 style="font-size: 15px; font-weight: 500; color: #fff; display: flex; align-items: center; gap: 8px;">
                                <?= renderSvg('terminal', 15) ?>
                                <span>Daftar Lengkap Perintah Telegram</span>
                            </h3>
                            <p style="color: var(--text-muted); font-size: 12px; margin-top: 2px;">
                                Semua perintah dan tag di bawah ini aktif otomatis di bot Telegram Anda.
                            </p>
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <span class="badge badge-done"><?= count(getDefaultCommands()) ?> Perintah Tersedia</span>
                            <?php if (!empty($projects)): ?>
                                <?php if (count($projects) === 1): ?>
                                    <a href="?action=connect_bot_commands&id=<?= $projects[0]['id'] ?>" class="btn btn-primary btn-sm" style="background: var(--text-primary, #ece4d6); color: #0d0c0a; font-weight: 600; border-radius: 999px;" title="Hubungkan perintah ke Bot Telegram">
                                        <?= renderSvg('terminal', 13) ?>
                                        <span>Hubungkan Perintah ke Bot</span>
                                    </a>
                                <?php else: ?>
                                    <div style="position: relative; display: inline-block;">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="const d = document.getElementById('dropdownSyncCmds'); d.style.display = d.style.display === 'none' ? 'block' : 'none';" style="background: var(--text-primary, #ece4d6); color: #0d0c0a; font-weight: 600; border-radius: 999px;">
                                            <?= renderSvg('terminal', 13) ?>
                                            <span>Hubungkan Perintah ke Bot ▾</span>
                                        </button>
                                        <div id="dropdownSyncCmds" style="display: none; position: absolute; right: 0; top: 100%; margin-top: 6px; background: var(--bg-surface-elevated, #1a1814); border: 1px solid var(--border-medium, rgba(236,228,214,0.14)); border-radius: 10px; padding: 6px; z-index: 100; min-width: 220px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
                                            <?php foreach ($projects as $pj): ?>
                                                <a href="?action=connect_bot_commands&id=<?= $pj['id'] ?>" style="display: block; padding: 8px 12px; font-size: 12px; color: var(--text-primary, #ece4d6); text-decoration: none; border-radius: 6px;" onmouseover="this.style.background='rgba(236,228,214,0.08)'" onmouseout="this.style.background='transparent'">
                                                    📁 <?= htmlspecialchars($pj['name']) ?> (<code>#<?= htmlspecialchars($pj['code']) ?></code>)
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="table-responsive" style="margin-bottom: 16px;">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 150px;">Perintah / Tag</th>
                                    <th style="width: 140px;">Kategori</th>
                                    <th style="width: 250px;">Contoh Pemakaian</th>
                                    <th>Fungsi & Penjelasan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($projects)): ?>
                                    <?php foreach ($projects as $p): ?>
                                        <?php if (!empty($p['code'])): ?>
                                            <tr style="background: rgba(255, 255, 255, 0.02);">
                                                <td><code style="color: #fff; font-weight: 500;">/<?= htmlspecialchars($p['code']) ?></code></td>
                                                <td><span class="badge badge-project">Shortcut</span></td>
                                                <td><code>/<?= htmlspecialchars($p['code']) ?> perbaiki bug login</code></td>
                                                <td>Kirim tugas langsung ke proyek <b><?= htmlspecialchars($p['name']) ?></b>.</td>
                                            </tr>
                                            <tr style="background: rgba(255, 255, 255, 0.01);">
                                                <td><code style="color: #6ee7b7; font-weight: 500;">#<?= htmlspecialchars($p['code']) ?></code></td>
                                                <td><span class="badge badge-done">Tag Routing</span></td>
                                                <td><code>#<?= htmlspecialchars($p['code']) ?> buatkan endpoint API</code></td>
                                                <td>Awali pesan atau caption file dengan tag ini untuk diarahkan ke <b><?= htmlspecialchars($p['name']) ?></b>.</td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php foreach (getDefaultCommands() as $cmd): ?>
                                    <tr>
                                        <td><code style="color: #fff;">/<?= htmlspecialchars($cmd['command']) ?></code></td>
                                        <td>
                                            <span class="badge <?= $cmd['category'] === 'Tugas & Media' ? 'badge-progress' : ($cmd['category'] === 'Multi-Proyek' ? 'badge-done' : 'badge-pending') ?>">
                                                <?= htmlspecialchars($cmd['category']) ?>
                                            </span>
                                        </td>
                                        <td><code><?= htmlspecialchars($cmd['example']) ?></code></td>
                                        <td style="color: var(--text-secondary);"><?= htmlspecialchars($cmd['description']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="card-inner" style="font-size: 12.5px; line-height: 1.6; color: var(--text-secondary);">
                        <b style="color: #fff;">Tips Bebas Format:</b><br>
                        • Anda <b>tidak wajib</b> menggunakan garis miring (<code>/</code>). Teks instruksi biasa di grup atau chat pribadi otomatis dicatat ke antrean AI.<br>
                        • Kirim <b>Foto</b>, <b>Video</b>, atau <b>Dokumen</b> dengan caption (atau tanpa caption). Bot otomatis mengunduh lampiran ke folder antrean tugas.
                    </div>
                </div>

                <!-- Sub-Pane 2: Petunjuk Penggunaan (4 Langkah) -->
                <div id="subPaneGuide" class="card" style="display: none;">
                    <h3 style="font-size: 15px; font-weight: 500; color: #fff; margin-bottom: 16px;">Petunjuk Penggunaan (4 Langkah Mudah)</h3>
                    
                    <div class="card-inner" style="margin-bottom: 12px;">
                        <h4 style="font-size: 13.5px; font-weight: 500; color: #fff; margin-bottom: 4px;">1. Buat Bot via @BotFather</h4>
                        <p style="font-size: 12.5px; color: var(--text-muted); line-height: 1.5;">Buka Telegram &rarr; cari <b>@BotFather</b> &rarr; ketik <code>/newbot</code> &rarr; salin HTTP API Token &rarr; tempelkan pada kolom <b>Telegram Bot Token</b> di tab Pengaturan Proyek.</p>
                    </div>

                    <div class="card-inner" style="margin-bottom: 12px;">
                        <h4 style="font-size: 13.5px; font-weight: 500; color: #fff; margin-bottom: 4px;">2. Dapatkan Chat ID & User ID via /id atau /myid</h4>
                        <p style="font-size: 12.5px; color: var(--text-muted); line-height: 1.5;">Kirim pesan <code>/id</code> atau <code>/myid</code> ke Bot Anda di chat pribadi (private message) untuk mendapatkan <b>User ID Anda</b> & <b>ID Bot</b>, atau ketik <code>/id</code> di dalam grup untuk mendapatkan <b>Chat ID grup</b>. Bot juga otomatis membalas dengan panduan instalasi lengkap.</p>
                    </div>

                    <div class="card-inner" style="margin-bottom: 12px;">
                        <h4 style="font-size: 13.5px; font-weight: 500; color: #fff; margin-bottom: 4px;">3. Pasang Webhook & Tes Bot</h4>
                        <p style="font-size: 12.5px; color: var(--text-muted); line-height: 1.5;">Klik tombol <b>Pasang Webhook</b> pada proyek Anda agar bot aktif live &rarr; klik <b>Tes Bot</b> untuk memverifikasi koneksi notifikasi Telegram.</p>
                    </div>

                    <div class="card-inner">
                        <h4 style="font-size: 13.5px; font-weight: 500; color: #fff; margin-bottom: 4px;">4. Hubungkan ke Visual Studio Code (.VSIX)</h4>
                        <p style="font-size: 12.5px; color: var(--text-muted); line-height: 1.5;">Unduh file <code><?= basename(VSIX_FILE) ?></code> di atas &rarr; buka VS Code &rarr; tekan <code>Ctrl+Shift+X</code> &rarr; pilih <b>Install from VSIX...</b> &rarr; masukkan API Key akun Anda.</p>
                    </div>
                </div>
            </section>