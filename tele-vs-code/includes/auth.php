<?php
// includes/auth.php - Authentication, OTP verification, and Password Reset
if (!defined('TELE_VS_CODE')) exit;


if (!empty($_SESSION['tvsc_flash_error'])) {
    $errorMsg = $_SESSION['tvsc_flash_error'];
    unset($_SESSION['tvsc_flash_error']);
}
if (!empty($_SESSION['tvsc_flash_success'])) {
    $successMsg = $_SESSION['tvsc_flash_success'];
    unset($_SESSION['tvsc_flash_success']);
}

// 3. User Authentication Handlers (Register & Login)
// ------------------------------------------------------------------------------
$errorMsg = '';
$successMsg = '';

// Verifikasi OTP (Pendaftaran Baru atau Login Harian)
if ($action === 'verify_otp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $otpInput = trim($_POST['otp_code'] ?? '');
    $pending = $_SESSION['tvsc_pending_auth'] ?? null;

    if (!$pending || empty($pending['email'])) {
        $errorMsg = 'Sesi verifikasi kadaluarsa atau tidak ditemukan. Silakan ulangi proses login / daftar.';
    } elseif (strlen($otpInput) !== 6 || !ctype_digit($otpInput)) {
        $errorMsg = 'Kode verifikasi harus berupa 6 digit angka!';
    } else {
        if ($pending['type'] === 'register') {
            $stmtP = $db->prepare("SELECT * FROM pending_registrations WHERE email = ? AND otp_code = ? AND expires_at >= ?");
            $stmtP->execute([$pending['email'], $otpInput, time()]);
            $regData = $stmtP->fetch(PDO::FETCH_ASSOC);

            if ($regData) {
                $apiKey = 'tvsc_' . bin2hex(random_bytes(16));
                $now = date('Y-m-d H:i:s');
                $isAdmin = (strtolower($regData['email']) === strtolower(SUPERADMIN_EMAIL)) ? 1 : 0;

                $ins = $db->prepare("INSERT INTO users (email, whatsapp, password_hash, api_key, is_admin, last_email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$regData['email'], $regData['whatsapp'], $regData['password_hash'], $apiKey, $isAdmin, $now, $now, $now]);
                $uid = $db->lastInsertId();

                $pkey = 'prj_' . substr(bin2hex(random_bytes(6)), 0, 10);
                $insP = $db->prepare("INSERT INTO projects (user_id, project_key, name, created_at, updated_at) VALUES (?, ?, 'Proyek Utama', ?, ?)");
                $insP->execute([$uid, $pkey, $now, $now]);

                $del = $db->prepare("DELETE FROM pending_registrations WHERE email = ?");
                $del->execute([$regData['email']]);

                unset($_SESSION['tvsc_pending_auth']);
                $_SESSION['tvsc_uid'] = $uid;
                header('Location: ./?tab=projects&msg=welcome');
                exit;
            } else {
                $errorMsg = 'Kode verifikasi pendaftaran salah atau telah kadaluarsa!';
            }
        } elseif ($pending['type'] === 'login') {
            $stmtU = $db->prepare("SELECT * FROM users WHERE id = ? AND email_otp = ? AND email_otp_expires >= ?");
            $stmtU->execute([$pending['user_id'], $otpInput, time()]);
            $userData = $stmtU->fetch(PDO::FETCH_ASSOC);

            if ($userData) {
                $now = date('Y-m-d H:i:s');
                $upd = $db->prepare("UPDATE users SET last_email_verified_at = ?, email_otp = '', email_otp_expires = 0 WHERE id = ?");
                $upd->execute([$now, $userData['id']]);

                unset($_SESSION['tvsc_pending_auth']);
                session_regenerate_id(true);
                $_SESSION['tvsc_uid'] = $userData['id'];
                header('Location: ./');
                exit;
            } else {
                $errorMsg = 'Kode verifikasi login salah atau telah kadaluarsa!';
            }
        }
    }
}

// Kirim Ulang Kode OTP (Pendaftaran Baru atau Login 2FA Harian)
if ($action === 'resend_otp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pending = $_SESSION['tvsc_pending_auth'] ?? null;
    if ($pending && !empty($pending['email'])) {
        $email = $pending['email'];
        if (strtolower($email) === 'test@biru.click') {
            unset($_SESSION['tvsc_pending_auth']);
            header('Location: ./');
            exit;
        }
        $otpCode = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $expires = time() + 900; // 15 menit

        if ($pending['type'] === 'register') {
            $upd = $db->prepare("UPDATE pending_registrations SET otp_code = ?, expires_at = ? WHERE email = ?");
            $upd->execute([$otpCode, $expires, $email]);

            $subject = "Kode Verifikasi Baru Pendaftaran - Tele-vs-Code";
            $otpBox = '<div class="otp-code" style="background:#090d16; color:#10b981; font-size:32px; font-weight:800; letter-spacing:8px; padding:16px; border-radius:10px; margin:20px 0; border:1px dashed #10b981; font-family:monospace;">' . $otpCode . '</div>';
            $html = buildResponsiveEmailHtml('Kode Verifikasi Baru Pendaftaran', 'Gunakan kode 6 digit berikut untuk menyelesaikan pendaftaran akun Anda:', $otpBox, 'Kode berlaku selama 15 menit. Jangan bagikan kode ini kepada siapa pun.');
            sendSmtpEmail($email, $subject, $html);
            $successMsg = "Kode verifikasi baru telah dikirimkan ke {$email}!";
        } elseif ($pending['type'] === 'login') {
            $upd = $db->prepare("UPDATE users SET email_otp = ?, email_otp_expires = ? WHERE id = ?");
            $upd->execute([$otpCode, $expires, $pending['user_id']]);

            $subject = "Kode Verifikasi Baru Login 2FA Harian - Tele-vs-Code";
            $otpBox = '<div class="otp-code" style="background:#090d16; color:#38bdf8; font-size:32px; font-weight:800; letter-spacing:8px; padding:16px; border-radius:10px; margin:20px 0; border:1px dashed #38bdf8; font-family:monospace;">' . $otpCode . '</div>';
            $html = buildResponsiveEmailHtml('Kode Verifikasi Login Baru', 'Gunakan kode 6 digit berikut untuk verifikasi login harian Anda:', $otpBox, 'Kode berlaku selama 15 menit. Jangan berikan kode ini kepada siapa pun.');
            sendSmtpEmail($email, $subject, $html);
            $successMsg = "Kode verifikasi login baru telah dikirimkan ke {$email}!";
        }
    } else {
        $errorMsg = 'Sesi verifikasi kadaluarsa. Silakan ulangi proses pendaftaran / login.';
    }
}

// Batal Verifikasi OTP
if ($action === 'cancel_otp') {
    unset($_SESSION['tvsc_pending_auth']);
    header('Location: ./');
    exit;
}

// Register dengan Verifikasi OTP Email & Turnstile
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = normalizePhone(trim($_POST['whatsapp'] ?? ''));
    $password = $_POST['password'] ?? '';
    $turnstileToken = $_POST['cf-turnstile-response'] ?? '';

    if (!verifyTurnstileToken($turnstileToken)) {
        $errorMsg = 'Verifikasi keamanan Cloudflare Turnstile gagal! Harap coba kembali.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = 'Format email tidak valid!';
    } elseif (strlen($phone) < 9) {
        $errorMsg = 'Nomor WhatsApp tidak valid (minimal 9 digit)!';
    } elseif (strtolower($email) !== 'test@biru.click' && !verifyWhatsAppNumberServerSide($_POST['whatsapp'] ?? '')) {
        $errorMsg = '❌ Nomor WhatsApp tidak valid atau tidak terdaftar di WhatsApp! Harap gunakan nomor WhatsApp aktif.';
    } elseif (strlen($password) < 6) {
        $errorMsg = 'Password minimal 6 karakter!';
    } else {
        $chkE = $db->prepare("SELECT id FROM users WHERE email = ?");
        $chkE->execute([$email]);
        if ($chkE->fetch()) {
            $errorMsg = '❌ Alamat email ini sudah pernah digunakan mendaftar! Silakan gunakan email lain atau masuk.';
        } else {
            $chkW = $db->prepare("SELECT id FROM users WHERE whatsapp = ?");
            $chkW->execute([$phone]);
            if ($chkW->fetch()) {
                $errorMsg = '❌ Nomor WhatsApp ini sudah pernah digunakan mendaftar! Silakan gunakan nomor lain atau masuk.';
            } else {
                if (strtolower($email) === 'test@biru.click') {
                    // Khusus akun test@biru.click: Bebas verifikasi email, langsung buat akun & login tanpa kirim kode verifikasi
                    $apiKey = 'tvsc_' . bin2hex(random_bytes(16));
                    $now = date('Y-m-d H:i:s');
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $isAdmin = (strtolower($email) === strtolower(SUPERADMIN_EMAIL)) ? 1 : 0;

                    $ins = $db->prepare("INSERT INTO users (email, whatsapp, password_hash, api_key, is_admin, last_email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$email, $phone, $hash, $apiKey, $isAdmin, $now, $now, $now]);
                    $uid = $db->lastInsertId();

                    $pkey = 'prj_' . substr(bin2hex(random_bytes(6)), 0, 10);
                    $insP = $db->prepare("INSERT INTO projects (user_id, project_key, name, created_at, updated_at) VALUES (?, ?, 'Proyek Utama', ?, ?)");
                    $insP->execute([$uid, $pkey, $now, $now]);

                    session_regenerate_id(true);
                    $_SESSION['tvsc_uid'] = $uid;
                    header('Location: ./?tab=projects&msg=welcome');
                    exit;
                }

                $otp = sprintf('%06d', random_int(100000, 999999));
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $expires = time() + 900; // 15 menit

                $insPending = $db->prepare("INSERT OR REPLACE INTO pending_registrations (email, whatsapp, password_hash, otp_code, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)");
                $insPending->execute([$email, $phone, $hash, $otp, $expires, date('Y-m-d H:i:s')]);

                // Kirim kode verifikasi ke email
                $subject = "🔑 Kode Verifikasi Pendaftaran Tele-vs-Code: $otp";
                $otpBox = '<div class="otp-code" style="background:#090d16; color:#10b981; font-size:32px; font-weight:800; letter-spacing:8px; padding:16px; border-radius:10px; margin:20px 0; border:1px dashed #10b981; font-family:monospace;">' . $otp . '</div>';
                $html = buildResponsiveEmailHtml('Kode Verifikasi Pendaftaran', 'Gunakan 6 digit kode di bawah ini untuk mengaktifkan akun Tele-vs-Code Anda:', $otpBox, 'Kode berlaku selama 15 menit. Jika Anda tidak merasa mendaftar, abaikan email ini dengan aman.');
                sendSmtpEmail($email, $subject, $html);

                $_SESSION['tvsc_pending_auth'] = ['type' => 'register', 'email' => $email, 'time' => time()];
                $successMsg = "Kode verifikasi 6 digit telah dikirim ke <b>" . htmlspecialchars($email) . "</b>. Masukkan kode untuk mengaktifkan akun Anda.";
            }
        }
    }
}

// Login dengan Pemeriksaan Verifikasi Email 1 Hari Sekali & Turnstile
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $failKey = 'login_fails_' . md5($ip);
    $lockKey = 'login_locked_until_' . md5($ip);
    $turnstileToken = $_POST['cf-turnstile-response'] ?? '';

    if (!verifyTurnstileToken($turnstileToken)) {
        $errorMsg = 'Verifikasi keamanan Cloudflare Turnstile gagal! Harap coba kembali.';
    } elseif (!empty($_SESSION[$lockKey]) && time() < $_SESSION[$lockKey]) {
        $remaining = max(1, ceil(($_SESSION[$lockKey] - time()) / 60));
        $errorMsg = "Terlalu banyak percobaan login gagal. Silakan tunggu {$remaining} menit lagi.";
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        $password = $_POST['password'] ?? '';

        $phoneCandidate = normalizePhone($identifier);
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? OR whatsapp = ?");
        $stmt->execute([strtolower($identifier), $phoneCandidate]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && !empty($user['is_blocked'])) {
            $errorMsg = '❌ Akun Anda telah dinonaktifkan / diblokir oleh Administrator.';
        } elseif ($user && password_verify($password, $user['password_hash'])) {
            unset($_SESSION[$failKey], $_SESSION[$lockKey]);

            // Cek apakah login perlu kode verifikasi email (wajib verifikasi 1 hari sekali / 24 jam)
            $needsDailyOtp = false;
            $isBypassOtpUser = (strtolower($user['email'] ?? '') === 'test@biru.click');
            if (!$isBypassOtpUser && (empty($user['last_email_verified_at']) || (time() - strtotime($user['last_email_verified_at'])) > 86400)) {
                $needsDailyOtp = true;
            }

            if ($needsDailyOtp) {
                $otp = sprintf('%06d', random_int(100000, 999999));
                $expires = time() + 900; // 15 menit
                $upd = $db->prepare("UPDATE users SET email_otp = ?, email_otp_expires = ? WHERE id = ?");
                $upd->execute([$otp, $expires, $user['id']]);

                // Kirim kode verifikasi login ke email
                $subject = "🔐 Kode Verifikasi Login Harian Tele-vs-Code: $otp";
                $otpBox = '<div class="otp-code" style="background:#090d16; color:#38bdf8; font-size:32px; font-weight:800; letter-spacing:8px; padding:16px; border-radius:10px; margin:20px 0; border:1px dashed #38bdf8; font-family:monospace;">' . $otp . '</div>';
                $html = buildResponsiveEmailHtml('Kode Verifikasi Login Harian', 'Untuk keamanan akun, Anda diminta verifikasi email setiap 1 hari sekali. Masukkan kode berikut:', $otpBox, 'Kode berlaku selama 15 menit. Jangan berikan kode ini kepada siapa pun.');
                sendSmtpEmail($user['email'], $subject, $html);

                $_SESSION['tvsc_pending_auth'] = ['type' => 'login', 'user_id' => $user['id'], 'email' => $user['email'], 'time' => time()];
                $successMsg = "Verifikasi Harian: Kode verifikasi 6 digit telah dikirim ke <b>" . htmlspecialchars($user['email']) . "</b>.";
            } else {
                // Sudah verifikasi dalam kurun waktu 24 jam terakhir (atau akun bypass test@biru.click) -> login langsung
                if ($isBypassOtpUser) {
                    $now = date('Y-m-d H:i:s');
                    $updVerified = $db->prepare("UPDATE users SET last_email_verified_at = ?, email_otp = '', email_otp_expires = 0 WHERE id = ?");
                    $updVerified->execute([$now, $user['id']]);
                }
                session_regenerate_id(true);
                $_SESSION['tvsc_uid'] = $user['id'];
                header('Location: ./');
                exit;
            }
        } else {
            $_SESSION[$failKey] = ($_SESSION[$failKey] ?? 0) + 1;
            if ($_SESSION[$failKey] >= 5) {
                $_SESSION[$lockKey] = time() + 300; // Kunci 5 menit
                $_SESSION[$failKey] = 0;
                $errorMsg = 'Terlalu banyak percobaan gagal. Akun dikunci sementara selama 5 menit.';
            } else {
                $sisa = 5 - $_SESSION[$failKey];
                $errorMsg = "Email/Nomor WhatsApp atau Password salah! (Sisa percobaan: $sisa)";
            }
        }
    }
}

