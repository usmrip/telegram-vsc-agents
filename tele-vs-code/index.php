<?php
// ==============================================================================
// Platform: Telegram Dev Notifier for VS Code & Antigravity
// URL: https://biru.co.id/tele-vs-code/
// Multi-User, Multi-Project System with Individual Telegram Bots, Custom Menus,
// Project-based Task Queue, Dedicated Settings, and Superadmin User Management.
// ==============================================================================

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// Security Headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Helper: CSRF Protection
function getCsrfToken() {
    if (empty($_SESSION['tvsc_csrf'])) {
        $_SESSION['tvsc_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['tvsc_csrf'];
}

function verifyCsrfToken($token) {
    if (empty($_SESSION['tvsc_csrf']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['tvsc_csrf'], (string)$token);
}

// ------------------------------------------------------------------------------
// Database Path: Sejajar dengan public_html di data/tele-vs-code/tele-vs-code.db
// ------------------------------------------------------------------------------
function resolveDbPath() {
    $cur = __DIR__;
    $parent1 = dirname($cur); // misal /home/domain/public_html atau U:\domains\biru.co.id\public_html
    $parent2 = dirname($parent1); // domain root (sejajar public_html)

    if (basename($parent1) === 'public_html' || is_dir($parent2 . '/public_html')) {
        $dataDir = $parent2 . '/data/tele-vs-code';
    } elseif (is_dir('U:/domains/biru.co.id/data/tele-vs-code')) {
        $dataDir = 'U:/domains/biru.co.id/data/tele-vs-code';
    } else {
        $dataDir = dirname($cur) . '/data/tele-vs-code';
    }

    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0750, true);
        @file_put_contents($dataDir . '/.htaccess', "Deny from all\n");
    }
    return $dataDir . '/tele-vs-code.db';
}

define('DB_FILE', resolveDbPath());
function getLatestVsixFile() {
    $candidates = glob(__DIR__ . '/tele-vs-code-*.vsix');
    if (!empty($candidates)) {
        usort($candidates, function($a, $b) {
            return version_compare(
                preg_replace('/^.*tele-vs-code-|\.vsix$/', '', $b),
                preg_replace('/^.*tele-vs-code-|\.vsix$/', '', $a)
            );
        });
        return $candidates[0];
    }
    return __DIR__ . '/tele-vs-code-1.0.7.vsix';
}
define('VSIX_FILE', getLatestVsixFile());
define('SUPERADMIN_EMAIL', 'iam@usm.rip');

// ------------------------------------------------------------------------------
// 1. Database & Schema Initialization (SQLite)
// ------------------------------------------------------------------------------
function getDb() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA foreign_keys = ON;");
        
        // Table Users
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            whatsapp TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            api_key TEXT UNIQUE NOT NULL,
            bot_token TEXT DEFAULT '',
            chat_id TEXT DEFAULT '',
            project_name TEXT DEFAULT 'My Project',
            timezone TEXT DEFAULT 'Asia/Jakarta',
            is_blocked INTEGER DEFAULT 0,
            block_notify INTEGER DEFAULT 0,
            is_admin INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table Projects (Multi-Project Support per User)
        $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            project_key TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            bot_token TEXT DEFAULT '',
            chat_id TEXT DEFAULT '',
            timezone TEXT DEFAULT '',
            custom_commands TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        // Table Tasks (Assigned to User and Project)
        $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
            id TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            project_id INTEGER DEFAULT 0,
            instruction TEXT NOT NULL,
            sender TEXT DEFAULT 'Telegram',
            status TEXT DEFAULT 'PENDING',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes TEXT,
            result TEXT DEFAULT '',
            command TEXT DEFAULT '',
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        // Table Bot Whitelist (Private Chat per User / Project)
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_whitelist (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            project_id INTEGER DEFAULT 0,
            telegram_user_id TEXT NOT NULL,
            telegram_username TEXT DEFAULT '',
            name TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        // Table Settings (Key-Value)
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            val TEXT DEFAULT ''
        )");

        // Table Password Resets
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            token TEXT UNIQUE NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Migrations: ensure columns exist
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN project_id INTEGER DEFAULT 0"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN result TEXT DEFAULT ''"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN command TEXT DEFAULT ''"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN attachment_url TEXT DEFAULT ''"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN attachment_type TEXT DEFAULT ''"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN attachment_name TEXT DEFAULT ''"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN is_blocked INTEGER DEFAULT 0"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN block_notify INTEGER DEFAULT 0"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN is_admin INTEGER DEFAULT 0"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN timezone TEXT DEFAULT 'Asia/Jakarta'"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE projects ADD COLUMN timezone TEXT DEFAULT ''"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE projects ADD COLUMN code TEXT DEFAULT ''"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE projects ADD COLUMN last_workspace_folder TEXT DEFAULT ''"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE projects ADD COLUMN last_operator TEXT DEFAULT ''"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE projects ADD COLUMN last_seen DATETIME DEFAULT NULL"); } catch (Exception $e) {}
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS chat_sessions (
                chat_id TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                active_project_id INTEGER DEFAULT 0,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } catch (Exception $e) {}

        // Inisialisasi kode proyek jika masih kosong
        try {
            $emptyCodes = $pdo->query("SELECT id, name, user_id FROM projects WHERE code IS NULL OR code = ''")->fetchAll(PDO::FETCH_ASSOC);
            $userCounters = [];
            foreach ($emptyCodes as $ec) {
                $uid = $ec['user_id'];
                if (!isset($userCounters[$uid])) {
                    $userCounters[$uid] = 0;
                }
                $letter = chr(97 + ($userCounters[$uid] % 26)); // 'a', 'b', 'c', ...
                $userCounters[$uid]++;
                $pdo->prepare("UPDATE projects SET code = ? WHERE id = ?")->execute([$letter, $ec['id']]);
            }
        } catch (Exception $e) {}

        // Set Superadmin iam@usm.rip
        try {
            $pdo->prepare("UPDATE users SET is_admin = 1 WHERE LOWER(email) = ?")->execute([strtolower(SUPERADMIN_EMAIL)]);
        } catch (Exception $e) {}

        // Migration: migrate legacy single-project user data into projects table
        try {
            $users = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($users as $u) {
                $chkP = $pdo->prepare("SELECT id FROM projects WHERE user_id = ?");
                $chkP->execute([$u['id']]);
                if (!$chkP->fetch()) {
                    $pkey = 'prj_' . substr(bin2hex(random_bytes(6)), 0, 10);
                    $pName = !empty($u['project_name']) ? $u['project_name'] : 'Proyek Utama';
                    $insP = $pdo->prepare("INSERT INTO projects (user_id, project_key, name, bot_token, chat_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $insP->execute([$u['id'], $pkey, $pName, $u['bot_token'] ?? '', $u['chat_id'] ?? '', $u['created_at'], $u['created_at']]);
                    $newPrjId = $pdo->lastInsertId();
                    
                    $updT = $pdo->prepare("UPDATE tasks SET project_id = ? WHERE user_id = ? AND (project_id = 0 OR project_id IS NULL)");
                    $updT->execute([$newPrjId, $u['id']]);
                }
            }
        } catch (Exception $e) {}
    }
    return $pdo;
}

// Helper: Supported Timezones
function getSupportedTimezones() {
    return [
        'Asia/Jakarta'     => 'WIB (Asia/Jakarta, UTC+7)',
        'Asia/Makassar'    => 'WITA (Asia/Makassar, UTC+8)',
        'Asia/Jayapura'    => 'WIT (Asia/Jayapura, UTC+9)',
        'Asia/Singapore'   => 'SGT (Asia/Singapore, UTC+8)',
        'Asia/Kuala_Lumpur'=> 'MYT (Asia/Kuala Lumpur, UTC+8)',
        'Asia/Bangkok'     => 'ICT (Asia/Bangkok, UTC+7)',
        'UTC'              => 'UTC (Universal Coordinated Time, UTC+0)',
        'Asia/Tokyo'       => 'JST (Asia/Tokyo, UTC+9)',
        'Europe/London'    => 'GMT/BST (Europe/London)',
        'America/New_York' => 'EST/EDT (America/New York)'
    ];
}

// Helper: Get Timezone Abbreviation
function getTimezoneAbbr($tzName) {
    if (!$tzName) return 'WIB';
    if ($tzName === 'Asia/Jakarta') return 'WIB';
    if ($tzName === 'Asia/Makassar') return 'WITA';
    if ($tzName === 'Asia/Jayapura') return 'WIT';
    if ($tzName === 'Asia/Singapore') return 'SGT';
    if ($tzName === 'Asia/Bangkok') return 'ICT';
    if ($tzName === 'UTC') return 'UTC';
    try {
        $dt = new DateTime('now', new DateTimeZone($tzName));
        return $dt->format('T');
    } catch (Exception $e) {
        return $tzName;
    }
}

// Helper: Apply Default Timezone
function applyTimezone($tz) {
    $tz = trim($tz ?: 'Asia/Jakarta');
    @date_default_timezone_set($tz);
    return $tz;
}

// Helper: Generate Random 5-Character Unique Project Code Tag (Global Uniqueness like Domain)
function generateUniqueProjectCode($db) {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    for ($attempt = 0; $attempt < 300; $attempt++) {
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $stmt = $db->prepare('SELECT COUNT(*) FROM projects WHERE LOWER(code) = ?');
        $stmt->execute([$code]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $code;
        }
    }
    return substr(bin2hex(random_bytes(3)), 0, 5);
}

// Helper: Normalize WhatsApp Number
function normalizePhone($phone) {
    $clean = preg_replace('/[^0-9]/', '', $phone);
    if (strpos($clean, '0') === 0) {
        $clean = '62' . substr($clean, 1);
    }
    return $clean;
}

// Helper: Call Telegram Bot API
function callTelegram($botToken, $method, $params = []) {
    if (!$botToken) return ['ok' => false, 'description' => 'Bot Token kosong'];
    if (!preg_match('/^[0-9]{8,12}:[a-zA-Z0-9_-]{30,50}$/', $botToken)) {
        return ['ok' => false, 'description' => 'Format Bot Token tidak valid'];
    }
    if (!preg_match('/^[a-zA-Z0-9]+$/', $method)) {
        return ['ok' => false, 'description' => 'Nama method Telegram tidak valid'];
    }
    $url = "https://api.telegram.org/bot{$botToken}/{$method}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    if (!empty($params)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return ['ok' => false, 'description' => "CURL Error: $err"];
    }
    return json_decode($response, true) ?: ['ok' => false, 'description' => 'Respon JSON tidak valid'];
}

// Default Telegram Bot Menu Commands
function getDefaultCommands() {
    return [
        [
            'command' => 'task',
            'category' => 'Tugas & Media',
            'example' => '/task Buatkan fitur login',
            'description' => 'Tambah tugas baru ke antrean AI Agent (bisa juga langsung kirim teks biasa, foto, dokumen, atau video).'
        ],
        [
            'command' => 'projects',
            'category' => 'Multi-Proyek',
            'example' => '/projects atau /proyek',
            'description' => 'Tampilkan seluruh proyek terhubung, kode routing (#a, #b), dan status proyek aktif saat ini.'
        ],
        [
            'command' => 'use',
            'category' => 'Multi-Proyek',
            'example' => '/use a atau /use b',
            'description' => 'Ganti proyek aktif untuk chat/grup ini. Setelah itu pesan biasa tanpa tag otomatis masuk ke proyek ini.'
        ],
        [
            'command' => 'status',
            'category' => 'Monitoring',
            'example' => '/status atau /status a',
            'description' => 'Lihat status ringkasan antrean tugas (Menunggu, Dikerjakan, Selesai) untuk semua proyek atau proyek tertentu.'
        ],
        [
            'command' => 'id',
            'category' => 'Akses & Whitelist',
            'example' => '/id atau /myid',
            'description' => 'Cek Telegram User ID & Chat ID Anda untuk didaftarkan ke Whitelist Private Chat.'
        ],
        [
            'command' => 'help',
            'category' => 'Panduan',
            'example' => '/help atau /start',
            'description' => 'Tampilkan menu bantuan interaktif dan ringkasan seluruh perintah bot Telegram.'
        ],
        [
            'command' => 'model',
            'category' => 'AI Agent',
            'example' => '/model',
            'description' => 'Cek sisa kuota model AI (Gemini, Claude, GPT) dan status mode kerja agen otonom.'
        ],
        [
            'command' => 'whoami',
            'category' => 'Multi-Workspace',
            'example' => '/whoami',
            'description' => 'Identifikasi workspace VS Code & folder kerja aktif yang terhubung di chat ini.'
        ],
        [
            'command' => 'ping',
            'category' => 'Diagnostik',
            'example' => '/ping',
            'description' => 'Tes kecepatan respons bot dan status aktif server webhook.'
        ],
        [
            'command' => 'backup',
            'category' => 'Workflow Dev',
            'example' => '/backup',
            'description' => 'Trigger pencadangan (backup) file kode proyek.'
        ],
        [
            'command' => 'sampah',
            'category' => 'Workflow Dev',
            'example' => '/sampah',
            'description' => 'Trigger pembersihan file temporary, log, dan cache proyek.'
        ],
        [
            'command' => 'publish',
            'category' => 'Workflow Dev',
            'example' => '/publish',
            'description' => 'Trigger proses deploy / publish proyek.'
        ]
    ];
}

