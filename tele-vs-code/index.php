<?php
// ==============================================================================
// Platform: Telegram Dev Notifier for VS Code & Antigravity
// URL: https://biru.co.id/tele-vs-code/
// Multi-User, Multi-Project System with Individual Telegram Bots, Custom Menus,
// Project-based Task Queue, Dedicated Settings, and Superadmin User Management.
// ==============================================================================

// Deteksi apakah request adalah Webhook atau API CLI / VS Code agar tidak memicu disk/session locking
$reqUri = $_SERVER['REQUEST_URI'] ?? '';
$actionParam = $_GET['action'] ?? ($_POST['action'] ?? '');
$isApiOrWebhook = (
    strpos($reqUri, '/tele-vs-code/webhook') !== false ||
    isset($_GET['is_webhook']) ||
    in_array($actionParam, ['pull', 'api_pull', 'push', 'api_push', 'done', 'api_done', 'failed', 'api_failed', 'in_progress', 'api_in_progress', 'status', 'api_status', 'projects', 'api_projects', 'webhook'])
);

if (!$isApiOrWebhook && session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// Language Setting (ID / EN)
if (isset($_GET['lang'])) {
    $selLang = strtolower(trim($_GET['lang'])) === 'en' ? 'en' : 'id';
    $_SESSION['tvsc_lang'] = $selLang;
    @setcookie('tvsc_lang', $selLang, time() + (86400 * 365), '/');
}
$currentLang = $_SESSION['tvsc_lang'] ?? ($_COOKIE['tvsc_lang'] ?? 'id');
if ($currentLang !== 'en') $currentLang = 'id';

function __t($idText, $enText) {
    global $currentLang;
    return ($currentLang === 'en') ? $enText : $idText;
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
            $verA = preg_replace('/^.*tele-vs-code-|\.vsix$/', '', $a);
            $verB = preg_replace('/^.*tele-vs-code-|\.vsix$/', '', $b);
            // Suffix huruf (misal: 1.0.8a atau 1.0.8-a) adalah revisi lanjutan setelah 1.0.8
            $numA = preg_replace('/[^0-9.]/', '', $verA);
            $numB = preg_replace('/[^0-9.]/', '', $verB);
            if ($numA === $numB && $verA !== $verB) {
                return strcmp($b, $a);
            }
            $cmp = version_compare($verB, $verA);
            if ($cmp !== 0) return $cmp;
            return filemtime($b) <=> filemtime($a);
        });
        return $candidates[0];
    }
    return __DIR__ . '/tele-vs-code-1.0.9.vsix';
}
define('VSIX_FILE', getLatestVsixFile());
define('SUPERADMIN_EMAIL', 'iam@usm.rip');

// ------------------------------------------------------------------------------
// 1. Database & Schema Initialization (SQLite)
// ------------------------------------------------------------------------------
function getDb() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 30,
        ]);
        $pdo->exec("PRAGMA busy_timeout = 30000;");
        $pdo->exec("PRAGMA journal_mode = WAL;");
        $pdo->exec("PRAGMA synchronous = NORMAL;");
        $pdo->exec("PRAGMA foreign_keys = ON;");
        
        // Optimasi: Eksekusi DDL & Migrasi skema database hanya jika versi skema belum up-to-date
        $ver = (int)$pdo->query("PRAGMA user_version")->fetchColumn();
        $targetVer = 2;
        if ($ver < $targetVer) {
            runDatabaseMigrations($pdo);
            $pdo->exec("PRAGMA user_version = $targetVer;");
        }
    }
    return $pdo;
}