// Logout
if ($action === 'logout') {
    unset($_SESSION['tvsc_uid']);
    session_destroy();
    header('Location: ./');
    exit;
}

// Lupa Password (Kirim Link ke Email)
if ($action === 'forgot_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = 'Format email tidak valid!';
    } else {
        $stmtU = $db->prepare("SELECT id, email FROM users WHERE LOWER(email) = ?");
        $stmtU->execute([$email]);
        $u = $stmtU->fetch(PDO::FETCH_ASSOC);

        if (!$u) {
            $successMsg = "✅ Jika alamat email <b>" . htmlspecialchars($email) . "</b> terdaftar, tautan reset password telah dikirim ke inbox / folder spam Anda.";
        } else {
            $del = $db->prepare("DELETE FROM password_resets WHERE LOWER(email) = ?");
            $del->execute([$email]);

            $token = bin2hex(random_bytes(24));
            $expires = date('Y-m-d H:i:s', time() + 3600); // 1 jam

            $ins = $db->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
            $ins->execute([$email, $token, $expires]);

            $resetUrl = "https://biru.co.id/tele-vs-code/?action=reset_pass_form&token=" . urlencode($token);

            $subject = "Permintaan Reset Password - Tele-vs-Code";
            $resetContent = '<p style="color: #cbd5e1; font-size: 14px; line-height: 1.6; margin-bottom: 16px;">Halo, kami menerima permintaan untuk mengatur ulang kata sandi akun Tele-vs-Code Anda (<b>' . htmlspecialchars($email) . '</b>).</p><div style="margin: 24px 0;"><a href="' . htmlspecialchars($resetUrl) . '" style="background: #0284c7; color: #ffffff; padding: 12px 26px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 14px; display: inline-block; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.4);">🔒 Buat Password Baru</a></div><p style="font-size: 12px; color: #64748b; word-break: break-all; margin-top: 16px;">Atau salin tautan berikut ke peramban Anda:<br><a href="' . htmlspecialchars($resetUrl) . '" style="color: #38bdf8;">' . htmlspecialchars($resetUrl) . '</a></p>';
            $htmlBody = buildResponsiveEmailHtml('Permintaan Reset Password', 'Ikuti tautan di bawah ini untuk membuat password baru:', $resetContent, 'Tautan berlaku selama 1 jam. Jika Anda tidak meminta reset password, abaikan email ini dengan aman.');

            $debugLog = '';
            $mailRes = sendSmtpEmail($email, $subject, $htmlBody, $debugLog);
            if ($mailRes['ok']) {
                $successMsg = "✅ Tautan reset password berhasil dikirim ke <b>" . htmlspecialchars($email) . "</b>! Silakan periksa inbox atau folder spam Anda (berlaku 1 jam).";
            } else {
                $errorMsg = "Gagal mengirim email reset password: " . ($mailRes['error'] ?: 'Periksa konfigurasi SMTP');
            }
        }
    }
}

