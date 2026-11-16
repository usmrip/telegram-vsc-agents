<?php
// views/footer.php - Modals, JavaScript Core, Toast & Dynamic AJAX Engine
if (!defined('TELE_VS_CODE')) exit;
?>
    <footer class="app-footer">
        <div class="footer-credit">
            &copy; <?= date('Y') ?> <b>Telegram VS Code</b> &middot; <?= __t('Karya Dedikasi Anak Magang di', 'Dedicated Creation by Intern Talents at') ?> <a href="https://www.biru.co.id" target="_blank" rel="noopener noreferrer" class="footer-biru-link">Biru Indonesia Creative</a>
        </div>
        <div class="footer-links">
            <button type="button" class="btn btn-secondary btn-sm" onclick="openAboutModal()">
                <?= renderSvg('user', 13) ?>
                <span><?= __t('Profil Kami', 'Our Profile') ?></span>
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="openPrivacyModal()">
                <?= renderSvg('shield', 13) ?>
                <span><?= __t('Regulasi & Privasi Data', 'Regulations & Privacy') ?></span>
            </button>
            <a href="https://ayo.ke/feedback-tele-vs-code" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">
                <?= renderSvg('help', 13) ?>
                <span><?= __t('Saran & Bug', 'Feedback') ?></span>
            </a>
            <a href="https://ayo.ke/donasi" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">
                <span style="font-size: 12px;">❤️</span>
                <span><?= __t('Donasi', 'Donate') ?></span>
            </a>
        </div>
        <div class="social-links">
            <a href="https://www.biru.co.id" target="_blank" rel="noopener noreferrer" class="social-icon-btn" title="Website Biru Software">
                <?= renderSvg('globe', 14) ?>
            </a>
            <a href="https://instagram.com/birusoftware" target="_blank" rel="noopener noreferrer" class="social-icon-btn" title="Instagram @birusoftware">
                <?= renderSvg('instagram', 14) ?>
            </a>
            <a href="https://youtube.com/@birusoftware" target="_blank" rel="noopener noreferrer" class="social-icon-btn" title="YouTube @birusoftware">
                <?= renderSvg('youtube', 14) ?>
            </a>
            <a href="https://wa.me/6282257577772" target="_blank" rel="noopener noreferrer" class="social-icon-btn" title="WhatsApp: +62 822 575 77772">
                <?= renderSvg('whatsapp', 14) ?>
            </a>
            <a href="mailto:hai@biru.co.id" class="social-icon-btn" title="Email: hai@biru.co.id">
                <?= renderSvg('mail', 14) ?>
            </a>
        </div>
    </footer>
</div>

<!-- ======================================================================== -->
<!-- MODALS (iOS Dialog Sheet Style)                                          -->
<!-- ======================================================================== -->

<!-- 1. Modal Detail & Edit Result Tugas -->

