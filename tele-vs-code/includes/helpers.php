<?php
// ==============================================================================
// Platform: Telegram Dev Notifier for VS Code & Antigravity
// Module: General Helpers, Telegram Communication & SVG Icons
// ==============================================================================

if (!function_exists('getSupportedTimezones')) {
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
}

if (!function_exists('getTimezoneAbbr')) {
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
}

if (!function_exists('shortTaskId')) {
    function shortTaskId($taskId) {
        if (empty($taskId)) return '';
        $clean = trim($taskId);
        if (preg_match('/([a-zA-Z0-9]{4})$/', $clean, $m)) {
            return '#' . strtolower($m[1]);
        }
        return $clean;
    }
}

if (!function_exists('resolveTaskId')) {
    function resolveTaskId($db, $taskId) {
        if (empty($taskId)) return $taskId;
        $clean = trim($taskId);
        $stmt = $db->prepare("SELECT id FROM tasks WHERE id = ? LIMIT 1");
        $stmt->execute([$clean]);
        $found = $stmt->fetchColumn();
        if ($found) return $found;

        $withHash = str_starts_with($clean, '#') ? $clean : '#' . $clean;
        $withoutHash = ltrim($clean, '#');
        $stmt = $db->prepare("SELECT id FROM tasks WHERE id = ? OR id = ? LIMIT 1");
        $stmt->execute([$withHash, $withoutHash]);
        $found = $stmt->fetchColumn();
        if ($found) return $found;

        if (preg_match('/([a-zA-Z0-9]{4})$/', $clean, $m)) {
            $last4 = strtolower($m[1]);
            $stmt = $db->prepare("SELECT id FROM tasks WHERE LOWER(id) LIKE ? OR LOWER(id) LIKE ? ORDER BY id DESC LIMIT 1");
            $stmt->execute(['%' . $last4, '%-' . $last4]);
            $found = $stmt->fetchColumn();
            if ($found) return $found;
        }

        return $clean;
    }
}

if (!function_exists('applyTimezone')) {
    function applyTimezone($tz) {
        $tz = trim($tz ?: 'Asia/Jakarta');
        @date_default_timezone_set($tz);
        return $tz;
    }
}

if (!function_exists('generateUniqueProjectCode')) {
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
}

if (!function_exists('normalizePhone')) {
    function normalizePhone($phone) {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (strpos($clean, '0') === 0) {
            $clean = '62' . substr($clean, 1);
        }
        return $clean;
    }
}

if (!function_exists('callTelegram')) {
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
}

