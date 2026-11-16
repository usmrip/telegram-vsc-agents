<?php
// includes/webhook.php - Telegram Webhook Multi-Tenant Handler
if (!defined('TELE_VS_CODE')) exit;

if ($action === 'webhook') {
    try {
    $pKey = $_GET['pkey'] ?? ($_GET['project_key'] ?? ($_GET['project'] ?? ($_GET['p'] ?? '')));
    $apiKey = $_GET['secret'] ?? ($_POST['secret'] ?? ($_GET['key'] ?? ($_POST['key'] ?? '')));

    $project = null;
    $allProjects = [];
    if ($pKey) {
        $stmtP = $db->prepare("SELECT p.*, u.email, u.api_key, u.is_blocked, u.block_notify, u.timezone as user_timezone FROM projects p JOIN users u ON p.user_id = u.id WHERE (p.project_key = ? OR LOWER(p.code) = ?)");
        $stmtP->execute([$pKey, strtolower($pKey)]);
        $project = $stmtP->fetch(PDO::FETCH_ASSOC);
        $stmtP->closeCursor();
        if ($project) {
            if (!empty($project['bot_token'])) {
                $stmtAll = $db->prepare("SELECT p.*, u.email, u.api_key, u.is_blocked, u.block_notify, u.timezone as user_timezone FROM projects p JOIN users u ON p.user_id = u.id WHERE (p.user_id = ? OR p.bot_token = ?) ORDER BY p.id ASC");
                $stmtAll->execute([$project['user_id'], $project['bot_token']]);
            } else {
                $stmtAll = $db->prepare("SELECT p.*, u.email, u.api_key, u.is_blocked, u.block_notify, u.timezone as user_timezone FROM projects p JOIN users u ON p.user_id = u.id WHERE p.user_id = ? ORDER BY p.id ASC");
                $stmtAll->execute([$project['user_id']]);
            }
            $allProjects = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif ($apiKey) {
        $stmtU = $db->prepare("SELECT * FROM users WHERE api_key = ?");
        $stmtU->execute([$apiKey]);
        $user = $stmtU->fetch(PDO::FETCH_ASSOC);
        $stmtU->closeCursor();
        if ($user) {
            $stmtAll = $db->prepare("SELECT p.*, u.email, u.api_key, u.is_blocked, u.block_notify, u.timezone as user_timezone FROM projects p JOIN users u ON p.user_id = u.id WHERE p.user_id = ? ORDER BY p.id ASC");
            $stmtAll->execute([$user['id']]);
            $allProjects = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
            $project = $allProjects[0] ?? null;
        }
    }

    // Fallback: Jika tidak ada pKey atau apiKey di URL webhook (ambil project pertama yang punya bot_token)
    if (!$project) {
        $stmtFallback = $db->query("SELECT p.*, u.email, u.api_key, u.is_blocked, u.block_notify, u.timezone as user_timezone FROM projects p JOIN users u ON p.user_id = u.id WHERE p.bot_token != '' ORDER BY p.id ASC LIMIT 1");
        $project = $stmtFallback->fetch(PDO::FETCH_ASSOC);
        if ($project) {
            $stmtAll = $db->prepare("SELECT p.*, u.email, u.api_key, u.is_blocked, u.block_notify, u.timezone as user_timezone FROM projects p JOIN users u ON p.user_id = u.id WHERE (p.user_id = ? OR p.bot_token = ?) ORDER BY p.id ASC");
            $stmtAll->execute([$project['user_id'], $project['bot_token']]);
            $allProjects = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    if (!$project) { echo "OK"; exit; }

    // Jika Akun Pengguna Diblokir oleh Superadmin, abaikan webhook
    if (!empty($project['is_blocked'])) {
        echo "OK"; exit;
    }

    // Set Timezone Acuan Proyek
    $effectiveTz = !empty($project['timezone']) ? $project['timezone'] : (!empty($project['user_timezone']) ? $project['user_timezone'] : 'Asia/Jakarta');
    applyTimezone($effectiveTz);
    $tzAbbr = getTimezoneAbbr($effectiveTz);

    $raw = file_get_contents('php://input');
    $update = json_decode($raw, true);
    if (!$update || (!isset($update['message']) && !isset($update['callback_query']))) { echo "OK"; exit; }

    $isCallback = isset($update['callback_query']);
    $callbackId = $isCallback ? ($update['callback_query']['id'] ?? '') : '';
    $callbackData = $isCallback ? trim($update['callback_query']['data'] ?? '') : '';

    $msg = $isCallback ? ($update['callback_query']['message'] ?? []) : $update['message'];
    $incomingChatId = (string)($msg['chat']['id'] ?? '');
    $chatType = $msg['chat']['type'] ?? 'private';
    $senderObj = $isCallback ? ($update['callback_query']['from'] ?? []) : ($msg['from'] ?? []);
    $sender = !empty($senderObj['username']) ? '@' . $senderObj['username'] : ($senderObj['first_name'] ?? 'User');
    $senderUserId = (string)($senderObj['id'] ?? '');
    $senderUsername = strtolower(ltrim($senderObj['username'] ?? '', '@'));
    $messageId = $msg['message_id'] ?? 0;
    $text = $isCallback ? '' : trim($msg['text'] ?? ($msg['caption'] ?? ''));

    // Deteksi Lampiran Media (Foto, Dokumen, Video, Audio, Voice) & Media Group (Album Multi-File)
    $attachmentUrl = '';
    $attachmentType = '';
    $attachmentName = '';
    $attachmentFileId = null;
    $mediaGroupId = isset($msg['media_group_id']) ? trim((string)$msg['media_group_id']) : '';

    if (!empty($msg['photo']) && is_array($msg['photo'])) {
        $attachmentType = 'photo';
        $bestPhoto = end($msg['photo']);
        $attachmentFileId = $bestPhoto['file_id'] ?? null;
        $attachmentName = 'photo_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(2)), 0, 4) . '.jpg';
    } elseif (!empty($msg['voice']) && is_array($msg['voice'])) {
        $attachmentType = 'voice';
        $attachmentFileId = $msg['voice']['file_id'] ?? null;
        $attachmentName = 'voice_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(2)), 0, 4) . '.ogg';
    } elseif (!empty($msg['audio']) && is_array($msg['audio'])) {
        $attachmentType = 'audio';
        $attachmentFileId = $msg['audio']['file_id'] ?? null;
        $origName = !empty($msg['audio']['file_name']) ? basename($msg['audio']['file_name']) : 'audio_' . date('Ymd_His') . '.mp3';
        $attachmentName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $origName);
    } elseif (!empty($msg['document']) && is_array($msg['document'])) {
        $attachmentType = 'document';
        $attachmentFileId = $msg['document']['file_id'] ?? null;
        $origName = !empty($msg['document']['file_name']) ? basename($msg['document']['file_name']) : 'document_' . date('Ymd_His') . '.bin';
        $attachmentName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $origName);
    } elseif (!empty($msg['video']) && is_array($msg['video'])) {
        $attachmentType = 'video';
        $attachmentFileId = $msg['video']['file_id'] ?? null;
        $origName = !empty($msg['video']['file_name']) ? basename($msg['video']['file_name']) : 'video_' . date('Ymd_His') . '.mp4';
        $attachmentName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $origName);
    }

    // Helper untuk mengunduh file lampiran Telegram ke folder uploads/<taskId>/ dengan proteksi keamanan penuh
    $downloadTelegramAttachment = function($botToken, $fileId, $rawName, $targetTaskId) {
        if (!$fileId || !$botToken || !$targetTaskId) {
            return ['', $rawName];
        }
        $fileInfo = callTelegram($botToken, 'getFile', ['file_id' => $fileId]);
        if (empty($fileInfo['ok']) || empty($fileInfo['result']['file_path'])) {
            return ['', $rawName];
        }
        $remotePath = str_replace(['..', '\\'], '', $fileInfo['result']['file_path']);
        $downloadSrc = "https://api.telegram.org/file/bot" . $botToken . "/" . $remotePath;

        $mainUploadDir = dirname(__DIR__) . '/uploads';
        $uploadDir = $mainUploadDir . '/' . $targetTaskId;
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }
        if (!file_exists($mainUploadDir . '/.htaccess')) {
            $ht = "<FilesMatch \"(?i)\\.(php|phtml|phar|phps|inc|shtml|cgi|pl|py|sh|bat|cmd|exe|htaccess)$\">\n"
                . "    <IfModule mod_authz_core.c>\n"
                . "        Require all denied\n"
                . "    </IfModule>\n"
                . "    <IfModule !mod_authz_core.c>\n"
                . "        Order Deny,Allow\n"
                . "        Deny from all\n"
                . "    </IfModule>\n"
                . "</FilesMatch>\n"
                . "<IfModule mod_headers.c>\n"
                . "    Header set X-Content-Type-Options \"nosniff\"\n"
                . "</IfModule>\n";
            @file_put_contents($mainUploadDir . '/.htaccess', $ht);
        }

        $safeName = basename(str_replace(['../', '..\\', chr(0)], '', $rawName));
        $safeName = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $safeName);
        $safeName = preg_replace('/\.(php[0-9]*|phtml|phar|phps|inc|shtml|cgi|pl|py|sh|bash|bat|cmd|ps1|exe|asp|aspx|jsp|env)\./i', '_$1.', $safeName);

        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        $executableExts = [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'inc',
            'shtml', 'cgi', 'pl', 'py', 'sh', 'bash', 'zsh', 'bat', 'cmd', 'ps1', 'psm1',
            'exe', 'com', 'scr', 'msi', 'vbs', 'vbe', 'wsf', 'wsh', 'htaccess', 'htpasswd',
            'ini', 'config', 'conf', 'asp', 'aspx', 'jsp', 'jspx', 'action', 'do', 'dll', 'so',
            'env', 'svgz'
        ];
        if (in_array($ext, $executableExts) || empty($ext)) {
            $safeName .= '.txt';
        }

        // Hindari tertimpanya nama file jika ada nama identik di tugas yang sama
        if (file_exists($uploadDir . '/' . $safeName)) {
            $pi = pathinfo($safeName);
            $safeName = ($pi['filename'] ?? 'file') . '_' . substr(bin2hex(random_bytes(2)), 0, 4) . (!empty($pi['extension']) ? '.' . $pi['extension'] : '');
        }

        $targetFile = $uploadDir . '/' . $safeName;
        $fileData = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($downloadSrc);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $fileData = curl_exec($ch);
            curl_close($ch);
        }
        if ($fileData === false || $fileData === null) {
            $ctx = stream_context_create(['http' => ['timeout' => 15]]);
            $fileData = @file_get_contents($downloadSrc, false, $ctx);
        }
        if ($fileData !== false && strlen($fileData) > 0) {
            if (preg_match('/<\?(php|=|\s)/i', $fileData) || stripos($fileData, '__halt_compiler') !== false) {
                if (!str_ends_with(strtolower($safeName), '.txt')) {
                    $safeName .= '.txt';
                    $targetFile = $uploadDir . '/' . $safeName;
                }
            }
            file_put_contents($targetFile, $fileData);
            $dlUrl = "https://biru.co.id/tele-vs-code/uploads/" . rawurlencode($targetTaskId) . "/" . rawurlencode($safeName);
            return [$dlUrl, $safeName];
        }
        return ['', $safeName];
    };

    // Command Khusus Wizard: /verifikasi atau /verify (Dapat dieksekusi sebelum whitelist agar proses setup mulus)
    if (preg_match('/^\/(verifikasi|verify)(@\w+)?/i', $text)) {
        $botUsername = '';
        $botMe = callTelegram($project['bot_token'], 'getMe');
        if (!empty($botMe['result']['username'])) {
            $botUsername = '@' . $botMe['result']['username'];
        } elseif (!empty($project['bot_username'])) {
            $botUsername = '@' . $project['bot_username'];
        } else {
            $botUsername = 'Bot Anda';
        }

        $nowTime = date('d-m-Y H:i:s') . ' ' . $tzAbbr;
        $nowTs = time();

        try {
            // Update status verifikasi proyek
            $db->prepare("UPDATE projects SET bot_verified_at = CURRENT_TIMESTAMP, bot_username = ?, chat_id = ? WHERE id = ?")
               ->execute([ltrim($botUsername, '@'), $incomingChatId, $project['id']]);
            setSetting('bot_verify_ping_' . $project['id'], (string)$nowTs);

            // Daftarkan otomatis user pengirim ke whitelist proyek ini
            $chkWhite = $db->prepare("SELECT id FROM bot_whitelist WHERE user_id = ? AND telegram_user_id = ?");
            $chkWhite->execute([$project['user_id'], $senderUserId]);
            if (!$chkWhite->fetch()) {
                $db->prepare("INSERT INTO bot_whitelist (user_id, project_id, telegram_user_id, telegram_username, name) VALUES (?, ?, ?, ?, ?)")
                   ->execute([$project['user_id'], $project['id'], $senderUserId, $senderUsername, $sender]);
            }
        } catch (Exception $e) {}

        // Sesuai aturan user: "jika sudah verifikasi, balas ditelegram Bot Anda <nama_BOT> sudah terverifikasi dan terhubungsejak"
        $reply = "🎉 <b>Verifikasi Berhasil!</b>\n\n"
               . "Bot Anda <b>{$botUsername}</b> sudah terverifikasi dan terhubung sejak <b>{$nowTime}</b>.\n\n"
               . "✅ <b>Status:</b> Terverifikasi & Terhubung\n"
               . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($project['name']) . "</code> (#" . ($project['code'] ?: 'a') . ")\n"
               . "💬 <b>Chat ID:</b> <code>$incomingChatId</code>\n"
               . "👤 <b>Whitelist:</b> Terdaftar (Akses Penuh Aktif)\n\n"
               . "🚀 <b>Bot Siap Digunakan!</b> Anda sekarang dapat langsung mengirimkan instruksi coding, teks biasa, foto, dokumen, atau video ke AI Agent di VS Code.";

        callTelegram($project['bot_token'], 'sendMessage', [
            'chat_id' => $incomingChatId,
            'text' => $reply,
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $messageId
        ]);
        echo "OK"; exit;
    }

    // 1. Pemeriksaan Hak Akses Whitelist Pengguna & Filter Proyek yang Diizinkan (Strict Per-Project Whitelist)
    $stmtW = $db->prepare("SELECT * FROM bot_whitelist WHERE (telegram_user_id = ? OR (telegram_username != '' AND LOWER(telegram_username) = ?))");
    $stmtW->execute([$senderUserId, $senderUsername]);
    $myWhitelists = $stmtW->fetchAll(PDO::FETCH_ASSOC);
    $stmtW->closeCursor();

    // Filter daftar proyek: HANYA proyek yang benar-benar di-whitelist untuk pengirim ini
    $accessibleProjects = [];
    foreach ($allProjects as $p) {
        foreach ($myWhitelists as $w) {
            // Cocok jika: user pemilik proyek sama, DAN (berlaku untuk semua proyek akun / project_id = 0 ATAU target project_id sama persis)
            if ((int)$w['user_id'] === (int)$p['user_id'] && ((int)$w['project_id'] === 0 || (int)$w['project_id'] === (int)$p['id'])) {
                $accessibleProjects[] = $p;
                break;
            }
        }
    }

    // JIKA USER BELUM DI-WHITELIST UNTUK PROYEK APAPUN:
    // Jangan respon perintah apapun termasuk list projects (/projects), /whoami, /use, /status, /task, dsb.
    // HANYA perintah /id atau /myid yang direspons agar user dapat mengetahui User ID untuk didaftarkan ke whitelist.
    // Handler perintah publik /id, /myid, atau /start (untuk pengguna belum whitelist)
    $isIdCmd = (bool)preg_match('/^\/(id|myid|start)(?:@\w+)?(?:\s.*)?$/i', $text);
    if (empty($accessibleProjects)) {
        if ($isIdCmd) {
            $botId = explode(':', $project['bot_token'])[0] ?? '';
            $reply = "🆔 <b>INFORMASI IDENTITAS TELEGRAM & BOT</b>\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                   . "👤 <b>Identitas Anda:</b>\n"
                   . "• <b>User ID Anda:</b> <code>$senderUserId</code> <i>(Gunakan nomor ini untuk Whitelist)</i>\n"
                   . "• <b>Username:</b> " . ($senderUsername ? "@" . htmlspecialchars($senderUsername) : "<i>(Belum disetel)</i>") . "\n"
                   . "• <b>Nama:</b> " . htmlspecialchars($sender) . "\n"
                   . "• <b>Chat ID:</b> <code>$incomingChatId</code> (" . htmlspecialchars($chatType) . ")\n"
                   . "• <b>Status Whitelist:</b> ⚪ <b>Belum Terdaftar</b>\n\n"
                   . "🤖 <b>Identitas Bot & Proyek:</b>\n"
                   . "• <b>Bot ID:</b> <code>$botId</code>\n"
                   . "• <b>Target Proyek:</b> <code>" . htmlspecialchars($project['name']) . "</code> (#" . htmlspecialchars($project['code'] ?: 'a') . ")\n"
                   . "• <b>Waktu Server:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "🚀 <b>PANDUAN PEMASANGAN & CARA PENGGUNAAN:</b>\n\n"
                   . "<b>1. Daftarkan User ID ke Whitelist</b>\n"
                   . "• Buka dashboard: <a href=\"https://biru.co.id/tele-vs-code/\">biru.co.id/tele-vs-code/</a>\n"
                   . "• Buka tab <b>Proyek</b> &rarr; scroll ke bagian <b>Whitelist Pengguna Private Chat</b>\n"
                   . "• Klik <b>Tambah User Whitelist</b> & masukkan User ID Anda: <code>$senderUserId</code>\n\n"
                   . "<b>2. Pasang Ekstensi di Visual Studio Code</b>\n"
                   . "• Buka VS Code &rarr; tekan <code>Ctrl+Shift+X</code> (menu Extensions)\n"
                   . "• Cari <b>Tele-vs-Code</b> oleh <i>Biru Indonesia Creative</i> &rarr; klik <b>Install</b>\n"
                   . "• <i>Marketplace: <a href=\"https://marketplace.visualstudio.com/items?itemName=BiruIndonesiaCreative.tele-vs-code\">Link Tele-vs-Code</a></i>\n\n"
                   . "<b>3. Hubungkan ke Server Biru di VS Code</b>\n"
                   . "• Di VS Code, klik status bar <b>Tele-vs-Code</b> di pojok kanan bawah (atau tekan <code>F1</code> &rarr; pilih <b>Tele-vs-Code: Hubungkan ke Server Biru</b>)\n"
                   . "• Masukkan <b>API Secret Key</b> akun Anda & pilih proyek Anda\n\n"
                   . "<b>4. Kirim Tugas & AI Otomatis Bekerja</b>\n"
                   . "Ketik perintah apapun di chat ini (misal: <code>buat file auth.js</code> atau <code>refactor navbar</code>).\n"
                   . "AI Agent di VS Code akan langsung mengeksekusi antrean tugas di <code>TASK_QUEUE.md</code> sampai selesai 100% dan membalas laporannya ke sini!\n\n"
                   . "⚠️ <i>Catatan: Daftarkan User ID <code>$senderUserId</code> ke whitelist di dashboard agar bot dapat mulai mengeksekusi instruksi Anda.</i>";

            callTelegram($project['bot_token'], 'sendMessage', [
                'chat_id' => $incomingChatId,
                'text' => $reply,
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $messageId
            ]);
        }
        // JANGAN RESPON perintah lainnya sama sekali (silent ignore)
        echo "OK"; exit;
    }

    // Batasi cakupan seluruh proyek HANYA pada proyek yang diizinkan untuk pengirim ini
    $allProjects = $accessibleProjects;
    $whitelistedUser = $myWhitelists[0] ?? null;
    $isPrivateChat = ($chatType === 'private');

    // Ambil sesi chat untuk proyek aktif (/use <kode>) dan default agent
    $activeProjId = 0;
    $chatDefaultAgent = '';
    if ($incomingChatId) {
        try {
            if (!empty($senderUserId)) {
                $stmtSes = $db->prepare("SELECT active_project_id, default_agent FROM chat_sessions WHERE chat_id = ?");
                $stmtSes->execute([$incomingChatId . ':' . $senderUserId]);
                $rowSes = $stmtSes->fetch(PDO::FETCH_ASSOC);
                if ($rowSes) {
                    $activeProjId = (int)($rowSes['active_project_id'] ?? 0);
                    $chatDefaultAgent = trim($rowSes['default_agent'] ?? '');
                }
                $stmtSes->closeCursor();
            }
            if ($activeProjId <= 0) {
                $stmtSes = $db->prepare("SELECT active_project_id, default_agent FROM chat_sessions WHERE chat_id = ?");
                $stmtSes->execute([$incomingChatId]);
                $rowSes = $stmtSes->fetch(PDO::FETCH_ASSOC);
                if ($rowSes) {
                    if ($activeProjId <= 0) $activeProjId = (int)($rowSes['active_project_id'] ?? 0);
                    if (!$chatDefaultAgent) $chatDefaultAgent = trim($rowSes['default_agent'] ?? '');
                }
                $stmtSes->closeCursor();
            }
        } catch (Exception $e) {
            try {
                $stmtSes = $db->prepare("SELECT active_project_id FROM chat_sessions WHERE chat_id = ?");
                $stmtSes->execute([$incomingChatId . (!empty($senderUserId) ? ':' . $senderUserId : '')]);
                $activeProjId = (int)$stmtSes->fetchColumn();
                $stmtSes->closeCursor();
            } catch (Exception $e2) {}
        }
    }

    // Proyek aktif: default ke proyek pertama yang diizinkan untuk user ini
    $activeProject = $allProjects[0];
    if ($incomingChatId) {
        foreach ($allProjects as $ap) {
            if (!empty($ap['chat_id']) && (string)$ap['chat_id'] === $incomingChatId) {
                $activeProject = $ap;
                break;
            }
        }
    }
    if ($activeProjId > 0) {
        foreach ($allProjects as $ap) {
            if ((int)$ap['id'] === $activeProjId) {
                $activeProject = $ap;
                break;
            }
        }
    }

    // Tentukan agent aktif / default agent saat ini
    $currentAgent = !empty($chatDefaultAgent) ? $chatDefaultAgent : (!empty($activeProject['default_agent']) ? $activeProject['default_agent'] : 'Antigravity AI Agent');

    // =========================================================================
    // Handler Callback Query (Klik Tombol Menu Inline Telegram)
    // =========================================================================
    if (!empty($isCallback) && !empty($callbackData)) {
        $cbData = $callbackData;
        $cbId = $callbackId;
        $pId = (int)$activeProject['id'];
        $pCode = !empty($activeProject['code']) ? $activeProject['code'] : 'a';

        if (strpos($cbData, 'cb:exec_') === 0) {
            $actionPart = substr($cbData, 8); // 'vscode', 'shell', 'auto', 'guide'

            if ($actionPart === 'guide') {
                callTelegram($project['bot_token'], 'answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => '📖 Membuka Panduan Setup Multi-OS...'
                ]);

                $guideReply = "📖 <b>PANDUAN SETUP RUNNER SHELL CLI (MULTI-OS)</b>\n"
                            . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                            . "Aplikasi ini dapat berjalan di background terminal/server OS apapun tanpa GUI VS Code:\n\n"
                            . "🐧 <b>1. Linux Server / VPS (Ubuntu, Debian, Rocky, Alma, CentOS):</b>\n"
                            . "<code>cd /path/ke/proyek</code>\n"
                            . "<code>nohup node .vscode/tele-vs-code/tele-worker.js > tele-worker.log 2>&1 &</code>\n"
                            . "<i>Atau jalankan shell script:</i>\n"
                            . "<code>chmod +x .vscode/tele-vs-code/tele-worker.sh && ./.vscode/tele-vs-code/tele-worker.sh</code>\n"
                            . "<i>Atau via PM2 (Rekomendasi 24/7):</i>\n"
                            . "<code>pm2 start .vscode/tele-vs-code/tele-worker.js --name tele-worker</code>\n\n"
                            . "🍎 <b>2. macOS (Terminal / Zsh):</b>\n"
                            . "<code>./.vscode/tele-vs-code/tele-worker.sh</code>\n\n"
                            . "🪟 <b>3. Windows (PowerShell / Command Prompt):</b>\n"
                            . "<code>node .vscode/tele-vs-code/tele-worker.js</code>\n"
                            . "<i>Atau via CMD:</i> <code>.\\.vscode\\tele-vs-code\\tele-worker.cmd</code>\n\n"
                            . "━━━━━━━━━━━━━━━━━━━━━━\n"
                            . "💡 <b>Kelebihan Mode Shell:</b>\n"
                            . "• Bebas biaya polling: <b>0 Token / Rp 0</b>\n"
                            . "• Berjalan 24/7 di server headless / VPS tanpa GUI\n"
                            . "• Mendukung CLI Agents (Antigravity, Claude, Gemini, Cursor)\n\n"
                            . "<i>Gunakan perintah <code>/executor vscode</code> untuk beralih kembali ke VS Code kapan saja.</i>";

                callTelegram($project['bot_token'], 'sendMessage', [
                    'chat_id' => $incomingChatId,
                    'text' => $guideReply,
                    'parse_mode' => 'HTML'
                ]);
                echo "OK"; exit;
            } elseif (in_array($actionPart, ['vscode', 'shell', 'auto'])) {
                $newMode = $actionPart;
                $db->prepare("UPDATE projects SET executor_mode = ? WHERE id = ?")->execute([$newMode, $pId]);
                $activeProject['executor_mode'] = $newMode;

                $modeTitle = ($newMode === 'shell') ? '⚡ Shell CLI OS' : (($newMode === 'auto') ? '🔄 Auto Detect' : '💻 VS Code');

                callTelegram($project['bot_token'], 'answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text' => "✅ Eksekutor dialihkan ke: $modeTitle"
                ]);

                $inlineKb = [
                    'inline_keyboard' => [
                        [
                            ['text' => ($newMode === 'vscode' ? '✅ 💻 VS Code' : '💻 VS Code'), 'callback_data' => 'cb:exec_vscode'],
                            ['text' => ($newMode === 'shell' ? '✅ ⚡ Shell OS' : '⚡ Shell OS'), 'callback_data' => 'cb:exec_shell'],
                            ['text' => ($newMode === 'auto' ? '✅ 🔄 Auto' : '🔄 Auto'), 'callback_data' => 'cb:exec_auto']
                        ],
                        [
                            ['text' => '📖 Petunjuk Setup Linux / Mac / Windows', 'callback_data' => 'cb:exec_guide']
                        ]
                    ]
                ];

                $updatedReply = "⚙️ <b>STATUS MESIN EKSEKUTOR DIPERBARUI!</b>\n"
                              . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                              . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($activeProject['name']) . "</code> (<code>#$pCode</code>)\n"
                              . "🎯 <b>Mode Aktif Sekarang:</b> <b>$modeTitle</b>\n"
                              . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n\n";

                if ($newMode === 'shell') {
                    $updatedReply .= "👉 <b>Aktivasi di Terminal Shell OS (Linux / Mac / Windows):</b>\n"
                                   . "Jalankan worker di terminal folder proyek Anda:\n"
                                   . "• Linux / macOS: <code>nohup node .vscode/tele-vs-code/tele-worker.js > tele-worker.log 2>&1 &</code>\n"
                                   . "• Windows: <code>node .vscode/tele-vs-code/tele-worker.js</code>\n\n"
                                   . "<i>Worker akan otomatis memantau antrean tugas dan mengeksekusi instruksi dari Telegram tanpa GUI VS Code.</i>";
                } elseif ($newMode === 'vscode') {
                    $updatedReply .= "👉 <b>Aktivasi di VS Code:</b>\n"
                                   . "Buka folder proyek di Visual Studio Code (<code>code .</code>) dan pastikan status bar <b>Tele-vs-Code</b> aktif terhubung.\n\n"
                                   . "<i>Tugas akan otomatis dialirkan ke Chat IDE & AI Agent di VS Code.</i>";
                } else {
                    $updatedReply .= "👉 <b>Mode Auto:</b>\n"
                                   . "Sistem otomatis memilih VS Code jika editor aktif, dan otomatis fallback ke background Shell worker jika editor offline.";
                }

                callTelegram($project['bot_token'], 'sendMessage', [
                    'chat_id' => $incomingChatId,
                    'text' => $updatedReply,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode($inlineKb)
                ]);
                echo "OK"; exit;
            }
        }
        echo "OK"; exit;
    }

    // Command /id & /myid - Jika sudah whitelisted
    if ($text === '/id' || $text === '/myid') {
        $botId = explode(':', $project['bot_token'])[0] ?? '';
        $reply = "🆔 <b>INFORMASI IDENTITAS TELEGRAM & BOT</b>\n"
               . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
               . "👤 <b>Identitas Anda:</b>\n"
               . "• <b>User ID Anda:</b> <code>$senderUserId</code> (🟢 <b>Terdaftar di Whitelist</b>)\n"
               . "• <b>Username:</b> " . ($senderUsername ? "@" . htmlspecialchars($senderUsername) : "<i>(Belum disetel)</i>") . "\n"
               . "• <b>Nama:</b> " . htmlspecialchars($sender) . "\n"
               . "• <b>Chat ID:</b> <code>$incomingChatId</code> (" . htmlspecialchars($chatType) . ")\n\n"
               . "🤖 <b>Identitas Bot & Proyek:</b>\n"
               . "• <b>Bot ID:</b> <code>$botId</code>\n"
               . "• <b>Proyek Aktif:</b> <code>" . htmlspecialchars($activeProject['name']) . "</code> (#" . ($activeProject['code'] ?: $activeProject['id']) . ")\n"
               . "• <b>Project Key:</b> <code>" . htmlspecialchars($activeProject['project_key']) . "</code>\n"
               . "• <b>Waktu Server:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n\n"
               . "━━━━━━━━━━━━━━━━━━━━━━\n"
               . "🚀 <b>PANDUAN PEMASANGAN & PENGGUNAAN:</b>\n\n"
               . "<b>1. Pasang Ekstensi di VS Code</b>\n"
               . "Cari <b>Tele-vs-Code</b> oleh <i>Biru Indonesia Creative</i> di menu Extensions VS Code (<code>Ctrl+Shift+X</code>) atau unduh via <a href=\"https://marketplace.visualstudio.com/items?itemName=BiruIndonesiaCreative.tele-vs-code\">VS Code Marketplace</a>.\n\n"
               . "<b>2. Hubungkan Workspace ke Server Biru</b>\n"
               . "Klik status bar <b>Tele-vs-Code</b> di pojok kanan bawah VS Code (atau <code>F1</code> &rarr; <i>Tele-vs-Code: Hubungkan ke Server Biru</i>), lalu masukkan API Secret Key akun Anda.\n\n"
               . "<b>3. Kirim Tugas ke AI Agent</b>\n"
               . "Ketik perintah di chat ini untuk membuat antrean tugas yang otomatis dikerjakan oleh AI Agent VS Code!";

        callTelegram($project['bot_token'], 'sendMessage', [
            'chat_id' => $incomingChatId,
            'text' => $reply,
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $messageId
        ]);
        echo "OK"; exit;
    }

        // DETEKSI REPLY KE PESAN TUGAS ATAU CAPTION #TASK-XXXX (Membatalkan, Merevisi, atau Menambahkan Lampiran File ke Tugas)
    $repliedTaskId = null;
    if (!empty($msg['reply_to_message'])) {
        $repText = $msg['reply_to_message']['text'] ?? ($msg['reply_to_message']['caption'] ?? '');
        if (preg_match('/(#?TASK-[A-Za-z0-9_-]+)/i', $repText, $mRep)) {
            $repliedTaskId = strtoupper($mRep[1]);
            if ($repliedTaskId[0] !== '#') $repliedTaskId = '#' . $repliedTaskId;
        }
    }
    if (!$repliedTaskId && $attachmentType && preg_match('/^(?:\/attach(?:@\w+)?\s+)?(#?TASK-[A-Za-z0-9_-]+)(?:\s+(.*))?$/is', $text, $mDirectAttach)) {
        $candTaskId = strtoupper($mDirectAttach[1]);
        if ($candTaskId[0] !== '#') $candTaskId = '#' . $candTaskId;
        $repliedTaskId = $candTaskId;
        $text = trim($mDirectAttach[2] ?? '');
    }

    if ($repliedTaskId) {
        $stmtRepTask = $db->prepare("SELECT t.*, p.name as project_name, p.code as project_code, p.bot_token, p.chat_id, p.timezone as project_timezone FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE UPPER(t.id) = UPPER(?)");
        $stmtRepTask->execute([$repliedTaskId]);
        $repTask = $stmtRepTask->fetch(PDO::FETCH_ASSOC);
        $stmtRepTask->closeCursor();

        if ($repTask) {
            $repliedTaskId = $repTask['id'];
            $repTz = !empty($repTask['project_timezone']) ? $repTask['project_timezone'] : $effectiveTz;
            $repTzAbbr = getTimezoneAbbr($repTz);
            $pName = htmlspecialchars($repTask['project_name'] ?: 'Tele-vs-Code');

            // 1. Aksi Pembatalan Tugas
            $cleanRevCmd = trim(preg_replace('/^\/(?:[A-Za-z0-9_-]+)(?:@\w+)?\s*/i', '', $text));
            $isCancelReply = !$attachmentType && (
                preg_match('/^(?:\/cancel|\/batal|batal|batalkan|cancel|stop|hapus)(?:\s|$)/i', $text)
                || preg_match('/^(?:\/cancel|\/batal|batal|batalkan|cancel|stop|hapus)(?:\s|$)/i', $cleanRevCmd)
                || stripos($text, 'batalkan task') !== false
                || stripos($text, 'batal task') !== false
                || stripos($text, 'cancel task') !== false
                || stripos($text, 'batalkan') !== false
            );

            if ($isCancelReply) {
                if ($repTask['status'] === 'DONE') {
                    $reply = "⚠️ <b>TUGAS SUDAH SELESAI</b>\n\n"
                           . "Tugas <code>$repliedTaskId</code> sudah tuntas 100% sebelumnya dan tidak dapat dibatalkan.";
                } else {
                    $now = date('Y-m-d H:i:s');
                    $cancelNote = "Dibatalkan oleh pemohon ($sender) via Telegram Reply";
                    $upd = $db->prepare("UPDATE tasks SET status = 'FAILED', notes = ?, result = ?, updated_at = ? WHERE id = ?");
                    $upd->execute([$cancelNote, $cancelNote, $now, $repliedTaskId]);

                    $reply = "🚫 <b>TUGAS BERHASIL DIBATALKAN!</b>\n"
                           . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                           . "🆔 <b>ID Tugas:</b> <code>$repliedTaskId</code>\n"
                           . "📁 <b>Proyek:</b> <code>$pName</code>\n"
                           . "👤 <b>Pemohon:</b> " . htmlspecialchars($sender) . "\n"
                           . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $repTzAbbr\n"
                           . "📌 <b>Status:</b> <code>[FAILED / DIBATALKAN]</code>\n\n"
                           . "<i>Tugas telah dihentikan dan dikeluarkan dari antrean eksekusi AI Agent.</i>";
                }

                callTelegram($project['bot_token'], 'sendMessage', [
                    'chat_id' => $incomingChatId,
                    'text' => $reply,
                    'parse_mode' => 'HTML',
                    'reply_to_message_id' => $messageId
                ]);
                echo "OK"; exit;
            }

            // 2. Aksi Revisi Instruksi dan/atau Penambahan File Lampiran ke Tugas
            if (in_array($repTask['status'], ['PENDING', 'IN_PROGRESS', 'WAITING']) || $attachmentType) {
                $cleanRev = trim(preg_replace('/^\/(?:task|attach)(@\w+)?\s*/i', '', $text));
                if ($cleanRev !== '' || $attachmentType) {
                    // Gunakan file lock per task agar jika user me-reply dengan album (banyak file sekaligus), tidak terjadi race condition
                    $lockPath = sys_get_temp_dir() . '/tvsc_task_' . md5($repliedTaskId) . '.lock';
                    $lockFp = @fopen($lockPath, 'c+');
                    if ($lockFp) {
                        @flock($lockFp, LOCK_EX);
                    }

                    // Ambil ulang data terbaru setelah lock diperoleh
                    $stmtFresh = $db->prepare("SELECT * FROM tasks WHERE id = ?");
                    $stmtFresh->execute([$repliedTaskId]);
                    $freshTask = $stmtFresh->fetch(PDO::FETCH_ASSOC) ?: $repTask;
                    $stmtFresh->closeCursor();

                    $now = date('Y-m-d H:i:s');
                    $updatedInstruction = $freshTask['instruction'];
                    $existingAttachments = parseTaskAttachments($freshTask);

                    $addedFileLine = '';
                    if ($attachmentType && $attachmentFileId) {
                        $botTok = !empty($repTask['bot_token']) ? $repTask['bot_token'] : $project['bot_token'];
                        list($dlUrl, $dlName) = $downloadTelegramAttachment($botTok, $attachmentFileId, $attachmentName, $repliedTaskId);
                        $attachmentUrl = $dlUrl;
                        $attachmentName = $dlName;

                        $typeLabel = ($attachmentType === 'photo') ? 'Foto' : (($attachmentType === 'video') ? 'Video' : (($attachmentType === 'audio' || $attachmentType === 'voice') ? 'Audio' : 'Dokumen'));
                        $attachmentHeader = "[$typeLabel Terlampir: $attachmentName" . ($attachmentUrl ? " ($attachmentUrl)" : "") . "]";
                        $updatedInstruction = $attachmentHeader . "\n" . $updatedInstruction;

                        if ($attachmentUrl) {
                            $existingAttachments[] = [
                                'url' => $attachmentUrl,
                                'type' => $attachmentType,
                                'name' => $attachmentName
                            ];
                        }
                        $addedFileLine = "📎 <b>File Ditambahkan:</b> <code>" . htmlspecialchars($attachmentName) . "</code> ($typeLabel)\n"
                                       . ($attachmentUrl ? "🔗 <b>Tautan:</b> <a href=\"$attachmentUrl\">Buka File</a>\n" : "")
                                       . "📦 <b>Total Lampiran Tugas:</b> <code>" . count($existingAttachments) . " file</code>\n\n";
                    }

                    if ($cleanRev !== '') {
                        $updatedInstruction .= "\n\n[Revisi/Tambahan dari $sender pada $now]:\n" . $cleanRev;
                    }

                    $primaryUrl = !empty($freshTask['attachment_url']) ? $freshTask['attachment_url'] : ($existingAttachments[0]['url'] ?? $attachmentUrl);
                    $primaryType = !empty($freshTask['attachment_type']) ? $freshTask['attachment_type'] : ($existingAttachments[0]['type'] ?? $attachmentType);
                    $primaryName = !empty($freshTask['attachment_name']) ? $freshTask['attachment_name'] : ($existingAttachments[0]['name'] ?? $attachmentName);
                    $newAttachmentsJson = !empty($existingAttachments) ? json_encode($existingAttachments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
                    $newMediaGroupId = $mediaGroupId !== '' ? $mediaGroupId : ($freshTask['media_group_id'] ?? '');

                    $upd = $db->prepare("UPDATE tasks SET instruction = ?, attachment_url = ?, attachment_type = ?, attachment_name = ?, attachments_json = ?, media_group_id = ?, updated_at = ? WHERE id = ?");
                    safeDbExecute($upd, [$updatedInstruction, $primaryUrl, $primaryType, $primaryName, $newAttachmentsJson, $newMediaGroupId, $now, $repliedTaskId]);

                    if ($lockFp) {
                        @flock($lockFp, LOCK_UN);
                        @fclose($lockFp);
                    }

                    $headerTitle = ($attachmentType && $cleanRev === '')
                        ? "📎 <b>LAMPIRAN BERHASIL DITAMBAHKAN KE TUGAS!</b>"
                        : "📝 <b>INSTRUKSI & LAMPIRAN TUGAS DIPERBARUI!</b>";

                    $reply = "$headerTitle\n"
                           . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                           . "🆔 <b>ID Tugas:</b> <code>$repliedTaskId</code>\n"
                           . "📁 <b>Proyek:</b> <code>$pName</code>\n"
                           . "👤 <b>Pengirim:</b> " . htmlspecialchars($sender) . "\n"
                           . "🕒 <b>Waktu Update:</b> " . date('d-m-Y H:i:s') . " $repTzAbbr\n\n"
                           . $addedFileLine
                           . ($cleanRev !== '' ? "➕ <b>Instruksi Tambahan:</b>\n<code>" . htmlspecialchars($cleanRev) . "</code>\n\n" : "")
                           . "🚀 <i>Pembaruan telah disinkronkan ke tugas $repliedTaskId!</i>";

                    callTelegram($project['bot_token'], 'sendMessage', [
                        'chat_id' => $incomingChatId,
                        'text' => $reply,
                        'parse_mode' => 'HTML',
                        'reply_to_message_id' => $messageId,
                        'disable_web_page_preview' => true
                    ]);
                    echo "OK"; exit;
                }
            }
        }
    }


    // Command /whoami (Identifikasi Multi-Workspace VS Code)
    if (strpos($text, '/whoami') === 0) {
        $pList = $allProjects;

        $reply = "👤 <b>IDENTIFIKASI WORKSPACE TELE-VS-CODE</b>\n\n";
        $reply .= "📌 <b>Proyek Aktif di Chat Ini:</b>\n";
        $reply .= "• Nama: <b>" . htmlspecialchars($activeProject['name']) . "</b> (#" . ($activeProject['code'] ?: $activeProject['id']) . ")\n";
        $curExec = $activeProject['executor_mode'] ?? 'vscode';
        $execLabel = ($curExec === 'shell') ? '⚡ Shell CLI OS (Headless)' : (($curExec === 'auto') ? '🔄 Auto Detect' : '💻 VS Code Extension');
        $reply .= "• Mesin Eksekutor: <code>$execLabel</code>\n";
        if (!empty($activeProject['last_workspace_folder'])) {
            $reply .= "• Folder VS Code: <code>" . htmlspecialchars($activeProject['last_workspace_folder']) . "</code>\n";
        }
        if (!empty($activeProject['last_operator'])) {
            $reply .= "• Operator: <code>" . htmlspecialchars($activeProject['last_operator']) . "</code>\n";
        }
        if (!empty($activeProject['last_seen'])) {
            $reply .= "• Terakhir Aktif: " . htmlspecialchars($activeProject['last_seen']) . " $tzAbbr\n";
        }
        $reply .= "\n📋 <b>SEMUA WORKSPACE TERHUBUNG:</b>\n";

        $nowTs = time();
        foreach ($pList as $idx => $p) {
            $pCode = $p['code'] ?: chr(97 + ($idx % 26));
            $isCur = ($p['id'] == $activeProject['id']) ? " ⭐ [AKTIF]" : "";
            $lastFolder = !empty($p['last_workspace_folder']) ? $p['last_workspace_folder'] : '(Belum ada koneksi VS Code)';
            $lastOp = !empty($p['last_operator']) ? $p['last_operator'] : '-';
            $statusDot = "⚪";
            $statusText = "Belum aktif";
            if (!empty($p['last_seen'])) {
                $seenTs = strtotime($p['last_seen']);
                $diffSec = $nowTs - $seenTs;
                if ($diffSec < 60) {
                    $statusDot = "🟢";
                    $statusText = "Online (Polling {$diffSec}s lalu)";
                } elseif ($diffSec < 3600) {
                    $statusDot = "🟡";
                    $statusText = "Aktif " . round($diffSec / 60) . " menit lalu";
                } else {
                    $statusDot = "⚪";
                    $statusText = "Terakhir: " . date('d/m H:i', $seenTs);
                }
            }

            $reply .= "\n{$statusDot} <b>[#{$pCode}] " . htmlspecialchars($p['name']) . "$isCur</b>\n"
                   . "   📂 Folder: <code>" . htmlspecialchars($lastFolder) . "</code>\n"
                   . "   💻 Operator: <code>" . htmlspecialchars($lastOp) . "</code>\n"
                   . "   ⚙️ Eksekutor: <code>" . (($p['executor_mode'] ?? 'vscode') === 'shell' ? '⚡ Shell OS' : ((($p['executor_mode'] ?? 'vscode') === 'auto') ? '🔄 Auto' : '💻 VS Code')) . "</code>\n"
                   . "   📡 Status: <i>{$statusText}</i>\n";
        }

        $reply .= "\n💡 <b>PANDUAN ROUTING MULTI-WORKSPACE:</b>\n"
               . "• Ketik <code>#a &lt;instruksi&gt;</code> untuk kirim ke Workspace A\n"
               . "• Ketik <code>#b &lt;instruksi&gt;</code> untuk kirim ke Workspace B\n"
               . "• Atau ketik <code>/use &lt;kode&gt;</code> untuk ganti workspace default chat!";

        callTelegram($project['bot_token'], 'sendMessage', [
            'chat_id' => $incomingChatId,
            'text' => $reply,
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $messageId
        ]);
        echo "OK"; exit;
    }

    // Command /projects atau /proyek - Daftar proyek & cara routing multi-vscode
    if (strpos($text, '/projects') === 0 || strpos($text, '/proyek') === 0 || $text === '/p') {
        $lines = [];
        $hasMultiUser = count(array_unique(array_column($allProjects, 'user_id'))) > 1;
        foreach ($allProjects as $idx => $p) {
            $num = $idx + 1;
            $codeStr = !empty($p['code']) ? $p['code'] : chr(97 + ($idx % 26));
            $isCur = ($p['id'] == $activeProject['id']) ? " 🌟 <b>(Aktif di Chat ini)</b>" : "";
            $ownerStr = ($hasMultiUser && !empty($p['email'])) ? "   • Akun: <code>" . htmlspecialchars($p['email']) . "</code>\n" : "";
            $lines[] = "<b>$num. " . htmlspecialchars($p['name']) . "</b>$isCur\n"
                     . $ownerStr
                     . "   • Kode Tag: <code>#$codeStr</code> atau <code>[$codeStr]</code>\n"
                     . "   • Eksekutor: <code>" . (($p['executor_mode'] ?? 'vscode') === 'shell' ? '⚡ Shell OS' : ((($p['executor_mode'] ?? 'vscode') === 'auto') ? '🔄 Auto' : '💻 VS Code')) . "</code>\n"
                     . "   • Perintah Cepat: <code>/use $codeStr</code> atau <code>/$codeStr &lt;instruksi&gt;</code>";
        }
        $reply = "📁 <b>DAFTAR PROYEK TERHUBUNG (" . count($allProjects) . " Proyek):</b>\n\n"
               . implode("\n\n", $lines) . "\n\n"
               . "💡 <b>CARA MENGARAHKAN TUGAS KE VS CODE:</b>\n"
               . "1. <b>Gunakan Tag di Awal Pesan:</b>\n"
               . "   <code>#a Buat fitur login di Folder A</code>\n"
               . "   <code>#b Perbaiki styling tombol di Folder B</code>\n\n"
               . "2. <b>Ganti Proyek Aktif untuk Chat ini:</b>\n"
               . "   <code>/use a</code>  atau  <code>/use b</code>\n"
               . "   <i>(Setelah aktif, pesan biasa tanpa tag otomatis masuk ke proyek tersebut)</i>.";

        callTelegram($project['bot_token'], 'sendMessage', [
            'chat_id' => $incomingChatId,
            'text' => $reply,
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $messageId
        ]);
        echo "OK"; exit;
    }

    // Command /agent atau /agents: Menampilkan daftar agen atau mengganti default agent
    if (preg_match('/^\/(agents?|bot_agent|set_agent)(?:@\w+)?(?:\s+([\s\S]+))?$/i', $text, $mAgtCmd)) {
        $rawAgtTarget = trim($mAgtCmd[2] ?? '');
        $supported = getSupportedAiAgents();
        $pCode = !empty($activeProject['code']) ? $activeProject['code'] : 'a';

        if ($rawAgtTarget === '') {
            // Tampilkan daftar agen yang siap diperintah
            $listText = "";
            foreach ($supported as $kCode => $info) {
                $isCurrent = (strcasecmp($info['name'], $currentAgent) === 0 || strcasecmp($kCode, $currentAgent) === 0);
                $statusBadge = $isCurrent ? " <b>[🟢 AKTIF SEKARANG]</b>" : "";
                $listText .= "{$info['badge']} <code>{$info['tag']}</code> - <b>{$info['name']}</b>$statusBadge\n"
                           . "   <i>{$info['desc']}</i>\n";
            }

            $reply = "🤖 <b>DAFTAR AI AGENT RESMI (TELE-VS-CODE)</b>\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                   . "🎯 <b>Agent Default Saat Ini:</b>\n"
                   . "👉 <code>$currentAgent</code> (Proyek: <b>" . htmlspecialchars($activeProject['name']) . "</b> / <code>#$pCode</code>)\n\n"
                   . "⚡ <b>Agen yang Siap Diperintah:</b>\n"
                   . $listText . "\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "🔄 <b>Ganti Default Agent untuk Chat Ini:</b>\n"
                   . "Ketik: <code>/agent &lt;kode_agent&gt;</code>\n"
                   . "Contoh: <code>/agent copilot</code> atau <code>/agent claude</code> atau <code>/agent antigravity</code>\n\n"
                   . "💡 <b>Contoh Perintah Tugas Langsung:</b>\n"
                   . "1. <b>Tugaskan langsung ke Agent tertentu:</b>\n"
                   . "   <code>@copilot buatkan fungsi verifikasi email</code>\n"
                   . "   <code>@claude optimasi query SQL</code>\n"
                   . "   <code>@antigravity proses antrian sekarang</code>\n\n"
                   . "2. <b>Kombinasi Proyek & Agent:</b>\n"
                   . "   <code>#$pCode @copilot tambahkan validasi form</code>\n"
                   . "   <code>/$pCode @claude perbaiki bug di index.php</code>\n\n"
                   . "<i>(Jika tidak menyebutkan @agent, tugas otomatis dikerjakan oleh agent default: <b>$currentAgent</b>).</i>";

            callTelegram($project['bot_token'], 'sendMessage', [
                'chat_id' => $incomingChatId,
                'text' => $reply,
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $messageId
            ]);
            echo "OK"; exit;
        } else {
            // Pengguna ingin ganti default agent: /agent <kodeagent>
            $matched = resolveAgentCodeOrName($rawAgtTarget);
            if ($matched) {
                $newAgentCode = $matched[0];
                $newAgentName = $matched[1];

                // Simpan ke sesi chat (dan ke proyek aktif jika relevan)
                $sessionKey = ($chatType === 'private' || empty($senderUserId)) ? $incomingChatId : ($incomingChatId . ':' . $senderUserId);
                $updSes = $db->prepare("INSERT INTO chat_sessions (chat_id, user_id, active_project_id, default_agent, updated_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(chat_id) DO UPDATE SET default_agent = excluded.default_agent, updated_at = excluded.updated_at");
                $updSes->execute([$sessionKey, $activeProject['user_id'] ?? 1, $activeProject['id'] ?? 0, $newAgentName, date('Y-m-d H:i:s')]);
                $updSes->execute([$incomingChatId, $activeProject['user_id'] ?? 1, $activeProject['id'] ?? 0, $newAgentName, date('Y-m-d H:i:s')]);

                // Update juga default_agent di project aktif
                if (!empty($activeProject['id'])) {
                    $db->prepare("UPDATE projects SET default_agent = ? WHERE id = ?")->execute([$newAgentName, $activeProject['id']]);
                }

                $reply = "✅ <b>DEFAULT AGENT BERHASIL DIUBAH!</b>\n\n"
                       . "🤖 <b>Agent Default Sekarang:</b> <code>$newAgentName</code> (<code>@$newAgentCode</code>)\n"
                       . "📁 <b>Proyek Aktif:</b> <code>" . htmlspecialchars($activeProject['name']) . "</code> (Kode: <code>#$pCode</code>)\n"
                       . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n\n"
                       . "👉 Semua perintah instruksi tanpa mention di chat ini selanjutnya akan otomatis dikerjakan oleh <b>$newAgentName</b>.\n\n"
                       . "━━━━━━━━━━━━━━━━━━━━━━\n"
                       . "💡 <b>Contoh Perintah:</b>\n"
                       . "• Tanpa mention: <code>Buatkan modul autentikasi JWT</code> &rarr; otomatis ke <b>$newAgentName</b>\n"
                       . "• Mention agen lain sesekali: <code>@antigravity proses antrian</code>\n"
                       . "• Spesifik proyek & agen: <code>#$pCode @$newAgentCode buat fitur baru</code> atau <code>/$pCode @$newAgentCode jalankan unit test</code>";

                callTelegram($project['bot_token'], 'sendMessage', [
                    'chat_id' => $incomingChatId,
                    'text' => $reply,
                    'parse_mode' => 'HTML',
                    'reply_to_message_id' => $messageId
                ]);
                echo "OK"; exit;
            } else {
                $validCodes = implode(', ', array_map(function($k) { return "<code>$k</code>"; }, array_keys($supported)));
                $reply = "⚠️ <b>KODE AGENT TIDAK DIKENALI</b>\n\n"
                       . "Kode agent '<b>" . htmlspecialchars($rawAgtTarget) . "</b>' tidak terdaftar.\n\n"
                       . "Pilihan kode agent yang valid:\n" . $validCodes . "\n\n"
                       . "Contoh penggunaan:\n"
                       . "• <code>/agent copilot</code>\n"
                       . "• <code>/agent claude</code>\n"
                       . "• <code>/agent antigravity</code>\n"
                       . "Ketik <code>/agent</code> untuk melihat daftar lengkap.";

                callTelegram($project['bot_token'], 'sendMessage', [
                    'chat_id' => $incomingChatId,
                    'text' => $reply,
                    'parse_mode' => 'HTML',
                    'reply_to_message_id' => $messageId
                ]);
                echo "OK"; exit;
            }
        }
    }

    // Command /use atau /switch untuk ganti proyek aktif
    if (preg_match('/^\/(use|switch|pilih|proyek_aktif)(?:@\w+)?(?:\s+([\s\S]+))?$/i', $text, $mUse)) {
        $rawTarget = trim($mUse[2] ?? '');
        $target = '';
        $inlineInstruction = '';
        if ($rawTarget === '') {
            $curCode = !empty($activeProject['code']) ? $activeProject['code'] : 'a';
            $ownerInfo = !empty($activeProject['email']) ? "\n👤 <b>Akun:</b> <code>" . htmlspecialchars($activeProject['email']) . "</code>" : "";
            $reply = "🌟 <b>PROYEK AKTIF SAAT INI:</b>\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                   . "📁 <b>Nama Proyek:</b> <code>" . htmlspecialchars($activeProject['name']) . "</code>\n"
                   . "🏷️ <b>Kode Tag:</b> <code>#$curCode</code> (atau <code>[#$curCode]</code>)\n"
                   . "🔑 <b>Project Key:</b> <code>" . htmlspecialchars($activeProject['project_key']) . "</code>" . $ownerInfo . "\n"
                   . "🤖 <b>Agent Aktif:</b> <code>" . htmlspecialchars($currentAgent) . "</code>\n"
                   . "🕒 <b>Waktu Server:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n\n"
                   . "👉 <i>Setiap pesan instruksi biasa di chat ini otomatis dikerjakan oleh <b>" . htmlspecialchars($currentAgent) . "</b> di VS Code proyek <b>" . htmlspecialchars($activeProject['name']) . "</b>.</i>\n\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "🔄 <b>Ganti Proyek Aktif:</b>\n"
                   . "Ketik: <code>/use &lt;kode_tag&gt;</code> (Contoh: <code>/use a</code> atau <code>/use b</code>)\n"
                   . "🤖 <b>Ganti Default Agent:</b> Ketik <code>/agent &lt;kode&gt;</code> (Contoh: <code>/agent copilot</code>)\n"
                   . "Ketik <code>/projects</code> untuk melihat semua proyek Anda.";
            callTelegram($project['bot_token'], 'sendMessage', [
                'chat_id' => $incomingChatId,
                'text' => $reply,
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $messageId
            ]);
            echo "OK"; exit;
        } else {
            $parts = preg_split('/\r?\n/', $rawTarget, 2);
            $firstLine = trim($parts[0]);
            $words = preg_split('/\s+/', $firstLine, 2);
            $candidateToken = strtolower(ltrim($words[0], '#'));

            $foundP = null;
            // Prioritas 1: Exact match Kode Proyek, Project Key, ID, Index Letter (a/b/c), atau Nomor Urut pada token pertama
            foreach ($allProjects as $idx => $p) {
                $pCode = strtolower($p['code'] ?: chr(97 + ($idx % 26)));
                $pIndexLetter = chr(97 + ($idx % 26));
                $pKey = strtolower($p['project_key'] ?? '');
                if ($candidateToken === $pCode || $candidateToken === $pIndexLetter || ($pKey && $candidateToken === $pKey) || $candidateToken === (string)$p['id'] || $candidateToken === (string)($idx + 1)) {
                    $foundP = $p;
                    $target = $candidateToken;
                    $inlineInstruction = trim(($words[1] ?? '') . (isset($parts[1]) ? "\n" . $parts[1] : ''));
                    break;
                }
            }

            // Prioritas 2: Cek firstLine secara utuh
            if (!$foundP) {
                $candidateFull = strtolower(ltrim($firstLine, '#'));
                foreach ($allProjects as $idx => $p) {
                    $pCode = strtolower($p['code'] ?: chr(97 + ($idx % 26)));
                    $pIndexLetter = chr(97 + ($idx % 26));
                    $pKey = strtolower($p['project_key'] ?? '');
                    if ($candidateFull === $pCode || $candidateFull === $pIndexLetter || ($pKey && $candidateFull === $pKey) || $candidateFull === (string)$p['id'] || $candidateFull === (string)($idx + 1) || $candidateFull === strtolower(trim($p['name'] ?? ''))) {
                        $foundP = $p;
                        $target = $candidateFull;
                        $inlineInstruction = isset($parts[1]) ? trim($parts[1]) : '';
                        break;
                    }
                }
            }

            // Prioritas 3: Cek rawTarget secara utuh (jika tidak ada instruksi tambahan)
            if (!$foundP) {
                $candidateRaw = strtolower(ltrim($rawTarget, '#'));
                foreach ($allProjects as $idx => $p) {
                    if ($candidateRaw === strtolower(trim($p['name'] ?? ''))) {
                        $foundP = $p;
                        $target = $candidateRaw;
                        $inlineInstruction = '';
                        break;
                    }
                }
            }

            if ($foundP) {
                $sessionKey = ($chatType === 'private' || empty($senderUserId)) ? $incomingChatId : ($incomingChatId . ':' . $senderUserId);
                $updSes = $db->prepare("INSERT INTO chat_sessions (chat_id, user_id, active_project_id, updated_at) VALUES (?, ?, ?, ?) ON CONFLICT(chat_id) DO UPDATE SET active_project_id = excluded.active_project_id, updated_at = excluded.updated_at");
                $updSes->execute([$sessionKey, $foundP['user_id'], $foundP['id'], date('Y-m-d H:i:s')]);
                $updSes->execute([$incomingChatId, $foundP['user_id'], $foundP['id'], date('Y-m-d H:i:s')]);

                $pCode = $foundP['code'] ?: $foundP['id'];
                $ownerInfo = !empty($foundP['email']) ? "\n👤 <b>Akun:</b> <code>" . htmlspecialchars($foundP['email']) . "</code>" : "";
                $effectiveAgent = !empty($chatDefaultAgent) ? $chatDefaultAgent : (!empty($foundP['default_agent']) ? $foundP['default_agent'] : $currentAgent);

                if ($inlineInstruction !== '') {
                    callTelegram($project['bot_token'], 'sendMessage', [
                        'chat_id' => $incomingChatId,
                        'text' => "🎯 <b>Proyek aktif dialihkan ke " . htmlspecialchars($foundP['name']) . " (<code>#$pCode</code>)</b>.\n🤖 <b>Agent:</b> <code>" . htmlspecialchars($effectiveAgent) . "</code>\n⚡ Memproses instruksi...",
                        'parse_mode' => 'HTML',
                        'reply_to_message_id' => $messageId
                    ]);
                    $activeProject = $foundP;
                    $targetProject = $foundP;
                    $text = $inlineInstruction;
                } else {
                    $reply = "🎯 <b>PROYEK AKTIF BERHASIL DIUBAH!</b>\n\n"
                           . "📁 <b>Proyek Saat Ini:</b> <code>" . htmlspecialchars($foundP['name']) . "</code> (Kode: <code>#$pCode</code>)" . $ownerInfo . "\n"
                           . "🤖 <b>Agent Aktif:</b> <code>" . htmlspecialchars($effectiveAgent) . "</code>\n"
                           . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n\n"
                           . "👉 Semua pesan instruksi dari Anda selanjutnya di chat ini akan otomatis dikerjakan oleh <b>" . htmlspecialchars($effectiveAgent) . "</b> di VS Code proyek <b>" . htmlspecialchars($foundP['name']) . "</b>.\n\n"
                           . "<i>Gunakan tag <code>#&lt;kode&gt;</code> jika ingin menargetkan proyek lain sesekali, atau ketik <code>/agent &lt;kode&gt;</code> untuk mengganti agent default.</i>";
                    callTelegram($project['bot_token'], 'sendMessage', [
                        'chat_id' => $incomingChatId,
                        'text' => $reply,
                        'parse_mode' => 'HTML',
                        'reply_to_message_id' => $messageId
                    ]);
                    echo "OK"; exit;
                }
            } else {
                $reply = "⛔ <b>AKSES PROYEK DITOLAK</b>\n\n"
                       . "Proyek '<b>" . htmlspecialchars($firstLine) . "</b>' tidak ditemukan atau Anda tidak terdaftar di whitelist untuk proyek tersebut.\n\n"
                       . "Ketik <code>/projects</code> untuk melihat daftar proyek yang diizinkan untuk akun Anda.";
                callTelegram($project['bot_token'], 'sendMessage', [
                    'chat_id' => $incomingChatId,
                    'text' => $reply,
                    'parse_mode' => 'HTML',
                    'reply_to_message_id' => $messageId
                ]);
                echo "OK"; exit;
            }
        }
    }

    // Command /executor, /mode, /shell: Switch Mesin Eksekutor (VS Code vs Shell OS)
    if (preg_match('/^\/(executor|mode|shell|eksekutor)(?:@\w+)?(?:\s+([\s\S]+))?$/i', $text, $mExecCmd)) {
        $rawExecArg = strtolower(trim($mExecCmd[2] ?? ''));
        $pCode = !empty($activeProject['code']) ? $activeProject['code'] : 'a';
        $pId = (int)$activeProject['id'];
        $curExec = !empty($activeProject['executor_mode']) ? $activeProject['executor_mode'] : 'vscode';

        if (in_array($rawExecArg, ['vscode', 'ide', 'code'])) {
            $newMode = 'vscode';
            $db->prepare("UPDATE projects SET executor_mode = ? WHERE id = ?")->execute([$newMode, $pId]);
            $activeProject['executor_mode'] = $newMode;

            $reply = "💻 <b>MESIN EKSEKUTOR DIUBAH KE: VS CODE EXTENSION</b>\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                   . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($activeProject['name']) . "</code> (<code>#$pCode</code>)\n"
                   . "⚙️ <b>Mode Eksekutor:</b> <code>VS Code Extension (IDE Mode)</code>\n"
                   . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n\n"
                   . "👉 <b>Cara Kerja:</b>\n"
                   . "Tugas yang masuk selanjutnya akan dialirkan ke jendela editor <b>Visual Studio Code</b> dan dikerjakan langsung oleh AI Agent di panel Chat IDE.\n\n"
                   . "💡 <i>Pastikan Anda telah membuka workspace proyek di VS Code dan status bar <b>Tele-vs-Code</b> aktif terhubung.</i>\n\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "🔄 Ingin beralih ke Shell terminal? Ketik: <code>/executor shell</code>";

            $inlineKb = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ 💻 VS Code (Aktif)', 'callback_data' => 'cb:exec_vscode'],
                        ['text' => '⚡ Switch ke Shell OS', 'callback_data' => 'cb:exec_shell'],
                        ['text' => '🔄 Auto', 'callback_data' => 'cb:exec_auto']
                    ]
                ]
            ];

            callTelegram($project['bot_token'], 'sendMessage', [
                'chat_id' => $incomingChatId,
                'text' => $reply,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($inlineKb),
                'reply_to_message_id' => $messageId
            ]);
            echo "OK"; exit;

        } elseif (in_array($rawExecArg, ['shell', 'cli', 'terminal', 'headless'])) {
            $newMode = 'shell';
            $db->prepare("UPDATE projects SET executor_mode = ? WHERE id = ?")->execute([$newMode, $pId]);
            $activeProject['executor_mode'] = $newMode;

            $reply = "⚡ <b>MESIN EKSEKUTOR DIUBAH KE: SHELL CLI OS!</b>\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                   . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($activeProject['name']) . "</code> (<code>#$pCode</code>)\n"
                   . "⚙️ <b>Mode Eksekutor:</b> <code>Shell CLI / Terminal Runner (Multi-Platform)</code>\n"
                   . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n\n"
                   . "👉 <b>Aktivasi di Terminal Komputer / Server Anda:</b>\n"
                   . "Aplikasi ini dapat berjalan di OS apapun (Linux, macOS, Windows) tanpa membuka GUI VS Code.\n\n"
                   . "<b>1. Linux Server / VPS / macOS:</b>\n"
                   . "<code>nohup node .vscode/tele-vs-code/tele-worker.js > tele-worker.log 2>&1 &</code>\n"
                   . "<i>Atau jalankan wrapper:</i> <code>./.vscode/tele-vs-code/tele-worker.sh</code>\n"
                   . "<i>Atau gunakan PM2:</i> <code>pm2 start .vscode/tele-vs-code/tele-worker.js --name tele-worker</code>\n\n"
                   . "<b>2. Windows (PowerShell / Command Prompt):</b>\n"
                   . "<code>node .vscode/tele-vs-code/tele-worker.js</code>\n"
                   . "<i>Atau jalankan CMD wrapper:</i> <code>.\\.vscode\\tele-vs-code\\tele-worker.cmd</code>\n\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "🔄 Ingin beralih kembali ke VS Code? Ketik: <code>/executor vscode</code>";

            $inlineKb = [
                'inline_keyboard' => [
                    [
                        ['text' => '💻 Switch ke VS Code', 'callback_data' => 'cb:exec_vscode'],
                        ['text' => '✅ ⚡ Shell OS (Aktif)', 'callback_data' => 'cb:exec_shell'],
                        ['text' => '🔄 Auto', 'callback_data' => 'cb:exec_auto']
                    ],
                    [
                        ['text' => '📖 Panduan Lengkap Multi-OS', 'callback_data' => 'cb:exec_guide']
                    ]
                ]
            ];

            callTelegram($project['bot_token'], 'sendMessage', [
                'chat_id' => $incomingChatId,
                'text' => $reply,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($inlineKb),
                'reply_to_message_id' => $messageId
            ]);
            echo "OK"; exit;

        } elseif (in_array($rawExecArg, ['auto', 'otomatis'])) {
            $newMode = 'auto';
            $db->prepare("UPDATE projects SET executor_mode = ? WHERE id = ?")->execute([$newMode, $pId]);
            $activeProject['executor_mode'] = $newMode;

            $reply = "🔄 <b>MESIN EKSEKUTOR DIUBAH KE: OTOMATIS (AUTO DETECT)</b>\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                   . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($activeProject['name']) . "</code> (<code>#$pCode</code>)\n"
                   . "⚙️ <b>Mode Eksekutor:</b> <code>Auto Detect (VS Code &harr; Shell CLI)</code>\n"
                   . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n\n"
                   . "👉 <b>Cara Kerja:</b>\n"
                   . "• Jika VS Code sedang terbuka dan aktif (ada heartbeat terkini), tugas diproses di <b>VS Code IDE</b>.\n"
                   . "• Jika VS Code ditutup atau offline, tugas otomatis dialihkan dan dieksekusi oleh <b>Shell CLI Runner</b> di background server/komputer Anda.\n\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "🔄 Pilih mode tetap kapan saja: <code>/executor vscode</code> atau <code>/executor shell</code>";

            $inlineKb = [
                'inline_keyboard' => [
                    [
                        ['text' => '💻 VS Code', 'callback_data' => 'cb:exec_vscode'],
                        ['text' => '⚡ Shell OS', 'callback_data' => 'cb:exec_shell'],
                        ['text' => '✅ 🔄 Auto (Aktif)', 'callback_data' => 'cb:exec_auto']
                    ]
                ]
            ];

            callTelegram($project['bot_token'], 'sendMessage', [
                'chat_id' => $incomingChatId,
                'text' => $reply,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($inlineKb),
                'reply_to_message_id' => $messageId
            ]);
            echo "OK"; exit;

        } else {
            // Tampilkan status eksekutor saat ini & menu inline interaktif
            $modeLabel = ($curExec === 'shell') 
                ? "⚡ <b>Shell CLI / Headless Terminal (OS Apapun)</b>" 
                : (($curExec === 'auto') ? "🔄 <b>Otomatis (Auto Detect: VS Code / Shell)</b>" : "💻 <b>Visual Studio Code (IDE Extension)</b>");

            $reply = "⚙️ <b>PENGATURAN MESIN EKSEKUTOR (MULTI-PLATFORM)</b>\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                   . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($activeProject['name']) . "</code> (<code>#$pCode</code>)\n"
                   . "🎯 <b>Eksekutor Saat Ini:</b>\n"
                   . "👉 $modeLabel\n\n"
                   . "Pilih mesin eksekutor yang ingin Anda gunakan untuk mengerjakan tugas:\n\n"
                   . "1. 💻 <b>Visual Studio Code (IDE Mode)</b>\n"
                   . "   Tugas dialirkan ke antarmuka editor VS Code & diselesaikan oleh AI Agent di Chat IDE.\n\n"
                   . "2. ⚡ <b>Shell CLI OS (Headless Mode)</b>\n"
                   . "   Tugas dieksekusi langsung di terminal / shell OS apapun (Linux Server VPS, macOS, atau Windows) tanpa membutuhkan aplikasi GUI VS Code.\n\n"
                   . "3. 🔄 <b>Otomatis (Auto Detect)</b>\n"
                   . "   Memprioritaskan VS Code saat editor aktif, dan otomatis fallback ke Shell CLI saat editor offline.\n\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "🔘 <b>Klik tombol di bawah untuk beralih mode secara instan:</b>";

            $inlineKb = [
                'inline_keyboard' => [
                    [
                        ['text' => ($curExec === 'vscode' ? '✅ 💻 VS Code' : '💻 VS Code'), 'callback_data' => 'cb:exec_vscode'],
                        ['text' => ($curExec === 'shell' ? '✅ ⚡ Shell OS' : '⚡ Shell OS'), 'callback_data' => 'cb:exec_shell'],
                        ['text' => ($curExec === 'auto' ? '✅ 🔄 Auto' : '🔄 Auto'), 'callback_data' => 'cb:exec_auto']
                    ],
                    [
                        ['text' => '📖 Petunjuk Setup Runner di Linux / Mac / Windows', 'callback_data' => 'cb:exec_guide']
                    ]
                ]
            ];

            callTelegram($project['bot_token'], 'sendMessage', [
                'chat_id' => $incomingChatId,
                'text' => $reply,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($inlineKb),
                'reply_to_message_id' => $messageId
            ]);
            echo "OK"; exit;
        }
    }

    // Command /lapo: Melihat apa yang sedang dikerjakan sebuah tag proyek
    if (preg_match('/^\/lapo(@\w+)?(?:\s+(.+))?$/is', $text, $mLapo)) {
        $lapoArg = trim($mLapo[2] ?? '');
        $pTarget = null;
        $isAll = false;

        if ($lapoArg) {
            $argLower = strtolower(ltrim($lapoArg, '#'));
            if (in_array($argLower, ['all', 'semua', '*'])) {
                $isAll = true;
            } else {
                // Prioritas 1: Exact match Kode / Tag Proyek (misal: 'biru', 'a', 'b') atau Project Key
                foreach ($allProjects as $idx => $p) {
                    $pCode = strtolower($p['code'] ?: chr(97 + ($idx % 26)));
                    $pKey = strtolower($p['project_key'] ?? '');
                    if ($argLower === $pCode || ($pKey && $argLower === $pKey)) {
                        $pTarget = $p;
                        break;
                    }
                }
                // Prioritas 2: Exact match ID atau nomor urut
                if (!$pTarget) {
                    foreach ($allProjects as $idx => $p) {
                        if ($argLower === (string)$p['id'] || $argLower === (string)($idx + 1)) {
                            $pTarget = $p;
                            break;
                        }
                    }
                }
                // Prioritas 3: Exact match Nama Proyek
                if (!$pTarget) {
                    foreach ($allProjects as $idx => $p) {
                        if ($argLower === strtolower(trim($p['name'] ?? ''))) {
                            $pTarget = $p;
                            break;
                        }
                    }
                }
            }
        } else {
            // Tanpa argumen: gunakan proyek aktif chat saat ini!
            $pTarget = $activeProject;
        }

        if ($isAll) {
            // Ringkasan semua proyek
            $reply = "🛠️ <b>STATUS PEKERJAAN SEMUA PROYEK</b>
"
                   . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $tzAbbr

";

            foreach ($allProjects as $p) {
                $pCode = $p['code'] ?: $p['id'];
                $isAct = ($p['id'] == $activeProject['id']) ? " ⭐" : "";

                // Cari tugas yang sedang IN_PROGRESS
                $stmtAct = $db->prepare("SELECT * FROM tasks WHERE project_id = ? AND status = 'IN_PROGRESS' ORDER BY id DESC LIMIT 1");
                $stmtAct->execute([$p['id']]);
                $curTask = $stmtAct->fetch(PDO::FETCH_ASSOC);

                // Hitung antrean PENDING
                $stmtPend = $db->prepare("SELECT COUNT(*) FROM tasks WHERE project_id = ? AND status = 'PENDING'");
                $stmtPend->execute([$p['id']]);
                $pendCount = (int)$stmtPend->fetchColumn();

                $reply .= "📁 <b>" . htmlspecialchars($p['name']) . " (#$pCode)$isAct:</b>
";
                if ($curTask) {
                    $instSnippet = htmlspecialchars(mb_strimwidth($curTask['instruction'], 0, 75, '...'));
                    $reply .= "   ⚡ <b>Lagi Dikerjakan:</b> <i>&quot;$instSnippet&quot;</i>
"
                           . "   👤 <b>Pengirim:</b> " . htmlspecialchars($curTask['sender']) . "
"
                           . ($pendCount > 0 ? "   ⏳ Antrean Menunggu: $pendCount tugas
" : "");
                } elseif ($pendCount > 0) {
                    $stmtNext = $db->prepare("SELECT * FROM tasks WHERE project_id = ? AND status = 'PENDING' ORDER BY id ASC LIMIT 1");
                    $stmtNext->execute([$p['id']]);
                    $nxtTask = $stmtNext->fetch(PDO::FETCH_ASSOC);
                    $nxtSnippet = $nxtTask ? htmlspecialchars(mb_strimwidth($nxtTask['instruction'], 0, 70, '...')) : '';
                    $reply .= "   ⏳ <b>Antrean Berikutnya:</b> <i>&quot;$nxtSnippet&quot;</i> ($pendCount tugas menunggu)
";
                } else {
                    $stmtDone = $db->prepare("SELECT * FROM tasks WHERE project_id = ? AND status = 'DONE' ORDER BY updated_at DESC, id DESC LIMIT 1");
                    $stmtDone->execute([$p['id']]);
                    $lastDone = $stmtDone->fetch(PDO::FETCH_ASSOC);
                    if ($lastDone) {
                        $doneSnippet = htmlspecialchars(mb_strimwidth($lastDone['instruction'], 0, 60, '...'));
                        $reply .= "   💤 <b>Standby / Idle</b> (Terakhir selesai: <i>&quot;$doneSnippet&quot;</i>)
";
                    } else {
                        $reply .= "   💤 <b>Standby / Idle</b> (Belum ada tugas)
";
                    }
                }
                $reply .= "
";
            }
            $reply .= "<i>Ketik <code>/lapo &lt;tag&gt;</code> untuk rincian pekerjaan sebuah tag proyek.</i>";
        } elseif ($pTarget) {
            $pCode = $pTarget['code'] ?: $pTarget['id'];
            $pName = htmlspecialchars($pTarget['name']);

            // Cek status worker (last_seen)
            $workerStatus = "⚪ Belum Terhubung";
            if (!empty($pTarget['last_seen'])) {
                $lastSeenSec = time() - strtotime($pTarget['last_seen']);
                if ($lastSeenSec <= 15) {
                    $workerStatus = "🟢 Aktif Online ($lastSeenSec dtk lalu)";
                } elseif ($lastSeenSec < 300) {
                    $workerStatus = "🟡 Aktif (" . round($lastSeenSec / 60, 1) . " mnt lalu)";
                } else {
                    $workerStatus = "⚪ Offline (" . date('d/m H:i', strtotime($pTarget['last_seen'])) . ")";
                }
            }

            // 1. Cari tugas yang sedang aktif dikerjakan (IN_PROGRESS)
            $stmtAct = $db->prepare("SELECT * FROM tasks WHERE project_id = ? AND status = 'IN_PROGRESS' ORDER BY id DESC LIMIT 1");
            $stmtAct->execute([$pTarget['id']]);
            $curTask = $stmtAct->fetch(PDO::FETCH_ASSOC);

            // 2. Hitung jumlah antrean PENDING
            $stmtPend = $db->prepare("SELECT COUNT(*) FROM tasks WHERE project_id = ? AND status = 'PENDING'");
            $stmtPend->execute([$pTarget['id']]);
            $pendCount = (int)$stmtPend->fetchColumn();

            // 3. Hitung jumlah total tugas selesai
            $stmtDoneCount = $db->prepare("SELECT COUNT(*) FROM tasks WHERE project_id = ? AND status = 'DONE'");
            $stmtDoneCount->execute([$pTarget['id']]);
            $doneCount = (int)$stmtDoneCount->fetchColumn();

            $reply = "🛠️ <b>LAPORAN PEKERJAAN (LAPO)</b>
"
                   . "📁 <b>Proyek:</b> <code>$pName</code> (#$pCode)
"
                   . "📡 <b>Worker VS Code:</b> $workerStatus
"
                   . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $tzAbbr

";

            if ($curTask) {
                // Ada tugas yang sedang aktif dikerjakan!
                $elapsedSec = time() - strtotime($curTask['updated_at'] ?: $curTask['created_at']);
                $elapsedText = ($elapsedSec < 60) ? "$elapsedSec detik lalu" : round($elapsedSec / 60) . " menit lalu";

                $reply .= "⚡ <b>SEDANG DIKERJAKAN SAAT INI:</b>
"
                       . "🆔 <b>ID Tugas:</b> <code>{$curTask['id']}</code>
"
                       . "📝 <b>Instruksi:</b> <i>&quot;" . htmlspecialchars($curTask['instruction']) . "&quot;</i>
"
                       . "👤 <b>Pengirim:</b> " . htmlspecialchars($curTask['sender']) . "
"
                       . "⏱️ <b>Mulai:</b> " . htmlspecialchars($curTask['created_at']) . " ($elapsedText)
"
                       . (!empty($curTask['notes']) ? "📌 <b>Catatan:</b> " . htmlspecialchars($curTask['notes']) . "
" : "")
                       . "
";

                if ($pendCount > 0) {
                    $reply .= "⏳ <b>Antrean Berikutnya:</b> Ada <b>$pendCount</b> tugas menunggu giliran.
";
                }
            } elseif ($pendCount > 0) {
                // Tidak ada IN_PROGRESS, tapi ada PENDING
                $stmtNext = $db->prepare("SELECT * FROM tasks WHERE project_id = ? AND status = 'PENDING' ORDER BY id ASC LIMIT 1");
                $stmtNext->execute([$pTarget['id']]);
                $nextTask = $stmtNext->fetch(PDO::FETCH_ASSOC);

                $reply .= "⏳ <b>ANTREAN MENUNGGU (PENDING):</b>
"
                       . "🆔 <b>ID:</b> <code>{$nextTask['id']}</code>
"
                       . "📝 <b>Instruksi:</b> <i>&quot;" . htmlspecialchars($nextTask['instruction']) . "&quot;</i>
"
                       . "👤 <b>Pengirim:</b> " . htmlspecialchars($nextTask['sender']) . "
"
                       . "📊 <b>Total Antrean Menunggu:</b> $pendCount tugas
"
                       . "👉 <i>Tugas akan segera dieksekusi otomatis oleh AI Agent saat worker aktif.</i>
";
            } else {
                // Tidak ada tugas aktif maupun antrean -> Standby / Idle
                $reply .= "💤 <b>STATUS: STANDBY / IDLE</b>
"
                       . "<i>Saat ini AI Agent tidak sedang mengerjakan tugas apapun untuk tag <code>#$pCode</code>.</i>

";

                // Tampilkan tugas terakhir yang selesai
                $stmtLastDone = $db->prepare("SELECT * FROM tasks WHERE project_id = ? AND status = 'DONE' ORDER BY updated_at DESC, id DESC LIMIT 1");
                $stmtLastDone->execute([$pTarget['id']]);
                $lastDone = $stmtLastDone->fetch(PDO::FETCH_ASSOC);

                if ($lastDone) {
                    $reply .= "✅ <b>TUGAS TERAKHIR SELESAI (100%):</b>
"
                           . "🆔 <b>ID:</b> <code>{$lastDone['id']}</code>
"
                           . "📝 <b>Instruksi:</b> <i>&quot;" . htmlspecialchars($lastDone['instruction']) . "&quot;</i>
"
                           . (!empty($lastDone['notes']) ? "📋 <b>Hasil:</b> " . htmlspecialchars($lastDone['notes']) . "
" : "")
                           . "🕒 <b>Waktu Selesai:</b> " . htmlspecialchars($lastDone['updated_at'] ?: $lastDone['created_at']) . "
";
                }
                $reply .= "
💡 <i>Ketik <code>#$pCode &lt;instruksi&gt;</code> untuk memberikan tugas baru ke proyek ini.</i>";
            }
        } else {
            // Tag tidak ditemukan
            $tagList = [];
            foreach ($allProjects as $p) {
                $tagList[] = "• <code>#" . ($p['code'] ?: $p['id']) . "</code> (" . htmlspecialchars($p['name']) . ")";
            }
            $reply = "❓ <b>TAG PROYEK TIDAK DITEMUKAN</b>

"
                   . "Tag atau proyek '<b>" . htmlspecialchars($lapoArg) . "</b>' tidak ditemukan di akun Anda.

"
                   . "📌 <b>Daftar Tag Proyek Anda:</b>
"
                   . implode("
", $tagList) . "

"
                   . "💡 <b>Contoh Penggunaan:</b>
"
                   . "• <code>/lapo</code> (cek proyek aktif saat ini)
"
                   . "• <code>/lapo #" . ($allProjects[0]['code'] ?? 'tag') . "</code> (cek tag tertentu)
"
                   . "• <code>/lapo all</code> (cek semua tag proyek)";
        }

        callTelegram($project['bot_token'], 'sendMessage', [
            'chat_id' => $incomingChatId,
            'text' => $reply,
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $messageId
        ]);
        echo "OK"; exit;
    }

    // Command /status
    if (strpos($text, '/status') === 0 || strpos($text, '/listq') === 0) {
        $pTarget = null;
        if (preg_match('/^\/(status|listq)\s+(.+)$/i', $text, $mSt)) {
            $stArg = strtolower(ltrim(trim($mSt[2]), '#'));
            foreach ($allProjects as $idx => $p) {
                $pCode = strtolower($p['code'] ?: chr(97 + ($idx % 26)));
                $pKey = strtolower($p['project_key'] ?? '');
                if ($stArg === $pCode || ($pKey && $stArg === $pKey)) {
                    $pTarget = $p;
                    break;
                }
            }
            if (!$pTarget) {
                foreach ($allProjects as $idx => $p) {
                    if ($stArg === (string)$p['id'] || $stArg === (string)($idx + 1)) {
                        $pTarget = $p;
                        break;
                    }
                }
            }
            if (!$pTarget) {
                foreach ($allProjects as $idx => $p) {
                    if ($stArg === strtolower(trim($p['name'] ?? ''))) {
                        $pTarget = $p;
                        break;
                    }
                }
            }
        }

        if ($pTarget) {
            $stmtT = $db->prepare("SELECT status, count(*) as c FROM tasks WHERE project_id = ? GROUP BY status");
            $stmtT->execute([$pTarget['id']]);
            $counts = ['PENDING' => 0, 'IN_PROGRESS' => 0, 'DONE' => 0, 'FAILED' => 0];
            while ($row = $stmtT->fetch(PDO::FETCH_ASSOC)) {
                $counts[$row['status']] = $row['c'];
            }
            $reply = "📊 <b>STATUS ANTREAN: " . htmlspecialchars($pTarget['name']) . "</b> (#" . ($pTarget['code'] ?: $pTarget['id']) . ")\n\n"
                   . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n"
                   . "⏳ <b>Menunggu:</b> {$counts['PENDING']}\n"
                   . "⚡ <b>Sedang Dikerjakan:</b> {$counts['IN_PROGRESS']}\n"
                   . "✅ <b>Selesai:</b> {$counts['DONE']}\n"
                   . ($counts['FAILED'] > 0 ? "🚨 <b>Gagal:</b> {$counts['FAILED']}\n" : "");
        } else {
            $reply = "📊 <b>STATUS ANTREAN SEMUA PROYEK</b>\n"
                   . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n\n";
            foreach ($allProjects as $p) {
                $stmtT = $db->prepare("SELECT status, count(*) as c FROM tasks WHERE project_id = ? GROUP BY status");
                $stmtT->execute([$p['id']]);
                $counts = ['PENDING' => 0, 'IN_PROGRESS' => 0, 'DONE' => 0, 'FAILED' => 0];
                while ($row = $stmtT->fetch(PDO::FETCH_ASSOC)) {
                    $counts[$row['status']] = $row['c'];
                }
                $isAct = ($p['id'] == $activeProject['id']) ? " ⭐" : "";
                $reply .= "📁 <b>" . htmlspecialchars($p['name']) . " (#" . ($p['code'] ?: $p['id']) . ")$isAct:</b>\n"
                       . "   ⏳ Menunggu: {$counts['PENDING']} | ⚡ Dikerjakan: {$counts['IN_PROGRESS']} | ✅ Selesai: {$counts['DONE']}\n\n";
            }
            $reply .= "<i>Ketik <code>/status &lt;kode&gt;</code> untuk rincian proyek tertentu.</i>";
        }

        callTelegram($project['bot_token'], 'sendMessage', [
            'chat_id' => $incomingChatId,
            'text' => $reply,
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $messageId
        ]);
        echo "OK"; exit;
    }

    // Command /help
    if (strpos($text, '/help') === 0 || strpos($text, '/start') === 0) {
        $cmds = getProjectCommands($activeProject);
        $cmdLines = [];
        $cmdLines[] = "• <code>/projects</code> - Daftar proyek terhubung (#a, #b)";
        $cmdLines[] = "• <code>/use &lt;kode&gt;</code> - Ganti proyek aktif di chat ini";
        $cmdLines[] = "• <code>/executor</code> - Ganti mesin eksekutor (VS Code / Shell OS)";
        $cmdLines[] = "• <code>/lapo &lt;tag&gt;</code> - Cek apa yang sedang dikerjakan tag";
        $cmdLines[] = "• <code>/status</code> - Cek status antrean proyek";
        foreach ($cmds as $c) {
            $cmdLines[] = "• <code>/{$c['command']}</code> - {$c['description']}";
        }
        $reply = "🤖 <b>MENU PERINTAH TELE-VS-CODE BOT</b>\n"
               . "📁 <b>Proyek Aktif:</b> <code>" . htmlspecialchars($activeProject['name']) . "</code> (#" . ($activeProject['code'] ?: $activeProject['id']) . ")\n\n"
               . implode("\n", $cmdLines) . "\n\n"
               . "💡 <b>CARA KIRIM TUGAS KE 2 PROYEK (Folder A & B):</b>\n"
               . "• <code>#a &lt;instruksi&gt;</code> -> Masuk ke Folder A\n"
               . "• <code>#b &lt;instruksi&gt;</code> -> Masuk ke Folder B\n"
               . "• Atau ketik <code>/use b</code> untuk pindah proyek aktif!";
        callTelegram($project['bot_token'], 'sendMessage', [
            'chat_id' => $incomingChatId,
            'text' => $reply,
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $messageId
        ]);
        echo "OK"; exit;
    }

    // Command /aktivasi, /setup, /panduan: Petunjuk Lengkap Aktivasi Bot & VS Code
    if (preg_match('/^\/(aktivasi|setup|panduan|install)(@\w+)?/i', $text)) {
        $pName = htmlspecialchars($activeProject['name']);
        $pKey = htmlspecialchars($activeProject['project_key']);
        $pCode = htmlspecialchars($activeProject['code'] ?: 'a');
        $reply = "🚀 <b>PANDUAN LENGKAP AKTIVASI BOT TELE-VS-CODE</b>\n"
               . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
               . "Hai <b>" . htmlspecialchars($sender) . "</b>! Berikut adalah langkah mudah mengaktifkan dan menghubungkan bot ini ke VS Code Anda:\n\n"
               . "<b>1️⃣ Langkah 1: Catat User ID Telegram Anda</b>\n"
               . "• User ID Anda: <code>$senderUserId</code>\n"
               . "• Pastikan User ID ini sudah ditambahkan ke menu <b>Whitelist</b> di Web Dashboard agar Anda memiliki hak akses penuh.\n\n"
               . "<b>2️⃣ Langkah 2: Buka Dashboard & Buat Proyek</b>\n"
               . "• Buka: <a href=\"https://biru.co.id/tele-vs-code/\">https://biru.co.id/tele-vs-code/</a>\n"
               . "• Masukkan Bot Token dari @BotFather dan tentukan Kode Tag (contoh: <code>#$pCode</code>).\n"
               . "• Salin <b>API Secret Key</b> dan <b>Project Key</b> (<code>$pKey</code>).\n\n"
               . "<b>3️⃣ Langkah 3: Pasang Ekstensi di VS Code</b>\n"
               . "• Unduh paket ekstensi <code>tele-vs-code.vsix</code> dari Dashboard atau cari <i>Telegram VSC Agents</i> di VS Code Marketplace.\n"
               . "• Buka command palette di VS Code: <code>Ctrl+Shift+P</code> &gt; pilih <i>Install from VSIX</i>.\n\n"
               . "<b>4️⃣ Langkah 4: Hubungkan ke Server Biru</b>\n"
               . "• Di pojok kanan bawah VS Code, klik ikon <b>((·)) Tele-vs-Code</b>.\n"
               . "• Pilih <b>Hubungkan ke Server Biru (Setup Wizard)</b> lalu tempel API Secret dan Project Key.\n\n"
               . "<b>5️⃣ Langkah 5: Mulai Bekerja!</b>\n"
               . "• Kirim pesan teks atau pesan suara (Voice Note) di chat ini:\n"
               . "  <code>#$pCode buatkan fitur login</code>\n"
               . "• AI Agent (Antigravity) di VS Code akan langsung mengerjakan tugas Anda secara otonom sampai 100% tuntas!\n\n"
               . "💡 <i>Ketik <code>/help</code> untuk melihat daftar lengkap perintah bot.</i>";

        callTelegram($project['bot_token'], 'sendMessage', [
            'chat_id' => $incomingChatId,
            'text' => $reply,
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $messageId
        ]);
        echo "OK"; exit;
    }

    // Command /ping
    if (strpos($text, '/ping') === 0) {
        $reply = "🏓 <b>Pong!</b> Bot & Server aktif untuk proyek <b>" . htmlspecialchars($activeProject['name']) . "</b>.\n🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $tzAbbr";
        callTelegram($project['bot_token'], 'sendMessage', [
            'chat_id' => $incomingChatId,
            'text' => $reply,
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $messageId
        ]);
        echo "OK"; exit;
    }

    // Command /model: Cek akun email aktif dan status kuota model AI (Requirement: tambahakan akun email aktif model untuk perintah /model)
    if (preg_match('/^\/model(@\w+)?(?:\s+(.+))?$/is', $text, $mModel)) {
        $modelArg = trim($mModel[2] ?? '');

        // Ambil akun email pemilik proyek / akun terhubung
        $ownerEmail = '';
        if (!empty($activeProject['user_id'])) {
            $stmtUser = $db->prepare("SELECT email FROM users WHERE id = ?");
            $stmtUser->execute([$activeProject['user_id']]);
            $ownerEmail = $stmtUser->fetchColumn() ?: '';
        }
        if (empty($ownerEmail) && !empty($user['email'])) {
            $ownerEmail = $user['email'];
        }

        // Cek jika ada custom model_email di proyek
        $activeModelEmail = !empty($activeProject['model_email']) ? $activeProject['model_email'] : $ownerEmail;

        // Jika user memberikan input email baru: /model <email> atau /model set <email>
        $confirmMsg = "";
        $cleanArgEmail = preg_replace('/^set\s+/i', '', $modelArg);
        if ($cleanArgEmail && filter_var($cleanArgEmail, FILTER_VALIDATE_EMAIL)) {
            $newEmail = strtolower($cleanArgEmail);
            try {
                $updM = $db->prepare("UPDATE projects SET model_email = ?, updated_at = ? WHERE id = ?");
                $updM->execute([$newEmail, date('Y-m-d H:i:s'), $activeProject['id']]);
                $activeModelEmail = $newEmail;
                $activeProject['model_email'] = $newEmail;

                $confirmMsg = "✅ <b>Akun Email Model Berhasil Diperbarui:</b>\n"
                            . "• <b>Email Baru:</b> <code>$newEmail</code>\n"
                            . "• <b>Proyek:</b> <code>" . htmlspecialchars($activeProject['name']) . "</code> (#" . ($activeProject['code'] ?: 'a') . ")\n\n";
            } catch (Exception $e) {}
        }

        $pTag = $activeProject['code'] ?: 'a';
        $pName = htmlspecialchars($activeProject['name']);
        $pKey = htmlspecialchars($activeProject['project_key']);
        $workspaceFolder = !empty($activeProject['last_workspace_folder']) ? $activeProject['last_workspace_folder'] : '(Belum ada koneksi VS Code)';

        // Hitung metrik tugas riil untuk akurasi status kuota
        $stmtToday = $db->prepare("SELECT COUNT(*) FROM tasks WHERE project_id = ? AND status = 'DONE' AND updated_at >= date('now', 'start of day')");
        $stmtToday->execute([$activeProject['id']]);
        $doneToday = (int)$stmtToday->fetchColumn();

        $stmtWeek = $db->prepare("SELECT COUNT(*) FROM tasks WHERE project_id = ? AND status = 'DONE' AND updated_at >= date('now', '-7 days')");
        $stmtWeek->execute([$activeProject['id']]);
        $doneWeek = (int)$stmtWeek->fetchColumn();

        $stmtActive = $db->prepare("SELECT COUNT(*) FROM tasks WHERE project_id = ? AND status IN ('PENDING', 'IN_PROGRESS')");
        $stmtActive->execute([$activeProject['id']]);
        $activeCount = (int)$stmtActive->fetchColumn();

        $reply = ($confirmMsg ? $confirmMsg : "")
               . "🧠 <b>AI MODEL & STATUS KUOTA AKTIF</b>\n"
               . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
               . "👤 <b>Akun Email Model:</b> <code>" . htmlspecialchars($activeModelEmail ?: 'iam@usm.rip') . "</code>\n"
               . "🤖 <b>Agent Aktif:</b> <code>Antigravity AI Agent</code>\n"
               . "📁 <b>Target Proyek:</b> <code>$pName</code> (#$pTag)\n"
               . "💻 <b>Workspace:</b> <code>" . htmlspecialchars($workspaceFolder) . "</code>\n\n"
               . "📊 <b>Akurasi Status & Kuota AI:</b>\n"
               . "• <b>Status Model:</b> 🟢 <b>100% Aktif & Siap Kerja</b>\n"
               . "• <b>Mesin AI:</b> Google Gemini 2.5 Pro / Flash & Claude 3.5\n"
               . "• <b>Tugas Tuntas Hari Ini:</b> $doneToday tugas\n"
               . "• <b>Tugas Tuntas 7 Hari Terakhir:</b> $doneWeek tugas\n"
               . "• <b>Antrean Aktif Saat Ini:</b> $activeCount tugas\n"
               . "• <b>Batas Kuota:</b> 🟢 Normal (Dalam batas kuota langganan & fair use)\n\n"
               . "💡 <i>Ketik <code>/model &lt;email_baru&gt;</code> untuk memperbarui akun email model AI.</i>\n"
               . "🎯 <i>Sematkan <code>@antigravity</code>, <code>@claude</code>, <code>@copilot</code>, <code>@cursor</code> untuk delegasi ke agent tertentu.</i>\n"
               . "🟢 <i>Agent standby dan siap mengeksekusi instruksi coding secara otonom.</i>";

        callTelegram($project['bot_token'], 'sendMessage', [
            'chat_id' => $incomingChatId,
            'text' => $reply,
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $messageId
        ]);
        echo "OK"; exit;
    }

    // Command /cancel atau /batal atau /<kode_proyek> batalkan: Batalkan tugas aktif
    $cleanText = trim($text);
    $directCancelTarget = null;
    $targetTaskId = null;
    $isDirectCancelCmd = false;

    if (preg_match('/^\/(?:cancel|batal|stop|hapus)(?:@\w+)?(?:\s+(.+))?$/is', $cleanText, $mCancel)) {
        $isDirectCancelCmd = true;
        $cancelArg = trim($mCancel[1] ?? '');
        if (preg_match('/(#?TASK-[A-Za-z0-9_-]+)/i', $cancelArg, $mCid)) {
            $targetTaskId = $mCid[1];
        } elseif ($cancelArg) {
            foreach ($allProjects as $idx => $p) {
                $pCode = strtolower($p['code'] ?: chr(97 + ($idx % 26)));
                if (strtolower($cancelArg) === $pCode || $cancelArg === (string)$p['id']) {
                    $directCancelTarget = $p;
                    break;
                }
            }
        }
        if (!$directCancelTarget) {
            $directCancelTarget = $activeProject;
        }
    } elseif (preg_match('/^\/([a-zA-Z0-9_-]+)(?:@\w+)?\s+(batal|batalkan|cancel|stop)$/is', $cleanText, $mPrjCancel)) {
        $slashPrj = strtolower($mPrjCancel[1]);
        foreach ($allProjects as $idx => $p) {
            $pCode = strtolower($p['code'] ?: chr(97 + ($idx % 26)));
            if ($slashPrj === $pCode || $slashPrj === (string)$p['id']) {
                $isDirectCancelCmd = true;
                $directCancelTarget = $p;
                break;
            }
        }
    }

    if ($isDirectCancelCmd && $directCancelTarget) {
        $pTz = !empty($directCancelTarget['timezone']) ? $directCancelTarget['timezone'] : $effectiveTz;
        $pTzAbbr = getTimezoneAbbr($pTz);
        $pName = htmlspecialchars($directCancelTarget['name'] ?: 'Tele-vs-Code');

        $curTaskToCancel = null;
        if ($targetTaskId) {
            $stmtC = $db->prepare("SELECT * FROM tasks WHERE id = ? LIMIT 1");
            $stmtC->execute([$targetTaskId]);
            $curTaskToCancel = $stmtC->fetch(PDO::FETCH_ASSOC);
            $stmtC->closeCursor();
        } else {
            // Ambil tugas terakhir yang masih IN_PROGRESS atau PENDING pada proyek ini
            $stmtC = $db->prepare("SELECT * FROM tasks WHERE project_id = ? AND status IN ('IN_PROGRESS', 'PENDING') ORDER BY id DESC LIMIT 1");
            $stmtC->execute([$directCancelTarget['id']]);
            $curTaskToCancel = $stmtC->fetch(PDO::FETCH_ASSOC);
            $stmtC->closeCursor();
        }

        if ($curTaskToCancel) {
            if ($curTaskToCancel['status'] === 'DONE') {
                $reply = "⚠️ <b>TUGAS SUDAH SELESAI</b>\n\n"
                       . "Tugas <code>{$curTaskToCancel['id']}</code> sudah tuntas 100% sebelumnya dan tidak dapat dibatalkan.";
            } else {
                $now = date('Y-m-d H:i:s');
                $cancelNote = "Dibatalkan oleh pemohon ($sender) via Telegram Command";
                $upd = $db->prepare("UPDATE tasks SET status = 'FAILED', notes = ?, result = ?, updated_at = ? WHERE id = ?");
                $upd->execute([$cancelNote, $cancelNote, $now, $curTaskToCancel['id']]);
                $upd->closeCursor();

                $reply = "🚫 <b>TUGAS BERHASIL DIBATALKAN!</b>\n"
                       . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                       . "🆔 <b>ID Tugas:</b> <code>{$curTaskToCancel['id']}</code>\n"
                       . "📁 <b>Proyek:</b> <code>$pName</code>\n"
                       . "👤 <b>Pemohon:</b> " . htmlspecialchars($sender) . "\n"
                       . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $pTzAbbr\n"
                       . "📌 <b>Status:</b> <code>[FAILED / DIBATALKAN]</code>\n\n"
                       . "<i>Tugas telah dihentikan dan dikeluarkan dari antrean eksekusi AI Agent.</i>";
            }
        } else {
            $reply = "ℹ️ <b>TIDAK ADA TUGAS AKTIF</b>\n\n"
                   . "Tidak ditemukan tugas yang sedang berjalan atau menunggu di proyek <b>$pName</b>.";
        }

        callTelegram($project['bot_token'], 'sendMessage', [
            'chat_id' => $incomingChatId,
            'text' => $reply,
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $messageId ?? null
        ]);
        echo "OK"; exit;
    }

    // Tangkap Tugas Baru (Teks atau Lampiran Media)
    $instruction = '';
    $explicitProject = null;
    $taskCommand = '';

    if (preg_match('/^\/(?:task|laporan|report|bug|order|perintah|misi)(@\w+)?(?:\s+(.+))?$/is', $text, $matches)) {
        $rawSlash = explode('@', substr($text, 1))[0];
        $taskCommand = '/' . strtolower(explode(' ', $rawSlash)[0]);
        $instruction = trim($matches[2] ?? '');
    } elseif (preg_match('/^\/(cron|schedule)(@\w+)?(?:\s+(.+))?$/is', $text, $matches)) {
        $taskCommand = '/' . strtolower($matches[1]);
        $instruction = trim($matches[3] ?? '');
        if ($instruction) {
            $instruction = "[Cron Task / Jadwal Berkala] " . $instruction;
        } else {
            callTelegram($activeProject['bot_token'], 'sendMessage', [
                'chat_id' => $incomingChatId,
                'text' => "⏰ <b>Format Perintah Cron / Schedule AI Agent:</b>\n\n<code>/cron @antigravity &lt;perintah berkala&gt;</code>\n\n<i>Contoh:</i>\n<code>/cron @antigravity cek error log dan status server</code>",
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $messageId
            ]);
            echo "OK"; exit;
        }
    } elseif ($text && $text[0] !== '/') {
        $instruction = $text;
    } elseif (preg_match('/^\/([a-zA-Z0-9_-]+)(@\w+)?(?:\s+(.+))?$/is', $text, $matches)) {
        // Cek jika perintah adalah kode proyek, misal: /a Kerjakan tugas ini atau /biru Kerjakan tugas ini
        $slashCode = strtolower($matches[1]);
        foreach ($allProjects as $idx => $p) {
            $pCode = strtolower($p['code'] ?: chr(97 + ($idx % 26)));
            $pIndexLetter = chr(97 + ($idx % 26));
            if ($slashCode === $pCode || $slashCode === $pIndexLetter || $slashCode === (string)$p['id']) {
                $explicitProject = $p;
                $taskCommand = '/' . $slashCode;
                $instruction = trim($matches[3] ?? '');
                break;
            }
        }
        if (!$explicitProject) {
            if ($attachmentType || $isPrivateChat) {
                $instruction = $text;
            }
        }
    } elseif ($attachmentType) {
        $typeLabel = ($attachmentType === 'photo') ? 'Foto' : (($attachmentType === 'video') ? 'Video' : 'Dokumen');
        $instruction = "Tolong analisis dan proses file $typeLabel terlampir ini.";
    }

    if ($instruction || $attachmentType) {
        // Jika belum ditentukan dari slash command, cek awalan tag di teks (#a, [a], (a), a:, folder-a:)
        $targetProject = $explicitProject ?: $activeProject;
        $matchedTag = '';

        if (!$explicitProject && $instruction) {
            foreach ($allProjects as $idx => $p) {
                $pCode = strtolower($p['code'] ?: chr(97 + ($idx % 26)));
                $pIndexLetter = chr(97 + ($idx % 26));
                $pId = (string)$p['id'];
                $pSlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $p['name']));

                $pKey = strtolower($p['project_key'] ?? '');
                $candidates = array_unique(array_filter([$pCode, $pIndexLetter, $pKey, $pId, $pSlug]));
                foreach ($candidates as $cand) {
                    if (strlen($cand) < 1) continue;
                    // Format: #a ..., [a] ..., (a) ..., a: ..., a - ...
                    $regex = '/^\s*(?:[#@\[\(]' . preg_quote($cand, '/') . '[\]\)]?[:\s-]*|' . preg_quote($cand, '/') . '[:\s-]+)\s*(.*)$/is';
                    if (preg_match($regex, $instruction, $mTag)) {
                        $targetProject = $p;
                        $matchedTag = $cand;
                        $taskCommand = '#' . $cand;
                        if (!empty($mTag[1])) {
                            $instruction = trim($mTag[1]);
                        }
                        break 2;
                    }
                }
            }
        }

        if (!$taskCommand) {
            $taskCommand = '#' . strtolower($targetProject['code'] ?: 'a');
        }

        // Security Guard Check: Cegah perintah berbahaya (reboot, shell, dll) jika bukan dari akun iam@usm.rip
        if (isRestrictedDangerousCommand($text) || isRestrictedDangerousCommand($instruction)) {
            $ownerEmail = strtolower($targetProject['email'] ?? ($project['email'] ?? ''));
            if (!isAuthorizedSecurityUser($ownerEmail)) {
                $secBlockMsg = "⛔ <b>AKSES DITOLAK: KEAMANAN SISTEM</b>\n"
                             . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                             . "🚨 <b>Perintah Berbahaya Dicegah:</b>\n"
                             . "Perintah sistem tingkat rendah (seperti <code>reboot</code>, <code>shell</code>, atau modifikasi destruktif) telah dicegah oleh <b>Security Guard</b> demi keamanan server dan workspace.\n\n"
                             . "🔒 <b>Hak Akses Terbatas:</b>\n"
                             . "Perintah ini HANYA diizinkan untuk akun terverifikasi (<code>iam@usm.rip</code>).\n\n"
                             . "🛑 <i>Instruksi dibatalkan dan tidak dimasukkan ke antrean tugas.</i>";

                callTelegram($targetProject['bot_token'] ?? $project['bot_token'], 'sendMessage', [
                    'chat_id' => $incomingChatId,
                    'text' => $secBlockMsg,
                    'parse_mode' => 'HTML',
                    'reply_to_message_id' => $messageId
                ]);
                echo "OK";
                exit;
            }
        }

        $taskId = '#TASK-' . substr(bin2hex(random_bytes(2)), 0, 4);
        $now = date('Y-m-d H:i:s');
        $taskSender = $sender;
        if ($isPrivateChat) {
            $displayName = !empty($whitelistedUser['name']) ? $whitelistedUser['name'] : $sender;
            $taskSender = $displayName . ' (Private Chat)';
        }

        // ======================================================================
        // MULTI-FILE UPLOAD TELEGRAM (ALBUM / MEDIA GROUP & PENGIRIMAN BERUNTUN)
        // ======================================================================
        $mgLockFp = null;
        if ($attachmentType) {
            $lockKey = $mediaGroupId !== ''
                ? ('mg_' . $targetProject['id'] . '_' . $mediaGroupId)
                : ('seq_' . $targetProject['id'] . '_' . md5($taskSender));
            $mgLockPath = sys_get_temp_dir() . '/tvsc_' . md5($lockKey) . '.lock';
            $mgLockFp = @fopen($mgLockPath, 'c+');
            if ($mgLockFp) {
                @flock($mgLockFp, LOCK_EX);
            }

            $existingMultiTask = null;
            if ($mediaGroupId !== '') {
                // Mode 1: Pengiriman Album Media Group (Foto/Video multi-file sekaligus oleh Telegram)
                $stmtMg = $db->prepare("SELECT * FROM tasks WHERE project_id = ? AND media_group_id = ? AND created_at >= datetime('now', '-180 seconds') ORDER BY created_at DESC LIMIT 1");
                $stmtMg->execute([$targetProject['id'], $mediaGroupId]);
                $existingMultiTask = $stmtMg->fetch(PDO::FETCH_ASSOC);
                $stmtMg->closeCursor();
            } else {
                // Mode 2: Pengiriman Berkas Beruntun (Dokumen PDF/ZIP/TXT atau media terpisah dalam 60 detik)
                // Jika pesan bukan merupakan perintah baru eksplisit (/ atau #), otomatis satukan ke tugas PENDING sebelumnya
                $isExplicitNewCommand = preg_match('/^\/([a-zA-Z0-9_-]+)/', $text) || preg_match('/^#(?!TASK-)([a-zA-Z0-9_-]+)/i', $text);
                if (!$isExplicitNewCommand) {
                    $stmtSeq = $db->prepare("SELECT * FROM tasks WHERE project_id = ? AND sender = ? AND status = 'PENDING' AND (attachment_url != '' OR attachments_json != '') AND created_at >= datetime('now', '-60 seconds') ORDER BY created_at DESC LIMIT 1");
                    $stmtSeq->execute([$targetProject['id'], $taskSender]);
                    $existingMultiTask = $stmtSeq->fetch(PDO::FETCH_ASSOC);
                    $stmtSeq->closeCursor();
                }
            }

            if ($existingMultiTask) {
                $mergeTaskId = $existingMultiTask['id'];
                $botTok = !empty($targetProject['bot_token']) ? $targetProject['bot_token'] : $project['bot_token'];
                list($dlUrl, $dlName) = $downloadTelegramAttachment($botTok, $attachmentFileId, $attachmentName, $mergeTaskId);
                $attachmentUrl = $dlUrl;
                $attachmentName = $dlName;

                $existingAtts = parseTaskAttachments($existingMultiTask);
                if ($attachmentUrl) {
                    $existingAtts[] = [
                        'url' => $attachmentUrl,
                        'type' => $attachmentType,
                        'name' => $attachmentName
                    ];
                }

                $typeLabel = ($attachmentType === 'photo') ? 'Foto' : (($attachmentType === 'video') ? 'Video' : (($attachmentType === 'audio' || $attachmentType === 'voice') ? 'Audio' : 'Dokumen'));
                $attachmentHeader = "[$typeLabel Terlampir: $attachmentName" . ($attachmentUrl ? " ($attachmentUrl)" : "") . "]";

                $mergedInst = $existingMultiTask['instruction'];
                if ($text !== '' && preg_match('/Tolong analisis dan proses (?:file|[0-9]+ file) .* terlampir ini\./i', $mergedInst)) {
                    $mergedInst = preg_replace('/Tolong analisis dan proses (?:file|[0-9]+ file) .* terlampir ini\./i', $instruction, $mergedInst);
                } elseif ($text !== '' && strpos($mergedInst, $text) === false) {
                    $mergedInst .= "\n[Tambahan lampiran $attachmentName]: " . $text;
                }
                $mergedInst = $attachmentHeader . "\n" . $mergedInst;

                $primaryUrl = !empty($existingMultiTask['attachment_url']) ? $existingMultiTask['attachment_url'] : ($existingAtts[0]['url'] ?? $attachmentUrl);
                $primaryType = !empty($existingMultiTask['attachment_type']) ? $existingMultiTask['attachment_type'] : ($existingAtts[0]['type'] ?? $attachmentType);
                $primaryName = !empty($existingMultiTask['attachment_name']) ? $existingMultiTask['attachment_name'] : ($existingAtts[0]['name'] ?? $attachmentName);
                $newAttsJson = !empty($existingAtts) ? json_encode($existingAtts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

                $updMg = $db->prepare("UPDATE tasks SET instruction = ?, attachment_url = ?, attachment_type = ?, attachment_name = ?, attachments_json = ?, updated_at = ? WHERE id = ?");
                safeDbExecute($updMg, [$mergedInst, $primaryUrl, $primaryType, $primaryName, $newAttsJson, $now, $mergeTaskId]);

                if ($mgLockFp) {
                    @flock($mgLockFp, LOCK_UN);
                    @fclose($mgLockFp);
                    $mgLockFp = null;
                }

                if (empty($targetProject['block_notify'])) {
                    $totalFiles = count($existingAtts);
                    $mergeReply = "📎 <b>FILE LAMPIRAN KE-{$totalFiles} DIGABUNGKAN!</b>\n"
                                . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                                . "🆔 <b>ID Tugas:</b> <code>$mergeTaskId</code>\n"
                                . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($targetProject['name']) . "</code>\n"
                                . "📄 <b>File Baru:</b> <code>" . htmlspecialchars($attachmentName) . "</code> ($typeLabel)\n"
                                . ($attachmentUrl ? "🔗 <b>Tautan File:</b> <a href=\"$attachmentUrl\">Buka Lampiran</a>\n" : "")
                                . "📦 <b>Total Lampiran:</b> <code>{$totalFiles} file</code>\n\n"
                                . "<i>Seluruh file lampiran telah disatukan dalam tugas $mergeTaskId untuk diproses oleh AI Agent.</i>";

                    callTelegram($botTok, 'sendMessage', [
                        'chat_id' => $incomingChatId,
                        'text' => $mergeReply,
                        'parse_mode' => 'HTML',
                        'reply_to_message_id' => $messageId,
                        'disable_web_page_preview' => true
                    ]);
                }
                echo "OK";
                exit;
            }
        }

        // ======================================================================
        // ATURAN HEMAT KUOTA & ANTI PERINTAH BERULANG (QUOTA GUARD / DEDUPLICATION)
        // ======================================================================
        // Cek apakah perintah serupa sedang aktif (PENDING / WAITING / IN_PROGRESS)
        // atau baru saja selesai (DONE) dalam kurun waktu 60 detik (cooldown spam)
        $cleanInstLower = strtolower(trim(preg_replace('/\s+/', ' ', $instruction)));

        if ($cleanInstLower !== '' && !$attachmentType) {
            $stmtDup = $db->prepare("SELECT id, status, instruction, created_at, updated_at FROM tasks WHERE project_id = ? AND status IN ('PENDING', 'WAITING', 'IN_PROGRESS') ORDER BY id DESC LIMIT 10");
            $stmtDup->execute([$targetProject['id']]);
            $activeTasks = $stmtDup->fetchAll(PDO::FETCH_ASSOC);
            $stmtDup->closeCursor();

            $isDuplicate = false;
            $dupTask = null;
            foreach ($activeTasks as $at) {
                $existingText = strtolower(trim(preg_replace('/\s+/', ' ', $at['instruction'] ?? '')));
                if ($existingText === $cleanInstLower) {
                    $isDuplicate = true;
                    $dupTask = $at;
                    break;
                }
            }

            // Jika tidak ada di antrean aktif, cek apakah baru saja selesai (cooldown 60 detik)
            if (!$isDuplicate) {
                $stmtRecent = $db->prepare("SELECT id, status, instruction, updated_at FROM tasks WHERE project_id = ? AND status = 'DONE' AND updated_at >= datetime('now', '-60 seconds') ORDER BY id DESC LIMIT 5");
                $stmtRecent->execute([$targetProject['id']]);
                $recentTasks = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
                $stmtRecent->closeCursor();

                foreach ($recentTasks as $rt) {
                    $existingText = strtolower(trim(preg_replace('/\s+/', ' ', $rt['instruction'] ?? '')));
                    if ($existingText === $cleanInstLower) {
                        $isDuplicate = true;
                        $dupTask = $rt;
                        break;
                    }
                }
            }

            if ($isDuplicate && $dupTask) {
                if ($mgLockFp) {
                    @flock($mgLockFp, LOCK_UN);
                    @fclose($mgLockFp);
                }
                $statusLabel = ($dupTask['status'] === 'IN_PROGRESS') ? 'Sedang Dikerjakan' : (($dupTask['status'] === 'DONE') ? 'Baru Saja Selesai' : 'Dalam Antrean');
                $replyDup = "⚠️ <b>PERINTAH SERUPA SUDAH ADA (QUOTA GUARD)</b>\n"
                          . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                          . "💡 <b>Penghematan Kuota AI Agent:</b>\n"
                          . "Perintah ini tidak ditambahkan lagi karena tugas serupa sudah tercatat sebelumnya untuk menghemat pemakaian kuota/token AI Agent.\n\n"
                          . "📌 <b>Task ID Terkait:</b> <code>" . htmlspecialchars($dupTask['id']) . "</code>\n"
                          . "📊 <b>Status Saat Ini:</b> <code>" . $statusLabel . " (" . htmlspecialchars($dupTask['status']) . ")</code>\n\n"
                          . "⏳ <i>Harap tunggu hingga tugas tersebut selesai dikerjakan.</i>";

                callTelegram($targetProject['bot_token'] ?? $project['bot_token'], 'sendMessage', [
                    'chat_id' => $incomingChatId,
                    'text' => $replyDup,
                    'parse_mode' => 'HTML',
                    'reply_to_message_id' => $messageId
                ]);
                echo "OK";
                exit;
            }
        }

        // Unduh file lampiran jika ada
        $initialAttachmentsJson = '';
        if ($attachmentFileId && !empty($targetProject['bot_token'])) {
            list($dlUrl, $dlName) = $downloadTelegramAttachment($targetProject['bot_token'], $attachmentFileId, $attachmentName, $taskId);
            $attachmentUrl = $dlUrl;
            $attachmentName = $dlName;
            if ($attachmentUrl) {
                $initialAttachmentsJson = json_encode([[
                    'url' => $attachmentUrl,
                    'type' => $attachmentType ?: 'document',
                    'name' => $attachmentName
                ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        // Format instruksi agar AI Agent langsung mengetahui keberadaan dan URL lampiran
        if ($attachmentType) {
            $typeLabel = ($attachmentType === 'photo') ? 'Foto' : (($attachmentType === 'video') ? 'Video' : (($attachmentType === 'audio' || $attachmentType === 'voice') ? 'Audio' : 'Dokumen'));
            $attachmentHeader = "[$typeLabel Terlampir: $attachmentName" . ($attachmentUrl ? " ($attachmentUrl)" : "") . "]";
            $instruction = $attachmentHeader . "\n" . $instruction;
        }

        // Deteksi Agent spesifik dari perintah (misal: @antigravity, @antigrafity, @claude, @copilot, dll)
        $assignedAgent = !empty($currentAgent) ? $currentAgent : 'Antigravity AI Agent'; // Default: Agent Aktif saat ini
        if (preg_match('/(?:^|\s)@([a-zA-Z0-9_-]+)/i', $instruction, $mAgt)) {
            $rawAgent = strtolower($mAgt[1]);
            $instruction = trim(preg_replace('/(?:^|\s)@' . preg_quote($mAgt[1], '/') . '/i', '', $instruction));

            // Dukungan alias & variasi ejaan fonetik (antigrafity, antigraviti, antigravity, dll)
            if (preg_match('/^antigra[fv]i?t[yi]?$/i', $rawAgent) || in_array($rawAgent, ['antigrav', 'anti-gravity'])) {
                $assignedAgent = 'Antigravity AI Agent';
            } elseif (in_array($rawAgent, ['claude', 'claud'])) {
                $assignedAgent = 'Claude Agent';
            } elseif (in_array($rawAgent, ['copilot', 'copilote', 'github-copilot'])) {
                $assignedAgent = 'Copilot Agent';
            } elseif (in_array($rawAgent, ['cursor', 'cursor-ai'])) {
                $assignedAgent = 'Cursor Agent';
            } elseif (in_array($rawAgent, ['gemini', 'gemini-cli'])) {
                $assignedAgent = 'Gemini Agent';
            } elseif ($rawAgent === 'roo') {
                $assignedAgent = 'Roo Agent';
            } elseif ($rawAgent === 'cline') {
                $assignedAgent = 'Cline Agent';
            } elseif ($rawAgent === 'codex') {
                $assignedAgent = 'Codex Agent';
            } else {
                $assignedAgent = ucfirst($rawAgent) . ' Agent';
            }
        } elseif (preg_match('/(?:--agent|\[agent:\s*)([\w\s-]+)\]?/i', $instruction, $mAgt)) {
            $rawAgent = trim($mAgt[1]);
            $instruction = trim(preg_replace('/(?:--agent|\[agent:\s*)([\w\s-]+)\]?/i', '', $instruction));
            if (preg_match('/^antigra[fv]i?t[yi]?$/i', strtolower($rawAgent))) {
                $assignedAgent = 'Antigravity AI Agent';
            } else {
                $assignedAgent = ucfirst($rawAgent) . ' Agent';
            }
        }

        // Deteksi Prioritas Tugas (URGENT, HIGH, NORMAL, LOW)
        $taskPriority = 'NORMAL';
        if (preg_match('/(?:--priority\s*|\[prioritas:\s*|\[priority:\s*|\[)(URGENT|HIGH|NORMAL|LOW)\]?/i', $instruction, $mPrio)) {
            $taskPriority = strtoupper($mPrio[1]);
            $instruction = trim(preg_replace('/(?:--priority\s*|\[prioritas:\s*|\[priority:\s*|\[)' . preg_quote($mPrio[1], '/') . '\]?/i', '', $instruction));
        } elseif (preg_match('/^(URGENT|DARURAT)[:\s-]+(.*)$/is', $instruction, $mPrio)) {
            $taskPriority = 'URGENT';
            $instruction = trim($mPrio[2]);
        }

        $maxInsRetries = 10;
        for ($insAttempt = 1; $insAttempt <= $maxInsRetries; $insAttempt++) {
            try {
                $stmtIns = $db->prepare("INSERT INTO tasks (id, user_id, project_id, instruction, sender, status, created_at, updated_at, attachment_url, attachment_type, attachment_name, attachments_json, media_group_id, command, assigned_agent, priority) VALUES (?, ?, ?, ?, ?, 'PENDING', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtIns->execute([$taskId, $targetProject['user_id'], $targetProject['id'], $instruction, $taskSender, $now, $now, $attachmentUrl, $attachmentType, $attachmentName, $initialAttachmentsJson, $mediaGroupId, $taskCommand, $assignedAgent, $taskPriority]);
                $stmtIns->closeCursor();
                break;
            } catch (PDOException $pdoErr) {
                if ($insAttempt === $maxInsRetries) {
                    if ($mgLockFp) {
                        @flock($mgLockFp, LOCK_UN);
                        @fclose($mgLockFp);
                        $mgLockFp = null;
                    }
                    throw $pdoErr;
                }
                // Jika database sedang terkunci/sibuk, reset koneksi PDO dan tunggu jeda progresif
                $db = getDb(true);
                usleep(200000 * $insAttempt); // 200ms, 400ms, 600ms...
            }
        }
        if ($mgLockFp) {
            @flock($mgLockFp, LOCK_UN);
            @fclose($mgLockFp);
            $mgLockFp = null;
        }

        // Cek jika pengiriman notifikasi Telegram diblokir oleh admin
        if (empty($targetProject['block_notify'])) {
            $attachLine = "";
            if ($attachmentType) {
                $typeLabel = ($attachmentType === 'photo') ? 'Foto' : (($attachmentType === 'video') ? 'Video' : 'Dokumen');
                $attachLine = "📎 <b>Lampiran:</b> " . htmlspecialchars($attachmentName) . " (Tipe: $typeLabel)\n";
                if ($attachmentUrl) {
                    $attachLine .= "🔗 <b>Tautan File:</b> <a href=\"$attachmentUrl\">Buka Lampiran</a>\n";
                }
            }

            $tipMulti = "";
            if (count($allProjects) > 1 && !$matchedTag && !$explicitProject) {
                $tipMulti = "\n\n💡 <i>Tip: Masuk ke <b>" . htmlspecialchars($targetProject['name']) . "</b>. Gunakan tag <code>#a</code> / <code>#b</code> atau <code>/use &lt;kode&gt;</code> untuk memilih proyek lain.</i>";
            }

            $cronWarningNotice = "";
            $isCronInstruction = ($taskCommand === '/cron' || $taskCommand === '/schedule' || preg_match('/(?:setiap\s+\d+\s*(?:menit|detik|jam)|secara\s+berkala|cron|schedule)/i', $instruction));
            if ($isCronInstruction) {
                $cronWarningNotice = "\n\n⚠️ <b>PERINGATAN KUOTA TOKEN (CRON):</b>\n"
                                   . "<i>Menjalankan tugas AI Agent secara berkala (cron loop) menguras kuota kredit token secara masif (setiap iterasi memproses ulang seluruh prompt). Disarankan menggunakan polling worker lokal (0 token).</i>";
            }

            $reply = "📥 <b>TUGAS DITERIMA DI ANTREAN!" . ($isPrivateChat ? " (Private Chat)" : "") . "</b>\n\n"
                   . "🆔 <b>ID:</b> <code>$taskId</code>\n"
                   . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($targetProject['name']) . "</code> (Kode: <code>#" . ($targetProject['code'] ?: $targetProject['id']) . "</code>)\n"
                   . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n"
                   . "👤 <b>Pemohon:</b> " . htmlspecialchars($taskSender) . "\n"
                   . "🤖 <b>Eksekutor:</b> <code>" . htmlspecialchars($assignedAgent) . "</code>\n"
                   . $attachLine . "\n"
                   . "📝 <b>Instruksi:</b>\n<code>" . htmlspecialchars($instruction) . "</code>\n\n"
                   . "⏳ <b>Status:</b> <code>[PENDING]</code>\n"
                   . "<i>Tugas siap ditarik oleh VS Code: " . htmlspecialchars($targetProject['name']) . "</i>"
                   . $cronWarningNotice
                   . $tipMulti;

            sendTelegramWithMedia($targetProject['bot_token'], $incomingChatId, $reply, $attachmentType, $attachmentUrl, $attachmentFileId, $messageId);

            // Jika dari Private Chat dan proyek punya Group Chat terpisah, kirim pemberitahuan ringkas ke grup
            if ($isPrivateChat && !empty($targetProject['chat_id']) && (string)$targetProject['chat_id'] !== $incomingChatId) {
                $groupNotice = "📢 <b>TUGAS BARU VIA PRIVATE CHAT</b>\n\n"
                             . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($targetProject['name']) . "</code>\n"
                             . "🆔 <b>ID:</b> <code>$taskId</code>\n"
                             . "👤 <b>Pemohon:</b> " . htmlspecialchars($taskSender) . "\n"
                             . ($attachmentType ? "📎 <b>Lampiran:</b> " . htmlspecialchars($attachmentName) . " (" . ucfirst($attachmentType) . ")\n" : "")
                             . "📝 <b>Instruksi:</b> " . htmlspecialchars(mb_substr($instruction, 0, 150)) . (mb_strlen($instruction) > 150 ? '...' : '') . "\n"
                             . "⏳ <b>Status:</b> <code>[PENDING]</code>";
                sendTelegramWithMedia($targetProject['bot_token'], $targetProject['chat_id'], $groupNotice, $attachmentType, $attachmentUrl, $attachmentFileId);
            }
        }
    }
    echo "OK"; exit;
    } catch (Throwable $webhookErr) {
        @file_put_contents(__DIR__ . '/webhook_error.log', date('Y-m-d H:i:s') . ' ' . $webhookErr->getMessage() . ' in ' . $webhookErr->getFile() . ':' . $webhookErr->getLine() . "\n", FILE_APPEND);
        // Jangan biarkan Telegram menggantung tanpa balasan
        if (!empty($targetProject['bot_token']) && !empty($incomingChatId)) {
            $errNotice = "⚠️ <b>Gagal Memproses Laporan / Tugas</b>\n"
                       . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                       . "Terjadi kendala teknis saat memproses pesan: <code>" . htmlspecialchars($webhookErr->getMessage()) . "</code>\n\n"
                       . "Silakan coba kirim ulang perintah atau foto Anda.";
            callTelegram($targetProject['bot_token'], 'sendMessage', [
                'chat_id' => $incomingChatId,
                'text' => $errNotice,
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $messageId ?? null
            ]);
        }
        echo "OK"; exit;
    }
}