<!-- ==================================================================== -->
<!-- Modal Konfirmasi Sapa Bot Sebelum Uji Koneksi (Studio Ink Theme)     -->
<!-- ==================================================================== -->
<!-- ==================================================================== -->
<!-- Modal Interaktif: Setup Bot <nama_BOT> (Studio Ink Theme 3-Steps Wizard) -->
<!-- ==================================================================== -->
<div id="modalSetupBotWizard" class="modal-overlay" style="display: none; align-items: center; justify-content: center; backdrop-filter: blur(6px); z-index: 9999;" onclick="if(event.target === this) closeSetupBotWizard()">
    <div class="modal-dialog" style="max-width: 580px; width: 92%; padding: 28px 26px; text-align: left; background: var(--bg-surface, #14130f); border: 1px solid var(--border-medium, rgba(236,228,214,0.14)); border-radius: 16px; box-shadow: 0 28px 70px rgba(0,0,0,0.75); position: relative; overflow: hidden;">

        <!-- Canvas Efek Konfeti Selebrasi -->
        <canvas id="confettiCanvas" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 50; display: none;"></canvas>

        <!-- Header Wizard -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(255, 90, 31, 0.12); border: 1px solid rgba(255, 90, 31, 0.35); display: inline-flex; align-items: center; justify-content: center; color: var(--signal, #ff5a1f);">
                    <?= renderSvg('bot', 24) ?>
                </div>
                <div>
                    <h3 id="wizHeaderTitle" style="font-family: var(--font-display, 'Instrument Serif', Georgia, serif); font-size: 22px; font-weight: normal; color: var(--text-primary, #ece4d6); margin: 0 0 3px 0; letter-spacing: -0.01em;">
                        Setup Bot <span id="wizBotUsernameText" style="color: var(--signal, #ff5a1f);"></span>
                    </h3>
                    <div style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-muted, #777064);">
                        <span>Proyek: <b id="wizProjectNameText" style="color: var(--text-primary, #ece4d6);"></b></span>
                        <span>•</span>
                        <span style="color: var(--accent-success, #9cc48a); display: inline-flex; align-items: center; gap: 4px;">
                            <span style="width: 7px; height: 7px; border-radius: 50%; background: #22c55e; display: inline-block;"></span>
                            Bot Aktif
                        </span>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-secondary btn-xs" onclick="closeSetupBotWizard()" style="border-radius: 50%; width: 28px; height: 28px; padding: 0; display: flex; align-items: center; justify-content: center; font-size: 16px; color: var(--text-muted);" title="Tutup">&times;</button>
        </div>

        <!-- Stepper Visual (3 Langkah) -->
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 22px;">
            <div id="wizStepPill1" class="step-pill-wiz active" style="padding: 7px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 500; display: flex; align-items: center; gap: 6px; background: rgba(255,90,31,0.08); border: 1px solid var(--signal, #ff5a1f); color: var(--text-primary, #ece4d6); transition: all 0.25s ease;">
                <span id="wizStepNum1" style="width: 18px; height: 18px; border-radius: 50%; background: var(--signal, #ff5a1f); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; flex-shrink: 0;">1</span>
                <span>1. Sapa Bot</span>
            </div>
            <div id="wizStepPill2" class="step-pill-wiz" style="padding: 7px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 500; display: flex; align-items: center; gap: 6px; background: var(--bg-surface-elevated, #1a1814); border: 1px solid var(--border-subtle, rgba(236,228,214,0.08)); color: var(--text-muted, #777064); transition: all 0.25s ease;">
                <span id="wizStepNum2" style="width: 18px; height: 18px; border-radius: 50%; background: rgba(236,228,214,0.1); color: var(--text-secondary, #a8a091); display: inline-flex; align-items: center; justify-content: center; font-size: 10px; flex-shrink: 0;">2</span>
                <span>2. Pasang Perintah</span>
            </div>
            <div id="wizStepPill3" class="step-pill-wiz" style="padding: 7px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 500; display: flex; align-items: center; gap: 6px; background: var(--bg-surface-elevated, #1a1814); border: 1px solid var(--border-subtle, rgba(236,228,214,0.08)); color: var(--text-muted, #777064); transition: all 0.25s ease;">
                <span id="wizStepNum3" style="width: 18px; height: 18px; border-radius: 50%; background: rgba(236,228,214,0.1); color: var(--text-secondary, #a8a091); display: inline-flex; align-items: center; justify-content: center; font-size: 10px; flex-shrink: 0;">3</span>
                <span>3. Verifikasi</span>
            </div>
        </div>

        <!-- ======================= STEP 1: SAPA BOT ======================= -->
        <div id="wizStepContent1" class="wiz-step-pane" style="display: block;">
            <div style="background: rgba(236, 228, 214, 0.03); border: 1px solid var(--border-subtle, rgba(236,228,214,0.08)); border-radius: 12px; padding: 18px; margin-bottom: 20px;">
                <div style="font-size: 14px; font-weight: 600; color: var(--text-primary, #ece4d6); display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                    <span style="font-size: 18px;">💬</span>
                    <span>Apakah Anda (atau anggota grup) sudah pernah menyapa bot Anda di Telegram?</span>
                </div>
                <p style="font-size: 12.5px; color: var(--text-secondary, #a8a091); line-height: 1.6; margin: 0;">
                    Telegram menerapkan aturan privasi ketat: <b>Bot TIDAK BISA mengirim pesan perdana</b> ke akun pribadi atau grup sebelum ada interaksi pembuka dari pengguna (seperti menekan tombol <b>START</b> / <code>/start</code> atau mengirimkan pesan sapaan). Untuk grup, bot wajib dimasukkan ke dalam grup terlebih dahulu.
                </p>
            </div>

            <div style="display: flex; flex-direction: column; gap: 10px;">
                <a href="https://t.me/" id="btnWizOpenBotLink" target="_blank" rel="noopener noreferrer" class="btn btn-secondary" style="width: 100%; justify-content: center; padding: 12px 18px; font-size: 13px; border-radius: 999px;">
                    <?= renderSvg('globe', 14) ?>
                    <span>Buka Bot di Telegram (<span id="wizLinkBotUsername">t.me</span>)</span>
                </a>
                <button type="button" class="btn btn-primary" onclick="goToWizStep(2)" style="width: 100%; justify-content: center; padding: 12px 18px; font-size: 13px; font-weight: 600; background: var(--text-primary, #ece4d6); color: #0d0c0a; border: none; border-radius: 999px;">
                    <?= renderSvg('check', 14) ?>
                    <span>Ya, Sudah Menyapa Bot — Lanjut Langkah 2 ➡️</span>
                </button>
            </div>
        </div>

        <!-- ======================= STEP 2: PASANG PERINTAH ======================= -->
        <div id="wizStepContent2" class="wiz-step-pane" style="display: none;">
            <div style="background: rgba(236, 228, 214, 0.03); border: 1px solid var(--border-subtle, rgba(236,228,214,0.08)); border-radius: 12px; padding: 18px; margin-bottom: 18px;">
                <div style="font-size: 14px; font-weight: 600; color: var(--signal, #ff5a1f); display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                    <?= renderSvg('terminal', 16) ?>
                    <span>Kenapa Harus Memasang Perintah Telegram?</span>
                </div>
                <div style="font-size: 12.5px; color: var(--text-secondary, #a8a091); line-height: 1.65;">
                    <div style="margin-bottom: 8px;">
                        <b style="color: var(--text-primary, #ece4d6);">1. Koneksi Webhook Realtime:</b> Menghubungkan bot dengan server Tele-vs-Code agar setiap instruksi coding, teks biasa, foto, dokumen, atau video yang Anda kirim di Telegram langsung ditangkap dan diteruskan ke AI Agent di VS Code.
                    </div>
                    <div style="margin-bottom: 8px;">
                        <b style="color: var(--text-primary, #ece4d6);">2. Menu Perintah Interaktif [ / ]:</b> Mendaftarkan daftar perintah resmi (<code>/task</code>, <code>/status</code>, <code>/projects</code>, dll.) ke tombol menu Telegram sehingga Anda tidak perlu menghafal format perintah.
                    </div>
                    <div>
                        <b style="color: var(--text-primary, #ece4d6);">3. Tombol Menu Chat:</b> Mengaktifkan tombol menu di obrolan Telegram agar selalu siap digunakan.
                    </div>
                </div>
            </div>

            <div id="wizStep2Feedback" style="display: none; padding: 12px 14px; border-radius: 10px; font-size: 12.5px; margin-bottom: 16px;"></div>

            <div style="display: flex; flex-direction: column; gap: 10px;">
                <button type="button" id="btnWizInstallCommands" class="btn btn-primary" onclick="executeWizInstallCommands()" style="width: 100%; justify-content: center; padding: 12px 18px; font-size: 13px; font-weight: 600; background: var(--text-primary, #ece4d6); color: #0d0c0a; border: none; border-radius: 999px;">
                    <?= renderSvg('zap', 14) ?>
                    <span>⚡ Pasang Perintah & Webhook Sekarang</span>
                </button>
                <button type="button" class="btn btn-secondary" onclick="goToWizStep(1)" style="width: 100%; justify-content: center; padding: 10px 18px; font-size: 12.5px; border-radius: 999px;">
                    <span>⬅️ Kembali ke Langkah 1</span>
                </button>
            </div>
        </div>

        <!-- ======================= STEP 3: VERIFIKASI DUA ARAH ======================= -->
        <div id="wizStepContent3" class="wiz-step-pane" style="display: none;">
            
            <!-- Keadaan 1: Menunggu Verifikasi dari Telegram -->
            <div id="wizStep3Waiting">
                <div style="text-align: center; padding: 14px 10px 20px;">
                    <!-- Animasi Radar Pulse -->
                    <div style="position: relative; width: 68px; height: 68px; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center;">
                        <div style="position: absolute; width: 100%; height: 100%; border-radius: 50%; background: rgba(255,90,31,0.15); animation: radarPulse 1.8s ease-out infinite;"></div>
                        <div style="position: absolute; width: 70%; height: 70%; border-radius: 50%; background: rgba(255,90,31,0.25); animation: radarPulse 1.8s ease-out infinite 0.4s;"></div>
                        <div style="width: 44px; height: 44px; border-radius: 50%; background: var(--signal, #ff5a1f); display: flex; align-items: center; justify-content: center; color: #fff; z-index: 2;">
                            <?= renderSvg('terminal', 20) ?>
                        </div>
                    </div>

                    <h4 style="font-size: 15px; font-weight: 600; color: var(--text-primary, #ece4d6); margin: 0 0 6px 0;">
                        Ketik <code>/verifikasi</code> di Telegram
                    </h4>
                    <p style="font-size: 12.5px; color: var(--text-secondary, #a8a091); line-height: 1.55; margin: 0 auto 16px; max-width: 440px;">
                        Buka aplikasi Telegram Anda di obrolan dengan bot <b id="wizVerifyBotName" style="color: var(--text-primary, #ece4d6);"></b> (atau di grup tujuan), lalu ketik dan kirimkan perintah:
                    </p>

                    <!-- Box Perintah /verifikasi -->
                    <div style="background: var(--bg-surface-inset, #0a0907); border: 1.5px dashed var(--signal, #ff5a1f); border-radius: 12px; padding: 14px; display: inline-flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                        <code style="font-size: 17px; font-weight: 700; color: #fff; letter-spacing: 1px;">/verifikasi</code>
                        <button type="button" class="btn btn-secondary btn-xs" onclick="copyText('/verifikasi', 'Perintah verifikasi')" style="border-radius: 999px;">
                            <?= renderSvg('copy', 12) ?>
                            <span>Salin</span>
                        </button>
                    </div>

                    <div style="display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 12px; color: var(--text-muted, #777064);">
                        <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--signal, #ff5a1f); animation: blinkDot 1s infinite;"></span>
                        <span>Mendengarkan sinyal dari Telegram...</span>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 10px;">
                    <a href="https://t.me/" id="btnWizDirectTg" target="_blank" rel="noopener noreferrer" class="btn btn-secondary" style="flex: 1; justify-content: center; padding: 11px 16px; font-size: 12.5px; border-radius: 999px;">
                        <?= renderSvg('globe', 13) ?>
                        <span>Buka Chat Telegram</span>
                    </a>
                    <button type="button" class="btn btn-outline" onclick="goToWizStep(2)" style="flex: 1; justify-content: center; padding: 11px 16px; font-size: 12.5px; border-radius: 999px;">
                        <span>⬅️ Kembali</span>
                    </button>
                </div>
            </div>

            <!-- Keadaan 2: Verifikasi Berhasil 100% (Konfeti + Ucapan Terima Kasih) -->
            <div id="wizStep3Success" style="display: none; text-align: center; padding: 18px 10px 10px;">
                <div style="width: 62px; height: 62px; border-radius: 50%; background: rgba(156, 196, 138, 0.15); border: 2px solid var(--accent-success, #9cc48a); display: flex; align-items: center; justify-content: center; color: var(--accent-success, #9cc48a); margin: 0 auto 16px; animation: bounceIn 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275);">
                    <?= renderSvg('check', 30) ?>
                </div>

                <h3 style="font-family: var(--font-display, 'Instrument Serif', Georgia, serif); font-size: 26px; font-weight: normal; color: var(--text-primary, #ece4d6); margin: 0 0 6px 0;">
                    🎉 Terima Kasih! Bot Anda Telah Terverifikasi & Terhubung
                </h3>
                <p style="font-size: 13px; color: var(--text-secondary, #a8a091); line-height: 1.6; max-width: 460px; margin: 0 auto 20px;">
                    Bot <b id="wizSuccessBotUsername" style="color: var(--text-primary, #ece4d6);"></b> telah terhubung penuh dengan server dan siap menerima tugas coding kapan saja dari Telegram ke VS Code.
                </p>

                <div style="background: var(--bg-surface-inset, #0a0907); border: 1px solid var(--border-subtle, rgba(236,228,214,0.08)); border-radius: 12px; padding: 14px 18px; margin-bottom: 22px; text-align: left; font-size: 12.5px; color: var(--text-secondary, #a8a091);">
                    <div style="padding: 4px 0; border-bottom: 1px solid var(--border-subtle, rgba(236,228,214,0.08)); display: flex; justify-content: space-between;">
                        <span>Status Bot:</span>
                        <b style="color: var(--accent-success, #9cc48a);">🟢 Terverifikasi & Aktif</b>
                    </div>
                    <div style="padding: 4px 0; border-bottom: 1px solid var(--border-subtle, rgba(236,228,214,0.08)); display: flex; justify-content: space-between;">
                        <span>Waktu Verifikasi:</span>
                        <b id="wizSuccessVerifiedAt" style="color: var(--text-primary, #ece4d6);">-</b>
                    </div>
                    <div style="padding: 4px 0; display: flex; justify-content: space-between;">
                        <span>Perintah Telegram:</span>
                        <b style="color: var(--text-primary, #ece4d6);">Aktif di Tombol [ / ]</b>
                    </div>
                </div>

                <button type="button" class="btn btn-primary" onclick="closeSetupBotWizard(true)" style="width: 100%; justify-content: center; padding: 13px 22px; font-size: 13.5px; font-weight: 700; background: var(--text-primary, #ece4d6); color: #0d0c0a; border: none; border-radius: 999px; box-shadow: 0 4px 18px rgba(0,0,0,0.4);">
                    <?= renderSvg('check', 15) ?>
                    <span>Selesai & Tutup Wizard</span>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes radarPulse {
    0% { transform: scale(0.6); opacity: 0.9; }
    100% { transform: scale(1.6); opacity: 0; }
}
@keyframes blinkDot {
    0%, 100% { opacity: 0.3; }
    50% { opacity: 1; }
}
@keyframes bounceIn {
    0% { transform: scale(0.3); opacity: 0; }
    50% { transform: scale(1.08); opacity: 1; }
    100% { transform: scale(1); }
}
</style>

<div id="modalTaskDetail" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">
                <?= renderSvg('tasks', 16) ?>
                <span>Detail & Hasil Tugas:</span>
                <code id="mTaskId" style="color: #fff; font-size: 13px;"></code>
            </h3>
            <button type="button" class="btn btn-icon btn-ghost" onclick="closeTaskDetail()">
                <?= renderSvg('close', 16) ?>
            </button>
        </div>

        <div class="grid-2" style="margin-bottom: 14px; font-size: 12px;">
            <div><span style="color: var(--text-muted);">Proyek:</span> <b id="mTaskProject" style="color: #fff;"></b></div>
            <div><span style="color: var(--text-muted);">Pengirim:</span> <b id="mTaskSender" style="color: #fff;"></b></div>
            <div><span style="color: var(--text-muted);">Waktu Masuk:</span> <span id="mTaskTime" style="color: #fff;"></span></div>
            <div><span style="color: var(--text-muted);">Status:</span> <span id="mTaskStatusBadge"></span></div>
            <div style="grid-column: span 2;"><span style="color: var(--text-muted);">Eksekutor Agent:</span> <b id="mTaskAgent" style="color: #c084fc; display: inline-flex; align-items: center; gap: 4px;"></b></div>
        </div>

        <div class="form-group">
            <label>Instruksi Tugas:</label>
            <div id="mTaskInstruction" class="card-inner" style="font-size: 13px; max-height: 120px; overflow-y: auto; line-height: 1.5; color: #fff;"></div>
        </div>

        <div class="form-group" id="mTaskAttachmentsWrap" style="display: none;">
            <label id="mTaskAttachmentsLabel">Lampiran File:</label>
            <div id="mTaskAttachmentsList" class="card-inner" style="display: flex; flex-wrap: wrap; gap: 8px; max-height: 180px; overflow-y: auto; padding: 8px;"></div>
        </div>

        <form method="POST" action="?action=save_task_result">
            <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="id" id="mFormTaskId">

            <div class="form-group">
                <label>Command / Perintah (Baris 2):</label>
                <input type="text" name="command" id="mTaskCommand" placeholder="Contoh: /task, /a, #a, tele-task add..." style="font-family: monospace;">
                <small style="color: var(--text-muted); font-size: 11px;">Perintah yang dicatat dan ditampilkan di antrean baris 2.</small>
            </div>

            <div class="form-group">
                <label>Hasil / Result Pekerjaan (Baris 3):</label>
                <textarea name="result" id="mTaskResult" rows="4" placeholder="Masukkan laporan atau hasil pengerjaan tugas di sini..."></textarea>
                <small style="color: var(--text-muted); font-size: 11px;">Tersimpan permanen di database dan dapat dibaca oleh user.</small>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label>Ubah Status Tugas:</label>
                <select name="status" id="mTaskStatusSelect">
                    <option value="PENDING">PENDING (Menunggu)</option>
                    <option value="IN_PROGRESS">IN_PROGRESS (Sedang Dikerjakan)</option>
                    <option value="DONE">DONE (Selesai 100%)</option>
                    <option value="FAILED">FAILED (Kendala / Gagal)</option>
                </select>
            </div>

            <div style="display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap;">
                <button type="button" class="btn btn-outline btn-sm" onclick="copyResultText()">
                    <?= renderSvg('copy', 12) ?>
                    <span>Salin Hasil</span>
                </button>
                <button type="submit" class="btn btn-primary btn-sm">Simpan Perubahan</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeTaskDetail()">Tutup</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Modal Tandai Selesai (iOS Quick Complete Dialog) -->
<div id="modalMarkDone" class="modal-overlay">
    <div class="modal-dialog" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="modal-title">
                <?= renderSvg('check', 16, 'text-success') ?>
                <span>Tandai Selesai:</span>
                <code id="markDoneTaskLabel" style="font-size: 13px; color: #fff;"></code>
            </h3>
            <button type="button" class="btn btn-icon btn-ghost" onclick="closeMarkDoneModal()">
                <?= renderSvg('close', 16) ?>
            </button>
        </div>
        <form method="POST" action="?action=mark_task">
            <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="id" id="markDoneTaskId">
            <input type="hidden" name="status" value="DONE">
            <div class="form-group">
                <label>Ringkasan Hasil Pekerjaan (DONE):</label>
                <textarea name="result" rows="4" placeholder="Contoh: Fitur CRUD dan validasi selesai 100% dan teruji..." required>Tugas selesai dikerjakan 100% dan terverifikasi.</textarea>
                <small style="color: var(--text-muted); font-size: 11px;">Hasil ini akan disimpan ke database dan dilaporkan ke Telegram.</small>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px;">
                <button type="button" class="btn btn-outline btn-sm" onclick="closeMarkDoneModal()">Batal</button>
                <button type="submit" class="btn btn-primary btn-sm">
                    <?= renderSvg('check', 13) ?>
                    <span>Simpan Selesai</span>
                </button>
            </div>
        </form>
    </div>
</div>

<div id="modalResetPass" class="modal-overlay">
    <div class="modal-dialog" style="max-width: 440px;">
        <div class="modal-header">
            <h3 class="modal-title">
                <?= renderSvg('key', 16) ?>
                <span>Reset Password Pengguna</span>
            </h3>
            <button type="button" class="btn btn-icon btn-ghost" onclick="closeResetPassModal()">
                <?= renderSvg('close', 16) ?>
            </button>
        </div>
        <form method="POST" action="?action=admin_reset_password">
            <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="user_id" id="resetPassUserId">
            <div class="form-group">
                <label>Pengguna:</label>
                <div id="resetPassUserEmail" style="font-size: 13px; font-weight: 500; color: #fff; padding: 8px 12px; background: rgba(255,255,255,0.04); border-radius: 8px; margin-bottom: 12px;"></div>
            </div>
            <div class="form-group">
                <label>Password Baru (Minimal 6 Karakter):</label>
                <input type="password" name="new_password" id="resetPassNewPassword" minlength="6" placeholder="Masukkan password baru..." required>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px;">
                <button type="button" class="btn btn-outline btn-sm" onclick="closeResetPassModal()">Batal</button>
                <button type="submit" class="btn btn-primary btn-sm">
                    <?= renderSvg('check', 13) ?>
                    <span>Perbarui Password</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Donasi Tele-vs-Code -->
<div id="modalDonation" class="modal-overlay" style="display: none;" onclick="if(event.target === this) closeDonationModal()">
    <div class="modal-content" style="max-width: 500px; text-align: center; padding: 28px 24px;">
        <div style="display: flex; justify-content: flex-end; margin-top: -8px; margin-right: -8px;">
            <button type="button" class="btn btn-icon btn-ghost" onclick="closeDonationModal()" title="Tutup">
                <?= renderSvg('close', 16) ?>
            </button>
        </div>

        <div style="width: 58px; height: 58px; border-radius: 50%; background: rgba(244, 63, 94, 0.12); border: 1px solid rgba(244, 63, 94, 0.35); display: inline-flex; align-items: center; justify-content: center; font-size: 28px; margin-bottom: 12px;">
            ❤️
        </div>

        <h3 style="font-size: 18px; font-weight: 600; color: #fff; margin-bottom: 4px;">
            Dukung Tele-vs-Code
        </h3>

        <!-- Tujuan Donasi -->
        <div style="background: rgba(255, 255, 255, 0.04); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 12px 16px; margin: 12px 0 16px; font-size: 13.5px; color: #fecdd3; line-height: 1.6;">
            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); margin-bottom: 3px;">Tujuan Donasi</div>
            <b>"bertahan hidup dengan teknologi dan #berkembangBaik"</b>
        </div>

        <p style="font-size: 12.5px; color: var(--text-secondary); line-height: 1.6; margin-bottom: 16px; text-align: left;">
            Terima kasih telah menggunakan <b>Tele-vs-Code</b>! Notifikasi dan antrean tugas AI Agent proyek Anda telah berhasil berjalan normal. Jika alat ini bermanfaat mempermudah pekerjaan Anda, dukung kelanjutan proyek ini melalui 2 langkah mudah:
        </p>

        <!-- Instruksi Like & Review di Marketplace VSIX -->
        <div style="background: rgba(56, 189, 248, 0.07); border: 1px solid rgba(56, 189, 248, 0.25); border-radius: var(--radius-md); padding: 14px 16px; margin-bottom: 20px; text-align: left; font-size: 12px; color: #bae6fd; line-height: 1.6;">
            <div style="display: flex; align-items: center; gap: 8px; font-weight: 600; color: #38bdf8; margin-bottom: 4px;">
                <span style="font-size: 14px;">⭐</span>
                <span>Beri Rating & Komentar di VS Code Marketplace:</span>
            </div>
            <div>
                1. Buka halaman resmi ekstensi <b>Tele-vs-Code</b> di VS Code Marketplace.<br>
                2. Berikan <b>Rating 5 Bintang</b> dan tulis ulasan atau komentar pengalaman Anda menggunakan Tele-vs-Code.<br>
                3. Ulasan Anda sangat berharga agar ekstensi ini terus berkembang dan bermanfaat bagi banyak developer!
            </div>
            <div style="margin-top: 10px;">
                <a href="https://marketplace.visualstudio.com/items?itemName=BiruIndonesiaCreative.tele-vs-code&ssr=false#review-details" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm" style="font-size: 11.5px; width: 100%; justify-content: center; background: rgba(56, 189, 248, 0.12); color: #38bdf8; border-color: rgba(56, 189, 248, 0.4);">
                    <span>⭐ Buka Halaman Review Marketplace &rarr;</span>
                </a>
            </div>
        </div>

        <!-- Tombol Ayo Donasi -->
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <a href="https://ayo.ke/donasi" target="_blank" rel="noopener noreferrer" class="btn btn-primary" style="justify-content: center; font-weight: 600; padding: 11px 20px; font-size: 14px; background: #e11d48; border-color: #be123c; color: #fff; box-shadow: 0 4px 14px rgba(225, 29, 72, 0.4);">
                <span>❤️ Ayo Donasi</span>
            </a>
            <button type="button" class="btn btn-ghost btn-sm" onclick="closeDonationModal()" style="color: var(--text-muted); font-size: 12px;">
                Nanti Saja
            </button>
        </div>
    </div>
