<?php
// views/tab_tasks.php - Task Queue List, Priority Badges, Dropzone & Clipboard Paste
if (!defined('TELE_VS_CODE')) exit;
?>
        
            <!-- ================================================================ -->
            <!-- TAB 1: ANTREAN TUGAS BERDASARKAN PROYEK                          -->
            <!-- ================================================================ -->
            <section class="tab-pane">
                                <!-- Card Akun & Kredensial Pribadi & Status Bot Telegram -->
                <?php
                    // Ambil Proyek yang sedang dipilih atau proyek utama untuk status bot
                    $targetBotProject = null;
                    if (!empty($filterProjectId)) {
                        foreach ($projects as $p) {
                            if ($p['id'] == $filterProjectId) { $targetBotProject = $p; break; }
                        }
                    }
                    if (!$targetBotProject) {
                        // Cari proyek yang bot-nya belum terverifikasi untuk diprioritaskan
                        foreach ($projects as $p) {
                            if (empty($p['bot_verified_at']) && !empty($p['bot_token'])) {
                                $targetBotProject = $p;
                                break;
                            }
                        }
                    }
                    if (!$targetBotProject && !empty($projects)) {
                        $targetBotProject = $projects[0];
                    }

                    $isBotVerified = !empty($targetBotProject['bot_verified_at']);
                    $hasBotToken = !empty($targetBotProject['bot_token']);
                    $botNameDisplay = !empty($targetBotProject['bot_username']) ? ('@' . ltrim($targetBotProject['bot_username'], '@')) : (!empty($targetBotProject['name']) ? $targetBotProject['name'] : 'Bot');
                ?>
                <div class="card" style="background: linear-gradient(135deg, rgba(22, 22, 28, 0.95), rgba(12, 12, 15, 0.85)); border: 1px solid rgba(255, 255, 255, 0.16); border-radius: 12px; padding: 20px 22px; margin-bottom: 22px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
                        <div style="flex: 1; min-width: 290px;">
                            <h2 style="font-size: 16px; font-weight: 600; color: #ffffff; display: flex; align-items: center; gap: 8px; margin: 0 0 6px 0;">
                                <?= renderSvg('key', 16) ?>
                                <span><?= __t('Akun & Kredensial Pribadi', 'Account & Personal Credentials') ?></span>
                            </h2>
                            <p style="font-size: 12.5px; color: var(--text-secondary); margin: 0 0 14px 0; line-height: 1.5;">
                                <?= __t('Gunakan <b>API Secret Key</b> ini untuk menghubungkan ekstensi Visual Studio Code dengan dashboard antrean.', 'Use this <b>API Secret Key</b> to connect the Visual Studio Code extension with the queue dashboard.') ?>
                            </p>

                            <!-- API Key Box & Zona Waktu -->
                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <div style="flex: 1; min-width: 260px; max-width: 480px; display: flex; align-items: center; gap: 8px; background: var(--bg-surface-inset); border: 1px solid var(--border-subtle); padding: 8px 12px; border-radius: var(--radius-md);">
                                    <span style="font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase;">API Key:</span>
                                    <code style="font-size: 12.5px; color: #fff; word-break: break-all; flex: 1; font-family: monospace;"><?= htmlspecialchars($currentUser['api_key']) ?></code>
                                    <button type="button" class="btn btn-icon btn-ghost" onclick="copyText('<?= htmlspecialchars($currentUser['api_key']) ?>', 'API Secret Key')" title="<?= __t('Salin API Key', 'Copy API Key') ?>" style="padding: 4px;">
                                        <?= renderSvg('copy', 13) ?>
                                    </button>
                                </div>

                                <?php 
                                    $userTz = !empty($currentUser['timezone']) ? $currentUser['timezone'] : 'Asia/Jakarta';
                                    $userTzAbbr = getTimezoneAbbr($userTz);
                                ?>
                                <div style="display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-subtle); padding: 8px 12px; border-radius: var(--radius-md); font-size: 12px; color: var(--text-secondary);">
                                    <?= renderSvg('clock', 12) ?>
                                    <span><?= htmlspecialchars($userTz) ?> (<?= htmlspecialchars($userTzAbbr) ?>)</span>
                                </div>
                            </div>
                        </div>

                        <!-- Tombol Aksi Kredensial -->
                        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                            <a href="?action=download_vsix" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
                                <?= renderSvg('download', 13) ?>
                                <span><?= __t('Unduh Ekstensi .VSIX', 'Download .VSIX Extension') ?></span>
                            </a>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="openProfileModal()" style="display: inline-flex; align-items: center; gap: 6px;">
                                <?= renderSvg('user', 13) ?>
                                <span><?= __t('Edit Profil & Password', 'Edit Profile & Password') ?></span>
                            </button>
                        </div>
                    </div>

                    <!-- Divider Garis Halus -->
                    <div style="height: 1px; background: rgba(255, 255, 255, 0.08); margin: 16px 0;"></div>

                    <!-- Status Bot Telegram & Tombol Setup Bot -->
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; background: rgba(0, 0, 0, 0.35); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 10px; padding: 12px 16px;">
                        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                            <div style="font-size: 13px; font-weight: 600; color: #fff; display: flex; align-items: center; gap: 6px;">
                                <?= renderSvg('bot', 15) ?>
                                <span>Status Bot Telegram:</span>
                                <?php if ($targetBotProject): ?>
                                    <span style="font-weight: 400; color: var(--text-muted); font-size: 12px;">(Proyek: <?= htmlspecialchars($targetBotProject['name']) ?>)</span>
                                <?php endif; ?>
                            </div>

                            <?php if (!$targetBotProject || !$hasBotToken): ?>
                                <!-- Kondisi 1: Belum Ada Bot Token -->
                                <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); font-size: 11.5px; padding: 4px 10px; border-radius: 6px; display: inline-flex; align-items: center; gap: 5px;">
                                    <?= renderSvg('alert-circle', 12) ?>
                                    <span><?= __t('Bot Token Belum Diatur', 'Bot Token Not Configured') ?></span>
                                </span>
                            <?php elseif ($isBotVerified): ?>
                                <!-- Kondisi 2: Bot Sudah Terverifikasi -->
                                <span class="badge" style="background: rgba(34, 197, 94, 0.15); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.3); font-size: 11.5px; padding: 4px 10px; border-radius: 6px; display: inline-flex; align-items: center; gap: 5px;">
                                    <?= renderSvg('check', 12) ?>
                                    <span><?= __t('Bot Terverifikasi', 'Bot Verified') ?>: <b><?= htmlspecialchars($botNameDisplay) ?></b></span>
                                </span>
                                <span style="font-size: 11.5px; color: var(--text-muted);">
                                    <?= __t('Terhubung sejak', 'Connected since') ?> <?= date('d M Y H:i', strtotime($targetBotProject['bot_verified_at'])) ?>
                                </span>
                            <?php else: ?>
                                <!-- Kondisi 3: Bot Belum Terverifikasi -->
                                <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.35); font-size: 11.5px; padding: 4px 10px; border-radius: 6px; display: inline-flex; align-items: center; gap: 5px;">
                                    <?= renderSvg('clock', 12) ?>
                                    <span><?= __t('Bot Belum Terverifikasi', 'Bot Not Verified Yet') ?>: <b><?= htmlspecialchars($botNameDisplay) ?></b></span>
                                </span>
                                <span style="font-size: 11.5px; color: #fcd34d;">
                                    <?= __t('Silakan jalankan setup wizard untuk verifikasi & menghubungkan perintah bot.', 'Please run setup wizard to verify & connect bot commands.') ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Tombol Setup Bot (Muncul jika belum terverifikasi) -->
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <?php if ($targetBotProject && $hasBotToken && !$isBotVerified): ?>
                                <button type="button" onclick="openSetupBotWizard(<?= $targetBotProject['id'] ?>, '<?= htmlspecialchars(addslashes($targetBotProject['name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($targetBotProject['bot_token']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($targetBotProject['bot_username'] ?? ''), ENT_QUOTES) ?>')" class="btn btn-primary btn-sm" style="background: #f59e0b; color: #000; font-weight: 700; border: none; display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 6px; box-shadow: 0 2px 10px rgba(245, 158, 11, 0.3);" title="Jalankan Wizard Setup Bot">
                                    <?= renderSvg('bot', 13) ?>
                                    <span>Setup Bot <?= htmlspecialchars($botNameDisplay) ?></span>
                                </button>
                            <?php elseif ($targetBotProject && $hasBotToken && $isBotVerified): ?>
                                <button type="button" onclick="openSetupBotWizard(<?= $targetBotProject['id'] ?>, '<?= htmlspecialchars(addslashes($targetBotProject['name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($targetBotProject['bot_token']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($targetBotProject['bot_username'] ?? ''), ENT_QUOTES) ?>')" class="btn btn-outline btn-sm" style="display: inline-flex; align-items: center; gap: 6px;" title="Buka Wizard Setup Bot Kembali">
                                    <?= renderSvg('settings', 13) ?>
                                    <span>Setup Ulang Bot</span>
                                </button>
                            <?php else: ?>
                                <a href="?tab=projects" class="btn btn-outline btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
                                    <?= renderSvg('plus', 13) ?>
                                    <span><?= __t('Atur Bot di Pengaturan', 'Setup Bot in Settings') ?></span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 4 Counter Stats Cards (Slender Typography) -->
                <div class="grid-4" style="margin-bottom: 22px;">
                    <div class="stat-card">
                        <div class="val" id="stat-val-total"><?= $taskStats['TOTAL'] ?></div>
                        <div class="lbl">
                            <?= renderSvg('layers', 12) ?>
                            <span><?= __t('Total Tugas', 'Total Tasks') ?></span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="val" id="stat-val-pending" style="color: var(--accent-warning);"><?= $taskStats['PENDING'] ?></div>
                        <div class="lbl">
                            <?= renderSvg('clock', 12) ?>
                            <span><?= __t('Menunggu (PENDING)', 'Pending Queue') ?></span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="val" id="stat-val-progress" style="color: var(--accent-info);"><?= $taskStats['IN_PROGRESS'] ?></div>
                        <div class="lbl">
                            <?= renderSvg('play', 12) ?>
                            <span><?= __t('Sedang Dikerjakan', 'In Progress') ?></span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="val" id="stat-val-done" style="color: var(--accent-success);"><?= $taskStats['DONE'] ?></div>
                        <div class="lbl">
                            <?= renderSvg('check', 12) ?>
                            <span><?= __t('Selesai (DONE)', 'Completed') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Filter & Tambah Tugas Bar -->
                <div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
                    <form method="GET" action="./" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
                        <input type="hidden" name="tab" value="tasks">
                        
                        <div style="flex: 1; min-width: 200px;">
                            <label><?= __t('Filter Proyek:', 'Filter Project:') ?></label>
                            <select name="filter_project" onchange="this.form.submit()">
                                <option value="0"><?= __t('Semua Proyek', 'All Projects') ?> (<?= count($projects) ?> <?= __t('Proyek', 'Projects') ?>)</option>
                                <option value="ide" <?= (!empty($filterOnlyIde)) ? 'selected' : '' ?>>💻 <?= __t('Khusus Perintah IDE Chat / VS Code', 'IDE Chat & VS Code Commands') ?></option>
                                <?php foreach ($projects as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= $filterProjectId == $p['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="width: 180px;">
                            <label><?= __t('Status Tugas:', 'Task Status:') ?></label>
                            <select name="filter_status" onchange="this.form.submit()">
                                <option value=""><?= __t('Semua Status', 'All Statuses') ?></option>
                                <option value="PENDING" <?= $filterStatus === 'PENDING' ? 'selected' : '' ?>><?= __t('PENDING (Menunggu)', 'PENDING (Queued)') ?></option>
                                <option value="IN_PROGRESS" <?= $filterStatus === 'IN_PROGRESS' ? 'selected' : '' ?>><?= __t('IN_PROGRESS (Dikerjakan)', 'IN_PROGRESS (Running)') ?></option>
                                <option value="DONE" <?= $filterStatus === 'DONE' ? 'selected' : '' ?>><?= __t('DONE (Selesai)', 'DONE (Completed)') ?></option>
                            </select>
                        </div>

                        <div style="padding-bottom: 1px;">
                            <button type="button" class="btn btn-secondary" onclick="const b = document.getElementById('boxAddTask'); b.style.display = b.style.display === 'none' ? 'block' : 'none';">
                                <?= renderSvg('plus', 13) ?>
                                <span><?= __t('Tambah Manual', 'Add Manually') ?></span>
                            </button>
                        </div>
                    </form>

                    <!-- Box Tambah Tugas Manual (Collapsible) -->
                    <div id="boxAddTask" style="display: none; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border-subtle);">
                        <form method="POST" action="?action=add_manual_task" id="formAddManualTask" class="ajax-form" data-ajax="true">
                            <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                            <input type="hidden" name="ajax" value="1">
                            <input type="hidden" name="key" id="formManualApiKey" value="<?= htmlspecialchars($currentUser['api_key'] ?? '') ?>">
                            <div class="grid-3" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px;">
                                <div class="form-group">
                                    <label><?= renderSvg('project', 13) ?> Pilih Proyek Tujuan:</label>
                                    <select name="project_id" required class="form-control">
                                        <?php foreach ($projects as $p): ?>
                                            <option value="<?= $p['id'] ?>" <?= $filterProjectId == $p['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($p['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label><?= renderSvg('user', 13) ?> Pengirim / Pemohon:</label>
                                    <input type="text" name="sender" value="Dashboard User" required class="form-control">
                                </div>
                                <div class="form-group">
                                    <label><?= renderSvg('zap', 13) ?> Prioritas Eksekusi:</label>
                                    <select name="priority" id="taskPrioritySelect" class="form-control" style="font-weight: 500;">
                                        <option value="URGENT" style="color: #f87171;">⚡ URGENT (Prioritas Utama / Cepat)</option>
                                        <option value="HIGH" style="color: #fb923c;">🔥 HIGH (Prioritas Tinggi)</option>
                                        <option value="NORMAL" selected style="color: #60a5fa;">🚀 NORMAL (Standar / Default)</option>
                                        <option value="LOW" style="color: #94a3b8;">⏳ LOW (Prioritas Rendah / Santai)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group" style="margin-top: 12px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; flex-wrap: wrap; gap: 4px;">
                                    <label style="margin: 0;">Instruksi Tugas Coding:</label>
                                    <span style="font-size: 11px; color: var(--text-muted); display: flex; align-items: center; gap: 4px;">
                                        <?= renderSvg('copy', 11) ?> Bisa Paste (Ctrl+V) & Upload Lebih dari 1 File (Gambar, Video, Dokumen)
                                    </span>
                                </div>
                                <textarea name="instruction" id="taskInstruction" rows="3" placeholder="Tulis instruksi coding di sini, atau paste (Ctrl+V) beberapa screenshot gambar/video/file langsung ke area ini..." required class="form-control"></textarea>
                            </div>

                            <!-- Dropzone Media Uploader (Multi-File Support) -->
                            <div class="form-group" style="margin-top: 10px;">
                                <div class="dropzone-area" id="taskDropzone">
                                    <input type="file" id="mediaFileInput" multiple accept="image/*,video/*,audio/*,.pdf,.zip,.rar,.7z,.txt,.log,.json,.csv,.md,.doc,.docx,.xls,.xlsx" style="display: none;">
                                    <div class="dropzone-prompt" id="dropzonePrompt">
                                        <div class="dropzone-icon">
                                            <?= renderSvg('upload', 22) ?>
                                        </div>
                                        <div class="dropzone-text">
                                            <b>Tarik & lepas file di sini (bisa lebih dari 1 file)</b>, atau <span class="dropzone-browse">Pilih Berkas</span>
                                        </div>
                                        <div class="dropzone-hint">
                                            Mendukung multi-upload: Screenshot UI (PNG, JPG, WebP), Video demo (MP4, WebM), Audio, PDF, ZIP, atau file log
                                        </div>
                                    </div>
                                    <div class="dropzone-preview" id="dropzonePreview" style="display: none; flex-direction: column; align-items: stretch; gap: 8px; width: 100%;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; padding-bottom: 6px; border-bottom: 1px solid var(--border-subtle);">
                                            <span id="dropzoneFileCountBadge" style="font-size: 12px; font-weight: 600; color: var(--accent-info); display: inline-flex; align-items: center; gap: 5px;">
                                                <?= renderSvg('file', 13) ?> <span>0 File Terlampir</span>
                                            </span>
                                            <div style="display: flex; align-items: center; gap: 6px;">
                                                <button type="button" class="btn btn-secondary btn-xs" id="btnAddMoreFiles" style="padding: 3px 8px; font-size: 11px;">
                                                    <?= renderSvg('plus', 11) ?> Tambah File Lagi
                                                </button>
                                                <button type="button" class="btn btn-ghost btn-xs" id="btnRemoveAttachment" style="padding: 3px 8px; font-size: 11px; color: var(--accent-danger);" title="Hapus Semua Lampiran">
                                                    <?= renderSvg('trash', 11) ?> Hapus Semua
                                                </button>
                                            </div>
                                        </div>
                                        <div id="dropzonePreviewList" style="display: flex; flex-direction: column; gap: 6px; max-height: 240px; overflow-y: auto;"></div>
                                    </div>
                                    <input type="hidden" name="attachment_url" id="taskAttachmentUrl" value="">
                                    <input type="hidden" name="attachment_type" id="taskAttachmentType" value="">
                                    <input type="hidden" name="attachment_name" id="taskAttachmentName" value="">
                                    <input type="hidden" name="attachments_json" id="taskAttachmentsJson" value="[]">
                                </div>
                            </div>

                            <div style="display: flex; gap: 10px; align-items: center; margin-top: 14px;">
                                <button type="submit" id="btnSubmitTask" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
                                    <?= renderSvg('zap', 13) ?>
                                    <span>Simpan ke Antrean</span>
                                </button>
                                <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('boxAddTask').style.display='none'">Batal</button>
                                <span id="formSubmitStatus" style="font-size: 12px; color: var(--text-muted); display: none;"></span>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tabel Antrean Tugas -->
                <div class="card" style="padding: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
                        <h2 style="font-size: 15px; font-weight: 500; color: #fff; display: flex; align-items: center; gap: 8px;">
                            <?= renderSvg('tasks', 15) ?>
                            <span><?= __t('Daftar Antrean Tugas', 'Task Queue List') ?></span>
                            <?php if ($filterProjectId > 0): ?>
                                <?php 
                                    $curPName = 'Proyek Terpilih';
                                    foreach ($projects as $p) { if ($p['id'] == $filterProjectId) $curPName = $p['name']; }
                                ?>
                                <span class="badge badge-project"><?= htmlspecialchars($curPName) ?></span>
                            <?php endif; ?>
                        </h2>
                        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                            <span class="badge badge-live-sync" id="liveSyncBadge">Live Auto-Update</span>
                            <button type="button" class="btn btn-secondary btn-xs" onclick="expandAllTasks()" title="Buka Semua Rincian Tugas">
                                <?= renderSvg('chevron-down', 11) ?> <?= __t('Buka Semua', 'Expand All') ?>
                            </button>
                            <button type="button" class="btn btn-secondary btn-xs" onclick="collapseAllTasks()" title="Tutup Semua Rincian Tugas">
                                <?= renderSvg('chevron-up', 11) ?> <?= __t('Tutup Semua', 'Collapse All') ?>
                            </button>
                            <span id="taskQueueCountSummary" style="font-size: 12px; color: var(--text-muted); margin-left: 6px;">
                                Total <?= $totalTasksCount ?> Tugas (Hal <?= $page ?>/<?= $totalPages ?>)
                            </span>
                        </div>
                    </div>

                    <!-- Banner Notifikasi Tugas Aktif dari IDE Chat -->
                    <div id="activeIdeBannerContainer">
                    <?php
                    $stmtActiveIde = $db->prepare("SELECT t.id, t.instruction, t.status, t.project_id, p.name as project_name FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.user_id = ? AND (t.sender LIKE '%IDE%' OR t.sender LIKE '%VS Code%' OR t.command = 'tele-task add') AND t.status IN ('PENDING', 'IN_PROGRESS') ORDER BY t.created_at DESC LIMIT 1");
                    $stmtActiveIde->execute([$currentUser['id']]);
                    $activeIdeTask = $stmtActiveIde->fetch(PDO::FETCH_ASSOC);
                    ?>
                    <?php if ($activeIdeTask): ?>
                        <div style="background: rgba(56, 189, 248, 0.08); border: 1px solid rgba(56, 189, 248, 0.35); border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size: 16px;">💻</span>
                                <div>
                                    <span style="font-weight: 600; color: #38bdf8;">Perintah dari IDE Aktif:</span>
                                    <code style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; padding: 1px 5px; border-radius: 4px; font-size: 12px;"><?= htmlspecialchars($activeIdeTask['id']) ?></code>
                                    <span style="color: var(--text-secondary); margin-left: 6px; font-size: 13px;"><?= htmlspecialchars(mb_strimwidth($activeIdeTask['instruction'], 0, 80, '...')) ?></span>
                                    <span class="badge badge-project" style="font-size: 11px; margin-left: 4px;"><?= htmlspecialchars($activeIdeTask['project_name'] ?: 'Tele VS Code') ?></span>
                                </div>
                            </div>
                            <span class="badge <?= $activeIdeTask['status'] === 'IN_PROGRESS' ? 'badge-progress' : 'badge-pending' ?>"><?= $activeIdeTask['status'] ?></span>
                        </div>
                    <?php endif; ?>
                    </div>

                    <div id="taskCardsWrapper">
                    <?php require __DIR__ . '/partial_task_cards.php'; ?>
                    </div>
                </div>
            </section>