if (!function_exists('sendTelegramWithMedia')) {
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

        // 2. Fallback: Kirim via sendMessage dengan Link Preview Tersemat
        $msgText = $text;
        $linkPreviewOptions = ['is_disabled' => true];
        if ($attachmentUrl && in_array($attachmentType, ['photo', 'video'])) {
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
}

if (!function_exists('getSupportedAiAgents')) {
    function getSupportedAiAgents() {
        return [
            'antigravity' => [
                'name' => 'Antigravity AI Agent',
                'tag' => '@antigravity',
                'badge' => '⚡',
                'desc' => 'Multi-Task & Autonomous Pair Programmer (Utama)',
                'aliases' => ['anti', 'agy', 'antigrafity', 'antigraviti', 'anti-gravity']
            ],
            'claude' => [
                'name' => 'Claude Agent',
                'tag' => '@claude',
                'badge' => '🧠',
                'desc' => 'Claude Code CLI & Arsitektur Kompleks',
                'aliases' => ['claud', 'anthropic']
            ],
            'copilot' => [
                'name' => 'Copilot Agent',
                'tag' => '@copilot',
                'badge' => '🐙',
                'desc' => 'GitHub Copilot Chat & Editor IDE',
                'aliases' => ['github-copilot', 'gh-copilot', 'copilote']
            ],
            'gemini' => [
                'name' => 'Gemini Agent',
                'tag' => '@gemini',
                'badge' => '🔮',
                'desc' => 'Google Gemini CLI & Analisis Cepat',
                'aliases' => ['gemini-cli', 'google-gemini']
            ],
            'cursor' => [
                'name' => 'Cursor Agent',
                'tag' => '@cursor',
                'badge' => '💻',
                'desc' => 'Cursor Editor AI Pair Programming',
                'aliases' => ['cursor-ai']
            ],
            'cline' => [
                'name' => 'Cline Agent',
                'tag' => '@cline',
                'badge' => '🤖',
                'desc' => 'Autonomous Coding & Tool Execution',
                'aliases' => ['claude-dev', 'cline-agent']
            ],
            'roo' => [
                'name' => 'Roo Agent',
                'tag' => '@roo',
                'badge' => '🦘',
                'desc' => 'Roo Code Autonomous Pair Agent',
                'aliases' => ['roo-code', 'roocode']
            ],
            'codex' => [
                'name' => 'Codex Agent',
                'tag' => '@codex',
                'badge' => '⚡',
                'desc' => 'OpenAI Codex CLI Automation',
                'aliases' => []
            ]
        ];
    }
}

if (!function_exists('resolveAgentCodeOrName')) {
    function resolveAgentCodeOrName($input) {
        $clean = strtolower(trim(ltrim($input, '@')));
        if ($clean === '') return null;
        $agents = getSupportedAiAgents();
        foreach ($agents as $code => $info) {
            if ($clean === $code || strtolower($info['name']) === $clean || in_array($clean, $info['aliases'])) {
                return [$code, $info['name']];
            }
        }
        return null;
    }
}

if (!function_exists('getDefaultCommands')) {
    function getDefaultCommands() {
        return [
            [
                'command' => 'task',
                'category' => 'Tugas & Media',
                'example' => '/task Buatkan fitur login',
                'description' => 'Tambah tugas baru ke antrean AI Agent (bisa juga langsung kirim teks biasa, foto, dokumen, atau video).'
            ],
            [
                'command' => 'agent',
                'category' => 'AI Agent',
                'example' => '/agent atau /agent copilot',
                'description' => 'Lihat daftar agen AI yang siap diperintah atau ganti default agent (Antigravity, Claude, Copilot, Cursor, Gemini, dll).'
            ],
            [
                'command' => 'agents',
                'category' => 'AI Agent',
                'example' => '/agents',
                'description' => 'Daftar seluruh AI Agent resmi yang didukung beserta contoh perintahnya.'
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
                'command' => 'executor',
                'category' => 'Mesin Eksekutor',
                'example' => '/executor atau /executor shell atau /executor vscode',
                'description' => 'Pilih mesin eksekutor (VS Code atau Shell OS) & panduan runner Linux/macOS/Windows.'
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
}

if (!function_exists('getProjectCommands')) {
    function getProjectCommands($project) {
        $defaults = getDefaultCommands();
        $list = [];
        $seen = [];

        $list[] = [
            'command' => 'start',
            'description' => 'Mulai bot & periksa koneksi dengan server'
        ];
        $seen['start'] = true;

        if (!empty($project['code'])) {
            $pCode = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $project['code'])));
            if (!empty($pCode) && strlen($pCode) <= 32 && !isset($seen[$pCode])) {
                $list[] = [
                    'command' => $pCode,
                    'description' => 'Kirim tugas ke proyek ' . mb_substr($project['name'] ?? '', 0, 30)
                ];
                $seen[$pCode] = true;
            }
        }

        foreach ($defaults as $d) {
            $cmd = strtolower(trim($d['command']));
            $cmd = preg_replace('/^[^a-z0-9_]+/', '', $cmd);
            $cmd = preg_replace('/[^a-z0-9_]/', '', $cmd);
            if (empty($cmd) || strlen($cmd) > 32 || isset($seen[$cmd])) continue;
            if ($cmd === 'verifikasi' || $cmd === 'verify') continue;

            $desc = trim($d['description']);
            if (mb_strlen($desc) > 200) {
                $desc = mb_substr($desc, 0, 197) . '...';
            }
            if (mb_strlen($desc) < 3) {
                $desc = 'Perintah ' . $cmd;
            }

            $list[] = [
                'command' => $cmd,
                'description' => $desc
            ];
            $seen[$cmd] = true;
            if (count($list) >= 90) break;
        }
        return $list;
    }
}

if (!function_exists('getSetting')) {
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
}

if (!function_exists('setSetting')) {
    function setSetting($key, $val) {
        global $db;
        try {
            if (!$db) $db = getDb();
            $stmt = $db->prepare("INSERT INTO settings (key, val) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET val = excluded.val");
            $stmt->execute([$key, $val]);
        } catch (Exception $e) {}
    }
}

if (!function_exists('renderSvg')) {
    function renderSvg($name, $size = 15, $class = '') {
        $icons = [
            'bell' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
            'download' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
            'user' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
            'logout' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
            'tasks' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></svg>',
            'settings' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
            'help' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
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
            'upload' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
            'zap' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="'.$class.'"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>'
        ];
        return $icons[$name] ?? '';
    }
}

// Safe Polyfill untuk mb_strimwidth jika modul mbstring tidak aktif
if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth($str, $start, $width, $trimmarker = '', $encoding = null) {
        if (function_exists('mb_substr') && function_exists('mb_strlen')) {
            if (mb_strlen($str, 'UTF-8') <= $width) return $str;
            return mb_substr($str, $start, max(0, $width - mb_strlen($trimmarker, 'UTF-8')), 'UTF-8') . $trimmarker;
        }
        if (strlen($str) <= $width) return $str;
        return substr($str, $start, max(0, $width - strlen($trimmarker))) . $trimmarker;
    }
}