</div>

<!-- Sonner Toast Engine & Client Controller -->
<script src="assets/js/liquid.js?v=<?= @filemtime(__DIR__ . '/../assets/js/liquid.js') ?: time() ?>"></script>
<script src="assets/js/sonner.js?v=<?= @filemtime(__DIR__ . '/../assets/js/sonner.js') ?: time() ?>"></script>
<script src="assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: time() ?>"></script>
<script>
window.TVSC_USER_KEY = <?= !empty($currentUser['api_key']) ? json_encode($currentUser['api_key']) : '""' ?>;
const IS_USER_ADMIN = <?= !empty($isSuperAdmin) ? 'true' : 'false' ?>;
let tagCheckTimeout = null;
function checkTagUniqueness(inputEl, excludeId, feedbackId, submitBtnId) {
    clearTimeout(tagCheckTimeout);
    const feedbackEl = document.getElementById(feedbackId);
    const submitBtn = submitBtnId ? document.getElementById(submitBtnId) : null;
    const rawVal = inputEl.value.trim().toLowerCase().replace(/[^a-z0-9_-]/g, '');
    inputEl.value = rawVal;

    const minLen = IS_USER_ADMIN ? 1 : 5;

    if (!rawVal || rawVal.length < minLen) {
        if (feedbackEl) {
            feedbackEl.innerHTML = `<span style="color: #f87171;">❌ Kode TAG minimal ${minLen} karakter!${!IS_USER_ADMIN ? ' (Hanya admin yang boleh kurang dari 5 karakter)' : ''}</span>`;
        }
        if (submitBtn) submitBtn.disabled = true;
        inputEl.style.borderColor = 'rgba(248, 113, 113, 0.7)';
        return;
    }

    tagCheckTimeout = setTimeout(async () => {
        try {
            const res = await fetch(`?action=check_code_unique&code=${encodeURIComponent(rawVal)}&exclude_id=${excludeId}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const rawText = await res.text();
            let data = {};
            try { data = JSON.parse(rawText); } catch (e) {}
            if (feedbackEl) {
                if (data.available) {
                    feedbackEl.innerHTML = `<span style="color: #4ade80;">✅ Kode TAG <b>#${rawVal}</b> tersedia & unik di seluruh sistem!</span>`;
                    if (submitBtn) submitBtn.disabled = false;
                    inputEl.style.borderColor = 'rgba(74, 222, 128, 0.6)';
                } else {
                    feedbackEl.innerHTML = `<span style="color: #f87171;">❌ ${data.message || 'Kode TAG sudah dipakai pengguna lain!'}</span>`;
                    if (submitBtn) submitBtn.disabled = true;
                    inputEl.style.borderColor = 'rgba(248, 113, 113, 0.7)';
                }
            }
        } catch (e) {}
    }, 250);
}

function openDonationModal() {
    const m = document.getElementById('modalDonation');
    if (m) m.style.display = 'flex';
}
function closeDonationModal() {
    const m = document.getElementById('modalDonation');
    if (m) m.style.display = 'none';
}

function toggleProjectCard(id) {
    const form = document.getElementById('projectForm_' + id);
    const textEl = document.getElementById('textToggleProject_' + id);
    const iconEl = document.getElementById('iconToggleProject_' + id);
    const btn = document.getElementById('btnToggleProject_' + id);
    if (!form) return;

    const isClosed = (form.style.display === 'none' || form.style.display === '');
    if (isClosed) {
        form.style.display = 'block';
        if (textEl) textEl.textContent = 'Minimalkan';
        if (iconEl) iconEl.style.transform = 'rotate(180deg)';
        if (btn) btn.setAttribute('title', 'Minimalkan Card Proyek');
    } else {
        form.style.display = 'none';
        if (textEl) textEl.textContent = 'Buka';
        if (iconEl) iconEl.style.transform = 'rotate(0deg)';
        if (btn) btn.setAttribute('title', 'Buka Pengaturan Proyek');
    }
}
</script>