// Buka Formulir Reset Password dari Link Email
$resetToken = $_GET['token'] ?? ($_POST['token'] ?? '');
$resetEmail = '';
$isTokenValid = false;

if ($action === 'reset_pass_form') {
    if (!$resetToken) {
        $errorMsg = 'Token reset password tidak ditemukan!';
        $action = '';
    } else {
        $stmtChk = $db->prepare("SELECT * FROM password_resets WHERE token = ? AND expires_at > datetime('now')");
        $stmtChk->execute([$resetToken]);
        $resetRow = $stmtChk->fetch(PDO::FETCH_ASSOC);
        if (!$resetRow) {
            $errorMsg = 'Tautan reset password sudah tidak valid atau telah kedaluwarsa (berlaku 1 jam). Silakan ajukan lupa password kembali.';
            $action = '';
        } else {
            $resetEmail = $resetRow['email'];
            $isTokenValid = true;
        }
    }
}

// Proses Simpan Password Baru (dari Link Email)
if ($action === 'do_reset_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['token'] ?? '');
    $newPass = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    $stmtChk = $db->prepare("SELECT * FROM password_resets WHERE token = ? AND expires_at > datetime('now')");
    $stmtChk->execute([$token]);
    $resetRow = $stmtChk->fetch(PDO::FETCH_ASSOC);

    if (!$resetRow) {
        $errorMsg = 'Tautan reset password sudah tidak valid atau telah kedaluwarsa!';
        $action = '';
    } elseif (strlen($newPass) < 6) {
        $errorMsg = 'Password baru minimal 6 karakter!';
        $action = 'reset_pass_form';
        $resetToken = $token;
        $resetEmail = $resetRow['email'];
        $isTokenValid = true;
    } elseif ($newPass !== $confirmPass) {
        $errorMsg = 'Konfirmasi password baru tidak cocok!';
        $action = 'reset_pass_form';
        $resetToken = $token;
        $resetEmail = $resetRow['email'];
        $isTokenValid = true;
    } else {
        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $upd = $db->prepare("UPDATE users SET password_hash = ?, updated_at = ? WHERE LOWER(email) = ?");
        $upd->execute([$hash, date('Y-m-d H:i:s'), strtolower($resetRow['email'])]);

        $del = $db->prepare("DELETE FROM password_resets WHERE LOWER(email) = ?");
        $del->execute([strtolower($resetRow['email'])]);

        $successMsg = '✅ Password Anda berhasil diperbarui! Silakan login dengan password baru.';
        $action = '';
    }
}

// ------------------------------------------------------------------------------
// 4. Logged-in User Session & Handlers
// ------------------------------------------------------------------------------


// AJAX response untuk auth agar interaksi modal/form login & register mulus tanpa reload
if (isAjaxRequest() && ($_SERVER['REQUEST_METHOD'] === 'POST' || in_array($action, ['logout', 'resend_otp', 'cancel_otp']))) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => empty($errorMsg),
        'message' => empty($errorMsg) ? ($successMsg ?: 'Berhasil') : $errorMsg,
        'error' => $errorMsg ?: null,
        'redirect' => empty($errorMsg) && !empty($_SESSION['tvsc_uid']) ? 'index.php?tab=tasks' : null,
        'otp_required' => !empty($_SESSION['tvsc_pending_auth'])
    ]);
    exit;
}
