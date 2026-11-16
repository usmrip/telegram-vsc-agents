<?php
// views/tab_projects.php - Project Management, Bot Webhook & Credentials
if (!defined('TELE_VS_CODE')) exit;
?>
        
            <!-- ================================================================ -->
            <!-- TAB 2: PENGATURAN PROYEK & KREDENSIAL                            -->
            <!-- ================================================================ -->
            <section class="tab-pane">
                <?php if (!empty($canShowDonation)): ?>
                    <div class="card" style="border: 1px solid rgba(244, 63, 94, 0.3); background: rgba(244, 63, 94, 0.05); margin-bottom: 22px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; padding: 16px 20px;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <span style="font-size: 26px;">❤️</span>
                            <div>
                                <div style="font-size: 13.5px; font-weight: 600; color: #fff;">
                                    Dukung Tele-vs-Code: <i>"bertahan hidup dengan teknologi dan #berkembangBaik"</i>
                                </div>
                                <div style="font-size: 12px; color: var(--text-muted); margin-top: 3px;">
                                    Proyek Anda telah berhasil terlayani dan sukses menerima notifikasi tugas AI Agent. Dukung kelanjutan proyek ini dengan ulasan bintang 5 atau donasi.
                                </div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <a href="https://marketplace.visualstudio.com/items?itemName=BiruIndonesiaCreative.tele-vs-code&ssr=false#review-details" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm" style="border-radius: var(--radius-sm); font-size: 12px; height: 32px; padding: 0 13px; display: inline-flex; align-items: center; gap: 6px;">
                                <span>⭐</span>
                                <span>Rating Marketplace</span>
                            </a>
                            <button type="button" class="btn btn-primary btn-sm" onclick="openDonationModal()" style="background: #e11d48; border-color: #be123c; border-radius: var(--radius-sm); font-size: 12px; height: 32px; padding: 0 14px; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 10px rgba(225, 29, 72, 0.35);">
                                <span>❤️</span>
                                <span>Ayo Donasi</span>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
                <!-- Kredensial Pengguna & Download VSIX -->
                <div class="card" style="background: linear-gradient(135deg, rgba(22, 22, 28, 0.95), rgba(12, 12, 15, 0.85)); border-color: rgba(255, 255, 255, 0.16); margin-bottom: 22px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
                        <div>
                            <h2 style="font-size: 16px; font-weight: 500; color: #ffffff; display: flex; align-items: center; gap: 8px;">
                                <?= renderSvg('key', 16) ?>
                                <span>Akun & Kredensial Pribadi</span>
                            </h2>
                            <p style="font-size: 12.5px; color: var(--text-secondary); margin-top: 4px;">
                                Gunakan <b>API Secret Key</b> ini untuk menghubungkan ekstensi Visual Studio Code dengan dashboard antrean.
                            </p>
                        </div>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <a href="?action=download_vsix" class="btn btn-primary btn-sm">
                                <?= renderSvg('download', 13) ?>
                                <span>Unduh Ekstensi .VSIX</span>
                            </a>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="openProfileModal()">
                                <?= renderSvg('user', 13) ?>
                                <span><?= __t('Edit Profil & Password', 'Edit Profile & Password') ?></span>
                            </button>
                        </div>
                    </div>

                    <div style="margin-top: 18px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 280px; display: flex; align-items: center; gap: 8px; background: var(--bg-surface-inset); border: 1px solid var(--border-subtle); padding: 8px 12px; border-radius: var(--radius-md);">
                            <span style="font-size: 11px; font-weight: 500; color: var(--text-muted); text-transform: uppercase;">API Key:</span>
                            <code style="font-size: 12.5px; color: #fff; word-break: break-all; flex: 1;"><?= htmlspecialchars($currentUser['api_key']) ?></code>
                            <button type="button" class="btn btn-icon btn-ghost" onclick="copyText('<?= htmlspecialchars($currentUser['api_key']) ?>', 'API Secret Key')" title="Salin API Key">
                                <?= renderSvg('copy', 13) ?>
                            </button>
                        </div>

                        <!-- Zona Waktu Default Akun -->
                        <?php 
                            $userTz = !empty($currentUser['timezone']) ? $currentUser['timezone'] : 'Asia/Jakarta';
                            $userTzAbbr = getTimezoneAbbr($userTz);
                        ?>
                        <form method="POST" action="?action=save_user_timezone" style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                            <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                            <div style="min-width: 170px;">
                                <select name="timezone" style="padding: 7px 10px; font-size: 12px;">
                                    <?php foreach (getSupportedTimezones() as $tzKey => $tzLabel): ?>
                                        <option value="<?= $tzKey ?>" <?= $userTz === $tzKey ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($tzLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-secondary btn-sm" title="Simpan Zona Waktu Akun">Simpan</button>
                        </form>
                    </div>

                    <!-- Box Form Ganti Password (Collapsible) -->
                    <div id="boxChangePass" style="display: none; background: var(--bg-surface-inset); border: 1px dashed var(--border-medium); border-radius: var(--radius-md); padding: 16px; margin-top: 16px;">
                        <h4 style="font-size: 13.5px; font-weight: 500; color: #ffffff; margin-bottom: 12px;">Ganti Password Akun</h4>
                        <form method="POST" action="?action=change_password">
                            <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                            <input type="hidden" name="tab" value="projects">
                            <div class="grid-3" style="margin-bottom: 12px;">
                                <div class="form-group">
                                    <label>Password Saat Ini:</label>
                                    <input type="password" name="current_password" placeholder="Password lama" required>
                                </div>
                                <div class="form-group">
                                    <label>Password Baru:</label>
                                    <input type="password" name="new_password" minlength="6" placeholder="Password baru" required>
                                    <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;">Minimal 6 karakter.</small>
                                </div>
                                <div class="form-group">
                                    <label>Konfirmasi Password Baru:</label>
                                    <input type="password" name="confirm_password" minlength="6" placeholder="Ulangi password baru" required>
                                </div>
                            </div>
                            <div style="display: flex; gap: 8px;">
                                <button type="submit" class="btn btn-primary btn-sm">Simpan Password Baru</button>
                                <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('boxChangePass').style.display='none'">Batal</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Header Daftar Proyek & Tombol Tambah -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h3 style="font-size: 16px; font-weight: 500; color: #ffffff; display: flex; align-items: center; gap: 8px;">
                        <?= renderSvg('settings', 16) ?>
                        <span>Daftar Proyek & Bot Telegram</span>
                    </h3>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="const p = document.getElementById('boxNewProject'); p.style.display = p.style.display === 'none' ? 'block' : 'none';">
                        <?= renderSvg('plus', 13) ?>
                        <span>Tambah Proyek Baru</span>
                    </button>
                </div>

                <!-- Panduan Multi-Project VS Code & 1 Bot Telegram -->
                <div class="card" style="margin-bottom: 22px; padding: 20px;">
                    <h4 style="font-size: 14px; font-weight: 500; color: #fff; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                        <?= renderSvg('bot', 15) ?>
                        <span>Routing Multi-Proyek dalam 1 Bot Telegram</span>
                    </h4>
                    <p style="font-size: 12.5px; color: var(--text-secondary); line-height: 1.6; margin-bottom: 12px;">
                        Jika Anda membuka 2 VS Code (misal <b>Folder A</b> dan <b>Folder B</b>) dalam 1 bot Telegram / 1 grup chat:
                    </p>
                    <div class="grid-2" style="margin-bottom: 12px;">
                        <div class="card-inner">
                            <b style="color: #6ee7b7; font-size: 12px;">1. Setting di VS Code Folder A:</b>
                            <code style="display: block; margin-top: 6px; padding: 6px 10px; background: rgba(0,0,0,0.4); border-radius: 4px; font-size: 11.5px;">{ "telegramNotifier.project": "a" }</code>
                        </div>
                        <div class="card-inner">
                            <b style="color: #7dd3fc; font-size: 12px;">2. Setting di VS Code Folder B:</b>
                            <code style="display: block; margin-top: 6px; padding: 6px 10px; background: rgba(0,0,0,0.4); border-radius: 4px; font-size: 11.5px;">{ "telegramNotifier.project": "b" }</code>
                        </div>
                    </div>
                    <div class="card-inner" style="font-size: 12px; line-height: 1.6; color: var(--text-secondary);">
                        <b style="color: #fff;">Cara Kirim Perintah dari Telegram:</b><br>
                        • <b>Per-Pesan:</b> Awali pesan dengan tag kode proyek (contoh: <code>#a buatkan login</code> atau <code>#b perbaiki tombol</code>).<br>
                        • <b>Atau Ganti Proyek Aktif:</b> Ketik <code>/use a</code> atau <code>/use b</code>. Setelah itu pesan biasa tanpa tag otomatis masuk ke proyek aktif.
                    </div>
                </div>

                <!-- Form Tambah Proyek Baru (Collapsible) -->
                <div class="card" id="boxNewProject" style="display: none; margin-bottom: 22px;">
                    <h4 style="font-size: 14px; font-weight: 500; color: #fff; margin-bottom: 14px;">Tambah Proyek Baru</h4>
                    <form method="POST" action="?action=create_project">
                        <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                        <div class="grid-2">
                            <div class="form-group">
                                <label>Nama Proyek * :</label>
                                <input type="text" name="name" placeholder="Contoh: E-Commerce Mobile App" required>
                            </div>
                            <div class="form-group">
                                <label>Kode Tag Telegram (Unik Global, <?= !empty($isSuperAdmin) ? 'Bebas 1-20 Karakter' : 'Minimal 5 Karakter' ?>) * :</label>
                                <?php $defCode = generateUniqueProjectCode($db); ?>
                                <input type="text" name="code" id="new_project_code" value="<?= $defCode ?>" placeholder="Contoh: <?= $defCode ?>" style="color: #fff; font-weight: 600; letter-spacing: 1px;" minlength="<?= !empty($isSuperAdmin) ? '1' : '5' ?>" onblur="checkTagUniqueness(this, 0, 'new_code_feedback', 'btnCreateProject')" oninput="checkTagUniqueness(this, 0, 'new_code_feedback', 'btnCreateProject')" required>
                                <div id="new_code_feedback" style="font-size: 11.5px; margin-top: 4px; color: var(--text-muted);">
                                    Prefix di Telegram: <code>#<?= $defCode ?></code> (<?= !empty($isSuperAdmin) ? 'Admin: bebas 1-20 karakter' : 'Minimal 5 karakter unik di seluruh sistem' ?>)
                                </div>
                            </div>
                        </div>
                        <div class="grid-2">
                            <div class="form-group">
                                <label>Telegram Bot Token :</label>
                                <input type="text" name="bot_token" placeholder="Token dari @BotFather">
                            </div>
                            <div class="form-group">
                                <label>Chat ID / Group ID :</label>
                                <input type="text" name="chat_id" placeholder="Contoh: -0000000000">
                                <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
                                    💡 Ketik <code>/id</code> atau <code>/myid</code> di chat bot Telegram untuk mengetahui Chat ID Anda.
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Zona Waktu Notifikasi :</label>
                            <select name="timezone">
                                <option value="">(Gunakan Default Akun: <?= htmlspecialchars($currentUser['timezone'] ?: 'Asia/Jakarta') ?>)</option>
                                <?php foreach (getSupportedTimezones() as $tzKey => $tzLabel): ?>
                                    <option value="<?= $tzKey ?>"><?= htmlspecialchars($tzLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="display: flex; gap: 8px; margin-top: 14px;">
                            <button type="submit" id="btnCreateProject" class="btn btn-primary btn-sm">Buat Proyek</button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('boxNewProject').style.display='none'">Batal</button>
                        </div>
                    </form>
                </div>

                <!-- Hasil Diagnostik Tes Bot (Jika Ada) -->
                <?php if (!empty($_SESSION['tvsc_bot_diag'])): ?>
                    <?php 
                        $d = $_SESSION['tvsc_bot_diag']; 
                        unset($_SESSION['tvsc_bot_diag']); 
                        $allSuccess = $d['token_ok'] && $d['msg_ok'];
                    ?>
                    <div class="card" style="border-color: <?= $allSuccess ? 'rgba(16, 185, 129, 0.4)' : 'rgba(244, 63, 94, 0.4)' ?>; margin-bottom: 22px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                            <h3 style="font-size: 15px; font-weight: 500; color: #fff;">
                                Diagnostik Bot: <u><?= htmlspecialchars($d['project_name']) ?></u>
                            </h3>
                            <span class="badge <?= $allSuccess ? 'badge-done' : ($d['token_ok'] ? 'badge-pending' : 'badge-failed') ?>">
                                <?= $allSuccess ? 'STATUS 100% NORMAL' : ($d['token_ok'] ? 'PERIKSA CHAT ID' : 'TOKEN TIDAK VALID') ?>
                            </span>
                        </div>
                        <div class="grid-2">
                            <div class="card-inner">
                                <div style="font-size: 11.5px; font-weight: 500; color: var(--text-muted); text-transform: uppercase;">1. Token & Identitas Bot</div>
                                <?php if ($d['token_ok']): ?>
                                    <div style="font-size: 12.5px; margin-top: 4px;">
                                        • Username: <b>@<?= htmlspecialchars($d['bot_info']['username']) ?></b><br>
                                        • Nama: <?= htmlspecialchars($d['bot_info']['first_name']) ?><br>
                                        • ID Bot: <code><?= htmlspecialchars($d['bot_info']['id']) ?></code>
                                    </div>
                                <?php else: ?>
                                    <div style="font-size: 12.5px; color: #fda4af; margin-top: 4px;">❌ <?= htmlspecialchars($d['error_detail']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="card-inner">
                                <div style="font-size: 11.5px; font-weight: 500; color: var(--text-muted); text-transform: uppercase;">2. Pengiriman Notifikasi</div>
                                <?php if ($d['msg_ok']): ?>
                                    <div style="font-size: 12.5px; color: #6ee7b7; margin-top: 4px;">
                                        ✅ Pesan uji coba berhasil diterima di Telegram!
                                    </div>
                                <?php else: ?>
                                    <div style="font-size: 12.5px; color: #fda4af; margin-top: 4px;">
                                        ❌ <?= htmlspecialchars($d['msg_error'] ?? 'Pesan belum terkirim.') ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- List Proyek Milik User -->
                <?php foreach ($projects as $index => $prj): ?>
                    <?php
                        $webhookUrl = "https://biru.co.id/tele-vs-code/webhook?pkey=" . urlencode($prj['project_key']);
                    ?>
                    <div class="card" id="projectCard_<?= $prj['id'] ?>" style="margin-bottom: 20px;">
                        <div class="project-card-header">
                            <!-- Judul di card: 1 kolom, rata kiri, lebar 100% -->
                            <div class="project-card-title-box" onclick="toggleProjectCard(<?= $prj['id'] ?>)" title="Klik untuk membuka / menutup form pengaturan">
                                <h3 style="font-size: 16px; font-weight: 500; color: #fff; display: flex; align-items: center; justify-content: flex-start; flex-wrap: wrap; gap: 8px; width: 100%; text-align: left; margin: 0 0 6px 0;">
                                    <span><?= htmlspecialchars($prj['name']) ?></span>
                                    <span class="badge badge-done">Tag: #<?= htmlspecialchars($prj['code'] ?: 'a') ?></span>
                                    <span style="font-size: 11.5px; background: rgba(56, 189, 248, 0.1); color: #38bdf8; padding: 2px 10px; border-radius: 6px; border: 1px solid rgba(56, 189, 248, 0.3); display: inline-flex; align-items: center; gap: 6px;" onclick="event.stopPropagation();">
                                        <span>Project Key:</span> <code><?= htmlspecialchars($prj['project_key']) ?></code>
                                        <button type="button" class="btn btn-icon btn-ghost" style="width: 18px; height: 18px; color: #38bdf8; padding: 0;" onclick="event.stopPropagation(); copyText('<?= htmlspecialchars($prj['project_key']) ?>', 'Project Key')" title="Salin Project Key">
                                            <?= renderSvg('copy', 11) ?>
                                        </button>
                                    </span>
                                </h3>
                                <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px; display: flex; align-items: center; justify-content: flex-start; flex-wrap: wrap; gap: 6px; width: 100%; text-align: left;" onclick="event.stopPropagation();">
                                    <span>Webhook:</span>
                                    <code><?= $webhookUrl ?></code>
                                    <button type="button" class="btn btn-icon btn-ghost" style="width: 20px; height: 20px;" onclick="event.stopPropagation(); copyText('<?= htmlspecialchars($webhookUrl) ?>', 'Webhook URL')" title="Salin Webhook">
                                        <?= renderSvg('copy', 11) ?>
                                    </button>
                                </div>
                            </div>
                            <div class="project-card-actions-box">
                                <?php if (!empty($prj['bot_token'])): ?>
                                    <button type="button" onclick="openSetupBotWizard(<?= $prj['id'] ?>, '<?= htmlspecialchars(addslashes($prj['name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($prj['bot_token']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($prj['bot_username'] ?? ''), ENT_QUOTES) ?>')" class="btn btn-secondary btn-sm" title="Jalankan Wizard Setup Bot">
                                        <?= renderSvg('bot', 13) ?>
                                        <span id="btnSetupBotLabel_<?= $prj['id'] ?>">Setup Bot <?= !empty($prj['bot_username']) ? ('@' . htmlspecialchars($prj['bot_username'])) : '' ?></span>
                                    </button>
                                    <a href="?action=connect_bot_commands&id=<?= $prj['id'] ?>" class="btn btn-outline btn-sm" title="Hubungkan Perintah & Webhook ke Bot Telegram agar bot bisa menerima instruksi">
                                        <?= renderSvg('terminal', 13) ?>
                                        <span>Hubungkan Perintah</span>
                                    </a>
                                <?php endif; ?>
                                <!-- Tombol Minimize / Collapse Card (Default Tertutup) -->
                                <button type="button" class="btn btn-outline btn-sm" id="btnToggleProject_<?= $prj['id'] ?>" onclick="toggleProjectCard(<?= $prj['id'] ?>)" title="Buka / Minimalkan Form Proyek" style="display: inline-flex; align-items: center; gap: 6px;">
                                    <span id="iconToggleProject_<?= $prj['id'] ?>" style="display: inline-flex; align-items: center; transition: transform 0.25s ease;">
                                        <?= renderSvg('chevron-down', 13) ?>
                                    </span>
                                    <span id="textToggleProject_<?= $prj['id'] ?>">Buka</span>
                                </button>
                            </div>
                        </div>

                        <!-- Form Edit Proyek (Collapsible, Default Tertutup) -->
                        <form method="POST" action="?action=save_project" id="projectForm_<?= $prj['id'] ?>" style="display: none; margin-top: 18px; border-top: 1px solid var(--border-subtle); padding-top: 18px;">
                            <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                            <input type="hidden" name="project_id" value="<?= $prj['id'] ?>">

                            <div class="grid-2">
                                <div class="form-group">
                                    <label>Nama Proyek:</label>
                                    <input type="text" name="name" value="<?= htmlspecialchars($prj['name']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Kode Tag Telegram (Unik Global, <?= !empty($isSuperAdmin) ? 'Bebas 1-20 Karakter' : 'Minimal 5 Karakter' ?>) * :</label>
                                    <input type="text" name="code" id="edit_project_code_<?= $prj['id'] ?>" value="<?= htmlspecialchars($prj['code'] ?? '') ?>" placeholder="<?= !empty($isSuperAdmin) ? '1-20 karakter' : 'Minimal 5 karakter' ?>" style="font-weight: 600; color: #fff; letter-spacing: 1px;" minlength="<?= !empty($isSuperAdmin) ? '1' : '5' ?>" onblur="checkTagUniqueness(this, <?= $prj['id'] ?>, 'edit_code_feedback_<?= $prj['id'] ?>', 'btnSaveProject_<?= $prj['id'] ?>')" oninput="checkTagUniqueness(this, <?= $prj['id'] ?>, 'edit_code_feedback_<?= $prj['id'] ?>', 'btnSaveProject_<?= $prj['id'] ?>')" required>
                                    <div id="edit_code_feedback_<?= $prj['id'] ?>" style="font-size: 11.5px; margin-top: 4px; color: var(--text-muted);">
                                        Prefix di Telegram: <code>#<?= htmlspecialchars($prj['code'] ?? '') ?></code> (<?= !empty($isSuperAdmin) ? 'Admin: bebas 1-20 karakter' : 'Minimal 5 karakter' ?>)
                                    </div>
                                </div>
                            </div>

                            <div class="grid-2">
                                <div class="form-group">
                                    <label>Telegram Bot Token:</label>
                                    <input type="text" name="bot_token" value="<?= htmlspecialchars($prj['bot_token']) ?>" placeholder="123456789:ABCdef...">
                                </div>
                                <div class="form-group">
                                    <label>Group / Chat ID:</label>
                                    <input type="text" name="chat_id" value="<?= htmlspecialchars($prj['chat_id']) ?>" placeholder="-0000000000">
                                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
                                        💡 Ketik <code>/id</code> atau <code>/myid</code> di grup Telegram atau chat pribadi bot untuk melihat Chat ID.
                                    </div>
                                </div>
                            </div>

                            <div class="grid-2" style="margin-bottom: 14px;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label>Zona Waktu Proyek:</label>
                                    <?php 
                                        $pTz = !empty($prj['timezone']) ? $prj['timezone'] : (!empty($currentUser['timezone']) ? $currentUser['timezone'] : 'Asia/Jakarta');
                                        $pAbbr = getTimezoneAbbr($pTz);
                                    ?>
                                    <select name="timezone">
                                        <option value="">(Default Akun: <?= htmlspecialchars($currentUser['timezone'] ?: 'Asia/Jakarta') ?>)</option>
                                        <?php foreach (getSupportedTimezones() as $tzKey => $tzLabel): ?>
                                            <option value="<?= $tzKey ?>" <?= (!empty($prj['timezone']) && $prj['timezone'] === $tzKey) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($tzLabel) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label>Key Proyek (Read-only):</label>
                                    <input type="text" value="<?= htmlspecialchars($prj['project_key']) ?>" readonly style="color: var(--text-muted);">
                                </div>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 6px;">
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <button type="submit" class="btn btn-primary btn-sm">Simpan Pengaturan</button>
                                    <?php if (!empty($prj['bot_token'])): ?>
                                        <button type="button" onclick="openSetupBotWizard(<?= $prj['id'] ?>, '<?= htmlspecialchars(addslashes($prj['name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($prj['bot_token']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($prj['bot_username'] ?? ''), ENT_QUOTES) ?>')" class="btn btn-outline btn-sm">Setup Bot <?= !empty($prj['bot_username']) ? ('@' . htmlspecialchars($prj['bot_username'])) : '' ?></button>
                                        <a href="?action=connect_bot_commands&id=<?= $prj['id'] ?>" class="btn btn-secondary btn-sm" title="Hubungkan Perintah & Webhook agar bot dapat menerima perintah di Telegram">
                                            <?= renderSvg('terminal', 13) ?>
                                            <span>Hubungkan Perintah</span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <a href="?action=delete_project&id=<?= $prj['id'] ?>&csrf=<?= getCsrfToken() ?>" onclick="return confirm('<?= count($projects) > 1 ? ('Yakin ingin menghapus proyek ' . htmlspecialchars($prj['name']) . '?') : ('⚠️ Perhatian: Ini adalah satu-satunya proyek Anda. Yakin ingin menghapus proyek ' . htmlspecialchars($prj['name']) . '?') ?>')" class="btn btn-danger btn-sm" title="Hapus Proyek" style="display: inline-flex; align-items: center; gap: 6px;">
                                    <?= renderSvg('trash', 13) ?>
                                    <span>Hapus Proyek</span>
                                </a>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>

                <!-- Whitelist Pengguna Private Chat Telegram -->
                <div class="card" style="margin-top: 24px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
                        <div>
                            <h3 style="font-size: 15px; font-weight: 500; color: #fff; display: flex; align-items: center; gap: 8px;">
                                <?= renderSvg('user', 15) ?>
                                <span>Whitelist Pengguna Private Chat Telegram</span>
                            </h3>
                            <p style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                                Izinkan ID Telegram mengirimkan tugas secara 1-on-1 ke bot tanpa harus melalui grup.
                            </p>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="const b = document.getElementById('boxNewWhitelist'); b.style.display = b.style.display === 'none' ? 'block' : 'none';">
                            <?= renderSvg('plus', 13) ?>
                            <span>Tambah User Whitelist</span>
                        </button>
                    </div>

                    <!-- Informasi Cara Mendapatkan User ID Telegram -->
                    <div style="background: rgba(56, 189, 248, 0.08); border: 1px solid rgba(56, 189, 248, 0.25); border-radius: var(--radius-md); padding: 14px 18px; margin-bottom: 18px; font-size: 12.5px; color: #bae6fd; line-height: 1.6;">
                        <div style="display: flex; align-items: center; gap: 8px; font-weight: 600; color: #38bdf8; margin-bottom: 4px;">
                            <?= renderSvg('help', 15) ?>
                            <span>Cara Mengetahui User ID Telegram Anda:</span>
                        </div>
                        <div>
                            Buka aplikasi Telegram dan kirim pesan perintah <code>/id</code> atau <code>/myid</code> ke <b>Bot Telegram</b> proyek Anda di obrolan pribadi (private message). Bot akan langsung membalas dengan <b>User ID Anda</b>, <b>ID Bot</b>, serta panduan pemasangan. Salin nomor User ID tersebut lalu klik tombol <b>Tambah User Whitelist</b> di atas untuk mendaftarkannya.
                        </div>
                    </div>

                    <!-- Form Tambah Whitelist (Collapsible) -->
                    <div id="boxNewWhitelist" style="display: none; background: var(--bg-surface-inset); border: 1px dashed var(--border-medium); border-radius: var(--radius-md); padding: 16px; margin-bottom: 18px;">
                        <h4 style="font-size: 13.5px; font-weight: 500; color: #fff; margin-bottom: 12px;">Daftarkan User ke Whitelist</h4>
                        <form method="POST" action="?action=add_whitelist">
                            <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                            <div class="grid-3">
                                <div class="form-group">
                                    <label>Telegram User ID * :</label>
                                    <input type="text" name="telegram_user_id" placeholder="Contoh: 123456789" required>
                                </div>
                                <div class="form-group">
                                    <label>Username Telegram :</label>
                                    <input type="text" name="telegram_username" placeholder="@username">
                                </div>
                                <div class="form-group">
                                    <label>Nama / Label :</label>
                                    <input type="text" name="name" placeholder="Budi (Frontend Dev)">
                                </div>
                            </div>
                            <div class="grid-2">
                                <div class="form-group">
                                    <label>Berlaku Untuk Proyek :</label>
                                    <select name="project_id">
                                        <option value="0">Semua Proyek (Global Akun)</option>
                                        <?php foreach ($projects as $p): ?>
                                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div style="display: flex; align-items: flex-end; gap: 8px; margin-bottom: 16px;">
                                    <button type="submit" class="btn btn-primary btn-sm" style="height: 38px;">Simpan ke Whitelist</button>
                                    <button type="button" class="btn btn-outline btn-sm" style="height: 38px;" onclick="document.getElementById('boxNewWhitelist').style.display='none'">Batal</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Tabel Whitelist -->
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>User ID</th>
                                    <th>Username</th>
                                    <th>Nama / Keterangan</th>
                                    <th>Proyek</th>
                                    <th>Terdaftar</th>
                                    <th style="text-align: right;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($whitelistUsers)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                            <i>Belum ada user di whitelist private chat.</i>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($whitelistUsers as $wu): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars($wu['telegram_user_id']) ?></code></td>
                                            <td><?= !empty($wu['telegram_username']) ? '@' . htmlspecialchars($wu['telegram_username']) : '-' ?></td>
                                            <td><b><?= htmlspecialchars($wu['name'] ?: '-') ?></b></td>
                                            <td>
                                                <span class="badge badge-project">
                                                    <?= empty($wu['project_id']) ? 'Semua Proyek' : htmlspecialchars($wu['project_name'] ?: 'ID: ' . $wu['project_id']) ?>
                                                </span>
                                            </td>
                                            <td style="font-size: 11.5px; color: var(--text-muted);"><?= $wu['created_at'] ?></td>
                                            <td style="text-align: right;">
                                                <a href="?action=delete_whitelist&id=<?= $wu['id'] ?>&csrf=<?= getCsrfToken() ?>" class="btn btn-icon btn-ghost" style="color: var(--accent-danger);" onclick="return confirm('Hapus user dari whitelist?')" title="Hapus">
                                                    <?= renderSvg('trash', 13) ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
