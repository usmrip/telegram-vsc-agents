<?php
// views/landing.php - Public Landing Page & Auth Modal
if (!defined('TELE_VS_CODE')) exit;
?>
    
        <!-- ==================================================================== -->
        <!-- PUBLIC LANDING / HERO SECTION                                        -->
        <!-- ==================================================================== -->
        <main class="landing">
            <section class="landing-copy">
                <div class="landing-eyebrow">VS Code &nbsp;&harr;&nbsp; Telegram</div>
                <h1 class="landing-title">
                    <?= __t(
                        'Agen AI bekerja di editor.<br><em>Kabarnya</em> <span class="muted">sampai di saku.</span>',
                        'AI agents working in your editor.<br><em>Updates</em> <span class="muted">delivered to your pocket.</span>'
                    ) ?>
                </h1>
                <p class="landing-lead">
                    <?= __t(
                        'Kirim instruksi dari Telegram, biarkan agen di VS Code mengerjakannya, lalu terima laporan selesai &mdash; lengkap dengan hasil dan command yang dijalankan. Tidak perlu menunggu di depan layar.',
                        'Send coding instructions from Telegram, let AI agents in VS Code execute them, and receive completion reports &mdash; complete with output and commands run. No need to stay stuck at your desk.'
                    ) ?>
                </p>

                <ol class="landing-steps" style="margin-top: 28px;">
                    <li>
                        <span class="num">01</span>
                        <h3><?= __t('Tulis perintah di Telegram', 'Send commands via Telegram') ?></h3>
                        <p><?= __t('Pesan biasa, foto, atau dokumen &mdash; pakai tag <code>#a</code> untuk memilih proyek.', 'Regular text, photos, or documents &mdash; use tags like <code>#a</code> to choose target project.') ?></p>
                    </li>
                    <li>
                        <span class="num">02</span>
                        <h3><?= __t('Agen mengambil antrean', 'Agent pulls task queue') ?></h3>
                        <p><?= __t('Ekstensi VS Code menarik tugas dan agen mengerjakannya di folder yang tepat.', 'The VS Code extension pulls tasks and your agent executes them in the right workspace.') ?></p>
                    </li>
                    <li>
                        <span class="num">03</span>
                        <h3><?= __t('Laporan kembali ke kamu', 'Status delivered to you') ?></h3>
                        <p><?= __t('Status PROSES, SELESAI, atau KENDALA dikirim otomatis beserta ringkasannya.', 'IN_PROGRESS, DONE, or FAILED status is sent back with full summaries and output.') ?></p>
                    </li>
                </ol>
            </section>

            <section class="landing-preview-col">
                <div class="transcript" aria-hidden="true" style="margin-top: 0;">
                    <div class="transcript-head"><span>terminal &middot; vs code</span><span>tele-notify</span></div>
                    <span class="ln"><span class="p">$</span> tele-task add "Perbaiki validasi form login"</span>
                    <span class="ln"><span class="dim">&rarr;</span> #TASK-0412 tercatat &middot; <span class="dim">IN_PROGRESS</span></span>
                    <span class="ln"><span class="p">$</span> npm test <span class="dim">&mdash; 48 passed</span></span>
                    <span class="ln"><span class="p">$</span> tele-task done "#TASK-0412" "Validasi email &amp; WA selesai"</span>
                    <span class="ln caret"><span class="ok">&check;</span> terkirim ke Telegram &middot; 0.4s</span>
                </div>

                <!-- Quick Action Card on Landing -->
                <div class="card" style="margin-top: 24px; padding: 22px; border: 1px solid var(--border-medium); background: var(--ink-2); border-radius: var(--radius-lg);">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                        <span style="font-size: 20px;">⚡</span>
                        <h3 style="font-size: 15px; font-weight: 600; color: #fff; margin: 0;"><?= __t('Akses Cepat Pengembang', 'Quick Developer Access') ?></h3>
                    </div>
                    <p style="font-size: 12.5px; color: var(--text-secondary); line-height: 1.5; margin-bottom: 16px;">
                        <?= __t('Kelola antrean tugas coding, pantau progres AI Agent secara real-time, dan unduh paket ekstensi VS Code.', 'Manage task queues, track AI agent progress in real-time, and download the VS Code extension package.') ?>
                    </p>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-primary btn-sm" onclick="openAuthModal('login')" style="flex: 1; min-width: 120px;">
                            <span><?= __t('Masuk', 'Sign In') ?></span>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="openAuthModal('register')" style="flex: 1; min-width: 120px;">
                            <span><?= __t('Daftar Akun', 'Register') ?></span>
                        </button>
                    </div>
                </div>
            </section>
        </main>

        <!-- ==================================================================== -->
        <!-- MODAL POPUP: LOGIN & DAFTAR (iOS Minimalist Monochrome)              -->
        <!-- ==================================================================== -->
        <div class="modal-overlay" id="modalAuth" onclick="if(event.target === this) closeAuthModal()">
            <div class="modal-dialog" style="max-width: 440px; padding: 26px 24px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div class="auth-brand-glyph" style="margin-bottom: 0; width: 36px; height: 36px;"><?= renderSvg('bell', 18) ?></div>
                        <div>
                            <h2 class="auth-title" style="font-size: 17px; margin-bottom: 2px;" id="authModalTitle"><?= __t('Masuk ke ruang kerja', 'Sign in to workspace') ?></h2>
                            <p class="auth-subtitle" style="font-size: 12px; margin-bottom: 0;" id="authModalSubtitle"><?= __t('Satu akun untuk semua proyek dan bot kamu.', 'One account for all your projects and bots.') ?></p>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary btn-xs" onclick="closeAuthModal()" style="border-radius: 50%; width: 28px; height: 28px; padding: 0; display: flex; align-items: center; justify-content: center; font-size: 16px;" title="<?= __t('Tutup', 'Close') ?>">&times;</button>
                </div>

                <!-- Segmented Control Switcher -->
                <div class="auth-segmented" id="modalAuthSegmented" style="margin: 14px 0 18px;">
                    <button type="button" class="auth-segmented-btn active" id="btnAuthLogin" onclick="switchAuthTab('login')"><?= __t('Masuk', 'Sign In') ?></button>
                    <button type="button" class="auth-segmented-btn" id="btnAuthRegister" onclick="switchAuthTab('register')"><?= __t('Daftar Baru', 'Register') ?></button>
                </div>

                <!-- Box 1: Form Login -->
                <div id="boxLogin">
                    <form method="POST" action="?action=login">
                        <div class="form-group">
                            <label><?= __t('Email atau Nomor WhatsApp:', 'Email or WhatsApp Number:') ?></label>
                            <input type="text" name="identifier" placeholder="<?= __t('nama@email.com atau 08123456789', 'name@email.com or +123456789') ?>" required autocomplete="username">
                        </div>
                        <div class="form-group" style="margin-bottom: 14px;">
                            <label><?= __t('Password:', 'Password:') ?></label>
                            <input type="password" name="password" placeholder="<?= __t('Password Anda', 'Your Password') ?>" required autocomplete="current-password">
                        </div>
                        <?php if (!empty($isTurnstileActive)): ?>
                        <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($cfSiteKey) ?>" data-callback="onTurnstileSuccessLogin" data-expired-callback="onTurnstileExpiredLogin" data-error-callback="onTurnstileErrorLogin" data-theme="dark" style="margin-bottom: 14px;"></div>
                        <?php endif; ?>
                        <button type="submit" id="btnSubmitLogin" class="btn btn-primary" style="width: 100%; height: 42px;">
                            <span><?= __t('Masuk', 'Sign In') ?></span>
                        </button>
                    </form>
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12px; margin-top: 16px; flex-wrap: wrap; gap: 8px;">
                        <a href="javascript:void(0)" onclick="switchAuthTab('register')" style="color: var(--text-secondary);"><?= __t('Belum punya akun? <b style="color: #fff;">Daftar</b>', 'Don\'t have an account? <b style="color: #fff;">Sign Up</b>') ?></a>
                        <a href="javascript:void(0)" onclick="switchAuthTab('forgot')" style="color: var(--text-muted); text-decoration: underline;"><?= __t('Lupa Password?', 'Forgot Password?') ?></a>
                    </div>
                </div>

                <!-- Box 2: Form Register -->
                <div id="boxRegister" style="display: none;">
                    <form method="POST" action="?action=register">
                        <div class="form-group">
                            <label><?= __t('Alamat Email (Unik):', 'Email Address (Unique):') ?></label>
                            <input type="email" name="email" placeholder="name@email.com" required autocomplete="email">
                        </div>
                        <div class="form-group">
                            <label><?= __t('Nomor WhatsApp Aktif (Unik):', 'Active WhatsApp Number (Unique):') ?></label>
                            <input type="text" name="whatsapp" placeholder="081234567890" required autocomplete="tel">
                            <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?= __t('Untuk login alternatif dan pemulihan akun.', 'For alternative login and account recovery.') ?></small>
                        </div>
                        <div class="form-group" style="margin-bottom: 14px;">
                            <label><?= __t('Password (Minimal 6 Karakter):', 'Password (Minimum 6 Characters):') ?></label>
                            <input type="password" name="password" minlength="6" placeholder="<?= __t('Kombinasi huruf & angka', 'Letters & numbers combination') ?>" required autocomplete="new-password">
                        </div>
                        <?php if (!empty($isTurnstileActive)): ?>
                        <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($cfSiteKey) ?>" data-callback="onTurnstileSuccessRegister" data-expired-callback="onTurnstileExpiredRegister" data-error-callback="onTurnstileErrorRegister" data-theme="dark" style="margin-bottom: 14px;"></div>
                        <?php endif; ?>
                        <button type="submit" id="btnSubmitRegister" class="btn btn-primary" style="width: 100%; height: 40px;">
                            <span><?= __t('Daftar & Buat Akun', 'Create Account') ?></span>
                        </button>
                    </form>
                    <div style="text-align: center; font-size: 12px; margin-top: 16px;">
                        <a href="javascript:void(0)" onclick="switchAuthTab('login')" style="color: var(--text-secondary);"><?= __t('Sudah punya akun? <b style="color: #fff;">Masuk</b>', 'Already have an account? <b style="color: #fff;">Sign In</b>') ?></a>
                    </div>
                </div>

                <!-- Box 3: Form Lupa Password -->
                <div id="boxForgot" style="display: none;">
                    <div style="text-align: center; margin-bottom: 16px;">
                        <h3 style="font-size: 15px; font-weight: 500; color: #fff;"><?= __t('Pemulihan Password', 'Password Recovery') ?></h3>
                        <p style="font-size: 12px; color: var(--text-muted); margin-top: 4px;"><?= __t('Tautan reset aman akan dikirimkan ke email terdaftar Anda.', 'A secure reset link will be sent to your registered email.') ?></p>
                    </div>
                    <form method="POST" action="?action=forgot_password">
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label><?= __t('Alamat Email Terdaftar:', 'Registered Email Address:') ?></label>
                            <input type="email" name="email" placeholder="nama@email.com" required autocomplete="email">
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%; height: 38px;">
                            <span><?= __t('Kirim Link Reset Password', 'Send Reset Link') ?></span>
                        </button>
                    </form>
                    <div style="text-align: center; font-size: 12px; margin-top: 16px;">
                        <a href="javascript:void(0)" onclick="switchAuthTab('login')" style="color: var(--text-secondary);"><?= __t('Kembali ke Login', 'Back to Sign In') ?></a>
                    </div>
                </div>

                <!-- Box 4: Form Reset Password Baru -->
                <?php if (!empty($isTokenValid) && !empty($resetToken)): ?>
                    <div id="boxResetPass">
                        <div style="text-align: center; margin-bottom: 16px;">
                            <h3 style="font-size: 15px; font-weight: 500; color: #fff;"><?= __t('Buat Password Baru', 'Set New Password') ?></h3>
                            <p style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">Akun: <b><?= htmlspecialchars($resetEmail) ?></b></p>
                        </div>
                        <form method="POST" action="?action=do_reset_password">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($resetToken) ?>">
                            <div class="form-group">
                                <label><?= __t('Password Baru:', 'New Password:') ?></label>
                                <input type="password" name="new_password" minlength="6" placeholder="Password baru" required autocomplete="new-password">
                                <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?= __t('Minimal 6 karakter.', 'Min 6 characters.') ?></small>
                            </div>
                            <div class="form-group" style="margin-bottom: 20px;">
                                <label><?= __t('Konfirmasi Password Baru:', 'Confirm New Password:') ?></label>
                                <input type="password" name="confirm_password" minlength="6" placeholder="Ketik ulang password baru" required autocomplete="new-password">
                            </div>
                            <button type="submit" class="btn btn-primary" style="width: 100%; height: 38px;">
                                <span><?= __t('Simpan Password Baru', 'Save New Password') ?></span>
                            </button>
                        </form>
                        <div style="text-align: center; font-size: 12px; margin-top: 16px;">
                            <a href="javascript:void(0)" onclick="closeAuthModal()" style="color: var(--text-secondary);"><?= __t('Batal', 'Cancel') ?></a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Box 5: Form Verifikasi OTP Email (Pendaftaran Baru & Login 2FA Harian) -->
                <div id="boxOtp" style="<?= !empty($_SESSION['tvsc_pending_auth']) ? '' : 'display: none;' ?>">
                    <div style="text-align: center; margin-bottom: 16px;">
                        <div style="width: 44px; height: 44px; border-radius: 50%; background: rgba(59, 130, 246, 0.15); color: #60a5fa; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 10px;">
                            <?= renderSvg('key', 20) ?>
                        </div>
                        <h3 style="font-size: 15.5px; font-weight: 600; color: #fff; margin-bottom: 4px;"><?= __t('Verifikasi Kode Email', 'Email Verification Code') ?></h3>
                        <p style="font-size: 12px; color: var(--text-muted); line-height: 1.5; margin-bottom: 0;">
                            <?= __t('Kode 6 digit telah dikirimkan ke email:', 'A 6-digit verification code was sent to:') ?><br>
                            <b style="color: #60a5fa;"><?= htmlspecialchars($_SESSION['tvsc_pending_auth']['email'] ?? 'email Anda') ?></b>
                        </p>
                    </div>
                    <form method="POST" action="?action=verify_otp">
                        <div class="form-group" style="margin-bottom: 16px;">
                            <label style="text-align: center; display: block; font-size: 12px;"><?= __t('Masukkan 6 Digit Kode:', 'Enter 6-Digit Code:') ?></label>
                            <input type="text" name="otp_code" maxlength="6" pattern="[0-9]{6}" placeholder="123456" required autocomplete="one-time-code" style="text-align: center; font-size: 22px; letter-spacing: 6px; font-weight: 700; height: 46px;" autofocus>
                            <small style="color: var(--text-muted); font-size: 11px; margin-top: 6px; display: block; text-align: center;"><?= __t('Kode berlaku selama 15 menit.', 'Code expires in 15 minutes.') ?></small>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%; height: 40px;">
                            <span><?= __t('Verifikasi & Lanjutkan', 'Verify & Continue') ?></span>
                        </button>
                    </form>
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12px; margin-top: 16px; flex-wrap: wrap; gap: 8px;">
                        <form method="POST" action="?action=resend_otp" style="margin: 0;">
                            <button type="submit" class="btn-link" style="background: none; border: none; color: #60a5fa; cursor: pointer; padding: 0; font-size: 12px; text-decoration: underline;"><?= __t('Kirim Ulang Kode', 'Resend Code') ?></button>
                        </form>
                        <a href="?action=cancel_otp" style="color: var(--text-muted);"><?= __t('Batal / Ganti Akun', 'Cancel / Switch Account') ?></a>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($errorMsg) || !empty($isTokenValid) || isset($_GET['auth']) || !empty($_SESSION['tvsc_pending_auth'])): ?>
            <script>
            window.addEventListener('DOMContentLoaded', () => {
                openAuthModal('<?= !empty($_SESSION['tvsc_pending_auth']) ? 'otp' : (!empty($isTokenValid) ? 'reset' : ((isset($_GET['auth']) && $_GET['auth'] === 'register') ? 'register' : 'login')) ?>');
            });
            </script>
        <?php endif; ?>