// Skema Migrasi Database (Hanya dijalankan saat inisialisasi / update skema)
function runDatabaseMigrations($pdo) {
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
        last_email_verified_at DATETIME DEFAULT NULL,
        email_otp TEXT DEFAULT '',
        email_otp_expires INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Add columns if table already exists
    try { $pdo->exec("ALTER TABLE users ADD COLUMN name TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN last_email_verified_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN email_otp TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN email_otp_expires INTEGER DEFAULT 0"); } catch (Exception $e) {}

    // Table Visitor Analytics (ip-api.com geolocation logging)
    $pdo->exec("CREATE TABLE IF NOT EXISTS visitor_analytics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        country TEXT DEFAULT '',
        country_code TEXT DEFAULT '',
        region TEXT DEFAULT '',
        city TEXT DEFAULT '',
        zip TEXT DEFAULT '',
        lat REAL DEFAULT 0,
        lon REAL DEFAULT 0,
        timezone TEXT DEFAULT '',
        isp TEXT DEFAULT '',
        org TEXT DEFAULT '',
        user_agent TEXT DEFAULT '',
        device_type TEXT DEFAULT 'desktop',
        browser TEXT DEFAULT '',
        os TEXT DEFAULT '',
        page_url TEXT DEFAULT '',
        user_id INTEGER DEFAULT 0,
        user_email TEXT DEFAULT '',
        visited_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_analytics_ip ON visitor_analytics(ip)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_analytics_time ON visitor_analytics(visited_at)"); } catch (Exception $e) {}

    // Table Pending Registrations (OTP Verification)
    $pdo->exec("CREATE TABLE IF NOT EXISTS pending_registrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        whatsapp TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        otp_code TEXT NOT NULL,
        expires_at INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
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
    try { $pdo->exec("ALTER TABLE projects ADD COLUMN model_email TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN assigned_agent TEXT DEFAULT 'Antigravity AI Agent'"); } catch (Exception $e) {}
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
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return ['ok' => false, 'description' => "CURL Error: $err"];
    }
        return json_decode($response, true) ?: ['ok' => false, 'description' => 'Respon JSON tidak valid'];
}

// Helper: Kirim pesan Telegram lengkap dengan preview media / foto / video
function sendTelegramWithMedia($botToken, $chatId, $text, $attachmentType = '', $attachmentUrl = '', $attachmentFileId = '', $replyToMsgId = null) {
    if (!$botToken || !$chatId) return ['ok' => false, 'description' => 'Bot token atau Chat ID kosong'];

    $attachmentType = strtolower((string)$attachmentType);
    $mediaTarget = $attachmentFileId ?: $attachmentUrl;

    // 1. Coba kirim via native sendPhoto / sendVideo jika ada media dan teks muat di caption (<= 1024 karakter)
    if ($mediaTarget && in_array($attachmentType, ['photo', 'video', 'voice', 'audio'])) {
        if ($attachmentType === 'video') {
            $method = 'sendVideo'; $field = 'video';
        } elseif ($attachmentType === 'voice') {
            $method = 'sendVoice'; $field = 'voice';
        } elseif ($attachmentType === 'audio') {
            $method = 'sendAudio'; $field = 'audio';
        } else {
            $method = 'sendPhoto'; $field = 'photo';
        }

        if (mb_strlen($text) <= 1024) {
            $params = [
                'chat_id' => $chatId,
                $field => $mediaTarget,
                'caption' => $text,
                'parse_mode' => 'HTML'
            ];
            if ($replyToMsgId) $params['reply_to_message_id'] = $replyToMsgId;
            $res = callTelegram($botToken, $method, $params);
            if (!empty($res['ok'])) {
                return $res;
            }
        }
    }

    // 2. Fallback atau jika teks panjang (> 1024 chars): Kirim via sendMessage dengan Link Preview Tersemat
    $msgText = $text;
    $linkPreviewOptions = ['is_disabled' => true];
    if ($attachmentUrl && in_array($attachmentType, ['photo', 'video'])) {
        // Sematkan tag anchor URL media tak kasat mata di awal teks agar Telegram merender gambar besar di bubble chat
        $msgText = '<a href="' . htmlspecialchars($attachmentUrl) . '">&#8205;</a>' . $text;
        $linkPreviewOptions = [
            'is_disabled' => false,
            'url' => $attachmentUrl,
            'prefer_large_media' => true,
            'show_above_text' => false
        ];
    }

    $params = [
        'chat_id' => $chatId,
        'text' => $msgText,
        'parse_mode' => 'HTML',
        'link_preview_options' => $linkPreviewOptions
    ];
    if ($replyToMsgId) $params['reply_to_message_id'] = $replyToMsgId;
    return callTelegram($botToken, 'sendMessage', $params);
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
            'command' => 'lapo',
            'category' => 'Monitoring',
            'example' => '/lapo atau /lapo <tag>',
            'description' => 'Lihat apa yang sedang dikerjakan oleh AI Agent pada sebuah tag proyek (atau proyek aktif saat ini).'
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
            'command' => '@agent',
            'category' => 'AI Agent',
            'example' => '@claude buat fitur auth atau @antigravity refactor api',
            'description' => 'Tugaskan instruksi secara spesifik ke AI Agent tertentu (@antigravity, @claude, @copilot, @cursor, @gemini, @roo, @cline).'
        ],
        [
            'command' => 'cron',
            'category' => 'AI Agent',
            'example' => '/cron @antigravity cek status database',
            'description' => 'Jadwalkan tugas berkala ke AI Agent (otomatis dieksekusi secara periodik).'
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
    // Hanya gunakan perintah resmi standar bot (hindari shortcut tag seperti /jogja yang mengotori daftar)
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


// Helper: Deteksi IP Asli Pengunjung
function getVisitorIp() {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

// Helper: Deteksi Device, OS, & Browser dari User-Agent
function parseUserAgentDetails($ua) {
    $device = 'Desktop';
    if (preg_match('/(mobile|android|iphone|ipad|phone)/i', $ua)) {
        $device = preg_match('/(ipad|tablet)/i', $ua) ? 'Tablet' : 'Mobile';
    }

    $os = 'Unknown OS';
    if (preg_match('/windows nt 10/i', $ua)) $os = 'Windows 10/11';
    elseif (preg_match('/windows nt/i', $ua)) $os = 'Windows';
    elseif (preg_match('/android/i', $ua)) $os = 'Android';
    elseif (preg_match('/iphone/i', $ua)) $os = 'iOS';
    elseif (preg_match('/macintosh|mac os x/i', $ua)) $os = 'macOS';
    elseif (preg_match('/linux/i', $ua)) $os = 'Linux';

    $browser = 'Other';
    if (preg_match('/edg/i', $ua)) $browser = 'Edge';
    elseif (preg_match('/chrome/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome/i', $ua)) $browser = 'Safari';
    elseif (preg_match('/firefox/i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/opr|opera/i', $ua)) $browser = 'Opera';

    return ['device' => $device, 'os' => $os, 'browser' => $browser];
}

// Helper: Ambil Data Lokasi via ip-api.com dengan Cache Database Cepat
function getGeoLocationFromIp($db, $ip) {
    // Abaikan loopback / private IP
    if ($ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        return [
            'country' => 'Localhost',
            'country_code' => 'ID',
            'region' => 'Development',
            'city' => 'Local Machine',
            'zip' => '-',
            'lat' => 0,
            'lon' => 0,
            'timezone' => 'Asia/Jakarta',
            'isp' => 'Internal Loopback',
            'org' => 'Localhost Network'
        ];
    }

    // 1. Cek riwayat IP di database untuk menghemat kuota ip-api.com
    $stmt = $db->prepare("SELECT country, country_code, region, city, zip, lat, lon, timezone, isp, org FROM visitor_analytics WHERE ip = ? AND country != '' LIMIT 1");
    $stmt->execute([$ip]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cached) {
        return $cached;
    }

    // 2. Query ke ip-api.com (Free HTTP Endpoint)
    $url = "http://ip-api.com/json/" . rawurlencode($ip) . "?fields=status,country,countryCode,regionName,city,zip,lat,lon,timezone,isp,org";
    $resp = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_USERAGENT, 'TeleVsCode-Analytics/1.0');
        $resp = curl_exec($ch);
        curl_close($ch);
    }

    if (!$resp && function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
        $opts = ['http' => ['method' => 'GET', 'timeout' => 4, 'header' => "User-Agent: TeleVsCode-Analytics/1.0\r\n"]];
        $resp = @file_get_contents($url, false, stream_context_create($opts));
    }

    if (!$resp && function_exists('shell_exec')) {
        $resp = @shell_exec('curl.exe -s --max-time 4 ' . escapeshellarg($url));
    }

    if ($resp) {
        $data = json_decode($resp, true);
        if ($data && isset($data['status']) && $data['status'] === 'success') {
            return [
                'country' => $data['country'] ?? '',
                'country_code' => $data['countryCode'] ?? '',
                'region' => $data['regionName'] ?? '',
                'city' => $data['city'] ?? '',
                'zip' => $data['zip'] ?? '',
                'lat' => (float)($data['lat'] ?? 0),
                'lon' => (float)($data['lon'] ?? 0),
                'timezone' => $data['timezone'] ?? '',
                'isp' => $data['isp'] ?? '',
                'org' => $data['org'] ?? ''
            ];
        }
    }

    return [
        'country' => 'Unknown',
        'country_code' => '',
        'region' => '',
        'city' => '',
        'zip' => '',
        'lat' => 0,
        'lon' => 0,
        'timezone' => '',
        'isp' => 'Unknown ISP',
        'org' => ''
    ];
}

// Helper: Rekam Kunjungan Pengunjung ke Database
function recordVisitorHit($db, $currentUser = null) {
    // Lewati polling ajax auto-update agar log analitik rapi & bermakna
    if (isset($_GET['action']) && in_array($_GET['action'], ['sync_poll', 'status_stream'])) {
        return;
    }

    $ip = getVisitorIp();
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $page = $_SERVER['REQUEST_URI'] ?? '/';

    // Throttle 5 menit per IP dan Halaman agar tidak flooding
    $chk = $db->prepare("SELECT id FROM visitor_analytics WHERE ip = ? AND page_url = ? AND visited_at >= datetime('now', '-5 minutes') LIMIT 1");
    $chk->execute([$ip, $page]);
    if ($chk->fetch()) {
        return; // Sudah tercatat dalam 5 menit terakhir
    }

    $geo = getGeoLocationFromIp($db, $ip);
    $uaDetails = parseUserAgentDetails($ua);

    $userId = $currentUser['id'] ?? 0;
    $userEmail = $currentUser['email'] ?? '';

    $ins = $db->prepare("INSERT INTO visitor_analytics (
        ip, country, country_code, region, city, zip, lat, lon, timezone, isp, org,
        user_agent, device_type, browser, os, page_url, user_id, user_email, visited_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $ins->execute([
        $ip,
        $geo['country'],
        $geo['country_code'],
        $geo['region'],
        $geo['city'],
        $geo['zip'],
        $geo['lat'],
        $geo['lon'],
        $geo['timezone'],
        $geo['isp'],
        $geo['org'],
        $ua,
        $uaDetails['device'],
        $uaDetails['browser'],
        $uaDetails['os'],
        $page,
        $userId,
        $userEmail,
        date('Y-m-d H:i:s')
    ]);
}

// Helper: Verifikasi Cloudflare Turnstile Token
function verifyTurnstileToken($token) {
    $secret = getSetting('turnstile_secret_key', '1x0000000000000000000000000000000AA');
    if (!$secret || $secret === '1x0000000000000000000000000000000AA') return true;
    if (!$token) return false;
    $postData = http_build_query([
        'secret' => $secret,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
    $opts = ['http' => [
        'method' => 'POST',
        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
        'content' => $postData,
        'timeout' => 5
    ]];
    $context = stream_context_create($opts);
    $result = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
    if ($result) {
        $res = json_decode($result, true);
        return !empty($res['success']);
    }
    return true;
}


// Helper: Verifikasi Validitas Nomor WhatsApp di Server-Side (Aman, tidak dapat di-inspect atau injeksi)
function verifyWhatsAppNumberServerSide($phone) {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($digits) < 9 || strlen($digits) > 16) return false;

    // Normalisasi awalan ke format lokal 08... yang diharapkan API
    $checkNum = $digits;
    if (strpos($checkNum, '62') === 0) {
        $checkNum = '0' . substr($checkNum, 2);
    }
    if (strpos($checkNum, '0') !== 0) {
        $checkNum = '0' . $checkNum;
    }

    $apiUrl = "https://sowhats.biru.click/api/checknumber/" . rawurlencode($checkNum);
    $resp = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_USERAGENT, 'TeleVsCode-Validator/1.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $resp = curl_exec($ch);
        curl_close($ch);
    }

    if (!$resp && function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: TeleVsCode-Validator/1.0\r\nAccept: application/json\r\n",
                'timeout' => 8,
                'ignore_errors' => true
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
        $resp = @file_get_contents($apiUrl, false, stream_context_create($opts));
    }

    if (!$resp && function_exists('shell_exec')) {
        $resp = @shell_exec('curl.exe -s ' . escapeshellarg($apiUrl));
    }

    if ($resp) {
        $json = json_decode($resp, true);
        if ($json && isset($json['status']) && (string)$json['status'] === '200') {
            if (isset($json['message']) && strtolower($json['message']) === 'registered') {
                return true;
            }
        }
    }
    return false;
}


// Helper: Generator Template Email HTML Responsif Standar Mobile & Desktop
function buildResponsiveEmailHtml($title, $subtitle, $contentHtml, $footerNote = '') {
    $siteUrl = 'https://biru.co.id/tele-vs-code/';
    return '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>' . htmlspecialchars($title) . '</title>
    <style>
        body { margin: 0; padding: 0; background-color: #080d1a; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table { border-collapse: collapse; }
        img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        @media only screen and (max-width: 600px) {
            .email-container { width: 100% !important; max-width: 100% !important; padding: 8px !important; }
            .email-card { padding: 22px 16px !important; border-radius: 12px !important; }
            .otp-code { font-size: 26px !important; letter-spacing: 5px !important; padding: 12px !important; }
            .email-title { font-size: 19px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 24px 10px; background-color: #080d1a; color: #f8fafc;">
    <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
        <tr>
            <td align="center">
                <table class="email-container" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="max-width: 500px; width: 100%; margin: 0 auto;">
                    <!-- Brand Header -->
                    <tr>
                        <td align="center" style="padding-bottom: 18px;">
                            <table border="0" cellpadding="0" cellspacing="0" role="presentation">
                                <tr>
                                    <td align="center" style="padding-right: 8px;">
                                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(56, 189, 248, 0.15); display: inline-flex; align-items: center; justify-content: center; color: #38bdf8; font-size: 16px; line-height: 32px; text-align: center;">🔔</div>
                                    </td>
                                    <td align="left">
                                        <span style="font-size: 18px; font-weight: 800; color: #ffffff; letter-spacing: -0.3px;">tele<span style="color: #38bdf8;">/</span>vs<span style="color: #38bdf8;">/</span>code</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Main Card -->
                    <tr>
                        <td class="email-card" style="background-color: #111827; border: 1px solid #1f2937; border-radius: 16px; padding: 28px 24px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.6); text-align: center;">
                            <h1 class="email-title" style="margin: 0 0 6px 0; font-size: 21px; font-weight: 700; color: #ffffff; letter-spacing: -0.3px;">' . htmlspecialchars($title) . '</h1>
                            ' . ($subtitle ? '<p style="margin: 0 0 20px 0; font-size: 13.5px; color: #94a3b8; line-height: 1.5;">' . htmlspecialchars($subtitle) . '</p>' : '<div style="margin-bottom: 16px;"></div>') . '
                            ' . $contentHtml . '
                            ' . ($footerNote ? '<p style="margin: 20px 0 0 0; font-size: 11.5px; color: #64748b; line-height: 1.5; border-top: 1px solid rgba(255, 255, 255, 0.06); padding-top: 16px;">' . $footerNote . '</p>' : '') . '
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td align="center" style="padding-top: 18px; font-size: 11.5px; color: #475569;">
                            Tele-vs-Code &middot; Visual Studio Code & Telegram AI Notifier<br>
                            <a href="' . $siteUrl . '" style="color: #38bdf8; text-decoration: none;">biru.co.id/tele-vs-code</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
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

// Endpoint Live Polling Auto-Update untuk Dashboard Web
if ($action === 'check_task_updates') {
    if (!$currentUser) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    header('Content-Type: application/json');
    $lastHash = $_GET['hash'] ?? '';

    // Ambil data status dan tugas terbaru untuk user ini
    $stmtU = $db->prepare("SELECT id, status, updated_at, instruction, notes, result, sender, project_id FROM tasks WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT 25");
    $stmtU->execute([$currentUser['id']]);
    $recentTasks = $stmtU->fetchAll(PDO::FETCH_ASSOC);

    // Ambil statistik tugas
    $stmtC = $db->prepare("SELECT status, count(*) as c FROM tasks WHERE user_id = ? GROUP BY status");
    $stmtC->execute([$currentUser['id']]);
    $stats = ['TOTAL' => 0, 'PENDING' => 0, 'IN_PROGRESS' => 0, 'DONE' => 0, 'FAILED' => 0];
    while ($sc = $stmtC->fetch(PDO::FETCH_ASSOC)) {
        $stats[$sc['status']] = (int)$sc['c'];
        $stats['TOTAL'] += (int)$sc['c'];
    }

    $statePayload = ['tasks' => $recentTasks, 'stats' => $stats];
    $curHash = md5(json_encode($statePayload));

    echo json_encode([
        'ok' => true,
        'hash' => $curHash,
        'changed' => ($lastHash && $curHash !== $lastHash),
        'tasks' => $recentTasks,
        'stats' => $stats,
        'server_time' => date('H:i:s')
    ]);
    exit;
}

// Kompatibilitas alias action untuk ekstensi VS Code & CLI:
if ($action === 'pull') $action = 'api_pull';
if ($action === 'push') $action = 'api_push';
if ($action === 'done') $action = 'api_done';
if ($action === 'failed') $action = 'api_failed';
if ($action === 'in_progress' || $action === 'start' || $action === 'proses') $action = 'api_in_progress';
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
    try {
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

        // DETEKSI REPLY KE PESAN TUGAS (Membatalkan atau Merevisi Tugas)
    $repliedTaskId = null;
    if (!empty($msg['reply_to_message'])) {
        $repText = $msg['reply_to_message']['text'] ?? ($msg['reply_to_message']['caption'] ?? '');
        if (preg_match('/(#?TASK-[A-Za-z0-9_-]+)/i', $repText, $mRep)) {
            $repliedTaskId = $mRep[1];
            if ($repliedTaskId[0] !== '#') $repliedTaskId = '#' . $repliedTaskId;
        }
    }

    if ($repliedTaskId) {
        $stmtRepTask = $db->prepare("SELECT t.*, p.name as project_name, p.code as project_code, p.bot_token, p.chat_id, p.timezone as project_timezone FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.id = ?");
        $stmtRepTask->execute([$repliedTaskId]);
        $repTask = $stmtRepTask->fetch(PDO::FETCH_ASSOC);

        if ($repTask) {
            $repTz = !empty($repTask['project_timezone']) ? $repTask['project_timezone'] : $effectiveTz;
            $repTzAbbr = getTimezoneAbbr($repTz);
            $pName = htmlspecialchars($repTask['project_name'] ?: 'Tele-vs-Code');

            // 1. Aksi Pembatalan Tugas
            if (preg_match('/^(?:\/cancel|\/batal|batal|batalkan|cancel|stop|hapus)/i', $text) || stripos($text, 'batalkan task') !== false || stripos($text, 'batal task') !== false || stripos($text, 'cancel task') !== false) {
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

            // 2. Aksi Revisi / Tambahan Instruksi Tugas
            if (in_array($repTask['status'], ['PENDING', 'IN_PROGRESS'])) {
                $cleanRev = trim(preg_replace('/^\/task(@\w+)?\s*/i', '', $text));
                if ($cleanRev) {
                    $now = date('Y-m-d H:i:s');
                    $updatedInstruction = $repTask['instruction'] . "\n\n[Revisi/Tambahan dari $sender pada $now]:\n" . $cleanRev;

                    $upd = $db->prepare("UPDATE tasks SET instruction = ?, updated_at = ? WHERE id = ?");
                    $upd->execute([$updatedInstruction, $now, $repliedTaskId]);

                    $reply = "📝 <b>INSTRUKSI TUGAS BERHASIL DIREVISI!</b>\n"
                           . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                           . "🆔 <b>ID Tugas:</b> <code>$repliedTaskId</code>\n"
                           . "📁 <b>Proyek:</b> <code>$pName</code>\n"
                           . "👤 <b>Pengirim:</b> " . htmlspecialchars($sender) . "\n"
                           . "🕒 <b>Waktu Revisi:</b> " . date('d-m-Y H:i:s') . " $repTzAbbr\n\n"
                           . "➕ <b>Instruksi Tambahan:</b>\n<code>" . htmlspecialchars($cleanRev) . "</code>\n\n"
                           . "🚀 <i>Pembaruan instruksi telah disinkronkan ke AI Agent di VS Code!</i>";

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
            $curCode = !empty($activeProject['code']) ? $activeProject['code'] : 'a';
            $ownerInfo = !empty($activeProject['email']) ? "\n👤 <b>Akun:</b> <code>" . htmlspecialchars($activeProject['email']) . "</code>" : "";
            $reply = "🌟 <b>PROYEK AKTIF SAAT INI:</b>\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                   . "📁 <b>Nama Proyek:</b> <code>" . htmlspecialchars($activeProject['name']) . "</code>\n"
                   . "🏷️ <b>Kode Tag:</b> <code>#$curCode</code> (atau <code>[#$curCode]</code>)\n"
                   . "🔑 <b>Project Key:</b> <code>" . htmlspecialchars($activeProject['project_key']) . "</code>" . $ownerInfo . "\n"
                   . "🕒 <b>Waktu Server:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n\n"
                   . "👉 <i>Setiap pesan instruksi biasa di chat ini otomatis dikerjakan oleh VS Code proyek <b>" . htmlspecialchars($activeProject['name']) . "</b>.</i>\n\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "🔄 <b>Ganti Proyek Aktif:</b>\n"
                   . "Ketik: <code>/use &lt;kode_tag&gt;</code> (Contoh: <code>/use a</code> atau <code>/use b</code>)\n"
                   . "Ketik <code>/projects</code> untuk melihat semua proyek Anda.";
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

    // Tangkap Tugas Baru (Teks atau Lampiran Media)
    $instruction = '';
    $explicitProject = null;
    $taskCommand = '';

    if (preg_match('/^\/task(@\w+)?\s+(.+)$/is', $text, $matches)) {
        $taskCommand = '/task';
        $instruction = trim($matches[2]);
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

                // ======================================================================
                // KEAMANAN KETAT: Anti-Eksekusi File & Anti-Injeksi (Blacklist + Content Scan)
                // ======================================================================
                // 1. Sanitasi nama berkas: hilangkan path traversal dan karakter ilegal
                $attachmentName = basename(str_replace(['../', '..\\', chr(0)], '', $attachmentName));
                $attachmentName = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $attachmentName);

                // 2. Netralkan trik double extension (misal: payload.php.png -> payload_php.png)
                $attachmentName = preg_replace('/\.(php[0-9]*|phtml|phar|phps|inc|shtml|cgi|pl|py|sh|bash|bat|cmd|ps1|exe|asp|aspx|jsp|env)\./i', '_$1.', $attachmentName);

                // 3. Blacklist lengkap seluruh ekstensi file yang bisa dieksekusi (executable / scripts)
                $ext = strtolower(pathinfo($attachmentName, PATHINFO_EXTENSION));
                $executableExts = [
                    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'inc',
                    'shtml', 'cgi', 'pl', 'py', 'sh', 'bash', 'zsh', 'bat', 'cmd', 'ps1', 'psm1',
                    'exe', 'com', 'scr', 'msi', 'vbs', 'vbe', 'wsf', 'wsh', 'htaccess', 'htpasswd',
                    'ini', 'config', 'conf', 'asp', 'aspx', 'jsp', 'jspx', 'action', 'do', 'dll', 'so',
                    'env', 'svgz'
                ];
                if (in_array($ext, $executableExts) || empty($ext)) {
                    $attachmentName .= '.txt';
                }

                $targetFile = $uploadDir . '/' . $attachmentName;
                $fileData = @file_get_contents($downloadSrc);
                if ($fileData !== false && strlen($fileData) > 0) {
                    // 4. Inspeksi Konten: Cek apakah berkas mengandung tag PHP tersembunyi
                    if (preg_match('/<\?(php|=|\s)/i', $fileData) || stripos($fileData, '__halt_compiler') !== false) {
                        if (!str_ends_with(strtolower($attachmentName), '.txt')) {
                            $attachmentName .= '.txt';
                            $targetFile = $uploadDir . '/' . $attachmentName;
                        }
                    }

                    file_put_contents($targetFile, $fileData);
                    $attachmentUrl = "https://biru.co.id/tele-vs-code/uploads/" . rawurlencode($taskId) . "/" . rawurlencode($attachmentName);
                }
            }
        }

        // Format instruksi agar AI Agent langsung mengetahui keberadaan dan URL lampiran
        if ($attachmentType) {
            $typeLabel = ($attachmentType === 'photo') ? 'Foto' : (($attachmentType === 'video') ? 'Video' : 'Dokumen');
            $attachmentHeader = "[$typeLabel Terlampir: $attachmentName" . ($attachmentUrl ? " ($attachmentUrl)" : "") . "]";
            $instruction = $attachmentHeader . "\n" . $instruction;
        }

        // Deteksi Agent spesifik dari perintah (misal: @antigravity, @antigrafity, @claude, @copilot, dll)
        $assignedAgent = 'Antigravity AI Agent'; // Default: Agent Aktif saat ini
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

        $maxInsRetries = 5;
        for ($insAttempt = 1; $insAttempt <= $maxInsRetries; $insAttempt++) {
            try {
                $stmtIns = $db->prepare("INSERT INTO tasks (id, user_id, project_id, instruction, sender, status, created_at, updated_at, attachment_url, attachment_type, attachment_name, command, assigned_agent) VALUES (?, ?, ?, ?, ?, 'PENDING', ?, ?, ?, ?, ?, ?, ?)");
                $stmtIns->execute([$taskId, $targetProject['user_id'], $targetProject['id'], $instruction, $taskSender, $now, $now, $attachmentUrl, $attachmentType, $attachmentName, $taskCommand, $assignedAgent]);
                break;
            } catch (PDOException $pdoErr) {
                if ($insAttempt === $maxInsRetries) {
                    throw $pdoErr;
                }
                usleep(250000); // 250ms
            }
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
        echo "OK"; exit;
    }
}

// Download & Serve Lampiran Tugas
if ($action === 'attachment') {
    $rawTid = $_GET['task'] ?? '';
    $cleanTid = preg_replace('/[^a-zA-Z0-9_#-]/', '', $rawTid);
    $fname = basename($_GET['file'] ?? '');
    $filePath = __DIR__ . '/uploads/' . $cleanTid . '/' . $fname;
    if (!file_exists($filePath)) {
        $noHash = ltrim($cleanTid, '#');
        if (file_exists(__DIR__ . '/uploads/#' . $noHash . '/' . $fname)) {
            $filePath = __DIR__ . '/uploads/#' . $noHash . '/' . $fname;
        } elseif (file_exists(__DIR__ . '/uploads/' . $noHash . '/' . $fname)) {
            $filePath = __DIR__ . '/uploads/' . $noHash . '/' . $fname;
        }
    }
    if ($cleanTid && $fname && file_exists($filePath)) {
        $mime = 'application/octet-stream';
        $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4',
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
                $isNewlyStarted = ($pt['status'] === 'PENDING');
                $upd = $db->prepare("UPDATE tasks SET status = 'IN_PROGRESS', updated_at = ?, notes = 'Ditarik oleh VS Code Agent' WHERE id = ?");
                $upd->execute([$now, $pt['id']]);

                // Kirim notifikasi Telegram jika memulai pekerjaan (Requirement: info detail termasuk perintah)
                if ($isNewlyStarted && !empty($pt['bot_token']) && !empty($pt['chat_id']) && empty($pt['block_notify'])) {
                    $tz = !empty($pt['timezone']) ? $pt['timezone'] : (!empty($user['timezone']) ? $user['timezone'] : 'Asia/Jakarta');
                    $tzAbbr = getTimezoneAbbr($tz);
                    $instSnippet = htmlspecialchars($pt['instruction']);
                    $pName = htmlspecialchars($pt['project_name'] ?: 'Tele-vs-Code');
                    $pTag = !empty($pt['project_code']) ? (' (#' . htmlspecialchars($pt['project_code']) . ')') : '';

                    $startMsg = "⚡ <b>MEMULAI PEKERJAAN</b>\n"
                              . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                              . "🆔 <b>ID Tugas:</b> <code>{$pt['id']}</code>\n"
                              . "📁 <b>Proyek:</b> <code>$pName</code>$pTag\n"
                              . "🕒 <b>Waktu Mulai:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n"
                              . "👤 <b>Pemohon:</b> " . htmlspecialchars($pt['sender']) . "\n\n"
                              . "🤖 <b>Eksekutor:</b> <code>" . htmlspecialchars(!empty($pt['assigned_agent']) ? $pt['assigned_agent'] : 'Antigravity AI Agent') . "</code>\n\n"
                              . "📝 <b>Perintah / Instruksi:</b>\n<code>$instSnippet</code>\n\n"
                              . "🚀 <b>Status:</b> <code>[IN_PROGRESS]</code>\n"
                              . "🤖 <i>AI Agent di VS Code telah menerima tugas ini dan sedang mengeksekusi sampai tuntas 100%...</i>";

                    callTelegram($pt['bot_token'], 'sendMessage', [
                        'chat_id' => $pt['chat_id'],
                        'text' => $startMsg,
                        'parse_mode' => 'HTML'
                    ]);
                }
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

        $isIde = !empty($_POST['is_ide']) || !empty($_GET['is_ide']) || stripos($sender, 'IDE') !== false || stripos($sender, 'VS Code') !== false;
        $idPrefix = $isIde ? '#TASK-IDE-' : '#TASK-';
        $taskId = $idPrefix . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        $assignedAgent = trim($_POST['agent'] ?? ($_GET['agent'] ?? ($_POST['assigned_agent'] ?? ($_GET['assigned_agent'] ?? ''))));
        if (!$assignedAgent) {
            if (preg_match('/(?:^|\s)@(\w+)/i', $instruction, $mAgt)) {
                $cAgent = strtolower($mAgt[1]);
                $knownAgents = ['antigravity', 'gemini', 'claude', 'copilot', 'codex', 'cursor', 'roo', 'cline'];
                if (in_array($cAgent, $knownAgents)) {
                    $assignedAgent = ucfirst($cAgent) . ' Agent';
                    $instruction = trim(preg_replace('/(?:^|\s)@' . preg_quote($cAgent, '/') . '/i', '', $instruction));
                }
            } elseif (preg_match('/(?:--agent|\[agent:\s*)([\w\s-]+)\]?/i', $instruction, $mAgt)) {
                $assignedAgent = trim($mAgt[1]);
                $instruction = trim(preg_replace('/(?:--agent|\[agent:\s*)([\w\s-]+)\]?/i', '', $instruction));
            }
        }
        if (!$assignedAgent) $assignedAgent = 'Antigravity AI Agent';

        $now = date('Y-m-d H:i:s');
        $stmtIns = $db->prepare("INSERT INTO tasks (id, user_id, project_id, instruction, sender, status, created_at, updated_at, notes, command, assigned_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtIns->execute([$taskId, $user['id'], $prjId, $instruction, $sender, $status, $now, $now, $notes, $command, $assignedAgent]);

        echo json_encode(['ok' => true, 'id' => $taskId, 'message' => "Tugas $taskId berhasil dicatat"]);
        exit;
    }

        // Update Status Tugas Menjadi IN_PROGRESS (Requirement: notifikasi telegram jika memulai pekerjaan detail termasuk perintah)
    if ($action === 'api_in_progress') {
        $taskId = $_GET['id'] ?? ($_POST['id'] ?? '');
        $notes = trim($_POST['notes'] ?? ($_GET['notes'] ?? 'Sedang dikerjakan oleh AI Agent'));
        $now = date('Y-m-d H:i:s');
        $upd = $db->prepare("UPDATE tasks SET status = 'IN_PROGRESS', updated_at = ?, notes = ? WHERE id = ? AND user_id = ?");
        $upd->execute([$now, $notes, $taskId, $user['id']]);

        // Ambil data tugas & proyek untuk mengirim notifikasi mulai ke Telegram
        $stmtT = $db->prepare("SELECT t.*, p.bot_token, p.chat_id, p.name as project_name, p.code as project_code, p.timezone as project_timezone FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.id = ? AND t.user_id = ?");
        $stmtT->execute([$taskId, $user['id']]);
        $task = $stmtT->fetch(PDO::FETCH_ASSOC);

        if ($task && !empty($task['bot_token']) && !empty($task['chat_id']) && empty($user['block_notify'])) {
            $tz = !empty($task['project_timezone']) ? $task['project_timezone'] : (!empty($user['timezone']) ? $user['timezone'] : 'Asia/Jakarta');
            $tzAbbr = getTimezoneAbbr($tz);
            $instSnippet = htmlspecialchars($task['instruction']);
            $pName = htmlspecialchars($task['project_name'] ?: 'Tele-vs-Code');
            $pTag = !empty($task['project_code']) ? (' (#' . htmlspecialchars($task['project_code']) . ')') : '';

            $attachLine = "";
            if (!empty($task['attachment_type'])) {
                $typeLabel = ($task['attachment_type'] === 'photo') ? 'Foto' : (($task['attachment_type'] === 'video') ? 'Video' : 'Dokumen');
                $attachLine = "📎 <b>Lampiran:</b> " . htmlspecialchars($task['attachment_name']) . " (Tipe: $typeLabel)\n";
                if (!empty($task['attachment_url'])) {
                    $attachLine .= "🔗 <b>Tautan File:</b> <a href=\"{$task['attachment_url']}\">Buka Lampiran</a>\n";
                }
                $attachLine .= "\n";
            }

            $startMsg = "⚡ <b>MEMULAI PEKERJAAN</b>\n"
                      . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                      . "🆔 <b>ID Tugas:</b> <code>{$task['id']}</code>\n"
                      . "📁 <b>Proyek:</b> <code>$pName</code>$pTag\n"
                      . "🕒 <b>Waktu Mulai:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n"
                      . "👤 <b>Pemohon:</b> " . htmlspecialchars($task['sender']) . "\n\n"
                      . "🤖 <b>Eksekutor:</b> <code>" . htmlspecialchars(!empty($task['assigned_agent']) ? $task['assigned_agent'] : 'Antigravity AI Agent') . "</code>\n\n"
                      . $attachLine
                      . "📝 <b>Perintah / Instruksi:</b>\n<code>$instSnippet</code>\n\n"
                      . "🚀 <b>Status:</b> <code>[IN_PROGRESS]</code>\n"
                      . "📌 <b>Catatan:</b> " . htmlspecialchars($notes) . "\n"
                      . "🤖 <i>AI Agent di VS Code sedang mengeksekusi tugas ini sampai tuntas 100%...</i>";

            sendTelegramWithMedia(
                $task['bot_token'],
                $task['chat_id'],
                $startMsg,
                $task['attachment_type'] ?? '',
                $task['attachment_url'] ?? '',
                ''
            );
        }

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

        // Hitung Durasi Pengerjaan Tugas
        $durationText = "";
        if (!empty($task['created_at'])) {
            $createdTs = strtotime($task['created_at']);
            $nowTs = strtotime($now);
            $diffSec = max(0, $nowTs - $createdTs);
            $h = floor($diffSec / 3600);
            $m = floor(($diffSec % 3600) / 60);
            $s = $diffSec % 60;
            if ($h > 0) $durationText = "{$h} jam {$m} menit {$s} detik";
            elseif ($m > 0) $durationText = "{$m} menit {$s} detik";
            else $durationText = "{$s} detik";
        }

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
                
                // Analisis Cerdas & Solusi Otomatis jika Tugas Ada Kendala
                $solusiSection = "";
                if ($stat === 'FAILED') {
                    $combinedErr = strtolower($result . ' ' . $notes);
                    $solusiList = [];
                    if (strpos($combinedErr, 'syntax') !== false || strpos($combinedErr, 'parse') !== false || strpos($combinedErr, 'unexpected') !== false) {
                        $solusiList[] = "Periksa sintaks kode dan pastikan penutupan kurung, titik-koma, atau petik valid.";
                    }
                    if (strpos($combinedErr, 'not found') !== false || strpos($combinedErr, 'tidak ditemukan') !== false || strpos($combinedErr, 'enoent') !== false) {
                        $solusiList[] = "Pastikan path berkas, direktori, atau package dependensi tujuan sudah terpasang.";
                    }
                    if (strpos($combinedErr, 'permission') !== false || strpos($combinedErr, 'denied') !== false || strpos($combinedErr, 'eacces') !== false) {
                        $solusiList[] = "Buka izin tulis berkas/folder atau jalankan VS Code / terminal dengan hak akses cukup.";
                    }
                    if (strpos($combinedErr, 'timeout') !== false || strpos($combinedErr, 'network') !== false || strpos($combinedErr, 'connection') !== false) {
                        $solusiList[] = "Periksa stabilitas koneksi internet atau server lalu coba kirim ulang instruksi.";
                    }
                    if (empty($solusiList)) {
                        $solusiList[] = "Reply pesan notifikasi ini dengan instruksi tambahan (misal: '/task coba cara lain...').";
                        $solusiList[] = "Periksa detail log eksekusi di VS Code pada panel Output 'Tele-vs-Code'.";
                    }
                    $solusiSection = "💡 <b>Solusi & Rekomendasi Tindakan:</b>\n";
                    foreach ($solusiList as $s) {
                        $solusiSection .= "• " . htmlspecialchars($s) . "\n";
                    }
                    $solusiSection .= "\n🔄 <i>Ketik /task atau reply pesan ini untuk mencoba kembali dengan instruksi baru.</i>\n";
                }

                $durationLine = ($stat === 'DONE' && $durationText) ? "⏱️ <b>Durasi Pengerjaan:</b> <code>$durationText</code>\n" : "";
                $text = "<b>$emoji Tugas $taskId: " . ($stat === 'DONE' ? 'SELESAI' : 'KENDALA') . "</b>\n\n"
                      . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($pName) . "</code>\n"
                      . "🕒 <b>Waktu Selesai:</b> $now $tzAbbr\n"
                      . $durationLine
                      . "🤖 <b>Eksekutor:</b> <code>" . htmlspecialchars(!empty($task['assigned_agent']) ? $task['assigned_agent'] : 'Antigravity AI Agent') . "</code>\n"
                      . "📋 <b>" . ($stat === 'DONE' ? 'Hasil Pekerjaan:' : 'Penyebab Kendala:') . "</b>\n" . htmlspecialchars($result) . "\n\n"
                      . $solusiSection
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
    } elseif (!verifyWhatsAppNumberServerSide($_POST['whatsapp'] ?? '')) {
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
            if (empty($user['last_email_verified_at']) || (time() - strtotime($user['last_email_verified_at'])) > 86400) {
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
                // Sudah verifikasi dalam kurun waktu 24 jam terakhir -> login langsung
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

    // Cek kelayakan tombol donasi:
    // Khusus untuk yang login, memiliki proyek, dan proyek sudah dilayani oleh dan sukses notifikasi
    $userSuccessCount = 0;
    if (!empty($projects)) {
        $stmtDone = $db->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND (status = 'DONE' OR status = 'done')");
        $stmtDone->execute([$currentUser['id']]);
        $userSuccessCount = (int)$stmtDone->fetchColumn();
        if ($userSuccessCount === 0) {
            foreach ($projects as $p) {
                if (!empty($p['last_seen'])) {
                    $userSuccessCount = 1;
                    break;
                }
            }
        }
    }
    $canShowDonation = (!empty($currentUser) && !empty($projects) && $userSuccessCount > 0);

    // Load Whitelist Users milik User (Private Chat)
    $stmtW = $db->prepare("SELECT w.*, p.name as project_name FROM bot_whitelist w LEFT JOIN projects p ON w.project_id = p.id WHERE w.user_id = ? ORDER BY w.id DESC");
    $stmtW->execute([$currentUser['id']]);
    $whitelistUsers = $stmtW->fetchAll(PDO::FETCH_ASSOC);

    // Filter Tasks per Project & Pagination (Default 10)
    $filterProjectId = isset($_GET['filter_project']) ? (int)$_GET['filter_project'] : 0;
    $filterStatus = $_GET['filter_status'] ?? '';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? max(5, min(100, (int)$_GET['per_page'])) : 10; // Default 10!

    $countSql = "SELECT COUNT(*) FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.user_id = ?";
    $taskSql = "SELECT t.*, p.name as project_name, p.code as project_code, p.timezone as project_timezone FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.user_id = ?";
    $taskParams = [$currentUser['id']];

    if ($filterProjectId > 0) {
        $countSql .= " AND t.project_id = ?";
        $taskSql .= " AND t.project_id = ?";
        $taskParams[] = $filterProjectId;
    }
    if ($filterStatus && in_array($filterStatus, ['PENDING', 'IN_PROGRESS', 'DONE', 'FAILED'])) {
        $countSql .= " AND t.status = ?";
        $taskSql .= " AND t.status = ?";
        $taskParams[] = $filterStatus;
    }

    $stmtCountTotal = $db->prepare($countSql);
    $stmtCountTotal->execute($taskParams);
    $totalTasksCount = (int)$stmtCountTotal->fetchColumn();

    $totalPages = max(1, (int)ceil($totalTasksCount / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $taskSql .= " ORDER BY t.created_at DESC LIMIT $perPage OFFSET $offset";

    $stmtTasks = $db->prepare($taskSql);
    $stmtTasks->execute($taskParams);
    $myTasks = $stmtTasks->fetchAll(PDO::FETCH_ASSOC);

    // Hitung Statistik Tugas User (Parameterized Query - Safe from SQL Injection)
    $countSql = "SELECT status, count(*) as c FROM tasks WHERE user_id = ?";
    $countParams = [(int)$currentUser['id']];
    if ($filterProjectId > 0) {
        $countSql .= " AND project_id = ?";
        $countParams[] = (int)$filterProjectId;
    }
    $countSql .= " GROUP BY status";
    $stmtCount = $db->prepare($countSql);
    $stmtCount->execute($countParams);
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
                'globe' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
        'instagram' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>',
        'youtube' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M2.5 17a24.12 24.12 0 0 1 0-10 2 2 0 0 1 1.4-1.4 49.56 49.56 0 0 1 16.2 0A2 2 0 0 1 21.5 7a24.12 24.12 0 0 1 0 10 2 2 0 0 1-1.4 1.4 49.55 49.55 0 0 1-16.2 0A2 2 0 0 1 2.5 17"/><polygon points="10 15 15 12 10 9 10 15"/></svg>',
        'whatsapp' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
        'github' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M15 22v-4a4.8 4.8 0 0 0-1-3.5c3 0 6-2 6-5.5.08-1.25-.27-2.48-1-3.5.28-1.15.28-2.35 0-3.5 0 0-1 0-3 1.5-2.64-.5-5.36-.5-8 0C6 2 5 2 5 2c-.3 1.15-.3 2.35 0 3.5A5.403 5.403 0 0 0 4 9c0 3.5 3 5.5 6 5.5-.39.49-.68 1.05-.85 1.65-.17.6-.22 1.23-.15 1.85v4"/><path d="M9 18c-4.51 2-5-2-7-2"/></svg>',
        'info' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
        'feedback' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        'minimize' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>'
    ];
    return $icons[$name] ?? '';
}

// Sitekey Cloudflare Turnstile (dapat dikonfigurasi via settings atau default test key)
$cfSiteKey = getSetting('turnstile_site_key', '1x00000000000000000000AA');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Tele-vs-Code - Notifikasi pekerjaan AGEN AI di Visual Studio Code dengan Telegram</title>
    <meta name="description" content="Kirim instruksi coding dari Telegram ke AI Agent di VS Code. Terima hasil eksekusi lengkap secara real-time tanpa menunggu di depan layar.">
    <meta name="theme-color" content="#0d0c0a">

    <!-- Open Graph & Twitter Card Meta Tags -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://biru.co.id/tele-vs-code/">
    <meta property="og:title" content="Tele-vs-Code - Agen AI Bekerja di Editor, Kabarnya Sampai di Saku">
    <meta property="og:description" content="Kirim instruksi dari Telegram, biarkan agen di VS Code mengerjakannya, lalu terima laporan selesai lengkap secara real-time.">
    <meta property="og:image" content="https://biru.co.id/tele-vs-code/assets/img/og-image.png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Tele-vs-Code Dashboard Preview & AI Agent">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Tele-vs-Code - Agen AI Bekerja di Editor, Kabarnya Sampai di Saku">
    <meta name="twitter:description" content="Kirim instruksi dari Telegram, biarkan agen di VS Code mengerjakannya, lalu terima laporan selesai lengkap secara real-time.">
    <meta name="twitter:image" content="https://biru.co.id/tele-vs-code/assets/img/og-image.png">

    <link rel="icon" type="image/jpeg" href="assets/img/logo.jpg">
    <meta name="view-transition" content="same-origin">
    <script>if ('onpagereveal' in window) document.documentElement.classList.add('has-vt');</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@300..600&family=Geist+Mono:wght@400;500&family=Instrument+Serif:ital@0;1&display=swap">
    <!-- Studio Ink Theme -->
        <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <style>
    /* Critical Mobile & Auth Segmented Solid Hardening */
    html, body {
        overflow-x: hidden !important;
        width: 100% !important;
        max-width: 100vw !important;
        position: relative !important;
    }
    .container {
        width: 100% !important;
        max-width: 1120px !important;
        box-sizing: border-box !important;
        overflow-x: hidden !important;
    }
    .landing, .landing-copy, .landing-preview-col {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        overflow-x: hidden !important;
    }
    .landing-title {
        word-break: break-word !important;
        overflow-wrap: break-word !important;
    }
    .auth-segmented {
        background: rgba(255, 255, 255, 0.08) !important;
        border: 1px solid rgba(255, 255, 255, 0.15) !important;
        border-radius: 9999px !important;
        padding: 3px !important;
        gap: 3px !important;
        display: flex !important;
        box-sizing: border-box !important;
    }
    .auth-segmented .liquid-layer { display: none !important; }
    .auth-segmented-btn {
        flex: 1 !important;
        height: 36px !important;
        font-size: 13px !important;
        font-weight: 500 !important;
        border-radius: 9999px !important;
        border: none !important;
        color: #94a3b8 !important;
        background: transparent !important;
        box-sizing: border-box !important;
        cursor: pointer !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    .auth-segmented-btn:hover { color: #ffffff !important; }
    .auth-segmented-btn.active {
        background: #ece4d6 !important;
        color: #0d0c0a !important;
        font-weight: 700 !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4) !important;
    }
    .btn, .btn-sm, .btn-xs {
        box-sizing: border-box !important;
        max-width: 100% !important;
    }
    .navbar-actions .btn {
        flex: 0 0 auto !important;
        white-space: nowrap !important;
    }
    
    /* Single Animated Live Dot */
    @keyframes livePulse {
        0% {
            transform: scale(0.95);
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            opacity: 0.8;
        }
        50% {
            transform: scale(1.15);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0);
            opacity: 1;
        }
        100% {
            transform: scale(0.95);
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
            opacity: 0.8;
        }
    }
    .badge-live-sync {
        background: rgba(16, 185, 129, 0.12) !important;
        color: #86efac !important;
        border: 1px solid rgba(16, 185, 129, 0.3) !important;
        font-size: 11px !important;
        padding: 0 9px !important;
        border-radius: 12px !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;
        height: 22px !important;
    }
    .badge-live-sync::before {
        content: "" !important;
        display: inline-block !important;
        width: 6px !important;
        height: 6px !important;
        border-radius: 50% !important;
        background: #10b981 !important;
        animation: livePulse 1.5s ease-in-out infinite !important;
        box-shadow: 0 0 6px #10b981;
    }

    /* ==========================================================================
       Task Card Accordion & Media Preview Styling
       ========================================================================== */
    .task-card {
        transition: all var(--transition-fast);
    }
    .task-card.collapsed {
        padding: 12px 18px 12px 22px;
        gap: 6px;
    }
    .task-card.collapsed .task-card-row-meta {
        border-bottom: none;
        padding-bottom: 0;
    }
    .task-card-preview {
        font-size: 13px;
        color: var(--text-secondary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        cursor: pointer;
        padding-top: 4px;
        display: none;
        line-height: 1.4;
    }
    .task-card.collapsed .task-card-preview {
        display: block;
    }
    .task-card:not(.collapsed) .task-card-preview {
        display: none;
    }
    .task-accordion-toggle {
        color: var(--text-muted);
        transition: transform 0.2s ease, color var(--transition-fast);
    }
    .task-accordion-toggle:hover {
        color: #fff;
    }
    .task-card .chevron-icon {
        transition: transform 0.2s ease;
    }
    .task-card:not(.collapsed) .chevron-icon {
        transform: rotate(180deg);
    }
    .task-card-body {
        display: flex;
        flex-direction: column;
        gap: 14px;
        width: 100%;
        margin-top: 4px;
    }
    .task-card.collapsed .task-card-body {
        display: none !important;
    }

    /* Media Box & Photo Thumbnail */
    .task-card-media-box {
        margin: 6px 0 10px 0;
        padding: 10px 12px;
        background: var(--bg-surface-inset);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-md);
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .task-photo-wrap {
        display: inline-block;
        max-width: 320px;
    }
    .task-photo-link {
        position: relative;
        display: block;
        overflow: hidden;
        border-radius: var(--radius-sm);
        border: 1px solid var(--border-medium);
        background: #000;
        cursor: pointer;
    }
    .task-photo-img {
        display: block;
        max-width: 100%;
        max-height: 200px;
        object-fit: cover;
        border-radius: var(--radius-sm);
        transition: transform 0.25s ease;
    }
    .task-photo-link:hover .task-photo-img {
        transform: scale(1.04);
    }
    .task-photo-overlay {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, 0.45);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        color: #fff;
        font-size: 12px;
        font-weight: 500;
        opacity: 0;
        transition: opacity 0.2s ease;
    }
    .task-photo-link:hover .task-photo-overlay {
        opacity: 1;
    }
    .task-media-caption {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        font-size: 11.5px;
        color: var(--text-muted);
    }
    .badge-media-indicator {
        background: rgba(56, 189, 248, 0.12);
        color: #7dd3fc;
        border: 1px solid rgba(56, 189, 248, 0.3);
        font-size: 11px;
        padding: 2px 7px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    /* Styling tombol navigasi persis seperti tombol Tambah Manual (.btn.btn-secondary) */
    .navbar-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .navbar-actions .btn {
        border-radius: var(--radius-sm);
        height: 32px;
        font-family: var(--font-sans);
        font-size: 12.5px;
        font-weight: 500;
        background: var(--ink-3);
        border: 1px solid var(--border-subtle);
        color: var(--text-primary);
        padding: 0 13px;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        box-sizing: border-box;
        text-decoration: none;
        white-space: nowrap;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .navbar-actions .btn:hover {
        background: var(--ink-4);
        border-color: var(--border-hover);
        color: #fff;
    }
    .user-chip {
        border-radius: var(--radius-sm);
        background: var(--ink-3);
        border: 1px solid var(--border-subtle);
        height: 32px;
        padding: 0 11px;
        box-sizing: border-box;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        font-size: 12px;
        color: var(--text-secondary);
    }
    .lang-switcher {
        height: 32px;
        box-sizing: border-box;
        border-radius: var(--radius-sm);
        background: var(--ink-3);
        border: 1px solid var(--border-subtle);
        display: inline-flex;
        align-items: center;
        padding: 2px 3px;
        gap: 2px;
    }
    .lang-switcher a {
        height: 26px;
        line-height: 26px;
        padding: 0 8px;
        border-radius: calc(var(--radius-sm) - 1px);
        font-size: 11.5px;
        font-weight: 500;
        color: var(--text-secondary);
        text-decoration: none;
        transition: all 0.15s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .lang-switcher a.active {
        background: var(--text-primary);
        color: var(--ink-0);
        font-weight: 600;
    }
    .lang-switcher a:hover:not(.active) {
        color: #fff;
        background: var(--ink-4);
    }
    .app-footer {
        margin-top: 48px;
        padding-top: 24px;
        padding-bottom: 28px;
        border-top: 1px solid var(--border-subtle);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
        width: 100%;
    }
    .footer-credit {
        font-size: 12px;
        color: var(--text-muted);
    }
    .footer-biru-link {
        color: #38bdf8;
        text-decoration: none;
        font-weight: 500;
    }
    .footer-biru-link:hover {
        text-decoration: underline;
    }
    .footer-links {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .footer-links .btn {
        border-radius: var(--radius-sm);
    }
    .social-links {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .social-icon-btn {
        width: 32px;
        height: 32px;
        border-radius: var(--radius-sm);
        background: var(--ink-3);
        border: 1px solid var(--border-subtle);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: var(--text-secondary);
        text-decoration: none;
        transition: all 0.2s ease;
    }
    .social-icon-btn:hover {
        background: var(--ink-4);
        color: #fff;
        border-color: var(--border-hover);
        transform: translateY(-1px);
    }
    /* Floating Feedback Button */
    .floating-feedback-btn {
        position: fixed;
        right: 0;
        top: 50%;
        transform: translateY(-50%);
        background: #0284c7;
        color: #ffffff !important;
        padding: 9px 12px;
        border-radius: var(--radius-sm) 0 0 var(--radius-sm);
        box-shadow: -2px 4px 18px rgba(2, 132, 199, 0.45);
        display: inline-flex;
        align-items: center;
        gap: 7px;
        font-size: 12px;
        font-weight: 600;
        text-decoration: none;
        z-index: 9999;
        transition: all 0.25s ease;
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-right: none;
    }
    .floating-feedback-btn:hover {
        background: #0369a1;
        padding-right: 16px;
    }
    @media (max-width: 640px) {
        .navbar-actions {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            flex-wrap: wrap;
        }
        .navbar-actions .btn {
            padding: 0 10px;
            font-size: 11.5px;
            height: 32px;
            box-sizing: border-box;
            flex: 0 0 auto;
            max-width: 100%;
            white-space: nowrap;
        }
        .app-footer {
            flex-direction: column;
            align-items: flex-start;
            gap: 14px;
        }
    }
    </style>
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
            <?php if (!empty($canShowDonation)): ?>
                <!-- Tombol Donasi (sama seperti Tambah Manual) -->
                <button type="button" class="btn btn-secondary btn-sm" onclick="openDonationModal()" title="<?= __t('Dukung Pengembangan Tele-vs-Code', 'Support Tele-vs-Code Development') ?>">
                    <span style="font-size: 12px;">❤️</span>
                    <span><?= __t('Donasi', 'Donate') ?></span>
                </button>
            <?php endif; ?>

            <!-- Tombol About (sama seperti Tambah Manual) -->
            <button type="button" class="btn btn-secondary btn-sm" onclick="openAboutModal()" title="<?= __t('Tentang Telegram VS Code & Tim Magang', 'About Telegram VS Code & Intern Team') ?>">
                <?= renderSvg('info', 13) ?>
                <span><?= __t('About', 'About') ?></span>
            </button>

            <!-- Language Switcher (Dual Language ID / EN) -->
            <div class="lang-switcher" title="<?= __t('Ganti Bahasa', 'Switch Language') ?>">
                <a href="?lang=id<?= !empty($_SERVER['QUERY_STRING']) ? '&' . preg_replace('/(^|&)lang=[^&]*/', '', $_SERVER['QUERY_STRING']) : '' ?>" class="<?= $currentLang === 'id' ? 'active' : '' ?>">ID</a>
                <a href="?lang=en<?= !empty($_SERVER['QUERY_STRING']) ? '&' . preg_replace('/(^|&)lang=[^&]*/', '', $_SERVER['QUERY_STRING']) : '' ?>" class="<?= $currentLang === 'en' ? 'active' : '' ?>">EN</a>
            </div>

            <!-- Tombol Unduh VSIX (sama seperti Tambah Manual) -->
            <a class="btn btn-secondary btn-sm" href="?action=download_vsix" title="<?= __t('Download ekstensi VS Code offline (.VSIX)', 'Download offline VS Code extension (.VSIX)') ?>">
                <?= renderSvg('download', 13) ?>
                <span>Unduh VSIX</span>
            </a>

            <?php if ($currentUser): ?>
                <button type="button" class="btn btn-secondary btn-sm" onclick="openProfileModal()" title="<?= __t('Edit Profil & Password', 'Edit Profile & Password') ?>">
                    <?= renderSvg('user', 13) ?>
                    <span><?= htmlspecialchars(!empty($currentUser['name']) ? $currentUser['name'] : explode('@', $currentUser['email'])[0]) ?></span>
                    <?php if (!empty($isSuperAdmin)): ?>
                        <span class="badge badge-pending" style="height: 18px; font-size: 9.5px; padding: 0 5px; margin-left: 4px;">ADMIN</span>
                    <?php endif; ?>
                </button>
                <a class="btn btn-secondary btn-sm" href="?action=logout" title="<?= __t('Keluar dari akun', 'Sign Out') ?>">
                    <?= renderSvg('logout', 13) ?>
                    <span><?= __t('Keluar', 'Sign Out') ?></span>
                </a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$currentUser): ?>
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

                <!-- Action CTA Row (Popup Trigger Buttons) -->
                <div class="landing-cta-row" style="display: flex; gap: 12px; margin: 28px 0 32px; flex-wrap: wrap;">
                    <button type="button" class="btn btn-primary" onclick="openAuthModal('login')" style="padding: 12px 24px; font-size: 14px; font-weight: 600;">
                        <?= renderSvg('user', 14) ?>
                        <span><?= __t('Masuk ke Dashboard', 'Sign In to Dashboard') ?></span>
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="openAuthModal('register')" style="padding: 12px 24px; font-size: 14px; font-weight: 500;">
                        <span><?= __t('Daftar Akun Baru', 'Create Account') ?></span>
                    </button>
                </div>

                <ol class="landing-steps">
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
                        <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($cfSiteKey) ?>" data-callback="onTurnstileSuccessLogin" data-expired-callback="onTurnstileExpiredLogin" data-theme="dark" style="margin-bottom: 14px;"></div>
                        <button type="submit" id="btnSubmitLogin" class="btn btn-primary" style="width: 100%; height: 42px; opacity: 0.6; cursor: not-allowed;" disabled>
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
                        <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($cfSiteKey) ?>" data-callback="onTurnstileSuccessRegister" data-expired-callback="onTurnstileExpiredRegister" data-theme="dark" style="margin-bottom: 14px;"></div>
                        <button type="submit" id="btnSubmitRegister" class="btn btn-primary" style="width: 100%; height: 40px; opacity: 0.6; cursor: not-allowed;" disabled>
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
                                <label><?= __t('Password Baru (Min 6 Karakter):', 'New Password (Min 6 Chars):') ?></label>
                                <input type="password" name="new_password" minlength="6" placeholder="Password baru" required autocomplete="new-password">
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

    <?php else: ?>
        <!-- ==================================================================== -->
        <!-- MODAL POPUP: PROFIL & GANTI PASSWORD                                -->
        <!-- ==================================================================== -->
        <div class="modal-overlay" id="modalProfile" onclick="if(event.target === this) closeProfileModal()">
            <div class="modal-dialog" style="max-width: 480px; padding: 24px 22px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div class="auth-brand-glyph" style="margin-bottom: 0; width: 36px; height: 36px;"><?= renderSvg('user', 18) ?></div>
                        <div>
                            <h2 class="auth-title" style="font-size: 16.5px; margin-bottom: 2px;"><?= __t('Profil & Keamanan Akun', 'Profile & Account Security') ?></h2>
                            <p class="auth-subtitle" style="font-size: 12px; margin-bottom: 0;"><?= __t('Perbarui nama tampilan dan kata sandi akun Anda.', 'Update your display name and account password.') ?></p>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary btn-xs" onclick="closeProfileModal()" style="border-radius: 50%; width: 28px; height: 28px; padding: 0; display: flex; align-items: center; justify-content: center; font-size: 16px;" title="<?= __t('Tutup', 'Close') ?>">&times;</button>
                </div>

                <form method="POST" action="?action=update_profile">
                    <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($currentTab ?? 'projects') ?>">

                    <!-- Field Nama (Bisa diubah) -->
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label style="font-size: 12.5px; font-weight: 500; color: #fff; display: flex; justify-content: space-between;">
                            <span><?= __t('Nama Pengguna / Tampilan:', 'Display / User Name:') ?></span>
                            <span style="font-size: 11px; color: #10b981; font-weight: normal;"><?= __t('Dapat diubah', 'Editable') ?></span>
                        </label>
                        <input type="text" name="name" value="<?= htmlspecialchars($currentUser['name'] ?? '') ?>" placeholder="<?= __t('Masukkan nama lengkap Anda', 'Enter your full name') ?>" maxlength="100" style="height: 40px;">
                    </div>

                    <!-- Field Email (Terkunci / Tidak dapat dirubah) -->
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label style="font-size: 12.5px; font-weight: 500; color: var(--text-secondary); display: flex; justify-content: space-between;">
                            <span><?= __t('Alamat Email (Akun):', 'Email Address (Account):') ?></span>
                            <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); font-size: 10px; padding: 1px 6px;"><?= __t('Terkunci / Permanen', 'Locked / Permanent') ?></span>
                        </label>
                        <input type="email" value="<?= htmlspecialchars($currentUser['email']) ?>" readonly disabled style="height: 40px; background: rgba(255, 255, 255, 0.04); border-color: rgba(255, 255, 255, 0.1); color: var(--text-muted); cursor: not-allowed;">
                        <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?= __t('Alamat email digunakan sebagai kredensial utama dan tidak dapat diubah.', 'Email address is your primary credential and cannot be changed.') ?></small>
                    </div>

                    <!-- Field WhatsApp (Terkunci / Tidak dapat dirubah) -->
                    <div class="form-group" style="margin-bottom: 18px;">
                        <label style="font-size: 12.5px; font-weight: 500; color: var(--text-secondary); display: flex; justify-content: space-between;">
                            <span><?= __t('Nomor WhatsApp (Akun):', 'WhatsApp Number (Account):') ?></span>
                            <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); font-size: 10px; padding: 1px 6px;"><?= __t('Terkunci / Permanen', 'Locked / Permanent') ?></span>
                        </label>
                        <input type="text" value="<?= htmlspecialchars($currentUser['whatsapp']) ?>" readonly disabled style="height: 40px; background: rgba(255, 255, 255, 0.04); border-color: rgba(255, 255, 255, 0.1); color: var(--text-muted); cursor: not-allowed;">
                        <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?= __t('Nomor WhatsApp digunakan untuk autentikasi sistem dan tidak dapat diubah.', 'WhatsApp number is used for system auth and cannot be changed.') ?></small>
                    </div>

                    <!-- Collapsible / Bagian Ganti Password -->
                    <div style="background: var(--bg-surface-inset); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 14px; margin-bottom: 18px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h4 style="font-size: 13px; font-weight: 600; color: #fff; margin: 0; display: flex; align-items: center; gap: 6px;">
                                <?= renderSvg('key', 13) ?>
                                <span><?= __t('Ganti Password Akun (Opsional)', 'Change Account Password (Optional)') ?></span>
                            </h4>
                            <span style="font-size: 11px; color: var(--text-muted);"><?= __t('Kosongkan jika tidak ganti', 'Leave blank to keep') ?></span>
                        </div>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label style="font-size: 11.5px;"><?= __t('Password Lama / Saat Ini:', 'Current Password:') ?></label>
                            <input type="password" name="current_password" placeholder="<?= __t('Password saat ini (jika ganti password)', 'Current password (if changing)') ?>" style="height: 38px;">
                        </div>
                        <div class="grid-2" style="gap: 10px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label style="font-size: 11.5px;"><?= __t('Password Baru (Min 6 Karakter):', 'New Password (Min 6 Chars):') ?></label>
                                <input type="password" name="new_password" minlength="6" placeholder="<?= __t('Password baru', 'New password') ?>" style="height: 38px;">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label style="font-size: 11.5px;"><?= __t('Ulangi Password Baru:', 'Confirm New Password:') ?></label>
                                <input type="password" name="confirm_password" minlength="6" placeholder="<?= __t('Konfirmasi password', 'Confirm password') ?>" style="height: 38px;">
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="closeProfileModal()"><?= __t('Batal', 'Cancel') ?></button>
                        <button type="submit" class="btn btn-primary btn-sm" style="min-width: 130px;"><?= __t('Simpan Perubahan', 'Save Changes') ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ==================================================================== -->
        <!-- USER DASHBOARD (iOS Minimalist Monochrome)                           -->
        <!-- ==================================================================== -->

        <?php
            $tzDash = !empty($currentUser['timezone']) ? $currentUser['timezone'] : 'Asia/Jakarta';
            try { $nowDash = new DateTime('now', new DateTimeZone($tzDash)); } catch (Exception $e) { $nowDash = new DateTime(); }
            $hDash = (int)$nowDash->format('G');
            $greet = $currentLang === 'en' 
                ? ($hDash < 12 ? 'Good morning' : ($hDash < 18 ? 'Good afternoon' : 'Good evening'))
                : ($hDash < 11 ? 'Selamat pagi' : ($hDash < 15 ? 'Selamat siang' : ($hDash < 19 ? 'Selamat sore' : 'Selamat malam')));
            $nameDash = ucfirst(strtok($currentUser['email'], '@'));
            $dayName = $currentLang === 'en'
                ? ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][(int)$nowDash->format('w')]
                : ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'][(int)$nowDash->format('w')];
            $openTasks = (int)$taskStats['PENDING'] + (int)$taskStats['IN_PROGRESS'];
            $openTasksMsg = $openTasks > 0 
                ? __t("$openTasks tugas masih berjalan.", "$openTasks tasks in progress.") 
                : __t("Semua antrean beres.", "All tasks completed.");
        ?>
        <div class="dash-head">
            <div>
                <div class="dash-eyebrow"><span class="dot"></span><?= $dayName ?>, <?= $nowDash->format('d.m.Y') ?> &middot; <?= $nowDash->format('H:i') ?> <?= htmlspecialchars(getTimezoneAbbr($tzDash)) ?></div>
                <h1 class="dash-greeting"><?= $greet ?>, <?= htmlspecialchars($nameDash) ?>.<br><em><?= $openTasksMsg ?></em></h1>
            </div>
        </div>

        <!-- Main Navigation (liquid tab bar) -->
        <nav class="main-nav" aria-label="Navigasi utama">
            <a href="?tab=tasks<?= $filterProjectId ? "&filter_project=$filterProjectId" : "" ?>" class="nav-pill <?= $currentTab === 'tasks' ? 'active' : '' ?>">
                <?= renderSvg('tasks', 14) ?>
                <span><?= __t('Antrean Tugas', 'Task Queue') ?> (<?= $taskStats['TOTAL'] ?>)</span>
            </a>
            <a href="?tab=projects" class="nav-pill <?= $currentTab === 'projects' ? 'active' : '' ?>">
                <?= renderSvg('settings', 14) ?>
                <span><?= __t('Pengaturan & Proyek', 'Settings & Projects') ?> (<?= count($projects) ?>)</span>
            </a>
            <a href="?tab=bantuan" class="nav-pill <?= $currentTab === 'bantuan' ? 'active' : '' ?>">
                <?= renderSvg('help', 14) ?>
                <span><?= __t('Bantuan & Panduan', 'Help & Guides') ?></span>
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
                <!-- Banner Panduan Penting: Pemicu Manual AI Agent & Prioritas Pekerja (Warna Kuat & High Visibility) -->
                <div class="card" style="margin-bottom: 22px; border: 2px solid #f59e0b; background: linear-gradient(135deg, rgba(245, 158, 11, 0.15) 0%, rgba(99, 102, 241, 0.10) 100%); border-radius: 12px; padding: 18px 22px; box-shadow: 0 4px 20px rgba(245, 158, 11, 0.18);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 18px;">
                        <div style="flex: 1; min-width: 290px;">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                <span style="background: #f59e0b; color: #000; font-weight: 800; font-size: 11px; padding: 3px 8px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.5px; display: inline-flex; align-items: center; gap: 4px;">
                                    <?= renderSvg('zap', 12) ?> <?= __t('PETUNJUK UTAMA', 'KEY GUIDANCE') ?>
                                </span>
                                <h3 style="font-size: 16px; font-weight: 700; color: #fff; margin: 0;">
                                    <?= __t('Cara Terbaik: Perintahkan Manual AI Agent di IDE Chat', 'Best Practice: Prompt AI Agent Manually in IDE Chat') ?>
                                </h3>
                            </div>
                            <p style="color: #cbd5e1; font-size: 13px; line-height: 1.6; margin: 0 0 12px 0;">
                                <?= __t('Saat Anda berada di depan komputer, cara paling handal & instan agar AI Agent langsung menyelesaikan antrean <code>TASK_QUEUE.md</code> adalah mengetik kata kunci pemicu di Chat AI IDE (Antigravity / Claude / Copilot):', 'When you are at your computer, the most reliable and instant way for your AI Agent to solve <code>TASK_QUEUE.md</code> is by typing trigger keywords in your IDE AI Chat (Antigravity / Claude / Copilot):') ?>
                            </p>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                                <span style="color: #94a3b8; font-size: 12px; font-weight: 500;"><?= __t('Pemicu Sakti:', 'Magic Triggers:') ?></span>
                                <code style="background: rgba(0,0,0,0.5); border: 1px solid rgba(245,158,11,0.5); color: #fbbf24; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 13px; cursor: pointer;" onclick="copyText('proses antrian', 'Pemicu proses antrian')" title="<?= __t('Klik untuk salin', 'Click to copy') ?>">proses antrian</code>
                                <code style="background: rgba(0,0,0,0.5); border: 1px solid rgba(245,158,11,0.5); color: #fbbf24; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 13px; cursor: pointer;" onclick="copyText('handle task queue', 'Pemicu handle task queue')" title="<?= __t('Klik untuk salin', 'Click to copy') ?>">handle task queue</code>
                                <code style="background: rgba(0,0,0,0.5); border: 1px solid rgba(245,158,11,0.5); color: #fbbf24; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 13px; cursor: pointer;" onclick="copyText('kerjakan queue', 'Pemicu kerjakan queue')" title="<?= __t('Klik untuk salin', 'Click to copy') ?>">kerjakan queue</code>
                                <button type="button" class="btn btn-primary btn-sm" onclick="copyText('Periksa file TASK_QUEUE.md dan selesaikan seluruh tugas aktif sampai tuntas 100% sesuai AGENTS.md.', 'Perintah AI Agent')" style="background: #f59e0b; color: #000; font-weight: 700; border: none; margin-left: 6px; display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 6px;">
                                    <?= renderSvg('copy', 13) ?>
                                    <span><?= __t('Salin Perintah AI', 'Copy AI Prompt') ?></span>
                                </button>
                            </div>
                        </div>

                        <!-- Prioritas Pekerja Box -->
                        <div style="background: rgba(0, 0, 0, 0.55); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 10px; padding: 14px 18px; min-width: 270px; max-width: 360px;">
                            <div style="font-size: 12px; font-weight: 800; color: #fbbf24; text-transform: uppercase; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; letter-spacing: 0.5px;">
                                <?= renderSvg('activity', 13) ?> <?= __t('Prioritas Pekerja (Agent Priority):', 'Agent Priority Hierarchy:') ?>
                            </div>
                            <ul style="margin: 0; padding-left: 18px; font-size: 12.5px; color: #e2e8f0; line-height: 1.6;">
                                <li><strong style="color: #4ade80;">🥇 <?= __t('Prioritas Utama:', 'Primary Priority:') ?></strong> <code>Antigravity AI Agent</code> <?= __t('(otomatis mengambil semua tugas umum).', '(automatically takes all general tasks).') ?></li>
                                <li><strong style="color: #60a5fa;">🥈 <?= __t('Prioritas Spesifik:', 'Specific Assignment:') ?></strong> <?= __t('Ditentukan via', 'Assigned via') ?> <code>@claude</code>, <code>@copilot</code>, <code>@cursor</code>, <code>@gemini</code>.</li>
                                <li><strong style="color: #facc15;">🥉 <?= __t('Fallback Mandiri:', 'Autonomous Fallback:') ?></strong> <?= __t('Jika agent lain offline, Antigravity otomatis mengambil alih antrean.', 'If other agents are offline, Antigravity autonomously takes over.') ?></li>
                            </ul>
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

                    <div id="taskCardsWrapper">
                    <?php if (empty($myTasks)): ?>
                        <div style="text-align: center; padding: 48px 16px; color: var(--text-muted);">
                            <div style="width: 44px; height: 44px; border-radius: 50%; background: var(--bg-surface-inset); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px; color: var(--text-dim);">
                                <?= renderSvg('tasks', 20) ?>
                            </div>
                            <p style="font-size: 13.5px; font-weight: 400; color: var(--text-secondary);">Belum ada tugas di antrean.</p>
                            <p style="font-size: 12px; margin-top: 4px;">Kirim pesan <code>/task &lt;instruksi&gt;</code> di Telegram atau klik <b>Tambah Manual</b> di atas.</p>
                        </div>
                    <?php else: ?>
                        <div class="task-cards-list" id="taskCardsContainer">
                            <?php foreach ($myTasks as $t): ?>
                                <?php 
                                    $pTz = !empty($t['project_timezone']) ? $t['project_timezone'] : (!empty($currentUser['timezone']) ? $currentUser['timezone'] : 'Asia/Jakarta');
                                    $tAbbr = getTimezoneAbbr($pTz);
                                    $resText = !empty($t['result']) ? $t['result'] : (!empty($t['notes']) && $t['status'] === 'DONE' ? $t['notes'] : '');
                                    
                                    // Deteksi dan bersihkan lampiran (dari DB atau instruksi teks)
                                    $attUrl = !empty($t['attachment_url']) ? $t['attachment_url'] : '';
                                    $attType = !empty($t['attachment_type']) ? strtolower($t['attachment_type']) : '';
                                    $attName = !empty($t['attachment_name']) ? $t['attachment_name'] : '';
                                    $cleanInstruction = $t['instruction'];

                                    if (preg_match('/\[(?:Foto|Video|Dokumen)\s+Terlampir:\s*([^\]\(]+)(?:\s*\((https?:\/\/[^\)]+)\))?\]/i', $cleanInstruction, $mAtt)) {
                                        if (!$attName) $attName = trim($mAtt[1]);
                                        if (!$attUrl && !empty($mAtt[2])) $attUrl = trim($mAtt[2]);
                                        if (!$attType) {
                                            if (stripos($mAtt[0], 'Foto') !== false) $attType = 'photo';
                                            elseif (stripos($mAtt[0], 'Video') !== false) $attType = 'video';
                                            else $attType = 'document';
                                        }
                                        $cleanInstruction = trim(str_replace($mAtt[0], '', $cleanInstruction));
                                    }
                                    if ($attUrl && strpos($attUrl, '/uploads/#') !== false) {
                                        $attUrl = str_replace('/uploads/#', '/uploads/%23', $attUrl);
                                    }

                                    // Tentukan teks command yang ditampilkan di Baris 2
                                    $cmdDisplay = !empty($t['command']) ? trim($t['command']) : '';
                                    if (!$cmdDisplay) {
                                        $pCode = !empty($t['project_code']) ? strtolower($t['project_code']) : 'a';
                                        if ($t['sender'] === 'IDE Chat' || $t['sender'] === 'VS Code') {
                                            $cmdDisplay = 'tele-task add --sender "' . $t['sender'] . '"';
                                        } elseif (!empty($cleanInstruction) && preg_match('/^\/([a-zA-Z0-9_-]+)/', $cleanInstruction, $mCmd)) {
                                            $cmdDisplay = '/' . $mCmd[1];
                                        } elseif (!empty($cleanInstruction) && preg_match('/^#([a-zA-Z0-9_-]+)/', $cleanInstruction, $mCmd)) {
                                            $cmdDisplay = '#' . $mCmd[1];
                                        } else {
                                            $cmdDisplay = '#' . $pCode;
                                        }
                                    }
                                    $isPhoto = ($attType === 'photo' || preg_match('/\.(jpg|jpeg|png|webp|gif)($|\?)/i', $attUrl));
                                    $isVideo = ($attType === 'video' || preg_match('/\.(mp4|mov|mkv)($|\?)/i', $attUrl));
                                ?>
                                <!-- Card Akordion Default Minimized (Class: collapsed) -->
                                <div class="task-card collapsed" id="task-<?= htmlspecialchars($t['id']) ?>">
                                    <!-- ============================================================== -->
                                    <!-- BARIS 1: HEADER AKORDION (ID, WAKTU, PROYEK, SENDER, AKSI)     -->
                                    <!-- ============================================================== -->
                                    <div class="task-card-row-meta" onclick="toggleTaskAccordion('<?= htmlspecialchars($t['id']) ?>', event)" style="cursor: pointer;">
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
                                            <?php if ($attUrl && $isPhoto): ?>
                                                <span class="badge-media-indicator" title="Ada Lampiran Foto">
                                                    <?= renderSvg('photo', 11) ?> Foto
                                                </span>
                                            <?php elseif ($attUrl && $isVideo): ?>
                                                <span class="badge-media-indicator" style="background: rgba(168,85,247,0.12); color:#d8b4fe; border-color: rgba(168,85,247,0.3);" title="Ada Lampiran Video">
                                                    <?= renderSvg('video', 11) ?> Video
                                                </span>
                                            <?php elseif ($attUrl): ?>
                                                <span class="badge-media-indicator" style="background: rgba(16,185,129,0.12); color:#86efac; border-color: rgba(16,185,129,0.3);" title="Ada Lampiran File">
                                                    <?= renderSvg('file', 11) ?> File
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="task-meta-right">
                                            <?php if ($t['status'] === 'PENDING'): ?>
                                                <span class="badge badge-pending">PENDING</span>
                                            <?php elseif ($t['status'] === 'IN_PROGRESS'): ?>
                                                <span class="badge badge-progress">PROGRESS</span>
                                            <?php elseif ($t['status'] === 'DONE'): ?>
                                                <?php
                                                $durBadge = '';
                                                if (!empty($t['created_at']) && !empty($t['updated_at'])) {
                                                    $sec = max(0, strtotime($t['updated_at']) - strtotime($t['created_at']));
                                                    $h = floor($sec / 3600);
                                                    $m = floor(($sec % 3600) / 60);
                                                    $s = $sec % 60;
                                                    $durBadge = ($h > 0) ? "{$h}j {$m}m" : (($m > 0) ? "{$m}m {$s}s" : "{$s}s");
                                                }
                                                ?>
                                                <span class="badge badge-done" title="Durasi pengerjaan: <?= $durBadge ?>">DONE <?= $durBadge ? "($durBadge)" : "" ?></span>
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
                                                <button type="button" class="btn btn-icon btn-ghost task-accordion-toggle" onclick="toggleTaskAccordion('<?= htmlspecialchars($t['id']) ?>', event)" title="Buka/Tutup Rincian">
                                                    <svg class="chevron-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="6 9 12 15 18 9"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- PREVIEW 1-BARIS SAAT MINIMIZED -->
                                    <div class="task-card-preview" onclick="toggleTaskAccordion('<?= htmlspecialchars($t['id']) ?>', event)">
                                        <?= htmlspecialchars(mb_strimwidth($cleanInstruction, 0, 110, '...')) ?>
                                    </div>

                                    <!-- ============================================================== -->
                                    <!-- BODY AKORDION (MUNCUL SAAT DI-EXPAND / DIKLIK)                  -->
                                    <!-- ============================================================== -->
                                    <div class="task-card-body" id="body-task-<?= htmlspecialchars($t['id']) ?>">
                                        <!-- BARIS 2: INSTRUKSI LENGKAP -->
                                        <div class="task-card-row-body">
                                            <div class="task-card-instruction">
                                                <?= nl2br(htmlspecialchars($cleanInstruction)) ?>
                                            </div>

                                            <!-- TAMPILAN LAMPIRAN FOTO / VIDEO / DOKUMEN -->
                                            <?php if (!empty($attUrl)): ?>
                                                <div class="task-card-media-box">
                                                    <?php if ($isPhoto): ?>
                                                        <div class="task-photo-wrap">
                                                            <a href="<?= htmlspecialchars($attUrl) ?>" target="_blank" class="task-photo-link" onclick="openImageModal(event, '<?= htmlspecialchars($attUrl) ?>', '<?= htmlspecialchars(addslashes($attName ?: 'Lampiran Foto')) ?>')">
                                                                <img src="<?= htmlspecialchars($attUrl) ?>" alt="Lampiran Foto" class="task-photo-img" loading="lazy" onerror="this.parentElement.style.display='none';">
                                                                <div class="task-photo-overlay">
                                                                    <?= renderSvg('eye', 13) ?> <span>Perbesar Foto</span>
                                                                </div>
                                                            </a>
                                                            <div class="task-media-caption">
                                                                <span><?= renderSvg('photo', 12) ?> <?= htmlspecialchars($attName ?: 'photo.jpg') ?></span>
                                                                <a href="<?= htmlspecialchars($attUrl) ?>" target="_blank" download class="btn btn-secondary btn-xs" style="padding: 2px 8px; font-size: 11px;">
                                                                    <?= renderSvg('download', 11) ?> Unduh
                                                                </a>
                                                            </div>
                                                        </div>
                                                    <?php elseif ($isVideo): ?>
                                                        <div class="task-video-wrap" style="max-width: 420px;">
                                                            <video controls preload="metadata" style="max-width: 100%; max-height: 240px; border-radius: var(--radius-sm); border: 1px solid var(--border-medium); background: #000;">
                                                                <source src="<?= htmlspecialchars($attUrl) ?>">
                                                                Browser Anda tidak mendukung pemutar video HTML5.
                                                            </video>
                                                            <div class="task-media-caption" style="margin-top: 4px;">
                                                                <span><?= renderSvg('video', 12) ?> <?= htmlspecialchars($attName ?: 'video.mp4') ?></span>
                                                                <a href="<?= htmlspecialchars($attUrl) ?>" target="_blank" download class="btn btn-secondary btn-xs" style="padding: 2px 8px; font-size: 11px;">
                                                                    <?= renderSvg('download', 11) ?> Unduh
                                                                </a>
                                                            </div>
                                                        </div>
                                                    <?php elseif ($attType === 'voice' || $attType === 'audio' || preg_match('/\.(ogg|oga|mp3|wav|m4a)($|\?)/i', $attUrl)): ?>
                                                        <div class="task-audio-wrap" style="max-width: 440px; padding: 6px 0;">
                                                            <audio controls preload="metadata" style="width: 100%; height: 38px; border-radius: var(--radius-sm);">
                                                                <source src="<?= htmlspecialchars($attUrl) ?>">
                                                                Browser Anda tidak mendukung pemutar audio.
                                                            </audio>
                                                            <div class="task-media-caption" style="margin-top: 6px;">
                                                                <span><?= renderSvg('play', 11) ?> Pesan Suara / Audio (<?= htmlspecialchars($attName ?: 'audio') ?>)</span>
                                                                <a href="<?= htmlspecialchars($attUrl) ?>" target="_blank" download class="btn btn-secondary btn-xs" style="padding: 2px 8px; font-size: 11px;">
                                                                    <?= renderSvg('download', 11) ?> Unduh Audio
                                                                </a>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                                                            <span style="font-size: 12.5px; color: var(--text-secondary); display: flex; align-items: center; gap: 6px;">
                                                                <?= renderSvg('file', 14) ?> <?= htmlspecialchars($attName ?: 'Dokumen Terlampir') ?>
                                                            </span>
                                                            <a href="<?= htmlspecialchars($attUrl) ?>" target="_blank" download class="btn btn-secondary btn-xs">
                                                                <?= renderSvg('download', 11) ?> Unduh Dokumen
                                                            </a>
                                                        </div>
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

                                        <!-- BARIS 3: RESULT PEKERJAAN -->
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
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- ============================================================== -->
                        <!-- BARIS PAGINATION DEFAULT 10 LIST                                -->
                        <!-- ============================================================== -->
                        <?php if ($totalPages > 1 || $totalTasksCount > 10): ?>
                            <div class="pagination-bar" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border-subtle);">
                                <div style="font-size: 12.5px; color: var(--text-muted);">
                                    Menampilkan <b><?= ($totalTasksCount > 0 ? $offset + 1 : 0) ?>-<?= min($offset + $perPage, $totalTasksCount) ?></b> dari <b><?= $totalTasksCount ?></b> tugas
                                    (Halaman <?= $page ?> dari <?= $totalPages ?>)
                                </div>

                                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                    <?php 
                                        $baseQuery = "?tab=tasks";
                                        if ($filterProjectId > 0) $baseQuery .= "&filter_project=" . $filterProjectId;
                                        if ($filterStatus) $baseQuery .= "&filter_status=" . urlencode($filterStatus);
                                        $baseQuery .= "&per_page=" . $perPage;
                                    ?>

                                    <?php if ($page > 1): ?>
                                        <a href="<?= $baseQuery ?>&page=1" class="btn btn-secondary btn-xs" title="Halaman Pertama">«</a>
                                        <a href="<?= $baseQuery ?>&page=<?= $page - 1 ?>" class="btn btn-secondary btn-xs" title="Halaman Sebelumnya">‹</a>
                                    <?php endif; ?>

                                    <?php
                                        $startP = max(1, $page - 2);
                                        $endP = min($totalPages, $page + 2);
                                        for ($pIdx = $startP; $pIdx <= $endP; $pIdx++):
                                    ?>
                                        <a href="<?= $baseQuery ?>&page=<?= $pIdx ?>" class="btn btn-xs <?= $pIdx === $page ? 'btn-primary' : 'btn-secondary' ?>" style="<?= $pIdx === $page ? 'font-weight:600;' : '' ?>">
                                            <?= $pIdx ?>
                                        </a>
                                    <?php endfor; ?>

                                    <?php if ($page < $totalPages): ?>
                                        <a href="<?= $baseQuery ?>&page=<?= $page + 1 ?>" class="btn btn-secondary btn-xs" title="Halaman Berikutnya">›</a>
                                        <a href="<?= $baseQuery ?>&page=<?= $totalPages ?>" class="btn btn-secondary btn-xs" title="Halaman Terakhir">»</a>
                                    <?php endif; ?>

                                    <!-- Selector Per Page -->
                                    <div style="display: flex; align-items: center; gap: 4px; margin-left: 10px; font-size: 11.5px; color: var(--text-muted);">
                                        <span>Per hal:</span>
                                        <?php foreach ([10, 25, 50] as $lim): ?>
                                            <?php 
                                                $limQuery = "?tab=tasks";
                                                if ($filterProjectId > 0) $limQuery .= "&filter_project=" . $filterProjectId;
                                                if ($filterStatus) $limQuery .= "&filter_status=" . urlencode($filterStatus);
                                                $limQuery .= "&per_page=" . $lim . "&page=1";
                                            ?>
                                            <a href="<?= $limQuery ?>" class="badge" style="cursor: pointer; padding: 2px 6px; <?= $perPage === $lim ? 'background: var(--ink-4); color: #fff; font-weight:600; border-color: var(--accent-primary);' : 'background: transparent; color: var(--text-dim);' ?>">
                                                <?= $lim ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    </div>
                </div>
            </section>

        <?php elseif ($currentTab === 'projects'): ?>
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


                <!-- ================================================================ -->
                <!-- KARTU ANALITIK PENGUNJUNG & LOKASI IP-API (KHUSUS ADMIN)          -->
                <!-- ================================================================ -->
                <div class="card" style="margin-bottom: 24px; border: 1px solid rgba(56, 189, 248, 0.25); background: linear-gradient(180deg, rgba(15, 23, 42, 0.8) 0%, rgba(10, 15, 30, 0.95) 100%);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <h3 style="font-size: 16px; font-weight: 600; color: #38bdf8; display: flex; align-items: center; gap: 8px; margin: 0 0 4px 0;">
                                <?= renderSvg('globe', 18) ?>
                                <span><?= __t('Analitik Pengunjung & Lokasi Geografis (ip-api.com)', 'Visitor Analytics & Geolocation (ip-api.com)') ?></span>
                                <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); font-size: 10px;">ADMIN ONLY</span>
                            </h3>
                            <p style="font-size: 12px; color: var(--text-muted); margin: 0;">
                                <?= __t('Pencatatan real-time lokasi IP, negara, kota, ISP, dan tipe perangkat pengunjung menggunakan engine ip-api.com.', 'Real-time logging of visitor IP geolocation, country, city, ISP, and device type via ip-api.com engine.') ?>
                            </p>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <form method="POST" action="?action=clear_analytics" onsubmit="return confirm('Apakah Anda yakin ingin menghapus seluruh log analitik pengunjung?');" style="margin: 0;">
                                <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                                <button type="submit" class="btn btn-secondary btn-xs" style="color: #f87171; border-color: rgba(239, 68, 68, 0.3);">
                                    <?= renderSvg('trash', 12) ?>
                                    <span><?= __t('Bersihkan Log', 'Clear Logs') ?></span>
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- KPI Cards Analitik -->
                    <div class="grid-4" style="margin-bottom: 20px; gap: 10px;">
                        <div class="stat-card" style="padding: 14px 16px;">
                            <div class="val" style="color: #38bdf8; font-size: 28px;"><?= number_format($analyticsStats['total_hits']) ?></div>
                            <div class="lbl"><?= __t('Total Kunjungan (Hits)', 'Total Page Hits') ?></div>
                        </div>
                        <div class="stat-card" style="padding: 14px 16px;">
                            <div class="val" style="color: #34d399; font-size: 28px;"><?= number_format($analyticsStats['unique_ips']) ?></div>
                            <div class="lbl"><?= __t('Pengunjung Unik (IP)', 'Unique Visitors (IP)') ?></div>
                        </div>
                        <div class="stat-card" style="padding: 14px 16px;">
                            <div class="val" style="color: #fbbf24; font-size: 28px;"><?= number_format($analyticsStats['countries_count']) ?></div>
                            <div class="lbl"><?= __t('Negara Terdeteksi', 'Countries Detected') ?></div>
                        </div>
                        <div class="stat-card" style="padding: 14px 16px;">
                            <div class="val" style="color: #a78bfa; font-size: 28px;"><?= number_format($analyticsStats['cities_count']) ?></div>
                            <div class="lbl"><?= __t('Kota Terdeteksi', 'Cities Detected') ?></div>
                        </div>
                    </div>

                    <!-- Top Negara Breakdown -->
                    <?php if (!empty($topCountries)): ?>
                        <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 12px 16px; margin-bottom: 20px;">
                            <div style="font-size: 11.5px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 10px; display: flex; justify-content: space-between;">
                                <span><?= __t('Top 5 Asal Negara Pengunjung', 'Top 5 Visitor Countries') ?></span>
                                <span><?= __t('Perangkat: Mobile (' . $analyticsStats['mobile_hits'] . ') vs Desktop (' . $analyticsStats['desktop_hits'] . ')', 'Devices: Mobile (' . $analyticsStats['mobile_hits'] . ') vs Desktop (' . $analyticsStats['desktop_hits'] . ')') ?></span>
                            </div>
                            <div style="display: flex; gap: 14px; flex-wrap: wrap;">
                                <?php foreach ($topCountries as $tc): ?>
                                    <div style="flex: 1; min-width: 140px; background: var(--bg-surface-inset); border: 1px solid var(--border-subtle); border-radius: 6px; padding: 8px 12px; display: flex; justify-content: space-between; align-items: center;">
                                        <div style="font-size: 13px; font-weight: 500; color: #fff; display: flex; align-items: center; gap: 6px;">
                                            <span>🌐</span>
                                            <span><?= htmlspecialchars($tc['country']) ?></span>
                                        </div>
                                        <span class="badge badge-secondary" style="font-size: 11px;"><?= $tc['hits'] ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Tabel Log 40 Pengunjung Terkini -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <h4 style="font-size: 13.5px; font-weight: 500; color: #fff; margin: 0;">
                            <?= __t('Daftar Kunjungan Terkini', 'Recent Visitors Log') ?>
                        </h4>
                        <span style="font-size: 11.5px; color: var(--text-muted);">
                            <?= __t('Menampilkan 40 data terbaru', 'Showing latest 40 entries') ?>
                        </span>
                    </div>

                    <div class="table-wrapper">
                        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                            <thead>
                                <tr style="border-bottom: 1px solid var(--border-medium); text-align: left; color: var(--text-muted);">
                                    <th style="padding: 10px 8px;"><?= __t('Waktu', 'Time') ?></th>
                                    <th style="padding: 10px 8px;"><?= __t('IP Address', 'IP Address') ?></th>
                                    <th style="padding: 10px 8px;"><?= __t('Lokasi (Kota, Wilayah, Negara)', 'Location (City, Region, Country)') ?></th>
                                    <th style="padding: 10px 8px;"><?= __t('ISP / Provider', 'ISP / Provider') ?></th>
                                    <th style="padding: 10px 8px;"><?= __t('Perangkat & OS', 'Device & OS') ?></th>
                                    <th style="padding: 10px 8px;"><?= __t('Pengguna / Sesi', 'User / Session') ?></th>
                                    <th style="padding: 10px 8px; text-align: center;"><?= __t('Peta', 'Map') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentVisitors)): ?>
                                    <tr>
                                        <td colspan="7" style="padding: 24px; text-align: center; color: var(--text-muted);">
                                            <?= __t('Belum ada riwayat kunjungan yang tercatat.', 'No visitor logs recorded yet.') ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentVisitors as $v): ?>
                                        <tr style="border-bottom: 1px solid var(--border-subtle);">
                                            <td style="padding: 8px; color: var(--text-muted); white-space: nowrap; font-size: 11px;">
                                                <?= htmlspecialchars(date('d M Y H:i', strtotime($v['visited_at']))) ?>
                                            </td>
                                            <td style="padding: 8px; font-family: var(--font-mono); font-size: 11.5px; color: #38bdf8;">
                                                <?= htmlspecialchars($v['ip']) ?>
                                            </td>
                                            <td style="padding: 8px; color: #fff;">
                                                <?php if (!empty($v['city']) || !empty($v['country'])): ?>
                                                    <strong><?= htmlspecialchars($v['city'] ?: '-') ?></strong>, 
                                                    <span style="color: var(--text-secondary);"><?= htmlspecialchars($v['region'] ?: '') ?></span>
                                                    <span style="color: #fbbf24; font-size: 11px; margin-left: 2px;">(<?= htmlspecialchars($v['country'] ?: '-') ?>)</span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 8px; color: var(--text-secondary); max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?= htmlspecialchars($v['isp'] ?: '-') ?>
                                            </td>
                                            <td style="padding: 8px; white-space: nowrap;">
                                                <span class="badge <?= $v['device_type'] === 'Mobile' ? 'badge-primary' : 'badge-secondary' ?>" style="font-size: 10px; padding: 2px 6px;">
                                                    <?= htmlspecialchars($v['device_type']) ?>
                                                </span>
                                                <span style="color: var(--text-muted); font-size: 11px; margin-left: 4px;">
                                                    <?= htmlspecialchars($v['os'] . ' · ' . $v['browser']) ?>
                                                </span>
                                            </td>
                                            <td style="padding: 8px; font-size: 11.5px;">
                                                <?php if (!empty($v['user_email'])): ?>
                                                    <span style="color: #34d399; font-weight: 500;">👤 <?= htmlspecialchars($v['user_email']) ?></span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted);">Tamu (Guest)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 8px; text-align: center;">
                                                <?php if (!empty($v['lat']) && !empty($v['lon'])): ?>
                                                    <a href="https://www.google.com/maps?q=<?= urlencode($v['lat'] . ',' . $v['lon']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-xs" style="padding: 2px 7px; font-size: 10.5px;" title="<?= __t('Buka Koordinat GPS di Google Maps', 'Open GPS coordinates in Google Maps') ?>">
                                                        📍 Peta
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted);">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
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

                        <div style="border-top: 1px dashed var(--border-subtle); margin: 20px 0 16px; padding-top: 16px;">
                            <h4 style="font-size: 13.5px; font-weight: 600; color: #fff; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                                <span>🛡️</span> <?= __t('Cloudflare Turnstile (Proteksi Bot)', 'Cloudflare Turnstile (Bot Protection)') ?>
                            </h4>
                            <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 12px;">
                                <?= __t('Kunci Turnstile untuk verifikasi pendaftaran & login. Default menggunakan Cloudflare test key (selalu lolos).', 'Turnstile keys for registration & login verification. Defaults to Cloudflare test key (always passes).') ?>
                            </p>
                            <div class="grid-2" style="gap: 12px; margin-bottom: 8px;">
                                <div class="form-group">
                                    <label><?= __t('Site Key:', 'Site Key:') ?></label>
                                    <input type="text" name="turnstile_site_key" value="<?= htmlspecialchars(getSetting('turnstile_site_key', '1x00000000000000000000AA')) ?>" placeholder="1x00000000000000000000AA">
                                </div>
                                <div class="form-group">
                                    <label><?= __t('Secret Key:', 'Secret Key:') ?></label>
                                    <input type="password" name="turnstile_secret_key" value="<?= htmlspecialchars(getSetting('turnstile_secret_key', '1x0000000000000000000000000000000AA')) ?>" placeholder="1x0000000000000000000000000000000AA">
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-sm"><?= __t('Simpan Konfigurasi SMTP & Turnstile', 'Save SMTP & Turnstile Configuration') ?></button>
                    </form>

                    <hr style="border: 0; border-top: 1px solid var(--border-subtle); margin: 24px 0 20px;">

                    <h4 style="font-size: 14px; font-weight: 500; color: #fff; margin-bottom: 6px; display: flex; align-items: center; gap: 8px;">
                        <span>🧪</span> <?= __t('Uji Koneksi & Kirim Email Tes SMTP', 'Test SMTP Connection & Send Test Email') ?>
                    </h4>
                    <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 14px;">
                        <?= __t('Kirimkan email uji coba langsung untuk memverifikasi socket koneksi SMTP, autentikasi user/password, dan kesiapan pengiriman.', 'Send an immediate test email to verify SMTP socket connection, authentication, and delivery readiness.') ?>
                    </p>

                    <form method="POST" action="?action=test_smtp" style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 16px;">
                        <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                        <div class="form-group" style="flex: 1; min-width: 260px; margin-bottom: 0;">
                            <label><?= __t('Email Penerima Tes * :', 'Test Recipient Email * :') ?></label>
                            <input type="email" name="test_email" value="<?= htmlspecialchars(!empty($_SESSION['tvsc_smtp_diag']['email']) ? $_SESSION['tvsc_smtp_diag']['email'] : $currentUser['email']) ?>" placeholder="admin@domain.com" required>
                        </div>
                        <button type="submit" class="btn btn-secondary btn-sm" style="height: 38px;">
                            <span>🚀</span> <?= __t('Kirim Email Tes Sekarang', 'Send Test Email Now') ?>
                        </button>
                    </form>

                    <?php if (!empty($_SESSION['tvsc_smtp_diag'])): ?>
                        <?php 
                            $diag = $_SESSION['tvsc_smtp_diag']; 
                            $isDiagOk = !empty($diag['ok']);
                        ?>
                        <div style="background: <?= $isDiagOk ? 'rgba(16, 185, 129, 0.08)' : 'rgba(239, 68, 68, 0.08)' ?>; border: 1px solid <?= $isDiagOk ? 'rgba(16, 185, 129, 0.3)' : 'rgba(239, 68, 68, 0.3)' ?>; border-radius: 8px; padding: 14px; margin-top: 14px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span class="badge" style="background: <?= $isDiagOk ? '#10b981' : '#ef4444' ?>; color: #fff; font-weight: 600; font-size: 11px; padding: 2px 8px; border-radius: 4px;">
                                        <?= $isDiagOk ? '✅ ' . __t('BERHASIL', 'SUCCESS') : '❌ ' . __t('GAGAL', 'FAILED') ?>
                                    </span>
                                    <span style="font-size: 12.5px; color: #f8fafc; font-weight: 500;">
                                        <?= __t('Hasil Uji Coba Terakhir:', 'Last Diagnostic Result:') ?> <b><?= htmlspecialchars($diag['email']) ?></b> (<?= htmlspecialchars($diag['time']) ?>)
                                    </span>
                                </div>
                                <a href="?action=clear_smtp_diag" class="btn btn-secondary btn-xs" style="text-decoration: none;"><?= __t('Tutup Log', 'Dismiss Log') ?></a>
                            </div>

                            <?php if (!$isDiagOk && !empty($diag['error'])): ?>
                                <div style="color: #f87171; font-size: 12px; margin-bottom: 8px; font-weight: 500;">
                                    ⚠️ <?= htmlspecialchars($diag['error']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($diag['log'])): ?>
                                <details style="margin-top: 6px;">
                                    <summary style="font-size: 11.5px; color: var(--text-muted); cursor: pointer; user-select: none;">
                                        <?= __t('Lihat Rincian Socket Handshake Log (Technical Trace)', 'View Socket Handshake Technical Trace') ?>
                                    </summary>
                                    <pre style="background: #090d16; color: #86efac; padding: 12px; border-radius: 6px; font-size: 11.5px; font-family: monospace; max-height: 200px; overflow-y: auto; margin-top: 8px; white-space: pre-wrap; line-height: 1.4;"><?= htmlspecialchars($diag['log']) ?></pre>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

    <?php endif; ?>

    <!-- Global Footer (Landing & Dashboard) -->
    <footer class="app-footer">
        <div class="footer-credit">
            &copy; <?= date('Y') ?> <b>Telegram VS Code</b> &middot; <?= __t('Karya Dedikasi Anak Magang di', 'Dedicated Creation by Intern Talents at') ?> <a href="https://www.biru.co.id" target="_blank" rel="noopener noreferrer" class="footer-biru-link">Biru Indonesia Creative</a>
        </div>
        <div class="footer-links">
            <button type="button" class="btn btn-secondary btn-sm" onclick="openAboutModal()">
                <?= renderSvg('info', 13) ?>
                <span><?= __t('Tentang Kami', 'About Us') ?></span>
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
                <span><?= __t('Tentang Telegram VS Code', 'About Telegram VS Code') ?></span>
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
// Live Auto-Update Engine dengan Sonner Notifications
// ==============================================================================
(function() {
    let lastStateHash = '';
    let knownTaskStatus = {};
    let isInitialLoad = true;
    let isFetching = false;

    async function pollDashboardUpdates() {
        if (isFetching) return;
        isFetching = true;
        try {
            const url = '?action=check_task_updates&hash=' + encodeURIComponent(lastStateHash);
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
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

                // Cek Perubahan Status Tugas & Munculkan Notifikasi Sonner
                if (data.changed && Array.isArray(data.tasks)) {
                    for (const t of data.tasks) {
                        const prevStatus = knownTaskStatus[t.id];
                        const cleanInst = (t.instruction || '').replace(/\[(?:Foto|Video|Dokumen)[^\]]+\]/g, '').trim();
                        const snippet = cleanInst.length > 70 ? cleanInst.substring(0, 70) + '...' : cleanInst;

                        if (!prevStatus) {
                            // Tugas Baru Masuk
                            if (typeof toast !== 'undefined') {
                                toast('📥 Tugas Baru di Antrean!', {
                                    description: `${t.id}: ${snippet || 'Tugas coding baru diterima'}`
                                });
                            }
                        } else if (prevStatus !== t.status) {
                            // Status Berubah
                            if (t.status === 'DONE') {
                                if (typeof toast !== 'undefined') {
                                    const resSnippet = (t.result || t.notes || 'Tugas selesai 100%').substring(0, 80);
                                    toast.success(`✅ Tugas ${t.id} Selesai 100%!`, {
                                        description: resSnippet
                                    });
                                }
                            } else if (t.status === 'IN_PROGRESS') {
                                if (typeof toast !== 'undefined') {
                                    toast.info(`⚡ Memulai Pekerjaan: ${t.id}`, {
                                        description: 'AI Agent di VS Code sedang mengeksekusi tugas...'
                                    });
                                }
                            } else if (t.status === 'FAILED') {
                                if (typeof toast !== 'undefined') {
                                    toast.error(`🚨 Kendala pada ${t.id}`, {
                                        description: t.notes || 'Pengerjaan tugas mengalami kendala'
                                    });
                                }
                            }
                        }
                        knownTaskStatus[t.id] = t.status;
                    }

                    lastStateHash = data.hash;

                    // Auto-refresh DOM Antrean Tugas tanpa reload halaman penuh
                    const taskWrapper = document.getElementById('taskCardsWrapper');
                    if (taskWrapper) {
                        try {
                            const refreshUrl = window.location.href + (window.location.href.includes('?') ? '&' : '?') + '_ts=' + Date.now();
                            const pageRes = await fetch(refreshUrl, { cache: 'no-store' });
                            if (pageRes.ok) {
                                const htmlText = await pageRes.text();
                                const parser = new DOMParser();
                                const doc = parser.parseFromString(htmlText, 'text/html');
                                const newWrapper = doc.getElementById('taskCardsWrapper');
                                const newCountSummary = doc.getElementById('taskQueueCountSummary');
                                const oldCountSummary = document.getElementById('taskQueueCountSummary');

                                if (newWrapper) {
                                    taskWrapper.innerHTML = newWrapper.innerHTML;
                                }
                                if (newCountSummary && oldCountSummary) {
                                    oldCountSummary.innerHTML = newCountSummary.innerHTML;
                                }
                            }
                        } catch (domErr) {}
                    }
                }
            }
        } catch (err) {
        } finally {
            isFetching = false;
        }
    }

    // Jalankan auto-update setiap 3.5 detik
    setInterval(pollDashboardUpdates, 3500);
    setTimeout(pollDashboardUpdates, 1000);
})();

</script>

</body>
</html>