<!-- ==================================================================== -->
<!-- MODAL ABOUT TELEGRAM VS CODE (Dual Language ID & EN)                 -->
<!-- ==================================================================== -->
<div id="modalAbout" class="modal-overlay" style="display: none;" onclick="if(event.target === this) closeAboutModal()">
    <div class="modal-content" style="max-width: 620px; padding: 28px 24px; text-align: left;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.35); display: inline-flex; align-items: center; justify-content: center;">
                    <?= renderSvg('bell', 20) ?>
                </div>
                <div>
                    <h3 style="font-size: 17px; font-weight: 600; color: #fff; margin: 0;">Telegram VS Code</h3>
                    <div style="font-size: 11.5px; color: var(--text-muted);">Biru Indonesia Creative (Biru Software)</div>
                </div>
            </div>
            <button type="button" class="btn btn-icon btn-ghost" onclick="closeAboutModal()" title="<?= __t('Tutup', 'Close') ?>">
                <?= renderSvg('close', 16) ?>
            </button>
        </div>

        <div style="font-size: 13px; color: var(--text-secondary); line-height: 1.65; max-height: 70vh; overflow-y: auto; padding-right: 6px;">
            <!-- Dedikasi Tim Magang Mancanegara -->
            <div style="background: rgba(56, 189, 248, 0.08); border: 1px solid rgba(56, 189, 248, 0.25); border-radius: var(--radius-md); padding: 14px 16px; margin-bottom: 16px;">
                <div style="font-weight: 600; color: #38bdf8; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                    <span>🌟</span>
                    <span><?= __t('Karya Dedikasi Tim Magang Mancanegara', 'Dedicated Creation by International Intern Team') ?></span>
                </div>
                <p style="margin: 0 0 8px 0; color: #e0f2fe;">
                    <?= __t(
                        'Alat ini merupakan hasil dedikasi nyata dan kerja keras anak-anak magang di <b>Biru Indonesia Creative (Biru Software)</b>.',
                        'This tool is the dedicated creation and hard work of intern talents at <b>Biru Indonesia Creative (Biru Software)</b>.'
                    ) ?>
                </p>
                <p style="margin: 0; color: #bae6fd;">
                    <?= __t(
                        'Tim magang di Biru Indonesia Creative berasal dari berbagai negara (mancanegara) yang bersatu dengan satu harapan luhur: <b>memudahkan masyarakat luas untuk bertahan hidup dengan teknologi dan memunculkan insan-insan kreatif baru</b> di era kecerdasan buatan (AI).',
                        'Our internship team comes from multiple nations across the globe, united by a noble mission: <b>to empower people worldwide to survive and thrive through technology and foster creative talents</b> in the era of Artificial Intelligence.'
                    ) ?>
                </p>
            </div>

            <!-- Ucapan Terima Kasih -->
            <div style="margin-bottom: 16px;">
                <h4 style="font-size: 13.5px; font-weight: 600; color: #fff; margin-bottom: 6px;">
                    🙏 <?= __t('Terima Kasih Atas Dukungan Anda', 'Thank You for Your Trust & Support') ?>
                </h4>
                <p style="margin: 0;">
                    <?= __t(
                        'Terima kasih telah mempercayakan dan memanfaatkan Telegram VS Code sebagai sahabat berteknologi dan AI coding Anda. Kami merancang alat ini agar developer tidak perlu terus menerus menunggu di depan layar komputer &mdash; cukup delegasikan instruksi coding dari Telegram kapan saja dan di mana saja.',
                        'Thank you for utilizing Telegram VS Code as your essential companion in AI coding and modern development. We engineered this tool so developers don’t have to stay glued to computer monitors &mdash; easily delegate coding tasks from Telegram anytime, anywhere.'
                    ) ?>
                </p>
            </div>

            <!-- Like, Ulasan & Donasi -->
            <div style="background: rgba(244, 63, 94, 0.08); border: 1px solid rgba(244, 63, 94, 0.3); border-radius: var(--radius-md); padding: 14px 16px; margin-bottom: 16px;">
                <div style="font-weight: 600; color: #fda4af; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                    <span>❤️</span>
                    <span><?= __t('Dukung Kami: Ulasan Marketplace & Donasi', 'Support Us: Marketplace Reviews & Donations') ?></span>
                </div>
                <p style="margin: 0 0 10px 0; color: #ffe4e6;">
                    <?= __t(
                        'Demi misi mulia <b>"bertahan hidup dengan teknologi dan #berkembangBaik"</b>, dukungan Like, ulasan bintang 5 di VS Code Marketplace, dan partisipasi donasi Anda sangat berarti untuk membiayai server dan pengembangan fitur selanjutnya.',
                        'In line with our mission <b>"to thrive and survive with technology and #growWell"</b>, your 5-star reviews on the VS Code Marketplace and generous donations sustain our servers and ongoing development.'
                    ) ?>
                </p>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="https://marketplace.visualstudio.com/items?itemName=BiruIndonesiaCreative.tele-vs-code&ssr=false#review-details" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm" style="display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.06); color: #fff; border-color: rgba(255,255,255,0.25); border-radius: var(--radius-sm); font-size: 12px; height: 32px; padding: 0 14px;">
                        <span>⭐</span>
                        <span><?= __t('Ulas di Marketplace', 'Review on Marketplace') ?></span>
                    </a>
                    <a href="https://ayo.ke/donasi" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 6px; background: #e11d48; border-color: #be123c; color: #fff; border-radius: var(--radius-sm); font-size: 12px; height: 32px; padding: 0 14px; box-shadow: 0 2px 10px rgba(225,29,72,0.4);">
                        <span>❤️</span>
                        <span><?= __t('Ayo Donasi', 'Donate Now') ?></span>
                    </a>
                    <a href="https://ayo.ke/feedback-tele-vs-code" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 6px; border-radius: var(--radius-sm); font-size: 12px; height: 32px; padding: 0 14px;">
                        <span>💬</span>
                        <span><?= __t('Saran & Laporan Bug', 'Send Feedback') ?></span>
                    </a>
                </div>
            </div>

            <!-- Informasi Kontak Perusahaan -->
            <div style="background: rgba(255, 255, 255, 0.04); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 14px 16px; margin-bottom: 16px;">
                <h4 style="font-size: 13px; font-weight: 600; color: #fff; margin-bottom: 8px;">
                    🏢 <?= __t('Informasi Kontak & Pengembang', 'Contact & Developer Information') ?>
                </h4>
                <div style="font-size: 12px; display: grid; grid-template-columns: 1fr; gap: 6px;">
                    <div><b>Perusahaan:</b> Biru Indonesia Creative (Biru Software)</div>
                    <div><b>Website:</b> <a href="https://www.biru.co.id" target="_blank" rel="noopener noreferrer" style="color: #38bdf8;">www.biru.co.id</a></div>
                    <div><b>Email Resmi:</b> <a href="mailto:hai@biru.co.id" style="color: #38bdf8;">hai@biru.co.id</a></div>
                    <div><b>Hotline / Telepon:</b> <code>+62 822 575 77772</code> &nbsp;/&nbsp; <code>+1 (772) 779 7772</code></div>
                    <div><b>Saran & Feedback:</b> <a href="https://ayo.ke/feedback-tele-vs-code" target="_blank" rel="noopener noreferrer" style="color: #38bdf8;">https://ayo.ke/feedback-tele-vs-code</a></div>
                </div>

                <div style="margin-top: 12px; pt-2; border-top: 1px solid var(--border-subtle); padding-top: 10px;">
                    <div style="font-size: 11px; color: var(--text-muted); margin-bottom: 6px;">Sosial Media Resmi:</div>
                    <div class="social-links">
                        <a href="https://www.biru.co.id" target="_blank" rel="noopener noreferrer" class="social-icon-btn" title="Website"><span style="font-size:11px;">🌐</span></a>
                        <a href="https://instagram.com/birusoftware" target="_blank" rel="noopener noreferrer" class="social-icon-btn" title="Instagram"><span style="font-size:11px;">📷</span></a>
                        <a href="https://youtube.com/@birusoftware" target="_blank" rel="noopener noreferrer" class="social-icon-btn" title="YouTube"><span style="font-size:11px;">▶️</span></a>
                        <a href="https://wa.me/6282257577772" target="_blank" rel="noopener noreferrer" class="social-icon-btn" title="WhatsApp"><span style="font-size:11px;">💬</span></a>
                    </div>
                </div>
            </div>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border-subtle); flex-wrap: wrap; gap: 10px;">
            <button type="button" class="btn btn-link btn-sm" onclick="closeAboutModal(); openPrivacyModal();" style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: #38bdf8; text-decoration: none; padding: 0;">
                <?= renderSvg('shield', 13) ?>
                <span><?= __t('Lihat Regulasi & Privasi Data &rarr;', 'View Regulations & Data Privacy &rarr;') ?></span>
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeAboutModal()" style="border-radius: var(--radius-sm); min-width: 80px;">
                <?= __t('Tutup', 'Close') ?>
            </button>
        </div>
    </div>
</div>

<!-- ==================================================================== -->
<!-- MODAL REGULASI, PRIVASI & KERAHASIAAN DATA (Dual Language ID & EN)    -->
<!-- ==================================================================== -->
<!-- Floating Feedback Button -->
<a href="https://ayo.ke/feedback-tele-vs-code" target="_blank" rel="noopener noreferrer" class="floating-feedback-btn" id="floatingFeedbackBtn" title="Kirim Saran / Laporkan Bug">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
    <span><?= __t('Feedback', 'Feedback') ?></span>
</a>

<div id="modalPrivacy" class="modal-overlay" style="display: none;" onclick="if(event.target === this) closePrivacyModal()">
    <div class="modal-content" style="max-width: 620px; padding: 28px 24px; text-align: left;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.35); display: inline-flex; align-items: center; justify-content: center; color: #34d399;">
                    <?= renderSvg('shield', 20) ?>
                </div>
                <div>
                    <h3 style="font-size: 16.5px; font-weight: 600; color: #fff; margin: 0;">
                        <?= __t('Regulasi, Privasi & Kerahasiaan Data', 'Regulations, Privacy & Data Confidentiality') ?>
                    </h3>
                    <div style="font-size: 11.5px; color: var(--text-muted);">Telegram VS Code &middot; Biru Indonesia Creative</div>
                </div>
            </div>
            <button type="button" class="btn btn-icon btn-ghost" onclick="closePrivacyModal()" title="<?= __t('Tutup', 'Close') ?>">
                <?= renderSvg('close', 16) ?>
            </button>
        </div>

        <div style="font-size: 12.5px; color: var(--text-secondary); line-height: 1.65; max-height: 68vh; overflow-y: auto; padding-right: 6px;">
            <!-- Inti Layanan: Gratis untuk Bertahan Hidup dengan Teknologi -->
            <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: var(--radius-md); padding: 12px 16px; margin-bottom: 14px;">
                <b style="color: #34d399; font-size: 13px;">
                    <?= __t('1. 100% Gratis untuk Kemudahan Bertahan Hidup dengan Teknologi', '1. 100% Free to Empower Survival and Growth with Technology') ?>
                </b>
                <p style="margin: 6px 0 0 0; color: #d1fae5;">
                    <?= __t(
                        'Layanan <b>Telegram VS Code</b> disediakan <b>100% gratis</b> tanpa biaya lisensi tersembunyi. Tujuannya adalah mempermudah para pelajar, mahasiswa, developer independen, dan masyarakat luas untuk bertahan hidup, berinovasi, dan berkarya produktif dengan teknologi kecerdasan buatan (AI).',
                        'The <b>Telegram VS Code</b> service is provided <b>100% free of charge</b> with no hidden licensing fees. Our sole purpose is to empower students, independent developers, and creators worldwide to thrive, innovate, and achieve productivity through AI technology.'
                    ) ?>
                </p>
            </div>

            <!-- Kerahasiaan & Keamanan Data -->
            <div style="margin-bottom: 14px;">
                <b style="color: #fff; font-size: 13px;">
                    <?= __t('2. Kerahasiaan & Keamanan Data Anda', '2. Data Confidentiality & Security') ?>
                </b>
                <ul style="margin: 6px 0 0 18px; padding: 0;">
                    <li><b>Kunci API & Bot Token:</b> Disimpan secara privat dan hanya digunakan untuk mengautentikasi pengiriman pesan Telegram ke bot proyek Anda.</li>
                    <li><b>Kode Sumber Workspace:</b> Ekstensi bekerja secara lokal di komputer/laptop Anda. Kode sumber proyek Anda <b>TIDAK PERNAH</b> dikirimkan atau disimpan ke server publik kami. Hanya teks instruksi tugas dan hasil ringkasan laporan yang disinkronkan melalui antrean.</li>
                    <li><b>Tidak Dijual ke Pihak Ketiga:</b> Kami menjamin secara mutlak bahwa data akun, kontak, maupun antrean tugas Anda tidak akan pernah dijual, disewakan, atau dibagikan kepada pihak ketiga.</li>
                </ul>
            </div>

            <!-- Regulasi & Tanggung Jawab -->
            <div style="margin-bottom: 14px;">
                <b style="color: #fff; font-size: 13px;">
                    <?= __t('3. Regulasi & Tanggung Jawab Pengguna', '3. User Responsibilities & Fair Use') ?>
                </b>
                <p style="margin: 6px 0 0 0;">
                    <?= __t(
                        'Pengguna bertanggung jawab penuh atas segala instruksi perintah tugas yang dikirimkan ke agen AI lokal di komputernya. Dilarang menyalahgunakan alat ini untuk aktivitas melanggar hukum, peretasan tidak sah, atau tindakan yang merugikan sistem pihak lain.',
                        'Users bear full responsibility for task instructions dispatched to their local AI agents. Misusing this tool for unlawful acts, unauthorized intrusion, or harmful activities is strictly prohibited.'
                    ) ?>
                </p>
            </div>

            <!-- Kontak Bantuan -->
            <div style="background: rgba(255, 255, 255, 0.04); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 10px 14px;">
                <div style="font-weight: 600; color: #fff; margin-bottom: 3px;">Kontak Regulasi & Privasi:</div>
                <div>Biru Indonesia Creative &middot; <a href="mailto:hai@biru.co.id" style="color: #38bdf8;">hai@biru.co.id</a> &middot; Hotline: +62 822 575 77772</div>
            </div>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border-subtle); flex-wrap: wrap; gap: 10px;">
            <button type="button" class="btn btn-link btn-sm" onclick="closePrivacyModal(); openAboutModal();" style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: #38bdf8; text-decoration: none; padding: 0;">
                <span>&larr;</span>
                <span><?= __t('Profil Telegram VS Code', 'Profile of Telegram VS Code') ?></span>
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="closePrivacyModal()" style="border-radius: var(--radius-sm); min-width: 80px;">
                <?= __t('Tutup', 'Close') ?>
            </button>
        </div>
    </div>
</div>

<script>

// Switcher tab modal auth (Login, Register, Forgot, OTP, Reset)
function switchAuthTab(tab) {
    const boxLogin = document.getElementById('boxLogin');
    const boxRegister = document.getElementById('boxRegister');
    const boxForgot = document.getElementById('boxForgot');
    const boxOtp = document.getElementById('boxOtp');
    const boxResetPass = document.getElementById('boxResetPass');
    const btnLogin = document.getElementById('btnAuthLogin');
    const btnReg = document.getElementById('btnAuthRegister');
    const seg = document.getElementById('modalAuthSegmented');
    const title = document.getElementById('authModalTitle');
    const sub = document.getElementById('authModalSubtitle');

    if (boxLogin) boxLogin.style.display = 'none';
    if (boxRegister) boxRegister.style.display = 'none';
    if (boxForgot) boxForgot.style.display = 'none';
    if (boxOtp) boxOtp.style.display = 'none';
    if (boxResetPass) boxResetPass.style.display = 'none';
    if (btnLogin) btnLogin.classList.remove('active');
    if (btnReg) btnReg.classList.remove('active');
    if (seg) seg.style.display = 'flex';

    if (tab === 'register') {
        if (boxRegister) boxRegister.style.display = 'block';
        if (btnReg) btnReg.classList.add('active');
        if (title) title.textContent = 'Daftar Akun Baru';
        if (sub) sub.textContent = 'Verifikasi kode email & amankan akun kamu.';
    } else if (tab === 'forgot') {
        if (boxForgot) boxForgot.style.display = 'block';
        if (seg) seg.style.display = 'none';
        if (title) title.textContent = 'Lupa Password';
        if (sub) sub.textContent = 'Masukkan email untuk pemulihan akun.';
    } else if (tab === 'otp') {
        if (boxOtp) boxOtp.style.display = 'block';
        if (seg) seg.style.display = 'none';
        if (title) title.textContent = 'Verifikasi Kode Email';
        if (sub) sub.textContent = 'Masukkan 6 digit kode yang dikirim ke email kamu.';
    } else if (tab === 'reset') {
        if (boxResetPass) boxResetPass.style.display = 'block';
        if (seg) seg.style.display = 'none';
        if (title) title.textContent = 'Buat Password Baru';
        if (sub) sub.textContent = 'Masukkan password baru untuk akun kamu.';
    } else {
        if (boxLogin) boxLogin.style.display = 'block';
        if (btnLogin) btnLogin.classList.add('active');
        if (title) title.textContent = 'Masuk ke ruang kerja';
        if (sub) sub.textContent = 'Satu akun untuk semua proyek dan bot kamu.';
    }
}

