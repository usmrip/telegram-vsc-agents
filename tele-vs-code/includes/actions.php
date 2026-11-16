<?php
// includes/actions.php - Dashboard Actions (Projects, Tasks, Settings, Admin)
if (!defined('TELE_VS_CODE')) exit;


    // --------------------------------------------------------------------------
    // A. Superadmin Actions (Manajemen Pengguna, Blok Akses, Blok Notifikasi)
    // --------------------------------------------------------------------------
    if (!empty($isSuperAdmin)) {
        // Toggle Blokir Akses Login / API
        if ($action === 'admin_toggle_block') {
            if (!verifyCsrfToken($_GET['csrf'] ?? ($_POST['csrf'] ?? ''))) {
                $errorMsg = 'Permintaan tidak valid (CSRF token salah atau kedaluwarsa).';
            } else {
                $targetUid = (int)($_GET['user_id'] ?? 0);
                if ($targetUid === (int)$currentUser['id']) {
                    $errorMsg = 'Superadmin tidak dapat memblokir akunnya sendiri!';
                } else {
                    $stmt = $db->prepare("UPDATE users SET is_blocked = CASE WHEN is_blocked = 1 THEN 0 ELSE 1 END WHERE id = ?");
                    $stmt->execute([$targetUid]);
                    $successMsg = "✅ Status akses pengguna #$targetUid berhasil diperbarui!";
                }
            }
            $currentTab = 'admin';
        }

        // Toggle Blokir Notifikasi Telegram
        if ($action === 'admin_toggle_notify') {
            if (!verifyCsrfToken($_GET['csrf'] ?? ($_POST['csrf'] ?? ''))) {
                $errorMsg = 'Permintaan tidak valid (CSRF token salah atau kedaluwarsa).';
            } else {
                $targetUid = (int)($_GET['user_id'] ?? 0);
                $stmt = $db->prepare("UPDATE users SET block_notify = CASE WHEN block_notify = 1 THEN 0 ELSE 1 END WHERE id = ?");
                $stmt->execute([$targetUid]);
                $successMsg = "✅ Status izin notifikasi Telegram pengguna #$targetUid berhasil diperbarui!";
            }
            $currentTab = 'admin';
        }

        // Reset Password User
        if ($action === 'admin_reset_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
                $errorMsg = 'Permintaan tidak valid (CSRF token salah atau kedaluwarsa).';
            } else {
                $targetUid = (int)($_POST['user_id'] ?? 0);
                $newPass = trim($_POST['new_password'] ?? '');
                if (strlen($newPass) < 6) {
                    $errorMsg = 'Password baru minimal 6 karakter!';
                } else {
                    $hash = password_hash($newPass, PASSWORD_BCRYPT);
                    $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$hash, $targetUid]);
                    $successMsg = "✅ Password pengguna #$targetUid berhasil direset!";
                }
            }
            $currentTab = 'admin';
        }

        // Hapus User
        if ($action === 'admin_delete_user') {
            if (!verifyCsrfToken($_GET['csrf'] ?? ($_POST['csrf'] ?? ''))) {
                $errorMsg = 'Permintaan tidak valid (CSRF token salah atau kedaluwarsa).';
            } else {
                $targetUid = (int)($_GET['user_id'] ?? 0);
                if ($targetUid === (int)$currentUser['id']) {
                    $errorMsg = 'Superadmin tidak dapat menghapus akunnya sendiri!';
                } else {
                    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$targetUid]);
                    $successMsg = "✅ Pengguna #$targetUid beserta proyek dan antreannya berhasil dihapus!";
                }
            }
            $currentTab = 'admin';
        }

        // Tambah Pengguna Baru oleh Superadmin
        if ($action === 'admin_add_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $email = strtolower(trim($_POST['email'] ?? ''));
            $phone = normalizePhone(trim($_POST['whatsapp'] ?? ''));
            $password = $_POST['password'] ?? '';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errorMsg = 'Format email tidak valid!';
            } elseif (strlen($phone) < 9) {
                $errorMsg = 'Nomor WhatsApp tidak valid (minimal 9 digit)!';
            } elseif (strlen($password) < 6) {
                $errorMsg = 'Password minimal 6 karakter!';
            } else {
                $chkE = $db->prepare("SELECT id FROM users WHERE email = ?");
                $chkE->execute([$email]);
                if ($chkE->fetch()) {
                    $errorMsg = 'Email ini sudah terdaftar!';
                } else {
                    $chkW = $db->prepare("SELECT id FROM users WHERE whatsapp = ?");
                    $chkW->execute([$phone]);
                    if ($chkW->fetch()) {
                        $errorMsg = 'Nomor WhatsApp ini sudah terdaftar!';
                    } else {
                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        $apiKey = 'tvsc_' . bin2hex(random_bytes(16));
                        $now = date('Y-m-d H:i:s');
                        $ins = $db->prepare("INSERT INTO users (email, whatsapp, password_hash, api_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
                        $ins->execute([$email, $phone, $hash, $apiKey, $now, $now]);
                        $newUid = $db->lastInsertId();

                        // Buat proyek default
                        $pkey = 'prj_' . substr(bin2hex(random_bytes(6)), 0, 10);
                        $insP = $db->prepare("INSERT INTO projects (user_id, project_key, name, created_at, updated_at) VALUES (?, ?, 'Proyek Utama', ?, ?)");
                        $insP->execute([$newUid, $pkey, $now, $now]);

                        $successMsg = "✅ Pengguna baru <b>" . htmlspecialchars($email) . "</b> berhasil didaftarkan!";
                    }
                }
            }
            $currentTab = 'admin';
        }

        // Bersihkan log diagnosa SMTP
        if ($action === 'clear_smtp_diag') {
            unset($_SESSION['tvsc_smtp_diag']);
            header('Location: ./?tab=admin');
            exit;
        }

        // Bersihkan Log Analitik Pengunjung
        if ($action === 'clear_analytics' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $db->exec("DELETE FROM visitor_analytics");
            $successMsg = '✅ Seluruh data log analitik pengunjung berhasil dibersihkan!';
            $currentTab = 'admin';
        }

        // Simpan Konfigurasi SMTP Server & Cloudflare Turnstile
        if ($action === 'save_smtp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            setSetting('smtp_host', trim($_POST['smtp_host'] ?? ''));
            setSetting('smtp_port', (int)($_POST['smtp_port'] ?? 465));
            setSetting('smtp_crypto', strtolower(trim($_POST['smtp_crypto'] ?? 'ssl')));
            setSetting('smtp_user', trim($_POST['smtp_user'] ?? ''));
            if (!empty($_POST['smtp_pass'])) {
                setSetting('smtp_pass', trim($_POST['smtp_pass']));
            }
            setSetting('smtp_from_name', trim($_POST['smtp_from_name'] ?? 'Tele-vs-Code'));
            setSetting('smtp_from_email', trim($_POST['smtp_from_email'] ?? ''));

            if (isset($_POST['turnstile_site_key'])) {
                setSetting('turnstile_site_key', trim($_POST['turnstile_site_key']));
            }
            if (isset($_POST['turnstile_secret_key'])) {
                setSetting('turnstile_secret_key', trim($_POST['turnstile_secret_key']));
            }

            $successMsg = '✅ Konfigurasi Server SMTP & Cloudflare Turnstile berhasil disimpan!';
            $currentTab = 'admin';
        }

        // Uji Koneksi & Tes Kirim Email SMTP
        if ($action === 'test_smtp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $testEmail = trim($_POST['test_email'] ?? $currentUser['email']);
            $debugLog = '';
            $testSubject = "🧪 Uji Coba Konfigurasi SMTP - Tele-vs-Code (" . date('d-m-Y H:i:s') . ")";
            $diagDetails = "
            <div style='background: rgba(34, 197, 94, 0.08); border: 1px solid rgba(34, 197, 94, 0.25); border-radius: 8px; padding: 14px; margin: 16px 0; text-align: left;'>
                <div style='color: #22c55e; font-weight: 700; font-size: 13.5px; margin-bottom: 8px;'>✅ Tes Koneksi Socket SMTP Berhasil</div>
                <div style='font-size: 12px; color: #cbd5e1; line-height: 1.6;'>
                    <div><b>Waktu:</b> " . date('d-m-Y H:i:s') . " WIB</div>
                    <div><b>Penerima:</b> " . htmlspecialchars($testEmail) . "</div>
                    <div><b>Host SMTP:</b> " . htmlspecialchars(getSetting('smtp_host', 'localhost')) . "</div>
                    <div><b>Protokol:</b> " . strtoupper(htmlspecialchars(getSetting('smtp_crypto', 'ssl'))) . " (Port " . (int)getSetting('smtp_port', 465) . ")</div>
                </div>
            </div>";
            $testHtml = buildResponsiveEmailHtml('Uji Coba Konfigurasi SMTP', 'Verifikasi konektivitas socket SMTP dan kesiapan pengiriman email:', $diagDetails, 'Konfigurasi email Tele-vs-Code telah terverifikasi siap untuk pengiriman OTP & notifikasi.');

            $res = sendSmtpEmail($testEmail, $testSubject, $testHtml, $debugLog);
            $_SESSION['tvsc_smtp_diag'] = [
                'ok' => $res['ok'],
                'email' => $testEmail,
                'time' => date('d-m-Y H:i:s'),
                'log' => $res['log'],
                'error' => $res['error']
            ];

            if ($res['ok']) {
                $successMsg = "✅ Email uji coba SMTP berhasil dikirim ke <b>" . htmlspecialchars($testEmail) . "</b>!";
            } else {
                $errorMsg = "❌ Tes SMTP Gagal: " . htmlspecialchars($res['error']);
            }
            $currentTab = 'admin';
        }
    }

    // Update Profil Akun & Ganti Password (User Sedang Login)
    // Aturan: Nama dan Password bisa diubah, Email dan Nomor WhatsApp terkunci/tidak bisa dirubah
    if ($action === 'update_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $newName = trim($_POST['name'] ?? '');
        $curPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        $hasPasswordChange = (!empty($newPass) || !empty($curPass) || !empty($confirmPass));

        if ($hasPasswordChange) {
            if (!password_verify($curPass, $currentUser['password_hash'])) {
                $errorMsg = '❌ Password lama / saat ini salah! Masukkan password saat ini untuk mengganti password baru.';
            } elseif (strlen($newPass) < 6) {
                $errorMsg = '❌ Password baru minimal 6 karakter!';
            } elseif ($newPass !== $confirmPass) {
                $errorMsg = '❌ Konfirmasi password baru tidak cocok!';
            } else {
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $upd = $db->prepare("UPDATE users SET name = ?, password_hash = ?, updated_at = ? WHERE id = ?");
                $upd->execute([$newName, $hash, date('Y-m-d H:i:s'), $currentUser['id']]);
                $currentUser['name'] = $newName;
                $currentUser['password_hash'] = $hash;
                $successMsg = '✅ Nama profil dan kata sandi Anda berhasil diperbarui!';
            }
        } else {
            $upd = $db->prepare("UPDATE users SET name = ?, updated_at = ? WHERE id = ?");
            $upd->execute([$newName, date('Y-m-d H:i:s'), $currentUser['id']]);
            $currentUser['name'] = $newName;
            $successMsg = '✅ Nama profil berhasil diperbarui! (Email dan Nomor WhatsApp tetap aman & permanen)';
        }
        $currentTab = $_POST['tab'] ?? 'projects';
    }

    // Ganti Password Akun Sendiri (User Sedang Login)
    if ($action === 'change_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $curPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (!password_verify($curPass, $currentUser['password_hash'])) {
            $errorMsg = 'Password lama / saat ini salah!';
        } elseif (strlen($newPass) < 6) {
            $errorMsg = 'Password baru minimal 6 karakter!';
        } elseif ($newPass !== $confirmPass) {
            $errorMsg = 'Konfirmasi password baru tidak cocok!';
        } else {
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $upd = $db->prepare("UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?");
            $upd->execute([$hash, date('Y-m-d H:i:s'), $currentUser['id']]);
            $currentUser['password_hash'] = $hash;
            $successMsg = '✅ Password akun Anda berhasil diperbarui!';
        }
        $currentTab = $_POST['tab'] ?? 'projects';
    }

    // --------------------------------------------------------------------------
    // B. Project Actions (Create, Update, Delete, Webhook, Sync Menu, Test)
    // --------------------------------------------------------------------------

    // Tambah Proyek Baru
    if ($action === 'create_project' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['name'] ?? '');
        $code = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['code'] ?? '')));
        $botToken = trim($_POST['bot_token'] ?? '');
        $chatId = trim($_POST['chat_id'] ?? '');
        $timezone = trim($_POST['timezone'] ?? '');
        if (!$timezone) {
            $timezone = !empty($currentUser['timezone']) ? $currentUser['timezone'] : 'Asia/Jakarta';
        }
        $executorMode = trim($_POST['executor_mode'] ?? 'vscode');
        if (!in_array($executorMode, ['vscode', 'shell', 'auto'])) $executorMode = 'vscode';

        if (!$name) {
            $errorMsg = 'Nama proyek wajib diisi!';
        } else {
            if (!$code) {
                $code = generateUniqueProjectCode($db);
            }

            // Validasi panjang Kode TAG: minimal 5 karakter untuk umum, admin boleh 1-4 karakter
            if (strlen($code) < 5 && empty($isSuperAdmin)) {
                $errorMsg = "❌ Kode TAG minimal 5 karakter! Hanya admin yang dapat menggunakan kode kurang dari 5 karakter.";
                $currentTab = 'projects';
            } else {
                // Validasi keunikan Kode TAG di seluruh sistem (seperti nama domain)
                $stmtChk = $db->prepare("SELECT id FROM projects WHERE LOWER(code) = ? LIMIT 1");
                $stmtChk->execute([$code]);
                if ($stmtChk->fetch()) {
                    $errorMsg = "❌ Kode TAG '<b>#$code</b>' sudah digunakan pengguna lain! Seperti nama domain, Kode TAG harus unik di seluruh sistem.";
                    $currentTab = 'projects';
                } else {
                $pkey = 'prj_' . substr(bin2hex(random_bytes(6)), 0, 10);
                $now = date('Y-m-d H:i:s');
                $ins = $db->prepare("INSERT INTO projects (user_id, project_key, name, code, bot_token, chat_id, timezone, executor_mode, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$currentUser['id'], $pkey, $name, $code, $botToken, $chatId, $timezone, $executorMode, $now, $now]);
                $successMsg = "✅ Proyek <b>" . htmlspecialchars($name) . "</b> (Kode TAG: <code>#$code</code>) berhasil dibuat!";
                $currentTab = 'projects';
                }
            }
        }
    }

    // Update Proyek & Zona Waktu
    if ($action === 'save_project' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pid = (int)($_POST['project_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['code'] ?? '')));
        $botToken = trim($_POST['bot_token'] ?? '');
        $chatId = trim($_POST['chat_id'] ?? '');
        $timezone = trim($_POST['timezone'] ?? '');
        if (!$timezone) {
            $timezone = !empty($currentUser['timezone']) ? $currentUser['timezone'] : 'Asia/Jakarta';
        }
        $executorMode = trim($_POST['executor_mode'] ?? 'vscode');
        if (!in_array($executorMode, ['vscode', 'shell', 'auto'])) $executorMode = 'vscode';

        if (!$code) {
            $code = generateUniqueProjectCode($db);
        }

        // Validasi panjang Kode TAG: minimal 5 karakter untuk umum, admin boleh 1-4 karakter
        if (strlen($code) < 5 && empty($isSuperAdmin)) {
            $errorMsg = "❌ Kode TAG minimal 5 karakter! Hanya admin yang dapat menggunakan kode kurang dari 5 karakter.";
            $currentTab = 'projects';
        } else {
            // Validasi keunikan Kode TAG di seluruh sistem (seperti nama domain)
            $stmtChk = $db->prepare("SELECT id FROM projects WHERE LOWER(code) = ? AND id != ? LIMIT 1");
            $stmtChk->execute([$code, $pid]);
            if ($stmtChk->fetch()) {
                $errorMsg = "❌ Kode TAG '<b>#$code</b>' sudah digunakan pengguna lain! Seperti nama domain, Kode TAG harus unik di seluruh sistem.";
                $currentTab = 'projects';
            } else {
            $upd = $db->prepare("UPDATE projects SET name = ?, code = ?, bot_token = ?, chat_id = ?, timezone = ?, executor_mode = ?, updated_at = ? WHERE id = ? AND user_id = ?");
            $upd->execute([$name, $code, $botToken, $chatId, $timezone, $executorMode, date('Y-m-d H:i:s'), $pid, $currentUser['id']]);

            $successMsg = "✅ Perubahan proyek <b>" . htmlspecialchars($name) . "</b> (Kode TAG: <code>#$code</code>) berhasil disimpan!";
            $currentTab = 'projects';
            }
        }
    }

    // Update Zona Waktu Akun Pengguna
    if ($action === 'save_user_timezone' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $newTz = trim($_POST['timezone'] ?? 'Asia/Jakarta');
        $upd = $db->prepare("UPDATE users SET timezone = ?, updated_at = ? WHERE id = ?");
        $upd->execute([$newTz, date('Y-m-d H:i:s'), $currentUser['id']]);
        $currentUser['timezone'] = $newTz;
        $successMsg = "✅ Zona waktu default akun berhasil diatur ke <b>" . htmlspecialchars($newTz) . " (" . getTimezoneAbbr($newTz) . ")</b>!";
        $currentTab = $_POST['tab'] ?? 'projects';
    }

    // Hapus Proyek
    if ($action === 'delete_project') {
        if (!verifyCsrfToken($_GET['csrf'] ?? ($_POST['csrf'] ?? ''))) {
            $errorMsg = 'Permintaan tidak valid (CSRF token salah atau kedaluwarsa).';
        } else {
            $pid = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
            $del = $db->prepare("DELETE FROM projects WHERE id = ? AND user_id = ?");
            $del->execute([$pid, $currentUser['id']]);
            $successMsg = "✅ Proyek berhasil dihapus!";
        }
        if (!empty($_GET['ajax']) || !empty($_POST['ajax'])) {
            header('Content-Type: application/json; charset=utf-8');
            if (!empty($resWebhook['ok']) && !empty($resCmd['ok'])) {
                echo json_encode(['ok' => true, 'message' => 'Perintah & Webhook berhasil dipasang!', 'commands_count' => count($commands), 'bot_username' => $botUser]);
            } elseif (!empty($resWebhook['ok'])) {
                echo json_encode(['ok' => true, 'message' => 'Webhook aktif, menu perintah: ' . ($resCmd['description'] ?? 'OK'), 'bot_username' => $botUser]);
            } else {
                echo json_encode(['ok' => false, 'description' => $resWebhook['description'] ?? 'Gagal pasang webhook']);
            }
            exit;
        }
        $currentTab = 'projects';
    }

    // AJAX: Cek Bot Aktif & Ambil Nama Bot (getMe)
    if ($action === 'check_bot_active') {
        header('Content-Type: application/json; charset=utf-8');
        $pid = (int)($_GET['id'] ?? 0);
        if (!empty($isSuperAdmin)) {
            $stmtP = $db->prepare("SELECT * FROM projects WHERE id = ?");
            $stmtP->execute([$pid]);
        } else {
            $stmtP = $db->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
            $stmtP->execute([$pid, $currentUser['id']]);
        }
        $prj = $stmtP->fetch(PDO::FETCH_ASSOC);
        if (!$prj || empty($prj['bot_token'])) {
            echo json_encode(['ok' => false, 'description' => 'Telegram Bot Token belum diisi pada proyek ini.']);
            exit;
        }

        $resMe = callTelegram($prj['bot_token'], 'getMe');
        if (!empty($resMe['ok']) && !empty($resMe['result']['username'])) {
            $botU = $resMe['result']['username'];
            $botName = $resMe['result']['first_name'] ?? $botU;
            try {
                $db->prepare("UPDATE projects SET bot_username = ? WHERE id = ?")->execute([$botU, $prj['id']]);
            } catch (Exception $e) {}
            echo json_encode([
                'ok' => true,
                'username' => '@' . $botU,
                'raw_username' => $botU,
                'name' => $botName,
                'chat_id' => $prj['chat_id'] ?? ''
            ]);
            exit;
        } else {
            echo json_encode([
                'ok' => false,
                'description' => $resMe['description'] ?? 'Bot tidak aktif atau Bot Token tidak valid. Harap periksa kembali token dari @BotFather.'
            ]);
            exit;
        }
    }

    // AJAX: Cek Status Verifikasi Bot dari Telegram (/verifikasi)
    if ($action === 'check_bot_verification') {
        header('Content-Type: application/json; charset=utf-8');
        $pid = (int)($_GET['id'] ?? 0);
        $since = (int)($_GET['since'] ?? 0);

        if (!empty($isSuperAdmin)) {
            $stmtP = $db->prepare("SELECT id, name, bot_token, bot_username, bot_verified_at, chat_id FROM projects WHERE id = ?");
            $stmtP->execute([$pid]);
        } else {
            $stmtP = $db->prepare("SELECT id, name, bot_token, bot_username, bot_verified_at, chat_id FROM projects WHERE id = ? AND user_id = ?");
            $stmtP->execute([$pid, $currentUser['id']]);
        }
        $prj = $stmtP->fetch(PDO::FETCH_ASSOC);
        if (!$prj) {
            echo json_encode(['ok' => false, 'verified' => false, 'description' => 'Proyek tidak ditemukan']);
            exit;
        }

        $pingTs = (int)getSetting('bot_verify_ping_' . $prj['id'], '0');
        $isVerified = false;
        $verifiedAt = $prj['bot_verified_at'];

        if ($pingTs > 0 && ($since === 0 || $pingTs >= ($since - 5))) {
            $isVerified = true;
        } elseif (!empty($verifiedAt)) {
            $vTs = strtotime($verifiedAt);
            if ($since === 0 || $vTs >= ($since - 5)) {
                $isVerified = true;
            }
        }

        echo json_encode([
            'ok' => true,
            'verified' => $isVerified,
            'verified_at' => $verifiedAt,
            'bot_username' => !empty($prj['bot_username']) ? ('@' . $prj['bot_username']) : 'Bot',
            'chat_id' => $prj['chat_id'] ?? ''
        ]);
        exit;
    }

    // Hubungkan Perintah ke Bot (Webhook + setMyCommands + setChatMenuButton)
    if ($action === 'connect_bot_commands') {
        $pid = (int)($_GET['id'] ?? 0);
        if (!empty($isSuperAdmin)) {
            $stmtP = $db->prepare("SELECT * FROM projects WHERE id = ?");
            $stmtP->execute([$pid]);
        } else {
            $stmtP = $db->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
            $stmtP->execute([$pid, $currentUser['id']]);
        }
        $prj = $stmtP->fetch(PDO::FETCH_ASSOC);

        $isAjax = (!empty($_GET['ajax']) || !empty($_POST['ajax']));

        if (!$prj || empty($prj['bot_token'])) {
            $errorMsg = 'Bot Token belum diisi pada proyek ini! Harap masukkan dan simpan Telegram Bot Token terlebih dahulu.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'description' => $errorMsg]);
                exit;
            }
        } else {
            // 1. Pasang Webhook
            $webhookTarget = "https://biru.co.id/tele-vs-code/webhook?pkey=" . urlencode($prj['project_key']);
            $resWebhook = callTelegram($prj['bot_token'], 'setWebhook', ['url' => $webhookTarget]);

            // 2. Daftarkan Menu Perintah (setMyCommands)
            $commands = getProjectCommands($prj);
            $resCmd = callTelegram($prj['bot_token'], 'setMyCommands', ['commands' => $commands]);

            // 3. Atur Tombol Menu Default Chat (setChatMenuButton)
            callTelegram($prj['bot_token'], 'setChatMenuButton', ['menu_button' => ['type' => 'commands']]);

            // 4. Ambil profil bot (getMe)
            $botMe = callTelegram($prj['bot_token'], 'getMe');
            $botUser = !empty($botMe['result']['username']) ? ('@' . $botMe['result']['username']) : 'Bot Telegram';

            // Simpan bot_username ke database
            if (!empty($botMe['result']['username'])) {
                try {
                    $db->prepare("UPDATE projects SET bot_username = ? WHERE id = ?")->execute([$botMe['result']['username'], $prj['id']]);
                } catch (Exception $e) {}
            }

            if (!empty($resWebhook['ok']) && !empty($resCmd['ok'])) {
                $countCmd = count($commands);
                $successMsg = "⚡ <b>Perintah & Webhook Berhasil Dihubungkan ke Bot!</b><br>" .
                              "• Bot: <b>" . htmlspecialchars($botUser) . "</b><br>" .
                              "• Webhook: <b>Aktif</b> (Bot kini dapat menerima perintah & pesan langsung dari Telegram)<br>" .
                              "• Menu Perintah: <b>Aktif</b> ($countCmd perintah didaftarkan ke tombol <code>[/]</code> Telegram)<br>" .
                              "• Tombol Menu: <b>Aktif</b> (Commands Menu).";
            } elseif (!empty($resWebhook['ok'])) {
                $successMsg = "✅ Webhook berhasil dipasang (Bot siap menerima pesan), namun pendaftaran menu perintah mengalami kendala: " . htmlspecialchars($resCmd['description'] ?? 'Error API');
            } else {
                $errorMsg = "Gagal menghubungkan bot ke server: " . htmlspecialchars($resWebhook['description'] ?? 'Periksa kembali Bot Token Anda');
            }

            // Respons JSON untuk Wizard Step 2 (AJAX)
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                if (!empty($resWebhook['ok']) && !empty($resCmd['ok'])) {
                    echo json_encode([
                        'ok' => true,
                        'message' => 'Perintah & Webhook berhasil dipasang!',
                        'bot_username' => $botUser,
                        'commands_count' => count($commands)
                    ]);
                } elseif (!empty($resWebhook['ok'])) {
                    echo json_encode([
                        'ok' => true,
                        'message' => 'Webhook aktif, menu perintah: ' . ($resCmd['description'] ?? 'OK'),
                        'bot_username' => $botUser
                    ]);
                } else {
                    echo json_encode([
                        'ok' => false,
                        'description' => $resWebhook['description'] ?? 'Gagal pasang webhook Telegram. Periksa kembali Bot Token.'
                    ]);
                }
                exit;
            }
        }
        $currentTab = 'projects';
    }

    // Pasang Webhook Otomatis ke Telegram Bot Proyek
    if ($action === 'set_telegram_webhook') {
        $pid = (int)($_GET['id'] ?? 0);
        if (!empty($isSuperAdmin)) {
            $stmtP = $db->prepare("SELECT * FROM projects WHERE id = ?");
            $stmtP->execute([$pid]);
        } else {
            $stmtP = $db->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
            $stmtP->execute([$pid, $currentUser['id']]);
        }
        $prj = $stmtP->fetch(PDO::FETCH_ASSOC);

        if (!$prj || empty($prj['bot_token'])) {
            $errorMsg = 'Harap isi dan simpan Bot Token proyek ini terlebih dahulu!';
        } else {
            $webhookTarget = "https://biru.co.id/tele-vs-code/webhook?pkey=" . urlencode($prj['project_key']);
            $res = callTelegram($prj['bot_token'], 'setWebhook', ['url' => $webhookTarget]);
            if (!empty($res['ok'])) {
                $successMsg = "✅ Webhook Telegram berhasil dipasang untuk proyek <b>" . htmlspecialchars($prj['name']) . "</b>!";
            } else {
                $errorMsg = "Gagal pasang webhook: " . ($res['description'] ?? 'Periksa kembali Bot Token');
            }
        }
        $currentTab = 'projects';
    }

    // Sinkron Menu Telegram (setMyCommands)
    if ($action === 'sync_telegram_menu') {
        $pid = (int)($_GET['id'] ?? 0);
        if (!empty($isSuperAdmin)) {
            $stmtP = $db->prepare("SELECT * FROM projects WHERE id = ?");
            $stmtP->execute([$pid]);
        } else {
            $stmtP = $db->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
            $stmtP->execute([$pid, $currentUser['id']]);
        }
        $prj = $stmtP->fetch(PDO::FETCH_ASSOC);

        if (!$prj || empty($prj['bot_token'])) {
            $errorMsg = 'Bot Token belum diatur untuk proyek ini!';
        } else {
            $commands = getProjectCommands($prj);
            $res = callTelegram($prj['bot_token'], 'setMyCommands', ['commands' => $commands]);
            if (!empty($res['ok'])) {
                $countCmd = count($commands);
                $successMsg = "🚀 <b>Menu Telegram Berhasil Disinkronkan!</b> ($countCmd perintah telah aktif di tombol [/] bot Telegram Anda).";
            } else {
                $errorMsg = "Gagal sinkron menu Telegram: " . ($res['description'] ?? 'Error API');
            }
        }
        $currentTab = 'projects';
    }

    // Tes Lengkap Bot Telegram (Diagnostik Token, Webhook, Chat, Kirim Pesan)
    if ($action === 'test_bot' || $action === 'test_notification') {
        $pid = (int)($_GET['id'] ?? 0);
        if (!empty($isSuperAdmin)) {
            $stmtP = $db->prepare("SELECT * FROM projects WHERE id = ?");
            $stmtP->execute([$pid]);
        } else {
            $stmtP = $db->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
            $stmtP->execute([$pid, $currentUser['id']]);
        }
        $prj = $stmtP->fetch(PDO::FETCH_ASSOC);

        if (!$prj) {
            $errorMsg = 'Proyek tidak ditemukan!';
        } elseif (empty($prj['bot_token'])) {
            $errorMsg = 'Bot Token belum diisi! Silakan isi dan simpan Bot Token terlebih dahulu.';
        } else {
            $botToken = $prj['bot_token'];
            $chatId = $prj['chat_id'];
            
            $diag = [
                'project_name' => $prj['name'],
                'project_id' => $prj['id'],
                'token_ok' => false,
                'bot_info' => null,
                'webhook_ok' => false,
                'webhook_info' => null,
                'chat_ok' => false,
                'chat_info' => null,
                'msg_ok' => false,
                'msg_info' => null,
                'error_detail' => ''
            ];

            // 1. Tes getMe
            $getMe = callTelegram($botToken, 'getMe');
            if (!empty($getMe['ok'])) {
                $diag['token_ok'] = true;
                $diag['bot_info'] = $getMe['result'];
            } else {
                $diag['error_detail'] = 'Token Bot tidak valid: ' . ($getMe['description'] ?? 'Unauthorized');
            }

            // 2. Tes getWebhookInfo
            if ($diag['token_ok']) {
                $getHook = callTelegram($botToken, 'getWebhookInfo');
                if (!empty($getHook['ok'])) {
                    $diag['webhook_info'] = $getHook['result'];
                    $diag['webhook_ok'] = !empty($getHook['result']['url']);
                }
            }

            // 3. Tes getChat (jika ada chat_id)
            if ($diag['token_ok'] && !empty($chatId)) {
                $getChat = callTelegram($botToken, 'getChat', ['chat_id' => $chatId]);
                if (!empty($getChat['ok'])) {
                    $diag['chat_ok'] = true;
                    $diag['chat_info'] = $getChat['result'];
                } else {
                    $diag['chat_error'] = $getChat['description'] ?? 'Chat tidak ditemukan';
                }

                // 4. Tes sendMessage
                $prjTz = !empty($prj['timezone']) ? $prj['timezone'] : (!empty($currentUser['timezone']) ? $currentUser['timezone'] : 'Asia/Jakarta');
                applyTimezone($prjTz);
                $prjTzAbbr = getTimezoneAbbr($prjTz);

                $testMsg = "🎉 <b>DIAGNOSTIK & TES BOT DEV NOTIFIER</b>\n\n"
                         . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($prj['name']) . "</code>\n"
                         . "🤖 <b>Bot:</b> @" . htmlspecialchars($diag['bot_info']['username'] ?? 'Bot') . " (ID: <code>" . ($diag['bot_info']['id'] ?? '') . "</code>)\n"
                         . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $prjTzAbbr\n"
                         . "👤 <b>User:</b> " . htmlspecialchars($currentUser['email']) . "\n\n"
                         . "✅ <i>Bot dan server biru.co.id/tele-vs-code terhubung 100% normal!</i>";
                
                $sendRes = callTelegram($botToken, 'sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $testMsg,
                    'parse_mode' => 'HTML'
                ]);
                if (!empty($sendRes['ok'])) {
                    $diag['msg_ok'] = true;
                    $diag['msg_info'] = $sendRes['result'];
                } else {
                    $diag['msg_error'] = $sendRes['description'] ?? 'Gagal mengirim pesan';
                }
            }

            $_SESSION['tvsc_bot_diag'] = $diag;
            if ($diag['token_ok'] && $diag['msg_ok']) {
                $successMsg = "✅ <b>Uji Coba Bot Berhasil!</b> Pesan tes telah terkirim ke Telegram proyek <b>" . htmlspecialchars($prj['name']) . "</b>.";
            } else {
                $actualError = $diag['error_detail'] ?: ($diag['msg_error'] ?: ($diag['chat_error'] ?? 'Periksa Chat ID atau Webhook'));
                $errorMsg = "⚠️ <b>Hasil Tes Bot:</b> " . $actualError;
            }
        }
        $currentTab = 'projects';
    }

    // Tambah Whitelist User Private Chat
    if ($action === 'add_whitelist' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $tUserId = trim($_POST['telegram_user_id'] ?? '');
        $tUsername = trim(ltrim($_POST['telegram_username'] ?? '', '@'));
        $name = trim($_POST['name'] ?? '');
        $projectId = (int)($_POST['project_id'] ?? 0);

        if (!$tUserId) {
            $errorMsg = 'Telegram User ID wajib diisi!';
        } else {
            $stmtChk = $db->prepare("SELECT id FROM bot_whitelist WHERE user_id = ? AND project_id = ? AND telegram_user_id = ?");
            $stmtChk->execute([$currentUser['id'], $projectId, $tUserId]);
            if ($stmtChk->fetch()) {
                $errorMsg = 'User ID Telegram tersebut sudah terdaftar di whitelist untuk target proyek ini!';
            } else {
                $insW = $db->prepare("INSERT INTO bot_whitelist (user_id, project_id, telegram_user_id, telegram_username, name, created_at) VALUES (?, ?, ?, ?, ?, ?)");
                $insW->execute([$currentUser['id'], $projectId, $tUserId, $tUsername, $name, date('Y-m-d H:i:s')]);
                $successMsg = "✅ Pengguna Telegram <b>" . htmlspecialchars($name ?: $tUserId) . "</b> berhasil ditambahkan ke Whitelist Private Chat!";
            }
        }
        $currentTab = 'projects';
    }

    // Hapus Whitelist User Private Chat
    if ($action === 'delete_whitelist') {
        if (!verifyCsrfToken($_GET['csrf'] ?? ($_POST['csrf'] ?? ''))) {
            $errorMsg = 'Permintaan tidak valid (CSRF token salah atau kedaluwarsa).';
        } else {
            $wid = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
            $delW = $db->prepare("DELETE FROM bot_whitelist WHERE id = ? AND user_id = ?");
            $delW->execute([$wid, $currentUser['id']]);
            $successMsg = "✅ Pengguna berhasil dihapus dari Whitelist Private Chat!";
        }
        $currentTab = 'projects';
    }

    // --------------------------------------------------------------------------
    // C. Task Actions (Live Auto-Refresh Poll, Add Manual, Mark Done, Delete)
    // --------------------------------------------------------------------------
    if ($action === 'check_task_updates') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $clientHash = trim($_GET['hash'] ?? '');
        $filterPrj = isset($_GET['filter_project']) ? trim($_GET['filter_project']) : '';

        // Resolusi filter project ID jika formatnya nama/code (misal ayo.ke)
        $filterProjectId = 0;
        $filterOnlyIde = ($filterPrj === 'ide');
        if (is_numeric($filterPrj) && (int)$filterPrj > 0) {
            $filterProjectId = (int)$filterPrj;
        } elseif ($filterPrj && $filterPrj !== 'all' && !$filterOnlyIde) {
            $cleanRaw = strtolower(ltrim(trim($filterPrj), '#/'));
            $stmtFindP = $db->prepare("SELECT id FROM projects WHERE user_id = ? AND (project_key = ? OR LOWER(code) = ? OR LOWER(name) = ?) LIMIT 1");
            $stmtFindP->execute([(int)$currentUser['id'], $filterPrj, $cleanRaw, $cleanRaw]);
            $filterProjectId = (int)$stmtFindP->fetchColumn();
        }

        $countSql = "SELECT status, COUNT(*) as c FROM tasks WHERE user_id = ?";
        $countParams = [(int)$currentUser['id']];
        if ($filterOnlyIde) {
            $countSql .= " AND (sender LIKE '%IDE%' OR sender LIKE '%VS Code%' OR command = 'tele-task add')";
        } elseif ($filterProjectId > 0) {
            $countSql .= " AND project_id = ?";
            $countParams[] = $filterProjectId;
        }
        $countSql .= " GROUP BY status";
        $stmtCnt = $db->prepare($countSql);
        $stmtCnt->execute($countParams);
        $stats = ['TOTAL' => 0, 'PENDING' => 0, 'IN_PROGRESS' => 0, 'DONE' => 0, 'FAILED' => 0];
        while ($row = $stmtCnt->fetch(PDO::FETCH_ASSOC)) {
            $st = strtoupper($row['status'] ?? '');
            if (isset($stats[$st])) {
                $stats[$st] = (int)$row['c'];
            }
            $stats['TOTAL'] += (int)$row['c'];
        }

        // Ambil task terbaru sesuai filter project jika dipilih
        $taskSql = "SELECT id, project_id, instruction, sender, status, result, notes, assigned_agent, priority, attachment_url, attachments_json, created_at, updated_at FROM tasks WHERE user_id = ?";
        $taskParams = [(int)$currentUser['id']];
        if ($filterOnlyIde) {
            $taskSql .= " AND (sender LIKE '%IDE%' OR sender LIKE '%VS Code%' OR command = 'tele-task add')";
        } elseif ($filterProjectId > 0) {
            $taskSql .= " AND project_id = ?";
            $taskParams[] = $filterProjectId;
        }
        $taskSql .= " ORDER BY created_at DESC, id DESC LIMIT 30";
        $stmtLatest = $db->prepare($taskSql);
        $stmtLatest->execute($taskParams);
        $latestTasks = $stmtLatest->fetchAll(PDO::FETCH_ASSOC);

        $statePayload = [
            'stats' => $stats,
            'first_id' => !empty($latestTasks[0]['id']) ? $latestTasks[0]['id'] : '',
            'first_status' => !empty($latestTasks[0]['status']) ? $latestTasks[0]['status'] : '',
            'tasks' => array_map(function($t) {
                return [
                    'id' => $t['id'],
                    'status' => $t['status'],
                    'updated_at' => $t['updated_at'],
                    'created_at' => $t['created_at'],
                    'instruction' => $t['instruction'],
                    'result' => $t['result'],
                    'notes' => $t['notes'],
                    'attachments_json' => $t['attachments_json'] ?? ''
                ];
            }, $latestTasks)
        ];
        $newHash = md5(json_encode($statePayload));
        $isChanged = ($clientHash !== $newHash);

        $resPayload = [
            'ok' => true,
            'changed' => $isChanged,
            'hash' => $newHash,
            'first_id' => !empty($latestTasks[0]['id']) ? $latestTasks[0]['id'] : '',
            'stats' => $stats,
            'tasks' => $latestTasks
        ];

        // Jika berubah (tugas baru atau status update), sertakan cards_html & ide_banner_html agar refresh instan tanpa fetch kedua
        if ($isChanged) {
            $pData = renderTaskCardsPartialData($db, $currentUser, $_GET);
            $resPayload['cards_html'] = $pData['cards_html'];
            $resPayload['ide_banner_html'] = $pData['ide_banner_html'];
            $resPayload['count_summary'] = $pData['count_summary'];
        }

        echo json_encode($resPayload);
        exit;
    }

    if ($action === 'render_task_cards') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $pData = renderTaskCardsPartialData($db, $currentUser, $_GET);

        $filterPrj = isset($_GET['filter_project']) ? trim($_GET['filter_project']) : '';
        $filterProjectId = 0;
        $filterOnlyIde = ($filterPrj === 'ide');
        if (is_numeric($filterPrj) && (int)$filterPrj > 0) {
            $filterProjectId = (int)$filterPrj;
        } elseif ($filterPrj && $filterPrj !== 'all' && !$filterOnlyIde) {
            $cleanRaw = strtolower(ltrim(trim($filterPrj), '#/'));
            $stmtFindP = $db->prepare("SELECT id FROM projects WHERE user_id = ? AND (project_key = ? OR LOWER(code) = ? OR LOWER(name) = ?) LIMIT 1");
            $stmtFindP->execute([(int)$currentUser['id'], $filterPrj, $cleanRaw, $cleanRaw]);
            $filterProjectId = (int)$stmtFindP->fetchColumn();
        }

        $countSql = "SELECT status, COUNT(*) as c FROM tasks WHERE user_id = ?";
        $countParams = [(int)$currentUser['id']];
        if ($filterOnlyIde) {
            $countSql .= " AND (sender LIKE '%IDE%' OR sender LIKE '%VS Code%' OR command = 'tele-task add')";
        } elseif ($filterProjectId > 0) {
            $countSql .= " AND project_id = ?";
            $countParams[] = $filterProjectId;
        }
        $countSql .= " GROUP BY status";
        $stmtCnt = $db->prepare($countSql);
        $stmtCnt->execute($countParams);
        $stats = ['TOTAL' => 0, 'PENDING' => 0, 'IN_PROGRESS' => 0, 'DONE' => 0, 'FAILED' => 0];
        while ($row = $stmtCnt->fetch(PDO::FETCH_ASSOC)) {
            $st = strtoupper($row['status'] ?? '');
            if (isset($stats[$st])) {
                $stats[$st] = (int)$row['c'];
            }
            $stats['TOTAL'] += (int)$row['c'];
        }

        echo json_encode([
            'ok' => true,
            'cards_html' => $pData['cards_html'],
            'ide_banner_html' => $pData['ide_banner_html'],
            'count_summary' => $pData['count_summary'],
            'total_count' => $pData['total_count'],
            'page' => $pData['page'],
            'total_pages' => $pData['total_pages'],
            'stats' => $stats
        ]);
        exit;
    }

    if ($action === 'add_manual_task' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $prjId = (int)($_POST['project_id'] ?? 0);
        $instruction = trim($_POST['instruction'] ?? '');
        $sender = trim($_POST['sender'] ?? 'Dashboard Web');
        $command = trim($_POST['command'] ?? 'Web Dashboard');
        $priority = strtoupper(trim($_POST['priority'] ?? 'NORMAL'));
        if (!in_array($priority, ['URGENT', 'HIGH', 'NORMAL', 'LOW'])) $priority = 'NORMAL';

        $attUrl = trim($_POST['attachment_url'] ?? '');
        $attType = trim($_POST['attachment_type'] ?? '');
        $attName = trim($_POST['attachment_name'] ?? '');
        $attachmentsJsonRaw = trim($_POST['attachments_json'] ?? '');
        $attachmentsArr = [];
        if ($attachmentsJsonRaw !== '') {
            $dec = json_decode($attachmentsJsonRaw, true);
            if (is_array($dec)) {
                foreach ($dec as $it) {
                    if (is_array($it) && !empty($it['url'])) {
                        $attachmentsArr[] = [
                            'url' => trim((string)$it['url']),
                            'type' => trim((string)($it['type'] ?? 'document')),
                            'name' => trim((string)($it['name'] ?? basename(parse_url($it['url'], PHP_URL_PATH) ?: 'file'))),
                            'mime' => trim((string)($it['mime'] ?? '')),
                            'size' => isset($it['size']) ? (int)$it['size'] : 0,
                            'media_id' => isset($it['media_id']) ? (int)$it['media_id'] : 0
                        ];
                    }
                }
            }
        }
        if (empty($attachmentsArr) && $attUrl !== '') {
            $attachmentsArr[] = [
                'url' => $attUrl,
                'type' => $attType ?: 'document',
                'name' => $attName ?: basename(parse_url($attUrl, PHP_URL_PATH) ?: 'file')
            ];
        }
        if (!empty($attachmentsArr)) {
            $attUrl = $attachmentsArr[0]['url'];
            $attType = $attachmentsArr[0]['type'];
            $attName = $attachmentsArr[0]['name'];
        }
        $attachmentsJson = !empty($attachmentsArr) ? json_encode($attachmentsArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

        // Jika pengguna hanya upload file tanpa mengetik teks instruksi, berikan instruksi default
        if (!$instruction && !empty($attachmentsArr)) {
            $instruction = count($attachmentsArr) > 1
                ? ("Tolong analisis dan proses " . count($attachmentsArr) . " file terlampir ini.")
                : ("Tolong analisis dan proses file " . ($attName ?: $attType) . " terlampir ini.");
        }

        if (!$instruction) {
            $errorMsg = 'Instruksi tugas tidak boleh kosong!';
        } else {
            // Pastikan setiap file terlampir juga tercatat di header instruksi untuk kompatibilitas AI Agent
            if (!empty($attachmentsArr)) {
                $prefixLines = [];
                foreach ($attachmentsArr as $attItem) {
                    $uItem = $attItem['url'];
                    if ($uItem && strpos($instruction, $uItem) === false) {
                        $tItem = strtolower($attItem['type'] ?? 'document');
                        $tLbl = ($tItem === 'photo') ? 'Foto' : (($tItem === 'video') ? 'Video' : (($tItem === 'audio' || $tItem === 'voice') ? 'Audio' : 'Dokumen'));
                        $nItem = $attItem['name'] ?: basename(parse_url($uItem, PHP_URL_PATH) ?: 'file');
                        $prefixLines[] = "[$tLbl Terlampir: $nItem ($uItem)]";
                    }
                }
                if (!empty($prefixLines)) {
                    $instruction = implode("\n", $prefixLines) . "\n" . $instruction;
                }
            }

            $taskId = '#TASK-' . substr(bin2hex(random_bytes(2)), 0, 4);
            $now = date('Y-m-d H:i:s');
            $ins = $db->prepare("INSERT INTO tasks (id, user_id, project_id, instruction, sender, status, created_at, updated_at, command, priority, attachment_url, attachment_type, attachment_name, attachments_json) VALUES (?, ?, ?, ?, ?, 'PENDING', ?, ?, ?, ?, ?, ?, ?, ?)");
            safeDbExecute($ins, [$taskId, $currentUser['id'], $prjId, $instruction, $sender, $now, $now, $command, $priority, $attUrl, $attType, $attName, $attachmentsJson]);

            // Kaitkan project_media yang diupload dari dropzone ke taskId ini
            foreach ($attachmentsArr as $attItem) {
                if (!empty($attItem['media_id'])) {
                    try {
                        $updM = $db->prepare("UPDATE project_media SET task_id = ?, project_id = CASE WHEN project_id = 0 THEN ? ELSE project_id END WHERE id = ? AND user_id = ?");
                        safeDbExecute($updM, [$taskId, $prjId, (int)$attItem['media_id'], $currentUser['id']]);
                    } catch (\Throwable $eM) {}
                }
            }

            // Kirim Notifikasi ke Telegram Bot Proyek agar user melihat update langsung di Telegram
            if ($prjId > 0) {
                $stmtPrj = $db->prepare("SELECT bot_token, chat_id, name, timezone FROM projects WHERE id = ?");
                $stmtPrj->execute([$prjId]);
                $pData = $stmtPrj->fetch(PDO::FETCH_ASSOC);
                if ($pData && !empty($pData['bot_token']) && !empty($pData['chat_id'])) {
                    $pTz = !empty($pData['timezone']) ? $pData['timezone'] : 'Asia/Jakarta';
                    $tAbbr = getTimezoneAbbr($pTz);
                    $cleanInstDisplay = stripAttachmentHeadersFromInstruction($instruction);
                    $attSection = "";
                    if (!empty($attachmentsArr)) {
                        $attSection = "📎 <b>Lampiran (" . count($attachmentsArr) . " File):</b>\n";
                        foreach ($attachmentsArr as $iA => $aIt) {
                            $nA = htmlspecialchars($aIt['name'] ?: ('File ' . ($iA + 1)));
                            $uA = htmlspecialchars($aIt['url']);
                            $attSection .= "  • <a href=\"$uA\">$nA</a>\n";
                        }
                    }
                    $teleMsg = "📋 <b>TUGAS BARU DARI DASHBOARD WEB</b>\n\n"
                             . "🆔 <b>ID:</b> <code>$taskId</code>\n"
                             . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($pData['name']) . "</code>\n"
                             . "👤 <b>Pengirim:</b> " . htmlspecialchars($sender) . "\n"
                             . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " {$tAbbr}\n"
                             . "📊 <b>Status:</b> <b>⏳ PENDING</b>\n"
                             . $attSection
                             . "📝 <b>Instruksi:</b>\n" . htmlspecialchars($cleanInstDisplay ?: $instruction) . "\n\n"
                             . '🌐 <a href="https://biru.co.id/tele-vs-code/?tab=tasks">Buka Dashboard Status</a>';
                    callTelegram($pData['bot_token'], 'sendMessage', [
                        'chat_id' => $pData['chat_id'],
                        'text' => $teleMsg,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true
                    ]);
                }
            }

            $successMsg = "✅ Tugas <b>$taskId</b> berhasil ditambahkan ke antrean!";
            $currentTab = 'tasks';
        }

        $isAjaxReq = (!empty($_POST['ajax']) || !empty($_GET['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'));
        if ($isAjaxReq) {
            header('Content-Type: application/json');
            if (!empty($errorMsg)) {
                echo json_encode(['ok' => false, 'error' => strip_tags($errorMsg)]);
            } else {
                echo json_encode(['ok' => true, 'id' => $taskId, 'message' => strip_tags($successMsg)]);
            }
            exit;
        }
    }

    if ($action === 'mark_task') {
        $tid = $_GET['id'] ?? ($_POST['id'] ?? '');
        $newStat = $_GET['status'] ?? ($_POST['status'] ?? 'DONE');
        $resultText = trim($_POST['result'] ?? ($_GET['result'] ?? ''));
        $commandText = trim($_POST['command'] ?? ($_GET['command'] ?? ''));
        if (!$resultText && $newStat === 'DONE') $resultText = 'Tugas selesai dikerjakan 100%';
        if (!$resultText && $newStat === 'FAILED') $resultText = 'Pengerjaan tugas mengalami kendala';

        $effectiveTz = !empty($currentUser['timezone']) ? $currentUser['timezone'] : 'Asia/Jakarta';
        applyTimezone($effectiveTz);
        $now = date('Y-m-d H:i:s');

        if ($commandText) {
            $upd = $db->prepare("UPDATE tasks SET status = ?, updated_at = ?, notes = ?, result = ?, command = ? WHERE id = ? AND user_id = ?");
            safeDbExecute($upd, [$newStat, $now, $resultText, $resultText, $commandText, $tid, $currentUser['id']]);
        } else {
            $upd = $db->prepare("UPDATE tasks SET status = ?, updated_at = ?, notes = ?, result = ? WHERE id = ? AND user_id = ?");
            safeDbExecute($upd, [$newStat, $now, $resultText, $resultText, $tid, $currentUser['id']]);
        }
        $successMsg = "✅ Tugas <b>$tid</b> berhasil diperbarui ke status $newStat dan hasil pekerjaan tersimpan!";
        $currentTab = 'tasks';
    }

    if ($action === 'save_task_result' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $tid = trim($_POST['id'] ?? '');
        $resultText = trim($_POST['result'] ?? '');
        $commandText = trim($_POST['command'] ?? '');
        $newStat = trim($_POST['status'] ?? '');

        $effectiveTz = !empty($currentUser['timezone']) ? $currentUser['timezone'] : 'Asia/Jakarta';
        applyTimezone($effectiveTz);
        $now = date('Y-m-d H:i:s');

        if ($newStat) {
            $upd = $db->prepare("UPDATE tasks SET status = ?, result = ?, notes = ?, command = ?, updated_at = ? WHERE id = ? AND user_id = ?");
            safeDbExecute($upd, [$newStat, $resultText, $resultText, $commandText, $now, $tid, $currentUser['id']]);
        } else {
            $upd = $db->prepare("UPDATE tasks SET result = ?, notes = ?, command = ?, updated_at = ? WHERE id = ? AND user_id = ?");
            safeDbExecute($upd, [$resultText, $resultText, $commandText, $now, $tid, $currentUser['id']]);
        }
        $successMsg = "✅ Hasil / Result pekerjaan untuk tugas <b>$tid</b> berhasil disimpan ke database!";
        $currentTab = 'tasks';
    }

    if ($action === 'delete_task') {
        if (!verifyCsrfToken($_GET['csrf'] ?? ($_POST['csrf'] ?? ''))) {
            $errorMsg = 'Permintaan tidak valid (CSRF token salah atau kedaluwarsa).';
        } else {
            $tid = $_GET['id'] ?? ($_POST['id'] ?? '');
            $del = $db->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
            $del->execute([$tid, $currentUser['id']]);
            $successMsg = "✅ Tugas $tid berhasil dihapus dari antrean!";
        }
        $currentTab = 'tasks';
    }



// Tangani respon AJAX otomatis untuk seluruh aksi dashboard agar tidak me-refresh halaman atau re-submit form
if (isAjaxRequest() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => empty($errorMsg),
        'message' => empty($errorMsg) ? ($successMsg ?: 'Perintah berhasil diproses!') : $errorMsg,
        'error' => $errorMsg ?: null,
        'tab' => $currentTab ?? 'tasks',
        'task_id' => $taskId ?? null
    ]);
    exit;
}

// PRG (Post-Redirect-Get) Pattern untuk request standar non-AJAX
// Mencegah konfirmasi resubmission form saat user menekan refresh (F5) di browser
if (!isAjaxRequest() && $_SERVER['REQUEST_METHOD'] === 'POST' && (!empty($successMsg) || !empty($errorMsg))) {
    $redirTab = $currentTab ?? 'tasks';
    if (!empty($errorMsg)) {
        $_SESSION['tvsc_flash_error'] = $errorMsg;
    }
    if (!empty($successMsg)) {
        $_SESSION['tvsc_flash_success'] = $successMsg;
    }
    header("Location: index.php?tab=" . urlencode($redirTab));
    exit;
}