if (!function_exists('parseTaskAttachments')) {
    function parseTaskAttachments($t) {
        $list = [];
        $seenUrls = [];

        $addItem = function($url, $type = '', $name = '', $size = 0) use (&$list, &$seenUrls) {
            $url = trim((string)$url);
            if ($url === '') return;
            if (strpos($url, '/uploads/#') !== false) {
                $url = str_replace('/uploads/#', '/uploads/%23', $url);
            }
            $normKey = strtolower($url);
            if (isset($seenUrls[$normKey])) return;
            $seenUrls[$normKey] = true;

            $name = trim((string)$name);
            if ($name === '') {
                $name = rawurldecode(basename(parse_url($url, PHP_URL_PATH) ?: 'file'));
            }
            $type = strtolower(trim((string)$type));
            if (str_starts_with($type, 'image/') || $type === 'photo' || preg_match('/\.(jpg|jpeg|png|webp|gif|svg)($|\?)/i', $url)) {
                $normType = 'photo';
            } elseif (str_starts_with($type, 'video/') || $type === 'video' || preg_match('/\.(mp4|mov|mkv|webm|avi)($|\?)/i', $url)) {
                $normType = 'video';
            } elseif (str_starts_with($type, 'audio/') || $type === 'voice' || $type === 'audio' || preg_match('/\.(ogg|oga|mp3|wav|m4a)($|\?)/i', $url)) {
                $normType = ($type === 'voice') ? 'voice' : 'audio';
            } else {
                $normType = 'document';
            }

            $list[] = [
                'url' => $url,
                'type' => $normType,
                'mime' => $type ?: $normType,
                'name' => $name,
                'size' => (int)$size
            ];
        };

        // 1. Parse dari kolom attachments_json jika tersedia
        if (!empty($t['attachments_json'])) {
            $decoded = is_array($t['attachments_json']) ? $t['attachments_json'] : @json_decode($t['attachments_json'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_array($item) && !empty($item['url'] ?? ($item['file_url'] ?? ''))) {
                        $addItem(
                            $item['url'] ?? $item['file_url'],
                            $item['type'] ?? ($item['file_type'] ?? ''),
                            $item['name'] ?? ($item['file_name'] ?? ($item['original_name'] ?? '')),
                            $item['size'] ?? ($item['file_size'] ?? 0)
                        );
                    }
                }
            }
        }

        // 2. Fallback / gabung dengan kolom attachment_url utama
        if (!empty($t['attachment_url'])) {
            $addItem($t['attachment_url'], $t['attachment_type'] ?? '', $t['attachment_name'] ?? '');
        }

        // 3. Ekstrak semua tag [Foto/Video/Dokumen/Audio/Suara Terlampir: ...] di dalam instruction
        $inst = (string)($t['instruction'] ?? '');
        if ($inst !== '' && preg_match_all('/\[(Foto|Video|Dokumen|Audio|Suara)\s+Terlampir:\s*([^\]\(]+?)(?:\s*\((https?:\/\/[^\)]+)\))?\]/i', $inst, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $lbl = strtolower($m[1]);
                $nm = trim($m[2]);
                $u = !empty($m[3]) ? trim($m[3]) : '';
                $tp = ($lbl === 'foto') ? 'photo' : (($lbl === 'video') ? 'video' : (($lbl === 'audio' || $lbl === 'suara') ? 'audio' : 'document'));
                if ($u) {
                    $addItem($u, $tp, $nm);
                }
            }
        }

        return $list;
    }
}