// Cloudflare Turnstile Callbacks (Aktifkan tombol jika lolos Turnstile)
window.onTurnstileSuccessLogin = function(token) {
    const btn = document.getElementById('btnSubmitLogin');
    if (btn) {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
    }
};
window.onTurnstileExpiredLogin = function() {
    const btn = document.getElementById('btnSubmitLogin');
    if (btn) {
        btn.disabled = true;
        btn.style.opacity = '0.6';
        btn.style.cursor = 'not-allowed';
    }
};

window.onTurnstileSuccessRegister = function(token) {
    const btn = document.getElementById('btnSubmitRegister');
    if (btn) {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
    }
};
window.onTurnstileExpiredRegister = function() {
    const btn = document.getElementById('btnSubmitRegister');
    if (btn) {
        btn.disabled = true;
        btn.style.opacity = '0.6';
        btn.style.cursor = 'not-allowed';
    }
};

// Modal Profil & Password

// ==============================================================================
// Interactive Wizard: Setup Bot <nama_BOT> (Step-by-Step with Verification & Confetti)
// ==============================================================================
let wizProjectId = null;
let wizProjectName = '';
let wizBotToken = '';
let wizBotUsername = '';
let wizCurrentStep = 1;
let wizVerifyPollInterval = null;
let wizVerifyStartTime = 0;

// Validasi Bot Aktif & Buka Wizard Setup Bot
async function openSetupBotWizard(projectId, projectName, botToken, botUsername) {
    wizProjectId = projectId;
    wizProjectName = projectName;
    wizBotToken = botToken;
    wizBotUsername = botUsername || '';

    if (!botToken || botToken.trim() === '') {
        alert('❌ Telegram Bot Token belum diisi pada proyek ' + (projectName || '') + '. Harap masukkan dan simpan Bot Token terlebih dahulu di form proyek.');
        const form = document.getElementById('projectForm_' + projectId);
        if (form) form.style.display = 'block';
        return;
    }

    // Tampilkan indikator loading tombol
    const btnLabel = document.getElementById('btnSetupBotLabel_' + projectId);
    const originalLabel = btnLabel ? btnLabel.innerHTML : '';
    if (btnLabel) btnLabel.innerHTML = '⏳ Memeriksa Bot...';

    try {
        const res = await fetch('?action=check_bot_active&id=' + projectId, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        });
        const rawText = await res.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (jsonErr) {
            const cleanSnippet = rawText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 140);
            data = { ok: false, description: cleanSnippet || 'Terjadi kesalahan pada server saat memeriksa status bot.' };
        }

        if (btnLabel) btnLabel.innerHTML = originalLabel;

        if (!data.ok) {
            alert('❌ Bot Tidak Aktif: ' + (data.description || data.error || 'Bot Token tidak valid atau dinonaktifkan oleh Telegram.') + '\n\nWizard Setup Bot hanya dapat dijalankan setelah bot Telegram aktif.');
            return;
        }

        // Simpan username bot yang terverifikasi
        wizBotUsername = data.username || ('@' + (data.raw_username || 'Bot'));

        // Update teks judul dan label
        const titleEl = document.getElementById('wizBotUsernameText');
        if (titleEl) titleEl.textContent = wizBotUsername;
        const prjEl = document.getElementById('wizProjectNameText');
        if (prjEl) prjEl.textContent = projectName;
        const linkBotEl = document.getElementById('wizLinkBotUsername');
        if (linkBotEl) linkBotEl.textContent = 't.me/' + data.raw_username;
        const openLink = document.getElementById('btnWizOpenBotLink');
        if (openLink) openLink.href = 'https://t.me/' + data.raw_username;
        const openLinkDirect = document.getElementById('btnWizDirectTg');
        if (openLinkDirect) openLinkDirect.href = 'https://t.me/' + data.raw_username;
        const verifyBotName = document.getElementById('wizVerifyBotName');
        if (verifyBotName) verifyBotName.textContent = wizBotUsername;

        // Reset step ke Step 1
        goToWizStep(1);

        // Buka modal
        const modal = document.getElementById('modalSetupBotWizard');
        if (modal) modal.style.display = 'flex';

    } catch (err) {
        if (btnLabel) btnLabel.innerHTML = originalLabel;
        alert('❌ Gagal memeriksa status bot: ' + err.message);
    }
}

function closeSetupBotWizard(shouldReload) {
    if (wizVerifyPollInterval) {
        clearInterval(wizVerifyPollInterval);
        wizVerifyPollInterval = null;
    }
    const modal = document.getElementById('modalSetupBotWizard');
    if (modal) modal.style.display = 'none';
    if (shouldReload) {
        window.location.reload();
    }
}

function goToWizStep(stepNum) {
    wizCurrentStep = stepNum;

    // Update Step Pills
    for (let i = 1; i <= 3; i++) {
        const pill = document.getElementById('wizStepPill' + i);
        const num = document.getElementById('wizStepNum' + i);
        const content = document.getElementById('wizStepContent' + i);

        if (i === stepNum) {
            if (pill) {
                pill.style.background = 'rgba(255,90,31,0.08)';
                pill.style.borderColor = 'var(--signal, #ff5a1f)';
                pill.style.color = 'var(--text-primary, #ece4d6)';
            }
            if (num) {
                num.style.background = 'var(--signal, #ff5a1f)';
                num.style.color = '#fff';
            }
            if (content) content.style.display = 'block';
        } else if (i < stepNum) {
            if (pill) {
                pill.style.background = 'rgba(236,228,214,0.06)';
                pill.style.borderColor = 'var(--border-strong, rgba(236,228,214,0.28))';
                pill.style.color = 'var(--text-primary, #ece4d6)';
            }
            if (num) {
                num.style.background = 'var(--text-primary, #ece4d6)';
                num.style.color = '#0d0c0a';
            }
            if (content) content.style.display = 'none';
        } else {
            if (pill) {
                pill.style.background = 'var(--bg-surface-elevated, #1a1814)';
                pill.style.borderColor = 'var(--border-subtle, rgba(236,228,214,0.08))';
                pill.style.color = 'var(--text-muted, #777064)';
            }
            if (num) {
                num.style.background = 'rgba(236,228,214,0.1)';
                num.style.color = 'var(--text-secondary, #a8a091)';
            }
            if (content) content.style.display = 'none';
        }
    }

    // Jika masuk Step 3: Mulai Polling Verifikasi Telegram
    if (stepNum === 3) {
        startVerificationPolling();
    } else {
        if (wizVerifyPollInterval) {
            clearInterval(wizVerifyPollInterval);
            wizVerifyPollInterval = null;
        }
    }
}

// Langkah 2: Eksekusi Pasang Perintah & Webhook via AJAX
async function executeWizInstallCommands() {
    const btn = document.getElementById('btnWizInstallCommands');
    const fb = document.getElementById('wizStep2Feedback');
    if (!wizProjectId) return;

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '⏳ Menghubungkan Webhook & Mendaftarkan Perintah...';
    }

    try {
        const res = await fetch('?action=connect_bot_commands&id=' + wizProjectId + '&ajax=1');
        const rawText = await res.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (jsonErr) {
            const cleanSnippet = rawText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 120);
            data = { ok: false, description: cleanSnippet || 'Terjadi kesalahan internal pada server saat menghubungkan webhook.' };
        }

        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '⚡ Pasang Perintah & Webhook Sekarang';
        }

        if (data.ok) {
            if (fb) {
                fb.style.display = 'block';
                fb.style.background = 'rgba(156,196,138,0.14)';
                fb.style.border = '1px solid var(--accent-success, #9cc48a)';
                fb.style.color = 'var(--accent-success, #9cc48a)';
                fb.innerHTML = '✅ <b>Berhasil!</b> ' + (data.message || 'Perintah & Webhook aktif.') + ' Mengalihkan ke langkah verifikasi...';
            }
            setTimeout(() => {
                goToWizStep(3);
            }, 800);
        } else {
            if (fb) {
                fb.style.display = 'block';
                fb.style.background = 'rgba(255,90,31,0.14)';
                fb.style.border = '1px solid var(--signal, #ff5a1f)';
                fb.style.color = '#ff8555';
                fb.innerHTML = '❌ <b>Gagal:</b> ' + (data.description || 'Tidak dapat memasang webhook.');
            }
        }
    } catch (err) {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '⚡ Pasang Perintah & Webhook Sekarang';
        }
        if (fb) {
            fb.style.display = 'block';
            fb.style.background = 'rgba(255,90,31,0.14)';
            fb.style.border = '1px solid var(--signal, #ff5a1f)';
            fb.style.color = '#ff8555';
            fb.innerHTML = '❌ <b>Kesalahan:</b> ' + err.message;
        }
    }
}

// Langkah 3: Polling Status Verifikasi /verifikasi dari Telegram
function startVerificationPolling() {
    if (wizVerifyPollInterval) clearInterval(wizVerifyPollInterval);
    wizVerifyStartTime = Math.floor(Date.now() / 1000);

    const waitingBox = document.getElementById('wizStep3Waiting');
    const successBox = document.getElementById('wizStep3Success');
    if (waitingBox) waitingBox.style.display = 'block';
    if (successBox) successBox.style.display = 'none';

    wizVerifyPollInterval = setInterval(async () => {
        if (!wizProjectId) return;
        try {
            const res = await fetch('?action=check_bot_verification&id=' + wizProjectId + '&since=' + wizVerifyStartTime, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const rawText = await res.text();
            let data = {};
            try { data = JSON.parse(rawText); } catch(e) {}

            if (data.ok && data.verified) {
                // Berhasil verifikasi!
                clearInterval(wizVerifyPollInterval);
                wizVerifyPollInterval = null;

                if (waitingBox) waitingBox.style.display = 'none';
                if (successBox) successBox.style.display = 'block';

                const succBot = document.getElementById('wizSuccessBotUsername');
                if (succBot) succBot.textContent = data.bot_username || wizBotUsername;
                const succTime = document.getElementById('wizSuccessVerifiedAt');
                if (succTime) succTime.textContent = data.verified_at || new Date().toLocaleString();

                // Picu Efek Konfeti Halus!
                triggerSmoothConfetti();
            }
        } catch (e) {
            // Abaikan error polling sementara
        }
    }, 2000);
}

// Generator Efek Konfeti Canvas Ringan & Halus (Zero Dependency)
function triggerSmoothConfetti() {
    const canvas = document.getElementById('confettiCanvas');
    if (!canvas) return;
    canvas.style.display = 'block';
    canvas.width = canvas.offsetWidth;
    canvas.height = canvas.offsetHeight;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    const pieces = [];
    const colors = ['#ff5a1f', '#ece4d6', '#9cc48a', '#9dbbd0', '#e0ac4a', '#f43f5e', '#a855f7'];

    for (let i = 0; i < 80; i++) {
        pieces.push({
            x: Math.random() * canvas.width,
            y: Math.random() * -canvas.height * 0.5,
            size: Math.random() * 8 + 4,
            color: colors[Math.floor(Math.random() * colors.length)],
            speedY: Math.random() * 3 + 2,
            speedX: Math.random() * 4 - 2,
            rotation: Math.random() * 360,
            rotationSpeed: Math.random() * 6 - 3,
            opacity: 1
        });
    }

    let frame = 0;
    function renderConfetti() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        let activeCount = 0;

        pieces.forEach(p => {
            p.y += p.speedY;
            p.x += p.speedX;
            p.rotation += p.rotationSpeed;
            if (p.y > canvas.height * 0.7) {
                p.opacity -= 0.02;
            }

            if (p.opacity > 0 && p.y < canvas.height) {
                activeCount++;
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate((p.rotation * Math.PI) / 180);
                ctx.globalAlpha = Math.max(0, p.opacity);
                ctx.fillStyle = p.color;
                ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6);
                ctx.restore();
            }
        });

        frame++;
        if (activeCount > 0 && frame < 180) {
            requestAnimationFrame(renderConfetti);
        } else {
            canvas.style.display = 'none';
        }
    }
    requestAnimationFrame(renderConfetti);
}