// Helper: Build Full Commands List for a Project
function getProjectCommands($project) {
    $defaults = getDefaultCommands();
    $list = [];
    foreach ($defaults as $d) {
        $list[] = [
            'command' => $d['command'],
            'description' => $d['description']
        ];
    }

    // Tambahkan shortcut kode proyek (misal /a atau /b) jika ada
    $pCode = strtolower(preg_replace('/[^a-z0-9_]/', '', $project['code'] ?? ''));
    if ($pCode && !in_array($pCode, array_column($list, 'command'))) {
        array_unshift($list, [
            'command' => $pCode,
            'description' => 'Kirim tugas langsung ke ' . ($project['name'] ?? 'proyek ini')
        ]);
    }

    return $list;
}

// Helper: Ambil Nilai Pengaturan dari Database
function getSetting($key, $default = '') {
    global $db;
    try {
        if (!$db) $db = getDb();
        $stmt = $db->prepare("SELECT val FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row['val'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// Helper: Simpan Nilai Pengaturan ke Database
function setSetting($key, $val) {
    global $db;
    try {
        if (!$db) $db = getDb();
        $stmt = $db->prepare("INSERT INTO settings (key, val) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET val = excluded.val");
        $stmt->execute([$key, $val]);
    } catch (Exception $e) {}
}

// Helper: Kirim Email via Socket SMTP (SSL/TLS/Plain) dengan Fallback ke mail()
function sendSmtpEmail($to, $subject, $htmlBody, &$debugLog = null) {
    $host = trim(getSetting('smtp_host', ''));
    $port = (int)getSetting('smtp_port', 465);
    $crypto = strtolower(trim(getSetting('smtp_crypto', 'ssl')));
    $user = trim(getSetting('smtp_user', ''));
    $pass = trim(getSetting('smtp_pass', ''));
    $fromName = trim(getSetting('smtp_from_name', 'Tele-vs-Code'));
    $fromEmail = trim(getSetting('smtp_from_email', ''));
    if (!$fromEmail) {
        $fromEmail = (!empty($user) && filter_var($user, FILTER_VALIDATE_EMAIL)) ? $user : 'no-reply@biru.co.id';
    }

    $log = [];
    $log[] = "[" . date('H:i:s') . "] Memulai pengiriman email ke $to";

    // Jika Host SMTP belum diisi, gunakan mail() bawaan server
    if (empty($host)) {
        $log[] = "[" . date('H:i:s') . "] Host SMTP belum disetel, menggunakan fungsi mail() bawaan server...";
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . (!empty($fromName) ? "=?UTF-8?B?".base64_encode($fromName)."?= <$fromEmail>" : $fromEmail) . "\r\n";
        $sent = @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $htmlBody, $headers);
        $log[] = "[" . date('H:i:s') . "] Hasil mail(): " . ($sent ? "SUKSES" : "GAGAL");
        $debugLog = implode("\n", $log);
        return ['ok' => $sent, 'log' => $debugLog, 'error' => $sent ? '' : 'Fungsi mail() bawaan gagal mengirim email. Harap atur SMTP di dashboard admin.'];
    }

    $timeout = 15;
    $connectHost = ($crypto === 'ssl') ? 'ssl://' . $host : $host;
    $log[] = "[" . date('H:i:s') . "] Menghubungi SMTP socket: $connectHost:$port ($crypto)";

    $socket = @stream_socket_client("$connectHost:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        $log[] = "[" . date('H:i:s') . "] ❌ Gagal terhubung: $errstr ($errno)";
        $debugLog = implode("\n", $log);
        return ['ok' => false, 'log' => $debugLog, 'error' => "Koneksi ke host SMTP gagal: $errstr ($errno)"];
    }

    stream_set_timeout($socket, $timeout);

    $readReply = function() use ($socket, &$log) {
        $reply = '';
        while ($line = fgets($socket, 515)) {
            $reply .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        $log[] = "<-- " . trim($reply);
        return $reply;
    };

    $sendCommand = function($cmd, $hide = false) use ($socket, &$log, $readReply) {
        $log[] = "--> " . ($hide ? '******' : $cmd);
        fwrite($socket, $cmd . "\r\n");
        return $readReply();
    };

    $banner = $readReply();
    if (substr($banner, 0, 3) !== '220') {
        fclose($socket);
        $debugLog = implode("\n", $log);
        return ['ok' => false, 'log' => $debugLog, 'error' => "Respon banner server bukan 220: $banner"];
    }

    $sendCommand("EHLO " . (gethostname() ?: 'localhost'));

    if ($crypto === 'tls') {
        $res = $sendCommand("STARTTLS");
        if (substr($res, 0, 3) !== '220') {
            fclose($socket);
            $debugLog = implode("\n", $log);
            return ['ok' => false, 'log' => $debugLog, 'error' => "STARTTLS gagal: $res"];
        }
        $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        
        if (!stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
            fclose($socket);
            $debugLog = implode("\n", $log);
            return ['ok' => false, 'log' => $debugLog, 'error' => "Enkripsi TLS handshake gagal di soket."];
        }
        $sendCommand("EHLO " . (gethostname() ?: 'localhost'));
    }

    if (!empty($user)) {
        $res = $sendCommand("AUTH LOGIN");
        if (substr($res, 0, 3) !== '334') {
            fclose($socket);
            $debugLog = implode("\n", $log);
            return ['ok' => false, 'log' => $debugLog, 'error' => "Server menolak AUTH LOGIN: $res"];
        }

        $res = $sendCommand(base64_encode($user));
        if (substr($res, 0, 3) !== '334') {
            fclose($socket);
            $debugLog = implode("\n", $log);
            return ['ok' => false, 'log' => $debugLog, 'error' => "Username SMTP ditolak: $res"];
        }

        $res = $sendCommand(base64_encode($pass), true);
        if (substr($res, 0, 3) !== '235') {
            fclose($socket);
            $debugLog = implode("\n", $log);
            return ['ok' => false, 'log' => $debugLog, 'error' => "Password SMTP ditolak: $res"];
        }
    }

    $res = $sendCommand("MAIL FROM:<$fromEmail>");
    if (substr($res, 0, 3) !== '250') {
        fclose($socket);
        $debugLog = implode("\n", $log);
        return ['ok' => false, 'log' => $debugLog, 'error' => "MAIL FROM gagal: $res"];
    }

    $res = $sendCommand("RCPT TO:<$to>");
    if (substr($res, 0, 3) !== '250') {
        fclose($socket);
        $debugLog = implode("\n", $log);
        return ['ok' => false, 'log' => $debugLog, 'error' => "RCPT TO gagal untuk $to: $res"];
    }

    $res = $sendCommand("DATA");
    if (substr($res, 0, 3) !== '354') {
        fclose($socket);
        $debugLog = implode("\n", $log);
        return ['ok' => false, 'log' => $debugLog, 'error' => "DATA ditolak: $res"];
    }

    $headers = [
        "Date: " . date('r'),
        "To: <$to>",
        "From: " . (!empty($fromName) ? "=?UTF-8?B?".base64_encode($fromName)."?= <$fromEmail>" : "<$fromEmail>"),
        "Subject: =?UTF-8?B?".base64_encode($subject)."?=",
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "Content-Transfer-Encoding: base64"
    ];

    $emailContent = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody)) . "\r\n.";
    $res = $sendCommand($emailContent);
    $sendCommand("QUIT");
    fclose($socket);

    $debugLog = implode("\n", $log);
    if (substr($res, 0, 3) === '250') {
        return ['ok' => true, 'log' => $debugLog, 'error' => ''];
    } else {
        return ['ok' => false, 'log' => $debugLog, 'error' => "Gagal mengirim data email: $res"];
    }
}

// ------------------------------------------------------------------------------
// 2. Action Handlers (Auth, Webhook, REST API, Projects, Tasks)
// ------------------------------------------------------------------------------
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$db = getDb();

// Cek Keunikan Kode TAG via AJAX (On-Blur / Live Check Unik Global)
if ($action === 'check_code_unique') {
    header('Content-Type: application/json');
    $rawCode = trim($_GET['code'] ?? ($_POST['code'] ?? ''));
    $cleanCode = strtolower(preg_replace('/[^a-z0-9_-]/', '', $rawCode));
    $excludeId = (int)($_GET['exclude_id'] ?? ($_POST['exclude_id'] ?? 0));

    $isAdmin = false;
    if (!empty($_SESSION['tvsc_uid'])) {
        $uStmt = $db->prepare("SELECT email, is_admin FROM users WHERE id = ?");
        $uStmt->execute([$_SESSION['tvsc_uid']]);
        $uData = $uStmt->fetch(PDO::FETCH_ASSOC);
        if ($uData) {
            $isAdmin = (strtolower($uData['email']) === strtolower(SUPERADMIN_EMAIL) || !empty($uData['is_admin']));
        }
    }

    $minLen = $isAdmin ? 1 : 5;
    if (!$cleanCode || strlen($cleanCode) < $minLen) {
        $msg = "Kode TAG minimal $minLen karakter!";
        if (!$isAdmin) {
            $msg .= " Hanya admin yang dapat menggunakan kurang dari 5 karakter.";
        }
        echo json_encode(['ok' => false, 'available' => false, 'message' => $msg]);
        exit;
    }
    if (strlen($cleanCode) > 20) {
        echo json_encode(['ok' => false, 'available' => false, 'message' => 'Kode tag maksimal 20 karakter']);
        exit;
    }

    $stmt = $db->prepare('SELECT id, name FROM projects WHERE LOWER(code) = ? AND id != ? LIMIT 1');
    $stmt->execute([$cleanCode, $excludeId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        echo json_encode([
            'ok' => true,
            'available' => false,
            'code' => $cleanCode,
            'message' => "Kode TAG '#$cleanCode' sudah digunakan pengguna lain!"
        ]);
    } else {
        echo json_encode([
            'ok' => true,
            'available' => true,
            'code' => $cleanCode,
            'message' => "Kode TAG '#$cleanCode' tersedia dan unik di seluruh sistem."
        ]);
    }
    exit;
}

// Kompatibilitas alias action untuk ekstensi VS Code & CLI:
if ($action === 'pull') $action = 'api_pull';
if ($action === 'push') $action = 'api_push';
if ($action === 'done') $action = 'api_done';
if ($action === 'failed') $action = 'api_failed';
if ($action === 'in_progress') $action = 'api_in_progress';
if ($action === 'status') $action = 'api_status';
if ($action === 'projects') $action = 'api_projects';

// Deteksi jika diakses via endpoint /tele-vs-code/webhook
$reqUri = $_SERVER['REQUEST_URI'] ?? '';
$isWebhookPath = (strpos($reqUri, '/tele-vs-code/webhook') !== false || isset($_GET['is_webhook']));

// Jika endpoint /webhook dipanggil tanpa parameter action, anggap webhook Telegram
if ($isWebhookPath && empty($action)) {
    $action = 'webhook';
}

// Download VSIX Extension
if ($action === 'download_vsix') {
    $vsixTarget = VSIX_FILE;
    if (file_exists($vsixTarget)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename(VSIX_FILE) . '"');
        header('Content-Length: ' . filesize($vsixTarget));
        readfile($vsixTarget);
        exit;
    } else {
        die('File VSIX tidak ditemukan di server.');
    }
}

// Multi-Tenant Webhook dari Telegram Bot Pengguna (Berdasarkan pkey atau api_key / secret)
if ($action === 'webhook') {
    $pKey = $_GET['pkey'] ?? '';
    $apiKey = $_GET['secret'] ?? ($_POST['secret'] ?? ($_GET['key'] ?? ($_POST['key'] ?? '')));

    $project = null;
    $allProjects = [];
    if ($pKey) {
        $stmtP = $db->prepare("SELECT p.*, u.email, u.api_key, u.is_blocked, u.block_notify, u.timezone as user_timezone FROM projects p JOIN users u ON p.user_id = u.id WHERE p.project_key = ?");
        $stmtP->execute([$pKey]);
        $project = $stmtP->fetch(PDO::FETCH_ASSOC);
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
        if ($user) {
            $stmtAll = $db->prepare("SELECT p.*, u.email, u.api_key, u.is_blocked, u.block_notify, u.timezone as user_timezone FROM projects p JOIN users u ON p.user_id = u.id WHERE p.user_id = ? ORDER BY p.id ASC");
            $stmtAll->execute([$user['id']]);
            $allProjects = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
            $project = $allProjects[0] ?? null;
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
    if (!$update || !isset($update['message'])) { echo "OK"; exit; }

    $msg = $update['message'];
    $incomingChatId = (string)($msg['chat']['id'] ?? '');
    $chatType = $msg['chat']['type'] ?? 'private';
    $text = trim($msg['text'] ?? ($msg['caption'] ?? ''));
    $sender = !empty($msg['from']['username']) ? '@' . $msg['from']['username'] : ($msg['from']['first_name'] ?? 'User');
    $senderUserId = (string)($msg['from']['id'] ?? '');
    $senderUsername = strtolower(ltrim($msg['from']['username'] ?? '', '@'));
    $messageId = $msg['message_id'];

    // Deteksi Lampiran Media (Foto, Dokumen, Video)
    $attachmentUrl = '';
    $attachmentType = '';
    $attachmentName = '';
    $attachmentFileId = null;

    if (!empty($msg['photo']) && is_array($msg['photo'])) {
        $attachmentType = 'photo';
        $bestPhoto = end($msg['photo']);
        $attachmentFileId = $bestPhoto['file_id'] ?? null;
        $attachmentName = 'photo_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(2)), 0, 4) . '.jpg';
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

    // 1. Pemeriksaan Hak Akses Whitelist Pengguna & Filter Proyek yang Diizinkan (Strict Per-Project Whitelist)
    $stmtW = $db->prepare("SELECT * FROM bot_whitelist WHERE (telegram_user_id = ? OR (telegram_username != '' AND LOWER(telegram_username) = ?))");
    $stmtW->execute([$senderUserId, $senderUsername]);
    $myWhitelists = $stmtW->fetchAll(PDO::FETCH_ASSOC);

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

    // Ambil sesi chat untuk proyek aktif (/use <kode>)
    $activeProjId = 0;
    if ($incomingChatId) {
        if (!empty($senderUserId)) {
            $stmtSes = $db->prepare("SELECT active_project_id FROM chat_sessions WHERE chat_id = ?");
            $stmtSes->execute([$incomingChatId . ':' . $senderUserId]);
            $activeProjId = (int)$stmtSes->fetchColumn();
        }
        if ($activeProjId <= 0) {
            $stmtSes = $db->prepare("SELECT active_project_id FROM chat_sessions WHERE chat_id = ?");
            $stmtSes->execute([$incomingChatId]);
            $activeProjId = (int)$stmtSes->fetchColumn();
        }
    }

    // Proyek aktif: default ke proyek pertama yang diizinkan untuk user ini
    $activeProject = $allProjects[0];
    if ($activeProjId > 0) {
        foreach ($allProjects as $ap) {
            if ((int)$ap['id'] === $activeProjId) {
                $activeProject = $ap;
                break;
            }
        }
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

    // Command /whoami (Identifikasi Multi-Workspace VS Code)
    if (strpos($text, '/whoami') === 0) {
        $pList = $allProjects;

        $reply = "👤 <b>IDENTIFIKASI WORKSPACE TELE-VS-CODE</b>\n\n";
        $reply .= "📌 <b>Proyek Aktif di Chat Ini:</b>\n";
        $reply .= "• Nama: <b>" . htmlspecialchars($activeProject['name']) . "</b> (#" . ($activeProject['code'] ?: $activeProject['id']) . ")\n";
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

    // Command /use atau /switch untuk ganti proyek aktif
    if (preg_match('/^\/(use|switch|pilih|proyek_aktif)(\s+(.+))?$/i', $text, $mUse)) {
        $target = trim($mUse[3] ?? '');
        if (!$target) {
            $reply = "ℹ️ <b>Gunakan perintah:</b> <code>/use &lt;kode atau nomor proyek&gt;</code>\nContoh: <code>/use a</code> atau <code>/use 1</code>\n\nKetik <code>/projects</code> untuk melihat daftar semua proyek Anda.";
        } else {
            $targetLower = strtolower(ltrim($target, '#'));
            $foundP = null;

            // Prioritas 1: Exact match Kode Proyek (misal 'b') atau Project Key (misal 'prj_xxxx')
            foreach ($allProjects as $idx => $p) {
                $pCode = strtolower($p['code'] ?: chr(97 + ($idx % 26)));
                $pKey = strtolower($p['project_key'] ?? '');
                if ($targetLower === $pCode || ($pKey && $targetLower === $pKey)) {
                    $foundP = $p;
                    break;
                }
            }

            // Prioritas 2: Exact match ID atau Nomor Urut (1, 2, 3...)
            if (!$foundP) {
                foreach ($allProjects as $idx => $p) {
                    if ($targetLower === (string)$p['id'] || $targetLower === (string)($idx + 1)) {
                        $foundP = $p;
                        break;
                    }
                }
            }

            // Prioritas 3: Exact match Nama Proyek (Hanya jika SAMA PERSIS, TIDAK mengandung atau awalan)
            if (!$foundP) {
                foreach ($allProjects as $idx => $p) {
                    if ($targetLower === strtolower(trim($p['name'] ?? ''))) {
                        $foundP = $p;
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
                $reply = "🎯 <b>PROYEK AKTIF BERHASIL DIUBAH!</b>\n\n"
                       . "📁 <b>Proyek Saat Ini:</b> <code>" . htmlspecialchars($foundP['name']) . "</code> (Kode: <code>#$pCode</code>)" . $ownerInfo . "\n"
                       . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n\n"
                       . "👉 Semua pesan instruksi dari Anda selanjutnya di chat ini akan otomatis dikerjakan oleh VS Code proyek <b>" . htmlspecialchars($foundP['name']) . "</b>.\n\n"
                       . "<i>Gunakan tag <code>#&lt;kode&gt;</code> jika ingin menargetkan proyek lain sesekali.</i>";
            } else {
                $reply = "⛔ <b>AKSES PROYEK DITOLAK</b>\n\n"
                       . "Proyek '<b>" . htmlspecialchars($target) . "</b>' tidak ditemukan atau Anda tidak terdaftar di whitelist untuk proyek tersebut.\n\n"
                       . "Ketik <code>/projects</code> untuk melihat daftar proyek yang diizinkan untuk akun Anda.";
            }
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

    // Command /model
    if (strpos($text, '/model') === 0) {
        $reply = "🧠 <b>AI MODEL & STATUS KUOTA</b>\n\n"
               . "• <b>Gemini Models:</b> 10% Weekly Limit Remaining\n"
               . "• <b>Claude & GPT:</b> 100% Weekly Limit Remaining\n"
               . "• <b>Mode Kerja:</b> Antigravity Autonomous Agent (Pro)\n\n"
               . "<i>Siap mengerjakan instruksi tugas coding proyek " . htmlspecialchars($activeProject['name']) . ".</i>";
        callTelegram($project['bot_token'], 'sendMessage', [
            'chat_id' => $incomingChatId,
            'text' => $reply,
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $messageId
        ]);
        echo "OK"; exit;
    }

    // Tangkap Tugas Baru (Teks atau Lampiran Media)
    $instruction = '';
    $explicitProject = null;
    $taskCommand = '';

    if (preg_match('/^\/task(@\w+)?\s+(.+)$/is', $text, $matches)) {
        $taskCommand = '/task';
        $instruction = trim($matches[2]);
    } elseif ($text && $text[0] !== '/') {
        $instruction = $text;
    } elseif (preg_match('/^\/([a-zA-Z0-9_-]+)(@\w+)?(?:\s+(.+))?$/is', $text, $matches)) {
        // Cek jika perintah adalah kode proyek, misal: /a Kerjakan tugas ini
        $slashCode = strtolower($matches[1]);
        foreach ($allProjects as $idx => $p) {
            $pCode = strtolower($p['code'] ?: chr(97 + ($idx % 26)));
            if ($slashCode === $pCode || $slashCode === (string)$p['id']) {
                $explicitProject = $p;
                $taskCommand = '/' . $slashCode;
                $instruction = trim($matches[3] ?? '');
                break;
            }
        }
        if (!$explicitProject && $attachmentType) {
            $instruction = $text;
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
                $pId = (string)$p['id'];
                $pSlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $p['name']));

                $pKey = strtolower($p['project_key'] ?? '');
                $candidates = array_unique(array_filter([$pCode, $pKey, $pId, $pSlug]));
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

        $taskId = '#TASK-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        $now = date('Y-m-d H:i:s');
        $taskSender = $sender;
        if ($isPrivateChat) {
            $displayName = !empty($whitelistedUser['name']) ? $whitelistedUser['name'] : $sender;
            $taskSender = $displayName . ' (Private Chat)';
        }

        // Unduh file lampiran jika ada
        if ($attachmentFileId && !empty($targetProject['bot_token'])) {
            $fileInfo = callTelegram($targetProject['bot_token'], 'getFile', ['file_id' => $attachmentFileId]);
            if (!empty($fileInfo['ok']) && !empty($fileInfo['result']['file_path'])) {
                $remotePath = $fileInfo['result']['file_path'];
                // Mencegah path traversal pada file_path
                $remotePath = str_replace(['..', '\\'], '', $remotePath);
                $downloadSrc = "https://api.telegram.org/file/bot" . $targetProject['bot_token'] . "/" . $remotePath;

                $uploadDir = __DIR__ . '/uploads/' . $taskId;
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }

                // Pastikan uploads/.htaccess mematikan eksekusi script PHP
                $mainUploadDir = __DIR__ . '/uploads';
                if (!file_exists($mainUploadDir . '/.htaccess')) {
                    $ht = "<FilesMatch \"(?i)\\.(php|phtml|phar|phps|inc|shtml|cgi|pl|py|sh|bat|cmd|exe|htaccess)$\">\n    Order Deny,Allow\n    Deny from all\n</FilesMatch>\nOptions -ExecCGI -Indexes\nHeader set X-Content-Type-Options \"nosniff\"\n";
                    @file_put_contents($mainUploadDir . '/.htaccess', $ht);
                }

                // Netralkan ekstensi file berbahaya untuk mencegah RCE
                $ext = strtolower(pathinfo($attachmentName, PATHINFO_EXTENSION));
                $dangerousExts = ['php', 'phtml', 'phar', 'phps', 'inc', 'shtml', 'cgi', 'pl', 'py', 'sh', 'bat', 'cmd', 'exe', 'htaccess', 'ini', 'config', 'asp', 'aspx', 'jsp', 'env'];
                if (in_array($ext, $dangerousExts)) {
                    $attachmentName .= '.txt';
                }

                $targetFile = $uploadDir . '/' . $attachmentName;
                $fileData = @file_get_contents($downloadSrc);
                if ($fileData !== false && strlen($fileData) > 0) {
                    file_put_contents($targetFile, $fileData);
                    $attachmentUrl = "https://biru.co.id/tele-vs-code/uploads/" . $taskId . "/" . rawurlencode($attachmentName);
                }
            }
        }

        // Format instruksi agar AI Agent langsung mengetahui keberadaan dan URL lampiran
        if ($attachmentType) {
            $typeLabel = ($attachmentType === 'photo') ? 'Foto' : (($attachmentType === 'video') ? 'Video' : 'Dokumen');
            $attachmentHeader = "[$typeLabel Terlampir: $attachmentName" . ($attachmentUrl ? " ($attachmentUrl)" : "") . "]";
            $instruction = $attachmentHeader . "\n" . $instruction;
        }

        $stmtIns = $db->prepare("INSERT INTO tasks (id, user_id, project_id, instruction, sender, status, created_at, updated_at, attachment_url, attachment_type, attachment_name, command) VALUES (?, ?, ?, ?, ?, 'PENDING', ?, ?, ?, ?, ?, ?)");
        $stmtIns->execute([$taskId, $targetProject['user_id'], $targetProject['id'], $instruction, $taskSender, $now, $now, $attachmentUrl, $attachmentType, $attachmentName, $taskCommand]);

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

            $reply = "📥 <b>TUGAS DITERIMA DI ANTREAN!" . ($isPrivateChat ? " (Private Chat)" : "") . "</b>\n\n"
                   . "🆔 <b>ID:</b> <code>$taskId</code>\n"
                   . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($targetProject['name']) . "</code> (Kode: <code>#" . ($targetProject['code'] ?: $targetProject['id']) . "</code>)\n"
                   . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n"
                   . "👤 <b>Pemohon:</b> " . htmlspecialchars($taskSender) . "\n"
                   . $attachLine . "\n"
                   . "📝 <b>Instruksi:</b>\n<code>" . htmlspecialchars($instruction) . "</code>\n\n"
                   . "⏳ <b>Status:</b> <code>[PENDING]</code>\n"
                   . "<i>Tugas siap ditarik oleh VS Code: " . htmlspecialchars($targetProject['name']) . "</i>"
                   . $tipMulti;

            callTelegram($targetProject['bot_token'], 'sendMessage', [
                'chat_id' => $incomingChatId,
                'text' => $reply,
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $messageId
            ]);

            // Jika dari Private Chat dan proyek punya Group Chat terpisah, kirim pemberitahuan ringkas ke grup
            if ($isPrivateChat && !empty($targetProject['chat_id']) && (string)$targetProject['chat_id'] !== $incomingChatId) {
                $groupNotice = "📢 <b>TUGAS BARU VIA PRIVATE CHAT</b>\n\n"
                             . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($targetProject['name']) . "</code>\n"
                             . "🆔 <b>ID:</b> <code>$taskId</code>\n"
                             . "👤 <b>Pemohon:</b> " . htmlspecialchars($taskSender) . "\n"
                             . ($attachmentType ? "📎 <b>Lampiran:</b> " . htmlspecialchars($attachmentName) . " (" . ucfirst($attachmentType) . ")\n" : "")
                             . "📝 <b>Instruksi:</b> " . htmlspecialchars(mb_substr($instruction, 0, 150)) . (mb_strlen($instruction) > 150 ? '...' : '') . "\n"
                             . "⏳ <b>Status:</b> <code>[PENDING]</code>";
                callTelegram($project['bot_token'], 'sendMessage', [
                    'chat_id' => $project['chat_id'],
                    'text' => $groupNotice,
                    'parse_mode' => 'HTML'
                ]);
            }
        }
    }
    echo "OK"; exit;
}

// Download & Serve Lampiran Tugas
if ($action === 'attachment') {
    $tid = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['task'] ?? '');
    $fname = basename($_GET['file'] ?? '');
    $filePath = __DIR__ . '/uploads/' . $tid . '/' . $fname;
    if ($tid && $fname && file_exists($filePath)) {
        $mime = 'application/octet-stream';
        $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'mp4' => 'video/mp4', 'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo', 'mov' => 'video/quicktime',
            'pdf' => 'application/pdf', 'txt' => 'text/plain', 'json' => 'application/json', 'csv' => 'text/csv',
            'zip' => 'application/zip', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ];
        if (isset($mimeTypes[$ext])) {
            $mime = $mimeTypes[$ext];
        } elseif (function_exists('mime_content_type')) {
            $mime = @mime_content_type($filePath) ?: 'application/octet-stream';
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($filePath));
        header('Content-Disposition: inline; filename="' . $fname . '"');
        readfile($filePath);
        exit;
    }
    http_response_code(404);
    echo "Lampiran tidak ditemukan.";
    exit;
}

// REST API untuk VS Code Extension & Worker Agent
if (in_array($action, ['api_pull', 'api_push', 'api_done', 'api_failed', 'api_in_progress', 'api_status', 'api_projects'])) {
    $apiKey = $_GET['secret'] ?? ($_POST['secret'] ?? ($_GET['key'] ?? ($_POST['key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? ''))));
    if (!$apiKey) {
        http_response_code(401);
        echo json_encode(['error' => 'API Key wajib disertakan']);
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE api_key = ?");
    $stmt->execute([$apiKey]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(403);
        echo json_encode(['error' => 'API Key tidak valid']);
        exit;
    }

    // Blokir Akses API jika user diblokir oleh Superadmin
    if (!empty($user['is_blocked'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Akun Anda dinonaktifkan oleh Administrator']);
        exit;
    }

    header('Content-Type: application/json');

    // List Projects milik User
    if ($action === 'api_projects') {
        $stmtP = $db->prepare("SELECT id, project_key, name, code, bot_token, chat_id, timezone, last_workspace_folder, last_operator, last_seen FROM projects WHERE user_id = ?");
        $stmtP->execute([$user['id']]);
        echo json_encode(['ok' => true, 'projects' => $stmtP->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // Pull Pending Tasks (Opsional filter by project_id / project_key / code / name)
    if ($action === 'api_pull') {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 1;
        $mark = ($_GET['mark'] ?? '1') === '1';
        $prjFilter = $_GET['project_id'] ?? ($_GET['project'] ?? ($_GET['code'] ?? ''));
        $wsFolder = trim($_GET['workspace_folder'] ?? ($_POST['workspace_folder'] ?? ''));
        $operator = trim($_GET['operator'] ?? ($_POST['operator'] ?? ''));

        // Update Heartbeat Workspace / VS Code Instance untuk Proyek ini
        if ($wsFolder || $operator) {
            $now = date('Y-m-d H:i:s');
            if ($prjFilter) {
                $cleanFilter = strtolower(trim($prjFilter));
                if (is_numeric($prjFilter)) {
                    $updH = $db->prepare("UPDATE projects SET last_workspace_folder = ?, last_operator = ?, last_seen = ? WHERE user_id = ? AND (id = ? OR LOWER(code) = ?)");
                    $updH->execute([$wsFolder, $operator, $now, $user['id'], (int)$prjFilter, $cleanFilter]);
                } else {
                    $updH = $db->prepare("UPDATE projects SET last_workspace_folder = ?, last_operator = ?, last_seen = ? WHERE user_id = ? AND (project_key = ? OR LOWER(code) = ? OR LOWER(name) = ?)");
                    $updH->execute([$wsFolder, $operator, $now, $user['id'], $prjFilter, $cleanFilter, $cleanFilter]);
                }
            } else {
                $updH = $db->prepare("UPDATE projects SET last_workspace_folder = ?, last_operator = ?, last_seen = ? WHERE user_id = ? ORDER BY id ASC LIMIT 1");
                $updH->execute([$wsFolder, $operator, $now, $user['id']]);
            }
        }

        $sql = "SELECT t.*, p.name as project_name, p.code as project_code, p.bot_token, p.chat_id FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.user_id = ? AND (t.status = 'PENDING' OR (t.status = 'IN_PROGRESS' AND t.updated_at < datetime('now', '-2 minutes')))";
        $params = [$user['id']];

        if ($prjFilter) {
            $cleanFilter = strtolower(trim($prjFilter));
            if (is_numeric($prjFilter)) {
                $sql .= " AND (t.project_id = ? OR LOWER(p.code) = ?)";
                $params[] = (int)$prjFilter;
                $params[] = $cleanFilter;
            } else {
                $sql .= " AND (p.project_key = ? OR LOWER(p.code) = ? OR LOWER(p.name) = ?)";
                $params[] = $prjFilter;
                $params[] = $cleanFilter;
                $params[] = $cleanFilter;
            }
        }
        $sql .= " ORDER BY t.created_at ASC LIMIT ?";
        $params[] = $limit;

        $stmtTasks = $db->prepare($sql);
        $stmtTasks->execute($params);
        $pending = $stmtTasks->fetchAll(PDO::FETCH_ASSOC);

        if ($mark && !empty($pending)) {
            $now = date('Y-m-d H:i:s');
            foreach ($pending as $pt) {
                $upd = $db->prepare("UPDATE tasks SET status = 'IN_PROGRESS', updated_at = ?, notes = 'Ditarik oleh VS Code Agent' WHERE id = ?");
                $upd->execute([$now, $pt['id']]);
            }
        }
        echo json_encode(['ok' => true, 'count' => count($pending), 'tasks' => $pending]);
        exit;
    }

    // Push Task Baru
    if ($action === 'api_push') {
        $instruction = $_POST['instruction'] ?? ($_GET['instruction'] ?? '');
        $sender = $_POST['sender'] ?? 'VS Code';
        $status = $_POST['status'] ?? 'IN_PROGRESS';
        $notes = $_POST['notes'] ?? 'Dibuat via VS Code';
        $command = trim($_POST['command'] ?? ($_GET['command'] ?? ''));
        if (!$command) {
            $command = ($sender === 'IDE Chat' || $sender === 'VS Code') ? 'tele-task add' : '/task';
        }
        $prjInput = $_POST['project_id'] ?? ($_GET['project_id'] ?? ($_POST['project'] ?? ($_GET['project'] ?? '')));
        $prjId = 0;
        if ($prjInput) {
            if (is_numeric($prjInput)) {
                $prjId = (int)$prjInput;
            } else {
                $cleanPrj = strtolower(trim($prjInput));
                $stmtFind = $db->prepare("SELECT id FROM projects WHERE user_id = ? AND (project_key = ? OR LOWER(code) = ? OR LOWER(name) = ?) LIMIT 1");
                $stmtFind->execute([$user['id'], $prjInput, $cleanPrj, $cleanPrj]);
                $f = $stmtFind->fetch(PDO::FETCH_ASSOC);
                if ($f) $prjId = (int)$f['id'];
            }
        }
        if (!$prjId) {
            $stmtFirst = $db->prepare("SELECT id FROM projects WHERE user_id = ? ORDER BY id ASC LIMIT 1");
            $stmtFirst->execute([$user['id']]);
            $firstP = $stmtFirst->fetch(PDO::FETCH_ASSOC);
            $prjId = $firstP ? (int)$firstP['id'] : 0;
        }

        if (!$instruction) {
            echo json_encode(['error' => 'Instruksi kosong']);
            exit;
        }

        $taskId = '#TASK-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        $now = date('Y-m-d H:i:s');
        $stmtIns = $db->prepare("INSERT INTO tasks (id, user_id, project_id, instruction, sender, status, created_at, updated_at, notes, command) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtIns->execute([$taskId, $user['id'], $prjId, $instruction, $sender, $status, $now, $now, $notes, $command]);

        echo json_encode(['ok' => true, 'id' => $taskId, 'message' => "Tugas $taskId berhasil dicatat"]);
        exit;
    }

        // Update Status Tugas Menjadi IN_PROGRESS
    if ($action === 'api_in_progress') {
        $taskId = $_GET['id'] ?? ($_POST['id'] ?? '');
        $notes = trim($_POST['notes'] ?? ($_GET['notes'] ?? 'Sedang dikerjakan oleh AI Agent'));
        $now = date('Y-m-d H:i:s');
        $upd = $db->prepare("UPDATE tasks SET status = 'IN_PROGRESS', updated_at = ?, notes = ? WHERE id = ? AND user_id = ?");
        $upd->execute([$now, $notes, $taskId, $user['id']]);
        echo json_encode(['ok' => true, 'id' => $taskId, 'status' => 'IN_PROGRESS']);
        exit;
    }

    // Tandai Tugas Selesai / Gagal
    if ($action === 'api_done' || $action === 'api_failed') {
        $taskId = $_GET['id'] ?? ($_POST['id'] ?? '');
        $result = trim($_POST['result'] ?? ($_GET['result'] ?? ''));
        $notes = trim($_POST['notes'] ?? ($_GET['notes'] ?? ''));
        $cmdParam = trim($_POST['command'] ?? ($_GET['command'] ?? ''));
        if (!$result && $notes) $result = $notes;
        if (!$notes && $result) $notes = $result;
        if (!$result) $result = ($action === 'api_done') ? 'Tugas selesai 100%' : 'Gagal';
        if (!$notes) $notes = $result;

        $stat = ($action === 'api_done') ? 'DONE' : 'FAILED';

        $stmtT = $db->prepare("SELECT t.*, p.bot_token, p.chat_id, p.name as project_name, p.timezone as project_timezone FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.id = ? AND t.user_id = ?");
        $stmtT->execute([$taskId, $user['id']]);
        $task = $stmtT->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            echo json_encode(['error' => 'Tugas tidak ditemukan']);
            exit;
        }

        $effectiveTz = !empty($task['project_timezone']) ? $task['project_timezone'] : (!empty($user['timezone']) ? $user['timezone'] : 'Asia/Jakarta');
        applyTimezone($effectiveTz);
        $tzAbbr = getTimezoneAbbr($effectiveTz);
        $now = date('Y-m-d H:i:s');

        if ($cmdParam) {
            $upd = $db->prepare("UPDATE tasks SET status = ?, updated_at = ?, notes = ?, result = ?, command = ? WHERE id = ? AND user_id = ?");
            $upd->execute([$stat, $now, $notes, $result, $cmdParam, $taskId, $user['id']]);
        } else {
            $upd = $db->prepare("UPDATE tasks SET status = ?, updated_at = ?, notes = ?, result = ? WHERE id = ? AND user_id = ?");
            $upd->execute([$stat, $now, $notes, $result, $taskId, $user['id']]);
        }

        // Kirim Notifikasi ke Bot Telegram Proyek (Hanya jika TIDAK DIBLOKIR oleh Superadmin)
        $botToken = !empty($task['bot_token']) ? $task['bot_token'] : $user['bot_token'];
        $chatId = !empty($task['chat_id']) ? $task['chat_id'] : $user['chat_id'];
        $pName = !empty($task['project_name']) ? $task['project_name'] : $user['project_name'];

        if ($botToken && $chatId) {
            if (empty($user['block_notify'])) {
                $emoji = ($stat === 'DONE') ? '✅' : '🚨';
                $text = "<b>$emoji Tugas $taskId: " . ($stat === 'DONE' ? 'SELESAI' : 'KENDALA') . "</b>\n\n"
                      . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($pName) . "</code>\n"
                      . "🕒 <b>Waktu:</b> $now $tzAbbr\n"
                      . "📋 <b>Hasil Pekerjaan:</b>\n" . htmlspecialchars($result) . "\n\n"
                      . "🤖 <i>Dikirim via tele-vs-code</i>";
                callTelegram($botToken, 'sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML'
                ]);
            }
        }

        echo json_encode(['ok' => true, 'message' => "Tugas $taskId diperbarui ke $stat", 'result' => $result]);
        exit;
    }

    // Status Ringkasan
    if ($action === 'api_status') {
        $prjFilter = $_GET['project_id'] ?? '';
        $sql = "SELECT status, count(*) as c FROM tasks WHERE user_id = ?";
        $params = [$user['id']];
        if ($prjFilter) {
            $sql .= " AND project_id = ?";
            $params[] = (int)$prjFilter;
        }
        $sql .= " GROUP BY status";
        $stmtT = $db->prepare($sql);
        $stmtT->execute($params);
        $counts = ['PENDING' => 0, 'IN_PROGRESS' => 0, 'DONE' => 0, 'FAILED' => 0];
        while ($row = $stmtT->fetch(PDO::FETCH_ASSOC)) {
            $counts[$row['status']] = $row['c'];
        }
        echo json_encode(['ok' => true, 'summary' => $counts]);
        exit;
    }
}

// ------------------------------------------------------------------------------
// 3. User Authentication Handlers (Register & Login)
// ------------------------------------------------------------------------------
$errorMsg = '';
$successMsg = '';

// Register
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $errorMsg = 'Email ini sudah terdaftar! Silakan login.';
        } else {
            $chkW = $db->prepare("SELECT id FROM users WHERE whatsapp = ?");
            $chkW->execute([$phone]);
            if ($chkW->fetch()) {
                $errorMsg = 'Nomor WhatsApp ini sudah terdaftar! Gunakan nomor lain.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $apiKey = 'tvsc_' . bin2hex(random_bytes(16));
                $now = date('Y-m-d H:i:s');
                $isAdmin = (strtolower($email) === strtolower(SUPERADMIN_EMAIL)) ? 1 : 0;

                $ins = $db->prepare("INSERT INTO users (email, whatsapp, password_hash, api_key, is_admin, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$email, $phone, $hash, $apiKey, $isAdmin, $now, $now]);
                $uid = $db->lastInsertId();

                // Buat proyek pertama otomatis
                $pkey = 'prj_' . substr(bin2hex(random_bytes(6)), 0, 10);
                $insP = $db->prepare("INSERT INTO projects (user_id, project_key, name, created_at, updated_at) VALUES (?, ?, 'Proyek Utama', ?, ?)");
                $insP->execute([$uid, $pkey, $now, $now]);

                $_SESSION['tvsc_uid'] = $uid;
                header('Location: ./?tab=projects&msg=welcome');
                exit;
            }
        }
    }
}

// Login
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $failKey = 'login_fails_' . md5($ip);
    $lockKey = 'login_locked_until_' . md5($ip);

    if (!empty($_SESSION[$lockKey]) && time() < $_SESSION[$lockKey]) {
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
            session_regenerate_id(true);
            $_SESSION['tvsc_uid'] = $user['id'];
            header('Location: ./');
            exit;
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
            $htmlBody = '
            <!DOCTYPE html>
            <html>
            <head><meta charset="UTF-8"></head>
            <body style="font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; background: #090d16; color: #f8fafc; padding: 24px; margin: 0;">
                <div style="max-width: 520px; margin: 0 auto; background: #131c2e; border: 1px solid #1e293b; border-radius: 12px; padding: 28px;">
                    <h2 style="color: #38bdf8; margin-top: 0; font-size: 20px;">🔔 Tele-vs-Code</h2>
                    <h3 style="color: #f8fafc; font-size: 16px; margin-bottom: 12px;">Permintaan Reset Password</h3>
                    <p style="color: #cbd5e1; font-size: 14px; line-height: 1.6;">
                        Halo, kami menerima permintaan untuk mereset password akun Tele-vs-Code Anda (<b>' . htmlspecialchars($email) . '</b>).
                    </p>
                    <p style="color: #cbd5e1; font-size: 14px; line-height: 1.6;">
                        Klik tombol di bawah ini untuk membuat password baru. Tautan ini berlaku selama <b>1 jam</b>:
                    </p>
                    <div style="text-align: center; margin: 28px 0;">
                        <a href="' . $resetUrl . '" style="background: #0284c7; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 14px; display: inline-block;">
                            🔒 Buat Password Baru
                        </a>
                    </div>
                    <p style="color: #94a3b8; font-size: 12px; line-height: 1.5;">
                        Atau salin tautan berikut ke browser Anda:<br>
                        <a href="' . $resetUrl . '" style="color: #38bdf8; word-break: break-all;">' . $resetUrl . '</a>
                    </p>
                    <hr style="border: 0; border-top: 1px solid #1e293b; margin: 20px 0;">
                    <p style="color: #64748b; font-size: 11px; margin-bottom: 0;">
                        Jika Anda tidak merasa meminta reset password ini, Anda dapat mengabaikan email ini dengan aman.
                    </p>
                </div>
            </body>
            </html>';

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
$currentUser = null;
$projects = [];
$currentTab = $_GET['tab'] ?? 'tasks'; // Default tab: tasks
if ($currentTab === 'guide') $currentTab = 'bantuan';
$currentSubTab = $_GET['sub'] ?? 'perintah'; // Sub tab di Bantuan: perintah / petunjuk

if (!empty($_SESSION['tvsc_uid'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['tvsc_uid']]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

    // Jika akun user diblokir saat sesi aktif
    if ($currentUser && !empty($currentUser['is_blocked'])) {
        unset($_SESSION['tvsc_uid']);
        session_destroy();
        $currentUser = null;
        $errorMsg = '❌ Akun Anda telah diblokir oleh Administrator.';
    }
}

if ($currentUser) {
    if (empty($currentUser['api_key'])) {
        $currentUser['api_key'] = 'tvsc_' . bin2hex(random_bytes(16));
        $db->prepare("UPDATE users SET api_key = ? WHERE id = ?")->execute([$currentUser['api_key'], $currentUser['id']]);
    }
    $isSuperAdmin = (strtolower($currentUser['email']) === strtolower(SUPERADMIN_EMAIL) || !empty($currentUser['is_admin']));

    // --------------------------------------------------------------------------
    // A. Superadmin Actions (Manajemen Pengguna, Blok Akses, Blok Notifikasi)
    // --------------------------------------------------------------------------
    if ($isSuperAdmin) {
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

        // Simpan Konfigurasi SMTP Server
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

            $successMsg = '✅ Konfigurasi Server SMTP berhasil disimpan!';
            $currentTab = 'admin';
        }

        // Uji Koneksi & Tes Kirim Email SMTP
        if ($action === 'test_smtp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $testEmail = trim($_POST['test_email'] ?? $currentUser['email']);
            $debugLog = '';
            $testSubject = "🧪 Uji Coba Konfigurasi SMTP - Tele-vs-Code (" . date('d-m-Y H:i:s') . ")";
            $testHtml = "
            <div style='background: #090d16; color: #f8fafc; padding: 20px; font-family: sans-serif;'>
                <div style='max-width: 500px; margin: 0 auto; background: #131c2e; padding: 24px; border-radius: 8px; border: 1px solid #1e293b;'>
                    <h3 style='color: #22c55e; margin-top: 0;'>✅ Tes Koneksi SMTP Berhasil!</h3>
                    <p style='color: #cbd5e1; font-size: 14px; line-height: 1.5;'>
                        Halo Administrator, server SMTP Anda telah terhubung dan berhasil mengirimkan email uji coba ini.
                    </p>
                    <p style='color: #cbd5e1; font-size: 13px;'><b>Waktu Uji:</b> " . date('d-m-Y H:i:s') . "<br><b>Penerima:</b> " . htmlspecialchars($testEmail) . "</p>
                    <hr style='border: 0; border-top: 1px solid #1e293b; margin: 16px 0;'>
                    <small style='color: #94a3b8;'>Dikirim otomatis oleh Tele-vs-Code SMTP Mailer</small>
                </div>
            </div>";

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
                $ins = $db->prepare("INSERT INTO projects (user_id, project_key, name, code, bot_token, chat_id, timezone, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$currentUser['id'], $pkey, $name, $code, $botToken, $chatId, $timezone, $now, $now]);
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
            $upd = $db->prepare("UPDATE projects SET name = ?, code = ?, bot_token = ?, chat_id = ?, timezone = ?, updated_at = ? WHERE id = ? AND user_id = ?");
            $upd->execute([$name, $code, $botToken, $chatId, $timezone, date('Y-m-d H:i:s'), $pid, $currentUser['id']]);

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
        $currentTab = 'projects';
    }

    // Pasang Webhook Otomatis ke Telegram Bot Proyek
    if ($action === 'set_telegram_webhook') {
        $pid = (int)($_GET['id'] ?? 0);
        $stmtP = $db->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
        $stmtP->execute([$pid, $currentUser['id']]);
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
        $stmtP = $db->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
        $stmtP->execute([$pid, $currentUser['id']]);
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
        $stmtP = $db->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
        $stmtP->execute([$pid, $currentUser['id']]);
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
                $errorMsg = "⚠️ <b>Hasil Tes Bot:</b> " . ($diag['error_detail'] ?: ($diag['msg_error'] ?: 'Periksa Chat ID atau Webhook'));
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
    // C. Task Actions (Add Manual, Mark Done, Delete)
    // --------------------------------------------------------------------------
    if ($action === 'add_manual_task' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $prjId = (int)($_POST['project_id'] ?? 0);
        $instruction = trim($_POST['instruction'] ?? '');
        $sender = trim($_POST['sender'] ?? 'Dashboard Web');
        $command = trim($_POST['command'] ?? 'Web Dashboard');

        if (!$instruction) {
            $errorMsg = 'Instruksi tugas tidak boleh kosong!';
        } else {
            $taskId = '#TASK-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            $now = date('Y-m-d H:i:s');
            $ins = $db->prepare("INSERT INTO tasks (id, user_id, project_id, instruction, sender, status, created_at, updated_at, command) VALUES (?, ?, ?, ?, ?, 'PENDING', ?, ?, ?)");
            $ins->execute([$taskId, $currentUser['id'], $prjId, $instruction, $sender, $now, $now, $command]);
            $successMsg = "✅ Tugas <b>$taskId</b> berhasil ditambahkan ke antrean!";
            $currentTab = 'tasks';
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
            $upd->execute([$newStat, $now, $resultText, $resultText, $commandText, $tid, $currentUser['id']]);
        } else {
            $upd = $db->prepare("UPDATE tasks SET status = ?, updated_at = ?, notes = ?, result = ? WHERE id = ? AND user_id = ?");
            $upd->execute([$newStat, $now, $resultText, $resultText, $tid, $currentUser['id']]);
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
            $upd->execute([$newStat, $resultText, $resultText, $commandText, $now, $tid, $currentUser['id']]);
        } else {
            $upd = $db->prepare("UPDATE tasks SET result = ?, notes = ?, command = ?, updated_at = ? WHERE id = ? AND user_id = ?");
            $upd->execute([$resultText, $resultText, $commandText, $now, $tid, $currentUser['id']]);
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

    // Load Projects milik User
    $stmtP = $db->prepare("SELECT * FROM projects WHERE user_id = ? ORDER BY id ASC");
    $stmtP->execute([$currentUser['id']]);
    $projects = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    // Load Whitelist Users milik User (Private Chat)
    $stmtW = $db->prepare("SELECT w.*, p.name as project_name FROM bot_whitelist w LEFT JOIN projects p ON w.project_id = p.id WHERE w.user_id = ? ORDER BY w.id DESC");
    $stmtW->execute([$currentUser['id']]);
    $whitelistUsers = $stmtW->fetchAll(PDO::FETCH_ASSOC);

    // Filter Tasks per Project
    $filterProjectId = isset($_GET['filter_project']) ? (int)$_GET['filter_project'] : 0;
    $filterStatus = $_GET['filter_status'] ?? '';

    $taskSql = "SELECT t.*, p.name as project_name, p.code as project_code, p.timezone as project_timezone FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.user_id = ?";
    $taskParams = [$currentUser['id']];

    if ($filterProjectId > 0) {
        $taskSql .= " AND t.project_id = ?";
        $taskParams[] = $filterProjectId;
    }
    if ($filterStatus && in_array($filterStatus, ['PENDING', 'IN_PROGRESS', 'DONE', 'FAILED'])) {
        $taskSql .= " AND t.status = ?";
        $taskParams[] = $filterStatus;
    }
    $taskSql .= " ORDER BY t.created_at DESC LIMIT 50";

    $stmtTasks = $db->prepare($taskSql);
    $stmtTasks->execute($taskParams);
    $myTasks = $stmtTasks->fetchAll(PDO::FETCH_ASSOC);

    // Hitung Statistik Tugas User
    $stmtCount = $db->prepare("SELECT status, count(*) as c FROM tasks WHERE user_id = ?" . ($filterProjectId > 0 ? " AND project_id = $filterProjectId" : "") . " GROUP BY status");
    $stmtCount->execute([$currentUser['id']]);
    $taskStats = ['TOTAL' => 0, 'PENDING' => 0, 'IN_PROGRESS' => 0, 'DONE' => 0, 'FAILED' => 0];
    while ($sc = $stmtCount->fetch(PDO::FETCH_ASSOC)) {
        $taskStats[$sc['status']] = $sc['c'];
        $taskStats['TOTAL'] += $sc['c'];
    }

    // Data Khusus Superadmin (Tab Admin)
    $allUsers = [];
    $adminStats = ['total_users' => 0, 'blocked_users' => 0, 'blocked_notify' => 0, 'total_projects' => 0, 'total_tasks' => 0];
    if ($isSuperAdmin) {
        $stmtAll = $db->query("
            SELECT u.*, 
                   COUNT(DISTINCT p.id) as project_count, 
                   COUNT(DISTINCT t.id) as task_count 
            FROM users u 
            LEFT JOIN projects p ON u.id = p.user_id 
            LEFT JOIN tasks t ON u.id = t.user_id 
            GROUP BY u.id 
            ORDER BY u.id ASC
        ");
        $allUsers = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

        $adminStats['total_users'] = count($allUsers);
        foreach ($allUsers as $au) {
            if (!empty($au['is_blocked'])) $adminStats['blocked_users']++;
            if (!empty($au['block_notify'])) $adminStats['blocked_notify']++;
            $adminStats['total_projects'] += (int)$au['project_count'];
            $adminStats['total_tasks'] += (int)$au['task_count'];
        }
    }
}

function renderSvg($name, $size = 15, $class = '') {
    $icons = [
        'bell' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
        'download' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        'user' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'logout' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
        'tasks' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></svg>',
        'settings' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
        'help' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><circle cx="12" cy="12" r="10"/><path d="9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        'shield' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'plus' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
        'check' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M20 6L9 17l-5-5"/></svg>',
        'eye' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>',
        'trash' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>',
        'copy' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>',
        'terminal' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>',
        'clock' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'play' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><polygon points="5 3 19 12 5 21 5 3"/></svg>',
        'alert' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        'layers' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>',
        'photo' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>',
        'video' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><rect width="14" height="14" x="2" y="5" rx="2"/><polygon points="22 7 16 12 22 17 22 7"/></svg>',
        'file' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>',
        'key' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>',
        'link' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        'bot' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><rect width="18" height="12" x="3" y="6" rx="2"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><path d="M12 2v4"/><path d="M2 10h1"/><path d="M21 10h1"/></svg>',
        'close' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        'chevron-down' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><polyline points="6 9 12 15 18 9"/></svg>',
        'chevron-up' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><polyline points="18 15 12 9 6 15"/></svg>',
        'minimize' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>'
    ];
    return $icons[$name] ?? '';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Tele-vs-Code - Notifikasi pekerjaan AGEN AI di Visual Studio Code dengan Telegram</title>
    <meta name="theme-color" content="#0d0c0a">
    <meta name="view-transition" content="same-origin">
    <script>if ('onpagereveal' in window) document.documentElement.classList.add('has-vt');</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@300..600&family=Geist+Mono:wght@400;500&family=Instrument+Serif:ital@0;1&display=swap">
    <!-- Studio Ink Theme -->
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">
</head>
<body>

<!-- Hidden Flash Containers for Sonner Toast Engine -->
<?php if (!empty($successMsg)): ?>
    <div id="flashSuccessMessage" style="display:none;"><?= htmlspecialchars(strip_tags($successMsg)) ?></div>
<?php endif; ?>
<?php if (!empty($errorMsg)): ?>
    <div id="flashErrorMessage" style="display:none;"><?= htmlspecialchars(strip_tags($errorMsg)) ?></div>
<?php endif; ?>

<div class="container">
    <!-- Navbar Header -->
    <header class="navbar">
        <a href="./" class="navbar-brand">
            <span class="brand-icon-wrapper">
                <?= renderSvg('bell', 16) ?>
            </span>
            <span>tele<span class="brand-slash">/</span>vs<span class="brand-slash">/</span>code</span>
        </a>
        <div class="navbar-actions">
            <a class="btn btn-secondary btn-sm" href="?action=download_vsix" title="Download ekstensi VS Code offline (.VSIX)">
                <?= renderSvg('download', 14) ?>
                <span>Unduh VSIX</span>
            </a>
            <?php if ($currentUser): ?>
                <span class="user-chip">
                    <?= renderSvg('user', 12) ?>
                    <span><?= htmlspecialchars($currentUser['email']) ?></span>
                    <?php if (!empty($isSuperAdmin)): ?>
                        <span class="badge badge-pending" style="height: 18px; font-size: 9.5px; padding: 0 6px;">ADMIN</span>
                    <?php endif; ?>
                </span>
                <a class="btn btn-outline btn-sm" href="?action=logout" title="Keluar dari akun">
                    <?= renderSvg('logout', 13) ?>
                    <span>Keluar</span>
                </a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$currentUser): ?>
        <!-- ==================================================================== -->
        <!-- PUBLIC LANDING / LOGIN & REGISTER (iOS Minimalist Monochrome)         -->
        <!-- ==================================================================== -->
        <main class="landing">
            <section class="landing-copy">
                <div class="landing-eyebrow">VS Code &nbsp;&harr;&nbsp; Telegram</div>
                <h1 class="landing-title">
                    Agen AI bekerja<br>
                    di editor. <em>Kabarnya</em><br>
                    <span class="muted">sampai di saku.</span>
                </h1>
                <p class="landing-lead">
                    Kirim instruksi dari Telegram, biarkan agen di VS Code mengerjakannya,
                    lalu terima laporan selesai &mdash; lengkap dengan hasil dan command yang dijalankan.
                    Tidak perlu menunggu di depan layar.
                </p>

                <ol class="landing-steps">
                    <li>
                        <span class="num">01</span>
                        <h3>Tulis perintah di Telegram</h3>
                        <p>Pesan biasa, foto, atau dokumen &mdash; pakai tag <code>#a</code> untuk memilih proyek.</p>
                    </li>
                    <li>
                        <span class="num">02</span>
                        <h3>Agen mengambil antrean</h3>
                        <p>Ekstensi VS Code menarik tugas dan agen mengerjakannya di folder yang tepat.</p>
                    </li>
                    <li>
                        <span class="num">03</span>
                        <h3>Laporan kembali ke kamu</h3>
                        <p>Status PROSES, SELESAI, atau KENDALA dikirim otomatis beserta ringkasannya.</p>
                    </li>
                </ol>

                <div class="transcript" aria-hidden="true">
                    <div class="transcript-head"><span>terminal &middot; vs code</span><span>tele-notify</span></div>
                    <span class="ln"><span class="p">$</span> tele-task add "Perbaiki validasi form login"</span>
                    <span class="ln"><span class="dim">&rarr;</span> #TASK-0412 tercatat &middot; <span class="dim">IN_PROGRESS</span></span>
                    <span class="ln"><span class="p">$</span> npm test <span class="dim">&mdash; 48 passed</span></span>
                    <span class="ln"><span class="p">$</span> tele-task done "#TASK-0412" "Validasi email &amp; WA selesai"</span>
                    <span class="ln caret"><span class="ok">&check;</span> terkirim ke Telegram &middot; 0.4s</span>
                </div>
            </section>

            <div class="auth-wrapper">
            <div class="card auth-card">
                <div class="auth-header">
                    <div class="auth-brand-glyph"><?= renderSvg('bell', 20) ?></div>
                    <h2 class="auth-title">Masuk ke ruang kerja</h2>
                    <p class="auth-subtitle">Satu akun untuk semua proyek dan bot kamu.</p>
                </div>
                <!-- Segmented Control Switcher -->
                <div class="auth-segmented">
                    <button type="button" class="auth-segmented-btn active" id="btnAuthLogin" onclick="switchAuthTab('login')">Masuk</button>
                    <button type="button" class="auth-segmented-btn" id="btnAuthRegister" onclick="switchAuthTab('register')">Daftar Baru</button>
                </div>

                <!-- Box 1: Form Login -->
                <div id="boxLogin">
                    <form method="POST" action="?action=login">
                        <div class="form-group">
                            <label>Email atau Nomor WhatsApp:</label>
                            <input type="text" name="identifier" placeholder="nama@email.com atau 08123456789" required autocomplete="username">
                        </div>
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label>Password:</label>
                            <input type="password" name="password" placeholder="Password Anda" required autocomplete="current-password">
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%; height: 42px;">
                            <span>Masuk</span>
                        </button>
                    </form>
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12px; margin-top: 16px; flex-wrap: wrap; gap: 8px;">
                        <a href="javascript:void(0)" onclick="switchAuthTab('register')" style="color: var(--text-secondary);">Belum punya akun? <b style="color: #fff;">Daftar</b></a>
                        <a href="javascript:void(0)" onclick="switchAuthTab('forgot')" style="color: var(--text-muted); text-decoration: underline;">Lupa Password?</a>
                    </div>
                </div>

                <!-- Box 2: Form Register -->
                <div id="boxRegister" style="display: none;">
                    <form method="POST" action="?action=register">
                        <div class="form-group">
                            <label>Alamat Email (Unik):</label>
                            <input type="email" name="email" placeholder="nama@email.com" required autocomplete="email">
                        </div>
                        <div class="form-group">
                            <label>Nomor WhatsApp Aktif (Unik):</label>
                            <input type="text" name="whatsapp" placeholder="081234567890" required autocomplete="tel">
                            <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;">Untuk login alternatif dan pemulihan akun.</small>
                        </div>
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label>Password (Minimal 6 Karakter):</label>
                            <input type="password" name="password" minlength="6" placeholder="Kombinasi huruf & angka" required autocomplete="new-password">
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%; height: 38px;">
                            <span>Daftar & Buat Akun</span>
                        </button>
                    </form>
                    <div style="text-align: center; font-size: 12px; margin-top: 16px;">
                        <a href="javascript:void(0)" onclick="switchAuthTab('login')" style="color: var(--text-secondary);">Sudah punya akun? <b style="color: #fff;">Masuk</b></a>
                    </div>
                </div>

                <!-- Box 3: Form Lupa Password -->
                <div id="boxForgot" style="display: none;">
                    <div style="text-align: center; margin-bottom: 16px;">
                        <h3 style="font-size: 15px; font-weight: 500; color: #fff;">Pemulihan Password</h3>
                        <p style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">Tautan reset aman akan dikirimkan ke email terdaftar Anda.</p>
                    </div>
                    <form method="POST" action="?action=forgot_password">
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label>Alamat Email Terdaftar:</label>
                            <input type="email" name="email" placeholder="nama@email.com" required autocomplete="email">
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%; height: 38px;">
                            <span>Kirim Link Reset Password</span>
                        </button>
                    </form>
                    <div style="text-align: center; font-size: 12px; margin-top: 16px;">
                        <a href="javascript:void(0)" onclick="switchAuthTab('login')" style="color: var(--text-secondary);">Kembali ke Login</a>
                    </div>
                </div>

                <!-- Box 4: Form Reset Password Baru (dari Link Email) -->
                <?php if (!empty($isTokenValid) && !empty($resetToken)): ?>
                    <div id="boxResetPass">
                        <div style="text-align: center; margin-bottom: 16px;">
                            <h3 style="font-size: 15px; font-weight: 500; color: #fff;">Buat Password Baru</h3>
                            <p style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">Akun: <b><?= htmlspecialchars($resetEmail) ?></b></p>
                        </div>
                        <form method="POST" action="?action=do_reset_password">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($resetToken) ?>">
                            <div class="form-group">
                                <label>Password Baru (Min 6 Karakter):</label>
                                <input type="password" name="new_password" minlength="6" placeholder="Password baru" required autocomplete="new-password">
                            </div>
                            <div class="form-group" style="margin-bottom: 20px;">
                                <label>Konfirmasi Password Baru:</label>
                                <input type="password" name="confirm_password" minlength="6" placeholder="Ketik ulang password baru" required autocomplete="new-password">
                            </div>
                            <button type="submit" class="btn btn-primary" style="width: 100%; height: 38px;">
                                <span>Simpan Password Baru</span>
                            </button>
                        </form>
                        <div style="text-align: center; font-size: 12px; margin-top: 16px;">
                            <a href="./" style="color: var(--text-secondary);">Batal</a>
                        </div>
                    </div>
                    <script>
                    window.addEventListener('DOMContentLoaded', () => {
                        switchAuthTab('reset');
                    });
                    </script>
                <?php endif; ?>
            </div>
            </div>
        </main>

    <?php else: ?>
        <!-- ==================================================================== -->
        <!-- USER DASHBOARD (iOS Minimalist Monochrome)                           -->
        <!-- ==================================================================== -->

        <?php
            $tzDash = !empty($currentUser['timezone']) ? $currentUser['timezone'] : 'Asia/Jakarta';
            try { $nowDash = new DateTime('now', new DateTimeZone($tzDash)); } catch (Exception $e) { $nowDash = new DateTime(); }
            $hDash = (int)$nowDash->format('G');
            $greet = $hDash < 11 ? 'Selamat pagi' : ($hDash < 15 ? 'Selamat siang' : ($hDash < 19 ? 'Selamat sore' : 'Selamat malam'));
            $nameDash = ucfirst(strtok($currentUser['email'], '@'));
            $hariId = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'][(int)$nowDash->format('w')];
            $openTasks = (int)$taskStats['PENDING'] + (int)$taskStats['IN_PROGRESS'];
        ?>
        <div class="dash-head">
            <div>
                <div class="dash-eyebrow"><span class="dot"></span><?= $hariId ?>, <?= $nowDash->format('d.m.Y') ?> &middot; <?= $nowDash->format('H:i') ?> <?= htmlspecialchars(getTimezoneAbbr($tzDash)) ?></div>
                <h1 class="dash-greeting"><?= $greet ?>, <?= htmlspecialchars($nameDash) ?>.<br><em><?= $openTasks > 0 ? $openTasks . ' tugas masih berjalan.' : 'Semua antrean beres.' ?></em></h1>
            </div>
        </div>

        <!-- Main Navigation (liquid tab bar) -->
        <nav class="main-nav" aria-label="Navigasi utama">
            <a href="?tab=tasks<?= $filterProjectId ? "&filter_project=$filterProjectId" : "" ?>" class="nav-pill <?= $currentTab === 'tasks' ? 'active' : '' ?>">
                <?= renderSvg('tasks', 14) ?>
                <span>Antrean Tugas (<?= $taskStats['TOTAL'] ?>)</span>
            </a>
            <a href="?tab=projects" class="nav-pill <?= $currentTab === 'projects' ? 'active' : '' ?>">
                <?= renderSvg('settings', 14) ?>
                <span>Pengaturan & Proyek (<?= count($projects) ?>)</span>
            </a>
            <a href="?tab=bantuan" class="nav-pill <?= $currentTab === 'bantuan' ? 'active' : '' ?>">
                <?= renderSvg('help', 14) ?>
                <span>Bantuan</span>
            </a>
            <?php if (!empty($isSuperAdmin)): ?>
                <a href="?tab=admin" class="nav-pill nav-pill-admin <?= $currentTab === 'admin' ? 'active' : '' ?>">
                    <?= renderSvg('shield', 14) ?>
                    <span>Superadmin</span>
                </a>
            <?php endif; ?>
        </nav>

        <?php if ($currentTab === 'tasks'): ?>
            <!-- ================================================================ -->
            <!-- TAB 1: ANTREAN TUGAS BERDASARKAN PROYEK                          -->
            <!-- ================================================================ -->
            <section class="tab-pane">
                <!-- 4 Counter Stats Cards (Slender Typography) -->
                <div class="grid-4" style="margin-bottom: 22px;">
                    <div class="stat-card">
                        <div class="val"><?= $taskStats['TOTAL'] ?></div>
                        <div class="lbl">
                            <?= renderSvg('layers', 12) ?>
                            <span>Total Tugas</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="val" style="color: var(--accent-warning);"><?= $taskStats['PENDING'] ?></div>
                        <div class="lbl">
                            <?= renderSvg('clock', 12) ?>
                            <span>Menunggu (PENDING)</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="val" style="color: var(--accent-info);"><?= $taskStats['IN_PROGRESS'] ?></div>
                        <div class="lbl">
                            <?= renderSvg('play', 12) ?>
                            <span>Sedang Dikerjakan</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="val" style="color: var(--accent-success);"><?= $taskStats['DONE'] ?></div>
                        <div class="lbl">
                            <?= renderSvg('check', 12) ?>
                            <span>Selesai (DONE)</span>
                        </div>
                    </div>
                </div>

                <!-- Filter & Tambah Tugas Bar -->
                <div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
                    <form method="GET" action="./" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
                        <input type="hidden" name="tab" value="tasks">
                        
                        <div style="flex: 1; min-width: 200px;">
                            <label>Filter Proyek:</label>
                            <select name="filter_project" onchange="this.form.submit()">
                                <option value="0">Semua Proyek (<?= count($projects) ?> Proyek)</option>
                                <?php foreach ($projects as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= $filterProjectId == $p['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="width: 180px;">
                            <label>Status Tugas:</label>
                            <select name="filter_status" onchange="this.form.submit()">
                                <option value="">Semua Status</option>
                                <option value="PENDING" <?= $filterStatus === 'PENDING' ? 'selected' : '' ?>>PENDING (Menunggu)</option>
                                <option value="IN_PROGRESS" <?= $filterStatus === 'IN_PROGRESS' ? 'selected' : '' ?>>IN_PROGRESS (Dikerjakan)</option>
                                <option value="DONE" <?= $filterStatus === 'DONE' ? 'selected' : '' ?>>DONE (Selesai)</option>
                            </select>
                        </div>

                        <div style="padding-bottom: 1px;">
                            <button type="button" class="btn btn-secondary" onclick="const b = document.getElementById('boxAddTask'); b.style.display = b.style.display === 'none' ? 'block' : 'none';">
                                <?= renderSvg('plus', 13) ?>
                                <span>Tambah Manual</span>
                            </button>
                        </div>
                    </form>

                    <!-- Box Tambah Tugas Manual (Collapsible) -->
                    <div id="boxAddTask" style="display: none; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border-subtle);">
                        <form method="POST" action="?action=add_manual_task">
                            <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                            <div class="grid-2">
                                <div class="form-group">
                                    <label>Pilih Proyek Tujuan:</label>
                                    <select name="project_id" required>
                                        <?php foreach ($projects as $p): ?>
                                            <option value="<?= $p['id'] ?>" <?= $filterProjectId == $p['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($p['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Pengirim / Pemohon:</label>
                                    <input type="text" name="sender" value="Dashboard User" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Instruksi Tugas Coding:</label>
                                <textarea name="instruction" rows="2" placeholder="Contoh: Buatkan endpoint CRUD produk dengan validasi input..." required></textarea>
                            </div>
                            <div style="display: flex; gap: 8px;">
                                <button type="submit" class="btn btn-primary btn-sm">Simpan ke Antrean</button>
                                <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('boxAddTask').style.display='none'">Batal</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tabel Antrean Tugas -->
                <div class="card" style="padding: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                        <h2 style="font-size: 15px; font-weight: 500; color: #fff; display: flex; align-items: center; gap: 8px;">
                            <?= renderSvg('tasks', 15) ?>
                            <span>Daftar Antrean Tugas</span>
                            <?php if ($filterProjectId > 0): ?>
                                <?php 
                                    $curPName = 'Proyek Terpilih';
                                    foreach ($projects as $p) { if ($p['id'] == $filterProjectId) $curPName = $p['name']; }
                                ?>
                                <span class="badge badge-project"><?= htmlspecialchars($curPName) ?></span>
                            <?php endif; ?>
                        </h2>
                        <span style="font-size: 12px; color: var(--text-muted);"><?= count($myTasks) ?> Tugas Ditampilkan</span>
                    </div>

                    <?php if (empty($myTasks)): ?>
                        <div style="text-align: center; padding: 48px 16px; color: var(--text-muted);">
                            <div style="width: 44px; height: 44px; border-radius: 50%; background: var(--bg-surface-inset); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px; color: var(--text-dim);">
                                <?= renderSvg('tasks', 20) ?>
                            </div>
                            <p style="font-size: 13.5px; font-weight: 400; color: var(--text-secondary);">Belum ada tugas di antrean.</p>
                            <p style="font-size: 12px; margin-top: 4px;">Kirim pesan <code>/task &lt;instruksi&gt;</code> di Telegram atau klik <b>Tambah Manual</b> di atas.</p>
                        </div>
                    <?php else: ?>
                        <div class="task-cards-list">
                            <?php foreach ($myTasks as $t): ?>
                                <?php 
                                    $pTz = !empty($t['project_timezone']) ? $t['project_timezone'] : (!empty($currentUser['timezone']) ? $currentUser['timezone'] : 'Asia/Jakarta');
                                    $tAbbr = getTimezoneAbbr($pTz);
                                    $resText = !empty($t['result']) ? $t['result'] : (!empty($t['notes']) && $t['status'] === 'DONE' ? $t['notes'] : '');
                                    
                                    // Tentukan teks command yang ditampilkan di Baris 2
                                    $cmdDisplay = !empty($t['command']) ? trim($t['command']) : '';
                                    if (!$cmdDisplay) {
                                        $pCode = !empty($t['project_code']) ? strtolower($t['project_code']) : 'a';
                                        if ($t['sender'] === 'IDE Chat' || $t['sender'] === 'VS Code') {
                                            $cmdDisplay = 'tele-task add --sender "' . $t['sender'] . '"';
                                        } elseif (!empty($t['instruction']) && preg_match('/^\/([a-zA-Z0-9_-]+)/', $t['instruction'], $mCmd)) {
                                            $cmdDisplay = '/' . $mCmd[1];
                                        } elseif (!empty($t['instruction']) && preg_match('/^#([a-zA-Z0-9_-]+)/', $t['instruction'], $mCmd)) {
                                            $cmdDisplay = '#' . $mCmd[1];
                                        } else {
                                            $cmdDisplay = '#' . $pCode;
                                        }
                                    }
                                ?>
                                <div class="task-card" id="task-<?= htmlspecialchars($t['id']) ?>">
                                    <!-- ============================================================== -->
                                    <!-- BARIS 1: ID & WAKTU, PROYEK, PENGIRIM, STATUS, AKSI           -->
                                    <!-- ============================================================== -->
                                    <div class="task-card-row-meta">
                                        <div class="task-meta-left">
                                            <code class="task-id-badge"><?= htmlspecialchars($t['id']) ?></code>
                                            <span class="task-time-text">
                                                <?= htmlspecialchars($t['created_at']) ?> <b><?= $tAbbr ?></b>
                                            </span>
                                            <span class="badge badge-project">
                                                <?= htmlspecialchars($t['project_name'] ?: 'Tanpa Proyek') ?>
                                            </span>
                                            <span class="task-sender-badge">
                                                <?= renderSvg('user', 12) ?>
                                                <span><?= htmlspecialchars($t['sender']) ?></span>
                                            </span>
                                        </div>
                                        <div class="task-meta-right">
                                            <?php if ($t['status'] === 'PENDING'): ?>
                                                <span class="badge badge-pending">PENDING</span>
                                            <?php elseif ($t['status'] === 'IN_PROGRESS'): ?>
                                                <span class="badge badge-progress">PROGRESS</span>
                                            <?php elseif ($t['status'] === 'DONE'): ?>
                                                <span class="badge badge-done">DONE</span>
                                            <?php else: ?>
                                                <span class="badge badge-failed"><?= htmlspecialchars($t['status']) ?></span>
                                            <?php endif; ?>

                                            <div class="task-card-actions">
                                                <button type="button" class="btn btn-icon btn-ghost" style="color: var(--accent-info);" onclick="openTaskDetail(<?= htmlspecialchars(json_encode($t, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($tAbbr), ENT_QUOTES, 'UTF-8') ?>)" title="Lihat & Edit Hasil">
                                                    <?= renderSvg('eye', 14) ?>
                                                </button>
                                                <?php if ($t['status'] !== 'DONE'): ?>
                                                    <button type="button" class="btn btn-icon btn-ghost" style="color: var(--accent-success);" onclick="promptMarkDone('<?= htmlspecialchars($t['id']) ?>')" title="Tandai Selesai (DONE)">
                                                        <?= renderSvg('check', 14) ?>
                                                    </button>
                                                <?php endif; ?>
                                                <a href="?action=delete_task&id=<?= urlencode($t['id']) ?>&csrf=<?= getCsrfToken() ?>" onclick="return confirm('Hapus tugas <?= htmlspecialchars($t['id']) ?> dari antrean?')" class="btn btn-icon btn-ghost" style="color: var(--accent-danger);" title="Hapus Tugas">
                                                    <?= renderSvg('trash', 14) ?>
                                                </a>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ============================================================== -->
                                    <!-- BARIS 2: INSTRUKSI DAN KOMEN / COMMAND -> LEBAR FULL 100%     -->
                                    <!-- ============================================================== -->
                                    <div class="task-card-row-body">
                                        <div class="task-card-instruction">
                                            <?= nl2br(htmlspecialchars($t['instruction'])) ?>
                                        </div>

                                        <?php if (!empty($t['attachment_url'])): ?>
                                            <div class="task-card-attachment">
                                                <?php if ($t['attachment_type'] === 'photo'): ?>
                                                    <a href="<?= htmlspecialchars($t['attachment_url']) ?>" target="_blank" class="badge" style="background: rgba(56, 189, 248, 0.12); color: #7dd3fc; border-color: rgba(56, 189, 248, 0.3);">
                                                        <?= renderSvg('photo', 12) ?> Lihat Foto: <?= htmlspecialchars($t['attachment_name']) ?>
                                                    </a>
                                                <?php elseif ($t['attachment_type'] === 'video'): ?>
                                                    <a href="<?= htmlspecialchars($t['attachment_url']) ?>" target="_blank" class="badge" style="background: rgba(168, 85, 247, 0.12); color: #d8b4fe; border-color: rgba(168, 85, 247, 0.3);">
                                                        <?= renderSvg('video', 12) ?> Lihat Video: <?= htmlspecialchars($t['attachment_name']) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="<?= htmlspecialchars($t['attachment_url']) ?>" target="_blank" class="badge" style="background: rgba(16, 185, 129, 0.12); color: #86efac; border-color: rgba(16, 185, 129, 0.3);">
                                                        <?= renderSvg('file', 12) ?> Unduh Dokumen: <?= htmlspecialchars($t['attachment_name']) ?>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="task-card-command">
                                            <span class="task-cmd-prefix">
                                                <?= renderSvg('terminal', 12) ?>
                                                <span>Command:</span>
                                            </span>
                                            <code class="task-cmd-code"><?= htmlspecialchars($cmdDisplay) ?></code>
                                            <button type="button" class="btn btn-icon btn-ghost task-cmd-copy" onclick="copyText('<?= htmlspecialchars(addslashes($cmdDisplay)) ?>', 'Command')" title="Salin Command">
                                                <?= renderSvg('copy', 12) ?>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- ============================================================== -->
                                    <!-- BARIS 3: RESULT -> LEBAR FULL 100%                            -->
                                    <!-- ============================================================== -->
                                    <div class="task-card-row-result">
                                        <?php if (!empty($resText)): ?>
                                            <div class="task-result-box task-result-done">
                                                <div class="res-header">
                                                    <?= renderSvg('check', 12) ?>
                                                    <span>Result:</span>
                                                </div>
                                                <div class="task-result-content"><?= nl2br(htmlspecialchars($resText)) ?></div>
                                            </div>
                                        <?php elseif ($t['status'] === 'FAILED'): ?>
                                            <div class="task-result-box task-result-failed">
                                                <div class="res-header" style="color: #fda4af;">
                                                    <?= renderSvg('alert', 12) ?>
                                                    <span>Kendala / Error:</span>
                                                </div>
                                                <div class="task-result-content"><?= nl2br(htmlspecialchars($t['notes'] ?: 'Gagal diproses')) ?></div>
                                            </div>
                                        <?php elseif ($t['status'] === 'IN_PROGRESS'): ?>
                                            <div class="task-result-box task-result-progress">
                                                <div style="display: flex; align-items: center; gap: 6px; font-style: italic; color: #7dd3fc;">
                                                    <?= renderSvg('play', 11) ?>
                                                    <span>Sedang dikerjakan oleh AI Agent...</span>
                                                </div>
                                                <?php if (!empty($t['notes'])): ?>
                                                    <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 4px;">(<?= htmlspecialchars($t['notes']) ?>)</div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="task-result-box task-result-pending">
                                                <div style="display: flex; align-items: center; gap: 6px; font-style: italic;">
                                                    <?= renderSvg('clock', 11) ?>
                                                    <span>Menunggu giliran eksekusi oleh Agent / Developer...</span>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

        <?php elseif ($currentTab === 'projects'): ?>
            <!-- ================================================================ -->
            <!-- TAB 2: PENGATURAN PROYEK & KREDENSIAL                            -->
            <!-- ================================================================ -->
            <section class="tab-pane">
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
                            <button type="button" class="btn btn-secondary btn-sm" onclick="const p = document.getElementById('boxChangePass'); p.style.display = p.style.display === 'none' ? 'block' : 'none';">
                                <?= renderSvg('key', 13) ?>
                                <span>Ganti Password</span>
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
                                    <label>Password Baru (Min 6 Karakter):</label>
                                    <input type="password" name="new_password" minlength="6" placeholder="Password baru" required>
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
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;">
                            <div style="cursor: pointer;" onclick="toggleProjectCard(<?= $prj['id'] ?>)" title="Klik untuk membuka / menutup form pengaturan">
                                <h3 style="font-size: 16px; font-weight: 500; color: #fff; display: flex; align-items: center; gap: 8px;">
                                    <span><?= htmlspecialchars($prj['name']) ?></span>
                                    <span class="badge badge-done">Tag: #<?= htmlspecialchars($prj['code'] ?: 'a') ?></span>
                                    <span style="font-size: 11.5px; background: rgba(56, 189, 248, 0.1); color: #38bdf8; padding: 2px 10px; border-radius: 6px; border: 1px solid rgba(56, 189, 248, 0.3); display: inline-flex; align-items: center; gap: 6px;" onclick="event.stopPropagation();">
                                        <span>Project Key:</span> <code><?= htmlspecialchars($prj['project_key']) ?></code>
                                        <button type="button" class="btn btn-icon btn-ghost" style="width: 18px; height: 18px; color: #38bdf8; padding: 0;" onclick="event.stopPropagation(); copyText('<?= htmlspecialchars($prj['project_key']) ?>', 'Project Key')" title="Salin Project Key">
                                            <?= renderSvg('copy', 11) ?>
                                        </button>
                                    </span>
                                </h3>
                                <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px; display: flex; align-items: center; gap: 6px;" onclick="event.stopPropagation();">
                                    <span>Webhook:</span>
                                    <code><?= $webhookUrl ?></code>
                                    <button type="button" class="btn btn-icon btn-ghost" style="width: 20px; height: 20px;" onclick="event.stopPropagation(); copyText('<?= htmlspecialchars($webhookUrl) ?>', 'Webhook URL')" title="Salin Webhook">
                                        <?= renderSvg('copy', 11) ?>
                                    </button>
                                </div>
                            </div>
                            <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                                <?php if (!empty($prj['bot_token'])): ?>
                                    <a href="?action=test_bot&id=<?= $prj['id'] ?>" class="btn btn-secondary btn-sm" title="Uji Bot">
                                        <?= renderSvg('bot', 13) ?>
                                        <span>Tes Bot</span>
                                    </a>
                                    <a href="?action=set_telegram_webhook&id=<?= $prj['id'] ?>" class="btn btn-outline btn-sm" title="Pasang Webhook">
                                        <?= renderSvg('link', 13) ?>
                                        <span>Pasang Webhook</span>
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
                                        <a href="?action=test_bot&id=<?= $prj['id'] ?>" class="btn btn-outline btn-sm">Tes Koneksi</a>
                                    <?php endif; ?>
                                </div>
                                <?php if (count($projects) > 1): ?>
                                    <a href="?action=delete_project&id=<?= $prj['id'] ?>&csrf=<?= getCsrfToken() ?>" onclick="return confirm('Yakin ingin menghapus proyek <?= htmlspecialchars($prj['name']) ?>?')" class="btn btn-danger btn-sm" title="Hapus Proyek" style="display: inline-flex; align-items: center; gap: 6px;">
                                        <?= renderSvg('trash', 13) ?>
                                        <span>Hapus Proyek</span>
                                    </a>
                                <?php endif; ?>
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

        <?php elseif ($currentTab === 'bantuan' || $currentTab === 'guide'): ?>
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
                        <span class="badge badge-done"><?= count(getDefaultCommands()) ?> Perintah Tersedia</span>
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

        <?php elseif ($currentTab === 'admin' && !empty($isSuperAdmin)): ?>
            <!-- ================================================================ -->
            <!-- TAB 4: SUPERADMIN USER MANAGEMENT                                -->
            <!-- ================================================================ -->
            <section class="tab-pane">
                <div class="grid-4" style="margin-bottom: 22px;">
                    <div class="stat-card">
                        <div class="val" style="color: #fef08a;"><?= $adminStats['total_users'] ?></div>
                        <div class="lbl">Total Pengguna</div>
                    </div>
                    <div class="stat-card">
                        <div class="val"><?= $adminStats['total_projects'] ?></div>
                        <div class="lbl">Total Proyek</div>
                    </div>
                    <div class="stat-card">
                        <div class="val" style="color: <?= $adminStats['blocked_users'] > 0 ? '#fda4af' : '#86efac' ?>;"><?= $adminStats['blocked_users'] ?></div>
                        <div class="lbl">Akses Diblokir</div>
                    </div>
                    <div class="stat-card">
                        <div class="val" style="color: <?= $adminStats['blocked_notify'] > 0 ? '#fda4af' : '#86efac' ?>;"><?= $adminStats['blocked_notify'] ?></div>
                        <div class="lbl">Notif Diblokir</div>
                    </div>
                </div>

                <!-- Tombol Tambah Pengguna Manual -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h3 style="font-size: 16px; font-weight: 500; color: #fff; display: flex; align-items: center; gap: 8px;">
                        <?= renderSvg('shield', 16) ?>
                        <span>Manajemen Pengguna & Kontrol Akses</span>
                    </h3>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="const b = document.getElementById('boxAdminNewUser'); b.style.display = b.style.display === 'none' ? 'block' : 'none';">
                        <?= renderSvg('plus', 13) ?>
                        <span>Daftarkan Pengguna Baru</span>
                    </button>
                </div>

                <!-- Form Tambah User Manual (Collapsible) -->
                <div class="card" id="boxAdminNewUser" style="display: none; margin-bottom: 22px;">
                    <h4 style="font-size: 14px; font-weight: 500; color: #fff; margin-bottom: 14px;">Tambah Pengguna Baru Manual</h4>
                    <form method="POST" action="?action=admin_add_user">
                        <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                        <div class="grid-3">
                            <div class="form-group">
                                <label>Email Pengguna * :</label>
                                <input type="email" name="email" placeholder="user@email.com" required>
                            </div>
                            <div class="form-group">
                                <label>Nomor WhatsApp * :</label>
                                <input type="text" name="whatsapp" placeholder="081234567890" required>
                            </div>
                            <div class="form-group">
                                <label>Password * :</label>
                                <input type="password" name="password" minlength="6" placeholder="Minimal 6 karakter" required>
                            </div>
                        </div>
                        <div style="display: flex; gap: 8px; margin-top: 14px;">
                            <button type="submit" class="btn btn-primary btn-sm">Buat Pengguna</button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('boxAdminNewUser').style.display='none'">Batal</button>
                        </div>
                    </form>
                </div>

                <!-- Tabel Seluruh Pengguna -->
                <div class="card" style="margin-bottom: 24px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                        <h4 style="font-size: 14px; font-weight: 500; color: #fff;">Daftar Akun Pengguna</h4>
                        <span style="font-size: 12px; color: var(--text-muted);"><?= count($allUsers) ?> Pengguna</span>
                    </div>

                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 50px;">ID</th>
                                    <th>Email & Kontak</th>
                                    <th style="width: 90px; text-align: center;">Proyek</th>
                                    <th style="width: 90px; text-align: center;">Tugas</th>
                                    <th style="width: 140px; text-align: center;">Akses</th>
                                    <th style="width: 140px; text-align: center;">Notif Telegram</th>
                                    <th style="width: 140px; text-align: center;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allUsers as $u): ?>
                                    <?php $isTargetAdmin = (strtolower($u['email']) === strtolower(SUPERADMIN_EMAIL) || !empty($u['is_admin'])); ?>
                                    <tr>
                                        <td><code>#<?= $u['id'] ?></code></td>
                                        <td>
                                            <b><?= htmlspecialchars($u['email']) ?></b>
                                            <?php if ($isTargetAdmin): ?>
                                                <span class="badge" style="background: rgba(245, 158, 11, 0.2); color: #fef08a; font-size: 10px;">SUPERADMIN</span>
                                            <?php endif; ?>
                                            <br>
                                            <small style="color: var(--text-muted); font-size: 11px;">WA: <code><?= htmlspecialchars($u['whatsapp']) ?></code> &bull; Terdaftar: <?= htmlspecialchars(substr($u['created_at'], 0, 10)) ?></small>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="badge badge-project"><?= $u['project_count'] ?> Proyek</span>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="badge badge-done"><?= $u['task_count'] ?> Tugas</span>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if (!empty($u['is_blocked'])): ?>
                                                <span class="badge badge-failed" style="margin-bottom: 4px;">DIBLOKIR</span><br>
                                                <a href="?action=admin_toggle_block&user_id=<?= $u['id'] ?>&csrf=<?= getCsrfToken() ?>" class="btn btn-sm btn-outline" style="color: #6ee7b7; font-size: 11px;">Buka Blokir</a>
                                            <?php else: ?>
                                                <span class="badge badge-done" style="margin-bottom: 4px;">AKTIF</span><br>
                                                <?php if (!$isTargetAdmin): ?>
                                                    <a href="?action=admin_toggle_block&user_id=<?= $u['id'] ?>&csrf=<?= getCsrfToken() ?>" onclick="return confirm('Blokir akses untuk <?= htmlspecialchars($u['email']) ?>?')" class="btn btn-sm btn-outline" style="color: var(--accent-danger); font-size: 11px;">Blokir</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if (!empty($u['block_notify'])): ?>
                                                <span class="badge badge-failed" style="margin-bottom: 4px;">BLOCKED</span><br>
                                                <a href="?action=admin_toggle_notify&user_id=<?= $u['id'] ?>&csrf=<?= getCsrfToken() ?>" class="btn btn-sm btn-outline" style="color: #6ee7b7; font-size: 11px;">Izinkan</a>
                                            <?php else: ?>
                                                <span class="badge badge-done" style="margin-bottom: 4px;">AKTIF</span><br>
                                                <a href="?action=admin_toggle_notify&user_id=<?= $u['id'] ?>&csrf=<?= getCsrfToken() ?>" onclick="return confirm('Blokir notifikasi untuk <?= htmlspecialchars($u['email']) ?>?')" class="btn btn-sm btn-outline" style="color: var(--accent-danger); font-size: 11px;">Blokir</a>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <div style="display: flex; gap: 4px; justify-content: center;">
                                                <button type="button" class="btn btn-sm btn-outline" onclick="promptResetPass(<?= $u['id'] ?>, '<?= htmlspecialchars($u['email']) ?>')" title="Reset Password">
                                                    <?= renderSvg('key', 12) ?>
                                                </button>
                                                <?php if (!$isTargetAdmin): ?>
                                                    <a href="?action=admin_delete_user&user_id=<?= $u['id'] ?>&csrf=<?= getCsrfToken() ?>" onclick="return confirm('Hapus akun <?= htmlspecialchars($u['email']) ?> secara permanen?')" class="btn btn-sm btn-outline" style="color: var(--accent-danger);" title="Hapus Akun">
                                                        <?= renderSvg('trash', 12) ?>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Konfigurasi Server SMTP Email -->
                <div class="card">
                    <h3 style="font-size: 15px; font-weight: 500; color: #fff; margin-bottom: 6px;">Konfigurasi Server SMTP Email</h3>
                    <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 16px;">Untuk pengiriman email Lupa Password dan notifikasi sistem.</p>

                    <form method="POST" action="?action=save_smtp">
                        <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                        <div class="grid-3">
                            <div class="form-group">
                                <label>SMTP Host * :</label>
                                <input type="text" name="smtp_host" value="<?= htmlspecialchars(getSetting('smtp_host', '')) ?>" placeholder="mail.biru.co.id" required>
                            </div>
                            <div class="form-group">
                                <label>SMTP Port * :</label>
                                <input type="number" name="smtp_port" value="<?= htmlspecialchars(getSetting('smtp_port', '465')) ?>" placeholder="465" required>
                            </div>
                            <div class="form-group">
                                <label>Enkripsi Protokol :</label>
                                <?php $curCrypto = strtolower(getSetting('smtp_crypto', 'ssl')); ?>
                                <select name="smtp_crypto">
                                    <option value="ssl" <?= $curCrypto === 'ssl' ? 'selected' : '' ?>>SSL (Port 465 - Recommended)</option>
                                    <option value="tls" <?= $curCrypto === 'tls' ? 'selected' : '' ?>>TLS (Port 587)</option>
                                    <option value="none" <?= $curCrypto === 'none' ? 'selected' : '' ?>>None (Port 25)</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label>SMTP Username / Email :</label>
                                <input type="text" name="smtp_user" value="<?= htmlspecialchars(getSetting('smtp_user', '')) ?>" placeholder="no-reply@biru.co.id">
                            </div>
                            <div class="form-group">
                                <label>SMTP Password :</label>
                                <input type="password" name="smtp_pass" placeholder="<?= getSetting('smtp_pass') ? '(Password tersimpan)' : 'Password SMTP' ?>">
                            </div>
                        </div>

                        <div class="grid-2" style="margin-bottom: 16px;">
                            <div class="form-group">
                                <label>Nama Pengirim (From Name) :</label>
                                <input type="text" name="smtp_from_name" value="<?= htmlspecialchars(getSetting('smtp_from_name', 'Tele-vs-Code')) ?>" placeholder="Tele-vs-Code">
                            </div>
                            <div class="form-group">
                                <label>Email Pengirim (From Email) :</label>
                                <input type="email" name="smtp_from_email" value="<?= htmlspecialchars(getSetting('smtp_from_email', '')) ?>" placeholder="no-reply@biru.co.id">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-sm">Simpan Konfigurasi SMTP</button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

    <?php endif; ?>
</div>

<!-- ======================================================================== -->
<!-- MODALS (iOS Dialog Sheet Style)                                          -->
<!-- ======================================================================== -->

<!-- 1. Modal Detail & Edit Result Tugas -->
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
        </div>

        <div class="form-group">
            <label>Instruksi Tugas:</label>
            <div id="mTaskInstruction" class="card-inner" style="font-size: 13px; max-height: 120px; overflow-y: auto; line-height: 1.5; color: #fff;"></div>
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

<!-- Sonner Toast Engine & Client Controller -->
<script src="assets/js/liquid.js?v=<?= filemtime(__DIR__ . '/assets/js/liquid.js') ?>"></script>
<script src="assets/js/sonner.js?v=<?= filemtime(__DIR__ . '/assets/js/sonner.js') ?>"></script>
<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
<script>
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
            const res = await fetch(`?action=check_code_unique&code=${encodeURIComponent(rawVal)}&exclude_id=${excludeId}`);
            const data = await res.json();
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

</body>
</html>