if (!function_exists('stripAttachmentHeadersFromInstruction')) {
    function stripAttachmentHeadersFromInstruction($instruction) {
        if (empty($instruction)) return '';
        $cleaned = preg_replace('/\[(?:Foto|Video|Dokumen|Audio|Suara)\s+Terlampir:\s*[^\]\(]+(?:\s*\(https?:\/\/[^\)]+\))?\]\s*/i', '', (string)$instruction);
        return trim($cleaned);
    }
}

if (!function_exists('renderTaskCardsPartialData')) {
    function renderTaskCardsPartialData($db, $currentUser, $params = []) {
        if (!$currentUser || empty($currentUser['id'])) {
            return [
                'ok' => false,
                'cards_html' => '',
                'ide_banner_html' => '',
                'count_summary' => '',
                'total_count' => 0,
                'page' => 1,
                'total_pages' => 1,
                'tasks' => []
            ];
        }

        $filterProjectRaw = $params['filter_project'] ?? ($_GET['filter_project'] ?? '');
        $filterOnlyIde = ($filterProjectRaw === 'ide');
        $filterProjectId = is_numeric($filterProjectRaw) ? (int)$filterProjectRaw : 0;
        if (!$filterProjectId && $filterProjectRaw && $filterProjectRaw !== 'all' && !$filterOnlyIde) {
            $cleanRaw = strtolower(ltrim(trim($filterProjectRaw), '#/'));
            $stmtFindP = $db->prepare("SELECT id FROM projects WHERE user_id = ? AND (project_key = ? OR LOWER(code) = ? OR LOWER(name) = ?) LIMIT 1");
            $stmtFindP->execute([(int)$currentUser['id'], $filterProjectRaw, $cleanRaw, $cleanRaw]);
            $filterProjectId = (int)$stmtFindP->fetchColumn();
        }
        $filterStatus = $params['filter_status'] ?? ($_GET['filter_status'] ?? '');
        $page = isset($params['page']) ? max(1, (int)$params['page']) : (isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1);
        $perPage = isset($params['per_page']) ? max(5, min(100, (int)$params['per_page'])) : (isset($_GET['per_page']) ? max(5, min(100, (int)$_GET['per_page'])) : 10);

        $countSql = "SELECT COUNT(*) FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.user_id = ?";
        $taskSql = "SELECT t.*, p.name as project_name, p.code as project_code, p.timezone as project_timezone FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.user_id = ?";
        $taskParams = [(int)$currentUser['id']];

        if ($filterOnlyIde) {
            $countSql .= " AND (t.sender LIKE '%IDE%' OR t.sender LIKE '%VS Code%' OR t.command = 'tele-task add')";
            $taskSql .= " AND (t.sender LIKE '%IDE%' OR t.sender LIKE '%VS Code%' OR t.command = 'tele-task add')";
        } elseif ($filterProjectId > 0) {
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

        $taskSql .= " ORDER BY t.created_at DESC, t.id DESC LIMIT $perPage OFFSET $offset";

        $stmtTasks = $db->prepare($taskSql);
        $stmtTasks->execute($taskParams);
        $myTasks = $stmtTasks->fetchAll(PDO::FETCH_ASSOC);

        // Banner Notifikasi Tugas Aktif dari IDE Chat
        $stmtActiveIde = $db->prepare("SELECT t.id, t.instruction, t.status, t.project_id, p.name as project_name FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.user_id = ? AND (t.sender LIKE '%IDE%' OR t.sender LIKE '%VS Code%' OR t.command = 'tele-task add') AND t.status IN ('PENDING', 'IN_PROGRESS') ORDER BY t.created_at DESC, t.id DESC LIMIT 1");
        $stmtActiveIde->execute([(int)$currentUser['id']]);
        $activeIdeTask = $stmtActiveIde->fetch(PDO::FETCH_ASSOC);

        ob_start();
        $ideBannerHtml = '';
        if ($activeIdeTask) {
            ?>
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
            <?php
        }
        $ideBannerHtml = ob_get_clean();

        // Render Partial Task Cards
        ob_start();
        require dirname(__DIR__) . '/views/partial_task_cards.php';
        $cardsHtml = ob_get_clean();

        return [
            'ok' => true,
            'cards_html' => $cardsHtml,
            'ide_banner_html' => $ideBannerHtml,
            'count_summary' => "Total {$totalTasksCount} Tugas (Hal {$page}/{$totalPages})",
            'total_count' => $totalTasksCount,
            'page' => $page,
            'total_pages' => $totalPages,
            'tasks' => $myTasks
        ];
    }
}