function openProfileModal() {
    const m = document.getElementById('modalProfile');
    if (m) m.style.display = 'flex';
}
function closeProfileModal() {
    const m = document.getElementById('modalProfile');
    if (m) m.style.display = 'none';
}

function openAuthModal(tab) {
    const m = document.getElementById('modalAuth');
    if (m) {
        m.style.display = 'flex';
        if (typeof switchAuthTab === 'function') {
            switchAuthTab(tab || 'login');
        }
    }
}
function closeAuthModal() {
    const m = document.getElementById('modalAuth');
    if (m) m.style.display = 'none';
}

function openAboutModal() {
    const m = document.getElementById('modalAbout');
    if (m) m.style.display = 'flex';
}
function closeAboutModal() {
    const m = document.getElementById('modalAbout');
    if (m) m.style.display = 'none';
}
function openPrivacyModal() {
    const m = document.getElementById('modalPrivacy');
    if (m) m.style.display = 'flex';
}
function closePrivacyModal() {
    const m = document.getElementById('modalPrivacy');
    if (m) m.style.display = 'none';
}

// ==============================================================================
// Task Accordion & Image Lightbox Helpers
// ==============================================================================
function toggleTaskAccordion(taskId, event) {
    if (event) {
        const target = event.target;
        if (target.closest('.task-card-actions') || target.closest('a') || (target.closest('button') && !target.closest('.task-accordion-toggle'))) {
            return;
        }
    }
    const card = document.getElementById('task-' + taskId);
    if (!card) return;
    const body = document.getElementById('body-task-' + taskId);
    if (card.classList.contains('collapsed')) {
        card.classList.remove('collapsed');
        if (body) body.style.display = 'flex';
    } else {
        card.classList.add('collapsed');
        if (body) body.style.display = 'none';
    }
}

function expandAllTasks() {
    document.querySelectorAll('.task-card').forEach(card => {
        card.classList.remove('collapsed');
        const body = card.querySelector('.task-card-body');
        if (body) body.style.display = 'flex';
    });
}

function collapseAllTasks() {
    document.querySelectorAll('.task-card').forEach(card => {
        card.classList.add('collapsed');
        const body = card.querySelector('.task-card-body');
        if (body) body.style.display = 'none';
    });
}

function openImageModal(e, url, title) {
    if (e) e.preventDefault();
    let modal = document.getElementById('imageLightboxModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'imageLightboxModal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.85);backdrop-filter:blur(6px);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;cursor:pointer;';
        modal.onclick = function() { modal.style.display = 'none'; };
        modal.innerHTML = `
            <div style="position:relative;max-width:92vw;max-height:92vh;display:flex;flex-direction:column;align-items:center;" onclick="event.stopPropagation()">
                <div style="display:flex;justify-content:space-between;width:100%;margin-bottom:8px;color:#fff;font-size:13px;">
                    <span id="imageLightboxTitle" style="font-weight:500;"></span>
                    <button type="button" style="background:none;border:none;color:#fff;cursor:pointer;font-size:18px;line-height:1;padding:0 4px;" onclick="document.getElementById('imageLightboxModal').style.display='none'">✕</button>
                </div>
                <img id="imageLightboxImg" style="max-width:92vw;max-height:82vh;object-fit:contain;border-radius:8px;box-shadow:0 16px 48px rgba(0,0,0,0.7);border:1px solid rgba(255,255,255,0.15);" src="" alt="">
            </div>
        `;
        document.body.appendChild(modal);
    }
    document.getElementById('imageLightboxImg').src = url;
    document.getElementById('imageLightboxTitle').textContent = title || 'Lampiran Foto';
    modal.style.display = 'flex';
}


// ==============================================================================
// Live Auto-Update Engine dengan Sonner Notifications (Hanya saat sudah login)
// ==============================================================================
<?php if ($currentUser): ?>
// Penerapan Data Partial Refresh secara Instan ke DOM tanpa full DOMParser
window.applyQueueRefreshData = function(data) {
    try {
        const curWrapper = document.getElementById('taskCardsWrapper');
        if (!curWrapper || !data || !data.cards_html) return;

        // Catat akordion mana saja yang sedang dibuka pengguna
        const expandedIds = new Set();
        document.querySelectorAll('#taskCardsWrapper .task-card:not(.collapsed)').forEach(el => {
            if (el.id) expandedIds.add(el.id);
        });

        // 1. Suntikkan HTML kartu langsung ke kontainer antrean
        curWrapper.innerHTML = data.cards_html;

        // 2. Pulihkan state akordion yang sedang dibuka
        expandedIds.forEach(id => {
            const card = document.getElementById(id);
            if (card) card.classList.remove('collapsed');
        });

        // 3. Update Banner Tugas Aktif dari IDE
        const curIdeBanner = document.getElementById('activeIdeBannerContainer');
        if (curIdeBanner && data.ide_banner_html !== undefined) {
            curIdeBanner.innerHTML = data.ide_banner_html;
        }

        // 4. Update Summary Jumlah Tugas
        const curCountSummary = document.getElementById('taskQueueCountSummary');
        if (curCountSummary && data.count_summary) {
            curCountSummary.innerHTML = data.count_summary;
        }

        // 5. Update 4 Kartu Statistik Angka Real-time
        if (data.stats) {
            const elTotal = document.getElementById('stat-val-total');
            const elPending = document.getElementById('stat-val-pending');
            const elProgress = document.getElementById('stat-val-progress');
            const elDone = document.getElementById('stat-val-done');
            if (elTotal && data.stats.TOTAL !== undefined) elTotal.textContent = data.stats.TOTAL;
            if (elPending && data.stats.PENDING !== undefined) elPending.textContent = data.stats.PENDING;
            if (elProgress && data.stats.IN_PROGRESS !== undefined) elProgress.textContent = data.stats.IN_PROGRESS;
            if (elDone && data.stats.DONE !== undefined) elDone.textContent = data.stats.DONE;
        }

        // 6. Efek highlight / flash pada task card pertama jika baru
        const firstCard = curWrapper.querySelector('.task-card');
        if (firstCard) {
            firstCard.style.transition = 'box-shadow 0.4s ease, border-color 0.4s ease';
            firstCard.style.boxShadow = '0 0 16px rgba(56, 189, 248, 0.4)';
            firstCard.style.borderColor = 'rgba(56, 189, 248, 0.6)';
            setTimeout(() => {
                firstCard.style.boxShadow = '';
                firstCard.style.borderColor = '';
            }, 1200);
        }

        // 7. Efek visual singkat pada indikator status
        const liveBadge = document.getElementById('liveSyncBadge');
        if (liveBadge) {
            liveBadge.style.transition = 'transform 0.2s ease';
            liveBadge.style.transform = 'scale(1.06)';
            setTimeout(() => { liveBadge.style.transform = 'scale(1)'; }, 300);
        }
    } catch (err) {
        console.log('applyQueueRefreshData err:', err);
    }
};

