<?php
// ==============================================================================
// Platform: Telegram Dev Notifier for VS Code & Antigravity
// Module: Database Connection, Migrations & Safe Queries (SQLite)
// ==============================================================================

if (!function_exists('getDb')) {
    function getDb($forceNew = false) {
        static $pdo = null;
        if ($forceNew || $pdo === null) {
            $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 30,
            ]);
            $pdo->exec("PRAGMA busy_timeout = 30000;");
            $pdo->exec("PRAGMA journal_mode = WAL;");
            $pdo->exec("PRAGMA synchronous = NORMAL;");
            $pdo->exec("PRAGMA foreign_keys = ON;");
            $pdo->exec("PRAGMA temp_store = MEMORY;");
            $pdo->exec("PRAGMA cache_size = -20000;");
            
            // Optimasi: Eksekusi DDL & Migrasi skema database hanya jika versi skema belum up-to-date
            $stmtVer = $pdo->query("PRAGMA user_version");
            $ver = (int)$stmtVer->fetchColumn();
            $stmtVer->closeCursor();
            $targetVer = 9;
            if ($ver < $targetVer) {
                runDatabaseMigrations($pdo);
                $pdo->exec("PRAGMA user_version = $targetVer;");
            }
        }
        return $pdo;
    }
}

// Helper Eksekusi Query dengan Auto-Retry saat Lock Contention (Anti Database Locked)
if (!function_exists('safeDbExecute')) {
    function safeDbExecute($stmt, array $params = [], int $maxRetries = 5, int $delayMs = 150) {
        $attempts = 0;
        while (true) {
            $attempts++;
            try {
                return $stmt->execute($params);
            } catch (\PDOException $e) {
                $msg = $e->getMessage();
                $isLocked = ($e->getCode() == 'HY000' && (stripos($msg, 'database is locked') !== false || stripos($msg, 'busy') !== false));
                if ($isLocked && $attempts < $maxRetries) {
                    usleep($delayMs * 1000 * $attempts);
                    continue;
                }
                throw $e;
            }
        }
    }
}

// Skema Migrasi Database (Hanya dijalankan saat inisialisasi / update skema)
if (!function_exists('runDatabaseMigrations')) {
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
            timezone TEXT DEFAULT 'Asia/Jakarta',
            custom_commands TEXT DEFAULT '',
            executor_mode TEXT DEFAULT 'vscode',
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
            priority TEXT DEFAULT 'NORMAL',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes TEXT,
            result TEXT DEFAULT '',
            command TEXT DEFAULT '',
            retry_count INTEGER DEFAULT 0,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        // Table Project Media & Dokumentasi
        $pdo->exec("CREATE TABLE IF NOT EXISTS project_media (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            project_id INTEGER DEFAULT 0,
            project_key TEXT DEFAULT '',
            task_id TEXT DEFAULT '',
            file_name TEXT NOT NULL,
            original_name TEXT DEFAULT '',
            file_path TEXT NOT NULL,
            file_url TEXT NOT NULL,
            file_type TEXT DEFAULT '',
            file_size INTEGER DEFAULT 0,
            caption TEXT DEFAULT '',
            uploaded_by TEXT DEFAULT 'IDE',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
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
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN priority TEXT DEFAULT 'NORMAL'"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN retry_count INTEGER DEFAULT 0"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN is_blocked INTEGER DEFAULT 0"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN block_notify INTEGER DEFAULT 0"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN is_admin INTEGER DEFAULT 0"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN timezone TEXT DEFAULT 'Asia/Jakarta'"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE projects ADD COLUMN timezone TEXT DEFAULT 'Asia/Jakarta'"); } catch (Exception $e) {}
        try { $pdo->exec("UPDATE projects SET timezone = 'Asia/Jakarta' WHERE timezone IS NULL OR timezone = ''"); } catch (Exception $e) {}
        try { $pdo->exec("UPDATE users SET timezone = 'Asia/Jakarta' WHERE timezone IS NULL OR timezone = ''"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE projects ADD COLUMN code TEXT DEFAULT ''"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE projects ADD COLUMN last_workspace_folder TEXT DEFAULT ''"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE projects ADD COLUMN last_operator TEXT DEFAULT ''"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE projects ADD COLUMN last_seen DATETIME DEFAULT NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE projects ADD COLUMN model_email TEXT DEFAULT ''"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE projects ADD COLUMN bot_username TEXT DEFAULT ''"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE projects ADD COLUMN bot_verified_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE projects ADD COLUMN default_agent TEXT DEFAULT 'Antigravity AI Agent'"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE projects ADD COLUMN executor_mode TEXT DEFAULT 'vscode'"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN assigned_agent TEXT DEFAULT 'Antigravity AI Agent'"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN start_notified INTEGER DEFAULT 0"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN done_notified INTEGER DEFAULT 0"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN attachments_json TEXT DEFAULT ''"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN media_group_id TEXT DEFAULT ''"); } catch (Exception $e) {}
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS chat_sessions (
                chat_id TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                active_project_id INTEGER DEFAULT 0,
                default_agent TEXT DEFAULT '',
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE chat_sessions ADD COLUMN default_agent TEXT DEFAULT ''"); } catch (Exception $e) {}

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
    }
}

// Inisialisasi koneksi default database
$db = getDb();