// Silent Refresh Queue via endpoint ringan ?action=render_task_cards
window.triggerAsyncQueueRefresh = async function(overrideProject) {
    try {
        const curWrapper = document.getElementById('taskCardsWrapper');
        if (!curWrapper) return;

        // Ambil filter yang sedang aktif dari DOM atau URL
        const prjSelect = document.querySelector('select[name="filter_project"]');
        const stSelect = document.querySelector('select[name="filter_status"]');
        const urlParams = new URLSearchParams(window.location.search);

        let filterPrj = (overrideProject !== undefined && overrideProject !== null) 
            ? String(overrideProject) 
            : (prjSelect ? prjSelect.value : (urlParams.get('filter_project') || ''));
        const filterSt = stSelect ? stSelect.value : (urlParams.get('filter_status') || '');
        const page = urlParams.get('page') || '1';
        const perPage = urlParams.get('per_page') || '10';

        const baseUrl = window.location.pathname.replace(/\/+$/, '') + '/';
        const fetchUrl = baseUrl + '?action=render_task_cards'
                       + (filterPrj ? '&filter_project=' + encodeURIComponent(filterPrj) : '')
                       + (filterSt ? '&filter_status=' + encodeURIComponent(filterSt) : '')
                       + '&page=' + encodeURIComponent(page)
                       + '&per_page=' + encodeURIComponent(perPage)
                       + '&_ts=' + Date.now();

        const res = await fetch(fetchUrl, {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        if (!res.ok) return;
        const data = await res.json();
        if (data && data.ok && data.cards_html) {
            window.applyQueueRefreshData(data);
            if (typeof window.setDashboardStateHash === 'function' && data.hash) {
                window.setDashboardStateHash(data.hash);
            }
        }
    } catch (err) {
        console.log('Silent queue refresh err:', err);
    }
};

// Fungsi Modal Detail & Hasil Tugas
window.openTaskDetail = function(task, tzAbbr) {
    if (!task) return;
    const modal = document.getElementById('modalTaskDetail');
    if (!modal) return;

    const elId = document.getElementById('mTaskId');
    if (elId) elId.textContent = task.id || '';
    const elPrj = document.getElementById('mTaskProject');
    if (elPrj) elPrj.textContent = task.project_name || 'Tanpa Proyek';
    const elSnd = document.getElementById('mTaskSender');
    if (elSnd) elSnd.textContent = task.sender || 'Dashboard';
    const elTime = document.getElementById('mTaskTime');
    if (elTime) elTime.textContent = (task.created_at || '') + ' ' + (tzAbbr || 'WIB');

    const badge = document.getElementById('mTaskStatusBadge');
    if (badge) {
        badge.className = 'badge ' + (task.status === 'DONE' ? 'badge-done' : (task.status === 'IN_PROGRESS' ? 'badge-progress' : (task.status === 'FAILED' ? 'badge-failed' : 'badge-pending')));
        badge.textContent = task.status || 'PENDING';
    }

    const agentEl = document.getElementById('mTaskAgent');
    if (agentEl) {
        agentEl.textContent = task.assigned_agent || 'Antigravity AI Agent';
    }

    const instEl = document.getElementById('mTaskInstruction');
    if (instEl) {
        const clean = (task.instruction || '').replace(/\[(?:Foto|Video|Dokumen|Audio|Suara)\s+Terlampir:[^\]]+\]\s*/gi, '').trim();
        instEl.textContent = clean || task.instruction || '';
    }

    const formId = document.getElementById('mFormTaskId');
    if (formId) formId.value = task.id || '';

    const cmdEl = document.getElementById('mTaskCommand');
    if (cmdEl) cmdEl.value = task.command || '';

    const resEl = document.getElementById('mTaskResult');
    if (resEl) resEl.value = task.result || task.notes || '';

    const stSelect = document.getElementById('mTaskStatusSelect');
    if (stSelect) stSelect.value = task.status || 'PENDING';

    const wrapAtt = document.getElementById('mTaskAttachmentsWrap');
    const listAtt = document.getElementById('mTaskAttachmentsList');
    if (wrapAtt && listAtt) {
        listAtt.innerHTML = '';
        const atts = Array.isArray(task.attachments) ? task.attachments : [];
        if (atts.length > 0) {
            wrapAtt.style.display = 'block';
            atts.forEach(a => {
                const u = a.url || '';
                if (!u) return;
                const link = document.createElement('a');
                link.href = u;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.className = 'badge badge-project';
                link.style.cssText = 'padding: 4px 8px; font-size: 11.5px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;';
                link.textContent = '📎 ' + (a.name || a.type || 'File');
                listAtt.appendChild(link);
            });
        } else {
            wrapAtt.style.display = 'none';
        }
    }

    modal.style.display = 'flex';
};

window.closeTaskDetail = function() {
    const modal = document.getElementById('modalTaskDetail');
    if (modal) modal.style.display = 'none';
};

window.copyResultText = function() {
    const resEl = document.getElementById('mTaskResult');
    if (resEl && resEl.value) {
        navigator.clipboard.writeText(resEl.value).then(() => {
            if (typeof showToast === 'function') showToast('Hasil tugas berhasil disalin ke clipboard!', 'success');
        });
    }
};

window.promptMarkDone = function(taskId) {
    const modal = document.getElementById('modalMarkDone');
    if (!modal) return;
    const label = document.getElementById('markDoneTaskLabel');
    if (label) label.textContent = taskId;
    const input = document.getElementById('markDoneTaskId');
    if (input) input.value = taskId;
    modal.style.display = 'flex';
};

window.closeMarkDoneModal = function() {
    const modal = document.getElementById('modalMarkDone');
    if (modal) modal.style.display = 'none';
};

(function() {
    let lastStateHash = '';
    let knownTaskStatus = {};
    let isInitialLoad = true;
    let isFetching = false;

    window.setDashboardStateHash = function(h) {
        if (h) lastStateHash = h;
    };

    async function pollDashboardUpdates() {
        if (isFetching) return;
        isFetching = true;
        try {
            const prjSelect = document.querySelector('select[name="filter_project"]');
            const urlParams = new URLSearchParams(window.location.search);
            const filterPrj = prjSelect ? prjSelect.value : (urlParams.get('filter_project') || '');
            const baseUrl = window.location.pathname.replace(/\/+$/, '') + '/';
            const url = baseUrl + '?action=check_task_updates&hash=' + encodeURIComponent(lastStateHash)
                      + (filterPrj ? '&filter_project=' + encodeURIComponent(filterPrj) : '')
                      + '&_ts=' + Date.now();
            const res = await fetch(url, {
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!res.ok) { isFetching = false; return; }
            const data = await res.json();

            if (data.ok) {
                // Update Statistik Angka di Dashboard Real-time
                if (data.stats) {
                    const elTotal = document.getElementById('stat-val-total');
                    const elPending = document.getElementById('stat-val-pending');
                    const elProgress = document.getElementById('stat-val-progress');
                    const elDone = document.getElementById('stat-val-done');
                    if (elTotal) elTotal.textContent = data.stats.TOTAL;
                    if (elPending) elPending.textContent = data.stats.PENDING;
                    if (elProgress) elProgress.textContent = data.stats.IN_PROGRESS;
                    if (elDone) elDone.textContent = data.stats.DONE;
                }

                if (isInitialLoad) {
                    lastStateHash = data.hash;
                    if (Array.isArray(data.tasks)) {
                        for (const t of data.tasks) {
                            knownTaskStatus[t.id] = t.status;
                        }
                    }
                    isInitialLoad = false;
                    isFetching = false;
                    return;
                }

                // Cek apakah ada perubahan status, task baru, atau task teratas berbeda dari DOM
                let hasChanges = data.changed;
                const topDomCard = document.querySelector('#taskCardsWrapper .task-card');
                const topDomId = topDomCard ? topDomCard.id.replace(/^task-/, '') : '';
                const serverFirstId = (Array.isArray(data.tasks) && data.tasks.length > 0) ? data.tasks[0].id : (data.first_id || '');
                if (serverFirstId && topDomId && serverFirstId !== topDomId) {
                    hasChanges = true;
                }

                if (hasChanges && Array.isArray(data.tasks)) {
                    for (const t of data.tasks) {
                        try {
                            const prevStatus = knownTaskStatus[t.id];
                            const cleanInst = (t.instruction || '').replace(/\[(?:Foto|Video|Dokumen|Audio|Suara)[^\]]+\]/gi, '').trim();
                            const snippet = cleanInst.length > 70 ? cleanInst.substring(0, 70) + '...' : cleanInst;

                            if (!prevStatus) {
                                // Tugas Baru Masuk (baik dari IDE, CLI push, maupun Telegram)
                                const isIde = t.sender && (t.sender.includes('IDE') || t.sender.includes('VS Code'));
                                const toastTitle = isIde ? '💻 Tugas Baru dari IDE Masuk!' : '📥 Tugas Baru di Antrean!';
                                const toastDesc = `${t.id}: ${snippet || 'Tugas coding baru diterima'}`;
                                if (typeof toast !== 'undefined') {
                                    if (typeof toast.info === 'function') {
                                        toast.info(toastTitle, { description: toastDesc, duration: 6000 });
                                    } else if (typeof toast === 'function') {
                                        toast(toastTitle, { description: toastDesc, duration: 6000 });
                                    }
                                } else if (typeof showToast === 'function') {
                                    showToast(`${toastTitle} ${toastDesc}`, 'info');
                                }
                            } else if (prevStatus !== t.status) {
                                // Status Berubah
                                if (t.status === 'DONE') {
                                    const resSnippet = (t.result || t.notes || 'Tugas selesai 100%').substring(0, 80);
                                    if (typeof toast !== 'undefined' && typeof toast.success === 'function') {
                                        toast.success(`✅ Tugas ${t.id} Selesai 100%!`, { description: resSnippet, duration: 6000 });
                                    } else if (typeof showToast === 'function') {
                                        showToast(`✅ Tugas ${t.id} Selesai 100%! - ${resSnippet}`, 'success');
                                    }
                                } else if (t.status === 'IN_PROGRESS') {
                                    if (typeof toast !== 'undefined' && typeof toast.info === 'function') {
                                        toast.info(`⚡ Memulai Pekerjaan: ${t.id}`, { description: 'AI Agent di VS Code sedang mengeksekusi tugas...', duration: 5000 });
                                    } else if (typeof showToast === 'function') {
                                        showToast(`⚡ Memulai Pekerjaan: ${t.id}`, 'info');
                                    }
                                } else if (t.status === 'FAILED') {
                                    if (typeof toast !== 'undefined' && typeof toast.error === 'function') {
                                        toast.error(`🚨 Kendala pada ${t.id}`, { description: t.notes || 'Pengerjaan tugas mengalami kendala', duration: 6000 });
                                    } else if (typeof showToast === 'function') {
                                        showToast(`🚨 Kendala pada ${t.id}: ${t.notes || 'Pengerjaan mengalami kendala'}`, 'error');
                                    }
                                }
                            }
                        } catch (tErr) {
                            console.warn('Toast display warning:', tErr);
                        }

                        knownTaskStatus[t.id] = t.status;
                    }

                    if (data.hash) {
                        lastStateHash = data.hash;
                    }

                    // Terapkan update kartu tugas secara instan
                    if (data.cards_html && typeof window.applyQueueRefreshData === 'function') {
                        window.applyQueueRefreshData(data);
                    } else if (typeof window.triggerAsyncQueueRefresh === 'function') {
                        await window.triggerAsyncQueueRefresh();
                    }
                }
            }
        } catch (err) {
        } finally {
            isFetching = false;
        }
    }

    // Jalankan auto-update setiap 2.5 detik
    setInterval(pollDashboardUpdates, 2500);
    setTimeout(pollDashboardUpdates, 600);
})();
<?php endif; ?>
</script>


<script>
// Floating Feedback Button: Di mobile sembunyikan saat pertama dibuka, munculkan dengan animasi smooth saat discroll ke bawah
(function initFloatingButtonMobileScroll() {
    const floatBtn = document.getElementById('floatingFeedbackBtn');
    if (!floatBtn) return;

    let ticking = false;
    function checkScroll() {
        if (window.innerWidth <= 768) {
            // Threshold scroll 50px untuk memicu animasi muncul yang halus
            if (window.scrollY > 50) {
                floatBtn.classList.add('is-visible');
            } else {
                floatBtn.classList.remove('is-visible');
            }
        } else {
            // Pada desktop selalu tampil normal
            floatBtn.classList.remove('is-visible');
        }
        ticking = false;
    }

    function onScroll() {
        if (!ticking) {
            window.requestAnimationFrame(checkScroll);
            ticking = true;
        }
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    checkScroll();
})();
</script>

<!-- ============================================================================== -->
<!-- ADVANCED TELE-VS-CODE ENHANCEMENT ENGINE                                      -->
<!-- Features: Paste Object (Image/Video), Dropzone Uploader, AJAX Form Submissions -->
<!-- ============================================================================== -->
<script>
window.TVSC_USER_KEY = <?= !empty($currentUser['api_key']) ? json_encode($currentUser['api_key']) : '""' ?>;
document.addEventListener('DOMContentLoaded', function() {
    // --------------------------------------------------------------------------
    // 1. AJAX FORM INTERCEPTOR (Anti-Resubmission pada Refresh / F5)
    // --------------------------------------------------------------------------
    document.querySelectorAll('form.ajax-form, form[data-ajax="true"]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalBtnHtml = submitBtn ? submitBtn.innerHTML : '';
            const statusSpan = document.getElementById('formSubmitStatus');

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner" style="display:inline-block;width:12px;height:12px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin 0.6s linear infinite;margin-right:6px;"></span> Menyimpan...';
            }
            if (statusSpan) {
                statusSpan.style.display = 'inline';
                statusSpan.textContent = 'Memproses permintaan via AJAX...';
            }

            const formData = new FormData(form);
            formData.set('ajax', '1');

            fetch(form.action || window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(res => {
                const contentType = res.headers.get('content-type') || '';
                if (contentType.includes('application/json')) {
                    return res.json();
                }
                return res.text().then(text => ({ ok: res.ok, message: text }));
            })
            .then(data => {
                if (data.ok) {
                    if (typeof showToast === 'function') {
                        showToast(data.message || 'Tugas berhasil diproses!', 'success');
                    } else {
                        alert(data.message || 'Berhasil!');
                    }

                    // Reset form spesifik tugas manual
                    let targetPrj = null;
                    if (form.id === 'formAddManualTask') {
                        const instEl = document.getElementById('taskInstruction');
                        if (instEl) instEl.value = '';
                        resetDropzoneAttachment();

                        targetPrj = formData.get('project_id');
                        const filterSelect = document.querySelector('select[name="filter_project"]');
                        if (filterSelect && targetPrj) {
                            if (filterSelect.value !== '0' && filterSelect.value !== String(targetPrj)) {
                                filterSelect.value = String(targetPrj);
                            }
                        }
                    }
                    // Refresh data antrean secara asinkron tanpa reload browser
                    triggerAsyncQueueRefresh(targetPrj);
                } else {
                    const errMsg = data.error || data.message || 'Gagal memproses permintaan';
                    if (typeof showToast === 'function') {
                        showToast(errMsg, 'error');
                    } else {
                        alert(errMsg);
                    }
                }
            })
            .catch(err => {
                console.error('AJAX Submit Error:', err);
                if (typeof showToast === 'function') {
                    showToast('Kesalahan jaringan / server: ' + err.message, 'error');
                }
            })
            .finally(() => {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnHtml;
                }
                if (statusSpan) {
                    statusSpan.style.display = 'none';
                }
            });
        });
    });

    // --------------------------------------------------------------------------
    // 2. CLIPBOARD PASTE HANDLER (Multi-File Image, Video & Dokumen di Textarea)
    // --------------------------------------------------------------------------
    const taskInstructionArea = document.getElementById('taskInstruction');
    if (taskInstructionArea) {
        taskInstructionArea.addEventListener('paste', function(e) {
            const clipboardData = e.clipboardData || window.clipboardData;
            if (!clipboardData) return;

            const pastedFiles = [];
            if (clipboardData.items && clipboardData.items.length > 0) {
                for (let i = 0; i < clipboardData.items.length; i++) {
                    const item = clipboardData.items[i];
                    if (item.kind === 'file') {
                        const f = item.getAsFile();
                        if (f) pastedFiles.push(f);
                    }
                }
            } else if (clipboardData.files && clipboardData.files.length > 0) {
                for (let i = 0; i < clipboardData.files.length; i++) {
                    pastedFiles.push(clipboardData.files[i]);
                }
            }

            if (pastedFiles.length > 0) {
                uploadMediaFiles(pastedFiles, 'clipboard');
            }
        });
    }

    // --------------------------------------------------------------------------
    // 3. DROPZONE MEDIA UPLOADER CONTROLLER (MULTI-FILE SUPPORT)
    // --------------------------------------------------------------------------
    const dropzone = document.getElementById('taskDropzone');
    const fileInput = document.getElementById('mediaFileInput');
    const btnRemove = document.getElementById('btnRemoveAttachment');
    const btnAddMore = document.getElementById('btnAddMoreFiles');

    if (dropzone && fileInput) {
        // Klik area dropzone membuka dialog file chooser
        dropzone.addEventListener('click', function(e) {
            if (e.target.closest('#btnRemoveAttachment') || e.target.closest('.btn-remove-single-att')) return;
            fileInput.click();
        });

        if (btnAddMore) {
            btnAddMore.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                fileInput.click();
            });
        }

        // Event saat 1 atau lebih file dipilih via chooser
        fileInput.addEventListener('change', function() {
            if (fileInput.files && fileInput.files.length > 0) {
                uploadMediaFiles(Array.from(fileInput.files), 'file_input');
                fileInput.value = '';
            }
        });

        // Drag & Drop events
        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('dragover');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('dragover');
            }, false);
        });

        dropzone.addEventListener('drop', function(e) {
            const dt = e.dataTransfer;
            if (dt && dt.files && dt.files.length > 0) {
                uploadMediaFiles(Array.from(dt.files), 'drop');
            }
        });
    }

    if (btnRemove) {
        btnRemove.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            resetDropzoneAttachment();
            if (typeof showToast === 'function') {
                showToast('Semua lampiran media dihapus', 'info');
            }
        });
    }
});

// State penyimpanan multi-lampiran untuk form Tambah Tugas Manual
window.uploadedTaskAttachments = [];
window.currentDraftUploadId = '';

function syncAttachmentsHiddenInputs() {
    const list = Array.isArray(window.uploadedTaskAttachments) ? window.uploadedTaskAttachments : [];
    const urlEl = document.getElementById('taskAttachmentUrl');
    const typeEl = document.getElementById('taskAttachmentType');
    const nameEl = document.getElementById('taskAttachmentName');
    const jsonEl = document.getElementById('taskAttachmentsJson');
    const countBadge = document.getElementById('dropzoneFileCountBadge');
    const promptContainer = document.getElementById('dropzonePrompt');
    const previewContainer = document.getElementById('dropzonePreview');
    const previewList = document.getElementById('dropzonePreviewList');

    if (urlEl) urlEl.value = list.length > 0 ? (list[0].url || '') : '';
    if (typeEl) typeEl.value = list.length > 0 ? (list[0].type || '') : '';
    if (nameEl) nameEl.value = list.length > 0 ? (list[0].name || '') : '';
    if (jsonEl) jsonEl.value = JSON.stringify(list);

    const activeDomItems = previewList ? previewList.children.length : list.length;
    if (countBadge) {
        const spanTxt = countBadge.querySelector('span');
        const countNum = Math.max(list.length, activeDomItems);
        if (spanTxt) {
            spanTxt.textContent = countNum + ' File Terlampir';
        } else {
            countBadge.textContent = '📎 ' + countNum + ' File Terlampir';
        }
    }

    if (activeDomItems === 0 && list.length === 0) {
        if (promptContainer) promptContainer.style.display = 'flex';
        if (previewContainer) previewContainer.style.display = 'none';
    } else {
        if (promptContainer) promptContainer.style.display = 'none';
        if (previewContainer) previewContainer.style.display = 'flex';
    }
}

function removeSingleAttachment(uid, e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    window.uploadedTaskAttachments = (window.uploadedTaskAttachments || []).filter(item => item.uid !== uid);
    const rowEl = document.getElementById('att-row-' + uid);
    if (rowEl && rowEl.parentNode) {
        rowEl.parentNode.removeChild(rowEl);
    }
    syncAttachmentsHiddenInputs();
}

function uploadMediaFiles(files, source) {
    if (!files || !files.length) return;
    if (!window.currentDraftUploadId) {
        window.currentDraftUploadId = 'draft_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 6);
    }
    Array.from(files).forEach((file, idx) => {
        uploadMediaFile(file, source, idx);
    });
}

// Upload media helper function (mendukung multi-file dengan baris preview masing-masing)
function uploadMediaFile(file, source, batchIdx) {
    const promptContainer = document.getElementById('dropzonePrompt');
    const previewContainer = document.getElementById('dropzonePreview');
    const previewList = document.getElementById('dropzonePreviewList');

    if (!previewContainer) return;

    if (!window.currentDraftUploadId) {
        window.currentDraftUploadId = 'draft_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 6);
    }

    if (promptContainer) promptContainer.style.display = 'none';
    previewContainer.style.display = 'flex';

    const uid = 'f_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 7) + '_' + (batchIdx || 0);
    const displayName = file.name || ('Media-Pasted-' + Date.now() + '.png');
    const sizeKb = file.size ? (file.size / 1024).toFixed(1) + ' KB' : 'Media';

    const row = document.createElement('div');
    row.id = 'att-row-' + uid;
    row.style.cssText = 'display: flex; align-items: center; gap: 10px; padding: 6px 10px; background: var(--bg-base); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm);';

    const thumbBox = document.createElement('div');
    thumbBox.className = 'preview-media';
    thumbBox.style.cssText = 'width: 42px; height: 42px; border-radius: 6px; overflow: hidden; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.04); border: 1px solid var(--border-subtle);';

    if (file.type && file.type.startsWith('image/')) {
        const img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        img.style.cssText = 'width: 100%; height: 100%; object-fit: cover;';
        thumbBox.appendChild(img);
    } else if (file.type && file.type.startsWith('video/')) {
        const vid = document.createElement('video');
        vid.src = URL.createObjectURL(file);
        vid.muted = true;
        vid.style.cssText = 'width: 100%; height: 100%; object-fit: cover;';
        thumbBox.appendChild(vid);
    } else if (file.type && file.type.startsWith('audio/')) {
        thumbBox.innerHTML = '<span style="font-size:18px;">🎵</span>';
    } else {
        thumbBox.innerHTML = '<span style="font-size:18px;">📄</span>';
    }

    const infoBox = document.createElement('div');
    infoBox.className = 'preview-info';
    infoBox.style.cssText = 'flex: 1; min-width: 0;';

    const nameDiv = document.createElement('div');
    nameDiv.className = 'preview-filename';
    nameDiv.style.cssText = 'font-size: 12.5px; font-weight: 600; color: var(--text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;';
    nameDiv.textContent = displayName;

    const metaDiv = document.createElement('div');
    metaDiv.className = 'preview-meta';
    metaDiv.style.cssText = 'font-size: 11px; color: var(--text-muted); margin-top: 2px;';
    metaDiv.textContent = 'Mengunggah (' + sizeKb + ')...';

    const progBar = document.createElement('div');
    progBar.className = 'preview-progress-bar';
    progBar.style.cssText = 'height: 4px; background: rgba(255,255,255,0.08); border-radius: 2px; overflow: hidden; margin-top: 4px;';
    const progFill = document.createElement('div');
    progFill.className = 'preview-progress-fill';
    progFill.style.cssText = 'height: 100%; width: 25%; background: var(--accent-primary); transition: width 0.2s ease;';
    progBar.appendChild(progFill);

    infoBox.appendChild(nameDiv);
    infoBox.appendChild(metaDiv);
    infoBox.appendChild(progBar);

    const rmBtn = document.createElement('button');
    rmBtn.type = 'button';
    rmBtn.className = 'btn btn-icon btn-ghost btn-remove-single-att';
    rmBtn.title = 'Hapus file ini';
    rmBtn.style.cssText = 'color: var(--text-muted); flex-shrink: 0; padding: 4px;';
    rmBtn.innerHTML = '✕';
    rmBtn.onclick = function(ev) {
        removeSingleAttachment(uid, ev);
    };

    row.appendChild(thumbBox);
    row.appendChild(infoBox);
    row.appendChild(rmBtn);

    if (previewList) {
        previewList.appendChild(row);
    }
    syncAttachmentsHiddenInputs();

    const uploadData = new FormData();
    uploadData.append('file', file, displayName);
    uploadData.append('task_id', window.currentDraftUploadId);
    uploadData.append('source', source || 'dropzone');

    const csrfInput = document.querySelector('input[name="csrf"]');
    if (csrfInput && csrfInput.value) {
        uploadData.append('csrf', csrfInput.value);
    }
    const keyInput = document.getElementById('formManualApiKey') || document.querySelector('input[name="key"]');
    const userApiKey = (window.TVSC_USER_KEY) || (keyInput ? keyInput.value : '');
    if (userApiKey) {
        uploadData.append('key', userApiKey);
    }
    const prjSelect = document.querySelector('select[name="project_id"]');
    if (prjSelect && prjSelect.value) {
        uploadData.append('project_id', prjSelect.value);
    }

    const xhr = new XMLHttpRequest();
    xhr.withCredentials = true;
    xhr.open('POST', '?action=api_upload_media', true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable && progFill) {
            const percent = Math.round((e.loaded / e.total) * 90);
            progFill.style.width = percent + '%';
        }
    };

    xhr.onload = function() {
        if (progFill) progFill.style.width = '100%';
        try {
            const res = JSON.parse(xhr.responseText);
            if (res.ok && res.file_url) {
                let normType = res.file_type || 'document';
                if (!res.file_type && file.type) {
                    if (file.type.startsWith('image/')) normType = 'photo';
                    else if (file.type.startsWith('video/')) normType = 'video';
                    else if (file.type.startsWith('audio/')) normType = 'audio';
                }
                const attObj = {
                    uid: uid,
                    url: res.file_url,
                    type: normType,
                    mime: res.file_mime || file.type || 'application/octet-stream',
                    name: res.file_name || displayName,
                    size: res.file_size || file.size || 0,
                    media_id: res.media_id || 0
                };
                window.uploadedTaskAttachments.push(attObj);
                syncAttachmentsHiddenInputs();

                nameDiv.textContent = attObj.name;
                metaDiv.textContent = '✅ Siap dilampirkan • ' + (attObj.size ? (attObj.size / 1024).toFixed(1) + ' KB' : 'Tersimpan');
                if (typeof showToast === 'function') {
                    showToast('✅ File terlampir: ' + attObj.name + ' (Total: ' + window.uploadedTaskAttachments.length + ' file)', 'success');
                }
            } else {
                metaDiv.textContent = '❌ ' + (res.error || 'Gagal mengunggah');
                progFill.style.background = 'var(--accent-danger)';
                if (typeof showToast === 'function') {
                    showToast('Gagal mengunggah media: ' + (res.error || 'Error'), 'error');
                }
            }
        } catch (e) {
            metaDiv.textContent = '❌ Format respon tidak valid';
            progFill.style.background = 'var(--accent-danger)';
        }
    };

    xhr.onerror = function() {
        metaDiv.textContent = '❌ Kesalahan koneksi jaringan';
        progFill.style.background = 'var(--accent-danger)';
        if (typeof showToast === 'function') {
            showToast('Koneksi upload gagal', 'error');
        }
    };

    xhr.send(uploadData);
}

// Reset Dropzone UI
function resetDropzoneAttachment() {
    window.uploadedTaskAttachments = [];
    window.currentDraftUploadId = '';
    const promptContainer = document.getElementById('dropzonePrompt');
    const previewContainer = document.getElementById('dropzonePreview');
    const previewList = document.getElementById('dropzonePreviewList');
    const fileInput = document.getElementById('mediaFileInput');
    const urlInput = document.getElementById('taskAttachmentUrl');
    const typeInput = document.getElementById('taskAttachmentType');
    const nameInput = document.getElementById('taskAttachmentName');
    const jsonInput = document.getElementById('taskAttachmentsJson');

    if (previewList) previewList.innerHTML = '';
    if (promptContainer) promptContainer.style.display = 'flex';
    if (previewContainer) previewContainer.style.display = 'none';
    if (fileInput) fileInput.value = '';
    if (urlInput) urlInput.value = '';
    if (typeInput) typeInput.value = '';
    if (nameInput) nameInput.value = '';
    if (jsonInput) jsonInput.value = '[]';
}

// Fallback alias jika dipanggil dari script lain
async function triggerAsyncQueueRefresh() {
    if (typeof window.triggerAsyncQueueRefresh === 'function') {
        return await window.triggerAsyncQueueRefresh();
    }
}
</script>
<style>
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
</style>

</body>
</html>
